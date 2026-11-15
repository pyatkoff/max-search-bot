<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/config.php';
require_once __DIR__.'/lib/ManagerHttp.php';
require_once dirname(__DIR__).'/services/ManagerPromiseCalculationService.php';

ManagerHttp::startJson();
if(($_SERVER['REQUEST_METHOD']??'')!=='POST')ManagerHttp::respond(['ok'=>false,'error'=>'method_not_allowed'],405);
$data=ManagerHttp::body();
if(ManagerHttp::managerId()>0){
    try{ProjectAccessService::initializeReadOnly();}
    catch(Throwable $e){ManagerHttp::respond(['ok'=>false,'error'=>'project_unavailable'],503);}
}
$manager=ManagerHttp::requireManager();ManagerHttp::requireCsrf($data);$actor=(int)$manager['id'];
if(!is_int($data['expected_manager_id']??null)||$data['expected_manager_id']!==$actor)ManagerHttp::respond(['ok'=>false,'error'=>'actor_changed'],409);
$cid=$data['conversation_id']??null;$action=$data['action']??null;
if(!is_int($cid)||$cid<=0)ManagerHttp::respond(['ok'=>false,'error'=>'invalid_conversation_id'],422);
if(!is_string($action)||!in_array($action,['catalog','preview','arm','operation','status','cancel','recover'],true))ManagerHttp::respond(['ok'=>false,'error'=>'unknown_action'],400);
$command=$data;unset($command['action'],$command['csrf'],$command['conversation_id'],$command['expected_manager_id']);
if(($action==='catalog'&&$command!==[])
    ||($action==='preview'&&(count($command)!==3||!is_int($command['task_id']??null)||!is_string($command['promise_id']??null)||!is_array($command['composition']??null)))
    ||($action==='operation'&&(count($command)!==1||!is_string($command['operation_id']??null)))
    ||(in_array($action,['status','recover'],true)&&(count($command)!==1||!is_string($command['intent_id']??null))))
    ManagerHttp::respond(['ok'=>false,'error'=>'invalid_input'],422);
// No provider or transport work. Hold the ordinary session through identity and response.
try{
    $result=match($action){
        'catalog'=>ManagerPromiseCalculationService::catalog($actor,$cid),
        'preview'=>ManagerPromiseCalculationService::preview($actor,$cid,$command['task_id'],$command['promise_id'],$command['composition']),
        'arm'=>ManagerPromiseCalculationService::arm($actor,$cid,$command),
        'operation'=>ManagerPromiseCalculationService::operation($actor,$cid,$command['operation_id']),
        'status'=>ManagerPromiseCalculationService::status($actor,$cid,$command['intent_id']),
        'cancel'=>ManagerPromiseCalculationService::cancel($actor,$cid,$command),
        'recover'=>ManagerPromiseCalculationService::recover($actor,$cid,$command['intent_id']),
    };
}catch(Throwable $e){$result=['ok'=>false,'error'=>'storage_unavailable'];}
$status=!empty($result['ok'])?200:match($result['error']??''){
    'forbidden'=>403,'not_found','task_missing'=>404,'storage_unavailable'=>503,
    'actor_changed','operation_mismatch','preview_changed','context_changed','profile_changed','source_changed','source_unavailable',
    'task_changed','task_closed','scope_changed','intent_conflict','already_completed','part_unresolved','profile_required','profile_source_changed'=>409,
    default=>422,
};
ManagerHttp::respond($result+['viewer_id'=>$actor,'conversation_id'=>$cid],$status);
