<?php
require_once __DIR__ . '/../services/WebsiteProductionSmoke.php';

try {
    $result = WebsiteProductionSmoke::collect();
    echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . PHP_EOL;
    exit(!empty($result['ok']) ? 0 : 1);
} catch (Throwable $e) {
    echo json_encode(['ok'=>false,'error'=>$e->getMessage()], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit(1);
}
