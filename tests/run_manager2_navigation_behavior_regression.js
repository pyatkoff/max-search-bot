'use strict';
const fs=require('node:fs'),vm=require('node:vm'),assert=require('node:assert/strict'),path=require('node:path');
const root=path.resolve(__dirname,'..');
const presentation=fs.readFileSync(path.join(root,'manager2/assets/manager2.js'),'utf8');
const filters=fs.readFileSync(path.join(root,'manager/assets/workspace-v2-filters.js'),'utf8');
let checks=0;
function check(name,fn){fn();checks++;console.log('PASS '+name)}
function harness(){
  const S={manager:{id:7},authExpired:false,authGeneration:1,queue:'mine',viewMode:'kanban',leadTaskFilter:'today',current:11};
  const elements={workspaceRoot:{dataset:{}},leadTaskFilter:{value:'today'},m2SectionHint:{textContent:'',hidden:true}};
  const title={textContent:''},taskFilters={open:false},requests=[],mobile=[],saved=new Map(),pending=[];
  const nav=['dialogues','tasks','sales','archive'].map(name=>({dataset:{m2Nav:name},classList:{toggle(){}},setAttribute(){},removeAttribute(){}}));
  const W={S,$:id=>elements[id],esc:String};
  const window={WorkspaceV2:W,WorkspaceV2Kanban:{setMode:mode=>S.viewMode=mode},WorkspaceV2Inbox:{setQueue:(queue,{reload})=>{assert.equal(reload,false);S.queue=queue;return true},load:()=>{requests.push({queue:S.queue,view:S.viewMode,task:S.leadTaskFilter});return new Promise(resolve=>pending.push(resolve))}},WorkspaceV2Mobile:{showInbox:o=>mobile.push(o)}};
  const document={body:{classList:{contains:()=>true}},addEventListener(){},querySelector:s=>s==='.inboxHead .brand'?title:s==='.m2TaskFilters'?taskFilters:null,querySelectorAll:s=>s==='[data-m2-nav]'?nav:[]};
  const context=vm.createContext({window,document,sessionStorage:{setItem:(k,v)=>saved.set(k,v)},Map,Number,String,Intl,console});
  vm.runInContext(filters,context);
  // Exercise the actual exported navigation and filter owners without shell boot.
  vm.runInContext(presentation.replace('  init();',''),context);
  return {S,elements,title,taskFilters,requests,mobile,saved,pending,navigate:window.Manager2Presentation.navigate};
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
  console.log('MANAGER2 NAVIGATION: '+checks+' PASSED');
})().catch(error=>{console.error(error);process.exitCode=1});
