(function(){
'use strict';let scheduled=false,formTouched=false;
// Readonly date inputs omit the year. Any form interaction invalidates this
// document's result generation, even when its visible day/month stays equal.
for(const type of ['click','keydown','input','change'])document.addEventListener(type,e=>{if(!e.target?.closest?.('input,button,[role="combobox"],[role="option"]'))return;if(AnyTourAnex.anchors(document).some(a=>AnyTourAnex.resultRow(a).contains(e.target)))return;formTouched=true},true);
async function collect(anchor,button,editing=false){
 if(button.disabled)return;button.disabled=true;button.textContent='Проверяем условия…';
 try{
  const ticket=await AnyTourCollectorClient.begin(button,editing);if(!ticket)return;
  if(formTouched)throw new Error('Форма ANEX менялась. Завершите поиск и обновите страницу результата перед импортом.');
  const before=AnyTourAnex.extract(document,anchor,location.href);await new Promise(r=>setTimeout(r,750));
  const after=AnyTourAnex.extract(document,anchor,location.href);before.observed_at=after.observed_at;
  if(formTouched||JSON.stringify(before)!==JSON.stringify(after))throw new Error('Условия ANEX ещё меняются. Дождитесь окончания поиска.');
  await AnyTourCollectorClient.append(button,ticket,after,editing);
 }catch(e){button.textContent='Не добавлено';button.title=e.message;let note=button.parentElement.querySelector('[data-anytour-note]');if(!note){note=document.createElement('div');note.dataset.anytourNote='1';note.setAttribute('role','status');button.parentElement.append(note)}note.textContent=e.message}
 finally{button.disabled=false}
}
function scan(){scheduled=false;for(const anchor of AnyTourAnex.anchors(document)){const cell=AnyTourAnex.resultRow(anchor).children[1];if(cell.querySelector('[data-anytour-add]'))continue;for(const editing of [false,true]){const b=document.createElement('button');b.type='button';b.dataset.anytourAdd='1';if(editing)b.dataset.anytourEdit='1';b.textContent=editing?'✎ Изменить цену':'В подборку AnyTour';b.title=editing?'Изменить цену перед добавлением':'Добавить без изменения цены';b.addEventListener('click',e=>{e.preventDefault();e.stopPropagation();void collect(anchor,b,editing)});cell.append(b)}}}
new MutationObserver(()=>{if(!scheduled){scheduled=true;setTimeout(scan,150)}}).observe(document.body,{childList:true,subtree:true});scan();
})();
