'use strict';
const path=require('node:path');
const TARGETS=new Map([
  ['manager-ai-draft.spec.js','current-trip panel closes on conversation and account change, ignores late profile reads'],
  ['manager-assistant.spec.js','Manager assistant unifies list and current-client advice without touching reply 390'],
  ['manager-manual-groups.spec.js','manual group 390: one name creates a tab; two pointer/keyboard actions add or remove without replacing the conversation']
]);
const MAX_EVENTS=96,MAX_CONTEXTS=32,MAX_OUTPUT=24576;
function safeUrl(value){
  try{
    const u=new URL(value);
    if(!['127.0.0.1','localhost','[::1]'].includes(u.hostname))return u.protocol==='about:'?'about:blank':'non-loopback';
    const query=new URLSearchParams();
    for(const key of ['qa','conversation','viewer','workspace'])if(/^\d{1,12}$/.test(u.searchParams.get(key)||''))query.set(key,u.searchParams.get(key));
    return u.origin+u.pathname.slice(0,160)+(query.size?'?'+query:'');
  }catch{return 'unavailable'}
}
function observerError(state,phase,error){state.record('observererror',{phase,name:String(error?.name||'Error').slice(0,64)})}
function print(value){try{console.log('M2_TRANSITION_DIAGNOSTICS '+JSON.stringify(value))}catch{}}

async function start(page,context,browserName){
  const state={events:[],contexts:new Map(),mainFrameId:null,session:null,sequence:0,dropped:0};
  state.record=(kind,data={})=>{
    state.events.push({seq:++state.sequence,at:Date.now(),kind,...data});
    if(state.events.length>MAX_EVENTS){state.events.shift();state.dropped++}
  };
  try{
    // Passive listeners only. No prototype/history/evaluate replacements or polling.
    await page.addInitScript(()=>{
      if(window!==top||!['127.0.0.1','localhost','[::1]'].includes(location.hostname))return;
      const ring=[],limit=24;
      const id=node=>/^[A-Za-z][A-Za-z0-9_:-]{0,63}$/.test(node?.id||'')?node.id:null;
      const describe=node=>node?{id:id(node),tag:String(node.tagName||'').slice(0,16),details:id(node.closest?.('details')),detailsOpen:node.closest?.('details')?.open??null}:null;
      const snapshot=()=>({at:Math.round(performance.timeOrigin+performance.now()),active:describe(document.activeElement),
        screen:document.getElementById('workspaceRoot')?.dataset.mobileScreen||null,
        conversation:Number.isInteger(window.WorkspaceV2?.S?.current)?WorkspaceV2.S.current:null,
        historyLength:history.length,historyConversation:Number.isInteger(history.state?.conversation_id)?history.state.conversation_id:null,
        overlay:typeof history.state?.workspaceV2Overlay?.id==='string'?history.state.workspaceV2Overlay.id.slice(0,64):null,
        dialogs:[...document.querySelectorAll('dialog')].slice(0,8).map(node=>({id:id(node),open:node.open}))});
      const record=event=>{
        try{
          const key=event.type==='keydown'&&['Tab','Enter','Escape',' '].includes(event.key)?event.key:null;
          if(event.type==='keydown'&&key===null)return;
          ring.push({event:event.type,key,target:describe(event.target),...snapshot()});if(ring.length>limit)ring.shift();
        }catch{ring.push({event:'observererror',phase:'dom-event'});if(ring.length>limit)ring.shift()}
      };
      for(const name of ['focusin','focusout','pointerdown','keydown','close','cancel','toggle','popstate','hashchange','pagehide','pageshow']){
        addEventListener(name,record,{capture:true,passive:true});
      }
      // A dedicated observer getter; no product state, values, text, cookies or storage.
      window.__m2TransitionDiagnosticsRead=()=>({state:snapshot(),events:ring.slice()});
    });
  }catch(error){observerError(state,'init-script',error)}
  if(browserName!=='chromium'){state.record('cdp-unavailable',{browser:browserName});return state}
  try{
    const cdp=state.session=await context.newCDPSession(page);
    const tree=await cdp.send('Page.getFrameTree');state.mainFrameId=tree.frameTree.frame.id;
    const frame=(kind,data)=>state.record(kind,{frameId:data.frameId,main:data.frameId===state.mainFrameId,
      loaderId:data.loaderId,navigationType:data.navigationType,reason:data.reason,url:safeUrl(data.url)});
    cdp.on('Page.frameNavigated',({frame:f})=>state.record('frame-navigated',{frameId:f.id,parentId:f.parentId,
      main:f.id===state.mainFrameId,loaderId:f.loaderId,url:safeUrl(f.url)}));
    cdp.on('Page.frameStartedNavigating',data=>frame('navigation-started',data));
    cdp.on('Page.frameRequestedNavigation',data=>frame('navigation-requested',data));
    cdp.on('Page.navigatedWithinDocument',data=>frame('same-document',data));
    cdp.on('Runtime.executionContextCreated',({context:c})=>{
      const value={contextId:c.id,frameId:c.auxData?.frameId,main:c.auxData?.frameId===state.mainFrameId,isDefault:c.auxData?.isDefault};
      state.contexts.set(c.id,value);if(state.contexts.size>MAX_CONTEXTS)state.contexts.delete(state.contexts.keys().next().value);
      state.record('context-created',value);
    });
    cdp.on('Runtime.executionContextDestroyed',data=>state.record('context-destroyed',state.contexts.get(data.executionContextId)||{contextId:data.executionContextId,mapping:'unavailable'}));
    cdp.on('Runtime.executionContextsCleared',()=>state.record('contexts-cleared'));
    await cdp.send('Runtime.enable');await cdp.send('Page.enable');
  }catch(error){observerError(state,'cdp-setup',error)}
  return state;
}

