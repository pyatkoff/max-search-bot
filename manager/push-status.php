<?php
session_name('anytour_manager_panel');
session_set_cookie_params(['lifetime'=>60*60*12,'path'=>'/max-search/manager/','secure'=>true,'httponly'=>true,'samesite'=>'Lax']);
session_start();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$managerId=(int)($_SESSION['manager_id']??0);
if($managerId<=0){http_response_code(401);echo json_encode(['ok'=>false,'error'=>'unauthorized']);exit;}

$baseDir=dirname(__DIR__);
require_once $baseDir.'/config.php';
require_once $baseDir.'/services/ConversationDb.php';
require_once $baseDir.'/services/ManagerPushHealth.php';

$status=ManagerPushHealth::statusForManager(ConversationDb::connection(),$managerId);
echo json_encode(['ok'=>true,'push_status'=>$status],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
