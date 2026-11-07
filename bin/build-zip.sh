#!/usr/bin/env bash
# Builds build/ecomweb-for-woocommerce-<version>.zip, with the top folder ecomweb-for-woocommerce/.
#
# Fails unless the plugin header Version, the readme Stable tag and ECOMWEB_WOO_VERSION agree.
# Files listed in .distignore are left out. Compiles the .mo files with WP-CLI inside wp-env.
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"

SLUG="ecomweb-for-woocommerce"
MAIN="$SLUG.php"

HEADER_VERSION="$(sed -n 's/^[[:space:]]*\*[[:space:]]*Version:[[:space:]]*//p' "$MAIN" | head -1 | tr -d '[:space:]')"
STABLE_TAG="$(sed -n 's/^Stable tag:[[:space:]]*//p' readme.txt | head -1 | tr -d '[:space:]')"
CONSTANT="$(sed -n "s/.*define( 'ECOMWEB_WOO_VERSION', '\([^']*\)' ).*/\1/p" "$MAIN" | head -1)"

if [ -z "$HEADER_VERSION" ] || [ "$HEADER_VERSION" != "$STABLE_TAG" ] || [ "$HEADER_VERSION" != "$CONSTANT" ]; then
	echo "Version mismatch: plugin header '$HEADER_VERSION', readme Stable tag '$STABLE_TAG', ECOMWEB_WOO_VERSION '$CONSTANT'." >&2
	exit 1
fi
VERSION="$HEADER_VERSION"

# The .mo files are build output: compile them from the .po files inside wp-env.
npx wp-env run cli --env-cwd="wp-content/plugins/$SLUG" wp i18n make-mo languages

DEST="build/$SLUG"
rm -rf build
mkdir -p "$DEST"
rsync -a --exclude-from=.distignore ./ "$DEST/"

ZIP="build/$SLUG-$VERSION.zip"
(cd build && zip -qr "$SLUG-$VERSION.zip" "$SLUG")
echo "$ROOT/$ZIP" | sed "s#^$ROOT/##"
