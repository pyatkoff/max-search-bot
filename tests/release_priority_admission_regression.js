'use strict';

const assert = require('node:assert/strict');
const { spawnSync } = require('node:child_process');
const path = require('node:path');
const R = require('../tools/deployment_release');
const C = require('../tools/release_slot_close');
const { fixture, receipt } = require('./run_deployment_release_regression');
const { runGuard, verifyPreviousRelease } = require('../tools/release_priority');

const crypto=require('node:crypto');
const copy=R.clone,T='2026-11-06T06:00:00Z',H=x=>crypto.createHash('sha1').update(x).digest('hex');
const equal=(a,b,message)=>assert.deepEqual(a,b,message);
let stepSerial=1,jobSerial=1;
function step(name,conclusion='success'){
  return {number:stepSerial++,name,status:'completed',conclusion,started_at:T,completed_at:T};
}
function job(name,run,steps=[],conclusion='success'){
  return {id:jobSerial++,name,run_id:run.id,run_attempt:run.run_attempt,head_sha:run.head_sha,
    status:'completed',conclusion,steps};
}
const INSTALL_EXECUTION=['Build exact deployment bundle','Sync canonical production checkout',
  'Apply database migrations and fence the old hotel contour','Reconcile canonical MAX webhook ownership',
  'Ensure Metrika offline uploader cron','Apply profile and retain exact diagnostic snapshot'];
