<?php

declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/services/WebhookRuntimeLogger.php';

$passed = 0;
$failed = 0;
function webhookLogCheck(string $name, bool $ok): void
{
    global $passed, $failed;
    echo ($ok ? 'PASS  ' : 'FAIL  ') . $name . PHP_EOL;
    $ok ? $passed++ : $failed++;
}

$tmp = sys_get_temp_dir() . '/max-search-webhook-log-test-' . getmypid() . '-' . bin2hex(random_bytes(4));
putenv('MAX_SEARCH_WEBHOOK_LOG_DIR=' . $tmp);

try {
    webhookLogCheck('input log write succeeds outside application tree', WebhookRuntimeLogger::input("private input test\n"));
    webhookLogCheck('output log write succeeds outside application tree', WebhookRuntimeLogger::output("private output test\n"));

    $input = WebhookRuntimeLogger::inputFile();
    $output = WebhookRuntimeLogger::outputFile();
    webhookLogCheck('runtime paths resolve to configured external directory', dirname($input) === $tmp && dirname($output) === $tmp);
    webhookLogCheck('runtime directory is private', (fileperms($tmp) & 0777) === 0700);
    webhookLogCheck('runtime files are private', (fileperms($input) & 0777) === 0600 && (fileperms($output) & 0777) === 0600);

    for ($i = 0; $i < 20; $i++) WebhookRuntimeLogger::input(str_repeat('x', 70000));
    clearstatcache(true, $input);
    webhookLogCheck('input retention is bounded by size', filesize($input) <= 1048576);

    putenv('MAX_SEARCH_WEBHOOK_LOG_DIR=' . $root . '/.runtime/forbidden-webhook-log-test');
    webhookLogCheck('logger rejects a directory inside document root', WebhookRuntimeLogger::input('must not be written') === false);
    webhookLogCheck('rejected document-root log was not created', !is_file($root . '/.runtime/forbidden-webhook-log-test/webhook_input.log'));
} finally {
    putenv('MAX_SEARCH_WEBHOOK_LOG_DIR');
    foreach ([$tmp . '/webhook_input.log', $tmp . '/webhook_output.log'] as $file) {
        if (is_file($file)) @unlink($file);
    }
    if (is_dir($tmp)) @rmdir($tmp);
}

$webhook = (string)file_get_contents($root . '/webhook.php');
$handler = (string)file_get_contents($root . '/handlers/MaxUpdateHandler.php');
$exporter = (string)file_get_contents($root . '/export_debug_logs.php');
$deploy = (string)file_get_contents($root . '/.github/workflows/deploy.yml');
$shadow = (string)file_get_contents($root . '/.github/workflows/max-shadow-delivery.yml');
$smoke = (string)file_get_contents($root . '/tools/webhook_runtime_log_smoke.php');

webhookLogCheck('legacy webhook helpers delegate to external logger',
    str_contains($webhook, 'WebhookRuntimeLogger::input')
    && str_contains($webhook, 'WebhookRuntimeLogger::output')
    && !str_contains($webhook, "file_put_contents('tmp_in.txt'")
    && !str_contains($webhook, "file_put_contents('tmp_out.txt'")
);
webhookLogCheck('raw MAX request body is not persisted by the handler', !str_contains($handler, 'put_log_in($content)'));
webhookLogCheck('authorized diagnostics read the external input log', str_contains($exporter, "'tmp'=>WebhookRuntimeLogger::inputFile()"));
webhookLogCheck('shadow diagnostic reads the external input log', str_contains($shadow, 'WebhookRuntimeLogger::inputFile()'));
webhookLogCheck('deploy removes both legacy public webhook logs', str_contains($deploy, 'tmp_in.txt tmp_out.txt'));
webhookLogCheck('production smoke verifies external path and private modes',
    str_contains($deploy, 'php tools/webhook_runtime_log_smoke.php')
    && str_contains($smoke, "'outside_document_root'")
    && str_contains($smoke, '$directoryMode === 0700')
    && str_contains($smoke, '$fileMode === 0600')
);
webhookLogCheck('deploy verifies public webhook log paths are denied',
    str_contains($deploy, 'handlers/ai_errors.log tmp_in.txt tmp_out.txt')
    && str_contains($deploy, '403|404')
);

echo "\n--------------------------\n";
echo 'TOTAL ' . ($passed + $failed) . " | PASS {$passed} | FAIL {$failed}\n";
exit($failed > 0 ? 1 : 0);
