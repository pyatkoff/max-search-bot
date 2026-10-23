<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/config.php';
require_once __DIR__.'/lib/ManagerHttp.php';
require_once dirname(__DIR__).'/services/ManagerTourSelectionService.php';
require_once dirname(__DIR__).'/services/ManagerTourSelectionDeliveryService.php';
require_once dirname(__DIR__).'/services/ManagerTourSelectionShareService.php';
require_once dirname(__DIR__).'/services/ManagerTourSelectionCollectorSession.php';
ManagerHttp::startJson(); $manager=ManagerHttp::requireManager();
if (($_SERVER['REQUEST_METHOD']??'')!=='POST') ManagerHttp::respond(['ok'=>false,'error'=>'method_not_allowed'],405);
$data=ManagerHttp::body(); ManagerHttp::requireCsrf($data);
$collectorSession=hash('sha256',session_id());
session_write_close();
$id=$data['conversation_id']??null; $action=$data['action']??null;
if (!is_int($id) || $id<=0) ManagerHttp::respond(['ok'=>false,'error'=>'invalid_conversation_id'],422);
try {
    $result=match($action) {
        'collector_get'=>ManagerTourSelectionCollectorSession::view((int)$manager['id'],$id,$collectorSession,is_string($data['device_id']??null)?$data['device_id']:''),
        'collector_connect'=>ManagerTourSelectionCollectorSession::connect((int)$manager['id'],$id,$collectorSession,$data),
        'collector_revoke'=>ManagerTourSelectionCollectorSession::revoke((int)$manager['id'],$id,$collectorSession),
        'collector_import'=>ManagerTourSelectionService::import((int)$manager['id'],$id,$collectorSession,$data),
        'collector_operation'=>ManagerTourSelectionService::importOperation((int)$manager['id'],$id,$collectorSession,$data),
        'get'=>ManagerTourSelectionService::get((int)$manager['id'],$id),
        'save'=>ManagerTourSelectionService::save((int)$manager['id'],$id,$data),
        'operation'=>ManagerTourSelectionService::operation((int)$manager['id'],$id,is_string($data['operation_id']??null)?$data['operation_id']:''),
        'prepare'=>is_int($data['version']??null)?ManagerTourSelectionDeliveryService::prepare((int)$manager['id'],$id,$data['version']):['ok'=>false,'error'=>'invalid_version'],
        'history'=>ManagerTourSelectionDeliveryService::history((int)$manager['id'],$id),
        'share'=>ManagerTourSelectionShareService::get((int)$manager['id'],$id),
        'publish','revoke'=>is_int($data['share_version']??null)?ManagerTourSelectionShareService::change((int)$manager['id'],$id,$data['share_version'],is_string($data['publication_id']??null)?$data['publication_id']:'',$action==='publish'):['ok'=>false,'error'=>'invalid_version'],
        'publication'=>ManagerTourSelectionDeliveryService::get((int)$manager['id'],$id,is_string($data['publication_id']??null)?$data['publication_id']:''),
        'send'=>is_int($data['card_index']??null)?ManagerTourSelectionDeliveryService::send((int)$manager['id'],$id,is_string($data['publication_id']??null)?$data['publication_id']:'',$data['card_index']):['ok'=>false,'error'=>'invalid_card'],
        default=>['ok'=>false,'error'=>'unknown_action'],
    };
} catch (Throwable $e) {
    error_log('MANAGER_TOUR_SELECTION_FAILURE '.get_class($e));
    ManagerHttp::respond(['ok'=>false,'error'=>'storage_unavailable'],503);
}
$status=!empty($result['ok'])?200:match($result['error']??'') {'unauthorized'=>401,'forbidden'=>403,'not_found'=>404,'conflict','operation_mismatch','share_changed','trip_changed','connection_changed','source_price_changed','ambiguous_offer'=>409,'unknown_action'=>400,default=>422};
ManagerHttp::respond($result,$status);
