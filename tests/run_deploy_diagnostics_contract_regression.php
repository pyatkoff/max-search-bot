<?php
$workflow = (string)file_get_contents(__DIR__ . '/../.github/workflows/deploy.yml');
$exporter = (string)file_get_contents(__DIR__ . '/../export_debug_logs.php');

$checks = [
    'diagnostics download uses canonical remote scp' =>
        strpos($workflow, 'appleboy/scp-action@') === false
        && strpos($workflow, 'scp -o BatchMode=yes') !== false
        && strpos($workflow, '$remote_diag_dir/*.json') !== false,
    'diagnostics are generated outside document root' =>
        strpos($workflow, "MAX_SEARCH_DIAGNOSTICS_OUTPUT_DIR='/tmp/max-search-diagnostics-$EXPECTED_SHA'") !== false
        && strpos($workflow, 'cp diag-tdxAcIvIkZwuvgwq86B1x9fFMJo3GfRa-*.json diagnostics/') === false
        && strpos($exporter, 'MAX_SEARCH_DIAGNOSTICS_OUTPUT_DIR') !== false
        && strpos($exporter, 'Diagnostics output directory must stay outside the document root') !== false,
    'raw diagnostics use private filesystem permissions' =>
        strpos($exporter, 'mkdir($outputDir, 0700, true)') !== false
        && strpos($exporter, '@chmod($tmp,0600)') !== false,
    'legacy webroot diagnostics are removed' =>
        strpos($workflow, 'rm -f diag-tdxAcIvIkZwuvgwq86B1x9fFMJo3GfRa-*.json') !== false
        && strpos($workflow, 'rm -rf diagnostics') !== false,
    'temporary remote diagnostics are always cleaned' =>
        strpos($workflow, 'trap cleanup_remote_diagnostics EXIT') !== false
        && strpos($workflow, "rm -rf '$remote_diag_dir'") !== false,
    'download requires at least one json file' =>
        strpos($workflow, "find production-diagnostics -maxdepth 1 -type f -name '*.json' -print -quit") !== false,
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
