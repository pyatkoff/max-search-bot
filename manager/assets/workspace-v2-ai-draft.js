(function(){
  const W=window.WorkspaceV2;
  if(!W)return;
  const {S,$}=W;
  let bound=false,view=null,flight=null,sequence=0;
  let button,panel,status,body,insert,retry;
  const conversation=()=>window.WorkspaceV2Conversation;
  const context=()=>conversation()?.captureReplyContext();
  const latestMessageId=()=>Array.isArray(S.detail?.messages)?S.detail.messages.reduce((latest,message)=>{const id=Number(message.id);return Number.isSafeInteger(id)&&id>latest?id:latest},0):0;
  const messageState=()=>JSON.stringify(S.detail?.messages||[]);
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
  }
  function clearBody(){body.replaceChildren();insert.hidden=true;insert.disabled=true}
  function abortFlight(){sequence++;const previous=flight;flight=null;previous?.controller?.abort()}
  function resetSession(){
    abortFlight();view=null;
    if(!bound)return;
    panel.hidden=true;status.textContent='';clearBody();button.setAttribute('aria-expanded','false');setBusy(false);
  }
  function close(restoreFocus=true){
    const previous=view,mayFocus=previous&&sameContext(previous.snapshot.context)&&canCompose();
    resetSession();if(restoreFocus&&mayFocus)restoreSelection(previous.selection);
  }
  function stale(){
    if(!view||view.stale)return;
    abortFlight();view.stale=true;view.result=null;clearBody();
    status.textContent='Переписка изменилась. Сформируйте ответ заново.';setBusy(false);
  }
  function sync(){
    if(!bound)return;
    if(view&&(!canCompose()||!sameContext(view.snapshot.context))){resetSession();return}
    if(view&&!currentSnapshot(view.snapshot))stale();
    const disabled=!canCompose()||!!flight;if(button.disabled!==disabled)button.disabled=disabled;
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
    const actions={clarify:'Уточнить запрос',answer_question:'Ответить на вопрос',check_offer:'Проверить предложение',prepare_selection:'Подготовить подборку',no_contact:'Сейчас не писать'};
    const rawAction=readable(result.next_action),action=actions[rawAction]||rawAction,reason=readable(result.reason);
    if(result.should_draft){addText('p','aiDraftText',result.draft);insert.hidden=false}
    if(action)addText('p','aiDraftAction',action);
    if(reason)addText('p','aiDraftReason',reason);
    if(result.history_incomplete)addText('p','aiDraftWarning','В доступной истории есть пропуски. Проверьте контекст перед ответом.');
    if(Array.isArray(result.checks)&&result.checks.length){
      const list=addText('ul','aiDraftChecks','');
      for(const check of result.checks){const text=readable(check);if(!text)continue;const item=document.createElement('li');item.textContent=text;list.appendChild(item)}
    }
    status.textContent=result.should_draft?'Проверьте текст. Добавим в конец ответа.':'Сначала проверьте рекомендации. Новое сообщение пока не подготовлено.';
  }
  function failure(result){
    clearBody();view.result=null;
    if(['context_changed','context_stale','stale_context','stale_revision','revision_changed'].includes(result?.error)){stale();return}
    if(result?.error==='provider_not_configured'){status.textContent='AI сейчас недоступен. Ваш текст и вложения сохранены.';return}
    status.textContent='Не удалось подготовить ответ. Ваш текст и вложения сохранены. Можно повторить по кнопке.';
  }
  function validResult(result,saved){
    return result?.ok===true&&typeof result.revision==='string'&&!!result.revision&&Number.isSafeInteger(result.latest_message_id)&&result.latest_message_id===saved.latest&&typeof result.should_draft==='boolean'&&(!result.should_draft||typeof result.draft==='string'&&!!result.draft.trim());
  }
  async function generate(){
    if(!canCompose()||flight)return false;
    const saved=snapshot(),savedSelection=selection();abortFlight();
    view={snapshot:saved,selection:savedSelection,result:null,stale:false};
    panel.hidden=false;button.setAttribute('aria-expanded','true');clearBody();status.textContent='Готовим черновик…';
    const current={ticket:sequence,controller:typeof AbortController==='function'?new AbortController():null};flight=current;setBusy(true);positionPanel();panel.focus({preventScroll:true});
    const isCurrent=()=>flight===current&&current.ticket===sequence&&!!view&&currentSnapshot(saved);
    try{
      const result=await W.request('ai-draft.php','generate',{conversation_id:saved.context.conversationId,latest_message_id:saved.latest},{signal:current.controller?.signal,isCurrent});
      if(!isCurrent()){sync();return false}
      if(!validResult(result,saved)){if(result?.ok&&result.latest_message_id!==saved.latest)stale();else failure(result);return false}
      view.result=result;renderResult(result);return true;
    }catch(error){if(isCurrent())failure(null);else sync();return false}
    finally{if(flight===current){flight=null;setBusy(false)}}
  }
  async function insertDraft(){
    sync();if(!view?.result?.should_draft||flight||view.stale||!currentSnapshot(view.snapshot))return false;
    const saved=view.snapshot,result=view.result;
    const current={ticket:sequence,controller:typeof AbortController==='function'?new AbortController():null};flight=current;setBusy(true);status.textContent='Проверяем, что контекст не изменился…';
    const isCurrent=()=>flight===current&&current.ticket===sequence&&view?.result===result&&currentSnapshot(saved);
    try{
      const checked=await W.request('ai-draft.php','validate',{conversation_id:saved.context.conversationId,latest_message_id:saved.latest,revision:result.revision},{signal:current.controller?.signal,isCurrent});
      if(!isCurrent()){sync();return false}
      if(checked?.ok!==true||checked.revision!==result.revision||checked.latest_message_id!==saved.latest){
        if(checked?.http_status===403||checked?.http_status===404){resetSession();return false}
        stale();return false;
      }
      // Conversation remains the only draft/submit owner. Append to its current
      // text, including edits made while validation was pending; never touch File.
      const savedSelection=selection();
      if(!conversation().insertQuickReply(result.draft,saved.context)){
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
    retry=document.createElement('button');retry.id='aiDraftRetry';retry.type='button';retry.textContent='Сформировать заново';actions.appendChild(retry);panel.appendChild(actions);form.appendChild(panel);
    bound=true;button.addEventListener('click',()=>{if(view)close();else void generate()});dismiss.addEventListener('click',()=>close());
    retry.addEventListener('click',()=>void generate());insert.addEventListener('click',()=>void insertDraft());
    panel.addEventListener('keydown',event=>{if(event.key==='Escape'){event.preventDefault();event.stopPropagation();close()}});
    for(const event of ['input','select','keyup','pointerup'])reply.addEventListener(event,rememberSelection);
    window.addEventListener('pagehide',resetSession);
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
  window.WorkspaceV2AiDraft={bind,sync,resetSession};
  if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',bind,{once:true});else bind();
})();
