const {test:base,expect}=require('@playwright/test');
const http=require('node:http');
const fs=require('node:fs');
const os=require('node:os');
const path=require('node:path');

const root=path.resolve(__dirname,'../..');
const sourceRoot=process.env.M2_QA_SOURCE_ROOT||root;
const portrait=Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAEAAAABACAIAAAAlC+aJAAABM0lEQVR4nO2YzQ3CMAxGU8S4nDpIT+zABszAWBy4IIHAP89xovg7VpX9vdhp42y3x73NrFO2Aa8KIFsFkK0CyFYBZOscFPc4rp8P9/2CJ9rwo8RX6+9iMeAW+ute+I5cJIDcGciAAWg9UQwMgM0NwjD9ZxQA8CykvwhVgWwVQLYKIFsAgOdw5j/YVQVaa9aFRM7VWAW0bqipgGwhuSdwpoH3gMQZO5HxI+VLE8/EnVWf0WyF3Av9GFPwbUDuAdV4RZEwAObJ0I/hBUBuFjwYrk1M3e144tgB2BtCczQjAOveE9MCEOHeHFkNEOfeFn/6P7EOIHr5DVkUAH3ca3Mt1kIDSgrQs39UGZepwLASAfTvH3neNSowsgogWwWQrQLIlggg4laZyrtGBUaWFKB/FwkzPgFzBmYhb17hEQAAAABJRU5ErkJggg==','base64');
const etag=(account,channel='telegram')=>'"synthetic-manager-'+account+'-conversation-11-'+channel+'-v1"';
const cookie='synthetic_avatar_session=fixture-session';

