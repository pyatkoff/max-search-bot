<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/config.php';
require_once __DIR__.'/lib/ManagerHttp.php';
require_once dirname(__DIR__).'/services/ManagerAiUsageService.php';
ManagerHttp::startJson();
if(($_SERVER['REQUEST_METHOD']??'')!=='POST')ManagerHttp::respond(['ok'=>false,'error'=>'method_not_allowed'],405);
try{
    ProjectAccessService::initializeReadOnly();$manager=ManagerHttp::requireManager();ManagerHttp::requireAdmin($manager);
    $actor=(int)$manager['id'];$input=ManagerHttp::body();$action=$input['action']??'';
    if($action==='identity')ManagerHttp::respond(['ok'=>true,'viewer_id'=>$actor,'csrf'=>ManagerHttp::csrf(true)]);
    ManagerHttp::requireCsrf($input);
    if($action==='list'){
        session_write_close();$result=ManagerAiUsageService::read($actor,$input);
        if(!session_start(['read_and_close'=>true])||ManagerHttp::managerId()!==$actor||!ManagerRequestContext::validCsrf((string)$input['csrf']))ManagerHttp::respond(['ok'=>false,'error'=>'unauthorized'],401);
    }elseif($action==='review'){
        if(!is_int($input['message_id']??null)||!is_string($input['case_revision']??null))ManagerHttp::respond(['ok'=>false,'error'=>'invalid_request'],422);
        $result=ManagerAiUsageService::review($actor,$input['message_id'],$input['case_revision']);
    }else ManagerHttp::respond(['ok'=>false,'error'=>'unknown_action'],400);
    $status=!empty($result['ok'])?200:match($result['error']??'unavailable'){'forbidden'=>403,'not_found'=>404,'case_changed','stale_cursor'=>409,'invalid_request'=>422,default=>503};
    ManagerHttp::respond($result+['viewer_id'=>$actor],$status);
}catch(Throwable $ignored){ManagerHttp::respond(['ok'=>false,'error'=>'unavailable'],503);}
