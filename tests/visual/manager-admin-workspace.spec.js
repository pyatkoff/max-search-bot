const {test,expect}=require('@playwright/test');
const fs=require('node:fs');const path=require('node:path');
const {root,origin,entries,serveAdminShell}=require('./fixtures/admin-shell');
const projects=[{id:1,project_key:'synthetic-main',display_name:'Основной синтетический проект',is_active:1},{id:2,project_key:'synthetic-second',display_name:'Второй синтетический проект',is_active:1}];
const identity=(id=7,role='admin')=>({ok:true,csrf:'synthetic-csrf-'+id,manager:{id,role,display_name:'Синтетический администратор '+id},projects});
function snapshot(){return{ok:true,admin:{projects:projects.map(p=>({...p})),managers:[
{id:12,login:'synthetic-maria',display_name:'Мария Синтетическая',email:'maria@example.invalid',role:'manager',is_active:1,is_working:0,is_reachable:1,priority:4,project_ids:[1,2]},
{id:13,login:'synthetic-anton',display_name:'Антон Синтетический',role:'manager',is_active:0,is_working:1,is_reachable:0,priority:0,project_ids:[2]},
{id:14,login:'synthetic-admin',display_name:'Администратор Синтетический',role:'admin',is_active:1,is_working:1,is_reachable:1,priority:0,project_ids:[1]},
{id:15,login:'synthetic-unknown',display_name:'Неизвестные состояния',role:'manager',is_reachable:0,reachability_reason:'subscription_table_missing',priority:0,project_ids:[1]}
],sources:[],audit:[
{created_at:'2026-10-14 12:00:00',actor_name:'Синтетический администратор',action:'manager_updated',entity_type:'manager',entity_id:12},
{created_at:'2026-10-14 11:00:00',actor_name:'Другой синтетический администратор',action:'private_unknown_diagnostic',entity_type:'private_unknown_entity',entity_id:14}
],priority_available:true,priority:{rules:[{id:31,manager_id:12,rule_type:'project_key',rule_value:'synthetic-main',bonus:3,comment:'Синтетическое правило',is_active:1}],rule_types:['project_key','source_key']}}}}
function deferred(){let resolve;const promise=new Promise(r=>resolve=r);return{promise,resolve}}
async function setup(page,options={}){
 const state={calls:[],unexpected:[],errors:[],me:options.me||[identity()],snapshots:options.snapshots||[snapshot()],writes:options.writes||{}};
 page.on('pageerror',error=>state.errors.push(error.message));
 await page.route('**/*',async route=>{const request=route.request(),url=new URL(request.url()),method=request.method();const block=action=>{state.unexpected.push(method+' '+url.pathname+(action?' '+action:''));return route.abort()};
 if(url.origin!==origin)return block();if(await serveAdminShell(route))return;if(method==='GET'&&url.pathname==='/favicon.ico')return route.fulfill({status:204,body:''});
 if(method!=='POST'||url.pathname!=='/manager/api.php')return block();let data;try{data=request.postDataJSON()}catch(e){return block('invalid-json')}state.calls.push(data);
 const queue=data.action==='me'?state.me:data.action==='admin_snapshot'?state.snapshots:state.writes[data.action];if(!queue||!queue.length)return block(data.action+' not-authorized-in-fixture');if(data.action!=='me'&&!/^synthetic-csrf-\d+$/.test(data.csrf||''))return block('invalid-csrf');let response=queue.shift();if(typeof response==='function')response=await response(data);if(response?.promise)response=await response.promise;if(response?.abort)return route.abort('failed');if(response?.invalidJSON)return route.fulfill({contentType:'application/json',body:'{invalid json'});const body=response?.body||response;return route.fulfill({status:response?.status||(body?.ok===false?500:200),contentType:'application/json',body:JSON.stringify(body)});
 });await page.goto(origin+(options.entry||'/manager2/admin.php'));return state;
}
function clean(state){expect(state.unexpected).toEqual([]);expect(state.errors).toEqual([])}
async function ready(page){await expect(page.locator('#app')).toBeVisible();await expect(page.locator('#managers .item')).toHaveCount(4)}
async function section(page,name){await page.locator('[data-admin-section="'+name+'"]').click();await expect(page.locator('#adminSection-'+name)).toBeVisible()}
async function create(page,kind){await section(page,kind==='manager'?'managers':kind==='project'?'projects':'priority');await page.locator('[data-admin-create="'+kind+'"]').click();await expect(page.locator('#'+kind+'Editor')).toBeVisible()}
async function edit(page,kind,id){await section(page,kind==='manager'?'managers':kind==='project'?'projects':'priority');await page.locator('[data-admin-edit="'+kind+'"][data-admin-id="'+id+'"]').click();await expect(page.locator('#'+kind+'Editor')).toBeVisible()}
async function capture(page,browser,width,label){const dir=path.join(root,'manager2-artifacts');fs.mkdirSync(dir,{recursive:true});await page.screenshot({path:path.join(dir,`admin-workspace-${browser}-${width}-${label}.png`),animations:'disabled'})}
async function refresh(page){await page.evaluate(()=>load())}

