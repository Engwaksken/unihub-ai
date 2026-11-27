#!/usr/bin/env bash
# Shared settings/helpers for the WITU AI ops scripts. Sourced, not executed.
# shellcheck disable=SC2034  # variables are used by the scripts that source this file
APP=/var/www/witu-ai
SRC=/var/www/witu-ai-source
STAGE=/var/www/.witu-ai-stage
BK=/var/backups/witu-ai
PHP=/usr/bin/php8.4
COMPOSER=/usr/local/bin/composer
APP_USER=witu-cms
DEPLOY_USER=witu-deploy
SITE_URL=https://ai.witulibrary.org

log() { printf '\n==> %s\n' "$*"; }
die() { echo "ERROR: $*" >&2; exit 1; }
need_root() { [ "$(id -u)" -eq 0 ] || die "run with sudo"; }

# Read one key from the live .env without sourcing it (values may contain shell metacharacters).
envval() { grep -E "^$1=" "$APP/.env" 2>/dev/null | tail -1 | cut -d= -f2- | sed -E 's/^"(.*)"$/\1/; s/^'"'"'(.*)'"'"'$/\1/'; }

artisan() { (cd "$APP" && sudo -u "$APP_USER" "$PHP" artisan "$@"); }

# Ownership model (docs/witu/maintenance.md §Permissions):
#   code      witu-deploy:witu-cms  dirs 750 / files 640  (FPM reads via group, cannot modify code)
#   storage, bootstrap/cache  witu-cms:witu-cms  2770 / 660 (the only writable paths)
#   .env      witu-deploy:witu-cms  640
#   Apache (www-data) may only traverse the app root and read public/.
fix_permissions() {
  chown -R "$DEPLOY_USER:$APP_USER" "$APP"
  find "$APP" -path "$APP/storage" -prune -o -path "$APP/bootstrap/cache" -prune -o -type d -exec chmod 750 {} +
  find "$APP" -path "$APP/storage" -prune -o -path "$APP/bootstrap/cache" -prune -o -type f -exec chmod 640 {} +
  chown -R "$APP_USER:$APP_USER" "$APP/storage" "$APP/bootstrap/cache"
  find "$APP/storage" "$APP/bootstrap/cache" -type d -exec chmod 2770 {} +
  find "$APP/storage" "$APP/bootstrap/cache" -type f -exec chmod 660 {} +
  [ -f "$APP/.env" ] && chown "$DEPLOY_USER:$APP_USER" "$APP/.env" && chmod 640 "$APP/.env"
  chmod o+x "$APP"
  find "$APP/public" -type d -exec chmod o+rx {} +
  find "$APP/public" -type f ! -name '*.php' -exec chmod o+r {} +
}

# Database dump using the app's own credentials (root-run; password via env, not argv).
dump_db() {
  local out=$1 db user pass host
  db=$(envval DB_DATABASE); user=$(envval DB_USERNAME); pass=$(envval DB_PASSWORD); host=$(envval DB_HOST)
  [ -n "$db" ] || { echo "(no DB_DATABASE in .env — skipping DB dump)"; return 0; }
  MYSQL_PWD="$pass" mariadb-dump -h "${host:-127.0.0.1}" -u "$user" --single-transaction \
    --no-tablespaces --default-character-set=utf8mb4 "$db" | gzip -9 > "$out"
  gzip -t "$out"
  echo "DB dump: $out ($(du -h "$out" | cut -f1))"
}

health_check() {
  local ok=0 code
  artisan about --only=environment >/dev/null || ok=1
  artisan migrate:status >/dev/null || { echo "database check failed"; ok=1; }
  if grep -qs "DocumentRoot $APP/public" /etc/apache2/sites-enabled/*.conf; then
    code=$(curl -s -o /dev/null -m 15 -w '%{http_code}' "$SITE_URL/up")
    echo "GET $SITE_URL/up -> $code"; [ "$code" = 200 ] || ok=1
    code=$(curl -s -o /dev/null -m 15 -w '%{http_code}' "$SITE_URL/login")
    echo "GET $SITE_URL/login -> $code"; [ "$code" = 200 ] || ok=1
  else
    echo "(Apache not yet pointed at $APP/public — HTTP check skipped)"
  fi
  return $ok
}
