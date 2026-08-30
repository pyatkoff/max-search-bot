(function(){
const W=window.WorkspaceV2;if(!W)return;
const S=W.S,$=W.$;
let bound=false,busy=false;
function render(){
  const btn=$('managerShiftBtn');if(!btn)return;
  const working=!!S.manager?.is_working;
  btn.disabled=busy||!S.manager;
  btn.setAttribute('aria-pressed',working?'true':'false');
  btn.textContent=busy?'Сохраняем…':working?'Завершить смену':'Начать смену';
  btn.title=working?'Вы принимаете новые обращения':'Новые обращения не назначаются вам';
}
async function toggle(){
  if(busy||!S.manager)return;
  busy=true;render();
  const next=!S.manager.is_working;
  try{
    const j=await W.api('set_working',{working:next});
    if(!j?.ok||!j.manager)throw new Error(j?.error||'save_failed');
    S.manager=j.manager;
  }catch(e){
    alert('Не удалось изменить статус смены. Проверьте соединение и повторите.');
  }finally{busy=false;render()}
}
function bind(){
  if(bound)return;const btn=$('managerShiftBtn');if(!btn)return;
  btn.addEventListener('click',toggle);bound=true;
}
const originalBoot=W.boot;
W.boot=async function(){const result=await originalBoot();bind();render();return result};
const observer=new MutationObserver(()=>{if(!document.body.classList.contains('managerAuthOpen'))render()});
observer.observe(document.body,{attributes:true,attributeFilter:['class']});
bind();render();
window.WorkspaceV2Shift={render};
})();