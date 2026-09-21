(function(){
const W=window.WorkspaceV2,{S,$,api,pipe,statusText}=W;let bound=false,busy=false,openSeq=0,opening=0,refreshEpoch=0,refreshPending=false,accessLost=false;const drafts=new Map();
const replySessionKey='workspaceV2.replySession.v1',draftLifetime=24*60*60*1000,maxStoredDrafts=20,maxStoredDraftLength=20000;
let replySessionOwner=0,savedSelection=0;
let photoViewer=null;
function validId(value){return Number.isSafeInteger(value)&&value>0}
function removeReplySession(){try{sessionStorage.removeItem(replySessionKey)}catch(e){}}
function persistReplySession(){
  if(!replySessionOwner)return;
  const now=Date.now(),entries=[...drafts].reverse().filter(([id,d])=>validId(id)&&d.text.length<=maxStoredDraftLength&&now-d.updatedAt<draftLifetime).sort((a,b)=>b[1].updatedAt-a[1].updatedAt).slice(0,maxStoredDrafts).map(([id,d])=>({id,text:d.text,updatedAt:d.updatedAt}));
  // Remove the previous envelope first: a quota error must not revive a sent/cleared draft.
  removeReplySession();
  if(!savedSelection&&!entries.length)return;
  try{sessionStorage.setItem(replySessionKey,JSON.stringify({version:1,managerId:replySessionOwner,selected:savedSelection,updatedAt:now,drafts:entries}))}catch(e){}
}
function activateReplySession(managerId){
  managerId=Number(managerId||0);
  if(managerId!==replySessionOwner)closePhotoViewer(false);
  if(!validId(managerId)){drafts.clear();replySessionOwner=0;savedSelection=0;removeReplySession();return 0}
  if(replySessionOwner===managerId)return savedSelection;
  drafts.clear();replySessionOwner=managerId;savedSelection=0;
  try{
    const raw=sessionStorage.getItem(replySessionKey),now=Date.now();
    const value=raw&&raw.length<=2500000?JSON.parse(raw):null;
    if(value?.version===1&&value.managerId===managerId&&Number.isFinite(value.updatedAt)&&value.updatedAt<=now&&now-value.updatedAt<draftLifetime&&Array.isArray(value.drafts)){
      savedSelection=validId(value.selected)?value.selected:0;
      for(const d of value.drafts.slice(0,maxStoredDrafts))if(validId(d?.id)&&typeof d.text==='string'&&d.text.length>0&&d.text.length<=maxStoredDraftLength&&Number.isFinite(d.updatedAt)&&d.updatedAt<=now&&now-d.updatedAt<draftLifetime)drafts.set(d.id,{text:d.text,updatedAt:d.updatedAt});
    }
  }catch(e){}
  persistReplySession();return savedSelection;
}
function rememberSelection(id){savedSelection=validId(Number(id))?Number(id):0;persistReplySession()}
function forgetSavedConversation(id){drafts.delete(id);if(savedSelection===id)savedSelection=0;persistReplySession()}
function initials(name){const parts=String(name||'Турист').trim().split(/\s+/).filter(Boolean);return parts.slice(0,2).map(x=>x.charAt(0).toUpperCase()).join('')||'Т'}
function messageTime(value){const s=String(value||'').trim(),m=s.match(/^(\d{4})-(\d{2})-(\d{2})[ T](\d{2}):(\d{2})/);return m?`${m[3]}.${m[2]} · ${m[4]}:${m[5]}`:s}
function setReplyStatus(text='',kind=''){const el=$('replyStatus');if(!el)return;el.textContent=text;el.className='replyStatus'+(kind?' '+kind:'')}
function ensureLoadStatus(){let el=$('conversationLoadStatus');if(el)return el;el=document.createElement('div');el.id='conversationLoadStatus';el.className='conversationLoadStatus hidden';el.setAttribute('aria-live','polite');const zone=$('conversationZone'),head=zone?.querySelector('.conversationHead');if(zone&&head)head.insertAdjacentElement('afterend',el);return el}
function setLoadStatus(text='',kind=''){const el=ensureLoadStatus();if(!el)return;el.textContent=text;el.className='conversationLoadStatus'+(kind?' '+kind:'')+(text?'':' hidden')}
function setRefreshStatus(text=''){let el=$('conversationRefreshStatus');if(!el&&text){el=document.createElement('div');el.id='conversationRefreshStatus';el.setAttribute('aria-live','polite');const head=$('conversationZone')?.querySelector('.conversationHead');head?.insertAdjacentElement('afterend',el)}if(!el)return;el.textContent=text;el.className='conversationLoadStatus error'+(text?'':' hidden')}
function setBusy(value){busy=!!value;if(busy)refreshEpoch++;applyInteractionState()}
function deliverySuspended(){return String(S.detail?.delivery_failure?.category||'')==='suspended'}
function applyComposerState(){const suspended=deliverySuspended(),send=$('sendReply'),reply=$('replyText'),file=$('replyFile');if(send){send.disabled=busy||suspended||accessLost;send.textContent=busy?'Отправляем…':'Отправить'}if(reply)reply.disabled=busy||suspended||accessLost;if(file)file.disabled=busy||suspended||accessLost;document.querySelectorAll('.quickReplies [data-reply]').forEach(b=>{b.disabled=busy||suspended||accessLost})}
function applyActionState(){document.querySelectorAll('#conversationActions button').forEach(b=>{b.disabled=busy||accessLost})}
function applyInteractionState(){applyComposerState();applyActionState()}
// A failed request is not proof that the recipient did not receive the message.
// Keep explicit server reasons, but never turn a transport uncertainty into a retry instruction.
function sendFailureNotice(failure,fallback=''){
  if(['suspended','blocked','unavailable','unsupported'].includes(failure?.category))return String(fallback||failure.message||'Отправка отклонена.');
  return 'Отправка не подтверждена. Проверьте переписку перед повторной попыткой, чтобы не отправить сообщение дважды.';
}
function renderDeliveryFailure(failure){const el=$('deliveryFailure');if(!el)return;const f=failure||null;if(!f){el.textContent='';el.classList.add('hidden');applyInteractionState();return}const message=sendFailureNotice(f,String(f.message||f.error_message||''));el.textContent=message;el.classList.remove('hidden');el.classList.toggle('suspended',String(f.category||'')==='suspended');applyInteractionState()}
function autoGrow(){const el=$('replyText');if(!el)return;el.style.height='auto';el.style.height=Math.min(150,Math.max(38,el.scrollHeight))+'px'}
function saveDraft(id=S.current){const reply=$('replyText'),key=Number(id||0);if(!reply||!key)return;const text=reply.value;drafts.delete(key);if(text)drafts.set(key,{text,updatedAt:Date.now()});persistReplySession()}
function restoreDraft(id=S.current){const reply=$('replyText'),key=Number(id||0);if(!reply)return;reply.value=key?(drafts.get(key)?.text||''):'';autoGrow()}
function addQuickReply(text){
  const reply=$('replyText'),form=$('composer'),addition=String(text||'');
  if(!reply||!form||reply.disabled||form.classList.contains('hidden')||!addition)return;
  const draft=reply.value;
  reply.value=draft+(draft&&!draft.endsWith('\n')?'\n':'')+addition;
  saveDraft();autoGrow();reply.focus();
}
function suspendForAuthRecovery(){closePhotoViewer(false);openSeq++;opening=0;refreshEpoch++;setRefreshStatus();$('composer')?.classList.add('hidden')}
function resetForIdentityChange(){
  suspendForAuthRecovery();accessLost=false;drafts.clear();savedSelection=0;replySessionOwner=0;removeReplySession();S.current=0;S.detail=null;
  const reply=$('replyText');if(reply)reply.value='';autoGrow();setReplyStatus();setLoadStatus();
  window.WorkspaceV2Media?.clear();renderMessages([]);
  ['conversationTitle','conversationAvatar','conversationState','conversationMeta','conversationActions','deliveryFailure','composerLocked'].forEach(id=>{$(id)?.replaceChildren()});
  $('deliveryFailure')?.classList.add('hidden');$('composerLocked')?.classList.add('hidden');
}
function closePhotoViewer(restoreFocus=true){
  const view=photoViewer;if(!view)return;photoViewer=null;
  clearTimeout(view.timer);
  if(view.image){view.image.onload=null;view.image.onerror=null;view.image.removeAttribute('src')}
  window.removeEventListener('popstate',view.leave);window.removeEventListener('pagehide',view.leave);
  view.dialog.remove();
  if(restoreFocus&&view.opener?.isConnected&&!S.authExpired&&!accessLost)view.opener.focus({preventScroll:true});
}
function safePhotoUrl(url){
  if(!url||/[\u0000-\u0020\u007f]/.test(url))return false;
  try{const parsed=new URL(url,window.location.href);return !parsed.username&&!parsed.password&&(parsed.protocol==='https:'||(parsed.protocol==='http:'&&parsed.origin===window.location.origin))}catch(e){return false}
}
function openPhotoViewer(url,name,opener){
  if(S.authExpired||accessLost){setLoadStatus('Войдите в кабинет и откройте доступный диалог заново.','error');return true}
  if(!safePhotoUrl(url))return false;
  const dialog=document.createElement('dialog');
  // A real same-tab link remains usable when native dialogs are unavailable.
  if(typeof dialog.showModal!=='function')return false;
  closePhotoViewer(false);
  const view={dialog,opener,url,conversation:S.current,manager:S.manager?.id,auth:S.authGeneration,image:null,timer:0,leave:()=>closePhotoViewer(false)};
  photoViewer=view;dialog.className='photoViewer';dialog.setAttribute('aria-label','Просмотр фото');
  const bar=document.createElement('div');bar.className='photoViewerBar';
  const title=document.createElement('strong');title.textContent='Просмотр фото';bar.appendChild(title);
  const zoom=document.createElement('button');zoom.type='button';zoom.textContent='Увеличить';zoom.disabled=true;zoom.setAttribute('aria-pressed','false');bar.appendChild(zoom);
  const close=document.createElement('button');close.type='button';close.textContent='Закрыть';close.setAttribute('aria-label','Закрыть фото');close.onclick=()=>closePhotoViewer();bar.appendChild(close);
  const status=document.createElement('div');status.className='photoViewerStatus';status.setAttribute('role','status');
  const retry=document.createElement('button');retry.type='button';retry.textContent='Повторить';retry.hidden=true;
  const stage=document.createElement('div');stage.className='photoViewerStage';stage.tabIndex=0;stage.setAttribute('aria-label','Фотография');
  dialog.append(bar,status,retry,stage);document.body.appendChild(dialog);
  const current=()=>photoViewer===view&&!S.authExpired&&!accessLost&&S.current===view.conversation&&S.manager?.id===view.manager&&S.authGeneration===view.auth;
  function load(){
    if(!current()){closePhotoViewer(false);return}
    clearTimeout(view.timer);
    if(view.image){view.image.onload=null;view.image.onerror=null;view.image.removeAttribute('src')}
    const image=document.createElement('img');view.image=image;image.alt=name||'Фото';image.hidden=true;
    stage.replaceChildren(image);stage.classList.remove('zoomed');status.textContent='Загружаем фото…';retry.hidden=true;zoom.disabled=true;zoom.textContent='Увеличить';zoom.setAttribute('aria-pressed','false');
    const active=()=>current()&&view.image===image;
    const failed=()=>{if(!active())return;clearTimeout(view.timer);image.hidden=true;zoom.disabled=true;status.textContent='Не удалось загрузить фото. Проверьте соединение и доступ к диалогу, затем повторите попытку.';retry.hidden=false};
    image.onload=()=>{if(!active())return;clearTimeout(view.timer);image.hidden=false;status.textContent='';retry.hidden=true;zoom.disabled=false};
    image.onerror=failed;view.timer=setTimeout(failed,20000);image.src=url;
  }
  retry.onclick=load;
  zoom.onclick=()=>{if(!current()||!view.image?.naturalWidth)return;const expanded=stage.classList.toggle('zoomed');view.image.style.width=expanded?view.image.naturalWidth+'px':'';zoom.textContent=expanded?'Уместить':'Увеличить';zoom.setAttribute('aria-pressed',String(expanded))};
  dialog.addEventListener('cancel',event=>{event.preventDefault();closePhotoViewer()});
  dialog.addEventListener('close',()=>{if(photoViewer===view)closePhotoViewer()});
  dialog.addEventListener('keydown',event=>event.stopPropagation());
  window.addEventListener('popstate',view.leave);window.addEventListener('pagehide',view.leave);
  try{dialog.showModal();close.focus({preventScroll:true});load();return true}catch(e){closePhotoViewer(false);return false}
}
function photoLink(url,name,label){
  const link=document.createElement('a');link.href=url;link.className='photoOpen';link.setAttribute('aria-label',label);
  link.onclick=event=>{if(event.defaultPrevented||event.button!==0||event.metaKey||event.ctrlKey||event.shiftKey||event.altKey)return;if(openPhotoViewer(url,name,link))event.preventDefault()};
  return link;
}
function looksLikeImage(a,url){const probe=String(a?.name||url||'').split('?')[0].toLowerCase();return a?.type==='image'||/\.(png|jpe?g|gif|webp|bmp|avif)$/.test(probe)}
function mediaFallback(node,a,url,label='Вложение'){node.onerror=()=>{const link=document.createElement('a');link.textContent='Не удалось загрузить: '+(a?.name||label)+'. Открыть файл';if(url){link.href=url;link.target='_blank';link.rel='noopener'}node.replaceWith(link)}}
function renderAttachments(root,items){
  if(!Array.isArray(items)||!items.length)return;
  const wrap=document.createElement('div');wrap.className='attachments';
  items.forEach(a=>{
    if(!a)return;
    const url=String(a.url||'');let n;
    if((a.type==='image'||looksLikeImage(a,url))&&url){
      if(!safePhotoUrl(url)){n=document.createElement('span');n.textContent='Фото недоступно';wrap.appendChild(n);return}
      const preview=photoLink(url,a.name,'Увеличить фото');preview.classList.add('photoPreview');
      n=document.createElement('img');n.loading='lazy';n.alt=a.name||'Изображение';
      n.onerror=()=>{const text=document.createElement('span');text.textContent='Не удалось загрузить: '+(a.name||'Изображение')+'. Открыть файл';preview.setAttribute('aria-label',text.textContent);n.replaceWith(text)};
      n.src=url;preview.appendChild(n);wrap.appendChild(preview);
      const open=photoLink(url,a.name,'Открыть фото');open.textContent='Открыть фото';wrap.appendChild(open);return;
    }
    if(a.type==='video'&&url){n=document.createElement('video');n.src=url;n.controls=true;mediaFallback(n,a,url,'Видео')}
    else if(a.type==='audio'&&url){n=document.createElement('audio');n.src=url;n.controls=true;mediaFallback(n,a,url,'Аудио')}
    else{n=document.createElement('a');n.textContent='📎 '+(a.name||'Вложение');if(url){n.href=url;n.target='_blank';n.rel='noopener'}}
    wrap.appendChild(n);
  });root.appendChild(wrap);
}
function renderMessageBody(body,m){
  body.textContent=m.text||'';
  if(m.sender_type!=='ai')return;
  // Only exact, attribute-free bot bold spans are presentation markup.
  // All content remains text nodes; never parse stored messages as HTML.
  const text=String(m.text||''),matches=[...text.matchAll(/<b>([^<>]*)<\/b>/g)];
  if(!matches.length)return;
  body.replaceChildren();let offset=0;
  for(const match of matches){
    body.appendChild(document.createTextNode(text.slice(offset,match.index)));
    const strong=document.createElement('strong');strong.textContent=match[1];body.appendChild(strong);
    offset=match.index+match[0].length;
  }
  body.appendChild(document.createTextNode(text.slice(offset)));
}
// This is a stored server projection, not a guess from message order or local read state.
function renderMessageDelivery(root,m,expanded){
  const d=m.delivery,channel=d?.channel;
  if(m.direction!=='outbound'||m.sender_type!=='manager'||Number(m.id)<=0
    ||!['max','telegram','website'].includes(channel)||d?.read!=='unavailable')return;
  let label,explanation;
  const name=channel==='max'?'MAX':'Telegram';
  if(d.state==='accepted'&&channel!=='website'){
    label='Отправлено';explanation=name+' подтвердил приём сообщения. Это не подтверждение доставки на устройство или прочтения клиентом.';
  }else if(d.state==='stored'&&channel==='website'){
    label='В чате сайта';explanation='Сообщение сохранено для чата сайта. Это не подтверждает, что клиент открыл чат или прочитал сообщение.';
  }else{
    label='Статус не сохранён';explanation='Для этого сообщения нет сохранённого подтверждения отправки. Это не означает, что оно не доставлено. Не отправляйте его повторно только из-за отсутствия отметки.';
  }
  const details=document.createElement('details');details.className='messageDelivery';details.dataset.deliveryId=String(m.id);
  details.open=expanded.has(String(m.id));
  const summary=document.createElement('summary');summary.textContent=label;
  summary.setAttribute('aria-label',label+'. О доставке и прочтении');
  const info=document.createElement('div');info.className='messageDeliveryInfo';info.textContent=explanation;
  const read=document.createElement('div');read.className='messageReadUnavailable';
  read.textContent=channel==='website'?'Статус прочтения в чате сайта не передаётся.':'Статус прочтения: '+name+' не передаёт его через используемый API бота.';
  details.appendChild(summary);details.appendChild(info);details.appendChild(read);root.appendChild(details);
}
function renderMessages(messages,{stickToBottom=false,preserveScroll=false}={}){if(photoViewer&&!(messages||[]).some(m=>(m.attachments||[]).some(a=>a?.url===photoViewer.url)))closePhotoViewer(false);const box=$('messages'),distanceFromBottom=Math.max(0,box.scrollHeight-box.scrollTop-box.clientHeight);const expanded=new Set([...(document.querySelectorAll?.('#messages .messageDelivery[open]')||[])].map(el=>el.dataset.deliveryId));const frag=document.createDocumentFragment();(messages||[]).forEach(m=>{const n=document.createElement('div');const who=m.sender_type==='customer'?'customer':m.sender_type==='manager'?'manager':'ai',whoLabel=who==='customer'?'Турист':who==='manager'?'Менеджер':'AI';n.className='msg '+who;n.dataset.sender=who;const sender=document.createElement('span');sender.className='messageSender';sender.textContent=whoLabel;n.appendChild(sender);const body=document.createElement('div');body.className='msgBody';renderMessageBody(body,m);n.appendChild(body);renderAttachments(n,m.attachments||[]);const meta=document.createElement('div');meta.className='msgMeta';meta.textContent=messageTime(m.created_at||'');meta.title=m.created_at||'';n.appendChild(meta);renderMessageDelivery(n,m,expanded);frag.appendChild(n)});if(!frag.childNodes.length){const empty=document.createElement('div');empty.className='conversationEmpty';empty.innerHTML='<div class="conversationEmptyIcon">💬</div><strong>Сообщений пока нет</strong><span>История диалога появится здесь.</span>';frag.appendChild(empty)}box.replaceChildren(frag);if(stickToBottom)box.scrollTop=box.scrollHeight;else if(preserveScroll)box.scrollTop=Math.max(0,box.scrollHeight-box.clientHeight-distanceFromBottom)}
function renderHeader(c){const name=c.display_name||'Турист';$('conversationTitle').textContent=name;$('conversationAvatar').textContent=initials(name);const state=$('conversationState');state.textContent=statusText(c.status);state.className='conversationState '+String(c.status||'');const origin=[c.source_name,(c.channel||'').toUpperCase()].filter(Boolean).join(' · ');$('conversationMeta').textContent=[origin,c.manager_name?`Менеджер: ${c.manager_name}`:''].filter(Boolean).join(' · ')}
function clearCurrentReply(target){drafts.delete(target);persistReplySession();$('replyText').value='';autoGrow();window.WorkspaceV2Media?.clear()}
function canRefreshVisible(){const mobile=window.WorkspaceV2Mobile;return !!S.current&&Number(S.detail?.conversation?.id)===Number(S.current)&&!!S.manager?.id&&!S.authExpired&&!busy&&!opening&&document.visibilityState!=='hidden'&&(!mobile?.isMobile()||mobile.getScreen?.()==='conversation')}
async function refreshVisible(){
  if(refreshPending||!canRefreshVisible())return false;
  const target=Number(S.current),generation=openSeq,epoch=refreshEpoch,owner=Number(S.manager.id),authGeneration=S.authGeneration;
  const stillCurrent=()=>canRefreshVisible()&&Number(S.current)===target&&openSeq===generation&&refreshEpoch===epoch&&Number(S.manager?.id)===owner&&S.authGeneration===authGeneration;
  refreshPending=true;
  try{
    const d=await api('detail',{conversation_id:target});
    if(!stillCurrent())return false;
    if(!d?.ok){
      if([403,404].includes(d?.http_status)){closePhotoViewer(false);accessLost=true;forgetSavedConversation(target);clearCurrentReply(target);renderActions(S.detail.conversation);setRefreshStatus('Доступ к диалогу изменился. Выберите доступный лид в списке.')}
      else setRefreshStatus('Не удалось обновить переписку. Показаны последние загруженные сообщения.');
      return false;
    }
    if(Number(d.conversation?.id)!==target){setRefreshStatus('Не удалось обновить переписку. Показаны последние загруженные сообщения.');return false}
    const previous=S.detail,c=d.conversation,changed=JSON.stringify(previous.messages||[])!==JSON.stringify(d.messages||[]),failureChanged=JSON.stringify(previous.delivery_failure||null)!==JSON.stringify(d.delivery_failure||null);
    const actionsChanged=accessLost||failureChanged||JSON.stringify([previous.conversation.manager_id,previous.conversation.status,previous.conversation.manager_name])!==JSON.stringify([c.manager_id,c.status,c.manager_name]);
    accessLost=false;S.detail={...d,lead:previous.lead};setRefreshStatus();renderHeader(c);
    if(changed){const box=$('messages'),top=box.scrollTop,atBottom=box.scrollHeight-box.clientHeight-top<=48;renderMessages(d.messages||[],{stickToBottom:atBottom});if(!atBottom)box.scrollTop=top}
    if(failureChanged)renderDeliveryFailure(d.delivery_failure||null);
    if(actionsChanged)renderActions(c);
    if(Number(c.manager_id)!==Number(S.manager.id)||c.status!=='manager')clearCurrentReply(target);
    window.WorkspaceV2Inbox?.markRead(target);return true;
  }catch(e){if(stillCurrent())setRefreshStatus('Не удалось обновить переписку. Показаны последние загруженные сообщения.');return false}
  finally{refreshPending=false}
}
async function open(id,options={}){
  const previous=Number(S.current||0),target=Number(id||0),switching=target!==previous;
  if(!target)return false;
  if(switching&&window.WorkspaceV2Tasks?.blockNavigationForDirtyDraft?.())return false;
  if(switching)closePhotoViewer(false);
  const seq=++openSeq;opening=seq;refreshEpoch++;setRefreshStatus();
  if(switching&&previous)saveDraft(previous);
  setLoadStatus(switching?'Открываем лид…':'Обновляем диалог…','loading');
  const [detailResult,leadResult]=await Promise.allSettled([api('detail',{conversation_id:target}),pipe('detail',{conversation_id:target})]);
  if(opening===seq)opening=0;
  if(seq!==openSeq)return false;
  if(S.authExpired||(options.restoreSession&&savedSelection!==target))return false;
  const d=detailResult.status==='fulfilled'?detailResult.value:null,p=leadResult.status==='fulfilled'&&leadResult.value?.ok?leadResult.value:null;
  if(!d?.ok){if(options.restoreSession&&[403,404].includes(d?.http_status))forgetSavedConversation(target);if(!S.authExpired)setLoadStatus(switching?'Не удалось открыть лид. Текущий диалог не изменён.':'Не удалось обновить диалог. На экране остаются предыдущие данные.','error');return false}
  if(options.restoreSession&&Number(d.conversation?.id)!==target){forgetSavedConversation(target);setLoadStatus('Не удалось восстановить диалог. Выберите лид в списке.','error');return false}
  accessLost=false;S.current=target;S.detail={...d,lead:p};rememberSelection(target);setReplyStatus();setLoadStatus();window.WorkspaceV2Media?.configure(S.csrf,S.current);if(!options.preserveAttachment||switching)window.WorkspaceV2Media?.clear();window.WorkspaceV2Inbox?.markRead(S.current);
  const c=d.conversation;renderHeader(c);renderMessages(d.messages||[],{stickToBottom:options.stickToBottom===true||switching,preserveScroll:options.preserveMessageScroll===true&&!switching});renderDeliveryFailure(d.delivery_failure||null);if(p)window.WorkspaceV2LeadCard?.render(p,c);else window.WorkspaceV2LeadCard?.renderUnavailable(c);renderActions(c);if(Number(c.manager_id)!==Number(S.manager.id)||c.status!=='manager'){drafts.delete(target);persistReplySession();$('replyText').value='';autoGrow();window.WorkspaceV2Media?.clear()}else if(switching)restoreDraft(S.current);window.WorkspaceV2Inbox?.markActive(S.current);if(window.WorkspaceV2Mobile?.isMobile())window.WorkspaceV2Mobile.conversationOpened(S.current,{historyMode:options.mobileHistory||'push'});else $('conversationZone').classList.add('open');return true
}
async function refreshLeadData({refreshInbox=false,conversationId=S.current}={}){const target=Number(conversationId||0);if(!target)return false;let p;try{p=await pipe('detail',{conversation_id:target})}catch(e){p=null}const stillCurrent=Number(S.current)===target&&!!S.detail?.conversation;if(!p?.ok){if(stillCurrent&&!S.detail?.lead)window.WorkspaceV2LeadCard?.renderUnavailable(S.detail.conversation);return false}if(stillCurrent){S.detail.lead=p;window.WorkspaceV2LeadCard?.render(p,S.detail.conversation)}if(refreshInbox)await window.WorkspaceV2Inbox?.load({preserveScroll:true});return stillCurrent}
function renderActions(c){const root=$('conversationActions'),locked=$('composerLocked'),composer=$('composer');root.innerHTML='';const own=Number(c.manager_id)===Number(S.manager.id)&&c.status==='manager',canTake=(c.status==='waiting_manager'||c.status==='ai')&&!c.manager_id&&(S.manager.role==='admin'||S.manager.is_working),suspended=deliverySuspended();if(canTake)action('Взять','primary',()=>change('take'));if(own){action('Вернуть AI','',()=>change('release'));action('Закрыть','',()=>change('close'))}if(c.status==='closed')action('Переоткрыть','primary',()=>change('reopen'));composer.classList.toggle('hidden',!own||accessLost);locked.classList.toggle('hidden',own&&!suspended&&!accessLost);if(accessLost)locked.textContent='Доступ к диалогу изменился. Выберите доступный лид в списке.';else if(!own){if(c.status==='closed')locked.innerHTML='<strong>Диалог закрыт.</strong> Переоткройте его, чтобы продолжить общение.';else if(canTake)locked.innerHTML='<strong>Чтобы ответить туристу, возьмите лид.</strong> Переписку можно читать без назначения.';else if(c.manager_name)locked.innerHTML=`Лид сейчас у менеджера <strong>${W.esc(c.manager_name)}</strong>.`;else locked.innerHTML='Ответ сейчас недоступен для этого диалога.'}else if(suspended){locked.innerHTML='<strong>Отправка временно заблокирована.</strong> Клиент недоступен в MAX; дождитесь нового входящего сообщения.'}else locked.textContent='';applyInteractionState();autoGrow()}
function action(text,cl,fn){const b=document.createElement('button');b.className='actionBtn '+cl;b.type='button';b.textContent=text;b.disabled=busy||accessLost;b.onclick=fn;$('conversationActions').appendChild(b)}
const lifecycleCopy={take:{loading:'Берём лид…',success:'Лид назначен вам',error:'Не удалось взять лид'},release:{loading:'Возвращаем лид AI…',success:'Лид возвращён AI',error:'Не удалось вернуть лид AI'},close:{loading:'Закрываем диалог…',success:'Диалог закрыт',error:'Не удалось закрыть диалог'},reopen:{loading:'Переоткрываем диалог…',success:'Диалог снова в работе',error:'Не удалось переоткрыть диалог'}};
async function change(a){
  if(busy)return;
  if(accessLost)return;
  const target=Number(S.current||0),generation=openSeq,copy=lifecycleCopy[a]||{loading:'Сохраняем…',success:'Сохранено',error:'Не удалось сохранить'};
  if(!target)return;
  setBusy(true);setLoadStatus(copy.loading,'loading');
  try{
    const j=await api(a,{conversation_id:target});
    if(!j?.ok){if(!S.authExpired&&openSeq===generation)setLoadStatus(j?.error_message||copy.error,'error');return}
    const stillCurrent=openSeq===generation&&Number(S.current)===target;
    if(stillCurrent){const refreshed=await open(target,{preserveMessageScroll:true,mobileHistory:'none'});if(refreshed)setLoadStatus(copy.success,'success')}
    await window.WorkspaceV2Inbox?.load({preserveScroll:true})
  }catch(e){if(!S.authExpired&&openSeq===generation)setLoadStatus(copy.error,'error')}
  finally{setBusy(false)}
}
async function sendReply(){
  if(busy||deliverySuspended())return;
  if(accessLost||S.authExpired)return;
  const owner=replySessionOwner,draftText=$('replyText').value;
  const target=Number(S.current||0),generation=openSeq,text=$('replyText').value.trim(),hasFile=window.WorkspaceV2Media?.hasFile();
  const manager=Number(S.manager?.id),authGeneration=S.authGeneration;
  const sameSession=()=>!S.authExpired&&S.authGeneration===authGeneration&&Number(S.manager?.id)===manager;
  const sameTarget=()=>sameSession()&&Number(S.current)===target;
  const currentAttempt=()=>sameTarget()&&openSeq===generation;
  if(!target||(!text&&!hasFile))return;
  // The panel describes the latest attempt, not an unresolved older warning.
  // The existing suspended-recipient guard above is unchanged.
  renderDeliveryFailure(null);
  setBusy(true);setReplyStatus('Отправляем сообщение…');
  try{
    let j;
    try{
      if(hasFile)j=await window.WorkspaceV2Media.send(text);else j=await api('send',{conversation_id:target,text});
    }catch(e){
      if(currentAttempt())setReplyStatus(sendFailureNotice(null),'error');
      return;
    }
    if(j?.ok!==true){
      const failure=j?.failure||null;
      if(currentAttempt()&&failure&&S.detail?.conversation){S.detail.delivery_failure=failure;renderDeliveryFailure(failure);renderActions(S.detail.conversation)}
      if(currentAttempt())setReplyStatus(sendFailureNotice(failure,j?.error_message),'error');
      return;
    }
    if(sameSession()&&owner===replySessionOwner&&owner===Number(S.manager?.id)&&drafts.get(target)?.text===draftText){drafts.delete(target);persistReplySession()}
    const stillCurrent=currentAttempt();
    if(stillCurrent){
      if($('replyText').value===draftText)$('replyText').value='';autoGrow();
      setReplyStatus('Отправлено','success');
      // Sending already succeeded. A later history/render failure cannot reverse that result.
      let refreshed=false,refreshGeneration=openSeq;
      try{
        const refreshing=open(target,{stickToBottom:true,mobileHistory:'none',preserveAttachment:true});
        refreshGeneration=openSeq;refreshed=await refreshing;
      }catch(e){}
      if(sameTarget()&&openSeq===refreshGeneration)setReplyStatus(refreshed?'Отправлено':'Отправлено. Переписку пока не удалось обновить.','success');
    }
    // Inbox owns its refresh errors; they are not message-delivery failures.
    if(sameSession())try{await window.WorkspaceV2Inbox?.load({preserveScroll:true})}catch(e){}
  }finally{setBusy(false)}
}
function bind(){
  if(bound)return;bound=true;
  const form=$('composer'),reply=$('replyText');
  form.onsubmit=async e=>{e.preventDefault();await sendReply()};
  // Focusing the send button can collapse mobile quick replies between down/up,
  // moving the native click target. Keep pointer focus stable; keyboard focus and
  // the native click/submit remain unchanged. Never send from a pointer-down event.
  $('sendReply')?.addEventListener('mousedown',e=>{if(e.button===0)e.preventDefault()});
  reply.addEventListener('input',()=>{saveDraft();autoGrow()});
  reply.addEventListener('keydown',e=>{if(e.key==='Enter'&&(e.metaKey||e.ctrlKey)){e.preventDefault();form.requestSubmit()}});
  document.querySelectorAll('.quickReplies [data-reply]').forEach(b=>b.onclick=()=>addQuickReply(b.dataset.reply))
}
window.WorkspaceV2Conversation={bind,open,refreshVisible,activateReplySession,rememberSelection,getSavedSelection:()=>savedSelection,getOpenGeneration:()=>openSeq,suspendForAuthRecovery,resetForIdentityChange,refreshLeadData,renderMessages,renderHeader,renderDeliveryFailure,messageTime,sendReply,saveDraft,restoreDraft,setLoadStatus};
})();
