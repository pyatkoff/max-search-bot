const assert=require('node:assert/strict');
const fs=require('node:fs');
const path=require('node:path');
const vm=require('node:vm');

// Runs the canonical module. DOM stub parses generated options/checkboxes only;
// responses are synthetic and no HTTP or database writes occur.
const source=fs.readFileSync(path.join(__dirname,'../manager/assets/admin.js'),'utf8');
const escape=s=>String(s).replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
const deferred=()=>{let resolve;const promise=new Promise(r=>resolve=r);return{promise,resolve}};
const full=()=>({ok:true,admin:{projects:[{id:1,project_key:'synthetic',display_name:'Synthetic project',is_active:1},{id:2,project_key:'second',display_name:'Second project',is_active:1}],managers:[{id:7,login:'synthetic',display_name:'Synthetic manager',role:'admin',is_active:1,project_ids:[1,2]}],sources:[],audit:[{created_at:'2026-10-14 00:00:00',actor_name:'Synthetic admin',action:'manager_updated',entity_type:'manager',entity_id:7}],priority_available:true,priority:{rules:[{id:8,manager_id:7,rule_type:'project_key',rule_value:'synthetic',bonus:0,is_active:1}],rule_types:['project_key','source_key']}}});
function harness({snapshot=full(),editSafety=false,identity={ok:true,csrf:'synthetic-csrf',manager:{id:7,role:'admin'},projects:[{id:1,project_key:'synthetic',display_name:'Synthetic project'}]}}={}){
  const ids=new Map(),calls=[],snapshots=[snapshot],identities=[identity];let hook=null;
  class Element{
    constructor(tag='div'){this.tagName=tag.toUpperCase();this.children=[];this.handlers={};this.dataset={};this.disabled=false;this.checked=false;this._value='';this._html='';this._text='';this.className='';this.classList={add:c=>{this.className+=' '+c},remove:c=>{this.className=this.className.split(/\s+/).filter(x=>x!==c).join(' ')},contains:c=>this.className.split(/\s+/).includes(c),toggle:(c,force)=>{if(force)this.classList.add(c);else this.classList.remove(c)}};}
    set id(v){this._id=v;ids.set(v,this)}get id(){return this._id}
    set value(v){this._value=String(v)}get value(){return this._value}
    get options(){return this.children.filter(x=>x.tagName==='OPTION')}
    get selectedOptions(){return this.options.filter(x=>x.value===this.value)}
    set selectedIndex(i){this.value=this.options[i]?.value||''}get selectedIndex(){return this.options.findIndex(x=>x.value===this.value)}
    set textContent(v){this._text=String(v);this._html=escape(v);this.children=[]}get textContent(){return this._text}
    set innerHTML(v){this._html=String(v);this.children=[];this.parse(v);if(this.tagName==='SELECT')this.value=this.options[0]?.value||''}get innerHTML(){return this._html}
    parse(html){for(const m of String(html).matchAll(/<(input|option|button)\b([^>]*)(?:>([^<]*)<\/\1>)?/g)){const el=new Element(m[1]);el.value=m[2].match(/\bvalue="([^"]*)"/)?.[1]||'';el.checked=/\bchecked\b/.test(m[2]);el.disabled=/\bdisabled\b/.test(m[2]);el._text=m[3]||'';if(m[2].includes('data-admin-retry'))el.dataset.adminRetry='';if(m[2].includes('data-project-missing'))el.dataset.projectMissing='';this.appendChild(el)}}
    insertAdjacentHTML(position,html){assert.equal(position,'beforeend');this._html+=html;this.parse(html)}
    appendChild(el){el.parentElement=this;this.children.push(el);return el}
    get parentNode(){return this.parentElement}
    insertBefore(el,other){el.parentElement=this;this.children.splice(this.children.indexOf(other),0,el)}
    insertAdjacentElement(position,el){assert.equal(position,'afterend');this.parentElement.appendChild(el)}
    closest(selector){return selector==='.card'?(this.className==='card'?this:this.parentElement?.closest(selector)):null}
    querySelector(selector){return selector==='h3'?this.children.find(el=>el.tagName==='H3'):null}
    setAttribute(name,value){this[name]=value}
    replaceChildren(){this.children=[];this._html='';this._text=''}
    addEventListener(type,fn){this.handlers[type]=fn}
    focus(){}scrollIntoView(){}
    async dispatch(type){return this.handlers[type]?.({target:this})}
  }
  for(const id of ['app','denied','projects','managers','managerProjects','audit','priorityRules','projectId','projectKey','projectName','projectActive','saveProject','projectFormStatus','managerId','managerLogin','managerName','managerEmail','managerRole','managerPassword','managerPriority','managerActive','saveManager','managerFormStatus','ruleId','ruleManager','ruleType','ruleValue','ruleBonus','ruleComment','ruleActive','saveRule','ruleFormStatus']){const el=new Element(['ruleManager','ruleType','managerRole'].includes(id)?'select':'div');el.id=id}
  ids.get('app').className='hidden';ids.get('denied').className='hidden';ids.get('managerRole').value='manager';for(const id of ['managerActive','projectActive','ruleActive'])ids.get(id).checked=true;
  for(const kind of ['project','manager','rule']){const card=new Element();card.className='card';card.appendChild(new Element('h3'));for(const id of [kind+'Id',kind+'FormStatus','save'+kind[0].toUpperCase()+kind.slice(1)])card.appendChild(ids.get(id))}
  for(const [id,text] of [['saveProject','Сохранить проект'],['saveManager','Сохранить менеджера'],['saveRule','Сохранить правило']])ids.get(id).textContent=text;
  const descendants=el=>el.children.flatMap(child=>[child,...descendants(child)]);
  const document={readyState:'complete',getElementById:id=>ids.get(id),createElement:tag=>new Element(tag),querySelectorAll(selector){
    if(selector.startsWith('#managerProjects input'))return ids.get('managerProjects').children.filter(el=>el.tagName==='INPUT'&&(!selector.includes(':checked')||el.checked)&&(!selector.includes(':not(:disabled)')||!el.disabled));
    if(selector==='[data-admin-retry]')return [...ids.values()].flatMap(descendants).filter(el=>Object.hasOwn(el.dataset,'adminRetry'));
    return[];
  }};
  const context=vm.createContext({document,console,MutationObserver:class{observe(){}},ManagerHttpClient:{async request(action,data,csrf){calls.push({action,data,csrf});if(hook){const r=hook(action);if(r!==undefined)return r}if(action==='me')return identities.shift()||identity;if(action==='admin_snapshot'){const r=snapshots.shift();if(r instanceof Error)throw r;return r||full()}throw Error('Unexpected synthetic mutation: '+action)}},window:{ManagerTime:{format:value=>String(value)}}});
  vm.runInContext(source,context,{filename:'admin.js'});
  if(editSafety)vm.runInContext(fs.readFileSync(path.join(__dirname,'../manager/assets/admin-edit-safety.js'),'utf8'),context,{filename:'admin-edit-safety.js'});
  const settle=async()=>{for(let i=0;i<12;i++)await Promise.resolve()};
  return{ids,calls,document,ready:settle(),load:()=>context.load(),boot:()=>context.boot(),render:()=>context.render(),state:()=>vm.runInContext('S',context),enqueue:r=>snapshots.push(r),identity:r=>identities.push(r),hook:fn=>{hook=fn},editManager:id=>context.window.editManager(id),editRule:id=>context.window.editRule(id),settle};
}
const tests=[];const test=(name,run)=>tests.push({name,run});
const noWrites=h=>assert.equal(h.calls.some(c=>c.action.startsWith('save_')),false);
test('whole snapshot failure preserves explicit partial projects and never claims empty managers or audit',async()=>{
  const h=harness({snapshot:{ok:false,error:'admin_snapshot_failed',message:'PRIVATE_DIAGNOSTIC'}});await h.ready;
  assert.match(h.ids.get('projects').innerHTML,/Полный список проектов не загружен/);assert.match(h.ids.get('projects').innerHTML,/Synthetic project/);
  assert.match(h.ids.get('managers').innerHTML,/Не удалось загрузить менеджеров/);assert.doesNotMatch(h.ids.get('managers').innerHTML,/Менеджеров нет/);
  assert.match(h.ids.get('audit').innerHTML,/Не удалось загрузить журнал/);assert.doesNotMatch(h.ids.get('audit').innerHTML,/Изменений пока нет/);
  assert.match(h.ids.get('priorityRules').innerHTML,/Не удалось загрузить правила/);assert.doesNotMatch(h.ids.get('priorityRules').innerHTML,/продолжают работать/);
  for(const id of ['saveProject','saveManager','saveRule'])assert.equal(h.ids.get(id).disabled,true);
  assert.doesNotMatch([...h.ids.values()].map(el=>el.innerHTML).join(''),/PRIVATE_DIAGNOSTIC/);noWrites(h);
});
test('retry button fetches a fresh snapshot and keeps the unsent manager form and project selection',async()=>{
  const h=harness({snapshot:{ok:false,error:'network_error'}});await h.ready;
  h.ids.get('managerLogin').value='unsent-login';h.ids.get('managerName').value='Unsent name';h.ids.get('managerPassword').value='unsent-only';
  h.document.querySelectorAll('#managerProjects input')[0].checked=true;await h.ids.get('managerProjects').dispatch('change');
  h.enqueue(full());await h.document.querySelectorAll('[data-admin-retry]')[0].onclick();
  assert.match(h.ids.get('managers').innerHTML,/Synthetic manager/);assert.doesNotMatch(h.ids.get('managers').innerHTML,/Не удалось/);
  assert.equal(h.ids.get('managerLogin').value,'unsent-login');assert.equal(h.ids.get('managerName').value,'Unsent name');assert.equal(h.ids.get('managerPassword').value,'unsent-only');
  assert.deepEqual(h.document.querySelectorAll('#managerProjects input:checked').map(el=>el.value),['1']);assert.equal(h.ids.get('saveManager').disabled,false);noWrites(h);
});
test('only confirmed successful empty sections show empty states',async()=>{
  const snapshot={ok:true,admin:{projects:[],managers:[],sources:[],audit:[],priority_available:true,priority:{rules:[],rule_types:['project_key']}}};
  const h=harness({snapshot});await h.ready;
  assert.match(h.ids.get('projects').innerHTML,/Проектов нет/);assert.match(h.ids.get('managers').innerHTML,/Менеджеров нет/);assert.match(h.ids.get('audit').innerHTML,/Изменений пока нет/);assert.match(h.ids.get('priorityRules').innerHTML,/Правил пока нет/);noWrites(h);
});
for(const [label,failure] of [['network exception',new Error('PRIVATE_NETWORK')],['invalid JSON result',{ok:false,error:'invalid_response'}],['malformed success',{ok:true}]])test(label+' leaves the previous confirmed data visible as stale without clearing drafts',async()=>{
  const h=harness();await h.ready;h.editManager(7);h.ids.get('managerName').value='Draft name';h.ids.get('managerPassword').value='Draft password';h.editRule(8);h.ids.get('ruleValue').value='Draft rule';h.ids.get('ruleBonus').value='99';
  h.enqueue(failure);await h.load();
  assert.match(h.ids.get('managers').innerHTML,/Не удалось обновить менеджеров/);assert.match(h.ids.get('managers').innerHTML,/Synthetic manager/);assert.match(h.ids.get('audit').innerHTML,/Synthetic admin/);
  assert.equal(h.ids.get('managerName').value,'Draft name');assert.equal(h.ids.get('managerPassword').value,'Draft password');assert.equal(h.ids.get('ruleValue').value,'Draft rule');assert.equal(h.ids.get('ruleBonus').value,'99');assert.equal(h.ids.get('ruleManager').value,'7');assert.equal(h.ids.get('ruleType').value,'project_key');
  assert.deepEqual(h.document.querySelectorAll('#managerProjects input:checked').map(el=>el.value),['1','2']);for(const id of ['saveProject','saveManager','saveRule'])assert.equal(h.ids.get(id).disabled,true);noWrites(h);
});
test('failed refresh after confirmed empty calls it the last empty list, not current empty data',async()=>{
  const snapshot=full();snapshot.admin.managers=[];const h=harness({snapshot});await h.ready;h.enqueue({ok:false,error:'network_error'});await h.load();
  assert.match(h.ids.get('managers').innerHTML,/Последний загруженный список был пуст/);assert.doesNotMatch(h.ids.get('managers').innerHTML,/Менеджеров нет/);noWrites(h);
});
test('optional priority failure does not disable fresh managers and projects or invent empty rules',async()=>{
  const snapshot=full();snapshot.admin.priority_available=false;delete snapshot.admin.priority;const h=harness({snapshot});await h.ready;
  assert.match(h.ids.get('managers').innerHTML,/Synthetic manager/);assert.match(h.ids.get('audit').innerHTML,/Synthetic admin/);assert.equal(h.ids.get('saveProject').disabled,false);assert.equal(h.ids.get('saveManager').disabled,false);assert.equal(h.ids.get('saveRule').disabled,true);assert.doesNotMatch(h.ids.get('priorityRules').innerHTML,/Правил пока нет/);noWrites(h);
});
test('missing audit field is failed data rather than a confirmed empty journal',async()=>{
  const snapshot=full();delete snapshot.admin.audit;const h=harness({snapshot});await h.ready;
  assert.match(h.ids.get('audit').innerHTML,/Не удалось загрузить журнал/);assert.doesNotMatch(h.ids.get('audit').innerHTML,/Изменений пока нет/);assert.equal(h.ids.get('saveManager').disabled,false);noWrites(h);
});
test('refresh never rechecks a single project deliberately unchecked by the manager-form author',async()=>{
  const snapshot=full();snapshot.admin.projects=snapshot.admin.projects.slice(0,1);const h=harness({snapshot});await h.ready;
  const checkbox=h.document.querySelectorAll('#managerProjects input')[0];assert.equal(checkbox.checked,true);checkbox.checked=false;await h.ids.get('managerProjects').dispatch('change');h.enqueue(snapshot);await h.load();
  assert.equal(h.document.querySelectorAll('#managerProjects input')[0].checked,false);noWrites(h);
});
test('a refreshed catalog cannot silently drop selected project rights from an existing draft',async()=>{
  const h=harness();await h.ready;h.editManager(7);const next=full();next.admin.projects=next.admin.projects.slice(0,1);h.enqueue(next);await h.load();
  const selected=h.document.querySelectorAll('#managerProjects input:checked');assert.deepEqual(selected.map(el=>el.value),['1','2']);assert.equal(selected.find(el=>el.value==='2').disabled,true);assert.equal(h.ids.get('saveManager').disabled,true);await h.ids.get('saveManager').onclick();noWrites(h);
});
test('authorization expiry hides the admin app and preserves the draft for same-account re-entry',async()=>{
  const h=harness();await h.ready;h.ids.get('managerName').value='Private unsent name';h.enqueue({ok:false,error:'unauthorized'});await h.load();
  assert.equal(h.ids.get('app').classList.contains('hidden'),true);assert.match(h.ids.get('denied').textContent,/Сессия истекла/);assert.equal(h.ids.get('saveManager').disabled,true);
  await h.ids.get('denied').children[0].onclick();assert.equal(h.ids.get('app').classList.contains('hidden'),false);assert.equal(h.ids.get('managerName').value,'Private unsent name');noWrites(h);
});
test('a non-admin identity never requests or exposes an admin snapshot',async()=>{
  const h=harness({identity:{ok:true,manager:{id:8,role:'manager'}}});await h.ready;assert.equal(h.ids.get('app').classList.contains('hidden'),true);assert.equal(h.calls.some(c=>c.action==='admin_snapshot'),false);assert.match(h.ids.get('denied').textContent,/только администратору/);noWrites(h);
});
test('repeated retries share one read and retain button in-flight mutation guards',async()=>{
  const h=harness();await h.ready;const pending=deferred();h.hook(action=>action==='admin_snapshot'?pending.promise:undefined);h.ids.get('saveManager').dataset.idleText='Сохранить менеджера';h.ids.get('saveManager').disabled=true;
  const before=h.calls.length,a=h.load(),b=h.load();assert.equal(a,b);assert.equal(h.calls.length,before+1);pending.resolve(full());await a;assert.equal(h.ids.get('saveManager').disabled,true);noWrites(h);
});
test('a late snapshot from another identity cannot populate the new admin screen or restore its private drafts',async()=>{
  const h=harness({editSafety:true});await h.ready;h.editManager(7);h.ids.get('managerName').value='Previous private draft';await h.ids.get('managerName').dispatch('input');const pending=deferred();let captured=false;h.hook(action=>{if(action==='admin_snapshot'&&!captured){captured=true;return pending.promise}});const old=h.load();
  const next=full();next.admin.managers[0].display_name='New account directory';h.enqueue(next);h.identity({ok:true,csrf:'new-csrf',manager:{id:9,role:'admin'},projects:[]});await h.boot();
  pending.resolve(full());await old;assert.match(h.ids.get('managers').innerHTML,/New account directory/);assert.doesNotMatch(h.ids.get('managers').innerHTML,/Synthetic manager/);assert.equal(h.ids.get('managerName').value,'');assert.equal(h.ids.get('managerEditMode').textContent,'Новый менеджер');assert.equal(h.state().loading,false);noWrites(h);
});
test('cancel remains available during a catalog outage, and retry does not revive the cancelled draft',async()=>{
  const h=harness({editSafety:true});await h.ready;h.editManager(7);h.ids.get('managerName').value='Cancelled draft';h.enqueue({ok:false,error:'network_error'});await h.load();assert.equal(h.ids.get('saveManager').disabled,true);
  await h.ids.get('cancelManagerEdit').dispatch('click');assert.equal(h.ids.get('managerId').value,'');assert.equal(h.ids.get('managerName').value,'');assert.equal(h.ids.get('saveManager').disabled,true);
  h.enqueue(full());await h.load();assert.equal(h.ids.get('managerName').value,'');assert.equal(h.ids.get('saveManager').disabled,false);noWrites(h);
});
test('cancel is blocked only while an actual save is in flight',async()=>{
  const h=harness({editSafety:true});await h.ready;h.editManager(7);h.ids.get('managerName').value='In-flight draft';h.ids.get('saveManager').dataset.idleText='Сохранить менеджера';h.ids.get('saveManager').disabled=true;
  await h.ids.get('cancelManagerEdit').dispatch('click');assert.equal(h.ids.get('managerId').value,'7');assert.equal(h.ids.get('managerName').value,'In-flight draft');noWrites(h);
});
test('cancel removes a missing project marker without losing the ability to create a new manager',async()=>{
  const h=harness({editSafety:true});await h.ready;h.editManager(7);const next=full();next.admin.projects=next.admin.projects.slice(0,1);h.enqueue(next);await h.load();assert.equal(h.ids.get('saveManager').disabled,true);
  await h.ids.get('cancelManagerEdit').dispatch('click');assert.equal(h.ids.get('managerId').value,'');assert.equal(h.ids.get('saveManager').disabled,false);assert.equal(h.document.querySelectorAll('#managerProjects input').some(x=>x.value==='2'),false);noWrites(h);
});
test('CSRF failure asks to revalidate the session and retry uses a new token while preserving draft',async()=>{
  const h=harness();await h.ready;h.ids.get('projectName').value='Unsent project';h.enqueue({ok:false,error:'csrf'});await h.load();assert.equal(h.ids.get('app').classList.contains('hidden'),true);assert.match(h.ids.get('denied').textContent,/обновить сессию/);
  h.identity({ok:true,csrf:'renewed-csrf',manager:{id:7,role:'admin'},projects:[]});await h.ids.get('denied').children[0].onclick();assert.equal(h.ids.get('projectName').value,'Unsent project');assert.equal(h.calls.filter(c=>c.action==='admin_snapshot').at(-1).csrf,'renewed-csrf');noWrites(h);
});
test('editing another manager revalidates a formerly missing project selection',async()=>{
  const h=harness({editSafety:true});await h.ready;h.editManager(7);const next=full();next.admin.projects=next.admin.projects.slice(0,1);next.admin.managers[0].project_ids=[1];next.admin.managers.push({id:12,login:'second-manager',display_name:'Second manager',role:'manager',is_active:1,project_ids:[1]});
  h.enqueue(next);await h.load();assert.equal(h.ids.get('saveManager').disabled,true);assert.equal(h.state().projectSelectionValid,false);
  h.editManager(12);assert.deepEqual(h.document.querySelectorAll('#managerProjects input:checked').map(x=>x.value),['1']);assert.equal(h.state().projectSelectionValid,true);assert.equal(h.ids.get('saveManager').disabled,false);noWrites(h);
});
for(const recovery of ['select','edit'])test('fresh priority controls recover from an inactive target through '+recovery,async()=>{
  const h=harness({editSafety:true});await h.ready;h.editRule(8);const next=full();next.admin.managers[0].is_active=0;next.admin.managers.push({id:12,login:'second-manager',display_name:'Second manager',role:'manager',is_active:1,project_ids:[1]});next.admin.priority.rules.push({id:9,manager_id:12,rule_type:'source_key',rule_value:'second',bonus:0,is_active:1});
  h.enqueue(next);await h.load();assert.equal(h.ids.get('saveRule').disabled,true);assert.equal(h.ids.get('ruleManager').disabled,false);assert.equal(h.state().priorityAvailable,true);
  if(recovery==='select'){h.ids.get('ruleManager').value='12';await h.ids.get('ruleManager').dispatch('change')}else{h.editRule(9);assert.equal(h.ids.get('ruleId').value,'9');assert.equal(h.ids.get('ruleType').value,'source_key')}
  assert.equal(h.ids.get('ruleManager').value,'12');assert.equal(h.ids.get('saveRule').disabled,false);assert.equal(h.state().ruleSelectionValid,true);noWrites(h);
});
const prepareForm=(h,kind,label)=>{
  if(kind==='Manager'){h.ids.get('managerLogin').value='synthetic-login';h.ids.get('managerName').value=label;h.ids.get('managerPassword').value='Synthetic private password';h.document.querySelectorAll('#managerProjects input')[0].checked=true}
  if(kind==='Project'){h.ids.get('projectKey').value='synthetic-new';h.ids.get('projectName').value=label}
  if(kind==='Rule'){h.ids.get('ruleValue').value=label;h.ids.get('ruleComment').value='Synthetic private comment'}
};
const privateField={Manager:'managerName',Project:'projectName',Rule:'ruleValue'};
for(const kind of ['Project','Manager','Rule'])for(const outcome of [true,false,'throw'])test(`late ${kind} save ${outcome==='throw'?'exception':outcome?'success':'failure'} cannot change another account's private form`,async()=>{
  const h=harness({editSafety:true});await h.ready;prepareForm(h,kind,'Original private draft');const pending=deferred(),action={Project:'save_project',Manager:'save_manager',Rule:'save_priority_rule'}[kind];h.hook(a=>a===action?pending.promise:undefined);
  const saving=h.ids.get('save'+kind).onclick();assert.ok(h.ids.get('save'+kind).dataset.idleText);h.enqueue({ok:false,error:'unauthorized'});await h.load();
  h.identity({ok:true,csrf:'new-account-csrf',manager:{id:9,role:'admin'},projects:[]});await h.boot();assert.equal(h.ids.get('save'+kind).dataset.idleText,undefined);assert.equal(h.ids.get('save'+kind).disabled,false);
  prepareForm(h,kind,'New account private draft');const beforeReads=h.calls.filter(c=>c.action==='admin_snapshot').length,feedback=h.ids.get(kind[0].toLowerCase()+kind.slice(1)+'FormStatus').textContent;
  pending.resolve(outcome==='throw'?Promise.reject(new Error('PRIVATE_OLD_EXCEPTION')):outcome?{ok:true}:{ok:false,error:'PRIVATE_OLD_FAILURE'});await saving;
  assert.equal(h.ids.get(privateField[kind]).value,'New account private draft');assert.equal(h.ids.get(kind[0].toLowerCase()+kind.slice(1)+'FormStatus').textContent,feedback);assert.equal(h.calls.filter(c=>c.action==='admin_snapshot').length,beforeReads);assert.equal(h.ids.get('save'+kind).disabled,false);assert.equal(h.calls.filter(c=>c.action===action).length,1);
});
for(const kind of ['Project','Manager','Rule'])test(`old ${kind} continuation cannot release a newer account's in-flight save`,async()=>{
  const h=harness();await h.ready;prepareForm(h,kind,'Original draft');const old=deferred(),fresh=deferred(),action={Project:'save_project',Manager:'save_manager',Rule:'save_priority_rule'}[kind];let count=0;h.hook(a=>a===action?(count++===0?old.promise:fresh.promise):undefined);
  const oldSave=h.ids.get('save'+kind).onclick();h.enqueue({ok:false,error:'unauthorized'});await h.load();h.identity({ok:true,csrf:'new-account-csrf',manager:{id:9,role:'admin'},projects:[]});await h.boot();prepareForm(h,kind,'New in-flight draft');const newSave=h.ids.get('save'+kind).onclick();
  old.resolve({ok:true});await oldSave;assert.equal(h.ids.get('save'+kind).disabled,true);assert.ok(h.ids.get('save'+kind).dataset.idleText);assert.equal(h.ids.get(privateField[kind]).value,'New in-flight draft');
  fresh.resolve({ok:false,error:'network_error'});await newSave;assert.equal(h.ids.get('save'+kind).disabled,false);assert.equal(h.ids.get('save'+kind).dataset.idleText,undefined);assert.equal(h.ids.get(privateField[kind]).value,'New in-flight draft');assert.equal(h.calls.filter(c=>c.action===action).length,2);
});
for(const kind of ['Project','Manager','Rule'])test(`renewing the same account protects a newer ${kind} draft from its old save`,async()=>{
  const h=harness();await h.ready;prepareForm(h,kind,'Original draft');const pending=deferred(),action={Project:'save_project',Manager:'save_manager',Rule:'save_priority_rule'}[kind];h.hook(a=>a===action?pending.promise:undefined);const saving=h.ids.get('save'+kind).onclick();h.enqueue({ok:false,error:'csrf'});await h.load();h.identity({ok:true,csrf:'renewed-same-account',manager:{id:7,role:'admin'},projects:[]});await h.boot();prepareForm(h,kind,'Renewed account newer draft');const status=kind[0].toLowerCase()+kind.slice(1)+'FormStatus',feedback=h.ids.get(status).textContent,before=h.calls.filter(c=>c.action==='admin_snapshot').length;
  pending.resolve({ok:true});await saving;assert.equal(h.ids.get(privateField[kind]).value,'Renewed account newer draft');assert.equal(h.ids.get(status).textContent,feedback);assert.equal(h.ids.get('save'+kind).disabled,false);assert.equal(h.calls.filter(c=>c.action==='admin_snapshot').length,before);assert.equal(h.calls.filter(c=>c.action===action).length,1);
});
(async()=>{let failures=0;for(const{name,run}of tests){try{await run();console.log('PASS '+name)}catch(e){failures++;console.error('FAIL '+name+'\n'+e.stack)}}console.log(`TOTAL ${tests.length} | FAIL ${failures}`);process.exitCode=failures?1:0})().catch(e=>{console.error(e);process.exitCode=1});
