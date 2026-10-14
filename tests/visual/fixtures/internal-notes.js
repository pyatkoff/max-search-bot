const {expect}=require('@playwright/test');
const {execFileSync}=require('node:child_process');
const fs=require('node:fs'),path=require('node:path');
const root=path.resolve(__dirname,'../../..'),origin='http://127.0.0.1:4173';
const clone=value=>JSON.parse(JSON.stringify(value));
function wav(){const pcm=Buffer.alloc(8000*2*30),header=Buffer.alloc(44);header.write('RIFF');header.writeUInt32LE(pcm.length+36,4);header.write('WAVEfmt ',8);header.writeUInt32LE(16,16);header.writeUInt16LE(1,20);header.writeUInt16LE(1,22);header.writeUInt32LE(8000,24);header.writeUInt32LE(16000,28);header.writeUInt16LE(2,32);header.writeUInt16LE(16,34);header.write('data',36);header.writeUInt32LE(pcm.length,40);return Buffer.concat([header,pcm])}
// Actual PHP and production assets; all reads/mutations are local synthetic
// responses. No production connection, customer Send, CRM or push subscription.
async function setup(page,options={}){
 const sourceRoot=options.sourceRoot||root,shell=execFileSync('php',['manager2/index.php'],{cwd:sourceRoot,encoding:'utf8'});
 const calls=[],unexpected=[],errors=[],pending=new Set(),heldLists=[],heldWrites=[],receipts=new Map();
 let manager={id:7,role:options.role||'manager',display_name:'Мария',is_working:true},holdList=!!options.holdList,holdWrite=!!options.holdWrite,listError=!!options.listError,writeResult=options.writeResult||'success',deny=false,foundFalse=false,nextId=1000;
 const notes=options.notes||Array.from({length:options.total??3},(_,i)=>({id:i+1,conversation_id:11,author_id:i===0?8:7,author_name:i===0?'Ольга':'Мария',body:'Внутренняя заметка '+(i+1),version:1,created_at:'2026-10-14 10:00:00',updated_at:null,deleted:false}));
 const conv=id=>({id,customer_id:id+90,manager_id:manager.id,manager_name:manager.display_name,status:'manager',channel:'telegram',source_id:1,display_name:id===11?'Анна Морозова':'Алексей Иванов',original_display_name:id===11?'Анна Морозова':'Алексей Иванов',working_name:null,customer_name_version:0});
 const messages=Array.from({length:35},(_,i)=>({id:i+1,sender_type:i%3?'customer':'manager',manager_id:i%3?null:7,text:'Сообщение '+(i+1)+'. Подбираем отдых у моря.',created_at:'2026-10-14 10:00:00',...(options.media&&i===33?{attachments:[{type:'audio',name:'Синтетическое аудио',url:origin+'/synthetic-notes.wav'}]}:{})}));
 const view=(note,owner=manager.id,role=manager.role)=>({...clone(note),body:note.deleted?'':note.body,can_edit:!note.deleted&&(role==='admin'||note.author_id===owner)});
 const resultList=(d,owner,role)=>{const all=notes.filter(n=>n.conversation_id===Number(d.conversation_id)&&(!d.before_id||n.id<Number(d.before_id))).sort((a,b)=>a.id-b.id),items=all.slice(-50);return {ok:true,notes:items.map(n=>view(n,owner,role)),history:{has_more:all.length>items.length,before_id:items[0]?.id||0,page_size:50,mode:d.before_id?'older':'latest'}}};
 page.on('request',r=>pending.add(r));page.on('requestfinished',r=>pending.delete(r));page.on('requestfailed',r=>pending.delete(r));page.on('pageerror',e=>errors.push(e.message));
 await page.addInitScript(()=>{
  window.notesFixtureTiming={};const observer=new MutationObserver(()=>{if(!notesFixtureTiming.inboxMs&&document.querySelector('#inboxList .leadItem'))notesFixtureTiming.inboxMs=performance.now()});observer.observe(document,{subtree:true,childList:true});
  const reg={scope:location.origin+'/manager/',active:{postMessage(){}},pushManager:{getSubscription:async()=>null},showNotification:async()=>{}};
  Object.defineProperty(navigator,'serviceWorker',{configurable:true,value:{register:async()=>reg,ready:Promise.resolve(reg),addEventListener(){}}});function N(){}N.permission='default';Object.defineProperty(window,'Notification',{configurable:true,value:N});Object.defineProperty(window,'PushManager',{configurable:true,value:function(){}});
 });
 await page.route('**/*',async route=>{
  const req=route.request(),url=new URL(req.url()),method=req.method(),reply=(body,status=200)=>route.fulfill({status,contentType:'application/json',body:JSON.stringify(body)});
  const block=()=>{unexpected.push(method+' '+url.pathname);return route.abort()};
  if(url.protocol==='blob:'&&url.origin===origin)return route.continue();
  if(url.origin!==origin)return block();
  if(method==='GET'&&['/manager2/','/manager2/index.php'].includes(url.pathname))return route.fulfill({contentType:'text/html',body:shell});
  if(method==='GET'&&url.pathname==='/favicon.ico')return route.fulfill({status:204,body:''});
  if(method==='GET'&&url.pathname==='/manager/sw.js')return route.fulfill({contentType:'application/javascript',body:''});
  if(method==='GET'&&url.pathname==='/synthetic-notes.wav')return route.fulfill({contentType:'audio/wav',body:wav()});
  if(method==='GET'&&/^\/manager2?\/assets\/[\w.-]+$/.test(url.pathname))return route.fulfill({contentType:url.pathname.endsWith('.css')?'text/css':'application/javascript',body:fs.readFileSync(path.join(sourceRoot,url.pathname.slice(1)))});
  if(!url.pathname.startsWith('/manager/'))return block();
  let d={};if(method==='POST'){try{d=req.postDataJSON()||{}}catch{return block()}}
  const owner=manager.id,role=manager.role;calls.push({path:url.pathname,owner,payload_bytes:Buffer.byteLength(req.postData()||''),...d});
  if(method==='GET'&&url.pathname==='/manager/customer-avatar.php'){if([...url.searchParams.keys()].join(',')!=='conversation_id'||![11,12].includes(Number(url.searchParams.get('conversation_id'))))return block();return route.fulfill({status:404,body:''})}
  if(method==='POST'&&d.csrf!=='fixture-csrf'&&!['me','login'].includes(d.action))throw new Error('Canonical CSRF missing: '+url.pathname+' '+d.action);
  if(url.pathname==='/manager/api.php'){
   if(d.action==='me'||d.action==='login')return reply({ok:true,manager:clone(manager),csrf:'fixture-csrf',projects:[]});
   if(d.action==='detail'&&[11,12].includes(Number(d.conversation_id)))return reply({ok:true,conversation:conv(d.conversation_id),messages,delivery_failure:null,has_manager_reply:true,history:{has_more:false,before_id:1,page_size:200,mode:'latest'}});
   if(d.action==='counts')return reply({ok:true,counts:{waiting:{count:2,unread:2},mine:{count:2}}});
   if(d.action==='edit_candidates')return reply({ok:true,messages:[],candidates:[]});
  }
  if(url.pathname==='/manager/pipeline-api.php'){
   if(d.action==='catalog')return reply({ok:true,stages:[],tags:[],outcomes:{open:'В работе'},close_reasons:{}});
   if(d.action==='filter_options')return reply({ok:true,filters:{sources:[],managers:[clone(manager)]}});
   if(d.action==='list')return reply({ok:true,conversations:[11,12].map(id=>({...conv(id),project_label:'AnyTour',origin_label:'TG · реклама',last_text:'Ждём варианты у моря',last_sender_type:'customer',unread_count:id===11?2:0,awaiting_manager_reply:1,lead_outcome:'open'}))});
   if(d.action==='detail')return reply({ok:true,can_edit_pipeline:true,pipeline:{stage:null,tags:[],stage_history:[],outcome:{outcome:'open'}},tasks:[],trip:{city:'Москва',country:'Турция',adults:2},contact:{},source:{},handoff:{}});
  }
  if(url.pathname==='/manager/quick-replies-api.php'&&d.action==='toolbar')return reply({ok:true,replies:[],version:'0'.repeat(64),max_replies:100});
  if(url.pathname==='/manager/customer-name-api.php'&&d.action==='get'&&[11,12].includes(Number(d.conversation_id))){const c=conv(Number(d.conversation_id));return reply({ok:true,name:{customer_id:c.customer_id,display_name:c.display_name,original_display_name:c.original_display_name,working_name:null,version:0,updated_at:null,updated_by_manager_name:null,can_edit:true},history:[],history_has_more:false})}
  if(url.pathname==='/manager/post-context-api.php'&&d.action==='detail'&&[11,12].includes(d.conversation_id))return reply({ok:true,conversation_id:d.conversation_id,context:{current:null,history:[]}});
  if(url.pathname==='/manager/personal-workspace-api.php'&&d.action==='snapshot')return reply({ok:true,version:0,state:{tabs:[],labels:[],members:{},pins:[],settings:{start_tab:'waiting',density:'comfortable',font:'normal'}}});
  if(url.pathname==='/manager/internal-notes-api.php'){
   if(![11,12].includes(Number(d.conversation_id)))return block();
   if(deny)return reply({ok:false,error:'forbidden'},403);
   if(d.action==='list'){const result=resultList(d,owner,role),capturedError=listError;if(holdList)await new Promise(resolve=>heldLists.push(resolve));return capturedError?reply({ok:false,error:'list_failed'},500):reply(result)}
   if(d.action==='operation'){const previous=receipts.get(owner+':'+d.operation_id),note=previous?notes.find(n=>n.id===previous.id):null;return reply({ok:true,found:!foundFalse&&!!previous,result:foundFalse?null:previous?{...clone(previous),...(note?{note:view(note,owner,role)}:{})}:null})}
   if(!['create','update','delete'].includes(d.action))return block();
   const note=notes.find(n=>n.id===Number(d.id)&&n.conversation_id===Number(d.conversation_id));
   if(writeResult==='rejected')return reply({ok:false,error:'invalid_body'},422);
   if(d.action!=='create'&&(!note||note.deleted))return reply({ok:false,error:'not_found'},404);
   if(d.action!=='create'&&role!=='admin'&&note.author_id!==owner)return reply({ok:false,error:'forbidden'},403);
   if(writeResult==='conflict'||d.action!=='create'&&Number(d.version)!==note.version){writeResult='success';const result={ok:false,error:'conflict',id:note?.id||0,note:note?view(note,owner,role):null};receipts.set(owner+':'+d.operation_id,clone(result));return reply(result,409)}
   let result;
   if(d.action==='create'){const created={id:nextId++,conversation_id:Number(d.conversation_id),author_id:owner,author_name:manager.display_name,body:d.body,version:1,created_at:'2026-10-14 10:01:00',updated_at:null,deleted:false};notes.push(created);result={ok:true,id:created.id,saved_version:1,note:view(created,owner,role)}}
   if(d.action==='update'){if(note.body!==d.body){note.body=d.body;note.version++;note.updated_at='2026-10-14 10:02:00'}result={ok:true,id:note.id,saved_version:note.version,note:view(note,owner,role)}}
   if(d.action==='delete'){note.deleted=true;note.version++;result={ok:true,id:note.id,saved_version:note.version,note:view(note,owner,role)}}
   receipts.set(owner+':'+d.operation_id,clone(result));if(holdWrite)await new Promise(resolve=>heldWrites.push(resolve));if(writeResult==='unknown')return route.abort('failed');return reply(result);
  }
  if(url.pathname==='/manager/push-status.php')return reply({ok:true,push_status:{is_working:true,notification_path_usable:false,healthy_subscription_count:0,notification_path_reason:'no_subscription'}});
  if(url.pathname==='/manager/notification-events.php')return reply({ok:true,cursor:0,events:[]});
  unexpected.push(method+' '+url.pathname+' '+d.action);return reply({ok:false,error:'unexpected_fixture_action'},400);
 });
 await page.goto(origin+'/manager2/index.php?notes-qa=1');await expect(page.locator('#inboxList .leadItem')).toHaveCount(2);
 return {calls,unexpected,errors,pending,notes,receipts,setWriteResult:v=>writeResult=v,setFoundFalse:v=>foundFalse=v,setHoldList:v=>holdList=v,setHoldWrite:v=>holdWrite=v,setListError:v=>listError=v,setDeny:v=>deny=v,releaseList:()=>heldLists.shift()?.(),releaseWrite:()=>heldWrites.shift()?.(),switchManager:m=>manager=m};
}
module.exports={setup,root,origin};