async function finish(state,page,testInfo,completed){
  try{
    if(!completed&&testInfo.status!=='skipped'){
      let dom=null;
      try{
        // Exactly one read. Destruction/unavailability becomes observererror, never a retry.
        dom=await page.evaluate(()=>{
          if(!['127.0.0.1','localhost','[::1]'].includes(location.hostname)||typeof window.__m2TransitionDiagnosticsRead!=='function')return {observererror:'dom-observer-unavailable'};
          return window.__m2TransitionDiagnosticsRead();
        });
      }catch(error){observerError(state,'failure-dom-read',error)}
      const payload={schema:'m2-transition-diagnostics-v1',file:path.basename(testInfo.file),title:testInfo.title,
        statusAtObservation:testInfo.status,callbackCompleted:completed,callbackOutcome:'threw_or_interrupted',retry:testInfo.retry,mainFrameId:state.mainFrameId,contexts:[...state.contexts.values()],
        dropped:state.dropped,events:state.events,dom};
      while(Buffer.byteLength(JSON.stringify(payload),'utf8')>MAX_OUTPUT){
        if(payload.dom?.events?.length)payload.dom.events.shift();
        else if(payload.events.length)payload.events.shift();
        else{payload.dom=null;payload.contexts=[];break}
        payload.dropped++;
      }
      print(payload);
    }
  }catch(error){print({schema:'m2-transition-diagnostics-v1',observererror:'failure-observer',name:String(error?.name||'Error').slice(0,64)})}
  finally{
    if(state.session){
      try{state.session.removeAllListeners();await state.session.detach()}
      catch(error){if(!completed)print({schema:'m2-transition-diagnostics-v1',observererror:'cdp-detach',name:String(error?.name||'Error').slice(0,64)})}
    }
  }
}

/** Only the selected callbacks use this wrapper; other browser-only tests gain no page fixture.
 * The original callback is never caught/retried. finally preserves its thrown failure.
 */
function withTransitionDiagnostics(body){
  return async function transitionObserved({page,context,browserName},testInfo){
    if(TARGETS.get(path.basename(testInfo.file))!==testInfo.title)return body({page,context,browserName},testInfo);
    const state=await start(page,context,browserName);let completed=false;
    try{const value=await body({page,context,browserName},testInfo);completed=true;return value}
    finally{await finish(state,page,testInfo,completed)}
  };
}
module.exports={withTransitionDiagnostics};
