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
    showModal(){this.open=true}close(){this.open=false}
    scrollIntoView(){}
  }
  const body=new Element('body');body.className='manager2';
  document={body,activeElement:null,createElement:tag=>new Element(tag),getElementById:id=>body.querySelector('#'+id),querySelector:s=>body.querySelector(s)};
  const rail=new Element('nav');rail.className='m2Rail';body.appendChild(rail);const end=new Element();end.className='m2RailEnd';rail.appendChild(end);const tools=new Element();tools.className='conversationTools';body.appendChild(tools);
  const add=(id,tag='div')=>{const n=new Element(tag);n.id=id;body.appendChild(n);return n};
  add('replyText','textarea').value='Unsent customer reply';const file={name:'keep.txt'};add('replyFile','input').file=file;
  const S={manager:{id:7},authExpired:false,authGeneration:1,current:101,detail:{conversation:{id:101,manager_id:7,status:'manager'},messages:[{id:35,text:'Current customer request'}]}};
  const calls=[],timers=[];let now=Date.now(),hook=null;
  class Clock extends Date{constructor(...args){super(...(args.length?args:[now]))}static now(){return now}static parse(value){return /^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/.test(String(value))?NaN:Date.parse(value)}}
  const esc=v=>String(v??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
  const item=(id=101,changes={})=>({conversation_id:id,display_name:'Client '+id,channel:'telegram',last_message_at:'2026-10-20T15:00:00Z',tasks:[],recommendation:{revision:'a'.repeat(64),next_action:'check_offer',reason:'Check selected hotel & meal plan.',checks:['Compare the two selected hotels.'],prepared_at:new Date(now).toISOString(),expires_at:new Date(now+3600000).toISOString(),latest_message_id:35,evidence_message_ids:[35],history_incomplete:false,decision:null},...changes});
  const data=new Map([[101,item(101)],[102,item(102)]]);
  const W={S,$:id=>document.getElementById(id),esc,request:async(url,action,payload,options)=>{calls.push({url,action,...payload});if(hook){const r=await hook(url,action,payload,options);if(r!==undefined)return r}if(action==='list')return{ok:true,items:[...data.values()],has_more:false,next_cursor:null};if(action==='detail')return data.has(payload.conversation_id)?{ok:true,item:data.get(payload.conversation_id)}:{ok:false,error:'forbidden'};if(action==='decision'){const row=data.get(payload.conversation_id);row.recommendation={...row.recommendation,decision:payload.decision};return{ok:true}}throw Error('Unexpected assistant request '+action)},pipe:async(action,payload,options)=>{calls.push({url:'pipeline-api.php',action,...payload});return hook?await hook('pipeline-api.php',action,payload,options):{ok:true}}};
  const opened=[];const window={WorkspaceV2:W,WorkspaceV2Conversation:{open:async(id,opts)=>{opened.push({id,opts});S.current=id;return true},refreshLeadData:async()=>true},ManagerTime:{format:v=>v,toInput:()=>'',fromInput:v=>v}};
  const context=vm.createContext({window,document,console,Date:Clock,Event:class{constructor(type,opts={}){this.type=type;Object.assign(this,opts)}},setTimeout:fn=>{timers.push(fn);return timers.length},clearTimeout:()=>{}});
  vm.runInContext(fs.readFileSync(path.join(__dirname,'../manager/assets/workspace-v2-assistant.js'),'utf8'),context,{filename:'workspace-v2-assistant.js'});
  const A=window.WorkspaceV2Assistant,$=W.$;
  async function click(selector){const n=body.querySelector(selector);assert.ok(n,'Control exists: '+selector);assert.equal(n.disabled,false,'Control is enabled: '+selector);return n.onclick?.({preventDefault(){}})}
  function onlyReads(){assert.equal(calls.some(c=>!['list','detail'].includes(c.action)),false,'Only assistant list/detail requests')}
  return{A,W,S,window,document,$,body,calls,data,item,timers,opened,file,click,onlyReads,setHook:fn=>hook=fn,advance:ms=>now+=ms};
}
const deferred=()=>{let resolve;return{promise:new Promise(r=>resolve=r),resolve}};
const tests=[];function test(name,run){tests.push({name,run})}

