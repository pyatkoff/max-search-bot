<?php
declare(strict_types=1);

// The request selects only an already configured connection; its secret and
// canonical endpoint binding are checked before reading a customer update.
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

$project = $_GET['project'] ?? null;
$secret = $_SERVER['HTTP_X_MAX_BOT_API_SECRET'] ?? '';
$connection = is_string($project) && is_string($secret) ? ProjectMaxConnection::authenticate($project, $secret) : null;
$scope = $connection !== null ? RepairProjectSettings::webhook($connection) : null;
if ($scope === null || $project === AppleService39PilotCatalog::PROJECT) {
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
$result = RepairMaxWebhookService::handle($project, $secret, $update);
http_response_code($result['status']);
echo json_encode(['ok'=>$result['ok'],'result'=>$result['result']], JSON_UNESCAPED_SLASHES);
if (isset($result['archive']) && function_exists('fastcgi_finish_request')) {
    fastcgi_finish_request();
    RepairMaxWebhookService::archiveCommitted($result);
}
