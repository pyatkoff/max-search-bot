<?php

declare(strict_types=1);

$root=dirname(__DIR__);
$http=(string)file_get_contents($root.'/manager/lib/ManagerHttp.php');
$push=(string)file_get_contents($root.'/manager/push.php');
$status=(string)file_get_contents($root.'/manager/push-status.php');
$mediaUpload=(string)file_get_contents($root.'/manager/media-upload.php');
$pipeline=(string)file_get_contents($root.'/manager/pipeline-api.php');

$passed=0;$failed=0;
function mhCheck(string $name,bool $ok):void{global $passed,$failed;if($ok){echo "PASS  {$name}\n";$passed++;return;}echo "FAIL  {$name}\n";$failed++;}

mhCheck('HTTP boundary owns session and JSON headers',strpos($http,'ManagerRequestContext::startSession()')!==false&&strpos($http,"Content-Type: application/json")!==false&&strpos($http,"Cache-Control: no-store")!==false);
mhCheck('HTTP boundary owns JSON body lookup',strpos($http,'return ManagerRequestContext::jsonBody();')!==false);
mhCheck('HTTP boundary owns manager authorization',strpos($http,"'error'=>'unauthorized'")!==false&&strpos($http,'self::respond(')!==false);
mhCheck('HTTP boundary exposes CSRF and admin guards for incremental migration',strpos($http,'function requireCsrf')!==false&&strpos($http,'ManagerRequestContext::validCsrf')!==false&&strpos($http,'function requireAdmin')!==false&&strpos($http,'ManagerRequestContext::isAdmin')!==false);
mhCheck('HTTP boundary owns assigned conversation authorization',strpos($http,'function canEditConversation')!==false&&strpos($http,'ManagerRequestContext::canEditAssignedConversation')!==false&&strpos($http,'function requireConversationEdit')!==false&&strpos($http,"'error'=>'forbidden'")!==false);
mhCheck('CSRF guard validates existing session token without minting one',strpos($http,'ManagerRequestContext::csrf(true)')===false&&strpos($http,'ManagerRequestContext::validCsrf($token)')!==false);
mhCheck('HTTP boundary owns consistent JSON response flags',strpos($http,'JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES')!==false);
mhCheck('push endpoint delegates HTTP lifecycle',strpos($push,"require_once __DIR__.'/lib/ManagerHttp.php'")!==false&&strpos($push,'ManagerHttp::startJson();')!==false&&strpos($push,'ManagerHttp::requireManager();')!==false&&strpos($push,'ManagerRequestContext::')===false);
mhCheck('push status endpoint delegates HTTP lifecycle',strpos($status,"require_once __DIR__.'/lib/ManagerHttp.php'")!==false&&strpos($status,'ManagerHttp::startJson();')!==false&&strpos($status,'ManagerHttp::requireManager();')!==false&&strpos($status,'ManagerRequestContext::')===false);
mhCheck('media upload delegates auth csrf and JSON response lifecycle',strpos($mediaUpload,"require_once __DIR__.'/lib/ManagerHttp.php'")!==false&&strpos($mediaUpload,'ManagerHttp::startJson();')!==false&&strpos($mediaUpload,'ManagerHttp::requireManager();')!==false&&strpos($mediaUpload,'ManagerHttp::requireCsrf($_POST);')!==false&&strpos($mediaUpload,'ManagerRequestContext::')===false&&strpos($mediaUpload,'function mediaOut')===false);
mhCheck('pipeline API delegates auth csrf response and conversation authorization',strpos($pipeline,"require_once __DIR__.'/lib/ManagerHttp.php'")!==false&&strpos($pipeline,'ManagerHttp::startJson();')!==false&&strpos($pipeline,'ManagerHttp::body();')!==false&&strpos($pipeline,'ManagerHttp::requireManager();')!==false&&strpos($pipeline,'ManagerHttp::requireCsrf($data);')!==false&&strpos($pipeline,'ManagerHttp::canEditConversation(')!==false&&strpos($pipeline,'ManagerHttp::requireConversationEdit(')!==false&&strpos($pipeline,'ManagerRequestContext::')===false&&strpos($pipeline,'function pipelineOut')===false);
mhCheck('pipeline business services stay outside HTTP helper',strpos($pipeline,'SalesPipelineService::')!==false&&strpos($pipeline,'LeadTaskService::')!==false&&strpos($http,'SalesPipelineService')===false&&strpos($http,'LeadTaskService')===false);
mhCheck('business services remain outside HTTP helper',strpos($http,'ManagerPushService')===false&&strpos($http,'ManagerPushHealth')===false&&strpos($http,'ManagerOutboundService')===false&&strpos($push,'ManagerPushService::')!==false&&strpos($status,'ManagerPushHealth::')!==false&&strpos($mediaUpload,'ManagerOutboundService::')!==false);

$total=$passed+$failed;
echo "\n--------------------------\nTOTAL {$total} | PASS {$passed} | FAIL {$failed}\n";
exit($failed?1:0);
