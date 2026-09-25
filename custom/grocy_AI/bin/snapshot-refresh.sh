#!/usr/bin/env bash
#
# snapshot-refresh.sh — produce a local, gitignored, secret-scrubbed copy of the
# production Grocy SQLite for Phase 6 profiling + rollback rehearsal (plan 06-01).
#
# Pipeline (all read-only against prod; no production write ever occurs):
#   1. SSH to the prod host (root@10.10.0.156, key from 1Password).
#   2. Inside the `grocy` container, PHP PDO `VACUUM INTO` makes a transactionally
#      consistent copy of /config/data/grocy.db (source opened read, never written).
#   3. `docker cp` the copy to the host, `scp` it down to the raw snapshot path.
#   4. Apply bin/snapshot-scrub.sql to a working copy -> the scrubbed snapshot.
#
# The snapshot lives under custom/grocy_AI/.snapshots/ which is gitignored, so it
# can never be committed. Re-runnable; refuses to clobber without --force.
#
# Usage:
#   bin/snapshot-refresh.sh [--force] [--dry-run] [--help]
#
# Env overrides (all optional):
#   GROCY_PROD_SSH     default "root@10.10.0.156"
#   GROCY_PROD_CONTAINER default "grocy"
#   GROCY_PROD_DB      default "/config/data/grocy.db"  (path inside the container)
#   OP_SSH_ITEM        default "ssh:Personal Docker (102)"
#   OP_SSH_VAULT       default "API/SSH/Tokens"
#
# After a successful run these point at the produced files (handy for tests):
#   GROCY_AI_SNAPSHOT_RAW = custom/grocy_AI/.snapshots/grocy-prod-raw.sqlite
#   GROCY_AI_SNAPSHOT     = custom/grocy_AI/.snapshots/grocy-prod.sqlite
set -euo pipefail

# --- locate module dir relative to this script (works from any cwd) -----------
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
MODULE_DIR="$(cd "$SCRIPT_DIR/.." && pwd)"            # .../custom/grocy_AI
SNAP_DIR="$MODULE_DIR/.snapshots"
RAW="$SNAP_DIR/grocy-prod-raw.sqlite"                 # unscrubbed intermediate (gitignored)
FINAL="$SNAP_DIR/grocy-prod.sqlite"                   # scrubbed snapshot       (gitignored)
SCRUB_SQL="$SCRIPT_DIR/snapshot-scrub.sql"

PROD_SSH="${GROCY_PROD_SSH:-root@10.10.0.156}"
CONTAINER="${GROCY_PROD_CONTAINER:-grocy}"
PROD_DB="${GROCY_PROD_DB:-/config/data/grocy.db}"
OP_ITEM="${OP_SSH_ITEM:-ssh:Personal Docker (102)}"
OP_VAULT="${OP_SSH_VAULT:-API/SSH/Tokens}"

FORCE=0; DRY=0
for a in "$@"; do
  case "$a" in
    --force) FORCE=1 ;;
    --dry-run) DRY=1 ;;
    -h|--help) sed -n '2,40p' "$0"; exit 0 ;;
    *) echo "unknown arg: $a (see --help)" >&2; exit 2 ;;
  esac
done

log() { printf '[snapshot] %s\n' "$*" >&2; }
die() { printf '[snapshot] ERROR: %s\n' "$*" >&2; exit 1; }

need() { command -v "$1" >/dev/null 2>&1 || die "missing required tool: $1"; }
need op; need ssh; need scp; need sqlite3; need python3

if [[ $DRY -eq 1 ]]; then
  log "DRY RUN — nothing will be written or transferred."
  log "would SSH:        $PROD_SSH (key: op item '$OP_ITEM' / vault '$OP_VAULT')"
  log "would copy DB:    container '$CONTAINER' path '$PROD_DB' via PDO VACUUM INTO (read-only)"
  log "raw snapshot ->   $RAW"
  log "scrubbed with:    $SCRUB_SQL"
  log "final snapshot -> $FINAL"
  exit 0
fi

[[ -f "$SCRUB_SQL" ]] || die "scrub SQL not found: $SCRUB_SQL"
if [[ -e "$FINAL" && $FORCE -ne 1 ]]; then
  die "refusing to overwrite existing snapshot: $FINAL (pass --force to refresh)"
fi

mkdir -p "$SNAP_DIR"

# --- fetch the SSH key into tmpfs (RAM), never to disk -----------------------
umask 077
KEY="$(mktemp -p /dev/shm 2>/dev/null || mktemp)"
cleanup() { rm -f "$KEY"; }
trap cleanup EXIT INT TERM
op item get "$OP_ITEM" --vault "$OP_VAULT" --format json \
  | python3 -c "import json,sys;d=json.load(sys.stdin);[sys.stdout.write(f['ssh_formats']['openssh']['value']) for f in d['fields'] if f.get('id')=='private_key']" \
  > "$KEY"
