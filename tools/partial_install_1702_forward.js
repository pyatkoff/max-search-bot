'use strict';

const {isDeepStrictEqual: equal} = require('node:util');
const {census, complete, jobs, nativeLog, proveCheckedSuccessor, verifyRun} = require('./partial_install_1662_forward');
const {BASELINE_DIGEST, DIAGNOSTICS, baselineBytes, historical} = require('./partial_install_1702_evidence');

const MAIN = '32155b9eb2c8606b61554951bfdc988d70bbcb39';
const HEAD = '9b0a8a5a4dc252b977f95bee2b890287cdbbec1f';
const INSTALL_JOB = 138531045324;
// The observed independent preflight 45419815585/1 timed out without an HTTP response.
// A successful independently authenticated run, exact job and native log digest are required.
const PUBLIC_RECEIPT = null;
const WORKFLOW = 'pr1702-partial-install-preflight.yml';
const PROBE_BLOB = '5a471e84bfa5af39b2f02e4d8f25bc073770017d';
const BRANCH = 'audit/pr1702-partial-install-preflight-20261124';
const FILES = {
  '.github/workflows/deploy.yml':'modified',
  'resources/max2-common-v2/release-manifest.json':'modified',
  'tests/run_deployment_release_regression.js':'modified',
  'tests/run_partial_install_1662_forward_sync.py':'modified',
  'tests/run_partial_install_1702_forward_regression.js':'added',
  'tools/deployment_release.js':'modified',
  'tools/partial_install_1662_forward.js':'modified',
  'tools/partial_install_1702_evidence.js':'added',
  'tools/partial_install_1702_forward.js':'added',
  'tools/release_priority.js':'modified'
};

function allowedLog(id) {
  return id === INSTALL_JOB || (PUBLIC_RECEIPT !== null && id === PUBLIC_RECEIPT.job);
}
function requireProof(ok, reason) {
  if (!ok) throw new Error('partial-install-1702: ' + reason);
}

async function publicEvidence(api) {
  const p = PUBLIC_RECEIPT;
  requireProof(p !== null, 'positive public containment receipt pending');
  const [run, commit, relation, all, records, artifacts, rawLog] = await Promise.all([
    api.get('/actions/runs/' + p.run), api.get('/git/commits/' + p.source),
    api.get('/compare/' + MAIN + '...' + p.source + '?per_page=1'), census(api,p.source),
    jobs(api,p.run,p.source), api.get('/actions/runs/' + p.run + '/artifacts?per_page=100'),
    api.jobLog(p.job)
  ]);
  verifyRun(run,p.run,WORKFLOW,p.workflow,p.source,BRANCH,'push','success');
  requireProof(commit.sha === p.source && equal(commit.parents.map(row => row.sha), [MAIN]) &&
    relation.status === 'ahead' && relation.merge_base_commit?.sha === MAIN && relation.files.length === 1 &&
    relation.files[0].filename === '.github/workflows/' + WORKFLOW &&
    relation.files[0].status === 'added' && relation.files[0].sha === PROBE_BLOB &&
    equal(all.filter(row => row.path === '.github/workflows/' + WORKFLOW).map(row => row.id), [p.run]) &&
    complete(artifacts,'artifacts').length === 0, 'independent preflight source changed');
  const steps = ['Set up job','Verify exact source, original job history and active publisher',
    'Check the original public containment header without reading content','Acknowledge preflight scope','Complete job'];
  requireProof(records.length === 1 && records[0].id === p.job && records[0].name === 'preflight' &&
    records[0].conclusion === 'success' && equal(records[0].steps.map(row => row.name), steps) &&
    records[0].steps.every(row => row.status === 'completed' && row.conclusion === 'success'),
    'independent preflight incomplete');
  const values = nativeLog(rawLog,p.log).filter(line => line.startsWith('original_guard_current_http='));
  requireProof(values.length === 1 && /^original_guard_current_http=(403|404)$/.test(values[0]),
    'independent public containment unconfirmed');
  return Number(values[0].split('=')[1]);
}

async function provePartialInstall1702Forward(options) {
  requireProof(PUBLIC_RECEIPT !== null, 'positive public containment receipt pending');
  const spec = {
    previousPr:1702, main:MAIN, head:HEAD,
    binding:{comment_id:6818332618, head_sha:HEAD, owner_id:226193297, updated_at:'2026-11-24T14:35:09Z'},
    pr:1704, branch:'fix/1702-checked-forward-install', files:FILES, manifestBlob:'a43927da06c6ca3fa95d91633a35dd0e464f1848',
    diagnostics:DIAGNOSTICS, baselineBytes, history:async read => {
      const [, status] = await Promise.all([historical(read),publicEvidence(read)]);
      return {previous_pr:1702, previous_sha:MAIN, previous_run_id:'45416010115',
        previous_installation:'partial_after_checkout', previous_install_job_id:INSTALL_JOB,
        previous_application_sha:'5e0eeefd0a673fe492329a21f980351f80c2eccb',
        previous_accepted_record_sha256:BASELINE_DIGEST, previous_public_check_run:PUBLIC_RECEIPT.run,
        previous_observed_http:status};
    }
  };
  return proveCheckedSuccessor(options,spec);
}
module.exports = {provePartialInstall1702Forward, allowedLog, publicEvidence};
