#!/usr/bin/env bash
# Deploy gateway v2 from /var/www/witu-ai-source/gateway into /opt/witu-gateway (phase B).
#
#   sudo witu-deploy-gateway <git-ref> [--migrate]
#
#   <git-ref>   tag, branch or commit that passed CI (e.g. origin/main)
#   --migrate   approve pending migrations. Without it the script prints the SQL that would
#               run and stops before touching the live gateway.
#
# The gateway is API-only (no frontend assets), so no CI bundle is needed.
# Order: fetch -> stage (rsync + composer --no-dev) -> migration review -> backup (code +
#        SQLite online backup) -> stop worker/scheduler -> sync -> migrate -> caches ->
#        FPM reload -> start worker/scheduler -> health check. A failure after the sync
#        restores the previous code automatically. .env, storage/, the database and the
#        secrets are never overwritten.
set -euo pipefail
HERE="$(dirname "$(readlink -f "$0")")"
# shellcheck source=infra/witu/lib.sh
. "$HERE/lib.sh"
need_root

REF="${1:?usage: witu-deploy-gateway <git-ref> [--migrate]}"; shift
MIGRATE=0
while [ $# -gt 0 ]; do
  case "$1" in
    --migrate) MIGRATE=1; shift ;;
    *) die "unknown option $1" ;;
  esac
done
[ -f "$GW_APP/.env" ] || die "$GW_APP/.env missing: run install-gateway.sh first (runbook step B2)"
grep -qE '^APP_KEY=base64:.+' "$GW_APP/.env" || die "APP_KEY is empty in $GW_APP/.env"

exec 9>/run/witu-deploy.lock
flock -n 9 || die "another deploy/rollback is running"

TS=$(date -u +%Y%m%dT%H%M%SZ)
TMP=$(mktemp -d); trap 'rm -rf "$TMP"' EXIT
as_deploy() { sudo -u "$DEPLOY_USER" -H "$@"; }

log "Fetching $REF in $SRC"
[ -z "$(as_deploy git -C "$SRC" status --porcelain --untracked-files=no)" ] || die "$SRC has local modifications; commit or discard them (never edit code on the server)"
as_deploy git -C "$SRC" fetch --quiet --tags --prune origin
SHA=$(as_deploy git -C "$SRC" rev-parse --verify --short "$REF^{commit}") || die "unknown ref $REF"
as_deploy git -C "$SRC" checkout --quiet --detach "$SHA"
echo "commit $SHA: $(as_deploy git -C "$SRC" log -1 --format=%s)"

log "Staging in $GW_STAGE"
install -d -m 750 -o "$DEPLOY_USER" -g "$GW_USER" "$GW_STAGE"
as_deploy rsync -a --delete --exclude=/vendor/ --exclude=/.env --exclude=/storage/secrets/ \
  --exclude='/database/*.sqlite*' "$SRC/gateway/" "$GW_STAGE/"
(cd "$GW_STAGE" && as_deploy "$PHP" "$COMPOSER" check-platform-reqs --no-dev --lock) || die "PHP $($PHP -r 'echo PHP_VERSION;') does not satisfy gateway/composer.lock"
(cd "$GW_STAGE" && as_deploy env COMPOSER_NO_INTERACTION=1 "$PHP" "$COMPOSER" install --no-dev --prefer-dist \
  --optimize-autoloader --no-progress --quiet)
as_deploy "$PHP" "$COMPOSER" audit --no-dev --locked --working-dir="$GW_STAGE" || die "composer audit reported vulnerable packages"
# rsync -a copies owner, group and mode from the git checkout (group witu-deploy), so the
# app user could not read the stage. Give it group read for the migration review below.
chgrp -R "$GW_USER" "$GW_STAGE"
chmod -R g+rX "$GW_STAGE"
mkdir -p "$GW_STAGE"/storage/{logs,framework/cache/data} "$GW_STAGE/bootstrap/cache"
chgrp -R "$GW_USER" "$GW_STAGE/storage" "$GW_STAGE/bootstrap/cache"
chmod -R g+rwX "$GW_STAGE/storage" "$GW_STAGE/bootstrap/cache"

