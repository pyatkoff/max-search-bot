// Diagnostic branch only: two original cases without event tracing; product source unchanged.
const {test,expect}=require('@playwright/test');
const fs=require('node:fs'),path=require('node:path');
const {setup,root,emptyState}=require('./fixtures/personal-workspace');

const personal=s=>s.calls.filter(c=>c.path==='/manager/personal-workspace-api.php');
const writes=s=>personal(s).filter(c=>!['snapshot','list','operation'].includes(c.action));
const details=s=>s.calls.filter(c=>c.action==='detail');
const lists=s=>s.calls.filter(c=>c.action==='list');
const group=(id=10,label=20,name='Подбор')=>({id,name,kind:'group',group_label_id:label,label_id:label,label_ids:[label],queue:'all',queues:['all','closed'],channel:'',source_id:0,reply_needed:false,pinned_only:false});
function stateWithGroup(members=[]){const state=emptyState();state.tabs=[group()];state.labels=[{id:20,name:'Подбор'}];state.members=members.length?{'20':members}:{};return state}
function clean(s){expect(s.unexpected).toEqual([]);expect(s.errors).toEqual([]);expect(s.calls.filter(c=>['send','set_stage','set_outcome','create_task','update_task','set_working','assign'].includes(c.action))).toEqual([])}
async function settled(page,s){await expect.poll(()=>s.pending.size).toBe(0);await expect.poll(()=>page.evaluate(()=>!!WorkspaceV2.S.workspaceBound&&!WorkspaceV2.S.booting)).toBe(true)}
async function showGroupControl(page){if(!await page.locator('#pwGroupOpen').isVisible())await page.locator('#m2ConversationMenuToggle').click();}
async function openLead(page,id=11){await expect.poll(()=>page.evaluate(()=>!!WorkspaceV2.S.workspaceBound&&!WorkspaceV2.S.booting)).toBe(true);if(await page.evaluate(()=>WorkspaceV2Mobile.isMobile()&&WorkspaceV2Mobile.getScreen()!=='inbox'))await page.locator('#mobileBack').click();await page.locator(`#inboxList [data-conversation-id="${id}"] .m2RowOpen`).click();await expect(page.locator('#replyText')).toBeEnabled()}
async function inbox(page){if(await page.evaluate(()=>WorkspaceV2Mobile.isMobile()&&WorkspaceV2Mobile.getScreen()!=='inbox'))await page.locator('#mobileBack').click()}
async function close(page){await page.locator('#pwClose').click();await expect(page.locator('#pwDialog')).toBeHidden()}
async function createGroup(page){await page.locator('#m2WorkspaceSettings').click();await expect(page.locator('#pwDialog')).toBeVisible();await page.locator('#pwCreateGroup').click()}
async function bounded(page){expect(await page.evaluate(()=>document.documentElement.scrollWidth-innerWidth)).toBeLessThanOrEqual(1);const d=await page.locator('#pwDialog').boundingBox();if(d){expect(d.x).toBeGreaterThanOrEqual(-1);expect(d.y).toBeGreaterThanOrEqual(-1);expect(d.x+d.width).toBeLessThanOrEqual((await page.viewportSize()).width+1);expect(d.y+d.height).toBeLessThanOrEqual((await page.viewportSize()).height+1)}}
async function capture(page,browser,width,name){const dir=path.join(root,'manager2-artifacts');fs.mkdirSync(dir,{recursive:true});await page.screenshot({animations:'disabled',path:path.join(dir,`manual-groups-${browser}-${width}-${name}.png`)})}
async function select(page,id=10){await inbox(page);await page.locator(`[data-pw-selection="tab:${id}"]`).click()}
async function drafts(page){return page.evaluate(()=>({text:document.getElementById('replyText').value,media:document.getElementById('mediaSelection').textContent,scroll:document.getElementById('messages').scrollTop,conversation:WorkspaceV2.S.current,manager:WorkspaceV2.S.detail.conversation.manager_id,status:WorkspaceV2.S.detail.conversation.status}))}
async function readEarlier(page){
 const messages=page.locator('#messages');
 // Composer/file layout may still resize the pane after its network settles.
 // Start at the settled latest edge, then prove the real wheel reached the
 // history owner. Distance alone can be nonzero before that gesture is adopted.
 await expect.poll(()=>page.evaluate(()=>({following:WorkspaceV2Jump.isFollowingLatest(),atLatest:WorkspaceV2Jump.distanceFromLatest()<=2}))).toEqual({following:true,atLatest:true});
 await expect.poll(()=>messages.evaluate(box=>new Promise(resolve=>{
  const geometry=()=>{const r=box.getBoundingClientRect();return [r.x,r.y,r.width,r.height,box.clientHeight,box.scrollHeight].join(',')},before=geometry();
  requestAnimationFrame(()=>requestAnimationFrame(()=>resolve(geometry()===before)));
 }))).toBe(true);
 // Settle WebKit's native hit-test after the composer/file reflow. This fixed
 // two-part gesture establishes reading; it never retries a failed assertion.
 await messages.hover({position:{x:24,y:24}});
 await page.evaluate(()=>new Promise(resolve=>requestAnimationFrame(()=>requestAnimationFrame(resolve))));
 expect(await messages.evaluate(box=>{const r=box.getBoundingClientRect();return box.contains(document.elementFromPoint(r.left+24,r.top+24))})).toBe(true);
 await messages.evaluate(box=>{
  const receipt=window.manualGroupReadingGesture={wheels:0,lastWheel:performance.now(),lastScroll:performance.now()};
  box.addEventListener('wheel',event=>{if(event.isTrusted&&event.deltaY<0){receipt.wheels++;receipt.lastWheel=performance.now()}},{passive:true});
  box.addEventListener('scroll',()=>{receipt.lastScroll=performance.now()},{passive:true});
 });
 for(let step=0;step<2;step++){
  const before=await page.evaluate(()=>window.manualGroupReadingGesture.wheels);
  await page.mouse.wheel(0,-600);
  await expect.poll(()=>page.evaluate(()=>window.manualGroupReadingGesture.wheels)).toBeGreaterThan(before);
 }
 await expect.poll(()=>page.evaluate(()=>WorkspaceV2Jump.isFollowingLatest())).toBe(false);
 await expect.poll(()=>messages.evaluate(node=>node.scrollHeight-node.clientHeight-node.scrollTop)).toBeGreaterThan(50);
 // Receipt of wheel input precedes its asynchronous default scroll. Let the
 // fixed gesture finish before placing the exact preservation-test baseline.
 await expect.poll(()=>messages.evaluate(box=>new Promise(resolve=>{
  const receipt=window.manualGroupReadingGesture,top=box.scrollTop;
  requestAnimationFrame(()=>requestAnimationFrame(()=>resolve(box.scrollTop===top&&performance.now()-Math.max(receipt.lastWheel,receipt.lastScroll)>=200)));
 }))).toBe(true);
 console.log('MANUAL_GROUP_NATIVE_GESTURE '+JSON.stringify(await messages.evaluate(box=>({wheels:window.manualGroupReadingGesture.wheels,top:box.scrollTop,distance:WorkspaceV2Jump.distanceFromLatest(),following:WorkspaceV2Jump.isFollowingLatest()}))));
 expect(await messages.evaluate(node=>{node.scrollTop=140;return WorkspaceV2Jump.isFollowingLatest()})).toBe(false);
 // Retain the exact position assertion after two actual animation frames,
 // rather than letting a still-pending native scroll become the test baseline.
 await expect.poll(()=>page.evaluate(()=>new Promise(resolve=>{
  const box=document.getElementById('messages'),top=box.scrollTop;
  requestAnimationFrame(()=>requestAnimationFrame(()=>resolve({following:WorkspaceV2Jump.isFollowingLatest(),top:box.scrollTop,stable:box.scrollTop===top})));
 }))).toEqual({following:false,top:140,stable:true});
 console.log('MANUAL_GROUP_READING_READY '+JSON.stringify(await page.evaluate(()=>({width:innerWidth,following:WorkspaceV2Jump.isFollowingLatest(),scroll:document.getElementById('messages').scrollTop,distance:WorkspaceV2Jump.distanceFromLatest()}))));
}

