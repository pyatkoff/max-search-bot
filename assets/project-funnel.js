(function(root,factory){
  const api=factory();
  if(typeof module==='object'&&module.exports)module.exports=api;
  else{root.ProjectFunnel=api;api.start(root);}
})(typeof globalThis!=='undefined'?globalThis:this,function(){
  'use strict';
  function unique(raw){const out=new Map();for(const[k,v]of new URLSearchParams(raw)){if(out.has(k))throw Error('conflict');out.set(k,v);}return out;}
  function parse(raw){
    if(typeof raw!=='string'||raw.length>512||/[\x00-\x20\x7f]/.test(raw))throw Error('invalid_start');
    if(raw==='')return{kind:'publication',post_id:'',offer_id:''};
    const pub=/^post1_([A-Za-z0-9][A-Za-z0-9_.:-]{0,127})$/.exec(raw);
    if(pub)return{kind:'publication',post_id:pub[1],offer_id:''};
    const m=/^([1-9][0-9]{0,39})_region_([1-9][0-9]{0,19})_campaign_([0-9]{1,20})_ctx1_([A-Za-z0-9_-]{1,340})$/.exec(raw);
    if(!m)throw Error('invalid_start');
    const plain=atob(m[4].replace(/-/g,'+').replace(/_/g,'/')),ctx=JSON.parse(plain);
    const ref=/^[A-Za-z0-9][A-Za-z0-9_.:-]{0,127}$/;
    if(Object.keys(ctx).join(',')!=='post_id,offer_id'||!ref.test(ctx.post_id)||!ref.test(ctx.offer_id)
      ||btoa(JSON.stringify(ctx)).replace(/\+/g,'-').replace(/\//g,'_').replace(/=+$/,'')!==m[4])throw Error('invalid_context');
    return{kind:'advertising',...ctx,yclid:m[1],region:m[2],campaign:m[3]};
  }
  function context(href,bridge){
    const url=new URL(href),q=unique(url.search.slice(1)),h=unique(url.hash.slice(1));
    const a=typeof bridge?.initData==='string'?bridge.initData:'',b=h.get('WebAppData')||'';
    if(a&&b&&a!==b)throw Error('conflict');
    const init_data=a||b,signed=init_data?unique(init_data):new Map();
    if(!init_data)return{init_data:'',start_param:'',payload:parse(''),clean:url.origin+url.pathname,metric:''};
    const start_param=signed.get('start_param')??signed.get('startapp')??'';
    const hints=[];for(const src of[signed,q,h])for(const k of['start_param','startapp','WebAppStartParam','start'])if(src.has(k))hints.push(src.get(k));
    if(hints.some(v=>v!==start_param))throw Error('conflict');
    const payload=parse(start_param),clean=new URL(url.origin+url.pathname);clean.searchParams.set('project',q.get('project')||'');
    const metric=new URL(clean);if(payload.kind==='advertising')for(const[k,v]of Object.entries({yclid:payload.yclid,region:payload.region,campaign:payload.campaign}))metric.searchParams.set(k,v);
    return{init_data,start_param,payload,clean:clean.href,metric:metric.href};
  }
  function safeLink(link,channel){try{const u=new URL(link);return typeof link==='string'&&u.href===link&&link.startsWith(channel+'/')&&/^https:\/\/max\.ru\/[A-Za-z0-9_]{3,100}\/[A-Za-z0-9_-]{1,191}$/.test(link);}catch(_){return false;}}
  function receipt(data,ctx,cfg){return data?.ok===true&&safeLink(data.redirect_url,cfg.channel_url)
    &&(ctx.payload.post_id===''||data.post_id===ctx.payload.post_id)
    &&(ctx.payload.kind==='advertising'?data.mode==='advertising'&&data.saved===true&&data.offer_id===ctx.payload.offer_id&&/^max1_[a-f0-9]{64}$/.test(data.receipt?.visit_id||'')&&/^[1-9][0-9]{0,19}$/.test(data.receipt?.cursor||''):data.mode==='publication'&&data.saved===false);}
  async function start(win){
    const doc=win.document,cfg=JSON.parse(doc.getElementById('funnel-config').textContent),el=id=>doc.getElementById(id),pause=ms=>new Promise(r=>win.setTimeout(r,ms));
    let busy=false,ctx,bridgeScript,metric=false;
    function load(src){const s=doc.createElement('script');s.src=src;s.async=true;doc.head.appendChild(s);return s;}
    function status(title,text,loading=false){el('status-title').textContent=title;el('status-copy').textContent=text;doc.querySelector('main').setAttribute('aria-busy',String(loading));}
    async function cid(){
      if(!cfg.counter_id||ctx.payload.kind!=='advertising')return'';
      if(!metric){metric=true;win.ym=win.ym||function(){(win.ym.a=win.ym.a||[]).push(arguments);};win.ym.l=Date.now();win.ym(cfg.counter_id,'init',{defer:true,clickmap:false,trackLinks:false,webvisor:false});win.ym(cfg.counter_id,'hit',ctx.metric,{referrer:''});load('https://mc.yandex.ru/metrika/tag.js');}
      return await new Promise(resolve=>{let done=false;const end=v=>{if(!done){done=true;resolve(typeof v==='string'&&/^[1-9][0-9]{0,39}$/.test(v)?v:'');}};win.setTimeout(()=>end(''),3000);win.ym(cfg.counter_id,'getClientID',end);});
    }
    async function run(){
      if(busy)return;busy=true;el('retry').hidden=true;el('continue').hidden=true;status('Открываем предложение','Подготавливаем переход к публикации в MAX…',true);
      try{
        if(!win.WebApp&&!bridgeScript){bridgeScript=load('https://st.max.ru/js/max-web-app.js');bridgeScript.onload=()=>{try{win.WebApp?.ready?.();}catch(_){}};bridgeScript.onerror=()=>{bridgeScript?.remove();bridgeScript=null;};}
        if(!ctx){const until=Date.now()+2000;let next;do{next=context(win.location.href,win.WebApp);if(next.init_data)break;await pause(100);}while(Date.now()<until);
          if(!next.init_data)throw Error('open_in_max');ctx=next;win.history.replaceState(null,'',ctx.clean+win.location.hash);}
        try{win.WebApp?.ready?.();}catch(_){}
        const client_id=await cid(),controller=new AbortController(),timer=win.setTimeout(()=>controller.abort(),10000);
        let data;try{const res=await win.fetch(ctx.clean,{method:'POST',credentials:'same-origin',cache:'no-store',headers:{'Content-Type':'application/json'},body:JSON.stringify({init_data:ctx.init_data,start_param:ctx.start_param,client_id}),signal:controller.signal});data=await res.json();if(!res.ok||!receipt(data,ctx,cfg))throw Error('save_failed');}finally{win.clearTimeout(timer);}
        el('continue').href=data.redirect_url;el('continue').hidden=false;status('Готово','Открываем выбранную публикацию…');
        if(metric)win.ym(cfg.counter_id,'notBounce');
        let opened=false;
        if(typeof win.WebApp?.openMaxLink==='function'){try{win.WebApp.openMaxLink(data.redirect_url);opened=true;}catch(_){}}
        if(!opened){try{win.location.assign(data.redirect_url);}catch(_){}}
      }catch(_){status('Переход не выполнен','Откройте исходную ссылку внутри MAX. Если вы уже в MAX, проверьте сеть и повторите переход.');el('retry').hidden=false;}
      finally{busy=false;}
    }
    el('retry').addEventListener('click',run);await run();
  }
  return{parse,context,safeLink,receipt,start};
});
