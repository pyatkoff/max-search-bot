(function(){
function canonicalizeManagerUrl(){
  const path=String(location.pathname||'');
  if(!/\/index\.php$/.test(path))return;
  const canonical=path.replace(/index\.php$/,'')+location.search+location.hash;
  if(history?.replaceState)history.replaceState(history.state,'',canonical);
}
canonicalizeManagerUrl();
const S={csrf:'',manager:null,current:0,queue:'waiting',viewMode:'list',leadStageFilter:'',leadTagFilter:0,leadOutcomeFilter:'',leadTaskFilter:'',leadSearch:'',pipeline:{stages:[],tags:[],outcomes:{},closeReasons:{}},detail:null,searchTimer:null,authExpired:false,workspaceBound:false,booting:false};
const $=id=>document.getElementById(id);
function esc(v){const d=document.createElement('div');d.textContent=v??'';return d.innerHTML}
function showFatal(message){const box=$('inboxList');if(box){box.innerHTML=`<div class="empty"><strong>${esc(message)}</strong></div>`}const composer=$('composer');if(composer)composer.classList.add('hidden')}
function showStartupFailure(message='Не удалось загрузить рабочее место. Проверьте соединение и попробуйте ещё раз.'){
  const box=$('inboxList');
  if(box){box.innerHTML=`<div class="startupFailure" role="alert"><div class="startupFailureIcon" aria-hidden="true">↻</div><strong>Не удалось загрузить лиды</strong><span>${esc(message)}</span><button id="managerStartupRetry" class="actionBtn primary" type="button">Попробовать снова</button></div>`;const retry=$('managerStartupRetry');if(retry)retry.onclick=async()=>{retry.disabled=true;retry.textContent='Пробуем…';await boot()}}
  const health=$('notificationStatus');if(health){health.className='notificationHealth warn';const text=health.querySelector('.notificationText');if(text)text.innerHTML='<strong>Нет связи с сервером</strong><span>Рабочее место не загружено</span>'}
  const composer=$('composer');if(composer)composer.classList.add('hidden');
}
function ensureAuthRecovery(){
  let overlay=$('managerAuthRecovery');
  if(overlay)return overlay;
  overlay=document.createElement('div');
  overlay.id='managerAuthRecovery';
  overlay.className='managerAuthRecovery hidden';
  overlay.innerHTML=`<div class="managerAuthCard" role="dialog" aria-modal="true" aria-labelledby="managerAuthTitle"><div class="managerAuthBrand">AnyTour</div><h2 id="managerAuthTitle">Вход для менеджера</h2><p id="managerAuthMessage" class="managerAuthMessage">Сессия менеджера истекла. Войдите снова, чтобы продолжить.</p><form id="managerAuthForm"><label for="managerAuthLogin">Логин</label><input id="managerAuthLogin" name="login" type="text" autocomplete="username" autocapitalize="none" spellcheck="false" required><label for="managerAuthPassword">Пароль</label><input id="managerAuthPassword" name="password" type="password" autocomplete="current-password" required><div id="managerAuthError" class="managerAuthError hidden" role="alert"></div><button id="managerAuthSubmit" type="submit">Войти и продолжить</button></form></div>`;
  document.body.appendChild(overlay);
  $('managerAuthForm').addEventListener('submit',async e=>{e.preventDefault();await loginFromRecovery()});
  return overlay;
}
function showAuthRecovery(message='Сессия менеджера истекла. Войдите снова, чтобы продолжить.'){
  S.authExpired=true;
  const overlay=ensureAuthRecovery();
  $('managerAuthMessage').textContent=message;
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
  const r=await fetch(url,{method:'POST',headers:{'Content-Type':'application/json'},credentials:'same-origin',body:JSON.stringify({action,csrf:S.csrf,...data})});
  const j=await r.json().catch(()=>({ok:false,error:'invalid_response'}));
  if(r.status===401){showAuthRecovery();throw new Error('unauthorized')}
  if(!r.ok)return{...j,ok:false,http_status:r.status};
  return j;
}
const api=(action,data={})=>request('api.php',action,data);
const pipe=(action,data={})=>request('pipeline-api.php',action,data);
function statusText(s){return{ai:'AI',waiting_manager:'Ждёт менеджера',manager:'У менеджера',closed:'Закрыт'}[s]||s||''}
function outcomeText(s){return S.pipeline.outcomes[s]||{open:'В работе',won:'Продажа',lost:'Отказ'}[s]||'В работе'}
function formatWait(seconds){const s=Math.max(0,Number(seconds||0));if(s<60)return'<1 мин';const m=Math.floor(s/60);if(m<60)return`${m} мин`;const h=Math.floor(m/60),r=m%60;return`${h} ч${r?` ${r} мин`:''}`}
function val(v){return(v===null||v===undefined||v==='')?'—':String(v)}
function tripField(label,value){return`<div class="field"><span class="label">${esc(label)}</span><span class="value">${esc(val(value))}</span></div>`}
function applyIdentity(me){
  S.csrf=me.csrf||'';S.manager=me.manager||null;
  window.WorkspaceV2Media?.init();window.WorkspaceV2Media?.configure(S.csrf,S.current||0);
  if(S.manager){$('managerName').textContent=(S.manager.display_name||S.manager.login);const adminLink=$('adminLink');if(adminLink)adminLink.classList.toggle('hidden',S.manager.role!=='admin')}
}
async function loadCatalog(){
  const cat=await pipe('catalog').catch(()=>null);
  if(cat?.ok)S.pipeline={stages:cat.stages||[],tags:cat.tags||[],outcomes:cat.outcomes||{},closeReasons:cat.close_reasons||{}};
  window.WorkspaceV2Pipeline?.renderFilters();
}
function bindWorkspaceOnce(){
  if(S.workspaceBound)return;
  window.WorkspaceV2Pipeline?.bindFilters();window.WorkspaceV2Conversation?.bind();window.WorkspaceV2Inbox?.bind();window.WorkspaceV2Kanban?.bind();window.WorkspaceV2Mobile?.bind();window.WorkspaceV2Shortcuts?.bind();
  S.workspaceBound=true;
}
async function resumeAuthenticated(me){
  S.authExpired=false;hideAuthRecovery();applyIdentity(me);await loadCatalog();bindWorkspaceOnce();await window.WorkspaceV2Notifications?.init();await window.WorkspaceV2Notifications?.refresh();if(!S.authExpired)await window.WorkspaceV2Inbox?.load({preserveScroll:true}).catch(()=>{});
}
async function loginFromRecovery(){
  const login=$('managerAuthLogin').value.trim(),password=$('managerAuthPassword').value;
  const submit=$('managerAuthSubmit'),error=$('managerAuthError');
  if(!login||!password)return;
  submit.disabled=true;submit.textContent='Входим…';error.classList.add('hidden');
  try{
    const r=await fetch('api.php',{method:'POST',headers:{'Content-Type':'application/json'},credentials:'same-origin',body:JSON.stringify({action:'login',login,password})});
    const j=await r.json().catch(()=>({ok:false,error:'invalid_response'}));
    if(!r.ok||!j?.ok){error.textContent=r.status===401?'Неверный логин или пароль.':'Не удалось войти. Проверьте соединение и попробуйте ещё раз.';error.classList.remove('hidden');return}
    await resumeAuthenticated(j);
  }catch(e){error.textContent='Нет связи с сервером. Попробуйте ещё раз.';error.classList.remove('hidden')}
  finally{submit.disabled=false;submit.textContent='Войти и продолжить'}
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
window.WorkspaceV2={S,$,esc,api,pipe,statusText,outcomeText,formatWait,val,tripField,boot,showFatal,showAuthRecovery,showStartupFailure};
})();