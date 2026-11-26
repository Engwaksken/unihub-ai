#!/usr/bin/env bash
# Point ai.witulibrary.org at the Laravel CMS (PHP 8.4 FPM) and proxy /v1 to the FastAPI gateway.
#
#   sudo bash infra/witu/apply-apache.sh            # apply
#   sudo bash infra/witu/apply-apache.sh --restore /var/backups/witu-ai/apache-<ts>.tar.gz
#
# Safety: backs up all of /etc/apache2 first, keeps certbot's certificate paths, runs
# `apache2ctl configtest` and restores the backup automatically if anything fails.
set -euo pipefail
[ "$(id -u)" -eq 0 ] || { echo "Run with sudo."; exit 1; }
HERE="$(cd "$(dirname "$0")" && pwd)"
SITE=/etc/apache2/sites-available/ai.witulibrary.org-le-ssl.conf

restore() {
  echo "==> Restoring /etc/apache2 from $1"
  rm -rf /etc/apache2.failed && mv /etc/apache2 /etc/apache2.failed
  tar -C /etc -xzf "$1"
  apache2ctl configtest && systemctl restart apache2
  echo "Restored. The rejected config is kept in /etc/apache2.failed for inspection."
}

if [ "${1:-}" = "--restore" ]; then restore "${2:?backup tarball required}"; exit 0; fi

[ -S /run/php/witu-cms.sock ] || { echo "PHP-FPM socket /run/php/witu-cms.sock missing: run install-php.sh first."; exit 1; }
[ -f /var/www/witu-ai/public/index.php ] || { echo "/var/www/witu-ai/public/index.php missing."; exit 1; }
[ -f /var/www/witu-ai/vendor/autoload.php ] || { echo "vendor/ missing: run deploy-cms.sh first, so the site works the moment Apache switches."; exit 1; }
[ -f "$SITE" ] || { echo "$SITE not found."; exit 1; }

# Certificate paths must match what certbot manages.
for k in SSLCertificateFile SSLCertificateKeyFile; do
  cur=$(awk -v k="$k" '$1==k{print $2; exit}' "$SITE")
  new=$(awk -v k="$k" '$1==k{print $2; exit}' "$HERE/apache/ai.witulibrary.org-le-ssl.conf")
  [ "$cur" = "$new" ] || { echo "$k differs (current: $cur, template: $new). Edit the template to match, then re-run."; exit 1; }
done

BK="/var/backups/witu-ai/apache-$(date -u +%Y%m%dT%H%M%SZ).tar.gz"
mkdir -p /var/backups/witu-ai && chmod 700 /var/backups/witu-ai
tar -C /etc -czf "$BK" apache2
echo "==> Backed up /etc/apache2 to $BK"

trap 'echo "FAILED — rolling back"; restore "$BK"' ERR

# mod_php (prefork) cannot coexist with mpm_event; PHP-FPM replaces it. Refuse if another
# enabled site might still rely on mod_php.
if apache2ctl -M 2>/dev/null | grep -q 'php_module'; then
  others=""
  for f in /etc/apache2/sites-enabled/*; do
    n=$(basename "$f"); case "$n" in ai.witulibrary.org*|000-default*|'*') ;; *) others="$others $n" ;; esac
  done
  if [ -n "$others" ] && [ "${FORCE_MPM:-0}" != 1 ]; then
    trap - ERR
    echo "mod_php is enabled and these other sites are enabled: $others"
    echo "Check they don't need mod_php, then re-run with: sudo FORCE_MPM=1 bash $0"
    exit 1
  fi
  for f in /etc/apache2/mods-enabled/php*.load; do if [ -e "$f" ]; then a2dismod -q "$(basename "$f" .load)"; fi; done
  a2dismod -q mpm_prefork || true
  a2enmod -q mpm_event
fi
a2enmod -q proxy proxy_http proxy_fcgi setenvif headers rewrite ssl
# Debian's php8.x-fpm conf would hand ALL .php under any vhost to its default pool; we route explicitly.
a2disconf -q php8.4-fpm 2>/dev/null || true

install -m 644 "$HERE/apache/ai.witulibrary.org-le-ssl.conf" "$SITE"
a2ensite -q ai.witulibrary.org-le-ssl
apache2ctl configtest
systemctl restart apache2
trap - ERR

echo "==> Smoke test"
sleep 1
for u in / /login /up /v1/health; do
  printf '%-12s %s\n' "$u" "$(curl -s -o /dev/null -m 15 -w '%{http_code}' "https://ai.witulibrary.org$u")"
done
echo "Expected: / 302, /login 200, /up 200, /v1/health 200. If not: sudo bash $0 --restore $BK"
