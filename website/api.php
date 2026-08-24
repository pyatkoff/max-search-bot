<?php
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

$root = dirname(__DIR__);
$documentRoot = (string)($_SERVER['DOCUMENT_ROOT'] ?? '');
$prolog = $documentRoot !== '' ? $documentRoot . '/bitrix/modules/main/include/prolog_before.php' : '';
if ($prolog !== '' && is_file($prolog)) require_once $prolog;

require_once $root . '/maxsearchclass.php';
require_once $root . '/services/WebsiteSessionService.php';
require_once $root . '/services/IncomingUpdateDispatcher.php';
require_once $root . '/services/IntegrationRegistry.php';
require_once $root . '/integrations/WebsiteIncomingAdapter.php';
require_once $root . '/integrations/WebsiteMessengerAdapter.php';

function webOut(array $data, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

try {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') webOut(['ok'=>false,'error'=>'method_not_allowed'],405);
    $raw = (string)file_get_contents('php://input');
    $payload = json_decode($raw, true);
    if (!is_array($payload)) $payload = [];

    $action = strtolower(trim((string)($payload['action'] ?? 'init')));
    $session = WebsiteSessionService::resolve((string)($payload['token'] ?? ''));
    $afterId = max(0,(int)($payload['after_id'] ?? 0));

    if ($action === 'init' || $action === 'poll') {
        $snapshot = WebsiteSessionService::messages($session['external_user_id'],$afterId);
        webOut(['ok'=>true,'token'=>$session['token'],'chat'=>$snapshot]);
    }

    $requestPayload = $payload;
    $requestPayload['action'] = $action;
    if ($action === 'send') $requestPayload['action'] = 'message';
    if ($action === 'start') $requestPayload['action'] = 'start';
    if ($action === 'callback') $requestPayload['action'] = 'callback';
    if ($action === 'contact') $requestPayload['action'] = 'contact';

    if (($requestPayload['action'] ?? '') === 'message') {
        $text = trim((string)($requestPayload['text'] ?? ''));
        if ($text === '' || mb_strlen($text) > 2000) webOut(['ok'=>false,'error'=>'invalid_text'],422);
    }

    $incoming = WebsiteIncomingAdapter::fromPayload($requestPayload,$session['external_user_id'],$session['chat_id']);
    if (!$incoming) webOut(['ok'=>false,'error'=>'invalid_payload'],422);
    $incoming['source_key'] = WebsiteSessionService::sourceKey();

    $transport = new WebsiteMessengerAdapter('ai');
    IntegrationRegistry::useMessenger($transport);
    $handled = (new IncomingUpdateDispatcher())->dispatch($incoming);
    $snapshot = WebsiteSessionService::messages($session['external_user_id'],$afterId);
    webOut(['ok'=>true,'handled'=>$handled,'token'=>$session['token'],'chat'=>$snapshot]);
} catch (Throwable $e) {
    if (class_exists('DiagnosticLogger')) {
        try { DiagnosticLogger::error('website_chat','api_failure',['error'=>$e->getMessage()]); } catch (Throwable $ignored) {}
    }
    webOut(['ok'=>false,'error'=>'server_error'],500);
}
