#!/usr/bin/env bash
# Compares this repo's WooProduct fixtures with a copy (the API's __fixtures__ folder).
# Exits non-zero on any difference. Works from any current directory.
set -euo pipefail

if [ "$#" -ne 1 ] || [ ! -d "$1" ]; then
	echo "usage: bash bin/check-fixtures.sh <path-to-api-fixtures-dir>" >&2
	echo "Diffs tests/fixtures/ of this repo against the given folder (diff -r)." >&2
	exit 2
fi

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
diff -r "$ROOT/tests/fixtures" "$1"
