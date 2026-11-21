<?php
declare(strict_types=1);
// NEW sibling endpoint only: https://tour-max.ru/new/max2-value-attribution/lookup.php
// No global Bitrix boot, session, persistent writer, legacy endpoint or goal sender.
ini_set('display_errors', '0'); ini_set('log_errors', '0'); error_reporting(0);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store'); header('X-Content-Type-Options: nosniff');
try {
    $level = ob_get_level(); ob_start();
    try {
        require_once __DIR__ . '/services/Max2ValueLookupHttpV2.php';
        require_once __DIR__ . '/services/Max2ValueLookupRuntimeV2.php';
    } finally { while (ob_get_level() > $level) ob_end_clean(); }
    $body = file_get_contents('php://input', false, null, 0, 8193);
    if (!is_string($body)) throw new RuntimeException('input_unavailable');
    $reply = Max2ValueLookupHttpV2::handle($_SERVER, $body, time(),
        [Max2ValueLookupRuntimeV2::class, 'publicKey'], [Max2ValueLookupRuntimeV2::class, 'read']);
    http_response_code($reply['status_code']);
    foreach ($reply['headers'] as $name => $value) header($name . ': ' . $value);
    echo $reply['body'];
} catch (Throwable $ignored) {
    http_response_code(503); echo '{"ok":false,"error":"lookup_unavailable"}';
}
