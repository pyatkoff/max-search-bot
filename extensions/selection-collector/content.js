(function(){
'use strict';let scheduled=false;const source=AnyTourFunsun.observe(document);
const delay=ms=>new Promise(r=>setTimeout(r,ms));
async function refreshTarget(){/* Client identity stays in extension-owned UI, never in TO page DOM. */}
async function collect(row,button,editing=false){
 if(button.disabled)return;button.disabled=true;AnyTourCollectorClient.label(button,'Проверяем условия…');
 try{const ticket=await AnyTourCollectorClient.begin(button,editing);if(!ticket)return;
 if(!row.isConnected||!row.getClientRects().length)throw new Error('Строка больше не отображается.');
 const before=source.extract(row,location.href);await delay(750);const after=source.extract(row,location.href);
 before.observed_at=after.observed_at;if(!row.isConnected||!row.getClientRects().length||JSON.stringify(before)!==JSON.stringify(after))throw new Error('Условия ещё меняются. Дождитесь окончания поиска.');
 await AnyTourCollectorClient.append(button,ticket,after,editing);
 }catch(e){AnyTourCollectorClient.label(button,'Не добавлено');button.title=e.message;const note=row.querySelector('[data-anytour-note]')||document.createElement('div');note.dataset.anytourNote='1';note.setAttribute('role','status');note.textContent=e.message;button.parentElement.append(note)}
 finally{button.disabled=false}
}
function scan(){scheduled=false;document.querySelectorAll('tr.price_info').forEach(row=>{const cell=row.querySelector('.td_price');if(!cell||cell.querySelector('[data-anytour-add]'))return;for(const editing of [false,true]){const button=document.createElement('button');button.type='button';button.dataset.anytourAdd='1';if(editing)button.dataset.anytourEdit='1';AnyTourCollectorClient.label(button,editing?'✎ Изменить цену':'В подборку AnyTour');button.title=editing?'Изменить цену перед добавлением':'Добавить без изменения цены';button.addEventListener('click',e=>{e.preventDefault();e.stopPropagation();void collect(row,button,editing)});AnyTourCollectorClient.actions(cell).append(button)}})}
new MutationObserver(()=>{if(!scheduled){scheduled=true;setTimeout(scan,150)}}).observe(document.body,{childList:true,subtree:true});
void refreshTarget();scan();chrome.runtime.onMessage.addListener(m=>{if(m?.type==='target_changed')void refreshTarget()});
})();
