<?php

declare(strict_types=1);

$root=dirname(__DIR__);
$http=(string)file_get_contents($root.'/manager/lib/ManagerHttp.php');
$api=(string)file_get_contents($root.'/manager/api.php');
$push=(string)file_get_contents($root.'/manager/push.php');
$status=(string)file_get_contents($root.'/manager/push-status.php');
$pushEnable=(string)file_get_contents($root.'/manager/push-enable.php');
$mediaUpload=(string)file_get_contents($root.'/manager/media-upload.php');
$mediaFile=(string)file_get_contents($root.'/manager/media-file.php');
$pipeline=(string)file_get_contents($root.'/manager/pipeline-api.php');
$admin=(string)file_get_contents($root.'/manager/admin.php');
$routing=(string)file_get_contents($root.'/manager/routing.php');

$passed=0;$failed=0;
function mhCheck(string $name,bool $ok):void{global $passed,$failed;if($ok){echo "PASS  {$name}\n";$passed++;return;}echo "FAIL  {$name}\n";$failed++;}

$unbounded=[];
foreach(glob($root.'/manager/*.php')?:[] as $managerEndpoint){
    $source=(string)file_get_contents($managerEndpoint);
    if(strpos($source,"lib/ManagerHttp.php")===false||strpos($source,'ManagerHttp::start')===false)$unbounded[]=basename($managerEndpoint);
}
mhCheck('all top-level Manager PHP interfaces enter through ManagerHttp',$unbounded===[]);

