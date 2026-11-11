#!/usr/bin/env bash
# Builds build/ecomweb-for-woocommerce-<version>.zip, with the top folder ecomweb-for-woocommerce/.
#
# Fails unless the plugin header Version, the readme Stable tag and ECOMWEB_WOO_VERSION agree.
# Only git-tracked files under the paths in
# SHIP go in (their working-tree content), so untracked or hidden files such as .DS_Store,
# .wp-env.override.json or .claude/ never reach the zip. Fails if a hidden file got in anyway.
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

# What the plugin ships. Everything else (tests, bin, vendor, dev config) stays out. languages/
# stays out too: wordpress.org serves translations from translate.wordpress.org, and the vi .po
# in the repo is only the source to import there.
SHIP=( "$MAIN" uninstall.php readme.txt LICENSE includes assets )

DEST="build/$SLUG"
rm -rf build
mkdir -p "$DEST"
git ls-files -z -- "${SHIP[@]}" | while IFS= read -r -d '' FILE; do
	# Tracked but deleted in the working tree: nothing to ship.
	[ -f "$FILE" ] || continue
	mkdir -p "$DEST/$(dirname "$FILE")"
	cp -p "$FILE" "$DEST/$FILE"
done

HIDDEN="$(cd "$DEST" && find . -mindepth 1 -name '.*' -print)"
if [ -n "$HIDDEN" ]; then
	echo "Hidden files in the build: $HIDDEN" >&2
	exit 1
fi

ZIP="build/$SLUG-$VERSION.zip"
(cd build && zip -qr "$SLUG-$VERSION.zip" "$SLUG")
echo "$ROOT/$ZIP" | sed "s#^$ROOT/##"