// This isolated fixture proves browser HTTP-cache behaviour using the actual
// avatar loader. It is not a PHP authorization test or production measurement.
// Deliberately do not use page.route/context.route: routing disables HTTP cache.
const test=base.extend({
 page:async({browserName,playwright,launchOptions,contextOptions},use)=>{
  // Playwright WebKit's default ephemeral context has no HTTP NetworkCache.
  // Exercise a normal browser profile in every engine, keeping the profile
  // unique to this synthetic test and deleting it after the browser closes.
  const profile=fs.mkdtempSync(path.join(os.tmpdir(),'manager-avatar-http-cache-'+browserName+'-'));
  let context;
  try{
   context=await playwright[browserName].launchPersistentContext(profile,{...launchOptions,...contextOptions,headless:true});
   await use(context.pages()[0]||await context.newPage());
  }finally{
   try{await context?.close();}finally{fs.rmSync(profile,{recursive:true,force:true});}
  }
 },
 avatarServer:async({},use)=>{
  const asset=fs.readFileSync(path.join(sourceRoot,'manager/assets/workspace-v2-customer-avatar.js'));
  const requests=[],unexpected=[];
  let account=7,channel='telegram',ageFirst=false;
  const html=()=>`<!doctype html><html><head><meta charset="utf-8"><style>
   #conversationAvatar{display:block;width:40px;height:40px;overflow:hidden}
   .customerAvatarImage{width:100%;height:100%;object-fit:cover}
   </style></head><body><div id="conversationAvatar">FC</div><div id="inboxList"></div>
   <script>window.WorkspaceV2={S:{manager:{id:${account}},authGeneration:1,authExpired:false,current:11},showAuthRecovery(){this.S.authExpired=true}};
   window.fixtureCustomer={id:11,channel:'${channel}',display_name:'Fixture Customer'};</script>
   <script src="assets/workspace-v2-customer-avatar.js"></script>
   <script>WorkspaceV2CustomerAvatar.enhanceHeader(fixtureCustomer)</script></body></html>`;
  const server=http.createServer((req,res)=>{
   const url=new URL(req.url,'http://127.0.0.1');
   if(req.method==='GET'&&url.pathname==='/manager/cache-fixture.html'){
    // Keep the session cookie stable across account changes. URL scoping must
    // prevent reuse even when Vary: Cookie cannot distinguish the accounts.
    res.writeHead(200,{'Content-Type':'text/html; charset=utf-8','Cache-Control':'no-store','Set-Cookie':cookie+'; Path=/; SameSite=Lax; HttpOnly'});return res.end(html());
   }
   if(req.method==='GET'&&url.pathname==='/manager/assets/workspace-v2-customer-avatar.js'){
    res.writeHead(200,{'Content-Type':'application/javascript','Cache-Control':'no-store'});return res.end(asset);
   }
   if(req.method==='GET'&&url.pathname==='/manager/customer-avatar.php'&&url.searchParams.get('conversation_id')==='11'&&url.searchParams.has('account')&&url.searchParams.has('channel')&&[...url.searchParams.keys()].length===3){
    const requestedAccount=Number(url.searchParams.get('account'));
    const requestedChannel=url.searchParams.get('channel');
    const ifNoneMatch=req.headers['if-none-match']||'';
    const status=requestedAccount!==account||requestedChannel!==channel?403:ifNoneMatch===etag(account,channel)?304:200;
    const body=status===200?portrait:Buffer.alloc(0);
    const age=ageFirst&&requests.length===0?86401:0;
    requests.push({account:requestedAccount,channel:requestedChannel,ifNoneMatch,status,bodyBytes:body.length,cookie:req.headers.cookie||'',age});
    if(status===403){res.writeHead(status,{'Cache-Control':'private, no-store'});return res.end();}
    const headers={'Cache-Control':'private, max-age=86400, must-revalidate','Vary':'Cookie','ETag':etag(account,channel),'X-Content-Type-Options':'nosniff','Date':new Date(Date.now()-age*1000).toUTCString(),'Age':String(age)};
    if(status===200){headers['Content-Type']='image/png';headers['Content-Length']=String(body.length);}
    res.writeHead(status,headers);return res.end(body);
   }
   if(req.method==='GET'&&url.pathname==='/favicon.ico'){res.writeHead(204);return res.end();}
   unexpected.push(req.method+' '+url.pathname+url.search);res.writeHead(404,{'Cache-Control':'no-store'});res.end();
  });
  await new Promise((resolve,reject)=>{server.once('error',reject);server.listen(0,'127.0.0.1',resolve);});
  const origin='http://127.0.0.1:'+server.address().port;
  try{await use({url:origin+'/manager/cache-fixture.html',requests,unexpected,ageFirstResponse(){ageFirst=true},switchAccount(id){account=id},switchChannel(value){channel=value}});}
  finally{await new Promise(resolve=>{server.close(resolve);server.closeAllConnections();});}
 }
});

async function decoded(page){
 const image=page.locator('#conversationAvatar .customerAvatarImage');
 await expect(image).toHaveCount(1);
 await expect.poll(()=>image.evaluate(img=>img.complete&&img.naturalWidth)).toBe(64);
 expect(await image.getAttribute('src')).toMatch(/^blob:/);
}
async function rerenderHeader(page){
 await page.evaluate(()=>{for(let i=0;i<3;i++)WorkspaceV2CustomerAvatar.enhanceHeader(fixtureCustomer);});
 // Allow visibility callbacks to run before asserting the absence of requests.
 await page.waitForTimeout(100);
}

test('fresh daily browser cache survives full reload without an avatar HTTP request',async({page,avatarServer,browserName})=>{
 const errors=[];page.on('pageerror',error=>errors.push(error.message));
 await page.goto(avatarServer.url);await decoded(page);
 expect(avatarServer.requests).toEqual([{account:7,channel:'telegram',ifNoneMatch:'',status:200,bodyBytes:portrait.length,cookie,age:0}]);
 await rerenderHeader(page);await decoded(page);expect(avatarServer.requests).toHaveLength(1);
 await page.reload();await decoded(page);expect(avatarServer.requests).toHaveLength(1);
 await rerenderHeader(page);await decoded(page);expect(avatarServer.requests).toHaveLength(1);
 expect(avatarServer.unexpected).toEqual([]);expect(errors).toEqual([]);
 console.log(JSON.stringify({fixture:'loopback HTTP + actual avatar JS; no routing or provider',browser:browserName,metric:'avatar-daily-cache',initialPhotoRequests:1,reloadAdditionalRequests:0,initialPhotoBytes:portrait.length,reloadPhotoBytes:0,samePageAdditionalRequests:0,production:false}));
});

