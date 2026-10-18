const {test,expect}=require('@playwright/test');const {execFileSync}=require('node:child_process');const fs=require('node:fs');const path=require('node:path');
const root=path.resolve(__dirname,'../..'),origin='http://127.0.0.1:4173';
const baseline=process.env.M2_HISTORY_BASELINE==='1',assetRoot=baseline?path.resolve(process.env.M2_HISTORY_BASELINE_ROOT||path.join(root,'../history-qa-baseline')):root;
const shell=baseline?fs.readFileSync(path.join(assetRoot,'shell.html'),'utf8'):execFileSync('php',['manager2/index.php'],{cwd:root,encoding:'utf8'});
function wav(){const pcm=Buffer.alloc(8000*2*30),header=Buffer.alloc(44);header.write('RIFF');header.writeUInt32LE(pcm.length+36,4);header.write('WAVEfmt ',8);header.writeUInt32LE(16,16);header.writeUInt16LE(1,20);header.writeUInt16LE(1,22);header.writeUInt32LE(8000,24);header.writeUInt32LE(16000,28);header.writeUInt16LE(2,32);header.writeUInt16LE(16,34);header.write('data',36);header.writeUInt32LE(pcm.length,40);return Buffer.concat([header,pcm])}
const syntheticAudio=wav();
// Generated silent 20s VP8 color fixture (96x64, 5fps); no provider/customer media.
const syntheticVideo=Buffer.from('GkXfo59ChoEBQveBAULygQRC84EIQoKEd2VibUKHgQJChYECGFOAZwEAAAAAAAtfEU2bdLpNu4tTq4QVSalmU6yBoU27i1OrhBZUrmtTrIHYTbuMU6uEElTDZ1OsggEeTbuMU6uEHFO7a1OsggtJ7AEAAAAAAABZAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAVSalmsirXsYMPQkBNgI1MYXZmNjAuMTYuMTAwV0GNTGF2ZjYwLjE2LjEwMESJiEDTiAAAAAAAFlSua8GuAQAAAAAAADjXgQFzxYj70CvVgdTCupyBACK1nIN1bmSIgQCGhVZfVlA4g4EBI+ODhAvrwgDgibCBYLqBQJqBAhJUw2f8c3OgY8CAZ8iaRaOHRU5DT0RFUkSHjUxhdmY2MC4xNi4xMDBzc9ZjwItjxYj70CvVgdTCumfIoUWjh0VOQ09ERVJEh5RMYXZjNjAuMzEuMTAyIGxpYnZweGfIoUWjiERVUkFUSU9ORIeTMDA6MDA6MjAuMDAwMDAwMDAwAB9DtnVClueBAKO5gQAAgFADAJ0BKmAAQAAARwiFhYiFhIgCAgAGTCfOnTp06dOnTp03wP78Xk///qcf/9Tj//qcf1lAo5aBAMgA0QEAARAQABgAGFgv9AAIhSgAo5aBAZAA0QEAARAQABgAGFgv9AAIhSgAo5aBAlgA0QEAARAQABgAGFgv9AAIhSgAo5aBAyAA0QEAARAQABgAGFgv9AAIhSgAo5aBA+gA0QEAARAQABgAGFgv9AAIhSgAo5aBBLAA0QEAARAQABgAGFgv9AAIhSgAo5aBBXgA0QEAARAQFGAAYWC/0AAiFKAAo5aBBkAA0QEAARAQABgAGFgv9AAIhSgAo5aBBwgA0QEAARAQABgAGFgv9AAIhSgAo5aBB9AA0QEAARAQABgAGFgv9AAIhSgAo5aBCJgA0QEAARAQABgAGFgv9AAIhSgAo5aBCWAA0QEAARAQABgAGFgv9AAIhSgAo5aBCigA0QEAARAQABgAGFgv9AAIhSgAo5aBCvAA0QEAARAQABgAGFgv9AAIhSgAo5aBC7gA0QEAARAQABgAGFgv9AAIhSgAo5aBDIAA0QEAARAQABgAGFgv9AAIhSgAo5aBDUgA0QEAARAQABgAGFgv9AAIhSgAo5aBDhAA0QEAARAQFGAAYWC/0AAiFKAAo5aBDtgA0QEAARAQABgAGFgv9AAIhSgAo5aBD6AA0QEAARAQABgAGFgv9AAIhSgAo5aBEGgA0QEAARAQABgAGFgv9AAIhSgAo5aBETAA0QEAARAQABgAGFgv9AAIhSgAo5aBEfgA0QEAARAQABgAGFgv9AAIhSgAo5aBEsAA0QEAARAQABgAGFgv9AAIhSgAo5aBE4gA0QEAARAQABgAGFgv9AAIhSgAH0O2dUJ054IUUKOWgQAAANEBAAEQEAAYABhYL/QACIUoAKOWgQDIANEBAAEQEAAYABhYL/QACIUoAKOWgQGQANEBAAEQEAAYABhYL/QACIUoAKOWgQJYANEBAAEQEBRgAGFgv9AAIhSgAKOWgQMgANEBAAEQEAAYABhYL/QACIUoAKOWgQPoANEBAAEQEAAYABhYL/QACIUoAKOWgQSwANEBAAEQEAAYABhYL/QACIUoAKOWgQV4ANEBAAEQEAAYABhYL/QACIUoAKOWgQZAANEBAAEQEAAYABhYL/QACIUoAKOWgQcIANEBAAEQEAAYABhYL/QACIUoAKOWgQfQANEBAAEQEAAYABhYL/QACIUoAKOWgQiYANEBAAEQEAAYABhYL/QACIUoAKOWgQlgANEBAAEQEAAYABhYL/QACIUoAKOWgQooANEBAAEQEAAYABhYL/QACIUoAKOWgQrwANEBAAEQEBRgAGFgv9AAIhSgAKOWgQu4ANEBAAEQEAAYABhYL/QACIUoAKOWgQyAANEBAAEQEAAYABhYL/QACIUoAKOWgQ1IANEBAAEQEAAYABhYL/QACIUoAKOWgQ4QANEBAAEQEAAYABhYL/QACIUoAKOWgQ7YANEBAAEQEAAYABhYL/QACIUoAKOWgQ+gANEBAAEQEAAYABhYL/QACIUoAKOWgRBoANEBAAEQEAAYABhYL/QACIUoAKOWgREwANEBAAEQEAAYABhYL/QACIUoAKOWgRH4ANEBAAEQEAAYABhYL/QACIUoAKOWgRLAANEBAAEQEAAYABhYL/QACIUoAKOWgROIANEBAAEQEBRgAGFgv9AAIhSgAB9DtnVCdOeCKKCjloEAAADRAQABEBAAGAAYWC/0AAiFKACjloEAyADRAQABEBAAGAAYWC/0AAiFKACjloEBkADRAQABEBAAGAAYWC/0AAiFKACjloECWADRAQABEBAAGAAYWC/0AAiFKACjloEDIADRAQABEBAAGAAYWC/0AAiFKACjloED6ADRAQABEBAAGAAYWC/0AAiFKACjloEEsADRAQABEBAAGAAYWC/0AAiFKACjloEFeADRAQABEBAAGAAYWC/0AAiFKACjloEGQADRAQABEBAAGAAYWC/0AAiFKACjloEHCADRAQABEBAAGAAYWC/0AAiFKACjloEH0ADRAQABEBAUYABhYL/QACIUoACjloEImADRAQABEBAAGAAYWC/0AAiFKACjloEJYADRAQABEBAAGAAYWC/0AAiFKACjloEKKADRAQABEBAAGAAYWC/0AAiFKACjloEK8ADRAQABEBAAGAAYWC/0AAiFKACjloELuADRAQABEBAAGAAYWC/0AAiFKACjloEMgADRAQABEBAAGAAYWC/0AAiFKACjloENSADRAQABEBAAGAAYWC/0AAiFKACjloEOEADRAQABEBAAGAAYWC/0AAiFKACjloEO2ADRAQABEBAAGAAYWC/0AAiFKACjloEPoADRAQABEBAAGAAYWC/0AAiFKACjloEQaADRAQABEBAUYABhYL/QACIUoACjloERMADRAQABEBAAGAAYWC/0AAiFKACjloER+ADRAQABEBAAGAAYWC/0AAiFKACjloESwADRAQABEBAAGAAYWC/0AAiFKACjloETiADRAQABEBAAGAAYWC/0AAiFKAAfQ7Z1QhTngjzwo5aBAAAA0QEAARAQABgAGFgv9AAIhSgAo5aBAMgA0QEAARAQABgAGFgv9AAIhSgAo5aBAZAA0QEAARAQABgAGFgv9AAIhSgAo5aBAlgA0QEAARAQABgAGFgv9AAIhSgAo5aBAyAA0QEAARAQABgAGFgv9AAIhSgAo5aBA+gA0QEAARAQABgAGFgv9AAIhSgAo5aBBLAA0QEAARAQFGAAYWC/0AAiFKAAo5aBBXgA0QEAARAQABgAGFgv9AAIhSgAo5aBBkAA0QEAARAQABgAGFgv9AAIhSgAo5aBBwgA0QEAARAQABgAGFgv9AAIhSgAo5aBB9AA0QEAARAQABgAGFgv9AAIhSgAo5aBCJgA0QEAARAQABgAGFgv9AAIhSgAo5aBCWAA0QEAARAQABgAGFgv9AAIhSgAo5aBCigA0QEAARAQABgAGFgv9AAIhSgAo5aBCvAA0QEAARAQABgAGFgv9AAIhSgAo5aBC7gA0QEAARAQABgAGFgv9AAIhSgAo5aBDIAA0QEAARAQABgAGFgv9AAIhSgAo5aBDUgA0QEAARAQFGAAYWC/0AAiFKAAo5aBDhAA0QEAARAQABgAGFgv9AAIhSgAo5aBDtgA0QEAARAQABgAGFgv9AAIhSgAo5aBD6AA0QEAARAQABgAGFgv9AAIhSgAo5aBEGgA0QEAARAQABgAGFgv9AAIhSgAHFO7a5G7j7OBALeK94EB8YIBn/CBAw==','base64');
const stage={id:1,stage_key:'working',display_name:'В работе',sort_order:1};
const messages=(total,media=false)=>Array.from({length:total},(_,i)=>({id:i+1,sender_type:i===0?'manager':'customer',manager_id:i===0?7:null,text:'Синтетическое сообщение '+(i+1)+'. Обсуждаем отели, даты и состав туристов.',created_at:'2026-10-14 08:00:00',...(media&&i===total-2?{attachments:[{type:'audio',name:'Синтетическое аудио',url:origin+'/synthetic-history.wav'}]}:{})}));
function deferred(){let resolve;const promise=new Promise(r=>resolve=r);return{promise,resolve}}
async function setup(page,{total=500,media=false,syntheticSend=false,holdLead=false,pageSize=200,summaryAudio=false,httpMedia=false,waiting=false,notificationState='',initialHash='',initialAnonymous=false,initialListFailures=0,holdInitialList=false}={}){
 const state={calls:[],unexpected:[],errors:[],sends:[],payloadBytes:[],older:[],latest:[],leadGate:holdLead?deferred():null,documents:{11:messages(total,media),12:messages(3)},accountId:7,deny:false,unauthorized:false,summary:summaryAudio?{id:0,sender_type:'ai',text:'Синтетическая сводка первого обращения',created_at:'2026-10-14 08:00:00',attachments:[{type:'audio',name:'Синтетическое аудио в сводке',url:origin+'/synthetic-history.wav'}]}:null};if(summaryAudio)state.documents[11].forEach(m=>m.sender_type='customer');
 state.mediaRequests=[];state.mediaFailure='';
 state.logins=[];state.counts=[];state.lists=Array.from({length:initialListFailures},()=>({body:{ok:false,error:'unauthorized'},status:401}));
 state.initialList=holdInitialList?deferred():null;state.initialListEntered=holdInitialList?deferred():null;let initialListPending=holdInitialList;
 if(httpMedia)state.documents[11].forEach(m=>{if(m.id===1||m.id%20===0)m.attachments=[{type:'audio',name:'Аудио '+m.id,url:origin+'/synthetic-demand/'+m.id+'.wav'},{type:'video',name:'Видео '+m.id,url:origin+'/synthetic-demand/'+m.id+'.webm'}]});
 const conversation=id=>({id,customer_id:100+id,manager_id:state.accountId,manager_name:'Синтетический менеджер',status:'manager',channel:'max',source_id:1,source_name:'Синтетический проект',display_name:id===11?'Синтетический клиент А':'Синтетический клиент Б',...(waiting?{awaiting_manager_reply:1}:{})});
 const manager=()=>({id:state.accountId,role:'manager',display_name:'Синтетический менеджер '+state.accountId,is_working:true});
 const lead=id=>({ok:true,can_edit_pipeline:true,pipeline:{stage,stage_history:[],tags:[],outcome:{outcome:'open'}},tasks:[],trip:{city:'Москва',country:'Турция'},contact:{phone:''},source:{project_label:'Синтетический проект',channel:'max'},handoff:{technical_status:'manager',manager_name:conversation(id).manager_name}});
 const detail=data=>{const all=state.documents[data.conversation_id]||[],older=Number(data.before_id)>0,pool=older?all.filter(m=>m.id<Number(data.before_id)):all,rows=pool.slice(-pageSize).map(m=>({...m})),first=rows[0]?.id||0,hasMore=pool.length>rows.length;if(!older&&state.summary)rows.unshift({...state.summary});return{ok:true,conversation:conversation(Number(data.conversation_id)),messages:rows,delivery_failure:null,has_manager_reply:all.some(m=>m.sender_type==='manager'),history:{has_more:hasMore,before_id:first,page_size:pageSize,mode:older?'older':'latest'}}};
 state.unauthorized=initialAnonymous;
 await page.addInitScript(mode=>{
  sessionStorage.clear();const reg={scope:location.origin+'/manager/',active:{state:'activated',postMessage(){}},pushManager:{getSubscription:async()=>null},showNotification:async()=>{throw Error('Unexpected native notification');}};
  let container={register:async()=>reg,ready:Promise.resolve(reg),addEventListener(){}};
  if(mode){
   container=new EventTarget();const f=window.notificationFixture={registrations:0,mutations:0};
   const worker=new EventTarget();worker.state='installing';worker.postMessage=()=>{};
   reg.addEventListener=()=>{};reg.removeEventListener=()=>{};
   if(mode==='held-ready'||mode==='rejected-ready'){reg.active=null;reg.installing=worker;}
   container.register=()=>{f.registrations++;if(mode==='held-register')return new Promise(resolve=>{f.release=()=>resolve(reg)});if(mode==='rejected-register')return Promise.reject(Error('Synthetic registration unavailable'));return Promise.resolve(reg);};
   container.ready=Promise.resolve(reg);
   f.releaseReady=()=>{worker.state='activated';reg.active=worker;worker.dispatchEvent(new Event('statechange'));};
   f.rejectReady=()=>{worker.state='redundant';worker.dispatchEvent(new Event('statechange'));};
   f.emit=id=>container.dispatchEvent(new MessageEvent('message',{data:{type:'OPEN_CONVERSATION',conversationId:id}}));
  }
  Object.defineProperty(navigator,'serviceWorker',{configurable:true,value:container});function FakeNotification(){}FakeNotification.permission='denied';Object.defineProperty(window,'Notification',{configurable:true,value:FakeNotification});Object.defineProperty(window,'PushManager',{configurable:true,value:function(){}});
 },notificationState);
 page.on('pageerror',e=>state.errors.push(e.message));
 await page.route('**/*',async route=>{const req=route.request(),url=new URL(req.url()),method=req.method();const block=action=>{state.unexpected.push(method+' '+url.pathname+(action?' '+action:''));return route.abort()};
 // WebKit exposes local media/emoji object-URL reads to routing. Permit only
 // this fixture's same-origin blobs; external requests remain blocked below.
 if(url.protocol==='blob:'&&url.origin===origin)return route.continue();
 if(httpMedia&&url.origin===origin&&method==='GET'&&/^\/synthetic-demand\/\d+\.(wav|webm)$/.test(url.pathname)){
  const bytes=url.pathname.endsWith('.wav')?syntheticAudio:syntheticVideo,range=req.headers()['range'];
  let body=bytes,status=state.mediaFailure===url.pathname?404:200;
  const headers={'Content-Type':url.pathname.endsWith('.wav')?'audio/wav':'video/webm','Accept-Ranges':'bytes'};
  if(status===404)body=Buffer.alloc(0);
  else if(range){const match=/^bytes=(\d+)-(\d*)$/.exec(range);if(!match)return block('invalid-media-range');const first=Number(match[1]),last=Math.min(bytes.length-1,match[2]?Number(match[2]):bytes.length-1);if(first>last||first>=bytes.length){status=416;body=Buffer.alloc(0);headers['Content-Range']='bytes */'+bytes.length}else{status=206;body=bytes.subarray(first,last+1);headers['Content-Range']='bytes '+first+'-'+last+'/'+bytes.length}}
  headers['Content-Length']=String(body.length);state.mediaRequests.push({path:url.pathname,range:range||'',status,offeredBytes:body.length});
  return route.fulfill({status,headers,body});
 }
 if(url.origin!==origin)return block();if(method==='GET'&&['/manager2/','/manager2/index.php'].includes(url.pathname))return route.fulfill({contentType:'text/html',body:shell});if(method==='GET'&&url.pathname==='/favicon.ico')return route.fulfill({status:204,body:''});if(method==='GET'&&url.pathname==='/synthetic-history.wav')return route.fulfill({contentType:'audio/wav',body:syntheticAudio});
 if(method==='GET'&&(url.pathname.startsWith('/manager/assets/')||url.pathname.startsWith('/manager2/assets/'))){const p=path.resolve(assetRoot,'.'+url.pathname);if(!p.startsWith(assetRoot+path.sep)||!fs.existsSync(p))return block('asset');return route.fulfill({contentType:url.pathname.endsWith('.css')?'text/css':'text/javascript',body:fs.readFileSync(p)})}
 // Optional portraits remain absent for these exact synthetic clients.
 if(method==='GET'&&url.pathname==='/manager/customer-avatar.php'){if([...url.searchParams.keys()].join(',')!=='conversation_id,account,channel'||url.searchParams.get('account')!==String(state.accountId)||url.searchParams.get('channel')!==conversation(Number(url.searchParams.get('conversation_id'))).channel||!['11','12'].includes(url.searchParams.get('conversation_id')))return block();return route.fulfill({status:404,body:''})}
 if(!url.pathname.startsWith('/manager/')||!['GET','POST'].includes(method))return block();let data={};if(method==='POST'){try{data=req.postDataJSON()||{}}catch(e){return block('invalid-json')}}state.calls.push({path:url.pathname,...data});
 const reply=(body,status=200)=>{if(url.pathname==='/manager/api.php'&&data.action==='detail')state.payloadBytes.push(Buffer.byteLength(JSON.stringify(body)));return route.fulfill({status,contentType:'application/json',body:JSON.stringify(body)})};
 if(method==='POST'&&url.pathname==='/manager/personal-workspace-api.php'&&data.action==='snapshot'&&data.csrf==='synthetic-history-csrf')return state.unauthorized?reply({ok:false,error:'unauthorized'},401):reply(require('./fixtures/personal-workspace-default').emptyPersonalWorkspaceSnapshot());
 if(method==='POST'&&url.pathname==='/manager/post-context-api.php'&&data.action==='detail'&&data.csrf==='synthetic-history-csrf'&&[11,12].includes(data.conversation_id)){if(state.unauthorized)return reply({ok:false,error:'unauthorized'},401);if(state.deny)return reply({ok:false,error:'forbidden'},403);return reply({ok:true,conversation_id:data.conversation_id,context:{current:null,history:[]}})}
 if(method==='POST'&&url.pathname==='/manager/quick-replies-api.php'&&data.action==='toolbar'&&data.csrf==='synthetic-history-csrf'){if(state.unauthorized)return reply({ok:false,error:'unauthorized'},401);return reply({ok:true,replies:[],version:'0'.repeat(64),max_replies:100})}
 if(url.pathname==='/manager/api.php'){
  if(data.action==='login'){const pending=state.logins.shift();if(pending?.promise)await pending.promise;state.unauthorized=false;return reply({ok:true,csrf:'synthetic-history-csrf',manager:manager(),projects:[{project_key:'synthetic-main',display_name:'Синтетический проект'}]})}
  if(state.unauthorized)return reply({ok:false,error:'unauthorized'},401);
  if(data.action==='me')return reply({ok:true,csrf:'synthetic-history-csrf',manager:manager(),projects:[{project_key:'synthetic-main',display_name:'Синтетический проект'}]});
  if(data.action==='counts'){const item=state.counts.shift();if(item?.body)return reply(item.body,item.status||200);return reply({ok:true,counts:{waiting:{count:2,unread:0},mine:{count:2}}});}
  if(data.action==='edit_candidates')return reply({ok:true,messages:[],candidates:[]});
  if(data.action==='detail'){if(state.deny)return reply({ok:false,error:'forbidden'},403);if(![11,12].includes(Number(data.conversation_id)))return block('invalid-conversation');if(Object.keys(data).some(key=>!['action','csrf','conversation_id','before_id'].includes(key)))return block('unexpected-detail-field');const queue=Number(data.before_id)>0?state.older:state.latest;let item=queue.shift();if(typeof item==='function')item=await item(data);if(item?.promise)item=await item.promise;if(item?.abort)return route.abort('failed');if(item?.body)return reply(item.body,item.status||200);if(item?.ok===false)return reply(item,item.status||500);return reply(item||detail(data))}
  if(data.action==='send'&&syntheticSend){state.sends.push(data);const all=state.documents[data.conversation_id];all.push({id:Math.max(0,...all.map(m=>m.id))+1,sender_type:'manager',manager_id:state.accountId,text:data.text,created_at:'2026-10-14 08:01:00'});return reply({ok:true})}
 }
 if(url.pathname==='/manager/pipeline-api.php'){
  if(data.csrf!=='synthetic-history-csrf')return block('invalid-csrf');if(data.action==='catalog')return reply({ok:true,stages:[stage],tags:[],outcomes:{open:'В работе'},close_reasons:{}});if(data.action==='filter_options')return reply({ok:true,filters:{sources:[],managers:[manager()]}});if(data.action==='list'){const item=state.lists.shift();if(item?.body)return reply(item.body,item.status||200);const body={ok:true,conversations:[11,12].map(id=>({...conversation(id),can_edit_pipeline:true,lead_stage:stage,last_text:'Синтетическая переписка',last_sender_type:'customer',unread_count:0,lead_outcome:'open'}))};if(initialListPending){initialListPending=false;state.initialListEntered.resolve();await state.initialList.promise;}return reply(body)}if(data.action==='detail'){const held=state.leadGate?await state.leadGate.promise:null;if(held?.body&&Number(data.conversation_id)===11)return reply(held.body,held.status||200);return reply(lead(data.conversation_id))}
 }
 if(url.pathname==='/manager/push-status.php')return reply({ok:true,push_status:{is_working:true,notification_path_usable:false,healthy_subscription_count:0,notification_path_reason:'no_subscription'}});
 if(url.pathname==='/manager/push.php'&&method==='GET'&&url.searchParams.get('action')==='key')return reply({ok:true,public_key:Buffer.from([4,...Array(64).fill(1)]).toString('base64url')});
 if(url.pathname==='/manager/notification-events.php'&&data.action==='poll')return reply({ok:true,cursor:0,events:[]});
 return block(data.action);
 });await page.goto(origin+'/manager2/index.php?history-qa=1'+initialHash);if(holdInitialList)await state.initialListEntered.promise;else if(initialAnonymous||initialListFailures)await expect(page.locator('#managerAuthRecovery')).toBeVisible();else await expect(page.locator('#inboxList .leadItem')).toHaveCount(2);state.snapshot=detail;return state;
}
function clean(state,{sends=0}={}){expect(state.unexpected).toEqual([]);expect(state.errors).toEqual([]);expect(state.sends).toHaveLength(sends)}
async function open(page,id=11){if(await page.evaluate(()=>WorkspaceV2Mobile.isMobile()&&WorkspaceV2Mobile.getScreen()!=='inbox'))await page.locator('#mobileBack').click();await page.locator('#inboxList [data-conversation-id="'+id+'"]').click();await expect(page.locator('#conversationTitle')).toHaveText(id===11?'Синтетический клиент А':'Синтетический клиент Б');await settled(page)}
async function settled(page){await page.evaluate(()=>new Promise(r=>requestAnimationFrame(()=>requestAnimationFrame(r))));if(await page.evaluate(()=>innerWidth<=430&&WorkspaceV2Mobile.isMobile()))await expect.poll(async()=>Math.round((await page.locator('#conversationZone').boundingBox()).x)).toBe(0)}
async function refresh(page){await page.evaluate(()=>WorkspaceV2Conversation.refreshVisible());await settled(page)}
async function revealConversationTool(page,id){const button=page.locator('#'+id),menu=button.locator('xpath=ancestor::details[1]');if(await menu.count()&&!await menu.evaluate(el=>el.open))await menu.locator('summary').click();await expect(button).toBeVisible();return button}
async function useConversationTool(page,id){await (await revealConversationTool(page,id)).click()}
async function anchor(page){return page.locator('#messages').evaluate(box=>{const top=box.getBoundingClientRect().top,row=[...box.querySelectorAll('.msg')].find(n=>n.getBoundingClientRect().bottom>top+1);return{id:row?.dataset.scrollMessageKey,offset:row?.getBoundingClientRect().top-top}})}
async function sameAnchor(page,before){await settled(page);const row=page.locator('#messages [data-scroll-message-key="'+before.id+'"]');await expect(row).toHaveCount(1);const offset=await row.evaluate(el=>el.getBoundingClientRect().top-document.getElementById('messages').getBoundingClientRect().top);expect(Math.abs(offset-before.offset)).toBeLessThanOrEqual(2)}
async function readTop(page){await page.locator('#messages').evaluate(box=>{box.dispatchEvent(new WheelEvent('wheel',{deltaY:-900,bubbles:true}));box.scrollTop=0});await settled(page)}
async function screenshot(page,browser,width,part){const dir=path.join(root,'manager2-artifacts');fs.mkdirSync(dir,{recursive:true});await page.screenshot({path:path.join(dir,`history-${baseline?'before':'after'}-${browser}-${width}-${part}.png`),animations:'disabled'})}

