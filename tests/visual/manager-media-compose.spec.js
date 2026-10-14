const {test,expect}=require('@playwright/test');
const {execFileSync}=require('node:child_process');
const fs=require('node:fs'),path=require('node:path');
const root=path.resolve(__dirname,'../..'),origin='http://127.0.0.1:4173';
const shell=execFileSync('php',['manager2/index.php'],{cwd:root,encoding:'utf8'});
async function setup(page,{failSecond=false,unknown=false,unknownSecond=false,holdFirst=false,suspendedSecond=false,failedRefresh=false}={}){
 const uploads=[],errors=[],held=[];let lists=0,details=0;
 const manager={id:7,display_name:'Мария',role:'manager',is_working:true};
 const conversation=id=>({id,customer_id:id,display_name:id===11?'Анна':'Алексей',manager_id:7,status:'manager',channel:'telegram'});
 page.on('pageerror',e=>errors.push(e.message));
 await page.route('**/*',async route=>{
  const request=route.request(),url=new URL(request.url());
  const reply=(body,status=200)=>route.fulfill({status,contentType:'application/json',body:JSON.stringify(body)});
  if(url.origin!==origin)throw new Error('Forbidden external request '+url);
  if(url.pathname.startsWith('/manager/assets/')||url.pathname.startsWith('/manager2/assets/'))return route.fulfill({contentType:url.pathname.endsWith('.css')?'text/css':'application/javascript',body:fs.readFileSync(path.join(root,url.pathname.slice(1)))});
  if(url.pathname==='/manager2/')return route.fulfill({contentType:'text/html',body:shell});
  if(url.protocol==='blob:'&&url.origin===origin)return route.continue();
  if(request.method()==='GET'&&url.pathname==='/manager/customer-avatar.php'){expect([...url.searchParams.keys()]).toEqual(['conversation_id']);expect(['11','12']).toContain(url.searchParams.get('conversation_id'));return route.fulfill({status:404,body:''})}
  if(url.pathname==='/manager/media-upload.php'){
   uploads.push(request.postDataBuffer().toString());
   if(holdFirst&&uploads.length===1)await new Promise(resolve=>held.push(resolve));
   if((unknown&&uploads.length===1)||(unknownSecond&&uploads.length===2))return route.abort();
   if(suspendedSecond&&uploads.length===2)return reply({ok:false,error:'delivery_failed',failure:{category:'suspended',http_code:403,notice:'Диалог приостановлен'},error_message:'Диалог приостановлен'},409);
   if(failSecond&&uploads.length===2)return reply({ok:false,error:'file_too_large'},400);
   return reply({ok:true});
  }
  const d=request.method()==='POST'?request.postDataJSON()||{}:{};
  if(request.method()==='POST'&&url.pathname==='/manager/post-context-api.php'&&d.action==='detail'&&d.csrf==='fixture'&&[11,12].includes(d.conversation_id))return reply({ok:true,conversation_id:d.conversation_id,context:{current:null,history:[]}});
  if(request.method()==='POST'&&url.pathname==='/manager/quick-replies-api.php'&&d.action==='toolbar'&&d.csrf==='fixture')return reply({ok:true,replies:[],version:'0'.repeat(64),max_replies:100});
  if(url.pathname==='/manager/api.php'){
   if(d.action==='me')return reply({ok:true,manager,csrf:'fixture',projects:[]});
   if(d.action==='counts')return reply({ok:true,counts:{mine:{count:2}}});
   if(d.action==='detail'){details++;if(failedRefresh&&details>1)return reply({ok:false,error:'temporary_failure'},503);return reply({ok:true,conversation:conversation(d.conversation_id),messages:[{id:1,sender_type:'customer',text:'Здравствуйте!',created_at:'2026-10-14 08:00:00'}]})}
   if(d.action==='edit_candidates')return reply({ok:true,messages:[],candidates:[]});
   throw new Error('Unexpected API '+d.action);
  }
  if(url.pathname==='/manager/pipeline-api.php'){
   if(d.action==='catalog')return reply({ok:true,stages:[],tags:[],outcomes:{open:'В работе'},close_reasons:{}});
   if(d.action==='filter_options')return reply({ok:true,filters:{sources:[],managers:[manager]}});
   if(d.action==='list'){lists++;return reply({ok:true,conversations:[11,12].map(id=>({...conversation(id),last_text:'Здравствуйте!',can_edit_pipeline:true}))})}
   if(d.action==='detail')return reply({ok:true,can_edit_pipeline:true,pipeline:{stage:null,tags:[],stage_history:[],outcome:{outcome:'open'}},tasks:[],trip:{},contact:{},source:{},handoff:{}});
  }
  if(url.pathname==='/manager/push-status.php')return reply({ok:true,push_status:{is_working:true,healthy_subscription_count:0,notification_path_reason:'no_subscription'}});
  if(url.pathname==='/manager/notification-events.php')return reply({ok:true,cursor:0,events:[]});
  if(url.pathname==='/manager/push.php')return reply({ok:true,public_key:''});
  if(url.pathname==='/manager/sw.js')return route.fulfill({contentType:'application/javascript',body:''});
  if(url.pathname==='/favicon.ico')return route.fulfill({status:204,body:''});
  throw new Error('Unexpected endpoint '+url.pathname);
 });
 await page.goto(origin+'/manager2/');await expect(page.locator('#inboxList .leadItem')).toHaveCount(2);await page.locator('[data-conversation-id="11"]').click();await expect(page.locator('#composer')).toBeVisible();
 return{uploads,errors,release:()=>held.shift()?.(),lists:()=>lists};
}
const files=[{name:'Маршрут.txt',mimeType:'text/plain',buffer:Buffer.from('Synthetic')},{name:'Билеты.pdf',mimeType:'application/pdf',buffer:Buffer.from('%PDF synthetic')}];
for(const width of [390,430,768,1440])for(const height of [900,420])test(`selection ${width}x${height}`,async({page,browserName})=>{
 await page.setViewportSize({width,height});const s=await setup(page);await page.locator('#replyText').fill('Подпись к первому файлу');if(height===420){await page.locator('#m2ConversationMenuToggle').click();await page.locator('#conversationSearchOpen').click();await page.locator('#conversationSearchInput').fill('Здравствуйте');await page.locator('#replyText').focus()}
 fs.mkdirSync('manager-media-artifacts',{recursive:true});await page.screenshot({path:`manager-media-artifacts/${browserName}-${width}-${height}-before.png`});
 await page.locator('#replyFile').setInputFiles(files);await expect(page.locator('.mediaSelectedItem')).toHaveCount(2);expect(s.uploads).toHaveLength(0);
 await page.screenshot({path:`manager-media-artifacts/${browserName}-${width}-${height}-after.png`});
 const box=await page.locator('#replyText').boundingBox();expect(box.y+box.height).toBeLessThanOrEqual(height+1);const sendBox=await page.locator('#sendReply').boundingBox();expect(sendBox.y+sendBox.height).toBeLessThanOrEqual(height+1);expect((await page.locator('#messages').boundingBox()).height).toBeGreaterThanOrEqual(40);expect(await page.evaluate(()=>document.documentElement.scrollWidth)).toBeLessThanOrEqual(width+1);
 await page.locator('.mediaRemove').first().focus();await expect(page.locator('.mediaRemove').first()).toBeFocused();await page.locator('.mediaRemove').first().press('Enter');await expect(page.locator('.mediaSelectedItem')).toHaveCount(1);await page.locator('#sendReply').click();await expect(page.locator('#replyStatus')).toContainText('Отправлено');expect(s.uploads).toHaveLength(1);expect(s.uploads[0]).toContain('Билеты.pdf');expect(s.uploads[0]).toContain('Подпись к первому файлу');expect(s.errors).toEqual([]);
});
test('partial receipt clears confirmed caption and retries remaining file without repeating it',async({page})=>{
 const s=await setup(page,{failSecond:true});await page.locator('#replyText').fill('Подпись');await page.locator('#replyFile').setInputFiles(files);await page.locator('#sendReply').click();await expect(page.locator('#replyStatus')).toContainText('Отправлено файлов: 1');await expect(page.locator('#replyText')).toHaveValue('');await expect(page.locator('.mediaSelectedItem')).toHaveCount(1);await page.locator('#sendReply').click();await expect(page.locator('#replyStatus')).toContainText('Отправлено');expect(s.uploads).toHaveLength(3);expect(s.uploads[0]).toContain('Маршрут.txt');expect(s.uploads[2]).toContain('Билеты.pdf');expect(s.uploads[2]).not.toContain('Подпись');expect(s.errors).toEqual([]);
});
test('unknown transport outcome cannot be repeated and explicitly pasted screenshot stays local',async({page})=>{
 const s=await setup(page,{unknown:true});await page.evaluate(async()=>{const canvas=document.createElement('canvas');canvas.width=64;canvas.height=32;canvas.getContext('2d').fillRect(0,0,64,32);const blob=await new Promise(resolve=>canvas.toBlob(resolve));const dt=new DataTransfer();dt.items.add(new File([blob],'Screenshot.png',{type:'image/png'}));document.getElementById('replyText').dispatchEvent(new ClipboardEvent('paste',{clipboardData:dt,bubbles:true,cancelable:true}))});await expect.poll(()=>page.locator('.mediaPreview img').evaluate(img=>img.naturalWidth)).toBe(64);await expect(page.locator('.mediaSelectedItem')).toHaveCount(1);expect(s.uploads).toHaveLength(0);await page.locator('#sendReply').click();await expect(page.locator('#replyStatus')).toContainText('неизвестен');await expect(page.locator('.mediaRemove')).toBeDisabled();await page.locator('#sendReply').click();expect(s.uploads).toHaveLength(1);expect(s.errors).toEqual([]);
});

