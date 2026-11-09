#!/usr/bin/env node
'use strict';

// One read-only interpretation of the existing installation receipt and its
// repository-release extension. No deployment credential, API write or dispatch.
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const crypto = require('node:crypto');
const zlib = require('node:zlib');
const {spawnSync} = require('node:child_process');
const {performance} = require('node:perf_hooks');

const REPOSITORY = 'pyatkoff/max-search-bot';
const API_ROOT = '/repos/' + REPOSITORY;
const PREFIX = '.github/workflows/';
const DEPLOY = PREFIX + 'deploy.yml';
const CLOSER = PREFIX + 'modeled-conversion-value-export.yml';
const HOST = PREFIX + 'tour-selection-host.yml';
const RECORD_PATH = 'deploy_status.json';
const PUBLICATION_FILE = 'deployment-release-publication.json';
const PUBLICATION_PROTOCOL = 'anytour-deployment-publication-v1';
const FINGERPRINT_PROTOCOL = 'anytour-deployment-runtime-v1';
const CONTENT_POLICY = 'anytour-development-content-v1';
const RESOLVER_STEP = 'Resolve accepted repository and application';
const TERMINAL_STEP = 'Release only the admitted publisher after all original mandatory gates';
const PENDING_BINDING_STEP = 'Bind the published result to its original deployment run — ';
const TERMINAL_BINDING_STEP = 'Bind the terminal candidate to the original closer run — ';
const MAX_RECORD = 128 * 1024;
const MAX_API = 2 * 1024 * 1024;
const MAX_ARCHIVE = 4 * 1024 * 1024;
const INSTALL_STAGES = ['provenance','key','bundle','sync','migration','max_webhook',
  'metrika_cron','install_checks','smoke','diagnostics'];
const OPS_STAGES = ['migration','snapshot','live_sessions','daily_sessions','yesterday_sessions',
  'architecture_inventory','website_smoke','health'];
const DIRECT = new Set(['live-session-diagnostics.yml','max-production-health.yml','max-tls-preflight.yml',
  'modeled-conversion-monitor.yml','publish-conversation-diagnostics.yml','telegram-production-health.yml',
  'tour-selection-host.yml','modeled-conversion-value-export.yml'].map(p=>PREFIX+p));
const PUSH_OPERATIONS = new Set(['max-shadow-delivery.yml','repair-lead-receiver-config.yml',
  'restore-live-runtime.yml','restore-route-cache.yml','sync-legacy-lead-receiver.yml'].map(p=>PREFIX+p));
const MANUAL_OPERATIONS = new Set([...DIRECT,...PUSH_OPERATIONS,
  PREFIX+'max-owner-avatar-probe.yml',PREFIX+'max-owner-tour-preview.yml',PREFIX+'telegram-webhook-status.yml',
  PREFIX+'setup-appleservice39-connection.yml',PREFIX+'publish-appleservice39-pilot.yml']);
const SCHEDULES = new Map([[PREFIX+'live-session-diagnostics.yml','25 * * * *'],
  [PREFIX+'modeled-conversion-monitor.yml','2-59/5 * * * *']]);
const sha = v => typeof v === 'string' && /^[0-9a-f]{40}$/.test(v);
const digest64 = v => typeof v === 'string' && /^[0-9a-f]{64}$/.test(v);
const positive = v => (typeof v === 'string' && /^[1-9][0-9]{0,15}$/.test(v)
  && Number.isSafeInteger(Number(v))) || (Number.isSafeInteger(v) && v > 0);
const stamp = v => typeof v === 'string' && /^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\dZ$/.test(v)
  && Number.isFinite(Date.parse(v)) && new Date(v).toISOString().replace('.000Z','Z') === v;
class Refusal extends Error { constructor(code) { super(code); this.code = code; } }
function requireValue(ok, code) { if (!ok) throw new Refusal(code); }
function object(v) { return v !== null && typeof v === 'object' && !Array.isArray(v); }
function keys(v, expected) {
  return object(v) && Object.keys(v).sort().join(',') === [...expected].sort().join(',');
}
function canonical(v) {
  if (v === null || typeof v === 'string' || typeof v === 'boolean') return JSON.stringify(v);
  if (typeof v === 'number') {
    requireValue(Number.isFinite(v) && (!Number.isInteger(v) || Number.isSafeInteger(v)), 'invalid_number');
    return JSON.stringify(v);
  }
  if (Array.isArray(v)) return '[' + v.map(canonical).join(',') + ']';
  requireValue(object(v), 'invalid_json_value');
  return '{' + Object.keys(v).sort().map(k=>JSON.stringify(k)+':'+canonical(v[k])).join(',') + '}';
}
const wire = v => canonical(v) + '\n';
const digest = v => crypto.createHash('sha256').update(wire(v)).digest('hex');
const same = (a,b) => canonical(a) === canonical(b);
const clone = v => JSON.parse(canonical(v));
const iso = now => new Date(now()).toISOString().replace(/\.\d{3}Z$/,'Z');
function gitBlob(bytes) {
  return crypto.createHash('sha1').update(Buffer.from('blob '+bytes.length+'\0')).update(bytes).digest('hex');
}

