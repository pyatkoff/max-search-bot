<?php
require_once(__DIR__ . '/config.php');
require_once(__DIR__ . '/services/RuntimeBootstrap.php');
RuntimeBootstrap::boot();
require_once(__DIR__ . '/maxsearchclass.php');
require_once(__DIR__ . '/services/IntegrationRegistry.php');
require_once(__DIR__ . '/services/IncomingUpdateDispatcher.php');
require_once(__DIR__ . '/integrations/WebsiteIncomingAdapter.php');
require_once(__DIR__ . '/integrations/WebsiteMessengerAdapter.php');

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok'=>false,'error'=>'method_not_allowed']);
    exit;
}

$host = strtolower((string)($_SERVER['HTTP_HOST'] ?? ''));
$origin = trim((string)($_SERVER['HTTP_ORIGIN'] ?? ''));
if ($origin !== '') {
    $originHost = strtolower((string)parse_url($origin, PHP_URL_HOST));
    $requestHost = preg_replace('/:\d+$/', '', $host);
    if ($originHost === '' || $originHost !== $requestHost) {
        http_response_code(403);
        echo json_encode(['ok'=>false,'error'=>'origin_not_allowed']);
        exit;
    }
}

$cookieName = 'atc_session';
$sessionId = strtolower(trim((string)($_COOKIE[$cookieName] ?? '')));
if (!preg_match('/^[a-f0-9]{48}$/', $sessionId)) {
    $sessionId = bin2hex(random_bytes(24));
    setcookie($cookieName, $sessionId, [
        'expires'=>time() + 60*60*24*30,
        'path'=>'/',
        'secure'=>true,
        'httponly'=>true,
        'samesite'=>'Lax',
    ]);
}

function websiteConsultantRateAllowed(string $sessionId, int $limit = 20, int $windowSeconds = 60): bool
{
    $file = sys_get_temp_dir() . '/anytour_consultant_rate_' . hash('sha256', $sessionId) . '.json';
    $fp = @fopen($file, 'c+');
    if (!$fp) return true;
    $allowed = true;
    if (flock($fp, LOCK_EX)) {
        $now = time();
        rewind($fp);
        $raw = (string)stream_get_contents($fp);
        $timestamps = json_decode($raw, true);
        if (!is_array($timestamps)) $timestamps = [];
        $timestamps = array_values(array_filter($timestamps, static function ($ts) use ($now, $windowSeconds) {
            return (int)$ts > $now - $windowSeconds;
        }));
        if (count($timestamps) >= $limit) {
            $allowed = false;
        } else {
            $timestamps[] = $now;
            ftruncate($fp, 0);
            rewind($fp);
            fwrite($fp, json_encode($timestamps));
            fflush($fp);
        }
        flock($fp, LOCK_UN);
    }
    fclose($fp);
    return $allowed;
}

if (!websiteConsultantRateAllowed($sessionId)) {
    http_response_code(429);
    echo json_encode(['ok'=>false,'error'=>'rate_limited']);
    exit;
}

$raw = (string)file_get_contents('php://input');
$payload = json_decode($raw, true);
if (!is_array($payload)) {
    http_response_code(400);
    echo json_encode(['ok'=>false,'error'=>'invalid_json']);
    exit;
}

$hash = hexdec(substr(hash('sha256', $sessionId), 0, 8));
$chatId = -1600000000 - ($hash % 400000000);
$incoming = WebsiteIncomingAdapter::fromPayload($payload, $sessionId, (int)$chatId);
if (!$incoming) {
    http_response_code(400);
    echo json_encode(['ok'=>false,'error'=>'invalid_payload']);
    exit;
}

$messenger = new WebsiteMessengerAdapter();
IntegrationRegistry::useMessenger($messenger);

try {
    $handled = (new IncomingUpdateDispatcher())->dispatch($incoming);
    echo json_encode([
        'ok'=>(bool)$handled,
        'messages'=>$messenger->drain(),
    ], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    DiagnosticLogger::log('website_consultant', 'fatal', [
        'error'=>$e->getMessage(),
        'file'=>$e->getFile(),
        'line'=>$e->getLine(),
    ], $chatId, 'error');
    http_response_code(500);
    echo json_encode(['ok'=>false,'error'=>'internal_error']);
}
