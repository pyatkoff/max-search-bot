<?php

declare(strict_types=1);

require_once __DIR__ . '/../services/ManagerHandoffDispatchService.php';

$passed = 0;
$failed = 0;
function ohCheck(string $name, $actual, $expected): void
{
    global $passed, $failed;
    if ($actual === $expected) { echo "PASS  {$name}\n"; $passed++; return; }
    echo "FAIL  {$name}\n";
    echo '      expected: '.json_encode($expected, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)."\n";
    echo '      actual:   '.json_encode($actual, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)."\n";
    $failed++;
}

ohCheck('sent working-hours handoff enters active queue', ManagerHandoffDispatchService::shouldQueueWaiting(true, true), true);
ohCheck('failed working-hours handoff does not enter queue', ManagerHandoffDispatchService::shouldQueueWaiting(false, true), false);
ohCheck('sent outside-hours contact offer does not enter queue', ManagerHandoffDispatchService::shouldQueueWaiting(true, false), false);
ohCheck('failed outside-hours contact offer does not enter queue', ManagerHandoffDispatchService::shouldQueueWaiting(false, false), false);

$queueCalls=[];
$markWaiting=static function(string $platform,$chatId,array $payload)use(&$queueCalls):bool{
    $queueCalls[]=[$platform,$chatId,$payload];
    return true;
};
ohCheck('deferred handoff never invokes the waiting mutation',ManagerHandoffDispatchService::applyQueueDecision(['queue_waiting'=>false],'max',77,['source'=>'test'],$markWaiting),false);
ohCheck('deferred handoff leaves the waiting mutation untouched',count($queueCalls),0);
ohCheck('queued handoff applies the waiting mutation exactly once',ManagerHandoffDispatchService::applyQueueDecision(['queue_waiting'=>true],'telegram','chat-9',['source'=>'callback'],$markWaiting),true);
ohCheck('queued handoff preserves platform chat and payload exactly',$queueCalls,[['telegram','chat-9',['source'=>'callback']]]);

$callbackSource = (string)file_get_contents(__DIR__ . '/../actions/callbacks/ManagerCallbackAction.php');
$aiSource = (string)file_get_contents(__DIR__ . '/../actions/ManagerAction.php');
$dispatchSource = (string)file_get_contents(__DIR__ . '/../services/ManagerHandoffDispatchService.php');
$sourcePolicy = (string)file_get_contents(__DIR__ . '/../services/SourceHandlingService.php');

ohCheck('callback waiting transition delegates the dispatch queue decision', strpos($callbackSource, 'ManagerHandoffDispatchService::applyQueueDecision($handoff') !== false, true);
ohCheck('AI waiting transition delegates the dispatch queue decision', strpos($aiSource, 'ManagerHandoffDispatchService::applyQueueDecision($handoff') !== false, true);
ohCheck('AI outside-hours lifecycle is recorded as deferred not active request', strpos($aiSource, "'manager_request_deferred'") !== false, true);
ohCheck('dispatch owns the queue decision', strpos($dispatchSource, "'queue_waiting'=>self::shouldQueueWaiting") !== false, true);
ohCheck('dispatch owns application of the queue decision', strpos($dispatchSource, 'function applyQueueDecision') !== false && strpos($dispatchSource, "if (empty(\$handoff['queue_waiting'])) return false") !== false, true);
ohCheck('all handoff entrypoints delegate queue application to one owner',substr_count($aiSource,'ManagerHandoffDispatchService::applyQueueDecision')===1&&substr_count($callbackSource,'ManagerHandoffDispatchService::applyQueueDecision')===1&&substr_count($sourcePolicy,'ManagerHandoffDispatchService::applyQueueDecision')===1,true);
ohCheck('handoff entrypoints no longer mutate waiting state directly',strpos($aiSource,'ConversationControlService::markWaitingByChat')===false&&strpos($callbackSource,'ConversationControlService::markWaitingByChat')===false&&strpos($sourcePolicy,'ConversationControlService::markWaitingByChat')===false,true);
ohCheck('AI path still records the handoff before applying its queue transition',strpos($aiSource,'ConversationRecorder::eventByChat')<strpos($aiSource,'ManagerHandoffDispatchService::applyQueueDecision'),true);
ohCheck('source-policy path still records the handoff before applying its queue transition',strpos($sourcePolicy,'ConversationRecorder::eventByChat')<strpos($sourcePolicy,'ManagerHandoffDispatchService::applyQueueDecision'),true);

$total = $passed + $failed;
echo "\n--------------------------\n";
echo "TOTAL {$total} | PASS {$passed} | FAIL {$failed}\n";
exit($failed > 0 ? 1 : 0);
