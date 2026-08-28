(function(){
  function esc(v){const d=document.createElement('div');d.textContent=v??'';return d.innerHTML}
  function localDue(utc){if(!utc)return'Без срока';const d=new Date(String(utc).replace(' ','T')+'Z');return Number.isNaN(d.getTime())?'Без срока':d.toLocaleString('ru-RU',{day:'2-digit',month:'2-digit',hour:'2-digit',minute:'2-digit'})}
  function toIso(localValue){if(!localValue)return null;const d=new Date(localValue);return Number.isNaN(d.getTime())?null:d.toISOString()}
  function dueState(task){const state=String(task?.due_state||'');return['overdue','today','upcoming','unscheduled'].includes(state)?state:'unscheduled'}
  function urgency(state){const labels={overdue:'Просрочено',today:'Сегодня',upcoming:'Запланировано',unscheduled:'Без срока'};return labels[state]||labels.unscheduled}
  window.WorkspaceV2Tasks={
    render(root,{tasks=[],canEdit=false,onCreate,onToggle}){
      const open=tasks.filter(t=>t.status==='open'),done=tasks.filter(t=>t.status!=='open');
      root.innerHTML=`<div class="sectionTitle">Задачи и напоминания</div><div class="taskList">${open.length?open.map(t=>this.row(t,canEdit)).join(''):'<div class="muted taskEmpty">Открытых задач нет</div>'}${done.length?`<details class="doneTasks"><summary>Выполнено: ${done.length}</summary>${done.map(t=>this.row(t,canEdit)).join('')}</details>`:''}</div>${canEdit?'<div class="taskCreate"><input id="leadTaskTitle" maxlength="255" placeholder="Например: перезвонить туристу"><input id="leadTaskDue" type="datetime-local"><button id="leadTaskAdd" type="button" class="actionBtn">Добавить задачу</button></div>':'<div class="readonlyNote">Задачи изменяет ответственный менеджер или администратор.</div>'}`;
      if(canEdit){root.querySelectorAll('[data-task-toggle]').forEach(el=>el.onchange=()=>onToggle(Number(el.dataset.taskToggle),el.checked));const add=root.querySelector('#leadTaskAdd');if(add)add.onclick=()=>{const title=root.querySelector('#leadTaskTitle').value.trim(),due=toIso(root.querySelector('#leadTaskDue').value);if(!title){alert('Введите задачу');return}onCreate(title,due)}}
    },
    row(task,canEdit){const done=task.status!=='open',state=done?'done':dueState(task);return`<label class="taskRow ${done?'done':'due-'+state}">${canEdit?`<input type="checkbox" data-task-toggle="${Number(task.id)}" ${done?'checked':''}>`:'<span class="taskDot">•</span>'}<span class="taskBody"><span class="taskTitle">${esc(task.title)}</span><span class="taskMeta"><span class="taskUrgency ${esc(state)}">${esc(done?'Выполнено':urgency(state))}</span><span>${esc(localDue(task.due_at_utc))}${task.assigned_manager_name?` · ${esc(task.assigned_manager_name)}`:''}</span></span></span></label>`}
  };
})();