test('unknown second receipt clears only the confirmed caption and blocks the remaining file',async({page})=>{
 const s=await setup(page,{unknownSecond:true});await page.locator('#replyText').fill('Подпись');await page.locator('#replyFile').setInputFiles(files);await page.locator('#sendReply').click();await expect(page.locator('#replyStatus')).toContainText('Отправлено файлов: 1');await expect(page.locator('#replyStatus')).toContainText('неизвестен');await expect(page.locator('#replyText')).toHaveValue('');await expect(page.locator('.mediaSelectedItem')).toHaveCount(1);await expect(page.locator('.mediaFileName')).toHaveText('Билеты.pdf');await expect(page.locator('.mediaRemove')).toBeDisabled();await page.locator('#sendReply').click();expect(s.uploads).toHaveLength(2);expect(s.errors).toEqual([]);
});

test('pending attachment stops after switching client and old-account response cannot alter new files',async({page})=>{
 const s=await setup(page,{holdFirst:true});await page.locator('#replyFile').setInputFiles(files);await page.locator('#sendReply').click();await expect.poll(()=>s.uploads.length).toBe(1);
 await page.evaluate(()=>{WorkspaceV2Media.preserveDraft(11);WorkspaceV2Media.configure('fixture',12);WorkspaceV2Media.restoreDraft(12);WorkspaceV2.S.current=12});s.release();await expect.poll(()=>page.evaluate(()=>document.getElementById('sendReply').disabled)).toBe(false);expect(s.uploads).toHaveLength(1);
 await page.evaluate(()=>{WorkspaceV2Media.configure('fixture',11);WorkspaceV2Media.restoreDraft(11);WorkspaceV2.S.current=11});await expect(page.locator('.mediaSelectedItem')).toHaveCount(1);
});
test('pending account changes preserve new account file after old response',async({page})=>{
 const s=await setup(page,{holdFirst:true});await page.locator('#replyFile').setInputFiles(files);await page.locator('#sendReply').click();await expect.poll(()=>s.uploads.length).toBe(1);
 await page.evaluate(()=>{WorkspaceV2.S.authGeneration++;WorkspaceV2.S.manager={id:8,role:'manager'};WorkspaceV2Media.resetDrafts();WorkspaceV2Media.configure('new-account',11);WorkspaceV2Media.addFiles([new File(['new-account'],'New-account.txt',{type:'text/plain'})])});s.release();await expect.poll(()=>page.evaluate(()=>document.getElementById('sendReply').disabled)).toBe(false);await expect(page.locator('.mediaSelectedItem')).toHaveCount(1);await expect(page.locator('.mediaFileName')).toHaveText('New-account.txt');expect(s.uploads).toHaveLength(1);expect(s.errors).toEqual([]);
});

