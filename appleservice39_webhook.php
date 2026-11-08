<?php
declare(strict_types=1);

// This endpoint shares the manager conversation database. It never boots the
// AnyTour wizard/runtime or stores private requests beside the public script.
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    echo '{"ok":false,"result":"method_not_allowed"}';
    exit;
}
if (!is_file(__DIR__.'/config.php')) {
    http_response_code(503);
    echo '{"ok":false,"result":"not_configured"}';
    exit;
}
require_once __DIR__.'/config.php';
require_once __DIR__.'/services/RepairMaxWebhookService.php';

$secret = (string)($_SERVER['HTTP_X_MAX_BOT_API_SECRET'] ?? '');
if (ProjectMaxConnection::authenticate('appleservice39', $secret) === null) {
    http_response_code(403);
    echo '{"ok":false,"result":"forbidden"}';
    exit;
}
$body = file_get_contents('php://input', false, null, 0, 262145);
$update = is_string($body) && strlen($body) <= 262144 ? json_decode($body, true) : null;
if (!is_array($update) || array_is_list($update)) {
    http_response_code(400);
    echo '{"ok":false,"result":"invalid_update"}';
    exit;
}
$result = RepairMaxWebhookService::handle('appleservice39', $secret, $update);
http_response_code($result['status']);
echo json_encode(['ok'=>$result['ok'],'result'=>$result['result']], JSON_UNESCAPED_SLASHES);
if (isset($result['archive']) && function_exists('fastcgi_finish_request')) {
    fastcgi_finish_request();
    RepairMaxWebhookService::archiveCommitted($result);
}
// Without a confirmed FPM response flush, do no synchronous download work.
// The authenticated manager media endpoint recovers from the saved exact mid.
