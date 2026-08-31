<?php

declare(strict_types=1);

$base=dirname(__DIR__);
$workflow=(string)file_get_contents($base.'/.github/workflows/deploy-standby.yml');
$production=(string)file_get_contents($base.'/.github/workflows/deploy.yml');
$passed=0;$failed=0;
function standbyCheck(string $name,bool $ok):void{global $passed,$failed;if($ok){echo "PASS  {$name}\n";$passed++;return;}echo "FAIL  {$name}\n";$failed++;}

standbyCheck('standby has independent secrets',
    strpos($workflow,'STANDBY_DEPLOY_HOST')!==false &&
    strpos($workflow,'STANDBY_DEPLOY_USER')!==false &&
    strpos($workflow,'STANDBY_DEPLOY_SSH_KEY')!==false
);
standbyCheck('standby targets new app checkout',strpos($workflow,'/var/www/anytoour/data/www/app.anytoour.ru')!==false);
standbyCheck('standby deploys exact main sha',strpos($workflow,'git reset --hard origin/main')!==false&&strpos($workflow,'EXPECTED_SHA: ${{ github.sha }}')!==false);
standbyCheck('standby applies forward conversation migrations',strpos($workflow,'php tools/conversation_db.php migrate')!==false);
standbyCheck('standby does not invoke webhook endpoints',strpos($workflow,'php webhook.php')===false&&strpos($workflow,'curl')===false);
standbyCheck('standby does not start or restart services',!preg_match('/\b(systemctl|service|supervisorctl)\b/',$workflow));
standbyCheck('standby does not install cron',!preg_match('/\bcrontab\b/',$workflow));
standbyCheck('legacy production target remains unchanged',strpos($production,'cd ~/www/anytour.online/max-search')!==false);
standbyCheck('legacy deploy does not consume standby credentials',strpos($production,'STANDBY_DEPLOY_')===false);

$total=$passed+$failed;
echo "\n--------------------------\nTOTAL {$total} | PASS {$passed} | FAIL {$failed}\n";
exit($failed?1:0);
