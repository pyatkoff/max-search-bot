<?php
$baseDir=dirname(__DIR__);
require_once $baseDir.'/config.php';
require_once $baseDir.'/services/ManagerRequestContext.php';
require_once $baseDir.'/services/ManagerPushService.php';
ManagerRequestContext::startSession();
header('Content-Type: application/json; charset=utf-8');header('Cache-Control: no-store');
$id=ManagerRequestContext::managerId();$m=ManagerRequestContext::manager();if(!$m){http_response_code(401);echo json_encode(['ok'=>false,'error'=>'unauthorized']);exit;}
$data=ManagerRequestContext::jsonBody();
$action=(string)($data['action']??$_GET['action']??'key');
try{
 if($action==='key'){echo json_encode(['ok'=>true,'public_key'=>ManagerPushService::publicKey()]);exit;}
 if($action==='subscribe'){$ok=ManagerPushService::saveSubscription($id,(array)($data['subscription']??[]),(string)($_SERVER['HTTP_USER_AGENT']??''));echo json_encode(['ok'=>$ok]);exit;}
 http_response_code(400);echo json_encode(['ok'=>false,'error'=>'unknown_action']);
}catch(Throwable $e){http_response_code(500);echo json_encode(['ok'=>false,'error'=>'push_error']);}
