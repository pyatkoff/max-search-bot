const {test,expect}=require('@playwright/test');
const fs=require('node:fs'),path=require('node:path');
const {setup,root,emptyState}=require('./fixtures/personal-workspace');

// The real PHP entry point, canonical Inbox/PersonalWorkspace owners and source
// assets run here. Only account data and HTTP receipts are synthetic and local.
const pin=(page,id=11)=>page.locator(`#m2ConversationPin[data-pw-pin="${id}"]`);
const pinControl=page=>page.locator('.m2HeaderPin');
async function openConversation(page,id=11){await row(page,id).locator('.m2RowOpen').click();await expect(page.locator('#conversationTitle')).toHaveText(id===11?longName:'Синтетический клиент '+(id-11));await expect(pin(page,id)).toBeVisible()}
const row=(page,id=11)=>page.locator(`#inboxList .leadItem[data-conversation-id="${id}"]`);
const personal=s=>s.calls.filter(c=>c.path==='/manager/personal-workspace-api.php');
const writes=s=>personal(s).filter(c=>!['snapshot','list','operation'].includes(c.action));
const listCalls=s=>s.calls.filter(c=>c.action==='list');
const longName='Анна Морозова — очень длинное имя клиента для проверки ширины';
function rows(){return Array.from({length:8},(_,i)=>({
 id:11+i,customer_id:101+i,manager_id:i===1?null:i===2?8:7,
 manager_name:i===1?null:i===2?'Елизавета Александрова — очень длинное имя менеджера':'Мария',
 status:i===7?'closed':i===1?'waiting_manager':'manager',channel:['max','telegram','website'][i%3],source_id:1,
 display_name:i===0?longName:'Синтетический клиент '+i,original_display_name:'Синтетический клиент '+i,
 project_label:'AnyTour',origin_label:'Тестовый источник',last_text:i===6?'Спасибо!':'Здравствуйте! Хотим подобрать семейный отдых у моря. Нужен удобный пляж, хороший отель и перелёт без пересадок. Подскажите, пожалуйста, какие варианты есть на наши даты и какой получится бюджет за всех.',
 last_sender_type:'customer',last_message_at:'2026-10-16 08:00:00',unread_count:i===0?999:i===3?12:0,
 awaiting_manager_reply:i===0?1:0,awaiting_first_reply:i===1?1:0,wait_age_seconds:i===1?360:0,lead_outcome:'open'
}))}
function stateFor({font='normal',density='comfortable',groups=0,pinned=true}={}){
 const state=emptyState();state.settings={start_tab:'waiting',font,density};state.pins=pinned?[11]:[];
 state.labels=[{id:100,name:'Очень длинная личная метка для семейного отдыха'},{id:101,name:'Следующая длинная метка'},{id:102,name:'Дополнительная метка'}];
 state.members={100:[11],101:[11],102:[11]};
 for(let i=0;i<groups;i++){
  state.labels.push({id:200+i,name:'Личная группа '+i});state.members[200+i]=[11,18];
  state.tabs.push({id:10+i,name:i?'Очень длинное название личной группы для семейного отдыха '+i:'Подбор',kind:'group',group_label_id:200+i,label_id:200+i,label_ids:[200+i],queue:'all',queues:['all','closed'],channel:'',source_id:0,reply_needed:false,pinned_only:false});
 }
 return state;
}
async function settled(page,s){await expect.poll(()=>s.pending.size).toBe(0);await expect.poll(()=>page.evaluate(()=>!!WorkspaceV2.S.workspaceBound&&!WorkspaceV2.S.booting)).toBe(true);await expect(page.locator('#inboxList')).toHaveAttribute('aria-busy','false')}
function clean(s,{settingsWrites=0}={}){
 expect(s.unexpected).toEqual([]);expect(s.errors).toEqual([]);
 expect(s.calls.filter(c=>['send','send_media','set_working','set_stage','set_outcome','create_task','update_task','close'].includes(c.action))).toEqual([]);
 expect(writes(s).filter(c=>c.action==='settings')).toHaveLength(settingsWrites);
 expect(writes(s).every(c=>c.action==='pin'||settingsWrites>0&&c.action==='settings')).toBe(true);
}
async function capture(page,browserName,profile,height,testInfo){
 const name=`inbox-compact-${browserName}-${profile.width}x${height}-${profile.font}-${profile.density}-groups${profile.groups}${profile.role?'-'+profile.role:''}`;
 const dir=path.join(root,'manager2-artifacts');fs.mkdirSync(dir,{recursive:true});
 const image=await page.screenshot({animations:'disabled',path:path.join(dir,name+'.png')});
 await testInfo.attach(name,{body:image,contentType:'image/png'});
}
async function geometry(page){return page.evaluate(()=>{
 const item=document.querySelector('#inboxList .leadItem'),preview=item.querySelector('.leadPreview'),list=document.querySelector('#inboxList'),head=document.querySelector('.inboxHead'),meta=item.querySelector('.m2ChatMeta');
 const r=el=>el.getBoundingClientRect(),box=r(item),p=r(preview),m=meta&&r(meta),line=parseFloat(getComputedStyle(preview).lineHeight),paddingRight=parseFloat(getComputedStyle(item).paddingRight);
 return {inboxWidth:r(document.querySelector('.inboxZone')).width,headerHeight:r(head).height,listHeight:r(list).height,rowHeight:box.height,previewHeight:p.height,previewWidth:p.width,lineHeight:line,
  previewRightGap:box.right-paddingRight-p.right,overflow:document.documentElement.scrollWidth-innerWidth,
  contained:p.right<=box.right+1&&p.left>=box.left&&(!m||m.right<=box.right+1),
  counters:[...document.querySelectorAll('.queueTabs button[data-q] .queueCount')].map(el=>{const b=r(el),parent=r(el.closest('button'));return b.left>=parent.left-1&&b.right<=parent.right+1&&b.top>=parent.top-1&&b.bottom<=parent.bottom+1}),
  signals:[...item.querySelectorAll('.m2ChannelBadge,.leadReplyStatus,.unreadBadge,.leadActivityTime')].map(el=>{const b=r(el);return {name:el.className,visible:b.width>0&&b.height>0&&b.left>=box.left&&b.right<=box.right+1}})};
})}
async function shortPreview(page){
 const g=await row(page,17).locator('.leadPreview').evaluate(el=>({height:el.getBoundingClientRect().height,line:parseFloat(getComputedStyle(el).lineHeight)}));
 expect(Math.abs(g.height-g.line),'A short message must not reserve empty preview lines').toBeLessThanOrEqual(1);
}
async function noInboxStars(page){
 await expect(page.locator('#inboxList [data-pw-pin]')).toHaveCount(0);
 for(const marker of await page.locator('#inboxList .pwMarkerPin').all())await expect(marker).not.toBeVisible();
}
async function headerGeometry(page){return pin(page).evaluate(el=>{
 const box=el.getBoundingClientRect(),name=document.querySelector('#conversationTitle').getBoundingClientRect(),hit=document.elementFromPoint(box.x+box.width/2,box.y+box.height/2),style=getComputedStyle(el);
 // Native slide transforms can report 43.9999847 for a 44px control. Quantize
 // below a CSS layout subpixel; the 44px and real pointer-hit checks stay strict.
 const size=value=>Math.round(value*1024)/1024;
 return {width:size(box.width),height:size(box.height),fontSize:parseFloat(style.fontSize),hit:hit===el||el.contains(hit),sameLine:Math.abs((box.top+box.height/2)-(name.top+name.height/2))<=6,nearName:box.left>=name.right-1&&box.left-name.right<=16};
})}
const profiles=[
 {width:390,font:'normal',density:'comfortable',groups:0},{width:390,font:'normal',density:'compact',groups:1},
 {width:390,font:'large',density:'comfortable',groups:6},{width:390,font:'large',density:'compact',groups:6},
 {width:430,font:'large',density:'comfortable',groups:6},{width:768,font:'large',density:'compact',groups:6},
 {width:1440,font:'normal',density:'comfortable',groups:0},{width:1440,font:'normal',density:'compact',groups:1},
 {width:1440,font:'large',density:'comfortable',groups:6},{width:1440,font:'large',density:'compact',groups:6}
];
for(const profile of profiles)test(`compact Inbox same data ${profile.width} ${profile.font} ${profile.density} ${profile.groups} groups`,async({page,browserName},testInfo)=>{
 await page.setViewportSize({width:profile.width,height:900});const s=await setup(page,{state:stateFor(profile),rows:rows()});await settled(page,s);
 for(const height of [900,420]){
  await page.setViewportSize({width:profile.width,height});await noInboxStars(page);await expect(page.locator('#m2ConversationPin')).toHaveCount(0);await shortPreview(page);
  const g=await geometry(page);console.log('INBOX_COMPACT '+JSON.stringify({...profile,height,...g}));
  expect(g.overflow).toBeLessThanOrEqual(1);expect(g.contained).toBe(true);expect(Math.abs(g.previewRightGap),'Message preview must reclaim the former star column').toBeLessThanOrEqual(1);
  const lines=profile.density==='compact'?2:3;
  expect(g.previewHeight).toBeGreaterThan(g.lineHeight*(lines-0.5));expect(g.previewHeight).toBeLessThanOrEqual(g.lineHeight*lines+1);
  expect(g.counters).toHaveLength(2);expect(g.counters.every(Boolean),'Queue counters must stay inside their native button').toBe(true);
  expect(g.signals).toHaveLength(4);expect(g.signals.every(signal=>signal.visible)).toBe(true);
  expect(g.headerHeight).toBeLessThanOrEqual(132);expect(g.listHeight).toBeGreaterThanOrEqual(height===420?220:650);
  expect(g.rowHeight).toBeLessThanOrEqual((profile.font==='large'?138:128)+(lines-2)*g.lineHeight);
  if(profile.width===1440)expect(g.inboxWidth).toBe(306);
  await expect(row(page).locator('.unreadBadge')).toHaveText('999');await expect(row(page).locator('.m2RowOpen')).toHaveAccessibleName(new RegExp(longName));
  await capture(page,browserName,profile,height,testInfo);
 }
 expect(listCalls(s)).toHaveLength(1);expect(writes(s)).toHaveLength(0);
 let settingsWrites=0;
 if(profile.font==='large'&&(profile.density==='comfortable'||profile.width===768)){
  const original=profile.density,other=original==='compact'?'comfortable':'compact';
  for(const density of [other,original]){
   await page.locator('#m2WorkspaceSettings').click();await expect(page.locator('#pwDialog')).toBeVisible();await page.locator('#pwDensity').selectOption(density);await page.locator('#pwSettingsSave').click();await expect(page.locator('#pwDialog')).toHaveAttribute('aria-busy','false');await page.locator('#pwClose').click();await settled(page,s);await shortPreview(page);
   const lines=density==='compact'?2:3,g=await geometry(page);expect(g.previewHeight).toBeGreaterThan(g.lineHeight*(lines-0.5));expect(g.previewHeight).toBeLessThanOrEqual(g.lineHeight*lines+1);await expect(page.locator('#m2WorkspaceSettings')).toBeFocused();settingsWrites++;
   expect(writes(s)).toHaveLength(settingsWrites);expect(writes(s).at(-1)).toMatchObject({action:'settings',settings:{density,font:profile.font}});expect(s.calls.filter(c=>c.action==='detail'||/read|seen/.test(c.action||''))).toEqual([]);
  }
 }
 clean(s,{settingsWrites});
});

