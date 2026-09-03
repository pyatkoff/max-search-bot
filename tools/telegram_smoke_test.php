<?php
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$configFile = __DIR__ . '/../config.php';
if (is_file($configFile)) require_once $configFile;
require_once __DIR__ . '/../integrations/TelegramIncomingAdapter.php';
require_once __DIR__ . '/../services/TelegramWebhookHealth.php';
require_once __DIR__ . '/../services/TelegramStartSourceResolver.php';
require_once __DIR__ . '/../handlers/StateMessageHandler.php';

$update = [
    'update_id' => 990000001,
    'message' => [
        'message_id' => 990000002,
        'from' => [
            'id' => 990000003,
            'is_bot' => false,
            'first_name' => 'Smoke',
            'username' => 'telegram_smoke',
        ],
        'chat' => [
            'id' => 990000003,
            'first_name' => 'Smoke',
            'username' => 'telegram_smoke',
            'type' => 'private',
        ],
        'date' => time(),
        'text' => 'Хочу в Турцию в сентябре',
    ],
];

// Production smoke must not create a real conversation or trigger the configured
// source-handling policy. Normalize the update directly; routing behavior is
// covered by the required regression suite with isolated fixtures.
$incoming = TelegramIncomingAdapter::fromUpdate($update);
$sourceKey = is_array($incoming) ? TelegramStartSourceResolver::resolve($incoming) : '';
$text = is_array($incoming) ? trim((string)($incoming['text'] ?? '')) : '';

$checks = [
    'update normalized' => is_array($incoming),
    'platform normalized as telegram' => ($incoming['platform'] ?? '') === 'telegram',
    'message type preserved' => ($incoming['type'] ?? '') === 'message',
    'chat id preserved' => (int)($incoming['user']['chat_id'] ?? 0) === 990000003,
    'text preserved exactly' => $text === 'Хочу в Турцию в сентябре',
    'AnyTour Telegram source preserved' => $sourceKey === 'telegram:anytour-main',
    'free text routes to AI when self-service routing owns the turn' => StateMessageHandler::shouldRouteFreeTextToAi($text) === true,
];

$health = null;
if (defined('TELEGRAM_BOT_TOKEN') && trim((string)TELEGRAM_BOT_TOKEN) !== '') {
    $health = TelegramWebhookHealth::collect();
    $checks['Telegram API and configured webhook are healthy'] = ($health['ok'] ?? false) === true;
    echo 'TELEGRAM WEBHOOK STATUS: ' . json_encode([
        'ok'=>$health['ok'] ?? false,
        'configured'=>$health['configured'] ?? false,
        'bot'=>$health['bot'] ?? null,
        'webhook'=>$health['webhook'] ?? null,
        'transport'=>$health['transport'] ?? null,
        'reason'=>$health['reason'] ?? null,
    ], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_INVALID_UTF8_SUBSTITUTE) . PHP_EOL;
}

$failed = 0;
foreach ($checks as $label => $ok) {
    echo ($ok ? 'PASS  ' : 'FAIL  ') . $label . PHP_EOL;
    if (!$ok) $failed++;
}

if ($failed === 0) {
    echo PHP_EOL . "TELEGRAM SMOKE: OK" . PHP_EOL;
    exit(0);
}

echo PHP_EOL . "TELEGRAM SMOKE: FAILED ({$failed})" . PHP_EOL;
exit(1);
