# ecomweb-for-woocommerce
Copy WooCommerce products into an ecomweb store

## Releasing

Zips are published to https://downloads.ecomweb.app by `.github/workflows/release.yml`.

1. Bump the version (plugin header, constant, `readme.txt` stable tag and a `= X.Y.Z =`
   changelog entry) in a PR and merge it to `main`.
2. Tag the merge commit with the plain version and push the tag: `git tag X.Y.Z && git push origin X.Y.Z`.
   CI runs, then the zip is uploaded to R2, `woocommerce/versions.json` is updated and a
   GitHub release is created with the changelog entry as notes.
   A published version is never overwritten; to fix a release, ship a new version. If a
   run died after the zip upload but before `versions.json`, re-run the workflow and it
   resumes with the stored zip.

If only `gh release create` failed, `versions.json` is already updated and a re-run stops at
"already on downloads.ecomweb.app". Create the release by hand: download the zip from
`https://downloads.ecomweb.app/woocommerce/X.Y.Z/ecomweb-for-woocommerce-X.Y.Z.zip`, copy the
`notes` of that version from `https://downloads.ecomweb.app/woocommerce/versions.json`, then
`gh release create X.Y.Z <zip> --title X.Y.Z --notes "<notes>"`.

After `publish`, the `wordpress-org` job commits the same zip's files to wordpress.org SVN
(`trunk/` and `tags/X.Y.Z/`) with `10up/action-wordpress-plugin-deploy`, as `SVN_USERNAME` with
`SVN_PASSWORD` (both from the ecomweb-k8s vault, `ci_secrets`). A version already in SVN is
skipped, so re-running is safe. Release Confirmation is on for the plugin: wordpress.org holds
each version until a committer confirms it in the Release Management dashboard (an email
links there). Until then the listing keeps the previous version.

The downloads page's store version (`store.version` in `versions.json`) follows wordpress.org
on its own: the `sync-store` job runs daily, reads the version the plugin API serves, and sets
it if downloads.ecomweb.app lists it and it isn't yanked. To run it now, start the **Release**
workflow by hand with `sync-store-version`. The same workflow with `set-store-version` sets a
version directly, and `yank` with a reason withdraws a bad version.

All runs of the Release workflow share one concurrency group, so two never edit
`versions.json` at once. GitHub keeps only one waiting run per group: if a release is running
and two more runs queue (a second tag and the daily sync, say), the older waiting one is
cancelled. Push tags one at a time, and re-run a cancelled release.

### Testing `publish.sh` locally

Needs Docker, the aws CLI, Node 20+ and a built zip in `build/`.

```sh
docker run -d --name minio-dl -p 9100:9000 minio/minio server /data
export AWS_ACCESS_KEY_ID=minioadmin AWS_SECRET_ACCESS_KEY=minioadmin AWS_DEFAULT_REGION=auto
aws --endpoint-url http://localhost:9100 s3 mb s3://ecomweb-downloads
export R2_DOWNLOADS_ENDPOINT=http://localhost:9100 R2_DOWNLOADS_ACCESS_KEY_ID=minioadmin \
  R2_DOWNLOADS_SECRET_ACCESS_KEY=minioadmin R2_DOWNLOADS_BUCKET=ecomweb-downloads
bash .github/release/publish.sh woocommerce 1.0.0 build/ecomweb-for-woocommerce-1.0.0.zip readme.txt wp-readme  # published
bash .github/release/publish.sh woocommerce 1.0.0 build/ecomweb-for-woocommerce-1.0.0.zip readme.txt wp-readme  # exit 1, already on downloads.ecomweb.app
aws --endpoint-url http://localhost:9100 s3 rm s3://ecomweb-downloads/woocommerce/versions.json
bash .github/release/publish.sh woocommerce 1.0.0 build/ecomweb-for-woocommerce-1.0.0.zip readme.txt wp-readme  # resumed, same sha256
docker rm -f minio-dl
```
