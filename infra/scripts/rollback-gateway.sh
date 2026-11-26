#!/usr/bin/env bash
# Roll back to the previous release. With --restore-db <backup.sqlite.gz.age>,
# also restore the pre-deploy database (needed if the failed release ran a
# non-backward-compatible migration). Jobs admitted after the backup are lost;
# the worker recovers orphaned leases on start. Clients retry with the same
# idempotency key and get new jobs. (Full incident runbook: P5-3.)
set -euo pipefail
BASE=/opt/unihub-gateway
DB=/var/lib/unihub-gateway/gateway.sqlite
APP_USER=unihub-gw
PREV=$(cat "$BASE/previous_release")
[ -d "$PREV" ] || { echo "previous release $PREV missing"; exit 1; }

systemctl stop unihub-gateway-worker unihub-gateway-scheduler

if [ "${1:-}" = "--restore-db" ]; then
  BACKUP="${2:?backup file required}"
  KEY=/etc/unihub-gateway/secrets/backup.agekey
  cp -a "$DB" "$DB.before-rollback.$(date -u +%s)"
  age -d -i "$KEY" "$BACKUP" | gunzip > "$DB.restore"
  sqlite3 "$DB.restore" 'PRAGMA integrity_check;' | grep -qx ok
  mv "$DB.restore" "$DB" && rm -f "$DB-wal" "$DB-shm"
  chown "$APP_USER":"$APP_USER" "$DB"
fi

ln -sfn "$PREV" "$BASE/current.new" && mv -T "$BASE/current.new" "$BASE/current"
systemctl reload php8.4-fpm
systemctl start unihub-gateway-worker unihub-gateway-scheduler
curl -fsS --max-time 5 https://ai.kemmytech.com/healthz && echo "rolled back to $PREV"
