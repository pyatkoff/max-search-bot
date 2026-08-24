<?php
require_once __DIR__ . '/../handlers/TelegramWebhookHandler.php';
require_once __DIR__ . '/../services/DialogueApplication.php';
require_once __DIR__ . '/../services/IncomingUpdateDispatcher.php';
require_once __DIR__ . '/../handlers/StateMessageHandler.php';

$captured = [];
$sent = [];

$application = new DialogueApplication(
    static function (array $legacyMessage, array $incoming) use (&$captured): void {
        $text = trim((string)($legacyMessage['text'] ?? ''));
        $captured = [
            'platform' => (string)($incoming['platform'] ?? ''),
            'type' => (string)($incoming['type'] ?? ''),
            'chat_id' => $legacyMessage['chat']['id'] ?? 0,
            'text' => $text,
            'routes_to_ai' => StateMessageHandler::shouldRouteFreeTextToAi($text),
        ];
    }
);

$dispatcher = new IncomingUpdateDispatcher($application);
$messenger = new TelegramMessengerAdapter(
    static function (string $method, array $payload) use (&$sent): bool {
        $sent[] = ['method'=>$method, 'payload'=>$payload];
        return true;
    }
);

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

$handled = TelegramWebhookHandler::dispatchUpdate($update, $dispatcher, $messenger);

$checks = [
    'webhook update handled' => $handled === true,
    'platform normalized as telegram' => ($captured['platform'] ?? '') === 'telegram',
    'message type preserved' => ($captured['type'] ?? '') === 'message',
    'chat id preserved' => (int)($captured['chat_id'] ?? 0) === 990000003,
    'text preserved exactly' => ($captured['text'] ?? '') === 'Хочу в Турцию в сентябре',
    'free text routes to AI instead of exact-country wizard lookup' => ($captured['routes_to_ai'] ?? false) === true,
];

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
