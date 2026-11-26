<?php

declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/services/AiRuntimeLogger.php';
require_once $root . '/services/AiInvocationMetadataSnapshot.php';

$passed = 0;
$failed = 0;
function aiLogCheck(string $name, bool $ok): void
{
    global $passed, $failed;
    echo ($ok ? 'PASS  ' : 'FAIL  ') . $name . PHP_EOL;
    $ok ? $passed++ : $failed++;
}

$tmp = sys_get_temp_dir() . '/max-search-ai-log-test-' . getmypid() . '-' . bin2hex(random_bytes(4));
putenv('MAX_SEARCH_AI_LOG_DIR=' . $tmp);

try {
    aiLogCheck('debug log write succeeds outside application tree', AiRuntimeLogger::debug("AI INPUT: private test value\n"));
    aiLogCheck('error log write succeeds outside application tree', AiRuntimeLogger::error("private test error\n"));

    $debug = AiRuntimeLogger::debugFile();
    $error = AiRuntimeLogger::errorFile();
    aiLogCheck('runtime paths resolve to configured external directory', dirname($debug) === $tmp && dirname($error) === $tmp);
    aiLogCheck('runtime directory is private', (fileperms($tmp) & 0777) === 0700);
    aiLogCheck('runtime files are private', (fileperms($debug) & 0777) === 0600 && (fileperms($error) & 0777) === 0600);

    $now = new DateTimeImmutable('2026-11-23T14:00:00Z');
    $frame = static function (?string $id, string $stage, string $payload, ?DateTimeImmutable $at = null) use ($now): string {
        return 'AI INVOCATION: ' . json_encode([
            'schema' => 'anytour.ai_invocation.v1', 'invocation_id' => $id, 'stage' => $stage,
            'recorded_at_utc' => ($at ?? $now)->format('Y-m-d\TH:i:s\Z'),
            'payload_bytes' => strlen($payload), 'payload_sha256' => hash('sha256', $payload),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n" . $payload;
    };
    $writeSnapshots = static function (string $debugBytes, ?string $errorBytes = '') use ($debug, $error): void {
        file_put_contents($debug, $debugBytes); chmod($debug, 0600);
        if ($errorBytes === null) { if (file_exists($error)) unlink($error); }
        else { file_put_contents($error, $errorBytes); chmod($error, 0600); }
        clearstatcache();
    };
    $inputPayload = "23.11.2026 14:00:00--- chat=SYNTHETIC_NATIVE_CHAT --- route=RICH_AI\nAI INPUT: PRIVATE_FIXTURE +7 (900) 000-00-00\nAI CONTEXT BEFORE: {\"first_name\":\"PRIVATE_NAME\"}\n";
    $resultPayload = "AI RAW: {\"private_fixture\":true}\n";
    $inputFrame = $frame(str_repeat('a', 32), 'input', $inputPayload);
    $resultFrame = $frame(str_repeat('a', 32), 'result', $resultPayload);
    $writeSnapshots($inputFrame . $resultFrame);
    $projection = AiInvocationMetadataSnapshot::collect($now);
    $projectionJson = json_encode($projection);
    aiLogCheck('metadata projection verifies complete frames before contact redaction', $projection['ok'] === true && count($projection['records']) === 2 && $projection['records'][0]['payload_sha256_verified'] === true);
    aiLogCheck('metadata contains no payload digest, customer text, chat ID or file path',
        !str_contains($projectionJson, 'PRIVATE_FIXTURE') && !str_contains($projectionJson, 'PRIVATE_NAME')
        && !str_contains($projectionJson, 'SYNTHETIC_NATIVE_CHAT') && !str_contains($projectionJson, '+7 (900)')
        && !str_contains($projectionJson, hash('sha256', $inputPayload)) && !str_contains($projectionJson, $tmp));
    aiLogCheck('projection leaves original log bytes and modes unchanged', file_get_contents($debug) === $inputFrame . $resultFrame && file_get_contents($error) === '' && (fileperms($debug) & 0777) === 0600);
    aiLogCheck('metadata explicitly declines authenticated trace, session link and cross-file atomicity', $projection['trust'] === 'unauthenticated_text_log' && $projection['runtime_trace_proven'] === false && $projection['cross_stream_atomic'] === false && $projection['session_link_available'] === false && $projection['source_history_complete'] === false);

    aiLogCheck('v2 projection keeps uninstrumented input distinct from an observed missing row',
        $projection['schema'] === 'anytour.ai_invocation_metadata.v2'
        && $projection['records'][0]['website_context_read'] === 'not_recorded'
        && $projection['records'][1]['website_context_read'] === null);
    $contextHeader = static fn(string $outcome): string => 'AI CONTEXT READ: ' . json_encode([
        'schema' => 'anytour.ai_context_read.v1', 'website_page' => $outcome,
    ], JSON_UNESCAPED_SLASHES) . "\n";
    foreach (['available', 'empty', 'not_found', 'read_failed', 'not_attempted_invalid_chat', 'load_failed', 'not_recorded'] as $outcome) {
        $writeSnapshots($frame(str_repeat('a', 32), 'input', $contextHeader($outcome) . $inputPayload) . $resultFrame);
        $view = AiInvocationMetadataSnapshot::collect($now);
        aiLogCheck('verified first-line website outcome survives metadata projection: ' . $outcome,
            $view['ok'] === true && count($view['records']) === 2
            && $view['records'][0]['website_context_read'] === $outcome
            && $view['records'][0]['payload_sha256_verified'] === true
            && $view['records'][0]['shape_confirmed'] === true
            && $view['records'][1]['website_context_read'] === null);
    }
    $customerLookalike = str_replace('PRIVATE_FIXTURE', $contextHeader('available') . 'PRIVATE_FIXTURE', $inputPayload);
    $writeSnapshots($frame(str_repeat('a', 32), 'input', $customerLookalike) . $resultFrame);
    aiLogCheck('customer-text lookalike cannot invent a previously unrecorded context outcome',
        AiInvocationMetadataSnapshot::collect($now)['records'][0]['website_context_read'] === 'not_recorded');
    $writeSnapshots($frame(str_repeat('a', 32), 'input', $contextHeader('read_failed') . $customerLookalike) . $resultFrame);
    aiLogCheck('customer-text lookalike cannot override the actual first-line context outcome',
        AiInvocationMetadataSnapshot::collect($now)['records'][0]['website_context_read'] === 'read_failed');

    $badContextHeaders = [
        'AI CONTEXT READ: {"schema":"anytour.ai_context_read.v1","website_page":"PRIVATE_OUTCOME"}',
        'AI CONTEXT READ: {"schema":"anytour.ai_context_read.v1","website_page":"available","text":"PRIVATE_TEXT"}',
        'AI CONTEXT READ: {"schema":"anytour.ai_context_read.v1","website_page":"available","website\\u005fpage":"not_found"}',
        'AI CONTEXT READ: {"schema":"anytour.ai_context_read.v2","website_page":"available"}',
        'AI CONTEXT READ: {"schema":"anytour.ai_context_read.v1","website_page":null}',
        'AI CONTEXT READ:{"schema":"anytour.ai_context_read.v1","website_page":"available"}',
    ];
    $badHeadersRejected = true;
    foreach ($badContextHeaders as $badHeader) {
        $writeSnapshots($frame(str_repeat('a', 32), 'input', $badHeader . "\n" . $inputPayload) . $resultFrame);
        $badView = AiInvocationMetadataSnapshot::collect($now);
        $badHeadersRejected = $badHeadersRejected && $badView['records'] === []
            && $badView['streams'][0]['cutoff_reason'] === 'invalid_context_read_header'
            && !str_contains(json_encode($badView), 'PRIVATE_');
    }
    aiLogCheck('malformed, unknown, duplicate and private context fields stop without tail resynchronization', $badHeadersRejected);
    $writeSnapshots($frame(str_repeat('a', 32), 'input', rtrim($contextHeader('available'), "\n") . str_repeat(' ', 257) . "\n" . $inputPayload) . $resultFrame);
    aiLogCheck('typed context header has its own bounded size',
        AiInvocationMetadataSnapshot::collect($now)['streams'][0]['cutoff_reason'] === 'context_read_header_size_limit');
    $checkedContextFrame = $frame(str_repeat('a', 32), 'input', $contextHeader('read_failed') . $inputPayload);
    $writeSnapshots(str_replace('read_failed', 'PRIVATE_BAD', $checkedContextFrame) . $resultFrame);
    aiLogCheck('payload digest is checked before interpreting context metadata',
        AiInvocationMetadataSnapshot::collect($now)['streams'][0]['cutoff_reason'] === 'payload_digest_mismatch');

    $writeSnapshots($inputFrame, $frame(str_repeat('a', 32), 'exception', "23.11.2026 14:00:00--- chat=SYNTHETIC_NATIVE_CHAT --- PRIVATE_EXCEPTION\n"));
    $projection = AiInvocationMetadataSnapshot::collect($now);
    aiLogCheck('error stream supplies its real terminal frame without exception text', count($projection['records']) === 2 && $projection['records'][1]['stream'] === 'error' && $projection['records'][1]['stage'] === 'exception' && !str_contains(json_encode($projection), 'PRIVATE_EXCEPTION'));

    $writeSnapshots('', '');
    $empty = AiInvocationMetadataSnapshot::collect($now);
    $writeSnapshots('', null);
    $missing = AiInvocationMetadataSnapshot::collect($now);
    aiLogCheck('empty available stream differs from missing stream', $empty['ok'] === true && $empty['streams'][1]['read_status'] === 'available' && $empty['streams'][1]['valid_frames'] === 0 && $missing['ok'] === false && $missing['streams'][1]['reason'] === 'file_missing');
    $writeSnapshots("AI INPUT: unframed private fixture\nAI RAW: unlinked fixture\n");
    $legacy = AiInvocationMetadataSnapshot::collect($now);
    aiLogCheck('legacy text is counted only as unlinked bytes and never becomes an event', $legacy['records'] === [] && $legacy['streams'][0]['legacy_bytes'] > 0 && !str_contains(json_encode($legacy), 'private fixture'));

    $embedded = $frame(str_repeat('b', 32), 'input', $inputPayload) . $frame(str_repeat('b', 32), 'result', $resultPayload);
    $writeSnapshots($frame(str_repeat('a', 32), 'input', "AI INPUT: synthetic embedded frame\n" . $embedded . "AI CONTEXT BEFORE: {}\n") . $resultFrame);
    $projection = AiInvocationMetadataSnapshot::collect($now);
    aiLogCheck('valid payload consumes embedded fake headers instead of admitting extra events', count($projection['records']) === 2 && $projection['records'][0]['embedded_prefix_lines'] === 2 && !str_contains(json_encode($projection), str_repeat('b', 32)));

    $writeSnapshots(str_replace('PRIVATE_FIXTURE', 'ALTERED_FIXTURE', $inputFrame) . $resultFrame);
    $projection = AiInvocationMetadataSnapshot::collect($now);
    aiLogCheck('first digest failure stops admission without searching for later valid headers', $projection['records'] === [] && $projection['streams'][0]['cutoff_reason'] === 'payload_digest_mismatch' && $projection['streams'][0]['untrusted_tail_bytes'] > 0);
    $writeSnapshots(substr($inputFrame, 0, -3));
    aiLogCheck('incomplete payload reports explicit cutoff', AiInvocationMetadataSnapshot::collect($now)['streams'][0]['cutoff_reason'] === 'payload_truncated');
    $writeSnapshots($frame(null, 'input', $inputPayload));
    aiLogCheck('null invocation ID remains unlinked rather than being invented', AiInvocationMetadataSnapshot::collect($now)['records'][0]['invocation_id'] === null);
    $writeSnapshots($frame(str_repeat('a', 32), 'input', str_replace("\n", "\r\n", $inputPayload)) . $resultFrame);
    aiLogCheck('CRLF payload bytes validate without line reconstruction', count(AiInvocationMetadataSnapshot::collect($now)['records']) === 2);

    $writeSnapshots(str_replace('{"schema":', '{"stage":"result","schema":', $inputFrame) . $resultFrame);
    aiLogCheck('duplicate metadata keys fail before a later stage can be admitted', AiInvocationMetadataSnapshot::collect($now)['streams'][0]['cutoff_reason'] === 'duplicate_metadata_key');
    $writeSnapshots($frame(str_repeat('a', 32), 'input', $inputPayload . "[record truncated]\n") . $resultFrame);
    aiLogCheck('truncation marker inside a valid payload remains an explicit unknown tail', AiInvocationMetadataSnapshot::collect($now)['streams'][0]['cutoff_reason'] === 'truncation_marker_in_payload');
    $writeSnapshots("[record truncated]\n" . $inputFrame);
    aiLogCheck('legacy truncation marker prevents later resynchronization', AiInvocationMetadataSnapshot::collect($now)['streams'][0]['cutoff_reason'] === 'truncation_marker_in_legacy');
    $writeSnapshots($frame(str_repeat('a', 32), 'input', str_repeat('x', 65536) . "\n"));
    aiLogCheck('declared frame beyond known logger cap is rejected', AiInvocationMetadataSnapshot::collect($now)['streams'][0]['cutoff_reason'] === 'frame_size_limit');
    $writeSnapshots('AI INVOCATION: ' . str_repeat('x', 4097));
    aiLogCheck('metadata header cap stops the source explicitly', AiInvocationMetadataSnapshot::collect($now)['streams'][0]['cutoff_reason'] === 'header_size_limit');
    $writeSnapshots(str_repeat('x', 1048577));
    $overLimit = AiInvocationMetadataSnapshot::collect($now);
    aiLogCheck('oversized file is unavailable rather than partially parsed as complete', $overLimit['streams'][0]['reason'] === 'file_size_limit' && $overLimit['streams'][0]['bytes_read'] === 0 && $overLimit['records'] === []);

    $writeSnapshots($frame(str_repeat('a', 32), 'input', $inputPayload, $now->modify('-3601 seconds'))
        . $frame(str_repeat('a', 32), 'input', $inputPayload, $now->modify('+61 seconds')) . $inputFrame);
    $projection = AiInvocationMetadataSnapshot::collect($now);
    aiLogCheck('one-hour age and future bounds report omitted frames without retaining them', count($projection['records']) === 1 && $projection['streams'][0]['omitted_old_frames'] === 1 && $projection['streams'][0]['omitted_future_frames'] === 1 && $projection['records'][0]['frame_index'] === 3);
    $writeSnapshots(str_repeat($resultFrame, 129));
    $projection = AiInvocationMetadataSnapshot::collect($now);
    aiLogCheck('retention keeps at most 128 recent frames per stream and declares the cutoff', count($projection['records']) === 128 && $projection['records'][0]['frame_index'] === 2 && $projection['streams'][0]['omitted_by_limit'] === 1 && $projection['ok'] === false);

    $writeSnapshots($inputFrame);
    $locked = fopen($debug, 'rb'); flock($locked, LOCK_EX);
    $busy = AiInvocationMetadataSnapshot::collect($now);
    flock($locked, LOCK_UN); fclose($locked);
    aiLogCheck('collector does not wait for the cooperating logger lock', $busy['streams'][0]['reason'] === 'file_busy' && $busy['streams'][0]['bytes_read'] === 0);
    chmod($debug, 0640);
    $unsafe = AiInvocationMetadataSnapshot::collect($now);
    clearstatcache();
    aiLogCheck('collector reports unsafe file modes without repairing them', $unsafe['streams'][0]['reason'] === 'unsafe_permissions' && (fileperms($debug) & 0777) === 0640);
    chmod($debug, 0600);
    unlink($error); symlink($debug, $error);
    $linked = AiInvocationMetadataSnapshot::collect($now);
    aiLogCheck('collector refuses linked error log without modifying target', $linked['streams'][1]['reason'] === 'unsafe_file_type' && file_get_contents($debug) === $inputFrame);
    unlink($error); file_put_contents($error, ''); chmod($error, 0600);
    putenv('MAX_SEARCH_AI_LOG_DIR=' . $tmp . '-projection-absent');
    $absent = AiInvocationMetadataSnapshot::collect($now);
    aiLogCheck('both read-only logger getters leave absent directories absent', $absent['ok'] === false && !file_exists($tmp . '-projection-absent'));
    putenv('MAX_SEARCH_AI_LOG_DIR=' . $tmp);

    for ($i = 0; $i < 20; $i++) {
        AiRuntimeLogger::debug(str_repeat('x', 70000));
    }
    clearstatcache(true, $debug);
    aiLogCheck('debug retention is bounded by size', filesize($debug) <= 1048576);


    // Execute the real read-only CLI: content, permissions and absent paths
    // must remain unchanged. A bad mode is reported, never repaired.
    $beforeContents = file_get_contents($debug);
    $smokeCommand = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($root . '/tools/ai_runtime_log_smoke.php') . ' --check-only';
    exec($smokeCommand, $readOnlyOutput, $readOnlyCode);
    clearstatcache();
    aiLogCheck('check-only CLI succeeds without appending a probe', $readOnlyCode === 0 && file_get_contents($debug) === $beforeContents);

    $original = file_get_contents($debug);
    unlink($debug);
    file_put_contents($tmp . '/canary', 'private synthetic canary');
    chmod($tmp . '/canary', 0600);
    symlink($tmp . '/canary', $debug);
    exec($smokeCommand, $symlinkOutput, $symlinkCode);
    aiLogCheck('check-only refuses a linked log without touching its target', $symlinkCode !== 0 && file_get_contents($tmp . '/canary') === 'private synthetic canary');
    unlink($debug); unlink($tmp . '/canary');
    file_put_contents($debug, $original); chmod($debug, 0600);

    chmod($tmp, 0750);
    exec($smokeCommand, $badModeOutput, $badModeCode);
    clearstatcache();
    aiLogCheck('check-only reports unsafe mode without repairing it', $badModeCode !== 0 && (fileperms($tmp) & 0777) === 0750);
    chmod($tmp, 0700);
    putenv('MAX_SEARCH_AI_LOG_DIR=' . $tmp . '-absent');
    exec($smokeCommand, $absentOutput, $absentCode);
    aiLogCheck('check-only never creates missing directory', $absentCode !== 0 && !file_exists($tmp . '-absent'));
    putenv('MAX_SEARCH_AI_LOG_DIR=' . $tmp);

    putenv('MAX_SEARCH_AI_LOG_DIR=' . $root . '/.runtime/forbidden-ai-log-test');
    aiLogCheck('logger rejects a directory inside document root', AiRuntimeLogger::debug('must not be written') === false);
    aiLogCheck('rejected document-root log was not created', !is_file($root . '/.runtime/forbidden-ai-log-test/ai_debug.log'));
} finally {
    putenv('MAX_SEARCH_AI_LOG_DIR');
    foreach ([$tmp . '/ai_debug.log', $tmp . '/ai_errors.log'] as $file) {
        if (is_file($file)) @unlink($file);
    }
    if (is_dir($tmp)) @rmdir($tmp);
}

$runtimeFiles = [
    $root . '/services/AiInvocationService.php',
    $root . '/handlers/AiMessageHandler.php',
    $root . '/handlers/DepartureRouteAdviceHandler.php',
    $root . '/services/PendingMonthStore.php',
];
$runtimeSource = '';
foreach ($runtimeFiles as $file) {
    $source = (string)file_get_contents($file);
    $runtimeSource .= $source;
    // A shared diagnostic helper may serve more than one runtime call site.
    // Check each actual writer instead of counting references across files.
    aiLogCheck(basename($file) . ' uses the external AI logger',
        preg_match('/AiRuntimeLogger::(?:debug|error)\s*\(/', $source) === 1);
}
$deploy = (string)file_get_contents($root . '/.github/workflows/deploy.yml');
$exporter = (string)file_get_contents($root . '/export_debug_logs.php');
$smoke = (string)file_get_contents($root . '/tools/ai_runtime_log_smoke.php');

aiLogCheck('AI runtime writers do not write legacy webroot log paths',
    !str_contains($runtimeSource, "'/ai_debug.log'")
    && !str_contains($runtimeSource, "'/handlers/ai_debug.log'")
    && !str_contains($runtimeSource, "'/handlers/ai_errors.log'")
);
aiLogCheck('authorized CLI exporter reads the external AI log', str_contains($exporter, "'ai'=>AiRuntimeLogger::debugFile()"));
aiLogCheck('deploy removes legacy public AI logs', str_contains($deploy, 'rm -f ai_debug.log handlers/ai_debug.log handlers/ai_errors.log'));
aiLogCheck('production smoke verifies external path and private modes',
    str_contains($deploy, 'php tools/ai_runtime_log_smoke.php')
    && str_contains($smoke, "'outside_document_root'")
    && str_contains($smoke, '$directoryMode === 0700')
    && str_contains($smoke, '$fileMode === 0600')
);
$publicFence = file_get_contents(__DIR__.'/../tools/check_public_runtime_log_fence.sh');
aiLogCheck('deploy verifies public AI log paths are denied',
    str_contains($deploy, 'for path in ai_debug.log handlers/ai_debug.log handlers/ai_errors.log')
    && str_contains($deploy, 'bash tools/check_public_runtime_log_fence.sh "$path"')
    && str_contains($publicFence, 'ai_debug.log|handlers/ai_debug.log|handlers/ai_errors.log|')
    && str_contains($publicFence, '403|404) exit 0 ;;')
    && str_contains($publicFence, 'Public runtime log containment failed')
);

echo "\n--------------------------\n";
echo 'TOTAL ' . ($passed + $failed) . " | PASS {$passed} | FAIL {$failed}\n";
exit($failed > 0 ? 1 : 0);
