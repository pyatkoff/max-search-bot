<?php

declare(strict_types=1);

$path = __DIR__ . '/../docs/BOT_CUTOVER_RUNBOOK.md';
$text = (string)file_get_contents($path);

$required = [
    'Never run old and new bot live-processing concurrently.',
    'production bot writes are actually frozen',
    'Do not disable the Bitrix lead receiver.',
    'two production conversation snapshots',
    'SYNC_CONVERSATION_DB',
    'writes_frozen=true',
    'exact final data match',
    'Legacy-host independence is not required for bot cutover.',
    'one controlled lead reaching Bitrix through the existing bridge',
    'stop new-server bot processing before re-enabling the old bot processing',
];
foreach ($required as $needle) { if (!str_contains($text,$needle)) { fwrite(STDERR,"Missing cutover invariant: {$needle}\n"); exit(1); } }
if (str_contains($text,'disable the Bitrix lead receiver.')) { if (substr_count($text,'disable the Bitrix lead receiver.')!==1 || !str_contains($text,'Do not disable the Bitrix lead receiver.')) { fwrite(STDERR,"Runbook may disable intentional Bitrix lead receiver\n"); exit(1); } }

$service=(string)file_get_contents(__DIR__.'/../services/WebhookTargetConfig.php');
$telegramAdmin=(string)file_get_contents(__DIR__.'/../telegram_webhook_admin.php');
$maxAdmin=(string)file_get_contents(__DIR__.'/../repair_max_search_subscription.php');
$template=(string)file_get_contents(__DIR__.'/../config.example.php');
$statusTool=(string)file_get_contents(__DIR__.'/../tools/webhook_target_status.php');
$workflow=(string)file_get_contents(__DIR__.'/../.github/workflows/standby-webhook-target-diagnostic.yml');
$fastWorkflow=(string)file_get_contents(__DIR__.'/../.github/workflows/fast-cutover.yml');
$maxCutover=(string)file_get_contents(__DIR__.'/../tools/max_subscription_cutover.php');
$maxTls=(string)file_get_contents(__DIR__.'/../services/MaxTlsConfig.php');
$standbyConfigTool=(string)file_get_contents(__DIR__.'/../tools/standby_enable_standalone.php');
$maxHandler=(string)file_get_contents(__DIR__.'/../handlers/MaxUpdateHandler.php');

foreach (['TELEGRAM_WEBHOOK_URL','MAX_SEARCH_WEBHOOK_URL','https://app.anytoour.ru/telegram_webhook.php','https://app.anytoour.ru/webhook.php','invalid_webhook_target:'] as $needle) { if (!str_contains($service.$template,$needle)) { fwrite(STDERR,"Missing webhook cutover contract: {$needle}\n"); exit(1); } }
if (!str_contains($telegramAdmin,'WebhookTargetConfig::telegram()')) { fwrite(STDERR,"Telegram webhook admin bypasses configurable target\n"); exit(1); }
if (!str_contains($maxAdmin,'WebhookTargetConfig::max()')) { fwrite(STDERR,"MAX subscription admin bypasses configurable target\n"); exit(1); }
if (str_contains($telegramAdmin,"\$webhookUrl = 'https://app.anytoour.ru")) { fwrite(STDERR,"Telegram webhook target remains hardcoded to legacy host\n"); exit(1); }
foreach (['WebhookTargetConfig::telegram()','WebhookTargetConfig::max()','TELEGRAM_WEBHOOK_TARGET_HOST=','MAX_WEBHOOK_TARGET_HOST='] as $needle) { if (!str_contains($statusTool,$needle)) { fwrite(STDERR,"Missing webhook target diagnostic contract: {$needle}\n"); exit(1); } }
if (preg_match('/TOKEN|SECRET|PASS|PASSWORD/',$statusTool)) { fwrite(STDERR,"Webhook target diagnostic may expose secrets\n"); exit(1); }
foreach (['workflow_dispatch:','tools/webhook_target_status.php','STANDBY_DEPLOY_SSH_KEY','STANDBY_DEPLOY_HOST','STANDBY_DEPLOY_USER'] as $needle) { if (!str_contains($workflow,$needle)) { fwrite(STDERR,"Missing standby webhook diagnostic contract: {$needle}\n"); exit(1); } }
foreach (['setWebhook','deleteWebhook','/subscriptions','systemctl','crontab'] as $forbidden) { if (str_contains($workflow,$forbidden)) { fwrite(STDERR,"Standby webhook diagnostic is not read-only: {$forbidden}\n"); exit(1); } }

