'use strict';
const fs=require('node:fs'),vm=require('node:vm'),assert=require('node:assert/strict'),path=require('node:path');
const root=path.resolve(__dirname,'..');
const presentation=fs.readFileSync(path.join(root,'manager2/assets/manager2.js'),'utf8');
const filters=fs.readFileSync(path.join(root,'manager/assets/workspace-v2-filters.js'),'utf8');
const managerTime=fs.readFileSync(path.join(root,'manager/assets/manager-time.js'),'utf8');
let checks=0;
function check(name,fn){fn();checks++;console.log('PASS '+name)}
function harness(){
  const S={manager:{id:7},authExpired:false,authGeneration:1,queue:'mine',viewMode:'kanban',leadTaskFilter:'today',current:11};
  let classWrites=0;const classes=new Set(),classList={contains:key=>classes.has(key),add:key=>{classWrites++;classes.add(key)},remove:key=>{classWrites++;classes.delete(key)},toggle(key,force){const yes=force===undefined?!classes.has(key):!!force;if(yes)classes.add(key);else classes.delete(key);return yes}};
  const control=()=>({disabled:false,attrs:{},setAttribute(key,value){this.attrs[key]=value},focus(){this.focused=true},isConnected:true});
  const filterClasses=new Set(),filterPanel={classList:{contains:key=>filterClasses.has(key),add:key=>filterClasses.add(key),remove:key=>filterClasses.delete(key),toggle(key,force){const on=force===undefined?!filterClasses.has(key):force;if(on)filterClasses.add(key);else filterClasses.delete(key);return on}}};
  const elements={workspaceRoot:{dataset:{},classList},filtersPanel:filterPanel,filtersToggle:control(),leadTaskFilter:{value:'today'},m2MoreToggle:{classList:{toggle(){}}},m2SectionHint:{textContent:'',hidden:true},conversationTitle:control(),mobileLeadBtn:control(),mobileLeadClose:control()};
  const title={textContent:''},taskFilters={open:true},taskQuickFilters={hidden:true},requests=[],mobile=[],saved=new Map(),pending=[];
  const nav=['dialogues','tasks','sales','archive'].map(name=>({dataset:{m2Nav:name},classList:{toggle(){}},setAttribute(){},removeAttribute(){}}));
  const W={S,$:id=>elements[id],esc:String};const record=kind=>{requests.push({kind,queue:S.queue,view:S.viewMode,task:S.leadTaskFilter,filters:JSON.parse(JSON.stringify(window.Manager2Presentation?.listFilters()||null))});return new Promise(resolve=>pending.push(resolve))};
  const window={WorkspaceV2:W,WorkspaceV2Kanban:{setMode:mode=>S.viewMode=mode},WorkspaceV2Inbox:{setQueue:(queue,{reload})=>{assert.equal(reload,false);S.queue=queue;return true},load:()=>record('inbox')},WorkspaceV2Assistant:{openPlan:()=>record('plan'),close:()=>{}},WorkspaceV2Mobile:{showInbox:o=>mobile.push(o),isMobile:()=>false,getScreen:()=> 'conversation',showLead:()=>mobile.push('lead')}};
  const document={body:{classList:{contains:()=>true,toggle(){}}},addEventListener(){},querySelector:s=>s==='.inboxHead .brand'?title:s==='.m2TaskFilters'?taskFilters:s==='.m2TaskQuickFilters'?taskQuickFilters:null,querySelectorAll:s=>s==='[data-m2-nav]'?nav:[]};
  const context=vm.createContext({window,document,sessionStorage:{setItem:(k,v)=>saved.set(k,v)},Map,Number,String,Intl,console});
  vm.runInContext(filters,context);
  vm.runInContext(managerTime,context);
  // Exercise the actual exported navigation and filter owners without shell boot.
  vm.runInContext(presentation.replace('  init();',''),context);
  return {S,elements,title,taskFilters,taskQuickFilters,requests,mobile,saved,pending,navigate:window.Manager2Presentation.navigate,presentation:window.Manager2Presentation,window,classWrites:()=>classWrites};
}
async function explicitDialoguesReturnCheck(){
  const settle=async(h,target)=>{const before=h.requests.length,p=h.navigate(target);assert.equal(h.requests.length,before+1,'One load per explicit destination');assert.equal(h.elements.workspaceRoot.dataset.m2Section,target,'Immediate explicit destination');h.pending[before](true);await p;assert.equal(h.elements.workspaceRoot.dataset.m2Section,target,'Settled explicit destination');};
  const h=harness();h.S.leadProjectFilter='project-a';h.S.leadSourceFilter=91;h.S.leadSearch='Начатый поиск';h.S.leadManagerFilter='8';h.S.leadStageFilter='proposal';
  const text={value:'Неотправленный ответ'},file={files:[{name:'keep.pdf'}]};h.elements.replyText=text;h.elements.replyFile=file;
  for(const target of ['sales','archive','sales','tasks','sales','dialogues'])await settle(h,target);
  assert.equal(h.S.queue,'mine');assert.equal(h.S.leadProjectFilter,'project-a');assert.equal(h.S.leadSourceFilter,91);assert.equal(h.S.leadSearch,'Начатый поиск');assert.equal(h.S.leadManagerFilter,'8');assert.equal(h.S.leadStageFilter,'proposal');assert.equal(h.S.leadTaskFilter,'');assert.equal(h.elements.replyText,text);assert.equal(text.value,'Неотправленный ответ');assert.equal(h.elements.replyFile,file);assert.equal(file.files[0].name,'keep.pdf');
  for(const queue of ['mine','all','waiting','closed'])for(const entry of ['sales','tasks']){
    const q=harness();q.S.viewMode='list';Object.assign(q.S,{queue,leadProjectFilter:'project-b',leadSourceFilter:92,leadSearch:'Сохранённый поиск',leadManagerFilter:'7',leadStageFilter:'selection',leadTaskFilter:'today'});
    if(queue==='closed')q.window.WorkspaceV2PersonalWorkspace={selectionScope:()=> 'group:9'};
    const before=JSON.stringify(q.S);await settle(q,entry);await settle(q,'dialogues');
    assert.equal(JSON.stringify(q.S),before,'Returning preserves ordinary Inbox queue/project/task filters');if(queue==='closed')assert.equal(q.window.WorkspaceV2PersonalWorkspace.selectionScope(),'group:9');
  }
  check('explicit Dialogues return clears inherited Archive queue and preserves ordinary Inbox filters and composer',()=>assert.equal(h.requests.length,6));
}

