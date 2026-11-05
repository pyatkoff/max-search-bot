(function(){
'use strict';
const W=window.WorkspaceV2;if(!W)return;
const {S,esc}=W,MAX_OFFERS=5;
const bookingDrafts=new Map();let bookingDraftScope='';
function text(value,limit=512){return typeof value==='string'?(value.length>limit?value.slice(0,limit)+'…':value).trim():''}
function row(label,value,limit=600){value=text(value,limit);return value?`<div class="leadDetailRow"><span class="label">${esc(label)}</span><span class="value">${esc(value)}</span></div>`:''}
function date(value){const match=/^(\d{4})-(\d{2})-(\d{2})$/.exec(text(value,10));return match?`${match[3]}.${match[2]}.${match[1]}`:''}
function count(value,min,max){return Number.isInteger(value)&&value>=min&&value<=max?String(value):''}
function price(snapshot){
  const amount=text(snapshot.amount,32),currency=text(snapshot.currency,3);
  if(!/^\d{1,12}(?:\.\d{1,2})?$/.test(amount)||!['RUB','EUR','USD'].includes(currency))return 'Цена не указана';
  const [whole,fraction]=amount.split('.'),formatted=whole.replace(/\B(?=(\d{3})+(?!\d))/g,' ')+(fraction?','+fraction:'');
  return `${formatted} ${({RUB:'₽',EUR:'€',USD:'$'})[currency]}${snapshot.price_basis==='party'?' за всех':''}`;
}

function bookingButton(kind,id){
  return typeof W.pipe==='function'&&Number.isSafeInteger(id)&&id>0?'<button type="button" class="actionBtn" data-booking-kind="'+kind+'" data-booking-source="'+id+'">Оформление бронирования</button>':'';
}
function bookingFieldsMarkup(fields={},editable=false){
  const input=(key,label,max=300,type='text')=>'<label>'+esc(label)+'<input class="stageSelect" data-booking-field="'+key+'" type="'+type+'" maxlength="'+max+'" value="'+esc(type==='datetime-local'?(fields._localDates?(fields[key]||''):(window.ManagerTime?.toInput(fields[key])||'')):(fields[key]||''))+'"></label>';
  const area=(key,label,max)=>'<label>'+esc(label)+'<textarea class="outcomeNote" data-booking-field="'+key+'" maxlength="'+max+'">'+esc(fields[key]||'')+'</textarea></label>';
  return '<fieldset '+(editable?'':'disabled')+'><label>Состояние<select class="stageSelect" data-booking-field="state">'+Object.entries({checking:'Проверяем условия',ready:'Условия согласованы',confirmed:'Бронь подтверждена оператором',cancelled:'Отменено'}).map(([v,label])=>'<option value="'+v+'" '+((fields.state||'checking')===v?'selected':'')+'>'+label+'</option>').join('')+'</select></label>'+
    input('amount','Подтверждённая полная сумма за всех',16)+input('currency','Валюта: RUB, EUR или USD',3)+
    input('checked_at','Условия проверены (МСК)',20,'datetime-local')+input('verification_source','Где проверены цена и наличие',300)+
    '<label><input type="checkbox" data-booking-field="available" '+(fields.available?'checked':'')+'> Наличие подтверждено</label>'+
    area('terms','Подтверждённые условия: даты, состав, номер, питание, рейсы, багаж, сборы и условия оформления',2500)+
    '<label><input type="checkbox" data-booking-field="customer_agreed" '+(fields.customer_agreed?'checked':'')+'> Турист согласовал эти условия и сумму</label>'+
    area('agreement_evidence','Подтверждение туриста: дата и ссылка или номер сообщения',500)+
    input('operator','Туроператор',160)+input('booking_reference','Номер подтверждённой брони у оператора',160)+
    input('confirmed_at','Бронь подтверждена (МСК)',20,'datetime-local')+
    area('cancellation_reason','Причина отмены, если отменено',500)+
    '<button type="submit" class="actionBtn primary">Сохранить оформление</button></fieldset>';
}
function bookingError(code){
  return ({conflict:'Запись уже изменена. Откройте сохранённую запись перед следующим изменением.',verification_required:'Для согласованных условий нужны проверенная полная сумма, наличие, условия и подтверждение туриста.',verification_expired:'Проверка старше суток. Обновите цену и наличие перед согласованием.',booking_evidence_required:'Укажите оператора, номер брони и время подтверждения.',cancellation_reason_required:'Укажите причину отмены.',invalid_transition:'Подтверждённую бронь можно исправить или отменить; отменённая запись остаётся в истории.',source_unavailable:'Этот выбор больше не доступен для начала оформления.',context_changed:'Контекст клиента изменился. Сохранение остановлено.',forbidden:'Изменять может ответственный менеджер или администратор.',invalid_fields:'Проверьте сумму, валюту и даты. Будущее время не принимается.'})[code]||'Сохранение не подтверждено. Проверьте его исход.';
}
function bindBookings(root,conversation){
  if(!root.querySelectorAll||typeof W.pipe!=='function')return;
  for(const button of root.querySelectorAll('[data-booking-kind]')){
    button.onclick=async()=>{
      const kind=button.dataset.bookingKind,id=Number(button.dataset.bookingSource),actor=Number(S.manager?.id||0),generation=S.authGeneration;
      if(!actor||!['native','web','search'].includes(kind)||!Number.isSafeInteger(id))return;
      const current=()=>Number(S.current)===conversation&&Number(S.detail?.conversation?.id)===conversation&&Number(S.manager?.id)===actor&&S.authGeneration===generation&&!S.authExpired&&button.isConnected;
      const scope=actor+':'+generation;if(bookingDraftScope!==scope){bookingDrafts.clear();bookingDraftScope=scope}
      const parent=button.parentElement;
      let panel=parent.querySelector('[data-booking-panel]');
      if(panel){panel.remove();return}
      panel=document.createElement('div');panel.dataset.bookingPanel='';panel.className='tourBooking';
      parent.appendChild(panel);
      const key='anytour-booking-v1:'+actor+':'+conversation+':'+kind+':'+id;
      let pending='',record=null,canEdit=false,busy=false;
      const getPending=()=>{try{const op=sessionStorage.getItem(key)||'';return /^[a-f0-9]{32}$/.test(op)?op:''}catch(e){return 'storage-unavailable'}};
      const clearPending=expected=>{try{if(sessionStorage.getItem(key)===expected)sessionStorage.removeItem(key);pending=getPending()}catch(e){/* Keep the original fence on storage failure. */}};
      const say=message=>{const node=panel.querySelector('[data-booking-status]');if(node)node.textContent=message};
      const active=()=>current()&&panel.isConnected;
      function render(message=''){
        panel.innerHTML='<div class="leadPanelTitle">От выбора до бронирования</div><p class="leadPanelHint">Точные выбранные условия сохранены выше. Цена из подборки не становится подтверждённой автоматически. Здесь фиксируется работа менеджера и уже полученное подтверждение оператора. Сохранение не отправляет заявку, сообщение или платёж.</p>'+
          (record?'<p>Сохранено: '+esc(({checking:'проверка условий',ready:'условия согласованы',confirmed:'бронь подтверждена оператором',cancelled:'отменено'})[record.fields.state])+' · версия '+Number(record.revision)+'</p>':'<p>Оформление ещё не сохранено.</p>')+
          '<form data-booking-form>'+bookingFieldsMarkup(bookingDrafts.get(key)?.fields||record?.fields,canEdit&&!pending)+'</form><div role="status" aria-live="polite" data-booking-status>'+esc(message)+'</div><button class="actionBtn" type="button" data-booking-read>'+(pending?'Проверить сохранение':'Открыть сохранённую запись')+'</button>';
        panel.querySelector('[data-booking-read]').onclick=()=>read(true);
        panel.querySelector('form').oninput=()=>{const fields={_localDates:true};for(const n of panel.querySelectorAll('[data-booking-field]'))fields[n.dataset.bookingField]=n.type==='checkbox'?n.checked:n.value;const revision=bookingDrafts.get(key)?.revision??(record?.revision||0);bookingDrafts.set(key,{fields,revision});while(bookingDrafts.size>20)bookingDrafts.delete(bookingDrafts.keys().next().value)};
        panel.querySelector('form').onsubmit=event=>{event.preventDefault();save()};
      }
      async function read(discardDraft=false){
        if(busy||!active())return;if(discardDraft&&!getPending())bookingDrafts.delete(key);busy=true;pending=getPending();if(pending==='storage-unavailable'){busy=false;canEdit=false;render('Не удалось прочитать защиту от повтора. Откройте сохранённую запись после восстановления хранилища.');return}panel.textContent='Открываем оформление…';
        let result;try{result=await W.pipe('booking_read',{conversation_id:conversation,source_kind:kind,source_id:id,operation_id:pending})}catch(e){result=null}
        busy=false;if(!active())return;
        if(result?.ok){
          record=result.record;canEdit=result.can_edit===true;
          if(pending&&result.operation_found){if(getPending()===pending&&bookingDraftScope===scope)bookingDrafts.delete(key);clearPending(pending)}
          render(pending?'Исход сохранения пока неизвестен. Повторная запись заблокирована.':result.operation_found?'Сохранение подтверждено.':'');
        }else{canEdit=false;if(['forbidden','not_found','unauthorized'].includes(result?.error))record=null;render(bookingError(result?.error));}
      }
      async function save(){
        if(busy||pending||!canEdit||!active())return;
        const fields={};
        for(const node of panel.querySelectorAll('[data-booking-field]')){
          const name=node.dataset.bookingField;
          if(node.type==='checkbox')fields[name]=node.checked;
          else if(node.type==='datetime-local'){
            const iso=node.value?window.ManagerTime?.fromInput(node.value):'';
            if(node.value&&!iso){say('Укажите корректное время по Москве.');return}
            fields[name]=iso?new Date(iso).toISOString().replace(/\.\d{3}Z$/,'Z'):'';
          }else fields[name]=node.value.trim();
        }
        let op;
        try{
          const bytes=new Uint8Array(16);crypto.getRandomValues(bytes);op=Array.from(bytes,x=>x.toString(16).padStart(2,'0')).join('');
          sessionStorage.setItem(key,op);if(sessionStorage.getItem(key)!==op)throw Error('storage');
        }catch(e){say('Не удалось сохранить защиту от повтора. Запись не отправлена.');return}
        pending=op;busy=true;panel.querySelector('fieldset').disabled=true;say('Сохраняем…');
        let result;try{result=await W.pipe('booking_save',{conversation_id:conversation,source_kind:kind,source_id:id,revision:bookingDrafts.get(key)?.revision??(record?.revision||0),operation_id:op,fields})}catch(e){result=null}
        busy=false;if(result?.ok&&getPending()===op&&bookingDraftScope===scope)bookingDrafts.delete(key);
        const rejected=['invalid_source','invalid_revision','invalid_operation','invalid_fields','verification_required','verification_expired','booking_evidence_required','cancellation_reason_required','invalid_transition','conflict','context_changed','forbidden','source_unavailable'];
        if(result?.ok||rejected.includes(result?.error))clearPending(op);
        if(!active())return;
        if(result?.ok){record=result.record;render(pending?'Запись сохранена, но локальная защита ещё не снята.':'Оформление сохранено.')}
        else if(pending){say('Исход сохранения неизвестен. Нажмите «Проверить сохранение»; повторная запись заблокирована.');panel.querySelector('[data-booking-read]').textContent='Проверить сохранение'}
        else {panel.querySelector('fieldset').disabled=false;say(bookingError(result?.error));}
      }
      await read();
    };
  }
}

function offerMarkup(offer){
  const snapshot=offer.snapshot,ages=Array.isArray(snapshot.child_ages)?snapshot.child_ages.filter(age=>Number.isInteger(age)&&age>=0&&age<=17).slice(0,12):[];
  const adults=count(snapshot.adults,1,20),party=[adults?`Взрослых: ${adults}`:'',ages.length?`Возраст детей: ${ages.join(', ')}`:''].filter(Boolean).join(' · ');
  const quoted=snapshot.quoted_details&&typeof snapshot.quoted_details==='object'?snapshot.quoted_details:null;
  const dates=quoted?'С '+date(snapshot.date_from):[date(snapshot.date_from),date(snapshot.date_to)].filter(Boolean).join(' — '),nights=count(snapshot.nights,1,365);
  const flights=Array.isArray(snapshot.flights)?snapshot.flights.slice(0,6).map(flight=>text(flight,300)).filter(Boolean).join(' · '):'';
  return `<details class="leadDetails tourChoice"><summary><span class="tourChoiceSummary"><span class="tourChoiceHotel">${snapshot.is_demo===true?'<span class="leadReadonly">Демо · </span>':''}${esc(text(snapshot.hotel,191))}</span><span class="tourChoicePrice">${esc(price(snapshot))}</span></span></summary><div class="leadDetailsBody">${snapshot.is_demo===true?'<div class="leadReadonly">Демонстрационный вариант</div>':''}<div class="leadPanelHint">Цена при отправке; актуальность и наличие нужно проверить.</div>${row('Вылет',text(snapshot.departure,160))}${row('Даты',dates)}${row('Ночей',nights)}${row('Состав',party)}${row('Номер',text(snapshot.room))}${row('Питание',text(snapshot.meal))}${row('Перелёт',flights,2000)}${quoted?row('Перелёт и багаж',quoted.flights,1200)+row('Включено',quoted.inclusions,600)+row('Уточнить',quoted.constraints,1200)+row('Источник',quoted.source_label,160):''}${row('Почему подходит',text(snapshot.rationale,600))}${row('Компромисс',text(snapshot.compromise,600))}${bookingButton('native',offer.offer_id)}</div></details>`;
}
function searchMarkup(item){
  const o=item.observation,t=o.trip||{},s=o.stay||{},f=o.flights||{},p=o.price||{};
  const ages=Array.isArray(t.childAges)?t.childAges.filter(age=>Number.isInteger(age)&&age>=0&&age<=17).slice(0,3):[];
  const party=[count(t.adults,1,6)?`Взрослых: ${count(t.adults,1,6)}`:'',ages.length?`Возраст детей: ${ages.join(', ')}`:''].filter(Boolean).join(' · ');
  const flight=leg=>{
    if(text(leg?.label,1000))return text(leg.label,1000);
    const point=value=>[text(value?.date,80),text(value?.time,80),text(value?.town,160),text(value?.port,160),text(value?.portCode,80)].filter(Boolean).join(' ');
    return [text(leg?.number,120),text(leg?.carrier,180),point(leg?.departure),point(leg?.arrival),text(leg?.baggage,160)?'Багаж: '+text(leg.baggage,160):'',text(leg?.carryOn,160)?'Ручная кладь: '+text(leg.carryOn,160):''].filter(Boolean).join(' · ');
  };
  const direction=value=>Array.isArray(value)?value.slice(0,4).map(flight).filter(Boolean).join(' / '):'';
  const flightText=f.state==='selected'?row('Туда',direction(f.outward),12000)+row('Обратно',direction(f.inward),12000):row('Перелёт',f.state==='manager-deferred'?'Подбор рейсов с менеджером':'Рейсы не выбраны');
  const qualification=item.price_state_now==='expired'?'Срок расчёта истёк.':p.amount===null?'Цена неизвестна.':p.state==='estimated'?'Расчётная сумма.':'Цена, показанная в поиске.';
  let observed='';
  if(Number.isSafeInteger(o.capturedAt)&&o.capturedAt>0&&o.capturedAt<=8640000000000000){const iso=new Date(o.capturedAt).toISOString();observed=window.ManagerTime?.format?window.ManagerTime.format(iso,{year:true})+' МСК':iso;}
  const source=({anex:'ANEX',tourvisor:'Tourvisor',andromeda:'Andromeda'})[o.source]||'';
  return `<details class="leadDetails tourChoice"><summary><span class="tourChoiceSummary"><span class="tourChoiceHotel">${esc(text(o.hotel?.name,191))}</span><span class="tourChoicePrice">${esc(price({amount:p.amount,currency:p.currency,price_basis:'party'}))}</span></span></summary><div class="leadDetailsBody"><div class="leadPanelHint">${esc(qualification)} Полную стоимость и наличие необходимо подтвердить.</div>${row('Курорт',o.hotel?.resort,300)}${row('Вылет',t.origin,180)}${row('С даты',date(t.departure))}${row('Ночей',count(t.nights,1,365))}${row('Состав',party)}${row('Номер',s.room,500)}${row('Питание',s.meal,300)}${row('Размещение',s.placement,500)}${row('Оператор',s.operator,300)}${flightText}${row('Источник',source)}${row('Условия получены',observed)}${bookingButton('search',item.inquiry_id)}</div></details>`;
}
function markup(projection){
  if(!projection||!Array.isArray(projection.offers))return '';
  const liked=projection.offers.slice(0,20).filter(offer=>offer&&text(offer.liked_at,64)&&offer.snapshot&&typeof offer.snapshot==='object'&&text(offer.snapshot.hotel,160));
  const inquiries=Array.isArray(projection.web_inquiries)?projection.web_inquiries.slice(0,20).filter(item=>item&&item.public_offer&&text(item.public_offer.hotel,191)):[];
  const searches=Array.isArray(projection.search_inquiries)?projection.search_inquiries.slice(0,20).filter(item=>item?.observation&&text(item.observation.hotel?.name,191)):[];
  if(!liked.length&&!inquiries.length&&!searches.length)return '';
  const limited=projection.limited===true||projection.offers.length>20||liked.length>MAX_OFFERS;
  const native=liked.length?`<div class="leadPanelHead"><div><div class="leadPanelTitle">Понравившиеся туры</div><div class="leadPanelHint">Клиент отметил эти варианты в подборке</div></div></div>${liked.slice(0,MAX_OFFERS).map(offerMarkup).join('')}`:'';
  const web=inquiries.length?`<div class="leadPanelHead"><div><div class="leadPanelTitle">Обращения из веб-подборки</div><div class="leadPanelHint">Турист перешёл в этот диалог с выбранным вариантом</div></div></div>${inquiries.slice(0,MAX_OFFERS).map(item=>{const o=item.public_offer;return `<details class="leadDetails tourChoice"><summary><span class="tourChoiceSummary"><span class="tourChoiceHotel">${esc(text(o.hotel,191))}</span><span class="tourChoicePrice">${esc(price({...o,price_basis:'party'}))}</span></span></summary><div class="leadDetailsBody"><div class="leadPanelHint">Вариант ${Number(item.variant)||'—'} · версия ${Number(item.link_version)||'—'}. Показанные условия, актуальность уточняется.</div>${row('Направление',o.destination,300)}${row('Вылет',o.departure,160)}${row('С даты',date(o.date_from))}${row('Ночей',count(o.nights,1,60))}${row('Состав',`Взрослых: ${count(o.adults,1,20)} · детей: ${count(o.children,0,12)}`)}${row('Номер',o.room,300)}${row('Питание',o.meal,160)}${row('Перелёт',o.flights,1200)}${row('Включено',o.inclusions,600)}${row('Уточнить',o.constraints,1200)}${row('Почему подходит',o.rationale,600)}${row('Компромисс',o.compromise,600)}${row('Условия получены',o.observed_at,64)}${bookingButton('web',item.inquiry_id)}</div></details>`}).join('')}`:'';
  const search=searches.length?`<div class="leadPanelHead"><div><div class="leadPanelTitle">Выбранные туры из поиска</div><div class="leadPanelHint">Турист продолжил в этом диалоге с показанными условиями</div></div></div>${searches.slice(0,MAX_OFFERS).map(searchMarkup).join('')}`:'';
  return `<section class="leadPanel" data-tour-choices>${native}${web}${search}${limited||inquiries.length>MAX_OFFERS||searches.length>MAX_OFFERS?'<div class="leadPanelHint">Показаны последние доступные варианты; список неполный.</div>':''}</section>`;
}
function enhanceCard(conversation){
  const root=W.$('leadCard');if(!root)return;
  root.querySelector('[data-tour-choices]')?.remove();
  const id=Number(conversation?.id||0);
  if(!id||id!==Number(S.current||0)||id!==Number(S.detail?.conversation?.id||0))return;
  const html=markup(S.detail?.tour_choices);if(!html)return;
  const hero=root.querySelector('.leadHero');if(hero)hero.insertAdjacentHTML('afterend',html);else root.insertAdjacentHTML('beforeend',html);
  bindBookings(root,id);
}
window.WorkspaceV2TourChoices={markup,enhanceCard,bookingFieldsMarkup,bookingError};
})();