for(const width of [390,1440])test(`compact Inbox admin keeps channel owner urgency and unread readable ${width}`,async({page,browserName},testInfo)=>{
 const data=rows();data[0].manager_name='Елизавета Александрова — очень длинное имя менеджера';
 const profile={width,font:'large',density:'comfortable',groups:6,role:'admin'};await page.setViewportSize({width,height:420});
 const s=await setup(page,{manager:{role:'admin'},state:stateFor(profile),rows:data});await settled(page,s);
 await expect(row(page).locator('.m2ChatManager')).toBeVisible();await expect(row(page).locator('.m2ChatManager')).toHaveText(data[0].manager_name);
 const g=await geometry(page);expect(g.contained).toBe(true);expect(g.signals.every(signal=>signal.visible)).toBe(true);expect(g.overflow).toBeLessThanOrEqual(1);
 await expect(row(page).locator('.leadReplyStatus')).toHaveText('Ждёт ответа');await expect(row(page,12).locator('.leadWaitCompact')).toBeVisible();
 expect(await row(page,12).locator('.leadWaitCompact').evaluate(el=>el.scrollWidth<=el.clientWidth+1),'Urgency and wait duration must remain readable').toBe(true);
 for(const [id,text]of[[11,'MAX'],[12,'TG'],[13,'Сайт']])await expect(row(page,id).locator('.m2ChannelBadge')).toHaveText(text);
 await expect(row(page,13).locator('.m2OwnershipMark')).toHaveAttribute('aria-label',/Елизавета|друг/i);
 await capture(page,browserName,profile,420,testInfo);clean(s);
});

