#!/usr/bin/env bash
# Roll the WITU AI CMS back to the code (and optionally the database) saved by a deploy.
#
#   sudo witu-rollback-cms                         # list available backups
#   sudo witu-rollback-cms <backup-dir>            # restore code only
#   sudo witu-rollback-cms <backup-dir> --with-db  # restore code AND database (data written
#                                                  # since that backup is lost — export it first)
#   sudo witu-rollback-cms --db-only <file.sql.gz> # restore just a database dump (nightly backups)
set -euo pipefail
HERE="$(dirname "$(readlink -f "$0")")"   # resolves /usr/local/sbin symlinks
# shellcheck source=infra/witu/lib.sh
. "$HERE/lib.sh"
need_root

restore_db() {
  local dump=$1 db
  db=$(envval DB_DATABASE); [ -n "$db" ] || die "DB_DATABASE missing in $APP/.env"
  gzip -t "$dump" || die "$dump is not a valid gzip file"
  log "Safety dump of the CURRENT database before overwriting it"
  dump_db "$BK/pre-restore-$(date -u +%Y%m%dT%H%M%SZ).sql.gz"
  log "Restoring $db from $dump"
  # root via unix socket: drop + recreate so tables added by newer migrations disappear too.
  # Database-level grants for witu_ai_user are kept (they are stored by name).
  mariadb -e "DROP DATABASE \`$db\`; CREATE DATABASE \`$db\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
  gunzip -c "$dump" | mariadb "$db"
  echo "database restored"
}

if [ $# -eq 0 ]; then
  echo "Deploy backups (newest last):"; ls -1d "$BK"/cms-* 2>/dev/null || echo "  none"
  echo; echo "Nightly DB backups:"; ls -1 "$BK"/nightly/*.sql.gz 2>/dev/null | tail -5 || true
  [ -f "$BK/deploys.log" ] && { echo; echo "Recent deploys:"; tail -5 "$BK/deploys.log"; }
  exit 0
fi

exec 9>/run/witu-deploy.lock
flock -n 9 || die "a deploy/rollback is running"

if [ "$1" = "--db-only" ]; then
  artisan down --retry=30 || true
  restore_db "${2:?dump file required}"
  artisan up
  exit 0
fi

DEST="${1%/}"; WITH_DB=0; [ "${2:-}" = "--with-db" ] && WITH_DB=1
[ -f "$DEST/code.tar.gz" ] || die "$DEST/code.tar.gz not found"
TMP=$(mktemp -d); trap 'rm -rf "$TMP"' EXIT

log "Rolling back to $(cat "$DEST/previous-sha" 2>/dev/null || echo '?') from $DEST"
tar -C "$TMP" -xzf "$DEST/code.tar.gz"
artisan down --retry=30 || true
rsync -a --delete --exclude=/storage/ --exclude=/.env "$TMP/" "$APP/"
fix_permissions
[ "$WITH_DB" -eq 1 ] && restore_db "$DEST/db.sql.gz"
artisan optimize:clear >/dev/null
artisan config:cache && artisan route:cache && artisan view:cache && artisan event:cache
systemctl reload php8.4-fpm
artisan up
health_check || die "health check failed after rollback — see storage/logs and /var/log/apache2/witu-ai-error.log"
echo "$(date -u +%Y%m%dT%H%M%SZ) ROLLBACK to $(cat "$APP/.deployed-sha" 2>/dev/null) from $DEST db=$WITH_DB" >> "$BK/deploys.log"
log "Rollback complete."
