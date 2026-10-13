const assert=require('node:assert/strict');
const fs=require('node:fs');
const path=require('node:path');
const vm=require('node:vm');
const {spawnSync}=require('node:child_process');
if(!process.argv.includes('--case')){
  for(const tz of ['UTC','Europe/Moscow','Europe/Kaliningrad','America/New_York','Asia/Kolkata']){
    const run=spawnSync(process.execPath,[__filename,'--case'],{encoding:'utf8',env:{...process.env,TZ:tz}});
    process.stdout.write(run.stdout);if(run.status!==0){process.stderr.write(run.stderr);process.exit(run.status||1)}
  }
  process.exit(0);
}
const W={S:{current:11},$:()=>null,esc:v=>String(v??''),pipe(){},api(){},statusText(){},outcomeText(){},formatWait(){}};
const context=vm.createContext({window:{WorkspaceV2:W},document:{readyState:'loading',addEventListener(){},createElement:()=>({textContent:'',get innerHTML(){return this.textContent}})},MutationObserver:class{observe(){}},Event:class{},console});
function load(file){vm.runInContext(fs.readFileSync(path.join(__dirname,'../manager/assets',file),'utf8'),context,{filename:file})}
if(fs.existsSync(path.join(__dirname,'../manager/assets/manager-time.js')))load('manager-time.js');
for(const file of ['workspace-v2-conversation.js','workspace-v2-inbox.js','workspace-v2-stage-history.js','workspace-v2-tasks.js','workspace-v2-task-presets.js','workspace-v2-kanban.js'])load(file);
const {WorkspaceV2Conversation:C,WorkspaceV2Inbox:I,WorkspaceV2StageHistory:H,WorkspaceV2Tasks:T,WorkspaceV2TaskPresets:P,ManagerTime:M}=context.window;
for(const [utc,expected] of [['2026-10-13 08:56:00','13.10 · 11:56'],['2026-12-31 22:15:00','01.01 · 01:15'],['2026-02-28 23:30:00','01.03 · 02:30']]){
  assert.equal(C.messageTime(utc),expected,'Transcript Moscow time');
  assert.equal(I.formatLastActivity(utc),expected,'Inbox must match transcript, including date rollover');
  assert.equal(I.formatTaskDue(utc),expected.replace(' · ',' '),'Task Inbox must use Moscow regardless of device timezone');
  assert.ok(context.window.WorkspaceV2Kanban.stageSinceText({lead_stage_since_at:utc}).includes(expected.replace(' · ',' ')),'Kanban Moscow stage time');
  assert.equal(H.formatStageSince(utc),expected.replace(' · ',' '),'Stage age must match Moscow');
  assert.ok(H.markup([{created_at:utc}]).includes(expected.replace(' · ',' ')),'History rows must use Moscow');
  const row=T.row({id:1,title:'Синтетическая задача',due_at_utc:utc,status:'open',due_state:'today'},true);
  assert.ok(row.includes(expected.replace(' · ',' ')),'Task row Moscow time');
  assert.ok(row.includes(M.toInput(utc)),'Task editor must show the same wall clock');
}
assert.equal(C.messageTime('2026-10-13T11:56:00+03:00'),'13.10 · 11:56','Explicit offset must not be shifted twice');
assert.equal(C.messageTime('2026-10-13T08:56:00Z'),'13.10 · 11:56');
assert.equal(I.formatSaleDate('2026-10-13'),'13.10.2026','Date-only business values stay literal');
assert.equal(M.format('not-a-date'),'not-a-date','Unknown display values stay literal');
assert.equal(M.toInput('not-a-date'),'');assert.equal(M.fromInput('2026-02-30T13:00'),null);
assert.equal(M.fromInput('2026-10-13T13:00'),'2026-10-13T10:00:00.000Z');
assert.equal(M.toInput('2026-10-13T10:00:00Z'),'2026-10-13T13:00');
assert.equal(M.fromInput(M.toInput('2026-12-31 22:15:00')),'2026-12-31T22:15:00.000Z');
const date=value=>vm.runInContext(`new Date(${JSON.stringify(value)})`,context);
const now=date('2026-10-13T14:50:45Z');
assert.equal(P.dateForPreset('quarter',now).toISOString(),'2026-10-13T15:05:00.000Z');
assert.equal(P.dateForPreset('hour',now).toISOString(),'2026-10-13T15:00:00.000Z');
assert.equal(P.dateForPreset('evening',now).toISOString(),'2026-10-13T15:00:00.000Z');
assert.equal(P.dateForPreset('tomorrow',now).toISOString(),'2026-10-14T07:00:00.000Z');
assert.equal(P.eveningLabel(now),'Сегодня 18:00');
assert.equal(P.eveningLabel(date('2026-10-13T15:00:00Z')),'Завтра 18:00');
assert.equal(P.dateForPreset('evening',date('2026-10-13T15:00:00Z')).toISOString(),'2026-10-14T15:00:00.000Z','Passed evening rolls forward');
assert.equal(P.dateForPreset('tomorrow',date('2026-12-31T22:30:00Z')).toISOString(),'2027-01-02T07:00:00.000Z');
const input={value:'',dispatchEvent(){}};P.apply(input,'tomorrow',now);
assert.equal(input.value,'2026-10-14T10:00','Preset and typed deadline have identical Moscow meaning');
const fields=new Map(['#leadTaskAdd','#leadTaskTitle','#leadTaskDue','#leadTaskCreateStatus'].map(id=>[id,{value:'',isConnected:true,addEventListener(){},focus(){}}]));
const root={set innerHTML(value){this.html=value},querySelector:s=>fields.get(s)||null,querySelectorAll:()=>[]};
let created;
W.pipe=async(action,payload)=>{assert.equal(action,'create_task');created={title:payload.title,due:payload.due_at};return {ok:false}};
T.render(root,{tasks:[],canEdit:true,conversationId:11});
fields.get('#leadTaskTitle').value='Синтетическая задача';fields.get('#leadTaskDue').value='2026-10-13T13:00';
(async()=>{
  await fields.get('#leadTaskAdd').onclick();
  assert.deepEqual(created,{title:'Синтетическая задача',due:'2026-10-13T10:00:00.000Z'},'Normal task form sends the intended UTC instant');
  assert.equal(fields.get('#leadTaskDue').value,'2026-10-13T13:00','Failed save preserves the typed deadline');
  console.log('PASS manager timestamps, rollover, offsets, task editor/create and presets in '+process.env.TZ);
})().catch(e=>{console.error(e);process.exitCode=1});
