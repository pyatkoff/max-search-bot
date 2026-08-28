<?php
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

$root = dirname(__DIR__);
if (is_file($root . '/config.php')) require_once $root . '/config.php';
require_once $root . '/services/WebsiteOriginPolicy.php';

function webOut(array $data, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$origin = isset($_SERVER['HTTP_ORIGIN']) ? trim((string) $_SERVER['HTTP_ORIGIN']) : '';
$originPolicy = new WebsiteOriginPolicy();
if (!$originPolicy->apply($origin)) webOut(['ok'=>false,'error'=>'origin_not_allowed'],403);
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

$documentRoot = (string)($_SERVER['DOCUMENT_ROOT'] ?? '');
$prolog = $documentRoot !== '' ? $documentRoot . '/bitrix/modules/main/include/prolog_before.php' : '';
if ($prolog !== '' && is_file($prolog)) require_once $prolog;

require_once $root . '/maxsearchclass.php';
require_once $root . '/services/WebsiteSessionService.php';
require_once $root . '/services/WebsitePageContextService.php';
require_once $root . '/services/IncomingUpdateDispatcher.php';
require_once $root . '/services/IntegrationRegistry.php';
require_once $root . '/integrations/WebsiteIncomingAdapter.php';
require_once $root . '/integrations/WebsiteMessengerAdapter.php';

try {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') webOut(['ok'=>false,'error'=>'method_not_allowed'],405);
    $raw = (string)file_get_contents('php://input');
    $payload = json_decode($raw, true);
    if (!is_array($payload)) $payload = [];

    $action = strtolower(trim((string)($payload['action'] ?? 'init')));
    $session = WebsiteSessionService::resolve((string)($payload['token'] ?? ''));
    $afterId = max(0,(int)($payload['after_id'] ?? 0));

    if ($action === 'context') {
        $context = is_array($payload['page_context'] ?? null) ? $payload['page_context'] : [];
        if (!$context) webOut(['ok'=>false,'error'=>'invalid_context'],422);
        $saved = WebsitePageContextService::save($session['external_user_id'],(int)$session['chat_id'],$context);
        webOut(['ok'=>$saved,'token'=>$session['token']],$saved?200:422);
    }

    if ($action === 'init' || $action === 'poll') {
        $snapshot = WebsiteSessionService::messages($session['external_user_id'],$afterId);
        webOut(['ok'=>true,'token'=>$session['token'],'chat'=>$snapshot]);
    }

    if ($action === 'profile') {
        $name = trim((string)($payload['name'] ?? ''));
        $phone = trim((string)($payload['phone'] ?? ''));
        $email = trim((string)($payload['email'] ?? ''));
        if (mb_strlen($name) > 120 || mb_strlen($phone) > 40 || mb_strlen($email) > 191) webOut(['ok'=>false,'error'=>'invalid_profile'],422);
        if ($email !== '' && !filter_var($email,FILTER_VALIDATE_EMAIL)) webOut(['ok'=>false,'error'=>'invalid_email'],422);
        if ($phone !== '' && !preg_match('/^[0-9+()\- .]{5,40}$/u',$phone)) webOut(['ok'=>false,'error'=>'invalid_phone'],422);
        $ok = WebsiteSessionService::updateProfile($session['external_user_id'],$name,$phone,$email);
        $snapshot = WebsiteSessionService::messages($session['external_user_id'],$afterId);
        webOut(['ok'=>$ok,'token'=>$session['token'],'chat'=>$snapshot],$ok?200:409);
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
