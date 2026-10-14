(function(){
'use strict';
const W=window.WorkspaceV2,{S,$,esc}=W;
const endpoint='customer-name-api.php',storagePrefix='workspaceV2.customerNameDraft.';
let dialog=null,context=null,name=null,history=[],historyMore=false,returnFocus=null,returnFocusId='',epoch=0;
let value='',dirty=false,version=0,pending=null,conflict=null,busy=false,readBusy=false,readOperation=null,readError='';
let onUpdated=null,needsProjection=false;
function managerId(){return !S.authExpired?Number(S.manager?.id||0):0}
function capture(c=S.detail?.conversation){return {conversationId:Number(c?.id||S.current||0),customerId:Number(c?.customer_id||0),managerId:managerId(),authGeneration:Number(S.authGeneration||0),openGeneration:Number(window.WorkspaceV2Conversation?.getOpenGeneration?.()||0)}}
function current(target=context){
  if(!target||!target.managerId||!target.conversationId)return false;
  const now=capture();
  return ['conversationId','managerId','authGeneration','openGeneration'].every(key=>now[key]===Number(target[key]))&&(!target.customerId||!now.customerId||target.customerId===now.customerId)&&Number(S.current||0)===target.conversationId;
}
function keyFor(target=context){return target?.managerId&&target?.customerId?storagePrefix+target.managerId+'.'+target.customerId:''}
function persist(){
  const key=keyFor();if(!key)return;
  try{if(dirty||pending)sessionStorage.setItem(key,JSON.stringify({value,dirty,version,pending}));else sessionStorage.removeItem(key)}catch(e){}
}
function restore(){
  const key=keyFor();if(!key)return;
  try{
    const saved=JSON.parse(sessionStorage.getItem(key)||'null');
    if(saved&&typeof saved.value==='string'&&saved.value.length<=500&&Number.isInteger(saved.version)&&saved.version>=0){value=saved.value;dirty=!!saved.dirty;version=saved.version}
    if(saved?.pending&&typeof saved.pending.operation_id==='string'&&saved.pending.data?.conversation_id)pending=saved.pending;
  }catch(e){}
}
function opId(){const bytes=new Uint8Array(16);window.crypto.getRandomValues(bytes);return Array.from(bytes,n=>n.toString(16).padStart(2,'0')).join('')}
function validName(candidate){return !!candidate&&Number.isSafeInteger(Number(candidate.customer_id))&&Number(candidate.customer_id)>0&&Number.isInteger(candidate.version)&&candidate.version>=0&&typeof candidate.display_name==='string'&&typeof candidate.can_edit==='boolean'}
function isUnknown(result){return !result||['network_error','invalid_response','operation_mismatch','operation_pending'].includes(result.error)||Number(result.http_status||0)>=500}
function errorText(result){return {invalid_name:'Введите имя от 1 до 120 символов без переносов строки.',invalid_version:'Обновите данные имени перед сохранением.',forbidden:'Рабочее имя меняет ответственный менеджер или администратор.',not_found:'Клиент больше недоступен. Выберите доступный диалог.',network_error:'Нет связи с сервером. Попробуйте снова.',conflict:'Имя изменил другой сотрудник. Ваш вариант сохранён; сравните оба имени.',operation_mismatch:'Результат требует проверки. Не повторяйте изменение.'}[result?.error]||'Не удалось получить подтверждение. Ваш вариант имени сохранён.'}
function status(text='',kind=''){
  const node=$('cnStatus');if(!node)return;node.textContent=text;node.className='cnStatus'+(kind?' '+kind:'');
}
async function request(action,data){
  const captured=epoch;if(!current())return {ok:false,error:'stale_session'};
  let result;try{result=await W.request(endpoint,action,data)}catch(e){result={ok:false,error:S.authExpired?'stale_session':'network_error'}}
  if(result?.ok&&(['get','save'].includes(action)&&!validName(result.name)||action==='operation'&&result.found&&result.result?.ok&&!validName(result.result.name)))result={ok:false,error:'invalid_response'};
  return captured===epoch&&current()?result:{ok:false,error:'stale_session'};
}
function canEdit(c){
  if(!managerId())return false;
  if(typeof c?.can_edit_customer_name==='boolean')return c.can_edit_customer_name;
  return S.manager?.role==='admin'||Number(c?.manager_id||0)===managerId();
}
function close(){persist();if(dialog?.open)dialog.close()}
function resetSession(){
  persist();epoch++;context=null;name=null;history=[];historyMore=false;value='';dirty=false;version=0;pending=null;conflict=null;busy=false;readBusy=false;readOperation=null;readError='';returnFocus=null;returnFocusId='';needsProjection=false;
  if(dialog){if(dialog.open)dialog.close();dialog.remove();dialog=null}
}
function syncContext(c){if(context&&(!current()||Number(c?.id)!==context.conversationId)){resetSession()}}
function originalMarkup(c,card=false){
  const original=String(c?.original_display_name||'').trim();
  return c?.working_name?`<span class="cnOriginalLabel">В мессенджере:</span> <span class="cnOriginalValue">${esc(original||'Имя не указано')}</span>`:card?'<span class="cnOriginalLabel">Используется имя из мессенджера</span>':'';
}
function enhanceHeader(c){
  syncContext(c);
  const root=document.querySelector('.conversationIdentityText'),title=document.querySelector('.conversationTitleRow');if(!root||!title)return;
  root.querySelector('.cnOriginalInline')?.remove();title.querySelector('#customerNameOpen')?.remove();
  if(c?.working_name){const line=document.createElement('div');line.className='cnOriginalInline';line.innerHTML=originalMarkup(c);line.title='В мессенджере: '+String(c.original_display_name||'Имя не указано');root.appendChild(line)}
  if(!canEdit(c))return;
  const button=document.createElement('button');button.id='customerNameOpen';button.className='cnEditIcon';button.type='button';button.setAttribute('aria-label','Изменить рабочее имя клиента');button.setAttribute('aria-haspopup','dialog');button.setAttribute('aria-controls','customerNameDialog');button.title='Рабочее имя клиента';
  button.innerHTML='<svg width="15" height="15" viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="m15 5 4 4M4 20l4-1 12-12a2.8 2.8 0 0 0-4-4L4 15v5Z" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"/></svg>';
  button.onclick=()=>open(c);title.appendChild(button);
}
function enhanceCard(c){
  syncContext(c);
  const root=$('leadCard'),hero=root?.querySelector('.leadHero');if(!hero)return;
  hero.querySelector('.cnCardIdentity')?.remove();
  const row=document.createElement('div');row.className='cnCardIdentity';
  row.innerHTML=`<div class="cnCardOriginal">${originalMarkup(c,true)}</div><button id="customerNameCardOpen" class="cnCardButton" type="button" aria-haspopup="dialog" aria-controls="customerNameDialog">${canEdit(c)?'Рабочее имя':'Имя и история'}</button>`;
  const title=hero.querySelector('.leadHeroTop')||hero.querySelector('.leadHeroName');
  if(title)title.insertAdjacentElement('afterend',row);else hero.prepend(row);
  $('customerNameCardOpen').onclick=()=>open(c);
}
function ensureDialog(){
  if(dialog)return dialog;
  dialog=document.createElement('dialog');dialog.id='customerNameDialog';dialog.className='cnDialog';dialog.setAttribute('aria-labelledby','cnHeading');
  dialog.innerHTML=`<div class="cnShell"><header class="cnHead"><div><span class="cnEyebrow">КАРТОЧКА КЛИЕНТА</span><h2 id="cnHeading">Имя клиента</h2><p>Понятное имя для вас и коллег.</p></div><button id="cnClose" class="cnClose" type="button" aria-label="Закрыть окно имени клиента">×</button></header>
    <div id="cnStatus" class="cnStatus" role="status" aria-live="polite"></div>
    <div class="cnBody"><div id="cnLoading" class="cnLoad" role="status">Загружаем имя и историю…</div><div id="cnLoadError" class="cnLoadError" hidden><strong>Не удалось загрузить имя клиента</strong><span>Переписка и черновики остаются на месте.</span><button id="cnRetry" class="cnButton" type="button">Повторить загрузку</button></div>
      <div id="cnContent" hidden><div class="cnIdentity"><div class="cnIdentityIcon" aria-hidden="true">Aa</div><div class="cnIdentityNames"><span class="cnLabel">Имя в кабинете</span><strong id="cnEffective"></strong><span class="cnSourceLine"><span class="cnLabel">В мессенджере:</span> <span id="cnOriginal"></span></span></div></div>
      <form id="cnForm" class="cnForm"><label for="cnInput">Рабочее имя<input id="cnInput" type="text" autocomplete="off" maxlength="120" placeholder="Например, Анна — семейная поездка" aria-describedby="cnHint cnReadonly"></label><p id="cnHint" class="cnHint">Это имя видят коллеги с доступом к клиенту. Клиенту оно не отправляется.</p><p id="cnReadonly" class="cnReadonly" hidden>Изменение доступно ответственному менеджеру или администратору.</p><div id="cnMeta" class="cnMeta"></div>
        <div id="cnConflict" class="cnConflict" hidden></div><div id="cnPending" class="cnPending" hidden></div>
        <div class="cnActions"><button id="cnSave" class="cnButton cnPrimary" type="submit">Сохранить имя</button><button id="cnRestore" class="cnButton" type="button">Вернуть исходное имя</button></div>
      </form>
      <details id="cnHistoryDetails" class="cnHistoryDetails"><summary>История имён <span id="cnHistoryCount"></span></summary><div id="cnHistory" class="cnHistory"></div><button id="cnMoreHistory" class="cnButton cnMore" type="button" hidden>Ранее</button></details>
      </div>
    </div><footer class="cnFooter"><span>Исходное имя и переписка сохраняются.</span><button id="cnCancel" class="cnButton" type="button">Закрыть</button></footer></div>`;
  document.body.appendChild(dialog);
  $('cnClose').onclick=close;$('cnCancel').onclick=close;$('cnRetry').onclick=()=>load();
  dialog.addEventListener('cancel',event=>{event.preventDefault();close()});dialog.addEventListener('keydown',event=>event.stopPropagation());
  dialog.addEventListener('close',()=>{
    if(!current())return;
    // Header/card identity projections replace the opener after a saved name.
    const target=returnFocus?.isConnected?returnFocus:$(returnFocusId);
    if(target?.isConnected&&!target.disabled)target.focus({preventScroll:true});
  });
  $('cnForm').addEventListener('submit',event=>{event.preventDefault();save(value)});
  $('cnRestore').onclick=()=>save(null);
  $('cnInput').addEventListener('input',()=>{value=$('cnInput').value;dirty=value!==String(name?.working_name||'');persist();if(!conflict)status();updateControls()});
  $('cnMoreHistory').onclick=()=>load({append:true});
  $('cnHistory').addEventListener('click',event=>{if(event.target.closest('[data-cn-history-retry]'))load({historyOnly:true})});
  return dialog;
}
function time(value){return value?window.ManagerTime.format(value,{year:true})+' МСК':''}
function renderHistory(){
  const node=$('cnHistory');if(!node)return;
  $('cnHistoryCount').textContent=history.length?String(history.length)+(historyMore?'+':''):'';
  if(readError){node.innerHTML='<div class="cnHistoryEmpty">Историю пока не удалось обновить. Имя сохраняется отдельно.<button class="cnButton" type="button" data-cn-history-retry>Повторить</button></div>';return}
  if(!history.length){node.innerHTML='<div class="cnHistoryEmpty">Рабочее имя ещё не меняли.</div>';return}
  const historyValue=name=>name===null||name===undefined?'<span class="cnHistoryOriginal">Исходное имя мессенджера</span>':esc(name);
  node.innerHTML=history.map(row=>`<div class="cnHistoryRow"><div class="cnHistoryChange"><span>${historyValue(row.before_name)}</span><span class="cnHistoryArrow" aria-label="изменено на">→</span><strong>${historyValue(row.after_name)}</strong></div><div class="cnHistoryMeta">${esc(row.actor_name||'Сотрудник')}${row.created_at?' · '+esc(time(row.created_at)):''}</div></div>`).join('');
}
function renderName(){
  if(!name)return;
  $('cnEffective').textContent=name.display_name||'Турист';$('cnOriginal').textContent=name.original_display_name||'Имя не указано';
  $('cnInput').value=value;
  $('cnMeta').textContent=name.updated_at?`Последнее изменение: ${name.updated_by_manager_name||'Сотрудник'} · ${time(name.updated_at)}`:'';
  renderConflict();renderPending();renderHistory();updateControls();
}
function renderConflict(){
  const node=$('cnConflict');node.hidden=!conflict;if(!conflict)return;
  node.innerHTML=`<strong>Имя изменил другой сотрудник</strong><div class="cnConflictCurrent">Сейчас: <b>${esc(conflict.display_name||'Турист')}</b></div><div class="cnMeta">${esc(conflict.updated_by_manager_name||'Сотрудник')}${conflict.updated_at?' · '+esc(time(conflict.updated_at)):''}</div><p>Ваш вариант остаётся в поле. Выберите, с каким именем продолжить.</p><div class="cnConflictActions"><button id="cnConflictKeep" class="cnButton" type="button">Продолжить с моим вариантом</button><button id="cnConflictReload" class="cnButton" type="button">Использовать имя коллеги</button></div>`;
  $('cnConflictKeep').onclick=()=>{if(busy||pending)return;name=conflict;version=Number(conflict.version);conflict=null;dirty=value!==String(name.working_name||'');persist();renderName();status('Ваш вариант сохранён в поле. Нажмите «Сохранить имя», чтобы применить его.','warn');$('cnInput').focus()};
  $('cnConflictReload').onclick=()=>{if(busy||pending)return;name=conflict;version=Number(conflict.version);value=String(name.working_name||'');dirty=false;conflict=null;persist();renderName();status('Используется актуальное имя коллеги.');$('cnInput').focus()};
}
function renderPending(){
  const node=$('cnPending');node.hidden=!pending;if(!pending)return;
  node.innerHTML='<strong>Результат изменения уточняется</strong><p>Новые изменения доступны после проверки. Ваш вариант имени сохранён.</p><button id="cnResolve" class="cnButton" type="button">Уточнить результат</button>';
  $('cnResolve').disabled=busy;$('cnResolve').onclick=reconcile;
}
function updateControls(){
  const disabled=busy||readBusy||!!pending||!name?.can_edit;
  $('cnInput').disabled=disabled;$('cnSave').disabled=disabled||!dirty||!!conflict;
  $('cnSave').textContent=busy?'Сохраняем…':'Сохранить имя';
  $('cnRestore').disabled=disabled||!name?.working_name||!!conflict;
  $('cnSave').hidden=!name?.can_edit;$('cnRestore').hidden=!name?.can_edit;$('cnReadonly').hidden=!!name?.can_edit;
  $('cnMoreHistory').hidden=!historyMore;$('cnMoreHistory').disabled=readBusy;
  if($('cnResolve'))$('cnResolve').disabled=busy||readBusy;
  ['cnConflictKeep','cnConflictReload'].forEach(id=>{if($(id))$(id).disabled=busy||!!pending});
}
async function load({append=false,historyOnly=false,refreshProjection=false}={}){
  if(!current())return;
  const data={conversation_id:context.conversationId,limit:20};
  if(append&&history.length)data.before_version=Math.min(...history.map(row=>Number(row.version)));
  const key=JSON.stringify(data),captured=epoch;
  if(readOperation?.key===key)return readOperation.promise;
  const operation={key,promise:null};readOperation=operation;readBusy=true;readError='';
  $('cnLoading').hidden=!!name;$('cnLoadError').hidden=true;updateControls();
  operation.promise=(async()=>{
    const result=await request('get',data);
    if(captured!==epoch||!current())return;
    readBusy=false;$('cnLoading').hidden=true;
    if(!result?.ok){
      if(!name){$('cnLoadError').hidden=false;$('cnContent').hidden=true}
      else{readError=errorText(result);renderHistory()}
      updateControls();return;
    }
    if(Number(result.name?.customer_id)!==Number(context.customerId)&&context.customerId){status('Клиент изменился. Откройте имя заново.','error');return}
    if(!context.customerId){context.customerId=Number(result.name.customer_id);restore()}
    const project=needsProjection||(refreshProjection&&!append&&Number(result.name.version)>=Number(name?.version||0)&&['version','working_name','original_display_name','can_edit'].some(key=>result.name[key]!==name?.[key]));
    if((!historyOnly&&!append)||project){
      name=result.name;
      if(dirty&&version!==Number(name.version))conflict=name;
      else if(!dirty){value=String(name.working_name||'');version=Number(name.version)}
    }
    const received=Array.isArray(result.history)?result.history:[];
    history=append?Array.from(new Map([...history,...received].map(row=>[row.version,row])).values()):received;historyMore=!!result.history_has_more;
    $('cnContent').hidden=false;renderName();persist();
    if(project){needsProjection=false;projectName()}
    if(!historyOnly&&!append){if(name.can_edit&&!pending)$('cnInput').focus();else $('cnCancel').focus()}
  })().finally(()=>{if(readOperation===operation)readOperation=null});
  return operation.promise;
}
async function save(workingName){
  if(!current()||!name?.can_edit||busy||readBusy||pending||conflict)return false;
  if(workingName!==null){workingName=String(workingName).trim();if(!workingName||Array.from(workingName).length>120||/[\u0000-\u001f\u007f-\u009f]/.test(workingName)){status(errorText({error:'invalid_name'}),'error');$('cnInput').focus();return false}}
  const captured=epoch;
  pending={operation_id:opId(),data:{conversation_id:context.conversationId,working_name:workingName,version}};persist();busy=true;renderPending();updateControls();status('Сохраняем имя…');
  const result=await request('save',{...pending.data,operation_id:pending.operation_id});
  if(captured!==epoch||!current())return false;
  busy=false;
  if(isUnknown(result)){status('Не удалось подтвердить результат. Уточните его перед следующим изменением.','warn');renderPending();updateControls();return false}
  finish(result);return !!result.ok;
}
function projectName(){
  const capturedContext={...context},captured=epoch;
  try{Promise.resolve(onUpdated?.(name,capturedContext)).catch(()=>{if(captured===epoch&&current())status('Имя сохранено. Список диалогов пока не удалось обновить.','success')})}catch(e){status('Имя сохранено. Список диалогов пока не удалось обновить.','success')}
}
function finish(result,{historyRead=true,message=''}={}){
  pending=null;busy=false;
  if(!result?.ok){
    if(result?.error==='conflict'&&result.current)conflict=result.current;
    if(result?.error==='forbidden'&&name)name.can_edit=false;
    persist();renderPending();renderConflict();updateControls();status(errorText(result),'error');return;
  }
  name=result.name;value=String(name.working_name||'');version=Number(name.version);dirty=false;conflict=null;persist();renderName();
  status(message||(name.working_name===null?'Исходное имя восстановлено.':'Рабочее имя сохранено.'),'success');
  // The root patches only identity projections and one Inbox read. It must not
  // reopen the conversation or rebuild active task/composer forms.
  projectName();
  if(historyRead)void load({historyOnly:true,refreshProjection:true});
}
async function reconcile(){
  if(!pending||busy||readBusy||!current())return;
  const operation=pending,captured=epoch;busy=true;renderPending();updateControls();
  const result=await request('operation',{conversation_id:context.conversationId,operation_id:operation.operation_id});
  if(captured!==epoch||!current()||pending!==operation)return;
  if(result?.ok&&result.found){
    if(!result.result?.ok){finish(result.result);return}
    // Receipts prove a historical write. A colleague may have renamed the same
    // customer since then; only a fresh snapshot can be painted as current.
    const snapshot=await request('get',{conversation_id:context.conversationId,limit:20});
    if(captured!==epoch||!current()||pending!==operation)return;
    if(snapshot?.ok&&Number(snapshot.name?.customer_id)===Number(context.customerId)&&Number(snapshot.name.version)>=Number(result.result.name?.version)){
      history=Array.isArray(snapshot.history)?snapshot.history:[];historyMore=!!snapshot.history_has_more;
      const later=Number(snapshot.name.version)!==Number(result.result.name?.version);
      finish({...result.result,name:snapshot.name},{historyRead:false,message:later?'Изменение подтверждено. После него имя обновили; показан текущий вариант.':'Изменение имени подтверждено.'});
    }else{
      pending=null;busy=false;dirty=false;conflict=null;needsProjection=true;name=null;persist();
      $('cnContent').hidden=true;$('cnLoadError').hidden=false;updateControls();
      status('Изменение подтверждено. Не удалось получить актуальное имя; повторите загрузку.','success');
    }
    return;
  }
  if(result?.ok&&result.found===false){
    // Only a checked missing receipt permits this exact-context, same-key retry.
    const retried=await request('save',{...operation.data,operation_id:operation.operation_id});
    if(captured!==epoch||!current()||pending!==operation)return;
    if(!isUnknown(retried)){finish(retried);return}
  }
  busy=false;renderPending();updateControls();status('Результат пока не подтверждён. Ваш вариант сохранён; уточните позже.','warn');
}
async function open(c=S.detail?.conversation){
  if(!managerId()||!c?.id||Number(c.id)!==Number(S.current))return false;
  if(dialog?.open&&current()){ $('cnInput').focus();return true }
  resetSession();context=capture(c);restore();ensureDialog();returnFocus=document.activeElement;returnFocusId=String(returnFocus?.id||'');dialog.showModal();$('cnClose').focus();status();await load();return true;
}
function configure(options={}){onUpdated=typeof options.onUpdated==='function'?options.onUpdated:null}
window.WorkspaceV2CustomerName={configure,enhanceHeader,enhanceCard,open,close,resetSession,capture,current};
})();
