(function(){
function canonicalizeManagerUrl(){
  const path=String(location.pathname||'');
  if(!/\/(?:index|workspace-v2)\.php$/.test(path))return;
  const canonical=path.replace(/(?:index|workspace-v2)\.php$/,'')+location.search+location.hash;
  if(history?.replaceState)history.replaceState(history.state,'',canonical);
}
canonicalizeManagerUrl();
const S={csrf:'',manager:null,current:0,queue:'waiting',viewMode:'list',leadStageFilter:'',leadTagFilter:0,leadOutcomeFilter:'',leadTaskFilter:'',leadSearch:'',pipeline:{stages:[],tags:[],outcomes:{},closeReasons:{}},detail:null,searchTimer:null,authExpired:false};
const $=id=>document.getElementById(id);
function esc(v){const d=document.createElement('div');d.textContent=v??'';return d.innerHTML}
function showFatal(message){const box=$('inboxList');if(box){box.innerHTML=`<div class="empty"><strong>${esc(message)}</strong><br>Обновите страницу после повторного входа.</div>`}const composer=$('composer');if(composer)composer.classList.add('hidden')}
async function request(url,action,data={}){const r=await fetch(url,{method:'POST',headers:{'Content-Type':'application/json'},credentials:'same-origin',body:JSON.stringify({action,csrf:S.csrf,...data})});const j=await r.json().catch(()=>({ok:false,error:'invalid_response'}));if(r.status===401){S.authExpired=true;showFatal('Сессия менеджера истекла.');throw new Error('unauthorized')}if(!r.ok)return{...j,ok:false,http_status:r.status};return j}
const api=(action,data={})=>request('api.php',action,data);
const pipe=(action,data={})=>request('pipeline-api.php',action,data);
function statusText(s){return{ai:'AI',waiting_manager:'Ждёт менеджера',manager:'У менеджера',closed:'Закрыт'}[s]||s||''}
function outcomeText(s){return S.pipeline.outcomes[s]||{open:'В работе',won:'Продажа',lost:'Отказ'}[s]||'В работе'}
function formatWait(seconds){const s=Math.max(0,Number(seconds||0));if(s<60)return'<1 мин';const m=Math.floor(s/60);if(m<60)return`${m} мин`;const h=Math.floor(m/60),r=m%60;return`${h} ч${r?` ${r} мин`:''}`}
function val(v){return(v===null||v===undefined||v==='')?'—':String(v)}
function tripField(label,value){return`<div class="field"><span class="label">${esc(label)}</span><span class="value">${esc(val(value))}</span></div>`}
async function boot(){const me=await api('me').catch(()=>null);if(!me?.ok)return;S.csrf=me.csrf;S.manager=me.manager;window.WorkspaceV2Media?.init();window.WorkspaceV2Media?.configure(S.csrf,0);$('managerName').textContent=(S.manager.display_name||S.manager.login)+' · Workspace V2';const adminLink=$('adminLink');if(adminLink)adminLink.classList.toggle('hidden',S.manager.role!=='admin');const cat=await pipe('catalog').catch(()=>null);if(cat?.ok)S.pipeline={stages:cat.stages||[],tags:cat.tags||[],outcomes:cat.outcomes||{},closeReasons:cat.close_reasons||{}};window.WorkspaceV2Pipeline?.renderFilters();window.WorkspaceV2Pipeline?.bindFilters();window.WorkspaceV2Conversation?.bind();window.WorkspaceV2Inbox?.bind();window.WorkspaceV2Kanban?.bind();window.WorkspaceV2Mobile?.bind();await window.WorkspaceV2Notifications?.init();if(!S.authExpired)await window.WorkspaceV2Inbox?.load().catch(()=>{})}
window.WorkspaceV2={S,$,esc,api,pipe,statusText,outcomeText,formatWait,val,tripField,boot,showFatal,canonicalizeManagerUrl};
})();
