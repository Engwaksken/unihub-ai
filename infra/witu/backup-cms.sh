#!/usr/bin/env bash
# Nightly WITU AI backup (installed as a root cron job by install-ops.sh):
#   - MariaDB dump of witu_ai (consistent, --single-transaction)
#   - storage/app (uploaded logo/favicon etc.) and a copy of .env
#   - the legacy gateway SQLite DB (online backup), while it is still in service
# Keeps 14 days. Restore: sudo witu-rollback-cms --db-only /var/backups/witu-ai/nightly/<file>.sql.gz
#
# Copy backups OFF the VPS regularly (MobaXterm SFTP download, docs/witu/backup-restore.md):
# a backup on the same disk does not survive the loss of the server.
set -euo pipefail
HERE="$(dirname "$(readlink -f "$0")")"   # resolves /usr/local/sbin symlinks
# shellcheck source=infra/witu/lib.sh
. "$HERE/lib.sh"
need_root
umask 077
OUT="$BK/nightly"; TS=$(date -u +%Y%m%dT%H%M%SZ)
mkdir -p "$OUT"

dump_db "$OUT/witu_ai-$TS.sql.gz"
tar -C "$APP" -czf "$OUT/storage-app-$TS.tar.gz" storage/app
cp -p "$APP/.env" "$OUT/env-$TS"
if [ -f /opt/witu-ai-gateway/database/gateway.db ]; then
  sqlite3 /opt/witu-ai-gateway/database/gateway.db ".backup '$OUT/legacy-gateway-$TS.db'" && gzip "$OUT/legacy-gateway-$TS.db"
fi
find "$OUT" -type f -mtime +14 -delete
echo "$(date -u +%FT%TZ) nightly backup ok: $(du -sh "$OUT" | cut -f1) in $OUT"
