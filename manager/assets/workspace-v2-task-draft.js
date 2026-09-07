(function(){
  const pending=new Set();
  const prefix='workspaceV2.taskDraft.';
  function currentId(){return Number(window.WorkspaceV2?.S?.current||0)}
  function keyFor(id=currentId()){return id>0?prefix+id:''}
  function read(key){if(!key)return null;try{const raw=sessionStorage.getItem(key);if(!raw)return null;const value=JSON.parse(raw);return value&&typeof value==='object'?value:null}catch(e){return null}}
  function write(key,value){if(!key)return;try{sessionStorage.setItem(key,JSON.stringify(value))}catch(e){}}
  function clear(key){if(!key)return;try{sessionStorage.removeItem(key)}catch(e){}}
  function beginCreate(id=currentId()){const key=keyFor(id);if(key)pending.add(key);return key}
  function finishCreate(key,result){if(!key)return;try{if(result!==false)clear(key)}finally{pending.delete(key)}}
  function bindDraft(root,key){if(!root||!key)return;const title=root.querySelector('#leadTaskTitle'),due=root.querySelector('#leadTaskDue');if(!title||!due)return;const saved=read(key);if(saved&&!pending.has(key)){title.value=String(saved.title||'');due.value=String(saved.due||'')}const persist=()=>write(key,{title:title.value,due:due.value});title.addEventListener('input',persist);due.addEventListener('change',persist)}
  function wrap(){const tasks=window.WorkspaceV2Tasks;if(!tasks||tasks.__draftPersistenceWrapped)return;const original=tasks.render;tasks.render=function(root,options){const key=keyFor(currentId()),result=original.call(tasks,root,options);bindDraft(root,key);return result};tasks.__draftPersistenceWrapped=true}
  wrap();
  window.WorkspaceV2TaskDraft={wrap,keyFor,read,clear,beginCreate,finishCreate};
})();
