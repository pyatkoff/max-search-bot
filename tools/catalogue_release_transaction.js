'use strict';

const fs = require('node:fs');
const path = require('node:path');
const {execFileSync, spawnSync} = require('node:child_process');
const {isDeepStrictEqual} = require('node:util');

const VERSION = /^[a-f0-9]{64}-[a-f0-9]{64}$/;
const OPERATION = /^[a-zA-Z0-9][a-zA-Z0-9_-]{7,95}$/;

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

function requireThat(condition, error) {
  if (!condition) throw new Error(error);
}

function privateDirectory(directory) {
  const stat = fs.lstatSync(directory);
  requireThat(stat.isDirectory() && fs.realpathSync(directory) === directory &&
    (stat.mode & 0o022) === 0, 'invalid_catalogue_release_directory');
}

function withLock(releases, run, {readOnly = false} = {}) {
  privateDirectory(releases);
  const lock = path.join(releases, 'operation.lock');
  const fd = fs.openSync(lock, fs.constants.O_RDONLY | (readOnly ? 0 : fs.constants.O_CREAT) |
    fs.constants.O_NOFOLLOW | fs.constants.O_NONBLOCK, 0o600);
  try {
    const opened = fs.fstatSync(fd);
    requireThat(opened.isFile(), 'invalid_catalogue_lock');
    // flock on this inherited file description remains held by the parent fd.
    const result = spawnSync('flock', [readOnly ? '--shared' : '--exclusive', '--nonblock', '--conflict-exit-code', '75', '3'],
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

function optionalReceipt(file, contract) {
  if (!fs.lstatSync(file, {throwIfNoEntry:false})) return null;
  const value = JSON.parse(readFile(file, 8192));
  requireThat(value?.contract === contract, 'invalid_catalogue_operation_receipt');
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

function applicationHead(app) {
  const env = Object.fromEntries(Object.entries(process.env).filter(([key]) => !key.startsWith('GIT_')));
  const actual = execFileSync('git', ['-C', app, 'rev-parse', '--show-toplevel', 'HEAD'],
    {encoding:'utf8', env, timeout:5000, stdio:['ignore','pipe','pipe']}).trimEnd();
  const [root, sha, ...extra] = actual.split('\n');
  requireThat(root === app && /^[a-f0-9]{40}$/.test(sha) && extra.length === 0, 'catalogue_application_changed');
  return sha;
}

function checkApplication(app, sha) {
  requireThat(applicationHead(app) === sha, 'catalogue_application_changed');
}

function transact({releases, lockRoot = releases, contract, request}, prepare) {
  return withLock(lockRoot, () => {
    privateDirectory(releases);
    const operations = path.join(releases, 'operations');
    fs.mkdirSync(operations, {recursive:true, mode:0o700});
    privateDirectory(operations);
    const receipt = path.join(operations, request.operation + '.json');
    const pendingFile = path.join(releases, 'pending.json');
    const recorded = optionalReceipt(receipt, contract);
    const pending = optionalReceipt(pendingFile, contract);
    if (recorded) {
      requireThat(isDeepStrictEqual(recorded.request, request), 'catalogue_operation_id_reused');
      if (pending?.request?.operation === request.operation) {
        requireThat(isDeepStrictEqual(recorded, pending) && currentVersion(releases) === recorded.to,
          'catalogue_pending_conflict');
        fs.unlinkSync(pendingFile);
        syncPath(releases);
      }
      return recorded;
    }
    requireThat(!pending || isDeepStrictEqual(pending.request, request), 'catalogue_release_pending');
    // A rollback of an older activation is bound to that operation, even if its bytes are selected again.
    const rollback = request.action === 'rollback';
    const rollbackMark = rollback ? path.join(operations, request.activation + '.rollback.json') : null;
    const priorRollback = rollback ? optionalReceipt(rollbackMark, contract) : null;
    requireThat(!priorRollback || (pending && isDeepStrictEqual(priorRollback, pending)),
      'catalogue_activation_already_rolled_back');
    const activation = rollback ? optionalReceipt(path.join(operations, request.activation + '.json'), contract) : null;
    const next = prepare(pending, activation);
    if (pending) requireThat(isDeepStrictEqual(pending, next), 'catalogue_pending_conflict');
    else writeNew(pendingFile, next);
    if (rollback && !priorRollback) writeNew(rollbackMark, next);
    if (currentVersion(releases) !== next.to) selectVersion(releases, next.to);
    writeNew(receipt, next);
    fs.unlinkSync(pendingFile);
    syncPath(releases);
    return next;
  });
}

module.exports = {VERSION, OPERATION, readFile, requireThat, currentVersion, privateDirectory,
  applicationHead, checkApplication, syncPath, withLock, transact};