for(const width of[390,430,768,1440])test.describe('Admin redesigned workspace '+width,()=>{
 test.use({viewport:{width,height:900}});
 test('sections, filters and editors stay usable without additional requests',async({page,browserName})=>{
  const state=await setup(page);await ready(page);await expect(page.locator('#adminSection-managers')).toBeVisible();await capture(page,browserName,width,'managers');
  const initialCalls=state.calls.length;for(const name of['projects','priority','audit','managers']){await section(page,name);await expect(page.locator('[data-admin-section="'+name+'"]').first()).toBeFocused();expect(state.calls.length).toBe(initialCalls)}
  await page.locator('#managerSearch').fill('Синтетическая');await expect(page.locator('#managers .item')).toHaveCount(1);await page.locator('#managerSearch').fill('');await page.locator('#managerRoleFilter').selectOption('manager');await page.locator('#managerActiveFilter').selectOption('1');await page.locator('#managerProjectFilter').selectOption('2');await expect(page.locator('#managers .item')).toHaveCount(1);await expect(page.locator('#managers')).toContainText('Мария Синтетическая');
  await page.locator('#managerSearch').fill('нет такого клиента');await expect(page.locator('#managers .item')).toHaveCount(0);await expect(page.locator('#managers')).toContainText('Никого не нашли');await expect(page.locator('#managers')).not.toContainText('Менеджеров нет');await page.locator('#managerSearch').fill('');
  await edit(page,'manager',12);await expect(page.locator('#managerLogin')).toBeFocused();await expect(page.locator('#managerPassword')).toHaveValue('');await capture(page,browserName,width,'manager-editor');await page.keyboard.press('Escape');await expect(page.locator('#managerEditor')).toBeHidden();await expect(page.locator('[data-admin-edit="manager"][data-admin-id="12"]')).toBeFocused();
  await create(page,'project');await expect(page.locator('#projectKey')).toBeFocused();await capture(page,browserName,width,'project-editor');await page.locator('[data-admin-close="project"]').click();await expect(page.locator('#projectEditor')).toBeHidden();
  await create(page,'rule');await expect(page.locator('#ruleValue')).toBeFocused();await capture(page,browserName,width,'rule-editor');await page.keyboard.press('Escape');await expect(page.locator('#ruleEditor')).toBeHidden();
  await section(page,'audit');await page.locator('#auditSearch').fill('Изменён менеджер');await expect(page.locator('#audit .auditRow')).toHaveCount(1);await expect(page.locator('#auditResults')).toContainText('1');await page.locator('#auditSearch').fill('private_unknown');await expect(page.locator('#audit .auditRow')).toHaveCount(0);await expect(page.locator('#audit')).not.toContainText('private_unknown');await page.locator('#auditSearch').fill('');await capture(page,browserName,width,'audit');
  expect(state.calls).toHaveLength(initialCalls);expect(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1)).toBe(true);clean(state);
 });
});

