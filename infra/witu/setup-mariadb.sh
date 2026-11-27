#!/usr/bin/env bash
# Create the WITU AI database and a least-privilege application user on the EXISTING MariaDB.
# Touches nothing else: no other database, no root account, no remote access.
#
#   sudo bash infra/witu/setup-mariadb.sh
#
# The generated password is written ONLY to /root/witu-ai-db.credentials (0600) and is never
# printed. The deploy step copies it into /var/www/witu-ai/.env (runbook step 5).
set -euo pipefail
[ "$(id -u)" -eq 0 ] || { echo "Run with sudo."; exit 1; }
HERE="$(cd "$(dirname "$0")" && pwd)"
DB=witu_ai
USER=witu_ai_user
CRED=/root/witu-ai-db.credentials

# root uses unix_socket auth on Debian's MariaDB: no password needed (or wanted) here.
mariadb -e "SELECT 1" >/dev/null || { echo "Cannot connect as root via unix socket."; exit 1; }

if mariadb -N -e "SELECT COUNT(*) FROM mysql.user WHERE user='$USER'" | grep -qx 0; then
  umask 077
  PASS=$(head -c 32 /dev/urandom | base64 | tr -dc 'A-Za-z0-9' | head -c 32)
  mariadb <<SQL
CREATE DATABASE IF NOT EXISTS \`$DB\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER '$USER'@'127.0.0.1' IDENTIFIED BY '$PASS';
CREATE USER '$USER'@'localhost' IDENTIFIED BY '$PASS';
GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, ALTER, INDEX, DROP, REFERENCES, CREATE TEMPORARY TABLES, LOCK TABLES
  ON \`$DB\`.* TO '$USER'@'127.0.0.1', '$USER'@'localhost';
FLUSH PRIVILEGES;
SQL
  printf 'DB_HOST=127.0.0.1\nDB_PORT=3306\nDB_DATABASE=%s\nDB_USERNAME=%s\nDB_PASSWORD=%s\n' "$DB" "$USER" "$PASS" > "$CRED"
  chmod 600 "$CRED"
  echo "Created database $DB and user $USER (password saved to $CRED, not shown)."
else
  echo "User $USER already exists — leaving it and its password unchanged."
  mariadb -e "CREATE DATABASE IF NOT EXISTS \`$DB\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
fi

echo "==> Server tuning (localhost only, small buffers for a 4 GB host shared with Ollama)"
if [ ! -f /etc/mysql/mariadb.conf.d/60-witu.cnf ]; then
  install -m 644 "$HERE/mariadb/60-witu.cnf" /etc/mysql/mariadb.conf.d/60-witu.cnf
  echo "Installed /etc/mysql/mariadb.conf.d/60-witu.cnf — takes effect on: sudo systemctl restart mariadb"
  echo "(restart when convenient; the CMS is the only app using it. Check: sudo mariadb -e 'SELECT @@bind_address')"
fi

echo "==> Verification"
# SHOW GRANTS includes the password hash; never print it.
mariadb -N -e "SHOW GRANTS FOR '$USER'@'127.0.0.1';" | sed -E "s/PASSWORD '[^']*'/PASSWORD '<hidden>'/"
mariadb -N -e "SELECT @@bind_address AS bind, @@skip_networking AS skip_net;"
ss -ltn | grep -q ':3306 ' && ss -ltn | grep ':3306 '
echo "Expect bind address 127.0.0.1 (or skip_networking=1). 0.0.0.0 or :: means 3306 is reachable from outside."
