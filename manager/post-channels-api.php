<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/config.php';
require_once __DIR__.'/lib/ManagerHttp.php';
require_once dirname(__DIR__).'/services/ChannelPostAdminService.php';
require_once dirname(__DIR__).'/services/ChannelPostRuntime.php';

ManagerHttp::startJson();
if (ManagerHttp::managerId() <= 0) ManagerHttp::requireManager();
$action = '';
try {
    ProjectAccessService::initializeReadOnly();
    $manager = ManagerHttp::requireManager();
    ManagerHttp::requireAdmin($manager);
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') ManagerHttp::respond(['ok'=>false,'error'=>'method_not_allowed'],405);
    $data = ManagerHttp::body();
    ManagerHttp::requireCsrf($data);
    $action = (string)($data['action'] ?? '');
    $actor = (int)$manager['id'];
    if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
    $service = new ChannelPostAdminService(null,null,ChannelPostRuntime::botIdentities());
    $result = match ($action) {
        'list'=>$service->snapshot($actor),
        'save'=>$service->save($actor,$data),
        default=>['ok'=>false,'error'=>'unknown_action'],
    };
} catch (Throwable $e) {
    // A failed response may follow a committed save. Reload and compare the
    // configuration; never authorize a blind repeat or expose provider details.
    ManagerHttp::respond(['ok'=>false,'error'=>$action === 'save' ? 'save_outcome_unknown' : 'post_channels_unavailable'],503);
}
$status = !empty($result['ok']) ? 200 : match ((string)($result['error'] ?? '')) {
    'unauthorized'=>401,'forbidden'=>403,'unknown_action'=>400,
    'channel_configuration_conflict','channel_identity_conflict','published_channel_source_immutable'=>409,
    'channel_verification_failed'=>422,default=>422,
};
ManagerHttp::respond($result,$status);
