<?php

declare(strict_types=1);

$base=dirname(__DIR__);
$workflow=(string)file_get_contents($base.'/.github/workflows/deploy-standby.yml');
$production=(string)file_get_contents($base.'/.github/workflows/deploy.yml');
$switch=(string)file_get_contents($base.'/tools/standby_enable_standalone.php');
$repair=(string)file_get_contents($base.'/tools/repair_standby_external_config.php');
$cleanup=(string)file_get_contents($base.'/tools/standby_cleanup_runtime_config.php');
$passed=0;$failed=0;
function standbyCheck(string $name,bool $ok):void{global $passed,$failed;if($ok){echo "PASS  {$name}\n";$passed++;return;}echo "FAIL  {$name}\n";$failed++;}

standbyCheck('standby has independent secrets',strpos($workflow,'STANDBY_DEPLOY_HOST')!==false&&strpos($workflow,'STANDBY_DEPLOY_USER')!==false&&strpos($workflow,'STANDBY_DEPLOY_SSH_KEY')!==false);
standbyCheck('standby targets new app checkout',strpos($workflow,'/var/www/anytoour/data/www/app.anytoour.ru')!==false);
standbyCheck('standby packages exact checkout into git bundle',strpos($workflow,'Checkout exact deployment SHA')!==false&&strpos($workflow,'git bundle create "$RUNNER_TEMP/canonical-deploy.bundle" HEAD')!==false&&strpos($workflow,'git bundle verify "$RUNNER_TEMP/canonical-deploy.bundle"')!==false);
standbyCheck('standby transfers bundle over deployment ssh',strpos($workflow,'scp "${ssh_opts[@]}" "$bundle_file"')!==false&&strpos($workflow,'git fetch \'$remote_bundle\' HEAD')!==false);
standbyCheck('standby does not depend on server github credentials',strpos($workflow,'git@github.com')===false&&strpos($workflow,'github_anytoour_deploy')===false&&strpos($workflow,'git fetch origin main')===false);
standbyCheck('standby binds deploy to workflow sha',strpos($workflow,'EXPECTED_SHA: ${{ github.sha }}')!==false&&strpos($workflow,"git cat-file -e '\$EXPECTED_SHA^{commit}'")!==false&&strpos($workflow,"git reset --hard '\$EXPECTED_SHA'")!==false);
standbyCheck('standby does not race against moving origin main',strpos($workflow,'git reset --hard origin/main')===false);
standbyCheck('standby verifies resulting exact sha',strpos($workflow,'git rev-parse HEAD')!==false&&strpos($workflow,"= '\$EXPECTED_SHA'")!==false);
$migrationPos=strpos($workflow,'php tools/conversation_db.php migrate');
$cleanupPos=strpos($workflow,'php tools/standby_cleanup_runtime_config.php');
standbyCheck('standby applies forward conversation migrations',$migrationPos!==false);
standbyCheck('standby cleans legacy runtime overrides before migrations',$cleanupPos!==false&&$migrationPos!==false&&$cleanupPos<$migrationPos);
standbyCheck('standby cleans runtime overrides exactly once',substr_count($workflow,'php tools/standby_cleanup_runtime_config.php')===1);
standbyCheck('standby enables standalone only behind explicit write guard',strpos($workflow,'MAX_SEARCH_ALLOW_STANDBY_CONFIG_WRITE=1')!==false&&strpos($workflow,'standby_enable_standalone.php --enable')!==false);
standbyCheck('standby retains switch only after green readiness doctor',strpos($workflow,'if php tools/standalone_readiness.php; then')!==false&&strpos($workflow,'standby_enable_standalone.php --commit')!==false);
standbyCheck('standby rolls config back if readiness fails',strpos($workflow,'standby_enable_standalone.php --rollback')!==false);
standbyCheck('switch is restricted to standby checkout',strpos($switch,"'/app.anytoour.ru'")!==false);
standbyCheck('switch changes only cutover mode constants',strpos($switch,"'MAX_SEARCH_STANDALONE_RUNTIME'")!==false&&strpos($switch,"'MAX_SEARCH_RUNTIME_STORAGE'")!==false&&strpos($switch,"'MAX_SEARCH_DESTINATION_STORAGE'")!==false&&strpos($switch,"'MAX_SEARCH_LEAD_DELIVERY'")!==false);
standbyCheck('switch removes only complete direct target definitions',strpos($switch,'$define = preg_match')!==false&&strpos($switch,'$const = preg_match')!==false&&strpos($switch,'Never delete structural guard/if')!==false&&strpos($switch,'MAX_SEARCH_WEBHOOK_URL')!==false);
standbyCheck('runtime cleanup is standby-write guarded',strpos($cleanup,"MAX_SEARCH_ALLOW_STANDBY_CONFIG_WRITE")!==false&&strpos($cleanup,"'/app.anytoour.ru'")!==false);
standbyCheck('runtime cleanup requires external config ownership',strpos($cleanup,'data/config/max-search.php')!==false&&strpos($cleanup,'Runtime config does not reference external standby config')!==false);
standbyCheck('runtime cleanup targets only deployment-owned duplicate constants',strpos($cleanup,"'MAX_SEARCH_STANDALONE_RUNTIME'")!==false&&strpos($cleanup,"'MAX_SEARCH_WEBHOOK_URL'")!==false&&strpos($cleanup,"'TELEGRAM_WEBHOOK_URL'")!==false&&strpos($cleanup,"'MAX_SEARCH_PUBLIC_BASE_URL'")!==false);
standbyCheck('runtime cleanup is lint gated and atomic',strpos($cleanup,'failed PHP lint')!==false&&strpos($cleanup,'rename($tmp, $runtimeConfig)')!==false);
standbyCheck('repair has lint-gated malformed-config fallback',strpos($repair,'Previous cutover attempts may already have left unmatched braces')!==false&&strpos($repair,"\$safeLines = ['<?php'];")!==false&&strpos($repair,'Repaired standby config still fails PHP lint')!==false);
standbyCheck('repair preserves existing recovery backup',strpos($repair,'!is_file($backup) && !copy($config, $backup)')!==false);
standbyCheck('standby does not invoke webhook endpoints',strpos($workflow,'php webhook.php')===false&&strpos($workflow,'curl')===false);
standbyCheck('standby does not start or restart services',!preg_match('/\b(systemctl|service|supervisorctl)\b/',$workflow));
standbyCheck('standby does not install cron',!preg_match('/\bcrontab\b/',$workflow));
standbyCheck('legacy production target remains unchanged',strpos($production,'cd ~/www/anytour.online/max-search')!==false);
standbyCheck('legacy deploy does not consume standby credentials',strpos($production,'STANDBY_DEPLOY_')===false);
standbyCheck('production deploy binds sync to workflow sha',strpos($production,'EXPECTED_SHA: ${{ github.sha }}')!==false&&strpos($production,'envs: EXPECTED_SHA')!==false&&strpos($production,'git cat-file -e "$EXPECTED_SHA^{commit}"')!==false&&strpos($production,'git reset --hard "$EXPECTED_SHA"')!==false);
standbyCheck('production deploy does not race against moving origin main',strpos($production,'git reset --hard origin/main')===false);
standbyCheck('production deploy verifies resulting exact sha',strpos($production,'test "$(git rev-parse HEAD)" = "$EXPECTED_SHA"')!==false);

$total=$passed+$failed;
echo "\n--------------------------\nTOTAL {$total} | PASS {$passed} | FAIL {$failed}\n";
exit($failed?1:0);
