#!/usr/bin/env bash
# Online-safe SQLite backup, compressed and encrypted with age (PRD §09).
# The DB holds only ciphertext payloads; payload keys are NOT included —
# back those up separately to an offline location (runbook: P5-3).
# Install as /usr/local/sbin/backup-gateway.sh, then daily via /etc/cron.d/unihub-gateway-backup:
#   15 2 * * * root /usr/local/sbin/backup-gateway.sh daily
set -euo pipefail
LABEL="${1:-manual}"
DB=/var/lib/unihub-gateway/gateway.sqlite
OUT=/var/backups/unihub-gateway
RECIPIENT_FILE=/etc/unihub-gateway/backup.recipient   # age public key
mkdir -p "$OUT" && chmod 700 "$OUT"
TS=$(date -u +%Y%m%dT%H%M%SZ)
TMP=$(mktemp)
trap 'rm -f "$TMP"' EXIT

sqlite3 "$DB" ".backup '$TMP'"
sqlite3 "$TMP" 'PRAGMA integrity_check;' | grep -qx ok
gzip -c "$TMP" | age -R "$RECIPIENT_FILE" > "$OUT/gateway-$TS-$LABEL.sqlite.gz.age"
chmod 600 "$OUT"/*.age

# Local retention: 14 days (off-host copy policy: Q-07)
find "$OUT" -name 'gateway-*.age' -mtime +14 -delete
echo "$OUT/gateway-$TS-$LABEL.sqlite.gz.age"
