const {test,expect}=require('@playwright/test');
const fs=require('node:fs'),path=require('node:path');
const {setup,root,emptyState}=require('./fixtures/personal-workspace');

// Actual PHP shell and source assets; all customers, preferences and HTTP
// responses are synthetic. No forced click or programmatic conversation open.
const lists=s=>s.calls.filter(c=>c.action==='list');
function stateFor(tabs,labels,font='normal',density='comfortable',long=false){
 const state=emptyState();
 state.tabs=Array.from({length:tabs},(_,i)=>({id:10+i,name:long?'Очень длинное название личной подборки для семейного отдыха '+i:'Море'+(i?' '+i:''),queue:'mine',channel:'',source_id:0,label_id:0,reply_needed:false,pinned_only:false}));
 state.labels=Array.from({length:labels},(_,i)=>({id:100+i,name:'Личная метка для семейного отдыха и пожеланий '+i}));
 state.labels.forEach(l=>state.members[l.id]=[11]);state.settings.font=font;state.settings.density=density;return state;
}
async function settled(page,s){await expect.poll(()=>s.pending.size).toBe(0);await expect.poll(()=>page.evaluate(()=>!!WorkspaceV2.S.workspaceBound&&!WorkspaceV2.S.booting)).toBe(true);await expect(page.locator('#pwOpen')).toBeVisible()}
function clean(s){expect(s.unexpected).toEqual([]);expect(s.errors).toEqual([]);expect(s.calls.filter(c=>['send','tab_save','label_save','settings','set_stage','set_outcome','create_task','update_task'].includes(c.action))).toEqual([])}
async function capture(page,browser,width,height,scenario,stage){
 const dir=path.join(root,'manager2-artifacts');fs.mkdirSync(dir,{recursive:true});
 await page.screenshot({animations:'disabled',path:path.join(dir,`personal-capacity-${process.env.M2_CAPACITY_STAGE||'after'}-${browser}-${width}x${height}-${scenario}-${stage}.png`)});
}
async function geometry(page){return page.evaluate(()=>{
 const box=document.querySelector('#inboxList'),item=box.querySelector('.leadItem'),lane=document.querySelector('.pwLane'),markers=item?.querySelector('.pwRowMarkers');
 const b=box.getBoundingClientRect(),r=item?.getBoundingClientRect(),l=lane?.getBoundingClientRect(),m=markers?.getBoundingClientRect();
 return {listHeight:b.height,listTop:b.top,listBottom:b.bottom,rowHeight:r?.height||0,laneHeight:l?.height||0,markerHeight:m?.height||0,visibleRow:r?Math.max(0,Math.min(b.bottom,r.bottom,innerHeight)-Math.max(b.top,r.top,0)):0,overflow:document.documentElement.scrollWidth-innerWidth};
})}
async function capacity(page){
 const g=await geometry(page);console.log('CAPACITY '+JSON.stringify(g));
 expect(g.overflow).toBeLessThanOrEqual(1);expect(g.listHeight).toBeGreaterThanOrEqual(80);expect(g.visibleRow).toBeGreaterThanOrEqual(48);return g;
}
async function keyboardTo(page,selector,{beforeSearch=false}={}){
 // Tabs precede search in document order; use real Shift+Tab for them. A
 // forward full-page wrap would leave/reenter browser chrome, generating a
 // genuine focus-triggered Inbox refresh unrelated to selecting this tab.
 // Client rows follow search and are reached with ordinary forward Tab.
 await page.locator('#leadSearch').click();
 for(let i=0;i<70;i++){
  await page.keyboard.press(beforeSearch?'Shift+Tab':'Tab');
  if(await page.locator(selector).evaluate(el=>document.activeElement===el))return;
 }
 throw new Error('Keyboard cannot reach '+selector);
}
async function select(page,s,tabs,keyboard=false){
 if(!tabs)return;
 const selector=`[data-pw-selection="tab:${10+tabs-1}"]`,before=lists(s).length;
 if(keyboard){await keyboardTo(page,selector,{beforeSearch:true});await expect(page.locator(selector)).toBeFocused();await expect(page.locator(selector)).toBeInViewport();await page.keyboard.press('Enter')}
 else await page.locator(selector).click();
 await expect.poll(()=>lists(s).length).toBe(before+1);await settled(page,s);
 expect(lists(s).at(-1).path).toBe('/manager/personal-workspace-api.php');expect(lists(s).at(-1).selection).toBe('tab:'+(10+tabs-1));
}
async function openClient(page,keyboard=false,id=11){
 const selector=`#inboxList [data-conversation-id="${id}"]`;
 if(keyboard){await keyboardTo(page,selector);await page.keyboard.press('Enter')}
 else await page.locator(selector+' .leadTitle').click();
 await expect(page.locator('#replyText')).toBeEnabled();await expect(page.locator('#conversationTitle')).toHaveText(id===11?'Анна Морозова':'Алексей Иванов');
 await expect(page.locator('#replyText')).toBeInViewport();await expect(page.locator('#sendReply')).toBeInViewport();
 await expect.poll(()=>page.locator('#replyText').evaluate(el=>{const r=el.getBoundingClientRect();return r.left>=-1&&r.right<=innerWidth+1})).toBe(true);
}
async function back(page){if(await page.locator('#mobileBack').isVisible()){await page.locator('#mobileBack').click();await expect(page.locator('#inboxList .leadTitle').first()).toBeVisible()}}

