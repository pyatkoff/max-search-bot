'use strict';
const assert=require('node:assert/strict');
const crypto=require('node:crypto');
const {setup:personalSetup,origin}=require('../../tests/visual/fixtures/personal-workspace');

const quickText='Синтетический ответ: уточним доступные варианты и вернёмся с предложением.';
const previewText='Синтетический ответ после проверки бюджета.';
const draftText='Черновик клиента А.\n  Сохраняем точный текст и вложение.';
const fileName='measurement-preview.png';
const previewPNG=Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aV1sAAAAASUVORK5CYII=','base64');
function dataset(){
 const rows=Array.from({length:200},(_,i)=>({id:i+11,customer_id:i+101,manager_id:7,manager_name:'Синтетический менеджер',status:'manager',channel:'telegram',source_id:1,project_key:'synthetic-main',project_label:'Синтетический проект',display_name:'Синтетический клиент '+String(i+1).padStart(3,'0'),original_display_name:'Синтетический клиент '+String(i+1).padStart(3,'0'),last_text:'Подбираем отдых: даты, состав семьи и условия проживания.',last_sender_type:'customer',unread_count:i%4,awaiting_manager_reply:1,awaiting_first_reply:1,lead_outcome:'open'}));
 const messages=Array.from({length:600},(_,i)=>({id:i+1,sender_type:i%4?'customer':'manager',manager_id:i%4?null:7,text:'Синтетическое сообщение '+(i+1)+'. Обсуждаем даты поездки, состав семьи, длительность отдыха и подходящий отель. Важны питание, удобный вылет и итоговая стоимость для всех участников.',created_at:'2026-10-14 10:00:00',...(i%20===19?{attachments:[{type:'audio',name:'Синтетическое аудио '+(i+1),url:origin+'/synthetic-measure/audio-'+(i+1)+'.wav'},{type:'file',name:'Условия '+(i+1)+'.pdf',url:origin+'/synthetic-measure/document-'+(i+1)+'.pdf'}]}:{})}));
 const state={tabs:[{id:10,name:'Измерительный подбор',kind:'group',group_label_id:20,label_id:20,label_ids:[20],queues:['all'],channel:'',source_id:0,reply_needed:false,pinned_only:false}],labels:[{id:20,name:'Измерительный подбор'}],members:{20:rows.map(r=>r.id)},pins:[],settings:{start_tab:'waiting',density:'comfortable',font:'normal'}};
 return {rows,messages,state,quickText,previewText,draftText,fileName};
}
const data=dataset();
const datasetSha=crypto.createHash('sha256').update(JSON.stringify(data)).digest('hex');

