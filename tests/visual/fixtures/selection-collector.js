const fs=require('node:fs'),path=require('node:path');
const root=path.resolve(__dirname,'../../..');
const sourceUrl='https://b2b.fstravel.com/search_tour?ADULT=2&CHILD=0&CHECKIN_BEG=20270605&CHECKIN_END=20270605&NIGHTS_FROM=7&NIGHTS_TILL=7&STATEINC=18803&TOWNFROMINC=274286&CURRENCY=1&PROGRAMINC=114';
function sourceHtml(){
 const select=(key,value,label=value)=>`<select name="${key}"><option value="${value}">${label}</option></select>`;
 return `<!doctype html><html lang="ru"><meta charset="utf-8"><style>body{font:16px system-ui;padding:24px}td{padding:12px}.price::before{content:attr(data-converted_price_old);display:block;text-decoration:line-through;color:#777}</style><h1>Синтетическая выдача FUN&SUN</h1><form>${select('ADULT','2')}${select('CHILD','0')}${select('NIGHTS_FROM','7')}${select('NIGHTS_TILL','7')}${select('STATEINC','18803','Турция')}${select('TOWNFROMINC','274286','Москва')}${select('CURRENCY','1','RUB')}${select('PROGRAMINC','114','Промо')}<input name="CHECKIN_BEG" value="05.06.2027"><input name="CHECKIN_END" value="05.06.2027">${select('AGE1','4')}${select('AGE2','6')}${select('AGE3','12')}</form><table><tbody><tr class="price_info adult-2 child-0 spoKey-60 hotelKey-10 roomKey-20 mealKey-30 htPlaceKey-40 tourKey-78 programTypeKey-114 townFromKey-274286 stateKey-18803" data-hotel="10" data-room="20" data-meal="30" data-htplace="40" data-tour="78" data-ptype="114" data-townfrom="274286" data-state="18803" data-checkin="20270605" data-nights="7" data-cat-claim="0x${'a'.repeat(64)}"><td class="arrival"><span class="samo_format_date" data-date="05.06.2027">05.06.2027, сб</span><span class="popup-note" title="Вылеты: 01:00, 02:00; Эконом"></span></td><td class="tour-nights">7</td><td class="link-hotel"><a href="#hotel">Синтетический отель 5*</a> (Лара)</td><td class="hotel-room">Standard / 2AD</td><td class="hotel-meals">Ultra All Inclusive</td><td class="tour">Synthetic tour</td><td class="type_price">Synthetic tariff</td><td class="td_price"><span class="price" data-converted-price-number="237017" data-converted_price_old="259304 RUB" data-currency_title="RUB" data-currency="1">237 017 RUB</span></td></tr></tbody></table></html>`;
}
async function sourceFixture(page,options={}){
 let url=sourceUrl,html=sourceHtml();
 if(options.partner){
  const u=new URL(url);u.searchParams.set('PROGRAMINC','0');url=u.href;
  html=html.replace('<select name="PROGRAMINC"><option value="114">Промо</option></select>','<select name="PROGRAMINC"><option value="0">----</option></select>')
   .replaceAll('hotelKey-10','hotelKey--10').replaceAll('roomKey-20','roomKey--2147483647').replaceAll('htPlaceKey-40','htPlaceKey--2147483647').replace('spoKey-60','spoKey-').replace('programTypeKey-114','programTypeKey-5')
   .replace('data-hotel="10"','data-hotel="-10"').replace('data-room="20"','data-room="-2147483647"').replace('data-htplace="40"','data-htplace="-2147483647"').replace('data-ptype="114"','data-ptype="5"')
   .replace('href="#hotel"','href="https://b2b.fstravel.com/hotel/-10?partner=HBK"').replace('Standard / 2AD','DOUBLE STANDARD / 2ADL').replace('Synthetic tariff','Информация о тарифе в корзине');
 }
 if(options.ajax){
  url='https://b2b.fstravel.com/search_tour';
  const submitted=new URL(sourceUrl);submitted.searchParams.set('PROGRAMINC','0');
  html=html.replace('<select name="PROGRAMINC"><option value="114">Промо</option></select>','<select name="PROGRAMINC"><option value="0">----</option></select>');
  html=html.replace('</form>',`<button class="load" type="button">Искать</button></form><div id="samo-link-to-page"><a href="${submitted.href.replaceAll('&','&amp;')}">Ссылка на эту страницу</a></div>`);
  html=html.replace('<style>',`<style>button{width:100%;height:55px;padding:18px;font:24px serif;border-radius:20px}[data-anytour-add]::after{content:'HOST'}.td_price{min-width:100px;width:118px}.td_price .price{font-weight:bold;white-space:nowrap}`);
 }
 const calls=[],galleryRequests=[],partnerPhotos=[101,102,103].map(i=>'https://hbpro.expert/resource/hotel/photo_1200/11/22/'+i+'.jpg'),nativePhotos=['MainPhoto','Gallery','InsideOfTheRoom'].map((kind,i)=>'https://apigate-tui.fstravel.com/api/geocontent/static/Hotel/00180000-ac11-0242-6c05-08d9934f9e4b/'+kind+'-Source-test'+i+'.jpg'),canonical='/hotel/europe/turkey/synthetic-hotel';
 const gallery=photos=>'<meta charset="utf-8"><h2>Синтетический отель</h2>'+photos.map(src=>'<img class="slider-img" src="'+src+'">').join('');
 await page.route('**/*',route=>{
  const target=new URL(route.request().url());
  if(target.origin==='https://b2b.fstravel.com'&&target.pathname==='/hotel/10'){galleryRequests.push(target.href);return route.fulfill({contentType:'text/html',body:options.canonical?'<script>location.replace('+JSON.stringify(canonical)+')</script>':gallery(nativePhotos)})}
  if(target.origin==='https://b2b.fstravel.com'&&target.pathname===canonical&&options.canonical){galleryRequests.push(target.href);return route.fulfill({contentType:'text/html',body:gallery(nativePhotos)})}
  if(target.href==='https://b2b.fstravel.com/hotel/-10?partner=HBK'&&options.partner){galleryRequests.push(target.href);return route.fulfill({contentType:'text/html',body:gallery(partnerPhotos)})}
  if([...nativePhotos,...partnerPhotos].includes(target.href)){galleryRequests.push(target.href);return route.fulfill({contentType:'image/jpeg',body:fs.readFileSync(path.join(root,'assets/owner-tour-cards/compact-v1/cover.jpg'))})}
  if(route.request().url()===url)return route.fulfill({contentType:'text/html',body:html});
  calls.push(route.request().url());return route.abort();
 });
 await page.goto(url);await page.addStyleTag({path:path.join(root,'extensions/selection-collector/content.css')});await page.addScriptTag({path:path.join(root,'extensions/selection-collector/funsun.js')});
 if(options.ajax)await page.evaluate(()=>{window.fixtureFunsunObserver=AnyTourFunsun.observe(document);document.querySelector('button.load').addEventListener('click',e=>{if(window.fixtureHoldSearch)return;const old=document.querySelector('tr.price_info'),row=old.cloneNode(true);row.querySelectorAll('[data-anytour-actions],[data-anytour-note]').forEach(el=>el.remove());old.remove();e.target.disabled=true;setTimeout(()=>{document.querySelector('tbody').append(row);e.target.disabled=false},50)})});
 return {calls,galleryRequests,partnerPhotos,nativePhotos,search:async()=>{await page.getByRole('button',{name:'Искать',exact:true}).click();await page.locator('tr.price_info').waitFor({state:'visible'})},read:()=>page.evaluate(()=>{try{return {value:window.fixtureFunsunObserver?fixtureFunsunObserver.extract(document.querySelector('tr.price_info'),location.href,'2026-10-23T11:00:00Z'):AnyTourFunsun.extract(document,document.querySelector('tr.price_info'),location.href,'2026-10-23T11:00:00Z')}}catch(e){return {error:e.message}}})};
}
async function bridgeFixture(page){
 const calls=[],events=[],errors=[];let connected=false;
 const target={actor:7,conversation_id:11,client:'Синтетическая Анна',channel:'telegram',trip:'Лето у моря',started_at:'2026-10-23 10:00:00',dates:['2027-06-05'],count:0,version:0,binding:'b'.repeat(64)},connection={id:'c'.repeat(32),expires_at:'2099-01-01 00:00:00'};
 const config={actor:7,csrf:'fixture-csrf',conversation_id:11};
 let shell=fs.readFileSync(path.join(root,'manager/tour-selection-collector.php'),'utf8').split('?><!doctype')[1];
 shell='<!doctype'+shell.replace(/<\?=\$assetVersion\('[^']+'\)\?>/g,'fixture').replace(/<\?=json_encode\(\$config,[\s\S]*?\)\?>/,JSON.stringify(config));
 page.on('pageerror',error=>errors.push(error.message));
 await page.exposeFunction('collectorFixtureEvent',event=>events.push(event));
 await page.addInitScript(()=>document.addEventListener('anytour-collector-page',event=>window.collectorFixtureEvent(event.detail)));
 await page.route('**/*',async route=>{
  const u=new URL(route.request().url());
  if(u.origin!=='https://app.anytoour.ru')throw new Error('unexpected collector request');
  if(u.pathname==='/manager/tour-selection-collector.php')return route.fulfill({contentType:'text/html',body:shell});
  if(/^\/manager\/assets\/[a-z0-9.-]+$/.test(u.pathname))return route.fulfill({contentType:u.pathname.endsWith('.css')?'text/css':'application/javascript',body:fs.readFileSync(path.join(root,u.pathname.slice(1)))});
  if(u.pathname==='/manager/tour-selections-api.php'){
   const d=route.request().postDataJSON();calls.push(d);if(d.csrf!=='fixture-csrf'||d.conversation_id!==11)throw new Error('collector auth context lost');
   if(d.action==='collector_connect')connected=true;
   if(d.action==='collector_revoke')connected=false;
   let result={ok:true,target,connection:connected?connection:null};
   if(d.action==='collector_import'){target.count++;result={ok:true,state:'added',saved_version:target.count,count:target.count}}
   if(d.action==='collector_operation')result={ok:true,found:false,count:target.count};
   return route.fulfill({contentType:'application/json',body:JSON.stringify(result)});
  }
  if(u.pathname==='/favicon.ico')return route.fulfill({status:204,body:''});
  throw new Error('unexpected collector path '+u.pathname);
 });
 await page.goto('https://app.anytoour.ru/manager/tour-selection-collector.php?conversation_id=11');
 const hello=()=>page.evaluate(()=>document.dispatchEvent(new CustomEvent('anytour-collector-extension',{detail:{type:'hello',device:'d'.repeat(32),nonce:'e'.repeat(32)}})));
 const command=data=>page.evaluate(data=>document.dispatchEvent(new CustomEvent('anytour-collector-extension',{detail:{type:'command',nonce:'e'.repeat(32),actor:7,conversation_id:11,connection_id:'c'.repeat(32),request_id:'f'.repeat(32),operation_id:'a'.repeat(32),observation:{adapter:'funsun-b2b-v1'},...data}})),data);
 return {calls,events,errors,hello,command};
}
module.exports={sourceFixture,bridgeFixture,root};

async function anexSourceFixture(page){
 const url='https://agent.anextour.ru/search/tour?ADULT=2&CHILD=0&CHECKIN_BEG=20261025&CHECKIN_END=20261025&NIGHTMIN=7&NIGHTMAX=7&CURRENCY=1&STATEINC=3&TOWNFROM=moskva';
 const quote='0x'+'b'.repeat(268),href=url+'&CLAIM='+quote+'&HOTEL_INC=100&NIGHTS=7&fullNumber=AYT-12345&TAB_ACTIVE=description';
 const photos=[1,2,3].map(i=>'https://files.anextour.ru/hotel/hotels/turkey/synthetic/o'+i+'?&hotelCode=100');
 const input=(key,value)=>`<input data-testid="${key}" value="${value}">`;
 const html=`<!doctype html><html lang="ru"><meta charset="utf-8"><style>body{font:16px system-ui;padding:16px}#row{border:1px solid #ccc;padding:12px}#info{display:flex;flex-wrap:wrap;gap:12px}#info>div{padding:4px}#date>span{display:block}</style><h1>Синтетическая выдача ANEX</h1>${input('TOWNFROM','Москва')}${input('STATEINC','Турция')}${input('ADULT','2 взрослых')}${input('CURRENCY','RUB')}${input('CHECKIN_BEG','25.10')}${input('NIGHTMIN','7')}${input('NIGHTMAX','7')}<div id="row"><div id="info"><div><div id="date"><span>25 окт. 2026</span><span>вс</span></div><div>10:10 ещё</div><div>ANEX Light</div></div><div>7</div><div>Moscow Antalya</div><div><div></div><div><a id="anexQuote" href="${href}">Синтетический ANEX<span>5 *</span></a><span>Белек</span></div></div><div>Moscow Antalya</div><div>7ночей</div><div>UAI</div><div><div><button><span>Standard Room</span></button><button><span>DBL</span></button></div></div><div>UAI</div><div>Э/к</div><div></div></div><div id="price"><button id="nativePrice">177 323 ₽</button></div><div></div></div></html>`;
 const calls=[];await page.route('**/*',route=>{calls.push(route.request().url());if(route.request().url()===href)return route.fulfill({contentType:'text/html',body:'<div role="tabpanel"><h3>Синтетический ANEX</h3><div id="sliderPhoto">'+photos.map(url=>'<img src="'+url.replace('&','&amp;')+'">').join('')+'</div></div>'});return route.request().url()===url?route.fulfill({contentType:'text/html',body:html}):route.abort()});
 await page.goto(url);await page.addScriptTag({path:path.join(root,'extensions/selection-collector/anex.js')});await page.addStyleTag({path:path.join(root,'extensions/selection-collector/content.css')});
 return {calls,url,quote,galleryUrl:href,photos,read:()=>page.evaluate(()=>{try{return{value:AnyTourAnex.extract(document,document.querySelector('#anexQuote'),location.href,'2026-10-24T09:30:00Z')}}catch(e){return{error:e.message}}})};
}
module.exports.anexSourceFixture=anexSourceFixture;

async function tourvisorSourceFixture(page,options={}){
 const url='https://pro.tourvisor.ru/search#tvtourid=1234567890';
 const search='/search?ts_dosearch=1&s_form_mode=0&s_nights_from=11&s_nights_to=11&s_directflight=0&s_regular=1&x_hotel_codes=502&s_j_date_from=29.10.2026&s_j_date_to=29.10.2026&s_adults=2&s_meal=7&s_flyfrom=1&s_country=1';
 const operator='https://agent.anextour.ru/search/tour?ADULT=2&CHILD=0&CURRENCY=1&STATEINC=11&TOWNFROM=moskva&CHECKIN_BEG=20261029&CHECKIN_END=20261029&NIGHTMIN=11&NIGHTMAX=11&HOTELLIST=798';
 const option=(icon,head,foot)=>`<div class="TVTourCardOption ${icon}"><div class="TVTourCardOptionHeader">${head}</div><div class="TVTourCardOptionFooter">${foot}</div></div>`;
 const html=`<!doctype html><html lang="ru"><meta charset="utf-8"><style>body{font:16px system-ui;margin:0;padding:16px;background:#f1f5f9}.TVTourCardWindow,.TVMenuControl{max-width:650px;background:white;padding:16px;border-radius:14px;margin:0 auto 16px}.TVTourCardInfoTitle{font-size:20px}.TVTourCardOption{margin:12px 0}.TVTourCardOptionHeader{font-weight:600}.TVTourCardPriceValue{font-size:30px;font-weight:bold}.TVTourCardPriceType{font-size:12px}.TVTourCardIncludePrices{margin:12px 0}.TVHide{display:none}.TVMenuControl a{display:block;font-size:13px;overflow-wrap:anywhere;margin-bottom:10px}.TVTourCardOperatorControlLogoImage{width:40px;height:20px}</style><div class="TVTourCardWindow" data-tvtourlink="https://selection.example/#tvtourid=1234567890"><a class="TVTourCardOperatorControlWrapper" href="${operator}"><img class="TVTourCardOperatorControlLogoImage" src="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='40' height='20'/%3E"></a><div class="TVTourCardInfoTitle"><div class="TVHotelTitleName">Синтетический Tourvisor 3*</div><div class="TVHotelTitleResort">Египет, Хургада</div></div>${option('TVMapMarkerIcon','Египет','из Москвы')}${option('TVCalendarIcon','29 октября','11 ночей')}${option('TVMealIcon','Питание','AI - Все Включено')}${option('TVBedIcon','standard room','2 взрослых')}${option('TVInfoIcon','','Synthetic Egypt PROMO')}<div class="TVTourCardInfoFlights">XX999 Москва - Хургада; XX998 Хургада - Москва</div><div class="TVTourCardActionPrice"><div class="TVTourCardPriceValue">160 000</div><div class="TVTourCardPriceCurrency">РУБ</div><div class="TVTourCardPriceType">за номер</div><div class="TVTourCardIncludePrices">Стоимость тура 144 000 РУБ Топливный сбор 16 000 РУБ</div><div class="TVTourCardPriceWaiter TVHide">Загрузка</div><button id="nativeContinue">Продолжить</button></div></div><div class="TVMenuControl"><a class="TVSearchLinkItem" href="${search}">Открыть в поиске туров</a><a class="TVOperatorLinkItem" href="${operator}">Открыть в системе туроператора</a></div></html>`;
 const calls=[];await page.route('**/*',route=>{calls.push(route.request().url());if(route.request().url().startsWith('https://pro.tourvisor.ru/search'))return route.fulfill({contentType:'text/html',body:html});if(route.request().url()==='https://tourvisor.ru/pics/operators/mobilelogo/13.svg')return route.fulfill({contentType:'image/svg+xml',body:'<svg xmlns="http://www.w3.org/2000/svg" width="40" height="20"/>'});return route.abort()});
 await page.goto(url);await page.locator('.TVTourCardOperatorControlLogoImage').evaluate(e=>e.setAttribute('src','https://tourvisor.ru/pics/operators/mobilelogo/13.svg'));
 if(options.photos)await page.locator('.TVTourCardWindow').evaluate(card=>{for(let i=1;i<=3;i++){const photo=document.createElement('div');photo.className='TVPreviewGalleryItem';photo.style.backgroundImage='url("https://static.tourvisor.ru/hotel_pics/verybig/1/502-'+i+'.jpg")';card.append(photo)}});
 await page.addScriptTag({path:path.join(root,'extensions/selection-collector/tourvisor.js')});await page.addStyleTag({path:path.join(root,'extensions/selection-collector/content.css')});
 return {calls,url,read:()=>page.evaluate(()=>{try{return{value:AnyTourTourvisor.extract(document,location.href,'2026-10-24T11:00:00Z')}}catch(e){return{error:e.message}}})};
}
module.exports.tourvisorSourceFixture=tourvisorSourceFixture;
