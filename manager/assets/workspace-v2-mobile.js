(function(){
const W=window.WorkspaceV2,{S,$}=W;let bound=false,screen='inbox',navigationSeq=0;
let overlay=null,overlaySequence=0,overlayClosing=null;
const isMobile=()=>window.matchMedia(document.body.classList.contains('manager2')?'(max-width:1100px)':'(max-width:900px)').matches;
function currentState(next=screen){return{workspaceV2:true,manager_id:Number(S.manager?.id||0),screen:next,conversation_id:Number(S.current||0)}}
function afterOverlayClose(task){if(overlayClosing)overlayClosing.promise.then(task);else task()}
function openOverlay(id,onBack){
 if(overlay?.id===id)return overlay.token;
 if(overlay)return false;
 const item={id,token:Date.now().toString(36)+':'+(++overlaySequence),managerId:Number(S.manager?.id||0),generation:S.authGeneration,onBack,pushed:false};overlay=item;
 afterOverlayClose(()=>{
  if(overlay!==item||Number(S.manager?.id||0)!==item.managerId||S.authGeneration!==item.generation||S.authExpired)return;
  const state=isMobile()?currentState():{...(history.state||{})};
  history.pushState({...state,workspaceV2Overlay:{id,token:item.token}},'',location.href);item.pushed=true;
 });
 return item.token;
}
function closeOverlay(id){
 const item=overlay;if(!item||item.id!==id)return false;overlay=null;
 if(!item.pushed||history.state?.workspaceV2Overlay?.token!==item.token)return true;
 let resolve;const closing={promise:new Promise(done=>{resolve=done}),resolve:()=>resolve()};overlayClosing=closing;
 // Closing by Escape/button removes the same entry that browser Back consumes.
 // New opens/navigation wait for that pop, so its late event cannot close them.
 try{history.back()}catch(error){overlayClosing=null;closing.resolve();const state={...(history.state||{})};delete state.workspaceV2Overlay;history.replaceState(state,'',location.href)}
 return true;
}
function consumeOverlayBack(event){
 if(overlayClosing){const closing=overlayClosing;overlayClosing=null;closing.resolve();event.workspaceOverlayHandled=true;return true}
 if(overlay){
  if(event.state?.workspaceV2Overlay?.token===overlay.token){event.workspaceOverlayHandled=true;return true}
  const item=overlay;overlay=null;event.workspaceOverlayHandled=true;
  if(item.managerId===Number(S.manager?.id||0)&&item.generation===S.authGeneration&&!S.authExpired)item.onBack?.();
  return true;
 }
 if(event.state?.workspaceV2Overlay){const state={...event.state};delete state.workspaceV2Overlay;history.replaceState(state,'',location.href)}
 return false;
}
function apply(next){screen=next==='lead'?'lead':next==='conversation'?'conversation':'inbox';const conversation=$('conversationZone'),lead=$('leadZone'),root=$('workspaceRoot');if(!conversation||!lead||!root)return;root.dataset.mobileScreen=screen;conversation.classList.toggle('open',screen==='conversation'||screen==='lead');lead.classList.toggle('open',screen==='lead');document.body.classList.toggle('workspaceMobileDetail',isMobile()&&screen!=='inbox')}
function record(next,{replace=false}={}){if(!isMobile())return;const state=currentState(next),generation=S.authGeneration;afterOverlayClose(()=>{if(Number(S.manager?.id||0)!==state.manager_id||S.authGeneration!==generation||S.authExpired)return;if(replace)history.replaceState(state,'',location.href);else history.pushState(state,'',location.href)})}
function beginNavigation(){navigationSeq++;window.WorkspaceV2Conversation?.cancelPendingOpen?.();return navigationSeq}
function showInbox({historyMode='push'}={}){beginNavigation();if(!isMobile()){apply('inbox');return}apply('inbox');window.WorkspaceV2Conversation?.rememberSelection(0);if(historyMode==='replace')record('inbox',{replace:true});else if(historyMode==='push')record('inbox')}
function conversationOpened(id,{historyMode='push'}={}){if(!isMobile())return;if(historyMode!=='none')beginNavigation();S.current=Number(id||S.current||0);apply('conversation');if(historyMode==='none')return;record('conversation',{replace:historyMode==='replace'})}
function showLead({historyMode='push'}={}){if(!isMobile()||!S.current)return;beginNavigation();apply('lead');if(historyMode==='none')return;record('lead',{replace:historyMode==='replace'})}
function back(){if(overlay){history.back();return}if(!isMobile())return;if(screen==='lead'||screen==='conversation'){history.back();return}showInbox({historyMode:'none'})}
async function restore(state){
 if(!isMobile())return;
 const seq=beginNavigation(),manager=Number(S.manager?.id||0),generation=S.authGeneration;
 const current=()=>seq===navigationSeq&&manager===Number(S.manager?.id||0)&&generation===S.authGeneration&&!S.authExpired&&isMobile();
 const target=state?.workspaceV2&&Number(state.manager_id)===manager?state.screen:'inbox',conversationId=Number(state?.conversation_id||0);
 if(target==='lead'||target==='conversation'){
  if(conversationId&&conversationId!==Number(S.current)){
   const pending=window.WorkspaceV2Conversation?.open(conversationId,{mobileHistory:'none',preserveMessageScroll:true}),openGeneration=window.WorkspaceV2Conversation?.getOpenGeneration?.();
   const opened=await pending;
   // A late restore must not hide a newer client, erase its reload selection or
   // finish another pending transition. Conversation owns revoking its reads.
   if(!current()||openGeneration!==window.WorkspaceV2Conversation?.getOpenGeneration?.())return;
   if(!opened){apply('inbox');window.WorkspaceV2Conversation?.rememberSelection(0);return}
  }else if(!S.current){apply('inbox');window.WorkspaceV2Conversation?.rememberSelection(0);return}
  window.WorkspaceV2Conversation?.rememberSelection(S.current);apply(target);
 }else{apply('inbox');window.WorkspaceV2Conversation?.rememberSelection(0)}
}
function bind(){if(bound)return;bound=true;if(isMobile()&&(!history.state?.workspaceV2||Number(history.state.manager_id)!==Number(S.manager?.id)))history.replaceState(currentState('inbox'),'',location.href);apply('inbox');$('mobileBack')?.addEventListener('click',e=>{e.preventDefault();back()});$('mobileLeadBtn')?.addEventListener('click',e=>{e.preventDefault();showLead()});$('mobileLeadClose')?.addEventListener('click',e=>{e.preventDefault();back()});window.addEventListener('popstate',e=>{if(!consumeOverlayBack(e))restore(e.state)});window.addEventListener('resize',()=>{if(!isMobile()){document.body.classList.remove('workspaceMobileDetail');$('workspaceRoot')?.removeAttribute('data-mobile-screen');$('conversationZone')?.classList.remove('open');$('leadZone')?.classList.remove('open');return}apply(screen)})}
function reloadHistoryMode(){return history.state?.workspaceV2&&Number(history.state.manager_id)===Number(S.manager?.id)&&['conversation','lead'].includes(history.state.screen)?'replace':'push'}
window.WorkspaceV2Mobile={bind,reloadHistoryMode,showInbox,conversationOpened,showLead,back,restore,openOverlay,closeOverlay,getScreen:()=>screen,isMobile};
})();
