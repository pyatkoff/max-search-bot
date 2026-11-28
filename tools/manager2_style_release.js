#!/usr/bin/env node
'use strict';

const fs = require('node:fs');
const path = require('node:path');
const {createHash} = require('node:crypto');
const {execFileSync} = require('node:child_process');
const {OPERATION, VERSION, readFile, requireThat, currentVersion, privateDirectory,
  checkApplication, syncPath, transact} = require('./catalogue_release_transaction');

const SHA256 = /^[a-f0-9]{64}$/;
const MAX_BYTES = 131072;
const RECEIPT = 'manager2-style-operation-v1';
const digest = bytes => createHash('sha256').update(bytes).digest('hex');

function verifyVersion(releases, version, contractPin) {
  if (version === 'bundled') return;
  requireThat(VERSION.test(version) && version.endsWith('-' + contractPin), 'manager2_style_contract_changed');
  const directory = path.join(releases, version);
  privateDirectory(directory);
  requireThat(digest(readFile(path.join(directory, 'style.css'), MAX_BYTES)) === version.slice(0, 64),
    'manager2_style_digest_mismatch');
}

function stage(releases, version, bytes) {
  const directory = path.join(releases, version);
  const temporary = fs.mkdtempSync(path.join(releases, '.style-'));
  try {
    const file = path.join(temporary, 'style.css');
    fs.writeFileSync(file, bytes, {flag:'wx', mode:0o600});
    syncPath(file);
    syncPath(temporary);
    fs.renameSync(temporary, directory);
    syncPath(releases);
  } finally {
    fs.rmSync(temporary, {recursive:true, force:true});
  }
}

function main(args) {
  const [action, app, application_sha, previous, file, package_sha256, activationOp] = args;
  const activate = action === 'activate';
  requireThat((activate && args.length === 7) || (action === 'rollback' && args.length === 5),
    'usage: activate APP APP_SHA PREVIOUS CSS APPROVED_SHA OPERATION | rollback APP APP_SHA ACTIVATION OPERATION');
  const operation = activate ? activationOp : file;
  requireThat(path.isAbsolute(app) && app !== '/' && fs.realpathSync(app) === app &&
    /^[a-f0-9]{40}$/.test(application_sha) && OPERATION.test(operation), 'invalid_manager2_style_operation');
  requireThat(activate ? (previous === 'bundled' || VERSION.test(previous)) && SHA256.test(package_sha256)
    : OPERATION.test(previous) && previous !== operation, 'invalid_manager2_style_operation');
  const request = activate ? {action, operation, application_sha, previous, package_sha256}
    : {action, operation, application_sha, activation:previous};
  const root = path.join(path.dirname(app), 'max-search-bot-private-logs');
  privateDirectory(root);
  const releases = path.join(root, 'manager2-style-releases');
  fs.mkdirSync(releases, {recursive:true, mode:0o700});
  const lockRoot = path.join(root, 'hotel-catalogue-releases');
  return transact({releases, lockRoot, contract:RECEIPT, request}, (pending, prior) => {
    checkApplication(app, application_sha);
    const contract_sha256 = execFileSync('php', ['-r', 'require $argv[1]; echo Manager2WorkspaceStyle::contractPin($argv[2]);',
      path.join(app, 'services/Manager2WorkspaceStyle.php'), app],
    {encoding:'utf8', timeout:10000, stdio:['ignore','pipe','pipe']});
    requireThat(SHA256.test(contract_sha256), 'manager2_style_contract_unavailable');
    const actual = currentVersion(releases);
    let from, to, state;
    if (activate) {
      from = previous;
      to = package_sha256 + '-' + contract_sha256;
      state = 'activated';
      requireThat(actual === from || (pending && actual === to), 'catalogue_baseline_changed');
      requireThat(from !== to, 'catalogue_version_already_selected');
      verifyVersion(releases, from, contract_sha256);
      const bytes = readFile(file, MAX_BYTES);
      requireThat(bytes.length > 0 && digest(bytes) === package_sha256, 'manager2_style_digest_mismatch');
      // This release contains one self-contained stylesheet. Asset URLs belong
      // to a component package with its own paths, not to this single file.
      const css = new TextDecoder('utf-8', {fatal:true}).decode(bytes);
      requireThat(!/@import\b|\burl\s*\(/i.test(css), 'manager2_style_external_dependency');
      if (!fs.lstatSync(path.join(releases, to), {throwIfNoEntry:false})) {
        requireThat(!pending, 'pending_catalogue_version_missing');
        stage(releases, to, bytes);
      }
      verifyVersion(releases, to, contract_sha256);
    } else {
      requireThat(prior?.request?.operation === request.activation && prior.request.action === 'activate' &&
        prior.state === 'activated' && VERSION.test(prior.to) && prior.contract_sha256 === contract_sha256,
      'invalid_catalogue_activation_receipt');
      from = prior.to;
      to = prior.from;
      state = 'rolled_back';
      requireThat(actual === from || (pending && actual === to), 'catalogue_baseline_changed');
      verifyVersion(releases, to, contract_sha256);
    }
    return {contract:RECEIPT, request, from, to, state, contract_sha256};
  });
}

try {
  process.stdout.write(JSON.stringify(main(process.argv.slice(2))) + '\n');
} catch (error) {
  process.stderr.write((error.code || error.message) + '\n');
  process.exitCode = 1;
}
