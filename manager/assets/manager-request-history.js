(function(){
'use strict';
const text=(v,n)=>typeof v==='string'&&[...v].length<=n;
const positive=v=>Number.isSafeInteger(v)&&v>0,nonnegative=v=>Number.isSafeInteger(v)&&v>=0;
const node=(tag,value='',className='')=>{const el=document.createElement(tag);el.textContent=value;if(className)el.className=className;return el};
const dimensions={value:'значение',kind:'происхождение',importance:'важность',note:'комментарий',citation:'основание'};
const kinds={customer_statement:'Слова туриста',manager_interpretation:'Трактовка менеджера'},importance={hard:'Обязательное',preferred:'Желательное',unknown:'Важность не указана'};
function time(value){
 if(typeof value!=='string'||!/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/.test(value))return 'Время не подтверждено';
 const date=new Date(value);if(!Number.isFinite(+date)||date.toISOString()!==value.replace('Z','.000Z'))return 'Время не подтверждено';
 return new Intl.DateTimeFormat('ru-RU',{timeZone:'Europe/Moscow',year:'numeric',month:'2-digit',day:'2-digit',hour:'2-digit',minute:'2-digit',second:'2-digit',hourCycle:'h23'}).format(date)+' МСК';
}
function citation(value){return value===null||!!value&&positive(value.message_id)&&['customer','manager'].includes(value.sender_type)&&text(value.quote,600)}
function field(value,key){
 if(value===null)return true;if(!value||!Object.hasOwn(kinds,value.kind)||!Object.hasOwn(importance,value.importance)||!text(value.note,200)||!citation(value.citation))return false;
 if(key!=='party')return text(value.value,400)&&value.value.trim()!=='';
 const p=value.value;return !!p&&Number.isInteger(p.adults)&&p.adults>=1&&p.adults<=20&&Number.isInteger(p.children)&&p.children>=0&&p.children<=12&&(p.child_ages===null?p.children>0:Array.isArray(p.child_ages)&&p.child_ages.length===p.children&&p.child_ages.every(a=>Number.isInteger(a)&&a>=0&&a<=17));
}
function create({root,request,labels}){
 let scope=null,revision=0,read=null,sourceRead=null,sourceRevision=0,version=0,snapshot=0,before=0,more=false,busy=false,opened=false,events=[],summaries=[],dialog=null,returnFocus=null;
 let openButton,refreshButton,olderButton,status,list,summary;
 const same=c=>!!scope&&scope.actor===c.actor&&scope.cid===c.cid;
 const current=(c,ticket)=>same(c)&&ticket===revision;
 function closeSource(){sourceRevision++;sourceRead?.abort();sourceRead=null;if(dialog?.open)dialog.close();dialog?.querySelector('.profileHistorySourceBody')?.replaceChildren();if(returnFocus?.isConnected)returnFocus.focus({preventScroll:true});returnFocus=null;}
 function reset(){revision++;read?.abort();read=null;returnFocus=null;closeSource();dialog?.remove();dialog=null;scope=null;events=[];summaries=[];opened=false;busy=false;snapshot=before=version=0;more=false;root.replaceChildren();}
 function controls(){
  root.replaceChildren();const actions=node('div','','profileHistoryControls');openButton=node('button','Показать «было → стало»','usageReview');openButton.type='button';openButton.id='profileHistoryOpen';openButton.onclick=()=>load(false);
  refreshButton=node('button','Обновить историю','usageReview');refreshButton.type='button';refreshButton.id='profileHistoryRefresh';refreshButton.onclick=()=>load(false);
  olderButton=node('button','Более ранние версии','usageReview');olderButton.type='button';olderButton.id='profileHistoryOlder';olderButton.onclick=()=>load(true);
  actions.append(openButton,refreshButton,olderButton);status=node('p','','profileHistoryStatus');status.id='profileHistoryStatus';status.setAttribute('role','status');status.setAttribute('aria-live','polite');summary=node('div','','profileHistorySummary');list=node('div','','profileHistoryList');root.append(actions,status,summary,list);
 }
 function showControls(){if(!scope)return;openButton.hidden=opened;refreshButton.hidden=!opened;olderButton.hidden=!opened||!more;for(const button of [openButton,refreshButton,olderButton])button.disabled=busy;summary.hidden=opened;}
 function renderSummary(){summary.replaceChildren();for(const item of summaries){const author=text(item.author_name,160)&&item.author_name.trim()?item.author_name:'Менеджер №'+item.manager_id;summary.appendChild(node('p',`Версия ${item.version} · ${author} · ${time(item.recorded_at)}`))}if(!summaries.length)summary.appendChild(node('p','Сохранённых версий пока нет.'));}
 function sync({actor,conversationId,latestVersion,history}){
  if(!positive(actor)||!positive(conversationId)||!nonnegative(latestVersion)){reset();return;}
  const c={actor,cid:conversationId};if(!same(c)){reset();scope=c;controls();}
  version=latestVersion;summaries=Array.isArray(history)?history:[];renderSummary();
  if(opened&&snapshot<version&&!busy)status.textContent='Есть новая сохранённая версия. Обновите историю, чтобы увидеть её.';showControls();
 }
 function validPage(r,append){
  if(r?.ok!==true||r.viewer_id!==scope.actor||r.conversation_id!==scope.cid||!nonnegative(r.snapshot_version)||!nonnegative(r.before_version)||typeof r.has_more!=='boolean'||!Array.isArray(r.events)||r.events.length>3||append&&r.snapshot_version!==snapshot)return false;
  let prior=append?before:r.snapshot_version+1;
  for(const e of r.events){
   if(!e||!positive(e.version)||e.version>=prior||e.version>r.snapshot_version||!text(e.label,120)||!/^[a-f0-9]{32}$/.test(e.trip_id)||!['initial','new_trip','same_trip','previous_unavailable'].includes(e.baseline)||!e.author||!positive(e.author.id)||e.author.name!==null&&!text(e.author.name,160)||e.recorded_at!==null&&!text(e.recorded_at,20)||typeof e.label_changed!=='boolean'||typeof e.offer_changed!=='boolean'||e.label_before!==null&&!text(e.label_before,120)||!Array.isArray(e.changes)||e.changes.length>12)return false;
   if(e.baseline==='initial'&&e.version!==1||(e.baseline==='same_trip'?e.previous_version!==e.version-1:e.previous_version!==null))return false;
   const keys=new Set();for(const change of e.changes){if(!change||!Object.hasOwn(labels,change.field)||keys.has(change.field)||!text(change.label,80)||!field(change.before,change.field)||!field(change.after,change.field)||e.baseline!=='same_trip'&&change.before!==null||change.before===null&&change.after===null||!Array.isArray(change.dimensions)||!change.dimensions.length||new Set(change.dimensions).size!==change.dimensions.length||change.dimensions.some(d=>!Object.hasOwn(dimensions,d)))return false;keys.add(change.field);}prior=e.version;
  }
  return r.before_version===(r.events.at(-1)?.version||0)&&(!r.has_more||r.events.length>0);
 }
 async function load(append){
  if(!scope||busy||append&&!more)return;const captured={...scope},ticket=++revision;read?.abort();read=new AbortController();closeSource();busy=true;status.textContent='Загружаем сохранённые изменения…';showControls();let result;
  try{result=await request('history',append?{before_version:before,snapshot_version:snapshot}:{},{signal:read.signal,isCurrent:()=>current(captured,ticket)})}catch{result=null}
  if(!current(captured,ticket))return;busy=false;read=null;
  if(!validPage(result,append)){status.textContent=result?.error==='superseded'?'Данные обновились. Повторите загрузку истории.':'Не удалось загрузить историю. Можно повторить; ваш черновик сохранён.';showControls();return;}
  events=append?events.concat(result.events):result.events;snapshot=result.snapshot_version;before=result.before_version;more=result.has_more;opened=true;render();status.textContent=events.length?(more?'Показана часть истории. Можно открыть более ранние версии.':'Все доступные сохранённые версии показаны.'):'Сохранённых версий пока нет.';if(snapshot<version)status.textContent+=' Есть новая версия — обновите историю.';showControls();
 }
 function valueText(value){if(typeof value==='string')return value;return `Взрослых: ${value.adults}; детей: ${value.children}`+(value.children?`; возраст: ${value.child_ages===null?'уточняется':value.child_ages.join(', ')}`:'');}
 function side(event,change,key){
  const f=change[key],box=node('div','','profileHistorySide');box.dataset.historySide=key;box.appendChild(node('h5',key==='before'?'Было':'Стало'));
  if(!f){box.appendChild(node('p',key==='before'&&event.baseline==='previous_unavailable'?'Прежняя версия недоступна':'Не зафиксировано','profileHistoryValue'));return box;}
  box.appendChild(node('p',valueText(f.value),'profileHistoryValue'));box.appendChild(node('p',kinds[f.kind]+' · '+importance[f.importance],'profileHistoryAnnotation'));if(f.note)box.appendChild(node('p','Комментарий менеджера: '+f.note,'profileHistoryAnnotation'));
  if(f.citation){box.appendChild(node('blockquote',f.citation.quote,'profileHistoryQuote'));const button=node('button','К сообщению-основанию','usageReview');button.type='button';button.dataset.historySource=`${event.version}:${change.field}:${key}`;button.onclick=()=>openSource(event.version,change.field,key,button);box.appendChild(button);}else box.appendChild(node('p','Без сообщения-основания — уточнение менеджера.','profileHistoryAnnotation'));return box;
 }
 function render(){
  list.replaceChildren();for(const event of events){const card=node('article','','profileHistoryEvent');card.dataset.historyVersion=String(event.version);card.appendChild(node('h3','Версия '+event.version+' · '+event.label));card.appendChild(node('p',(event.author.name||'Менеджер №'+event.author.id)+' · '+time(event.recorded_at),'profileHistoryMeta'));
   const baselines={initial:'Первая сохранённая версия этой поездки.',new_trip:'Начата другая поездка. Её условия показаны отдельно от прежней.',previous_unavailable:'Предыдущая версия недоступна. Показаны известные условия этой записи.'};if(baselines[event.baseline])card.appendChild(node('p',baselines[event.baseline],'profileHistoryBoundary'));
   if(event.label_changed)card.appendChild(node('p','Название запроса: '+(event.baseline==='same_trip'?event.label_before+' → ':'')+event.label,'profileHistoryAnnotation'));
   if(event.offer_changed)card.appendChild(node('p','Изменено подтверждение предложения для поездки.','profileHistoryAnnotation'));
   if(!event.changes.length&&!event.label_changed&&!event.offer_changed)card.appendChild(node('p','Повторная сверка — сохранённые условия не изменились.'));
   for(const change of event.changes){const item=node('section','','profileHistoryChange');item.dataset.historyField=change.field;item.appendChild(node('h4',change.label));item.appendChild(node('p','Изменено: '+change.dimensions.map(d=>dimensions[d]).join(', '),'profileHistoryAnnotation'));const sides=node('div','','profileHistorySides');sides.append(side(event,change,'before'),side(event,change,'after'));item.appendChild(sides);card.appendChild(item);}list.appendChild(card);
  }
 }
 function ensureDialog(){if(dialog)return;dialog=document.createElement('dialog');dialog.id='profileHistorySourceDialog';dialog.className='profileHistorySourceDialog';const header=node('header'),title=node('h2','Сообщение-основание');title.id='profileHistorySourceTitle';dialog.setAttribute('aria-labelledby',title.id);const close=node('button','Вернуться к истории','usageReview');close.type='button';close.id='profileHistorySourceClose';close.onclick=closeSource;header.append(title,close);const body=node('div','','profileHistorySourceBody');body.setAttribute('role','status');dialog.append(header,body);dialog.addEventListener('cancel',event=>{event.preventDefault();closeSource()});dialog.addEventListener('close',()=>{if(dialog.open)return;sourceRevision++;sourceRead?.abort();sourceRead=null;body.replaceChildren();if(returnFocus?.isConnected)returnFocus.focus({preventScroll:true});returnFocus=null});document.body.appendChild(dialog);}
 async function openSource(target,fieldName,sideName,button){
  if(!scope)return;const captured={...scope},pageTicket=revision,ticket=++sourceRevision;sourceRead?.abort();sourceRead=new AbortController();ensureDialog();returnFocus=button;const body=dialog.querySelector('.profileHistorySourceBody');body.replaceChildren(node('p','Открываем сообщение…'));if(!dialog.open)dialog.showModal();
  const valid=()=>current(captured,pageTicket)&&ticket===sourceRevision&&dialog.open;
  let r;try{r=await request('history_source',{version:target,field:fieldName,side:sideName},{signal:sourceRead.signal,isCurrent:valid})}catch{r=null}
  if(!valid())return;sourceRead=null;
  const good=r?.ok===true&&r.viewer_id===scope.actor&&r.conversation_id===scope.cid&&r.version===target&&r.field===fieldName&&r.side===sideName&&typeof r.available==='boolean'&&(r.available?['matches_saved','changed'].includes(r.source_state)&&r.citation&&citation(r.citation)&&r.message&&positive(r.message.id)&&r.message.id===r.citation.message_id&&['customer','manager'].includes(r.message.sender_type)&&text(r.message.text,6000)&&typeof r.message.truncated==='boolean':r.source_state==='unavailable'&&!r.message)&&(!r.citation||citation(r.citation));
  body.replaceChildren();if(!good){body.appendChild(node('p','Не удалось открыть сообщение. Черновик сохранён.'));const retry=node('button','Повторить','usageReview');retry.type='button';retry.onclick=()=>openSource(target,fieldName,sideName,button);body.appendChild(retry);return;}
  if(r.citation){body.appendChild(node('h3','Цитата при сохранении'));body.appendChild(node('blockquote',r.citation.quote,'profileHistoryQuote'));}
  const states={matches_saved:'Сообщение совпадает с сохранённым основанием.',changed:'Основание изменилось после сохранения. Историческая цитата оставлена отдельно.',unavailable:'Сообщение-основание недоступно. Это не подтверждает его удаление.'};body.appendChild(node('p',states[r.source_state],'profileHistoryBoundary'));
  if(r.available){body.appendChild(node('h3','Сообщение №'+r.message.id+' · '+(r.message.sender_type==='customer'?'Турист':r.message.sender_type==='manager'?'Менеджер':'Система')));body.appendChild(node('p',r.message.text,'profileHistoryOriginal'));if(r.message.truncated)body.appendChild(node('p','Показан сохранённый фрагмент сообщения.'));}
 }
 return {sync,reset};
}
window.ManagerRequestHistory={create};
})();
