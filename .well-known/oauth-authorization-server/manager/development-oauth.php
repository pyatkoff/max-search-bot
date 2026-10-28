<?php

declare(strict_types=1);

// Dedicated path-bearing DEV issuer; never delegates to the legacy metadata index.
require_once dirname(__DIR__,3).'/services/ManagerPreparationOAuth.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
if (($_SERVER['REQUEST_METHOD']??'')!=='GET') {
    http_response_code(405);
    header('Allow: GET');
    echo '{"error":"invalid_request"}';
    exit;
}
echo json_encode(ManagerPreparationOAuth::authorizationMetadata(ManagerPreparationOAuth::PROFILE_DEVELOPMENT),JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
