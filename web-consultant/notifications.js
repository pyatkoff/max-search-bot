(()=>{
  const tokenKey='anytour_consultant_token_v1';
  const script=document.currentScript;
  const apiUrl=new URL('api.php',script&&script.src?script.src:location.href).toString();
  let baseline=null;
  let polling=false;

  const getUi=()=>{
    const host=document.getElementById('anytour-consultant-host');
    const root=host&&host.shadowRoot;
    if(!root)return null;
    return {root,panel:root.querySelector('.panel'),launcher:root.querySelector('.launcher')};
  };

  const ensureBadge=ui=>{
    let badge=ui.root.querySelector('.anytour-unread-badge');
    if(badge)return badge;
    const style=document.createElement('style');
    style.textContent='.anytour-unread-badge{position:absolute;right:-4px;top:-4px;min-width:21px;height:21px;padding:0 6px;border-radius:999px;background:#e53935;color:#fff;border:2px solid #fff;display:none;align-items:center;justify-content:center;font:800 11px/17px -apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;box-shadow:0 4px 12px rgba(178,36,32,.32);z-index:3}.anytour-unread-badge.is-visible{display:flex}';
    ui.root.appendChild(style);
    badge=document.createElement('span');
    badge.className='anytour-unread-badge';
    badge.setAttribute('aria-label','Новые сообщения');
    ui.launcher.appendChild(badge);
    return badge;
  };

  const clearBadge=()=>{
    const ui=getUi();
    if(!ui)return;
    const badge=ui.root.querySelector('.anytour-unread-badge');
    if(badge){badge.classList.remove('is-visible');badge.textContent=''}
  };

  const showBadge=count=>{
    const ui=getUi();
    if(!ui||ui.panel.classList.contains('open'))return;
    const badge=ensureBadge(ui);
    const value=Math.max(1,Math.min(99,count||1));
    badge.textContent=value>=99?'99+':String(value);
    badge.classList.add('is-visible');
  };

  const check=async()=>{
    if(polling||document.hidden)return;
    const token=localStorage.getItem(tokenKey)||'';
    const ui=getUi();
    if(!token||!ui||ui.panel.classList.contains('open'))return;
    polling=true;
    try{
      const r=await fetch(apiUrl,{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json'},body:JSON.stringify({action:'poll',token,after_id:baseline||0})});
      const j=await r.json();
      if(!r.ok||!j.ok)return;
      const messages=(j.chat&&Array.isArray(j.chat.messages))?j.chat.messages:[];
      let maxId=baseline||0,unread=0;
      messages.forEach(m=>{
        const id=Number(m&&m.id)||0;
        if(id>maxId)maxId=id;
        if(baseline!==null&&id>baseline&&m&&m.direction!=='inbound')unread++;
      });
      if(baseline===null){baseline=maxId;return}
      if(maxId>baseline)baseline=maxId;
      if(unread>0)showBadge(unread);
    }catch(e){}finally{polling=false}
  };

  document.addEventListener('click',e=>{
    const ui=getUi();
    if(!ui)return;
    const path=typeof e.composedPath==='function'?e.composedPath():[];
    if(path.includes(ui.launcher))clearBadge();
  },true);

  const observe=()=>{
    const ui=getUi();
    if(!ui){setTimeout(observe,500);return}
    ensureBadge(ui);
    new MutationObserver(()=>{if(ui.panel.classList.contains('open'))clearBadge()}).observe(ui.panel,{attributes:true,attributeFilter:['class']});
  };

  observe();
  setInterval(check,8000);
  setTimeout(check,1200);
})();
