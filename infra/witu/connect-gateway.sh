#!/usr/bin/env bash
# Phase B: connect the CMS to gateway v2. Safe to re-run.
#
#   sudo bash infra/witu/connect-gateway.sh [--rotate-token]
#
# 1. gateway:secrets init       key pepper, payload key, token store (kept if present)
# 2. CMS public key -> gateway  derived from the CMS secret key file, so nothing is pasted
# 3. management token           minted once and written straight into the CMS .env;
#                               never printed, never on a command line (--rotate-token
#                               issues a new one and invalidates the old)
# 4. CMS config cache + cms:gateway-check (exit 0 only if the CMS authenticates end to end)
set -euo pipefail
HERE="$(cd "$(dirname "$0")" && pwd)"
# shellcheck source=infra/witu/lib.sh
. "$HERE/lib.sh"
need_root

ROTATE=0
[ "${1:-}" = --rotate-token ] && ROTATE=1
[ -f "$GW_APP/vendor/autoload.php" ] || die "gateway not deployed yet: run witu-deploy-gateway first (step B3)"
[ -f "$APP/.env" ] || die "$APP/.env missing"
CMS_KEY=$(envval CMS_ASSERTION_SECRET_KEY_PATH)
[ -n "$CMS_KEY" ] && [ -r "$CMS_KEY" ] || die "CMS assertion key '$CMS_KEY' not found: run cms:assertion-keypair (runbook step 9)"

log "Gateway secrets"
gw_artisan gateway:secrets init

log "CMS public key -> gateway"
PUB=$("$PHP" -r '$k = base64_decode(trim((string) file_get_contents($argv[1])), true);
  if ($k === false || strlen($k) !== SODIUM_CRYPTO_SIGN_SECRETKEYBYTES) { fwrite(STDERR, "bad key file\n"); exit(1); }
  echo base64_encode(sodium_crypto_sign_publickey_from_secretkey($k));' "$CMS_KEY")
gw_artisan gateway:secrets cms-key --public-key="$PUB"
echo "fingerprint: $(printf '%s' "$PUB" | base64 -d | sha256sum | cut -c1-16)"

log "Management token"
if [ "$ROTATE" -eq 0 ] && grep -qE '^GATEWAY_MGMT_TOKEN=uhm_.+' "$APP/.env"; then
  echo "the CMS already has a token (use --rotate-token to replace it)"
else
  OPT=(); [ "$ROTATE" -eq 1 ] && OPT=(--replace)
  # The token only ever lives in this process's environment and in the CMS .env (640).
  TOKEN=$(gw_artisan gateway:secrets mgmt-token "${OPT[@]}" | grep -oE 'uhm_[A-Za-z0-9_-]+' | tail -1)
  [ -n "$TOKEN" ] || die "gateway did not return a management token"
  grep -qE '^GATEWAY_MGMT_TOKEN=' "$APP/.env" || echo 'GATEWAY_MGMT_TOKEN=' >> "$APP/.env"
  TOKEN="$TOKEN" perl -pi -e 's/^GATEWAY_MGMT_TOKEN=.*/GATEWAY_MGMT_TOKEN=$ENV{TOKEN}/' "$APP/.env"
  unset TOKEN
  echo "token written to $APP/.env (not shown)"
fi
grep -qE '^GATEWAY_MGMT_URL=' "$APP/.env" || echo 'GATEWAY_MGMT_URL=' >> "$APP/.env"
URL="$GW_URL" perl -pi -e 's/^GATEWAY_MGMT_URL=.*/GATEWAY_MGMT_URL=$ENV{URL}/' "$APP/.env"
chown "$DEPLOY_USER:$APP_USER" "$APP/.env" && chmod 640 "$APP/.env"

log "CMS: reload configuration and test the connection"
artisan config:cache >/dev/null
systemctl reload php8.4-fpm
artisan cms:gateway-check
