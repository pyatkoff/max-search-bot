<?php
declare(strict_types=1);
// Embedded into the existing read carrier; reads only, no durable file creation.
ini_set('display_errors', '0'); ini_set('log_errors', '0');
$out = ['operation' => CAPTION_FEED_OPERATION, 'ok' => false, 'filesystem_writes' => 0,
    'database_calls' => 0, 'provider_calls' => 0];
try {
    caption_feed_need(PHP_SAPI === 'cli' && realpath(getcwd()) === '/var/www/anytoour/data/www/app.anytoour.ru'
        && trim((string)shell_exec('git rev-parse HEAD')) === EXPECTED_INSTALLED_SHA
        && trim((string)shell_exec('git status --porcelain --untracked-files=no')) === '', 'caption_capture_runtime_changed');
    $raw = base64_decode(CAPTION_CAPTURE_OPERATIONS_BASE64, true);
    caption_feed_need(is_string($raw) && hash('sha256', $raw) === CAPTION_CAPTURE_OPERATIONS_SHA256, 'caption_capture_approval_changed');
    $operations = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
    $out += caption_feed_capture($operations, CAPTION_FEED_OPERATION);
    $out['installed_sha'] = EXPECTED_INSTALLED_SHA; $out['ok'] = true;
} catch (Throwable $e) {
    if ($e instanceof CaptionFeedLockBusy) $out['lock_failure'] = $e->diagnostic;
    $out['error'] = preg_match('/\A[a-z][a-z0-9_]{1,100}\z/D', $e->getMessage()) ? $e->getMessage() : 'caption_feed_capture_failed';
}
echo json_encode($out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), "\n";
exit($out['ok'] ? 0 : 2);
