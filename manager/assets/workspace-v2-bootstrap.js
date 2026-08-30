(function(){
  const start=()=>window.WorkspaceV2?.boot();
  const load=(src,onDone)=>{const script=document.createElement('script');script.src=src;script.onload=onDone;script.onerror=onDone;document.head.appendChild(script)};
  load('assets/workspace-v2-task-presets.js?v=1',()=>load('assets/workspace-v2-shift.js?v=1',start));
})();
