<?php
// CLI-only exporter of recent MAX Search logs to static JSON files.
// Run from cron once per minute.

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$baseDir = __DIR__;

$maxLinesByType = [
    'funnel' => 2500,
    'tmp' => 500,
    'cron' => 2500,
    'ai' => 1200,
    'metrika' => 1200,
    'metrika_queue' => 1200,
];

$logs = [
    'funnel' => $baseDir . '/funnel.csv',
    'tmp' => $baseDir . '/tmp_in.txt',
    'cron' => $baseDir . '/cron_followup.log',
    'ai' => $baseDir . '/ai_debug.log',
    'metrika' => $baseDir . '/metrika_events.log',
    'metrika_queue' => $baseDir . '/metrika_offline_queue.csv',
];

$outputs = [
    'funnel' => $baseDir . '/diag-tdxAcIvIkZwuvgwq86B1x9fFMJo3GfRa-funnel.json',
    'tmp' => $baseDir . '/diag-tdxAcIvIkZwuvgwq86B1x9fFMJo3GfRa-tmp.json',
    'cron' => $baseDir . '/diag-tdxAcIvIkZwuvgwq86B1x9fFMJo3GfRa-cron.json',
    'ai' => $baseDir . '/diag-tdxAcIvIkZwuvgwq86B1x9fFMJo3GfRa-ai.json',
    'metrika' => $baseDir . '/diag-tdxAcIvIkZwuvgwq86B1x9fFMJo3GfRa-metrika.json',
    'metrika_queue' => $baseDir . '/diag-tdxAcIvIkZwuvgwq86B1x9fFMJo3GfRa-metrika-queue.json',
];

function tailLines($file, $maxLines)
{
    if (!is_file($file) || !is_readable($file)) return [];
    $fh = fopen($file, 'rb');
    if (!$fh) return [];
    fseek($fh, 0, SEEK_END);
    $size = ftell($fh);
    $cursor = $size;
    $buffer = '';
    $chunkSize = 16384;
    while ($cursor > 0 && substr_count($buffer, "\n") <= $maxLines) {
        $read = min($chunkSize, $cursor);
        $cursor -= $read;
        fseek($fh, $cursor);
        $chunk = fread($fh, $read);
        if ($chunk === false) break;
        $buffer = $chunk . $buffer;
    }
    fclose($fh);
    $lines = preg_split("/\r\n|\n|\r/", $buffer);
    if ($lines && end($lines) === '') array_pop($lines);
    if (count($lines) > $maxLines) $lines = array_slice($lines, -$maxLines);
    return $lines;
}

function redactLine($line)
{
    $line = preg_replace('/"(first_name|last_name|name|avatar_url|full_avatar_url)"\s*:\s*"[^"]*"/u', '"$1":"[redacted]"', $line);
    $line = preg_replace('/(?<!\d)(?:\+7|8)[\s\-\(\)]*(?:\d[\s\-\(\)]*){10}(?!\d)/u', '[phone-redacted]', $line);
    return $line;
}

function atomicWriteJson($path, $data)
{
    $tmp = $path . '.tmp';
    $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false) return false;
    if (file_put_contents($tmp, $json, LOCK_EX) === false) return false;
    @chmod($tmp, 0644);
    return rename($tmp, $path);
}

function phpEnvironment()
{
    $candidates = [
        PHP_BINARY,
        '/usr/bin/php',
        '/usr/bin/php8.4',
        '/usr/bin/php8.3',
        '/usr/bin/php8.2',
        '/usr/bin/php8.1',
        '/usr/bin/php8.0',
        '/usr/bin/php7.4',
        '/opt/php84/bin/php',
        '/opt/php83/bin/php',
        '/opt/php82/bin/php',
        '/opt/php81/bin/php',
        '/opt/php80/bin/php',
        '/opt/php74/bin/php',
    ];
    $seen = [];
    $found = [];
    foreach ($candidates as $bin) {
        if (!$bin || isset($seen[$bin])) continue;
        $seen[$bin] = true;
        if (!is_file($bin) || !is_executable($bin)) continue;
        $out = [];
        $code = 0;
        exec(escapeshellarg($bin) . ' -r ' . escapeshellarg('echo PHP_VERSION;') . ' 2>&1', $out, $code);
        $found[] = [
            'binary' => $bin,
            'version' => trim(implode("\n", $out)),
            'exit_code' => $code,
        ];
    }
    return [
        'current_binary' => PHP_BINARY,
        'current_version' => PHP_VERSION,
        'available' => $found,
    ];
}

function runConversationRegression($baseDir)
{
    $testFile = $baseDir . '/tests/run_conversation_regression.php';
    if (!is_file($testFile) || !is_readable($testFile)) {
        return [
            'ok' => false,
            'exit_code' => 127,
            'total' => null,
            'passed' => null,
            'failed' => null,
            'error' => 'test_file_not_found_or_unreadable',
            'output' => [],
        ];
    }

    $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($testFile) . ' 2>&1';
    $output = [];
    $exitCode = 0;
    exec($cmd, $output, $exitCode);

    $total = null;
    $passed = null;
    $failed = null;
    foreach (array_reverse($output) as $line) {
        if (preg_match('/TOTAL\s+(\d+)\s+\|\s+PASS\s+(\d+)\s+\|\s+FAIL\s+(\d+)/', $line, $m)) {
            $total = (int)$m[1];
            $passed = (int)$m[2];
            $failed = (int)$m[3];
            break;
        }
    }

    return [
        'ok' => ($exitCode === 0 && $failed === 0),
        'exit_code' => $exitCode,
        'total' => $total,
        'passed' => $passed,
        'failed' => $failed,
        'output' => array_slice($output, -80),
    ];
}

$manifest = [
    'ok' => true,
    'generated_at' => date('c'),
    'php' => phpEnvironment(),
    'max_lines_by_type' => $maxLinesByType,
    'logs' => [],
    'tests' => [],
];

foreach ($logs as $type => $file) {
    $maxLines = isset($maxLinesByType[$type]) ? $maxLinesByType[$type] : 1000;
    $entry = [
        'ok' => false,
        'type' => $type,
        'source' => basename($file),
        'generated_at' => date('c'),
        'max_lines' => $maxLines,
        'lines' => []
    ];

    if (is_file($file) && is_readable($file)) {
        $lines = tailLines($file, $maxLines);
        foreach ($lines as &$line) $line = redactLine($line);
        unset($line);
        $entry['ok'] = true;
        $entry['size_bytes'] = filesize($file);
        $entry['mtime'] = date('c', filemtime($file));
        $entry['count'] = count($lines);
        $entry['lines'] = $lines;
    } else {
        $entry['error'] = 'file_not_found_or_unreadable';
    }

    atomicWriteJson($outputs[$type], $entry);
    $manifest['logs'][$type] = [
        'ok' => $entry['ok'],
        'source' => $entry['source'],
        'count' => isset($entry['count']) ? $entry['count'] : 0,
        'max_lines' => $maxLines,
        'file' => basename($outputs[$type])
    ];
}

$regression = runConversationRegression($baseDir);
$manifest['tests']['conversation_regression'] = $regression;
if (!$regression['ok']) $manifest['ok'] = false;

atomicWriteJson($baseDir . '/diag-tdxAcIvIkZwuvgwq86B1x9fFMJo3GfRa-index.json', $manifest);

echo "OK " . date('c') . PHP_EOL;
