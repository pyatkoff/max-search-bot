(function(){
'use strict';
const contract='tour-selection-editor-host-v1';
let mounted=null;
function mount(host){
if(!host||host.contract!==contract||!['capture','isCurrent','canOpen','canEdit','launcher','request','formatTime','openCollector','takeCollectorIntent','activate'].every(k=>typeof host[k]==='function')||typeof host.origin!=='string')throw new TypeError('Invalid selection editor host');
if(mounted)throw new Error('Selection editor already mounted');
const $=id=>document.getElementById(id),esc=value=>{const node=document.createElement('div');node.textContent=value??'';return node.innerHTML};
const endpoint='tour-selections-api.php';
let dialog=null,context=null,epoch=0,version=0,draft=null,selected='',canEdit=false,dirty=false,busy=false,loading=false,pending=null,conflict=null,timer=0,returnFocus=null,rawAges={};
const sessions=new Map();
let comparison=null,tripPanel=null,previewIssues=[],photoUpload=null;
let publication=null,preview=false,sending=false,photoFailures=0,photoPending=0,photoEpoch=0,sharing=false,interestLoading=false;
function capture(c){return host.capture(c)}
function current(target=context){return host.isCurrent(target)}
function key(c=context){return c?.actor&&c?.conversation?'workspaceV2.tourSelection.'+c.actor+'.'+c.conversation:''}
function clone(value){return JSON.parse(JSON.stringify(value))}
function id(){const data=new Uint8Array(16);crypto.getRandomValues(data);return Array.from(data,n=>n.toString(16).padStart(2,'0')).join('')}
function empty(){return {title:'Подборка туров',intro:'',offers:[]}}
function stash(){
  if(!context||!draft)return;
  const data={draft:clone(draft),version,selected,dirty,rawAges:clone(rawAges),pending:pending?clone(pending):null,conflict:conflict?clone(conflict):null,comparison:comparison?clone(comparison):null,at:Date.now()};
  sessions.set(key(),data);
  try{if(dirty||pending||conflict)sessionStorage.setItem(key(),JSON.stringify(data));else sessionStorage.removeItem(key())}catch(e){}
}
function restore(){
  let data=sessions.get(key());
  try{if(!data)data=JSON.parse(sessionStorage.getItem(key())||'null')}catch(e){}
  const bounded=value=>value&&Array.isArray(value.offers)&&value.offers.length<=20&&JSON.stringify(value).length<=300000;
  // A conflict envelope can contain displayed, server and original local terms.
  if(!data||Date.now()-Number(data.at||0)>86400000||!bounded(data.draft)||JSON.stringify(data).length>900000||data.conflict&&!bounded(data.conflict.draft)||data.comparison&&(!data.conflict||!bounded(data.comparison.draft)))return false;
  draft=clone(data.draft);version=Number(data.version||0);selected=String(data.selected||'');dirty=!!data.dirty;rawAges=data.rawAges||{};pending=data.pending||null;conflict=data.conflict||null;comparison=data.comparison||null;return true;
}
function status(text,error=false){const el=$('tsStatus');if(el){el.textContent=text;el.classList.toggle('tsError',error)}}
function error(result){return {demo_only:'Демонстрация: карточки проверяются сервером в рабочем редакторе. Здесь ничего не отправляется.',forbidden:'Отправить подборку может менеджер, который ведёт диалог.',not_found:'Доступ к диалогу изменился.',invalid_draft:'Проверьте даты, сумму, возраст детей и ссылки.',conflict:'Подборку изменил другой сотрудник. Ваши правки сохранены отдельно.',storage_unavailable:'Сервер пока не подтвердил действие.',network_error:'Нет связи с сервером. Проверьте результат в истории отправки.',operation_mismatch:'Не удалось сопоставить сохранение. Требуется проверка.',draft_changed:'Подборка изменилась. Загрузите актуальный черновик.',empty_selection:'Включите хотя бы один вариант в подборку.',unsupported_channel:'Подборки доступны в личных Telegram и MAX диалогах этого проекта.',preparation_failed:'Не удалось подготовить фото или подтвердить получателя. Карточка не отправлена.',delivery_rejected:'Канал отклонил этот вариант. Можно повторить отправку; доставленные карточки не повторятся.',delivery_unknown:'Результат отправки неизвестен. Проверьте переписку; повторная отправка заблокирована.',delivery_unverified:'Карточка создана. Нажмите «Проверить доставку».',delivery_in_progress:'Отправка уже начата в другой вкладке.'}[result?.error]||'Не удалось подтвердить действие. Ваши правки сохранены.'}
async function request(action,data={}){
  const target=context,token=epoch;if(!current(target))return {ok:false,error:'stale_context'};
  const controller=new AbortController(),timeout=setTimeout(()=>controller.abort(),action==='send'?120000:20000);
  try{
    const result=await host.request(endpoint,action,{conversation_id:target.conversation,...data},{signal:controller.signal,isCurrent:()=>token===epoch&&current(target)});
    return token===epoch&&current(target)?result:{ok:false,error:'stale_context'};
  }catch(e){return {ok:false,error:current(target)?'network_error':'stale_context'}}finally{clearTimeout(timeout)}
}
function close(){cancelPhotoUpload(false);tripPanel=null;clearTimeout(timer);timer=0;stash();if(dialog?.open)dialog.close()}
function suspend(){cancelPhotoUpload(false);tripPanel=null;previewIssues=[];stash();clearTimeout(timer);timer=0;epoch++;context=null;draft=null;pending=null;conflict=null;comparison=null;dirty=false;busy=false;loading=false;canEdit=false;publication=null;preview=false;sending=false;sharing=false;if(dialog?.open)dialog.close();$('tourSelectionOpen')?.remove()}
function activate(){if(context&&!current())suspend();host.activate()}
function sync(c){
  if(context&&!current())suspend();
  const root=host.launcher();if(!root)return;
  let button=$('tourSelectionOpen');
  if(!host.canOpen(c)){button?.remove();return}
  const target=capture(c);if(host.takeCollectorIntent(target))queueMicrotask(()=>{if(current(target))void open(c)});
  if(!button){button=document.createElement('button');button.id='tourSelectionOpen';button.type='button';button.className='tsOpen';button.textContent='Подборка';button.setAttribute('aria-haspopup','dialog');button.setAttribute('aria-controls','tourSelectionDialog');root.appendChild(button)}
  if(button.parentElement!==root)root.appendChild(button);
  button.onclick=()=>{const menu=button.closest('details');if(menu)menu.open=false;void open(c)};
  if(context&&current()&&!host.canEdit(c)&&canEdit){canEdit=false;clearTimeout(timer);controls();status('Доступ к редактированию изменился. Ваши правки сохранены отдельно.',true)}
}
function ensure(){
  if(dialog)return;
  dialog=document.createElement('dialog');dialog.id='tourSelectionDialog';dialog.className='tsDialog';dialog.setAttribute('aria-labelledby','tsTitle');
  dialog.innerHTML=`<div class="tsShell"><header class="tsHead"><div><span class="tsKicker">Подборка туров</span><h2 id="tsTitle"></h2><p id="tsRecipient"></p></div><button id="tsClose" type="button" class="tsClose" aria-label="Закрыть подборку">×</button></header><div id="tsStatus" class="tsStatus" role="status" aria-live="polite"></div><div id="tsRecovery" class="tsRecovery" hidden></div><div class="tsLayout"><aside class="tsSidebar"><div class="tsListHead"><strong id="tsCount">Варианты</strong><button id="tsAdd" type="button">+ Добавить</button></div><div id="tsList" class="tsList"></div><div class="tsSidebarHint">Черновик сохраняется для этого клиента. Добавление варианта ничего не отправляет.</div></aside><main id="tsEditor" class="tsEditor"></main></div><footer class="tsFooter"><span id="tsSaved">Загружаем черновик…</span><button id="tsSave" type="button" class="tsPrimary">Сохранить</button><button id="tsDone" type="button">Готово</button></footer></div>`;
  document.body.appendChild(dialog);
  const footer=dialog.querySelector('.tsFooter');
  const tripButton=document.createElement('button');tripButton.id='tsTripAdd';tripButton.type='button';tripButton.textContent='Добавить по условиям поездки';tripButton.onclick=showTrip;const actions=document.createElement('div');actions.className='tsImportActions';actions.append(tripButton);dialog.querySelector('.tsListHead').after(actions);
  const collector=document.createElement('button');collector.id='tsCollector';collector.type='button';collector.textContent='Сборщик туров';collector.onclick=()=>{if(current()&&canEdit)host.openCollector(context.conversation)};actions.append(collector);
  if(typeof host.openStandalone==='function'){const standalone=document.createElement('button');standalone.id='tsStandalone';standalone.type='button';standalone.textContent='Открыть отдельно';standalone.onclick=()=>{if(current())host.openStandalone(context.conversation)};actions.append(standalone)}
  for(const [name,label,handler] of [['tsHistory','История отправки',showHistory],['tsPreview','Предпросмотр',showPreview],['tsEdit','К редактированию',()=>{preview=false;render()}],['tsShare','Общая ссылка',showShare],['tsSend','Отправить подборку',sendAll]]){const button=document.createElement('button');button.id=name;button.type='button';button.textContent=label;button.onclick=handler;footer.insertBefore(button,$('tsDone'))}
  $('tsClose').onclick=close;$('tsDone').onclick=()=>{if(preview)close();else if(dirty&&!busy&&!pending&&!conflict)void save();else close()};$('tsSave').onclick=()=>save();$('tsAdd').onclick=add;
  dialog.addEventListener('cancel',e=>{e.preventDefault();close()});dialog.addEventListener('keydown',e=>e.stopPropagation());
  dialog.addEventListener('close',()=>{if(current()&&returnFocus?.isConnected)returnFocus.focus({preventScroll:true})});
  $('tsList').addEventListener('click',event=>{const button=event.target.closest('[data-ts-select]');if(button){selected=button.dataset.tsSelect;render()}});
  $('tsEditor').addEventListener('input',edit);$('tsEditor').addEventListener('change',edit);
  $('tsEditor').addEventListener('click',event=>{
    if(event.target.closest('#tsUploadRetry')){void submitPhotoUpload();return}
    if(event.target.closest('#tsUploadCancel')){cancelPhotoUpload();return}
    const remove=event.target.closest('[data-ts-remove-photo]');if(remove&&editable()&&!busy){item().photos=item().photos.filter(x=>x.kind!==remove.dataset.tsRemovePhoto);changed();render();status('Фото убрано из варианта. История отправки сохранена.');return}
    if(event.target.closest('[data-ts-copy-photos]')){copyHotelPhotos();return}
    const fix=event.target.closest('[data-ts-fix-offer]');if(fix){fixPreviewIssue(fix.dataset.tsFixOffer,fix.dataset.tsFixField);return}
    const action=event.target.closest('[data-ts-action]')?.dataset.tsAction;if(action)mutate(action);
  });
}
async function open(c){
  if(!host.canOpen(c))return;
  close();tripPanel=null;previewIssues=[];epoch++;context=capture(c);version=0;draft=empty();selected='';dirty=false;busy=false;pending=null;conflict=null;comparison=null;rawAges={};canEdit=false;publication=null;preview=false;sending=false;sharing=false;interestLoading=false;restore();ensure();returnFocus=$('tourSelectionOpen')?.closest('details')?.querySelector('summary')||$('tourSelectionOpen');
  $('tsTitle').textContent=c.display_name||'Турист';$('tsRecipient').textContent=(c.channel==='telegram'?'Telegram':c.channel==='max'?'MAX':String(c.channel||''))+' · '+(host.notice||'Сначала предпросмотр, затем отправка');
  render();if(!dialog.open)dialog.showModal();await load();
}
function validSnapshot(value){return value&&Number(value.conversation_id)===context?.conversation&&Number.isInteger(value.version)&&value.version>=0&&typeof value.can_edit==='boolean'&&value.draft&&Array.isArray(value.draft.offers)&&value.draft.offers.length<=20}
async function load(){
  loading=true;controls();const token=epoch,result=await request('get');if(token!==epoch)return;loading=false;
  if(!result?.ok||!validSnapshot(result.selection)){status(error(result),true);if([403,404].includes(result?.http_status))canEdit=false;recovery('read');controls();return}
  const snapshot=result.selection;previewIssues=[];canEdit=snapshot.can_edit;
  if(!dirty&&!pending&&!conflict){draft=clone(snapshot.draft);version=snapshot.version}
  else if(snapshot.version!==version&&!pending){conflict=snapshot;if(comparison){draft=clone(snapshot.draft);rawAges={}}}
  render();status(!canEdit?'Просмотр. Править может ответственный менеджер или администратор.':dirty?'Восстановлены ваши несохранённые правки.':'Черновик загружен.');
  if(pending)recovery('pending');else if(conflict)recovery('conflict');
}
function validTrip(t){
  if(!t||!['saved','historical','unbound'].includes(t.state)||typeof t.revision!=='string'||t.revision!==''&&!/^[a-f0-9]{64}$/.test(t.revision)||!t.fields||typeof t.fields!=='object'||Array.isArray(t.fields)||!Array.isArray(t.details)||!Array.isArray(t.warnings))return false;
  if([...t.details,...t.warnings].some(x=>typeof x!=='string'||x.length>1200))return false;
  return Object.entries(t.fields).every(([k,v])=>['destination','departure'].includes(k)?typeof v==='string'&&v.length<=160:k==='date_from'?typeof v==='string'&&/^\d{4}-\d{2}-\d{2}$/.test(v):k==='nights'?Number.isInteger(v)&&v>0&&v<=60:k==='adults'?Number.isInteger(v)&&v>0&&v<=20:k==='child_ages'?Array.isArray(v)&&v.length<=12&&v.every(x=>Number.isInteger(x)&&x>=0&&x<=17):false);
}
async function showTrip(){
  if(!editable()||busy||draft.offers.length>=20)return;
  const token=epoch,panel={trip:null};tripPanel=panel;busy=true;clearTimeout(timer);render();
  const result=await request('trip_context');if(token!==epoch)return;busy=false;
  if(tripPanel!==panel||!dialog.open){controls();return}
  if(!result?.ok||!validTrip(result.trip)){tripPanel=null;render();status(result?.error==='trip_changed'?'Условия изменились. Откройте их заново.':'Не удалось загрузить условия поездки. Можно добавить предложение вручную.',true);return}
  panel.trip=result.trip;render();
}
function renderTrip(){
  const t=tripPanel.trip,editor=$('tsEditor');
  editor.innerHTML='<section class="tsTripPanel"><h3>Последние сохранённые условия</h3><p>Проверьте по переписке, что это текущая поездка. Сохранённые параметры могут относиться к прежнему запросу.</p>'+(t?'<ul>'+[...(t.details||[]),...(t.warnings||[])].map(x=>'<li>'+esc(x)+'</li>').join('')+'</ul>':'<p>Загружаем условия…</p>')+(t?.revision&&Object.keys(t.fields||{}).length?'<label class="tsTripConfirm"><input id="tsTripConfirm" type="checkbox"> Это условия текущей поездки</label><button id="tsTripApply" type="button" class="tsPrimary" disabled>Добавить с этими условиями</button>':t?'<p>Нет подходящих сохранённых условий. Добавьте предложение вручную.</p>':'')+'<button id="tsTripCancel" type="button">Вернуться к редактору</button></section>';
  $('tsTripCancel').disabled=busy;$('tsTripCancel').onclick=()=>{tripPanel=null;render();if(dirty)changed()};
  if($('tsTripConfirm')){$('tsTripConfirm').disabled=busy;$('tsTripConfirm').onchange=()=>{$('tsTripApply').disabled=busy||!$('tsTripConfirm').checked};$('tsTripApply').onclick=applyTrip;}
}
async function applyTrip(){
  const panel=tripPanel;if(!panel?.trip?.revision||!$('tsTripConfirm')?.checked||!editable()||busy||draft.offers.length>=20)return;
  const token=epoch;busy=true;render();
  const result=await request('trip_apply',{revision:panel.trip.revision,confirm_current_trip:true});if(token!==epoch)return;busy=false;
  if(tripPanel!==panel||!dialog.open){controls();return}
  if(!result?.ok||!validTrip(result.trip)||result.trip.revision!==panel.trip.revision){tripPanel=null;render();status(result?.error==='trip_changed'?'Условия поездки или подборка изменились. Откройте условия заново.':'Не удалось проверить условия поездки. Ваши варианты сохранены.',true);return}
  const offer=newOffer();for(const key of ['destination','departure','date_from','nights','adults','child_ages'])if(Object.hasOwn(result.trip.fields,key))offer[key]=clone(result.trip.fields[key]);
  tripPanel=null;draft.offers.push(offer);selected=offer.id;changed();render();$('ts-hotel')?.focus();
}
function newOffer(){return {id:id(),selected:true,hotel:'',destination:'',departure:'',room:'',meal:'',source_label:'',rationale:'',compromise:'',flights:'',inclusions:'',constraints:'',stars:null,nights:null,adults:null,date_from:'',child_ages:[],amount:'',currency:'RUB',source_url:'',observed_at:'',photos:[]}}
function add(){if(!editable()||draft.offers.length>=20)return;const item=newOffer();draft.offers.push(item);selected=item.id;changed();render();$('ts-hotel')?.focus()}
function editable(){return current()&&canEdit&&!loading&&!pending&&!conflict&&!preview&&!sending&&!photoUpload}
function invalidAges(){return draft?.offers.some(x=>Object.hasOwn(rawAges,x.id)&&!validAges(rawAges[x.id]))}
function validAges(value){const raw=String(value).trim();return raw===''||/^\d{1,2}(?:\s*,\s*\d{1,2})*$/.test(raw)&&raw.split(',').length<=12&&raw.split(',').every(x=>Number(x)>=0&&Number(x)<=17)}
function changed(){previewIssues=[];$('tsPreviewIssues')?.remove();dirty=true;stash();status(invalidAges()?'Возраст детей укажите числами от 0 до 17 через запятую.':'Есть несохранённые изменения.',invalidAges());controls();clearTimeout(timer);timer=setTimeout(()=>{if(editable()&&!busy&&!invalidAges()&&dialog?.open)void save()},900)}
function item(){return draft?.offers.find(x=>x.id===selected)}
function edit(event){
  if(event.target.dataset.tsUpload){if(event.type==='change')void choosePhoto(event.target);return}
  if(event.target.id==='ts-hotel-group'&&editable()){
    const offer=item(),value=event.target.value;if(!offer)return;
    if(value){
      const other=draft.offers.find(x=>x.id!==offer.id&&(value==='g:'+x.hotel_group||value==='o:'+x.id));if(!other)return;
      const group=other.hotel_group||id();offer.hotel_group=group;other.hotel_group=group;
    }else delete offer.hotel_group;
    changed();renderList();renderGrouping();renderPhotoReuse();$('ts-hotel-group')?.focus({preventScroll:true});return;
  }
  if(event.target.dataset.tsPhoto&&editable()){
    const offer=item(),kind=event.target.dataset.tsPhoto;if(!offer)return;const photos=new Map(offer.photos.map(x=>[x.kind,x]));
    if(event.target.value.trim())photos.set(kind,{kind,url:event.target.value.trim(),illustrative:true});else photos.delete(kind);
    offer.photos=['cover','beach','room'].filter(x=>photos.has(x)).map(x=>photos.get(x));changed();renderPhotoReuse();return;
  }
  const input=event.target,key=input.dataset.tsField;if(!key||!editable())return;
  const offer=item();if(!offer)return;
  let value=input.type==='checkbox'?input.checked:input.value;
  if(['stars','nights','adults'].includes(key)){value=value===''?null:Number(value);if(value!==null&&!Number.isInteger(value))return}
  if(key==='child_ages'){rawAges[offer.id]=String(value);if(!validAges(value)){changed();return}const raw=String(value).trim();value=raw?raw.split(',').map(x=>Number(x.trim())):[]}
  if(key==='hotel'&&offer.hotel!==value){delete offer.hotel_group;}
  offer[key]=value;changed();renderList();if(key==='hotel'){renderGrouping();renderPhotoReuse();}
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
function cancelPhotoUpload(redraw=true){
  const upload=photoUpload;if(!upload)return;photoUpload=null;upload.controller?.abort();busy=false;
  if(redraw&&current()&&dialog?.open){render();status('Загрузка отменена. Прежние фото сохранены.')}
}
function uploadCurrent(upload){return photoUpload===upload&&current(upload.target)&&epoch===upload.epoch&&dialog?.open&&selected===upload.offer&&version===upload.version}
async function choosePhoto(input){
  const file=input.files?.[0],offer=item(),kind=input.dataset.tsUpload,target=capture(),token=epoch;
  if(!file||!offer||!editable()||busy)return;
  if(file.size===0||file.size>2097152||file.type&&!['image/jpeg','image/png'].includes(file.type)){input.value='';status('Выберите JPEG или PNG до 2 МБ. Прежнее фото сохранено.',true);return}
  clearTimeout(timer);if(dirty)await save();
  if(token!==epoch||!current(target)||dirty||busy||pending||conflict||!editable()||item()?.id!==offer.id||!dialog?.open)return;
  photoUpload={target,epoch:token,offer:offer.id,kind,version,operation:id(),file,encoded:null,hash:null,nextPart:0,progress:0,controller:null,loading:false};
  await submitPhotoUpload();
}
function renderUploadNotice(){
  $('tsUploadNotice')?.remove();if(!photoUpload||!$('tsEditor'))return;
  const notice=document.createElement('section');notice.id='tsUploadNotice';notice.className='tsUploadNotice';
  const text=document.createElement('p');text.textContent=photoUpload.loading?'Загружаем фото… '+photoUpload.progress+'%':'Ответ о загрузке не подтверждён. Повторите этот же файл или отмените. Прежнее фото сохранено.';notice.append(text);
  for(const [id,label] of [['tsUploadRetry','Повторить загрузку фото'],['tsUploadCancel','Отменить загрузку']]){const b=document.createElement('button');b.type='button';b.id=id;b.textContent=label;b.disabled=id==='tsUploadRetry'&&photoUpload.loading;b.hidden=id==='tsUploadRetry'&&photoUpload.loading;notice.append(b)}
  $('tsEditor').prepend(notice);
}
async function submitPhotoUpload(){
  const upload=photoUpload;if(!upload||upload.loading||!uploadCurrent(upload)||!canEdit||pending||conflict||sending||preview)return;
  upload.loading=true;busy=true;controls();renderUploadNotice();status('Загружаем фото…');
  let result;
  try {
    if(!upload.encoded)upload.encoded=await new Promise((resolve,reject)=>{const reader=new FileReader();reader.onload=()=>resolve(String(reader.result).split(',')[1]||'');reader.onerror=()=>reject(new Error('file_read'));reader.readAsDataURL(upload.file)});
    if(!uploadCurrent(upload)){if(photoUpload===upload)cancelPhotoUpload(false);return;}
    if(!upload.hash)upload.hash=Array.from(new Uint8Array(await crypto.subtle.digest('SHA-256',await upload.file.arrayBuffer())),x=>x.toString(16).padStart(2,'0')).join('');
    const count=Math.ceil(upload.encoded.length/96000);
    for(let index=upload.nextPart;index<count;index++){
      if(!uploadCurrent(upload)){if(photoUpload===upload)cancelPhotoUpload(false);return;}
      upload.controller=new AbortController();const timeout=setTimeout(()=>upload.controller.abort(),30000);
      try {result=await host.request('tour-selection-photo.php','upload',{conversation_id:upload.target.conversation,version:upload.version,offer_id:upload.offer,kind:upload.kind,operation_id:upload.operation,image_base64:upload.encoded.slice(index*96000,(index+1)*96000),part_index:index,part_count:count,image_bytes:upload.file.size,image_sha256:upload.hash},{signal:upload.controller.signal,isCurrent:()=>uploadCurrent(upload)});} finally {clearTimeout(timeout)}
      if(!uploadCurrent(upload)){if(photoUpload===upload)cancelPhotoUpload(false);return;}
      if(!result?.ok||result.operation_id!==upload.operation||result.photo)break;
      if(result.state!=='receiving'||result.part_index!==index||index===count-1){result={ok:false,error:'invalid_response'};break;}
      upload.nextPart=index+1;upload.progress=Math.floor(upload.nextPart/count*100);controls();renderUploadNotice();
    }
  }catch(e){result={ok:false,error:'network_error'}}
  if(!uploadCurrent(upload)){if(photoUpload===upload)cancelPhotoUpload(false);return;}
  upload.loading=false;busy=false;
  if(result?.ok&&result.operation_id===upload.operation&&result.photo?.kind===upload.kind&&typeof result.photo.url==='string'&&new RegExp('^https://app\\.anytoour\\.ru/manager/tour-selection-photo\\.php\\?conversation_id='+upload.target.conversation+'&draft_photo=[a-f0-9]{32}$').test(result.photo.url)){
    const offer=item(),photos=new Map(offer.photos.map(x=>[x.kind,x]));photos.set(upload.kind,{url:result.photo.url,kind:upload.kind,illustrative:true});offer.photos=['cover','beach','room'].filter(k=>photos.has(k)).map(k=>photos.get(k));photoUpload=null;changed();render();
    status('Фото загружено. Сохраняем вариант…');await save();if(current()&&!dirty&&!pending&&!conflict)status('Фото добавлено. Сообщения туристу не отправлялись.');return;
  }
  const failures={invalid_photo:'Файл не подходит. Нужен JPEG или PNG до 2 МБ, не меньше 100 × 100 пикселей.',photo_capacity:'Хранилище фото заполнено. Прежнее фото сохранено.',photo_storage_unavailable:'Не удалось сохранить фото. Прежнее фото сохранено.',operation_mismatch:'Файл отличается от начатой загрузки. Выберите его заново.',draft_changed:'Подборка изменилась в другой вкладке. Откройте её заново перед загрузкой.',forbidden:'Доступ к изменению подборки изменился.',not_found:'Доступ к подборке изменился.'};
  if(failures[result?.error]){photoUpload=null;render();status(failures[result.error],true);return}
  controls();renderUploadNotice();status('Загрузка не подтверждена. Повторите этот же файл или отмените.',true);
}
// Reuse only explicit same-hotel cover/territory photos. Each room stays its
// own illustrative image; a copy never links subsequent edits or overwrites data.
function reusablePhotos(offer,source){
  if(!offer?.hotel_group||offer.id===source?.id||offer.hotel_group!==source?.hotel_group)return [];
  return ['cover','beach'].flatMap(kind=>{
    const found=source.photos.filter(x=>x.kind===kind);
    return !offer.photos.some(x=>x.kind===kind)&&found.length===1?[found[0]]:[];
  });
}
function renderPhotoReuse(){
  const root=$('tsPhotoReuse'),offer=item();if(!root||!offer)return;
  const previous=$('tsPhotoSource')?.value;
  const candidates=draft.offers.flatMap((source,index)=>{const photos=reusablePhotos(offer,source);return photos.length?[{source,index,photos}]:[]});
  root.hidden=!candidates.length;root.replaceChildren();if(!candidates.length)return;
  root.innerHTML='<label for="tsPhotoSource">Фото этого отеля из подборки<select id="tsPhotoSource">'+candidates.map(({source,index,photos})=>`<option value="${esc(source.id)}">Вариант ${index+1} · ${esc(source.hotel)} · ${photos.map(x=>x.kind==='cover'?'отель':'пляж или бассейн').join(', ')}</option>`).join('')+'</select></label><button type="button" data-ts-copy-photos>Подставить фото отеля</button><p>Только пустые поля отеля и пляжа. Фото номера остаётся отдельным. Отели объединены вашим выбором.</p>';
  if(candidates.some(x=>x.source.id===previous))$('tsPhotoSource').value=previous;
  root.querySelectorAll('select,button').forEach(x=>{x.disabled=!editable()||busy});
}
function copyHotelPhotos(){
  if(!editable()||busy)return;
  const offer=item(),source=draft.offers.find(x=>x.id===$('tsPhotoSource')?.value),photos=reusablePhotos(offer,source);if(!photos.length)return;
  const combined=new Map(offer.photos.map(x=>[x.kind,x]));for(const photo of photos)combined.set(photo.kind,clone(photo));
  offer.photos=['cover','beach','room'].filter(x=>combined.has(x)).map(x=>combined.get(x));changed();render();
  status('Фото отеля подставлены. Проверьте фото номера перед предпросмотром.');
  const field=$('ts-photo-room');field?.focus();field?.scrollIntoView({block:'center'});
}
const previewFieldNames={hotel:'отель',departure:'вылет',date_from:'дату',nights:'ночи',adults:'состав',room:'номер',meal:'питание',amount:'общую цену',photos:'фото отеля',conditions:'условия'};
function renderPreviewIssues(){
  $('tsPreviewIssues')?.remove();
  const issues=previewIssues.filter(x=>draft.offers.some(o=>o.id===x.offer_id&&o.selected));if(!issues.length)return;
  const panel=document.createElement('section');panel.id='tsPreviewIssues';panel.className='tsPreviewIssues';panel.setAttribute('aria-label','Что заполнить для предпросмотра');
  panel.innerHTML='<h3>Что заполнить для предпросмотра</h3><p>Нажмите, чтобы перейти к нужному полю. После правок откройте предпросмотр ещё раз.</p><div>'+issues.map(issue=>issue.fields.map(field=>`<button type="button" data-ts-fix-offer="${esc(issue.offer_id)}" data-ts-fix-field="${esc(field)}">Вариант ${draft.offers.findIndex(x=>x.id===issue.offer_id)+1}: ${esc(field==='photos'&&!draft.offers.find(x=>x.id===issue.offer_id)?.import_source?'три фото':previewFieldNames[field])}</button>`).join('')).join('')+'</div>';
  $('tsEditor').prepend(panel);panel.querySelectorAll('button').forEach(x=>{x.disabled=!editable()||busy});
}
function fixPreviewIssue(offerId,field){
  if(!editable()||busy||!previewIssues.some(x=>x.offer_id===offerId&&x.fields.includes(field))||!draft.offers.some(x=>x.id===offerId&&x.selected))return;
  selected=offerId;render();
  if(field==='photos'&&item().import_source&&!item().photos.length){const section=$('tsEditor').querySelector('.tsPhotoFields');section?.scrollIntoView({block:'center'});return;}
  const firstPhoto=['cover','beach','room'].find(kind=>!item().photos.some(x=>x.kind===kind))||'cover';
  const target=$(field==='photos'?'ts-photo-'+firstPhoto:field==='conditions'?'ts-hotel':'ts-'+field);if(target?.closest('details'))target.closest('details').open=true;target?.focus();target?.scrollIntoView({block:'center'});
}
function renderList(){
  const list=$('tsList');if(!list||!draft)return;
  $('tsCount').textContent='Варианты · '+draft.offers.length;
  list.innerHTML=draft.offers.length?[...hotelGroups().values()].map(members=>
    members.map(({offer:x,index:i})=>`<button type="button" data-ts-select="${esc(x.id)}" class="tsOption ${x.id===selected?'isSelected':''}" aria-pressed="${x.id===selected}"><span class="tsOptionNumber">${i+1}</span><span>${members.length>1&&i===members[0].index?`<span class="tsGroupLabel">Один отель · вариантов ${members.length}</span>`:''}<strong>${esc(x.hotel||'Новый вариант')}</strong><small>${esc([x.date_from,x.room].filter(Boolean).join(' · '))}</small><small>${esc(x.amount?x.amount+' '+x.currency+' за всех':'Цена не указана')}</small><small>${x.selected?'Включён в подборку':'Исключён из подборки'}</small></span></button>`).join('')).join(''):'<p class="tsEmptyList">Добавьте первое предложение. Можно сохранить незаполненный вариант и вернуться к нему позже.</p>';
}
function render(){
  if(!draft||!dialog)return;
  dialog.classList.toggle('tsTripMode',!!tripPanel);
  if(preview&&publication){renderPreview();controls();return}
  dialog.classList.remove('tsPreviewMode');
  if(!item())selected=draft.offers[0]?.id||'';renderList();const editor=$('tsEditor'),offer=item();
  if(tripPanel){renderTrip();controls();return}
  if(!offer){editor.innerHTML='<div class="tsEmpty"><span aria-hidden="true">＋</span><h3>Соберите подборку для клиента</h3><p>Добавьте найденный тур, укажите условия и общую цену. Варианты сохранятся здесь отдельно от переписки.</p><button type="button" id="tsEmptyAdd" class="tsPrimary">Добавить предложение</button></div>';$('tsEmptyAdd').onclick=add;controls();return}
  editor.innerHTML=`<div class="tsEditorHead"><h3>Условия предложения</h3><div class="tsOrder"><button type="button" data-ts-action="up" aria-label="Поднять предложение">↑</button><button type="button" data-ts-action="down" aria-label="Опустить предложение">↓</button><button type="button" data-ts-action="remove">Удалить</button></div></div><label class="tsInclude"><input data-ts-field="selected" type="checkbox" ${offer.selected?'checked':''}> Включить в подборку</label><div class="tsFields">${input('hotel','Название отеля','text','maxlength="191"')}${input('stars','Звёздность','number','min="0" max="5"')}${input('destination','Страна и курорт','text','maxlength="160"')}${input('departure','Вылет из','text','maxlength="160"')}${input('date_from','Дата вылета','date')}${input('nights','Ночей','number','min="1" max="60"')}${input('adults','Взрослых','number','min="1" max="20"')}${input('child_ages','Возраст детей через запятую','text','inputmode="numeric" placeholder="Например, 4, 10" maxlength="60"')}${input('room','Номер и размещение','text','maxlength="300"')}${input('meal','Питание','text','maxlength="160"')}${input('amount','Цена за всех','text','inputmode="decimal" placeholder="Например, 240000" maxlength="12"')}<label for="ts-currency">Валюта<select id="ts-currency" data-ts-field="currency">${['RUB','EUR','USD'].map(v=>`<option ${offer.currency===v?'selected':''}>${v}</option>`).join('')}</select></label>${textarea('rationale','Почему рекомендуем')}${textarea('compromise','Что важно учесть')}</div><details class="tsDetails"><summary>Источник и дополнительные условия</summary><div class="tsFields">${input('source_label','Туроператор или источник','text','maxlength="160"')}${input('source_url','Ссылка на предложение','url','maxlength="2048" placeholder="https://…"')}${textarea('flights','Перелёт и багаж')}${textarea('inclusions','Что включено')}${textarea('constraints','Непроверенные условия и доплаты')}</div></details><p class="tsPriceHint">Сохранение черновика не подтверждает актуальность цены. Перед отправкой условия потребуется проверить.</p>`;
  const grouping=document.createElement('section');grouping.id='tsGrouping';grouping.className='tsGrouping';editor.insertBefore(grouping,editor.querySelector('.tsDetails'));renderGrouping();
  if(offer.import_source){const source=document.createElement('section');source.className='tsImportedSource';const title=document.createElement('h3');title.textContent='Исходные условия '+(offer.import_source.adapter==='anex-agent-v1'?'ANEX':offer.import_source.adapter==='tourvisor-snapshot-v1'?'Tourvisor':'FUN&SUN');source.append(title);for(const text of [offer.import_source.fields.amount+' '+offer.import_source.fields.currency+(offer.import_source.adapter==='tourvisor-snapshot-v1'?' за номер · менеджер подтвердил 1 номер на 2 взрослых · ':' за весь состав · ')+offer.import_source.observed_at,offer.import_source.raw.room+' · '+offer.import_source.raw.meal,'Цена со страницы. Полная стоимость, рейсы, фото и соответствие каталогу не подтверждены. Ручная сумма выше хранится отдельно.']){const p=document.createElement('p');p.textContent=text;source.append(p)}const link=document.createElement('a');link.href=offer.import_source.url;link.target='_blank';link.rel='noopener noreferrer';link.textContent='Открыть исходный поиск';source.append(link);editor.insertBefore(source,editor.querySelector('.tsDetails'))}
  controls();recovery(conflict?'conflict':pending?'pending':'');
  const photoSection=document.createElement('section');photoSection.className='tsPhotoFields';
  const automatic=Array.isArray(offer.import_source?.photos)||!!offer.import_source&&offer.photos.length===0;
  if(automatic){
    photoSection.classList.add('tsAutomaticPhotos');
    photoSection.innerHTML='<h3>Фотографии отеля</h3><p class="tsAutomaticPhotoStatus">'+(offer.photos.length?'Фото добавлены автоматически из галереи источника. Можно сразу открыть предпросмотр.':'Источник пока не отдал фотографии. Добавьте этот же тур из источника повторно: фото заполнятся без дубля предложения.')+'</p><div class="tsFields">'+offer.photos.map((photo,i)=>`<div class="tsWide tsPhotoSlot"><h4>Фото ${i+1}</h4><img class="tsUploadedThumb" loading="lazy" decoding="async" src="${esc(photo.url)}" alt="Фото отеля ${i+1}" referrerpolicy="no-referrer"><p class="tsUploadedLabel">${offer.import_source.photos?.includes(photo.url)?'Из галереи источника':'Фото заменено'}</p><details class="tsPhotoReplace"><summary>Заменить фото</summary><label class="tsFileLabel" for="ts-upload-${photo.kind}">Выбрать другой файл<input id="ts-upload-${photo.kind}" type="file" data-ts-upload="${photo.kind}" accept="image/jpeg,image/png" ${!editable()||busy?'disabled':''}></label><label for="ts-photo-${photo.kind}">Ссылка на другое фото<input id="ts-photo-${photo.kind}" type="url" data-ts-photo="${photo.kind}" maxlength="2048" placeholder="https://…/photo.jpg" value="" ${!editable()||busy?'disabled':''}></label><button type="button" data-ts-remove-photo="${photo.kind}">Убрать фото</button></details></div>`).join('')+'</div><p>На снимках — отель. Интерьер выбранного номера может отличаться.</p>';
  }else{
  photoSection.innerHTML='<h3>Три фото для карточки</h3><p>Выберите реальные фото этого отеля с устройства: JPEG или PNG до 2 МБ. Фото номера будет отмечено как пример интерьера. Загруженные файлы доступны сотрудникам с доступом к этому клиенту.</p><div class="tsFields">'+[['cover','Отель'],['beach','Пляж или бассейн'],['room','Номер']].map(([kind,label])=>{const url=offer.photos.find(x=>x.kind===kind)?.url||'',uploaded=/^https:\/\/app\.anytoour\.ru\/manager\/tour-selection-photo\.php\?conversation_id=/.test(url);return `<div class="tsWide tsPhotoSlot"><h4>${label}</h4>${uploaded?`<img class="tsUploadedThumb" loading="lazy" decoding="async" src="${esc(url)}" alt="${label==='Номер'?'Пример интерьера номера':label}" referrerpolicy="no-referrer">`:''}<label class="tsFileLabel" for="ts-upload-${kind}">${uploaded?'Заменить фото':'Выбрать фото'}: ${label}<input id="ts-upload-${kind}" type="file" data-ts-upload="${kind}" accept="image/jpeg,image/png" ${!editable()||busy?'disabled':''}></label>${uploaded?'<p class="tsUploadedLabel">Фото добавлено в черновик</p><button type="button" data-ts-remove-photo="'+kind+'">Убрать фото</button><details class="tsPhotoUrl"><summary>Заменить по ссылке</summary>':''}<label for="ts-photo-${kind}">${uploaded?'Ссылка на новое фото':'Или HTTPS-ссылка'}<input id="ts-photo-${kind}" type="url" data-ts-photo="${kind}" maxlength="2048" placeholder="https://…/photo.jpg" value="${esc(uploaded?'':url)}" ${!editable()||busy?'disabled':''}></label>${uploaded?'</details>':''}</div>`}).join('')+'</div>';
  }
  editor.insertBefore(photoSection,editor.querySelector('.tsPriceHint'));
  photoSection.querySelectorAll('.tsUploadedThumb').forEach(img=>img.addEventListener('error',()=>{img.alt='Не удалось показать фото';const label=img.closest('.tsPhotoSlot').querySelector('.tsUploadedLabel');if(label)label.textContent='Фото не удалось показать. Замените его или проверьте предпросмотр.'}));
  photoSection.querySelectorAll('[data-ts-remove-photo]').forEach(x=>{x.disabled=!editable()||busy});
  const reuse=document.createElement('div');reuse.id='tsPhotoReuse';reuse.className='tsPhotoReuse';photoSection.querySelector('.tsFields').before(reuse);renderPhotoReuse();renderPreviewIssues();renderUploadNotice();
}
function controls(){
  if(!dialog)return;const disabled=!editable();
  $('tsAdd').disabled=disabled||busy||(draft?.offers.length||0)>=20;$('tsSave').disabled=disabled||busy||!dirty||invalidAges();$('tsDone').disabled=busy||loading||(!preview&&invalidAges());
  $('tsSaved').textContent=photoUpload?.loading?'Загружаем фото… '+photoUpload.progress+'%':photoUpload?'Загрузка фото не подтверждена':busy?(tripPanel?'Проверяем условия поездки…':'Сохраняем…'):pending?'Результат сохранения уточняется':conflict?'Есть конфликт правок':dirty?'Не все изменения сохранены':version?(host.savedLabel||'Сохранено на сервере'):'Новый черновик';
  $('tsEditor')?.querySelectorAll('input,textarea,select,[data-ts-action],[data-ts-copy-photos],[data-ts-fix-offer],[data-ts-remove-photo],#tsEmptyAdd').forEach(x=>{x.disabled=disabled||busy});
  $('tsSave').hidden=preview;$('tsPreview').hidden=preview;$('tsEdit').hidden=!preview;$('tsSend').hidden=!preview;$('tsHistory').hidden=preview;
  $('tsCollector').hidden=preview;$('tsCollector').disabled=!!photoUpload||!canEdit||busy||loading||sending||sharing;
  $('tsPreview').disabled=!!photoUpload||busy||loading||sending||!canEdit||!!pending||!!conflict||invalidAges();$('tsHistory').disabled=!!photoUpload||busy||loading||sending;$('tsEdit').disabled=sending;
  $('tsShare').hidden=!preview;$('tsShare').disabled=busy||loading||sending||sharing;
  if($('tsInterest')){$('tsInterest').disabled=busy||loading||sending||sharing||interestLoading;$('tsInterest').textContent=interestLoading?'Загружаем активность…':'Активность подборки'}
  const publish=$('tsPublish');if(publish)publish.disabled=!canEdit||busy||sending||sharing||dirty||!!pending||!!conflict||photoPending>0||photoFailures>0;
  if(sharing){$('tsEdit').disabled=true;$('tsDone').disabled=true}else $('tsDone').disabled=false;
  $('tsTripAdd').hidden=preview;$('tsTripAdd').disabled=disabled||busy||!!tripPanel||(draft?.offers.length||0)>=20;
  $('tsList')?.querySelectorAll('button').forEach(x=>{x.disabled=!!photoUpload||busy});
  if(tripPanel){for(const id of ['tsAdd','tsSave','tsDone','tsPreview','tsHistory','tsCollector'])$(id).disabled=true;}
  const cards=publication?.cards||[],unknown=cards.some(x=>['reserved','unknown'].includes(x.state)),known=cards.some(x=>x.state==='known'),remaining=cards.filter(x=>x.state!=='delivered').length;
  $('tsSend').disabled=sending||sharing||!publication?.can_send||unknown||!remaining||photoFailures>0||photoPending>0;
  $('tsSend').textContent=sending?'Отправляем…':unknown?'Результат неизвестен':photoPending?'Загружаем фото…':known?'Проверить доставку':remaining?'Отправить варианты ('+remaining+')':'Подборка отправлена';
  if(item()){const at=draft.offers.findIndex(x=>x.id===selected);const up=dialog.querySelector('[data-ts-action="up"]'),down=dialog.querySelector('[data-ts-action="down"]');if(up)up.disabled=disabled||busy||at===0;if(down)down.disabled=disabled||busy||at===draft.offers.length-1}
}
function recovery(kind){
  const root=$('tsRecovery');if(!root)return;dialog.classList.toggle('tsConflictMode',kind==='conflict');root.hidden=!kind;root.replaceChildren();if(!kind)return;
  const text=document.createElement('p');text.textContent=kind==='conflict'?'На сервере есть другая версия. Ваши правки сохранены; выберите, с какой версией продолжить.':kind==='pending'?'Сервер мог сохранить изменения. Сначала проверьте результат, чтобы не перезаписать чужую правку.':'Не удалось загрузить подборку. Ваши локальные правки сохранены.';root.appendChild(text);
  const button=(label,fn)=>{const el=document.createElement('button');el.type='button';el.textContent=label;el.onclick=fn;root.appendChild(el)};
  if(kind==='conflict'){
    if(!comparison)button('Показать версию коллеги',()=>{if(!conflict||busy||comparison)return;comparison={draft:clone(draft),rawAges:clone(rawAges),selected};draft=clone(conflict.draft);rawAges={};stash();render();recovery('conflict');status('Просмотр версии коллеги. Ваши правки сохранены; выберите, с какой версией продолжить.');});
    button('Продолжить с моими правками',()=>{if(!conflict||busy)return;if(comparison){draft=clone(comparison.draft);rawAges=clone(comparison.rawAges);selected=comparison.selected}version=conflict.version;canEdit=conflict.can_edit;conflict=null;comparison=null;dirty=true;stash();render();recovery('');status('Ваш вариант в редакторе. Проверьте его и нажмите «Сохранить».');});
    if(comparison)button('Продолжить с версией коллеги',()=>{if(!conflict||busy)return;draft=clone(conflict.draft);version=conflict.version;canEdit=conflict.can_edit;rawAges={};dirty=false;conflict=null;comparison=null;stash();render();recovery('');status('Выбрана версия с сервера.');});
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
    version=result.selection.version;canEdit=result.selection.can_edit;draft=clone(result.selection.draft);rawAges={};dirty=false;pending=null;conflict=null;stash();render();status(host.savedMessage||'Черновик сохранён.');return;
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
function previewCaption(card){
  const text=String(card.text||''),lines=text.split('\n'),hotel=card.snapshot?.hotel;
  // Use the immutable renderer text; never reconstruct terms or accept HTML.
  if(lines.length<3||typeof hotel!=='string'||!hotel||!lines[0].startsWith(hotel))return esc(text);
  return '<strong class="tsCardTitle">'+esc(hotel)+'</strong>'+esc(lines[0].slice(hotel.length))+'\n<strong class="tsCardPrice">'+esc(lines[1])+'</strong>\n'+esc(lines.slice(2).join('\n'));
}
function renderPreview(){
  dialog.classList.add('tsPreviewMode');photoFailures=0;photoPending=publication.cards.reduce((sum,card)=>sum+card.photos.length,0);const imageRun=++photoEpoch,token=epoch,publicationId=publication.id;
  $('tsEditor').innerHTML='<div class="tsPreviewHeading"><h3>Предпросмотр подборки</h3><p>'+esc(publication.cards.some(card=>card.photo_layout==='gallery-v1')?(publication.channel==='telegram'?'Telegram — фотографии отеля с перелистыванием.':'MAX — фотографии отеля.'):(publication.channel==='telegram'?'Telegram — карусель из трёх фото.':'MAX — мозаика из трёх фото.'))+' Проверьте фото, условия и общую цену. «Нравится» сохранит именно показанный вариант.</p></div><div class="tsPreviewCards">'+publication.cards.map(card=>`<article class="tsPreviewCard" data-ts-card="${card.index}"><div class="tsPhotos tsPhotoCount${card.photos.length} ${publication.channel==='telegram'?'tsPhotoSlideshow':'tsPhotoMosaic'}" ${publication.channel==='telegram'?'tabindex="0" role="region" aria-label="Фото отеля"':''}>${card.photos.map((url,i)=>`<img src="${esc(url)}" alt="${card.photo_layout==='gallery-v1'?'Фото отеля '+(i+1):['Отель','Пляж или бассейн','Пример интерьера номера'][i]}" referrerpolicy="no-referrer">`).join('')}</div>${publication.channel==='telegram'&&card.photos.length>1?'<div class="tsPhotoNav"><button type="button" data-ts-photo-step="-1" aria-label="Предыдущее фото">←</button><span class="tsPhotoPosition" aria-live="polite">Фото 1 из 3</span><button type="button" data-ts-photo-step="1" aria-label="Следующее фото">→</button></div>':''}<p class="tsCardText">${previewCaption(card)}</p><span class="tsLikePreview">♡ Нравится</span><p class="tsDeliveryState" data-ts-state="${card.index}"></p></article>`).join('')+'</div>';
  $('tsEditor').scrollTop=0;
  $('tsEditor').querySelectorAll('.tsPreviewCard').forEach(card=>{
    const gallery=card.querySelector('.tsPhotoSlideshow'),nav=card.querySelector('.tsPhotoNav');if(!gallery||!nav)return;
    const images=gallery.querySelectorAll('img'),position=nav.querySelector('.tsPhotoPosition'),buttons=nav.querySelectorAll('button');
    const index=()=>Math.max(0,Math.min(images.length-1,Math.round(gallery.scrollLeft/Math.max(1,gallery.clientWidth))));
    const update=()=>{const i=index();position.textContent='Фото '+(i+1)+' из '+images.length;buttons[0].disabled=i===0;buttons[1].disabled=i===images.length-1;};
    for(const button of buttons)button.onclick=()=>{const i=Math.max(0,Math.min(images.length-1,index()+Number(button.dataset.tsPhotoStep)));gallery.scrollLeft=i*gallery.clientWidth;update();};
    gallery.addEventListener('scroll',update,{passive:true});update();
  });
  const active=()=>imageRun===photoEpoch&&token===epoch&&preview&&publication?.id===publicationId&&current();
  $('tsEditor').querySelectorAll('img').forEach(img=>{
    img.addEventListener('load',()=>{if(active()){photoPending--;controls()}},{once:true});
    img.addEventListener('error',()=>{if(!active())return;photoPending--;photoFailures++;img.classList.add('tsPhotoFailed');controls();status('Не удалось загрузить фото. Вернитесь к редактированию и проверьте прямую ссылку.',true)},{once:true});
  });
  const activity=document.createElement('button');activity.id='tsInterest';activity.type='button';activity.textContent='Активность подборки';activity.onclick=()=>showInterest();$('tsEditor').querySelector('.tsPreviewHeading').appendChild(activity);
  const interest=document.createElement('section');interest.id='tsInterestPanel';interest.className='tsInterestPanel';interest.hidden=true;interest.setAttribute('aria-live','polite');activity.parentElement.after(interest);
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
  if(result?.ok&&result.publication){previewIssues=[];publication=result.publication;preview=true;render();status('Предпросмотр готов. Проверьте подборку перед отправкой.');return}
  previewIssues=result?.error==='incomplete_offers'&&Array.isArray(result.fields)?result.fields.filter(x=>draft.offers.some(o=>o.id===x.offer_id&&o.selected)&&Array.isArray(x.fields)).map(x=>({offer_id:x.offer_id,fields:x.fields.filter(f=>Object.hasOwn(previewFieldNames,f))})):[];
  renderPreviewIssues();if(previewIssues.length)$('tsPreviewIssues')?.scrollIntoView({block:'start'});
  const names=previewFieldNames;
  if(result?.error==='card_too_long'){status('Сократите пояснения и дополнительные условия в варианте '+result.position+'. Условия не будут обрезаны при отправке.',true);controls();return}
  status(result?.error==='incomplete_offers'?'Заполните '+(result.fields||[]).map(x=>'в варианте '+x.position+': '+x.fields.map(f=>f==='photos'&&!draft.offers.find(o=>o.id===x.offer_id)?.import_source?'три фото':names[f]||f).join(', ')).join('; ')+'.':error(result),true);controls();
}
async function showHistory(){
  if(!current()||busy||sending)return;const token=epoch;busy=true;controls();const result=await request('history');if(token!==epoch)return;busy=false;controls();
  if(!result?.ok){status(error(result),true);return}
  const root=$('tsRecovery');root.replaceChildren();root.hidden=false;
  if(!result.publications.length){root.textContent='Пока нет подготовленных к отправке подборок.';return}
  const heading=document.createElement('p');heading.textContent='Подготовленные подборки и результаты отправки:';root.appendChild(heading);
  for(const entry of result.publications){const button=document.createElement('button');button.type='button';button.textContent='Версия '+entry.draft_version+' · '+entry.created_at+' UTC';button.onclick=async()=>{const t=epoch;const read=await request('publication',{publication_id:entry.publication_id});if(t!==epoch)return;if(read?.ok&&read.publication){publication=read.publication;preview=true;render();recovery('');status('Показана сохранённая версия и результат отправки.')}else status(error(read),true)};root.appendChild(button)}
}
async function showInterest(){
  if(!current()||busy||sending||sharing||interestLoading||!publication)return;
  const token=epoch,id=publication.id;interestLoading=true;controls();
  const result=await request('interest',{publication_id:id});if(token!==epoch)return;interestLoading=false;controls();
  if(!current()||publication?.id!==id||!preview)return;
  const root=$('tsInterestPanel');if(!root)return;root.replaceChildren();root.hidden=false;root.scrollIntoView({block:'start'});
  if(!result?.ok||!result.interest){root.textContent='Активность сейчас недоступна. Это не означает, что просмотров не было.';return}
  const data=result.interest,number=v=>Number.isSafeInteger(v)&&v>=0?v:0;
  const add=(tag,text,parent=root)=>{const node=document.createElement(tag);node.textContent=text;parent.appendChild(node);return node};
  add('h3','Активность этой версии');
  add('p','За последние '+number(data.window_days)+' дней. Это анонимные сигналы браузера, а не подтверждение личности или прочтения. Блокировщики и недоступная связь могут скрыть часть событий.');
  const grid=document.createElement('dl');grid.className='tsInterestCounts';root.appendChild(grid);
  const browser=data.totals?.browser||{};
  for(const [key,label] of [['page_view','Показ страницы'],['hotel_view','Показ отеля'],['bot_open','Переход к боту']]){
    const cell=document.createElement('div'),term=document.createElement('dt'),count=document.createElement('dd');
    term.textContent=label;count.textContent=number(browser[key]?.unique)+' · повторов '+number(browser[key]?.repeat);cell.append(term,count);grid.appendChild(cell);
  }
  const native=data.native||{};
  add('p','Подтверждено в ботах: «Нравится» — '+number(native.liked)+', обращений — '+number(native.inquiries)+'. Переход по кнопке сам по себе обращением не считается.');
  const variants=(data.variants||[]).filter(row=>Number.isInteger(row.index)).map(row=>({row,card:publication.cards.find(card=>card.index===row.index)})).filter(item=>item.card);
  if(variants.some(({row})=>number(row.hotel_views))){
    add('h4','Просмотренные отели');
    for(const {row,card} of variants)if(number(row.hotel_views))add('p',card.snapshot.hotel+': показов — '+number(row.hotel_views)+'.').className='tsInterestHotel';
    add('p','Просмотр отеля не означает выбор конкретного номера или цены.');
  }
  if(variants.some(({row})=>number(row.bot_opens))){
    add('h4','Переходы к точным вариантам');
    add('p','Условия взяты из этой сохранённой версии. Актуальность цены не проверена.');
    for(const {row,card} of variants)if(number(row.bot_opens)){
      const item=add('article','');item.className='tsInterestOffer';
      add('h4','Вариант '+(card.index+1)+' · '+card.snapshot.hotel,item);
      // Keep the renderer's exact price/party/date/room/meal text, not current draft fields.
      add('p',String(card.text||'').split('\n').slice(1,6).join('\n'),item).className='tsInterestTerms';
      add('p','Переходов к боту — '+number(row.bot_opens)+'. Это ещё не обращение.',item);
      const button=add('button','Показать карточку варианта '+(card.index+1),item);button.type='button';button.className='tsInterestJump';
      button.onclick=()=>{
        if(token!==epoch||!current()||!dialog.open||!preview||publication?.id!==id)return;
        const target=dialog.querySelector('.tsPreviewCard[data-ts-card="'+card.index+'"]');if(!target)return;
        target.tabIndex=-1;target.focus({preventScroll:true});(target.querySelector('.tsCardText')||target).scrollIntoView({block:'start'});
      };
    }
  }
  if(data.last_browser_at)add('p','Последний сигнал браузера: '+host.formatTime(data.last_browser_at,{year:true})+' МСК.');
  const automated=[];
  for(const [bucket,label] of [['preview','События предпросмотра'],['robot','События распознанной автоматизации']]){
    let count=0,repeats=0;for(const value of Object.values(data.totals?.[bucket]||{})){count+=number(value?.unique);repeats+=number(value?.repeat)}automated.push(label+': '+count+' · повторов '+repeats);
  }
  add('p',automated.join('. ')+'. Определение автоматизации ограничено доступными признаками.');
  if(data.limited||native.likes_limited||native.inquiries_limited)add('p','Достигнут предел выборки. Показанные числа — только найденные события, а не полный итог.');
  add('p','Обновление страницы подборки сохраняет прежние версии и выборы. Анонимные посещения не назначаются исходному клиенту.');
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
    const hosted='https://tours.anytoour.ru/?'+share.path.slice('/selection/?'.length);
    const url=share.public_url===hosted?hosted:new URL(share.path,host.origin).href,input=document.createElement('input');input.readOnly=true;input.value=url;input.setAttribute('aria-label','Общая ссылка на подборку');root.appendChild(input);
    const copy=document.createElement('button');copy.type='button';copy.textContent='Скопировать ссылку';copy.onclick=async()=>{if(!current())return;const token=epoch;input.select();try{await navigator.clipboard.writeText(url);if(token===epoch&&current())status('Ссылка скопирована.')}catch(e){if(token===epoch&&current())status('Выделена ссылка — скопируйте её.')}};root.appendChild(copy);
    const open=document.createElement('a');const previewPath=typeof share.preview_path==='string'&&/^\/selection\/\?t=[a-f0-9]{64}&preview=[0-9]{10}\.[a-f0-9]{64}$/.test(share.preview_path)?share.preview_path:null;
    const hostedPreview=previewPath?'https://tours.anytoour.ru/?'+previewPath.slice('/selection/?'.length):null;
    open.href=url===hosted&&hostedPreview&&share.preview_url===hostedPreview?hostedPreview:previewPath?new URL(previewPath,host.origin).href:url;open.target='_blank';open.rel='noopener noreferrer';open.textContent='Открыть страницу';root.appendChild(open);
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
mounted=Object.freeze({sync,activate,suspend,open,close});
return mounted;
}
window.AnyTourSelectionEditor=Object.freeze({contract,mount});
})();