test('partial provider suspension remains visible and blocks send when detail refresh fails',async({page})=>{
 const s=await setup(page,{suspendedSecond:true,failedRefresh:true});await page.locator('#replyText').fill('Подпись');await page.locator('#replyFile').setInputFiles(files);await page.locator('#sendReply').click();await expect(page.locator('#replyStatus')).toContainText('Отправлено файлов: 1');await expect(page.locator('#deliveryFailure')).toContainText('Диалог приостановлен');await expect(page.locator('#sendReply')).toBeDisabled();expect(s.uploads).toHaveLength(2);await expect(page.locator('.mediaSelectedItem')).toHaveCount(1);expect(s.errors).toEqual([]);
});

test('repeated canonical identity setup binds chooser and paste once',async({page})=>{
 const s=await setup(page);await page.evaluate(()=>{WorkspaceV2Media.init();WorkspaceV2Media.init()});await page.locator('#replyFile').setInputFiles([files[0]]);await expect(page.locator('.mediaSelectedItem')).toHaveCount(1);
 await page.evaluate(()=>{const dt=new DataTransfer();dt.items.add(new File(['synthetic clipboard'],'Clipboard.txt',{type:'text/plain'}));document.getElementById('replyText').dispatchEvent(new ClipboardEvent('paste',{clipboardData:dt,bubbles:true,cancelable:true}))});await expect(page.locator('.mediaSelectedItem')).toHaveCount(2);await page.locator('#sendReply').click();await expect(page.locator('#replyStatus')).toContainText('Отправлено');expect(s.uploads).toHaveLength(2);expect(s.errors).toEqual([]);
});

