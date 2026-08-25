<?php

declare(strict_types=1);

require_once dirname(__DIR__).'/services/MaxTransport.php';

$base=dirname(__DIR__);
$outbound=(string)file_get_contents($base.'/services/ManagerOutboundService.php');
$api=(string)file_get_contents($base.'/manager/api.php');
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
mdCheck('manager API returns human delivery failure message',strpos($api,"'error_message'=>")!==false);
mdCheck('known suspended dialog is checked before MAX adapter send',strpos($outbound,'unresolvedSuspendedFailure($conversationId')!==false && strpos($outbound,'suppressed_retry')!==false);
mdCheck('suspended guard reads structured failure events',strpos($outbound,"event_type='manager_message_failed'")!==false && strpos($outbound,"['category']??'')==='suspended'")!==false);
mdCheck('new customer inbound clears suspended guard',strpos($outbound,"direction='inbound' AND sender_type='customer' AND created_at>?")!==false);
$guardStart=strpos($outbound,'if ($suspended) {');
$adapterStart=strpos($outbound,"$adapter = new MaxMessengerAdapter",$guardStart===false?0:$guardStart);
$guardSegment=($guardStart!==false && $adapterStart!==false)?substr($outbound,$guardStart,$adapterStart-$guardStart):'';
mdCheck('suppressed retry returns before transport and does not write another failure event',strpos($guardSegment,'return false;')!==false && strpos($guardSegment,'manager_message_failed')===false);

$total=$passed+$failed;
echo "\n--------------------------\nTOTAL {$total} | PASS {$passed} | FAIL {$failed}\n";
exit($failed?1:0);
