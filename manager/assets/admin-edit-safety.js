(function(){
const $=id=>document.getElementById(id);
const kinds=['project','manager','rule'];
const labels={project:{new:'Новый проект',edit:'Проект',section:'projects',field:'projectKey',title:'projectEditorTitle'},manager:{new:'Новый менеджер',edit:'Менеджер',section:'managers',field:'managerLogin',title:'managerEditorTitle'},rule:{new:'Новое правило',edit:'Правило',section:'priority',field:'ruleValue',title:'ruleEditorTitle'}};
function makeCancelButton(id,label){let button=$(id);if(button)return button;button=document.createElement('button');button.id=id;button.type='button';button.className='btn btnSecondary';button.textContent=label;return button}
function installControls(saveId,statusId,cancelId,cancelLabel){const save=$(saveId),status=$(statusId);if(!save||!status)return null;const row=document.createElement('div');row.className='adminFormActions';save.parentNode.insertBefore(row,save);row.appendChild(save);const cancel=makeCancelButton(cancelId,cancelLabel);row.appendChild(cancel);return cancel}
function modeLabel(kind,id,name){if(!id)return labels[kind].new;return `Редактируется ${labels[kind].edit.toLocaleLowerCase('ru')}: ${name||'#'+id}`}
function ensureModeStatus(card,modeId){let el=$(modeId);if(el)return el;el=document.createElement('div');el.id=modeId;el.className='adminEditMode';el.setAttribute('role','status');el.setAttribute('aria-live','polite');const heading=card?.querySelector('h3');if(heading)heading.insertAdjacentElement('afterend',el);return el}
function boot(){
 const projectCard=$('projectEditor')||$('projectId')?.closest('.card'),managerCard=$('managerEditor')||$('managerId')?.closest('.card'),ruleCard=$('ruleEditor')||$('ruleId')?.closest('.card');
 const projectMode=ensureModeStatus(projectCard,'projectEditMode'),managerMode=ensureModeStatus(managerCard,'managerEditMode'),ruleMode=ensureModeStatus(ruleCard,'ruleEditMode');
 const cancelProject=installControls('saveProject','projectFormStatus','cancelProjectEdit','Отменить изменения');
 const cancelManager=installControls('saveManager','managerFormStatus','cancelManagerEdit','Отменить изменения');
 const cancelRule=installControls('saveRule','ruleFormStatus','cancelRuleEdit','Отменить изменения');
 const modes={project:projectMode,manager:managerMode,rule:ruleMode},cancelButtons={project:cancelProject,manager:cancelManager,rule:cancelRule};
 const states=Object.fromEntries(kinds.map(kind=>[kind,{open:false,engaged:false,baseline:window.ManagerAdminWorkspace?.formFingerprint(kind)||'',source:null,targetId:0}]));
 let section='managers',pendingSavedFocus={};
 const busy=kind=>!!$('save'+kind[0].toUpperCase()+kind.slice(1))?.dataset.idleText;
 const fingerprint=kind=>window.ManagerAdminWorkspace?.formFingerprint(kind)||'';
 const dirty=kind=>states[kind].engaged&&states[kind].baseline!==fingerprint(kind);
 function recordName(kind){return String(kind==='manager'?$('managerName')?.value||$('managerLogin')?.value:kind==='project'?$('projectName')?.value:$('ruleValue')?.value||'').trim()}
 function syncMode(kind){const id=Number($(kind+'Id')?.value||0),name=recordName(kind),mode=modes[kind];if(mode){mode.textContent=modeLabel(kind,id,name);mode.classList.toggle('editing',id>0)}const heading=$(labels[kind].title);if(heading)heading.textContent=id?labels[kind].edit+': '+(name||'#'+id):labels[kind].new;const cancel=cancelButtons[kind];if(cancel){cancel.classList.remove('hidden');cancel.disabled=busy(kind)}if(!states[kind].engaged)states[kind].baseline=fingerprint(kind)}
 function syncProjectMode(){syncMode('project')}
 function syncManagerMode(){syncMode('manager')}
 function syncRuleMode(){syncMode('rule')}
 function syncVisible(){kinds.forEach(kind=>{const editor=$(kind+'Editor'),visible=states[kind].open&&labels[kind].section===section;if(editor){editor.classList.toggle('hidden',!visible);editor.setAttribute('aria-hidden',visible?'false':'true')}const panel=$('adminSection-'+labels[kind].section);if(panel)panel.classList.toggle('hasEditor',visible)});kinds.forEach(syncMode)}
 function rememberSource(kind){const active=document.activeElement;if(active&&(active.dataset?.adminEdit===kind||active.dataset?.adminCreate===kind))states[kind].source=active}
 function returnFocus(kind){const state=states[kind],source=state.source;if(source&&source.isConnected!==false){source.focus();return}const match=[...document.querySelectorAll('[data-admin-edit]')].find(button=>button.dataset.adminEdit===kind&&Number(button.dataset.adminId)===state.targetId);const fallback=match||[...document.querySelectorAll('[data-admin-create]')].find(button=>button.dataset.adminCreate===kind);fallback?.focus()}
 function openEditor(kind){pendingSavedFocus={};section=labels[kind].section;window.ManagerAdminWorkspace?.selectSection(section);states[kind].open=true;states[kind].engaged=true;syncVisible();$(kind+'Editor')?.scrollIntoView({block:'nearest'});$(labels[kind].field)?.focus()}
 function closeEditor(kind,focus=true){states[kind].open=false;syncVisible();if(focus&&section===labels[kind].section)returnFocus(kind)}
 function permitReplacement(kind){if(busy(kind))return false;if(!dirty(kind))return true;return typeof window.confirm==='function'?window.confirm('В форме есть несохранённые изменения. Отменить их и открыть другую запись?'):true}
 function resetProject(){ $('projectId').value='';$('projectKey').value='';$('projectName').value='';$('projectActive').checked=true }
 function resetManager(){ $('managerId').value='';$('managerLogin').value='';$('managerName').value='';$('managerEmail').value='';$('managerRole').value='manager';$('managerPassword').value='';$('managerPriority').value=0;$('managerActive').checked=true;document.querySelectorAll('#managerProjects input').forEach(x=>x.checked=false) }
 function resetRule(){ $('ruleId').value='';$('ruleValue').value='';$('ruleBonus').value=0;$('ruleComment').value='';$('ruleActive').checked=true;const manager=$('ruleManager');if(manager&&manager.options.length)manager.selectedIndex=0;const type=$('ruleType');if(type&&type.options.length)type.selectedIndex=0 }
 function reset(kind){({project:resetProject,manager:resetManager,rule:resetRule})[kind]();const status=$(kind+'FormStatus');if(status){status.textContent='';status.className='formStatus'}window.ManagerAdminLoadState?.formCancelled?.(kind);states[kind].baseline=fingerprint(kind);states[kind].engaged=false;syncMode(kind)}
 const originalEditProject=window.editProject,originalEditManager=window.editManager,originalEditRule=window.editRule;
 const original={project:originalEditProject,manager:originalEditManager,rule:originalEditRule};
 kinds.forEach(kind=>{const name='edit'+kind[0].toUpperCase()+kind.slice(1);if(typeof original[kind]==='function')window[name]=id=>{const state=states[kind],current=Number($(kind+'Id')?.value||0);if(current===Number(id)&&state.engaged){rememberSource(kind);openEditor(kind);return}if(!permitReplacement(kind))return;original[kind](id);if(Number($(kind+'Id')?.value||0)!==Number(id))return;state.targetId=Number(id);state.baseline=fingerprint(kind);state.engaged=true;rememberSource(kind);openEditor(kind)}});
 document.querySelectorAll('[data-admin-create]').forEach(button=>button.addEventListener('click',()=>{const kind=button.dataset.adminCreate;if(!states[kind]||busy(kind))return;if(Number($(kind+'Id').value||0)){if(!permitReplacement(kind))return;reset(kind)}rememberSource(kind);states[kind].targetId=0;openEditor(kind)}));
 document.querySelectorAll('[data-admin-close]').forEach(button=>button.addEventListener('click',()=>{const kind=button.dataset.adminClose;if(states[kind])closeEditor(kind)}));
 document.addEventListener?.('keydown',event=>{if(event.key!=='Escape')return;const kind=kinds.find(kind=>states[kind].open&&labels[kind].section===section);if(kind){event.preventDefault();closeEditor(kind)}});
 kinds.forEach(kind=>cancelButtons[kind]?.addEventListener('click',()=>{if(busy(kind))return;reset(kind);closeEditor(kind)}));
 const projectStatus=$('projectFormStatus'),managerStatus=$('managerFormStatus'),ruleStatus=$('ruleFormStatus');
 if(projectStatus)new MutationObserver(syncProjectMode).observe(projectStatus,{childList:true,characterData:true,subtree:true});
 if(managerStatus)new MutationObserver(syncManagerMode).observe(managerStatus,{childList:true,characterData:true,subtree:true});
 if(ruleStatus)new MutationObserver(syncRuleMode).observe(ruleStatus,{childList:true,characterData:true,subtree:true});
 const fields={project:['projectKey','projectName','projectActive'],manager:['managerLogin','managerName','managerEmail','managerRole','managerPassword','managerPriority','managerActive','managerProjects'],rule:['ruleManager','ruleType','ruleValue','ruleBonus','ruleComment','ruleActive']};
 kinds.forEach(kind=>fields[kind].forEach(id=>{['input'].forEach(event=>$(id)?.addEventListener(event,()=>{states[kind].engaged=true;syncMode(kind)}))}));
 window.ManagerAdminEditSafety={
   sync(){syncProjectMode();syncManagerMode();syncRuleMode()},
   sectionChanged(name){if(name!==section)pendingSavedFocus={};section=name;syncVisible()},
   resetIdentity(){pendingSavedFocus={};kinds.forEach(kind=>{states[kind]={open:false,engaged:false,baseline:fingerprint(kind),source:null,targetId:0}});section='managers';syncVisible()},
   saved(kind,clear,submittedFingerprint){if(!states[kind])return;states[kind].baseline=submittedFingerprint||fingerprint(kind);states[kind].targetId=Number($(kind+'Id')?.value||0)||states[kind].targetId;if(clear){states[kind].engaged=false;closeEditor(kind);pendingSavedFocus[kind]=section===labels[kind].section?{kind,section,node:document.activeElement}:null}else syncMode(kind)},
   restoreSavedFocus(kind){const pending=pendingSavedFocus[kind];if(!pending)return;delete pendingSavedFocus[kind];const active=document.activeElement;if(section===pending.section&&!states[kind].open&&(!active||active===document.body||active===pending.node||active.isConnected===false))returnFocus(kind)},
 };
 syncVisible();
}
if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',boot,{once:true});else boot();
})();
