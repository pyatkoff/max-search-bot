<?php

declare(strict_types=1);

$root=dirname(__DIR__);
$snapshot=(string)file_get_contents($root.'/tools/production_snapshot.php');

$passed=0;$failed=0;
function tpsCheck(string $name,bool $ok):void{global$passed,$failed;if($ok){echo "PASS  {$name}\n";$passed++;return;}echo "FAIL  {$name}\n";$failed++;}

tpsCheck('production snapshot loads Telegram webhook health',strpos($snapshot,"require_once $baseDir.'/services/TelegramWebhookHealth.php';")!==false);
tpsCheck('production snapshot exposes Telegram webhook health',strpos($snapshot,"'telegram_webhook_health'")!==false);
tpsCheck('production snapshot collects Telegram webhook health',strpos($snapshot,'TelegramWebhookHealth::collect()')!==false);
tpsCheck('snapshot keeps Telegram inspection read-only',strpos($snapshot,'setWebhook')===false&&strpos($snapshot,'deleteWebhook')===false);

echo "\n--------------------------\nTOTAL ".($passed+$failed)." | PASS {$passed} | FAIL {$failed}\n";
exit($failed?1:0);
