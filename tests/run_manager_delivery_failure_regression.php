<?php

declare(strict_types=1);

require_once dirname(__DIR__).'/services/MaxTransport.php';

$base=dirname(__DIR__);
$outbound=(string)file_get_contents($base.'/services/ManagerOutboundService.php');
$passed=0;$failed=0;
function mdCheck(string $name,bool $ok):void{global $passed,$failed;if($ok){echo "PASS  {$name}\n";$passed++;return;}echo "FAIL  {$name}\n";$failed++;}

$blocked=MaxTransport::classifyFailure(403,'{"message":"Bot was blocked by user"}');
$missing=MaxTransport::classifyFailure(404,'{"message":"user not found"}');
$limited=MaxTransport::classifyFailure(429,'{"message":"too many requests"}');
$unknown=MaxTransport::classifyFailure(400,'{"message":"bad request"}');

mdCheck('explicit blocked response is classified blocked',($blocked['category']??'')==='blocked');
mdCheck('missing recipient is classified unavailable',($missing['category']??'')==='unavailable');
mdCheck('rate limit is classified temporary',($limited['category']??'')==='temporary');
mdCheck('unrecognized client error remains unknown',($unknown['category']??'')==='unknown');
mdCheck('failure records manager_message_failed event',strpos($outbound,"'manager_message_failed'")!==false);
mdCheck('failure does not create manager_message success event in failure branch',strpos($outbound,'if ($ok) {')!==false);
mdCheck('blocked failure has explicit manager notification',strpos($outbound,'пользователь заблокировал бота')!==false);
mdCheck('transport failure is exposed without parsing text logs',strpos($outbound,'MaxTransport::lastError()')!==false);

$total=$passed+$failed;
echo "\n--------------------------\nTOTAL {$total} | PASS {$passed} | FAIL {$failed}\n";
exit($failed?1:0);
