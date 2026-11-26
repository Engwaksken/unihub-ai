#!/usr/bin/env bash
# Atomic release deploy for the gateway (run on the VPS as a sudo-capable admin).
# Layout:
#   /opt/unihub-gateway/releases/<timestamp>-<sha>/   code
#   /opt/unihub-gateway/shared/.env, shared/storage/  persisted
#   /opt/unihub-gateway/current -> releases/...       active
#   /var/lib/unihub-gateway/gateway.sqlite            DB (WAL)
# Usage: deploy-gateway.sh <git-ref> [repo-url]
set -euo pipefail
REF="${1:?git ref (tag/commit) required}"
REPO="${2:-${UNIHUB_REPO:?set UNIHUB_REPO or pass repo url}}"
BASE=/opt/unihub-gateway
DB=/var/lib/unihub-gateway/gateway.sqlite
PHP=/usr/bin/php8.4
APP_USER=unihub-gw

TMP=$(mktemp -d)
trap 'rm -rf "$TMP"' EXIT
git clone --quiet "$REPO" "$TMP/src"
git -C "$TMP/src" checkout --quiet "$REF"
SHA=$(git -C "$TMP/src" rev-parse --short HEAD)
REL="$BASE/releases/$(date -u +%Y%m%d%H%M%S)-$SHA"

echo "==> Building $REL"
mkdir -p "$REL"
cp -a "$TMP/src/gateway/." "$REL/"
rm -rf "$REL/storage"
ln -s "$BASE/shared/storage" "$REL/storage"
ln -s "$BASE/shared/.env" "$REL/.env"
(cd "$REL" && COMPOSER_ALLOW_SUPERUSER=1 composer install --no-dev --prefer-dist --no-interaction --optimize-autoloader --quiet)
chown -R root:"$APP_USER" "$REL" && chmod -R g-w,o-rwx "$REL"

echo "==> Pre-migration backup"
"$(dirname "$0")/backup-gateway.sh" "pre-deploy-$SHA"

echo "==> Pausing worker, migrating"
systemctl stop unihub-gateway-worker   # waits for in-flight job (TimeoutStopSec)
sudo -u "$APP_USER" "$PHP" "$REL/artisan" migrate --force
sudo -u "$APP_USER" "$PHP" "$REL/artisan" config:cache
sudo -u "$APP_USER" "$PHP" "$REL/artisan" route:cache

echo "==> Switching current -> $REL"
PREV=$(readlink -f "$BASE/current" || true)
ln -sfn "$REL" "$BASE/current.new" && mv -T "$BASE/current.new" "$BASE/current"
echo "$PREV" > "$BASE/previous_release"

systemctl reload php8.4-fpm
systemctl start unihub-gateway-worker
systemctl restart unihub-gateway-scheduler

echo "==> Health check"
sleep 2
curl -fsS --max-time 5 https://ai.kemmytech.com/healthz && echo
systemctl is-active --quiet unihub-gateway-worker && echo "worker: active"

# Keep last 5 releases
ls -1dt "$BASE"/releases/* | tail -n +6 | xargs -r rm -rf
echo "Deployed $SHA. Rollback: infra/scripts/rollback-gateway.sh"
