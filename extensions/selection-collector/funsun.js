(function(root){
'use strict';
const fields=['TOWNFROMINC','STATEINC','CHECKIN_BEG','CHECKIN_END','NIGHTS_FROM','NIGHTS_TILL','ADULT','CHILD','CURRENCY','PROGRAMINC'];
const clean=s=>String(s||'').replace(/\s+/gu,' ').trim();
const fail=message=>{throw new Error(message)};
const date=s=>{const m=/^(\d{2})\.(\d{2})\.(\d{4})$/.exec(s||'');if(!m)return null;const iso=m[3]+'-'+m[2]+'-'+m[1];const d=new Date(iso+'T00:00:00Z');return !isNaN(d)&&d.toISOString().slice(0,10)===iso?iso:null};
const dayKey=iso=>iso?.replaceAll('-','');
const text=(row,selector)=>{const nodes=row.querySelectorAll(selector);if(nodes.length!==1)fail('Изменилась строка предложения. Добавьте тур вручную.');const e=nodes[0];if(e.closest('[hidden],[aria-hidden="true"]'))fail('Поле скрыто. Импорт остановлен.');return clean(e.innerText??e.textContent)};
function form(doc,href){
 const url=new URL(href);if(url.origin!=='https://b2b.fstravel.com'||url.pathname!=='/search_tour'||url.username||url.password)fail('Поддерживается выдача FUN&SUN «Туры с перелетом».');
 const loaders=doc.querySelectorAll('button.load');if(loaders.length>1||Array.from(loaders).some(e=>e.disabled||e.getAttribute('aria-busy')==='true'))fail('Дождитесь окончания поиска.');
 const links=doc.querySelectorAll('#samo-link-to-page a');let result=url;
 if(links.length){if(links.length!==1)fail('Не удалось определить выполненный поиск. Нажмите «Искать» ещё раз.');result=new URL(links[0].getAttribute('href'),url);if(result.origin!==url.origin||result.pathname!==url.pathname||result.username||result.password||result.port||result.hash)fail('Не удалось определить выполненный поиск. Нажмите «Искать» ещё раз.');}
 const out={};for(const key of fields){const nodes=doc.querySelectorAll('[name="'+key+'"]');if(nodes.length!==1||nodes[0].disabled)fail('Форма ещё загружается или изменилась.');const e=nodes[0];out[key]={value:e.value,text:clean(e.selectedOptions?.[0]?.textContent||e.value)}}
 const from=date(out.CHECKIN_BEG.value),to=date(out.CHECKIN_END.value);if(!from||!to||from>to)fail('Дождитесь установившихся дат формы.');
 // FUN&SUN updates this DOM link when submitting an AJAX search, while the
 // address bar stays unchanged. Bind its submitted conditions to the settled
 // form and exact row; never construct result provenance from live inputs.
 for(const key of fields.filter(k=>k!=='PROGRAMINC')){const expected=key.startsWith('CHECKIN_')?dayKey(date(out[key].value)):out[key].value;if(result.searchParams.getAll(key).length!==1||result.searchParams.get(key)!==expected)fail('Условия изменились. Нажмите «Искать» и дождитесь результатов.');}
 const adults=Number(out.ADULT.value),children=Number(out.CHILD.value);if(!Number.isInteger(adults)||adults<1||adults>20||!Number.isInteger(children)||children<0||children>3)fail('Состав туристов не поддерживается.');
 const ages=[];for(let i=1;i<=children;i++){const e=doc.querySelector('select[name="AGE'+i+'"]');const value=e?.value;if(!e||e.disabled||!/^\d{1,2}$/.test(value||'')||Number(value)>17||result.searchParams.getAll('AGE'+i).length!==1||result.searchParams.get('AGE'+i)!==value)fail('Возраст ребёнка изменился. Нажмите «Искать» и дождитесь результатов.');ages.push(Number(value));}
 return {values:out,from,to,adults,children,ages,url};
}
function extract(doc,row,href,at=new Date().toISOString().replace(/\.\d{3}Z$/,'Z')){
 const f=form(doc,href),v=f.values;
 if(!row?.matches('tr.price_info')||row.closest('[hidden],[aria-hidden="true"]'))fail('Выберите отображённую строку предложения.');
 const attrs={hotel:'hotel',room:'room',meal:'meal',placement:'htplace',tour:'tour',program:'ptype',departure:'townfrom',country:'state'},keys={};
 for(const [key,attr]of Object.entries(attrs)){const value=row.getAttribute('data-'+attr);if(!/^[1-9]\d{0,11}$/.test(value||''))fail('В строке нет точного идентификатора условий.');keys[key]=value}
 const classes=Array.from(row.classList),spo=classes.map(x=>/^spoKey-([1-9]\d*)$/.exec(x)).find(Boolean);if(!spo)fail('Не определён тариф предложения.');keys.spo=spo[1];
 const checkin=row.getAttribute('data-checkin'),nights=Number(row.getAttribute('data-nights'));
 const day=text(row,'.arrival .samo_format_date'),dayNode=row.querySelector('.arrival .samo_format_date'),iso=date(dayNode?.getAttribute('data-date'));
 if(!iso||!day.startsWith(dayNode.getAttribute('data-date'))||dayKey(iso)!==checkin||iso<f.from||iso>f.to||!Number.isInteger(nights)||nights<1||nights>60||nights<Number(v.NIGHTS_FROM.value)||nights>Number(v.NIGHTS_TILL.value)||text(row,'.tour-nights')!==String(nights)||keys.country!==v.STATEINC.value||keys.departure!==v.TOWNFROMINC.value||!classes.includes('adult-'+f.adults)||!classes.includes('child-'+f.children))fail('Строка не соответствует текущей форме. Выполните поиск заново.');
 const prices=row.querySelectorAll('.td_price .price');if(prices.length!==1)fail('Текущая цена не определена однозначно.');const price=prices[0];
 const rawPrice=clean(price.innerText??price.textContent),currency=price.getAttribute('data-currency_title'),amount=price.getAttribute('data-converted-price-number');
 if(!['RUB','EUR','USD'].includes(currency)||currency!==v.CURRENCY.text||price.getAttribute('data-currency')!==v.CURRENCY.value||!/^\d+(?:\.\d{1,2})?$/.test(amount||'')||Number(amount)<=0||rawPrice.replace(/[\s\u00a0\u202f]/gu,'')!==amount+currency)fail('Цена или валюта не совпадает с отображённой суммой этой строки.');
 const quote=row.getAttribute('data-cat-claim');if(!/^0x[0-9a-f]{32,1024}$/i.test(quote||''))fail('Нет идентификатора исходного предложения.');
 const title=text(row,'.link-hotel a'),room=text(row,'.hotel-room'),meal=text(row,'.hotel-meals'),star=/\s([0-5])\*$/.exec(title);
 const hotel=star?title.slice(0,star.index).trim():title,place=text(row,'.link-hotel').replace(title,'').trim().replace(/^\(|\)$/g,'');
 const safe=new URL('https://b2b.fstravel.com/search_tour');for(const key of [...fields,...f.ages.map((_,i)=>'AGE'+(i+1))]){const val=key.startsWith('CHECKIN_')?dayKey(date(v[key].value)):key.startsWith('AGE')?String(f.ages[Number(key.slice(3))-1]):v[key].value;safe.searchParams.set(key,val)}safe.searchParams.set('DOLOAD','1');
 const raw={hotel:title,date:day,nights:String(nights),room,meal,price:rawPrice,party:f.adults+'AD'+(f.children?' + '+f.children+'CH ('+f.ages.join(', ')+')':''),tour:text(row,'.tour'),flight_alternatives:clean(row.querySelector('.arrival .popup-note')?.getAttribute('title')||''),tariff:row.querySelector('.type_price')?text(row,'.type_price'):''};
 if(hotel.length>191||room.length>300||meal.length>160||Object.values(raw).some(x=>x.length>1200))fail('Слишком длинные условия. Импорт остановлен.');
 return {adapter:'funsun-b2b-v1',url:safe.href,observed_at:at,quote_id:quote,keys,raw,offer:{hotel,destination:clean(v.STATEINC.text+(place?', '+place:'')),departure:v.TOWNFROMINC.text,date_from:iso,nights,adults:f.adults,child_ages:f.ages,room,meal,amount,currency,stars:star?Number(star[1]):null}};
}
root.AnyTourFunsun={extract,form};
})(typeof window==='undefined'?module.exports:window);
