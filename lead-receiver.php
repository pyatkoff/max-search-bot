<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

const MAX_SEARCH_LEAD_RECEIVER_MAX_BODY = 65536;
const MAX_SEARCH_LEAD_RECEIVER_TTL = 600;

function lead_receiver_out(array $data, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/services/BitrixLeadDeliveryGateway.php';

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    lead_receiver_out(['ok' => true, 'receiver' => 'max-search-hmac-bitrix-lead', 'writes' => true]);
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') lead_receiver_out(['ok' => false, 'error' => 'Method not allowed'], 405);
if (stripos((string)($_SERVER['CONTENT_TYPE'] ?? ''), 'application/json') !== 0) lead_receiver_out(['ok' => false, 'error' => 'JSON request required'], 415);
if ((int)($_SERVER['CONTENT_LENGTH'] ?? 0) > MAX_SEARCH_LEAD_RECEIVER_MAX_BODY) lead_receiver_out(['ok' => false, 'error' => 'Request too large'], 413);

$raw = (string) file_get_contents('php://input');
if ($raw === '' || strlen($raw) > MAX_SEARCH_LEAD_RECEIVER_MAX_BODY) lead_receiver_out(['ok' => false, 'error' => 'Invalid request body'], 400);
$secret = defined('MAX_SEARCH_LEAD_BRIDGE_SECRET') ? trim((string) MAX_SEARCH_LEAD_BRIDGE_SECRET) : '';
if ($secret === '') lead_receiver_out(['ok' => false, 'error' => 'Lead receiver is not configured'], 503);
$signature = trim((string)($_SERVER['HTTP_X_MAX_SEARCH_SIGNATURE'] ?? ''));
$expected = hash_hmac('sha256', $raw, $secret);
if ($signature === '' || !hash_equals($expected, $signature)) lead_receiver_out(['ok' => false, 'error' => 'Unauthorized'], 401);

$data = json_decode($raw, true);
$element = is_array($data) && isset($data['element']) && is_array($data['element']) ? $data['element'] : null;
if (!$element || empty($element['IBLOCK_ID']) || empty($element['PROPERTY_VALUES']) || !is_array($element['PROPERTY_VALUES'])) {
    lead_receiver_out(['ok' => false, 'error' => 'Invalid lead payload'], 422);
}

// Bound replay/transport retries without changing business payload semantics.
$key = hash('sha256', $raw);
$dir = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'max-search-lead-receiver';
if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) lead_receiver_out(['ok' => false, 'error' => 'Idempotency storage unavailable'], 500);
$path = $dir . DIRECTORY_SEPARATOR . $key . '.json';
$fh = @fopen($path, 'c+');
if (!$fh || !flock($fh, LOCK_EX)) lead_receiver_out(['ok' => false, 'error' => 'Idempotency lock unavailable'], 500);
rewind($fh);
$stored = json_decode((string) stream_get_contents($fh), true);
if (is_array($stored) && !empty($stored['leadId']) && ((int)($stored['time'] ?? 0) + MAX_SEARCH_LEAD_RECEIVER_TTL) >= time()) {
    $leadId = (int)$stored['leadId'];
    flock($fh, LOCK_UN); fclose($fh);
    lead_receiver_out(['ok' => true, 'duplicate' => true, 'leadId' => $leadId]);
}

try {
    $leadId = (int) BitrixLeadDeliveryGateway::create($element);
} catch (Throwable $e) {
    flock($fh, LOCK_UN); fclose($fh);
    lead_receiver_out(['ok' => false, 'error' => 'Lead insert failed'], 500);
}
if ($leadId <= 0) {
    flock($fh, LOCK_UN); fclose($fh);
    lead_receiver_out(['ok' => false, 'error' => 'Lead insert failed'], 500);
}
rewind($fh); ftruncate($fh, 0);
fwrite($fh, json_encode(['leadId' => $leadId, 'time' => time()], JSON_UNESCAPED_SLASHES));
fflush($fh); flock($fh, LOCK_UN); fclose($fh);
lead_receiver_out(['ok' => true, 'duplicate' => false, 'leadId' => $leadId]);
