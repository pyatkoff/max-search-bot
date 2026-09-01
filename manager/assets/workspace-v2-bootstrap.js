(function(){
  const self=document.currentScript;
  const summarySrc=self?.src?new URL('workspace-v2-lead-summary-copy.js',self.src).href:'assets/workspace-v2-lead-summary-copy.js';
  function bindSummaryCopy(){
    if(window.WorkspaceV2LeadSummaryCopy){window.WorkspaceV2LeadSummaryCopy.bind();return}
    if(document.querySelector('script[data-workspace-lead-summary-copy]'))return;
    const script=document.createElement('script');script.src=summarySrc;script.defer=true;script.dataset.workspaceLeadSummaryCopy='1';script.onload=()=>window.WorkspaceV2LeadSummaryCopy?.bind();document.head.appendChild(script);
  }
  const start=()=>{window.WorkspaceV2?.boot();bindSummaryCopy()};
  if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',start,{once:true});else start();
})();
