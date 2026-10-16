(function(){
  if(!document.body.classList.contains('manager2'))return;
  const {S,$}=window.WorkspaceV2;
  const form=$('composer'),reply=$('replyText'),zone=$('conversationZone');
  if(!form||!reply||!zone)return;
  let view=null,scheduled=0;
  const home=document.createComment('Canonical reply form position');
  form.before(home);
  const expand=document.createElement('button');
  expand.id='m2ReplyExpand';expand.type='button';expand.className='m2ReplyExpand';expand.textContent='Развернуть';
  expand.setAttribute('aria-haspopup','dialog');expand.setAttribute('aria-controls','m2ReplyEditor');
  expand.setAttribute('aria-expanded','false');
  form.querySelector('.composerActions').prepend(expand);
  reply.setAttribute('aria-label','Ответ клиенту');
  const dialog=document.createElement('dialog');
  dialog.id='m2ReplyEditor';dialog.className='m2ReplyEditor';
  dialog.setAttribute('aria-labelledby','m2ReplyEditorTitle');dialog.setAttribute('aria-describedby','m2ReplyEditorRecipient');
  dialog.innerHTML='<header class="m2ReplyEditorHead"><div><h2 id="m2ReplyEditorTitle">Ответ клиенту</h2><p id="m2ReplyEditorRecipient"></p></div><button id="m2ReplyCollapse" type="button">Свернуть</button></header><div class="m2ReplyEditorBody"></div>';
  zone.appendChild(dialog);

  function context(){return window.WorkspaceV2Conversation.captureReplyContext()}
  function sameContext(c){const now=context();return Object.keys(now).every(key=>now[key]===c[key])}
  function unavailable(){
    const c=S.detail?.conversation,issue=$('conversationLifecycleStatus');
    return S.authExpired||!S.current||Number(c?.id)!==Number(S.current)||c.status!=='manager'||Number(c.manager_id)!==Number(S.manager?.id)||form.classList.contains('hidden')||S.detail?.delivery_failure?.category==='suspended'||issue&&!issue.classList.contains('hidden');
  }
  function selection(){return {start:reply.selectionStart,end:reply.selectionEnd,direction:reply.selectionDirection,top:reply.scrollTop,left:reply.scrollLeft}}
  function restoreSelection(saved,focus){
    if(focus&&!reply.disabled)reply.focus({preventScroll:true});
    reply.setSelectionRange(saved.start,saved.end,saved.direction);
    reply.scrollTop=saved.top;reply.scrollLeft=saved.left;
  }
  function sizeDialog(){
    const viewport=window.visualViewport,height=viewport?.height||innerHeight,gap=height<=540?8:24;
    dialog.style.setProperty('--m2-editor-height',Math.max(0,height-gap*2)+'px');
    dialog.style.setProperty('--m2-editor-top',((viewport?.offsetTop||0)+gap)+'px');
    dialog.style.setProperty('--m2-editor-media-height',Math.max(40,Math.min(140,height*.18))+'px');
  }
  function fitViewport(){
    const viewport=window.visualViewport;
    const reduced=!!viewport&&window.WorkspaceV2Mobile?.isMobile()&&viewport.scale===1&&viewport.height<innerHeight-60;
    zone.classList.toggle('m2ReplyViewport',!!reduced);
    if(reduced){
      zone.style.setProperty('--m2-reply-visible-height',viewport.height+'px');
      zone.style.setProperty('--m2-reply-visible-top',viewport.offsetTop+'px');
      zone.style.setProperty('--m2-reply-visible-media',Math.min(100,viewport.height*.2)+'px');
    }
  }
  // This presentation owns Manager2 sizing. The original cabinet retains its
  // canonical sizing path; there is no second draft, textarea or submit owner.
  function resize(){
    fitViewport();
    if(view){sizeDialog();reply.style.height='';return true}
    if(form.classList.contains('hidden'))return true;
    const style=getComputedStyle(reply),line=parseFloat(style.lineHeight)||22;
    const padding=parseFloat(style.paddingTop)+parseFloat(style.paddingBottom),border=parseFloat(style.borderTopWidth)+parseFloat(style.borderBottomWidth);
    const height=Math.min(window.visualViewport?.height||innerHeight,zone.getBoundingClientRect().height||innerHeight);
    const minimum=line*(height>540?2:1)+padding+border;
    const saved=selection();reply.style.height='0px';
    const desired=reply.scrollHeight+border;
    const head=zone.querySelector('.conversationHead').getBoundingClientRect().height;
    const reserve=Math.min(160,height*.3),room=height-head-form.getBoundingClientRect().height-reserve;
    const maximum=Math.max(minimum,Math.min(line*(innerWidth<=740?6:8)+padding+border,room));
    reply.style.height=Math.ceil(Math.max(minimum,Math.min(desired,maximum)))+'px';
    reply.scrollTop=saved.top;reply.scrollLeft=saved.left;
    return true;
  }
  function schedule(){if(!scheduled)scheduled=requestAnimationFrame(()=>{scheduled=0;resize()})}
  function close(restoreFocus=true){
    const previous=view;if(!previous)return;
    const saved=selection(),current=sameContext(previous.context)&&!unavailable();
    view=null;home.after(form);dialog.close();expand.setAttribute('aria-expanded','false');$('m2ReplyEditorRecipient').textContent='';
    window.WorkspaceV2Mobile?.closeOverlay('reply-editor');
    // A desktop editor can become mobile while open. Return to its canonical
    // conversation screen rather than leaving the live draft offscreen.
    const mobile=window.WorkspaceV2Mobile;
    if(restoreFocus&&current&&mobile?.isMobile()&&mobile.getScreen()!=='conversation')mobile.conversationOpened(S.current,{historyMode:'replace'});
    resize();restoreSelection(saved,restoreFocus&&current);
  }
  function sync(){
    if(view&&(!sameContext(view.context)||unavailable()))close(false);
    expand.disabled=!!reply.disabled||!!unavailable();
    if(view)$('m2ReplyEditorRecipient').textContent=[S.detail.conversation.display_name||'Клиент',String(S.detail.conversation.channel||'').toUpperCase()].filter(Boolean).join(' · ');
  }
  function open(){
    if(view||reply.disabled||unavailable())return;
    if(window.WorkspaceV2Mobile?.openOverlay('reply-editor',()=>close())===false)return;
    const saved=selection();view={context:context()};
    dialog.querySelector('.m2ReplyEditorBody').appendChild(form);
    sync();sizeDialog();reply.style.height='';
    dialog.showModal();expand.setAttribute('aria-expanded','true');restoreSelection(saved,true);
  }
  expand.addEventListener('click',open);
  $('m2ReplyCollapse').addEventListener('click',()=>close());
  dialog.addEventListener('cancel',event=>{event.preventDefault();close()});
  dialog.addEventListener('close',()=>{if(view&&!dialog.open)close()});
  dialog.addEventListener('keydown',event=>{
    // The canonical emoji handler consumes Escape before the dialog's native
    // cancel action. Textarea Ctrl/Command+Enter still reaches its one submit.
    if(event.key!=='Escape')event.stopPropagation();
  });
  window.addEventListener('resize',schedule);
  window.visualViewport?.addEventListener('resize',schedule);
  window.visualViewport?.addEventListener('scroll',schedule);
  window.addEventListener('pagehide',()=>close(false));
  if(typeof ResizeObserver==='function'){
    const observer=new ResizeObserver(schedule);observer.observe(zone);observer.observe(form);
  }
  window.WorkspaceV2ReplyEditor={resize,sync,close};
  sync();
})();
