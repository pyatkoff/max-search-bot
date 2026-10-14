(function(){
'use strict';
const W=window.WorkspaceV2;if(!W)return;
function render(container,event){
  if(!container)return;
  container.replaceChildren();container.hidden=true;
  if(!event||event.event_type!=='ai_resumed'||event.reason!=='handoff_cancelled'||!Number.isSafeInteger(event.event_id)||event.event_id<=0||!/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/.test(event.created_at||''))return;
  const title=document.createElement('strong');title.textContent='Клиент отменил ожидание менеджера';
  const text=document.createElement('span');text.textContent='Клиент выбрал продолжение с ботом.';
  const time=document.createElement('time');
  const date=window.ManagerTime?.parse(event.created_at);if(!date)return;
  time.dateTime=date.toISOString();time.textContent=window.ManagerTime.format(event.created_at,{year:true})+' МСК';
  container.classList.add('handoffCancellation');container.append(title,text,time);container.hidden=false;
}
W.HandoffCancellation={render};
})();
