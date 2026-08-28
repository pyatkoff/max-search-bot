(()=>{
  const script=document.currentScript;
  const apiUrl=new URL('api.php',script&&script.src?script.src:location.href).toString();
  const storageKey='anytour_consultant_token_v1';
  let lastKey='';
  let timer=0;

  const current=()=>({url:location.href,title:document.title||''});
  const token=()=>{try{return localStorage.getItem(storageKey)||''}catch(e){return ''}};
  const send=async()=>{
    const t=token();
    if(!t)return false;
    const ctx=current();
    const key=ctx.url+'\n'+ctx.title;
    if(key===lastKey)return true;
    try{
      const r=await fetch(apiUrl,{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json'},body:JSON.stringify({action:'context',token:t,page_context:ctx})});
      if(!r.ok)return false;
      const j=await r.json();
      if(!j||!j.ok)return false;
      lastKey=key;
      return true;
    }catch(e){return false}
  };
  const schedule=()=>{clearTimeout(timer);timer=setTimeout(send,120)};

  let attempts=0;
  const boot=setInterval(async()=>{
    attempts++;
    if(await send()||attempts>=20)clearInterval(boot);
  },250);

  window.addEventListener('popstate',schedule);
  window.addEventListener('hashchange',schedule);
  window.addEventListener('focus',schedule);
  document.addEventListener('visibilitychange',()=>{if(!document.hidden)schedule()});
  setInterval(()=>{const ctx=current();if(ctx.url+'\n'+ctx.title!==lastKey)schedule()},2000);
})();
