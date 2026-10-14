<?php

declare(strict_types=1);
require_once dirname(__DIR__).'/config.php';
require_once __DIR__.'/lib/ManagerHttp.php';
require_once dirname(__DIR__).'/services/ManagerCustomerNameService.php';

ManagerHttp::startJson();
$manager=ManagerHttp::requireManager();
if (($_SERVER['REQUEST_METHOD']??'')!=='POST') ManagerHttp::respond(['ok'=>false,'error'=>'method_not_allowed'],405);
$data=ManagerHttp::body();ManagerHttp::requireCsrf($data);
$actor=(int)$manager['id'];$conversationId=(int)($data['conversation_id']??0);$action=(string)($data['action']??'');
try {
    $result=match($action) {
        'get'=>ManagerCustomerNameService::get($actor,$conversationId,$data),
        'save'=>ManagerCustomerNameService::save($actor,$conversationId,$data),
        'operation'=>ManagerCustomerNameService::operation($actor,$conversationId,(string)($data['operation_id']??'')),
        default=>['ok'=>false,'error'=>'unknown_action'],
    };
} catch (Throwable $e) {
    error_log('MANAGER_CUSTOMER_NAME_FAILURE '.get_class($e));
    ManagerHttp::respond(['ok'=>false,'error'=>'storage_unavailable'],503);
}
$error=(string)($result['error']??'');
$status=!empty($result['ok'])?200:match($error) {'unauthorized'=>401,'forbidden'=>403,'not_found'=>404,'conflict','operation_mismatch'=>409,'unknown_action'=>400,default=>422};
ManagerHttp::respond($result,$status);