test.describe('Admin mutation and identity boundaries',()=>{
 test.use({viewport:{width:1440,height:900}});
 test('alternate and legacy entrypoints retain canonical assets, navigation and protected reads',async({page})=>{
  let canonicalIds,canonicalScripts;
  for(const entry of entries){
   const state=await setup(page,{entry});await ready(page);
   const rendered=await page.evaluate(()=>({base:document.baseURI,ids:[...document.querySelectorAll('[id]')].map(node=>node.id).sort(),scripts:[...document.scripts].map(node=>new URL(node.src).pathname),links:[...document.querySelectorAll('.nav a')].map(node=>new URL(node.href).pathname)}));
   expect(new Set(rendered.ids).size).toBe(rendered.ids.length);
   expect(rendered.scripts.every(src=>src.startsWith('/manager/assets/'))).toBe(true);
   expect(rendered.links).toContain('/manager2/');expect(rendered.links).toContain('/manager/routing.php');expect(rendered.links).toContain('/manager/pipeline-admin.php');
   if(entry==='/manager/admin.php'){canonicalIds=rendered.ids;canonicalScripts=rendered.scripts}else{
    expect(rendered.base).toBe(origin+'/manager/');expect(rendered.ids).toEqual(canonicalIds);expect(rendered.scripts).toEqual(canonicalScripts);
    await expect(page.locator('link[href^="/manager2/assets/"]')).toHaveCount(1);
   }
   expect(state.calls.map(call=>call.action)).toEqual(['me','admin_snapshot']);clean(state);
   await page.unroute('**/*');
  }
 });
 test('anonymous navigation exposes sign-in guidance without a catalog or editor request',async({page})=>{
  const state=await setup(page,{me:[{status:401,body:{ok:false,error:'unauthorized'}}],snapshots:[]});
  await expect(page.locator('#denied')).toContainText('Войдите в кабинет менеджера');await expect(page.locator('#app')).toBeHidden();
  for(const kind of ['project','manager','rule'])await expect(page.locator('#'+kind+'Editor')).toBeHidden();
  expect(state.calls.map(call=>call.action)).toEqual(['me']);clean(state);
 });
 test('account, shift and notification states are separate and missing states stay unknown',async({page})=>{
  const state=await setup(page);await ready(page);const maria=page.locator('#managers .item').filter({hasText:'Мария Синтетическая'}),anton=page.locator('#managers .item').filter({hasText:'Антон Синтетический'}),unknown=page.locator('#managers .item').filter({hasText:'Неизвестные состояния'});
  await expect(maria).toContainText('Аккаунт активен');await expect(maria).toContainText('Вне смены');await expect(maria).toContainText('Уведомления доступны');await expect(anton).toContainText('Аккаунт отключён');await expect(anton).toContainText('На смене');await expect(anton).toContainText('Уведомления недоступны');await expect(unknown).toContainText('Аккаунт: нет данных');await expect(unknown).toContainText('Смена: нет данных');await expect(unknown).toContainText('Уведомления: нет данных');await expect(unknown).not.toContainText('Вне смены');clean(state);
 });
 test('manager validation, network and invalid JSON preserve form, then confirmed save closes editor',async({page})=>{
  const state=await setup(page,{snapshots:[snapshot(),snapshot()],writes:{save_manager:[{abort:true},{invalidJSON:true},{ok:true}]}});await ready(page);await create(page,'manager');await page.locator('#saveManager').click();await expect(page.locator('#managerFormStatus')).toContainText('Логин');await expect(page.locator('#managerLogin')).toBeFocused();expect(state.calls.filter(c=>c.action==='save_manager')).toHaveLength(0);
  await page.locator('#managerLogin').fill('synthetic-new');await page.locator('#saveManager').click();await expect(page.locator('#managerFormStatus')).toContainText('8 символов');await expect(page.locator('#managerPassword')).toBeFocused();await page.locator('#managerPassword').fill('synthetic-private-password');await page.locator('#managerName').fill('Новый синтетический менеджер');await page.locator('#saveManager').click();await expect(page.locator('#managerFormStatus')).toContainText('выберите хотя бы один проект');
  await page.locator('#managerProjects input[value="1"]').check();await page.locator('#saveManager').click();await expect(page.locator('#managerFormStatus')).toContainText('Нет связи');await expect(page.locator('#managerName')).toHaveValue('Новый синтетический менеджер');await expect(page.locator('#managerPassword')).toHaveValue('synthetic-private-password');await expect(page.locator('#managerProjects input[value="1"]')).toBeChecked();
  await page.locator('#saveManager').click();await expect(page.locator('#managerFormStatus')).toContainText('Данные формы сохранены');await expect(page.locator('#managerLogin')).toHaveValue('synthetic-new');await page.locator('#saveManager').click();await expect(page.locator('#managerEditor')).toBeHidden();await expect(page.locator('#adminWorkspaceStatus')).toContainText('Менеджер сохранён');await expect(page.locator('[data-admin-create="manager"]')).toBeFocused();expect(state.calls.filter(c=>c.action==='save_manager')).toHaveLength(3);expect(state.calls.at(-1).action).toBe('admin_snapshot');clean(state);
 });
 test('editor close keeps draft, cancel resets it, and switching dirty record requires explicit choice',async({page})=>{
  const state=await setup(page);await ready(page);await edit(page,'manager',12);await page.locator('#managerName').fill('Несохранённое рабочее имя');await page.locator('[data-admin-close="manager"]').click();await edit(page,'manager',12);await expect(page.locator('#managerName')).toHaveValue('Несохранённое рабочее имя');await expect(page.locator('#managerId')).toHaveValue('12');await page.locator('[data-admin-close="manager"]').click();
  page.once('dialog',d=>d.dismiss());await page.locator('[data-admin-edit="manager"][data-admin-id="13"]').click();await expect(page.locator('#managerId')).toHaveValue('12');await expect(page.locator('#managerEditor')).toBeHidden();page.once('dialog',d=>d.accept());await edit(page,'manager',13);await expect(page.locator('#managerId')).toHaveValue('13');await page.locator('#managerName').fill('Не терять при создании нового');page.once('dialog',d=>d.dismiss());await page.locator('[data-admin-create="manager"]').click();await expect(page.locator('#managerId')).toHaveValue('13');await expect(page.locator('#managerName')).toHaveValue('Не терять при создании нового');await page.locator('#cancelManagerEdit').click();await expect(page.locator('#managerEditor')).toBeHidden();await create(page,'manager');await expect(page.locator('#managerId')).toHaveValue('');await expect(page.locator('#managerName')).toHaveValue('');clean(state);
 });
 test('project and priority editors save only through canonical synthetic mutations with safe confirmation',async({page})=>{
  const state=await setup(page,{snapshots:[snapshot(),snapshot(),snapshot()],writes:{save_project:[{ok:false,error:'missing_display_name'},{ok:true}],save_priority_rule:[{ok:true}]}});await ready(page);await edit(page,'project',1);await page.locator('#projectName').fill('');await page.locator('#saveProject').click();await expect(page.locator('#projectFormStatus')).toContainText('Укажите название');await expect(page.locator('#projectName')).toBeFocused();await page.locator('#projectName').fill('Синтетический изменённый проект');await page.locator('#saveProject').click();await expect(page.locator('#projectEditor')).toBeHidden();await expect(page.locator('#adminWorkspaceStatus')).toContainText('Проект сохранён');await expect.poll(()=>state.calls.filter(c=>c.action==='admin_snapshot').length).toBe(2);await expect(page.locator('[data-admin-edit="project"][data-admin-id="1"]')).toBeFocused();
  await edit(page,'rule',31);await page.locator('#ruleComment').fill('Синтетический изменённый комментарий');await page.locator('#saveRule').click();await expect(page.locator('#ruleEditor')).toBeHidden();await expect(page.locator('#adminWorkspaceStatus')).toContainText('Правило сохранено');await expect.poll(()=>state.calls.filter(c=>c.action==='admin_snapshot').length).toBe(3);await expect(page.locator('[data-admin-edit="rule"][data-admin-id="31"]')).toBeFocused();const actions=state.calls.filter(c=>!['me','admin_snapshot'].includes(c.action)).map(c=>c.action);expect(actions).toEqual(['save_project','save_project','save_priority_rule']);clean(state);
 });
 test('completed edit refresh restores source focus without stealing newer navigation or typing',async({page})=>{
  const slow=deferred(),state=await setup(page,{snapshots:[snapshot(),slow],writes:{save_project:[{ok:true,project_id:1}]}});await ready(page);await edit(page,'project',1);await page.locator('#projectName').fill('Изменённый синтетический проект');await page.locator('#saveProject').click();await expect(page.locator('#projectEditor')).toBeHidden();await expect.poll(()=>state.calls.filter(c=>c.action==='admin_snapshot').length).toBe(2);await section(page,'managers');await page.locator('#managerSearch').fill('Мария');slow.resolve(snapshot());await expect(page.locator('#managers .item')).toHaveCount(1);await expect(page.locator('#managerSearch')).toBeFocused();await expect(page.locator('#managerSearch')).toHaveValue('Мария');await expect(page.locator('#adminSection-managers')).toBeVisible();clean(state);
 });
 test('audit stays bounded and searches safe translated summaries while manager email search is local',async({page})=>{
  const large=snapshot();large.admin.audit=Array.from({length:80},(_,i)=>({...large.admin.audit[0],entity_id:i+1}));const state=await setup(page,{snapshots:[large]});await ready(page);await page.locator('#managerSearch').fill('maria@example.invalid');await expect(page.locator('#managers .item')).toHaveCount(1);await section(page,'audit');await expect(page.locator('#audit .auditRow')).toHaveCount(50);await page.locator('#auditSearch').fill('Изменён менеджер');await expect(page.locator('#audit .auditRow')).toHaveCount(50);await expect(page.locator('#audit')).not.toContainText('manager_updated');expect(state.calls.map(c=>c.action)).toEqual(['me','admin_snapshot']);clean(state);
 });
 test('stale snapshot keeps records and private form; missing chosen project disables write rather than silently removing access',async({page})=>{
  const smaller=snapshot();smaller.admin.projects=[projects[0]];const state=await setup(page,{snapshots:[snapshot(),{ok:false,error:'admin_snapshot_failed'},smaller]});await ready(page);await edit(page,'manager',12);await page.locator('#managerName').fill('Сохраняемый синтетический черновик');await refresh(page);await expect(page.locator('#managers')).toContainText('Показаны последние загруженные данные');await expect(page.locator('#managers .item')).toHaveCount(4);await expect(page.locator('#managerName')).toHaveValue('Сохраняемый синтетический черновик');await expect(page.locator('#saveManager')).toBeDisabled();await page.locator('[data-admin-retry]:visible').first().click();await expect(page.locator('#managerProjects [data-project-missing]')).toBeChecked();await expect(page.locator('#managerProjects [data-project-missing]')).toBeDisabled();await expect(page.locator('#saveManager')).toBeDisabled();await expect(page.locator('#managerName')).toHaveValue('Сохраняемый синтетический черновик');clean(state);
 });
 test('unauthorized and CSRF recovery preserve same-owner form but another account clears it',async({page})=>{
  const state=await setup(page,{me:[identity(),identity(),identity(8)],snapshots:[snapshot(),{ok:false,error:'unauthorized'},snapshot(),{ok:false,error:'csrf'},snapshot()]});await ready(page);await edit(page,'manager',12);await page.locator('#managerName').fill('Секретный синтетический черновик');await refresh(page);await expect(page.locator('#denied')).toContainText('Сессия истекла');await expect(page.locator('#app')).toBeHidden();await page.locator('#denied button').click();await ready(page);await expect(page.locator('#managerName')).toHaveValue('Секретный синтетический черновик');await refresh(page);await expect(page.locator('#denied')).toContainText('подтвердить доступ');await page.locator('#denied button').click();await ready(page);await expect(page.locator('#managerName')).toHaveValue('');await expect(page.locator('#managerId')).toHaveValue('');await expect(page.locator('#managerEditor')).toBeHidden();clean(state);
 });
 test('non-admin account cannot load catalog or enter editors',async({page})=>{
  const state=await setup(page,{me:[identity(7,'manager')],snapshots:[]});await expect(page.locator('#denied')).toContainText('Доступно только администратору');await expect(page.locator('#app')).toBeHidden();expect(state.calls.map(c=>c.action)).toEqual(['me']);clean(state);
 });
 for(const kind of ['project','manager','rule'])test('confirmed slow '+kind+' create retains newer text and binds returned ID before another save',async({page})=>{
  const slow=deferred(),action=kind==='rule'?'save_priority_rule':'save_'+kind,idKey=kind+'_id',returnedId=90;const state=await setup(page,{snapshots:[snapshot(),snapshot(),snapshot()],writes:{[action]:[slow,{ok:true,[idKey]:returnedId}]}});await ready(page);await create(page,kind);
  const field=kind==='manager'?'managerName':kind==='project'?'projectName':'ruleComment';
  if(kind==='manager'){await page.locator('#managerLogin').fill('synthetic-created');await page.locator('#managerPassword').fill('synthetic-private-password');await page.locator('#managerProjects input[value="1"]').check()}
  if(kind==='project')await page.locator('#projectKey').fill('synthetic-created');if(kind==='rule')await page.locator('#ruleValue').fill('synthetic-main');
  await page.locator('#'+field).fill('Синтетическая исходная версия');await page.locator('#save'+kind[0].toUpperCase()+kind.slice(1)).click();await expect.poll(()=>state.calls.filter(c=>c.action===action).length).toBe(1);await page.locator('#'+field).fill('Синтетическая новая версия');slow.resolve({ok:true,[idKey]:returnedId});
  await expect.poll(()=>state.calls.filter(c=>c.action==='admin_snapshot').length).toBe(2);await expect(page.locator('#'+kind+'Id')).toHaveValue(String(returnedId));await expect(page.locator('#'+kind+'Editor')).toBeVisible();await expect(page.locator('#'+field)).toHaveValue('Синтетическая новая версия');await expect(page.locator('#save'+kind[0].toUpperCase()+kind.slice(1))).toBeEnabled();await page.locator('#save'+kind[0].toUpperCase()+kind.slice(1)).click();await expect(page.locator('#'+kind+'Editor')).toBeHidden();expect(state.calls.filter(c=>c.action===action).map(c=>c[idKey])).toEqual([0,returnedId]);clean(state);
 });
 test('late old-account save cannot reset or close the new account editor',async({page})=>{
  const old=deferred(),state=await setup(page,{me:[identity(),identity(8)],snapshots:[snapshot(),snapshot()],writes:{save_manager:[old]}});await ready(page);await edit(page,'manager',12);await page.locator('#managerName').fill('Старый синтетический аккаунт');await page.locator('#saveManager').click();await expect(page.locator('#saveManager')).toBeDisabled();await expect.poll(()=>state.calls.filter(c=>c.action==='save_manager').length).toBe(1);await page.evaluate(()=>boot());await ready(page);await create(page,'manager');await page.locator('#managerLogin').fill('synthetic-other-account');await page.locator('#managerName').fill('Новый синтетический аккаунт');old.resolve({ok:true});await page.waitForFunction(()=>document.querySelector('#saveManager')&&!document.querySelector('#saveManager').dataset.idleText);await expect(page.locator('#managerEditor')).toBeVisible();await expect(page.locator('#managerName')).toHaveValue('Новый синтетический аккаунт');await expect(page.locator('#adminWorkspaceStatus')).not.toContainText('Менеджер сохранён');expect(state.calls.filter(c=>c.action==='admin_snapshot')).toHaveLength(2);clean(state);
 });
 test('late old-account load cannot overwrite fresh records or expose prior loading state',async({page})=>{
  const old=deferred(),fresh=snapshot();fresh.admin.managers=[{id:80,login:'synthetic-new-account',display_name:'Каталог нового аккаунта',role:'manager',is_active:1,project_ids:[1]}];const state=await setup(page,{me:[identity(),identity(8)],snapshots:[snapshot(),old,fresh]});await ready(page);await page.evaluate(()=>{window.syntheticOldLoad=load()});await expect(page.locator('#managers')).toContainText('Обновляем');await page.evaluate(()=>boot());await expect(page.locator('#managers .item')).toHaveCount(1);await expect(page.locator('#managers')).toContainText('Каталог нового аккаунта');old.resolve(snapshot());await page.evaluate(()=>window.syntheticOldLoad);await expect(page.locator('#managers .item')).toHaveCount(1);await expect(page.locator('#managers')).not.toContainText('Мария Синтетическая');await expect(page.locator('#managers')).not.toContainText('Обновляем');clean(state);
 });
});

