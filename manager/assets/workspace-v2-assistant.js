(function(){
  'use strict';
  const W=window.WorkspaceV2;
  if(!W||!document.body.classList.contains('manager2'))return;
  const {S,$,esc}=W;
  const labels={clarify:'Уточнить запрос',answer_question:'Ответить на вопрос',check_offer:'Проверить предложение',prepare_selection:'Подготовить подборку',no_contact:'Сейчас не писать'};
  let owner=0,epoch=0,modal=0,dialog=null,opener=null,selected=0,cursor=null,hasMore=false,busy=false,detailTicket=0,listTicket=0,filter='all',checkedAt='',timer=null,signature='';
  let rows=new Map(),pending=new Set(),statusText='',statusError=false;
  const rendered=new WeakMap();
  const authenticated=()=>owner>0&&!S.authExpired&&Number(S.manager?.id)===owner;
  const valid=(scope)=>scope===epoch&&authenticated();
  function fresh(item){const r=item?.recommendation,raw=String(r?.expires_at||'');const date=window.ManagerTime?.parse?.(raw);const deadline=date?date.getTime():Date.parse(raw.replace(' ','T')+(/(?:Z|[+-]\d{2}:?\d{2})$/.test(raw)?'':'Z'));return !!r&&deadline>Date.now()}
  function active(r){return r&&!['done','irrelevant'].includes(r.decision)&&r.next_action!=='no_contact'}
  function status(text='',error=false){statusText=text;statusError=error;if($('assistantStatus')){$('assistantStatus').textContent=text;$('assistantStatus').classList.toggle('isError',error)}}
  function errorText(result){return({forbidden:'Доступ к клиенту изменился. Обновите список.',stale_context:'Переписка изменилась. Загрузите актуальную рекомендацию.',list_changed:'Приоритет или доступ изменились. Список нужно загрузить заново.',decision_conflict:'Отметка уже изменилась. Загружено текущее состояние.',csrf:'Обновите кабинет и повторите вход.'})[result?.error]||'Не удалось загрузить данные. Попробуйте обновить список.'}
  async function request(action,data={},scope=epoch){
    try{return await W.request('assistant-api.php',action,data,{isCurrent:()=>valid(scope)})}
    catch{return{ok:false,error:'network_error'}}
  }
  function time(value){return window.ManagerTime?.format(value,{year:true})||String(value||'')}
  function renderMarkup(root,html,key=''){
    const previous=rendered.get(root);
    // Compare intended output, not live innerHTML: opening native details changes
    // its serialized attributes. Identical reads must retain the actual nodes.
    if(previous?.key===key&&previous.html===html)return false;
    root.innerHTML=html;rendered.set(root,{key,html});return true;
  }
  function clear(){rows.clear();selected=0;cursor=null;hasMore=false;pending.clear();checkedAt='';signature='';statusText='';statusError=false;busy=false;listTicket++;detailTicket++;if(dialog){for(const id of ['assistantList','assistantDetail']){const root=$(id);root.replaceChildren();rendered.delete(root)}$('assistantStatus').textContent=''}}
  function activate(id){epoch++;owner=Number(id||0);close();clear();sync()}
  function suspend(){epoch++;owner=0;close();clear();sync()}
  function bind(){
    if(!$('assistantOpen')){
      const rail=document.querySelector('.m2Rail');if(!rail)return;
      const button=document.createElement('button');button.type='button';button.id='assistantOpen';button.className='m2Nav assistantNav';button.innerHTML='<span class="assistantNavMark" aria-hidden="true">✦</span><span>Помощник</span>';button.setAttribute('aria-haspopup','dialog');button.onclick=()=>open();rail.insertBefore(button,rail.querySelector('.m2RailEnd'));
    }
    sync();
  }
  function sync(c=S.detail?.conversation){
    const global=$('assistantOpen');if(global)global.disabled=!authenticated();
    const own=authenticated()&&Number(c?.id)===Number(S.current)&&Number(c?.manager_id)===owner&&c?.status==='manager';
    let button=$('assistantClientOpen');
    const host=document.querySelector('.m2ConversationMenu>div')||document.querySelector('.conversationTools');
    if(!button&&host){button=document.createElement('button');button.type='button';button.id='assistantClientOpen';button.className='actionBtn assistantClientOpen';button.textContent='Рекомендации';button.setAttribute('aria-haspopup','dialog');button.onclick=()=>{const menu=button.closest?.('details');if(menu)menu.open=false;void open(Number(S.current))};host.appendChild(button)}
    if(button)button.hidden=!own;
    if(!authenticated()){close();clear();return}
    if(!dialog?.open)return;
    const next=JSON.stringify([S.current,c?.manager_id,c?.status,S.detail?.messages]);
    if(signature&&signature!==next&&selected===Number(S.current)){
      // Hide old reasoning immediately while the canonical snapshot is checked.
      const item=rows.get(selected);if(item)item.recommendation=null;
      if($('assistantTaskEditor')?.querySelector('form')){const advice=$('assistantDetail').querySelector('.assistantRecommendation');if(advice)advice.hidden=true;status('Переписка изменилась. Сохраните или отмените новый срок, затем обновится рекомендация.')}
      else{render();void loadDetail(selected)}
    }
    signature=next;
  }
  function ensureDialog(){
    if(dialog)return;
    dialog=document.createElement('dialog');dialog.id='assistantDialog';dialog.className='assistantDialog';dialog.setAttribute('aria-labelledby','assistantTitle');
    dialog.innerHTML='<header class="assistantHead"><div><h2 id="assistantTitle">Помощник менеджера</h2><p>Рекомендации и существующие задачи по моим активным клиентам</p></div><button id="assistantClose" type="button">Закрыть</button></header><div class="assistantToolbar"><div class="assistantFilters" role="group" aria-label="Показать клиентов"><button type="button" data-assistant-filter="all">Все клиенты</button><button type="button" data-assistant-filter="actions">Следующие шаги</button><button type="button" data-assistant-filter="tasks">С задачами</button><button type="button" data-assistant-filter="done">Отмеченные</button></div><button id="assistantRefresh" type="button">Обновить</button></div><div id="assistantStatus" class="assistantStatus" role="status" aria-live="polite"></div><p id="assistantCoverage" class="assistantCoverage"></p><div class="assistantBody"><section class="assistantClients" aria-label="Мои клиенты"><div id="assistantList"></div><button id="assistantMore" type="button" hidden>Ещё клиенты</button></section><section id="assistantDetail" class="assistantDetail" aria-label="Рекомендация по клиенту"></section></div>';
    document.body.appendChild(dialog);
    $('assistantClose').onclick=close;dialog.addEventListener('cancel',event=>{event.preventDefault();close()});dialog.addEventListener('keydown',event=>event.stopPropagation());
    $('assistantRefresh').onclick=()=>loadList(false);$('assistantMore').onclick=()=>loadList(true);
    dialog.querySelectorAll('[data-assistant-filter]').forEach(button=>button.onclick=()=>{filter=button.dataset.assistantFilter;render()});
  }
  async function open(id=0){
    if(!authenticated())return false;
    ensureDialog();modal++;const opened=modal,scope=epoch;opener=document.activeElement;selected=Number(id||0);signature=JSON.stringify([S.current,S.detail?.conversation?.manager_id,S.detail?.conversation?.status,S.detail?.messages]);
    if(!dialog.open)dialog.showModal();render();
    const flight=id?loadDetail(Number(id)):loadList(false),initialDetail=detailTicket,initialList=listTicket;
    const loaded=await flight;
    if(valid(scope)&&dialog.open&&modal===opened){if(loaded&&detailTicket===initialDetail&&listTicket===initialList)$('assistantClose').focus();schedule()}
    return true;
  }
  function close({restoreFocus=true}={}){modal++;clearTimeout(timer);timer=null;detailTicket++;listTicket++;busy=false;if(dialog?.open){dialog.close();if(restoreFocus&&authenticated()&&opener?.isConnected){if(opener.id==='assistantClientOpen'){const menu=opener.closest?.('details');if(menu)menu.open=true}opener.focus({preventScroll:true})}}}
function schedule(){clearTimeout(timer);if(!dialog?.open||!authenticated())return;timer=setTimeout(()=>{if(!dialog?.open)return;if(!$('assistantTaskEditor')?.querySelector('form')){render();if(selected)void loadDetail(selected)}else if(!fresh(rows.get(selected))){const advice=$('assistantDetail').querySelector('.assistantRecommendation');if(advice)advice.hidden=true}schedule()},20000)}
  async function loadList(more=false){
    if(!authenticated()||busy)return false;
    if($('assistantTaskEditor')?.querySelector('form')){status('Сохраните или отмените новый срок перед обновлением списка.');return false}
    const scope=epoch,ticket=++listTicket,selectionTicket=detailTicket;busy=true;status('Загружаем клиентов…');renderBusy();
    const result=await request('list',{limit:20,...(more&&cursor?{cursor}:{})},scope);
    if(!valid(scope)||ticket!==listTicket||!dialog?.open)return false;
    busy=false;
    if(!result.ok||!Array.isArray(result.items)){if(more&&result.error==='list_changed'){rows.clear();cursor=null;hasMore=false;selected=0;detailTicket++;render();return loadList(false)}if(result.error==='forbidden')clear();status(errorText(result),true);renderBusy();return false}
    const newerSelected=selectionTicket!==detailTicket?rows.get(selected):null;
    if(!more)rows.clear();
    for(const item of result.items)rows.set(Number(item.conversation_id),item);
    if(newerSelected)rows.set(Number(newerSelected.conversation_id),newerSelected);
    cursor=result.next_cursor;hasMore=!!result.has_more;checkedAt=new Date().toISOString();
    if(selected&&!rows.has(selected))selected=0;
    status('');render();return true;
  }
  async function loadDetail(id){
    if(!authenticated())return false;
    const scope=epoch,ticket=++detailTicket;selected=id;render();
    const result=await request('detail',{conversation_id:id},scope);
    if(!valid(scope)||ticket!==detailTicket||selected!==id||!dialog?.open)return false;
    if(!result.ok||!result.item){rows.delete(id);selected=0;status(errorText(result),true);render();return false}
    rows.set(id,result.item);status('');render();return true;
  }
  function reviewNeeded(item){return !!item?.operational?.awaiting_manager_reply&&!(fresh(item)&&item.recommendation.next_action==='no_contact')}
  function arrived(item){return !!item?.operational?.due_task||(item.tasks||[]).some(t=>t.status==='open'&&(t.overdue||t.due_state==='overdue'||(t.due_at_utc&&Date.parse(String(t.due_at_utc).replace(' ','T')+(/(?:Z|[+-]\d{2}:?\d{2})$/.test(t.due_at_utc)?'':'Z'))<=Date.now())))}
  function rank(item){if(reviewNeeded(item))return 0;if(arrived(item))return 1;if(fresh(item)&&active(item.recommendation))return 2;if((item.tasks||[]).some(t=>t.status==='open'))return 3;return 4}
  function title(item){if(reviewNeeded(item))return 'Проверить обращение без ответа';const task=(item.tasks||[]).find(t=>t.status==='open');if(task)return task.title;return fresh(item)?labels[item.recommendation.next_action]||'Рекомендация':'Нет свежего разбора'}
  function matches(item){if(filter==='tasks')return(item.tasks||[]).some(t=>t.status==='open');if(filter==='actions')return rank(item)<4;if(filter==='done')return fresh(item)&&['done','irrelevant'].includes(item.recommendation.decision);return true}
  function renderBusy(){if(!dialog)return;$('assistantRefresh').disabled=busy;$('assistantMore').disabled=busy;dialog.setAttribute('aria-busy',busy?'true':'false')}
  function render(){
    if(!dialog?.open||!authenticated())return;
    const items=[...rows.values()].filter(matches).sort((a,b)=>rank(a)-rank(b)||Number(b.conversation_id)-Number(a.conversation_id));
    const list=$('assistantList'),focus=document.activeElement,focusedClient=list.contains(focus)?Number(focus.dataset?.assistantClient||0):0;
    const listChanged=renderMarkup(list,items.length?items.map(item=>{const id=Number(item.conversation_id),r=fresh(item)?item.recommendation:null;return`<button class="assistantClient${id===selected?' isSelected':''}" type="button" data-assistant-client="${id}" aria-pressed="${id===selected}"><strong>${esc(item.display_name||'Клиент')}</strong><span>${esc(title(item))}</span><small>${esc(r&&['done','irrelevant'].includes(r.decision)?r.decision==='done'?'Выполнено':'Неактуально':reviewNeeded(item)?'Есть обращение без записанного ответа':arrived(item)?'Наступил срок задачи':r?.next_action==='no_contact'?'Ожидание клиента':r?'Свежая рекомендация':'Задачи доступны · разбора пока нет')}</small></button>`}).join(''):'<p class="assistantEmpty">В загруженной части списка таких клиентов нет.</p>');
    $('assistantList').querySelectorAll('[data-assistant-client]').forEach(button=>button.onclick=async()=>{const id=Number(button.dataset.assistantClient),loaded=await loadDetail(id);if(loaded&&dialog?.open&&selected===id)$(window.matchMedia?.('(max-width:767px)')?.matches?'assistantBack':'assistantConversation')?.focus()});
    if(listChanged&&focusedClient&&!focus.isConnected)(list.querySelector(`[data-assistant-client="${focusedClient}"]`)||$('assistantRefresh'))?.focus({preventScroll:true});
    $('assistantCoverage').textContent=`Загружено клиентов: ${rows.size}${hasMore?' · есть ещё':''}. Сначала обращения без ответа и наступившие задачи. AI-рекомендации — только со свежим разбором.${checkedAt?' Список обновлён '+time(checkedAt)+' МСК.':''}`;
    $('assistantMore').hidden=!hasMore;dialog.querySelectorAll('[data-assistant-filter]').forEach(button=>button.setAttribute('aria-pressed',String(button.dataset.assistantFilter===filter)));
    dialog.classList.toggle('hasSelection',selected>0);renderDetail();renderBusy();status(statusText,statusError);
  }
  function renderDetail(){
    const root=$('assistantDetail'),item=rows.get(selected),focus=document.activeElement,hadFocus=!!focus&&root.contains(focus),previousKey=rendered.get(root)?.key;
    if(!item){const changed=renderMarkup(root,'<p class="assistantEmpty">Выберите клиента, чтобы увидеть основание рекомендации и задачи.</p>',0);if(changed&&hadFocus)$('assistantClose')?.focus({preventScroll:true});return}
    const r=fresh(item)?item.recommendation:null,id=Number(item.conversation_id),tasks=(item.tasks||[]).filter(t=>t.status==='open'),blocked=pending.has(id);
    const changed=renderMarkup(root,`<button id="assistantBack" class="assistantBack" type="button">← К списку</button><div class="assistantClientHead"><div><h3>${esc(item.display_name||'Клиент')}</h3><span>${esc(item.channel||'')} · ${esc(time(item.last_message_at))}</span></div><button id="assistantConversation" type="button">Открыть диалог</button></div>${reviewNeeded(item)||arrived(item)?`<article class="assistantOperational"><h4>${reviewNeeded(item)?'Обращение без записанного ответа':'Наступил срок задачи'}</h4><p>${reviewNeeded(item)?'Откройте переписку и проверьте, нужен ли ответ.':'Проверьте существующую задачу и её срок ниже.'}</p></article>`:''}${r?`<article class="assistantRecommendation"><div class="assistantKicker">Рекомендация · ${esc(labels[r.next_action]||'Следующий шаг')}</div><h4>${r.decision==='done'?'Отмечено выполненным':r.decision==='irrelevant'?'Отмечено неактуальным':r.next_action==='no_contact'?'Дождаться клиента':esc(labels[r.next_action]||'Следующий шаг')}</h4><p>${esc(r.reason)}</p>${r.checks?.length?`<ul>${r.checks.map(text=>`<li>${esc(text)}</li>`).join('')}</ul>`:''}${r.history_incomplete?'<p class="assistantWarning">В доступной истории есть пропуски. Перед действием проверьте переписку.</p>':''}<details class="assistantSource"><summary>Основание и актуальность</summary><p>По переписке до сообщения ${Number(r.latest_message_id)||'—'}. Подготовлено ${esc(time(r.prepared_at))} МСК. Действительно до ${esc(time(r.expires_at))} МСК.</p><p>Сообщений в основании: ${(r.evidence_message_ids||[]).length}. Полный контекст — в диалоге.</p></details><div class="assistantActions">${['done','irrelevant'].includes(r.decision)?'<button type="button" data-assistant-decision="open">Вернуть в работу</button>':r.next_action==='no_contact'?'':`<button type="button" data-assistant-decision="done">Выполнено</button><button type="button" id="assistantPlan">Запланировать</button><button type="button" data-assistant-decision="irrelevant">Неактуально</button>`}</div></article>`:'<div class="assistantEmpty"><strong>Нет свежего разбора</strong><p>Это не означает, что по клиенту всё сделано. Проверьте переписку и существующие задачи.</p></div>'}<section class="assistantTasks"><h4>Задачи и напоминания</h4>${tasks.length?tasks.map(task=>`<article class="assistantTask"><strong>${esc(task.title)}</strong><span>${esc({overdue:'Просрочено',today:'Сегодня',upcoming:'Запланировано',unscheduled:'Без срока'}[task.due_state]||'Без срока')}${task.due_at_utc?' · '+esc(time(task.due_at_utc))+' МСК':''}</span><div><button type="button" data-assistant-task="${Number(task.id)}">Выполнено</button><button type="button" data-assistant-snooze="${Number(task.id)}">Перенести</button></div></article>`).join(''):'<p>Открытых задач нет.</p>'}${item.tasks_truncated?'<p>Показаны первые 50 задач. Полный список — в карточке клиента.</p>':''}</section><div id="assistantTaskEditor"></div>`,id);
    $('assistantBack').onclick=()=>{const old=selected;selected=0;detailTicket++;render();dialog.querySelector(`[data-assistant-client="${old}"]`)?.focus()};
    $('assistantConversation').onclick=()=>openConversation(id);
    root.querySelectorAll('[data-assistant-decision]').forEach(button=>{button.disabled=blocked;button.onclick=()=>decide(item,button.dataset.assistantDecision)});
    const plan=$('assistantPlan');if(plan){plan.disabled=blocked;plan.onclick=()=>planTask(item)}
    root.querySelectorAll('[data-assistant-task]').forEach(button=>{button.disabled=blocked;button.onclick=()=>taskMutation(item,{action:'set_task_completed',task_id:Number(button.dataset.assistantTask),completed:true})});
    root.querySelectorAll('[data-assistant-snooze]').forEach(button=>{button.disabled=blocked;button.onclick=()=>editTask(item,tasks.find(task=>Number(task.id)===Number(button.dataset.assistantSnooze)))});
    // Bind even retained nodes to the freshly read item/revision. A removed
    // focused action moves to a stable control only within the same client.
    if(changed&&hadFocus&&previousKey===id&&!focus.isConnected)$('assistantConversation')?.focus({preventScroll:true});
  }
  async function decide(item,decision){
    const id=Number(item.conversation_id),r=item.recommendation,scope=epoch,opened=modal;if(pending.has(id)||!fresh(item))return false;
    pending.add(id);renderDetail();status('Сохраняем отметку…');
    const result=await request('decision',{conversation_id:id,revision:r.revision,expected_decision:r.decision??null,decision},scope);
    if(!valid(scope))return false;
    pending.delete(id);
    // Reads reconcile uncertain results; they never repeat the write.
    if(!dialog?.open||modal!==opened||selected!==id)return !!result.ok;
    await loadDetail(id);if(!result.ok&&dialog?.open&&modal===opened&&selected===id)status(result.error==='network_error'?'Исход отметки не подтверждён. Показано доступное текущее состояние; запись не повторялась.':errorText(result),true);
    return !!result.ok;
  }
  async function openConversation(id){
    if(!authenticated())return false;
    if(protectTaskDraft())return false;
    const mobileHistory=Number(S.current)===Number(id)&&window.WorkspaceV2Mobile?.getScreen?.()==='conversation'?'none':'push';
    close({restoreFocus:false});return window.WorkspaceV2Conversation?.open(id,{preserveAttachment:true,preserveMessageScroll:true,preserveHistory:true,mobileHistory});
  }
  function protectTaskDraft(){
    if(!window.WorkspaceV2Tasks?.blockNavigationForDirtyDraft?.())return false;
    close();window.WorkspaceV2Tasks.blockNavigationForDirtyDraft();return true;
  }
  async function planTask(item){
    const id=Number(item.conversation_id),scope=epoch;
    // Planning uses the existing task editor. No second create endpoint or hidden reminder.
    if(!await openConversation(id)||!valid(scope)||Number(S.current)!==id)return false;
    await window.WorkspaceV2Conversation?.refreshLeadData({conversationId:id});
    if(!valid(scope)||Number(S.current)!==id)return false;
    const root=$('leadTasksBody');if(!root)return false;
    if((item.tasks||[]).some(task=>task.status==='open')){window.Manager2Presentation?.openClientCard($('mobileLeadBtn'));root.scrollIntoView({block:'nearest'});return true}
    const form=root.querySelector('.taskCreate'),title=root.querySelector('#leadTaskTitle');
    if(!form||!title)return false;
    if(!title.value&&!root.querySelector('#leadTaskDue')?.value){title.value=String(item.recommendation?.checks?.[0]||labels[item.recommendation?.next_action]||'Проверить запрос клиента').slice(0,255);title.dispatchEvent(new Event('input',{bubbles:true}))}
    window.Manager2Presentation?.revealTaskDraft(form);title.focus();return true;
  }
  function editTask(item,task){
    if(!task)return;
    const host=$('assistantTaskEditor');host.innerHTML=`<form class="assistantTaskEditor"><label for="assistantDue">Новый срок · МСК</label><input id="assistantDue" type="datetime-local" required value="${esc(window.ManagerTime?.toInput(task.due_at_utc)||'')}"><div><button type="submit">Сохранить срок</button><button type="button" id="assistantCancelDue">Отмена</button></div></form>`;
    $('assistantCancelDue').onclick=()=>{host.replaceChildren();$('assistantConversation')?.focus({preventScroll:true});return loadDetail(Number(item.conversation_id))};host.querySelector('form').onsubmit=event=>{event.preventDefault();const due=window.ManagerTime?.fromInput($('assistantDue').value);if(!due){status('Укажите корректные дату и время по МСК.',true);return}void taskMutation(item,{action:'update_task',task_id:Number(task.id),title:task.title,due_at:due})};$('assistantDue').focus();
  }
  async function taskMutation(item,data){
    const id=Number(item.conversation_id),scope=epoch,opened=modal;if(pending.has(id))return false;
    if(protectTaskDraft())return false;
    // Save explicitly consumes this deadline editor, even if the canonical task
    // already has the requested value and the following read is identical.
    if(data.action==='update_task'){$('assistantTaskEditor')?.replaceChildren();$('assistantConversation')?.focus({preventScroll:true})}
    pending.add(id);renderDetail();status('Сохраняем задачу…');let result;
    try{const {action,...payload}=data;result=await W.pipe(action,{conversation_id:id,...payload},{isCurrent:()=>valid(scope)})}catch{result={ok:false,error:'network_error'}}
    if(!valid(scope))return false;pending.delete(id);
    if(!dialog?.open||modal!==opened||selected!==id)return !!result.ok;
    await loadDetail(id);
    if(!dialog?.open||modal!==opened||selected!==id)return !!result.ok;
    if(result.ok){status('Задача сохранена.');if(Number(S.current)===id)void window.WorkspaceV2Conversation?.refreshLeadData({conversationId:id,refreshInbox:true})}
    else status(['network_error','invalid_response'].includes(result.error)?'Исход изменения неизвестен. Проверьте текущее состояние задачи; повторного запроса не было.':errorText(result),true);
    return !!result.ok;
  }
  window.WorkspaceV2Assistant={bind,activate,suspend,sync,open,close,loadList,loadDetail};
})();
