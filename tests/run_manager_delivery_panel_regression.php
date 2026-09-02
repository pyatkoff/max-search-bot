<?php

declare(strict_types=1);

$base=dirname(__DIR__);
$api=(string)file_get_contents($base.'/manager/api.php');
$panel=(string)file_get_contents($base.'/manager/index.php')."\n".(string)file_get_contents($base.'/manager/assets/workspace-v2-conversation.js');
$state=(string)file_get_contents($base.'/services/ManagerDeliveryStateService.php');
$conversations=(string)file_get_contents($base.'/services/ManagerConversationService.php');
$queues=(string)file_get_contents($base.'/services/ManagerQueueProjectionService.php');
$passed=0;$failed=0;
function mdpCheck(string $name,bool $ok):void{global $passed,$failed;if($ok){echo "PASS  {$name}\n";$passed++;return;}echo "FAIL  {$name}\n";$failed++;}

mdpCheck('manager detail exposes active delivery failure',strpos($api,"\$d['delivery_failure']=")!==false && strpos($api,'ManagerDeliveryStateService::activeFailure')!==false);
mdpCheck('state is based on structured manager_message_failed events',strpos($state,"event_type='manager_message_failed'")!==false && strpos($state,"['category']")!==false && strpos($state,"'suspended'")!==false);
mdpCheck('new customer inbound clears suspended state',strpos($state,"direction='inbound'")!==false && strpos($state,'$lastInboundAt')!==false && strpos($state,'$failedAt')!==false && strpos($state,'$lastInboundAt>')!==false);
mdpCheck('suspended state explicitly disables retry',strpos($state,"'retry_allowed'=>false")!==false || strpos($state,"'retry_allowed' => false")!==false);
mdpCheck('Manager shell has persistent delivery failure marker',strpos($panel,'id="deliveryFailure"')!==false && strpos($panel,'renderDeliveryFailure(d.delivery_failure||null)')!==false);
mdpCheck('Manager shell disables send controls while suspended',strpos($panel,"deliverySuspended()")!==false && strpos($panel,'send.disabled=busy||suspended')!==false && strpos($panel,'reply.disabled=busy||suspended')!==false);
mdpCheck('failed send renders returned reason immediately',strpos($panel,'j?.error_message')!==false && strpos($panel,'renderDeliveryFailure(failure)')!==false);
mdpCheck('delivery state supports batched list lookup',strpos($state,'function activeFailures(array $conversationIds)')!==false && strpos($conversations,'ManagerDeliveryStateService::activeFailures')!==false);
mdpCheck('manager list visibly marks suspended MAX recipient',strpos($conversations,'🔴 Клиент недоступен в MAX')!==false && strpos($conversations,"'delivery_failure_category'")!==false);
mdpCheck('delivery state owns filtering already-decorated suspended recipients',strpos($state,'function withoutSuspendedRecipients(array $rows)')!==false && strpos($state,"['delivery_failure_category']")!==false);
mdpCheck('urgent waiting list delegates actionable projection outside HTTP interface',strpos($api,'ManagerQueueProjectionService::actionableRows($queue,$rows)')!==false && strpos($api,'function withoutSuspendedWaiting')===false && strpos($queues,"in_array(\$queue,['waiting','attention'],true)")!==false);
mdpCheck('queue counts materialize waiting requested and mine only once each',substr_count($queues,'ManagerConversationService::list(')===3 && strpos($queues,"ManagerConversationService::list(\$managerId,'waiting',200,\$projectKey)")!==false && strpos($queues,"ManagerConversationService::list(\$managerId,'requested',200,\$projectKey)")!==false && strpos($queues,"ManagerConversationService::list(\$managerId,'mine',200,\$projectKey)")!==false);
mdpCheck('legacy conversation service no longer owns queue count business rules',strpos($conversations,'function queueCounts(')===false);
mdpCheck('waiting counter excludes suspended recipients without a duplicate HTTP-layer query',strpos($queues,"\$waiting=self::actionableRows('waiting',\$rawWaiting)")!==false && strpos($api,'ManagerQueueProjectionService::counts')!==false && strpos($api,"ManagerConversationService::list((int)\$m['id'],'waiting',200")===false);
mdpCheck('notification unread preserves raw waiting plus mine contract without requested double count',strpos($queues,'foreach(array_merge($rawWaiting,$mine) as $row)')!==false && strpos($queues,'array_merge($rawWaiting,$requested,$mine)')===false);
mdpCheck('taken unanswered rows expose server-calculated wait age',strpos($conversations,'wait_age_seconds')!==false && strpos($conversations,'TIMESTAMPDIFF(SECOND')!==false && strpos($conversations,'⏱ Без ответа')!==false && strpos($conversations,"if(!empty(\$row['awaiting_first_reply']))")!==false);

$total=$passed+$failed;
echo "\n--------------------------\nTOTAL {$total} | PASS {$passed} | FAIL {$failed}\n";
exit($failed?1:0);
