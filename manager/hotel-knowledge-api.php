<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/config.php';
require_once __DIR__.'/lib/ManagerHttp.php';
require_once dirname(__DIR__).'/services/ManagerHotelKnowledgeReadService.php';
ManagerHttp::startJson();
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') ManagerHttp::respond(['ok'=>false,'error'=>'method_not_allowed'],405);
try {
    ProjectAccessService::initializeReadOnly();
    $manager = ManagerHttp::requireManager();
    $input = ManagerHttp::body();
    ManagerHttp::requireCsrf($input);
    $actor = (int)$manager['id'];
    if (array_key_exists('editor_actor',$input) || array_key_exists('editor_resource',$input)) {
        if (($input['editor_actor']??null) !== $actor || ($input['editor_resource']??null) !== 'hotel-knowledge-api.php') ManagerHttp::respond(['ok'=>false,'error'=>'stale_actor'],409);
        unset($input['editor_actor'],$input['editor_resource']);
    }
    $conversation = $input['conversation_id'] ?? null;
    if (!is_int($conversation) || $conversation <= 0) ManagerHttp::respond(['ok'=>false,'error'=>'invalid_conversation_id'],422);
    $csrf = $input['csrf'];
    if (array_key_exists('action',$input) && $input['action'] !== 'read') ManagerHttp::respond(['ok'=>false,'error'=>'unknown_action'],400);
    unset($input['csrf'], $input['conversation_id'], $input['action']);
    session_write_close();
    $result = ManagerHotelKnowledgeReadService::read($actor, $conversation, $input, static function() use ($actor, $csrf): bool {
        return session_start(['read_and_close'=>true]) && ManagerHttp::managerId() === $actor
            && ManagerRequestContext::validCsrf((string)$csrf);
    });
    $status = !empty($result['ok']) ? 200 : match ($result['error'] ?? '') {
        'unauthorized'=>401, 'forbidden'=>403, 'selection_changed'=>409, 'knowledge_unavailable'=>503, default=>422,
    };
    ManagerHttp::respond($result + ['viewer_id'=>$actor], $status);
} catch (Throwable $ignored) {
    ManagerHttp::respond(['ok'=>false,'error'=>'knowledge_unavailable'],503);
}
