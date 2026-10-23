(function(){
'use strict';let nonce='',device='';
const emit=detail=>document.dispatchEvent(new CustomEvent('anytour-collector-extension',{detail}));
async function hello(){try{const r=await chrome.runtime.sendMessage({type:'bridge_hello'});nonce=r.nonce;device=r.device;emit({type:'hello',device,nonce})}catch{}}
document.addEventListener('anytour-collector-page-ready',hello);
document.addEventListener('anytour-collector-page',event=>{const m=event.detail;if(!m||m.nonce!==nonce)return;void chrome.runtime.sendMessage({type:'bridge_event',event:m}).catch(()=>{})});
chrome.runtime.onMessage.addListener((m,sender,respond)=>{if(sender.id!==chrome.runtime.id)return;if(m?.type==='bridge_reconnect'){void hello().then(()=>respond({ok:true}));return true}if(m?.type!=='bridge_command'||m.nonce!==nonce)return;emit({...m.command,type:'command',nonce});respond({ok:true})});
void hello();
})();