for(const width of[390,430,768,1440])test.describe('Stable transcript '+width,()=>{
 test.use({viewport:{width,height:844}});
 test('appending retains selection and a playing audio node',async({page,browserName})=>{
  const state=await setup(page,{total:35,media:true});await open(page);await expect(page.locator('#messages audio')).toHaveCount(1);await page.locator('#messages audio').evaluate(async a=>{await a.play();await new Promise(r=>a.readyState>=1?r():a.addEventListener('loadedmetadata',r,{once:true}));a.currentTime=5;await new Promise(r=>a.seeking?a.addEventListener('seeked',r,{once:true}):r());window.syntheticPlayingAudio=a;window.syntheticAudioTime=a.currentTime});await page.locator('#messages [data-scroll-message-key="33"] .msgBody').evaluate(el=>{const r=document.createRange();r.selectNodeContents(el);const s=getSelection();s.removeAllRanges();s.addRange(r);window.syntheticSelectedText=s.toString()});await screenshot(page,browserName,width,'unchanged-selection');state.documents[11].push({id:36,sender_type:'customer',text:'Новое синтетическое сообщение после начала аудио',created_at:'2026-10-14 08:01:00'});await refresh(page);await expect(page.locator('#messages')).toContainText('Новое синтетическое сообщение после начала аудио');const retained=await page.evaluate(()=>({selection:getSelection().toString()===window.syntheticSelectedText,audio:document.querySelector('#messages audio')===window.syntheticPlayingAudio,playing:!document.querySelector('#messages audio').paused,time:document.querySelector('#messages audio').currentTime>=window.syntheticAudioTime-.05}));expect(retained).toEqual({selection:true,audio:true,playing:true,time:true});await screenshot(page,browserName,width,'retained-selection');clean(state);
 });
});

