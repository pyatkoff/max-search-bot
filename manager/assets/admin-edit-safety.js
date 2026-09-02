(function(){
const $=id=>document.getElementById(id);
function makeCancelButton(id,label){let button=$(id);if(button)return button;button=document.createElement('button');button.id=id;button.type='button';button.className='btn btnSecondary hidden';button.textContent=label;return button}
function installControls(saveId,statusId,cancelId,cancelLabel){const save=$(saveId),status=$(statusId);if(!save||!status)return null;const row=document.createElement('div');row.className='adminFormActions';save.parentNode.insertBefore(row,save);row.appendChild(save);const cancel=makeCancelButton(cancelId,cancelLabel);row.appendChild(cancel);return cancel}
function modeLabel(kind,id,name){if(!id)return `Новый ${kind}`;return `Редактируется ${kind}: ${name||'#'+id}`}
function ensureModeStatus(card,modeId){let el=$(modeId);if(el)return el;el=document.createElement('div');el.id=modeId;el.className='adminEditMode';el.setAttribute('role','status');el.setAttribute('aria-live','polite');const heading=card?.querySelector('h3');if(heading)heading.insertAdjacentElement('afterend',el);return el}
function boot(){
 const projectCard=$('projectId')?.closest('.card'),managerCard=$('managerId')?.closest('.card');
 const projectMode=ensureModeStatus(projectCard,'projectEditMode'),managerMode=ensureModeStatus(managerCard,'managerEditMode');
 const cancelProject=installControls('saveProject','projectFormStatus','cancelProjectEdit','Отменить редактирование');
 const cancelManager=installControls('saveManager','managerFormStatus','cancelManagerEdit','Отменить редактирование');
 const originalEditProject=window.editProject,originalEditManager=window.editManager;
 function syncProjectMode(){const id=Number($('projectId')?.value||0),name=String($('projectName')?.value||'').trim();if(projectMode){projectMode.textContent=modeLabel('проект',id,name);projectMode.classList.toggle('editing',id>0)}if(cancelProject)cancelProject.classList.toggle('hidden',id<=0)}
 function syncManagerMode(){const id=Number($('managerId')?.value||0),name=String($('managerName')?.value||$('managerLogin')?.value||'').trim();if(managerMode){managerMode.textContent=modeLabel('менеджер',id,name);managerMode.classList.toggle('editing',id>0)}if(cancelManager)cancelManager.classList.toggle('hidden',id<=0)}
 if(typeof originalEditProject==='function')window.editProject=id=>{originalEditProject(id);syncProjectMode();projectMode?.scrollIntoView({block:'nearest'})};
 if(typeof originalEditManager==='function')window.editManager=id=>{originalEditManager(id);syncManagerMode();managerMode?.scrollIntoView({block:'nearest'})};
 cancelProject?.addEventListener('click',()=>{if($('saveProject')?.disabled)return;$('projectId').value='';$('projectKey').value='';$('projectName').value='';$('projectActive').checked=true;$('projectFormStatus').textContent='';$('projectFormStatus').className='formStatus';syncProjectMode();$('projectKey')?.focus()});
 cancelManager?.addEventListener('click',()=>{if($('saveManager')?.disabled)return;$('managerId').value='';$('managerLogin').value='';$('managerName').value='';$('managerEmail').value='';$('managerRole').value='manager';$('managerPassword').value='';$('managerPriority').value=0;$('managerActive').checked=true;document.querySelectorAll('#managerProjects input').forEach(x=>x.checked=false);$('managerFormStatus').textContent='';$('managerFormStatus').className='formStatus';syncManagerMode();$('managerLogin')?.focus()});
 const projectStatus=$('projectFormStatus'),managerStatus=$('managerFormStatus');
 if(projectStatus)new MutationObserver(syncProjectMode).observe(projectStatus,{childList:true,characterData:true,subtree:true});
 if(managerStatus)new MutationObserver(syncManagerMode).observe(managerStatus,{childList:true,characterData:true,subtree:true});
 $('projectName')?.addEventListener('input',syncProjectMode);$('managerName')?.addEventListener('input',syncManagerMode);$('managerLogin')?.addEventListener('input',syncManagerMode);
 syncProjectMode();syncManagerMode();
}
if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',boot,{once:true});else boot();
})();