test.describe('Short admin viewport',()=>{
 test.use({viewport:{width:390,height:420}});
 test('navigation and editor controls remain reachable with short mobile height',async({page,browserName})=>{
  const state=await setup(page);await ready(page);for(const name of['projects','priority','audit','managers']){await section(page,name);expect(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1)).toBe(true)}await edit(page,'manager',12);await page.locator('#managerName').fill('Короткий синтетический экран');await expect(page.locator('#managerName')).toBeFocused();await page.locator('#cancelManagerEdit').scrollIntoViewIfNeeded();await expect(page.locator('#cancelManagerEdit')).toBeInViewport();await capture(page,browserName,390,'short-editor');await page.locator('#cancelManagerEdit').click();await expect(page.locator('#managerEditor')).toBeHidden();await expect(page.locator('[data-admin-edit="manager"][data-admin-id="12"]')).toBeFocused();clean(state);
 });
});


// The owner ledger uses the real PHP renderer with synthetic data only.
const {execFileSync:renderDeliveryPHP}=require('node:child_process');
function deliveryRegistryHTML(scenario='ready'){
 const source=process.env.M2_QA_SOURCE_ROOT||root;
 return renderDeliveryPHP('php',[path.join(source,'tests/visual/fixtures/modeled-delivery-registry.php'),scenario],{cwd:source,encoding:'utf8'});
}
async function openDeliveryRegistry(page,scenario='ready'){
 const state={unexpected:[],errors:[]};
 page.on('pageerror',e=>state.errors.push(e.message));
 await page.route('**/*',async route=>{
  const request=route.request(),url=new URL(request.url());
  if(url.origin===origin&&request.method()==='GET'&&url.pathname==='/manager/assigned-value.php')
   return route.fulfill({contentType:'text/html',body:deliveryRegistryHTML(scenario)});
  if(url.origin===origin&&request.method()==='GET'&&url.pathname==='/favicon.ico')
   return route.fulfill({status:204,body:''});
  state.unexpected.push(request.method()+' '+url.pathname);return route.abort();
 });
 await page.goto(origin+'/manager/assigned-value.php?view=delivery');
 return state;
}
for(const width of[390,430,768,1440])test.describe('Private modeled delivery registry '+width,()=>{
 test.use({viewport:{width,height:900}});
 test('real renderer shows reconciled awards and readable details without requests',async({page,browserName},testInfo)=>{
  const state=await openDeliveryRegistry(page);
  await expect(page.getByRole('heading',{name:'Конверсии',exact:true})).toBeVisible();
  await expect(page.locator('.dialogue')).toHaveCount(2);
  await expect(page.locator('.totals')).toContainText('13 333 ₽');
  await expect(page.locator('.monitor')).toContainText('Регулярность подтверждена');
  expect(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth)).toBe(true);
  const dir=path.join(root,'manager2-artifacts');fs.mkdirSync(dir,{recursive:true});
  const file=path.join(dir,'modeled-delivery-'+browserName+'-'+width+'.png');
  const png=await page.screenshot({path:file,fullPage:true,animations:'disabled'});
  await testInfo.attach('modeled-delivery-'+width,{body:png,contentType:'image/png'});
  // Synthetic preview lets cloud-only reviewers inspect layout without Mac access.
  if(browserName==='chromium'&&[390,1440].includes(width))
   console.log('MVR-PREVIEW-'+width+':'+png.toString('base64'));
  await page.locator('.event-details').first().locator('summary').click();
  await expect(page.locator('.event-details').first()).toContainText('профилем №901');
  await expect(page.locator('.event-details').first()).toContainText('точное время подтверждения');
  expect(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth)).toBe(true);
  expect(state.unexpected).toEqual([]);expect(state.errors).toEqual([]);
 });
 test('stale OK and unavailable ledger stay visibly distinct from success',async({page})=>{
  let state=await openDeliveryRegistry(page,'stale');
  await expect(page.locator('.monitor')).toContainText('Проверка устарела');
  await expect(page.locator('.monitor')).toContainText('0 из 3 проверок');
  await expect(page.locator('.monitor')).not.toContainText('Регулярность подтверждена');
  expect(state.unexpected).toEqual([]);expect(state.errors).toEqual([]);
  await page.unroute('**/*');
  state=await openDeliveryRegistry(page,'error');
  await expect(page.getByRole('heading',{name:'Реестр сейчас недоступен'})).toBeVisible();
  await expect(page.locator('.dialogue')).toHaveCount(0);
  await expect(page.locator('body')).not.toContainText('Анна');
  expect(state.unexpected).toEqual([]);expect(state.errors).toEqual([]);
 });
});
test('conversion entry is owner-only and disappears when admin identity is revoked',async({page})=>{
 const state=await setup(page);await ready(page);
 await expect(page.locator('#adminConversions')).toBeVisible();
 await expect(page.locator('#adminConversions')).toHaveAttribute('href','/manager/assigned-value.php?view=delivery');
 state.snapshots.push({ok:false,error:'forbidden'});await page.evaluate(()=>load());
 await expect(page.locator('#adminConversions')).toBeHidden();clean(state);
});
test('ordinary manager cannot see the conversion entry or read admin data',async({page})=>{
 const state=await setup(page,{me:[identity(12,'manager')]});
 await expect(page.locator('#denied')).toContainText('Доступно только администратору');
 await expect(page.locator('#adminConversions')).toBeHidden();
 expect(state.calls.map(x=>x.action)).toEqual(['me']);clean(state);
});

