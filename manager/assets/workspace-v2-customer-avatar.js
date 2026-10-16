(function(){
'use strict';
const W=window.WorkspaceV2,{S}=W;
const cache=new Map(),slots=new Map(),waiting=new Map(),active=new Map(),fullCache=new Map();
const MAX_ENTRIES=48,MAX_BYTES=8*1024*1024,MAX_PHOTO_BYTES=256*1024;
const PHOTO_TTL=24*60*60*1000,NEGATIVE_TTL=60*1000;
let observer=null,epoch=0,owner='',fallbackBound=false,dialog=null,viewer=null,fullJob=null;
const FULL_MAX_BYTES=1024*1024,FULL_CACHE_ENTRIES=8,FULL_CACHE_BYTES=8*1024*1024;
function account(){return !S.authExpired&&Number(S.manager?.id)>0?Number(S.manager.id)+':'+Number(S.authGeneration||0):''}
function current(record){return record.epoch===epoch&&record.owner===account()&&record.owner===owner}
function clearImage(node){
  node.querySelectorAll('.customerAvatarImage,.customerAvatarOpen').forEach(image=>image.remove());
  node.setAttribute('aria-hidden','true');
  const row=node.closest('.leadItem');if(row?.dataset.avatarInteractive){if(!row.querySelector('.m2RowOpen')){row.setAttribute('role','button');row.tabIndex=0}delete row.dataset.avatarInteractive;const identity=row.querySelector('.leadIdentity');identity?.removeAttribute('role');identity?.removeAttribute('tabindex');identity?.removeAttribute('aria-label')}
}
function setInitials(node,text){
  if(!node)return;
  // Update only the text node; canonical polling must keep the decoded image.
  let label=[...node.childNodes].find(child=>child.nodeType===3);
  if(!label){label=document.createTextNode('');node.prepend(label)}label.nodeValue=String(text||'');
}
function fullCurrent(target=viewer){return !!target&&viewer===target&&current(target)&&Number(S.current||0)===target.contextId&&dialog?.open}
function clearFullCache(conversationId=0){fullCache.forEach((entry,key)=>{if(!conversationId||entry.conversationId===conversationId){URL.revokeObjectURL(entry.url);fullCache.delete(key)}})}
function focusableAvatar(node){
  if(!node?.isConnected||node.disabled||!visible(node)||getComputedStyle(node).visibility==='hidden')return false;
  // Mobile panels cover the Inbox without hiding its DOM. A return target must
  // actually be exposed, rather than merely having a rectangle in the viewport.
  const rect=node.getBoundingClientRect(),hit=document.elementFromPoint(Math.max(0,Math.min(window.innerWidth-1,rect.left+rect.width/2)),Math.max(0,Math.min(window.innerHeight-1,rect.top+rect.height/2)));
  return hit===node||!!(hit&&node.contains(hit));
}
function closeViewer({clear=false,restore=true}={}){
  const previous=viewer;viewer=null;fullJob?.controller.abort();fullJob=null;
  if(previous?.leave){window.removeEventListener('popstate',previous.leave);window.removeEventListener('pagehide',previous.leave)}
  if(dialog?.open)dialog.close();dialog?.querySelector('.customerAvatarFullImage')?.removeAttribute('src');
  if(clear)clearFullCache();
  if(restore&&previous&&current(previous)&&Number(S.current||0)===previous.contextId){const opener=focusableAvatar(previous.opener)?previous.opener:[...document.querySelectorAll('[data-customer-avatar="'+previous.conversationId+'"] .customerAvatarOpen')].find(focusableAvatar);opener?.focus({preventScroll:true})}
}
function syncViewer(){if(viewer&&!fullCurrent())closeViewer({clear:true,restore:false})}
function conversationChanging(id){if(viewer&&Number(id)!==viewer.contextId)closeViewer({clear:true,restore:false})}
function makeInteractive(node,record){
  node.removeAttribute('aria-hidden');
  const row=node.closest('.leadItem'),identity=row?.querySelector('.leadIdentity');
  if(row&&identity&&!row.querySelector('.m2RowOpen')){row.dataset.avatarInteractive='true';row.setAttribute('role','group');row.tabIndex=-1;identity.setAttribute('role','button');identity.tabIndex=0;identity.setAttribute('aria-label',row.getAttribute('aria-label')||'Открыть диалог')}
  if(node.querySelector('.customerAvatarOpen'))return;
  const button=document.createElement('button');button.type='button';button.className='customerAvatarOpen';button.setAttribute('aria-label','Увеличить фото клиента');button.setAttribute('aria-haspopup','dialog');button.setAttribute('aria-controls','customerAvatarDialog');button.title='Увеличить фото клиента';
  button.addEventListener('click',event=>{event.preventDefault();event.stopPropagation();openViewer(node,record,button)});
  button.addEventListener('keydown',event=>event.stopPropagation());node.appendChild(button);
}
function ensureDialog(){
  if(dialog)return dialog;
  dialog=document.createElement('dialog');dialog.id='customerAvatarDialog';dialog.className='customerAvatarDialog';dialog.setAttribute('aria-labelledby','customerAvatarHeading');
  dialog.innerHTML='<div class="customerAvatarViewer"><header class="customerAvatarViewerHead"><h2 id="customerAvatarHeading">Фото клиента</h2><button type="button" class="customerAvatarClose" aria-label="Закрыть фото клиента">×</button></header><div class="customerAvatarStage"><img class="customerAvatarFullImage" alt="Фото клиента" draggable="false"></div><div class="customerAvatarViewerStatus" role="status" aria-live="polite"></div><footer class="customerAvatarViewerNav" hidden><button type="button" class="customerAvatarPrevious" aria-label="Предыдущее фото">‹</button><span class="customerAvatarCount" aria-live="polite"></span><button type="button" class="customerAvatarNext" aria-label="Следующее фото">›</button></footer></div>';
  document.body.appendChild(dialog);
  dialog.querySelector('.customerAvatarClose').onclick=()=>closeViewer();
  dialog.querySelector('.customerAvatarPrevious').onclick=()=>navigatePhoto(-1);
  dialog.querySelector('.customerAvatarNext').onclick=()=>navigatePhoto(1);
  dialog.addEventListener('cancel',event=>{event.preventDefault();closeViewer()});
  dialog.addEventListener('click',event=>{if(event.target===dialog)closeViewer()});
  dialog.addEventListener('keydown',event=>{event.stopPropagation();if(event.key==='ArrowLeft'||event.key==='ArrowRight'){event.preventDefault();navigatePhoto(event.key==='ArrowLeft'?-1:1)}});
  let touch=null;const stage=dialog.querySelector('.customerAvatarStage');
  stage.addEventListener('touchstart',event=>{const point=event.touches[0];touch=point?{x:point.clientX,y:point.clientY}:null},{passive:true});
  stage.addEventListener('touchend',event=>{const point=event.changedTouches[0],start=touch;touch=null;if(!start||!point)return;const dx=point.clientX-start.x,dy=point.clientY-start.y;if(Math.abs(dx)>45&&Math.abs(dx)>Math.abs(dy)*1.3)navigatePhoto(dx>0?-1:1)},{passive:true});
  return dialog;
}
function updateViewer(text=''){
  if(!fullCurrent())return;
  const count=viewer.count,multiple=count>1;dialog.querySelector('.customerAvatarViewerStatus').textContent=text;dialog.querySelector('.customerAvatarViewerNav').hidden=!multiple;
  dialog.querySelector('.customerAvatarCount').textContent=(viewer.index+1)+' из '+count;
  dialog.querySelector('.customerAvatarPrevious').disabled=viewer.index<=0;dialog.querySelector('.customerAvatarNext').disabled=viewer.index>=count-1;
}
function openViewer(node,record,opener){
  if(!ensureOwner()||!current(record)||slots.get(node)!==record)return;
  const thumbnail=cacheEntry(record.key);if(!thumbnail?.url||!node.querySelector('.customerAvatarImage'))return;
  closeViewer({restore:false});ensureDialog();
  viewer={...record,contextId:Number(S.current||0),opener,index:0,loadedIndex:0,count:1,thumbnail:thumbnail.url,leave:()=>closeViewer({restore:false})};
  window.addEventListener('popstate',viewer.leave);window.addEventListener('pagehide',viewer.leave);
  dialog.querySelector('.customerAvatarFullImage').src=thumbnail.url;dialog.showModal();dialog.querySelector('.customerAvatarClose').focus();updateViewer('Загружаем фото…');void loadFullPhoto(viewer,0);
}
function navigatePhoto(direction){if(!fullCurrent()||viewer.count<2)return;const index=viewer.index+direction;if(index<0||index>=viewer.count)return;viewer.index=index;void loadFullPhoto(viewer,index)}
function fullEntry(key){const entry=fullCache.get(key);if(!entry)return null;if(entry.until<=Date.now()){URL.revokeObjectURL(entry.url);fullCache.delete(key);return null}fullCache.delete(key);fullCache.set(key,entry);return entry}
function pruneFullCache(){let bytes=0;fullCache.forEach(entry=>bytes+=entry.bytes);while(fullCache.size>FULL_CACHE_ENTRIES||bytes>FULL_CACHE_BYTES){const key=fullCache.keys().next().value,entry=fullCache.get(key);bytes-=entry.bytes;URL.revokeObjectURL(entry.url);fullCache.delete(key)}}
function photoCount(response){const raw=String(response.headers.get('X-Avatar-Photo-Count')||'');return /^\d{1,4}$/.test(raw)&&Number(raw)>=1&&Number(raw)<=1000?Number(raw):1}
function decodeFullPhoto(url,signal){
  return new Promise((resolve,reject)=>{
    const image=document.createElement('img');let finished=false;
    const done=ok=>{if(finished)return;finished=true;image.onload=null;image.onerror=null;image.removeAttribute('src');signal.removeEventListener('abort',aborted);ok?resolve():reject(new Error('photo_decode_failed'))};
    const aborted=()=>done(false);signal.addEventListener('abort',aborted,{once:true});
    image.onerror=()=>done(false);image.onload=()=>{if(!image.naturalWidth||!image.naturalHeight)return done(false);if(typeof image.decode==='function')image.decode().then(()=>done(true),()=>done(false));else done(true)};
    image.src=url;if(signal.aborted)done(false);
  });
}
async function loadFullPhoto(target,index){
  fullJob?.controller.abort();fullJob=null;
  const key=target.key+':'+index,cached=fullEntry(key);
  if(cached&&fullCurrent(target)){target.count=cached.count;target.loadedIndex=index;dialog.querySelector('.customerAvatarFullImage').src=cached.url;updateViewer(cached.limited?'Показаны первые 1000 фото.':'');return}
  if(!fullCurrent(target))return;
  const job={controller:new AbortController(),target,index};fullJob=job;const timer=setTimeout(()=>job.controller.abort(),10000);let objectUrl='',retained=false;updateViewer('Загружаем фото…');
  try{
    const response=await fetch('customer-avatar.php?conversation_id='+encodeURIComponent(target.conversationId)+'&account='+encodeURIComponent(Number(S.manager.id))+'&channel='+encodeURIComponent(target.channel)+'&size=full&photo='+index,{credentials:'same-origin',cache:'default',redirect:'error',signal:job.controller.signal});
    if(!fullCurrent(target)||fullJob!==job||target.index!==index)return;
    if(response.status===401){resetSession();W.showAuthRecovery();return}if(response.status===403){invalidateConversation(target.conversationId);return}if(!response.ok)throw new Error('photo_unavailable');
    const type=String(response.headers.get('Content-Type')||'').split(';')[0].toLowerCase();if(!['image/jpeg','image/png','image/webp'].includes(type)||Number(response.headers.get('Content-Length')||0)>FULL_MAX_BYTES)throw new Error('invalid_photo');
    const count=photoCount(response);if(index>=count){target.count=count;if(target.loadedIndex>=count){target.loadedIndex=0;dialog.querySelector('.customerAvatarFullImage').src=target.thumbnail}throw new Error('photo_index_unavailable')}
    const blob=await response.blob();if(!fullCurrent(target)||fullJob!==job||target.index!==index)return;if(!blob.size||blob.size>FULL_MAX_BYTES)throw new Error('invalid_photo');
    objectUrl=URL.createObjectURL(blob);await decodeFullPhoto(objectUrl,job.controller.signal);if(!fullCurrent(target)||fullJob!==job||target.index!==index)return;
    const entry={url:objectUrl,bytes:blob.size,until:Date.now()+PHOTO_TTL,conversationId:target.conversationId,count,limited:response.headers.get('X-Avatar-Photo-Limited')==='1'};
    fullCache.set(key,entry);retained=true;pruneFullCache();target.count=entry.count;target.loadedIndex=index;dialog.querySelector('.customerAvatarFullImage').src=entry.url;updateViewer(entry.limited?'Показаны первые 1000 фото.':'');
  }catch(error){if(fullCurrent(target)&&fullJob===job&&target.index===index){if(index===0)dialog.querySelector('.customerAvatarFullImage').src=target.thumbnail;else target.index=target.loadedIndex;updateViewer('Не удалось загрузить большое фото. Попробуйте открыть его позже.')}}finally{clearTimeout(timer);if(objectUrl&&!retained)URL.revokeObjectURL(objectUrl);if(fullJob===job)fullJob=null}
}
function resetSession(){
  closeViewer({clear:true,restore:false});epoch++;observer?.disconnect();observer=null;
  active.forEach(job=>job.controller.abort());active.clear();waiting.clear();
  slots.forEach((record,node)=>{clearImage(node);delete node.dataset.customerAvatar});slots.clear();
  cache.forEach(entry=>{if(entry.url)URL.revokeObjectURL(entry.url)});cache.clear();owner='';
}
function ensureOwner(){const next=account();if(owner!==next){resetSession();owner=next}return !!next}
function dropCache(key){if(viewer?.key===key)closeViewer({clear:true,restore:false});const entry=cache.get(key);if(entry?.url)URL.revokeObjectURL(entry.url);cache.delete(key);slots.forEach((record,node)=>{if(record.key===key)clearImage(node)})}
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
  const existing=node.querySelector('.customerAvatarImage');if(existing?.getAttribute('src')===entry.url){if(existing.complete&&existing.naturalWidth)makeInteractive(node,record);return;}
  clearImage(node);
  const image=document.createElement('img');image.className='customerAvatarImage';image.alt='';image.decoding='async';image.draggable=false;
  image.addEventListener('error',()=>{
    if(!current(record)||cache.get(record.key)!==entry)return;
    dropCache(record.key);cache.set(record.key,{until:Date.now()+NEGATIVE_TTL,bytes:0});pruneCache();
  },{once:true});
  image.addEventListener('load',()=>{if(current(record)&&slots.get(node)===record&&node.isConnected)makeInteractive(node,record)},{once:true});
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
    const response=await fetch('customer-avatar.php?conversation_id='+encodeURIComponent(record.conversationId)+'&account='+encodeURIComponent(Number(S.manager.id))+'&channel='+encodeURIComponent(record.channel),{credentials:'same-origin',cache:'default',redirect:'error',signal:controller.signal});
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
  const id=Number(conversationId);if(viewer?.conversationId===id)closeViewer({restore:false});clearFullCache(id);const keys=['telegram','max'].map(channel=>owner+':'+id+':'+channel);
  keys.forEach(key=>{active.get(key)?.controller.abort();active.delete(key);waiting.delete(key);dropCache(key)});
  slots.forEach((record,node)=>{if(record.conversationId===id){observer?.unobserve(node);clearImage(node);slots.delete(node)}});
  // A denial must not be retried by every list/header refresh.
  if(owner)keys.forEach(key=>cache.set(key,{until:Date.now()+NEGATIVE_TTL,bytes:0}));pruneCache();
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
  const key=owner+':'+conversationId+':'+c.channel,previous=slots.get(node);if(previous&&current(previous)&&previous.key===key){previous.name=c.display_name||'Турист';const entry=cacheEntry(key);if(entry?.url)paint(node,previous,entry);if(previous.visible)queue(previous);return}if(previous){if(viewer?.key===previous.key)closeViewer({clear:true,restore:false});observer?.unobserve(node);clearImage(node);slots.delete(node);delete node.dataset.customerAvatar}
  // Both messengers use the same protected, visible-only binary endpoint.
  // Website clients keep initials without portrait requests.
  if(!['telegram','max'].includes(c.channel))return;
  ensureObserver();
  node.classList.add('customerAvatar');node.dataset.customerAvatar=String(conversationId);node.setAttribute('aria-hidden','true');
  const record={owner,epoch,conversationId,channel:c.channel,key,visible:false};slots.set(node,record);
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
function enhanceHeader(c){syncViewer();cleanup();const node=document.getElementById('conversationAvatar');setInitials(node,initials(c));attach(node,c)}
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
  setInitials(avatar,initials(c));attach(avatar,c);
}
window.WorkspaceV2CustomerAvatar={enhanceInbox,enhanceHeader,enhanceCard,invalidateConversation,resetSession,setInitials,conversationChanging};
})();
