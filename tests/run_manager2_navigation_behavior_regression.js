'use strict';
const fs=require('node:fs'),vm=require('node:vm'),assert=require('node:assert/strict'),path=require('node:path');
const root=path.resolve(__dirname,'..');
const presentation=fs.readFileSync(path.join(root,'manager2/assets/manager2.js'),'utf8');
const filters=fs.readFileSync(path.join(root,'manager/assets/workspace-v2-filters.js'),'utf8');
let checks=0;
function check(name,fn){fn();checks++;console.log('PASS '+name)}
function harness(){
  const S={manager:{id:7},authExpired:false,authGeneration:1,queue:'mine',viewMode:'kanban',leadTaskFilter:'today',current:11};
  let classWrites=0;const classes=new Set(),classList={contains:key=>classes.has(key),add:key=>{classWrites++;classes.add(key)},remove:key=>{classWrites++;classes.delete(key)}};
  const control=()=>({disabled:false,attrs:{},setAttribute(key,value){this.attrs[key]=value},focus(){this.focused=true},isConnected:true});
  const elements={workspaceRoot:{dataset:{},classList},leadTaskFilter:{value:'today'},m2SectionHint:{textContent:'',hidden:true},conversationTitle:control(),mobileLeadBtn:control(),mobileLeadClose:control()};
  const title={textContent:''},taskFilters={open:false},requests=[],mobile=[],saved=new Map(),pending=[];
  const nav=['dialogues','tasks','sales','archive'].map(name=>({dataset:{m2Nav:name},classList:{toggle(){}},setAttribute(){},removeAttribute(){}}));
  const W={S,$:id=>elements[id],esc:String};
  const window={WorkspaceV2:W,WorkspaceV2Kanban:{setMode:mode=>S.viewMode=mode},WorkspaceV2Inbox:{setQueue:(queue,{reload})=>{assert.equal(reload,false);S.queue=queue;return true},load:()=>{requests.push({queue:S.queue,view:S.viewMode,task:S.leadTaskFilter});return new Promise(resolve=>pending.push(resolve))}},WorkspaceV2Mobile:{showInbox:o=>mobile.push(o),isMobile:()=>false,getScreen:()=> 'conversation',showLead:()=>mobile.push('lead')}};
  const document={body:{classList:{contains:()=>true}},addEventListener(){},querySelector:s=>s==='.inboxHead .brand'?title:s==='.m2TaskFilters'?taskFilters:null,querySelectorAll:s=>s==='[data-m2-nav]'?nav:[]};
  const context=vm.createContext({window,document,sessionStorage:{setItem:(k,v)=>saved.set(k,v)},Map,Number,String,Intl,console});
  vm.runInContext(filters,context);
  // Exercise the actual exported navigation and filter owners without shell boot.
  vm.runInContext(presentation.replace('  init();',''),context);
  return {S,elements,title,taskFilters,requests,mobile,saved,pending,navigate:window.Manager2Presentation.navigate,presentation:window.Manager2Presentation,window,classWrites:()=>classWrites};
}
(async()=>{
  for(const target of ['dialogues','tasks','archive','sales']){
    const h=harness(),p=h.navigate(target);
    check(target+' performs exactly one list request',()=>assert.equal(h.requests.length,1));
    check(target+' uses the final queue/mode/filter in that request',()=>assert.deepEqual(h.requests[0],{queue:target==='archive'?'closed':'mine',view:target==='sales'?'kanban':'list',task:target==='sales'?'today':''}));
    if(target!=='sales')check(target+' persists the cleared task filter',()=>assert.equal(JSON.parse([...h.saved.values()][0]).task,''));
    check(target+' shows the destination immediately',()=>assert.equal(h.elements.workspaceRoot.dataset.m2Section,target));
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
    const h=harness(),p=h.navigate('tasks');check('plan section explains that clients without tasks remain visible',()=>assert.match(h.elements.m2SectionHint.textContent,/диалоги с задачами и без них/));h.pending[0](false);check('failure is returned without another hidden request',()=>assert.equal(h.requests.length,1));assert.equal(await p,false);
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
  console.log('MANAGER2 NAVIGATION: '+checks+' PASSED');
})().catch(error=>{console.error(error);process.exitCode=1});
