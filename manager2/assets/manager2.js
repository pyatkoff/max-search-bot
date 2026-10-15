(function(){
  'use strict';
  const W=window.WorkspaceV2;
  if(!W||!document.body.classList.contains('manager2'))return;
  const {$,S,esc}=W;
  const disclosures=new Map();
  let section='dialogues',navigationGeneration=0,notificationOpener=null,clientCardOpener=null,clientCardConversation=0;
  let conversationMenu=null,conversationMenuActions=null,conversationMenuHistory=null;
  const conversationToolButtons=[];
  let initialInboxSearchSynced=false;
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
  function ownershipSignal(c){
    const assigned=Number(c?.manager_id||0),own=assigned>0&&assigned===Number(S.manager?.id||0);
    if(!assigned)return {kind:'free',label:'Свободный диалог'};
    if(own)return {kind:'own',label:'Мой диалог'};
    return {kind:'assigned',label:'Диалог назначен другому менеджеру'};
  }
  function formatInboxActivity(value,now=new Date()){
    const time=window.ManagerTime,date=time?.parse(value),wall=date&&time.wallDate(date),today=time?.wallDate(now);
    if(!wall||!today)return null;
    const pad=n=>String(n).padStart(2,'0'),day=Date.UTC(wall.getUTCFullYear(),wall.getUTCMonth(),wall.getUTCDate()),currentDay=Date.UTC(today.getUTCFullYear(),today.getUTCMonth(),today.getUTCDate());
    const sameYear=wall.getUTCFullYear()===today.getUTCFullYear();
    const text=day===currentDay?pad(wall.getUTCHours())+':'+pad(wall.getUTCMinutes()):currentDay-day===86400000?'Вчера':pad(wall.getUTCDate())+'.'+pad(wall.getUTCMonth()+1)+(sameYear?'':'.'+String(wall.getUTCFullYear()).slice(-2));
    return {text,exact:time.format(date,{year:true})+':'+pad(wall.getUTCSeconds())+' '+time.label};
  }
  function enhanceInboxMarkers(node){
    const markers=node?.querySelector('.pwRowMarkers'),meta=node?.querySelector('.m2ChatMeta');
    if(!markers||!meta||markers.children.length!==1||!markers.firstElementChild.classList.contains('pwMarkerPin'))return;
    meta.insertBefore(markers,meta.querySelector('.unreadBadge'));
  }
  function enhanceInbox(rows){
    const searchToggle=$('m2InboxSearchToggle'),search=$('leadSearch'),searchRow=document.querySelector('.inboxSearchRow');
    if(searchToggle&&search&&searchRow){
      if(!initialInboxSearchSynced){initialInboxSearchSynced=true;searchRow.hidden=!String(search.value||'').trim();searchToggle.setAttribute('aria-expanded',searchRow.hidden?'false':'true')}
      searchToggle.classList.toggle('isActive',!!String(search.value||'').trim());
    }
    const byId=new Map(rows.map(row=>[Number(row.id),row])),now=new Date();
    // Render from the authorized canonical list; this hook only compacts its nodes.
    document.querySelectorAll('#inboxList .taskQueueSection').forEach(el=>{
      if(section!=='tasks')el.remove();
    });
    document.querySelectorAll('#inboxList .leadItem').forEach(el=>{
      const c=byId.get(Number(el.dataset.conversationId));if(!c)return;
      const signal=ownershipSignal(c),line=el.querySelector('.leadTitleLine'),identity=el.querySelector('.leadIdentity');
      const name=el.querySelector('.leadTitle');if(name)name.textContent=c.display_name||'Турист';
      const mark=document.createElement('span');mark.className='m2OwnershipMark '+signal.kind;mark.title=signal.label;mark.setAttribute('role','img');mark.setAttribute('aria-label',signal.label);line?.appendChild(mark);
      const activity=el.querySelector('.leadActivityTime'),formatted=formatInboxActivity(c.last_message_at||c.started_at||'',now);
      if(activity){if(formatted){activity.textContent=formatted.text;activity.title='Последняя активность: '+formatted.exact;activity.setAttribute('aria-label',activity.title);line?.appendChild(activity)}else activity.remove()}
      const preview=el.querySelector('.leadPreview');if(preview&&identity)identity.appendChild(preview);
      const secondary=document.createElement('div');secondary.className='m2ChatMeta';
      const channel={max:'MAX',telegram:'TG',website:'Сайт'}[String(c.channel||'')];
      if(channel){const badge=document.createElement('span');badge.className='m2ChannelBadge '+String(c.channel);badge.textContent=channel;badge.title='Канал клиента: '+(c.channel==='telegram'?'Telegram':channel);secondary.appendChild(badge)}
      if(S.manager?.role==='admin'&&String(c.manager_name||'').trim()){
        const manager=document.createElement('span');manager.className='m2ChatManager';manager.textContent=c.manager_name;manager.title='Ответственный менеджер';secondary.appendChild(manager);
      }
      const wait=el.querySelector('.leadWaitCompact');let reply=el.querySelector('.leadReplyStatus');
      if(!reply&&Number(c.awaiting_manager_reply||0)===1){reply=document.createElement('span');reply.className='leadReplyStatus';reply.textContent='Ждёт ответа'}
      if(reply)secondary.appendChild(reply);if(wait)secondary.appendChild(wait);
      const unread=el.querySelector('.unreadBadge');if(unread)secondary.appendChild(unread);
      if(secondary.childNodes.length)identity?.appendChild(secondary);
      if(!el.querySelector('.leadSignals')?.childNodes.length)el.querySelector('.leadSignals')?.remove();
      el.querySelectorAll('.leadSource:not(.leadActivityTime),.leadTrip,.leadContextRow,.leadActionRow').forEach(node=>node.remove());
      el.setAttribute('aria-label','Открыть диалог '+(c.display_name||'Турист')+' · '+signal.label);
    });
  }
  function clientCardIsOpen(){return window.WorkspaceV2Mobile?.isMobile()?window.WorkspaceV2Mobile.getScreen()==='lead':$('workspaceRoot')?.classList.contains('m2ClientCardOpen')}
  function syncClientCard(){
    const valid=!!S.manager&&!S.authExpired&&Number(S.current)>0;
    if((!valid||clientCardConversation&&clientCardConversation!==Number(S.current))&&$('workspaceRoot')?.classList.contains('m2ClientCardOpen'))$('workspaceRoot').classList.remove('m2ClientCardOpen');
    const expanded=valid&&!!clientCardIsOpen();
    ['conversationTitle','mobileLeadBtn'].forEach(id=>{const b=$(id);if(!b)return;b.disabled=!valid;b.setAttribute('aria-expanded',expanded?'true':'false');b.setAttribute('aria-controls','leadZone')});
    if(!valid)clientCardConversation=0;
  }
  function openClientCard(opener=document.activeElement,{focus=true}={}){
    if(!S.manager||S.authExpired||!S.current)return false;
    clientCardOpener=opener;clientCardConversation=Number(S.current);
    const mobile=window.WorkspaceV2Mobile;
    if(mobile?.isMobile())mobile.showLead();else $('workspaceRoot').classList.add('m2ClientCardOpen');
    syncClientCard();
    if(focus)setTimeout(()=>{if(clientCardIsOpen())$('mobileLeadClose')?.focus({preventScroll:true})},0);
    return true;
  }
  function closeClientCard(){
    if(window.WorkspaceV2Mobile?.isMobile())return; // Mobile owns its existing Back/history operation.
    $('workspaceRoot')?.classList.remove('m2ClientCardOpen');syncClientCard();
    const target=clientCardOpener?.isConnected?clientCardOpener:$('mobileLeadBtn');target?.focus({preventScroll:true});
  }
  function revealInboxSearch({focus=true}={}){
    if(!S.manager||S.authExpired)return false;
    const input=$('leadSearch'),row=document.querySelector('.inboxSearchRow'),toggle=$('m2InboxSearchToggle');
    if(!input||!row||!toggle)return false;
    const mobile=window.WorkspaceV2Mobile;if(mobile?.isMobile()&&mobile.getScreen()!=='inbox')mobile.showInbox();
    row.hidden=false;toggle.setAttribute('aria-expanded','true');if(focus)input.focus();return true;
  }
  function initInboxTools(){
    const heading=document.querySelector('.inboxHead .brandRow'),search=$('leadSearch'),searchRow=document.querySelector('.inboxSearchRow'),filters=$('filtersToggle');
    const tools=document.createElement('div');tools.id='m2InboxTools';tools.className='m2InboxTools';heading.appendChild(tools);
    function tool(id,label,glyph){const b=document.createElement('button');b.id=id;b.type='button';b.className='m2InboxTool';b.title=label;b.setAttribute('aria-label',label);b.textContent=glyph;tools.appendChild(b);return b}
    const toggle=tool('m2InboxSearchToggle','Поиск диалогов','⌕');toggle.setAttribute('aria-controls','leadSearch');toggle.setAttribute('aria-expanded','false');
    function showSearch(show){searchRow.hidden=!show;toggle.setAttribute('aria-expanded',show?'true':'false');if(show)search.focus();else toggle.focus({preventScroll:true})}
    searchRow.hidden=!String(search.value||S.leadSearch||'').trim();toggle.setAttribute('aria-expanded',searchRow.hidden?'false':'true');
    toggle.onclick=()=>showSearch(searchRow.hidden);
    search.addEventListener('keydown',e=>{if(e.key==='Escape'){e.preventDefault();e.stopPropagation();showSearch(false)}});
    // A nonempty hidden search keeps an explicit indicator; collapsing never clears a filter.
    const searchIndicator=()=>toggle.classList.toggle('isActive',!!String(search.value||'').trim());search.addEventListener('input',searchIndicator);searchIndicator();
    if(filters){filters.title='Фильтры диалогов';filters.setAttribute('aria-label','Фильтры диалогов');tools.appendChild(filters)}
    const pinned=tool('m2PinnedToggle','Мои закреплённые диалоги','☆');pinned.setAttribute('aria-pressed','false');pinned.onclick=()=>{if(pinned.getAttribute('aria-pressed')==='true')void window.WorkspaceV2Inbox?.setQueue('all',{reload:true});else void window.WorkspaceV2PersonalWorkspace?.selectSelection('pinned')};
    const settings=tool('m2WorkspaceSettings','Мои вкладки: условия и настройки','⚙');settings.setAttribute('aria-haspopup','dialog');settings.setAttribute('aria-controls','pwDialog');settings.onclick=()=>void window.WorkspaceV2PersonalWorkspace?.open();
    const sync=()=>{
      const canonical=$('pwOpen'),selection=document.querySelector('#pwLane [data-pw-selection="pinned"]');
      const settingsDisabled=!canonical||canonical.disabled,pinnedDisabled=!selection||$('pwLane')?.getAttribute('aria-busy')==='true';
      if(settings.disabled!==settingsDisabled)settings.disabled=settingsDisabled;if(pinned.disabled!==pinnedDisabled)pinned.disabled=pinnedDisabled;
      const active=selection?.getAttribute('aria-pressed')==='true',pressed=active?'true':'false',glyph=active?'★':'☆';
      if(pinned.getAttribute('aria-pressed')!==pressed)pinned.setAttribute('aria-pressed',pressed);if(pinned.textContent!==glyph)pinned.textContent=glyph;
    };
    new MutationObserver(sync).observe(document.querySelector('.inboxHead'),{childList:true,subtree:true,attributes:true,attributeFilter:['disabled','aria-busy','aria-pressed']});sync();
  }
  function revealTaskDraft(form){
    for(let el=form.parentElement;el;el=el.parentElement)if(el instanceof HTMLDetailsElement)el.open=true;
    const mobile=window.WorkspaceV2Mobile;
    if(mobile?.isMobile()&&mobile.getScreen()==='inbox')mobile.conversationOpened(S.current);
    openClientCard($('mobileLeadBtn'),{focus:false});
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
    const taskFilters=document.querySelector('.m2TaskFilters');if(taskFilters)taskFilters.open=next==='tasks';
    if(next==='tasks'&&$('filtersPanel')?.classList.contains('hidden'))$('filtersToggle')?.click();
    syncNavigation();
    const loaded=await window.WorkspaceV2Inbox.load({preserveScroll:false});
    if(generation===navigationGeneration&&!S.authExpired&&authGeneration===S.authGeneration&&managerId===Number(S.manager?.id||0))syncNavigation();
    return loaded;
  }
  function updateClock(){const clock=$('m2Clock');if(clock){const now=new Date();clock.dateTime=now.toISOString();clock.textContent=new Intl.DateTimeFormat('ru-RU',{timeZone:'Europe/Moscow',hour:'2-digit',minute:'2-digit',hourCycle:'h23'}).format(now)+' МСК'}}
  function init(){
    initConversationMenu();initInboxTools();
    window.WorkspaceV2PostContext?.init({endpoint:'/manager/post-context-api.php'});
    const manager=$('managerName'),shift=$('managerShiftBtn'),admin=$('adminLink'),health=$('notificationStatus');
    if(manager){$('m2Identity').appendChild(manager);manager.classList.remove('muted')}
    if(shift)$('m2ShiftSlot').appendChild(shift);
    if(admin){admin.title='Настройки и менеджеры';admin.setAttribute('aria-label','Настройки и менеджеры')}
    const publications=document.createElement('a');publications.id='m2PublicationsLink';publications.href='/manager/publications.php';publications.textContent='Публикации';publications.style.textDecoration='none';publications.hidden=true;
    const syncPublications=()=>{publications.hidden=!!S.authExpired||S.manager?.role!=='admin'||!!admin?.classList.contains('hidden');};
    const placeAdmin=()=>{const slot=$('m2AdminSlot');if(admin)slot.appendChild(admin);publications.className='actionBtn';slot.appendChild(publications);syncPublications()};
    if(admin)new MutationObserver(syncPublications).observe(admin,{attributes:true,attributeFilter:['class']});
    if(manager)new MutationObserver(syncPublications).observe(manager,{childList:true,subtree:true});
    new MutationObserver(syncPublications).observe(document.body,{attributes:true,attributeFilter:['class']});
    if(health)$('m2NotificationSlot').appendChild(health);placeAdmin();
    const shortcuts=document.querySelector('.taskShortcuts');if(shortcuts){const details=document.createElement('details');details.className='m2TaskFilters';details.innerHTML='<summary>Быстрые фильтры</summary>';$('filtersPanel').prepend(details);details.appendChild(shortcuts)}
    const hint=document.createElement('p');hint.id='m2SectionHint';hint.className='m2SectionHint';hint.hidden=true;document.querySelector('.inboxHead .brandRow').after(hint);
    const attach=document.querySelector('.attachBtn');
    if(attach){attach.tabIndex=0;attach.setAttribute('role','button');attach.setAttribute('aria-label','Прикрепить файл');attach.addEventListener('keydown',e=>{if(e.key==='Enter'||e.key===' '){e.preventDefault();const input=$('replyFile');if(input&&!input.disabled)input.click()}})}
    $('mobileLeadBtn').textContent='Клиент';$('mobileLeadBtn').setAttribute('aria-label','Карточка клиента');
    const clientCardClose=$('mobileLeadClose');clientCardClose.textContent='×';clientCardClose.title='Закрыть карточку клиента';clientCardClose.setAttribute('aria-label','Закрыть карточку клиента');
    const clientCardHeader=document.querySelector('.leadZone>.zoneHead');clientCardHeader.insertBefore(clientCardClose,clientCardHeader.querySelector('.leadHeaderActions'));
    const header=document.querySelector('.leadZone .brand');if(header)header.textContent='Карточка клиента';
    const clientName=document.createElement('span');clientName.id='m2ClientName';clientName.className='m2ClientName';document.querySelector('.leadHeaderText').appendChild(clientName);
    const syncClientName=()=>{clientName.textContent=S.current?$('conversationTitle').textContent:'';syncConversationMenu();syncClientCard()};new MutationObserver(syncClientName).observe($('conversationTitle'),{childList:true,subtree:true});syncClientName();
    $('conversationTitle').addEventListener('click',e=>openClientCard(e.currentTarget));
    document.querySelectorAll('[data-m2-nav]').forEach(b=>b.addEventListener('click',()=>navigate(b.dataset.m2Nav)));
    const dialog=$('m2Notifications');
    $('m2NotificationButton').onclick=()=>{notificationOpener=document.activeElement;dialog.showModal()};
    $('m2NotificationsClose').onclick=()=>dialog.close();dialog.addEventListener('close',()=>notificationOpener?.focus({preventScroll:true}));
    dialog.addEventListener('click',e=>{if(e.target!==dialog)return;const r=dialog.getBoundingClientRect();if(e.clientX<r.left||e.clientX>r.right||e.clientY<r.top||e.clientY>r.bottom)dialog.close()});
    if(health){const update=()=>{const kind=health.classList.contains('ok')?'healthy':health.classList.contains('critical')?'error':health.classList.contains('warn')?'warn':'';const dot=document.querySelector('.m2NotificationDot');dot.className='m2NotificationDot'+(kind?' '+kind:'');$('m2NotificationButton').title=health.textContent.trim();syncNavigation()};new MutationObserver(update).observe(health,{attributes:true,childList:true,subtree:true});update()}
    if(manager)new MutationObserver(syncNavigation).observe(manager,{childList:true,subtree:true});
    new MutationObserver(()=>{compactBoard();syncNavigation();syncClientCard()}).observe($('workspaceRoot'),{attributes:true,attributeFilter:['class']});
    new MutationObserver(compactBoard).observe($('kanbanBoard'),{childList:true});
    document.querySelector('.queueTabs').addEventListener('click',()=>{if(section==='tasks')section='dialogues';setTimeout(syncNavigation,0)});
    $('mobileLeadBtn').addEventListener('click',e=>{e.stopImmediatePropagation();openClientCard(e.currentTarget)});
    $('mobileLeadClose').addEventListener('click',()=>{if(!window.WorkspaceV2Mobile?.isMobile())closeClientCard()});
    window.addEventListener('popstate',()=>setTimeout(()=>{syncClientCard();if(window.WorkspaceV2Mobile?.getScreen()==='conversation'&&clientCardOpener?.isConnected)clientCardOpener.focus({preventScroll:true})},0));
    window.addEventListener('resize',()=>{if(window.WorkspaceV2Mobile?.isMobile()&&$('workspaceRoot').classList.contains('m2ClientCardOpen'))$('workspaceRoot').classList.remove('m2ClientCardOpen');syncClientCard()});
    $('leadZone').addEventListener('keydown',e=>{if(e.key==='Escape'&&!window.WorkspaceV2Mobile?.isMobile()&&!e.target.closest('dialog')){e.preventDefault();closeClientCard()}});
    document.addEventListener('keydown',e=>{if(e.key==='Escape')document.querySelectorAll('.m2ConversationMenu[open]').forEach(d=>{d.open=false;d.querySelector('summary').focus()})});
    updateClock();setInterval(updateClock,60000);syncNavigation();
  }
  window.Manager2Presentation={enhanceLeadCard,enhanceTasks,enhanceConversationActions,focusConversationTool,conversationHistoryTarget,revealTaskDraft,budgetFact,navigate,syncNavigation,enhanceInbox,enhanceInboxMarkers,ownershipSignal,formatInboxActivity,openClientCard,closeClientCard,syncClientCard,revealInboxSearch};
  init();
})();
