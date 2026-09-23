<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/services/ManagerSendGuardService.php';

$passed=0;$failed=0;
function msgGuardCheck(string $name,bool $ok):void{global$passed,$failed;if($ok){echo "PASS  {$name}\n";$passed++;return;}echo "FAIL  {$name}\n";$failed++;}

$root=dirname(__DIR__);
$guard=(string)file_get_contents($root.'/services/ManagerSendGuardService.php');
$outbound=(string)file_get_contents($root.'/services/ManagerOutboundService.php');
$recorder=(string)file_get_contents($root.'/services/ConversationRecorder.php');

msgGuardCheck('lock key is scoped by conversation and manager',ManagerSendGuardService::lockKey(207,5)==='manager-send:207:5'&&ManagerSendGuardService::lockKey(207,4)!==ManagerSendGuardService::lockKey(207,5));
msgGuardCheck('guard uses a bounded immediate duplicate window',strpos($guard,'DUPLICATE_WINDOW_SECONDS = 3')!==false&&strpos($guard,"sender_type='manager'")!==false&&strpos($guard,"direction='outbound'")!==false);
msgGuardCheck('guard compares normalized stored text to requested text',strpos($guard,'html_entity_decode')!==false&&strpos($guard,'trim($stored) === $text')!==false);
msgGuardCheck('guard serializes duplicate candidates with advisory lock',strpos($guard,'SELECT GET_LOCK')!==false&&strpos($guard,'SELECT RELEASE_LOCK')!==false);
msgGuardCheck('outbound text send acquires guard before adapter delivery',strpos($outbound,'ManagerSendGuardService::acquire')!==false&&strpos($outbound,'ManagerSendGuardService::isImmediateDuplicate')!==false&&strpos($outbound,'$adapter->send')!==false&&strpos($outbound,'ManagerSendGuardService::isImmediateDuplicate')<strpos($outbound,'$adapter->send'));
msgGuardCheck('suppressed replay is treated idempotently and observable',strpos($outbound,"'manager_message_suppressed_duplicate'")!==false&&strpos($outbound,'return true;')!==false);
msgGuardCheck('advisory lock is released in finally',strpos($outbound,'finally')!==false&&strpos($outbound,'ManagerSendGuardService::release')!==false);
msgGuardCheck('media path is not subject to text duplicate guard',substr_count($outbound,'ManagerSendGuardService::acquire')===1&&substr_count($outbound,'ManagerSendGuardService::isImmediateDuplicate')===1);
msgGuardCheck('manager adapters disable ambiguous chat-based transcript mirroring',strpos($outbound,"new MaxMessengerAdapter(null, null, 'manager', null, false)")!==false&&strpos($outbound,"new TelegramMessengerAdapter(null, 'manager', false)")!==false&&strpos($outbound,"new WebsiteMessengerAdapter('manager', false)")!==false);
msgGuardCheck('successful delivery mirrors text to the exact conversation',strpos($outbound,'ConversationRecorder::outboundForConversation($conversationId,$channel,$storedText')!==false&&strpos($recorder,'public static function outboundForConversation(int $conversationId')!==false&&strpos($recorder,"'outbound',\$senderType,\$senderId,\$platform")!==false);
$maxTransport=(string)file_get_contents(dirname(__DIR__).'/services/MaxTransport.php');
$editPolicy=(string)file_get_contents(dirname(__DIR__).'/services/ManagerMessageEditPolicy.php');
$maxAdapter=(string)file_get_contents(dirname(__DIR__).'/integrations/MaxMessengerAdapter.php');
msgGuardCheck('MAX edit transport targets exact message id with PUT',strpos($maxTransport,"'PUT','/messages',['message_id'=>\$messageId]")!==false&&strpos($maxTransport,"['text'=>\$text,'format'=>'html']")!==false);
msgGuardCheck('manager edit policy is five minutes',strpos($editPolicy,'WINDOW_SECONDS=300')!==false);
msgGuardCheck('manager edit policy requires exact owner',strpos($editPolicy,"(int)\$row['sender_id']!==\$managerId")!==false&&strpos($editPolicy,"(int)\$row['conversation_manager_id']!==\$managerId")!==false);
msgGuardCheck('provider channels require external message identity',strpos($editPolicy,"['max','telegram']")!==false&&strpos($editPolicy,"missing_external_id")!==false);
msgGuardCheck('website participates in common edit policy',strpos($editPolicy,"['max','telegram','website']")!==false);
msgGuardCheck('MAX adapter retains provider message id for accepted manager sends',strpos($maxAdapter,'lastExternalMessageId')!==false&&strpos($maxAdapter,"['message_id'] ?? ''")!==false);
msgGuardCheck('exact conversation mirror stores external provider message id',strpos($recorder,'external_message_id,text,metadata_json')!==false&&strpos($recorder,"\$externalMessageId !== '' ? \$externalMessageId : null")!==false);
msgGuardCheck('manager MAX text and media pass provider identity into exact mirror',substr_count($outbound,"\$channel==='max'?\$adapter->lastExternalMessageId()")===2);

echo "\n--------------------------\nTOTAL ".($passed+$failed)." | PASS {$passed} | FAIL {$failed}\n";
exit($failed?1:0);
