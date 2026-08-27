(()=>{
const state={csrf:'',conversationId:0,busy:false};
const $=id=>document.getElementById(id);
function selection(){return $('mediaSelection')}function input(){return $('replyFile')}
function clear(){const f=input(),box=selection();if(f)f.value='';if(box){box.textContent='';box.classList.add('hidden')}}
function render(){const f=input()?.files?.[0],box=selection();if(!box)return;if(!f){clear();return}const mb=(f.size/1024/1024).toFixed(f.size>=1024*1024?1:2);box.innerHTML=`<span class="mediaFileName"></span><span class="mediaFileSize"></span><button class="mediaRemove" type="button" aria-label="Убрать вложение">×</button>`;box.querySelector('.mediaFileName').textContent=f.name;box.querySelector('.mediaFileSize').textContent=`${mb} МБ`;box.querySelector('.mediaRemove').onclick=clear;box.classList.remove('hidden')}
function init(){input()?.addEventListener('change',render)}
function configure(csrf,conversationId){state.csrf=csrf||'';state.conversationId=Number(conversationId||0)}
function hasFile(){return !!input()?.files?.length}
async function send(caption=''){if(state.busy||!hasFile()||!state.conversationId)return{ok:false,error:'media_not_ready'};state.busy=true;try{const data=new FormData();data.append('csrf',state.csrf);data.append('conversation_id',String(state.conversationId));data.append('file',input().files[0]);data.append('caption',caption||'');const r=await fetch('media-upload.php',{method:'POST',body:data});const j=await r.json().catch(()=>({ok:false,error:'invalid_response'}));if(r.status===401){location.href='index.php';return j}if(j.ok)clear();return j}catch(e){return{ok:false,error:'network_error',error_message:'Не удалось отправить файл'}}finally{state.busy=false}}
window.WorkspaceV2Media={init,configure,hasFile,send,clear};
})();
