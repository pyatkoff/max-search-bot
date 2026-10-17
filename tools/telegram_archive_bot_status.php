<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once dirname(__DIR__).'/services/TelegramArchiveBotConfig.php';
try { echo json_encode(['ok'=>true,'bot'=>(new TelegramArchiveBotConfig())->publicStatus()], JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n"; }
catch (Throwable $error) { echo '{"ok":false,"error":"archive_status_unavailable"}'."\n"; exit(1); }
