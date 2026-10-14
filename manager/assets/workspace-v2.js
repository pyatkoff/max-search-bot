(function(){
function canonicalizeManagerUrl(){
  const path=String(location.pathname||'');
  if(!/\/index\.php$/.test(path))return;
  const canonical=path.replace(/index\.php$/,'')+location.search+location.hash;
  if(history?.replaceState)history.replaceState(history.state,'',canonical);
}
canonicalizeManagerUrl();
const S={csrf:'',manager:null,projects:[],filterSources:[],filterManagers:[],filterOptionsReady:false,current:0,queue:'waiting',viewMode:'list',leadProjectFilter:'*',leadSourceFilter:0,leadManagerFilter:'',leadStageFilter:'',leadTagFilter:0,leadOutcomeFilter:'',leadTaskFilter:'',leadSearch:'',pipeline:{stages:[],tags:[],outcomes:{},closeReasons:{}},detail:null,searchTimer:null,authExpired:false,authGeneration:0,workspaceBound:false,booting:false};
const $=id=>document.getElementById(id);
function esc(v){const d=document.createElement('div');d.textContent=v??'';return d.innerHTML}
function showFatal(message){const box=$('inboxList');if(box){box.innerHTML=`<div class="empty"><strong>${esc(message)}</strong></div>`}const composer=$('composer');if(composer)composer.classList.add('hidden')}
function showStartupFailure(message='Не удалось загрузить рабочее место. Проверьте соединение и попробуйте ещё раз.'){
  const box=$('inboxList');
  if(box){box.innerHTML=`<div class="startupFailure" role="alert"><div class="startupFailureIcon" aria-hidden="true">↻</div><strong>Не удалось загрузить диалоги</strong><span>${esc(message)}</span><button id="managerStartupRetry" class="actionBtn primary" type="button">Попробовать снова</button></div>`;const retry=$('managerStartupRetry');if(retry)retry.onclick=async()=>{retry.disabled=true;retry.textContent='Пробуем…';await boot()}}
  const health=$('notificationStatus');if(health){health.className='notificationHealth warn';const text=health.querySelector('.notificationText');if(text)text.innerHTML='<strong>Нет связи с сервером</strong><span>Рабочее место не загружено</span>'}
  const composer=$('composer');if(composer)composer.classList.add('hidden');
}
function ensureAuthRecovery(){
  let overlay=$('managerAuthRecovery');
  if(overlay)return overlay;
  overlay=document.createElement('div');
  overlay.id='managerAuthRecovery';
  overlay.className='managerAuthRecovery hidden';
  overlay.innerHTML=`<div class="managerAuthCard" role="dialog" aria-modal="true" aria-labelledby="managerAuthTitle"><div class="managerAuthBrand">AnyTour</div><h2 id="managerAuthTitle">Вход для менеджера</h2><p id="managerAuthMessage" class="managerAuthMessage">Войдите в кабинет, чтобы открыть диалоги и задачи.</p><form id="managerAuthForm"><label for="managerAuthLogin">Логин</label><input id="managerAuthLogin" name="login" type="text" autocomplete="username" autocapitalize="none" spellcheck="false" required><label for="managerAuthPassword">Пароль</label><input id="managerAuthPassword" name="password" type="password" autocomplete="current-password" required><div id="managerAuthError" class="managerAuthError hidden" role="alert"></div><button id="managerAuthSubmit" type="submit">Войти</button></form></div>`;
  document.body.appendChild(overlay);
  $('managerAuthForm').addEventListener('submit',async e=>{e.preventDefault();await loginFromRecovery()});
  return overlay;
}
function showAuthRecovery(message=''){
  if(!S.authExpired){S.authGeneration++;window.WorkspaceV2Sound?.suspend();window.WorkspaceV2Conversation?.suspendForAuthRecovery()}
  window.WorkspaceV2QuickReplies?.resetSession();window.WorkspaceV2CustomerName?.resetSession();window.WorkspaceV2CustomerAvatar?.resetSession();
  S.authExpired=true;
  const overlay=ensureAuthRecovery();
  const recovering=!!S.manager?.id;
  $('managerAuthMessage').textContent=message||(recovering?'Сессия менеджера истекла. Войдите снова, чтобы продолжить.':'Войдите в кабинет, чтобы открыть диалоги и задачи.');
  $('managerAuthSubmit').textContent=recovering?'Войти и продолжить':'Войти';
  $('managerAuthError').classList.add('hidden');
  $('managerAuthError').textContent='';
  $('managerAuthPassword').value='';
  overlay.classList.remove('hidden');
  document.body.classList.add('managerAuthOpen');
  const composer=$('composer');if(composer)composer.classList.add('hidden');
}
function hideAuthRecovery(){
  const overlay=$('managerAuthRecovery');if(overlay)overlay.classList.add('hidden');
  document.body.classList.remove('managerAuthOpen');
}
async function request(url,action,data={}){
  if(S.authExpired){showAuthRecovery();throw new Error('unauthorized')}
  const generation=S.authGeneration;
  const r=await fetch(url,{method:'POST',headers:{'Content-Type':'application/json'},credentials:'same-origin',body:JSON.stringify({action,csrf:S.csrf,...data})});
  const j=await r.json().catch(()=>({ok:false,error:'invalid_response'}));
  if(generation!==S.authGeneration)return{ok:false,error:'stale_session'};
  if(r.status===401){showAuthRecovery();throw new Error('unauthorized')}
  if(!r.ok)return{...j,ok:false,http_status:r.status};
  return j;
}
const api=(action,data={})=>request('api.php',action,data);
const pipe=(action,data={})=>request('pipeline-api.php',action,data);
const notificationEvents=cursor=>request('notification-events.php','poll',{cursor});
function statusText(s){return{ai:'С ботом',waiting_manager:'Ждёт менеджера',manager:'У менеджера',closed:'Диалог закрыт'}[s]||s||''}
function outcomeText(s){return S.pipeline.outcomes[s]||{open:'В работе',won:'Продажа',lost:'Отказ'}[s]||'В работе'}
function formatWait(seconds){const s=Math.max(0,Number(seconds||0));if(s<60)return'<1 мин';const m=Math.floor(s/60);if(m<60)return`${m} мин`;const h=Math.floor(m/60),r=m%60;return`${h} ч${r?` ${r} мин`:''}`}
function val(v){return(v===null||v===undefined||v==='')?'—':String(v)}
function tripField(label,value){return`<div class="field"><span class="label">${esc(label)}</span><span class="value">${esc(val(value))}</span></div>`}
function applyIdentity(me){
  S.csrf=me.csrf||'';S.manager=me.manager||null;S.projects=Array.isArray(me.projects)?me.projects:[];
  window.WorkspaceV2Sound?.activate(S.manager?.id);
  window.WorkspaceV2Media?.init();window.WorkspaceV2Media?.configure(S.csrf,S.current||0);
  if(S.manager){$('managerName').textContent=(S.manager.display_name||S.manager.login);const adminLink=$('adminLink');if(adminLink)adminLink.classList.toggle('hidden',S.manager.role!=='admin')}
}
async function loadCatalog(){
  const generation=S.authGeneration;
  const [cat,filterOptions]=await Promise.all([pipe('catalog').catch(()=>null),pipe('filter_options').catch(()=>null)]);
  if(generation!==S.authGeneration||S.authExpired)return;
  if(cat?.ok)S.pipeline={stages:cat.stages||[],tags:cat.tags||[],outcomes:cat.outcomes||{},closeReasons:cat.close_reasons||{}};
  if(filterOptions?.ok&&filterOptions.filters){const f=filterOptions.filters;S.projects=Array.isArray(f.projects)?f.projects:S.projects;S.filterSources=Array.isArray(f.sources)?f.sources:[];S.filterManagers=Array.isArray(f.managers)?f.managers:[];S.filterOptionsReady=true}else{S.filterSources=[];S.filterManagers=[];S.filterOptionsReady=false}
  window.WorkspaceV2Filters?.render();
}
async function applyCustomerName(name,context){
  const current=window.WorkspaceV2Conversation?.captureReplyContext?.();
  if(S.authExpired||!name||!context||!current||['conversationId','managerId','authGeneration','openGeneration'].some(key=>Number(context[key])!==Number(current[key]))||Number(context.customerId)!==Number(S.detail?.conversation?.customer_id)||Number(name.customer_id)!==Number(context.customerId))return false;
  const c=S.detail.conversation;
  if(Number(name.version)<Number(c.customer_name_version||0))return false;
  Object.assign(c,{display_name:name.display_name,original_display_name:name.original_display_name,working_name:name.working_name,customer_name_version:Number(name.version||0)});
  window.WorkspaceV2Conversation?.renderHeader(c);
  const hero=$('leadCard')?.querySelector('.leadHeroName');if(hero)hero.textContent=c.display_name||'Турист';
  window.WorkspaceV2CustomerName?.enhanceCard(c);window.WorkspaceV2CustomerAvatar?.enhanceCard(c);
  // Refresh only the selected list/board. Reopening the transcript/card would
  // overwrite unfinished task/outcome editors and disturb the reply viewport.
  await window.WorkspaceV2Inbox?.load({preserveScroll:true});return true;
}
function bindWorkspaceOnce(){
  if(S.workspaceBound)return;
  window.WorkspaceV2Filters?.bind();window.WorkspaceV2Conversation?.bind();window.WorkspaceV2Inbox?.bind();window.WorkspaceV2Kanban?.bind();window.WorkspaceV2Mobile?.bind();window.WorkspaceV2Shortcuts?.bind();window.WorkspaceV2QuickReplies?.bind();
  S.workspaceBound=true;window.WorkspaceV2CustomerName?.configure({onUpdated:applyCustomerName});
}
async function refreshNotificationHealth(generation,managerId){
  const stillAuthenticated=()=>!S.authExpired&&S.authGeneration===generation&&Number(S.manager?.id||0)===managerId;
  try{
    await window.WorkspaceV2Notifications?.init();
    if(stillAuthenticated())await window.WorkspaceV2Notifications?.refresh();
  }catch(e){}
}
async function resumeAuthenticated(me){
  const previousId=Number(S.manager?.id||0),sameIdentity=previousId>0&&previousId===Number(me.manager?.id||0),recovering=S.authExpired;
  const conversationId=recovering&&sameIdentity?Number(S.current||0):0,conversationGeneration=window.WorkspaceV2Conversation?.getOpenGeneration();
  S.authGeneration++;
  if(previousId&&!sameIdentity){
    window.WorkspaceV2Conversation?.resetForIdentityChange();
    window.WorkspaceV2Media?.resetDrafts?.();
    window.WorkspaceV2QuickReplies?.resetSession();window.WorkspaceV2CustomerName?.resetSession();window.WorkspaceV2CustomerAvatar?.resetSession();
    $('inboxList')?.replaceChildren();$('kanbanBoard')?.replaceChildren();$('leadCard')?.replaceChildren();
    window.WorkspaceV2Mobile?.showInbox({historyMode:'replace'});
  }
  S.authExpired=false;hideAuthRecovery();applyIdentity(me);
  const generation=S.authGeneration,managerId=Number(S.manager?.id||0);
  const stillAuthenticated=()=>!S.authExpired&&S.authGeneration===generation&&Number(S.manager?.id||0)===managerId;
  const savedConversationId=window.WorkspaceV2Conversation?.activateReplySession(S.manager?.id);
  await loadCatalog();if(!stillAuthenticated())return;bindWorkspaceOnce();
  // Personal reply shortcuts load independently; they must not delay the Inbox.
  void window.WorkspaceV2QuickReplies?.configure?.();
  // Native push registration and its health request must not hold the Inbox or
  // a recovered conversation behind a slow/unavailable notification provider.
  void refreshNotificationHealth(generation,managerId);
  await window.WorkspaceV2Inbox?.load({preserveScroll:true}).catch(()=>{});
  if(!stillAuthenticated())return;
  if(conversationId&&!S.authExpired&&Number(S.current)===conversationId&&window.WorkspaceV2Conversation?.getOpenGeneration()===conversationGeneration)await window.WorkspaceV2Conversation?.open(conversationId,{preserveMessageScroll:true,mobileHistory:'none',preserveAttachment:true});
  else if(savedConversationId===window.WorkspaceV2Conversation?.getSavedSelection()&&savedConversationId&&!S.authExpired&&!S.current&&window.WorkspaceV2Conversation?.getOpenGeneration()===conversationGeneration)await window.WorkspaceV2Conversation?.open(savedConversationId,{restoreSession:true,mobileHistory:window.WorkspaceV2Mobile?.reloadHistoryMode?.()||'none'});
}
async function loginFromRecovery(){
  const login=$('managerAuthLogin').value.trim(),password=$('managerAuthPassword').value;
  const submit=$('managerAuthSubmit'),error=$('managerAuthError');
  if(!login||!password)return;
  const submitLabel=submit.textContent;
  submit.disabled=true;submit.textContent='Входим…';error.classList.add('hidden');
  try{
    const r=await fetch('api.php',{method:'POST',headers:{'Content-Type':'application/json'},credentials:'same-origin',body:JSON.stringify({action:'login',login,password})});
    const j=await r.json().catch(()=>({ok:false,error:'invalid_response'}));
    if(!r.ok||!j?.ok){error.textContent=r.status===401?'Неверный логин или пароль.':'Не удалось войти. Проверьте соединение и попробуйте ещё раз.';error.classList.remove('hidden');return}
    await resumeAuthenticated(j);
  }catch(e){error.textContent='Нет связи с сервером. Попробуйте ещё раз.';error.classList.remove('hidden')}
  finally{submit.disabled=false;submit.textContent=submitLabel}
}
async function boot(){
  if(S.booting)return;
  S.booting=true;
  try{
    const me=await api('me');
    if(!me?.ok){if(!S.authExpired)showStartupFailure();return}
    await resumeAuthenticated(me);
  }catch(e){if(!S.authExpired)showStartupFailure()}
  finally{S.booting=false}
}
window.WorkspaceV2={S,$,esc,api,pipe,request,notificationEvents,statusText,outcomeText,formatWait,val,tripField,boot,showFatal,showAuthRecovery,showStartupFailure};
})();
