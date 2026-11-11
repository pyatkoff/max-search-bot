(function(){
const W=window.WorkspaceV2;if(!W)return;const {$,S,api}=W;
let bound=false,state='ready',query='',hits=[],active=-1,all=false,revision=0,timer=0;
function emptyRemote(){return {hits:[],active:-1,busy:false,error:false,before:0,snapshot:0,hasMore:true,scanned:0}}
let remote=emptyRemote();
function panel(){return $('conversationSearchPanel')}
function invalidate(){revision++;clearTimeout(timer);timer=0;remote.busy=false}
function fullCurrent(captured,ticket,text){return ticket===revision&&all&&state==='ready'&&query===text&&!panel()?.classList.contains('hidden')&&!S.authExpired&&JSON.stringify(window.WorkspaceV2Conversation?.captureReplyContext?.())===JSON.stringify(captured)&&window.WorkspaceV2Conversation?.canNavigateMessages?.()===true}
function validPage(result,captured,text,cursor,snapshot){
 const rows=result?.matches;
 return result?.ok===true&&Number(result.conversation_id)===captured.conversationId&&result.query===text&&Number.isSafeInteger(result.snapshot_id)&&result.snapshot_id>=0&&(!snapshot||result.snapshot_id===snapshot)&&Number.isSafeInteger(result.before_id)&&result.before_id>=0&&Number.isInteger(result.scanned)&&result.scanned>=0&&result.scanned<=1000&&typeof result.has_more==='boolean'&&(!result.has_more||result.scanned>0)&&(!cursor||result.before_id<cursor)&&result.before_id<=result.snapshot_id&&Array.isArray(rows)&&rows.length<=25&&rows.length<=result.scanned&&rows.every((row,i)=>Number.isSafeInteger(row?.message_id)&&row.message_id>0&&row.message_id<=result.snapshot_id&&(!cursor||row.message_id<cursor)&&row.message_id>=result.before_id&&(i===0||row.message_id<rows[i-1].message_id)&&typeof row.snippet==='string'&&row.snippet.length<=1000&&typeof row.sender_type==='string'&&typeof row.created_at==='string');
}
async function loadFull(){
 if(remote.busy||!remote.hasMore||!query.trim()||state!=='ready')return false;
 const captured=window.WorkspaceV2Conversation?.captureReplyContext?.(),text=query,ticket=++revision,start=remote.hits.length;
 if(!fullCurrent(captured,ticket,text))return false;
 remote.busy=true;remote.error=false;updateStatus();
 // Each request inspects at most 1000 saved rows. Stop after ten requests or
 // one page of hits; a visible continuation prevents an unbounded background
 // scan and keeps an empty partial result distinct from no matches anywhere.
 for(let page=0;page<10;page++){
  const cursor=remote.before,snapshot=remote.snapshot,valid=()=>fullCurrent(captured,ticket,text);let result;
  try{result=await api('message_search',{conversation_id:captured.conversationId,query:text,before_id:cursor,snapshot_id:snapshot},{isCurrent:valid})}catch(e){result=null}
  if(!valid()){if(ticket===revision){remote.busy=false;remote.error=true;updateStatus()}return false}
  if(!validPage(result,captured,text,cursor,snapshot)){
   remote.busy=false;
   if([403,404].includes(result?.http_status)){window.WorkspaceV2Conversation?.invalidateMessageAccess?.();return false}
   remote.error=true;updateStatus();return false;
  }
  remote.hits.push(...result.matches);remote.snapshot=result.snapshot_id;remote.before=result.before_id;remote.hasMore=result.has_more;remote.scanned+=result.scanned;
  if(remote.active<0&&remote.hits.length)remote.active=0;
  updateStatus();
  if(!remote.hasMore||remote.hits.length>start)break;
 }
 remote.busy=false;if(remote.hits.length>start)remote.active=start;updateStatus();return true;
}
function renderFull(){
 const toggle=$('conversationSearchAll'),scope=$('conversationSearchFullScope'),preview=$('conversationSearchResult'),more=$('conversationSearchMore');if(!toggle)return;
 toggle.textContent=all?'Загруженные':'Вся история';toggle.setAttribute('aria-pressed',String(all));toggle.setAttribute('aria-label',all?'Искать в загруженных сообщениях':'Искать во всей сохранённой истории');toggle.title=toggle.getAttribute('aria-label');toggle.disabled=state!=='ready';
 $('conversationSearchScope').hidden=all;scope.hidden=!all;preview.hidden=!all||remote.active<0;
 scope.textContent='Вся сохранённая история · от новых к старым. Без текста цитат и файлов.'+(query.trim()&&remote.scanned?' Проверено: '+remote.scanned+'. '+(remote.hasMore?'Есть продолжение.':'Проверена вся история на момент поиска.'):'');
 const hit=remote.hits[remote.active];if(hit){preview.textContent=(hit.sender_type==='manager'?'Менеджер: ':hit.sender_type==='customer'?'Клиент: ':'Сообщение: ')+hit.snippet;preview.title='Открыть сообщение и соседнюю переписку';preview.disabled=remote.busy||state!=='ready'}
 more.hidden=!all||!query.trim()||remote.busy||(!remote.hasMore&&!remote.error);more.textContent=remote.error?'Повторить поиск':'Продолжить поиск';more.title=more.textContent;more.disabled=state!=='ready';
}
function clearMarks(){const box=$('messages');if(!box)return;box.querySelectorAll('mark.conversationSearchHit').forEach(mark=>{const parent=mark.parentNode;mark.replaceWith(document.createTextNode(mark.textContent));parent?.normalize()})}
function updateStatus(){
  const count=$('conversationSearchCount');if(!count)return;
  count.textContent=state==='loading'?'Загружаем переписку…':state==='error'?'Не удалось загрузить переписку. Поиск доступен после повторного открытия.':all?!query.trim()?'Введите текст':remote.busy?'Ищем по всей истории…':remote.error?'Поиск прерван. Можно повторить.':remote.hits.length?(remote.active+1)+' из '+remote.hits.length+(remote.hasMore?'+':'')+' сообщений':remote.hasMore?'Совпадений пока нет':'Совпадений нет во всей истории':!query?'Введите текст':hits.length?(active+1)+' из '+hits.length:'Совпадений нет';
  ['conversationSearchPrevious','conversationSearchNext'].forEach(id=>{const el=$(id);if(el)el.disabled=state!=='ready'||(all?remote.busy||(id==='conversationSearchPrevious'?remote.active<=0:remote.active>=remote.hits.length-1&&!remote.hasMore)||!query.trim():!hits.length)});renderFull();
}
function select(index,{scroll=true}={}){
  if(all){if(remote.busy||state!=='ready')return false;if(index>=remote.hits.length){void loadFull();return true}remote.active=Math.max(0,index);updateStatus();return true}
  if(!hits.length||state!=='ready')return false;
  active=(index+hits.length)%hits.length;
  hits.forEach((hit,i)=>hit.marks.forEach(mark=>mark.classList.toggle('isCurrentSearchHit',i===active)));
  if(scroll){
    const box=$('messages'),mark=hits[active].marks[0];
    // Inform the canonical scroll owner before positioning, so media/composer
    // reflow preserves the searched message instead of returning to the bottom.
    box.dispatchEvent(new WheelEvent('wheel',{deltaY:-1}));
    // A match can occur far below the row header or wrap onto several lines.
    // Position its first actual text fragment; a fixed row inset can leave the
    // matching text outside a short transcript viewport.
    const fragment=mark.getClientRects()[0]||mark.getBoundingClientRect();
    const inset=Math.min(16,Math.max(0,(box.clientHeight-fragment.height)/2));
    box.scrollTop+=fragment.top-box.getBoundingClientRect().top-box.clientTop-inset;
    box.dispatchEvent(new Event('scroll'));window.WorkspaceV2Jump?.capture();
  }
  updateStatus();return true;
}
function rebuild({scroll=false}={}){
  if(all){clearMarks();updateStatus();return}
  const previousKey=hits[active]?.key,previousOffset=hits[active]?.offset;
  clearMarks();hits=[];active=-1;
  const box=$('messages');if(!box||!query||state!=='ready'){updateStatus();return}
  box.querySelectorAll('.msg').forEach((row,rowIndex)=>{
    const body=row.querySelector('.msgBody');if(!body)return;
    const text=body.textContent;
    // Lowercasing can change code-unit length in some alphabets. Use a literal
    // escaped Unicode regexp for exact original offsets, without parsing HTML.
    const pattern=new RegExp(query.replace(/[.*+?^${}()|[\]\\]/g,'\\$&'),'giu');
    const matches=[...text.matchAll(pattern)];if(!matches.length)return;
    const walker=document.createTreeWalker(body,NodeFilter.SHOW_TEXT),nodes=[];let node,offset=0;
    while((node=walker.nextNode())){nodes.push({node,start:offset,end:offset+node.textContent.length});offset+=node.textContent.length}
    const rowHits=matches.map(match=>({row,key:row.dataset.scrollMessageKey||'row:'+rowIndex,offset:match.index,marks:[],start:match.index,end:match.index+match[0].length}));
    nodes.forEach(({node,start,end})=>{
      const relevant=rowHits.filter(hit=>hit.start<end&&hit.end>start);if(!relevant.length)return;
      const fragment=document.createDocumentFragment(),original=node.textContent;let cursor=0;
      relevant.forEach(hit=>{const left=Math.max(start,hit.start)-start,right=Math.min(end,hit.end)-start;
        fragment.appendChild(document.createTextNode(original.slice(cursor,left)));
        const mark=document.createElement('mark');mark.className='conversationSearchHit';mark.textContent=original.slice(left,right);fragment.appendChild(mark);hit.marks.push(mark);cursor=right;
      });fragment.appendChild(document.createTextNode(original.slice(cursor)));node.replaceWith(fragment);
    });hits.push(...rowHits);
  });
  active=hits.findIndex(hit=>hit.key===previousKey&&hit.offset===previousOffset);if(active<0)active=0;
  if(hits.length)select(active,{scroll});else{active=-1;updateStatus()}
}
function setState(value){state=value;if(value!=='ready')invalidate();rebuild()}
function rendered(){if(panel()&&!panel().classList.contains('hidden'))rebuild()}
function close({focus=true}={}){const el=panel();if(!el)return false;invalidate();el.classList.add('hidden');$('conversationSearchOpen')?.setAttribute('aria-expanded','false');clearMarks();hits=[];active=-1;if(focus&&!window.Manager2Presentation?.focusConversationTool?.('conversationSearchOpen'))$('conversationSearchOpen')?.focus({preventScroll:true});return true}
function reset(){invalidate();query='';all=false;remote=emptyRemote();const input=$('conversationSearchInput');if(input)input.value='';state='ready';close({focus:false});updateStatus()}
function open(){
  if(!S.current||S.authExpired||Number(S.detail?.conversation?.id)!==Number(S.current))return false;
  bind();const el=panel();if(!el)return false;
  el.classList.remove('hidden');$('conversationSearchOpen')?.setAttribute('aria-expanded','true');rebuild();$('conversationSearchInput')?.focus({preventScroll:true});return true;
}
function bind(){
  if(bound)return;const zone=$('conversationZone'),box=$('messages');if(!zone||!box)return;bound=true;
  const root=document.createElement('section');root.id='conversationSearchPanel';root.className='conversationSearchPanel hidden';root.setAttribute('aria-label','Поиск в переписке');
  const controls=document.createElement('div');controls.className='conversationSearchControls';
  const label=document.createElement('label');label.className='conversationSearchLabel';label.textContent='Найти в переписке';label.htmlFor='conversationSearchInput';
  const input=document.createElement('input');input.type='search';input.id='conversationSearchInput';input.placeholder='Текст сообщения';input.maxLength=200;input.autocomplete='off';input.setAttribute('aria-describedby','conversationSearchScope conversationSearchCount');
  const count=document.createElement('span');count.id='conversationSearchCount';count.setAttribute('role','status');count.setAttribute('aria-live','polite');
  controls.append(label,input,count);
  [['conversationSearchPrevious','Назад','Предыдущее совпадение',()=>select((all?remote.active:active)-1)],['conversationSearchNext','Вперёд','Следующее совпадение',()=>select((all?remote.active:active)+1)],['conversationSearchAll','Вся история','Искать во всей сохранённой истории',()=>{invalidate();all=!all;remote=emptyRemote();rebuild();if(all)void loadFull()}],['conversationSearchClose','Закрыть','Закрыть поиск',()=>close()]].forEach(([id,text,aria,handler])=>{const b=document.createElement('button');b.type='button';b.id=id;b.className='actionBtn';b.textContent=text;b.setAttribute('aria-label',aria);b.onclick=handler;controls.appendChild(b)});
  const scope=document.createElement('div');scope.id='conversationSearchScope';scope.className='conversationSearchScope';scope.textContent='Только загруженные сообщения. Поиск не проверяет всю историю и цитаты.';
  const fullScope=document.createElement('div');fullScope.id='conversationSearchFullScope';fullScope.className='conversationSearchScope';fullScope.hidden=true;
  const result=document.createElement('button');result.type='button';result.id='conversationSearchResult';result.className='conversationSearchResult';result.hidden=true;
  result.onclick=()=>{const hit=remote.hits[remote.active],captured=window.WorkspaceV2Conversation?.captureReplyContext?.(),ticket=revision,text=query;if(hit)void window.WorkspaceV2MessageNavigation?.openSearchTarget?.(hit.message_id,result,{isCurrent:()=>fullCurrent(captured,ticket,text)})};
  const more=document.createElement('button');more.type='button';more.id='conversationSearchMore';more.className='actionBtn';more.hidden=true;more.onclick=()=>void loadFull();
  input.setAttribute('aria-describedby','conversationSearchScope conversationSearchFullScope conversationSearchCount');
  controls.insertBefore(more,controls.querySelector('#conversationSearchClose'));root.append(controls,scope,fullScope,result);box.before(root);
  const changed=()=>{invalidate();query=input.value;remote=emptyRemote();rebuild({scroll:true});if(all&&query.trim())timer=setTimeout(()=>void loadFull(),250)};
  input.addEventListener('compositionstart',()=>{invalidate();remote=emptyRemote();updateStatus()});
  input.addEventListener('input',event=>{if(event.isComposing)return;changed()});
  input.addEventListener('compositionend',changed);
  root.addEventListener('keydown',event=>{if(event.isComposing)return;if(event.key==='Escape'){event.preventDefault();event.stopPropagation();close()}else if(event.key==='Enter'&&event.target===input&&!event.ctrlKey&&!event.metaKey&&!event.altKey){event.preventDefault();if(all&&timer){clearTimeout(timer);timer=0;void loadFull()}else select((all?remote.active:active)+(event.shiftKey?-1:1))}});
  updateStatus();
}
window.WorkspaceV2ConversationSearch={bind,open,close,reset,setState,rendered};
})();
