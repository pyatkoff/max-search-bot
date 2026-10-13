(function(){
const W=window.WorkspaceV2;if(!W)return;const {$,S}=W;
let bound=false,observer=null,resizeObserver=null,frame=0,followingLatest=true,readingAnchor=null,smoothJump=false;
let lastGeometry=null,lastAnchor=null;const observedRows=new Set();
function distanceFromLatest(){const box=$('messages');if(!box)return 0;return Math.max(0,box.scrollHeight-box.scrollTop-box.clientHeight)}
function geometry(box){return {top:box.scrollTop,height:box.clientHeight,total:box.scrollHeight,width:box.clientWidth}}
function sameSize(a,b){return !!a&&a.height===b.height&&a.total===b.total&&a.width===b.width}
function findAnchor(box){
  const edge=box.getBoundingClientRect().top;
  const row=[...box.children].find(el=>el.getBoundingClientRect().bottom>edge+1);
  const offset=row?row.getBoundingClientRect().top-edge:0;
  return row?{row,key:row.dataset.scrollMessageKey||'',offset,contentTop:offset+box.scrollTop}:null;
}
function anchorRow(anchor,box){return anchor?.row.parentElement===box?anchor.row:anchor?.key?[...box.children].find(el=>el.dataset.scrollMessageKey===anchor.key):null}
function rememberGeometry(box){lastGeometry=geometry(box);lastAnchor=findAnchor(box)}
function ensureButton(){let button=$('jumpToLatest');if(button)return button;const actions=document.querySelector('#conversationZone .conversationHead .actions');if(!actions)return null;button=document.createElement('button');button.id='jumpToLatest';button.className='jumpToLatest actionBtn hidden';button.type='button';button.setAttribute('aria-label','Перейти к последним сообщениям');button.title='К последним сообщениям';button.innerHTML='<span aria-hidden="true">↓</span><span class="jumpToLatestLabel">К последним</span>';button.onclick=()=>scrollToLatest({smooth:true});const anchor=$('mobileLeadBtn');if(anchor)actions.insertBefore(button,anchor);else actions.prepend(button);return button}
function update(){const button=ensureButton(),box=$('messages');if(!button||!box)return;const hasConversation=Number(S.current||0)>0,farFromLatest=distanceFromLatest()>48;button.classList.toggle('hidden',!(hasConversation&&farFromLatest));button.setAttribute('aria-hidden',hasConversation&&farFromLatest?'false':'true')}
function align({syncIntent=true}={}){
  const box=$('messages');if(!box||!Number(S.current||0)||S.authExpired)return;
  if(syncIntent)syncScrollIntent();
  if(followingLatest){smoothJump=false;box.scrollTop=box.scrollHeight;readingAnchor=null;}
  else if(readingAnchor){
    const row=anchorRow(readingAnchor,box);
    if(row)box.scrollTop+=row.getBoundingClientRect().top-box.getBoundingClientRect().top-readingAnchor.offset;
  }
  if(!followingLatest)readingAnchor=findAnchor(box);
  rememberGeometry(box);update();
}
function schedule(){if(frame)return;frame=window.requestAnimationFrame(()=>{frame=0;align()})}
function syncScrollIntent(){
  const box=$('messages');if(!box)return false;const next=geometry(box),sizeChanged=!sameSize(lastGeometry,next);
  if(lastGeometry&&next.top!==lastGeometry.top){
    const row=anchorRow(lastAnchor,box);
    // Native anchoring may move scrollTop when media above the visible row grows.
    // Subtract that layout movement to recognise a real scroll even when its
    // event arrives after the image/viewport resize.
    const layoutShift=sizeChanged&&row?row.getBoundingClientRect().top-box.getBoundingClientRect().top+next.top-lastAnchor.contentTop:0;
    const movement=next.top-lastGeometry.top-layoutShift;
    // A resize can also move a pinned viewport forwards (for example when
    // focus hides shortcuts and opens the emoji menu). Leaving the latest
    // message requires movement towards history, not that layout adjustment.
    if(!sizeChanged||(Math.abs(movement)>2&&(!followingLatest||movement < -48))){
    if(smoothJump&&movement>=0){followingLatest=true;if(distanceFromLatest()<=2)smoothJump=false;}
    else {smoothJump=false;followingLatest=distanceFromLatest()<=48;}
    readingAnchor=followingLatest?null:findAnchor(box);
    }
  }
  if(!sizeChanged)rememberGeometry(box);
  return sizeChanged;
}
function onScroll(){if(syncScrollIntent())schedule();update()}
function capture(){
  onScroll();const box=$('messages');
  return box?{followingLatest,anchor:followingLatest?null:readingAnchor||findAnchor(box),top:box.scrollTop}:null;
}
function restore(position,{stickToBottom=false,preserveScroll=false}={}){
  const box=$('messages');if(!box)return;
  smoothJump=false;followingLatest=stickToBottom||(preserveScroll&&position?.followingLatest===true);
  readingAnchor=followingLatest?null:preserveScroll?position?.anchor||null:null;
  if(!followingLatest&&preserveScroll&&position)box.scrollTop=position.top;
  if(!followingLatest&&!readingAnchor)readingAnchor=findAnchor(box);
  align({syncIntent:false});observeRows();schedule();
}
function isFollowingLatest(){onScroll();return followingLatest}
function scrollToLatest({smooth=false}={}){
  const box=$('messages');if(!box)return;followingLatest=true;readingAnchor=null;smoothJump=smooth;
  rememberGeometry(box);box.scrollTo({top:box.scrollHeight,behavior:smooth?'smooth':'auto'});
  if(!smooth)align({syncIntent:false});window.setTimeout(update,smooth?220:0);
}
function observeRows(){
  const box=$('messages');if(!box||!resizeObserver)return;
  for(const row of observedRows)if(row.parentElement!==box){resizeObserver.unobserve(row);observedRows.delete(row);}
  for(const row of box.children)if(!observedRows.has(row)){observedRows.add(row);resizeObserver.observe(row);}
}
function bind(){
  if(bound)return;bound=true;const box=$('messages');if(!box)return;
  rememberGeometry(box);followingLatest=distanceFromLatest()<=48;ensureButton();
  box.addEventListener('scroll',onScroll,{passive:true});
  observer=new MutationObserver(()=>{observeRows();schedule()});observer.observe(box,{childList:true,subtree:true});
  if('ResizeObserver'in window){resizeObserver=new ResizeObserver(schedule);resizeObserver.observe(box);observeRows();}
  box.addEventListener('load',schedule,true);box.addEventListener('loadedmetadata',schedule,true);
  window.addEventListener('resize',schedule,{passive:true});update();
}
if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',bind,{once:true});else bind();
window.WorkspaceV2Jump={bind,update,scrollToLatest,distanceFromLatest,capture,restore,isFollowingLatest};
})();