// The old automatic read deliberately stays pending across real shell navigation.
const refreshDetails=state=>state.calls.filter(c=>c.path==='/manager/api.php'&&c.action==='detail'&&!c.before_id).length;
// Cold native media requests, not a timing benchmark: all traffic stays in this
// actual-shell fixture, with real WAV/VP8 decoding and byte-range responses.
for(const width of [390,1440])test('history media waits for explicit playback '+width,async({page,browserName})=>{
 await page.setViewportSize({width,height:900});const state=await setup(page,{total:205,httpMedia:true});await open(page);
 await expect(page.locator('#messages audio')).toHaveCount(10);await expect(page.locator('#messages video')).toHaveCount(10);
 await expect(page.locator('#replyText')).toBeEnabled();
 // Native resource selection runs asynchronously after DOM insertion. Settle its
 // actual network lifecycle before counting requests; this is not a latency SLA.
 await page.waitForLoadState('networkidle');
 console.log(JSON.stringify({mediaDemand:browserName,width,phase:'cold-open',requests:state.mediaRequests.length,unique:new Set(state.mediaRequests.map(r=>r.path)).size,offeredBytes:state.mediaRequests.reduce((n,r)=>n+r.offeredBytes,0)}));
 expect(state.mediaRequests).toEqual([]);
 await page.locator('#messages [data-scroll-message-key="200"]').scrollIntoViewIfNeeded();await page.waitForLoadState('networkidle');expect(state.mediaRequests).toEqual([]);await screenshot(page,browserName,width,'media-cold');
 await page.locator('#replyText').fill('Черновик во время прослушивания');await page.locator('#replyFile').setInputFiles({name:'retained.txt',mimeType:'text/plain',buffer:Buffer.from('Synthetic only')});
 const audio=page.locator('#messages [data-scroll-message-key="200"] audio'),video=page.locator('#messages [data-scroll-message-key="200"] video');
 // A native WebKit video outside the clipped transcript may suspend frame
 // progression while remaining unpaused. Play the chosen visible control;
 // don't confuse that browser optimization with lost playback on refresh.
 await readTop(page);
 for(const [kind,node,suffix] of [['audio',audio,'wav'],['video',video,'webm']]){
  await node.scrollIntoViewIfNeeded();
  await node.evaluate(async el=>{el.muted=true;el.fixtureEvents=[];for(const type of ['play','playing','pause','waiting','stalled','error'])el.addEventListener(type,()=>el.fixtureEvents.push({type,time:el.currentTime,paused:el.paused,ready:el.readyState}));await el.play()});
  await node.scrollIntoViewIfNeeded();await settled(page);
  try{await expect.poll(()=>node.evaluate(el=>el.currentTime)).toBeGreaterThan(.1)}catch(error){console.log(JSON.stringify({mediaNativeFailure:kind,browserName,width,state:await node.evaluate(el=>({time:el.currentTime,paused:el.paused,ready:el.readyState,network:el.networkState,box:el.getBoundingClientRect().toJSON(),viewport:innerHeight,hidden:document.hidden,events:el.fixtureEvents}))}));throw error}
  expect(state.mediaRequests.some(r=>r.path==='/synthetic-demand/200.'+suffix)).toBe(true);
  await node.evaluate((el,kind)=>{window['demandPlaying'+kind]=el;window['demandTime'+kind]=el.currentTime},kind);
 }
 expect(new Set(state.mediaRequests.map(r=>r.path))).toEqual(new Set(['/synthetic-demand/200.wav','/synthetic-demand/200.webm']));
 await page.locator('#messages [data-scroll-message-key="200"]').scrollIntoViewIfNeeded();await screenshot(page,browserName,width,'media-explicit-play');
 state.documents[11].push({id:206,sender_type:'customer',text:'Новое сообщение при воспроизведении'});await refresh(page);
 await expect(page.locator('#messages [data-scroll-message-key="206"]')).toHaveCount(1);
 await readTop(page);expect(await page.locator('#messages').evaluate(el=>el.scrollTop)).toBe(0);
 const before=await anchor(page);await useConversationTool(page,'conversationLoadOlder');await expect(page.locator('#messages [data-scroll-message-key="1"]')).toHaveCount(1);await settled(page);await sameAnchor(page,before);
 await expect(page.locator('#messages audio')).toHaveCount(11);await expect(page.locator('#messages video')).toHaveCount(11);await page.waitForLoadState('networkidle');
 expect(new Set(state.mediaRequests.map(r=>r.path))).toEqual(new Set(['/synthetic-demand/200.wav','/synthetic-demand/200.webm']));
 for(const [kind,node] of [['audio',audio],['video',video]])expect(await node.evaluate((el,kind)=>el===window['demandPlaying'+kind]&&!el.paused&&el.currentTime>=window['demandTime'+kind],kind)).toBe(true);
 await expect(page.locator('#replyText')).toHaveValue('Черновик во время прослушивания');await expect(page.locator('#mediaSelection')).toContainText('retained.txt');
 await page.locator('#messages [data-scroll-message-key="1"]').scrollIntoViewIfNeeded();await screenshot(page,browserName,width,'media-older-retained');
 expect(state.calls.filter(c=>c.path==='/manager/api.php'&&c.action==='detail'&&c.before_id)).toHaveLength(1);clean(state);
});
for(const [width,height] of [[390,900],[430,900],[768,900],[1440,900],[390,420]])for(const kind of ['audio','video'])test('native '+kind+' keyboard reading survives incoming messages '+width+'x'+height,async({page,browserName})=>{
 await page.setViewportSize({width,height});const state=await setup(page,{total:205,httpMedia:true});
 for(const message of state.documents[11])if(message.attachments)message.attachments=message.attachments.filter(a=>a.type===kind);
 await open(page);const draft='Черновик при чтении '+kind;await page.locator('#replyText').fill(draft);await page.locator('#replyFile').setInputFiles({name:'native-reading.txt',mimeType:'text/plain',buffer:Buffer.from('Synthetic only')});await page.locator('#replyText').click();
 let focused='';for(let step=0;step<40;step++){await page.keyboard.press('Shift+Tab');focused=await page.evaluate(()=>document.activeElement?.tagName?.toLowerCase());if(focused===kind)break}expect(focused).toBe(kind);await settled(page);
 const before=await page.evaluate(()=>{const media=document.activeElement,rect=media.getBoundingClientRect(),box=document.getElementById('messages').getBoundingClientRect();window.nativeReadingMedia=media;return{offset:rect.top-box.top,visible:rect.bottom>box.top&&rect.top<box.bottom,src:media.getAttribute('src')}});expect(before.visible).toBe(true);
 await screenshot(page,browserName,width,'native-'+kind+'-focus-before-'+height);
 state.documents[11].push({id:206,sender_type:'customer',text:'Новое сообщение при чтении с клавиатуры'});await refresh(page);
 const after=await page.evaluate(()=>{const media=window.nativeReadingMedia,rect=media.getBoundingClientRect(),box=document.getElementById('messages').getBoundingClientRect();return{same:document.activeElement===media&&media.isConnected,offset:rect.top-box.top,visible:rect.bottom>box.top&&rect.top<box.bottom,src:media.getAttribute('src')}});
 expect(after.same).toBe(true);expect(after.visible).toBe(true);expect(Math.abs(after.offset-before.offset)).toBeLessThanOrEqual(2);expect(after.src).toBe(before.src);expect(await page.evaluate(()=>WorkspaceV2Jump.isFollowingLatest())).toBe(false);
 await expect(page.locator('#replyText')).toHaveValue(draft);await expect(page.locator('#mediaSelection')).toContainText('native-reading.txt');await screenshot(page,browserName,width,'native-'+kind+'-focus-retained-'+height);
 await useConversationTool(page,'jumpToLatest');await expect.poll(()=>page.evaluate(()=>WorkspaceV2Jump.distanceFromLatest())).toBeLessThanOrEqual(2);expect(await page.evaluate(()=>WorkspaceV2Jump.isFollowingLatest())).toBe(true);clean(state);
});
for(const [width,height] of [[390,900],[430,900],[768,900],[1440,900],[390,420]])for(const kind of ['file','photo','details'])test('transcript '+kind+' keyboard reading survives incoming messages '+width+'x'+height,async({page,browserName})=>{
 await page.setViewportSize({width,height});const state=await setup(page,{total:205});const message=state.documents[11].find(m=>m.id===200);
 if(kind==='file')message.attachments=[{type:'file',name:'Условия200.pdf',url:origin+'/synthetic-demand/200.pdf'}];
 if(kind==='photo')message.attachments=[{type:'image',name:'Фото200.png',url:origin+'/synthetic-reading.png'}];
 if(kind==='details'){message.direction='inbound';message.service_action={type:'callback',label:'Запросил менеджера',raw_payload:'manager_after_tours'};}
 const photoGate=deferred();if(kind==='photo')await page.route(origin+'/synthetic-reading.png',async route=>{await photoGate.promise;await route.fulfill({contentType:'image/png',body:Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aV1sAAAAASUVORK5CYII=','base64')})});
 await open(page);const draft='Черновик при чтении '+kind;await page.locator('#replyText').fill(draft);await page.locator('#replyFile').setInputFiles({name:'transcript-reading.txt',mimeType:'text/plain',buffer:Buffer.from('Synthetic only')});await page.evaluate(()=>window.transcriptReadingFile=document.getElementById('replyFile').files[0]);await page.locator('#replyText').click();
 const target=kind==='details'?'summary':'a';let focused=false;for(let step=0;step<40;step++){await page.keyboard.press('Shift+Tab');focused=await page.evaluate(target=>document.activeElement?.matches(target)&&document.activeElement.closest('[data-scroll-message-key="200"]')!==null,target);if(focused)break}expect(focused).toBe(true);await settled(page);
 const before=await page.evaluate(()=>{const node=document.activeElement,rect=node.getBoundingClientRect(),box=document.getElementById('messages').getBoundingClientRect();window.transcriptReadingNode=node;return{offset:rect.top-box.top,visible:rect.bottom>box.top&&rect.top<box.bottom}});expect(before.visible).toBe(true);await screenshot(page,browserName,width,'transcript-'+kind+'-before-'+height);
 if(kind==='photo'){photoGate.resolve();await expect.poll(()=>page.locator('#messages [data-scroll-message-key="200"] img').evaluate(img=>img.complete&&img.naturalWidth>0)).toBe(true);await settled(page);const loaded=await page.evaluate(()=>{const node=window.transcriptReadingNode,rect=node.getBoundingClientRect(),box=document.getElementById('messages').getBoundingClientRect();return{same:node===document.activeElement,offset:rect.top-box.top,visible:rect.bottom>box.top&&rect.top<box.bottom}});expect(loaded.same).toBe(true);expect(loaded.visible).toBe(true);expect(Math.abs(loaded.offset-before.offset)).toBeLessThanOrEqual(2);}
 state.documents[11].push({id:206,sender_type:'customer',text:'Новое сообщение во время чтения вложения'});await refresh(page);await expect(page.locator('#messages [data-scroll-message-key="206"]')).toHaveCount(1);
 const after=await page.evaluate(()=>{const node=window.transcriptReadingNode,rect=node.getBoundingClientRect(),box=document.getElementById('messages').getBoundingClientRect();return{same:node===document.activeElement&&node.isConnected,offset:rect.top-box.top,visible:rect.bottom>box.top&&rect.top<box.bottom,file:document.getElementById('replyFile').files[0]===window.transcriptReadingFile}});
 expect(after.same).toBe(true);expect(after.visible).toBe(true);expect(Math.abs(after.offset-before.offset)).toBeLessThanOrEqual(2);expect(after.file).toBe(true);expect(await page.evaluate(()=>WorkspaceV2Jump.isFollowingLatest())).toBe(false);await expect(page.locator('#replyText')).toHaveValue(draft);await screenshot(page,browserName,width,'transcript-'+kind+'-retained-'+height);
 await useConversationTool(page,'jumpToLatest');await expect.poll(()=>page.evaluate(()=>WorkspaceV2Jump.distanceFromLatest())).toBeLessThanOrEqual(2);expect(await page.evaluate(()=>WorkspaceV2Jump.isFollowingLatest())).toBe(true);clean(state);
});
test('history media keeps explicit native error fallback without loading other files',async({page,browserName})=>{
 await page.setViewportSize({width:390,height:420});const state=await setup(page,{total:205,httpMedia:true});await open(page);await page.waitForLoadState('networkidle');expect(state.mediaRequests).toEqual([]);
 for(const [kind,suffix,label] of [['audio','wav','Аудио 200'],['video','webm','Видео 200']]){
  state.mediaFailure='/synthetic-demand/200.'+suffix;const node=page.locator('#messages [data-scroll-message-key="200"] '+kind);
  await node.evaluate(async el=>{el.muted=true;try{await el.play()}catch(e){if(!['NotSupportedError','AbortError'].includes(e.name))throw e}});
  const link=page.locator('#messages [data-scroll-message-key="200"] a').filter({hasText:'Не удалось загрузить: '+label});
  await expect(link).toHaveAttribute('href',origin+state.mediaFailure);await expect(link).toHaveAttribute('target','_blank');
  expect(state.mediaRequests.filter(r=>r.path===state.mediaFailure).every(r=>r.status===404)).toBe(true);
 }
 expect(new Set(state.mediaRequests.map(r=>r.path))).toEqual(new Set(['/synthetic-demand/200.wav','/synthetic-demand/200.webm']));
 await page.locator('#messages [data-scroll-message-key="200"] a').last().scrollIntoViewIfNeeded();await screenshot(page,browserName,390,'media-explicit-failure');clean(state);
});
const refreshLists=state=>state.calls.filter(c=>c.action==='list').length;
async function focusRefresh(page){await page.evaluate(()=>window.dispatchEvent(new Event('focus')))}
async function holdAutomatic(page,state){
 const hold=deferred(),before=refreshDetails(state);state.latest.push(hold);
 await focusRefresh(page);await expect.poll(()=>refreshDetails(state)).toBe(before+1);return hold;
}
async function proveCurrentFlight(page,state,old,id){
 const current=await holdAutomatic(page,state),details=refreshDetails(state),lists=refreshLists(state);
 const obsoleteResponse=page.waitForResponse(r=>r.url().endsWith('/manager/api.php')&&r.status()===401);
 old.resolve({body:{ok:false,error:'unauthorized'},status:401});await (await obsoleteResponse).finished();await settled(page);
 await expect(page.locator('#managerAuthRecovery')).toBeHidden();
 expect(await page.evaluate(()=>WorkspaceV2.S.authExpired)).toBe(false);
 await page.evaluate(()=>{for(let i=0;i<5;i++)window.dispatchEvent(new Event('focus'))});await settled(page);
 expect(refreshDetails(state)).toBe(details);expect(refreshLists(state)).toBe(lists);
 expect(await page.evaluate(()=>WorkspaceV2Conversation.refreshVisible())).toBe(false);
 const all=state.documents[id],nextId=all.at(-1).id+1;
 all.push({id:nextId,sender_type:'customer',text:'Новое входящее без ожидания старого запроса',created_at:'2026-10-14 08:02:00'});
 current.resolve(state.snapshot({conversation_id:id}));
 await expect(page.locator('#messages [data-scroll-message-key="'+nextId+'"]')).toHaveCount(1);
 await focusRefresh(page);await expect.poll(()=>refreshDetails(state)).toBe(details+1);
 await expect(page.locator('#composer')).toBeVisible();return nextId;
}
for(const width of [390,430,768,1440])test('obsolete automatic detail releases the new conversation at '+width+'x420',async({page,browserName})=>{
 await page.setViewportSize({width,height:420});const state=await setup(page,{total:35,media:true});state.documents[12]=messages(35,true);await open(page);
 await page.locator('#replyText').fill('Черновик клиента А');await page.locator('#replyFile').setInputFiles({name:'client-a.txt',mimeType:'text/plain',buffer:Buffer.from('Synthetic A')});
 const old=await holdAutomatic(page,state);
 await open(page,12);await page.locator('#replyText').fill('Точный черновик клиента Б\nБез отправки');await page.locator('#replyFile').setInputFiles({name:'client-b.txt',mimeType:'text/plain',buffer:Buffer.from('Synthetic B')});
 await page.locator('#messages audio').evaluate(async audio=>{await audio.play();window.refreshPlayingAudio=audio});
 await page.locator('#messages').hover();await page.mouse.wheel(0,-20000);
 // WebKit wheel scrolling is asynchronous: capture only after the native scroll
 // reaches the earlier-history boundary, not while its animation is in progress.
 await expect.poll(()=>page.locator('#messages').evaluate(box=>box.scrollTop)).toBe(0);await settled(page);
 expect(await page.evaluate(()=>WorkspaceV2Jump.isFollowingLatest())).toBe(false);const before=await anchor(page);
 await proveCurrentFlight(page,state,old,12);await sameAnchor(page,before);
 expect(await page.locator('#messages audio').evaluate(audio=>audio===window.refreshPlayingAudio&&!audio.paused)).toBe(true);
 await expect(page.locator('#replyText')).toHaveValue('Точный черновик клиента Б\nБез отправки');await expect(page.locator('#mediaSelection')).toContainText('client-b.txt');
 const send=await page.locator('#sendReply').boundingBox();expect(send.y+send.height).toBeLessThanOrEqual(421);expect(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1)).toBe(true);
 await screenshot(page,browserName,width,'refresh-context-short');
 await open(page,11);await expect(page.locator('#replyText')).toHaveValue('Черновик клиента А');await expect(page.locator('#mediaSelection')).toContainText('client-a.txt');
 await open(page,12);await expect(page.locator('#replyText')).toHaveValue('Точный черновик клиента Б\nБез отправки');await expect(page.locator('#mediaSelection')).toContainText('client-b.txt');clean(state);
});
for(const width of [390,1440])for(const owner of [7,8])test('obsolete automatic detail releases recovered account '+owner+' at '+width,async({page,browserName})=>{
 await page.setViewportSize({width,height:900});const state=await setup(page,{total:3});await open(page);
 await page.locator('#replyText').fill('Приватный черновик прежнего аккаунта');await page.locator('#replyFile').setInputFiles({name:'private.txt',mimeType:'text/plain',buffer:Buffer.from('Synthetic private')});
 const old=await holdAutomatic(page,state);state.unauthorized=true;
 await page.evaluate(()=>WorkspaceV2.api('counts').catch(()=>null));await expect(page.locator('#managerAuthRecovery')).toBeVisible();state.accountId=owner;
 await page.locator('#managerAuthLogin').fill('synthetic-owner');await page.locator('#managerAuthPassword').fill('synthetic-password');await page.locator('#managerAuthSubmit').click();
 await expect(page.locator('#managerAuthRecovery')).toBeHidden();if(owner===8)await open(page);else await expect(page.locator('#composer')).toBeVisible();
 await expect(page.locator('#replyText')).toHaveValue(owner===7?'Приватный черновик прежнего аккаунта':'');
 if(owner===7)await expect(page.locator('#mediaSelection')).toContainText('private.txt');else await expect(page.locator('.mediaSelectedItem')).toHaveCount(0);
 await page.locator('#replyText').fill('Текущий подтверждённый аккаунт');await proveCurrentFlight(page,state,old,11);
 expect(await page.evaluate(()=>WorkspaceV2.S.manager.id)).toBe(owner);await expect(page.locator('#replyText')).toHaveValue('Текущий подтверждённый аккаунт');
 await screenshot(page,browserName,width,'refresh-recovery-'+owner);clean(state);
});

// Execute the exact worker with synthetic Clients, then deliver its command via
// the browser's EventTarget to the real receiver/core/Conversation. This does not
// create an OS notification or claim a native notificationclick reproduction.
const notificationWorker=fs.readFileSync(path.join(assetRoot,'manager/sw.js'),'utf8');
async function notificationClick(page,id,definitions=[{path:'/manager/push-enable.php'},{path:'/manager2/admin.php'},{path:'/manager/',focusFails:true},{path:'/manager2/'}]){
 return page.evaluate(async({source,id,definitions})=>{
  const handlers={},calls=[],fallbacks=[];
  const clients=definitions.map(def=>{const client={url:location.origin+def.path,async focus(){calls.push(['focus',def.path]);if(def.focusFails)throw Error('Synthetic unfocusable client');return client;},postMessage(data){calls.push(['post',def.path]);if(/^\/manager2?\/(?:index\.php)?$/.test(def.path))navigator.serviceWorker.dispatchEvent(new MessageEvent('message',{data}));}};return client;});
  const worker={location:{href:location.origin+'/manager/sw.js'},addEventListener(type,fn){handlers[type]=fn;},clients:{matchAll:async()=>clients,openWindow:async url=>{fallbacks.push(url);}}};
  new Function('self','importScripts',source)(worker,()=>{});let wait;
  handlers.notificationclick({notification:{data:{conversationId:id,url:'./'},close(){}},waitUntil(p){wait=p;}});await wait;return{calls,fallbacks};
 },{source:notificationWorker,id,definitions});
}
async function notificationLogin(page){await page.locator('#managerAuthLogin').fill('synthetic-owner');await page.locator('#managerAuthPassword').fill('synthetic-password');await page.locator('#managerAuthSubmit').click();await expect(page.locator('#managerAuthRecovery')).toBeHidden();}

const supersessionDraft='Новый клиент Б после уведомления\n  Точный черновик до повторного входа';
const supersessionFile='notification-supersession-B.txt';
const notificationTargets=state=>state.calls.filter(c=>c.path==='/manager/api.php'&&c.action==='detail'&&!c.before_id).map(c=>Number(c.conversation_id));
async function supersessionStart(page,{width,height=844,withReply=true}={}){
 await page.setViewportSize({width,height});const state=await setup(page,{total:3,notificationState:'held-register',initialHash:'#conversation=11',holdInitialList:true});
 expect(await page.evaluate(()=>WorkspaceV2.S.booting)).toBe(true);expect(notificationTargets(state)).toEqual([]);
 if(withReply){
  const routed=await notificationClick(page,12);expect(routed.calls.filter(c=>c[0]==='post')).toEqual([['post','/manager2/']]);expect(routed.fallbacks).toEqual([]);
  await expect(page.locator('#conversationTitle')).toHaveText('Синтетический клиент Б');await expect(page.locator('#replyText')).toBeEnabled();await settled(page);
  await page.locator('#replyText').fill(supersessionDraft);await page.locator('#replyFile').setInputFiles({name:supersessionFile,mimeType:'text/plain',buffer:Buffer.from('Synthetic supersession file B')});
  await page.evaluate(()=>{window.supersessionFile=document.getElementById('replyFile').files[0];window.supersessionInput=document.getElementById('replyText');});
 }
 return state;
}
async function supersessionExpire(page,state,{alreadyExpired=false}={}){
 if(!alreadyExpired){state.counts.push({body:{ok:false,error:'unauthorized'},status:401});await page.evaluate(()=>WorkspaceV2.api('counts').catch(()=>{}));}
 await expect(page.locator('#managerAuthRecovery')).toBeVisible();
 const oldList=page.waitForResponse(r=>r.url().endsWith('/manager/pipeline-api.php')&&r.request().postDataJSON()?.action==='list');state.initialList.resolve();await(await oldList).finished();
 await expect.poll(()=>page.evaluate(()=>WorkspaceV2.S.booting)).toBe(false);
}
async function supersessionLogin(page,{targetRead=false}={}){
 const inbox=page.waitForResponse(r=>r.url().endsWith('/manager/pipeline-api.php')&&r.request().postDataJSON()?.action==='list');
 const detail=targetRead?page.waitForResponse(r=>r.url().endsWith('/manager/api.php')&&r.request().postDataJSON()?.action==='detail'):null;
 await notificationLogin(page);await(await inbox).finished();if(detail)await(await detail).finished();await page.evaluate(()=>new Promise(r=>requestAnimationFrame(()=>requestAnimationFrame(r))));
}
async function supersessionReply(page){
 await expect.poll(()=>page.evaluate(()=>WorkspaceV2.S.current)).toBe(12);
 await expect(page.locator('#conversationTitle')).toHaveText('Синтетический клиент Б');await expect(page.locator('#replyText')).toBeEnabled();await expect(page.locator('#replyText')).toHaveValue(supersessionDraft);await expect(page.locator('#mediaSelection')).toContainText(supersessionFile);
 expect(await page.evaluate(()=>document.getElementById('replyText')===supersessionInput&&document.getElementById('replyFile').files[0]===supersessionFile)).toBe(true);
}
for(const [width,height] of [[390,844],[430,844],[768,844],[1440,844],[390,420]])test('notification supersession keeps newer reply after startup expiry at '+width+'x'+height,async({page,browserName})=>{
 const state=await supersessionStart(page,{width,height});await supersessionExpire(page,state);await supersessionLogin(page,{targetRead:true});await supersessionReply(page);
 expect(notificationTargets(state)).toEqual([12,12]);expect(await page.evaluate(()=>location.hash)).toBe('');await settled(page);await screenshot(page,browserName,width,'notification-supersession-retained-'+height);
 await notificationClick(page,11);await expect(page.locator('#conversationTitle')).toHaveText('Синтетический клиент А');await expect(page.locator('#replyText')).toHaveValue('');await expect(page.locator('#mediaSelection')).not.toContainText(supersessionFile);await settled(page);
 if(width<=1100){await page.goBack();await expect(page.locator('#conversationTitle')).toHaveText('Синтетический клиент Б');await settled(page);}else await notificationClick(page,12);
 await supersessionReply(page);await page.locator('#replyText').click();await page.locator('#replyText').press('End');const caret=await page.locator('#replyText').evaluate(el=>({start:el.selectionStart,end:el.selectionEnd}));await page.locator('#replyText').press('!');await expect(page.locator('#replyText')).toHaveValue(supersessionDraft.slice(0,caret.start)+'!'+supersessionDraft.slice(caret.end));
 await screenshot(page,browserName,width,'notification-supersession-return-'+height);clean(state);
});
for(const status of [401,403,404,500])test('notification supersession failed newer detail '+status+' never restores obsolete target',async({page,browserName})=>{
 const width=390,state=await supersessionStart(page,{width,withReply:false});state.latest.push({body:{ok:false,error:'synthetic_newer_target'},status});await notificationClick(page,12);
 await expect.poll(()=>notificationTargets(state).length).toBe(1);if(status!==401)await expect(page.locator('#conversationLoadStatus')).toContainText('Не удалось открыть диалог');
 await supersessionExpire(page,state,{alreadyExpired:status===401});await supersessionLogin(page);
 expect(await page.evaluate(()=>WorkspaceV2.S.current)).toBe(0);expect(notificationTargets(state)).toEqual([12]);expect(await page.evaluate(()=>location.hash)).toBe('');await expect(page.locator('#composer')).toBeHidden();
 await notificationClick(page,12);await expect(page.locator('#conversationTitle')).toHaveText('Синтетический клиент Б');await expect(page.locator('#replyText')).toBeEnabled();await page.locator('#replyText').fill('Повторный выбор только по явному действию');await screenshot(page,browserName,width,'notification-supersession-failure-'+status);clean(state);
});
for(const width of [390,1440])test('notification supersession new account discards old reply and File at '+width,async({page,browserName})=>{
 const state=await supersessionStart(page,{width});await supersessionExpire(page,state);state.accountId=8;await supersessionLogin(page);
 expect(await page.evaluate(()=>[WorkspaceV2.S.manager.id,WorkspaceV2.S.current])).toEqual([8,0]);expect(notificationTargets(state)).toEqual([12]);expect(await page.evaluate(()=>location.hash)).toBe('');await expect(page.locator('#replyText')).toHaveValue('');await expect(page.locator('#composer')).toBeHidden();await expect(page.locator('#mediaSelection')).not.toContainText(supersessionFile);expect(await page.locator('#replyFile').evaluate(el=>el.files.length)).toBe(0);
 await notificationClick(page,12);await expect(page.locator('#conversationTitle')).toHaveText('Синтетический клиент Б');await expect(page.locator('#replyText')).toBeEnabled();await expect(page.locator('#replyText')).toHaveValue('');await expect(page.locator('#mediaSelection')).not.toContainText(supersessionFile);expect(await page.locator('#replyFile').evaluate(el=>el.files.length)).toBe(0);await page.locator('#replyText').fill('Только новый аккаунт');await screenshot(page,browserName,width,'notification-supersession-new-account');clean(state);
});

for(const width of [390,430,768,1440])test('notification reauth cold target survives repeated Inbox401 at '+width,async({page,browserName})=>{
 await page.setViewportSize({width,height:844});const state=await setup(page,{total:3,notificationState:'held-register',initialHash:'#conversation=12',initialListFailures:2});
 expect(await page.evaluate(()=>location.hash)).toBe('#conversation=12');expect(refreshDetails(state)).toBe(0);
 await page.locator('#managerAuthLogin').fill('synthetic-owner');await page.locator('#managerAuthPassword').fill('synthetic-password');await page.locator('#managerAuthSubmit').click();
 await expect.poll(()=>refreshLists(state)).toBe(2);await expect(page.locator('#managerAuthSubmit')).toBeEnabled();expect(await page.evaluate(()=>WorkspaceV2.S.authExpired)).toBe(true);expect(await page.evaluate(()=>location.hash)).toBe('#conversation=12');
 await notificationLogin(page);await expect(page.locator('#conversationTitle')).toHaveText('Синтетический клиент Б');await expect(page.locator('#replyText')).toBeEnabled();expect(refreshDetails(state)).toBe(1);expect(await page.evaluate(()=>location.hash)).toBe('');
 await page.locator('#replyText').fill('После повторного входа — нужному клиенту');await screenshot(page,browserName,width,'notification-reauth-cold-target');clean(state);
});

for(const width of [390,430,768,1440])test('notification reauth keeps typed login and pending submit at '+width,async({page,browserName})=>{
 const height=width===390?420:844;await page.setViewportSize({width,height});const state=await setup(page,{total:3,notificationState:'rejected-register',initialAnonymous:true}),password=page.locator('#managerAuthPassword');
 await page.locator('#managerAuthLogin').fill('synthetic-owner');await password.fill('typed-synthetic-password');await password.focus();await password.evaluate(el=>el.setSelectionRange(2,7,'backward'));
 const generation=await page.evaluate(()=>WorkspaceV2.S.authGeneration);await notificationClick(page,12);await notificationClick(page,11);
 await expect(page.locator('#managerAuthLogin')).toHaveValue('synthetic-owner');await expect(password).toHaveValue('typed-synthetic-password');await expect(password).toBeFocused();expect(await password.evaluate(el=>[el.selectionStart,el.selectionEnd,el.selectionDirection])).toEqual([2,7,'backward']);expect(await page.evaluate(()=>WorkspaceV2.S.authGeneration)).toBe(generation);expect(refreshDetails(state)).toBe(0);
 const login=deferred();state.logins.push(login);await page.locator('#managerAuthSubmit').click();await expect(page.locator('#managerAuthSubmit')).toBeDisabled();await expect(page.locator('#managerAuthSubmit')).toHaveText('Входим…');
 await notificationClick(page,12);await expect(page.locator('#managerAuthSubmit')).toBeDisabled();await expect(page.locator('#managerAuthSubmit')).toHaveText('Входим…');await expect(password).toHaveValue('typed-synthetic-password');await screenshot(page,browserName,width,'notification-reauth-pending-submit-'+height);
 login.resolve();await expect(page.locator('#managerAuthRecovery')).toBeHidden();await expect(page.locator('#conversationTitle')).toHaveText('Синтетический клиент Б');await expect(page.locator('#replyText')).toBeEnabled();expect(refreshDetails(state)).toBe(1);expect(state.calls.filter(c=>c.action==='login')).toHaveLength(1);expect(await page.evaluate(()=>location.hash)).toBe('');clean(state);
});

for(const width of [390,1440])test('notification reauth discards old account intent before another Inbox401 at '+width,async({page,browserName})=>{
 await page.setViewportSize({width,height:844});const state=await setup(page,{total:3,notificationState:'rejected-register',initialHash:'#conversation=12',initialListFailures:1});state.accountId=8;state.lists.push({body:{ok:false,error:'unauthorized'},status:401});
 await page.locator('#managerAuthLogin').fill('synthetic-account8');await page.locator('#managerAuthPassword').fill('synthetic-password');await page.locator('#managerAuthSubmit').click();await expect.poll(()=>refreshLists(state)).toBe(2);await expect(page.locator('#managerAuthSubmit')).toBeEnabled();
 expect(await page.evaluate(()=>WorkspaceV2.S.manager.id)).toBe(8);expect(await page.evaluate(()=>WorkspaceV2.S.authExpired)).toBe(true);expect(await page.evaluate(()=>location.hash)).toBe('');
 await notificationLogin(page);expect(await page.evaluate(()=>WorkspaceV2.S.current)).toBe(0);expect(refreshDetails(state)).toBe(0);await open(page,11);await page.locator('#replyText').fill('Только новый аккаунт');await screenshot(page,browserName,width,'notification-reauth-new-account');clean(state);
});

for(const width of [390,1440])test('notification reauth retains the previous draft and File through repeated login at '+width,async({page,browserName})=>{
 await page.setViewportSize({width,height:844});const state=await setup(page,{total:3,notificationState:'held-register'});await open(page);const draft='Точный черновик до повторного входа\n  Продолжение';await page.locator('#replyText').fill(draft);await page.locator('#replyFile').setInputFiles({name:'notification-reauth-retained.txt',mimeType:'text/plain',buffer:Buffer.from('Synthetic reauth retained')});await page.evaluate(()=>window.reauthFile=document.getElementById('replyFile').files[0]);
 state.latest.push({body:{ok:false,error:'unauthorized'},status:401});await refresh(page);await expect(page.locator('#managerAuthRecovery')).toBeVisible();await notificationClick(page,12);state.lists.push({body:{ok:false,error:'unauthorized'},status:401});const lists=refreshLists(state);
 await page.locator('#managerAuthLogin').fill('synthetic-owner');await page.locator('#managerAuthPassword').fill('synthetic-password');await page.locator('#managerAuthSubmit').click();await expect.poll(()=>refreshLists(state)).toBe(lists+1);await expect(page.locator('#managerAuthSubmit')).toBeEnabled();expect(await page.evaluate(()=>location.hash)).toBe('#conversation=12');
 await notificationLogin(page);await expect(page.locator('#conversationTitle')).toHaveText('Синтетический клиент Б');
 if(width<=1100){await page.goBack();await expect(page.locator('#conversationTitle')).toHaveText('Синтетический клиент А');await settled(page);}else await open(page,11);
 await expect(page.locator('#replyText')).toHaveValue(draft);await expect(page.locator('#mediaSelection')).toContainText('notification-reauth-retained.txt');expect(await page.evaluate(()=>document.getElementById('replyFile').files[0]===reauthFile)).toBe(true);await screenshot(page,browserName,width,'notification-reauth-retained-reply');clean(state);
});

for(const [width,height,mode] of [[390,844,'held-register'],[430,844,'rejected-register'],[768,844,'held-register'],[1440,844,'rejected-register'],[390,420,'held-register'],[390,844,'held-ready'],[1440,844,'rejected-ready']])test('notification navigation retains the current reply then opens the next client '+width+'x'+height+' '+mode,async({page,browserName})=>{
 const proof=await prepareRetainedHistory(page,{width,height,notificationState:mode}),state=proof.state;
 const reads=refreshDetails(state),length=await page.evaluate(()=>history.length);
 await page.locator('#replyText').evaluate(el=>{window.notificationFile=document.getElementById('replyFile').files[0];window.notificationInput=el;});
 const routed=await notificationClick(page,11);expect(routed.calls.filter(c=>c[0]==='post')).toEqual([['post','/manager2/']]);expect(routed.fallbacks).toEqual([]);
 await expect.poll(()=>refreshDetails(state)).toBe(reads+1);await assertRetainedHistory(page,proof);expect(await page.evaluate(()=>history.length)).toBe(length);
 expect(await page.evaluate(()=>document.getElementById('replyText')===notificationInput&&document.getElementById('replyFile').files[0]===notificationFile)).toBe(true);
 await page.evaluate(async mode=>{if(mode==='held-register')notificationFixture.release();if(mode==='held-ready')notificationFixture.releaseReady();if(mode==='rejected-ready')notificationFixture.rejectReady();await WorkspaceV2Notifications.init();},mode);
 expect(refreshDetails(state)).toBe(reads+1);expect(await page.evaluate(()=>notificationFixture.registrations)).toBe(1);
 await screenshot(page,browserName,width,'notification-retained-'+mode+'-'+height);
 await notificationClick(page,12);await expect(page.locator('#conversationTitle')).toHaveText('Синтетический клиент Б');await expect(page.locator('#messages .msg')).toHaveCount(3);await expect(page.locator('#replyText')).toBeEnabled();await expect(page.locator('#replyText')).toHaveValue('');await expect(page.locator('#mediaSelection')).not.toContainText('retained-history.txt');
 await page.locator('#replyText').fill('Ответ клиенту Б из уведомления');await page.locator('#replyFile').setInputFiles({name:'notification-B.txt',mimeType:'text/plain',buffer:Buffer.from('Synthetic B')});
 await screenshot(page,browserName,width,'notification-target-'+mode+'-'+height);
 if(width<=1100){await page.goBack();await expect(page.locator('#conversationTitle')).toHaveText('Синтетический клиент А');await settled(page);}else await open(page,11);
 await expect(page.locator('#replyText')).toHaveValue(proof.draft);await expect(page.locator('#mediaSelection')).toContainText('retained-history.txt');await page.locator('#replyText').click();await page.locator('#replyText').press('End');const caret=await page.locator('#replyText').evaluate(el=>({start:el.selectionStart,end:el.selectionEnd}));await page.locator('#replyText').press('!');await expect(page.locator('#replyText')).toHaveValue(proof.draft.slice(0,caret.start)+'!'+proof.draft.slice(caret.end));
 clean(state);
});

for(const status of [401,403,404,500])test('notification navigation current detail '+status+' keeps canonical recovery',async({page,browserName})=>{
 const proof=await prepareRetainedHistory(page,{width:390,notificationState:'ready'}),state=proof.state;state.latest.push({body:{ok:false,error:'synthetic'},status});const reads=refreshDetails(state);
 await notificationClick(page,12,[{path:'/manager2/'}]);await expect.poll(()=>refreshDetails(state)).toBe(reads+1);
 if(status===401){await expect(page.locator('#managerAuthRecovery')).toBeVisible();await expect(page.locator('#composer')).toBeHidden();await notificationLogin(page);await expect(page.locator('#replyText')).toHaveValue(proof.draft);await expect(page.locator('#mediaSelection')).toContainText('retained-history.txt');}else await assertRetainedHistory(page,proof);
 expect(await page.evaluate(()=>WorkspaceV2.S.current)).toBe(11);await screenshot(page,browserName,390,'notification-failure-'+status);clean(state);
});

for(const anonymous of [false,true])test('notification navigation new-tab fragment opens once after login anonymous='+anonymous,async({page,browserName})=>{
 const width=anonymous?390:1440;await page.setViewportSize({width,height:844});const state=await setup(page,{total:3,notificationState:'rejected-register',initialHash:'#conversation=12',initialAnonymous:anonymous});
 if(anonymous)await notificationLogin(page);await expect(page.locator('#conversationTitle')).toHaveText('Синтетический клиент Б');await expect(page.locator('#replyText')).toBeEnabled();expect(refreshDetails(state)).toBe(1);expect(await page.evaluate(()=>location.hash)).toBe('');
 await page.locator('#replyText').fill('Новая вкладка: ответ правильному клиенту');await screenshot(page,browserName,width,'notification-new-tab-'+anonymous);clean(state);
});

for(const status of [200,401])test('notification navigation late detail cannot restore an old account '+status,async({page})=>{
 await page.setViewportSize({width:1440,height:844});const state=await setup(page,{total:3,notificationState:'held-register'});await open(page);await page.locator('#replyText').fill('Приватный текст аккаунта А');await page.locator('#replyFile').setInputFiles({name:'notification-private-A.txt',mimeType:'text/plain',buffer:Buffer.from('Synthetic A')});
 const gate=deferred();state.latest.push(gate);const reads=refreshDetails(state);await notificationClick(page,12,[{path:'/manager2/'}]);await expect.poll(()=>refreshDetails(state)).toBe(reads+1);await page.evaluate(()=>WorkspaceV2.showAuthRecovery());state.accountId=8;await notificationLogin(page);await open(page,11);await page.locator('#replyText').fill('Только аккаунт Б');
 const received=page.waitForResponse(r=>r.url().endsWith('/manager/api.php')&&r.status()===status);gate.resolve(status===200?state.snapshot({conversation_id:12}):{body:{ok:false,error:'unauthorized'},status:401});await(await received).finished();await settled(page);
 await expect(page.locator('#conversationTitle')).toHaveText('Синтетический клиент А');await expect(page.locator('#replyText')).toHaveValue('Только аккаунт Б');await expect(page.locator('#mediaSelection')).not.toContainText('notification-private-A.txt');await expect(page.locator('#managerAuthRecovery')).toBeHidden();expect(await page.evaluate(()=>WorkspaceV2.S.manager.id)).toBe(8);await page.evaluate(()=>notificationFixture.release());clean(state);
});

const olderDetails=state=>state.calls.filter(c=>c.path==='/manager/api.php'&&c.action==='detail'&&Number(c.before_id)>0);
async function prepareRetainedHistory(page,{width=1440,height=844,holdLead=false,viaNext=false,notificationState=''}={}){
 await page.setViewportSize({width,height});const state=await setup(page,{total:800,media:true,holdLead,waiting:viaNext,notificationState});
 if(viaNext){
  await open(page,12);await page.locator('#replyText').fill('Черновик клиента Б перед переходом');
  await page.locator('#replyFile').setInputFiles({name:'original-B.txt',mimeType:'text/plain',buffer:Buffer.from('Synthetic B file')});
  await useConversationTool(page,'nextWaitingConversation');await expect(page.locator('#conversationTitle')).toHaveText('Синтетический клиент А');await settled(page);
 }else await open(page);
 await page.evaluate(()=>WorkspaceV2Conversation.loadOlder());await expect(page.locator('#messages .msg')).toHaveCount(400);
 await page.locator('#replyText').fill('Точный черновик после неудачного перехода\nПродолжаем отвечать клиенту А');
 await page.locator('#replyFile').setInputFiles({name:'retained-history.txt',mimeType:'text/plain',buffer:Buffer.from('Synthetic retained file')});
 await page.locator('#messages audio').evaluate(async audio=>{audio.muted=true;await audio.play();window.retainedPlayingAudio=audio;window.retainedAudioTime=audio.currentTime});
 await readTop(page);await expect.poll(()=>page.locator('#messages').evaluate(box=>box.scrollTop)).toBe(0);
 const before=await anchor(page);
 await page.locator('#messages [data-scroll-message-key="402"] .msgBody').evaluate(el=>{const r=document.createRange();r.selectNodeContents(el);getSelection().removeAllRanges();getSelection().addRange(r);window.retainedSelection=getSelection().toString();window.retainedMessageRow=el.closest('.msg');window.retainedComposer=document.getElementById('composer');window.retainedReply=document.getElementById('replyText')});
 return{state,before,draft:await page.locator('#replyText').inputValue()};
}
async function assertRetainedHistory(page,{before,draft},count=400){
 await expect(page.locator('#conversationTitle')).toHaveText('Синтетический клиент А');await expect(page.locator('#messages .msg')).toHaveCount(count);
 await expect(page.locator('#managerAuthRecovery')).toBeHidden();await expect(page.locator('#replyText')).toBeEnabled();await expect(page.locator('#replyText')).toHaveValue(draft);
 await expect(page.locator('#mediaSelection')).toContainText('retained-history.txt');
 expect(await page.evaluate(()=>document.getElementById('composer')===window.retainedComposer&&document.getElementById('replyText')===window.retainedReply&&document.querySelector('#messages [data-scroll-message-key="402"]')===window.retainedMessageRow)).toBe(true);
 expect(await page.evaluate(()=>getSelection().toString()===window.retainedSelection)).toBe(true);
 expect(await page.locator('#messages audio').evaluate(audio=>audio===window.retainedPlayingAudio&&!audio.paused&&audio.currentTime>=window.retainedAudioTime-.05)).toBe(true);
 await sameAnchor(page,before);
}
for(const [width,height,outcome] of [[390,844,'500'],[430,844,'500'],[768,844,'500'],[1440,844,'500'],[390,420,'500'],...[403,404,'network','application','mismatched'].map(status=>[1440,844,String(status)])]){
 test('retained history survives failed target '+outcome+' at '+width+'x'+height,async({page,browserName})=>{
  const saved=await prepareRetainedHistory(page,{width,height}),{state}=saved,history=await page.evaluate(()=>WorkspaceV2History.getState()),details=refreshDetails(state),older=olderDetails(state).length;
  state.latest.push(outcome==='network'?{abort:true}:outcome==='application'?{body:{ok:false,error:'synthetic_target'},status:200}:outcome==='mismatched'?{body:{...state.snapshot({conversation_id:12}),conversation:{...state.snapshot({conversation_id:12}).conversation,id:999}}}:{body:{ok:false,error:'synthetic_target'},status:Number(outcome)});
  expect(await page.evaluate(()=>WorkspaceV2Conversation.open(12))).toBe(false);await settled(page);
  await expect(page.locator('#conversationLoadStatus')).toContainText('Текущий диалог не изменён');
  expect(await page.evaluate(()=>WorkspaceV2History.getState())).toEqual(history);expect(refreshDetails(state)).toBe(details+1);expect(olderDetails(state)).toHaveLength(older);
  await assertRetainedHistory(page,saved);await refresh(page);await assertRetainedHistory(page,saved);
  expect(await page.evaluate(()=>WorkspaceV2History.getState())).toEqual(history);
  await screenshot(page,browserName,width,'failed-target-'+outcome+'-'+height);
  expect(await page.evaluate(()=>WorkspaceV2Conversation.loadOlder())).toBe(true);await assertRetainedHistory(page,saved,600);
  expect(olderDetails(state)).toHaveLength(older+1);expect(olderDetails(state).at(-1).before_id).toBe(401);
  // A real pointer action transfers native selection into the editor. WebKit's
  // programmatic focus alone can leave the earlier message selection owning input.
  await page.locator('#replyText').click();await expect(page.locator('#replyText')).toBeFocused();await page.locator('#replyText').press('ControlOrMeta+End');await page.locator('#replyText').press('!');await expect(page.locator('#replyText')).toHaveValue(saved.draft+'!');
  expect(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1)).toBe(true);clean(state);
 });
}
for(const width of [390,1440])test('retained history rejects the old older401 while a resumed page is pending at '+width,async({page,browserName})=>{
 const saved=await prepareRetainedHistory(page,{width}),{state}=saved,old=deferred();state.older.push(old);
 await page.evaluate(()=>{void WorkspaceV2Conversation.loadOlder()});await expect(page.locator('#conversationLoadOlder')).toBeDisabled();
 state.latest.push({body:{ok:false,error:'synthetic_target'},status:500});expect(await page.evaluate(()=>WorkspaceV2Conversation.open(12))).toBe(false);
 const current=deferred();state.older.push(current);await page.evaluate(()=>{void WorkspaceV2Conversation.loadOlder()});await expect(page.locator('#conversationLoadOlder')).toBeDisabled();
 const history=await page.evaluate(()=>WorkspaceV2History.getState()),count=state.calls.length;
 const received=page.waitForResponse(r=>r.url().endsWith('/manager/api.php')&&r.status()===401);old.resolve({body:{ok:false,error:'unauthorized'},status:401});await(await received).finished();await settled(page);
 expect(await page.evaluate(()=>WorkspaceV2History.getState())).toEqual(history);expect(state.calls).toHaveLength(count);await assertRetainedHistory(page,saved);
 current.resolve(state.snapshot({conversation_id:11,before_id:401}));await expect(page.locator('#messages .msg')).toHaveCount(600);await assertRetainedHistory(page,saved,600);
 await screenshot(page,browserName,width,'failed-target-late401');clean(state);
});
for(const width of [390,1440])test('retained history search resumes after a failed target and successful navigation starts fresh at '+width,async({page})=>{
 await page.setViewportSize({width,height:844});const state=await setup(page,{total:800});await open(page);await page.evaluate(()=>WorkspaceV2Conversation.loadOlder());await expect(page.locator('#messages .msg')).toHaveCount(400);
 await useConversationTool(page,'conversationSearchOpen');await page.locator('#conversationSearchInput').fill('сообщение');await expect(page.locator('#conversationSearchCount')).toContainText('400');
 state.latest.push({body:{ok:false,error:'synthetic_target'},status:404});expect(await page.evaluate(()=>WorkspaceV2Conversation.open(12))).toBe(false);
 await expect(page.locator('#conversationSearchInput')).toHaveValue('сообщение');await expect(page.locator('#conversationSearchCount')).toContainText('400');await expect(page.locator('#conversationSearchNext')).toBeEnabled();
 await expect(page.locator('#conversationSearchScope')).toContainText('400 загруженным');await page.locator('#conversationSearchNext').click();
 expect(await page.evaluate(()=>WorkspaceV2Conversation.open(12))).toBe(true);await expect(page.locator('#messages .msg')).toHaveCount(3);
 expect(await page.evaluate(()=>WorkspaceV2History.getState().has_more)).toBe(false);await expect(page.locator('#conversationSearchPanel')).toBeHidden();
 await expect(page.locator('#messages [data-scroll-message-key="402"]')).toHaveCount(0);clean(state);
});
test('retained history keeps a disjoint-window gap across failed navigation until explicit pages fill it',async({page})=>{
 await page.setViewportSize({width:430,height:844});const state=await setup(page,{total:800});await open(page);await page.evaluate(()=>WorkspaceV2Conversation.loadOlder());await expect(page.locator('#messages .msg')).toHaveCount(400);
 state.documents[11].push(...messages(400).map(m=>({...m,id:m.id+800})));await refresh(page);
 const history=await page.evaluate(()=>WorkspaceV2History.getState());expect(history.loaded).toBe(600);expect(history.before_id).toBe(1001);expect(history.has_gap).toBe(true);
 state.latest.push({body:{ok:false,error:'synthetic_target'},status:500});expect(await page.evaluate(()=>WorkspaceV2Conversation.open(12))).toBe(false);expect(await page.evaluate(()=>WorkspaceV2History.getState())).toEqual(history);
 await refresh(page);expect(await page.evaluate(()=>WorkspaceV2History.getState())).toEqual(history);await expect(page.locator('#conversationHistoryStatus')).toContainText('промежуток');
 expect(await page.evaluate(()=>WorkspaceV2Conversation.loadOlder())).toBe(true);expect(await page.evaluate(()=>WorkspaceV2History.getState().has_gap)).toBe(true);
 expect(await page.evaluate(()=>WorkspaceV2Conversation.loadOlder())).toBe(true);expect(await page.evaluate(()=>WorkspaceV2History.getState().has_gap)).toBe(false);
 const ids=await page.locator('#messages .msg').evaluateAll(rows=>rows.map(row=>Number(row.dataset.scrollMessageKey)));expect(ids).toEqual(Array.from({length:800},(_,i)=>401+i));expect(new Set(ids).size).toBe(800);clean(state);
});
for(const [width,status,oldStatus] of [[390,500,200],[1440,500,200],[1440,403,200],[1440,404,200],[390,500,401],[1440,500,401]])test('retained card ends loading after failed target '+status+' and old card'+oldStatus+' at '+width,async({page,browserName})=>{
 const saved=await prepareRetainedHistory(page,{width,holdLead:true}),{state}=saved;
 await expect(page.locator('#leadCard')).toContainText('Загружаем карточку');
 state.latest.push({body:{ok:false,error:'synthetic_target'},status});expect(await page.evaluate(()=>WorkspaceV2Conversation.open(12))).toBe(false);
 const count=state.calls.length,old=page.waitForResponse(r=>r.url().endsWith('/manager/pipeline-api.php')&&r.request().postDataJSON()?.action==='detail'&&r.request().postDataJSON()?.conversation_id===11);
 state.leadGate.resolve(oldStatus===200?null:{body:{ok:false,error:'unauthorized'},status:401});state.leadGate=null;await(await old).finished();await settled(page);
 await expect(page.locator('#leadCard')).toContainText('Карточка клиента временно недоступна');await expect(page.locator('#leadCard')).not.toContainText('Загружаем карточку');
 expect(state.calls).toHaveLength(count);expect(await page.evaluate(()=>WorkspaceV2.S.detail.lead)).toBeNull();await assertRetainedHistory(page,saved);
 await refresh(page);await expect(page.locator('#leadCard')).toContainText('Карточка клиента временно недоступна');await assertRetainedHistory(page,saved);
 await page.locator('#conversationTitle').click();await expect(page.locator('#retryLeadCard')).toBeVisible();const reads=state.calls.filter(c=>c.path==='/manager/pipeline-api.php'&&c.action==='detail'&&c.conversation_id===11).length;
 await page.locator('#retryLeadCard').click();await expect(page.locator('#retryLeadCard')).toHaveCount(0);
 expect(await page.evaluate(()=>WorkspaceV2.S.detail.lead?.ok)).toBe(true);expect(state.calls.filter(c=>c.path==='/manager/pipeline-api.php'&&c.action==='detail'&&c.conversation_id===11)).toHaveLength(reads+1);
 await screenshot(page,browserName,width,'failed-target-card-'+status+'-old'+oldStatus);await expect(page.locator('#conversationTitle')).toHaveText('Синтетический клиент А');clean(state);
});

async function backPastPendingClient(page,state){
 const old=deferred(),before=refreshDetails(state),length=await page.evaluate(()=>history.length);state.latest.push(old);
 // Native same-document history, including the actual asynchronous popstate
 // callback. No direct restore() invocation or replacement navigation handler.
 await page.evaluate(()=>history.back());await expect.poll(()=>refreshDetails(state)).toBe(before+1);
 expect(state.calls.filter(c=>c.path==='/manager/api.php'&&c.action==='detail'&&!c.before_id).at(-1).conversation_id).toBe(12);
 await page.evaluate(()=>history.back());await expect(page.locator('#workspaceRoot')).toHaveAttribute('data-mobile-screen','inbox');
 expect(await page.evaluate(()=>WorkspaceV2Conversation.getSavedSelection())).toBe(0);
 return {old,before,length};
}
async function settleBackClient(page,state,old,status){
 const received=page.waitForResponse(r=>r.url().endsWith('/manager/api.php')&&r.request().postDataJSON()?.action==='detail'&&r.request().postDataJSON()?.conversation_id===12&&r.status()===status);
 old.resolve({body:status===200?state.snapshot({conversation_id:12}):{ok:false,error:'synthetic_obsolete'},status});
 await(await received).finished();await settled(page);
}
for(const [width,height] of [[390,844],[430,844],[768,844],[390,420]])for(const status of [200,401])test('mobile history superseded Back '+status+' retains the current transcript before and after native Forward at '+width+'x'+height,async({page,browserName})=>{
 const saved=await prepareRetainedHistory(page,{width,height,viaNext:true}),{state}=saved;
 const history=await page.evaluate(()=>WorkspaceV2History.getState()),generation=await page.evaluate(()=>WorkspaceV2.S.authGeneration);
 const {old,before,length}=await backPastPendingClient(page,state);
 await expect(page.locator('#managerAuthRecovery')).toBeHidden();expect(await page.evaluate(()=>WorkspaceV2.S.current)).toBe(11);
 // Select the existing Forward destination, without an extra detail read for
 // the already retained authorized client. The obsolete HTTP read is still held.
 await page.evaluate(()=>history.go(2));await expect(page.locator('#workspaceRoot')).toHaveAttribute('data-mobile-screen','conversation');
 await assertRetainedHistory(page,saved);expect(await page.evaluate(()=>WorkspaceV2History.getState())).toEqual(history);
 expect(refreshDetails(state)).toBe(before+1);expect(await page.evaluate(()=>history.length)).toBe(length);
 expect(await page.evaluate(()=>history.state.conversation_id)).toBe(11);
 await settleBackClient(page,state,old,status);await assertRetainedHistory(page,saved);
 expect(await page.evaluate(()=>WorkspaceV2.S.authGeneration)).toBe(generation);expect(await page.evaluate(()=>WorkspaceV2Conversation.getSavedSelection())).toBe(11);
 expect(await page.evaluate(()=>WorkspaceV2Mobile.getScreen())).toBe('conversation');expect(await page.evaluate(()=>WorkspaceV2History.getState())).toEqual(history);
 expect(refreshDetails(state)).toBe(before+1);
 expect(await page.evaluate(()=>WorkspaceV2Conversation.loadOlder())).toBe(true);await assertRetainedHistory(page,saved,600);
 await page.locator('#replyText').click();await expect(page.locator('#replyText')).toBeFocused();await page.locator('#replyText').press('ControlOrMeta+End');await page.locator('#replyText').press('!');
 await expect(page.locator('#replyText')).toHaveValue(saved.draft+'!');await expect(page.locator('#mediaSelection')).toContainText('retained-history.txt');
 expect(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1)).toBe(true);
 await screenshot(page,browserName,width,'mobile-back-'+status+'-'+height);clean(state);
});
for(const width of [390,1440])for(const status of [200,401,500])test('mobile history obsolete Back '+status+' cannot hide a newer client at '+width,async({page,browserName})=>{
 const saved=await prepareRetainedHistory(page,{width:390,viaNext:true}),{state}=saved;
 const {old}=await backPastPendingClient(page,state);
 if(width===1440)await page.setViewportSize({width,height:844});
 await page.locator('#inboxList [data-conversation-id="12"] .m2RowOpen').click();await expect(page.locator('#conversationTitle')).toHaveText('Синтетический клиент Б');
 await expect(page.locator('#replyText')).toBeEnabled();await expect(page.locator('#replyText')).toHaveValue('Черновик клиента Б перед переходом');
 await expect(page.locator('#mediaSelection')).toContainText('original-B.txt');
 const draft='Новый ответ клиенту Б после возврата';await page.locator('#replyText').fill(draft);
 const entries=await page.evaluate(()=>({length:history.length,state:history.state})),reads=refreshDetails(state);
 await settleBackClient(page,state,old,status);
 await expect(page.locator('#managerAuthRecovery')).toBeHidden();await expect(page.locator('#composer')).toBeVisible();await expect(page.locator('#replyText')).toBeEnabled();
 await expect(page.locator('#conversationTitle')).toHaveText('Синтетический клиент Б');await expect(page.locator('#messages .msg')).toHaveCount(3);
 await expect(page.locator('#replyText')).toHaveValue(draft);await expect(page.locator('#mediaSelection')).toContainText('original-B.txt');
 expect(await page.evaluate(()=>WorkspaceV2.S.current)).toBe(12);expect(await page.evaluate(()=>WorkspaceV2Conversation.getSavedSelection())).toBe(12);
 if(width===390)expect(await page.evaluate(()=>WorkspaceV2Mobile.getScreen())).toBe('conversation');
 expect(await page.evaluate(()=>({length:history.length,state:history.state}))).toEqual(entries);expect(refreshDetails(state)).toBe(reads);
 await screenshot(page,browserName,width,'mobile-back-newer-'+status);
 await useConversationTool(page,'nextWaitingConversation');await expect(page.locator('#conversationTitle')).toHaveText('Синтетический клиент А');
 await expect(page.locator('#replyText')).toHaveValue(saved.draft);await expect(page.locator('#mediaSelection')).toContainText('retained-history.txt');
 await expect(page.locator('#messages .msg')).toHaveCount(200);expect(await page.evaluate(()=>WorkspaceV2History.getState().loaded)).toBe(200);clean(state);
});
async function holdOlderWithKeyboard(page,state){
 const hold=deferred(),count=olderDetails(state).length;state.older.push(hold);
 const button=await revealConversationTool(page,'conversationLoadOlder');await button.focus();await button.press('Enter');
 await expect.poll(()=>olderDetails(state).length).toBe(count+1);await expect(button).toBeDisabled();
 await page.evaluate(()=>{void WorkspaceV2Conversation.loadOlder();void WorkspaceV2Conversation.loadOlder()});
 expect(olderDetails(state)).toHaveLength(count+1);return hold;
}
for(const width of [390,430,768,1440])for(const height of [844,420])test('older transport obsolete401 leaves the new client usable at '+width+'x'+height,async({page,browserName})=>{
 await page.setViewportSize({width,height});const state=await setup(page,{media:true});state.documents[12]=messages(500,true);await open(page);
 await page.locator('#replyText').fill('Черновик клиента А до загрузки истории');await page.locator('#replyFile').setInputFiles({name:'older-client-a.txt',mimeType:'text/plain',buffer:Buffer.from('Synthetic A')});
 const old=await holdOlderWithKeyboard(page,state);await open(page,12);
 const draft='Точный черновик клиента Б\nБез отправки';await page.locator('#replyText').fill(draft);await page.locator('#replyFile').setInputFiles({name:'older-client-b.txt',mimeType:'text/plain',buffer:Buffer.from('Synthetic B')});
 const current=await holdOlderWithKeyboard(page,state);
 // Establish the same reading anchor across engines without depending on native
 // wheel-delta clamping. The HTTP response still uses core request() auth handling.
 // Native muted playback keeps this check independent of headless audio hardware.
 await page.locator('#messages audio').evaluate(async audio=>{audio.muted=true;await audio.play();window.olderPlayingAudio=audio;window.olderAudioTime=audio.currentTime});
 await readTop(page);await expect.poll(()=>page.locator('#messages').evaluate(box=>box.scrollTop)).toBe(0);
 const before=await anchor(page);await page.locator('#replyText').focus();await page.locator('#replyText').evaluate(el=>el.setSelectionRange(2,9));
 const history=await page.evaluate(()=>WorkspaceV2History.getState()),count=state.calls.length;
 const response=page.waitForResponse(r=>r.url().endsWith('/manager/api.php')&&r.status()===401);old.resolve({body:{ok:false,error:'unauthorized'},status:401});await (await response).finished();await settled(page);
 await screenshot(page,browserName,width,'older401-context-'+height);
 await expect(page.locator('#managerAuthRecovery')).toBeHidden();await expect(page.locator('#composer')).toBeVisible();await expect(page.locator('#replyText')).toBeEnabled();
 expect(await page.evaluate(()=>WorkspaceV2.S.authExpired)).toBe(false);await expect(page.locator('#conversationTitle')).toHaveText('Синтетический клиент Б');
 expect(await page.evaluate(()=>WorkspaceV2History.getState())).toEqual(history);expect(state.calls).toHaveLength(count);expect(olderDetails(state)).toHaveLength(2);
 await expect(page.locator('#replyText')).toHaveValue(draft);await expect(page.locator('#mediaSelection')).toContainText('older-client-b.txt');await expect(page.locator('#replyText')).toBeFocused();
 expect(await page.locator('#replyText').evaluate(el=>[el.selectionStart,el.selectionEnd])).toEqual([2,9]);await sameAnchor(page,before);
 expect(await page.locator('#messages audio').evaluate(audio=>audio===window.olderPlayingAudio&&!audio.paused&&audio.currentTime>=window.olderAudioTime-.05)).toBe(true);
 current.resolve(state.snapshot({conversation_id:12,before_id:301}));await expect(page.locator('#messages .msg')).toHaveCount(400);await sameAnchor(page,before);await expect(page.locator('#replyText')).toBeFocused();
 expect(await page.locator('#messages audio').evaluate(audio=>audio===window.olderPlayingAudio&&!audio.paused)).toBe(true);await expect(page.locator('#conversationHistoryStatus')).toBeEmpty();
 expect(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1)).toBe(true);const send=await page.locator('#sendReply').boundingBox();expect(send.y+send.height).toBeLessThanOrEqual(height+1);
 await open(page,11);await expect(page.locator('#replyText')).toHaveValue('Черновик клиента А до загрузки истории');await expect(page.locator('#mediaSelection')).toContainText('older-client-a.txt');
 await open(page,12);await expect(page.locator('#replyText')).toHaveValue(draft);await expect(page.locator('#mediaSelection')).toContainText('older-client-b.txt');clean(state);
});
for(const width of [390,1440])for(const status of [403,404])test('older transport current'+status+' locks the inaccessible client at '+width+'x420',async({page})=>{
 await page.setViewportSize({width,height:420});const state=await setup(page);await open(page);await page.locator('#replyText').fill('Приватный черновик при смене доступа');await page.locator('#replyFile').setInputFiles({name:'private-access.txt',mimeType:'text/plain',buffer:Buffer.from('Synthetic private')});
 state.older.push({body:{ok:false,error:status===403?'forbidden':'not_found'},status});await useConversationTool(page,'conversationLoadOlder');
 await expect(page.locator('#composer')).toBeHidden();await expect(page.locator('#conversationRefreshStatus')).toContainText('Доступ');await expect(page.locator('#managerAuthRecovery')).toBeHidden();
 await expect(page.locator('#replyText')).toHaveValue('');await expect(page.locator('.mediaSelectedItem')).toHaveCount(0);expect(await page.evaluate(()=>WorkspaceV2History.getState().loaded)).toBe(0);
 const count=olderDetails(state).length;expect(await page.evaluate(()=>WorkspaceV2Conversation.loadOlder())).toBe(false);expect(olderDetails(state)).toHaveLength(count);clean(state);
});
for(const width of [390,1440])test('older transport current500 retains the transcript and permits one explicit retry at '+width+'x420',async({page})=>{
 await page.setViewportSize({width,height:420});const state=await setup(page);await open(page);await readTop(page);const before=await anchor(page);
 await page.locator('#replyText').fill('Черновик после ошибки истории');await page.locator('#replyFile').setInputFiles({name:'history-retry.txt',mimeType:'text/plain',buffer:Buffer.from('Synthetic retry')});state.older.push({body:{ok:false,error:'synthetic_history_failure'},status:500});await useConversationTool(page,'conversationLoadOlder');
 await expect(page.locator('#conversationHistoryStatus')).toContainText('Не удалось');await expect(page.locator('#conversationHistoryStatus')).toBeVisible();await expect(page.locator('#managerAuthRecovery')).toBeHidden();await expect(page.locator('#messages .msg')).toHaveCount(200);await sameAnchor(page,before);
 await expect(page.locator('#composer')).toBeVisible();await expect(page.locator('#replyText')).toHaveValue('Черновик после ошибки истории');await expect(page.locator('#mediaSelection')).toContainText('history-retry.txt');
 const retry=await holdOlderWithKeyboard(page,state);expect(olderDetails(state)).toHaveLength(2);retry.resolve(state.snapshot({conversation_id:11,before_id:301}));await expect(page.locator('#messages .msg')).toHaveCount(400);await sameAnchor(page,before);await expect(page.locator('#conversationHistoryStatus')).toBeEmpty();clean(state);
});

