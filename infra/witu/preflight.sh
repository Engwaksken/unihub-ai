#!/usr/bin/env bash
# WITU AI — VPS preflight for ai.witulibrary.org (Debian 12, Apache, MariaDB).
# READ-ONLY: installs nothing, changes nothing, prints no secret values.
#
#   sudo bash /var/www/witu-ai-source/infra/witu/preflight.sh | tee ~/witu-preflight.txt
#
# Paste the output back for review before running any install/deploy step (runbook step 1).
set -u
section() { printf '\n===== %s =====\n' "$1"; }
have() { command -v "$1" >/dev/null 2>&1; }
APP=/var/www/witu-ai
SRC=/var/www/witu-ai-source
LEGACY=/opt/witu-ai-gateway

section "Host"
date -u +"%Y-%m-%dT%H:%M:%SZ"; hostname; grep PRETTY_NAME /etc/os-release; uname -r
nproc; free -m; swapon --show
df -h / /var /opt 2>/dev/null | sort -u

section "Memory per service (RSS MB)"
for s in ollama witu-ai-gateway mariadb apache2 php8.2-fpm php8.3-fpm php8.4-fpm; do
  if systemctl list-unit-files "$s.service" >/dev/null 2>&1 && systemctl is-active --quiet "$s"; then
    pids=$(systemctl show -p MainPID --value "$s"); cg=$(systemctl show -p ControlGroup --value "$s")
    rss=0; for p in $(cat "/sys/fs/cgroup$cg/cgroup.procs" 2>/dev/null || echo "$pids"); do r=$(awk '/VmRSS/{print $2}' "/proc/$p/status" 2>/dev/null); rss=$((rss + ${r:-0})); done
    printf '%-18s active   %6d MB\n' "$s" $((rss / 1024))
  else
    st=$(systemctl is-active "$s" 2>/dev/null); printf '%-18s %s\n' "$s" "${st:-absent}"
  fi
done

section "Listening sockets (anything not on 127.0.0.1/::1 is public)"
# Debian's awk is mawk, which has no \b word boundary: match "port then non-digit or end".
ss -ltnp 2>/dev/null | awk 'NR==1 || /:(22|80|443|3306|8000|8081|11434|9000)([^0-9]|$)/'
echo "-- other public listeners (should be none):"
ss -ltnH 2>/dev/null | awk '$4 !~ /^(127\.|\[::1\]|::1)/ && $4 !~ /:(22|80|443)$/ {print "  " $4; n++} END {if (!n) print "  none"}'

section "Firewall"
(have ufw && ufw status verbose) || (have nft && nft list ruleset 2>/dev/null | head -40) || echo "no ufw/nft found"

section "PHP"
for p in /usr/bin/php8.*; do [ -x "$p" ] && echo "$p $("$p" -r 'echo PHP_VERSION;')"; done
echo "default php: $(php -r 'echo PHP_VERSION;' 2>/dev/null || echo none)"
REQ="sodium pdo_mysql mbstring openssl fileinfo intl curl zip tokenizer ctype dom xml bcmath pdo_sqlite opcache"
for p in /usr/bin/php8.3 /usr/bin/php8.4; do
  [ -x "$p" ] || continue
  mods=$("$p" -m | tr 'A-Z' 'a-z'); miss=""
  for m in $REQ; do echo "$mods" | grep -qx "$m" || { [ "$m" = opcache ] && echo "$mods" | grep -qx "zend opcache"; } || miss="$miss $m"; done
  echo "$p missing:${miss:- none}"
done
ls /etc/apt/sources.list.d/ 2>/dev/null; grep -rl "packages.sury.org" /etc/apt/ 2>/dev/null || echo "sury PHP repo: not configured"
# As root, Composer asks "Continue as root/super user [yes]?" and would wait forever.
have composer && COMPOSER_ALLOW_SUPERUSER=1 composer --no-interaction --version </dev/null 2>/dev/null | head -1 || echo "composer: not installed"
have node && echo "node $(node --version)" || echo "node: not installed (fine: assets are built by CI/locally)"

section "Apache"
apache2ctl -v 2>/dev/null | head -1
apache2ctl -M 2>/dev/null | grep -E 'mpm_|php|proxy|rewrite|headers|ssl|setenvif|cgi' | sort
[ -d /etc/koha ] && { echo "Koha present (needs mpm_itk; the WITU kit never changes the MPM):"; ls /etc/koha/sites 2>/dev/null | sed 's/^/  koha instance: /'; }
ls -l /etc/apache2/sites-enabled/
apache2ctl -S 2>&1 | head -30
grep -nE 'DocumentRoot|ServerName|ServerAlias|ProxyPass|SSLCertificate|Include' /etc/apache2/sites-available/ai.witulibrary.org*.conf 2>/dev/null

