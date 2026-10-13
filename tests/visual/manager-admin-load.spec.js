const {test,expect}=require('@playwright/test');
const {execFileSync}=require('node:child_process');
const fs=require('node:fs');
const path=require('node:path');

const root=path.resolve(__dirname,'../..'),origin='http://127.0.0.1:4173';
// Render the actual PHP shell and serve its canonical assets directly. Every
// browser API response is synthetic; unexpected requests and writes are blocked.
const shell=execFileSync('php',['manager/admin.php'],{cwd:root,encoding:'utf8'});
const assets=new Set(['admin.css','admin.js','admin-edit-safety.js','manager-time.js','manager-http-client.js']);
const projects=[
  {id:1,project_key:'synthetic-main',display_name:'Синтетический основной проект',is_active:1},
  {id:2,project_key:'synthetic-second',display_name:'Синтетический второй проект',is_active:1}
];
const manager={id:7,role:'admin',display_name:'Синтетический администратор'};
const full=()=>({ok:true,admin:{
  projects,managers:[{id:12,login:'synthetic-manager',display_name:'Синтетический менеджер Мария',role:'manager',is_active:1,priority:0,project_ids:[1,2]}],sources:[],
  audit:[{created_at:'2026-10-13 12:00:00',actor_name:manager.display_name,action:'manager_updated',entity_type:'manager',entity_id:12}],
  priority_available:true,priority:{rules:[],rule_types:['project_key','source_key']}
}});
const empty=()=>({ok:true,admin:{projects:[],managers:[],sources:[],audit:[],priority_available:true,priority:{rules:[],rule_types:['project_key']}}});
const failed={ok:false,error:'admin_snapshot_failed',message:'PRIVATE_ADMIN_DIAGNOSTIC'};

async function setup(page,snapshots){
  const calls=[],unexpected=[],errors=[];
  page.on('pageerror',error=>errors.push(error.message));
  await page.route('**/*',async route=>{
    const request=route.request(),url=new URL(request.url()),method=request.method();
    const block=action=>{unexpected.push(method+' '+url.pathname+(action?' '+action:''));return route.abort()};
    if(url.origin!==origin)return block();
    if(method==='GET'&&url.pathname==='/manager/admin.php')return route.fulfill({contentType:'text/html',body:shell});
    if(method==='GET'&&url.pathname==='/favicon.ico')return route.fulfill({status:204,body:''});
    const asset=url.pathname.match(/^\/manager\/assets\/([^/]+)$/)?.[1];
    if(method==='GET'&&assets.has(asset))return route.fulfill({
      contentType:asset.endsWith('.css')?'text/css':'application/javascript',
      body:fs.readFileSync(path.join(root,'manager/assets',asset))
    });
    if(method!=='POST'||url.pathname!=='/manager/api.php')return block();
    let data;try{data=request.postDataJSON()}catch(error){return block('invalid-json')}
    calls.push(data);
    const reply=(body,status=200)=>route.fulfill({status,contentType:'application/json',body:JSON.stringify(body)});
    if(data?.action==='me')return reply({ok:true,csrf:'synthetic-admin-csrf',manager,projects});
    if(data?.action!=='admin_snapshot')return block(data?.action);
    if(data.csrf!=='synthetic-admin-csrf')return block('invalid-csrf');
    const response=snapshots.shift();
    if(!response)return block('unexpected-snapshot');
    return reply(response,response.ok?200:500);
  });
  await page.goto(origin+'/manager/admin.php');
  await expect(page.locator('#app')).toBeVisible();
  return{calls,unexpected,errors};
}
function assertReadsOnly(state){
  expect(state.calls.every(call=>['me','admin_snapshot'].includes(call.action))).toBe(true);
  expect(state.unexpected).toEqual([]);
  expect(state.errors).toEqual([]);
}
async function capture(page,browser,width,state){
  const directory=path.join(root,'manager2-artifacts');fs.mkdirSync(directory,{recursive:true});
  await page.screenshot({path:path.join(directory,`admin-${browser}-${width}-${state}.png`),fullPage:true,animations:'disabled'});
}

