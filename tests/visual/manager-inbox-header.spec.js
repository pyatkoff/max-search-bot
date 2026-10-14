const {test,expect}=require('@playwright/test');
const fs=require('node:fs'),path=require('node:path');
const {setup,root,emptyState}=require('./fixtures/personal-workspace');

// Render both real PHP presentations with real source assets. All accounts,
// customers, permissions, notifications and HTTP responses are synthetic.
const listCalls=s=>s.calls.filter(c=>c.action==='list');
const snapshots=s=>s.calls.filter(c=>c.path==='/manager/personal-workspace-api.php'&&c.action==='snapshot');
const writes=new Set(['send','set_working','subscribe','tab_save','tab_delete','tab_reorder','label_save','label_delete','label_membership','pin','settings','set_stage','set_outcome','create_task','update_task']);
function state(){const s=emptyState();s.tabs=[{id:10,name:'Море',queue:'mine',channel:'',source_id:0,label_id:0,reply_needed:false,pinned_only:false}];return s}
async function settled(page,s){
 await expect.poll(()=>s.pending.size).toBe(0);
 await expect.poll(()=>page.evaluate(()=>!!WorkspaceV2.S.workspaceBound&&!WorkspaceV2.S.booting)).toBe(true);
 await expect(page.locator('#pwOpen')).toBeVisible();
}
function clean(s){expect(s.errors).toEqual([]);expect(s.unexpected).toEqual([]);expect(s.calls.filter(c=>writes.has(c.action))).toEqual([]);expect(s.calls.filter(c=>c.path==='/manager/push.php')).toEqual([])}
async function screenshot(page,browser,presentation,width,height,stage){
 const dir=path.join(root,'manager2-artifacts');fs.mkdirSync(dir,{recursive:true});
 await page.screenshot({animations:'disabled',path:path.join(dir,`inbox-header-${presentation}-${browser}-${width}x${height}-${stage}.png`)});
}
async function geometry(page){return page.evaluate(()=>{
 const box=el=>{const r=el.getBoundingClientRect();return {left:r.left,right:r.right,top:r.top,bottom:r.bottom,width:r.width,height:r.height}};
 const head=document.querySelector('.inboxHead'),list=document.querySelector('#inboxList'),zone=document.querySelector('.inboxZone'),admin=document.querySelector('#adminLink');
 return {header:box(head),list:box(list),zone:box(zone),admin:box(admin),overflow:document.documentElement.scrollWidth-innerWidth,scrollTop:list.scrollTop};
})}
async function fullyInHeader(page,selector){
 const bounds=await page.locator(selector).evaluate(el=>{
  const r=el.getBoundingClientRect(),h=document.querySelector('.inboxHead').getBoundingClientRect();
  return {left:r.left,right:r.right,top:r.top,bottom:r.bottom,headLeft:h.left,headRight:h.right,headTop:h.top,headBottom:h.bottom,width:r.width,height:r.height};
 });
 expect(bounds.width,selector+' has width').toBeGreaterThan(0);expect(bounds.height,selector+' has height').toBeGreaterThan(0);
 expect(bounds.left,selector+' left edge').toBeGreaterThanOrEqual(bounds.headLeft-1);expect(bounds.right,selector+' right edge').toBeLessThanOrEqual(bounds.headRight+1);
 expect(bounds.top,selector+' top edge').toBeGreaterThanOrEqual(bounds.headTop-1);expect(bounds.bottom,selector+' bottom edge').toBeLessThanOrEqual(bounds.headBottom+1);
 await expect(page.locator(selector)).toBeInViewport();
}
async function primaryControls(page,presentation,height){
 const g=await geometry(page);console.log('INBOX_HEADER '+presentation+' '+JSON.stringify(g));
 expect(g.overflow).toBeLessThanOrEqual(1);
 expect(g.header.height).toBeLessThanOrEqual(presentation==='manager'?230:205);
 // Small mobile Manager2 includes a separate 52px topbar and 62px rail.
 // Require an operable list as well as fully exposed queues and search;
 // a large list obtained by clipping the search is not an acceptable fix.
 expect(g.list.height).toBeGreaterThanOrEqual(height===420?(g.zone.height<350?100:180):350);
 if(height===900)expect(g.list.height/g.zone.height).toBeGreaterThan(.6);
 for(const selector of ['.queueTabs [data-q="waiting"]','.queueTabs [data-q="mine"]','.queueTabs [data-q="all"]','.queueTabs [data-q="closed"]','#leadSearch','#filtersToggle','#pwOpen'])await fullyInHeader(page,selector);
 for(const queue of ['waiting','mine']){
  const badge=await page.locator(`.queueTabs [data-q="${queue}"] .queueCount`).evaluate(el=>{
   const b=el.getBoundingClientRect(),p=el.parentElement.getBoundingClientRect();return {position:getComputedStyle(el).position,left:b.left,right:b.right,top:b.top,bottom:b.bottom,parentLeft:p.left,parentRight:p.right,parentTop:p.top,parentBottom:p.bottom};
  });
  expect(badge.position).toBe('static');expect(badge.left).toBeGreaterThanOrEqual(badge.parentLeft-1);expect(badge.right).toBeLessThanOrEqual(badge.parentRight+1);expect(badge.top).toBeGreaterThanOrEqual(badge.parentTop-1);expect(badge.bottom).toBeLessThanOrEqual(badge.parentBottom+1);
 }
 return g;
}
async function keyboardTo(page,selector,{backward=false}={}){
 await page.locator('#leadSearch').click();
 for(let i=0;i<80;i++){
  await page.keyboard.press(backward?'Shift+Tab':'Tab');
  if(await page.locator(selector).evaluate(el=>document.activeElement===el)){await expect(page.locator(selector)).toBeInViewport();return}
 }
 throw new Error('Real keyboard navigation cannot reach '+selector);
}
function settingsToggle(presentation){return presentation==='manager'?'#inboxWorkSettings > summary':'#m2NotificationButton'}
async function openSettings(page,presentation,keyboard=false){
 const toggle=settingsToggle(presentation);
 if(keyboard){await keyboardTo(page,toggle,{backward:true});await page.keyboard.press('Enter')}else await page.locator(toggle).click();
 if(presentation==='manager')await expect(page.locator('#inboxWorkSettings')).toHaveAttribute('open','');
 else await expect(page.locator('#m2Notifications')).toBeVisible();
 await expect(page.locator('#notificationStatus')).toContainText('Уведомления включены');
}
async function closeSettings(page,presentation,keyboard=false){
 if(presentation==='manager'){
  const toggle=page.locator(settingsToggle(presentation));
  if(keyboard){await expect(toggle).toBeFocused();await page.keyboard.press('Enter')}else await toggle.click();
  await expect(page.locator('#inboxWorkSettings')).not.toHaveAttribute('open','');
 }else{
  if(keyboard)await page.keyboard.press('Escape');else await page.locator('#m2NotificationsClose').click();
  await expect(page.locator('#m2Notifications')).toBeHidden();await expect(page.locator('#m2NotificationButton')).toBeFocused();
 }
}
async function openClient(page,id=11,keyboard=false){
 const selector=`#inboxList [data-conversation-id="${id}"]`;
 if(keyboard){await keyboardTo(page,selector);await page.keyboard.press('Enter')}else await page.locator(selector+' .leadTitle').click();
 await expect(page.locator('#conversationTitle')).toHaveText(id===11?'Анна Морозова':'Алексей Иванов');await expect(page.locator('#replyText')).toBeEnabled();
 await expect(page.locator('#replyText')).toBeInViewport();await expect(page.locator('#sendReply')).toBeInViewport();
 await page.locator('#replyText').click();await expect(page.locator('#replyText')).toBeFocused();
}
async function back(page){
 if(await page.locator('#mobileBack').isVisible()){
  await page.locator('#mobileBack').click();await expect(page.locator('#workspaceRoot')).toHaveAttribute('data-mobile-screen','inbox');
 }
}

