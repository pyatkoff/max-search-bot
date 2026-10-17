<?php
$workflow = (string)file_get_contents(__DIR__ . '/../.github/workflows/deploy.yml');
$exporter = (string)file_get_contents(__DIR__ . '/../export_debug_logs.php');
$syncStart=strpos($workflow,'      - name: Sync canonical production checkout');
$migrationStart=strpos($workflow,'      - name: Apply database migrations');
$smokeStart=strpos($workflow,'      - name: Production smoke checks');
$downloadStart=strpos($workflow,'      - name: Download production diagnostics');
$sync=$syncStart!==false&&$migrationStart!==false?substr($workflow,$syncStart,$migrationStart-$syncStart):'';
$smoke=$smokeStart!==false&&$downloadStart!==false?substr($workflow,$smokeStart,$downloadStart-$smokeStart):'';
$reset=strpos($sync,'git reset --hard');$contain=strpos($sync,'php tools/structured_runtime_log_smoke.php;');
$privateReaders=true;
foreach (['export_debug_logs.php'=>2,'tools/production_snapshot.php'=>3,'tools/export_handoff_snapshot.php'=>1,'services/ProjectHealth.php'=>1] as $path=>$calls) {
    $reader=(string)file_get_contents(dirname(__DIR__).'/'.$path);
    if (substr_count($reader,'DiagnosticLogger::filePath()')!==$calls
        || !str_contains($reader,'DiagnosticLogger.php') || str_contains($reader,'/structured_events.log')) $privateReaders=false;
}

$checks = [
    'diagnostics download uses canonical remote scp' =>
        strpos($workflow, 'appleboy/scp-action@') === false
        && strpos($workflow, 'scp -o BatchMode=yes') !== false
        && strpos($workflow, '$remote_diag_dir/*.json') !== false,
    'diagnostics are generated outside document root' =>
        strpos($workflow, 'MAX_SEARCH_DIAGNOSTICS_OUTPUT_DIR=\'/tmp/max-search-diagnostics-$EXPECTED_SHA\'') !== false
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
        && strpos($workflow, 'rm -rf \'$remote_diag_dir\'') !== false,
    'download requires at least one json file' =>
        strpos($workflow, "find production-diagnostics -maxdepth 1 -type f -name '*.json' -print -quit") !== false,
    'diagnostics download outcome is captured' =>
        strpos($workflow, 'DOWNLOAD_OUTCOME: ${{ steps.download_diagnostics.outcome }}') !== false,
    'deploy telemetry exposes diagnostics outcome' =>
        strpos($workflow, '"diagnostics":"%s"') !== false,
    'failed diagnostics transfer fails deployment health' =>
        strpos($workflow, 'test "$DOWNLOAD_OUTCOME" = "success"') !== false,
    'all structured log consumers use the same explicit private logger owner' => $privateReaders,
    'structured history containment runs in exact checkout sync before migrations' =>
        $reset!==false&&$contain!==false&&$reset<$contain
        && substr_count($sync,'php tools/structured_runtime_log_smoke.php;')===1
        && str_contains($sync,"= '\$EXPECTED_SHA'; php tools/structured_runtime_log_smoke.php;")
        && str_contains($workflow,"if: steps.sync.outcome == 'success'"),
    'early anonymous structured log probe discards headers and accepts only denied or missing' =>
        str_contains($sync,"-o /dev/null -I -w '%{http_code}' 'https://app.anytoour.ru/structured_events.log'")
        && str_contains($sync,'case "$status" in 403|404)')
        && str_contains($sync,'Public structured log containment failed: HTTP $status')
        && str_contains($sync,'exit 1;; esac'),
    'later smoke verifies the existing private boundary and rechecks its public fence' =>
        str_contains($smoke,'php tools/structured_runtime_log_smoke.php --check-only;')
        && str_contains($smoke,'tmp_out.txt structured_events.log; do')
        && str_contains($smoke,"-o /dev/null -I -w '%{http_code}'")
        && str_contains($smoke,'case "$status" in 403|404)')
        && !str_contains($workflow,'rm -f structured_events.log'),
];

$failed = 0;
foreach ($checks as $name => $ok) {
    echo ($ok ? 'PASS  ' : 'FAIL  ') . $name . PHP_EOL;
    if (!$ok) $failed++;
}

echo $failed === 0 ? "DEPLOY DIAGNOSTICS CONTRACT: OK\n" : "DEPLOY DIAGNOSTICS CONTRACT: FAIL ({$failed})\n";
exit($failed > 0 ? 1 : 0);