for(const width of [390,430,768,1440])test.describe('Admin load state '+width,()=>{
  test.use({viewport:{width,height:900}});
  test('failed snapshot, retry with private draft and confirmed empty catalog stay distinct',async({page,browserName})=>{
    const state=await setup(page,[failed,full(),empty()]);
    await expect(page.locator('#managers')).toContainText('Не удалось загрузить менеджеров.');
    await expect(page.locator('#managers')).not.toContainText('Менеджеров нет');
    await expect(page.locator('#projects')).toContainText('Полный список проектов не загружен.');
    await expect(page.locator('#audit')).not.toContainText('Изменений пока нет');
    await expect(page.locator('#app')).not.toContainText('PRIVATE_ADMIN_DIAGNOSTIC');
    for(const id of ['saveProject','saveManager','saveRule'])await expect(page.locator('#'+id)).toBeDisabled();
    await capture(page,browserName,width,'failed');

    await page.locator('#managerName').fill('Несохранённый синтетический менеджер');
    await page.locator('#managerPassword').fill('Synthetic-private-password');
    await page.locator('#managerProjects input[value="2"]').check();
    await page.locator('#managers [data-admin-retry]').click();
    await expect(page.locator('#managers .item')).toHaveCount(1);
    await expect(page.locator('#managers')).toContainText('Синтетический менеджер Мария');
    await expect(page.locator('#managers')).not.toContainText('Не удалось');
    await expect(page.locator('#managerName')).toHaveValue('Несохранённый синтетический менеджер');
    await expect(page.locator('#managerPassword')).toHaveValue('Synthetic-private-password');
    await expect(page.locator('#managerProjects input[value="2"]')).toBeChecked();
    await expect(page.locator('#managerProjects input[value="1"]')).not.toBeChecked();
    await expect(page.locator('#saveManager')).toBeEnabled();
    expect(state.calls.filter(call=>call.action==='admin_snapshot')).toHaveLength(2);
    await capture(page,browserName,width,'recovered');

    await page.reload();
    await expect(page.locator('#projects')).toContainText('Проектов нет');
    await expect(page.locator('#managers')).toContainText('Менеджеров нет');
    await expect(page.locator('#priorityRules')).toContainText('Правил пока нет');
    await expect(page.locator('#audit')).toContainText('Изменений пока нет');
    await expect(page.locator('[data-admin-retry]')).toHaveCount(0);
    await expect(page.locator('#app')).not.toContainText('Не удалось загрузить');
    expect(state.calls.filter(call=>call.action==='admin_snapshot')).toHaveLength(3);
    assertReadsOnly(state);
    await capture(page,browserName,width,'empty');
  });
});

test.describe('Admin optional priority failure',()=>{
  test.use({viewport:{width:1440,height:900}});
  test('priority outage keeps confirmed projects, managers and audit usable',async({page,browserName})=>{
    const snapshot=full();snapshot.admin.priority_available=false;delete snapshot.admin.priority;
    const state=await setup(page,[snapshot]);
    await expect(page.locator('#projects .item')).toHaveCount(2);
    await expect(page.locator('#projects')).toContainText('Синтетический второй проект');
    await expect(page.locator('#managers .item')).toHaveCount(1);
    await expect(page.locator('#managers')).toContainText('Синтетический менеджер Мария');
    await expect(page.locator('#audit .auditRow')).toHaveCount(1);
    await expect(page.locator('#audit')).toContainText(manager.display_name);
    await expect(page.locator('#priorityRules')).toContainText('Не удалось загрузить правила приоритета.');
    await expect(page.locator('#priorityRules')).not.toContainText('Правил пока нет');
    await expect(page.locator('#saveProject')).toBeEnabled();
    await expect(page.locator('#saveManager')).toBeEnabled();
    await expect(page.locator('#saveRule')).toBeDisabled();
    expect(state.calls.filter(call=>call.action==='admin_snapshot')).toHaveLength(1);
    assertReadsOnly(state);
    await capture(page,browserName,1440,'priority-failed');
  });
});
