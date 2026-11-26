#!/usr/bin/env node
'use strict';

const fs = require('node:fs');
const {execFileSync} = require('node:child_process');
const {hotelCatalogueAddition, telegramPublicObserverOnly, parseRawDiff} = require('./ci_change_scope');
const release = require('./deployment_release');

const FULL = 'full';
const HOTEL = 'hotel-catalogue-data';
const TELEGRAM_OBSERVER = 'telegram-public-observer';
const MAIN = 'refs/heads/main';

async function selectDeployScope({env, event, git, acceptedBaseline}) {
  const before = event?.before;
  const after = event?.after;
  if (env.GITHUB_REPOSITORY !== release.REPOSITORY || env.GITHUB_EVENT_NAME !== 'push'
      || env.GITHUB_WORKFLOW_REF !== release.REPOSITORY + '/' + release.DEPLOY + '@' + MAIN
      || env.GITHUB_JOB !== 'verify-syntax' || env.GITHUB_RUN_ATTEMPT !== '1'
      || env.GITHUB_REF !== MAIN || event?.ref !== MAIN || event.repository?.full_name !== release.REPOSITORY
      || event.forced !== false || event.created !== false || event.deleted !== false
      || !release.sha(before) || /^0+$/.test(before) || !release.sha(after)
      || before === after || after !== env.GITHUB_SHA) return FULL;

  try {
    if (git(['rev-parse', 'HEAD']).trim() !== after || git(['rev-parse', after + '^1']).trim() !== before) return FULL;
    const changes = parseRawDiff(git(['diff', '--raw', '-z', '--no-renames', '--no-abbrev', before, after]));
    let profile;
    if (telegramPublicObserverOnly(changes)) {
      profile = TELEGRAM_OBSERVER;
    } else if (hotelCatalogueAddition(changes, (side, path) => git(['show', (side === 'base' ? before : after) + ':' + path]))) {
      profile = HOTEL;
    } else {
      return FULL;
    }
    return await acceptedBaseline(before, after) === true ? profile : FULL;
  } catch (_) {
    return FULL;
  }
}

async function installedBaseline(api, before, after) {
  const main = () => api('GET', release.API_ROOT + '/git/ref/heads/main');
  const location = await release.readRecord(api);
  const previous = location.record.repository_release;
  if (previous?.phase !== 'pending_terminal' || previous.kind !== 'installed'
      || previous.repository_sha !== before || previous.application_sha !== before) return false;
  const result = await release.resolveCurrent(api, {sha:before}, location);
  if (!result.ready || result.repository_sha !== before || result.application_sha !== before) return false;
  await release.requireOps(api, before);
  const current = await release.readRecord(api);
  return current.blob === location.blob && current.record_digest === location.record_digest
    && (await main())?.object?.sha === after;
}

async function main() {
  let event;
  try { event = JSON.parse(fs.readFileSync(process.env.GITHUB_EVENT_PATH, 'utf8')); } catch (_) {}
  const git = args => execFileSync('git', args, {
    encoding:'utf8', maxBuffer:16 * 1024 * 1024, stdio:['ignore','pipe','pipe'],
  });
  const scope = await selectDeployScope({env:process.env,event,git,
    acceptedBaseline:(before, after) => installedBaseline(release.makeReadApi(process.env.GITHUB_TOKEN), before, after)});
  fs.appendFileSync(process.env.GITHUB_OUTPUT, 'profile=' + scope + '\n');
  process.stdout.write('production_verification_profile=' + scope + '\n');
}

module.exports = { selectDeployScope, installedBaseline };
if (require.main === module) main().catch(error => {
  process.stderr.write(String(error.message) + '\n');
  process.exitCode = 1;
});