// JSON.parse alone silently accepts duplicate authority fields. This bounded
// parser rejects duplicate keys, nonfinite numbers and excessive nesting.
function parseJson(input, limit = MAX_RECORD) {
  const bytes = Buffer.isBuffer(input) ? input : Buffer.from(String(input),'utf8');
  requireValue(bytes.length > 0 && bytes.length <= limit, 'json_size_limit');
  let text;
  try { text = new TextDecoder('utf-8',{fatal:true}).decode(bytes); }
  catch { throw new Refusal('invalid_json_encoding'); }
  let i=0,nodes=0;
  const white=()=>{while(i<text.length && /[\x20\t\r\n]/.test(text[i]))i++;};
  const string=()=>{
    const start=i++;
    while(i<text.length) {
      if(text[i]==='\\'){i+=2;continue;}
      if(text[i++]==='"') {
        try { return JSON.parse(text.slice(start,i)); } catch { throw new Refusal('invalid_json'); }
      }
    }
    throw new Refusal('invalid_json');
  };
  const value=depth=>{
    requireValue(depth<=32 && ++nodes<=20000,'json_structure_limit'); white();
    if(text[i]==='"')return string();
    if(text[i]==='{'){
      i++;white();const out=Object.create(null),seen=new Set();
      if(text[i]==='}'){i++;return out;}
      while(i<text.length){
        white();requireValue(text[i]==='"','invalid_json');const k=string();
        requireValue(!seen.has(k),'duplicate_json_key');seen.add(k);white();
        requireValue(text[i++]===':','invalid_json');out[k]=value(depth+1);white();
        if(text[i]==='}'){i++;return out;}
        requireValue(text[i++ ]===',','invalid_json');
      }
    } else if(text[i]==='['){
      i++;white();const out=[];
      if(text[i]===']'){i++;return out;}
      while(i<text.length){
        out.push(value(depth+1));white();if(text[i]===']'){i++;return out;}
        requireValue(text[i++ ]===',','invalid_json');
      }
    } else {
      for(const [literal,result] of [['true',true],['false',false],['null',null]])
        if(text.startsWith(literal,i)){i+=literal.length;return result;}
      const m=/^-?(?:0|[1-9][0-9]*)(?:\.[0-9]+)?(?:[eE][+-]?[0-9]+)?/.exec(text.slice(i));
      if(m){i+=m[0].length;const x=Number(m[0]);requireValue(Number.isFinite(x)
        && (!Number.isInteger(x)||Number.isSafeInteger(x)),'invalid_number');return x;}
    }
    throw new Refusal('invalid_json');
  };
  const out=value(0);white();requireValue(i===text.length,'invalid_json');return out;
}
function readJson(file) {
  let data;
  try {
    const stat=fs.lstatSync(file);
    requireValue(stat.isFile()&&!stat.isSymbolicLink()&&stat.size<=MAX_RECORD,'invalid_input_file');
    data=fs.readFileSync(file);
  } catch(e) { if(e instanceof Refusal)throw e;throw new Refusal('input_unavailable'); }
  return parseJson(data);
}
function saveJson(file, value) {
  const body=wire(value);requireValue(Buffer.byteLength(body)<=MAX_RECORD,'record_size_limit');
  fs.writeFileSync(file,body,{mode:0o600});
}
function installationReceipt(record, success = true) {
  requireValue(object(record),'invalid_installation_receipt');
  const receipt=clone(record);delete receipt.repository_release;
  requireValue(keys(receipt,['schema','sha','run_id','install_attempt','smoke_attempt','diagnostics_attempt','reported_at',...INSTALL_STAGES])
    &&receipt.schema===2&&sha(receipt.sha)&&positive(receipt.run_id)
    && typeof receipt.run_id==='string'&&receipt.install_attempt===1
    && [1,2].includes(receipt.smoke_attempt)&&[1,2].includes(receipt.diagnostics_attempt)
    && stamp(receipt.reported_at),'invalid_installation_receipt');
  requireValue(INSTALL_STAGES.every(s=>success?receipt[s]==='success':['success','failure'].includes(receipt[s])),
    'installation_unconfirmed');
  return receipt;
}
function component(value, editor) {
  const expected=['package_id','source_sha','backend_fingerprint','state_digest'];
  if(editor)expected.push('release_kind','acceptance');
  requireValue(keys(value,expected)&&digest64(value.package_id)&&sha(value.source_sha)
    &&digest64(value.backend_fingerprint)&&digest64(value.state_digest),'invalid_component_identity');
  if(editor)requireValue(['normal','common','independent'].includes(value.release_kind)
    && value.acceptance===(value.release_kind==='independent'?'independent_activated':'installed'),
    'unaccepted_editor_identity');
  return value;
}
function validateObservation(v, mode = null) {
  requireValue(keys(v,['schema','protocol','mode','status','reason_code','application_sha',
    'core_digest','configuration_key_id','components','digest'])
    &&v.schema===1&&v.protocol===FINGERPRINT_PROTOCOL&&['core','components','all'].includes(v.mode)
    &&(!mode||v.mode===mode)&&['known','unknown','blocked'].includes(v.status)&&sha(v.application_sha)
    &&keys(v.components,['public','editor']),'invalid_runtime_observation');
  if(v.status!=='known'){
    requireValue(typeof v.reason_code==='string'&&/^[a-z][a-z0-9_]{0,95}$/.test(v.reason_code)
      &&v.digest===null&&v.core_digest===null&&v.configuration_key_id===null
      &&v.components.public===null&&v.components.editor===null,'invalid_runtime_observation');return v;
  }
  requireValue(v.reason_code===null,'invalid_runtime_observation');
  if(v.mode==='components')requireValue(v.core_digest===null&&v.configuration_key_id===null,'invalid_runtime_observation');
  else requireValue(digest64(v.core_digest)&&digest64(v.configuration_key_id),'invalid_runtime_observation');
  if(v.mode==='core')requireValue(v.components.public===null&&v.components.editor===null,'invalid_runtime_observation');
  else {component(v.components.public,false);component(v.components.editor,true);}
  requireValue(v.digest===digest({schema:1,application_sha:v.application_sha,core_digest:v.core_digest,
    configuration_key_id:v.configuration_key_id,components:v.components}),'runtime_digest_mismatch');
  return v;
}
function joinObservations(core,components) {
  validateObservation(core,'core');validateObservation(components,'components');
  requireValue(core.status==='known'&&components.status==='known'
    &&core.application_sha===components.application_sha,'runtime_observation_unconfirmed');
  const result={...clone(core),mode:'all',components:clone(components.components)};
  result.digest=digest({schema:1,application_sha:result.application_sha,core_digest:result.core_digest,
    configuration_key_id:result.configuration_key_id,components:result.components});
  return validateObservation(result,'all');
}
function compareObservations(baseline,current) {
  validateObservation(baseline,'all');validateObservation(current,'all');
  requireValue(baseline.status==='known','baseline_observation_unconfirmed');
  if(current.status==='blocked')throw new Refusal('runtime_observation_blocked');
  if(current.status==='unknown')return {kind:'installed',reason:'runtime_observation_unknown'};
  requireValue(current.application_sha===baseline.application_sha,'installed_source_drift');
  requireValue(same(current.components.public,baseline.components.public),'public_component_drift');
  const editorSame=same(current.components.editor,baseline.components.editor),e=current.components.editor;
  requireValue(editorSame||(e.release_kind==='independent'&&e.acceptance==='independent_activated'
    &&e.backend_fingerprint===baseline.components.editor.backend_fingerprint),'editor_component_drift');
  // A benign configuration fallback must not hide a simultaneous confirmed
  // component conflict and authorize the ordinary installer to overwrite it.
  if(current.core_digest!==baseline.core_digest||current.configuration_key_id!==baseline.configuration_key_id)
    return {kind:'installed',reason:'runtime_configuration_changed'};
  return editorSame?{kind:'application_unchanged',reason:'confirmed_same_runtime',editor:'unchanged'}:
    {kind:'application_unchanged',reason:'accepted_independent_editor',editor:'accepted_independent_advance'};
}
function validateReference(ref) {
  requireValue(keys(ref,['commit','blob','record_digest','producer_run_id','producer_run_attempt'])
    &&sha(ref.commit)&&sha(ref.blob)&&digest64(ref.record_digest)&&positive(ref.producer_run_id)
    &&[1,2].includes(ref.producer_run_attempt),'invalid_baseline_reference');return ref;
}
function terminalBody(kind,pr) {
  requireValue(positive(pr)&&['installed','application_unchanged'].includes(kind),'invalid_terminal');
  return 'PR'+pr+(kind==='installed'?': установлено, выпуск свободен':': приложение без изменений, выпуск свободен');
}
function validateRelease(record, success = true) {
  const installation=installationReceipt(record,success),r=record.repository_release;
  if(r?.schema===2)return validateTerminalRecovery(record,installation);
  requireValue(keys(r,['schema','kind','phase','repository_sha','application_sha','release','baseline',
    'content','intent_scan','observation','observation_producer','stages','baseline_eligible','closure'])
    &&r.schema===1&&['installed','application_unchanged'].includes(r.kind)
    &&['pending_closure','pending_terminal'].includes(r.phase)&&sha(r.repository_sha)&&sha(r.application_sha)
    &&r.application_sha===installation.sha&&keys(r.release,['run_id','run_attempt','event'])
    &&positive(r.release.run_id)&&typeof r.release.run_id==='string'&&[1,2].includes(r.release.run_attempt)
    &&['push','workflow_dispatch'].includes(r.release.event)
    &&typeof r.baseline_eligible==='boolean','invalid_repository_release');
  requireValue(object(r.content)&&r.content.policy===CONTENT_POLICY&&sha(r.content.installed_sha)
    &&r.content.candidate_sha===r.repository_sha
    &&object(r.intent_scan)&&['no_registered_intent','deployment_intent_present','explicit_intent','unknown']
      .includes(r.intent_scan.status),'invalid_release_content');
  if(r.kind==='installed'){
    requireValue(r.application_sha===r.repository_sha&&r.release.run_id===installation.run_id
      &&r.baseline===null,'installed_release_mismatch');
  } else {
    validateReference(r.baseline);
    requireValue(r.repository_sha!==r.application_sha&&r.release.event==='push'&&r.release.run_attempt===1
      &&r.content.installed_sha===r.application_sha&&r.content.release_path==='content_candidate'
      &&['identical_contents','development_only'].includes(r.content.classification)
      &&digest64(r.content.runtime_fingerprint)&&r.intent_scan.status==='no_registered_intent'
      &&r.baseline_eligible===false,'unchanged_release_mismatch');
  }
  requireValue(keys(r.stages,INSTALL_STAGES),'invalid_stage_dispositions');
  for(const s of INSTALL_STAGES)requireValue(same(r.stages[s],r.kind==='installed'
    ?{execution:'performed',result:installation[s]}:['provenance','key'].includes(s)
      ?{execution:'performed',result:'success'}:{execution:'not_performed',assurance:'inherited'}),
    'invalid_stage_dispositions');
  if(r.observation!==null){
    validateObservation(r.observation,'all');
    requireValue(r.observation.application_sha===r.application_sha&&object(r.observation_producer)
      &&positive(r.observation_producer.run_id)&&[1,2].includes(r.observation_producer.run_attempt),
      'observation_source_mismatch');
  } else requireValue(r.observation_producer===null&&r.kind==='installed'&&!r.baseline_eligible,
    'observation_missing');
  if(r.kind==='application_unchanged')requireValue(r.observation.status==='known','runtime_observation_unconfirmed');
  if(r.baseline_eligible)requireValue(r.kind==='installed'&&r.phase==='pending_terminal'
    &&r.release.event==='push'&&r.intent_scan.status==='no_registered_intent'
    &&r.observation?.status==='known','ineligible_baseline');
  if(r.phase==='pending_closure')requireValue(r.closure===null&&!r.baseline_eligible,'premature_terminal');
  else {
    const c=r.closure;
    requireValue(keys(c,['run_id','run_attempt','job','terminal_step','binding','expected_terminal','gates'])
      &&positive(c.run_id)&&typeof c.run_id==='string'&&c.run_attempt===1&&c.job==='release-slot'
      &&c.terminal_step===TERMINAL_STEP&&keys(c.binding,['pr','head_sha','comment_id','owner_id','updated_at'])
      &&positive(c.binding.pr)&&sha(c.binding.head_sha)&&positive(c.binding.comment_id)
      &&positive(c.binding.owner_id)&&stamp(c.binding.updated_at)
      &&c.expected_terminal===terminalBody(r.kind,c.binding.pr)&&Array.isArray(c.gates)
      &&c.gates.length>=9&&c.gates.length<=20,'invalid_terminal_proposal');
    for(const g of c.gates)requireValue(keys(g,['path','run_id','run_attempt','head_sha','conclusion'])
      &&typeof g.path==='string'&&g.path.startsWith(PREFIX)&&positive(g.run_id)&&[1,2].includes(g.run_attempt)
      &&g.head_sha===r.repository_sha&&['success','skipped'].includes(g.conclusion),'invalid_gate_reference');
  }
  return {record,installation,release:r};
}

function runIdentity(run,{id,attempt,head,workflow,event,completed=true}) {
  requireValue(object(run)&&String(run.id)===String(id)&&run.run_attempt===attempt&&run.path===workflow
    &&run.head_sha===head&&run.head_branch==='main'&&run.head_repository?.full_name===REPOSITORY
    &&(!event||run.event===event)&&stamp(run.created_at),'untrusted_workflow_run');
  if(completed)requireValue(run.status==='completed'&&run.conclusion==='success','producer_not_successful');
  else requireValue(['queued','in_progress','waiting','pending','requested','completed'].includes(run.status)
    &&(run.status==='completed'?run.conclusion==='success':run.conclusion===null),'producer_not_active');
  return run;
}
function boundedList(v,field,code) {
  requireValue(object(v)&&Array.isArray(v[field])&&Number.isSafeInteger(v.total_count)
    &&v.total_count===v[field].length&&v.total_count<100
    &&new Set(v[field].map(x=>x.id)).size===v[field].length,code);return v[field];
}
async function readRecord(api,commit=null) {
  if(commit===null){
    const ref=await api('GET',API_ROOT+'/git/ref/heads/diagnostics');
    requireValue(sha(ref?.object?.sha),'diagnostics_ref_unavailable');commit=ref.object.sha;
  }
  requireValue(sha(commit),'invalid_diagnostics_ref');
  const item=await api('GET',API_ROOT+'/contents/'+RECORD_PATH+'?ref='+commit);
  requireValue(item?.type==='file'&&item.path===RECORD_PATH&&item.encoding==='base64'
    &&sha(item.sha)&&Number.isSafeInteger(item.size)&&item.size>0&&item.size<=MAX_RECORD
    &&typeof item.content==='string','invalid_diagnostics_content');
  const encoded=item.content.replace(/\n/g,'');
  requireValue(/^[A-Za-z0-9+/]*={0,2}$/.test(encoded),'invalid_diagnostics_encoding');
  const bytes=Buffer.from(encoded,'base64');
  requireValue(bytes.length===item.size&&bytes.toString('base64')===encoded
    &&gitBlob(bytes)===item.sha,'diagnostics_blob_mismatch');
  const record=parseJson(bytes);
  return {record,commit,blob:item.sha,record_digest:digest(record)};
}
async function requireAncestry(api,base,head) {
  if(base===head)return;
  const c=await api('GET',API_ROOT+'/compare/'+base+'...'+head+'?per_page=1');
  requireValue(['ahead','identical'].includes(c?.status)&&c.merge_base_commit?.sha===base,
    'unpublished_diagnostics_proof');
}
function publicationName(phase,id,attempt) {
  requireValue(['pending_closure','pending_terminal'].includes(phase)&&positive(id)
    &&[1,2].includes(attempt),'invalid_publication_context');
  return 'deployment-release-'+(phase==='pending_closure'?'pending':'terminal')+'-'+id+'-'+attempt;
}
function publicationProof(record,location,producerId,producerAttempt) {
  const {release:r}=validateRelease(record,false);
  requireValue(sha(location.commit)&&sha(location.blob),'invalid_publication_location');
  return {schema:1,protocol:PUBLICATION_PROTOCOL,phase:r.phase,repository:REPOSITORY,
    repository_sha:r.repository_sha,application_sha:r.application_sha,
    release_run_id:r.release.run_id,release_run_attempt:r.release.run_attempt,
    producer_run_id:String(producerId),producer_run_attempt:producerAttempt,
    commit:location.commit,blob:location.blob,record_digest:digest(record)};
}
function validatePublication(p,record,producerOverride=null) {
  requireValue(keys(p,['schema','protocol','phase','repository','repository_sha','application_sha',
    'release_run_id','release_run_attempt','producer_run_id','producer_run_attempt','commit','blob','record_digest'])
    &&p.schema===1&&p.protocol===PUBLICATION_PROTOCOL&&p.repository===REPOSITORY
    &&sha(p.commit)&&sha(p.blob)&&digest64(p.record_digest),'invalid_publication_proof');
  const r=validateRelease(record).release;
  const producer=producerOverride||(r.phase==='pending_closure'?r.release:r.closure);
  requireValue(p.phase===r.phase&&p.repository_sha===r.repository_sha&&p.application_sha===r.application_sha
    &&p.release_run_id===r.release.run_id&&p.release_run_attempt===r.release.run_attempt
    &&p.producer_run_id===producer.run_id&&p.producer_run_attempt===producer.run_attempt
    &&p.record_digest===digest(record),'publication_binding_mismatch');return p;
}

