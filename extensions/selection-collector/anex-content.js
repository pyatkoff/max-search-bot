(function(){
'use strict';let scheduled=false;const source=AnyTourAnex.observe(document);
async function collect(anchor,button,editing=false){
 if(button.disabled)return;button.disabled=true;AnyTourCollectorClient.label(button,'Проверяем условия…');
 try{
  const ticket=await AnyTourCollectorClient.begin(button,editing);if(!ticket)return;
  const before=source.extract(anchor,location.href);await new Promise(r=>setTimeout(r,750));
  const after=source.extract(anchor,location.href);before.observed_at=after.observed_at;
  if(JSON.stringify(before)!==JSON.stringify(after))throw new Error('Условия ANEX ещё меняются. Дождитесь окончания поиска.');
  AnyTourCollectorClient.label(button,'Добавляем фото…');
  const photos=await AnyTourCollectorClient.gallery(anchor.href,doc=>AnyTourAnex.photos(doc,after.keys.hotel));
  const fresh=source.extract(anchor,location.href);fresh.observed_at=after.observed_at;
  if(JSON.stringify(fresh)!==JSON.stringify(after))throw new Error('Условия ANEX изменились во время загрузки фото. Добавьте текущий вариант заново.');
  after.photos=photos;
  await AnyTourCollectorClient.append(button,ticket,after,editing);
 }catch(e){AnyTourCollectorClient.label(button,'Не добавлено');button.title=e.message;let note=button.parentElement.querySelector('[data-anytour-note]');if(!note){note=document.createElement('div');note.dataset.anytourNote='1';note.setAttribute('role','status');button.parentElement.append(note)}note.dataset.anytourTone='error';note.textContent=e.message}
 finally{button.disabled=false}
}
function scan(){scheduled=false;for(const anchor of AnyTourAnex.anchors(document)){const cell=AnyTourAnex.resultRow(anchor).children[1];if(cell.querySelector('[data-anytour-add]'))continue;for(const editing of [false,true]){const b=document.createElement('button');b.type='button';b.dataset.anytourAdd='1';if(editing)b.dataset.anytourEdit='1';AnyTourCollectorClient.label(b,editing?'✎ Изменить цену':'В подборку AnyTour');b.title=editing?'Изменить цену перед добавлением':'Добавить без изменения цены';b.addEventListener('click',e=>{e.preventDefault();e.stopPropagation();void collect(anchor,b,editing)});AnyTourCollectorClient.actions(cell).append(b)}}}
new MutationObserver(()=>{if(!scheduled){scheduled=true;setTimeout(scan,150)}}).observe(document.body,{childList:true,subtree:true});scan();
})();
