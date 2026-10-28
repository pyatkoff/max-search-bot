(function(){
'use strict';let nonce='',device='',helloInFlight=null;
const emit=detail=>document.dispatchEvent(new CustomEvent('anytour-collector-extension',{detail}));
function hello(){
 if(helloInFlight)return helloInFlight;
 // Initial load, page-ready and worker recovery can overlap. One request keeps
 // the page and worker on the same nonce even when replies would be reordered.
 helloInFlight=(async()=>{try{const r=await chrome.runtime.sendMessage({type:'bridge_hello'});nonce=r.nonce;device=r.device;emit({type:'hello',device,nonce})}catch{}})().finally(()=>{helloInFlight=null});
 return helloInFlight;
}
document.addEventListener('anytour-collector-page-ready',hello);
document.addEventListener('anytour-collector-page',event=>{const m=event.detail;if(!m||m.nonce!==nonce)return;void chrome.runtime.sendMessage({type:'bridge_event',event:m}).catch(()=>{})});
chrome.runtime.onMessage.addListener((m,sender,respond)=>{if(sender.id!==chrome.runtime.id)return;if(m?.type==='bridge_reconnect'){void hello().then(()=>respond({ok:true}));return true}if(m?.type!=='bridge_command'||m.nonce!==nonce)return;emit({...m.command,type:'command',nonce});respond({ok:true})});
void hello();
})();
