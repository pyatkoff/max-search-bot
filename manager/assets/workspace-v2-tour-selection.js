(function(){
'use strict';
const W=window.WorkspaceV2,{S,$,esc}=W,endpoint='tour-selections-api.php';
let dialog=null,context=null,epoch=0,version=0,draft=null,selected='',canEdit=false,dirty=false,busy=false,loading=false,pending=null,conflict=null,timer=0,returnFocus=null,rawAges={};
const sessions=new Map();
let publication=null,preview=false,sending=false,photoFailures=0,photoPending=0,photoEpoch=0,sharing=false;
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
function error(result){return {forbidden:'Отправить подборку может менеджер, который ведёт диалог.',not_found:'Доступ к диалогу изменился.',invalid_draft:'Проверьте даты, сумму, возраст детей и ссылки.',conflict:'Подборку изменил другой сотрудник. Ваши правки сохранены отдельно.',storage_unavailable:'Сервер пока не подтвердил действие.',network_error:'Нет связи с сервером. Проверьте результат в истории отправки.',operation_mismatch:'Не удалось сопоставить сохранение. Требуется проверка.',draft_changed:'Подборка изменилась. Загрузите актуальный черновик.',empty_selection:'Включите хотя бы один вариант в подборку.',unsupported_channel:'Подборки доступны в личных Telegram и MAX диалогах этого проекта.',preparation_failed:'Не удалось подготовить фото или подтвердить получателя. Карточка не отправлена.',delivery_rejected:'Канал отклонил этот вариант. Можно повторить отправку; доставленные карточки не повторятся.',delivery_unknown:'Результат отправки неизвестен. Проверьте переписку; повторная отправка заблокирована.',delivery_unverified:'Карточка создана. Нажмите «Проверить доставку».',delivery_in_progress:'Отправка уже начата в другой вкладке.'}[result?.error]||'Не удалось подтвердить действие. Ваши правки сохранены.'}
async function request(action,data={}){
  const target=context,token=epoch;if(!current(target))return {ok:false,error:'stale_context'};
  const controller=new AbortController(),timeout=setTimeout(()=>controller.abort(),action==='send'?120000:20000);
  try{
    const result=await W.request(endpoint,action,{conversation_id:target.conversation,...data},{signal:controller.signal,isCurrent:()=>token===epoch&&current(target)});
    return token===epoch&&current(target)?result:{ok:false,error:'stale_context'};
  }catch(e){return {ok:false,error:current(target)?'network_error':'stale_context'}}finally{clearTimeout(timeout)}
}
function close(){clearTimeout(timer);timer=0;stash();if(dialog?.open)dialog.close()}
function suspend(){stash();clearTimeout(timer);timer=0;epoch++;context=null;draft=null;pending=null;conflict=null;dirty=false;busy=false;loading=false;canEdit=false;publication=null;preview=false;sending=false;sharing=false;if(dialog?.open)dialog.close();$('tourSelectionOpen')?.remove()}
function activate(){if(context&&!current())suspend()}
function sync(c){
  if(context&&!current())suspend();
  // Keep the narrow client-name row available to the existing client-card action.
  // The stable conversation menu survives lifecycle action redraws.
  const mobile=window.matchMedia('(max-width:740px)').matches;
  const root=mobile?document.querySelector('.m2ConversationMenu>div'):document.querySelector('.conversationTitleRow');if(!root)return;
  let button=$('tourSelectionOpen');
  if(!actor()||Number(c?.id)!==Number(S.current)){button?.remove();return}
  if(!button){button=document.createElement('button');button.id='tourSelectionOpen';button.type='button';button.className='tsOpen';button.textContent='Подборка';button.setAttribute('aria-haspopup','dialog');button.setAttribute('aria-controls','tourSelectionDialog');root.appendChild(button)}
  if(button.parentElement!==root)root.appendChild(button);
  button.onclick=()=>{const menu=button.closest('details');if(menu)menu.open=false;void open(c)};
  if(context&&current()&&(c.status==='closed'||!(S.manager?.role==='admin'||Number(c.manager_id)===actor()))&&canEdit){canEdit=false;clearTimeout(timer);controls();status('Доступ к редактированию изменился. Ваши правки сохранены отдельно.',true)}
}
function ensure(){
  if(dialog)return;
  dialog=document.createElement('dialog');dialog.id='tourSelectionDialog';dialog.className='tsDialog';dialog.setAttribute('aria-labelledby','tsTitle');
  dialog.innerHTML=`<div class="tsShell"><header class="tsHead"><div><span class="tsKicker">Подборка туров</span><h2 id="tsTitle"></h2><p id="tsRecipient"></p></div><button id="tsClose" type="button" class="tsClose" aria-label="Закрыть подборку">×</button></header><div id="tsStatus" class="tsStatus" role="status" aria-live="polite"></div><div id="tsRecovery" class="tsRecovery" hidden></div><div class="tsLayout"><aside class="tsSidebar"><div class="tsListHead"><strong id="tsCount">Варианты</strong><button id="tsAdd" type="button">+ Добавить</button></div><div id="tsList" class="tsList"></div><div class="tsSidebarHint">Черновик сохраняется для этого клиента. Добавление варианта ничего не отправляет.</div></aside><main id="tsEditor" class="tsEditor"></main></div><footer class="tsFooter"><span id="tsSaved">Загружаем черновик…</span><button id="tsSave" type="button" class="tsPrimary">Сохранить</button><button id="tsDone" type="button">Готово</button></footer></div>`;
  document.body.appendChild(dialog);
  const footer=dialog.querySelector('.tsFooter');
  for(const [name,label,handler] of [['tsHistory','История отправки',showHistory],['tsPreview','Предпросмотр',showPreview],['tsEdit','К редактированию',()=>{preview=false;render()}],['tsShare','Общая ссылка',showShare],['tsSend','Отправить подборку',sendAll]]){const button=document.createElement('button');button.id=name;button.type='button';button.textContent=label;button.onclick=handler;footer.insertBefore(button,$('tsDone'))}
  $('tsClose').onclick=close;$('tsDone').onclick=()=>{if(preview)close();else if(dirty&&!busy&&!pending&&!conflict)void save();else close()};$('tsSave').onclick=()=>save();$('tsAdd').onclick=add;
  dialog.addEventListener('cancel',e=>{e.preventDefault();close()});dialog.addEventListener('keydown',e=>e.stopPropagation());
  dialog.addEventListener('close',()=>{if(current()&&returnFocus?.isConnected)returnFocus.focus({preventScroll:true})});
  $('tsList').addEventListener('click',event=>{const button=event.target.closest('[data-ts-select]');if(button){selected=button.dataset.tsSelect;render()}});
  $('tsEditor').addEventListener('input',edit);$('tsEditor').addEventListener('change',edit);
  $('tsEditor').addEventListener('click',event=>{const action=event.target.closest('[data-ts-action]')?.dataset.tsAction;if(action)mutate(action)});
}
async function open(c){
  if(!actor()||Number(c.id)!==Number(S.current))return;
  close();epoch++;context=capture(c);version=0;draft=empty();selected='';dirty=false;busy=false;pending=null;conflict=null;rawAges={};canEdit=false;publication=null;preview=false;sending=false;sharing=false;restore();ensure();returnFocus=$('tourSelectionOpen')?.closest('details')?.querySelector('summary')||$('tourSelectionOpen');
  $('tsTitle').textContent=c.display_name||'Турист';$('tsRecipient').textContent=(c.channel==='telegram'?'Telegram':c.channel==='max'?'MAX':String(c.channel||''))+' · Сначала предпросмотр, затем отправка';
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
function editable(){return current()&&canEdit&&!loading&&!pending&&!conflict&&!preview&&!sending}
function invalidAges(){return draft?.offers.some(x=>Object.hasOwn(rawAges,x.id)&&!validAges(rawAges[x.id]))}
function validAges(value){const raw=String(value).trim();return raw===''||/^\d{1,2}(?:\s*,\s*\d{1,2})*$/.test(raw)&&raw.split(',').length<=12&&raw.split(',').every(x=>Number(x)>=0&&Number(x)<=17)}
function changed(){dirty=true;stash();status(invalidAges()?'Возраст детей укажите числами от 0 до 17 через запятую.':'Есть несохранённые изменения.',invalidAges());controls();clearTimeout(timer);timer=setTimeout(()=>{if(editable()&&!busy&&!invalidAges()&&dialog?.open)void save()},900)}
function item(){return draft?.offers.find(x=>x.id===selected)}
function edit(event){
  if(event.target.id==='ts-hotel-group'&&editable()){
    const offer=item(),value=event.target.value;if(!offer)return;
    if(value){
      const other=draft.offers.find(x=>x.id!==offer.id&&(value==='g:'+x.hotel_group||value==='o:'+x.id));if(!other)return;
      const group=other.hotel_group||id();offer.hotel_group=group;other.hotel_group=group;
    }else delete offer.hotel_group;
    changed();renderList();renderGrouping();$('ts-hotel-group')?.focus({preventScroll:true});return;
  }
  if(event.target.dataset.tsPhoto&&editable()){
    const offer=item(),kind=event.target.dataset.tsPhoto;if(!offer)return;const photos=new Map(offer.photos.map(x=>[x.kind,x]));
    if(event.target.value.trim())photos.set(kind,{kind,url:event.target.value.trim(),illustrative:true});else photos.delete(kind);
    offer.photos=['cover','beach','room'].filter(x=>photos.has(x)).map(x=>photos.get(x));changed();return;
  }
  const input=event.target,key=input.dataset.tsField;if(!key||!editable())return;
  const offer=item();if(!offer)return;
  let value=input.type==='checkbox'?input.checked:input.value;
  if(['stars','nights','adults'].includes(key)){value=value===''?null:Number(value);if(value!==null&&!Number.isInteger(value))return}
  if(key==='child_ages'){rawAges[offer.id]=String(value);if(!validAges(value)){changed();return}const raw=String(value).trim();value=raw?raw.split(',').map(x=>Number(x.trim())):[]}
  if(key==='hotel'&&offer.hotel!==value){delete offer.hotel_group;}
  offer[key]=value;changed();renderList();if(key==='hotel')renderGrouping();
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
function hotelGroups(){
  const groups=new Map();for(const [index,offer] of draft.offers.entries()){
    const key=offer.hotel_group?'group:'+offer.hotel_group:'offer:'+offer.id;if(!groups.has(key))groups.set(key,[]);groups.get(key).push({offer,index});
  }return groups;
}
function renderGrouping(){
  const root=$('tsGrouping'),offer=item();if(!root||!offer)return;
  const options=new Map();for(const [index,other] of draft.offers.entries()){
    if(other.id!==offer.id)options.set(other.hotel_group?'g:'+other.hotel_group:'o:'+other.id,'вариант '+(index+1)+' · '+(other.hotel||'Без названия'));
  }
  if(offer.hotel_group&&!options.has('g:'+offer.hotel_group))options.set('g:'+offer.hotel_group,offer.hotel||'Текущий отель');
  root.innerHTML='<label for="ts-hotel-group">Варианты одного отеля<select id="ts-hotel-group"><option value="">Отдельный отель</option>'+[...options].map(([key,name])=>`<option value="${esc(key)}" ${'g:'+offer.hotel_group===key?'selected':''}>Тот же отель: ${esc(name)}</option>`).join('')+'</select></label><p>Объединяйте только один и тот же отель. На общей странице фото будут общими, условия и цены — отдельными. Изменение названия снимает объединение этого варианта.</p>';
  $('ts-hotel-group').disabled=!editable()||busy;
}
function renderList(){
  const list=$('tsList');if(!list||!draft)return;
  $('tsCount').textContent='Варианты · '+draft.offers.length;
  list.innerHTML=draft.offers.length?[...hotelGroups().values()].map(members=>
    members.map(({offer:x,index:i})=>`<button type="button" data-ts-select="${esc(x.id)}" class="tsOption ${x.id===selected?'isSelected':''}" aria-pressed="${x.id===selected}"><span class="tsOptionNumber">${i+1}</span><span>${members.length>1&&i===members[0].index?`<span class="tsGroupLabel">Один отель · вариантов ${members.length}</span>`:''}<strong>${esc(x.hotel||'Новый вариант')}</strong><small>${esc([x.date_from,x.room].filter(Boolean).join(' · '))}</small><small>${esc(x.amount?x.amount+' '+x.currency+' за всех':'Цена не указана')}</small><small>${x.selected?'Включён в подборку':'Исключён из подборки'}</small></span></button>`).join('')).join(''):'<p class="tsEmptyList">Добавьте первое предложение. Можно сохранить незаполненный вариант и вернуться к нему позже.</p>';
}
function render(){
  if(!draft||!dialog)return;
  if(preview&&publication){renderPreview();controls();return}
  dialog.classList.remove('tsPreviewMode');
  if(!item())selected=draft.offers[0]?.id||'';renderList();const editor=$('tsEditor'),offer=item();
  if(!offer){editor.innerHTML='<div class="tsEmpty"><span aria-hidden="true">＋</span><h3>Соберите подборку для клиента</h3><p>Добавьте найденный тур, укажите условия и общую цену. Варианты сохранятся здесь отдельно от переписки.</p><button type="button" id="tsEmptyAdd" class="tsPrimary">Добавить предложение</button></div>';$('tsEmptyAdd').onclick=add;controls();return}
  editor.innerHTML=`<div class="tsEditorHead"><h3>Условия предложения</h3><div class="tsOrder"><button type="button" data-ts-action="up" aria-label="Поднять предложение">↑</button><button type="button" data-ts-action="down" aria-label="Опустить предложение">↓</button><button type="button" data-ts-action="remove">Удалить</button></div></div><label class="tsInclude"><input data-ts-field="selected" type="checkbox" ${offer.selected?'checked':''}> Включить в подборку</label><div class="tsFields">${input('hotel','Название отеля','text','maxlength="191"')}${input('stars','Звёздность','number','min="0" max="5"')}${input('destination','Страна и курорт','text','maxlength="160"')}${input('departure','Вылет из','text','maxlength="160"')}${input('date_from','Дата вылета','date')}${input('nights','Ночей','number','min="1" max="60"')}${input('adults','Взрослых','number','min="1" max="20"')}${input('child_ages','Возраст детей через запятую','text','inputmode="numeric" placeholder="Например, 4, 10" maxlength="60"')}${input('room','Номер и размещение','text','maxlength="300"')}${input('meal','Питание','text','maxlength="160"')}${input('amount','Цена за всех','text','inputmode="decimal" placeholder="Например, 240000" maxlength="12"')}<label for="ts-currency">Валюта<select id="ts-currency" data-ts-field="currency">${['RUB','EUR','USD'].map(v=>`<option ${offer.currency===v?'selected':''}>${v}</option>`).join('')}</select></label>${textarea('rationale','Почему рекомендуем')}${textarea('compromise','Что важно учесть')}</div><details class="tsDetails"><summary>Источник и дополнительные условия</summary><div class="tsFields">${input('source_label','Туроператор или источник','text','maxlength="160"')}${input('source_url','Ссылка на предложение','url','maxlength="2048" placeholder="https://…"')}${textarea('flights','Перелёт и багаж')}${textarea('inclusions','Что включено')}${textarea('constraints','Непроверенные условия и доплаты')}</div></details><p class="tsPriceHint">Сохранение черновика не подтверждает актуальность цены. Перед отправкой условия потребуется проверить.</p>`;
  const grouping=document.createElement('section');grouping.id='tsGrouping';grouping.className='tsGrouping';editor.insertBefore(grouping,editor.querySelector('.tsDetails'));renderGrouping();
  controls();recovery(conflict?'conflict':pending?'pending':'');
  const photoSection=document.createElement('section');photoSection.className='tsPhotoFields';
  photoSection.innerHTML='<h3>Три фото для карточки</h3><p>Прямые HTTPS-ссылки на фото отеля, пляжа и номера. Фото номера будет отмечено как пример интерьера.</p><div class="tsFields">'+[['cover','Отель'],['beach','Пляж или бассейн'],['room','Номер']].map(([kind,label])=>`<label class="tsWide" for="ts-photo-${kind}">${esc(label)}<input id="ts-photo-${kind}" type="url" data-ts-photo="${kind}" maxlength="2048" placeholder="https://…/photo.jpg" value="${esc(offer.photos.find(x=>x.kind===kind)?.url||'')}" ${!editable()||busy?'disabled':''}></label>`).join('')+'</div>';
  editor.insertBefore(photoSection,editor.querySelector('.tsPriceHint'));
}
function controls(){
  if(!dialog)return;const disabled=!editable();
  $('tsAdd').disabled=disabled||busy||(draft?.offers.length||0)>=20;$('tsSave').disabled=disabled||busy||!dirty||invalidAges();$('tsDone').disabled=busy||loading||(!preview&&invalidAges());
  $('tsSaved').textContent=busy?'Сохраняем…':pending?'Результат сохранения уточняется':conflict?'Есть конфликт правок':dirty?'Не все изменения сохранены':version?'Сохранено на сервере':'Новый черновик';
  $('tsEditor')?.querySelectorAll('input,textarea,select,[data-ts-action],#tsEmptyAdd').forEach(x=>{x.disabled=disabled||busy});
  $('tsSave').hidden=preview;$('tsPreview').hidden=preview;$('tsEdit').hidden=!preview;$('tsSend').hidden=!preview;$('tsHistory').hidden=preview;
  $('tsPreview').disabled=busy||loading||sending||!canEdit||!!pending||!!conflict||invalidAges();$('tsHistory').disabled=busy||loading||sending;$('tsEdit').disabled=sending;
  $('tsShare').hidden=!preview;$('tsShare').disabled=busy||loading||sending||sharing;
  const publish=$('tsPublish');if(publish)publish.disabled=!canEdit||busy||sending||sharing||dirty||!!pending||!!conflict||photoPending>0||photoFailures>0;
  if(sharing){$('tsEdit').disabled=true;$('tsDone').disabled=true}else $('tsDone').disabled=false;
  const cards=publication?.cards||[],unknown=cards.some(x=>['reserved','unknown'].includes(x.state)),known=cards.some(x=>x.state==='known'),remaining=cards.filter(x=>x.state!=='delivered').length;
  $('tsSend').disabled=sending||sharing||!publication?.can_send||unknown||!remaining||photoFailures>0||photoPending>0;
  $('tsSend').textContent=sending?'Отправляем…':unknown?'Результат неизвестен':photoPending?'Загружаем фото…':known?'Проверить доставку':remaining?'Отправить варианты ('+remaining+')':'Подборка отправлена';
  if(item()){const at=draft.offers.findIndex(x=>x.id===selected);const up=dialog.querySelector('[data-ts-action="up"]'),down=dialog.querySelector('[data-ts-action="down"]');if(up)up.disabled=disabled||busy||at===0;if(down)down.disabled=disabled||busy||at===draft.offers.length-1}
}
function recovery(kind){
  const root=$('tsRecovery');if(!root)return;root.hidden=!kind;root.replaceChildren();if(!kind)return;
  const text=document.createElement('p');text.textContent=kind==='conflict'?'На сервере есть другая версия. Ваши правки сохранены; выберите, с какой версией продолжить.':kind==='pending'?'Сервер мог сохранить изменения. Сначала проверьте результат, чтобы не перезаписать чужую правку.':'Не удалось загрузить подборку. Ваши локальные правки сохранены.';root.appendChild(text);
  const button=(label,fn)=>{const el=document.createElement('button');el.type='button';el.textContent=label;el.onclick=fn;root.appendChild(el)};
  if(kind==='conflict'){
    button('Показать версию коллеги',()=>{if(!conflict||busy)return;draft=clone(conflict.draft);version=conflict.version;canEdit=conflict.can_edit;rawAges={};dirty=false;conflict=null;stash();render();recovery('');status('Загружена версия с сервера.');});
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
    // An identical retry can find its old receipt after a colleague has saved
    // a newer draft. Confirming our command must not silently adopt their terms.
    if(result.saved_version!==result.selection.version){pending=null;canEdit=result.selection.can_edit;conflict=result.selection;dirty=true;stash();render();status('Сохранение подтверждено, но затем подборку изменили. Сравните версии.',true);return;}
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
    if(sameVersion){draft=clone(result.selection.draft);version=result.selection.version;rawAges={};dirty=false;conflict=null}else{conflict=result.selection;dirty=true}
    stash();render();recovery(conflict?'conflict':'');status(sameVersion?'Сохранение подтверждено.':'Сохранение подтверждено, но затем подборку изменили. Сравните версии.',!sameVersion);return;
  }
  controls();recovery('pending');status(result?.ok?'Подтверждение ещё не найдено. Можно повторить тот же запрос с защитой от дублей.':error(result),true);
  if(result?.ok&&!result.found&&validSnapshot(result.selection)&&result.selection.can_edit){const button=document.createElement('button');button.type='button';button.textContent='Повторить это сохранение';button.onclick=()=>{if(pending&&!busy&&current())void submit(pending)};$('tsRecovery').appendChild(button)}
}
function renderPreview(){
  dialog.classList.add('tsPreviewMode');photoFailures=0;photoPending=publication.cards.length*3;const imageRun=++photoEpoch,token=epoch,publicationId=publication.id;
  $('tsEditor').innerHTML='<div class="tsPreviewHeading"><h3>Предпросмотр подборки</h3><p>'+esc(publication.channel==='telegram'?'Telegram — карусель из трёх фото.':'MAX — мозаика из трёх фото.')+' Проверьте фото, условия и общую цену. «Нравится» сохранит именно показанный вариант.</p></div><div class="tsPreviewCards">'+publication.cards.map(card=>`<article class="tsPreviewCard" data-ts-card="${card.index}"><div class="tsPhotos">${card.photos.map((url,i)=>`<img src="${esc(url)}" alt="${['Отель','Пляж или бассейн','Пример интерьера номера'][i]}" referrerpolicy="no-referrer">`).join('')}</div><p class="tsCardText">${esc(card.text)}</p><span class="tsLikePreview">♡ Нравится</span><p class="tsDeliveryState" data-ts-state="${card.index}"></p></article>`).join('')+'</div>';
  const active=()=>imageRun===photoEpoch&&token===epoch&&preview&&publication?.id===publicationId&&current();
  $('tsEditor').querySelectorAll('img').forEach(img=>{
    img.addEventListener('load',()=>{if(active()){photoPending--;controls()}},{once:true});
    img.addEventListener('error',()=>{if(!active())return;photoPending--;photoFailures++;img.classList.add('tsPhotoFailed');controls();status('Не удалось загрузить фото. Вернитесь к редактированию и проверьте прямую ссылку.',true)},{once:true});
  });
  updateDelivery();
}
function updateDelivery(){
  const labels={pending:'Ещё не отправлен',rejected:'Канал отклонил отправку. Вариант не доставлен.',reserved:'Отправка началась. Результат уточняется.',unknown:'Результат неизвестен — повторная отправка заблокирована.',known:'Карточка создана. Требуется проверка доставки.',delivered:'✓ Отправлен'};
  for(const card of publication?.cards||[]){const el=dialog.querySelector('[data-ts-state="'+card.index+'"]');if(el){el.textContent=labels[card.state]||'Результат уточняется';el.classList.toggle('isDelivered',card.state==='delivered')}}
  controls();
}
async function showPreview(){
  if(!current()||busy||sending||pending||conflict||invalidAges())return;
  clearTimeout(timer);if(dirty)await save();if(dirty||busy||pending||conflict||!current())return;
  const token=epoch;busy=true;controls();status('Готовим предпросмотр…');const result=await request('prepare',{version});if(token!==epoch)return;busy=false;
  if(result?.ok&&result.publication){publication=result.publication;preview=true;render();status('Предпросмотр готов. Проверьте подборку перед отправкой.');return}
  const names={hotel:'отель',departure:'вылет',date_from:'дату',nights:'ночи',adults:'состав',room:'номер',meal:'питание',amount:'общую цену',photos:'три фото',conditions:'условия'};
  if(result?.error==='card_too_long'){status('Сократите пояснения и дополнительные условия в варианте '+result.position+'. Условия не будут обрезаны при отправке.',true);controls();return}
  status(result?.error==='incomplete_offers'?'Заполните '+(result.fields||[]).map(x=>'в варианте '+x.position+': '+x.fields.map(f=>names[f]||f).join(', ')).join('; ')+'.':error(result),true);controls();
}
async function showHistory(){
  if(!current()||busy||sending)return;const token=epoch;busy=true;controls();const result=await request('history');if(token!==epoch)return;busy=false;controls();
  if(!result?.ok){status(error(result),true);return}
  const root=$('tsRecovery');root.replaceChildren();root.hidden=false;
  if(!result.publications.length){root.textContent='Пока нет подготовленных к отправке подборок.';return}
  const heading=document.createElement('p');heading.textContent='Подготовленные подборки и результаты отправки:';root.appendChild(heading);
  for(const entry of result.publications){const button=document.createElement('button');button.type='button';button.textContent='Версия '+entry.draft_version+' · '+entry.created_at+' UTC';button.onclick=async()=>{const t=epoch;const read=await request('publication',{publication_id:entry.publication_id});if(t!==epoch)return;if(read?.ok&&read.publication){publication=read.publication;preview=true;render();recovery('');status('Показана сохранённая версия и результат отправки.')}else status(error(read),true)};root.appendChild(button)}
}
async function sendAll(){
  if(!current()||sending||!publication?.can_send||photoFailures||photoPending)return;const target=publication.id,token=epoch;sending=true;controls();
  try{
    for(const next of publication.cards.filter(x=>x.state!=='delivered')){
      if(token!==epoch||!current()||!dialog.open||publication.id!==target)break;
      if(['reserved','unknown'].includes(next.state)){status('Результат предыдущей отправки неизвестен. Повторная отправка заблокирована.',true);break}
      status('Отправляем вариант '+(next.index+1)+' из '+publication.cards.length+'…');
      const result=await request('send',{publication_id:target,card_index:next.index});if(token!==epoch)return;
      if(result?.publication){publication=result.publication;updateDelivery()}
      if(!result?.ok){status(error(result),true);const read=await request('publication',{publication_id:target});if(token!==epoch)return;if(read?.ok&&read.publication){publication=read.publication;updateDelivery()}break}
    }
    if(token===epoch&&publication.cards.every(x=>x.state==='delivered'))status('Подборка отправлена. Выбор туриста появится в карточке клиента.');
  }finally{if(token===epoch){sending=false;updateDelivery()}}
}
async function showShare(){
  if(!current()||!preview||!publication||busy||sending||sharing)return;
  const token=epoch,id=publication.id;sharing=true;controls();
  const result=await request('share');if(token!==epoch)return;sharing=false;controls();
  if(!current()||publication?.id!==id||!preview)return;
  if(!result?.ok||!validShare(result.share)){status('Не удалось прочитать состояние общей ссылки. Повторите проверку.',true);return}
  renderShare(result.share);
}
function validShare(value){return value&&Number.isInteger(value.version)&&value.version>=0&&typeof value.active==='boolean'&&(!value.active||typeof value.path==='string'&&/^\/selection\/\?t=[a-f0-9]{64}$/.test(value.path))}
function renderShare(share){
  const root=$('tsRecovery');root.replaceChildren();root.hidden=false;
  const heading=document.createElement('strong');heading.textContent='Общая страница подборки';root.appendChild(heading);
  const hint=document.createElement('p');hint.textContent='Публикуются показанные туры, фото и публичный комментарий. Проверьте, что заголовок и комментарий не содержат личных данных. Ссылку можно пересылать; публикация ничего не отправляет в мессенджер.';root.appendChild(hint);
  if(share.active){
    const url=new URL(share.path,location.origin).href,input=document.createElement('input');input.readOnly=true;input.value=url;input.setAttribute('aria-label','Общая ссылка на подборку');root.appendChild(input);
    const copy=document.createElement('button');copy.type='button';copy.textContent='Скопировать ссылку';copy.onclick=async()=>{if(!current())return;const token=epoch;input.select();try{await navigator.clipboard.writeText(url);if(token===epoch&&current())status('Ссылка скопирована.')}catch(e){if(token===epoch&&current())status('Выделена ссылка — скопируйте её.')}};root.appendChild(copy);
    const open=document.createElement('a');open.href=url;open.target='_blank';open.rel='noopener noreferrer';open.textContent='Открыть страницу';root.appendChild(open);
    const label=document.createElement('p');label.textContent=share.publication_id===publication.id?'По ссылке опубликована эта версия.':'По ссылке опубликована другая версия. Обновление заменит страницу, сохранив адрес.';root.appendChild(label);
  }else{const label=document.createElement('p');label.textContent=share.version?'Ссылка отозвана. Повторная публикация создаст новый адрес.':'Подборка ещё не опубликована.';root.appendChild(label)}
  const change=(label,action)=>{const b=document.createElement('button');b.id=action==='publish'?'tsPublish':'tsRevoke';b.type='button';b.textContent=label;b.disabled=(!canEdit&&!(action==='revoke'&&share.can_revoke))||sharing||(action==='publish'&&(dirty||!!pending||!!conflict||photoPending>0||photoFailures>0));b.onclick=()=>changeShare(action,share);root.appendChild(b)};
  if(!share.active||share.publication_id!==publication.id)change(share.active?'Обновить по той же ссылке':'Опубликовать эту версию','publish');
  if(share.active)change('Отозвать ссылку','revoke');
}
async function changeShare(action,share){
  if(!current()||sharing||(!canEdit&&!(action==='revoke'&&share.can_revoke))||sending)return;
  if(action==='publish'&&(dirty||pending||conflict||photoPending||photoFailures))return;
  const token=epoch;sharing=true;controls();$('tsRecovery').querySelectorAll('button').forEach(b=>b.disabled=true);
  const result=await request(action,{share_version:share.version,publication_id:action==='revoke'?share.publication_id:publication.id});if(token!==epoch)return;sharing=false;controls();
  if(result?.ok&&validShare(result.share)){renderShare(result.share);status(action==='revoke'?'Ссылка отозвана.':'Страница опубликована. Сообщения туристу не отправлялись.');return}
  // Read back after an uncertain write. Never retry a publish/revoke automatically.
  await showShare();if(token!==epoch)return;
  status(result?.error==='draft_changed'?'Черновик изменился. Сначала подготовьте новый предпросмотр.':result?.error==='share_changed'?'Состояние ссылки изменилось в другой вкладке. Показано актуальное состояние.':'Ответ на действие не подтверждён. Проверьте состояние ссылки перед новым действием.',true);
}
window.addEventListener('resize',()=>{if(S.detail?.conversation)sync(S.detail.conversation)});
window.WorkspaceV2TourSelection={sync,activate,suspend,open,close};
})();
