<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$files = [
    'repair_max_search_subscription.php',
    'telegram_webhook_admin.php',
    'cron_followup.php',
    'cron_metrika.php',
    'metrika_queue.php',
    'funnel_report.php',
    'rotate_funnel_fix4.php',
    'departure_route_test.php',
    'tools/lead_bridge_probe.php',
    'tools/max_webhook_reconcile.php',
    'tools/prune_legacy_base.php',
    'tools/telegram_smoke_test.php',
    'tools/webhook_target_status.php',
    'tools/website_production_smoke.php',
];

$failures = [];
foreach ($files as $relative) {
    $source = (string)file_get_contents($root . '/' . $relative);
    $guard = strpos($source, "PHP_SAPI !== 'cli'");
    $exit = strpos($source, 'exit;', $guard === false ? 0 : $guard);
    $firstOperationalStatement = strlen($source);

    foreach (['require_once', 'header(', 'readfile(', 'curl_init(', 'file_put_contents(', '$documentRoot ='] as $needle) {
        $position = strpos($source, $needle);
        if ($position !== false) {
            $firstOperationalStatement = min($firstOperationalStatement, $position);
        }
    }

    if ($guard === false || $exit === false || $guard > $firstOperationalStatement || $exit > $firstOperationalStatement) {
        $failures[] = $relative . ' must reject non-CLI requests before config, output, file access, or side effects';
    }
}

if ($failures !== []) {
    foreach ($failures as $failure) {
        fwrite(STDERR, "FAIL: {$failure}\n");
    }
    exit(1);
}

echo 'CLI entrypoint guard regression passed (' . count($files) . " scripts).\n";
