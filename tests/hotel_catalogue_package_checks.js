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
  console.log('Hotel catalogue sealed package: ' + checks + ' CLI cases passed');
} finally {
  fs.rmSync(root, {recursive:true, force:true});
}