test('an entry older than one day revalidates once and renews the browser cache',async({page,avatarServer,browserName})=>{
 const errors=[];page.on('pageerror',error=>errors.push(error.message));
 // Date and Age make the HTTP entry stale without waiting or changing the
 // browser clock. The decoded in-page object URL is still immediately usable.
 avatarServer.ageFirstResponse();
 await page.goto(avatarServer.url);await decoded(page);expect(avatarServer.requests).toHaveLength(1);
 expect(avatarServer.requests[0].age).toBe(86401);
 await page.reload();await decoded(page);await expect.poll(()=>avatarServer.requests.length).toBe(2);
 expect(avatarServer.requests[1]).toEqual({account:7,channel:'telegram',ifNoneMatch:etag(7),status:304,bodyBytes:0,cookie,age:0});
 await page.reload();await decoded(page);expect(avatarServer.requests).toHaveLength(2);
 expect(avatarServer.unexpected).toEqual([]);expect(errors).toEqual([]);
 console.log(JSON.stringify({fixture:'loopback HTTP + actual avatar JS; initial Age=86401',browser:browserName,metric:'avatar-expired-daily-cache',httpStatuses:avatarServer.requests.map(r=>r.status),bodyBytes:avatarServer.requests.map(r=>r.bodyBytes),production:false}));
});

test('account and channel changes cannot reuse a fresh photo cached under the prior URL',async({page,avatarServer})=>{
 const errors=[];page.on('pageerror',error=>errors.push(error.message));
 await page.goto(avatarServer.url);await decoded(page);expect(avatarServer.requests).toHaveLength(1);
 avatarServer.switchAccount(8);await page.reload();await decoded(page);
 expect(avatarServer.requests).toEqual([
  {account:7,channel:'telegram',ifNoneMatch:'',status:200,bodyBytes:portrait.length,cookie,age:0},
  {account:8,channel:'telegram',ifNoneMatch:'',status:200,bodyBytes:portrait.length,cookie,age:0}
 ]);
 await page.reload();await decoded(page);expect(avatarServer.requests).toHaveLength(2);
 avatarServer.switchChannel('max');await page.reload();await decoded(page);
 expect(avatarServer.requests).toHaveLength(3);
 expect(avatarServer.requests[2]).toEqual({account:8,channel:'max',ifNoneMatch:'',status:200,bodyBytes:portrait.length,cookie,age:0});
 await page.reload();await decoded(page);expect(avatarServer.requests).toHaveLength(3);
 expect(avatarServer.unexpected).toEqual([]);expect(errors).toEqual([]);
});

test('canonical authentication reset removes a daily cached photo without loading it again',async({page,avatarServer})=>{
 const errors=[];page.on('pageerror',error=>errors.push(error.message));
 await page.goto(avatarServer.url);await decoded(page);expect(avatarServer.requests).toHaveLength(1);
 await page.evaluate(()=>{
  WorkspaceV2.S.authExpired=true;WorkspaceV2.S.authGeneration++;
  WorkspaceV2CustomerAvatar.resetSession();
 });
 await expect(page.locator('#conversationAvatar .customerAvatarImage')).toHaveCount(0);
 await rerenderHeader(page);expect(avatarServer.requests).toHaveLength(1);
 await expect(page.locator('#conversationAvatar .customerAvatarImage')).toHaveCount(0);
 expect(avatarServer.unexpected).toEqual([]);expect(errors).toEqual([]);
});
