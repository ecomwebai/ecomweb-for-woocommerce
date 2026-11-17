// Kept identical in ecomweb-for-woocommerce and ecomscrape-extension; change both.
//
// Runs edit-versions.sh against a fake `aws` that keeps objects in a temp folder.
import { test } from "node:test";
import assert from "node:assert/strict";
import { spawnSync } from "node:child_process";
import { chmodSync, mkdirSync, mkdtempSync, readFileSync, writeFileSync } from "node:fs";
import { tmpdir } from "node:os";
import { join, dirname } from "node:path";
import { fileURLToPath } from "node:url";

const here = dirname(fileURLToPath(import.meta.url));

// aws --endpoint-url E s3 cp s3://B/K DEST --only-show-errors
// aws --endpoint-url E s3api put-object --bucket B --key K --body F ...
const FAKE_AWS = `#!/usr/bin/env bash
set -euo pipefail
shift 2
case "$1" in
  s3) src=\${3#s3://}; cp "$FAKE_S3/$src" "$4" ;;
  s3api)
    shift 2
    while [ "$#" -gt 0 ]; do
      case "$1" in --bucket) b=$2 ;; --key) k=$2 ;; --body) f=$2 ;; esac
      shift
    done
    mkdir -p "$(dirname "$FAKE_S3/$b/$k")"; cp "$f" "$FAKE_S3/$b/$k" ;;
esac
`;

const versionsFile = (store, yanked = null) => ({
  product: "woo",
  store: store ? { version: store } : null,
  versions: [
    { version: "1.0.1", date: "2026-11-18", file: "w-1.0.1.zip", size: 1, sha256: "a", notes: "n", yanked },
    { version: "1.0.0", date: "2026-11-12", file: "w-1.0.0.zip", size: 1, sha256: "b", notes: "n", yanked: null },
  ],
});

function setup(file) {
  const dir = mkdtempSync(join(tmpdir(), "ev-"));
  const bin = join(dir, "bin");
  const s3 = join(dir, "s3");
  mkdirSync(bin);
  mkdirSync(join(s3, "bucket", "woo"), { recursive: true });
  writeFileSync(join(bin, "aws"), FAKE_AWS);
  chmodSync(join(bin, "aws"), 0o755);
  writeFileSync(join(s3, "bucket", "woo", "versions.json"), JSON.stringify(file));
  const run = (...args) =>
    spawnSync("bash", [join(here, "edit-versions.sh"), "woo", ...args], {
      encoding: "utf8",
      env: {
        ...process.env,
        PATH: `${bin}:${process.env.PATH}`,
        FAKE_S3: s3,
        R2_DOWNLOADS_ENDPOINT: "https://r2.example",
        R2_DOWNLOADS_ACCESS_KEY_ID: "id",
        R2_DOWNLOADS_SECRET_ACCESS_KEY: "secret",
        R2_DOWNLOADS_BUCKET: "bucket",
      },
    });
  const read = () => JSON.parse(readFileSync(join(s3, "bucket", "woo", "versions.json"), "utf8"));
  return { run, read };
}

test("sync-store sets a newer live version", () => {
  const { run, read } = setup(versionsFile("1.0.0"));
  const r = run("sync-store", "1.0.1");
  assert.equal(r.status, 0, r.stderr);
  assert.match(r.stdout, /sync-store 1\.0\.1: versions\.json updated/);
  assert.deepEqual(read().store, { version: "1.0.1" });
});

test("sync-store leaves versions.json alone when nothing changed, unlisted or yanked", () => {
  for (const [file, live, word] of [
    [versionsFile("1.0.1"), "1.0.1", "same"],
    [versionsFile("1.0.0"), "2.0.0", "unlisted"],
    [versionsFile("1.0.0", { date: "2026-11-18", reason: "bad" }), "1.0.1", "yanked"],
  ]) {
    const { run, read } = setup(file);
    const before = read();
    const r = run("sync-store", live);
    assert.equal(r.status, 0, r.stderr);
    assert.match(r.stdout, new RegExp(`store serves ${live.replace(/\./g, "\\.")}: ${word}`));
    assert.deepEqual(read(), before);
  }
});

test("set-store and yank", () => {
  const { run, read } = setup(versionsFile("1.0.0"));
  assert.equal(run("set-store", "1.0.1").status, 0);
  assert.deepEqual(read().store, { version: "1.0.1" });
  assert.equal(run("yank", "1.0.1", "broken upload").status, 0);
  assert.equal(read().versions[0].yanked.reason, "broken upload");
  const noReason = run("yank", "1.0.0");
  assert.notEqual(noReason.status, 0);
  assert.match(noReason.stderr, /yank reason is required/);
});

test("rejects a bad version or action", () => {
  const { run } = setup(versionsFile(null));
  assert.equal(run("sync-store", "v1").status, 1);
  assert.equal(run("rename", "1.0.0").status, 2);
});
