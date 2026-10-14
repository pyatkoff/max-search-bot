(()=>{
const state={csrf:'',conversationId:0,busy:false,restored:null};
const attachmentDrafts=new Map();
const account=()=>Number(window.WorkspaceV2?.S?.manager?.id||0);
const draftKey=id=>account()+':'+Number(id||0);
const selectedFile=()=>input()?.files?.[0]||state.restored;
function preserveDraft(id=state.conversationId){const file=selectedFile();if(file&&Number(id)>0)attachmentDrafts.set(draftKey(id),file);else attachmentDrafts.delete(draftKey(id))}
function restoreDraft(id=state.conversationId){if(input())input().value='';state.restored=attachmentDrafts.get(draftKey(id))||null;render()}
function resetDrafts(){attachmentDrafts.clear();state.restored=null;clear()}
const $=id=>document.getElementById(id);
function selection(){return $('mediaSelection')}function input(){return $('replyFile')}
function clear(){attachmentDrafts.delete(draftKey(state.conversationId));state.restored=null;const f=input(),box=selection();if(f)f.value='';if(box){box.textContent='';box.classList.add('hidden')}}
function render(){const f=selectedFile(),box=selection();if(!box)return;if(!f){clear();return}const mb=(f.size/1024/1024).toFixed(f.size>=1024*1024?1:2);box.innerHTML=`<span class="mediaFileName"></span><span class="mediaFileSize"></span><button class="mediaRemove" type="button" aria-label="Убрать вложение">×</button>`;box.querySelector('.mediaFileName').textContent=f.name;box.querySelector('.mediaFileSize').textContent=`${mb} МБ`;box.querySelector('.mediaRemove').onclick=clear;box.classList.remove('hidden')}
function init(){input()?.addEventListener('change',()=>{state.restored=null;render();preserveDraft()})}
function configure(csrf,conversationId){state.csrf=csrf||'';state.conversationId=Number(conversationId||0)}
function hasFile(){return !!selectedFile()}
async function send(caption=''){
  if(state.busy||!hasFile()||!state.conversationId)return{ok:false,error:'media_not_ready'};
  const generation=window.WorkspaceV2?.S?.authGeneration,file=selectedFile();
  state.busy=true;
  try{
    const target=state.conversationId,sourceKey=draftKey(target),data=new FormData();
    data.append('csrf',state.csrf);data.append('conversation_id',String(target));data.append('file',file);data.append('caption',caption||'');
    const r=await fetch('media-upload.php',{method:'POST',body:data,credentials:'same-origin'});
    const j=await r.json().catch(()=>({ok:false,error:'invalid_response'}));
    if(generation!==window.WorkspaceV2?.S?.authGeneration)return{ok:false,error:'stale_session',conversation_id:target};
    if(r.status===401){window.WorkspaceV2?.showAuthRecovery?.();return{...j,ok:false,error:'unauthorized',http_status:401,conversation_id:target}}
    if(j.ok&&attachmentDrafts.get(sourceKey)===file)attachmentDrafts.delete(sourceKey);
    if(j.ok&&state.conversationId===target&&selectedFile()===file)clear();
    return{...j,conversation_id:target};
  }catch(e){return{ok:false,error:'network_error',error_message:'Не удалось отправить файл'}}
  finally{state.busy=false}
}
window.WorkspaceV2Media={init,configure,hasFile,send,clear,preserveDraft,restoreDraft,resetDrafts};
})();
