<?php

declare(strict_types=1);

require_once dirname(__DIR__).'/config.php';
require_once __DIR__.'/lib/ManagerHttp.php';
require_once dirname(__DIR__).'/services/ManagerQuickReplyService.php';

ManagerHttp::startJson();
$manager=ManagerHttp::requireManager();
if (($_SERVER['REQUEST_METHOD']??'')!=='POST') ManagerHttp::respond(['ok'=>false,'error'=>'method_not_allowed'],405);
$data=ManagerHttp::body();
ManagerHttp::requireCsrf($data);
$actor=(int)$manager['id'];$admin=ManagerHttp::isAdmin($manager);
$action=(string)($data['action']??'');
try {
    if ($action==='list') $result=ManagerQuickReplyService::list($actor,$admin,$data);
    elseif ($action==='toolbar') $result=ManagerQuickReplyService::toolbar($actor,$admin);
    elseif ($action==='operation') $result=ManagerQuickReplyService::operation($actor,(string)($data['operation_id']??''));
    else $result=ManagerQuickReplyService::mutate($actor,$admin,$action,$data);
} catch (Throwable $e) {
    // No content, account token or reply body is logged. A lost write response
    // must be reconciled by operation_id instead of starting another command.
    error_log('MANAGER_QUICK_REPLY_FAILURE '.get_class($e));
    ManagerHttp::respond(['ok'=>false,'error'=>'storage_unavailable'],503);
}
$error=(string)($result['error']??'');
$status=!empty($result['ok'])?200:match($error) {
    'unauthorized'=>401,'forbidden'=>403,'not_found'=>404,
    'conflict','operation_mismatch'=>409,'unknown_action'=>400,default=>422,
};
ManagerHttp::respond($result,$status);
