#!/usr/bin/env node
'use strict';

const assert = require('node:assert/strict');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const {createHash} = require('node:crypto');
const {spawnSync} = require('node:child_process');

const root = fs.mkdtempSync(path.join(os.tmpdir(), 'hotel-package-regression-'));
const digest = (bytes, algorithm = 'sha256') => createHash(algorithm).update(bytes).digest('hex');
const buffer = value => Buffer.from(JSON.stringify(value) + '\n');
const runner = path.resolve(__dirname, '../tools/hotel_catalogue_package.js');
const reader = path.join(root, 'reader.php');
const otherReader = path.join(root, 'other-reader.php');
const packageFile = path.join(root, 'package.json');
const secondPackage = path.join(root, 'second.json');
const problemPackage = path.join(root, 'modified.json');
let checks = 0;

function assertCommand(args, expectedError) {
  const result = spawnSync(process.execPath, [runner, ...args], {encoding:'utf8', timeout:5000});
  assert.ifError(result.error);
  assert.equal(result.status, expectedError ? 1 : 0, result.stderr);
  ++checks;
  if (expectedError) {
    assert.match(result.stderr, expectedError);
    assert.equal(result.stdout, '');
  } else return JSON.parse(result.stdout);
}

function snapshot(directory, ids, changedId = null) {
  const target = path.join(root, directory);
  fs.mkdirSync(target);
  const bindings = [];
  for (const id of ids) {
    const bytes = buffer({id, approved_fixture:id !== changedId});
    const bundle_file = 'anytour-' + id + '-fixture.json';
    fs.writeFileSync(path.join(target, bundle_file), bytes);
    bindings.push({bundle_file, bundle_sha256:digest(bytes)});
  }
  const shard = buffer({schema_version:1, project_key:'anytour', version:'fixture', bindings});
  fs.writeFileSync(path.join(target, 'manifest.json'), shard);
  fs.writeFileSync(path.join(target, 'catalogue.json'), buffer({schema_version:'hotel_catalogue_v1',
    project_key:'anytour', shards:[{file:'manifest.json',
      git_blob_sha:digest(Buffer.concat([Buffer.from('blob ' + shard.length + '\0'), shard]), 'sha1')}]}));
  return target;
}

function checkCheckoutGuard() {
  const app = path.join(root, 'app');
  const privateRoot = path.join(root, 'max-search-bot-private-logs');
  const releases = path.join(privateRoot, 'hotel-catalogue-releases');
  const lock = path.join(releases, 'operation.lock');
  const supportedReader = path.join(app, 'services/ManagerHotelKnowledge.php');
  const bin = path.join(root, 'bin');
  const guard = path.resolve(__dirname, '../tools/deploy_hotel_catalogue_guard.sh');
  for (const folder of [privateRoot, path.dirname(supportedReader), bin]) fs.mkdirSync(folder, {recursive:true});
  const env = {...process.env, PATH:bin + path.delimiter + process.env.PATH};
  fs.writeFileSync(path.join(bin, 'php'), [
    '#!/bin/bash',
    '[[ "$1" == tools/hotel_catalogue_compatibility.php && "$2" == "$PWD" ]] || exit 1',
    'if [[ "$(cat "$3")" != "approved reader" ]]; then echo incompatible_reader >&2; exit 1; fi',
    'echo compatible',
  ].join('\n') + '\n', {mode:0o700});
  const git = (...args) => {
    const result = spawnSync('git', args, {cwd:app, encoding:'utf8', timeout:5000});
    assert.equal(result.status, 0, result.stderr);
    return result.stdout.trim();
  };
  const commitReader = text => {
    fs.writeFileSync(supportedReader, text);
    git('add', 'services/ManagerHotelKnowledge.php');
    git('-c', 'user.name=Fixture', '-c', 'user.email=fixture@example.invalid', 'commit', '-qm', text);
    return git('rev-parse', 'HEAD');
  };
  git('init', '-q', '--object-format=sha1');
  const accepted = commitReader('approved reader');
  const incompatible = commitReader('different reader');
  git('reset', '--hard', accepted);
  const switchTo = (sha, expected, diagnostic) => {
    const command = `set -euo pipefail
source "$1"
deploy_hotel_catalogue_guard "$PWD" "$2"
if flock --nonblock "$3" true; then exit 42; fi
git reset --hard "$2"`;
    const noSwitch = git('rev-parse', 'HEAD');
    const result = spawnSync('bash', ['-c', command, 'test', guard, sha, lock], {
      cwd:app, env, encoding:'utf8', timeout:5000,
    });
    assert.ifError(result.error);
    assert.equal(result.status, expected, result.stderr);
    assert.match(result.stdout + result.stderr, diagnostic);
    assert.equal(git('rev-parse', 'HEAD'), expected === 0 ? sha : noSwitch);
    ++checks;
  };
  switchTo(incompatible, 0, /hotel_catalogue_guard=bundled/);
  fs.symlinkSync(path.join(releases, 'test-only'), path.join(releases, 'current'));
  switchTo(accepted, 0, /compatible/);
  switchTo(incompatible, 1, /incompatible_reader/);
  const pending = path.join(releases, 'pending.json');
  fs.writeFileSync(pending, '{}');
  switchTo(accepted, 1, /release_pending/);
  fs.unlinkSync(pending);
  fs.renameSync(lock, lock + '.original');
  fs.symlinkSync(lock + '.original', lock);
  switchTo(accepted, 1, /invalid_lock/);
}

