<?php
declare(strict_types=1);
require_once dirname(__DIR__,3).'/services/ManagerPreparationOAuth.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
if (($_SERVER['REQUEST_METHOD']??'')!=='GET') {
    header('Allow: GET');http_response_code(405);
    echo json_encode(['error'=>'invalid_request'],JSON_THROW_ON_ERROR);exit;
}
echo json_encode(ManagerPreparationOAuth::resourceMetadata('replies'),JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
