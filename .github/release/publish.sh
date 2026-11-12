#!/usr/bin/env bash
# Kept identical in ecomweb-for-woocommerce and ecomscrape-extension; change both.
#
# Usage: publish.sh <product> <version> <zip-path> <notes-from> <notes-format>
# Env:   R2_DOWNLOADS_ENDPOINT, R2_DOWNLOADS_ACCESS_KEY_ID,
#        R2_DOWNLOADS_SECRET_ACCESS_KEY, R2_DOWNLOADS_BUCKET
#        RELEASE_NOTES_FILE (optional): path to write the version's release notes to
# Prints "published <url>" or "resumed <url>". Exits 1 if the version is already
# listed or on any unexpected state.
set -euo pipefail

if [ "$#" -ne 5 ]; then
  echo "usage: publish.sh <product> <version> <zip-path> <notes-from> <notes-format>" >&2
  exit 2
fi
product=$1 version=$2 zip=$3 notes_from=$4 notes_format=$5

if ! [[ $version =~ ^[0-9]+\.[0-9]+\.[0-9]+$ ]]; then
  echo "invalid version: $version" >&2
  exit 1
fi

export AWS_ACCESS_KEY_ID=$R2_DOWNLOADS_ACCESS_KEY_ID
export AWS_SECRET_ACCESS_KEY=$R2_DOWNLOADS_SECRET_ACCESS_KEY
export AWS_DEFAULT_REGION=auto
bucket=$R2_DOWNLOADS_BUCKET
base_url=https://downloads.ecomweb.app
cli=$(dirname "$0")/downloads.mjs

s3() { aws --endpoint-url "$R2_DOWNLOADS_ENDPOINT" "$@"; }

work=$(mktemp -d)
trap 'rm -rf "$work"' EXIT
versions=$work/versions.json
err=$work/err

file=$(basename "$zip")
zip_key=$product/$version/$file
json_key=$product/versions.json
cache_zip='public, max-age=31536000, immutable'
cache_json='public, max-age=60'

# Succeeds if the key exists, returns 1 on a 404, fails the run on anything else.
exists() {
  if s3 s3api head-object --bucket "$bucket" --key "$1" >/dev/null 2>"$err"; then
    return 0
  fi
  if grep -qE '\(404\)|Not Found|NoSuchKey' "$err"; then
    return 1
  fi
  cat "$err" >&2
  echo "head-object $1 failed" >&2
  exit 1
}

# 1. versions.json (missing = no file yet)
if exists "$json_key"; then
  s3 s3 cp "s3://$bucket/$json_key" "$versions" --only-show-errors
fi

# 2. zip already in the bucket?
if exists "$zip_key"; then zip_exists=true; else zip_exists=false; fi

# 3. what to do
action=$(node "$cli" action --product "$product" --versions "$versions" \
  --version "$version" --zip-exists "$zip_exists")
case "$action" in
  published)
    echo "$version is already on downloads.ecomweb.app; release a new version" >&2
    exit 1
    ;;
  publish)
    cond=()
    if aws s3api put-object --generate-cli-skeleton input 2>/dev/null | grep -q IfNoneMatch; then
      cond=(--if-none-match '*')
    elif exists "$zip_key"; then
      # Old aws CLI without conditional puts: best-effort re-check instead.
      echo "$zip_key appeared while publishing; another run got there first" >&2
      exit 1
    fi
    if ! s3 s3api put-object --bucket "$bucket" --key "$zip_key" --body "$zip" \
      ${cond[@]+"${cond[@]}"} \
      --content-type application/zip --cache-control "$cache_zip" >/dev/null 2>"$err"; then
      cat "$err" >&2
      if grep -qE 'PreconditionFailed|ConditionalRequestConflict|\(412\)|\(409\)' "$err"; then
        echo "$zip_key already exists; another run got there first" >&2
      fi
      exit 1
    fi
    result=published
    ;;
  resume)
    s3 s3 cp "s3://$bucket/$zip_key" "$zip" --only-show-errors
    result=resumed
    ;;
  *)
    echo "unexpected action from downloads.mjs: '$action'" >&2
    exit 1
    ;;
esac

# 5. metadata from the zip as it is in the bucket
if size=$(stat -c%s "$zip" 2>/dev/null); then :; else size=$(stat -f%z "$zip"); fi
sha256=$(sha256sum "$zip" | cut -d' ' -f1)
date=$(date -u +%F)

node "$cli" add --product "$product" --versions "$versions" --version "$version" \
  --file "$file" --size "$size" --sha256 "$sha256" --date "$date" \
  --notes-from "$notes_from" --notes-format "$notes_format" >"$work/new.json"

s3 s3api put-object --bucket "$bucket" --key "$json_key" --body "$work/new.json" \
  --content-type application/json --cache-control "$cache_json" >/dev/null

if [ -n "${RELEASE_NOTES_FILE:-}" ]; then
  node -e 'const f=JSON.parse(require("fs").readFileSync(process.argv[1],"utf8"));
    process.stdout.write(f.versions.find(v=>v.version===process.argv[2]).notes+"\n")' \
    "$work/new.json" "$version" >"$RELEASE_NOTES_FILE"
fi

echo "$result $base_url/$zip_key"
