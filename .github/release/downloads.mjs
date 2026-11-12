// Kept identical in ecomweb-for-woocommerce and ecomscrape-extension; change both.
//
// Edits the per-product versions.json published to R2 for downloads.ecomweb.app.
// Dependency-free ESM, Node >= 20.
import { readFileSync } from "node:fs";
import { parseArgs } from "node:util";
import { fileURLToPath } from "node:url";

const VERSION_RE = /^[0-9]+\.[0-9]+\.[0-9]+$/;

function assertVersion(v) {
  if (typeof v !== "string" || !VERSION_RE.test(v)) {
    throw new Error(`invalid version: ${v}`);
  }
}

export function compareVersions(a, b) {
  assertVersion(a);
  assertVersion(b);
  const pa = a.split(".").map(Number);
  const pb = b.split(".").map(Number);
  for (let i = 0; i < 3; i++) {
    if (pa[i] !== pb[i]) return pa[i] < pb[i] ? -1 : 1;
  }
  return 0;
}

export function emptyVersions(product) {
  return { product, store: null, versions: [] };
}

const isListed = (file, version) => file.versions.some((v) => v.version === version);

export function releaseAction(file, version, zipExists) {
  assertVersion(version);
  if (isListed(file, version)) return "published";
  return zipExists ? "resume" : "publish";
}

export function addVersion(file, entry) {
  assertVersion(entry.version);
  if (isListed(file, entry.version)) {
    throw new Error(`version ${entry.version} already listed`);
  }
  const versions = [...file.versions, { ...entry, yanked: null }].sort((a, b) =>
    compareVersions(b.version, a.version),
  );
  return { ...file, versions };
}

export function setStoreVersion(file, version) {
  assertVersion(version);
  if (!isListed(file, version)) throw new Error(`version ${version} not listed`);
  return { ...file, store: { version } };
}

export function yankVersion(file, version, reason, date) {
  assertVersion(version);
  if (!isListed(file, version)) throw new Error(`version ${version} not listed`);
  if (typeof reason !== "string" || reason.trim() === "") {
    throw new Error("yank reason is required");
  }
  return {
    ...file,
    versions: file.versions.map((v) =>
      v.version === version ? { ...v, yanked: { date, reason: reason.trim() } } : v,
    ),
  };
}

export function extractNotes(text, format, version) {
  assertVersion(version);
  const lines = text.split(/\r?\n/);
  const out = [];
  let state = format === "wp-readme" ? "seek-changelog" : "seek";
  for (const line of lines) {
    const t = line.trim();
    if (format === "wp-readme") {
      if (state === "seek-changelog") {
        if (/^==\s*Changelog\s*==$/i.test(t)) state = "seek";
        continue;
      }
      if (state === "seek") {
        if (/^==[^=].*==$/.test(t)) state = "done";
        else if (t === `= ${version} =`) state = "in";
        if (state === "done") break;
        continue;
      }
      if (/^=.*=$/.test(t)) break;
      out.push(line);
    } else if (format === "markdown") {
      if (state === "seek") {
        if (t === `## ${version}`) state = "in";
        continue;
      }
      if (line.startsWith("## ")) break;
      out.push(line);
    } else {
      throw new Error(`unknown notes format: ${format}`);
    }
  }
  const notes = out.join("\n").trim();
  if (!notes) throw new Error(`no notes for ${version}`);
  return notes;
}

function main(argv) {
  const [cmd, ...rest] = argv;
  const { values: o } = parseArgs({
    args: rest,
    options: {
      product: { type: "string" },
      versions: { type: "string" },
      version: { type: "string" },
      "zip-exists": { type: "string" },
      file: { type: "string" },
      size: { type: "string" },
      sha256: { type: "string" },
      date: { type: "string" },
      "notes-from": { type: "string" },
      "notes-format": { type: "string" },
      reason: { type: "string" },
      from: { type: "string" },
      format: { type: "string" },
    },
  });
  const need = (k) => {
    if (o[k] === undefined) throw new Error(`missing --${k}`);
    return o[k];
  };
  if (cmd === "notes") {
    // Checks the changelog before anything is uploaded; exits 1 if the version has no notes.
    const text = readFileSync(need("from"), "utf8");
    process.stdout.write(extractNotes(text, need("format"), need("version")) + "\n");
    return;
  }
  const product = need("product");
  let file;
  try {
    file = JSON.parse(readFileSync(need("versions"), "utf8"));
  } catch (e) {
    if (e.code !== "ENOENT") throw e;
    file = emptyVersions(product);
  }
  const print = (f) => process.stdout.write(JSON.stringify(f, null, 2) + "\n");

  switch (cmd) {
    case "action": {
      const z = need("zip-exists");
      if (z !== "true" && z !== "false") throw new Error("--zip-exists must be true or false");
      process.stdout.write(releaseAction(file, need("version"), z === "true") + "\n");
      break;
    }
    case "add": {
      const version = need("version");
      const size = Number(need("size"));
      if (!Number.isInteger(size) || size < 0) throw new Error("invalid --size");
      const notes = extractNotes(
        readFileSync(need("notes-from"), "utf8"),
        need("notes-format"),
        version,
      );
      print(
        addVersion(file, {
          version,
          date: need("date"),
          file: need("file"),
          size,
          sha256: need("sha256"),
          notes,
        }),
      );
      break;
    }
    case "set-store":
      print(setStoreVersion(file, need("version")));
      break;
    case "yank":
      print(yankVersion(file, need("version"), need("reason"), need("date")));
      break;
    default:
      throw new Error(`unknown command: ${cmd}`);
  }
}

if (process.argv[1] === fileURLToPath(import.meta.url)) {
  try {
    main(process.argv.slice(2));
  } catch (e) {
    process.stderr.write(`${e.message}\n`);
    process.exit(1);
  }
}
