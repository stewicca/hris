#!/usr/bin/env bash
# =============================================================================
# Update the running production stack.
#
# Run this ON THE SERVER, from the repo checkout:
#
#     ssh <your-server>
#     cd ~/hris
#     ./update-prod.sh                 # git pull + side-loaded/local images
#     ./update-prod.sh --pull          # git pull + docker compose pull
#     ./update-prod.sh --load img.tgz  # git pull + docker load from a tarball
#     ./update-prod.sh --build         # git pull + build here (see warning)
#
# Add --yes to answer prompts automatically when running unattended.
#
# What it does, in order: fetch and fast-forward the checkout, warn about new
# .env keys, dump the database, obtain the new images, recreate the stack, and
# wait for the health checks to go green.
#
# Migrations are NOT run here. The app container's entrypoint runs them on
# every start, so they happen automatically during the recreate step.
# =============================================================================
set -euo pipefail

cd "$(dirname "$0")"

COMPOSE_FILE="docker-compose.prod.yml"
ENV_FILE=".env.prod"
BACKUP_DIR="backups"
BACKUP_KEEP=7
HEALTH_TIMEOUT=300

MODE="none"
LOAD_FILE=""
BRANCH=""
DO_BACKUP=1
SKIP_GIT=0
ASSUME_YES=0

red()  { printf '\033[31m%s\033[0m\n' "$*"; }
warn() { printf '\033[33m==> %s\033[0m\n' "$*"; }
say()  { printf '\033[36m==> %s\033[0m\n' "$*"; }
die()  { red "ERROR: $*" >&2; exit 1; }

usage() {
    awk 'NR > 2 && /^# ={10,}/ { exit } NR > 2' "$0" | sed 's/^# \{0,1\}//'
    exit "${1:-0}"
}

while [[ $# -gt 0 ]]; do
    case "$1" in
        --pull)      MODE="pull"; shift ;;
        --build)     MODE="build"; shift ;;
        --load)      MODE="load"; LOAD_FILE="${2:?--load needs a tarball path}"; shift 2 ;;
        --branch)    BRANCH="${2:?--branch needs a name}"; shift 2 ;;
        --no-backup) DO_BACKUP=0; shift ;;
        --skip-git)  SKIP_GIT=1; shift ;;
        -y|--yes)    ASSUME_YES=1; shift ;;
        -h|--help)   usage 0 ;;
        *)           red "unknown argument: $1"; usage 64 ;;
    esac
done

# -----------------------------------------------------------------------------
# Preflight
# -----------------------------------------------------------------------------
command -v docker >/dev/null || die "docker is not installed"
docker compose version >/dev/null 2>&1 || die "the docker compose plugin is not installed"
[[ -f "$COMPOSE_FILE" ]] || die "$COMPOSE_FILE not found — run this from the repo root"
[[ -f "$ENV_FILE" ]] || die "$ENV_FILE not found. Copy .env.prod.example and fill it in."

perms="$(stat -c '%a' "$ENV_FILE")"
if [[ "$perms" != "600" ]]; then
    warn "$ENV_FILE is mode $perms; it holds database and admin credentials. Fixing to 600."
    chmod 600 "$ENV_FILE"
fi

dc() { docker compose --env-file "$ENV_FILE" -f "$COMPOSE_FILE" "$@"; }

# Read a single key out of the dotenv file without sourcing it, so nothing in
# .env.prod is ever executed by this script.
env_value() {
    sed -n "s/^$1=//p" "$ENV_FILE" | head -n1 | sed -e 's/^"\(.*\)"$/\1/' -e "s/^'\(.*\)'$/\1/"
}

IMAGE_PREFIX="$(env_value IMAGE_PREFIX)"
IMAGE_PREFIX="${IMAGE_PREFIX:-localhost/hris}"

