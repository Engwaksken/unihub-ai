#!/usr/bin/env bash
# Remove Koha (packages, instances, Apache sites, databases, users, files) from this server.
#
#   sudo bash infra/witu/remove-koha.sh            # DRY RUN (default): lists everything, changes nothing
#   sudo bash infra/witu/remove-koha.sh --apply    # back up, then remove
#   sudo bash infra/witu/remove-koha.sh --apply --autoremove   # also let apt remove now-unused dependencies
#
# Safety:
# - Nothing is removed without --apply. Review the dry-run list first.
# - Before removing: tar of /etc/apache2, /etc/koha and Koha data/log dirs, and a dump of every
#   koha_* database, in /var/backups/witu-ai/koha-removal-<timestamp>/.
# - An Apache site is only disabled when EVERY DocumentRoot/Alias in it points into Koha
#   (/usr/share/koha, /var/lib/koha ...). A site that also serves something else (for example
#   witulibrary.org) is reported for manual review and left untouched.
# - Apache config is tested after the change and restored from the backup if the test fails.
# - The WITU AI CMS, gateway, FastAPI, Ollama, MariaDB itself and PHP are not touched.
set -euo pipefail
[ "$(id -u)" -eq 0 ] || { echo "Run with sudo."; exit 1; }

APPLY=0; AUTOREMOVE=0
for a in "$@"; do
  case "$a" in
    --apply) APPLY=1 ;;
    --autoremove) AUTOREMOVE=1 ;;
    *) echo "unknown option $a"; exit 1 ;;
  esac
done
TS=$(date -u +%Y%m%dT%H%M%SZ)
BK=/var/backups/witu-ai/koha-removal-$TS
KOHA_DIRS=(/etc/koha /var/lib/koha /var/log/koha /var/cache/koha /var/spool/koha /var/run/koha /usr/share/koha)
say() { printf '%s\n' "$*"; }
section() { printf '\n===== %s =====\n' "$1"; }
koha_path() { case "$1" in *koha*) return 0 ;; *) return 1 ;; esac; }