function fullJobs(run,record){
  const rows=C.INSTALL_JOBS.map(name=>job(name,run,name==='deploy'?INSTALL_EXECUTION.map(n=>step(n)):[step('Required source checks')]));
  rows.push(job('verify',run,[...['check','download','status'].map(k=>step(C.VERIFY_STEPS[k])),
    step(R.PENDING_BINDING_STEP+R.digest(record)),step(C.VERIFY_STEPS.cleanup)]));return rows;
}
function priorWaveFixture(){
  const p=R.PRIOR_WAVE_RECOVERY,q=p.previous;
  const pending={"bundle":"success","diagnostics":"success","diagnostics_attempt":1,"install_attempt":1,"install_checks":"success","key":"success","max_webhook":"success","metrika_cron":"success","migration":"success","provenance":"success","reported_at":"2026-11-17T12:17:04Z","repository_release":{"application_sha":p.sha,"baseline":null,"baseline_eligible":false,"closure":null,"content":{"candidate_sha":p.sha,"classification":"unconfirmed","installed_sha":"6d3da4fb7602de9e6dcd7ba3af06f70749606f1f","policy":"anytour-development-content-v1","release_path":"full_required","runtime_fingerprint":null},"intent_scan":{"checked_commits":9,"coverage":"registered_markers_and_reserved_families","matched_commits":[],"status":"no_registered_intent"},"kind":"installed","observation":null,"observation_producer":null,"phase":"pending_closure","release":{"event":"push","run_attempt":1,"run_id":p.deploy},"repository_sha":p.sha,"schema":1,"stages":Object.fromEntries(R.INSTALL_STAGES.map(s=>[s,{execution:'performed',result:'success'}]))},"run_id":p.deploy,"schema":2,"sha":p.sha,"smoke":"success","smoke_attempt":1,"sync":"success"};
  equal(R.gitBlob(Buffer.from(R.wire(pending))),p.blob,'fixed prior-wave pending blob is reproduced');
  equal(R.digest(pending),p.record_digest,'fixed prior-wave pending digest is reproduced');
  const f=fixture(p.sha,pending),original=f.head,originalCommit=f.commits.get(original);
  f.records.set(p.commit,f.records.get(original));
  f.commits.set(p.commit,{...copy(originalCommit),sha:p.commit});f.head=p.commit;
  const wave=f.start(p.sha,Number(p.deploy),Number(p.run_id),p.pr);f.runs.clear();f.jobs.clear();
  for(const r of [...wave.wave,wave.own])f.runs.set(r.id,r);
  f.jobs.set(wave.parent.id,fullJobs(wave.parent,pending));
  const activation=wave.wave.find(r=>r.path.endsWith(C.ACTIVATION.file));
  f.jobs.set(activation.id,[job('activate',activation,[],'skipped')]);
  const failed=wave.own;failed.status='completed';failed.conclusion='failure';
  const failure=job('release-slot',failed,[step('Prepare the exact release proof before terminal publication','failure'),
    step(R.TERMINAL_BINDING_STEP,'skipped'),step(R.TERMINAL_STEP,'skipped')],'failure');failure.id=p.job_id;
  f.jobs.set(failed.id,[job('configure',failed,[],'skipped'),failure,job('release-slot-recovery',failed,[],'skipped')]);
  const marker=x=>({comment_id:x.comment_id,head_sha:x.head_sha,owner_id:x.owner_id,updated_at:x.updated_at});
  const pull=x=>({number:x.pr,state:'closed',merged:true,merge_commit_sha:x.sha,
    head:{sha:x.head_sha,repo:{full_name:R.REPOSITORY}},base:{ref:'main',repo:{full_name:R.REPOSITORY}},
    body:'<!-- release-slot-close:v1 '+JSON.stringify(marker(x))+' -->'});
  const comment=x=>({id:x.comment_id,body:'PR'+x.pr+': выпуск занят',user:{id:x.owner_id},updated_at:x.updated_at,
    author_association:'OWNER',issue_url:'https://api.github.com'+R.API_ROOT+'/issues/1122'});
  f.pr=pull(p);f.comment=comment(p);const oldPr=pull(q),oldComment=comment(q);
  const previous={...copy(wave.parent),id:Number(q.deploy),head_sha:q.sha,head_commit:{id:q.sha,message:'Original previous source'},
    created_at:'2026-11-06T05:00:00Z',updated_at:'2026-11-06T05:30:00Z',status:'completed',conclusion:'failure'};
  f.runs.set(previous.id,previous);
  const denied=['Prepare canonical SSH private key','Verify installed production checks','Download exact installed diagnostic snapshot',
    'Publish verification stage status','Remove acknowledged diagnostic snapshot'];
  const previousVerify=job('verify',previous,[step('Admit only unfinished verification without installation','failure'),
    step('Require complete installed verification','failure'),...denied.map(n=>step(n,'skipped'))],'failure');
  previousVerify.id=q.verify_job_id;
  f.jobs.set(previous.id,[...C.INSTALL_JOBS.map(n=>job(n,previous,n==='deploy'?INSTALL_EXECUTION.map(n=>step(n)):[])),previousVerify]);
  const oldTag='deploy-proof:v1:'+q.deploy+':1';
  const childNames={'max-tls-preflight.yml':['strict-tls-preflight'],'max-production-health.yml':['inspect'],
    'telegram-production-health.yml':['inspect'],'publish-conversation-diagnostics.yml':['publish'],'live-session-diagnostics.yml':['publish'],
    'tour-selection-host.yml':['host','independent-editor'],'modeled-conversion-monitor.yml':['monitor']};
  const oldChildren=C.REQUIRED.filter(([file,event])=>event==='workflow_run'&&file!==C.ACTIVATION.file).map(([file],i)=>{
    const child={...copy(wave.wave.find(r=>r.path===R.PREFIX+file)),id:({'max-production-health.yml':44079622025,'telegram-production-health.yml':44079621974,'max-tls-preflight.yml':44079622090,'tour-selection-host.yml':44079622065,'live-session-diagnostics.yml':44079621931,'publish-conversation-diagnostics.yml':44079621993,'modeled-conversion-monitor.yml':44079622033})[file],name:oldTag,display_title:oldTag,
      status:'completed',conclusion:'skipped'};
    f.runs.set(child.id,child);f.jobs.set(child.id,childNames[file].map(n=>job(n,child,[],'skipped')));return child;
  });
  const oldMonitor=oldChildren.find(r=>r.path.endsWith('modeled-conversion-monitor.yml'));
  const oldCloser={...copy(failed),id:44079622034,name:oldTag,display_title:oldTag,conclusion:'skipped'};
  f.runs.set(oldCloser.id,oldCloser);f.jobs.set(oldCloser.id,['configure','release-slot','release-slot-recovery'].map(n=>job(n,oldCloser,[],'skipped')));
  const oldActivationTag='monitor-proof:v1:'+oldMonitor.id+':1';
  const oldActivation={...copy(activation),id:44079634544,name:oldActivationTag,display_title:oldActivationTag,conclusion:'skipped'};
  f.runs.set(oldActivation.id,oldActivation);f.jobs.set(oldActivation.id,[job('activate',oldActivation,[],'skipped')]);
  const source=H('source-checked prior1597 completion'),runId=800000;
  const native={...copy(failed),id:runId,head_sha:source,head_commit:{id:source,message:'Finite metadata continuation'},head_branch:p.branch,
    event:'push',status:'in_progress',conclusion:null,actor:{id:p.owner_id}};
  delete native.display_title;f.runs.set(runId,native);
  const own=job('release-slot-recovery',native,[],null);own.status='in_progress';own.runner_id=345;
  f.jobs.set(runId,[own,job('configure',native,[],'skipped'),job('release-slot',native,[],'skipped')]);
  for(const [i,name,file]of [[1,'Regression tests','regression-tests.yml'],[2,'Retired domain guard','retired-domain-guard.yml']])
    f.runs.set(runId+i,{...copy(native),id:runId+i,name,path:R.PREFIX+file,event:'pull_request',status:'completed',conclusion:'success'});
  f.api.jobLog=async id=>{
    assert.ok([p.job_id,q.verify_job_id].includes(id),'only the two pinned original logs are read');
    return Buffer.from(T+' release_slot=held reason='+(id===p.job_id?'ambiguous_run':'main_changed')+'\n');
  };
  const originalHook=async call=>{
    const u=call.path;
    if(u===R.API_ROOT+'/git/ref/heads/'+p.branch)return {object:{sha:source}};
    if(u===R.API_ROOT+'/actions/workflows/modeled-conversion-value-export.yml/runs?branch='+p.branch+'&event=push&per_page=100'){
      const list=[...f.runs.values()].filter(r=>r.event==='push'&&r.head_branch===p.branch&&r.path===R.CLOSER);
      return {total_count:list.length,workflow_runs:copy(list)};
    }
    if(u===R.API_ROOT+'/compare/'+p.sha+'...'+source+'?per_page=1')return {status:'ahead',merge_base_commit:{sha:p.sha}};
    if(u===R.API_ROOT+'/compare/'+q.sha+'...'+p.sha)return {status:'ahead',merge_base_commit:{sha:q.sha},ahead_by:1,behind_by:0};
    if(u===R.API_ROOT+'/contents/tools/release_slot_close.js?ref='+p.sha)return {sha:p.original_code};
    if(u===R.API_ROOT+'/pulls/'+q.pr)return copy(oldPr);
    if(u===R.API_ROOT+'/issues/comments/'+q.comment_id){if(call.method==='PATCH')oldComment.body=call.body.body;return copy(oldComment);}
    if(u===R.API_ROOT+'/issues/comments/'+p.comment_id){if(call.method==='PATCH')f.comment={...f.comment,body:call.body.body};return copy(f.comment);}
  };
  f.hook=originalHook;
  const env={GITHUB_REPOSITORY:R.REPOSITORY,GITHUB_EVENT_NAME:'push',GITHUB_REF:'refs/heads/'+p.branch,GITHUB_SHA:source,
    GITHUB_WORKFLOW_REF:R.REPOSITORY+'/'+R.CLOSER+'@refs/heads/'+p.branch,GITHUB_JOB:'release-slot-recovery',
    GITHUB_RUN_ID:String(runId),GITHUB_RUN_ATTEMPT:'1'};
  const event={repository:{full_name:R.REPOSITORY},ref:env.GITHUB_REF,before:'0'.repeat(40),after:source,created:true,deleted:false,forced:false};
  return {f,p,q,env,event,source,native,own,failure,previous,previousVerify,oldChildren,oldCloser,oldActivation,activation,
    oldPr,oldComment,originalHook,options:()=>({env,event,api:f.api}),writes:()=>f.calls.filter(c=>c.method==='PATCH'&&c.path.includes('/issues/comments/'))};
}

