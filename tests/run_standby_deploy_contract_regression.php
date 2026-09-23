<?php

declare(strict_types=1);

$base=dirname(__DIR__);
$workflow=(string)file_get_contents($base.'/.github/workflows/deploy-standby.yml');
$production=(string)file_get_contents($base.'/.github/workflows/deploy.yml');
$switch=(string)file_get_contents($base.'/tools/standby_enable_standalone.php');
$repair=(string)file_get_contents($base.'/tools/repair_standby_external_config.php');
$cleanup=(string)file_get_contents($base.'/tools/standby_cleanup_runtime_config.php');
$leadRepairTool=(string)file_get_contents($base.'/tools/repair_lead_receiver_config.php');
$leadRepairWorkflow=(string)file_get_contents($base.'/.github/workflows/repair-lead-receiver-config.yml');
$legacyReceiverSync=(string)file_get_contents($base.'/.github/workflows/sync-legacy-lead-receiver.yml');
require_once $base.'/services/LeadReceiverConfigEditor.php';
$passed=0;$failed=0;
function standbyCheck(string $name,bool $ok):void{global $passed,$failed;if($ok){echo "PASS  {$name}\n";$passed++;return;}echo "FAIL  {$name}\n";$failed++;}

standbyCheck('standby has independent secrets',strpos($workflow,'STANDBY_DEPLOY_HOST')!==false&&strpos($workflow,'STANDBY_DEPLOY_USER')!==false&&strpos($workflow,'STANDBY_DEPLOY_SSH_KEY')!==false);
standbyCheck('standby targets canonical app checkout',strpos($workflow,'/var/www/anytoour/data/www/app.anytoour.ru')!==false);
standbyCheck('standby packages exact checkout into git bundle',strpos($workflow,'Checkout exact deployment SHA')!==false&&strpos($workflow,'git bundle create "$RUNNER_TEMP/canonical-deploy.bundle" HEAD')!==false&&strpos($workflow,'git bundle verify "$RUNNER_TEMP/canonical-deploy.bundle"')!==false);
standbyCheck('standby transfers bundle over deployment ssh',strpos($workflow,'scp "${ssh_opts[@]}" "$bundle_file"')!==false&&strpos($workflow,'git fetch \'$remote_bundle\' HEAD')!==false);
standbyCheck('standby does not depend on server github credentials',strpos($workflow,'git@github.com')===false&&strpos($workflow,'github_anytoour_deploy')===false&&strpos($workflow,'git fetch origin main')===false);
standbyCheck('standby binds deploy to workflow sha',strpos($workflow,'EXPECTED_SHA: ${{ github.sha }}')!==false&&strpos($workflow,"git cat-file -e '\$EXPECTED_SHA^{commit}'")!==false&&strpos($workflow,"git reset --hard '\$EXPECTED_SHA'")!==false);
standbyCheck('standby verifies resulting exact sha',strpos($workflow,'git rev-parse HEAD')!==false&&strpos($workflow,"= '\$EXPECTED_SHA'")!==false);
$migrationPos=strpos($workflow,'php tools/conversation_db.php migrate');
$cleanupPos=strpos($workflow,'php tools/standby_cleanup_runtime_config.php');
standbyCheck('standby applies forward conversation migrations',$migrationPos!==false);
standbyCheck('standby cleans runtime overrides before migrations',$cleanupPos!==false&&$migrationPos!==false&&$cleanupPos<$migrationPos);
standbyCheck('standby cleans runtime overrides exactly once',substr_count($workflow,'php tools/standby_cleanup_runtime_config.php')===1);
standbyCheck('standby enables standalone only behind explicit write guard',strpos($workflow,'MAX_SEARCH_ALLOW_STANDBY_CONFIG_WRITE=1')!==false&&strpos($workflow,'standby_enable_standalone.php --enable')!==false);
standbyCheck('standby retains switch only after green readiness doctor',strpos($workflow,'if php tools/standalone_readiness.php; then')!==false&&strpos($workflow,'standby_enable_standalone.php --commit')!==false);
standbyCheck('standby rolls config back if readiness fails',strpos($workflow,'standby_enable_standalone.php --rollback')!==false);
standbyCheck('switch is restricted to canonical checkout',strpos($switch,"'/app.anytoour.ru'")!==false);
standbyCheck('switch changes only cutover mode constants',strpos($switch,"'MAX_SEARCH_STANDALONE_RUNTIME'")!==false&&strpos($switch,"'MAX_SEARCH_RUNTIME_STORAGE'")!==false&&strpos($switch,"'MAX_SEARCH_DESTINATION_STORAGE'")!==false&&strpos($switch,"'MAX_SEARCH_LEAD_DELIVERY'")!==false);
standbyCheck('runtime cleanup is standby-write guarded',strpos($cleanup,'MAX_SEARCH_ALLOW_STANDBY_CONFIG_WRITE')!==false&&strpos($cleanup,"'/app.anytoour.ru'")!==false);
standbyCheck('runtime cleanup is lint gated and atomic',strpos($cleanup,'failed PHP lint')!==false&&strpos($cleanup,'rename($tmp, $runtimeConfig)')!==false);
standbyCheck('repair preserves existing recovery backup',strpos($repair,'!is_file($backup) && !copy($config, $backup)')!==false);
standbyCheck('standby does not invoke webhook endpoints',strpos($workflow,'php webhook.php')===false&&strpos($workflow,'curl')===false);
standbyCheck('standby does not start or restart services',!preg_match('/\b(systemctl|service|supervisorctl)\b/',$workflow));
standbyCheck('standby does not install cron',!preg_match('/\bcrontab\b/',$workflow));

