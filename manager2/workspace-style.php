<?php
declare(strict_types=1);

require_once dirname(__DIR__).'/services/Manager2WorkspaceStyle.php';

$version = $_GET['v'] ?? '';
$css = is_string($version) ? Manager2WorkspaceStyle::bytes(dirname(__DIR__), $version) : null;
if ($css === null) {
    http_response_code(404);
    header('Cache-Control: no-store');
    exit;
}
header('Content-Type: text/css; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: public, max-age=31536000, immutable');
echo $css;
