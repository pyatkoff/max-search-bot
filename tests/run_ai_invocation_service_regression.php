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


// Decode the captured append boundary; never search payload text for headers.
function aiInvocationFrame(array $attempt): array
{
    $text = $attempt['text'];
    $lineEnd = strpos($text, "\n");
    if ($lineEnd === false || !str_starts_with($text, 'AI INVOCATION: ')) {
        throw new RuntimeException('Missing invocation frame at append boundary');
    }
    $metadata = json_decode(substr($text, strlen('AI INVOCATION: '), $lineEnd - strlen('AI INVOCATION: ')), true, 512, JSON_THROW_ON_ERROR);
    $keys = array_keys($metadata); sort($keys);
    $expectedKeys = ['schema','invocation_id','stage','recorded_at_utc','payload_bytes','payload_sha256']; sort($expectedKeys);
    $payload = substr($text, $lineEnd + 1);
    if ($keys !== $expectedKeys || $metadata['schema'] !== 'anytour.ai_invocation.v1'
        || !in_array($metadata['stage'], ['input','result','exception'], true)
        || ($metadata['invocation_id'] !== null && (!is_string($metadata['invocation_id']) || preg_match('/\A[0-9a-f]{32}\z/', $metadata['invocation_id']) !== 1))
        || !is_int($metadata['payload_bytes']) || $metadata['payload_bytes'] !== strlen($payload)
        || !is_string($metadata['payload_sha256']) || !hash_equals(hash('sha256', $payload), $metadata['payload_sha256'])) {
        throw new RuntimeException('Invalid metadata, exact byte length or payload digest');
    }
    return ['metadata'=>$metadata,'payload'=>$payload,'stream'=>$attempt['stream']];
}

function aiInvocationReadFramedStream(string $bytes): array
{
    $frames = []; $offset = 0;
    while ($offset < strlen($bytes)) {
        $end = strpos($bytes, "\n", $offset);
        if ($end === false || substr($bytes, $offset, strlen('AI INVOCATION: ')) !== 'AI INVOCATION: ') {
            throw new RuntimeException('Missing frame at exact next byte offset');
        }
        $metadata = json_decode(substr($bytes, $offset + strlen('AI INVOCATION: '), $end - $offset - strlen('AI INVOCATION: ')), true, 512, JSON_THROW_ON_ERROR);
        $length = $metadata['payload_bytes'] ?? null;
        if (!is_int($length) || $length < 0 || strlen($bytes) - $end - 1 < $length) {
            throw new RuntimeException('Incomplete framed payload');
        }
        $size = $end + 1 - $offset + $length;
        $frames[] = aiInvocationFrame(['stream'=>'combined','text'=>substr($bytes, $offset, $size)]);
        $offset += $size;
    }
    return $frames;
}

function aiInvocationOriginalInput(string $payload, string $route, int $chat, string $message, array $context): bool
{
    if (preg_match('/\A\n\d{2}\.\d{2}\.\d{4} \d{2}:\d{2}:\d{2}--- chat=' . preg_quote((string)$chat, '/') . ' ---\n/', $payload, $prefix) !== 1) {
        return false;
    }
    return $payload === $prefix[0] . 'ROUTE: ' . $route . "\nAI INPUT: " . $message
        . "\nAI CONTEXT BEFORE: " . json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
}

