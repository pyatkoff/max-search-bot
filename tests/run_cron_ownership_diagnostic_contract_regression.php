<?php

declare(strict_types=1);

$root=dirname(__DIR__);
$workflow=(string)file_get_contents($root.'/.github/workflows/cron-ownership-diagnostic.yml');
$recovery=(string)file_get_contents($root.'/.github/workflows/restore-live-runtime.yml');

function cronOwnershipAssert(bool $condition,string $message):void
{
    if(!$condition){fwrite(STDERR,"FAIL: {$message}\n");exit(1);}
}

cronOwnershipAssert(!is_file($root.'/.github/workflows/activate-new-cron-owner.yml'),'one-time cron activation workflow must stay retired');
$required=['workflow_dispatch:','DEPLOY_SSH_KEY','STANDBY_DEPLOY_SSH_KEY','crontab -l','cron_followup\\.php','metrika_queue\\.php','${label}_BOT_CRON_COUNT=${count}','inspect "$RUNNER_TEMP/prod_key" "$DEPLOY_USER@$DEPLOY_HOST" LEGACY','inspect "$RUNNER_TEMP/standby_key" "$STANDBY_DEPLOY_USER@$STANDBY_DEPLOY_HOST" NEW'];
foreach($required as $needle){cronOwnershipAssert(str_contains($workflow,$needle),'missing cron diagnostic contract: '.$needle);}
foreach(['crontab -r','crontab -u','| crontab','systemctl','service ','kill ','rm -','sed -i'] as $forbidden){cronOwnershipAssert(!str_contains($workflow,$forbidden),'cron diagnostic remains read-only: '.$forbidden);}

foreach(['LEGACY_FOLLOWUP_CRON_COUNT=','NEW_FOLLOWUP_CRON_COUNT=1','BOT_CRON_OWNERSHIP=NEW_ONLY','MAX_SHADOW_MODE=OFF','MAX_NEW_ONLY_HEALTH=OK','tools/lead_bridge_probe.php','/var/www/anytoour/data/www/app.anytoour.ru/cron_followup.php'] as $needle){cronOwnershipAssert(str_contains($recovery,$needle),'canonical recovery must preserve cron ownership safety: '.$needle);}
cronOwnershipAssert(str_contains($recovery,'subscription_count') && str_contains($recovery,'!==1'),'canonical recovery must require one MAX subscription');
cronOwnershipAssert(!str_contains($recovery,'healthy_cutover_dual'),'canonical recovery must not accept dual MAX ownership');

$probe='set -euo pipefail; count="$(printf "" | grep -Ec "cron_followup\\.php" || true)"; [[ "$count" == "0" ]]';
exec('bash -c '.escapeshellarg($probe),$out,$code);
cronOwnershipAssert($code===0,'zero-match cron counting behavior remains pipefail-safe');

echo "cron ownership diagnostic/canonical recovery contract OK\n";
