(function(){
  const start=()=>{
    const presentation=window.Manager2Presentation;
    if(presentation)window.WorkspaceV2RequestProfile?.bindMenu?.({
      register:presentation.registerConversationTool,
      focus:presentation.focusConversationTool,
    });
    window.WorkspaceV2?.boot();
  };
  if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',start,{once:true});else start();
})();
