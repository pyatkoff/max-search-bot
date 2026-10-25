<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/config.php';
require_once __DIR__.'/lib/ManagerHttp.php';
require_once dirname(__DIR__).'/services/ManagerClientEventService.php';

ManagerHttp::startJson();$manager=ManagerHttp::requireManager();
if (($_SERVER['REQUEST_METHOD']??'')!=='POST') ManagerHttp::respond(['ok'=>false,'error'=>'method_not_allowed'],405);
$data=ManagerHttp::body();ManagerHttp::requireCsrf($data);
$id=$data['conversation_id']??0;
if (!is_int($id)||$id<=0) ManagerHttp::respond(['ok'=>false,'error'=>'invalid_conversation_id'],422);
if (($data['action']??'')!=='list') ManagerHttp::respond(['ok'=>false,'error'=>'unknown_action'],400);
// No writes or session changes below: a slow secondary view must not serialize
// the same account's transcript, composer or notification requests.
if (session_status()===PHP_SESSION_ACTIVE) session_write_close();
try {$result=ManagerClientEventService::list((int)$manager['id'],$id,$data);}
catch (Throwable $e) {error_log('MANAGER_CLIENT_EVENTS_FAILURE '.get_class($e));ManagerHttp::respond(['ok'=>false,'error'=>'storage_unavailable'],503);}
$status=!empty($result['ok'])?200:match($result['error']??'') {'unauthorized'=>401,'forbidden'=>403,default=>422};
ManagerHttp::respond($result,$status);
