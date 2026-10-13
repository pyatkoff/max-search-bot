(function(){
  const pending=new Map();
  const prefix='workspaceV2.taskDraft.';
  function currentId(){return Number(window.WorkspaceV2?.S?.current||0)}
  function managerId(){const state=window.WorkspaceV2?.S;const id=Number(state?.manager?.id||0);return !state?.authExpired&&Number.isSafeInteger(id)&&id>0?id:0}
  function generation(){return Number(window.WorkspaceV2?.S?.authGeneration||0)}
  function keyFor(id=currentId()){id=Number(id||0);const owner=managerId();return owner&&Number.isSafeInteger(id)&&id>0?prefix+owner+'.'+id:''}
  function ownedKey(key){const owner=managerId();if(!owner||typeof key!=='string'||!key.startsWith(prefix+owner+'.'))return false;const id=Number(key.slice((prefix+owner+'.').length));return Number.isSafeInteger(id)&&id>0&&key===keyFor(id)}
  // Legacy conversation-only keys carry no trustworthy account identity; leave them untouched.
  function read(key){if(!ownedKey(key))return null;try{const raw=sessionStorage.getItem(key);if(!raw)return null;const value=JSON.parse(raw);return value&&typeof value==='object'?value:null}catch(e){return null}}
  function write(key,value){if(!ownedKey(key))return;try{sessionStorage.setItem(key,JSON.stringify(value))}catch(e){}}
  function clear(key){if(!ownedKey(key))return;try{sessionStorage.removeItem(key)}catch(e){}}
  function beginCreate(id=currentId()){const key=keyFor(id);if(!key)return null;const operation={key,owner:managerId(),generation:generation()};pending.set(key,operation);return operation}
  function finishCreate(operation,result){if(!operation||pending.get(operation.key)!==operation)return;try{if(result===true&&operation.owner===managerId()&&operation.generation===generation())clear(operation.key)}finally{if(pending.get(operation.key)===operation)pending.delete(operation.key)}}
  function bindDraft(root,key){if(!root||!key)return;const title=root.querySelector('#leadTaskTitle'),due=root.querySelector('#leadTaskDue');if(!title||!due)return;const saved=read(key),boundGeneration=generation(),operation=pending.get(key),creating=operation?.owner===managerId()&&operation?.generation===boundGeneration;if(saved&&!creating){title.value=String(saved.title||'');due.value=String(saved.due||'')}const persist=()=>{if(title.disabled||due.disabled||boundGeneration!==generation())return;write(key,{title:title.value,due:due.value})};title.addEventListener('input',persist);due.addEventListener('change',persist)}
  function wrap(){const tasks=window.WorkspaceV2Tasks;if(!tasks||tasks.__draftPersistenceWrapped)return;const original=tasks.render;tasks.render=function(root,options){const key=keyFor(options?.conversationId??currentId()),result=original.call(tasks,root,options);bindDraft(root,key);return result};tasks.__draftPersistenceWrapped=true}
  wrap();
  window.WorkspaceV2TaskDraft={wrap,keyFor,read,clear,beginCreate,finishCreate};
})();
