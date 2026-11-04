(function(global){
'use strict';
const scopes={hotel:'Отель',room_category:'Категория номера',facility:'Объект или услуга'};
const same=(a,b)=>a&&b&&Object.keys(a).length===Object.keys(b).length&&Object.keys(a).every(k=>Object.hasOwn(b,k)&&a[k]===b[k]);
function selector(value){
  if(!value||!Number.isSafeInteger(value.conversation_id)||value.conversation_id<=0)return null;
  const hex=v=>typeof v==='string'&&/^[a-f0-9]{32}$/.test(v);
  if(value.kind==='working_draft'&&Number.isSafeInteger(value.draft_version)&&value.draft_version>0&&hex(value.offer_id))
    return {kind:value.kind,draft_version:value.draft_version,offer_id:value.offer_id};
  if(value.kind==='publication'&&hex(value.publication_id)&&Number.isInteger(value.card_index)&&value.card_index>=0&&value.card_index<20)
    return {kind:value.kind,publication_id:value.publication_id,card_index:value.card_index};
  return null;
}
function validReference(r){
  const object=v=>v&&typeof v==='object'&&!Array.isArray(v);
  const strings=v=>Array.isArray(v)&&v.every(x=>typeof x==='string');
  const fact=f=>object(f)&&typeof f.text==='string'&&(f.source_ids===undefined||strings(f.source_ids))
    &&(f.scope===undefined||object(f.scope)&&typeof f.scope.key==='string');
  if(!object(r)||typeof r.status!=='string'||!Array.isArray(r.facts)||!Array.isArray(r.sources)||JSON.stringify(r).length>65536)return false;
  if(r.status!=='available')return true;
  if(!r.facts.length||!r.sources.length||!/^[a-f0-9]{64}$/.test(r.binding_revision||'')||!r.facts.every(fact))return false;
  if(!r.sources.every(x=>object(x)&&typeof x.id==='string'&&['title','url','publication_date','retrieved_on','document_season','limitations'].every(k=>x[k]==null||typeof x[k]==='string')))return false;
  const l=r.material_limits||{};
  return object(l)&&(l.temporal==null||typeof l.temporal==='string')&&(l.unknowns===undefined||strings(l.unknowns))
    &&(l.source_conflicts===undefined||Array.isArray(l.source_conflicts)&&l.source_conflicts.every(fact))
    &&(l.source_read_updates===undefined||Array.isArray(l.source_read_updates)&&l.source_read_updates.every(x=>object(x)&&(x.text===undefined||fact(x))));
}
function mount(root,host){
  root.classList.add('hotelKnowledgeView');
  let generation=0,controller=null,sourceNumbers=new Map();
  const text=(tag,value,parent=root)=>{const node=document.createElement(tag);node.textContent=String(value??'');parent.append(node);return node;};
  const clear=()=>{++generation;controller?.abort();controller=null;root.replaceChildren();};
  const status=value=>{root.replaceChildren();const n=text('p',value);n.setAttribute('role','status');};
  function fact(record,parent){
    text('p',record.text,parent);
    if(record.scope?.key)text('small',(scopes[record.scope.kind]||'Область применения')+': '+record.scope.key,parent);
    if(record.valid_at_travel!=='verified')text('small','Условия на даты поездки требуют проверки.',parent);
    if(record.source_ids?.length)text('small','Источники: '+record.source_ids.map(id=>sourceNumbers.get(id)||'требует проверки').join(', '),parent);
  }
  function render(reference){
    root.replaceChildren();sourceNumbers=new Map(reference.sources.map((s,i)=>[s.id,i+1]));text('h3','Сведения об отеле');
    text('p','Цена, наличие, выбранный номер, питание и условия на даты поездки проверяются отдельно. Чтение справки не подтверждает выбор туриста.');
    if(reference.status!=='available'){
      text('p','Подтверждённая справка для этого варианта недоступна. Требуется проверка точного отеля и источника.');return;
    }
    if(reference.coverage?.complete!==true)text('p','Справка частичная. Отсутствие сведений не означает отсутствие ограничений.');
    for(const record of reference.facts){const section=text('section','');fact(record,section);}
    const limits=reference.material_limits||{};
    if(limits.temporal||limits.unknowns?.length||limits.source_conflicts?.length||limits.source_read_updates?.length){
      const section=text('section','');text('h4','Ограничения и вопросы для проверки',section);
      text('small','Ограничения приведены в формулировках исследовательской справки, включая текст на языке источника.',section);
      if(limits.temporal)text('p',limits.temporal,section);
      for(const item of limits.unknowns||[])text('p',item,section);
      for(const record of limits.source_conflicts||[]){text('strong','Противоречие источников',section);fact(record,section);}
      for(const record of limits.source_read_updates||[])if(record.text)fact(record,section);
    }
    const details=text('details','');text('summary','Источники и версия сведений',details);
    for(const source of reference.sources){
      const section=text('section','',details);text('p',sourceNumbers.get(source.id)+'. '+(source.title||'Источник'),section);
      const dates=[source.publication_date&&'Опубликовано: '+source.publication_date,source.retrieved_on&&'Прочитано: '+source.retrieved_on,source.document_season&&'Сезон документа: '+source.document_season].filter(Boolean);
      text('small',dates.join(' · ')||'Дата публикации не указана.',section);
      if(source.limitations)text('p',source.limitations.replace(/\s*\(turn\d+(?:view|search)\d+\)/g,''),section);
      try{const u=new URL(source.url);if(u.protocol==='https:'){const a=text('a','Открыть источник',section);a.href=u.href;a.target='_blank';a.rel='noopener noreferrer';}}catch(_error){}
    }
    text('small','Версия сведений: '+reference.binding_revision,details);
  }
  async function show(value){
    clear();const pins=selector(value),target=host.capture();
    if(!pins||!Number.isSafeInteger(target?.actor)||target.actor<=0||target.conversation!==value.conversation_id||!host.isCurrent(target)){status('Выберите сохранённый вариант и проверьте доступ.');return;}
    const token=generation;controller=new AbortController();status('Читаем сведения для выбранного варианта…');
    let result;
    try{result=await host.read({conversation_id:value.conversation_id,...pins},{signal:controller.signal,target});}
    catch(_error){result={ok:false,error:'network_error'};}
    if(token!==generation)return;
    if(!host.isCurrent(target)){clear();return;}
    if(!result?.ok||result.schema_version!=='hotel_offer_knowledge_v1'||result.viewer_id!==target.actor||!same(result.selection,pins)){
      status('Сведения не подтверждены для текущего аккаунта и варианта. Проверьте доступ и сохранённую версию.');return;
    }
    const r=result.hotel_reference;
    if(!validReference(r)){
      status('Не удалось проверить целостность справки. Требуется повторное чтение.');return;
    }
    render(r);
  }
  return Object.freeze({show,clear,destroy:clear});
}
global.AnyTourHotelKnowledgeView=Object.freeze({mount});
})(window);

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
let offerDetailsOpen=false,photoEditOffer='',bulkPrice=null,printProposal=null,imageExport=null,imageDownload=null;
let comparison=null,tripPanel=null,previewIssues=[],photoUpload=null,hotelKnowledge=null,biblioPanel=null,oneTouchPanel=null,appearancePanel=false;
let offerComparison=null;
// View reads are cancellable independently of the durable send/save context.
let publication=null,preview=false,sending=false,photoFailures=0,photoPending=0,photoEpoch=0,sharing=false,interestLoading=false,historyView=0;
function capture(c){return host.capture(c)}
function current(target=context){return host.isCurrent(target)}
function key(c=context){return c?.actor&&c?.conversation?'workspaceV2.tourSelection.'+c.actor+'.'+c.conversation:''}
function clone(value){return JSON.parse(JSON.stringify(value))}
function id(){const data=new Uint8Array(16);crypto.getRandomValues(data);return Array.from(data,n=>n.toString(16).padStart(2,'0')).join('')}
function empty(){return {title:'Подборка туров',intro:'',offers:[]}}
const appearancePalettes={forest:'#087654',ocean:'#1766a1',sunset:'#a14427'};
function appearanceSettings(value){
  const valid=value&&['photo','compact'].includes(value.template)&&Object.hasOwn(appearancePalettes,value.palette)&&typeof value.agency_name==='string';
  return valid?{template:value.template,palette:value.palette,agency_name:value.agency_name,accent:appearancePalettes[value.palette]}:{template:'legacy',palette:'forest',agency_name:'AnyTour',accent:appearancePalettes.forest};
}
function applyAppearance(root,value){
  for(const key of ['photo','compact'])root.classList.remove('tsTemplate-'+key);
  for(const key of Object.keys(appearancePalettes))root.classList.remove('tsPalette-'+key);
  const a=appearanceSettings(value);if(a.template!=='legacy')root.classList.add('tsTemplate-'+a.template,'tsPalette-'+a.palette);
}
function showAppearance(){if(!editable()||busy||tripPanel)return;historyView++;recovery('');appearancePanel=true;render();$('tsSelectionTitle')?.focus()}
function renderAppearance(){
  const root=$('tsEditor'),a=appearanceSettings(draft.appearance);
  root.innerHTML='<section class="tsAppearance"><h3>Оформление подборки</h3><p>Заголовок, вступление и оформление сохраняются вместе с версией подборки. Уже подготовленные версии не меняются.</p><label>Заголовок подборки<input id="tsSelectionTitle" maxlength="120"></label><label>Вступление для клиента<textarea id="tsSelectionIntro" rows="3" maxlength="1200"></textarea></label><label>Макет<select id="tsAppearanceTemplate"><option value="">Стандартное AnyTour</option><option value="photo">С фото</option><option value="compact">Для сравнения</option></select></label><label>Цвет<select id="tsAppearancePalette"><option value="forest">Зелёный</option><option value="ocean">Синий</option><option value="sunset">Терракотовый</option></select></label><label>Название агентства<input id="tsAppearanceAgency" maxlength="80"></label><p>Выберите макет, чтобы задать название агентства и цвет. Все фотографии, цены, условия и предупреждения сохраняются в любом макете.</p><button id="tsAppearanceBack" type="button">К вариантам</button></section>';
  $('tsSelectionTitle').value=draft.title;$('tsSelectionIntro').value=draft.intro;$('tsAppearanceAgency').value=a.agency_name;
  $('tsAppearanceTemplate').value=a.template==='legacy'?'':a.template;$('tsAppearancePalette').value=a.palette;
  $('tsSelectionTitle').oninput=e=>{if(!editable()||busy)return;draft.title=e.target.value.trim()||'Подборка туров';changed()};
  $('tsSelectionIntro').oninput=e=>{if(!editable()||busy)return;draft.intro=e.target.value;changed()};
  const update=()=>{if(!editable()||busy)return;const template=$('tsAppearanceTemplate').value;if(!template)delete draft.appearance;else draft.appearance={template,palette:$('tsAppearancePalette').value,agency_name:$('tsAppearanceAgency').value.trim()||'AnyTour'};changed()};
  $('tsAppearanceTemplate').onchange=()=>{update();render()};$('tsAppearancePalette').onchange=update;$('tsAppearanceAgency').oninput=update;
  $('tsAppearanceBack').onclick=()=>{if(busy)return;appearancePanel=false;render();$('tsAppearance')?.focus()};
}
function canCompareOffers(){return current()&&dialog?.open&&draft?.offers.length>=2&&!loading&&!busy&&!pending&&!conflict&&!preview&&!sending&&!sharing&&!photoUpload&&!bulkPrice&&!tripPanel&&!biblioPanel&&!oneTouchPanel&&!appearancePanel}
function comparisonCurrent(job=offerComparison){return !!job&&job===offerComparison&&job.epoch===epoch&&current(job.context)&&dialog?.open}
function showOfferComparison(){
  if(!canCompareOffers()||offerComparison)return;
  clearTimeout(timer);timer=0;historyView++;recovery('');
  const included=draft.offers.filter(offer=>offer.selected),choices=included.length>=2?included:draft.offers;
  offerComparison={epoch,context,version,dirty,draft:clone(draft),rawAges:clone(rawAges),ids:new Set(choices.slice(0,2).map(offer=>offer.id))};
  render();$('tsOfferCompareTitle')?.focus();
}
function comparisonTripSignature(offer,job){
  const ages=Object.hasOwn(job.rawAges,offer.id)&&!validAges(job.rawAges[offer.id])?{invalid:String(job.rawAges[offer.id])}:[...(offer.child_ages||[])].sort((a,b)=>a-b);
  return JSON.stringify([offer.currency,offer.date_from,offer.nights,offer.adults,ages]);
}
function comparisonParty(offer,job){
  const adults=Number(offer.adults),parts=[Number.isInteger(adults)&&adults>0&&adults<=20?adults+' взр.':'Взрослые не указаны'];
  if(Object.hasOwn(job.rawAges,offer.id)){
    const raw=String(job.rawAges[offer.id]);if(raw.trim())parts.push(validAges(raw)?'дети: '+raw:'Возраст детей требует исправления: '+raw);
  }else if(offer.child_ages?.length)parts.push('дети: '+offer.child_ages.join(', '));
  return parts.join(' · ');
}
function comparisonAmount(value,currency,qualifier){
  if(value==null||String(value).trim()==='')return 'Цена не указана';
  try{const units=priceUnits(value);if(units<=0n)return 'Цена не указана';return overviewAmount(String(units/100n)+'.'+String(units%100n).padStart(2,'0'),currency,qualifier)}catch{return 'Проверьте цену: '+String(value)}
}
function renderOfferComparison(){
  const job=offerComparison,root=$('tsOfferCompare');if(!comparisonCurrent(job)||!root)return;
  root.hidden=false;dialog.classList.add('tsOfferCompareMode');
  if(!root.firstChild){
    root.innerHTML='<div class="tsCompareTools"><h3 id="tsOfferCompareTitle" tabindex="-1">Сравнение вариантов</h3><p>Только просмотр черновика. Цены, условия и включение в подборку не меняются.</p><details id="tsCompareChooser"><summary id="tsCompareChoiceCount"></summary><fieldset id="tsCompareChoices"><legend>Выберите от двух до четырёх вариантов</legend></fieldset></details></div><div id="tsCompareScroll" class="tsCompareScroll" tabindex="0" role="region" aria-label="Сравнение условий; таблица прокручивается"></div><div class="tsCompareFooter"><button id="tsCompareBack" type="button">К вариантам</button></div>';
    for(const [index,offer]of job.draft.offers.entries()){
      const label=document.createElement('label'),input=document.createElement('input'),text=document.createElement('span');input.type='checkbox';input.dataset.tsCompareOffer=offer.id;input.checked=job.ids.has(offer.id);
      text.textContent=(index+1)+'. '+(offer.hotel||'Без названия')+(offer.selected?'':' · не включён в подборку');label.append(input,text);$('tsCompareChoices').append(label);
      input.onchange=()=>{if(!comparisonCurrent(job))return;if(input.checked&&job.ids.size>=4){input.checked=false;return}if(input.checked)job.ids.add(offer.id);else job.ids.delete(offer.id);renderComparedOffers(job)};
    }
    $('tsCompareBack').onclick=()=>{if(comparisonCurrent(job))closeOfferComparison()};
  }
  renderComparedOffers(job);
}
function renderComparedOffers(job){
  if(!comparisonCurrent(job))return;
  $('tsCompareChoiceCount').textContent='Выбрать варианты · '+job.ids.size+' из '+job.draft.offers.length;
  for(const input of $('tsCompareChoices').querySelectorAll('input'))input.disabled=job.ids.size>=4&&!input.checked;
  const root=$('tsCompareScroll'),offers=job.draft.offers.filter(offer=>job.ids.has(offer.id));root.replaceChildren();
  if(offers.length<2){const hint=document.createElement('p');hint.textContent='Отметьте ещё вариант для сравнения.';root.append(hint);return}
  const table=document.createElement('table');table.className='tsCompareTable tsCompareCount'+offers.length;
  const caption=document.createElement('caption');caption.setAttribute('role','status');caption.setAttribute('aria-live','polite');
  const signatures=offers.map(offer=>comparisonTripSignature(offer,job));
  caption.textContent=(job.dirty?'Показан черновик с несохранёнными правками. ':'Показана сохранённая версия '+job.version+'. ')+(new Set(signatures).size>1?'Различаются валюта, даты, длительность или состав — сопоставляйте полные условия. ':'Проверьте номер, питание и полную стоимость. ')+'Цену и наличие нужно подтвердить у оператора; незаполненное остаётся неизвестным.';
  table.append(caption);const head=document.createElement('thead'),header=document.createElement('tr'),term=document.createElement('th');term.scope='col';term.textContent='Условия';header.append(term);
  for(const offer of offers){
    const cell=document.createElement('th');cell.scope='col';const title=document.createElement('strong'),included=document.createElement('small'),open=document.createElement('button');
    const position=job.draft.offers.findIndex(item=>item.id===offer.id)+1;title.textContent='Вариант '+position+' · '+(offer.hotel||'Без названия');included.textContent=offer.selected?'В подборке':'Не включён в подборку';open.type='button';open.dataset.tsCompareOpen=offer.id;open.textContent='К условиям';open.setAttribute('aria-label','Открыть условия варианта '+position+' '+(offer.hotel||''));
    open.onclick=()=>{if(!comparisonCurrent(job))return;const id=offer.id;if(!draft.offers.some(item=>item.id===id))return;closeOfferComparison(false);selected=id;offerDetailsOpen=true;render();$('ts-hotel')?.focus();resumeComparisonAutosave()};cell.append(title,included,open);header.append(cell);
  }
  head.append(header);table.append(head);const body=document.createElement('tbody');
  const rows=[['Направление',offer=>offer.destination||'Не указано'],['Вылет из',offer=>offer.departure||'Не указан'],['Дата вылета',offer=>offer.date_from||'Не указана'],['Ночей',offer=>offer.nights||'Не указано'],['Состав',offer=>comparisonParty(offer,job)],['Номер',offer=>offer.room||'Не указан'],['Питание',offer=>offer.meal||'Не указано'],['Цена за весь состав',offer=>comparisonAmount(offer.amount,offer.currency,offer.price_qualifier)],['Исходная цена',offer=>offer.import_source?.fields?comparisonAmount(offer.import_source.fields.amount,offer.import_source.fields.currency,offer.import_source.fields.price_qualifier)+(offer.import_source.adapter==='tourvisor-snapshot-v1'?' за номер':' за весь состав'):'Нет сохранённой цены источника'],['Цена получена',offer=>offer.import_source?.observed_at||'Дата источника не сохранена'],['Рейсы',offer=>offer.flights||'Не указаны'],['Включено',offer=>offer.inclusions||'Не указано'],['Важные условия',offer=>offer.constraints||'Не указаны'],['Пояснение менеджера',offer=>offer.rationale||'Не указано'],['Компромиссы',offer=>offer.compromise||'Не указаны'],['Фотографии',offer=>offer.photos?.length?offer.photos.length+' фото; интерьер выбранного номера требует проверки':'Фото не добавлены']];
  for(const [label,value]of rows){const row=document.createElement('tr'),name=document.createElement('th');name.scope='row';name.textContent=label;row.append(name);for(const offer of offers){const cell=document.createElement('td');cell.textContent=String(value(offer));row.append(cell)}body.append(row)}
  table.append(body);root.append(table);
}
function resumeComparisonAutosave(){if(dirty&&current()&&dialog?.open)timer=setTimeout(()=>{if(editable()&&!busy&&!invalidAges()&&dialog?.open)void save()},900)}
function closeOfferComparison(resume=true){
  if(!offerComparison)return;offerComparison=null;const root=$('tsOfferCompare');root?.replaceChildren();if(root)root.hidden=true;dialog?.classList.remove('tsOfferCompareMode');
  if(resume&&current()&&dialog?.open){render();$('tsCompareOffers')?.focus();resumeComparisonAutosave()}
}
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
  if(!data||Date.now()-Number(data.at||0)>86400000&&data.pending?.kind!=='biblio'||!bounded(data.draft)||JSON.stringify(data).length>900000||data.conflict&&!bounded(data.conflict.draft)||data.comparison&&(!data.conflict||!bounded(data.comparison.draft)))return false;
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
function close(){closeOfferComparison(false);appearancePanel=false;cancelImageExport();clearPrint();closeBulkPrices(false);clearHotelKnowledge();historyView++;cancelPhotoUpload(false);tripPanel=null;biblioPanel=null;clearOneTouchCapture(true);clearTimeout(timer);timer=0;stash();if(dialog?.open)dialog.close()}
function editCurrent(){
  appearancePanel=false;
  cancelImageExport();historyView++;preview=false;render();recovery(conflict?'conflict':pending?'pending':'');
  status(conflict?'Есть конфликт правок. Сравните версии перед сохранением.':pending?'Результат сохранения уточняется. Сначала проверьте его.':!canEdit?'Просмотр текущего черновика. Править может ответственный менеджер или администратор.':dirty?'Текущий черновик. Есть несохранённые изменения.':'Текущий черновик. Изменения не отправляют сообщения.',!!conflict||!!pending);
}
function suspend(){closeOfferComparison(false);cancelImageExport();clearPrint();closeBulkPrices(false);clearHotelKnowledge();cancelPhotoUpload(false);photoEditOffer='';tripPanel=null;biblioPanel=null;clearOneTouchCapture(true);previewIssues=[];stash();clearTimeout(timer);timer=0;epoch++;context=null;draft=null;pending=null;conflict=null;comparison=null;dirty=false;busy=false;loading=false;canEdit=false;publication=null;preview=false;sending=false;sharing=false;if(dialog?.open)dialog.close();$('tourSelectionOpen')?.remove()}
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
  const biblio=document.createElement('button');biblio.id='tsBiblio';biblio.type='button';biblio.textContent='Библио-Глобус';biblio.onclick=showBiblio;actions.append(biblio);
  const oneTouch=document.createElement('button');oneTouch.id='tsOneTouch';oneTouch.type='button';oneTouch.textContent='OneTouch';oneTouch.onclick=showOneTouch;actions.append(oneTouch);
  const appearance=document.createElement('button');appearance.id='tsAppearance';appearance.type='button';appearance.textContent='Оформление';appearance.onclick=showAppearance;actions.append(appearance);
  const compare=document.createElement('button');compare.id='tsCompareOffers';compare.type='button';compare.textContent='Сравнить';compare.setAttribute('aria-label','Сравнить варианты');compare.setAttribute('aria-controls','tsOfferCompare');compare.onclick=showOfferComparison;actions.append(compare);
  const comparisonPanel=document.createElement('section');comparisonPanel.id='tsOfferCompare';comparisonPanel.className='tsOfferCompare';comparisonPanel.hidden=true;comparisonPanel.setAttribute('aria-labelledby','tsOfferCompareTitle');dialog.querySelector('.tsLayout').before(comparisonPanel);
  const prices=document.createElement('button');prices.id='tsBulkPrices';prices.type='button';prices.className='tsBulkButton';prices.textContent='Цены';prices.title='Изменить несколько цен';prices.setAttribute('aria-label','Изменить несколько цен');prices.onclick=showBulkPrices;actions.append(prices);
  const bulk=document.createElement('section');bulk.id='tsBulkPrice';bulk.className='tsBulkPrice';bulk.hidden=true;bulk.setAttribute('aria-labelledby','tsBulkTitle');dialog.querySelector('.tsLayout').before(bulk);
  if(typeof host.openStandalone==='function'){const standalone=document.createElement('button');standalone.id='tsStandalone';standalone.type='button';standalone.textContent='Открыть отдельно';standalone.onclick=()=>{if(current())host.openStandalone(context.conversation)};actions.append(standalone)}
  for(const [name,label,handler] of [['tsHistory','История отправки',showHistory],['tsPreview','Предпросмотр',showPreview],['tsEdit','К редактированию',editCurrent],['tsPrint','Печать / PDF',printPreview],['tsShare','Общая ссылка',showShare],['tsSend','Отправить подборку',sendAll]]){const button=document.createElement('button');button.id=name;button.type='button';button.textContent=label;button.onclick=handler;footer.insertBefore(button,$('tsDone'))}
  window.addEventListener('afterprint',()=>{clearPrint();controls()});
  const headActions=document.createElement('div');headActions.className='tsHeadActions';$('tsClose').before(headActions);headActions.append($('tsHistory'),$('tsClose'));$('tsHistory').textContent='История';$('tsHistory').setAttribute('aria-label','История отправки');
  $('tsSave').classList.remove('tsPrimary');$('tsPreview').classList.add('tsPrimary');$('tsSend').classList.add('tsPrimary');
  $('tsClose').onclick=close;$('tsDone').onclick=()=>{if(preview)close();else if(dirty&&!busy&&!pending&&!conflict)void save();else close()};$('tsSave').onclick=()=>save();$('tsAdd').onclick=add;
  dialog.addEventListener('cancel',e=>{e.preventDefault();if(offerComparison)closeOfferComparison();else close()});dialog.addEventListener('keydown',e=>e.stopPropagation());
  dialog.addEventListener('close',()=>{if(current()&&returnFocus?.isConnected)returnFocus.focus({preventScroll:true})});
  $('tsList').addEventListener('click',event=>{const button=event.target.closest('[data-ts-select]');if(button){selected=button.dataset.tsSelect;render()}});
  $('tsEditor').addEventListener('input',edit);$('tsEditor').addEventListener('change',edit);
  $('tsEditor').addEventListener('click',event=>{
    const photoMode=event.target.closest('[data-ts-photo-mode]');if(photoMode){if(editable()&&!busy&&item()?.import_source){photoEditOffer=photoMode.dataset.tsPhotoMode==='manual'?selected:'';offerDetailsOpen=true;render()}return}
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
  close();tripPanel=null;previewIssues=[];epoch++;context=capture(c);version=0;draft=empty();selected='';dirty=false;busy=false;pending=null;conflict=null;comparison=null;rawAges={};canEdit=false;publication=null;preview=false;sending=false;sharing=false;interestLoading=false;offerDetailsOpen=false;photoEditOffer='';restore();offerDetailsOpen=dirty||!!pending||!!conflict;ensure();returnFocus=$('tourSelectionOpen')?.closest('details')?.querySelector('summary')||$('tourSelectionOpen');
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
  tripPanel=null;draft.offers.push(offer);selected=offer.id;offerDetailsOpen=true;changed();render();$('ts-hotel')?.focus();
}
const oneTouchPendingFiles=new Map();
function oneTouchDropPreview(panel){for(const photo of panel?.review?.localPhotos||[])URL.revokeObjectURL(photo.preview)}
function clearOneTouchCapture(dropFiles=false){oneTouchDropPreview(oneTouchPanel);oneTouchPanel=null;if(dropFiles){for(const saved of oneTouchPendingFiles.values())for(const photo of saved.photos)URL.revokeObjectURL(photo.preview);oneTouchPendingFiles.clear()}}
function oneTouchCaptureField(panel,editor){
  const label=document.createElement('label');label.textContent='Файл предложений OneTouch';const input=document.createElement('input');input.type='file';input.accept='application/json,.json';input.id='tsOneTouchFile';input.onchange=()=>void oneTouchChooseCapture(panel,input.files?.[0]);label.append(input);editor.querySelector('h3').after(label);
  const notice=document.createElement('p');notice.textContent=panel.bundle?'Файл: '+panel.fileName+'. Время цены из файла: '+panel.bundle.source.captured_at+'. Фото могут быть получены раньше цены.':'Выберите полученный файл источника. Цена и время в файле указаны его автором; окончательная стоимость и наличие требуют проверки.';label.after(notice);
  const resume=document.createElement('button');resume.type='button';resume.id='tsOneTouchResumePhotos';resume.textContent='Дополнить фото сохранённого варианта';resume.hidden=!panel.bundle||item()?.import_source?.raw?.capture_mode!=='manager-file-v1';resume.onclick=()=>void oneTouchResumePhotos(panel);notice.after(resume);
}
async function oneTouchChooseCapture(panel,file){
  if(!oneTouchCurrent(panel)||busy||!file)return;oneTouchDropPreview(panel);panel.review=null;panel.offers=[];panel.bundle=null;
  try{
    if(file.size<1||file.size>8388608)throw Error('Файл слишком большой или пустой.');
    const bundle=JSON.parse(await file.text());if(!oneTouchCurrent(panel))return;
    if(bundle?.schema!=='onetouch-bundle-v1'||!bundle.source||bundle.source.schema!=='onetouch-manager-capture-v1'||!Array.isArray(bundle.gallery_cache)||!bundle.gallery_cache.length||bundle.gallery_cache.length>20)throw Error('Не удалось прочитать файл OneTouch.');
    const url=new URL(bundle.source.url);if(url.origin!=='https://b2b.onetouch.travel'||url.pathname!=='/search_tour')throw Error('Источник файла не соответствует OneTouch.');
    const seen=new Set();for(const g of bundle.gallery_cache){if(typeof g.url!=='string'||seen.has(g.url)||typeof g.html_gzip_base64!=='string'||!Array.isArray(g.images)||!g.images.length||g.images.length>3)throw Error('В файле не хватает связанных фотографий.');seen.add(g.url)}
    panel.bundle=bundle;panel.fileName=file.name;const d=url.searchParams.get('CHECKIN_BEG')||'';panel.date=d.slice(0,4)+'-'+d.slice(4,6)+'-'+d.slice(6,8);panel.nights=Number(url.searchParams.get('NIGHTS_FROM'));panel.message='Файл выбран. Прочитайте предложения перед добавлением.';panel.error=false;
  }catch(e){panel.message=e.message||'Файл не прочитан.';panel.error=true}render();
}
function oneTouchCaptureHeader(bundle){
  const source=clone(bundle.source);source.galleries=bundle.gallery_cache.map(g=>({hotel:String(g.hotel),url:g.url,observed_at:g.observed_at,html_sha256:g.html_sha256,images:g.images.map(p=>({url:p.url,sha256:p.sha256}))}));
  if(new TextEncoder().encode(JSON.stringify(source)).length>90000)throw Error('Данные источника превышают допустимый размер файла.');return source;
}
function oneTouchGallery(bundle,raw){const list=bundle.gallery_cache.filter(g=>g.url===raw.hotel_url&&g.html_sha256===raw.capture_gallery_sha256);if(list.length!==1)throw Error('Галерея не соответствует сохранённому предложению.');return list[0]}
async function oneTouchLocalPhotos(panel,review,gallery){
  const hashes=JSON.parse(review.raw.capture_photo_sha256),prepared=[];
  try{for(const url of review.photos){
    const images=gallery.images.filter(p=>p.url===url);if(images.length!==1)throw Error('В файле нет фотографии этого варианта.');const image=images[0];
    if(image.mime!=='image/jpeg'||typeof image.base64!=='string'||image.base64.length>2796204||image.sha256!==hashes[url])throw Error('Фотография не соответствует файлу источника.');
    const bytes=Uint8Array.from(atob(image.base64),c=>c.charCodeAt(0));if(bytes.length<3||bytes.length>2097152||bytes[0]!==255||bytes[1]!==216||bytes[2]!==255)throw Error('Недопустимый размер фотографии.');
    const hash=Array.from(new Uint8Array(await crypto.subtle.digest('SHA-256',bytes)),x=>x.toString(16).padStart(2,'0')).join('');
    if(hash!==image.sha256||!oneTouchCurrent(panel))throw Error('Фотография или контекст изменились.');
    prepared.push({source:url,bytes,sha256:hash,preview:URL.createObjectURL(new Blob([bytes],{type:'image/jpeg'}))});
  }return prepared;}catch(e){for(const p of prepared)URL.revokeObjectURL(p.preview);throw e}
}
function oneTouchRememberPhotos(command,panel){
  oneTouchPendingFiles.set(command.operation_id,{target:clone(context),epoch,raw:clone(panel.review.raw),offer:clone(panel.review.offer),photos:panel.review.localPhotos});
}
async function oneTouchFinishPhotos(operation,offerId){
  const saved=oneTouchPendingFiles.get(operation);if(!saved)return false;
  if(!current(saved.target)||epoch!==saved.epoch||pending||conflict||dirty||busy||!dialog?.open)return false;
  const matches=draft.offers.filter(o=>o.import_source?.adapter==='onetouch-public-v1'&&o.import_source.raw.capture_reference===saved.raw.capture_reference&&o.import_source.raw.hotel_url===saved.raw.hotel_url&&['hotel','date_from','nights','room','meal','amount'].every(k=>o.import_source.fields[k]===saved.offer[k])&&o.import_source.raw.tariff===saved.raw.tariff&&o.import_source.raw.tour===saved.raw.tour);
  const offer=offerId?matches.find(o=>o.id===offerId):matches.length===1?matches[0]:null;if(!offer){status('Тур сохранён; привязку фотографий нужно проверить. Повторного импорта нет.',true);return false}
  selected=offer.id;render();
  for(let i=0;i<saved.photos.length;i++){
    if(!current(saved.target)||epoch!==saved.epoch||pending||conflict||dirty||busy||photoUpload||!dialog?.open||item()?.id!==offer.id)return false;
    const kind=['cover','beach','room'][i],photo=saved.photos[i];if(item().photos.some(p=>p.kind===kind))continue;
    photoUpload={target:capture(),epoch,offer:offer.id,kind,version,operation:id(),file:new File([photo.bytes],'OneTouch-photo-'+(i+1)+'.jpg',{type:'image/jpeg'}),sourcePhotoUrl:photo.source,encoded:null,hash:null,nextPart:0,progress:0,controller:null,loading:false};
    await submitPhotoUpload();if(photoUpload||pending||conflict||dirty)return false;
  }
  for(const photo of saved.photos)URL.revokeObjectURL(photo.preview);oneTouchPendingFiles.delete(operation);status('OneTouch сохранён с фотографиями из файла. Сообщения туристу не отправлялись.');return true;
}
async function oneTouchResumePhotos(panel){
  if(!oneTouchCurrent(panel)||busy||!panel.bundle)return;const offer=item(),source=offer?.import_source;
  if(source?.adapter!=='onetouch-public-v1'||source.raw.capture_reference!==panel.bundle.source.reference)return;
  try{const review={raw:source.raw,photos:source.photos},gallery=oneTouchGallery(panel.bundle,source.raw);review.localPhotos=await oneTouchLocalPhotos(panel,review,gallery);const operation=id();oneTouchPendingFiles.set(operation,{target:clone(context),epoch,raw:clone(source.raw),offer:clone(source.fields),photos:review.localPhotos});clearOneTouchCapture();render();await oneTouchFinishPhotos(operation,offer.id)}catch(e){panel.message=e.message;panel.error=true;render()}
}

function oneTouchCurrent(panel){return oneTouchPanel===panel&&current(panel.target)&&epoch===panel.epoch&&version===panel.version&&canEdit&&!dirty&&!pending&&!conflict&&!preview&&dialog?.open}
function oneTouchError(result){return {onetouch_capture_required:'Выберите файл источника OneTouch.',onetouch_capture_expired:'Время цены в файле старше допуска приложения. Исходная дата не обновляется.',onetouch_capture_invalid:'Файл источника или связанные данные повреждены.',onetouch_invalid_url:'Проверьте дату и число ночей.',onetouch_source_unavailable:'OneTouch не ответил. Сохранённые варианты не изменены.',onetouch_source_changed:'Условия на сайте изменились или не поддерживаются. Варианты не добавлены.',onetouch_photos_unavailable:'Не удалось подтвердить фотографии именно этого отеля.',onetouch_expired:'Истекло время проверки предложения. Добавление не выполнено.',onetouch_price_confirmation:'Подтвердите, что это минимальная цена «от».',source_price_changed:'Исходная цена такого варианта изменилась. Проверьте сохранённый вариант.',manual_price_conflict:'У сохранённого варианта другая ручная цена.',ambiguous_offer:'Похожий вариант уже есть с другими условиями.'}[result?.error]||error(result)}
async function showOneTouch(){
  if(!editable()||busy||!current()||(draft?.offers.length||0)>=20)return;
  if(dirty){await save();if(dirty||pending||conflict||busy||!current())return;}
  const trip=draft.offers.find(x=>x.date_from&&x.nights);
  oneTouchPanel={target:clone(context),epoch,version,date:trip?.date_from||'',nights:trip?.nights||7,offers:[],offset:0,review:null,message:'',error:false};render();$('tsOneTouchDate')?.focus();
}
function oneTouchQuery(date,nights){
  if(!/^\d{4}-\d{2}-\d{2}$/.test(date)||!Number.isInteger(nights)||nights<1||nights>60)throw Error('Проверьте дату и число ночей.');
  const q={samo_action:'PRICES',TOWNFROMINC:'2',STATEINC:'9',TOURTYPE:'0',TOURINC:'0',PROGRAMGROUPINC:'0',PROGRAMINC:'0',CHECKIN_BEG:date.replaceAll('-',''),NIGHTS_FROM:String(nights),CHECKIN_END:date.replaceAll('-',''),NIGHTS_TILL:String(nights),ADULT:'2',CURRENCY:'1',CHILD:'0',TOWNS_ANY:'1',townssearch:'0',TOWNS:'',STARS_ANY:'1',STARS:'',HOTELS_ANY:'1',hotelsearch:'0',HOTELS:'',MEALS_ANY:'1',MEALS:'',ROOMS_ANY:'1',ROOMS:'',CHILD_IN_BED:'0',FREIGHT:'1',COMFORTABLE_SEATS:'0',FILTER:'1',MOMENT_CONFIRM:'0',WITHOUT_PROMO:'0',HOTELTYPES:'',PARTITION_PRICE:'',PRICEPAGE:'1',DYN_SEPARATE:'1',rev:'965964269'};
  return 'https://b2b.onetouch.travel/search_tour?'+new URLSearchParams(q);
}
function validOneTouchRow(row){return row&&typeof row.ticket==='string'&&row.ticket.length<=32768&&row.offer&&typeof row.offer.hotel==='string'&&typeof row.offer.amount==='string'&&row.offer.price_qualifier==='from'&&row.offer.currency==='RUB'&&row.offer.adults===2&&Array.isArray(row.offer.child_ages)&&!row.offer.child_ages.length&&row.raw&&['transport','tour','tariff','availability'].every(k=>typeof row.raw[k]==='string')}
function oneTouchPhotosReady(panel){const photos=panel?.review?.localPhotos,images=Array.from($('tsOneTouchResults')?.querySelectorAll('img')||[]);return !!photos?.length&&images.length===photos.length&&images.every((img,i)=>img.isConnected&&img.getAttribute('src')===photos[i].preview&&photos[i].source===panel.review.photos[i]&&biblioImageReady(img))}
function oneTouchControls(){
  const panel=oneTouchPanel;if(!panel)return;const locked=busy||loading||!oneTouchCurrent(panel);
  $('tsEditor')?.querySelectorAll('input,button').forEach(node=>{node.disabled=locked});
  $('tsOneTouchDate')&&( $('tsOneTouchDate').disabled=true );$('tsOneTouchNights')&&( $('tsOneTouchNights').disabled=true );$('tsOneTouchRead')&&( $('tsOneTouchRead').disabled=locked||!panel.bundle );
  const add=$('tsOneTouchAdd');if(add)add.disabled=locked||!$('tsOneTouchConfirm')?.checked||!oneTouchPhotosReady(panel);
  const state=$('tsOneTouchPhotoStatus');if(state)state.textContent=oneTouchPhotosReady(panel)?'Фотографии загружены.':'Ожидаем фотографии. При ошибке добавление недоступно.';
}
function renderOneTouch(){
  const panel=oneTouchPanel,editor=$('tsEditor');if(!panel||!editor)return;
  editor.innerHTML='<section class="tsBiblioPanel"><h3>Предложения OneTouch</h3><p>Поддержанный поиск: Москва → Турция, 2 взрослых без детей. Цена на странице — минимальная «от». Рейсы, багаж, доплаты и окончательная стоимость требуют проверки.</p><label>Дата вылета<input id="tsOneTouchDate" type="date" value="'+esc(panel.date)+'"></label><label>Ночей<input id="tsOneTouchNights" type="number" min="1" max="60" value="'+esc(panel.nights)+'"></label><button id="tsOneTouchRead" type="button">Прочитать предложения</button><p id="tsOneTouchStatus" role="status" class="'+(panel.error?'tsError':'')+'">'+esc(busy?'Читаем OneTouch…':panel.message)+'</p><div id="tsOneTouchResults"></div><button id="tsOneTouchCancel" type="button">Вернуться к подборке</button></section>';
  oneTouchCaptureField(panel,editor);
  const results=$('tsOneTouchResults'),review=panel.review;
  if(review){
    const o=review.offer;
    results.innerHTML='<article><h4>'+esc(o.hotel+(o.stars?' '+o.stars+'*':''))+'</h4><p><strong>Исходная цена: '+esc(overviewAmount(o.amount,o.currency,'from'))+'</strong></p><dl class="tsBiblioTerms">'+[['Поездка',[o.destination,o.departure,o.date_from,o.nights+' ноч.'].join(' · ')],['Состав','2 взрослых'],['Номер и размещение',o.room],['Питание',o.meal],['Тур',review.raw.tour],['Перевозка',review.raw.transport],['Тариф',review.raw.tariff],['Ограничения',review.raw.availability]].map(([k,v])=>'<div><dt>'+esc(k)+'</dt><dd>'+esc(v)+'</dd></div>').join('')+'</dl><div class="tsBiblioPhotos">'+(review.localPhotos||[]).map(photo=>'<img src="'+esc(photo.preview)+'" alt="Фото отеля '+esc(o.hotel)+'" referrerpolicy="no-referrer">').join('')+'</div><p id="tsOneTouchPhotoStatus" role="status"></p><p>Фото относятся к отелю. Категория номера и включённые услуги по ним не подтверждены.</p><label>Минимальная цена для клиента, RUB<input id="tsOneTouchAmount" type="text" inputmode="decimal" maxlength="12" value="'+esc(o.amount)+'"></label><label class="tsTripConfirm"><input id="tsOneTouchConfirm" type="checkbox"> Проверил состав и условия. Сохраняю минимальную цену «от», окончательная стоимость требует проверки.</label><button id="tsOneTouchAdd" type="button" disabled>Добавить с фото и условиями</button><button id="tsOneTouchBack" type="button">К предложениям</button></article>';
    results.querySelectorAll('img').forEach(img=>{img.onload=img.onerror=()=>{if(oneTouchPanel===panel&&panel.review===review)oneTouchControls()}});
    $('tsOneTouchConfirm').onchange=oneTouchControls;$('tsOneTouchAdd').onclick=()=>void addOneTouch(panel);
    $('tsOneTouchBack').onclick=()=>{if(busy)return;panel.review=null;render()};
  }else{
    panel.offers.slice(panel.offset,panel.offset+20).forEach(row=>{const card=document.createElement('article');card.innerHTML='<h4>'+esc(row.offer.hotel)+'</h4><p>'+esc([row.offer.date_from,row.offer.nights+' ноч.',row.offer.room,row.offer.meal].join(' · '))+'</p><strong>'+esc(overviewAmount(row.offer.amount,row.offer.currency,'from'))+'</strong>';const select=document.createElement('button');select.type='button';select.textContent='Проверить фото и условия';select.onclick=()=>void reviewOneTouch(panel,row);card.append(select);results.append(card)});
    for(const [label,offset] of [['Предыдущие',panel.offset-20],['Следующие',panel.offset+20]])if(offset>=0&&offset<panel.offers.length){const button=document.createElement('button');button.type='button';button.textContent=label;button.onclick=()=>{if(oneTouchCurrent(panel)&&!busy){panel.offset=offset;render()}};results.append(button)}
  }
  $('tsOneTouchDate').oninput=()=>{panel.date=$('tsOneTouchDate').value};$('tsOneTouchNights').oninput=()=>{panel.nights=Number($('tsOneTouchNights').value)};
  $('tsOneTouchRead').onclick=()=>void readOneTouch(panel);$('tsOneTouchCancel').onclick=()=>{if(!busy){oneTouchPanel=null;render()}};oneTouchControls();
}
async function readOneTouch(panel){
  if(!oneTouchCurrent(panel)||busy||!panel.bundle)return;let input;try{input=oneTouchCaptureHeader(panel.bundle)}catch(e){panel.message=e.message;panel.error=true;render();return}
  oneTouchDropPreview(panel);panel.review=null;busy=true;render();const result=await request('onetouch_search',{capture:input});if(epoch!==panel.epoch)return;busy=false;if(!oneTouchCurrent(panel)){controls();return}
  if(result?.ok&&result.version===panel.version&&Array.isArray(result.offers)&&result.offers.length>0&&result.offers.length<=250&&result.offers.every(validOneTouchRow)){panel.offers=result.offers;panel.offset=0;panel.message='Прочитано предложений с сохранённой галереей: '+result.offers.length+'. Время цены и фотографий сохранены отдельно.';panel.error=false}else{panel.offers=[];panel.message=oneTouchError(result);panel.error=true}render();
}
async function reviewOneTouch(panel,row){
  if(!oneTouchCurrent(panel)||busy||!panel.bundle)return;let gallery;try{gallery=oneTouchGallery(panel.bundle,row.raw)}catch(e){panel.message=e.message;panel.error=true;render();return}
  oneTouchDropPreview(panel);panel.review=null;busy=true;render();const result=await request('onetouch_review',{ticket:row.ticket,gallery_gzip_base64:gallery.html_gzip_base64});if(epoch!==panel.epoch)return;busy=false;if(!oneTouchCurrent(panel)){controls();return}
  if(result?.ok&&result.version===panel.version&&validOneTouchRow(result)&&Array.isArray(result.photos)&&result.photos.length>0&&result.photos.length<=3){try{result.localPhotos=await oneTouchLocalPhotos(panel,result,gallery);if(!oneTouchCurrent(panel)){for(const p of result.localPhotos)URL.revokeObjectURL(p.preview);return}panel.review=result;panel.message='Цена из файла: '+result.raw.capture_price_declared_at+'. Галерея получена: '+result.raw.capture_gallery_observed_at+'. Проверьте минимальную цену и условия.';panel.error=false}catch(e){panel.message=e.message;panel.error=true}}else{panel.message=oneTouchError(result);panel.error=true}render();
}
async function addOneTouch(panel){
  if(!oneTouchCurrent(panel)||busy||!panel.review||!$('tsOneTouchConfirm')?.checked||!oneTouchPhotosReady(panel))return;
  let amount;try{const units=priceUnits($('tsOneTouchAmount').value);if(units<=0n)throw Error();amount=String(units/100n)+(units%100n?'.'+String(units%100n).padStart(2,'0'):'')}catch(e){panel.message='Укажите положительную цену с точностью до двух знаков.';panel.error=true;$('tsOneTouchStatus').textContent=panel.message;return}
  const command={kind:'onetouch',operation_id:id(),ticket:panel.review.ticket,confirm_price_from:true,manager_amount:amount,dispatched:false};oneTouchRememberPhotos(command,panel);pending=command;clearOneTouchCapture();stash();render();return submitOneTouch(command);
}
async function submitOneTouch(command){
  if(!current()||busy||pending!==command)return;if(command.dispatched)return reconcileOneTouch();
  command.dispatched=true;const token=epoch;busy=true;stash();controls();const {kind,dispatched,...data}=command,result=await request('onetouch_add',data);if(token!==epoch)return;busy=false;
  if(result?.ok&&['added','already_added','saved'].includes(result.state)&&Number.isInteger(result.saved_version)){pending=null;dirty=false;if(typeof result.offer_id==='string')selected=result.offer_id;stash();await load();if(!await oneTouchFinishPhotos(command.operation_id,result.offer_id))status('Добавление OneTouch подтверждено. Завершите перенос фотографий к этому же варианту; повторный импорт не нужен.');return}
  if(result?.http_status&&result.http_status<500&&!['operation_mismatch','invalid_response'].includes(result.error)){pending=null;stash();render();status(oneTouchError(result),true);return}
  stash();controls();recovery('pending');status('Исход добавления не подтверждён. Повторное добавление не выполняется; проверьте сохранённую операцию.',true);
}
async function reconcileOneTouch(){
  if(!current()||busy||pending?.kind!=='onetouch')return;const token=epoch,command=pending;busy=true;controls();const {kind,dispatched,...data}=command,result=await request('onetouch_operation',data);if(token!==epoch)return;busy=false;
  if(result?.ok&&result.found===true&&Number.isInteger(result.saved_version)){pending=null;dirty=false;stash();await load();await oneTouchFinishPhotos(command.operation_id,result.offer_id);status('Сохранённая операция OneTouch подтверждена. Повторного добавления не было.');return}
  stash();controls();recovery('pending');status('Сохранение OneTouch пока не подтверждено. Повторное добавление не выполняется.',true);
}


function newOffer(){return {id:id(),selected:true,hotel:'',destination:'',departure:'',room:'',meal:'',source_label:'',rationale:'',compromise:'',flights:'',inclusions:'',constraints:'',stars:null,nights:null,adults:null,date_from:'',child_ages:[],amount:'',currency:'RUB',source_url:'',observed_at:'',photos:[]}}
function add(){if(!editable()||draft.offers.length>=20)return;const item=newOffer();draft.offers.push(item);selected=item.id;offerDetailsOpen=true;changed();render();$('ts-hotel')?.focus()}
function editable(){return current()&&canEdit&&!loading&&!pending&&!conflict&&!preview&&!sending&&!photoUpload&&!bulkPrice&&!biblioPanel&&!oneTouchPanel&&!offerComparison}

function biblioError(result){return {biblio_invalid_url:'Вставьте полную ссылку на результаты поиска Библио-Глобуса с городом вылета, датами и составом.',biblio_source_unavailable:'Сайт Библио-Глобуса не ответил. Варианты в подборке сохранены.',biblio_source_changed:'Не удалось однозначно прочитать условия на странице оператора.',biblio_no_quotes:'На этой странице не найдено предложений с однозначной ценой, составом и условиями.',biblio_photos_unavailable:'Фотографии именно этого отеля не подтверждены. Вариант не добавлен.',biblio_expired:'Цена прочитана более десяти минут назад. Прочитайте предложения заново.',biblio_price_confirmation:'Подтвердите цену за весь указанный состав.',source_price_changed:'У такого варианта изменилась исходная цена. Проверьте уже сохранённый вариант.',manual_price_conflict:'У этого варианта уже есть другая ручная цена. Проверьте сохранённый вариант.',ambiguous_offer:'Похожий вариант уже есть с другими условиями. Проверьте его перед добавлением.',selection_full:'В подборке уже 20 вариантов.',connection_changed:'Клиент или запрос изменился. Откройте подборку заново.'}[result?.error]||error(result)}
function biblioCurrent(panel){return biblioPanel===panel&&current(panel.target)&&epoch===panel.epoch&&version===panel.version&&canEdit&&!dirty&&!pending&&!conflict&&!preview&&dialog?.open}
async function showBiblio(){
  if(!editable()||busy||tripPanel||draft.offers.length>=20)return;
  const token=epoch;clearTimeout(timer);if(dirty)await save();
  if(token!==epoch||!editable()||busy||dirty||!dialog?.open)return;
  clearHotelKnowledge();historyView++;recovery('');
  biblioPanel={target:clone(context),epoch,version,url:'',offers:[],review:null,next:null,total:0,message:'',error:false};render();$('tsBiblioUrl')?.focus();
}
function biblioTerms(offer,raw){
  const party=offer.adults+' взр.'+(offer.child_ages?.length?' · дети: '+offer.child_ages.join(', '):'');
  return '<dl class="tsBiblioTerms">'+[['Поездка',[offer.destination,'вылет: '+offer.departure,offer.date_from,offer.nights+' ноч.'].join(' · ')],['Состав',party],['Номер',offer.room],['Питание',offer.meal],['Проживание',raw.stay_from+' — '+raw.stay_until],['Перевозка',raw.transport],['Включено',raw.services],['Состав пакета',raw.components],['Оговорки цены',raw.price_note||'Актуальность и наличие требуют проверки.']].map(([k,v])=>'<div><dt>'+esc(k)+'</dt><dd>'+esc(v)+'</dd></div>').join('')+'</dl>';
}
function validBiblioRow(row){return row&&typeof row.ticket==='string'&&row.ticket.length<=32768&&row.offer&&typeof row.offer.hotel==='string'&&typeof row.offer.amount==='string'&&['RUB','EUR','USD'].includes(row.offer.currency)&&Array.isArray(row.offer.child_ages)&&row.raw&&['stay_from','stay_until','transport','services','price_note'].every(k=>typeof row.raw[k]==='string')}
function renderBiblio(){
  const panel=biblioPanel,editor=$('tsEditor'),review=panel.review;
  editor.innerHTML='<section class="tsBiblioPanel"><h3>Предложения Библио-Глобуса</h3><p>Прочитайте публичные результаты поиска. Проверьте состав, цену и условия выбранного предложения перед добавлением. Сообщения клиенту не отправляются.</p><label>Ссылка на результаты поиска<input id="tsBiblioUrl" type="url" maxlength="4096" autocomplete="off" placeholder="https://www.bgoperator.ru/price.shtml?…" value="'+esc(panel.url)+'"></label><button id="tsBiblioRead" class="tsPrimary" type="button">Прочитать предложения</button><p id="tsBiblioStatus" role="status" class="'+(panel.error?'tsError':'')+'">'+esc(busy?'Читаем страницу оператора…':panel.message)+'</p><div id="tsBiblioResults"></div><button id="tsBiblioCancel" type="button">Вернуться к подборке</button></section>';
  const results=$('tsBiblioResults');
  if(review){
    results.innerHTML='<article><h4>'+esc(review.offer.hotel+(review.offer.stars?' '+review.offer.stars+'*':''))+'</h4><p><strong>Исходная цена: '+esc(overviewAmount(review.offer.amount,review.offer.currency,review.offer.price_qualifier))+'</strong><br>'+esc(review.offer.adults+' взр.'+(review.offer.child_ages.length?' · дети: '+review.offer.child_ages.join(', '):'')+' · '+review.offer.date_from+' · '+review.offer.nights+' ноч.')+'</p><div class="tsBiblioPhotos">'+review.photos.map(url=>'<img src="'+esc(url)+'" alt="Фото отеля '+esc(review.offer.hotel)+'" referrerpolicy="no-referrer">').join('')+'</div><p id="tsBiblioPhotoStatus" role="status"></p><p>Фото относятся к отелю. Категория номера по ним не подтверждена.</p>'+biblioTerms(review.offer,review.raw)+'<p><strong>Исходная цена: '+esc(overviewAmount(review.offer.amount,review.offer.currency,review.offer.price_qualifier))+'</strong></p><label>Цена для клиента, '+esc(review.offer.currency)+'<input id="tsBiblioAmount" type="text" inputmode="decimal" maxlength="12" value="'+esc(review.offer.amount)+'"></label><label class="tsTripConfirm"><input id="tsBiblioConfirm" type="checkbox"> Проверил: показанная исходная цена относится ко всему указанному составу</label><button id="tsBiblioAdd" type="button" class="tsPrimary" disabled>Добавить с фото и условиями</button><button id="tsBiblioBack" type="button">К предложениям</button></article>';
  }else if(panel.offers.length){
    const count=document.createElement('p');count.textContent='Прочитано предложений: '+panel.total+'. Выберите вариант для проверки фото.';results.append(count);
    panel.offers.forEach((row,index)=>{const card=document.createElement('article');card.innerHTML='<h4>'+esc(row.offer.hotel)+'</h4><p>'+esc([row.offer.date_from,row.offer.nights+' ноч.',row.offer.room,row.offer.meal].join(' · '))+'</p><strong>'+esc(overviewAmount(row.offer.amount,row.offer.currency,row.offer.price_qualifier))+'</strong><p>'+esc(row.raw.party)+'</p>';const button=document.createElement('button');button.type='button';button.textContent='Проверить фото и условия';button.dataset.biblioChoice=String(index);button.onclick=()=>void reviewBiblio(panel,row);card.append(button);results.append(card)});
    if(panel.next!==null){const next=document.createElement('button');next.id='tsBiblioNext';next.type='button';next.textContent='Следующие предложения';next.onclick=()=>void readBiblio(panel,panel.next);results.append(next)}
  }
  $('tsBiblioUrl').oninput=()=>{panel.url=$('tsBiblioUrl').value};$('tsBiblioRead').onclick=()=>void readBiblio(panel,0);
  $('tsBiblioCancel').onclick=()=>{if(busy)return;biblioPanel=null;render()};
  if(review){
    const images=Array.from(results.querySelectorAll('.tsBiblioPhotos img'));
    const updated=()=>{if(biblioPanel===panel&&panel.review===review&&images.every(img=>img.isConnected&&results.contains(img)))biblioControls()};
    images.forEach(img=>{img.onload=updated;img.onerror=updated});
    const amount=$('tsBiblioAmount');amount.onfocus=()=>{if(biblioCurrent(panel)&&panel.review===review&&amount.isConnected)amount.scrollIntoView({block:'nearest',inline:'nearest'})};
    $('tsBiblioBack').onclick=()=>{if(busy)return;panel.review=null;render()};$('tsBiblioConfirm').onchange=biblioControls;$('tsBiblioAdd').onclick=()=>void addBiblio(panel);
  }
}
function biblioImageReady(img){return img.complete&&img.naturalWidth>=100&&img.naturalHeight>=100&&img.naturalWidth<=12000&&img.naturalHeight<=12000&&img.naturalWidth*img.naturalHeight<=24000000}
function biblioPhotosReady(panel){const photos=panel?.review?.photos,images=Array.from($('tsEditor')?.querySelectorAll('.tsBiblioPhotos img')||[]);return !!photos?.length&&images.length===photos.length&&images.every((img,index)=>img.isConnected&&img.getAttribute('src')===photos[index]&&biblioImageReady(img))}
function biblioControls(){
  if(!biblioPanel)return;$('tsEditor')?.querySelectorAll('input,button').forEach(node=>{node.disabled=busy||loading||!biblioCurrent(biblioPanel)});
  const ready=biblioPhotosReady(biblioPanel),note=$('tsBiblioPhotoStatus');if(note){const failed=Array.from($('tsEditor').querySelectorAll('.tsBiblioPhotos img')).some(img=>img.complete&&!biblioImageReady(img)),text=ready?'Фотографии загружены.':failed?'Не все фотографии доступны или подходят для подборки. Выберите другое предложение или прочитайте фото заново. Вариант не добавлен.':'Загружаем фотографии…';if(note.textContent!==text)note.textContent=text;note.classList.toggle('tsError',failed)}
  if($('tsBiblioAdd'))$('tsBiblioAdd').disabled=busy||!biblioCurrent(biblioPanel)||!$('tsBiblioConfirm')?.checked||!ready;
}
async function readBiblio(panel,offset){
  if(!biblioCurrent(panel)||busy)return;panel.url=$('tsBiblioUrl').value.trim();panel.review=null;busy=true;render();
  const result=await request('biblio_search',{url:panel.url,offset});if(epoch!==panel.epoch)return;busy=false;if(!biblioCurrent(panel)){controls();return}
  if(result?.ok&&result.version===panel.version&&Array.isArray(result.offers)&&result.offers.length<=20&&result.offers.every(validBiblioRow)&&Number.isInteger(result.total)&&(result.next_offset===null||Number.isInteger(result.next_offset))){panel.offers=result.offers;panel.total=result.total;panel.next=result.next_offset;panel.message='Предложения прочитаны. Цена и наличие могут измениться.';panel.error=false;}
  else{panel.offers=[];panel.next=null;panel.message=biblioError(result);panel.error=true}render();
}
async function reviewBiblio(panel,row){
  if(!biblioCurrent(panel)||busy)return;busy=true;render();const result=await request('biblio_review',{ticket:row.ticket});if(epoch!==panel.epoch)return;busy=false;if(!biblioCurrent(panel)){controls();return}
  if(result?.ok&&result.version===panel.version&&validBiblioRow(result)&&Array.isArray(result.photos)&&result.photos.length>0&&result.photos.length<=3&&result.photos.every(x=>typeof x==='string'&&/^https:\/\/www\.bgoperator\.ru\/pr_img\/[1-9][0-9]{5,14}\/[0-9]{8}\/[1-9][0-9]{3,14}\/[A-Za-z0-9_-]{1,100}\.(jpg|jpeg|png)$/.test(x))){panel.review=result;panel.message='Проверьте условия и цену за весь состав.';panel.error=false}
  else{panel.message=biblioError(result);panel.error=true}render();
}
async function addBiblio(panel){
  if(!biblioCurrent(panel)||busy||!panel.review||!$('tsBiblioConfirm')?.checked||!biblioPhotosReady(panel))return;
  let amount;try{const units=priceUnits($('tsBiblioAmount').value);if(units<=0n)throw Error();amount=String(units/100n)+(units%100n?'.'+String(units%100n).padStart(2,'0'):'')}catch(e){panel.message='Укажите положительную цену с точностью до двух знаков.';panel.error=true;$('tsBiblioStatus').textContent=panel.message;return}
  pending={kind:'biblio',operation_id:id(),ticket:panel.review.ticket,confirm_party_price:true,manager_amount:amount};biblioPanel=null;stash();render();return submitBiblio(pending);
}
async function submitBiblio(command){
  if(pending!==command||busy||!current())return;const token=epoch;busy=true;stash();controls();
  const {kind,...data}=command,result=await request('biblio_add',data);if(token!==epoch)return;busy=false;
  if(result?.ok){await reconcile();return}
  if(result?.http_status&&result.http_status<500&&!['operation_mismatch','invalid_response'].includes(result.error)){pending=null;if([403,404].includes(result.http_status))canEdit=false;stash();render();status(biblioError(result),true);return}
  stash();controls();recovery('pending');status('Результат добавления не подтверждён. Сначала проверьте сохранение; повторное добавление не запускается.',true);
}
function priceUnits(value){
  const text=String(value??'').trim().replace(/[\s\u00a0\u202f]/g,'').replace(',','.');
  if(!/^(?:0|[1-9][0-9]{0,8})(?:\.[0-9]{1,2})?$/.test(text))throw new Error('Нужна сумма с точностью до двух знаков.');
  const [whole,part='']=text.split('.');return BigInt(whole)*100n+BigInt(part.padEnd(2,'0'));
}
function bulkTotal(base,action,value,basis,round){
  const start=priceUnits(base),change=priceUnits(value),maximum=99999999999n;
  if(start<=0n||start>maximum)throw new Error('Текущая цена не указана или недопустима.');
  if(!['discount','markup'].includes(action)||!['percent','amount'].includes(basis))throw new Error('Выберите способ изменения цены.');
  if(basis==='percent'&&action==='discount'&&change>10000n)throw new Error('Скидка не может превышать 100 процентов.');
  // Keep the exact final rational amount until the one requested rounding.
  const numerator=basis==='percent'?start*(action==='discount'?10000n-change:10000n+change):(action==='discount'?start-change:start+change)*10000n;
  const steps={none:1n,unit:100n,ten:1000n,hundred:10000n,thousand:100000n};
  if(!Object.hasOwn(steps,round))throw new Error('Выберите округление.');
  const step=steps[round],quantum=step*10000n,total=((numerator+quantum/2n)/quantum)*step;
  if(total<=0n||total>maximum)throw new Error('Итоговая цена должна быть больше нуля и меньше миллиарда.');
  const fraction=String(total%100n).padStart(2,'0');return String(total/100n)+(fraction==='00'?'':'.'+fraction);
}
function bulkCurrent(){return !!bulkPrice&&current(bulkPrice.context)&&epoch===bulkPrice.epoch&&version===bulkPrice.version&&canEdit&&!busy&&!loading&&!pending&&!conflict&&!preview&&!sending&&!sharing&&!photoUpload&&bulkPrice.signature===JSON.stringify(draft)}
function showBulkPrices(){
  if(!editable()||busy||sharing||tripPanel||!draft.offers.length||invalidAges())return;
  clearTimeout(timer);timer=0;clearHotelKnowledge();historyView++;recovery('');
  bulkPrice={context:capture(),epoch,version,signature:JSON.stringify(draft),offers:clone(draft.offers)};
  const root=$('tsBulkPrice');root.hidden=false;dialog.classList.add('tsBulkMode');
  root.innerHTML='<div class="tsBulkBody"><h3 id="tsBulkTitle">Изменить несколько цен</h3><p>Расчёт от текущих цен на момент открытия этой формы. Смена процента и предпросмотр не накапливают скидку. Исходные цены поставщиков, условия и фотографии сохраняются.</p><div class="tsFields"><label for="tsBulkAction">Действие<select id="tsBulkAction"><option value="discount">Скидка</option><option value="markup">Наценка</option></select></label><label for="tsBulkBasis">Способ расчёта<select id="tsBulkBasis"><option value="percent">Процент</option><option value="amount">Сумма в валюте вариантов</option></select></label><label for="tsBulkValue">Размер изменения<input id="tsBulkValue" type="text" inputmode="decimal" value="5" maxlength="12"></label><label for="tsBulkRound">Округление<select id="tsBulkRound"><option value="none">До копеек / центов</option><option value="unit">До целого</option><option value="ten">До десятков</option><option value="hundred">До сотен</option><option value="thousand">До тысяч</option></select></label></div><fieldset id="tsBulkItems"><legend>Какие варианты изменить</legend>'+bulkPrice.offers.map((offer,index)=>'<label><input type="checkbox" data-ts-bulk-offer="'+esc(offer.id)+'" '+(offer.selected?'checked':'')+'><span>'+esc((index+1)+'. '+(offer.hotel||'Без названия'))+'<small>'+esc(overviewAmount(offer.amount,offer.currency,offer.price_qualifier))+(offer.selected?'':' · не включён в подборку')+'</small></span></label>').join('')+'</fieldset><div id="tsBulkError" class="tsError" role="status" aria-live="polite"></div><div id="tsBulkPreview" class="tsBulkPreview" aria-label="Предпросмотр изменения цен"></div><p>Изменяются только цены отмеченных вариантов. Сообщения клиенту и опубликованные версии не меняются. Повторное открытие формы начинает новый расчёт от текущих цен.</p></div><div class="tsBulkFooter"><button id="tsBulkCancel" type="button">Отмена</button><button id="tsBulkApply" type="button" class="tsPrimary">Применить</button></div>';
  root.addEventListener('input',previewBulkPrices);root.addEventListener('change',previewBulkPrices);
  const batch=bulkPrice;$('tsBulkCancel').onclick=()=>{if(bulkPrice===batch)closeBulkPrices()};$('tsBulkApply').onclick=()=>{if(bulkPrice===batch)applyBulkPrices()};
  controls();$('tsBulkValue').focus();
}
function bulkPlan(){
  if(!bulkCurrent())throw new Error('Подборка или доступ изменились. Закройте расчёт и откройте его заново.');
  const ids=new Set([...$('tsBulkItems').querySelectorAll('input:checked')].map(x=>x.dataset.tsBulkOffer));
  const offers=bulkPrice.offers.filter(x=>ids.has(x.id));if(!offers.length)throw new Error('Отметьте хотя бы один вариант.');
  const action=$('tsBulkAction').value,basis=$('tsBulkBasis').value,value=$('tsBulkValue').value,round=$('tsBulkRound').value;
  if(basis==='amount'&&new Set(offers.map(x=>x.currency)).size>1)throw new Error('Для изменения суммой отметьте варианты в одной валюте. Конвертация не выполняется.');
  return offers.map(offer=>{try{return {offer,amount:bulkTotal(offer.amount,action,value,basis,round)}}catch(error){throw new Error((offer.hotel||'Вариант')+': '+error.message)}});
}
function previewBulkPrices(){
  if(!bulkPrice)return;const allowed=bulkCurrent(),root=$('tsBulkPrice');
  root.querySelectorAll('input,select').forEach(x=>{if(x.disabled!==!allowed)x.disabled=!allowed});
  try{
    const rows=bulkPlan(),changes=rows.filter(x=>priceUnits(x.amount)!==priceUnits(x.offer.amount)).length;
    const message=changes?'':'Цены не изменятся. Измените параметры расчёта.',markup=rows.map(x=>'<div class="tsBulkRow"><strong>'+esc(x.offer.hotel||'Без названия')+'</strong><span>'+esc(overviewAmount(x.offer.amount,x.offer.currency,x.offer.price_qualifier))+' → <b>'+esc(overviewAmount(x.amount,x.offer.currency,x.offer.price_qualifier))+'</b></span></div>').join(''),label='Применить к вариантам: '+rows.length;
    if($('tsBulkError').textContent!==message)$('tsBulkError').textContent=message;
    if(bulkPrice.previewMarkup!==markup){$('tsBulkPreview').innerHTML=markup;bulkPrice.previewMarkup=markup}
    // A select's blur/change can run between pointerdown and click in WebKit.
    // Keep the existing button text node and layout when the plan is unchanged.
    if($('tsBulkApply').textContent!==label)$('tsBulkApply').textContent=label;
    if($('tsBulkApply').disabled!==!changes)$('tsBulkApply').disabled=!changes;
  }catch(error){if($('tsBulkError').textContent!==error.message)$('tsBulkError').textContent=error.message;if(bulkPrice.previewMarkup!==undefined){$('tsBulkPreview').replaceChildren();delete bulkPrice.previewMarkup}if(!$('tsBulkApply').disabled)$('tsBulkApply').disabled=true;}
}
function closeBulkPrices(resume=true){
  if(!bulkPrice)return;bulkPrice=null;const root=$('tsBulkPrice');root?.replaceChildren();if(root)root.hidden=true;dialog?.classList.remove('tsBulkMode');
  if(resume&&current()&&dialog?.open){render();$('tsBulkPrices')?.focus();if(dirty)timer=setTimeout(()=>{if(editable()&&!busy&&!invalidAges()&&dialog?.open)void save()},900)}
}
function applyBulkPrices(){
  if(!bulkPrice)return;
  try{
    const rows=bulkPlan();if(!rows.some(x=>priceUnits(x.amount)!==priceUnits(x.offer.amount)))return;
    const next=clone(draft);for(const row of rows)next.offers.find(x=>x.id===row.offer.id).amount=row.amount;
    closeBulkPrices(false);draft=next;changed();render();status('Изменены цены отмеченных вариантов: '+rows.length+'. Черновик сохраняется; клиенту ничего не отправлено.');
  }catch(error){$('tsBulkError').textContent=error.message;$('tsBulkApply').disabled=true;}
}
function invalidAges(){return draft?.offers.some(x=>Object.hasOwn(rawAges,x.id)&&!validAges(rawAges[x.id]))}
function validAges(value){const raw=String(value).trim();return raw===''||/^\d{1,2}(?:\s*,\s*\d{1,2})*$/.test(raw)&&raw.split(',').length<=12&&raw.split(',').every(x=>Number(x)>=0&&Number(x)<=17)}
function changed(){clearHotelKnowledge();renderOfferOverview();previewIssues=[];$('tsPreviewIssues')?.remove();dirty=true;stash();status(invalidAges()?'Возраст детей укажите числами от 0 до 17 через запятую.':'Есть несохранённые изменения.',invalidAges());controls();clearTimeout(timer);timer=setTimeout(()=>{if(editable()&&!busy&&!invalidAges()&&dialog?.open)void save()},900)}
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
      try {result=await host.request('tour-selection-photo.php','upload',{conversation_id:upload.target.conversation,version:upload.version,offer_id:upload.offer,kind:upload.kind,operation_id:upload.operation,image_base64:upload.encoded.slice(index*96000,(index+1)*96000),part_index:index,part_count:count,image_bytes:upload.file.size,image_sha256:upload.hash,...(upload.sourcePhotoUrl?{source_photo_url:upload.sourcePhotoUrl}:{})},{signal:upload.controller.signal,isCurrent:()=>uploadCurrent(upload)});} finally {clearTimeout(timeout)}
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
  appearancePanel=false;selected=offerId;offerDetailsOpen=true;render();
  if(field==='photos'&&item().import_source&&!item().photos.length){const section=$('tsEditor').querySelector('.tsPhotoFields');section?.scrollIntoView({block:'center'});return;}
  const firstPhoto=['cover','beach','room'].find(kind=>!item().photos.some(x=>x.kind===kind))||'cover';
  const target=$(field==='photos'?'ts-photo-'+firstPhoto:field==='conditions'?'ts-hotel':'ts-'+field);for(let details=target?.closest('details');details;details=details.parentElement?.closest('details'))details.open=true;target?.focus();target?.scrollIntoView({block:'center'});
}
function renderList(){
  const list=$('tsList');if(!list||!draft)return;
  $('tsCount').textContent='Варианты · '+draft.offers.length;
  list.innerHTML=draft.offers.length?[...hotelGroups().values()].map(members=>
    members.map(({offer:x,index:i})=>`<button type="button" data-ts-select="${esc(x.id)}" class="tsOption ${x.id===selected?'isSelected':''}" aria-pressed="${x.id===selected}"><span class="tsOptionNumber">${i+1}</span><span>${members.length>1&&i===members[0].index?`<span class="tsGroupLabel">Один отель · вариантов ${members.length}</span>`:''}<strong>${esc(x.hotel||'Новый вариант')}</strong><small>${esc([x.date_from,x.room].filter(Boolean).join(' · '))}</small><small>${esc(x.amount?(x.price_qualifier==='from'?'от ':'')+x.amount+' '+x.currency+' за всех':'Цена не указана')}</small><small>${x.selected?'Включён в подборку':'Исключён из подборки'}</small></span></button>`).join('')).join(''):'<p class="tsEmptyList">Добавьте первое предложение. Можно сохранить незаполненный вариант и вернуться к нему позже.</p>';
  if(list.scrollWidth>list.clientWidth){const active=list.querySelector('.isSelected');if(active){const bounds=list.getBoundingClientRect(),card=active.getBoundingClientRect();if(card.left<bounds.left)list.scrollLeft+=card.left-bounds.left;else if(card.right>bounds.right)list.scrollLeft+=card.right-bounds.right;}}
}
function overviewAmount(value,currency,qualifier){
  const amount=Number(value);return value!=null&&String(value).trim()!==''&&Number.isFinite(amount)?(qualifier==='from'?'от ':'')+new Intl.NumberFormat('ru-RU',{maximumFractionDigits:2}).format(amount)+'\u00a0'+({RUB:'₽',EUR:'€',USD:'$'}[currency]||currency):'Цена не указана';
}
function renderOfferOverview(){
  const root=$('tsOfferOverview'),offer=item();if(!root||!offer)return;
  const party=[offer.adults?offer.adults+' взр.':'Взрослые не указаны',offer.child_ages?.length?'дети: '+offer.child_ages.join(', '):''].filter(Boolean).join(' · ');
  const terms=[['Поездка',[offer.date_from,offer.nights?offer.nights+' ноч.':''].filter(Boolean).join(' · ')||'Даты не указаны'],['Состав',party],['Номер',offer.room||'Не указан'],['Питание',offer.meal||'Не указано']];
  root.innerHTML='<div class="tsOverviewHeading"><div><span class="tsOverviewLabel">'+(offer.selected?'В подборке':'Исключён из подборки')+'</span><h3>'+esc(offer.hotel||'Новый вариант')+'</h3><p>'+esc([offer.destination,offer.departure&&'Вылет: '+offer.departure].filter(Boolean).join(' · '))+'</p></div><strong class="tsOverviewPrice">'+esc(overviewAmount(offer.amount,offer.currency,offer.price_qualifier))+'<small>за весь состав</small></strong></div><dl class="tsOverviewTerms">'+terms.map(([label,value])=>'<div><dt>'+esc(label)+'</dt><dd>'+esc(value)+'</dd></div>').join('')+'</dl>'+(offer.rationale?'<p class="tsOverviewNote"><strong>Почему рекомендуем:</strong> '+esc(offer.rationale)+'</p>':'')+(offer.compromise?'<p class="tsOverviewNote"><strong>Важно учесть:</strong> '+esc(offer.compromise)+'</p>':'')+'<p class="tsOverviewMeta">'+esc(offer.photos.length?'Фото: '+offer.photos.length:'Фото пока не добавлены')+' · Цена и наличие требуют проверки</p>';
  if(offer.import_source?.fields){const source=document.createElement('p');source.className='tsOverviewMeta';source.textContent='Исходная цена: '+overviewAmount(offer.import_source.fields.amount,offer.import_source.fields.currency,offer.import_source.fields.price_qualifier)+' · '+offer.import_source.observed_at+'. Редактируемая цена хранится отдельно.';root.append(source)}
}
function render(){
  clearPrint();
  clearHotelKnowledge();
  if(!draft||!dialog)return;
  dialog.classList.toggle('tsBiblioMode',!!biblioPanel||!!oneTouchPanel);
  dialog.classList.toggle('tsAppearanceMode',appearancePanel&&!preview);
  dialog.classList.toggle('tsOfferCompareMode',!!offerComparison);
  applyAppearance($('tsEditor'),preview?publication?.appearance:null);
  if(bulkPrice){controls();return}
  if(offerComparison){renderOfferComparison();controls();return}
  if(oneTouchPanel){renderList();renderOneTouch();controls();return}
  if(biblioPanel){renderList();renderBiblio();controls();return}
  dialog.classList.toggle('tsTripMode',!!tripPanel);
  if(preview&&publication){renderPreview();controls();return}
  if(appearancePanel){renderAppearance();controls();return}
  dialog.classList.remove('tsPreviewMode');
  if(!item())selected=draft.offers[0]?.id||'';renderList();const editor=$('tsEditor'),offer=item();
  if(tripPanel){renderTrip();controls();return}
  if(!offer){editor.innerHTML='<div class="tsEmpty"><span aria-hidden="true">＋</span><h3>Соберите подборку для клиента</h3><p>Добавьте найденный тур, укажите условия и общую цену. Варианты сохранятся здесь отдельно от переписки.</p><button type="button" id="tsEmptyAdd" class="tsPrimary">Добавить предложение</button></div>';$('tsEmptyAdd').onclick=add;controls();return}
  editor.innerHTML=`<div class="tsEditorHead"><h3>Условия предложения</h3><div class="tsOrder"><button type="button" data-ts-action="up" aria-label="Поднять предложение">↑</button><button type="button" data-ts-action="down" aria-label="Опустить предложение">↓</button><button type="button" data-ts-action="remove">Удалить</button></div></div><label class="tsInclude"><input data-ts-field="selected" type="checkbox" ${offer.selected?'checked':''}> Включить в подборку</label><div class="tsFields">${input('hotel','Название отеля','text','maxlength="191"')}${input('stars','Звёздность','number','min="0" max="5"')}${input('destination','Страна и курорт','text','maxlength="160"')}${input('departure','Вылет из','text','maxlength="160"')}${input('date_from','Дата вылета','date')}${input('nights','Ночей','number','min="1" max="60"')}${input('adults','Взрослых','number','min="1" max="20"')}${input('child_ages','Возраст детей через запятую','text','inputmode="numeric" placeholder="Например, 4, 10" maxlength="60"')}${input('room','Номер и размещение','text','maxlength="300"')}${input('meal','Питание','text','maxlength="160"')}${input('amount','Цена за всех','text','inputmode="decimal" placeholder="Например, 240000" maxlength="12"')}<label for="ts-currency">Валюта<select id="ts-currency" data-ts-field="currency">${['RUB','EUR','USD'].map(v=>`<option ${offer.currency===v?'selected':''}>${v}</option>`).join('')}</select></label><label for="ts-price-qualifier">Как показывать цену<select id="ts-price-qualifier" data-ts-field="price_qualifier"><option value="fixed" ${offer.price_qualifier!=='from'?'selected':''}>Указанная сумма</option><option value="from" ${offer.price_qualifier==='from'?'selected':''}>Минимальная — «от»</option></select></label>${textarea('rationale','Почему рекомендуем')}${textarea('compromise','Что важно учесть')}</div><details class="tsDetails"><summary>Источник и дополнительные условия</summary><div class="tsFields">${input('source_label','Туроператор или источник','text','maxlength="160"')}${input('source_url','Ссылка на предложение','url','maxlength="2048" placeholder="https://…"')}${textarea('flights','Перелёт и багаж')}${textarea('inclusions','Что включено')}${textarea('constraints','Непроверенные условия и доплаты')}</div></details><p class="tsPriceHint">Сохранение черновика не подтверждает актуальность цены. Перед отправкой условия потребуется проверить.</p>`;
  const offerDetails=document.createElement('details');offerDetails.id='tsOfferDetails';offerDetails.className='tsOfferDetails';offerDetails.open=offerDetailsOpen||!!pending||!!conflict;
  const summary=document.createElement('summary');summary.textContent='Изменить условия, цену и фото';offerDetails.append(summary);
  const form=document.createElement('div');form.className='tsOfferForm';while(editor.firstChild)form.append(editor.firstChild);offerDetails.append(form);editor.append(offerDetails);
  offerDetails.addEventListener('toggle',()=>{if(offerDetails.isConnected&&item()?.id===offer.id)offerDetailsOpen=offerDetails.open});
  const overview=document.createElement('section');overview.id='tsOfferOverview';overview.className='tsOfferOverview';overview.setAttribute('aria-label','Обзор выбранного варианта');editor.prepend(overview);renderOfferOverview();
  const order=form.querySelector('.tsEditorHead');if(order){order.querySelector('h3').textContent='Порядок и включение';offerDetails.after(order);order.after(form.querySelector('.tsInclude'))}
  const grouping=document.createElement('section');grouping.id='tsGrouping';grouping.className='tsGrouping';editor.querySelector('.tsDetails').before(grouping);renderGrouping();
  if(offer.import_source){const source=document.createElement('section');source.className='tsImportedSource';const title=document.createElement('h3');title.textContent='Исходные условия '+({'anex-agent-v1':'ANEX','tourvisor-snapshot-v1':'Tourvisor','biblio-public-v1':'Библио-Глобус','onetouch-public-v1':'OneTouch'}[offer.import_source.adapter]||'FUN&SUN');source.append(title);for(const text of [(offer.import_source.fields.price_qualifier==='from'?'от ':'')+offer.import_source.fields.amount+' '+offer.import_source.fields.currency+(offer.import_source.adapter==='tourvisor-snapshot-v1'?' за номер · менеджер подтвердил 1 номер на 2 взрослых · ':' за весь состав · ')+offer.import_source.observed_at,offer.import_source.raw.room+' · '+offer.import_source.raw.meal,'Цена со страницы. Полная стоимость, рейсы, фото и соответствие каталогу не подтверждены. Ручная сумма выше хранится отдельно.']){const p=document.createElement('p');p.textContent=text;source.append(p)}const link=document.createElement('a');link.href=offer.import_source.url;link.target='_blank';link.rel='noopener noreferrer';link.textContent=offer.import_source.adapter==='biblio-public-v1'?'Карточка отеля у оператора':'Открыть исходный поиск';source.append(link);editor.querySelector('.tsDetails').before(source)}
  controls();recovery(conflict?'conflict':pending?'pending':'');
  const photoSection=document.createElement('section');photoSection.className='tsPhotoFields';
  const filePhotos=offer.import_source?.raw?.capture_mode==='manager-file-v1',gallery=Array.isArray(offer.import_source?.photos),automatic=(gallery||!!offer.import_source&&offer.photos.length===0)&&photoEditOffer!==offer.id;
  if(automatic){
    photoSection.classList.add('tsAutomaticPhotos');
    const sourceCount=offer.photos.filter(photo=>offer.import_source.photos?.includes(photo.url)).length;
    const photoStatus=offer.photos.length&&filePhotos?'Фотографии сохранены в подборке. Снимки из файла источника могли быть заменены менеджером — проверьте их в предпросмотре.':!offer.photos.length&&filePhotos?'Тур сохранён. Завершите перенос фотографий из файла к этому же варианту, не импортируя тур повторно.':!offer.photos.length?'Источник пока не отдал фотографии. Сначала проверьте состояние исходной операции в сборщике. Если результат неизвестен, не добавляйте тур повторно.':sourceCount===offer.photos.length?'Фото добавлены автоматически из галереи источника. Можно сразу открыть предпросмотр.':sourceCount?'Фото из источника дополнены или заменены менеджером. Проверьте их в предпросмотре.':'Фото добавлены менеджером. Проверьте их в предпросмотре.';
    photoSection.innerHTML='<h3>Фотографии отеля</h3><p class="tsAutomaticPhotoStatus">'+photoStatus+'</p><button type="button" data-ts-photo-mode="manual" '+(!editable()||busy?'disabled':'')+'>'+(offer.photos.length?'Добавить или заменить фото':'Добавить фото вручную')+'</button><div class="tsFields">'+offer.photos.map((photo,i)=>`<div class="tsWide tsPhotoSlot"><h4>Фото ${i+1}</h4><img class="tsUploadedThumb" loading="lazy" decoding="async" src="${esc(photo.url)}" alt="Фото отеля ${i+1}" referrerpolicy="no-referrer"><p class="tsUploadedLabel">${filePhotos?'Сохранённое фото':offer.import_source.photos?.includes(photo.url)?'Из галереи источника':'Добавлено менеджером'}</p><details class="tsPhotoReplace"><summary>Заменить фото</summary><label class="tsFileLabel" for="ts-upload-${photo.kind}">Выбрать другой файл<input id="ts-upload-${photo.kind}" type="file" data-ts-upload="${photo.kind}" accept="image/jpeg,image/png" ${!editable()||busy?'disabled':''}></label><label for="ts-photo-${photo.kind}">Ссылка на другое фото<input id="ts-photo-${photo.kind}" type="url" data-ts-photo="${photo.kind}" maxlength="2048" placeholder="https://…/photo.jpg" value="" ${!editable()||busy?'disabled':''}></label><button type="button" data-ts-remove-photo="${photo.kind}">Убрать фото</button></details></div>`).join('')+'</div><p>На снимках — отель. Интерьер выбранного номера может отличаться.</p>';
  }else{
  photoSection.innerHTML='<h3>'+(gallery?'Фотографии этого отеля':'Три фото для карточки')+'</h3><p>'+(offer.import_source?(gallery?'Для предпросмотра достаточно одного фото отеля. ':'Для предпросмотра нужны фото отеля, пляжа или бассейна и пример номера. ')+'Добавляйте только реальные снимки этого же отеля. Условия и исходные данные сохраняются; импорт не повторяется. ':'')+'Выберите JPEG или PNG до 2 МБ. Фото номера будет отмечено как пример интерьера. Загруженные файлы доступны сотрудникам с доступом к этому клиенту.</p>'+(offer.import_source&&(gallery||!offer.photos.length)?'<button type="button" data-ts-photo-mode="auto" '+(!editable()||busy?'disabled':'')+'>Свернуть редактор фото</button>':'')+'<div class="tsFields">'+(filePhotos?[['cover','Фото 1'],['beach','Фото 2'],['room','Фото 3']]:[['cover','Отель'],['beach','Пляж или бассейн'],['room','Номер']]).map(([kind,label])=>{const url=offer.photos.find(x=>x.kind===kind)?.url||'',uploaded=/^https:\/\/app\.anytoour\.ru\/manager\/tour-selection-photo\.php\?conversation_id=/.test(url);return `<div class="tsWide tsPhotoSlot"><h4>${label}</h4>${uploaded?`<img class="tsUploadedThumb" loading="lazy" decoding="async" src="${esc(url)}" alt="${label==='Номер'?'Пример интерьера номера':label}" referrerpolicy="no-referrer">`:''}<label class="tsFileLabel" for="ts-upload-${kind}">${uploaded?'Заменить фото':'Выбрать фото'}: ${label}<input id="ts-upload-${kind}" type="file" data-ts-upload="${kind}" accept="image/jpeg,image/png" ${!editable()||busy?'disabled':''}></label>${uploaded?'<p class="tsUploadedLabel">Фото добавлено в черновик</p><button type="button" data-ts-remove-photo="'+kind+'">Убрать фото</button><details class="tsPhotoUrl"><summary>Заменить по ссылке</summary>':''}<label for="ts-photo-${kind}">${uploaded?'Ссылка на новое фото':'Или HTTPS-ссылка'}<input id="ts-photo-${kind}" type="url" data-ts-photo="${kind}" maxlength="2048" placeholder="https://…/photo.jpg" value="${esc(uploaded?'':url)}" ${!editable()||busy?'disabled':''}></label>${uploaded?'</details>':''}</div>`}).join('')+'</div>';
  }
  const knowledge=document.createElement('section');knowledge.className='tsKnowledge';
  const readFacts=document.createElement('button');readFacts.type='button';readFacts.id='tsHotelKnowledgeRead';readFacts.textContent='Сведения об отеле';readFacts.onclick=()=>void readHotelKnowledge();
  const facts=document.createElement('div');facts.id='tsHotelKnowledge';knowledge.append(readFacts,facts);offerDetails.after(knowledge);
  editor.querySelector('.tsPriceHint').before(photoSection);
  photoSection.querySelectorAll('.tsUploadedThumb').forEach(img=>img.addEventListener('error',()=>{img.alt='Не удалось показать фото';const label=img.closest('.tsPhotoSlot').querySelector('.tsUploadedLabel');if(label)label.textContent='Фото не удалось показать. Замените его или проверьте предпросмотр.'}));
  photoSection.querySelectorAll('[data-ts-remove-photo]').forEach(x=>{x.disabled=!editable()||busy});
  const reuse=document.createElement('div');reuse.id='tsPhotoReuse';reuse.className='tsPhotoReuse';photoSection.querySelector('.tsFields').before(reuse);renderPhotoReuse();renderPreviewIssues();renderUploadNotice();
}
function clearHotelKnowledge(){hotelKnowledge?.clear();hotelKnowledge=null;}
function canReadHotelKnowledge(){return current()&&dialog?.open&&item()&&version>0&&!dirty&&!pending&&!conflict&&!busy&&!loading&&!preview&&!photoUpload&&!sending;}
async function readHotelKnowledge(){
  if(!canReadHotelKnowledge())return;
  clearHotelKnowledge();const target=context,token=epoch,offerId=selected,savedVersion=version;
  const valid=()=>token===epoch&&current(target)&&canReadHotelKnowledge()&&selected===offerId&&version===savedVersion;
  const root=$('tsHotelKnowledge');if(!root)return;
  hotelKnowledge=AnyTourHotelKnowledgeView.mount(root,{
    capture:()=>target,isCurrent:t=>t===target&&valid(),
    read:(data,options)=>host.request('hotel-knowledge-api.php','read',data,{signal:options.signal,isCurrent:valid})
  });
  await hotelKnowledge.show({conversation_id:target.conversation,kind:'working_draft',draft_version:savedVersion,offer_id:offerId});
}
function controls(){
  if(!dialog)return;const disabled=!editable();
  $('tsCompareOffers').hidden=preview;$('tsCompareOffers').disabled=!canCompareOffers()||!!offerComparison;$('tsCompareOffers').setAttribute('aria-expanded',String(!!offerComparison));
  $('tsAppearance').hidden=preview;$('tsAppearance').disabled=disabled||busy||!!tripPanel;
  $('tsBulkPrices').disabled=disabled||busy||!!tripPanel||!(draft?.offers.length)||invalidAges();$('tsBulkPrices').hidden=preview;
  if(bulkPrice){$('tsHistory').disabled=true;previewBulkPrices();}
  const factsButton=$('tsHotelKnowledgeRead');if(factsButton){factsButton.disabled=!canReadHotelKnowledge();factsButton.title=dirty?'Сначала сохраните изменения':'';}
  $('tsAdd').disabled=disabled||busy||(draft?.offers.length||0)>=20;$('tsSave').disabled=disabled||busy||!dirty||invalidAges();$('tsDone').disabled=busy||loading||(!preview&&invalidAges());
  $('tsSaved').textContent=photoUpload?.loading?'Загружаем фото… '+photoUpload.progress+'%':photoUpload?'Загрузка фото не подтверждена':busy?(tripPanel?'Проверяем условия поездки…':'Сохраняем…'):pending?'Результат сохранения уточняется':conflict?'Есть конфликт правок':dirty?'Не все изменения сохранены':version?(host.savedLabel||'Сохранено на сервере'):'Новый черновик';
  $('tsEditor')?.querySelectorAll('input,textarea,select,[data-ts-action],[data-ts-copy-photos],[data-ts-fix-offer],[data-ts-remove-photo],[data-ts-photo-mode],#tsEmptyAdd').forEach(x=>{x.disabled=disabled||busy});
  $('tsSave').hidden=preview;$('tsPreview').hidden=preview;$('tsEdit').hidden=!preview;$('tsSend').hidden=!preview;$('tsHistory').hidden=preview;$('tsDone').hidden=!preview;
  $('tsCollector').hidden=preview;$('tsCollector').disabled=!!photoUpload||!canEdit||busy||loading||sending||sharing;
  $('tsOneTouch').hidden=preview;$('tsOneTouch').disabled=disabled||busy||!!tripPanel||(draft?.offers.length||0)>=20;
  $('tsBiblio').hidden=preview;$('tsBiblio').disabled=disabled||busy||!!tripPanel||(draft?.offers.length||0)>=20;
  $('tsPreview').disabled=!!photoUpload||busy||loading||sending||!canEdit||!!pending||!!conflict||invalidAges();$('tsHistory').disabled=!!photoUpload||busy||loading||sending;$('tsEdit').disabled=sending;
  if(bulkPrice){$('tsHistory').disabled=true;$('tsPreview').disabled=true;}
  if(offerComparison){for(const id of ['tsHistory','tsPreview','tsCollector','tsAppearance','tsOneTouch','tsBiblio','tsTripAdd','tsBulkPrices','tsAdd','tsSave'])$(id).disabled=true;}
  $('tsShare').hidden=!preview;$('tsShare').disabled=busy||loading||sending||sharing;
  const printLabel=printProposal?'Вернуть предпросмотр':'Печать / PDF';$('tsPrint').hidden=!preview;if($('tsPrint').textContent!==printLabel)$('tsPrint').textContent=printLabel;$('tsPrint').disabled=printProposal?!current():!canPrint();
  for(const button of $('tsEditor')?.querySelectorAll('[data-ts-image-export]')||[]){const card=publication?.cards.find(card=>card.index===Number(button.dataset.tsImageExport));button.hidden=imageDownload?.button===button;button.disabled=!!imageExport||!canExportImage(card);button.textContent=imageExport?.card===card?'Готовим изображение…':'Подготовить картинку';}
  if(imageDownload){imageDownload.link.setAttribute('aria-disabled',String(imageDownload.dispatched||!imageDownload.matches()));imageDownload.cancel.disabled=imageDownload.dispatched;}
  if($('tsInterest')){$('tsInterest').disabled=busy||loading||sending||sharing||interestLoading;$('tsInterest').textContent=interestLoading?'Загружаем активность…':'Активность подборки'}
  const publish=$('tsPublish');if(publish)publish.disabled=!canEdit||busy||sending||sharing||dirty||!!pending||!!conflict||photoPending>0||photoFailures>0;
  if(sharing){$('tsEdit').disabled=true;$('tsDone').disabled=true}else $('tsDone').disabled=false;
  $('tsTripAdd').hidden=preview;$('tsTripAdd').disabled=disabled||busy||!!tripPanel||(draft?.offers.length||0)>=20;
  $('tsList')?.querySelectorAll('button').forEach(x=>{x.disabled=!!photoUpload||busy});
  if(tripPanel){for(const id of ['tsAdd','tsSave','tsDone','tsPreview','tsHistory','tsCollector'])$(id).disabled=true;}
  if(oneTouchPanel){for(const id of ['tsAdd','tsSave','tsDone','tsPreview','tsHistory','tsCollector','tsBulkPrices','tsTripAdd','tsBiblio'])$(id).disabled=true;$('tsList')?.querySelectorAll('button').forEach(x=>{x.disabled=true});oneTouchControls();}
  if(appearancePanel&&!preview){
    for(const id of ['tsAdd','tsHistory','tsCollector','tsBulkPrices','tsTripAdd','tsBiblio','tsOneTouch'])$(id).disabled=true;
    $('tsList')?.querySelectorAll('button').forEach(x=>{x.disabled=true});
    const legacy=!$('tsAppearanceTemplate')?.value;
    for(const id of ['tsAppearanceAgency','tsAppearancePalette'])if($(id))$(id).disabled=disabled||busy||legacy;
    if($('tsAppearanceBack'))$('tsAppearanceBack').disabled=busy;
  }
  if(biblioPanel){for(const id of ['tsAdd','tsSave','tsDone','tsPreview','tsHistory','tsCollector','tsBulkPrices','tsTripAdd'])$(id).disabled=true;$('tsList')?.querySelectorAll('button').forEach(x=>{x.disabled=true});biblioControls();}
  const cards=publication?.cards||[],unknown=cards.some(x=>['reserved','unknown'].includes(x.state)),known=cards.some(x=>x.state==='known'),remaining=cards.filter(x=>x.state!=='delivered').length;
  $('tsSend').disabled=sending||sharing||!publication?.can_send||unknown||!remaining||photoFailures>0||photoPending>0;
  $('tsSend').textContent=sending?'Отправляем…':unknown?'Результат неизвестен':photoPending?'Загружаем фото…':known?'Проверить доставку':remaining?'Отправить варианты ('+remaining+')':'Подборка отправлена';
  if(offerComparison)for(const id of ['tsHistory','tsPreview','tsSend','tsShare','tsPrint','tsEdit','tsDone'])$(id).disabled=true;
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
  if(pending?.kind==='onetouch')return reconcileOneTouch();
  if(!pending||busy||!current())return;busy=true;controls();const token=epoch,result=await request('operation',{operation_id:pending.operation_id});if(token!==epoch)return;busy=false;
  if(result?.ok&&result.found&&validSnapshot(result.selection)){
    const sameVersion=result.saved_version===result.selection.version,biblio=pending.kind==='biblio';pending=null;canEdit=result.selection.can_edit;
    // Direct source intake starts from a clean draft. Its pre-add snapshot is
    // never a competing local edit that could delete the confirmed new offer.
    if(sameVersion||biblio){draft=clone(result.selection.draft);version=result.selection.version;rawAges={};dirty=false;conflict=null}else{conflict=result.selection;dirty=true}
    stash();render();recovery(conflict?'conflict':'');status(biblio?'Добавление подтверждено. Показана текущая сохранённая подборка.':sameVersion?'Сохранение подтверждено.':'Сохранение подтверждено, но затем подборку изменили. Сравните версии.',!sameVersion&&!biblio);return;
  }
  controls();recovery('pending');status(result?.ok?'Подтверждение ещё не найдено. Можно повторить тот же запрос с защитой от дублей.':error(result),true);
  if(result?.ok&&!result.found&&validSnapshot(result.selection)&&result.selection.can_edit){const button=document.createElement('button');button.type='button';button.textContent='Повторить это сохранение';button.onclick=()=>{if(pending&&!busy&&current())void(pending.kind==='biblio'?submitBiblio(pending):submit(pending))};$('tsRecovery').appendChild(button)}
}
function previewCaption(card){
  const text=String(card.text||''),lines=text.split('\n'),hotel=card.snapshot?.hotel;
  // Use the immutable renderer text; never reconstruct terms or accept HTML.
  if(lines.length<3||typeof hotel!=='string'||!hotel||!lines[0].startsWith(hotel))return esc(text);
  return '<strong class="tsCardTitle">'+esc(hotel)+'</strong>'+esc(lines[0].slice(hotel.length))+'\n<strong class="tsCardPrice">'+esc(lines[1])+'</strong>\n'+esc(lines.slice(2).join('\n'));
}
function canPrint(){
  if(!current()||!dialog?.open||!preview||!publication?.cards.length||busy||loading||sending||sharing||photoPending||photoFailures||printProposal||imageExport)return false;
  const images=Array.from($('tsEditor').querySelectorAll('.tsPhotos img'));
  return images.length===publication.cards.reduce((n,card)=>n+card.photos.length,0)&&images.every(img=>img.complete&&img.naturalWidth>0);
}
function cancelImageExport(){
  if(imageExport)imageExport.cancelled=true;imageExport=null;
  const ready=imageDownload;imageDownload=null;
  if(ready){ready.job.cancelled=true;clearTimeout(ready.timer);ready.link.removeAttribute('href');URL.revokeObjectURL(ready.url);ready.root.remove();ready.button.hidden=false;}
}
function exportImages(card){
  if(!card||!Number.isInteger(card.index)||card.index<0||card.index>=20)return [];
  return Array.from($('tsEditor')?.querySelectorAll('[data-ts-card="'+card.index+'"] .tsPhotos img')||[]);
}
function canExportImage(card){
  if(!current()||!dialog?.open||!preview||!publication?.cards.includes(card)||busy||loading||sending||sharing||printProposal||!Array.isArray(card.photos)||card.photos.length<1||card.photos.length>3)return false;
  const images=exportImages(card);
  return images.length===card.photos.length&&images.every((image,index)=>image.isConnected&&image.getAttribute('src')===card.photos[index]&&biblioImageReady(image));
}
function exportLines(context,text,font,width){
  context.font=font;const lines=[];
  const units=value=>typeof Intl.Segmenter==='function'?Array.from(new Intl.Segmenter('ru',{granularity:'grapheme'}).segment(value),item=>item.segment):Array.from(value);
  for(const paragraph of text.split('\n')){
    let line='';
    for(const word of paragraph.split(/\s+/u).filter(Boolean)){
      const next=line?line+' '+word:word;
      if(context.measureText(next).width<=width){line=next;continue}
      if(line){lines.push(line);line='';}
      if(context.measureText(word).width<=width){line=word;continue}
      for(const unit of units(word)){
        if(line&&context.measureText(line+unit).width>width){lines.push(line);line='';}
        line+=unit;
      }
    }
    lines.push(line);
  }
  return lines;
}
function drawExportImage(card,title,version,images,appearance=null,intro=''){
  if(typeof card.text!=='string'||card.text.length>20000||typeof title!=='string'||title.length>500||typeof intro!=='string'||intro.length>1200||!Number.isInteger(version)||version<1)throw Error('image_export_unavailable');
  const canvas=document.createElement('canvas');canvas.width=1080;canvas.height=1;
  const context=canvas.getContext('2d');if(!context)throw Error('image_export_unavailable');
  const margin=56,width=canvas.width-margin*2,blocks=[];let y=48;
  const text=(value,font,color,lineHeight,gap=0)=>{const lines=exportLines(context,value,font,width);blocks.push({lines,font,color,lineHeight,y});y+=lines.length*lineHeight+gap;};
  const design=appearanceSettings(appearance);
  text(design.agency_name,'bold 28px Arial',design.accent,38,12);
  text(title,'bold 34px Arial','#18312b',44,10);
  if(intro)text(intro,'24px Arial','#52695c',34,12);
  text('Вариант '+(card.index+1)+' · Версия подборки '+version,'24px Arial','#52695c',34,24);
  const photoY=y,gap=14,photoWidth=(width-gap*(images.length-1))/images.length,photoHeight=design.template==='compact'?180:images.length===1?Math.round(width*0.56):260;
  y+=photoHeight+12;
  if(card.photo_layout!=='gallery-v1'&&images.length===3)text('Третье фото — пример интерьера номера.','22px Arial','#52695c',30,16);else y+=16;
  const lines=card.text.split('\n');
  text(lines[0]||'','bold 38px Arial','#18312b',48,10);
  if(lines.length>1)text(lines[1],'bold 44px Arial',design.accent,54,16);
  if(lines.length>2)text(lines.slice(2).join('\n'),'28px Arial','#18312b',40,24);
  text('Цена и наличие требуют подтверждения перед оформлением.','22px Arial','#52695c',32);
  const height=Math.ceil(y+48);
  // Never clip a room, price, disclaimer or any other immutable condition.
  if(height>8192)throw Error('image_export_too_long');
  canvas.height=height;context.fillStyle='#f4f7f2';context.fillRect(0,0,canvas.width,height);
  context.fillStyle='#ffffff';context.fillRect(24,24,canvas.width-48,height-48);
  context.fillStyle=design.accent;context.fillRect(24,24,8,height-48);
  context.imageSmoothingEnabled=true;context.imageSmoothingQuality='high';
  images.forEach((image,index)=>{
    const scale=Math.max(photoWidth/image.naturalWidth,photoHeight/image.naturalHeight),sourceWidth=photoWidth/scale,sourceHeight=photoHeight/scale;
    context.drawImage(image,(image.naturalWidth-sourceWidth)/2,(image.naturalHeight-sourceHeight)/2,sourceWidth,sourceHeight,margin+index*(photoWidth+gap),photoY,photoWidth,photoHeight);
  });
  context.textBaseline='top';
  for(const block of blocks){context.font=block.font;context.fillStyle=block.color;block.lines.forEach((line,index)=>context.fillText(line,margin,block.y+index*block.lineHeight));}
  return canvas;
}
async function exportCardImage(card){
  if(imageExport||!canExportImage(card))return;
  cancelImageExport();
  const job={card,epoch,publication,photoEpoch,version:publication.draft_version,title:publication.title,appearance:clone(publication.appearance||null),intro:publication.intro||'',index:card.index,text:card.text,photos:card.photos.slice(),focus:document.activeElement===$('tsEditor').querySelector('[data-ts-image-export="'+card.index+'"]'),cancelled:false};imageExport=job;controls();status('Готовим изображение показанного варианта…');let canvas=null;
  const matches=()=>!job.cancelled&&job.epoch===epoch&&job.photoEpoch===photoEpoch&&publication===job.publication&&publication.draft_version===job.version&&publication.title===job.title&&JSON.stringify(publication.appearance||null)===JSON.stringify(job.appearance)&&(publication.intro||'')===job.intro&&card.index===job.index&&card.text===job.text&&card.photos.length===job.photos.length&&card.photos.every((url,index)=>url===job.photos[index])&&canExportImage(card);
  const active=()=>imageExport===job&&matches();
  try{
    if(JSON.stringify(publication.appearance||null)!==JSON.stringify(job.appearance)||(publication.intro||'')!==job.intro)throw Error('image_export_unavailable');
    canvas=drawExportImage(card,job.title,job.version,exportImages(card),job.appearance,job.intro);
    const blob=await new Promise((resolve,reject)=>{try{canvas.toBlob(value=>value?resolve(value):reject(Error('image_export_unavailable')),'image/png')}catch(error){reject(error)}});
    if(!active())return;
    const button=$('tsEditor').querySelector('[data-ts-image-export="'+job.index+'"]');if(!button?.isConnected)throw Error('image_export_unavailable');
    const root=document.createElement('div'),link=document.createElement('a'),cancel=document.createElement('button'),url=URL.createObjectURL(blob);
    const ready={job,root,link,cancel,url,button,matches,dispatched:false,timer:0,focus:job.focus};
    try{
      root.className='tsImageReady';link.className='tsImageDownload';link.dataset.tsImageDownload=String(job.index);link.href=url;link.download='AnyTour-v'+job.version+'-variant-'+(job.index+1)+'.png';link.textContent='Скачать картинку';link.setAttribute('aria-label','Скачать картинку варианта '+(job.index+1)+' версии '+job.version);
      // The second, ordinary user click keeps the native download gesture in
      // protected browser frames. Never dispatch an asynchronous link.click().
      link.onclick=event=>{
        if(imageDownload!==ready||ready.dispatched||!matches()||!link.isConnected){event.preventDefault();return;}
        ready.dispatched=true;controls();status('Браузеру передан запрос на скачивание картинки.');
        ready.timer=setTimeout(()=>{if(imageDownload===ready){cancelImageExport();controls();}},1000);
      };
      cancel.type='button';cancel.textContent='Отменить';cancel.onclick=()=>{if(imageDownload!==ready)return;const same=matches();cancelImageExport();controls();if(same){status('Подготовленная картинка закрыта.');if(button.isConnected)button.focus();}};
      root.append(link,cancel);button.after(root);imageDownload=ready;button.hidden=true;
    }catch(error){link.removeAttribute('href');URL.revokeObjectURL(url);root.remove();throw error;}
    status('Картинка варианта '+(job.index+1)+' готова. Нажмите «Скачать картинку».');
  }catch(error){if(active())status(error.message==='image_export_too_long'?'Условий слишком много для одной картинки. Используйте Печать / PDF — текст не будет обрезан.':'Не удалось подготовить изображение с этими фото. Используйте Печать / PDF или попробуйте ещё раз.',true);}
  finally{if(canvas){canvas.width=0;canvas.height=0;}if(imageExport===job){imageExport=null;controls();if(imageDownload?.job===job&&imageDownload.focus&&matches()&&[document.body,imageDownload.button].includes(document.activeElement))imageDownload.link.focus({preventScroll:true});}}
}
function clearPrint(){
  if(!printProposal)return;
  for(const {image,marker} of printProposal.images)if(marker.isConnected)marker.replaceWith(image);
  printProposal.root.remove();printProposal=null;document.body.classList.remove('tsPrinting');
}
function printPreview(){
  // Some browser hosts do not emit a print lifecycle. Keep an explicit way to
  // restore the same photos, without guessing that printing finished by timeout.
  if(printProposal){clearPrint();controls();return}
  if(!canPrint())return;
  // Print only the immutable, currently displayed publication. Move already
  // loaded images, so opening the print dialog never re-fetches private photos.
  const root=document.createElement('section');root.className='tsPrintProposal';root.setAttribute('aria-label','Подборка для печати');
  const text=(tag,value,parent=root)=>{const el=document.createElement(tag);el.textContent=value;parent.append(el);return el};
  applyAppearance(root,publication.appearance);
  text('p',appearanceSettings(publication.appearance).agency_name).className='tsPrintBrand';text('h1',publication.title);
  if(publication.intro)text('p',publication.intro).className='tsPrintIntro';
  text('p','Версия подборки '+publication.draft_version).className='tsPrintVersion';
  printProposal={root,images:[]};
  try{
    for(const card of publication.cards){
      const article=text('article','');article.className='tsPrintOffer';text('h2','Вариант '+(card.index+1),article);
      const photos=text('div','',article);photos.className='tsPrintPhotos';
      let photoIndex=0;
      for(const image of dialog.querySelectorAll('[data-ts-card="'+card.index+'"] .tsPhotos img')){
        const marker=document.createComment('print photo');image.replaceWith(marker);printProposal.images.push({image,marker});
        const figure=document.createElement('figure');figure.append(image);photos.append(figure);
        if(card.photo_layout!=='gallery-v1'&&++photoIndex===3)text('figcaption','Пример интерьера номера',figure);
      }
      const caption=text('p','',article);caption.className='tsCardText';caption.innerHTML=previewCaption(card);
    }
    text('p','Цена и наличие требуют подтверждения менеджером перед оформлением.').className='tsPrintNotice';
    dialog.append(root);document.body.classList.add('tsPrinting');controls();window.print();
  }catch(_error){clearPrint();controls();status('Не удалось открыть печать. Версия подборки сохранена, попробуйте ещё раз.',true)}
}
function renderPreview(){
  cancelImageExport();
  dialog.classList.add('tsPreviewMode');photoFailures=0;photoPending=publication.cards.reduce((sum,card)=>sum+card.photos.length,0);const imageRun=++photoEpoch,token=epoch,publicationId=publication.id;
  $('tsEditor').innerHTML='<div class="tsPreviewHeading"><h3>Предпросмотр подборки</h3><p>'+esc(publication.cards.some(card=>card.photo_layout==='gallery-v1')?(publication.channel==='telegram'?'Telegram — фотографии отеля с перелистыванием.':'MAX — фотографии отеля.'):(publication.channel==='telegram'?'Telegram — карусель из трёх фото.':'MAX — мозаика из трёх фото.'))+' Проверьте фото, условия и общую цену. «Нравится» сохранит именно показанный вариант.</p></div><div class="tsPreviewCards">'+publication.cards.map(card=>`<article class="tsPreviewCard" data-ts-card="${card.index}"><div class="tsPhotos tsPhotoCount${card.photos.length} ${publication.channel==='telegram'?'tsPhotoSlideshow':'tsPhotoMosaic'}" ${publication.channel==='telegram'?'tabindex="0" role="region" aria-label="Фото отеля"':''}>${card.photos.map((url,i)=>`<img src="${esc(url)}" alt="${card.photo_layout==='gallery-v1'?'Фото отеля '+(i+1):['Отель','Пляж или бассейн','Пример интерьера номера'][i]}" referrerpolicy="no-referrer">`).join('')}</div>${publication.channel==='telegram'&&card.photos.length>1?'<div class="tsPhotoNav"><button type="button" data-ts-photo-step="-1" aria-label="Предыдущее фото">←</button><span class="tsPhotoPosition" aria-live="polite">Фото 1 из 3</span><button type="button" data-ts-photo-step="1" aria-label="Следующее фото">→</button></div>':''}<p class="tsCardText">${previewCaption(card)}</p><span class="tsLikePreview">♡ Нравится</span><p class="tsDeliveryState" data-ts-state="${card.index}"></p></article>`).join('')+'</div>';
  const heading=$('tsEditor').querySelector('.tsPreviewHeading'),design=appearanceSettings(publication.appearance);
  if(publication.appearance||publication.title!=='Подборка туров'||publication.intro){
    const header=document.createElement('div');header.className='tsSelectionHeading';
    const brand=document.createElement('p');brand.className='tsSelectionBrand';brand.textContent=design.agency_name;header.append(brand);
    const title=document.createElement('h4');title.textContent=publication.title;header.append(title);
    if(publication.intro){const intro=document.createElement('p');intro.className='tsSelectionIntro';intro.textContent=publication.intro;header.append(intro)}
    heading.append(header);
  }
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
  for(const card of publication.cards){const article=$('tsEditor').querySelector('[data-ts-card="'+card.index+'"]');if(!article)continue;const button=document.createElement('button');button.type='button';button.className='tsExportImage';button.dataset.tsImageExport=String(card.index);button.textContent='Подготовить картинку';button.onclick=()=>{if(active()&&button.isConnected)void exportCardImage(card)};article.querySelector('.tsLikePreview').before(button);}
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
  if(!current()||busy||sending||pending||conflict||biblioPanel||oneTouchPanel||offerComparison||invalidAges())return;
  historyView++;
  clearTimeout(timer);if(dirty)await save();if(dirty||busy||pending||conflict||!current())return;
  const token=epoch;busy=true;controls();status('Готовим предпросмотр…');const result=await request('prepare',{version});if(token!==epoch)return;busy=false;
  if(result?.ok&&result.publication){appearancePanel=false;previewIssues=[];publication=result.publication;preview=true;render();status('Предпросмотр готов. Проверьте подборку перед отправкой.');return}
  previewIssues=result?.error==='incomplete_offers'&&Array.isArray(result.fields)?result.fields.filter(x=>draft.offers.some(o=>o.id===x.offer_id&&o.selected)&&Array.isArray(x.fields)).map(x=>({offer_id:x.offer_id,fields:x.fields.filter(f=>Object.hasOwn(previewFieldNames,f))})):[];
  renderPreviewIssues();if(previewIssues.length)$('tsPreviewIssues')?.scrollIntoView({block:'start'});
  const names=previewFieldNames;
  if(result?.error==='card_too_long'){status('Сократите пояснения и дополнительные условия в варианте '+result.position+'. Условия не будут обрезаны при отправке.',true);controls();return}
  status(result?.error==='incomplete_offers'?'Заполните '+(result.fields||[]).map(x=>'в варианте '+x.position+': '+x.fields.map(f=>f==='photos'&&!draft.offers.find(o=>o.id===x.offer_id)?.import_source?'три фото':names[f]||f).join(', ')).join('; ')+'.':error(result),true);controls();
}
async function showHistory(){
  if(!current()||busy||sending||bulkPrice||biblioPanel||oneTouchPanel||appearancePanel||offerComparison)return;const token=epoch,view=++historyView;busy=true;controls();const result=await request('history');if(token!==epoch||view!==historyView||!current()||!dialog.open||appearancePanel||offerComparison)return;busy=false;controls();
  if(!result?.ok){status(error(result),true);return}
  const root=$('tsRecovery');root.replaceChildren();root.hidden=false;
  if(!result.publications.length){root.textContent='Пока нет подготовленных к отправке подборок.';return}
  const heading=document.createElement('p');heading.textContent='Подготовленные подборки и результаты отправки:';root.appendChild(heading);
  for(const entry of result.publications){const button=document.createElement('button');button.type='button';button.textContent='Версия '+entry.draft_version+' · '+entry.created_at+' UTC';button.onclick=async()=>{if(!current()||!dialog.open||busy||sending||sharing||bulkPrice||biblioPanel||oneTouchPanel||appearancePanel||offerComparison)return;const t=epoch,selectedView=++historyView;const read=await request('publication',{publication_id:entry.publication_id});if(t!==epoch||selectedView!==historyView||!current()||!dialog.open||biblioPanel||oneTouchPanel||appearancePanel||offerComparison)return;if(read?.ok&&read.publication?.id===entry.publication_id){publication=read.publication;preview=true;render();recovery('');status('Показана сохранённая версия и результат отправки.')}else status(error(read),true)};root.appendChild(button)}
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
  if(!current()||offerComparison||sending||!publication?.can_send||photoFailures||photoPending)return;const target=publication.id,token=epoch;sending=true;controls();
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
  if(!current()||offerComparison||sharing||(!canEdit&&!(action==='revoke'&&share.can_revoke))||sending)return;
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
