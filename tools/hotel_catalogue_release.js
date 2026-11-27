#!/usr/bin/env node
'use strict';

const fs = require('node:fs');
const path = require('node:path');
const {spawnSync} = require('node:child_process');
const {isDeepStrictEqual} = require('node:util');
const {CONTRACT, INDEX, MAX_PACKAGE, digest, readSnapshot, snapshotSeal,
  verifyPackage, stageVersion} = require('./hotel_catalogue_package');

const {VERSION, OPERATION, readFile, requireThat, currentVersion, privateDirectory, checkApplication,
  syncPath, transact} = require('./catalogue_release_transaction');

const SHA256 = /^[a-f0-9]{64}$/;
const RECEIPT = 'anytour-hotel-catalogue-operation-v1';

function releaseSeal(version, index) {
  const [package_sha256, reader_sha256] = version.split('-');
  return {contract:CONTRACT, package_sha256, reader_sha256, catalogue_index_sha256:digest(index)};
}

function snapshot(app, releases, version, readerSha) {
  if (version === 'bundled') return readSnapshot(path.join(app, 'knowledge', 'hotels'));
  requireThat(VERSION.test(version) && version.endsWith('-' + readerSha), 'catalogue_reader_changed');
  const directory = path.join(releases, version);
  privateDirectory(directory);
  const files = readSnapshot(path.join(directory, 'hotels'));
  const seal = JSON.parse(readFile(path.join(directory, 'release.json'), 4096));
  requireThat(files.has(INDEX) && isDeepStrictEqual(seal, releaseSeal(version, files.get(INDEX))),
    'invalid_staged_catalogue_seal');
  return files;
}

function validateTypedAdditions(reader, directory, added) {
  const result = spawnSync('php', ['-r', 'require $argv[1]; exit(ManagerHotelKnowledge::validateReleaseAdditions(' +
    '$argv[2], json_decode(stream_get_contents(STDIN), true, 8, JSON_THROW_ON_ERROR)) ? 0 : 1);',
    reader, directory], {stdio:'pipe', timeout:30000, input:JSON.stringify(added)});
  requireThat(result.status === 0, 'catalogue_typed_validation_failed');
}

function activate(app, releases, request, archive, reader, readerBytes, pending) {
  const reader_sha256 = digest(readerBytes);
  const version = request.package_sha256 + '-' + reader_sha256;
  const from = request.previous_version;
  const actual = currentVersion(releases);
  requireThat(actual === from || (pending && actual === version), 'catalogue_baseline_changed');
  const baseline = snapshot(app, releases, from, reader_sha256);
  const checked = verifyPackage(baseline, readerBytes, readFile(archive, MAX_PACKAGE), request.package_sha256);
  const root = path.join(releases, version);
  const directory = path.join(root, 'hotels');
  if (fs.lstatSync(root, {throwIfNoEntry:false})) {
    const staged = snapshot(app, releases, version, reader_sha256);
    requireThat(staged.size === checked.staged.size &&
      [...checked.staged].every(([name, bytes]) => staged.get(name)?.equals(bytes)), 'staged_catalogue_changed');
  } else {
    requireThat(!pending, 'pending_catalogue_version_missing');
    stageVersion(releases, checked);
  }
  if (!pending) {
    const added = [...checked.staged.keys()].filter(name => !baseline.has(name) && name.startsWith('anytour-'));
    validateTypedAdditions(reader, directory, added);
    for (const name of checked.staged.keys()) syncPath(path.join(directory, name));
    syncPath(directory);
    syncPath(path.join(root, 'release.json'));
    syncPath(root);
    syncPath(releases);
  }
  return {contract:RECEIPT, request, from, to:version, state:'activated', reader_sha256,
    previous_catalogue_sha256:checked.receipt.previous_catalogue_sha256,
    catalogue_sha256:checked.receipt.catalogue_sha256};
}

function rollback(app, releases, request, activation, readerSha, pending) {
  requireThat(activation?.request?.operation === request.activation && activation.request.action === 'activate' &&
    activation.state === 'activated' && VERSION.test(activation.to) && activation.reader_sha256 === readerSha,
    'invalid_catalogue_activation_receipt');
  const actual = currentVersion(releases);
  requireThat(actual === activation.to || (pending && actual === activation.from), 'catalogue_baseline_changed');
  const restored = snapshot(app, releases, activation.from, readerSha);
  requireThat(snapshotSeal(restored) === activation.previous_catalogue_sha256, 'rollback_catalogue_changed');
  return {contract:RECEIPT, request, from:activation.to, to:activation.from, state:'rolled_back',
    reader_sha256:readerSha, previous_catalogue_sha256:activation.catalogue_sha256,
    catalogue_sha256:activation.previous_catalogue_sha256};
}

function main(args) {
  const [action, app, application_sha, current, archive, package_sha256, activateOp] = args;
  const isActivation = action === 'activate';
  requireThat((isActivation && args.length === 7) || (action === 'rollback' && args.length === 5),
    'usage: activate APP APP_SHA PREVIOUS PACKAGE APPROVED_SHA OPERATION | rollback APP APP_SHA ACTIVATION OPERATION');
  const operation = isActivation ? activateOp : archive;
  requireThat(path.isAbsolute(app) && fs.realpathSync(app) === app && app !== '/' &&
    /^[a-f0-9]{40}$/.test(application_sha) && OPERATION.test(operation), 'invalid_catalogue_operation');
  requireThat(isActivation ? (current === 'bundled' || VERSION.test(current)) && SHA256.test(package_sha256)
    : OPERATION.test(current) && current !== operation, 'invalid_catalogue_operation');
  const request = isActivation
    ? {action, operation, application_sha, previous_version:current, package_sha256}
    : {action, operation, application_sha, activation:current};
  const releases = path.join(path.dirname(app), 'max-search-bot-private-logs', 'hotel-catalogue-releases');
  return transact({releases, contract:RECEIPT, request}, (pending, activation) => {
    checkApplication(app, application_sha);
    const reader = path.join(app, 'services', 'ManagerHotelKnowledge.php');
    const readerBytes = readFile(reader, 131072);
    return isActivation ? activate(app, releases, request, archive, reader, readerBytes, pending)
      : rollback(app, releases, request, activation, digest(readerBytes), pending);
  });
}

try {
  process.stdout.write(JSON.stringify(main(process.argv.slice(2))) + '\n');
} catch (error) {
  process.stderr.write((error.code || error.message) + '\n');
  process.exitCode = 1;
}