for(const width of [390,430,768,1440])test('short capacity minimal single tab '+width,async({page,browserName})=>{
 if(process.env.M2_CAPACITY_BASE_ROOT){
  const baseline=await page.context().newPage();try{
   await baseline.setViewportSize({width,height:420});const old=await setup(baseline,{state:stateFor(1,0),sourceRoot:process.env.M2_CAPACITY_BASE_ROOT});await settled(baseline,old);await select(baseline,old,1);
   const g=await geometry(baseline);console.log('BASELINE '+width+' '+JSON.stringify(g));
   await capture(baseline,browserName,width,420,'one-short-tab','baseline-selected');
   expect(g.visibleRow).toBeLessThan(48);let pointerOpened=false;
   try{await baseline.locator('#inboxList [data-conversation-id="11"] .leadTitle').click({timeout:500});pointerOpened=true}catch(e){if(!/Timeout/.test(e.message))throw e}
   expect(pointerOpened,'An ordinary pointer cannot open the collapsed baseline list').toBe(false);clean(old);
  }finally{await baseline.close()}
 }
 await page.setViewportSize({width,height:420});const s=await setup(page,{state:stateFor(1,0),...(process.env.M2_CAPACITY_SOURCE?{sourceRoot:process.env.M2_CAPACITY_SOURCE}:{})});await settled(page,s);
 await select(page,s,1);await capture(page,browserName,width,420,'one-short-tab','selected');await capacity(page);
 await openClient(page);await page.locator('#replyText').fill('Сохранённый ответ на коротком экране');await back(page);await capacity(page);await openClient(page,true);await expect(page.locator('#replyText')).toHaveValue('Сохранённый ответ на коротком экране');clean(s);
});

const profiles=[{tabs:0,labels:0,long:false},{tabs:1,labels:30,long:true},{tabs:12,labels:30,long:true}];
for(const width of [390,430,768,1440])for(const height of [420,900])for(const profile of profiles)for(const large of [false,true]){
 const {tabs,labels,long}=profile,font=large?'large':'normal',density=large?'compact':'comfortable',scenario=`tabs${tabs}-labels${labels}-${font}-${density}`;
 test(`capacity ${width}x${height} ${scenario}`,async({page,browserName})=>{
  await page.setViewportSize({width,height});const s=await setup(page,{state:stateFor(tabs,labels,font,density,long),...(process.env.M2_CAPACITY_SOURCE?{sourceRoot:process.env.M2_CAPACITY_SOURCE}:{})});await settled(page,s);expect(lists(s)).toHaveLength(1);
  await select(page,s,tabs,true);await capture(page,browserName,width,height,scenario,'inbox');const g=await capacity(page);
  if(labels){expect(g.markerHeight).toBeLessThanOrEqual(48);expect(g.rowHeight).toBeLessThanOrEqual(230);await expect(page.locator('#inboxList [data-conversation-id="11"] .pwRowMarkers')).toContainText(/метк|\+|ещё/)}
  // A normal pointer opens the row after real keyboard tab selection, then
  // normal Back + keyboard activation returns to the same draft and files.
  await openClient(page);await page.locator('#replyText').fill('Личный ответ — сохранить точно');
  await page.locator('#replyFile').setInputFiles({name:'capacity.txt',mimeType:'text/plain',buffer:Buffer.from('Synthetic attachment')});const attachment=await page.locator('#mediaSelection').textContent();
  await page.locator('#replyText').click();await expect(page.locator('#replyText')).toBeFocused();await expect(page.locator('#sendReply')).toBeInViewport();
  await capture(page,browserName,width,height,scenario,'reply');await back(page);await capacity(page);await openClient(page,true);
  await expect(page.locator('#replyText')).toHaveValue('Личный ответ — сохранить точно');await expect(page.locator('#mediaSelection')).toHaveText(attachment);
  expect(lists(s)).toHaveLength(tabs?2:1);expect(s.calls.filter(c=>c.path==='/manager/personal-workspace-api.php'&&c.action==='snapshot')).toHaveLength(1);clean(s);
 });
}

