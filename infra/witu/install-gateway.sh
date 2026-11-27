#!/usr/bin/env bash
# Phase B, one time: prepare the server for gateway v2 beside the FastAPI gateway.
#
#   sudo bash infra/witu/install-gateway.sh
#
# Creates the witu-gw account, /opt/witu-gateway (code), /var/lib/witu-gateway (SQLite),
# /etc/witu-gateway/secrets, the .env with a new APP_KEY, the PHP-FPM pool, the worker and
# scheduler units (enabled, started by the first deploy) and the Apache vhost on
# 127.0.0.1:8081. Idempotent: existing .env, database and secrets are never replaced.
# FastAPI, Ollama, the CMS and the public Apache sites are not changed; Apache is
# reloaded gracefully after a configtest, and its config is restored if anything fails.
set -euo pipefail
HERE="$(cd "$(dirname "$0")" && pwd)"
# shellcheck source=infra/witu/lib.sh
. "$HERE/lib.sh"
need_root

[ -x "$PHP" ] || die "$PHP missing: run install-php.sh first"
command -v sqlite3 >/dev/null || die "sqlite3 missing: run install-php.sh first"
"$PHP" -m | grep -qix pdo_sqlite || die "php8.4 pdo_sqlite extension missing"
id "$DEPLOY_USER" >/dev/null 2>&1 || die "$DEPLOY_USER missing: run install-php.sh first"

log "Service account $GW_USER"
id "$GW_USER" >/dev/null 2>&1 || useradd --system --home-dir /nonexistent --shell /usr/sbin/nologin "$GW_USER"
usermod -aG "$GW_USER" "$DEPLOY_USER"

log "Directories"
install -d -m 750 -o "$DEPLOY_USER" -g "$GW_USER" "$GW_APP"
install -d -m 2770 -o "$GW_USER" -g "$GW_USER" "$GW_DATA"
[ -f "$GW_DB" ] || install -m 660 -o "$GW_USER" -g "$GW_USER" /dev/null "$GW_DB"
install -d -m 755 /etc/witu-gateway
install -d -m 700 -o "$GW_USER" -g "$GW_USER" "$GW_SECRETS"
install -d -m 750 -o "$DEPLOY_USER" -g "$GW_USER" "$GW_STAGE"   # the app user reads it for the migration review
ls -ld "$GW_APP" "$GW_DATA" "$GW_DB" "$GW_SECRETS"

log ".env"
if [ -f "$GW_APP/.env" ]; then
  echo "kept existing $GW_APP/.env"
else
  install -m 640 -o "$DEPLOY_USER" -g "$GW_USER" "$HERE/env/gateway.env.example" "$GW_APP/.env"
  K=$("$PHP" -r 'echo base64_encode(random_bytes(32));')
  sed -i "s|^APP_KEY=.*|APP_KEY=base64:$K|" "$GW_APP/.env"; unset K
  echo "created $GW_APP/.env with a new APP_KEY"
fi

log "PHP-FPM pools witu-gateway (management) and witu-gateway-public (apps, phase C)"
install -m 644 "$HERE/php/witu-gateway.pool.conf" /etc/php/8.4/fpm/pool.d/witu-gateway.conf
install -m 644 "$HERE/php/witu-gateway-public.pool.conf" /etc/php/8.4/fpm/pool.d/witu-gateway-public.conf
php-fpm8.4 -t
systemctl reload php8.4-fpm
for sock in witu-gateway witu-gateway-public; do
  for _ in $(seq 1 20); do [ -S "/run/php/$sock.sock" ] && break; sleep 0.5; done
  ls -l "/run/php/$sock.sock" || { journalctl -u php8.4-fpm -n 20 --no-pager; exit 1; }
done

log "systemd units (enabled now, started by the first deploy)"
for u in $GW_UNITS; do install -m 644 "$HERE/systemd/$u.service" "/etc/systemd/system/$u.service"; done
systemctl daemon-reload
# shellcheck disable=SC2086  # GW_UNITS is a space-separated list on purpose
systemctl enable $GW_UNITS

log "Apache: internal vhost on 127.0.0.1:8081"
BKA="$BK/apache-$(date -u +%Y%m%dT%H%M%SZ).tar.gz"
mkdir -p "$BK" && chmod 700 "$BK"
tar -C /etc -czf "$BKA" apache2
echo "backed up /etc/apache2 to $BKA"
restore_apache() {
  echo "FAILED: restoring /etc/apache2 from $BKA"
  rm -rf /etc/apache2.failed && mv /etc/apache2 /etc/apache2.failed && tar -C /etc -xzf "$BKA"
  apache2ctl configtest && systemctl reload apache2
}
trap restore_apache ERR
a2enmod -q proxy proxy_fcgi setenvif rewrite
install -m 644 "$HERE/apache/witu-gateway-internal.conf" /etc/apache2/sites-available/witu-gateway-internal.conf
install -m 644 "$HERE/apache/witu-gateway-public.conf" /etc/apache2/sites-available/witu-gateway-public.conf
a2ensite -q witu-gateway-internal witu-gateway-public
apache2ctl configtest
systemctl reload apache2
trap - ERR
sleep 1
for port in 8081 8082; do
  ss -ltn | grep -q "127.0.0.1:$port " && echo "listening on 127.0.0.1:$port (loopback only)"
  if ss -ltn | awk '{print $4}' | grep -Eq "^(0\.0\.0\.0|\*|\[::\]):$port\$"; then
    die "port $port is listening publicly: check /etc/apache2/ports.conf"
  fi
done

log "TLS certificate expiry on Server & alerts"
CERT=/etc/letsencrypt/live/ai.witulibrary.org/cert.pem
if [ -f "$CERT" ] && command -v setfacl >/dev/null; then
  # The certificate is public; certbot only makes these two directories root-only (0700).
  # Allow witu-gw to pass through them (x, no listing). Private keys stay 0600 root.
  setfacl -m "u:$GW_USER:x" /etc/letsencrypt/live /etc/letsencrypt/archive
  if sudo -u "$GW_USER" test -r "$CERT"; then
    sed -i "s|^GATEWAY_TLS_CERT_PATH=.*|GATEWAY_TLS_CERT_PATH=$CERT|" "$GW_APP/.env"
    sudo -u "$GW_USER" test -r /etc/letsencrypt/live/ai.witulibrary.org/privkey.pem && die "privkey.pem is readable by $GW_USER: check its permissions"
    echo "gateway reads $CERT (expiry date only; the private key stays root-only)"
    if [ -f "$GW_APP/vendor/autoload.php" ]; then
      gw_artisan config:cache >/dev/null
      systemctl restart witu-gateway-scheduler
    fi
  else
    echo "WARNING: $GW_USER still cannot read $CERT; certificate expiry will show as Unknown"
  fi
else
  echo "no $CERT (or setfacl missing): certificate expiry will show as Unknown"
fi

echo
echo "Done. Next: sudo witu-deploy-gateway origin/main   (runbook phase B, step B3)"
