(function(){
  const start=()=>window.WorkspaceV2?.boot();
  const script=document.createElement('script');
  script.src='assets/workspace-v2-shift.js?v=1';
  script.onload=start;
  script.onerror=start;
  document.head.appendChild(script);
})();
