(function(){
  'use strict';
  const W=window.WorkspaceV2;
  if(!W||!document.body.classList.contains('manager2'))return;
  const {S,$}=W;
  let context=null,read=null,stamp='',sequence=0,openIntent=0,dialog=null,frame=null,nonce='',opener=null;
  let row=null,label=null,criteria=null,state=null,button=null,dialogState=null,overlayHeld=false,handoffSlot=null,handoffAnchor=null,content=null,profileInMenu=false;
  function restoreHandoff(){const source=$('handoffContext');if(source&&handoffAnchor?.parentNode&&source.parentNode!==handoffAnchor.parentNode)handoffAnchor.parentNode.insertBefore(source,handoffAnchor.nextSibling);}
  function groupHandoff(){const source=$('handoffContext');if(!source||!handoffSlot)return;if(!handoffAnchor){handoffAnchor=document.createComment('Original request context position');source.before(handoffAnchor);}if(source.parentNode!==handoffSlot)handoffSlot.appendChild(source);}
  const capture=()=>{
    const conversation=Number(S.current),manager=Number(S.manager?.id);
    const c=S.detail?.conversation;
    return !S.authExpired&&window.WorkspaceV2Conversation?.hasReadAccess?.()!==false&&manager>0&&conversation>0&&Number(c?.id)===conversation
      &&c.project_key==='anytour'?{conversation,manager,auth:S.authGeneration,owner:Number(c.manager_id||0),status:c.status,role:S.manager.role,channel:c.channel,customer:c.customer_id,customerChannel:c.customer_channel_id}:null;
  };
  const same=(a,b)=>!!a&&!!b&&Object.keys(a).every(key=>a[key]===b[key]);
  const current=c=>same(c,capture());
  function close({restoreFocus=true,destroy=false,fromHistory=false}={}){
    ++openIntent;
    if(overlayHeld&&!fromHistory)window.WorkspaceV2Mobile?.closeOverlay('request-profile');
    overlayHeld=false;
    if(dialog?.open)dialog.close();
    if(destroy){frame?.remove();frame=null;nonce='';}
    if(restoreFocus&&current(context)&&opener?.isConnected){
      if(opener===button&&profileInMenu)window.Manager2Presentation?.focusConversationTool(button.id);
      else opener.focus({preventScroll:true});
    }
  }
  function reset(){
    ++sequence;read?.abort();read=null;stamp='';context=null;close({restoreFocus:false,destroy:true});restoreHandoff();
    if(row){row.hidden=true;row.open=false;label.textContent='';criteria.textContent='';state.textContent='';state.hidden=true;button.disabled=true;button.hidden=true;delete row.dataset.state;}
    if(dialogState){dialogState.textContent='';dialogState.hidden=true;}
  }
  function ensureRow(){
    if(row)return;
    const head=document.querySelector('#conversationZone .conversationHead');
    if(!head)return;
    row=document.createElement('details');row.id='m2CurrentTrip';row.className='m2CurrentTrip';row.hidden=true;
    const summary=document.createElement('summary'),title=document.createElement('span');title.className='m2RequestTitle';title.textContent='Запрос клиента';summary.appendChild(title);
    label=document.createElement('strong');criteria=document.createElement('small');summary.append(label,criteria);row.appendChild(summary);
    content=document.createElement('div');content.className='m2RequestContent';state=document.createElement('span');state.id='m2RequestStatus';state.className='m2RequestStatus';state.setAttribute('role','status');state.setAttribute('aria-live','polite');content.appendChild(state);
    button=document.createElement('button');button.id='m2RequestProfileOpen';button.type='button';button.className='actionBtn';button.textContent='Уточнить запрос';button.hidden=true;
    button.setAttribute('aria-describedby',state.id);
    button.setAttribute('aria-haspopup','dialog');button.addEventListener('click',()=>open(button));content.appendChild(button);
    handoffSlot=document.createElement('div');handoffSlot.className='m2RequestSource';content.appendChild(handoffSlot);row.appendChild(content);
    // The trip belongs to the conversation, never to an optional AI panel.
    head.after(row);
    profileInMenu=window.Manager2Presentation?.registerConversationTool(button)===true;
  }
  function placeSummary(){
    row.hidden=!criteria.textContent&&profileInMenu;state.hidden=false;
    state.classList.toggle('m2RequestMenuStatus',row.hidden);
    if(row.hidden){row.open=false;if(state.parentElement!==button.parentElement)button.before(state);}
    else if(state.parentElement!==content)content.prepend(state);
  }
  function render(profile){
    if(!row)return;
    const states={fresh:'Запрос сверен с перепиской',stale:'Есть новые сообщения. Сверьте запрос.',source_changed:'Исходные данные изменились. Сверьте запрос.'};
    const name=typeof profile?.label==='string'?profile.label.trim():'';label.textContent=name==='Текущая поездка'?'':name;label.hidden=!label.textContent;
    const party=profile?.fields?.party?.value,parts=[];
    const dates=profile?.fields?.dates?.value,budget=profile?.fields?.budget?.value;
    if(typeof dates==='string'&&dates.trim())parts.push(dates.trim());
    if(party&&Number.isSafeInteger(party.adults)&&party.adults>0){
      let text=party.adults+' взр.';
      if(Number.isSafeInteger(party.children)&&party.children>0){
        const agesKnown=Array.isArray(party.child_ages)&&party.child_ages.length===party.children&&party.child_ages.every(age=>Number.isSafeInteger(age)&&age>=0);
        text+=' + '+party.children+' дет.'+(agesKnown?' ('+party.child_ages.join(', ')+')':' — возраст уточняется');
      }else if(!Number.isSafeInteger(party.children))text+=' · дети уточняются';
      parts.push(text);
    }
    if(typeof budget==='string'&&budget.trim())parts.push(budget.trim());
    for(const key of ['departure','destination','nights','hotel','room_meal','must_have']){
      const field=profile?.fields?.[key];
      if(typeof field?.value==='string'&&field.value.trim())parts.push(field.value.trim()+(field.kind==='manager_interpretation'?' (версия менеджера)':''));
    }
    const original=typeof S.detail?.handoff_context?.summary==='string'?S.detail.handoff_context.summary.trim():'';
    criteria.textContent=parts.join(' · ')||original.slice(0,180)+(original.length>180?'…':'');criteria.hidden=!criteria.textContent;
    state.textContent=states[profile?.status]||'Сохранённого запроса пока нет. Уточните его по переписке.';
    row.dataset.state=profile?.status==='fresh'?'fresh':'review';placeSummary();
  }
  async function load(){
    if(!current(context))return false;
    const saved=context,ticket=++sequence;read?.abort();read=new AbortController();
    render(null);state.textContent='Загружаем запрос…';button.disabled=false;
    try{
      const r=await W.request('request-profile-api.php','get',{conversation_id:saved.conversation},
        {signal:read.signal,isCurrent:()=>ticket===sequence&&current(saved)});
      if(ticket!==sequence||!current(saved))return false;
      if(r.ok&&r.viewer_id!==saved.manager){reset();W.showAuthRecovery('Аккаунт изменился. Войдите снова, чтобы открыть условия этой поездки.');return false;}
      if(r.ok&&r.viewer_id===saved.manager&&r.conversation_id===saved.conversation&&r.profile){render(r.profile);return true;}
      else{render(null);if([403,404].includes(r.http_status)){button.disabled=true;state.textContent='Профиль недоступен в этом диалоге';close({restoreFocus:false,destroy:true});}}
    }catch(error){if(ticket===sequence&&current(saved)&&error.name!=='AbortError'){render(null);state.textContent='Не удалось загрузить условия. Откройте профиль для повторной проверки.';}}
    finally{if(ticket===sequence)read=null;}
    return false;
  }
  function sync(){
    const next=capture();
    if(!next){reset();return;}
    ensureRow();if(!row)return;
    if(!same(context,next)){reset();context=next;}
    button.hidden=false;placeSummary();groupHandoff();
    const messages=S.detail?.messages||[];
    const nextStamp=JSON.stringify([next,messages.map(m=>[m.id,m.direction,m.sender_type,m.text,m.created_at,m.updated_at,m.metadata,m.attachments,m.deleted_at])]);
    if(stamp!==nextStamp){stamp=nextStamp;void load();}
  }
  function ensureDialog(){
    if(dialog)return;
    dialog=document.createElement('dialog');dialog.id='m2RequestProfilePanel';dialog.className='m2RequestProfilePanel';
    dialog.setAttribute('aria-labelledby','m2RequestProfileTitle');
    const header=document.createElement('header'),copy=document.createElement('div'),title=document.createElement('h2');title.id='m2RequestProfileTitle';title.textContent='Запрос клиента';
    dialogState=document.createElement('p');dialogState.className='m2RequestProfileState';dialogState.hidden=true;dialogState.setAttribute('role','status');dialogState.setAttribute('aria-live','polite');copy.append(title,dialogState);
    const done=document.createElement('button');done.type='button';done.className='actionBtn';done.textContent='Готово';done.addEventListener('click',()=>close());
    header.append(copy,done);dialog.appendChild(header);
    dialog.addEventListener('cancel',event=>{event.preventDefault();close();});
    dialog.addEventListener('close',()=>{if(!dialog.open&&overlayHeld)close();});
    document.body.appendChild(dialog);
  }
  async function open(source=document.activeElement){
    sync();if(!current(context)||button?.disabled)return false;
    const opening=context,intent=++openIntent,screen=window.WorkspaceV2Mobile?.getScreen(),navigation=window.WorkspaceV2Conversation?.getOpenGeneration();
    // Cached unsaved form state is reusable only after a fresh authorized read.
    // A cookie/account can change outside this tab while the frame is closed.
    if(!await load()||!current(opening)||intent!==openIntent||screen!==window.WorkspaceV2Mobile?.getScreen()
      ||navigation!==window.WorkspaceV2Conversation?.getOpenGeneration())return false;
    ensureDialog();opener=source;
    if(!dialog.open){
      const token=window.WorkspaceV2Mobile?.openOverlay('request-profile',()=>close({fromHistory:true}));
      if(token===false)return false;
      overlayHeld=!!token;
    }
    if(!frame){
      nonce=crypto.randomUUID().replaceAll('-','');
      frame=document.createElement('iframe');frame.title='Уточнение запроса клиента';frame.name='request-profile-'+nonce;
      const url=new URL('/manager2/request-profile.php',location.origin);
      url.searchParams.set('conversation',String(context.conversation));url.searchParams.set('workspace','1');
      url.searchParams.set('viewer',String(context.manager));url.searchParams.set('panel',nonce);frame.src=url.href;
      dialog.appendChild(frame);
    }
    if(!dialog.open)dialog.showModal();
    return true;
  }
  document.addEventListener('click',event=>{
    const link=event.target.closest?.('[data-request-profile] a');
    if(!link||event.defaultPrevented||event.button!==0||event.ctrlKey||event.metaKey||event.shiftKey||event.altKey)return;
    if(capture()){event.preventDefault();void open(link);}
  });
  window.addEventListener('message',event=>{
    const data=event.data;
    if(event.origin!==location.origin||event.source!==frame?.contentWindow||!current(context)||!nonce
      ||!data||data.type!=='anytour.request-profile'||data.panel!==nonce
      ||data.conversation_id!==context.conversation||data.viewer_id!==context.manager)return;
    if(data.action==='saved'){
      dialogState.textContent='Профиль сохранён. Проверьте ответ с учётом новых условий.';dialogState.hidden=false;
      window.WorkspaceV2AiDraft?.resetSession();window.WorkspaceV2AiDraft?.sync();
      void load();
    }else if(data.action==='editing'){
      dialogState.textContent='Есть изменения. Сохраните профиль или проверьте результат записи.';dialogState.hidden=false;
    }else if(data.action==='loaded')void load();
    else if(data.action==='close')close();
    else if(data.action==='access-lost'){close({restoreFocus:false,destroy:true});void load();}
  });
  window.addEventListener('pagehide',reset);
  window.addEventListener('popstate',()=>{++openIntent;});
  document.addEventListener('keydown',event=>{if(event.key==='Escape'&&!dialog?.open)++openIntent;});
  window.WorkspaceV2RequestProfile={open,sync,reset,close,refresh:()=>{sync();if(current(context)&&!read)void load();},conversationChanging:target=>{if(Number(target)!==context?.conversation)reset();}};
  sync();
})();