for(const width of [390,1440])test(`header favorite pointer Enter Space adds no conversation or read request ${width}`,async({page})=>{
 await page.setViewportSize({width,height:900});const s=await setup(page,{state:stateFor({pinned:false}),rows:rows()});await settled(page,s);
 await noInboxStars(page);await openConversation(page);await settled(page,s);const before=s.calls.length;
 const g=await headerGeometry(page);expect(g.width).toBeGreaterThanOrEqual(44);expect(g.height).toBeGreaterThanOrEqual(44);expect(g.hit&&g.sameLine&&g.nearName).toBe(true);
 for(const [activate,pressed]of[['click',true],['Enter',false],['Space',true]]){
  const star=pin(page);await expect(star).toBeVisible();await expect(star).toHaveAccessibleDescription(new RegExp(longName));if(activate==='click')await star.click();else{await star.focus();await star.press(activate)}
  await expect(pin(page)).toHaveAttribute('aria-pressed',String(pressed));await expect(pin(page)).toBeFocused();
  expect(await page.evaluate(()=>WorkspaceV2.S.current)).toBe(11);await noInboxStars(page);
 }
 await settled(page,s);expect(writes(s)).toHaveLength(3);expect(writes(s).map(c=>c.enabled)).toEqual([true,false,true]);
 expect(new Set(writes(s).map(c=>c.operation_id)).size).toBe(3);expect(s.calls.slice(before).filter(c=>c.action==='detail'||/read|seen/.test(c.action||''))).toEqual([]);
 await expect(row(page)).toHaveAttribute('role','listitem');await expect(row(page).locator('.m2RowOpen')).toHaveJSProperty('tagName','BUTTON');
 expect(await row(page).locator('.m2RowOpen button').count()).toBe(0);clean(s);
});

test('header favorite unknown receipt is visible and checked once without repeating the write',async({page})=>{
 const s=await setup(page,{state:stateFor({pinned:false}),rows:rows(),writeResult:'unknown'});await settled(page,s);await openConversation(page);await pin(page).click();
 const control=pinControl(page);await expect(control.locator('.pwDirectPinStatus')).toBeVisible();await expect(control).toContainText(/неизвест|подтверд|уточн/i);
 await expect(pin(page)).toHaveAttribute('aria-pressed','false');await expect(pin(page)).toHaveAttribute('aria-disabled','true');expect(writes(s)).toHaveLength(1);
 await control.locator('[data-pw-pin-recovery="11"]').click();await expect(pin(page)).toHaveAttribute('aria-pressed','true');await expect(pin(page)).toBeFocused();
 expect(writes(s)).toHaveLength(1);expect(personal(s).filter(c=>c.action==='operation')).toHaveLength(1);expect(s.account(7).state.pins).toEqual([11]);clean(s);
});

test('header favorite rejection stays truthful and requires an explicit retry',async({page})=>{
 const s=await setup(page,{state:stateFor({pinned:false}),rows:rows(),writeResult:'rejected'});await settled(page,s);await openConversation(page);await pin(page).click();
 const control=pinControl(page);await expect(control.locator('.pwDirectPinStatus')).toBeVisible();await expect(control).toContainText(/доступ|Не удалось/);
 await expect(pin(page)).toHaveAttribute('aria-pressed','false');expect(writes(s)).toHaveLength(1);expect(s.account(7).state.pins).toEqual([]);
 s.setWriteResult('success');await control.locator('[data-pw-pin-recovery="11"]').click();await expect(pin(page)).toHaveAttribute('aria-pressed','true');expect(writes(s)).toHaveLength(2);clean(s);
});

