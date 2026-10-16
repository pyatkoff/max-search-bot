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
        ]);
    }
    if($action==='subscribe'){
        ManagerHttp::requireCsrf($data);
        $subscription=(array)($data['subscription']??[]);
        $ok=ManagerPushService::saveSubscription(
            $managerId,
            $subscription,
            (string)($_SERVER['HTTP_USER_AGENT']??'')
        );
        if(!$ok){
            ManagerHttp::respond(['ok'=>false],409);
        }
        $pdo=ConversationDb::connection();
        $pushStatus=ManagerPushHealth::statusForManager($pdo,$managerId);
        $currentSubscription=ManagerPushHealth::statusForSubscription($pdo,$managerId,(string)($subscription['endpoint']??''));
        ManagerHttp::respond(['ok'=>true,'push_status'=>$pushStatus,'current_subscription'=>$currentSubscription]);
    }
    ManagerHttp::respond(['ok'=>false,'error'=>'unknown_action'],400);
}catch(Throwable $e){
    ManagerHttp::respond(['ok'=>false,'error'=>'push_error'],500);
}