// Read one named JSON member without extracting a caller-controlled ZIP path.
function crc32(bytes) {
  let crc=0xffffffff;
  for(const x of bytes){crc^=x;for(let k=0;k<8;k++)crc=(crc>>>1)^((crc&1)?0xedb88320:0);}
  return (crc^0xffffffff)>>>0;
}
function archiveJson(bytes,wanted) {
  requireValue(Buffer.isBuffer(bytes)&&bytes.length>=22&&bytes.length<=MAX_ARCHIVE,'artifact_size_limit');
  let end=-1;
  for(let i=bytes.length-22;i>=Math.max(0,bytes.length-65557);i--)
    if(bytes.readUInt32LE(i)===0x06054b50&&i+22+bytes.readUInt16LE(i+20)===bytes.length){end=i;break;}
  requireValue(end>=0&&bytes.readUInt16LE(end+4)===0&&bytes.readUInt16LE(end+6)===0
    &&bytes.readUInt16LE(end+8)===bytes.readUInt16LE(end+10),'invalid_artifact_zip');
  const count=bytes.readUInt16LE(end+10),size=bytes.readUInt32LE(end+12),start=bytes.readUInt32LE(end+16);
  requireValue(count>0&&count<=64&&start+size===end,'invalid_artifact_zip');
  let at=start,found=null;const names=new Set();
  for(let n=0;n<count;n++){
    requireValue(at+46<=end&&bytes.readUInt32LE(at)===0x02014b50,'invalid_artifact_zip');
    const flags=bytes.readUInt16LE(at+8),method=bytes.readUInt16LE(at+10),
      compressed=bytes.readUInt32LE(at+20),uncompressed=bytes.readUInt32LE(at+24),
      length=bytes.readUInt16LE(at+28),extra=bytes.readUInt16LE(at+30),comment=bytes.readUInt16LE(at+32),
      local=bytes.readUInt32LE(at+42),mode=(bytes.readUInt32LE(at+38)>>>16)&0xf000;
    requireValue(!(flags&1)&&[0,8].includes(method)&&at+46+length+extra+comment<=end
      &&mode!==0xa000,'invalid_artifact_member');
    const name=bytes.subarray(at+46,at+46+length).toString('utf8');
    requireValue(name.length>0&&!name.startsWith('/')&&!name.includes('\\')
      &&!name.split('/').some(x=>x==='.'||x==='..')&&!names.has(name),'invalid_artifact_member');
    names.add(name);
    if(name===wanted){
      requireValue(found===null&&uncompressed<=MAX_RECORD&&compressed<=MAX_ARCHIVE
        &&local+30<=start&&bytes.readUInt32LE(local)===0x04034b50,'invalid_artifact_member');
      const localLength=bytes.readUInt16LE(local+26),localExtra=bytes.readUInt16LE(local+28);
      const begin=local+30+localLength+localExtra;
      requireValue(bytes.subarray(local+30,local+30+localLength).toString('utf8')===wanted
        &&begin+compressed<=start&&bytes.readUInt16LE(local+8)===method,'invalid_artifact_member');
      let decoded;
      try {decoded=method===0?bytes.subarray(begin,begin+compressed)
        :zlib.inflateRawSync(bytes.subarray(begin,begin+compressed),{maxOutputLength:MAX_RECORD});}
      catch{throw new Refusal('invalid_artifact_member');}
      requireValue(decoded.length===uncompressed&&crc32(decoded)===bytes.readUInt32LE(at+16),'artifact_checksum_mismatch');
      found=parseJson(decoded);
    }
    at+=46+length+extra+comment;
  }
  requireValue(at===end&&found!==null,'artifact_member_missing');return found;
}
async function artifactJson(api,meta,member,run,expectedName) {
  requireValue(positive(meta?.id)&&meta.name===expectedName&&meta.expired===false
    &&Number.isSafeInteger(meta.size_in_bytes)&&meta.size_in_bytes>0&&meta.size_in_bytes<=MAX_ARCHIVE
    &&meta.workflow_run?.id===run.id&&meta.workflow_run.head_sha===run.head_sha
    &&meta.workflow_run.head_branch==='main'&&typeof meta.digest==='string'
    &&/^sha256:[0-9a-f]{64}$/.test(meta.digest)&&stamp(meta.created_at)
    &&Date.parse(meta.created_at)>=Date.parse(run.run_started_at||run.created_at),'untrusted_artifact');
  requireValue(typeof api.artifact==='function','artifact_read_capability_missing');
  const bytes=await api.artifact(meta);
  requireValue(Buffer.isBuffer(bytes)&&bytes.length===meta.size_in_bytes
    &&crypto.createHash('sha256').update(bytes).digest('hex')===meta.digest.slice(7),'artifact_digest_mismatch');
  return archiveJson(bytes,member);
}
async function findArtifact(api,run,name,knownId=null) {
  if(knownId!==null)return api('GET',API_ROOT+'/actions/artifacts/'+knownId);
  const rows=boundedList(await api('GET',API_ROOT+'/actions/runs/'+run.id+'/artifacts?per_page=100'),
    'artifacts','incomplete_artifacts');
  const matches=rows.filter(a=>a.name===name);
  requireValue(matches.length===1,'publication_artifact_missing');return matches[0];
}
async function requirePublicationStep(api,run,r,recordDigest,producerAttempt,closed) {
  const actual=boundedList(await api('GET',API_ROOT+'/actions/runs/'+run.id+
    '/attempts/'+producerAttempt+'/jobs?per_page=100'),'jobs','incomplete_publication_jobs');
  const own=actual.filter(j=>j.name===(r.phase==='pending_terminal'?'release-slot':'verify'));
  requireValue(own.length===1&&own[0].run_id===run.id&&own[0].head_sha===r.repository_sha
    &&Array.isArray(own[0].steps)&&((closed||r.phase==='pending_closure'||run.status==='completed')
      ?own[0].status==='completed'&&(own[0].conclusion==='success'||(producerAttempt===1&&run.run_attempt===2&&own[0].conclusion==='failure'))
      :own[0].status==='in_progress'&&own[0].conclusion===null),'publication_job_unconfirmed');
  const named=(r.phase==='pending_terminal'?TERMINAL_BINDING_STEP:PENDING_BINDING_STEP)+recordDigest;
  const steps=own[0].steps.filter(s=>s.name===named);
  requireValue(steps.length===1&&steps[0].status==='completed'&&steps[0].conclusion==='success',
    'publication_step_unconfirmed');
  if(closed){
    const terminal=own[0].steps.filter(s=>s.name===TERMINAL_STEP);
    requireValue(terminal.length===1&&terminal[0].status==='completed'&&terminal[0].conclusion==='success'
      &&Number.isSafeInteger(steps[0].number)&&steps[0].number<terminal[0].number,'terminal_step_unconfirmed');
  }
}
async function authenticatePublication(api,location,{closed=false,diagnosticsHead=null,metadataWait=null}={}) {
  if(location.record.repository_release?.schema===2)
    return authenticateTerminalRecovery(api,location,{closed,diagnosticsHead});
  const {record}=location,{release:r}=validateRelease(record);
  let producer=r.phase==='pending_closure'?r.release:r.closure,run;
  if(r.phase==='pending_closure'&&r.kind==='installed'){
    const completed=await deploymentProof(api,installationReceipt(record),{metadataWait});run=completed.run;
    producer={run_id:r.release.run_id,run_attempt:completed.publicationAttempt};
  }else{
    run=await api('GET',API_ROOT+'/actions/runs/'+producer.run_id);
    runIdentity(run,{id:producer.run_id,attempt:producer.run_attempt,head:r.repository_sha,
      workflow:r.phase==='pending_closure'?DEPLOY:CLOSER,event:r.phase==='pending_closure'?r.release.event:'workflow_run',
      completed:r.phase==='pending_closure'||closed});
  }
  if(r.phase==='pending_terminal'){
    const origin='deploy-proof:v1:'+r.release.run_id+':'+r.release.run_attempt;
    requireValue(run.display_title===origin||(run.display_title===undefined
      &&run.name==='Configure AnyTour modeled value goal'),'terminal_origin_mismatch');
  }
  requireValue(location.record_digest===digest(record),'publication_digest_mismatch');
  // The original successful upload step binds the digest into retained Jobs
  // metadata. Artifact expiry alone cannot invalidate an accepted installation.
  // Run deletion, changed metadata or a missing exact step remain UNKNOWN.
  await requirePublicationStep(api,run,r,location.record_digest,producer.run_attempt,closed);
  await requireAncestry(api,location.commit,diagnosticsHead||location.commit);
  if(closed)requireValue(r.phase==='pending_terminal','release_not_closed');
  return {commit:location.commit,blob:location.blob,record_digest:location.record_digest,
    producer_run_id:producer.run_id,producer_run_attempt:producer.run_attempt};
}
async function readBaseline(api,ref,diagnosticsHead) {
  validateReference(ref);
  const location=await readRecord(api,ref.commit),r=validateRelease(location.record).release;
  requireValue(location.blob===ref.blob&&location.record_digest===ref.record_digest
    &&r.kind==='installed'&&r.phase==='pending_terminal'&&r.baseline_eligible===true,
    'unaccepted_installation_baseline');
  const verified=await authenticatePublication(api,location,{closed:true,diagnosticsHead});
  requireValue(same(verified,ref),'baseline_producer_changed');
  const install=await deploymentProof(api,installationReceipt(location.record));
  requireValue(install.run.event==='push','untrusted_baseline_event');
  return {location,release:r,installation:installationReceipt(location.record),reference:verified};
}