async function recoverAccount(page,s,id){
 await page.evaluate(()=>WorkspaceV2.showAuthRecovery());s.switchManager({id,role:'manager',display_name:'Ольга',is_working:true});
 await page.locator('#managerAuthLogin').fill('synthetic-account');await page.locator('#managerAuthPassword').fill('synthetic-password');await page.locator('#managerAuthSubmit').click();await expect(page.locator('#managerAuthRecovery')).toBeHidden();
}
test('late favorite receipt cannot leak old account state into the next account',async({page})=>{
 const s=await setup(page,{state:stateFor({pinned:false}),rows:rows(),holdWrite:true});await settled(page,s);await openConversation(page);await pin(page).click();await expect.poll(()=>writes(s).length).toBe(1);
 s.account(8).state=emptyState();s.setHoldWrite(false);await recoverAccount(page,s,8);s.releaseWrite();await settled(page,s);await openConversation(page);
 await expect(pin(page)).toHaveAttribute('aria-pressed','false');expect(s.account(7).state.pins).toEqual([11]);expect(s.account(8).state.pins).toEqual([]);expect(writes(s)).toHaveLength(1);
 await expect(pinControl(page).locator('.pwDirectPinStatus')).not.toBeVisible();await noInboxStars(page);clean(s);
});

test('favorite remains unavailable until its account snapshot is confirmed',async({page})=>{
 const s=await setup(page,{state:stateFor(),rows:rows(),holdSnapshot:true});await openConversation(page);await expect(pin(page)).toBeDisabled();await expect(pin(page)).toHaveAttribute('aria-pressed','mixed');expect(writes(s)).toHaveLength(0);
 s.setHoldSnapshot(false);s.releaseSnapshot();await settled(page,s);await expect(pin(page)).toBeEnabled();await expect(pin(page)).toHaveAttribute('aria-pressed','true');clean(s);
});

async function activeDestinations(page){return page.locator('.m2TabRow .queueTabs button[data-q].active,.m2TabRow [data-pw-selection][aria-pressed="true"],#m2PinnedToggle[aria-pressed="true"],[data-m2-nav="archive"].active').evaluateAll(nodes=>nodes.filter(node=>node.getClientRects().length).map(node=>node.id==='m2PinnedToggle'?'pinned':node.dataset.q||node.dataset.pwSelection||node.dataset.m2Nav||node.id))}
function silentWav(){const samples=80000,b=Buffer.alloc(44+samples*2);b.write('RIFF',0);b.writeUInt32LE(b.length-8,4);b.write('WAVEfmt ',8);b.writeUInt32LE(16,16);b.writeUInt16LE(1,20);b.writeUInt16LE(1,22);b.writeUInt32LE(8000,24);b.writeUInt32LE(16000,28);b.writeUInt16LE(2,32);b.writeUInt16LE(16,34);b.write('data',36);b.writeUInt32LE(samples*2,40);return b}
for(const width of [390,1440])test(`unified queues groups favorites archive preserve reply file and audio ${width}`,async({page})=>{
 await page.setViewportSize({width,height:900});const state=stateFor({groups:6});const wav=silentWav(),messages=[{id:1,sender_type:'customer',text:'Синтетическое аудио',created_at:'2026-10-16 08:00:00',attachments:[{type:'audio',name:'synthetic.wav',url:'data:audio/wav;base64,'+wav.toString('base64')}]}];
 const s=await setup(page,{state,rows:rows(),filterQueues:true,messages});await settled(page,s);await page.locator('.m2TabRow [data-q="mine"]').click();await settled(page,s);await expect(row(page).locator('.m2RowOpen')).toBeVisible();await row(page).locator('.m2RowOpen').click();await expect(page.locator('#replyText')).toBeEnabled();
 await page.locator('#replyText').fill('Ответ с сохранённым файлом и аудио');await page.locator('#replyFile').setInputFiles([{name:'synthetic.txt',mimeType:'text/plain',buffer:Buffer.from('Synthetic attachment; never sent')},{name:'synthetic.wav',mimeType:'audio/wav',buffer:wav}]);
 const attachment=await page.locator('#mediaSelection').textContent();await expect(page.locator('#mediaSelection .mediaSelectedItem')).toHaveCount(2);
 const audio=page.locator('#messages audio');await expect(audio).toHaveCount(1);await expect.poll(()=>audio.evaluate(el=>el.readyState)).toBeGreaterThanOrEqual(1);await audio.evaluate(async el=>{el.muted=true;await el.play();window.compactAudioIdentity=el});await expect.poll(()=>audio.evaluate(el=>el.currentTime)).toBeGreaterThan(.1);await audio.evaluate(el=>window.compactAudioTime=el.currentTime);
 await page.locator('#replyText').evaluate(el=>el.setSelectionRange(2,7));await page.locator('#m2ConversationPin').click();await expect(page.locator('#m2ConversationPin')).toHaveAttribute('aria-pressed','false');
 expect(await page.locator('#replyText').evaluate(el=>[el.selectionStart,el.selectionEnd])).toEqual([2,7]);expect(await audio.evaluate(el=>el===window.compactAudioIdentity&&!el.paused&&el.currentTime>=window.compactAudioTime)).toBe(true);
 if(await page.locator('#mobileBack').isVisible())await page.locator('#mobileBack').click();
 const choose=async(selector,active)=>{const before=listCalls(s).length;await page.locator(selector).click();await settled(page,s);expect(listCalls(s)).toHaveLength(before+1);expect(await activeDestinations(page)).toEqual([active])};
 const beforeTasks=listCalls(s).length;await page.locator('[data-m2-nav="tasks"]').click();await settled(page,s);expect(listCalls(s)).toHaveLength(beforeTasks+1);await expect(page.locator('#workspaceRoot')).toHaveAttribute('data-m2-section','tasks');
 const beforeQueue=listCalls(s).length;await page.locator('.m2TabRow [data-q="all"]').click();await settled(page,s);expect(listCalls(s)).toHaveLength(beforeQueue+1);await expect(page.locator('.inboxHead .brand')).toHaveText('Диалоги');await expect(page.locator('#workspaceRoot')).toHaveAttribute('data-m2-section','dialogues');expect(await activeDestinations(page)).toEqual(['all']);
 await choose('.m2TabRow [data-pw-selection="tab:10"]','tab:10');await expect(page.locator('#inboxList .leadItem')).toHaveCount(2);
 await expect(row(page,18)).toContainText(/закрыт/i);const beforeFavorites=listCalls(s).length;await page.locator('#m2PinnedToggle').click();await settled(page,s);expect(listCalls(s)).toHaveLength(beforeFavorites+1);expect(await activeDestinations(page)).toEqual(['pinned']);await expect(page.locator('#inboxList .leadItem')).toHaveCount(0);
 await choose('[data-m2-nav="archive"]','archive');await expect(row(page,18)).toBeVisible();await choose('.m2TabRow [data-q="mine"]','mine');
 await row(page).locator('.m2RowOpen').click();await expect(page.locator('#replyText')).toHaveValue('Ответ с сохранённым файлом и аудио');await expect(page.locator('#mediaSelection')).toHaveText(attachment);
 expect(await audio.evaluate(el=>el===window.compactAudioIdentity&&!el.paused&&el.currentTime>=window.compactAudioTime)).toBe(true);await audio.evaluate(el=>el.pause());expect(writes(s)).toHaveLength(1);clean(s);
});

