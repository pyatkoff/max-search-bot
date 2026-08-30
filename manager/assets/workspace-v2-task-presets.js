(function(){
  function pad(v){return String(v).padStart(2,'0')}
  function localInputValue(date){return `${date.getFullYear()}-${pad(date.getMonth()+1)}-${pad(date.getDate())}T${pad(date.getHours())}:${pad(date.getMinutes())}`}
  function roundedHour(from){const d=new Date(from.getTime());d.setSeconds(0,0);d.setMinutes(0);d.setHours(d.getHours()+1);return d}
  function todayAt(from,hour){const d=new Date(from.getTime());d.setHours(hour,0,0,0);if(d<=from)d.setDate(d.getDate()+1);return d}
  function tomorrowAt(from,hour){const d=new Date(from.getTime());d.setDate(d.getDate()+1);d.setHours(hour,0,0,0);return d}
  function dateForPreset(preset,now=new Date()){
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
  function markup(prefix='taskDuePreset'){
    return `<div class="taskDuePresets" role="group" aria-label="Быстро выбрать срок"><button type="button" data-due-preset="hour">Через час</button><button type="button" data-due-preset="evening">Сегодня 18:00</button><button type="button" data-due-preset="tomorrow">Завтра 10:00</button></div>`;
  }
  function bind(root,input){
    if(!root||!input)return;
    root.querySelectorAll('[data-due-preset]').forEach(btn=>btn.onclick=()=>apply(input,String(btn.dataset.duePreset||'')));
  }
  window.WorkspaceV2TaskPresets={dateForPreset,localInputValue,apply,markup,bind};
})();
