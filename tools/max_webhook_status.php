<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

$baseDir = dirname(__DIR__);
require_once $baseDir . '/config.php';
require_once $baseDir . '/services/MaxWebhookHealth.php';

$result = MaxWebhookHealth::collect();
echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_INVALID_UTF8_SUBSTITUTE) . "\n";
exit(!empty($result['ok']) ? 0 : 2);
