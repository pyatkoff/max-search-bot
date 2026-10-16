(function(){
  let initialized=false,enableInFlight=false,interactiveEnableInFlight=false,autoRecoveryAttempted=false,lastStatus=null,activationFlight=null,recentInteractiveFailure=null,subscriptionFlight=null,workerRegistrationFlight=null,permissionFlight=null,recentFailureSession=null,autoRecoverySession=null,lastStatusSession=null,browserStatus=null,browserStatusSession=null,statusSequence=0;
  function esc(value){const div=document.createElement('div');div.textContent=value??'';return div.innerHTML;}
  function reasonText(reason){return {healthy_subscription:'Уведомления включены',no_subscription:'Уведомления не подключены',subscription_unhealthy:'Уведомления требуют внимания',subscription_table_missing:'Уведомления недоступны',manager_inactive:'Профиль менеджера неактивен',managers_table_missing:'Статус уведомлений недоступен',health_check_failed:'Не удалось проверить уведомления',session_expired:'Войдите в кабинет',enable_failed:'Не удалось включить уведомления',enable_timeout:'Подключение не завершилось',permission_denied:'Разрешение на уведомления не выдано',unsupported_push:'Этот браузер не поддерживает уведомления',service_worker_failed:'Не удалось подготовить уведомления',subscription_pending:'Браузер ещё подключает уведомления',subscription_failed:'Браузер не подключил уведомления',server_setup_failed:'Не удалось завершить подключение уведомлений'}[reason]||'Статус уведомлений неизвестен';}
  function isSetupFailure(reason){return ['enable_failed','enable_timeout','permission_denied','unsupported_push','service_worker_failed','subscription_pending','subscription_failed','server_setup_failed'].includes(String(reason||''));}
  function setupFailureReason(error,stage=''){const message=String(error?.message||'');if(message==='unsupported_push')return'unsupported_push';if(message==='subscribe_timeout'&&stage==='subscription_create')return'subscription_pending';if(message.includes('timeout'))return'enable_timeout';if(message.startsWith('service_worker_'))return'service_worker_failed';if(message.startsWith('subscription_')||message.startsWith('subscribe_')||message.startsWith('unsubscribe_'))return'subscription_failed';if(message.startsWith('push_key_')||message.startsWith('push_save_'))return'server_setup_failed';if(stage.startsWith('subscription_'))return'subscription_failed';if(stage.startsWith('service_worker_'))return'service_worker_failed';return'enable_failed';}
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
  function requireActiveJob(job){requireSession(job.session);if(job.superseded)throw new Error('subscription_changed_while_pending');}
  function resetSessionState(){
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
    const label='Этот браузер: '+(localLabels[reason]||reasonText(reason)),shift=working===null?'Статус смены неизвестен':working?'Смена включена':'Вне смены';
    const failureDetails={permission_denied:'Разрешите уведомления для сайта в настройках браузера',enable_timeout:'Повторите попытку. Если окно разрешения не появилось — проверьте настройки сайта в браузере',unsupported_push:'На iPhone добавьте кабинет на экран «Домой» и откройте оттуда. В других браузерах откройте кабинет вне Telegram или MAX.',service_worker_failed:'Обновите страницу и повторите. Если не поможет — откройте кабинет в обычном браузере',subscription_pending:'Разрешение получено. Браузер ещё подключает устройство. Можно работать в кабинете — статус обновится автоматически.',subscription_failed:'Проверьте разрешение уведомлений для сайта и повторите',server_setup_failed:'Повторите попытку. Если ошибка останется — сообщите администратору',enable_failed:'Обновите страницу и повторите попытку',browser_check_failed:'Проверка не завершилась. Повторите её или подключите уведомления на этом устройстве.',browser_checking:'Можно продолжать работу в кабинете.'};
    const detail=(reason==='subscription_failed'&&String(status?.setup_diagnostic?.code||'').startsWith('AbortError')?(status.setup_diagnostic.code==='AbortError/PUSH_NETWORK'?'Браузер не смог подключиться к службе уведомлений. Проверьте соединение и повторите.':'Браузер не завершил подключение уведомлений. Повторите попытку.'):failureDetails[reason])||(workingWithoutPush?'Смена без уведомлений на этом устройстве. Новые сообщения могут остаться без быстрого ответа':shift);
    const actionLabel=interactiveEnableInFlight?'Подключаем…':reason==='subscription_pending'?'Проверить':status?.setup_diagnostic?'Повторить':'Включить на этом устройстве',diagnostic=setupFailure?status?.setup_diagnostic:null;
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
  async function recoverSubscription(reg,key,job){
    try{return await reg.pushManager.subscribe({userVisibleOnly:true,applicationServerKey:key});}
    catch(error){
      if(error?.name!=='AbortError')throw error;
      // A browser/provider may finish another registration after the failed call.
      // Re-read it before the only sequential retry; never delete it to retry.
      await new Promise(resolve=>setTimeout(resolve,250));requireActiveJob(job);
      const actual=await reg.pushManager.getSubscription();
      requireActiveJob(job);if(actual){if(compatibleSubscription(actual,key))return actual;throw error;}
      return reg.pushManager.subscribe({userVisibleOnly:true,applicationServerKey:key});
    }
  }
  function subscriptionJob(reg,key){
    if(subscriptionFlight){
      if(!sameSession(subscriptionFlight.session)){subscriptionFlight.superseded=true;throw new Error('subscription_pending_session_change');}
      if(!sameBytes(subscriptionFlight.key,key)||(reg!==subscriptionFlight.reg&&(!reg.scope||reg.scope!==subscriptionFlight.reg.scope))){subscriptionFlight.superseded=true;throw new Error('subscription_changed_while_pending');}
      return subscriptionFlight;
    }
    const job={stage:'subscription_wait',httpStatus:0,observers:new Set(),promise:null,reg,key,superseded:false,session:sessionSnapshot()};
    const run=async()=>{
      requireActiveJob(job);subscriptionStage(job,'subscription_read');
      let sub=await reg.pushManager.getSubscription();requireActiveJob(job);
      let unhealthy=currentPushUnhealthy();
      if(sub){
        // Read this endpoint even before the page probe has completed. Another
        // healthy browser cannot mask it, and another tab may have repaired it
        // while this tab waited for the origin lock.
        const {response,data}=await setupRequest(job,'push-status.php',{method:'POST',headers:{'Content-Type':'application/json',Accept:'application/json'},credentials:'same-origin',cache:'no-store',body:JSON.stringify({csrf:job.session.csrf,endpoint:sub.endpoint||sub.toJSON().endpoint})},'subscription_health_timeout');
        if(response.status===401)throw new Error('push_session_expired');
        if(!response.ok||!data?.ok||!['healthy_subscription','no_subscription','subscription_unhealthy'].includes(data.current_subscription?.notification_path_reason))throw new Error('subscription_health_failed');
        unhealthy=String(data.current_subscription.notification_path_reason||'')==='subscription_unhealthy';
      }
      requireActiveJob(job);if(sub&&(unhealthy||!compatibleSubscription(sub,key))){
        subscriptionStage(job,'subscription_repair');await sub.unsubscribe();sub=null;
      }
      requireActiveJob(job);if(!sub){subscriptionStage(job,'subscription_create');sub=await recoverSubscription(reg,key,job);}
      requireActiveJob(job);
      subscriptionStage(job,'push_save');
      const {response:save,data:saved}=await setupRequest(job,'push.php',{method:'POST',headers:{'Content-Type':'application/json'},credentials:'same-origin',body:JSON.stringify({action:'subscribe',csrf:job.session.csrf,subscription:sub.toJSON()})},'push_save_timeout');
      requireActiveJob(job);if(save.status===401)throw new Error('push_session_expired');
      if(!save.ok||!saved?.ok||!saved.push_status)throw new Error('push_save_failed');
      if(!saved.push_status.notification_path_usable||!saved.current_subscription?.notification_path_usable)throw new Error('push_save_unhealthy');
      browserStatus=saved.current_subscription;browserStatusSession=job.session;statusSequence++;
      return {...saved.push_status,current_subscription:saved.current_subscription};
    };
    // The lock belongs to the real operation, not its UI timeout. A retry in this
    // page observes this same job; another tab re-reads after the lock is released.
    job.promise=Promise.resolve().then(()=>navigator.locks?.request?navigator.locks.request('anytour-manager-push-subscription',run):run());
    subscriptionFlight=job;
    job.promise.then(()=>{if(subscriptionFlight===job)subscriptionFlight=null;},()=>{if(subscriptionFlight===job)subscriptionFlight=null;});
    return job;
  }
  function observeSubscription(job){
    return new Promise((resolve,reject)=>{
      let timer,finished=false;
      const cleanup=()=>{finished=true;clearTimeout(timer);job.observers.delete(arm);};
      const arm=()=>{clearTimeout(timer);const stage=job.stage,label={subscription_wait:'subscription_wait_timeout',subscription_read:'subscription_read_timeout',subscription_repair:'unsubscribe_timeout',subscription_create:'subscribe_timeout',push_save:'push_save_timeout'}[stage];timer=setTimeout(()=>{cleanup();reject(new Error(label));},stage==='subscription_create'?15000:10000);};
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
    const attempt={session:activationSession,promise:null,promote(nextRoot,confirmation){if(interactive)return;root=nextRoot;interactive=true;showConfirmation=confirmation;interactiveEnableInFlight=true;showBusy();}};
    activationFlight=attempt;if(interactive)showBusy();
    attempt.promise=(async()=>{
      let finalStatus=null,setupStage='support',setupHttpStatus=0,job=null;
      try{
        if(!('serviceWorker'in navigator)||!('PushManager'in window)||!('Notification'in window))throw new Error('unsupported_push');
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
        const key=b64ToUint8(keyJson.public_key);setupStage='subscription_wait';job=subscriptionJob(reg,key);
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
  function enable(root){return reconcile(root,{requestPermission:true,showConfirmation:true,interactive:true});}
  function maybeRecoverGranted(root){if(autoRecoverySession&&!sameSession(autoRecoverySession))autoRecoveryAttempted=false;if(autoRecoveryAttempted||enableInFlight||recentInteractiveFailure||!['no_subscription','subscription_unhealthy','healthy_subscription'].includes(String(browserStatus?.notification_path_reason||''))||!('Notification'in window)||Notification.permission!=='granted'||!recoverablePushStatus())return false;autoRecoveryAttempted=true;autoRecoverySession=sessionSnapshot();void reconcile(root,{requestPermission:false,showConfirmation:false,interactive:false});return true;}
  async function probeBrowser(session){
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
      return{endpoint,registration:reg};
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
      if(local.endpoint){
        const checked=await setupRequest({},'push-status.php',{method:'POST',headers:{'Content-Type':'application/json',Accept:'application/json'},credentials:'same-origin',cache:'no-store',body:JSON.stringify({csrf:refreshSession.csrf,endpoint:local.endpoint})},'push_status_timeout');
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
        browserStatus=Notification.permission==='granted'?checked.data.current_subscription:{notification_path_usable:false,notification_path_reason:Notification.permission==='denied'?'permission_denied':'no_subscription'};
      }else browserStatus=local;
      browserStatusSession=refreshSession;display();
      // Preserve the existing account-level once-only recovery policy. A new
      // local probe never expands it and UNKNOWN must never start native writes.
      maybeRecoverGranted(root);
    }catch(error){if(!current())return;browserStatus={notification_path_usable:false,notification_path_reason:'browser_check_failed'};browserStatusSession=refreshSession;render(root,recentInteractiveFailure||withCurrentWorking({notification_path_usable:false,notification_path_reason:'health_check_failed'}));}
  }
  function handleSetupClick(event){const target=event.target,button=target&&typeof target.closest==='function'?target.closest('[data-enable-push]'):null;if(!button)return;event.preventDefault();enable(document.getElementById('notificationStatus'));}
  document.addEventListener('click',handleSetupClick);
  async function init(){if(initialized)return;initialized=true;if(!('serviceWorker' in navigator))return;try{const registered=await withTimeout(registeredWorker(),10000,'service_worker_register_timeout');const ready=await readyRegistration(registered);ready.active?.postMessage({type:'SYNC_PUSH'});navigator.serviceWorker.addEventListener('message',event=>{const data=event.data||{};if(data.type==='OPEN_CONVERSATION'&&Number(data.conversationId||0)>0){window.WorkspaceV2Conversation?.open(Number(data.conversationId));}});}catch(error){console.warn('Workspace V2 service worker registration failed',error);}}
  window.WorkspaceV2Notifications={refresh,init,enable,maybeRecoverGranted,readyRegistration};
  if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',refresh,{once:true});else refresh();
})();
