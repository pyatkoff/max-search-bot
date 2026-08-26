<?php
$workflow = (string)file_get_contents(__DIR__ . '/../.github/workflows/deploy.yml');

$checks = [
    'diagnostics transfer uses directory source instead of wildcard' =>
        strpos($workflow, 'source: "www/anytour.online/max-search/diagnostics"') !== false
        && strpos($workflow, 'diagnostics/*.json') === false,
    'diagnostics download outcome is captured' =>
        strpos($workflow, 'DOWNLOAD_OUTCOME: ${{ steps.download_diagnostics.outcome }}') !== false,
    'deploy telemetry exposes diagnostics outcome' =>
        strpos($workflow, '"diagnostics":"%s"') !== false,
    'failed diagnostics transfer fails deployment health' =>
        strpos($workflow, 'test "$DOWNLOAD_OUTCOME" = "success"') !== false,
];

$failed = 0;
foreach ($checks as $name => $ok) {
    echo ($ok ? 'PASS  ' : 'FAIL  ') . $name . PHP_EOL;
    if (!$ok) $failed++;
}

echo $failed === 0 ? "DEPLOY DIAGNOSTICS CONTRACT: OK\n" : "DEPLOY DIAGNOSTICS CONTRACT: FAIL ({$failed})\n";
exit($failed > 0 ? 1 : 0);
