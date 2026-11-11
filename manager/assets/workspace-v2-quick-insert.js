(function(){
'use strict';
const {S,$,esc}=window.WorkspaceV2;
let bound=false,provider=null,panel=null,view=null,sequence=0,timer=null,composing=false,frame=0;
const conversation=()=>window.WorkspaceV2Conversation;
function token(text,start,end){
 if(typeof text!=='string'||!Number.isSafeInteger(start)||start!==end||start<0||start>text.length||text.slice(start)&&!/^\s/u.test(text.slice(start)))return null;
 const match=/(?:^|\s)\/([\p{L}\p{N}_-]{0,120})$/u.exec(text.slice(0,start));
 return match?{query:match[1],start:start-match[1].length-1,end:start}:null;
}
function close(){
 sequence++;clearTimeout(timer);timer=null;view=null;
 if(panel){panel.hidden=true;panel.replaceChildren()}
 const input=$('replyText');input?.setAttribute('aria-expanded','false');input?.removeAttribute('aria-activedescendant');
}
function current(v=view){return !!v&&view===v&&v.sequence===sequence&&!S.authExpired&&conversation()?.canReplaceQuickReply?.(v.range)===true}
function position(){
 if(!panel||panel.hidden)return;
 const reply=$('replyText'),rect=reply.getBoundingClientRect(),viewport=window.visualViewport;
 const left=viewport?.offsetLeft||0,top=viewport?.offsetTop||0,width=viewport?.width||window.innerWidth,height=viewport?.height||window.innerHeight;
 const anchorTop=Math.max(top+8,Math.min(rect.top,top+height-8)),anchorBottom=Math.max(top+8,Math.min(rect.bottom,top+height-8));
 const above=anchorTop-top-8,below=top+height-anchorBottom-8,up=above>=Math.min(220,below),room=Math.max(0,up?above:below),cap=Math.min(280,room);
 const size=Math.min(560,width-16,Math.max(288,rect.width));
 panel.style.width=size+'px';panel.style.maxHeight=cap+'px';panel.style.left=Math.max(left+8,Math.min(rect.left,left+width-size-8))+'px';
 const actualHeight=Math.min(panel.scrollHeight,cap),desired=up?anchorTop-8-actualHeight:anchorBottom+8;
 panel.style.top=Math.max(top+8,Math.min(desired,top+height-actualHeight-8))+'px';
}
function schedulePosition(){if(panel&&!panel.hidden&&!frame)frame=window.requestAnimationFrame(()=>{frame=0;position()})}
function render(){
 const v=view;if(!current(v)){close();return}
 panel.hidden=false;panel.setAttribute('aria-busy',String(v.loading||v.busy));
 panel.innerHTML='<div class="qrComposerHeading">Быстрые ответы · /</div><div id="qrComposerChoices" class="qrComposerChoices" role="listbox" aria-label="Найденные шаблоны">'+v.rows.map((r,i)=>`<button type="button" tabindex="-1" role="option" id="qrComposerOption${i}" data-qr-composer-index="${i}" aria-selected="${i===v.active}" ${v.busy?'disabled':''}><strong>${esc(r.title)}</strong><span>${esc([r.scope==='shared'?'Общий':'Личный',r.category,r.materials?.length?'Файлов: '+r.materials.length:'',r.requires_review?'Условия требуют проверки':''].filter(Boolean).join(' · '))}</span></button>`).join('')+'</div><div class="qrComposerMessage" role="status">'+esc(v.message||(v.loading?'Ищем шаблоны…':v.rows.length?'↑ ↓ — выбрать · Enter — выбрать · Esc — закрыть':'Ничего не найдено. Измените слово после /.'))+'</div>';
 const input=$('replyText');input.setAttribute('aria-expanded','true');
 if(v.rows.length&&!v.loading)input.setAttribute('aria-activedescendant','qrComposerOption'+v.active);else input.removeAttribute('aria-activedescendant');
 position();panel.querySelector('[aria-selected="true"]')?.scrollIntoView({block:'nearest'});
}
async function choose(index){
 const v=view,r=v?.rows[index];if(!r||!current(v)||v.loading||v.busy)return;
 v.busy=true;v.message='Проверяем шаблон…';render();
 try{
  const result=await provider.choose(r,v.range,()=>current(v),message=>{if(current(v)){v.message=message;render()}});
  if(!current(v))return;
  if(result){close();return}
  v.message='Вставка отменена. Проверьте доступ, текст и вложения; выберите шаблон заново.';
 }catch(error){if(current(v))v.message=/[А-Яа-я]/.test(error.message)?error.message:'Не удалось проверить шаблон. Черновик сохранён.'}
 finally{if(current(v)){v.busy=false;render()}}
}
function update(){
 const input=$('replyText');
 if(composing||document.activeElement!==input||input.disabled||$('quickRepliesDialog')?.open){close();return}
 const found=token(input.value,input.selectionStart,input.selectionEnd);if(!found){close();return}
 if(current()&&view.range.text===input.value&&view.range.caretStart===input.selectionStart&&view.range.caretEnd===input.selectionEnd)return;
 close();const range=conversation()?.captureQuickReplyReplacement?.(found.start,found.end);if(!range)return;
 const v={sequence,range,rows:[],active:0,loading:true,busy:false,message:''};view=v;render();
 timer=setTimeout(async()=>{
  timer=null;if(!current(v))return;
  try{
   const result=await provider.search(found.query,range,()=>current(v));if(!current(v))return;
   const project=String(S.detail?.conversation?.project_key||'');
   if(!result?.ok||result.project_key!==project||!Array.isArray(result.replies)||result.replies.some(r=>r.project_key!==project||!Number.isSafeInteger(r.id)||r.id<=0||!Number.isSafeInteger(r.version)||r.version<=0||typeof r.title!=='string'||typeof r.body!=='string'))throw Error('Не удалось загрузить шаблоны. Измените поиск, чтобы попробовать снова.');
   v.rows=result.replies.slice(0,8);v.message=result.has_more?'Показаны первые 8. Уточните слово после /.':'';
  }catch(error){if(current(v))v.message=error.message}
  finally{if(current(v)){v.loading=false;render()}}
 },180);
}
function sync(){if(view&&!current())close()}
function bind(actions){
 if(bound)return;const input=$('replyText'),form=$('composer');if(!input||!form||!document.createElement)return;
 bound=true;provider=actions;panel=document.createElement('div');panel.id='qrComposerPicker';panel.className='qrComposerPicker';panel.hidden=true;form.appendChild(panel);
 input.setAttribute('aria-autocomplete','list');input.setAttribute('aria-controls','qrComposerChoices');input.setAttribute('aria-expanded','false');
 input.addEventListener('input',update);input.addEventListener('click',update);
 input.addEventListener('select',()=>{if(view)sync()});
 input.addEventListener('compositionstart',()=>{composing=true;close()});input.addEventListener('compositionend',()=>{composing=false;update()});
 input.addEventListener('blur',close);
 input.addEventListener('keydown',event=>{
  if(event.isComposing||composing||!current())return;
  if(event.key==='Escape'){event.preventDefault();event.stopImmediatePropagation();close();return}
  if(event.key==='Tab'||event.ctrlKey||event.metaKey||event.altKey||event.shiftKey){close();return}
  if(['ArrowDown','ArrowUp'].includes(event.key)&&view.rows.length){event.preventDefault();event.stopImmediatePropagation();view.active=(view.active+(event.key==='ArrowDown'?1:-1)+view.rows.length)%view.rows.length;render();return}
  if(event.key==='Enter'&&view.rows.length){event.preventDefault();event.stopImmediatePropagation();void choose(view.active)}
 },true);
 input.addEventListener('keyup',event=>{if(['ArrowLeft','ArrowRight','Home','End'].includes(event.key))update()});
 panel.addEventListener('mousedown',event=>event.preventDefault());
 panel.addEventListener('click',event=>{const row=event.target.closest('[data-qr-composer-index]');if(row)void choose(Number(row.dataset.qrComposerIndex))});
 window.addEventListener('resize',schedulePosition,{passive:true});window.visualViewport?.addEventListener('resize',schedulePosition,{passive:true});window.visualViewport?.addEventListener('scroll',schedulePosition,{passive:true});
 if(window.ResizeObserver){const observer=new window.ResizeObserver(schedulePosition);observer.observe(input);observer.observe(form)}
 document.addEventListener('visibilitychange',()=>{if(document.visibilityState==='hidden')close()});
}
window.WorkspaceV2QuickInsert={bind,sync,close,token};
})();
