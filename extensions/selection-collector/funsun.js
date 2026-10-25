(function(root){
'use strict';
const fields=['TOWNFROMINC','STATEINC','CHECKIN_BEG','CHECKIN_END','NIGHTS_FROM','NIGHTS_TILL','ADULT','CHILD','CURRENCY','PROGRAMINC'];
const clean=s=>String(s||'').replace(/\s+/gu,' ').trim();
const fail=message=>{throw new Error(message)};
const canonicalKey=value=>/^[1-9]\d{0,11}$/.test(value||'')?value:null;
const keyNames={hotel:'отеля',room:'номера',meal:'питания',htplace:'размещения',tour:'тура',ptype:'программы',townfrom:'города вылета',state:'страны'};
function keyError(attr,reason,message){fail(message+' (FS-'+attr+'-'+reason+').');}
function rowKey(row,attr,classPrefix){
 const raw=row.getAttribute('data-'+attr),data=canonicalKey(raw);
 const classes=[...new Set(Array.from(row.classList).map(value=>value.startsWith(classPrefix)?canonicalKey(value.slice(classPrefix.length)):null).filter(Boolean))];
 if(classes.length>1)keyError(attr,'multiple','Неоднозначные данные '+keyNames[attr]+'. Повторите поиск или добавьте вариант вручную');
 if(data&&classes.length===1&&data!==classes[0])keyError(attr,'mismatch','Не совпадают данные '+keyNames[attr]+'. Повторите поиск или добавьте вариант вручную');
 if(raw!==null&&raw!==''&&!data)keyError(attr,'format','Формат данных '+keyNames[attr]+' пока не поддерживается. Добавьте вариант вручную');
 const value=data||classes[0];if(!value)keyError(attr,'missing','Нет точных данных '+keyNames[attr]+'. Добавьте вариант вручную');return value;
}
const date=s=>{const m=/^(\d{2})\.(\d{2})\.(\d{4})$/.exec(s||'');if(!m)return null;const iso=m[3]+'-'+m[2]+'-'+m[1];const d=new Date(iso+'T00:00:00Z');return !isNaN(d)&&d.toISOString().slice(0,10)===iso?iso:null};
const dayKey=iso=>iso?.replaceAll('-','');
const text=(row,selector)=>{const nodes=row.querySelectorAll(selector);if(nodes.length!==1)fail('Изменилась строка предложения. Добавьте тур вручную.');const e=nodes[0];if(e.closest('[hidden],[aria-hidden="true"]'))fail('Поле скрыто. Импорт остановлен.');return clean(e.innerText??e.textContent)};
function inputs(doc,href){
 const url=new URL(href);if(url.origin!=='https://b2b.fstravel.com'||url.pathname!=='/search_tour'||url.username||url.password)fail('Поддерживается выдача FUN&SUN «Туры с перелетом».');
 const out={};for(const key of fields){const nodes=doc.querySelectorAll('[name="'+key+'"]');if(nodes.length!==1||nodes[0].disabled)fail('Форма ещё загружается или изменилась.');const e=nodes[0];out[key]={value:e.value,text:clean(e.selectedOptions?.[0]?.textContent||e.value)}}
 const from=date(out.CHECKIN_BEG.value),to=date(out.CHECKIN_END.value);if(!from||!to||from>to)fail('Дождитесь установившихся дат формы.');
 const adults=Number(out.ADULT.value),children=Number(out.CHILD.value);if(!Number.isInteger(adults)||adults<1||adults>20||!Number.isInteger(children)||children<0||children>3)fail('Состав туристов не поддерживается.');
 const ages=[];for(let i=1;i<=children;i++){const e=doc.querySelector('select[name="AGE'+i+'"]');const value=e?.value;if(!e||e.disabled||!/^\d{1,2}$/.test(value||'')||Number(value)>17)fail('Возраст ребёнка не определён.');ages.push(Number(value));}
 return {values:out,from,to,adults,children,ages,url};
}
function sourceUrl(f){
 const safe=new URL('https://b2b.fstravel.com/search_tour'),v=f.values;
 for(const key of [...fields,...f.ages.map((_,i)=>'AGE'+(i+1))]){const val=key.startsWith('CHECKIN_')?dayKey(date(v[key].value)):key.startsWith('AGE')?String(f.ages[Number(key.slice(3))-1]):v[key].value;safe.searchParams.set(key,val)}safe.searchParams.set('DOLOAD','1');return safe;
}
function form(doc,href,submitted=null){
 const loaders=doc.querySelectorAll('button.load');if(loaders.length>1||Array.from(loaders).some(e=>e.disabled||e.getAttribute('aria-busy')==='true'))fail('Дождитесь окончания поиска.');
 const f=inputs(doc,href),v=f.values,result=submitted?new URL(submitted.url):f.url;
 for(const key of fields.filter(k=>k!=='PROGRAMINC'||submitted)){const expected=key.startsWith('CHECKIN_')?dayKey(date(v[key].value)):v[key].value;if(result.searchParams.getAll(key).length!==1||result.searchParams.get(key)!==expected)fail('Условия изменились. Нажмите «Искать» и дождитесь результатов.');}
 for(let i=0;i<f.ages.length;i++)if(result.searchParams.getAll('AGE'+(i+1)).length!==1||result.searchParams.get('AGE'+(i+1))!==String(f.ages[i]))fail('Возраст ребёнка изменился. Нажмите «Искать» и дождитесь результатов.');
 if(submitted&&submitted.labels!==JSON.stringify(v))fail('Условия изменились. Нажмите «Искать» и дождитесь результатов.');return f;
}
function observe(doc){
 let current=null,attempted=false,rows=new WeakMap();const seen=new WeakSet();
 const remember=()=>doc.querySelectorAll('tr.price_info').forEach(row=>seen.add(row));remember();
 const begin=()=>{attempted=true;rows=new WeakMap();remember();try{const f=inputs(doc,doc.location.href);current={url:sourceUrl(f).href,labels:JSON.stringify(f.values)}}catch{current=null}};
 doc.addEventListener('click',e=>{if(e.target?.closest?.('button.load'))begin()},true);
 doc.addEventListener('submit',e=>{if(e.target?.querySelector?.('button.load'))begin()},true);
 // A live share link changes when merely editing inputs; it is not result proof.
 // Only new row nodes after this captured search can use its immutable context.
 const watch=new MutationObserver(records=>{for(const record of records)for(const node of record.addedNodes){if(node.nodeType!==1)continue;for(const row of [node,...node.querySelectorAll('tr.price_info')]){if(!row.matches('tr.price_info')||seen.has(row))continue;seen.add(row);if(current)rows.set(row,current)}}});
 watch.observe(doc.body,{childList:true,subtree:true});
 return {extract(row,href,at){if(attempted&&(!current||rows.get(row)!==current))fail('Нажмите «Искать» и дождитесь новых результатов.');return extract(doc,row,href,at,attempted?current:null)}};
}
function extract(doc,row,href,at=new Date().toISOString().replace(/\.\d{3}Z$/,'Z'),submitted=null){
 const f=form(doc,href,submitted),v=f.values;
 if(!row?.matches('tr.price_info')||row.closest('[hidden],[aria-hidden="true"]'))fail('Выберите отображённую строку предложения.');
 const attrs={hotel:['hotel','hotelKey-'],room:['room','roomKey-'],meal:['meal','mealKey-'],placement:['htplace','htPlaceKey-'],tour:['tour','tourKey-'],program:['ptype','programTypeKey-'],departure:['townfrom','townFromKey-'],country:['state','stateKey-']},keys={};
 for(const [key,[attr,classPrefix]]of Object.entries(attrs))keys[key]=rowKey(row,attr,classPrefix);
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
 const safe=sourceUrl(f);
 const raw={hotel:title,date:day,nights:String(nights),room,meal,price:rawPrice,party:f.adults+'AD'+(f.children?' + '+f.children+'CH ('+f.ages.join(', ')+')':''),tour:text(row,'.tour'),flight_alternatives:clean(row.querySelector('.arrival .popup-note')?.getAttribute('title')||''),tariff:row.querySelector('.type_price')?text(row,'.type_price'):''};
 if(hotel.length>191||room.length>300||meal.length>160||Object.values(raw).some(x=>x.length>1200))fail('Слишком длинные условия. Импорт остановлен.');
 return {adapter:'funsun-b2b-v1',url:safe.href,observed_at:at,quote_id:quote,keys,raw,offer:{hotel,destination:clean(v.STATEINC.text+(place?', '+place:'')),departure:v.TOWNFROMINC.text,date_from:iso,nights,adults:f.adults,child_ages:f.ages,room,meal,amount,currency,stars:star?Number(star[1]):null}};
}
root.AnyTourFunsun={extract,form,observe};
})(typeof window==='undefined'?module.exports:window);
