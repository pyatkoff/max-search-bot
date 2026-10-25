'use strict';
const assert=require('node:assert/strict'),fs=require('node:fs'),path=require('node:path'),vm=require('node:vm');
const source=fs.readFileSync(path.join(__dirname,'../manager/assets/workspace-v2-client-events.js'),'utf8');
const defer=()=>{let resolve;const promise=new Promise(r=>resolve=r);return {resolve,promise}};
function harness(handler){
  const nodes=new Map(),calls=[];let active=null;
  function element(id=''){
    const listeners=new Map(),attributes=new Map();
    const node={id,value:'',textContent:'',dataset:{},hidden:false,disabled:false,isConnected:true,open:false,children:[],markup:'',classList:{toggle(){},add(){},remove(){}},
      setAttribute(k,v){attributes.set(k,String(v))},getAttribute:k=>attributes.get(k),focus(){active=node},blur(){active=null},setSelectionRange(start,end){node.selectionStart=start;node.selectionEnd=end},
      addEventListener(type,callback){listeners.set(type,callback)},emit(type,event={}){return listeners.get(type)?.(event)},showModal(){node.open=true},close(){node.open=false;node.emit('close')},
      appendChild(child){node.children.push(child);nodes.set(child.id,child)},querySelector:selector=>nodes.get(selector)||null,
      querySelectorAll(selector){return [...nodes.values()].filter(n=>selector.split(',').some(s=>s==='[data-ce-unused]'&&n.dataset.ceUnused||s==='[data-ce-unused2]'&&n.dataset.ceUnused2))},
      get innerHTML(){return node.markup},set innerHTML(html){node.markup=html;for(const child of node.children){child.isConnected=false;if(active===child)active=null;if(child.id&&nodes.get(child.id)===child)nodes.delete(child.id)}node.children=[];for(const m of html.matchAll(/id="([^"]+)"/g)){const child=element(m[1]);node.children.push(child);nodes.set(m[1],child)}for(const m of html.matchAll(/<button[^>]*data-in-(edit|delete)="(\d+)"[^>]*>/g)){const child=element();child.dataset[m[1]==='edit'?'ceUnused':'ceUnused2']=m[2];node.children.push(child);nodes.set('[data-in-'+m[1]+'="'+m[2]+'"]',child)}}
    };return node;
  }
  const body=element('body'),anchor=element('header');
  const S={manager:{id:5,role:'manager'},authGeneration:1,authExpired:false,current:101,detail:{conversation:{id:101,display_name:'Павел'}}};
  const W={S,$:id=>nodes.get(id)||null,esc:v=>String(v??'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;'),request:async(endpoint,action,data,options)=>{calls.push({endpoint,action,data,options});return handler(action,data,options)}};
  const window={WorkspaceV2:W,crypto:require('node:crypto').webcrypto,confirm:()=>true,ManagerTime:{format:v=>v?'14.10.2026 · 12:30':''}};
  const document={body,createElement:()=>element(),querySelector:selector=>selector==='.conversationHead .actions'?anchor:nodes.get(selector)||null,get activeElement(){return active}};
  vm.runInContext(source,vm.createContext({window,document,Uint8Array,console}));
  const api=window.WorkspaceV2ClientEvents;api.bind();api.activate(5);api.enhanceCard(S.detail.conversation);
  return {api,S,nodes,calls,active:()=>active,element};
}
const item=(key='event:1',extra={})=>({key,kind:'event',created_at:'2026-10-14 10:00:00',actor:'Менеджер',title:'Менеджер принял диалог',details:[],...extra});
const list=(items=[item()],cursor='')=>({ok:true,conversation_id:101,items,history:{has_more:!!cursor,cursor,page_size:30}});
(async()=>{
 const lazy=harness(()=>list());assert.equal(lazy.calls.length,0);await lazy.api.open();assert.equal(lazy.calls.length,1);assert.equal(lazy.calls[0].endpoint,'client-events-api.php');assert.equal(lazy.calls[0].action,'list');assert.equal(lazy.calls[0].data.conversation_id,101);assert.equal(lazy.calls[0].data.page_size,30);await lazy.api.open();assert.equal(lazy.calls.length,1);assert.match(lazy.nodes.get('ceList').innerHTML,/Менеджер принял/);
 console.log('PASS lazy bounded read never joins Inbox or duplicates current dialog open');
 const paging=harness((action,data)=>list(data.cursor?[item('event:1'),item('note:2',{kind:'note',current_record:true,details:[{label:'Текущее содержание',value:'<b>literal</b>'}]})]:[item('event:1')],data.cursor?'':'cursor1'));await paging.api.open();await paging.api.load(true);assert.equal(paging.calls[1].data.cursor,'cursor1');assert.equal([...paging.nodes.get('ceList').innerHTML.matchAll(/data-ce-key=/g)].length,2);assert.match(paging.nodes.get('ceList').innerHTML,/&lt;b&gt;literal&lt;\/b&gt;/);assert.match(paging.nodes.get('ceDialog').innerHTML,/История всех их правок не сохраняется/);assert.equal(paging.nodes.get('ceOlder').hidden,true);await paging.api.load(false);assert.equal(paging.calls[2].data.cursor,'');
 console.log('PASS explicit cursor paging deduplicates and current snapshots render escaped with truthful scope');
 const held=defer(),one=harness(()=>held.promise);const opening=one.api.open(),other=one.api.load();assert.equal(one.calls.length,1);assert.equal(one.nodes.get('ceRefresh').disabled,true);held.resolve(list());await Promise.all([opening,other]);assert.equal(one.nodes.get('ceRefresh').disabled,false);
 console.log('PASS concurrent clicks share one bounded read and controls recover after settlement');
 for(const change of ['close','navigation','auth','account']){
  const pending=defer(),h=harness(()=>pending.promise);const loading=h.api.open();const options=h.calls[0].options;assert.equal(options.isCurrent(),true);
  if(change==='close')h.api.close();
  if(change==='navigation'){h.S.current=102;h.S.detail={conversation:{id:102,display_name:'Другой клиент'}};h.api.enhanceCard(h.S.detail.conversation)}
  if(change==='auth'){h.S.authExpired=true;h.S.authGeneration++;h.api.suspend()}
  if(change==='account'){h.S.manager.id=6;h.S.authGeneration++;h.api.activate(6)}
  assert.equal(options.isCurrent(),false,'stale transport cannot trigger global401 recovery');pending.resolve(list([item('event:9',{title:'Old private content'})]));await loading;assert.doesNotMatch(h.nodes.get('ceList').innerHTML,/Old private/);assert.equal(h.nodes.get('ceDialog').open,false);
 }
 console.log('PASS close navigation auth and account changes reject old data and stale401 transport ownership');
 const old=defer();let reads=0;const reopen=harness(()=>++reads===1?old.promise:{ok:false,error:'forbidden',http_status:403});const first=reopen.api.open();reopen.api.close();await reopen.api.open();old.resolve(list([item('event:8',{title:'Old authorization'})]));await first;assert.doesNotMatch(reopen.nodes.get('ceList').innerHTML,/Old authorization/);assert.match(reopen.nodes.get('ceStatus').textContent,/Доступ/);assert.equal(reads,2);
 console.log('PASS same-dialog reopen requires fresh permission and old positive cannot override new403');
 let failure=false;const refresh=harness(()=>failure?{ok:false,error:'network_error'}:list());await refresh.api.open();failure=true;await refresh.api.load(false);assert.match(refresh.nodes.get('ceList').innerHTML,/Менеджер принял/);assert.match(refresh.nodes.get('ceStatus').textContent,/Не удалось/);assert.equal(refresh.nodes.get('ceRefresh').disabled,false);
 console.log('PASS transient failure retains confirmed content with explicit retry');
 let denied=false;const acl=harness(()=>denied?{ok:false,error:'forbidden',http_status:403}:list());await acl.api.open();denied=true;await acl.api.load(false);assert.doesNotMatch(acl.nodes.get('ceList').innerHTML,/Менеджер принял/);assert.equal(acl.nodes.get('ceOlder').hidden,true);
 console.log('PASS permission rejection clears all protected content and paging');
 const empty=harness(()=>list([]));await empty.api.open();assert.match(empty.nodes.get('ceList').innerHTML,/пока нет зарегистрированных/);assert.equal(empty.calls.some(c=>c.action!=='list'),false);empty.api.close();assert.equal(empty.nodes.get('ceList').innerHTML,'');
 console.log('PASS confirmed empty differs from loading and close clears private data without writes');
})().catch(error=>{console.error(error);process.exitCode=1});
