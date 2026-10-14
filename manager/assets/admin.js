const S={csrf:'',manager:null,ownerId:0,identityGeneration:0,loading:false,loadFlight:null,loadOwner:'',projectCatalogReady:false,projectSelectionTouched:false,projectSelectionValid:true,projectChoicesKey:null,priorityAvailable:false,ruleSelectionValid:false,sections:{projects:'pending',managers:'pending',priority:'pending',audit:'pending'},confirmed:{projects:false,managers:false,priority:false,audit:false},data:{projects:[],managers:[],sources:[],audit:[],priority:{rules:[],rule_types:[]}}};const $=id=>document.getElementById(id);
const api=(action,data={})=>ManagerHttpClient.request(action,data,S.csrf);
function esc(v){const d=document.createElement('div');d.textContent=v??'';return d.innerHTML}
const ruleLabels={project_key:'Проект',source_key:'Источник',platform:'Платформа',entry_channel:'MAX-канал входа',region_id:'Регион',campaign_id:'Кампания'};
const auditLabels={project_created:'Создан проект',project_updated:'Изменён проект',manager_created:'Создан менеджер',manager_updated:'Изменён менеджер',priority_rule_created:'Создано правило',priority_rule_updated:'Изменено правило',priority_rule_deleted:'Удалено правило'};
const adminErrors={invalid_login:'Логин должен содержать минимум 3 символа: латинские буквы, цифры и . _ @ + -',password_too_short:'Пароль должен содержать минимум 8 символов.',cannot_remove_own_admin_access:'Нельзя отключить собственный аккаунт администратора или снять с него роль администратора.',manager_requires_project:'Для активного менеджера выберите хотя бы один проект.',duplicate_login:'Такой логин уже используется другим менеджером.',not_found:'Запись больше не существует. Обновите страницу и повторите.',invalid_project_key:'Код проекта заполнен неверно.',missing_display_name:'Укажите название.',duplicate_project_key:'Такой код проекта уже существует.',network_error:'Нет связи с сервером. Данные формы сохранены — повторите попытку.',invalid_response:'Сервер вернул некорректный ответ. Данные формы сохранены.'};
function errorText(j,fallback){return adminErrors[j?.error]||fallback}
function setFormStatus(id,text='',kind=''){const el=$(id);if(!el)return;el.textContent=text;el.className='formStatus'+(text?' visible':'')+(kind?' '+kind:'')}
function setManagerStatus(text='',kind=''){setFormStatus('managerFormStatus',text,kind)}
function withBusyButton(id,busy,busyText='Сохраняем…'){const btn=$(id);if(!btn)return;btn.disabled=!!busy;if(busy){if(!btn.dataset.idleText)btn.dataset.idleText=btn.textContent;btn.textContent=busyText}else{btn.textContent=btn.dataset.idleText||btn.textContent;delete btn.dataset.idleText;syncWriteAvailability()}}
const formFields={project:['projectId','projectKey','projectName','projectActive'],manager:['managerId','managerLogin','managerName','managerEmail','managerRole','managerPassword','managerPriority','managerActive'],rule:['ruleId','ruleManager','ruleType','ruleValue','ruleBonus','ruleComment','ruleActive']};
function formFingerprint(kind){const fields=(formFields[kind]||[]).map(id=>{const el=$(id);return[id,el?.value||'',!!el?.checked]});if(kind==='manager')fields.push(['projects',[...document.querySelectorAll('#managerProjects input:checked')].map(el=>el.value).sort()]);return JSON.stringify(fields)}
function captureSaveContext(kind){return{managerId:Number(S.manager?.id||0),generation:S.identityGeneration,kind,recordId:Number($(kind+'Id')?.value||0),fingerprint:formFingerprint(kind)}}
function isCurrentSave(context){return context.managerId>0&&S.manager?.role==='admin'&&context.managerId===Number(S.manager.id)&&context.generation===S.identityGeneration}
function workspaceStatus(text='',kind=''){const el=$('adminWorkspaceStatus');if(!el)return;el.textContent=text;el.className='formStatus'+(text?' visible':'')+(kind?' '+kind:'')}
function savedWithNewerDraft(context,message,result){if(context.fingerprint===formFingerprint(context.kind))return false;const field=$(context.kind+'Id'),savedId=Number(result?.[context.kind+'_id']||0);if(context.recordId===0&&Number(field?.value||0)===0&&Number.isInteger(savedId)&&savedId>0)field.value=savedId;setFormStatus(context.kind+'FormStatus',message+' Более новый черновик остался в форме и ещё не отправлен.','success');workspaceStatus(message+' Новый черновик остался в редакторе.','success');window.ManagerAdminEditSafety?.saved?.(context.kind,false,context.fingerprint);return true}
function finishEditorSave(kind,message){workspaceStatus(message,'success');window.ManagerAdminEditSafety?.saved?.(kind,true)}
function clearBusyButtons(){for(const [id,status] of [['saveProject','projectFormStatus'],['saveManager','managerFormStatus'],['saveRule','ruleFormStatus']]){const button=$(id);if(!button?.dataset.idleText)continue;button.textContent=button.dataset.idleText;delete button.dataset.idleText;setFormStatus(status,'Сохранение не подтверждено. Проверьте запись после повторного входа перед новой попыткой.','error')}}
function validateProjectSelection(){S.projectSelectionValid=![...document.querySelectorAll('#managerProjects input:checked')].some(x=>!S.data.projects.some(p=>Number(p.id)===Number(x.value)));return S.projectSelectionValid}
function validateRuleSelection(){const manager=$('ruleManager'),type=$('ruleType');S.ruleSelectionValid=!manager.selectedOptions[0]?.disabled&&!type.selectedOptions[0]?.disabled&&!!manager.value&&!!type.value;return S.ruleSelectionValid}
function selectSingleProjectForNewManager(){if(S.projectSelectionTouched||!S.projectCatalogReady)return;if(Number($('managerId').value||0)!==0)return;const inputs=[...document.querySelectorAll('#managerProjects input:not(:disabled)')];if(inputs.length===1&&!inputs.some(x=>x.checked))inputs[0].checked=true}
function resetProjectForm(){$('projectId').value='';$('projectKey').value='';$('projectName').value='';$('projectActive').checked=true}
function resetManagerForm(){S.projectSelectionTouched=false;$('managerId').value='';$('managerLogin').value='';$('managerName').value='';$('managerEmail').value='';$('managerRole').value='manager';$('managerPassword').value='';$('managerPriority').value=0;$('managerActive').checked=true;document.querySelectorAll('#managerProjects input').forEach(x=>x.checked=false)}
function resetRuleForm(){$('ruleId').value='';$('ruleValue').value='';$('ruleBonus').value=0;$('ruleComment').value='';$('ruleActive').checked=true;const manager=$('ruleManager');if(manager&&manager.options.length)manager.selectedIndex=0;const type=$('ruleType');if(type&&type.options.length)type.selectedIndex=0}
const sectionLabels={projects:'проекты',managers:'менеджеров',priority:'правила приоритета',audit:'журнал изменений'};
let selectedSection='managers';
function selectSection(name){
  if(!Object.hasOwn(sectionLabels,name))return;
  selectedSection=name;
  Object.keys(sectionLabels).forEach(section=>{const el=$('adminSection-'+section);if(el){el.classList.toggle('hidden',section!==name);el.setAttribute('aria-hidden',section===name?'false':'true')}});
  document.querySelectorAll('[data-admin-section]').forEach(button=>{const current=button.dataset.adminSection===name;button.classList.toggle('selected',current);button.classList.toggle('active',current);button.setAttribute('aria-current',current?'page':'false');button.setAttribute('aria-pressed',current?'true':'false')});
  window.ManagerAdminEditSafety?.sectionChanged?.(name);
}
function resetWorkspaceIdentity(){selectedSection='managers';['managerSearch','managerRoleFilter','managerActiveFilter','managerProjectFilter','auditSearch'].forEach(id=>{if($(id))$(id).value=''});workspaceStatus();window.ManagerAdminEditSafety?.resetIdentity?.();selectSection('managers')}
function signal(value){return value===true||value===1||value==='1'?true:value===false||value===0||value==='0'?false:null}
const roleLabels={manager:'Менеджер',admin:'Администратор'};
const reachabilityLabels={no_subscription:'Уведомления ещё не подключены',subscription_unhealthy:'Последняя отправка уведомления завершилась ошибкой',subscription_table_missing:'Сведения об уведомлениях недоступны',healthy_subscription:'Есть действующая подписка на уведомления'};
function reachabilitySignal(manager){return['unknown','subscription_table_missing'].includes(manager.reachability_reason)?null:signal(manager.is_reachable)}
function statusPill(value,yes,no,unknown){return`<span class="statusPill ${value===true?'positive':value===false?'neutral':'unknown'}">${esc(value===true?yes:value===false?no:unknown)}</span>`}
function bindWorkspace(){document.querySelectorAll('[data-admin-section]').forEach(button=>button.onclick=()=>selectSection(button.dataset.adminSection));['managerSearch','managerRoleFilter','managerActiveFilter','managerProjectFilter'].forEach(id=>$(id)?.addEventListener(id==='managerSearch'?'input':'change',()=>{renderManagers();bindLoadRetries();bindEditButtons()}));$('auditSearch')?.addEventListener('input',()=>{renderAudit();bindLoadRetries()});selectSection(selectedSection)}
function bindEditButtons(){document.querySelectorAll('[data-admin-edit]').forEach(button=>button.onclick=()=>{const kind=button.dataset.adminEdit,id=Number(button.dataset.adminId);if(kind==='manager')window.editManager(id);if(kind==='project')window.editProject(id);if(kind==='rule')window.editRule(id)})}
function editButton(kind,id,name){return`<button type="button" class="btn btnSecondary" data-admin-edit="${kind}" data-admin-id="${Number(id)}" data-admin-record-name="${esc(name).replace(/"/g,'&quot;')}" aria-label="Изменить ${esc(name).replace(/"/g,'&quot;')}">Изменить</button>`}
function sectionView(name,body,empty){
  const state=S.sections[name],label=sectionLabels[name];let message='';
  if(S.loading)message='Обновляем '+label+'…';
  else if(state==='partial')message='Полный список проектов не загружен. Показаны доступные проекты из данных входа.';
  else if(state==='stale')message='Не удалось обновить '+label+'. Показаны последние загруженные данные.';
  else if(state==='failed')message='Не удалось загрузить '+label+'.';
  else if(state==='pending')message='Загружаем '+label+'…';
  const retry=state!=='ready'&&!S.loading&&state!=='pending';
  const notice=message?`<div class="formStatus visible ${S.loading||state==='pending'?'':'error'}" role="status" aria-live="polite">${esc(message)}${retry?' <button type="button" class="btn btnSecondary" data-admin-retry>Повторить загрузку</button>':''}</div>`:'';
  const content=state==='ready'?(body||empty):(state==='stale'?(body||'<div class="muted">Последний загруженный список был пуст.</div>'):state==='partial'?body:'');
  return notice+content;
}
function bindLoadRetries(){document.querySelectorAll('[data-admin-retry]').forEach(button=>button.onclick=()=>load())}
function renderEditorLoadStates(){
  const dependencies={project:['projects'],manager:['managers','projects'],rule:['priority','managers']};
  Object.entries(dependencies).forEach(([kind,names])=>{const editor=$(kind+'Editor');if(!editor)return;let node=$(kind+'EditorLoadStatus');if(!node){node=document.createElement('div');node.id=kind+'EditorLoadStatus';node.setAttribute('role','status');node.setAttribute('aria-live','polite');const heading=$(kind+'EditorTitle');if(heading)heading.insertAdjacentElement('afterend',node);else editor.appendChild(node)}const unavailable=names.some(name=>S.sections[name]!=='ready');node.className='formStatus'+(unavailable?' visible':'')+(unavailable&&!S.loading?' error':'');if(!unavailable){node.replaceChildren();return}node.innerHTML=S.loading?'Обновляем данные. Черновик остаётся в форме.':'Не все данные загружены. Черновик остаётся в форме; сохранение доступно после успешной загрузки. <button type="button" class="btn btnSecondary" data-admin-retry>Повторить загрузку</button>'});
}
function syncWriteAvailability(){
  const admin=S.manager?.role==='admin',projectsReady=S.sections.projects==='ready',managersReady=S.sections.managers==='ready';
  ['saveProject','saveManager','saveRule'].forEach(id=>{const el=$(id);if(!el)return;const ready=id==='saveProject'?projectsReady:id==='saveManager'?projectsReady&&managersReady&&S.projectSelectionValid:S.priorityAvailable&&S.ruleSelectionValid;el.disabled=!admin||!ready||!!el.dataset.idleText});
}
function renderProjectChoices(){
  const projects=S.data.projects||[],key=JSON.stringify(projects.map(p=>[p.id,p.display_name,p.is_active])),inputs=[...document.querySelectorAll('#managerProjects input')],selected=inputs.filter(x=>x.checked).map(x=>Number(x.value));
  if(S.projectChoicesKey!==key){
    $('managerProjects').innerHTML=projects.length?projects.map(p=>`<label class="${Number(p.is_active)===0?'off':''}"><input type="checkbox" value="${p.id}" ${Number(p.is_active)===0?'disabled':''}> ${esc(p.display_name)}${Number(p.is_active)===0?' · выключен':''}</label>`).join(''):'<div class="muted">'+(S.sections.projects==='ready'?'Нет доступных проектов':'Проекты не загружены')+'</div>';
    const missing=selected.filter(id=>!projects.some(p=>Number(p.id)===id));
    if(missing.length)$('managerProjects').insertAdjacentHTML('beforeend',missing.map(id=>`<label class="off"><input type="checkbox" value="${id}" checked disabled data-project-missing> Проект #${id} отсутствует в загруженном каталоге</label>`).join(''));
    document.querySelectorAll('#managerProjects input').forEach(x=>x.checked=selected.includes(Number(x.value)));
    S.projectChoicesKey=key;
  }
  selectSingleProjectForNewManager();validateProjectSelection();
}
function renderRuleChoices(){
  const manager=$('ruleManager'),type=$('ruleType'),managerValue=manager.value,typeValue=type.value;
  manager.innerHTML=(S.data.managers||[]).filter(m=>Number(m.is_active)).map(m=>`<option value="${m.id}">${esc(m.display_name||m.login)}</option>`).join('');
  const types=S.data.priority?.rule_types||['project_key','source_key','platform','entry_channel','region_id','campaign_id'];
  type.innerHTML=types.map(t=>`<option value="${esc(t)}">${esc(ruleLabels[t]||t)}</option>`).join('');
  if(managerValue){if(![...manager.options].some(x=>x.value===managerValue)){const option=document.createElement('option');option.value=managerValue;option.textContent='Выбранный менеджер недоступен';option.disabled=true;manager.appendChild(option)}manager.value=managerValue}
  if(typeValue){if(![...type.options].some(x=>x.value===typeValue)){const option=document.createElement('option');option.value=typeValue;option.textContent='Выбранное условие недоступно';option.disabled=true;type.appendChild(option)}type.value=typeValue}
  validateRuleSelection();
  S.priorityAvailable=S.sections.priority==='ready'&&S.sections.managers==='ready';
  ['ruleManager','ruleType','ruleValue','ruleBonus','ruleComment','ruleActive'].forEach(id=>{const el=$(id);if(el)el.disabled=!S.priorityAvailable});
}
function showDenied(message,retry=false){
  S.identityGeneration++;clearBusyButtons();S.manager=null;S.csrf='';$('app').classList.add('hidden');const node=$('denied');node.classList.remove('hidden');node.textContent=message;
  if(retry){const button=document.createElement('button');button.type='button';button.className='btn btnSecondary';button.textContent='Повторить загрузку';button.onclick=()=>boot();node.appendChild(button)}
  syncWriteAvailability();
}
async function boot(){
  let j;try{j=await api('me')}catch(e){j={ok:false,error:'network_error'}}
  if(!j?.ok){showDenied(j?.error==='unauthorized'?'Войдите в кабинет менеджера, затем повторите загрузку.':'Не удалось открыть администрирование. Проверьте соединение и повторите загрузку.',true);return false}
  if(!j.manager||j.manager.role!=='admin'){showDenied('Доступно только администратору.');return false}
  if(S.ownerId&&S.ownerId!==Number(j.manager.id)){
    resetProjectForm();resetManagerForm();resetRuleForm();['projectFormStatus','managerFormStatus','ruleFormStatus'].forEach(id=>setFormStatus(id));S.data={projects:[],managers:[],sources:[],audit:[],priority:{rules:[],rule_types:[]}};S.sections={projects:'pending',managers:'pending',priority:'pending',audit:'pending'};S.confirmed={projects:false,managers:false,priority:false,audit:false};S.projectChoicesKey=null;S.projectCatalogReady=false;S.priorityAvailable=false;S.projectSelectionValid=true;$('managerProjects').replaceChildren();resetWorkspaceIdentity();window.ManagerAdminEditSafety?.sync?.();
  }
  S.identityGeneration++;clearBusyButtons();S.ownerId=Number(j.manager.id);S.csrf=j.csrf;S.manager=j.manager;
  if(!S.confirmed.projects&&Array.isArray(j.projects)&&j.projects.length){S.data.projects=j.projects.map(p=>({...p,is_active:p.is_active??1}));S.sections.projects='partial'}
  $('denied').classList.add('hidden');$('app').classList.remove('hidden');render();return load();
}
function failSections(){Object.keys(S.sections).forEach(name=>S.sections[name]=S.confirmed[name]?'stale':name==='projects'&&S.data.projects.length?'partial':'failed');S.projectCatalogReady=false;S.priorityAvailable=false}
function load(){
  if(!S.manager||S.manager.role!=='admin')return Promise.resolve(false);
  const managerId=Number(S.manager.id),generation=S.identityGeneration,owner=managerId+':'+generation;
  if(S.loadFlight&&S.loadOwner===owner)return S.loadFlight;
  S.loadOwner=owner;S.loading=true;render();
  const work=(async()=>{
    let j;try{j=await api('admin_snapshot')}catch(e){j={ok:false,error:'network_error'}}
    if(Number(S.manager?.id)!==managerId||generation!==S.identityGeneration)return false;
    if(!j?.ok||!j.admin||typeof j.admin!=='object'){
      failSections();
      if(j?.error==='csrf'){showDenied('Не удалось подтвердить доступ. Повторите загрузку, чтобы обновить сессию.',true);return false}
      if(['unauthorized','forbidden'].includes(j?.error)){showDenied(j.error==='unauthorized'?'Сессия истекла. Войдите в кабинет менеджера, затем повторите загрузку.':'Доступно только администратору.',j.error==='unauthorized');return false}
      return false;
    }
    const data=j.admin;
    ['projects','managers','audit'].forEach(name=>{if(Array.isArray(data[name])){S.data[name]=data[name];S.sections[name]='ready';S.confirmed[name]=true}else S.sections[name]=S.confirmed[name]?'stale':name==='projects'&&S.data.projects.length?'partial':'failed'});
    if(Array.isArray(data.sources))S.data.sources=data.sources;
    if(data.priority_available===true&&Array.isArray(data.priority?.rules)&&Array.isArray(data.priority?.rule_types)){S.data.priority=data.priority;S.sections.priority='ready';S.confirmed.priority=true}else S.sections.priority=S.confirmed.priority?'stale':'failed';
    S.projectCatalogReady=S.sections.projects==='ready';
    return ['projects','managers','audit','priority'].every(name=>S.sections[name]==='ready');
  })();
  const flight=work.finally(()=>{if(S.loadFlight!==flight)return;S.loadFlight=null;S.loading=false;render()});S.loadFlight=flight;return flight;
}
function renderManagerProjectFilter(){
  const filter=$('managerProjectFilter');if(!filter)return;const value=filter.value;
  filter.innerHTML='<option value="">Все проекты</option>'+(S.data.projects||[]).map(p=>`<option value="${Number(p.id)}">${esc(p.display_name)}</option>`).join('');
  if(value&&!Array.from(filter.options).some(option=>option.value===value)){const option=document.createElement('option');option.value=value;option.textContent='Выбранный проект недоступен';filter.appendChild(option)}filter.value=value;
}
function renderManagers(){
  const managers=S.data.managers||[],query=String($('managerSearch')?.value||'').trim().toLocaleLowerCase('ru'),role=$('managerRoleFilter')?.value||'',active=$('managerActiveFilter')?.value||'',project=$('managerProjectFilter')?.value||'';
  const visible=managers.filter(m=>(!query||[m.display_name,m.login,m.email].some(value=>String(value||'').toLocaleLowerCase('ru').includes(query)))&&(!role||m.role===role)&&(active===''||signal(m.is_active)===(active==='1'))&&(!project||(m.project_ids||[]).map(Number).includes(Number(project))));
  const results=$('managerResults');if(results)results.textContent=S.sections.managers==='ready'||S.sections.managers==='stale'?`Показано ${visible.length} из ${managers.length}`:'Список ещё не подтверждён';
  const body=visible.map(m=>{const name=m.display_name||m.login||('Менеджер #'+m.id),names=(m.project_ids||[]).map(id=>{const p=(S.data.projects||[]).find(x=>Number(x.id)===Number(id));return p?p.display_name:('Проект #'+id)}),initials=String(name).trim().split(/\s+/).slice(0,2).map(part=>part[0]||'').join('').toLocaleUpperCase('ru');
    return`<article class="item adminPerson ${signal(m.is_active)===false?'off':''}"><div class="adminPersonIdentity"><span class="adminPersonAvatar" aria-hidden="true">${esc(initials)}</span><div><div class="adminPersonName">${esc(name)}</div><div class="adminPersonMeta">${esc(m.login)} · ${esc(roleLabels[m.role]||'Роль не указана')} · Базовый приоритет ${Number(m.priority||0)}</div></div></div><div class="adminPersonStatus">${statusPill(signal(m.is_active),'Аккаунт активен','Аккаунт отключён','Аккаунт: нет данных')}${statusPill(signal(m.is_working),'На смене','Вне смены','Смена: нет данных')}${statusPill(reachabilitySignal(m),'Уведомления доступны','Уведомления недоступны','Уведомления: нет данных')}</div>${reachabilitySignal(m)!==true&&reachabilityLabels[m.reachability_reason]?`<div class="muted adminSignalDetail">${esc(reachabilityLabels[m.reachability_reason])}</div>`:''}<div class="adminPersonProjects">${esc(names.join(', ')||'Без доступа к проектам')}</div><div class="adminItemActions">${editButton('manager',m.id,name)}</div></article>`}).join('');
  const empty=managers.length?'<div class="adminEmpty">Никого не нашли по этим условиям. Измените поиск или фильтры.</div>':'<div class="adminEmpty">Менеджеров нет</div>';
  $('managers').innerHTML=sectionView('managers',S.sections.managers==='stale'&&managers.length?(body||empty):body,empty);
}
const auditEntityLabels={project:'Проект',manager:'Менеджер',priority_rule:'Правило приоритета'};
function auditSummary(a){const actor=a.actor_name||a.actor_login||(a.actor_manager_id?'Менеджер #'+a.actor_manager_id:'Система'),action=auditLabels[a.action]||'Изменение',target=[auditEntityLabels[a.entity_type]||'Объект',a.entity_id?('#'+a.entity_id):'',a.project_key||''].filter(Boolean).join(' · '),time=window.ManagerTime.format(a.created_at,{year:true});return{actor,action,target,time}}
function renderAudit(){const rows=(S.data.audit||[]).slice(0,50),query=String($('auditSearch')?.value||'').trim().toLocaleLowerCase('ru'),summaries=rows.map(auditSummary),visible=summaries.filter(a=>!query||[a.actor,a.action,a.target,a.time].join(' ').toLocaleLowerCase('ru').includes(query));const results=$('auditResults');if(results)results.textContent=S.sections.audit==='ready'||S.sections.audit==='stale'?`Показано ${visible.length} из ${rows.length} последних изменений`:'Журнал ещё не подтверждён';const body=visible.map(a=>`<div class="auditRow"><div class="auditTime muted">${esc(a.time)}</div><div class="auditActor">${esc(a.actor)}</div><div><span class="auditAction">${esc(a.action)}</span><div class="auditTarget">${esc(a.target)}</div></div></div>`).join('');$('audit').innerHTML=sectionView('audit',S.sections.audit==='stale'&&rows.length?(body||'<div class="auditEmpty">По этому запросу изменений не найдено.</div>'):body,rows.length?'<div class="auditEmpty">По этому запросу изменений не найдено.</div>':'<div class="auditEmpty">Изменений пока нет</div>')}
function render(){const d=S.data;
  $('projects').innerHTML=sectionView('projects',(d.projects||[]).map(p=>`<article class="item adminProject ${signal(p.is_active)===false?'off':''}"><div><b>${esc(p.display_name)}</b><div class="muted">${esc(p.project_key)}</div></div>${statusPill(signal(p.is_active),'Проект активен','Проект выключен','Статус: нет данных')}<div class="adminItemActions">${editButton('project',p.id,p.display_name)}</div></article>`).join(''),'<div class="adminEmpty">Проектов нет</div>');
  renderProjectChoices();renderManagerProjectFilter();renderManagers();renderRuleChoices();
  const rules=(d.priority?.rules||[]).map(r=>{const m=(d.managers||[]).find(x=>Number(x.id)===Number(r.manager_id)),name=m?.display_name||m?.login||('Менеджер #'+r.manager_id);return`<article class="item adminRule ${Number(r.is_active)?'':'off'}"><b>${esc(name)}</b><div class="adminRuleCondition">${esc(ruleLabels[r.rule_type]||'Условие')} = <b>${esc(r.rule_value)}</b> · Надбавка <b>${Number(r.bonus)>=0?'+':''}${Number(r.bonus)}</b></div>${statusPill(signal(r.is_active),'Правило включено','Правило выключено','Статус: нет данных')}${r.comment?`<div class="muted">${esc(r.comment)}</div>`:''}<div class="adminItemActions">${editButton('rule',r.id,'правило для '+name)}</div></article>`}).join('');
  $('priorityRules').innerHTML=sectionView('priority',rules,'<div class="adminEmpty">Правил пока нет</div>');renderAudit();renderEditorLoadStates();bindLoadRetries();bindEditButtons();syncWriteAvailability();
}
window.editProject=id=>{const p=S.data.projects.find(x=>Number(x.id)===Number(id));if(!p)return;setFormStatus('projectFormStatus');$('projectId').value=p.id;$('projectKey').value=p.project_key;$('projectName').value=p.display_name;$('projectActive').checked=Number(p.is_active)===1};
window.editManager=id=>{S.projectSelectionTouched=true;const m=S.data.managers.find(x=>Number(x.id)===Number(id));if(!m)return;setManagerStatus();$('managerId').value=m.id;$('managerLogin').value=m.login;$('managerName').value=m.display_name||'';$('managerEmail').value=m.email||'';$('managerRole').value=m.role||'manager';$('managerPassword').value='';$('managerPriority').value=Number(m.priority||0);$('managerActive').checked=Number(m.is_active)===1;document.querySelectorAll('#managerProjects input').forEach(x=>x.checked=(m.project_ids||[]).map(Number).includes(Number(x.value)));validateProjectSelection();syncWriteAvailability()};
window.editRule=id=>{if(!S.priorityAvailable)return;const r=(S.data.priority?.rules||[]).find(x=>Number(x.id)===Number(id));if(!r)return;setFormStatus('ruleFormStatus');$('ruleId').value=r.id;$('ruleManager').value=r.manager_id;$('ruleType').value=r.rule_type;$('ruleValue').value=r.rule_value;$('ruleBonus').value=Number(r.bonus||0);$('ruleComment').value=r.comment||'';$('ruleActive').checked=Number(r.is_active)===1;validateRuleSelection();syncWriteAvailability()};
$('saveProject').onclick=async()=>{if($('saveProject').disabled)return;const context=captureSaveContext('project');setFormStatus('projectFormStatus','Сохраняем проект…');withBusyButton('saveProject',true);let j;try{j=await api('save_project',{project_id:Number($('projectId').value||0),project_key:$('projectKey').value,display_name:$('projectName').value,is_active:$('projectActive').checked?1:0})}catch(e){j={ok:false,error:'network_error'}}if(!isCurrentSave(context))return;withBusyButton('saveProject',false);if(j.ok&&savedWithNewerDraft(context,'Проект сохранён.',j)){await load();return}if(j.ok){resetProjectForm();setFormStatus('projectFormStatus','Проект сохранён.','success');finishEditorSave('project','Проект сохранён.');await load();if(isCurrentSave(context))window.ManagerAdminEditSafety?.restoreSavedFocus?.('project')}else{setFormStatus('projectFormStatus',errorText(j,'Не удалось сохранить проект. Данные формы сохранены — исправьте ошибку и повторите.'),'error');if(j?.error==='invalid_project_key')$('projectKey').focus();else if(j?.error==='missing_display_name')$('projectName').focus()}};
$('managerProjects').addEventListener('change',()=>{S.projectSelectionTouched=true;validateProjectSelection();syncWriteAvailability();setManagerStatus()});$('managerRole').addEventListener('change',()=>{setManagerStatus();selectSingleProjectForNewManager()});$('managerActive').addEventListener('change',()=>{setManagerStatus();selectSingleProjectForNewManager()});
$('saveManager').onclick=async()=>{if($('saveManager').disabled)return;const context=captureSaveContext('manager');setManagerStatus();const manager_id=Number($('managerId').value||0);const login=$('managerLogin').value.trim();const password=$('managerPassword').value;const role=$('managerRole').value;const is_active=$('managerActive').checked?1:0;const project_ids=[...document.querySelectorAll('#managerProjects input:checked')].map(x=>Number(x.value));if(!/^[A-Za-z0-9._@+-]{3,191}$/.test(login)){setManagerStatus(adminErrors.invalid_login,'error');$('managerLogin').focus();return}if((manager_id===0||password!=='')&&password.length<8){setManagerStatus(adminErrors.password_too_short,'error');$('managerPassword').focus();return}if(is_active&&role==='manager'&&!S.projectCatalogReady){setManagerStatus('Проекты не загружены. Повторите загрузку списка проектов — данные формы останутся на месте.','error');return}if(is_active&&role==='manager'&&!project_ids.length){setManagerStatus(adminErrors.manager_requires_project,'error');$('managerProjects').scrollIntoView({block:'nearest'});return}withBusyButton('saveManager',true);let j;try{j=await api('save_manager',{manager_id,login,display_name:$('managerName').value,email:$('managerEmail').value,role,password,priority:Number($('managerPriority').value||0),is_active,project_ids})}catch(e){j={ok:false,error:'network_error'}}if(!isCurrentSave(context))return;withBusyButton('saveManager',false);if(j.ok&&savedWithNewerDraft(context,'Менеджер сохранён.',j)){await load();return}if(j.ok){resetManagerForm();setManagerStatus('Менеджер сохранён.','success');finishEditorSave('manager','Менеджер сохранён.');await load();if(isCurrentSave(context)){selectSingleProjectForNewManager();window.ManagerAdminEditSafety?.restoreSavedFocus?.('manager')}}else setManagerStatus(errorText(j,'Не удалось сохранить менеджера. Данные формы сохранены — исправьте ошибку и повторите.'),'error')};
['ruleManager','ruleType'].forEach(id=>$(id).addEventListener('change',()=>{validateRuleSelection();syncWriteAvailability();setFormStatus('ruleFormStatus')}));
$('saveRule').onclick=async()=>{if(!S.priorityAvailable||$('saveRule').disabled)return;const context=captureSaveContext('rule');setFormStatus('ruleFormStatus','Сохраняем правило…');withBusyButton('saveRule',true);let j;try{j=await api('save_priority_rule',{rule_id:Number($('ruleId').value||0),manager_id:Number($('ruleManager').value||0),rule_type:$('ruleType').value,rule_value:$('ruleValue').value,bonus:Number($('ruleBonus').value||0),comment:$('ruleComment').value,is_active:$('ruleActive').checked?1:0})}catch(e){j={ok:false,error:'network_error'}}if(!isCurrentSave(context))return;withBusyButton('saveRule',false);if(j.ok&&savedWithNewerDraft(context,'Правило сохранено.',j)){await load();return}if(j.ok){resetRuleForm();setFormStatus('ruleFormStatus','Правило сохранено.','success');finishEditorSave('rule','Правило сохранено.');await load();if(isCurrentSave(context))window.ManagerAdminEditSafety?.restoreSavedFocus?.('rule')}else setFormStatus('ruleFormStatus',errorText(j,'Не удалось сохранить правило. Данные формы сохранены — повторите попытку.'),'error')};
window.ManagerAdminWorkspace={selectSection,formFingerprint,workspaceStatus};
window.ManagerAdminLoadState={formCancelled(kind){if(kind==='manager'){S.projectSelectionTouched=false;S.projectChoicesKey=null;renderProjectChoices()}if(kind==='rule')renderRuleChoices();syncWriteAvailability()}};
bindWorkspace();boot();
