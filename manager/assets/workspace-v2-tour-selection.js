(function(){
'use strict';
const W=window.WorkspaceV2,{S,$,esc}=W,endpoint='tour-selections-api.php';
let dialog=null,context=null,epoch=0,version=0,draft=null,selected='',canEdit=false,dirty=false,busy=false,loading=false,pending=null,conflict=null,timer=0,returnFocus=null,rawAges={};
const sessions=new Map();
function actor(){return S.authExpired?0:Number(S.manager?.id||0)}
function capture(c=S.detail?.conversation){return {actor:actor(),conversation:Number(c?.id||0),auth:Number(S.authGeneration||0),open:Number(window.WorkspaceV2Conversation?.getOpenGeneration?.()||0)}}
function current(target=context){const now=capture();return !!target&&target.actor>0&&target.conversation>0&&target.actor===now.actor&&target.conversation===now.conversation&&target.conversation===Number(S.current)&&target.auth===now.auth&&target.open===now.open}
function key(c=context){return c?.actor&&c?.conversation?'workspaceV2.tourSelection.'+c.actor+'.'+c.conversation:''}
function clone(value){return JSON.parse(JSON.stringify(value))}
function id(){const data=new Uint8Array(16);crypto.getRandomValues(data);return Array.from(data,n=>n.toString(16).padStart(2,'0')).join('')}
function empty(){return {title:'Подборка туров',intro:'',offers:[]}}
function stash(){
  if(!context||!draft)return;
  const data={draft:clone(draft),version,selected,dirty,rawAges:clone(rawAges),pending:pending?clone(pending):null,conflict:conflict?clone(conflict):null,at:Date.now()};
  sessions.set(key(),data);
  try{if(dirty||pending||conflict)sessionStorage.setItem(key(),JSON.stringify(data));else sessionStorage.removeItem(key())}catch(e){}
}
function restore(){
  let data=sessions.get(key());
  try{if(!data)data=JSON.parse(sessionStorage.getItem(key())||'null')}catch(e){}
  if(!data||Date.now()-Number(data.at||0)>86400000||!data.draft||!Array.isArray(data.draft.offers)||data.draft.offers.length>20||JSON.stringify(data).length>300000)return false;
  draft=clone(data.draft);version=Number(data.version||0);selected=String(data.selected||'');dirty=!!data.dirty;rawAges=data.rawAges||{};pending=data.pending||null;conflict=data.conflict||null;return true;
}
function status(text,error=false){const el=$('tsStatus');if(el){el.textContent=text;el.classList.toggle('tsError',error)}}
function error(result){return {forbidden:'Изменения доступны ответственному менеджеру или администратору.',not_found:'Доступ к диалогу изменился.',invalid_draft:'Проверьте данные: даты, сумму, возраст детей и ссылки без секретных параметров.',conflict:'Подборку изменил другой сотрудник. Ваши правки сохранены отдельно.',storage_unavailable:'Сервер пока не подтвердил сохранение.',network_error:'Нет связи с сервером.',operation_mismatch:'Не удалось сопоставить сохранение. Требуется проверка.'}[result?.error]||'Не удалось подтвердить действие. Ваши правки сохранены.'}
async function request(action,data={}){
  const target=context,token=epoch;if(!current(target))return {ok:false,error:'stale_context'};
  const controller=new AbortController(),timeout=setTimeout(()=>controller.abort(),20000);
  try{
    const result=await W.request(endpoint,action,{conversation_id:target.conversation,...data},{signal:controller.signal,isCurrent:()=>token===epoch&&current(target)});
    return token===epoch&&current(target)?result:{ok:false,error:'stale_context'};
  }catch(e){return {ok:false,error:current(target)?'network_error':'stale_context'}}finally{clearTimeout(timeout)}
}
function close(){clearTimeout(timer);timer=0;stash();if(dialog?.open)dialog.close()}
function suspend(){stash();clearTimeout(timer);timer=0;epoch++;context=null;draft=null;pending=null;conflict=null;dirty=false;busy=false;loading=false;canEdit=false;if(dialog?.open)dialog.close();$('tourSelectionOpen')?.remove()}
function activate(){if(context&&!current())suspend()}
function sync(c){
  if(context&&!current())suspend();
  const root=document.querySelector('.conversationTitleRow');if(!root)return;
  let button=$('tourSelectionOpen');
  if(!actor()||Number(c?.id)!==Number(S.current)){button?.remove();return}
  if(!button){button=document.createElement('button');button.id='tourSelectionOpen';button.type='button';button.className='tsOpen';button.textContent='Подборка';button.setAttribute('aria-haspopup','dialog');button.setAttribute('aria-controls','tourSelectionDialog');root.appendChild(button)}
  button.onclick=()=>open(c);
  if(context&&current()&&(c.status==='closed'||!(S.manager?.role==='admin'||Number(c.manager_id)===actor()))&&canEdit){canEdit=false;clearTimeout(timer);controls();status('Доступ к редактированию изменился. Ваши правки сохранены отдельно.',true)}
}
function ensure(){
  if(dialog)return;
  dialog=document.createElement('dialog');dialog.id='tourSelectionDialog';dialog.className='tsDialog';dialog.setAttribute('aria-labelledby','tsTitle');
  dialog.innerHTML=`<div class="tsShell"><header class="tsHead"><div><span class="tsKicker">Подборка туров</span><h2 id="tsTitle"></h2><p id="tsRecipient"></p></div><button id="tsClose" type="button" class="tsClose" aria-label="Закрыть подборку">×</button></header><div id="tsStatus" class="tsStatus" role="status" aria-live="polite"></div><div id="tsRecovery" class="tsRecovery" hidden></div><div class="tsLayout"><aside class="tsSidebar"><div class="tsListHead"><strong id="tsCount">Варианты</strong><button id="tsAdd" type="button">+ Добавить</button></div><div id="tsList" class="tsList"></div><div class="tsSidebarHint">Черновик сохраняется для этого клиента. Добавление варианта ничего не отправляет.</div></aside><main id="tsEditor" class="tsEditor"></main></div><footer class="tsFooter"><span id="tsSaved">Загружаем черновик…</span><button id="tsSave" type="button" class="tsPrimary">Сохранить</button><button id="tsDone" type="button">Готово</button></footer></div>`;
  document.body.appendChild(dialog);
  $('tsClose').onclick=close;$('tsDone').onclick=()=>{if(dirty&&!busy&&!pending&&!conflict)void save();else close()};$('tsSave').onclick=()=>save();$('tsAdd').onclick=add;
  dialog.addEventListener('cancel',e=>{e.preventDefault();close()});dialog.addEventListener('keydown',e=>e.stopPropagation());
  dialog.addEventListener('close',()=>{if(current()&&returnFocus?.isConnected)returnFocus.focus({preventScroll:true})});
  $('tsList').addEventListener('click',event=>{const button=event.target.closest('[data-ts-select]');if(button){selected=button.dataset.tsSelect;render()}});
  $('tsEditor').addEventListener('input',edit);$('tsEditor').addEventListener('change',edit);
  $('tsEditor').addEventListener('click',event=>{const action=event.target.closest('[data-ts-action]')?.dataset.tsAction;if(action)mutate(action)});
}
async function open(c){
  if(!actor()||Number(c.id)!==Number(S.current))return;
  close();epoch++;context=capture(c);version=0;draft=empty();selected='';dirty=false;busy=false;pending=null;conflict=null;rawAges={};canEdit=false;restore();ensure();returnFocus=$('tourSelectionOpen');
  $('tsTitle').textContent=c.display_name||'Турист';$('tsRecipient').textContent=(c.channel==='telegram'?'Telegram':c.channel==='max'?'MAX':String(c.channel||''))+' · Только черновик';
  render();if(!dialog.open)dialog.showModal();await load();
}
function validSnapshot(value){return value&&Number(value.conversation_id)===context?.conversation&&Number.isInteger(value.version)&&value.version>=0&&typeof value.can_edit==='boolean'&&value.draft&&Array.isArray(value.draft.offers)&&value.draft.offers.length<=20}
async function load(){
  loading=true;controls();const token=epoch,result=await request('get');if(token!==epoch)return;loading=false;
  if(!result?.ok||!validSnapshot(result.selection)){status(error(result),true);if([403,404].includes(result?.http_status))canEdit=false;recovery('read');controls();return}
  const snapshot=result.selection;canEdit=snapshot.can_edit;
  if(!dirty&&!pending&&!conflict){draft=clone(snapshot.draft);version=snapshot.version}
  else if(snapshot.version!==version&&!pending)conflict=snapshot;
  render();status(!canEdit?'Просмотр. Править может ответственный менеджер или администратор.':dirty?'Восстановлены ваши несохранённые правки.':'Черновик загружен.');
  if(pending)recovery('pending');else if(conflict)recovery('conflict');
}
function newOffer(){return {id:id(),selected:true,hotel:'',destination:'',departure:'',room:'',meal:'',source_label:'',rationale:'',compromise:'',flights:'',inclusions:'',constraints:'',stars:null,nights:null,adults:null,date_from:'',child_ages:[],amount:'',currency:'RUB',source_url:'',observed_at:'',photos:[]}}
function add(){if(!editable()||draft.offers.length>=20)return;const item=newOffer();draft.offers.push(item);selected=item.id;changed();render();$('ts-hotel')?.focus()}
function editable(){return current()&&canEdit&&!loading&&!pending&&!conflict}
function invalidAges(){return draft?.offers.some(x=>Object.hasOwn(rawAges,x.id)&&!validAges(rawAges[x.id]))}
function validAges(value){const raw=String(value).trim();return raw===''||/^\d{1,2}(?:\s*,\s*\d{1,2})*$/.test(raw)&&raw.split(',').length<=12&&raw.split(',').every(x=>Number(x)>=0&&Number(x)<=17)}
function changed(){dirty=true;stash();status(invalidAges()?'Возраст детей укажите числами от 0 до 17 через запятую.':'Есть несохранённые изменения.',invalidAges());controls();clearTimeout(timer);timer=setTimeout(()=>{if(editable()&&!busy&&!invalidAges()&&dialog?.open)void save()},900)}
function item(){return draft?.offers.find(x=>x.id===selected)}
function edit(event){
  const input=event.target,key=input.dataset.tsField;if(!key||!editable())return;
  const offer=item();if(!offer)return;
  let value=input.type==='checkbox'?input.checked:input.value;
  if(['stars','nights','adults'].includes(key)){value=value===''?null:Number(value);if(value!==null&&!Number.isInteger(value))return}
  if(key==='child_ages'){rawAges[offer.id]=String(value);if(!validAges(value)){changed();return}const raw=String(value).trim();value=raw?raw.split(',').map(x=>Number(x.trim())):[]}
  offer[key]=value;changed();renderList();
}
function mutate(action){
  if(!editable()||busy)return;const at=draft.offers.findIndex(x=>x.id===selected);if(at<0)return;
  if(action==='remove'){draft.offers.splice(at,1);selected=draft.offers[Math.min(at,draft.offers.length-1)]?.id||''}
  else if(action==='up'&&at>0)[draft.offers[at-1],draft.offers[at]]=[draft.offers[at],draft.offers[at-1]];
  else if(action==='down'&&at<draft.offers.length-1)[draft.offers[at+1],draft.offers[at]]=[draft.offers[at],draft.offers[at+1]];
  else return;changed();render();
}
function input(key,label,type='text',extra=''){
  const offer=item(),value=key==='child_ages'?(Object.hasOwn(rawAges,offer.id)?rawAges[offer.id]:(offer[key]||[]).join(', ')):offer[key]??'';
  return `<label for="ts-${key}">${esc(label)}<input id="ts-${key}" data-ts-field="${key}" type="${type}" value="${esc(value)}" ${extra}></label>`;
}
function textarea(key,label){return `<label class="tsWide" for="ts-${key}">${esc(label)}<textarea id="ts-${key}" data-ts-field="${key}" rows="2" maxlength="${['rationale','compromise','inclusions'].includes(key)?600:1200}">${esc(item()[key]||'')}</textarea></label>`}
function renderList(){
  const list=$('tsList');if(!list||!draft)return;
  $('tsCount').textContent='Варианты · '+draft.offers.length;
  list.innerHTML=draft.offers.length?draft.offers.map((x,i)=>`<button type="button" data-ts-select="${esc(x.id)}" class="tsOption ${x.id===selected?'isSelected':''}" aria-pressed="${x.id===selected}"><span class="tsOptionNumber">${i+1}</span><span><strong>${esc(x.hotel||'Новый вариант')}</strong><small>${esc(x.amount?x.amount+' '+x.currency+' за всех':'Цена не указана')}</small><small>${x.selected?'Включён в подборку':'Исключён из подборки'}</small></span></button>`).join(''):'<p class="tsEmptyList">Добавьте первое предложение. Можно сохранить незаполненный вариант и вернуться к нему позже.</p>';
}
function render(){
  if(!draft||!dialog)return;
  if(!item())selected=draft.offers[0]?.id||'';renderList();const editor=$('tsEditor'),offer=item();
  if(!offer){editor.innerHTML='<div class="tsEmpty"><span aria-hidden="true">＋</span><h3>Соберите подборку для клиента</h3><p>Добавьте найденный тур, укажите условия и общую цену. Варианты сохранятся здесь отдельно от переписки.</p><button type="button" id="tsEmptyAdd" class="tsPrimary">Добавить предложение</button></div>';$('tsEmptyAdd').onclick=add;controls();return}
  editor.innerHTML=`<div class="tsEditorHead"><h3>Условия предложения</h3><div class="tsOrder"><button type="button" data-ts-action="up" aria-label="Поднять предложение">↑</button><button type="button" data-ts-action="down" aria-label="Опустить предложение">↓</button><button type="button" data-ts-action="remove">Удалить</button></div></div><label class="tsInclude"><input data-ts-field="selected" type="checkbox" ${offer.selected?'checked':''}> Включить в подборку</label><div class="tsFields">${input('hotel','Название отеля','text','maxlength="191"')}${input('stars','Звёздность','number','min="0" max="5"')}${input('destination','Страна и курорт','text','maxlength="160"')}${input('departure','Вылет из','text','maxlength="160"')}${input('date_from','Дата вылета','date')}${input('nights','Ночей','number','min="1" max="60"')}${input('adults','Взрослых','number','min="1" max="20"')}${input('child_ages','Возраст детей через запятую','text','inputmode="numeric" placeholder="Например, 4, 10" maxlength="60"')}${input('room','Номер и размещение','text','maxlength="300"')}${input('meal','Питание','text','maxlength="160"')}${input('amount','Цена за всех','text','inputmode="decimal" placeholder="Например, 240000" maxlength="12"')}<label for="ts-currency">Валюта<select id="ts-currency" data-ts-field="currency">${['RUB','EUR','USD'].map(v=>`<option ${offer.currency===v?'selected':''}>${v}</option>`).join('')}</select></label>${textarea('rationale','Почему рекомендуем')}${textarea('compromise','Что важно учесть')}</div><details class="tsDetails"><summary>Источник и дополнительные условия</summary><div class="tsFields">${input('source_label','Туроператор или источник','text','maxlength="160"')}${input('source_url','Ссылка на предложение','url','maxlength="2048" placeholder="https://…"')}${textarea('flights','Перелёт и багаж')}${textarea('inclusions','Что включено')}${textarea('constraints','Непроверенные условия и доплаты')}</div></details><p class="tsPriceHint">Сохранение черновика не подтверждает актуальность цены. Перед отправкой условия потребуется проверить.</p>`;
  controls();recovery(conflict?'conflict':pending?'pending':'');
}
function controls(){
  if(!dialog)return;const disabled=!editable();
  $('tsAdd').disabled=disabled||busy||(draft?.offers.length||0)>=20;$('tsSave').disabled=disabled||busy||!dirty||invalidAges();$('tsDone').disabled=busy||loading||invalidAges();
  $('tsSaved').textContent=busy?'Сохраняем…':pending?'Результат сохранения уточняется':conflict?'Есть конфликт правок':dirty?'Не все изменения сохранены':version?'Сохранено на сервере':'Новый черновик';
  $('tsEditor')?.querySelectorAll('input,textarea,select,[data-ts-action],#tsEmptyAdd').forEach(x=>{x.disabled=disabled||busy});
  if(item()){const at=draft.offers.findIndex(x=>x.id===selected);const up=dialog.querySelector('[data-ts-action="up"]'),down=dialog.querySelector('[data-ts-action="down"]');if(up)up.disabled=disabled||busy||at===0;if(down)down.disabled=disabled||busy||at===draft.offers.length-1}
}
function recovery(kind){
  const root=$('tsRecovery');if(!root)return;root.hidden=!kind;root.replaceChildren();if(!kind)return;
  const text=document.createElement('p');text.textContent=kind==='conflict'?'На сервере есть другая версия. Ваши правки сохранены; выберите, с какой версией продолжить.':kind==='pending'?'Сервер мог сохранить изменения. Сначала проверьте результат, чтобы не перезаписать чужую правку.':'Не удалось загрузить подборку. Ваши локальные правки сохранены.';root.appendChild(text);
  const button=(label,fn)=>{const el=document.createElement('button');el.type='button';el.textContent=label;el.onclick=fn;root.appendChild(el)};
  if(kind==='conflict'){
    button('Показать версию коллеги',()=>{if(!conflict||busy)return;draft=clone(conflict.draft);version=conflict.version;canEdit=conflict.can_edit;dirty=false;conflict=null;stash();render();recovery('');status('Загружена версия с сервера.');});
    button('Продолжить с моими правками',()=>{if(!conflict||busy)return;version=conflict.version;canEdit=conflict.can_edit;conflict=null;dirty=true;stash();render();recovery('');status('Ваш вариант в редакторе. Проверьте его и нажмите «Сохранить».');});
  }else if(kind==='pending')button('Проверить сохранение',()=>reconcile());
  else button('Повторить загрузку',()=>load());
}
async function save(){
  clearTimeout(timer);timer=0;if(!editable()||busy||!dirty||invalidAges())return;
  const command={version,draft:clone(draft),operation_id:id()};pending=command;return submit(command);
}
async function submit(command){
  const token=epoch;busy=true;stash();controls();
  const result=await request('save',command);if(token!==epoch)return;busy=false;
  if(result?.ok&&validSnapshot(result.selection)){
    version=result.selection.version;canEdit=result.selection.can_edit;draft=clone(result.selection.draft);rawAges={};dirty=false;pending=null;conflict=null;stash();render();status('Черновик сохранён.');return;
  }
  if(result?.error==='conflict'&&validSnapshot(result.selection)){pending=null;conflict=result.selection;canEdit=conflict.can_edit;stash();controls();recovery('conflict');status(error(result),true);return}
  if(result?.http_status&&result.http_status<500&&!['operation_mismatch','invalid_response'].includes(result.error)){pending=null;if([403,404].includes(result.http_status))canEdit=false;stash();controls();status(error(result),true);return}
  stash();controls();recovery('pending');status(error(result),true);
}
async function reconcile(){
  if(!pending||busy||!current())return;busy=true;controls();const token=epoch,result=await request('operation',{operation_id:pending.operation_id});if(token!==epoch)return;busy=false;
  if(result?.ok&&result.found&&validSnapshot(result.selection)){
    const sameVersion=result.saved_version===result.selection.version;pending=null;canEdit=result.selection.can_edit;
    if(sameVersion){draft=clone(result.selection.draft);version=result.selection.version;dirty=false;conflict=null}else{conflict=result.selection;dirty=true}
    stash();render();recovery(conflict?'conflict':'');status(sameVersion?'Сохранение подтверждено.':'Сохранение подтверждено, но затем подборку изменили. Сравните версии.',!sameVersion);return;
  }
  controls();recovery('pending');status(result?.ok?'Подтверждение ещё не найдено. Можно повторить тот же запрос с защитой от дублей.':error(result),true);
  if(result?.ok&&!result.found&&validSnapshot(result.selection)&&result.selection.can_edit){const button=document.createElement('button');button.type='button';button.textContent='Повторить это сохранение';button.onclick=()=>{if(pending&&!busy&&current())void submit(pending)};$('tsRecovery').appendChild(button)}
}
window.WorkspaceV2TourSelection={sync,activate,suspend,open,close};
})();
