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
  PREFIX+'setup-appleservice39-connection.yml',PREFIX+'publish-appleservice39-pilot.yml',
  PREFIX+'max2-common-value-state-v2.yml']);
const SCHEDULES = new Map([[PREFIX+'live-session-diagnostics.yml','25 * * * *'],
  [PREFIX+'modeled-conversion-monitor.yml','2-59/5 * * * *']]);
const HOTEL_REGISTRY_READ = Object.freeze({branch:'ops/anytour-hotel-four-read-20261123',
  workflow:PREFIX+'read-1566-main-hotel-registry.yml',job:'registry',operation:'hotel_four_registry',
  owner:'pyatkoff',owner_id:226193297,repository_id:1342493152});
const MAX2_HOLD_PAIR_READ = Object.freeze({branch:'ops/max2-hold-pair-read-20261123',
  workflow:PREFIX+'max2-hold-pair-read.yml',job:'diagnose',operation:'max2_hold_pair_20261123',
  owner:'pyatkoff',owner_id:226193297,repository_id:1342493152});
const HOTEL_FOUR_TRANSFER = Object.freeze({branch:'ops/anytour-hotel-four-apply-20261123',
  workflow:PREFIX+'edit-1566-historical-telegram-with-channel-default.yml',job:'transfer',operation:'hotel_four_transfer',
  owner:'pyatkoff',owner_id:226193297,repository_id:1342493152,
  plan_path:'config/anytour_existing_post_transfer_four_20261123.json',plan_size:11972,
  plan_blob:'648e68b7395fcab65c321448b1a6a68554de17dc',
  plan_digest:'fdda41801951a033715b5ca5d567ef348f2ee885ab40074d35dfa8151d52de56'});
const HOTEL_NEXT_TRANSFER = Object.freeze({...HOTEL_FOUR_TRANSFER,
  branch:'ops/anytour-hotel-next-apply-20261123',
  workflow:PREFIX+'edit-1566-next-historical-telegram.yml',operation:'hotel_next_transfer',
  plan_path:'config/anytour_existing_post_transfer_next_20261123.json',plan_size:5520,
  plan_blob:'297bed885e03f3422e624ac3dff6fd873e689135',
  plan_digest:'30f93518da51279979f7669538f7c09f6096733e9423f755dc21981af821b98d'});
const HOTEL_RANKED_46 = Object.freeze({...HOTEL_FOUR_TRANSFER,
  branch:'ops/anytour-hotel-ranked-46-apply-20261123',workflow:PREFIX+'edit-1566-ranked-46-historical-telegram.yml',operation:'hotel_ranked_46_transfer',
  plan_path:'config/anytour_ranked_46_20261123.json',plan_size:712,plan_blob:'f3e0d4ee289f189eb5c43aaf85f8024c3de801ba',
  plan_digest:'30d58e6649877a0eb07deb7e2fddcfa99c92081aac5fbef8c3c2b1d7a4a92833',native_run:'45175721919',native_artifact:14818587717,
  native_zip_bytes:94348,native_zip_digest:'cf776ab108e8b023aaf6217fa1d5e43dce6047d462e2c96295cc94175332d0cc'});
const HOTEL_REMAINING_TRANSFER = Object.freeze({...HOTEL_NEXT_TRANSFER,
  branch:'ops/anytour-hotel-remaining-apply-20261123',operation:'hotel_remaining_transfer'});