(async()=>{
  if(process.argv.includes('--daily-flow-only')){await require('./support/manager-daily-flow-regression')(check);console.log('MANAGER DAILY FLOW: '+checks+' PASSED');return;}
  if(process.argv.includes('--dialogues-return-only')){await explicitDialoguesReturnCheck();console.log('MANAGER2 NAVIGATION TARGET: '+checks+' PASSED');return;}
  {
    const h=harness(),moves=[],pin={},markers={children:[pin]},identity={appendChild:node=>moves.push(node)};
    const node={querySelector:selector=>selector==='.pwRowMarkers'?markers:selector==='.leadIdentity'?identity:null};
    h.presentation.enhanceInboxMarkers(node);
    check('personal pin markers join the client identity beside the direct favorite control',()=>assert.deepEqual(moves,[markers]));
    markers.children=[pin,{}];h.presentation.enhanceInboxMarkers(node);
    check('named personal labels retain their marker container inside the client identity',()=>assert.deepEqual(moves,[markers,markers]));
    h.presentation.enhanceInboxMarkers({querySelector:selector=>selector==='.pwRowMarkers'?markers:null});
    check('missing client identity does not create a synthetic marker row',()=>assert.equal(moves.length,2));
    h.presentation.enhanceInboxMarkers({querySelector:selector=>selector==='.leadIdentity'?identity:null});
    h.presentation.enhanceInboxMarkers({querySelector:()=>null});
    h.presentation.enhanceInboxMarkers(null);
    check('missing personal markers or row leave the client identity unchanged',()=>assert.equal(moves.length,2));
  }
  {
    const h=harness(),format=h.presentation.formatInboxActivity;
    const cases=[
      ['today uses Moscow time','2026-10-15 09:07:08','2026-10-15 18:00:00','12:07','15.10.2026 12:07:08 МСК'],
      ['UTC evening belongs to tomorrow in Moscow','2026-10-15 21:05:00','2026-10-15 21:30:00','00:05','16.10.2026 00:05:00 МСК'],
      ['Moscow midnight changes the prior day to yesterday','2026-10-15 20:59:00','2026-10-15 21:00:00','Вчера','15.10.2026 23:59:00 МСК'],
      ['month rollover keeps yesterday','2026-09-30 12:00:00','2026-10-01 09:00:00','Вчера','30.09.2026 15:00:00 МСК'],
      ['year rollover keeps yesterday','2025-12-31 12:00:00','2026-01-01 09:00:00','Вчера','31.12.2025 15:00:00 МСК'],
      ['older date in current year omits year','2026-10-12 12:00:00','2026-10-15 09:00:00','12.10','12.10.2026 15:00:00 МСК'],
      ['older year remains distinguishable','2025-12-30 12:00:00','2026-01-01 09:00:00','30.12.25','30.12.2025 15:00:00 МСК'],
      ['leap-day rollover keeps yesterday','2024-02-29 12:00:00','2024-03-01 09:00:00','Вчера','29.02.2024 15:00:00 МСК'],
      ['explicit offset identifies the same Moscow instant','2026-10-15T05:07:08-04:00','2026-10-15 18:00:00','12:07','15.10.2026 12:07:08 МСК']
    ];
    for(const [name,value,now,text,exact] of cases)check('inbox activity '+name,()=>assert.deepEqual(JSON.parse(JSON.stringify(format(value,now))),{text,exact}));
    for(const value of ['',null,'invalid','2026-02-30 09:00:00'])check('inbox activity rejects invalid timestamp '+String(value),()=>assert.equal(format(value,'2026-10-15 09:00:00'),null));
    check('inbox activity cannot infer a date without a valid reference clock',()=>assert.equal(format('2026-10-15 09:00:00','invalid'),null));
  }
  for(const target of ['dialogues','tasks','archive','sales']){
    const h=harness(),p=h.navigate(target);
    check(target+' performs exactly one list request',()=>assert.equal(h.requests.length,1));
    check(target+' uses the final queue/mode/filter in that request',()=>assert.deepEqual(h.requests[0],{kind:target==='tasks'?'plan':'inbox',queue:target==='archive'?'closed':'mine',view:target==='sales'?'kanban':'list',task:target==='dialogues'||target==='sales'||target==='tasks'?'today':'',filters:target==='sales'?{queue:'mine',project_key:'*',source_id:0,manager_filter:'7',lead_stage_key:'',lead_tag_id:0,lead_outcome:'open',lead_task_filter:'',search:''}:null}));
    if(target==='archive')check(target+' persists the cleared task filter',()=>assert.equal(JSON.parse([...h.saved.values()][0]).task,''));
    check(target+' shows the destination immediately',()=>assert.equal(h.elements.workspaceRoot.dataset.m2Section,target));
    if(target==='tasks')check('entering My Plan closes advanced filters without another request',()=>{assert.equal(h.taskFilters.open,false);assert.equal(h.elements.filtersPanel.classList.contains('hidden'),true);assert.equal(h.elements.filtersToggle.attrs['aria-expanded'],'false');assert.equal(h.requests.length,1)});
    h.pending[0](true);assert.equal(await p,true);
  }
  {
    const h=harness(),older=h.navigate('tasks'),latest=h.navigate('archive');
    h.pending[1](true);await latest;h.pending[0](true);await older;
    check('late earlier completion does not revert the latest section',()=>assert.equal(h.elements.workspaceRoot.dataset.m2Section,'archive'));
    check('late earlier completion does not reopen task controls',()=>assert.equal(h.taskFilters.open,false));
    check('rapid navigation still has one request per action',()=>assert.equal(h.requests.length,2));
  }
  {
    const h=harness(),p=h.navigate('tasks');h.S.authGeneration++;h.S.authExpired=true;h.elements.workspaceRoot.dataset.m2Section='signed-out';h.pending[0](true);await p;
    check('old-session completion cannot reapply navigation UI',()=>assert.equal(h.elements.workspaceRoot.dataset.m2Section,'signed-out'));
    await h.navigate('sales');check('expired session never starts another list request',()=>assert.equal(h.requests.length,1));
  }
  {
    const h=harness();h.S.manager=null;await h.navigate('tasks');await h.navigate('unknown');check('anonymous and invalid targets cause no request',()=>assert.equal(h.requests.length,0));
  }
  {
    const h=harness(),p=h.navigate('tasks');check('plan section explains actions and outstanding replies',()=>assert.match(h.elements.m2SectionHint.textContent,/Следующие действия, вопросы без ответа/));h.pending[0](false);check('failure is returned without another hidden request',()=>assert.equal(h.requests.length,1));assert.equal(await p,false);
  }
  {
    const h=harness();
    check('ownership distinguishes current manager, free and another assigned manager',()=>{
      assert.deepEqual(JSON.parse(JSON.stringify(h.presentation.ownershipSignal({manager_id:7}))),{kind:'own',label:'Мой диалог'});
      assert.deepEqual(JSON.parse(JSON.stringify(h.presentation.ownershipSignal({manager_id:null}))),{kind:'free',label:'Свободный диалог'});
      assert.deepEqual(JSON.parse(JSON.stringify(h.presentation.ownershipSignal({manager_id:8}))),{kind:'assigned',label:'Диалог назначен другому менеджеру'});
    });
    h.presentation.openClientCard(h.elements.conversationTitle,{focus:false});
    check('desktop name opener reveals only the existing client pane without a list request',()=>{assert.equal(h.elements.workspaceRoot.classList.contains('m2ClientCardOpen'),true);assert.equal(h.elements.conversationTitle.attrs['aria-expanded'],'true');assert.equal(h.requests.length,0)});
    h.presentation.closeClientCard();
    check('closing desktop card restores the actual name opener and expanded state',()=>{assert.equal(h.elements.workspaceRoot.classList.contains('m2ClientCardOpen'),false);assert.equal(h.elements.conversationTitle.focused,true);assert.equal(h.elements.conversationTitle.attrs['aria-expanded'],'false')});
    h.presentation.revealTaskDraft({parentElement:null});
    check('a protected dirty task can reveal the closed desktop card',()=>assert.equal(h.elements.workspaceRoot.classList.contains('m2ClientCardOpen'),true));
    h.S.current=12;h.presentation.syncClientCard();const writes=h.classWrites();h.presentation.syncClientCard();
    check('changing client collapses stale desktop card and does not repeatedly mutate class',()=>{assert.equal(h.elements.workspaceRoot.classList.contains('m2ClientCardOpen'),false);assert.equal(h.classWrites(),writes)});
    h.S.authExpired=true;h.presentation.syncClientCard();const invalidWrites=h.classWrites();h.presentation.syncClientCard();
    check('auth loss disables name/card openers without a class-observer cycle',()=>{assert.equal(h.elements.conversationTitle.disabled,true);assert.equal(h.elements.mobileLeadBtn.disabled,true);assert.equal(h.classWrites(),invalidWrites);assert.equal(h.presentation.openClientCard(null,{focus:false}),false)});
  }
  {
    const h=harness();h.window.WorkspaceV2Mobile.isMobile=()=>true;h.window.WorkspaceV2Mobile.getScreen=()=> 'lead';
    h.presentation.openClientCard(h.elements.conversationTitle,{focus:false});
    check('mobile card opening delegates once to the existing history owner',()=>{assert.deepEqual(h.mobile,['lead']);assert.equal(h.elements.workspaceRoot.classList.contains('m2ClientCardOpen'),false);assert.equal(h.requests.length,0)});
    h.presentation.closeClientCard();check('desktop close helper does not duplicate mobile history',()=>assert.deepEqual(h.mobile,['lead']));
  }
  {
    const h=harness();h.S.queue='closed';h.S.leadProjectFilter='archived-project';h.S.leadSourceFilter=91;h.S.leadSearch='old text';h.S.leadStageFilter='old-stage';h.S.leadManagerFilter='8';
    h.window.WorkspaceV2PersonalWorkspace={selectionScope:()=> 'group:9'};
    const p=h.navigate('sales');
    check('Sales ignores archived personal Inbox scope and defaults to own active clients',()=>assert.deepEqual(JSON.parse(JSON.stringify(h.presentation.listFilters())),{queue:'mine',project_key:'*',source_id:0,manager_filter:'7',lead_stage_key:'',lead_tag_id:0,lead_outcome:'open',lead_task_filter:'',search:''}));
    h.pending[0](true);await p;const back=h.navigate('dialogues');
    check('returning from Sales retains all original Inbox filters and personal selection',()=>{assert.equal(h.S.queue,'closed');assert.equal(h.S.leadProjectFilter,'archived-project');assert.equal(h.S.leadTaskFilter,'today');assert.equal(h.S.leadSearch,'old text');assert.equal(h.presentation.listFilters(),null);assert.equal(h.window.WorkspaceV2PersonalWorkspace.selectionScope(),'group:9')});
    h.pending[1](true);await back;
  }
  {
    const h=harness(),opening=h.navigate('tasks');h.pending[0](true);await opening;h.window.WorkspaceV2Assistant.canLeavePlan=()=>false;
    check('an open deadline editor blocks section navigation before any request or mode change',()=>assert.equal(h.requests.length,1));assert.equal(await h.navigate('sales'),false);
    check('blocked navigation retains the Plan section and original Inbox filters',()=>{assert.equal(h.requests.length,1);assert.equal(h.elements.workspaceRoot.dataset.m2Section,'tasks');assert.equal(h.S.viewMode,'list');assert.equal(h.S.leadTaskFilter,'today')});
  }
  await explicitDialoguesReturnCheck();
  await require('./support/manager-daily-flow-regression')(check);
  console.log('MANAGER2 NAVIGATION: '+checks+' PASSED');
})().catch(error=>{console.error(error);process.exitCode=1});
