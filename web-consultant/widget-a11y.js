(()=>{
  const hostId='anytour-consultant-host';
  const dialogId='anytour-consultant-dialog';
  const mobileQuery=window.matchMedia?window.matchMedia('(max-width:520px)'):null;
  let previousBodyOverflow=null;

  const install=()=>{
    const host=document.getElementById(hostId);
    const root=host&&host.shadowRoot;
    if(!root)return false;
    if(root.querySelector('[data-anytour-a11y-style]'))return true;

    const panel=root.querySelector('.panel');
    const launcher=root.querySelector('.launcher');
    const launcherLabel=root.querySelector('.launcher-label');
    if(!panel||!launcher)return false;

    const style=document.createElement('style');
    style.setAttribute('data-anytour-a11y-style','1');
    style.textContent=`
      button:focus-visible,a:focus-visible,input:focus-visible,textarea:focus-visible{outline:3px solid rgba(11,124,255,.32);outline-offset:2px}
      .head button:focus-visible{outline-color:rgba(255,255,255,.72)}
      @media(prefers-reduced-motion:reduce){*,*:before,*:after{scroll-behavior:auto!important;animation-duration:.001ms!important;animation-iteration-count:1!important;transition-duration:.001ms!important}}
    `;
    root.appendChild(style);

    panel.id=dialogId;
    panel.setAttribute('aria-hidden',panel.classList.contains('open')?'false':'true');
    [launcher,launcherLabel].filter(Boolean).forEach(button=>{
      button.setAttribute('aria-controls',dialogId);
      button.setAttribute('aria-expanded',panel.classList.contains('open')?'true':'false');
    });

    const setPageScrollLocked=locked=>{
      if(!document.body)return;
      if(locked){
        if(previousBodyOverflow===null)previousBodyOverflow=document.body.style.overflow;
        document.body.style.overflow='hidden';
        return;
      }
      if(previousBodyOverflow!==null){
        document.body.style.overflow=previousBodyOverflow;
        previousBodyOverflow=null;
      }
    };

    const syncState=()=>{
      const open=panel.classList.contains('open');
      panel.setAttribute('aria-hidden',open?'false':'true');
      [launcher,launcherLabel].filter(Boolean).forEach(button=>button.setAttribute('aria-expanded',open?'true':'false'));
      setPageScrollLocked(Boolean(open&&mobileQuery&&mobileQuery.matches));
    };

    new MutationObserver(syncState).observe(panel,{attributes:true,attributeFilter:['class']});
    if(mobileQuery){
      const onViewportChange=()=>syncState();
      if(typeof mobileQuery.addEventListener==='function')mobileQuery.addEventListener('change',onViewportChange);
      else if(typeof mobileQuery.addListener==='function')mobileQuery.addListener(onViewportChange);
    }

    root.addEventListener('keydown',event=>{
      if(event.key!=='Tab'||!panel.classList.contains('open'))return;
      const focusable=Array.from(panel.querySelectorAll('button:not([disabled]),a[href],input:not([disabled]),textarea:not([disabled]),[tabindex]:not([tabindex="-1"])')).filter(el=>el.getClientRects().length>0);
      if(!focusable.length)return;
      const first=focusable[0],last=focusable[focusable.length-1],active=root.activeElement;
      if(event.shiftKey&&active===first){event.preventDefault();last.focus();return}
      if(!event.shiftKey&&active===last){event.preventDefault();first.focus()}
    });

    window.addEventListener('pagehide',()=>setPageScrollLocked(false),{once:true});
    syncState();
    return true;
  };

  if(install())return;
  let attempts=0;
  const timer=setInterval(()=>{
    attempts++;
    if(install()||attempts>=40)clearInterval(timer);
  },50);
})();
