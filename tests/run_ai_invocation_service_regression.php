<?php

declare(strict_types=1);

$handler = (string)file_get_contents(dirname(__DIR__) . '/handlers/AiMessageHandler.php');
$service = (string)file_get_contents(dirname(__DIR__) . '/services/AiInvocationService.php');
$passed = 0;
$failed = 0;

function aiInvocationCheck(string $name, bool $ok): void
{
    global $passed, $failed;
    if ($ok) {
        echo "PASS  {$name}\n";
        $passed++;
        return;
    }
    echo "FAIL  {$name}\n";
    $failed++;
}

aiInvocationCheck('handler routes rich requests through invocation service', strpos($handler, "AiInvocationService::invoke('RICH_AI', \$chat_id, \$userText, \$current)") !== false);
aiInvocationCheck('handler routes short AI fallback through invocation service', strpos($handler, "AiInvocationService::invoke('SHORT_AI', \$chat_id, \$userText, \$current)") !== false);
aiInvocationCheck('handler no longer invokes AiRouter directly', strpos($handler, 'AiRouter::parseTourRequest') === false);
aiInvocationCheck('service owns router invocation', strpos($service, 'AiRouter::parseTourRequest($userText, $aiCurrent)') !== false);
aiInvocationCheck('service decorates a copy of search context for invocation only', strpos($service, '$aiCurrent = $current;') !== false && strpos($service, '$aiCurrent[\'_page_context\'] = $pageContext') !== false);
aiInvocationCheck('service preserves route diagnostics', strpos($service, '"ROUTE: " . $route') !== false && strpos($service, '"AI RAW: "') !== false);
aiInvocationCheck('service converts thrown AI failures into error payload', strpos($service, 'catch (\\Throwable $e)') !== false && strpos($service, "return ['_error'=>true];") !== false);
aiInvocationCheck('date handling stays outside invocation service boundary', strpos($service, 'AiDateHandler') === false && strpos($service, 'AiDateContextService') === false && strpos($handler, 'AiDateContextService::resolveLocal') !== false && strpos($handler, 'AiDateContextService::applyAiGuard') !== false);

echo "\n--------------------------\nTOTAL " . ($passed + $failed) . " | PASS {$passed} | FAIL {$failed}\n";
exit($failed ? 1 : 0);
