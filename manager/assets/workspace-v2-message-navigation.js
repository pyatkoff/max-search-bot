(function(){
'use strict';
const W=window.WorkspaceV2;if(!W)return;const {S,$,api}=W;
let bound=false,scope='',revision=0,stack=[],active=null,dialog=null;
function context(){return window.WorkspaceV2Conversation?.captureReplyContext?.()||null}
function key(){const c=context();return c?JSON.stringify(c):''}
function current(c){return !S.authExpired&&key()===JSON.stringify(c)&&window.WorkspaceV2Conversation?.canNavigateMessages?.()===true}
function sourceRow(id){return [...($('messages')?.children||[])].find(row=>Number(row.dataset.scrollMessageKey)===Number(id))||null}
function anchor(id){const row=sourceRow(id),box=$('messages');return row&&box?{id:Number(id),offset:row.getBoundingClientRect().top-box.getBoundingClientRect().top,top:box.scrollTop}:null}
function focusQuote(id){sourceRow(id)?.querySelector('[data-quote-source-id]')?.focus({preventScroll:true})}
function restore(saved){
 const row=sourceRow(saved?.id),box=$('messages');if(!row||!box)return false;
 window.WorkspaceV2Jump?.restore({followingLatest:false,anchor:{row,key:String(saved.id),offset:saved.offset},top:saved.top},{preserveScroll:true});focusQuote(saved.id);return true;
}
function clearPreview(){if(dialog)window.WorkspaceV2Conversation?.renderMessageContext?.($('messageContextMessages'),[])}
function reset(){revision++;active=null;stack=[];scope='';if(dialog?.open)dialog.close();clearPreview();renderBar()}
function sync(){const next=key();if(scope&&scope!==next)reset();scope=next;renderBar()}
function ensureBar(){
 let bar=$('messageNavigationBar');const messages=$('messages');if(bar||!messages)return bar;
 bar=document.createElement('div');bar.id='messageNavigationBar';bar.className='messageNavigationBar';bar.hidden=true;
 const back=document.createElement('button');back.id='messageNavigationBack';back.type='button';back.className='actionBtn';back.textContent='Вернуться к цитате';back.onclick=returnToQuote;
 const status=document.createElement('span');status.id='messageNavigationStatus';status.setAttribute('role','status');status.setAttribute('aria-live','polite');
 const retry=document.createElement('button');retry.id='messageNavigationRetry';retry.type='button';retry.className='actionBtn';retry.textContent='Повторить';retry.onclick=()=>active&&void openQuote(active.sourceId,null,{retry:true});
 bar.append(back,status,retry);messages.before(bar);return bar;
}
function renderBar(){
 const bar=ensureBar();if(!bar)return;
 const visible=!!S.current&&!S.authExpired&&(stack.length>0||!!active);bar.hidden=!visible;
 $('messageNavigationBack').hidden=!stack.length;
 $('messageNavigationStatus').textContent=active?.status||'';
 $('messageNavigationRetry').hidden=!active?.retry;
}
function ensureDialog(){
 if(dialog)return dialog;
 dialog=document.createElement('dialog');dialog.id='messageContextDialog';dialog.className='messageContextDialog';dialog.setAttribute('aria-labelledby','messageContextTitle');
 const head=document.createElement('div');head.className='messageContextHead';const title=document.createElement('strong');title.id='messageContextTitle';title.textContent='Исходное сообщение';
 const back=document.createElement('button');back.id='messageContextBack';back.type='button';back.className='actionBtn';back.textContent='Вернуться к цитате';back.onclick=()=>dialog.close();head.append(title,back);
 const description=document.createElement('p');description.className='messageContextDescription';description.textContent='Оригинал и соседние сообщения. Это ограниченный фрагмент переписки.';
 const messages=document.createElement('div');messages.id='messageContextMessages';messages.className='messages messageContextMessages';
 dialog.append(head,description,messages);document.body.appendChild(dialog);
 dialog.addEventListener('close',()=>{clearPreview();if(active?.peek){const saved=active.anchor;active=null;renderBar();if(scope===key())restore(saved)}});
 return dialog;
}
function validResponse(result,sourceId,conversationId){
 if(result?.ok!==true||Number(result.conversation_id)!==conversationId||Number(result.source_message_id)!==sourceId||typeof result.available!=='boolean')return false;
 if(!result.available)return true;
 const rows=result.messages,target=Number(result.target_message_id);return Number.isSafeInteger(target)&&target>0&&Array.isArray(rows)&&rows.length>0&&rows.length<=41&&rows.some(m=>Number(m.id)===target)&&rows.every((m,i)=>Number.isSafeInteger(Number(m?.id))&&Number(m.id)>0&&(i===0||Number(m.id)>Number(rows[i-1].id)));
}
async function openQuote(sourceId,button=null,{retry=false}={}){
 sourceId=Number(sourceId);const captured=context();if(!Number.isSafeInteger(sourceId)||sourceId<=0||!current(captured)||!sourceRow(sourceId))return false;
 sync();const saved=retry&&active?.sourceId===sourceId?active.anchor:anchor(sourceId);if(!saved)return false;
 const ticket=++revision,priorStack=stack;active={sourceId,anchor:saved,status:'Открываем исходное сообщение…',retry:false};renderBar();
 const valid=()=>ticket===revision&&current(captured)&&!!sourceRow(sourceId);
 let result;try{result=await api('message_target',{conversation_id:captured.conversationId,source_message_id:sourceId},{isCurrent:valid})}catch(e){result=null}
 if(!valid()){if(ticket===revision){active=null;renderBar()}return false}
 if(!validResponse(result,sourceId,captured.conversationId)){
  if([403,404].includes(result?.http_status)){window.WorkspaceV2Conversation?.invalidateMessageAccess?.();return false}
  active.status=[403,404].includes(result?.http_status)?'Диалог больше недоступен. Обновите список диалогов.':'Не удалось открыть исходное сообщение. Можно повторить.';active.retry=![403,404].includes(result?.http_status);renderBar();return false;
 }
 if(!result.available){active.status='Оригинал не найден в сохранённой переписке этого диалога.';active.retry=false;renderBar();focusQuote(sourceId);return false}
 const target=Number(result.target_message_id),row=sourceRow(target);
 if(row){
  stack=[...priorStack,saved].slice(-10);active=null;renderBar();window.WorkspaceV2Jump?.scrollToMessage(target);row.classList.add('messageNavigationTarget');
  window.setTimeout(()=>row.classList.remove('messageNavigationTarget'),1800);return true;
 }
 const peek=ensureDialog();active={sourceId,anchor:saved,status:'',retry:false,peek:true};renderBar();
 window.WorkspaceV2Conversation?.renderMessageContext?.($('messageContextMessages'),result.messages);
 const original=[...$('messageContextMessages').children].find(node=>Number(node.dataset.scrollMessageKey)===target);original?.classList.add('messageNavigationTarget');
 peek.showModal();const box=$('messageContextMessages');if(original)box.scrollTop=Math.max(0,original.offsetTop-box.offsetTop-48);return true;
}
function returnToQuote(){
 revision++;active=null;const saved=stack.pop();renderBar();if(scope===key())return restore(saved);return false;
}
function bind(){if(bound)return;bound=true;ensureBar()}
window.WorkspaceV2MessageNavigation={bind,sync,reset,openQuote,returnToQuote};
})();