standbyCheck('production targets canonical app checkout',strpos($production,'/var/www/anytoour/data/www/app.anytoour.ru')!==false);
standbyCheck('production uses canonical server credentials',strpos($production,'secrets.STANDBY_DEPLOY_HOST')!==false&&strpos($production,'secrets.STANDBY_DEPLOY_USER')!==false&&strpos($production,'secrets.STANDBY_DEPLOY_SSH_KEY')!==false);
standbyCheck('production packages exact workflow sha',strpos($production,'Checkout exact deployment SHA')!==false&&strpos($production,'git bundle create "$RUNNER_TEMP/production-deploy.bundle" HEAD')!==false&&strpos($production,'git bundle verify "$RUNNER_TEMP/production-deploy.bundle"')!==false);
standbyCheck('production transfers bundle over ssh',strpos($production,'scp "${ssh_opts[@]}" "$bundle_file"')!==false&&strpos($production,'git fetch \'$remote_bundle\' HEAD')!==false);
standbyCheck('production binds sync to workflow sha',strpos($production,'EXPECTED_SHA: ${{ github.sha }}')!==false&&strpos($production,"git cat-file -e '\$EXPECTED_SHA^{commit}'")!==false&&strpos($production,"git reset --hard '\$EXPECTED_SHA'")!==false);
standbyCheck('production does not depend on server github fetch',strpos($production,'git fetch origin main')===false&&strpos($production,'git@github.com')===false);
standbyCheck('production verifies resulting exact sha',strpos($production,'git rev-parse HEAD')!==false&&strpos($production,"= '\$EXPECTED_SHA'")!==false);

