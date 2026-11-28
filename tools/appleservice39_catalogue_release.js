#!/usr/bin/env node
'use strict';

const fs = require('node:fs');
const path = require('node:path');
const {createHash} = require('node:crypto');
const {spawnSync} = require('node:child_process');
const {OPERATION, VERSION, readFile, requireThat, currentVersion, privateDirectory,
  checkApplication, syncPath, transact} = require('./catalogue_release_transaction');

const SHA256 = /^[a-f0-9]{64}$/;
const RECEIPT = 'appleservice39-catalogue-operation-v1';
const digest = bytes => createHash('sha256').update(bytes).digest('hex');

function versionBytes(releases, version, readerSha, bundled) {
  if (version === 'bundled') return bundled;
  requireThat(VERSION.test(version) && version.endsWith('-' + readerSha), 'catalogue_reader_changed');
  const directory = path.join(releases, version);
  privateDirectory(directory);
  const bytes = readFile(path.join(directory, 'catalogue.json'), 65536);
  requireThat(digest(bytes) === version.split('-')[0], 'catalogue_digest_mismatch');
  return bytes;
}

function main(args) {
  const [action, app, application_sha, previous, file, package_sha256, activationOp] = args;
  const activate = action === 'activate';
  requireThat((activate && args.length === 7) || (action === 'rollback' && args.length === 5),
    'usage: activate APP APP_SHA PREVIOUS PACKAGE APPROVED_SHA OPERATION | rollback APP APP_SHA ACTIVATION OPERATION');
  const operation = activate ? activationOp : file;
  requireThat(path.isAbsolute(app) && app !== '/' && fs.realpathSync(app) === app &&
    /^[a-f0-9]{40}$/.test(application_sha) && OPERATION.test(operation), 'invalid_catalogue_operation');
  requireThat(activate ? (previous === 'bundled' || VERSION.test(previous)) && SHA256.test(package_sha256)
    : OPERATION.test(previous) && previous !== operation, 'invalid_catalogue_operation');
  const request = activate ? {action, operation, application_sha, previous, package_sha256}
    : {action, operation, application_sha, activation:previous};
  const root = path.join(path.dirname(app), 'max-search-bot-private-logs');
  privateDirectory(root);
  const releases = path.join(root, 'appleservice39-catalogue-releases');
  fs.mkdirSync(releases, {recursive:true, mode:0o700});
  const lockRoot = path.join(root, 'hotel-catalogue-releases');
  return transact({releases, lockRoot, contract:RECEIPT, request}, (pending, prior) => {
    checkApplication(app, application_sha);
    const reader = path.join(app, 'services/AppleService39PilotCatalog.php');
    const reader_sha256 = digest(readFile(reader, 131072));
    const bundled = readFile(path.join(app, 'resources/appleservice39-pilot-entries.json'), 65536);
    const bundled_sha256 = digest(bundled);
    const actual = currentVersion(releases);
    let from, to, state;
    if (activate) {
      from = previous;
      to = package_sha256 + '-' + reader_sha256;
      state = 'activated';
      requireThat(actual === from || (pending && actual === to), 'catalogue_baseline_changed');
      requireThat(from !== to, 'catalogue_version_already_selected');
      versionBytes(releases, from, reader_sha256, bundled);
      const candidate = readFile(file, 65536);
      requireThat(digest(candidate) === package_sha256, 'catalogue_digest_mismatch');
      if (!pending) {
        const result = spawnSync('php', ['-r', 'require $argv[1]; try {' +
          'AppleService39PilotCatalog::validateQuestionRelease(stream_get_contents(STDIN));' +
          '} catch (Throwable $error) { exit(1); }', reader], {input:candidate, timeout:10000});
        requireThat(result.status === 0, 'appleservice39_catalogue_validation_failed');
      }
      const directory = path.join(releases, to);
      if (!fs.lstatSync(directory, {throwIfNoEntry:false})) {
        requireThat(!pending, 'pending_catalogue_version_missing');
        const temporary = fs.mkdtempSync(path.join(releases, '.package-'));
        try {
          const data = path.join(temporary, 'catalogue.json');
          fs.writeFileSync(data, candidate, {flag:'wx', mode:0o600});
          syncPath(data);
          syncPath(temporary);
          fs.renameSync(temporary, directory);
          syncPath(releases);
        } finally {
          fs.rmSync(temporary, {recursive:true, force:true});
        }
      }
      versionBytes(releases, to, reader_sha256, bundled);
    } else {
      requireThat(prior?.request?.operation === request.activation && prior.request.action === 'activate' &&
        prior.state === 'activated' && VERSION.test(prior.to) && prior.reader_sha256 === reader_sha256 &&
        prior.bundled_sha256 === bundled_sha256, 'invalid_catalogue_activation_receipt');
      from = prior.to;
      to = prior.from;
      state = 'rolled_back';
      requireThat(actual === from || (pending && actual === to), 'catalogue_baseline_changed');
      versionBytes(releases, to, reader_sha256, bundled);
    }
    return {contract:RECEIPT, request, from, to, state, reader_sha256, bundled_sha256};
  });
}

try {
  process.stdout.write(JSON.stringify(main(process.argv.slice(2))) + '\n');
} catch (error) {
  process.stderr.write((error.code || error.message) + '\n');
  process.exitCode = 1;
}
