(function(){
'use strict';
const W=window.WorkspaceV2,{S,$}=W,endpoint='promise-calculations-api.php',prefix='workspaceV2.promiseCalculation.v1.';
const records=new Map(),digests=new WeakMap(),taskReads=new WeakMap();
let sessionStamp='';
const hex=(value,n)=>typeof value==='string'&&new RegExp('^[a-f0-9]{'+n+'}$').test(value);
const positive=value=>Number.isSafeInteger(value)&&value>0;
const object=value=>!!value&&typeof value==='object'&&!Array.isArray(value);
const partStates=['pending','processing','unknown','confirmed','rejected','cancelled'];
const copy=value=>JSON.parse(JSON.stringify(value));
const context=()=>window.WorkspaceV2Conversation.captureReplyContext();
const key=c=>prefix+c.managerId+'.'+c.conversationId;
function current(c){const n=context();return !S.authExpired&&!S.logoutPending&&Object.keys(n).every(k=>n[k]===c[k])&&available()}
function available(){const c=S.detail?.conversation;return positive(Number(S.manager?.id))&&positive(Number(S.current))&&!S.authExpired&&!S.logoutPending&&Number(c?.id)===Number(S.current)&&Number(c?.manager_id)===Number(S.manager.id)&&c.status==='manager'&&c.project_key==='anytour'&&['max','telegram'].includes(c.channel)&&window.WorkspaceV2Conversation.hasReadAccess()}
function validComposition(v){return object(v)&&typeof v.text==='string'&&v.text.length<=20000&&Array.isArray(v.files)&&v.files.length<=10&&v.files.every(f=>object(f)&&hex(f.sha256,64)&&positive(f.size)&&typeof f.name==='string'&&f.name.length<=1000)}
function validPending(p,r){return object(p)&&['arm','cancel'].includes(p.action)&&hex(p.operation_id,32)&&hex(p.manifest_sha256,64)&&hex(p.request_sha256,64)&&object(p.input)&&p.input.operation_id===p.operation_id&&(p.action==='cancel'?hex(p.intent_id,32)&&p.input.intent_id===p.intent_id:p.input.task_id===r.task_id&&p.input.promise_id===r.promise_id&&hex(p.input.preview_sha256,64)&&validComposition(p.input.composition))}
function validIntent(i){
 return object(i)&&hex(i.intent_id,32)&&hex(i.promise_id,64)&&positive(i.task_id)&&['armed','completed','blocked','cancelled'].includes(i.state)&&hex(i.manifest_sha256,64)
  &&Array.isArray(i.parts)&&i.parts.length>=1&&i.parts.length<=10&&i.total===i.parts.length&&Number.isInteger(i.confirmed)&&i.confirmed>=0&&i.confirmed<=i.total
  &&i.parts.every((p,index)=>object(p)&&p.index===index&&partStates.includes(p.state)&&(p.operation_id===null||hex(p.operation_id,32))
   &&(p.kind==='text'&&i.parts.length===1&&hex(p.text_sha256,64)||p.kind==='media'&&hex(p.file_sha256,64)&&hex(p.caption_sha256,64)&&positive(p.size)&&typeof p.name==='string'&&p.name.length<=1000))
  &&i.confirmed===i.parts.filter(p=>p.state==='confirmed').length&&typeof i.can_recover==='boolean'&&positive(i.task_revision)&&[null,'open','done'].includes(i.task_status);
}
function validRecord(r,c){return object(r)&&r.schema===1&&r.actor===c.managerId&&r.conversation_id===c.conversationId&&positive(r.task_id)&&hex(r.promise_id,64)&&typeof r.title==='string'&&r.title.length<=255
 &&(r.intent==null||validIntent(r.intent)&&r.intent.promise_id===r.promise_id&&r.intent.task_id===r.task_id)
 &&(r.history==null||validIntent(r.history)&&r.history.state==='completed'&&r.history.task_id===r.task_id&&r.history.promise_id===r.promise_id)
 &&(r.pending==null||validPending(r.pending,r))}
function record(c=context()){
 const k=key(c);if(records.has(k))return records.get(k);
 let r=null;try{const raw=window.sessionStorage.getItem(k);if(raw!==null){if(raw.length>50000)throw Error('invalid');r=JSON.parse(raw);if(!validRecord(r,c))throw Error('invalid');r.verified=false}}
 catch(e){r={corrupt:true,notice:'Сохранённую связь с расчётом не удалось прочитать. Отправка остановлена; проверьте сохранённую попытку.'}}
 records.set(k,r);return r;
}
function persist(r,c=context()){
 try{if(r===null){window.sessionStorage.removeItem(key(c));records.set(key(c),null);return true}
  const value={schema:1,actor:c.managerId,conversation_id:c.conversationId,task_id:r.task_id,promise_id:r.promise_id,title:r.title,intent:r.intent||null,history:r.history||null,pending:r.pending||null};
  if(!validRecord(value,c))throw Error('invalid');window.sessionStorage.setItem(key(c),JSON.stringify(value));records.set(key(c),r);r.storageFailed=false;return true;
 }catch(e){if(r){records.set(key(c),r);r.storageFailed=true;r.notice='Связь не удалось сохранить в этой вкладке. Отправка остановлена; текст и файлы сохранены.'}return false}
}
function operationId(){const bytes=new Uint8Array(16);window.crypto.getRandomValues(bytes);return [...bytes].map(b=>b.toString(16).padStart(2,'0')).join('')}
async function request(action,body,c){
 const controller=new AbortController(),timeout=setTimeout(()=>controller.abort(),20000);
 try{const value=await W.request(endpoint,action,{conversation_id:c.conversationId,expected_manager_id:c.managerId,...body},{signal:controller.signal,isCurrent:()=>!S.authExpired&&!S.logoutPending&&Number(S.manager?.id)===c.managerId&&S.authGeneration===c.authGeneration});
  if(!object(value))throw Error('invalid_response');if(value.ok===true&&(value.viewer_id!==c.managerId||value.conversation_id!==c.conversationId))throw Error('scope_mismatch');return value;
 }finally{clearTimeout(timeout)}
}
const definiteRefusals=['actor_changed','forbidden','not_found','invalid_input','invalid_composition','empty_composition','unsupported_channel','preview_changed','context_changed','profile_changed','source_changed','source_unavailable','task_missing','task_closed','task_changed','scope_changed','intent_conflict','part_unresolved','profile_required','profile_source_changed'];
function rejected(value){return value?.ok===false&&value.http_status>=400&&value.http_status<500&&definiteRefusals.includes(value.error)}
const reasons={profile_required:'Сначала проверьте условия поездки.',profile_source_changed:'Основание условий поездки изменилось. Проверьте поездку.',source_unavailable:'Исходное обещание сейчас недоступно.',source_changed:'Исходное обещание изменилось. Проверьте связь с задачей.',profile_changed:'Условия поездки изменились. Проверьте расчёт.',context_changed:'В переписке появились изменения. Проверьте расчёт ещё раз.',task_closed:'Задача уже завершена.',task_changed:'Задача изменилась. Проверьте её перед отправкой.',task_missing:'Задача сейчас недоступна.',scope_changed:'Связь с задачей изменилась. Проверьте её.',preview_changed:'Проверенные условия изменились. Состав нужно проверить заново.',intent_conflict:'Для задачи уже закреплён расчёт. Проверьте его состояние.',already_completed:'Эта задача уже завершалась после отправки расчёта. Повторно она не закроется.',forbidden:'Доступ к задаче изменился.',actor_changed:'Менеджер изменился. Откройте диалог заново.',unsupported_channel:'В этом канале привязка расчёта недоступна.',storage_unavailable:'Состояние связи сейчас недоступно. Отправка остановлена.'};
function refusal(value){return reasons[value?.reason||value?.error]||'Не удалось проверить связь с задачей. Отправка остановлена; текст и файлы сохранены.'}
function notice(r,text){r.notice=text;render();return {allow:false}}
function element(tag,className,text){const node=document.createElement(tag);if(className)node.className=className;if(text!==undefined)node.textContent=text;return node}
function button(id,text,action,disabled){const b=element('button','actionBtn',text);b.id=id;b.type='button';b.disabled=!!disabled;b.onclick=action;return b}
function completionMessage(i){return i.task_status==='done'?'Расчёт отправлен. Задача выполнена.':i.task_status==='open'?'Предыдущий расчёт отправлен. Задача сейчас открыта.':'Расчёт отправлялся с подтверждённым завершением задачи. Текущее состояние задачи не подтверждено.'}
function bar(){let box=$('promiseCalculation');const host=$('composer');if(!box&&host){box=element('section','promiseCalculation');box.id='promiseCalculation';box.setAttribute('aria-label','Расчёт для задачи');host.insertBefore(box,host.firstChild)}return box}
function syncSession(){
 const stamp=JSON.stringify([Number(S.manager?.id),S.authGeneration,!!S.authExpired,!!S.logoutPending]);
 if(stamp!==sessionStamp){for(const r of records.values())if(r){r.preview=null;r.verified=false;r.notice='';r.refreshRequested=false}sessionStamp=stamp}
}
function render(){
 syncSession();
 const box=bar();if(!box)return;const r=available()?record():null;box.hidden=!r;if(!r){box.replaceChildren();return}
 // Only this small panel is replaced. Textarea, native File objects and task
 // create/edit fields remain under their existing canonical owners.
 const wasOpen=box.querySelector('details')?.open;box.replaceChildren();
 box.appendChild(element('strong','promiseCalculationTitle',r.corrupt?'Проверьте связь с расчётом':'Расчёт для задачи «'+r.title+'»'));
 if(r.history)box.appendChild(element('div','promiseCalculationHistory','Предыдущий расчёт подтверждён. Сейчас готовится новый расчёт по возвращённой в работу задаче.'));
 const i=r.intent,completed=i?.state==='completed'&&r.verified;
 const message=r.notice||(r.pending?'Сохранение связи не подтверждено. Проверьте эту операцию; отправка остановлена.':!r.verified&&i?'Проверьте сохранённую связь перед отправкой.':completed?completionMessage(i):i?.state==='armed'?'Подтверждена отправка '+i.confirmed+' из '+i.total+' частей расчёта.'+(i.parts.some(p=>['processing','unknown'].includes(p.state))?' Подтверждение неполное. Проверьте результат отправки.':''):i&&i.state!=='armed'?'Автоматическое выполнение '+(i.state==='cancelled'?'отменено.':'остановлено.'):'Подготовьте текст или прикрепите файл здесь. «Отправить» сначала покажет состав для проверки.');
 const status=element('div','promiseCalculationStatus',message);status.id='promiseCalculationStatus';status.setAttribute('role','status');status.setAttribute('aria-live','polite');box.appendChild(status);
 if(r.preview){
  const p=r.preview.value,details=element('details','promiseCalculationReview');details.open=wasOpen!==false;details.appendChild(element('summary','','Проверенный состав'+(p.composition.files.length?' (файлов: '+p.composition.files.length+')':': сообщение')));
  const body=element('div','promiseCalculationReviewBody');
  body.appendChild(element('p','','Задача: '+String(p.task.title||r.title)));
  if(p.source?.text)body.appendChild(element('blockquote','',p.source.text));
  if(p.profile?.label)body.appendChild(element('p','',p.profile.label));
  if(object(p.profile?.fields))for(const [name,value]of Object.entries(p.profile.fields)){const text=object(value)?value.value:value;if(typeof text==='string'||typeof text==='number')body.appendChild(element('p','',(p.profile.field_labels?.[name]||name)+': '+text))}
  if(p.profile?.status==='needs_review')body.appendChild(element('strong','promiseCalculationWarning','Условия поездки требуют проверки. Сверьте расчёт перед отправкой.'));
  if(p.composition.text)body.appendChild(element('pre','promiseCalculationText',p.composition.text));
  if(p.composition.files.length){const list=element('ol','promiseCalculationFiles');for(const f of p.composition.files)list.appendChild(element('li','',f.name+' · '+(f.size/1024).toFixed(1)+' КБ'));body.appendChild(list);body.appendChild(element('p','','Файлы отправятся в этом порядке. Текст — подпись к первому.'))}
  details.appendChild(body);box.appendChild(details);
 }
 if(r.corrupt)return;
 const actions=element('div','promiseCalculationActions');
 if(r.pending){actions.appendChild(button('promiseCalculationCheck','Проверить связь',()=>checkOperation(),r.busy));if(r.absent)actions.appendChild(button('promiseCalculationRetry',r.pending.action==='arm'?'Повторить сохранение':'Повторить отмену',()=>retryOperation(),r.busy))}
 else if(i){actions.appendChild(button('promiseCalculationCheck',i.can_recover?'Проверить выполнение задачи':'Проверить состояние',()=>checkIntent(!!i.can_recover),r.busy));
  if(i.state==='armed'||i.state==='blocked')actions.appendChild(button('promiseCalculationChange','Изменить состав',()=>detach(true),r.busy));}
 if(!r.pending)actions.appendChild(button('promiseCalculationRemove','Снять связь',()=>detach(false),r.busy));
 box.appendChild(actions);
}
function syncContext(){const r=available()?record():null;if(r?.preview&&!r.busy&&!matches(r.preview.snapshot)){r.preview=null;r.notice='Состав изменился. «Отправить» покажет его для повторной проверки.'}render()}
async function sha(bytes){return [...new Uint8Array(await window.crypto.subtle.digest('SHA-256',bytes))].map(b=>b.toString(16).padStart(2,'0')).join('')}
const hash=value=>sha(new TextEncoder().encode(JSON.stringify(value)));
const commandHash=(action,cid,input)=>hash(action==='arm'?['arm',cid,input.operation_id,input.task_id,input.promise_id,input.composition,input.preview_sha256]:['cancel',cid,input.operation_id,input.intent_id]);
function canonicalName(name){const base=String(name).replace(/\/+$/,'').split('/').pop();return base.replace(/^[ \t\n\r\0\x0B]+|[ \t\n\r\0\x0B]+$/g,'').replace(/[\x00-\x1F\x7F]+/g,'')||'attachment'}
function fileDigest(file){if(!digests.has(file))digests.set(file,file.arrayBuffer().then(sha));return digests.get(file)}
function capture(){const media=window.WorkspaceV2Media.captureSelection();if(!media)return null;return {context:context(),text:String($('replyText').value),media}}
function matches(snapshot){return !!snapshot&&current(snapshot.context)&&snapshot.text===$('replyText').value&&window.WorkspaceV2Media.matchesSelection(snapshot.media)}
async function composition(snapshot){
 const rows=snapshot.media.rows;if(rows.length>10||rows.some(({row})=>row.status==='unknown'))throw Error('delivery_unknown');
 const files=await Promise.all(rows.map(async({row})=>({sha256:await fileDigest(row.file),size:row.file.size,name:canonicalName(row.file.name)})));
 const text=snapshot.text.trim();if(!text&&!files.length)throw Error('empty');
 if(text.length>20000)throw Error('length');return {text,files};
}
async function expectedParts(value){
 if(!value.files.length)return [{index:0,kind:'text',text_sha256:await sha(new TextEncoder().encode(value.text))}];
 const caption=await sha(new TextEncoder().encode(value.text)),empty=await sha(new TextEncoder().encode(''));
 return value.files.map((f,index)=>({index,kind:'media',file_sha256:f.sha256,caption_sha256:index===0?caption:empty,size:f.size,name:f.name}));
}
function sameParts(actual,expected){return Array.isArray(actual)&&actual.length===expected.length&&expected.every((p,index)=>object(actual[index])&&Object.keys(p).every(k=>actual[index][k]===p[k]))}
function sameComposition(actual,expected){return object(actual)&&actual.text===expected.text&&Array.isArray(actual.files)&&actual.files.length===expected.files.length&&expected.files.every((f,index)=>object(actual.files[index])&&actual.files[index].sha256===f.sha256&&actual.files[index].size===f.size&&actual.files[index].name===f.name)}
function matchesIntent(r,i,manifest){return validIntent(i)&&i.task_id===r.task_id&&i.promise_id===r.promise_id&&(!manifest||i.manifest_sha256===manifest)}
function acceptIntent(r,i,c){r.intent=copy(i);r.verified=true;r.pending=null;r.absent=false;r.preview=null;r.notice='';return persist(r,c)}
async function checkIntent(recover=false,refreshTasks=true){
 const c=context(),r=record(c);if(!available()||!r?.intent||r.pending||r.busy)return false;r.busy=true;r.notice='Проверяем состояние расчёта…';render();
 try{const result=await request(recover?'recover':'status',{intent_id:r.intent.intent_id},c);if(!current(c))return false;
  if(result.ok!==true||!matchesIntent(r,result.intent,r.intent.manifest_sha256)||result.intent.intent_id!==r.intent.intent_id){r.notice=refusal(result);return false}
  const accepted=acceptIntent(r,result.intent,c);
  if(accepted&&refreshTasks&&result.intent.state==='completed')void Promise.resolve(window.WorkspaceV2Conversation.refreshLeadData({conversationId:c.conversationId,protectTaskDraft:true})).catch(()=>{});
  return accepted;
 }catch(e){if(current(c))r.notice='Состояние расчёта не подтверждено. Ничего не отправлялось повторно.';return false}
 finally{r.busy=false;if(available()&&record()===r){render();if(r.refreshRequested){r.refreshRequested=false;void checkIntent(false,false)}}}
}
async function checkOperation(){
 const c=context(),r=record(c);if(!available()||!r?.pending||r.busy)return false;const pending=copy(r.pending);r.busy=true;r.notice='Проверяем сохранение связи…';render();
 try{const result=await request('operation',{operation_id:pending.operation_id},c);if(!current(c))return false;
  if(result.ok===true&&result.found===false&&result.intent===null&&result.action===null&&result.request_sha256===null){r.absent=true;r.notice='Сохранение пока не найдено. Можно явно повторить ту же сохранённую команду. Сообщение и файлы при этом не отправляются.';return false}
  if(result.ok!==true||result.found!==true||result.action!==pending.action||result.request_sha256!==pending.request_sha256||!matchesIntent(r,result.intent,pending.manifest_sha256)||pending.action==='cancel'&&(result.intent.intent_id!==pending.intent_id||!['cancelled','completed','blocked'].includes(result.intent.state))){r.notice='Связь пока не подтверждена. Проверьте эту же операцию; отправка остановлена.';return false}
  return acceptIntent(r,result.intent,c);
 }catch(e){if(current(c))r.notice='Связь пока не подтверждена. Проверьте эту же операцию позже.';return false}
 finally{r.busy=false;if(current(c))render()}
}
async function retryOperation(){
 const c=context(),r=record(c);if(!available()||!r?.pending||!r.absent||r.busy)return false;
 const pending=copy(r.pending);r.busy=true;r.absent=false;r.notice='Повторяем сохранение связи. Сообщение и файлы не отправляются.';render();
 try{
  if(!validPending(pending,r)||await commandHash(pending.action,c.conversationId,pending.input)!==pending.request_sha256||pending.action==='arm'&&await hash(pending.input.composition)!==pending.manifest_sha256){r.notice='Сохранённая команда не прошла проверку. Новая операция и отправка не начаты.';return false}
  if(!current(c))return false;
  const result=await request(pending.action,pending.input,c);
  if(result.ok!==true){if(rejected(result)){r.pending=null;r.preview=null;persist(r,c)}r.notice=refusal(result);return false}
  if(!matchesIntent(r,result.intent,pending.manifest_sha256)||pending.action==='cancel'&&result.intent.intent_id!==pending.intent_id){r.notice='Результат той же команды не подтверждён. Отправка остановлена.';return false}
  const accepted=acceptIntent(r,result.intent,c);r.notice='Сохранение подтверждено. Проверьте текущий состав перед отдельным явным нажатием «Отправить».';return accepted;
 }catch(e){if(current(c))r.notice='Сохранение снова не подтверждено. Проверьте эту же операцию; отправка остановлена.';return false}
 finally{r.busy=false;if(current(c))render()}
}
async function detach(keepTask=false){
 const c=context(),r=record(c);if(!available()||!r||r.corrupt||r.pending||r.busy)return false;
 const i=r.intent;
 // A missing calculation proof is not an unresolved ordinary send. Disarming
 // automation is safe after that exact send is terminal; real send fences stay.
 if(window.WorkspaceV2Conversation.pendingCalculation?.(i?.intent_id)||window.WorkspaceV2Media.hasPendingCalculation?.(i?.intent_id)){notice(r,'Сначала проверьте результат начатой отправки. Её связь нельзя убрать до подтверждения.');return false}
 r.busy=true;render();
 try{
  if(i&&!['cancelled','completed'].includes(i.state)){
   const input={operation_id:operationId(),intent_id:i.intent_id};r.pending={action:'cancel',operation_id:input.operation_id,intent_id:i.intent_id,manifest_sha256:i.manifest_sha256,input,request_sha256:await commandHash('cancel',c.conversationId,input)};if(!current(c)||!persist(r,c))return false;
   const result=await request('cancel',input,c);
   if(result.ok!==true||!matchesIntent(r,result.intent,i.manifest_sha256)||result.intent.intent_id!==i.intent_id||!['cancelled','completed','blocked'].includes(result.intent.state)){r.notice=refusal(result);return false}
   if(!acceptIntent(r,result.intent,c))return false;
  }
  if(!current(c))return false;
  if(i&&!await window.WorkspaceV2Media.unbindCalculation(i.intent_id)){notice(r,'Связь отменена, но вложения пока не сохранены. Отправка остановлена.');return false}
  if(!current(c))return false;
  if(keepTask){r.intent=null;r.verified=true;r.preview=null;r.notice='Подготовьте новый состав. «Отправить» покажет его для повторной проверки.';return persist(r,c)}
  return persist(null,c);
 }catch(e){if(current(c))r.notice='Отмена связи не подтверждена. Проверьте эту операцию; отправка остановлена.';return false}
 finally{r.busy=false;if(current(c))render()}
}
async function select(entry,{newCalculation=false}={}){
 syncSession();const c=context();if(!available()||!positive(entry?.task_id)||!hex(entry?.promise_id,64)||entry.kind!=='send_calculation'||entry.task?.id!==entry.task_id||entry.task.conversation_id!==c.conversationId)return false;
 if(newCalculation&&(entry.can_arm!==true||entry.intent?.state!=='completed'||entry.task.status!=='open'))return false;
 let r=record(c);if(r&&(r.corrupt||r.pending||r.busy||r.intent&&r.task_id!==entry.task_id||newCalculation&&r.intent&&r.intent.state!=='completed')){if(!r.corrupt)notice(r,'Сначала проверьте или снимите текущую связь с расчётом.');return false}
 if(r&&r.task_id===entry.task_id&&!newCalculation){render();return true}
 r={schema:1,actor:c.managerId,conversation_id:c.conversationId,task_id:entry.task_id,promise_id:entry.promise_id,title:String(entry.task.title||'Отправить расчёт'),intent:newCalculation?null:entry.intent||null,history:newCalculation?entry.intent:null,verified:true,notice:entry.can_arm===false&&!entry.intent?refusal(entry):''};
 if(r.intent&&!matchesIntent(r,r.intent))return false;if(!persist(r,c)){render();return false}
 render();const mobile=window.WorkspaceV2Mobile;if(mobile?.isMobile()&&mobile.getScreen?.()==='lead')mobile.back();else{window.Manager2Presentation?.closeClientCard?.();$('replyText')?.focus?.({preventScroll:true})}return true;
}
async function renderTasks(root,{tasks=[],canEdit=false,conversationId=0}){
 if(!canEdit||!tasks.some(task=>task.status==='open')||!available()||Number(conversationId)!==Number(S.current))return;
 const c=context(),ticket={};taskReads.set(root,ticket);
 try{const result=await request('catalog',{},c);if(!current(c)||taskReads.get(root)!==ticket||!root.isConnected||result.ok!==true||result.can_edit!==true||!Array.isArray(result.entries)||result.entries.length>200)return;
  for(const entry of result.entries){const task=tasks.find(t=>t.id===entry.task_id);if(!task||task.status!=='open'||entry.kind!=='send_calculation'||entry.task?.id!==task.id||entry.task.status!=='open'||!hex(entry.promise_id,64)||entry.intent&&!validIntent(entry.intent))continue;
   const toggle=root.querySelector('[data-task-toggle="'+task.id+'"]'),row=toggle?.closest('.taskRow'),host=row?.querySelector('.taskBody');if(!host||host.querySelector('[data-calculation-task]'))continue;
   const valid=()=>current(c)&&taskReads.get(root)===ticket&&root.isConnected;
   const action=button('',entry.intent?'Состояние расчёта':'Отправить расчёт',()=>valid()&&select(entry),entry.can_arm!==true&&!entry.intent);action.dataset.calculationTask=String(task.id);action.className='actionBtn promiseCalculationTask';if(action.disabled)action.title=refusal(entry);host.appendChild(action);
   if(entry.intent?.state==='completed'&&entry.can_arm===true){const fresh=button('','Отправить новый расчёт',()=>valid()&&select(entry,{newCalculation:true}));fresh.dataset.calculationNew=String(task.id);fresh.className='actionBtn promiseCalculationTask';host.appendChild(fresh)}
  }
 }catch(e){/* An optional read never replaces task forms or their drafts. */}
}
async function armedDispatch(r,snapshot,value){
 const i=r.intent;if(i.state!=='armed')return notice(r,i.state==='completed'?completionMessage(i):'Автоматическое выполнение остановлено. Снимите связь или проверьте её состояние.');
 if(i.parts.some(p=>['processing','unknown'].includes(p.state)))return notice(r,'Сначала проверьте результат начатой отправки. Повторная отправка заблокирована.');
 if(!value.files.length){
  const expected=await expectedParts(value);if(!matches(snapshot)||!sameParts(i.parts,expected)||i.parts[0].state==='confirmed')return notice(r,'Состав изменился. Нажмите «Изменить состав», затем проверьте новый расчёт.');
  return {allow:true,link:{calculation_intent_id:i.intent_id,calculation_part_index:0},matches:()=>matches(snapshot)};
 }
 // A lost arm response can be recovered before the original File rows were
 // tagged. Only the entire unchanged, wholly unstarted manifest may be rebound.
 if(snapshot.media.rows.every(({row})=>row.calculationIntentId===undefined&&row.calculationPartIndex===undefined)&&i.parts.every(p=>p.state==='pending'&&p.operation_id===null)){
  const full=await expectedParts(value);if(!matches(snapshot)||!sameParts(i.parts,full)||!await window.WorkspaceV2Media.bindCalculation(snapshot.media,i.intent_id))return notice(r,'Состав не совпал с сохранённым расчётом. Измените состав явно и проверьте его заново.');
  snapshot=capture();if(!snapshot)return {allow:false};
 }
 const rows=snapshot.media.rows,indices=rows.map(({row})=>row.calculationPartIndex);
 if(new Set(indices).size!==indices.length||rows.some(({row})=>row.calculationIntentId!==i.intent_id)||indices.some((index,pos)=>!Number.isInteger(index)||index<0||index>9||pos>0&&index<=indices[pos-1]))return notice(r,'Состав файлов изменился. Нажмите «Изменить состав», затем проверьте новый расчёт.');
 const caption=await sha(new TextEncoder().encode(value.text)),empty=await sha(new TextEncoder().encode(''));
 const expected=i.parts.filter(p=>p.state!=='confirmed');
 if(expected.length!==rows.length||rows.some(({row},pos)=>{const p=expected[pos],f=value.files[pos];return p?.kind!=='media'||p.index!==row.calculationPartIndex||p.file_sha256!==f.sha256||p.size!==f.size||p.name!==f.name||p.caption_sha256!==(pos===0?caption:empty)})||!matches(snapshot))return notice(r,'Состав изменился. Сохранённые файлы и подпись должны совпадать с проверенным расчётом. Для нового расчёта нажмите «Изменить состав».');
 return {allow:true,media:{intentId:i.intent_id,snapshot:snapshot.media},matches:()=>matches(snapshot)};
}
async function beforeSend(){
 syncSession();const c=context();let r=record(c);const snapshot=capture();
 // Restored File rows retain their link even if this panel's local record was
 // removed. They can never fall through to the ordinary unbound send path.
 const linked=snapshot?.media.rows.some(({row})=>row.calculationIntentId!=null||row.calculationPartIndex!=null);
 if(!r&&linked){
  const ids=new Set(snapshot.media.rows.map(({row})=>row.calculationIntentId));if(ids.size!==1||!hex([...ids][0],32))return {allow:false};
  try{const result=await request('status',{intent_id:[...ids][0]},c);if(!current(c)||result.ok!==true||!validIntent(result.intent))return {allow:false};
   r={schema:1,actor:c.managerId,conversation_id:c.conversationId,task_id:result.intent.task_id,promise_id:result.intent.promise_id,title:'Отправить расчёт',intent:result.intent,verified:true,notice:'Связь с вложениями восстановлена. Проверьте её перед следующей явной отправкой.'};persist(r,c);render();
  }catch(e){}return {allow:false};
 }
 if(!r)return {allow:true};
 if(!available()||r.corrupt||r.busy)return {allow:false};
 if(r.pending){await checkOperation();return {allow:false}}
 if(r.intent&&!r.verified){await checkIntent();return {allow:false}}
 if(!snapshot)return notice(r,'Дождитесь восстановления вложений. Текст и файлы сохранены.');
 r.busy=true;r.notice='Проверяем состав расчёта…';render();
 try{
  const value=await composition(snapshot);if(!matches(snapshot))return notice(r,'Состав изменился во время проверки. Нажмите «Отправить», чтобы проверить его заново.');
  if(r.intent)return await armedDispatch(r,snapshot,value);
  const previous=r.preview;
  if(!previous||!matches(previous.snapshot)||!sameComposition(previous.value.composition,value)){
   r.preview=null;const expected=await expectedParts(value);if(!matches(snapshot))return notice(r,'Состав изменился. Проверьте его ещё раз.');
   const result=await request('preview',{task_id:r.task_id,promise_id:r.promise_id,composition:value},c);
   if(!matches(snapshot))return notice(r,'Состав или диалог изменились. Проверьте расчёт ещё раз.');
   if(result.ok!==true||result.can_arm!==true)return notice(r,refusal(result));
   if(!hex(result.preview_sha256,64)||result.manifest_sha256!==await hash(value)||result.task?.id!==r.task_id||result.promise?.promise_id!==r.promise_id||result.promise.kind!=='send_calculation'||!sameComposition(result.composition,value)||!sameParts(result.parts,expected)||!matches(snapshot))return notice(r,'Состав ответа не совпал с расчётом. Отправка остановлена; проверьте его ещё раз.');
   r.preview={snapshot,value:result};return notice(r,'Состав проверен. Сверьте текст и файлы ниже; нажмите обычную «Отправить» ещё раз, чтобы отправить этот расчёт.');
  }
  const input={operation_id:operationId(),task_id:r.task_id,promise_id:r.promise_id,composition:value,preview_sha256:previous.value.preview_sha256};
  r.pending={action:'arm',operation_id:input.operation_id,manifest_sha256:previous.value.manifest_sha256,input,request_sha256:await commandHash('arm',c.conversationId,input)};if(!matches(snapshot)){r.pending=null;return {allow:false}}if(!persist(r,c))return {allow:false};r.notice='Сохраняем связь с задачей…';render();
  const result=await request('arm',input,c);
  if(result.ok!==true){if(rejected(result)){r.pending=null;r.preview=null;persist(r,c)}r.notice=refusal(result);return {allow:false}}
  if(!matchesIntent(r,result.intent,previous.value.manifest_sha256)||!sameParts(result.intent.parts,previous.value.parts)){r.notice='Связь не подтверждена. Проверьте эту же операцию; отправка остановлена.';return {allow:false}}
  if(!acceptIntent(r,result.intent,c)||!matches(snapshot))return {allow:false};
  if(value.files.length&&!await window.WorkspaceV2Media.bindCalculation(snapshot.media,result.intent.intent_id))return notice(r,'Связь сохранена, но состав изменился или вложения не сохранены. Отправка остановлена.');
  if(!current(c)||snapshot.text!==$('replyText').value)return {allow:false};
  return await armedDispatch(r,capture(),value);
 }catch(e){return notice(r,e.message==='empty'?'Подготовьте текст или прикрепите файл в обычном редакторе.':e.message==='length'?'Сократите текст до 20 000 знаков.':e.message==='delivery_unknown'?'Сначала проверьте результат отправки файла. Повтор заблокирован.':r.pending?'Связь не подтверждена. Проверьте эту же операцию; отправка остановлена.':'Не удалось проверить состав. Отправка остановлена; текст и файлы сохранены.')}
 finally{r.busy=false;if(current(c))render()}
}
function nativeResult(link,state,actor,cid){
 if(!hex(link?.calculation_intent_id,32)||Number(S.manager?.id)!==actor||Number(S.current)!==cid||!available())return;
 const r=record();if(r?.intent?.intent_id!==link.calculation_intent_id)return;
 r.preview=null;if(!['confirmed','rejected','cancelled'].includes(state)){r.notice='Отправка расчёта и выполнение задачи пока не подтверждены. Проверьте результат этой попытки.';render();return}
 if(r.busy){r.refreshRequested=true;return}void checkIntent(false,false);
}
function afterSend(intentId,actor,cid){if(!hex(intentId,32)||Number(S.manager?.id)!==actor||Number(S.current)!==cid||!available())return;const r=record();if(r?.intent?.intent_id!==intentId)return;if(r.busy){r.refreshRequested=true;return}void checkIntent(false,false)}
window.WorkspaceV2PromiseCalculation={renderTasks,select,beforeSend,syncContext,checkIntent,checkOperation,retryOperation,detach,nativeResult,afterSend};
})();
