'use strict';
// Synthetic endpoint/receipt model shared by the DOM and native-browser tests.
// Product code is loaded unchanged; no customer, provider or live model is used.
const crypto=require('node:crypto');
const clone=value=>JSON.parse(JSON.stringify(value));
const digest=value=>crypto.createHash('sha256').update(value).digest('hex');
const hash=value=>digest(JSON.stringify(value));
function fixtureService({conversationId=101,actor=7}={}){
 const task={id:77,conversation_id:conversationId,title:'Отправить согласованный расчёт',due_at_utc:'2030-11-20 12:00:00',status:'open',is_pinned:true,assigned_manager_id:actor,assigned_manager_name:'Мария',business_revision:5,due_state:'upcoming'};
 const promise={promise_id:'1'.repeat(64),kind:'send_calculation',deadline:null};
 const profile={trip_id:'a'.repeat(32),version:3,sha256:'b'.repeat(64),context_sha256:'c'.repeat(64),boundary_message_id:10,status:'fresh',label:'Поездка в Турцию',fields:{dates:{value:'20 ноября, 7 ночей'},tourists:{value:'2 взрослых'}},field_labels:{dates:'Даты поездки',tourists:'Туристы'}};
 const source={id:13,revision:'e'.repeat(64),author_manager_id:actor,text:'Пришлю расчёт стоимости этой поездки.',recorded_at_utc:'2026-11-15T09:00:00Z'};
 const intents=new Map(),operations=new Map(),attempts=new Map();
 const state={task,promise,profile,source,intents,operations,attempts,revision:1,reject:null,nativeState:'confirmed',latest:null,completionCount:0};
 const envelope=value=>({ok:true,viewer_id:actor,conversation_id:conversationId,...value});
 const latest=()=>state.latest?intents.get(state.latest):null;
 const view=intent=>({...clone(intent),task_status:task.status});
 function parts(composition){return composition.files.length?composition.files.map((f,index)=>({index,kind:'media',file_sha256:f.sha256,size:f.size,name:f.name,caption_sha256:digest(index===0?composition.text:'')})):[{index:0,kind:'text',text_sha256:digest(composition.text)}]}
 function catalog(){return envelope({can_edit:true,entries:[{task_id:task.id,promise_id:promise.promise_id,kind:promise.kind,task:clone(task),can_arm:task.status==='open'&&!state.reject&&(!latest()||['completed','cancelled'].includes(latest().state)),reason:state.reject,intent:latest()?view(latest()):null}]})}
 function preview(d){
  if(state.reject)return {ok:false,error:state.reject};if(latest()?.state==='armed')return {ok:false,error:'intent_conflict'};
  const composition={text:d.composition.text.trim(),files:clone(d.composition.files)},manifest=hash(composition);
  return envelope({can_arm:true,reason:null,preview_sha256:hash([task.business_revision,state.revision,manifest]),manifest_sha256:manifest,composition,parts:parts(composition),task:clone(task),promise:clone(promise),profile:clone(profile),source:clone(source)});
 }
 function write(d){
  if(operations.has(d.operation_id))return clone(operations.get(d.operation_id).result);
  if(state.reject)return {ok:false,error:state.reject};
  let intent;
  if(d.action==='arm'){
   const p=preview(d);if(!p.ok)return p;if(p.preview_sha256!==d.preview_sha256)return {ok:false,error:'preview_changed'};
   const id=(intents.size+1).toString(16).padStart(32,'0');intent={intent_id:id,promise_id:promise.promise_id,task_id:task.id,state:'armed',reason:null,manifest_sha256:p.manifest_sha256,
    parts:p.parts.map(p=>({...p,state:'pending',operation_id:null})),total:p.parts.length,confirmed:0,task_revision:task.business_revision,created_at:'2026-11-15 09:01:00',completed_at:null,can_recover:false};
   intents.set(id,intent);state.latest=id;
  }else{intent=intents.get(d.intent_id);if(!intent)return {ok:false,error:'not_found'};intent.state='cancelled'}
  const result=envelope({replayed:false,intent:view(intent)});const input=d.action==='arm'?{operation_id:d.operation_id,task_id:d.task_id,promise_id:d.promise_id,composition:d.composition,preview_sha256:d.preview_sha256}:{operation_id:d.operation_id,intent_id:d.intent_id};operations.set(d.operation_id,{action:d.action,request_sha256:hash(d.action==='arm'?['arm',conversationId,input.operation_id,input.task_id,input.promise_id,input.composition,input.preview_sha256]:['cancel',conversationId,input.operation_id,input.intent_id]),result});return clone(result);
 }
 function operation(d){const operation=operations.get(d.operation_id);return envelope({found:!!operation,action:operation?.action??null,request_sha256:operation?.request_sha256??null,intent:operation?view(intents.get(operation.result.intent.intent_id)):null})}
 function status(d){const intent=intents.get(d.intent_id);return intent?envelope({intent:view(intent)}):{ok:false,error:'not_found'}}
 function finishIntent(intent){intent.confirmed=intent.parts.filter(p=>p.state==='confirmed').length;if(intent.state==='armed'&&intent.confirmed===intent.total&&task.status==='open'&&task.business_revision===intent.task_revision){intent.state='completed';intent.completed_at='2026-11-15 09:02:00';task.status='done';task.business_revision++;state.completionCount++}}
 function native(d,fileBytes=null){
  const media=fileBytes!==null,link=d.calculation_intent_id,intent=link?intents.get(link):null,index=Number(d.calculation_part_index),part=intent?.parts[index],caption=String(d.caption||'').trim();
  if(link){if(!part||part.kind!==(media?'media':'text')||media&&(part.file_sha256!==digest(fileBytes)||part.caption_sha256!==digest(caption))||!media&&part.text_sha256!==digest(d.text.trim()))throw Error('fixture native manifest mismatch');}
  const attempt={state:state.nativeState,...(media?{kind:'media',media_type:'file',caption_included:!!caption}:{})};
  attempts.set(d.operation_id,{attempt,link,index});if(part){part.state=state.proofMissing?'unknown':state.nativeState;part.operation_id=d.operation_id;finishIntent(intent)}
  return {ok:attempt.state==='confirmed',attempt:clone(attempt)};
 }
 function receipt(d){const a=attempts.get(d.operation_id);return a?{ok:true,attempt:clone(a.attempt)}:{ok:true,attempt:{...(d.action==='media_receipt'?{kind:'media'}:{}),state:'not_found'}}}
 function confirm(operationId){const a=attempts.get(operationId);a.attempt.state='confirmed';if(a.link){const intent=intents.get(a.link);intent.parts[a.index].state='confirmed';finishIntent(intent)}}
 function reopen(){task.status='open';task.business_revision++}
 function request(d){return d.action==='catalog'?catalog():d.action==='preview'?preview(d):d.action==='operation'?operation(d):['status','recover'].includes(d.action)?status(d):write(d)}
 return {state,catalog,preview,write,operation,status,native,receipt,confirm,reopen,request};
}
module.exports={fixtureService,clone,digest};