test('removing the last favorite keeps the open conversation and header focus',async({page})=>{
 await page.setViewportSize({width:390,height:420});const s=await setup(page,{state:stateFor(),rows:rows()});await settled(page,s);await page.locator('#m2PinnedToggle').click();await expect(page.locator('#inboxList .leadItem')).toHaveCount(1);await openConversation(page);
 await pin(page).press('Enter');await expect(page.locator('#inboxList .leadItem')).toHaveCount(0);await expect(pin(page)).toBeFocused();await expect(pin(page)).toHaveAttribute('aria-pressed','false');expect(await activeDestinations(page)).toEqual(['pinned']);
 await expect(page.locator('#conversationTitle')).toHaveText(longName);await noInboxStars(page);expect(writes(s)).toHaveLength(1);expect(writes(s)[0]).toMatchObject({action:'pin',conversation_id:11,enabled:false});
 // A separate device removes the repinned item while the mobile Inbox's native
 // opener has focus. Its empty refreshed list must return focus to the visible
 // favorite navigation button, rather than an off-screen conversation.
 await pin(page).click();await expect(page.locator('#inboxList .leadItem')).toHaveCount(1);await settled(page,s);await page.locator('#mobileBack').click();
 const opener=row(page).locator('.m2RowOpen');await opener.focus();await expect(opener).toBeFocused();const account=s.account(7);account.state.pins=[];account.version++;
 await page.evaluate(()=>WorkspaceV2Inbox.load({preserveScroll:true}));await settled(page,s);await expect(page.locator('#inboxList .leadItem')).toHaveCount(0);
 const destination=page.locator('#m2PinnedToggle');await expect(destination).toBeFocused();await expect(destination).toBeInViewport();await expect(page.locator('#m2ConversationPin')).not.toBeInViewport();await noInboxStars(page);expect(writes(s)).toHaveLength(2);clean(s);
});

