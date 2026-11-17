#!/usr/bin/env bash
# Kept identical in ecomweb-for-woocommerce and ecomscrape-extension; change both.
#
# Usage: edit-versions.sh <product> set-store|yank|sync-store <version> [reason]
# Env:   R2_DOWNLOADS_ENDPOINT, R2_DOWNLOADS_ACCESS_KEY_ID,
#        R2_DOWNLOADS_SECRET_ACCESS_KEY, R2_DOWNLOADS_BUCKET
#
# Edits <product>/versions.json on downloads.ecomweb.app.
# - set-store: the store's version is <version> (must be listed).
# - yank: marks <version> yanked with <reason>.
# - sync-store: <version> is what the store serves now; sets it only when
#   `downloads.mjs store-action` says "set" (listed, not yanked, not already set).
# Prints one line saying what changed.
set -euo pipefail

if [ "$#" -lt 3 ] || [ "$#" -gt 4 ]; then
  echo "usage: edit-versions.sh <product> set-store|yank|sync-store <version> [reason]" >&2
  exit 2
fi
product=$1 action=$2 version=$3 reason=${4:-}

if ! [[ $version =~ ^[0-9]+\.[0-9]+\.[0-9]+$ ]]; then
  echo "invalid version: $version" >&2
  exit 1
fi

cli=$(dirname "$0")/downloads.mjs

export AWS_ACCESS_KEY_ID=$R2_DOWNLOADS_ACCESS_KEY_ID
export AWS_SECRET_ACCESS_KEY=$R2_DOWNLOADS_SECRET_ACCESS_KEY
export AWS_DEFAULT_REGION=auto
# aws CLI >= 2.23 sends CRC checksums by default, which has broken R2 before.
export AWS_REQUEST_CHECKSUM_CALCULATION=WHEN_REQUIRED
export AWS_RESPONSE_CHECKSUM_VALIDATION=WHEN_REQUIRED
s3() { aws --endpoint-url "$R2_DOWNLOADS_ENDPOINT" "$@"; }

work=$(mktemp -d)
trap 'rm -rf "$work"' EXIT
key=$product/versions.json
s3 s3 cp "s3://$R2_DOWNLOADS_BUCKET/$key" "$work/versions.json" --only-show-errors

case "$action" in
  set-store)
    node "$cli" set-store --product "$product" --versions "$work/versions.json" --version "$version" > "$work/new.json"
    ;;
  yank)
    node "$cli" yank --product "$product" --versions "$work/versions.json" --version "$version" \
      --reason "$reason" --date "$(date -u +%F)" > "$work/new.json"
    ;;
  sync-store)
    decision=$(node "$cli" store-action --product "$product" --versions "$work/versions.json" --version "$version")
    if [ "$decision" != set ]; then
      echo "store serves $version: $decision, versions.json unchanged"
      exit 0
    fi
    node "$cli" set-store --product "$product" --versions "$work/versions.json" --version "$version" > "$work/new.json"
    ;;
  *)
    echo "unknown action: $action" >&2
    exit 2
    ;;
esac

s3 s3api put-object --bucket "$R2_DOWNLOADS_BUCKET" --key "$key" --body "$work/new.json" \
  --content-type application/json --cache-control 'public, max-age=60' > /dev/null
echo "$action $version: versions.json updated"
