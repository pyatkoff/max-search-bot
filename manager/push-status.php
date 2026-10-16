<?php
$baseDir=dirname(__DIR__);
require_once $baseDir.'/config.php';
require_once __DIR__.'/lib/ManagerHttp.php';
require_once $baseDir.'/services/ConversationDb.php';
require_once $baseDir.'/services/ManagerPushHealth.php';
require_once $baseDir.'/services/ManagerPushConnectionService.php';

ManagerHttp::startJson();
ManagerHttp::requireManager();
$managerId=ManagerHttp::managerId();

$endpoint=null;
$token='';
if(($_SERVER['REQUEST_METHOD']??'GET')==='POST'){
    $data=ManagerHttp::body();
    ManagerHttp::requireCsrf($data);
    $token=is_string($data['installation_token']??null)?$data['installation_token']:'';
    if((!is_string($data['endpoint']??null)||trim($data['endpoint'])==='')&&!preg_match('/\A[a-f0-9]{64}\z/D',$token)){
        ManagerHttp::respond(['ok'=>false,'error'=>'invalid_endpoint'],422);
    }
    $endpoint=is_string($data['endpoint']??null)?trim($data['endpoint']):'';
}

$pdo=ConversationDb::connection();
$status=ManagerPushHealth::statusForManager($pdo,$managerId);
$response=['ok'=>true,'push_status'=>$status];
if($endpoint!==null){
    $response['current_subscription']=ManagerPushHealth::statusForSubscription($pdo,$managerId,$endpoint);
    $response['current_connection']=ManagerPushConnectionService::current($managerId,$token,$endpoint);
    if($response['current_connection']&&!$response['current_connection']['enabled'])$response['current_subscription']=['notification_path_usable'=>false,'notification_path_reason'=>'subscription_revoked'];
}
ManagerHttp::respond($response);
