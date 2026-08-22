<?php
// CLI-only exporter of recent MAX Search logs to static JSON files.
// Put in /var/www/545v0023442/data/www/anytour.online/max-search/export_debug_logs.php
// Run from cron once per minute.
//
// Public output files are intentionally unguessable and read-only snapshots.
// Names/avatar URLs and obvious phone numbers are redacted.

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$baseDir = __DIR__;
$maxLines = 5000;

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
    // Remove personal display names and avatar URLs from webhook JSON.
    $line = preg_replace('/"(first_name|last_name|name|avatar_url|full_avatar_url)"\s*:\s*"[^"]*"/u', '"$1":"[redacted]"', $line);

    // Redact obvious RU-style phone numbers while leaving long yclid values intact.
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

$manifest = [
    'ok' => true,
    'generated_at' => date('c'),
    'max_lines_per_log' => $maxLines,
    'logs' => []
];

foreach ($logs as $type => $file) {
    $entry = [
        'ok' => false,
        'type' => $type,
        'source' => basename($file),
        'generated_at' => date('c'),
        'lines' => []
    ];

    if (is_file($file) && is_readable($file)) {
        $lines = tailLines($file, $maxLines);
        foreach ($lines as &$line) {
            $line = redactLine($line);
        }
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
        'file' => basename($outputs[$type])
    ];
}

atomicWriteJson($baseDir . '/diag-tdxAcIvIkZwuvgwq86B1x9fFMJo3GfRa-index.json', $manifest);

echo "OK " . date('c') . PHP_EOL;