for(const width of[390,430,768,1440])test.describe('History pagination '+width,()=>{
 test.use({viewport:{width,height:844}});
 test('explicit older pages preserve reading anchor, incoming messages and drafts without duplicate refresh rows',async({page,browserName})=>{
  const state=await setup(page,{total:501});await open(page);await expect(page.locator('#messages .msg')).toHaveCount(200);expect(await page.evaluate(()=>WorkspaceV2.S.detail.has_manager_reply)).toBe(true);await expect(page.locator('#messages [data-scroll-message-key="302"]')).toHaveCount(1);await expect(page.locator('#conversationLoadOlder')).toBeEnabled();await page.locator('#replyText').fill('Черновик при чтении старой истории');await page.locator('#replyFile').setInputFiles({name:'synthetic-history-note.txt',mimeType:'text/plain',buffer:Buffer.from('Синтетический файл без отправки')});await readTop(page);const before=await anchor(page);await screenshot(page,browserName,width,'latest-200');await useConversationTool(page,'conversationLoadOlder');await expect(page.locator('#messages .msg')).toHaveCount(400);await sameAnchor(page,before);await expect(page.locator('#replyText')).toHaveValue('Черновик при чтении старой истории');await expect(page.locator('#mediaSelection')).toContainText('synthetic-history-note.txt');
  state.documents[11].push({id:502,sender_type:'customer',text:'Входящее при чтении ранних сообщений',created_at:'2026-10-14 08:01:00'});const reading=await anchor(page);await refresh(page);await expect(page.locator('#messages .msg')).toHaveCount(401);await sameAnchor(page,reading);await expect(page.locator('#messages')).toContainText('Входящее при чтении ранних сообщений');await refresh(page);await expect(page.locator('#messages .msg')).toHaveCount(401);await sameAnchor(page,reading);
  await useConversationTool(page,'conversationLoadOlder');await expect(page.locator('#messages .msg')).toHaveCount(502);const ids=await page.locator('#messages .msg').evaluateAll(rows=>rows.map(r=>Number(r.dataset.scrollMessageKey)));expect(ids).toEqual(Array.from({length:502},(_,i)=>i+1));expect(new Set(ids).size).toBe(502);const olderCount=state.calls.filter(c=>c.action==='detail'&&Number(c.before_id)>0).length;await page.evaluate(()=>WorkspaceV2Conversation.loadOlder());expect(state.calls.filter(c=>c.action==='detail'&&Number(c.before_id)>0)).toHaveLength(olderCount);expect(await page.evaluate(()=>WorkspaceV2History.getState().has_more)).toBe(false);await expect(page.locator('#conversationHistoryControls')).toBeHidden();await screenshot(page,browserName,width,'complete-history');await page.locator('#jumpToLatest').click();await expect.poll(()=>page.locator('#messages').evaluate(b=>b.scrollHeight-b.clientHeight-b.scrollTop)).toBeLessThanOrEqual(2);clean(state);
 });
});

