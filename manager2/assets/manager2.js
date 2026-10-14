(function(){
  'use strict';
  const W=window.WorkspaceV2;
  if(!W||!document.body.classList.contains('manager2'))return;
  const {$,S,esc}=W;
  const disclosures=new Map();
  let section='dialogues',navigationGeneration=0,notificationOpener=null;
  let conversationMenu=null,conversationMenuActions=null,conversationMenuHistory=null;
  const conversationToolButtons=[];
  function disclosure(key,label,conversationId=Number(S.current||0)){
    const el=document.createElement('details');
    el.dataset.m2Disclosure=key;el.dataset.conversationId=String(conversationId);
    const summary=document.createElement('summary');summary.textContent=label;el.appendChild(summary);
    el.open=disclosures.get(conversationId+':'+key)===true;
    return el;
  }
  document.addEventListener('toggle',e=>{
    const el=e.target;
    if(!(el instanceof HTMLDetailsElement)||!el.dataset.m2Disclosure)return;
    const key=el.dataset.conversationId+':'+el.dataset.m2Disclosure;
    disclosures.delete(key);disclosures.set(key,el.open);
    while(disclosures.size>100)disclosures.delete(disclosures.keys().next().value);
  },true);
  function budgetFact(trip){
    const b=trip?.budget,amount=Number(b?.max);
    if(!b||!Number.isFinite(amount)||amount<=0)return {label:'Бюджет',value:'Пока не указан'};
    const currency={RUB:'₽',EUR:'€',USD:'$'}[String(b.currency||'RUB').toUpperCase()]||String(b.currency||'');
    return {label:b.basis==='per_person'?'Бюджет на человека':b.basis==='total'?'Бюджет за всех':'Бюджет',value:'до '+new Intl.NumberFormat('ru-RU',{maximumFractionDigits:2}).format(amount)+' '+currency};
  }
  function enhanceLeadCard(p,c){
    const root=$('leadCard'),hero=root?.querySelector('.leadHero'),sales=root?.querySelector('.leadSalesGrid')?.closest('.leadPanel'),tasks=$('leadTasksSection');
    if(!root||!hero||!sales||!tasks)return;
    const contact=hero.querySelector('.leadContactActions');
    if(contact)hero.appendChild(contact);
    const phone=String(p.contact?.phone||'').trim();
    if(phone&&contact){
      const copy=document.createElement('button');copy.type='button';copy.className='m2CopyPhone';copy.textContent='Скопировать';copy.setAttribute('aria-label','Скопировать телефон клиента');
      copy.onclick=async()=>{try{await navigator.clipboard.writeText(phone);copy.textContent='Скопировано';copy.setAttribute('aria-label','Телефон скопирован')}catch{copy.textContent='Номер выделен';const range=document.createRange();range.selectNodeContents(contact.querySelector('.leadContactLink')||contact);const selection=window.getSelection();selection.removeAllRanges();selection.addRange(range)}};
      contact.appendChild(copy);
    }
    // Move existing, bound controls. All mutations still belong to canonical modules.
    const grid=sales.querySelector('.leadSalesGrid'),tags=$('leadTags')?.parentElement,outcome=sales.querySelector('.leadOutcomeCompactForm'),owner=hero.querySelector('.leadOwnerEditor'),handoff=hero.querySelector('.leadHandoffStrip');
    const extra=disclosure('sale','Теги и результат · '+W.outcomeText(p.pipeline?.outcome?.outcome));extra.className='m2SaleDetails';
    if(tags)extra.appendChild(tags);if(outcome)extra.appendChild(outcome);
    sales.classList.add('m2SalesStage');
    const service=root.querySelector('.leadDetails:last-child .leadDetailsBody');
    if(handoff&&!handoff.classList.contains('isError')&&service)service.appendChild(handoff);
    if(owner){const d=disclosure('owner','Передать другому менеджеру');d.className='m2OwnerDetails';d.appendChild(owner);(service||grid).appendChild(d)}
    const route=hero.querySelector('.leadRoute');
    const tripPanel=document.createElement('section');tripPanel.className='leadPanel m2TripPanel';
    const trip=p.trip||{},budget=budgetFact(trip),adults=String(trip.adults??'').trim(),children=String(trip.children??'').trim();
    const pax=[adults?adults+' взр.':'',children&&children!=='0'?children+' дет.':''].filter(Boolean).join(' · ')||'Уточняются';
    const routeMain=route?.querySelector('.leadRouteMain');if(routeMain)tripPanel.appendChild(routeMain);
    const facts=document.createElement('div');facts.className='m2TripFacts';
    facts.innerHTML=`<div class="m2TripFact"><span>Вылет</span><strong>${esc(trip.date||'Дата уточняется')}</strong></div><div class="m2TripFact"><span>Туристы</span><strong>${esc(pax)}</strong></div><div class="m2TripFact m2Budget"><span>${esc(budget.label)}</span><strong>${esc(budget.value)}</strong></div>`;
    tripPanel.appendChild(facts);route?.remove();
    const tripDetails=root.querySelector('.leadDetails');
    const readonly=sales.querySelector('.leadReadonly');if(readonly)extra.appendChild(readonly);
    root.insertBefore(tasks,hero.nextSibling);root.insertBefore(sales,tasks.nextSibling);root.insertBefore(tripPanel,sales.nextSibling);
    if(tripDetails)root.insertBefore(extra,tripDetails.nextSibling);else root.appendChild(extra);
  }
  function enhanceTasks(root,{tasks=[],conversationId=0}){
    const list=root.querySelector('.taskList'),rows=list?[...list.querySelectorAll(':scope > .taskRow')]:[],done=list?.querySelector('.doneTasks');
    if(!list)return;
    if(rows.length>1||done){
      const all=disclosure('tasks','Все задачи · '+tasks.length,conversationId);all.className='m2AllTasks';
      rows.slice(1).forEach(row=>all.appendChild(row));if(done)all.appendChild(done);
      list.appendChild(all);
      const remaining=tasks.filter(t=>t.status==='open').slice(1),overdue=remaining.filter(t=>t.due_state==='overdue').length,today=remaining.filter(t=>t.due_state==='today').length;
      if(overdue||today){const notice=document.createElement('span');notice.className='m2ExtraUrgency';notice.textContent=overdue?'Ещё просроченных задач: '+overdue:'Ещё задач на сегодня: '+today;all.before(notice)}
    }
    root.querySelectorAll('.taskQuickActions').forEach(actions=>{
      const menu=document.createElement('details');menu.className='m2TaskSnooze';const summary=document.createElement('summary');summary.textContent='Перенести';menu.appendChild(summary);actions.before(menu);menu.appendChild(actions);
      actions.querySelector('[data-task-snooze=quarter]')?.replaceChildren(document.createTextNode('+15 мин'));
      const hour=actions.querySelector('[data-task-snooze=hour]'),due=window.WorkspaceV2TaskPresets?.dateForPreset?.('hour',new Date());
      if(hour&&due)hour.textContent='К '+new Intl.DateTimeFormat('ru-RU',{timeZone:'Europe/Moscow',hour:'2-digit',minute:'2-digit',hourCycle:'h23'}).format(due);
    });
    const create=root.querySelector('.taskCreate');
    if(create){
      const details=disclosure('create','＋ Задача',conversationId);details.className='m2TaskCreate';create.before(details);details.appendChild(create);
      const title=create.querySelector('#leadTaskTitle'),due=create.querySelector('#leadTaskDue');
      title?.setAttribute('aria-label','Название новой задачи');due?.setAttribute('aria-label','Срок новой задачи, МСК');
      const cancel=document.createElement('button');cancel.type='button';cancel.className='m2TaskCancel';cancel.textContent='Отмена';
      cancel.onclick=()=>{title.value='';due.value='';title.dispatchEvent(new Event('input',{bubbles:true}));due.dispatchEvent(new Event('change',{bubbles:true}));details.open=false};create.appendChild(cancel);
      details.addEventListener('toggle',()=>{if(!details.open&&(String(title?.value||'').trim()||due?.value)){details.open=true;const status=create.querySelector('#leadTaskCreateStatus');if(status){status.textContent='Добавьте задачу или нажмите «Отмена»';status.className='taskCreateStatus dirty'}}});
      // Canonical TaskDraft restores values immediately after Tasks.render returns.
      queueMicrotask(()=>{if(create.isConnected&&(String(title?.value||'').trim()||due?.value))details.open=true});
    }
  }
  function revealTaskDraft(form){
    for(let el=form.parentElement;el;el=el.parentElement)if(el instanceof HTMLDetailsElement)el.open=true;
    const mobile=window.WorkspaceV2Mobile;
    if(mobile?.isMobile()&&mobile.getScreen()!=='lead'){if(mobile.getScreen()==='inbox')mobile.conversationOpened(S.current);mobile.showLead()}
  }
  function syncConversationMenu(){
    if(!conversationMenu)return;
    conversationMenu.hidden=!S.manager||S.authExpired||!S.current;
    if(conversationMenu.hidden){conversationMenu.open=false;conversationMenuActions?.replaceChildren()}
  }
  function focusConversationTool(id){
    const button=conversationToolButtons.find(el=>el.id===id)||(id==='conversationLoadOlder'&&conversationMenuHistory?.contains($(id))?$(id):null);
    if(!button||!conversationMenu||conversationMenu.hidden)return false;
    // Canonical feature owners return to their original bound button. Reveal
    // that button before focusing it instead of leaving focus inside hidden UI.
    conversationMenu.open=true;button.focus({preventScroll:true});return true;
  }
  function initConversationMenu(){
    const root=$('conversationActions');if(!root)return;
    conversationMenu=document.createElement('details');conversationMenu.className='m2ConversationMenu';conversationMenu.hidden=true;
    const summary=document.createElement('summary');summary.id='m2ConversationMenuToggle';summary.textContent='⋯';summary.setAttribute('aria-label','Действия с диалогом');conversationMenu.appendChild(summary);
    const items=document.createElement('div');
    ['conversationSearchOpen','nextWaitingConversation'].forEach(id=>{
      const button=$(id);if(!button)return;
      conversationToolButtons.push(button);items.appendChild(button);
      button.addEventListener('click',()=>conversationMenu.open=false);
    });
    conversationMenuHistory=document.createElement('div');conversationMenuHistory.className='m2ConversationHistoryAction';items.appendChild(conversationMenuHistory);
    conversationMenuActions=document.createElement('div');conversationMenuActions.className='m2ConversationMenuActions';items.appendChild(conversationMenuActions);conversationMenu.appendChild(items);
    // renderActions clears only the canonical root. This stable sibling retains
    // the real Search/Next nodes and their once-bound listeners across redraws.
    root.after(conversationMenu);
    conversationMenu.addEventListener('keydown',e=>{if(e.key==='Escape'){e.preventDefault();conversationMenu.open=false;summary.focus()}});
    new MutationObserver(syncConversationMenu).observe(document.body,{attributes:true,attributeFilter:['class']});
    syncConversationMenu();
  }
  function conversationHistoryTarget(){if(!conversationMenu)initConversationMenu();return conversationMenuHistory}
  function enhanceConversationActions(root){
    void window.WorkspaceV2PostContext?.sync(S.detail?.conversation);
    const secondary=[...root.children].filter(el=>el.tagName==='BUTTON'&&!el.classList.contains('primary'));
    if(!conversationMenu)initConversationMenu();
    conversationMenuActions?.replaceChildren(...secondary);
    secondary.forEach(el=>el.addEventListener('click',()=>conversationMenu.open=false));
    syncConversationMenu();
  }
  function compactBoard(){
    $('kanbanBoard')?.querySelectorAll('.kanbanCard').forEach(card=>{
      if(card.querySelector('.m2BoardActions'))return;
      const controls=[...card.children].filter(n=>n.matches('.kanbanTaskComplete,.kanbanQuickTask,.kanbanStageBlock'));
      if(!controls.length)return;
      const d=document.createElement('details');d.className='m2BoardActions';d.innerHTML='<summary>Действия с клиентом</summary>';controls.forEach(n=>d.appendChild(n));card.appendChild(d);
    });
  }
  function syncNavigation(){
    if(S.viewMode==='kanban')section='sales';else if(S.queue==='closed')section='archive';else if(section==='sales'||section==='archive')section='dialogues';
    document.querySelectorAll('[data-m2-nav]').forEach(b=>{const active=b.dataset.m2Nav===section;b.classList.toggle('active',active);b.disabled=!S.manager||S.authExpired;if(active)b.setAttribute('aria-current','page');else b.removeAttribute('aria-current')});
    const title=document.querySelector('.inboxHead .brand');if(title)title.textContent={dialogues:'Диалоги',tasks:'Мой план работы',sales:'Продажи',archive:'Архив'}[section];
    const hint=$('m2SectionHint');if(hint){hint.textContent={tasks:'Мои диалоги с задачами и без них. Выберите срок или приоритет.',archive:'Закрытые диалоги. Результат продажи указан в карточке.'}[section]||'';hint.hidden=!hint.textContent}
    $('workspaceRoot').dataset.m2Section=section;
  }
  async function navigate(next){
    if(!S.manager||S.authExpired||!['dialogues','tasks','sales','archive'].includes(next))return false;
    const generation=++navigationGeneration,authGeneration=S.authGeneration,managerId=Number(S.manager.id);
    section=next;
    window.WorkspaceV2Kanban.setMode(next==='sales'?'kanban':'list');
    if(next!=='sales'){
      // Filters own persistence and task-queue semantics; navigation does not click legacy controls.
      window.WorkspaceV2Filters.setTaskFilter('',{reload:false});
      window.WorkspaceV2Inbox.setQueue(next==='archive'?'closed':'mine',{reload:false});
    }
    window.WorkspaceV2Mobile?.showInbox({historyMode:'replace'});
    window.WorkspaceV2Filters.setExpanded(next==='tasks');
    syncNavigation();
    const loaded=await window.WorkspaceV2Inbox.load({preserveScroll:false});
    if(generation===navigationGeneration&&!S.authExpired&&authGeneration===S.authGeneration&&managerId===Number(S.manager?.id||0))syncNavigation();
    return loaded;
  }
  function updateClock(){const clock=$('m2Clock');if(clock){const now=new Date();clock.dateTime=now.toISOString();clock.textContent=new Intl.DateTimeFormat('ru-RU',{timeZone:'Europe/Moscow',hour:'2-digit',minute:'2-digit',hourCycle:'h23'}).format(now)+' МСК'}}
  function init(){
    initConversationMenu();
    window.WorkspaceV2PostContext?.init({endpoint:'/manager/post-context-api.php'});
    const manager=$('managerName'),shift=$('managerShiftBtn'),admin=$('adminLink'),health=$('notificationStatus');
    if(manager){$('m2Identity').appendChild(manager);manager.classList.remove('muted')}
    if(shift)$('m2ShiftSlot').appendChild(shift);
    if(admin){admin.title='Настройки и менеджеры';admin.setAttribute('aria-label','Настройки и менеджеры')}
    const publications=document.createElement('a');publications.id='m2PublicationsLink';publications.href='/manager/publications.php';publications.textContent='Публикации';publications.style.textDecoration='none';publications.hidden=true;
    const syncPublications=()=>{publications.hidden=!!S.authExpired||S.manager?.role!=='admin'||!!admin?.classList.contains('hidden');};
    const adminQuery=window.matchMedia('(max-width:740px)');const placeAdmin=()=>{const slot=adminQuery.matches?$('m2NotificationSlot'):$('m2AdminSlot');if(admin)slot.appendChild(admin);publications.className=adminQuery.matches?'actionBtn':'m2Legacy';slot.appendChild(publications);syncPublications()};adminQuery.addEventListener('change',placeAdmin);
    if(admin)new MutationObserver(syncPublications).observe(admin,{attributes:true,attributeFilter:['class']});
    if(manager)new MutationObserver(syncPublications).observe(manager,{childList:true,subtree:true});
    new MutationObserver(syncPublications).observe(document.body,{attributes:true,attributeFilter:['class']});
    if(health)$('m2NotificationSlot').appendChild(health);placeAdmin();
    const hint=document.createElement('p');hint.id='m2SectionHint';hint.className='m2SectionHint';hint.hidden=true;document.querySelector('.inboxHead .brandRow').after(hint);
    const attach=document.querySelector('.attachBtn');
    if(attach){attach.tabIndex=0;attach.setAttribute('role','button');attach.setAttribute('aria-label','Прикрепить файл');attach.addEventListener('keydown',e=>{if(e.key==='Enter'||e.key===' '){e.preventDefault();const input=$('replyFile');if(input&&!input.disabled)input.click()}})}
    $('mobileLeadBtn').textContent='Клиент';$('mobileLeadBtn').setAttribute('aria-label','Карточка клиента');$('mobileLeadClose').textContent='К диалогу';
    const header=document.querySelector('.leadZone .brand');if(header)header.textContent='Карточка клиента';
    const clientName=document.createElement('span');clientName.id='m2ClientName';clientName.className='m2ClientName';document.querySelector('.leadHeaderText').appendChild(clientName);
    const syncClientName=()=>{clientName.textContent=S.current?$('conversationTitle').textContent:'';syncConversationMenu()};new MutationObserver(syncClientName).observe($('conversationTitle'),{childList:true,subtree:true});syncClientName();
    document.querySelectorAll('[data-m2-nav]').forEach(b=>b.addEventListener('click',()=>navigate(b.dataset.m2Nav)));
    const dialog=$('m2Notifications');
    $('m2NotificationButton').onclick=()=>{notificationOpener=document.activeElement;dialog.showModal()};
    $('m2NotificationsClose').onclick=()=>dialog.close();dialog.addEventListener('close',()=>notificationOpener?.focus({preventScroll:true}));
    dialog.addEventListener('click',e=>{if(e.target!==dialog)return;const r=dialog.getBoundingClientRect();if(e.clientX<r.left||e.clientX>r.right||e.clientY<r.top||e.clientY>r.bottom)dialog.close()});
    if(health){const update=()=>{const kind=health.classList.contains('ok')?'healthy':health.classList.contains('critical')?'error':health.classList.contains('warn')?'warn':'';const dot=document.querySelector('.m2NotificationDot');dot.className='m2NotificationDot'+(kind?' '+kind:'');$('m2NotificationButton').title=health.textContent.trim();syncNavigation()};new MutationObserver(update).observe(health,{attributes:true,childList:true,subtree:true});update()}
    if(manager)new MutationObserver(syncNavigation).observe(manager,{childList:true,subtree:true});
    new MutationObserver(()=>{compactBoard();syncNavigation()}).observe($('workspaceRoot'),{attributes:true,attributeFilter:['class']});
    new MutationObserver(compactBoard).observe($('kanbanBoard'),{childList:true});
    document.querySelector('.queueTabs').addEventListener('click',()=>{if(section==='tasks')section='dialogues';setTimeout(syncNavigation,0)});
    $('mobileLeadBtn').addEventListener('click',()=>{if(window.WorkspaceV2Mobile.isMobile())setTimeout(()=>$('mobileLeadClose').focus({preventScroll:true}),0)});
    $('mobileLeadClose').addEventListener('click',()=>setTimeout(()=>$('mobileLeadBtn').focus({preventScroll:true}),0));
    document.addEventListener('keydown',e=>{if(e.key==='Escape')document.querySelectorAll('.m2ConversationMenu[open]').forEach(d=>{d.open=false;d.querySelector('summary').focus()})});
    updateClock();setInterval(updateClock,60000);syncNavigation();
  }
  window.Manager2Presentation={enhanceLeadCard,enhanceTasks,enhanceConversationActions,focusConversationTool,conversationHistoryTarget,revealTaskDraft,budgetFact,navigate,syncNavigation};
  init();
})();
