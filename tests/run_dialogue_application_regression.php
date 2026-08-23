<?php

declare(strict_types=1);

require_once __DIR__ . '/../services/IncomingMessage.php';
require_once __DIR__ . '/../services/DialogueApplication.php';
require_once __DIR__ . '/../services/IncomingUpdateDispatcher.php';

$passed = 0;
$failed = 0;
function daCheck(string $name, $actual, $expected): void {
    global $passed, $failed;
    if ($actual === $expected) { echo "PASS  {$name}\n"; $passed++; return; }
    echo "FAIL  {$name}\n";
    echo '      expected: '.json_encode($expected, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)."\n";
    echo '      actual:   '.json_encode($actual, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)."\n";
    $failed++;
}

$messages = [];
$callbacks = [];
$contacts = [];
$acks = [];

$app = new DialogueApplication(
    static function(array $legacy, array $incoming) use (&$messages): void { $messages[] = [$legacy,$incoming]; },
    static function(array $legacy, array $incoming) use (&$callbacks): void { $callbacks[] = [$legacy,$incoming]; },
    static function($chatId, string $phone, array $incoming) use (&$contacts): void { $contacts[] = [$chatId,$phone,$incoming]; },
    static function(string $callbackId, array $incoming) use (&$acks): void { $acks[] = [$callbackId,$incoming]; }
);
$dispatcher = new IncomingUpdateDispatcher($app);

$text = IncomingMessage::text('max', 123, -123, 'm1', 'Турция', ['first_name'=>'Pavel']);
daCheck('dispatch text handled', $dispatcher->dispatch($text), true);
daCheck('legacy text chat id', $messages[0][0]['chat']['id'], -123);
daCheck('legacy text body', $messages[0][0]['text'], 'Турция');

$callback = IncomingMessage::callback('telegram', 77, 77, 'cb1', 'manager_request', ['first_name'=>'Ivan']);
daCheck('dispatch callback handled', $dispatcher->dispatch($callback), true);
daCheck('callback acknowledged', $acks[0][0], 'cb1');
daCheck('legacy callback payload', $callbacks[0][0]['data'], 'manager_request');
daCheck('legacy callback user', $callbacks[0][0]['from']['id'], 77);

$contact = IncomingMessage::contact('telegram', 77, 77, 'm2', '+79990000000');
daCheck('dispatch contact handled', $dispatcher->dispatch($contact), true);
daCheck('contact chat', $contacts[0][0], 77);
daCheck('contact phone', $contacts[0][1], '+79990000000');

daCheck('invalid missing platform rejected', $dispatcher->dispatch(['type'=>'message','user'=>['chat_id'=>1]]), false);
daCheck('invalid missing chat rejected', $dispatcher->dispatch(['type'=>'message','platform'=>'max','user'=>[]]), false);

$total = $passed + $failed;
echo "\n--------------------------\n";
echo "TOTAL {$total} | PASS {$passed} | FAIL {$failed}\n";
exit($failed > 0 ? 1 : 0);
