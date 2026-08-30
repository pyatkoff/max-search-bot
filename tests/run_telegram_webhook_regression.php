<?php

declare(strict_types=1);

if (!defined('TELEGRAM_WEBHOOK_SECRET')) define('TELEGRAM_WEBHOOK_SECRET', 'test-secret');
if (!defined('TELEGRAM_BOT_TOKEN')) define('TELEGRAM_BOT_TOKEN', 'test-token');

require_once __DIR__ . '/../services/ProjectConfig.php';
require_once __DIR__ . '/../services/IntegrationRegistry.php';
require_once __DIR__ . '/../services/DialogueApplication.php';
require_once __DIR__ . '/../services/IncomingUpdateDispatcher.php';
require_once __DIR__ . '/../services/TelegramWebhookHealth.php';
require_once __DIR__ . '/../integrations/TelegramMessengerAdapter.php';
require_once __DIR__ . '/../handlers/TelegramWebhookHandler.php';

$passed = 0;
$failed = 0;
function twCheck(string $name, $actual, $expected): void {
    global $passed, $failed;
    if ($actual === $expected) { echo "PASS  {$name}\n"; $passed++; return; }
    echo "FAIL  {$name}\n";
    echo '      expected: '.json_encode($expected, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)."\n";
    echo '      actual:   '.json_encode($actual, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)."\n";
    $failed++;
}

ProjectConfig::resetForTests([
    'id'=>'anytour',
    'messenger'=>['telegram'=>['source_key'=>'telegram:anytour-main']],
]);

twCheck('Webhook accepts correct secret', TelegramWebhookHandler::secretAccepted('test-secret'), true);
twCheck('Webhook rejects wrong secret', TelegramWebhookHandler::secretAccepted('wrong'), false);

$messages = [];
$callbacks = [];
$contacts = [];
$acks = [];
$app = new DialogueApplication(
    static function(array $message, array $incoming) use (&$messages): void { $messages[] = [$message,$incoming]; },
    static function(array $query, array $incoming) use (&$callbacks): void { $callbacks[] = [$query,$incoming]; },
    static function($chatId, string $phone, array $incoming) use (&$contacts): void { $contacts[] = [$chatId,$phone,$incoming]; },
    static function(string $callbackId, array $incoming) use (&$acks): void { $acks[] = [$callbackId,$incoming]; }
);
$dispatcher = new IncomingUpdateDispatcher($app);

$outbound = [];
$messenger = new TelegramMessengerAdapter(static function(string $method, array $payload) use (&$outbound): bool {
    $outbound[] = [$method,$payload];
    return true;
});

$textUpdate = [
    'update_id'=>1001,
    'message'=>[
        'message_id'=>50,
        'from'=>['id'=>777,'first_name'=>'Pavel','username'=>'ptest'],
        'chat'=>['id'=>777],
        'text'=>'/start',
    ],
];
twCheck('Text update handled', TelegramWebhookHandler::dispatchUpdate($textUpdate,$dispatcher,$messenger), true);
twCheck('Text update reaches application', $messages[0][0]['text'] ?? null, '/start');
twCheck('Text update keeps Telegram platform', $messages[0][1]['platform'] ?? null, 'telegram');
twCheck('Telegram keeps positive chat id', $messages[0][1]['user']['chat_id'] ?? null, 777);
twCheck('Telegram update gets project-specific source', $messages[0][1]['source_key'] ?? null, 'telegram:anytour-main');
twCheck('Registry overridden to Telegram messenger', IntegrationRegistry::messenger() === $messenger, true);

$callbackUpdate = [
    'update_id'=>1002,
    'callback_query'=>[
        'id'=>'tg-cb-1',
        'from'=>['id'=>777,'first_name'=>'Pavel'],
        'data'=>'pick_country_4',
    ],
];
twCheck('Callback update handled', TelegramWebhookHandler::dispatchUpdate($callbackUpdate,$dispatcher,$messenger), true);
twCheck('Callback payload reaches application', $callbacks[0][0]['data'] ?? null, 'pick_country_4');
twCheck('Callback keeps Telegram source', $callbacks[0][1]['source_key'] ?? null, 'telegram:anytour-main');
twCheck('Callback acknowledgement hook runs', $acks[0][0] ?? null, 'tg-cb-1');

$contactUpdate = [
    'update_id'=>1003,
    'message'=>[
        'message_id'=>51,
        'from'=>['id'=>777,'first_name'=>'Pavel'],
        'contact'=>['phone_number'=>'+79990000000'],
    ],
];
twCheck('Contact update handled', TelegramWebhookHandler::dispatchUpdate($contactUpdate,$dispatcher,$messenger), true);
twCheck('Contact phone reaches application', $contacts[0][1] ?? null, '+79990000000');
twCheck('Contact keeps Telegram source', $contacts[0][2]['source_key'] ?? null, 'telegram:anytour-main');

$messenger->answerCallback('direct-callback');
twCheck('Telegram answerCallback uses API method', $outbound[0][0] ?? null, 'answerCallbackQuery');
twCheck('Telegram answerCallback sends callback id', $outbound[0][1]['callback_query_id'] ?? null, 'direct-callback');

$healthRequest = static function(string $method): array {
    if ($method === 'getMe') return ['transport_ok'=>true,'http'=>200,'json'=>['ok'=>true,'result'=>['id'=>123,'username'=>'Any_tour_bot','first_name'=>'AnyTour']]];
    return ['transport_ok'=>true,'http'=>200,'json'=>['ok'=>true,'result'=>['url'=>'https://example.test/current-webhook','pending_update_count'=>2,'allowed_updates'=>['message','callback_query']]]];
};
$health = TelegramWebhookHealth::collect($healthRequest);
twCheck('Telegram health probe validates API', $health['ok'] ?? null, true);
twCheck('Telegram health probe exposes username without token', $health['bot']['username'] ?? null, 'Any_tour_bot');
twCheck('Telegram health probe exposes current webhook', $health['webhook']['url'] ?? null, 'https://example.test/current-webhook');
twCheck('Telegram health probe never exposes token', array_key_exists('token',$health), false);

$explicitHealth = TelegramWebhookHealth::collectToken('different-test-token',$healthRequest);
twCheck('Telegram health supports explicit token identity inspection', $explicitHealth['bot']['id'] ?? null, 123);
twCheck('Explicit token health still never exposes token', array_key_exists('token',$explicitHealth), false);

$ignored = ['update_id'=>1004,'edited_channel_post'=>['text'=>'ignore me']];
twCheck('Unsupported update ignored safely', TelegramWebhookHandler::dispatchUpdate($ignored,$dispatcher,$messenger), false);

IntegrationRegistry::resetForTests();
ProjectConfig::resetForTests(null);

$total = $passed + $failed;
echo "\n--------------------------\n";
echo "TOTAL {$total} | PASS {$passed} | FAIL {$failed}\n";
exit($failed > 0 ? 1 : 0);
