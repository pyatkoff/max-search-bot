(function(){
const W=window.WorkspaceV2,{S,$,api,pipe,statusText}=W;let bound=false,busy=false,openSeq=0;const drafts=new Map();
function initials(name){const parts=String(name||'Турист').trim().split(/\s+/).filter(Boolean);return parts.slice(0,2).map(x=>x.charAt(0).toUpperCase()).join('')||'Т'}
function messageTime(value){const s=String(value||'').trim(),m=s.match(/^(\d{4})-(\d{2})-(\d{2})[ T](\d{2}):(\d{2})/);return m?`${m[3]}.${m[2]} · ${m[4]}:${m[5]}`:s}
function setReplyStatus(text='',kind=''){const el=$('replyStatus');if(!el)return;el.textContent=text;el.className='replyStatus'+(kind?' '+kind:'')}
function ensureLoadStatus(){let el=$('conversationLoadStatus');if(el)return el;el=document.createElement('div');el.id='conversationLoadStatus';el.className='conversationLoadStatus hidden';el.setAttribute('aria-live','polite');const zone=$('conversationZone'),head=zone?.querySelector('.conversationHead');if(zone&&head)head.insertAdjacentElement('afterend',el);return el}
function setLoadStatus(text='',kind=''){const el=ensureLoadStatus();if(!el)return;el.textContent=text;el.className='conversationLoadStatus'+(kind?' '+kind:'')+(text?'':' hidden')}
function setBusy(value){busy=!!value;applyInteractionState()}
function deliverySuspended(){return String(S.detail?.delivery_failure?.category||'')==='suspended'}
function applyComposerState(){const suspended=deliverySuspended(),send=$('sendReply'),reply=$('replyText'),file=$('replyFile');if(send){send.disabled=busy||suspended;send.textContent=busy?'Отправляем…':'Отправить'}if(reply)reply.disabled=busy||suspended;if(file)file.disabled=busy||suspended}
function applyActionState(){document.querySelectorAll('#conversationActions button').forEach(b=>{b.disabled=busy})}
function applyInteractionState(){applyComposerState();applyActionState()}
function renderDeliveryFailure(failure){const el=$('deliveryFailure');if(!el)return;const f=failure||null;if(!f){el.textContent='';el.classList.add('hidden');applyInteractionState();return}const message=String(f.message||f.error_message||'Сообщение клиенту не доставлено.');el.textContent=message;el.classList.remove('hidden');el.classList.toggle('suspended',String(f.category||'')==='suspended');applyInteractionState()}
function autoGrow(){const el=$('replyText');if(!el)return;el.style.height='auto';el.style.height=Math.min(150,Math.max(38,el.scrollHeight))+'px'}
function saveDraft(id=S.current){const reply=$('replyText'),key=Number(id||0);if(!reply||!key)return;const text=reply.value;if(text)drafts.set(key,text);else drafts.delete(key)}
function restoreDraft(id=S.current){const reply=$('replyText'),key=Number(id||0);if(!reply)return;reply.value=key?(drafts.get(key)||''):'';autoGrow()}
function looksLikeImage(a,url){const probe=String(a?.name||url||'').split('?')[0].toLowerCase();return a?.type==='image'||/\.(png|jpe?g|gif|webp|bmp|avif)$/.test(probe)}
function mediaFallback(node,a,url,label='Вложение'){node.onerror=()=>{const link=document.createElement('a');link.textContent='📎 '+(a?.name||label);if(url){link.href=url;link.target='_blank';link.rel='noopener'}node.replaceWith(link)}}
function renderAttachments(root,items){if(!Array.isArray(items)||!items.length)return;const wrap=document.createElement('div');wrap.className='attachments';items.forEach(a=>{const url=String(a?.url||'');let n;if((a.type==='image'||looksLikeImage(a,url))&&url){n=document.createElement('img');n.src=url;n.loading='lazy';n.alt=a?.name||'Изображение';mediaFallback(n,a,url,'Изображение')}else if(a.type==='video'&&url){n=document.createElement('video');n.src=url;n.controls=true;mediaFallback(n,a,url,'Видео')}else if(a.type==='audio'&&url){n=document.createElement('audio');n.src=url;n.controls=true}else{n=document.createElement('a');n.textContent='📎 '+(a.name||'Вложение');if(url){n.href=url;n.target='_blank';n.rel='noopener'}}wrap.appendChild(n)});root.appendChild(wrap)}
function renderMessages(messages,{stickToBottom=false,preserveScroll=false}={}){const box=$('messages'),distanceFromBottom=Math.max(0,box.scrollHeight-box.scrollTop-box.clientHeight);const frag=document.createDocumentFragment();(messages||[]).forEach(m=>{const n=document.createElement('div');const who=m.sender_type==='customer'?'customer':m.sender_type==='manager'?'manager':'ai',whoLabel=who==='customer'?'Турист':who==='manager'?'Менеджер':'AI';n.className='msg '+who;n.dataset.sender=who;const sender=document.createElement('span');sender.className='messageSender';sender.textContent=whoLabel;n.appendChild(sender);const body=document.createElement('div');body.className='msgBody';body.textContent=m.text||'';n.appendChild(body);renderAttachments(n,m.attachments||[]);const meta=document.createElement('div');meta.className='msgMeta';meta.textContent=messageTime(m.created_at||'');meta.title=m.created_at||'';n.appendChild(meta);frag.appendChild(n)});if(!frag.childNodes.length){const empty=document.createElement('div');empty.className='conversationEmpty';empty.innerHTML='<div class="conversationEmptyIcon">💬</div><strong>Сообщений пока нет</strong><span>История диалога появится здесь.</span>';frag.appendChild(empty)}box.replaceChildren(frag);if(stickToBottom)box.scrollTop=box.scrollHeight;else if(preserveScroll)box.scrollTop=Math.max(0,box.scrollHeight-box.clientHeight-distanceFromBottom)}
function renderHeader(c){const name=c.display_name||'Турист';$('conversationTitle').textContent=name;$('conversationAvatar').textContent=initials(name);const state=$('conversationState');state.textContent=statusText(c.status);state.className='conversationState '+String(c.status||'');const origin=[c.source_name,(c.channel||'').toUpperCase()].filter(Boolean).join(' · ');$('conversationMeta').textContent=[origin,c.manager_name?`Менеджер: ${c.manager_name}`:''].filter(Boolean).join(' · ')}
async function open(id,options={}){
  const previous=Number(S.current||0),target=Number(id||0),switching=target!==previous;
  if(!target)return false;
  if(switching&&window.WorkspaceV2Tasks?.blockNavigationForDirtyDraft?.())return false;
  const seq=++openSeq;
  if(switching&&previous)saveDraft(previous);
  setLoadStatus(switching?'Открываем лид…':'Обновляем диалог…','loading');
  let d,p;
  try{[d,p]=await Promise.all([api('detail',{conversation_id:target}),pipe('detail',{conversation_id:target})])}
  catch(e){if(seq!==openSeq)return false;if(!S.authExpired)setLoadStatus(switching?'Не удалось открыть лид. Текущий диалог не изменён.':'Не удалось обновить диалог. На экране остаются предыдущие данные.','error');return false}
  if(seq!==openSeq)return false;
  if(!d?.ok||!p?.ok){setLoadStatus(switching?'Не удалось открыть лид. Текущий диалог не изменён.':'Не удалось обновить диалог. На экране остаются предыдущие данные.','error');return false}
  S.current=target;S.detail={...d,lead:p};setReplyStatus();setLoadStatus();window.WorkspaceV2Media?.configure(S.csrf,S.current);window.WorkspaceV2Media?.clear();window.WorkspaceV2Inbox?.markRead(S.current);
  const c=d.conversation;renderHeader(c);renderMessages(d.messages||[],{stickToBottom:options.stickToBottom===true||switching,preserveScroll:options.preserveMessageScroll===true&&!switching});renderDeliveryFailure(d.delivery_failure||null);window.WorkspaceV2LeadCard?.render(p,c);renderActions(c);if(switching)restoreDraft(S.current);window.WorkspaceV2Inbox?.markActive(S.current);if(window.WorkspaceV2Mobile?.isMobile())window.WorkspaceV2Mobile.conversationOpened(S.current,{historyMode:options.mobileHistory||'push'});else $('conversationZone').classList.add('open');return true
}
async function refreshLeadData({refreshInbox=false,conversationId=S.current}={}){const target=Number(conversationId||0);if(!target)return false;const p=await pipe('detail',{conversation_id:target});if(!p?.ok)return false;const stillCurrent=Number(S.current)===target&&!!S.detail?.conversation;if(stillCurrent){S.detail.lead=p;window.WorkspaceV2LeadCard?.render(p,S.detail.conversation)}if(refreshInbox)await window.WorkspaceV2Inbox?.load({preserveScroll:true});return stillCurrent}
function renderActions(c){const root=$('conversationActions'),locked=$('composerLocked'),composer=$('composer');root.innerHTML='';const own=Number(c.manager_id)===Number(S.manager.id)&&c.status==='manager',canTake=(c.status==='waiting_manager'||c.status==='ai')&&!c.manager_id&&(S.manager.role==='admin'||S.manager.is_working),suspended=deliverySuspended();if(canTake)action('Взять','primary',()=>change('take'));if(own){action('Вернуть AI','',()=>change('release'));action('Закрыть','',()=>change('close'))}if(c.status==='closed')action('Переоткрыть','primary',()=>change('reopen'));composer.classList.toggle('hidden',!own);locked.classList.toggle('hidden',own&&!suspended);if(!own){if(c.status==='closed')locked.innerHTML='<strong>Диалог закрыт.</strong> Переоткройте его, чтобы продолжить общение.';else if(canTake)locked.innerHTML='<strong>Чтобы ответить туристу, возьмите лид.</strong> Переписку можно читать без назначения.';else if(c.manager_name)locked.innerHTML=`Лид сейчас у менеджера <strong>${W.esc(c.manager_name)}</strong>.`;else locked.innerHTML='Ответ сейчас недоступен для этого диалога.'}else if(suspended){locked.innerHTML='<strong>Отправка временно заблокирована.</strong> Клиент недоступен в MAX; дождитесь нового входящего сообщения.'}else locked.textContent='';applyInteractionState();autoGrow()}
function action(text,cl,fn){const b=document.createElement('button');b.className='actionBtn '+cl;b.type='button';b.textContent=text;b.disabled=busy;b.onclick=fn;$('conversationActions').appendChild(b)}
const lifecycleCopy={take:{loading:'Берём лид…',success:'Лид назначен вам',error:'Не удалось взять лид'},release:{loading:'Возвращаем лид AI…',success:'Лид возвращён AI',error:'Не удалось вернуть лид AI'},close:{loading:'Закрываем диалог…',success:'Диалог закрыт',error:'Не удалось закрыть диалог'},reopen:{loading:'Переоткрываем диалог…',success:'Диалог снова в работе',error:'Не удалось переоткрыть диалог'}};
async function change(a){
  if(busy)return;
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
  const target=Number(S.current||0),generation=openSeq,text=$('replyText').value.trim(),hasFile=window.WorkspaceV2Media?.hasFile();
  if(!target||(!text&&!hasFile))return;
  setBusy(true);setReplyStatus('Отправляем сообщение…');
  try{
    let j;if(hasFile)j=await window.WorkspaceV2Media.send(text);else j=await api('send',{conversation_id:target,text});
    const stillCurrent=openSeq===generation&&Number(S.current)===target;
    if(!j?.ok){
      const failure=j?.failure||null;
      if(stillCurrent&&failure&&S.detail?.conversation){S.detail.delivery_failure=failure;renderDeliveryFailure(failure);renderActions(S.detail.conversation)}
      if(stillCurrent&&!S.authExpired)setReplyStatus(j?.error_message||failure?.message||'Не удалось отправить сообщение','error');
      return
    }
    drafts.delete(target);
    if(stillCurrent){
      $('replyText').value='';autoGrow();
      const refreshed=await open(target,{stickToBottom:true,mobileHistory:'none'});
      if(refreshed){const statusGeneration=openSeq;setReplyStatus('Отправлено','success');setTimeout(()=>{if(!busy&&Number(S.current)===target&&openSeq===statusGeneration)setReplyStatus()},1400)}
    }
    await window.WorkspaceV2Inbox?.load({preserveScroll:true})
  }catch(e){
    if(!S.authExpired&&openSeq===generation&&Number(S.current)===target)setReplyStatus('Не удалось отправить сообщение','error')
  }finally{setBusy(false)}
}
function bind(){if(bound)return;bound=true;const form=$('composer'),reply=$('replyText');form.onsubmit=async e=>{e.preventDefault();await sendReply()};reply.addEventListener('input',()=>{saveDraft();autoGrow()});reply.addEventListener('keydown',e=>{if(e.key==='Enter'&&(e.metaKey||e.ctrlKey)){e.preventDefault();form.requestSubmit()}});document.querySelectorAll('.quickReplies [data-reply]').forEach(b=>b.onclick=()=>{reply.value=b.dataset.reply||'';saveDraft();autoGrow();reply.focus()})}
window.WorkspaceV2Conversation={bind,open,refreshLeadData,renderMessages,renderHeader,renderDeliveryFailure,messageTime,sendReply,saveDraft,restoreDraft,setLoadStatus};
})();