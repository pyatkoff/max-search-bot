<?php

declare(strict_types=1);

$workflow=(string)file_get_contents(__DIR__.'/../.github/workflows/cron-ownership-diagnostic.yml');
$required=['workflow_dispatch:','DEPLOY_SSH_KEY','STANDBY_DEPLOY_SSH_KEY','crontab -l','cron_followup\\.php','metrika_queue\\.php','LEGACY_BOT_CRON_COUNT=','NEW_BOT_CRON_COUNT='];
foreach($required as $needle){if(!str_contains($workflow,$needle)){fwrite(STDERR,"Missing cron diagnostic contract: {$needle}\n");exit(1);}}
foreach(['crontab -','crontab -r','systemctl','service ','kill ','rm -','sed -i'] as $forbidden){if(str_contains($workflow,$forbidden)){fwrite(STDERR,"Cron diagnostic is not read-only: {$forbidden}\n");exit(1);}}
echo "cron ownership diagnostic contract OK\n";
