<?php
require_once(__DIR__ . '/config.php');
require_once(__DIR__ . '/services/RuntimeBootstrap.php');
RuntimeBootstrap::boot((string)($_SERVER['DOCUMENT_ROOT'] ?? ''));
require_once(__DIR__ . '/maxsearchclass.php');
require_once(__DIR__ . '/handlers/TelegramWebhookHandler.php');

header('Content-Type: application/json; charset=utf-8');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok'=>false,'error'=>'method_not_allowed']);
    exit;
}

$providedSecret = (string)($_SERVER['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN'] ?? '');
if (!TelegramWebhookHandler::secretAccepted($providedSecret)) {
    http_response_code(403);
    echo json_encode(['ok'=>false,'error'=>'forbidden']);
    exit;
}

$raw = (string)file_get_contents('php://input');
$update = json_decode($raw, true);
if (!is_array($update)) {
    http_response_code(400);
    echo json_encode(['ok'=>false,'error'=>'invalid_json']);
    exit;
}

try {
    TelegramWebhookHandler::dispatchUpdate($update);
    echo json_encode(['ok'=>true]);
} catch (Throwable $e) {
    DiagnosticLogger::log('telegram_webhook', 'fatal', [
        'error'=>$e->getMessage(),
        'file'=>$e->getFile(),
        'line'=>$e->getLine(),
    ], null, 'error');
    http_response_code(500);
    echo json_encode(['ok'=>false,'error'=>'internal_error']);
}
