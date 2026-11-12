import { test } from "node:test";
import assert from "node:assert/strict";
import { execFileSync } from "node:child_process";
import { mkdtempSync, writeFileSync } from "node:fs";
import { tmpdir } from "node:os";
import { join, dirname } from "node:path";
import { fileURLToPath } from "node:url";
import {
  compareVersions,
  emptyVersions,
  releaseAction,
  addVersion,
  setStoreVersion,
  yankVersion,
  extractNotes,
} from "./downloads.mjs";

const here = dirname(fileURLToPath(import.meta.url));
const entry = (version) => ({
  version,
  date: "2026-11-12",
  file: `p-${version}.zip`,
  size: 10,
  sha256: "abc",
  notes: "n",
});
const empty = emptyVersions("woo");
const one = addVersion(empty, entry("1.0.0"));

test("compareVersions", () => {
  assert.ok(compareVersions("1.0.10", "1.0.9") > 0);
  assert.equal(compareVersions("2.0.0", "2.0.0"), 0);
  assert.ok(compareVersions("1.0.0", "1.0.1") < 0);
  assert.throws(() => compareVersions("1.0", "1.0.0"));
});

test("emptyVersions", () => {
  assert.deepEqual(empty, { product: "woo", store: null, versions: [] });
});

test("releaseAction", () => {
  assert.equal(releaseAction(empty, "1.0.0", false), "publish");
  assert.equal(releaseAction(empty, "1.0.0", true), "resume");
  assert.equal(releaseAction(one, "1.0.0", true), "published");
  assert.equal(releaseAction(one, "1.0.0", false), "published");
});

test("addVersion sorts newest first and sets yanked null", () => {
  const f = addVersion(one, entry("1.0.1"));
  assert.deepEqual(f.versions.map((v) => v.version), ["1.0.1", "1.0.0"]);
  assert.equal(f.versions[0].yanked, null);
  const g = addVersion(addVersion(empty, entry("1.0.10")), entry("1.0.9"));
  assert.deepEqual(g.versions.map((v) => v.version), ["1.0.10", "1.0.9"]);
});

test("addVersion rejects duplicates and does not mutate", () => {
  const before = JSON.stringify(one);
  assert.throws(() => addVersion(one, entry("1.0.0")));
  addVersion(one, entry("1.0.1"));
  assert.equal(JSON.stringify(one), before);
});

test("setStoreVersion", () => {
  assert.deepEqual(setStoreVersion(one, "1.0.0").store, { version: "1.0.0" });
  assert.throws(() => setStoreVersion(one, "9.9.9"));
});

test("yankVersion", () => {
  const f = yankVersion(one, "1.0.0", "broken", "2026-11-13");
  assert.deepEqual(f.versions[0].yanked, { date: "2026-11-13", reason: "broken" });
  assert.equal(one.versions[0].yanked, null);
  assert.throws(() => yankVersion(one, "1.0.0", "  ", "2026-11-13"));
  assert.throws(() => yankVersion(one, "2.0.0", "x", "2026-11-13"));
});

const readme = `=== Plugin ===
Stable tag: 1.0.1

== Description ==
Text.

== Changelog ==

= 1.0.1 =
* Fixed a thing
* Added a thing

= 1.0.0 =
* Initial release

== Upgrade Notice ==

= 1.0.1 =
Upgrade now.
`;

test("extractNotes wp-readme", () => {
  assert.equal(extractNotes(readme, "wp-readme", "1.0.1"), "* Fixed a thing\n* Added a thing");
  assert.equal(extractNotes(readme, "wp-readme", "1.0.0"), "* Initial release");
  assert.throws(() => extractNotes(readme, "wp-readme", "1.2.0"), /no notes for 1\.2\.0/);
});

test("extractNotes markdown", () => {
  const md = "# Changelog\n\n## 1.0.1\n\n- a\n- b\n\n## 1.0.0\n\n- c\n";
  assert.equal(extractNotes(md, "markdown", "1.0.1"), "- a\n- b");
  assert.throws(() => extractNotes(md, "markdown", "3.0.0"), /no notes for 3\.0\.0/);
});

test("CLI add with no versions file", () => {
  const dir = mkdtempSync(join(tmpdir(), "dl-"));
  const notes = join(dir, "CHANGELOG.md");
  execFileSync("node", ["-e", `require("fs").writeFileSync(${JSON.stringify(notes)}, "## 1.0.0\\n\\n- first\\n")`]);
  const out = execFileSync("node", [
    join(here, "downloads.mjs"), "add",
    "--product", "woo", "--versions", join(dir, "versions.json"),
    "--version", "1.0.0", "--file", "woo-1.0.0.zip", "--size", "123",
    "--sha256", "deadbeef", "--date", "2026-11-12",
    "--notes-from", notes, "--notes-format", "markdown",
  ]).toString();
  const json = JSON.parse(out);
  assert.equal(json.product, "woo");
  assert.equal(json.versions.length, 1);
  assert.equal(json.versions[0].size, 123);
  assert.equal(json.versions[0].notes, "- first");
  assert.ok(out.endsWith("}\n"));
});

test("CLI action", () => {
  const dir = mkdtempSync(join(tmpdir(), "dl-"));
  const out = execFileSync("node", [
    join(here, "downloads.mjs"), "action", "--product", "woo",
    "--versions", join(dir, "none.json"), "--version", "1.0.0", "--zip-exists", "true",
  ]).toString();
  assert.equal(out.trim(), "resume");
});

test("CLI notes prints the notes, fails when they are missing", () => {
  const dir = mkdtempSync(join(tmpdir(), "dl-"));
  const notes = join(dir, "CHANGELOG.md");
  writeFileSync(notes, "## 1.0.2 - 2026-11-20\n\n- dated\n\n## 1.0.1\n\n- second\n");
  const run = (version) =>
    execFileSync("node", [
      join(here, "downloads.mjs"), "notes",
      "--from", notes, "--format", "markdown", "--version", version,
    ], { stdio: "pipe" }).toString();
  assert.equal(run("1.0.1"), "- second\n");
  assert.throws(() => run("1.0.2"), (e) => e.status === 1 && /no notes for 1\.0\.2/.test(e.stderr));
  assert.throws(() => run("9.9.9"), (e) => e.status === 1);
});