chmod 600 "$KEY"
[[ -s "$KEY" ]] || die "could not load SSH key from 1Password item '$OP_ITEM'"

SSH_OPTS=(-i "$KEY" -o IdentitiesOnly=yes -o StrictHostKeyChecking=accept-new -o ConnectTimeout=10)

# --- acquire a consistent copy on the prod host (read-only against prod) ------
log "acquiring consistent copy from $PROD_SSH (container '$CONTAINER') ..."
ssh "${SSH_OPTS[@]}" "$PROD_SSH" CONTAINER="$CONTAINER" PROD_DB="$PROD_DB" 'bash -s' <<'REMOTE'
set -euo pipefail
rm -f /tmp/grocy-snap.db
cat > /tmp/grocy-vac.php <<PHP
<?php
\$src='${PROD_DB}'; \$out='/tmp/grocy-snap.db';
@unlink(\$out);
\$before=md5_file(\$src);
\$pdo=new PDO('sqlite:'.\$src, null, null, [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
\$pdo->exec("VACUUM INTO '".\$out."'");   // reads source only; never writes prod
\$n=\$pdo->query('SELECT COUNT(*) FROM products')->fetchColumn();
\$pdo=null;
if (md5_file(\$src) !== \$before) { fwrite(STDERR,"PROD DB CHANGED — aborting\n"); exit(3); }
fwrite(STDERR, "prod copy: products=\$n size=".filesize(\$out)."\n");
PHP
docker cp /tmp/grocy-vac.php "${CONTAINER}":/tmp/grocy-vac.php
docker exec "${CONTAINER}" php /tmp/grocy-vac.php
docker exec "${CONTAINER}" rm -f /tmp/grocy-vac.php /tmp/grocy-snap.db.from 2>/dev/null || true
docker cp "${CONTAINER}":/tmp/grocy-snap.db /tmp/grocy-snap.db
docker exec "${CONTAINER}" rm -f /tmp/grocy-snap.db
rm -f /tmp/grocy-vac.php
REMOTE

log "transferring snapshot down (scp, no pty) ..."
scp "${SSH_OPTS[@]}" "$PROD_SSH":/tmp/grocy-snap.db "$RAW"
ssh "${SSH_OPTS[@]}" "$PROD_SSH" 'rm -f /tmp/grocy-snap.db'

# --- verify the raw copy opens and has a plausible product count --------------
RAW_PRODUCTS="$(sqlite3 "$RAW" 'SELECT count(*) FROM products;')"
[[ "$RAW_PRODUCTS" =~ ^[0-9]+$ && "$RAW_PRODUCTS" -gt 0 ]] || die "raw snapshot unreadable or empty"
log "raw snapshot: $RAW ($RAW_PRODUCTS products)"

# --- scrub into the final snapshot -------------------------------------------
cp -f "$RAW" "$FINAL"
sqlite3 "$FINAL" < "$SCRUB_SQL"

# --- verify secrets gone, data preserved --------------------------------------
FINAL_PRODUCTS="$(sqlite3 "$FINAL" 'SELECT count(*) FROM products;')"
API_KEYS="$(sqlite3 "$FINAL" 'SELECT count(*) FROM api_keys;')"
SESSIONS="$(sqlite3 "$FINAL" 'SELECT count(*) FROM sessions;')"
PW_LEFT="$(sqlite3 "$FINAL" "SELECT count(*) FROM users WHERE password <> '\$scrubbed\$no-login';")"
[[ "$FINAL_PRODUCTS" == "$RAW_PRODUCTS" ]] || die "product count changed during scrub ($RAW_PRODUCTS -> $FINAL_PRODUCTS)"
[[ "$API_KEYS" == "0" ]] || die "api_keys not scrubbed (count=$API_KEYS)"
[[ "$SESSIONS" == "0" ]] || die "sessions not scrubbed (count=$SESSIONS)"
[[ "$PW_LEFT" == "0" ]]  || die "user password hash(es) not scrubbed (count=$PW_LEFT)"

log "DONE."
log "  scrubbed snapshot: $FINAL  ($FINAL_PRODUCTS products; api_keys/sessions emptied; passwords blanked)"
log "  raw intermediate:  $RAW    (unscrubbed; gitignored)"
log "  export for tests:  GROCY_AI_SNAPSHOT=$FINAL  GROCY_AI_SNAPSHOT_RAW=$RAW"
