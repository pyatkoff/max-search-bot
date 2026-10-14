(function(){
'use strict';
const W=window.WorkspaceV2,{S}=W;
const cache=new Map(),slots=new Map(),waiting=new Map(),active=new Map();
const MAX_ENTRIES=48,MAX_BYTES=8*1024*1024,MAX_PHOTO_BYTES=256*1024;
const PHOTO_TTL=10*60*1000,NEGATIVE_TTL=60*1000;
let observer=null,epoch=0,owner='',fallbackBound=false;
function account(){return !S.authExpired&&Number(S.manager?.id)>0?Number(S.manager.id)+':'+Number(S.authGeneration||0):''}
function current(record){return record.epoch===epoch&&record.owner===account()&&record.owner===owner}
function clearImage(node){node.querySelectorAll('.customerAvatarImage').forEach(image=>image.remove())}
function resetSession(){
  epoch++;observer?.disconnect();observer=null;
  active.forEach(job=>job.controller.abort());active.clear();waiting.clear();
  slots.forEach((record,node)=>{clearImage(node);delete node.dataset.customerAvatar});slots.clear();
  cache.forEach(entry=>{if(entry.url)URL.revokeObjectURL(entry.url)});cache.clear();owner='';
}
function ensureOwner(){const next=account();if(owner!==next){resetSession();owner=next}return !!next}
function dropCache(key){const entry=cache.get(key);if(entry?.url)URL.revokeObjectURL(entry.url);cache.delete(key);slots.forEach((record,node)=>{if(record.key===key)clearImage(node)})}
function cacheEntry(key){
  const entry=cache.get(key);if(!entry)return null;
  if(entry.until<=Date.now()){dropCache(key);return null}
  cache.delete(key);cache.set(key,entry);return entry;
}
function pruneCache(){
  let bytes=0;cache.forEach(entry=>{bytes+=entry.bytes||0});
  while(cache.size>MAX_ENTRIES||bytes>MAX_BYTES){const key=cache.keys().next().value;bytes-=cache.get(key)?.bytes||0;dropCache(key)}
}
function paint(node,record,entry){
  if(!current(record)||!node.isConnected||slots.get(node)!==record||!entry?.url)return;
  const existing=node.querySelector('.customerAvatarImage');if(existing?.getAttribute('src')===entry.url)return;
  clearImage(node);
  const image=document.createElement('img');image.className='customerAvatarImage';image.alt='';image.decoding='async';image.draggable=false;
  image.addEventListener('error',()=>{
    if(!current(record)||cache.get(record.key)!==entry)return;
    dropCache(record.key);cache.set(record.key,{until:Date.now()+NEGATIVE_TTL,bytes:0});pruneCache();
  },{once:true});
  image.src=entry.url;node.appendChild(image);
}
function matchingSlots(key){return [...slots].filter(([node,record])=>node.isConnected&&current(record)&&record.key===key)}
function liveSlots(key){return matchingSlots(key).filter(([,record])=>record.visible)}
function cleanup(){
  slots.forEach((record,node)=>{if(!node.isConnected||!current(record)){observer?.unobserve(node);clearImage(node);slots.delete(node)}});
  waiting.forEach((record,key)=>{if(!current(record)||!liveSlots(key).length)waiting.delete(key)});
}
function queue(record){
  if(!current(record))return;
  const entry=cacheEntry(record.key);
  if(entry){matchingSlots(record.key).forEach(([node,slot])=>paint(node,slot,entry));return}
  if(!active.has(record.key)&&!waiting.has(record.key))waiting.set(record.key,record);
  pump();
}
function pump(){
  cleanup();
  while(active.size<2&&waiting.size){
    const [key,record]=waiting.entries().next().value;waiting.delete(key);
    if(!current(record)||!liveSlots(key).length)continue;
    const job={controller:new AbortController(),record};active.set(key,job);
    void load(job).finally(()=>{if(active.get(key)===job)active.delete(key);pump()});
  }
}
async function load(job){
  const {record,controller}=job,timer=setTimeout(()=>controller.abort(),10000);
  try{
    const response=await fetch('customer-avatar.php?conversation_id='+encodeURIComponent(record.conversationId),{credentials:'same-origin',cache:'no-store',redirect:'error',signal:controller.signal});
    if(!current(record)||active.get(record.key)!==job)return;
    if(response.status===401){resetSession();W.showAuthRecovery();return}
    if(response.status===403){invalidateConversation(record.conversationId);return}
    if(!response.ok)throw new Error('photo_unavailable');
    const type=String(response.headers.get('Content-Type')||'').split(';')[0].toLowerCase();
    if(!['image/jpeg','image/png','image/webp'].includes(type))throw new Error('invalid_photo_type');
    const size=Number(response.headers.get('Content-Length')||0);
    if(size>MAX_PHOTO_BYTES)throw new Error('photo_too_large');
    const blob=await response.blob();
    if(!current(record)||active.get(record.key)!==job)return;
    if(!blob.size||blob.size>MAX_PHOTO_BYTES)throw new Error('invalid_photo_size');
    const entry={url:URL.createObjectURL(blob),bytes:blob.size,until:Date.now()+PHOTO_TTL};
    cache.set(record.key,entry);pruneCache();
    if(cache.get(record.key)===entry)matchingSlots(record.key).forEach(([node,slot])=>paint(node,slot,entry));
  }catch(error){
    if(current(record)&&active.get(record.key)===job){cache.set(record.key,{until:Date.now()+NEGATIVE_TTL,bytes:0});pruneCache()}
  }finally{clearTimeout(timer)}
}
function invalidateConversation(conversationId){
  const id=Number(conversationId),key=owner+':'+id;
  active.get(key)?.controller.abort();active.delete(key);waiting.delete(key);dropCache(key);
  slots.forEach((record,node)=>{if(record.conversationId===id){observer?.unobserve(node);clearImage(node);slots.delete(node)}});
  // A denial must not be retried by every list/header refresh.
  if(owner)cache.set(key,{until:Date.now()+NEGATIVE_TTL,bytes:0});pruneCache();
}
function visible(node){const rect=node.getBoundingClientRect();return rect.width>0&&rect.height>0&&rect.bottom>0&&rect.top<window.innerHeight&&rect.right>0&&rect.left<window.innerWidth}
function fallbackScan(){if(!ensureOwner())return;cleanup();slots.forEach((record,node)=>{record.visible=visible(node);if(record.visible)queue(record)})}
function ensureObserver(){
  if(observer||typeof IntersectionObserver!=='function')return;
  observer=new IntersectionObserver(entries=>{
    entries.forEach(entry=>{const record=slots.get(entry.target);if(!record||!current(record))return;record.visible=entry.isIntersecting;if(record.visible)queue(record)});
  },{threshold:0.01});
}
function attach(node,c){
  const conversationId=Number(c?.id||0);if(!node||!Number.isSafeInteger(conversationId)||conversationId<=0||!ensureOwner())return;
  const previous=slots.get(node);if(previous){observer?.unobserve(node);clearImage(node);slots.delete(node);delete node.dataset.customerAvatar}
  // Current private-profile adapter supports Telegram only. Keep MAX/website
  // initials immediately without issuing known-unavailable HTTP requests.
  if(c.channel!=='telegram')return;
  ensureObserver();
  node.classList.add('customerAvatar');node.dataset.customerAvatar=String(conversationId);node.setAttribute('aria-hidden','true');
  const record={owner,epoch,conversationId,key:owner+':'+conversationId,visible:false};slots.set(node,record);
  const entry=cacheEntry(record.key);if(entry?.url)paint(node,record,entry);
  if(observer)observer.observe(node);
  else{
    if(!fallbackBound){fallbackBound=true;window.addEventListener('scroll',fallbackScan,true);window.addEventListener('resize',fallbackScan)}
    record.visible=visible(node);if(record.visible)queue(record);
  }
}
function enhanceInbox(rows){
  if(!ensureOwner())return;cleanup();
  const byId=new Map((rows||[]).map(c=>[Number(c.id),c]));
  document.querySelectorAll('#inboxList .leadItem[data-conversation-id]').forEach(row=>{const c=byId.get(Number(row.dataset.conversationId));if(c)attach(row.querySelector('.leadAvatar'),c)});
}
function enhanceHeader(c){cleanup();attach(document.getElementById('conversationAvatar'),c)}
function initials(c){const parts=String(c?.display_name||'Турист').trim().split(/\s+/).filter(Boolean);return parts.slice(0,2).map(word=>Array.from(word)[0]?.toUpperCase()||'').join('')||'Т'}
function enhanceCard(c){
  cleanup();
  const hero=document.querySelector('#leadCard .leadHero');if(!hero)return;hero.classList.add('customerAvatarHero');
  let avatar=hero.querySelector('.customerAvatarCard');
  if(!avatar){
    let identity=hero.querySelector('.leadHeroTop > div');
    if(!identity){identity=document.createElement('div');const title=hero.querySelector('.leadHeroName'),source=hero.querySelector('.leadHeroSource');if(title)identity.appendChild(title);if(source)identity.appendChild(source);hero.prepend(identity)}
    const text=document.createElement('div');text.className='customerAvatarCardText';while(identity.firstChild)text.appendChild(identity.firstChild);
    identity.classList.add('customerAvatarCardIdentity');avatar=document.createElement('div');avatar.className='customerAvatarCard';identity.appendChild(avatar);identity.appendChild(text);
  }
  avatar.textContent=initials(c);attach(avatar,c);
}
window.WorkspaceV2CustomerAvatar={enhanceInbox,enhanceHeader,enhanceCard,invalidateConversation,resetSession};
})();
