<?php
$baseDir=dirname(__DIR__);
require_once $baseDir.'/config.php';
require_once __DIR__.'/lib/ManagerHttp.php';
require_once $baseDir.'/services/ManagerPushService.php';
require_once $baseDir.'/services/ConversationDb.php';
require_once $baseDir.'/services/ManagerPushHealth.php';

ManagerHttp::startJson();
ManagerHttp::requireManager();
$managerId=ManagerHttp::managerId();
$data=ManagerHttp::body();
$action=(string)($data['action']??$_GET['action']??'key');

try{
    if($action==='key'){
        ManagerHttp::respond([
            'ok'=>true,
            'public_key'=>ManagerPushService::publicKey(),
            'csrf'=>ManagerHttp::csrf(true),
            'manager_id'=>$managerId,
        ]);
    }
    if(in_array($action,['connections','operation','enable_intent','subscribe','revoke'],true)){
        if(($_SERVER['REQUEST_METHOD']??'GET')!=='POST')ManagerHttp::respond(['ok'=>false,'error'=>'method'],405);
        ManagerHttp::requireCsrf($data);
        if(isset($data['expected_manager_id'])&&$data['expected_manager_id']!==$managerId)ManagerHttp::respond(['ok'=>false,'error'=>'session_changed'],403);
        if(isset($data['operation_id'])&&!isset($data['expected_manager_id']))ManagerHttp::respond(['ok'=>false,'error'=>'session_changed'],403);
        if($action==='connections')$result=ManagerPushConnectionService::connections($managerId,(string)($data['installation_token']??''),(string)($data['endpoint']??''));
        elseif($action==='operation')$result=ManagerPushConnectionService::operation($managerId,(string)($data['operation_id']??''));
        else $result=ManagerPushConnectionService::mutate($managerId,$action,$data,(string)($_SERVER['HTTP_USER_AGENT']??''));
        $http=(int)($result['http_status']??200);unset($result['http_status']);
        if($action!=='subscribe'||empty($result['ok']))ManagerHttp::respond($result,$http);
        $subscription=(array)($data['subscription']??[]);
        $pdo=ConversationDb::connection();
        $pushStatus=ManagerPushHealth::statusForManager($pdo,$managerId);
        $currentSubscription=ManagerPushHealth::statusForSubscription($pdo,$managerId,(string)($subscription['endpoint']??''));
        ManagerHttp::respond($result+['push_status'=>$pushStatus,'current_subscription'=>$currentSubscription]);
    }
    ManagerHttp::respond(['ok'=>false,'error'=>'unknown_action'],400);
}catch(Throwable $e){
    ManagerHttp::respond(['ok'=>false,'error'=>'push_error'],500);
}
