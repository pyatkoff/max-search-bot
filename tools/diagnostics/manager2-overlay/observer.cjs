'use strict';
const fs=require('node:fs');
const {PRODUCT_SHA,PRODUCT_TREE}=require('./run-once.cjs');
function register(test){
  let events=[],sequence=0,session,mainFrameId;
  const record=(kind,data={})=>events.push({seq:++sequence,at:new Date().toISOString(),kind,...data});
  test.beforeEach(async({page,context})=>{
   events=[];sequence=0;
   page.on('console',message=>{const text=message.text();if(text.startsWith('__M2_CAUSAL__'))record('browser',JSON.parse(text.slice('__M2_CAUSAL__'.length)))});
   page.on('framenavigated',frame=>record('framenavigated',{main:frame===page.mainFrame(),url:frame.url()}));
   page.on('pageerror',error=>record('pageerror',{message:error.message,stack:error.stack}));
   page.on('request',request=>{
    let action=null,conversation=null;const body=request.postData();if(body&&body.startsWith('{')){const data=JSON.parse(body);action=data.action||null;conversation=data.conversation_id||null}
    if(request.isNavigationRequest()||request.url().includes('request-profile')||request.url().includes('/manager/api.php'))record('request',{method:request.method(),url:request.url(),type:request.resourceType(),navigation:request.isNavigationRequest(),action,conversation});
   });
   page.on('requestfailed',request=>record('requestfailed',{url:request.url(),navigation:request.isNavigationRequest(),error:request.failure()?.errorText}));
   page.on('crash',()=>record('crash'));
   await page.addInitScript(()=>{
    const describe=node=>node?{id:node.id||null,tag:node.tagName||null,detailsId:node.closest?.('details')?.id||null,detailsOpen:node.closest?.('details')?.open??null}:null;
    const state=()=>({url:location.href,top:window===top,historyLength:history.length,historyState:history.state,active:describe(document.activeElement),dialogs:[...document.querySelectorAll('dialog')].map(node=>({id:node.id,open:node.open})),screen:document.getElementById('workspaceRoot')?.dataset.mobileScreen,current:window.WorkspaceV2?.S?.current});
    const emit=(event,details={})=>console.debug('__M2_CAUSAL__'+JSON.stringify({event,clock:performance.now(),...state(),...details}));
    for(const name of ['pushState','replaceState','back','forward','go']){
     const original=history[name];
     history[name]=function(...args){emit('history-call',{method:name,args,stack:new Error().stack});const result=Reflect.apply(original,this,args);emit('history-return',{method:name});return result};
    }
    const nativeClose=HTMLDialogElement.prototype.close;
    HTMLDialogElement.prototype.close=function(...args){emit('dialog-close-call',{dialogId:this.id,wasOpen:this.open});const result=Reflect.apply(nativeClose,this,args);emit('dialog-close-return',{dialogId:this.id,nowOpen:this.open});return result};
    for(const name of ['popstate','hashchange','beforeunload','pagehide','pageshow','resize'])window.addEventListener(name,event=>{emit(name,{eventState:event.state,persisted:event.persisted});if(name==='popstate')queueMicrotask(()=>emit('popstate-after-handlers'))},true);
    for(const name of ['close','cancel','toggle'])document.addEventListener(name,event=>{emit('native-'+name,{target:describe(event.target),targetOpen:event.target?.open});queueMicrotask(()=>emit('native-'+name+'-after-handlers',{target:describe(event.target),targetOpen:event.target?.open}))},true);
    document.addEventListener('focusin',event=>emit('focusin',{target:describe(event.target),previous:describe(event.relatedTarget)}),true);
    document.addEventListener('keydown',event=>emit('keydown',{key:event.key,target:describe(event.target)}),true);
    emit('init-observer');
   });
   session=await context.newCDPSession(page);
   const tree=await session.send('Page.getFrameTree');mainFrameId=tree.frameTree.frame.id;
   session.on('Page.frameNavigated',({frame,type})=>record('cdp-frame-navigated',{frameId:frame.id,parentId:frame.parentId,url:frame.url,loaderId:frame.loaderId,type,main:frame.id===mainFrameId}));
   session.on('Page.navigatedWithinDocument',data=>record('cdp-same-document',data));
   session.on('Page.frameRequestedNavigation',data=>record('cdp-navigation-requested',data));
   session.on('Page.frameStartedNavigating',data=>record('cdp-navigation-started',data));
   session.on('Runtime.executionContextCreated',({context})=>record('context-created',{id:context.id,origin:context.origin,frameId:context.auxData?.frameId,isDefault:context.auxData?.isDefault,main:context.auxData?.frameId===mainFrameId}));
   session.on('Runtime.executionContextDestroyed',data=>record('context-destroyed',data));
   session.on('Runtime.executionContextsCleared',()=>record('contexts-cleared'));
   await session.send('Runtime.enable');await session.send('Page.enable');
   const evaluate=page.evaluate.bind(page);
   page.evaluate=async(fn,arg)=>{
    if(!String(fn).includes('WorkspaceV2Conversation.open(12)'))return evaluate(fn,arg);
    record('awaited-open12-start',{url:page.url()});let completed=false;
    try{const result=await evaluate(fn,arg);completed=true;return result}
    finally{record('awaited-open12-end',{url:page.url(),completed})}
   };
   record('observer-ready',{url:page.url(),mainFrameId,browserVersion:context.browser().version()});
  });
  test.afterEach(async({page},info)=>{
   record('test-end',{status:info.status,url:page.url(),errors:info.errors.map(error=>error.message)});
   fs.writeFileSync(info.outputPath('overlay-causal-events.json'),JSON.stringify({title:info.title,status:info.status,sourceCommit:PRODUCT_SHA,sourceTree:PRODUCT_TREE,mainFrameId,events},null,2));
  });
}
module.exports={register};