// Aggregate acquisition timing uses the real PHP renderer and synthetic counts only.
function acquisitionStatsHTML(scenario='ready'){
 const source=process.env.M2_QA_SOURCE_ROOT||root;
 return renderDeliveryPHP('php',[path.join(source,'tests/visual/fixtures/modeled-acquisition-stats.php'),scenario],{cwd:source,encoding:'utf8'});
}
async function openAcquisitionStats(page,scenario='ready'){
 const state={unexpected:[],errors:[]};
 page.on('pageerror',e=>state.errors.push(e.message));
 await page.route('**/*',async route=>{
  const request=route.request(),url=new URL(request.url());
  if(url.origin===origin&&request.method()==='GET'&&url.pathname==='/manager/assigned-value.php'&&url.searchParams.get('view')==='acquisition')
   return route.fulfill({contentType:'text/html',body:acquisitionStatsHTML(scenario)});
  if(url.origin===origin&&request.method()==='GET'&&url.pathname==='/favicon.ico')
   return route.fulfill({status:204,body:''});
  state.unexpected.push(request.method()+' '+url.pathname);return route.abort();
 });
 await page.goto(origin+'/manager/assigned-value.php?view=acquisition');
 return state;
}
for(const width of[390,430,768,1440])test.describe('Private modeled acquisition statistics '+width,()=>{
 test.use({viewport:{width,height:900}});
 test('real renderer distinguishes observed inquiries, paired timing and pending Telegram source',async({page,browserName},testInfo)=>{
  const state=await openAcquisitionStats(page);
  await expect(page.getByRole('heading',{name:'От вступления до обращения',exact:true})).toBeVisible();
  await expect(page.locator('.channel-card')).toHaveCount(2);
  await expect(page.locator('#channel-max')).toContainText('5 из 12');
  await expect(page.locator('#channel-max')).toContainText('41,7%');
  await expect(page.locator('#channel-max')).toContainText('1 дн. 1 ч.');
  await expect(page.locator('#channel-telegram')).toContainText('Даты вступления подключим из Bitrix');
  await expect(page.locator('#channel-telegram')).not.toContainText('Медиана');
  await expect(page.locator('#channel-telegram')).not.toContainText('0%');
  await expect(page.locator('#channel-max .entry-context')).toContainText('сама по себе не подтверждает предшествующий визит');
  await expect(page.locator('#channel-telegram .entry-counts>div')).toHaveText([
   'Вход не позже обращения4','Неоднозначная связь2','Только более поздний вход1','Нет подходящего наблюдения2']);
  await expect(page.locator('#channel-telegram .entry-context')).toContainText('порядок событий внутри секунды неизвестен');
  await expect(page.locator('#channel-telegram .entry-context')).toContainText('не значит, что человек бот или визита не было');
  await expect(page.locator('select[name="period"]')).toHaveValue('30');
  expect(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth)).toBe(true);
  const dir=path.join(root,'manager2-artifacts');fs.mkdirSync(dir,{recursive:true});
  const file=path.join(dir,'modeled-acquisition-'+browserName+'-'+width+'.png');
  const png=await page.screenshot({path:file,fullPage:true,animations:'disabled'});
  await testInfo.attach('modeled-acquisition-'+width,{body:png,contentType:'image/png'});
  if(browserName==='chromium'&&[390,1440].includes(width))
   console.log('MAS-PREVIEW-'+width+':'+png.toString('base64'));
  await page.locator('.source-details summary').click();
  await expect(page.locator('.source-details')).toContainText('Проверено сохранённых профилей: 43');
  expect(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth)).toBe(true);
  expect(state.unexpected).toEqual([]);expect(state.errors).toEqual([]);
 });
 test('empty, unknown membership and unavailable results have different meanings',async({page})=>{
  let state=await openAcquisitionStats(page,'unknown');
  await expect(page.locator('#channel-max')).toContainText('0 из 12');
  await expect(page.locator('#channel-max')).toContainText('Нет подходящих пар дат');
  await expect(page.locator('#channel-max .timing')).toHaveCount(0);
  expect(state.unexpected).toEqual([]);expect(state.errors).toEqual([]);
  await page.unroute('**/*');state=await openAcquisitionStats(page,'empty');
  await expect(page.locator('.channel-card')).toHaveCount(2);
  await expect(page.locator('#channel-max')).toContainText('В доступных данных за выбранный период таких обращений нет.');
  await expect(page.locator('.error')).toHaveCount(0);
  expect(state.unexpected).toEqual([]);expect(state.errors).toEqual([]);
  await page.unroute('**/*');state=await openAcquisitionStats(page,'error');
  await expect(page.getByRole('heading',{name:'Статистика сейчас недоступна',exact:true})).toBeVisible();
  await expect(page.locator('.channel-card')).toHaveCount(0);
  expect(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth)).toBe(true);
  expect(state.unexpected).toEqual([]);expect(state.errors).toEqual([]);
 });
});


