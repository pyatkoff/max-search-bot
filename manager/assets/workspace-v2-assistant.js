(function(){
  'use strict';
  const W=window.WorkspaceV2;
  if(!W||!document.body.classList.contains('manager2'))return;
  const {S,$,esc}=W;
  const labels={clarify:'Уточнить запрос',answer_question:'Ответить на вопрос',check_offer:'Проверить предложение',prepare_selection:'Подготовить подборку',no_contact:'Сейчас не писать'};
  let owner=0,epoch=0,modal=0,dialog=null,opener=null,selected=0,selectionVersion=0,cursor=null,hasMore=false,busy=false,detailTicket=0,listTicket=0,filter='all',checkedAt='',timer=null,signature='';
  let rows=new Map(),pending=new Set(),statusText='',statusError=false;
  let planMode=false,workScope='mine';
  let promiseNavigation=0;
  const rendered=new WeakMap();
  const authenticated=()=>owner>0&&!S.authExpired&&Number(S.manager?.id)===owner;
  const valid=(scope)=>scope===epoch&&authenticated();
  function fresh(item){const r=item?.recommendation,raw=String(r?.expires_at||'');const date=window.ManagerTime?.parse?.(raw);const deadline=date?date.getTime():Date.parse(raw.replace(' ','T')+(/(?:Z|[+-]\d{2}:?\d{2})$/.test(raw)?'':'Z'));return !item?.delivery_suspended&&!!r&&deadline>Date.now()}
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
  function activate(id){epoch++;owner=Number(id||0);workScope='mine';close();clear();sync()}
  function suspend(){epoch++;owner=0;close();clear();sync()}
  function bind(){sync()}
  function sync(c=S.detail?.conversation){
    const global=$('assistantOpen');if(global)global.disabled=!authenticated();
    const own=authenticated()&&Number(c?.id)===Number(S.current)&&((Number(c?.manager_id)===owner&&c?.status==='manager')||(S.manager?.role==='admin'&&['manager','waiting_manager'].includes(c?.status)));
    let button=$('assistantClientOpen');
    const host=document.querySelector('.m2ConversationMenu>div')||document.querySelector('.conversationTools');
    if(!button&&host&&!document.body.classList.contains('manager2')){button=document.createElement('button');button.type='button';button.id='assistantClientOpen';button.className='actionBtn assistantClientOpen';button.textContent='Помощник в диалоге';button.setAttribute('aria-controls','aiDraftPanel');button.onclick=()=>{const menu=button.closest?.('details');if(menu)menu.open=false;window.WorkspaceV2AiDraft?.open?.()};host.appendChild(button)}
    if(button)button.hidden=!own||document.body.classList.contains('manager2');
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
    dialog.innerHTML='<header class="assistantHead"><div><h2 id="assistantTitle">Обзор работы</h2><p id="assistantSubtitle">Рекомендации и существующие задачи по моим активным клиентам</p></div><button id="assistantClose" type="button">Закрыть</button></header><div class="assistantToolbar"><div class="assistantFilters" role="group" aria-label="Показать клиентов"><button type="button" data-assistant-filter="all">Все клиенты</button><button type="button" data-assistant-filter="actions">Следующие шаги</button><button type="button" data-assistant-filter="tasks">С задачами</button><button type="button" data-assistant-filter="waiting">Сейчас не писать</button><button type="button" data-assistant-filter="done">Отмеченные</button></div><button id="assistantRefresh" type="button">Обновить</button></div><div id="assistantStatus" class="assistantStatus" role="status" aria-live="polite"></div><p id="assistantCoverage" class="assistantCoverage"></p><div class="assistantBody"><section class="assistantClients" aria-label="Мои клиенты"><div id="assistantList"></div><button id="assistantMore" type="button" hidden>Ещё клиенты</button></section><section id="assistantDetail" class="assistantDetail" aria-label="Рекомендация по клиенту"></section></div>';
    document.body.appendChild(dialog);
    $('assistantClose').onclick=()=>planMode?window.Manager2Presentation?.navigate('dialogues'):close();dialog.addEventListener('cancel',event=>{event.preventDefault();if(planMode)window.Manager2Presentation?.navigate('dialogues');else close()});dialog.addEventListener('keydown',event=>event.stopPropagation());
    const scope=document.createElement('div');scope.className='assistantWorkScope';scope.innerHTML='<label for="assistantScope">Клиенты</label><select id="assistantScope"><option value="mine">Мои</option><option value="team">Команда</option></select>';dialog.insertBefore(scope,dialog.querySelector('.assistantToolbar'));
    $('assistantScope').onchange=()=>{if(busy||pending.size||!canLeavePlan()){$('assistantScope').value=workScope;return}workScope=S.manager?.role==='admin'&&$('assistantScope').value==='team'?'team':'mine';rows.clear();cursor=null;hasMore=false;selected=0;detailTicket++;render();void loadList(false)};
    $('assistantRefresh').onclick=()=>loadList(false);$('assistantMore').onclick=()=>loadList(true);
    dialog.querySelectorAll('[data-assistant-filter]').forEach(button=>button.onclick=()=>{filter=button.dataset.assistantFilter;render()});
  }
  async function open(id=0,{plan=false}={}){
    if(!authenticated())return false;
    ensureDialog();if(planMode!==plan){if(dialog.open)dialog.close();clear();}modal++;const opened=modal,scope=epoch;opener=document.activeElement;selected=Number(id||0);signature=JSON.stringify([S.current,S.detail?.conversation?.manager_id,S.detail?.conversation?.status,S.detail?.messages]);
    planMode=plan;
    dialog.classList.toggle('assistantPlanView',planMode);
    if(planMode){const host=document.querySelector('.inboxZone');if(!host)return false;if(dialog.parentElement!==host)host.appendChild(dialog);dialog.setAttribute('role','region');if(!dialog.open)dialog.show();filter='actions';}
    else{if(dialog.parentElement!==document.body)document.body.appendChild(dialog);dialog.setAttribute('role','dialog');if(!dialog.open)dialog.showModal();}
    render();
    const flight=id?loadDetail(Number(id)):loadList(false),initialDetail=detailTicket,initialList=listTicket;
    const loaded=await flight;
    if(valid(scope)&&dialog.open&&modal===opened){if(loaded&&detailTicket===initialDetail&&listTicket===initialList)$('assistantClose').focus();schedule()}
    return true;
  }
  function canLeavePlan(){if(!planMode||!$('assistantTaskEditor')?.querySelector('form'))return true;status('Сохраните или отмените новый срок перед переходом.');$('assistantDue')?.focus({preventScroll:true});return false;}
  function close({restoreFocus=true}={}){modal++;clearTimeout(timer);timer=null;detailTicket++;listTicket++;busy=false;if(dialog?.open){dialog.close();if(restoreFocus&&authenticated()&&opener?.isConnected){if(opener.id==='assistantClientOpen'){const menu=opener.closest?.('details');if(menu)menu.open=true}opener.focus({preventScroll:true})}}planMode=false;if(dialog){dialog.classList.remove('assistantPlanView');dialog.setAttribute('role','dialog');if(dialog.parentElement!==document.body)document.body.appendChild(dialog)}syncPromiseViews()}
function schedule(){clearTimeout(timer);if(!dialog?.open||!authenticated())return;timer=setTimeout(()=>{if(!dialog?.open)return;if(!$('assistantTaskEditor')?.querySelector('form')){render();if(selected)void loadDetail(selected)}else if(!fresh(rows.get(selected))){const advice=$('assistantDetail').querySelector('.assistantRecommendation');if(advice)advice.hidden=true}schedule()},20000)}
  async function loadList(more=false){
    if(!authenticated()||busy)return false;
    if($('assistantTaskEditor')?.querySelector('form')){status('Сохраните или отмените новый срок перед обновлением списка.');return false}
    const scope=epoch,ticket=++listTicket,selectionTicket=detailTicket;busy=true;status('Загружаем клиентов…');renderBusy();
    const result=await request('list',{limit:20,...(planMode?{scope:workScope}:{}),...(more&&cursor?{cursor}:{})},scope);
    if(!valid(scope)||ticket!==listTicket||!dialog?.open)return false;
    busy=false;
    if(!result.ok||!Array.isArray(result.items)){if(more&&result.error==='list_changed'){rows.clear();cursor=null;hasMore=false;selected=0;detailTicket++;render();return loadList(false)}if(result.error==='forbidden')clear();status(errorText(result),true);renderBusy();return false}
    const newerSelected=selectionTicket!==detailTicket?rows.get(selected):null;
    if(!more)rows.clear();
    for(const item of result.items)rows.set(Number(item.conversation_id),item);
    if(newerSelected)rows.set(Number(newerSelected.conversation_id),newerSelected);
    cursor=result.next_cursor;hasMore=!!result.has_more;checkedAt=new Date().toISOString();
    if(selected&&!rows.has(selected)){selected=0;selectionVersion++}
    status('');render();return true;
  }
  async function loadDetail(id){
    if(!authenticated())return false;
    if(selected!==id)selectionVersion++;
    const scope=epoch,ticket=++detailTicket;selected=id;render();
    const result=await request('detail',{conversation_id:id},scope);
    if(!valid(scope)||ticket!==detailTicket||selected!==id||!dialog?.open)return false;
    if(!result.ok||!result.item){rows.delete(id);selected=0;status(errorText(result),true);render();return false}
    rows.set(id,result.item);status('');render();return true;
  }
  function reviewNeeded(item){return !item?.delivery_suspended&&!!item?.operational?.awaiting_manager_reply&&!(fresh(item)&&item.recommendation.next_action==='no_contact')}
  function arrived(item){return !!item?.operational?.due_task||(item.tasks||[]).some(t=>t.status==='open'&&(t.overdue||t.due_state==='overdue'||(t.due_at_utc&&Date.parse(String(t.due_at_utc).replace(' ','T')+(/(?:Z|[+-]\d{2}:?\d{2})$/.test(t.due_at_utc)?'':'Z'))<=Date.now())))}
  function rank(item){if(reviewNeeded(item))return 0;if(arrived(item))return 1;if(fresh(item)&&active(item.recommendation))return 2;if((item.tasks||[]).some(t=>t.status==='open'))return 3;return 4}
  function title(item){if(reviewNeeded(item))return 'Разобрать сообщение клиента';const task=(item.tasks||[]).find(t=>t.status==='open');if(task)return task.title;if(fresh(item)&&item.recommendation.next_action==='no_contact')return 'Сейчас не писать';if(item?.delivery_suspended)return 'Клиент недоступен в MAX';return fresh(item)?labels[item.recommendation.next_action]||'Рекомендация':'Нет свежего разбора'}
  function workStatus(item,r){
    if(reviewNeeded(item))return 'Есть обращение без записанного ответа';
    if(arrived(item))return 'Наступил срок задачи';
    if((item.tasks||[]).some(task=>task.status==='open'))return 'Есть открытая задача';
    if(r&&['done','irrelevant'].includes(r.decision))return r.decision==='done'?'Выполнено':'Неактуально';
    if(item.delivery_suspended)return 'Ожидание новой активности клиента';
    if(r?.next_action==='no_contact')return 'Исходящее сообщение сейчас не нужно';
    return r?'Свежая рекомендация':'Задачи доступны · разбора пока нет';
  }
  function cardContext(item){const task=(item.tasks||[]).filter(t=>t.status==='open'&&t.due_at_utc).sort((a,b)=>String(a.due_at_utc).localeCompare(String(b.due_at_utc)))[0];const reason=fresh(item)?String(item.recommendation.reason||''):reviewNeeded(item)?'Есть обращение без записанного ответа':item.delivery_suspended?'Ответ станет доступен после новой активности клиента':'';return {reason,deadline:task?'Срок: '+time(task.due_at_utc)+' МСК':'Срок не назначен'};}
  function matches(item){if(filter==='tasks')return(item.tasks||[]).some(t=>t.status==='open');if(filter==='actions')return rank(item)<4;if(filter==='waiting')return fresh(item)&&item.recommendation.next_action==='no_contact';if(filter==='done')return fresh(item)&&['done','irrelevant'].includes(item.recommendation.decision);return true}
  function renderBusy(){if(!dialog)return;$('assistantRefresh').disabled=busy;$('assistantMore').disabled=busy;if($('assistantScope'))$('assistantScope').disabled=busy||pending.size>0;dialog.setAttribute('aria-busy',busy?'true':'false')}
  function render(){
    if(!dialog?.open||!authenticated())return;
    syncPromiseViews();
    const team=S.manager?.role==='admin'&&(!planMode||workScope==='team');$('assistantTitle').textContent=planMode?'Мой план':team?'Обзор работы владельца':'Обзор работы менеджера';$('assistantSubtitle').textContent=planMode?(team?'Действия по клиентам команды в доступных проектах':'Обращения, обещания и следующие шаги по моим клиентам'):(team?'Клиенты всей команды во всех доступных проектах':'Следующие шаги и существующие задачи по моим активным клиентам');$('assistantClose').textContent=planMode?'К диалогам':'Закрыть';$('assistantScope').parentElement.hidden=!planMode||S.manager?.role!=='admin';$('assistantScope').value=workScope;
    const items=[...rows.values()].filter(matches).sort((a,b)=>rank(a)-rank(b)||Number(b.conversation_id)-Number(a.conversation_id));
    const list=$('assistantList'),focus=document.activeElement,focusedClient=list.contains(focus)?Number(focus.dataset?.assistantClient||0):0;
    const listChanged=renderMarkup(list,items.length?items.map(item=>{const id=Number(item.conversation_id),r=fresh(item)?item.recommendation:null,context=cardContext(item);return`<button class="assistantClient${id===selected?' isSelected':''}" type="button" data-assistant-client="${id}" aria-pressed="${id===selected}"><strong>${esc(item.display_name||'Клиент')}</strong>${team?`<small>${esc(item.project_name||item.project_key||'')} · ${esc(item.manager_name||'Без назначения')}</small>`:''}<span>${esc(title(item))}</span>${planMode?`<small class="assistantCardReason">${esc(context.reason)}</small><small>${esc(context.deadline)}</small>`:""}<small${planMode&&reviewNeeded(item)&&!r?' hidden':''}>${esc(workStatus(item,r))}</small></button>`}).join(''):'<p class="assistantEmpty">В загруженной части списка таких клиентов нет.</p>');
    $('assistantList').querySelectorAll('[data-assistant-client]').forEach(button=>button.onclick=async()=>{const id=Number(button.dataset.assistantClient),loaded=await loadDetail(id);if(loaded&&dialog?.open&&selected===id)$(window.matchMedia?.('(max-width:767px)')?.matches?'assistantBack':'assistantConversation')?.focus()});
    if(listChanged&&focusedClient&&!focus.isConnected)(list.querySelector(`[data-assistant-client="${focusedClient}"]`)||$('assistantRefresh'))?.focus({preventScroll:true});
    $('assistantCoverage').textContent=`Показано клиентов: ${rows.size}${hasMore?' · есть ещё':''}.${checkedAt?' Обновлено '+time(checkedAt)+' МСК.':''}`;
    $('assistantMore').hidden=!hasMore;dialog.querySelectorAll('[data-assistant-filter]').forEach(button=>button.setAttribute('aria-pressed',String(button.dataset.assistantFilter===filter)));
    dialog.classList.toggle('hasSelection',selected>0);renderDetail();renderBusy();status(statusText,statusError);
  }
  const promiseAvailability=['available','none','unavailable','not_applicable'];
  const promiseKinds={send_calculation:'Отправить расчёт',send_selection:'Отправить подборку'};
  const promiseReasons={scope_changed:'Изменились условия автоматического выполнения. Проверьте связь.',source_changed:'Исходное сообщение изменилось.',source_unavailable:'Исходное сообщение недоступно.',trip_changed:'Обещание относится к другой поездке.',profile_changed:'Условия поездки изменились.',profile_source_changed:'Основание условий поездки изменилось.',profile_unavailable:'Условия поездки сейчас недоступны.',task_changed:'Задача изменилась после подтверждения связи.',task_closed:'Задача была завершена.',context_changed:'Пришло новое сообщение; проверьте дальнейший шаг.',publication_changed:'Закреплённый состав требует проверки.',delivery_evidence_changed:'Подтверждение отправки требует проверки.',access_changed:'Доступ к автоматическому выполнению изменился.',task_done:'Задача уже была завершена.',delivery_unknown:'Следующий шаг: проверить результат отправки.',delivery_unverified:'Следующий шаг: проверить подтверждение отправки.',delivery_rejected:'Отправка отклонена. Проверьте её результат в редакторе подборки.'};
  const promiseHex=(value,length)=>typeof value==='string'&&new RegExp('^[a-f0-9]{'+length+'}$').test(value);
  const promiseObject=value=>!!value&&typeof value==='object'&&!Array.isArray(value);
  const promiseCodes=value=>Array.isArray(value)&&value.length<=20&&value.every(code=>typeof code==='string'&&code.length<=128);
  function promiseDeadline(value){
    if(value===null)return true;if(!promiseObject(value)||typeof value.value!=='string')return false;
    if(value.kind==='instant')return /^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:Z|[+-]\d{2}:\d{2})$/.test(value.value)&&!!window.ManagerTime?.parse(value.value);
    if(value.kind!=='date'||value.zone!=='Europe/Moscow'||!/^\d{4}-\d{2}-\d{2}$/.test(value.value))return false;
    const date=new Date(value.value+'T00:00:00Z');return !Number.isNaN(date.getTime())&&date.toISOString().slice(0,10)===value.value;
  }
  function promiseFacts(value){
    if(!promiseObject(value)||!promiseAvailability.includes(value.availability)||!promiseCodes(value.stale_reasons))return null;
    const b=value.binding,s=value.selection,i=s?.intent,p=s?.progress;
    if(b!==null&&(!promiseObject(b)||!promiseHex(b.promise_id,64)||!Object.hasOwn(promiseKinds,b.kind)||!promiseDeadline(b.deadline)||!Number.isSafeInteger(b.source_message_id)||b.source_message_id<=0||!['current','changed','unavailable'].includes(b.source_state)||!promiseHex(b.trip_id,32)||!Number.isSafeInteger(b.profile_version)||b.profile_version<=0))return null;
    if(value.availability==='available'&&!b||!promiseObject(s)||!promiseAvailability.includes(s.availability)||!promiseCodes(s.stale_reasons))return null;
    if(i!==null&&(!promiseObject(i)||!promiseHex(i.intent_id,32)||!promiseHex(i.publication_id,32)||!['armed','completed','cancelled','blocked'].includes(i.state)))return null;
    if(s.availability==='available'&&!i)return null;
    if(p!==null&&(!promiseObject(p)||!['available','unavailable'].includes(p.availability)||p.availability==='available'&&(!Number.isInteger(p.confirmed)||!Number.isInteger(p.total)||p.confirmed<0||p.total<1||p.total>20||p.confirmed>p.total)||p.availability==='unavailable'&&(p.confirmed!==null||p.total!==null&&(!Number.isInteger(p.total)||p.total<1||p.total>20))))return null;
    return value;
  }
  function promisedDue(task,binding){
    const deadline=binding.deadline;if(deadline===null)return '';
    const current=window.ManagerTime?.parse(task.due_at_utc);
    if(deadline.kind==='date'){
      return 'Срок обещания: '+deadline.value.split('-').reverse().join('.')+' · календарный день, Москва';
    }
    const promised=window.ManagerTime.parse(deadline.value);if(current?.getTime()===promised?.getTime())return '';
    return 'Срок обещания: '+time(deadline.value)+':'+String(window.ManagerTime.wallDate(deadline.value).getUTCSeconds()).padStart(2,'0')+' МСК';
  }
  function promiseMarkup(item,task){
    if(item.promise_coverage==='not_loaded')return '<div class="assistantPromiseFacts"><span>Проверяем связь с обещанием…</span></div>';
    if(item.promise_coverage!=='checked')return '';
    const p=promiseFacts(task.promise);if(!p||p.availability==='unavailable')return '<div class="assistantPromiseFacts"><span>Связь с обещанием сейчас недоступна. Обновите план.</span></div>';
    if(p.availability!=='available')return '';
    const b=p.binding,s=p.selection,i=s.intent,progress=s.progress,lines=['Подтверждённое обещание: '+promiseKinds[b.kind]],due=promisedDue(task,b);if(due)lines.push(due);
    if(s.availability==='none'&&b.kind==='send_selection')lines.push('Следующий шаг: закрепить состав подборки.');
    if(s.availability==='unavailable')lines.push('Состояние закреплённой подборки сейчас недоступно.');
    if(s.availability==='available'){
      lines.push(i.state==='armed'?'Автоматическое выполнение ожидает весь закреплённый состав.':i.state==='completed'?'Задача уже завершалась автоматически'+(i.completed_at&&window.ManagerTime.parse(i.completed_at)?' '+time(i.completed_at)+' МСК':'')+'. Сейчас она открыта.':i.state==='cancelled'?'Автоматическое выполнение отменено.':'Автоматическое выполнение остановлено.');
      if(progress?.availability==='available')lines.push('Подтверждена отправка '+progress.confirmed+' из '+progress.total+' вариантов.');
      else lines.push('Подтверждение отправки сейчас недоступно.');
    }
    const warnings=[...p.stale_reasons,...s.stale_reasons,...(i?.reason?[i.reason]:[]),...(progress?.reason?[progress.reason]:[])];if(b.source_state!=='current')warnings.push(b.source_state==='changed'?'source_changed':'source_unavailable');
    for(const message of new Set(warnings.map(code=>promiseReasons[code]||'Проверьте актуальность связи и выполнение вручную.')))lines.push(message);
    const source=b.source_state!=='unavailable'?`<button type="button" data-assistant-promise-source="${Number(task.id)}">Исходное сообщение</button>`:'';
    const publication=s.availability==='available'&&i?`<button type="button" data-assistant-promise-selection="${Number(task.id)}">Состав и отправка</button>`:'';
    return `<div class="assistantPromiseFacts" data-promise-availability="${p.availability}">${lines.map(line=>`<span>${esc(line)}</span>`).join('')}<div class="assistantPromiseActions">${source}${publication}</div></div>`;
  }
  function promiseIdentity(task){const p=promiseFacts(task?.promise),b=p?.binding,i=p?.selection?.intent;return p?.availability==='available'?JSON.stringify([Number(task.id),b.promise_id,b.source_message_id,b.source_state,i?.intent_id||null,i?.publication_id||null]):'';}
  async function openPromiseFact(item,task,kind,button){
    const identity=promiseIdentity(task),p=promiseFacts(task?.promise),id=Number(item.conversation_id),C=window.WorkspaceV2Conversation;
    if(!identity||!authenticated()||!dialog?.open||selected!==id||pending.has(id))return false;
    if(kind==='source'&&p.binding.source_state==='unavailable'||kind==='selection'&&p.selection.availability!=='available')return false;
    const scope=epoch,opened=modal,selection=selectionVersion,run=++promiseNavigation,managerId=Number(S.manager.id),authGeneration=S.authGeneration,origin=JSON.stringify(C?.captureReplyContext?.());
    const current=()=>valid(scope)&&!S.logoutPending&&S.authGeneration===authGeneration&&modal===opened&&selectionVersion===selection&&run===promiseNavigation&&dialog?.open&&selected===id&&origin===JSON.stringify(C?.captureReplyContext?.())&&promiseIdentity(rows.get(id)?.tasks?.find(row=>Number(row.id)===Number(task.id)))===identity;
    if(!current())return false;
    const onReturn=()=>{if(current())($('assistantDetail').querySelector(`[data-assistant-promise-${kind}="${Number(task.id)}"]`)||$('assistantConversation'))?.focus({preventScroll:true})};
    button.disabled=true;let result=false;
    try{
      if(kind==='source')result=await window.WorkspaceV2MessageNavigation?.openSearchTarget?.(p.binding.source_message_id,button,{context:{conversationId:id,managerId,authGeneration},isCurrent:current,returnLabel:'Вернуться к плану',historyOverlay:true,onReturn,title:'Исходное сообщение · '+String(item.display_name||'Турист')});
      else result=await window.Manager2Presentation?.openPromiseSelectionForTask?.({conversation_id:id,task_id:Number(task.id),promise_id:p.binding.promise_id,intent_id:p.selection.intent.intent_id,publication_id:p.selection.intent.publication_id},button,{isCurrent:current,onReturn,label:String(item.display_name||'Турист')});
      if(!result&&current())status('Просмотр сейчас недоступен. Проверьте сообщение в открытом окне или обновите план.',true);
      return result===true;
    }finally{if(button.isConnected)button.disabled=false;}
  }
  function syncPromiseViews(){window.WorkspaceV2MessageNavigation?.sync?.();window.WorkspaceV2PromiseSelection?.sync?.();}
  function renderDetail(){
    const root=$('assistantDetail'),item=rows.get(selected),focus=document.activeElement,hadFocus=!!focus&&root.contains(focus),previousKey=rendered.get(root)?.key;
    if(!item){const changed=renderMarkup(root,'<p class="assistantEmpty">Выберите клиента, чтобы увидеть основание рекомендации и задачи.</p>',0);if(changed&&hadFocus)$('assistantClose')?.focus({preventScroll:true});return}
    const r=fresh(item)?item.recommendation:null,id=Number(item.conversation_id),suspended=!!item.delivery_suspended,tasks=(item.tasks||[]).filter(t=>t.status==='open'),blocked=pending.has(id);
    const planAction='<button type="button" id="assistantPlan">'+(tasks.length?'Открыть задачи':'Запланировать')+'</button>';
    const changed=renderMarkup(root,`<button id="assistantBack" class="assistantBack" type="button">← К списку</button><div class="assistantClientHead"><div><h3>${esc(item.display_name||'Клиент')}</h3><span>${esc(item.channel||'')} · ${esc(time(item.last_message_at))}</span>${S.manager?.role==='admin'?`<p>${esc(item.project_name||item.project_key||'')} · ${esc(item.manager_name||'Без назначения')}</p>`:''}</div><button id="assistantConversation" type="button">Открыть диалог</button></div>${suspended?`<article class="assistantUnavailable"><strong>Клиент недоступен в MAX</strong><p>${esc(item.delivery_notice||'Ответ станет доступен после новой активности клиента.')}</p></article>`:''}${reviewNeeded(item)||arrived(item)?`<article class="assistantOperational"><h4>${reviewNeeded(item)?'Обращение без записанного ответа':'Наступил срок задачи'}</h4><p>${reviewNeeded(item)?'Откройте переписку и проверьте, нужен ли ответ.':'Проверьте существующую задачу и её срок ниже.'}</p></article>`:''}${r?`<article class="assistantRecommendation"><div class="assistantKicker">Рекомендация · ${esc(labels[r.next_action]||'Следующий шаг')}</div><h4>${r.decision==='done'?'Отмечено выполненным':r.decision==='irrelevant'?'Отмечено неактуальным':r.next_action==='no_contact'?'Сейчас не писать':esc(labels[r.next_action]||'Следующий шаг')}</h4><p>${esc(r.reason)}</p>${r.checks?.length?`<ul>${r.checks.map(text=>`<li>${esc(text)}</li>`).join('')}</ul>`:''}${r.history_incomplete?'<p class="assistantWarning">В доступной истории есть пропуски. Перед действием проверьте переписку.</p>':''}<details class="assistantSource"><summary>Основание и актуальность</summary><p>По переписке до сообщения ${Number(r.latest_message_id)||'—'}. Подготовлено ${esc(time(r.prepared_at))} МСК. Действительно до ${esc(time(r.expires_at))} МСК.</p><p>Сообщений в основании: ${(r.evidence_message_ids||[]).length}. Полную переписку можно открыть в диалоге.</p></details><div class="assistantActions">${r.next_action!=='no_contact'?'<button id="assistantReply" type="button">Совет и ответ в переписке</button>':''}${item.can_decide===false?'': ['done','irrelevant'].includes(r.decision)?'<button type="button" data-assistant-decision="open">Вернуть в работу</button>':r.next_action==='no_contact'?planAction:`<button type="button" data-assistant-decision="done">Выполнено</button>${planAction}<button type="button" data-assistant-decision="irrelevant">Неактуально</button>`}</div></article>`:suspended?'':'<div class="assistantEmpty"><strong>Нет свежего разбора</strong><p>Это не означает, что по клиенту всё сделано. Проверьте переписку и существующие задачи.</p></div>'}<section class="assistantTasks"><h4>Задачи и напоминания</h4>${tasks.length?tasks.map(task=>`<article class="assistantTask"><strong>${esc(task.title)}</strong><span>${esc({overdue:'Просрочено',today:'Сегодня',upcoming:'Запланировано',unscheduled:'Без срока'}[task.due_state]||'Без срока')}${task.due_at_utc?' · '+esc(time(task.due_at_utc))+' МСК':''}</span>${promiseMarkup(item,task)}<div><button type="button" data-assistant-task="${Number(task.id)}">Выполнено</button><button type="button" data-assistant-snooze="${Number(task.id)}">Перенести</button></div></article>`).join(''):'<p>Открытых задач нет.</p>'}${item.tasks_truncated?'<p>Показаны первые 50 задач. Все задачи можно открыть в карточке клиента.</p>':''}</section><div id="assistantTaskEditor"></div>`,id);
    $('assistantBack').onclick=()=>{const old=selected;selected=0;selectionVersion++;detailTicket++;render();dialog.querySelector(`[data-assistant-client="${old}"]`)?.focus()};
    $('assistantConversation').onclick=()=>openConversation(id);
    if($('assistantReply'))$('assistantReply').onclick=()=>openAdvice(id);
    root.querySelectorAll('[data-assistant-decision]').forEach(button=>{button.disabled=blocked;button.onclick=()=>decide(item,button.dataset.assistantDecision)});
    const plan=$('assistantPlan');if(plan){plan.disabled=blocked;plan.onclick=()=>planTask(item)}
    root.querySelectorAll('[data-assistant-task]').forEach(button=>{button.disabled=blocked;button.onclick=()=>taskMutation(item,{action:'set_task_completed',task_id:Number(button.dataset.assistantTask),completed:true})});
    root.querySelectorAll('[data-assistant-snooze]').forEach(button=>{button.disabled=blocked;button.onclick=()=>editTask(item,tasks.find(task=>Number(task.id)===Number(button.dataset.assistantSnooze)))});
    for(const kind of ['source','selection'])root.querySelectorAll('[data-assistant-promise-'+kind+']').forEach(button=>{button.disabled=blocked;button.onclick=()=>openPromiseFact(item,tasks.find(task=>Number(task.id)===Number(button.dataset[kind==='source'?'assistantPromiseSource':'assistantPromiseSelection'])),kind,button)});
    syncPromiseViews();
    // Bind even retained nodes to the freshly read item/revision. A removed
    // focused action moves to a stable control only within the same client.
    if(changed&&hadFocus&&previousKey===id&&!focus.isConnected)$('assistantConversation')?.focus({preventScroll:true});
  }
  async function decide(item,decision){
    const id=Number(item.conversation_id),r=item.recommendation,scope=epoch,opened=modal;if(pending.has(id)||!fresh(item)||item.can_decide===false)return false;
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
    if(!planMode)close({restoreFocus:false});return window.WorkspaceV2Conversation?.open(id,{preserveAttachment:true,preserveMessageScroll:true,preserveHistory:true,protectTaskDraft:true,mobileHistory});
  }
  async function openAdvice(id){
    const scope=epoch;
    if(!await openConversation(id)||!valid(scope)||Number(S.current)!==Number(id))return false;
    // Read the canonical saved proposal. Opening the plan never generates or inserts text.
    return window.WorkspaceV2AiDraft?.openPrepared?.()||false;
  }
  function protectTaskDraft(){
    if(!window.WorkspaceV2Tasks?.blockNavigationForDirtyDraft?.())return false;
    if(!planMode){close();window.WorkspaceV2Tasks.blockNavigationForDirtyDraft();}return true;
  }
  async function planTask(item){
    const id=Number(item.conversation_id),scope=epoch,revision=item.recommendation?.revision;
    if(pending.has(id)||!fresh(item)||item.can_decide===false||['done','irrelevant'].includes(item.recommendation?.decision))return false;
    pending.add(id);renderDetail();
    try{
      // Planning opens the existing editor. These reads never create a task or call a model.
      const opening=openConversation(id),opened=modal,selection=selectionVersion;
      if(!await opening||!valid(scope)||modal!==opened||selectionVersion!==selection||selected!==id||Number(S.current)!==id)return false;
      const C=window.WorkspaceV2Conversation,captured=C?.captureReplyContext?.(),ticket=detailTicket,detail=S.detail;
      if(!captured||captured.conversationId!==id)return false;
      const current=()=>{const now=C.captureReplyContext();return valid(scope)&&modal===opened&&selectionVersion===selection&&detailTicket===ticket&&selected===id&&S.detail===detail&&Number(S.detail?.conversation?.id)===id&&['conversationId','managerId','authGeneration','openGeneration'].every(key=>now[key]===captured[key])};
      if(!current()||protectTaskDraft())return false;
      const refreshed=await C.refreshLeadData({conversationId:id,protectTaskDraft:true});
      if(!current()||protectTaskDraft())return false;
      const lead=S.detail?.lead,root=$('leadTasksBody');
      if(refreshed!==true||!lead?.ok||!Array.isArray(lead.tasks)||(lead.degraded_components||[]).some(part=>['tasks','pipeline'].includes(part))){C.setLoadStatus?.('Не удалось получить текущие задачи. Обновите карточку перед планированием.','error');return false}
      if(!root)return false;
      if(lead.tasks.some(task=>task.status==='open')){window.Manager2Presentation?.openClientCard($('conversationTitle'));root.scrollIntoView({block:'nearest'});return true}
      if(lead.can_edit_pipeline!==true||lead.pipeline?.outcome?.outcome!=='open'){C.setLoadStatus?.('Создание задачи сейчас недоступно. Проверьте состояние карточки и права.','error');return false}
      const review=await request('detail',{conversation_id:id},scope);
      if(!current()||S.detail.lead!==lead||protectTaskDraft())return false;
      if(!review.ok||Number(review.item?.conversation_id)!==id||review.item.can_decide===false){C.setLoadStatus?.('Не удалось проверить актуальный доступ к планированию. Обновите диалог.','error');return false}
      const form=root.querySelector('.taskCreate'),title=root.querySelector('#leadTaskTitle');
      if(!form||!title)return false;
      const recommendation=review.item.recommendation,same=fresh(review.item)&&recommendation.revision===revision&&!['done','irrelevant'].includes(recommendation.decision);
      if(!title.value&&!root.querySelector('#leadTaskDue')?.value&&same){
        const firstCheck=typeof recommendation.checks?.[0]==='string'?recommendation.checks[0].trim():'';
        const proposed=firstCheck||(recommendation.next_action==='no_contact'?'':labels[recommendation.next_action]||'Проверить запрос клиента');
        if(proposed){title.value=proposed.slice(0,255);title.dispatchEvent(new Event('input',{bubbles:true}))}
      }
      window.Manager2Presentation?.revealTaskDraft(form);title.focus();
      if(!same)C.setLoadStatus?.('Рекомендация изменилась. Старый текст не перенесён в задачу.');
      return true;
    }finally{if(valid(scope)){pending.delete(id);if(dialog?.open&&selected===id&&!$('assistantTaskEditor')?.querySelector('form'))renderDetail()}}
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
  window.WorkspaceV2Assistant={bind,activate,suspend,sync,canLeavePlan,open,openPlan:id=>planMode&&dialog?.open&&!id?Promise.resolve(true):open(id,{plan:true}),isPlanOpen:()=>planMode&&!!dialog?.open,close,loadList,loadDetail};
})();
