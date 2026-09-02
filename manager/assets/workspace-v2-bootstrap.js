(function(){
  function ensureManagerRequestQueue(){
    const tabs=document.querySelector('.queueTabs');
    if(!tabs||tabs.querySelector('button[data-q="requested"]'))return;
    const button=document.createElement('button');
    button.type='button';
    button.dataset.q='requested';
    button.textContent='Запросили менеджера';
    const mine=tabs.querySelector('button[data-q="mine"]');
    tabs.insertBefore(button,mine||null);
  }
  const start=()=>{ensureManagerRequestQueue();window.WorkspaceV2?.boot()};
  if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',start,{once:true});else start();
})();
