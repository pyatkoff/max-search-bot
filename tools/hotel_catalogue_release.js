#!/usr/bin/env node
'use strict';

const fs = require('node:fs');
const path = require('node:path');
const {execFileSync, spawnSync} = require('node:child_process');
const {isDeepStrictEqual} = require('node:util');
const {CONTRACT, INDEX, MAX_PACKAGE, digest, readFile, readSnapshot, snapshotSeal,
  verifyPackage, stageVersion} = require('./hotel_catalogue_package');

const SHA256 = /^[a-f0-9]{64}$/;
const VERSION = /^[a-f0-9]{64}-[a-f0-9]{64}$/;
const OPERATION = /^[a-zA-Z0-9][a-zA-Z0-9_-]{7,95}$/;
const RECEIPT = 'anytour-hotel-catalogue-operation-v1';

function requireThat(condition, error) {
  if (!condition) throw new Error(error);
}

function privateDirectory(directory) {
  const stat = fs.lstatSync(directory);
  requireThat(stat.isDirectory() && fs.realpathSync(directory) === directory &&
    (stat.mode & 0o022) === 0, 'invalid_catalogue_release_directory');
}

function withLock(releases, run) {
  privateDirectory(releases);
  const lock = path.join(releases, 'operation.lock');
  const fd = fs.openSync(lock, fs.constants.O_RDONLY | fs.constants.O_CREAT |
    fs.constants.O_NOFOLLOW | fs.constants.O_NONBLOCK, 0o600);
  try {
    const opened = fs.fstatSync(fd);
    requireThat(opened.isFile(), 'invalid_catalogue_lock');
    // flock on this inherited file description remains held by the parent fd.
    const result = spawnSync('flock', ['--exclusive', '--nonblock', '--conflict-exit-code', '75', '3'],
      {stdio:['ignore', 'ignore', 'pipe', fd]});
    requireThat(result.status === 0, result.status === 75 ? 'catalogue_operation_busy' : 'catalogue_lock_failed');
    const named = fs.lstatSync(lock);
    requireThat(named.isFile() && named.dev === opened.dev && named.ino === opened.ino,
      'catalogue_lock_replaced');
    return run();
  } finally {
    fs.closeSync(fd);
  }
}

function syncPath(target) {
  const fd = fs.openSync(target, fs.constants.O_RDONLY | fs.constants.O_NOFOLLOW);
  try { fs.fsyncSync(fd); }
  finally { fs.closeSync(fd); }
}

function writeNew(file, value) {
  const directory = path.dirname(file);
  const temp = fs.mkdtempSync(path.join(directory, '.operation-'));
  try {
    const body = path.join(temp, 'receipt.json');
    fs.writeFileSync(body, JSON.stringify(value) + '\n', {flag:'wx', mode:0o600});
    syncPath(body);
    fs.linkSync(body, file);
    syncPath(directory);
  } finally {
    fs.rmSync(temp, {recursive:true, force:true});
  }
}

function optionalReceipt(file) {
  if (!fs.lstatSync(file, {throwIfNoEntry:false})) return null;
  const value = JSON.parse(readFile(file, 8192));
  requireThat(value?.contract === RECEIPT, 'invalid_catalogue_operation_receipt');
  return value;
}

function currentVersion(releases) {
  const current = path.join(releases, 'current');
  if (!fs.lstatSync(current, {throwIfNoEntry:false})) return 'bundled';
  const target = fs.readlinkSync(current);
  const version = path.basename(target);
  requireThat(VERSION.test(version) && target === path.join(releases, version), 'invalid_catalogue_pointer');
  return version;
}

function selectVersion(releases, version) {
  const pointer = path.join(releases, 'current');
  if (version === 'bundled') {
    fs.unlinkSync(pointer);
  } else {
    const temporary = fs.mkdtempSync(path.join(releases, '.pointer-'));
    try {
      const next = path.join(temporary, 'current');
      fs.symlinkSync(path.join(releases, version), next);
      fs.renameSync(next, pointer);
    } finally {
      fs.rmSync(temporary, {recursive:true, force:true});
    }
  }
  syncPath(releases);
}

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
  return withLock(releases, () => {
    const operations = path.join(releases, 'operations');
    fs.mkdirSync(operations, {recursive:true, mode:0o700});
    privateDirectory(operations);
    const receipt = path.join(operations, operation + '.json');
    const pendingFile = path.join(releases, 'pending.json');
    const recorded = optionalReceipt(receipt);
    const pending = optionalReceipt(pendingFile);
    if (recorded) {
      requireThat(isDeepStrictEqual(recorded.request, request), 'catalogue_operation_id_reused');
      if (pending?.request?.operation === operation) {
        requireThat(isDeepStrictEqual(recorded, pending) && currentVersion(releases) === recorded.to,
          'catalogue_pending_conflict');
        fs.unlinkSync(pendingFile);
        syncPath(releases);
      }
      return recorded;
    }
    requireThat(!pending || isDeepStrictEqual(pending.request, request), 'catalogue_release_pending');
    // A previously rolled-back activation cannot affect a later activation of the same bytes.
    const rollbackMark = isActivation ? null : path.join(operations, current + '.rollback.json');
    const priorRollback = rollbackMark === null ? null : optionalReceipt(rollbackMark);
    requireThat(!priorRollback || (pending && isDeepStrictEqual(priorRollback, pending)), 'catalogue_activation_already_rolled_back');
    const env = Object.fromEntries(Object.entries(process.env).filter(([key]) => !key.startsWith('GIT_')));
    const installed = execFileSync('git', ['-C', app, 'rev-parse', '--show-toplevel', 'HEAD'],
      {encoding:'utf8', env, timeout:5000, stdio:['ignore','pipe','pipe']}).trimEnd();
    requireThat(installed === app + '\n' + application_sha, 'catalogue_application_changed');
    const reader = path.join(app, 'services', 'ManagerHotelKnowledge.php');
    const readerBytes = readFile(reader, 131072);
    const next = isActivation ? activate(app, releases, request, archive, reader, readerBytes, pending)
      : rollback(app, releases, request, optionalReceipt(path.join(operations, current + '.json')),
        digest(readerBytes), pending);
    if (pending) requireThat(isDeepStrictEqual(pending, next), 'catalogue_pending_conflict');
    else writeNew(pendingFile, next);
    if (rollbackMark !== null && !priorRollback) writeNew(rollbackMark, next);
    if (currentVersion(releases) !== next.to) selectVersion(releases, next.to);
    writeNew(receipt, next);
    fs.unlinkSync(pendingFile);
    syncPath(releases);
    return next;
  });
}

try {
  process.stdout.write(JSON.stringify(main(process.argv.slice(2))) + '\n');
} catch (error) {
  process.stderr.write((error.code || error.message) + '\n');
  process.exitCode = 1;
}
