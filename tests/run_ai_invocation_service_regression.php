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

// Execute the actual invocation and router against a captured model boundary.
// No provider, production database, customer transport or current dialogue is used.
$tmp = sys_get_temp_dir() . '/ai-reference-payload-' . bin2hex(random_bytes(8));
try {
    mkdir($tmp . '/ai', 0700, true); mkdir($tmp . '/services', 0700);
    copy(dirname(__DIR__) . '/services/AiInvocationService.php', $tmp . '/services/AiInvocationService.php');
    copy(dirname(__DIR__) . '/ai/AiRouter.php', $tmp . '/ai/AiRouter.php');
    file_put_contents($tmp . '/ai/AiClient.php', <<<'PHP'
<?php
class AiClient {
    public static array $payloads = [];
    public static string $instructions = '';
    public static function requestJson(string $instructions, array $payload): array {
        self::$instructions = $instructions; self::$payloads[] = $payload;
        return ['intent'=>'question','parameters'=>[],'reply'=>'fixture response'];
    }
}
PHP
    );
    file_put_contents($tmp . '/services/SourceHandlingService.php', <<<'PHP'
<?php
class SourceHandlingService {
    public static array $recent = [];
    public static array $post = ['historical_publication'=>true,'text'=>'Synthetic unrelated publication'];
    public static function sourcePostContext($chat): array { return $chat === 41001 ? self::$post : []; }
    public static function recentDialogueContext($chat): array { return $chat === 41001 ? self::$recent : []; }
}
PHP
    );
    file_put_contents($tmp . '/services/WebsitePageContextService.php', <<<'PHP'
<?php
class WebsitePageContextService {
    public static function forChat(int $chat): array { return []; }
}
PHP
    );
    file_put_contents($tmp . '/services/AiRuntimeLogger.php', <<<'PHP'
<?php
class AiRuntimeLogger {
    public static function debug(string $text): bool { return true; }
    public static function error(string $text): bool { return true; }
}
PHP
    );
    require $tmp . '/services/AiInvocationService.php';
    $current = ['country'=>'Турция','date'=>'15.06.2027','adults'=>2,'children'=>0,'nights'=>'7'];
    $before = $current;
    SourceHandlingService::$recent = ['messages'=>[
        ['role'=>'customer','text'=>'Казань или Самара'],
        ['role'=>'assistant','text'=>'Какой город взять для первого подбора?'],
    ],'complete_history'=>false];
    $reply = AiInvocationService::invoke('SHORT_AI',41001,'Какой вариант дешевле?',$current);
    $payload = AiClient::$payloads[0] ?? [];
    aiInvocationCheck('actual model payload retains the latest question', ($payload['message'] ?? null) === 'Какой вариант дешевле?');
    aiInvocationCheck('actual model payload carries the bounded preceding subject', ($payload['current']['_recent_dialogue'] ?? null) === SourceHandlingService::$recent);
    aiInvocationCheck('reference context never mutates canonical trip input', $current === $before && array_intersect_key($payload['current'] ?? [],$current) === $current);
    aiInvocationCheck('immutable publication remains separately labelled reference data', ($payload['current']['_source_post'] ?? null) === SourceHandlingService::$post);
    aiInvocationCheck('the same model boundary returns the captured result once', count(AiClient::$payloads) === 1 && ($reply['reply'] ?? '') === 'fixture response');
    aiInvocationCheck('router limits prior messages to question reference, not new facts',
        str_contains(AiClient::$instructions,'Новые parameters извлекай из последнего message')
        && str_contains(AiClient::$instructions,'Старые реплики assistant не подтверждают факты'));
    SourceHandlingService::$recent = [];
    AiInvocationService::invoke('SHORT_AI',41001,'Новый вопрос',$current);
    aiInvocationCheck('unavailable history is omitted rather than reconstructed', !array_key_exists('_recent_dialogue',AiClient::$payloads[1]['current'] ?? []));
    SourceHandlingService::$recent = ['messages'=>[['role'=>'customer','text'=>'Other subject']],'complete_history'=>false];
    AiInvocationService::invoke('SHORT_AI',41002,'Вопрос в другом диалоге',$current);
    aiInvocationCheck('another dialogue receives neither source nor recent context',
        !array_key_exists('_recent_dialogue',AiClient::$payloads[2]['current'] ?? [])
        && !array_key_exists('_source_post',AiClient::$payloads[2]['current'] ?? []));
} catch (Throwable $e) {
    aiInvocationCheck('captured model payload fixture completes', false);
    echo 'Fixture error: ' . $e->getMessage() . PHP_EOL;
} finally {
    if (is_dir($tmp)) {
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($tmp, FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($files as $file) { $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname()); }
        rmdir($tmp);
    }
}

echo "\n--------------------------\nTOTAL " . ($passed + $failed) . " | PASS {$passed} | FAIL {$failed}\n";
exit($failed ? 1 : 0);
