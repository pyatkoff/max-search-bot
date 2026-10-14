const {expect}=require('@playwright/test');
const {execFileSync}=require('node:child_process');
const fs=require('node:fs'),path=require('node:path');
const root=path.resolve(__dirname,'../../..'),origin='http://127.0.0.1:4173';
const clone=value=>JSON.parse(JSON.stringify(value));
const emptyState=()=>({tabs:[],labels:[],members:{},pins:[],settings:{start_tab:'waiting',density:'comfortable',font:'normal'}});
// Actual PHP shell and real source assets. Every data request and write is
// synthetic, local and account-scoped. Unexpected traffic is always rejected.
async function setup(page,options={}){
 const sourceRoot=options.sourceRoot||root,shell=execFileSync('php',['manager2/index.php'],{cwd:sourceRoot,encoding:'utf8'});
 const calls=[],unexpected=[],errors=[],heldSnapshots=[],heldWrites=[],heldLists=[],receipts=new Map(),accounts=options.accounts||new Map(),pending=new Set();
 let manager={id:7,role:'manager',display_name:'Мария',is_working:true},snapshotError=!!options.snapshotError,holdSnapshot=!!options.holdSnapshot,holdWrite=!!options.holdWrite,holdList=false,writeResult=options.writeResult||'success',listError=false,nextId=10;
 const account=id=>{if(!accounts.has(id))accounts.set(id,{version:1,state:clone(options.state||emptyState())});return accounts.get(id)};
 const conv=id=>({id,customer_id:id+90,manager_id:manager.id,manager_name:manager.display_name,status:'manager',channel:'telegram',source_id:1,display_name:id===11?'Анна Морозова':'Алексей Иванов',original_display_name:id===11?'Анна Морозова':'Алексей Иванов',working_name:null,customer_name_version:0,name_version:0});
 const visibleIds=new Set([11,12,...(options.personalRows||[]).map(row=>row.id)]);
 const rows=()=>[11,12].map(id=>({...conv(id),project_label:'AnyTour',origin_label:'TG · реклама',last_text:'Ждём варианты у моря',last_sender_type:'customer',unread_count:id===11?2:0,awaiting_manager_reply:1,lead_outcome:'open'}));
 const messages=Array.from({length:35},(_,i)=>({id:i+1,sender_type:i%3?'customer':'manager',manager_id:i%3?null:7,text:'Сообщение '+(i+1)+'. Подбираем отдых у моря.',created_at:'2026-10-14 10:00:00'}));
 const snapshot=id=>({ok:true,...clone(account(id))});
 page.on('request',r=>pending.add(r));page.on('requestfinished',r=>pending.delete(r));page.on('requestfailed',r=>pending.delete(r));page.on('pageerror',e=>errors.push(e.message));
 await page.addInitScript(()=>{
  window.personalFixtureTiming={};
  const observer=new MutationObserver(()=>{if(!window.personalFixtureTiming.inboxMs&&document.querySelector('#inboxList .leadItem'))window.personalFixtureTiming.inboxMs=performance.now()});observer.observe(document,{subtree:true,childList:true});
  const reg={scope:location.origin+'/manager/',active:{postMessage(){}},pushManager:{getSubscription:async()=>null},showNotification:async()=>{}};
  Object.defineProperty(navigator,'serviceWorker',{configurable:true,value:{register:async()=>reg,ready:Promise.resolve(reg),addEventListener(){}}});
  function N(){}N.permission='default';Object.defineProperty(window,'Notification',{configurable:true,value:N});Object.defineProperty(window,'PushManager',{configurable:true,value:function(){}});
 });
 await page.route('**/*',async route=>{
  const req=route.request(),url=new URL(req.url()),method=req.method(),reply=(body,status=200)=>route.fulfill({status,contentType:'application/json',body:JSON.stringify(body)});
  const block=()=>{unexpected.push(method+' '+url.pathname);return route.abort()};
  if(url.origin!==origin)return block();
  if(method==='GET'&&['/manager2/','/manager2/index.php'].includes(url.pathname))return route.fulfill({contentType:'text/html',body:shell});
  if(method==='GET'&&url.pathname==='/favicon.ico')return route.fulfill({status:204,body:''});
  if(method==='GET'&&url.pathname==='/manager/sw.js')return route.fulfill({contentType:'application/javascript',body:''});
  if(method==='GET'&&/^\/manager2?\/assets\/[\w.-]+$/.test(url.pathname))return route.fulfill({contentType:url.pathname.endsWith('.css')?'text/css':'application/javascript',body:fs.readFileSync(path.join(sourceRoot,url.pathname.slice(1)))});
  if(!url.pathname.startsWith('/manager/'))return block();
  let d={};if(method==='POST'){try{d=req.postDataJSON()||{}}catch{return block()}}
  const owner=manager.id;calls.push({path:url.pathname,owner,payload_bytes:Buffer.byteLength(req.postData()||''),...d});
  if(method==='GET'&&url.pathname==='/manager/customer-avatar.php'){
   if([...url.searchParams.keys()].join(',')!=='conversation_id'||!visibleIds.has(Number(url.searchParams.get('conversation_id'))))return block();
   return route.fulfill({status:404,body:''});
  }
  if(method==='POST'&&d.csrf!=='fixture-csrf'&&!['me','login'].includes(d.action))throw new Error('Canonical CSRF missing: '+url.pathname+' '+d.action);
  if(url.pathname==='/manager/api.php'){
   if(d.action==='me'||d.action==='login')return reply({ok:true,manager:clone(manager),csrf:'fixture-csrf',projects:[]});
   if(d.action==='detail'&&visibleIds.has(Number(d.conversation_id)))return reply({ok:true,conversation:conv(d.conversation_id),messages,delivery_failure:null,has_manager_reply:true,history:{has_more:false,before_id:1,page_size:200,mode:'latest'}});
   if(d.action==='counts')return reply({ok:true,counts:{waiting:{count:2,unread:2},mine:{count:2}}});
   if(d.action==='edit_candidates')return reply({ok:true,messages:[],candidates:[]});
  }
  if(url.pathname==='/manager/pipeline-api.php'){
   if(d.action==='catalog')return reply({ok:true,stages:[],tags:[],outcomes:{open:'В работе'},close_reasons:{}});
   if(d.action==='filter_options')return reply({ok:true,filters:{sources:[],managers:[clone(manager)]}});
   if(d.action==='list')return reply({ok:true,conversations:rows()});
   if(d.action==='detail')return reply({ok:true,can_edit_pipeline:true,pipeline:{stage:null,tags:[],stage_history:[],outcome:{outcome:'open'}},tasks:[],trip:{city:'Москва',country:'Турция',adults:2},contact:{},source:{},handoff:{}});
  }
  if(url.pathname==='/manager/quick-replies-api.php'&&d.action==='toolbar')return reply({ok:true,replies:[],version:'0'.repeat(64),max_replies:100});
  if(url.pathname==='/manager/customer-name-api.php'&&d.action==='get'&&visibleIds.has(Number(d.conversation_id))){const c=conv(Number(d.conversation_id));return reply({ok:true,name:{customer_id:c.customer_id,display_name:c.display_name,original_display_name:c.original_display_name,working_name:null,version:0,updated_at:null,updated_by_manager_name:null,can_edit:true},history:[],history_has_more:false})}
  if(url.pathname==='/manager/post-context-api.php'&&d.action==='detail'&&[11,12].includes(d.conversation_id))return reply({ok:true,conversation_id:d.conversation_id,context:{current:null,history:[]}});
  if(url.pathname==='/manager/personal-workspace-api.php'){
   if(d.action==='snapshot'){const result=snapshot(owner);if(holdSnapshot)await new Promise(resolve=>heldSnapshots.push(resolve));return snapshotError?reply({ok:false,error:'snapshot_failed'},500):reply(result)}
   if(d.action==='operation'){const previous=receipts.get(owner+':'+d.operation_id);return reply({...snapshot(owner),found:!!previous,result:previous?{...previous,...snapshot(owner)}:null})}
   if(d.action==='list'){
    const state=account(owner).state,tab=state.tabs.find(t=>'tab:'+t.id===d.selection);let items=rows();
    if(d.selection==='pinned'||tab?.pinned_only)items=items.filter(r=>state.pins.includes(r.id));
    if(tab?.label_id)items=items.filter(r=>(state.members[tab.label_id]||[]).includes(r.id));
    if(tab?.channel)items=items.filter(r=>r.channel===tab.channel);
    if(options.personalRows)items=clone(options.personalRows);
    const capturedError=listError,result={...snapshot(owner),conversations:items,bounded_count:items.length,has_more:!!options.limited,limited:!!options.limited,count_scope:'loaded_visible',limit:200};if(holdList)await new Promise(resolve=>heldLists.push(resolve));if(capturedError)return reply({ok:false,error:'list_failed'},500);return reply(result);
   }
   const allowed=['tab_save','tab_delete','tab_reorder','label_save','label_delete','label_membership','pin','settings'];
   if(!allowed.includes(d.action))return block();
   const a=account(owner);
   if(writeResult==='conflict'||Number(d.expected_version)!==a.version){writeResult='success';return reply({...snapshot(owner),ok:false,error:'conflict'},409)}
   if(writeResult==='rejected')return reply({ok:false,error:'forbidden'},403);
   const state=a.state;
   let outcome={};
   if(d.action==='tab_save'){const tab={queue:'mine',channel:'',source_id:0,reply_needed:false,label_id:0,pinned_only:false,...d.tab,id:Number(d.id||0)||nextId++};const i=state.tabs.findIndex(t=>t.id===tab.id);if(i<0)state.tabs.push(tab);else state.tabs[i]=tab;outcome.id=tab.id;}
   if(d.action==='tab_delete'){state.tabs=state.tabs.filter(t=>t.id!==Number(d.id));if(state.settings.start_tab==='tab:'+d.id)state.settings.start_tab='waiting';outcome.deleted_id=d.id;}
   if(d.action==='tab_reorder')state.tabs=(d.ids||d.tab_ids||[]).map(id=>state.tabs.find(t=>t.id===id));
   if(d.action==='label_save'){const label={id:Number(d.id||0)||nextId++,name:d.name};const i=state.labels.findIndex(t=>t.id===label.id);if(i<0)state.labels.push(label);else state.labels[i]=label;outcome.id=label.id;}
   if(d.action==='label_delete'){state.labels=state.labels.filter(t=>t.id!==Number(d.id));delete state.members[d.id];outcome.deleted_id=d.id;}
   if(d.action==='label_membership'){const ids=(state.members[d.label_id]||[]).filter(id=>id!==Number(d.conversation_id));if(d.enabled)ids.push(Number(d.conversation_id));if(ids.length)state.members[d.label_id]=ids;else delete state.members[d.label_id];}
   if(d.action==='pin'){state.pins=state.pins.filter(id=>id!==Number(d.conversation_id));if(d.enabled)state.pins.push(Number(d.conversation_id));}
   if(d.action==='settings')state.settings={...state.settings,...d.settings};
   a.version++;const result={...snapshot(owner),...outcome};receipts.set(owner+':'+d.operation_id,clone(result));
   if(holdWrite)await new Promise(resolve=>heldWrites.push(resolve));
   if(writeResult==='unknown')return route.abort('failed');return reply(result);
  }
  if(url.pathname==='/manager/push-status.php')return reply({ok:true,push_status:{is_working:true,notification_path_usable:false,healthy_subscription_count:0,notification_path_reason:'no_subscription'}});
  if(url.pathname==='/manager/notification-events.php')return reply({ok:true,cursor:0,events:[]});
  unexpected.push(method+' '+url.pathname+' '+d.action);return reply({ok:false,error:'unexpected_fixture_action'},400);
 });
 await page.goto(origin+'/manager2/index.php?qa=1');await expect(page.locator('#inboxList .leadItem')).toHaveCount(2);
 return {calls,unexpected,errors,pending,accounts,snapshot,account,setWriteResult:v=>writeResult=v,setSnapshotError:v=>snapshotError=v,setHoldSnapshot:v=>holdSnapshot=v,setHoldWrite:v=>holdWrite=v,setHoldList:v=>holdList=v,setListError:v=>listError=v,releaseSnapshot:()=>heldSnapshots.shift()?.(),releaseWrite:()=>heldWrites.shift()?.(),releaseList:()=>heldLists.shift()?.(),switchManager:m=>manager=m};
}
module.exports={setup,root,origin,emptyState};