for(const width of [390,430,768,1440])test('regional manager grant survives an ordinary edit at '+width,async({page,browserName})=>{
 await page.setViewportSize({width,height:900});
 const initial=snapshot();initial.admin.managers[0].regional_project_ids=[2];
 const state=await setup(page,{snapshots:[initial,initial],writes:{save_manager:[{ok:true}]}});
 await ready(page);await edit(page,'manager',12);
 await expect(page.locator('#managerRegionalProjects input[value="2"]')).toBeChecked();
 await expect(page.locator('#managerRegionalProjects input[value="1"]')).not.toBeChecked();
 await page.locator('#managerName').fill('Обновлённое синтетическое имя');
 await page.locator('#managerRegionalProjects').scrollIntoViewIfNeeded();await capture(page,browserName,width,'regional-access');
 expect(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1)).toBe(true);
 await page.locator('#saveManager').click();await expect(page.locator('#managerEditor')).toBeHidden();
 expect(state.calls.find(c=>c.action==='save_manager')).toMatchObject({manager_id:12,project_ids:[1,2],regional_project_ids:[2]});
 await create(page,'manager');await page.locator('#managerProjects input[value="1"]').check();
 await expect(page.locator('#managerRegionalProjects input[value="1"]')).not.toBeChecked();
 clean(state);
});

