#!/usr/bin/env bash
# Unihub AI — VPS preflight (READ-ONLY). Installs nothing, changes nothing.
# Usage: bash server-preflight.sh > preflight-$(hostname)-$(date -u +%Y%m%dT%H%M%SZ).txt 2>&1
# Paste the output into docs/preflight-results.md (redact IPs if the repo is shared).
set -u
section() { printf '\n===== %s =====\n' "$1"; }
have() { command -v "$1" >/dev/null 2>&1; }

section "Identity"
date -u +"%Y-%m-%dT%H:%M:%SZ"; hostname; id
if sudo -n true 2>/dev/null; then echo "sudo: passwordless available"; else echo "sudo: not passwordless (may still be available interactively)"; fi

section "OS / kernel / virtualisation"
cat /etc/os-release 2>/dev/null | grep -E '^(PRETTY_NAME|VERSION_ID)='
uname -srm
have systemd-detect-virt && systemd-detect-virt
ps -p 1 -o comm= | sed 's/^/pid1: /'

section "CPU (Ollama needs AVX/AVX2 for usable speed)"
nproc
grep -m1 'model name' /proc/cpuinfo
for f in avx avx2 avx512f fma f16c; do
  if grep -qw "$f" /proc/cpuinfo; then echo "$f: yes"; else echo "$f: NO"; fi
done

section "Memory (model ~1.1-1.9 GB resident; budget 4 GB total)"
free -m
swapon --show 2>/dev/null || true

section "Disk"
df -hT -x tmpfs -x devtmpfs 2>/dev/null

section "Existing services (Frappe/ERPNext co-location is a STOP risk)"
if have systemctl; then systemctl list-units --type=service --state=running --no-pager --no-legend | awk '{print $1}'; fi
ps -eo rss,comm --sort=-rss | head -15 | awk 'NR==1{print "RSS_MB COMMAND"; next}{printf "%6.0f %s\n",$1/1024,$2}'
for p in bench frappe gunicorn redis-server mariadbd mysqld supervisord nginx apache2 httpd ollama php-fpm; do
  pgrep -x "$p" >/dev/null 2>&1 && echo "running: $p"
done

section "Listening ports"
if have ss; then ss -tlnp 2>/dev/null | sed 1d | awk '{print $4, $6}'; else netstat -tlnp 2>/dev/null; fi

section "Firewall"
(sudo -n ufw status 2>/dev/null || echo "ufw: n/a or needs sudo")
(sudo -n nft list ruleset 2>/dev/null | head -20 || true)

section "Runtimes"
for b in php sqlite3 nginx certbot openssl curl ollama; do
  if have "$b"; then printf '%-8s ' "$b"; "$b" --version 2>&1 | head -1 || "$b" -v 2>&1 | head -1; else echo "$b: not installed"; fi
done
have php && php -m 2>/dev/null | grep -iE '^(sodium|pdo_sqlite|curl|mbstring|intl|pcntl|posix)$' | tr '\n' ' ' && echo

section "Outbound connectivity (HEAD only)"
for u in https://ollama.com https://registry.ollama.ai https://packagist.org; do
  code=$(curl -sS -o /dev/null -I -m 10 -w '%{http_code}' "$u" 2>/dev/null || echo fail); echo "$u -> $code"
done

section "Time sync"
have timedatectl && timedatectl 2>/dev/null | grep -E 'Time zone|synchronized'

echo; echo "Preflight complete. Nothing was modified."
