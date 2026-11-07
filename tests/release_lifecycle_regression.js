'use strict';

const assert = require('node:assert/strict');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const { execFileSync, spawnSync } = require('node:child_process');

// Required by the existing CI-scope entrypoint. Keeping this file in tests/
// preserves every original relative tool path and CLI check.
// The caller owns assertions and failure reporting; importing has no effect.
module.exports = function verifyReleaseLifecycle(check) {
// Priority is mutable metadata, so its read-only admission can be repeated
// without rerunning accepted source, browser or production checks.
async function verifyReleasePriority() {
  const { decidePriority, readOpenPulls, runGuard } = require('../tools/release_priority');
  const repository = 'pyatkoff/max-search-bot';
  const now = Date.parse('2026-11-03T18:20:00Z');
  const head = 'a'.repeat(40), base = 'b'.repeat(40);
  const context = { repository, prNumber: 1306, headSha: head, baseSha: base, now };
  const claim = { head, base, started_at: '2026-11-03T18:10:00Z',
    expires_at: '2026-11-03T18:30:00Z', kind: 'product' };
  const marker = value => '<!-- anytour-release-priority:v1 ' + JSON.stringify(value) + ' -->';
  const pr = (number, body = '', patch = {}) => ({ number, body, state: 'open', draft: false,
    author_association: 'OWNER', head: { sha: head, repo: { full_name: repository } },
    base: { ref: 'main', sha: base }, ...patch });
  const target = pr(1306), ready = pr(1305, marker(claim));
  const decide = pulls => decidePriority(pulls, context);
  const rejects = async (action, code, message) => {
    let observed = null;
    try { await action(); } catch (error) { observed = error.code; }
    check(observed, code, message);
  };
  check(decide([target]).allowed, true, 'no reservation does not invent a blocker');
  check(decide([target, ready]).holder_pr, 1305, 'ready product retains priority over later candidate');
  check(decide([target, ready]).allowed, false, 'later candidate cannot pass the release gate');
  check(decidePriority([target, ready], { ...context, prNumber: 1305 }).allowed, true, 'holder can finish');
  check(decide([pr(1304, marker({ ...claim, started_at: '2026-11-03T18:11:00Z' })), ready, target])
    .holder_pr, 1305, 'time ordering precedes PR number');
  check(decide([pr(1304, marker(claim)), ready, target]).holder_pr, 1304, 'equal times have one deterministic owner');

  for (const [description, other] of [
    ['expired', pr(1305, marker({ ...claim, expires_at: '2026-11-03T18:20:00Z' }))],
    ['updated head', pr(1305, marker({ ...claim, head: 'c'.repeat(40) }))],
    ['advanced base', pr(1305, marker({ ...claim, base: 'c'.repeat(40) }))],
    ['draft', pr(1305, marker(claim), { draft: true })],
    ['closed', pr(1305, marker(claim), { state: 'closed' })],
    ['untrusted author', pr(1305, marker(claim), { author_association: 'CONTRIBUTOR' })],
    ['fork', pr(1305, marker(claim), { head: { sha: head, repo: { full_name: 'outside/fork' } } })],
    ['different base branch', pr(1305, marker(claim), { base: { ref: 'other', sha: base } })]
  ]) check(decide([target, other]).allowed, true, description + ' cannot hold the release');
  check(decide([target, pr(1305, 'Documentation mentions anytour-release-priority:v1 only.')]).allowed, true,
    'mentioning the policy without a marker is not a reservation');
  const incident = pr(1307, marker({ ...claim, kind: 'incident',
    incident: 'https://github.com/' + repository + '/issues/1122' }));
  check(decide([target, ready, incident]).holder_pr, 1307, 'explicit confirmed-incident reference outranks product');
  check(decidePriority([target, ready, incident], { ...context, prNumber: 1307 }).allowed, true,
    'incident still uses the same admission, never bypasses checks or the publisher');

  for (const [body, code] of [
    [marker({ ...claim, expires_at: '2026-11-03T19:00:00Z' }), 'invalid_window'],
    [marker({ ...claim, started_at: '2026-11-03T18:21:00Z' }), 'invalid_window'],
    [marker({ ...claim, expires_at: claim.started_at }), 'invalid_window'],
    [marker({ ...claim, expires_at: '2026-02-30T18:30:00Z' }), 'invalid_window'],
    [marker({ ...claim, expires_at: '2026-11-03T18:30:00+00:00' }), 'invalid_fields'],
    [marker({ ...claim, head: 'short' }), 'invalid_fields'],
    [marker({ ...claim, kind: 'cleanup' }), 'invalid_fields'],
    [marker({ ...claim, renew: true }), 'invalid_fields'],
    [marker({ ...claim, kind: 'incident' }), 'incident_reference_required'],
    [marker({ ...claim, kind: 'incident', incident: 'https://github.com/other/repo/issues/1' }), 'incident_reference_required'],
    [marker({ ...claim, incident: 'https://github.com/' + repository + '/issues/1' }), 'unexpected_incident_reference'],
    [marker(claim) + marker(claim), 'invalid_marker'],
    [marker(claim) + '<!-- anytour-release-priority:v1', 'invalid_marker'],
    [marker(claim).replace(':v1', ':v2'), 'invalid_marker'],
    ['<!-- anytour-release-priority:v1 nope -->', 'invalid_json'],
    ['<!-- anytour-release-priority:v1 [] -->', 'invalid_claim'],
    ['<!-- anytour-release-priority:v1', 'invalid_marker']
  ]) await rejects(() => decide([target, pr(1305, body)]), code, 'malformed authoritative claim stops safely: ' + code);
  await rejects(() => decide([target, target]), 'ambiguous_snapshot', 'duplicate snapshot cannot authorize a merge');
  await rejects(() => decide([ready]), 'superseded_target', 'removed target cannot merge');
  await rejects(() => decide([pr(1306, '', { head: { sha: 'c'.repeat(40) } })]),
    'superseded_target', 'changed target cannot reuse a successful decision');
  await rejects(() => decidePriority([target], { ...context, repository: '../other' }),
    'invalid_context', 'repository identity is not an arbitrary URL');

  const event = JSON.stringify({ pull_request: target });
  const env = { GITHUB_EVENT_NAME: 'pull_request', GITHUB_REPOSITORY: repository,
    GITHUB_EVENT_PATH: 'event.json', GITHUB_TOKEN: 'fixture-token' };
  let calls = [];
  const response = (value, status = 200) => ({ ok: status === 200, status, json: async () => value });
  const fetchGood = async (url, options) => {
    calls.push(url);
    check(options.redirect, 'error', 'REST redirect is not an alternate host');
    check(Boolean(options.signal), true, 'each read has cancellation');
    return response(url.endsWith('/git/ref/heads/main') ? { object: { sha: base } } : [target, ready]);
  };
  const options = { env, readEvent: () => event, fetchImpl: fetchGood, now };
  check((await runGuard(options)).allowed, false, 'CI executes fresh admission against main and open PRs');
  check(calls.length, 2, 'one main read and one bounded PR read');
  calls = [];
  check((await runGuard({ ...options, admission: context,
    env: { ...env, GITHUB_EVENT_NAME: '' }, readEvent: () => { throw Error('not used'); } })).allowed,
    false, 'explicit pre-merge admission does not rely on an old CI event');
  check(calls.length, 2, 'explicit admission stays read only and bounded');
  calls = [];
  check((await runGuard({ ...options, env: { ...env, GITHUB_EVENT_NAME: 'workflow_dispatch' } })).reason,
    'manual_checks_only', 'manual validation does not claim merge authorization');
  check(calls.length, 0, 'manual validation does not reread merge state');
  await rejects(() => runGuard({ ...options, env: { ...env, GITHUB_EVENT_NAME: 'push' } }),
    'unsupported_event', 'unknown events do not get admission');
  await rejects(() => runGuard({ ...options, readEvent: () => 'broken' }),
    'invalid_event', 'unreadable event cannot authorize');
  await rejects(() => runGuard({ ...options, env: { ...env, GITHUB_TOKEN: '' } }),
    'missing_read_capability', 'missing capability does not pass open');
  let requests = 0;
  await rejects(() => runGuard({ ...options, fetchImpl: async () => {
    requests++; return response({ object: { sha: 'c'.repeat(40) } });
  } }), 'base_changed', 'current main must match the frozen admission');
  check(requests, 1, 'changed main stops without fetching more state');

  requests = 0;
  await rejects(() => runGuard({ ...options, fetchImpl: async () => {
    requests++; return response({}, 429);
  } }), 'api_http_429', 'rate limit is a stop, not a bypass');
  check(requests, 1, 'rate limit is never retried');
  await rejects(() => runGuard({ ...options, fetchImpl: async () => { throw Error('fixture-token must not leak'); } }),
    'read_failed', 'network failure is sanitized and closed');
  await rejects(() => runGuard({ ...options, fetchImpl: async () => {
    const error = new Error('private transport detail'); error.code = 'ECONNRESET'; throw error;
  } }), 'read_failed', 'coded transport failures cannot print private error details');
  await rejects(() => runGuard({ ...options, fetchImpl: async () => ({
    ok: true, json: async () => { throw Error('invalid or timed out response body'); }
  }) }), 'invalid_api_response', 'body parsing failure is closed');

  requests = 0;
  const hundred = Array.from({ length: 100 }, (_, i) => pr(i + 1));
  const pages = [hundred, [target, ready]];
  check((await readOpenPulls(repository, 'fixture-token', async () => response(pages[requests++]))).length,
    102, 'second page is read before deciding absence of priority');
  check(requests, 2, 'pagination is bounded to returned pages');
  requests = 0;
  await rejects(() => readOpenPulls(repository, 'fixture-token', async () => {
    requests++; return response(hundred);
  }), 'snapshot_limit', 'truncated snapshot never means no priority');
  check(requests, 3, 'snapshot limit bounds API work');
  await rejects(() => readOpenPulls(repository, 'fixture-token', async () => response({})),
    'invalid_api_response', 'non-array PR list is not an empty list');
}


async function verifyReleaseSlot() {
  const { REQUIRED, CLOSER, ACTIVATION, context, binding, gateState, recordState, verifyTelemetry,
    verifyConfigure, closeRelease, makeApi } = require('../tools/release_slot_close');
  const repository = 'pyatkoff/max-search-bot';
  const prefix = '/repos/' + repository;
  const source = 'a'.repeat(40), head = 'b'.repeat(40), created = '2026-11-03T19:00:00Z';
  const copy = value => JSON.parse(JSON.stringify(value));
  const c = { sha: source, deploy: 100, run: 200, configure: 'skipped', activation: false };
  const env = { GITHUB_REPOSITORY: repository, GITHUB_EVENT_NAME: 'workflow_run',
    GITHUB_REF: 'refs/heads/main', GITHUB_RUN_ID: '200', GITHUB_RUN_ATTEMPT: '1',
    GITHUB_SHA: source, CONFIGURE_RESULT: 'skipped' };
  const runs = REQUIRED.map(([file, event, name], i) => ({ id: 100 + i,
    path: '.github/workflows/' + file, name, event, head_sha: source, head_branch: 'main',
    head_repository: { full_name: repository }, run_attempt: 1, created_at: created,
    status: 'completed', conclusion: 'success' }));
  runs.push({ id: 200, path: CLOSER, name: 'Configure AnyTour modeled value goal',
    event: 'workflow_run', head_sha: source, head_branch: 'main', head_repository: { full_name: repository },
    run_attempt: 1, created_at: created, status: 'in_progress', conclusion: null });
  const snapshot = { total_count: runs.length, workflow_runs: runs };
  const event = { action: 'completed', repository: { full_name: repository },
    workflow_run: { ...copy(runs[0]), head_commit: { id: source, message: 'Ordinary product release' } } };
  const marker = { comment_id: 900, head_sha: head, owner_id: 800, updated_at: created };
  const pr = { number: 55, state: 'closed', merged: true, merge_commit_sha: source,
    head: { sha: head, repo: { full_name: repository } },
    base: { ref: 'main', repo: { full_name: repository } },
    body: '<!-- release-slot-close:v1 ' + JSON.stringify(marker) + ' -->' };
  const b = binding(pr, source);
  const comment = { id: 900, body: b.active, user: { id: 800 }, updated_at: created,
    author_association: 'OWNER', issue_url: 'https://api.github.com' + prefix + '/issues/1122' };
  const deploy = { sha: source, run_id: '100', provenance: 'success', key: 'success',
    bundle: 'success', sync: 'success', migration: 'success', max_webhook: 'success',
    metrika_cron: 'success', smoke: 'success', diagnostics: 'success' };
  const ops = { sha: source, migration: 'success', snapshot: 'success', live_sessions: 'success',
    daily_sessions: 'success', yesterday_sessions: 'success', architecture_inventory: 'success',
    website_smoke: 'success', health: 'success' };
  const jobs = { total_count: 2, jobs: [
    { name: 'configure', run_id: 200, head_sha: source, status: 'completed', conclusion: 'skipped' },
    { name: 'release-slot', run_id: 200, head_sha: source, status: 'in_progress', conclusion: null },
  ] };
  const rejects = async (fn, expected, label) => {
    let actual = 'no_refusal'; try { await fn(); } catch (error) { actual = error.code; }
    check(actual, expected, 'release slot: ' + label);
  };
  check(JSON.stringify(context(env, event)), JSON.stringify(c), 'release slot: exact original push context');
  for (const [field, value] of [['GITHUB_EVENT_NAME','workflow_dispatch'], ['GITHUB_RUN_ATTEMPT','2'],
    ['GITHUB_REF','refs/heads/foreign'], ['GITHUB_SHA','c'.repeat(40)], ['CONFIGURE_RESULT','failure']]) {
    await rejects(() => context({ ...env, [field]: value }, event), 'untrusted_context', 'reject ' + field);
  }
  for (const [field, value] of [['event','workflow_dispatch'], ['run_attempt',3], ['conclusion','failure'],
    ['head_repository',{ full_name:'foreign/repo' }], ['path','.github/workflows/other.yml']]) {
    const e = copy(event); e.workflow_run[field] = value;
    await rejects(() => context(env, e), 'untrusted_context', 'upstream ' + field);
  }
  check(binding({ ...pr, body:'No automatic closure admitted.' }, source), null, 'release slot: no opt-in stays untouched');
  for (const body of [pr.body + '\n' + pr.body, '<!-- release-slot-close:v1 {broken} -->',
    pr.body.replace(head, source), pr.body.replace('"owner_id":800','"owner_id":0')]) {
    await rejects(() => binding({ ...pr, body }, source), 'invalid_binding', 'malformed or foreign binding');
  }
  await rejects(() => binding({ ...pr, merged:false }, source), 'untrusted_pull', 'unmerged source');
  check(REQUIRED.filter(([file, event, name]) => file === 'modeled-conversion-monitor.yml' && event === 'workflow_run' && name === 'Monitor modeled conversion delivery').length,
    1, 'release slot: installed canonical monitor is a mandatory original gate');
  check(gateState(snapshot, c), 'ready', 'release slot: all original gates ready');
  for (let i = 0; i < REQUIRED.length; i++) {
    const missing = copy(snapshot); missing.workflow_runs.splice(i, 1); missing.total_count--;
    if (i === 0) await rejects(() => gateState(missing,c), 'deployment_missing', 'deploy absent');
    else check(gateState(missing,c), 'waiting', 'release slot: missing gate ' + i + ' waits');
    for (const failure of ['failure','cancelled','skipped','timed_out',null]) {
      const changed = copy(snapshot); changed.workflow_runs[i].conclusion = failure;
      if (REQUIRED[i][0] === ACTIVATION.file && failure === 'skipped')
        check(gateState(changed,c), 'ready', 'release slot: declared activation skip still requires job proof');
      else await rejects(() => gateState(changed,c), 'required_gate_failed', 'mandatory gate ' + i + ' ' + failure);
    }
    const pending = copy(snapshot); pending.workflow_runs[i].status = 'in_progress'; pending.workflow_runs[i].conclusion = null;
    check(gateState(pending,c), 'waiting', 'release slot: running gate ' + i + ' waits');
  }
  for (const [field, value] of [['head_sha','c'.repeat(40)], ['run_attempt',2],
    ['head_repository',{ full_name:'foreign/repo' }], ['head_branch','feature']]) {
    const changed = copy(snapshot); changed.workflow_runs[2][field] = value;
    await rejects(() => gateState(changed,c), 'untrusted_run', 'reject gate ' + field);
  }
  const duplicate = copy(snapshot); duplicate.workflow_runs.push({ ...runs[2], id: 800 }); duplicate.total_count++;
  await rejects(() => gateState(duplicate,c), 'ambiguous_run', 'duplicate workflow origin');
  const manual = copy(snapshot); manual.workflow_runs[2].event = 'workflow_dispatch';
  check(gateState(manual,c), 'waiting', 'release slot: manual success never replaces original gate');
  const scheduled = copy(snapshot); scheduled.workflow_runs.push({ ...runs[6], event:'schedule', id:801 }); scheduled.total_count++;
  check(gateState(scheduled,c), 'ready', 'release slot: natural later monitoring is separate');
  await rejects(() => gateState({ ...snapshot, total_count:100 },c), 'incomplete_runs', 'pagination incomplete');
  const unknown = copy(snapshot); unknown.workflow_runs.push({ ...runs[2], id:802, path:'.github/workflows/new-required.yml' }); unknown.total_count++;
  await rejects(() => gateState(unknown,c), 'unclassified_gate', 'new postdeploy gate cannot be silently ignored');
  const stale = copy(snapshot); stale.workflow_runs[2].created_at = '2026-11-03T18:59:59Z';
  await rejects(() => gateState(stale,c), 'stale_gate', 'old postdeploy result');
  const closer = copy(snapshot); closer.workflow_runs.at(-1).status = 'completed';
  await rejects(() => gateState(closer,c), 'closer_not_active', 'cancelled or replayed closer');
  check(recordState(comment,b), 'active', 'release slot: admitted exact owner and body');
  check(recordState({ ...comment, body:b.released },b), 'released', 'release slot: terminal is idempotent');
  for (const changed of [{ ...comment, body:'PR55: UNKNOWN' }, { ...comment, updated_at:'2026-11-03T19:00:01Z' }])
    await rejects(() => recordState(changed,b), 'record_changed', 'owner update cannot be overwritten');
  for (const changed of [{ ...comment, id:901 }, { ...comment, user:{id:801} },
    { ...comment, issue_url:comment.issue_url+'1' }, { ...comment, author_association:'NONE' }])
    await rejects(() => recordState(changed,b), 'foreign_record', 'foreign record never writable');
  verifyTelemetry(deploy,ops,c); check(true,true,'release slot: actual installation evidence accepted');
  for (const key of ['sha','run_id','provenance','key','bundle','sync','migration','max_webhook','metrika_cron','smoke','diagnostics'])
    await rejects(() => verifyTelemetry({ ...deploy,[key]:'wrong' },ops,c), 'installation_unconfirmed', 'installation ' + key);
  for (const key of Object.keys(ops))
    await rejects(() => verifyTelemetry(deploy,{ ...ops,[key]:'wrong' },c), 'diagnostics_unconfirmed', 'diagnostics ' + key);
  verifyConfigure(jobs,c); check(true,true,'release slot: declared configuration skip accepted');
  const configured=copy(jobs); configured.jobs[0].conclusion='success'; verifyConfigure(configured,{...c,configure:'success'});
  check(true,true,'release slot: actual configuration success accepted');
  await rejects(() => verifyConfigure(configured,c), 'configuration_unconfirmed','skip cannot mask configured job');
  function fixture(hook) {
    let time=0, saved=copy(comment), writes=0, reads=0;
    const calls=[];
    const api=async(method,path,body,raw) => {
      calls.push({method,path,body,raw});
      const override=hook && await hook({method,path,body,raw,calls,time,writes,reads});
      if(override !== undefined) return override;
      if(method==='PATCH'){ writes++; saved={...saved,body:body.body}; return copy(saved); }
      if(path.endsWith('/git/ref/heads/main')) return {object:{sha:source}};
      if(path.includes('/commits/')) return [{number:55,state:'closed',merge_commit_sha:source}];
      if(path.endsWith('/pulls/55')) return copy(pr);
      if(path.includes('/issues/comments/')) { reads++; return copy(saved); }
      if(path.includes('/actions/runs?')) return copy(snapshot);
      const activation = runs.find(r => r.path.endsWith('/' + ACTIVATION.file));
      if(path.includes('/actions/runs/' + activation.id + '/attempts/1/jobs?'))
        return {total_count:1,jobs:[{name:'activate',run_id:activation.id,head_sha:source,status:'completed',conclusion:'skipped'}]};
      if(path.includes('/attempts/1/jobs?')) return copy(jobs);
      if(path.includes('/contents/deploy_status')) return copy(deploy);
      if(path.includes('/contents/ops_status')) return copy(ops);
      throw Error('unexpected synthetic request');
    };
    return { options:{env,event,api,now:()=>time,sleep:async(ms)=>{time+=ms;}},
      calls, writes:()=>writes, time:()=>time };
  }
  const tagged = copy(snapshot);
  const taggedMonitor = tagged.workflow_runs.find(r=>r.path.endsWith('/modeled-conversion-monitor.yml'));
  for(const run of tagged.workflow_runs.filter(r=>r.event==='workflow_run')) {
    run.display_title=run.path.endsWith('/'+ACTIVATION.file)?'monitor-proof:v1:'+taggedMonitor.id+':1':'deploy-proof:v1:100:1';
    run.name=run.display_title;
  }
  check(gateState(tagged,c),'ready','release slot: actual API exposes exact run title as name');
  const wrongTitle=copy(tagged);wrongTitle.workflow_runs[2].display_title='deploy-proof:v1:999:1';
  await rejects(()=>gateState(wrongTitle,c),'untrusted_run','matching name cannot hide foreign display title');
  const wrongParent=copy(tagged);wrongParent.workflow_runs[2].name=wrongParent.workflow_runs[2].display_title='deploy-proof:v1:999:1';
  await rejects(()=>gateState(wrongParent,c),'untrusted_run','foreign parent cannot masquerade as workflow name');
  const wrongMonitor=copy(tagged);const act=wrongMonitor.workflow_runs.find(r=>r.path.endsWith('/'+ACTIVATION.file));
  act.name=act.display_title='monitor-proof:v1:999:1';
  await rejects(()=>gateState(wrongMonitor,c),'untrusted_run','activation requires exact monitor parent');
  let taggedFixture=fixture(({path})=>path.includes('/actions/runs?')?copy(tagged):undefined);
  check(await closeRelease(taggedFixture.options),'released','release slot: actual tagged API closes through complete normal path');
  check(taggedFixture.writes(),1,'release slot: tagged API still writes only the bound publisher once');

  let f=fixture();
  check(await closeRelease(f.options),'released','release slot: successful original path closes');
  check(f.writes(),1,'release slot: exactly one update, no create/dispatch');
  check(f.calls.at(-1).method,'GET','release slot: exactly one final readback');
  check(f.calls.filter(x=>x.method!=='GET').every(x=>x.method==='PATCH'&&x.path===prefix+'/issues/comments/900'),
    true,'release slot: only bound record writable');
  f=fixture(({path,reads})=>path.includes('/issues/comments/')&&reads===0?{...comment,body:b.released}:undefined);
  check(await closeRelease(f.options),'already_released','release slot: accepted terminal never repeated');
  check(f.writes(),0,'release slot: no repeated write');
  f=fixture(({path,time})=>{
    if(path.includes('/actions/runs?')&&time<40000){const v=copy(snapshot);v.workflow_runs[3].status='in_progress';v.workflow_runs[3].conclusion=null;return v;}
  });
  check(await closeRelease(f.options),'released','release slot: parallel original gates complete without dispatch');
  check(f.time(),40000,'release slot: only incomplete gates wait');
  f=fixture(({path})=>{
    if(path.includes('/actions/runs?')) {const v=copy(snapshot);v.workflow_runs[3].status='in_progress';v.workflow_runs[3].conclusion=null;return v;}
  });
  await rejects(()=>closeRelease(f.options),'gate_deadline','bounded pending wait');
  check(f.writes(),0,'release slot: timeout retains slot');
  for (const [label, hook, code] of [
    ['failed gate',({path})=>{if(path.includes('/actions/runs?')){const v=copy(snapshot);v.workflow_runs[3].conclusion='failure';return v;}},'required_gate_failed'],
    ['late main change',({path,calls})=>path.endsWith('/git/ref/heads/main')&&calls.filter(x=>x.path===path).length===3?{object:{sha:head}}:undefined,'main_changed'],
    ['late owner edit',({path,reads})=>path.includes('/issues/comments/')&&reads===1?{...comment,body:'held for repair'}:undefined,'record_changed'],
    ['binding revoked',({path,calls})=>path.endsWith('/pulls/55')&&calls.filter(x=>x.path===path).length===2?{...pr,body:''}:undefined,'binding_changed'],
    ['API refusal',({path})=>{if(path.includes('/actions/runs?'))throw Object.assign(Error('private'),{code:'api_http_429'});},'api_http_429'],
  ]) {
    f=fixture(hook);await rejects(()=>closeRelease(f.options),code,label);check(f.writes(),0,'release slot: '+label+' cannot write');
  }
  let attempts=0;
  f=fixture(({method})=>{if(method==='PATCH'){attempts++;throw Error('unknown transport private');}});
  await rejects(()=>closeRelease(f.options),'write_unknown','unknown write outcome');
  check(attempts,1,'release slot: unknown mutation never retried');
  f=fixture(({path,writes})=>{if(path.includes('/issues/comments/')&&writes)throw Error('readback unavailable');});
  await rejects(()=>closeRelease(f.options),'write_unknown','unknown readback');
  check(f.writes(),1,'release slot: unavailable readback never resends');
  let requests=0;
  await rejects(()=>makeApi('fixture',async()=>{requests++;return{ok:false,status:429};})('GET',prefix+'/git/ref/heads/main'),
    'api_http_429','transport rate limit');
  check(requests,1,'release slot: no transport retry');
  await rejects(()=>makeApi('fixture',async()=>{throw Object.assign(Error('private token'),{code:'ECONNRESET'});})('GET',prefix+'/git/ref/heads/main'),
    'transport_failed','private transport details sanitized');
  await rejects(()=>makeApi('fixture')('PATCH',prefix+'/issues/1122',{body:'cannot create'}),'invalid_write_target','no new records');
  await rejects(()=>makeApi(''),'missing_capability','no ambient fallback credential');
}


async function verifyReleaseSlotActivation() {
  const { REQUIRED, CLOSER, ACTIVATION, context, gateState, verifyActivation, closeRelease } = require('../tools/release_slot_close');
  const repository='pyatkoff/max-search-bot', source='a'.repeat(40), head='b'.repeat(40);
  const created='2026-11-03T23:00:00Z', prefix='/repos/'+repository;
  const copy=v=>JSON.parse(JSON.stringify(v));
  const rejects=async(fn,code,label)=>{
    let actual='no_refusal';try{await fn();}catch(e){actual=e.code;}
    check(actual,code,'release activation: '+label);
  };
  function fixture(requested=false,hook) {
    const runs=REQUIRED.map(([file,event,name],i)=>({id:100+i,path:'.github/workflows/'+file,
      name,event,head_sha:source,head_branch:'main',head_repository:{full_name:repository},
      run_attempt:1,created_at:created,status:'completed',conclusion:'success'}));
    runs.push({id:200,path:CLOSER,name:'Configure AnyTour modeled value goal',event:'workflow_run',
      head_sha:source,head_branch:'main',head_repository:{full_name:repository},
      run_attempt:1,created_at:created,status:'in_progress',conclusion:null});
    const activation=runs.find(r=>r.path.endsWith('/'+ACTIVATION.file));
    const snapshot={total_count:runs.length,workflow_runs:runs};
    const c={sha:source,deploy:100,run:200,configure:'skipped',activation:requested};
    const env={GITHUB_REPOSITORY:repository,GITHUB_EVENT_NAME:'workflow_run',GITHUB_REF:'refs/heads/main',
      GITHUB_RUN_ID:'200',GITHUB_RUN_ATTEMPT:'1',GITHUB_SHA:source,CONFIGURE_RESULT:'skipped'};
    const event={action:'completed',repository:{full_name:repository},
      workflow_run:{...copy(runs[0]),head_commit:{id:source,message:requested?ACTIVATION.marker:'Product release'}}};
    const marker={comment_id:900,head_sha:head,owner_id:800,updated_at:created};
    const pr={number:55,state:'closed',merged:true,merge_commit_sha:source,
      head:{sha:head,repo:{full_name:repository}},base:{ref:'main',repo:{full_name:repository}},
      body:'<!-- release-slot-close:v1 '+JSON.stringify(marker)+' -->'};
    let record={id:900,body:'PR55: выпуск занят',user:{id:800},updated_at:created,author_association:'OWNER',
      issue_url:'https://api.github.com'+prefix+'/issues/1122'};
    const activationJobs={total_count:1,jobs:[{name:'activate',run_id:activation.id,head_sha:source,
      status:'completed',conclusion:requested?'success':'skipped'}]};
    const configJobs={total_count:1,jobs:[{name:'configure',run_id:200,head_sha:source,status:'completed',conclusion:'skipped'}]};
    const deploy={sha:source,run_id:'100'};
    for(const key of ['provenance','key','bundle','sync','migration','max_webhook','metrika_cron','smoke','diagnostics'])deploy[key]='success';
    const ops={sha:source};
    for(const key of ['migration','snapshot','live_sessions','daily_sessions','yesterday_sessions','architecture_inventory','website_smoke','health'])ops[key]='success';
    let time=0,writes=0;const calls=[];
    const activationPath=prefix+'/actions/runs/'+activation.id+'/attempts/1/jobs?per_page=100';
    const api=async(method,path,body)=>{
      calls.push({method,path,body});
      const override=hook&&await hook({method,path,body,calls,activationPath,snapshot,activationJobs,activation,requested});
      if(override!==undefined)return copy(override);
      if(method==='PATCH'){writes++;record={...record,body:body.body};return copy(record);}
      if(path.endsWith('/git/ref/heads/main'))return{object:{sha:source}};
      if(path.includes('/commits/'))return[{number:55,state:'closed',merge_commit_sha:source}];
      if(path.endsWith('/pulls/55'))return copy(pr);
      if(path.includes('/issues/comments/'))return copy(record);
      if(path.includes('/actions/runs?'))return copy(snapshot);
      if(path===activationPath)return copy(activationJobs);
      if(path.includes('/actions/runs/200/attempts/1/jobs?'))return copy(configJobs);
      if(path.includes('/contents/deploy_status'))return copy(deploy);
      if(path.includes('/contents/ops_status'))return copy(ops);
      throw Error('unexpected synthetic activation request');
    };
    return{c,env,event,snapshot,activation,activationJobs,activationPath,calls,writes:()=>writes,
      options:{env,event,api,now:()=>time,sleep:async ms=>{time+=ms;}}};
  }
  check(REQUIRED.filter(([file])=>file===ACTIVATION.file).length,1,'release activation: existing installed gate included once');
  for(const requested of [false,true]){
    const f=fixture(requested);
    check(context(f.env,f.event).activation,requested,'release activation: bound original commit determines expected outcome');
    check(gateState(f.snapshot,f.c),'ready','release activation: completed metadata is only preliminary readiness');
    verifyActivation(f.activationJobs,f.activation,f.c);
    check(await closeRelease(f.options),'released','release activation: '+(requested?'approved success':'declared job skip')+' closes');
    check(f.writes(),1,'release activation: one existing record update only');
    check(f.calls.filter(x=>x.path===f.activationPath).length,1,'release activation: original job read once before update');
  }
  for(const change of [e=>{delete e.workflow_run.head_commit;},e=>{e.workflow_run.head_commit.id=head;},
    e=>{e.workflow_run.head_commit.message=null;},e=>{e.workflow_run.head_commit.message='x'.repeat(65537);}]){
    const f=fixture();change(f.event);await rejects(()=>context(f.env,f.event),'untrusted_context','unknown commit cannot imply skipped activation');
  }
  const skippedRun=fixture();skippedRun.activation.conclusion='skipped';
  check(gateState(skippedRun.snapshot,skippedRun.c),'ready','release activation: explicitly skipped run still needs exact job skip');
  verifyActivation(skippedRun.activationJobs,skippedRun.activation,skippedRun.c);
  for(const [label,requested,mutate,code] of [
    ['missing run',false,f=>{f.snapshot.workflow_runs=f.snapshot.workflow_runs.filter(r=>r.id!==f.activation.id);f.snapshot.total_count--;},'gate_deadline'],
    ['pending run',false,f=>{f.activation.status='in_progress';f.activation.conclusion=null;},'gate_deadline'],
    ['failed run',false,f=>{f.activation.conclusion='failure';},'required_gate_failed'],
    ['cancelled run',false,f=>{f.activation.conclusion='cancelled';},'required_gate_failed'],
    ['required run skipped',true,f=>{f.activation.conclusion='skipped';},'required_gate_failed'],
    ['unexpected activation',false,f=>{f.activationJobs.jobs[0].conclusion='success';},'activation_unconfirmed'],
    ['required job skipped',true,f=>{f.activationJobs.jobs[0].conclusion='skipped';},'activation_unconfirmed'],
    ['job wrong source',false,f=>{f.activationJobs.jobs[0].head_sha=head;},'activation_unconfirmed'],
    ['job foreign run',false,f=>{f.activationJobs.jobs[0].run_id=999;},'activation_unconfirmed'],
    ['job missing identity',false,f=>{f.activationJobs.jobs[0].name='unknown';},'activation_unconfirmed'],
    ['job still active',false,f=>{f.activationJobs.jobs[0].status='in_progress';},'activation_unconfirmed'],
    ['job missing',false,f=>{f.activationJobs.jobs=[];f.activationJobs.total_count=0;},'incomplete_activation_jobs'],
    ['jobs incomplete',false,f=>{f.activationJobs.total_count=2;},'incomplete_activation_jobs'],
    ['extra job',false,f=>{f.activationJobs.jobs.push({...f.activationJobs.jobs[0],name:'new_gate'});f.activationJobs.total_count++;},'incomplete_activation_jobs'],
    ['rerun cannot substitute',false,f=>{f.activation.run_attempt=2;},'untrusted_run'],
    ['duplicate origin',false,f=>{f.snapshot.workflow_runs.push({...f.activation,id:999});f.snapshot.total_count++;},'ambiguous_run'],
  ]){
    const f=fixture(requested);mutate(f);await rejects(()=>closeRelease(f.options),code,label);
    check(f.writes(),0,'release activation: '+label+' retains publisher');
  }
  let f=fixture(false,({path,activationPath})=>{
    if(path===activationPath)throw Object.assign(Error('unavailable'),{code:'api_http_429'});
  });
  await rejects(()=>closeRelease(f.options),'api_http_429','activation metadata refusal');
  check(f.writes(),0,'release activation: refused read cannot release');
  check(f.calls.filter(x=>x.path===f.activationPath).length,1,'release activation: refused read is not retried');
  f=fixture(false,({path,calls,snapshot,activation})=>{
    if(path.includes('/actions/runs?')&&calls.filter(x=>x.path===path).length===2){
      const changed=copy(snapshot);changed.workflow_runs.find(r=>r.id===activation.id).conclusion='failure';return changed;
    }
  });
  await rejects(()=>closeRelease(f.options),'required_gate_failed','late failed activation');
  check(f.writes(),0,'release activation: late failure cannot release');
}


async function verifyReleaseRecovery() {
  const M=require('../tools/release_slot_close');
  const copy=v=>JSON.parse(JSON.stringify(v));
  const repository='pyatkoff/max-search-bot',source='a'.repeat(40);
  const prefix='/repos/'+repository;
  const rejects=async(fn,code,label)=>{
    let actual='no_refusal';try{await fn();}catch(e){actual=e.code;}
    check(actual,code,'verification recovery: '+label);
  };
  function fixture(kind='download') {
    let serial=1000;
    const c={sha:source,deploy:100};
    const first={id:100,path:'.github/workflows/deploy.yml',name:'Deploy production',event:'push',
      head_branch:'main',head_repository:{full_name:repository},head_sha:source,head_commit:{id:source},
      run_attempt:1,created_at:'2026-11-01T01:00:00Z',run_started_at:'2026-11-01T01:00:00Z',
      updated_at:'2026-11-01T01:09:00Z',status:'completed',conclusion:'failure'};
    const current={...copy(first),run_attempt:2,run_started_at:'2026-11-01T01:10:00Z',
      updated_at:'2026-11-01T01:19:00Z',conclusion:'success'};
    const step=(name,conclusion,attempt)=>({number:++serial,name,status:'completed',conclusion,
      started_at:attempt===1?'2026-11-01T01:03:00Z':'2026-11-01T01:13:00Z',
      completed_at:attempt===1?'2026-11-01T01:04:00Z':'2026-11-01T01:14:00Z'});
    const job=(name,attempt,conclusion,steps)=>({id:++serial,name,run_id:100,run_attempt:attempt,
      head_sha:source,status:'completed',conclusion,runner_id:1234,runner_name:'Hosted Agent',labels:['ubuntu-latest'],
      started_at:attempt===1?'2026-11-01T01:02:00Z':'2026-11-01T01:12:00Z',
      completed_at:attempt===1?'2026-11-01T01:08:00Z':'2026-11-01T01:18:00Z',steps});
    const originals=M.INSTALL_JOBS.map(name=>job(name,1,'success',[step('Original required install gate','success',1)]));
    const copied=originals.map(j=>({...copy(j),id:++serial,run_attempt:2}));
    const states={check:'success',download:'success',status:'success',publication:'success',cleanup:'success'};
    if(kind==='check'){states.check='failure';states.download='skipped';states.cleanup='skipped';}
    if(kind==='download'){states.download='failure';states.cleanup='skipped';}
    if(kind==='status'){states.status='failure';states.publication='skipped';states.cleanup='skipped';}
    if(kind==='publication')states.publication='failure';
    if(kind==='cleanup')states.cleanup='failure';
    const named=(k,n,result)=>k==='publication'&&result!=='skipped'?n+'d'.repeat(64):n;
    const old=job('verify',1,'failure',Object.entries(M.VERIFY_STEPS).map(([k,n])=>step(named(k,n,states[k]),states[k],1)));
    const unfinished={check:['check','download','status','publication','cleanup'],
      download:['download','status','publication','cleanup'],status:['status','publication','cleanup'],
      publication:['publication'],cleanup:['cleanup']}[kind];
    const latest=job('verify',2,'success',Object.entries(M.VERIFY_STEPS).map(([k,n])=>{
      const result=unfinished.includes(k)?'success':'skipped';return step(named(k,n,result),result,2);
    }));
    const jobs=[...originals,old,...copied,latest];
    return {c,first,current,old,latest,originals,copied,history:{total_count:jobs.length,jobs}};
  }
  for(const kind of ['check','download','status','publication','cleanup']) {
    const f=fixture(kind),r=M.deploymentHistory(f.history,f.first,f.current,f.c);
    check(r.smokeAttempt,kind==='check'?2:1,'verification recovery: original smoke reused '+kind);
    check(r.diagnosticsAttempt,['check','download'].includes(kind)?2:1,'verification recovery: original copy reused '+kind);
    check(r.plan.run_check,kind==='check','verification recovery: only failed check reexecutes '+kind);
    check(r.plan.run_download,['check','download'].includes(kind),'verification recovery: only unfinished copy '+kind);
    check(r.plan.run_status,!['publication','cleanup'].includes(kind),'verification recovery: negative result must be republished '+kind);
    check(r.plan.run_publication,kind!=='cleanup','verification recovery: failed publication is not accepted '+kind);
    if(kind==='publication')check(r.plan.run_status,false,'verification recovery: proof recovery preserves the actual installation receipt');
    if(kind==='cleanup')check(r.plan.publication_result,'success','verification recovery: original proof is retained after cleanup failure');
  }
  const invalid=[
    ['new installer execution',f=>{f.copied.at(-1).started_at='2026-11-01T01:11:00Z';},'installation_or_source_replayed'],
    ['new source runner',f=>{f.copied[0].runner_id++;},'installation_or_source_replayed'],
    ['changed original install result',f=>{f.originals.at(-1).conclusion='failure';},'installation_unconfirmed'],
    ['failed original install step',f=>{f.originals.at(-1).steps[0].conclusion='failure';},'installation_unconfirmed'],
    ['third attempt',f=>{f.current.run_attempt=3;},'untrusted_deployment_attempt'],
    ['manual run',f=>{f.current.event='workflow_dispatch';},'untrusted_deployment_attempt'],
    ['foreign source',f=>{f.current.head_sha='c'.repeat(40);},'untrusted_deployment_attempt'],
    ['foreign repository',f=>{f.current.head_repository.full_name='other/repo';},'untrusted_deployment_attempt'],
    ['unfinished original',f=>{f.first.status='in_progress';},'original_attempt_not_failed'],
    ['successful original',f=>{f.first.conclusion='success';},'original_attempt_not_failed'],
    ['unfinished recovery',f=>{f.current.status='in_progress';f.current.conclusion=null;},'invalid_recovery_attempt'],
    ['truncated jobs',f=>{f.history.total_count++;},'incomplete_deployment_jobs'],
    ['duplicate ids',f=>{f.copied[0].id=f.originals[0].id;},'incomplete_deployment_jobs'],
    ['foreign job',f=>{f.latest.head_sha='c'.repeat(40);},'untrusted_deployment_job'],
    ['missing install job',f=>{f.history.jobs.splice(0,1);f.history.total_count--;},'ambiguous_deployment_job'],
    ['accepted check replay',f=>{f.latest.steps[0].conclusion='success';},'accepted_step_replayed_or_unconfirmed'],
    ['failed proof accepted',f=>{f.latest.steps.find(s=>s.name.startsWith(M.VERIFY_STEPS.publication)).conclusion='skipped';},'accepted_step_replayed_or_unconfirmed'],
    ['proof digest absent',f=>{f.old.steps.find(s=>s.name.startsWith(M.VERIFY_STEPS.publication)).name=M.VERIFY_STEPS.publication;},'incomplete_job_steps'],
    ['masked failure or unclassified skipped copy',f=>{f.old.steps[1].conclusion='skipped';f.old.steps[3].conclusion='skipped';},'unclassified_original_verification'],
    ['lost step metadata',f=>{f.old.steps.pop();},'incomplete_job_steps'],
    ['recovery still failed',f=>{f.latest.conclusion='failure';},'recovery_not_confirmed'],
  ];
  for(const [label,mutate,code] of invalid) {
    const f=fixture();mutate(f);
    await rejects(()=>M.deploymentHistory(f.history,f.first,f.current,f.c),code,label);
  }
  const f=fixture();
  const marker={comment_id:900,head_sha:'b'.repeat(40),owner_id:800,updated_at:'2026-11-01T01:00:00Z'};
  const pr={number:55,state:'closed',merged:true,merge_commit_sha:source,
    head:{sha:marker.head_sha,repo:{full_name:repository}},base:{ref:'main',repo:{full_name:repository}},
    body:'<!-- release-slot-close:v1 '+JSON.stringify(marker)+' -->'};
  const record={id:900,user:{id:800},author_association:'OWNER',updated_at:marker.updated_at,
    issue_url:'https://api.github.com'+prefix+'/issues/1122',body:'PR55: выпуск занят'};
  const env={GITHUB_REPOSITORY:repository,GITHUB_EVENT_NAME:'push',GITHUB_REF:'refs/heads/main',
    GITHUB_JOB:'verify',GITHUB_SHA:source,GITHUB_RUN_ID:'100',GITHUB_RUN_ATTEMPT:'2'};
  const current=copy(f.current);current.status='in_progress';current.conclusion=null;
  const history=copy(f.history);history.jobs.at(-1).status='in_progress';history.jobs.at(-1).conclusion=null;
  const calls=[];
  const api=async(method,path)=>{
    calls.push({method,path});
    if(method!=='GET')throw Error('recovery attempted a write');
    if(path.endsWith('/git/ref/heads/main'))return{object:{sha:source}};
    if(path.includes('/commits/'))return[{number:55,state:'closed',merge_commit_sha:source}];
    if(path.endsWith('/pulls/55'))return copy(pr);
    if(path.includes('/issues/comments/'))return copy(record);
    if(path.endsWith('/attempts/1'))return copy(f.first);
    if(path.endsWith('/attempts/2'))return copy(current);
    if(path.includes('/jobs?filter=all'))return copy(history);
    throw Error('unexpected recovery API request '+path);
  };
  const plan=await M.admitVerification({env,api});
  check(plan.run_check,false,'verification recovery: API admission preserves successful check');
  check(plan.run_download,true,'verification recovery: API admission resumes failed copy');
  check(calls.every(x=>x.method==='GET'),true,'verification recovery: admission cannot mutate');
  check(calls.filter(x=>x.path.includes('/issues/comments/')).length,2,'verification recovery: fresh publisher version');
  // Recover only a failed upload of an already successful status. The exact
  // digest is retained in the original failed upload step, even without artifact.
  const R=require('../tools/deployment_release'),publication=fixture('publication');
  const originalReceipt={...M.stageStatus({...env,GITHUB_RUN_ATTEMPT:'1',SMOKE_ATTEMPT:'1',DIAGNOSTICS_ATTEMPT:'1',
    CHECK_OUTCOME:'success',DOWNLOAD_OUTCOME:'success'}),reported_at:'2026-11-01T01:07:00Z'};
  const originalStatus=R.releaseRecord(originalReceipt,{sha:source,id:'100',attempt:1,event:'push'},
    {content:R.contentSummary(null,source,source),intent:{status:'unknown'}});
  const originalDigest=R.digest(originalStatus);
  publication.old.steps.find(s=>s.name.startsWith(M.VERIFY_STEPS.publication)).name=M.VERIFY_STEPS.publication+originalDigest;
  publication.current.status='in_progress';publication.current.conclusion=null;
  publication.latest.status='in_progress';publication.latest.conclusion=null;
  let savedStatus=copy(originalStatus),statusReads=0;
  const publicationApi=async(method,p)=>{
    check(method,'GET','verification recovery: publication recovery never rewrites status');
    if(p.endsWith('/actions/runs/100')||p.endsWith('/actions/runs/100/attempts/2'))return copy(publication.current);
    if(p.endsWith('/actions/runs/100/attempts/1'))return copy(publication.first);
    if(p.includes('/jobs?filter=all'))return copy(publication.history);
    if(p.endsWith('/git/ref/heads/diagnostics'))return {object:{sha:'d'.repeat(40)}};
    if(p.includes('/contents/deploy_status.json?ref=')){
      statusReads++;const bytes=Buffer.from(R.wire(savedStatus));return {type:'file',path:'deploy_status.json',encoding:'base64',
        sha:R.gitBlob(bytes),size:bytes.length,content:bytes.toString('base64')};
    }
    return api(method,p);
  };
  const publicationEnv={...env,GITHUB_WORKFLOW_REF:repository+'/.github/workflows/deploy.yml@refs/heads/main'};
  const publicationEvent={repository:{full_name:repository}};
  check((await M.resumePublication({env:publicationEnv,event:publicationEvent,api:publicationApi})).record_digest,
    originalDigest,'verification recovery: original failed proof digest is resumed');
  savedStatus.repository_release.intent_scan={status:'explicit_intent'};
  await rejects(()=>M.resumePublication({env:publicationEnv,event:publicationEvent,api:publicationApi}),
    'publication_recovery_source_changed','concurrent status replacement cannot be newly authorized');
  check(statusReads,2,'verification recovery: each attempt reads only the current exact receipt');
  await rejects(()=>M.admitVerification({env:{...env,GITHUB_RUN_ATTEMPT:'3'},api}),'untrusted_verification_context','third attempt before credentials');
  await rejects(()=>M.admitVerification({env,api:async()=>{throw Object.assign(Error('private'),{code:'api_http_429'});}}),'api_http_429','rate limit is not retried');
  await rejects(()=>M.admitVerification({env,api:async(m,p)=>p.endsWith('/git/ref/heads/main')?{object:{sha:'c'.repeat(40)}}:api(m,p)}),'main_changed','changed source before credentials');
  await rejects(()=>M.admitVerification({env,api:async(m,p)=>p.includes('/issues/comments/')?{...record,updated_at:'2026-11-01T01:00:01Z'}:api(m,p)}),'record_changed','publisher changed');
  const deploy={...M.stageStatus({...env,SMOKE_ATTEMPT:'1',DIAGNOSTICS_ATTEMPT:'2',CHECK_ACCEPTED:'success',DOWNLOAD_OUTCOME:'success'})};
  check(deploy.smoke,'success','verification recovery: stage status reuses accepted check');
  check(deploy.diagnostics_attempt,2,'verification recovery: receipt binds actual transfer attempt');
  const makeRun=(id,path,title)=>({id,path:'.github/workflows/'+path,event:'workflow_run',display_title:title,
    head_sha:source,head_branch:'main',head_repository:{full_name:repository},run_attempt:1,status:'completed',conclusion:'success'});
  const oldMonitor=makeRun(301,'modeled-conversion-monitor.yml','deploy-proof:v1:100:1');
  const newMonitor=makeRun(401,'modeled-conversion-monitor.yml','deploy-proof:v1:100:2');
  const oldGate=makeRun(302,'max-tls-preflight.yml','deploy-proof:v1:100:1');
  const newGate=makeRun(402,'max-tls-preflight.yml','deploy-proof:v1:100:2');
  const oldActivation=makeRun(303,M.ACTIVATION.file,'monitor-proof:v1:301:1');
  const newActivation=makeRun(403,M.ACTIVATION.file,'monitor-proof:v1:401:1');
  const snapshot={total_count:6,workflow_runs:[oldMonitor,newMonitor,oldGate,newGate,oldActivation,newActivation]};
  const skipped=async path=>{
    const runId=Number(path.match(/runs\/([0-9]+)/)[1]);
    return{total_count:1,jobs:[{run_id:runId,head_sha:source,status:'completed',conclusion:'skipped',steps:[]}]};
  };
  const selected=await M.selectRecoveryRuns(snapshot,{...f.c,recovery:{}},skipped);
  check(selected.total_count,3,'verification recovery: only exact all-skipped first wave ignored');
  const duplicate=copy(snapshot);duplicate.workflow_runs.push({...oldGate,id:304});duplicate.total_count++;
  await rejects(()=>M.selectRecoveryRuns(duplicate,{...f.c,recovery:{}},skipped),'ambiguous_run','duplicate failed-wave origin');
  await rejects(()=>M.selectRecoveryRuns(snapshot,{...f.c,recovery:{}},async p=>{
    const x=await skipped(p);x.jobs[0].conclusion='success';return x;
  }),'original_wave_executed','executed notification or gate cannot replay');
  const unknown=copy(snapshot);unknown.workflow_runs[2].display_title='unknown';
  await rejects(()=>M.selectRecoveryRuns(unknown,{...f.c,recovery:{}},skipped),'untrusted_gate_origin','timing is not provenance');

  await rejects(()=>M.admitVerification({env:{...env,GITHUB_RUN_ID:'1e2'},api}),'untrusted_verification_context','malformed run identifier');
  await rejects(()=>M.stageStatus({...env,SMOKE_ATTEMPT:'unknown',DIAGNOSTICS_ATTEMPT:'1'}),'invalid_status_attempt','unconfirmed receipt attempt');
  const firstEnv={...env,GITHUB_RUN_ATTEMPT:'1'};
  const originalRunning={...copy(f.first),status:'in_progress',conclusion:null};
  const firstApi=async(method,path)=>{
    if(path.endsWith('/actions/runs/100'))return originalRunning;
    if(path.includes('/attempts/1/jobs?'))return {total_count:f.originals.length,jobs:copy(f.originals)};
    return api(method,path);
  };
  check((await M.admitVerification({env:firstEnv,api:firstApi})).run_check,true,'verification recovery: initial installed checks admitted');
  await rejects(()=>M.admitVerification({env:firstEnv,api:async(m,p)=>{
    if(p.includes('/attempts/1/jobs?')){const x=await firstApi(m,p);x.jobs.at(-1).conclusion='failure';return x;}
    return firstApi(m,p);
  }}),'installation_unconfirmed','failed provenance or installer cannot publish accepted telemetry');

  // Exercise the real terminal closer with a recovered deployment, both
  // provenance waves, original successful parents, and exactly one publisher.
  const gateRuns=M.REQUIRED.map(([file,event,name],i)=>({id:100+i,path:'.github/workflows/'+file,name,event,
    head_sha:source,head_branch:'main',head_repository:{full_name:repository},run_attempt:i===0?2:1,
    created_at:'2026-11-01T01:20:00Z',status:'completed',conclusion:'success'}));
  const monitor=gateRuns.find(r=>r.path.endsWith('/modeled-conversion-monitor.yml'));
  for(const r of gateRuns.filter(r=>r.event==='workflow_run'))r.display_title=r.path.endsWith('/'+M.ACTIVATION.file)?
    'monitor-proof:v1:'+monitor.id+':1':'deploy-proof:v1:100:2';
  for(const run of gateRuns.filter(r=>r.event==='workflow_run'))run.name=run.display_title;
  const own={id:200,path:M.CLOSER,name:'deploy-proof:v1:100:2',event:'workflow_run',
    display_title:'deploy-proof:v1:100:2',head_sha:source,head_branch:'main',head_repository:{full_name:repository},
    run_attempt:1,created_at:'2026-11-01T01:20:00Z',status:'in_progress',conclusion:null};
  const originals=gateRuns.filter(r=>r.event==='workflow_run').map(r=>({...copy(r),id:r.id+500,display_title:'deploy-proof:v1:100:1'}));
  originals.find(r=>r.path.endsWith('/'+M.ACTIVATION.file)).display_title='monitor-proof:v1:'+(monitor.id+500)+':1';
  for(const run of originals)run.name=run.display_title;
  const allRuns={workflow_runs:[...gateRuns,own,...originals],total_count:gateRuns.length+1+originals.length};
  let saved=copy(record),writes=0;
  const closerApi=async(method,path,body)=>{
    if(method==='PATCH'){writes++;saved={...saved,body:body.body};return copy(saved);}
    if(path.includes('/actions/runs?'))return copy(allRuns);
    if(path.includes('/issues/comments/'))return copy(saved);
    if(path.endsWith('/attempts/2'))return copy(f.current);
    if(path.includes('/jobs?filter=all'))return copy(f.history);
    if(path.includes('/actions/runs/200/attempts/1/jobs?'))return {total_count:1,jobs:[{name:'configure',run_id:200,head_sha:source,status:'completed',conclusion:'skipped'}]};
    const activation=gateRuns.find(r=>r.path.endsWith('/'+M.ACTIVATION.file));
    if(path.includes('/actions/runs/'+activation.id+'/attempts/1/jobs?'))return{total_count:1,jobs:[{name:'activate',run_id:activation.id,head_sha:source,status:'completed',conclusion:'skipped'}]};
    if(path.includes('/attempts/1/jobs?'))return skipped(path.replace(prefix,''));
    if(path.includes('/contents/deploy_status'))return deploy;
    if(path.includes('/contents/ops_status'))return Object.fromEntries(['migration','snapshot','live_sessions','daily_sessions','yesterday_sessions','architecture_inventory','website_smoke','health'].map(k=>[k,'success']).concat([['sha',source]]));
    return api(method,path);
  };
  const closeEnv={...env,GITHUB_EVENT_NAME:'workflow_run',GITHUB_RUN_ID:'200',GITHUB_RUN_ATTEMPT:'1',CONFIGURE_RESULT:'skipped'};
  const closeEvent={action:'completed',repository:{full_name:repository},
    workflow_run:{...copy(gateRuns[0]),head_commit:{id:source,message:'Product release'}}};
  check(await M.closeRelease({env:closeEnv,event:closeEvent,api:closerApi,sleep:async()=>{throw Error('unexpected wait');}}),
    'released','verification recovery: recovered run closes through real final guard');
  check(writes,1,'verification recovery: same publisher updated exactly once');

}

return verifyReleasePriority().then(verifyReleaseRecovery).then(verifyReleaseSlot).then(verifyReleaseSlotActivation).then(() => {
  const admission = spawnSync(process.execPath, [path.resolve(__dirname, '../tools/release_priority.js'),
    '--admit', '1306', 'a'.repeat(40), 'b'.repeat(40)], {
    encoding: 'utf8', env: { GITHUB_REPOSITORY: 'pyatkoff/max-search-bot' }
  });
  check(admission.status, 1, 'CLI admission without token stops before I/O');
  check(admission.stderr.includes('missing_read_capability'), true, 'CLI admits valid arguments to the guard');
  const invalid = spawnSync(process.execPath, [path.resolve(__dirname, '../tools/release_priority.js'), '--admit'],
    { encoding: 'utf8', env: {} });
  check(invalid.status, 1, 'incomplete CLI admission cannot fall back to CI success');
});
};