for(const width of [390,1440])test('all thirty labels remain readable in their own editor '+width,async({page,browserName})=>{
 await page.setViewportSize({width,height:420});const s=await setup(page,{state:stateFor(12,30,'large','compact',true)});await settled(page,s);await page.locator('#pwOpen').click();await expect(page.locator('#pwDialog')).toBeVisible();await expect(page.locator('[data-pw-edit-label]')).toHaveCount(30);
 const last=page.locator('[data-pw-edit-label="129"]');await last.scrollIntoViewIfNeeded();await expect(last).toBeInViewport();await expect(page.locator('#pwLabels')).toContainText('Личная метка для семейного отдыха и пожеланий 29');
 await last.click();await expect(page.locator('#pwLabelName')).toHaveValue('Личная метка для семейного отдыха и пожеланий 29');await expect(page.locator('#pwLabelName')).toBeFocused();await page.locator('#pwLabelCancel').click();await expect(page.locator('#pwNewLabel')).toBeFocused();await page.keyboard.press('Escape');await expect(page.locator('#pwDialog')).toBeHidden();await expect(page.locator('#pwOpen')).toBeFocused();await capture(page,browserName,width,420,'all-labels','closed');await capacity(page);clean(s);
});

for(const width of [390,430,768,1440])test('failed personal refresh keeps a usable short list and retries only that selection '+width,async({page,browserName})=>{
 await page.setViewportSize({width,height:420});const s=await setup(page,{state:stateFor(1,0)});await settled(page,s);await select(page,s,1);
 await expect(page.locator('#pwListScope')).toContainText('Загружено 2');s.setListError(true);
 // Selecting the already-selected tab is an ordinary explicit same-scope
 // refresh. Recovery uses the real status retry button, not a direct API call.
 await page.locator('[data-pw-selection="tab:10"]').click();await expect(page.locator('#inboxLoadStatus')).toContainText('Не удалось обновить');await settled(page,s);
 await expect(page.locator('#inboxList')).toHaveAttribute('data-stale','true');await expect(page.locator('#pwListScope')).toHaveCount(0);await expect(page.locator('#inboxList .leadItem')).toHaveCount(2);
 await expect(page.locator('#inboxLoadStatus button')).toBeInViewport();await capture(page,browserName,width,420,'error-one-tab','stale');await capacity(page);
 await openClient(page);await page.locator('#replyText').fill('Ответ остаётся при ошибке списка');await back(page);s.setListError(false);await page.locator('#inboxLoadStatus button').click();await expect(page.locator('#inboxLoadStatus')).toBeHidden();await settled(page,s);
 await expect(page.locator('#inboxList')).not.toHaveAttribute('data-stale','true');await expect(page.locator('#pwListScope')).toContainText('Загружено 2');await capacity(page);await openClient(page,true);await expect(page.locator('#replyText')).toHaveValue('Ответ остаётся при ошибке списка');
 const scoped=lists(s).slice(1);expect(scoped).toHaveLength(3);expect(scoped.every(c=>c.path==='/manager/personal-workspace-api.php'&&c.selection==='tab:10')).toBe(true);clean(s);
});

for(const width of [390,1440])test('ordinary Inbox re-entry retains only the matching client file and draft '+width,async({page})=>{
 await page.setViewportSize({width,height:420});const s=await setup(page,{state:stateFor(1,0)});await settled(page,s);await select(page,s,1);await openClient(page);
 await page.locator('#replyText').fill('Только для первого клиента');await page.locator('#replyFile').setInputFiles({name:'first-client.txt',mimeType:'text/plain',buffer:Buffer.from('First synthetic client only')});const firstFile=await page.locator('#mediaSelection').textContent();await expect(page.locator('#mediaSelection')).toContainText('first-client.txt');
 await back(page);await openClient(page,true);await expect(page.locator('#replyText')).toHaveValue('Только для первого клиента');await expect(page.locator('#mediaSelection')).toHaveText(firstFile);
 await back(page);await openClient(page,false,12);await expect(page.locator('#replyText')).toHaveValue('');await expect(page.locator('#mediaSelection')).not.toContainText('first-client.txt');await page.locator('#replyText').fill('Только для второго клиента');
 await back(page);await openClient(page,true);await expect(page.locator('#replyText')).toHaveValue('Только для первого клиента');await expect(page.locator('#mediaSelection')).toHaveText(firstFile);
 expect(lists(s)).toHaveLength(2);clean(s);
});