module.exports = async function verifyPublisherAdmission(check = assert.equal) {
  const repository = 'pyatkoff/max-search-bot';
  const head = 'a'.repeat(40), base = 'b'.repeat(40);
  const acquired = '2026-11-10T10:26:27Z';
  const now = Date.parse('2026-11-10T10:28:00Z');
  const publisher = { comment_id: 648, owner_id: 17, updated_at: acquired };
  const binding = { ...publisher, head_sha: head };
  const slot = value => '<!-- release-slot-close:v1 ' + JSON.stringify(value) + ' -->';
  const reservation = (started_at, patch = {}) => '<!-- anytour-release-priority:v1 ' +
    JSON.stringify({ head, base, started_at, expires_at: '2026-11-10T10:47:19Z', kind: 'product', ...patch }) + ' -->';
  const pr = (number, body = '', patch = {}) => ({ number, body, state: 'open', draft: false,
    author_association: 'OWNER', head: { sha: head, repo: { full_name: repository } },
    base: { ref: 'main', sha: base }, ...patch });
  const record = { id: 648, user: { id: 17 }, author_association: 'OWNER', updated_at: acquired,
    created_at: '2026-10-28T00:00:00Z', body: 'PR1438: выпуск занят',
    issue_url: 'https://api.github.com/repos/' + repository + '/issues/1122' };
  const copy = value => JSON.parse(JSON.stringify(value));
  let target, other, comment, freshTarget, mainReads, calls, previous;
  const acceptedPrevious = async () => {
    const f = fixture(base);
    const proof = await C.closeRelease({ ...f.options(), prepare: true });
    f.bindTerminal(proof.record_digest);
    await C.closeRelease(f.options());
    f.complete();
    f.calls.length = 0;
    return f;
  };
  const reset = () => {
    target = pr(1438, slot(binding)); other = pr(1441, reservation('2026-11-10T10:27:10Z'));
    comment = copy(record); freshTarget = null; mainReads = 0; calls = []; previous = null;
  };
  reset();
  const response = (value, status = 200) => ({ ok: status === 200, status, json: async () => copy(value) });
  const fetchImpl = async (url, options) => {
    calls.push(url);
    check(options.method || 'GET', 'GET', 'publisher continuation performs no mutation');
    check(options.redirect, 'error', 'publisher proof does not follow redirects');
    if (url.endsWith('/git/ref/heads/main')) { mainReads++; return response({ object: { sha: base } }); }
    if (url.includes('/pulls?state=open')) return response([target, other]);
    if (url.endsWith('/issues/comments/648')) return response(comment);
    if (url.endsWith('/pulls/1438')) return response(freshTarget || target);
    if (!previous) previous = await acceptedPrevious();
    return response(await previous.api('GET', url.replace('https://api.github.com', ''), undefined,
      options.headers.Accept === 'application/vnd.github.raw+json'));
  };
  const env = { GITHUB_REPOSITORY: repository, GITHUB_TOKEN: 'synthetic-read-token' };
  const admission = { repository, prNumber: 1438, headSha: head, baseSha: base };
  const run = (patch = {}) => runGuard({ env, admission: { ...admission, publisher: copy(publisher) }, fetchImpl, now, ...patch });
  const rejects = async (action, code, message) => {
    let observed = null;
    try { await action(); } catch (error) { observed = error.code; }
    check(observed, code, message);
  };

  // Reproduce the actual interleaving: admission, acquisition, then a new reservation.
  other.body = '';
  check((await runGuard({ env, admission, fetchImpl, now: Date.parse(acquired) - 8000 })).allowed,
    true, 'ordinary admission succeeds before the publisher is acquired');
  reset();
  check((await runGuard({ env, admission, fetchImpl, now })).allowed,
    false, 'a candidate without pinned acquisition cannot ignore the later reservation');
  reset();
  const continuation = await run();
  check(continuation.allowed, true, 'the same admitted publisher survives a strictly later reservation');
  check(continuation.reason, 'active_publisher', 'continuation names its actual authority');
  check(continuation.ignored_later_reservations, 1, 'the late reservation is reported explicitly');
  check(calls.length > 6 && calls.length < 60, true, 'continuation authenticates previous terminal with bounded read-only requests');
  check(calls.some(url => url.includes('/actions/workflows/deploy.yml/runs?')), true,
    'a different free publisher cannot bypass original deployment evidence');

  for (const started of ['2026-11-10T10:26:26Z', acquired]) {
    reset(); other.body = reservation(started);
    check((await run()).allowed, false, 'earlier or simultaneous reservation is never overridden: ' + started);
  }
  reset(); other.body = reservation('2026-11-10T10:27:10Z', {
    kind: 'incident', incident: 'https://github.com/' + repository + '/issues/1122' });
  check((await run()).allowed, true, 'a later incident retains the existing non-preemption rule for active publishers');

  for (const [description, change, code] of [
    ['missing binding', () => { target.body = ''; }, 'invalid_publisher_binding'],
    ['duplicate binding', () => { target.body += slot(binding); }, 'invalid_publisher_binding'],
    ['malformed binding', () => { target.body = '<!-- release-slot-close:v1 broken -->'; }, 'invalid_publisher_binding'],
    ['unsupported binding', () => { target.body = slot(binding).replace(':v1', ':v2'); }, 'invalid_publisher_binding'],
    ['unknown binding field', () => { target.body = slot({ ...binding, renew: true }); }, 'invalid_publisher_binding'],
    ['foreign binding owner', () => { target.body = slot({ ...binding, owner_id: 18 }); }, 'invalid_publisher_binding'],
    ['changed binding head', () => { target.body = slot({ ...binding, head_sha: 'c'.repeat(40) }); }, 'invalid_publisher_binding'],
    ['changed comment id', () => { comment.id++; }, 'publisher_changed'],
    ['foreign comment owner', () => { comment.user.id++; }, 'publisher_changed'],
    ['untrusted comment author', () => { comment.author_association = 'CONTRIBUTOR'; }, 'publisher_changed'],
    ['missing comment association', () => { delete comment.author_association; }, 'publisher_changed'],
    ['changed comment version', () => { comment.updated_at = '2026-11-10T10:26:28Z'; }, 'publisher_changed'],
    ['foreign issue', () => { comment.issue_url = 'https://api.github.com/repos/other/repo/issues/1122'; }, 'publisher_changed'],
    ['wrong issue', () => { comment.issue_url = 'https://api.github.com/repos/' + repository + '/issues/55'; }, 'publisher_changed'],
    ['terminal publisher', () => { comment.body = 'PR1438: установлено, выпуск свободен'; }, 'publisher_changed'],
    ['another PR owns publisher', () => { comment.body = 'PR1441: выпуск занят'; }, 'publisher_changed'],
    ['untrusted target', () => { target.author_association = 'CONTRIBUTOR'; }, 'invalid_publisher_binding'],
    ['fork target', () => { target.head.repo.full_name = 'outside/fork'; }, 'invalid_publisher_binding'],
    ['draft target', () => { target.draft = true; }, 'invalid_publisher_binding'],
    ['changed target head', () => { target.head.sha = 'c'.repeat(40); }, 'superseded_target'],
    ['changed final target', () => { freshTarget = { ...copy(target), body: slot({ ...binding, owner_id: 18 }) }; }, 'invalid_publisher_binding'],
    ['foreign final target number', () => { freshTarget = { ...copy(target), number: 1441 }; }, 'invalid_publisher_binding'],
    ['missing final target number', () => { freshTarget = copy(target); delete freshTarget.number; }, 'invalid_publisher_binding'],
    ['malformed fresh reservation', () => { freshTarget = { ...copy(target), body: target.body + '<!-- anytour-release-priority:v1 broken -->' }; }, 'invalid_json'],
    ['malformed late reservation', () => { other.body = '<!-- anytour-release-priority:v1 broken -->'; }, 'invalid_json']
  ]) {
    reset(); change(); await rejects(() => run(), code, description + ' refuses without fallback');
  }
  for (const expected of [{ ...publisher, owner_id: 0 }, { ...publisher, extra: true },
    { ...publisher, updated_at: '2026-11-10T10:29:00Z' }, { ...publisher, updated_at: '2026-02-30T10:26:27Z' }, null]) {
    reset(); await rejects(() => run({ admission: { ...admission, publisher: expected } }),
      'invalid_publisher_context', 'invalid pinned acquisition cannot fall back to ordinary admission');
  }
  reset();
  await rejects(() => run({ fetchImpl: async (url, options) => url.endsWith('/issues/comments/648') ?
    response({}, 429) : fetchImpl(url, options) }), 'api_http_429', 'proof rate limit refuses without retry');
  reset();
  await rejects(() => run({ fetchImpl: async (url, options) => url.endsWith('/git/ref/heads/main') && mainReads ?
    response({ object: { sha: 'c'.repeat(40) } }) : fetchImpl(url, options) }),
    'base_changed', 'main changing during publisher proof refuses continuation');
  for (const [description, change] of [
    ['body', () => { comment.body = 'PR1441: выпуск занят'; }],
    ['version', () => { comment.updated_at = '2026-11-10T10:26:28Z'; }],
    ['owner', () => { comment.user.id++; }],
    ['association', () => { comment.author_association = 'CONTRIBUTOR'; }],
    ['issue', () => { comment.issue_url += '/other'; }]
  ]) {
    reset();
    await rejects(() => run({ fetchImpl: async (url, options) => {
      const result = await fetchImpl(url, options);
      if (url.endsWith('/git/ref/heads/main') && mainReads === 2) change();
      return result;
    } }), 'publisher_changed', 'publisher ' + description + ' changing during proof refuses continuation');
  }
  for (const invalidJson of [false, true]) {
    reset(); let recordReads = 0;
    await rejects(() => run({ fetchImpl: async (url, options) => {
      if (url.endsWith('/issues/comments/648') && ++recordReads === 2) return invalidJson ?
        { ok: true, json: async () => { throw Error('private transport failure'); } } : response({}, 429);
      return fetchImpl(url, options);
    } }), invalidJson ? 'invalid_api_response' : 'api_http_429', 'failed final proof read refuses without fallback');
    check(recordReads, 2, 'failed final proof read is not retried');
  }
  reset();
  const ciEnv = { ...env, GITHUB_EVENT_NAME: 'pull_request', GITHUB_EVENT_PATH: 'event.json' };
  check((await runGuard({ env: ciEnv, readEvent: () => JSON.stringify({ pull_request: target }), fetchImpl, now })).allowed,
    false, 'ordinary required CI retains its original priority decision');

  // Reproduce1596→1597: source/priority and a different publisher are valid,
  // but the current main's original release has no accepted terminal.
  const plain = () => runGuard({ env, admission, fetchImpl, now });
  reset(); other.body = ''; previous = fixture(base);
  await rejects(plain, 'previous_release_unclosed',
    'an installed base still awaiting technical closure cannot admit another merge');
  check(previous.calls.every(c => c.method === 'GET'), true, 'pending release admission never mutates');

  const unboundSource = 'c9b4d97a945f88e068b71a9af6c948013434e050';
  const unboundRecord = R.releaseRecord({
    ...receipt(unboundSource, '44587916300'),
    reported_at: '2026-11-19T18:42:54Z',
  }, { sha: unboundSource, id: '44587916300', attempt: 1, event: 'push' }, {
    content: R.contentSummary(null, '3e63ed046a3cdd5ae0e12ca431ae0bce59beae7b', unboundSource),
    intent: {"checked_commits":1,"coverage":"registered_markers_and_reserved_families","matched_commits":[],"status":"no_registered_intent"},
  });
  const checkUnbound = async (change = () => {}, candidate = 1631) => {
    const f = fixture(unboundSource, unboundRecord);
    f.runs.delete(f.wave.own.id);
    const wave = f.start(unboundSource, 44587916300, 44590757092, 1630);
    f.pr = { ...f.pr, body: 'Original PR had no publisher binding', updated_at: '2026-11-19T18:26:59Z',
      head: { ...f.pr.head, sha: '204afa0f455a227536f62196eb4f84762fe416b3' } };
    wave.own.status = 'completed';
    wave.own.conclusion = 'success';
    const closerJob = job('release-slot', wave.own, [
      step('Prepare the exact release proof before terminal publication'),
      step(R.TERMINAL_BINDING_STEP, 'skipped'),
      step(R.TERMINAL_STEP, 'skipped'),
    ]);
    closerJob.id = 135795803154;
    f.jobs.set(wave.own.id, [closerJob, job('configure', wave.own, [], 'skipped'),
      job('release-slot-recovery', wave.own, [], 'skipped')]);
    change(f, wave, closerJob);
    await verifyPreviousRelease({ repository, prNumber: candidate, baseSha: unboundSource }, env.GITHUB_TOKEN,
      async (url, options) => response(await f.api(options.method || 'GET', url.replace('https://api.github.com', ''),
        undefined, options.headers.Accept === 'application/vnd.github.raw+json')));
    check(f.calls.every(call => call.method === 'GET'), true, 'PR1630 recognition only reads original receipts');
  };
  await checkUnbound();
  await rejects(() => checkUnbound(undefined, 1632), 'previous_release_unclosed',
    'the exact unbound predecessor cannot admit an unrelated PR');
  await rejects(() => checkUnbound(f => { f.pr = { ...f.pr, body: slot({ ...publisher, head_sha: f.pr.head.sha }) }; }),
    'previous_unbound_pull_changed', 'a bound predecessor cannot use the unbound receipt');
  await rejects(() => checkUnbound((f, wave) => { wave.own.conclusion = 'failure'; }),
    'original_terminal_not_skipped', 'an unsuccessful closer cannot be recognized as a proved skip');
  await rejects(() => checkUnbound((f, wave, closer) => { closer.steps[2].conclusion = 'success'; }),
    'previous_unbound_terminal_changed', 'an executed terminal must use the normal proof');
  await rejects(() => checkUnbound(f => { f.artifacts.set(44590757092, [{ id: 12 }]); }),
    'previous_unbound_publication_found', 'unexpected original terminal publication refuses admission');

  for (const [description, change, code] of [
    ['active Deploy', f => { f.wave.parent.status = 'in_progress'; f.wave.parent.conclusion = null; }, 'producer_not_successful'],
    ['failed Deploy', f => { f.wave.parent.conclusion = 'failure'; }, 'producer_not_successful'],
    ['foreign Deploy source', f => { f.hook = c => c.path.includes('/actions/workflows/deploy.yml/runs?')
      ? { total_count: 1, workflow_runs: [{ ...copy(f.wave.parent), head_sha: 'c'.repeat(40) }] } : undefined; }, 'untrusted_workflow_run'],
    ['manual Deploy', f => { f.wave.parent.event = 'workflow_dispatch'; }, 'untrusted_workflow_run'],
    ['missing terminal step', f => { const j = f.jobs.get(f.wave.own.id).find(j => j.name === 'release-slot');
      j.steps = j.steps.filter(s => s.name !== R.TERMINAL_STEP); }, 'terminal_step_unconfirmed'],
    ['unbound terminal digest', f => { const j = f.jobs.get(f.wave.own.id).find(j => j.name === 'release-slot');
      j.steps = j.steps.filter(s => !s.name.startsWith(R.TERMINAL_BINDING_STEP)); }, 'publication_step_unconfirmed'],
    ['failed original closer', f => { f.wave.own.conclusion = 'failure'; }, 'producer_not_active'],
    ['active original closer', f => { f.wave.own.status = 'in_progress'; f.wave.own.conclusion = null; }, 'release_pending'],
    ['held earlier publisher', f => { f.comment = { ...f.comment, body: 'PR55: выпуск занят' }; }, 'previous_publisher_unclosed'],
    ['foreign earlier publisher owner', f => { f.comment = { ...f.comment, user: { id: 801 } }; }, 'foreign_record'],
    ['removed previous binding', f => { f.pr = { ...f.pr, body: '' }; }, 'previous_binding_changed']
  ]) {
    reset(); other.body = ''; previous = await acceptedPrevious(); change(previous);
    await rejects(plain, code, description + ' refuses even with a valid candidate publisher');
    check(previous.calls.every(c => c.method === 'GET'), true, description + ' is read-only');
  }
  for (const [description, value, code] of [
    ['missing native deployment', { total_count: 0, workflow_runs: [] }, 'ambiguous_previous_deployment'],
    ['incomplete native deployment list', { total_count: 1, workflow_runs: [] }, 'incomplete_previous_deployments']
  ]) {
    reset(); other.body = ''; previous = await acceptedPrevious();
    previous.hook = c => c.path.includes('/actions/workflows/deploy.yml/runs?') ? value : undefined;
    await rejects(plain, code, description + ' cannot authorize a merge');
  }
  reset(); other.body = ''; previous = await acceptedPrevious();
  previous.hook = c => c.path.includes('/actions/workflows/deploy.yml/runs?')
    ? { total_count: 2, workflow_runs: [copy(previous.wave.parent),
      { ...copy(previous.wave.parent), id: 1001 }] } : undefined;
  await rejects(plain, 'ambiguous_previous_deployment', 'two native deployment origins refuse');

  reset(); other.body = ''; previous = await acceptedPrevious();
  let diagnosticReads = 0;
  previous.hook = c => {
    if (c.path.endsWith('/git/ref/heads/diagnostics') && ++diagnosticReads === 2)
      previous.putRecord({ ...copy(previous.record), reported_at: '2026-11-06T06:00:01Z' }, previous.head);
  };
  await rejects(plain, 'previous_release_changed', 'changed diagnostic proof refuses final admission');

  reset(); other.body = ''; previous = await acceptedPrevious();
  let previousPublisherReads = 0;
  previous.hook = c => {
    if (c.path.endsWith('/issues/comments/900') && ++previousPublisherReads === 2)
      previous.comment = { ...previous.comment, updated_at: '2026-11-06T06:00:01Z' };
  };
  await rejects(plain, 'previous_publisher_changed', 'changed previous publisher version refuses');

  reset(); other.body = ''; previous = await acceptedPrevious();
  await rejects(() => runGuard({ env, admission, now, fetchImpl: async (url, options) =>
    url.endsWith('/git/ref/heads/main') && mainReads ? response({ object: { sha: 'c'.repeat(40) } })
      : fetchImpl(url, options) }), 'base_changed', 'main moving after terminal verification refuses');

  reset(); other.body = ''; previous = await acceptedPrevious();
  let deploymentReads = 0;
  await rejects(() => runGuard({ env, admission, now, fetchImpl: async (url, options) => {
    if (url.includes('/actions/workflows/deploy.yml/runs?')) { deploymentReads++; return response({}, 503); }
    return fetchImpl(url, options);
  } }), 'api_http_503', 'previous deployment metadata API refusal cannot bypass the fence');
  check(deploymentReads, 1, 'final admission never retries a failed metadata read');

  reset(); other.body = ''; previous = fixture(base);
  check((await runGuard({ env: { ...env, GITHUB_EVENT_NAME: 'pull_request' }, now,
    readEvent: () => JSON.stringify({ pull_request: target }), fetchImpl })).allowed, true,
    'ordinary source CI remains independent of a pending production release');
  check(calls.length, 2, 'source CI still reads only main and priority metadata');


  // The ordinary next release reuses the SAME issue1122 publisher, after the
  // original native terminal succeeded. Its current busy text belongs to us.
  for (const [mode, expected] of [
    ['accepted', null], ['foreign_previous_owner', 'previous_publisher_changed'],
    ['late_target_binding', 'invalid_publisher_binding'], ['late_acquisition', 'publisher_changed']
  ]) {
    reset(); other.body = ''; previous = await acceptedPrevious();
    const shared = { comment_id: 900, owner_id: mode === 'foreign_previous_owner' ? 801 : 800, updated_at: acquired };
    target.body = slot({ ...shared, head_sha: head });
    previous.comment = { ...previous.comment, body: 'PR1438: выпуск занят',
      user: { id: shared.owner_id }, updated_at: acquired };
    const sharedRun = () => runGuard({ env, admission: { ...admission, publisher: shared }, now,
      fetchImpl: async (url, options) => {
        const result = await fetchImpl(url, options);
        if (url.includes('/commits/' + base + '/pulls?')) {
          if (mode === 'late_target_binding') target.body = slot({ ...shared, head_sha: 'c'.repeat(40) });
          if (mode === 'late_acquisition') previous.comment.updated_at = '2026-11-10T10:26:28Z';
        }
        return result;
      } });
    if (expected) await rejects(sharedRun, expected, 'shared publisher ' + mode + ' refuses');
    else check((await sharedRun()).allowed, true,
      'same completed publisher reacquired by this exact next PR remains admissible');
    check(previous.calls.every(c => c.method === 'GET'), true,
      'shared publisher proof never acquires or releases a record');
  }


  // A genuinely recovered predecessor uses the canonical receipt, original
  // failures and native recovery terminal; no resolver or proof is stubbed.
  const recovered=priorWaveFixture();
  const prepared=await C.resumePriorWaveTerminal({...recovered.options(),prepare:true});
  recovered.own.steps.push(step(R.TERMINAL_BINDING_STEP+prepared.record_digest));
  recovered.env.PREPARED_RECORD_DIGEST=prepared.record_digest;
  await C.resumeTerminal(recovered.options());
  recovered.native.status='completed';recovered.native.conclusion='success';
  recovered.own.status='completed';recovered.own.conclusion='success';recovered.own.steps.push(step(R.TERMINAL_STEP));
  recovered.f.calls.length=0;
  let logMode='direct',logRequests=0,storageRequests=0;
  const recoveredFetch=async(url,options)=>{
    check(options.method||'GET','GET','recovery admission is read-only');
    if(url.includes('/actions/jobs/')&&url.endsWith('/logs')){
      logRequests++;
      check(options.redirect,'manual','canonical log redirect is validated explicitly');
      check(options.headers.Authorization,'Bearer '+env.GITHUB_TOKEN,'only GitHub receives repository token');
      if(logMode==='503')return new Response('',{status:503});
      if(logMode==='oversize')return new Response('x',{headers:{'content-length':String(2*1024*1024+1)}});
      if(logMode==='redirect'||logMode==='foreign_redirect')return new Response(null,{status:302,
        headers:{location:logMode==='redirect'?'https://proof.blob.core.windows.net/job.log':'https://attacker.example/job.log'}});
      const id=Number(/\/actions\/jobs\/(\d+)\/logs$/.exec(url)[1]);
      return new Response(logMode==='wrong_reason'?T+' release_slot=held reason=wrong\n':await recovered.f.api.jobLog(id));
    }
    if(url.startsWith('https://proof.blob.core.windows.net/')){
      storageRequests++;check(options.redirect,'error','storage cannot redirect again');
      check(Object.keys(options.headers).length,0,'signed storage never receives repository credentials');
      // The fixture has two original logs, requested in this exact order.
      return new Response(await recovered.f.api.jobLog(logRequests%2?recovered.p.job_id:recovered.q.verify_job_id));
    }
    check(options.redirect,'error','ordinary canonical metadata never follows redirects');
    return response(await recovered.f.api('GET',url.replace('https://api.github.com',''),undefined,
      options.headers.Accept==='application/vnd.github.raw+json'));
  };
  const recoveredContext={...admission,baseSha:recovered.p.sha,now};
  for(const mode of ['direct','redirect']){
    logMode=mode;logRequests=0;storageRequests=0;
    await verifyPreviousRelease(recoveredContext,env.GITHUB_TOKEN,recoveredFetch);
    check(logRequests,2,'canonical recovered predecessor authenticates both original reasons: '+mode);
    check(storageRequests,mode==='redirect'?2:0,'only allowed signed log destinations are fetched');
  }
  for(const [mode,code]of [['503','api_http_503'],['oversize','response_size_limit'],
    ['foreign_redirect','invalid_log_redirect'],['wrong_reason','failed_terminal_reason_changed']]){
    logMode=mode;logRequests=0;storageRequests=0;
    await rejects(()=>verifyPreviousRelease(recoveredContext,env.GITHUB_TOKEN,recoveredFetch),code,'recovery log '+mode+' refuses');
    check(logRequests,1,'failed log request is not retried: '+mode);
    check(storageRequests,0,'invalid log never follows a foreign destination');
  }
  check(recovered.f.calls.every(c=>c.method==='GET'),true,'recovered admission never mutates remote state');

  // Losing a different candidate publisher while proving the predecessor must
  // refuse just as losing the same shared publisher does.
  for(const [description,change,code]of [
    ['body',()=>{comment.body='PR1441: выпуск занят';},'publisher_changed'],
    ['version',()=>{comment.updated_at='2026-11-10T10:27:00Z';},'publisher_changed'],
    ['owner',()=>{comment.user.id++;},'publisher_changed'],
    ['head',()=>{target.head.sha='c'.repeat(40);},'invalid_publisher_binding'],
    ['binding',()=>{target.body='';},'invalid_publisher_binding']]){
    reset();other.body='';previous=await acceptedPrevious();let changed=false;
    await rejects(()=>run({fetchImpl:async(url,options)=>{
      if(!changed&&url.includes('/actions/workflows/deploy.yml/runs?')){changed=true;change();}
      return fetchImpl(url,options);
    }}),code,'different candidate publisher late '+description+' refuses');
    check(changed,true,'mutation was after the initial publisher admission');
  }


  for(const mode of ['reservation','head']){
    reset();other.body='';previous=await acceptedPrevious();let changed=false;
    const late=()=>runGuard({env,admission,now,fetchImpl:async(url,options)=>{
      if(!changed&&url.includes('/actions/workflows/deploy.yml/runs?')){
        changed=true;
        if(mode==='reservation')other.body=reservation('2026-11-10T10:27:10Z');
        else target.head.sha='c'.repeat(40);
      }
      return fetchImpl(url,options);
    }});
    if(mode==='reservation'){
      const decision=await late();check(decision.allowed,false,'plain final admission respects newly ready release');
      check(decision.holder_pr,1441,'new existing reservation retains its ordinary priority');
    }else await rejects(late,'superseded_target','plain final admission refreshes exact target head');
    check(changed,true,'plain mutation occurred during predecessor proof');
  }


  for(const mode of ['new_during_proof','expired_during_proof']){
    reset();other.body='';previous=await acceptedPrevious();let ticks=0,changed=false;
    if(mode==='expired_during_proof')target.body+='\n'+reservation('2026-11-10T10:26:00Z',
      {expires_at:'2026-11-10T10:29:00Z'});
    const result=await runGuard({env,admission,now,clock:()=>ticks++?120000:0,fetchImpl:async(url,options)=>{
      if(!changed&&url.includes('/actions/workflows/deploy.yml/runs?')){
        changed=true;if(mode==='new_during_proof')other.body=reservation('2026-11-10T10:28:01Z');
      }
      return fetchImpl(url,options);
    }});
    check(ticks,2,'bounded monotonic clock sampled at start and final admission');
    check(result.allowed,mode==='expired_during_proof','long proof uses elapsed time: '+mode);
    check(result.reason,mode==='expired_during_proof'?'no_active_reservation':'earlier_ready_release',
      'final reservation window is current: '+mode);
  }

  reset();other.body='';previous=await acceptedPrevious();let ownerTicks=0;
  const stillOwned=await run({clock:()=>ownerTicks++?120000:0,fetchImpl:async(url,options)=>{
    if(url.includes('/actions/workflows/deploy.yml/runs?'))other.body=reservation('2026-11-10T10:28:01Z');
    return fetchImpl(url,options);
  }});
  check(stillOwned.allowed,true,'elapsed time does not expire an acquired publisher');
  check(stillOwned.reason,'active_publisher','existing ownership semantics are preserved');
  check(stillOwned.ignored_later_reservations,1,'new later claim still cannot displace acquired owner');
  reset();other.body='';previous=await acceptedPrevious();let backwards=0;
  await rejects(()=>run({clock:()=>backwards++?-1:0}),'invalid_clock','backwards monotonic clock refuses admission');

  const {profile, authorizeEvent, publish} = require('../tools/release_slot_1758_receipt');
  const recoveryRef = 'refs/heads/' + profile.branch;
  const recoverySource = '5'.repeat(40);
  const recoveryEnv = {
    GITHUB_REPOSITORY: repository, GITHUB_EVENT_NAME:'push', GITHUB_REF:recoveryRef,
    GITHUB_WORKFLOW_REF:repository + '/' + C.CLOSER + '@' + recoveryRef,
    GITHUB_JOB:'release-slot-recovery', GITHUB_RUN_ATTEMPT:'1',
    GITHUB_ACTOR:'pyatkoff', GITHUB_TRIGGERING_ACTOR:'pyatkoff',
    GITHUB_SHA:recoverySource, GITHUB_RUN_ID:'46123456789',
  };
  const recoveryEvent = {
    repository:{full_name:repository,id:1342493152},
    sender:{login:'pyatkoff',id:profile.owner}, ref:recoveryRef, before:'0'.repeat(40),
    after:recoverySource, created:true, deleted:false, forced:false,
  };
  check(authorizeEvent(recoveryEnv,recoveryEvent), undefined, 'the original authorized PR1758 receipt event is accepted');
  for (const changed of [{created:false}, {forced:true}, {sender:{login:'pyatkoff',id:1}}]) {
    await rejects(() => publish({
      env:recoveryEnv, event:{...recoveryEvent,...changed}, api:() => {
        throw new Error('a rejected event must never read or write the API');
      },
    }), 'unbound_receipt_event_invalid', 'a different PR1758 recovery event is refused before any API operation');
  }
  await rejects(() => publish({
    env:{...recoveryEnv,GITHUB_RUN_ATTEMPT:'2'}, event:recoveryEvent, api:() => {
      throw new Error('a rerun must never read or write the API');
    },
  }), 'unbound_receipt_event_invalid', 'a second PR1758 attempt cannot create a terminal receipt');

  const tool = path.resolve(__dirname, '../tools/release_priority.js');
  const cli = spawnSync(process.execPath, [tool, '--admit', '1438', head, base, '--publisher',
    '648', '17', acquired], { encoding: 'utf8', env: { GITHUB_REPOSITORY: repository } });
  check(cli.status, 1, 'pinned CLI cannot continue without read capability');
  check(cli.stderr.includes('missing_read_capability'), true, 'pinned CLI reaches the same read-only guard');
};

if (require.main === module) {
  let count = 0;
  module.exports((actual, expected, message) => { assert.equal(actual, expected, message); count++; })
    .then(() => process.stdout.write('Publisher admission: ' + count + ' assertions passed\n'))
    .catch(error => { process.stderr.write(error.stack + '\n'); process.exitCode = 1; });
}
