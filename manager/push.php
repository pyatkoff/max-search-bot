<?php
session_name('anytour_manager_panel');
session_set_cookie_params(['lifetime'=>60*60*12,'path'=>'/max-search/manager/','secure'=>true,'httponly'=>true,'samesite'=>'Lax']);
session_start();
$baseDir=dirname(__DIR__);
require_once $baseDir.'/config.php';
require_once $baseDir.'/services/ManagerAuthService.php';
require_once $baseDir.'/services/ManagerPushService.php';
header('Content-Type: application/json; charset=utf-8');header('Cache-Control: no-store');
$id=(int)($_SESSION['manager_id']??0);$m=$id?ManagerAuthService::byId($id):null;if(!$m){http_response_code(401);echo json_encode(['ok'=>false,'error'=>'unauthorized']);exit;}
$raw=(string)file_get_contents('php://input');$data=json_decode($raw,true);if(!is_array($data))$data=[];
$action=(string)($data['action']??$_GET['action']??'key');
try{
 if($action==='key'){echo json_encode(['ok'=>true,'public_key'=>ManagerPushService::publicKey()]);exit;}
 if($action==='subscribe'){$ok=ManagerPushService::saveSubscription($id,(array)($data['subscription']??[]),(string)($_SERVER['HTTP_USER_AGENT']??''));echo json_encode(['ok'=>$ok]);exit;}
 http_response_code(400);echo json_encode(['ok'=>false,'error'=>'unknown_action']);
}catch(Throwable $e){http_response_code(500);echo json_encode(['ok'=>false,'error'=>'push_error']);}
