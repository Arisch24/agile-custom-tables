#!/usr/bin/env bash
# Build an installable Agile Custom Tables release ZIP: runtime PHP, compiled assets
# and a production-only Composer autoloader. Run `npm run release`, which
# rebuilds the assets first.

set -euo pipefail

PLUGIN_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
SLUG="agile-custom-tables"
VERSION="$(sed -n 's/^ \* Version:[[:space:]]*//p' "$PLUGIN_DIR/$SLUG.php" | head -n 1)"
OUTPUT="${1:-$PLUGIN_DIR/dist/${SLUG}-${VERSION}.zip}"
# Composer runs inside DDEV (WSL has no PHP), so staging must sit inside the
# mounted project rather than in a system temp folder.
STAGING_ROOT="$PLUGIN_DIR/dist/.staging"
CONTAINER_STAGING="/var/www/html/wp-content/plugins/$SLUG/dist/.staging/$SLUG"

cleanup() {
	rm -rf -- "$STAGING_ROOT"
}
trap cleanup EXIT

if [ -z "$VERSION" ]; then
	echo "error: no Version header in $SLUG.php" >&2
	exit 1
fi
if [ ! -f "$PLUGIN_DIR/build/settings.asset.php" ]; then
	echo "error: build/ is missing; run \`npm run build\` first" >&2
	exit 1
fi

cleanup
mkdir -p "$STAGING_ROOT/$SLUG" "$(dirname "$OUTPUT")"
cp -R \
	"$PLUGIN_DIR/$SLUG.php" \
	"$PLUGIN_DIR/uninstall.php" \
	"$PLUGIN_DIR/readme.txt" \
	"$PLUGIN_DIR/includes" \
	"$PLUGIN_DIR/assets" \
	"$PLUGIN_DIR/build" \
	"$PLUGIN_DIR/composer.json" \
	"$PLUGIN_DIR/composer.lock" \
	"$STAGING_ROOT/$SLUG/"

ddev exec -d "$CONTAINER_STAGING" \
	"COMPOSER_ROOT_VERSION=$VERSION composer install --no-dev --optimize-autoloader --no-interaction --no-progress --quiet"
rm -- "$STAGING_ROOT/$SLUG/composer.json" "$STAGING_ROOT/$SLUG/composer.lock"

rm -f -- "$OUTPUT"
python3 -I -m zipfile -c "$OUTPUT" "$STAGING_ROOT/$SLUG"

python3 -I - "$OUTPUT" "$SLUG" <<'PY'
import sys
import zipfile

archive, slug = sys.argv[1:]
required = [
    f"{slug}/{slug}.php",
    f"{slug}/uninstall.php",
    f"{slug}/readme.txt",
    f"{slug}/includes/Plugin.php",
    f"{slug}/assets/admin.js",
    f"{slug}/build/settings.js",
    f"{slug}/build/settings.asset.php",
    f"{slug}/build/settings.css",
    f"{slug}/vendor/autoload.php",
]
allowed = (
    f"{slug}/{slug}.php",
    f"{slug}/uninstall.php",
    f"{slug}/readme.txt",
    f"{slug}/includes/",
    f"{slug}/assets/",
    f"{slug}/build/",
    f"{slug}/vendor/autoload.php",
    f"{slug}/vendor/composer/",
)

with zipfile.ZipFile(archive) as release:
    names = release.namelist()
    files = [name for name in names if not name.endswith("/")]
    missing = [name for name in required if name not in names]
    if missing:
        raise SystemExit(f"error: release ZIP is missing {', '.join(missing)}")
    extra = [name for name in files if not name.startswith(allowed)]
    if extra:
        raise SystemExit(f"error: release ZIP contains unexpected files: {', '.join(extra)}")
    classmap = release.read(f"{slug}/vendor/composer/autoload_classmap.php").decode()
    for cls in ("Plugin", "Capabilities", "Registry", "Entries", "Admin", "Settings"):
        if f"'AgileCustomTables\\\\{cls}'" not in classmap:
            raise SystemExit(f"error: autoloader does not map AgileCustomTables\\{cls}")
    bad = release.testzip()
    if bad:
        raise SystemExit(f"error: corrupt ZIP member: {bad}")

print(f"Wrote {archive} ({len(files)} files)")
PY