if [[ "$MODE" == "pull" && "$IMAGE_PREFIX" == localhost/* ]]; then
    die "--pull needs IMAGE_PREFIX in $ENV_FILE to point at a real registry (it is '$IMAGE_PREFIX')."
fi

if [[ "$MODE" == "build" ]]; then
    warn "Building images on the server."
    warn "The build runs both Vite builds; on a small VPS this is slow and memory-hungry."
    warn "Building on a laptop or in CI and using --pull or --load is the safer path."
fi

if [[ "$MODE" == "load" ]]; then
    [[ -f "$LOAD_FILE" ]] || die "image tarball not found: $LOAD_FILE"
fi

# -----------------------------------------------------------------------------
# 1. Update the checkout
# -----------------------------------------------------------------------------
OLD_REV=""
if [[ "$SKIP_GIT" -eq 0 ]]; then
    git rev-parse --git-dir >/dev/null 2>&1 || die "not a git repository"

    if [[ -n "$(git status --porcelain --untracked-files=no)" ]]; then
        die "the working tree has local modifications. Commit, stash or revert them first:
$(git status --short --untracked-files=no)"
    fi

    BRANCH="${BRANCH:-$(git rev-parse --abbrev-ref HEAD)}"
    OLD_REV="$(git rev-parse HEAD)"

    say "Fetching origin/$BRANCH ..."
    git fetch --quiet origin "$BRANCH"

    # --ff-only: a server checkout must never end up with a merge commit or a
    # conflict to resolve by hand.
    if ! git merge-base --is-ancestor HEAD "origin/$BRANCH"; then
        die "HEAD is not an ancestor of origin/$BRANCH — the checkout has diverged. Resolve manually."
    fi

    git merge --ff-only --quiet "origin/$BRANCH"
    NEW_REV="$(git rev-parse HEAD)"

    if [[ "$OLD_REV" == "$NEW_REV" ]]; then
        say "Already up to date at ${NEW_REV:0:8}."
        if [[ "$MODE" == "none" ]]; then
            say "Nothing to do. Pass --pull, --load or --build to refresh images anyway."
            exit 0
        fi
    else
        say "Updated ${OLD_REV:0:8} -> ${NEW_REV:0:8}"
        git --no-pager log --oneline "$OLD_REV..$NEW_REV" | sed 's/^/    /'
    fi
fi

# -----------------------------------------------------------------------------
# 2. Check for configuration the new code expects but this server lacks
#
# A key added to .env.prod.example and forgotten in .env.prod is the most
# common cause of a deploy that starts cleanly and then misbehaves, so surface
# it before anything restarts.
# -----------------------------------------------------------------------------
missing=()
while IFS= read -r key; do
    grep -q "^${key}=" "$ENV_FILE" || missing+=("$key")
done < <(grep -oE '^[A-Z_][A-Z0-9_]*=' .env.prod.example | tr -d '=')

if [[ ${#missing[@]} -gt 0 ]]; then
    warn "These keys are in .env.prod.example but missing from $ENV_FILE:"
    printf '      %s\n' "${missing[@]}"
    warn "Add them before continuing if the new code needs them."
    if [[ "$ASSUME_YES" -eq 1 ]]; then
        warn "Continuing anyway (--yes)."
    elif [[ ! -t 0 ]]; then
        die "not running interactively and --yes was not given — aborting."
    else
        reply=""
        read -r -p "    Continue anyway? [y/N] " reply || true
        [[ "$reply" =~ ^[Yy]$ ]] || die "aborted by operator"
    fi
fi

# -----------------------------------------------------------------------------
# 3. Back up the database
#
# The app entrypoint runs `migrate --force` on start, so the restart below can
# change the schema. Take the dump before that happens, not after.
#
# dump-db.sh owns the dump, the integrity checks and the retention policy, and
# prints the resulting path on stdout. These dumps still only exist on this
# box; pull them to a laptop with ./pull-backups.sh.
# -----------------------------------------------------------------------------
backup=""
if [[ "$DO_BACKUP" -eq 1 ]]; then
    if dc ps --status running --services 2>/dev/null | grep -qx db; then
        [[ -x ./dump-db.sh ]] || die "./dump-db.sh is missing or not executable"

        if ! backup="$(./dump-db.sh --dir "$BACKUP_DIR" --keep "$BACKUP_KEEP")"; then
            die "database dump failed — refusing to update on top of an unbacked-up database. Use --no-backup to override."
        fi
    else
        warn "The db service is not running; skipping the backup."
    fi
else
    warn "Skipping the database backup (--no-backup)."
fi

# -----------------------------------------------------------------------------
# 4. Obtain the images
# -----------------------------------------------------------------------------
case "$MODE" in
    pull)
        say "Pulling images from ${IMAGE_PREFIX} ..."
        dc pull
        ;;
    load)
        say "Loading images from $LOAD_FILE ..."
        if [[ "$LOAD_FILE" == *.gz || "$LOAD_FILE" == *.tgz ]]; then
            gunzip -c "$LOAD_FILE" | docker load
        else
            docker load -i "$LOAD_FILE"
        fi
        ;;
    build)
        say "Building images locally ..."
        ./build-prod.sh
        ;;
    none)
        warn "No image source given; recreating with the images already present."
        ;;
esac

# -----------------------------------------------------------------------------
# 5. Recreate the stack
#
# The app entrypoint migrates, clears and re-caches on start, so the site is
# down for as long as that takes — typically seconds, longer if a migration is
# heavy. There is no blue/green here; a single app container is replaced.
# -----------------------------------------------------------------------------
say "Recreating containers ..."
dc up -d --remove-orphans

# -----------------------------------------------------------------------------
# 6. Wait for health
# -----------------------------------------------------------------------------
say "Waiting for health checks (timeout ${HEALTH_TIMEOUT}s) ..."

health_of() {
    docker inspect --format '{{if .State.Health}}{{.State.Health.Status}}{{else}}none{{end}}' "$1" 2>/dev/null || echo "missing"
}

deadline=$((SECONDS + HEALTH_TIMEOUT))
failed=""
for container in hris_app hris_nginx; do
    while :; do
        status="$(health_of "$container")"
        case "$status" in
            healthy) say "$container is healthy"; break ;;
            unhealthy) failed="$container"; break ;;
            missing) failed="$container"; break ;;
        esac
        if (( SECONDS >= deadline )); then
            failed="$container"
            break
        fi
        sleep 3
    done
    if [[ -n "$failed" ]]; then
        break
    fi
done

if [[ -n "$failed" ]]; then
    red ""
    red "Deploy did not come up cleanly: $failed is '$(health_of "$failed")'."
    red ""
    red "Last 50 log lines:"
    dc logs --tail=50 "${failed#hris_}" || true
    red ""
    red "NOT rolling back automatically. The entrypoint has already run"
    red "migrations, so reverting the images without reverting the schema can"
    red "leave the database ahead of the code — usually worse than the outage."
    red ""
    if [[ -n "$OLD_REV" ]]; then
        red "Previous revision: $OLD_REV"
    fi
    if [[ "$DO_BACKUP" -eq 1 ]]; then
        red "Pre-update backup: ${backup:-none taken}"
    fi
    exit 1
fi

# The face service loads a large model and can take a couple of minutes; report
# it but do not fail the deploy over it, attendance falls back to GPS-only.
face_status="$(health_of hris_face)"
if [[ "$face_status" != "healthy" ]]; then
    warn "hris_face is '$face_status' — it loads a large model and may still be starting."
    warn "Check with: docker compose --env-file $ENV_FILE -f $COMPOSE_FILE logs -f face-recognition"
fi

# -----------------------------------------------------------------------------
# 7. Reclaim disk
# -----------------------------------------------------------------------------
say "Pruning dangling images ..."
docker image prune -f >/dev/null

say "Done. Stack status:"
dc ps
