<?php

declare(strict_types=1);

require_once __DIR__ . '/../services/RuntimeBootstrap.php';

$passed = 0;
$failed = 0;

function rbCheck(string $name, $actual, $expected): void
{
    global $passed, $failed;
    if ($actual === $expected) {
        echo "PASS  {$name}\n";
        $passed++;
        return;
    }
    echo "FAIL  {$name}\n";
    $failed++;
}

rbCheck('standalone defaults false', RuntimeBootstrap::isStandalone(), false);

$thrown = false;
try {
    RuntimeBootstrap::boot('/definitely/missing/runtime-root');
} catch (RuntimeException $e) {
    $thrown = str_starts_with($e->getMessage(), 'legacy_bitrix_bootstrap_missing:');
}
rbCheck('legacy missing prolog fails explicitly', $thrown, true);

$sourceFiles = [
    __DIR__ . '/../webhook.php',
    __DIR__ . '/../telegram_webhook.php',
    __DIR__ . '/../cron_followup.php',
    __DIR__ . '/../website_consultant_api.php',
    __DIR__ . '/../web-consultant/api.php',
    __DIR__ . '/../open_tours.php',
    __DIR__ . '/../metrika_queue.php',
    __DIR__ . '/../tools/telegram_start_smoke.php',
];
foreach ($sourceFiles as $file) {
    $source = (string)file_get_contents($file);
    $label = str_replace(dirname(__DIR__) . '/', '', $file);
    rbCheck($label . ' uses runtime bootstrap', str_contains($source, 'RuntimeBootstrap::boot('), true);
    rbCheck($label . ' has no direct Bitrix prolog dependency', str_contains($source, '/bitrix/modules/main/include/prolog_before.php'), false);
}

$openTours = (string)file_get_contents(__DIR__ . '/../open_tours.php');
rbCheck('open_tours uses configured public base', str_contains($openTours, 'ProjectConfig::baseDomain()'), true);
rbCheck('open_tours has no hardcoded legacy host', str_contains($openTours, 'app.anytoour.ru'), false);

$metrikaQueue = (string)file_get_contents(__DIR__ . '/../metrika_queue.php');
rbCheck('metrika_queue keeps same queue file', str_contains($metrikaQueue, "metrika_offline_queue.csv"), true);
rbCheck('metrika_queue remains read-only', str_contains($metrikaQueue, 'readfile($file)'), true);

echo "\n--------------------------\n";
echo 'TOTAL ' . ($passed + $failed) . " | PASS {$passed} | FAIL {$failed}\n";
exit($failed > 0 ? 1 : 0);