// The retained synthetic portrait is also used by the canonical avatar suites.
const portrait=Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAEAAAABACAIAAAAlC+aJAAABM0lEQVR4nO2YzQ3CMAxGU8S4nDpIT+zABszAWBy4IIHAP89xovg7VpX9vdhp42y3x73NrFO2Aa8KIFsFkK0CyFYBZOscFPc4rp8P9/2CJ9rwo8RX6+9iMeAW+ute+I5cJIDcGciAAWg9UQwMgM0NwjD9ZxQA8CykvwhVgWwVQLYKIFsAgOdw5j/YVQVaa9aFRM7VWAW0bqipgGwhuSdwpoH3gMQZO5HxI+VLE8/EnVWf0WyF3Av9GFPwbUDuAdV4RZEwAObJ0I/hBUBuFjwYrk1M3e144tgB2BtCczQjAOveE9MCEOHeHFkNEOfeFn/6P7EOIHr5DVkUAH3ca3Mt1kIDSgrQs39UGZepwLASAfTvH3neNSowsgogWwWQrQLIlggg4laZyrtGBUaWFKB/FwkzPgFzBmYhb17hEQAAAABJRU5ErkJggg==','base64');
function refreshRows(){return Array.from({length:200},(_,i)=>({...rows()[0],id:11+i,customer_id:101+i,display_name:'Синтетический клиент '+i,original_display_name:'Синтетический клиент '+i,channel:i?'website':'telegram',unread_count:i===0?3:0,awaiting_manager_reply:0}))}
for(const width of [390,430,768,1440])test(`incremental Inbox refresh preserves selection focus anchors and reply ${width}`,async({page,browserName},testInfo)=>{
 await page.setViewportSize({width,height:420});const data=refreshRows(),s=await setup(page,{state:stateFor(),rows:data,avatarImage:portrait});await settled(page,s);
 await expect(row(page).locator('.customerAvatarOpen')).toBeVisible();
 await page.evaluate(()=>{
  const box=document.getElementById('inboxList'),nodes=[...box.querySelectorAll('.leadItem')],item=nodes[15],button=item.querySelector('.m2RowOpen'),text=item.querySelector('.leadPreview').firstChild;
  box.scrollTop=item.offsetTop-box.offsetTop-20;button.focus({preventScroll:true});
  window.inboxRefreshProof={nodes,button,text,avatar:nodes[0].querySelector('.leadAvatar'),markers:nodes[0].querySelector('.pwRowMarkers'),top:item.getBoundingClientRect().top,blur:0};
  button.addEventListener('blur',()=>inboxRefreshProof.blur++);getSelection().setBaseAndExtent(text,24,text,2);
 });
 const before=listCalls(s).length;await page.evaluate(()=>WorkspaceV2Inbox.load({preserveScroll:true}));await settled(page,s);
 expect(listCalls(s)).toHaveLength(before+1);
 expect(await page.evaluate(()=>{
  const p=inboxRefreshProof,nodes=[...document.querySelectorAll('#inboxList .leadItem')],sel=getSelection();
  return {same:nodes.filter((n,i)=>n===p.nodes[i]).length,focus:document.activeElement===p.button,blur:p.blur,selection:[sel.anchorNode===p.text,sel.anchorOffset,sel.focusNode===p.text,sel.focusOffset],avatar:nodes[0].querySelector('.leadAvatar')===p.avatar,markers:nodes[0].querySelector('.pwRowMarkers')===p.markers,anchor:Math.abs(p.nodes[15].getBoundingClientRect().top-p.top)<1};
 })).toEqual({same:200,focus:true,blur:0,selection:[true,24,true,2],avatar:true,markers:true,anchor:true});
 // A new arrival above the reader, removal elsewhere and one real text/unread
 // update must not rebuild the other rows or shift the visible reading anchor.
 data[199]={...data[199],last_text:'Новое сообщение с обновлённым непрочитанным',unread_count:7};data.splice(150,1);data.unshift({...data[1],id:999,customer_id:999,display_name:'Новый диалог'});
 await page.evaluate(()=>WorkspaceV2Inbox.load({preserveScroll:true}));await settled(page,s);
 await expect(row(page,210).locator('.leadPreview')).toHaveText(data.at(-1).last_text);await expect(row(page,210).locator('.unreadBadge')).toHaveText('7');await expect(row(page,161)).toHaveCount(0);
 expect(await page.evaluate(()=>{
  const p=inboxRefreshProof,sel=getSelection();return {focus:document.activeElement===p.button,selection:[sel.anchorNode===p.text,sel.anchorOffset,sel.focusNode===p.text,sel.focusOffset],anchor:Math.abs(p.nodes[15].getBoundingClientRect().top-p.top)<1,same:document.querySelector('[data-conversation-id="26"]')===p.nodes[15]};
 })).toEqual({focus:true,selection:[true,24,true,2],anchor:true,same:true});
 // Move the selected/focused row itself (including engines without moveBefore).
 const selected=data.splice(data.findIndex(r=>r.id===26),1)[0];data.splice(20,0,selected);
 await page.evaluate(()=>WorkspaceV2Inbox.load({preserveScroll:true}));await settled(page,s);
 expect(await page.evaluate(()=>({focus:document.activeElement===inboxRefreshProof.button,text:getSelection().toString(),backwards:getSelection().anchorOffset>getSelection().focusOffset}))).toEqual({focus:true,text:data[20].last_text.slice(2,24),backwards:true});
 // Already-read refreshes must not invalidate an otherwise unchanged row.
 await page.evaluate(()=>WorkspaceV2Inbox.markRead(26));await page.evaluate(()=>WorkspaceV2Inbox.load({preserveScroll:true}));await settled(page,s);
 expect(await row(page,26).evaluate(el=>el===inboxRefreshProof.nodes[15])).toBe(true);
 // The first visible row may itself change while a new arrival inserts above.
 const anchor=await page.evaluate(()=>{const box=document.getElementById('inboxList'),top=box.getBoundingClientRect().top,el=[...box.querySelectorAll('.leadItem')].find(n=>n.getBoundingClientRect().bottom>top+1);return {id:Number(el.dataset.conversationId),top:el.getBoundingClientRect().top}});
 const anchorRow=data.find(r=>r.id===anchor.id);anchorRow.last_text+=' — обновлено';data.unshift({...data[1],id:998,customer_id:998,display_name:'Ещё один диалог'});
 await page.evaluate(()=>WorkspaceV2Inbox.load({preserveScroll:true}));await settled(page,s);
 expect(Math.abs(await row(page,anchor.id).evaluate(el=>el.getBoundingClientRect().top)-anchor.top)).toBeLessThan(1);
 data.shift();
 await expect(page.locator('#inboxList .m2RowOpen')).toHaveCount(201);await expect(page.locator('#inboxList .m2RowOpen button')).toHaveCount(0);
 // A local read removes the badge immediately; a later authoritative projection
 // can restore a nonzero count, rather than reusing the locally modified row.
 await page.evaluate(()=>WorkspaceV2Inbox.markRead(210));await expect(row(page,210).locator('.unreadBadge')).toHaveCount(0);
 await page.evaluate(()=>WorkspaceV2Inbox.load({preserveScroll:true}));await settled(page,s);await expect(row(page,210).locator('.unreadBadge')).toHaveText('7');
 await row(page,26).locator('.m2RowOpen').click();await expect(page.locator('#replyText')).toBeEnabled();await page.locator('#replyText').fill('Черновик не отправлять');
 await page.locator('#replyFile').setInputFiles({name:'synthetic.txt',mimeType:'text/plain',buffer:Buffer.from('Never sent')});
 await page.locator('#replyText').evaluate(el=>{el.setSelectionRange(3,9);window.inboxReplyIdentity={el,file:document.getElementById('replyFile').files[0],message:document.querySelector('#messages .msg')}});
 await page.evaluate(()=>WorkspaceV2Inbox.load({preserveScroll:true}));await settled(page,s);
 await expect(page.locator('#replyText')).toHaveValue('Черновик не отправлять');expect(await page.locator('#replyText').evaluate(el=>el===inboxReplyIdentity.el&&el.selectionStart===3&&el.selectionEnd===9&&document.getElementById('replyFile').files[0]===inboxReplyIdentity.file&&document.querySelector('#messages .msg')===inboxReplyIdentity.message)).toBe(true);
 if(await page.locator('#mobileBack').isVisible())await page.locator('#mobileBack').click();
 // Deleting the focused row gives the adjacent native opener keyboard focus.
 await row(page,26).locator('.m2RowOpen').focus();data.splice(data.findIndex(r=>r.id===26),1);
 await page.evaluate(()=>WorkspaceV2Inbox.load({preserveScroll:true}));await settled(page,s);await expect(row(page,26)).toHaveCount(0);
 expect(await page.evaluate(()=>document.activeElement.matches('#inboxList .m2RowOpen'))).toBe(true);
 await capture(page,browserName,{width,font:'normal',density:'comfortable',groups:0},420,testInfo);
 expect(s.calls.filter(c=>c.path==='/manager/api.php'&&c.action==='detail')).toHaveLength(1);expect(writes(s)).toHaveLength(0);clean(s);
});