test.describe('History state boundaries',()=>{
 test.use({viewport:{width:1440,height:844}});
 for(const total of[500,501,1001])test('all '+total+' messages remain reachable through bounded explicit pages',async({page})=>{
  const state=await setup(page,{total});await open(page);await expect(page.locator('#messages .msg')).toHaveCount(200);expect(state.calls.filter(c=>c.action==='detail'&&c.path==='/manager/api.php')).toHaveLength(1);for(let step=1;step<Math.ceil(total/200);step++){await useConversationTool(page,'conversationLoadOlder');await expect(page.locator('#messages .msg')).toHaveCount(Math.min(total,(step+1)*200))}await expect(page.locator('#messages [data-scroll-message-key="1"]')).toHaveCount(1);await expect(page.locator('#messages [data-scroll-message-key="'+total+'"]')).toHaveCount(1);const pages=state.calls.filter(c=>c.path==='/manager/api.php'&&c.action==='detail');expect(pages).toHaveLength(Math.ceil(total/200));expect(pages.slice(1).every(c=>Number.isSafeInteger(c.before_id)&&c.before_id>0)).toBe(true);expect(await page.evaluate(()=>WorkspaceV2.S.detail.has_manager_reply)).toBe(true);clean(state);
 });
 test('more than one page of new arrivals never makes the unseen middle slice unreachable',async({page})=>{
  const state=await setup(page,{total:1000});await open(page);await expect(page.locator('#messages [data-scroll-message-key="801"]')).toHaveCount(1);state.documents[11].push(...messages(300).map(m=>({...m,id:m.id+1000,sender_type:'customer'})));await refresh(page);await expect(page.locator('#messages [data-scroll-message-key="1300"]')).toHaveCount(1);expect(await page.evaluate(()=>WorkspaceV2History.getState().has_more)).toBe(true);for(let i=0;i<8&&await page.evaluate(()=>WorkspaceV2History.getState().has_more);i++){await useConversationTool(page,'conversationLoadOlder');await expect.poll(()=>page.evaluate(()=>WorkspaceV2History.getState().paging)).toBe(false)}const ids=await page.locator('#messages .msg').evaluateAll(rows=>rows.map(r=>Number(r.dataset.scrollMessageKey)));expect(ids).toEqual(Array.from({length:1300},(_,i)=>i+1));await expect(page.locator('#messages [data-scroll-message-key="1050"]')).toHaveCount(1);expect(new Set(ids).size).toBe(1300);clean(state);
 });
 test('an older response requested before a new-arrival gap cannot erase its new backfill cursor',async({page})=>{
  const state=await setup(page,{total:1000});await open(page);const oldPage=state.snapshot({conversation_id:11,before_id:801}),hold=deferred();state.older.push(hold);await useConversationTool(page,'conversationLoadOlder');await expect(page.locator('#conversationLoadOlder')).toBeDisabled();state.documents[11].push(...messages(300).map(m=>({...m,id:m.id+1000,sender_type:'customer'})));await refresh(page);hold.resolve(oldPage);await expect.poll(()=>page.evaluate(()=>WorkspaceV2History.getState().paging)).toBe(false);expect(await page.evaluate(()=>WorkspaceV2History.getState().before_id)).toBe(1101);await expect(page.locator('#messages [data-scroll-message-key="601"]')).toHaveCount(0);for(let i=0;i<8&&await page.evaluate(()=>WorkspaceV2History.getState().has_more);i++){await useConversationTool(page,'conversationLoadOlder');await expect.poll(()=>page.evaluate(()=>WorkspaceV2History.getState().paging)).toBe(false)}const ids=await page.locator('#messages .msg').evaluateAll(rows=>rows.map(r=>Number(r.dataset.scrollMessageKey)));expect(ids).toEqual(Array.from({length:1300},(_,i)=>i+1));clean(state);
 });
 test('a synthetic own reply while a gap is open preserves its active backfill cursor',async({page})=>{
  const state=await setup(page,{total:1000,syntheticSend:true});await open(page);state.documents[11].push(...messages(300).map(m=>({...m,id:m.id+1000,sender_type:'customer'})));await refresh(page);expect(await page.evaluate(()=>WorkspaceV2History.getState().before_id)).toBe(1101);await page.locator('#replyText').fill('Синтетический ответ во время незагруженного промежутка');await page.locator('#replyText').press('Control+Enter');await expect(page.locator('#replyStatus')).toHaveText('Отправлено');await expect(page.locator('#messages [data-scroll-message-key="1301"]')).toHaveCount(1);expect(await page.evaluate(()=>WorkspaceV2History.getState().before_id)).toBe(1101);expect(await page.evaluate(()=>WorkspaceV2History.getState().has_gap)).toBe(true);await useConversationTool(page,'conversationLoadOlder');await expect(page.locator('#messages [data-scroll-message-key="1050"]')).toHaveCount(1);expect(await page.evaluate(()=>WorkspaceV2History.getState().has_gap)).toBe(false);for(let i=0;i<7&&await page.evaluate(()=>WorkspaceV2History.getState().has_more);i++){await useConversationTool(page,'conversationLoadOlder');await expect.poll(()=>page.evaluate(()=>WorkspaceV2History.getState().paging)).toBe(false)}const ids=await page.locator('#messages .msg').evaluateAll(rows=>rows.map(r=>Number(r.dataset.scrollMessageKey)));expect(ids).toEqual(Array.from({length:1301},(_,i)=>i+1));clean(state,{sends:1});
 });
 test('history cursors work with nonconsecutive global message IDs',async({page})=>{
  const state=await setup(page,{total:501});state.documents[11].forEach(m=>m.id*=3);await open(page);for(let i=0;i<3&&await page.evaluate(()=>WorkspaceV2History.getState().has_more);i++){await useConversationTool(page,'conversationLoadOlder');await expect.poll(()=>page.evaluate(()=>WorkspaceV2History.getState().paging)).toBe(false)}const ids=await page.locator('#messages .msg').evaluateAll(rows=>rows.map(r=>Number(r.dataset.scrollMessageKey)));expect(ids).toEqual(Array.from({length:501},(_,i)=>(i+1)*3));clean(state);
 });
 test('unchanged id-zero summary audio stays connected and playing across append and older prepend',async({page})=>{
  const state=await setup(page,{summaryAudio:true});await open(page);await expect(page.locator('#messages audio')).toHaveCount(1);await page.locator('#messages audio').evaluate(async a=>{await a.play();a.currentTime=5;await new Promise(r=>a.seeking?a.addEventListener('seeked',r,{once:true}):r());window.syntheticSummaryAudio=a;window.syntheticSummaryTime=a.currentTime});state.summary.created_at='2026-10-14 08:01:00';state.documents[11].push({id:501,sender_type:'customer',text:'Синтетическое новое входящее'});await refresh(page);await useConversationTool(page,'conversationLoadOlder');await expect.poll(()=>page.evaluate(()=>WorkspaceV2History.getState().paging)).toBe(false);expect(await page.evaluate(()=>({identity:document.querySelector('#messages audio')===window.syntheticSummaryAudio,playing:!document.querySelector('#messages audio').paused,time:document.querySelector('#messages audio').currentTime>=window.syntheticSummaryTime-.05}))).toEqual({identity:true,playing:true,time:true});await expect(page.locator('#messages audio')).toHaveCount(1);clean(state);
 });
 test('older failure keeps current transcript and permits one explicit retry; concurrent clicks coalesce',async({page})=>{
  const state=await setup(page);await open(page);await readTop(page);await page.locator('#replyText').fill('Сохраняемый синтетический черновик');state.older.push({abort:true});const before=await anchor(page);await useConversationTool(page,'conversationLoadOlder');await expect(page.locator('#conversationHistoryStatus')).toContainText('Не удалось');await expect(page.locator('#messages .msg')).toHaveCount(200);await sameAnchor(page,before);await expect(page.locator('#replyText')).toHaveValue('Сохраняемый синтетический черновик');await expect(page.locator('#replyText')).toBeEnabled();
  const hold=deferred();state.older.push(hold);await useConversationTool(page,'conversationLoadOlder');await expect(page.locator('#conversationLoadOlder')).toBeDisabled();await page.evaluate(()=>{void WorkspaceV2Conversation.loadOlder();void WorkspaceV2Conversation.loadOlder()});expect(state.calls.filter(c=>c.action==='detail'&&c.before_id>0)).toHaveLength(2);hold.resolve(state.snapshot({conversation_id:11,before_id:301}));await expect(page.locator('#messages .msg')).toHaveCount(400);await sameAnchor(page,before);clean(state);
 });
 test('incoming latest response and a delayed older page merge without losing either slice',async({page})=>{
  const state=await setup(page);await open(page);await readTop(page);const before=await anchor(page),hold=deferred();state.older.push(hold);await useConversationTool(page,'conversationLoadOlder');await expect(page.locator('#conversationLoadOlder')).toBeDisabled();state.documents[11].push({id:501,sender_type:'customer',text:'Входящее во время загрузки предыдущей страницы'});await refresh(page);hold.resolve(state.snapshot({conversation_id:11,before_id:301}));await expect(page.locator('#messages .msg')).toHaveCount(401);await sameAnchor(page,before);await expect(page.locator('#messages [data-scroll-message-key="301"]')).toHaveCount(1);await expect(page.locator('#messages [data-scroll-message-key="501"]')).toHaveCount(1);clean(state);
 });
 test('open message search retains selection in an unchanged marked body when a matching message arrives',async({page})=>{
  const state=await setup(page,{total:35});await open(page);await useConversationTool(page,'conversationSearchOpen');await page.locator('#conversationSearchInput').fill('Синтетическое');await page.locator('#messages [data-scroll-message-key="34"] .msgBody').evaluate(el=>{const walker=document.createTreeWalker(el,NodeFilter.SHOW_TEXT);let n;while((n=walker.nextNode()))if(n.textContent.length>=6){const r=document.createRange();r.setStart(n,0);r.setEnd(n,6);getSelection().removeAllRanges();getSelection().addRange(r);window.syntheticMarkedSelection=getSelection().toString();break}});state.documents[11].push({id:36,sender_type:'customer',text:'Синтетическое новое совпадающее сообщение'});await refresh(page);expect(await page.evaluate(()=>getSelection().toString())).toBe(await page.evaluate(()=>window.syntheticMarkedSelection));await expect(page.locator('#conversationSearchCount')).toContainText('36');await expect(page.locator('#conversationSearchScope')).toContainText('загруженн');clean(state);
 });
 test('latest-page edits and deletion update only affected rows while unrelated media survives',async({page})=>{
  const state=await setup(page,{total:35,media:true});await open(page);await page.locator('#messages audio').evaluate(a=>window.syntheticUnchangedAudio=a);await page.locator('#messages [data-scroll-message-key="30"]').evaluate(r=>window.syntheticUnchangedRow=r);state.documents[11].find(m=>m.id===33).text='Изменённое синтетическое сообщение';state.documents[11]=state.documents[11].filter(m=>m.id!==32);await refresh(page);await expect(page.locator('#messages [data-scroll-message-key="33"]')).toContainText('Изменённое синтетическое сообщение');await expect(page.locator('#messages [data-scroll-message-key="32"]')).toHaveCount(0);expect(await page.evaluate(()=>document.querySelector('#messages audio')===window.syntheticUnchangedAudio)).toBe(true);expect(await page.evaluate(()=>document.querySelector('#messages [data-scroll-message-key="30"]')===window.syntheticUnchangedRow)).toBe(true);clean(state);
 });
 test('a late older response cannot enter another client transcript and file/text drafts stay separate',async({page})=>{
  const state=await setup(page);await open(page);await page.locator('#replyText').fill('Синтетический черновик клиента А');await page.locator('#replyFile').setInputFiles({name:'client-a.txt',mimeType:'text/plain',buffer:Buffer.from('Синтетический A')});const hold=deferred();state.older.push(hold);await useConversationTool(page,'conversationLoadOlder');await open(page,12);await page.locator('#replyText').fill('Синтетический черновик клиента Б');hold.resolve(state.snapshot({conversation_id:11,before_id:301}));await settled(page);await expect(page.locator('#messages .msg')).toHaveCount(3);await expect(page.locator('#replyText')).toHaveValue('Синтетический черновик клиента Б');await expect(page.locator('#mediaSelection')).not.toContainText('client-a.txt');await open(page,11);await expect(page.locator('#replyText')).toHaveValue('Синтетический черновик клиента А');await expect(page.locator('#mediaSelection')).toContainText('client-a.txt');clean(state);
 });
 test('an old account response cannot reintroduce previous history or drafts after account recovery',async({page})=>{
  const state=await setup(page);await open(page);await page.locator('#replyText').fill('Синтетический приватный черновик аккаунта А');const hold=deferred();state.older.push(hold);await useConversationTool(page,'conversationLoadOlder');await page.evaluate(()=>WorkspaceV2.showAuthRecovery());state.accountId=8;await page.locator('#managerAuthLogin').fill('synthetic-account-b');await page.locator('#managerAuthPassword').fill('synthetic-password');await page.locator('#managerAuthSubmit').click();await expect(page.locator('#managerAuthRecovery')).toBeHidden();await open(page,12);await page.locator('#replyText').fill('Аккаунт Б');hold.resolve(state.snapshot({conversation_id:11,before_id:301}));await settled(page);await expect(page.locator('#messages .msg')).toHaveCount(3);await expect(page.locator('#replyText')).toHaveValue('Аккаунт Б');expect(await page.evaluate(()=>WorkspaceV2.S.manager.id)).toBe(8);clean(state);
 });
 test('older transport current401 keeps same-owner draft and recovers canonical auth',async({page})=>{
  const state=await setup(page);await open(page);await page.locator('#replyText').fill('Синтетический черновик до истечения сессии');await page.locator('#replyFile').setInputFiles({name:'history-auth.txt',mimeType:'text/plain',buffer:Buffer.from('Synthetic auth recovery')});state.older.push({body:{ok:false,error:'unauthorized'},status:401});await useConversationTool(page,'conversationLoadOlder');await expect(page.locator('#managerAuthRecovery')).toBeVisible();await expect(page.locator('#composer')).toBeHidden();await page.locator('#managerAuthLogin').fill('synthetic-owner');await page.locator('#managerAuthPassword').fill('synthetic-password');await page.locator('#managerAuthSubmit').click();await expect(page.locator('#managerAuthRecovery')).toBeHidden();await expect(page.locator('#replyText')).toHaveValue('Синтетический черновик до истечения сессии');await expect(page.locator('#mediaSelection')).toContainText('history-auth.txt');await expect(page.locator('#messages .msg')).toHaveCount(200);expect(olderDetails(state)).toHaveLength(1);clean(state);
 });
 test('permission loss while older page is pending locks composer and rejects late history',async({page})=>{
  const state=await setup(page);await open(page);await page.locator('#replyText').fill('Синтетический черновик при смене доступа');const hold=deferred();state.older.push(hold);await useConversationTool(page,'conversationLoadOlder');state.deny=true;await refresh(page);await expect(page.locator('#composer')).toBeHidden();await expect(page.locator('#conversationRefreshStatus')).toContainText('Доступ');const count=await page.locator('#messages .msg').count();hold.resolve(state.snapshot({conversation_id:11,before_id:301}));await settled(page);await expect(page.locator('#messages .msg')).toHaveCount(count);await expect(page.locator('#replyText')).toHaveValue('');clean(state);
 });
 test('secondary card hydration does not delay bounded transcript and synthetic own reply preserves loaded history',async({page})=>{
  const state=await setup(page,{holdLead:true,syntheticSend:true});await open(page);await expect(page.locator('#messages .msg')).toHaveCount(200);await expect(page.locator('#composer')).toBeVisible();await useConversationTool(page,'conversationLoadOlder');await expect(page.locator('#messages .msg')).toHaveCount(400);state.leadGate.resolve();await page.locator('#replyText').fill('Синтетический ответ только в локальном fixture');await page.locator('#replyText').press('Control+Enter');await expect(page.locator('#replyStatus')).toHaveText('Отправлено');await expect(page.locator('#messages')).toContainText('Синтетический ответ только в локальном fixture');await expect(page.locator('#messages [data-scroll-message-key="101"]')).toHaveCount(1);clean(state,{sends:1});
 });
});

