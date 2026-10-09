#!/bin/sh
# Builds the installable plugin: dist/tudorsync-woocommerce/ and dist/tudorsync-woocommerce-<version>.zip,
# with vendor/ inside (tudorsync/core copied, not symlinked, plus phpoffice/phpspreadsheet), so the
# store needs neither Composer nor the monorepo. Run from anywhere: modules/woocommerce/bin/build.sh
set -eu

MODULE_DIR=$(cd "$(dirname "$0")/.." && pwd)
DIST_DIR="$MODULE_DIR/dist"
BUILD_DIR="$DIST_DIR/tudorsync-woocommerce"
VERSION=$(sed -n 's/^ \* Version: *//p' "$MODULE_DIR/tudorsync-woocommerce.php")

rm -rf "$BUILD_DIR" "$DIST_DIR"/tudorsync-woocommerce-*.zip
mkdir -p "$BUILD_DIR"

cp -R "$MODULE_DIR/tudorsync-woocommerce.php" "$MODULE_DIR/uninstall.php" "$MODULE_DIR/composer.json" \
    "$MODULE_DIR/README.md" "$MODULE_DIR/includes" "$MODULE_DIR/assets" "$BUILD_DIR/"
[ -d "$MODULE_DIR/languages" ] && cp -R "$MODULE_DIR/languages" "$BUILD_DIR/"

# The path repository points at ../../core relative to the module; from dist/ it must be absolute.
CORE_DIR=$(cd "$MODULE_DIR/../../core" && pwd)
sed -i.bak "s#\"url\": \"../../core\"#\"url\": \"$CORE_DIR\"#" "$BUILD_DIR/composer.json" && rm "$BUILD_DIR/composer.json.bak"

(cd "$BUILD_DIR" && COMPOSER_MIRROR_PATH_REPOS=1 composer install --no-dev --optimize-autoloader --no-interaction --quiet)

# Keep core's resources (price list, report templates) but not its tests or dev files.
rm -rf "$BUILD_DIR/vendor/tudorsync/core/tests" "$BUILD_DIR/vendor/tudorsync/core/vendor" \
    "$BUILD_DIR/vendor/tudorsync/core/.phpunit.result.cache" "$BUILD_DIR/vendor/tudorsync/core/composer.lock" \
    "$BUILD_DIR/composer.lock"
cp "$MODULE_DIR/composer.json" "$BUILD_DIR/composer.json"
# No paths of the build machine in the package.
sed -i.bak "s#$CORE_DIR#../../core#g" "$BUILD_DIR/vendor/composer/installed.json" && rm "$BUILD_DIR/vendor/composer/installed.json.bak"

(cd "$DIST_DIR" && zip -qr "tudorsync-woocommerce-$VERSION.zip" tudorsync-woocommerce)
echo "Built $DIST_DIR/tudorsync-woocommerce-$VERSION.zip"