for(const presentation of ['manager','manager2'])test('paired pre-fix header evidence '+presentation,async({page,browserName})=>{
 test.skip(!process.env.M2_HEADER_BASE_ROOT,'Exact retained pre-fix source root is supplied by CI.');
 await page.setViewportSize({width:1440,height:900});
 const before=await setup(page,{presentation,managerRole:'admin',healthyPush:true,state:state(),sourceRoot:process.env.M2_HEADER_BASE_ROOT});await settled(page,before);
 await expect(page.locator('#notificationStatus')).toContainText('Уведомления включены');
 const old=await geometry(page);console.log('INBOX_HEADER_BEFORE '+presentation+' '+JSON.stringify(old));
 expect(old.header.height).toBeGreaterThan(presentation==='manager'?230:205);
 if(presentation==='manager'){
  expect(old.header.height/old.zone.height).toBeGreaterThan(.4);
  // The supplied screenshot has a normally scrolled card. Reproduce the
  // permanent settings occupation and clipped admin action, not card scroll.
  expect(old.admin.right).toBeGreaterThan(old.header.right+1);
 }
 await screenshot(page,browserName,presentation,1440,900,'before');clean(before);
});

for(const presentation of ['manager','manager2'])for(const width of [390,430,768,1440])for(const height of [420,900]){
 test(`${presentation} ${width}x${height} header stays compact and settings remain operable`,async({page,browserName})=>{
  await page.setViewportSize({width,height});const s=await setup(page,{presentation,managerRole:'admin',healthyPush:true,state:state()});await settled(page,s);
  expect(listCalls(s)).toHaveLength(1);expect(snapshots(s)).toHaveLength(1);
  if(presentation==='manager')await expect(page.locator('#inboxWorkSettings')).not.toHaveAttribute('open','');
  else await expect(page.locator('#m2Notifications')).toBeHidden();
  await primaryControls(page,presentation,height);await screenshot(page,browserName,presentation,width,height,'closed');
  // Wide Manager2 keeps admin access in the rail. A modal intentionally makes
  // the rail inert, so exercise its bound link before opening notifications.
  if(presentation==='manager2'&&width>740){await expect(page.locator('#adminLink')).toBeInViewport();await page.locator('#adminLink').click({trial:true})}
  const callsBefore=s.calls.filter(c=>c.path!=='/manager/customer-avatar.php'&&c.path!=='/manager/notification-events.php').length;
  await openSettings(page,presentation,true);
  for(const selector of ['#soundToggle','#soundTest']){
   await page.locator(selector).scrollIntoViewIfNeeded();await expect(page.locator(selector)).toBeInViewport();await page.locator(selector).click({trial:true});
   if(presentation==='manager')await fullyInHeader(page,selector);
  }
  // Admin action must remain horizontally reachable; the initial screenshot
  // cut this exact long bound link at the right edge of the legacy column.
  if(presentation==='manager'||width<=740){
   await page.locator('#adminLink').scrollIntoViewIfNeeded();await expect(page.locator('#adminLink')).toBeInViewport();await page.locator('#adminLink').click({trial:true});
   if(presentation==='manager')await fullyInHeader(page,'#adminLink');
  }
  await screenshot(page,browserName,presentation,width,height,'settings');await closeSettings(page,presentation,true);await settled(page,s);
  expect(s.calls.filter(c=>c.path!=='/manager/customer-avatar.php'&&c.path!=='/manager/notification-events.php')).toHaveLength(callsBefore);
  await primaryControls(page,presentation,height);
  await keyboardTo(page,'#filtersToggle');await page.keyboard.press('Enter');
  await expect(page.locator('#filtersToggle')).toHaveAttribute('aria-expanded','true');await expect(page.locator('#filtersPanel .taskShortcuts')).toBeVisible();
  await page.locator('.taskShortcut[data-task-filter="today"]').click({trial:true});
  await page.locator('#filtersToggle').click();await expect(page.locator('.taskShortcuts')).toBeHidden();
  expect(listCalls(s)).toHaveLength(1);expect(snapshots(s)).toHaveLength(1);await primaryControls(page,presentation,height);clean(s);
 });

 test(`${presentation} ${width}x${height} queues search and personal tabs preserve the reply context`,async({page,browserName})=>{
  await page.setViewportSize({width,height});const s=await setup(page,{presentation,managerRole:'admin',healthyPush:true,state:state()});await settled(page,s);
  await page.locator('.queueTabs [data-q="mine"]').click();await expect.poll(()=>listCalls(s).length).toBe(2);await settled(page,s);expect(listCalls(s).at(-1).queue).toBe('mine');
  await keyboardTo(page,'[data-pw-selection="tab:10"]',{backward:true});await page.keyboard.press('Enter');await expect.poll(()=>listCalls(s).length).toBe(3);await settled(page,s);
  expect(listCalls(s).at(-1).path).toBe('/manager/personal-workspace-api.php');expect(listCalls(s).at(-1).selection).toBe('tab:10');
  await page.locator('#leadSearch').fill('Анна');await expect.poll(()=>listCalls(s).length).toBe(4);await settled(page,s);expect(listCalls(s).at(-1).search).toBe('Анна');expect(listCalls(s).at(-1).selection).toBe('tab:10');
  await openClient(page);await page.locator('#replyText').fill('Ответ только Анне — не отправлять');
  await page.locator('#replyFile').setInputFiles({name:'header-context.txt',mimeType:'text/plain',buffer:Buffer.from('Synthetic unsent client attachment')});
  const media=await page.locator('#mediaSelection').textContent();await expect(page.locator('#mediaSelection')).toContainText('header-context.txt');await screenshot(page,browserName,presentation,width,height,'reply');
  await back(page);await page.locator('[data-pw-selection="tab:10"]').click();await expect.poll(()=>listCalls(s).length).toBe(5);await settled(page,s);
  await openSettings(page,presentation);await closeSettings(page,presentation);await openClient(page,11,true);
  await expect(page.locator('#replyText')).toHaveValue('Ответ только Анне — не отправлять');await expect(page.locator('#mediaSelection')).toHaveText(media);
  await back(page);await openClient(page,12);await expect(page.locator('#replyText')).toHaveValue('');await expect(page.locator('#mediaSelection')).not.toContainText('header-context.txt');
  await back(page);await openClient(page,11,true);await expect(page.locator('#replyText')).toHaveValue('Ответ только Анне — не отправлять');await expect(page.locator('#mediaSelection')).toHaveText(media);
  expect(listCalls(s)).toHaveLength(5);expect(snapshots(s)).toHaveLength(1);clean(s);
 });
}

