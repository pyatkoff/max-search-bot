(function(){
  const start=()=>window.WorkspaceV2?.boot();
  if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',start,{once:true});else start();
})();
