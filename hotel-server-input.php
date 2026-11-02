<?php
declare(strict_types=1);
ini_set('display_errors','0');
ini_set('log_errors','0');
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
require_once __DIR__.'/services/HotelServerPrivateUpload.php';
$input=fopen('php://input','rb');
$result=HotelServerPrivateUpload::receive(__DIR__,$_SERVER,$input);
if(is_resource($input))fclose($input);
http_response_code($result['code']);
echo json_encode($result['body'],JSON_THROW_ON_ERROR),"\n";
