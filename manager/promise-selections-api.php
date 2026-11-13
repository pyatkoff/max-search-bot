<?php
declare(strict_types=1);

require_once dirname(__DIR__).'/config.php';
require_once __DIR__.'/lib/ManagerHttp.php';
require_once dirname(__DIR__).'/services/ManagerPromiseSelectionService.php';

ManagerHttp::startJson();
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') ManagerHttp::respond(['ok'=>false,'error'=>'method_not_allowed'],405);
$data = ManagerHttp::body();
if (ManagerHttp::managerId() > 0) {
    try { ProjectAccessService::initializeReadOnly(); }
    catch (Throwable $ignored) { ManagerHttp::respond(['ok'=>false,'error'=>'project_unavailable'],503); }
}
$manager = ManagerHttp::requireManager();
ManagerHttp::requireCsrf($data);
$actor = (int)$manager['id'];
$cid = $data['conversation_id'] ?? null;
$action = $data['action'] ?? null;
if (!is_int($cid) || $cid <= 0) ManagerHttp::respond(['ok'=>false,'error'=>'invalid_conversation_id'],422);
if (!is_string($action) || !in_array($action,['catalog','preview','attach','cancel','operation','status'],true)) {
    ManagerHttp::respond(['ok'=>false,'error'=>'unknown_action'],400);
}
$command = $data;
unset($command['action'],$command['conversation_id'],$command['csrf']);
if (($action === 'catalog' && $command !== [])
    || ($action === 'preview' && (count($command) !== 2 || !is_string($command['promise_id'] ?? null) || !is_string($command['publication_id'] ?? null)))
    || ($action === 'operation' && (count($command) !== 1 || !is_string($command['operation_id'] ?? null)))
    || ($action === 'status' && (count($command) !== 1 || !is_string($command['intent_id'] ?? null)))) {
    ManagerHttp::respond(['ok'=>false,'error'=>'invalid_command'],422);
}

// These bounded private operations perform no network/provider work. Keep the
// canonical session lock through the operation; logout/account changes cannot
// replace its identity between authorization and the private response.
try {
    $result = match ($action) {
        'catalog' => ManagerPromiseSelectionService::catalog($actor,$cid),
        'preview' => ManagerPromiseSelectionService::preview($actor,$cid,$command['promise_id'],$command['publication_id']),
        'attach' => ManagerPromiseSelectionService::attach($actor,$cid,$command),
        'cancel' => ManagerPromiseSelectionService::cancel($actor,$cid,$command),
        'operation' => ManagerPromiseSelectionService::operation($actor,$cid,$command['operation_id']),
        'status' => ManagerPromiseSelectionService::status($actor,$cid,$command['intent_id']),
    };
} catch (Throwable $error) {
    error_log('MANAGER_PROMISE_SELECTION_FAILURE '.get_class($error));
    ManagerHttp::respond(['ok'=>false,'error'=>'storage_unavailable'],503);
}
$status = !empty($result['ok']) ? 200 : match ($result['error'] ?? '') {
    'unauthorized' => 401,
    'forbidden' => 403,
    'not_found','task_missing','task_not_found','publication_not_found','promise_not_found' => 404,
    'operation_mismatch','preview_changed','context_changed','profile_changed','source_changed',
    'task_changed','task_closed','binding_conflict','intent_conflict','already_attached','profile_required',
    'delivery_started','duplicate_cards','scope_changed','profile_source_changed','source_unavailable' => 409,
    'storage_unavailable','schema_unavailable' => 503,
    default => 422,
};
ManagerHttp::respond($result + ['viewer_id'=>$actor,'conversation_id'=>$cid],$status);
