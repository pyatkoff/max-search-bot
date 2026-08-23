<?php

declare(strict_types=1);

$passed = 0;
$failed = 0;
function mnhCheck(string $name, $actual, $expected): void {
    global $passed, $failed;
    if ($actual === $expected) { echo "PASS  {$name}\n"; $passed++; return; }
    echo "FAIL  {$name}\n";
    echo '      expected: '.json_encode($expected, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)."\n";
    echo '      actual:   '.json_encode($actual, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)."\n";
    $failed++;
}

$files = [
    'StateMessageHandler' => __DIR__ . '/../handlers/StateMessageHandler.php',
    'AiShortAnswerHandler' => __DIR__ . '/../handlers/AiShortAnswerHandler.php',
    'DepartureRouteAdviceHandler' => __DIR__ . '/../handlers/DepartureRouteAdviceHandler.php',
];

foreach ($files as $name => $file) {
    $source = (string)file_get_contents($file);
    mnhCheck($name . ' loads IntegrationRegistry', strpos($source, 'IntegrationRegistry.php') !== false, true);
    mnhCheck($name . ' has no direct MaxSend', strpos($source, 'MaxSearchApi::MaxSend(') === false, true);
    mnhCheck($name . ' uses messenger abstraction', strpos($source, 'IntegrationRegistry::messenger()->send(') !== false, true);
}

$total = $passed + $failed;
echo "\n--------------------------\n";
echo "TOTAL {$total} | PASS {$passed} | FAIL {$failed}\n";
exit($failed > 0 ? 1 : 0);