const ORIGINAL_NEXT_TRANSFER = Object.freeze({
  run:45235830990,job:137907167978,sha:'bb7d0bde64092cbd24a69084e651ff44336ba8b5',
  artifact:14850280926,digest:'sha256:8df0ba31b546c644f800a71324d959c8ebdaa1a0801ffdc2f0d8ef5cebf3034c'});
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
  observation_sha:'f4db027b4fb39242a6b8e51cba460c309b01db3f',observation_run_id:'43240168728',observation_job_id:131258118456,
  read_failure_sha:'d6f732dffd4976c663153771d9bb4b122456e3e6',read_failure_run_id:'43243320862',read_failure_job_id:131269294778
});
// The next original release stopped before SSH/TLS; its closer never prepared
// or patched a terminal. Continue only that proved unstarted gate, once.
const GATE_RECOVERY=Object.freeze({
  branch:TERMINAL_RECOVERY.branch,before:'cb98b8ba4f51080d28a5096b62a9bae7c65380bc',
  sha:'0cb62969d0a8fa59b1872df465b3810bf5b569d4',deploy:'43250756234',
  run_id:'43254816784',job_id:131310064735,tls_run_id:'43254816916',tls_job_id:131310062683,
  commit:'89f4fa72811c15fdc0eed25710469f3f01662397',blob:'e5ea04e56b631f9449e16ce3debf53add14fb744',
  record_digest:'d22939ab236756bdb6ae28fec01d069b59eb84eeb59fd037993583ef03b90047',
  pr:1506,head_sha:'cb98b8ba4f51080d28a5096b62a9bae7c65380bc',
  comment_id:6789986080,owner_id:226193297,updated_at:'2026-11-12T15:40:38Z',
  tls_workflow:PREFIX+'max-tls-preflight.yml',tls_step:'Wait for exact active-server SHA and verify strict TLS'
});
// Owner-authorised one-time metadata completion for the already installed1529.
// The original ref write is proved present, but its upload/terminal never ran.
const UNPUBLISHED_TERMINAL_RECOVERY=Object.freeze({
  branch:'ops/recover-pr1529-terminal-20261113',sha:'8d6ac35dd17614b2cadb3aa3af7637c74280c01c',
  deploy:'43456259794',run_id:'43458412572',job_id:131998597573,
  commit:'b367a1aac9d5ae58ecf2be7fbc7a00be5080b51c',blob:'a46b8d215b6719be2ba9b6bd8361dcdc728e388d',
  record_digest:'6ece5ff4fad0e479b039c2dba2debbd40742a703f41b3632068f9c98239a5ddc',
  pending_commit:'a432237a9e528b9f55c6e10fe89e5893fbc06649',pending_blob:'978e1999d1e55091b2dd560c547da6b9ea94d9da',
  pending_digest:'4c7f0d7a6eca6f96ddffc60614105a0f9d4fff9f4d276d1da89238e900b2346c',
  pr:1529,head_sha:'51a900b316cad03a41b07c6a79e36e6ee322c029',comment_id:6775467253,
  owner_id:226193297,updated_at:'2026-11-13T15:47:04Z',
  original_code:'5b81f5e8f4e2d98aff760fc5d093f2d8f7d8ea88'
});
// Owner-transferred one-time metadata reconciliation of the 1596/1597 overlap.
// Installation and failed original executions remain immutable.
const PRIOR_WAVE_RECOVERY=Object.freeze({
  branch:'ops/recover-pr1597-terminal-20261117',sha:'fd9b28dab2147f13f3b0d0f7f856b14392b141d2',
  deploy:'44079440323',run_id:'44081722753',job_id:134033721866,
  commit:'af7ee5c9aad44951cda9fd6b3f61f7947c232d44',blob:'dfc7eef713d4b6ab7f606420002f96c544e9e937',
  record_digest:'3ffb405894009e90885b68cb3db652240d4a587c0547674d8ca6cc1c9ada65c3',
  pr:1597,head_sha:'46f2ab2c01fcf1da5cea2d2f0c042fbbc0a676ff',comment_id:6928374515,
  owner_id:226193297,updated_at:'2026-11-17T12:01:41Z',original_code:'39efce1e243ce68b2170a3f3a645b12f03496cd3',
  old_children:Object.freeze([44079622025,44079621974,44079622090,44079622065,44079621931,44079621993,44079622033,44079622034,44079634544]),
  previous:Object.freeze({sha:'c45b29452f8909eb190a04c6a54645400bb87c11',deploy:'44077678956',
    verify_job_id:134026179047,pr:1596,head_sha:'96fd2b5ef87a8592cfc324308a72232b39e2a545',
    comment_id:6682640708,owner_id:226193297,updated_at:'2026-11-17T11:49:29Z',
    terminal:'PR1596: замещено PR1597; исходная проверка не завершена, выпуск свободен'})
});
// Owner-approved metadata-only continuation of PR1643's exact body-format refusal.
// Ordinary recordState remains strict; these pins do not authorize another incident.
const PUBLISHER_BODY_RECOVERY=Object.freeze({
  branch:'ops/recover-pr1643-terminal-20261121',sha:'cabc34b7166994c65816ec8c81e48895ea889683',
  deploy:'44912653990',run_id:'44914105188',job_id:136875917604,
  commit:'d8b5805dc9699dcc3169aa4005fe1fa6155b1f86',blob:'0b02cdb005a8e9d60cfb0a3ffdccd3d2174c474d',
  record_digest:'0b1d28a99159afad6409d30cd630596413d9502f95294a88d8ead388556777a5',
  pr:1643,head_sha:'de603d8c5b53a5dbf3e972e166da96c42c4e5500',comment_id:7063060212,
  owner_id:226193297,updated_at:'2026-11-21T14:11:18Z',
  body_bytes:2956,body_sha256:'2f9cda3b8ccc67f1d1838b2a42e6ac3d37300e7aa37c1527354563cc7f7fb130',
  original_code:'612d124bd14378ceaffad6e691dbcc03597ea910',
  priority_code:'3c1b228d36785546d71fb9e21c68ee4b03961619',
  source_files:Object.freeze(['tools/deployment_release.js','tools/release_slot_close.js','tools/release_priority.js',
    'tests/run_deployment_release_regression.js',CLOSER]),
  changed_files:Object.freeze(['tools/deployment_release.js','tools/release_slot_close.js',
    'tests/run_deployment_release_regression.js',CLOSER,PREFIX+'max2-common-value-state-v2.yml',
    'tests/run_max2_common_value_state_operation_v2_regression.py','tests/max2_common_v2/fixtures.json',
    'resources/max2-common-v2/release-manifest.json','tests/check_ci_workflow_scopes.py'])
});
function publisherBodyRecovery(r){
  const x=r.closure?.recovery,p=PUBLISHER_BODY_RECOVERY;
  requireValue(keys(x,['kind','source_sha','source_files','original_commit','original_blob','original_record_digest',
    'original_run_id','original_job_id','publisher_body_sha256','publisher_body_bytes'])&&x.kind==='publisher_body_format_v1'
    &&sha(x.source_sha)&&x.source_sha!==p.sha&&keys(x.source_files,p.source_files)
    &&Object.values(x.source_files).every(sha)&&x.source_files['tools/release_priority.js']===p.priority_code
    &&r.repository_sha===p.sha&&r.application_sha===p.sha&&r.kind==='installed'
    &&r.release.run_id===p.deploy&&r.release.run_attempt===1&&r.release.event==='push'
    &&r.closure.job==='release-slot-recovery'&&r.closure.run_id!==p.run_id
    &&x.original_commit===p.commit&&x.original_blob===p.blob&&x.original_record_digest===p.record_digest
    &&x.original_run_id===p.run_id&&x.original_job_id===p.job_id
    &&x.publisher_body_sha256===p.body_sha256&&x.publisher_body_bytes===p.body_bytes
    &&r.observation===null&&r.observation_producer===null&&r.baseline_eligible===false
    &&same(r.closure.binding,{pr:p.pr,head_sha:p.head_sha,comment_id:p.comment_id,
      owner_id:p.owner_id,updated_at:p.updated_at}),'invalid_publisher_body_recovery');return x;
}
async function publisherBodySource(api,source,expected=null,{local=false}={}){
  const p=PUBLISHER_BODY_RECOVERY,get=q=>api('GET',API_ROOT+q);
  requireValue(sha(source)&&source!==p.sha&&(local===true||keys(expected,p.source_files)),
    'publisher_recovery_source_unproved');
  const relation=await get('/compare/'+p.sha+'...'+source+'?per_page=1');
  requireValue(relation?.status==='ahead'&&relation.merge_base_commit?.sha===p.sha
    &&Array.isArray(relation.files)&&relation.files.length===p.changed_files.length
    &&same(relation.files.map(f=>f.filename).sort(),[...p.changed_files].sort())
    &&relation.files.every(f=>f.status==='modified'&&!f.previous_filename),'publisher_recovery_source_changed');
  const ci=boundedList(await get('/actions/runs?head_sha='+source+'&per_page=100'),
    'workflow_runs','incomplete_recovery_ci');
  for(const [name,file]of [['Regression tests','regression-tests.yml'],['Retired domain guard','retired-domain-guard.yml']]){
    const rows=ci.filter(r=>r.path===PREFIX+file&&r.event==='pull_request');
    requireValue(rows.length===1&&rows[0].name===name&&rows[0].head_sha===source
      &&rows[0].head_repository?.full_name===REPOSITORY&&rows[0].run_attempt===1
      &&rows[0].status==='completed'&&rows[0].conclusion==='success','recovery_ci_unconfirmed');
  }
  const pins={};
  for(const name of p.source_files){
    const item=await get('/contents/'+name+'?ref='+source);
    requireValue(item?.type==='file'&&item.path===name&&sha(item.sha)
      &&Number.isSafeInteger(item.size)&&item.size>0&&item.size<=512*1024,'publisher_recovery_source_unproved');
    pins[name]=item.sha;
    if(local){
      const file=path.resolve(__dirname,'..',name),info=fs.lstatSync(file);
      requireValue(info.isFile()&&!info.isSymbolicLink()&&info.size===item.size
        &&gitBlob(fs.readFileSync(file))===item.sha,'publisher_recovery_checkout_changed');
    }
  }
  requireValue(pins['tools/release_priority.js']===p.priority_code
    &&(expected===null||same(pins,expected)),'publisher_recovery_source_changed');return pins;
}
function priorWaveRecovery(r){
  const x=r.closure?.recovery,p=PRIOR_WAVE_RECOVERY;
  requireValue(keys(x,['kind','source_sha','original_commit','original_blob','original_record_digest',
    'original_run_id','original_job_id','superseded'])&&x.kind==='prior_failed_wave_v1'
    &&sha(x.source_sha)&&x.source_sha!==p.sha&&r.repository_sha===p.sha&&r.kind==='installed'
    &&r.release.run_id===p.deploy&&r.release.run_attempt===1&&r.closure.job==='release-slot-recovery'
    &&r.closure.run_id!==p.run_id&&x.original_commit===p.commit&&x.original_blob===p.blob
    &&x.original_record_digest===p.record_digest&&x.original_run_id===p.run_id&&x.original_job_id===p.job_id
    &&same(x.superseded,p.previous)&&same(r.closure.binding,{pr:p.pr,head_sha:p.head_sha,
      comment_id:p.comment_id,owner_id:p.owner_id,updated_at:p.updated_at}),
    'invalid_prior_wave_recovery');return x;
}
function unpublishedTerminalRecovery(r){
  const x=r.closure?.recovery,p=UNPUBLISHED_TERMINAL_RECOVERY;
  requireValue(keys(x,['kind','source_sha','original_commit','original_blob','original_record_digest',
    'original_run_id','original_job_id'])&&x.kind==='unpublished_terminal_v1'&&sha(x.source_sha)&&x.source_sha!==p.sha
    &&r.repository_sha===p.sha&&r.kind==='installed'&&r.release.run_id===p.deploy&&r.release.run_attempt===1
    &&r.closure.job==='release-slot-recovery'&&r.closure.run_id!==p.run_id
    &&x.original_commit===p.commit&&x.original_blob===p.blob&&x.original_record_digest===p.record_digest
    &&x.original_run_id===p.run_id&&x.original_job_id===p.job_id
    &&same(r.closure.binding,{pr:p.pr,head_sha:p.head_sha,comment_id:p.comment_id,
      owner_id:p.owner_id,updated_at:p.updated_at}),'invalid_unpublished_terminal_recovery');return x;
}
function recoveryBranch(r){
  if(r.closure?.recovery?.kind==='publisher_body_format_v1')return PUBLISHER_BODY_RECOVERY.branch;
  if(r.closure?.recovery?.kind==='prior_failed_wave_v1')return PRIOR_WAVE_RECOVERY.branch;
  return r.closure?.recovery?.kind==='unpublished_terminal_v1'?UNPUBLISHED_TERMINAL_RECOVERY.branch:TERMINAL_RECOVERY.branch;
}
function gateRecovery(r){
  const x=r.closure?.recovery,p=GATE_RECOVERY;
  requireValue(keys(x,['kind','source_sha','original_commit','original_blob','original_record_digest',
    'original_run_id','original_job_id','tls_run_id'])&&x.kind==='unstarted_tls_v1'
    &&sha(x.source_sha)&&x.source_sha!==p.sha&&positive(x.tls_run_id)&&typeof x.tls_run_id==='string'
    &&x.tls_run_id!==p.tls_run_id&&r.repository_sha===p.sha&&r.kind==='installed'
    &&r.release.run_id===p.deploy&&r.release.run_attempt===2&&r.closure.job==='release-slot-recovery'
    &&r.closure.run_id!==p.run_id&&x.original_commit===p.commit&&x.original_blob===p.blob
    &&x.original_record_digest===p.record_digest&&x.original_run_id===p.run_id&&x.original_job_id===p.job_id
    &&same(r.closure.binding,{pr:p.pr,head_sha:p.head_sha,comment_id:p.comment_id,
      owner_id:p.owner_id,updated_at:p.updated_at}),'invalid_gate_recovery');return x;
}
function terminalRecovery(r){
  if(r.closure?.recovery?.kind==='publisher_body_format_v1')return publisherBodyRecovery(r);
  if(r.closure?.recovery?.kind==='prior_failed_wave_v1')return priorWaveRecovery(r);
  if(r.closure?.recovery?.kind==='unpublished_terminal_v1')return unpublishedTerminalRecovery(r);
  if(r.closure?.recovery?.kind==='unstarted_tls_v1')return gateRecovery(r);
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
      &&(g.head_sha===r.repository_sha||(c.recovery?.kind==='unstarted_tls_v1'
        &&g.path===GATE_RECOVERY.tls_workflow&&g.run_id===c.recovery.tls_run_id
        &&g.head_sha===c.recovery.source_sha&&g.run_attempt===1&&g.conclusion==='success'))
      &&['success','skipped'].includes(g.conclusion),'invalid_gate_reference');
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
      branch:r.closure?.recovery?recoveryBranch(r):'main',
      workflow:r.phase==='pending_closure'?DEPLOY:CLOSER,event:r.closure?.recovery?'push':r.phase==='pending_closure'?r.release.event:'workflow_run',
      completed:r.phase==='pending_closure'||closed});
  }
  if(r.phase==='pending_terminal'){
    const origin='deploy-proof:v1:'+r.release.run_id+':'+r.release.run_attempt;
    if(r.closure.recovery)await authenticateFailedTerminal(api,record,{closed});
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
async function authenticateFailedTerminal(api,record,{closed=false}={}){
  if(record.repository_release?.closure?.recovery?.kind==='publisher_body_format_v1'){
    const r=validateRelease(record).release,x=publisherBodyRecovery(r),p=PUBLISHER_BODY_RECOVERY;
    const proof=await failedPublisherBodyTerminal(api),restored=clone(record),C=require('./release_slot_close');
    restored.repository_release.phase='pending_closure';restored.repository_release.closure=null;
    requireValue(same(restored,proof.original.record),'original_publisher_body_record_changed');
    requireValue(same(C.gateReferences(proof.snapshot),r.closure.gates),'gates_changed');
    await publisherBodySource(api,x.source_sha,x.source_files);
    const native=await api('GET',API_ROOT+'/actions/runs/'+r.closure.run_id);
    runIdentity(native,{id:r.closure.run_id,attempt:1,head:x.source_sha,workflow:CLOSER,event:'push',
      branch:p.branch,completed:closed});
    requireValue(native.actor?.id===p.owner_id&&native.triggering_actor?.id===p.owner_id,
      'publisher_continuation_owner_changed');
    // A later PR can lawfully acquire the same publisher. Historical completion
    // is proved by the original digest-bound native terminal step below;
    // current ownership remains the unchanged release-priority admission's job.
    return proof;
  }
  if(record.repository_release?.closure?.recovery?.kind==='prior_failed_wave_v1'){
    const r=validateRelease(record).release;priorWaveRecovery(r);
    const proof=await failedPriorWaveTerminal(api),restored=clone(record);
    restored.repository_release.phase='pending_closure';restored.repository_release.closure=null;
    requireValue(same(restored,proof.original.record),'original_prior_wave_record_changed');
    const C=require('./release_slot_close');
    requireValue(same(C.gateReferences(proof.snapshot),r.closure.gates),'gates_changed');return proof;
  }
  if(record.repository_release?.closure?.recovery?.kind==='unpublished_terminal_v1'){
    const r=validateRelease(record).release,p=UNPUBLISHED_TERMINAL_RECOVERY;
    unpublishedTerminalRecovery(r);
    const original=await failedUnpublishedTerminal(api);
    const restored=clone(record);delete restored.repository_release.closure.recovery;
    restored.repository_release.closure.run_id=p.run_id;restored.repository_release.closure.job='release-slot';
    requireValue(same(restored,original.record),'original_unpublished_record_changed');return {original};
  }
  if(record.repository_release?.closure?.recovery?.kind==='unstarted_tls_v1'){
    const r=validateRelease(record).release,x=gateRecovery(r),original=await failedGateEvidence(api);
    const restored=clone(record);restored.repository_release.phase='pending_closure';
    restored.repository_release.closure=null;restored.repository_release.baseline_eligible=false;
    requireValue(same(restored,original.record),'original_gate_record_changed');
    const tls=await continuationTls(api,x.tls_run_id,x.source_sha);
    const expected={path:GATE_RECOVERY.tls_workflow,run_id:String(tls.id),run_attempt:1,
      head_sha:x.source_sha,conclusion:'success'};
    requireValue(r.closure.gates.filter(g=>g.path===GATE_RECOVERY.tls_workflow).length===1
      &&same(r.closure.gates.find(g=>g.path===GATE_RECOVERY.tls_workflow),expected),'gate_proof_changed');
    return {original,run:tls};
  }
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
function failedGateJobs(tlsJobs,closeJobs){
  const p=GATE_RECOVERY;
  const one=(raw,runId,jobId,name)=>{
    const jobs=boundedList(raw,'jobs','incomplete_failed_gate_jobs');
    const rows=jobs.filter(j=>j.id===jobId&&j.name===name);
    requireValue(rows.length===1&&rows[0].run_id===Number(runId)&&rows[0].head_sha===p.sha
      &&rows[0].run_attempt===1&&rows[0].status==='completed'&&rows[0].conclusion==='failure'
      &&Array.isArray(rows[0].steps),'failed_gate_not_original');return rows[0];
  };
  const tls=one(tlsJobs,p.tls_run_id,p.tls_job_id,'strict-tls-preflight');
  const closer=one(closeJobs,p.run_id,p.job_id,'release-slot');
  const result=(job,name,outcome)=>{const rows=job.steps.filter(s=>s.name===name);
    requireValue(rows.length===1&&rows[0].status==='completed'&&rows[0].conclusion===outcome,'failed_gate_executed');return rows[0];};
  const resolved=result(tls,RESOLVER_STEP,'failure');
  const key=result(tls,'Prepare SSH private key','skipped');
  const check=result(tls,p.tls_step,'skipped');
  requireValue(resolved.number<key.number&&key.number<check.number&&tls.steps.every(s=>
    s===resolved||['success','skipped'].includes(s.conclusion)),'failed_gate_executed');
  const prepare=result(closer,'Prepare the exact release proof before terminal publication','failure');
  const terminal=result(closer,TERMINAL_STEP,'skipped');
  const upload=closer.steps.filter(s=>s.name===TERMINAL_BINDING_STEP);
  requireValue(upload.length===1&&upload[0].status==='completed'&&upload[0].conclusion==='skipped'
    &&prepare.number<upload[0].number&&upload[0].number<terminal.number
    &&closer.steps.every(s=>s===prepare||['success','skipped'].includes(s.conclusion)),'failed_terminal_executed');
  return {tls,closer};
}
async function failedPublisherBodyTerminal(api){
  const p=PUBLISHER_BODY_RECOVERY,get=q=>api('GET',API_ROOT+q),C=require('./release_slot_close');
  const original=await readRecord(api,p.commit);
  requireValue(original.blob===p.blob&&original.record_digest===p.record_digest,
    'original_publisher_body_record_changed');
  const r=validateRelease(original.record).release;
  requireValue(r.repository_sha===p.sha&&r.application_sha===p.sha&&r.phase==='pending_closure'
    &&r.kind==='installed'&&r.release.run_id===p.deploy&&r.release.run_attempt===1&&r.release.event==='push'
    &&r.closure===null&&r.observation===null&&r.observation_producer===null&&r.baseline_eligible===false,
    'original_publisher_body_record_changed');
  await authenticatePublication(api,original);await deploymentProof(api,installationReceipt(original.record));
  const run=await get('/actions/runs/'+p.run_id);
  runIdentity(run,{id:p.run_id,attempt:1,head:p.sha,workflow:CLOSER,event:'workflow_run',conclusion:'failure'});
  requireValue(run.display_title==='deploy-proof:v1:'+p.deploy+':1','failed_terminal_origin_changed');
  const raw=await get('/actions/runs/'+p.run_id+'/attempts/1/jobs?per_page=100');
  const jobs=boundedList(raw,'jobs','incomplete_failed_terminal_jobs'),rows=jobs.filter(j=>j.id===p.job_id&&j.name==='release-slot');
  requireValue(jobs.length===3&&rows.length===1&&rows[0].run_id===Number(p.run_id)&&rows[0].run_attempt===1
    &&rows[0].head_sha===p.sha&&rows[0].status==='completed'&&rows[0].conclusion==='failure'
    &&Array.isArray(rows[0].steps),'failed_terminal_not_original');
  const own=rows[0],one=(name,result)=>{const ss=own.steps.filter(s=>s.name===name);
    requireValue(ss.length===1&&ss[0].status==='completed'&&ss[0].conclusion===result,'failed_terminal_executed');return ss[0];};
  const prepare=one('Prepare the exact release proof before terminal publication','failure');
  const upload=one(TERMINAL_BINDING_STEP,'skipped'),terminal=one(TERMINAL_STEP,'skipped');
  requireValue(prepare.number<upload.number&&upload.number<terminal.number
    &&own.steps.every(s=>s===prepare||['success','skipped'].includes(s.conclusion)),'failed_terminal_executed');
  requireValue(['configure','release-slot-recovery'].every(name=>{
    const ss=jobs.filter(j=>j.name===name);return ss.length===1&&ss[0].run_id===Number(p.run_id)
      &&ss[0].head_sha===p.sha&&ss[0].run_attempt===1&&ss[0].status==='completed'
      &&ss[0].conclusion==='skipped'&&Array.isArray(ss[0].steps)&&ss[0].steps.length===0;
  }),'configuration_executed');
  requireValue(boundedList(await get('/actions/runs/'+p.run_id+'/artifacts?per_page=100'),
    'artifacts','incomplete_failed_terminal_artifacts').length===0,'original_terminal_already_published');
  requireValue((await get('/contents/tools/release_slot_close.js?ref='+p.sha))?.sha===p.original_code,
    'original_terminal_source_changed');
  requireValue(typeof api.jobLog==='function','original_terminal_log_unavailable');
  const bytes=await api.jobLog(p.job_id);
  requireValue(Buffer.isBuffer(bytes)&&bytes.length>0&&bytes.length<=2*1024*1024
    &&Buffer.from(bytes.toString('utf8')).equals(bytes),'original_terminal_log_unavailable');
  const lines=bytes.toString('utf8').split(/\r?\n/).filter(l=>/^\d{4}-\d\d-\d\dT[0-9:.]+Z release_slot=/.test(l));
  requireValue(lines.length===1&&lines[0].endsWith('Z release_slot=held reason=record_changed'),'failed_terminal_reason_changed');
  const b=C.binding(await get('/pulls/'+p.pr),p.sha);
  requireValue(b&&same(C.bindingSnapshot(b),{pr:p.pr,head_sha:p.head_sha,comment_id:p.comment_id,
    owner_id:p.owner_id,updated_at:p.updated_at}),'binding_changed');
  const c={sha:p.sha,deploy:Number(p.deploy),run:Number(p.run_id),configure:'skipped',activation:false,
    terminalRecovery:true,publisherBodyRecovery:true};
  const snapshot=await C.selectReleaseRuns(await get('/actions/runs?head_sha='+p.sha+'&per_page=100'),c,get);
  requireValue(!snapshot.waiting&&C.gateState(snapshot.runs,c)==='ready','gates_changed');
  C.verifyConfigure(raw,c);
  const a=snapshot.runs.workflow_runs.find(x=>x.path===PREFIX+C.ACTIVATION.file&&x.event==='workflow_run');
  const aj=await get('/actions/runs/'+a.id+'/attempts/1/jobs?per_page=100');
  C.verifyActivation(aj,a,c,snapshot.runs.workflow_runs.find(x=>x.path===PREFIX+'modeled-conversion-monitor.yml'&&x.event==='workflow_run')?.id);
  requireValue(aj.jobs[0].steps.length===0,'activation_executed');
  return {original,snapshot:snapshot.runs,binding:b};
}
async function failedPriorWaveTerminal(api){
  const p=PRIOR_WAVE_RECOVERY,q=p.previous,get=path=>api('GET',API_ROOT+path),C=require('./release_slot_close');
  const original=await readRecord(api,p.commit);
  requireValue(original.blob===p.blob&&original.record_digest===p.record_digest,'original_prior_wave_record_changed');
  const r=validateRelease(original.record).release;
  requireValue(r.repository_sha===p.sha&&r.phase==='pending_closure'&&r.kind==='installed'
    &&r.release.run_id===p.deploy&&r.release.run_attempt===1,'original_prior_wave_record_changed');
  await authenticatePublication(api,original);await deploymentProof(api,installationReceipt(original.record));
  const run=await get('/actions/runs/'+p.run_id);
  runIdentity(run,{id:p.run_id,attempt:1,head:p.sha,workflow:CLOSER,event:'workflow_run',conclusion:'failure'});
  requireValue(run.display_title==='deploy-proof:v1:'+p.deploy+':1','failed_terminal_origin_changed');
  const raw=await get('/actions/runs/'+p.run_id+'/attempts/1/jobs?per_page=100');
  const jobs=boundedList(raw,'jobs','incomplete_failed_terminal_jobs'),rows=jobs.filter(j=>j.id===p.job_id&&j.name==='release-slot');
  requireValue(jobs.length===3&&rows.length===1&&rows[0].run_id===Number(p.run_id)&&rows[0].run_attempt===1
    &&rows[0].head_sha===p.sha&&rows[0].status==='completed'&&rows[0].conclusion==='failure'
    &&Array.isArray(rows[0].steps),'failed_terminal_not_original');
  const own=rows[0],one=(name,result)=>{const ss=own.steps.filter(s=>s.name===name);
    requireValue(ss.length===1&&ss[0].status==='completed'&&ss[0].conclusion===result,'failed_terminal_executed');return ss[0];};
  const prepare=one('Prepare the exact release proof before terminal publication','failure');
  const upload=one(TERMINAL_BINDING_STEP,'skipped'),terminal=one(TERMINAL_STEP,'skipped');
  requireValue(prepare.number<upload.number&&upload.number<terminal.number
    &&own.steps.every(s=>s===prepare||['success','skipped'].includes(s.conclusion)),'failed_terminal_executed');
  requireValue(['configure','release-slot-recovery'].every(name=>{
    const ss=jobs.filter(j=>j.name===name);return ss.length===1&&ss[0].run_id===Number(p.run_id)
      &&ss[0].head_sha===p.sha&&ss[0].run_attempt===1&&ss[0].status==='completed'
      &&ss[0].conclusion==='skipped'&&Array.isArray(ss[0].steps)&&ss[0].steps.length===0;
  }),'configuration_executed');
  requireValue(boundedList(await get('/actions/runs/'+p.run_id+'/artifacts?per_page=100'),
    'artifacts','incomplete_failed_terminal_artifacts').length===0,'original_terminal_already_published');
  requireValue((await get('/contents/tools/release_slot_close.js?ref='+p.sha))?.sha===p.original_code,'original_terminal_source_changed');
  requireValue(typeof api.jobLog==='function','original_terminal_log_unavailable');
  const exactLog=async(job,reason)=>{
    const bytes=await api.jobLog(job);
    requireValue(Buffer.isBuffer(bytes)&&bytes.length>0&&bytes.length<=2*1024*1024
      &&Buffer.from(bytes.toString('utf8')).equals(bytes),'original_terminal_log_unavailable');
    const lines=bytes.toString('utf8').split(/\r?\n/).filter(l=>/^\d{4}-\d\d-\d\dT[0-9:.]+Z release_slot=/.test(l));
    requireValue(lines.length===1&&lines[0].endsWith('Z release_slot=held reason='+reason),'failed_terminal_reason_changed');
  };
  await exactLog(p.job_id,'ambiguous_run');await exactLog(q.verify_job_id,'main_changed');
  const previous=await get('/actions/runs/'+q.deploy);
  runIdentity(previous,{id:q.deploy,attempt:1,head:q.sha,workflow:DEPLOY,event:'push',conclusion:'failure'});
  const priorJobs=boundedList(await get('/actions/runs/'+q.deploy+'/attempts/1/jobs?per_page=100'),
    'jobs','previous_deploy_jobs_incomplete');
  requireValue(priorJobs.filter(j=>j.name==='verify'&&j.id===q.verify_job_id&&j.run_id===Number(q.deploy)
    &&j.head_sha===q.sha&&j.run_attempt===1&&j.status==='completed'&&j.conclusion==='failure').length===1,
    'previous_deploy_jobs_untrusted');
  const previousBinding=C.binding(await get('/pulls/'+q.pr),q.sha);
  requireValue(previousBinding&&same(C.bindingSnapshot(previousBinding),{pr:q.pr,head_sha:q.head_sha,
    comment_id:q.comment_id,owner_id:q.owner_id,updated_at:q.updated_at}),'previous_binding_changed');
  const c={sha:p.sha,deploy:Number(p.deploy),run:Number(p.run_id),configure:'skipped',activation:false,
    terminalRecovery:true,priorWaveRecovery:true};
  const rawSnapshot=await get('/actions/runs?head_sha='+p.sha+'&per_page=100');
  const withoutOld=await C.selectPreviousFailedWave(rawSnapshot,c,get);
  const removed=rawSnapshot.workflow_runs.filter(x=>!withoutOld.workflow_runs.some(y=>y.id===x.id)).map(x=>x.id).sort((a,b)=>a-b);
  requireValue(same(removed,[...p.old_children].sort((a,b)=>a-b)),'original_previous_wave_missing');
  const snapshot=await C.selectReleaseRuns(withoutOld,c,get);
  // The classifier positively authenticates all nine exact old children and failed parent.
  requireValue(!snapshot.waiting&&C.gateState(snapshot.runs,c)==='ready','gates_changed');
  C.verifyConfigure(raw,c);
  const a=snapshot.runs.workflow_runs.find(x=>x.path===PREFIX+C.ACTIVATION.file&&x.event==='workflow_run');
  const aj=await get('/actions/runs/'+a.id+'/attempts/1/jobs?per_page=100');
  C.verifyActivation(aj,a,c,snapshot.runs.workflow_runs.find(x=>x.path===PREFIX+'modeled-conversion-monitor.yml'&&x.event==='workflow_run')?.id);
  requireValue(aj.jobs[0].steps.length===0,'activation_executed');
  return {original,snapshot:snapshot.runs,previousBinding};
}
async function failedUnpublishedTerminal(api){
  const p=UNPUBLISHED_TERMINAL_RECOVERY,get=path=>api('GET',API_ROOT+path),C=require('./release_slot_close');
  const original=await readRecord(api,p.commit),pending=await readRecord(api,p.pending_commit);
  requireValue(original.blob===p.blob&&original.record_digest===p.record_digest
    &&pending.blob===p.pending_blob&&pending.record_digest===p.pending_digest,'original_unpublished_record_changed');
  const r=validateRelease(original.record).release,prior=validateRelease(pending.record).release;
  requireValue(r.repository_sha===p.sha&&r.phase==='pending_terminal'&&r.kind==='installed'&&!r.closure.recovery
    &&r.closure.run_id===p.run_id&&r.closure.job==='release-slot'&&r.release.run_id===p.deploy
    &&prior.phase==='pending_closure','original_unpublished_record_changed');
  const restored=clone(original.record),s=restored.repository_release;
  s.phase=prior.phase;s.closure=prior.closure;s.observation=prior.observation;
  s.observation_producer=prior.observation_producer;s.baseline_eligible=prior.baseline_eligible;
  requireValue(same(restored,pending.record),'original_installation_record_changed');
  await requireAncestry(api,p.pending_commit,p.commit);
  await authenticatePublication(api,pending);await deploymentProof(api,installationReceipt(pending.record));
  const run=await get('/actions/runs/'+p.run_id);
  runIdentity(run,{id:p.run_id,attempt:1,head:p.sha,workflow:CLOSER,event:'workflow_run',conclusion:'failure'});
  requireValue(run.display_title==='deploy-proof:v1:'+p.deploy+':1','failed_terminal_origin_changed');
  const raw=await get('/actions/runs/'+p.run_id+'/attempts/1/jobs?per_page=100');
  const jobs=boundedList(raw,'jobs','incomplete_failed_terminal_jobs'),rows=jobs.filter(j=>j.id===p.job_id&&j.name==='release-slot');
  requireValue(rows.length===1&&rows[0].run_id===Number(p.run_id)&&rows[0].run_attempt===1&&rows[0].head_sha===p.sha
    &&rows[0].status==='completed'&&rows[0].conclusion==='failure'&&Array.isArray(rows[0].steps),'failed_terminal_not_original');
  const own=rows[0],one=(name,result)=>{const ss=own.steps.filter(s=>s.name===name);
    requireValue(ss.length===1&&ss[0].status==='completed'&&ss[0].conclusion===result,'failed_terminal_executed');return ss[0];};
  const prepare=one('Prepare the exact release proof before terminal publication','failure');
  const upload=one(TERMINAL_BINDING_STEP,'skipped'),terminal=one(TERMINAL_STEP,'skipped');
  requireValue(prepare.number<upload.number&&upload.number<terminal.number
    &&own.steps.every(s=>s===prepare||['success','skipped'].includes(s.conclusion)),'failed_terminal_executed');
  requireValue(boundedList(await get('/actions/runs/'+p.run_id+'/artifacts?per_page=100'),
    'artifacts','incomplete_failed_terminal_artifacts').length===0,'original_terminal_already_published');
  requireValue((await get('/contents/tools/release_slot_close.js?ref='+p.sha))?.sha===p.original_code,'original_terminal_source_changed');
  requireValue(typeof api.jobLog==='function','original_terminal_log_unavailable');
  const bytes=await api.jobLog(p.job_id);
  requireValue(Buffer.isBuffer(bytes)&&bytes.length>0&&bytes.length<=2*1024*1024
    &&Buffer.from(bytes.toString('utf8')).equals(bytes),'original_terminal_log_unavailable');
  const lines=bytes.toString('utf8').split(/\r?\n/).filter(l=>/^\d{4}-\d\d-\d\dT[0-9:.]+Z release_slot=/.test(l));
  requireValue(lines.length===1&&lines[0].endsWith('Z release_slot=held reason=diagnostics_write_unknown'),'failed_terminal_reason_changed');
  const c={sha:p.sha,deploy:Number(p.deploy),run:Number(p.run_id),configure:'skipped',activation:false,
    terminalRecovery:true,unpublishedRecovery:true};
  const snapshot=await C.selectReleaseRuns(await get('/actions/runs?head_sha='+p.sha+'&per_page=100'),c,get);
  requireValue(!snapshot.waiting&&C.gateState(snapshot.runs,c)==='ready'
    &&same(C.gateReferences(snapshot.runs),r.closure.gates),'gates_changed');
  C.verifyConfigure(raw,c);
  const configuration=jobs.find(j=>j.name==='configure');
  requireValue(Array.isArray(configuration.steps)&&configuration.steps.length===0,'configuration_executed');
  const activation=snapshot.runs.workflow_runs.find(x=>x.path===PREFIX+C.ACTIVATION.file&&x.event==='workflow_run');
  const activationJobs=await get('/actions/runs/'+activation.id+'/attempts/1/jobs?per_page=100');
  C.verifyActivation(activationJobs,activation,c,snapshot.runs.workflow_runs.find(x=>x.path===PREFIX+'modeled-conversion-monitor.yml'&&x.event==='workflow_run')?.id);
  requireValue(activationJobs.jobs[0].steps.length===0,'activation_executed');
  return original;
}
async function failedGateEvidence(api){
  const p=GATE_RECOVERY,get=path=>api('GET',API_ROOT+path);
  const original=await readRecord(api,p.commit);
  requireValue(original.blob===p.blob&&original.record_digest===p.record_digest
    &&original.record.repository_release.phase==='pending_closure','original_gate_record_changed');
  await authenticatePublication(api,original);
  for(const [runId,workflow,origin]of [[p.tls_run_id,p.tls_workflow,'deploy-proof:v1:'+p.deploy+':2'],
    [p.run_id,CLOSER,'deploy-proof:v1:'+p.deploy+':2']]){
    const run=await get('/actions/runs/'+runId);
    runIdentity(run,{id:runId,attempt:1,head:p.sha,workflow,event:'workflow_run',conclusion:'failure'});
    requireValue(run.display_title===origin,'failed_gate_origin_changed');
  }
  failedGateJobs(await get('/actions/runs/'+p.tls_run_id+'/attempts/1/jobs?per_page=100'),
    await get('/actions/runs/'+p.run_id+'/attempts/1/jobs?per_page=100'));
  requireValue(typeof api.jobLog==='function','failed_gate_log_unavailable');
  const lines=(await api.jobLog(p.tls_job_id)).toString('utf8').split(/\r?\n/)
    .filter(l=>/deployment_release=refused reason=/.test(l));
  requireValue(lines.length===1&&/deployment_release=refused reason=api_http_502$/.test(lines[0]),'failed_gate_reason_changed');
  return original;
}
async function gateContinuationContext({env,event,api,workflow,job}){
  const p=GATE_RECOVERY,ref='refs/heads/'+p.branch,source=env.GITHUB_SHA;
  requireValue(env.GITHUB_REPOSITORY===REPOSITORY&&env.GITHUB_EVENT_NAME==='push'
    &&env.GITHUB_REF===ref&&env.GITHUB_WORKFLOW_REF===REPOSITORY+'/'+workflow+'@'+ref
    &&env.GITHUB_JOB===job&&env.GITHUB_RUN_ATTEMPT==='1'&&sha(source)&&source!==p.sha
    &&positive(env.GITHUB_RUN_ID)&&event?.repository?.full_name===REPOSITORY
    &&event.ref===ref&&event.before===p.before&&event.after===source&&event.created===false
    &&event.deleted===false&&event.forced===false,'untrusted_gate_continuation');
  const get=path=>api('GET',API_ROOT+path);
  requireValue((await get('/git/ref/heads/main'))?.object?.sha===p.sha,'main_changed');
  const native=await get('/actions/runs/'+env.GITHUB_RUN_ID);
  runIdentity(native,{id:env.GITHUB_RUN_ID,attempt:1,head:source,workflow,event:'push',branch:p.branch,completed:false});
  requireValue(['queued','in_progress'].includes(native.status)&&native.actor?.id===p.owner_id,'gate_continuation_not_active');
  const jobs=boundedList(await get('/actions/runs/'+native.id+'/attempts/1/jobs?per_page=100'),'jobs','incomplete_gate_continuation_jobs');
  const own=jobs.filter(j=>j.name===job);
  requireValue(own.length===1&&own[0].run_id===native.id&&own[0].run_attempt===1&&own[0].head_sha===source
    &&own[0].status==='in_progress'&&own[0].conclusion===null&&positive(own[0].runner_id),'gate_continuation_not_active');
  const relation=await get('/compare/'+p.before+'...'+source+'?per_page=1');
  requireValue(relation?.status==='ahead'&&relation.merge_base_commit?.sha===p.before,'recovery_source_changed');
  const runs=boundedList(await get('/actions/runs?head_sha='+source+'&per_page=100'),'workflow_runs','incomplete_gate_continuation_ci');
  for(const [name,path]of [['Regression tests',PREFIX+'regression-tests.yml'],['Retired domain guard',PREFIX+'retired-domain-guard.yml']]){
    const found=runs.filter(r=>r.name===name&&r.event==='pull_request');
    requireValue(found.length===1&&found[0].path===path&&found[0].head_sha===source
      &&found[0].head_repository?.full_name===REPOSITORY&&found[0].run_attempt===1
      &&found[0].status==='completed'&&found[0].conclusion==='success','recovery_ci_unconfirmed');
  }
  const pr=await get('/pulls/'+p.pr),C=require('./release_slot_close'),b=C.binding(pr,p.sha);
  requireValue(b&&same(C.bindingSnapshot(b),{pr:p.pr,head_sha:p.head_sha,comment_id:p.comment_id,
    owner_id:p.owner_id,updated_at:p.updated_at}),'binding_changed');
  requireValue(C.recordState(await get('/issues/comments/'+p.comment_id),b)==='active','prepared_terminal_already_released');
  const original=await failedGateEvidence(api);await requireOps(api,p.sha);
  requireValue((await get('/git/ref/heads/main'))?.object?.sha===p.sha,'main_changed');
  return {source,runId:String(native.id),original,b};
}
async function continuationTls(api,runId,source){
  const p=GATE_RECOVERY,run=await api('GET',API_ROOT+'/actions/runs/'+runId);
  runIdentity(run,{id:runId,attempt:1,head:source,workflow:p.tls_workflow,event:'push',branch:p.branch});
  requireValue(run.actor?.id===p.owner_id,'foreign_tls_continuation');
  const jobs=boundedList(await api('GET',API_ROOT+'/actions/runs/'+runId+'/attempts/1/jobs?per_page=100'),
    'jobs','incomplete_tls_continuation_jobs');
  requireValue(jobs.length===1&&jobs[0].name==='strict-tls-preflight'&&jobs[0].run_id===run.id
    &&jobs[0].head_sha===source&&jobs[0].run_attempt===1&&jobs[0].status==='completed'
    &&jobs[0].conclusion==='success'&&Array.isArray(jobs[0].steps),'tls_continuation_unconfirmed');
  for(const name of [RESOLVER_STEP,'Prepare SSH private key',p.tls_step]){
    const rows=jobs[0].steps.filter(s=>s.name===name);
    requireValue(rows.length===1&&rows[0].status==='completed'&&rows[0].conclusion==='success','tls_continuation_unconfirmed');
  }
  return run;
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
        if(api.transientReads!==false&&[502,503].includes(response.status)&&attempt<2){
          let retryMs=attempt===0?250:750;
          const retryAfter=response.headers?.get('retry-after');
          if(retryAfter!==null&&retryAfter!==undefined){
            const requested=/^[0-9]+$/.test(retryAfter)?Number(retryAfter)*1000:Date.parse(retryAfter)-Date.now();
            requireValue(Number.isFinite(requested),'api_http_'+response.status);
            retryMs=Math.max(retryMs,requested);
          }
          requireValue(performance.now()+retryMs<deadline,'api_http_'+response.status);
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
    requireValue([TERMINAL_RECOVERY.job_id,TERMINAL_RECOVERY.continuation_job_id,TERMINAL_RECOVERY.observation_job_id,
      TERMINAL_RECOVERY.read_failure_job_id,GATE_RECOVERY.tls_job_id,UNPUBLISHED_TERMINAL_RECOVERY.job_id,
      PRIOR_WAVE_RECOVERY.job_id,PRIOR_WAVE_RECOVERY.previous.verify_job_id,PUBLISHER_BODY_RECOVERY.job_id,
      137646126584,137658227484,137707302320,137760498372].includes(job),'untrusted_terminal_log');
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
// One finite forward release after PR1600 failed before acquiring credentials.
// No retry, dispatch, installation receipt or publisher mutation is performed here.
const UNSTARTED_FORWARD = {
  sha:"970e1bab10e44b2ab8148e609840be8630f5d5db",
  head_sha:"c1d34ae37aca71377c4b63541f0f92e137b3e296",
  pr:1600,
  deploy:"44100865685",
  comment_id:6928374515,
  owner_id:226193297,
  updated_at:"2026-11-17T14:13:31Z",
  workflow_blob:"ea398626670c37c70f4eace3c503f813cc3d2c18",
  application_sha:"fd9b28dab2147f13f3b0d0f7f856b14392b141d2",
  commit:"876c980f329b01b6720dc7272ce5f36434b8ad41",
  blob:"4e8fc7a4d6fd0cab85dfb830428a0cdbaaaf5a4f",
  record_digest:"1399ed7731510e27878ccadf4f375d6bb2bea990d7bc86543356e1252054ae7e",
  jobs: [
    {id:134100184310,name:"verify-inventory",conclusion:"success",steps:[
      ["Set up job","success"],
      ["Checkout exact verification SHA","success"],
      ["Setup PHP","success"],
      ["Verify required inventory and architecture","success"],
      ["Post Checkout exact verification SHA","success"],
      ["Complete job","success"],
    ]},
    {id:134100184973,name:"verify-syntax",conclusion:"success",steps:[
      ["Set up job","success"],
      ["Checkout exact verification SHA","success"],
      ["Setup PHP","success"],
      ["PHP syntax check","success"],
      ["Post Checkout exact verification SHA","success"],
      ["Complete job","success"],
    ]},
    {id:134104653686,name:"verify / diagnostics",conclusion:"success",steps:[
      ["Set up job","success"],
      ["Checkout exact verification SHA","success"],
      ["Setup PHP","success"],
      ["Run every required group in this lane","success"],
      ["Post Checkout exact verification SHA","success"],
      ["Complete job","success"],
    ]},
    {id:134104653810,name:"verify / application",conclusion:"failure",steps:[
      ["Set up job","success"],
      ["Checkout exact verification SHA","success"],
      ["Setup PHP","success"],
      ["Run every required group in this lane","failure"],
      ["Post Checkout exact verification SHA","success"],
      ["Complete job","success"],
    ]},
    {id:134106119021,name:"deploy",conclusion:"failure",steps:[
      ["Set up job","success"],
      ["Require the complete deployment verification","failure"],
      ["Checkout exact deployment SHA","skipped"],
      ["Verify deployment SHA is current main","skipped"],
      ["Resolve original accepted installation and complete release intent","skipped"],
      ["Prepare canonical SSH private key","skipped"],
      ["Select installation or prove the unchanged application without mutation","skipped"],
      ["Build exact deployment bundle","skipped"],
      ["Sync canonical production checkout","skipped"],
      ["Apply database migrations and fence the old hotel contour","skipped"],
      ["Reconcile canonical MAX webhook ownership","skipped"],
      ["Ensure Metrika offline uploader cron","skipped"],
      ["Apply profile and retain exact diagnostic snapshot","skipped"],
      ["Fail failed installation","failure"],
      ["Complete job","success"],
    ]},
    {id:134106202492,name:"verify",conclusion:"skipped",steps:[
    ]},
  ],
  children: [
    {"id":44102603173,"path":".github/workflows/modeled-pipeline-monitor-activation.yml","title":"monitor-proof:v1:44102585963:1"},
    {"id":44102586106,"path":".github/workflows/max-tls-preflight.yml","title":"deploy-proof:v1:44100865685:1"},
    {"id":44102585970,"path":".github/workflows/telegram-production-health.yml","title":"deploy-proof:v1:44100865685:1"},
    {"id":44102586115,"path":".github/workflows/tour-selection-host.yml","title":"deploy-proof:v1:44100865685:1"},
    {"id":44102585963,"path":".github/workflows/modeled-conversion-monitor.yml","title":"deploy-proof:v1:44100865685:1"},
    {"id":44102585935,"path":".github/workflows/live-session-diagnostics.yml","title":"deploy-proof:v1:44100865685:1"},
    {"id":44102586276,"path":".github/workflows/modeled-conversion-value-export.yml","title":"deploy-proof:v1:44100865685:1"},
    {"id":44102586451,"path":".github/workflows/publish-conversation-diagnostics.yml","title":"deploy-proof:v1:44100865685:1"},
    {"id":44102585942,"path":".github/workflows/max-production-health.yml","title":"deploy-proof:v1:44100865685:1"},
  ],
};
function forwardMarker(pr,head) {
  const body=pr?.body;
  requireValue(typeof body==='string'&&body.length<=65536,'invalid_forward_marker');
  const matches=[...body.matchAll(/<!-- release-forward-unstarted:v1 (\{[^\r\n]*\}) -->/g)];
  requireValue((body.match(/<!--\s*release-forward-unstarted:/g)||[]).length===1&&matches.length===1,
    'invalid_forward_marker');
  let m;try{m=JSON.parse(matches[0][1]);}catch{throw new Refusal('invalid_forward_marker');}
  const p=UNSTARTED_FORWARD;
  requireValue(keys(m,['previous_pr','previous_sha','previous_run_id','head_sha'])&&m.previous_pr===p.pr
    &&m.previous_sha===p.sha&&m.previous_run_id===p.deploy&&sha(head)&&m.head_sha===head,
    'invalid_forward_marker');return m;
}
async function proveUnstartedDeployment(api) {
  const p=UNSTARTED_FORWARD,get=path=>api('GET',API_ROOT+path);
  const old=await get('/actions/runs/'+p.deploy);
  runIdentity(old,{id:p.deploy,attempt:1,head:p.sha,workflow:DEPLOY,event:'push',conclusion:'failure'});
  requireValue(old.head_commit?.id===p.sha,'forward_original_changed');
  requireValue((await get('/contents/'+DEPLOY+'?ref='+p.sha))?.sha===p.workflow_blob,'forward_source_changed');
  const jobs=boundedList(await get('/actions/runs/'+p.deploy+'/jobs?filter=all&per_page=100'),'jobs','forward_jobs_incomplete');
  requireValue(jobs.length===p.jobs.length&&new Set(jobs.map(j=>j.id)).size===jobs.length,'forward_jobs_incomplete');
  for(const expected of p.jobs){
    const j=jobs.find(j=>j.id===expected.id);
    requireValue(j&&j.name===expected.name&&j.run_id===Number(p.deploy)&&j.run_attempt===1
      &&j.head_sha===p.sha&&j.status==='completed'&&j.conclusion===expected.conclusion&&Array.isArray(j.steps)
      &&j.steps.every(s=>s.status==='completed')&&same(j.steps.map(s=>[s.name,s.conclusion]),expected.steps),
      'forward_installation_not_unstarted');
  }
  const artifacts=boundedList(await get('/actions/runs/'+p.deploy+'/artifacts?per_page=100'),'artifacts','forward_artifacts_incomplete');
  requireValue(artifacts.length===0,'forward_original_published');
  const runs=boundedList(await get('/actions/runs?head_sha='+p.sha+'&per_page=100'),'workflow_runs','forward_runs_incomplete');
  requireValue(new Set(runs.map(r=>r.id)).size===runs.length,'forward_runs_incomplete');
  const deploys=runs.filter(r=>r.path===DEPLOY);
  requireValue(deploys.length===1&&deploys[0].id===Number(p.deploy)&&deploys[0].run_attempt===1
    &&deploys[0].status==='completed'&&deploys[0].conclusion==='failure','forward_deploy_replayed');
  const children=runs.filter(r=>r.event==='workflow_run');
  requireValue(children.length===p.children.length,'forward_tail_incomplete');
  const childNames={
    'max-tls-preflight.yml':['strict-tls-preflight'],'max-production-health.yml':['inspect'],
    'telegram-production-health.yml':['inspect'],'publish-conversation-diagnostics.yml':['publish'],
    'live-session-diagnostics.yml':['publish'],'tour-selection-host.yml':['host','independent-editor'],
    'modeled-conversion-monitor.yml':['monitor'],'modeled-pipeline-monitor-activation.yml':['activate'],
    'modeled-conversion-value-export.yml':['configure','release-slot','release-slot-recovery']};
  for(const expected of p.children){
    const r=children.find(r=>r.id===expected.id);
    runIdentity(r,{id:String(expected.id),attempt:1,head:p.sha,workflow:expected.path,event:'workflow_run',conclusion:'skipped'});
    requireValue(r.head_commit?.id===p.sha&&r.display_title===expected.title,'forward_tail_changed');
    const js=boundedList(await get('/actions/runs/'+r.id+'/attempts/1/jobs?per_page=100'),'jobs','forward_tail_jobs_incomplete');
    const names=childNames[expected.path.slice(PREFIX.length)];
    requireValue(js.length===names.length&&same(js.map(j=>j.name).sort(),[...names].sort())
      &&new Set(js.map(j=>j.id)).size===js.length&&js.every(j=>positive(j.id)&&j.run_id===r.id&&j.run_attempt===1
        &&j.head_sha===p.sha&&j.status==='completed'&&j.conclusion==='skipped'&&Array.isArray(j.steps)&&j.steps.length===0),
      'forward_tail_executed');
  }
  return {previous_pr:p.pr,previous_sha:p.sha,previous_run_id:p.deploy,installation:'not_started'};
}
async function proveUnstartedForward({api,pr,head,phase,source}) {
  const p=UNSTARTED_FORWARD,C=require('./release_slot_close'),get=path=>api('GET',API_ROOT+path);
  requireValue(['before_handoff','after_handoff','installation'].includes(phase)&&positive(pr)&&pr!==p.pr&&sha(head),
    'invalid_forward_context');
  const installation=phase==='installation',main=installation?source:p.sha;
  requireValue(sha(main)&&(await get('/git/ref/heads/main'))?.object?.sha===main,'main_changed');
  const target=await get('/pulls/'+pr);
  requireValue(target?.number===pr&&target.head?.sha===head&&target.head?.repo?.full_name===REPOSITORY
    &&target.base?.ref==='main'&&target.base.repo?.full_name===REPOSITORY
    &&(installation?target.merged===true&&target.state==='closed'&&target.merge_commit_sha===source:
      target.state==='open'&&target.draft===false&&target.base.sha===p.sha),'forward_pull_changed');
  forwardMarker(target,head);
  const original=C.binding(await get('/pulls/'+p.pr),p.sha);
  requireValue(original&&same(C.bindingSnapshot(original),{pr:p.pr,head_sha:p.head_sha,comment_id:p.comment_id,
    owner_id:p.owner_id,updated_at:p.updated_at}),'forward_original_binding_changed');
  let binding=original;
  if(phase!=='before_handoff'){
    // Open forward PR uses the identical canonical binding parser without claiming it is merged.
    binding=C.binding(installation?target:{...target,merged:true,state:'closed',merge_commit_sha:p.sha},main);
    requireValue(binding&&binding.comment_id===p.comment_id&&binding.owner_id===p.owner_id
      &&Date.parse(binding.updated_at)>Date.parse(p.updated_at),'forward_owner_changed');
  }
  requireValue(C.recordState(await get('/issues/comments/'+p.comment_id),binding)==='active','forward_publisher_changed');
  if(installation){
    const commit=await get('/commits/'+source);
    requireValue(commit?.sha===source&&Array.isArray(commit.parents)&&commit.parents.length===2
      &&commit.parents[0]?.sha===p.sha&&commit.parents[1]?.sha===head,'forward_not_direct_successor');
  }
  const evidence=await proveUnstartedDeployment(api);
  const location=await readRecord(api);
  requireValue(location.blob===p.blob&&location.record_digest===p.record_digest,
    'forward_accepted_record_changed');
  await requireAncestry(api,p.commit,location.commit);
  const accepted=await resolveCurrent(api,{sha:p.application_sha},location);
  await requireOps(api,p.application_sha);
  requireValue(accepted.ready===true&&accepted.perform_actions===true&&accepted.application_sha===p.application_sha,
    'forward_original_application_unaccepted');
  requireValue((await get('/git/ref/heads/main'))?.object?.sha===main,'main_changed');
  requireValue(C.recordState(await get('/issues/comments/'+p.comment_id),binding)==='active','forward_publisher_changed');
  const freshTarget=await get('/pulls/'+pr);
  const snapshot=pr=>({number:pr.number,state:pr.state,merged:pr.merged,draft:pr.draft??null,merge_commit_sha:pr.merge_commit_sha??null,
    head:{sha:pr.head?.sha??null,repository:pr.head?.repo?.full_name??null},
    base:{ref:pr.base?.ref??null,sha:pr.base?.sha??null,repository:pr.base?.repo?.full_name??null}});
  requireValue(same(snapshot(freshTarget),snapshot(target))&&same(forwardMarker(freshTarget,head),forwardMarker(target,head)),
    'forward_pull_changed');
  const freshOriginal=C.binding(await get('/pulls/'+p.pr),p.sha);
  requireValue(same(C.bindingSnapshot(freshOriginal),C.bindingSnapshot(original)),'forward_original_binding_changed');
  if(phase!=='before_handoff'){
    const freshBinding=C.binding(installation?freshTarget:{...freshTarget,merged:true,state:'closed',merge_commit_sha:p.sha},main);
    requireValue(same(C.bindingSnapshot(freshBinding),C.bindingSnapshot(binding)),'forward_owner_changed');
  }
  runIdentity(await get('/actions/runs/'+p.deploy),{id:p.deploy,attempt:1,head:p.sha,workflow:DEPLOY,event:'push',conclusion:'failure'});
  const latest=await readRecord(api);
  requireValue(latest.blob===p.blob&&latest.record_digest===p.record_digest,'forward_accepted_record_changed');
  await requireAncestry(api,p.commit,latest.commit);
  requireValue((await get('/git/ref/heads/main'))?.object?.sha===main,'main_changed');
  requireValue(C.recordState(await get('/issues/comments/'+p.comment_id),binding)==='active','forward_publisher_changed');
  return {...evidence,phase,head_sha:head,binding:C.bindingSnapshot(binding),application_sha:accepted.application_sha};
}
async function admitForwardInstallation(api,c,event) {
  const get=path=>api('GET',API_ROOT+path),p=UNSTARTED_FORWARD;
  const commit=await get('/commits/'+c.sha);
  requireValue(commit?.sha===c.sha&&Array.isArray(commit.parents)&&commit.parents.length>=1&&commit.parents.length<=2
    &&commit.parents.every(parent=>sha(parent.sha)),'forward_source_unconfirmed');
  // Only PR1667 may succeed the pinned partially installed #1662 on the first main push.
  const partialBase='302ba66b5739e369a4df94471c8cfe9c054dd070';
  if(commit.parents.some(parent=>parent.sha===partialBase)||event.before===partialBase){
    requireValue(c.event==='push'&&c.attempt===1&&event.forced===false&&event.before===partialBase
      &&event.after===c.sha&&commit.parents.length===2&&commit.parents[0].sha===partialBase,
      'invalid_partial_forward_source');
    const {provePartialInstall1662Forward}=require('./partial_install_1662_forward');
    await provePartialInstall1662Forward({api,pr:1667,head:commit.parents[1].sha,
      phase:'installation',source:c.sha,runId:c.id,event});
    return {expectedCheckout:partialBase};
  }
  const failedVerifyBase='b3386d558f72e9eb80acefad9e87b1ab91b4426f';
  if(commit.parents.some(parent=>parent.sha===failedVerifyBase)||event.before===failedVerifyBase){
    requireValue(c.event==='push'&&c.attempt===1&&event.forced===false&&event.before===failedVerifyBase
      &&event.after===c.sha&&commit.parents.length===2&&commit.parents[0].sha===failedVerifyBase,
      'invalid_exhausted_verify_forward_source');
    const pulls=await get('/commits/'+c.sha+'/pulls?per_page=100');
    requireValue(Array.isArray(pulls)&&pulls.length<100,'incomplete_pulls');
    const merged=pulls.filter(pr=>pr.merge_commit_sha===c.sha&&pr.state==='closed');
    requireValue(merged.length===1,'ambiguous_pull');
    const {proveExhaustedVerify1664Forward}=require('./exhausted_verify_1664_forward');
    return proveExhaustedVerify1664Forward({api,pr:merged[0].number,head:commit.parents[1].sha,
      phase:'installation',source:c.sha,runId:c.id,event});
  }
  // Only the immediate successor of the fixed failed source is in scope.
  if(!commit.parents.some(parent=>parent.sha===p.sha)){
    requireValue(event.before!==p.sha,'forward_not_direct_successor');return;
  }
  requireValue(c.event==='push'&&c.attempt===1&&event.forced===false&&event.before===p.sha
    &&event.after===c.sha,'untrusted_forward_installation');
  const pulls=await get('/commits/'+c.sha+'/pulls?per_page=100');
  requireValue(Array.isArray(pulls)&&pulls.length<100,'incomplete_pulls');
  const selected=pulls.filter(pr=>pr.merge_commit_sha===c.sha&&pr.state==='closed');
  requireValue(selected.length===1,'ambiguous_pull');
  const runs=boundedList(await get('/actions/runs?head_sha='+c.sha+'&per_page=100'),'workflow_runs','forward_runs_incomplete');
  const deploys=runs.filter(r=>r.path===DEPLOY);
  requireValue(deploys.length===1&&String(deploys[0].id)===c.id&&deploys[0].run_attempt===1,
    'forward_deploy_replayed');
  return proveUnstartedForward({api,pr:selected[0].number,head:selected[0].head.sha,phase:'installation',source:c.sha});
}

async function planDeployment({env,event,api,repository=process.cwd(),compare=v2}) {
  const c=await sourceContext(env,event,api,DEPLOY);
  requireValue(c.attempt===1&&c.job==='deploy'&&['push','workflow_dispatch'].includes(c.event),'untrusted_deployment_context');
  if(c.event==='push')requireValue(event.ref==='refs/heads/main'&&event.after===c.sha&&typeof event.forced==='boolean','untrusted_push');
  const forward=await admitForwardInstallation(api,c,event);
  const location=await readRecord(api),result={schema:1,protocol:'anytour-deployment-plan-v1',repository:REPOSITORY,
    repository_sha:c.sha,run_id:c.id,run_attempt:1,event:c.event,plan:'install',application_sha:c.sha,
    source:{commit:location.commit,blob:location.blob,record_digest:location.record_digest},
    baseline:null,installation:null,content:contentSummary(null,c.sha,c.sha),intent_scan:{status:'unknown'}};
  if(forward?.expectedCheckout){
    // This single successor performs its own full installation and receives the ordinary installed receipt.
    return {...result,expected_checkout_sha:forward.expectedCheckout};
  }
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
    branch:r.closure.recovery?recoveryBranch(r):'main',workflow:CLOSER,
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
/** One initial owner push admits only the two original MAX2 HOLD observations. */
async function resolveMax2HoldPairRead({env,event,api}) {
  const p=MAX2_HOLD_PAIR_READ,ref='refs/heads/'+p.branch;
  requireValue(env.GITHUB_REPOSITORY===REPOSITORY&&env.GITHUB_REF===ref
    &&env.GITHUB_WORKFLOW_REF===REPOSITORY+'/'+p.workflow+'@'+ref
    &&env.GITHUB_EVENT_NAME==='push'&&env.GITHUB_JOB===p.job
    &&env.GITHUB_RUN_ATTEMPT==='1'&&sha(env.GITHUB_SHA)&&positive(env.GITHUB_RUN_ID)
    &&env.GITHUB_ACTOR===p.owner&&env.GITHUB_TRIGGERING_ACTOR===p.owner
    &&event?.repository?.full_name===REPOSITORY&&event.repository.id===p.repository_id
    &&event.sender?.id===p.owner_id&&event.sender.login===p.owner
    &&event.ref===ref&&event.after===env.GITHUB_SHA&&event.before==='0'.repeat(40)
    &&event.created===true&&event.deleted===false&&event.forced===false,
    'untrusted_max2_hold_pair_read');
  const c={sha:env.GITHUB_SHA,id:String(env.GITHUB_RUN_ID),attempt:1,event:'push',workflow:p.workflow,job:p.job};
  requireValue((await api('GET',API_ROOT+'/git/ref/heads/main'))?.object?.sha===c.sha,'main_changed');
  const branchPath=API_ROOT+'/git/ref/heads/'+p.branch;
  requireValue((await api('GET',branchPath))?.object?.sha===c.sha,'max2_hold_pair_read_branch_changed');
  const run=await api('GET',API_ROOT+'/actions/runs/'+c.id);
  runIdentity(run,{id:c.id,attempt:1,head:c.sha,workflow:p.workflow,event:'push',completed:false,branch:p.branch});
  // The run summary may lag its already assigned native job below.
  requireValue(['queued','in_progress'].includes(run.status),'workflow_not_active');
  requireValue(run.actor?.id===p.owner_id&&run.actor.login===p.owner
    &&run.triggering_actor?.id===p.owner_id&&run.triggering_actor.login===p.owner,
    'foreign_max2_hold_pair_read_actor');
  const jobs=boundedList(await api('GET',API_ROOT+'/actions/runs/'+c.id+'/attempts/1/jobs?per_page=100'),
    'jobs','max2_hold_pair_read_jobs_incomplete');
  requireValue(jobs.length===1&&jobs[0].name===p.job&&jobs[0].run_id===run.id&&jobs[0].run_attempt===1
    &&jobs[0].head_sha===c.sha&&jobs[0].status==='in_progress'&&jobs[0].conclusion===null
    &&positive(jobs[0].runner_id),'max2_hold_pair_read_job_not_active');
  const runs=boundedList(await api('GET',API_ROOT+'/actions/workflows/'+p.workflow.slice(PREFIX.length)
    +'/runs?branch='+encodeURIComponent(p.branch)+'&per_page=100'),
    'workflow_runs','max2_hold_pair_read_inventory_incomplete');
  requireValue(runs.length===1&&String(runs[0].id)===c.id,'max2_hold_pair_read_replay_forbidden');
  runIdentity(runs[0],{id:c.id,attempt:1,head:c.sha,workflow:p.workflow,event:'push',completed:false,branch:p.branch});
  const location=await readRecord(api),result=await resolveCurrent(api,c,location);
  requireValue(result.ready===true&&result.perform_actions===true,'release_pending');
  requireValue((await api('GET',branchPath))?.object?.sha===c.sha,'max2_hold_pair_read_branch_changed');
  return {...await finalResolution(api,c,location,result),read_operation:p.operation};
}
/** One initial owner push admits only the four-hotel observation, before SSH. */
async function resolveHotelRegistryRead({env,event,api}) {
  const p=HOTEL_REGISTRY_READ,ref='refs/heads/'+p.branch;
  requireValue(env.GITHUB_REPOSITORY===REPOSITORY&&env.GITHUB_REF===ref
    &&env.GITHUB_WORKFLOW_REF===REPOSITORY+'/'+p.workflow+'@'+ref
    &&env.GITHUB_EVENT_NAME==='push'&&env.GITHUB_JOB===p.job
    &&env.GITHUB_RUN_ATTEMPT==='1'&&sha(env.GITHUB_SHA)&&positive(env.GITHUB_RUN_ID)
    &&env.GITHUB_ACTOR===p.owner&&env.GITHUB_TRIGGERING_ACTOR===p.owner
    &&event?.repository?.full_name===REPOSITORY&&event.repository.id===p.repository_id
    &&event.sender?.id===p.owner_id&&event.sender.login===p.owner
    &&event.ref===ref&&event.after===env.GITHUB_SHA&&event.before==='0'.repeat(40)
    &&event.created===true&&event.deleted===false&&event.forced===false,
    'untrusted_hotel_registry_read');
  const c={sha:env.GITHUB_SHA,id:String(env.GITHUB_RUN_ID),attempt:1,event:'push',workflow:p.workflow,job:p.job};
  requireValue((await api('GET',API_ROOT+'/git/ref/heads/main'))?.object?.sha===c.sha,'main_changed');
  const branchPath=API_ROOT+'/git/ref/heads/'+p.branch;
  requireValue((await api('GET',branchPath))?.object?.sha===c.sha,'hotel_registry_read_branch_changed');
  const run=await api('GET',API_ROOT+'/actions/runs/'+c.id);
  runIdentity(run,{id:c.id,attempt:1,head:c.sha,workflow:p.workflow,event:'push',completed:false,branch:p.branch});
  requireValue(run.status==='in_progress','workflow_not_active');
  requireValue(run.actor?.id===p.owner_id&&run.actor.login===p.owner
    &&run.triggering_actor?.id===p.owner_id&&run.triggering_actor.login===p.owner,
    'foreign_hotel_registry_read_actor');
  // A newly created ref alone is not one-use evidence: deleting and recreating it
  // must not turn a previous observation into another admitted operation.
  const runs=boundedList(await api('GET',API_ROOT+'/actions/workflows/'+p.workflow.slice(PREFIX.length)
    +'/runs?branch='+encodeURIComponent(p.branch)+'&per_page=100'),
    'workflow_runs','hotel_registry_read_inventory_incomplete');
  requireValue(runs.length===1&&String(runs[0].id)===c.id,'hotel_registry_read_replay_forbidden');
  runIdentity(runs[0],{id:c.id,attempt:1,head:c.sha,workflow:p.workflow,event:'push',completed:false,branch:p.branch});
  const location=await readRecord(api),result=await resolveCurrent(api,c,location);
  requireValue(result.ready===true&&result.perform_actions===true,'release_pending');
  requireValue((await api('GET',branchPath))?.object?.sha===c.sha,'hotel_registry_read_branch_changed');
  return {...await finalResolution(api,c,location,result),read_operation:p.operation};
}
/** The reviewed plan is data, not user consent. Only a separate first owner push
 * can select the existing editor; this resolver itself performs GETs only. */
async function proveOriginalNextTransfer(api) {
  const {run,job,sha:head,artifact,digest:artifactDigest}=ORIGINAL_NEXT_TRANSFER;
  const p=HOTEL_NEXT_TRANSFER;
  const [source,jobs,proof]=await Promise.all([
    api('GET',API_ROOT+'/actions/runs/'+run),
    api('GET',API_ROOT+'/actions/runs/'+run+'/attempts/1/jobs?per_page=100'),
    api('GET',API_ROOT+'/actions/artifacts/'+artifact)
  ]);
  runIdentity(source,{id:run,attempt:1,head,workflow:p.workflow,event:'push',branch:p.branch,conclusion:'failure'});
  requireValue(source.actor?.id===p.owner_id&&source.triggering_actor?.id===p.owner_id,'original_hotel_transfer_actor_changed');
  const rows=boundedList(jobs,'jobs','original_hotel_transfer_jobs_incomplete');
  requireValue(rows.length===1&&rows[0].id===job&&rows[0].run_id===run&&rows[0].head_sha===head&&
    rows[0].run_attempt===1&&rows[0].name===p.job&&rows[0].status==='completed'&&rows[0].conclusion==='failure'
    &&Array.isArray(rows[0].steps),'original_hotel_transfer_job_changed');
  for(const [name,outcome] of [
    ['Apply the installed editor once and retain its original receipts','failure'],
    ['Save the original sanitized transfer result','success']]){
    const steps=rows[0].steps.filter(step=>step.name===name);
    requireValue(steps.length===1&&steps[0].status==='completed'&&steps[0].conclusion===outcome,
      'original_hotel_transfer_result_changed');
  }
  requireValue(proof?.id===artifact&&proof.digest===artifactDigest&&proof.name==='issue-1566-hotel-next-transfer'
    &&proof.workflow_run?.id===run&&proof.workflow_run.head_sha===head
    &&proof.workflow_run.head_branch===p.branch,'original_hotel_transfer_artifact_changed');
}

async function resolveHotelTransfer({env,event,api},p=HOTEL_FOUR_TRANSFER) {
  const ref='refs/heads/'+p.branch;
  requireValue(env.GITHUB_REPOSITORY===REPOSITORY&&env.GITHUB_REF===ref
    &&env.GITHUB_WORKFLOW_REF===REPOSITORY+'/'+p.workflow+'@'+ref
    &&env.GITHUB_EVENT_NAME==='push'&&env.GITHUB_JOB===p.job
    &&env.GITHUB_RUN_ATTEMPT==='1'&&sha(env.GITHUB_SHA)&&positive(env.GITHUB_RUN_ID)
    &&env.GITHUB_ACTOR===p.owner&&env.GITHUB_TRIGGERING_ACTOR===p.owner
    &&event?.repository?.full_name===REPOSITORY&&event.repository.id===p.repository_id
    &&event.sender?.id===p.owner_id&&event.sender.login===p.owner
    &&event.ref===ref&&event.after===env.GITHUB_SHA&&event.before==='0'.repeat(40)
    &&event.created===true&&event.deleted===false&&event.forced===false,
    'untrusted_hotel_four_transfer');
  const c={sha:env.GITHUB_SHA,id:String(env.GITHUB_RUN_ID),attempt:1,event:'push',workflow:p.workflow,job:p.job};
  requireValue((await api('GET',API_ROOT+'/git/ref/heads/main'))?.object?.sha===c.sha,'main_changed');
  const branchPath=API_ROOT+'/git/ref/heads/'+p.branch;
  requireValue((await api('GET',branchPath))?.object?.sha===c.sha,'hotel_transfer_branch_changed');
  const run=await api('GET',API_ROOT+'/actions/runs/'+c.id);
  runIdentity(run,{id:c.id,attempt:1,head:c.sha,workflow:p.workflow,event:'push',completed:false,branch:p.branch});
  requireValue(run.status==='in_progress','workflow_not_active');
  requireValue(run.actor?.id===p.owner_id&&run.actor.login===p.owner
    &&run.triggering_actor?.id===p.owner_id&&run.triggering_actor.login===p.owner,'foreign_hotel_transfer_actor');
  const runs=boundedList(await api('GET',API_ROOT+'/actions/workflows/'+p.workflow.slice(PREFIX.length)
    +'/runs?branch='+encodeURIComponent(p.branch)+'&per_page=100'),
    'workflow_runs','hotel_transfer_inventory_incomplete');
  requireValue(runs.length===1&&String(runs[0].id)===c.id,'hotel_transfer_replay_forbidden');
  runIdentity(runs[0],{id:c.id,attempt:1,head:c.sha,workflow:p.workflow,event:'push',completed:false,branch:p.branch});
  const jobs=boundedList(await api('GET',API_ROOT+'/actions/runs/'+c.id+'/attempts/1/jobs?per_page=100'),
    'jobs','hotel_transfer_jobs_incomplete');
  const own=jobs.filter(j=>j.name===p.job);
  requireValue(own.length===1&&positive(own[0].id)&&own[0].run_id===Number(c.id)&&own[0].run_attempt===1
    &&own[0].head_sha===c.sha&&own[0].status==='in_progress'&&own[0].conclusion===null,
    'hotel_transfer_job_unconfirmed');
  // Bind the exact immutable GitHub source bytes, not a mutable ref, filename,
  // local summary or the historical reader's successful execution alone.
  const item=await api('GET',API_ROOT+'/contents/'+p.plan_path+'?ref='+c.sha);
  requireValue(item?.type==='file'&&item.path===p.plan_path&&item.sha===p.plan_blob
    &&item.encoding==='base64'&&item.size===p.plan_size&&typeof item.content==='string','hotel_transfer_plan_changed');
  const encoded=item.content.replace(/\n/g,'');
  requireValue(/^[A-Za-z0-9+/]*={0,2}$/.test(encoded),'hotel_transfer_plan_changed');
  const bytes=Buffer.from(encoded,'base64');
  requireValue(bytes.length===p.plan_size&&bytes.toString('base64')===encoded&&gitBlob(bytes)===p.plan_blob
    &&crypto.createHash('sha256').update(bytes).digest('hex')===p.plan_digest,'hotel_transfer_plan_changed');
  if(p===HOTEL_REMAINING_TRANSFER)await proveOriginalNextTransfer(api);
  const location=await readRecord(api),result=await resolveCurrent(api,c,location);
  requireValue(result.ready===true&&result.perform_actions===true,'release_pending');
  requireValue((await api('GET',branchPath))?.object?.sha===c.sha,'hotel_transfer_branch_changed');
  return {...await finalResolution(api,c,location,result),write_operation:p.operation};
}
async function resolveRankedHotelTransfer(inputs) {
  const p = HOTEL_RANKED_46;
  const run = await inputs.api('GET', API_ROOT+'/actions/runs/'+p.native_run);
  runIdentity(run, {id:p.native_run, attempt:1, head:'db1157a5091ff0f708d7825acf6ca4e1dc9a5339',
    workflow:PREFIX+'read-1566-telegram-46-20261123.yml', event:'push',
    branch:'ops/1566-telegram-46-native-read-20261123'});
  requireValue(run.repository?.full_name===REPOSITORY && run.repository.id===p.repository_id
    && run.head_repository?.id===p.repository_id && run.actor?.login===p.owner && run.actor?.id===p.owner_id
    && run.triggering_actor?.login===p.owner && run.triggering_actor?.id===p.owner_id,'ranked_native_actor_changed');
  const artifacts = boundedList(await inputs.api('GET',API_ROOT+'/actions/runs/'+p.native_run+'/artifacts?per_page=100'),
    'artifacts','ranked_native_artifact_inventory_incomplete');
  const artifact = artifacts[0];
  requireValue(artifacts.length===1 && artifact.id===p.native_artifact
    && artifact.name==='issue-1566-telegram-46-preflight' && artifact.expired===false
    && artifact.digest==='sha256:'+p.native_zip_digest && artifact.size_in_bytes===p.native_zip_bytes
    && String(artifact.workflow_run?.id)===p.native_run && artifact.workflow_run.repository_id===p.repository_id
    && artifact.workflow_run.head_sha==='db1157a5091ff0f708d7825acf6ca4e1dc9a5339','ranked_native_original_not_confirmed');
  return resolveHotelTransfer(inputs,p);
}
async function resolveConsumer({env,event,api,sleep=ms=>new Promise(resolve=>setTimeout(resolve,ms)),now=()=>performance.now()}) {
  if(env.GITHUB_EVENT_NAME==='push'&&env.GITHUB_REF==='refs/heads/'+GATE_RECOVERY.branch){
    const x=await gateContinuationContext({env,event,api,workflow:GATE_RECOVERY.tls_workflow,job:'strict-tls-preflight'});
    const current=await readRecord(api);
    requireValue(current.blob===x.original.blob&&current.record_digest===GATE_RECOVERY.record_digest,'deployment_result_changed');
    return resolution(x.source,GATE_RECOVERY.sha,'installed');
  }
  if(env.GITHUB_REF==='refs/heads/'+HOTEL_REGISTRY_READ.branch)return resolveHotelRegistryRead({env,event,api});
  if(env.GITHUB_REF==='refs/heads/'+MAX2_HOLD_PAIR_READ.branch)return resolveMax2HoldPairRead({env,event,api});
  if(env.GITHUB_REF==='refs/heads/'+HOTEL_FOUR_TRANSFER.branch)return resolveHotelTransfer({env,event,api});
  if(env.GITHUB_REF==='refs/heads/'+HOTEL_NEXT_TRANSFER.branch)return resolveHotelTransfer({env,event,api},HOTEL_NEXT_TRANSFER);
  if(env.GITHUB_REF==='refs/heads/'+HOTEL_RANKED_46.branch)return resolveRankedHotelTransfer({env,event,api});
  if(env.GITHUB_REF==='refs/heads/'+HOTEL_REMAINING_TRANSFER.branch)return resolveHotelTransfer({env,event,api},HOTEL_REMAINING_TRANSFER);
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
  const coverageBranch='ops/appleservice39-coverage-20261112';
  const deviceBranch='ops/appleservice39-device-v1-20261119';
  const deviceRecoveryBranch='ops/appleservice39-device-recovery-20261119';
  const telegramCatalogueBranch='ops/appleservice39-telegram-catalogue-20261119';
  const ref=env.GITHUB_REF;
  const operations=new Map([[coverageBranch,'coverage_batch'],[deviceBranch,'device_batch'],[deviceRecoveryBranch,'device_recovery'],[telegramCatalogueBranch,'telegram_catalogue']]);
  const branch=[...operations.keys()].find(name=>ref==='refs/heads/'+name);
  const workflow=PREFIX+'publish-appleservice39-pilot.yml';
  const predecessor='c5c0276d94337eee130467716c9794176b2ab25a',predecessorRun=43186776772;
  const initial=event?.before==='0'.repeat(40)&&event?.created===true;
  const recovery=branch===coverageBranch&&event?.before===predecessor&&event?.created===false&&event?.after!==predecessor;
  requireValue(branch!==undefined&&env.GITHUB_REPOSITORY===REPOSITORY
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
  if(branch===deviceRecoveryBranch){
    // This run observes the existing device requests. The ledger separately fences
    // every create and never reopens the original iPhone 18 request.
    const originalSha='8299a835d9b896c57dd4ffb9b34db75f613b3de0',originalRun=44436183201;
    const original=await api('GET',API_ROOT+'/actions/runs/'+originalRun);
    runIdentity(original,{id:originalRun,attempt:1,head:originalSha,workflow,event:'push',branch:deviceBranch,conclusion:'failure'});
    const job=await api('GET',API_ROOT+'/actions/jobs/135269278628');
    const failures=Array.isArray(job?.steps)?job.steps.filter(step=>step.conclusion==='failure'):[];
    requireValue(job?.id===135269278628&&job.run_id===originalRun&&job.name==='publish'&&job.status==='completed'
      &&job.conclusion==='failure'&&failures.length===1&&failures[0].name==='Run the installed fixed pilot operation once',
      'device_original_operation_unproven');
    const relation=await api('GET',API_ROOT+'/compare/'+originalSha+'...'+c.sha+'?per_page=1');
    requireValue(relation?.status==='ahead'&&relation.merge_base_commit?.sha===originalSha,'publication_source_not_descendant');
  }
  const location=await readRecord(api);
  const result=await resolveCurrent(api,c,location);
  requireValue(result.ready===true&&result.perform_actions===true,'release_pending');
  const admitted=await finalResolution(api,c,location,result);
  return {...admitted,publication_operation:operations.get(branch)};
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
module.exports={proveOriginalNextTransfer,PUBLISHER_BODY_RECOVERY,publisherBodyRecovery,publisherBodySource,failedPublisherBodyTerminal,
  UNSTARTED_FORWARD,forwardMarker,proveUnstartedDeployment,proveUnstartedForward,admitForwardInstallation,REPOSITORY,API_ROOT,PREFIX,DEPLOY,CLOSER,HOST,RECORD_PATH,PUBLICATION_FILE,PUBLICATION_PROTOCOL,
  TERMINAL_RECOVERY,GATE_RECOVERY,UNPUBLISHED_TERMINAL_RECOVERY,PRIOR_WAVE_RECOVERY,
  priorWaveRecovery,failedPriorWaveTerminal,terminalRecovery,gateRecovery,
  unpublishedTerminalRecovery,recoveryBranch,failedUnpublishedTerminal,authenticateFailedTerminal,
  failedGateJobs,failedGateEvidence,gateContinuationContext,continuationTls,
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
      const fields={plan:result.plan,application_sha:result.application_sha,plan_file:args.output};
      if(result.expected_checkout_sha)fields.expected_checkout_sha=result.expected_checkout_sha;
      outputs(fields);return;
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

