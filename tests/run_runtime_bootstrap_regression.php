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
];
foreach ($sourceFiles as $file) {
    $source = (string)file_get_contents($file);
    rbCheck(basename($file) . ' uses runtime bootstrap', str_contains($source, 'RuntimeBootstrap::boot('), true);
    rbCheck(basename($file) . ' has no direct prolog require', str_contains($source, 'require_once($_SERVER[\'DOCUMENT_ROOT\'] . \'/bitrix/modules/main/include/prolog_before.php\')'), false);
}

echo "\n--------------------------\n";
echo 'TOTAL ' . ($passed + $failed) . " | PASS {$passed} | FAIL {$failed}\n";
exit($failed > 0 ? 1 : 0);
