const {test,expect}=require('@playwright/test');
const {execFileSync}=require('node:child_process');
const fs=require('node:fs'),path=require('node:path');
const root=path.resolve(__dirname,'../..'),origin='http://127.0.0.1:4173';
const shell=execFileSync('php',['manager2/clients.php'],{cwd:root,encoding:'utf8'});
const me=(id=7,role='admin')=>({ok:true,manager:{id,role,display_name:'Синтетический сотрудник'},csrf:'synthetic-'+id});
const client=(id=12,name='Мастерская Север')=>({id,display_name:name,is_active:true,managed:true,bot_key:'synthetic_'+id+'_bot',state:'created',check:null});
function deferred(){let resolve;const promise=new Promise(r=>resolve=r);return{promise,resolve};}
async function setup(page,options={}){
  const state={identity:options.identity||me(),clients:options.clients||[client()],calls:[],errors:[],unexpected:[],receipts:{},...options};
  page.on('pageerror',e=>state.errors.push(e.message));page.on('dialog',dialog=>dialog.accept());
  await page.route('**/*',async route=>{
    const request=route.request(),url=new URL(request.url());const block=()=>{state.unexpected.push(request.method()+' '+url.pathname);return route.abort();};
    if(url.origin!==origin)return block();
    if(request.method()==='GET'&&url.pathname==='/manager2/clients.php')return route.fulfill({contentType:'text/html',body:shell});
    if(request.method()==='GET'&&/^\/manager\/assets\/(clients\.(js|css)|manager-http-client\.js)$/.test(url.pathname))return route.fulfill({contentType:url.pathname.endsWith('.css')?'text/css':'application/javascript',body:fs.readFileSync(path.join(root,url.pathname.slice(1)))});
    if(url.pathname==='/favicon.ico')return route.fulfill({status:204,body:''});
    if(request.method()!=='POST'||url.pathname!=='/manager/clients-api.php')return block();
    const data=request.postDataJSON();state.calls.push(data);
    if(data.action!=='me'){expect(data.expected_actor).toBe(state.identity.manager.id);expect(data.csrf).toBe(state.identity.csrf);}
    let result;
    if(state.handle)result=await state.handle(data,state);
    if(result===undefined)switch(data.action){
      case 'me':result=state.identity;break;
      case 'list':result={ok:true,is_admin:state.identity.manager.role==='admin',clients:state.clients};break;
      case 'detail':{const row=state.clients.find(c=>c.id===data.project_id);result={ok:true,client:{id:data.project_id,display_name:row.display_name,bot_key:row.bot_key,business_profile:row.business_profile||null,materials:row.materials??'Диагностика — бесплатно\nГарантия 90 дней',can_configure:!['active','activation_pending'].includes(row.state),staff:[{display_name:'Анна',login:'anna'}]}};break;}
      case 'create':{
        const row=client(22,data.client.display_name);if(data.client.business_kind==='manager_inbox')row.business_profile={kind:'manager_inbox',label:'Обращения к менеджеру',description:'Для компаний любой тематики: общий вопрос и ответ сотрудника.'};state.clients.push(row);result={ok:true,state:'created',project_id:22,manager_ids:[31],operation_id:data.client.operation_id};state.receipts[data.client.operation_id]=result;break;
      }
      case 'verify':state.clients.find(c=>c.id===data.project_id).state='verified';result={ok:true,state:'planned',reason:'registration_required'};break;
      case 'configure':{const row=state.clients.find(c=>c.id===data.project_id);Object.assign(row,{bot_key:data.client.bot_key,materials:data.client.materials,state:'created'});result={ok:true,state:'configured',project_id:data.project_id,operation_id:data.client.operation_id};state.receipts[data.client.operation_id]=result;break;}
      case 'activate':state.clients.find(c=>c.id===data.project_id).state='active';result={ok:true,state:'active',reason:'activated',operation_id:data.operation_id,project_id:data.project_id};state.receipts[data.operation_id]=result;break;
      case 'receipt':result=state.receipts[data.operation_id]||{ok:true,state:'not_found'};break;
      case 'cancel':result=state.receipts[data.operation_id]||{ok:true,state:'cancelled',operation_id:data.operation_id};state.receipts[data.operation_id]=result;break;
      default:return block();
    }
    if(result?.abort)return route.abort('failed');if(result?.invalidJSON)return route.fulfill({contentType:'application/json',body:'{bad json'});
    return route.fulfill({contentType:'application/json',body:JSON.stringify(result)});
  });
  await page.goto(origin+'/manager2/clients.php');await expect(page.locator('#workspace')).toBeVisible();await expect(page.locator('#clients button')).toHaveCount(state.clients.length);return state;
}
function clean(state){expect(state.errors).toEqual([]);expect(state.unexpected).toEqual([]);}
async function fill(page){await page.locator('#addClient').click();await page.locator('#clientName').fill('Новая мастерская');await page.locator('#botKey').fill('new_synthetic_bot');await page.locator('#botToken').fill('private-synthetic-token');await page.locator('#staffName').fill('Мария');await page.locator('#staffLogin').fill('new_maria');await page.locator('#staffPassword').fill('private-synthetic-password');await page.locator('#materials').fill('Ремонт телефонов\nДиагностика — бесплатно');}
async function capture(page,browser,width,label){const directory=path.join(root,'manager2-artifacts');fs.mkdirSync(directory,{recursive:true});await page.screenshot({path:path.join(directory,`clients-${browser}-${width}-${label}.png`),fullPage:true,animations:'disabled'});}

