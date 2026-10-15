const assert=require('node:assert/strict');
const fs=require('node:fs');
const path=require('node:path');
const vm=require('node:vm');

// Execute the actual filter owner with isolated browser storage and select
// controls. No transport, manager session or production information is used.
const storageKey='anytour.manager.workspace.filters.v1';
const saved={project:'anytour',source:12,manager:'9',stage:'quote',tag:8,outcome:'won',task:'today',search:'Турция 🌍'};
const cases=[];
const test=(name,run)=>cases.push({name,run});
function harness({role='admin',initial=saved}={}){
  const nodes=new Map(),loads=[],queues=[],writes=[],stored=new Map([[storageKey,JSON.stringify(initial)]]),timers=new Map();let timerId=0;
  const decode=text=>text.replace(/&quot;/g,'"').replace(/&#39;/g,"'").replace(/&lt;/g,'<').replace(/&gt;/g,'>').replace(/&amp;/g,'&');
  class Control{
    constructor(select=false){this.select=select;this.disabled=false;this.options=[];this._value='';this.classes=new Set();this.attributes={};this.classList={contains:name=>this.classes.has(name),toggle:(name,on)=>{on=on===undefined?!this.classes.has(name):on;if(on)this.classes.add(name);else this.classes.delete(name);return on}};}
    set innerHTML(value){this.html=String(value);this.options=[...this.html.matchAll(/<option value="([^"]*)">([^<]*)<\/option>/g)].map(match=>({value:decode(match[1]),label:decode(match[2])}));this._value=this.options[0]?.value||'';}
    get innerHTML(){return this.html||'';}
    set value(value){value=String(value);this._value=!this.select||this.options.some(option=>option.value===value)?value:'';}
    get value(){return this._value;}
    setAttribute(name,value){this.attributes[name]=String(value);}
    replaceChildren(...children){this.html='';this.options=children;this._value=this.options[0]?.value||'';}
  }
  for(const id of ['leadProjectFilter','leadSourceFilter','leadManagerFilter','leadStageFilter','leadTagFilter','leadOutcomeFilter','leadTaskFilter'])nodes.set(id,new Control(true));
  nodes.get('leadTaskFilter').innerHTML=['','today','planned','none','pinned','overdue'].map(value=>`<option value="${value}">${value}</option>`).join('');
  for(const id of ['leadSearch','leadManagerFilterWrap','filtersCount','filtersPanel','filtersToggle'])nodes.set(id,new Control());
  const S={manager:{role},projects:[],filterSources:[],filterManagers:[],filterOptionsReady:false,pipeline:{stages:[],tags:[],outcomes:{}},queue:'waiting',leadProjectFilter:'*',leadSourceFilter:0,leadManagerFilter:'',leadStageFilter:'',leadTagFilter:0,leadOutcomeFilter:'',leadTaskFilter:'',leadSearch:''};
  const criteria=()=>({project:S.leadProjectFilter,source:S.leadSourceFilter,manager:S.leadManagerFilter,stage:S.leadStageFilter,tag:S.leadTagFilter,outcome:S.leadOutcomeFilter,task:S.leadTaskFilter,search:S.leadSearch});
  // Match the real core's textContent -> innerHTML escape. Quotes remain literal;
  // this is a text escape and must never stand in for HTML attribute escaping.
  const window={WorkspaceV2:{S,$:id=>nodes.get(id),esc:value=>String(value).replace(/[&<>]/g,char=>({'&':'&amp;','<':'&lt;','>':'&gt;'}[char]))},WorkspaceV2Inbox:{setQueue(queue,options){S.queue=queue;queues.push({queue,options});},async load(options){loads.push({criteria:criteria(),queue:S.queue,options});return true}}};
  const document={activeElement:null,querySelectorAll:()=>[],createElement(tag){assert.equal(tag,'option');return {value:'',textContent:'',get label(){return this.textContent;}};}};
  const context=vm.createContext({window,document,sessionStorage:{getItem:key=>stored.get(key),setItem(key,value){writes.push({key,value});stored.set(key,value);}},clearTimeout:id=>timers.delete(id),setTimeout:(fn,ms)=>{const id=++timerId;timers.set(id,{fn,ms});return id;}});
  vm.runInContext(fs.readFileSync(path.join(__dirname,'../manager/assets/workspace-v2-filters.js'),'utf8'),context,{filename:'workspace-v2-filters.js'});
  function settle(){S.projects=[{project_key:'anytour',display_name:'AnyTour'}];S.filterSources=[{id:12,project_key:'anytour',display_name:'MAX'}];S.filterManagers=[{id:9,display_name:'Synthetic manager'}];S.filterOptionsReady=true;S.pipeline={stages:[{stage_key:'quote',display_name:'Подбор'}],tags:[{id:8,display_name:'Семья'}],outcomes:{won:'Продажа',open:'В работе'}};}
  return {F:window.WorkspaceV2Filters,S,nodes,loads,queues,writes,stored,criteria,settle,document,async runTimers(){for(const [id,timer] of timers){timers.delete(id);assert.equal(timer.ms,250);await timer.fn();}}};
}

test('pending metadata preserves every saved criterion and uses truthful disabled options',()=>{
  const h=harness();h.F.render({pending:true});
  assert.deepEqual(h.criteria(),saved);assert.equal(h.writes.length,0);assert.equal(h.loads.length,0);
  for(const [id,value] of [['leadProjectFilter','anytour'],['leadSourceFilter','12'],['leadManagerFilter','9'],['leadStageFilter','quote'],['leadTagFilter','8'],['leadOutcomeFilter','won']]){
    const select=h.nodes.get(id);assert.equal(select.disabled,true);assert.equal(select.value,value);assert.equal(select.options.length,1);assert.equal(select.options[0].label,'Загрузка…');assert.match(select.title,/Выбранный .*загружаем/);assert.doesNotMatch(select.title,/недоступны|Все /);
  }
  assert.equal(h.nodes.get('leadTaskFilter').disabled,false);assert.equal(h.nodes.get('leadTaskFilter').value,'today');assert.equal(h.nodes.get('leadSearch').value,saved.search);assert.equal(h.S.queue,'mine');
  assert.equal(h.nodes.get('filtersCount').textContent,'7');
});

test('quoted or markup-like saved criteria remain literal option values while metadata is pending',()=>{
  const unusual={...saved,project:'anytour" autofocus onfocus="synthetic',manager:'9"><option value="other',stage:'quote" data-test="saved',outcome:'won"><img src=x>'};
  const h=harness({initial:unusual});h.F.render({pending:true});assert.deepEqual(h.criteria(),unusual);assert.equal(h.writes.length,0);
  for(const [id,value] of [['leadProjectFilter',unusual.project],['leadManagerFilter',unusual.manager],['leadStageFilter',unusual.stage],['leadOutcomeFilter',unusual.outcome]]){
    const select=h.nodes.get(id);assert.equal(select.options.length,1);assert.equal(select.value,value);assert.equal(select.options[0].value,value);assert.equal(select.options[0].label,'Загрузка…');assert.deepEqual(select.attributes,{});
  }
});

test('settled valid metadata keeps the saved request and enables actual directory choices',()=>{
  const h=harness();h.F.render({pending:true});h.settle();h.F.render();
  assert.deepEqual(h.criteria(),saved);assert.equal(h.writes.length,0);assert.equal(h.loads.length,0);
  for(const id of ['leadProjectFilter','leadSourceFilter','leadManagerFilter','leadStageFilter','leadTagFilter','leadOutcomeFilter']){assert.equal(h.nodes.get(id).disabled,false);assert.doesNotMatch(h.nodes.get(id).innerHTML,/Загрузка|загружаем|загружаются/);assert.equal(h.nodes.get(id).title,'');}
  assert.equal(h.nodes.get('leadSourceFilter').value,'12');assert.equal(h.nodes.get('leadStageFilter').value,'quote');
});

test('pending mode clears the admin-only manager criterion before an ordinary manager request',()=>{
  const h=harness({role:'manager'});h.F.render({pending:true});
  assert.deepEqual(h.criteria(),{...saved,manager:''});assert.equal(h.nodes.get('leadManagerFilterWrap').classList.contains('hidden'),true);assert.equal(h.nodes.get('leadManagerFilter').value,'');
  assert.equal(h.nodes.get('filtersCount').textContent,'6');assert.equal(h.S.queue,'mine');assert.equal(h.loads.length,0);
});

test('settled missing metadata reconciles stale criteria and persists them without dropping task or search',()=>{
  const h=harness();h.F.render({pending:true});assert.deepEqual(h.criteria(),saved);h.F.render();
  assert.deepEqual(h.criteria(),{project:'*',source:0,manager:'',stage:'',tag:0,outcome:'',task:'today',search:saved.search});
  assert.deepEqual(JSON.parse(h.stored.get(storageKey)),h.criteria());assert.ok(h.writes.length>0);assert.equal(h.S.queue,'mine');
  assert.equal(h.nodes.get('leadStageFilter').disabled,true);assert.equal(h.nodes.get('leadStageFilter').options[0].label,'Этапы временно недоступны');assert.equal(h.nodes.get('leadTaskFilter').disabled,false);
});

test('Clear while metadata is pending stays in loading mode and only reloads the cleared Inbox request',async()=>{
  const h=harness();h.F.render({pending:true});h.F.bind();h.document.activeElement=h.nodes.get('leadSearch');h.nodes.get('leadSearch').value='Ещё не сохранённый поиск';h.nodes.get('leadSearch').oninput();await h.F.clear();
  const cleared={project:'*',source:0,manager:'',stage:'',tag:0,outcome:'',task:'',search:''};
  assert.deepEqual(h.criteria(),cleared);assert.deepEqual(JSON.parse(h.stored.get(storageKey)),cleared);assert.equal(h.loads.length,1);assert.deepEqual(h.loads[0].criteria,cleared);assert.equal(h.nodes.get('leadSearch').value,'');
  assert.equal(h.S.searchTimer,null);await h.runTimers();assert.equal(h.loads.length,1);
  for(const id of ['leadProjectFilter','leadSourceFilter','leadStageFilter','leadTagFilter','leadOutcomeFilter']){assert.equal(h.nodes.get(id).disabled,true);assert.equal(h.nodes.get(id).options[0].label,'Загрузка…');assert.match(h.nodes.get(id).title,/загружаются/);}
  h.settle();h.F.render();assert.deepEqual(h.criteria(),cleared);assert.equal(h.loads.length,1);assert.equal(h.nodes.get('leadStageFilter').disabled,false);
});

test('source and task shortcuts remain usable during metadata loading without resetting saved filters',async()=>{
  const h=harness();h.F.render({pending:true});await h.F.setSourceShortcut(-1);await h.F.setTaskShortcut('planned');
  const selected={...saved,source:-1,task:'planned'};
  assert.deepEqual(h.criteria(),selected);assert.equal(h.nodes.get('leadSourceFilter').value,'-1');assert.equal(h.nodes.get('leadSourceFilter').options[0].label,'Загрузка…');assert.match(h.nodes.get('leadSourceFilter').title,/Источник не определён.*загружаем/);assert.equal(h.nodes.get('leadStageFilter').value,'quote');assert.equal(h.nodes.get('leadTaskFilter').value,'planned');
  assert.equal(h.loads.length,2);assert.deepEqual(h.loads[1].criteria,selected);assert.equal(h.loads[1].queue,'mine');
  h.settle();h.F.render();assert.deepEqual(h.criteria(),selected);assert.equal(h.nodes.get('leadSourceFilter').value,'-1');
});

test('repeated pending render does not reload storage over a newer task or search choice',()=>{
  const h=harness();h.F.render({pending:true});h.S.leadSearch='Новый вопрос';h.S.leadTaskFilter='none';h.F.render({pending:true});
  assert.deepEqual(h.criteria(),{...saved,task:'none',search:'Новый вопрос'});assert.equal(h.nodes.get('leadSearch').value,'Новый вопрос');assert.equal(h.nodes.get('leadTaskFilter').value,'none');assert.equal(h.writes.length,0);assert.equal(h.loads.length,0);
});

test('metadata settling during the search debounce preserves typed text and applies its actual query once',async()=>{
  const h=harness();h.F.render({pending:true});h.F.bind();
  const search=h.nodes.get('leadSearch');h.document.activeElement=search;search.value='Новый запрос туриста';search.oninput();
  assert.equal(h.S.leadSearch,saved.search);assert.equal(h.loads.length,0);
  h.settle();h.F.render();assert.equal(search.value,'Новый запрос туриста');assert.equal(h.loads.length,0);
  await h.runTimers();assert.equal(h.S.leadSearch,'Новый запрос туриста');assert.equal(h.loads.length,1);assert.deepEqual(h.loads[0].criteria,{...saved,search:'Новый запрос туриста'});assert.equal(search.value,'Новый запрос туриста');assert.equal(h.S.searchTimer,null);
});

test('blur before metadata settles keeps the pending query and sends its exact text once',async()=>{
  const h=harness();h.F.render({pending:true});h.F.bind();
  const search=h.nodes.get('leadSearch');h.document.activeElement=search;search.value='  Только новые варианты 🌍  ';search.oninput();h.document.activeElement=null;
  assert.equal(h.S.leadSearch,saved.search);assert.ok(h.S.searchTimer);h.settle();h.F.render();
  assert.equal(search.value,'  Только новые варианты 🌍  ');assert.equal(h.loads.length,0);
  await h.runTimers();assert.equal(h.S.searchTimer,null);assert.equal(h.loads.length,1);assert.deepEqual(h.loads[0].criteria,{...saved,search:'Только новые варианты 🌍'});
  h.F.render();assert.equal(search.value,'Только новые варианты 🌍');assert.equal(h.loads.length,1);
});

(async()=>{let failed=0;for(const {name,run} of cases){try{await run();console.log('PASS '+name);}catch(error){failed++;console.error('FAIL '+name+'\n'+error.stack);}}console.log(`TOTAL ${cases.length} | FAIL ${failed}`);process.exitCode=failed?1:0;})();
