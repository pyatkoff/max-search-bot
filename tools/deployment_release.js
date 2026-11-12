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
const {setTimeout: waitForRetry} = require('node:timers/promises');

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
// One source-checked continuation of the observed pre-PATCH read refusal.
// The failed original execution and its immutable prepared record remain evidence.
const TERMINAL_RECOVERY=Object.freeze({
  branch:'ops/recover-pr1505-terminal-20261112',
  sha:'6e1c61915d0afc35ec8198d59021fe5a6bddadb6',
  run_id:'43231780686',job_id:131228674673,
  commit:'34f4a2cb95c80b5c336c6d4489c8f7c4ca034e4c',blob:'90ed503d30f72dff476fbbfd595fe7c384d861c0',
  record_digest:'cf5625c99d737a4f56653d6bf6a11caaa399f52208eb670df45d8b4ff171e4ff',
  pr:1505,comment_id:6789986080,owner_id:226193297,updated_at:'2026-11-12T13:41:14Z',
  continuation_sha:'658e387cadec98ff32f343fbcd5aa830dd0e85bf',continuation_run_id:'43239070014',continuation_job_id:131254894159,
  observation_sha:'f4db027b4fb39242a6b8e51cba460c309b01db3f',observation_run_id:'43240168728',observation_job_id:131258118456
});
function terminalRecovery(r){
  const x=r.closure?.recovery,p=TERMINAL_RECOVERY;
  requireValue(keys(x,['source_sha','original_commit','original_blob','original_record_digest','original_run_id','original_job_id'])
    &&sha(x.source_sha)&&x.source_sha!==p.sha&&r.repository_sha===p.sha&&r.kind==='installed'
    &&r.closure.job==='release-slot-recovery'&&r.closure.run_id!==p.run_id
    &&x.original_commit===p.commit&&x.original_blob===p.blob&&x.original_record_digest===p.record_digest
    &&x.original_run_id===p.run_id&&x.original_job_id===p.job_id
    &&same(r.closure.binding,{pr:p.pr,head_sha:'e6161e9c8789e3058db24b78ae97aecd13c15b3f',
      comment_id:p.comment_id,owner_id:p.owner_id,updated_at:p.updated_at}),'invalid_terminal_recovery');return x;
}
function validateRelease(record, success = true) {
  const installation=installationReceipt(record,success),r=record.repository_release;
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
    const recovered=object(c)&&Object.hasOwn(c,'recovery');
    requireValue(keys(c,['run_id','run_attempt','job','terminal_step','binding','expected_terminal','gates',...(recovered?['recovery']:[])])
      &&positive(c.run_id)&&typeof c.run_id==='string'&&c.run_attempt===1&&c.job===(recovered?'release-slot-recovery':'release-slot')
      &&c.terminal_step===TERMINAL_STEP&&keys(c.binding,['pr','head_sha','comment_id','owner_id','updated_at'])
      &&positive(c.binding.pr)&&sha(c.binding.head_sha)&&positive(c.binding.comment_id)
      &&positive(c.binding.owner_id)&&stamp(c.binding.updated_at)
      &&c.expected_terminal===terminalBody(r.kind,c.binding.pr)&&Array.isArray(c.gates)
      &&c.gates.length>=9&&c.gates.length<=20,'invalid_terminal_proposal');
    for(const g of c.gates)requireValue(keys(g,['path','run_id','run_attempt','head_sha','conclusion'])
      &&typeof g.path==='string'&&g.path.startsWith(PREFIX)&&positive(g.run_id)&&[1,2].includes(g.run_attempt)
      &&g.head_sha===r.repository_sha&&['success','skipped'].includes(g.conclusion),'invalid_gate_reference');
    if(recovered)terminalRecovery(r);
  }
  return {record,installation,release:r};
}