function aiInvocationNoMetadataLeak(array $values, array $ids): bool
{
    $text = json_encode($values, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    foreach (array_merge(['AI INVOCATION:', 'anytour.ai_invocation.v1', 'invocation_id', 'recorded_at_utc', 'payload_sha256'], $ids) as $needle) {
        if (is_string($needle) && $needle !== '' && strpos($text, $needle) !== false) return false;
    }
    return true;
}

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
    public static array $instructionHistory = [];
    public static $onRequest = null;
    public static function requestJson(string $instructions, array $payload): array {
        self::$instructions = $instructions; self::$payloads[] = $payload;
        self::$instructionHistory[] = $instructions;
        if (is_callable(self::$onRequest)) return (self::$onRequest)($instructions, $payload);
        return ['intent'=>'question','parameters'=>[],'reply'=>'fixture response'];
    }
    public static function reset(): void {
        self::$payloads = []; self::$instructions = ''; self::$instructionHistory = []; self::$onRequest = null;
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
    // These are append attempts, not claims that a failed logger persisted them.
    public static array $attempts = [];
    public static string $behavior = 'ok';
    private static function append(string $stream, string $text): bool {
        self::$attempts[] = ['stream'=>$stream,'text'=>$text];
        if (self::$behavior === 'throw') throw new RuntimeException('fixture logger failure');
        return self::$behavior !== 'false';
    }
    public static function debug(string $text): bool { return self::append('debug', $text); }
    public static function error(string $text): bool { return self::append('error', $text); }
    public static function reset(): void { self::$attempts = []; self::$behavior = 'ok'; }
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


    // Re-enter the real service while the outer model boundary is still active.
    // Same-chat interleaving is deliberately ambiguous without invocation IDs.
    $nestedCurrent = ['country'=>'Египет','adults'=>2,'children'=>0];
    foreach ([['same chat', 42001, 42001], ['different chats', 42002, 42003]] as [$label, $outerChat, $innerChat]) {
        AiClient::reset(); AiRuntimeLogger::reset();
        $innerReply = null; $beforeNested = $nestedCurrent;
        $outerExpected = ['intent'=>'question','parameters'=>[],'reply'=>'outer fixture response'];
        $innerExpected = ['intent'=>'question','parameters'=>[],'reply'=>'inner fixture response'];
        AiClient::$onRequest = static function(string $instructions, array $payload) use (&$innerReply, $innerChat, $nestedCurrent, $outerExpected, $innerExpected): array {
            if ($payload['message'] === 'outer request') {
                $innerReply = AiInvocationService::invoke('RICH_AI', $innerChat, 'inner request', $nestedCurrent);
                return $outerExpected;
            }
            return $innerExpected;
        };
        $outerReply = AiInvocationService::invoke('SHORT_AI', $outerChat, 'outer request', $nestedCurrent);
        $frames = array_map('aiInvocationFrame', AiRuntimeLogger::$attempts);
        $metadata = array_column($frames, 'metadata');
        $ids = array_column($metadata, 'invocation_id');
        aiInvocationCheck($label . ': real append order is input A, input B, result B, result A',
            count($frames) === 4 && array_column($metadata, 'stage') === ['input','input','result','result']
            && array_column($frames, 'stream') === ['debug','debug','debug','debug']);
        aiInvocationCheck($label . ': independent invocation IDs pair both nested completions correctly',
            count($ids) === 4 && is_string($ids[0]) && is_string($ids[1])
            && $ids[0] !== $ids[1] && $ids[0] === $ids[3] && $ids[1] === $ids[2]);
        aiInvocationCheck($label . ': original input and raw-result payload bytes remain unchanged',
            count($frames) === 4
            && aiInvocationOriginalInput($frames[0]['payload'], 'SHORT_AI', $outerChat, 'outer request', $nestedCurrent)
            && aiInvocationOriginalInput($frames[1]['payload'], 'RICH_AI', $innerChat, 'inner request', $nestedCurrent)
            && $frames[2]['payload'] === 'AI RAW: ' . json_encode($innerExpected, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n"
            && $frames[3]['payload'] === 'AI RAW: ' . json_encode($outerExpected, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n");
        aiInvocationCheck($label . ': metadata cannot enter model instructions, payload, return or canonical current',
            AiClient::$payloads === [['message'=>'outer request','current'=>$nestedCurrent],['message'=>'inner request','current'=>$nestedCurrent]]
            && $outerReply === $outerExpected && $innerReply === $innerExpected && $nestedCurrent === $beforeNested
            && count(AiClient::$instructionHistory) === 2
            && aiInvocationNoMetadataLeak([AiClient::$instructionHistory, AiClient::$payloads, $outerReply, $innerReply, $nestedCurrent], $ids));
    }

    AiClient::reset(); AiRuntimeLogger::reset();
    $providerError = "fixture provider failure\nВторая строка";
    AiClient::$onRequest = static function(string $instructions, array $payload) use ($providerError): array {
        throw new RuntimeException($providerError);
    };
    $errorReply = AiInvocationService::invoke('RICH_AI', 42004, 'throw from provider fixture', $nestedCurrent);
    $frames = array_map('aiInvocationFrame', AiRuntimeLogger::$attempts);
    aiInvocationCheck('thrown model failure retains the correct invocation ID across debug and error streams',
        count($frames) === 2 && array_column($frames, 'stream') === ['debug','error']
        && array_column(array_column($frames, 'metadata'), 'stage') === ['input','exception']
        && is_string($frames[0]['metadata']['invocation_id'])
        && $frames[0]['metadata']['invocation_id'] === $frames[1]['metadata']['invocation_id']);
    aiInvocationCheck('thrown model failure preserves original exception payload and error return',
        $errorReply === ['_error'=>true] && count(AiClient::$payloads) === 1 && count($frames) === 2
        && preg_match('/\A\d{2}\.\d{2}\.\d{4} \d{2}:\d{2}:\d{2}--- chat=42004 --- '
            . preg_quote($providerError . PHP_EOL, '/') . '\z/', $frames[1]['payload']) === 1);

    AiClient::reset(); AiRuntimeLogger::reset();
    $returnedError = ['_error'=>true,'reason'=>'fixture domain error'];
    AiClient::$onRequest = static function(string $instructions, array $payload) use ($returnedError): array { return $returnedError; };
    $actualError = AiInvocationService::invoke('SHORT_AI', 42005, 'return error array', $nestedCurrent);
    $frames = array_map('aiInvocationFrame', AiRuntimeLogger::$attempts);
    aiInvocationCheck('returned error array is a result, never relabelled as a thrown exception',
        $actualError === $returnedError && count($frames) === 2 && array_column($frames, 'stream') === ['debug','debug']
        && array_column(array_column($frames, 'metadata'), 'stage') === ['input','result']
        && $frames[0]['metadata']['invocation_id'] === $frames[1]['metadata']['invocation_id']
        && $frames[1]['payload'] === 'AI RAW: ' . json_encode($returnedError, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n");

    foreach (['false', 'throw'] as $loggerBehavior) {
        foreach ([false, true] as $providerThrows) {
            AiClient::reset(); AiRuntimeLogger::reset();
            AiRuntimeLogger::$behavior = $loggerBehavior;
            $expected = ['intent'=>'question','parameters'=>[],'reply'=>'business result survives diagnostics'];
            AiClient::$onRequest = static function(string $instructions, array $payload) use ($providerThrows, $expected): array {
                if ($providerThrows) throw new RuntimeException('fixture model failure');
                return $expected;
            };
            $actual = AiInvocationService::invoke('SHORT_AI', 42006, 'logger failure boundary', $nestedCurrent);
            $frames = array_map('aiInvocationFrame', AiRuntimeLogger::$attempts);
            aiInvocationCheck('logger ' . $loggerBehavior . ' preserves ' . ($providerThrows ? 'model error' : 'successful model result') . ' without retry',
                $actual === ($providerThrows ? ['_error'=>true] : $expected) && count(AiClient::$payloads) === 1
                && count($frames) === 2
                && array_column(array_column($frames, 'metadata'), 'stage') === ['input', $providerThrows ? 'exception' : 'result']
                && $frames[0]['metadata']['invocation_id'] === $frames[1]['metadata']['invocation_id']);
        }
    }

    AiClient::reset(); AiRuntimeLogger::reset();
    $fakeHeader = 'AI INVOCATION: {"schema":"anytour.ai_invocation.v1","invocation_id":"ffffffffffffffffffffffffffffffff","stage":"result","recorded_at_utc":"2000-01-01T00:00:00Z","payload_bytes":0,"payload_sha256":"' . hash('sha256', '') . '"}';
    $multiline = "Семья с ребёнком 👧\n" . $fakeHeader . "\nПродолжить поиск";
    AiInvocationService::invoke('SHORT_AI', 42007, $multiline, $nestedCurrent);
    $frames = array_map('aiInvocationFrame', AiRuntimeLogger::$attempts);
    aiInvocationCheck('UTF-8 multiline input retains exact byte length, SHA-256 and final LF',
        count($frames) === 2 && aiInvocationOriginalInput($frames[0]['payload'], 'SHORT_AI', 42007, $multiline, $nestedCurrent)
        && $frames[0]['metadata']['payload_bytes'] === strlen($frames[0]['payload'])
        && $frames[0]['metadata']['payload_sha256'] === hash('sha256', $frames[0]['payload'])
        && str_ends_with($frames[0]['payload'], "\n") && str_ends_with($frames[1]['payload'], "\n"));
    $streamFrames = aiInvocationReadFramedStream(implode('', array_column(AiRuntimeLogger::$attempts, 'text')));
    aiInvocationCheck('embedded fake header is consumed inside its payload rather than becoming a third record',
        count($streamFrames) === 2 && array_column($streamFrames, 'payload') === array_column($frames, 'payload')
        && strpos($streamFrames[0]['payload'], $fakeHeader) !== false
        && $streamFrames[0]['metadata']['invocation_id'] !== 'ffffffffffffffffffffffffffffffff');

    AiClient::reset(); AiRuntimeLogger::reset();
    $originalTimezone = date_default_timezone_get();
    try {
        date_default_timezone_set('Pacific/Kiritimati');
        $earliest = time();
        AiInvocationService::invoke('SHORT_AI', 42008, 'recording clock fixture', $nestedCurrent);
        $latest = time();
    } finally {
        date_default_timezone_set($originalTimezone);
    }
    $frames = array_map('aiInvocationFrame', AiRuntimeLogger::$attempts);
    $utcValid = count($frames) === 2;
    foreach ($frames as $frame) {
        $stamp = $frame['metadata']['recorded_at_utc'];
        $parsed = is_string($stamp) ? DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s\Z', $stamp, new DateTimeZone('UTC')) : false;
        $utcValid = $utcValid && $parsed !== false && $parsed->format('Y-m-d\TH:i:s\Z') === $stamp
            && $parsed->getTimestamp() >= $earliest && $parsed->getTimestamp() <= $latest;
    }
    aiInvocationCheck('recording metadata is a bounded UTC instant despite a non-UTC default timezone', $utcValid);

    // Fixture-only fault injection: namespace a copy of the actual service and
    // shadow random_bytes there. The production class and public API are unchanged.
    $entropyPreamble = <<<'PHP'
<?php
namespace AiInvocationEntropyFixture;
use \AiRouter;
use \AiRuntimeLogger;
use \SourceHandlingService;
use \WebsitePageContextService;
function random_bytes(int $length): string { throw new \RuntimeException('fixture entropy unavailable'); }
PHP;
    $serviceCopy = (string)file_get_contents($tmp . '/services/AiInvocationService.php');
    if (!str_starts_with($serviceCopy, '<?php')) throw new RuntimeException('Expected PHP service source for entropy fixture');
    file_put_contents($tmp . '/services/AiInvocationEntropyFixture.php', $entropyPreamble . "\n" . substr($serviceCopy, 5));
    require $tmp . '/services/AiInvocationEntropyFixture.php';
    AiClient::reset(); AiRuntimeLogger::reset();
    $entropyReply = \AiInvocationEntropyFixture\AiInvocationService::invoke('SHORT_AI', 42009, 'entropy failure fixture', $nestedCurrent);
    $frames = array_map('aiInvocationFrame', AiRuntimeLogger::$attempts);
    aiInvocationCheck('entropy failure leaves invocation IDs null instead of inventing a replacement identity',
        count($frames) === 2 && array_column(array_column($frames, 'metadata'), 'invocation_id') === [null, null]
        && array_column(array_column($frames, 'metadata'), 'stage') === ['input','result']);
    aiInvocationCheck('entropy failure preserves one model call, its result and the unmodified payload',
        count(AiClient::$payloads) === 1 && $entropyReply === ['intent'=>'question','parameters'=>[],'reply'=>'fixture response']
        && AiClient::$payloads[0] === ['message'=>'entropy failure fixture','current'=>$nestedCurrent]
        && aiInvocationNoMetadataLeak([AiClient::$instructions, AiClient::$payloads, $entropyReply], []));

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
