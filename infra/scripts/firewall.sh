#!/usr/bin/env bash
# Host firewall for the gateway VPS (PRD §08). Review before running; needs sudo.
# Usage: sudo bash firewall.sh <admin-ssh-cidr>
set -euo pipefail
ADMIN_CIDR="${1:?admin SSH CIDR required, e.g. 41.210.0.0/16}"

ufw --force reset
ufw default deny incoming
ufw default allow outgoing
ufw allow from "$ADMIN_CIDR" to any port 22 proto tcp
ufw allow 80/tcp
ufw allow 443/tcp
# Explicitly deny Ollama even though it binds to loopback (defence in depth).
ufw deny 11434/tcp
ufw --force enable
ufw status verbose

echo
echo "Verify from OUTSIDE the host:  nc -vz <vps-ip> 11434   (must fail)"
