<?php

declare(strict_types=1);

require_once dirname(__DIR__).'/config.php';
require_once __DIR__.'/lib/ManagerHttp.php';
require_once dirname(__DIR__).'/services/ManagerAiDraftContextService.php';
require_once dirname(__DIR__).'/services/ManagerAiDraftService.php';

ManagerHttp::startJson();
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    ManagerHttp::respond(['ok'=>false,'error'=>'method_not_allowed'],405);
}

/** Only fixed error codes cross the HTTP boundary; never provider details. */
function aiDraftRespond(array $result): never
{
    $status = !empty($result['ok']) ? 200 : match ((string)($result['error'] ?? '')) {
        'unauthorized'=>401,
        'forbidden','reply_not_allowed'=>403,
        'not_found'=>404,
        'context_stale','suspended'=>409,
        'invalid_conversation_id','invalid_request','unknown_action'=>400,
        default=>503,
    };
    ManagerHttp::respond($result,$status);
}

try {
    // The normal auth chain has a legacy project UPSERT. This readiness check
    // must precede it, so drafting cannot repair or modify project metadata.
    ProjectAccessService::initializeReadOnly();
    $manager = ManagerHttp::requireManager();
    $data = ManagerHttp::body();
    ManagerHttp::requireCsrf($data);
    $action = $data['action'] ?? '';
    if (!in_array($action,['generate','validate'],true)) {
        aiDraftRespond(['ok'=>false,'error'=>'unknown_action']);
    }
    $cid = $data['conversation_id'] ?? null;
    $expectedLatest = $data['latest_message_id'] ?? null;
    if (!is_int($cid) || $cid <= 0 || !is_int($expectedLatest) || $expectedLatest < 0) {
        aiDraftRespond(['ok'=>false,'error'=>'invalid_request']);
    }
    $actor = (int)$manager['id'];
    $before = ManagerAiDraftContextService::snapshot($cid,$actor);
    if (empty($before['ok'])) aiDraftRespond($before);
    if ($expectedLatest !== $before['latest_message_id']) {
        aiDraftRespond(['ok'=>false,'error'=>'context_stale']);
    }
    if ($action === 'validate') {
        $revision = $data['revision'] ?? null;
        if (!is_string($revision) || !preg_match('/^[a-f0-9]{64}$/D',$revision)) {
            aiDraftRespond(['ok'=>false,'error'=>'invalid_request']);
        }
        if (!hash_equals($before['revision'],$revision)) {
            aiDraftRespond(['ok'=>false,'error'=>'context_stale']);
        }
        aiDraftRespond(['ok'=>true,'revision'=>$before['revision'],'latest_message_id'=>$before['latest_message_id']]);
    }

    // Do not hold the manager's session or a database snapshot while waiting
    // for the model: Inbox, logout and reassignment must remain independent.
    session_write_close();
    $proposal = ManagerAiDraftService::generate($before['context'], null, $before['company_knowledge'] ?? null,
        static fn(): ManagerSelectionSnapshot => ManagerSelectionSnapshot::read($before));

    // Reload the same session without writing it. A logout/account/CSRF change
    // while the model was running invalidates the entire response.
    if (!session_start(['read_and_close'=>true])) {
        aiDraftRespond(['ok'=>false,'error'=>'unauthorized']);
    }
    if (ManagerHttp::managerId() !== $actor || !ManagerRequestContext::validCsrf((string)$data['csrf'])) {
        aiDraftRespond(['ok'=>false,'error'=>'unauthorized']);
    }
    $after = ManagerAiDraftContextService::snapshot($cid,$actor);
    if (empty($after['ok'])) aiDraftRespond($after);
    if (!hash_equals($before['revision'],$after['revision'])) {
        aiDraftRespond(['ok'=>false,'error'=>'context_stale']);
    }
    if (empty($proposal['ok'])) aiDraftRespond($proposal);
    aiDraftRespond($proposal + [
        'revision'=>$after['revision'],
        'latest_message_id'=>$after['latest_message_id'],
    ]);
} catch (Throwable $error) {
    // No transcript, prompt, generated text, URL, credential or exception text.
    error_log('MANAGER_AI_DRAFT_FAILURE '.get_class($error));
    aiDraftRespond(['ok'=>false,'error'=>'context_unavailable']);
}
