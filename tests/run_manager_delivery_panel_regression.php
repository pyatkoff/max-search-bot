<?php

declare(strict_types=1);

$base=dirname(__DIR__);
$api=(string)file_get_contents($base.'/manager/api.php');
$panel=(string)file_get_contents($base.'/manager/index.php');
$state=(string)file_get_contents($base.'/services/ManagerDeliveryStateService.php');
$conversations=(string)file_get_contents($base.'/services/ManagerConversationService.php');
$passed=0;$failed=0;
function mdpCheck(string $name,bool $ok):void{global $passed,$failed;if($ok){echo "PASS  {$name}\n";$passed++;return;}echo "FAIL  {$name}\n";$failed++;}

mdpCheck('manager detail exposes active delivery failure',strpos($api,"\$d['delivery_failure']=")!==false && strpos($api,'ManagerDeliveryStateService::activeFailure')!==false);
mdpCheck('state is based on structured manager_message_failed events',strpos($state,"event_type='manager_message_failed'")!==false && strpos($state,"['category'] ?? '') !== 'suspended'")!==false);
mdpCheck('new customer inbound clears suspended state',strpos($state,"direction='inbound'")!==false && strpos($state,'$lastInboundAt > $failedAt')!==false);
mdpCheck('suspended state explicitly disables retry',strpos($state,"'retry_allowed'=>false")!==false || strpos($state,"'retry_allowed' => false")!==false);
mdpCheck('manager panel has persistent delivery failure marker',strpos($panel,'id="deliveryFailure"')!==false && strpos($panel,'renderDeliveryFailure(j.delivery_failure)')!==false);
mdpCheck('manager panel disables send controls while suspended',strpos($panel,"f.category==='suspended'")!==false && strpos($panel,'send.disabled=suspended')!==false && strpos($panel,"$('text').disabled=suspended")!==false);
mdpCheck('failed send renders returned reason immediately',strpos($panel,'j.error_message')!==false && strpos($panel,'renderDeliveryFailure(j.failure')!==false);
mdpCheck('delivery state supports batched list lookup',strpos($state,'function activeFailures(array $conversationIds)')!==false && strpos($conversations,'ManagerDeliveryStateService::activeFailures')!==false);
mdpCheck('manager list visibly marks suspended MAX recipient',strpos($conversations,'🔴 Клиент недоступен в MAX')!==false && strpos($conversations,"'delivery_failure_category'")!==false);

$total=$passed+$failed;
echo "\n--------------------------\nTOTAL {$total} | PASS {$passed} | FAIL {$failed}\n";
exit($failed?1:0);
