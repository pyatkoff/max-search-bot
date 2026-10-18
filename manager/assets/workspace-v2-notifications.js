(function(){
  let initialized=false,enableInFlight=false,interactiveEnableInFlight=false,autoRecoveryAttempted=false,lastStatus=null,activationFlight=null,recentInteractiveFailure=null,subscriptionFlight=null,workerRegistrationFlight=null,permissionFlight=null,recentFailureSession=null,autoRecoverySession=null,lastStatusSession=null,browserStatus=null,browserStatusSession=null,statusSequence=0;
  let connectionsRoot=null,connectionsSession=null,connectionsSequence=0,connectionRows=[],connectionRead=false,connectionBusy=new Set(),connectionErrors=new Map(),localEndpoint='';
  let browserConnection=null,browserConnectionSession=null;
  const installationKey=session=>'anytour-manager-push-installation:'+session.managerId;
  const pendingPrefix=session=>'anytour-manager-push-pending:'+session.managerId+':';
  function identity(session,create=false){
    let saved=null;try{saved=JSON.parse(localStorage.getItem(installationKey(session))||'null');}catch(error){}
    if(!saved||!/^[a-f0-9]{64}$/.test(saved.token||'')){if(!create)return null;saved={token:randomToken(),revision:0};localStorage.setItem(installationKey(session),JSON.stringify(saved));}
    return saved;
  }
  function randomToken(){return [...crypto.getRandomValues(new Uint8Array(32))].map(x=>x.toString(16).padStart(2,'0')).join('');}
  function acceptConnection(session,row){if(!row)return true;if(sameSession(browserConnectionSession)&&browserConnection?.id===row.id&&browserConnection.revision>row.revision)return false;browserConnection=row;browserConnectionSession=session;return true;}
  function pendingCommands(session){const rows=[];try{for(let n=0;n<localStorage.length;n++){const key=localStorage.key(n);if(key?.startsWith(pendingPrefix(session))){const item=JSON.parse(localStorage.getItem(key));if(item&&/^[a-f0-9]{64}$/.test(item.id||''))rows.push(item);}}}catch(error){throw new Error('push_storage_failed');}return rows;}
  function invalidateConnections(session){if(connectionsRoot&&sameSession(connectionsSession)&&sameSession(session)){connectionsSequence++;connectionRead=false;}}
  function finishCommand(session,pending,data){
    if(!sameSession(session))return;
    invalidateConnections(session);
    localStorage.removeItem(pendingPrefix(session)+pending.id);
    if(pending.action!=='revoke'&&data.current_connection){const saved=identity(session);if(saved&&saved.token===pending.token){saved.revision=Math.max(saved.revision,Number(data.current_connection.revision));localStorage.setItem(installationKey(session),JSON.stringify(saved));if(!browserConnection||browserConnection.id!==data.current_connection.id||browserConnection.revision<=data.current_connection.revision){browserConnection=data.current_connection;browserConnectionSession=session;}}}
  }
  async function command(session,action,input,state){
    requireSession(session);if(pendingCommands(session).length)throw new Error('push_operation_unknown');
    const pending={id:randomToken(),action,target:Number(input.connection_id||0),...(input.installation_token?{token:input.installation_token}:{})};
    localStorage.setItem(pendingPrefix(session)+pending.id,JSON.stringify(pending));
    invalidateConnections(session);
    try{
      const result=await setupRequest(state,'push.php',{method:'POST',headers:{'Content-Type':'application/json'},credentials:'same-origin',cache:'no-store',body:JSON.stringify({...input,action,operation_id:pending.id,csrf:session.csrf,expected_manager_id:session.managerId})},'push_save_timeout');
      requireSession(session);
      if(result.response.status===401){finishCommand(session,pending,{});throw new Error('push_session_expired');}
      if(result.response.status===403){finishCommand(session,pending,{});throw new Error('push_save_forbidden');}
      if(!result.data?.operation||result.data.operation.id!==pending.id)throw new Error('push_operation_unknown');
      finishCommand(session,pending,result.data);
      if(!result.response.ok||!result.data.ok)throw new Error(result.data.error==='subscription_revoked'?'subscription_revoked':'push_save_failed');
      return result.data;
    }catch(error){if(pendingCommands(session).some(p=>p.id===pending.id)){const unknown=new Error('push_operation_unknown');unknown.name=error.name;throw unknown;}throw error;}
  }
  async function reconcileCommands(session){
    for(const pending of pendingCommands(session)){
      const {response,data}=await setupRequest({},'push.php',{method:'POST',headers:{'Content-Type':'application/json'},credentials:'same-origin',cache:'no-store',body:JSON.stringify({action:'operation',operation_id:pending.id,expected_manager_id:session.managerId,csrf:session.csrf})},'push_status_timeout');
      requireSession(session);if(response.status===401)throw new Error('push_session_expired');
      if(!data?.found||data.operation?.id!==pending.id)throw new Error('push_operation_unknown');
      finishCommand(session,pending,data);
    }
  }
  function esc(value){const div=document.createElement('div');div.textContent=value??'';return div.innerHTML;}
  function reasonText(reason){return {healthy_subscription:'Уведомления включены',no_subscription:'Уведомления не подключены',subscription_unhealthy:'Уведомления требуют внимания',subscription_table_missing:'Уведомления недоступны',manager_inactive:'Профиль менеджера неактивен',managers_table_missing:'Статус уведомлений недоступен',health_check_failed:'Не удалось проверить уведомления',session_expired:'Войдите в кабинет',enable_failed:'Не удалось включить уведомления',enable_timeout:'Подключение не завершилось',permission_denied:'Разрешение на уведомления не выдано',unsupported_push:'Этот браузер не поддерживает уведомления',service_worker_failed:'Не удалось подготовить уведомления',subscription_pending:'Браузер ещё подключает уведомления',subscription_failed:'Браузер не подключил уведомления',server_setup_failed:'Не удалось завершить подключение уведомлений'}[reason]||'Статус уведомлений неизвестен';}
  function isSetupFailure(reason){return ['enable_failed','enable_timeout','permission_denied','unsupported_push','service_worker_failed','subscription_pending','subscription_failed','server_setup_failed','push_operation_unknown','subscription_revoked'].includes(String(reason||''));}
  function setupFailureReason(error,stage=''){const message=String(error?.message||'');if(['push_operation_unknown','subscription_revoked'].includes(message))return message;if(message==='push_save_timeout'&&['push_intent','push_save'].includes(stage))return'push_operation_unknown';if(message==='unsupported_push')return'unsupported_push';if(message==='subscribe_timeout'&&stage==='subscription_create')return'subscription_pending';if(message.includes('timeout'))return'enable_timeout';if(message.startsWith('service_worker_'))return'service_worker_failed';if(message.startsWith('subscription_')||message.startsWith('subscribe_')||message.startsWith('unsubscribe_'))return'subscription_failed';if(message.startsWith('push_key_')||message.startsWith('push_save_'))return'server_setup_failed';if(stage.startsWith('subscription_'))return'subscription_failed';if(stage.startsWith('service_worker_'))return'service_worker_failed';return'enable_failed';}
  function setupFailureDiagnostic(stage,error,httpStatus=0){
    const stageText={support:'Поддержка браузера',permission:'Разрешение браузера',service_worker_register:'Подготовка уведомлений',service_worker_ready:'Запуск уведомлений',push_key:'Получение настроек',subscription_wait:'Ожидание подключения',subscription_read:'Проверка подписки',subscription_repair:'Обновление подписки',subscription_create:'Подключение устройства',push_save:'Сохранение подписки'}[stage]||'Подключение уведомлений';
    const knownMessages={unsupported_push:'UNSUPPORTED',permission_not_granted:'PERMISSION_NOT_GRANTED',permission_timeout:'TIMEOUT',service_worker_register_timeout:'TIMEOUT',service_worker_ready_timeout:'TIMEOUT',push_key_timeout:'TIMEOUT',subscription_wait_timeout:'TIMEOUT',subscription_read_timeout:'TIMEOUT',subscription_health_timeout:'TIMEOUT',unsubscribe_timeout:'TIMEOUT',subscribe_timeout:'TIMEOUT',push_save_timeout:'TIMEOUT',push_key_failed:'KEY_RESPONSE',push_save_failed:'SAVE_RESPONSE',push_save_unhealthy:'UNHEALTHY'};
    const knownNames={NotAllowedError:'NotAllowedError',AbortError:'AbortError',SecurityError:'SecurityError',InvalidStateError:'InvalidStateError',NotSupportedError:'NotSupportedError',InvalidAccessError:'InvalidAccessError',NetworkError:'NetworkError',TypeError:'TypeError',OperationError:'OperationError',DataError:'DataError'};
    const code=Number.isInteger(httpStatus)&&httpStatus>=400&&httpStatus<=599?`HTTP_${httpStatus}`:Object.prototype.hasOwnProperty.call(knownMessages,error?.message)?knownMessages[error.message]:Object.prototype.hasOwnProperty.call(knownNames,error?.name)?knownNames[error.name]:'FAILED';
    const nativeMessages={'Registration failed - push service error':'PUSH_SERVICE','Registration failed - could not connect to push server':'PUSH_NETWORK','Registration failed - push service not available':'PUSH_UNAVAILABLE','Registration failed - storage error':'PUSH_STORAGE','Registration failed - storage corrupt':'PUSH_STORAGE','Registration failed - could not retrieve the public key':'PUSH_KEY'};
    const nativeCode=!code.startsWith('HTTP_')&&error?.name==='AbortError'&&Object.prototype.hasOwnProperty.call(nativeMessages,error?.message)?`AbortError/${nativeMessages[error.message]}`:code;
    return {stage:stageText,code:nativeCode};
  }
  function b64ToUint8(base64String){const padding='='.repeat((4-base64String.length%4)%4),base64=(base64String+padding).replace(/-/g,'+').replace(/_/g,'/'),raw=atob(base64);return Uint8Array.from([...raw].map(c=>c.charCodeAt(0)));}
  function sameBytes(left,right){if(!left||!right)return false;const a=new Uint8Array(left),b=new Uint8Array(right);if(a.length!==b.length)return false;for(let i=0;i<a.length;i++){if(a[i]!==b[i])return false;}return true;}
  function currentWorking(){return lastStatus!=null&&Object.prototype.hasOwnProperty.call(lastStatus,'is_working')?!!lastStatus.is_working:null;}
  function currentPushUnhealthy(){return String((browserStatus||lastStatus)?.notification_path_reason||'')==='subscription_unhealthy';}
  function recoverablePushStatus(){return ['no_subscription','subscription_unhealthy'].includes(String(lastStatus?.notification_path_reason||''));}
  function csrf(){return String(window.WorkspaceV2?.S?.csrf||'');}
  function sessionSnapshot(){const state=window.WorkspaceV2?.S||{};return{managerId:Number(state.manager?.id||0),generation:Number(state.authGeneration||0),csrf:csrf()};}
  function sameSession(session){const current=sessionSnapshot();return !!session&&session.managerId===current.managerId&&session.generation===current.generation&&session.csrf===current.csrf;}
  function requireSession(session){if(!sameSession(session))throw new Error('push_session_changed');}
  function requireActiveJob(job){requireSession(job.session);if(job.superseded)throw new Error('subscription_changed_while_pending');if(job.intent&&sameSession(browserConnectionSession)&&browserConnection?.id===job.intent.id&&(browserConnection.revision>job.intent.revision||!browserConnection.enabled))throw new Error(browserConnection.enabled?'subscription_changed_while_pending':'subscription_revoked');}
  function resetSessionState(){
    if(browserConnectionSession&&!sameSession(browserConnectionSession)){browserConnection=null;browserConnectionSession=null;}
    if(connectionsSession&&!sameSession(connectionsSession)){connectionsSequence++;connectionsSession=null;connectionRows=[];connectionRead=false;connectionBusy.clear();connectionErrors.clear();localEndpoint='';if(connectionsRoot)connectionsRoot.replaceChildren();}
    if(lastStatusSession&&!sameSession(lastStatusSession)){lastStatus=null;lastStatusSession=null;}
    if(browserStatusSession&&!sameSession(browserStatusSession)){browserStatus=null;browserStatusSession=null;statusSequence++;}
    if(recentFailureSession&&!sameSession(recentFailureSession)){recentInteractiveFailure=null;recentFailureSession=null;}
    if(autoRecoverySession&&!sameSession(autoRecoverySession))autoRecoveryAttempted=false;
  }
  function withCurrentWorking(status){const working=currentWorking();return working===null?status:{...status,is_working:working};}
  function render(root,status){
    if(!root)return;
    const account=status||{},originalReason=String(account.notification_path_reason||''),local=account.current_subscription||browserStatus;
    // Account health describes all saved subscriptions. Only a matching local
    // subscription may make this browser green; setup diagnostics take priority.
    if((status===lastStatus||account.current_subscription)&&!isSetupFailure(originalReason)&&!['session_expired','health_check_failed'].includes(originalReason))status=withCurrentWorking(local||{notification_path_usable:false,notification_path_reason:'browser_checking'});
    const usable=!!status?.notification_path_usable,count=Number(lastStatus?.healthy_subscription_count??account.healthy_subscription_count??0),reason=String(status?.notification_path_reason||''),setupFailure=isSetupFailure(reason),hasWorking=status!=null&&Object.prototype.hasOwnProperty.call(status,'is_working'),working=hasWorking?!!status.is_working:null,workingWithoutPush=working===true&&!usable;
    const localLabels={browser_checking:'Проверяем уведомления…',browser_check_failed:'Не удалось проверить уведомления',no_subscription:'Уведомления не подключены'};
    const label='Этот браузер: '+(reason==='subscription_revoked'?'Уведомления отключены':reason==='push_operation_unknown'?'Результат ещё не подтверждён':localLabels[reason]||reasonText(reason)),shift=working===null?'Статус смены неизвестен':working?'Смена включена':'Вне смены';
    const failureDetails={permission_denied:'Разрешите уведомления для сайта в настройках браузера',enable_timeout:'Повторите попытку. Если окно разрешения не появилось — проверьте настройки сайта в браузере',unsupported_push:'На iPhone добавьте кабинет на экран «Домой» и откройте оттуда. В других браузерах откройте кабинет вне Telegram или MAX.',service_worker_failed:'Обновите страницу и повторите. Если не поможет — откройте кабинет в обычном браузере',subscription_pending:'Разрешение получено. Браузер ещё подключает устройство. Можно работать в кабинете — статус обновится автоматически.',subscription_failed:'Проверьте разрешение уведомлений для сайта и повторите',server_setup_failed:'Повторите попытку. Если ошибка останется — сообщите администратору',enable_failed:'Обновите страницу и повторите попытку',browser_check_failed:'Проверка не завершилась. Повторите её или подключите уведомления на этом устройстве.',browser_checking:'Можно продолжать работу в кабинете.'};
    const detail=reason==='subscription_revoked'?'Включить их снова можно только явным действием в этом браузере.':reason==='push_operation_unknown'?'Ответ не получен. Проверим сохранённый результат без повторного подключения.':(reason==='subscription_failed'&&String(status?.setup_diagnostic?.code||'').startsWith('AbortError')?(status.setup_diagnostic.code==='AbortError/PUSH_NETWORK'?'Браузер не смог подключиться к службе уведомлений. Проверьте соединение и повторите.':'Браузер не завершил подключение уведомлений. Повторите попытку.'):failureDetails[reason])||(workingWithoutPush?'Смена без уведомлений на этом устройстве. Новые сообщения могут остаться без быстрого ответа':shift);
    const actionLabel=interactiveEnableInFlight?'Подключаем…':['subscription_pending','push_operation_unknown'].includes(reason)?'Проверить':status?.setup_diagnostic?'Повторить':'Включить на этом устройстве',diagnostic=setupFailure?status?.setup_diagnostic:null;
    const accountDetail=originalReason==='health_check_failed'?'Статус аккаунта не удалось проверить':lastStatus?`В аккаунте: ${count} ${count%10===1&&count%100!==11?'подписка':count%10>=2&&count%10<=4&&(count%100<12||count%100>14)?'подписки':'подписок'} без известных ошибок`:'Статус аккаунта пока неизвестен';
    root.className='notificationHealth '+(usable?'ok':workingWithoutPush?'critical':'warn');
    root.innerHTML=`<span class="notificationDot" aria-hidden="true"></span><div class="notificationText"><strong>${esc(label)}</strong><small>${esc(detail)}</small><small class="notificationAccount">${esc(shift)} · ${esc(accountDetail)}</small>${diagnostic?`<details class="notificationDiagnostic"><summary>Подробности для администратора</summary><div>Этап: ${esc(diagnostic.stage)} · Код: ${esc(diagnostic.code)}</div></details>`:''}</div>${usable?'':`<button type="button" class="notificationAction" data-enable-push ${interactiveEnableInFlight?'disabled':''}>${esc(actionLabel)}</button>`}`;
  }
  function handleUnauthorized(root){window.WorkspaceV2?.showAuthRecovery?.();render(root,{notification_path_usable:false,notification_path_reason:'session_expired'});}
  async function parseJson(response){return response.json().catch(()=>null);}
  function withTimeout(promise,ms,label){let timer;return Promise.race([promise,new Promise((_,reject)=>{timer=setTimeout(()=>reject(new Error(label||'timeout')),ms);})]).finally(()=>clearTimeout(timer));}
  function requestedPermission(){
    if(permissionFlight)return permissionFlight;
    // Keep the native request alive across UI timeouts. Call it synchronously
    // from the click so browsers retain the user gesture for the first prompt.
    const pending=Promise.resolve(Notification.requestPermission());
    permissionFlight=pending;
    pending.then(()=>{if(permissionFlight===pending)permissionFlight=null;},()=>{if(permissionFlight===pending)permissionFlight=null;});
    return pending;
  }
  function registeredWorker(){
    if(workerRegistrationFlight)return workerRegistrationFlight;
    const pending=Promise.resolve().then(()=>navigator.serviceWorker.register('sw.js',{scope:'./'}));
    workerRegistrationFlight=pending;
    pending.then(()=>{if(workerRegistrationFlight===pending)workerRegistrationFlight=null;},()=>{if(workerRegistrationFlight===pending)workerRegistrationFlight=null;});
    return pending;
  }
  async function readyRegistration(registration=null){
    const reg=registration||await withTimeout(registeredWorker(),10000,'service_worker_register_timeout');
    // Manager2 shares the canonical /manager/ worker. Global ready/controller
    // match the document URL, so they cannot establish readiness on /manager2/.
    if(reg.active?.state==='activated')return reg;
    return new Promise((resolve,reject)=>{
      let timer,finished=false;
      const workers=new Set();
      const cleanup=()=>{clearTimeout(timer);reg.removeEventListener('updatefound',inspect);workers.forEach(worker=>worker.removeEventListener('statechange',inspect));};
      const finish=error=>{if(finished)return;finished=true;cleanup();if(error)reject(error);else resolve(reg);};
      const inspect=()=>{
        if(finished)return;
        if(reg.active?.state==='activated'){finish();return;}
        const candidates=[reg.active,reg.waiting,reg.installing].filter(Boolean);
        candidates.forEach(worker=>{if(!workers.has(worker)){workers.add(worker);worker.addEventListener('statechange',inspect);}});
        if(workers.size&&[...workers].every(worker=>worker.state==='redundant')&&candidates.every(worker=>worker.state==='redundant'))finish(new Error('service_worker_redundant'));
      };
      reg.addEventListener('updatefound',inspect);
      timer=setTimeout(()=>finish(new Error('service_worker_ready_timeout')),10000);
      inspect();
    });
  }
  async function setupRequest(state,url,options,label){
    const controller=typeof AbortController==='function'?new AbortController():null;
    // Bound the complete HTTP body, not just response headers. An expired
    // session needs no JSON body before returning to the sign-in recovery path.
    const pending=(async()=>{const response=await fetch(url,controller?{...options,signal:controller.signal}:options);state.httpStatus=response.status;return{response,data:response.status===401?null:await parseJson(response)};})();
    try{return await withTimeout(pending,10000,label);}
    catch(error){if(error?.message===label)controller?.abort();throw error;}
  }
  function subscriptionStage(job,stage){job.stage=stage;job.httpStatus=0;job.observers.forEach(observer=>observer());}
  function compatibleSubscription(sub,key){return !!sub&&(!sub.options?.applicationServerKey||sameBytes(sub.options.applicationServerKey,key));}
  const diagnosticDuration=start=>Math.min(600000,Math.max(0,Date.now()-start));
  async function nativeSetupCall(job,call,run){
    const started=Date.now();
    try{const sub=await run();job.nativeTrace.push({call,outcome:sub?'subscription':'none',duration_ms:diagnosticDuration(started)});return sub;}
    catch(error){job.nativeTrace.push({call,outcome:setupFailureDiagnostic('subscription_create',error).code,duration_ms:diagnosticDuration(started)});throw error;}
  }
  function reportSetupFailure(job,error){
    // Observe the real native outcome only. Telemetry cannot cancel/retry setup,
    // adopt a new account, or turn a UI deadline into a provider failure.
    try{
      if(!job.reportRequested||job.reported||job.stage!=='subscription_create'||!job.nativeTrace.length)return;
      requireActiveJob(job);
      const last=job.nativeTrace[job.nativeTrace.length-1];
      if(['subscription','none'].includes(last.outcome))return;
      job.reported=true;
      const diagnostic={stage:job.stage,code:setupFailureDiagnostic(job.stage,error).code,elapsed_ms:diagnosticDuration(job.started),permission:['granted','denied','default'].includes(Notification.permission)?Notification.permission:'unknown',secure_context:window.isSecureContext===true,worker_active:job.reg.active?.state==='activated',worker_scope_matches:job.reg.scope===new URL('./',document.baseURI).href,web_locks:typeof navigator.locks?.request==='function',key_length:job.key.length,key_uncompressed:job.key[0]===4,trace:job.nativeTrace.slice(0,3)};
      // Fixed enums/booleans/counts only: Firefox's privileged native cause is
      // unavailable to the page. Never send raw errors, endpoints or key bytes.
      void setupRequest({},'push.php?action=setup_diagnostic',{method:'POST',headers:{'Content-Type':'application/json'},credentials:'same-origin',cache:'no-store',body:JSON.stringify({action:'setup_diagnostic',csrf:job.session.csrf,expected_manager_id:job.session.managerId,diagnostic})},'push_diagnostic_timeout').catch(()=>{});
    }catch(ignored){}
  }
  async function recoverSubscription(reg,key,job){
    try{return await nativeSetupCall(job,'subscribe',()=>reg.pushManager.subscribe({userVisibleOnly:true,applicationServerKey:key}));}
    catch(error){
      if(error?.name!=='AbortError')throw error;
      // A browser/provider may finish another registration after the failed call.
      // Re-read it before the only sequential retry; never delete it to retry.
      await new Promise(resolve=>setTimeout(resolve,250));requireActiveJob(job);
      const actual=await nativeSetupCall(job,'recovery_read',()=>reg.pushManager.getSubscription());
      requireActiveJob(job);if(actual){if(compatibleSubscription(actual,key))return actual;throw error;}
      return nativeSetupCall(job,'retry_subscribe',()=>reg.pushManager.subscribe({userVisibleOnly:true,applicationServerKey:key}));
    }
  }
  function subscriptionJob(reg,key,interactive,intentBase){
    if(subscriptionFlight){
      if(!sameSession(subscriptionFlight.session)){subscriptionFlight.superseded=true;throw new Error('subscription_pending_session_change');}
      if(!sameBytes(subscriptionFlight.key,key)||(reg!==subscriptionFlight.reg&&(!reg.scope||reg.scope!==subscriptionFlight.reg.scope))){subscriptionFlight.superseded=true;throw new Error('subscription_changed_while_pending');}
      if(interactive)subscriptionFlight.reportRequested=true;
      return subscriptionFlight;
    }
    const job={stage:'subscription_wait',httpStatus:0,observers:new Set(),promise:null,reg,key,interactive,intentBase,superseded:false,session:sessionSnapshot(),intent:null,started:Date.now(),nativeTrace:[],reportRequested:interactive,reported:false};
    const run=async()=>{
      requireActiveJob(job);subscriptionStage(job,'subscription_read');
      let sub=await reg.pushManager.getSubscription();requireActiveJob(job);
      let unhealthy=currentPushUnhealthy();
      const installation=job.intentBase.installation;
      if(!installation)throw new Error('subscription_revoked');
      {
        // Read this endpoint even before the page probe has completed. Another
        // healthy browser cannot mask it, and another tab may have repaired it
        // while this tab waited for the origin lock.
        const {response,data}=await setupRequest(job,'push-status.php',{method:'POST',headers:{'Content-Type':'application/json',Accept:'application/json'},credentials:'same-origin',cache:'no-store',body:JSON.stringify({csrf:job.session.csrf,installation_token:installation.token,...(sub?{endpoint:sub.endpoint||sub.toJSON().endpoint}:{})})},'subscription_health_timeout');
        if(response.status===401)throw new Error('push_session_expired');
        if(!response.ok||!data?.ok||!['healthy_subscription','no_subscription','subscription_unhealthy','subscription_revoked'].includes(data.current_subscription?.notification_path_reason))throw new Error('subscription_health_failed');
        requireActiveJob(job);
        if(!acceptConnection(job.session,data.current_connection))throw new Error('subscription_changed_while_pending');
        if(job.interactive){
          // The click cannot adopt a revocation/version first seen after it began.
          // A fresh click on a visible disabled state can explicitly renew it.
          const observed=data.current_connection,known=job.intentBase.connection;
          if(observed&&!observed.enabled&&(!known||known.id!==observed.id||known.enabled))throw new Error('subscription_revoked');
          const expected=known&&known.id===observed?.id?Math.max(known.revision,installation.revision):installation.revision>0?installation.revision:Number(observed?.revision||0);
          subscriptionStage(job,'push_intent');
          const intent=await command(job.session,'enable_intent',{installation_token:installation.token,expected_revision:expected,...(sub?{subscription:sub.toJSON()}:{})},job);
          if(!intent.current_connection?.enabled)throw new Error('subscription_revoked');
          if(intent.current_connection.revision!==intent.operation.result.revision)throw new Error('subscription_changed_while_pending');
          job.intent={id:intent.current_connection.id,token:installation.token,revision:intent.current_connection.revision};
        }else{
          if(!data.current_connection?.enabled||data.current_connection.revision!==installation.revision)throw new Error('subscription_revoked');
          job.intent={id:data.current_connection.id,token:installation.token,revision:installation.revision};
        }
        unhealthy=String(data.current_subscription.notification_path_reason||'')==='subscription_unhealthy';
      }
      requireActiveJob(job);if(sub&&(unhealthy||!compatibleSubscription(sub,key))){
        subscriptionStage(job,'subscription_repair');await sub.unsubscribe();sub=null;
      }
      requireActiveJob(job);if(!sub){subscriptionStage(job,'subscription_create');sub=await recoverSubscription(reg,key,job);}
      requireActiveJob(job);
      subscriptionStage(job,'push_save');
      const saved=await command(job.session,'subscribe',{installation_token:job.intent.token,expected_revision:job.intent.revision,subscription:sub.toJSON()},job);
      requireActiveJob(job);
      if(!saved.current_connection?.enabled)throw new Error('subscription_revoked');
      if(saved.current_connection.revision!==job.intent.revision)throw new Error('subscription_changed_while_pending');
      if(!saved.push_status)throw new Error('push_save_failed');
      if(!saved.push_status.notification_path_usable||!saved.current_subscription?.notification_path_usable)throw new Error('push_save_unhealthy');
      browserStatus=saved.current_subscription;browserStatusSession=job.session;statusSequence++;
      if(connectionsRoot)void loadConnections();
      return {...saved.push_status,current_subscription:saved.current_subscription};
    };
    // The lock belongs to the real operation, not its UI timeout. A retry in this
    // page observes this same job; another tab re-reads after the lock is released.
    job.promise=Promise.resolve().then(()=>navigator.locks?.request?navigator.locks.request('anytour-manager-push-subscription',run):run());
    subscriptionFlight=job;
    job.promise.then(()=>{if(subscriptionFlight===job)subscriptionFlight=null;},error=>{if(subscriptionFlight===job)subscriptionFlight=null;reportSetupFailure(job,error);});
    return job;
  }
  function observeSubscription(job){
    return new Promise((resolve,reject)=>{
      let timer,finished=false;
      const cleanup=()=>{finished=true;clearTimeout(timer);job.observers.delete(arm);};
      const arm=()=>{clearTimeout(timer);const stage=job.stage,label={subscription_wait:'subscription_wait_timeout',subscription_read:'subscription_read_timeout',subscription_repair:'unsubscribe_timeout',subscription_create:'subscribe_timeout',push_intent:'push_save_timeout',push_save:'push_save_timeout'}[stage];timer=setTimeout(()=>{cleanup();reject(new Error(label));},stage==='subscription_create'?15000:10000);};
      job.observers.add(arm);arm();
      job.promise.then(status=>{if(!finished){cleanup();resolve(status);}},error=>{if(!finished){cleanup();reject(error);}});
    });
  }
  function observeLateSubscription(job,session,failure){
    // The UI deadline cannot cancel native work. Follow this exact job's real
    // result, without letting another device's health or a newer attempt heal it.
    const current=()=>sameSession(session)&&!job.superseded&&recentInteractiveFailure===failure&&!enableInFlight;
    job.promise.then(status=>{
      if(!current())return;
      lastStatus=status;lastStatusSession=session;recentInteractiveFailure=null;recentFailureSession=null;
      render(document.getElementById('notificationStatus'),status);
    },error=>{
      if(!current())return;
      if(error?.message==='push_session_expired'){recentInteractiveFailure=null;recentFailureSession=null;handleUnauthorized(document.getElementById('notificationStatus'));return;}
      const status=withCurrentWorking({notification_path_usable:false,notification_path_reason:setupFailureReason(error,job.stage)});
      status.setup_diagnostic=setupFailureDiagnostic(job.stage,error,job.httpStatus);
      recentInteractiveFailure=status;recentFailureSession=session;render(document.getElementById('notificationStatus'),status);
    });
  }
  function reconcile(root,{requestPermission=true,showConfirmation=true,interactive=true}={}){
    resetSessionState();if(enableInFlight){
      if(!sameSession(activationFlight.session))return Promise.resolve(false);
      if(interactive)activationFlight.promote(root,showConfirmation);
      return activationFlight.promise;
    }
    const activationSession=sessionSnapshot();statusSequence++;enableInFlight=true;interactiveEnableInFlight=interactive;
    const showBusy=()=>{recentInteractiveFailure=null;recentFailureSession=null;render(root,withCurrentWorking({notification_path_usable:false,notification_path_reason:'no_subscription'}));};
    // The click adopts recovery already running for this session. It keeps the
    // same bounded UI wait and actual subscription job, without a queued retry.
    const attempt={session:activationSession,promise:null,job:null,promote(nextRoot,confirmation){if(interactive)return;root=nextRoot;interactive=true;if(attempt.job)attempt.job.reportRequested=true;showConfirmation=confirmation;interactiveEnableInFlight=true;showBusy();}};
    activationFlight=attempt;if(interactive)showBusy();
    attempt.promise=(async()=>{
      let finalStatus=null,setupStage='support',setupHttpStatus=0,job=null;
      try{
        if(!('serviceWorker'in navigator)||!('PushManager'in window)||!('Notification'in window))throw new Error('unsupported_push');
        const saved=identity(activationSession,interactive),intentBase={installation:saved?{...saved}:null,connection:sameSession(browserConnectionSession)&&browserConnection?{...browserConnection}:null};
        setupStage='permission';
        const permission=Notification.permission==='granted'?'granted':requestPermission?await withTimeout(requestedPermission(),15000,'permission_timeout'):Notification.permission;
        requireSession(activationSession);
        if(permission!=='granted'){if(interactive){finalStatus=withCurrentWorking({notification_path_usable:false,notification_path_reason:'permission_denied'});finalStatus.setup_diagnostic=setupFailureDiagnostic(setupStage,new Error('permission_not_granted'));}return false;}
        setupStage='service_worker_register';const registered=await withTimeout(registeredWorker(),10000,'service_worker_register_timeout');
        setupStage='service_worker_ready';const reg=await readyRegistration(registered);
        requireSession(activationSession);setupStage='push_key';const keyRequest={httpStatus:0};let keyResponse;
        try{keyResponse=await setupRequest(keyRequest,'push.php?action=key',{credentials:'same-origin',cache:'no-store'},'push_key_timeout');}finally{setupHttpStatus=keyRequest.httpStatus;}
        requireSession(activationSession);const {response:keyResp,data:keyJson}=keyResponse;if(keyResp.status===401){handleUnauthorized(root);return false;}
        if(!keyResp.ok||!keyJson?.ok||!keyJson.public_key)throw new Error('push_key_failed');
        const key=b64ToUint8(keyJson.public_key);setupStage='subscription_wait';job=subscriptionJob(reg,key,interactive,intentBase);
        attempt.job=job;
        const status=await observeSubscription(job);requireSession(activationSession);lastStatus=status;lastStatusSession=activationSession;finalStatus=lastStatus;recentInteractiveFailure=null;recentFailureSession=null;
        // Saving a healthy subscription completes setup. Optional OS display
        // must neither hold the UI busy nor turn that saved result into failure.
        if(showConfirmation){try{Promise.resolve(reg.showNotification('AnyTour',{body:'Уведомления включены',tag:'anytour-push-enabled'})).catch(()=>{});}catch(e){}}
        return true;
      }catch(error){
        setupStage=job?.stage||setupStage;setupHttpStatus=job?.httpStatus??setupHttpStatus;
        if(!sameSession(activationSession)||error?.message==='push_session_changed')return false;
        if(error?.message==='push_session_expired'){handleUnauthorized(root);return false;}
        console.warn(interactive?'Workspace V2 push enable failed':'Workspace V2 push recovery failed',error);
        if(interactive){finalStatus=withCurrentWorking({notification_path_usable:false,notification_path_reason:setupFailureReason(error,setupStage)});finalStatus.setup_diagnostic=setupFailureDiagnostic(setupStage,error,setupHttpStatus);if(job&&finalStatus.notification_path_reason==='subscription_pending')observeLateSubscription(job,activationSession,finalStatus);}
        return false;
      }finally{enableInFlight=false;interactiveEnableInFlight=false;activationFlight=null;if(sameSession(activationSession)){if(interactive&&finalStatus&&!finalStatus.notification_path_usable&&isSetupFailure(finalStatus.notification_path_reason)){recentInteractiveFailure=finalStatus;recentFailureSession=activationSession;}if(finalStatus)render(root,finalStatus);else if(lastStatus)render(root,lastStatus);}if(interactive&&finalStatus?.notification_path_usable)setTimeout(()=>refresh(),500);}
    })();
    return attempt.promise;
  }
  function enable(root){
    if(enableInFlight)return reconcile(root,{requestPermission:true,showConfirmation:true,interactive:true});
    const session=sessionSnapshot();
    let pending;try{pending=pendingCommands(session);}catch(error){render(root,withCurrentWorking({notification_path_usable:false,notification_path_reason:'server_setup_failed'}));return Promise.resolve(false);}
    if(pending.length)return(async()=>{
      try{await reconcileCommands(session);requireSession(session);recentInteractiveFailure=null;recentFailureSession=null;await refresh();if(connectionsRoot)void loadConnections();return true;}
      catch(error){if(sameSession(session)){if(error.message==='push_session_expired')handleUnauthorized(root);else{recentInteractiveFailure=withCurrentWorking({notification_path_usable:false,notification_path_reason:'push_operation_unknown'});recentFailureSession=session;render(root,recentInteractiveFailure);}}return false;}
    })();
    return reconcile(root,{requestPermission:true,showConfirmation:true,interactive:true});
  }
  function maybeRecoverGranted(root){if(autoRecoverySession&&!sameSession(autoRecoverySession))autoRecoveryAttempted=false;const session=sessionSnapshot();if(!identity(session)||pendingCommands(session).length||autoRecoveryAttempted||enableInFlight||recentInteractiveFailure||!['no_subscription','subscription_unhealthy','healthy_subscription'].includes(String(browserStatus?.notification_path_reason||''))||!('Notification'in window)||Notification.permission!=='granted'||!recoverablePushStatus())return false;autoRecoveryAttempted=true;autoRecoverySession=session;void reconcile(root,{requestPermission:false,showConfirmation:false,interactive:false});return true;}
  async function probeBrowser(session){
    if(sameSession(session))localEndpoint='';
    if(!('serviceWorker'in navigator)||!('PushManager'in window)||!('Notification'in window))return{notification_path_usable:false,notification_path_reason:'unsupported_push'};
    if(Notification.permission==='denied')return{notification_path_usable:false,notification_path_reason:'permission_denied'};
    // Inspection must not register, subscribe, prompt, repair or sync. Manager2
    // lives outside the worker scope, so query the canonical base explicitly.
    try{
      if(typeof navigator.serviceWorker.getRegistration!=='function')throw new Error('browser_probe_unavailable');
      const scope=new URL('./',document.baseURI).href;
      const reg=await withTimeout(Promise.resolve().then(()=>navigator.serviceWorker.getRegistration(scope)),5000,'browser_probe_timeout');
      requireSession(session);
      // getRegistration may return an ancestor worker. Its subscription belongs
      // to a different registration and cannot prove this manager path.
      if(!reg||reg.scope!==scope)return{notification_path_usable:false,notification_path_reason:'no_subscription'};
      const sub=reg?await withTimeout(Promise.resolve().then(()=>reg.pushManager.getSubscription()),5000,'browser_probe_timeout'):null;
      requireSession(session);
      if(!sub||Notification.permission!=='granted')return{notification_path_usable:false,notification_path_reason:'no_subscription'};
      const endpoint=sub.endpoint||sub.toJSON?.().endpoint;
      if(typeof endpoint!=='string'||!endpoint)throw new Error('browser_probe_unavailable');
      localEndpoint=endpoint;return{endpoint,registration:reg};
    }catch(error){return{notification_path_usable:false,notification_path_reason:'browser_check_failed'};}
  }
  async function refresh(){
    resetSessionState();const refreshSession=sessionSnapshot(),sequence=++statusSequence,root=document.getElementById('notificationStatus');if(!root)return;
    const current=()=>sameSession(refreshSession)&&sequence===statusSequence;
    const display=()=>{if(interactiveEnableInFlight&&sameSession(activationFlight?.session))render(root,withCurrentWorking({notification_path_usable:false,notification_path_reason:'no_subscription'}));else render(root,recentInteractiveFailure?withCurrentWorking(recentInteractiveFailure):lastStatus);};
    if(!recentInteractiveFailure&&!interactiveEnableInFlight)render(root,withCurrentWorking({notification_path_usable:false,notification_path_reason:'browser_checking'}));
    // The shell loads before me/login establishes the JS account and CSRF.
    // Canonical post-auth startup will refresh; do not send an ownerless proof.
    if(refreshSession.managerId<=0||!refreshSession.csrf||window.WorkspaceV2?.S?.authExpired)return;
    try{if(pendingCommands(refreshSession).length&&recentInteractiveFailure?.notification_path_reason!=='push_operation_unknown'){recentInteractiveFailure=withCurrentWorking({notification_path_usable:false,notification_path_reason:'push_operation_unknown'});recentFailureSession=refreshSession;}}catch(error){}
    try{
      // Both reads are bounded and independent of Inbox/composer readiness.
      const accountRead=setupRequest({},'push-status.php',{headers:{Accept:'application/json'},credentials:'same-origin',cache:'no-store'},'push_status_timeout');
      const localRead=probeBrowser(refreshSession);
      const {response,data}=await accountRead;
      if(!current())return;
      if(response.status===401){handleUnauthorized(root);return;}
      if(!response.ok||!data?.ok)throw new Error('push_status_failed');
      lastStatus=data.push_status||{};lastStatusSession=refreshSession;
      browserStatus={notification_path_usable:false,notification_path_reason:'browser_checking'};browserStatusSession=refreshSession;display();
      const local=await localRead;if(!current())return;
      const installation=identity(refreshSession);
      if(local.endpoint||installation){
        const checked=await setupRequest({},'push-status.php',{method:'POST',headers:{'Content-Type':'application/json',Accept:'application/json'},credentials:'same-origin',cache:'no-store',body:JSON.stringify({csrf:refreshSession.csrf,...(local.endpoint?{endpoint:local.endpoint}:{}),...(installation?{installation_token:installation.token}:{})})},'push_status_timeout');
        if(!current())return;
        if(checked.response.status===401){handleUnauthorized(root);return;}
        if(!checked.response.ok||!checked.data?.ok||!checked.data.current_subscription)throw new Error('push_status_failed');
        // A different tab may have replaced the native subscription during HTTP.
        if(checked.data.current_subscription.notification_path_usable){
          const actual=await withTimeout(Promise.resolve().then(()=>local.registration.pushManager.getSubscription()),5000,'browser_probe_timeout');
          if(!current())return;
          if((actual?.endpoint||actual?.toJSON?.().endpoint)!==local.endpoint)throw new Error('browser_subscription_changed');
        }
        lastStatus=checked.data.push_status||lastStatus;lastStatusSession=refreshSession;
        if(!acceptConnection(refreshSession,checked.data.current_connection))throw new Error('browser_subscription_changed');
        browserStatus=checked.data.current_subscription.notification_path_reason==='subscription_revoked'?checked.data.current_subscription:Notification.permission==='granted'?checked.data.current_subscription:{notification_path_usable:false,notification_path_reason:Notification.permission==='denied'?'permission_denied':'no_subscription'};
      }else browserStatus=local;
      browserStatusSession=refreshSession;display();
      if(pendingCommands(refreshSession).length){if(recentInteractiveFailure?.notification_path_reason!=='push_operation_unknown')recentInteractiveFailure=withCurrentWorking({notification_path_usable:false,notification_path_reason:'push_operation_unknown'});recentFailureSession=refreshSession;display();return;}
      // Preserve the existing account-level once-only recovery policy. A new
      // local probe never expands it and UNKNOWN must never start native writes.
      maybeRecoverGranted(root);
    }catch(error){if(!current())return;browserStatus={notification_path_usable:false,notification_path_reason:'browser_check_failed'};browserStatusSession=refreshSession;render(root,recentInteractiveFailure||withCurrentWorking({notification_path_usable:false,notification_path_reason:'health_check_failed'}));}
  }
  function activityText(value){if(!value)return'Последняя активность неизвестна';const date=new Date(value);if(!Number.isFinite(date.getTime()))return'Последняя активность неизвестна';return'Последняя активность: '+new Intl.DateTimeFormat('ru-RU',{day:'2-digit',month:'2-digit',year:'numeric',hour:'2-digit',minute:'2-digit',timeZone:'Europe/Moscow'}).format(date)+' МСК';}
  function renderConnections(message=''){
    if(!connectionsRoot||!connectionsSession||!sameSession(connectionsSession))return;
    const focused=connectionsRoot.contains(document.activeElement)?document.activeElement?.closest('[data-connection-id]')?.dataset.connectionId:null;
    let pending;try{pending=pendingCommands(connectionsSession);}catch(error){connectionsRoot.innerHTML='<p role="status">Не удалось прочитать сохранённый результат в этом браузере. Управление подключениями пока недоступно.</p>';return;}
    const labels={saved:'Подписка сохранена',attention:'Нужна проверка в этом браузере',revoked:'Уведомления отключены',not_connected:'Подключение не завершено'};
    connectionsRoot.innerHTML=`<div class="notificationConnectionsHead"><h3>Подключённые браузеры</h3><button type="button" data-connections-refresh ${connectionRead?'disabled':''}>${connectionRead?'Проверяем…':'Обновить'}</button></div><p class="notificationConnectionsHint">Это браузерные подключения. Сохранённая подписка не подтверждает показ уведомления.</p>${message?`<p class="notificationConnectionNotice" role="status">${esc(message)}</p>`:''}<div class="notificationConnectionList">${connectionRows.map(row=>{
      const busy=connectionBusy.has(row.id),unknown=pending.some(p=>p.target===row.id),error=connectionErrors.get(row.id);
      return `<div class="notificationConnection" data-connection-id="${row.id}" tabindex="-1"><div><strong>${esc(row.browser)}${row.current?' <span class="notificationCurrent">Этот браузер</span>':''}</strong><span>${esc(labels[row.status]||'Статус неизвестен')}</span><small>${esc(activityText(row.last_activity_at))}</small>${error?`<span class="notificationConnectionError" role="status">${esc(error)}</span>`:''}</div>${row.enabled||unknown?`<button type="button" data-revoke-connection="${row.id}" ${busy||pending.length&&!unknown?'disabled':''}>${busy?'Проверяем…':unknown?'Проверить результат':'Отключить уведомления'}</button>`:''}</div>`;
    }).join('')}</div>${!connectionRows.length&&!connectionRead?'<p>Сохранённых подключений пока нет. Уведомления можно включить выше на этом устройстве.</p>':''}<p class="notificationConnectionsHint">Отключение не меняет смену и звук кабинета. Уже отправленные уведомления могут прийти.</p>`;
    if(focused){const row=connectionsRoot.querySelector(`[data-connection-id="${Number(focused)}"]`);(row?.querySelector('button')||row)?.focus({preventScroll:true});}
  }
  async function loadConnections(){
    resetSessionState();const session=sessionSnapshot();if(!connectionsRoot||session.managerId<=0||!session.csrf||window.WorkspaceV2?.S?.authExpired)return;
    if(connectionRead&&sameSession(connectionsSession))return;
    const sequence=++connectionsSequence;connectionsSession=session;connectionRead=true;renderConnections();
    const current=()=>sequence===connectionsSequence&&sameSession(session);
    try{
      const saved=identity(session);
      const {response,data}=await setupRequest({},'push.php',{method:'POST',headers:{'Content-Type':'application/json'},credentials:'same-origin',cache:'no-store',body:JSON.stringify({action:'connections',csrf:session.csrf,expected_manager_id:session.managerId,...(saved?{installation_token:saved.token}:{}),...(localEndpoint?{endpoint:localEndpoint}:{})})},'push_status_timeout');
      if(!current())return;
      if(response.status===401){connectionRows=[];connectionRead=false;renderConnections('Войдите в кабинет, чтобы увидеть свои подключения.');handleUnauthorized(document.getElementById('notificationStatus'));return;}
      if(!response.ok||!data?.ok||!Array.isArray(data.connections))throw new Error('connections_read_failed');
      connectionRows=data.connections.filter(row=>Number.isSafeInteger(row.id)&&row.id>0&&Number.isSafeInteger(row.revision));connectionRead=false;
      const local=connectionRows.find(row=>row.current);if(local&&acceptConnection(session,local)&&!local.enabled&&!enableInFlight&&!pendingCommands(session).length){browserStatus={notification_path_usable:false,notification_path_reason:'subscription_revoked'};browserStatusSession=session;statusSequence++;recentInteractiveFailure=null;recentFailureSession=null;render(document.getElementById('notificationStatus'),lastStatus);}
      renderConnections(data.truncated?'Показаны первые 100 подключений.':'');
    }catch(error){if(current()){connectionRead=false;renderConnections(error.message==='session_expired'?'Войдите в кабинет, чтобы увидеть свои подключения.':'Не удалось обновить подключения. Проверьте соединение и нажмите «Обновить».');}}
  }
  function openConnections(parent){
    resetSessionState();if(!parent)return;
    if(!connectionsRoot){connectionsRoot=document.createElement('section');connectionsRoot.id='notificationConnections';connectionsRoot.className='notificationConnections';connectionsRoot.setAttribute('aria-label','Браузерные подключения');}
    if(connectionsRoot.parentElement!==parent)parent.appendChild(connectionsRoot);
    void loadConnections();
  }
  async function revokeConnection(id){
    const session=sessionSnapshot(),row=connectionRows.find(item=>item.id===id);if(!row||!sameSession(connectionsSession)||connectionBusy.has(id))return;
    connectionBusy.add(id);connectionErrors.delete(id);renderConnections();
    try{
      const pending=pendingCommands(session);
      if(pending.length)await reconcileCommands(session);
      else{const result=await command(session,'revoke',{connection_id:id,expected_revision:row.revision},{});if(row.current)acceptConnection(session,result.current_connection);}
      requireSession(session);connectionBusy.delete(id);await loadConnections();await refresh();
    }catch(error){if(sameSession(session)){connectionBusy.delete(id);if(error.message==='push_session_expired'){connectionRows=[];renderConnections('Войдите в кабинет, чтобы увидеть свои подключения.');handleUnauthorized(document.getElementById('notificationStatus'));return;}connectionErrors.set(id,error.message==='push_operation_unknown'?'Ответ не получен. Сначала проверьте сохранённый результат.':'Не удалось подтвердить отключение. Обновите список и проверьте доступ.');renderConnections();}}
  }
  function handleSetupClick(event){
    const target=event.target;if(!target||typeof target.closest!=='function')return;
    const connection=target.closest('[data-revoke-connection]');if(connection){event.preventDefault();void revokeConnection(Number(connection.dataset.revokeConnection));return;}
    if(target.closest('[data-connections-refresh]')){event.preventDefault();void loadConnections();return;}
    const button=target.closest('[data-enable-push]');if(!button)return;event.preventDefault();void enable(document.getElementById('notificationStatus'));
  }
  document.addEventListener('click',handleSetupClick);
  // Navigation belongs to the workspace even while native push setup is pending
  // or unavailable. Bind once at module load, independently of repeated init().
  try{navigator.serviceWorker?.addEventListener('message',event=>{const data=event.data||{};if(data.type==='OPEN_CONVERSATION')window.WorkspaceV2?.openNotificationConversation?.(Number(data.conversationId));});}catch(error){/* A restricted worker must not prevent the workspace from loading. */}
  async function init(){if(initialized)return;initialized=true;if(!('serviceWorker' in navigator))return;try{const registered=await withTimeout(registeredWorker(),10000,'service_worker_register_timeout');await readyRegistration(registered);}catch(error){console.warn('Workspace V2 service worker registration failed',error);}}
  window.WorkspaceV2Notifications={refresh,init,enable,maybeRecoverGranted,readyRegistration,openConnections};
  if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',refresh,{once:true});else refresh();
})();