test('assistant return delegates loaded-history preservation to the canonical conversation owner',async()=>{
  const h=harness();h.A.activate(7);await h.A.open(101);await h.click('#assistantConversation');
  assert.equal(h.opened.length,1);assert.equal(h.opened[0].id,101);
  assert.equal(h.opened[0].opts.preserveHistory,true);
  assert.equal(h.opened[0].opts.preserveMessageScroll,true);assert.equal(h.opened[0].opts.preserveAttachment,true);
  assert.equal(h.$('replyText').value,'Unsent customer reply');assert.equal(h.$('replyFile').file,h.file);h.onlyReads();
});

test('opening a conversation dismisses its menu but explicit Close restores the opener',async()=>{
  const h=harness();h.A.activate(7);h.A.bind();
  const button=h.$('assistantClientOpen'),menu={open:false};button.closest=()=>menu;button.focus();
  await h.A.open(101);await h.click('#assistantConversation');assert.equal(menu.open,false,'Navigation must not reopen the menu over the retained history');
  button.focus();await h.A.open(101);await h.click('#assistantClose');assert.equal(menu.open,true);assert.equal(h.document.activeElement,button);h.onlyReads();
});

test('activation is lazy and exposes current-owner controls without transport',async()=>{
  const h=harness();h.A.activate(7);h.A.bind();assert.equal(h.calls.length,0);assert.equal(h.$('assistantOpen').disabled,false);assert.equal(h.$('assistantClientOpen').hidden,false);h.S.detail.conversation.manager_id=8;h.A.sync();assert.equal(h.$('assistantClientOpen').hidden,true);assert.equal(h.calls.length,0);
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
test('no_contact stays an informational waiting state with no proposed task action',async()=>{
  const h=harness();h.data.get(101).recommendation.next_action='no_contact';h.A.activate(7);await h.A.open(101);assert.match(h.$('assistantDetail').textContent,/Дождаться клиента/);assert.equal(h.$('assistantPlan'),null);assert.equal(h.$('assistantDetail').querySelectorAll('[data-assistant-decision]').length,0);h.onlyReads();
});
test('expired proposal is never rendered as a fresh recommendation',async()=>{
  const h=harness();h.advance(3600001);h.A.activate(7);await h.A.open(101);assert.match(h.$('assistantDetail').textContent,/Нет свежего разбора/);assert.equal(h.$('assistantDetail').querySelector('[data-assistant-decision]'),null);h.onlyReads();
});
test('SQL expiry uses canonical UTC parsing even when local SQL date parsing is unsupported',async()=>{
  const h=harness(),seen=[];const sql=h.data.get(101).recommendation.expires_at.replace('T',' ').slice(0,19);h.data.get(101).recommendation.expires_at=sql;h.window.ManagerTime.parse=value=>{seen.push(value);return new Date(value.replace(' ','T')+'Z')};h.A.activate(7);await h.A.open(101);assert.match(h.$('assistantDetail').textContent,/Check selected hotel/);assert.ok(seen.includes(sql),'Canonical UTC parser reads the database timestamp');h.advance(3600001);await h.A.loadDetail(101);assert.match(h.$('assistantDetail').textContent,/Нет свежего разбора/);h.onlyReads();
});
test('planning opens the canonical task draft and preserves existing unsaved text',async()=>{
  const h=harness(),host=h.document.createElement('div');host.id='leadTasksBody';host.innerHTML='<form class="taskCreate"><input id="leadTaskTitle"><input id="leadTaskDue"></form>';h.body.appendChild(host);h.$('leadTaskTitle').value='Already edited task';h.$('leadTaskDue').value='2026-10-21T10:00';let revealed=0;h.window.Manager2Presentation={revealTaskDraft:()=>revealed++};h.A.activate(7);await h.A.open(101);await h.click('#assistantPlan');assert.equal(h.opened.length,1);assert.equal(h.opened[0].id,101);assert.equal(h.$('leadTaskTitle').value,'Already edited task');assert.equal(h.$('leadTaskDue').value,'2026-10-21T10:00');assert.equal(h.$('replyText').value,'Unsent customer reply');assert.equal(h.$('replyFile').file,h.file);assert.equal(revealed,1);assert.equal(h.calls.some(c=>c.url==='pipeline-api.php'),false,'Planning alone creates no task or reminder');
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

async function run(){let passed=0;for(const t of tests){await t.run();passed++;console.log('PASS '+t.name)}console.log('Manager assistant behavior: '+passed+' checks passed')}
if(require.main===module)run().catch(error=>{console.error(error.stack);process.exitCode=1});
module.exports={harness,deferred};
