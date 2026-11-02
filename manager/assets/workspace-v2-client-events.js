(function(){
'use strict';
const W=window.WorkspaceV2,{S,$,esc}=W,endpoint='client-events-api.php';
let owner=0,generation=0,epoch=0,ticket=0,visibleId=0,openedId=0,dialog=null,opener=null,restoreConversation=0,flight=null;
let rows=[],history={has_more:false,cursor:''},loaded=false;
const labels={event:'Диалог',stage:'Этап',name:'Имя клиента',note:'Заметка',task:'Задача'};
function authenticated(){return owner>0&&!S.authExpired&&Number(S.manager?.id||0)===owner&&Number(S.authGeneration||0)===generation}
function context(){const id=Number(S.current||0),available=window.WorkspaceV2Conversation?.canNavigateMessages;return authenticated()&&id>0&&visibleId===id&&Number(S.detail?.conversation?.id)===id&&(!available||available())?id:0}
function valid(id,scope,requestTicket){return scope===epoch&&requestTicket===ticket&&context()===id&&openedId===id&&!!dialog?.open}
function time(value){return window.ManagerTime?.format(value,{year:true})||''}
function clear(){rows=[];history={has_more:false,cursor:''};loaded=false;if(dialog){$('ceList').innerHTML='';$('ceClient').textContent='';$('ceStatus').textContent='';$('ceOlder').hidden=true}}
function close(restore=true){restoreConversation=restore===true&&authenticated()&&context()===openedId?openedId:0;ticket++;openedId=0;flight=null;dialog?.close();clear()}
function suspend(){epoch++;close(false);visibleId=0;const button=$('ceOpen');if(button)button.hidden=true}
function reset(){suspend();owner=0}
function activate(managerId){const next=Number(managerId||0);if(next===owner&&generation===Number(S.authGeneration||0)&&authenticated())return;suspend();owner=next;generation=Number(S.authGeneration||0)}
function bind(){const existing=$('ceOpen');if(existing){window.Manager2Presentation?.registerConversationTool?.(existing);return}const anchor=document.querySelector('.conversationHead .actions');if(!anchor)return;const button=document.createElement('button');button.id='ceOpen';button.className='actionBtn';button.type='button';button.textContent='События';button.setAttribute('aria-label','Лента событий клиента');button.setAttribute('aria-haspopup','dialog');button.setAttribute('aria-controls','ceDialog');button.hidden=true;button.onclick=event=>open(event.currentTarget);anchor.appendChild(button);window.Manager2Presentation?.registerConversationTool?.(button)}
function enhanceCard(c){bind();visibleId=Number(c?.id||0);const available=context()>0,button=$('ceOpen');if(button)button.hidden=!available;if(dialog?.open&&(!available||openedId!==visibleId))close(false);if(dialog?.open&&available)$('ceClient').textContent=String(c.display_name||'Клиент')}
function ensureDialog(){
 if(dialog)return;
 dialog=document.createElement('dialog');dialog.id='ceDialog';dialog.className='ceDialog';dialog.setAttribute('aria-labelledby','ceTitle');
 dialog.innerHTML='<div class="ceHeader"><div><h2 id="ceTitle">События клиента</h2><p id="ceClient"></p></div><button id="ceClose" type="button">Закрыть</button></div><p class="ceScope">По открытому диалогу: зарегистрированные события, заметки и задачи. Переписка находится в чате.</p><div class="ceToolbar"><button id="ceRefresh" type="button">Обновить</button><span>Время — МСК</span></div><div id="ceStatus" class="ceStatus" role="status" aria-live="polite"></div><div class="ceScroll"><div id="ceList" class="ceList"></div><button id="ceOlder" type="button" hidden>Показать более ранние</button></div><p class="ceFootnote">Для заметок и задач показано текущее содержание. История всех их правок не сохраняется.</p>';
 document.body.appendChild(dialog);$('ceClose').onclick=()=>close();$('ceRefresh').onclick=()=>load(false);$('ceOlder').onclick=()=>load(true);
 dialog.addEventListener('cancel',event=>{event.preventDefault();close()});dialog.addEventListener('keydown',event=>event.stopPropagation());
 dialog.addEventListener('close',()=>{if(dialog.open||!restoreConversation||context()!==restoreConversation)return;restoreConversation=0;if(opener?.id==='ceOpen'&&window.Manager2Presentation?.focusConversationTool?.('ceOpen'))return;if(opener?.isConnected&&!opener.hidden&&!opener.disabled)opener.focus({preventScroll:true})});
}
async function open(source=document.activeElement){const id=context();if(!id)return false;ensureDialog();if(dialog.open&&openedId===id)return true;if(dialog.open)close(false);clear();restoreConversation=0;opener=source;openedId=id;$('ceClient').textContent=String(S.detail.conversation.display_name||'Клиент');dialog.showModal();render();await load(false);return true}
function status(text='',error=false){$('ceStatus').textContent=text;$('ceStatus').classList.toggle('error',error)}
function busy(value){dialog.setAttribute('aria-busy',value?'true':'false');$('ceRefresh').disabled=value;$('ceOlder').disabled=value}
function render(){
 const list=$('ceList');
 list.innerHTML=rows.length?rows.map(item=>{
  const details=Array.isArray(item.details)?item.details:[];
  const content=details.map(detail=>'<div class="ceDetail"><span>'+esc(detail.label||'')+'</span><p>'+esc(detail.value||'')+'</p></div>').join('');
  const updated=item.current_record&&item.updated_at&&item.updated_at!==item.created_at?'<p class="ceUpdated">Текущая запись изменена '+esc(time(item.updated_at))+' · МСК</p>':'';
  const due=item.due_at?'<p class="ceUpdated">Текущий срок: '+esc(time(item.due_at))+' · МСК</p>':'';
  return '<article class="ceEvent" data-ce-key="'+esc(String(item.key||'')).replace(/"/g,'&quot;')+'"><div class="ceEventMeta"><span class="ceKind">'+esc(labels[item.kind]||'Событие')+'</span><time>'+esc(time(item.created_at))+'</time></div><h3>'+esc(item.title||'Событие')+'</h3><p class="ceActor">'+esc(item.actor||'Система')+'</p>'+(item.current_record?'<details class="ceCurrent"><summary>Текущее содержание</summary>'+content+updated+due+'</details>':content)+'</article>';
 }).join(''):'<p class="ceEmpty">'+(loaded?'Для этого диалога пока нет зарегистрированных событий.':'События ещё не загружены.')+'</p>';
 $('ceOlder').hidden=!loaded||!history.has_more;
}
async function load(older=false){
 const id=openedId,scope=epoch;if(!id||context()!==id||!dialog?.open)return false;
 if(flight)return flight.promise;if(older&&(!loaded||!history.has_more||!history.cursor))return false;
 const requestTicket=++ticket,cursor=older?history.cursor:'',requestFocus=document.activeElement,currentFlight={};flight=currentFlight;
 busy(true);status(older?'Загружаем более ранние события…':'Загружаем события…');
 currentFlight.promise=(async()=>{
  let result;try{result=await W.request(endpoint,'list',{conversation_id:id,cursor,page_size:30},{isCurrent:()=>valid(id,scope,requestTicket)})}catch(error){result={ok:false,error:'network_error'}}
  if(!valid(id,scope,requestTicket))return false;
  if(!result.ok||Number(result.conversation_id)!==id||!Array.isArray(result.items)||!result.history||typeof result.history.has_more!=='boolean'||typeof result.history.cursor!=='string'){
   if(['forbidden','unauthorized','not_found'].includes(result.error)||[401,403,404].includes(Number(result.http_status)))clear();
   status(result.error==='forbidden'||result.error==='not_found'?'Доступ к событиям этого диалога не подтверждён.':result.error==='unauthorized'?'Войдите снова, чтобы посмотреть события.':'Не удалось загрузить события. Нажмите «Обновить» и попробуйте снова.',true);render();return false;
  }
  if(older){const keys=new Set(rows.map(item=>item.key));rows=rows.concat(result.items.filter(item=>!keys.has(item.key)))}else rows=result.items;
  history=result.history;loaded=true;status();render();if(!older)$('ceList').parentElement?.scrollTo?.({top:0});return true;
 })().finally(()=>{if(flight===currentFlight)flight=null;if(valid(id,scope,requestTicket)){busy(false);if((!document.activeElement||document.activeElement===document.body)&&requestFocus?.isConnected&&!requestFocus.disabled&&!requestFocus.hidden)requestFocus.focus({preventScroll:true})}});
 return currentFlight.promise;
}
window.WorkspaceV2ClientEvents={bind,activate,suspend,reset,enhanceCard,open,close,load};
})();