test('incremental Inbox invalidates changed account filter and Moscow day while retaining fresh content',async({page})=>{
 await page.clock.install({time:new Date('2026-10-16T20:59:00Z')});const data=refreshRows().slice(0,2);data[0].last_message_at='2026-10-16 08:00:00';
 const s=await setup(page,{state:stateFor(),rows:data});await settled(page,s);await expect(row(page).locator('.leadActivityTime')).toHaveText('11:00');
 await row(page).evaluate(el=>window.retainedInboxRow=el);await page.clock.setSystemTime(new Date('2026-10-16T21:01:00Z'));await page.evaluate(()=>WorkspaceV2Inbox.load());await settled(page,s);
 await expect(row(page).locator('.leadActivityTime')).toHaveText('Вчера');expect(await row(page).evaluate(el=>el===retainedInboxRow)).toBe(true);
 await page.evaluate(()=>{WorkspaceV2.S.leadSearch='Синтетический';return WorkspaceV2Inbox.load()});await settled(page,s);expect(await row(page).evaluate(el=>el===retainedInboxRow)).toBe(false);
 await row(page).evaluate(el=>window.retainedInboxRow=el);s.account(8).state=emptyState();await recoverAccount(page,s,8);await settled(page,s);
 expect(await row(page).evaluate(el=>el===retainedInboxRow)).toBe(false);await expect(row(page).locator('.pwRowMarkers')).toHaveCount(0);expect(writes(s)).toHaveLength(0);clean(s);
});

test('personal Inbox refresh retains marker selection and truthful stale loading empty recovery',async({page})=>{
 const data=refreshRows().slice(0,2),s=await setup(page,{state:stateFor(),rows:data});await settled(page,s);await page.locator('#m2PinnedToggle').click();await settled(page,s);
 await row(page).locator('.pwMarkerLabel').first().evaluate(el=>{window.personalMarker=el;getSelection().setBaseAndExtent(el.firstChild,8,el.firstChild,1)});
 await page.evaluate(()=>WorkspaceV2Inbox.load());await settled(page,s);expect(await row(page).locator('.pwMarkerLabel').first().evaluate(el=>el===personalMarker&&getSelection().toString()===el.textContent.slice(1,8))).toBe(true);
 s.setHoldList(true);const loading=page.evaluate(()=>WorkspaceV2Inbox.load());await expect(page.locator('#inboxList')).toHaveAttribute('aria-busy','true');await expect(row(page)).toHaveCount(1);s.setHoldList(false);s.releaseList();await loading;await settled(page,s);
 s.setListError(true);await page.evaluate(()=>WorkspaceV2Inbox.load());await settled(page,s);await expect(page.locator('#inboxList')).toHaveAttribute('data-stale','true');await expect(row(page)).toHaveCount(1);await expect(page.locator('#inboxLoadStatus')).toContainText('Не удалось обновить');
 s.setListError(false);s.account(7).state.pins=[];s.account(7).version++;await page.locator('#inboxLoadStatus button').click();await settled(page,s);await expect(page.locator('#inboxList .leadItem')).toHaveCount(0);await expect(page.locator('#inboxList .empty')).toBeVisible();await expect(page.locator('#inboxList')).not.toHaveAttribute('data-stale','true');
 expect(writes(s)).toHaveLength(0);clean(s);
});
test('loaded client photo stays independent from native conversation and favorite controls',async({page})=>{
 await page.setViewportSize({width:390,height:900});const s=await setup(page,{state:stateFor(),rows:rows(),avatarImage:portrait});await settled(page,s);
 for(const width of [390,1440]){
  await page.setViewportSize({width,height:900});const item=row(page),avatar=item.locator('.customerAvatarOpen');await expect(avatar).toBeVisible();
  await expect(item).toHaveAttribute('role','listitem');expect(await item.locator('.m2RowOpen button,.m2RowOpen [role="button"]').count()).toBe(0);
  expect(await item.locator('.leadIdentity[role="button"]').count()).toBe(0);await expect(item.locator('.leadAvatar')).not.toHaveAttribute('aria-hidden','true');
  await avatar.focus();await avatar.evaluate(el=>window.compactAvatarIdentity=el);await page.evaluate(()=>WorkspaceV2Inbox.load({preserveScroll:true}));await settled(page,s);await expect(avatar).toBeFocused();expect(await avatar.evaluate(el=>el===window.compactAvatarIdentity)).toBe(true);
  await avatar.press('Enter');await expect(page.locator('#customerAvatarDialog')).toBeVisible();await expect.poll(()=>page.locator('.customerAvatarFullImage').evaluate(el=>el.naturalWidth)).toBe(64);
  expect(s.calls.filter(c=>c.path==='/manager/api.php'&&c.action==='detail')).toHaveLength(0);expect(await page.evaluate(()=>WorkspaceV2.S.current)).toBe(0);
  await page.locator('.customerAvatarClose').click();await expect(page.locator('#customerAvatarDialog')).toBeHidden();await expect(avatar).toBeFocused();
 }
 await row(page).locator('.m2RowOpen').press('Enter');await expect(page.locator('#conversationTitle')).toHaveText(longName);await expect(page.locator('#replyText')).toBeEnabled();
 expect(s.calls.filter(c=>c.path==='/manager/api.php'&&c.action==='detail')).toHaveLength(1);expect(writes(s)).toHaveLength(0);clean(s);
});

