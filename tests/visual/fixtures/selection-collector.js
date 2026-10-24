const fs=require('node:fs'),path=require('node:path');
const root=path.resolve(__dirname,'../../..');
const sourceUrl='https://b2b.fstravel.com/search_tour?ADULT=2&CHILD=0&CHECKIN_BEG=20270605&CHECKIN_END=20270605&NIGHTS_FROM=7&NIGHTS_TILL=7&STATEINC=18803&TOWNFROMINC=274286&CURRENCY=1&PROGRAMINC=114';
function sourceHtml(){
 const select=(key,value,label=value)=>`<select name="${key}"><option value="${value}">${label}</option></select>`;
 return `<!doctype html><html lang="ru"><meta charset="utf-8"><style>body{font:16px system-ui;padding:24px}td{padding:12px}.price::before{content:attr(data-converted_price_old);display:block;text-decoration:line-through;color:#777}</style><h1>Синтетическая выдача FUN&SUN</h1><form>${select('ADULT','2')}${select('CHILD','0')}${select('NIGHTS_FROM','7')}${select('NIGHTS_TILL','7')}${select('STATEINC','18803','Турция')}${select('TOWNFROMINC','274286','Москва')}${select('CURRENCY','1','RUB')}${select('PROGRAMINC','114','Промо')}<input name="CHECKIN_BEG" value="05.06.2027"><input name="CHECKIN_END" value="05.06.2027">${select('AGE1','4')}${select('AGE2','6')}${select('AGE3','12')}</form><table><tbody><tr class="price_info adult-2 child-0 spoKey-60" data-hotel="10" data-room="20" data-meal="30" data-htplace="40" data-tour="78" data-ptype="114" data-townfrom="274286" data-state="18803" data-checkin="20270605" data-nights="7" data-cat-claim="0x${'a'.repeat(64)}"><td class="arrival"><span class="samo_format_date" data-date="05.06.2027">05.06.2027, сб</span><span class="popup-note" title="Вылеты: 01:00, 02:00; Эконом"></span></td><td class="tour-nights">7</td><td class="link-hotel"><a href="#hotel">Синтетический отель 5*</a> (Лара)</td><td class="hotel-room">Standard / 2AD</td><td class="hotel-meals">Ultra All Inclusive</td><td class="tour">Synthetic tour</td><td class="type_price">Synthetic tariff</td><td class="td_price"><span class="price" data-converted-price-number="237017" data-converted_price_old="259304 RUB" data-currency_title="RUB" data-currency="1">237 017 RUB</span></td></tr></tbody></table></html>`;
}
async function sourceFixture(page){
 const calls=[];await page.route('**/*',route=>route.request().url()===sourceUrl?route.fulfill({contentType:'text/html',body:sourceHtml()}):route.abort());
 await page.goto(sourceUrl);await page.addScriptTag({path:path.join(root,'extensions/selection-collector/funsun.js')});
 return {calls,read:()=>page.evaluate(()=>{try{return {value:AnyTourFunsun.extract(document,document.querySelector('tr.price_info'),location.href,'2026-10-23T11:00:00Z')}}catch(e){return {error:e.message}}})};
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
 const input=(key,value)=>`<input data-testid="${key}" value="${value}">`;
 const html=`<!doctype html><html lang="ru"><meta charset="utf-8"><style>body{font:16px system-ui;padding:16px}#row{border:1px solid #ccc;padding:12px}#info{display:flex;flex-wrap:wrap;gap:12px}#info>div{padding:4px}#date>span{display:block}</style><h1>Синтетическая выдача ANEX</h1>${input('TOWNFROM','Москва')}${input('STATEINC','Турция')}${input('ADULT','2 взрослых')}${input('CURRENCY','RUB')}${input('CHECKIN_BEG','25.10')}${input('NIGHTMIN','7')}${input('NIGHTMAX','7')}<div id="row"><div id="info"><div><div id="date"><span>25 окт. 2026</span><span>вс</span></div><div>10:10 ещё</div><div>ANEX Light</div></div><div>7</div><div>Moscow Antalya</div><div><div></div><div><a id="anexQuote" href="${href}">Синтетический ANEX<span>5 *</span></a><span>Белек</span></div></div><div>Moscow Antalya</div><div>7ночей</div><div>UAI</div><div><div><button><span>Standard Room</span></button><button><span>DBL</span></button></div></div><div>UAI</div><div>Э/к</div><div></div></div><div id="price"><button id="nativePrice">177 323 ₽</button></div><div></div></div></html>`;
 const calls=[];await page.route('**/*',route=>{calls.push(route.request().url());return route.request().url()===url?route.fulfill({contentType:'text/html',body:html}):route.abort()});
 await page.goto(url);await page.addScriptTag({path:path.join(root,'extensions/selection-collector/anex.js')});await page.addStyleTag({path:path.join(root,'extensions/selection-collector/content.css')});
 return {calls,url,quote,read:()=>page.evaluate(()=>{try{return{value:AnyTourAnex.extract(document,document.querySelector('#anexQuote'),location.href,'2026-10-24T09:30:00Z')}}catch(e){return{error:e.message}}})};
}
module.exports.anexSourceFixture=anexSourceFixture;
