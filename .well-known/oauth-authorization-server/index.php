<?php

declare(strict_types=1);

require_once dirname(__DIR__,2).'/services/ManagerPreparationOAuth.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
if (($_SERVER['REQUEST_METHOD']??'')!=='GET') {
    http_response_code(405);
    header('Allow: GET');
    echo '{"error":"invalid_request"}';
    exit;
}
echo json_encode(ManagerPreparationOAuth::authorizationMetadata(),JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