mhCheck('HTTP boundary owns session and JSON headers',strpos($http,'function start()')!==false&&strpos($http,'ManagerRequestContext::startSession()')!==false&&strpos($http,'self::start();')!==false&&strpos($http,"Content-Type: application/json")!==false&&strpos($http,"Cache-Control: no-store")!==false);
mhCheck('legacy manager path redirects to canonical app origin before session bootstrap',strpos($http,"legacyPrefix='/max-search/manager'")!==false&&strpos($http,"target='https://app.anytoour.ru'")!==false&&strpos($http,"header('Location: '.\$target,true,308)")!==false&&strpos($http,'self::redirectLegacyPath();')<strpos($http,'ManagerRequestContext::startSession();'));
mhCheck('HTTP boundary owns JSON body lookup',strpos($http,'return ManagerRequestContext::jsonBody();')!==false);
mhCheck('HTTP boundary owns manager authorization',strpos($http,"'error'=>'unauthorized'")!==false&&strpos($http,'self::respond(')!==false);
mhCheck('HTTP boundary exposes csrf token and csrf/admin guards',strpos($http,'function csrf(bool $rotate=false)')!==false&&strpos($http,'return ManagerRequestContext::csrf($rotate);')!==false&&strpos($http,'function requireCsrf')!==false&&strpos($http,'ManagerRequestContext::validCsrf')!==false&&strpos($http,'function isAdmin')!==false&&strpos($http,'return ManagerRequestContext::isAdmin($manager);')!==false&&strpos($http,'function requireAdmin')!==false&&strpos($http,'if(!self::isAdmin($manager))')!==false);
mhCheck('HTTP boundary owns assigned conversation authorization',strpos($http,'function canEditConversation')!==false&&strpos($http,'ManagerRequestContext::canEditAssignedConversation')!==false&&strpos($http,'function requireConversationEdit')!==false&&strpos($http,"'error'=>'forbidden'")!==false);
mhCheck('CSRF guard validates existing session token without rotating it',strpos($http,'ManagerRequestContext::validCsrf($token)')!==false&&strpos($http,'self::csrf(true)')===false);
mhCheck('HTTP boundary owns consistent JSON response flags',strpos($http,'JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES')!==false);
mhCheck('main Manager API delegates HTTP and auth boundary',strpos($api,"require_once __DIR__ . '/lib/ManagerHttp.php'")!==false&&strpos($api,'ManagerHttp::startJson();')!==false&&strpos($api,'ManagerHttp::body();')!==false&&strpos($api,'ManagerHttp::requireManager();')!==false&&strpos($api,'ManagerHttp::csrf(true)')!==false&&strpos($api,'ManagerHttp::requireCsrf($data);')!==false&&strpos($api,'ManagerHttp::requireAdmin($m);')!==false&&strpos($api,'ManagerHttp::isAdmin($m);')!==false&&strpos($api,'ManagerRequestContext::')===false);
mhCheck('main Manager API does not recreate shared auth/csrf wrapper helpers',strpos($api,'function body()')===false&&strpos($api,'function manager()')===false&&strpos($api,'function csrf()')===false&&strpos($api,'function requireCsrf(')===false&&strpos($api,'function requireAdmin(')===false&&strpos($api,"if(!$m) out(['ok'=>false,'error'=>'unauthorized']")===false);
mhCheck('main Manager API keeps business actions outside HTTP helper',strpos($api,'ManagerConversationService::')!==false&&strpos($api,'ManagerOutboundService::')!==false&&strpos($api,'ManagerAvailabilityService::')!==false&&strpos($http,'ManagerConversationService')===false&&strpos($http,'ManagerOutboundService')===false&&strpos($http,'ManagerAvailabilityService')===false);
mhCheck('push endpoint delegates HTTP lifecycle and protects subscription writes',strpos($push,"require_once __DIR__.'/lib/ManagerHttp.php'")!==false&&strpos($push,'ManagerHttp::startJson();')!==false&&strpos($push,'ManagerHttp::requireManager();')!==false&&strpos($push,"if(\$action==='subscribe')")!==false&&strpos($push,'ManagerHttp::requireCsrf($data);')!==false&&strpos($push,'ManagerRequestContext::')===false);
mhCheck('push status endpoint delegates HTTP lifecycle',strpos($status,"require_once __DIR__.'/lib/ManagerHttp.php'")!==false&&strpos($status,'ManagerHttp::startJson();')!==false&&strpos($status,'ManagerHttp::requireManager();')!==false&&strpos($status,'ManagerRequestContext::')===false);
mhCheck('push enable page delegates session auth and csrf lifecycle without JSON headers',strpos($pushEnable,"require_once __DIR__.'/lib/ManagerHttp.php'")!==false&&strpos($pushEnable,'ManagerHttp::start();')!==false&&strpos($pushEnable,'ManagerHttp::managerId()')!==false&&strpos($pushEnable,'ManagerHttp::manager()')!==false&&strpos($pushEnable,'ManagerHttp::csrf(true)')!==false&&strpos($pushEnable,'JSON.stringify({action:\'subscribe\',csrf,subscription:sub.toJSON()})')!==false&&strpos($pushEnable,'ManagerRequestContext::')===false&&strpos($pushEnable,'ManagerHttp::startJson();')===false);
mhCheck('media upload delegates auth csrf and JSON response lifecycle',strpos($mediaUpload,"require_once __DIR__.'/lib/ManagerHttp.php'")!==false&&strpos($mediaUpload,'ManagerHttp::startJson();')!==false&&strpos($mediaUpload,'ManagerHttp::requireManager();')!==false&&strpos($mediaUpload,'ManagerHttp::requireCsrf($_POST);')!==false&&strpos($mediaUpload,'ManagerRequestContext::')===false&&strpos($mediaUpload,'function mediaOut')===false);
mhCheck('media preview delegates session and auth lifecycle without JSON headers',strpos($mediaFile,"require_once __DIR__.'/lib/ManagerHttp.php'")!==false&&strpos($mediaFile,'ManagerHttp::start();')!==false&&strpos($mediaFile,'ManagerHttp::requireManager();')!==false&&strpos($mediaFile,'ManagerHttp::managerId();')!==false&&strpos($mediaFile,'ManagerRequestContext::')===false&&strpos($mediaFile,'ManagerHttp::startJson();')===false);
mhCheck('pipeline API delegates auth csrf response and conversation authorization',strpos($pipeline,"require_once __DIR__.'/lib/ManagerHttp.php'")!==false&&strpos($pipeline,'ManagerHttp::startJson();')!==false&&strpos($pipeline,'ManagerHttp::body();')!==false&&strpos($pipeline,'ManagerHttp::requireManager();')!==false&&strpos($pipeline,'ManagerHttp::requireCsrf($data);')!==false&&strpos($pipeline,'ManagerHttp::canEditConversation(')!==false&&strpos($pipeline,'ManagerHttp::requireConversationEdit(')!==false&&strpos($pipeline,'ManagerRequestContext::')===false&&strpos($pipeline,'function pipelineOut')===false);
mhCheck('pipeline list delegates actionable waiting/attention projection to canonical service',strpos($pipeline,"require_once \$baseDir.'/services/ManagerQueueProjectionService.php';")!==false&&strpos($pipeline,'ManagerQueueProjectionService::actionableRows($queue,$rows)')!==false&&strpos($pipeline,"if(in_array($queue,['waiting','attention'],true))")===false);
mhCheck('admin and routing shells delegate session bootstrap to ManagerHttp',strpos($admin,"require_once __DIR__.'/lib/ManagerHttp.php'")!==false&&strpos($admin,'ManagerHttp::start();')!==false&&strpos($admin,'ManagerRequestContext::')===false&&strpos($routing,"require_once __DIR__.'/lib/ManagerHttp.php'")!==false&&strpos($routing,'ManagerHttp::start();')!==false&&strpos($routing,'ManagerRequestContext::')===false);
mhCheck('pipeline business services stay outside HTTP helper',strpos($pipeline,'SalesPipelineService::')!==false&&strpos($pipeline,'LeadTaskService::')!==false&&strpos($http,'SalesPipelineService')===false&&strpos($http,'LeadTaskService')===false);
mhCheck('business services remain outside HTTP helper',strpos($http,'ManagerPushService')===false&&strpos($http,'ManagerPushHealth')===false&&strpos($http,'ManagerOutboundService')===false&&strpos($push,'ManagerPushService::')!==false&&strpos($status,'ManagerPushHealth::')!==false&&strpos($mediaUpload,'ManagerOutboundService::')!==false);
mhCheck('all current Manager write interfaces use shared csrf guard',strpos($api,'ManagerHttp::requireCsrf($data);')!==false&&strpos($pipeline,'ManagerHttp::requireCsrf($data);')!==false&&strpos($mediaUpload,'ManagerHttp::requireCsrf($_POST);')!==false&&strpos($push,'ManagerHttp::requireCsrf($data);')!==false);

$total=$passed+$failed;
echo "\n--------------------------\nTOTAL {$total} | PASS {$passed} | FAIL {$failed}\n";
exit($failed?1:0);
