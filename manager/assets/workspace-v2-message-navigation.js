(function(){
'use strict';
const W=window.WorkspaceV2;if(!W)return;const {S,$,api}=W;
let bound=false,scope='',revision=0,stack=[],active=null,dialog=null;
function context(){return window.WorkspaceV2Conversation?.captureReplyContext?.()||null}
function key(){const c=context();return c?JSON.stringify(c):''}
function current(c){return !S.authExpired&&key()===JSON.stringify(c)&&window.WorkspaceV2Conversation?.canNavigateMessages?.()===true}
function sourceRow(id){return [...($('messages')?.children||[])].find(row=>Number(row.dataset.scrollMessageKey)===Number(id))||null}
function quoteStamp(id){const node=sourceRow(id)?.querySelector('[data-quote-source-id]');return node?JSON.stringify([node.dataset.linkedMid,node.textContent]):null}
function anchor(id){const row=sourceRow(id),box=$('messages');return row&&box?{id:Number(id),offset:row.getBoundingClientRect().top-box.getBoundingClientRect().top,top:box.scrollTop}:null}
function focusQuote(id){sourceRow(id)?.querySelector('[data-quote-source-id]')?.focus({preventScroll:true})}
function restore(saved){
 const row=sourceRow(saved?.id),box=$('messages');if(!row||!box)return false;
 window.WorkspaceV2Jump?.restore({followingLatest:false,anchor:{row,key:String(saved.id),offset:saved.offset},top:saved.top},{preserveScroll:true});focusQuote(saved.id);return true;
}
function clearPreview(){if(dialog)window.WorkspaceV2Conversation?.renderMessageContext?.($('messageContextMessages'),[])}
function reset(){const previous=active;revision++;active=null;stack=[];scope='';if(previous?.overlay)window.WorkspaceV2Mobile?.closeOverlay('message-context');if(dialog?.open)dialog.close();clearPreview();if($('messageContextStatus')){$('messageContextStatus').hidden=true;$('messageContextStatus').textContent=''}renderBar()}
function sync(){if(active?.external){if(!externalAllowed(active))reset();return}const next=key();if(scope&&scope!==next)reset();scope=next;renderBar()}
function ensureBar(){
 let bar=$('messageNavigationBar');const messages=$('messages');if(bar||!messages)return bar;
 bar=document.createElement('div');bar.id='messageNavigationBar';bar.className='messageNavigationBar';bar.hidden=true;
 const back=document.createElement('button');back.id='messageNavigationBack';back.type='button';back.className='actionBtn';back.textContent='Вернуться к цитате';back.onclick=returnToQuote;
 const status=document.createElement('span');status.id='messageNavigationStatus';status.setAttribute('role','status');status.setAttribute('aria-live','polite');
 const retry=document.createElement('button');retry.id='messageNavigationRetry';retry.type='button';retry.className='actionBtn';retry.textContent='Повторить';retry.onclick=()=>active&&(active.search?void openSearchTarget(active.sourceId,active.returnFocus,{isCurrent:active.isCurrent,returnLabel:active.returnLabel}):void openQuote(active.sourceId,null,{retry:true}));
 bar.append(back,status,retry);messages.before(bar);return bar;
}
function renderBar(){
 if(active?.external)return;
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
 const status=document.createElement('p');status.id='messageContextStatus';status.hidden=true;status.setAttribute('role','status');status.setAttribute('aria-live','polite');
 dialog.append(head,description,status,messages);document.body.appendChild(dialog);
 dialog.addEventListener('close',()=>{if(dialog.open)return;clearPreview();const previous=active;if(previous?.external){active=null;revision++;if(previous.overlay&&!previous.fromHistory)window.WorkspaceV2Mobile?.closeOverlay('message-context');status.hidden=true;status.textContent='';if(externalAllowed(previous)){if(typeof previous.onReturn==='function')previous.onReturn();else previous.returnFocus?.isConnected&&previous.returnFocus.focus({preventScroll:true})}return}if(active?.peek){const saved=active.anchor,focus=active.returnFocus;active=null;renderBar();if(scope===key()){if(focus?.isConnected)focus.focus({preventScroll:true});else restore(saved)}}});
 return dialog;
}
function validResponse(result,sourceId,conversationId){
 if(result?.ok!==true||Number(result.conversation_id)!==conversationId||(sourceId!==null&&Number(result.source_message_id)!==sourceId)||typeof result.available!=='boolean')return false;
 if(Object.hasOwn(result,'quote_comparison')&&!validComparison(result.quote_comparison,result.available))return false;
 if(!result.available)return true;
 const rows=result.messages,target=Number(result.target_message_id);return Number.isSafeInteger(target)&&target>0&&Array.isArray(rows)&&rows.length>0&&rows.length<=41&&rows.some(m=>Number(m.id)===target)&&rows.every((m,i)=>Number.isSafeInteger(Number(m?.id))&&Number(m.id)>0&&(i===0||Number(m.id)>Number(rows[i-1].id)));
}
function validComparison(value,available){
 if(!value||!['unavailable','not_comparable','text_matches','excerpt_matches','text_differs'].includes(value.state))return false;
 if(!available&&value.state!=='unavailable'||available&&value.state==='unavailable')return false;
 const s=value.snapshot;if(s===null)return !available;
 return !!s&&['reply','forward'].includes(s.type)&&typeof s.text==='string'&&[...s.text].length<=500&&typeof s.sender_name==='string'&&[...s.sender_name].length<=160&&typeof s.may_be_truncated==='boolean';
}
function renderComparison(result){
 const value=result.quote_comparison,box=$('messageContextMessages'),section=document.createElement('section');section.className='quoteComparison';section.dataset.comparisonState=value.state;
 const add=(tag,text,className)=>{const node=document.createElement(tag);node.textContent=text;if(className)node.className=className;section.appendChild(node);return node};
 const states={text_matches:'Текст цитаты совпадает с сохранённым оригиналом.',excerpt_matches:'Сохранённый фрагмент цитаты совпадает. Полный текст этим не проверен.',text_differs:'Текст цитаты отличается от сохранённого оригинала.',not_comparable:'В сохранённой цитате нет текста для сравнения.',unavailable:'Оригинал недоступен в сохранённой переписке. Это не подтверждает его удаление.'};
 add('p',states[value.state],'quoteComparisonStatus');
 const snapshot=value.snapshot;if(snapshot){add('h3',snapshot.type==='forward'?'Сохранённый фрагмент пересылки':'Цитата в выбранном сообщении');if(snapshot.sender_name)add('p',snapshot.sender_name,'quoteComparisonSender');add('blockquote',snapshot.text||'Текст цитаты не сохранён.','quoteComparisonText');if(snapshot.may_be_truncated)add('p','Цитата может быть сокращена.','quoteComparisonHint')}
 add('p','Показаны сохранённые данные. Последние изменения в мессенджере могут отсутствовать.','quoteComparisonHint');
 if(result.available)add('h3','Сохранённый оригинал');
 const original=[...box.children].find(node=>Number(node.dataset.scrollMessageKey)===Number(result.target_message_id));box.insertBefore(section,original||box.firstChild);return section;
}
async function openQuote(sourceId,button=null,{retry=false}={}){
 sourceId=Number(sourceId);const captured=context();if(!Number.isSafeInteger(sourceId)||sourceId<=0||!current(captured)||!sourceRow(sourceId))return false;
 sync();const saved=retry&&active?.sourceId===sourceId?active.anchor:anchor(sourceId);if(!saved)return false;
 const ticket=++revision,priorStack=stack,stamp=quoteStamp(sourceId);active={sourceId,anchor:saved,status:'Открываем исходное сообщение…',retry:false};renderBar();
 const valid=()=>ticket===revision&&current(captured)&&!!sourceRow(sourceId)&&quoteStamp(sourceId)===stamp;
 let result;try{result=await api('message_target',{conversation_id:captured.conversationId,source_message_id:sourceId},{isCurrent:valid})}catch(e){result=null}
 if(!valid()){if(ticket===revision){active=null;renderBar()}return false}
 if(!validResponse(result,sourceId,captured.conversationId)){
  if([403,404].includes(result?.http_status)){window.WorkspaceV2Conversation?.invalidateMessageAccess?.();return false}
  active.status=[403,404].includes(result?.http_status)?'Диалог больше недоступен. Обновите список диалогов.':'Не удалось открыть исходное сообщение. Можно повторить.';active.retry=![403,404].includes(result?.http_status);renderBar();return false;
 }
 if(result.quote_comparison){
  const peek=ensureDialog();active={sourceId,anchor:saved,returnFocus:button,status:'',retry:false,peek:true};renderBar();
  $('messageContextTitle').textContent='Цитата и сохранённый оригинал';$('messageContextBack').textContent='Вернуться к цитате';
  window.WorkspaceV2Conversation?.renderMessageContext?.($('messageContextMessages'),result.messages||[]);
  const section=renderComparison(result);peek.showModal();const box=$('messageContextMessages');box.scrollTop=Math.max(0,box.scrollTop+section.getBoundingClientRect().top-box.getBoundingClientRect().top-12);return true;
 }
 if(!result.available){active.status='Оригинал не найден в сохранённой переписке этого диалога.';active.retry=false;renderBar();focusQuote(sourceId);return false}
 const target=Number(result.target_message_id),row=sourceRow(target);
 if(row){
  stack=[...priorStack,saved].slice(-10);active=null;renderBar();window.WorkspaceV2Jump?.scrollToMessage(target);row.classList.add('messageNavigationTarget');
  window.setTimeout(()=>row.classList.remove('messageNavigationTarget'),1800);return true;
 }
 const peek=ensureDialog();active={sourceId,anchor:saved,status:'',retry:false,peek:true};renderBar();
 $('messageContextTitle').textContent='Исходное сообщение';$('messageContextBack').textContent='Вернуться к цитате';
 window.WorkspaceV2Conversation?.renderMessageContext?.($('messageContextMessages'),result.messages);
 const original=[...$('messageContextMessages').children].find(node=>Number(node.dataset.scrollMessageKey)===target);original?.classList.add('messageNavigationTarget');
 peek.showModal();const box=$('messageContextMessages');if(original)box.scrollTop=Math.max(0,original.offsetTop-box.offsetTop-48);return true;
}
function externalAllowed(view){try{return !S.authExpired&&!S.logoutPending&&Number(S.manager?.id)===view.context.managerId&&S.authGeneration===view.context.authGeneration&&view.isCurrent()===true}catch(e){return false}}
async function openExternalTarget(target,button,{context:captured,isCurrent,returnLabel,historyOverlay,onReturn,title}){
 if(!captured||!Number.isSafeInteger(captured.conversationId)||captured.conversationId<=0||!Number.isSafeInteger(captured.managerId)||captured.managerId<=0||typeof isCurrent!=='function'||!Number.isSafeInteger(target)||target<=0)return false;
 const next={external:true,context:{...captured},isCurrent,returnFocus:button,onReturn,overlay:false,fromHistory:false};if(!externalAllowed(next)||dialog?.open)return false;
 const peek=ensureDialog(),ticket=++revision;active=next;
 if(historyOverlay){const token=window.WorkspaceV2Mobile?.openOverlay('message-context',()=>{if(active===next){next.fromHistory=true;peek.close()}});if(token===false){active=null;return false}next.overlay=!!token;}
 $('messageContextTitle').textContent=typeof title==='string'?title.slice(0,300):'Исходное сообщение';$('messageContextBack').textContent=returnLabel;
 const status=$('messageContextStatus');status.hidden=false;status.textContent='Загружаем исходное сообщение…';clearPreview();peek.showModal();$('messageContextBack').focus({preventScroll:true});
 const valid=()=>active===next&&ticket===revision&&peek.open&&externalAllowed(next);let result;
 try{result=await api('message_context',{conversation_id:captured.conversationId,target_message_id:target},{isCurrent:valid})}catch(e){result=null}
 if(!valid())return false;
 // An external task preview has its own ACL. Its refusal never invalidates
 // the unrelated conversation, composer or attachment currently on screen.
 if(!validResponse(result,null,captured.conversationId)||(result.available&&Number(result.target_message_id)!==target)){status.textContent='Не удалось открыть исходное сообщение. Закройте просмотр и обновите план.';return false;}
 if(!result.available){status.textContent='Исходное сообщение недоступно в сохранённой переписке.';return false;}
 window.WorkspaceV2Conversation?.renderMessageContext?.($('messageContextMessages'),result.messages);status.hidden=true;status.textContent='';
 const box=$('messageContextMessages'),original=[...box.children].find(row=>Number(row.dataset.scrollMessageKey)===target);original?.classList.add('messageNavigationTarget');if(original)box.scrollTop=Math.max(0,original.offsetTop-box.offsetTop-48);return true;
}
async function openSearchTarget(target,button,options={}){
 const {isCurrent=()=>true,returnLabel='Вернуться к поиску'}=options;
 if(options.context!==undefined)return openExternalTarget(Number(target),button,{...options,isCurrent,returnLabel});
 target=Number(target);const captured=context();if(!Number.isSafeInteger(target)||target<=0||!current(captured)||!isCurrent())return false;
 sync();const ticket=++revision;
 active={sourceId:target,search:true,returnFocus:button,isCurrent,returnLabel,status:'Открываем найденное сообщение…',retry:false};renderBar();
 const valid=()=>ticket===revision&&current(captured)&&isCurrent();let result;
 try{result=await api('message_context',{conversation_id:captured.conversationId,target_message_id:target},{isCurrent:valid})}catch(e){result=null}
 if(!valid()){if(ticket===revision){active=null;renderBar()}return false}
 if(!validResponse(result,null,captured.conversationId)||(result.available&&Number(result.target_message_id)!==target)){
  if([403,404].includes(result?.http_status)){window.WorkspaceV2Conversation?.invalidateMessageAccess?.();return false}
  active.status='Не удалось открыть найденное сообщение. Можно повторить.';active.retry=true;renderBar();return false;
 }
 if(!result.available){active.status='Сообщение больше не доступно в сохранённой переписке.';renderBar();return false}
 const peek=ensureDialog();active.peek=true;active.status='';renderBar();
 $('messageContextTitle').textContent='Найденное сообщение';$('messageContextBack').textContent=returnLabel;
 window.WorkspaceV2Conversation?.renderMessageContext?.($('messageContextMessages'),result.messages);
 const original=[...$('messageContextMessages').children].find(node=>Number(node.dataset.scrollMessageKey)===target);original?.classList.add('messageNavigationTarget');
 peek.showModal();const box=$('messageContextMessages');if(original)box.scrollTop=Math.max(0,original.offsetTop-box.offsetTop-48);return true;
}
function returnToQuote(){
 revision++;active=null;const saved=stack.pop();renderBar();if(scope===key())return restore(saved);return false;
}
function bind(){if(bound)return;bound=true;ensureBar()}
window.WorkspaceV2MessageNavigation={bind,sync,reset,openQuote,openSearchTarget,returnToQuote};
})();
