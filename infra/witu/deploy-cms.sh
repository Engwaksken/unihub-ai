#!/usr/bin/env bash
# Deploy the WITU AI Laravel CMS from /var/www/witu-ai-source (git) into /var/www/witu-ai.
#
#   sudo witu-deploy-cms <git-ref> [--assets <unihub-cms-<sha>.zip>] [--migrate]
#
#   <git-ref>     tag, branch or commit that passed CI (e.g. origin/main, v1.0.0, 3f2c1ab)
#   --assets      the CI "cms-bundle" artifact zip for THE SAME commit (its compiled public/build
#                 is used; Node is never run on the VPS). Optional: without it the bundle is
#                 downloaded from that commit's successful CI run on GitHub (needs the token in
#                 /etc/witu-ai/github-token). With it, e.g. an SFTP-uploaded zip, nothing is downloaded.
#   --migrate     approve pending migrations. Without it the script prints the SQL that would run
#                 (migrate --pretend) and stops before touching the live site.
#
# Order: fetch -> stage (rsync + composer --no-dev + assets) -> migration review -> backup
#        (DB + code) -> maintenance mode -> sync -> permissions -> migrate -> caches -> FPM reload
#        -> up -> health check. Any failure after the sync restores the previous code automatically.
#        The live .env and storage/ are never overwritten. The DB is restored only on request
#        (witu-rollback-cms --with-db), because a schema rollback can lose new data.
set -euo pipefail
HERE="$(dirname "$(readlink -f "$0")")"   # resolves /usr/local/sbin symlinks
# shellcheck source=infra/witu/lib.sh
. "$HERE/lib.sh"
need_root

REF="${1:?usage: witu-deploy-cms <git-ref> [--assets <bundle.zip>] [--migrate]}"; shift
ASSETS=""; MIGRATE=0
while [ $# -gt 0 ]; do
  case "$1" in
    --assets) ASSETS="${2:?}"; shift 2 ;;
    --migrate) MIGRATE=1; shift ;;
    *) die "unknown option $1" ;;
  esac
done
[ -z "$ASSETS" ] || [ -f "$ASSETS" ] || die "--assets $ASSETS: file not found"

exec 9>/run/witu-deploy.lock
flock -n 9 || die "another deploy/rollback is running"

TS=$(date -u +%Y%m%dT%H%M%SZ)
TMP=$(mktemp -d); trap 'rm -rf "$TMP"' EXIT
chmod 755 "$TMP"   # the deploy user reads the unpacked assets from here
as_deploy() { sudo -u "$DEPLOY_USER" -H "$@"; }

log "Fetching $REF in $SRC"
[ -z "$(as_deploy git -C "$SRC" status --porcelain --untracked-files=no)" ] || die "$SRC has local modifications; commit or discard them (never edit code on the server)"
as_deploy git -C "$SRC" fetch --quiet --tags --prune origin
SHA=$(as_deploy git -C "$SRC" rev-parse --verify --short "$REF^{commit}") || die "unknown ref $REF"
as_deploy git -C "$SRC" checkout --quiet --detach "$SHA"
echo "commit $SHA: $(as_deploy git -C "$SRC" log -1 --format=%s)"

if [ -z "$ASSETS" ]; then
  log "Downloading the CI bundle for $SHA from GitHub"
  mkdir -p "$TMP/bundle"
  ASSETS=$(fetch_bundle "$(as_deploy git -C "$SRC" rev-parse "$SHA^{commit}")" "$TMP/bundle")
  [ -n "$ASSETS" ] && [ -f "$ASSETS" ] || die "the downloaded artifact contained no unihub-cms-*.zip"
  echo "bundle: $(basename "$ASSETS")"
fi

log "Verifying assets bundle"
if [ -f "$ASSETS.sha256" ]; then (cd "$(dirname "$ASSETS")" && sha256sum -c --quiet "$(basename "$ASSETS").sha256") || die "bundle checksum mismatch"; fi
BUNDLE_SHA=$(basename "$ASSETS" .zip | sed -n 's/^unihub-cms-\([0-9a-f]\{7,\}\)$/\1/p')
[ -n "$BUNDLE_SHA" ] || die "bundle file must be named unihub-cms-<sha>.zip (as produced by CI)"
case "$SHA" in
  "$BUNDLE_SHA"*) ;;
  *) case "$BUNDLE_SHA" in "$SHA"*) ;; *) die "bundle is for commit $BUNDLE_SHA but deploying $SHA — use the cms-bundle artifact of this exact commit" ;; esac ;;
esac
unzip -q "$ASSETS" 'cms/public/build/*' -d "$TMP"
[ -f "$TMP/cms/public/build/manifest.json" ] || die "bundle has no cms/public/build/manifest.json"

log "Staging in $STAGE"
mkdir -p "$STAGE" && chown "$DEPLOY_USER:$DEPLOY_USER" "$STAGE"
as_deploy rsync -a --delete --exclude=/vendor/ --exclude=/node_modules/ --exclude=/public/build/ \
  --exclude=/.env --exclude=/public/hot "$SRC/cms/" "$STAGE/"