foreach ([
    "paths:\n      - '.github/workflows/fast-cutover.yml'",
    'workflow_dispatch:',
    'MAX_SEARCH_ALLOW_STANDBY_CONFIG_WRITE=1',
    'https://app.anytoour.ru/telegram_webhook.php',
    'max-search-pre-fast-cutover-',
    '--single-transaction --quick --skip-lock-tables --no-tablespaces',
    'FAST_DB_IMPORT=OK',
    'action=set',
    'tools/max_subscription_cutover.php --add-new',
    'MAX_SUBSCRIPTION_TARGET_OK=YES',
    'MAX_SUBSCRIPTION_LEGACY_COUNT=1',
    'MAX_SUBSCRIPTION_NEW_COUNT=1',
    'tools/lead_bridge_probe.php',
    'BOT_CUTOVER=COMPLETE',
    'Detect partial cutover ownership',
    'TELEGRAM_ALREADY_NEW=1',
    "if: env.TELEGRAM_ALREADY_NEW != '1'",
    'PARTIAL_CUTOVER_RESUME=YES',
    'healthy_cutover_dual',
    'legacy is not removed by this workflow',
    'resume mode skipped DB overwrite and Telegram mutation',
    'small number of conversations created after the dump started and before webhook switch may not exist on the new DB',
] as $needle) { if (!str_contains($fastWorkflow,$needle)) { fwrite(STDERR,"Missing fast cutover invariant: {$needle}\n"); exit(1); } }
foreach (["'MAX_SEARCH_WEBHOOK_URL' => \"'https://app.anytoour.ru/webhook.php'\"","'TELEGRAM_WEBHOOK_URL' => \"'https://app.anytoour.ru/telegram_webhook.php'\"","'MAX_SEARCH_PUBLIC_BASE_URL' => \"'https://app.anytoour.ru'\"","'MAX_SEARCH_TRACKING_BASE_URL' => \"'https://app.anytoour.ru'\""] as $needle) { if (!str_contains($standbyConfigTool,$needle)) { fwrite(STDERR,"Standby cutover URL/mode not pinned: {$needle}\n"); exit(1); } }
if (str_contains($standbyConfigTool,"'MAX_SEARCH_MAX_SHADOW_MODE' =>")) { fwrite(STDERR,"Standalone deploy must preserve MAX live/shadow processing state\n"); exit(1); }
foreach (['--add-new','--activate-new','--rollback-old','https://app.anytoour.ru/webhook.php','WebhookTargetConfig::max()',"createSubscription(\$api, \$token, \$newUrl)",'MAX_SUBSCRIPTION_TARGET_OK=','MaxTlsConfig::curlOptions(false)'] as $needle) { if (!str_contains($maxCutover,$needle)) { fwrite(STDERR,"Missing MAX cutover safety contract: {$needle}\n"); exit(1); } }
foreach (['CURLOPT_SSL_VERIFYPEER => true','CURLOPT_SSL_VERIFYHOST => 2','CURLOPT_CAINFO','MAX_SEARCH_MAX_API_INSECURE_COMPAT'] as $needle) { if (!str_contains($maxTls,$needle)) { fwrite(STDERR,"Missing MAX TLS policy contract: {$needle}\n"); exit(1); } }
foreach (['MAX_SEARCH_MAX_SHADOW_MODE','SHADOW_UPDATE_RECEIVED'] as $needle) { if (!str_contains($maxHandler,$needle)) { fwrite(STDERR,"Missing MAX shadow-processing guard: {$needle}\n"); exit(1); } }
if (str_contains($fastWorkflow,'MAX_SEARCH_MAX_API_INSECURE_COMPAT=1')) { fwrite(STDERR,"Fast cutover must not disable MAX TLS verification\n"); exit(1); }
if (str_contains($fastWorkflow,'lead-receiver.php --disable') || str_contains($fastWorkflow,'rm lead-receiver.php')) { fwrite(STDERR,"Fast cutover may disable intentional Bitrix receiver\n"); exit(1); }

echo "bot cutover runbook contract OK\n";
