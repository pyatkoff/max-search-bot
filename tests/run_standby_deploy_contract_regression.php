<?php

declare(strict_types=1);

$base=dirname(__DIR__);
$workflow=(string)file_get_contents($base.'/.github/workflows/deploy-standby.yml');
$production=(string)file_get_contents($base.'/.github/workflows/deploy.yml');
$switch=(string)file_get_contents($base.'/tools/standby_enable_standalone.php');
$passed=0;$failed=0;
function standbyCheck(string $name,bool $ok):void{global $passed,$failed;if($ok){echo "PASS  {$name}\n";$passed++;return;}echo "FAIL  {$name}\n";$failed++;}

standbyCheck('standby has independent secrets',strpos($workflow,'STANDBY_DEPLOY_HOST')!==false&&strpos($workflow,'STANDBY_DEPLOY_USER')!==false&&strpos($workflow,'STANDBY_DEPLOY_SSH_KEY')!==false);
standbyCheck('standby targets new app checkout',strpos($workflow,'/var/www/anytoour/data/www/app.anytoour.ru')!==false);
standbyCheck('standby binds deploy to workflow sha',strpos($workflow,'EXPECTED_SHA: ${{ github.sha }}')!==false&&strpos($workflow,"git cat-file -e '\$EXPECTED_SHA^{commit}'")!==false&&strpos($workflow,"git reset --hard '\$EXPECTED_SHA'")!==false);
standbyCheck('standby does not race against moving origin main',strpos($workflow,'git reset --hard origin/main')===false);
standbyCheck('standby verifies resulting exact sha',strpos($workflow,'git rev-parse HEAD')!==false&&strpos($workflow,"= '\$EXPECTED_SHA'")!==false);
standbyCheck('standby applies forward conversation migrations',strpos($workflow,'php tools/conversation_db.php migrate')!==false);
standbyCheck('standby enables standalone only behind explicit write guard',strpos($workflow,'MAX_SEARCH_ALLOW_STANDBY_CONFIG_WRITE=1')!==false&&strpos($workflow,'standby_enable_standalone.php --enable')!==false);
standbyCheck('standby retains switch only after green readiness doctor',strpos($workflow,'if php tools/standalone_readiness.php; then')!==false&&strpos($workflow,'standby_enable_standalone.php --commit')!==false);
standbyCheck('standby rolls config back if readiness fails',strpos($workflow,'standby_enable_standalone.php --rollback')!==false);
standbyCheck('switch is restricted to standby checkout',strpos($switch,"'/app.anytoour.ru'")!==false);
standbyCheck('switch changes only cutover mode constants',strpos($switch,"'MAX_SEARCH_STANDALONE_RUNTIME'")!==false&&strpos($switch,"'MAX_SEARCH_RUNTIME_STORAGE'")!==false&&strpos($switch,"'MAX_SEARCH_DESTINATION_STORAGE'")!==false&&strpos($switch,"'MAX_SEARCH_LEAD_DELIVERY'")!==false);
standbyCheck('switch removes every stale cutover-key line before canonical definition',strpos($switch,"preg_match('/\\b' . \$namePattern . '\\b/'")!==false&&strpos($switch,"\$lines[] = \"define('{\$name}', {\$value});\"")!==false&&strpos($switch,"MAX_SEARCH_WEBHOOK_URL")!==false);
standbyCheck('standby does not invoke webhook endpoints',strpos($workflow,'php webhook.php')===false&&strpos($workflow,'curl')===false);
standbyCheck('standby does not start or restart services',!preg_match('/\b(systemctl|service|supervisorctl)\b/',$workflow));
standbyCheck('standby does not install cron',!preg_match('/\bcrontab\b/',$workflow));
standbyCheck('legacy production target remains unchanged',strpos($production,'cd ~/www/anytour.online/max-search')!==false);
standbyCheck('legacy deploy does not consume standby credentials',strpos($production,'STANDBY_DEPLOY_')===false);

$total=$passed+$failed;
echo "\n--------------------------\nTOTAL {$total} | PASS {$passed} | FAIL {$failed}\n";
exit($failed?1:0);