for(const presentation of ['manager','manager2'])test(presentation+' settings preserve manager permissions and unavailable notification recovery',async({page})=>{
 await page.setViewportSize({width:390,height:420});const s=await setup(page,{presentation,state:state()});await settled(page,s);
 const toggle=page.locator(settingsToggle(presentation));await keyboardTo(page,settingsToggle(presentation),{backward:true});await page.keyboard.press('Enter');
 await expect(page.locator('#adminLink')).toBeHidden();await expect(page.locator('#notificationStatus')).toContainText('Смена без уведомлений');
 const recovery=page.locator('#notificationStatus [data-enable-push]');await recovery.scrollIntoViewIfNeeded();await expect(recovery).toBeInViewport();await recovery.click({trial:true});
 await closeSettings(page,presentation,true);if(presentation==='manager')await expect(toggle).toBeFocused();await primaryControls(page,presentation,420);
 expect(listCalls(s)).toHaveLength(1);expect(snapshots(s)).toHaveLength(1);clean(s);
});

for(const presentation of ['manager','manager2'])for(const width of [390,1440])test(`${presentation} ${width}px moved quick filters retain canonical behavior`,async({page})=>{
 await page.setViewportSize({width,height:900});const s=await setup(page,{presentation,managerRole:'admin',healthyPush:true,state:state()});await settled(page,s);
 await page.locator('#filtersToggle').click();await expect(page.locator('#filtersPanel .taskShortcuts')).toBeVisible();expect(listCalls(s)).toHaveLength(1);
 await page.locator('.taskShortcut[data-task-filter="today"]').click();await expect.poll(()=>listCalls(s).length).toBe(2);await settled(page,s);
 expect(listCalls(s).at(-1).lead_task_filter).toBe('today');expect(listCalls(s).at(-1).queue).toBe('mine');await expect(page.locator('#leadTaskFilter')).toHaveValue('today');await expect(page.locator('.taskShortcut[data-task-filter="today"]')).toHaveAttribute('aria-pressed','true');
 await page.locator('#filtersToggle').click();await expect(page.locator('#filtersPanel')).toBeHidden();await expect(page.locator('#filtersCount')).toHaveText('1');expect(listCalls(s)).toHaveLength(2);
 await page.locator('#filtersToggle').click();await expect(page.locator('.taskShortcut[data-task-filter="today"]')).toHaveAttribute('aria-pressed','true');expect(listCalls(s)).toHaveLength(2);
 await page.locator('.taskShortcut[data-task-filter="today"]').click();await expect.poll(()=>listCalls(s).length).toBe(3);await settled(page,s);expect(listCalls(s).at(-1).lead_task_filter).toBe('');
 await page.locator('.sourceShortcut[data-source-filter="-1"]').click();await expect.poll(()=>listCalls(s).length).toBe(4);await settled(page,s);expect(listCalls(s).at(-1).source_id).toBe(-1);await expect(page.locator('#leadSourceFilter')).toHaveValue('-1');
 await page.locator('#clearFilters').click();await expect.poll(()=>listCalls(s).length).toBe(5);await settled(page,s);expect(listCalls(s).at(-1).source_id).toBe(0);expect(listCalls(s).at(-1).lead_task_filter).toBe('');
 await page.locator('#filtersToggle').click();await expect(page.locator('#filtersPanel')).toBeHidden();expect(listCalls(s)).toHaveLength(5);expect(snapshots(s)).toHaveLength(1);clean(s);
});

