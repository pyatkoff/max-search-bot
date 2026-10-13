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
function captureSaveContext(){return{managerId:Number(S.manager?.id||0),generation:S.identityGeneration}}
function isCurrentSave(context){return context.managerId>0&&S.manager?.role==='admin'&&context.managerId===Number(S.manager.id)&&context.generation===S.identityGeneration}
function clearBusyButtons(){for(const [id,status] of [['saveProject','projectFormStatus'],['saveManager','managerFormStatus'],['saveRule','ruleFormStatus']]){const button=$(id);if(!button?.dataset.idleText)continue;button.textContent=button.dataset.idleText;delete button.dataset.idleText;setFormStatus(status,'Сохранение не подтверждено. Проверьте запись после повторного входа перед новой попыткой.','error')}}
function validateProjectSelection(){S.projectSelectionValid=![...document.querySelectorAll('#managerProjects input:checked')].some(x=>!S.data.projects.some(p=>Number(p.id)===Number(x.value)));return S.projectSelectionValid}
function validateRuleSelection(){const manager=$('ruleManager'),type=$('ruleType');S.ruleSelectionValid=!manager.selectedOptions[0]?.disabled&&!type.selectedOptions[0]?.disabled&&!!manager.value&&!!type.value;return S.ruleSelectionValid}
function selectSingleProjectForNewManager(){if(S.projectSelectionTouched||!S.projectCatalogReady)return;if(Number($('managerId').value||0)!==0)return;const inputs=[...document.querySelectorAll('#managerProjects input:not(:disabled)')];if(inputs.length===1&&!inputs.some(x=>x.checked))inputs[0].checked=true}
function resetProjectForm(){$('projectId').value='';$('projectKey').value='';$('projectName').value='';$('projectActive').checked=true}
function resetManagerForm(){S.projectSelectionTouched=false;$('managerId').value='';$('managerLogin').value='';$('managerName').value='';$('managerEmail').value='';$('managerRole').value='manager';$('managerPassword').value='';$('managerPriority').value=0;$('managerActive').checked=true;document.querySelectorAll('#managerProjects input').forEach(x=>x.checked=false)}
function resetRuleForm(){$('ruleId').value='';$('ruleValue').value='';$('ruleBonus').value=0;$('ruleComment').value='';$('ruleActive').checked=true;const manager=$('ruleManager');if(manager&&manager.options.length)manager.selectedIndex=0;const type=$('ruleType');if(type&&type.options.length)type.selectedIndex=0}
const sectionLabels={projects:'проекты',managers:'менеджеров',priority:'правила приоритета',audit:'журнал изменений'};
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
    resetProjectForm();resetManagerForm();resetRuleForm();['projectFormStatus','managerFormStatus','ruleFormStatus'].forEach(id=>setFormStatus(id));S.data={projects:[],managers:[],sources:[],audit:[],priority:{rules:[],rule_types:[]}};S.sections={projects:'pending',managers:'pending',priority:'pending',audit:'pending'};S.confirmed={projects:false,managers:false,priority:false,audit:false};S.projectChoicesKey=null;S.projectCatalogReady=false;S.priorityAvailable=false;S.projectSelectionValid=true;$('managerProjects').replaceChildren();window.ManagerAdminEditSafety?.sync?.();
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
function renderAudit(){const rows=S.data.audit||[];const body=rows.map(a=>{const actor=a.actor_name||a.actor_login||(a.actor_manager_id?'Менеджер #'+a.actor_manager_id:'Система');const target=[a.entity_type,a.entity_id?('#'+a.entity_id):'',a.project_key||''].filter(Boolean).join(' · ');return`<div class="auditRow"><div class="auditTime muted">${esc(window.ManagerTime.format(a.created_at,{year:true}))}</div><div class="auditActor">${esc(actor)}</div><div><span class="auditAction">${esc(auditLabels[a.action]||a.action||'Изменение')}</span>${target?`<div class="auditTarget">${esc(target)}</div>`:''}</div></div>`}).join('');$('audit').innerHTML=sectionView('audit',body,'<div class="auditEmpty">Изменений пока нет</div>')}
function render(){const d=S.data;
  $('projects').innerHTML=sectionView('projects',(d.projects||[]).map(p=>`<div class="item ${Number(p.is_active)?'':'off'}"><b>${esc(p.display_name)}</b> <span class="muted">${esc(p.project_key)}</span> <span class="pill">${Number(p.is_active)?'активен':'выключен'}</span> <a href="#" onclick="editProject(${p.id});return false">изменить</a></div>`).join(''),'<div class="muted">Проектов нет</div>');
  renderProjectChoices();
  $('managers').innerHTML=sectionView('managers',(d.managers||[]).map(m=>{const names=(m.project_ids||[]).map(id=>{const p=d.projects.find(x=>Number(x.id)===Number(id));return p?p.display_name:null}).filter(Boolean);return`<div class="item ${Number(m.is_active)?'':'off'}"><b>${esc(m.display_name||m.login)}</b> <span class="muted">${esc(m.login)}</span> <span class="pill">${esc(m.role)}</span> <span class="pill">приоритет ${Number(m.priority||0)}</span><br><span class="muted">${esc(names.join(', ')||'без проектов')}</span> <a href="#" onclick="editManager(${m.id});return false">изменить</a></div>`}).join(''),'<div class="muted">Менеджеров нет</div>');
  renderRuleChoices();
  const rules=(d.priority?.rules||[]).map(r=>{const m=(d.managers||[]).find(x=>Number(x.id)===Number(r.manager_id));return`<div class="item ${Number(r.is_active)?'':'off'}"><b>${esc(m?.display_name||m?.login||('Менеджер #'+r.manager_id))}</b> · ${esc(ruleLabels[r.rule_type]||r.rule_type)} = <b>${esc(r.rule_value)}</b> → <b>${Number(r.bonus)>=0?'+':''}${Number(r.bonus)}</b> <span class="pill">${Number(r.is_active)?'включено':'выключено'}</span>${r.comment?`<br><span class="muted">${esc(r.comment)}</span>`:''} <a href="#" onclick="editRule(${r.id});return false">изменить</a></div>`}).join('');
  $('priorityRules').innerHTML=sectionView('priority',rules,'<div class="muted">Правил пока нет</div>');renderAudit();bindLoadRetries();syncWriteAvailability();
}
window.editProject=id=>{const p=S.data.projects.find(x=>Number(x.id)===Number(id));if(!p)return;setFormStatus('projectFormStatus');$('projectId').value=p.id;$('projectKey').value=p.project_key;$('projectName').value=p.display_name;$('projectActive').checked=Number(p.is_active)===1};
window.editManager=id=>{S.projectSelectionTouched=true;const m=S.data.managers.find(x=>Number(x.id)===Number(id));if(!m)return;setManagerStatus();$('managerId').value=m.id;$('managerLogin').value=m.login;$('managerName').value=m.display_name||'';$('managerEmail').value=m.email||'';$('managerRole').value=m.role||'manager';$('managerPassword').value='';$('managerPriority').value=Number(m.priority||0);$('managerActive').checked=Number(m.is_active)===1;document.querySelectorAll('#managerProjects input').forEach(x=>x.checked=(m.project_ids||[]).map(Number).includes(Number(x.value)));validateProjectSelection();syncWriteAvailability()};
window.editRule=id=>{if(!S.priorityAvailable)return;const r=(S.data.priority?.rules||[]).find(x=>Number(x.id)===Number(id));if(!r)return;setFormStatus('ruleFormStatus');$('ruleId').value=r.id;$('ruleManager').value=r.manager_id;$('ruleType').value=r.rule_type;$('ruleValue').value=r.rule_value;$('ruleBonus').value=Number(r.bonus||0);$('ruleComment').value=r.comment||'';$('ruleActive').checked=Number(r.is_active)===1;validateRuleSelection();syncWriteAvailability()};
$('saveProject').onclick=async()=>{if($('saveProject').disabled)return;const context=captureSaveContext();setFormStatus('projectFormStatus','Сохраняем проект…');withBusyButton('saveProject',true);let j;try{j=await api('save_project',{project_id:Number($('projectId').value||0),project_key:$('projectKey').value,display_name:$('projectName').value,is_active:$('projectActive').checked?1:0})}catch(e){j={ok:false,error:'network_error'}}if(!isCurrentSave(context))return;withBusyButton('saveProject',false);if(j.ok){resetProjectForm();setFormStatus('projectFormStatus','Проект сохранён.','success');await load()}else{setFormStatus('projectFormStatus',errorText(j,'Не удалось сохранить проект. Данные формы сохранены — исправьте ошибку и повторите.'),'error');if(j?.error==='invalid_project_key')$('projectKey').focus();else if(j?.error==='missing_display_name')$('projectName').focus()}};
$('managerProjects').addEventListener('change',()=>{S.projectSelectionTouched=true;validateProjectSelection();syncWriteAvailability();setManagerStatus()});$('managerRole').addEventListener('change',()=>{setManagerStatus();selectSingleProjectForNewManager()});$('managerActive').addEventListener('change',()=>{setManagerStatus();selectSingleProjectForNewManager()});
$('saveManager').onclick=async()=>{if($('saveManager').disabled)return;const context=captureSaveContext();setManagerStatus();const manager_id=Number($('managerId').value||0);const login=$('managerLogin').value.trim();const password=$('managerPassword').value;const role=$('managerRole').value;const is_active=$('managerActive').checked?1:0;const project_ids=[...document.querySelectorAll('#managerProjects input:checked')].map(x=>Number(x.value));if(!/^[A-Za-z0-9._@+-]{3,191}$/.test(login)){setManagerStatus(adminErrors.invalid_login,'error');$('managerLogin').focus();return}if((manager_id===0||password!=='')&&password.length<8){setManagerStatus(adminErrors.password_too_short,'error');$('managerPassword').focus();return}if(is_active&&role==='manager'&&!S.projectCatalogReady){setManagerStatus('Проекты не загружены. Повторите загрузку списка проектов — данные формы останутся на месте.','error');return}if(is_active&&role==='manager'&&!project_ids.length){setManagerStatus(adminErrors.manager_requires_project,'error');$('managerProjects').scrollIntoView({block:'nearest'});return}withBusyButton('saveManager',true);let j;try{j=await api('save_manager',{manager_id,login,display_name:$('managerName').value,email:$('managerEmail').value,role,password,priority:Number($('managerPriority').value||0),is_active,project_ids})}catch(e){j={ok:false,error:'network_error'}}if(!isCurrentSave(context))return;withBusyButton('saveManager',false);if(j.ok){resetManagerForm();setManagerStatus('Менеджер сохранён.','success');await load();if(isCurrentSave(context))selectSingleProjectForNewManager()}else setManagerStatus(errorText(j,'Не удалось сохранить менеджера. Данные формы сохранены — исправьте ошибку и повторите.'),'error')};
['ruleManager','ruleType'].forEach(id=>$(id).addEventListener('change',()=>{validateRuleSelection();syncWriteAvailability();setFormStatus('ruleFormStatus')}));
$('saveRule').onclick=async()=>{if(!S.priorityAvailable||$('saveRule').disabled)return;const context=captureSaveContext();setFormStatus('ruleFormStatus','Сохраняем правило…');withBusyButton('saveRule',true);let j;try{j=await api('save_priority_rule',{rule_id:Number($('ruleId').value||0),manager_id:Number($('ruleManager').value||0),rule_type:$('ruleType').value,rule_value:$('ruleValue').value,bonus:Number($('ruleBonus').value||0),comment:$('ruleComment').value,is_active:$('ruleActive').checked?1:0})}catch(e){j={ok:false,error:'network_error'}}if(!isCurrentSave(context))return;withBusyButton('saveRule',false);if(j.ok){resetRuleForm();setFormStatus('ruleFormStatus','Правило сохранено.','success');await load()}else setFormStatus('ruleFormStatus',errorText(j,'Не удалось сохранить правило. Данные формы сохранены — повторите попытку.'),'error')};
window.ManagerAdminLoadState={formCancelled(kind){if(kind==='manager'){S.projectSelectionTouched=false;S.projectChoicesKey=null;renderProjectChoices()}if(kind==='rule')renderRuleChoices();syncWriteAvailability()}};
boot();
