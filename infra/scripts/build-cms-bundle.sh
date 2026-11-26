#!/usr/bin/env bash
# Build a ready-to-upload CMS bundle for cPanel shared hosting (no composer/npm on the server).
# Contents: the committed /cms source at a git revision + production vendor/ (no dev packages,
# optimised autoloader) + compiled public/build assets. No .env, no tests, no node_modules.
#
# Usage:  bash infra/scripts/build-cms-bundle.sh [git-ref]        (default HEAD)
#   env:  PHP=/path/to/php8.x  COMPOSER=/path/to/composer.phar   (defaults: php / composer on PATH)
# Output: dist/unihub-cms-<shortsha>.zip  (+ .sha256)
set -euo pipefail
REF="${1:-HEAD}"
ROOT="$(git rev-parse --show-toplevel)"
SHA="$(git -C "$ROOT" rev-parse --short "$REF")"
PHP="${PHP:-php}"
COMPOSER_BIN="${COMPOSER:-$(command -v composer)}"
DIST="$ROOT/dist"
WORK="$(mktemp -d)"
trap 'rm -rf "$WORK"' EXIT

if [ -n "$(git -C "$ROOT" status --porcelain -- cms)" ] && [ "$REF" = "HEAD" ]; then
  echo "NOTE: cms/ has uncommitted changes; the bundle uses the COMMITTED revision $SHA." >&2
fi

echo "==> Exporting cms/ at $SHA"
git -C "$ROOT" archive "$REF" cms | tar -x -C "$WORK"
cd "$WORK/cms"

echo "==> composer install --no-dev (PHP: $("$PHP" -r 'echo PHP_VERSION;'))"
"$PHP" "$COMPOSER_BIN" install --no-dev --prefer-dist --optimize-autoloader --no-interaction --no-progress

echo "==> npm ci && npm run build"
npm ci --no-audit --no-fund
npm run build
rm -rf node_modules

echo "==> Pruning development-only files"
rm -rf tests phpunit.xml phpstan.neon .phpunit.cache .editorconfig .github
find storage -type f ! -name .gitignore -delete
find bootstrap/cache -type f ! -name .gitignore ! -name packages.php ! -name services.php -delete

cat > BUNDLE-INFO.txt <<EOF
Unihub AI CMS deploy bundle
commit:      $(git -C "$ROOT" rev-parse "$REF")
built_at:    $(date -u +%Y-%m-%dT%H:%M:%SZ)
built_with:  PHP $("$PHP" -r 'echo PHP_VERSION;'), node $(node -v)
php_floor:   8.3 (composer platform pinned; requires ext sodium, pdo_mysql, mbstring, openssl, fileinfo, intl)

Install / update on cPanel: see INSTALL-CPANEL.md in this bundle.
EOF
cp "$ROOT/docs/deploy/cms-cpanel.md" INSTALL-CPANEL.md

echo "==> Zipping"
mkdir -p "$DIST"
OUT="$DIST/unihub-cms-$SHA.zip"
rm -f "$OUT"
"$PHP" -r '
$src = $argv[1]; $out = $argv[2];
$z = new ZipArchive();
if ($z->open($out, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) { fwrite(STDERR, "cannot create zip\n"); exit(1); }
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($src, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);
foreach ($it as $f) {
    $rel = "cms/" . str_replace("\\", "/", substr($f->getPathname(), strlen($src) + 1));
    $f->isDir() ? $z->addEmptyDir($rel) : $z->addFile($f->getPathname(), $rel);
}
$z->close();
' "$WORK/cms" "$OUT"
( cd "$DIST" && sha256sum "$(basename "$OUT")" > "$(basename "$OUT").sha256" )
echo "Built $OUT ($(du -h "$OUT" | cut -f1))"
