<?php

declare(strict_types=1);

require_once dirname(__DIR__).'/config.php';
require_once __DIR__.'/lib/ManagerHttp.php';
require_once dirname(__DIR__).'/services/ManagerAssistantService.php';

ManagerHttp::startJson();
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') ManagerHttp::respond(['ok'=>false,'error'=>'method_not_allowed'],405);

function assistantRespond(array $result): never
{
    $status = !empty($result['ok']) ? 200 : match ((string)($result['error'] ?? '')) {
        'unauthorized'=>401, 'forbidden'=>403, 'not_found'=>404,
        'stale_context','decision_conflict','list_changed'=>409, 'invalid_request','unknown_action'=>400, default=>503,
    };
    ManagerHttp::respond($result,$status);
}

try {
    ProjectAccessService::initializeReadOnly();
    $manager = ManagerHttp::requireManager();
    $data = ManagerHttp::body();
    ManagerHttp::requireCsrf($data);
    $actor = (int)$manager['id'];
    $action = $data['action'] ?? '';
    if ($action === 'list') {
        $cursor = $data['cursor'] ?? null;
        $limit = $data['limit'] ?? ManagerAssistantService::MAX_PAGE;
        if (($cursor !== null && (!is_string($cursor) || strlen($cursor)>90)) || !is_int($limit)) assistantRespond(['ok'=>false,'error'=>'invalid_request']);
        session_write_close();
        assistantRespond(ManagerAssistantService::listing($actor,$cursor,$limit));
    }
    if (!in_array($action,['detail','decision'],true)) assistantRespond(['ok'=>false,'error'=>'unknown_action']);
    $cid = $data['conversation_id'] ?? null;
    if (!is_int($cid) || $cid <= 0) assistantRespond(['ok'=>false,'error'=>'invalid_request']);
    if ($action === 'detail') {
        session_write_close();
        assistantRespond(ManagerAssistantService::detail($actor,$cid));
    }
    $revision = $data['revision'] ?? null;
    $decision = $data['decision'] ?? null;
    $expected = $data['expected_decision'] ?? null;
    if (!is_string($revision) || !is_string($decision) || ($expected !== null && !is_string($expected))) assistantRespond(['ok'=>false,'error'=>'invalid_request']);
    // Keep the short same-account session lock through this metadata-only write.
    assistantRespond(ManagerAssistantService::decide($actor,$cid,$revision,$decision,$expected));
} catch (Throwable $ignored) { assistantRespond(['ok'=>false,'error'=>'assistant_unavailable']); }
