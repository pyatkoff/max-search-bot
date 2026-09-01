(function(){
  function pad(v){return String(v).padStart(2,'0')}
  function localInputValue(date){return `${date.getFullYear()}-${pad(date.getMonth()+1)}-${pad(date.getDate())}T${pad(date.getHours())}:${pad(date.getMinutes())}`}
  function afterMinutes(from,minutes){const d=new Date(from.getTime());d.setSeconds(0,0);d.setMinutes(d.getMinutes()+minutes);return d}
  function roundedHour(from){const d=new Date(from.getTime());d.setSeconds(0,0);d.setMinutes(0);d.setHours(d.getHours()+1);return d}
  function todayAt(from,hour){const d=new Date(from.getTime());d.setHours(hour,0,0,0);if(d<=from)d.setDate(d.getDate()+1);return d}
  function tomorrowAt(from,hour){const d=new Date(from.getTime());d.setDate(d.getDate()+1);d.setHours(hour,0,0,0);return d}
  function sameLocalDay(a,b){return a.getFullYear()===b.getFullYear()&&a.getMonth()===b.getMonth()&&a.getDate()===b.getDate()}
  function eveningLabel(now=new Date()){
    return sameLocalDay(todayAt(now,18),now)?'Сегодня 18:00':'Завтра 18:00';
  }
  function dateForPreset(preset,now=new Date()){
    if(preset==='quarter')return afterMinutes(now,15);
    if(preset==='hour')return roundedHour(now);
    if(preset==='evening')return todayAt(now,18);
    if(preset==='tomorrow')return tomorrowAt(now,10);
    return null;
  }
  function apply(input,preset,now=new Date()){
    if(!input)return false;
    const date=dateForPreset(preset,now);if(!date)return false;
    input.value=localInputValue(date);
    input.dispatchEvent(new Event('change',{bubbles:true}));
    return true;
  }
  function markup(now=new Date()){
    return `<div class="taskDuePresets" role="group" aria-label="Быстро выбрать срок"><button type="button" data-due-preset="quarter">Через 15 мин</button><button type="button" data-due-preset="hour">Через час</button><button type="button" data-due-preset="evening">${eveningLabel(now)}</button><button type="button" data-due-preset="tomorrow">Завтра 10:00</button></div>`;
  }
  function enhance(form,input){
    if(!form||!input||form.querySelector('.taskDuePresets'))return;
    input.insertAdjacentHTML('afterend',markup());
    const presets=input.nextElementSibling;
    presets?.querySelectorAll('[data-due-preset]').forEach(btn=>btn.onclick=()=>apply(input,String(btn.dataset.duePreset||'')));
  }
  function enhanceAll(root=document){
    const create=root.querySelector?.('.taskCreate');
    if(create)enhance(create,create.querySelector('#leadTaskDue'));
    root.querySelectorAll?.('.taskEditForm').forEach(form=>enhance(form,form.querySelector('[data-task-edit-due]')));
    root.querySelectorAll?.('.kanbanQuickTaskForm').forEach(form=>enhance(form,form.querySelector('.kanbanTaskDue')));
  }
  let observing=false;
  function start(){
    enhanceAll();
    const root=document.getElementById('leadCard');
    if(root&&!observing){observer.observe(root,{childList:true,subtree:true});observing=true}
  }
  const observer=new MutationObserver(mutations=>mutations.forEach(m=>m.addedNodes.forEach(node=>{if(node.nodeType===1)enhanceAll(node.closest?.('#leadTasksBody')||node)})));
  if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',start,{once:true});else start();
  window.WorkspaceV2TaskPresets={dateForPreset,localInputValue,eveningLabel,apply,enhanceAll};
})();