test('Alt+J still activates the canonical next attention conversation once',async({page})=>{
 await page.setViewportSize({width:1440,height:900});const s=await setup(page,{state:stateFor({pinned:false}),rows:rows()});await settled(page,s);
 await page.locator('#m2InboxSearchToggle').focus();await page.keyboard.press('Alt+j');await expect(page.locator('#conversationTitle')).toHaveText(longName);await settled(page,s);
 expect(s.calls.filter(c=>c.path==='/manager/api.php'&&c.action==='detail').map(c=>c.conversation_id)).toEqual([11]);
 await page.locator('#conversationTitle').focus();await page.keyboard.press('Alt+j');await expect(page.locator('#conversationTitle')).toHaveText('Синтетический клиент 1');await settled(page,s);
 expect(s.calls.filter(c=>c.path==='/manager/api.php'&&c.action==='detail').map(c=>c.conversation_id)).toEqual([11,12]);expect(writes(s)).toHaveLength(0);clean(s);
});

test('one favorite entry and the full horizontal strip retain scrolling and keyboard access',async({page})=>{
 await page.setViewportSize({width:390,height:900});const s=await setup(page,{state:stateFor({groups:6}),rows:rows(),filterQueues:true});await settled(page,s);
 const expected=['waiting','mine','all',...Array.from({length:6},(_,i)=>'tab:'+(10+i))];
 for(const width of [390,1440]){
  await page.setViewportSize({width,height:900});await noInboxStars(page);
  await expect(page.locator('#m2TabNavigator select,#m2TabPicker,.m2TabPickerControl,.m2TabPickerChevron,#m2PinnedSelection')).toHaveCount(0);
  await expect(page.locator('.m2TabRow [data-pw-selection="pinned"]')).not.toBeVisible();await expect(page.locator('#m2PinnedToggle')).toHaveCount(1);await expect(page.locator('#m2PinnedToggle')).toBeVisible();
  const strip=page.locator('#m2TabRow');
  const g=await strip.evaluate(el=>{const box=el.getBoundingClientRect(),parent=el.parentElement.getBoundingClientRect(),style=getComputedStyle(el);return {fullWidth:Math.abs(box.right-parent.right)<=1&&Math.abs(box.left-parent.left)<=1,scrollable:el.scrollWidth>el.clientWidth,overflow:style.overflowX,touchAction:style.touchAction}});
  expect(g.fullWidth,'The former square must leave no reserved strip column').toBe(true);expect(g.scrollable).toBe(true);expect(g.overflow).toBe('auto');expect(g.touchAction).not.toBe('none');
  // Trusted horizontal wheel input exercises the browser's own scrolling; no
  // application state or scrollLeft setter is used to reveal the final group.
  await page.locator('.m2TabRow [data-q="waiting"]').focus();const beforeScroll=await strip.evaluate(el=>el.scrollLeft),box=await strip.boundingBox();await page.mouse.move(box.x+box.width/2,box.y+box.height/2);await page.mouse.wheel(2000,0);
  await expect.poll(()=>strip.evaluate(el=>el.scrollLeft)).toBeGreaterThan(beforeScroll+20);
  const last=page.locator('.m2TabRow [data-pw-selection="tab:15"]');await expect(last).toBeInViewport();let before=listCalls(s).length;await last.click();await settled(page,s);expect(listCalls(s)).toHaveLength(before+1);expect(await activeDestinations(page)).toEqual(['tab:15']);
  // The single existing favorite entry remains in the toolbar. Normal Tab
  // reaches it; there is no second visible favorite entry in the strip.
  await page.locator('#m2InboxSearchToggle').focus();for(let i=0;i<8&&!await page.locator('#m2PinnedToggle').evaluate(el=>document.activeElement===el);i++)await page.keyboard.press('Tab');await expect(page.locator('#m2PinnedToggle')).toBeFocused();before=listCalls(s).length;await page.keyboard.press('Enter');await settled(page,s);expect(listCalls(s)).toHaveLength(before+1);expect(await activeDestinations(page)).toEqual(['pinned']);
  // Follow the actual Tab order through every native destination. The real
  // focusin owner reveals clipped buttons, and each Enter performs one read.
  await page.locator('#m2WorkspaceSettings').focus();const seen=new Set();
  for(let i=0;i<40&&seen.size<expected.length;i++){
   await page.keyboard.press('Tab');const key=await page.evaluate(()=>{const el=document.activeElement;return el?.closest('.m2TabRow')?(el.dataset.q||el.dataset.pwSelection||''):''});
   if(!expected.includes(key)||seen.has(key))continue;
   const focused=page.locator(key.startsWith('tab:')||key==='pinned'?`.m2TabRow [data-pw-selection="${key}"]`:`.m2TabRow [data-q="${key}"]`);await expect(focused).toBeFocused();await expect(focused).toBeInViewport();
   before=listCalls(s).length;await page.keyboard.press('Enter');await settled(page,s);expect(listCalls(s)).toHaveLength(before+1);expect(await activeDestinations(page)).toEqual([key]);seen.add(key);
  }
  expect([...seen].sort()).toEqual([...expected].sort());await noInboxStars(page);
 }
 expect(writes(s)).toHaveLength(0);clean(s);
});
