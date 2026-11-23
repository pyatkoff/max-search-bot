'use strict';
// One original, read-only MAX13 operation. Canonical release validators are
// loaded unchanged; this does not impersonate a main/dispatch consumer.
const fs = require('node:fs');
const path = require('node:path');
const crypto = require('node:crypto');
const {spawnSync} = require('node:child_process');
const REPO = 'pyatkoff/max-search-bot';
const BRANCH = 'audit/max13-native-20261123';
const WORKFLOW = '.github/workflows/max13-native-read-20261123.yml';
const ROOT = 'tools/direct_demand/operations/max13-amp-check/';
const APP = '898d796f5220bd8694f52206d1525d97e9739d18';
const OWNER = 226193297;
const MODULES = Object.freeze({'deployment_release.js':'ce8ca9b741ee973b533a61b2dc5df3474939d01b',
  'release_slot_close.js':'a81a0ef78d30af8d7c2a55bd99b5c08718043015'});
const sha = b => crypto.createHash('sha256').update(b).digest('hex');
const blob = b => crypto.createHash('sha1').update(Buffer.from('blob '+b.length+'\0')).update(b).digest('hex');
function need(ok, code) { if (!ok) throw new Error(code); }
function read(file) { return JSON.parse(fs.readFileSync(file,'utf8')); }
function bounded(v,key) { need(v && Number.isInteger(v.total_count) && v.total_count >= 0 && v.total_count < 100 && Array.isArray(v[key]) && v[key].length === v.total_count,'incomplete_'+key);return v[key]; }
function command(args) { const r=spawnSync('git',args,{encoding:'utf8',timeout:15000,maxBuffer:2*1024*1024});need(r.status===0,'source_inventory_unavailable');return r.stdout; }
async function main() {
  need(process.argv.length===5,'arguments');
  const b=read(process.argv[2]), canonical=path.resolve(process.argv[3]), checkpoint=process.argv[4];
  need(['credentials','before_read'].includes(checkpoint) && b.schema===1 && b.stage==='read' && b.operation==='commercial-max13-amp-read-20261123-v1'
    && b.application_sha===APP && b.expected_before===APP && b.source_comment.id===6961029169 && b.approval_comment.id===7072422617,'binding_scope');
  need(JSON.stringify(Object.entries(b.release_modules).sort())===JSON.stringify(Object.entries(MODULES).sort()),'canonical_module_binding');
  for(const [file,expected] of Object.entries(MODULES))need(blob(fs.readFileSync(path.join(canonical,file)))===expected,'canonical_module_changed');
  const R=require(path.join(canonical,'deployment_release.js'));
  const C=require(path.join(canonical,'release_slot_close.js'));
  const api=R.makeReadApi(process.env.GITHUB_TOKEN), get=p=>api('GET',R.API_ROOT+p);
  const e=process.env, event=read(e.GITHUB_EVENT_PATH);
  need(e.GITHUB_REPOSITORY===REPO && e.GITHUB_ACTOR==='pyatkoff' && e.GITHUB_ACTOR_ID===String(OWNER)
    && e.GITHUB_EVENT_NAME==='push' && e.GITHUB_RUN_ATTEMPT==='1' && e.GITHUB_REF==='refs/heads/'+BRANCH
    && e.GITHUB_WORKFLOW_REF===REPO+'/'+WORKFLOW+'@refs/heads/'+BRANCH && e.GITHUB_JOB==='native-read'
    && /^[a-f0-9]{40}$/.test(e.GITHUB_SHA) && /^[1-9][0-9]+$/.test(e.GITHUB_RUN_ID),'native_context');
  need(event.repository?.full_name===REPO && event.ref===e.GITHUB_REF && event.before===b.expected_before && event.after===e.GITHUB_SHA
    && event.created===false && event.deleted===false && event.forced===false,'native_push');
  const run=await get('/actions/runs/'+e.GITHUB_RUN_ID);
  R.runIdentity(run,{id:e.GITHUB_RUN_ID,attempt:1,head:e.GITHUB_SHA,workflow:WORKFLOW,event:'push',branch:BRANCH,completed:false});
  need(run.status==='in_progress' && run.actor?.id===OWNER && run.triggering_actor?.id===OWNER,'native_run');
  const commit=await get('/git/commits/'+e.GITHUB_SHA);
  need(commit.sha===e.GITHUB_SHA && commit.parents.length===1 && commit.parents[0].sha===APP,'original_parent');
  need((await get('/git/ref/heads/'+BRANCH)).object.sha===e.GITHUB_SHA,'operation_branch_advanced');
  const jobs=bounded(await get('/actions/runs/'+e.GITHUB_RUN_ID+'/attempts/1/jobs?per_page=100'),'jobs');
  need(jobs.length===2,'job_inventory');
  const current=jobs.filter(j=>j.name==='native-read'), ci=jobs.filter(j=>j.name==='source-check');
  need(current.length===1 && current[0].status==='in_progress' && current[0].run_attempt===1 && current[0].head_sha===e.GITHUB_SHA,'native_job');
  need(ci.length===1 && ci[0].status==='completed' && ci[0].conclusion==='success' && ci[0].run_attempt===1 && ci[0].head_sha===e.GITHUB_SHA
    && ci[0].steps.some(s=>s.name==='Check exact MAX13 read-only sources' && s.conclusion==='success')
    && !ci[0].steps.some(s=>['failure','cancelled','timed_out','skipped'].includes(s.conclusion)),'same_head_source_ci');
  const changed=command(['diff','--name-only',APP,e.GITHUB_SHA]).trim().split('\n').filter(Boolean).sort();
  const expected=[...Object.keys(b.source_files),ROOT+'binding.json',WORKFLOW].sort();
  need(JSON.stringify(changed)===JSON.stringify(expected),'changed_source_scope');
  const inventory=command(['ls-tree','-rz','--name-only','HEAD','--',ROOT]).split('\0').filter(Boolean).sort();
  need(JSON.stringify(inventory)===JSON.stringify([...Object.keys(b.source_files),ROOT+'binding.json'].sort()),'source_inventory');
  for(const [file,hash] of Object.entries(b.source_files)) {
    need(file.startsWith(ROOT) && !file.split('/').includes('..') && /^[a-f0-9]{64}$/.test(hash),'source_file_scope');
    need(sha(fs.readFileSync(file))===hash,'source_file_changed');
  }
  need(sha(fs.readFileSync(WORKFLOW))===b.workflow_sha256,'workflow_changed');
  const comments=[];
  for(const target of [b.source_comment,b.approval_comment]) {
    const c=await get('/issues/comments/'+target.id);
    need(c.id===target.id && c.user?.id===OWNER && c.issue_url==='https://api.github.com/repos/'+REPO+'/issues/1566'
      && c.updated_at===target.updated_at && sha(Buffer.from(c.body,'utf8'))===target.body_sha256,'ownership_or_approval_changed');comments.push(c);
  }
  need(comments[0].body.includes('MAX13_AMP_READ') && comments[0].body.includes('разовое продолжение CONTENT')
    && comments[0].body.includes('2335,2603,2602,11751,11775,64918,11791,1703,2721,33531,11847,17608,236'),'exact_continuation_missing');
  const active=[];
  for(const status of ['in_progress','queued','waiting','requested','pending'])active.push(...bounded(await get('/actions/runs?status='+status+'&per_page=100'),'workflow_runs'));
  need(!active.some(r=>String(r.id)!==e.GITHUB_RUN_ID && (r.path===WORKFLOW || r.path==='.github/workflows/content100-bot-publication-20261101.yml'
    || /^audit\/(?:price660-|feed55-production)/.test(r.head_branch))),'competing_content_run');
  need((await get('/git/ref/heads/main')).object.sha===APP,'main_changed');
  const location=await R.readRecord(api), resolved=await R.resolveCurrent(api,{sha:APP},location);
  need(resolved.ready===true && resolved.perform_actions===true && resolved.kind==='installed'
    && resolved.repository_sha===APP && resolved.application_sha===APP,'application_not_admitted');
  await R.requireOps(api,APP);
  const release=await R.currentBinding(api,APP);
  need(release && C.recordState(await get('/issues/comments/'+release.comment_id),release)==='released','publisher_not_free');
  const latest=await R.readRecord(api);
  need(latest.blob===location.blob && latest.record_digest===location.record_digest,'release_changed');
  for(const previous of comments){const c=await get('/issues/comments/'+previous.id);need(c.updated_at===previous.updated_at && c.body===previous.body,'ownership_changed');}
  need((await get('/git/ref/heads/main')).object.sha===APP && (await get('/git/ref/heads/'+BRANCH)).object.sha===e.GITHUB_SHA,'source_changed');
  const proof={ok:true,stage:'read',checkpoint,operation:b.operation,repository_sha:APP,application_sha:APP,
    operation_run_id:Number(e.GITHUB_RUN_ID),operation_attempt:1,operation_head:e.GITHUB_SHA,
    source_ci_job_id:ci[0].id,source_comment:b.source_comment,approval_comment:b.approval_comment,
    diagnostics_commit:location.commit,diagnostics_blob:location.blob,record_digest:location.record_digest,
    observed_at_utc:new Date().toISOString(),provider_calls:0,post_write_attempts:0,xml_write_attempts:0};
  fs.writeFileSync('max13-read/'+checkpoint+'-admission.json',JSON.stringify(proof,null,2)+'\n',{flag:'wx'});
  process.stdout.write('MAX13_'+checkpoint.toUpperCase()+'_ADMISSION_OK\n');
}
main().catch(e=>{process.stderr.write('MAX13_ADMISSION_REFUSED '+e.message+'\n');process.exitCode=1;});