for(const width of[390,430,768,1440])test.describe('Short history viewport '+width,()=>{
 test.use({viewport:{width,height:420}});
 test('history, search and selected file leave the reply composer reachable',async({page,browserName})=>{
  const state=await setup(page);await open(page);await page.locator('#replyText').fill('Синтетический черновик\nВторая строка');await page.locator('#replyFile').setInputFiles({name:'short.txt',mimeType:'text/plain',buffer:Buffer.from('Синтетический файл')});await useConversationTool(page,'conversationSearchOpen');await expect(page.locator('#messages')).toBeVisible();const box=await page.locator('#messages').boundingBox(),send=await page.locator('#sendReply').boundingBox();expect(box.height).toBeGreaterThanOrEqual(40);expect(send.y+send.height).toBeLessThanOrEqual(421);expect(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1)).toBe(true);await expect(page.locator('.m2ConversationMenu')).not.toHaveAttribute('open','');await expect(page.locator('#conversationLoadOlder')).toBeHidden();await screenshot(page,browserName,width,'short-search-media');await page.locator('#conversationSearchClose').click();await page.locator('#m2ConversationMenuToggle').click();await expect(page.locator('.m2ConversationMenu')).not.toHaveAttribute('open','');await useConversationTool(page,'conversationLoadOlder');await expect(page.locator('#messages .msg')).toHaveCount(400);await expect(page.locator('#replyText')).toHaveValue('Синтетический черновик\nВторая строка');clean(state);
 });
});

