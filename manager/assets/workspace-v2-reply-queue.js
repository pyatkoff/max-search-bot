(function(){
'use strict';
const W=window.WorkspaceV2,{S,$}=W;
const storagePrefix='workspaceV2.replyQueue.pending.v1.';
let owner=0,generation=0,visibleId=0;
const pending=new Map(),latest=new Map(),issues=new Map();
function authenticated(){return owner>0&&!S.authExpired&&Number(S.manager?.id)===owner&&Number(S.authGeneration||0)===generation}
function context(){return window.WorkspaceV2Conversation?.captureReplyContext?.()}
function sameSession(c){return authenticated()&&c?.managerId===owner&&c.authGeneration===generation}
function current(c){const now=context();return sameSession(c)&&now&&Object.keys(now).every(k=>now[k]===c[k])&&visibleId===c.conversationId&&Number(S.detail?.conversation?.id)===c.conversationId&&window.WorkspaceV2Conversation?.hasReadAccess?.()!==false&&window.WorkspaceV2Conversation?.canNavigateMessages?.()!==false}
function validState(q){return q?.schema===1&&typeof q.context==='string'&&/^[a-f0-9]{64}$/.test(q.context)&&Number.isSafeInteger(q.version)&&q.version>=0&&typeof q.needed==='boolean'&&Number.isSafeInteger(q.customer_message_id)&&q.customer_message_id>=0&&Number.isSafeInteger(q.request_event_id)&&q.request_event_id>=0&&typeof q.can_resolve==='boolean'&&typeof q.can_undo==='boolean'}
function validCommand(p){const x=p?.input;return ['resolve','undo'].includes(p?.kind)&&x&&Number.isSafeInteger(x.conversation_id)&&x.conversation_id>0&&typeof x.operation_id==='string'&&/^[a-f0-9]{32}$/.test(x.operation_id)&&typeof x.context==='string'&&/^[a-f0-9]{64}$/.test(x.context)&&Number.isSafeInteger(x.expected_version)&&x.expected_version>=0&&(p.kind==='resolve'?Number.isSafeInteger(x.through_message_id)&&x.through_message_id>=0&&Number.isSafeInteger(x.through_request_event_id)&&x.through_request_event_id>=0:Number.isSafeInteger(x.resolution_actor_id)&&x.resolution_actor_id>0&&typeof x.resolution_operation_id==='string'&&/^[A-Za-z0-9_-]{16,96}$/.test(x.resolution_operation_id))}
function persist(){try{if(pending.size)localStorage.setItem(storagePrefix+owner,JSON.stringify([...pending.values()].map(({kind,input})=>({kind,input}))));else localStorage.removeItem(storagePrefix+owner);return true}catch(e){return false}}
function suspend(){visibleId=0;const button=$('replyQueueResolve'),box=$('replyQueueStatus');if(button)button.hidden=true;if(box)box.hidden=true}
function activate(managerId){const next=Number(managerId||0);if(next===owner&&authenticated())return;suspend();pending.clear();latest.clear();issues.clear();owner=next;generation=Number(S.authGeneration||0);if(!owner)return;try{const raw=localStorage.getItem(storagePrefix+owner);if(raw&&raw.length<=1000000){const entries=JSON.parse(raw);if(Array.isArray(entries))for(const p of entries)if(validCommand(p))pending.set(p.input.conversation_id,{...p,busy:false,notFound:false})}}catch(e){}}
function bind(){
 let button=$('replyQueueResolve');
 if(!button){const host=document.querySelector('.conversationHead .actions');if(!host)return;button=document.createElement('button');button.id='replyQueueResolve';button.type='button';button.className='actionBtn';button.textContent='Ответ не требуется';button.title='Отметить текущие сообщения: ответ не требуется';button.hidden=true;button.onclick=()=>mutate('resolve');host.appendChild(button)}
 window.Manager2Presentation?.registerConversationTool?.(button);
 if(!$('replyQueueStatus')){const box=document.createElement('div');box.id='replyQueueStatus';box.className='replyQueueStatus';box.hidden=true;box.setAttribute('role','status');box.setAttribute('aria-live','polite');const text=document.createElement('span');text.id='replyQueueText';box.appendChild(text);document.querySelector('.conversationHead')?.insertAdjacentElement('afterend',box)}
}
function time(unix){if(!Number.isSafeInteger(unix)||unix<=0)return '';return window.ManagerTime?.format(new Date(unix*1000),{year:true})||''}
function wait(q){return typeof q.wait_age_seconds==='number'&&Number.isFinite(q.wait_age_seconds)&&q.wait_age_seconds>=0?W.formatWait(q.wait_age_seconds):'время ожидания неизвестно'}
function addButton(id,text,fn,disabled=false){let b=$(id);if(!b){b=document.createElement('button');b.id=id;b.type='button';b.className='actionBtn';$('replyQueueStatus').appendChild(b)}b.textContent=text;b.onclick=fn;b.disabled=disabled;b.hidden=false;return b}
function render(){
 bind();const c=context(),q=S.detail?.reply_queue,available=current(c)&&validState(q),button=$('replyQueueResolve'),box=$('replyQueueStatus');if(!button||!box)return;
 const id=c?.conversationId,p=pending.get(id),issue=issues.get(id);button.hidden=!available||!q.can_resolve;button.disabled=!!p;
 for(const name of ['replyQueueUndo','replyQueueCheck','replyQueueRetry'])if($(name))$(name).hidden=true;
 box.hidden=!available||(!q.needed&&!q.resolution&&!p&&!issue);if(box.hidden)return;
 box.classList.toggle('pending',!!p);box.classList.toggle('error',!!issue||!!p&&!p.busy);
 let text=q.needed?'Ожидает ответа · '+wait(q):'';
 if(q.needed&&q.waiting_since_unix){const at=time(q.waiting_since_unix);if(at)text+=' · с '+at+' МСК'}
 if(q.resolution){const r=q.resolution,at=time(r.recorded_at),decision='Ответ не требуется · '+String(r.actor_name||'Сотрудник')+(at?' · '+at+' МСК':'');text+=(text?'\nПоследняя отметка: ':'')+decision}
 if(p){text+=(text?'\n':'')+(p.busy?'Проверяем результат…':p.notFound?'Подтверждение пока не найдено. Можно проверить снова или повторить сохранение этой же отметки.':'Результат пока не подтверждён. Проверьте, сохранилась ли отметка.');addButton('replyQueueCheck',p.busy?'Подождите…':'Проверить результат',check,!!p.busy);if(p.notFound&&!p.busy)addButton('replyQueueRetry','Повторить сохранение',retry)}
 else if(q.can_undo)addButton('replyQueueUndo','Отменить отметку',()=>mutate('undo'));
 if(issue)text+=(text?'\n':'')+issue;
 $('replyQueueText').textContent=text;
}
function older(q,previous){return previous&&previous.context===q.context&&['version','customer_message_id','request_event_id','manager_message_id','queue_event_id'].some(key=>Number.isSafeInteger(q[key])&&Number.isSafeInteger(previous[key])&&q[key]<previous[key])}
function sync(c){bind();visibleId=Number(c?.id||0);if(authenticated()&&visibleId===Number(S.current)&&validState(S.detail?.reply_queue)){const q=S.detail.reply_queue,previous=latest.get(visibleId);if(older(q,previous))S.detail.reply_queue=previous;else latest.set(visibleId,q)}render()}
function accept(c,result){if(!current(c))return false;const q=result.reply_queue,previous=S.detail?.reply_queue;if(!validState(q)||!validState(previous)||q.context!==previous.context||older(q,previous)){render();return false}S.detail.reply_queue=q;latest.set(c.conversationId,q);render();return true}
function refreshInbox(c){if(!sameSession(c))return;void Promise.resolve(window.WorkspaceV2Inbox?.load({preserveScroll:true})).catch(()=>{})}
function operationId(){const bytes=new Uint8Array(16);window.crypto.getRandomValues(bytes);return [...bytes].map(b=>b.toString(16).padStart(2,'0')).join('')}
async function mutate(kind){
 const c=context(),q=S.detail?.reply_queue;if(!current(c)||!validState(q)||pending.has(c.conversationId)||(kind==='resolve'?!q.can_resolve:!q.can_undo||!q.resolution))return false;
 const input={conversation_id:c.conversationId,operation_id:operationId(),context:q.context,expected_version:q.version};
 if(kind==='resolve'){input.through_message_id=q.customer_message_id;input.through_request_event_id=q.request_event_id}else{input.resolution_actor_id=q.resolution.actor_id;input.resolution_operation_id=q.resolution.operation_id}
 const p={kind,input,busy:false,notFound:false};pending.set(c.conversationId,p);issues.delete(c.conversationId);
 if(!persist()){pending.delete(c.conversationId);issues.set(c.conversationId,'Не удалось сохранить данные для проверки результата. Освободите место в браузере и повторите действие.');render();return false}
 return dispatch(c,p);
}
const rejected={forbidden:'Доступ к этому действию изменился. Обновите диалог.',context_changed:'Назначение или состояние диалога изменилось. Обновите диалог перед новой отметкой.',conflict:'Другой менеджер уже изменил отметку. Показано актуальное состояние.',invalid_request:'Отметка не сохранена. Обновите диалог перед новым действием.',operation_mismatch:'Данные этой попытки не совпали. Обновите диалог.'};
async function dispatch(c,p){
 if(!current(c)||p.busy)return false;const focus=document.activeElement;p.busy=true;p.notFound=false;render();let result;
 try{result=await W.api('reply_queue_'+p.kind,p.input,{isCurrent:()=>sameSession(c)})}catch(e){result=null}
 if(!sameSession(c))return false;
 p.busy=false;
 if(result?.ok===true&&validState(result.reply_queue)&&Number.isSafeInteger(result.saved_version)){
  pending.delete(c.conversationId);issues.delete(c.conversationId);persist();accept(c,result);refreshInbox(c);if(current(c)&&(document.activeElement===focus||document.activeElement===document.body)){const target=$('replyQueueUndo');if(target&&!target.hidden)target.focus({preventScroll:true});else{const box=$('replyQueueStatus');box?.setAttribute('tabindex','-1');box?.focus({preventScroll:true})}}return true;
 }
 if(result?.ok===false&&rejected[result.error]){pending.delete(c.conversationId);persist();if(current(c)){issues.set(c.conversationId,rejected[result.error]);accept(c,result);render()}return false}
 if(current(c))render();return false;
}
async function check(){
 const c=context(),p=pending.get(c?.conversationId);if(!current(c)||!p||p.busy)return false;p.busy=true;render();let result;
 try{result=await W.api('reply_queue_receipt',{conversation_id:c.conversationId,operation_id:p.input.operation_id},{isCurrent:()=>sameSession(c)})}catch(e){result=null}
 if(!sameSession(c))return false;p.busy=false;
 if(result?.ok===true&&typeof result.found==='boolean'&&validState(result.reply_queue)){
  if(result.found&&result.result?.ok===true){pending.delete(c.conversationId);issues.delete(c.conversationId);persist();accept(c,result);refreshInbox(c);return true}
  if(!result.found)p.notFound=true;accept(c,result);
 }else if(result?.error==='forbidden'&&current(c))issues.set(c.conversationId,'Доступ к результату изменился. Обновите диалог.');
 if(current(c))render();return false;
}
function retry(){const c=context(),p=pending.get(c?.conversationId);if(!current(c)||!p?.notFound||p.busy)return false;return dispatch(c,p)}
window.WorkspaceV2ReplyQueue={bind,activate,suspend,sync,mutate,check};
})();
