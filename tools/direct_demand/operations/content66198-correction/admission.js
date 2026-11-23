'use strict';

// Read-only admission for this explicitly approved continuation of the existing
// CONTENT carrier. The production release validators are loaded unchanged from
// the exact admitted application commit. This does not manufacture a main or
// workflow_dispatch context for the ordinary consumer resolver.
const fs = require('node:fs');
const path = require('node:path');
const crypto = require('node:crypto');
const {spawnSync} = require('node:child_process');
const REPO = 'pyatkoff/max-search-bot';
const BRANCH = 'audit/content100-bot-publication-20261101';
const WORKFLOW = '.github/workflows/content100-bot-publication-20261101.yml';
const APP = '08f11061fbaac645585ae7215578d0fd4630e31a';
const SOURCE = 6961029169;
const APPROVAL = 7072600922;
const OWNER = 226193297;
const MODULES = Object.freeze({'deployment_release.js':'8f9b427fe05ad3fca501f872d429ea98009ead53',
  'release_slot_close.js':'a81a0ef78d30af8d7c2a55bd99b5c08718043015'});
const sha256 = b => crypto.createHash('sha256').update(b).digest('hex');
function need(ok, code) { if (!ok) throw new Error(code); }
function json(file) { return JSON.parse(fs.readFileSync(file, 'utf8')); }
function gitBlob(bytes) { return crypto.createHash('sha1').update(Buffer.from('blob '+bytes.length+'\0')).update(bytes).digest('hex'); }
function bounded(v, key) { need(v && Number.isInteger(v.total_count) && v.total_count >= 0 && v.total_count < 100 && Array.isArray(v[key]) && v[key].length === v.total_count, 'incomplete_'+key); return v[key]; }
function gh(resource) {
  const r = spawnSync('gh', ['api', resource], {encoding:'utf8', timeout:20000, maxBuffer:2*1024*1024});
  need(r.status === 0, 'github_read_refused'); return JSON.parse(r.stdout);
}
async function main() {
  need(process.argv.length === 4, 'arguments');
  const b = json(process.argv[2]);
  const canonical = path.resolve(process.argv[3]);
  need(b.schema === 1 && ['read','edit','feed'].includes(b.stage) && ['credentials','before_apply'].includes(b.checkpoint) && b.application_sha === APP
    && b.source_comment.id === SOURCE && b.approval_comment.id === APPROVAL, 'binding_scope');
  need(JSON.stringify(Object.entries(b.release_modules).sort()) === JSON.stringify(Object.entries(MODULES).sort()), 'canonical_module_binding');
  for (const [file, blob] of Object.entries(b.release_modules)) {
    need(['deployment_release.js','release_slot_close.js'].includes(file) && /^[a-f0-9]{40}$/.test(blob), 'module_scope');
    need(gitBlob(fs.readFileSync(path.join(canonical, file))) === blob, 'release_module_changed');
  }
  need(Object.keys(b.release_modules).length === 2, 'module_count');
  const R = require(path.join(canonical, 'deployment_release.js'));
  const C = require(path.join(canonical, 'release_slot_close.js'));
  const api = R.makeReadApi(process.env.GITHUB_TOKEN);
  const get = p => api('GET', R.API_ROOT + p);
  const env = process.env, event = json(env.GITHUB_EVENT_PATH);
  need(env.GITHUB_REPOSITORY === REPO && env.GITHUB_ACTOR === 'pyatkoff'
    && env.GITHUB_ACTOR_ID === String(OWNER) && env.GITHUB_EVENT_NAME === 'push'
    && env.GITHUB_RUN_ATTEMPT === '1' && env.GITHUB_REF === 'refs/heads/'+BRANCH
    && env.GITHUB_WORKFLOW_REF === REPO+'/'+WORKFLOW+'@refs/heads/'+BRANCH
    && env.GITHUB_JOB === b.job && /^[a-f0-9]{40}$/.test(env.GITHUB_SHA)
    && /^[1-9][0-9]+$/.test(env.GITHUB_RUN_ID), 'native_context');
  need(event.repository?.full_name === REPO && event.ref === env.GITHUB_REF
    && event.before === b.expected_before && event.after === env.GITHUB_SHA
    && event.created === false && event.deleted === false && event.forced === false, 'native_push');
  const native = await get('/actions/runs/'+env.GITHUB_RUN_ID);
  R.runIdentity(native, {id:env.GITHUB_RUN_ID,attempt:1,head:env.GITHUB_SHA,workflow:WORKFLOW,event:'push',branch:BRANCH,completed:false});
  need(native.status === 'in_progress' && native.actor?.id === OWNER && native.triggering_actor?.id === OWNER, 'native_run');
  const commit = await get('/git/commits/'+env.GITHUB_SHA);
  need(commit.sha === env.GITHUB_SHA && commit.parents.length === 1 && commit.parents[0].sha === b.expected_before, 'carrier_parent');
  need((await get('/git/ref/heads/'+BRANCH)).object.sha === env.GITHUB_SHA, 'carrier_advanced');
  const jobs = bounded(await get('/actions/runs/'+env.GITHUB_RUN_ID+'/attempts/1/jobs?per_page=100'), 'jobs');
  const current = jobs.filter(j => j.name === b.job);
  need(current.length === 1 && current[0].status === 'in_progress' && current[0].run_attempt === 1
    && current[0].head_sha === env.GITHUB_SHA, 'native_job');

  const ci = await get('/actions/runs/'+b.source_ci.run_id);
  R.runIdentity(ci, {id:b.source_ci.run_id,attempt:1,head:b.source_ci.head_sha,workflow:WORKFLOW,event:'push',branch:BRANCH});
  need(ci.actor?.id === OWNER && ci.triggering_actor?.id === OWNER, 'source_ci_owner');
  const ciJobs = bounded(await get('/actions/runs/'+b.source_ci.run_id+'/attempts/1/jobs?per_page=100'), 'jobs');
  need(ciJobs.length === 1 && ciJobs[0].name === 'source-check' && ciJobs[0].conclusion === 'success'
    && ciJobs[0].run_attempt === 1 && ciJobs[0].head_sha === b.source_ci.head_sha
    && ciJobs[0].steps.some(s => s.name === 'Check the exact caption and XML correction sources' && s.conclusion === 'success')
    && !ciJobs[0].steps.some(s => ['failure','cancelled','timed_out'].includes(s.conclusion)), 'source_ci');
  need(Object.keys(b.source_files).length >= 3, 'source_files_missing');
  const tree = spawnSync('git', ['ls-tree','-rz','--name-only','HEAD','--','tools/direct_demand/operations/content66198-correction/'], {encoding:'utf8',timeout:10000});
  need(tree.status === 0 && JSON.stringify(tree.stdout.split('\0').filter(Boolean).sort()) === JSON.stringify(Object.keys(b.source_files).sort()), 'source_inventory_changed');
  for (const [file, hash] of Object.entries(b.source_files)) {
    need(file.startsWith('tools/direct_demand/operations/content66198-correction/')
      && !file.split('/').includes('..') && /^[a-f0-9]{64}$/.test(hash), 'source_file_scope');
    const bytes = fs.readFileSync(file);
    need(sha256(bytes) === hash, 'local_source_changed');
    const source = await get('/contents/'+file+'?ref='+b.source_ci.head_sha);
    need(source.type === 'file' && source.encoding === 'base64' && source.sha === gitBlob(bytes)
      && Buffer.from(source.content, 'base64').equals(bytes), 'ci_source_changed');
  }

  const comments = [];
  for (const target of [b.source_comment, b.approval_comment]) {
    const c = await get('/issues/comments/'+target.id);
    need(c.id === target.id && c.user?.id === OWNER
      && c.issue_url === 'https://api.github.com/repos/'+REPO+'/issues/1566' && c.updated_at === target.updated_at
      && sha256(Buffer.from(c.body, 'utf8')) === target.body_sha256, 'ownership_or_approval_changed');
    comments.push(c);
  }
  need(comments[0].body.includes('TV66198') && comments[0].body.includes('разовое продолжение CONTENT'), 'content_continuation_missing');
  const active = [];
  for (const status of ['in_progress','queued','waiting','requested','pending']) {
    active.push(...bounded(await get('/actions/runs?status='+status+'&per_page=100'), 'workflow_runs'));
  }
  const competing = active.filter(r => String(r.id) !== env.GITHUB_RUN_ID
    && (r.path === WORKFLOW || /^audit\/(?:price660-|feed55-production)/.test(r.head_branch)));
  need(competing.length === 0, 'content_or_feed_run_active');
  if (b.stage !== 'read') {
    const collector = ['in_progress','queued','waiting','requested','pending'].flatMap(status => bounded(
      gh('repos/pyatkoff/poisk-turov-test/actions/runs?status='+status+'&per_page=100'), 'workflow_runs'));
    need(!collector.some(r => r.path === '.github/workflows/collect-anytour-tours.yml'), 'collector_active');
  }

  need((await get('/git/ref/heads/main')).object.sha === APP, 'main_changed');
  const location = await R.readRecord(api);
  const resolved = await R.resolveCurrent(api, {sha:APP}, location);
  need(resolved.ready === true && resolved.perform_actions === true && resolved.kind === 'installed'
    && resolved.repository_sha === APP && resolved.application_sha === APP, 'application_not_admitted');
  await R.requireOps(api, APP);
  const binding = await R.currentBinding(api, APP);
  need(binding && C.recordState(await get('/issues/comments/'+binding.comment_id), binding) === 'released', 'publisher_not_free');
  const latest = await R.readRecord(api);
  need(latest.blob === location.blob && latest.record_digest === location.record_digest, 'release_changed');
  for (let i=0; i<comments.length; i++) {
    const c = await get('/issues/comments/'+comments[i].id);
    need(c.updated_at === comments[i].updated_at && c.body === comments[i].body, 'ownership_changed');
  }
  need((await get('/git/ref/heads/main')).object.sha === APP
    && (await get('/git/ref/heads/'+BRANCH)).object.sha === env.GITHUB_SHA, 'source_changed');
  const proof = {ok:true,stage:b.stage,checkpoint:b.checkpoint,repository_sha:APP,application_sha:APP,
    operation_run_id:Number(env.GITHUB_RUN_ID),operation_head:env.GITHUB_SHA,operation_attempt:1,
    source_ci:b.source_ci,source_comment:b.source_comment,approval_comment:b.approval_comment,
    diagnostics_commit:location.commit,diagnostics_blob:location.blob,record_digest:location.record_digest,
    observed_at_utc:new Date().toISOString(),provider_calls:0,post_write_attempts:0,xml_write_attempts:0};
  fs.writeFileSync('content66198-'+b.stage+'-'+b.checkpoint+'-admission.json', JSON.stringify(proof,null,2)+'\n', {flag:'wx'});
  process.stdout.write('CONTENT66198_'+b.stage.toUpperCase()+'_NATIVE_ADMISSION_OK\n');
}
main().catch(e => { process.stderr.write('CONTENT66198_ADMISSION_REFUSED '+e.message+'\n'); process.exitCode=1; });
