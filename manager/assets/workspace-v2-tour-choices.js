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
  const dates=[date(snapshot.date_from),date(snapshot.date_to)].filter(Boolean).join(' — '),nights=count(snapshot.nights,1,365);
  const flights=Array.isArray(snapshot.flights)?snapshot.flights.slice(0,6).map(flight=>text(flight,300)).filter(Boolean).join(' · '):'';
  return `<details class="leadDetails tourChoice"><summary><span class="tourChoiceSummary"><span class="tourChoiceHotel">${snapshot.is_demo===true?'<span class="leadReadonly">Демо · </span>':''}${esc(text(snapshot.hotel,191))}</span><span class="tourChoicePrice">${esc(price(snapshot))}</span></span></summary><div class="leadDetailsBody">${snapshot.is_demo===true?'<div class="leadReadonly">Демонстрационный вариант</div>':''}<div class="leadPanelHint">Цена при отправке; актуальность и наличие нужно проверить.</div>${row('Вылет',text(snapshot.departure,160))}${row('Даты',dates)}${row('Ночей',nights)}${row('Состав',party)}${row('Номер',text(snapshot.room))}${row('Питание',text(snapshot.meal))}${row('Перелёт',flights,2000)}${row('Почему подходит',text(snapshot.rationale,600))}${row('Компромисс',text(snapshot.compromise,600))}</div></details>`;
}
function markup(projection){
  if(!projection||!Array.isArray(projection.offers))return '';
  const liked=projection.offers.slice(0,20).filter(offer=>offer&&text(offer.liked_at,64)&&offer.snapshot&&typeof offer.snapshot==='object'&&text(offer.snapshot.hotel,160));
  if(!liked.length)return '';
  const limited=projection.limited===true||projection.offers.length>20||liked.length>MAX_OFFERS;
  return `<section class="leadPanel" data-tour-choices><div class="leadPanelHead"><div><div class="leadPanelTitle">Понравившиеся туры</div><div class="leadPanelHint">Клиент отметил эти варианты в подборке</div></div></div>${liked.slice(0,MAX_OFFERS).map(offerMarkup).join('')}${limited?'<div class="leadPanelHint">Показаны последние доступные варианты; список неполный.</div>':''}</section>`;
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
