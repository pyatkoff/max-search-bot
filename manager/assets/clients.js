/* global ManagerHttpClient */
(() => {
  'use strict';
  const $ = id => document.getElementById(id);
  const S = {actor:0,csrf:'',admin:false,generation:0,detailSequence:0,clients:[],selected:0,busy:false,pending:'',pendingKind:'',ready:false};
  const states = {created:'Сохранена · подключение не активировано',verified:'Проверена · можно активировать',active:'Подключение активно',activation_pending:'Результат активации требует проверки',check_failed:'Проверка подключения не пройдена'};
  const errors = {duplicate_client:'Имя бота, токен или логин сотрудника уже используются. Проверьте данные.',connection_in_use:'Этот бот уже подключён к системе.',client_save_failed:'Не удалось сохранить компанию. Данные не записаны.',client_capacity:'Достигнут предел числа компаний.',verification_required:'Сначала проверьте подключение.',activation_pending:'У этой компании уже есть незавершённая активация. Повторный запрос не отправлен.',connection_unavailable:'Подключение недоступно. Проверьте настройки и доступ компании.',operation_conflict:'Этот запрос уже завершён с другими данными. Уточните его результат.',context_stale:'Настройки или доступ изменились. Обновите страницу перед продолжением.',storage_unavailable:'Хранилище временно недоступно. Попробуйте узнать результат позже.',forbidden:'Доступ изменился. Обновите страницу.',not_found:'Компания недоступна.',network_error:'Не удалось получить ответ. Проверьте соединение.',invalid_response:'Сервер вернул неполный ответ.',receipt_storage_unavailable:'Браузер не сохранил номер операции. Разрешите хранение данных для этой вкладки и повторите.'};
  const reasons = {registration_required:'Проверка пройдена. Бот готов к отдельной активации.',already_active:'Подключение активно и подтверждено.',activated:'Подключение активировано.',bot_identity_mismatch:'Токен не соответствует указанному боту.',unknown_subscription_url:'У бота уже есть другое подключение. Оно сохранено; активация остановлена.',provider_read_failed:'Не удалось прочитать настройки MAX.',endpoint_probe_failed:'Не удалось проверить приём событий.',project_source_unavailable:'Компания или её источник обращений недоступны.',registration_secret_unconfirmed:'MAX не подтвердил результат активации. Повторная активация заблокирована.',registration_unconfirmed:'Не удалось подтвердить активацию. Повторная активация заблокирована.',mutation_admission_changed:'Настройки изменились до активации. Подключение не выполнено.'};
  function message(text) {$('notice').textContent=text;}
  function failure(result) {return errors[result?.error] || (String(result?.error||'').startsWith('invalid_')?'Проверьте формат и длину заполненных полей.':'Не удалось выполнить действие. Проверьте результат перед повтором.');}
  function current(generation) {return generation===S.generation&&S.ready;}
  function storageKey() {return 'client-onboarding-operation:'+S.actor;}
  function remember(operation,kind) {
    try {sessionStorage.setItem(storageKey(),operation);if(sessionStorage.getItem(storageKey())!==operation)throw new Error();}
    catch(e) {message(errors.receipt_storage_unavailable);return false;}
    S.pending=operation;S.pendingKind=kind;sync();return true;
  }
  function forget() {try{sessionStorage.removeItem(storageKey());}catch(e){}S.pending='';S.pendingKind='';sync();}
  function clearProjection() {
    $('detail').hidden=true;$('detailTitle').textContent='';$('detailMaterials').textContent='';$('detailBot').textContent='';$('staff').replaceChildren();$('clients').replaceChildren();$('empty').hidden=true;S.clients=[];S.selected=0;S.detailSequence++;
  }
  function clearPrivate() {
    $('clientForm').reset();$('editor').hidden=true;clearProjection();
  }
  function denied(text) {
    S.generation++;S.ready=false;S.csrf='';S.busy=false;clearPrivate();$('workspace').hidden=true;$('access').hidden=false;message(text);
  }
  async function request(action,data={},generation=S.generation) {
    const controller=new AbortController(),timer=setTimeout(()=>controller.abort(),45000);
    let result;
    try {result=await ManagerHttpClient.request(action,{expected_actor:S.actor,...data},S.csrf,'clients-api.php',{signal:controller.signal});}
    finally {clearTimeout(timer);}
    if(generation!==S.generation)return null;
    if(['unauthorized','csrf','identity_changed','forbidden'].includes(result?.error)){denied('Сессия или права доступа изменились. Повторите загрузку.');return null;}
    return result;
  }
  function selected() {return S.clients.find(row=>row.id===S.selected);}
  function sync() {
    $('addClient').hidden=!S.admin;$('addClient').disabled=S.busy||!!S.pending;
    $('clientFields').disabled=S.busy||!!S.pending||!S.ready;
    $('refresh').disabled=S.busy;$('receipt').disabled=S.busy;$('cancelOperation').disabled=S.busy;
    $('pending').hidden=!S.pending;
    $('pendingText').textContent='Ответ на запрос не подтверждён. Сначала узнайте результат. Отмена разрешена только до фактического подключения; она также остановит запоздалый запрос.';
    const row=selected();$('connectionActions').hidden=!S.admin||!row?.managed;
    $('verifyClient').disabled=S.busy||!row?.is_active;
    $('activateClient').hidden=!row||row.state!=='verified';$('activateClient').disabled=S.busy||!!S.pending||!row?.is_active;
    if(row)$('connectionState').textContent=(!row.is_active?'Компания отключена. ':row.managed?states[row.state]||'Состояние не подтверждено':'Компания уже в системе.')+(row.check?.reason?' '+(reasons[row.check.reason]||'Подробный результат подключения требует проверки.'):'');
  }
  function render() {
    $('clients').replaceChildren();$('empty').hidden=S.clients.length>0;
    for(const row of S.clients){
      const button=document.createElement('button');button.type='button';button.className='clientRow';button.dataset.clientId=String(row.id);button.setAttribute('aria-pressed',String(row.id===S.selected));
      const name=document.createElement('strong'),status=document.createElement('span');name.textContent=row.display_name;status.textContent=!row.is_active?'Отключена':row.managed?states[row.state]||'Состояние не подтверждено':'В системе';button.append(name,status);button.onclick=()=>open(row.id);$('clients').append(button);
    }
    sync();
  }
  async function load(generation=S.generation) {
    const result=await request('list',{},generation);if(!result||!current(generation))return false;
    if(!result.ok){message(failure(result));return false;}
    S.admin=result.is_admin===true;S.clients=result.clients||[];
    if(!selected()){S.selected=0;S.detailSequence++;$('detail').hidden=true;$('detailMaterials').textContent='';$('staff').replaceChildren();}
    if(!S.admin){$('editor').hidden=true;S.pending='';S.pendingKind='';$('clientForm').reset();}
    render();return true;
  }
  async function open(id,focus=true) {
    S.selected=id;const generation=S.generation,sequence=++S.detailSequence;render();$('detail').hidden=true;$('detailMaterials').textContent='';$('staff').replaceChildren();
    const row=selected();if(!row?.managed){message('Компания уже в системе. Её настройки доступны в администрировании.');return;}
    message('Загружаем карточку…');const result=await request('detail',{project_id:id},generation);
    if(!result||!current(generation)||sequence!==S.detailSequence||S.selected!==id)return;
    if(!result.ok){message(failure(result));return;}
    const client=result.client;$('detailTitle').textContent=client.display_name;$('detailBot').textContent='Бот MAX: @'+client.bot_key;$('detailMaterials').textContent=client.materials||'Материалы пока не добавлены.';
    for(const employee of client.staff||[]){const item=document.createElement('li');item.textContent=employee.display_name+' · '+employee.login;$('staff').append(item);}
    $('detail').hidden=false;sync();message('');if(focus)$('detailTitle').focus({preventScroll:true});
  }
  async function outcome(result,generation) {
    if(!result||!current(generation))return;
    if(result.state==='created'){
      forget();$('clientForm').reset();$('editor').hidden=true;message('Компания сохранена. Проверьте подключение перед активацией.');await load(generation);if(current(generation))await open(result.project_id);return;
    }
    if(result.state==='cancelled'){forget();message('Запрос отменён до подключения. Можно проверить данные и сохранить заново.');await load(generation);return;}
    if(['active','unknown','blocked'].includes(result.state)){
      forget();await load(generation);if(!current(generation))return;
      message(reasons[result.reason]||(result.state==='active'?'Подключение активно.':result.state==='unknown'?'Результат активации не подтверждён. Повторная активация заблокирована.':'Подключение не выполнено. Проверьте данные.'));return;
    }
    if(['pending','not_found'].includes(result.state)){S.pendingKind=result.operation_kind||S.pendingKind;message(result.state==='not_found'?'Результат пока не найден. Не отправляйте создание повторно: проверьте позже или отмените этот запрос.':'Операция ещё не завершена. Повторная активация не отправлена.');sync();return;}
    message(failure(result));
  }
  async function reconcile(cancel=false) {
    if(!S.pending||S.busy)return;const generation=S.generation;S.busy=true;sync();
    try {await outcome(await request(cancel?'cancel':'receipt',{operation_id:S.pending},generation),generation);}
    finally {if(current(generation)){S.busy=false;sync();}}
  }
  async function boot() {
    const generation=++S.generation;S.ready=false;S.busy=false;clearProjection();$('workspace').hidden=true;$('access').hidden=true;
    const me=await request('me',{},generation);if(generation!==S.generation)return;
    if(!me?.ok||!Number.isInteger(me.manager?.id)){denied(me?.error==='unauthorized'?'Войдите в кабинет, чтобы открыть компании.':'Не удалось загрузить компании. Проверьте соединение и повторите.');return;}
    if(S.actor!==me.manager.id||(S.admin&&me.manager.role!=='admin'))clearPrivate();S.actor=me.manager.id;S.admin=me.manager.role==='admin';S.csrf=me.csrf;S.ready=true;S.pending='';S.pendingKind='';
    if(S.admin)try{const op=sessionStorage.getItem(storageKey());if(/^[a-f0-9]{32}$/.test(op||''))S.pending=op;}catch(e){}
    $('workspace').hidden=false;message('');sync();await load(generation);if(current(generation)&&S.pending)await reconcile();
  }
  $('addClient').onclick=()=>{$('editor').hidden=false;$('editorTitle').focus();};
  $('closeEditor').onclick=()=>{$('editor').hidden=true;$('addClient').focus();};
  $('refresh').onclick=()=>{if(!S.busy)boot();};$('retry').onclick=boot;
  $('receipt').onclick=()=>reconcile();$('cancelOperation').onclick=()=>reconcile(true);
  $('clientForm').onsubmit=async event=>{
    event.preventDefault();if(S.busy||S.pending||!S.admin||!S.ready||!$('clientForm').reportValidity())return;
    const generation=S.generation,operation=crypto.randomUUID().replaceAll('-','');
    const client={operation_id:operation,display_name:$('clientName').value,business_kind:$('businessKind').value,bot_key:$('botKey').value,token:$('botToken').value,materials:$('materials').value,staff:[{name:$('staffName').value,login:$('staffLogin').value,password:$('staffPassword').value}]};
    if(!remember(operation,'create'))return;S.busy=true;sync();message('Сохраняем компанию…');
    try{
      const result=await request('create',{client},generation);if(!result||!current(generation))return;
      if(result.ok)await outcome(result,generation);
      else if(['network_error','invalid_response','save_outcome_unknown','storage_unavailable'].includes(result.error))message('Ответ о сохранении не получен. Узнайте результат перед следующим действием.');
      else {forget();message(failure(result));}
    }finally{client.token='';client.staff[0].password='';if(current(generation)){S.busy=false;sync();}}
  };
  $('materialsFile').onchange=async()=>{
    const file=$('materialsFile').files?.[0],revision=$('materials').value,generation=S.generation;if(!file)return;
    if(file.size>100000||!(/\.(txt|csv)$/i).test(file.name)){message('Выберите TXT или CSV размером до 100 КБ.');$('materialsFile').value='';return;}
    try{const text=new TextDecoder('utf-8',{fatal:true}).decode(await file.arrayBuffer());if(!current(generation)||!S.admin||S.busy||S.pending||$('materials').value!==revision)return;if(text.includes('\0'))throw new Error();$('materials').value=text;message('Материалы загружены в поле. Они сохранятся вместе с компанией.');}
    catch(e){message('Файл должен содержать текст UTF-8.');}finally{$('materialsFile').value='';}
  };
  $('verifyClient').onclick=async()=>{
    const id=S.selected,generation=S.generation;if(S.busy||!S.admin||!id)return;S.busy=true;sync();message('Проверяем подключение…');
    try{const result=await request('verify',{project_id:id},generation);if(!result||!current(generation))return;await load(generation);if(current(generation)&&S.selected===id)message(reasons[result.reason]||failure(result));}
    finally{if(current(generation)){S.busy=false;sync();}}
  };
  $('activateClient').onclick=async()=>{
    const id=S.selected,generation=S.generation;if(S.busy||S.pending||!S.admin||selected()?.state!=='verified')return;
    const operation=crypto.randomUUID().replaceAll('-','');if(!remember(operation,'activate'))return;S.busy=true;sync();message('Активируем подключение…');
    try{const result=await request('activate',{project_id:id,operation_id:operation},generation);if(!result||!current(generation))return;
      if(result.state)await outcome(result,generation);
      else if(['network_error','invalid_response','save_outcome_unknown','storage_unavailable'].includes(result.error))message('Ответ об активации не получен. Сначала узнайте результат.');
      else{forget();message(failure(result));await load(generation);}
    }finally{if(current(generation)){S.busy=false;sync();}}
  };
  window.addEventListener('beforeunload',event=>{const dirty=['clientName','botKey','botToken','staffName','staffLogin','staffPassword','materials'].some(id=>$(id).value);if(dirty||S.pending){event.preventDefault();event.returnValue='';}});
  document.addEventListener('visibilitychange',()=>{if(document.visibilityState==='visible')boot();});
  boot();
})();
