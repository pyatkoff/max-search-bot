<?php
declare(strict_types=1);

// Compatibility for search buttons issued with the application origin before #771.
// This endpoint does not bootstrap the bot, write state or record analytics.
header('Cache-Control: no-store');
if (!in_array($_SERVER['REQUEST_METHOD'] ?? '', ['GET', 'HEAD'], true)) {
    header('Allow: GET, HEAD');
    http_response_code(405);
    exit;
}
$query = (string)($_SERVER['QUERY_STRING'] ?? '');
if (strlen($query) > 8192 || preg_match('/[\x00-\x20\x7f]/', $query)) {
    http_response_code(400);
    exit;
}
require_once dirname(__DIR__) . '/services/ProjectConfig.php';
// Keep encoded arrays, repeated keys, plus signs and YCLID exactly as received.
// The destination is versioned configuration, never Host or a query-supplied URL.
$destination = ProjectConfig::searchUrl();
header('Location: ' . $destination . ($query === '' ? '' : '?' . $query), true, 302);
exit;
