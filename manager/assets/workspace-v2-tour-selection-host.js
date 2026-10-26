(function(){
'use strict';
// Manager2 alone owns session recovery, authorized conversation navigation and
// request/CSRF handling. The editor receives this boundary, never workspace state.
const W=window.WorkspaceV2,{S}=W,contract='tour-selection-editor-host-v1';
let intent=(()=>{const u=new URL(location.href),value=u.searchParams.get('selection'),owner=u.searchParams.get('collector_actor');if(u.searchParams.getAll('selection').length!==1||u.searchParams.getAll('collector_actor').length!==1||!/^\d+$/.test(value||'')||!/^\d+$/.test(owner||''))return null;const conversation=Number(value),manager=Number(owner);return Number.isSafeInteger(conversation)&&conversation>0&&String(conversation)===value&&Number.isSafeInteger(manager)&&manager>0&&String(manager)===owner&&u.hash==='#conversation='+value?{conversation,manager}:null})();
function clearIntent(){intent=null;const u=new URL(location.href);u.searchParams.delete('selection');u.searchParams.delete('collector_actor');history.replaceState(history.state,'',u.pathname+u.search+u.hash)}
function actor(){return S.authExpired?0:Number(S.manager?.id||0)}
function capture(c=S.detail?.conversation){return {actor:actor(),conversation:Number(c?.id||0),auth:Number(S.authGeneration||0),open:Number(window.WorkspaceV2Conversation?.getOpenGeneration?.()||0)}}
function isCurrent(target){const now=capture();return !!target&&target.actor>0&&target.conversation>0&&target.actor===now.actor&&target.conversation===now.conversation&&target.conversation===Number(S.current)&&target.auth===now.auth&&target.open===now.open}
const host={
  contract,origin:location.origin,capture,isCurrent,
  canOpen:c=>!!actor()&&Number(c?.id)===Number(S.current),
  canEdit:c=>c.status!=='closed'&&(S.manager?.role==='admin'||Number(c.manager_id)===actor()),
  launcher:()=>document.querySelector(window.matchMedia('(max-width:740px)').matches?'.m2ConversationMenu>div':'.conversationTitleRow'),
  request:(resource,action,data,options)=>W.request(resource,action,data,options),
  formatTime:(value,options)=>window.ManagerTime.format(value,options),
  openCollector:conversation=>window.open('tour-selection-collector.php?conversation_id='+conversation,'_blank','noopener'),
  takeCollectorIntent:target=>{if(!intent)return false;const previous=intent;clearIntent();return previous.manager===target.actor&&previous.conversation===target.conversation},
  activate:()=>{if(intent&&actor()&&intent.manager!==actor())clearIntent()}
};
const standalone=document.body.dataset.selectionEditorEntry;
if(standalone==='https://tours.anytoour.ru/editor.php')host.openStandalone=conversation=>{
  const target=capture();if(isCurrent(target)&&target.conversation===conversation)window.open(standalone+'?conversation_id='+conversation+'&editor_actor='+target.actor,'_blank','noopener');
};
const editor=window.AnyTourSelectionEditor.mount(Object.freeze(host));
// Preserve the existing workspace lifecycle caller contract.
window.WorkspaceV2TourSelection=editor;
window.addEventListener('resize',()=>{if(S.detail?.conversation)editor.sync(S.detail.conversation)});
})();
