#!/usr/bin/env bash
# Install PHP 8.4 (FPM + CLI) BESIDE the existing PHP 8.2, plus Composer, the service
# accounts, the WITU PHP-FPM pool and OPcache settings. Idempotent; safe to re-run.
#
#   sudo bash infra/witu/install-php.sh
#
# Why 8.4: cms/composer.json needs php ^8.3 (Laravel 13); the repo's gateway needs >= 8.4.
# One php8.4-fpm serves both. PHP 8.2 packages are NOT removed and the default `php` CLI
# is left pointing where it pointed before. Apache is NOT touched here (runbook step 6).
set -euo pipefail
[ "$(id -u)" -eq 0 ] || { echo "Run with sudo."; exit 1; }
HERE="$(cd "$(dirname "$0")" && pwd)"
PHPV=8.4

echo "==> Free memory/disk check"
avail_mb=$(awk '/MemAvailable/{print int($2/1024)}' /proc/meminfo)
free_gb=$(df -BG --output=avail / | tail -1 | tr -dc 0-9)
echo "MemAvailable=${avail_mb}MB, free disk on /=${free_gb}GB"
[ "$free_gb" -ge 3 ] || { echo "Less than 3 GB free on / — stop and free space first."; exit 1; }

PREV_PHP=$(readlink -f /etc/alternatives/php 2>/dev/null || true)

echo "==> PHP package repository (packages.sury.org, Debian's de-facto PHP backports)"
if ! grep -rqs "packages.sury.org/php" /etc/apt/sources.list /etc/apt/sources.list.d/; then
  apt-get update -q
  apt-get install -y -q ca-certificates curl lsb-release
  curl -fsSLo /tmp/debsuryorg-archive-keyring.deb https://packages.sury.org/debsuryorg-archive-keyring.deb
  dpkg -i /tmp/debsuryorg-archive-keyring.deb && rm -f /tmp/debsuryorg-archive-keyring.deb
  echo "deb [signed-by=/usr/share/keyrings/debsuryorg-archive-keyring.gpg] https://packages.sury.org/php/ $(lsb_release -sc) main" \
    > /etc/apt/sources.list.d/php.list
else
  echo "already configured"
fi
# The existing PHP 8.2 (mod_php for the other sites on this host) must stay on Debian's own
# packages: without this pin, sury's newer 8.2 builds would replace them on the next upgrade.
cat > /etc/apt/preferences.d/witu-php82-debian <<'PIN'
Package: php8.2* libapache2-mod-php8.2
Pin: release o=Debian
Pin-Priority: 990
PIN
apt-get update -q
echo "php8.2 candidate stays on Debian: $(apt-cache policy libapache2-mod-php8.2 2>/dev/null | awk '/Candidate/{print $2}')"

echo "==> PHP $PHPV packages"
apt-get install -y -q --no-install-recommends \
  php$PHPV-fpm php$PHPV-cli php$PHPV-common php$PHPV-mysql php$PHPV-sqlite3 php$PHPV-mbstring \
  php$PHPV-intl php$PHPV-curl php$PHPV-zip php$PHPV-xml php$PHPV-bcmath php$PHPV-opcache \
  unzip rsync git acl sqlite3

# Keep the system's default `php` CLI where it was (8.2 users, if any, are unaffected).
if [ -n "$PREV_PHP" ] && [ -x "$PREV_PHP" ]; then update-alternatives --set php "$PREV_PHP" >/dev/null; fi
echo "default php CLI: $(php -r 'echo PHP_VERSION;')   witu uses: /usr/bin/php$PHPV ($(/usr/bin/php$PHPV -r 'echo PHP_VERSION;'))"

for ext in sodium pdo_mysql mbstring openssl fileinfo intl curl zip tokenizer ctype dom xml bcmath; do
  /usr/bin/php$PHPV -m | grep -qix "$ext" || { echo "MISSING extension after install: $ext"; exit 1; }
done
echo "all required extensions present"

echo "==> Composer (installer signature verified)"
# Our own copy at /usr/local/bin, run with php8.4 by the deploy. An older system Composer
# (Debian's /usr/bin/composer, 2.5.x on this host) is left in place for whoever uses it.
if [ ! -x /usr/local/bin/composer ]; then
  EXPECTED=$(curl -fsSL https://composer.github.io/installer.sig)
  curl -fsSLo /tmp/composer-setup.php https://getcomposer.org/installer
  ACTUAL=$(/usr/bin/php$PHPV -r "echo hash_file('sha384', '/tmp/composer-setup.php');")
  [ "$EXPECTED" = "$ACTUAL" ] || { rm -f /tmp/composer-setup.php; echo "Composer installer checksum MISMATCH — aborting."; exit 1; }
  /usr/bin/php$PHPV /tmp/composer-setup.php --quiet --install-dir=/usr/local/bin --filename=composer
  rm -f /tmp/composer-setup.php
fi
/usr/bin/php$PHPV /usr/local/bin/composer --version

echo "==> Service accounts"
id witu-cms >/dev/null 2>&1 || useradd --system --home-dir /nonexistent --shell /usr/sbin/nologin witu-cms
id witu-deploy >/dev/null 2>&1 || useradd --create-home --shell /bin/bash witu-deploy
usermod -aG witu-cms witu-deploy   # deploy user can read the app's group-owned files

echo "==> PHP-FPM pool + OPcache"
install -m 644 "$HERE/php/witu-cms.pool.conf" /etc/php/$PHPV/fpm/pool.d/witu-cms.conf
install -m 644 "$HERE/php/99-witu.ini" /etc/php/$PHPV/fpm/conf.d/99-witu.ini
install -m 644 "$HERE/php/99-witu-cli.ini" /etc/php/$PHPV/cli/conf.d/99-witu.ini
# The stock "www" pool would keep 2+ idle workers as www-data for nothing; park it.
[ -f /etc/php/$PHPV/fpm/pool.d/www.conf ] && mv /etc/php/$PHPV/fpm/pool.d/www.conf /etc/php/$PHPV/fpm/pool.d/www.conf.disabled
mkdir -p /var/log/witu-ai && chown witu-cms:adm /var/log/witu-ai && chmod 750 /var/log/witu-ai
php-fpm$PHPV -t
systemctl enable --now php$PHPV-fpm
systemctl reload php$PHPV-fpm
ls -l /run/php/witu-cms.sock

echo
echo "Done. Apache is unchanged; continue with runbook step 4 (MariaDB)."
