(function(){
 'use strict';
 const root=document.querySelector('[data-selection-interest]');if(!root)return;
 let cap;try{cap=JSON.parse(root.dataset.selectionInterest)}catch{return}
 const hex=()=>Array.from(crypto.getRandomValues(new Uint8Array(16)),x=>x.toString(16).padStart(2,'0')).join('');
 if(!globalThis.crypto?.getRandomValues||!cap||!/^[a-f0-9]{64}$/.test(cap.t||''))return;
 let visitor;
 try{const key='anytour-selection-visit:'+cap.t;visitor=sessionStorage.getItem(key);if(!/^[a-f0-9]{32}$/.test(visitor||'')){visitor=hex();sessionStorage.setItem(key,visitor)}}catch{visitor=hex()}
 const sent=new Map(),visible=new Map();let timer=null,started=false;
 function report(kind,card=-1,platform=''){
  const key=[kind,card,platform].join(':');if(sent.has(key))return;
  const data={...cap,event_id:hex(),visitor_id:visitor,kind,card_index:card,platform};sent.set(key,data);
  // No retries or blocking navigation. A POST is not a confirmed bot inquiry.
  void fetch('activity.php',{method:'POST',mode:'same-origin',credentials:'omit',keepalive:true,
   headers:{'Content-Type':'application/json'},body:JSON.stringify(data)}).catch(()=>{});
 }
 function ready(){
  if(document.visibilityState!=='visible')return;
  clearTimeout(timer);timer=setTimeout(()=>{
   if(document.visibilityState!=='visible')return;
   if(!started){started=true;report('page_view')}
   for(const [node,state] of visible)if(state)report('hotel_view',Number(node.dataset.interestCard));
  },1000);
 }
 if('IntersectionObserver'in window){
  const observer=new IntersectionObserver(entries=>{for(const e of entries)visible.set(e.target,e.isIntersecting&&e.intersectionRatio>=0.5);ready()},{threshold:0.5});
  root.querySelectorAll('[data-interest-hotel]').forEach(node=>observer.observe(node));
 }
 document.addEventListener('visibilitychange',()=>{clearTimeout(timer);ready()});
 root.querySelectorAll('[data-interest-platform]').forEach(link=>link.addEventListener('click',()=>{
  if(document.visibilityState==='visible')report('bot_open',Number(link.dataset.interestCard),link.dataset.interestPlatform);
 }));ready();
})();
