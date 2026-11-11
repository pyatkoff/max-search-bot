'use strict';
const assert=require('node:assert/strict');
const fs=require('node:fs');
const path=require('node:path');
const vm=require('node:vm');
const escape=value=>String(value).replace(/[&<>"']/g,char=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'})[char]);
const card={innerHTML:''},calls=[];
const S={current:11,manager:{role:'manager'},pipeline:{stages:[],tags:[],outcomes:{},closeReasons:{}}};
const window={WorkspaceV2:{S,$:id=>id==='leadCard'?card:null,esc:escape,statusText:value=>String(value||''),tripField:(label,value)=>value?`<div class="field"><span>${escape(label)}</span><strong>${escape(value)}</strong></div>`:'',api:(...args)=>calls.push(args)}};
vm.runInNewContext(fs.readFileSync(path.join(__dirname,'../manager/assets/workspace-v2-lead-card.js'),'utf8'),{window});
const ui=window.WorkspaceV2LeadCard;
let checks=0;
function check(name,run){run();checks++;console.log('PASS '+name)}
function region(name,label=name){return {yandex_region_id:'62',name,label,hierarchy:[name,'Россия'],known:true}}
function render(source={},trip={city:'Москва',country:'Турция'},project='anytour'){
  const payload={pipeline:{},trip,source:{project_label:project,origin_label:'MAX · реклама',...source},contact:{},handoff:{},tasks:[]};
  const before=JSON.stringify(payload);
  ui.render(payload,{display_name:'Турист',project_key:project});
  assert.equal(JSON.stringify(payload),before,'rendering must not mutate attribution or trip needs');
  return card.innerHTML;
}
check('first advertising entry stays separate from latest entry and explicit departure',()=>{
  const html=render({first_entry_region:region('Красноярск','Красноярск · Красноярский край'),latest_entry_region:region('Самара','Самара · Самарская область'),attribution_region:'63'});
  const hero=html.split('<section id="leadTasksSection"')[0];
  assert(hero.includes('<span class="leadEntryRegionCaption">Первый рекламный вход</span>'));
  assert(hero.includes('<strong class="leadEntryRegionValue">Красноярск · Красноярский край</strong>'));
  assert(!hero.includes('Самара'));
  assert(hero.includes('Москва → Турция'));
  assert(html.includes('<span class="label">Последний рекламный вход</span><span class="value">Самара · Самарская область</span>'));
  assert(!html.includes('Регион атрибуции'));
});
check('missing or malformed first receipt never falls back to latest raw attribution or departure',()=>{
  for(const first of [undefined,null,{},[],true,12,'Красноярск',{label:[]},{label:{}},{name:'Красноярск'},{label:'   '}]){
    const html=render({first_entry_region:first,latest_entry_region:region('Самара'),attribution_region:'63'});
    assert(!html.includes('leadEntryRegion'));
    assert(!html.includes('Первый рекламный вход'));
    assert(html.includes('Последний рекламный вход'));
    assert(html.includes('Москва → Турция'));
  }
});
check('legacy raw attribution remains only when the validated latest label is unavailable',()=>{
  for(const latest of [undefined,null,{label:''},{label:23}]){
    const html=render({first_entry_region:region('Красноярск'),latest_entry_region:latest,attribution_region:'63'});
    assert(html.includes('<span class="label">Регион атрибуции</span><span class="value">63</span>'));
    assert(!html.includes('Последний рекламный вход'));
    assert(html.includes('leadEntryRegionValue">Красноярск'));
  }
  const html=render();assert(!html.includes('Регион атрибуции'));assert(!html.includes('рекламный вход'));
});
check('unknown receipt presents only the safe server label without inventing a location',()=>{
  const unknown={yandex_region_id:'99999999999999999999',name:'',label:'Регион не определён',hierarchy:[],known:false};
  const html=render({first_entry_region:unknown,latest_entry_region:unknown});
  assert(html.includes('leadEntryRegionValue">Регион не определён'));
  assert(!html.includes(unknown.yandex_region_id));
  assert(!html.includes('Место жительства'));
});
check('captured labels and hierarchy are escaped while private receipt extras are ignored',()=>{
  const untrusted=region('<img src=x onerror="bad()">', '<script>bad()</script> & "город"');
  untrusted.hierarchy=['PRIVATE_HIERARCHY'];untrusted.metadata_json='PRIVATE_METADATA';untrusted.token='PRIVATE_TOKEN';untrusted.receipt_sha256='PRIVATE_HASH';
  const html=render({first_entry_region:untrusted,latest_entry_region:untrusted});
  assert(html.includes('&lt;script&gt;bad()&lt;/script&gt; &amp; &quot;город&quot;'));
  for(const value of ['<script>','<img ','PRIVATE_'])assert(!html.includes(value),value);
  assert(!html.includes('<form'));
});
check('read-only entry display works across projects and does not issue a mutation',()=>{
  for(const project of ['anytour','travel_partner','new_project']){
    const html=render({first_entry_region:region('Калининград','Калининград · Калининградская область')},{city:'Москва',country:'Турция'},project);
    assert(html.includes('leadEntryRegionValue">Калининград · Калининградская область'));
    assert(!html.includes('data-entry-region'));
  }
  assert.deepEqual(calls,[]);
});
console.log(`TOTAL ${checks} | PASS ${checks} | FAIL 0`);
