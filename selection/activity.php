<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/config.php';
require_once dirname(__DIR__).'/services/ManagerTourSelectionInterestService.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: private, no-store, max-age=0');
header('X-Robots-Tag: noindex, nofollow, noarchive');
header('Referrer-Policy: no-referrer');header('X-Content-Type-Options: nosniff');
function interestRespond(array $data,int $status): never {http_response_code($status);echo json_encode($data,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);exit;}
if (($_SERVER['REQUEST_METHOD']??'')!=='POST') {header('Allow: POST');interestRespond(['ok'=>false,'error'=>'method_not_allowed'],405);}
// This is an anonymous capability endpoint, never an alternate Manager session.
if (($_SERVER['HTTP_ORIGIN']??'')!=='https://app.anytoour.ru') interestRespond(['ok'=>false,'error'=>'origin'],403);
if (!preg_match('~\Aapplication/json(?:\s*;.*)?\z~i',$_SERVER['CONTENT_TYPE']??'')) interestRespond(['ok'=>false,'error'=>'content_type'],415);
$raw=file_get_contents('php://input',false,null,0,4097);
if (!is_string($raw) || strlen($raw)>4096) interestRespond(['ok'=>false,'error'=>'invalid_observation'],422);
$data=json_decode($raw,true,8);
if (!is_array($data) || array_is_list($data)) interestRespond(['ok'=>false,'error'=>'invalid_observation'],422);
try {$result=ManagerTourSelectionInterestService::record($data,(string)($_SERVER['HTTP_USER_AGENT']??''));}
catch (Throwable $e) {interestRespond(['ok'=>false,'error'=>'observations_unavailable'],503);}
interestRespond($result,!empty($result['ok'])?200:match($result['error']??'') {'unavailable'=>410,'rate_limited'=>429,'observation_mismatch'=>409,default=>422});
