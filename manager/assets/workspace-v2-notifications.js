(function(){
  let initialized=false,enableInFlight=false,interactiveEnableInFlight=false,autoRecoveryAttempted=false,lastStatus=null,activationFlight=null,recentInteractiveFailure=null,subscriptionFlight=null,workerRegistrationFlight=null,recentFailureSession=null,autoRecoverySession=null,lastStatusSession=null;
  function esc(value){const div=document.createElement('div');div.textContent=value??'';return div.innerHTML;}
  function reasonText(reason){return {healthy_subscription:'Уведомления включены',no_subscription:'Уведомления не подключены',subscription_unhealthy:'Уведомления требуют внимания',subscription_table_missing:'Уведомления недоступны',manager_inactive:'Профиль менеджера неактивен',managers_table_missing:'Статус уведомлений недоступен',health_check_failed:'Не удалось проверить уведомления',session_expired:'Войдите в кабинет',enable_failed:'Не удалось включить уведомления',enable_timeout:'Подключение не завершилось',permission_denied:'Разрешение на уведомления не выдано',unsupported_push:'Этот браузер не поддерживает уведомления',service_worker_failed:'Не удалось подготовить уведомления',subscription_failed:'Браузер не подключил уведомления',server_setup_failed:'Не удалось завершить подключение уведомлений'}[reason]||'Статус уведомлений неизвестен';}
  function isSetupFailure(reason){return ['enable_failed','enable_timeout','permission_denied','unsupported_push','service_worker_failed','subscription_failed','server_setup_failed'].includes(String(reason||''));}
  function setupFailureReason(error,stage=''){const message=String(error?.message||'');if(message==='unsupported_push')return'unsupported_push';if(message.includes('timeout'))return'enable_timeout';if(message.startsWith('service_worker_'))return'service_worker_failed';if(message.startsWith('subscription_')||message.startsWith('subscribe_')||message.startsWith('unsubscribe_'))return'subscription_failed';if(message.startsWith('push_key_')||message.startsWith('push_save_'))return'server_setup_failed';if(stage.startsWith('subscription_'))return'subscription_failed';if(stage.startsWith('service_worker_'))return'service_worker_failed';return'enable_failed';}
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
  function currentPushUnhealthy(){return String(lastStatus?.notification_path_reason||'')==='subscription_unhealthy';}
  function recoverablePushStatus(){return ['no_subscription','subscription_unhealthy'].includes(String(lastStatus?.notification_path_reason||''));}
  function csrf(){return String(window.WorkspaceV2?.S?.csrf||'');}
  function sessionSnapshot(){const state=window.WorkspaceV2?.S||{};return{managerId:Number(state.manager?.id||0),generation:Number(state.authGeneration||0),csrf:csrf()};}
  function sameSession(session){const current=sessionSnapshot();return !!session&&session.managerId===current.managerId&&session.generation===current.generation&&session.csrf===current.csrf;}
  function requireSession(session){if(!sameSession(session))throw new Error('push_session_changed');}
  function requireActiveJob(job){requireSession(job.session);if(job.superseded)throw new Error('subscription_changed_while_pending');}
  function resetSessionState(){
    if(lastStatusSession&&!sameSession(lastStatusSession)){lastStatus=null;lastStatusSession=null;}
    if(recentFailureSession&&!sameSession(recentFailureSession)){recentInteractiveFailure=null;recentFailureSession=null;}
    if(autoRecoverySession&&!sameSession(autoRecoverySession))autoRecoveryAttempted=false;
  }
  function withCurrentWorking(status){const working=currentWorking();return working===null?status:{...status,is_working:working};}
  function render(root,status){if(!root)return;const usable=!!status?.notification_path_usable,count=Number(status?.healthy_subscription_count||0),reason=String(status?.notification_path_reason||''),setupFailure=isSetupFailure(reason),hasWorking=status!=null&&Object.prototype.hasOwnProperty.call(status,'is_working'),working=hasWorking?!!status.is_working:null,workingWithoutPush=working===true&&!usable,label=setupFailure?reasonText(reason):workingWithoutPush?'Смена без уведомлений':reasonText(reason),suffix=usable&&count>0?` · ${count} ${count===1?'устройство':'устройства'}`:'',shift=working===null?'Статус смены неизвестен':working?'Смена включена':'Вне смены',failureDetails={permission_denied:'Разрешите уведомления для сайта в настройках браузера',enable_timeout:'Повторите попытку. Если окно разрешения не появилось — проверьте настройки сайта в браузере',unsupported_push:'Откройте кабинет в обычном браузере, не внутри Telegram или MAX',service_worker_failed:'Обновите страницу и повторите. Если не поможет — откройте кабинет в обычном браузере',subscription_failed:'Проверьте разрешение уведомлений для сайта и повторите',server_setup_failed:'Повторите попытку. Если ошибка останется — сообщите администратору',enable_failed:'Обновите страницу и повторите попытку'},detail=(reason==='subscription_failed'&&String(status?.setup_diagnostic?.code||'').startsWith('AbortError')?(status.setup_diagnostic.code==='AbortError/PUSH_NETWORK'?'Браузер не смог подключиться к службе уведомлений. Проверьте соединение и повторите.':'Браузер не завершил подключение уведомлений. Повторите попытку.'):failureDetails[reason])||(workingWithoutPush?'Новые сообщения могут остаться без быстрого ответа':shift+suffix),actionLabel=interactiveEnableInFlight?'Подключаем…':setupFailure?'Повторить':workingWithoutPush?'Включить':'Настроить',diagnostic=setupFailure?status?.setup_diagnostic:null;root.className='notificationHealth '+(usable?'ok':workingWithoutPush?'critical':'warn');root.innerHTML=`<span class="notificationDot" aria-hidden="true"></span><div class="notificationText"><strong>${esc(label)}</strong><small>${esc(detail)}</small>${diagnostic?`<details class="notificationDiagnostic"><summary>Подробности для администратора</summary><div>Этап: ${esc(diagnostic.stage)} · Код: ${esc(diagnostic.code)}</div></details>`:''}</div>${usable?'':`<button type="button" class="notificationAction" data-enable-push ${interactiveEnableInFlight?'disabled':''}>${esc(actionLabel)}</button>`}`;}
  function handleUnauthorized(root){window.WorkspaceV2?.showAuthRecovery?.();render(root,{notification_path_usable:false,notification_path_reason:'session_expired'});}
  async function parseJson(response){return response.json().catch(()=>null);}
  function withTimeout(promise,ms,label){let timer;return Promise.race([promise,new Promise((_,reject)=>{timer=setTimeout(()=>reject(new Error(label||'timeout')),ms);})]).finally(()=>clearTimeout(timer));}
  function registeredWorker(){
    if(workerRegistrationFlight)return workerRegistrationFlight;
    const pending=Promise.resolve().then(()=>navigator.serviceWorker.register('sw.js',{scope:'./'}));
    workerRegistrationFlight=pending;
    pending.then(()=>{if(workerRegistrationFlight===pending)workerRegistrationFlight=null;},()=>{if(workerRegistrationFlight===pending)workerRegistrationFlight=null;});
    return pending;
  }
  async function subscriptionRequest(job,url,options,label){
    const controller=typeof AbortController==='function'?new AbortController():null;
    const pending=(async()=>{const response=await fetch(url,controller?{...options,signal:controller.signal}:options);job.httpStatus=response.status;return{response,data:await parseJson(response)};})();
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
      if(sub&&unhealthy){
        // Another tab may already have renewed the registration while this tab
        // waited for the origin lock. Fresh server health prevents a second repair.
        const {response,data}=await subscriptionRequest(job,'push-status.php',{headers:{Accept:'application/json'},credentials:'same-origin',cache:'no-store'},'subscription_health_timeout');
        if(response.status===401)throw new Error('push_session_expired');
        if(!response.ok||!data?.ok)throw new Error('subscription_health_failed');
        unhealthy=String(data.push_status?.notification_path_reason||'')==='subscription_unhealthy';
      }
      requireActiveJob(job);if(sub&&(unhealthy||!compatibleSubscription(sub,key))){
        subscriptionStage(job,'subscription_repair');await sub.unsubscribe();sub=null;
      }
      requireActiveJob(job);if(!sub){subscriptionStage(job,'subscription_create');sub=await recoverSubscription(reg,key,job);}
      requireActiveJob(job);
      subscriptionStage(job,'push_save');
      const {response:save,data:saved}=await subscriptionRequest(job,'push.php',{method:'POST',headers:{'Content-Type':'application/json'},credentials:'same-origin',body:JSON.stringify({action:'subscribe',csrf:job.session.csrf,subscription:sub.toJSON()})},'push_save_timeout');
      requireActiveJob(job);if(save.status===401)throw new Error('push_session_expired');
      if(!save.ok||!saved?.ok||!saved.push_status)throw new Error('push_save_failed');
      if(!saved.push_status.notification_path_usable)throw new Error('push_save_unhealthy');
      return saved.push_status;
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
  function reconcile(root,{requestPermission=true,showConfirmation=true,interactive=true}={}){
    resetSessionState();if(enableInFlight){
      if(!sameSession(activationFlight.session))return Promise.resolve(false);
      if(interactive)activationFlight.promote(root,showConfirmation);
      return activationFlight.promise;
    }
    const activationSession=sessionSnapshot();enableInFlight=true;interactiveEnableInFlight=interactive;
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
        const permission=Notification.permission==='granted'?'granted':requestPermission?await withTimeout(Notification.requestPermission(),15000,'permission_timeout'):Notification.permission;
        if(permission!=='granted'){if(interactive){finalStatus=withCurrentWorking({notification_path_usable:false,notification_path_reason:'permission_denied'});finalStatus.setup_diagnostic=setupFailureDiagnostic(setupStage,new Error('permission_not_granted'));}return false;}
        setupStage='service_worker_register';const registered=await withTimeout(registeredWorker(),10000,'service_worker_register_timeout');
        setupStage='service_worker_ready';const reg=await withTimeout(navigator.serviceWorker.ready,10000,'service_worker_ready_timeout');
        if(registered.scope&&registered.scope!==reg.scope)throw new Error('service_worker_scope_mismatch');
        setupStage='push_key';const keyResp=await withTimeout(fetch('push.php?action=key',{credentials:'same-origin',cache:'no-store'}),10000,'push_key_timeout'),keyJson=await parseJson(keyResp);
        requireSession(activationSession);setupHttpStatus=keyResp.status;if(keyResp.status===401){handleUnauthorized(root);return false;}
        if(!keyResp.ok||!keyJson?.ok||!keyJson.public_key)throw new Error('push_key_failed');
        const key=b64ToUint8(keyJson.public_key);setupStage='subscription_wait';job=subscriptionJob(reg,key);
        const status=await observeSubscription(job);requireSession(activationSession);lastStatus=status;lastStatusSession=activationSession;finalStatus=lastStatus;recentInteractiveFailure=null;recentFailureSession=null;
        if(showConfirmation){try{await reg.showNotification('AnyTour',{body:'Уведомления включены',tag:'anytour-push-enabled'});}catch(e){}}
        return true;
      }catch(error){
        setupStage=job?.stage||setupStage;setupHttpStatus=job?.httpStatus??setupHttpStatus;
        if(!sameSession(activationSession)||error?.message==='push_session_changed')return false;
        if(error?.message==='push_session_expired'){handleUnauthorized(root);return false;}
        console.warn(interactive?'Workspace V2 push enable failed':'Workspace V2 push recovery failed',error);
        if(interactive){finalStatus=withCurrentWorking({notification_path_usable:false,notification_path_reason:setupFailureReason(error,setupStage)});finalStatus.setup_diagnostic=setupFailureDiagnostic(setupStage,error,setupHttpStatus);}
        return false;
      }finally{enableInFlight=false;interactiveEnableInFlight=false;activationFlight=null;if(sameSession(activationSession)){if(interactive&&finalStatus&&!finalStatus.notification_path_usable&&isSetupFailure(finalStatus.notification_path_reason)){recentInteractiveFailure=finalStatus;recentFailureSession=activationSession;}if(finalStatus)render(root,finalStatus);else if(lastStatus)render(root,lastStatus);}if(interactive&&finalStatus?.notification_path_usable)setTimeout(()=>refresh(),500);}
    })();
    return attempt.promise;
  }
  function enable(root){return reconcile(root,{requestPermission:true,showConfirmation:true,interactive:true});}
  function maybeRecoverGranted(root){if(autoRecoverySession&&!sameSession(autoRecoverySession))autoRecoveryAttempted=false;if(autoRecoveryAttempted||enableInFlight||recentInteractiveFailure||!('Notification'in window)||Notification.permission!=='granted'||!recoverablePushStatus())return false;autoRecoveryAttempted=true;autoRecoverySession=sessionSnapshot();void reconcile(root,{requestPermission:false,showConfirmation:false,interactive:false});return true;}
  async function refresh(){resetSessionState();const refreshSession=sessionSnapshot();const root=document.getElementById('notificationStatus');if(!root)return;try{const response=await fetch('push-status.php',{headers:{Accept:'application/json'},credentials:'same-origin',cache:'no-store'});const data=await parseJson(response);requireSession(refreshSession);if(response.status===401){handleUnauthorized(root);return;}if(!response.ok||!data?.ok)throw new Error('push_status_failed');lastStatus=data.push_status||{};lastStatusSession=refreshSession;if(interactiveEnableInFlight&&sameSession(activationFlight?.session))render(root,withCurrentWorking({notification_path_usable:false,notification_path_reason:'no_subscription'}));else render(root,recentInteractiveFailure?withCurrentWorking(recentInteractiveFailure):lastStatus);maybeRecoverGranted(root);}catch(error){if(!sameSession(refreshSession))return;render(root,recentInteractiveFailure||withCurrentWorking({notification_path_usable:false,notification_path_reason:'health_check_failed'}));}}
  function handleSetupClick(event){const target=event.target,button=target&&typeof target.closest==='function'?target.closest('[data-enable-push]'):null;if(!button)return;event.preventDefault();enable(document.getElementById('notificationStatus'));}
  document.addEventListener('click',handleSetupClick);
  async function init(){if(initialized)return;initialized=true;if(!('serviceWorker' in navigator))return;try{await withTimeout(registeredWorker(),10000,'service_worker_register_timeout');const ready=await withTimeout(navigator.serviceWorker.ready,10000,'service_worker_ready_timeout');ready.active?.postMessage({type:'SYNC_PUSH'});navigator.serviceWorker.addEventListener('message',event=>{const data=event.data||{};if(data.type==='OPEN_CONVERSATION'&&Number(data.conversationId||0)>0){window.WorkspaceV2Conversation?.open(Number(data.conversationId));}});}catch(error){console.warn('Workspace V2 service worker registration failed',error);}}
  window.WorkspaceV2Notifications={refresh,init,enable,maybeRecoverGranted};
  if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',refresh,{once:true});else refresh();
})();
