<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/config.php';
require_once __DIR__.'/lib/ManagerHttp.php';
require_once dirname(__DIR__).'/services/ManagerInternalNoteService.php';
ManagerHttp::startJson();$manager=ManagerHttp::requireManager();
if (($_SERVER['REQUEST_METHOD']??'')!=='POST') ManagerHttp::respond(['ok'=>false,'error'=>'method_not_allowed'],405);
$data=ManagerHttp::body();ManagerHttp::requireCsrf($data);
$id=$data['conversation_id']??0;$action=$data['action']??'';
if (!is_int($id)||$id<=0) ManagerHttp::respond(['ok'=>false,'error'=>'invalid_conversation_id'],422);
if (!is_string($action)) ManagerHttp::respond(['ok'=>false,'error'=>'unknown_action'],400);
try {
    $result=match($action) {
        'list'=>ManagerInternalNoteService::list((int)$manager['id'],$id,$data),
        'operation'=>ManagerInternalNoteService::operation((int)$manager['id'],$id,is_string($data['operation_id']??null)?$data['operation_id']:''),
        default=>ManagerInternalNoteService::mutate((int)$manager['id'],$id,$action,$data),
    };
} catch (Throwable $e) {
    // Staff text, identities and request bodies never enter public diagnostics.
    error_log('MANAGER_INTERNAL_NOTE_FAILURE '.get_class($e));ManagerHttp::respond(['ok'=>false,'error'=>'storage_unavailable'],503);
}
$status=!empty($result['ok'])?200:match($result['error']??'') {'unauthorized'=>401,'forbidden'=>403,'not_found'=>404,'conflict','operation_mismatch','deleted'=>409,'unknown_action'=>400,default=>422};
ManagerHttp::respond($result,$status);