for(const width of[390,430])test.describe('Short history menu lifecycle '+width,()=>{
 test.use({viewport:{width,height:420}});
 test('closed menu older action retains drafts, visible loading and retry status after action refresh',async({page})=>{
  const state=await setup(page);await open(page);await page.locator('#replyText').fill('Синтетический черновик\nВторая строка');await page.locator('#replyFile').setInputFiles({name:'history-menu.txt',mimeType:'text/plain',buffer:Buffer.from('Синтетический файл')});
  const older=page.locator('#conversationLoadOlder');await expect(older).toBeHidden();expect(await older.evaluate(el=>el.closest('.m2ConversationHistoryAction')!==null)).toBe(true);await older.evaluate(el=>window.syntheticOlderButton=el);
  const hold=deferred();state.older.push(hold);await useConversationTool(page,'conversationLoadOlder');await expect(older).toBeDisabled();await expect(page.locator('#conversationHistoryStatus')).toBeVisible();await expect(page.locator('#conversationHistoryStatus')).toContainText('остаются доступны');
  await page.locator('#m2ConversationMenuToggle').click();await expect(older).toBeHidden();await expect(page.locator('#conversationHistoryStatus')).toBeVisible();await refresh(page);expect(await older.evaluate(el=>el===window.syntheticOlderButton)).toBe(true);
  hold.resolve({ok:false,error:'synthetic_history_failure',http_status:500});await expect(page.locator('#conversationHistoryStatus')).toContainText('Не удалось');await expect(page.locator('#conversationHistoryStatus')).toBeVisible();await expect(page.locator('#replyText')).toHaveValue('Синтетический черновик\nВторая строка');await expect(page.locator('#mediaSelection')).toContainText('history-menu.txt');
  await useConversationTool(page,'conversationSearchOpen');const box=await page.locator('#messages').boundingBox(),send=await page.locator('#sendReply').boundingBox();expect(box.height).toBeGreaterThanOrEqual(40);expect(send.y+send.height).toBeLessThanOrEqual(421);await page.locator('#conversationSearchClose').click();await page.locator('#m2ConversationMenuToggle').click();await useConversationTool(page,'conversationLoadOlder');await expect(page.locator('#messages .msg')).toHaveCount(400);await expect(page.locator('#conversationHistoryStatus')).toBeHidden();expect(await older.evaluate(el=>el===window.syntheticOlderButton)).toBe(true);clean(state);
 });
 test('short menu older control and visible gap warning survive a breakpoint with keyboard focus',async({page})=>{
  const state=await setup(page,{total:1000});await open(page);state.documents[11].push(...messages(300).map(m=>({...m,id:m.id+1000,sender_type:'customer'})));await refresh(page);await expect(page.locator('#conversationHistoryStatus')).toContainText('Между загруженными');await expect(page.locator('#conversationHistoryStatus')).toBeVisible();await expect(page.locator('#conversationLoadOlder')).toBeHidden();
  await page.locator('#replyText').fill('Синтетический черновик\nВторая строка');await page.locator('#replyFile').setInputFiles({name:'gap.txt',mimeType:'text/plain',buffer:Buffer.from('Синтетический файл')});await useConversationTool(page,'conversationSearchOpen');expect((await page.locator('#messages').boundingBox()).height).toBeGreaterThanOrEqual(40);expect((await page.locator('#sendReply').boundingBox()).y+44).toBeLessThanOrEqual(421);await page.locator('#conversationSearchClose').click();
  await (await revealConversationTool(page,'conversationLoadOlder')).focus();await page.setViewportSize({width,height:844});await expect(page.locator('#conversationLoadOlder')).toBeFocused();await expect.poll(()=>page.locator('#conversationLoadOlder').evaluate(el=>el.parentElement.id)).toBe('conversationHistoryControls');await page.setViewportSize({width,height:420});await expect(page.locator('#conversationLoadOlder')).toBeFocused();await expect(page.locator('#conversationLoadOlder')).toBeVisible();await page.locator('#conversationLoadOlder').press('Escape');await expect(page.locator('#m2ConversationMenuToggle')).toBeFocused();await expect(page.locator('#conversationLoadOlder')).toBeHidden();await expect(page.locator('#conversationHistoryStatus')).toBeVisible();
  await useConversationTool(page,'conversationLoadOlder');await expect(page.locator('#messages [data-scroll-message-key="1050"]')).toHaveCount(1);expect(await page.evaluate(()=>WorkspaceV2History.getState().has_gap)).toBe(false);clean(state);
 });
});

