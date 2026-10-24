(function(){
'use strict';let scheduled=false;
function scan(){scheduled=false;const menus=Array.from(document.querySelectorAll('.TVMenuControl')).filter(e=>e.getBoundingClientRect().width>0&&e.querySelector('a.TVSearchLinkItem'));
 for(const menu of menus){if(menu.querySelector('[data-anytour-add]'))continue;const b=document.createElement('button');b.type='button';b.dataset.anytourAdd='1';b.textContent='В подборку AnyTour';menu.append(b);
 b.onclick=async e=>{e.preventDefault();e.stopPropagation();if(b.disabled)return;b.disabled=true;try{const before=AnyTourTourvisor.extract(document,location.href);await new Promise(r=>setTimeout(r,750));const after=AnyTourTourvisor.extract(document,location.href);before.observed_at=after.observed_at;if(JSON.stringify(before)!==JSON.stringify(after))throw new Error('Карточка ещё меняется. Дождитесь её загрузки.');const r=await chrome.runtime.sendMessage({type:'enqueue',observation:after});if(!r.ok)throw new Error(r.error||'Очередь не сохранена.');b.textContent='Проверьте цену и клиента в AnyTour';}catch(error){b.textContent='Не добавлено';let note=menu.querySelector('[data-anytour-note]');if(!note){note=document.createElement('p');note.dataset.anytourNote='1';note.setAttribute('role','status');menu.append(note)}note.textContent=error.message}finally{b.disabled=false}};
 }
}
new MutationObserver(()=>{if(!scheduled){scheduled=true;setTimeout(scan,150)}}).observe(document.body,{childList:true,subtree:true});scan();
})();
