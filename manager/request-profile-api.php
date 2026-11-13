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
    if(($input['action']??'')==='history'){
        foreach(['before_version','snapshot_version'] as $key)if(array_key_exists($key,$input)&&(!is_int($input[$key])||$input[$key]<0))ManagerHttp::respond(['ok'=>false,'error'=>'invalid_history_cursor'],422);
    }
    if(($input['action']??'')==='history_source'&&(!is_int($input['version']??null)||!is_string($input['field']??null)||!is_string($input['side']??null)))ManagerHttp::respond(['ok'=>false,'error'=>'invalid_history_source'],422);
    $result=match($input['action']??''){
        'get'=>ManagerRequestProfileService::read($actor,$cid),
        'history'=>ManagerRequestProfileService::historyPage($actor,$cid,$input['before_version']??0,$input['snapshot_version']??0),
        'history_source'=>ManagerRequestProfileService::historySource($actor,$cid,$input['version'],$input['field'],$input['side']),
        'save'=>ManagerRequestProfileService::save($actor,$cid,$input),
        'operation'=>ManagerRequestProfileService::operation($actor,$cid,is_string($input['operation_id']??null)?$input['operation_id']:''),
        default=>['ok'=>false,'error'=>'unknown_action']};
    if(!session_start(['read_and_close'=>true])||ManagerHttp::managerId()!==$actor||!ManagerRequestContext::validCsrf((string)$input['csrf']))ManagerHttp::respond(['ok'=>false,'error'=>'unauthorized'],401);
    $status=!empty($result['ok'])?200:match($result['error']??''){'forbidden'=>403,'conflict','context_changed','operation_mismatch'=>409,'profile_unavailable','history_unavailable'=>503,default=>422};
    ManagerHttp::respond($result+['viewer_id'=>$actor],$status);
}catch(Throwable $ignored){ManagerHttp::respond(['ok'=>false,'error'=>'profile_unavailable'],503);}