function runIdentity(run,{id,attempt,head,workflow,event,completed=true,branch='main',conclusion='success'}) {
  requireValue(object(run)&&String(run.id)===String(id)&&run.run_attempt===attempt&&run.path===workflow
    &&run.head_sha===head&&run.head_branch===branch&&run.head_repository?.full_name===REPOSITORY
    &&(!event||run.event===event)&&stamp(run.created_at),'untrusted_workflow_run');
  if(completed)requireValue(run.status==='completed'&&run.conclusion===conclusion,'producer_not_successful');
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
  const own=actual.filter(j=>j.name===(r.phase==='pending_terminal'?r.closure.job:'verify'));
  requireValue(own.length===1&&own[0].run_id===run.id&&own[0].head_sha===(r.closure?.recovery?.source_sha||r.repository_sha)
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
  const {record}=location,{release:r}=validateRelease(record);
  let producer=r.phase==='pending_closure'?r.release:r.closure,run;
  if(r.phase==='pending_closure'&&r.kind==='installed'){
    const completed=await deploymentProof(api,installationReceipt(record),{metadataWait});run=completed.run;
    producer={run_id:r.release.run_id,run_attempt:completed.publicationAttempt};
  }else{
    run=await api('GET',API_ROOT+'/actions/runs/'+producer.run_id);
    runIdentity(run,{id:producer.run_id,attempt:producer.run_attempt,head:r.closure?.recovery?.source_sha||r.repository_sha,
      branch:r.closure?.recovery?TERMINAL_RECOVERY.branch:'main',
      workflow:r.phase==='pending_closure'?DEPLOY:CLOSER,event:r.closure?.recovery?'push':r.phase==='pending_closure'?r.release.event:'workflow_run',
      completed:r.phase==='pending_closure'||closed});
  }
  if(r.phase==='pending_terminal'){
    const origin='deploy-proof:v1:'+r.release.run_id+':'+r.release.run_attempt;
    if(r.closure.recovery)await authenticateFailedTerminal(api,record);
    else requireValue(run.display_title===origin||(run.display_title===undefined
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
async function authenticateFailedTerminal(api,record){
  const r=validateRelease(record).release,x=terminalRecovery(r),p=TERMINAL_RECOVERY;
  const original=await readRecord(api,x.original_commit);
  requireValue(original.blob===x.original_blob&&original.record_digest===x.original_record_digest,'original_terminal_changed');
  const restored=clone(record);delete restored.repository_release.closure.recovery;
  restored.repository_release.closure.run_id=p.run_id;restored.repository_release.closure.job='release-slot';
  requireValue(same(restored,original.record),'original_terminal_changed');
  const run=await api('GET',API_ROOT+'/actions/runs/'+p.run_id);
  runIdentity(run,{id:p.run_id,attempt:1,head:p.sha,workflow:CLOSER,event:'workflow_run',conclusion:'failure'});
  requireValue(run.display_title==='deploy-proof:v1:'+r.release.run_id+':1','terminal_origin_mismatch');
  const jobs=boundedList(await api('GET',API_ROOT+'/actions/runs/'+p.run_id+'/attempts/1/jobs?per_page=100'),
    'jobs','incomplete_original_terminal');
  const own=jobs.filter(j=>j.id===p.job_id&&j.name==='release-slot');
  requireValue(own.length===1&&own[0].run_id===Number(p.run_id)&&own[0].head_sha===p.sha
    &&own[0].run_attempt===1&&own[0].status==='completed'&&own[0].conclusion==='failure'
    &&Array.isArray(own[0].steps),'original_terminal_not_failed');
  const prepared=own[0].steps.filter(s=>s.name===TERMINAL_BINDING_STEP+p.record_digest);
  const terminal=own[0].steps.filter(s=>s.name===TERMINAL_STEP);
  requireValue(prepared.length===1&&prepared[0].conclusion==='success'&&prepared[0].status==='completed'
    &&terminal.length===1&&terminal[0].conclusion==='failure'&&terminal[0].status==='completed'
    &&prepared[0].number<terminal[0].number,'original_terminal_not_failed');return {original,run};
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
function makeReadApi(token,fetchImpl=globalThis.fetch,{sleep=waitForRetry}={}) {
  requireValue(typeof token==='string'&&token.length>0&&typeof fetchImpl==='function'&&typeof sleep==='function','missing_capability');
  const request=async(url,headers,redirect='error',limit=MAX_API,signal=null)=>{
    // Retry only an explicit temporary refusal of this read. The same 20-second
    // deadline covers all requests and delays; writers use a separate transport.
    const deadline=performance.now()+20000;
    const controller=new AbortController(),timer=setTimeout(()=>controller.abort(),20000);
    const abort=()=>controller.abort();
    if(signal){if(signal.aborted)abort();else signal.addEventListener('abort',abort,{once:true});}
    try{
      for(let attempt=0;attempt<3;attempt++){
        const response=await fetchImpl(url,{method:'GET',redirect,signal:controller.signal,headers});
        if([502,503].includes(response.status)&&attempt<2){
          const refusal='api_http_'+response.status;
          let retryMs=attempt===0?250:750;
          const retryAfter=response.headers?.get('retry-after');
          if(retryAfter!==null&&retryAfter!==undefined){
            const requested=/^[0-9]+$/.test(retryAfter)?Number(retryAfter)*1000:Date.parse(retryAfter)-Date.now();
            requireValue(Number.isFinite(requested),refusal);
            retryMs=Math.max(retryMs,requested);
          }
          requireValue(performance.now()+retryMs<deadline,refusal);
          try{await response.body?.cancel();}catch{} // Release only the discarded error response.
          await sleep(retryMs,undefined,{signal:controller.signal});
          continue;
        }
        if(redirect==='manual'&&[301,302,303,307,308].includes(response.status))return response;
        requireValue(response.ok,'api_http_'+(Number.isInteger(response.status)?response.status:'unknown'));
        return await responseBytes(response,limit);
      }
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
    requireValue([TERMINAL_RECOVERY.job_id,TERMINAL_RECOVERY.continuation_job_id,TERMINAL_RECOVERY.observation_job_id].includes(job),'untrusted_terminal_log');
    const response=await request('https://api.github.com'+API_ROOT+'/actions/jobs/'+job+'/logs',headers(false),'manual',2*1024*1024);
    if(Buffer.isBuffer(response))return response;
    let url;try{url=new URL(response.headers.get('location'));}catch{throw new Refusal('invalid_log_redirect');}
    requireValue(url.protocol==='https:'&&!url.username&&!url.password&&!url.port&&!url.hash
      &&(url.hostname.endsWith('.blob.core.windows.net')||url.hostname.endsWith('.actions.githubusercontent.com')),
      'invalid_log_redirect');
    return request(url.href,{},'error',2*1024*1024);
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
  runIdentity(closer,{id:r.closure.run_id,attempt:1,head:r.closure.recovery?.source_sha||c.sha,
    branch:r.closure.recovery?TERMINAL_RECOVERY.branch:'main',workflow:CLOSER,
    event:r.closure.recovery?'push':'workflow_run',completed:false});
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

/** One fixed owner-authorised publication push, before any SSH/provider access. */
async function resolvePublicationOperation({env,event,api}) {
  const branch='ops/appleservice39-coverage-20261112',ref='refs/heads/'+branch;
  const workflow=PREFIX+'publish-appleservice39-pilot.yml';
  const predecessor='c5c0276d94337eee130467716c9794176b2ab25a',predecessorRun=43186776772;
  const initial=event?.before==='0'.repeat(40)&&event?.created===true;
  const recovery=event?.before===predecessor&&event?.created===false&&event?.after!==predecessor;
  requireValue(env.GITHUB_REPOSITORY===REPOSITORY&&env.GITHUB_REF===ref
    &&env.GITHUB_WORKFLOW_REF===REPOSITORY+'/'+workflow+'@'+ref
    &&env.GITHUB_EVENT_NAME==='push'&&env.GITHUB_JOB==='publish'
    &&env.GITHUB_RUN_ATTEMPT==='1'&&sha(env.GITHUB_SHA)&&positive(env.GITHUB_RUN_ID)
    &&event?.repository?.full_name===REPOSITORY&&event.ref===ref&&event.after===env.GITHUB_SHA
    &&(initial||recovery)&&event.deleted===false&&event.forced===false,
    'untrusted_publication_operation');
  const c={sha:env.GITHUB_SHA,id:String(env.GITHUB_RUN_ID),attempt:1,event:'push',workflow,job:'publish'};
  requireValue((await api('GET',API_ROOT+'/git/ref/heads/main'))?.object?.sha===c.sha,'main_changed');
  const run=await api('GET',API_ROOT+'/actions/runs/'+c.id);
  runIdentity(run,{id:c.id,attempt:1,head:c.sha,workflow,event:'push',completed:false,branch});
  requireValue(run.status==='in_progress','workflow_not_active');
  if(recovery){
    // One native fast-forward from the exact cancelled source, never a rerun or
    // a new publication identity after a private/provider operation could start.
    const old=await api('GET',API_ROOT+'/actions/runs/'+predecessorRun);
    runIdentity(old,{id:predecessorRun,attempt:1,head:predecessor,workflow,event:'push',branch,conclusion:'cancelled'});
    const jobs=boundedList(await api('GET',API_ROOT+'/actions/runs/'+predecessorRun+'/attempts/1/jobs?per_page=100'),
      'jobs','publication_predecessor_unknown');
    const regression=jobs.filter(j=>j.name==='AppleService39 pilot publication regression');
    const publication=jobs.filter(j=>j.name==='publish');
    requireValue(jobs.length===2&&regression.length===1&&publication.length===1
      &&jobs.every(j=>j.run_id===predecessorRun&&j.run_attempt===1&&j.head_sha===predecessor&&j.status==='completed')
      &&regression[0].conclusion==='cancelled'&&publication[0].conclusion==='skipped'
      &&Array.isArray(publication[0].steps)&&publication[0].steps.length===0,
      'publication_predecessor_has_writes');
    const relation=await api('GET',API_ROOT+'/compare/'+predecessor+'...'+c.sha+'?per_page=1');
    requireValue(relation?.status==='ahead'&&relation.merge_base_commit?.sha===predecessor,'publication_source_not_descendant');
  }
  const location=await readRecord(api);
  const result=await resolveCurrent(api,c,location);
  requireValue(result.ready===true&&result.perform_actions===true,'release_pending');
  return finalResolution(api,c,location,result);
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
  TERMINAL_RECOVERY,terminalRecovery,authenticateFailedTerminal,
  CONTENT_POLICY,FINGERPRINT_PROTOCOL,RESOLVER_STEP,TERMINAL_STEP,MAX_RECORD,MAX_API,MAX_ARCHIVE,INSTALL_STAGES,OPS_STAGES,
  PENDING_BINDING_STEP,TERMINAL_BINDING_STEP,
  Refusal,requireValue,object,keys,sha,positive,stamp,canonical,wire,digest,same,clone,iso,gitBlob,parseJson,readJson,saveJson,
  installationReceipt,validateObservation,joinObservations,compareObservations,validateReference,terminalBody,validateRelease,
  runIdentity,boundedList,readRecord,requireAncestry,publicationName,publicationProof,validatePublication,archiveJson,
  artifactJson,findArtifact,authenticatePublication,readBaseline,makeReadApi,sourceContext,deploymentProof,requireOps,
  v2,intentScan,contentSummary,releaseRecord,acceptedBaseline,planDeployment,currentBinding,requireCurrentBinding,admitUnchanged,
  wrapInstalled,verifyUnchanged,resolveCurrent,resolveConsumer,resolvePublicationOperation,resolveEditor,hostObservation,argumentsOf,outputs,responseBytes};
if(require.main===module){
  (async()=>{
    const args=argumentsOf(process.argv.slice(2),['resolve-consumer','resolve-publication-operation','resolve-editor','plan-deployment','admit-unchanged','wrap-installed']);
    const env=process.env,event=readJson(env.GITHUB_EVENT_PATH),api=makeReadApi(env.GITHUB_TOKEN),common={env,event,api};
    if(args.command==='resolve-publication-operation'){
      requireValue(keys(args,['command']),'invalid_arguments');outputs(await resolvePublicationOperation(common));return;
    }
    if(args.command==='resolve-consumer'){
      requireValue(keys(args,['command']),'invalid_arguments');outputs(await resolveConsumer(common));return;
    }
    if(args.command==='resolve-editor'){
      requireValue(keys(args,['command','repository-sha','application-sha']),'invalid_arguments');
      process.stdout.write(wire(await resolveEditor({...common,repositorySha:args['repository-sha'],applicationSha:args['application-sha']})));return;
    }
    if(args.command==='plan-deployment'){
      requireValue(keys(args,['command','output']),'invalid_arguments');const result=await planDeployment(common);saveJson(args.output,result);
      outputs({plan:result.plan,application_sha:result.application_sha,plan_file:args.output});return;
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


