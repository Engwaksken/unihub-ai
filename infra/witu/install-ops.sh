#!/usr/bin/env bash
# Install the WITU AI ops tooling as ROOT-OWNED copies (so the deploy account cannot alter
# what it is allowed to run with sudo), plus cron, logrotate and the deploy sudoers rule.
# Re-run after pulling changes to infra/witu/ (review the diff first).
#
#   sudo bash /var/www/witu-ai-source/infra/witu/install-ops.sh
set -euo pipefail
[ "$(id -u)" -eq 0 ] || { echo "Run with sudo."; exit 1; }
HERE="$(cd "$(dirname "$0")" && pwd)"
LIB=/usr/local/lib/witu-ai

install -d -m 755 -o root -g root "$LIB"
for f in lib.sh deploy-cms.sh rollback-cms.sh backup-cms.sh; do
  install -m 755 -o root -g root "$HERE/$f" "$LIB/$f"
done
ln -sfn "$LIB/deploy-cms.sh" /usr/local/sbin/witu-deploy-cms
ln -sfn "$LIB/rollback-cms.sh" /usr/local/sbin/witu-rollback-cms
ln -sfn "$LIB/backup-cms.sh" /usr/local/sbin/witu-backup

install -d -m 700 /var/backups/witu-ai
install -d -m 750 -o witu-deploy -g witu-deploy /home/witu-deploy/incoming

# The deploy account may run exactly these two commands as root, nothing else.
cat > /etc/sudoers.d/witu-deploy <<'EOF'
witu-deploy ALL=(root) NOPASSWD: /usr/local/sbin/witu-deploy-cms, /usr/local/sbin/witu-rollback-cms
EOF
chmod 440 /etc/sudoers.d/witu-deploy
visudo -cf /etc/sudoers.d/witu-deploy

# Laravel scheduler (alert e-mails) every minute as the app user; nightly backup at 02:30 server time.
cat > /etc/cron.d/witu-ai <<'EOF'
SHELL=/bin/sh
PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin
* * * * *  witu-cms  cd /var/www/witu-ai && /usr/bin/php8.4 artisan schedule:run >> /dev/null 2>&1
30 2 * * * root      /usr/local/sbin/witu-backup >> /var/log/witu-ai/backup.log 2>&1
EOF
chmod 644 /etc/cron.d/witu-ai

cat > /etc/logrotate.d/witu-ai <<'EOF'
/var/log/witu-ai/*.log {
    weekly
    rotate 8
    compress
    delaycompress
    missingok
    notifempty
    copytruncate
}
EOF
# Laravel's own logs use LOG_STACK=daily (one file per day, 14 kept by Laravel itself),
# so they are deliberately not rotated here.
logrotate -d /etc/logrotate.d/witu-ai >/dev/null 2>&1 && echo "logrotate config OK"

echo "Installed: witu-deploy-cms, witu-rollback-cms, witu-backup (in /usr/local/sbin), cron, logrotate, sudoers."
