#!/usr/bin/env node
'use strict';

const fs = require('node:fs');
const { execFileSync } = require('node:child_process');

const OAUTH_PATHS = new Set([
  'manager/ai-oauth.php',
  'services/ManagerPreparationOAuth.php',
  'tests/run_manager_preparation_oauth_regression.php',
  'tests/visual/fixtures/oauth-consent.js',
  'tests/visual/manager-ai-draft.spec.js',
  'docs/MANAGER_PREPARED_REPLIES.md',
]);
const FIXTURE = 'tests/visual/fixtures/oauth-consent.js';
const DOCUMENT = 'docs/MANAGER_PREPARED_REPLIES.md';
// Existing component owners only. Shared auth, shell, callbacks, fixtures,
// migrations and CI files deliberately cannot select this browser scope.
const TOUR_SELECTION_PATHS = new Set([
  'manager/assets/workspace-v2-tour-selection.js',
  'manager/assets/workspace-v2-tour-selection.css',
  'manager/tour-selections-api.php',
  'manager/tour-selection-photo.php',
  'selection/index.php', 'selection/photo.php', 'selection/view.php', 'selection/selection.css',
  'services/ManagerTourSelectionService.php',
  'services/ManagerTourSelectionPublicationDraft.php',
  'services/ManagerTourSelectionDeliveryService.php',
  'services/ManagerTourSelectionShareService.php',
  'services/TourSelectionPhotoStore.php',
  'tests/run_manager_tour_selection_regression.php',
  'tests/run_manager_tour_delivery_regression.php',
  'tests/run_manager_tour_share_regression.php',
  'tests/support/manager-tour-selection.php',
  'tests/visual/manager-tour-selection.spec.js',
  'docs/MANAGER_TOUR_SELECTIONS.md',
]);
const TOUR_DOCUMENT = 'docs/MANAGER_TOUR_SELECTIONS.md';
const TOUR_CI_PROOF_PATHS = new Set([
  'tools/ci_change_scope.js', 'tools/verify_tour_selection_browser_report.js',
  '.github/workflows/ci-change-scope.yml', '.github/workflows/workspace-v2-visual-qa.yml',
]);
const full = reason => ({ mode: 'full', reason });
const regular = mode => mode === '100644' || mode === '100755';

// Optional modes are supplied by the CLI's raw diff, never inferred from disk.
function classifyChanges(changes) {
  if (!Array.isArray(changes) || changes.length === 0) return full('empty_changes');
  const tour = changes.every(change => change && TOUR_SELECTION_PATHS.has(change.path));
  const paths = tour ? TOUR_SELECTION_PATHS : OAUTH_PATHS;
  let hasCode = false;
  for (const change of changes) {
    if (!change || !paths.has(change.path)) return full('outside_reviewed_allowlist');
    if (change.previousPath !== undefined) return full('rename_or_copy');
    if (change.status !== 'M' && !(change.status === 'A' && !tour && change.path === FIXTURE)) {
      return full('unsupported_change_status');
    }
    const hasModes = change.oldMode !== undefined || change.newMode !== undefined;
    if (hasModes) {
      if (!regular(change.newMode)) return full('non_regular_file');
      if (change.status === 'M' && change.oldMode !== change.newMode) return full('file_mode_change');
      if (change.status === 'A' && change.oldMode !== '000000') return full('invalid_addition_mode');
    }
    if (change.path !== (tour ? TOUR_DOCUMENT : DOCUMENT)) hasCode = true;
  }
  return hasCode ? { mode: tour ? 'tour-selection' : 'oauth', reason: tour ? 'bounded_tour_selection_changes' : 'bounded_oauth_changes' } : full('documentation_only');
}

function parseRawDiff(raw) {
  if (raw === '') return [];
  if (!raw.endsWith('\0')) throw new Error('unterminated_diff');
  const fields = raw.slice(0, -1).split('\0');
  const changes = [];
  for (let i = 0; i < fields.length;) {
    const header = /^:(\d{6}) (\d{6}) [a-f0-9]{40,64} [a-f0-9]{40,64} ([A-Z](?:\d+)?)$/.exec(fields[i++]);
    if (!header || i >= fields.length || fields[i] === '') throw new Error('invalid_raw_diff');
    const change = { oldMode: header[1], newMode: header[2], status: header[3], path: fields[i++] };
    if (/^[RC]/.test(change.status)) {
      if (i >= fields.length || fields[i] === '') throw new Error('invalid_rename_diff');
      change.previousPath = change.path;
      change.path = fields[i++];
    }
    changes.push(change);
  }
  return changes;
}