test('create, inspect, verify and separately activate a second company',async({page})=>{
  const state=await setup(page);await fill(page);await page.locator('#saveClient').click();await expect(page.locator('#detailTitle')).toHaveText('Новая мастерская');await expect(page.locator('#editor')).toBeHidden();await expect(page.locator('#botToken')).toHaveValue('');
  expect(state.calls.filter(c=>c.action==='activate')).toHaveLength(0);await expect(page.locator('#activateClient')).toBeHidden();await page.locator('#verifyClient').click();await expect(page.locator('#activateClient')).toBeVisible();await page.locator('#activateClient').click();await expect(page.locator('#connectionState')).toContainText('Подключение активно');
  expect(state.calls.filter(c=>c.action==='create')).toHaveLength(1);expect(state.calls.filter(c=>c.action==='activate')).toHaveLength(1);await expect(page.locator('#detailMaterials')).toContainText('Гарантия 90 дней');clean(state);
});
test('double submit is fenced while a create is in flight',async({page})=>{
  const gate=deferred();const state=await setup(page,{handle:async data=>data.action==='create'?gate.promise:undefined});await fill(page);await page.locator('#saveClient').click();await expect(page.locator('#saveClient')).toBeDisabled();await page.locator('#clientForm').evaluate(form=>form.dispatchEvent(new Event('submit',{cancelable:true})));
  expect(state.calls.filter(c=>c.action==='create')).toHaveLength(1);gate.resolve({ok:false,error:'duplicate_client'});await expect(page.locator('#saveClient')).toBeEnabled();await expect(page.locator('#botToken')).toHaveValue('private-synthetic-token');clean(state);
});
test('neutral company profile explains its limits and is retained in its card',async({page})=>{
  const state=await setup(page);await fill(page);await page.locator('#businessKind').selectOption('manager_inbox');await expect(page.locator('#profileHint')).toContainText('Для компаний любой тематики');await expect(page.locator('#profileHint')).toContainText('не включены');
  await page.locator('#clientName').fill('Студия Север');await page.locator('#materials').fill('Услуги студии');await page.locator('#saveClient').click();await expect(page.locator('#detailTitle')).toHaveText('Студия Север');await expect(page.locator('#detailProfile')).toContainText('Обращения к менеджеру');
  expect(state.calls.find(c=>c.action==='create').client.business_kind).toBe('manager_inbox');expect(state.calls.filter(c=>c.action==='activate')).toHaveLength(0);await expect(page.locator('#botToken')).toHaveValue('');clean(state);
});
test('lost successful create restores receipt after reload without storing secrets or replaying create',async({page})=>{
  const state=await setup(page,{handle:async(data,state)=>{
    if(data.action!=='create')return;
    const result={ok:true,state:'created',operation_id:data.client.operation_id,project_id:22,manager_ids:[31]};state.clients.push(client(22,'Новая мастерская'));state.receipts[data.client.operation_id]=result;return{abort:true};
  }});await fill(page);await page.locator('#saveClient').click();await expect(page.locator('#pending')).toBeVisible();await expect(page.locator('#receipt')).toBeEnabled();
  const stored=await page.evaluate(()=>JSON.stringify({...sessionStorage,...localStorage}));expect(stored).not.toContain('private-synthetic');expect(stored).not.toContain('Новая мастерская');
  await page.reload();await expect(page.locator('#detailTitle')).toHaveText('Новая мастерская');await expect(page.locator('#pending')).toBeHidden();expect(state.calls.filter(c=>c.action==='create')).toHaveLength(1);await expect(page.locator('#botToken')).toHaveValue('');clean(state);
});
test('unknown create stays blocked until a durable cancellation',async({page})=>{
  const state=await setup(page,{handle:async data=>data.action==='create'?{invalidJSON:true}:undefined});await fill(page);await page.locator('#saveClient').click();await expect(page.locator('#receipt')).toBeEnabled();await expect(page.locator('#saveClient')).toBeDisabled();await page.locator('#receipt').click();await expect(page.locator('#notice')).toContainText('пока не найден');await expect(page.locator('#saveClient')).toBeDisabled();await page.locator('#cancelOperation').click();await expect(page.locator('#pending')).toBeHidden();await expect(page.locator('#saveClient')).toBeEnabled();await expect(page.locator('#clientName')).toHaveValue('Новая мастерская');expect(state.calls.filter(c=>c.action==='create')).toHaveLength(1);clean(state);
});
test('unknown activation is shown and cannot be retried',async({page})=>{
  const state=await setup(page,{handle:async(data,state)=>{if(data.action==='activate'){state.clients[0].state='activation_pending';return{ok:false,state:'unknown',reason:'registration_secret_unconfirmed',operation_id:data.operation_id,project_id:12};}}});await page.locator('[data-client-id="12"]').click();await page.locator('#verifyClient').click();await page.locator('#activateClient').click();await expect(page.locator('#notice')).toContainText('Повторная активация заблокирована');await expect(page.locator('#activateClient')).toBeHidden();await expect(page.locator('#pending')).toBeHidden();expect(state.calls.filter(c=>c.action==='activate')).toHaveLength(1);clean(state);
});
test('wrong connection can be corrected before activation and requires a fresh check',async({page,browserName})=>{
  await page.setViewportSize({width:390,height:900});const state=await setup(page);await page.locator('[data-client-id="12"]').click();await page.locator('#verifyClient').click();await expect(page.locator('#activateClient')).toBeEnabled();
  await page.locator('#connectionEditor summary').click();await page.locator('#editBotKey').fill('corrected_synthetic_bot');await page.locator('#editToken').fill('replacement-private-token');await page.locator('#editMaterials').fill('Исправленные материалы');await expect(page.locator('#verifyClient')).toBeDisabled();await expect(page.locator('#activateClient')).toBeDisabled();
  await capture(page,browserName,390,'correction');expect(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth)).toBe(true);await page.locator('#saveConnection').click();await expect(page.locator('#detailBot')).toContainText('corrected_synthetic_bot');await expect(page.locator('#editToken')).toHaveValue('');await expect(page.locator('#detailMaterials')).toHaveText('Исправленные материалы');await expect(page.locator('#activateClient')).toBeHidden();expect(state.calls.filter(c=>c.action==='activate')).toHaveLength(0);
  await page.locator('#verifyClient').click();await page.locator('#activateClient').click();await expect(page.locator('#connectionEditor')).toBeHidden();clean(state);
});
test('lost correction restores its receipt after reload without a second save',async({page})=>{
  const state=await setup(page,{handle:async(data,state)=>{if(data.action==='configure'){Object.assign(state.clients[0],{bot_key:data.client.bot_key,materials:data.client.materials,state:'created'});state.receipts[data.client.operation_id]={ok:true,state:'configured',project_id:12,operation_id:data.client.operation_id};return{abort:true};}}});
  await page.locator('[data-client-id="12"]').click();await page.locator('#connectionEditor summary').click();await page.locator('#editToken').fill('replacement-private-token');await page.locator('#editMaterials').fill('Новые материалы');await page.locator('#saveConnection').click();await expect(page.locator('#receipt')).toBeEnabled();await expect(page.locator('#saveConnection')).toBeDisabled();
  expect(await page.evaluate(()=>JSON.stringify({...sessionStorage,...localStorage}))).not.toContain('replacement-private-token');await page.reload();await expect(page.locator('#pending')).toBeHidden();await expect(page.locator('#notice')).toContainText('Исправления сохранены');await page.locator('[data-client-id="12"]').click();await expect(page.locator('#detailMaterials')).toHaveText('Новые материалы');await expect(page.locator('#editToken')).toHaveValue('');expect(state.calls.filter(c=>c.action==='configure')).toHaveLength(1);clean(state);
});
test('late create receipt does not replace a company selected while saving',async({page})=>{
  const gate=deferred();const state=await setup(page,{handle:async data=>data.action==='create'?gate.promise:undefined});await fill(page);await page.locator('#saveClient').click();await page.locator('[data-client-id="12"]').click();await expect(page.locator('#detailTitle')).toHaveText('Мастерская Север');state.clients.push(client(22,'Новая мастерская'));gate.resolve({ok:true,state:'created',project_id:22});await expect(page.locator('#pending')).toBeHidden();await expect(page.locator('#detailTitle')).toHaveText('Мастерская Север');clean(state);
});
test('late card response cannot overwrite another selection',async({page})=>{
  const gate=deferred();const state=await setup(page,{clients:[client(12),client(13,'Мастерская Юг')],handle:async data=>data.action==='detail'&&data.project_id===12?gate.promise:undefined});await page.locator('[data-client-id="12"]').click();await expect.poll(()=>state.calls.filter(c=>c.action==='detail').length).toBe(1);await page.locator('[data-client-id="13"]').click();await expect(page.locator('#detailTitle')).toHaveText('Мастерская Юг');gate.resolve({ok:true,client:{id:12,display_name:'Чужой поздний ответ',materials:'private old data',staff:[]}});await page.waitForTimeout(50);await expect(page.locator('#detailTitle')).toHaveText('Мастерская Юг');await expect(page.locator('body')).not.toContainText('private old data');clean(state);
});
test('another account clears private form and rejects a late create response',async({page})=>{
  const gate=deferred();const state=await setup(page,{handle:async data=>data.action==='create'?gate.promise:undefined});await fill(page);await page.locator('#saveClient').click();await expect(page.locator('#saveClient')).toBeDisabled();state.identity=me(8);state.clients=[];
  await page.evaluate(()=>document.dispatchEvent(new Event('visibilitychange')));await expect(page.locator('#clientName')).toHaveValue('');await expect(page.locator('#botToken')).toHaveValue('');gate.resolve({ok:true,state:'created',project_id:22});await page.waitForTimeout(50);await expect(page.locator('#detail')).toBeHidden();await expect(page.locator('#pending')).toBeHidden();clean(state);
});
test('employee reads own materials without provisioning controls',async({page})=>{
  const state=await setup(page,{identity:me(31,'manager')});await expect(page.locator('#addClient')).toBeHidden();await page.locator('[data-client-id="12"]').click();await expect(page.locator('#detailMaterials')).toContainText('Диагностика — бесплатно');await expect(page.locator('#connectionActions')).toBeHidden();expect(state.calls.map(c=>c.action)).toEqual(['me','list','detail']);clean(state);
});
test('demotion with failed refresh never exposes cached admin companies or credentials',async({page})=>{
  const state=await setup(page);await page.locator('[data-client-id="12"]').click();await expect(page.locator('#detailMaterials')).toContainText('Гарантия');await fill(page);
  state.identity=me(7,'manager');state.handle=async data=>data.action==='list'?{ok:false,error:'storage_unavailable'}:undefined;
  await page.evaluate(()=>document.dispatchEvent(new Event('visibilitychange')));await expect(page.locator('#notice')).toContainText('Хранилище');await expect(page.locator('#clients button')).toHaveCount(0);await expect(page.locator('#detail')).toBeHidden();await expect(page.locator('#addClient')).toBeHidden();await expect(page.locator('#botToken')).toHaveValue('');clean(state);
});
test('text file import rejects oversized input and never executes materials',async({page})=>{
  const state=await setup(page);await fill(page);await page.locator('#materialsFile').setInputFiles({name:'prices.txt',mimeType:'text/plain',buffer:Buffer.from('<img src=x onerror=alert(1)>\nДиагностика 0 ₽')});await expect(page.locator('#materials')).toHaveValue('<img src=x onerror=alert(1)>\nДиагностика 0 ₽');await expect(page.locator('img')).toHaveCount(0);await page.locator('#materialsFile').setInputFiles({name:'large.txt',mimeType:'text/plain',buffer:Buffer.alloc(100001,65)});await expect(page.locator('#notice')).toContainText('до 100 КБ');await expect(page.locator('#materials')).toHaveValue(/Диагностика/);clean(state);
});
for(const width of [390,430,768,1440])test(`company list and editor remain usable at ${width}px`,async({page,browserName})=>{
  await page.setViewportSize({width,height:900});const state=await setup(page,{clients:[client(),client(13,'Вторая мастерская с длинным названием') ]});await capture(page,browserName,width,'list');await fill(page);await page.locator('#businessKind').selectOption('manager_inbox');await capture(page,browserName,width,'editor');expect((await page.locator('#businessKind').boundingBox()).height).toBeGreaterThanOrEqual(44);expect(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth)).toBe(true);await page.locator('#saveClient').scrollIntoViewIfNeeded();await expect(page.locator('#saveClient')).toBeInViewport();clean(state);
});
test('short phone viewport scrolls to explicit activation',async({page,browserName})=>{
  await page.setViewportSize({width:390,height:420});const state=await setup(page);await page.locator('[data-client-id="12"]').click();await page.locator('#verifyClient').click();await page.locator('#activateClient').scrollIntoViewIfNeeded();await expect(page.locator('#activateClient')).toBeInViewport();await capture(page,browserName,390,'short-activation');expect(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth)).toBe(true);clean(state);
});
