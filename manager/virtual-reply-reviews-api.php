<?php
declare(strict_types=1);
ini_set('display_errors','0');
require_once dirname(__DIR__).'/config.php';
require_once __DIR__.'/lib/ManagerHttp.php';
require_once dirname(__DIR__).'/services/VirtualReplyReviewBrowser.php';
ManagerHttp::startJson();
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
if (($_SERVER['REQUEST_METHOD']??'')!=='GET') ManagerHttp::respond(['ok'=>false,'error'=>'method_not_allowed'],405);
if (ManagerHttp::managerId()<=0) ManagerHttp::respond(['ok'=>false,'error'=>'unauthorized'],401);
try {
    // Prevent the legacy project upsert in metadata-only authenticated readers.
    ProjectAccessService::initializeReadOnly();
    $actor=ManagerHttp::requireManager();
    ManagerHttp::requireAdmin($actor);
    if (ProjectConfig::projectId()!=='anytour') ManagerHttp::respond(['ok'=>false,'error'=>'forbidden'],403);
    $result=(new VirtualReplyReviewBrowser(new VirtualReplyStore(ConversationDb::connection())))->read($actor,$_GET);
    ManagerHttp::respond($result,empty($result['ok'])?403:200);
} catch (InvalidArgumentException $error) {
    ManagerHttp::respond(['ok'=>false,'error'=>'invalid_request'],422);
} catch (Throwable $error) {
    $unavailable=in_array($error->getMessage(),['virtual_reply_case_unavailable','virtual_reply_review_unavailable','virtual_reply_source_changed','virtual_reply_holdout_reserved'],true);
    ManagerHttp::respond(['ok'=>false,'error'=>$unavailable?'review_unavailable':'storage_unavailable'],$unavailable?404:503);
}
