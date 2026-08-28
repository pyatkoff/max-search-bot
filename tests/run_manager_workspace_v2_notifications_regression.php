<?php

declare(strict_types=1);

$root=dirname(__DIR__);
$workspace=(string)file_get_contents($root.'/manager/workspace-v2.php');
$js=(string)file_get_contents($root.'/manager/assets/workspace-v2-notifications.js');
$css=(string)file_get_contents($root.'/manager/assets/workspace-v2-notifications.css');
$endpoint=(string)file_get_contents($root.'/manager/push-status.php');
$http=(string)file_get_contents($root.'/manager/lib/ManagerHttp.php');
$health=(string)file_get_contents($root.'/services/ManagerPushHealth.php');
$passed=0;$failed=0;
function nCheck(string $name,bool $ok):void{global$passed,$failed;if($ok){echo "PASS  {$name}\n";$passed++;return;}echo "FAIL  {$name}\n";$failed++;}
function nAssetLoaded(string $html,string $file):bool{return strpos($html,'assets/'.$file)!==false||strpos($html,"workspaceAsset('{$file}')")!==false;}

nCheck('workspace loads dedicated notifications module',nAssetLoaded($workspace,'workspace-v2-notifications.css')&&nAssetLoaded($workspace,'workspace-v2-notifications.js')&&strpos($workspace,'id="notificationStatus"')!==false);
nCheck('notification module is read-only health UI',strpos($js,"fetch('push-status.php'")!==false&&strpos($js,'notification_path_usable')!==false&&strpos($js,'healthy_subscription_count')!==false&&strpos($js,"href=\"push-enable.php\"")!==false&&strpos($js,'is_working')!==false);
nCheck('notification module never mutates manager shift',strpos($js,'is_working=')===false&&strpos($js,'set_working')===false&&strpos($js,"fetch('push.php'")===false);
nCheck('notification auth expiry never navigates or reloads workspace',strpos($js,"response.status===401")!==false&&strpos($js,'handleUnauthorized(root)')!==false&&strpos($js,'workspace.S.authExpired=true')!==false&&strpos($js,"showFatal?.('Сессия менеджера истекла.')")!==false&&strpos($js,"location.href=")===false&&strpos($js,'location.replace(')===false&&strpos($js,'location.assign(')===false&&strpos($js,'location.reload(')===false);
nCheck('notification auth expiry is visible without pretending push failure',strpos($js,"session_expired:'Сессия менеджера истекла'")!==false&&strpos($js,"notification_path_reason:'session_expired'")!==false);
nCheck('notification endpoint uses shared Manager HTTP/context boundary',strpos($endpoint,"require_once __DIR__.'/lib/ManagerHttp.php'")!==false&&strpos($endpoint,'ManagerHttp::requireManager()')!==false&&strpos($endpoint,'ManagerHttp::managerId()')!==false&&strpos($endpoint,'ManagerPushHealth::statusForManager')!==false&&strpos($http,'ManagerRequestContext::startSession()')!==false&&strpos($http,'return ManagerRequestContext::managerId();')!==false);
nCheck('health service keeps working and reachable separate',strpos($health,"'is_working'")!==false&&strpos($health,"'notification_path_usable'")!==false&&strpos($health,"'notification_path_reason'")!==false);
nCheck('notification UI has desktop and mobile styles',strpos($css,'.notificationHealth')!==false&&strpos($css,'.notificationAction')!==false&&strpos($css,'@media(max-width:900px)')!==false&&strpos($css,'@media(max-width:520px)')!==false);
nCheck('warning states offer explicit setup path',strpos($js,"no_subscription:'Уведомления не подключены'")!==false&&strpos($js,"subscription_unhealthy:'Уведомления требуют внимания'")!==false&&strpos($js,'Настроить')!==false);

echo "\n--------------------------\nTOTAL ".($passed+$failed)." | PASS {$passed} | FAIL {$failed}\n";
exit($failed?1:0);