test('short screen with search retains file count, current progress and scrollable controls at selection limit',async({page})=>{
 await page.setViewportSize({width:390,height:420});const s=await setup(page,{holdFirst:true});await page.locator('#m2ConversationMenuToggle').click();await page.locator('#conversationSearchOpen').click();await page.locator('#conversationSearchInput').fill('Здравствуйте');await page.locator('#replyText').fill('Файлы');await page.locator('#replyFile').setInputFiles(Array.from({length:10},(_,i)=>({name:'Файл-'+(i+1)+'.txt',mimeType:'text/plain',buffer:Buffer.from('synthetic')})));await expect(page.locator('.mediaSelectedItem')).toHaveCount(10);await expect(page.locator('.mediaPreview').first()).toHaveText('1/10');await page.locator('.mediaRemove').last().focus();await expect(page.locator('.mediaRemove').last()).toBeFocused();await page.locator('.mediaRemove').first().focus();await page.locator('#replyFile').setInputFiles([{name:'Лишний.txt',mimeType:'text/plain',buffer:Buffer.from('synthetic')}]);await expect(page.locator('.mediaSelectedItem')).toHaveCount(10);await expect(page.locator('.mediaSelectionHint')).toContainText('не добавлена');await page.locator('#sendReply').click();await expect(page.locator('.mediaFileSize').first()).toHaveText('Отправляем 1/10');expect(await page.locator('#messages').evaluate(el=>el.clientHeight)).toBeGreaterThanOrEqual(40);const progress=await page.locator('.mediaFileSize').first().boundingBox();expect(progress.y+progress.height).toBeLessThanOrEqual(420);await page.screenshot({path:'manager-media-artifacts/short-search-progress.png'});s.release();await expect(page.locator('#replyStatus')).toContainText('Отправлено');expect(s.uploads).toHaveLength(10);expect(s.errors).toEqual([]);
});
