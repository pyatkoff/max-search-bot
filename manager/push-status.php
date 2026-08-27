<?php
$baseDir=dirname(__DIR__);
require_once $baseDir.'/config.php';
require_once $baseDir.'/services/ManagerRequestContext.php';
require_once $baseDir.'/services/ConversationDb.php';
require_once $baseDir.'/services/ManagerPushHealth.php';
ManagerRequestContext::startSession();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$managerId=ManagerRequestContext::managerId();
if($managerId<=0||!ManagerRequestContext::manager()){http_response_code(401);echo json_encode(['ok'=>false,'error'=>'unauthorized']);exit;}

$status=ManagerPushHealth::statusForManager(ConversationDb::connection(),$managerId);
echo json_encode(['ok'=>true,'push_status'=>$status],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
