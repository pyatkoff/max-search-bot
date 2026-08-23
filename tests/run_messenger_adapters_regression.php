<?php

declare(strict_types=1);

require_once __DIR__ . '/../services/ProjectConfig.php';
require_once __DIR__ . '/../services/IntegrationRegistry.php';
require_once __DIR__ . '/../integrations/MaxIncomingAdapter.php';
require_once __DIR__ . '/../integrations/TelegramIncomingAdapter.php';
require_once __DIR__ . '/../integrations/TelegramMessengerAdapter.php';

$passed = 0;
$failed = 0;
function maCheck(string $name, $actual, $expected): void {
    global $passed, $failed;
    if ($actual === $expected) { echo "PASS  {$name}\n"; $passed++; return; }
    echo "FAIL  {$name}\n";
    echo '      expected: '.json_encode($expected, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)."\n";
    echo '      actual:   '.json_encode($actual, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)."\n";
    $failed++;
}

$max = MaxIncomingAdapter::fromUpdate([
    'update_type'=>'message_created',
    'message'=>[
        'sender'=>['user_id'=>123,'name'=>'Ivan Petrov'],
        'body'=>['mid'=>'m1','text'=>'Турция в сентябре'],
    ],
]);
maCheck('MAX normalized platform', $max['platform'] ?? null, 'max');
maCheck('MAX keeps external id', $max['user']['external_user_id'] ?? null, '123');
maCheck('MAX uses negative internal id', $max['user']['chat_id'] ?? null, -123);
maCheck('MAX text normalized', $max['text'] ?? null, 'Турция в сентябре');

$tg = TelegramIncomingAdapter::fromUpdate([
    'update_id'=>1,
    'message'=>[
        'message_id'=>77,
        'from'=>['id'=>456,'first_name'=>'Anna','username'=>'anna'],
        'chat'=>['id'=>456],
        'text'=>'Хочу в Египет',
    ],
]);
maCheck('Telegram normalized platform', $tg['platform'] ?? null, 'telegram');
maCheck('Telegram positive internal id', $tg['user']['chat_id'] ?? null, 456);
maCheck('Telegram text normalized', $tg['text'] ?? null, 'Хочу в Египет');

$tgCallback = TelegramIncomingAdapter::fromUpdate([
    'callback_query'=>[
        'id'=>'cb1',
        'from'=>['id'=>456,'first_name'=>'Anna'],
        'data'=>'manager_request',
    ],
]);
maCheck('Telegram callback type', $tgCallback['type'] ?? null, 'callback');
maCheck('Telegram callback payload', $tgCallback['callback_data'] ?? null, 'manager_request');

$tgContact = TelegramIncomingAdapter::fromUpdate([
    'message'=>[
        'message_id'=>78,
        'from'=>['id'=>456,'first_name'=>'Anna'],
        'contact'=>['phone_number'=>'+79990000000'],
    ],
]);
maCheck('Telegram contact type', $tgContact['type'] ?? null, 'contact');
maCheck('Telegram contact phone', $tgContact['contact_phone'] ?? null, '+79990000000');

$markup = TelegramMessengerAdapter::convertButtons([
    [['text'=>'Туры','callback_data'=>'show_tours'], ['text'=>'Сайт','url'=>'https://example.com']]
]);
maCheck('Telegram inline keyboard', $markup['inline_keyboard'][0][0]['callback_data'] ?? null, 'show_tours');
maCheck('Telegram link button', $markup['inline_keyboard'][0][1]['url'] ?? null, 'https://example.com');

$contactMarkup = TelegramMessengerAdapter::convertButtons([
    [['text'=>'Отправить номер','request_contact'=>true]]
]);
maCheck('Telegram contact keyboard', $contactMarkup['keyboard'][0][0]['request_contact'] ?? null, true);

$calls = [];
$messenger = new TelegramMessengerAdapter(static function($method, $payload) use (&$calls) {
    $calls[] = [$method, $payload];
    return true;
});
maCheck('Telegram injected sender works', $messenger->send(456, 'Привет'), true);
maCheck('Telegram injected method', $calls[0][0] ?? null, 'sendMessage');
maCheck('Telegram injected chat id', $calls[0][1]['chat_id'] ?? null, 456);

maCheck('Telegram contact request works', $messenger->sendContactRequest(456, 'Телефон?', 'phone_manual', 'back_check'), true);
maCheck('Telegram contact request uses two messages', count($calls), 3);
$contactReply = json_decode((string)($calls[1][1]['reply_markup'] ?? ''), true);
$fallbackReply = json_decode((string)($calls[2][1]['reply_markup'] ?? ''), true);
maCheck('Telegram contact message requests contact', $contactReply['keyboard'][0][0]['request_contact'] ?? null, true);
maCheck('Telegram manual fallback keeps callback', $fallbackReply['inline_keyboard'][0][0]['callback_data'] ?? null, 'phone_manual');
maCheck('Telegram back fallback keeps callback', $fallbackReply['inline_keyboard'][1][0]['callback_data'] ?? null, 'back_check');

$maxCalls = [];
$maxMessenger = new MaxMessengerAdapter(
    static function($chatId, string $text) use (&$maxCalls): bool { $maxCalls[]=['plain',$chatId,$text]; return true; },
    static function($chatId, string $text, array $buttons) use (&$maxCalls): bool { $maxCalls[]=['buttons',$chatId,$text,$buttons]; return true; }
);
maCheck('MAX contact request works', $maxMessenger->sendContactRequest(-123, 'Телефон?', 'phone_manual', 'back_check'), true);
maCheck('MAX contact button kept', $maxCalls[0][3][0][0]['request_contact'] ?? null, true);
maCheck('MAX manual callback kept', $maxCalls[0][3][1][0]['callback_data'] ?? null, 'phone_manual');
maCheck('MAX back callback kept', $maxCalls[0][3][2][0]['callback_data'] ?? null, 'back_check');

ProjectConfig::resetForTests([
    'id'=>'tg-test',
    'messenger'=>['provider'=>'telegram'],
    'search'=>['provider'=>'tourvisor'],
    'leads'=>['provider'=>'bitrix'],
]);
IntegrationRegistry::resetForTests();
$registryMessenger = IntegrationRegistry::messenger();
maCheck('Registry selects Telegram', get_class($registryMessenger), 'TelegramMessengerAdapter');

ProjectConfig::resetForTests(null);
IntegrationRegistry::resetForTests();

$total = $passed + $failed;
echo "\n--------------------------\n";
echo "TOTAL {$total} | PASS {$passed} | FAIL {$failed}\n";
exit($failed > 0 ? 1 : 0);
