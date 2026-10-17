<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__.'/fixtures/TelegramArchiveHttpFixture.php';
$fixture = new TelegramArchiveHttpFixture();
try { echo json_encode(['url'=>$fixture->url])."\n"; fflush(STDOUT); fgets(STDIN); }
finally { $fixture->close(); }
