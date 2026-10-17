<?php
declare(strict_types=1);
ini_set('display_errors', '0');
require_once __DIR__.'/config.php';
require_once __DIR__.'/services/TelegramArchiveBotRuntime.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') { http_response_code(405); echo '{"ok":false}'; exit; }
try {
    $store = new TelegramArchiveBotConfig(); $config = $store->load();
    $secret = (string)($_SERVER['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN'] ?? '');
    if (!TelegramArchiveBotRuntime::authenticated($config, $secret)) { http_response_code(403); echo '{"ok":false}'; exit; }
    $raw = file_get_contents('php://input', false, null, 0, 2097153);
    if (!is_string($raw) || strlen($raw) > 2097152) { http_response_code(400); echo '{"ok":false}'; exit; }
    $update = json_decode($raw, true, 64, JSON_THROW_ON_ERROR);
    if (!is_array($update)) { http_response_code(400); echo '{"ok":false}'; exit; }
    TelegramArchiveBotRuntime::receive($update, $config);
    $store->authenticatedEvent($secret);
    echo '{"ok":true}';
} catch (JsonException $error) { http_response_code(400); echo '{"ok":false}'; }
catch (Throwable $error) { http_response_code(500); echo '{"ok":false}'; }
