#!/usr/bin/env bash
# Checks bin/build-zip.sh: an untracked hidden file in the tree (.DS_Store, .wp-env.override.json,
# .claude/) or an untracked PHP file never reaches the zip, and what ships is exactly the plugin.
# Needs wp-env running (the build compiles the .mo files in it). Run with `npm run test:build`.
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
cd "$ROOT"

PLANTED=( .DS_Store includes/.DS_Store .wp-env.override.json .claude/settings.local.json includes/class-untracked-scratch.php )
for FILE in "${PLANTED[@]}"; do
	if [ -e "$FILE" ]; then
		echo "SKIP: $FILE already exists; not touching it." >&2
		exit 2
	fi
done
cleanup() {
	rm -f "${PLANTED[@]}"
	rmdir .claude 2>/dev/null || true
}
trap cleanup EXIT
mkdir -p .claude
for FILE in "${PLANTED[@]}"; do
	echo planted > "$FILE"
done
echo '{}' > .wp-env.override.json # wp-env reads it, so it must be valid JSON.

ZIP="$(bash bin/build-zip.sh | tail -1)"
LIST="$(unzip -Z1 "$ZIP")"

FAIL=0
for FILE in "${PLANTED[@]}"; do
	if printf '%s\n' "$LIST" | grep -qxF "ecomweb-for-woocommerce/$FILE"; then
		echo "FAIL: $FILE is in the zip." >&2
		FAIL=1
	fi
done
if printf '%s\n' "$LIST" | grep -E '(^|/)\.[^/]' >/dev/null; then
	echo "FAIL: hidden files in the zip:" >&2
	printf '%s\n' "$LIST" | grep -E '(^|/)\.[^/]' >&2
	FAIL=1
fi
for FILE in ecomweb-for-woocommerce.php uninstall.php readme.txt LICENSE includes/class-plugin.php includes/views/page-connected.php assets/js/admin.js assets/css/admin.css languages/ecomweb-for-woocommerce-vi.mo languages/ecomweb-for-woocommerce-vi.po languages/ecomweb-for-woocommerce.pot; do
	if ! printf '%s\n' "$LIST" | grep -qxF "ecomweb-for-woocommerce/$FILE"; then
		echo "FAIL: $FILE is missing from the zip." >&2
		FAIL=1
	fi
done
if printf '%s\n' "$LIST" | grep -E '^ecomweb-for-woocommerce/(tests|bin|vendor|node_modules|build)/' >/dev/null; then
	echo "FAIL: dev folders in the zip." >&2
	FAIL=1
fi

if [ "$FAIL" = 0 ]; then
	echo "ok: $ZIP ($(printf '%s\n' "$LIST" | grep -vc '/$') files, nothing planted got in)"
fi
exit "$FAIL"
