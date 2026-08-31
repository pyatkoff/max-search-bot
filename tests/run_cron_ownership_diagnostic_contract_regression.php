<?php

declare(strict_types=1);

$workflow=(string)file_get_contents(__DIR__.'/../.github/workflows/cron-ownership-diagnostic.yml');
$activate=(string)file_get_contents(__DIR__.'/../.github/workflows/activate-new-cron-owner.yml');
$required=['workflow_dispatch:','DEPLOY_SSH_KEY','STANDBY_DEPLOY_SSH_KEY','crontab -l','cron_followup\\.php','metrika_queue\\.php','${label}_BOT_CRON_COUNT=${count}','inspect "$RUNNER_TEMP/prod_key" "$DEPLOY_USER@$DEPLOY_HOST" LEGACY','inspect "$RUNNER_TEMP/standby_key" "$STANDBY_DEPLOY_USER@$STANDBY_DEPLOY_HOST" NEW'];
foreach($required as $needle){if(!str_contains($workflow,$needle)){fwrite(STDERR,"Missing cron diagnostic contract: {$needle}\n");exit(1);}}
foreach(['crontab -r','crontab -u','| crontab','systemctl','service ','kill ','rm -','sed -i'] as $forbidden){if(str_contains($workflow,$forbidden)){fwrite(STDERR,"Cron diagnostic is not read-only: {$forbidden}\n");exit(1);}}

foreach(['LEGACY_FOLLOWUP_CRON_COUNT=','NEW_FOLLOWUP_CRON_COUNT=','BOT_CRON_OWNERSHIP=NEW_ONLY','MAX_SHADOW_MODE=OFF','MAX_HEALTH=OK','tools/lead_bridge_probe.php'] as $needle){if(!str_contains($activate,$needle)){fwrite(STDERR,"Missing cron activation safety contract: {$needle}\n");exit(1);}}
$zeroSafe="grep -Ec '(^|[[:space:]])([^#[:space:]]*/)?cron_followup\\.php([[:space:]]|$)' || true";
if(substr_count($activate,$zeroSafe)!==4){fwrite(STDERR,"Cron activation count must tolerate zero matches under pipefail in both pre/post checks\n");exit(1);}
if(str_contains($activate,"| grep -E '(^|[[:space:]])([^#[:space:]]*/)?cron_followup\\.php([[:space:]]|$)' | wc -l")){fwrite(STDERR,"Cron activation still treats zero grep matches as a pipefail error\n");exit(1);}

foreach(['healthy_cutover_dual','https://app.anytoour.ru/webhook.php','https://anytour.online/max-search/webhook.php','unexpected_subscription_urls'] as $needle){if(!str_contains($activate,$needle)){fwrite(STDERR,"Cron activation does not recognize safe MAX cutover state: {$needle}\n");exit(1);}}
if(!str_contains($activate,'$single=($reason==="healthy"&&$count===1') || !str_contains($activate,'$dual=($reason==="healthy_cutover_dual"&&$count===2')){fwrite(STDERR,"Cron activation must accept only final single-owner or explicit healthy dual MAX ownership\n");exit(1);}
if(!str_contains($activate,'count($unexpected)===0')){fwrite(STDERR,"Healthy dual MAX preflight must reject unexpected subscriptions\n");exit(1);}

$probe='set -euo pipefail; count="$(printf "" | grep -Ec "cron_followup\\.php" || true)"; [[ "$count" == "0" ]]';
exec('bash -c '.escapeshellarg($probe),$out,$code);
if($code!==0){fwrite(STDERR,"Zero-match cron counting behavior is not pipefail-safe\n");exit(1);}

echo "cron ownership diagnostic/activation contract OK\n";
