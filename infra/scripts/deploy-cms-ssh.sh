#!/usr/bin/env bash
# Deploy a CMS bundle to cPanel over SSH, atomically, with instant rollback.
# Run from YOUR machine (Git Bash / Linux / macOS), after building or downloading a bundle.
#
#   bash infra/scripts/deploy-cms-ssh.sh <user@host> dist/unihub-cms-<sha>.zip [php-binary]
#   bash infra/scripts/deploy-cms-ssh.sh <user@host> --rollback [php-binary]
#
# Server layout (created on first run, all under ~/unihub-cms):
#   releases/<sha>-<timestamp>/   extracted bundles (last 5 kept)
#   shared/.env                   you create this once (template copied on first run)
#   shared/storage/               persistent logs, sessions cache, etc.
#   current -> releases/...       point the (sub)domain document root at  ~/unihub-cms/current/public
#
# The first run stops after preparing shared/.env so you can fill it in; run again to finish.
set -euo pipefail
HOST="${1:?user@host required}"
ARG="${2:?bundle zip or --rollback required}"
PHP_BIN="${3:-/opt/cpanel/ea-php83/root/usr/bin/php}"

if [ "$ARG" = "--rollback" ]; then
  ssh "$HOST" "PHP_BIN='$PHP_BIN' bash -s" <<'REMOTE'
set -euo pipefail
cd ~/unihub-cms
PREV=$(cat previous_release 2>/dev/null || true)
[ -n "$PREV" ] && [ -d "$PREV" ] || { echo "No previous release recorded."; exit 1; }
CUR=$(readlink -f current)
ln -sfn "$PREV" current.new && mv -T current.new current
echo "$CUR" > previous_release
"$PHP_BIN" current/artisan config:cache >/dev/null && "$PHP_BIN" current/artisan route:cache >/dev/null
echo "Rolled back to $PREV. If the failed release ran migrations, restore the DB backup in ~/unihub-cms/backups/."
REMOTE
  exit 0
fi

ZIP="$ARG"
[ -f "$ZIP" ] || { echo "Bundle not found: $ZIP"; exit 1; }
if [ -f "$ZIP.sha256" ]; then (cd "$(dirname "$ZIP")" && sha256sum -c "$(basename "$ZIP").sha256"); fi
NAME=$(basename "$ZIP" .zip)

echo "==> Uploading $ZIP"
ssh "$HOST" 'mkdir -p ~/unihub-cms/incoming ~/unihub-cms/releases ~/unihub-cms/shared/storage ~/unihub-cms/backups && chmod 700 ~/unihub-cms'
scp -q "$ZIP" "$HOST:unihub-cms/incoming/$NAME.zip"

echo "==> Installing on server"
STATUS=0
ssh "$HOST" "PHP_BIN='$PHP_BIN' NAME='$NAME' bash -s" <<'REMOTE' || STATUS=$?
set -euo pipefail
cd ~/unihub-cms
"$PHP_BIN" -r 'exit(version_compare(PHP_VERSION, "8.3.0", ">=") ? 0 : 1);' || { echo "PHP >= 8.3 required: $PHP_BIN"; exit 1; }
for ext in sodium pdo_mysql mbstring openssl fileinfo intl; do
  "$PHP_BIN" -m | grep -qix "$ext" || { echo "Missing PHP extension: $ext (cPanel → Select PHP Version)"; exit 1; }
done

REL="releases/${NAME#unihub-cms-}-$(date -u +%Y%m%d%H%M%S)"
mkdir -p "$REL.tmp"
(cd "$REL.tmp" && unzip -q "../../incoming/$NAME.zip")
mv "$REL.tmp/cms" "$REL" && rmdir "$REL.tmp"
rm -f "incoming/$NAME.zip"

# Shared state
if [ ! -f shared/.env ]; then
  cp "$REL/.env.example" shared/.env && chmod 600 shared/.env
  "$PHP_BIN" -r '$f=$argv[1]; $s=file_get_contents($f); $s=preg_replace("/^APP_KEY=.*$/m","APP_KEY=base64:".base64_encode(random_bytes(32)),$s); file_put_contents($f,$s);' shared/.env
  echo
  echo "FIRST RUN: created ~/unihub-cms/shared/.env (APP_KEY generated)."
  echo "Edit it now (APP_URL, DB_*, MAIL_*, GATEWAY_MGMT_URL/TOKEN, CMS_ASSERTION_SECRET_KEY_PATH), then re-run this deploy."
  rm -rf "$REL"
  exit 3
fi
if [ ! -d shared/storage/framework ]; then cp -a "$REL/storage/." shared/storage/; fi
rm -rf "$REL/storage"
ln -s "$HOME/unihub-cms/shared/storage" "$REL/storage"
ln -s "$HOME/unihub-cms/shared/.env" "$REL/.env"
chmod -R u+rwX,go-w "$REL" && chmod -R u+rwX "$REL/bootstrap/cache"

# DB backup before migrating (mysqldump if available; credentials from .env)
envval() { grep -E "^$1=" shared/.env | tail -1 | cut -d= -f2- | sed -E 's/^"(.*)"$/\1/'; }
DB_DATABASE=$(envval DB_DATABASE); DB_USERNAME=$(envval DB_USERNAME); DB_PASSWORD=$(envval DB_PASSWORD); DB_HOST=$(envval DB_HOST)
if command -v mysqldump >/dev/null 2>&1 && [ -n "$DB_DATABASE" ]; then
  MYSQL_PWD="$DB_PASSWORD" mysqldump -h "${DB_HOST:-localhost}" -u "$DB_USERNAME" --single-transaction --no-tablespaces "$DB_DATABASE" | gzip > "backups/pre-$(basename "$REL").sql.gz" \
    && echo "DB backup: backups/pre-$(basename "$REL").sql.gz"
  ls -1t backups/*.sql.gz 2>/dev/null | tail -n +11 | xargs -r rm -f
fi

cd "$REL"
"$PHP_BIN" artisan migrate --force
"$PHP_BIN" artisan config:cache && "$PHP_BIN" artisan route:cache && "$PHP_BIN" artisan view:cache
cd ~/unihub-cms

PREV=$(readlink -f current 2>/dev/null || true)
ln -sfn "$HOME/unihub-cms/$REL" current.new && mv -T current.new current
[ -n "$PREV" ] && echo "$PREV" > previous_release
ls -1dt releases/*/ | tail -n +6 | xargs -r rm -rf
echo "Live: $(readlink -f current)"
REMOTE
if [ "$STATUS" -eq 3 ]; then exit 0; fi           # first run: fill in shared/.env, then re-run
if [ "$STATUS" -ne 0 ]; then echo "Deploy FAILED on the server (exit $STATUS); the live release was not changed."; exit "$STATUS"; fi

cat <<EOF

Done. Checklist:
  - Document root of the CMS (sub)domain → ~/unihub-cms/current/public   (cPanel → Domains; one-time)
  - Cron (one-time):  * * * * * cd ~/unihub-cms/current && $PHP_BIN artisan schedule:run >> /dev/null 2>&1
  - First install only, over SSH in ~/unihub-cms/current:
      $PHP_BIN artisan cms:assertion-keypair ~/unihub-cms/secrets/cms_assertion.key
      $PHP_BIN artisan cms:system-user
      $PHP_BIN artisan cms:bootstrap-owner you@yourdomain "Your Name"
  - Rollback:  bash infra/scripts/deploy-cms-ssh.sh $HOST --rollback $PHP_BIN
  - After editing shared/.env later, refresh the cached config:
      cd ~/unihub-cms/current && $PHP_BIN artisan config:cache
EOF
