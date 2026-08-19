#!/usr/bin/env bash
# =============================================================================
# Dump the production database.
#
# Run this ON THE SERVER, from the repo checkout:
#
#     ssh <your-server>
#     cd ~/hris
#     ./dump-db.sh                     # -> backups/hris-<timestamp>.sql.gz
#     ./dump-db.sh --keep 30           # keep the 30 newest dumps, prune older
#     ./dump-db.sh --dir /mnt/backups  # write somewhere other than ./backups
#     ./dump-db.sh --no-prune          # keep everything
#
# Every dump gets a `.sha256` sidecar so the copy pulled to a laptop can be
# verified end to end. Pulling them off this box is a separate step, run from
# the laptop: ./pull-backups.sh <your-server>. A dump that never leaves the
# server is not a backup.
#
# Progress goes to stderr and the path of the finished dump is the only thing
# on stdout, so this composes:
#
#     backup="$(./dump-db.sh --keep 7)"
#
# The dump is taken with --single-transaction, so InnoDB tables are consistent
# with each other and the site stays writable while it runs.
# =============================================================================
set -euo pipefail

cd "$(dirname "$0")"

COMPOSE_FILE="docker-compose.prod.yml"
ENV_FILE=".env.prod"
BACKUP_DIR="backups"
BACKUP_KEEP=7
DO_PRUNE=1

red()  { printf '\033[31m%s\033[0m\n' "$*" >&2; }
warn() { printf '\033[33m==> %s\033[0m\n' "$*" >&2; }
say()  { printf '\033[36m==> %s\033[0m\n' "$*" >&2; }
die()  { red "ERROR: $*"; exit 1; }

usage() {
    awk 'NR > 2 && /^# ={10,}/ { exit } NR > 2' "$0" | sed 's/^# \{0,1\}//'
    exit "${1:-0}"
}

while [[ $# -gt 0 ]]; do
    case "$1" in
        --keep)     BACKUP_KEEP="${2:?--keep needs a number}"; shift 2 ;;
        --dir)      BACKUP_DIR="${2:?--dir needs a path}"; shift 2 ;;
        --no-prune) DO_PRUNE=0; shift ;;
        -h|--help)  usage 0 ;;
        *)          red "unknown argument: $1"; usage 64 ;;
    esac
done

[[ "$BACKUP_KEEP" =~ ^[0-9]+$ ]] || die "--keep expects a number, got '$BACKUP_KEEP'"
(( BACKUP_KEEP > 0 )) || die "--keep must be at least 1; use --no-prune to keep everything"

# -----------------------------------------------------------------------------
# Preflight
# -----------------------------------------------------------------------------
command -v docker >/dev/null || die "docker is not installed"
docker compose version >/dev/null 2>&1 || die "the docker compose plugin is not installed"
[[ -f "$COMPOSE_FILE" ]] || die "$COMPOSE_FILE not found — run this from the repo root"
[[ -f "$ENV_FILE" ]] || die "$ENV_FILE not found. Copy .env.prod.example and fill it in."

dc() { docker compose --env-file "$ENV_FILE" -f "$COMPOSE_FILE" "$@"; }

# Read a single key out of the dotenv file without sourcing it, so nothing in
# .env.prod is ever executed by this script.
env_value() {
    sed -n "s/^$1=//p" "$ENV_FILE" | head -n1 | sed -e 's/^"\(.*\)"$/\1/' -e "s/^'\(.*\)'$/\1/"
}

DB_ROOT_PASSWORD="$(env_value DB_ROOT_PASSWORD)"
DB_DATABASE="$(env_value DB_DATABASE)"
[[ -n "$DB_ROOT_PASSWORD" && -n "$DB_DATABASE" ]] || die "DB_ROOT_PASSWORD or DB_DATABASE missing from $ENV_FILE"

# Compose interpolates $VAR inside --env-file values, so a password containing a
# literal `$` reached MySQL with that part expanded (usually to nothing) when the
# container was created, while this script reads the raw text. The dump then
# fails with "Access denied" for no visible reason.
if [[ "$DB_ROOT_PASSWORD" == *'$'* ]]; then
    warn "DB_ROOT_PASSWORD in $ENV_FILE contains a '\$'. Compose expands that, so what MySQL"
    warn "actually stored may differ from what is written here. If the dump is denied access,"
    warn "escape it as '\$\$' in $ENV_FILE or pick a password without a dollar sign."