for(const width of [390,430])test(`compact header keeps native tools diagnostic control manual group ${width}: one name creates a tab; two pointer/keyboard actions add or remove without replacing the conversation`,async({page,browserName})=>{
 await page.setViewportSize({width,height:900});const s=await setup(page);await settled(page,s);
 // Personal groups are created through settings, with only a name to enter.
 await createGroup(page);await expect(page.locator('#pwGroupName')).toBeFocused();await expect(page.locator('#pwDialog input:not([type="hidden"]):visible')).toHaveCount(1);await expect(page.locator('#pwDialog select:visible')).toHaveCount(0);
 await page.locator('#pwGroupName').fill('Подбор');await page.locator('#pwGroupSave').click();await expect(page.locator('#pwGroupName')).toHaveCount(0);const created=s.account(7).state.tabs.find(tab=>tab.kind==='group');expect(created.name).toBe('Подбор');expect(writes(s)).toHaveLength(1);expect(writes(s)[0]).toMatchObject({action:'group_create',name:'Подбор'});if(await page.locator('#pwDialog').isVisible())await close(page);
 await expect(page.locator(`[data-pw-selection="tab:${created.id}"]`)).toHaveText('Подбор');await select(page,created.id);await expect(page.locator('#inboxList .leadItem')).toHaveCount(0);await page.locator('[data-q="mine"]').click();await openLead(page);await settled(page,s);
 await page.locator('#replyText').fill('  Точный черновик 🌍\nВыбираем отель, без отправки.  ');await page.locator('#replyFile').setInputFiles([{name:'dates.txt',mimeType:'text/plain',buffer:Buffer.from('Keep selected document')},{name:'voice.ogg',mimeType:'audio/ogg',buffer:Buffer.from('Keep selected audio, never upload')}]);await readEarlier(page);const before=await drafts(page),readCount=details(s).length,listCount=lists(s).length,writeCount=writes(s).length,snapshotCount=personal(s).filter(c=>c.action==='snapshot').length;
 await page.evaluate(()=>{window.manualGroupDom={messages:document.getElementById('messages'),composer:document.getElementById('replyText')}});
 // Exactly two ordinary actions: open Group, choose Add. No client-card detour.
 await showGroupControl(page);await page.locator('#pwGroupOpen').click();const add=page.locator(`[data-pw-group-membership="${created.id}"]`);await expect(add).toContainText('Добавить');await expect(page.locator('#pwDialog')).toContainText(/только вам/);await add.click();await expect.poll(()=>s.account(7).state.members[String(created.group_label_id)]).toEqual([11]);await expect(page.locator('#pwConversationGroups')).toContainText('Подбор');if(await page.locator('#pwDialog').isVisible())await close(page);
 expect(writes(s)).toHaveLength(writeCount+1);expect(writes(s).at(-1)).toMatchObject({action:'group_membership',id:created.id,conversation_id:11,enabled:true});expect(details(s)).toHaveLength(readCount);expect(lists(s)).toHaveLength(listCount);expect(personal(s).filter(c=>c.action==='snapshot')).toHaveLength(snapshotCount);expect(await drafts(page)).toEqual(before);expect(await page.evaluate(()=>window.manualGroupDom.messages===document.getElementById('messages')&&window.manualGroupDom.composer===document.getElementById('replyText'))).toBe(true);
 // The same two actions work from a keyboard, including a short screen.
 await page.setViewportSize({width,height:420});await showGroupControl(page);await page.locator('#pwGroupOpen').focus();await page.keyboard.press('Enter');const remove=page.locator(`[data-pw-group-membership="${created.id}"]`);await expect(remove).toContainText('Убрать');await remove.scrollIntoViewIfNeeded();await expect(remove).toBeInViewport();await bounded(page);await capture(page,browserName,width,'picker-short');await remove.focus();await page.keyboard.press('Enter');await expect.poll(()=>s.account(7).state.members[String(created.group_label_id)]||[]).toEqual([]);if(await page.locator('#pwDialog').isVisible())await page.keyboard.press('Escape');await expect(page.locator('#pwDialog')).toBeHidden();await expect(page.locator('#pwGroupOpen')).toBeFocused();
 expect(writes(s)).toHaveLength(writeCount+2);expect(writes(s).at(-1)).toMatchObject({action:'group_membership',enabled:false});expect(details(s)).toHaveLength(readCount);expect(lists(s)).toHaveLength(listCount);expect((await drafts(page)).text).toBe(before.text);expect((await drafts(page)).media).toBe(before.media);await page.setViewportSize({width,height:900});await capture(page,browserName,width,'removed');clean(s);
});
