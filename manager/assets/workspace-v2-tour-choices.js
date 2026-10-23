(function(){
'use strict';
const W=window.WorkspaceV2;if(!W)return;
const {S,esc}=W,MAX_OFFERS=5;
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
function offerMarkup(offer){
  const snapshot=offer.snapshot,ages=Array.isArray(snapshot.child_ages)?snapshot.child_ages.filter(age=>Number.isInteger(age)&&age>=0&&age<=17).slice(0,12):[];
  const adults=count(snapshot.adults,1,20),party=[adults?`Взрослых: ${adults}`:'',ages.length?`Возраст детей: ${ages.join(', ')}`:''].filter(Boolean).join(' · ');
  const quoted=snapshot.quoted_details&&typeof snapshot.quoted_details==='object'?snapshot.quoted_details:null;
  const dates=quoted?'С '+date(snapshot.date_from):[date(snapshot.date_from),date(snapshot.date_to)].filter(Boolean).join(' — '),nights=count(snapshot.nights,1,365);
  const flights=Array.isArray(snapshot.flights)?snapshot.flights.slice(0,6).map(flight=>text(flight,300)).filter(Boolean).join(' · '):'';
  return `<details class="leadDetails tourChoice"><summary><span class="tourChoiceSummary"><span class="tourChoiceHotel">${snapshot.is_demo===true?'<span class="leadReadonly">Демо · </span>':''}${esc(text(snapshot.hotel,191))}</span><span class="tourChoicePrice">${esc(price(snapshot))}</span></span></summary><div class="leadDetailsBody">${snapshot.is_demo===true?'<div class="leadReadonly">Демонстрационный вариант</div>':''}<div class="leadPanelHint">Цена при отправке; актуальность и наличие нужно проверить.</div>${row('Вылет',text(snapshot.departure,160))}${row('Даты',dates)}${row('Ночей',nights)}${row('Состав',party)}${row('Номер',text(snapshot.room))}${row('Питание',text(snapshot.meal))}${row('Перелёт',flights,2000)}${quoted?row('Перелёт и багаж',quoted.flights,1200)+row('Включено',quoted.inclusions,600)+row('Уточнить',quoted.constraints,1200)+row('Источник',quoted.source_label,160):''}${row('Почему подходит',text(snapshot.rationale,600))}${row('Компромисс',text(snapshot.compromise,600))}</div></details>`;
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
  return `<details class="leadDetails tourChoice"><summary><span class="tourChoiceSummary"><span class="tourChoiceHotel">${esc(text(o.hotel?.name,191))}</span><span class="tourChoicePrice">${esc(price({amount:p.amount,currency:p.currency,price_basis:'party'}))}</span></span></summary><div class="leadDetailsBody"><div class="leadPanelHint">${esc(qualification)} Полную стоимость и наличие необходимо подтвердить.</div>${row('Курорт',o.hotel?.resort,300)}${row('Вылет',t.origin,180)}${row('С даты',date(t.departure))}${row('Ночей',count(t.nights,1,365))}${row('Состав',party)}${row('Номер',s.room,500)}${row('Питание',s.meal,300)}${row('Размещение',s.placement,500)}${row('Оператор',s.operator,300)}${flightText}${row('Источник',source)}${row('Условия получены',observed)}</div></details>`;
}
function markup(projection){
  if(!projection||!Array.isArray(projection.offers))return '';
  const liked=projection.offers.slice(0,20).filter(offer=>offer&&text(offer.liked_at,64)&&offer.snapshot&&typeof offer.snapshot==='object'&&text(offer.snapshot.hotel,160));
  const inquiries=Array.isArray(projection.web_inquiries)?projection.web_inquiries.slice(0,20).filter(item=>item&&item.public_offer&&text(item.public_offer.hotel,191)):[];
  const searches=Array.isArray(projection.search_inquiries)?projection.search_inquiries.slice(0,20).filter(item=>item?.observation&&text(item.observation.hotel?.name,191)):[];
  if(!liked.length&&!inquiries.length&&!searches.length)return '';
  const limited=projection.limited===true||projection.offers.length>20||liked.length>MAX_OFFERS;
  const native=liked.length?`<div class="leadPanelHead"><div><div class="leadPanelTitle">Понравившиеся туры</div><div class="leadPanelHint">Клиент отметил эти варианты в подборке</div></div></div>${liked.slice(0,MAX_OFFERS).map(offerMarkup).join('')}`:'';
  const web=inquiries.length?`<div class="leadPanelHead"><div><div class="leadPanelTitle">Обращения из веб-подборки</div><div class="leadPanelHint">Турист перешёл в этот диалог с выбранным вариантом</div></div></div>${inquiries.slice(0,MAX_OFFERS).map(item=>{const o=item.public_offer;return `<details class="leadDetails tourChoice"><summary><span class="tourChoiceSummary"><span class="tourChoiceHotel">${esc(text(o.hotel,191))}</span><span class="tourChoicePrice">${esc(price({...o,price_basis:'party'}))}</span></span></summary><div class="leadDetailsBody"><div class="leadPanelHint">Вариант ${Number(item.variant)||'—'} · версия ${Number(item.link_version)||'—'}. Показанные условия, актуальность уточняется.</div>${row('Направление',o.destination,300)}${row('Вылет',o.departure,160)}${row('С даты',date(o.date_from))}${row('Ночей',count(o.nights,1,60))}${row('Состав',`Взрослых: ${count(o.adults,1,20)} · детей: ${count(o.children,0,12)}`)}${row('Номер',o.room,300)}${row('Питание',o.meal,160)}${row('Перелёт',o.flights,1200)}${row('Включено',o.inclusions,600)}${row('Уточнить',o.constraints,1200)}${row('Почему подходит',o.rationale,600)}${row('Компромисс',o.compromise,600)}${row('Условия получены',o.observed_at,64)}</div></details>`}).join('')}`:'';
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
}
window.WorkspaceV2TourChoices={markup,enhanceCard};
})();
