<?php
$workflow = (string)file_get_contents(__DIR__ . '/../.github/workflows/deploy.yml');
$exporter = (string)file_get_contents(__DIR__ . '/../export_debug_logs.php');
$syncStart=strpos($workflow,'      - name: Sync canonical production checkout');
$migrationStart=strpos($workflow,'      - name: Apply database migrations');
$smokeStart=strpos($workflow,'      - name: Verify installed production checks');
$downloadStart=strpos($workflow,'      - name: Download exact installed diagnostic snapshot');
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
    'diagnostics download uses the canonical channel and exact retained archive' =>
        !str_contains($workflow, 'appleboy/scp-action@')
        && str_contains($workflow, 'scp -i "$RUNNER_TEMP/deploy_key"')
        && str_contains($workflow, '$DEPLOY_USER@$DEPLOY_HOST:$snapshot/snapshot.tar')
        && str_contains($workflow, 'sha256sum -c snapshot.sha256'),
    'diagnostics are generated outside document root once per install' =>
        str_contains($workflow, 'snapshot="/tmp/max-search-diagnostics-$EXPECTED_SHA-$run_id"')
        && str_contains($workflow, 'MAX_SEARCH_DIAGNOSTICS_OUTPUT_DIR="$snapshot"')
        && str_contains($workflow, 'test ! -e "$snapshot"')
        && str_contains($exporter, 'Diagnostics output directory must stay outside the document root'),
    'raw diagnostics use private filesystem permissions' =>
        str_contains($exporter, 'mkdir($outputDir, 0700, true)')
        && str_contains($exporter, '@chmod($tmp,0600)')
        && str_contains($workflow, 'install -d -m 700 "$snapshot"')
        && str_contains($workflow, 'chmod 400 "$snapshot/snapshot.tar" "$snapshot/snapshot.sha256"'),
    'legacy webroot diagnostics are removed' =>
        str_contains($workflow, 'rm -f diag-tdxAcIvIkZwuvgwq86B1x9fFMJo3GfRa-*.json')
        && str_contains($workflow, 'rm -rf diagnostics'),
    'failed copy retains original snapshot and cleanup reconciles absence' =>
        !str_contains($workflow, 'trap cleanup_remote_diagnostics EXIT')
        && str_contains($workflow, 'if test ! -e "$snapshot"; then exit 0; fi')
        && str_contains($workflow, 'rm -rf -- "$snapshot"')
        && str_contains($workflow, "steps.download.outcome == 'success' || steps.admission.outputs.download_result == 'success'"),
    'export requires a JSON input before sealing the archive' =>
        str_contains($workflow, 'find "$snapshot" -maxdepth 1 -type f -name')
        && str_contains($workflow, 'tar -cf snapshot.tar -- *.json'),
    'diagnostics outcome and accepted original are captured' =>
        str_contains($workflow, 'DOWNLOAD_OUTCOME: ${{ steps.download.outcome }}')
        && str_contains($workflow, 'DOWNLOAD_ACCEPTED: ${{ steps.admission.outputs.download_result }}'),
    'deploy telemetry uses the finite canonical renderer before branch checkout' =>
        str_contains($workflow, 'node tools/release_slot_close.js --stage-status')
        && strpos($workflow, '--stage-status') < strpos($workflow, 'git checkout -B diagnostics'),
    'failed transfer cannot pass the final verification aggregate' =>
        str_contains($workflow, 'test "$DOWNLOAD" = success || test "$DOWNLOAD_ACCEPTED" = success'),
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
        str_contains($smoke,'php tools/structured_runtime_log_smoke.php --check-only')
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
