<?php
$configFile = __DIR__ . '/../config.php';
if (is_file($configFile)) require_once $configFile;
require_once __DIR__ . '/../handlers/TelegramWebhookHandler.php';
require_once __DIR__ . '/../integrations/TelegramIncomingAdapter.php';
require_once __DIR__ . '/../services/TelegramStartSourceResolver.php';
require_once __DIR__ . '/../services/DialogueApplication.php';
require_once __DIR__ . '/../services/IncomingUpdateDispatcher.php';
require_once __DIR__ . '/../services/TelegramWebhookHealth.php';
require_once __DIR__ . '/../handlers/StateMessageHandler.php';

$sent = [];

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

// Validate the transport normalization contract directly. Production source handling
// is configurable (ai / ask / manager), so controller dispatch may intentionally
// consume the normalized message before DialogueApplication is reached.
$normalized = TelegramIncomingAdapter::fromUpdate($update) ?: [];
$normalized['source_key'] = TelegramStartSourceResolver::resolve($normalized);
$text = trim((string)($normalized['text'] ?? ''));

$application = new DialogueApplication(static function (): void {});
$dispatcher = new IncomingUpdateDispatcher($application);
$messenger = new TelegramMessengerAdapter(
    static function (string $method, array $payload) use (&$sent): bool {
        $sent[] = ['method'=>$method, 'payload'=>$payload];
        return true;
    }
);
$handled = TelegramWebhookHandler::dispatchUpdate($update, $dispatcher, $messenger);

$checks = [
    'webhook update handled' => $handled === true,
    'platform normalized as telegram' => ($normalized['platform'] ?? '') === 'telegram',
    'message type preserved' => ($normalized['type'] ?? '') === 'message',
    'chat id preserved' => (int)($normalized['user']['chat_id'] ?? 0) === 990000003,
    'text preserved exactly' => $text === 'Хочу в Турцию в сентябре',
    'AnyTour Telegram source preserved' => ($normalized['source_key'] ?? '') === 'telegram:anytour-main',
    'free text is AI-compatible independent of configurable source policy' => StateMessageHandler::shouldRouteFreeTextToAi($text) === true,
];

$failed = 0;
foreach ($checks as $label => $ok) {
    echo ($ok ? 'PASS  ' : 'FAIL  ') . $label . PHP_EOL;
    if (!$ok) $failed++;
}

if (defined('TELEGRAM_BOT_TOKEN') && trim((string)TELEGRAM_BOT_TOKEN) !== '') {
    $health = TelegramWebhookHealth::collect();
    echo 'TELEGRAM WEBHOOK STATUS: ' . json_encode([
        'ok'=>$health['ok'] ?? false,
        'configured'=>$health['configured'] ?? false,
        'bot'=>$health['bot'] ?? null,
        'webhook'=>$health['webhook'] ?? null,
        'transport'=>$health['transport'] ?? null,
        'reason'=>$health['reason'] ?? null,
    ], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_INVALID_UTF8_SUBSTITUTE) . PHP_EOL;
}

if ($failed === 0) {
    echo PHP_EOL . "TELEGRAM SMOKE: OK" . PHP_EOL;
    exit(0);
}

echo PHP_EOL . "TELEGRAM SMOKE: FAILED ({$failed})" . PHP_EOL;
exit(1);
