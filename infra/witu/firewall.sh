#!/usr/bin/env bash
# Host firewall for ai.witulibrary.org (ufw). The preflight found ufw inactive: every
# service listening on a public address was reachable from the internet.
#
#   sudo bash infra/witu/firewall.sh --close-8080 [--dry-run]
#   sudo bash infra/witu/firewall.sh --keep-8080  [--dry-run]
#   sudo bash infra/witu/firewall.sh --8080-from 41.210.0.0/16 [--dry-run]   (office/VPN range only)
#
# Allows: SSH (the port sshd really uses, opened FIRST so the session cannot be cut off),
# HTTP 80 and HTTPS 443. Port 8080 (Apache: staff.witulibrary.org, the Koha staff site) is an
# explicit choice because closing it changes how library staff reach that site.
# Everything else inbound is denied; outbound is unrestricted (apt, certbot, GitHub, SMTP).
# Loopback services (MariaDB 3306, FastAPI 8000, gateway 8081/8082, Ollama 11434) are
# unaffected: they never listen publicly.
# Undo at any time:  sudo ufw disable
set -euo pipefail
[ "$(id -u)" -eq 0 ] || { echo "Run with sudo."; exit 1; }

MODE=""; FROM=""; DRY=0
while [ $# -gt 0 ]; do
  case "$1" in
    --close-8080) MODE=close; shift ;;
    --keep-8080) MODE=keep; shift ;;
    --8080-from) MODE=from; FROM="${2:?--8080-from needs a CIDR, e.g. 41.210.0.0/16}"; shift 2 ;;
    --dry-run) DRY=1; shift ;;
    *) echo "unknown option $1"; exit 1 ;;
  esac
done
[ -n "$MODE" ] || { echo "Choose what happens to port 8080 (Koha staff site): --close-8080, --keep-8080 or --8080-from <CIDR>"; exit 1; }
if [ "$MODE" = from ] && ! printf '%s' "$FROM" | grep -Eq '^[0-9]{1,3}(\.[0-9]{1,3}){3}(/[0-9]{1,2})?$'; then
  echo "--8080-from expects an IPv4 address or CIDR, e.g. 41.210.0.0/16"; exit 1
fi

SSH_PORT=$(sshd -T 2>/dev/null | awk '$1=="port"{print $2; exit}')
SSH_PORT=${SSH_PORT:-22}

run() { if [ "$DRY" -eq 1 ]; then echo "would run: ufw $*"; else ufw "$@"; fi; }

if ! command -v ufw >/dev/null; then
  if [ "$DRY" -eq 1 ]; then echo "would install: ufw"; else apt-get install -y -q ufw; fi
fi

echo "==> SSH on port $SSH_PORT stays open (added first)"
run allow "$SSH_PORT/tcp" comment 'SSH'
run allow 80/tcp comment 'HTTP (certbot, redirect)'
run allow 443/tcp comment 'HTTPS'
case "$MODE" in
  keep)  run allow 8080/tcp comment 'Koha staff site' ;;
  from)  run allow from "$FROM" to any port 8080 proto tcp comment 'Koha staff site (restricted)' ;;
  close) echo "==> 8080 not allowed: the Koha staff site is no longer reachable from outside" ;;
esac
run default deny incoming
run default allow outgoing
run --force enable

if [ "$DRY" -eq 0 ]; then
  echo
  ufw status verbose
  echo
  echo "Check from your PC that https://ai.witulibrary.org/ and https://witulibrary.org/ still open,"
  echo "and open a SECOND MobaXterm session before closing this one. Undo: sudo ufw disable"
fi