as_deploy rsync -a --delete "$TMP/cms/public/build/" "$STAGE/public/build/"
(cd "$STAGE" && as_deploy "$PHP" "$COMPOSER" check-platform-reqs --no-dev --lock) || die "PHP $($PHP -r 'echo PHP_VERSION;') does not satisfy composer.lock"
(cd "$STAGE" && as_deploy env COMPOSER_NO_INTERACTION=1 "$PHP" "$COMPOSER" install --no-dev --prefer-dist \
  --optimize-autoloader --no-progress --quiet)
as_deploy "$PHP" "$COMPOSER" audit --no-dev --locked --working-dir="$STAGE" || die "composer audit reported vulnerable packages"

[ -f "$APP/.env" ] || die "$APP/.env does not exist — create it first (runbook step 5)"
grep -qE '^APP_KEY=base64:.+' "$APP/.env" || die "APP_KEY is empty in $APP/.env (runbook step 5 generates it)"

log "Reviewing database migrations"
cp -p "$APP/.env" "$STAGE/.env"; chown "$DEPLOY_USER:$APP_USER" "$STAGE/.env"
PRETEND=$(cd "$STAGE" && as_deploy "$PHP" artisan migrate --pretend --force 2>&1) || { rm -f "$STAGE/.env"; echo "$PRETEND"; die "migration review failed (DB credentials in .env?)"; }
rm -f "$STAGE/.env"
if printf '%s' "$PRETEND" | grep -q "Nothing to migrate"; then
  echo "no pending migrations"
elif [ "$MIGRATE" -ne 1 ]; then
  printf '%s\n' "$PRETEND" > "$BK/pending-migrations-$SHA.sql.txt"
  printf '%s\n' "$PRETEND" | head -60
  echo
  echo "Pending migrations found (full SQL: $BK/pending-migrations-$SHA.sql.txt)."
  echo "Review them, then re-run with --migrate. The live site has NOT been changed."
  exit 4
else
  echo "pending migrations approved (--migrate)"
fi

log "Backing up current release"
DEST="$BK/cms-$TS"
mkdir -p "$DEST" && chmod 700 "$BK" "$DEST"
PREV_SHA=$(cat "$APP/.deployed-sha" 2>/dev/null || echo unknown)
echo "$PREV_SHA" > "$DEST/previous-sha"
tar -C "$APP" -czf "$DEST/code.tar.gz" --exclude=./storage --exclude=./.env .
tar -C "$APP" -czf "$DEST/storage-app.tar.gz" storage/app 2>/dev/null || true
cp -p "$APP/.env" "$DEST/env.backup"
dump_db "$DEST/db.sql.gz"
echo "backup: $DEST"

restore_code() {
  echo "!! Deploy failed — restoring previous code from $DEST"
  mkdir -p "$TMP/restore" && tar -C "$TMP/restore" -xzf "$DEST/code.tar.gz"
  rsync -a --delete --exclude=/storage/ --exclude=/.env "$TMP/restore/" "$APP/"
  fix_permissions
  artisan config:cache >/dev/null 2>&1 || true
  artisan route:cache >/dev/null 2>&1 || true
  artisan view:cache >/dev/null 2>&1 || true
  systemctl reload php8.4-fpm
  artisan up || true
  echo "Previous code restored. If migrations ran, restore the DB with:  sudo witu-rollback-cms $DEST --with-db"
}

log "Maintenance mode + sync"
[ -f "$APP/vendor/autoload.php" ] && { artisan down --retry=30 --refresh=15 || true; }
trap 'restore_code; rm -rf "$TMP"' ERR
rsync -a --delete --exclude=/storage/ --exclude=/.env --exclude=/.deployed-sha "$STAGE/" "$APP/"
mkdir -p "$APP"/storage/{app/public,framework/cache/data,framework/sessions,framework/views,logs} "$APP/bootstrap/cache"
fix_permissions

[ "$MIGRATE" -eq 1 ] && { log "Migrating"; artisan migrate --force; }

log "Rebuilding caches"
artisan optimize:clear >/dev/null
artisan config:cache
artisan route:cache
artisan view:cache
artisan event:cache
systemctl reload php8.4-fpm
artisan up

log "Health check"
health_check
trap - ERR

echo "$SHA" > "$APP/.deployed-sha"; chown "$DEPLOY_USER:$APP_USER" "$APP/.deployed-sha"
echo "$TS $SHA (previous $PREV_SHA) backup=$DEST" >> "$BK/deploys.log"
# keep the 10 newest deploy backups
find "$BK" -maxdepth 1 -type d -name 'cms-*' | sort | head -n -10 | xargs -r rm -rf
log "Deployed $SHA. Rollback: sudo witu-rollback-cms $DEST [--with-db]"