log "Reviewing database migrations"
# Run as the app user: SQLite in WAL mode writes -wal/-shm files next to the database,
# and they must stay owned by witu-gw.
cp -p "$GW_APP/.env" "$GW_STAGE/.env"; chown "$DEPLOY_USER:$GW_USER" "$GW_STAGE/.env"
PRETEND=$(cd "$GW_STAGE" && sudo -u "$GW_USER" "$PHP" artisan migrate --pretend --force 2>&1) || { rm -f "$GW_STAGE/.env"; echo "$PRETEND"; die "migration review failed"; }
rm -f "$GW_STAGE/.env"
if printf '%s' "$PRETEND" | grep -q "Nothing to migrate"; then
  echo "no pending migrations"
elif [ "$MIGRATE" -ne 1 ]; then
  printf '%s\n' "$PRETEND" > "$BK/pending-gateway-migrations-$SHA.sql.txt"
  printf '%s\n' "$PRETEND" | head -80
  echo
  echo "Pending migrations found (full SQL: $BK/pending-gateway-migrations-$SHA.sql.txt)."
  echo "Review them, then re-run with --migrate. The live gateway has NOT been changed."
  exit 4
else
  echo "pending migrations approved (--migrate)"
fi

log "Backing up current release"
DEST="$BK/gateway-$TS"
mkdir -p "$DEST" && chmod 700 "$BK" "$DEST"
PREV_SHA=$(cat "$GW_APP/.deployed-sha" 2>/dev/null || echo none)
echo "$PREV_SHA" > "$DEST/previous-sha"
tar -C "$GW_APP" -czf "$DEST/code.tar.gz" --exclude=./storage --exclude=./.env .
cp -p "$GW_APP/.env" "$DEST/env.backup"
# Online, consistent copy even while the gateway is running.
sqlite3 "$GW_DB" ".backup '$DEST/gateway.sqlite'"
sqlite3 "$DEST/gateway.sqlite" 'PRAGMA integrity_check;' | grep -qx ok || die "SQLite backup failed its integrity check"
echo "backup: $DEST"

restore_code() {
  echo "!! Deploy failed: restoring previous code from $DEST"
  if [ "$PREV_SHA" != none ]; then
    mkdir -p "$TMP/restore" && tar -C "$TMP/restore" -xzf "$DEST/code.tar.gz"
    rsync -a --delete --exclude=/storage/ --exclude=/.env --exclude=/.deployed-sha "$TMP/restore/" "$GW_APP/"
    fix_gw_permissions
    gw_artisan config:cache >/dev/null 2>&1 || true
    gw_artisan route:cache >/dev/null 2>&1 || true
    systemctl reload php8.4-fpm
    # shellcheck disable=SC2086
    systemctl start $GW_UNITS || true
  fi
  echo "If migrations ran, the pre-deploy database is $DEST/gateway.sqlite (stop the units, copy it over $GW_DB, keep owner $GW_USER)."
}

log "Stopping worker and scheduler (the worker finishes its current job first)"
# shellcheck disable=SC2086
systemctl stop $GW_UNITS
trap 'restore_code; rm -rf "$TMP"' ERR

log "Sync"
rsync -a --delete --exclude=/storage/ --exclude=/.env --exclude=/.deployed-sha "$GW_STAGE/" "$GW_APP/"
mkdir -p "$GW_APP"/storage/{app,framework/cache/data,framework/views,logs} "$GW_APP/bootstrap/cache"
fix_gw_permissions

[ "$MIGRATE" -eq 1 ] && { log "Migrating"; gw_artisan migrate --force; }

log "Rebuilding caches"
gw_artisan optimize:clear >/dev/null
gw_artisan config:cache
gw_artisan route:cache
gw_artisan event:cache
systemctl reload php8.4-fpm
# shellcheck disable=SC2086
systemctl start $GW_UNITS

log "Health check"
gw_artisan about --only=environment >/dev/null
code=$(gw_probe)
echo "GET $GW_URL/summary without credentials -> $code (expected 401)"
[ "$code" = 401 ] || false
sleep 3
for u in $GW_UNITS; do printf '%-26s %s\n' "$u" "$(systemctl is-active "$u")"; systemctl is-active --quiet "$u" || false; done
trap - ERR

echo "$SHA" > "$GW_APP/.deployed-sha"; chown "$DEPLOY_USER:$GW_USER" "$GW_APP/.deployed-sha"
echo "$TS gateway $SHA (previous $PREV_SHA) backup=$DEST" >> "$BK/deploys.log"
find "$BK" -maxdepth 1 -type d -name 'gateway-*' | sort | head -n -10 | xargs -r rm -rf
log "Deployed gateway $SHA. Backup: $DEST"
