<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../services/SourceAttributionProductionSmoke.php';
try {
    $result=SourceAttributionProductionSmoke::collect();
    echo json_encode($result,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT).PHP_EOL;
    exit(!empty($result['ok'])?0:1);
} catch(Throwable $e) {
    fwrite(STDERR,$e->getMessage().PHP_EOL); exit(1);
}
