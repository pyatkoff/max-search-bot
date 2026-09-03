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

echo "\n--------------------------\nTOTAL ".($passed+$failed)." | PASS {$passed} | FAIL {$failed}\n";
exit($failed?1:0);
