(()=>{
  const script=document.currentScript;
  const apiUrl=new URL('api.php',script&&script.src?script.src:location.href).toString();
  const storageKey='anytour_consultant_token_v1';
  let lastKey='';
  let timer=0;

  const clean=(v,max=180)=>typeof v==='string'?v.trim().slice(0,max):'';
  const number=(v)=>{const n=Number(v);return Number.isFinite(n)&&n>=0?n:null};
  const pick=(src,key)=>src&&Object.prototype.hasOwnProperty.call(src,key)?src[key]:undefined;
  const normalize=(raw)=>{
    raw=raw&&typeof raw==='object'?raw:{};
    const out={};
    const strings=['entity_type','hotel_name','tour_name','destination','country','resort','currency','meal','departure_date','return_date','operator','room'];
    strings.forEach(k=>{const v=clean(pick(raw,k),k==='tour_name'||k==='hotel_name'?220:120);if(v)out[k]=v});
    const price=number(pick(raw,'price'));if(price!==null)out.price=price;
    const stars=number(pick(raw,'stars'));if(stars!==null&&stars<=5)out.stars=stars;
    const nights=number(pick(raw,'nights'));if(nights!==null&&nights<=60)out.nights=nights;
    return out;
  };
  const fromExplicit=()=>normalize(window.AnyTourPageContext||{});
  const jsonLdNodes=()=>{
    const found=[];
    document.querySelectorAll('script[type="application/ld+json"]').forEach(node=>{
      try{
        const data=JSON.parse(node.textContent||'null');
        const walk=(v)=>{if(Array.isArray(v))v.forEach(walk);else if(v&&typeof v==='object'){found.push(v);if(v['@graph'])walk(v['@graph'])}};
        walk(data);
      }catch(e){}
    });
    return found;
  };
  const fromJsonLd=()=>{
    const nodes=jsonLdNodes();
    let entity=null,offer=null;
    for(const n of nodes){
      const t=Array.isArray(n['@type'])?n['@type']:[n['@type']];
      if(!entity&&t.some(x=>['Hotel','LodgingBusiness','Product','TouristTrip'].includes(x)))entity=n;
      if(!offer&&t.includes('Offer'))offer=n;
      if(entity&&!offer&&entity.offers)offer=Array.isArray(entity.offers)?entity.offers[0]:entity.offers;
    }
    if(!entity&&!offer)return {};
    const type=entity&&entity['@type'];
    const rating=entity&&entity.starRating;
    const address=entity&&entity.address;
    return normalize({
      entity_type:Array.isArray(type)?type[0]:type,
      hotel_name:entity&&entity.name,
      tour_name:entity&&entity.name,
      destination:address&&(address.addressLocality||address.addressRegion),
      country:address&&address.addressCountry,
      price:offer&&offer.price,
      currency:offer&&offer.priceCurrency,
      stars:rating&&(rating.ratingValue||rating),
    });
  };
  const structured=()=>{const explicit=fromExplicit();return Object.keys(explicit).length?explicit:fromJsonLd()};
  const current=()=>({url:location.href,title:document.title||'',structured:structured()});
  const token=()=>{try{return localStorage.getItem(storageKey)||''}catch(e){return ''}};
  const send=async()=>{
    const t=token();
    if(!t)return false;
    const ctx=current();
    const key=JSON.stringify(ctx);
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
  window.addEventListener('anytour:page-context',schedule);
  document.addEventListener('visibilitychange',()=>{if(!document.hidden)schedule()});
  setInterval(()=>{if(JSON.stringify(current())!==lastKey)schedule()},2000);
})();
