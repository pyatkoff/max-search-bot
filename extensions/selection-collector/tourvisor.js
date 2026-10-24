(function(root){
'use strict';
const clean=v=>String(v??'').replace(/\s+/g,' ').trim(),fail=m=>{throw new Error(m)},visible=e=>!!e&&e.getBoundingClientRect().width>0&&e.getBoundingClientRect().height>0;
const one=(root,selector)=>{const es=Array.from(root.querySelectorAll(selector)).filter(visible);if(es.length!==1)fail('Карточка Tourvisor изменилась. Используйте ручное добавление.');return es[0]};
const text=(root,selector)=>clean(one(root,selector).innerText);
function extract(doc,href,now=new Date().toISOString().replace(/\.\d{3}Z$/,'Z')){
 const page=new URL(href);if(page.origin!=='https://pro.tourvisor.ru'||page.pathname!=='/search'||!/^#tvtourid=[1-9]\d{5,15}$/.test(page.hash))fail('Откройте конкретную карточку Tourvisor.');
 const card=one(doc,'.TVTourCardWindow'),snapshot=page.hash.slice(10),link=new URL(card.getAttribute('data-tvtourlink')||'');
 // This agency-configured share address is read only for its snapshot fragment.
 // It is never followed, persisted, or trusted as a Manager/TO origin.
 if(link.hash!==page.hash||link.protocol!=='https:'||link.username||link.password||link.port||link.pathname!=='/'||link.search)fail('Снимок карточки Tourvisor не совпал.');
 const menu=one(doc,'.TVMenuControl'),search=one(menu,'a.TVSearchLinkItem'),url=new URL(search.getAttribute('href'),page.origin);
 const required=['ts_dosearch','s_form_mode','s_nights_from','s_nights_to','s_directflight','s_regular','x_hotel_codes','s_j_date_from','s_j_date_to','s_adults','s_meal','s_flyfrom','s_country'];
 if(url.origin!==page.origin||url.pathname!=='/search'||url.hash||url.username||url.password||url.port||Array.from(url.searchParams).length!==required.length||required.some(k=>url.searchParams.getAll(k).length!==1)||Array.from(url.searchParams).some(([k,v])=>!required.includes(k)||!/^\d[\d.]{0,11}$/.test(v)))fail('Откройте меню именно этой карточки; условия поиска не поддерживаются.');
 const q=Object.fromEntries(url.searchParams);for(const [k,v]of Object.entries({ts_dosearch:'1',s_form_mode:'0',s_directflight:'0',s_regular:'1',s_adults:'2',s_meal:'7',s_flyfrom:'1',s_country:'1'}))if(q[k]!==v)fail('Поддержан только наблюдённый шаблон: Москва → Египет, 2 взрослых, AI, RUB.');
 const operator=one(card,'.TVTourCardOperatorControlLogoImage');if(operator.getAttribute('src')!=='https://tourvisor.ru/pics/operators/mobilelogo/13.svg')fail('Пока поддержан только снимок ANEX внутри Tourvisor.');
 const cardOperator=new URL(one(card,'a.TVTourCardOperatorControlWrapper').getAttribute('href')),menuOperator=new URL(one(menu,'a.TVOperatorLinkItem').getAttribute('href'));
 if(cardOperator.href!==menuOperator.href||cardOperator.origin!=='https://agent.anextour.ru'||cardOperator.pathname!=='/search/tour'||cardOperator.hash||cardOperator.username||cardOperator.password)fail('Меню относится к другой карточке. Откройте его заново.');
 const title=text(card,'.TVTourCardInfoTitle .TVHotelTitleName'),hotel=title.match(/^(.+) ([1-5])\*$/);if(!hotel)fail('Не удалось прочитать название и звёздность.');
 const option=(icon,part)=>text(one(card,'.TVTourCardOption.'+icon),'.TVTourCardOption'+part);
 const destination=text(card,'.TVTourCardInfoTitle .TVHotelTitleResort'),date=option('TVCalendarIcon','Header'),nightsText=option('TVCalendarIcon','Footer'),room=option('TVBedIcon','Header'),party=option('TVBedIcon','Footer'),meal=option('TVMealIcon','Footer'),tariff=option('TVInfoIcon','Footer');
 if(option('TVMapMarkerIcon','Header')!=='Египет'||option('TVMapMarkerIcon','Footer')!=='из Москвы'||party!=='2 взрослых'||meal!=='AI - Все Включено'||!destination.startsWith('Египет, '))fail('Условия карточки не совпали с поиском.');
 const months=['января','февраля','марта','апреля','мая','июня','июля','августа','сентября','октября','ноября','декабря'],d=q.s_j_date_from.match(/^(\d{2})\.(\d{2})\.(\d{4})$/),n=nightsText.match(/^([1-9]\d?) ночей$/);
 if(!d||q.s_j_date_to!==q.s_j_date_from||!n||q.s_nights_from!==n[1]||q.s_nights_to!==n[1]||+n[1]>60||date!==`${+d[1]} ${months[+d[2]-1]}`||!/^\d{1,12}$/.test(q.x_hotel_codes))fail('Точная дата или ночи не подтверждены меню карточки.');
 const iso=`${d[3]}-${d[2]}-${d[1]}`;if(new Date(iso+'T00:00:00Z').toISOString().slice(0,10)!==iso)fail('Дата не поддерживается.');
 for(const [k,v]of Object.entries({ADULT:'2',CHILD:'0',CURRENCY:'1',STATEINC:'11',TOWNFROM:'moskva',CHECKIN_BEG:iso.replace(/-/g,''),CHECKIN_END:iso.replace(/-/g,''),NIGHTMIN:n[1],NIGHTMAX:n[1]}))if(cardOperator.searchParams.getAll(k).length!==1||cardOperator.searchParams.get(k)!==v)fail('Операторская основа не совпала с карточкой.');
 if(!/^[1-9]\d{0,11}$/.test(cardOperator.searchParams.get('HOTELLIST')||''))fail('Отель операторской ссылки не определён.');
 const priceRoot=one(card,'.TVTourCardActionPrice'),amount=text(priceRoot,'.TVTourCardPriceValue'),currency=text(priceRoot,'.TVTourCardPriceCurrency'),basis=text(priceRoot,'.TVTourCardPriceType').toLowerCase(),parts=text(priceRoot,'.TVTourCardIncludePrices').match(/^Стоимость тура ([\d\s]+) РУБ Топливный сбор ([\d\s]+) РУБ$/);
 const value=s=>{const v=clean(s).replace(/\s/g,'');if(!/^(0|[1-9]\d{0,8})$/.test(v))fail('Цена карточки не поддерживается.');return v};
 if(currency!=='РУБ'||basis!=='за номер'||!parts||+value(parts[1])+ +value(parts[2])!==+value(amount)||+value(amount)<=0)fail('Итог, основа цены и включённое топливо не совпали.');
 if(Array.from(priceRoot.querySelectorAll('.TVTourCardPriceWaiter')).some(visible))fail('Цена ещё загружается.');
 const flights=text(card,'.TVTourCardInfoFlights');if(!flights||flights.length>800)fail('Дождитесь условий карточки.');
 const raw={hotel:title,destination,date,nights:nightsText,room,meal,party,price:amount+' РУБ',price_basis:basis,base_price:clean(parts[1])+' РУБ',fuel_price:clean(parts[2])+' РУБ',tariff,flight_alternatives:flights,party_price_confirmation:'pending'};
 return {adapter:'tourvisor-snapshot-v1',url:url.href,observed_at:now,quote_id:'tv-snapshot-'+snapshot,keys:{hotel:q.x_hotel_codes,meal:q.s_meal,departure:q.s_flyfrom,country:q.s_country,operator:'13'},offer:{hotel:hotel[1],destination,departure:'Москва',date_from:iso,nights:+n[1],adults:2,child_ages:[],room,meal,amount:value(amount),currency:'RUB',stars:+hotel[2]},raw};
}
root.AnyTourTourvisor={extract};
})(typeof window==='undefined'?globalThis:window);
