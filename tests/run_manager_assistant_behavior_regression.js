const assert=require('node:assert/strict');
const fs=require('node:fs');
const path=require('node:path');
const vm=require('node:vm');

// Dependency-free DOM behavior harness. Production code owns all assistant
// state and transport decisions; this deliberately makes no layout claim.
function harness(){
  let document;
  const decode=v=>String(v).replace(/&(?:amp|lt|gt|quot|#39);/g,s=>({'&amp;':'&','&lt;':'<','&gt;':'>','&quot;':'"','&#39;':"'"}[s]));
  class Element{
    constructor(tag='div'){this.tagName=tag.toUpperCase();this.children=[];this.parentElement=null;this.attributes={};this.dataset={};this.handlers={};this.hidden=false;this.disabled=false;this.value='';this.open=false;this._text='';this._html='';this.className='';this.classList={contains:n=>this.className.split(/\s+/).includes(n),add:(...ns)=>{this.className=[...new Set([...this.className.split(/\s+/).filter(Boolean),...ns])].join(' ')},remove:(...ns)=>{this.className=this.className.split(/\s+/).filter(n=>!ns.includes(n)).join(' ')},toggle:(n,f)=>{const yes=f===undefined?!this.classList.contains(n):!!f;yes?this.classList.add(n):this.classList.remove(n);return yes}};}
    get id(){return this.attributes.id||''}set id(v){this.attributes.id=String(v)}
    setAttribute(k,v){this.attributes[k]=String(v);if(k==='class')this.className=String(v);if(k.startsWith('data-'))this.dataset[k.slice(5).replace(/-([a-z])/g,(_,c)=>c.toUpperCase())]=String(v)}
    getAttribute(k){return this.attributes[k]??null}
    appendChild(n){if(n.parentElement)n.parentElement.children.splice(n.parentElement.children.indexOf(n),1);n.parentElement=this;this.children.push(n);return n}
    prepend(n){this.appendChild(n);this.children.unshift(this.children.pop())}
    insertBefore(n,b){if(!b)return this.appendChild(n);this.appendChild(n);this.children.pop();this.children.splice(this.children.indexOf(b),0,n);return n}
    replaceChildren(...nodes){this.children.forEach(n=>n.parentElement=null);this.children=[];this._text='';this._html='';nodes.forEach(n=>this.appendChild(n))}
    set textContent(v){this.replaceChildren();this._text=String(v)}get textContent(){return this._text+this.children.map(n=>n.textContent).join('')}
    set innerHTML(v){this.replaceChildren();this._html=String(v);const stack=[this];for(const token of this._html.matchAll(/<\/?[a-z][^>]*>|[^<]+/gi)){const s=token[0];if(s.startsWith('</')){if(stack.length>1)stack.pop();continue}if(s[0]!=='<'){const text=new Element('text');text._text=decode(s);stack.at(-1).appendChild(text);continue}const tag=s.match(/^<([a-z0-9]+)/i)[1],n=new Element(tag);for(const a of s.matchAll(/\s([\w-]+)(?:="([^"]*)")?/g)){const key=a[1],value=decode(a[2]||'');n.setAttribute(key,value);if(key==='hidden'||key==='disabled')n[key]=true;if(key==='value')n.value=value}stack.at(-1).appendChild(n);if(!['input','br','hr','img','meta','link'].includes(tag)&&!s.endsWith('/>'))stack.push(n)}}
    get innerHTML(){return this._html||this._text}
    matches(s){const tag=s.match(/^[a-z][\w-]*/i)?.[0],id=s.match(/#([\w-]+)/)?.[1];if(tag&&this.tagName!==tag.toUpperCase()||id&&this.id!==id)return false;if([...s.matchAll(/\.([\w-]+)/g)].some(m=>!this.classList.contains(m[1])))return false;return [...s.matchAll(/\[([\w-]+)(?:="([^"]*)")?\]/g)].every(([,k,v])=>this.getAttribute(k)!==null&&(v===undefined||this.getAttribute(k)===v))}
    querySelectorAll(s){const [first,...rest]=s.trim().split(/\s+/),found=[];for(const c of this.children){if(c.matches(first))found.push(...(rest.length?c.querySelectorAll(rest.join(' ')):[c]));found.push(...c.querySelectorAll(s))}return [...new Set(found)]}
    querySelector(s){return this.querySelectorAll(s)[0]||null}
    contains(node){return node===this||this.children.some(child=>child.contains(node))}
    addEventListener(k,fn){(this.handlers[k]||=[]).push(fn)}
    dispatchEvent(e){for(const fn of this.handlers[e.type]||[])fn(e);return true}
    focus(){document.activeElement=this}
    get isConnected(){return this===document.body||!!this.parentElement?.isConnected}
    showModal(){this.open=true}show(){this.open=true}close(){this.open=false}
    scrollIntoView(){}
  }
  const body=new Element('body');body.className='manager2';
  document={body,activeElement:null,createElement:tag=>new Element(tag),getElementById:id=>body.querySelector('#'+id),querySelector:s=>body.querySelector(s)};
  const inboxZone=new Element('aside');inboxZone.className='inboxZone';body.appendChild(inboxZone);const rail=new Element('nav');rail.className='m2Rail';body.appendChild(rail);const end=new Element();end.className='m2RailEnd';rail.appendChild(end);const tools=new Element();tools.className='conversationTools';body.appendChild(tools);
  const add=(id,tag='div')=>{const n=new Element(tag);n.id=id;body.appendChild(n);return n};
  add('replyText','textarea').value='Unsent customer reply';const file={name:'keep.txt'};add('replyFile','input').file=file;
  const lead=(changes={})=>({ok:true,tasks:[],can_edit_pipeline:true,pipeline:{outcome:{outcome:'open'}},degraded_components:[],...changes});
  const leads=new Map([[101,lead()],[102,lead()]]);
  const S={manager:{id:7},authExpired:false,authGeneration:1,current:101,detail:{conversation:{id:101,manager_id:7,status:'manager'},messages:[{id:35,text:'Current customer request'}],lead:leads.get(101)}};
  const calls=[],timers=[],leadReads=[];let now=Date.now(),hook=null,leadHook=null,openGeneration=1;
  class Clock extends Date{constructor(...args){super(...(args.length?args:[now]))}static now(){return now}static parse(value){return /^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/.test(String(value))?NaN:Date.parse(value)}}
  const esc=v=>String(v??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
  const item=(id=101,changes={})=>({conversation_id:id,manager_id:7,status:'manager',can_decide:true,display_name:'Client '+id,channel:'telegram',last_message_at:'2026-10-20T15:00:00Z',tasks:[],recommendation:{revision:'a'.repeat(64),next_action:'check_offer',reason:'Check selected hotel & meal plan.',checks:['Compare the two selected hotels.'],prepared_at:new Date(now).toISOString(),expires_at:new Date(now+3600000).toISOString(),latest_message_id:35,evidence_message_ids:[35],history_incomplete:false,decision:null},...changes});
  const data=new Map([[101,item(101)],[102,item(102)]]);
  const W={S,$:id=>document.getElementById(id),esc,request:async(url,action,payload,options)=>{calls.push({url,action,...payload});if(hook){const r=await hook(url,action,payload,options);if(r!==undefined)return r}if(action==='list')return{ok:true,items:[...data.values()],has_more:false,next_cursor:null};if(action==='detail')return data.has(payload.conversation_id)?{ok:true,item:data.get(payload.conversation_id)}:{ok:false,error:'forbidden'};if(action==='decision'){const row=data.get(payload.conversation_id);row.recommendation={...row.recommendation,decision:payload.decision};return{ok:true}}throw Error('Unexpected assistant request '+action)},pipe:async(action,payload,options)=>{calls.push({url:'pipeline-api.php',action,...payload});return hook?await hook('pipeline-api.php',action,payload,options):{ok:true}}};
  const captureReplyContext=()=>({conversationId:Number(S.current||0),managerId:Number(S.manager?.id||0),authGeneration:S.authGeneration,openGeneration});
  const navigate=id=>{openGeneration++;S.current=Number(id);const row=data.get(Number(id));S.detail={...S.detail,conversation:{id:Number(id),manager_id:row?.manager_id??S.manager?.id,status:row?.status??'manager'},lead:leads.get(Number(id))??lead()};};
  const opened=[];const window={WorkspaceV2:W,WorkspaceV2Conversation:{
    open:async(id,opts)=>{opened.push({id,opts});navigate(id);return true},
    captureReplyContext,getOpenGeneration:()=>openGeneration,
    refreshLeadData:async(options={})=>{const id=Number(options.conversationId??S.current),before=captureReplyContext();leadReads.push({...options,conversationId:id});const result=leadHook?await leadHook(options):leads.get(id);if(result===false||!result?.ok||S.authExpired||id!==Number(S.current)||JSON.stringify(before)!==JSON.stringify(captureReplyContext()))return false;S.detail.lead=result;return true}
  },ManagerTime:{format:v=>v,toInput:()=>'',fromInput:v=>v}};
  const context=vm.createContext({window,document,console,Date:Clock,Event:class{constructor(type,opts={}){this.type=type;Object.assign(this,opts)}},setTimeout:fn=>{timers.push(fn);return timers.length},clearTimeout:()=>{}});
  vm.runInContext(fs.readFileSync(path.join(__dirname,'../manager/assets/workspace-v2-assistant.js'),'utf8'),context,{filename:'workspace-v2-assistant.js'});
  const A=window.WorkspaceV2Assistant,$=W.$;
  async function click(selector){const n=body.querySelector(selector);assert.ok(n,'Control exists: '+selector);assert.equal(n.disabled,false,'Control is enabled: '+selector);return n.onclick?.({preventDefault(){}})}
  function onlyReads(){assert.equal(calls.some(c=>!['list','detail'].includes(c.action)),false,'Only assistant list/detail requests')}
  return{A,W,S,window,document,$,body,calls,data,item,lead,leads,leadReads,timers,opened,file,click,onlyReads,navigate,setHook:fn=>hook=fn,setLeadHook:fn=>leadHook=fn,advance:ms=>now+=ms};
}
const deferred=()=>{let resolve;return{promise:new Promise(r=>resolve=r),resolve}};
const tests=[];function test(name,run){tests.push({name,run})}

test('unanswered work stays visible without AI and fresh no-contact remains separate',async()=>{
  const h=harness();h.data.clear();h.data.set(101,h.item(101,{recommendation:null,operational:{awaiting_manager_reply:true,due_task:false}}));h.A.activate(7);await h.A.open();await h.click('[data-assistant-filter="actions"]');assert.ok(h.body.querySelector('[data-assistant-client="101"]'));await h.A.loadDetail(101);assert.match(h.$('assistantDetail').textContent,/Обращение без записанного ответа/);assert.match(h.$('assistantDetail').textContent,/Нет свежего разбора/);assert.equal(h.$('assistantPlan'),null);
  h.data.set(101,h.item(101,{operational:{awaiting_manager_reply:true,due_task:false},recommendation:{...h.item().recommendation,next_action:'no_contact'}}));await h.A.loadDetail(101);assert.equal(h.$('assistantDetail').querySelector('.assistantOperational'),null);neutralNoContact(h);assert.equal(h.body.querySelector('[data-assistant-client="101"]'),null);
  h.advance(3600001);await h.A.loadDetail(101);assert.ok(h.body.querySelector('[data-assistant-client="101"]'));assert.match(h.$('assistantDetail').textContent,/Обращение без записанного ответа/);h.onlyReads();
});
for(const role of ['manager','admin'])test(role+' sees neutral suspended state without automatic reply or old AI action',async()=>{
  const h=harness();h.S.manager.role=role;h.data.set(101,h.item(101,{delivery_suspended:true,delivery_notice:'Ответ станет доступен после новой активности клиента.',operational:{awaiting_manager_reply:true,due_task:false}}));h.A.activate(7);await h.A.open(101);
  assert.ok(h.$('assistantDetail').querySelector('.assistantUnavailable'));assert.equal(h.$('assistantDetail').querySelector('.assistantOperational'),null);assert.equal(h.$('assistantDetail').querySelector('.assistantRecommendation'),null);assert.equal(h.$('assistantPlan'),null);assert.match(h.body.querySelector('[data-assistant-client="101"]').textContent,/Клиент недоступен в MAX/);assert.doesNotMatch(h.$('assistantDetail').textContent,/Обращение без записанного ответа|Check selected hotel/);
  await h.click('[data-assistant-filter="actions"]');assert.equal(h.body.querySelector('[data-assistant-client="101"]'),null);
  h.data.set(101,h.item(101,{delivery_suspended:false,operational:{awaiting_manager_reply:true,due_task:false}}));await h.A.loadDetail(101);assert.equal(h.$('assistantDetail').querySelector('.assistantUnavailable'),null);assert.ok(h.$('assistantDetail').querySelector('.assistantOperational'));assert.ok(h.$('assistantDetail').querySelector('.assistantRecommendation'));assert.ok(h.body.querySelector('[data-assistant-client="101"]'));h.onlyReads();
});
test('suspended client retains an explicitly created task and its deadline controls',async()=>{
  const h=harness();h.data.set(101,h.item(101,{delivery_suspended:true,operational:{awaiting_manager_reply:true,due_task:true},tasks:[{id:91,title:'Позвонить по договорённости',status:'open',due_state:'overdue',due_at_utc:'2000-01-01 00:00:00'}]}));h.A.activate(7);await h.A.open(101);
  assert.match(h.$('assistantDetail').querySelector('.assistantOperational').textContent,/Наступил срок задачи/);assert.doesNotMatch(h.$('assistantDetail').textContent,/Обращение без записанного ответа|Check selected hotel/);assert.match(h.$('assistantDetail').querySelector('.assistantTasks').textContent,/Позвонить по договорённости/);assert.ok(h.$('assistantDetail').querySelector('[data-assistant-snooze="91"]'));await h.click('[data-assistant-filter="actions"]');assert.ok(h.body.querySelector('[data-assistant-client="101"]'));h.onlyReads();
});
test('a future task marked today never becomes an arrived reminder',async()=>{
  const h=harness();h.data.set(101,h.item(101,{recommendation:null,tasks:[{id:1,title:'Future today',status:'open',due_state:'today',due_at_utc:'2999-01-01 10:00:00'}],operational:{awaiting_manager_reply:false,due_task:false}}));h.A.activate(7);await h.A.open(101);assert.equal(h.$('assistantDetail').querySelector('.assistantOperational'),null);assert.doesNotMatch(h.body.querySelector('[data-assistant-client="101"]').textContent,/Наступил/);h.onlyReads();
});
test('changed ordering discards old pages and restarts once without consuming own input',async()=>{
  const h=harness();let n=0;h.setHook((url,action,p)=>{if(action!=='list')return; n++;return n===1?{ok:true,items:[h.item(101)],has_more:true,next_cursor:'v1.old.101'}:n===2?{ok:false,error:'list_changed'}:{ok:true,items:[h.item(102,{recommendation:null,operational:{awaiting_manager_reply:true}})],has_more:false,next_cursor:null}});h.A.activate(7);await h.A.open();await h.A.loadList(true);assert.equal(n,3);assert.deepEqual(h.calls.map(c=>c.cursor||null),[null,'v1.old.101',null]);assert.equal(h.body.querySelector('[data-assistant-client="101"]'),null);assert.ok(h.body.querySelector('[data-assistant-client="102"]'));assert.equal(h.$('replyText').value,'Unsent customer reply');assert.equal(h.$('replyFile').file,h.file);h.onlyReads();
});
test('list refresh and continuation preserve an unfinished existing deadline form',async()=>{
  const h=harness();h.A.activate(7);await h.A.open(101);const host=h.$('assistantTaskEditor');host.innerHTML='<form><input id="assistantDue" value="2026-11-01T10:00"></form>';const form=host.querySelector('form'),input=h.$('assistantDue');input.value='2026-12-12T15:42';const before=h.calls.length;await h.A.loadList(true);await h.A.loadList(false);assert.equal(h.calls.length,before);assert.equal(host.querySelector('form'),form);assert.equal(input.value,'2026-12-12T15:42');assert.match(h.$('assistantStatus').textContent,/Сохраните или отмените/);h.onlyReads();
});

test('assistant return delegates loaded-history preservation to the canonical conversation owner',async()=>{
  const h=harness();h.A.activate(7);await h.A.open(101);await h.click('#assistantConversation');
  assert.equal(h.opened.length,1);assert.equal(h.opened[0].id,101);
  assert.equal(h.opened[0].opts.preserveHistory,true);
  assert.equal(h.opened[0].opts.preserveMessageScroll,true);assert.equal(h.opened[0].opts.preserveAttachment,true);
  assert.equal(h.$('replyText').value,'Unsent customer reply');assert.equal(h.$('replyFile').file,h.file);h.onlyReads();
});

test('entry review navigation leaves focus to the conversation owner and explicit Close restores the native reply',async()=>{
  const h=harness();h.A.activate(7);h.A.bind();const input=h.$('replyText');let focuses=0;const nativeFocus=input.focus.bind(input);input.focus=()=>{focuses++;nativeFocus()};input.focus();
  await h.A.open(101);const before=focuses;await h.click('#assistantConversation');assert.equal(focuses,before,'Navigation must not force focus to an old launcher');
  input.focus();await h.A.open(101);await h.click('#assistantClose');assert.equal(h.document.activeElement,input);assert.equal(focuses,before+2);assert.equal(input.value,'Unsent customer reply');assert.equal(h.$('replyFile').file,h.file);h.onlyReads();
});

for(const [screen,id,mode] of [['conversation',101,'none'],['conversation',102,'push'],['inbox',101,'push'],['lead',101,'push']])test('assistant navigation preserves the canonical history boundary '+screen+' '+id,async()=>{
  const h=harness();h.window.WorkspaceV2Mobile={getScreen:()=>screen};h.A.activate(7);await h.A.open(id);await h.click('#assistantConversation');assert.equal(h.opened[0].opts.mobileHistory,mode);h.onlyReads();
});

test('workspace owner sees team identity and cannot change another manager decision',async()=>{
  const h=harness();h.S.manager.role='admin';h.data.set(101,h.item(101,{project_name:'AnyTour',manager_name:'Другой менеджер',manager_id:9,can_decide:false}));h.A.activate(7);await h.A.open(101);
  assert.match(h.$('assistantTitle').textContent,/владельца/);assert.match(h.$('assistantSubtitle').textContent,/всей команды/);assert.match(h.$('assistantDetail').textContent,/Другой менеджер/);assert.equal(h.$('assistantDetail').querySelector('[data-assistant-decision]'),null);h.onlyReads();
});

test('entry review binding keeps the canonical reply helper and creates no duplicate menu launcher',async()=>{
  const h=harness();let opened=0;const replyHelper={open:()=>{opened++}};h.window.WorkspaceV2AiDraft=replyHelper;h.A.activate(7);h.A.bind();
  assert.equal(h.$('assistantClientOpen'),null);assert.equal(h.window.WorkspaceV2AiDraft,replyHelper);assert.equal(opened,0);assert.equal(h.$('assistantDialog'),null);assert.equal(h.$('assistantOpen'),null);assert.equal(h.calls.length,0);
});
test('entry review activation remains lazy when current client ownership changes',async()=>{
  const h=harness();h.A.activate(7);h.A.bind();assert.equal(h.calls.length,0);assert.equal(h.$('assistantOpen'),null);assert.equal(h.$('assistantClientOpen'),null);
  h.S.detail.conversation.manager_id=8;h.A.sync();assert.equal(h.$('assistantClientOpen'),null);assert.equal(h.calls.length,0);assert.equal(h.$('replyText').value,'Unsent customer reply');assert.equal(h.$('replyFile').file,h.file);
});

test('list paging retains old clients beyond the ordinary inbox cap',async()=>{
  const h=harness();h.setHook((url,action,p)=>action==='list'?{ok:true,items:[h.item(p.cursor?901:101)],has_more:!p.cursor,next_cursor:p.cursor?null:101}:undefined);h.A.activate(7);await h.A.open();await h.A.loadList(true);assert.ok(h.body.querySelector('[data-assistant-client="901"]'));assert.match(h.$('assistantCoverage').textContent,/2/);assert.equal(h.calls.at(-1).cursor,101);h.onlyReads();
});
test('detail uses saved reasoning and leaves native reply/file untouched',async()=>{
  const h=harness();h.data.get(101).recommendation.reason='<script>private();</script>';h.A.activate(7);await h.A.open(101);assert.match(h.$('assistantDetail').textContent,/<script>private\(\);<\/script>/);assert.equal(h.$('assistantDetail').querySelector('script'),null);assert.equal(h.$('replyText').value,'Unsent customer reply');assert.equal(h.$('replyFile').file,h.file);h.onlyReads();
});
test('unchanged periodic reads retain native reading nodes, disclosure and action focus',async()=>{
  const h=harness();h.A.activate(7);await h.A.open(101);const detail=h.$('assistantDetail'),source=detail.querySelector('.assistantSource'),button=detail.querySelector('[data-assistant-decision="done"]'),listButton=h.body.querySelector('[data-assistant-client="101"]'),text=detail.querySelector('.assistantRecommendation p').children[0],body=h.body.querySelector('.assistantBody');source.open=true;button.focus();body.scrollTop=180;const same=JSON.parse(JSON.stringify(h.data.get(101)));h.setHook((url,action)=>action==='detail'?{ok:true,item:JSON.parse(JSON.stringify(same))}:undefined);h.timers.at(-1)();assert.equal(h.document.activeElement,button,'Request start preserves focused action');assert.equal(button.isConnected,true);await new Promise(setImmediate);assert.equal(detail.querySelector('.assistantSource'),source);assert.equal(source.open,true);assert.equal(detail.querySelector('[data-assistant-decision="done"]'),button);assert.equal(detail.querySelector('.assistantRecommendation p').children[0],text,'Native text selection anchors stay connected');assert.equal(h.document.activeElement,button);assert.equal(h.body.querySelector('[data-assistant-client="101"]'),listButton);assert.equal(body.scrollTop,180);h.onlyReads();
});
test('an identical explicit list refresh retains list focus and an expanded client basis',async()=>{
  const h=harness();h.A.activate(7);await h.A.open();await h.A.loadDetail(101);const listButton=h.body.querySelector('[data-assistant-client="101"]'),source=h.$('assistantDetail').querySelector('.assistantSource');source.open=true;listButton.focus();await h.A.loadList(false);assert.equal(h.body.querySelector('[data-assistant-client="101"]'),listButton);assert.equal(h.document.activeElement,listButton);assert.equal(h.$('assistantDetail').querySelector('.assistantSource'),source);assert.equal(source.open,true);h.onlyReads();
});
test('retained visually identical actions bind the latest invisible proposal revision',async()=>{
  const h=harness();h.A.activate(7);await h.A.open(101);const button=h.$('assistantDetail').querySelector('[data-assistant-decision="done"]'),current=JSON.parse(JSON.stringify(h.data.get(101)));current.recommendation.revision='b'.repeat(64);h.data.set(101,current);await h.A.loadDetail(101);assert.equal(h.$('assistantDetail').querySelector('[data-assistant-decision="done"]'),button);button.focus();await h.click('[data-assistant-decision="done"]');assert.equal(h.calls.find(c=>c.action==='decision').revision,'b'.repeat(64));assert.equal(button.isConnected,false,'Changed decision replaces the obsolete action');assert.equal(h.document.activeElement,h.$('assistantConversation'));
});
test('a focused action removed by changed advice moves to the same client navigation',async()=>{
  const h=harness();h.A.activate(7);await h.A.open(101);const old=h.$('assistantDetail').querySelector('[data-assistant-decision="done"]');old.focus();h.data.set(101,h.item(101,{recommendation:null}));await h.A.loadDetail(101);assert.equal(old.isConnected,false);assert.equal(h.document.activeElement,h.$('assistantConversation'));assert.match(h.$('assistantDetail').textContent,/Нет свежего разбора/);h.onlyReads();
});
test('expiry hides the old advice before a slow periodic response and cannot restore it',async()=>{
  const h=harness(),gate=deferred();h.A.activate(7);await h.A.open(101);const previous=JSON.parse(JSON.stringify(h.data.get(101))),old=h.$('assistantDetail').querySelector('[data-assistant-decision="done"]');old.focus();h.setHook((url,action)=>action==='detail'?gate.promise:undefined);h.advance(3600001);h.timers.at(-1)();assert.equal(old.isConnected,false);assert.equal(h.document.activeElement,h.$('assistantConversation'));assert.equal(h.$('assistantDetail').querySelector('.assistantRecommendation'),null);gate.resolve({ok:true,item:previous});await new Promise(setImmediate);assert.equal(h.$('assistantDetail').querySelector('.assistantRecommendation'),null);assert.match(h.$('assistantDetail').textContent,/Нет свежего разбора/);h.onlyReads();
});
test('expiry preserves the logical list focus when the row label changes',async()=>{
  const h=harness();h.A.activate(7);await h.A.open();const old=h.body.querySelector('[data-assistant-client="101"]');old.focus();h.advance(3600001);h.timers.at(-1)();const current=h.body.querySelector('[data-assistant-client="101"]');assert.notEqual(current,old);assert.equal(old.isConnected,false);assert.equal(h.document.activeElement,current);assert.match(current.textContent,/Нет свежего разбора/);h.onlyReads();
});
test('expiry focuses the refresh control if an actions-filtered client disappears',async()=>{
  const h=harness();h.A.activate(7);await h.A.open();await h.click('[data-assistant-filter="actions"]');h.body.querySelector('[data-assistant-client="101"]').focus();h.advance(3600001);h.timers.at(-1)();assert.equal(h.body.querySelector('[data-assistant-client="101"]'),null);assert.equal(h.document.activeElement,h.$('assistantRefresh'));h.onlyReads();
});
for(const failure of [{ok:false,error:'detail_failed',http_status:500},{ok:true,item:null},{ok:false,error:'unauthorized',http_status:401},{ok:false,error:'forbidden',http_status:403}])test('refresh failure clears cached advice instead of retaining it: '+(failure.http_status||'empty'),async()=>{
  const h=harness(),gate=deferred();h.A.activate(7);await h.A.open(101);const old=h.$('assistantDetail').querySelector('[data-assistant-decision="done"]');old.focus();h.setHook((url,action)=>action==='detail'?gate.promise:undefined);const refresh=h.A.loadDetail(101);assert.equal(old.isConnected,true,'A slow read does not rebuild unchanged advice');gate.resolve(failure);await refresh;assert.equal(old.isConnected,false);assert.equal(h.$('assistantDetail').querySelector('.assistantRecommendation'),null);assert.equal(h.document.activeElement,h.$('assistantClose'));h.onlyReads();
});
test('no_contact keeps neutral advice with explicit planning and no reply or automatic decision',async()=>{
  const h=harness();h.data.get(101).recommendation.next_action='no_contact';h.A.activate(7);await h.A.open(101);neutralNoContact(h);assert.ok(h.$('assistantPlan'));assert.equal(h.$('assistantReply'),null);assert.equal(h.$('assistantDetail').querySelectorAll('[data-assistant-decision]').length,0);h.onlyReads();
});
test('expired proposal is never rendered as a fresh recommendation',async()=>{
  const h=harness();h.advance(3600001);h.A.activate(7);await h.A.open(101);assert.match(h.$('assistantDetail').textContent,/Нет свежего разбора/);assert.equal(h.$('assistantDetail').querySelector('[data-assistant-decision]'),null);h.onlyReads();
});
test('SQL expiry uses canonical UTC parsing even when local SQL date parsing is unsupported',async()=>{
  const h=harness(),seen=[];const sql=h.data.get(101).recommendation.expires_at.replace('T',' ').slice(0,19);h.data.get(101).recommendation.expires_at=sql;h.window.ManagerTime.parse=value=>{seen.push(value);return new Date(value.replace(' ','T')+'Z')};h.A.activate(7);await h.A.open(101);assert.match(h.$('assistantDetail').textContent,/Check selected hotel/);assert.ok(seen.includes(sql),'Canonical UTC parser reads the database timestamp');h.advance(3600001);await h.A.loadDetail(101);assert.match(h.$('assistantDetail').textContent,/Нет свежего разбора/);h.onlyReads();
});
test('planning protects the canonical task draft and preserves existing unsaved text',async()=>{
  const h=harness(),host=h.document.createElement('div');host.id='leadTasksBody';host.innerHTML='<form class="taskCreate"><input id="leadTaskTitle"><input id="leadTaskDue"></form>';h.body.appendChild(host);h.$('leadTaskTitle').value='Already edited task';h.$('leadTaskDue').value='2026-10-21T10:00';let blocked=0;h.window.WorkspaceV2Tasks={blockNavigationForDirtyDraft:()=>{blocked++;return true}};h.A.activate(7);await h.A.open(101);await h.click('#assistantPlan');assert.equal(h.opened.length,0);assert.equal(h.leadReads.length,0);assert.ok(blocked>0);assert.equal(h.$('leadTaskTitle').value,'Already edited task');assert.equal(h.$('leadTaskDue').value,'2026-10-21T10:00');assert.equal(h.$('replyText').value,'Unsent customer reply');assert.equal(h.$('replyFile').file,h.file);h.onlyReads();
});

// Planning must read two independent canonical facts: the current saved advice
// and the current task list. Cached assistant tasks are deliberately separate.
function planningHarness(checks=['  Подготовить обещанный расчёт  ']){
  const h=harness(),host=h.document.createElement('section');host.id='leadTasksBody';host.innerHTML='<div class="taskCreate"><input id="leadTaskTitle"><input id="leadTaskDue"></div>';h.body.appendChild(host);
  const heading=h.document.createElement('h3');heading.id='conversationTitle';h.body.appendChild(heading);
  h.data.set(101,h.item(101,{recommendation:{...h.item().recommendation,next_action:'no_contact',should_draft:false,checks,reason:'Менеджер обещал подготовить расчёт; повторное обещание туристу не нужно.'}}));
  const effects={reveals:[],cards:[],focuses:0,dirtyChecks:0};const title=h.$('leadTaskTitle'),focus=title.focus.bind(title);title.focus=()=>{effects.focuses++;focus()};
  h.window.Manager2Presentation={revealTaskDraft:form=>effects.reveals.push(form),openClientCard:target=>effects.cards.push(target)};
  h.window.WorkspaceV2Tasks={blockNavigationForDirtyDraft:()=>{effects.dirtyChecks++;return !!(title.value.trim()||h.$('leadTaskDue').value)}};
  return {...h,effects};
}
async function openPlanning(h){h.A.activate(7);await h.A.open(101);assert.ok(h.$('assistantPlan'));}
function neutralNoContact(h){assert.match(h.$('assistantDetail').querySelector('.assistantRecommendation h4').textContent,/^(?:Сейчас не писать|Исходящее сообщение сейчас не нужно)$/);assert.doesNotMatch(h.$('assistantDetail').textContent,/Дождаться клиента|Ожидание клиента/);}
function preservedComposer(h){assert.equal(h.$('replyText').value,'Unsent customer reply');assert.equal(h.$('replyFile').file,h.file);h.onlyReads();}
async function pausePlanning(h,phase){
  const gate=deferred();let reached=false;
  if(phase==='detail')h.setHook((url,action)=>{if(action==='detail'){reached=true;return gate.promise}});
  else h.setLeadHook(()=>{reached=true;return gate.promise});
  const flight=h.click('#assistantPlan');await new Promise(setImmediate);assert.equal(reached,true,'Planning awaits the canonical '+phase+' read');
  return{finish:async(result)=>{gate.resolve(result??(phase==='detail'?{ok:true,item:h.data.get(101)}:h.leads.get(101)));await flight}};
}

test('planning no-contact opens the canonical draft from a fresh check without sending or saving',async()=>{
  const h=planningHarness();await openPlanning(h);const reads=h.calls.filter(c=>c.action==='detail').length;await h.click('#assistantPlan');
  assert.equal(h.opened.length,1);assert.equal(h.opened[0].id,101);assert.equal(h.opened[0].opts.protectTaskDraft,true);assert.equal(h.S.detail.conversation.id,101);assert.equal(h.calls.filter(c=>c.action==='detail').length,reads+1);assert.equal(h.leadReads.length,1);assert.equal(h.leadReads[0].conversationId,101);assert.equal(h.leadReads[0].protectTaskDraft,true);
  assert.equal(h.$('leadTaskTitle').value,'Подготовить обещанный расчёт');assert.equal(h.$('leadTaskDue').value,'');assert.equal(h.effects.reveals.length,1);assert.equal(h.effects.focuses,1);assert.equal(h.document.activeElement,h.$('leadTaskTitle'));assert.equal(h.$('assistantReply'),null);preservedComposer(h);
});
for(const checks of [[],['   ']])test('planning no-contact leaves a blank title when the check is '+(checks.length?'whitespace':'absent'),async()=>{
  const h=planningHarness(checks);await openPlanning(h);await h.click('#assistantPlan');assert.equal(h.$('leadTaskTitle').value,'');assert.equal(h.effects.reveals.length,1);assert.equal(h.$('leadTaskDue').value,'');preservedComposer(h);
});
test('planning keeps an existing task above the neutral no-contact title without changing filters',async()=>{
  const h=planningHarness(),task={id:91,title:'Сравнить обещанные варианты',status:'open',due_state:'unscheduled'};h.data.get(101).tasks=[task];h.A.activate(7);await h.A.openPlan();
  const card=h.$('assistantList').querySelector('[data-assistant-client="101"]');assert.ok(card);assert.equal(card.querySelector('span').textContent,task.title);assert.doesNotMatch(card.textContent,/Ожидание клиента/);
  await h.click('[data-assistant-filter="waiting"]');assert.ok(h.$('assistantList').querySelector('[data-assistant-client="101"]'));await h.A.loadDetail(101);neutralNoContact(h);assert.equal(h.$('assistantReply'),null);preservedComposer(h);
});
for(const freshTask of [true,false])test('planning trusts fresh tasks when the assistant cache is '+(freshTask?'empty':'outdated'),async()=>{
  const h=planningHarness(),task={id:91,title:'Проверить обещанный расчёт',status:'open',due_state:'unscheduled'};
  h.data.get(101).tasks=freshTask?[]:[task];h.leads.set(101,h.lead({tasks:freshTask?[task]:[]}));await openPlanning(h);await h.click('#assistantPlan');
  assert.equal(h.leadReads.length,1);assert.equal(h.effects.cards.length,freshTask?1:0);assert.equal(h.effects.reveals.length,freshTask?0:1);assert.equal(h.$('leadTaskTitle').value,freshTask?'':'Подготовить обещанный расчёт');preservedComposer(h);
});
for(const [name,change] of [
  ['failed refresh',false],['degraded task read',{degraded_components:['tasks']}],['read-only card',{can_edit_pipeline:false}],
  ['won lead',{pipeline:{outcome:{outcome:'won'}}}],['lost lead',{pipeline:{outcome:{outcome:'lost'}}}],['missing task list',{tasks:null}]
])test('planning does not fill or reveal a new task after '+name,async()=>{
  const h=planningHarness();await openPlanning(h);h.setLeadHook(()=>change===false?false:h.lead(change));await h.click('#assistantPlan');
  assert.equal(h.leadReads.length,1);assert.equal(h.$('leadTaskTitle').value,'');assert.equal(h.effects.reveals.length,0);assert.equal(h.effects.cards.length,0);assert.equal(h.effects.focuses,0);preservedComposer(h);
});
for(const kind of ['changed','missing','expired','invalid timestamp'])test('planning never copies an old check after the recommendation is '+kind,async()=>{
  const h=planningHarness();await openPlanning(h);const paused=await pausePlanning(h,'detail'),original=h.data.get(101);
  const recommendation=kind==='missing'?null:{...original.recommendation,...(kind==='changed'?{revision:'b'.repeat(64),checks:['Другое действие']}:{expires_at:kind==='expired'?'2000-01-01T00:00:00Z':'unknown'})};
  h.data.set(101,{...original,recommendation});await paused.finish();assert.equal(h.$('leadTaskTitle').value,'');assert.equal(h.$('leadTaskDue').value,'');assert.equal(h.effects.cards.length,0);preservedComposer(h);
});
test('planning rechecks expiry after the canonical task read',async()=>{
  const h=planningHarness();await openPlanning(h);const paused=await pausePlanning(h,'lead');h.advance(3600001);await paused.finish();assert.equal(h.$('leadTaskTitle').value,'');assert.equal(h.$('leadTaskDue').value,'');preservedComposer(h);
});
test('planning rejects a replaced canonical task snapshot while the advice read is pending',async()=>{
  const h=planningHarness();await openPlanning(h);const paused=await pausePlanning(h,'detail');h.S.detail.lead=h.lead({tasks:[{id:92,title:'Уже добавленный расчёт',status:'open'}]});h.$('replyText').focus();await paused.finish();
  assert.equal(h.$('leadTaskTitle').value,'');assert.equal(h.effects.reveals.length,0);assert.equal(h.effects.focuses,0);assert.equal(h.document.activeElement,h.$('replyText'));preservedComposer(h);
});
for(const [name,changes] of [['read-only recommendation',{can_decide:false}],['new owner',{manager_id:9,can_decide:false}],['closed conversation',{status:'closed',can_decide:false}]])test('planning stops after a fresh '+name,async()=>{
  const h=planningHarness();await openPlanning(h);const paused=await pausePlanning(h,'detail');h.data.set(101,{...h.data.get(101),...changes});await paused.finish();assert.equal(h.$('leadTaskTitle').value,'');assert.equal(h.effects.reveals.length,0);assert.equal(h.effects.cards.length,0);assert.equal(h.effects.focuses,0);preservedComposer(h);
});
for(const phase of ['detail','lead'])for(const change of ['A-B-A','new authentication'])test('planning fences '+change+' while awaiting '+phase,async()=>{
  const h=planningHarness();await openPlanning(h);const paused=await pausePlanning(h,phase);
  if(change==='A-B-A'){h.navigate(102);h.navigate(101)}else h.S.authGeneration++;
  h.$('replyText').focus();const focus=h.document.activeElement;await paused.finish();assert.equal(h.$('leadTaskTitle').value,'');assert.equal(h.effects.reveals.length,0);assert.equal(h.effects.cards.length,0);assert.equal(h.effects.focuses,0);assert.equal(h.document.activeElement,focus);preservedComposer(h);
});
for(const phase of ['detail','lead'])test('planning protects a new manual task draft entered while awaiting '+phase,async()=>{
  const h=planningHarness();await openPlanning(h);const paused=await pausePlanning(h,phase);h.$('leadTaskTitle').value='Новая ручная правка';h.$('leadTaskDue').value='2026-12-01T11:45';h.$('leadTaskDue').focus();const checks=h.effects.dirtyChecks;await paused.finish();
  assert.ok(h.effects.dirtyChecks>checks,'Canonical dirty-draft protection is rechecked');assert.equal(h.$('leadTaskTitle').value,'Новая ручная правка');assert.equal(h.$('leadTaskDue').value,'2026-12-01T11:45');assert.equal(h.effects.reveals.length,0);assert.equal(h.effects.focuses,0);assert.equal(h.document.activeElement,h.$('leadTaskDue'));preservedComposer(h);
});
for(const navigation of ['another client','A-B-A selection','close and reopen'])test('planning rejects '+navigation+' while the canonical conversation open is pending',async()=>{
  const h=planningHarness(),gate=deferred();h.A.activate(7);await h.A.openPlan();await h.click('[data-assistant-filter="waiting"]');await h.A.loadDetail(101);
  const open=h.window.WorkspaceV2Conversation.open;let entered=false;h.window.WorkspaceV2Conversation.open=async(...args)=>{const result=await open(...args);entered=true;await gate.promise;return result};
  const planning=h.click('#assistantPlan');await new Promise(setImmediate);assert.equal(entered,true);
  if(navigation==='close and reopen'){h.A.close();await h.A.openPlan();await h.click('[data-assistant-filter="waiting"]');await h.A.loadDetail(101)}
  else{await h.A.loadDetail(102);if(navigation==='A-B-A selection')await h.A.loadDetail(101)}
  h.$('replyText').focus();const focus=h.document.activeElement;gate.resolve();await planning;
  assert.equal(h.opened.length,1);assert.equal(h.leadReads.length,0,'Old assistant intent must stop before another canonical read');assert.equal(h.$('leadTaskTitle').value,'');assert.equal(h.effects.reveals.length,0);assert.equal(h.effects.cards.length,0);assert.equal(h.effects.focuses,0);assert.equal(h.document.activeElement,focus);preservedComposer(h);
});
test('planning accepts canonical same-selection sync when opening the intended conversation',async()=>{
  const h=planningHarness();h.navigate(102);
  // HTTP detail objects are separate from the server fixture; sync may clear
  // only the cached recommendation while its canonical refresh is pending.
  h.setHook((url,action,payload)=>action==='detail'?{ok:true,item:JSON.parse(JSON.stringify(h.data.get(payload.conversation_id)))}:undefined);
  h.A.activate(7);await h.A.openPlan();await h.click('[data-assistant-filter="waiting"]');await h.A.loadDetail(101);
  const open=h.window.WorkspaceV2Conversation.open;h.window.WorkspaceV2Conversation.open=async(...args)=>{const result=await open(...args);h.A.sync();return result};
  await h.click('#assistantPlan');assert.equal(h.S.current,101);assert.equal(h.leadReads.length,1);assert.equal(h.$('leadTaskTitle').value,'Подготовить обещанный расчёт');assert.equal(h.effects.reveals.length,1);assert.equal(h.effects.focuses,1);preservedComposer(h);
});
test('late detail from previous client cannot replace current selection',async()=>{
  const h=harness(),gate=deferred();h.setHook((url,action,p)=>action==='detail'&&p.conversation_id===101?gate.promise:undefined);h.A.activate(7);const old=h.A.open(101);await h.A.loadDetail(102);const current=h.$('assistantDetail').querySelector('[data-assistant-decision="done"]');current.focus();gate.resolve({ok:true,item:h.item(101)});await old;assert.match(h.$('assistantDetail').textContent,/Client 102/);assert.doesNotMatch(h.$('assistantDetail').textContent,/Client 101/);assert.equal(h.document.activeElement,current,'Late initial open does not steal the new client focus');h.onlyReads();
});
test('an obsolete list-click read cannot steal focus after same-client reopening',async()=>{
  const h=harness(),gate=deferred();h.A.activate(7);await h.A.open();h.setHook((url,action,p)=>action==='detail'&&p.conversation_id===101?gate.promise:undefined);const old=h.click('[data-assistant-client="101"]');h.A.close();h.setHook(null);await h.A.open(101);const current=h.$('assistantDetail').querySelector('[data-assistant-decision="done"]');current.focus();gate.resolve({ok:true,item:h.item(101)});await old;assert.equal(h.document.activeElement,current);assert.equal(current.isConnected,true);h.onlyReads();
});
test('Back fences a pending identical read without restoring detail or stealing list focus',async()=>{
  const h=harness(),gate=deferred();h.A.activate(7);await h.A.open(101);h.setHook((url,action)=>action==='detail'?gate.promise:undefined);const refresh=h.A.loadDetail(101);await h.click('#assistantBack');const button=h.body.querySelector('[data-assistant-client="101"]');assert.equal(h.document.activeElement,button);gate.resolve({ok:true,item:h.item(101)});await refresh;assert.equal(h.$('assistantDialog').classList.contains('hasSelection'),false);assert.equal(h.document.activeElement,button);assert.equal(h.$('assistantDetail').querySelector('.assistantRecommendation'),null);h.onlyReads();
});
test('Close and same-client reopen reject an earlier read while retaining current focus',async()=>{
  const h=harness(),gate=deferred();h.A.activate(7);await h.A.open(101);h.setHook((url,action)=>action==='detail'?gate.promise:undefined);const old=h.A.loadDetail(101);h.A.close();h.setHook(null);h.data.set(101,h.item(101,{recommendation:null}));await h.A.open(101);const focus=h.document.activeElement;gate.resolve({ok:true,item:h.item(101)});await old;assert.equal(h.document.activeElement,focus);assert.match(h.$('assistantDetail').textContent,/Нет свежего разбора/);assert.equal(h.$('assistantDetail').querySelector('.assistantRecommendation'),null);h.onlyReads();
});
test('a late list page cannot overwrite a newer current-client detail',async()=>{
  const h=harness(),gate=deferred();h.A.activate(7);await h.A.open();h.setHook((url,action)=>action==='list'?gate.promise:undefined);const refresh=h.A.loadList();h.data.get(101).recommendation.reason='New canonical detail.';await h.A.loadDetail(101);const older=h.item(101);older.recommendation.reason='Older list snapshot.';gate.resolve({ok:true,items:[older],has_more:false,next_cursor:null});await refresh;assert.match(h.$('assistantDetail').textContent,/New canonical detail/);assert.doesNotMatch(h.$('assistantDetail').textContent,/Older list snapshot/);h.onlyReads();
});
test('a changed current transcript hides old reasoning before the new read completes',async()=>{
  const h=harness(),gate=deferred();h.A.activate(7);await h.A.open(101);h.setHook((url,action)=>action==='detail'?gate.promise:undefined);h.S.detail.messages.push({id:36,text:'A new constraint.'});h.A.sync();assert.doesNotMatch(h.$('assistantDetail').textContent,/Check selected hotel/);assert.equal(h.$('assistantDetail').querySelector('[data-assistant-decision]'),null);gate.resolve({ok:false,error:'stale_context'});await new Promise(setImmediate);assert.match(h.$('assistantStatus').textContent,/Переписка изменилась/);h.onlyReads();
});
test('account replacement clears private rows and rejects the old list response',async()=>{
  const h=harness(),gate=deferred();h.A.activate(7);h.setHook((url,action)=>action==='list'?gate.promise:undefined);const old=h.A.open();h.S.manager={id:8};h.S.authGeneration++;h.A.activate(8);gate.resolve({ok:true,items:[h.item(101)],has_more:false});await old;assert.equal(h.$('assistantDialog').open,false);assert.equal(h.$('assistantList').textContent,'');assert.equal(h.$('assistantDetail').textContent,'');h.onlyReads();
});
test('suspend clears private content while preserving unsent customer draft',async()=>{
  const h=harness();h.A.activate(7);await h.A.open(101);h.S.authExpired=true;h.A.suspend();assert.equal(h.$('assistantDialog').open,false);assert.equal(h.$('assistantDetail').textContent,'');assert.equal(h.$('assistantList').textContent,'');assert.equal(h.$('replyText').value,'Unsent customer reply');assert.equal(h.$('replyFile').file,h.file);
});
test('a late decision response cannot force selection back to its old client',async()=>{
  const h=harness(),gate=deferred();h.A.activate(7);await h.A.open(101);h.setHook((url,action)=>action==='decision'?gate.promise:undefined);const write=h.click('[data-assistant-decision="done"]');await h.A.loadDetail(102);gate.resolve({ok:true});await write;assert.match(h.$('assistantDetail').textContent,/Client 102/);assert.doesNotMatch(h.$('assistantDetail').textContent,/Client 101/);assert.equal(h.calls.filter(c=>c.action==='decision').length,1);
});
test('close/reopen fences old decisions even when the same client is selected',async()=>{
  const h=harness(),gate=deferred();h.A.activate(7);await h.A.open(101);h.setHook((url,action)=>action==='decision'?gate.promise:undefined);const write=h.click('[data-assistant-decision="done"]');h.A.close();await h.A.open(102);const before=h.calls.length;gate.resolve({ok:false,error:'decision_conflict'});await write;assert.match(h.$('assistantDetail').textContent,/Client 102/);assert.equal(h.calls.length,before);assert.equal(h.$('assistantStatus').textContent,'');
});
test('switching client during decision reconciliation cannot publish an old conflict',async()=>{
  const h=harness(),gate=deferred();h.A.activate(7);await h.A.open(101);h.setHook((url,action,p)=>action==='decision'?{ok:false,error:'decision_conflict'}:action==='detail'&&p.conversation_id===101?gate.promise:undefined);const write=h.click('[data-assistant-decision="done"]');await new Promise(setImmediate);await h.A.loadDetail(102);gate.resolve({ok:true,item:h.item(101)});await write;assert.match(h.$('assistantDetail').textContent,/Client 102/);assert.equal(h.$('assistantStatus').textContent,'');assert.equal(h.calls.filter(c=>c.action==='decision').length,1);
});
test('uncertain decision reconciles by reading without retrying the write',async()=>{
  const h=harness();h.A.activate(7);await h.A.open(101);h.setHook((url,action)=>action==='decision'?Promise.reject(Error('lost response')):undefined);await h.click('[data-assistant-decision="done"]');assert.equal(h.calls.filter(c=>c.action==='decision').length,1);assert.equal(h.calls.filter(c=>c.action==='detail').length,2);assert.match(h.$('assistantStatus').textContent,/не подтверждён/);
});
test('conflicting decision loads the server state without another mutation',async()=>{
  const h=harness();h.A.activate(7);await h.A.open(101);h.setHook((url,action)=>{if(action!=='decision')return;h.data.get(101).recommendation.decision='irrelevant';return{ok:false,error:'decision_conflict'}});await h.click('[data-assistant-decision="done"]');assert.match(h.$('assistantDetail').textContent,/Отмечено неактуальным/);assert.equal(h.calls.filter(c=>c.action==='decision').length,1);
});
test('late task completion cannot replace another selected client',async()=>{
  const h=harness(),gate=deferred();h.data.get(101).tasks=[{id:31,title:'Check supplier',status:'open',due_state:'overdue'}];h.A.activate(7);await h.A.open(101);h.setHook((url)=>url==='pipeline-api.php'?gate.promise:undefined);const write=h.click('[data-assistant-task="31"]');await h.A.loadDetail(102);gate.resolve({ok:true});await write;assert.match(h.$('assistantDetail').textContent,/Client 102/);assert.equal(h.calls.filter(c=>c.action==='set_task_completed').length,1);
});
test('deadline draft survives scheduled refresh and transcript changes until explicit cancellation',async()=>{
  const h=harness();h.data.get(101).tasks=[{id:31,title:'Check supplier',status:'open',due_state:'today',due_at_utc:'2026-10-20 17:00:00'}];h.A.activate(7);await h.A.open(101);await h.click('[data-assistant-snooze="31"]');const input=h.$('assistantDue');input.value='2026-10-22T15:45';const before=h.calls.length;h.timers.at(-1)();await new Promise(setImmediate);assert.equal(h.$('assistantDue'),input,'Timer retains the native unsaved date input');assert.equal(input.value,'2026-10-22T15:45');assert.equal(h.calls.length,before,'Timer does not reload while a deadline is edited');h.S.detail.messages.push({id:36,text:'A new travel constraint.'});h.A.sync();assert.equal(h.$('assistantDue'),input,'Transcript invalidation retains the native deadline input');assert.equal(input.value,'2026-10-22T15:45');assert.equal(h.$('assistantDetail').querySelector('.assistantRecommendation').hidden,true,'Obsolete advice is hidden immediately');assert.equal(h.calls.length,before,'Transcript change waits for explicit deadline completion');h.data.get(101).recommendation={...h.item(101).recommendation,reason:'Updated advice after the new message.'};await h.click('#assistantCancelDue');assert.equal(h.$('assistantDue'),null);assert.match(h.$('assistantDetail').textContent,/Updated advice/);assert.equal(h.calls.length,before+1);assert.equal(h.calls.at(-1).action,'detail');h.onlyReads();
});
test('explicit deadline Cancel clears its form even when the canonical read is identical',async()=>{
  const h=harness();h.data.get(101).tasks=[{id:31,title:'Check supplier',status:'open',due_state:'today',due_at_utc:'2026-10-20 17:00:00'}];h.A.activate(7);await h.A.open(101);await h.click('[data-assistant-snooze="31"]');const input=h.$('assistantDue');input.value='2026-10-22T15:45';const before=h.calls.length;await h.click('#assistantCancelDue');assert.equal(input.isConnected,false);assert.equal(h.$('assistantTaskEditor').querySelector('form'),null);assert.equal(h.calls.length,before+1);assert.equal(h.calls.at(-1).action,'detail');assert.equal(h.document.activeElement,h.$('assistantConversation'));h.onlyReads();
});
test('explicit deadline Save consumes the form when the task response has no visible change',async()=>{
  const h=harness();h.data.get(101).tasks=[{id:31,title:'Check supplier',status:'open',due_state:'today',due_at_utc:'2026-10-20 17:00:00'}];h.A.activate(7);await h.A.open(101);await h.click('[data-assistant-snooze="31"]');h.$('assistantDue').value='2026-10-20T20:00';h.$('assistantTaskEditor').querySelector('form').onsubmit({preventDefault(){}});await new Promise(setImmediate);assert.equal(h.$('assistantTaskEditor').querySelector('form'),null);assert.equal(h.calls.filter(c=>c.action==='update_task').length,1);assert.match(h.$('assistantStatus').textContent,/Задача сохранена/);assert.equal(h.document.activeElement,h.$('assistantConversation'));
});
test('access refusal clears the previously visible recommendation',async()=>{
  const h=harness();h.A.activate(7);await h.A.open(101);h.data.delete(101);await h.A.loadDetail(101);assert.doesNotMatch(h.$('assistantDetail').textContent,/Check selected hotel/);assert.equal(h.body.querySelector('[data-assistant-client="101"]'),null);assert.equal(h.$('assistantDialog').classList.contains('hasSelection'),false,'Mobile returns to the list after an inaccessible selection');h.onlyReads();
});

test('existing dirty task editor blocks assistant mutations and same-client reloads',async()=>{
  const h=harness();let warnings=0;h.window.WorkspaceV2Tasks={blockNavigationForDirtyDraft:()=>{warnings++;return true}};h.data.get(101).tasks=[{id:31,title:'Check supplier',status:'open',due_state:'today'}];h.A.activate(7);await h.A.open(101);await h.click('[data-assistant-task="31"]');assert.equal(h.$('assistantDialog').open,false);assert.equal(h.calls.filter(c=>c.url==='pipeline-api.php').length,0);await h.A.open(101);await h.click('#assistantConversation');assert.equal(h.$('assistantDialog').open,false);assert.equal(h.opened.length,0);assert.ok(warnings>=2);assert.equal(h.$('replyText').value,'Unsent customer reply');assert.equal(h.$('replyFile').file,h.file);h.onlyReads();
});


test('work plan is a separate inline view with an explicit own scope and preserves the composer',async()=>{
 const h=harness();h.A.activate(7);await h.A.openPlan();
 assert.equal(h.$('assistantDialog').parentElement.className,'inboxZone');assert.equal(h.$('assistantDialog').getAttribute('role'),'region');assert.equal(h.$('assistantTitle').textContent,'Мой план');
 assert.equal(h.calls.find(c=>c.action==='list').scope,'mine');
 await h.A.loadDetail(101);await h.click('#assistantConversation');
 assert.equal(h.A.isPlanOpen(),true);assert.equal(h.$('replyText').value,'Unsent customer reply');assert.equal(h.$('replyFile').file,h.file);h.onlyReads();
});
test('work plan team scope is explicit and never survives an account change',async()=>{
 const h=harness();h.S.manager.role='admin';h.A.activate(7);await h.A.openPlan();h.$('assistantScope').value='team';h.$('assistantScope').onchange();await new Promise(setImmediate);
 assert.equal(h.calls.filter(c=>c.action==='list').at(-1).scope,'team');
 h.S.manager={id:8,role:'manager'};h.S.authGeneration++;h.A.activate(8);await h.A.openPlan();
 assert.equal(h.calls.filter(c=>c.action==='list').at(-1).scope,'mine');assert.equal(h.$('assistantScope').parentElement.hidden,true);h.onlyReads();
});
test('plan advice opens one canonical saved proposal without generation or insertion',async()=>{
 const h=harness();let previews=0;h.window.WorkspaceV2AiDraft={openPrepared:async()=>{previews++;return true}};
 h.A.activate(7);await h.A.openPlan();await h.A.loadDetail(101);await h.click('#assistantReply');
 assert.equal(previews,1);assert.equal(h.opened.length,1);assert.equal(h.$('replyText').value,'Unsent customer reply');assert.equal(h.$('replyFile').file,h.file);h.onlyReads();
});
test('no-contact is an explicit plan view and is never converted to a send action',async()=>{
 const h=harness();h.data.set(101,h.item(101,{recommendation:{...h.item().recommendation,next_action:'no_contact',should_draft:false}}));
 h.A.activate(7);await h.A.openPlan();await h.click('[data-assistant-filter="waiting"]');
 assert.ok(h.body.querySelector('[data-assistant-client="101"]'));await h.A.loadDetail(101);assert.equal(h.$('assistantReply'),null);assert.ok(h.$('assistantPlan'));assert.doesNotMatch(h.$('assistantDetail').textContent,/Дождаться клиента|Ожидание клиента/);h.onlyReads();
});

async function run(){const selected=process.argv.includes('--entry-review-only')?tests.filter(t=>t.name.startsWith('entry review ')):process.argv.includes('--daily-flow-only')?tests.filter(t=>t.name.startsWith('daily flow ')):process.argv.includes('--ux-review-only')?tests.filter(t=>t.name.startsWith('ux review ')):process.argv.includes('--planning-only')?tests.filter(t=>t.name.startsWith('planning ')):tests;let passed=0;for(const t of selected){await t.run();passed++;console.log('PASS '+t.name)}console.log('Manager assistant behavior: '+passed+' checks passed')}
test('inline plan keeps the protected task editor reachable and never opens another client',async()=>{
 const h=harness();let blocks=0;h.window.WorkspaceV2Tasks={blockNavigationForDirtyDraft:()=>{blocks++;return true}};h.A.activate(7);await h.A.openPlan();await h.A.loadDetail(101);await h.click('#assistantConversation');assert.equal(h.A.isPlanOpen(),true);assert.equal(h.$('assistantDialog').parentElement.className,'inboxZone');assert.equal(h.opened.length,0);assert.equal(blocks,1);assert.equal(h.$('replyText').value,'Unsent customer reply');assert.equal(h.$('replyFile').file,h.file);h.onlyReads();
});
test('changing from the legacy review to My Plan never displays cached team rows during the read',async()=>{
 const h=harness();h.S.manager.role='admin';h.A.activate(7);await h.A.open();assert.ok(h.$('assistantList').children.length);const gate=deferred();h.setHook((url,action)=>action==='list'?gate.promise:undefined);const opening=h.A.openPlan();assert.equal(h.$('assistantList').querySelector('[data-assistant-client]'),null);gate.resolve({ok:true,items:[],has_more:false});await opening;assert.equal(h.calls.at(-1).scope,'mine');h.onlyReads();
});
test('plan cards display the advice reason and the earliest existing task deadline',async()=>{
 const h=harness();h.data.get(101).tasks=[{id:3,title:'Later',status:'open',due_at_utc:'2026-10-25 09:00:00'},{id:4,title:'Earlier',status:'open',due_at_utc:'2026-10-22 10:00:00'}];h.A.activate(7);await h.A.openPlan();const card=h.$('assistantList').querySelector('[data-assistant-client="101"]');assert.match(card.textContent,/Срок:/);assert.match(card.textContent,/2026-10-22/);assert.ok(card.textContent.includes(h.data.get(101).recommendation.reason));h.onlyReads();
});

// Cross-surface completion: run the canonical result and stage owners with the
// same dependency-free DOM. No source copies, server/provider calls or sends.
function completionHarness({initialOutcome='open',initialStage='new',canEdit=true}={}){
 const h=harness(),host=h.document.createElement('section');host.innerHTML='<select id="leadOutcome"></select><div id="leadCloseReasonWrap"><select id="leadCloseReason"></select></div><div id="leadSaleWrap"><input id="leadSaleAmount"><input id="leadSaleDate"></div><textarea id="leadOutcomeNote"></textarea><button id="saveOutcome"></button><p id="outcomeSaveStatus"></p><p id="salesSaveStatus"></p>';h.body.appendChild(host);h.$('leadOutcome').value=initialOutcome;h.$('leadOutcome').closest=()=>null;
 h.S.pipeline={stages:[{stage_key:'new',display_name:'Запрос',is_terminal:0,is_won:0},{stage_key:'custom_won',display_name:'Договор подписан',is_terminal:1,is_won:1},{stage_key:'custom_lost',display_name:'Не бронирует',is_terminal:1,is_won:0}]};
 let hook=null;h.W.pipe=async(action,payload)=>{h.calls.push({action,...payload});return hook?hook(action,payload):{ok:true}};
 const context={window:h.window,document:h.document,console};for(const file of ['workspace-v2-outcome.js','workspace-v2-pipeline.js','workspace-v2-kanban-stage.js'])vm.runInNewContext(fs.readFileSync(path.join(__dirname,'../manager/assets/'+file),'utf8'),context);
 const O=h.window.WorkspaceV2Outcome;O.bind({canEdit,pipeline:{stage:{stage_key:initialStage},outcome:{outcome:initialOutcome}}});return {...h,O,setWriteHook:fn=>hook=fn};
}
test('completing a custom terminal stage waits for one explicit atomic result save',async()=>{
 const h=completionHarness();assert.equal(h.O.startCompletion(h.S.pipeline.stages[1]),true);assert.equal(h.$('leadOutcome').value,'won');assert.equal(h.$('leadOutcomeStage').value,'custom_won');assert.equal(h.$('leadOutcomeStageWrap').hidden,false);assert.equal(h.calls.length,0);
 h.$('leadSaleAmount').value='250000';h.$('leadSaleDate').value='2026-11-02';await h.O.saveOutcome();assert.equal(h.calls.length,1);assert.equal(h.calls[0].action,'set_outcome');assert.equal(h.calls[0].stage_key,'custom_won');assert.equal(h.calls[0].expected_stage_key,'new');assert.equal(h.calls[0].sale_amount,'250000');assert.equal(h.O.isOutcomeDirty(),false);assert.equal(h.$('replyFile').file,h.file);assert.equal(h.$('replyText').value,'Unsent customer reply');
});
test('refusal requires its reason and reuses the same atomic stage/result action',async()=>{
 const h=completionHarness();h.O.startCompletion(h.S.pipeline.stages[2]);await h.O.saveOutcome();assert.equal(h.calls.length,0);assert.match(h.$('outcomeSaveStatus').textContent,/причину/);h.$('leadCloseReason').value='price';await h.O.saveOutcome();assert.equal(h.calls.length,1);assert.equal(h.calls[0].outcome,'lost');assert.equal(h.calls[0].stage_key,'custom_lost');assert.equal(h.calls[0].close_reason,'price');
});
test('historical inactive stage does not block an ordinary note edit or rewrite history',async()=>{
 const h=completionHarness({initialOutcome:'lost',initialStage:'historic_inactive'});assert.equal(h.$('leadOutcomeStageWrap').hidden,true);h.$('leadCloseReason').value='historic_reason';h.$('leadOutcomeNote').value='Дополнение к результату';h.$('leadOutcomeNote').oninput();await h.O.saveOutcome();assert.equal(h.calls.length,1);assert.equal(Object.hasOwn(h.calls[0],'stage_key'),false);assert.equal(h.calls[0].note,'Дополнение к результату');
});
test('completion conflict keeps the edit visible and never retries the write',async()=>{
 const h=completionHarness();h.O.startCompletion(h.S.pipeline.stages[1]);h.setWriteHook(()=>({ok:false,error:'stage_or_result_changed'}));await h.O.saveOutcome();assert.equal(h.calls.length,1);assert.equal(h.O.isOutcomeDirty(),true);assert.match(h.$('outcomeSaveStatus').textContent,/Этап уже изменился/);assert.equal(h.$('leadOutcomeStage').value,'custom_won');
});
test('a pending result save cannot double-submit or clear another client state',async()=>{
 const h=completionHarness(),gate=deferred();h.O.startCompletion(h.S.pipeline.stages[1]);h.setWriteHook(()=>gate.promise);const saving=h.O.saveOutcome();await h.O.saveOutcome();assert.equal(h.calls.length,1);h.S.current=102;h.$('outcomeSaveStatus').textContent='Новый клиент';gate.resolve({ok:true});await saving;assert.equal(h.$('outcomeSaveStatus').textContent,'Новый клиент');assert.equal(h.O.isOutcomeSaving(101),false);
});
test('terminal selection from the card or Sales opens completion without a stage-only write',async()=>{
 const h=completionHarness(),stage=h.document.createElement('select');stage.value='custom_won';assert.equal(await h.window.WorkspaceV2Pipeline.saveStage(stage,'new'),false);assert.equal(stage.value,'new');assert.equal(h.calls.length,0);assert.equal(h.$('leadOutcome').value,'won');
 h.window.WorkspaceV2Kanban={setMode:()=>{}};assert.equal(await h.window.WorkspaceV2KanbanStage.saveStage(101,'custom_lost',{previousStage:'new'}),false);assert.equal(h.calls.length,0);assert.equal(h.$('leadOutcome').value,'lost');assert.equal(h.opened.length,1);
});
test('read-only cards cannot start completion through the exported control',async()=>{
 const h=completionHarness({canEdit:false});assert.equal(h.O.startCompletion(h.S.pipeline.stages[1]),false);assert.equal(h.calls.length,0);
});

test('switching sections cannot discard an open plan deadline editor',async()=>{
 const h=harness();h.data.get(101).tasks=[{id:31,title:'Согласованный звонок',status:'open',due_at_utc:'2026-11-03 10:00:00'}];h.A.activate(7);await h.A.openPlan();await h.A.loadDetail(101);await h.click('[data-assistant-snooze="31"]');h.$('assistantDue').value='2026-11-04T10:00';assert.equal(h.A.canLeavePlan(),false);assert.equal(h.$('assistantDue').value,'2026-11-04T10:00');assert.equal(h.document.activeElement,h.$('assistantDue'));assert.match(h.$('assistantStatus').textContent,/Сохраните или отмените/);h.onlyReads();await h.click('#assistantCancelDue');assert.equal(h.A.canLeavePlan(),true);
});

for(const initialScope of ['mine','team'])test('ux review scope change preserves an unsaved deadline from '+initialScope,async()=>{
 const h=harness();h.S.manager.role='admin';h.data.get(101).tasks=[{id:31,title:'Согласованный звонок',status:'open',due_at_utc:'2026-11-03 10:00:00'}];h.A.activate(7);await h.A.openPlan();
 if(initialScope==='team'){h.$('assistantScope').value='team';h.$('assistantScope').onchange();await new Promise(setImmediate)}
 await h.A.loadDetail(101);await h.click('[data-assistant-snooze="31"]');const input=h.$('assistantDue'),form=h.$('assistantTaskEditor').querySelector('form');input.value='2026-11-04T10:00';const before=h.calls.length;
 const nextScope=initialScope==='mine'?'team':'mine';h.$('assistantScope').value=nextScope;h.$('assistantScope').onchange();await new Promise(setImmediate);
 assert.equal(h.$('assistantScope').value,initialScope);assert.equal(h.$('assistantDue'),input);assert.equal(h.$('assistantTaskEditor').querySelector('form'),form);assert.equal(input.isConnected,true);assert.equal(input.value,'2026-11-04T10:00');assert.equal(h.document.activeElement,input);assert.equal(h.calls.length,before);assert.match(h.$('assistantStatus').textContent,/Сохраните или отмените/);assert.equal(h.$('replyText').value,'Unsent customer reply');assert.equal(h.$('replyFile').file,h.file);
 await h.click('#assistantCancelDue');h.$('assistantScope').value=nextScope;h.$('assistantScope').onchange();await new Promise(setImmediate);assert.equal(h.calls.at(-1).scope,nextScope);assert.equal(h.$('assistantScope').value,nextScope);h.onlyReads();
});
test('ux review unanswered message stays neutral even when the latest input is thanks',async()=>{
 const h=harness();h.S.detail.messages=[{id:34,text:'А какая цена на 14 ночей?'},{id:35,text:'Спасибо'}];h.data.set(101,h.item(101,{recommendation:null,operational:{awaiting_manager_reply:true}}));h.A.activate(7);await h.A.openPlan();const card=h.$('assistantList').querySelector('[data-assistant-client="101"]');assert.match(card.textContent,/Разобрать сообщение клиента/);assert.match(card.textContent,/Есть обращение без записанного ответа/);assert.doesNotMatch(card.textContent,/Ответить на вопрос клиента|Есть вопрос без ответа/);
 await h.A.loadDetail(101);assert.match(h.$('assistantDetail').textContent,/нужен ли ответ/);h.data.set(101,h.item(101,{operational:{awaiting_manager_reply:true},recommendation:{...h.item().recommendation,next_action:'no_contact'}}));await h.A.loadDetail(101);await h.click('[data-assistant-filter="waiting"]');const waiting=h.$('assistantList').querySelector('[data-assistant-client="101"]');assert.match(waiting.textContent,/Сейчас не писать/);assert.doesNotMatch(waiting.textContent,/Разобрать сообщение/);assert.equal(h.$('assistantReply'),null);assert.equal(h.$('replyText').value,'Unsent customer reply');assert.equal(h.$('replyFile').file,h.file);h.onlyReads();
});


test('daily flow Manager2 exposes one canonical reply helper without a duplicate menu entry',async()=>{const h=harness();h.A.activate(7);h.A.bind();assert.equal(h.$('assistantClientOpen'),null);assert.equal(h.calls.length,0);h.onlyReads();});

module.exports={harness,deferred};

if(require.main===module)run().catch(error=>{console.error(error.stack);process.exitCode=1});
