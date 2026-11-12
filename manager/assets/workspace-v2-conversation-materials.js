(function(){
'use strict';
const W=window.WorkspaceV2;if(!W)return;const {S,$,api}=W;
let bound=false,dialog=null,scope='',revision=0,busy=false,failed=false,kind='all',snapshot=0,before=0,hasMore=true,total=0;
const key=()=>JSON.stringify(window.WorkspaceV2Conversation?.captureReplyContext?.()||null);
const canOpen=()=>!!S.manager?.id&&!S.authExpired&&window.WorkspaceV2Conversation?.canNavigateMessages?.()===true;
const current=(captured,ticket)=>dialog?.open&&scope===captured&&key()===captured&&ticket===revision&&!S.authExpired&&window.WorkspaceV2Conversation?.hasReadAccess?.()===true;
function clear(){revision++;busy=false;failed=false;snapshot=0;before=0;hasMore=true;total=0;$('conversationMaterialsList')?.replaceChildren()}
function close({focus=true}={}){
 const previous=scope;scope='';clear();if(dialog?.open)dialog.close();
 $('conversationMaterialsOpen')?.setAttribute('aria-expanded','false');
 if(focus&&previous===key()&&canOpen()&&!window.Manager2Presentation?.focusConversationTool?.('conversationMaterialsOpen'))$('conversationMaterialsOpen')?.focus({preventScroll:true});
}
function reset(){close({focus:false})}
function syncContext(){
 if(scope&&(scope!==key()||S.authExpired||window.WorkspaceV2Conversation?.hasReadAccess?.()!==true))reset();
 const button=$('conversationMaterialsOpen');if(button)button.disabled=!canOpen();
}
function status(){
 const text=$('conversationMaterialsStatus'),more=$('conversationMaterialsMore');if(!text||!more)return;
 text.textContent=busy?'Загружаем материалы…':failed?'Не удалось загрузить материалы. Можно повторить.':total?
   `Показано сообщений с материалами: ${total}. ${hasMore?'Можно просмотреть более ранние.':'Сохранённая история просмотрена.'}`:
   hasMore?'В просмотренной части истории материалов пока нет. Можно просмотреть более ранние сообщения.':'Подходящих материалов в сохранённой переписке нет.';
 more.hidden=!hasMore&&!failed;more.disabled=busy;more.textContent=failed?'Повторить':'Более ранние сообщения';
 $('conversationMaterialsList').setAttribute('aria-busy',busy?'true':'false');
}
function validLink(value){try{const u=new URL(value);return typeof value==='string'&&value.length<=2048&&['https:','http:'].includes(u.protocol)&&!u.username&&!u.password}catch(e){return false}}
function validResponse(r,cursor){
 if(r?.ok!==true||Number(r.conversation_id)!==Number(S.current)||r.kind!==kind||!Array.isArray(r.items)||r.items.length>25||typeof r.has_more!=='boolean')return false;
 const ceiling=Number(r.snapshot_id),next=Number(r.before_id),scanned=r.scanned;
 if(!Number.isSafeInteger(ceiling)||ceiling<0||!Number.isSafeInteger(next)||next<0||!Number.isSafeInteger(scanned)||scanned<0||scanned>250||r.items.length>scanned||(snapshot&&ceiling!==snapshot)||next>ceiling||(cursor&&next>=cursor&&scanned>0)||(r.has_more&&(!scanned||!next))||(scanned===0&&next!==0))return false;
 return r.items.every((row,i)=>row&&typeof row==='object'&&Number.isSafeInteger(row.message_id)&&row.message_id>0&&row.message_id>=next&&row.message_id<=ceiling&&(!cursor||row.message_id<cursor)&&(!i||row.message_id<r.items[i-1].message_id)
  &&typeof row.sender_type==='string'&&typeof row.created_at==='string'&&typeof row.snippet==='string'&&Array.from(row.snippet).length<=240
  &&Array.isArray(row.kinds)&&row.kinds.length>0&&row.kinds.every(k=>['files','links','offers'].includes(k))&&(kind==='all'||row.kinds.includes(kind))
  &&Array.isArray(row.files)&&row.files.length<=8&&row.files.every(f=>f&&['image','video','audio','file'].includes(f.type)&&typeof f.name==='string')
  &&Array.isArray(row.links)&&row.links.length<=8&&row.links.every(validLink)
  &&Number.isSafeInteger(row.file_count)&&row.file_count>=row.files.length&&Number.isSafeInteger(row.link_count)&&row.link_count>=row.links.length);
}
function item(row){
 const node=document.createElement('li');node.className='conversationMaterial';node.dataset.materialMessageId=String(row.message_id);
 const meta=document.createElement('div');meta.className='conversationMaterialMeta';
 const sender={customer:'Клиент',manager:'Менеджер',ai:'Бот'}[row.sender_type]||'Система';
 meta.textContent=sender+' · '+window.ManagerTime.format(row.created_at,{year:true})+' МСК';node.appendChild(meta);
 if(row.kinds.includes('offers')){const offer=document.createElement('strong');offer.textContent='Отправленное предложение';node.appendChild(offer)}
 if(row.snippet){const snippet=document.createElement('p');snippet.className='conversationMaterialSnippet';snippet.textContent=row.snippet;node.appendChild(snippet)}
 const files=document.createElement('ul');files.className='conversationMaterialFiles';
 for(const file of row.files){const line=document.createElement('li');line.textContent=file.name||({image:'Фото',video:'Видео',audio:'Аудио',file:'Файл'}[file.type]);files.appendChild(line)}
 if(row.file_count>row.files.length){const line=document.createElement('li');line.textContent=`Ещё файлов в исходном сообщении: ${row.file_count-row.files.length}`;files.appendChild(line)}
 if(row.file_count)node.appendChild(files);
 for(const value of row.links){const link=document.createElement('a');link.href=value;link.textContent=value;link.target='_blank';link.rel='noopener noreferrer';link.className='conversationMaterialLink';node.appendChild(link)}
 if(row.link_count>row.links.length){const note=document.createElement('p');note.textContent='Другие ссылки — в исходном сообщении.';node.appendChild(note)}
 const source=document.createElement('button');source.type='button';source.className='actionBtn';source.textContent='К сообщению';source.setAttribute('aria-label','Открыть исходное сообщение '+row.message_id);
 source.onclick=()=>{const captured=scope,ticket=revision;void window.WorkspaceV2MessageNavigation?.openSearchTarget?.(row.message_id,source,{isCurrent:()=>current(captured,ticket),returnLabel:'Вернуться к материалам'})};node.appendChild(source);return node;
}
async function load(){
 if(busy||!dialog?.open||!scope||scope!==key()||!hasMore)return false;
 const captured=scope,ticket=++revision,cursor=before;busy=true;failed=false;status();let result;
 try{result=await api('message_materials',{conversation_id:Number(S.current),kind,before_id:cursor,snapshot_id:snapshot},{isCurrent:()=>current(captured,ticket)})}catch(e){result=null}
 if(!current(captured,ticket))return false;
 busy=false;
 if([403,404].includes(result?.http_status)){reset();window.WorkspaceV2Conversation?.invalidateMessageAccess?.();return false}
 if(!validResponse(result,cursor)){failed=true;status();return false}
 snapshot=Number(result.snapshot_id);before=Number(result.before_id);hasMore=result.has_more;
 for(const row of result.items)$('conversationMaterialsList').appendChild(item(row));total+=result.items.length;status();return true;
}
function restart(){if(!dialog?.open)return;clear();$('conversationMaterialsList').scrollTop=0;void load()}
function ensureDialog(){
 if(dialog)return;
 dialog=document.createElement('dialog');dialog.id='conversationMaterialsDialog';dialog.className='conversationMaterialsDialog';dialog.setAttribute('aria-labelledby','conversationMaterialsTitle');
 const head=document.createElement('div');head.className='conversationMaterialsHead';const title=document.createElement('strong');title.id='conversationMaterialsTitle';title.textContent='Материалы диалога';
 const exit=document.createElement('button');exit.type='button';exit.className='actionBtn';exit.textContent='Закрыть';exit.id='conversationMaterialsClose';exit.onclick=()=>close();head.append(title,exit);
 const tools=document.createElement('div');tools.className='conversationMaterialsTools';const label=document.createElement('label');label.htmlFor='conversationMaterialsKind';label.textContent='Показать';
 const filter=document.createElement('select');filter.id='conversationMaterialsKind';for(const [value,text]of[['all','Всё'],['files','Файлы'],['links','Ссылки'],['offers','Предложения']]){const option=document.createElement('option');option.value=value;option.textContent=text;filter.appendChild(option)}
 filter.onchange=()=>{kind=filter.value;restart()};const refresh=document.createElement('button');refresh.id='conversationMaterialsRefresh';refresh.type='button';refresh.className='actionBtn';refresh.textContent='Обновить';refresh.onclick=restart;tools.append(label,filter,refresh);
 const description=document.createElement('p');description.className='conversationMaterialsDescription';description.textContent='Файлы, ссылки и отправленные предложения из сохранённой переписки. «К сообщению» открывает оригинал.';
 const list=document.createElement('ul');list.id='conversationMaterialsList';list.className='conversationMaterialsList';list.setAttribute('aria-label','Сообщения с материалами');
 const footer=document.createElement('div');footer.className='conversationMaterialsFooter';const message=document.createElement('div');message.id='conversationMaterialsStatus';message.setAttribute('role','status');message.setAttribute('aria-live','polite');const more=document.createElement('button');more.id='conversationMaterialsMore';more.type='button';more.className='actionBtn';more.onclick=()=>void load();footer.append(message,more);
 dialog.append(head,tools,description,list,footer);document.body.appendChild(dialog);
 dialog.addEventListener('close',()=>{if(!dialog.open&&scope)close()});
}
function open(){
 if(!canOpen())return false;ensureDialog();clear();scope=key();kind='all';$('conversationMaterialsKind').value=kind;
 $('conversationMaterialsOpen')?.setAttribute('aria-expanded','true');dialog.showModal();void load();return true;
}
function bind(){
 if(bound)return;bound=true;ensureDialog();const button=document.createElement('button');button.id='conversationMaterialsOpen';button.type='button';button.className='actionBtn';button.textContent='Материалы диалога';button.setAttribute('aria-haspopup','dialog');button.setAttribute('aria-expanded','false');button.setAttribute('aria-controls','conversationMaterialsDialog');button.onclick=open;
 if(!window.Manager2Presentation?.registerConversationTool?.(button))document.querySelector('.conversationTitleRow')?.appendChild(button);syncContext();
}
window.WorkspaceV2ConversationMaterials={bind,open,close,reset,syncContext,isOpen:()=>!!dialog?.open};
})();
