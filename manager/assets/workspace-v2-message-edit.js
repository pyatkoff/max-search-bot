(function(){
const W=window.WorkspaceV2;if(!W)return;const {S,api}=W;let timer=0,seq=0,busy=0;
function schedule(){clearTimeout(timer);timer=setTimeout(refresh,80)}
function messageNodes(){return [...document.querySelectorAll('#messages > .msg')]}
function rows(){return Array.isArray(S.detail?.messages)?S.detail.messages:[]}
function nodeMap(){const nodes=messageNodes(),items=rows(),map=new Map();for(let i=0;i<Math.min(nodes.length,items.length);i++){const id=Number(items[i]?.id||0);if(id>0)map.set(id,{node:nodes[i],message:items[i]})}return map}
function clearButtons(){document.querySelectorAll('#messages .messageEditButton').forEach(x=>x.remove())}
function editor(target,message,id){
  if(target.querySelector('.messageInlineEditor'))return;
  const body=target.querySelector('.msgBody');if(!body)return;
  const box=document.createElement('div');box.className='messageInlineEditor';
  const input=document.createElement('textarea');input.rows=3;input.value=String(message.text||'');input.maxLength=4096;input.setAttribute('aria-label','Изменить сообщение');
  const status=document.createElement('div');status.className='messageEditStatus';status.setAttribute('aria-live','polite');
  const actions=document.createElement('div');actions.className='messageEditActions';
  const cancel=document.createElement('button');cancel.type='button';cancel.textContent='Отмена';
  const save=document.createElement('button');save.type='button';save.textContent='Сохранить';save.className='primary';
  actions.append(cancel,save);box.append(input,status,actions);body.insertAdjacentElement('afterend',box);input.focus();input.setSelectionRange(input.value.length,input.value.length);
  cancel.onclick=()=>box.remove();
  save.onclick=async()=>{
    const text=input.value.trim();if(!text){status.textContent='Сообщение не может быть пустым.';return}
    if(busy)return;busy=id;save.disabled=cancel.disabled=true;status.textContent='Сохраняем…';
    try{
      const result=await api('edit_message',{message_id:id,text});
      if(!result?.ok){status.textContent=result?.error==='expired'?'Прошло больше 5 минут — редактирование уже недоступно.':'Не удалось изменить сообщение.';return}
      status.textContent='Изменено';const current=Number(S.current||0);
      if(current)await window.WorkspaceV2Conversation?.open(current,{mobileHistory:'none'});
    }catch(e){status.textContent='Не удалось изменить сообщение.'}
    finally{busy=0;if(box.isConnected){save.disabled=cancel.disabled=false}}
  };
}
async function refresh(){
  const conversation=Number(S.current||0),generation=Number(S.authGeneration||0),run=++seq;clearButtons();
  if(!conversation||!S.manager?.id||S.authExpired)return;
  let result;try{result=await api('edit_candidates',{conversation_id:conversation})}catch(e){return}
  if(run!==seq||conversation!==Number(S.current||0)||generation!==Number(S.authGeneration||0)||!result?.ok)return;
  const map=nodeMap();
  for(const candidate of result.messages||[]){
    const id=Number(candidate?.message_id||0),entry=map.get(id);if(!entry)continue;
    const meta=entry.node.querySelector('.msgMeta');if(!meta)continue;
    const button=document.createElement('button');button.type='button';button.className='messageEditButton';button.textContent='Изменить';
    const remaining=Math.max(0,Number(candidate.remaining_seconds||0));button.title=remaining?('Можно изменить ещё '+Math.ceil(remaining/60)+' мин.'):'Изменить сообщение';
    button.onclick=()=>editor(entry.node,entry.message,id);meta.appendChild(button);
  }
}
function bind(){const box=document.getElementById('messages');if(!box)return;new MutationObserver(schedule).observe(box,{childList:true});document.addEventListener('visibilitychange',()=>{if(document.visibilityState==='visible')schedule()});schedule()}
if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',bind,{once:true});else bind();
window.WorkspaceV2MessageEdit={refresh:schedule};
})();