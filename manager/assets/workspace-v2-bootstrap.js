(function(){
  const preset=document.createElement('script');
  preset.src='assets/workspace-v2-task-presets.js?v=1';
  document.head.appendChild(preset);
  const start=()=>window.WorkspaceV2?.boot();
  const script=document.createElement('script');
  script.src='assets/workspace-v2-shift.js?v=1';
  script.onload=start;
  script.onerror=start;
  document.head.appendChild(script);
})();
