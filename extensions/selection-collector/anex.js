(function(root){
'use strict';
const clean=s=>String(s||'').replace(/\s+/gu,' ').trim();
const fail=s=>{throw new Error(s)};
const queryFields=['ADULT','CHILD','CHECKIN_BEG','CHECKIN_END','NIGHTMIN','NIGHTMAX','CURRENCY','STATEINC','TOWNFROM'];
const months={'янв.':'01','февр.':'02','мар.':'03','апр.':'04','мая':'05','июн.':'06','июл.':'07','авг.':'08','сент.':'09','окт.':'10','нояб.':'11','дек.':'12'};
function one(parent,selector){const es=parent.querySelectorAll(selector);if(es.length!==1)fail('Шаблон строки ANEX изменился. Добавьте вариант вручную.');return es[0]}
function value(doc,key){const e=one(doc,'input[data-testid="'+key+'"]');if(e.disabled)return fail('Форма ANEX ещё загружается.');return clean(e.value)}
function resultRow(anchor){const info=anchor.parentElement?.parentElement?.parentElement,row=info?.parentElement;return row&&row.children.length===3&&info.children.length===11&&row.children[0]===info?row:null}
function anchors(doc){return Array.from(doc.querySelectorAll('a[href*="TAB_ACTIVE=description"]')).filter(e=>{try{const u=new URL(e.href);return u.origin==='https://agent.anextour.ru'&&u.pathname==='/search/tour'&&u.searchParams.has('CLAIM')&&resultRow(e)}catch{return false}})}
function extract(doc,anchor,href,at=new Date().toISOString().replace(/\.\d{3}Z$/,'Z')){
 const url=new URL(href),row=resultRow(anchor);
 if(url.origin!=='https://agent.anextour.ru'||url.pathname!=='/search/tour'||url.username||url.password||!row||!anchor.isConnected)fail('Поддерживается выбранная строка агентского поиска ANEX.');
 if(!row.getClientRects().length||row.closest('[hidden],[aria-hidden="true"]')||doc.querySelector('.ModalsWrapper__overlay'))fail('Закройте детализацию и выберите строку выдачи ANEX.');
 const link=new URL(anchor.href);if(link.origin!==url.origin||link.pathname!==url.pathname)fail('Источник строки не совпадает с поиском ANEX.');
 const safe=new URL('https://agent.anextour.ru/search/tour');
 for(const key of queryFields){const val=url.searchParams.get(key);if(url.searchParams.getAll(key).length!==1||link.searchParams.getAll(key).length!==1||link.searchParams.get(key)!==val||!/^[a-z0-9]{1,12}$/.test(val||''))fail('Строка не соответствует выполненному поиску.');safe.searchParams.set(key,val)}
 if(safe.searchParams.get('ADULT')!=='2'||safe.searchParams.get('CHILD')!=='0'||safe.searchParams.get('CURRENCY')!=='1'||safe.searchParams.get('STATEINC')!=='3'||safe.searchParams.get('TOWNFROM')!=='moskva')fail('Этот шаблон ANEX пока поддерживает Москву → Турцию, двух взрослых без детей и RUB. Остальные варианты добавьте вручную.');
 if(value(doc,'TOWNFROM')!=='Москва'||value(doc,'STATEINC')!=='Турция'||value(doc,'ADULT')!=='2 взрослых'||value(doc,'CURRENCY')!=='RUB')fail('Условия формы изменились. Выполните поиск заново.');
 for(const key of ['NIGHTMIN','NIGHTMAX'])if(value(doc,key)!==safe.searchParams.get(key))fail('Ночи формы не соответствуют выдаче.');
 const from=safe.searchParams.get('CHECKIN_BEG'),to=safe.searchParams.get('CHECKIN_END');
 // A single exact date is the observed template. Ranges remain manual until observed.
 if(!/^\d{8}$/.test(from)||from!==to||value(doc,'CHECKIN_BEG')!==from.slice(6,8)+'.'+from.slice(4,6))fail('Дождитесь точной даты поиска; диапазон ANEX пока добавляется вручную.');
 const cells=Array.from(row.children[0].children),dateText=clean(cells[0].children[0]?.innerText),dm=/^(\d{1,2}) ([а-я.]+) (\d{4}) (пн|вт|ср|чт|пт|сб|вс)$/u.exec(dateText);
 if(!dm||!months[dm[2]])fail('В строке нет точной даты с годом.');
 const iso=dm[3]+'-'+months[dm[2]]+'-'+dm[1].padStart(2,'0');
 if(iso.replaceAll('-','')!==from||new Date(iso+'T00:00:00Z').toISOString().slice(0,10)!==iso)fail('Дата строки не соответствует форме.');
 const nights=clean(cells[1].textContent),mobileNights=clean(cells[5].textContent);
 if(!/^[1-9]\d?$/.test(nights)||Number(nights)>60||mobileNights!==nights+'ночей'||Number(nights)<Number(value(doc,'NIGHTMIN'))||Number(nights)>Number(value(doc,'NIGHTMAX'))||link.searchParams.get('NIGHTS')!==nights)fail('Число ночей не определено однозначно.');
 const hotelRaw=clean(anchor.textContent),hm=/^(.+?)([0-5]) \*$/.exec(hotelRaw),resort=clean(anchor.parentElement.children[1]?.textContent);
 const meal=clean(cells[6].textContent),room=clean(cells[7].children[0]?.children[0]?.textContent),placement=clean(cells[7].children[0]?.children[1]?.textContent);
 if(!hm||!resort||!room||placement!=='DBL'||clean(cells[8].textContent)!==meal||!['RO','BB','HB','FB','AI','UAI'].includes(meal))fail('Номер, размещение или питание ANEX не подтверждены строкой.');
 const priceButtons=Array.from(row.children[1].querySelectorAll('button')).filter(e=>!e.hasAttribute('data-anytour-add')&&clean(e.textContent));
 if(priceButtons.length!==1)fail('Текущая цена строки не определена однозначно.');
 const price=clean(priceButtons[0].innerText??priceButtons[0].textContent),pm=/^([1-9][0-9 ]*) ₽$/.exec(price);
 if(!pm)fail('Цена строки ANEX не является одной суммой RUB.');
 const quote=link.searchParams.get('CLAIM'),hotel=link.searchParams.get('HOTEL_INC'),spo=link.searchParams.get('fullNumber');
 if(link.searchParams.getAll('CLAIM').length!==1||!/^0x[0-9a-f]{268}$/i.test(quote||'')||!/^[1-9]\d{0,11}$/.test(hotel||'')||!/^AYT-[1-9]\d{0,9}$/.test(spo||''))fail('Нет точного идентификатора выбранного предложения ANEX.');
 const tariff=clean(cells[0].children[2]?.textContent);if(!tariff)fail('Не определён тариф ANEX.');
 const raw={hotel:hotelRaw,resort,date:dateText,nights,room,placement,meal,price,party:'2 взрослых',tariff,departure:'Москва',country:'Турция'};
 if(Object.values(raw).some(s=>s.length>1200)||hm[1].trim().length>191||room.length>290)fail('Слишком длинные условия ANEX.');
 return {adapter:'anex-agent-v1',url:safe.href,observed_at:at,quote_id:quote.toLowerCase(),keys:{hotel,country:'3',departure:'moskva',spo},raw,offer:{hotel:hm[1].trim(),stars:Number(hm[2]),destination:'Турция, '+resort,departure:'Москва',date_from:iso,nights:Number(nights),adults:2,child_ages:[],room:room+' / DBL',meal,amount:pm[1].replaceAll(' ',''),currency:'RUB'}};
}
root.AnyTourAnex={extract,anchors,resultRow};
})(typeof window==='undefined'?module.exports:window);
