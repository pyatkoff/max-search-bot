<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$path = $root . '/docs/BOT_CUTOVER_RUNBOOK.md';
$text = (string)file_get_contents($path);
$service=(string)file_get_contents($root.'/services/WebhookTargetConfig.php');
$telegramAdmin=(string)file_get_contents($root.'/telegram_webhook_admin.php');
$maxAdmin=(string)file_get_contents($root.'/repair_max_search_subscription.php');
$template=(string)file_get_contents($root.'/config.example.php');
$statusTool=(string)file_get_contents($root.'/tools/webhook_target_status.php');
$workflow=(string)file_get_contents($root.'/.github/workflows/standby-webhook-target-diagnostic.yml');
$recovery=(string)file_get_contents($root.'/.github/workflows/restore-live-runtime.yml');
$maxTls=(string)file_get_contents($root.'/services/MaxTlsConfig.php');
$standbyConfigTool=(string)file_get_contents($root.'/tools/standby_enable_standalone.php');
$maxHandler=(string)file_get_contents($root.'/handlers/MaxUpdateHandler.php');

function botCutoverAssert(bool $condition,string $message):void
{
    if(!$condition){fwrite(STDERR,"FAIL: {$message}\n");exit(1);}
}

foreach ([
    '.github/workflows/fast-cutover.yml',
    '.github/workflows/final-max-live-cutover.yml',
    'tools/max_subscription_cutover.php',
] as $retired) {
    botCutoverAssert(!is_file($root.'/'.$retired),'completed cutover artifact must stay retired: '.$retired);
}

foreach (['Never run old and new bot live-processing concurrently.','Do not disable the Bitrix lead receiver.'] as $needle) {
    botCutoverAssert(str_contains($text,$needle),'runbook must preserve safety invariant: '.$needle);
}

foreach (['TELEGRAM_WEBHOOK_URL','MAX_SEARCH_WEBHOOK_URL','https://app.anytoour.ru/telegram_webhook.php','https://app.anytoour.ru/webhook.php','invalid_webhook_target:'] as $needle) {
    botCutoverAssert(str_contains($service.$template,$needle),'missing canonical webhook contract: '.$needle);
}
botCutoverAssert(str_contains($telegramAdmin,'WebhookTargetConfig::telegram()'),'Telegram webhook admin uses configured target');
botCutoverAssert(str_contains($maxAdmin,'WebhookTargetConfig::max()'),'MAX subscription admin uses configured target');
foreach (['WebhookTargetConfig::telegram()','WebhookTargetConfig::max()','TELEGRAM_WEBHOOK_TARGET_HOST=','MAX_WEBHOOK_TARGET_HOST='] as $needle) {
    botCutoverAssert(str_contains($statusTool,$needle),'missing webhook target diagnostic contract: '.$needle);
}
botCutoverAssert(!preg_match('/TOKEN|SECRET|PASS|PASSWORD/',$statusTool),'webhook target diagnostic must not expose secrets');
foreach (['workflow_dispatch:','tools/webhook_target_status.php','STANDBY_DEPLOY_SSH_KEY','STANDBY_DEPLOY_HOST','STANDBY_DEPLOY_USER'] as $needle) {
    botCutoverAssert(str_contains($workflow,$needle),'missing canonical webhook diagnostic contract: '.$needle);
}
foreach (['setWebhook','deleteWebhook','/subscriptions','systemctl','crontab'] as $forbidden) {
    botCutoverAssert(!str_contains($workflow,$forbidden),'standby webhook diagnostic stays read-only: '.$forbidden);
}

foreach (["'MAX_SEARCH_WEBHOOK_URL' => \"'https://app.anytoour.ru/webhook.php'\"","'TELEGRAM_WEBHOOK_URL' => \"'https://app.anytoour.ru/telegram_webhook.php'\"","'MAX_SEARCH_PUBLIC_BASE_URL' => \"'https://app.anytoour.ru'\"","'MAX_SEARCH_TRACKING_BASE_URL' => \"'https://app.anytoour.ru'\""] as $needle) {
    botCutoverAssert(str_contains($standbyConfigTool,$needle),'canonical runtime URL/mode stays pinned: '.$needle);
}
foreach (['CURLOPT_SSL_VERIFYPEER => true','CURLOPT_SSL_VERIFYHOST => 2','CURLOPT_CAINFO','MAX_SEARCH_MAX_API_INSECURE_COMPAT'] as $needle) {
    botCutoverAssert(str_contains($maxTls,$needle),'MAX TLS policy remains enforced: '.$needle);
}
foreach (['MAX_SEARCH_MAX_SHADOW_MODE','SHADOW_UPDATE_RECEIVED'] as $needle) {
    botCutoverAssert(str_contains($maxHandler,$needle),'MAX shadow-processing guard remains available: '.$needle);
}
foreach (['https://app.anytoour.ru/webhook.php','MAX_NEW_ONLY_HEALTH=OK','MAX_SHADOW_MODE=OFF','BOT_CRON_OWNERSHIP=NEW_ONLY','NEW_RUNTIME_RECOVERY=COMPLETE'] as $needle) {
    botCutoverAssert(str_contains($recovery,$needle),'canonical recovery workflow must verify live ownership: '.$needle);
}
botCutoverAssert(!str_contains($recovery,'healthy_cutover_dual'),'canonical recovery must not accept dual webhook ownership');

echo "retired bot cutover contract OK\n";
