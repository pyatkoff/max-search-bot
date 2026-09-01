<?php

declare(strict_types=1);
require_once dirname(__DIR__).'/services/MetrikaConversionGoalService.php';

$passed=0;$failed=0;
function mcCheck(string $name,bool $ok):void{global$passed,$failed;if($ok){echo "PASS  {$name}\n";$passed++;return;}echo "FAIL  {$name}\n";$failed++;}

$root=dirname(__DIR__);
$service=(string)file_get_contents($root.'/services/MetrikaConversionGoalService.php');
$outbound=(string)file_get_contents($root.'/services/ManagerOutboundService.php');
$incoming=(string)file_get_contents($root.'/services/IncomingUpdateDispatcher.php');
$pipeline=(string)file_get_contents($root.'/services/SalesPipelineService.php');
$max=(string)file_get_contents($root.'/maxsearchclass.php');
$managerAction=(string)file_get_contents($root.'/actions/ManagerAction.php');
$managerCallback=(string)file_get_contents($root.'/actions/callbacks/ManagerCallbackAction.php');

mcCheck('business stage goals map to approved Metrika targets',
    MetrikaConversionGoalService::stageGoal('working')==='max_lead_working'
    && MetrikaConversionGoalService::stageGoal('offer_sent')==='max_offer_sent'
    && MetrikaConversionGoalService::stageGoal('booking')==='max_booking'
    && MetrikaConversionGoalService::stageGoal('won')==='max_sale'
    && MetrikaConversionGoalService::stageGoal('clarifying')===null
);
mcCheck('manager reply requires a handoff and an actual manager message',
    strpos($service,"hasEvent(\$conversationId, 'waiting_manager')")!==false
    && strpos($service,"hasEvent(\$conversationId, 'manager_message')")!==false
    && strpos($service,"'max_manager_reply'")!==false
);
mcCheck('tourist reply after manager is a deeper separate goal',
    strpos($service,"'max_customer_reply_after_manager'")!==false
    && strpos($service,'self::managerReply($conversationId, $queue)')!==false
);
mcCheck('conversion goals are conversation-idempotent before canonical queueing',
    strpos($service,"'metrika_' . \$target")!==false
    && strpos($service,'SELECT 1 FROM conversation_events WHERE conversation_id=? AND event_type=? LIMIT 1')!==false
    && strpos($service,'INSERT INTO conversation_events (conversation_id,event_type,actor_type,payload_json)')!==false
);
mcCheck('Metrika transport remains the existing canonical queue with yclid dedupe and destination exclusions',
    strpos($service,'MaxSearchApi::queueMetrikaGoal')!==false
    && strpos($max,'public static function queueMetrikaGoal')!==false
    && strpos($max,"hash('sha256',\$yclid.'|'.\$target)")!==false
    && strpos($max,'metrikaExcludedDestination')!==false
    && strpos($max,"['россия','абхазия']")!==false
);
mcCheck('successful manager text and media delivery trigger first-reply conversion only after manager_message event',
    substr_count($outbound,'MetrikaConversionGoalService::managerReply($conversationId);')===2
    && strpos($outbound,"ConversationControlService::event(\$conversationId,'manager_message'")!==false
    && strpos(substr($outbound,(int)strpos($outbound,'private static function recordFailure')),'MetrikaConversionGoalService::managerReply')===false
);
mcCheck('tourist response conversion is evaluated after attribution sync and only in manager-owned non-callback inbound',
    strpos($incoming,'ConversationAttributionService::syncByChat($platform,$chatId);')!==false
    && strpos($incoming,"\$status === 'manager' && \$type !== 'callback'")!==false
    && strpos($incoming,'MetrikaConversionGoalService::customerReplyAfterManager((int)$ownership[\'id\']);')!==false
    && strpos($incoming,'ConversationAttributionService::syncByChat($platform,$chatId);') < strpos($incoming,'MetrikaConversionGoalService::customerReplyAfterManager')
);
mcCheck('sales pipeline owns working offer booking and sale conversion triggers',
    strpos($pipeline,'MetrikaConversionGoalService::salesStage($id,$key);')!==false
    && strpos($pipeline,"if(\$ok&&\$outcome==='won')MetrikaConversionGoalService::saleOutcome(\$id,\$outcome);")!==false
    && strpos($pipeline,'if($current===$key)return true;')!==false
);
mcCheck('existing manager request conversion remains intact',
    strpos($managerAction,"queueMetrikaGoal(\$chatId, 'max_manager_request')")!==false
    && strpos($managerCallback,"'max_manager_request'")!==false
);
mcCheck('new conversion owner is best effort and cannot block manager or tourist flow',
    substr_count($service,'catch (Throwable $e)')>=4
    && strpos($service,"DiagnosticLogger::log('metrika_conversion','queue_failed'")!==false
);

echo "\n--------------------------\nTOTAL ".($passed+$failed)." | PASS {$passed} | FAIL {$failed}\n";
exit($failed?1:0);
