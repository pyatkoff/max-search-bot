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
// Only this observation route has a small body contract; existing push commands
// retain their canonical body parsing and receipt behavior.
if(($_GET['action']??null)==='setup_diagnostic'){
    if(($_SERVER['REQUEST_METHOD']??'GET')!=='POST')ManagerHttp::respond(['ok'=>false,'error'=>'method'],405);
    require_once $baseDir.'/services/ManagerPushSetupDiagnostics.php';
    $raw=file_get_contents('php://input',false,null,0,ManagerPushSetupDiagnostics::MAX_BODY_BYTES+1);
    if($raw===false||strlen($raw)>ManagerPushSetupDiagnostics::MAX_BODY_BYTES)ManagerHttp::respond(['ok'=>false,'error'=>'diagnostic_body_too_large'],413);
    $report=json_decode($raw,true);if(!is_array($report))ManagerHttp::respond(['ok'=>false,'error'=>'invalid_diagnostic'],422);
    ManagerHttp::requireCsrf($report);
    if(!isset($report['expected_manager_id'])||$report['expected_manager_id']!==$managerId)ManagerHttp::respond(['ok'=>false,'error'=>'session_changed'],403);
    if(($report['action']??null)!=='setup_diagnostic')ManagerHttp::respond(['ok'=>false,'error'=>'invalid_diagnostic'],422);
    $event=ManagerPushSetupDiagnostics::project($report['diagnostic']??null,$managerId,(string)($_SERVER['HTTP_USER_AGENT']??''));
    if($event===null)ManagerHttp::respond(['ok'=>false,'error'=>'invalid_diagnostic'],422);
    $accepted=ManagerPushSetupDiagnostics::claim($_SESSION,time());
    // Persist the rate claim before releasing the same session to other tabs.
    if(session_status()===PHP_SESSION_ACTIVE)session_write_close();
    if($accepted)ManagerPushSetupDiagnostics::write($event);
    ManagerHttp::respond(['ok'=>true,'accepted'=>$accepted,'limited'=>!$accepted]);
}
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