test('a regional access edit made during save remains a newer unsent draft',async({page})=>{
 const initial=snapshot();initial.admin.managers[0].regional_project_ids=[2];const pending=deferred();
 const state=await setup(page,{snapshots:[initial,initial],writes:{save_manager:[pending]}});
 await ready(page);await edit(page,'manager',12);await page.locator('#managerName').fill('Первое имя');await page.locator('#saveManager').click();
 await expect.poll(()=>state.calls.filter(c=>c.action==='save_manager').length).toBe(1);
 await page.locator('#managerRegionalProjects input[value="1"]').check();pending.resolve({ok:true});
 await expect(page.locator('#managerFormStatus')).toContainText('Более новый черновик');
 await expect(page.locator('#managerRegionalProjects input[value="1"]')).toBeChecked();
 await expect(page.locator('#managerRegionalProjects input[value="2"]')).toBeChecked();
 expect(state.calls.find(c=>c.action==='save_manager').regional_project_ids).toEqual([2]);
 clean(state);
});

for(const width of [390,430,768,1440])test('routing territory search preserves hidden choices and existing member selection at '+width,async({page,browserName})=>{
 await page.setViewportSize({width,height:900});const state={calls:[],unexpected:[],errors:[]};
 const territories=[
  {region_id:'11131',name:'Самарская область',hierarchy:['Россия','Поволжье','Самарская область']},
  {region_id:'62',name:'Красноярский край',hierarchy:['Россия','Сибирь','Красноярский край']}
 ];
 const routing={groups:[{id:601,group_key:'synthetic-regional-team',display_name:'Синтетическая команда',members:[{id:12,display_name:'Тестовый менеджер'}],territory_region_ids:['11131'],territories:[territories[0]]}],sources:[],managers:[{id:12,display_name:'Тестовый менеджер'}],territory_options:territories};
 const shell=require('node:child_process').execFileSync('php',['manager/routing.php'],{cwd:root,encoding:'utf8'});
 page.on('pageerror',error=>state.errors.push(error.message));
 await page.route('**/*',async route=>{
  const request=route.request(),url=new URL(request.url());
  const refuse=()=>{state.unexpected.push(request.method()+' '+url.pathname);return route.abort()};
  if(url.origin!==origin)return refuse();
  if(request.method()==='GET'){
   if(url.pathname==='/manager/routing.php')return route.fulfill({contentType:'text/html',body:shell});
   if(url.pathname==='/favicon.ico')return route.fulfill({status:204,body:''});
   const file=url.pathname.match(/^\/manager\/assets\/(routing\.js|routing\.css|manager-http-client\.js)$/)?.[1];
   if(file)return route.fulfill({contentType:file.endsWith('.css')?'text/css':'application/javascript',body:fs.readFileSync(path.join(root,'manager/assets',file))});
   return refuse();
  }
  if(request.method()!=='POST'||url.pathname!=='/manager/api.php')return refuse();
  const data=request.postDataJSON();state.calls.push(data);
  const body=data.action==='me'?{...identity(),projects:[projects[0]]}:data.action==='routing_snapshot'?{ok:true,routing}:data.action==='save_group'?{ok:true,group_id:601}:null;
  if(!body)return refuse();return route.fulfill({contentType:'application/json',body:JSON.stringify(body)});
 });
 await page.goto(origin+'/manager/routing.php');await expect(page.locator('#groups')).toContainText('Самарская область');
 await page.locator('#groups a').click();await expect(page.locator('#members input[value="12"]')).toBeChecked();
 await expect(page.locator('#territories input[value="11131"]')).toBeChecked();
 await page.locator('#territorySearch').fill('Красноярский');await page.locator('#territories input[value="62"]').check();
 await expect(page.locator('#territories input[value="11131"]')).toHaveCount(0);
 await page.locator('#territorySearch').fill('');await expect(page.locator('#territories input[value="11131"]')).toBeChecked();
 await expect(page.locator('#territories input[value="62"]')).toBeChecked();
 await page.locator('#territories').scrollIntoViewIfNeeded();await capture(page,browserName,width,'routing-territories');
 expect(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1)).toBe(true);
 await page.locator('#saveGroup').click();await expect(page.locator('#groupStatus')).toContainText('Группа сохранена');
 const saved=state.calls.find(c=>c.action==='save_group');expect(saved).toMatchObject({project_key:'synthetic-main',group_id:601,member_ids:[12]});expect(saved.territory_region_ids.sort()).toEqual(['11131','62']);
 clean(state);
});

test('regranting a removed project restores its durable regional restriction',async({page})=>{
 const initial=snapshot();initial.admin.managers[0].project_ids=[1];initial.admin.managers[0].regional_project_ids=[2];
 const state=await setup(page,{snapshots:[initial,initial],writes:{save_manager:[{ok:true}]}});
 await ready(page);await edit(page,'manager',12);
 await expect(page.locator('#managerProjects input[value="2"]')).not.toBeChecked();
 await expect(page.locator('#managerRegionalProjects input[value="2"]')).toHaveCount(0);
 await page.locator('#managerProjects input[value="2"]').check();
 await expect(page.locator('#managerRegionalProjects input[value="2"]')).toBeChecked();
 await page.locator('#saveManager').click();await expect(page.locator('#managerEditor')).toBeHidden();
 expect(state.calls.find(c=>c.action==='save_manager')).toMatchObject({project_ids:[1,2],regional_project_ids:[2]});
 clean(state);
});
