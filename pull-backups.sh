#!/usr/bin/env bash
# =============================================================================
# Pull the server's database dumps down to this machine.
#
# Run this ON YOUR LAPTOP (or any box that is not the production server) —
# dumps that only exist on the server die with the server:
#
#     ./pull-backups.sh <your-server>              # pull whatever is there now
#     ./pull-backups.sh --dump <your-server>       # dump on the server, then pull
#     ./pull-backups.sh --keep 30 <your-server>    # prune local copies past 30
#     ./pull-backups.sh --dest /mnt/archive <your-server>
#     ./pull-backups.sh --refetch <your-server>    # re-download, ignore local copies
#
# <your-server> is an SSH alias or user@host, the same one used for deploys.
# Defaults can live in the environment instead of on the command line:
#
#     export HRIS_SERVER=hris-prod
#     export HRIS_BACKUP_DEST=/mnt/archive
#
# Dumps land in ~/hris-backups, deliberately outside this checkout: they hold
# every employee record and password hash in production, and nothing that
# sensitive belongs in a working tree where a stray `git add -A` can reach it.
# Keep --dest outside the repo for the same reason.
#
# Transfers are resumable and skip files already downloaded, so re-running this
# over a slow link is cheap. Everything pulled is verified against the .sha256
# sidecar that dump-db.sh writes; dumps taken by update-prod.sh predate the
# sidecars and fall back to a gzip integrity check.
#
# Nothing is ever deleted on the server — retention there belongs to
# dump-db.sh --keep.
# =============================================================================
set -euo pipefail

SERVER="${HRIS_SERVER:-}"
DEST="${HRIS_BACKUP_DEST:-$HOME/hris-backups}"
REMOTE_DIR="hris"
KEEP=""
DO_DUMP=0
DO_REFETCH=0
VERIFY_ALL=0

red()  { printf '\033[31m%s\033[0m\n' "$*" >&2; }
warn() { printf '\033[33m==> %s\033[0m\n' "$*"; }
say()  { printf '\033[36m==> %s\033[0m\n' "$*"; }
die()  { red "ERROR: $*"; exit 1; }

usage() {
    awk 'NR > 2 && /^# ={10,}/ { exit } NR > 2' "$0" | sed 's/^# \{0,1\}//'
    exit "${1:-0}"
}

while [[ $# -gt 0 ]]; do
    case "$1" in
        --dump)       DO_DUMP=1; shift ;;
        --dest)       DEST="${2:?--dest needs a path}"; shift 2 ;;
        --remote-dir) REMOTE_DIR="${2:?--remote-dir needs a path}"; shift 2 ;;
        --keep)       KEEP="${2:?--keep needs a number}"; shift 2 ;;
        --refetch)    DO_REFETCH=1; shift ;;
        --verify-all) VERIFY_ALL=1; shift ;;
        -h|--help)    usage 0 ;;
        -*)           red "unknown argument: $1"; usage 64 ;;
        *)
            [[ -z "$SERVER" ]] || { red "server given twice: '$SERVER' and '$1'"; usage 64; }
            SERVER="$1"
            shift
            ;;
    esac
done

# -----------------------------------------------------------------------------
# Preflight
# -----------------------------------------------------------------------------
[[ -n "$SERVER" ]] || die "no server given. Pass it as an argument or set HRIS_SERVER.
    ./pull-backups.sh <your-server>"

if [[ -n "$KEEP" ]]; then
    [[ "$KEEP" =~ ^[0-9]+$ ]] || die "--keep expects a number, got '$KEEP'"
    (( KEEP > 0 )) || die "--keep must be at least 1"
fi

command -v ssh >/dev/null || die "ssh is not installed"
command -v rsync >/dev/null || die "rsync is not installed (apt install rsync / brew install rsync)"
command -v sha256sum >/dev/null || die "sha256sum is not installed"

REMOTE_BACKUPS="$REMOTE_DIR/backups"

say "Checking the connection to $SERVER ..."
ssh -o BatchMode=yes "$SERVER" true 2>/dev/null \
    || die "cannot reach $SERVER over ssh without a prompt. Check your ~/.ssh/config and agent."

# rsync needs it on the far side too, and the failure message it produces on its
# own is famously unhelpful.
ssh "$SERVER" 'command -v rsync >/dev/null' \
    || die "rsync is missing on $SERVER (sudo apt install rsync)"

# -----------------------------------------------------------------------------
# Optionally take a fresh dump first
#
# Deliberately a separate step from the transfer: if the dump fails there is
# nothing new worth pulling, and dump-db.sh already refuses to leave a broken
# file behind.
# -----------------------------------------------------------------------------
if [[ "$DO_DUMP" -eq 1 ]]; then
    say "Dumping the database on $SERVER ..."
    ssh "$SERVER" "cd ${REMOTE_DIR} && ./dump-db.sh" >/dev/null \
        || die "the remote dump failed; nothing was pulled. Run it by hand to see why:
    ssh $SERVER 'cd ${REMOTE_DIR} && ./dump-db.sh'"
fi

# -----------------------------------------------------------------------------
# Transfer
# -----------------------------------------------------------------------------
mkdir -p "$DEST"

