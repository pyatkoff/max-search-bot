(function(){
const W=window.WorkspaceV2;if(!W)return;const {$,S}=W;
let bound=false,state='ready',query='',hits=[],active=-1;
function panel(){return $('conversationSearchPanel')}
function clearMarks(){const box=$('messages');if(!box)return;box.querySelectorAll('mark.conversationSearchHit').forEach(mark=>{const parent=mark.parentNode;mark.replaceWith(document.createTextNode(mark.textContent));parent?.normalize()})}
function updateStatus(){
  const count=$('conversationSearchCount');if(!count)return;
  count.textContent=state==='loading'?'Загружаем переписку…':state==='error'?'Не удалось загрузить переписку. Поиск доступен после повторного открытия.':!query?'Введите текст':hits.length?(active+1)+' из '+hits.length:'Совпадений нет';
  ['conversationSearchPrevious','conversationSearchNext'].forEach(id=>{const el=$(id);if(el)el.disabled=state!=='ready'||!hits.length});
}
function select(index,{scroll=true}={}){
  if(!hits.length||state!=='ready')return false;
  active=(index+hits.length)%hits.length;
  hits.forEach((hit,i)=>hit.marks.forEach(mark=>mark.classList.toggle('isCurrentSearchHit',i===active)));
  if(scroll){
    const box=$('messages'),row=hits[active].row;
    // Inform the canonical scroll owner before positioning, so media/composer
    // reflow preserves the searched message instead of returning to the bottom.
    box.dispatchEvent(new WheelEvent('wheel',{deltaY:-1}));
    box.scrollTop+=row.getBoundingClientRect().top-box.getBoundingClientRect().top-16;
    box.dispatchEvent(new Event('scroll'));window.WorkspaceV2Jump?.capture();
  }
  updateStatus();return true;
}
function rebuild({scroll=false}={}){
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
function setState(value){state=value;rebuild()}
function rendered(){if(panel()&&!panel().classList.contains('hidden'))rebuild()}
function close({focus=true}={}){const el=panel();if(!el)return false;el.classList.add('hidden');$('conversationSearchOpen')?.setAttribute('aria-expanded','false');clearMarks();hits=[];active=-1;if(focus)$('conversationSearchOpen')?.focus({preventScroll:true});return true}
function reset(){query='';const input=$('conversationSearchInput');if(input)input.value='';state='ready';close({focus:false});updateStatus()}
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
  [['conversationSearchPrevious','Назад','Предыдущее совпадение',()=>select(active-1)],['conversationSearchNext','Вперёд','Следующее совпадение',()=>select(active+1)],['conversationSearchClose','Закрыть','Закрыть поиск',()=>close()]].forEach(([id,text,aria,handler])=>{const b=document.createElement('button');b.type='button';b.id=id;b.className='actionBtn';b.textContent=text;b.setAttribute('aria-label',aria);b.onclick=handler;controls.appendChild(b)});
  const scope=document.createElement('div');scope.id='conversationSearchScope';scope.className='conversationSearchScope';scope.textContent='Только загруженные сообщения. Поиск не проверяет всю историю и цитаты.';
  root.append(controls,scope);box.before(root);
  input.addEventListener('input',event=>{if(event.isComposing)return;query=input.value;rebuild({scroll:true})});
  input.addEventListener('compositionend',()=>{query=input.value;rebuild({scroll:true})});
  root.addEventListener('keydown',event=>{if(event.isComposing)return;if(event.key==='Escape'){event.preventDefault();event.stopPropagation();close()}else if(event.key==='Enter'&&!event.ctrlKey&&!event.metaKey&&!event.altKey){event.preventDefault();select(active+(event.shiftKey?-1:1))}});
  updateStatus();
}
window.WorkspaceV2ConversationSearch={bind,open,close,reset,setState,rendered};
})();