// Measurement-only transport extension around the existing actual-shell fixture.
// All authenticated data remains synthetic and every mutation is rejected.
async function setup(page,{sourceRoot,beforeNavigate}={}){
 const documents=new Map(),transport=[],rejected=[];
 const messages=id=>{if(!documents.has(id))documents.set(id,structuredClone(data.messages));return documents.get(id)};
 const fixture=await personalSetup(page,{sourceRoot,rows:data.rows,messages:data.messages.slice(-200),state:data.state,filterQueues:true,beforeNavigate:async p=>{
  await p.route('**/manager/*.php',async route=>{
   const request=route.request(),url=new URL(request.url());
   if(url.origin!==origin){rejected.push('external');return route.abort()}
   if(request.method()==='GET')return route.fallback();
   let body;try{body=request.postDataJSON()}catch{return route.abort()}
   const allowed={
    '/manager/api.php':['me','login','counts','detail','edit_candidates'],
    '/manager/pipeline-api.php':['catalog','filter_options','list','detail'],
    '/manager/personal-workspace-api.php':['snapshot','list','operation'],
    '/manager/quick-replies-api.php':['toolbar','list'],
    '/manager/post-context-api.php':['detail'],
    '/manager/customer-name-api.php':['get'],
    '/manager/push-status.php':['status',''],
    '/manager/push.php':['connections'],
    '/manager/notification-events.php':['poll'],
   };
   if(!allowed[url.pathname]?.includes(body.action||'')){rejected.push(url.pathname+' '+body.action);return route.abort()}
   const reply=async value=>{const json=JSON.stringify(value);transport.push({path:url.pathname,action:body.action,conversation_id:body.conversation_id||0,before_id:body.before_id||0,json_bytes:Buffer.byteLength(json)});return route.fulfill({contentType:'application/json',body:json})};
   if(url.pathname==='/manager/api.php'&&body.action==='counts')return reply({ok:true,counts:{waiting:{count:200,unread:150},mine:{count:200}}});
   if(url.pathname==='/manager/api.php'&&body.action==='detail'){
    assert.equal(body.csrf,'fixture-csrf');const id=Number(body.conversation_id);assert.ok(data.rows.some(r=>r.id===id));
    assert.ok(Object.keys(body).every(k=>['action','csrf','conversation_id','before_id'].includes(k)));
    const all=messages(id),before=Number(body.before_id||0),available=before?all.filter(m=>m.id<before):all,selected=available.slice(-200);
    return reply({ok:true,conversation:structuredClone(data.rows.find(r=>r.id===id)),messages:selected,delivery_failure:null,has_manager_reply:true,history:{has_more:available.length>200,before_id:selected[0]?.id||0,page_size:200,mode:before?'older':'latest'}});
   }
   if(url.pathname==='/manager/quick-replies-api.php'){
    assert.equal(body.csrf,'fixture-csrf');const replies=[{id:1,title:'Вставить ответ',body:quickText,category:'Измерение',scope:'personal',manager_id:7,version:1,can_edit:true,is_favorite:false},{id:2,title:'Проверить бюджет',body:'Синтетический ответ, бюджет: {trip_budget}',category:'Измерение',scope:'personal',manager_id:7,version:1,can_edit:true,is_favorite:false}];
    return reply(body.action==='toolbar'?{ok:true,replies,version:'0'.repeat(64),max_replies:100}:{ok:true,replies,total:2,has_more:false,categories:['Измерение'],can_manage_shared:false});
   }
   return route.fallback();
  });
  if(beforeNavigate)await beforeNavigate(p);
 }});
 return {...fixture,transport,rejected,documents,reset(){documents.clear()},append(){messages(11).push({id:601,sender_type:'customer',text:'Новое синтетическое входящее после загрузки истории.',created_at:'2026-10-14 10:01:00'})}};
}

