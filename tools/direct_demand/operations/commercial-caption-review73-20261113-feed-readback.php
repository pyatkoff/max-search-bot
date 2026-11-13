<?php
declare(strict_types=1);
// Same carrier, read-only verification after its existing writer returns. No retry.
ini_set('display_errors', '0'); ini_set('log_errors', '0');
$out = ['operation' => CAPTION_FEED_OPERATION, 'ok' => false, 'filesystem_writes' => 0,
    'database_calls' => 0, 'provider_calls' => 0, 'public_feed_gets' => 0, 'files' => []]; $locks = [];
try {
    caption_feed_need(PHP_SAPI === 'cli' && realpath(getcwd()) === '/var/www/anytoour/data/www/app.anytoour.ru'
        && trim((string)shell_exec('git rev-parse HEAD')) === EXPECTED_INSTALLED_SHA
        && trim((string)shell_exec('git status --porcelain --untracked-files=no')) === '', 'caption_readback_runtime_changed');
    $raw = base64_decode(CAPTION_READBACK_FILES_BASE64, true);
    caption_feed_need(is_string($raw) && hash('sha256', $raw) === CAPTION_READBACK_FILES_SHA256, 'caption_readback_approval_changed');
    $files = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
    caption_feed_need(array_column($files, 'name') === caption_feed_names(), 'caption_readback_scope_changed');
    $root = '/var/www/anytoour/data/channel-post-operations';
    caption_feed_need(!is_link($root) && realpath($root) === $root, 'caption_readback_root_changed');
    $global = caption_feed_locked_jsonl($root.'/monitor-price-after-collection-v1.jsonl', 100000000, LOCK_EX, $locks);
    caption_feed_need(($global['state']['schema'] ?? null) === 1 && array_key_exists('pending', $global['state'])
        && $global['state']['pending'] === null, 'caption_readback_monitor_pending');
    $dir = getcwd().'/feed'; caption_feed_need(!is_link($dir) && realpath($dir) === $dir, 'caption_readback_directory_changed');
    foreach ($files as $f) {
        $path = $dir.'/'.$f['name']; caption_feed_need(!is_link($path) && is_file($path) && realpath($path) === $path, 'caption_readback_file_missing');
        $local = file_get_contents($path);
        caption_feed_need(is_string($local) && strlen($local) === $f['bytes'] && hash('sha256', $local) === $f['sha256']
            && substr_count($local, '<offer id="') === $f['rows'], 'caption_local_hash_mismatch');
        $url = 'https://app.anytoour.ru/feed/'.$f['name']; $body = ''; $http = curl_init($url);
        caption_feed_need($http !== false, 'caption_public_get_init_failed');
        curl_setopt_array($http, [CURLOPT_FOLLOWLOCATION => false, CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2, CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_TIMEOUT => 35,
            CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use (&$body): int {
                if (strlen($body)+strlen($chunk) >= 4000000) return 0; $body .= $chunk; return strlen($chunk);
            }]);
        $out['public_feed_gets']++; $ok = curl_exec($http); $status = (int)curl_getinfo($http, CURLINFO_HTTP_CODE);
        $errno = curl_errno($http); curl_close($http);
        caption_feed_need($ok === true && $errno === 0 && $status === 200 && strlen($body) === $f['bytes']
            && hash('sha256', $body) === $f['sha256'], 'caption_public_hash_mismatch');
        $out['files'][] = ['name' => $f['name'], 'url' => $url, 'http_status' => $status, 'bytes' => strlen($body),
            'rows' => $f['rows'], 'expected_sha256' => $f['sha256'], 'local_sha256' => hash('sha256', $local),
            'public_sha256' => hash('sha256', $body), 'public_content_base64' => base64_encode($body)];
    }
    $out['monitor_journal_sha256'] = $global['sha256']; $out['monitor_journal_unchanged'] = true;
    $out['installed_sha'] = EXPECTED_INSTALLED_SHA; $out['observed_at_utc'] = gmdate('Y-m-d\TH:i:s\Z'); $out['ok'] = true;
} catch (Throwable $e) {
    $out['error'] = preg_match('/\A[a-z][a-z0-9_]{1,100}\z/D', $e->getMessage()) ? $e->getMessage() : 'caption_feed_readback_failed';
}
foreach (array_reverse($locks) as $stream) if (is_resource($stream)) { flock($stream, LOCK_UN); fclose($stream); }
echo json_encode($out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), "\n";
exit($out['ok'] ? 0 : 2);
