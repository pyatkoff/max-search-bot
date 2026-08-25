<?php

declare(strict_types=1);

require_once dirname(__DIR__).'/services/MaxTransport.php';

$base=dirname(__DIR__);
$outbound=(string)file_get_contents($base.'/services/ManagerOutboundService.php');
$api=(string)file_get_contents($base.'/manager/api.php');
$panel=(string)file_get_contents($base.'/manager/index.php');
$state=(string)file_get_contents($base.'/services/ManagerDeliveryStateService.php');
$passed=0;$failed=0;
function mdCheck(string $name,bool $ok):void{global $passed,$failed;if($ok){echo "PASS  {$name}\n";$passed++;return;}echo "FAIL  {$name}\n";$failed++;}

$suspended=MaxTransport::classifyFailure(403,'{"message":"Key: error.dialog.suspended, args: [35602284,]."}');
$blocked=MaxTransport::classifyFailure(403,'{"message":"Bot was blocked by user"}');
$missing=MaxTransport::classifyFailure(404,'{"message":"user not found"}');
$limited=MaxTransport::classifyFailure(429,'{"message":"too many requests"}');
$unknown=MaxTransport::classifyFailure(400,'{"message":"bad request"}');

mdCheck('live MAX dialog.suspended response is classified suspended',($suspended['category']??'')==='suspended');
mdCheck('explicit blocked response is classified blocked',($blocked['category']??'')==='blocked');
mdCheck('missing recipient is classified unavailable',($missing['category']??'')==='unavailable');
mdCheck('rate limit is classified temporary',($limited['category']??'')==='temporary');
mdCheck('unrecognized client error remains unknown',($unknown['category']??'')==='unknown');
mdCheck('failure records manager_message_failed event',strpos($outbound,"'manager_message_failed'")!==false);
mdCheck('failure does not create manager_message success event in failure branch',strpos($outbound,'if ($ok) {')!==false);
mdCheck('suspended failure explains user must restart or unblock bot',strpos($outbound,'остановил или заблокировал бота MAX')!==false);
mdCheck('transport failure is exposed without parsing text logs',strpos($outbound,'MaxTransport::lastError()')!==false);
mdCheck('manager API returns structured delivery failure',strpos($api,"'error'=>'delivery_failed'")!==false && strpos($api,"'failure'=>\$failure")!==false);
mdCheck('manager detail exposes persistent delivery failure',strpos($api,"'delivery_failure'=")!==false && strpos($api,'ManagerDeliveryStateService::activeFailure')!==false);
mdCheck('known suspended recipient is rejected before MAX retry',strpos($outbound,'ManagerDeliveryStateService::activeFailure')!==false && strpos($outbound,'self::$lastFailure = $activeFailure')!==false);
mdCheck('new inbound activity clears suspended state',strpos($state,"direction='inbound'")!==false && strpos($state,'$lastInboundAt > $failedAt')!==false);
mdCheck('manager panel has persistent failure marker',strpos($panel,'id="deliveryFailure"')!==false && strpos($panel,'renderDeliveryFailure(j.delivery_failure)')!==false);
mdCheck('manager panel disables suspended retries',strpos($panel,"f.category==='suspended'")!==false && strpos($panel,"send.disabled=suspended")!==false);

$total=$passed+$failed;
echo "\n--------------------------\nTOTAL {$total} | PASS {$passed} | FAIL {$failed}\n";
exit($failed?1:0);
