<?php

declare(strict_types=1);

$workflow = (string)file_get_contents(dirname(__DIR__) . '/.github/workflows/cutover-preflight.yml');
$legacyService = (string) file_get_contents(dirname(__DIR__) . '/services/CutoverLegacyHostDependency.php');
$legacyTool = (string) file_get_contents(dirname(__DIR__) . '/tools/cutover_legacy_host_dependency.php');
$legacyDiagnosticWorkflow = (string) file_get_contents(dirname(__DIR__) . '/.github/workflows/standby-legacy-host-diagnostic.yml');

function cutoverPreflightAssert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

cutoverPreflightAssert(strpos($workflow, 'workflow_dispatch:') !== false, 'preflight must remain manually dispatched');
cutoverPreflightAssert(strpos($workflow, 'require_data_match:') !== false, 'preflight must support a strict final data-match gate');
cutoverPreflightAssert(strpos($workflow, 'require_legacy_host_independence:') !== false, 'preflight must support a strict legacy-host retirement gate');
cutoverPreflightAssert(strpos($workflow, 'not required for bot cutover') !== false, 'legacy-host independence must be documented as retirement-only');
cutoverPreflightAssert(strpos($workflow, 'standalone_readiness.php') !== false, 'preflight must verify standalone readiness');
cutoverPreflightAssert(strpos($workflow, 'cutover_legacy_host_dependency.php') !== false, 'preflight must inspect legacy host dependency');
cutoverPreflightAssert(strpos($workflow, 'cutover_data_snapshot.php') !== false, 'preflight must compare conversation-store snapshots');
cutoverPreflightAssert(strpos($workflow, 'BOT_CUTOVER_READY=') !== false, 'preflight must expose bot cutover readiness independently');
cutoverPreflightAssert(strpos($workflow, 'legacy_host_retirement_ready=') !== false, 'preflight must expose old-host retirement readiness independently');
cutoverPreflightAssert(strpos($workflow, 'BOT_CUTOVER_LEGACY_BRIDGE=ALLOWED') !== false, 'bot cutover must explicitly allow the intentional Bitrix lead bridge');
cutoverPreflightAssert(strpos($workflow, 'legacy_host_dependency_for_full_retirement') !== false, 'legacy dependency must block only full old-host retirement');
cutoverPreflightAssert(strpos($workflow, 'CUTOVER_PREFLIGHT_READY=YES') !== false, 'preflight must emit an explicit ready result');
cutoverPreflightAssert(strpos($workflow, 'production_sha_mismatch') !== false, 'preflight must detect stale production code');
cutoverPreflightAssert(strpos($workflow, 'standby_sha_mismatch') !== false, 'preflight must detect stale standby code');

foreach (['mysqldump ', 'mysql ', 'DROP DATABASE', 'DROP TABLE', 'TRUNCATE ', 'INSERT ', 'UPDATE ', 'DELETE ', 'webhook set'] as $forbidden) {
    cutoverPreflightAssert(stripos($workflow, $forbidden) === false, 'preflight must stay read-only: ' . $forbidden);
}
cutoverPreflightAssert(stripos($workflow, 'crontab ') === false, 'preflight must not install or mutate cron');
cutoverPreflightAssert(stripos($workflow, 'systemctl ') === false, 'preflight must not start or restart services');

cutoverPreflightAssert(strpos($workflow, 'secrets.DEPLOY_SSH_KEY') !== false, 'preflight should reuse production deploy SSH connection');
cutoverPreflightAssert(strpos($workflow, 'secrets.STANDBY_DEPLOY_SSH_KEY') !== false, 'preflight should reuse standby deploy SSH connection');

cutoverPreflightAssert(strpos($legacyService, 'LeadBridgeConfig::receiverUrl()') !== false, 'legacy-host detector must inspect configured lead receiver');
cutoverPreflightAssert(strpos($legacyService, "'anytour.online'") !== false, 'legacy-host detector must recognize canonical legacy host');
cutoverPreflightAssert(strpos($legacyService, "'lead_bridge'") !== false, 'legacy-host detector must identify lead bridge dependency');
cutoverPreflightAssert(strpos($legacyTool, "PHP_SAPI !== 'cli'") !== false, 'legacy-host diagnostic must remain CLI-only');
cutoverPreflightAssert(strpos($legacyTool, 'LEGACY_HOST_DEPENDENCY=') !== false, 'legacy-host diagnostic must expose dependency result');
cutoverPreflightAssert(strpos($legacyTool, 'LEAD_RECEIVER_HOST=') !== false, 'legacy-host diagnostic must expose receiver host without secrets');
cutoverPreflightAssert(strpos($legacyTool, 'MAX_SEARCH_LEAD_BRIDGE_SECRET') === false, 'legacy-host diagnostic must never expose bridge secret');
cutoverPreflightAssert(strpos($legacyTool, 'CONVERSATION_DB_PASS') === false, 'legacy-host diagnostic must never expose DB password');

cutoverPreflightAssert(strpos($legacyDiagnosticWorkflow, "branches: [main]") !== false, 'standby legacy-host diagnostic must run from main');
cutoverPreflightAssert(strpos($legacyDiagnosticWorkflow, "- 'tools/cutover_legacy_host_dependency.php'") !== false, 'standby diagnostic push trigger must stay scoped to detector changes');
cutoverPreflightAssert(strpos($legacyDiagnosticWorkflow, 'php tools/cutover_legacy_host_dependency.php') !== false, 'standby diagnostic must execute the non-secret detector');
foreach (['lead_bridge_probe.php', 'mysqldump ', 'mysql ', 'crontab ', 'systemctl ', 'webhook.php'] as $forbidden) {
    cutoverPreflightAssert(stripos($legacyDiagnosticWorkflow, $forbidden) === false, 'standby diagnostic must remain read-only: ' . $forbidden);
}

echo "OK cutover preflight contract regression\n";