standbyCheck('lead receiver editor accepts exact legacy receiver shape',LeadReceiverConfigEditor::validateUrl('https://legacy.example/max-search/lead-receiver.php')['host']==='legacy.example');
$badTargets=[
    'http://legacy.example/max-search/lead-receiver.php',
    'https://app.anytoour.ru/max-search/lead-receiver.php',
    'https://legacy.example/lead-receiver.php',
    'https://legacy.example/max-search/lead-receiver.php?x=1',
];
foreach($badTargets as $target){
    $rejected=false;try{LeadReceiverConfigEditor::validateUrl($target);}catch(InvalidArgumentException $e){$rejected=true;}
    standbyCheck('lead receiver editor rejects unsafe target '.md5($target),$rejected);
}
$sample="<?php\ndefine('KEEP_SECRET','secret-value');\ndefine('MAX_SEARCH_LEAD_RECEIVER_URL','https://wrong.example/max-search/lead-receiver.php');\n?>\n";
$rewritten=LeadReceiverConfigEditor::rewrite($sample,'https://legacy.example/max-search/lead-receiver.php');
standbyCheck('lead receiver rewrite preserves unrelated config',strpos($rewritten,"define('KEEP_SECRET','secret-value');")!==false);
standbyCheck('lead receiver rewrite owns only one target definition',substr_count($rewritten,'MAX_SEARCH_LEAD_RECEIVER_URL')===1&&LeadReceiverConfigEditor::directValue($rewritten)==='https://legacy.example/max-search/lead-receiver.php');
standbyCheck('lead receiver repair tool is cli and write guarded',strpos($leadRepairTool,"PHP_SAPI !== 'cli'")!==false&&strpos($leadRepairTool,'MAX_SEARCH_ALLOW_STANDBY_CONFIG_WRITE')!==false);
standbyCheck('lead receiver repair tool mutates only external config target',strpos($leadRepairTool,"'/var/www/anytoour/data/config/max-search.php'")!==false&&strpos($leadRepairTool,'LeadReceiverConfigEditor::rewrite')!==false&&strpos($leadRepairTool,'MAX_SEARCH_LEAD_BRIDGE_SECRET')===false);
standbyCheck('lead receiver repair tool is lint gated atomic and rollbackable',strpos($leadRepairTool,'failed PHP lint')!==false&&strpos($leadRepairTool,'rename($tmp, $config)')!==false&&strpos($leadRepairTool,'--rollback')!==false&&strpos($leadRepairTool,'--commit')!==false);
standbyCheck('lead receiver repair discovers existing legacy receiver rather than embedding retired host',strpos($leadRepairWorkflow,'*/max-search/lead-receiver.php')!==false&&strpos($leadRepairWorkflow,'LEGACY_LEAD_RECEIVER_DISCOVERY=OK')!==false);
standbyCheck('lead receiver repair waits for exact production sha',strpos($leadRepairWorkflow,'EXPECTED_SHA: ${{ github.sha }}')!==false&&strpos($leadRepairWorkflow,'CANONICAL_SHA_VERIFIED=')!==false);
standbyCheck('lead receiver repair verifies read-only bridge auth before committing config',strpos($leadRepairWorkflow,'php tools/lead_bridge_probe.php')!==false&&strpos($leadRepairWorkflow,'standalone_readiness.php')!==false&&strpos($leadRepairWorkflow,'--commit')!==false);
standbyCheck('lead receiver repair never sends a synthetic lead',strpos($leadRepairWorkflow,'lead-receiver.php')!==false&&strpos($leadRepairWorkflow,'CURLOPT_POST')===false&&strpos($leadRepairWorkflow,'--data')===false);
standbyCheck('lead receiver repair waits for natural Telegram retry and uses non-invasive smoke',strpos($leadRepairWorkflow,'pending_update_count')!==false&&strpos($leadRepairWorkflow,'telegram_start_smoke.php')!==false);
standbyCheck('legacy receiver sync uses exact source checkout and both bounded ssh owners',strpos($legacyReceiverSync,'ref: ${{ github.sha }}')!==false&&strpos($legacyReceiverSync,'secrets.DEPLOY_SSH_KEY')!==false&&strpos($legacyReceiverSync,'secrets.STANDBY_DEPLOY_SSH_KEY')!==false&&strpos($legacyReceiverSync,'ConnectTimeout=10')!==false);
standbyCheck('legacy receiver sync discovers only max-search receiver without hardcoded retired host',strpos($legacyReceiverSync,'*/max-search/lead-receiver.php')!==false&&strpos($legacyReceiverSync,'LEGACY_RECEIVER_DISCOVERY=OK')!==false);
standbyCheck('legacy receiver sync copies only receiver bootstrap files',strpos($legacyReceiverSync,'scp "${opts[@]}" lead-receiver.php')!==false&&strpos($legacyReceiverSync,'scp "${opts[@]}" services/RuntimeBootstrap.php')!==false);
standbyCheck('legacy receiver sync is rollbackable before verification',strpos($legacyReceiverSync,'rollback_legacy')!==false&&strpos($legacyReceiverSync,'max-search-lead-receiver-$EXPECTED_SHA.bak')!==false);
standbyCheck('legacy receiver sync verifies public GET and canonical HMAC without synthetic POST',strpos($legacyReceiverSync,'LEAD_BRIDGE_PROBE=OK')!==false&&strpos($legacyReceiverSync,'curl -sS -o')!==false&&strpos($legacyReceiverSync,'--data')===false&&strpos($legacyReceiverSync,'CURLOPT_POST')===false);
standbyCheck('legacy receiver sync waits for natural Telegram retry',strpos($legacyReceiverSync,'pending_update_count')!==false&&strpos($legacyReceiverSync,'telegram_start_smoke.php')!==false);
standbyCheck('legacy receiver sync retains rollback until Telegram confirmation',strpos($legacyReceiverSync,'LEGACY_RECEIVER_SYNC=VERIFIED_BACKUP_RETAINED')!==false&&strpos($legacyReceiverSync,'LEGACY_RECEIVER_SYNC=COMMITTED')!==false&&strpos($legacyReceiverSync,'runtime_absent=/tmp/max-search-runtime-bootstrap-$EXPECTED_SHA.absent')!==false);
standbyCheck('legacy receiver sync rolls back when natural retry never clears',strpos($legacyReceiverSync,'Telegram pending update did not clear after legacy receiver bootstrap')!==false&&strpos($legacyReceiverSync,'rollback_legacy')!==false);
$total=$passed+$failed;
echo "\n--------------------------\nTOTAL {$total} | PASS {$passed} | FAIL {$failed}\n";
exit($failed?1:0);
