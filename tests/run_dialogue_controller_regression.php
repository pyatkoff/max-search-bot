<?php

declare(strict_types=1);

require_once __DIR__ . '/../services/IncomingMessage.php';
require_once __DIR__ . '/../services/DialogueController.php';

$passed = 0;
$failed = 0;
function dcCheck(string $name, $actual, $expected): void {
    global $passed, $failed;
    if ($actual === $expected) { echo "PASS  {$name}\n"; $passed++; return; }
    echo "FAIL  {$name}\n";
    echo '      expected: '.json_encode($expected, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)."\n";
    echo '      actual:   '.json_encode($actual, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)."\n";
    $failed++;
}

$incoming = IncomingMessage::text(
    'telegram',
    77,
    77,
    'm1',
    'Хочу Турцию',
    ['first_name'=>'Ivan','last_name'=>'Petrov','username'=>'ivan']
);
$message = DialogueController::messageEnvelope($incoming);
dcCheck('message chat id preserved', $message['chat']['id'], 77);
dcCheck('message user first name preserved', $message['from']['first_name'], 'Ivan');
dcCheck('message platform preserved', $message['_platform'], 'telegram');
dcCheck('message text preserved', $message['text'], 'Хочу Турцию');

$callback = IncomingMessage::callback(
    'max',
    123,
    -123,
    'cb1',
    'manager_request',
    ['first_name'=>'Pavel']
);
$query = DialogueController::queryEnvelope($callback);
dcCheck('callback internal id preserved', $query['from']['id'], -123);
dcCheck('callback payload preserved', $query['data'], 'manager_request');
dcCheck('callback platform preserved', $query['_platform'], 'max');

$reply = DialogueController::objectionReply('очень дорого!');
dcCheck('price objection detected', is_string($reply), true);
dcCheck('normal message not objection', DialogueController::objectionReply('Турция на 7 ночей'), null);
dcCheck('objection response suggests dates', mb_strpos((string)$reply, 'сдвинуть даты') !== false, true);

$total = $passed + $failed;
echo "\n--------------------------\n";
echo "TOTAL {$total} | PASS {$passed} | FAIL {$failed}\n";
exit($failed > 0 ? 1 : 0);
