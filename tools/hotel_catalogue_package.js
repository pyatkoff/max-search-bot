#!/usr/bin/env node
'use strict';

const fs = require('node:fs');
const path = require('node:path');
const {createHash} = require('node:crypto');
const {hotelCatalogueAddition} = require('./ci_change_scope');

const INDEX = 'catalogue.json';
const DATA_ROOT = 'knowledge/hotels/';
const CONTRACT = 'anytour-hotel-catalogue-package-v1';
const SHARD = /^(?:manifest|manifest-[0-9]{4})\.json$/;
const BUNDLE = /^anytour-[1-9][0-9]{0,8}-[a-z0-9]+(?:-[a-z0-9]+)*\.json$/;
const MAX_FILES = 1 + 32 + 32 * 32;
const MAX_PACKAGE = 26 * 1024 * 1024;
const SHA256 = /^[a-f0-9]{64}$/;
const utf8 = new TextDecoder('utf-8', {fatal:true, ignoreBOM:true});

const digest = bytes => createHash('sha256').update(bytes).digest('hex');

function json(bytes) {
  try { return JSON.parse(utf8.decode(bytes)); }
  catch (_) { throw new Error('invalid_package_json'); }
}

function fileLimit(name) {
  if (typeof name !== 'string' || name.length > 255) return 0;
  if (SHARD.test(name)) return 65536;
  return name === INDEX || BUNDLE.test(name) ? 16384 : 0;
}

function readFile(file, limit) {
  const before = fs.lstatSync(file);
  if (!limit || !before.isFile() || before.size > limit) throw new Error('invalid_package_file');
  const fd = fs.openSync(file, fs.constants.O_RDONLY | fs.constants.O_NOFOLLOW | fs.constants.O_NONBLOCK);
  try {
    const first = fs.fstatSync(fd);
    if (!first.isFile() || first.dev !== before.dev || first.ino !== before.ino || first.size !== before.size) {
      throw new Error('package_file_changed');
    }
    const buffer = Buffer.alloc(first.size + 1);
    let length = 0;
    while (length < buffer.length) {
      const count = fs.readSync(fd, buffer, length, buffer.length - length, null);
      if (count === 0) break;
      length += count;
    }
    const last = fs.fstatSync(fd);
    if (length !== first.size || last.size !== first.size || last.mtimeMs !== first.mtimeMs || last.ctimeMs !== first.ctimeMs) {
      throw new Error('package_file_changed');
    }
    return buffer.subarray(0, length);
  } finally {
    fs.closeSync(fd);
  }
}

function readSnapshot(directory) {
  const root = path.resolve(directory);
  if (fs.realpathSync(root) !== root) throw new Error('invalid_catalogue_directory');
  const files = new Map();
  const listing = fs.opendirSync(root);
  try {
    let entry;
    while ((entry = listing.readSync()) !== null) {
      if (files.size >= MAX_FILES) throw new Error('catalogue_file_limit');
      files.set(entry.name, readFile(path.join(root, entry.name), fileLimit(entry.name)));
    }
  } finally {
    listing.closeSync();
  }
  return files;
}

function snapshotSeal(files) {
  const index = json(files.get(INDEX));
  if (index?.schema_version !== 'hotel_catalogue_v1' || index.project_key !== 'anytour' ||
      !Array.isArray(index.shards) || index.shards.length < 1 || index.shards.length > 32) {
    throw new Error('invalid_catalogue_index');
  }
  const referenced = new Set([INDEX]);
  for (const [position, shard] of index.shards.entries()) {
    const name = shard?.file;
    const bytes = files.get(name);
    if (typeof name !== 'string' || (position === 0 ? name !== 'manifest.json' : !/^manifest-[0-9]{4}\.json$/.test(name)) ||
        !bytes || referenced.has(name) ||
        shard.git_blob_sha !== createHash('sha1').update('blob ' + bytes.length + '\0').update(bytes).digest('hex')) {
      throw new Error('invalid_catalogue_shard_pin');
    }
    referenced.add(name);
    const manifest = json(bytes);
    if (manifest?.schema_version !== 1 || manifest.project_key !== 'anytour' ||
        !Array.isArray(manifest.bindings) || manifest.bindings.length > 32) throw new Error('invalid_catalogue_manifest');
    for (const row of manifest.bindings) {
      const member = row?.bundle_file;
      const data = files.get(member);
      if (typeof member !== 'string' || !BUNDLE.test(member) || referenced.has(member) ||
          !data || row.bundle_sha256 !== digest(data)) throw new Error('invalid_catalogue_bundle_pin');
      referenced.add(member);
      json(data);
    }
  }
  if (files.size !== referenced.size) throw new Error('unexpected_catalogue_member');
  return digest(JSON.stringify([...files.keys()].sort().map(name => [name, digest(files.get(name))])));
}

