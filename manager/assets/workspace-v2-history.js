(function(){
const W=window.WorkspaceV2;if(!W)return;const {S,$,api}=W;
let adapter=null,version=0,shortViewport=null,placementBound=false,state={key:'',messages:[],before:0,hasMore:false,gapFrontier:0,paging:false,error:'',flight:null};
const positive=id=>Number.isSafeInteger(Number(id))&&Number(id)>0;
function key(){const context=adapter?.capture();return context?JSON.stringify([context.conversationId,context.managerId,context.authGeneration,context.openGeneration]):''}
function metadata(detail){const h=detail?.history;return h&&typeof h.has_more==='boolean'&&['latest','older'].includes(h.mode)&&Number.isSafeInteger(Number(h.before_id))&&Number(h.before_id)>=0&&Number.isSafeInteger(Number(h.page_size))&&Number(h.page_size)>0&&Number(h.page_size)<=200?h:null}
function sortedUnion(messages){const keyed=new Map(),extra=[];for(const m of messages||[]){if(positive(m?.id))keyed.set(Number(m.id),m);else extra.push(m)}return[...keyed].sort((a,b)=>a[0]-b[0]).map(entry=>entry[1]).concat(extra)}
function controls(){return $('conversationHistoryControls')}
function ensureControls(){let root=controls();const box=$('messages');if(root||!box||typeof box.before!=='function')return root;root=document.createElement('div');root.id='conversationHistoryControls';root.className='conversationHistoryControls hidden';const button=document.createElement('button');button.id='conversationLoadOlder';button.type='button';button.className='actionBtn';button.textContent='Загрузить предыдущие сообщения';button.onclick=()=>loadOlder();const status=document.createElement('span');status.id='conversationHistoryStatus';status.setAttribute('role','status');status.setAttribute('aria-live','polite');root.appendChild(button);root.appendChild(status);box.before(root);return root}
function placeControls(root){
 if(!shortViewport&&typeof window.matchMedia==='function')shortViewport=window.matchMedia('(max-height: 540px)');
 if(shortViewport&&!placementBound){placementBound=true;if(typeof shortViewport.addEventListener==='function')shortViewport.addEventListener('change',render);else shortViewport.addListener?.(render)}
 const tools=document.querySelector?.('#conversationZone .conversationTools'),menu=shortViewport?.matches?window.Manager2Presentation?.conversationHistoryTarget?.():null,compact=!!shortViewport?.matches&&!!(menu||tools),box=$('messages'),button=$('conversationLoadOlder'),focused=document.activeElement,restoreFocus=focused&&(root.contains?.(focused)||focused===button);
 let moved=false;
 if(menu&&button){
  // Only the action goes into the compact menu; loading, retry and gap notices
  // stay visible before the transcript even when that menu is closed.
  if(box&&typeof box.before==='function'&&(root.parentElement!==box.parentElement||root.nextSibling!==box)){box.before(root);moved=true}
  if(button.parentElement!==menu){menu.appendChild(button);moved=true}
 }else{
  if(button&&button.parentElement!==root){root.insertBefore(button,root.firstChild);moved=true}
  if(compact&&root.parentElement!==tools){tools.appendChild(root);moved=true}else if(!compact&&box&&typeof box.before==='function'&&(root.parentElement!==box.parentElement||root.nextSibling!==box)){box.before(root);moved=true}
 }
 root.classList.toggle('compact',compact);root.classList.toggle('menuAction',!!menu);
 return{compact,menu,focus:moved&&restoreFocus?focused:null};
}
function render(){const root=ensureControls(),button=$('conversationLoadOlder'),status=$('conversationHistoryStatus');if(!root)return;const {compact,menu,focus}=placeControls(root),current=state.key&&state.key===key()&&!S.authExpired,visible=!!current&&(state.hasMore||state.paging||!!state.error),text=state.error||(state.paging?'Переписка и ответ остаются доступны.':state.gapFrontier?'Между загруженными частями могут быть сообщения. Загрузите предыдущие, чтобы заполнить промежуток.':'');root.classList.toggle('hidden',!visible||(!!menu&&!text));if(menu)menu.hidden=!visible;if(button){button.disabled=state.paging||!state.hasMore||!adapter?.canLoad();button.textContent=compact?(state.paging?'Загрузка…':state.error?'Повторить':'История'):(state.paging?'Загружаем предыдущие…':state.error?'Повторить загрузку предыдущих':'Загрузить предыдущие сообщения');const label=state.error?'Повторить загрузку предыдущих сообщений':'Загрузить предыдущие сообщения';button.setAttribute('aria-label',label);button.title=label}if(status)status.textContent=text;const scope=$('conversationSearchScope');if(scope&&current&&metadata(S.detail))scope.textContent='Поиск по '+state.messages.length+' загруженным сообщениям. '+(state.hasMore?'Предыдущие сообщения можно загрузить отдельно. ':'')+'Цитаты не проверяются.';if(focus&&visible){if(!menu||focus!==button||!window.Manager2Presentation?.focusConversationTool?.(button.id))focus.focus?.({preventScroll:true})}}
function reset(){version++;state={key:'',messages:[],before:0,hasMore:false,gapFrontier:0,paging:false,error:'',flight:null};render()}
function applyLatest(detail,{reset:restart=false,retainLoaded=false}={}){
 const h=metadata(detail),next=sortedUnion(detail?.messages||[]),currentKey=key();if(!h){reset();return next}
 const previous=state.messages,positiveRows=next.filter(m=>positive(m.id)),cutoff=positiveRows.length?Number(positiveRows[0].id):0;
 if(restart||state.key!==currentKey){
   let retained=[];if(retainLoaded&&state.key&&JSON.parse(state.key).slice(0,3).every((value,index)=>value===JSON.parse(currentKey)[index])&&h.has_more)retained=cutoff?previous.filter(m=>positive(m.id)&&Number(m.id)<cutoff):[];
   version++;state={key:currentKey,messages:sortedUnion([...retained,...next]),before:retained.length?(state.gapFrontier?state.before:Math.min(...retained.map(m=>Number(m.id)))):Number(h.before_id),hasMore:retained.length?state.hasMore:h.has_more,gapFrontier:retained.length?state.gapFrontier:0,paging:false,error:'',flight:null};
 }else{
   const older=h.has_more&&cutoff?previous.filter(m=>positive(m.id)&&Number(m.id)<cutoff):[];state.messages=sortedUnion([...older,...next]);
   if(!h.has_more){state.before=Number(h.before_id);state.hasMore=false;state.gapFrontier=0}else if(!state.before){state.before=Number(h.before_id);state.hasMore=h.has_more}
 }
 // IDs are global and need not be consecutive. A newer window with no retained
 // overlap may skip an unknown number of this conversation's messages.
 const priorIds=new Set(previous.filter(m=>positive(m.id)).map(m=>Number(m.id))),priorMax=priorIds.size?Math.max(...priorIds):0;
 if(h.has_more&&cutoff&&priorMax&&cutoff>priorMax&&!positiveRows.some(m=>priorIds.has(Number(m.id)))&&state.messages.some(m=>priorIds.has(Number(m.id)))){state.gapFrontier=state.gapFrontier||priorMax;state.before=cutoff;state.hasMore=true}
 render();return state.messages;
}
async function loadOlder(){
 if(!adapter?.canLoad()||state.key!==key()||!state.hasMore||!positive(state.before))return false;
 if(state.flight)return state.flight;
 const captured=adapter.capture(),capturedVersion=version,record=state,before=state.before,target=Number(S.current);record.paging=true;record.error='';render();
 const current=()=>version===capturedVersion&&state===record&&record.before===before&&adapter.isCurrent(captured)&&record.key===key();
 const work=(async()=>{try{const detail=await api('detail',{conversation_id:target,before_id:before});if(!current())return false;if(!detail?.ok){if([403,404].includes(detail?.http_status))adapter.accessLost();else record.error='Не удалось загрузить предыдущие сообщения. Уже загруженная переписка и черновик сохранены.';return false}const h=metadata(detail),rows=detail.messages;if(Number(detail.conversation?.id)!==target||!h||h.mode!=='older'||!Array.isArray(rows)||rows.length>Number(h.page_size)||Number(h.before_id)!==(rows.length?Math.min(...rows.map(m=>Number(m.id))):0)||rows.some(m=>!positive(m?.id)||Number(m.id)>=before)||Number(h.before_id)>=before||(h.has_more&&!positive(h.before_id))){record.error='Не удалось подтвердить предыдущую часть переписки. Повторите загрузку.';return false}const lower=h.has_more?Number(h.before_id):0,kept=record.messages.filter(m=>!positive(m.id)||Number(m.id)<lower||Number(m.id)>=before);record.messages=sortedUnion([...kept,...rows]);record.before=Number(h.before_id);if(record.gapFrontier&&(!h.has_more||lower<=record.gapFrontier))record.gapFrontier=0;record.hasMore=h.has_more;record.error='';S.detail={...S.detail,messages:record.messages,has_manager_reply:typeof S.detail.has_manager_reply==='boolean'?S.detail.has_manager_reply:detail.has_manager_reply};adapter.render(record.messages,{preserveScroll:true,prepend:true});return true}catch(e){if(current())record.error='Не удалось загрузить предыдущие сообщения. Уже загруженная переписка и черновик сохранены.';return false}})();
 const flight=work.finally(()=>{if(state===record&&record.flight===flight){record.flight=null;record.paging=false;render()}});record.flight=flight;return flight;
}
function bind(value){adapter=value;ensureControls();render()}
window.WorkspaceV2History={bind,reset,render,applyLatest,loadOlder,getState:()=>({before_id:state.before,has_more:state.hasMore,paging:state.paging,error:state.error,has_gap:!!state.gapFrontier,loaded:state.messages.length})};
})();
