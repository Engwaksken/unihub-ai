#!/usr/bin/env bash
# Back up the running FastAPI gateway (/opt/witu-ai-gateway) WITHOUT stopping it:
# source (venv excluded, pip freeze recorded instead), .env, systemd unit, and an online,
# consistent copy of the SQLite usage DB (sqlite backup API, safe while the service writes).
#
#   sudo bash infra/witu/backup-legacy-gateway.sh
#
# Output: /var/backups/witu-ai/legacy-gateway-<UTC timestamp>/  (root-only, 0700)
set -euo pipefail
[ "$(id -u)" -eq 0 ] || { echo "Run with sudo."; exit 1; }
LEGACY=/opt/witu-ai-gateway
DB="$LEGACY/database/gateway.db"
OUT="/var/backups/witu-ai/legacy-gateway-$(date -u +%Y%m%dT%H%M%SZ)"
umask 077
mkdir -p "$OUT"

tar -C /opt -czf "$OUT/source.tar.gz" --exclude='witu-ai-gateway/venv' --exclude='*.pyc' \
    --exclude='__pycache__' --exclude='witu-ai-gateway/database/gateway.db*' witu-ai-gateway
"$LEGACY/venv/bin/pip" freeze > "$OUT/requirements.freeze.txt" 2>/dev/null || true
"$LEGACY/venv/bin/python" --version > "$OUT/python-version.txt" 2>&1 || true
systemctl cat witu-ai-gateway > "$OUT/witu-ai-gateway.service.txt" 2>/dev/null || true

if [ -f "$DB" ]; then
  # Online backup through the SQLite API, using the gateway's own Python (no extra packages).
  "$LEGACY/venv/bin/python" - "$DB" "$OUT/gateway.db" <<'PY'
import sqlite3, sys
src = sqlite3.connect(f"file:{sys.argv[1]}?mode=ro", uri=True)
dst = sqlite3.connect(sys.argv[2])
src.backup(dst)
ok = dst.execute("PRAGMA integrity_check").fetchone()[0]
print("sqlite integrity:", ok)
dst.close(); src.close()
sys.exit(0 if ok == "ok" else 1)
PY
fi

(cd "$OUT" && sha256sum ./* > SHA256SUMS)
echo "Backup written to $OUT:"
ls -l "$OUT"
echo
echo "To review the code on your PC: in MobaXterm's SFTP pane, download $OUT/source.tar.gz"
echo "(it contains .env with secrets: keep it off GitHub and delete the local copy after review)."
