const assert=require('node:assert/strict');
const fs=require('node:fs');
const path=require('node:path');
const vm=require('node:vm');

// Execute the real optional presentation module with deferred synthetic HTTP.
// The DOM fixture deliberately retains the composer and transcript as siblings.
class Element {
  constructor(tag='div'){this.tagName=tag.toUpperCase();this.children=[];this.dataset={};this.attributes={};this.className='';this.hidden=false;this.open=false;this.scrollTop=0;this._text='';this.listeners={};this.classList={add:(...names)=>{this.className=[...new Set([...this.className.split(' ').filter(Boolean),...names])].join(' ')},contains:name=>this.className.split(' ').includes(name)}}
  set textContent(value){this._text=String(value);this.children=[]}
  get textContent(){return this._text+this.children.map(x=>x.textContent).join(' ')}
  appendChild(child){this.children.push(child);child.parentElement=this;return child}
  replaceChildren(...children){this.children=[];this._text='';children.forEach(x=>this.appendChild(x))}
  setAttribute(key,value){this.attributes[key]=value}
  removeAttribute(key){delete this.attributes[key]}
  insertAdjacentElement(where,child){const siblings=this.parentElement.children;siblings.splice(siblings.indexOf(this)+1,0,child);child.parentElement=this.parentElement}
  querySelectorAll(selector){const found=[];const visit=el=>{for(const child of el.children){if((selector.startsWith('.')&&child.classList.contains(selector.slice(1)))||(selector==='details[data-post-disclosure]'&&child.tagName==='DETAILS'&&child.dataset.postDisclosure)||child.tagName.toLowerCase()===selector)found.push(child);visit(child)}};visit(this);return found}
  querySelector(selector){return this.querySelectorAll(selector)[0]||null}
  addEventListener(name,handler){this.listeners[name]=handler}
}
function harness({lazy=false}={}){
  const ids=new Map(),make=(id,tag)=>{const el=new Element(tag);el.id=id;ids.set(id,el);return el};
  const body=make('body'),zone=make('conversationZone'),head=make('head');head.className='conversationHead';body.appendChild(zone);zone.appendChild(head);
  const title=make('conversationTitle');head.appendChild(title);const load=make('conversationLoadStatus');zone.appendChild(load);const refreshStatus=make('conversationRefreshStatus');zone.appendChild(refreshStatus);
  const messages=make('messages');messages.scrollTop=240;zone.appendChild(messages);const draft=make('replyText');draft.value='Не потерять черновик';zone.appendChild(draft);
  const requests=[],observers=[],events={};let openGeneration=1,recoveries=0;
  const S={authExpired:false,authGeneration:1,manager:{id:7},current:101,detail:{conversation:{id:101,last_message_at:'2026-10-14 09:20:00'}},csrf:'synthetic-csrf-only'};
  const W={S,$:id=>ids.get(id),showAuthRecovery(){recoveries++;S.authGeneration++;S.authExpired=true}};
  // Execute the canonical JSON transport itself. The presentation receives only
  // W.request; this fixture supplies synthetic HTTP at that owner's boundary.
  const core=fs.readFileSync(path.join(__dirname,'../manager/assets/workspace-v2.js'),'utf8');
  const requestSource=core.slice(core.indexOf('async function request('),core.indexOf('const api='));
  W.request=vm.runInNewContext(requestSource+'\nrequest',{S,showAuthRecovery:W.showAuthRecovery,fetch:(url,options)=>new Promise(resolve=>requests.push({url,options,resolve}))});
  const window={addEventListener:(type,callback)=>{events[type]=callback},WorkspaceV2Conversation:{getOpenGeneration:()=>openGeneration}};
  if(!lazy)window.WorkspaceV2=W;
  class MutationObserver {constructor(callback){this.callback=callback;observers.push(this)}observe(){}}
  vm.runInNewContext(fs.readFileSync(path.join(__dirname,'../manager/assets/workspace-v2-post-context.js'),'utf8'),{
    window,document:{body,createElement:tag=>new Element(tag)},URL,Date,Intl,MutationObserver
  });
  const module=window.WorkspaceV2PostContext;
  return {S,W,window,module,body,zone,messages,draft,requests,load,refreshStatus,title,observers,
    get host(){return zone.children.find(el=>el.id==='conversationPostContext')},
    init(){window.WorkspaceV2=W;module.init()},
    nextGeneration(){openGeneration++},
    select(id){S.current=id;S.detail={conversation:{id,last_message_at:'2026-10-14 09:20:00'}};openGeneration++},
    signal(target){observers.forEach(observer=>observer.callback([{target,addedNodes:[]}]))},
    recoveries:()=>recoveries,
    resolve(index,body,status=200){requests[index].resolve({status,ok:status>=200&&status<300,json:async()=>body})},
    async settle(){await new Promise(resolve=>setImmediate(resolve))}
  };
}
function result(conversationId=101,text='Турция, 7 ночей. Цена 150 000 ₽ за двоих.',extra={}){
  return {ok:true,conversation_id:conversationId,context:{current:{entry_id:1,title:'Турция — октябрь',channel_name:'AnyTour Москва',platform:'max',post_url:'https://max.ru/c/-80095447388179/AaE53iyDMmw',text,entered_at:'2026-10-14 09:20:00',version_number:2,media_count:1,...extra},history:[]}};
}
const cases=[];const test=(name,run)=>cases.push({name,run});
test('module may load before canonical runtime and starts only after init',async()=>{const h=harness({lazy:true});assert.ok(h.module);assert.equal(h.module.init(),false);assert.equal(h.requests.length,0);h.init();assert.equal(h.requests.length,1);h.resolve(0,result());await h.settle();assert.match(h.host.textContent,/Обращение по посту/)});
test('missing canonical transport reports unavailable without another HTTP owner',async()=>{const h=harness();delete h.W.request;h.init();await h.settle();assert.equal(h.requests.length,0);assert.equal(h.host.dataset.state,'error');assert.match(h.host.textContent,/Не удалось загрузить исходный пост/)});
test('optional request is pinned to the authorized current client and carries canonical session CSRF',async()=>{const h=harness();h.init();const request=h.requests[0];assert.equal(request.options.credentials,'same-origin');assert.deepEqual(JSON.parse(request.options.body),{action:'detail',csrf:'synthetic-csrf-only',conversation_id:101});assert.equal(h.host.dataset.state,'loading');h.resolve(0,result());await h.settle();assert.equal(h.messages.scrollTop,240);assert.equal(h.draft.value,'Не потерять черновик');assert.match(h.host.textContent,/Условия и цены из публикации/);assert.match(h.host.textContent,/12:20 МСК/)});
test('same generation renders/refresh hooks share exactly one optional request',async()=>{const h=harness();h.init();void h.module.sync();void h.module.sync();assert.equal(h.requests.length,1);h.resolve(0,result());await h.settle();await h.module.sync();assert.equal(h.requests.length,1)});
test('a late first client cannot paint over a selected second client',async()=>{const h=harness();h.init();h.select(102);const pending=h.module.sync();h.resolve(1,result(102,'Только второй клиент'));await pending;h.resolve(0,result(101,'Секрет первого клиента'));await h.settle();assert.match(h.host.textContent,/Только второй клиент/);assert.doesNotMatch(h.host.textContent,/Секрет первого клиента/)});
test('a pending old epoch cannot overwrite the same client after a fresh open',async()=>{const h=harness();h.init();h.nextGeneration();const pending=h.module.sync();h.resolve(1,result(101,'Свежий контекст'));await pending;h.resolve(0,result(101,'Старая версия'));await h.settle();assert.match(h.host.textContent,/Свежий контекст/);assert.doesNotMatch(h.host.textContent,/Старая версия/)});
test('old-session success and 401 cannot leak context or sign out a new account',async()=>{for(const status of [200,401]){const h=harness();h.init();h.S.manager={id:8};h.S.authGeneration+=2;h.nextGeneration();const pending=h.module.sync();h.resolve(1,result(101,'Контекст нового менеджера'));await pending;h.resolve(0,result(101,'Секрет старой сессии'),status);await h.settle();assert.equal(h.recoveries(),0);assert.doesNotMatch(h.host.textContent,/Секрет старой сессии/)}});
test('current-session 401 clears the card and invokes existing auth recovery',async()=>{const h=harness();h.init();h.resolve(0,{ok:false,error:'unauthorized'},401);await h.settle();assert.equal(h.recoveries(),1);assert.equal(h.host.hidden,true);assert.equal(h.host.textContent,'')});
test('auth overlay invalidates the visible post before old responses finish',async()=>{const h=harness();h.init();h.resolve(0,result());await h.settle();h.S.authExpired=true;h.signal(h.body);assert.equal(h.host.hidden,true);assert.equal(h.host.textContent,'')});
test('failed or loading canonical selection clears a previously visible post',async()=>{for(const kind of ['loading','error']){const h=harness();h.init();h.resolve(0,result());await h.settle();h.load.className=kind;h.signal(h.load);assert.equal(h.host.hidden,true);assert.equal(h.host.textContent,'')}});
test('fresh polling ACL loss clears context even when canonical detail remains retained',async()=>{const h=harness();h.init();h.resolve(0,result());await h.settle();const retained=h.S.detail;h.refreshStatus.className='conversationLoadStatus error';h.refreshStatus.textContent='Доступ к диалогу изменился. Выберите доступный диалог в списке.';h.signal(h.refreshStatus);assert.equal(h.S.detail,retained);assert.equal(h.host.hidden,true);assert.equal(h.host.textContent,'');await h.module.sync();assert.equal(h.requests.length,1)});
test('pending post read cannot repaint retained detail after fresh ACL failure',async()=>{const h=harness();h.init();h.refreshStatus.className='conversationLoadStatus error';h.refreshStatus.textContent='Доступ к диалогу изменился.';h.signal(h.refreshStatus);h.resolve(0,result());await h.settle();assert.equal(h.host.hidden,true);assert.equal(h.host.textContent,'')});
test('storage, malformed and mismatched responses remain honest and hide older text',async()=>{for(const [body,status] of [[{ok:false,error:'post_context_unavailable'},503],[result(102),200],[{ok:true,conversation_id:101},200]]){const h=harness();h.init();h.resolve(0,result());await h.settle();h.nextGeneration();const pending=h.module.sync();assert.doesNotMatch(h.host.textContent,/150 000/);h.resolve(1,body,status);await pending;assert.equal(h.host.dataset.state,'error');assert.doesNotMatch(h.host.textContent,/150 000/);await h.module.sync();assert.equal(h.requests.length,2)}});
test('empty legacy attribution never reuses an earlier client post',async()=>{const h=harness();h.init();h.resolve(0,result());await h.settle();h.select(102);const pending=h.module.sync();h.resolve(1,{ok:true,conversation_id:102,context:{current:null,history:[]}});await pending;assert.equal(h.host.dataset.state,'empty');assert.match(h.host.textContent,/без ссылки/);assert.doesNotMatch(h.host.textContent,/Турция/) });
test('unavailable latest token does not misattribute the previous confirmed entry',async()=>{const h=harness();h.init();const r=result();r.context.history=[r.context.current];r.context.current={available:false};h.resolve(0,r);await h.settle();assert.match(h.host.textContent,/Исходный пост этого обращения недоступен/);assert.match(h.host.textContent,/Предыдущее обращение по посту/);assert.equal(h.host.querySelectorAll('.postContextCard').length,0)});
test('registry unavailable marker also keeps previous entries as history',async()=>{const h=harness();h.init();const r=result();r.context.history=[r.context.current];r.context.current={unavailable:true};h.resolve(0,r);await h.settle();assert.match(h.host.textContent,/Исходный пост этого обращения недоступен/);assert.equal(h.host.querySelectorAll('.postContextCard').length,0)});
test('same-client refresh preserves post disclosure and its scroll without touching composer or transcript',async()=>{const h=harness();h.init();h.resolve(0,result());await h.settle();const details=h.host.querySelectorAll('details[data-post-disclosure]')[0];details.open=true;details.querySelector('.postContextText').scrollTop=65;h.nextGeneration();const pending=h.module.sync();h.resolve(1,result());await pending;const restored=h.host.querySelectorAll('details[data-post-disclosure]')[0];assert.equal(restored.open,true);assert.equal(restored.querySelector('.postContextText').scrollTop,65);assert.equal(h.messages.scrollTop,240);assert.equal(h.draft.value,'Не потерять черновик')});
test('same conversation under another account cannot inherit disclosures',async()=>{const h=harness();h.init();h.resolve(0,result());await h.settle();h.host.querySelectorAll('details[data-post-disclosure]')[0].open=true;h.S.manager={id:8};h.S.authGeneration++;h.nextGeneration();const pending=h.module.sync();h.resolve(1,result());await pending;assert.equal(h.host.querySelectorAll('details[data-post-disclosure]')[0].open,false)});
test('post HTML remains literal text and arbitrary media URLs are never rendered',async()=>{const h=harness();h.init();h.resolve(0,result(101,'<img src=x onerror=alert(1)>',{media_url:'https://attacker.test/token',post_url:'javascript:alert(1)'}));await h.settle();assert.match(h.host.textContent,/<img src=x onerror=alert\(1\)>/);assert.equal(h.host.querySelectorAll('img').length,0);assert.equal(h.host.querySelectorAll('.postContextOriginal').length,0)});
test('only exact current-conversation protected media can render and photo failure stays contained',async()=>{const h=harness();h.init();const url='/manager/post-context-media.php?conversation_id=101&entry_id=1&attachment=0';h.resolve(0,result(101,'caption',{media_url:url}));await h.settle();const image=h.host.querySelector('img');assert.equal(image.src,url);assert.equal(image.loading,'lazy');image.listeners.error();assert.equal(h.host.querySelector('img'),null);assert.match(h.host.textContent,/Фото недоступно/);assert.equal(h.draft.value,'Не потерять черновик')});
test('media proxy cannot switch conversation, entry, attachment or absolute host',()=>{const h=harness(),safe=h.module.safeMediaUrl,url='/manager/post-context-media.php?conversation_id=101&entry_id=1&attachment=0';assert.equal(safe(url,101,1),url);for(const wrong of [url.replace('101','102'),url.replace('entry_id=1','entry_id=2'),url.replace('attachment=0','attachment=1'),'https://app.anytoour.ru'+url,url+'&token=secret'])assert.equal(safe(wrong,101,1),'')});
test('original-post URL validation rejects credentials, alternate domains, redirects and cross-provider paths',()=>{const h=harness();const safe=h.module.safePostUrl;assert.equal(safe('https://max.ru/c/-80095447388179/AaE53iyDMmw','max'),'https://max.ru/c/-80095447388179/AaE53iyDMmw');assert.equal(safe('https://t.me/AnyTourTest/42','telegram'),'https://t.me/AnyTourTest/42');for(const url of ['http://max.ru/c/1/mid','https://max.ru.evil.test/c/1/mid','https://user:password@max.ru/c/1/mid','https://max.ru:9443/c/1/mid','https://max.ru/c/1/mid?to=evil','https://max.ru/join/secret','https://t.me/AnyTourTest/42'])assert.equal(safe(url,'max'),'')});

(async()=>{let failed=0;for(const item of cases){try{await item.run();console.log('PASS '+item.name)}catch(error){failed++;console.error('FAIL '+item.name+'\n'+error.stack)}}console.log(`TOTAL ${cases.length} | FAIL ${failed}`);process.exitCode=failed?1:0})();