// The shared AI spec may only append or edit its isolated native-consent loop.
function oauthSpecOnlyChange(baseSource, headSource) {
  function split(source) {
    if (typeof source !== 'string') return null;
    const importLine = "const {startOAuthFixture}=require('./fixtures/oauth-consent');";
    const lines = source.split('\n');
    if (lines.filter(line => line === importLine).length > 1) return null;
    source = lines.filter(line => line !== importLine).join('\n');
    const markers = [...source.matchAll(/^for\(const choice of /gm)];
    if (markers.length === 0) return { prefix: source.trimEnd(), hasOAuth: false };
    if (markers.length !== 1) return null;
    const offset = markers[0].index;
    const block = source.slice(offset).trimEnd();
    const header = block.split('\n')[0];
    if (!header.includes("])test('OAuth native '") || !block.endsWith('});') ||
        (block.match(/\btest\s*\(/g) || []).length !== 1 || /\btest\s*\.\s*(?:skip|fixme|only)\b/.test(block)) return null;
    return { prefix: source.slice(0, offset).trimEnd(), hasOAuth: true };
  }
  const base = split(baseSource), head = split(headSource);
  return !!(base && head && head.hasOAuth && base.prefix === head.prefix);
}

function selectScope(env = process.env) {
  if (env.GITHUB_EVENT_NAME !== 'pull_request') return full('not_pull_request');
  if (!env.GITHUB_EVENT_PATH) return full('missing_event_path');
  let event;
  try { event = JSON.parse(fs.readFileSync(env.GITHUB_EVENT_PATH, 'utf8')); }
  catch (_) { return full('invalid_event'); }
  const base = event?.pull_request?.base?.sha;
  const head = event?.pull_request?.head?.sha;
  if (typeof base !== 'string' || typeof head !== 'string' ||
      !/^[a-f0-9]{40}$/i.test(base) || !/^[a-f0-9]{40}$/i.test(head)) return full('invalid_commit_sha');
  try {
    const git = args => execFileSync('git', args, { encoding: 'utf8', maxBuffer: 16 * 1024 * 1024, stdio: ['ignore', 'pipe', 'pipe'] });
    for (const sha of [base, head]) {
      if (git(['rev-parse', '--verify', `${sha}^{commit}`]).trim().toLowerCase() !== sha.toLowerCase()) {
        return full('invalid_commit_ref');
      }
    }
    const changes = parseRawDiff(git(['diff', '--raw', '-z', '--no-renames', '--no-abbrev', base, head]));
    const result = classifyChanges(changes);
    const browserSpec = 'tests/visual/manager-ai-draft.spec.js';
    if (result.mode === 'oauth' && changes.some(change => change.path === browserSpec) &&
        !oauthSpecOnlyChange(git(['show', `${base}:${browserSpec}`]), git(['show', `${head}:${browserSpec}`]))) {
      return full('shared_browser_spec_changed');
    }
    // CI-rule edits retain full coverage AND exercise the new scoped runner.
    // Ordinary full changes do not pay for this additional commissioning proof.
    return { ...result, proveTourSelection: result.mode === 'full' && changes.some(change => TOUR_CI_PROOF_PATHS.has(change.path)) };
  } catch (_) { return full('diff_unavailable'); }
}

if (require.main === module) {
  const result = selectScope();
  if (process.env.GITHUB_OUTPUT) fs.appendFileSync(process.env.GITHUB_OUTPUT, `mode=${result.mode}\ntour-proof=${result.proveTourSelection === true}\n`);
  process.stdout.write(`${JSON.stringify(result)}\n`);
}

module.exports = { TOUR_SELECTION_PATHS, classifyChanges, parseRawDiff, oauthSpecOnlyChange, selectScope };
