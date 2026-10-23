'use strict';
const assert=require('node:assert/strict');
const fs=require('node:fs');
const path=require('node:path');
const vm=require('node:vm');
const escape=value=>String(value).replace(/[&<>"']/g,char=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'})[char]);
let inserted='',removed=0,checks=0;
const hero={insertAdjacentHTML(position,html){assert.equal(position,'afterend');inserted=html;}};
const root={querySelector(selector){if(selector==='[data-tour-choices]')return {remove(){removed++;inserted='';}};if(selector==='.leadHero')return hero;return null;},insertAdjacentHTML(position,html){inserted=html;}};
const S={current:7,detail:{conversation:{id:7}}};
const window={WorkspaceV2:{S,esc:escape,$:id=>id==='leadCard'?root:null}};
vm.runInNewContext(fs.readFileSync(path.join(__dirname,'../manager/assets/workspace-v2-tour-choices.js'),'utf8'),{window});
const ui=window.WorkspaceV2TourChoices;
const offer=(overrides={})=>({offer_id:17,delivered_at:'2026-10-21 12:00:00',liked_at:'2026-10-21 12:01:00',snapshot:{hotel:'Hotel Sea',departure:'Москва',date_from:'2026-11-03',date_to:'2026-11-10',nights:7,adults:2,child_ages:[5,5],room:'Family sea view',meal:'Всё включено',flights:['ZF 123 · 10:00','ZF 124 · 12:00'],amount:'123456.70',currency:'RUB',price_basis:'party',is_demo:true,price_verified:false,rationale:'Песчаный пляж',compromise:'До моря 400 м'},...overrides});
function check(name,run){run();checks++;console.log('PASS '+name);}
check('absent and unliked choices do not create an empty panel',()=>{
  for(const value of [undefined,null,{}, {offers:[]},{offers:[offer({liked_at:null})]}])assert.equal(ui.markup(value),'');
});
check('saved party conditions retain repeated child ages and exact decimal price',()=>{
  const html=ui.markup({offers:[offer()]});
  for(const value of ['Понравившиеся туры','123 456,70 ₽ за всех','03.11.2026 — 10.11.2026','Возраст детей: 5, 5','Family sea view','Всё включено','ZF 123 · 10:00','ZF 124 · 12:00','Песчаный пляж','До моря 400 м'])assert(html.includes(value),value);
  assert(!html.includes('offer_id'));assert(!html.includes('callback_data'));
});
check('demo and historical prices never imply current verification or booking',()=>{
  const demo=ui.markup({offers:[offer()]});
  assert(demo.includes('Демонстрационный вариант'));assert(demo.includes('актуальность и наличие нужно проверить'));
  const real=offer();real.snapshot={...real.snapshot,is_demo:false,price_verified:true};
  const html=ui.markup({offers:[real]});assert(!html.includes('Демонстрационный вариант'));assert(html.includes('актуальность и наличие нужно проверить'));
  assert(!html.includes('Забронировать'));assert(!html.includes('<button'));
});
check('manager sees complete sent inclusions without a calculated return promise',()=>{
  const item=offer();item.snapshot={...item.snapshot,is_demo:false,quoted_details:{flights:'Время рейсов уточняется',inclusions:'Трансфер',constraints:'Топливный сбор проверить',source_label:'Проверенный источник'}};
  const html=ui.markup({offers:[item]});
  for(const value of ['С 03.11.2026','Время рейсов уточняется','Трансфер','Топливный сбор проверить','Проверенный источник'])assert(html.includes(value),value);
  assert(!html.includes('03.11.2026 — 10.11.2026'));
});
check('hotel variants and currencies remain separate without totals',()=>{
  const second=offer({offer_id:18});second.snapshot={...second.snapshot,room:'Standard',amount:'999.99',currency:'EUR',date_from:'2026-12-01'};
  const html=ui.markup({offers:[offer(),second]});assert.equal((html.match(/class="leadDetails tourChoice"/g)||[]).length,2);
  for(const value of ['123 456,70 ₽ за всех','999,99 € за всех','Family sea view','Standard','01.12.2026'])assert(html.includes(value),value);
});
check('every accepted child age and flight segment remains visible',()=>{
  const item=offer();item.snapshot={...item.snapshot,child_ages:[0,1,2,3,4,5,6,7,8,9,10,11],flights:Array.from({length:6},(_,i)=>'Flight '+i+' '+String(i).repeat(270))};
  const html=ui.markup({offers:[item]});
  assert(html.includes('Возраст детей: 0, 1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11'));
  for(const flight of item.snapshot.flights)assert(html.includes(flight));
});
check('untrusted offer text is escaped and private tokens are never projected',()=>{
  const item=offer({callback_data:'PRIVATE_CALLBACK',token:'PRIVATE_TOKEN'});
  item.snapshot={...item.snapshot,hotel:'<img src=x onerror="bad()">',room:'<script>bad()</script>',flights:['<a href="javascript:bad()">x</a>'],rationale:'a & b',compromise:"'quoted'"};
  const html=ui.markup({offers:[item]});
  for(const value of ['<img','<script','<a ','onerror="bad()"','PRIVATE_CALLBACK','PRIVATE_TOKEN'])assert(!html.includes(value),value);
  assert(html.includes('&lt;img'));assert(html.includes('&lt;script'));assert(html.includes('a &amp; b'));assert(html.includes('&#039;quoted&#039;'));
});
check('bounded projection keeps five choices and explicitly states omission',()=>{
  const items=Array.from({length:30},(_,index)=>{const item=offer({offer_id:index});item.snapshot={...item.snapshot,hotel:'Hotel '+index};return item;});
  const html=ui.markup({offers:items});assert.equal((html.match(/class="leadDetails tourChoice"/g)||[]).length,5);assert(html.includes('список неполный'));
  assert(!html.includes('Hotel 5<'));assert(ui.markup({offers:[offer()],limited:true}).includes('список неполный'));
});
check('malformed prices cannot invent a currency or whole-party basis',()=>{
  const item=offer();item.snapshot={...item.snapshot,amount:'100 RUB + 50 EUR',currency:'RUB'};
  assert(ui.markup({offers:[item]}).includes('Цена не указана'));
  item.snapshot={...item.snapshot,amount:'100',currency:'USD',price_basis:null};
  const html=ui.markup({offers:[item]});assert(html.includes('100 $'));assert(!html.includes('за всех'));
});
check('only the selected conversation may enhance the current customer card',()=>{
  S.detail.tour_choices={offers:[offer()]};ui.enhanceCard({id:7});assert(inserted.includes('Hotel Sea'));
  ui.enhanceCard({id:8});assert.equal(inserted,'');
  S.current=8;ui.enhanceCard({id:8});assert.equal(inserted,'');
  S.current=7;S.detail.conversation.id=8;ui.enhanceCard({id:7});assert.equal(inserted,'');
  assert.equal(removed,4);
});
const inquiry=(overrides={})=>({variant:2,link_version:3,created_at:'2026-10-23 10:00:00',public_offer:{hotel:'Web sea hotel',departure:'Москва',date_from:'2027-06-12',nights:7,adults:2,children:2,room:'Exact web family room',meal:'Всё включено',amount:'260000.50',currency:'RUB',flights:'Рейсы требуют проверки',inclusions:'Трансфер',constraints:'Топливо необходимо уточнить'},...overrides});
check('web inquiry has its own section and exact public quote without a fabricated native like',()=>{
  const html=ui.markup({offers:[],web_inquiries:[inquiry()]});
  for(const value of ['Обращения из веб-подборки','Турист перешёл в этот диалог','Web sea hotel','260 000,50 ₽ за всех','Вариант 2 · версия 3','12.06.2027','Взрослых: 2 · детей: 2','Exact web family room','Рейсы требуют проверки','Трансфер','Топливо необходимо уточнить'])assert(html.includes(value),value);
  for(const value of ['Понравившиеся туры','Клиент отметил эти варианты','Возраст детей','Забронировать','<button','<form'])assert(!html.includes(value),value);
});
check('web inquiry rendering escapes public fields and ignores private original-client extras',()=>{
  const item=inquiry();item.public_offer={...item.public_offer,hotel:'<img src=x onerror="webBad()">',room:'<script>webBad()</script>',flights:'<a href="javascript:webBad()">flight</a>',inclusions:'a & b',constraints:"'quoted'",child_ages:[11,12],source_label:'PRIVATE_SOURCE_LABEL',source_url:'https://private-source.invalid/SECRET',callback_data:'PRIVATE_CALLBACK',quoted_details:{source_label:'PRIVATE_NESTED_SOURCE'}};
  item.snapshot=offer().snapshot;item.token='PRIVATE_TOKEN';
  const html=ui.markup({offers:[],web_inquiries:[item]});
  for(const value of ['<img','<script','<a ','onerror="webBad()"','Возраст детей','11, 12','PRIVATE_','private-source.invalid','child_ages','source_label','source_url','callback_data'])assert(!html.includes(value),value);
  for(const value of ['&lt;img','&lt;script','&lt;a','a &amp; b','&#039;quoted&#039;'])assert(html.includes(value),value);
});
check('native choices and web inquiries stay separate in a mixed customer card',()=>{
  const html=ui.markup({offers:[offer()],web_inquiries:[inquiry()]});
  assert.equal((html.match(/class="leadDetails tourChoice"/g)||[]).length,2);
  assert.equal((html.match(/Понравившиеся туры/g)||[]).length,1);assert.equal((html.match(/Обращения из веб-подборки/g)||[]).length,1);
  assert(html.includes('Возраст детей: 5, 5'));assert(html.includes('Взрослых: 2 · детей: 2'));assert(html.includes('123 456,70 ₽ за всех'));assert(html.includes('260 000,50 ₽ за всех'));
});
check('web inquiries are bounded with explicit omission and malformed entries stay absent',()=>{
  const items=Array.from({length:8},(_,i)=>{const item=inquiry();item.public_offer={...item.public_offer,hotel:'Web hotel '+i};return item;});
  const html=ui.markup({offers:[],web_inquiries:items});assert.equal((html.match(/class="leadDetails tourChoice"/g)||[]).length,5);assert(html.includes('список неполный'));assert(!html.includes('Web hotel 5<'));
  for(const value of [null,{}, {public_offer:null},{public_offer:{hotel:''}}])assert.equal(ui.markup({offers:[],web_inquiries:[value]}),'');
});
const search=()=>({price_state_now:'checked',observation:{capturedAt:1792756800000,source:'anex',hotel:{name:'Search sea hotel',resort:'Лара'},trip:{origin:'Москва',departure:'2027-06-12',nights:7,stayEnd:'2027-06-19',adults:2,childAges:[0,0,12]},stay:{room:'Exact search family room',meal:'Всё включено',placement:'2+3',operator:'ANEX'},flights:{state:'selected',outward:[{label:'Selected outward 10:00'}],inward:[{label:'Selected inward 19:00'}]},price:{amount:'231456.50',currency:'RUB',state:'checked',description:'PRIVATE_DESCRIPTION',finalPriceVerified:false}}});
check('search inquiry retains the exact selected pair and infant ages separately from native likes',()=>{
 const html=ui.markup({offers:[],search_inquiries:[search()]});for(const value of ['Выбранные туры из поиска','Search sea hotel','231 456,50 ₽ за всех','Возраст детей: 0, 0, 12','Exact search family room','Selected outward 10:00','Selected inward 19:00','12.06.2027','Полную стоимость и наличие необходимо подтвердить'])assert(html.includes(value),value);
 for(const value of ['Понравившиеся туры','Обращения из веб-подборки','PRIVATE_DESCRIPTION','19.06.2027','<button','<form'])assert(!html.includes(value),value);
});
check('search expiry estimate and unknown deferred price remain honest',()=>{
 const item=search();item.price_state_now='expired';assert(ui.markup({offers:[],search_inquiries:[item]}).includes('Срок расчёта истёк'));item.price_state_now='estimated';item.observation.price.state='estimated';assert(ui.markup({offers:[],search_inquiries:[item]}).includes('Расчётная сумма'));
 item.observation.price.amount=null;item.observation.flights={state:'manager-deferred',outward:[],inward:[]};const html=ui.markup({offers:[],search_inquiries:[item]});for(const value of ['Цена не указана','Цена неизвестна','Подбор рейсов с менеджером'])assert(html.includes(value),value);assert(!html.includes('Selected outward'));
});
check('structured flight leg retains both airports and baggage beside the return pair',()=>{
 const item=search();item.observation.flights.outward=[{number:'ZF123',carrier:'Example Air',departure:{date:'12.06.2027',time:'10:00',town:'Москва',port:'Внуково',portCode:'VKO'},arrival:{date:'12.06.2027',time:'14:00',town:'Анталья',port:'Анталья',portCode:'AYT'},baggage:'20 кг',carryOn:'5 кг'}];const html=ui.markup({offers:[],search_inquiries:[item]});for(const value of ['ZF123','Example Air','Внуково VKO','Анталья AYT','Багаж: 20 кг','Ручная кладь: 5 кг','Selected inward 19:00'])assert(html.includes(value),value);
});
check('search text is escaped and retained binding and capability details stay absent',()=>{
 const item=search();item.binding={record_id:'PRIVATE_RECORD'};item.token='PRIVATE_TOKEN';item.observation.hotel.name='<img src=x onerror="searchBad()">';item.observation.stay.room='<script>bad()</script>';item.observation.flights.outward=[{label:'<a href="javascript:bad()">flight</a>'}];const html=ui.markup({offers:[],search_inquiries:[item]});for(const value of ['<img','<script','<a ','PRIVATE_','onerror="searchBad()"'])assert(!html.includes(value),value);for(const value of ['&lt;img','&lt;script','&lt;a'])assert(html.includes(value),value);
});
check('mixed origin histories retain separate sections and bounded search coverage',()=>{
 const html=ui.markup({offers:[offer()],web_inquiries:[inquiry()],search_inquiries:Array.from({length:8},search)});assert.equal((html.match(/class="leadDetails tourChoice"/g)||[]).length,7);assert(html.includes('список неполный'));for(const value of ['Понравившиеся туры','Обращения из веб-подборки','Выбранные туры из поиска'])assert.equal(html.split(value).length-1,1);
});
check('bad search timestamps never break the remaining customer card',()=>{
 for(const capturedAt of [null,0,-1,NaN,9007199254740991]){const item=search();item.observation.capturedAt=capturedAt;assert(ui.markup({offers:[offer()],search_inquiries:[item]}).includes('Hotel Sea'));}
 for(const value of [null,{}, {observation:null},{observation:{hotel:{name:''}}}])assert.equal(ui.markup({offers:[],search_inquiries:[value]}),'');
});
console.log('PASS manager tour choices '+checks+' checks');
