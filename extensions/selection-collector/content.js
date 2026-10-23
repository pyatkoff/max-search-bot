(function(){
'use strict';let scheduled=false;
const delay=ms=>new Promise(r=>setTimeout(r,ms));
async function refreshTarget(){/* Client identity stays in extension-owned UI, never in TO page DOM. */}
async function collect(row,button){
 if(button.disabled)return;button.disabled=true;const old=button.textContent;button.textContent='Проверяем условия…';
 try{if(!row.isConnected||!row.getClientRects().length)throw new Error('Строка больше не отображается.');
 const before=AnyTourFunsun.extract(document,row,location.href);await delay(750);const after=AnyTourFunsun.extract(document,row,location.href);
 before.observed_at=after.observed_at;if(!row.isConnected||!row.getClientRects().length||JSON.stringify(before)!==JSON.stringify(after))throw new Error('Условия ещё меняются. Дождитесь окончания поиска.');
 const r=await chrome.runtime.sendMessage({type:'enqueue',observation:after});if(!r.ok)throw new Error(r.error||'Не удалось сохранить очередь.');
 button.textContent='Проверьте в AnyTour';button.title='Откройте расширение и подтвердите клиента. Туристу ничего не отправлено.';
 }catch(e){button.textContent='Не добавлено';button.title=e.message;const note=row.querySelector('[data-anytour-note]')||document.createElement('div');note.dataset.anytourNote='1';note.setAttribute('role','status');note.textContent=e.message;button.parentElement.append(note)}
 finally{button.disabled=false;setTimeout(()=>{if(button.textContent==='Не добавлено')button.textContent=old},5000)}
}
function scan(){scheduled=false;document.querySelectorAll('tr.price_info').forEach(row=>{const cell=row.querySelector('.td_price');if(!cell||cell.querySelector('[data-anytour-add]'))return;const button=document.createElement('button');button.type='button';button.dataset.anytourAdd='1';button.textContent='В подборку AnyTour';button.addEventListener('click',e=>{e.stopPropagation();void collect(row,button)});cell.append(button)})}
new MutationObserver(()=>{if(!scheduled){scheduled=true;setTimeout(scan,150)}}).observe(document.body,{childList:true,subtree:true});
void refreshTarget();scan();chrome.runtime.onMessage.addListener(m=>{if(m?.type==='target_changed')void refreshTarget()});
})();
