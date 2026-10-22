'use strict';
const fs=require('node:fs'),path=require('node:path'),{execFileSync}=require('node:child_process');
module.exports=({test,expect})=>{
  const root=path.resolve(__dirname,'../../..'),sourceRoot=process.env.M2_QA_SOURCE_ROOT||root,origin='http://127.0.0.1:4173';
  const shell=execFileSync('php',['manager2/analytics.php'],{cwd:sourceRoot,encoding:'utf8'});
  const scope={project_id:1,project_key:'anytour',display_name:'AnyTour',version:1,include_unsourced:false,sources:[{id:11,name:'Основной источник',channel:'telegram'}]};
  const result=(offset=0)=>({ok:true,as_of:'2026-10-22T10:00:00Z',mode:'started',metric:'all',totals:{conversations:206,assigned:205,waiting:155,requested:205,replied:103,continued:52,ready_candidates:7,won:1,lost:0,recorded_stage:1},first_reply:{n:103,p50_seconds:60,p95_seconds:120},by_manager:[{manager_id:9,manager_name:'Ответственный менеджер',conversations:206,waiting:155,replied:103,reply_n:103}],period_events:{requests:206,assignments:205,reply_messages:104,reply_conversations:104,delivery_failures:1},rows:Array.from({length:offset?50:50},(_,i)=>({id:1205-offset-i,display_name:'Синтетический клиент '+(offset+i+1),channel:'telegram',manager_id:9,manager_name:'Ответственный менеджер',first_reply_at:i%2?'2026-10-22T10:00:00Z':null,first_reply_seconds:i%2?60:null,first_reply_manager_id:i%2?7:null})),has_more:offset<150,next_cursor:1156-offset});
  async function setup(page,{admin=false}={}){
    const calls=[],errors=[],unexpected=[];let mode='success',grant=null;
    page.on('pageerror',e=>errors.push(e.message));await page.route('**/*',async route=>{
      const req=route.request(),url=new URL(req.url());const json=(body,status=200)=>route.fulfill({status,contentType:'application/json',body:JSON.stringify({viewer_id:4,...body})});
      if(url.origin!==origin){unexpected.push(req.url());return route.abort()}
      if(req.method()==='GET'&&url.pathname==='/manager2/analytics.php')return route.fulfill({contentType:'text/html',body:shell});
      if(req.method()==='GET'&&url.pathname.startsWith('/manager/assets/'))return route.fulfill({contentType:url.pathname.endsWith('.css')?'text/css':'application/javascript',body:fs.readFileSync(path.join(sourceRoot,url.pathname.slice(1)))});
      if(req.method()==='GET'&&url.pathname==='/favicon.ico')return route.fulfill({status:204,body:''});
      if(req.method()!=='POST'){unexpected.push(req.method()+' '+url.pathname);return route.abort()}
      const d=req.postDataJSON();calls.push({path:url.pathname,...d});
      if(url.pathname==='/manager/supervision-api.php'&&d.action==='identity')return json({ok:true,manager:{id:4,role:admin?'admin':'manager',display_name:'Руководитель'},csrf:'synthetic-csrf'});
      if(url.pathname==='/manager/api.php'&&d.action==='me')return json({ok:true,manager:{id:4,role:admin?'admin':'manager',display_name:'Руководитель'},csrf:'synthetic-csrf'});
      if(url.pathname!=='/manager/supervision-api.php'){unexpected.push(url.pathname);return json({ok:false,error:'unexpected'},400)}
      expect(d.csrf).toBe('synthetic-csrf');
      if(d.action==='scope')return json({ok:true,scopes:[scope],managers:[{id:9,name:'Ответственный менеджер'}]});
      if(d.action==='analytics'){
        if(mode==='wait')return;
        if(mode==='account')return json({...result(),viewer_id:9});
        if(mode==='denied')return json({ok:false,error:'forbidden'},403);
        if(mode==='error')return json({ok:false,error:'analytics_unavailable'},503);
        if(mode==='empty')return json({...result(),totals:Object.fromEntries(Object.keys(result().totals).map(k=>[k,0])),rows:[],by_manager:[],has_more:false,next_cursor:null});
        return json(result(d.cursor?50:0));
      }
      if(d.action==='directory')return json({ok:true,projects:[{id:1,display_name:'AnyTour'}],managers:[{id:4,project_id:1,login:'synthetic-manager',display_name:'Руководитель'}],sources:[{id:11,project_id:1,display_name:'Основной источник'}],grants:grant?[grant]:[]});
      if(d.action==='set'){grant={manager_id:d.manager_id,project_id:d.project_id,enabled:Number(d.enabled),include_unsourced:Number(d.include_unsourced),source_ids:d.source_ids,version:1};return json({ok:true,...grant})}
      unexpected.push(d.action);return json({ok:false,error:'unexpected'},400);
    });
    await page.goto(origin+'/manager2/analytics.php');await expect(page.locator('#analyticsStatus')).toHaveText('Готово.');return {calls,errors,unexpected,setMode:v=>mode=v};
  }
  for(const v of [{width:390,height:844},{width:430,height:844},{width:768,height:844},{width:1440,height:844},{width:390,height:420}])test('Supervisor analytics complete totals, readonly pages and responsive states '+v.width+'x'+v.height,async({page,browserName})=>{
    await page.setViewportSize(v);const s=await setup(page);await expect(page.locator('#analyticsTotals')).toContainText('206');await expect(page.locator('#analyticsRows .dialogue')).toHaveCount(50);await expect(page.locator('#analyticsLatency')).toContainText('N = 103');
    expect(await page.evaluate(()=>document.documentElement.scrollWidth-innerWidth)).toBeLessThanOrEqual(1);const dir=path.join(root,'manager2-artifacts');fs.mkdirSync(dir,{recursive:true});await page.screenshot({animations:'disabled',path:path.join(dir,'supervisor-analytics-'+browserName+'-'+v.width+'-'+v.height+'.png')});
    await page.locator('#analyticsMore').click();await expect(page.locator('#analyticsRows .dialogue')).toHaveCount(100);expect(s.calls.filter(c=>c.action==='analytics').at(-1).cursor).toBe(1156);await expect(page.locator('#analyticsRows a').first()).toHaveAttribute('href','/manager2/#conversation=1205');await expect(page.locator('#analyticsRows a').first()).toHaveAttribute('target','_blank');
    s.setMode('empty');await page.getByRole('button',{name:'Показать',exact:true}).click();await expect(page.locator('#analyticsStatus')).toContainText('данных нет');await expect(page.locator('#analyticsMore')).toBeHidden();
    s.setMode('error');await page.getByRole('button',{name:'Показать',exact:true}).click();await expect(page.locator('#analyticsStatus')).toContainText('Не удалось');await expect(page.locator('#analyticsReport')).toBeHidden();
    s.setMode('denied');await page.getByRole('button',{name:'Показать',exact:true}).click();await expect(page.locator('#analyticsStatus')).toContainText('отозван');await expect(page.locator('#analyticsApp')).toBeHidden();expect(s.calls.some(c=>['send','take','generate','validate','decision','set'].includes(c.action))).toBe(false);expect(s.errors).toEqual([]);expect(s.unexpected).toEqual([]);
  });
  test('Supervisor analytics never renders a different logged-in account response',async({page})=>{
    const s=await setup(page);s.setMode('account');await page.getByRole('button',{name:'Показать',exact:true}).click();await expect(page.locator('#analyticsApp')).toBeHidden();await expect(page.locator('#analyticsRows .dialogue')).toHaveCount(0);await expect(page.locator('#analyticsLogin')).toBeVisible();expect(s.errors).toEqual([]);expect(s.unexpected).toEqual([]);
  });
  test('Supervisor access command is explicit, exact account/source and readback before another write',async({page})=>{
    const s=await setup(page,{admin:true});await page.locator('#supervisorAdmin summary').click();await page.locator('#grantRead').click();await expect(page.locator('#grantIdentity')).toContainText('synthetic-manager · №4');await page.locator('#grantSources input').check();await page.locator('#grantEnabled').check();await page.locator('#grantSave').click();await expect(page.locator('#grantSave')).toBeDisabled();const sent=s.calls.filter(c=>c.action==='set');expect(sent).toHaveLength(1);expect(sent[0]).toMatchObject({manager_id:4,project_id:1,source_ids:[11],enabled:true,expected_version:0});await page.locator('#grantRead').click();await expect(page.locator('#grantIdentity')).toContainText('версия прав 1');await expect(page.locator('#grantSources input')).toBeChecked();await expect(page.locator('#grantEnabled')).toBeChecked();expect(s.errors).toEqual([]);expect(s.unexpected).toEqual([]);
  });
};
