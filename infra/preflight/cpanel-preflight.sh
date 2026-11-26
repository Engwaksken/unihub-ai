#!/usr/bin/env bash
# Unihub AI — cPanel shared-hosting preflight (READ-ONLY; installs/changes nothing).
# Run from your machine:   ssh <user>@<cpanel-host> 'bash -s' < infra/preflight/cpanel-preflight.sh
# Answers blocker B-2 (docs/work-plan.md). Paste the output into docs/preflight-results.md
# (redact the account name / IP if the repo is shared).
set -u
section() { printf '\n===== %s =====\n' "$1"; }
have() { command -v "$1" >/dev/null 2>&1; }

section "Account"
date -u +"%Y-%m-%dT%H:%M:%SZ"; whoami; echo "home: $HOME"; hostname
cat /etc/os-release 2>/dev/null | grep -E '^PRETTY_NAME=' || uname -sr

section "PHP binaries (cPanel EasyApache / CloudLinux alt-php)"
REQ="sodium pdo_mysql mbstring openssl fileinfo intl curl zip tokenizer ctype dom xml"
for php in $(ls -d /opt/cpanel/ea-php8*/root/usr/bin/php /opt/alt/php8*/usr/bin/php 2>/dev/null) $(command -v php 2>/dev/null); do
  [ -x "$php" ] || continue
  ver=$("$php" -r 'echo PHP_VERSION;' 2>/dev/null) || continue
  mods=$("$php" -m 2>/dev/null | tr 'A-Z' 'a-z')
  missing=""
  for m in $REQ; do echo "$mods" | grep -qx "$m" || missing="$missing $m"; done
  printf '%-48s %-8s missing:%s\n' "$php" "$ver" "${missing:- none}"
done
echo "default 'php' on PATH: $(command -v php 2>/dev/null || echo none)"
have php && php -r 'echo "memory_limit=".ini_get("memory_limit")." max_execution_time=".ini_get("max_execution_time")." disable_functions=".ini_get("disable_functions").PHP_EOL;'

section "Tools available over SSH"
for b in composer git node npm mysql mariadb unzip zip tar crontab; do
  if have "$b"; then printf '%-9s %s\n' "$b" "$("$b" --version 2>&1 | head -1)"; else echo "$b: not available"; fi
done

section "Database server"
(mysql --version 2>/dev/null || mariadb --version 2>/dev/null) || echo "mysql client not on PATH (check cPanel → MySQL Databases for the server version)"

section "Disk / quota"
df -h "$HOME" 2>/dev/null | tail -1
(quota -s 2>/dev/null | tail -2) || true

section "Outbound HTTPS (CMS must reach the gateway management API)"
for u in https://github.com https://repo.packagist.org https://ai.kemmytech.com/healthz; do
  code=$(curl -sS -o /dev/null -m 10 -w '%{http_code}' "$u" 2>/dev/null || echo fail); echo "$u -> $code"
done
echo "(ai.kemmytech.com failing is expected until the gateway VPS is deployed)"

section "Egress IP (for the gateway management allowlist, ADR D6)"
echo "egress IPv4: $(curl -4 -sS -m 10 https://api.ipify.org 2>/dev/null || echo unknown)"
echo "Ask the host whether this IP is dedicated or shared with other customers."

section "Document root options"
ls -ld "$HOME/public_html" 2>/dev/null
echo "Can you set a (sub)domain document root outside public_html? Check cPanel → Domains."

echo; echo "Preflight complete. Nothing was modified."
