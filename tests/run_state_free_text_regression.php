<?php
require_once __DIR__ . '/../handlers/StateMessageHandler.php';

$tests = [
    ['Хочу в Турцию в сентябре', true, 'natural country + month'],
    ['Турция в сентябре', true, 'country + month'],
    ['Хотим из Москвы в Египет на 8 ночей', true, 'full tour request'],
    ['2 взрослых в октябре', true, 'tourists + month'],
    ['Турция', false, 'single country stays wizard value'],
    ['Москва', false, 'single city stays wizard value'],
    ['Самара', false, 'single city stays wizard value'],
    ['', false, 'empty value'],
];

$passed = 0;
$failed = 0;
foreach ($tests as [$text, $expected, $label]) {
    $actual = StateMessageHandler::shouldRouteFreeTextToAi($text);
    if ($actual === $expected) {
        echo "PASS  {$label}\n";
        $passed++;
    } else {
        echo "FAIL  {$label}\n";
        echo '      text: ' . $text . "\n";
        echo '      expected: ' . var_export($expected, true) . "\n";
        echo '      actual:   ' . var_export($actual, true) . "\n";
        $failed++;
    }
}

$source = (string)file_get_contents(__DIR__ . '/../handlers/StateMessageHandler.php');
$guards = [
    'country fallback invokes free-text routing' => strpos($source, 'elseif(self::shouldRouteFreeTextToAi($country))') !== false,
    'city fallback invokes free-text routing' => strpos($source, 'elseif(self::shouldRouteFreeTextToAi($city))') !== false,
    'free text switches to AI status' => strpos($source, 'MaxSearchApi::setStatus($chatId, MaxSearchApi::$statusAi);') !== false,
    'free text reaches AiMessageHandler' => strpos($source, 'AiMessageHandler::handle($message, $chatId);') !== false,
];
foreach ($guards as $label => $ok) {
    if ($ok) { echo "PASS  {$label}\n"; $passed++; }
    else { echo "FAIL  {$label}\n"; $failed++; }
}

echo "\n----------------------------------------\n";
echo "TOTAL " . ($passed + $failed) . " | PASS {$passed} | FAIL {$failed}\n";
exit($failed > 0 ? 1 : 0);