# Dump filenames carry a UTC timestamp and are never rewritten, so a name that
# already exists locally is the same file — skipping it makes repeat runs
# nearly free. --refetch is the escape hatch when a local copy is suspect.
RSYNC_OPTS=(
    --archive
    --compress
    --human-readable
    --partial-dir=.rsync-partial
    --out-format='%n'
    --include='hris-*.sql.gz'
    --include='hris-*.sql.gz.sha256'
    --exclude='*'
)

if [[ "$DO_REFETCH" -eq 1 ]]; then
    warn "Re-fetching by content (--refetch); this re-reads every file on both ends."
    RSYNC_OPTS+=(--checksum)
else
    RSYNC_OPTS+=(--ignore-existing)
fi

say "Pulling $SERVER:$REMOTE_BACKUPS/ -> $DEST/"

transferred="$(mktemp)"
trap 'rm -f "$transferred"' EXIT

if ! rsync "${RSYNC_OPTS[@]}" "$SERVER:$REMOTE_BACKUPS/" "$DEST/" > "$transferred"; then
    die "rsync failed. If $REMOTE_BACKUPS does not exist yet, take a dump first:
    ./pull-backups.sh --dump $SERVER"
fi

new_dumps=()
while IFS= read -r line; do
    [[ "$line" == hris-*.sql.gz ]] && new_dumps+=("$line")
done < "$transferred"

if [[ ${#new_dumps[@]} -eq 0 ]]; then
    say "Nothing new to pull; the local copy is already current."
else
    if [[ "$DO_REFETCH" -eq 1 ]]; then
        say "Transferred ${#new_dumps[@]} dump(s):"
    else
        say "Pulled ${#new_dumps[@]} new dump(s):"
    fi
    printf '      %s\n' "${new_dumps[@]}"
fi

# -----------------------------------------------------------------------------
# Verify
#
# An unverified backup is a guess. Check the sidecar hash where there is one,
# and at minimum prove the gzip stream is intact where there is not.
# -----------------------------------------------------------------------------
to_check=("${new_dumps[@]+"${new_dumps[@]}"}")

if [[ "$VERIFY_ALL" -eq 1 ]]; then
    to_check=()
    while IFS= read -r file; do
        to_check+=("$(basename "$file")")
    done < <(find "$DEST" -maxdepth 1 -name 'hris-*.sql.gz' | sort)
    say "Verifying all ${#to_check[@]} local dump(s) ..."
fi

bad=()
unhashed=0
for name in ${to_check[@]+"${to_check[@]}"}; do
    if [[ -f "$DEST/$name.sha256" ]]; then
        ( cd "$DEST" && sha256sum --check --status "$name.sha256" ) || bad+=("$name (checksum mismatch)")
    else
        unhashed=$((unhashed + 1))
        gzip -t "$DEST/$name" 2>/dev/null || bad+=("$name (corrupt gzip)")
    fi
done

if [[ ${#bad[@]} -gt 0 ]]; then
    red ""
    red "These local copies did not verify:"
    printf '      %s\n' "${bad[@]}" >&2
    red ""
    red "--refetch compares by content and overwrites a bad copy, so this repairs them:"
    red "    ./pull-backups.sh --refetch $SERVER"
    red ""
    red "If it keeps failing, the copy on the server is the damaged one. Check it there:"
    red "    ssh $SERVER 'cd ${REMOTE_DIR}/backups && sha256sum -c *.sha256'"
    exit 1
fi

if [[ ${#to_check[@]} -gt 0 ]]; then
    say "Verified ${#to_check[@]} dump(s)$([[ $unhashed -gt 0 ]] && printf ' (%s without a sidecar hash, gzip-checked only)' "$unhashed")."
fi

# -----------------------------------------------------------------------------
# Local retention
#
# Off unless asked for: this directory is the off-server archive, and silently
# deleting the oldest copy of something is not a backup strategy.
# -----------------------------------------------------------------------------
if [[ -n "$KEEP" ]]; then
    # shellcheck disable=SC2012  # filenames are UTC timestamps; no spaces or newlines possible
    ls -1t "$DEST"/hris-*.sql.gz 2>/dev/null | tail -n "+$((KEEP + 1))" | while read -r old; do
        say "Pruning local copy $old"
        rm -f "$old" "$old.sha256"
    done
fi

count="$(find "$DEST" -maxdepth 1 -name 'hris-*.sql.gz' | wc -l)"
newest="$(find "$DEST" -maxdepth 1 -name 'hris-*.sql.gz' -printf '%f\n' 2>/dev/null | sort | tail -n1)"

say "$count dump(s) in $DEST, $(du -sh "$DEST" | cut -f1) total"
[[ -n "$newest" ]] && say "Newest: $newest"

cat <<EOF

Restore one into a stack (this wipes the target database):

    gunzip -c ${DEST}/${newest:-hris-<timestamp>.sql.gz} \\
      | docker compose --env-file .env.prod -f docker-compose.prod.yml \\
        exec -T db mysql -u root -p<DB_ROOT_PASSWORD> <DB_DATABASE>
EOF
