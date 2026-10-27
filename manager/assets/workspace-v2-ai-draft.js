(function(){
  const W=window.WorkspaceV2;
  if(!W)return;
  const {S,$}=W;
  let bound=false,view=null,flight=null,sequence=0;
  let button,panel,status,body,insert,retry;
  let ready=null,watch=null,statusFlight=null,watchTimer=null,expiryTimer=null,notice,noticeLabel;
  let suggestion,suggestionText,suggestionLabel,suggestionAccept;
  let preparationNotice,preparationLabel,preparationRetry;
  let recommendationNotice,recommendationTitle,recommendationReason;
  const automaticAttempts=new Map();
  let automaticIdentity='';
  let placeholder='',suggestionLargeFont=null;
  const dismissedNotices=new Set(),manager2=()=>document.body.classList.contains('manager2');
  const visible=()=>document.visibilityState!=='hidden';
  const conversation=()=>window.WorkspaceV2Conversation;
  const context=()=>conversation()?.captureReplyContext();
  const latestMessageId=()=>Array.isArray(S.detail?.messages)?S.detail.messages.reduce((latest,message)=>{const id=Number(message.id);return Number.isSafeInteger(id)&&id>latest?id:latest},0):0;
  const messageState=ids=>JSON.stringify((S.detail?.messages||[]).filter(message=>!ids||ids.has(String(message.id))));
  const validDraftId=value=>typeof value==='string'&&/^[a-f0-9]{32}$/.test(value);
  function canCompose(){
    const form=$('composer'),reply=$('replyText'),c=S.detail?.conversation,issue=$('conversationLifecycleStatus');
    return !!form&&!!reply&&!form.hidden&&!form.classList.contains('hidden')&&!reply.disabled&&!S.authExpired&&!!S.manager?.id&&!!S.current&&Number(c?.id)===Number(S.current)&&c.status==='manager'&&Number(c.manager_id)===Number(S.manager.id)&&S.detail?.delivery_failure?.category!=='suspended'&&!(issue&&!issue.classList.contains('hidden'));
  }
  function supervisorRead(){const c=S.detail?.conversation;return !S.authExpired&&!!S.manager?.id&&Number(c?.id)===Number(S.current)&&S.detail?.delivery_failure?.category!=='suspended'&&((S.detail?.owner_read===true&&S.manager.role==='admin')||(S.detail?.supervisor_read===true&&c?.status==='manager'&&Number(c.manager_id)>0&&Number(c.manager_id)!==Number(S.manager.id)))}
  const canAnalyze=()=>canCompose()||supervisorRead();
  const ownerRead=()=>supervisorRead()&&S.detail?.owner_read===true&&S.manager?.role==='admin';
  function sameContext(saved){
    const current=context();
    return !!saved&&!!current&&Object.keys(current).every(key=>current[key]===saved[key]);
  }
  const ownerBinding=()=>JSON.stringify([S.detail?.conversation?.manager_id,S.detail?.conversation?.status,S.detail?.conversation?.project_key,S.detail?.conversation?.source_id,S.detail?.supervisor_read,S.detail?.owner_read,S.manager?.role]);
  function snapshot(){const ids=new Set((S.detail?.messages||[]).map(message=>String(message.id)));return {context:context(),latest:latestMessageId(),messageIds:ids,messages:messageState(ids),owner:ownerBinding()}}
  function currentSnapshot(saved){return canAnalyze()&&sameContext(saved?.context)&&saved.latest===latestMessageId()&&saved.messages===messageState(saved.messageIds)&&saved.owner===ownerBinding()}
  function timeValue(value){const parsed=window.ManagerTime?.parse?.(value)||new Date(String(value||'').replace(' ','T')+(/(?:Z|[+-]\d{2}:?\d{2})$/.test(String(value||''))?'':'Z'));return Number.isNaN(parsed.getTime())?0:parsed.getTime()}
  function timeLabel(value){return window.ManagerTime?.format?window.ManagerTime.format(value)+' '+window.ManagerTime.label:''}
  function hideNotice(hidden){if(notice&&notice.hidden!==hidden)notice.hidden=hidden}
  function clearReady(){ready=null;window.clearTimeout?.(expiryTimer);expiryTimer=null;hideNotice(true);syncSuggestion();syncRecommendation()}
  function noticeKey(){return ready?JSON.stringify([S.manager?.id,S.current,ready.revision,ready.prepared_at]):''}
  function dismissNotice(){dismissedNotices.add(noticeKey());if(dismissedNotices.size>100)dismissedNotices.delete(dismissedNotices.values().next().value);hideNotice(true);syncSuggestion()}
  function suggestionAvailable(){
    return !!suggestion&&manager2()&&visible()&&!!ready&&ready.expires>Date.now()&&currentSnapshot(ready.snapshot)&&validResult(ready,ready.snapshot)&&ready.should_draft&&panel.hidden&&!flight&&!dismissedNotices.has(noticeKey())&&window.WorkspaceV2PersonalWorkspace?.showAiReadyNotice?.()!==false&&(ownerRead()||canCompose()&&$('replyText').value==='');
  }
  function syncSuggestion(){
    if(!suggestion)return;
    const shown=suggestionAvailable(),readonly=ownerRead(),reply=$('replyText'),form=$('composer'),ghost=shown&&!readonly;
    let layoutChanged=suggestion.hidden===shown;
    const largeFont=document.body.classList.contains('pwLargeFont');
    if(suggestionLargeFont!==largeFont){suggestionLargeFont=largeFont;if(ghost)layoutChanged=true}
    if(layoutChanged)suggestion.hidden=!shown;
    if(form.classList.contains('aiGhostVisible')!==ghost){form.classList.toggle('aiGhostVisible',ghost);layoutChanged=true}
    const hint=ghost?'':placeholder;
    if(reply.getAttribute('placeholder')!==hint)reply.setAttribute('placeholder',hint);
    if(!shown){
      if(suggestionText.textContent){suggestionText.textContent='';layoutChanged=true}
      if(layoutChanged)window.WorkspaceV2ReplyEditor?.resize?.();
      return;
    }
    const host=readonly?$('conversationZone'):reply.parentElement,before=readonly?$('composerLocked'):reply;
    if(host&&suggestion.parentElement!==host){host.insertBefore(suggestion,before);layoutChanged=true}
    suggestion.classList.toggle('aiSuggestionReadonly',readonly);
    suggestion.classList.toggle('aiSuggestionGhost',!readonly);
    if(suggestionText.textContent!==ready.draft){suggestionText.textContent=ready.draft;layoutChanged=true}
    const label=readonly?'AI-подсказка · Только просмотр · '+(S.detail?.conversation?.manager_name||'Без назначения'):'AI-подсказка';
    if(suggestionLabel.textContent!==label)suggestionLabel.textContent=label;
    suggestionAccept.hidden=readonly;suggestionAccept.disabled=readonly;
    if(layoutChanged)window.WorkspaceV2ReplyEditor?.resize?.();
  }
  function syncPreferences(){
    syncSuggestion();
    syncPreparation();
    syncRecommendation();
    if(!notice)return;
    hideNotice(!canCompose()||!manager2()||!visible()||!ready||ready.kind!=='prepared'||ready.expires<=Date.now()||!ready.should_draft||!currentSnapshot(ready.snapshot)||!panel.hidden||suggestion&&!suggestion.hidden||dismissedNotices.has(noticeKey())||window.WorkspaceV2PersonalWorkspace?.showAiReadyNotice?.()===false);
  }
  function hasReadyProposal(result,saved){
    return validResult(result,saved)&&typeof result.next_action==='string'&&typeof result.reason==='string'&&Array.isArray(result.checks);
  }
  function syncRecommendation(){
    if(!recommendationNotice)return;
    const shown=manager2()&&visible()&&!!ready&&ready.expires>Date.now()&&currentSnapshot(ready.snapshot)&&hasReadyProposal(ready,ready.snapshot)&&ready.should_draft===false&&panel.hidden&&!flight&&window.WorkspaceV2PersonalWorkspace?.showAiReadyNotice?.()!==false&&(ownerRead()||canCompose());
    let changed=recommendationNotice.hidden===shown;
    if(changed)recommendationNotice.hidden=!shown;
    if(!shown){
      if(recommendationTitle.textContent||recommendationReason.textContent){recommendationTitle.textContent='';recommendationReason.textContent='';changed=true}
      if(changed)window.WorkspaceV2ReplyEditor?.resize?.();
      return;
    }
    const readonly=ownerRead(),host=readonly?$('conversationZone'):$('composer');
    const before=readonly?$('composerLocked'):[...host.children].find(node=>node.classList.contains('composerSurface')||node.classList.contains('composerMain'))||host.firstChild;
    if(recommendationNotice.parentElement!==host){host.insertBefore(recommendationNotice,before);changed=true}
    recommendationNotice.classList.toggle('aiRecommendationReadonly',readonly);
    const title=ready.next_action==='no_contact'?'AI: сейчас отвечать не нужно':'AI: перед ответом нужна проверка';
    if(recommendationTitle.textContent!==title){recommendationTitle.textContent=title;changed=true}
    if(recommendationReason.textContent!==ready.reason){recommendationReason.textContent=ready.reason;changed=true}
    if(changed)window.WorkspaceV2ReplyEditor?.resize?.();
  }
  function automaticEligible(saved){
    return !!saved&&watch===saved&&currentSnapshot(saved.snapshot)&&visible()&&window.WorkspaceV2PersonalWorkspace?.showAiReadyNotice?.()!==false&&(ownerRead()||canCompose()&&$('replyText').value==='');
  }
  function syncAutomaticIdentity(){
    const identity=JSON.stringify([S.manager?.id,S.authGeneration,!!S.authExpired]);
    if(identity!==automaticIdentity){automaticAttempts.clear();automaticIdentity=identity}
  }
  function automaticKey(saved){
    const {conversationId,managerId,authGeneration}=saved.snapshot.context;
    return JSON.stringify([conversationId,managerId,authGeneration,saved.snapshot.latest,saved.snapshot.owner,saved.snapshot.messages]);
  }
  function syncPreparation(){
    if(!preparationNotice)return;
    const saved=watch,attempt=saved?.automatic,late=!!saved&&Date.now()>=saved.until;
    const shown=!!attempt&&attempt.phase!=='complete'&&automaticEligible(saved)&&panel.hidden&&!flight&&!(ready&&currentSnapshot(ready.snapshot));
    let changed=preparationNotice.hidden===shown;
    if(changed)preparationNotice.hidden=!shown;
    if(!shown){if(changed)window.WorkspaceV2ReplyEditor?.resize?.();return}
    const readonly=ownerRead(),host=readonly?$('conversationZone'):$('composer');
    const before=readonly?$('composerLocked'):[...host.children].find(node=>node.classList.contains('composerSurface')||node.classList.contains('composerMain'))||host.firstChild;
    if(preparationNotice.parentElement!==host){host.insertBefore(preparationNotice,before);changed=true}
    preparationNotice.classList.toggle('aiPreparationReadonly',readonly);
    const failed=attempt.phase==='failed',text=failed?(attempt.error==='preparation_queue_full'?'Очередь AI-подсказок занята. Попробуйте подготовить позже.':'Автоматическая подготовка AI-подсказки временно недоступна.'):late?'Подготовка AI-подсказки задерживается.':'AI готовит подсказку автоматически…';
    if(preparationLabel.textContent!==text){preparationLabel.textContent=text;changed=true}
    const retry=failed||late;
    if(preparationRetry.hidden===retry){preparationRetry.hidden=!retry;changed=true}
    if(preparationRetry.disabled!==!!statusFlight)preparationRetry.disabled=!!statusFlight;
    if(changed)window.WorkspaceV2ReplyEditor?.resize?.();
  }
  function retryAutomatic(){
    const saved=watch;
    if(!automaticEligible(saved)||flight||statusFlight)return;
    automaticAttempts.delete(automaticKey(saved));saved.automatic=null;saved.until=Date.now()+600000;
    window.clearTimeout?.(saved.deadlineTimer);saved.deadlineTimer=null;
    void readStatus(saved);
  }
  async function ensureAutomatic(saved,current,isCurrent){
    if(!automaticEligible(saved))return;
    const key=automaticKey(saved),previous=automaticAttempts.get(key);
    if(previous&&Date.now()<previous.until){
      saved.automatic=previous;saved.until=Math.min(saved.until,previous.until);
      if(!saved.deadlineTimer)saved.deadlineTimer=window.setTimeout?.(()=>{if(watch===saved)syncPreparation()},Math.max(1,saved.until-Date.now()));
      syncPreparation();return;
    }
    const attempt={phase:'ensuring',until:Date.now()+600000};
    automaticAttempts.set(key,attempt);saved.automatic=attempt;
    while(automaticAttempts.size>32)automaticAttempts.delete(automaticAttempts.keys().next().value);
    if(!saved.deadlineTimer)saved.deadlineTimer=window.setTimeout?.(()=>{if(watch===saved)syncPreparation()},Math.max(1,saved.until-Date.now()));
    syncPreparation();
    try{
      const result=await W.request('ai-draft.php','ensure_prepared',{conversation_id:saved.snapshot.context.conversationId,latest_message_id:saved.snapshot.latest},{signal:current.controller?.signal,isCurrent});
      if(!isCurrent())return;
      if(result?.http_status===403||result?.http_status===404){saved.blocked=true;clearReady();resetSession({keepReady:true});return}
      if(result?.ok===true&&(result.requested===true||result.queued===true)){
        attempt.phase='pending';saved.refreshStatus=result.preparation_state==='ready';
      }else{attempt.phase='failed';attempt.error=result?.error||'preparation_unavailable'}
    }catch(error){if(isCurrent()){attempt.phase='failed';attempt.error='preparation_unavailable'}}
    finally{
      // An aborted observer may have missed the enqueue receipt. A later visible
      // read can repeat the idempotent ensure; it never invokes a model here.
      if(attempt.phase==='ensuring'&&automaticAttempts.get(key)===attempt)automaticAttempts.delete(key);
      if(isCurrent())syncPreparation();
    }
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
    // A metadata poll must not erase the already read private text for this exact proposal.
    const previous=ready?.kind===kind&&ready.revision===result.revision&&ready.prepared_at===result.prepared_at?ready:null;
    window.clearTimeout?.(expiryTimer);expiryTimer=null;
    ready={...previous,...result,snapshot:saved,kind,expires,prepared_at:result.prepared_at||new Date().toISOString()};
    const captured=ready;
    expiryTimer=window.setTimeout?.(()=>{if(ready!==captured)return;clearReady();if(view?.result?.prepared===true){view.result=null;clearBody();status.textContent='Срок хранения готового ответа истёк. Проверьте наличие нового ответа.'}renderButton()},Math.min(expires-Date.now(),2147483647));
    renderButton();
  }
  function abortStatusRead(){const previous=statusFlight;statusFlight=null;previous?.controller?.abort();window.clearTimeout?.(watchTimer);watchTimer=null}
  function stopWatching(){syncAutomaticIdentity();abortStatusRead();window.clearTimeout?.(watch?.deadlineTimer);watch=null;clearReady();syncPreparation()}
  function scheduleStatus(saved){
    if(watch!==saved||saved.blocked||!visible()||!currentSnapshot(saved.snapshot)||Date.now()>=saved.until)return;
    window.clearTimeout?.(watchTimer);watchTimer=window.setTimeout?.(()=>{watchTimer=null;void readStatus(saved)},15000);
  }
  async function readStatus(saved){
    if(watch===saved&&Date.now()>=saved.until){syncPreparation();return}
    if(watch!==saved||saved.blocked||!visible()||!currentSnapshot(saved.snapshot)||Date.now()>=saved.until||statusFlight)return;
    if(flight){scheduleStatus(saved);return}
    const current={controller:typeof AbortController==='function'?new AbortController():null};statusFlight=current;
    const isCurrent=()=>watch===saved&&statusFlight===current&&visible()&&currentSnapshot(saved.snapshot);
    try{
      const result=await W.request('ai-draft.php','prepared_status',{conversation_id:saved.snapshot.context.conversationId,latest_message_id:saved.snapshot.latest},{signal:current.controller?.signal,isCurrent});
      if(!isCurrent())return;
      if(result?.http_status===403||result?.http_status===404){saved.blocked=true;clearReady();resetSession({keepReady:true});return}
      if(result?.ok===true&&result.prepared===true&&typeof result.should_draft==='boolean'&&typeof result.revision==='string'&&result.revision&&result.latest_message_id===saved.snapshot.latest){
        if(saved.automatic)saved.automatic.phase='complete';
        if(ready?.kind!=='generated'){
          rememberReady(result,saved.snapshot,'prepared');
          if(!hasReadyProposal(ready,saved.snapshot)){
            // Passive full-text reads never generate, register use or move focus.
            const proposal=await W.request('ai-draft.php','prepared_preview',{conversation_id:saved.snapshot.context.conversationId,latest_message_id:saved.snapshot.latest},{signal:current.controller?.signal,isCurrent});
            if(!isCurrent())return;
            if(proposal?.http_status===403||proposal?.http_status===404){saved.blocked=true;clearReady();resetSession({keepReady:true});return}
            if(hasReadyProposal(proposal,saved.snapshot)&&proposal.prepared===true&&proposal.should_draft===result.should_draft&&proposal.revision===result.revision&&proposal.prepared_at===result.prepared_at)rememberReady(proposal,saved.snapshot,'prepared');
            else clearReady();
          }
        }
      }else if(ready?.kind!=='generated'){
        clearReady();
        if(result?.ok===true&&result.prepared===false&&result.automatic_preparation===true)await ensureAutomatic(saved,current,isCurrent);
      }
      renderButton();
    }catch(error){if(isCurrent()&&ready?.kind!=='generated'){clearReady();renderButton()}}
    finally{if(statusFlight===current){statusFlight=null;syncPreparation();if(saved.refreshStatus){saved.refreshStatus=false;void readStatus(saved)}else scheduleStatus(saved)}}
  }
  function syncWatch(){
    syncAutomaticIdentity();
    // A server predating the new preference does not advertise readiness support.
    if(!manager2()||!(canCompose()||ownerRead())||!visible()||window.WorkspaceV2PersonalWorkspace?.supportsAiReadiness?.()===false){stopWatching();return}
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
    if(supervisorRead())return;
    const rect=$('composer').getBoundingClientRect?.();if(!rect)return;
    const top=window.visualViewport?.offsetTop||0;
    panel.style.setProperty('--ai-draft-room',Math.max(0,rect.top-top-8)+'px');
  }
  function setBusy(value){
    panel.setAttribute('aria-busy',String(value));
    insert.disabled=value||!view?.result?.should_draft||!canCompose();retry.disabled=value;
    retry.hidden=view?.retryAction==='validate';
    insert.textContent=view?.retryAction==='validate'?'Повторить вставку':'Добавить в ответ';
    const disabled=!canAnalyze()||value;if(button.disabled!==disabled)button.disabled=disabled;
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
    const supervised=supervisorRead(),host=supervised?$('conversationZone'):$('composer');
    if(host&&panel.parentElement!==host)host.appendChild(panel);
    panel.classList.toggle('aiDraftSupervisor',supervised);
    $('conversationZone')?.classList.toggle('aiDraftSupervisorHost',supervised);
    if(view&&(!canAnalyze()||!sameContext(view.snapshot.context))){resetSession();return}
    if(view&&!currentSnapshot(view.snapshot))stale();
    const disabled=!canAnalyze()||!!flight;if(button.disabled!==disabled)button.disabled=disabled;
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
    if(result.should_draft){addText('p','aiDraftText',result.draft);insert.hidden=!canCompose()}
    if(action)addText('p','aiDraftAction',action);
    if(reason)addText('p','aiDraftReason',reason);
    if(result.history_incomplete)addText('p','aiDraftWarning','В доступной истории есть пропуски. Проверьте контекст перед ответом.');
    if(Array.isArray(result.checks)&&result.checks.length){
      const list=addText('ul','aiDraftChecks','');
      for(const check of result.checks){const text=readable(check);if(!text)continue;const item=document.createElement('li');item.textContent=text;list.appendChild(item)}
    }
    const prepared=result.prepared===true&&result.should_draft?'Ответ подготовлен заранее. ':'';
    status.textContent=prepared+(result.should_draft?'Проверьте текст. Добавим в конец ответа.':rawAction==='no_contact'?'Сейчас отвечать не нужно.':'Перед ответом нужно проверить данные. Причина и следующий шаг — ниже.');
    if(supervisorRead())status.textContent=(S.detail?.owner_read?'Режим владельца':'Просмотр руководителя')+' · Ответственный: '+(S.detail?.conversation?.manager_name||'Без назначения')+'. Просмотр не считается использованием или отправкой ответа.';
    retry.textContent=result.should_draft?'Другой вариант':'Повторить подготовку';
  }
  function failure(result,action){
    clearBody();view.result=null;
    if(['context_changed','context_stale','stale_context','stale_revision','revision_changed'].includes(result?.error)){stale();return}
    if(action==='prepared'){
      if(result?.http_status===403||result?.http_status===404){resetSession();return}
      if(view.retryAction==='prepared'){status.textContent='Не удалось проверить AI-подсказку. Текст и файлы сохранены. Повторите проверку.';return}
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
    if((ready?.kind==='generated'||ready?.should_draft===false)&&ready.expires>Date.now()&&currentSnapshot(ready.snapshot)&&hasReadyProposal(ready,ready.snapshot)){
      const result=ready;abortStatusRead();abortFlight();view={snapshot:result.snapshot,selection:selection(),result,stale:false};panel.hidden=false;button.setAttribute('aria-expanded','true');renderResult(result);setBusy(false);positionPanel();panel.focus({preventScroll:true});if(watch)scheduleStatus(watch);return;
    }
    void loadProposal('prepared',true);
  }
  async function loadProposal(action,generateIfNeeded=false){
    if(!canAnalyze()||flight)return false;
    const recovery=action==='prepared'&&view?.retryAction==='prepared';
    abortStatusRead();dismissNotice();
    const saved=snapshot(),savedSelection=selection();abortFlight();
    view={snapshot:saved,selection:savedSelection,result:null,stale:false,...(recovery?{retryAction:'prepared'}:{})};
    panel.hidden=false;button.setAttribute('aria-expanded','true');clearBody();retry.textContent=recovery?'Повторить проверку':'Повторить подготовку';status.textContent=recovery?'Проверяем AI-подсказку…':'Читаем переписку и фото, готовим ответ…';
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
      if(action==='prepared'&&result?.ok===true&&result.prepared===false){
        clearReady();status.textContent=recovery?'Готовая подсказка больше недоступна. Можно подготовить новый ответ.':'Готового ответа пока нет. Можно сформировать ответ заново.';
        if(recovery){view.retryAction='generate';retry.textContent='Подготовить новый ответ'}return false;
      }
      if(!validResult(result,saved)||action==='prepared'&&result.prepared!==true){if(result?.ok&&result.latest_message_id!==saved.latest)stale();else failure(result,action);return false}
      // Both sources carry server-owned provenance through fresh validation.
      delete view.retryAction;view.result=result;renderResult(view.result);rememberReady(view.result,saved,action==='prepared'?'prepared':'generated');return true;
    }catch(error){if(isCurrent())failure(null,action);else sync();return false}
    finally{if(flight===current){flight=null;setBusy(false);if(watch)scheduleStatus(watch)}}
  }
  function showInsertionIssue(saved,message,result=null,changed=false){
    if(!visible()||!canCompose()||!sameContext(saved.context)||result&&!currentSnapshot(saved))return false;
    view={snapshot:saved,selection:selection(),result,stale:changed,retryAction:result?'validate':'prepared'};
    if(result)renderResult(result);else clearBody();
    status.textContent=message;retry.textContent='Повторить проверку';panel.hidden=false;button.setAttribute('aria-expanded','true');
    // Explicit insertion errors must be visible; keep the editor's focus and
    // selection intact, including the on-screen keyboard and any attached File.
    setBusy(!!flight);positionPanel();return true;
  }
  async function insertDraft({emptyOnly=false}={}){
    sync();if(!canCompose()||!view?.result?.should_draft||flight||view.stale||!currentSnapshot(view.snapshot))return false;
    if(emptyOnly&&$('replyText').value!=='')return false;
    if(view.result.prepared===true&&view.result.expires_at&&timeValue(view.result.expires_at)<=Date.now()){stale();return false}
    const saved=view.snapshot,result=view.result,draftId=validDraftId(view.result.draft_id)?view.result.draft_id:null;
    const current={ticket:sequence,controller:typeof AbortController==='function'?new AbortController():null};flight=current;setBusy(true);status.textContent='Проверяем, что контекст не изменился…';
    const isCurrent=()=>flight===current&&current.ticket===sequence&&view?.result===result&&currentSnapshot(saved);
    try{
      const checked=await W.request('ai-draft.php','validate',{conversation_id:saved.context.conversationId,latest_message_id:saved.latest,revision:result.revision,...(draftId?{draft_id:draftId}:{})},{signal:current.controller?.signal,isCurrent});
      if(!isCurrent()){sync();return false}
      if(checked?.ok!==true||checked.revision!==result.revision||checked.latest_message_id!==saved.latest){
        if(checked?.http_status===403||checked?.http_status===404){resetSession();return false}
        view.insertionIssue=true;
        if(checked?.ok===true||['context_changed','context_stale','stale_context','stale_revision','revision_changed'].includes(checked?.error))stale();
        else status.textContent='Не удалось проверить контекст. Текст и файлы сохранены. Повторите вставку.';
        return false;
      }
      if(result.prepared===true&&result.expires_at&&timeValue(result.expires_at)<=Date.now()){stale();return false}
      if(emptyOnly&&($('replyText').value!==''||!visible()||result.expires&&result.expires<=Date.now()))return false;
      // Conversation remains the only draft/submit owner. Append to its current
      // text, including edits made while validation was pending; never touch File.
      const savedSelection=selection();
      const validatedId=draftId&&checked.draft_id===draftId?draftId:null;
      if(!conversation().insertAiDraft(result.draft,saved.context,validatedId)){
        view.insertionIssue=true;
        status.textContent='Не удалось добавить черновик. Проверьте текст и доступность ответа.';return false;
      }
      close(false);restoreSelection(emptyOnly?{...savedSelection,start:result.draft.length,end:result.draft.length,direction:'none'}:savedSelection);return true;
    }catch(error){if(isCurrent()){view.insertionIssue=true;status.textContent='Не удалось проверить контекст. Черновик не добавлен. Повторите вставку.'}else sync();return false}
    finally{if(flight===current){flight=null;setBusy(false)}}
  }
  async function insertSuggestion(){
    const sourceView=view;
    const added=await insertDraft({emptyOnly:true});
    if(added&&sameContext(sourceView?.snapshot.context))dismissNotice();
    else if(panel.hidden&&view===sourceView){
      if(!sourceView.insertionIssue||!showInsertionIssue(sourceView.snapshot,status.textContent,sourceView.result,sourceView.stale))resetSession({keepReady:true});
    }
    return added;
  }
  async function acceptSuggestion(){
    syncPreferences();if(!suggestionAvailable()||!canCompose())return false;
    if(ready.kind==='generated'){
      view={snapshot:ready.snapshot,selection:selection(),result:ready,stale:false};
      return insertSuggestion();
    }
    const candidate=ready,saved=candidate.snapshot;
    abortStatusRead();abortFlight();
    const current={ticket:sequence,action:'prepared',controller:typeof AbortController==='function'?new AbortController():null};flight=current;setBusy(true);
    const isCurrent=()=>flight===current&&current.ticket===sequence&&visible()&&currentSnapshot(saved);
    try{
      // Register provenance only after the explicit Tab/click, then use the same
      // fresh validation and composer insertion path as the full preview.
      const result=await W.request('ai-draft.php','prepared',{conversation_id:saved.context.conversationId,latest_message_id:saved.latest},{signal:current.controller?.signal,isCurrent});
      if(!isCurrent())return false;
      if(result?.http_status===403||result?.http_status===404){resetSession();return false}
      if(!validResult(result,saved)||result.prepared!==true||result.revision!==candidate.revision||result.draft!==candidate.draft||result.prepared_at!==candidate.prepared_at){
        clearReady();showInsertionIssue(saved,result?.ok===true?'AI-подсказка изменилась. Проверьте актуальный вариант.':'Не удалось проверить AI-подсказку. Текст и файлы сохранены. Повторите проверку.');return false;
      }
      if($('replyText').value!=='')return false;
      view={snapshot:saved,selection:selection(),result,stale:false};
    }catch(error){if(isCurrent()){clearReady();showInsertionIssue(saved,'Не удалось проверить AI-подсказку. Текст и файлы сохранены. Повторите проверку.')}return false}
    finally{if(flight===current){flight=null;setBusy(false);if(watch)scheduleStatus(watch)}}
    return insertSuggestion();
  }
  function bind(){
    if(bound)return;
    const form=$('composer'),reply=$('replyText'),quick=$('quickRepliesOpen'),row=quick?.parentElement;
    if(!form||!reply||!row||!conversation())return;
    placeholder=reply.getAttribute('placeholder')||'';
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
      preparationNotice=document.createElement('div');preparationNotice.id='aiPreparationNotice';preparationNotice.className='aiPreparationNotice';preparationNotice.hidden=true;preparationNotice.setAttribute('role','status');preparationNotice.setAttribute('aria-live','polite');
      preparationLabel=document.createElement('span');preparationNotice.appendChild(preparationLabel);
      preparationRetry=document.createElement('button');preparationRetry.id='aiPreparationRetry';preparationRetry.type='button';preparationRetry.textContent='Повторить подготовку';preparationRetry.hidden=true;preparationRetry.addEventListener('click',retryAutomatic);preparationNotice.appendChild(preparationRetry);form.appendChild(preparationNotice);
      recommendationNotice=document.createElement('section');recommendationNotice.id='aiRecommendationNotice';recommendationNotice.className='aiRecommendationNotice';recommendationNotice.hidden=true;recommendationNotice.setAttribute('role','status');recommendationNotice.setAttribute('aria-live','polite');recommendationNotice.setAttribute('aria-label','Почему нет AI-черновика');
      const recommendationCopy=document.createElement('div');recommendationCopy.className='aiRecommendationCopy';recommendationTitle=document.createElement('strong');recommendationTitle.className='aiRecommendationTitle';recommendationCopy.appendChild(recommendationTitle);recommendationReason=document.createElement('p');recommendationReason.className='aiRecommendationReason';recommendationCopy.appendChild(recommendationReason);recommendationNotice.appendChild(recommendationCopy);
      const recommendationDetails=document.createElement('button');recommendationDetails.id='aiRecommendationDetails';recommendationDetails.type='button';recommendationDetails.textContent='Подробнее';recommendationDetails.addEventListener('click',openReady);recommendationNotice.appendChild(recommendationDetails);form.appendChild(recommendationNotice);
      suggestion=document.createElement('section');suggestion.id='aiDraftSuggestion';suggestion.className='aiDraftSuggestion';suggestion.hidden=true;suggestion.setAttribute('aria-label','AI-подсказка');
      const suggestionHead=document.createElement('div');suggestionHead.className='aiSuggestionHead';suggestionLabel=document.createElement('strong');suggestionHead.appendChild(suggestionLabel);
      const hideSuggestion=document.createElement('button');hideSuggestion.type='button';hideSuggestion.className='aiSuggestionDismiss';hideSuggestion.textContent='×';hideSuggestion.setAttribute('aria-label','Скрыть AI-подсказку');hideSuggestion.addEventListener('click',dismissNotice);suggestionHead.appendChild(hideSuggestion);suggestion.appendChild(suggestionHead);
      suggestionText=document.createElement('p');suggestionText.className='aiSuggestionText';suggestion.appendChild(suggestionText);
      const suggestionActions=document.createElement('div');suggestionActions.className='aiSuggestionActions';suggestionAccept=document.createElement('button');suggestionAccept.id='aiSuggestionAccept';suggestionAccept.type='button';suggestionAccept.textContent='Вставить в ответ';suggestionAccept.addEventListener('click',()=>void acceptSuggestion());suggestionActions.appendChild(suggestionAccept);
      const details=document.createElement('button');details.id='aiSuggestionDetails';details.type='button';details.textContent='Подробнее';details.addEventListener('click',openReady);suggestionActions.appendChild(details);suggestion.appendChild(suggestionActions);form.insertBefore(suggestion,form.querySelector('.composerSurface')||form.firstChild);
      reply.addEventListener('input',syncPreferences);
      reply.addEventListener('keydown',event=>{
        if(event.defaultPrevented||event.isComposing||event.repeat||event.shiftKey||event.altKey||event.ctrlKey||event.metaKey||document.activeElement!==reply)return;
        if(event.key==='Escape'&&suggestionAvailable()){event.preventDefault();event.stopPropagation();dismissNotice()}
        if(event.key==='Tab'&&suggestionAvailable()&&canCompose()){event.preventDefault();event.stopPropagation();void acceptSuggestion()}
      });
      notice=document.createElement('div');notice.id='aiReadyNotice';notice.className='aiReadyNotice';notice.hidden=true;notice.setAttribute('role','status');notice.setAttribute('aria-live','polite');
      noticeLabel=document.createElement('span');noticeLabel.textContent='AI — Ответ готов';notice.appendChild(noticeLabel);
      const show=document.createElement('button');show.type='button';show.textContent='Посмотреть';show.addEventListener('click',()=>void loadProposal('prepared',true));notice.appendChild(show);
      const hide=document.createElement('button');hide.type='button';hide.textContent='×';hide.setAttribute('aria-label','Скрыть уведомление AI');hide.addEventListener('click',dismissNotice);notice.appendChild(hide);form.appendChild(notice);
      document.addEventListener('visibilitychange',()=>{if(!visible())stopWatching();else sync()});
    }
    bound=true;button.addEventListener('click',()=>{if(view&&!view.stale)close();else openReady()});dismiss.addEventListener('click',()=>close());
    retry.addEventListener('click',()=>void loadProposal(view?.retryAction==='prepared'?'prepared':'generate'));insert.addEventListener('click',()=>void insertDraft());
    panel.addEventListener('keydown',event=>{if(event.key==='Escape'){event.preventDefault();event.stopPropagation();close()}});
    for(const event of ['input','select','keyup','pointerup'])reply.addEventListener(event,rememberSelection);
    window.addEventListener('pagehide',()=>{automaticAttempts.clear();resetSession()});
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
  window.WorkspaceV2AiDraft={bind,sync,resetSession,syncPreferences,openSupervisor:()=>{sync();if(supervisorRead())openReady()}};
  if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',bind,{once:true});else bind();
})();
