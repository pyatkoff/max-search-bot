<?php

declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/services/AiRuntimeLogger.php';

$passed = 0;
$failed = 0;
function aiLogCheck(string $name, bool $ok): void
{
    global $passed, $failed;
    echo ($ok ? 'PASS  ' : 'FAIL  ') . $name . PHP_EOL;
    $ok ? $passed++ : $failed++;
}

$tmp = sys_get_temp_dir() . '/max-search-ai-log-test-' . getmypid() . '-' . bin2hex(random_bytes(4));
putenv('MAX_SEARCH_AI_LOG_DIR=' . $tmp);

try {
    aiLogCheck('debug log write succeeds outside application tree', AiRuntimeLogger::debug("AI INPUT: private test value\n"));
    aiLogCheck('error log write succeeds outside application tree', AiRuntimeLogger::error("private test error\n"));

    $debug = AiRuntimeLogger::debugFile();
    $error = AiRuntimeLogger::errorFile();
    aiLogCheck('runtime paths resolve to configured external directory', dirname($debug) === $tmp && dirname($error) === $tmp);
    aiLogCheck('runtime directory is private', (fileperms($tmp) & 0777) === 0700);
    aiLogCheck('runtime files are private', (fileperms($debug) & 0777) === 0600 && (fileperms($error) & 0777) === 0600);

    for ($i = 0; $i < 20; $i++) {
        AiRuntimeLogger::debug(str_repeat('x', 70000));
    }
    clearstatcache(true, $debug);
    aiLogCheck('debug retention is bounded by size', filesize($debug) <= 1048576);

    putenv('MAX_SEARCH_AI_LOG_DIR=' . $root . '/.runtime/forbidden-ai-log-test');
    aiLogCheck('logger rejects a directory inside document root', AiRuntimeLogger::debug('must not be written') === false);
    aiLogCheck('rejected document-root log was not created', !is_file($root . '/.runtime/forbidden-ai-log-test/ai_debug.log'));
} finally {
    putenv('MAX_SEARCH_AI_LOG_DIR');
    foreach ([$tmp . '/ai_debug.log', $tmp . '/ai_errors.log'] as $file) {
        if (is_file($file)) @unlink($file);
    }
    if (is_dir($tmp)) @rmdir($tmp);
}

$runtimeFiles = [
    $root . '/services/AiInvocationService.php',
    $root . '/handlers/AiMessageHandler.php',
    $root . '/handlers/DepartureRouteAdviceHandler.php',
    $root . '/services/PendingMonthStore.php',
];
$runtimeSource = '';
foreach ($runtimeFiles as $file) $runtimeSource .= (string)file_get_contents($file);
$deploy = (string)file_get_contents($root . '/.github/workflows/deploy.yml');
$exporter = (string)file_get_contents($root . '/export_debug_logs.php');
$smoke = (string)file_get_contents($root . '/tools/ai_runtime_log_smoke.php');

aiLogCheck('AI runtime writers use the external logger', substr_count($runtimeSource, 'AiRuntimeLogger::') >= 7);
aiLogCheck('AI runtime writers do not write legacy webroot log paths',
    !str_contains($runtimeSource, "'/ai_debug.log'")
    && !str_contains($runtimeSource, "'/handlers/ai_debug.log'")
    && !str_contains($runtimeSource, "'/handlers/ai_errors.log'")
);
aiLogCheck('authorized CLI exporter reads the external AI log', str_contains($exporter, "'ai'=>AiRuntimeLogger::debugFile()"));
aiLogCheck('deploy removes legacy public AI logs', str_contains($deploy, 'rm -f ai_debug.log handlers/ai_debug.log handlers/ai_errors.log'));
aiLogCheck('production smoke verifies external path and private modes',
    str_contains($deploy, 'php tools/ai_runtime_log_smoke.php')
    && str_contains($smoke, "'outside_document_root'")
    && str_contains($smoke, '$directoryMode === 0700')
    && str_contains($smoke, '$fileMode === 0600')
);
aiLogCheck('deploy verifies public AI log paths are denied',
    str_contains($deploy, 'for path in ai_debug.log handlers/ai_debug.log handlers/ai_errors.log')
    && str_contains($deploy, '403|404')
    && str_contains($deploy, 'Public AI log containment failed')
);

echo "\n--------------------------\n";
echo 'TOTAL ' . ($passed + $failed) . " | PASS {$passed} | FAIL {$failed}\n";
exit($failed > 0 ? 1 : 0);