section "Packages"
mapfile -t PKGS < <(dpkg-query -W -f='${Package} ${Status}\n' 2>/dev/null | awk '$4=="installed" && $1 ~ /koha/ {print $1}')
if [ ${#PKGS[@]} -eq 0 ]; then say "no koha packages installed"; else printf '  %s\n' "${PKGS[@]}"; fi

section "Koha instances"
INSTANCES=()
if command -v koha-list >/dev/null 2>&1; then mapfile -t INSTANCES < <(koha-list 2>/dev/null || true); fi
if [ ${#INSTANCES[@]} -eq 0 ] && [ -d /etc/koha/sites ]; then mapfile -t INSTANCES < <(ls /etc/koha/sites 2>/dev/null); fi
if [ ${#INSTANCES[@]} -eq 0 ]; then say "none"; else printf '  instance: %s\n' "${INSTANCES[@]}"; fi

section "Apache sites"
KOHA_SITES=(); REVIEW_SITES=()
for f in /etc/apache2/sites-enabled/*.conf /etc/apache2/sites-available/*.conf; do
  [ -f "$f" ] || continue
  roots=$(grep -hiE '^\s*(DocumentRoot|Alias|ScriptAlias)\s' "$f" | awk '{print $NF}' | tr -d '"' || true)
  grep -qi koha "$f" || continue
  all_koha=1
  if [ -z "$roots" ]; then all_koha=1; else
    while read -r r; do [ -z "$r" ] && continue; koha_path "$r" || all_koha=0; done <<< "$roots"
  fi
  name=$(basename "$f")
  if [ "$all_koha" -eq 1 ]; then
    [[ " ${KOHA_SITES[*]} " == *" $name "* ]] || KOHA_SITES+=("$name")
  else
    [[ " ${REVIEW_SITES[*]} " == *" $name "* ]] || REVIEW_SITES+=("$name")
  fi
done
for s in "${KOHA_SITES[@]}"; do say "  Koha-only site (will be disabled and removed): $s"; done
for s in "${REVIEW_SITES[@]}"; do say "  MIXED site, mentions Koha but also serves other content: $s  -> left untouched, review by hand"; done
[ ${#KOHA_SITES[@]} -eq 0 ] && [ ${#REVIEW_SITES[@]} -eq 0 ] && say "no Apache site mentions Koha"
KOHA_CONFS=$(ls /etc/apache2/conf-enabled/ 2>/dev/null | grep -i koha || true)
[ -n "$KOHA_CONFS" ] && say "  Koha Apache confs: $KOHA_CONFS"

section "Databases and accounts"
DBS=$(mariadb -N -e "SHOW DATABASES LIKE 'koha\\_%'" 2>/dev/null || true)
DBUSERS=$(mariadb -N -e "SELECT CONCAT(\"'\",user,\"'@'\",host,\"'\") FROM mysql.user WHERE user LIKE 'koha\\_%'" 2>/dev/null || true)
[ -n "$DBS" ] && printf '  database: %s\n' $DBS || say "  no koha_* databases"
[ -n "$DBUSERS" ] && printf '  db account: %s\n' $DBUSERS
SYSUSERS=$(getent passwd | awk -F: '$1 ~ /-koha$/ {print $1}' || true)
[ -n "$SYSUSERS" ] && printf '  system user: %s\n' $SYSUSERS || say "  no *-koha system users"

section "Files, apt source, cron"
for d in "${KOHA_DIRS[@]}"; do [ -e "$d" ] && say "  $d ($(du -sh "$d" 2>/dev/null | cut -f1))"; done
APT=$(ls /etc/apt/sources.list.d/ 2>/dev/null | grep -i koha | sed 's#^#/etc/apt/sources.list.d/#' || true)
KEYS=$(ls /usr/share/keyrings/ /etc/apt/trusted.gpg.d/ 2>/dev/null | grep -i koha || true)
CRON=$(ls /etc/cron.d/ /etc/cron.daily/ /etc/cron.hourly/ /etc/cron.weekly/ /etc/cron.monthly/ /etc/logrotate.d/ 2>/dev/null | grep -i koha || true)
[ -n "$APT" ] && say "  apt source: $APT"
[ -n "$KEYS" ] && say "  apt keyring: $KEYS"
[ -n "$CRON" ] && say "  cron/logrotate: $CRON"

if [ "$APPLY" -ne 1 ]; then
  section "Dry run only"
  say "Nothing was changed. If this list is what you want removed, run again with --apply."
  [ ${#REVIEW_SITES[@]} -gt 0 ] && say "Mixed sites above will NOT be changed; paste them for review first."
  exit 0
fi

section "Backup to $BK"
mkdir -p "$BK" && chmod 700 "$BK"
tar -C / -czf "$BK/etc-apache2.tar.gz" etc/apache2
existing=(); for d in /etc/koha /var/lib/koha /var/log/koha; do [ -e "$d" ] && existing+=("${d#/}"); done
[ ${#existing[@]} -gt 0 ] && tar -C / -czf "$BK/koha-files.tar.gz" "${existing[@]}"
for db in $DBS; do mariadb-dump --single-transaction "$db" | gzip -9 > "$BK/$db.sql.gz"; done
dpkg-query -W -f='${Package} ${Version}\n' > "$BK/packages-before.txt"
ls -l "$BK"

restore_apache() { say "Apache config test failed: restoring"; rm -rf /etc/apache2.koha-failed; mv /etc/apache2 /etc/apache2.koha-failed; tar -C / -xzf "$BK/etc-apache2.tar.gz"; apache2ctl configtest && systemctl reload apache2; }

section "Removing"
for i in "${INSTANCES[@]}"; do
  if command -v koha-remove >/dev/null 2>&1; then koha-remove "$i" || say "koha-remove $i reported an error (continuing)"; fi
done
for s in "${KOHA_SITES[@]}"; do
  a2dissite -q "${s%.conf}" 2>/dev/null || true
  rm -f "/etc/apache2/sites-available/$s"
done
for c in $KOHA_CONFS; do a2disconf -q "${c%.conf}" || true; done
# Listen 8080 was added for the Koha staff site; drop it only if no enabled site still uses :8080.
if ! grep -qsE '<VirtualHost[^>]*:8080' /etc/apache2/sites-enabled/*; then
  sed -i -E '/^\s*Listen\s+8080\s*$/d' /etc/apache2/ports.conf
fi
if apache2ctl configtest; then systemctl reload apache2; else restore_apache; exit 1; fi

for u in $DBUSERS; do mariadb -e "DROP USER IF EXISTS $u"; done
for db in $DBS; do mariadb -e "DROP DATABASE IF EXISTS \`$db\`"; done
if [ ${#PKGS[@]} -gt 0 ]; then DEBIAN_FRONTEND=noninteractive apt-get purge -y -q "${PKGS[@]}"; fi
[ "$AUTOREMOVE" -eq 1 ] && DEBIAN_FRONTEND=noninteractive apt-get autoremove --purge -y -q
for u in $SYSUSERS; do userdel "$u" 2>/dev/null || true; getent group "$u" >/dev/null && groupdel "$u" 2>/dev/null || true; done
for d in "${KOHA_DIRS[@]}"; do rm -rf "$d"; done
for f in $APT; do rm -f "$f"; done
for k in $KEYS; do rm -f "/usr/share/keyrings/$k" "/etc/apt/trusted.gpg.d/$k"; done
for c in $CRON; do rm -f /etc/cron.d/"$c" /etc/cron.daily/"$c" /etc/cron.hourly/"$c" /etc/cron.weekly/"$c" /etc/cron.monthly/"$c" /etc/logrotate.d/"$c"; done
apt-get update -q >/dev/null 2>&1 || true

section "Done"
say "Koha removed. Backup: $BK"
say "Check: https://witulibrary.org/ and https://ai.witulibrary.org/ still open; ss -ltn | grep 8080 should print nothing."
