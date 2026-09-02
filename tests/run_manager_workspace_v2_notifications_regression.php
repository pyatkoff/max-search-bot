<?php

declare(strict_types=1);

$root=dirname(__DIR__);
$workspace=(string)file_get_contents($root.'/manager/index.php');
$js=(string)file_get_contents($root.'/manager/assets/workspace-v2-notifications.js');
$css=(string)file_get_contents($root.'/manager/assets/workspace-v2-notifications.css');
$endpoint=(string)file_get_contents($root.'/manager/push-status.php');
$http=(string)file_get_contents($root.'/manager/lib/ManagerHttp.php');
$health=(string)file_get_contents($root.'/services/ManagerPushHealth.php');
$passed=0;$failed=0;
function nCheck(string $name,bool $ok):void{global$passed,$failed;if($ok){echo "PASS  {$name}\n";$passed++;return;}echo "FAIL  {$name}\n";$failed++;}
function nAssetLoaded(string $html,string $file):bool{return strpos($html,'assets/'.$file)!==false||strpos($html,"workspaceAsset('{$file}')")!==false;}

nCheck('workspace loads dedicated notifications module',nAssetLoaded($workspace,'workspace-v2-notifications.css')&&nAssetLoaded($workspace,'workspace-v2-notifications.js')&&strpos($workspace,'id="notificationStatus"')!==false);
nCheck('notification module renders health and inline setup action',strpos($js,"fetch('push-status.php'")!==false&&strpos($js,'notification_path_usable')!==false&&strpos($js,'healthy_subscription_count')!==false&&strpos($js,'data-enable-push')!==false&&strpos($js,'is_working')!==false);
nCheck('push setup stays inside workspace instead of navigating to setup page',strpos($js,'href="push-enable.php"')===false&&strpos($js,"location.href=")===false&&strpos($js,'location.replace(')===false&&strpos($js,'location.assign(')===false&&strpos($js,'location.reload(')===false);
nCheck('inline push setup requests permission and persists subscription',strpos($js,'Notification.requestPermission()')!==false&&strpos($js,"navigator.serviceWorker.register('sw.js'")!==false&&strpos($js,"fetch('push.php?action=key'")!==false&&strpos($js,"fetch('push.php',{method:'POST'")!==false&&strpos($js,'reg.pushManager.subscribe')!==false&&strpos($js,"action:'subscribe'")!==false);
nCheck('inline push setup repairs stale VAPID subscription',strpos($js,'reg.pushManager.getSubscription()')!==false&&strpos($js,'sameBytes(sub.options.applicationServerKey,key)')!==false&&strpos($js,'sub.unsubscribe()')!==false);
nCheck('inline push setup recreates backend-confirmed unhealthy subscription',strpos($js,'function currentPushUnhealthy()')!==false&&strpos($js,"notification_path_reason||'')==='subscription_unhealthy'")!==false&&strpos($js,'currentPushUnhealthy()||')!==false&&strpos($js,'sub.unsubscribe()')!==false&&strpos($js,'sub=null')!==false);
nCheck('notification module never mutates manager shift',strpos($js,'set_working')===false&&strpos($js,"action:'set_working'")===false);
nCheck('notification auth expiry delegates to canonical recovery without navigation',strpos($js,"response.status===401")!==false&&strpos($js,'handleUnauthorized(root)')!==false&&strpos($js,'window.WorkspaceV2?.showAuthRecovery?.()')!==false&&strpos($js,'workspace.S.authExpired=true')===false&&strpos($js,'showFatal')===false);
nCheck('notification auth expiry is visible without pretending push failure',strpos($js,"session_expired:'Сессия менеджера истекла'")!==false&&strpos($js,"notification_path_reason:'session_expired'")!==false);
nCheck('notification health failures do not pretend manager is off shift',strpos($js,"Статус смены неизвестен")!==false&&strpos($js,"notification_path_reason:'health_check_failed',is_working:false")===false&&strpos($js,"hasOwnProperty.call(status,'is_working')")!==false);
nCheck('push setup preserves backend shift state instead of inventing working status',strpos($js,'lastStatus=data.push_status||{}')!==false&&strpos($js,'function currentWorking()')!==false&&strpos($js,'function withCurrentWorking(status)')!==false&&strpos($js,'is_working:true')===false);
nCheck('push setup transient and failure states reuse preserved shift state',strpos($js,"render(root,withCurrentWorking({notification_path_usable:false,notification_path_reason:'no_subscription'}))")!==false&&strpos($js,"finalStatus=withCurrentWorking({notification_path_usable:false,notification_path_reason:'permission_denied'})")!==false&&strpos($js,"finalStatus=withCurrentWorking({notification_path_usable:true,notification_path_reason:'healthy_subscription',healthy_subscription_count:1})")!==false&&strpos($js,"render(root,withCurrentWorking({notification_path_usable:false,notification_path_reason:'health_check_failed'}))")!==false);
nCheck('working manager without push has explicit lead-risk state',strpos($js,"workingWithoutPush=working===true&&!usable")!==false&&strpos($js,"'Смена без уведомлений'")!==false&&strpos($js,"'Новые лиды могут остаться без быстрого ответа'")!==false&&strpos($js,"workingWithoutPush?'critical':'warn'")!==false&&strpos($js,"workingWithoutPush?'Включить':'Настроить'")!==false);
nCheck('push setup reports denied failed and timeout states inline',strpos($js,"permission_denied:'Разрешение на уведомления не выдано'")!==false&&strpos($js,"enable_failed:'Не удалось включить уведомления'")!==false&&strpos($js,"enable_timeout:'Подключение не завершилось'")!==false&&strpos($js,"Подключаем…")!==false);
nCheck('push setup cannot remain permanently busy after an error',strpos($js,'finally{enableInFlight=false;if(finalStatus)render(root,finalStatus)')!==false&&strpos($js,'setTimeout(()=>refresh(),500)')!==false);
nCheck('push setup bounds browser and network waits',strpos($js,'function withTimeout(')!==false&&strpos($js,"'permission_timeout'")!==false&&strpos($js,"'service_worker_ready_timeout'")!==false&&strpos($js,"'subscribe_timeout'")!==false&&strpos($js,"'push_save_timeout'")!==false);
nCheck('off-shift push warning remains non-critical',strpos($js,"workingWithoutPush=working===true&&!usable")!==false&&strpos($js,"working?'Смена включена':'Вне смены'")!==false);
nCheck('notification endpoint uses shared Manager HTTP/context boundary',strpos($endpoint,"require_once __DIR__.'/lib/ManagerHttp.php'")!==false&&strpos($endpoint,'ManagerHttp::requireManager()')!==false&&strpos($endpoint,'ManagerHttp::managerId()')!==false&&strpos($endpoint,'ManagerPushHealth::statusForManager')!==false&&strpos($http,'ManagerRequestContext::startSession()')!==false&&strpos($http,'return ManagerRequestContext::managerId();')!==false);
nCheck('health service keeps working and reachable separate',strpos($health,"'is_working'")!==false&&strpos($health,"'notification_path_usable'")!==false&&strpos($health,"'notification_path_reason'")!==false);
nCheck('notification UI has desktop and mobile styles',strpos($css,'.notificationHealth')!==false&&strpos($css,'.notificationAction')!==false&&strpos($css,'@media(max-width:900px)')!==false&&strpos($css,'@media(max-width:520px)')!==false);
nCheck('inline setup button has neutral button styling and busy state',strpos($css,'background:transparent')!==false&&strpos($css,'.notificationAction:disabled')!==false&&strpos($css,'cursor:wait')!==false);
nCheck('critical push warning is visually distinct',strpos($css,'.notificationHealth.critical')!==false&&strpos($css,'.notificationHealth.critical .notificationDot')!==false&&strpos($css,'.notificationHealth.critical .notificationText small')!==false);
nCheck('critical explanation stays visible on small screens',strpos($css,'@media(max-width:520px)')!==false&&strpos($css,'.notificationHealth.critical .notificationText small{display:block}')!==false);
nCheck('warning states offer explicit setup path',strpos($js,"no_subscription:'Уведомления не подключены'")!==false&&strpos($js,"subscription_unhealthy:'Уведомления требуют внимания'")!==false&&strpos($js,'Настроить')!==false);

echo "\n--------------------------\nTOTAL ".($passed+$failed)." | PASS {$passed} | FAIL {$failed}\n";
exit($failed?1:0);