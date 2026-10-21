(function(){
  const W=window.WorkspaceV2;
  if(!W)return;
  const {S,$}=W;
  let bound=false,view=null,flight=null,sequence=0;
  let button,panel,status,body,insert,retry;
  let ready=null,watch=null,statusFlight=null,watchTimer=null,expiryTimer=null,notice,noticeLabel;
  const dismissedNotices=new Set(),manager2=()=>document.body.classList.contains('manager2');
  const visible=()=>document.visibilityState!=='hidden';
  const conversation=()=>window.WorkspaceV2Conversation;
  const context=()=>conversation()?.captureReplyContext();
  const latestMessageId=()=>Array.isArray(S.detail?.messages)?S.detail.messages.reduce((latest,message)=>{const id=Number(message.id);return Number.isSafeInteger(id)&&id>latest?id:latest},0):0;
  const messageState=()=>JSON.stringify(S.detail?.messages||[]);
  const validDraftId=value=>typeof value==='string'&&/^[a-f0-9]{32}$/.test(value);
  function canCompose(){
    const form=$('composer'),reply=$('replyText'),c=S.detail?.conversation,issue=$('conversationLifecycleStatus');
    return !!form&&!!reply&&!form.hidden&&!form.classList.contains('hidden')&&!reply.disabled&&!S.authExpired&&!!S.manager?.id&&!!S.current&&Number(c?.id)===Number(S.current)&&c.status==='manager'&&Number(c.manager_id)===Number(S.manager.id)&&S.detail?.delivery_failure?.category!=='suspended'&&!(issue&&!issue.classList.contains('hidden'));
  }
  function sameContext(saved){
    const current=context();
    return !!saved&&!!current&&Object.keys(current).every(key=>current[key]===saved[key]);
  }
  function snapshot(){return {context:context(),latest:latestMessageId(),messages:messageState()}}
  function currentSnapshot(saved){return canCompose()&&sameContext(saved?.context)&&saved.latest===latestMessageId()&&saved.messages===messageState()}
  function timeValue(value){const parsed=window.ManagerTime?.parse?.(value)||new Date(String(value||'').replace(' ','T')+(/(?:Z|[+-]\d{2}:?\d{2})$/.test(String(value||''))?'':'Z'));return Number.isNaN(parsed.getTime())?0:parsed.getTime()}
  function timeLabel(value){return window.ManagerTime?.format?window.ManagerTime.format(value)+' '+window.ManagerTime.label:''}
  function hideNotice(hidden){if(notice&&notice.hidden!==hidden)notice.hidden=hidden}
  function clearReady(){ready=null;window.clearTimeout?.(expiryTimer);expiryTimer=null;hideNotice(true)}
  function noticeKey(){return ready?JSON.stringify([S.manager?.id,S.current,ready.revision,ready.prepared_at]):''}
  function dismissNotice(){dismissedNotices.add(noticeKey());if(dismissedNotices.size>100)dismissedNotices.delete(dismissedNotices.values().next().value);hideNotice(true)}
  function syncPreferences(){
    if(!notice)return;
    hideNotice(!manager2()||!visible()||!ready||ready.kind!=='prepared'||ready.expires<=Date.now()||!ready.should_draft||!currentSnapshot(ready.snapshot)||!panel.hidden||dismissedNotices.has(noticeKey())||window.WorkspaceV2PersonalWorkspace?.showAiReadyNotice?.()===false);
  }
  function renderButton(){
    if(!bound||!manager2())return;
    let state='idle',label='AI',hint='Подготовить ответ по переписке, исходному посту и фото.';
    if(ready&&currentSnapshot(ready.snapshot)&&ready.expires>Date.now()){
      state=ready.kind;label=ready.should_draft?'AI — Ответ готов':'AI — Рекомендации';
      hint=(state==='prepared'?'Подготовлен автоматически':'Сформирован по нажатию')+(timeLabel(ready.prepared_at)?': '+timeLabel(ready.prepared_at):'')+'. Нажмите, чтобы проверить '+(ready.should_draft?'черновик.':'рекомендации.');
    }else if(view?.stale){state='stale';label='AI — Обновить';hint='Переписка изменилась. Прежний черновик нужно обновить.'}
    if(flight){state='loading';label=flight.action==='generate'?'AI — Готовим ответ':'AI — Проверяем';hint=flight.action==='generate'?'Готовим новый вариант по вашему нажатию.':'Проверяем готовый ответ и актуальность переписки.'}
    if(button.textContent!==label)button.textContent=label;
    button.dataset.aiState=state;button.title=hint;button.setAttribute('aria-label',label+'. '+hint);syncPreferences();
  }
  function rememberReady(result,saved,kind){
    if(!manager2()||!currentSnapshot(saved))return;
    const expires=kind==='prepared'?timeValue(result.expires_at):Date.now()+600000;
    if(!expires||expires<=Date.now())return;
    clearReady();ready={...result,snapshot:saved,kind,expires,prepared_at:result.prepared_at||new Date().toISOString()};
    const captured=ready;
    expiryTimer=window.setTimeout?.(()=>{if(ready!==captured)return;clearReady();if(view?.result?.prepared===true){view.result=null;clearBody();status.textContent='Срок хранения готового ответа истёк. Проверьте наличие нового ответа.'}renderButton()},Math.min(expires-Date.now(),2147483647));
    renderButton();
  }
  function abortStatusRead(){const previous=statusFlight;statusFlight=null;previous?.controller?.abort();window.clearTimeout?.(watchTimer);watchTimer=null}
  function stopWatching(){abortStatusRead();watch=null;clearReady()}
  function scheduleStatus(saved){
    if(watch!==saved||saved.blocked||!visible()||!currentSnapshot(saved.snapshot)||Date.now()>=saved.until)return;
    window.clearTimeout?.(watchTimer);watchTimer=window.setTimeout?.(()=>{watchTimer=null;void readStatus(saved)},15000);
  }
  async function readStatus(saved){
    if(watch!==saved||saved.blocked||!visible()||!currentSnapshot(saved.snapshot)||Date.now()>=saved.until||statusFlight)return;
    if(flight){scheduleStatus(saved);return}
    const current={controller:typeof AbortController==='function'?new AbortController():null};statusFlight=current;
    const isCurrent=()=>watch===saved&&statusFlight===current&&visible()&&currentSnapshot(saved.snapshot);
    try{
      const result=await W.request('ai-draft.php','prepared_status',{conversation_id:saved.snapshot.context.conversationId,latest_message_id:saved.snapshot.latest},{signal:current.controller?.signal,isCurrent});
      if(!isCurrent())return;
      if(result?.http_status===403||result?.http_status===404){saved.blocked=true;clearReady();resetSession({keepReady:true});return}
      if(result?.ok===true&&result.prepared===true&&typeof result.should_draft==='boolean'&&typeof result.revision==='string'&&result.revision&&result.latest_message_id===saved.snapshot.latest){
        if(ready?.kind!=='generated')rememberReady(result,saved.snapshot,'prepared');
      }else if(ready?.kind!=='generated')clearReady();
      renderButton();
    }catch(error){if(isCurrent()&&ready?.kind!=='generated'){clearReady();renderButton()}}
    finally{if(statusFlight===current){statusFlight=null;scheduleStatus(saved)}}
  }
  function syncWatch(){
    // A server predating the new preference does not advertise readiness support.
    if(!manager2()||!canCompose()||!visible()||window.WorkspaceV2PersonalWorkspace?.supportsAiReadiness?.()===false){stopWatching();return}
    if(watch&&currentSnapshot(watch.snapshot))return;
    stopWatching();watch={snapshot:snapshot(),until:Date.now()+600000};void readStatus(watch);
  }
  function selection(){const reply=$('replyText');return {start:reply.selectionStart,end:reply.selectionEnd,direction:reply.selectionDirection,top:reply.scrollTop,left:reply.scrollLeft}}
  function restoreSelection(saved){
    const reply=$('replyText');if(!saved||!canCompose())return;
    reply.focus({preventScroll:true});reply.setSelectionRange(saved.start,saved.end,saved.direction);reply.scrollTop=saved.top;reply.scrollLeft=saved.left;
  }
  function rememberSelection(){if(view)view.selection=selection()}
  function positionPanel(){
    if(!view||panel.hidden)return;
    const rect=$('composer').getBoundingClientRect?.();if(!rect)return;
    const top=window.visualViewport?.offsetTop||0;
    panel.style.setProperty('--ai-draft-room',Math.max(0,rect.top-top-8)+'px');
  }
  function setBusy(value){
    panel.setAttribute('aria-busy',String(value));
    insert.disabled=value||!view?.result?.should_draft;retry.disabled=value;
    const disabled=!canCompose()||value;if(button.disabled!==disabled)button.disabled=disabled;
    renderButton();
  }
  function clearBody(){body.replaceChildren();insert.hidden=true;insert.disabled=true}
  function abortFlight(){sequence++;const previous=flight;flight=null;previous?.controller?.abort()}
  function resetSession({keepReady=false}={}){
    abortFlight();view=null;
    if(!keepReady)stopWatching();
    if(!bound)return;
    panel.hidden=true;status.textContent='';clearBody();button.setAttribute('aria-expanded','false');setBusy(false);
  }
  function close(restoreFocus=true){
    const previous=view,mayFocus=previous&&sameContext(previous.snapshot.context)&&canCompose();
    resetSession({keepReady:true});if(restoreFocus&&mayFocus)restoreSelection(previous.selection);
  }
  function stale(){
    if(!view||view.stale)return;
    abortFlight();view.stale=true;view.result=null;clearBody();
    clearReady();
    status.textContent='Переписка изменилась. Сформируйте ответ заново.';setBusy(false);
  }
  function sync(){
    if(!bound)return;
    if(view&&(!canCompose()||!sameContext(view.snapshot.context))){resetSession();return}
    if(view&&!currentSnapshot(view.snapshot))stale();
    const disabled=!canCompose()||!!flight;if(button.disabled!==disabled)button.disabled=disabled;
    syncWatch();renderButton();
    positionPanel();
  }
  function addText(tag,className,text){
    const node=document.createElement(tag);node.className=className;node.textContent=text;body.appendChild(node);return node;
  }
  function readable(value){
    if(typeof value==='string')return value;
    if(value&&typeof value==='object')return String(value.text||value.message||value.label||'');
    return '';
  }
  function renderResult(result){
    clearBody();
    const actions={clarify:'Уточнить запрос',answer_question:'Ответить на вопрос',check_offer:'Проверить данные',prepare_selection:'Подготовить подборку',no_contact:'Сейчас не писать'};
    const rawAction=readable(result.next_action),action=actions[rawAction]||rawAction,reason=readable(result.reason);
    if(result.should_draft){addText('p','aiDraftText',result.draft);insert.hidden=false}
    if(action)addText('p','aiDraftAction',action);
    if(reason)addText('p','aiDraftReason',reason);
    if(result.history_incomplete)addText('p','aiDraftWarning','В доступной истории есть пропуски. Проверьте контекст перед ответом.');
    if(Array.isArray(result.checks)&&result.checks.length){
      const list=addText('ul','aiDraftChecks','');
      for(const check of result.checks){const text=readable(check);if(!text)continue;const item=document.createElement('li');item.textContent=text;list.appendChild(item)}
    }
    const prepared=result.prepared===true&&result.should_draft?'Ответ подготовлен заранее. ':'';
    status.textContent=prepared+(result.should_draft?'Проверьте текст. Добавим в конец ответа.':rawAction==='no_contact'?'Сейчас отвечать не нужно.':'Перед ответом нужно проверить данные. Причина и следующий шаг — ниже.');
    retry.textContent=result.should_draft?'Другой вариант':'Повторить подготовку';
  }
  function failure(result,action){
    clearBody();view.result=null;
    if(['context_changed','context_stale','stale_context','stale_revision','revision_changed'].includes(result?.error)){stale();return}
    if(action==='prepared'){
      if(result?.http_status===403||result?.http_status===404){resetSession();return}
      status.textContent='Не удалось проверить готовый ответ. Ваш текст и вложения сохранены. Можно сформировать ответ заново.';return;
    }
    if(result?.error==='provider_not_configured'){status.textContent='AI сейчас недоступен. Ваш текст и вложения сохранены.';return}
    if(result?.error==='provider_timeout'){status.textContent='Подготовка ответа заняла слишком много времени. Нажмите «Повторить подготовку».';return}
    status.textContent='Не удалось подготовить ответ. Ваш текст и вложения сохранены. Можно повторить по кнопке.';
  }
  function validResult(result,saved){
    return result?.ok===true&&typeof result.revision==='string'&&!!result.revision&&Number.isSafeInteger(result.latest_message_id)&&result.latest_message_id===saved.latest&&typeof result.should_draft==='boolean'&&(!result.should_draft||typeof result.draft==='string'&&!!result.draft.trim());
  }
  function openReady(){
    if(ready?.kind==='generated'&&ready.expires>Date.now()&&currentSnapshot(ready.snapshot)&&validResult(ready,ready.snapshot)){
      const result=ready;abortStatusRead();abortFlight();view={snapshot:result.snapshot,selection:selection(),result,stale:false};panel.hidden=false;button.setAttribute('aria-expanded','true');renderResult(result);setBusy(false);positionPanel();panel.focus({preventScroll:true});if(watch)scheduleStatus(watch);return;
    }
    void loadProposal('prepared',true);
  }
  async function loadProposal(action,generateIfNeeded=false){
    if(!canCompose()||flight)return false;
    abortStatusRead();dismissNotice();
    const saved=snapshot(),savedSelection=selection();abortFlight();
    view={snapshot:saved,selection:savedSelection,result:null,stale:false};
    panel.hidden=false;button.setAttribute('aria-expanded','true');clearBody();retry.textContent='Повторить подготовку';status.textContent='Читаем переписку и фото, готовим ответ…';
    const current={ticket:sequence,action,controller:typeof AbortController==='function'?new AbortController():null};flight=current;setBusy(true);positionPanel();panel.focus({preventScroll:true});
    const isCurrent=()=>flight===current&&current.ticket===sequence&&!!view&&currentSnapshot(saved);
    try{
      const request=kind=>W.request('ai-draft.php',kind,{conversation_id:saved.context.conversationId,latest_message_id:saved.latest},{signal:current.controller?.signal,isCurrent});
      let result=await request(action);
      if(!isCurrent()){sync();return false}
      const staleError=['context_changed','context_stale','stale_context','stale_revision','revision_changed'].includes(result?.error);
      const cacheMiss=result?.ok===true&&result.prepared===false;
      const needsReply=validResult(result,saved)&&result.prepared===true&&!result.should_draft&&result.next_action!=='no_contact';
      const cacheUnavailable=Number(result?.http_status)>=500||result?.error==='prepared_unavailable';
      if(generateIfNeeded&&action==='prepared'&&!staleError&&(cacheMiss||needsReply||cacheUnavailable)){
        clearReady();action='generate';current.action=action;setBusy(true);
        result=await request(action);
        if(!isCurrent()){sync();return false}
      }
      if(action==='prepared'&&result?.ok===true&&result.prepared===false){clearReady();status.textContent='Готового ответа пока нет. Можно сформировать ответ заново.';return false}
      if(!validResult(result,saved)||action==='prepared'&&result.prepared!==true){if(result?.ok&&result.latest_message_id!==saved.latest)stale();else failure(result,action);return false}
      // Both sources carry server-owned provenance through fresh validation.
      view.result=result;renderResult(view.result);rememberReady(view.result,saved,action==='prepared'?'prepared':'generated');return true;
    }catch(error){if(isCurrent())failure(null,action);else sync();return false}
    finally{if(flight===current){flight=null;setBusy(false);if(watch)scheduleStatus(watch)}}
  }
  async function insertDraft(){
    sync();if(!view?.result?.should_draft||flight||view.stale||!currentSnapshot(view.snapshot))return false;
    if(view.result.prepared===true&&view.result.expires_at&&timeValue(view.result.expires_at)<=Date.now()){stale();return false}
    const saved=view.snapshot,result=view.result,draftId=validDraftId(view.result.draft_id)?view.result.draft_id:null;
    const current={ticket:sequence,controller:typeof AbortController==='function'?new AbortController():null};flight=current;setBusy(true);status.textContent='Проверяем, что контекст не изменился…';
    const isCurrent=()=>flight===current&&current.ticket===sequence&&view?.result===result&&currentSnapshot(saved);
    try{
      const checked=await W.request('ai-draft.php','validate',{conversation_id:saved.context.conversationId,latest_message_id:saved.latest,revision:result.revision,...(draftId?{draft_id:draftId}:{})},{signal:current.controller?.signal,isCurrent});
      if(!isCurrent()){sync();return false}
      if(checked?.ok!==true||checked.revision!==result.revision||checked.latest_message_id!==saved.latest){
        if(checked?.http_status===403||checked?.http_status===404){resetSession();return false}
        stale();return false;
      }
      // Conversation remains the only draft/submit owner. Append to its current
      // text, including edits made while validation was pending; never touch File.
      const savedSelection=selection();
      const validatedId=draftId&&checked.draft_id===draftId?draftId:null;
      if(!conversation().insertAiDraft(result.draft,saved.context,validatedId)){
        status.textContent='Не удалось добавить черновик. Проверьте текст и доступность ответа.';return false;
      }
      close(false);restoreSelection(savedSelection);return true;
    }catch(error){if(isCurrent())status.textContent='Не удалось проверить контекст. Черновик не добавлен. Повторите проверку по кнопке.';else sync();return false}
    finally{if(flight===current){flight=null;setBusy(false)}}
  }
  function bind(){
    if(bound)return;
    const form=$('composer'),reply=$('replyText'),quick=$('quickRepliesOpen'),row=quick?.parentElement;
    if(!form||!reply||!row||!conversation())return;
    button=document.createElement('button');button.id='aiDraftOpen';button.type='button';button.className='aiDraftOpen';button.textContent='AI';
    button.setAttribute('aria-label','Подготовить ответ с AI');button.setAttribute('aria-controls','aiDraftPanel');button.setAttribute('aria-expanded','false');
    row.insertBefore(button,row.firstChild);
    panel=document.createElement('section');panel.id='aiDraftPanel';panel.className='aiDraftPanel';panel.hidden=true;panel.tabIndex=-1;
    panel.setAttribute('role','region');panel.setAttribute('aria-labelledby','aiDraftTitle');
    const head=document.createElement('div');head.className='aiDraftHead';
    const title=document.createElement('strong');title.id='aiDraftTitle';title.textContent='Черновик ответа';head.appendChild(title);
    const dismiss=document.createElement('button');dismiss.type='button';dismiss.className='aiDraftClose';dismiss.textContent='Закрыть';head.appendChild(dismiss);panel.appendChild(head);
    status=document.createElement('p');status.id='aiDraftStatus';status.className='aiDraftStatus';status.setAttribute('role','status');status.setAttribute('aria-live','polite');panel.appendChild(status);
    body=document.createElement('div');body.className='aiDraftBody';panel.appendChild(body);
    const actions=document.createElement('div');actions.className='aiDraftActions';
    insert=document.createElement('button');insert.id='aiDraftInsert';insert.type='button';insert.textContent='Добавить в ответ';insert.hidden=true;actions.appendChild(insert);
    retry=document.createElement('button');retry.id='aiDraftRetry';retry.type='button';retry.textContent='Повторить подготовку';actions.appendChild(retry);panel.appendChild(actions);form.appendChild(panel);
    if(manager2()){
      notice=document.createElement('div');notice.id='aiReadyNotice';notice.className='aiReadyNotice';notice.hidden=true;notice.setAttribute('role','status');notice.setAttribute('aria-live','polite');
      noticeLabel=document.createElement('span');noticeLabel.textContent='AI — Ответ готов';notice.appendChild(noticeLabel);
      const show=document.createElement('button');show.type='button';show.textContent='Посмотреть';show.addEventListener('click',()=>void loadProposal('prepared',true));notice.appendChild(show);
      const hide=document.createElement('button');hide.type='button';hide.textContent='×';hide.setAttribute('aria-label','Скрыть уведомление AI');hide.addEventListener('click',dismissNotice);notice.appendChild(hide);form.appendChild(notice);
      document.addEventListener('visibilitychange',()=>{if(!visible())stopWatching();else sync()});
    }
    bound=true;button.addEventListener('click',()=>{if(view&&!view.stale)close();else openReady()});dismiss.addEventListener('click',()=>close());
    retry.addEventListener('click',()=>void loadProposal('generate'));insert.addEventListener('click',()=>void insertDraft());
    panel.addEventListener('keydown',event=>{if(event.key==='Escape'){event.preventDefault();event.stopPropagation();close()}});
    for(const event of ['input','select','keyup','pointerup'])reply.addEventListener(event,rememberSelection);
    window.addEventListener('pagehide',()=>resetSession());
    window.addEventListener('resize',positionPanel);window.visualViewport?.addEventListener('resize',positionPanel);
    if(typeof ResizeObserver==='function'){const sizeObserver=new ResizeObserver(positionPanel);sizeObserver.observe(form)}
    if(typeof MutationObserver==='function'){
      const observer=new MutationObserver(sync);
      observer.observe(form,{attributes:true,attributeFilter:['class','disabled','hidden'],subtree:true});
      const messages=$('messages');if(messages)observer.observe(messages,{childList:true,characterData:true,subtree:true});
      observer.observe(document.body,{attributes:true,attributeFilter:['class']});
      const manager=$('managerName');if(manager)observer.observe(manager,{childList:true,characterData:true,subtree:true});
    }
    sync();
  }
  window.WorkspaceV2AiDraft={bind,sync,resetSession,syncPreferences};
  if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',bind,{once:true});else bind();
})();
