(function(){
'use strict';
const $=id=>document.getElementById(id),url=new URL(location.href),positive=v=>/^[1-9]\d{0,14}$/.test(v||'')&&Number.isSafeInteger(Number(v));
const hint=url.searchParams,conversation=hint.getAll('conversation_id').length===1&&positive(hint.get('conversation_id'))?Number(hint.get('conversation_id')):0,expected=hint.getAll('editor_actor').length===1&&positive(hint.get('editor_actor'))?Number(hint.get('editor_actor')):0;
let generation=0,context=null,csrf='',checking=false,editor=null,bootstrapController=null;
function message(text,login=false){$('editorMessage').textContent=text;$('editorSignIn').hidden=!login}
function suspend(){
  ++generation;bootstrapController?.abort();context=null;csrf='';editor?.suspend();
  for(const id of ['tsEditor','tsList','tsRecovery','tsTitle','tsRecipient'])$(id)?.replaceChildren();
  $('editorContext').textContent='Доступ к подборке не подтверждён.';
}
const current=target=>!!context&&!!target&&target.actor===expected&&target.actor===context.actor&&target.conversation===conversation&&target.auth===generation;
const capture=()=>({actor:context?.actor||0,conversation:context?.id||0,auth:generation,open:generation});
const host={contract:'tour-selection-editor-host-v1',origin:location.origin,capture,isCurrent:current,
  canOpen:c=>!!context&&c?.id===conversation&&context.actor===expected,
  canEdit:c=>c?.can_edit===true,launcher:()=>$('editorLauncher'),formatTime:(v,o)=>ManagerTime.format(v,o),
  takeCollectorIntent:()=>false,activate(){},
  openCollector:id=>{if(context&&id===conversation)window.open('/manager/tour-selection-collector.php?conversation_id='+conversation,'_blank','noopener')},
  async request(resource,action,data,options={}){
    const token=generation;if(!context||options.isCurrent&&!options.isCurrent())return{ok:false,error:'stale_context'};
    const result=await ManagerHttpClient.request(action,{...data,editor_actor:expected,editor_resource:resource},csrf,'tour-selection-editor-api.php',{signal:options.signal});
    if(token!==generation||options.isCurrent&&!options.isCurrent())return{ok:false,error:'stale_context'};
    if(['unauthorized','csrf','stale_actor'].includes(result.error)){
      suspend();message('Сессия изменилась. Войдите в Manager2 и снова проверьте доступ. Черновик сохранён отдельно для прежнего аккаунта.',true);
      return{ok:false,error:'stale_context'};
    }
    return result;
  }
};
async function start(){
  if(checking)return;checking=true;$('editorRetry').disabled=true;suspend();const token=generation;
  const controller=new AbortController();bootstrapController=controller;const timeout=setTimeout(()=>controller.abort(),20000);
  try{
    if(!conversation||!expected){message('Выберите клиента в Manager2 и откройте его подборку отдельно.');return}
    message('Проверяем ваш аккаунт и доступ к выбранному клиенту…');
    const me=await ManagerHttpClient.request('me',{},'','api.php',{signal:controller.signal});if(token!==generation)return;
    if(!me.ok||!me.csrf){message('Для доступа к подборке войдите в существующий аккаунт Manager2, затем нажмите «Проверить доступ и открыть».',true);return}
    if(me.manager?.id!==expected){message('В Manager2 открыт другой аккаунт. Выберите клиента заново из нужного аккаунта; эта ссылка не перенесена на него.',true);return}
    const result=await ManagerHttpClient.request('editor_context',{editor_actor:expected,conversation_id:conversation},me.csrf,'tour-selection-editor-api.php',{signal:controller.signal});
    if(token!==generation)return;
    const c=result.context;
    if(!result.ok||c?.actor!==expected||c?.id!==conversation||typeof c.can_edit!=='boolean'){
      message(['not_found','forbidden'].includes(result.error)?'Подборка недоступна вашему аккаунту. Откройте доступного клиента в Manager2.':'Не удалось подтвердить доступ. Проверьте соединение и повторите.',result.error==='unauthorized');return;
    }
    context=c;csrf=me.csrf;$('editorContext').textContent=c.display_name+' · '+(c.channel==='telegram'?'Telegram':c.channel==='max'?'MAX':c.channel);
    editor??=AnyTourSelectionEditor.mount(Object.freeze(host));editor.sync(c);message('Клиент подтверждён. Добавление и правки не отправляют сообщения.');await editor.open(c);
  }finally{clearTimeout(timeout);if(bootstrapController===controller)bootstrapController=null;checking=false;$('editorRetry').disabled=false}
}
$('editorRetry').onclick=()=>void start();
// BFCache and a hidden tab never retain a usable old account/generation.
window.addEventListener('pagehide',()=>{suspend();message('Снова проверьте доступ к подборке.')});
document.addEventListener('visibilitychange',()=>{if(document.hidden){suspend();message('Нажмите «Проверить доступ и открыть», чтобы продолжить.')}});
window.addEventListener('pageshow',event=>{if(event.persisted){suspend();message('Нажмите «Проверить доступ и открыть», чтобы продолжить.')}});
void start();
})();