fi

dc ps --status running --services 2>/dev/null | grep -qx db \
    || die "the db service is not running. Start the stack first:
    docker compose --env-file $ENV_FILE -f $COMPOSE_FILE up -d db"

mkdir -p "$BACKUP_DIR"
[[ -w "$BACKUP_DIR" ]] || die "$BACKUP_DIR is not writable"

# -----------------------------------------------------------------------------
# Dump
#
# The password is handed over as MYSQL_PWD in the exec environment rather than
# --password=, which would put it in the container's process list for anything
# reading /proc while the dump runs.
#
# --no-tablespaces: avoids needing the PROCESS privilege, and the tablespace
# statements are useless for restoring into a fresh container anyway.
# -----------------------------------------------------------------------------
stamp="$(date -u +%Y%m%dT%H%M%SZ)"
backup="$BACKUP_DIR/hris-${stamp}.sql.gz"

[[ -e "$backup" ]] && die "$backup already exists — refusing to overwrite it"

say "Dumping '$DB_DATABASE' to $backup ..."

if ! dc exec -T -e MYSQL_PWD="$DB_ROOT_PASSWORD" db mysqldump \
        --user=root \
        --single-transaction --routines --triggers --no-tablespaces \
        "$DB_DATABASE" | gzip > "$backup"; then
    rm -f "$backup"
    die "mysqldump failed; the partial dump has been removed"
fi

# -----------------------------------------------------------------------------
# Verify
#
# A dump that is corrupt or truncated is worse than no dump, because it looks
# like protection. gzip -t catches a broken stream and the trailer catches a
# mysqldump that died halfway through with the pipe still succeeding.
# -----------------------------------------------------------------------------
[[ -s "$backup" ]] || { rm -f "$backup"; die "the dump is empty — aborting"; }

if ! gzip -t "$backup" 2>/dev/null; then
    rm -f "$backup"
    die "the dump is not a valid gzip stream — aborting"
fi

if ! gunzip -c "$backup" | tail -n 5 | grep -q '^-- Dump completed'; then
    rm -f "$backup"
    die "the dump has no 'Dump completed' trailer, so it is truncated — aborting"
fi

tables="$(gunzip -c "$backup" | grep -c '^CREATE TABLE' || true)"
( cd "$BACKUP_DIR" && sha256sum "$(basename "$backup")" > "$(basename "$backup").sha256" )

say "Wrote $backup ($(du -h "$backup" | cut -f1), ${tables} tables)"

# -----------------------------------------------------------------------------
# Retention, because the disk on this box is small.
#
# The glob is shared with update-prod.sh's pre-deploy dumps on purpose: both
# kinds live in one directory under one retention policy.
# -----------------------------------------------------------------------------
if [[ "$DO_PRUNE" -eq 1 ]]; then
    # shellcheck disable=SC2012  # filenames are generated above; no spaces or newlines possible
    ls -1t "$BACKUP_DIR"/hris-*.sql.gz 2>/dev/null | tail -n "+$((BACKUP_KEEP + 1))" | while read -r old; do
        say "Pruning old dump $old"
        rm -f "$old" "$old.sha256"
    done

    # Sidecars left behind by update-prod.sh's own pruning, which only knows
    # about the .sql.gz files.
    for sidecar in "$BACKUP_DIR"/hris-*.sql.gz.sha256; do
        [[ -e "$sidecar" ]] || continue
        [[ -f "${sidecar%.sha256}" ]] || rm -f "$sidecar"
    done
else
    warn "Keeping every dump (--no-prune); watch the disk with: df -h ."
fi

kept="$(find "$BACKUP_DIR" -maxdepth 1 -name 'hris-*.sql.gz' | wc -l)"
say "$kept dump(s) in $BACKUP_DIR, $(du -sh "$BACKUP_DIR" | cut -f1) total"

printf '%s\n' "$backup"
