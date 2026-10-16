const assert=require('node:assert/strict');
const fs=require('node:fs');
const path=require('node:path');
const vm=require('node:vm');
const source=fs.readFileSync(path.join(__dirname,'../manager/assets/workspace-v2-quick-replies.js'),'utf8');
function deferred(){let resolve;const promise=new Promise(r=>resolve=r);return {promise,resolve}}
function harness(handler){
  const nodes=new Map(),values=new Map(),calls=[];
  let active=null;
  function element(id=''){
    const listeners=new Map(),attributes=new Map();
    const node={id,value:'',selectionStart:0,selectionEnd:0,selectionDirection:'none',maxLength:8000,innerMarkup:'',textContent:'',className:'',hidden:false,disabled:false,isConnected:true,open:false,children:[],dataset:{},parentElement:null,
      classList:{add(){},remove(){},toggle(){}},
      addEventListener(type,callback){listeners.set(type,callback)},emit(type,event={}){return listeners.get(type)?.(event)},
      setAttribute(key,value){attributes.set(key,String(value))},getAttribute:key=>attributes.get(key),
      focus(){active=node},showModal(){node.open=true},close(){node.open=false;node.emit('close')},remove(){node.isConnected=false},
      setSelectionRange(start,end,direction='none'){node.selectionStart=start;node.selectionEnd=end;node.selectionDirection=direction},
      setRangeText(text,start,end){node.value=node.value.slice(0,start)+text+node.value.slice(end);node.selectionStart=node.selectionEnd=start+text.length},
      querySelectorAll(selector){return selector==='[data-reply]'?node.children.filter(child=>child.dataset.reply):[]},querySelector(){return null},insertAdjacentHTML(where,html){node.innerMarkup+=html},
      insertBefore(child,before){child.parentElement=node;node.children.splice(Math.max(0,node.children.indexOf(before)),0,child);nodes.set(child.id,child)},replaceChildren(){node.children=[];node.innerMarkup=''},
      get innerHTML(){return node.innerMarkup},set innerHTML(html){
        node.innerMarkup=html;
        for(const match of html.matchAll(/id="([^"]+)"/g)){const child=element(match[1]);nodes.set(match[1],child)}
        for(const match of html.matchAll(/<textarea[^>]*id="([^"]+)"[^>]*>([\s\S]*?)<\/textarea>/g)){nodes.get(match[1]).value=match[2].replace(/&lt;/g,'<').replace(/&gt;/g,'>').replace(/&amp;/g,'&')}
      },
    };
    return node;
  }
  const trigger=element('quickRepliesOpen'),toolbar=element('quickReplies'),defaultReply=element('fixedDefault');defaultReply.dataset.reply='Стандартный ответ';trigger.parentElement=toolbar;toolbar.children=[trigger,defaultReply];
  nodes.set('quickRepliesOpen',trigger);nodes.set('quickReplies',toolbar);nodes.set('fixedDefault',defaultReply);nodes.set('replyText',element('replyText'));
  const S={current:101,manager:{id:5,role:'manager',display_name:'Анна'},authExpired:false,authGeneration:1,openGeneration:1,detail:{conversation:{id:101,display_name:'Павел'}}};
  const W={S,$:id=>nodes.get(id)||null,esc:value=>String(value??'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;'),
    request:async(endpoint,action,data)=>{calls.push({endpoint,action,data});return handler(action,data,calls)}
  };
  const document={createElement:()=>element(),body:{appendChild(node){nodes.set(node.id,node)}},get activeElement(){return active}};
  const window={WorkspaceV2:W,crypto:require('node:crypto').webcrypto,confirm:()=>true,
    WorkspaceV2Conversation:{captureReplyContext:()=>({conversationId:S.current,managerId:S.manager.id,authGeneration:S.authGeneration,openGeneration:S.openGeneration}),insertQuickReply:()=>true}
  };
  const context=vm.createContext({window,document,sessionStorage:{getItem:key=>values.get(key)||null,setItem:(key,value)=>values.set(key,value),removeItem:key=>values.delete(key)},Uint8Array,setTimeout,clearTimeout,console});
  vm.runInContext(source,context);
  return {api:window.WorkspaceV2QuickReplies,S,nodes,values,calls,window};
}
const reply={id:7,title:'Приветствие',body:'Здравствуйте, {client_name}! Я {manager_name}.',category:'Знакомство',scope:'personal',version:1,is_favorite:false,can_edit:true};
const list={ok:true,replies:[reply],categories:['Знакомство'],has_more:false,next_offset:null};
const second={...reply,id:8,title:'Даты и состав',body:'Уточните даты и состав туристов.',is_favorite:true};
const state=(replies,version='version-a')=>({ok:true,replies,version,max_replies:100});
function toolbarClick(h,id){h.nodes.get('quickRepliesSelected').emit('click',{target:{closest:selector=>selector==='[data-qr-toolbar-id]'?{dataset:{qrToolbarId:String(id)}}:null}})}
function move(h,id,direction){h.nodes.get('qrList').emit('click',{target:{closest:selector=>selector==='[data-qr-move]'?{dataset:{qrMoveId:String(id),qrMove:direction}}:null}})}
function select(h){h.nodes.get('qrList').emit('click',{target:{closest:selector=>selector==='[data-qr-select]'?{dataset:{qrSelect:'7'}}:null}})}
function enterEditor(h,title='Приветствие',body='Текст, который важно сохранить'){
  h.nodes.get('qrCreate').onclick();
  for(const [id,value] of [['qrTitle',title],['qrBody',body],['qrEditCategory','Знакомство']]){h.nodes.get(id).value=value;h.nodes.get(id).emit('input')}
  h.nodes.get('qrEditScope').value='personal';h.nodes.get('qrEditScope').emit('change');
}
const tick=()=>new Promise(resolve=>setTimeout(resolve,0));
(async()=>{
  const lazy=harness(()=>list);
  assert.equal(lazy.calls.length,0,'loading module never loads the library or blocks Inbox');
  await lazy.api.open();assert.equal(lazy.calls.length,1);await lazy.api.open();assert.equal(lazy.calls.length,1,'opening the already visible dialog does not duplicate the list');
  assert.equal(lazy.api.resolveText('{{manager_name}} / {client_name} / {unknown}',{manager_name:'Анна',client_name:'Павел',unknown:'must not resolve'}),'Анна / Павел / {unknown}');
  assert.equal(lazy.api.resolveText('{client_name}',{client_name:''}),'{client_name}','missing values never silently become empty');
  select(lazy);
  assert.equal(lazy.nodes.get('qrInsert').disabled,false);
  lazy.nodes.get('qrPreview').value='Неизвестно {budget}';lazy.nodes.get('qrPreview').emit('input');assert.equal(lazy.nodes.get('qrInsert').disabled,true);
  let inserted='';lazy.window.WorkspaceV2Conversation.insertQuickReply=text=>{inserted=text;return true};
  assert.equal(lazy.nodes.get('qrInsert').onclick(),false);assert.equal(inserted,'');
  lazy.nodes.get('qrPreview').value='Исправленный текст';lazy.nodes.get('qrPreview').emit('input');assert.equal(lazy.nodes.get('qrInsert').onclick(),true);assert.equal(inserted,'Исправленный текст');
  console.log('PASS lazy loading, insertion context and explicit placeholder resolution');

  const favorite=harness(action=>action==='favorite'?{ok:true,reply:{...reply,is_favorite:true}}:list);
  await favorite.api.open();select(favorite);
  favorite.nodes.get('qrPreview').value='Дополненный текст для конкретного клиента';favorite.nodes.get('qrPreview').emit('input');
  await favorite.nodes.get('qrFavorite').onclick();
  assert.match(favorite.nodes.get('qrDetail').innerHTML,/Дополненный текст для конкретного клиента/,'favorite changes retain the edited preview');
  console.log('PASS favorite update retains edited preview');

  const oldRead=deferred();let listCalls=0;
  const search=harness(action=>action==='list'?(++listCalls===1?oldRead.promise:{...list,replies:[{...reply,title:'Результат нового поиска'}]}):{ok:false});
  const opening=search.api.open();search.nodes.get('qrSearch').value='новый';search.nodes.get('qrSearch').emit('input');
  oldRead.resolve(list);await opening;assert.doesNotMatch(search.nodes.get('qrList').innerHTML,/Приветствие/,'old result invalidated before debounce fires');
  await new Promise(resolve=>setTimeout(resolve,250));assert.match(search.nodes.get('qrList').innerHTML,/Результат нового поиска/);assert.equal(search.calls.length,2);
  console.log('PASS delayed search result cannot overwrite a new query');

  let created=0;
  const retained=harness(action=>{
    if(action==='list')return created?{ok:false,error:'storage_unavailable',http_status:503}:list;
    if(action==='create'){created++;return {ok:false,error:'network_error'}}
    if(action==='operation')return {ok:true,found:true,result:{ok:true,reply:{...reply,id:9}}};
    throw Error(action);
  });
  await retained.api.open();enterEditor(retained);retained.nodes.get('qrEditor').emit('submit',{preventDefault(){}});await tick();
  const draft=JSON.parse(retained.values.get('workspaceV2.quickReplyDraft.5'));
  assert.equal(draft.editor.body,'Текст, который важно сохранить');assert.ok(draft.pending.operation_id);assert.equal(retained.nodes.get('qrSave').disabled,true);
  await retained.nodes.get('qrResolve').onclick();await tick();
  assert.equal(created,1,'receipt reconciliation must not resend a completed create');assert.equal(retained.values.has('workspaceV2.quickReplyDraft.5'),false);
  assert.match(retained.nodes.get('qrStatus').textContent,/^Ответ создан\./,'failed follow-up read cannot replace confirmed success');
  assert.match(retained.nodes.get('qrList').innerHTML,/Не удалось загрузить ответы/);
  console.log('PASS lost response is reconciled without duplicate write and follow-up failure preserves success truth');

  let createAttempt=0;
  const missing=harness((action,data)=>{
    if(action==='list')return list;
    if(action==='operation')return {ok:true,found:false};
    if(action==='create'){createAttempt++;return createAttempt===1?{ok:false,error:'invalid_response'}:{ok:true,reply}}
    throw Error(action);
  });
  await missing.api.open();enterEditor(missing);missing.nodes.get('qrEditor').emit('submit',{preventDefault(){}});await tick();
  await missing.nodes.get('qrResolve').onclick();
  const writes=missing.calls.filter(call=>call.action==='create');assert.equal(writes.length,2);assert.deepEqual(writes[0].data,writes[1].data,'checked missing receipt retries identical payload and key');
  assert.equal(missing.calls.filter(call=>call.action==='operation').length,1);
  console.log('PASS missing receipt is checked before a same-key same-payload retry');

  const staleCreate=deferred();
  const account=harness(action=>action==='list'?list:staleCreate.promise);
  await account.api.open();enterEditor(account,'Личное имя','Личный текст Анны');account.nodes.get('qrEditor').emit('submit',{preventDefault(){}});await tick();
  account.S.authGeneration++;account.api.resetSession();account.S.manager={id:6,display_name:'Светлана',role:'manager'};
  await account.api.open();staleCreate.resolve({ok:true,reply:{...reply,body:'private A'}});await tick();
  assert.doesNotMatch(account.nodes.get('qrDetail').innerHTML,/private A|Личный текст Анны/);assert.equal(account.values.has('workspaceV2.quickReplyDraft.6'),false);
  assert.equal(JSON.parse(account.values.get('workspaceV2.quickReplyDraft.5')).pending.action,'create','stale success never discards previous account unknown receipt');
  console.log('PASS account switch hides old draft and discards late mutation response while retaining reconciliation');

  const toolbar=harness(action=>action==='toolbar'?state([second,{...reply,is_favorite:true}]):list);
  toolbar.api.configure();await tick();toolbar.api.configure();await tick();
  assert.equal(toolbar.calls.filter(call=>call.action==='toolbar').length,1,'same authenticated lifecycle reads panel once and never reads Inbox');
  assert.equal(toolbar.nodes.get('fixedDefault').isConnected,false,'fixed defaults leave toolbar even before personal selection');
  assert.equal(toolbar.nodes.get('quickRepliesOpen').textContent,'Настроить ответы');
  assert.ok(toolbar.nodes.get('quickRepliesSelected').innerHTML.indexOf('Даты и состав')<toolbar.nodes.get('quickRepliesSelected').innerHTML.indexOf('Приветствие'));
  let direct=[];toolbar.nodes.get('replyText').value='Уже набранный текст';
  toolbar.window.WorkspaceV2Conversation.insertQuickReply=(text,ctx)=>{direct.push({text,ctx});toolbar.nodes.get('replyText').value+='\n'+text;return true};
  toolbar.S.current=102;toolbar.S.detail.conversation.id=102;toolbar.S.detail.conversation.display_name='Татьяна';toolbarClick(toolbar,7);
  assert.equal(direct[0].text,'Здравствуйте, Татьяна! Я Анна.');assert.equal(direct[0].ctx.conversationId,102,'quick button captures current conversation at click');
  assert.equal(toolbar.nodes.get('replyText').value,'Уже набранный текст\nЗдравствуйте, Татьяна! Я Анна.');
  toolbar.S.detail.conversation.display_name='Турист';toolbarClick(toolbar,7);
  assert.equal(direct.length,1,'unresolved customer name never inserts a raw token');assert.equal(toolbar.nodes.get('quickRepliesDialog').open,true);assert.equal(toolbar.nodes.get('qrInsert').disabled,true);
  assert.equal(toolbar.calls.filter(call=>!['toolbar','list'].includes(call.action)).length,0,'toolbar insertion never sends or writes a template');
  console.log('PASS persisted own order, nonblocking one-time loading and direct guarded insertion with unresolved-name preview');

  let currentPanel=state([{...reply,is_favorite:true},second]);
  const lostOrder=harness(action=>{
    if(action==='toolbar')return currentPanel;
    if(action==='list')return list;
    if(action==='reorder')return {ok:false,error:'network_error'};
    if(action==='operation')return {ok:true,found:true,result:{ok:true,toolbar:state([second,{...reply,is_favorite:true}],'old-receipt')}};
    throw Error(action);
  });
  lostOrder.api.configure();await tick();await lostOrder.api.open({panel:true});move(lostOrder,7,'down');await tick();
  const orderWrite=lostOrder.calls.find(call=>call.action==='reorder');assert.deepEqual([...orderWrite.data.ids],[8,7]);assert.equal(orderWrite.data.toolbar_version,'version-a');
  assert.ok(lostOrder.nodes.get('quickRepliesSelected').innerHTML.indexOf('Приветствие')<lostOrder.nodes.get('quickRepliesSelected').innerHTML.indexOf('Даты и состав'),'unconfirmed reordering keeps last confirmed composer order');
  assert.equal(JSON.parse(lostOrder.values.get('workspaceV2.quickReplyDraft.5')).pending.action,'reorder');
  const readsBeforeReceipt=lostOrder.calls.filter(call=>call.action==='toolbar').length;
  currentPanel=state([second],'another-device');await lostOrder.nodes.get('qrResolve').onclick();await tick();
  assert.equal(lostOrder.calls.filter(call=>call.action==='reorder').length,1,'receipt recovery never blindly repeats reordering');
  assert.equal(lostOrder.calls.filter(call=>call.action==='toolbar').length,readsBeforeReceipt+1,'recovered receipt refreshes canonical current state before displaying buttons');
  assert.doesNotMatch(lostOrder.nodes.get('quickRepliesSelected').innerHTML,/Приветствие/,'old receipt snapshot never restores a later removed choice');
  assert.equal(lostOrder.values.has('workspaceV2.quickReplyDraft.5'),false);
  console.log('PASS unknown reorder preserves confirmed chips and reconciles current account state instead of an obsolete receipt');

  const conflict=harness(action=>action==='toolbar'?state([{...reply,is_favorite:true},second]):action==='reorder'?{ok:false,error:'conflict',toolbar:state([second,{...reply,is_favorite:true}],'newer')}:list);
  conflict.api.configure();await tick();await conflict.api.open({panel:true});move(conflict,7,'down');await tick();
  assert.match(conflict.nodes.get('qrStatus').textContent,/другом устройстве/);assert.equal(conflict.values.has('workspaceV2.quickReplyDraft.5'),false);
  assert.ok(conflict.nodes.get('quickRepliesSelected').innerHTML.indexOf('Даты и состав')<conflict.nodes.get('quickRepliesSelected').innerHTML.indexOf('Приветствие'),'version conflict displays canonical server order');
  console.log('PASS explicit order conflict never claims a successful save');

  const oldToolbar=deferred();let toolbarReads=0;
  const accountPanel=harness(action=>action==='toolbar'?(++toolbarReads===1?oldToolbar.promise:state([{...second,title:'Ответ Светланы'}],'owner-six')):list);
  accountPanel.api.configure();accountPanel.S.authGeneration++;accountPanel.api.resetSession();accountPanel.S.manager={id:6,display_name:'Светлана',role:'manager'};accountPanel.api.configure();await tick();
  oldToolbar.resolve(state([{...reply,title:'Личный ответ Анны'}]));await tick();
  assert.match(accountPanel.nodes.get('quickRepliesSelected').innerHTML,/Ответ Светланы/);assert.doesNotMatch(accountPanel.nodes.get('quickRepliesSelected').innerHTML,/Личный ответ Анны/);
  console.log('PASS late toolbar response never exposes the previous account panel');

  let savedPanel=state([{...reply,is_favorite:true},second]);
  const reopen=harness(action=>action==='toolbar'?savedPanel:list);
  reopen.api.configure();await tick();await reopen.api.open({panel:true});reopen.api.close();
  const beforeReopen=reopen.calls.filter(call=>call.action==='toolbar').length;
  savedPanel=state([{...second,title:'Изменённый ответ',body:'Новый текст от другого устройства.'}],'canonical-current');
  await reopen.api.open({panel:true});
  assert.equal(reopen.calls.filter(call=>call.action==='toolbar').length,beforeReopen+1,'explicit settings reopen refreshes once and coalesces panel load');
  assert.match(reopen.nodes.get('quickRepliesSelected').innerHTML,/Изменённый ответ/);assert.doesNotMatch(reopen.nodes.get('quickRepliesSelected').innerHTML,/Приветствие/);
  let refreshedText='';reopen.window.WorkspaceV2Conversation.insertQuickReply=text=>{refreshedText=text;return true};reopen.api.close();toolbarClick(reopen,7);assert.equal(refreshedText,'','removed toolbar choice can no longer be inserted');toolbarClick(reopen,8);assert.equal(refreshedText,'Новый текст от другого устройства.');
  console.log('PASS explicit settings reopen sees updated/removed choices from another device without polling');

  const trip={city:'Москва',country:'Турция',date:'29.02.2028',nights:'7-9',adults:2,children:0,budget:{max:250000,currency:'RUB',basis:'total'}};
  const tripBody='Вылет {trip_city}, {trip_country}, {trip_date}, {trip_nights} ночей; {trip_adults} взрослых, {trip_children} детей; {trip_budget}.';
  const tripReply={...reply,title:'Параметры поездки',body:tripBody,is_favorite:true};
  const expected='Вылет Москва, Турция, 29.02.2028, 7-9 ночей; 2 взрослых, 0 детей; до '+new Intl.NumberFormat('ru-RU').format(250000)+' RUB за всех.';
  const typed=harness(()=>({...list,replies:[tripReply]}));
  assert.equal(typed.api.resolveText(tripBody,typed.api.tripValues(trip)),expected);
  const perPerson=typed.api.tripValues({...trip,budget:{max:'125000.50',currency:'EUR',basis:'per_person'}}).trip_budget;
  assert.equal(perPerson,'до '+new Intl.NumberFormat('ru-RU',{maximumFractionDigits:2}).format(125000.5)+' EUR на человека');
  for(const budget of [{max:10,basis:'total'},{max:10,currency:'RUB'},{max:10,currency:'rub',basis:'total'},{max:true,currency:'RUB',basis:'total'},{max:[250000],currency:'RUB',basis:'total'},{max:'1e5',currency:'RUB',basis:'total'},{max:10,currency:'RUB',basis:'unknown'},{max:Infinity,currency:'RUB',basis:'total'},{max:0,currency:'RUB',basis:'total'},{max:1.234,currency:'RUB',basis:'total'}])assert.equal(typed.api.tripValues({budget}).trip_budget,undefined);
  for(const date of ['29.02.2027','31.04.2026','01.13.2026','01.01','завтра','2026-10-16','01.01.0000'])assert.equal(typed.api.tripValues({date}).trip_date,undefined);
  for(const value of [null,false,'0',NaN,undefined,-1])assert.equal(typed.api.tripValues({children:value}).trip_children,undefined);
  for(const nights of ['0','9-7','07','7-','7.5',7])assert.equal(typed.api.tripValues({nights}).trip_nights,undefined);
  assert.equal(typed.api.tripValues({adults:0,city:42,country:{name:'Турция'}}).trip_adults,undefined);
  await typed.api.open();select(typed);typed.nodes.get('qrPreview').value='{constructor} / {toString} / {__proto__}';typed.nodes.get('qrPreview').emit('input');
  assert.equal(typed.nodes.get('qrPlaceholderStatus').textContent,'Замените подсказки в тексте: constructor, toString, __proto__');assert.equal(typed.nodes.get('qrInsert').disabled,true);
  console.log('PASS typed trip values preserve calendar, ranges, zero children and explicit budget units without inference');

  const tripPanel=harness(action=>action==='toolbar'?state([tripReply]):{...list,replies:[tripReply]});
  tripPanel.S.detail={conversation:{id:101,display_name:'Внутренний псевдоним',original_display_name:'Павел'},lead:{ok:true,trip},trip_summary:'Старая Греция, 1990 год'};
  let tripInserted=[];tripPanel.window.WorkspaceV2Conversation.insertQuickReply=(text,ctx)=>{tripInserted.push({text,ctx});return true};
  tripPanel.api.configure();await tick();toolbarClick(tripPanel,7);assert.equal(tripInserted[0].text,expected);
  const reads=tripPanel.calls.length;
  tripPanel.S.detail.lead=null;toolbarClick(tripPanel,7);assert.equal(tripInserted.length,1);assert.equal(tripPanel.nodes.get('qrInsert').disabled,true);
  const previewNode=tripPanel.nodes.get('qrPreview');previewNode.value='Моя правка | '+tripBody+' | конец';previewNode.emit('input');
  previewNode.setSelectionRange(previewNode.value.length-5,previewNode.value.length,'backward');
  tripPanel.S.detail.lead={ok:true,trip};assert.equal(previewNode.value,'Моя правка | '+tripBody+' | конец','late card never changes edited preview automatically');
  tripPanel.nodes.get('qrFillKnown').onclick();assert.equal(previewNode.value,'Моя правка | '+expected+' | конец');
  assert.equal(previewNode.selectionStart,previewNode.value.length-5);assert.equal(previewNode.selectionEnd,previewNode.value.length);assert.equal(previewNode.selectionDirection,'backward');
  assert.equal(tripPanel.calls.length,reads,'explicit fill performs no extra HTTP, QR write or send');
  tripPanel.S.detail.lead={ok:true,trip:{...trip,country:'Кипр'}};assert.equal(previewNode.value,'Моя правка | '+expected+' | конец','new known card values cannot replace already-resolved preview text');
  assert.equal(tripPanel.nodes.get('qrInsert').onclick(),true);assert.equal(tripInserted.at(-1).text,previewNode.value);
  assert.equal(tripPanel.values.size,0,'resolved client values are never saved as template/editor drafts');
  await tripPanel.api.open();select(tripPanel);assert.equal(tripPanel.nodes.get('qrPreview').value,expected.replace('Турция','Кипр'),'fresh library selection uses the current-card projection');
  const named={...tripReply,body:'{client_name} / {trip_country}'};const names=harness(()=>({...list,replies:[named]}));names.S.detail={conversation:{id:101,display_name:'Рабочий псевдоним',original_display_name:'Имя источника'},lead:{ok:true,trip}};await names.api.open();select(names);assert.equal(names.nodes.get('qrPreview').value,'Имя источника / Турция');
  console.log('PASS toolbar/library current-trip interpolation and explicit late-card fill preserve edited text and backward caret without requests');

  for(const change of [h=>{h.S.current=102},h=>{h.S.authGeneration++},h=>{h.S.openGeneration++},h=>{h.S.manager={id:6}},h=>{h.S.authExpired=true},h=>{h.S.detail.conversation.id=102}]){
    const fenced=harness(()=>({...list,replies:[tripReply]}));await fenced.api.open();select(fenced);
    fenced.S.detail.lead={ok:true,trip};let calls=0;fenced.window.WorkspaceV2Conversation.insertQuickReply=()=>{calls++;return true};
    change(fenced);const before=fenced.nodes.get('qrPreview').value;
    assert.equal(fenced.nodes.get('qrFillKnown').onclick(),false);assert.equal(fenced.nodes.get('qrPreview').value,before);assert.equal(fenced.nodes.get('qrInsert').onclick(),false);assert.equal(calls,0);
  }
  for(const lead of [null,{ok:false,trip},{trip}]){
    const failed=harness(()=>({...list,replies:[tripReply]}));failed.S.detail.lead=lead;await failed.api.open();select(failed);
    assert.equal(failed.nodes.get('qrPreview').value,tripBody);assert.equal(failed.nodes.get('qrInsert').disabled,true);
  }
  console.log('PASS missing/failed card and navigation/account/auth/reopen fences cannot resolve or insert another conversation trip');

  const template=harness(action=>action==='create'?{ok:false,error:'network_error'}:{...list,replies:[tripReply]});await template.api.open();enterEditor(template,'Параметры',tripBody);
  template.nodes.get('qrTripVariable').value='trip_children';const body=template.nodes.get('qrBody');body.setSelectionRange(0,0);template.nodes.get('qrAddTripVariable').onclick();
  assert.equal(body.value,'{trip_children}'+tripBody);assert.equal(body.selectionStart,'{trip_children}'.length);
  template.nodes.get('qrEditor').emit('submit',{preventDefault(){}});await tick();
  assert.equal(template.calls.find(call=>call.action==='create').data.body,'{trip_children}'+tripBody);
  assert.equal(JSON.parse(template.values.get('workspaceV2.quickReplyDraft.5')).editor.body,'{trip_children}'+tripBody);
  assert.equal(template.nodes.get('qrFillKnown')?.disabled??true,true);assert.equal(template.nodes.get('qrSave').disabled,true);
  console.log('PASS compact trip-variable control saves only reusable tokens and retains exact unknown-save template draft');
})().catch(error=>{console.error(error);process.exitCode=1});