for(const presentation of ['manager','manager2'])for(const width of [390,1440])test(`${presentation} ${width}px short expanded filters keep stale-list recovery and the unsent reply reachable`,async({page,browserName})=>{
 await page.setViewportSize({width,height:420});const s=await setup(page,{presentation,managerRole:'admin',healthyPush:true,state:state()});await settled(page,s);
 await page.locator('[data-pw-selection="tab:10"]').click();await expect.poll(()=>listCalls(s).length).toBe(2);await settled(page,s);
 await openClient(page);await page.locator('#replyText').fill('Ответ сохраняется при ошибке списка');await page.locator('#replyFile').setInputFiles({name:'header-recovery.txt',mimeType:'text/plain',buffer:Buffer.from('Synthetic unsent recovery attachment')});
 const media=await page.locator('#mediaSelection').textContent();await expect(page.locator('#mediaSelection')).toContainText('header-recovery.txt');await back(page);
 const usableList=async()=>{
  const g=await geometry(page);console.log('INBOX_HEADER_RECOVERY '+presentation+' '+width+' '+JSON.stringify(g));
  expect(g.overflow).toBeLessThanOrEqual(1);expect(g.list.height).toBeGreaterThanOrEqual(80);expect(g.list.bottom).toBeLessThanOrEqual(g.zone.bottom+1);expect(g.list.bottom).toBeLessThanOrEqual(421);
  await expect(page.locator('#inboxList .leadItem')).toHaveCount(2);
 };
 // Opening the real filters before the failure exercises both independent
 // header and list-status siblings when a late list refresh fails.
 await page.locator('#filtersToggle').click();await expect(page.locator('#filtersPanel')).toBeVisible();expect(listCalls(s)).toHaveLength(2);await usableList();
 s.setListError(true);await page.locator('[data-pw-selection="tab:10"]').click();await expect.poll(()=>listCalls(s).length).toBe(3);await settled(page,s);
 await expect(page.locator('#inboxLoadStatus')).toContainText('Не удалось обновить');await expect(page.locator('#inboxList')).toHaveAttribute('data-stale','true');await usableList();
 await page.locator('#filtersToggle').click();await expect(page.locator('#filtersPanel')).toBeHidden();await usableList();expect(listCalls(s)).toHaveLength(3);
 // Reopening after the failure must still leave real retry and list space,
 // without making another list or preference request merely to unfold UI.
 await page.locator('#filtersToggle').click();await expect(page.locator('#filtersPanel')).toBeVisible();await usableList();expect(listCalls(s)).toHaveLength(3);
 const retry=page.locator('#inboxLoadStatus button');await expect(retry).toBeInViewport();await screenshot(page,browserName,presentation,width,420,'failed-list-expanded-filters');
 s.setListError(false);await retry.click();await expect.poll(()=>listCalls(s).length).toBe(4);await expect(page.locator('#inboxLoadStatus')).toBeHidden();await settled(page,s);await expect(page.locator('#inboxList')).not.toHaveAttribute('data-stale','true');await usableList();
 await page.locator('#filtersToggle').click();await expect(page.locator('#filtersPanel')).toBeHidden();await openClient(page,11,true);await expect(page.locator('#replyText')).toHaveValue('Ответ сохраняется при ошибке списка');await expect(page.locator('#mediaSelection')).toHaveText(media);
 expect(listCalls(s)).toHaveLength(4);expect(listCalls(s).slice(1).every(c=>c.path==='/manager/personal-workspace-api.php'&&c.selection==='tab:10')).toBe(true);expect(snapshots(s)).toHaveLength(1);clean(s);
});
