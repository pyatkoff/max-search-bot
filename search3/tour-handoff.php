<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');header('Cache-Control: private, no-store, max-age=0');
header('X-Robots-Tag: noindex, nofollow, noarchive');header('Referrer-Policy: no-referrer');header('X-Content-Type-Options: nosniff');
function search3HandoffRespond(array $data,int $status): never {http_response_code($status);echo json_encode($data,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);exit;}
if (($_SERVER['REQUEST_METHOD']??'')!=='POST') {header('Allow: POST');search3HandoffRespond(['ok'=>false,'error'=>'method_not_allowed'],405);}
if (!empty($_SERVER['HTTP_ORIGIN'])||!empty($_SERVER['HTTP_COOKIE'])) search3HandoffRespond(['ok'=>false,'error'=>'server_producer_required'],403);
if (!preg_match('~\Aapplication/json(?:\s*;.*)?\z~i',$_SERVER['CONTENT_TYPE']??'')) search3HandoffRespond(['ok'=>false,'error'=>'content_type'],415);
require_once dirname(__DIR__).'/config.php';require_once dirname(__DIR__).'/services/Search3TourHandoffService.php';
if (!Search3TourHandoffTrust::keys()||!function_exists('sodium_crypto_sign_verify_detached')) search3HandoffRespond(['ok'=>false,'error'=>'producer_not_configured'],503);
$raw=file_get_contents('php://input',false,null,0,Search3TourHandoffService::MAX_BODY+1);
if (!is_string($raw)||strlen($raw)>Search3TourHandoffService::MAX_BODY) search3HandoffRespond(['ok'=>false,'error'=>'invalid_request'],413);
try {$result=Search3TourHandoffService::execute($raw,$_SERVER);}catch (Throwable $e) {search3HandoffRespond(['ok'=>false,'error'=>'handoff_unavailable'],503);}
search3HandoffRespond($result,!empty($result['ok'])?200:match($result['error']??'') {'unauthorized'=>401,'operation_mismatch'=>409,'rate_limited'=>429,'unavailable','bots_unavailable'=>503,default=>422});
