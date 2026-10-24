(function(){
'use strict';
// Only local operation state crosses back to a TO content script. No client,
// trip, connection capability or server response body enters the source DOM.
const pending=new WeakMap(),pause=()=>new Promise(r=>setTimeout(r,250));
function note(button,message){
 let el=button.parentElement?.querySelector('[data-anytour-note]');if(!button.parentElement)return;
 if(!el){el=document.createElement('div');el.dataset.anytourNote='1';el.setAttribute('role','status');button.parentElement.append(el)}
 el.textContent=message;button.title=message;
}
async function follow(button,operation){
 for(let attempt=0;attempt<40&&button.isConnected;attempt++){
  let result;try{result=await chrome.runtime.sendMessage({type:'add_status',operation_id:operation})}catch{}
  if(!result?.ok){button.textContent='Проверьте в AnyTour';note(button,'Результат пока не подтверждён. Проверьте очередь в расширении AnyTour. Повторного добавления нет.');return}
  if(result.state==='cancelled'){pending.delete(button);button.textContent=button.dataset.anytourEdit?'✎ Изменить цену':'В подборку AnyTour';note(button,'Правка отменена. Тур не добавлен.');return 'cancelled'}
  if(result.state==='editing'){button.textContent='Правка в AnyTour';note(button,'Откройте значок расширения AnyTour: там можно изменить цену и явно добавить тур. Пока он не добавлен.');return}
  if(result.state==='saved'){pending.delete(button);button.textContent='✓ В подборке';note(button,'');button.title='Предложение сохранено в выбранной подборке. Туристу ничего не отправлено.';return}
  if(result.state!=='queued'){
   const message=result.state==='waiting'?'Очередь сохранена, но сервер ещё не подтвердил добавление. Откройте расширение AnyTour.':result.error==='connection_changed'?'Клиент или поездка изменились. Проверьте исходную операцию в расширении AnyTour.':'Нужна проверка цены, похожего варианта или подключения. Откройте расширение AnyTour.';
   button.textContent=result.state==='waiting'?'В очереди AnyTour':'Проверьте в AnyTour';note(button,message);return;
  }
  button.textContent='Сохраняем…';await pause();
 }
 if(button.isConnected){button.textContent='В очереди AnyTour';note(button,'Предложение в очереди. Откройте расширение AnyTour или нажмите здесь, чтобы проверить результат без нового добавления.')}
}
window.AnyTourCollectorClient=Object.freeze({
 async begin(button,editing=false){
  const operation=pending.get(button);if(operation){if(editing)try{await chrome.runtime.sendMessage({type:'show_edit',operation_id:operation})}catch{}if(await follow(button,operation)!=='cancelled')return null}
  const result=await chrome.runtime.sendMessage({type:'begin_add'});
  if(!result?.ok||!/^[a-f0-9]{32}$/.test(result.ticket||''))throw new Error(result?.error||'Не удалось подтвердить выбранную подборку. Откройте расширение AnyTour.');
  note(button,'');
  return result.ticket;
 },
 async append(button,ticket,observation,editing=false){
  // The click's random ID is known before the durable enqueue. A lost reply
  // therefore checks that exact intent instead of claiming failure or replaying.
  pending.set(button,ticket);let result;
  try{result=await chrome.runtime.sendMessage({type:editing?'enqueue_edit':'enqueue',ticket,observation})}catch{}
  if(result?.ok===false&&result.error&&result.error!=='storage_unavailable'){pending.delete(button);throw new Error(result.error)}
  await follow(button,ticket);
 }
});
})();
