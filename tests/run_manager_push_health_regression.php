<?php

declare(strict_types=1);

$base=dirname(__DIR__);
$service=(string)file_get_contents($base.'/services/ManagerPushHealth.php');
$push=(string)file_get_contents($base.'/services/ManagerPushService.php');
$snapshot=(string)file_get_contents($base.'/tools/production_snapshot.php');
$sw=(string)file_get_contents($base.'/manager/sw.js');
$enable=(string)file_get_contents($base.'/manager/push-enable.php');
$pushEndpoint=(string)file_get_contents($base.'/manager/push.php');
$statusEndpoint=(string)file_get_contents($base.'/manager/push-status.php');
$http=(string)file_get_contents($base.'/manager/lib/ManagerHttp.php');
$context=(string)file_get_contents($base.'/services/ManagerRequestContext.php');
$panel=(string)file_get_contents($base.'/manager/index.php')."\n".(string)file_get_contents($base.'/manager/assets/workspace-v2-notifications.js');
$passed=0;$failed=0;
function mphCheck(string $name,bool $ok):void{global $passed,$failed;if($ok){echo "PASS  {$name}\n";$passed++;return;}echo "FAIL  {$name}\n";$failed++;}

mphCheck('push health scopes to active working managers',strpos($service,'is_active=1 AND is_working=1')!==false);
mphCheck('push health exposes missing subscriptions',strpos($service,"'missing_subscription_manager_ids'")!==false && strpos($service,"'subscription_count'")!==false);
mphCheck('push health exposes latest send errors',strpos($service,"'recent_error_manager_ids'")!==false && strpos($service,'last_error_at')!==false && strpos($service,"'last_error'")!==false);
mphCheck('push health fails soft and exposes diagnostic error',strpos($service,'catch (Throwable $e)')!==false && strpos($service,"'error' => get_class(\$e)")!==false);
mphCheck('push health explicitly exposes unusable working-manager notification paths',strpos($service,"'unusable_notification_path_manager_ids'")!==false && strpos($service,"'working_manager_notification_path_ok'")!==false);
mphCheck('push health gives per-manager notification path usability',strpos($service,"'notification_path_usable'")!==false && strpos($service,"'notification_path_reason'")!==false);
mphCheck('push path distinguishes missing, unhealthy and healthy subscription',strpos($service,"'no_subscription'")!==false && strpos($service,"'subscription_unhealthy'")!==false && strpos($service,"'healthy_subscription'")!==false);
mphCheck('push health can report one manager regardless of working state',strpos($service,'statusForManager(PDO $pdo, int $managerId)')!==false && strpos($service,"'is_working'")!==false);
mphCheck('production snapshot includes manager push health',strpos($snapshot,"ManagerPushHealth.php")!==false && strpos($snapshot,"'manager_push_health'")!==false && strpos($snapshot,"'manager_push_ok'")!==false);
mphCheck('push delivery logs missing selected manager subscription',strpos($push,"'no_subscription'")!==false && strpos($push,"'conversation_id'=>\$conversationId")!==false && strpos($push,"'manager_id'=>(int)\$managerId")!==false);
mphCheck('push delivery logs successful subscription send',strpos($push,"'delivery_success'")!==false && strpos($push,"'subscription_id'=>\$subscriptionId")!==false && strpos($push,"'http_code'=>\$code")!==false);
mphCheck('push delivery logs failed and expired sends',strpos($push,"'delivery_failed'")!==false && strpos($push,"'subscription_expired'")!==false && strpos($push,"'delivery_exception'")!==false);
mphCheck('production snapshot exposes recent per-conversation push evidence',strpos($snapshot,"'recent_manager_push_events'")!==false && strpos($snapshot,"recentStructuredComponentEvents")!==false && strpos($snapshot,"'manager_push',50")!==false);
mphCheck('service worker repairs server push subscription',strpos($sw,'syncPushSubscription')!==false && strpos($sw,"push.php?action=key")!==false && strpos($sw,"action:'subscribe'")!==false);
mphCheck('service worker repairs subscription on activation',strpos($sw,"self.addEventListener('activate'")!==false && strpos($sw,'await syncPushSubscription()')!==false);
mphCheck('service worker replaces stale VAPID subscription',strpos($sw,'applicationServerKey')!==false && strpos($sw,'await sub.unsubscribe()')!==false);
mphCheck('push enable replaces stale VAPID subscription',strpos($enable,'applicationServerKey')!==false && strpos($enable,'await sub.unsubscribe()')!==false && strpos($enable,"action:'subscribe'")!==false);
mphCheck('push surfaces reuse shared manager HTTP/context boundary',strpos($pushEndpoint,"require_once __DIR__.'/lib/ManagerHttp.php'")!==false&&strpos($statusEndpoint,"require_once __DIR__.'/lib/ManagerHttp.php'")!==false&&strpos($enable,"require_once __DIR__.'/lib/ManagerHttp.php'")!==false&&strpos($pushEndpoint,'ManagerHttp::requireManager()')!==false&&strpos($statusEndpoint,'ManagerHttp::requireManager()')!==false&&strpos($enable,'ManagerHttp::start()')!==false&&strpos($enable,'ManagerHttp::managerId()')!==false&&strpos($enable,'ManagerRequestContext::')===false&&strpos($http,'ManagerRequestContext::startSession()')!==false&&strpos($http,'ManagerRequestContext::manager()')!==false&&strpos($context,"session_name('anytour_manager_panel')")!==false);
mphCheck('authenticated push status endpoint uses current manager through shared boundary',strpos($statusEndpoint,'ManagerHttp::managerId()')!==false && strpos($statusEndpoint,'ManagerPushHealth::statusForManager')!==false && strpos($http,'return ManagerRequestContext::managerId();')!==false);
$readsShift=strpos($panel,"hasOwnProperty.call(status,'is_working')")!==false&&strpos($panel,'!!status.is_working')!==false;
$separatesReachability=strpos($panel,"usable=!!status?.notification_path_usable")!==false&&strpos($panel,"workingWithoutPush=working===true&&!usable")!==false&&strpos($panel,"root.className='notificationHealth '+(usable?'ok':workingWithoutPush?'critical':'warn')")!==false;
$honestUnknown=strpos($panel,"working===null?'Статус смены неизвестен':working?'Смена включена':'Вне смены'")!==false&&strpos($panel,"notification_path_reason:'health_check_failed',is_working:false")===false&&strpos($panel,"notification_path_reason:'session_expired',is_working:false")===false;
mphCheck('Manager workspace keeps shift and push reachability independent',$readsShift&&$separatesReachability&&$honestUnknown);
mphCheck('Manager workspace offers explicit push repair path',strpos($panel,'class="notificationAction" href="push-enable.php"')!==false && strpos($panel,"usable?'':")!==false && strpos($panel,"workingWithoutPush?'Включить':'Настроить'")!==false);
$healthyLabel=strpos($panel,"healthy_subscription:'Уведомления включены'")!==false;
$healthyPath=strpos($panel,"usable=!!status?.notification_path_usable")!==false;
$criticalOverride=strpos($panel,"label=workingWithoutPush?'Смена без уведомлений':reasonText(reason)")!==false;
mphCheck('Manager workspace only claims notifications enabled for healthy server path',$healthyLabel&&$healthyPath&&$criticalOverride);

$total=$passed+$failed;
echo "\n--------------------------\nTOTAL {$total} | PASS {$passed} | FAIL {$failed}\n";
exit($failed?1:0);
