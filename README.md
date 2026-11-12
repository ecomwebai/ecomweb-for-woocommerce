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

Two actions stay manual:

- Uploading the zip to wordpress.org.
- Telling the downloads page which version is live in the store: run the **Release**
  workflow by hand (`set-store-version`, version X.Y.Z) once the store has accepted it.
  The same workflow with `yank` and a reason withdraws a bad version.

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