function installMeter(){
 const fetches=[],longTasks=[],resources=[],objectFiles=new Map();let active=null,sequence=0,nativeFile=null;
 const createObjectURL=URL.createObjectURL.bind(URL);URL.createObjectURL=function(blob){const url=createObjectURL(blob);objectFiles.set(url,blob);return url};
 const supported=PerformanceObserver.supportedEntryTypes||[];
 if(supported.includes('longtask'))new PerformanceObserver(list=>longTasks.push(...list.getEntries().map(e=>({start:e.startTime,duration:e.duration})))).observe({type:'longtask',buffered:true});
 if(supported.includes('resource'))new PerformanceObserver(list=>resources.push(...list.getEntries().map(e=>({name:new URL(e.name,location.href).pathname,initiator:e.initiatorType,start:e.startTime,duration:e.duration,responseStart:e.responseStart,responseEnd:e.responseEnd,transferSize:e.transferSize,encodedBodySize:e.encodedBodySize,decodedBodySize:e.decodedBodySize})))).observe({type:'resource',buffered:true});
 const originalFetch=window.fetch;
 window.fetch=async function(...args){
  const url=new URL(typeof args[0]==='string'?args[0]:args[0].url,document.baseURI);let body={};try{body=JSON.parse(args[1]?.body||'{}')}catch{}
  const item={id:++sequence,path:url.pathname,method:args[1]?.method||'GET',action:body.action||'',conversation_id:body.conversation_id||0,before_id:body.before_id||0,start:performance.now()};fetches.push(item);
  try{const response=await originalFetch.apply(this,args);item.headers=performance.now();item.status=response.status;const json=response.json.bind(response);response.json=async()=>{try{const value=await json();item.jsonEnd=performance.now();item.jsonBytes=new TextEncoder().encode(JSON.stringify(value)).length;return value}catch(error){item.error=error.name;throw error}};return response}catch(error){item.error=error.name;throw error}
 };
 const visible=element=>{if(!element||!element.isConnected)return false;const r=element.getBoundingClientRect();return r.width>0&&r.height>0&&getComputedStyle(element).visibility!=='hidden'&&r.bottom>0&&r.top<innerHeight&&r.right>0&&r.left<innerWidth};
 function ownReply(id){const W=window.WorkspaceV2,C=W?.S?.detail?.conversation,input=document.getElementById('replyText'),send=document.getElementById('sendReply');return Number(W?.S?.current)===id&&Number(C?.id)===id&&Number(C.manager_id)===7&&C.status==='manager'&&!W.S.authExpired&&!!W.S.csrf&&visible(input)&&!input.disabled&&!input.readOnly&&visible(send)&&!send.disabled}
 function parsed(op,path,action){return fetches.slice(op.fetchIndex).some(r=>r.start>=op.start&&r.path===path&&r.action===action&&r.jsonEnd)}
 function condition(op){
  const W=window.WorkspaceV2,H=window.WorkspaceV2History,input=document.getElementById('replyText'),loaded=H?.getState().loaded;
  if(op.kind==='startup'||op.kind==='list')return !!W?.S?.workspaceBound&&!W.S.booting&&!W.S.authExpired&&document.querySelectorAll('#inboxList .leadItem').length===200&&window.WorkspaceV2Inbox?.getLoadedSelectionStatus()==='ready'&&(op.kind==='startup'||parsed(op,op.path,'list'));
  if(op.kind==='open'||op.kind==='back'){
   const read=parsed(op,'/manager/api.php','detail'),transcript=Number(W?.S?.current)===op.id&&loaded===op.loaded&&read;
   if(transcript&&!op.milestones.transcript)op.milestones.transcript=performance.now();
   if(transcript&&ownReply(op.id)&&!op.milestones.authorizedReply)op.milestones.authorizedReply=performance.now();
   if(read&&Number(W?.S?.current)===op.id&&W.S.detail?.lead&&!op.milestones.card)op.milestones.card=performance.now();
   const retained=op.kind!=='back'||(input?.value===op.draft&&document.getElementById('mediaSelection')?.textContent.includes(op.file));
   return !!op.milestones.authorizedReply&&retained;
  }
  if(op.kind==='history'||op.kind==='refresh')return !!op.commandDone&&loaded===op.loaded&&!H?.getState().paging&&ownReply(op.id)&&parsed(op,'/manager/api.php','detail');
  if(op.kind==='preview')return !!document.getElementById('quickRepliesDialog')?.open&&visible(document.getElementById('qrPreview'));
  if(op.kind==='insert')return ownReply(op.id)&&input?.value===op.draft&&!document.getElementById('quickRepliesDialog')?.open;
  if(op.kind==='file'){const img=document.querySelector('#mediaSelection img');return ownReply(op.id)&&document.getElementById('mediaSelection')?.textContent.includes(op.file)&&!!img?.complete&&img.naturalWidth>0}
  if(op.kind==='input')return ownReply(op.id)&&input?.value===op.draft;
  return false;
 }
 function tick(){const op=active;if(!op||op.start===null)return;const ready=condition(op);
  if(op.end){if(['open','back'].includes(op.kind)&&!op.milestones.card)requestAnimationFrame(tick);return}
  if(ready){op.ready??=performance.now();requestAnimationFrame(()=>requestAnimationFrame(()=>{if(active===op&&condition(op)){op.end=performance.now();if(['open','back'].includes(op.kind)&&!op.milestones.card)requestAnimationFrame(tick)}else if(active===op){op.ready=null;requestAnimationFrame(tick)}}))}else requestAnimationFrame(tick);
 }
 function start(){if(active&&active.start===null){active.start=performance.now();requestAnimationFrame(tick)}}
 for(const type of ['click','change','input','keydown','popstate'])window.addEventListener(type,event=>{if(type==='change'&&event.target?.id==='replyFile')nativeFile=event.target.files[0];if(!active||active.start!==null||active.event!==type)return;const match=type==='keydown'?event.key.toLowerCase()==='j'&&event.ctrlKey&&event.altKey:type==='popstate'||(active.selector&&event.target?.closest?.(active.selector));if(match){active.trusted=event.isTrusted;start()}},{capture:true});
 window.__managerMeasurement={
  begin(options){if(active&&!active.end)throw Error('Previous measurement unfinished');active={...options,start:null,ready:null,end:null,milestones:{},fetchIndex:fetches.length,armed:performance.now(),generation:window.WorkspaceV2Conversation?.getOpenGeneration()};if(!active.event)start();return true},
  done(){return !!active?.end},
  commandDone(){if(active)active.commandDone=performance.now()},
  fileRetained(){return !!nativeFile&&objectFiles.get(document.querySelector('#mediaSelection img')?.src)===nativeFile&&window.WorkspaceV2Media.hasFile()},
  diagnostic(){return {active,state:{current:window.WorkspaceV2?.S.current,history:window.WorkspaceV2History?.getState(),reply:document.getElementById('replyText')?.value,media:document.getElementById('mediaSelection')?.textContent,selection:window.WorkspaceV2Inbox?.getLoadedSelectionStatus()},fetches:fetches.slice(-12)}},
  cardDone(){if(!active||!['open','back'].includes(active.kind))return true;condition(active);return !!active.milestones.card},
  record(){if(!active?.end)throw Error('Measurement not ready');const op=active,reads=fetches.slice(op.fetchIndex).filter(r=>r.start>=op.start&&r.start<=op.end),tasks=longTasks.filter(r=>r.start<op.end&&r.start+r.duration>op.start),entries=resources.filter(r=>r.start>=op.start&&r.start<=op.end);return {...op,duration:op.end-op.start,timeOrigin:performance.timeOrigin,wallStart:new Date(performance.timeOrigin+op.start).toISOString(),wallEnd:new Date(performance.timeOrigin+op.end).toISOString(),fetches:reads,longTasks:supported.includes('longtask')?tasks:'not_measured',resources:entries,counts:{fetchesStarted:reads.length,jsonParsed:reads.filter(r=>r.jsonEnd).length,reserializedJSONBytes:reads.reduce((sum,r)=>sum+(r.jsonBytes||0),0),resourceEntries:entries.length,mediaResourceEntries:entries.filter(r=>r.name.startsWith('/synthetic-measure/')).length},state:{current:window.WorkspaceV2?.S.current,loaded:window.WorkspaceV2History?.getState().loaded,replyDisabled:document.getElementById('replyText')?.disabled,fileRetained:op.kind==='back'?window.__managerMeasurement.fileRetained():undefined},serverSQL:'not_measured',wireBytes:'not_measured'}},
  environment(){return {userAgent:navigator.userAgent,hardwareConcurrency:navigator.hardwareConcurrency,deviceMemory:navigator.deviceMemory??'not_measured',viewport:{width:innerWidth,height:innerHeight},deviceScaleFactor:devicePixelRatio,longTaskSupport:supported.includes('longtask'),timeOrigin:performance.timeOrigin}},
 };
 window.__managerMeasurement.begin({name:'startup',kind:'startup'});
}
module.exports={setup,installMeter,dataset:data,datasetSha,origin,quickText,previewText,draftText,fileName,previewPNG};