async function responseBytes(response,limit) {
  const length=response.headers?.get('content-length');
  if(length!==null&&length!==undefined)requireValue(/^[0-9]+$/.test(length)&&Number(length)<=limit,'response_size_limit');
  if(response.body?.getReader){
    const reader=response.body.getReader(),parts=[];let size=0;
    while(true){const {done,value}=await reader.read();if(done)break;size+=value.length;
      if(size>limit){await reader.cancel();throw new Refusal('response_size_limit');}parts.push(Buffer.from(value));}
    return Buffer.concat(parts,size);
  }
  const bytes=Buffer.from(await response.arrayBuffer());requireValue(bytes.length<=limit,'response_size_limit');return bytes;
}
function makeReadApi(token,fetchImpl=globalThis.fetch) {
  requireValue(typeof token==='string'&&token.length>0&&typeof fetchImpl==='function','missing_capability');
  const request=async(url,headers,redirect='error',limit=MAX_API,signal=null)=>{
    const controller=new AbortController(),timer=setTimeout(()=>controller.abort(),20000);
    const abort=()=>controller.abort();
    if(signal){if(signal.aborted)abort();else signal.addEventListener('abort',abort,{once:true});}
    try{
      const response=await fetchImpl(url,{method:'GET',redirect,signal:controller.signal,headers});
      if(redirect==='manual'&&[301,302,303,307,308].includes(response.status))return response;
      requireValue(response.ok,'api_http_'+(Number.isInteger(response.status)?response.status:'unknown'));
      return await responseBytes(response,limit);
    }catch(e){if(e instanceof Refusal)throw e;throw new Refusal('transport_failed');}
    finally{clearTimeout(timer);signal?.removeEventListener('abort',abort);}
  };
  const headers=raw=>({Authorization:'Bearer '+token,Accept:raw?'application/vnd.github.raw+json':'application/vnd.github+json',
    'X-GitHub-Api-Version':'2022-11-28'});
  const api=async(method,p,body,raw=false,signal=null)=>{
    requireValue(method==='GET'&&body===undefined&&typeof p==='string'&&p.startsWith(API_ROOT+'/')
      &&!/[\r\n\\#]/.test(p),'invalid_read_request');
    return parseJson(await request('https://api.github.com'+p,headers(raw),'error',MAX_API,signal),MAX_API);
  };
  api.artifact=async meta=>{
    requireValue(positive(meta?.id),'invalid_artifact');
    const response=await request('https://api.github.com'+API_ROOT+'/actions/artifacts/'+meta.id+'/zip',headers(false),'manual',MAX_ARCHIVE);
    if(Buffer.isBuffer(response))return response;
    let url;try{url=new URL(response.headers.get('location'));}catch{throw new Refusal('invalid_artifact_redirect');}
    requireValue(url.protocol==='https:'&&!url.username&&!url.password&&!url.port&&!url.hash
      &&(url.hostname.endsWith('.blob.core.windows.net')||url.hostname.endsWith('.actions.githubusercontent.com')
        ||url.hostname==='github.com'),'invalid_artifact_redirect');
    // The signed storage URL is untrusted transport data, never an API authority.
    // Do not forward the repository token to it or follow another redirect.
    return request(url.href,{},'error',MAX_ARCHIVE);
  };
  api.jobLog=async job=>{
    requireValue(job===LATE_RECOVERY.closer_job,'untrusted_log_job');
    let bytes=await request('https://api.github.com'+API_ROOT+'/actions/jobs/'+job+'/logs',headers(false),'manual');
    if(!Buffer.isBuffer(bytes)){
      let url;try{url=new URL(bytes.headers.get('location'));}catch{throw new Refusal('invalid_log_redirect');}
      requireValue(url.protocol==='https:'&&!url.username&&!url.password&&!url.port&&!url.hash
        &&(url.hostname.endsWith('.blob.core.windows.net')||url.hostname.endsWith('.actions.githubusercontent.com')
          ||url.hostname==='github.com'),'invalid_log_redirect');
      bytes=await request(url.href,{},'error');
    }
    try{return new TextDecoder('utf-8',{fatal:true}).decode(bytes);}
    catch{throw new Refusal('invalid_log_encoding');}
  };
  return api;
}
function workflowPath(env) {
  const prefix=REPOSITORY+'/',suffix='@refs/heads/main';
  requireValue(typeof env.GITHUB_WORKFLOW_REF==='string'&&env.GITHUB_WORKFLOW_REF.startsWith(prefix)
    &&env.GITHUB_WORKFLOW_REF.endsWith(suffix),'untrusted_workflow_ref');
  const p=env.GITHUB_WORKFLOW_REF.slice(prefix.length,-suffix.length);
  requireValue(/^\.github\/workflows\/[a-z0-9-]+\.yml$/.test(p),'untrusted_workflow_ref');return p;
}
async function sourceContext(env,event,api,expectedWorkflow=null) {
  requireValue(env.GITHUB_REPOSITORY===REPOSITORY&&env.GITHUB_REF==='refs/heads/main'
    &&sha(env.GITHUB_SHA)&&positive(env.GITHUB_RUN_ID)&&['1','2'].includes(env.GITHUB_RUN_ATTEMPT)
    &&event?.repository?.full_name===REPOSITORY,'untrusted_context');
  const workflow=workflowPath(env);
  requireValue(!expectedWorkflow||workflow===expectedWorkflow,'untrusted_workflow');
  const c={sha:env.GITHUB_SHA,id:String(env.GITHUB_RUN_ID),attempt:Number(env.GITHUB_RUN_ATTEMPT),
    event:env.GITHUB_EVENT_NAME,workflow,job:env.GITHUB_JOB};
  requireValue((await api('GET',API_ROOT+'/git/ref/heads/main'))?.object?.sha===c.sha,'main_changed');
  const run=await api('GET',API_ROOT+'/actions/runs/'+c.id);
  runIdentity(run,{id:c.id,attempt:c.attempt,head:c.sha,workflow,event:c.event,completed:false});
  requireValue(run.status==='in_progress','workflow_not_active');
  return {...c,run};
}
function installationStepResult(close,job,name) {
  try{return close.stepResult(job,name);}
  catch(error){
    // This pure shared reader has one typed refusal. Do not echo an arbitrary
    // transport error's code or turn it into metadata that may be retried.
    if(error instanceof Error&&error.constructor.name==='Refusal'&&error.code==='incomplete_job_steps')
      throw new Refusal('incomplete_job_steps');
    throw error;
  }
}
function installedMetadataWait(api,c,parent,{sleep,now,startedAt}) {
  if(parent.event!=='push'||parent.run_attempt!==1)return null;
  let remaining=2,waited=false,identity=null,timer=null;
  const controller=new AbortController();
  const elapsed=()=>now()-startedAt;
  const check=()=>{if(waited)requireValue(elapsed()>=0&&elapsed()<30000,'deployment_metadata_deadline');};
  const bounded=async action=>{
    check();
    if(!waited)return action();
    clearTimeout(timer);timer=setTimeout(()=>controller.abort(),30000-elapsed());
    let abort;
    const expired=new Promise((_,reject)=>{
      abort=()=>reject(new Refusal('deployment_metadata_deadline'));
      if(controller.signal.aborted)abort();else controller.signal.addEventListener('abort',abort,{once:true});
    });
    try{const result=await Promise.race([expired,action()]);check();return result;}
    finally{controller.signal.removeEventListener('abort',abort);}
  };
  const read=(method,p,body,raw=false)=>bounded(()=>api(method,p,body,raw,waited?controller.signal:null));
  return {
    check,api:read,close(){clearTimeout(timer);},
    bind(run,jobs){
      requireValue(String(run.id)===String(parent.id)&&run.run_attempt===1
        &&run.event===parent.event&&run.head_sha===c.sha,'deployment_metadata_changed');
      const current=jobs.map(j=>({id:j.id,name:j.name,run_id:j.run_id,head_sha:j.head_sha}));
      requireValue(current.every(j=>positive(j.id)),'installation_job_unconfirmed');
      if(identity===null)identity=current;
      else requireValue(same(identity,current),'deployment_metadata_changed');
    },
    async retry(){
      requireValue(remaining>0&&elapsed()>=0&&elapsed()+10000<30000,'deployment_metadata_deadline');
      remaining--;waited=true;await bounded(()=>sleep(10000));
      requireValue((await read('GET',API_ROOT+'/git/ref/heads/main'))?.object?.sha===c.sha,'main_changed');
    }
  };
}
async function deploymentProof(api,receipt,{completed=true,admitting=false,metadataWait=null}={}) {
  requireValue(!metadataWait||(completed&&!admitting),'invalid_metadata_wait');
  const close=require('./release_slot_close');
  let run;
  while(true){
  metadataWait?.check();
  run=await api('GET',API_ROOT+'/actions/runs/'+receipt.run_id);
  metadataWait?.check();
  if(metadataWait)requireValue(run?.run_attempt===1,'deployment_metadata_changed');
  requireValue([1,2].includes(run?.run_attempt),'invalid_deployment_attempt');
  runIdentity(run,{id:receipt.run_id,attempt:run.run_attempt,head:receipt.sha,workflow:DEPLOY,
    completed,event:run.event});
  requireValue(['push','workflow_dispatch'].includes(run.event),'untrusted_deployment_event');
  if(run.run_attempt===1){
    requireValue(receipt.smoke_attempt===1&&receipt.diagnostics_attempt===1,'invalid_verification_attempt');
    // A successful Deploy can now mean a proven no-install. Legacy schema 2
    // remains usable only when its original job metadata proves real execution.
    const jobs=boundedList(await api('GET',API_ROOT+'/actions/runs/'+run.id+'/attempts/1/jobs?per_page=100'),
      'jobs','incomplete_installation_jobs');
    metadataWait?.check();
    const required=[];
    for(const name of [...close.INSTALL_JOBS,'verify']){
      const matches=jobs.filter(j=>j.name===name);
      requireValue(matches.length===1&&matches[0].run_id===run.id&&matches[0].head_sha===receipt.sha
        &&matches[0].status==='completed'&&matches[0].conclusion==='success','installation_job_unconfirmed');
      required.push(matches[0]);
    }
    metadataWait?.bind(run,required);
    const deployment=jobs.find(j=>j.name==='deploy'),verification=jobs.find(j=>j.name==='verify');
    const steps=['Build exact deployment bundle','Sync canonical production checkout',
      'Apply database migrations and fence the old hotel contour','Reconcile canonical MAX webhook ownership',
      'Ensure Metrika offline uploader cron','Apply profile and retain exact diagnostic snapshot']
      .map(name=>[deployment,name,'installation_not_performed'])
      .concat(['check','download','status'].map(key=>[verification,close.VERIFY_STEPS[key],'verification_not_performed']));
    let pending=false;
    for(const [job,name,failure]of steps){
      const rows=Array.isArray(job.steps)?job.steps.filter(s=>s?.name===name):[];
      if(metadataWait&&rows.length===1&&rows[0].status==='in_progress'&&rows[0].conclusion===null)pending=true;
      else requireValue(installationStepResult(close,job,name)==='success',failure);
    }
    // Inspect every required row first: pending never hides a failed, missing,
    // duplicate or unknown step. The same budget is shared with publication.
    if(pending){await metadataWait.retry();continue;}
    return {run,attempt:1,publicationAttempt:1};
  }
  break;
  }
  requireValue(run.event==='push','manual_recovery_forbidden');
  const get=p=>api('GET',API_ROOT+p),c={sha:receipt.sha,deploy:Number(receipt.run_id)};
  const first=await get('/actions/runs/'+receipt.run_id+'/attempts/1');
  const history=await get('/actions/runs/'+receipt.run_id+'/jobs?filter=all&per_page=100');
  const recovered=close.deploymentHistory(history,first,run,c,admitting);
  requireValue(receipt.smoke_attempt===recovered.smokeAttempt&&receipt.diagnostics_attempt===recovered.diagnosticsAttempt,
    'recovery_receipt_mismatch');
  return {run,attempt:2,recovery:recovered,
    publicationAttempt:recovered.plan.run_publication?2:1};
}
async function requireOps(api,applicationSha) {
  const ops=await api('GET',API_ROOT+'/contents/ops_status.json?ref=diagnostics',undefined,true);
  requireValue(object(ops)&&ops.sha===applicationSha&&OPS_STAGES.every(s=>ops[s]==='success'),'diagnostics_unconfirmed');
  return ops;
}
function v2(repository,receipt,c,event) {
  const dir=fs.mkdtempSync(path.join(os.tmpdir(),'anytour-release-'));
  try{
    const receiptFile=path.join(dir,'receipt.json'),eventFile=path.join(dir,'event.json');
    saveJson(receiptFile,receipt);
    saveJson(eventFile,{repository:REPOSITORY,event_name:c.event,ref:'refs/heads/main',sha:c.sha,
      run_id:c.id,run_attempt:c.attempt,forced:c.event==='push'?event.forced===true:false,inputs:event.inputs||{}});
    const safeEnv=Object.fromEntries(Object.entries(process.env).filter(([k])=>!k.startsWith('GIT_')&&!k.startsWith('PYTHON')));
    // -I omits ambient PYTHONPATH. A fixed trusted launcher adds only the current
    // deployment tools directory, then calls the existing V2 implementation.
    const code='import sys;sys.path.insert(0,sys.argv[1]);from deployment_release_preview import main;sys.argv=sys.argv[1:];raise SystemExit(main())';
    const q=spawnSync('python3',['-I','-c',code,__dirname,'--repository',repository,'--installed-receipt',receiptFile,
      '--event-context',eventFile,'--candidate-sha',c.sha],{env:safeEnv,encoding:'utf8',maxBuffer:MAX_API,timeout:120000});
    requireValue([0,2].includes(q.status)&&!q.error,'content_plan_unavailable');
    const result=parseJson(q.stdout,MAX_API);
    requireValue(result?.protocol==='anytour-development-release-preview-v1'
      &&['blocked','ordinary_release_required','no_install_candidate'].includes(result.decision)
      &&result.skip_installation_authorized===false&&result.production_observed===false
      &&result.release_slot_mutation_authorized===false&&result.publication_performed===false,'invalid_content_plan');
    return result;
  }finally{fs.rmSync(dir,{recursive:true,force:true});}
}
function intentScan(repository,installed,candidate) {
  requireValue(sha(installed)&&sha(candidate),'invalid_intent_range');
  const code='import sys,json;sys.path.insert(0,sys.argv[1]);from pathlib import Path;from deployment_content_plan import GitObjects,scan_release_intents;print(json.dumps(scan_release_intents(GitObjects(Path(sys.argv[2])),sys.argv[3],sys.argv[4]),sort_keys=True))';
  const p=spawnSync('python3',['-I','-c',code,__dirname,repository,installed,candidate],
    {env:Object.fromEntries(Object.entries(process.env).filter(([k])=>!k.startsWith('GIT_')&&!k.startsWith('PYTHON'))),
      encoding:'utf8',maxBuffer:MAX_RECORD,timeout:30000});
  if(p.status!==0||p.error)return {status:'unknown'};
  try{const v=parseJson(p.stdout);if(['no_registered_intent','deployment_intent_present'].includes(v.status))return v;}catch{}
  return {status:'unknown'};
}
function contentSummary(comparison,installed,candidate) {
  return {policy:CONTENT_POLICY,installed_sha:installed,candidate_sha:candidate,
    classification:comparison?.classification||'unconfirmed',release_path:comparison?.release_path||'full_required',
    runtime_fingerprint:comparison?.candidate_runtime_fingerprint||null};
}
function releaseRecord(installation,c,{baseline=null,content,intent,observation=null,kind='installed'}) {
  const record=clone(installation),stages={};
  for(const s of INSTALL_STAGES)stages[s]=kind==='installed'
    ?{execution:'performed',result:record[s]}:['provenance','key'].includes(s)
      ?{execution:'performed',result:'success'}:{execution:'not_performed',assurance:'inherited'};
  record.repository_release={schema:1,kind,phase:'pending_closure',repository_sha:c.sha,application_sha:record.sha,
    release:{run_id:c.id,run_attempt:c.attempt,event:c.event},baseline,content,intent_scan:intent,
    observation,observation_producer:observation?{run_id:c.id,run_attempt:c.attempt}:null,
    stages,baseline_eligible:false,closure:null};
  validateRelease(record,false);return record;
}
async function acceptedBaseline(api,location) {
  const r=validateRelease(location.record).release;
  if(r.schema===2){await authenticateTerminalRecovery(api,location,{closed:true});return null;}
  if(r.kind==='installed'&&!r.baseline_eligible)return null;
  requireValue(r.phase==='pending_terminal','baseline_release_pending');
  const reference=await authenticatePublication(api,location,{closed:true});
  if(r.kind==='installed'){
    if(!r.baseline_eligible)return null;
    return readBaseline(api,reference,location.commit);
  }
  const baseline=await readBaseline(api,r.baseline,location.commit);
  requireValue(same(installationReceipt(location.record),baseline.installation),'installation_receipt_changed');
  requireValue(compareObservations(baseline.release.observation,r.observation).kind==='application_unchanged',
    'baseline_runtime_mismatch');
  return baseline;
}
async function planDeployment({env,event,api,repository=process.cwd(),compare=v2}) {
  const c=await sourceContext(env,event,api,DEPLOY);
  requireValue(c.attempt===1&&c.job==='deploy'&&['push','workflow_dispatch'].includes(c.event),'untrusted_deployment_context');
  if(c.event==='push')requireValue(event.ref==='refs/heads/main'&&event.after===c.sha&&typeof event.forced==='boolean','untrusted_push');
  const recovery=await recoveryForSource(api,c.sha),marked=hasRecoveryMessage(c.run);
  if(recovery||marked){
    requireValue(recovery&&marked,'recovery_message_missing');
    requireValue(c.event==='push'&&event.forced===false&&!Object.keys(event.inputs||{}).length,
      'untrusted_recovery_deploy');
    return planLateRecovery(api,c,recovery);
  }
  const location=await readRecord(api),result={schema:1,protocol:'anytour-deployment-plan-v1',repository:REPOSITORY,
    repository_sha:c.sha,run_id:c.id,run_attempt:1,event:c.event,plan:'install',application_sha:c.sha,
    source:{commit:location.commit,blob:location.blob,record_digest:location.record_digest},
    baseline:null,installation:null,content:contentSummary(null,c.sha,c.sha),intent_scan:{status:'unknown'}};
  if(c.event==='workflow_dispatch'||event.forced||Object.keys(event.inputs||{}).length){
    result.intent_scan={status:'explicit_intent'};return result;
  }
  let receipt;
  // A first deployment or old failed receipt is not an optimization baseline.
  // A typed record, however, must be valid: malformed authority never downgrades.
  if(location.record.repository_release){
    validateRelease(location.record,false);
    if(location.record.repository_release.schema===2){
      await authenticateTerminalRecovery(api,location,{closed:true});
      await requireAncestry(api,location.record.repository_release.repository_sha,c.sha);
    }
    if(!INSTALL_STAGES.every(s=>location.record[s]==='success'))return result;
    receipt=installationReceipt(location.record);
  }else{
    try{receipt=installationReceipt(location.record);}catch(e){if(!(e instanceof Refusal))throw e;return result;}
  }
  const preview=compare(repository,receipt,c,event);
  requireValue(preview.decision!=='blocked','content_plan_unconfirmed');
  result.content=contentSummary(preview.comparison,receipt.sha,c.sha);
  result.intent_scan=intentScan(repository,receipt.sha,c.sha);
  if(preview.decision!=='no_install_candidate'||!location.record.repository_release)return result;
  const baseline=await acceptedBaseline(api,location);
  if(!baseline)return result;
  requireValue(result.intent_scan.status==='no_registered_intent','intent_scan_changed');
  await requireCurrentBinding(api,c.sha);
  return {...result,plan:'observe_unchanged',application_sha:receipt.sha,baseline:baseline.reference,
    installation:receipt};
}
async function requireCurrentBinding(api,source) {
  const b=await currentBinding(api,source);
  const close=require('./release_slot_close');
  requireValue(b&&close.recordState(await api('GET',API_ROOT+'/issues/comments/'+b.comment_id),b)==='active','record_not_active');
  return b;
}
async function currentBinding(api,source) {
  const close=require('./release_slot_close'),pulls=await api('GET',API_ROOT+'/commits/'+source+'/pulls?per_page=100');
  requireValue(Array.isArray(pulls)&&pulls.length<100,'incomplete_pulls');
  const selected=pulls.filter(p=>p.merge_commit_sha===source&&p.state==='closed');
  requireValue(selected.length===1,'ambiguous_pull');
  const b=close.binding(await api('GET',API_ROOT+'/pulls/'+selected[0].number),source);
  return b;
}
async function admitUnchanged({env,event,api,plan,observation,repository=process.cwd(),compare=v2}) {
  requireValue(object(plan)&&plan.schema===1&&plan.protocol==='anytour-deployment-plan-v1'
    &&plan.plan==='observe_unchanged','invalid_saved_plan');
  const fresh=await planDeployment({env,event,api,repository,compare});
  requireValue(same(fresh,plan),'deployment_plan_changed');
  validateObservation(observation,'all');
  const baseline=await readBaseline(api,plan.baseline,plan.source.commit);
  const outcome=compareObservations(baseline.release.observation,observation);
  if(outcome.kind==='installed')return {kind:'installed',record:null};
  const c={sha:plan.repository_sha,id:plan.run_id,attempt:1,event:'push'};
  const record=releaseRecord(plan.installation,c,{baseline:plan.baseline,content:plan.content,
    intent:plan.intent_scan,observation:clone(observation),kind:'application_unchanged'});
  return {kind:'application_unchanged',record};
}
async function wrapInstalled({env,event,api,installation,repository=process.cwd(),now=Date.now}) {
  const c=await sourceContext(env,event,api,DEPLOY);
  requireValue(c.job==='verify'&&['push','workflow_dispatch'].includes(c.event)
    &&(c.event==='push'||c.attempt===1),'untrusted_verification_context');
  const raw=clone(installation);delete raw.repository_release;
  requireValue(raw.sha===c.sha&&raw.run_id===c.id&&!raw.reported_at,'installation_source_mismatch');
  raw.reported_at=iso(now);installationReceipt(raw,false);
  // The full path needs no accepted optimization baseline. Prove only whether
  // this actual installation may later become one; unknown/marker intent is false.
  let before=c.sha;const previous=await readRecord(api);
  if(sha(previous.record.sha))before=previous.record.sha;
  // A rewritten attempt-2 status may already have replaced the old installed
  // SHA with this run's failed receipt. Do not shrink A..R into R..R and lose an
  // earlier marker. Recovery still closes normally; only a new baseline is lost.
  const intent=c.attempt===2?{status:'unknown'}:
    c.event==='push'&&!event.forced&&!Object.keys(event.inputs||{}).length
      ?intentScan(repository,before,c.sha):{status:'explicit_intent'};
  return releaseRecord(raw,c,{content:contentSummary(null,before,c.sha),intent});
}

async function verifyUnchanged(api,location,{closed=false}={}) {
  const {release:r,installation}=validateRelease(location.record);
  requireValue(r.kind==='application_unchanged','not_unchanged_release');
  await authenticatePublication(api,location,{closed});
  const baseline=await readBaseline(api,r.baseline,location.commit);
  requireValue(same(installation,baseline.installation),'installation_receipt_changed');
  requireValue(compareObservations(baseline.release.observation,r.observation).kind==='application_unchanged',
    'baseline_runtime_mismatch');
  requireValue(String(r.observation_producer.run_id)===r.release.run_id&&r.observation_producer.run_attempt===1,
    'runtime_observer_mismatch');
  return {release:r,installation,baseline};
}
function resolution(r,a,kind,perform=true){return {repository_sha:r,application_sha:a,kind,perform_actions:perform,ready:true};}
async function pendingDeployment(api,source) {
  const rows=boundedList(await api('GET',API_ROOT+'/actions/workflows/deploy.yml/runs?head_sha='+source+'&branch=main&per_page=100'),
    'workflow_runs','incomplete_runs').filter(r=>r.path===DEPLOY);
  requireValue(rows.length===1,'unclassified_pending_release');
  const r=rows[0];
  runIdentity(r,{id:r.id,attempt:r.run_attempt,head:source,workflow:DEPLOY,event:r.event,completed:false});
  requireValue(['push','workflow_dispatch'].includes(r.event),'untrusted_deployment_event');
  requireValue(r.status!=='completed','pending_deployment_not_active');
  return r;
}
async function confirmPendingRecord(api,location) {
  const r=validateRelease(location.record).release;
  const run=await api('GET',API_ROOT+'/actions/runs/'+r.release.run_id);
  runIdentity(run,{id:r.release.run_id,attempt:run.run_attempt,head:r.repository_sha,workflow:DEPLOY,
    event:r.release.event,completed:false});
  requireValue([1,2].includes(run.run_attempt)&&(r.kind!=='application_unchanged'||run.run_attempt===1),
    'untrusted_pending_deployment');
  if(run.status==='completed'){
    await authenticatePublication(api,location);
    const runs=boundedList(await api('GET',API_ROOT+'/actions/workflows/modeled-conversion-value-export.yml/runs?head_sha='
      +r.repository_sha+'&event=workflow_run&per_page=100'),
      'workflow_runs','incomplete_runs');
    const closers=runs.filter(x=>x.path===CLOSER&&x.event==='workflow_run'
      &&x.display_title==='deploy-proof:v1:'+r.release.run_id+':'+run.run_attempt);
    requireValue(closers.length===1,'pending_closer_unconfirmed');
    runIdentity(closers[0],{id:closers[0].id,attempt:1,head:r.repository_sha,workflow:CLOSER,event:'workflow_run',completed:false});
    requireValue(closers[0].status!=='completed','pending_terminal_unconfirmed');
  }
}
async function resolveCurrent(api,c,location,{defer=false}={}) {
  const pending=()=>{if(defer)return {repository_sha:c.sha,application_sha:'',kind:'',perform_actions:false,ready:false};
    throw new Refusal('release_pending');};
  if(!location.record.repository_release){
    const receipt=installationReceipt(location.record);
    if(receipt.sha!==c.sha){await pendingDeployment(api,c.sha);return pending();}
    await deploymentProof(api,receipt);await requireOps(api,c.sha);
    return resolution(c.sha,c.sha,'installed');
  }
  const {release:r,installation}=validateRelease(location.record);
  if(r.repository_sha!==c.sha){await pendingDeployment(api,c.sha);return pending();}
  if(r.schema===2){
    const producer=await api('GET',API_ROOT+'/actions/runs/'+r.closure.run_id);
    if(producer.status!=='completed'){
      runIdentity(producer,{id:r.closure.run_id,attempt:1,head:c.sha,workflow:CLOSER,event:'workflow_run',completed:false});
      return pending();
    }
    await authenticateTerminalRecovery(api,location,{closed:true});
    return resolution(c.sha,installation.sha,'terminal_recovery');
  }
  if(r.kind==='application_unchanged'){
    if(r.phase!=='pending_terminal'){await confirmPendingRecord(api,location);return pending();}
    const closer=await api('GET',API_ROOT+'/actions/runs/'+r.closure.run_id);
    runIdentity(closer,{id:r.closure.run_id,attempt:1,head:c.sha,workflow:CLOSER,event:'workflow_run',completed:false});
    if(closer.status!=='completed')return pending();
    await verifyUnchanged(api,location,{closed:true});return resolution(c.sha,r.application_sha,r.kind);
  }
  // Explicit full Deploy has no release-slot job. Its actual receipt plus original
  // successful verification remains usable, but can never seed an unchanged path.
  if(r.release.event==='workflow_dispatch'){
    await deploymentProof(api,installation);await requireOps(api,c.sha);
    await authenticatePublication(api,location);return resolution(c.sha,c.sha,'installed');
  }
  if(r.phase!=='pending_terminal'){
    if(await currentBinding(api,c.sha)===null){
      await deploymentProof(api,installation);await requireOps(api,c.sha);
      await authenticatePublication(api,location);return resolution(c.sha,c.sha,'installed');
    }
    await confirmPendingRecord(api,location);
    return pending();
  }
  const closer=await api('GET',API_ROOT+'/actions/runs/'+r.closure.run_id);
  runIdentity(closer,{id:r.closure.run_id,attempt:1,head:c.sha,workflow:CLOSER,event:'workflow_run',completed:false});
  if(closer.status!=='completed')return pending();
  await authenticatePublication(api,location,{closed:true});await deploymentProof(api,installation);
  return resolution(c.sha,c.sha,'installed');
}
async function finalResolution(api,c,location,result) {
  if(location){
    const current=await readRecord(api);
    requireValue(current.blob===location.blob&&current.record_digest===location.record_digest,'deployment_result_changed');
  }
  requireValue((await api('GET',API_ROOT+'/git/ref/heads/main'))?.object?.sha===c.sha,'main_changed');
  return result;
}
async function resolveConsumer({env,event,api,sleep=ms=>new Promise(resolve=>setTimeout(resolve,ms)),now=()=>performance.now()}) {
  const startedAt=now();
  const c=await sourceContext(env,event,api);
  requireValue(c.attempt===1,'consumer_rerun_forbidden');
  const recovery=await recoveryForSource(api,c.sha),marked=hasRecoveryMessage(c.run);
  requireValue((!recovery&&!marked)||(recovery&&marked),'recovery_message_missing');
  if(recovery&&c.event==='workflow_run'){
    requireValue(DIRECT.has(c.workflow)&&event.action==='completed'
      &&c.run.display_title==='deploy-proof:v1:'+event.workflow_run?.id+':1',
      'untrusted_recovery_consumer');
    const p=await readRecoveryPlan(api,event.workflow_run);
    requireValue(p.repository_sha===c.sha,'recovery_parent_changed');
    requireValue(same(recovery.spec,p.intent),'recovery_intent_changed');
    await recoverySourceProof(api,c.sha,recovery);
    return finalResolution(api,c,null,resolution(c.sha,p.application_sha,'terminal_recovery',false));
  }
  if(c.event==='push'){
    requireValue(PUSH_OPERATIONS.has(c.workflow)&&event.ref==='refs/heads/main'&&event.after===c.sha,
      'untrusted_push_consumer');return finalResolution(api,c,null,resolution(c.sha,c.sha,'installed'));
  }
  requireValue((c.event==='workflow_run'&&DIRECT.has(c.workflow))
    ||(c.event==='workflow_dispatch'&&MANUAL_OPERATIONS.has(c.workflow))
    ||(c.event==='schedule'&&SCHEDULES.get(c.workflow)===event.schedule)
    ||(c.event==='issue_comment'&&c.workflow===PREFIX+'max-owner-tour-preview.yml'&&c.job==='owner-send'),
    'untrusted_consumer_event');
  const location=await readRecord(api);
  if(c.event!=='workflow_run')return finalResolution(api,c,location,
    await resolveCurrent(api,c,location,{defer:c.event==='schedule'}));
  const parent=event.workflow_run;
  requireValue(event.action==='completed'&&parent?.name==='Deploy production'
    &&[1,2].includes(parent.run_attempt),'untrusted_parent');
  runIdentity(parent,{id:parent.id,attempt:parent.run_attempt,head:c.sha,workflow:DEPLOY,event:parent.event});
  requireValue(['push','workflow_dispatch'].includes(parent.event)
    &&c.run.display_title==='deploy-proof:v1:'+parent.id+':'+parent.run_attempt,'untrusted_parent_origin');
  const receipt=installationReceipt(location.record);
  if(!location.record.repository_release){
    requireValue(receipt.sha===c.sha&&String(receipt.run_id)===String(parent.id),'installation_source_mismatch');
    const metadataWait=installedMetadataWait(api,c,parent,{sleep,now,startedAt});
    const proofApi=metadataWait?.api||api;
    try{
      await deploymentProof(proofApi,receipt,{metadataWait});
      return await finalResolution(proofApi,c,location,resolution(c.sha,c.sha,'installed'));
    }finally{metadataWait?.close();}
  }
  const r=validateRelease(location.record).release;
  requireValue(r.repository_sha===c.sha&&r.release.run_id===String(parent.id),'superseded_deployment_result');
  if(r.kind==='application_unchanged'){
    requireValue(parent.run_attempt===1&&r.release.run_attempt===1,'unchanged_recovery_forbidden');
    await verifyUnchanged(api,location);return finalResolution(api,c,location,resolution(c.sha,r.application_sha,r.kind,false));
  }
  const metadataWait=installedMetadataWait(api,c,parent,{sleep,now,startedAt});
  const proofApi=metadataWait?.api||api;
  try{
    await deploymentProof(proofApi,receipt,{metadataWait});await authenticatePublication(proofApi,location,{metadataWait});
    return await finalResolution(proofApi,c,location,resolution(c.sha,c.sha,'installed'));
  }finally{metadataWait?.close();}
}
async function resolveEditor({env,event,api,repositorySha,applicationSha}) {
  const c=await sourceContext(env,event,api,HOST);
  requireValue(c.event==='workflow_dispatch'&&c.job==='independent-editor'&&c.attempt===1
    &&repositorySha===c.sha&&sha(applicationSha),'untrusted_editor_context');
  const location=await readRecord(api),result=await resolveCurrent(api,c,location);
  requireValue(result.ready&&result.application_sha===applicationSha,'editor_application_mismatch');
  await finalResolution(api,c,location,result);
  return {repository_sha:c.sha,application_sha:applicationSha,installation_receipt:installationReceipt(location.record)};
}
async function hostObservation(api,run,applicationSha) {
  runIdentity(run,{id:run.id,attempt:1,head:applicationSha,workflow:HOST,event:'workflow_run'});
  const name='selection-host-'+applicationSha,meta=await findArtifact(api,run,name);
  const observation=validateObservation(await artifactJson(api,meta,'runtime-fingerprint.json',run,name),'all');
  requireValue(observation.application_sha===applicationSha,'host_observation_source_mismatch');
  return {observation,producer:{run_id:String(run.id),run_attempt:1}};
}

// Finite repair of PR1420's expired closer. This is NOT a generic no-install
// exemption or permission to retry an old workflow.
const LATE_RECOVERY=Object.freeze({
  application:'5c18f7a49e1fba15e58f7e6a266c2b3a5e7527f2',pr:1420,
  deploy:42599160592,closer:42599880179,closer_job:129101425849,
  monitor:42599880395,activation:42603556603,activation_job:129113619145,
  comment:6682640708,owner:226193297,version:'2026-11-09T10:10:40Z',
  head:'a0f0ac2fd5a9f1dcafe08eb717ae66654b2d7f24',
  gates:[42599160592,42599160646,42599880210,42599880201,42599880261,
    42599880370,42599880338,42599880087,42599880395,42603556603],
});
const RECOVERY_PATHS=Object.freeze(['tools/deployment_release.js','tools/release_slot_close.js',
  '.github/workflows/deploy.yml','tests/release_lifecycle_regression.js',
  'tests/run_deployment_release_regression.js','tests/check_ci_workflow_scopes.py',
  'tests/run_production_gate_sequence_regression.py','docs/RELEASE_PRIORITY.md'].sort());
const RECOVERY_PLAN_PROTOCOL='anytour-pr1420-closure-recovery-v1';
const RECOVERY_PLAN_STEP='Bind the recovery plan to its original maintenance run — ';
function hasRecoveryMessage(run) {
  const text=run?.head_commit?.message;
  if(typeof text!=='string'||!text.toLowerCase().includes('anytour-pr1420-closure-recovery'))return false;
  requireValue(text.includes('[anytour-pr1420-closure-recovery-v1]')&&run.head_commit.id===run.head_sha,
    'invalid_recovery_message');return true;
}
function recoverySpec(pr,source) {
  const body=pr?.body;
  if(typeof body!=='string'||!body.includes('release-slot-recovery:'))return null;
  requireValue(body.length<=65536&&pr.merged===true&&pr.state==='closed'
    &&pr.merge_commit_sha===source&&positive(pr.number)&&pr.number!==LATE_RECOVERY.pr
    &&pr.base?.ref==='main'&&pr.base.repo?.full_name===REPOSITORY
    &&pr.head?.repo?.full_name===REPOSITORY&&sha(pr.head.sha),'untrusted_recovery_pull');
  const matches=[...body.matchAll(/<!-- release-slot-recovery:v1 (\{[^\r\n]*\}) -->/g)];
  requireValue((body.match(/release-slot-recovery:/g)||[]).length===1&&matches.length===1,
    'invalid_recovery_intent');
  const spec=parseJson(matches[0][1],4096);
  requireValue(keys(spec,['source_head','base_sha','changes_digest','diagnostics'])
    &&spec.source_head===pr.head.sha&&spec.base_sha===LATE_RECOVERY.application
    &&digest64(spec.changes_digest)&&keys(spec.diagnostics,['commit','blob','record_digest'])
    &&sha(spec.diagnostics.commit)&&sha(spec.diagnostics.blob)&&digest64(spec.diagnostics.record_digest),
    'invalid_recovery_intent');
  return clone(spec);
}
async function recoveryForSource(api,source) {
  const rows=await api('GET',API_ROOT+'/commits/'+source+'/pulls?per_page=100');
  requireValue(Array.isArray(rows)&&rows.length<100,'incomplete_pulls');
  const merged=rows.filter(p=>p.merge_commit_sha===source&&p.state==='closed');
  if(!merged.length)return null;
  requireValue(merged.length===1,'ambiguous_pull');
  const pr=await api('GET',API_ROOT+'/pulls/'+merged[0].number);
  const spec=recoverySpec(pr,source);return spec?{pr,spec}:null;
}
async function recoverySourceProof(api,source,admitted) {
  const {pr,spec}=admitted;
  requireValue(same(recoverySpec(pr,source),spec),'recovery_intent_changed');
  const q=await api('GET',API_ROOT+'/git/commits/'+source);
  const h=await api('GET',API_ROOT+'/git/commits/'+spec.source_head);
  requireValue(q?.sha===source&&h?.sha===spec.source_head&&q.tree?.sha===h.tree?.sha
    &&Array.isArray(q.parents)&&q.parents.length===2
    &&q.parents[0].sha===spec.base_sha&&q.parents[1].sha===spec.source_head,
    'recovery_merge_changed');
  // Immutable Git trees provide complete mode/path/blob coverage without a
  // caller-controlled filesystem or a broad tools/tests/workflow exemption.
  const base=await api('GET',API_ROOT+'/git/commits/'+spec.base_sha);
  requireValue(base?.sha===spec.base_sha&&sha(base.tree?.sha)&&sha(q.tree?.sha),'recovery_base_changed');
  const trees=await Promise.all([base.tree.sha,q.tree.sha].map(async s=>{
    const t=await api('GET',API_ROOT+'/git/trees/'+s+'?recursive=1');
    requireValue(t?.truncated===false&&Array.isArray(t.tree)&&t.tree.length<50000,
      'incomplete_recovery_tree');
    const map=new Map();
    for(const e of t.tree){
      requireValue(e&&typeof e.path==='string'&&!map.has(e.path)&&sha(e.sha)
        &&['tree','blob','commit'].includes(e.type),'invalid_recovery_tree');
      map.set(e.path,e);
    }return map;
  }));
  const changed=[];
  for(const p of [...new Set([...trees[0].keys(),...trees[1].keys()])].sort()){
    const a=trees[0].get(p),b=trees[1].get(p);
    if(a?.sha===b?.sha&&a?.mode===b?.mode&&a?.type===b?.type)continue;
    // Directory tree hashes necessarily change with their admitted children.
    if(a?.type==='tree'&&b?.type==='tree'&&a.mode==='040000'&&b.mode==='040000')continue;
    requireValue(RECOVERY_PATHS.includes(p)&&a?.type==='blob'&&b?.type==='blob'
      &&a.mode==='100644'&&b.mode==='100644','recovery_content_out_of_scope');
    changed.push({path:p,old_mode:a.mode,old_blob:a.sha,new_mode:b.mode,new_blob:b.sha});
  }
  requireValue(same(changed.map(e=>e.path),RECOVERY_PATHS)&&digest(changed)===spec.changes_digest,
    'recovery_manifest_changed');
  const compare=await api('GET',API_ROOT+'/compare/'+spec.base_sha+'...'+source+'?per_page=100');
  requireValue(compare?.status==='ahead'&&compare.merge_base_commit?.sha===spec.base_sha
    &&Array.isArray(compare.commits)&&compare.commits.length===compare.total_commits
    &&compare.commits.length>0&&compare.commits.length<100,'incomplete_recovery_commits');
  const forbidden=/\[(?:modeled-pipeline-monitor-activation-v1|anytour-value-launch-1288|anytour-value-history-1293|anytour-value-history-fortnight-v1)\]/i;
  requireValue(compare.commits.every(x=>typeof x.commit?.message==='string'
    &&x.commit.message.length<=65536&&!forbidden.test(x.commit.message)),'recovery_business_intent');
  requireValue(typeof q.message==='string'&&q.message.includes('[anytour-pr1420-closure-recovery-v1]'),
    'recovery_message_missing');
  return changed;
}
function validateRecoveryPlan(p) {
  requireValue(keys(p,['schema','protocol','repository','repository_sha','run_id','run_attempt',
    'plan','application_sha','maintenance_pr','intent','original','binding'])
    &&p.schema===1&&p.protocol===RECOVERY_PLAN_PROTOCOL&&p.repository===REPOSITORY
    &&sha(p.repository_sha)&&positive(p.run_id)&&typeof p.run_id==='string'&&p.run_attempt===1
    &&p.plan==='terminal_recovery'&&p.application_sha===LATE_RECOVERY.application
    &&positive(p.maintenance_pr)&&p.maintenance_pr!==LATE_RECOVERY.pr
    &&keys(p.intent,['source_head','base_sha','changes_digest','diagnostics'])
    &&sha(p.intent.source_head)&&p.intent.base_sha===p.application_sha&&digest64(p.intent.changes_digest)
    &&keys(p.intent.diagnostics,['commit','blob','record_digest'])
    &&sha(p.intent.diagnostics.commit)&&sha(p.intent.diagnostics.blob)&&digest64(p.intent.diagnostics.record_digest),
    'invalid_recovery_plan');
  validateReference(p.original);
  requireValue(p.original.commit===p.intent.diagnostics.commit&&p.original.blob===p.intent.diagnostics.blob
    &&p.original.record_digest===p.intent.diagnostics.record_digest
    &&String(p.original.producer_run_id)===String(LATE_RECOVERY.deploy)&&p.original.producer_run_attempt===1
    &&keys(p.binding,['pr','head_sha','comment_id','owner_id','updated_at'])
    &&p.binding.pr===LATE_RECOVERY.pr&&p.binding.head_sha===LATE_RECOVERY.head
    &&p.binding.comment_id===LATE_RECOVERY.comment&&p.binding.owner_id===LATE_RECOVERY.owner
    &&p.binding.updated_at===LATE_RECOVERY.version,'recovery_original_changed');
  return p;
}
async function originalRecoveryRecord(api,plan,{current=false}={}) {
  validateRecoveryPlan(plan);
  const loc=await readRecord(api,current?null:plan.original.commit);
  requireValue(loc.commit===plan.original.commit&&loc.blob===plan.original.blob
    &&loc.record_digest===plan.original.record_digest,'recovery_diagnostics_changed');
  const r=validateRelease(loc.record).release;
  requireValue(r.schema===1&&r.kind==='installed'&&r.phase==='pending_closure'
    &&r.repository_sha===LATE_RECOVERY.application&&r.application_sha===r.repository_sha
    &&r.release.run_id===String(LATE_RECOVERY.deploy)&&r.release.run_attempt===1
    &&r.release.event==='push'&&r.closure===null&&!r.baseline_eligible,
    'recovery_original_changed');
  await authenticatePublication(api,loc);
  await requireOps(api,LATE_RECOVERY.application);
  return loc;
}
async function planLateRecovery(api,c,admitted) {
  requireValue(c.attempt===1&&c.job==='deploy'&&c.event==='push','untrusted_recovery_deploy');
  await recoverySourceProof(api,c.sha,admitted);
  const p={schema:1,protocol:RECOVERY_PLAN_PROTOCOL,repository:REPOSITORY,
    repository_sha:c.sha,run_id:c.id,run_attempt:1,plan:'terminal_recovery',
    application_sha:LATE_RECOVERY.application,maintenance_pr:admitted.pr.number,intent:admitted.spec,
    original:{...admitted.spec.diagnostics,producer_run_id:String(LATE_RECOVERY.deploy),producer_run_attempt:1},
    binding:{pr:LATE_RECOVERY.pr,head_sha:LATE_RECOVERY.head,comment_id:LATE_RECOVERY.comment,
      owner_id:LATE_RECOVERY.owner,updated_at:LATE_RECOVERY.version}};
  const loc=await originalRecoveryRecord(api,p,{current:true});
  const close=require('./release_slot_close');
  const old=await api('GET',API_ROOT+'/pulls/'+LATE_RECOVERY.pr);
  requireValue(same(close.bindingSnapshot(close.binding(old,LATE_RECOVERY.application)),p.binding),
    'recovery_original_binding_changed');
  requireValue(close.recordState(await api('GET',API_ROOT+'/issues/comments/'+LATE_RECOVERY.comment),
    close.binding(old,LATE_RECOVERY.application))==='active','recovery_slot_not_active');
  requireValue(same(installationReceipt(loc.record).sha,p.application_sha),'recovery_original_changed');
  return validateRecoveryPlan(p);
}
const RECOVERY_SERVER_STEPS=['Prepare canonical SSH private key',
  'Select installation or prove the unchanged application without mutation',
  'Build exact deployment bundle','Sync canonical production checkout',
  'Apply database migrations and fence the old hotel contour','Reconcile canonical MAX webhook ownership',
  'Ensure Metrika offline uploader cron','Apply profile and retain exact diagnostic snapshot'];
async function verifyRecoveryDeploy(api,run,p) {
  validateRecoveryPlan(p);
  runIdentity(run,{id:p.run_id,attempt:1,head:p.repository_sha,workflow:DEPLOY,event:'push'});
  const close=require('./release_slot_close');
  const jobs=boundedList(await api('GET',API_ROOT+'/actions/runs/'+run.id+'/attempts/1/jobs?per_page=100'),
    'jobs','incomplete_recovery_jobs');
  for(const name of close.INSTALL_JOBS){
    const matches=jobs.filter(j=>j.name===name);
    requireValue(matches.length===1&&matches[0].run_id===run.id&&matches[0].run_attempt===1
      &&matches[0].head_sha===p.repository_sha&&matches[0].status==='completed'
      &&matches[0].conclusion==='success','recovery_job_unconfirmed');
  }
  const d=jobs.find(j=>j.name==='deploy'),verify=jobs.filter(j=>j.name==='verify');
  requireValue(verify.length===1&&verify[0].run_id===run.id&&verify[0].head_sha===p.repository_sha
    &&verify[0].run_attempt===1&&verify[0].status==='completed'&&verify[0].conclusion==='skipped'
    &&Array.isArray(verify[0].steps)&&verify[0].steps.length===0,'recovery_verify_executed');
  for(const name of RECOVERY_SERVER_STEPS)requireValue(close.stepResult(d,name)==='skipped',
    'recovery_server_step_executed');
  for(const name of ['Admit only the finite metadata recovery',RECOVERY_PLAN_STEP+digest(p),
    'Fail failed installation'])requireValue(close.stepResult(d,name)==='success','recovery_plan_unbound');
  return jobs;
}
async function readRecoveryPlan(api,parent) {
  runIdentity(parent,{id:parent.id,attempt:1,head:parent.head_sha,workflow:DEPLOY,event:'push'});
  const name='closure-recovery-plan-'+parent.id+'-1';
  const meta=await findArtifact(api,parent,name);
  const p=validateRecoveryPlan(await artifactJson(api,meta,'release-plan.json',parent,name));
  requireValue(p.run_id===String(parent.id)&&p.repository_sha===parent.head_sha,'recovery_parent_changed');
  await verifyRecoveryDeploy(api,parent,p);return p;
}
function validateTerminalRecovery(record,installation) {
  const r=record.repository_release;
  requireValue(keys(r,['schema','kind','phase','repository_sha','application_sha','release','recovery_plan',
    'stages','baseline_eligible','closure'])
    &&r.schema===2&&r.kind==='terminal_recovery'&&r.phase==='pending_terminal'
    &&sha(r.repository_sha)&&r.application_sha===LATE_RECOVERY.application
    &&r.repository_sha!==r.application_sha&&installation.sha===r.application_sha
    &&installation.run_id===String(LATE_RECOVERY.deploy)&&r.baseline_eligible===false,
    'invalid_terminal_recovery');
  const p=validateRecoveryPlan(r.recovery_plan);
  requireValue(p.repository_sha===r.repository_sha&&same(r.release,{run_id:p.run_id,run_attempt:1,event:'push'})
    &&keys(r.stages,INSTALL_STAGES)&&INSTALL_STAGES.every(s=>same(r.stages[s],
      {execution:'not_performed',assurance:'original_installation'})),'recovery_installation_rewritten');
  const c=r.closure;
  requireValue(keys(c,['run_id','run_attempt','job','terminal_step','binding','expected_terminal','gates',
    'failed_closer','failure_log_digest'])
    &&positive(c.run_id)&&typeof c.run_id==='string'&&c.run_attempt===1&&c.job==='release-slot'
    &&c.terminal_step===TERMINAL_STEP&&same(c.binding,p.binding)
    &&c.expected_terminal===terminalBody('installed',LATE_RECOVERY.pr)
    &&same(c.failed_closer,{run_id:String(LATE_RECOVERY.closer),run_attempt:1,job_id:LATE_RECOVERY.closer_job,
      conclusion:'failure',reason:'gate_deadline'})&&digest64(c.failure_log_digest)
    &&Array.isArray(c.gates)&&c.gates.length===LATE_RECOVERY.gates.length,
    'invalid_recovery_closure');
  const close=require('./release_slot_close');
  c.gates.forEach((g,i)=>requireValue(keys(g,['path','run_id','run_attempt','head_sha','conclusion'])
    &&String(g.run_id)===String(LATE_RECOVERY.gates[i])&&g.path===PREFIX+close.REQUIRED[i][0]
    &&g.run_attempt===1&&g.head_sha===LATE_RECOVERY.application
    &&(i===9?['success','skipped'].includes(g.conclusion):g.conclusion==='success'),'invalid_recovery_gate'));
  return {record,installation,release:r};
}
async function authenticateTerminalRecovery(api,location,{closed=false,diagnosticsHead=null}={}) {
  const {record,release:r}=validateRelease(location.record),p=r.recovery_plan;
  requireValue(r.schema===2,'not_terminal_recovery');
  const admitted=await recoveryForSource(api,r.repository_sha);
  requireValue(admitted&&admitted.pr.number===p.maintenance_pr&&same(admitted.spec,p.intent),
    'recovery_intent_changed');
  await recoverySourceProof(api,r.repository_sha,admitted);
  const old=await originalRecoveryRecord(api,p);
  requireValue(same(installationReceipt(old.record),installationReceipt(record)),'recovery_installation_rewritten');
  const parent=await api('GET',API_ROOT+'/actions/runs/'+p.run_id);
  await verifyRecoveryDeploy(api,parent,p);
  const producer=await api('GET',API_ROOT+'/actions/runs/'+r.closure.run_id);
  runIdentity(producer,{id:r.closure.run_id,attempt:1,head:r.repository_sha,workflow:CLOSER,
    event:'workflow_run',completed:closed});
  requireValue(producer.display_title==='deploy-proof:v1:'+p.run_id+':1','terminal_origin_mismatch');
  requireValue(location.record_digest===digest(record),'publication_digest_mismatch');
  await requirePublicationStep(api,producer,r,location.record_digest,1,closed);
  if(!closed){
    const name=publicationName('pending_terminal',producer.id,1);
    const meta=await findArtifact(api,producer,name);
    const proof=validatePublication(await artifactJson(api,meta,PUBLICATION_FILE,producer,name),record);
    requireValue(proof.commit===location.commit&&proof.blob===location.blob,'recovery_diagnostics_changed');
  }
  await requireAncestry(api,p.original.commit,location.commit);
  await requireAncestry(api,location.commit,diagnosticsHead||location.commit);
  // Successful closed X already proves its exact PATCH/readback in the bound
  // terminal step. The same publisher may now belong to a later ordinary PR;
  // mutable current comment text is not historical installation authority.
  return {commit:location.commit,blob:location.blob,record_digest:location.record_digest,
    producer_run_id:r.closure.run_id,producer_run_attempt:1};
}

function argumentsOf(argv,commands) {
  requireValue(argv.length>0&&commands.includes(argv[0]),'invalid_mode');
  const out={command:argv[0]};
  for(let i=1;i<argv.length;i+=2){requireValue(/^--[a-z-]+$/.test(argv[i])&&typeof argv[i+1]==='string'
    &&argv[i+1].length>0&&!Object.hasOwn(out,argv[i].slice(2)),'invalid_arguments');out[argv[i].slice(2)]=argv[i+1];}
  return out;
}
function outputs(value){for(const [key,v]of Object.entries(value)){
  requireValue(/^[a-z_]+$/.test(key)&&!/[\r\n]/.test(String(v)),'invalid_output');process.stdout.write(key+'='+String(v)+'\n');}}
module.exports={REPOSITORY,API_ROOT,PREFIX,DEPLOY,CLOSER,HOST,RECORD_PATH,PUBLICATION_FILE,PUBLICATION_PROTOCOL,
  CONTENT_POLICY,FINGERPRINT_PROTOCOL,RESOLVER_STEP,TERMINAL_STEP,MAX_RECORD,MAX_API,MAX_ARCHIVE,INSTALL_STAGES,OPS_STAGES,
  PENDING_BINDING_STEP,TERMINAL_BINDING_STEP,
  Refusal,requireValue,object,keys,sha,positive,stamp,canonical,wire,digest,same,clone,iso,gitBlob,parseJson,readJson,saveJson,
  installationReceipt,validateObservation,joinObservations,compareObservations,validateReference,terminalBody,validateRelease,
  runIdentity,boundedList,readRecord,requireAncestry,publicationName,publicationProof,validatePublication,archiveJson,
  artifactJson,findArtifact,authenticatePublication,readBaseline,makeReadApi,sourceContext,deploymentProof,requireOps,
  v2,intentScan,contentSummary,releaseRecord,acceptedBaseline,planDeployment,currentBinding,requireCurrentBinding,admitUnchanged,
  wrapInstalled,verifyUnchanged,resolveCurrent,resolveConsumer,resolveEditor,hostObservation,argumentsOf,outputs,responseBytes};
Object.assign(module.exports,{LATE_RECOVERY,RECOVERY_PATHS,RECOVERY_PLAN_PROTOCOL,RECOVERY_PLAN_STEP,
  hasRecoveryMessage,recoverySpec,recoveryForSource,recoverySourceProof,validateRecoveryPlan,
  originalRecoveryRecord,planLateRecovery,RECOVERY_SERVER_STEPS,verifyRecoveryDeploy,readRecoveryPlan,
  validateTerminalRecovery,authenticateTerminalRecovery});
if(require.main===module){
  (async()=>{
    const args=argumentsOf(process.argv.slice(2),['resolve-consumer','resolve-editor','plan-deployment','admit-unchanged','wrap-installed']);
    const env=process.env,event=readJson(env.GITHUB_EVENT_PATH),api=makeReadApi(env.GITHUB_TOKEN),common={env,event,api};
    if(args.command==='resolve-consumer'){
      requireValue(keys(args,['command']),'invalid_arguments');outputs(await resolveConsumer(common));return;
    }
    if(args.command==='resolve-editor'){
      requireValue(keys(args,['command','repository-sha','application-sha']),'invalid_arguments');
      process.stdout.write(wire(await resolveEditor({...common,repositorySha:args['repository-sha'],applicationSha:args['application-sha']})));return;
    }
    if(args.command==='plan-deployment'){
      requireValue(keys(args,['command','output']),'invalid_arguments');const result=await planDeployment(common);saveJson(args.output,result);
      outputs({plan:result.plan,application_sha:result.application_sha,plan_file:args.output,
        plan_digest:result.plan==='terminal_recovery'?digest(result):''});return;
    }
    if(args.command==='admit-unchanged'){
      requireValue(keys(args,['command','plan','observation','output']),'invalid_arguments');
      const result=await admitUnchanged({...common,plan:readJson(args.plan),observation:readJson(args.observation)});
      if(result.record)saveJson(args.output,result.record);
      outputs({kind:result.kind,record_file:result.record?args.output:'',record_json:result.record?canonical(result.record):''});return;
    }
    requireValue(keys(args,['command','installation','output']),'invalid_arguments');
    saveJson(args.output,await wrapInstalled({...common,installation:readJson(args.installation)}));
  })().catch(e=>{process.stderr.write('deployment_release=refused reason='+(e instanceof Refusal?e.code:'internal_failure')+'\n');process.exitCode=1;});
}