for(const width of[390,430])test.describe('Short history auth boundaries '+width,()=>{
 test.use({viewport:{width,height:420}});
 test('expired auth blocks history, Search and Next before same-owner mobile recovery',async({page})=>{
  const state=await setup(page);await open(page);await page.locator('#replyText').fill('Синтетический приватный черновик');const hold=deferred();state.older.push(hold);await useConversationTool(page,'conversationLoadOlder');const count=state.calls.filter(c=>c.action==='detail'&&c.path==='/manager/api.php').length;await page.evaluate(()=>WorkspaceV2.showAuthRecovery());await expect(page.locator('#managerAuthRecovery')).toBeVisible();await expect(page.locator('.m2ConversationMenu')).toBeHidden();await expect(page.locator('#conversationLoadOlder')).toBeHidden();
  expect(await page.evaluate(async()=>({history:await WorkspaceV2Conversation.loadOlder(),search:WorkspaceV2Conversation.openSearch(),next:await WorkspaceV2Conversation.nextWaiting()}))).toEqual({history:false,search:false,next:false});expect(state.calls.filter(c=>c.action==='detail'&&c.path==='/manager/api.php')).toHaveLength(count);
  await page.locator('#managerAuthLogin').fill('synthetic-owner');await page.locator('#managerAuthPassword').fill('synthetic-password');await page.locator('#managerAuthSubmit').click();await expect(page.locator('#managerAuthRecovery')).toBeHidden();await open(page);hold.resolve(state.snapshot({conversation_id:11,before_id:301}));await settled(page);await expect(page.locator('#messages .msg')).toHaveCount(200);await expect(page.locator('#replyText')).toHaveValue('Синтетический приватный черновик');await useConversationTool(page,'conversationLoadOlder');await expect(page.locator('#messages .msg')).toHaveCount(400);clean(state);
 });
});
