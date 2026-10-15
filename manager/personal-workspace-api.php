<?php

declare(strict_types=1);

require_once dirname(__DIR__).'/config.php';
require_once __DIR__.'/lib/ManagerHttp.php';
require_once dirname(__DIR__).'/services/ManagerPersonalWorkspaceService.php';

ManagerHttp::startJson();
$manager=ManagerHttp::requireManager();
if (($_SERVER['REQUEST_METHOD']??'')!=='POST') ManagerHttp::respond(['ok'=>false,'error'=>'method_not_allowed'],405);
$data=ManagerHttp::body();
ManagerHttp::requireCsrf($data);
$actor=(int)$manager['id'];$action=$data['action']??'';
if (!is_string($action)) ManagerHttp::respond(['ok'=>false,'error'=>'unknown_action'],400);
try {
    $result=match($action) {
        'snapshot'=>ManagerPersonalWorkspaceService::snapshot($actor),
        'list'=>ManagerPersonalWorkspaceService::list($actor,$data),
        'operation'=>ManagerPersonalWorkspaceService::operation($actor,is_string($data['operation_id']??null)?$data['operation_id']:''),
        default=>ManagerPersonalWorkspaceService::mutate($actor,$action,$data),
    };
} catch (Throwable $e) {
    // No transcript, personal preference names or request body in public logs.
    error_log('MANAGER_PERSONAL_WORKSPACE_FAILURE '.get_class($e));
    ManagerHttp::respond(['ok'=>false,'error'=>'storage_unavailable'],503);
}
$status=!empty($result['ok'])?200:match($result['error']??'') {
    'unauthorized'=>401,'forbidden'=>403,'not_found'=>404,
    'conflict','operation_mismatch'=>409,'unknown_action'=>400,default=>422,
};
ManagerHttp::respond($result,$status);
