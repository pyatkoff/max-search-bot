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

$callbackSource = (string)file_get_contents(__DIR__ . '/../actions/callbacks/ManagerCallbackAction.php');
$aiSource = (string)file_get_contents(__DIR__ . '/../actions/ManagerAction.php');
$dispatchSource = (string)file_get_contents(__DIR__ . '/../services/ManagerHandoffDispatchService.php');

ohCheck('callback waiting transition is gated by dispatch queue decision', strpos($callbackSource, "if (!empty(\$handoff['queue_waiting']))") !== false, true);
ohCheck('AI waiting transition is gated by dispatch queue decision', strpos($aiSource, "if (!empty(\$handoff['queue_waiting'])) ConversationControlService::markWaitingByChat") !== false, true);
ohCheck('AI outside-hours lifecycle is recorded as deferred not active request', strpos($aiSource, "'manager_request_deferred'") !== false, true);
ohCheck('dispatch owns the queue decision', strpos($dispatchSource, "'queue_waiting'=>self::shouldQueueWaiting") !== false, true);

$total = $passed + $failed;
echo "\n--------------------------\n";
echo "TOTAL {$total} | PASS {$passed} | FAIL {$failed}\n";
exit($failed > 0 ? 1 : 0);