try {
  fs.writeFileSync(reader, '<?php // fixture reader v1');
  fs.writeFileSync(otherReader, '<?php // fixture reader v2');
  const base = snapshot('active', [10]);
  const candidate = snapshot('candidate', [10, 20]);
  const replaced = snapshot('replacement', [20, 30]);
  const receipt = assertCommand(['build', base, reader, candidate, packageFile]);
  assert.equal(receipt.file_count, 4);
  assert.notEqual(receipt.previous_catalogue_sha256, receipt.catalogue_sha256);
  assert.equal(fs.statSync(packageFile).mode & 0o777, 0o600);
  const verify = (file, hash, error, current = base, contract = reader) =>
    assertCommand(['verify', current, contract, file, hash], error);
  assert.deepEqual(verify(packageFile, receipt.package_sha256), receipt);
  const releases = path.join(root, 'releases');
  fs.mkdirSync(releases, {mode:0o700});
  const pointer = path.join(releases, 'current');
  fs.symlinkSync(base, pointer);
  const stage = (hash, error, current = base, contract = reader, target = releases) =>
    assertCommand(['stage', current, contract, packageFile, hash, target], error);
  stage('0'.repeat(64), /package_digest_mismatch/);
  stage(receipt.package_sha256, /package_binding_mismatch/, base, otherReader);
  stage(receipt.package_sha256, /catalogue_append_not_admitted/, candidate);
  const rootAlias = path.join(root, 'release-alias');
  fs.symlinkSync(releases, rootAlias);
  stage(receipt.package_sha256, /invalid_catalogue_release_directory/, base, reader, rootAlias);
  assert.deepEqual(fs.readdirSync(releases), ['current']);
  const staged = stage(receipt.package_sha256);
  const version = receipt.package_sha256 + '-' + receipt.reader_sha256;
  const target = path.join(releases, version);
  const catalogue_index_sha256 = digest(fs.readFileSync(path.join(candidate, 'catalogue.json')));
  assert.deepEqual(staged, {...receipt, catalogue_index_sha256, staged_version:version});
  const members = fs.readdirSync(candidate).sort();
  assert.deepEqual(fs.readdirSync(path.join(target, 'hotels')).sort(), members);
  for (const member of members) {
    const installed = path.join(target, 'hotels', member);
    assert.deepEqual(fs.readFileSync(installed), fs.readFileSync(path.join(candidate, member)));
    assert.equal(fs.statSync(installed).mode & 0o777, 0o600);
  }
  const seal = path.join(target, 'release.json');
  const originalSeal = fs.readFileSync(seal);
  assert.deepEqual(JSON.parse(originalSeal), {contract:receipt.contract, package_sha256:receipt.package_sha256,
    reader_sha256:receipt.reader_sha256, catalogue_index_sha256});
  assert.equal(fs.statSync(target).mode & 0o777, 0o700);
  assert.deepEqual(fs.readdirSync(target).sort(), ['hotels', 'release.json']);
  stage(receipt.package_sha256, /EEXIST/);
  assert.deepEqual(fs.readFileSync(seal), originalSeal);
  assert.equal(fs.readlinkSync(pointer), base);
  assert.deepEqual(assertCommand(['build', base, reader, candidate, secondPackage]), receipt);
  assert.deepEqual(fs.readFileSync(packageFile), fs.readFileSync(secondPackage));
  assertCommand(['build', base, reader, candidate, secondPackage], /EEXIST/);
  assert.deepEqual(fs.readFileSync(packageFile), fs.readFileSync(secondPackage));
  verify(packageFile, '0'.repeat(64), /package_digest_mismatch/);
  verify(packageFile, receipt.package_sha256, /package_binding_mismatch/, base, otherReader);
  verify(packageFile, receipt.package_sha256, /catalogue_append_not_admitted/, candidate);
  verify(packageFile, receipt.package_sha256, /package_binding_mismatch/, snapshot('older', []));
  assertCommand(['build', base, reader, replaced, problemPackage], /catalogue_append_not_admitted/);
  assertCommand(['build', base, reader, snapshot('edited-and-appended', [10, 20], 10), problemPackage],
    /catalogue_append_not_admitted/);
  const tamper = (change, error) => {
    const contents = JSON.parse(fs.readFileSync(packageFile, 'utf8'));
    change(contents);
    const bytes = buffer(contents);
    fs.writeFileSync(problemPackage, bytes);
    verify(problemPackage, digest(bytes), error);
  };
  tamper(v => v.files.push(v.files[0]), /invalid_package_member/);
  tamper(v => {v.files[0][0] = '../catalogue.json';}, /invalid_package_member/);
  tamper(v => {v.files[0][1] = Buffer.from('{}').toString('base64');}, /invalid_catalogue_bundle_pin/);
  tamper(v => {v.files.find(row => row[0] === 'manifest.json')[1] = Buffer.from('{}').toString('base64');},
    /invalid_catalogue_shard_pin/);
  tamper(v => {v.files.reverse();}, /package_binding_mismatch/);
  tamper(v => {v.files[0][1] += '\n';}, /invalid_package_member|package_binding_mismatch/);
  tamper(v => {v.previous_catalogue_sha256 = 'c'.repeat(64);}, /package_binding_mismatch/);
  const extra = path.join(candidate, 'anytour-100-fixture.json');
  fs.writeFileSync(extra, '{}');
  assertCommand(['build', base, reader, candidate, secondPackage], /unexpected_catalogue_member/);
  fs.unlinkSync(extra);
  fs.symlinkSync(path.join(base, 'anytour-10-fixture.json'), extra);
  assertCommand(['build', base, reader, candidate, secondPackage], /invalid_package_file/);
  checkCheckoutGuard();
  console.log('Hotel catalogue sealed package: ' + checks + ' CLI cases passed');
} finally {
  fs.rmSync(root, {recursive:true, force:true});
}
