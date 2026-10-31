<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/config.php';
require_once __DIR__.'/lib/ManagerHttp.php';
require_once dirname(__DIR__).'/services/ManagerRequestProfileService.php';
ManagerHttp::startJson();
if(($_SERVER['REQUEST_METHOD']??'')!=='POST')ManagerHttp::respond(['ok'=>false,'error'=>'method_not_allowed'],405);
try{
    ProjectAccessService::initializeReadOnly();$manager=ManagerHttp::requireManager();$input=ManagerHttp::body();$actor=(int)$manager['id'];
    if(($input['action']??'')==='identity')ManagerHttp::respond(['ok'=>true,'viewer_id'=>$actor,'csrf'=>ManagerHttp::csrf(true)]);
    ManagerHttp::requireCsrf($input);$cid=$input['conversation_id']??0;
    if(!is_int($cid)||$cid<=0)ManagerHttp::respond(['ok'=>false,'error'=>'invalid_conversation_id'],422);
    session_write_close();
    $result=match($input['action']??''){
        'get'=>ManagerRequestProfileService::read($actor,$cid),
        'save'=>ManagerRequestProfileService::save($actor,$cid,$input),
        'operation'=>ManagerRequestProfileService::operation($actor,$cid,is_string($input['operation_id']??null)?$input['operation_id']:''),
        default=>['ok'=>false,'error'=>'unknown_action']};
    if(!session_start(['read_and_close'=>true])||ManagerHttp::managerId()!==$actor||!ManagerRequestContext::validCsrf((string)$input['csrf']))ManagerHttp::respond(['ok'=>false,'error'=>'unauthorized'],401);
    $status=!empty($result['ok'])?200:match($result['error']??''){'forbidden'=>403,'conflict','context_changed','operation_mismatch'=>409,'profile_unavailable'=>503,default=>422};
    ManagerHttp::respond($result+['viewer_id'=>$actor],$status);
}catch(Throwable $ignored){ManagerHttp::respond(['ok'=>false,'error'=>'profile_unavailable'],503);}