function sealPackage(current, staged, reader) {
  const previous_catalogue_sha256 = snapshotSeal(current);
  const catalogue_sha256 = snapshotSeal(staged);
  const changes = [];
  for (const name of new Set([...current.keys(), ...staged.keys()])) {
    const before = current.get(name), after = staged.get(name);
    if (before && after && before.equals(after)) continue;
    changes.push({path:DATA_ROOT + name, status:before ? after ? 'M' : 'D' : 'A',
      oldMode:before ? '100644' : '000000', newMode:after ? '100644' : '000000'});
  }
  if (!hotelCatalogueAddition(changes, (side, filename) =>
    utf8.decode((side === 'base' ? current : staged).get(filename.slice(DATA_ROOT.length))))) {
    throw new Error('catalogue_append_not_admitted');
  }
  return {contract:CONTRACT, reader_sha256:digest(reader), previous_catalogue_sha256, catalogue_sha256};
}

function packageReceipt(seal, staged, output = null) {
  const hash = createHash('sha256');
  let bytes = 0;
  const emit = chunk => {
    const data = Buffer.from(chunk);
    hash.update(data);
    bytes += data.length;
    if (output !== null) fs.writeFileSync(output, data);
  };
  emit(JSON.stringify(seal).slice(0, -1) + ',"files":[');
  let separator = '';
  for (const name of [...staged.keys()].sort()) {
    emit(separator + JSON.stringify([name, staged.get(name).toString('base64')]));
    separator = ',';
  }
  emit(']}\n');
  return {...seal, package_sha256:hash.digest('hex'), file_count:staged.size, bytes};
}

function verifyPackage(current, reader, bytes, expectedSha) {
  if (!SHA256.test(expectedSha) || digest(bytes) !== expectedSha) throw new Error('package_digest_mismatch');
  const payload = json(bytes);
  if (payload?.contract !== CONTRACT || !Array.isArray(payload.files) || payload.files.length > MAX_FILES) {
    throw new Error('invalid_catalogue_package');
  }
  const staged = new Map();
  for (const entry of payload.files) {
    if (!Array.isArray(entry) || entry.length !== 2) throw new Error('invalid_package_member');
    const [name, encoded] = entry;
    const limit = fileLimit(name);
    if (!limit || staged.has(name) || typeof encoded !== 'string' || encoded.length > Math.ceil(limit / 3) * 4) {
      throw new Error('invalid_package_member');
    }
    const data = Buffer.from(encoded, 'base64');
    if (data.length > limit) throw new Error('invalid_package_member');
    staged.set(name, data);
  }
  const receipt = packageReceipt(sealPackage(current, staged, reader), staged);
  if (receipt.package_sha256 !== expectedSha) throw new Error('package_binding_mismatch');
  return receipt;
}

function main(args) {
  const [action, current, reader, inputs, last] = args;
  if (args.length !== 5 || !['build', 'verify'].includes(action)) {
    throw new Error('usage: build CURRENT READER STAGED PACKAGE | verify CURRENT READER PACKAGE SHA256');
  }
  const baseline = readSnapshot(current);
  const contract = readFile(reader, 131072);
  if (action === 'verify') return verifyPackage(baseline, contract, readFile(inputs, MAX_PACKAGE), last);

  const staged = readSnapshot(inputs);
  const seal = sealPackage(baseline, staged, contract);
  const output = path.resolve(last);
  // Publish a completed private staging file without replacing an existing one.
  const dir = fs.mkdtempSync(path.join(path.dirname(output), '.hotel-package-'));
  try {
    const temporary = path.join(dir, 'package.json');
    const file = fs.openSync(temporary, 'wx', 0o600);
    let receipt;
    try { receipt = packageReceipt(seal, staged, file); }
    finally { fs.closeSync(file); }
    fs.linkSync(temporary, output);
    return receipt;
  } finally {
    fs.rmSync(dir, {recursive:true, force:true});
  }
}

try {
  process.stdout.write(JSON.stringify(main(process.argv.slice(2))) + '\n');
} catch (error) {
  process.stderr.write((error.code || error.message) + '\n');
  process.exitCode = 1;
}
