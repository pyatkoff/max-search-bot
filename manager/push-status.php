<?php
$baseDir=dirname(__DIR__);
require_once $baseDir.'/config.php';
require_once __DIR__.'/lib/ManagerHttp.php';
require_once $baseDir.'/services/ConversationDb.php';
require_once $baseDir.'/services/ManagerPushHealth.php';

ManagerHttp::startJson();
ManagerHttp::requireManager();
$managerId=ManagerHttp::managerId();

$endpoint=null;
if(($_SERVER['REQUEST_METHOD']??'GET')==='POST'){
    $data=ManagerHttp::body();
    ManagerHttp::requireCsrf($data);
    if(!is_string($data['endpoint']??null)||trim($data['endpoint'])===''){
        ManagerHttp::respond(['ok'=>false,'error'=>'invalid_endpoint'],422);
    }
    $endpoint=trim($data['endpoint']);
}

$pdo=ConversationDb::connection();
$status=ManagerPushHealth::statusForManager($pdo,$managerId);
$response=['ok'=>true,'push_status'=>$status];
if($endpoint!==null)$response['current_subscription']=ManagerPushHealth::statusForSubscription($pdo,$managerId,$endpoint);
ManagerHttp::respond($response);
