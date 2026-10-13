const {test,expect}=require('@playwright/test');
const fs=require('node:fs');
const origin='http://127.0.0.1:4173';
for(const width of [390,430,768,1440])for(const timezoneId of ['UTC','Europe/Moscow','America/New_York'])test.describe(`Moscow clock ${width} ${timezoneId}`,()=>{
  test.use({viewport:{width,height:844},timezoneId,isMobile:width<768});
  test('one instant matches across inbox, transcript, stages and task editor',async({page,browserName})=>{
    const unexpected=[];
    await page.route('**/*',route=>{
      const r=route.request(),url=new URL(r.url());
      if(url.origin!==origin||r.method()!=='GET'||url.pathname.endsWith('.php')){unexpected.push(url.pathname);return route.abort()}
      return route.continue();
    });
    await page.clock.install({time:new Date('2026-10-13T14:50:00Z')});
    await page.goto(origin+'/tests/visual/workspace-v2-fixture.html?view=conversation');
    await page.evaluate(()=>{
      const by=(selector,id)=>document.querySelector(selector).id=id;
      by('.messages','messages');by('.conversationZone','conversationZone');by('.inboxList','inboxList');by('.leadCard','leadCard');
      by('.workspace','workspaceRoot');by('.leadZone','leadZone');by('.mobileLeadBtn','mobileLeadBtn');by('.mobileBack','mobileBack');by('.mobileLeadClose','mobileLeadClose');
      document.querySelector('#leadCard').innerHTML='<div id="timeStage"></div><div id="timeTasks"></div>';
      window.timeCalls=[];
      window.WorkspaceV2={S:{current:11,manager:{id:7},queue:'mine'},$:id=>document.getElementById(id),esc:v=>String(v??''),statusText:()=>'',outcomeText:()=>'',formatWait:()=>'',pipe:async(action,payload)=>{timeCalls.push({action,payload});return {ok:false}},api:async()=>{throw new Error('Unexpected API')}};
    });
    for(const asset of ['workspace-v2-conversation.js','workspace-v2-inbox.js','workspace-v2-stage-history.js','workspace-v2-tasks.js','workspace-v2-task-presets.js','workspace-v2-mobile.js'])await page.addScriptTag({url:origin+'/manager/assets/'+asset});
    await page.evaluate(()=>{
      WorkspaceV2Mobile.bind();WorkspaceV2Mobile.conversationOpened(11,{historyMode:'none'});
      const utc='2026-12-31 22:15:00';
      WorkspaceV2Conversation.renderMessages([{sender_type:'manager',text:'Синтетический пример времени',created_at:utc}]);
      WorkspaceV2Inbox.renderList([{id:11,display_name:'Пример',last_message_at:utc,last_text:'Синтетический пример времени',next_task_due_at_utc:utc,next_task_title:'Позвонить',lead_outcome:'open'}]);
      document.getElementById('timeStage').innerHTML=WorkspaceV2StageHistory.markup([{created_at:utc,from_stage_name:'Новый',to_stage_name:'В работе'}]);
      WorkspaceV2Tasks.render(document.getElementById('timeTasks'),{canEdit:true,conversationId:11,tasks:[{id:3,title:'Пример задачи',due_at_utc:utc,status:'open',due_state:'upcoming'}]});
      WorkspaceV2TaskPresets.enhanceAll(document.getElementById('timeTasks'));
    });
    await expect(page.locator('.leadActivityTime')).toHaveText('01.01 · 01:15');
    await expect(page.locator('.msgMeta')).toHaveText('01.01 · 01:15');
    await expect(page.locator('.msgMeta')).toHaveAttribute('title','01.01.2027 01:15 МСК');
    if(width<=900)await page.locator('#mobileLeadBtn').click();
    await expect(page.locator('#timeStage')).toContainText('01.01 01:15');
    await page.locator('.leadStageHistoryDetails summary').click();
    await expect(page.locator('.leadStageHistoryMeta')).toHaveText('01.01.2027 01:15');
    await expect(page.locator('.taskMeta')).toContainText('01.01 01:15');
    await page.getByRole('button',{name:'Изменить задачу',exact:true}).click();
    await expect(page.locator('[data-task-edit-due]')).toHaveValue('2027-01-01T01:15');
    await page.getByRole('button',{name:'Отмена',exact:true}).click();
    await page.locator('#leadTaskTitle').fill('Синтетический срок');
    await page.locator('#leadTaskDue').fill('2026-10-13T13:00');
    await page.getByRole('button',{name:'Добавить задачу',exact:true}).click();
    await expect(page.locator('#leadTaskCreateStatus')).toContainText('Не удалось добавить');
    expect(await page.evaluate(()=>timeCalls)).toEqual([{action:'create_task',payload:{conversation_id:11,title:'Синтетический срок',due_at:'2026-10-13T10:00:00.000Z'}}]);
    await page.locator('.taskCreate [data-due-preset="tomorrow"]').click();
    await expect(page.locator('#leadTaskDue')).toHaveValue('2026-10-14T10:00');
    await expect(page.locator('.taskCreate')).toContainText('МСК');
    const root=await page.evaluate(()=>({width:innerWidth,scroll:document.documentElement.scrollWidth}));
    expect(root.scroll).toBeLessThanOrEqual(root.width+1);expect(unexpected).toEqual([]);
    fs.mkdirSync('timezone-artifacts',{recursive:true});
    if(timezoneId==='UTC')await page.screenshot({path:`timezone-artifacts/${browserName}-${width}-moscow.png`});
  });
});