section "TLS / certbot"
(have certbot && certbot certificates 2>/dev/null | grep -E 'Certificate Name|Domains|Expiry') || echo "certbot not found"
systemctl list-timers certbot.timer --no-pager 2>/dev/null | head -3

section "MariaDB (no credentials printed)"
mariadb --version 2>/dev/null || mysql --version
mariadb -N -e "SELECT @@bind_address, @@port, @@innodb_buffer_pool_size/1048576 AS pool_mb, @@max_connections;" 2>/dev/null || echo "(run as root for DB details)"
mariadb -N -e "SHOW DATABASES;" 2>/dev/null | grep -vE '^(information_schema|performance_schema|mysql|sys)$' | sed 's/^/database: /'
mariadb -N -e "SELECT CONCAT(user,'@',host) FROM mysql.user WHERE user='root' OR user LIKE 'witu%';" 2>/dev/null | sed 's/^/account: /'

section "Ollama"
systemctl show ollama -p Environment --value 2>/dev/null | tr ' ' '\n' | grep -E '^OLLAMA_' | grep -v KEY
curl -sS -m 5 http://127.0.0.1:11434/api/version; echo
curl -sS -m 5 http://127.0.0.1:11434/api/tags | grep -o '"name":"[^"]*"' ; echo
curl -sS -m 5 http://127.0.0.1:11434/api/ps | grep -o '"name":"[^"]*"\|"size_vram":[0-9]*' ; echo

section "Legacy FastAPI gateway ($LEGACY)"
systemctl is-active witu-ai-gateway 2>/dev/null; systemctl cat witu-ai-gateway 2>/dev/null | grep -E 'ExecStart|User|WorkingDirectory|Environment'
ls -la "$LEGACY" 2>/dev/null
find "$LEGACY" -maxdepth 2 -name '*.py' -not -path '*/venv/*' 2>/dev/null
[ -f "$LEGACY/.env" ] && { echo ".env keys (values hidden):"; cut -d= -f1 "$LEGACY/.env" | grep -v '^#' | sed 's/^/  /'; }
[ -f "$LEGACY/database/gateway.db" ] && { ls -l "$LEGACY/database/gateway.db"; have sqlite3 && sqlite3 "$LEGACY/database/gateway.db" '.tables'; }
"$LEGACY/venv/bin/pip" freeze 2>/dev/null | grep -iE 'fastapi|uvicorn|pydantic|httpx|sqlalchemy'
echo "-- HTTP surface (no key sent; 401/403 on /v1 is the expected answer)"
for u in /health /v1/models /docs /openapi.json; do
  printf '%-16s %s\n' "$u" "$(curl -s -o /dev/null -m 5 -w '%{http_code}' "http://127.0.0.1:8000$u")"
done
curl -s -m 5 http://127.0.0.1:8000/openapi.json | grep -o '"/[^"]*":{"[a-z]*"' | sed 's/{//'

section "Laravel CMS ($APP) and source ($SRC)"
ls -la "$APP" | head -30
[ -f "$APP/artisan" ] && echo "artisan: present" || echo "artisan: MISSING (CMS files not at $APP root?)"
[ -d "$APP/vendor" ] && echo "vendor: present" || echo "vendor: missing (expected; deploy installs it)"
[ -f "$APP/public/build/manifest.json" ] && echo "public/build: present" || echo "public/build: missing (expected; deploy installs CI-built assets)"
[ -f "$APP/.env" ] && { echo ".env present, keys:"; cut -d= -f1 "$APP/.env" | grep -v '^#' | tr '\n' ' '; echo; } || echo ".env: none yet"
stat -c '%U:%G %a %n' "$APP" "$APP/storage" "$APP/bootstrap/cache" 2>/dev/null
git -C "$SRC" remote -v 2>/dev/null | head -1; git -C "$SRC" log --oneline -1 2>/dev/null; git -C "$SRC" status -s 2>/dev/null | head -5
[ -d "$SRC/cms" ] && diff -rq "$SRC/cms" "$APP" -x vendor -x node_modules -x storage -x .env -x build 2>/dev/null | head -15

section "Users / cron"
id witu-deploy 2>/dev/null || echo "witu-deploy: not created"
id witu-cms 2>/dev/null || echo "witu-cms: not created"
ls /etc/cron.d/ 2>/dev/null

echo; echo "Preflight complete. Nothing was modified."
