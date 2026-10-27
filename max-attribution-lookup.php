<?php
declare(strict_types=1);
// Release bundle is installed only at tour-max.ru/new/max-attribution/lookup.php.
ini_set('display_errors','0');ini_set('log_errors','0');error_reporting(0);
header('Content-Type: application/json; charset=utf-8');header('Cache-Control: no-store');header('X-Content-Type-Options: nosniff');
function max_attribution_reply(array $data,int $status):void{http_response_code($status);echo json_encode($data,JSON_UNESCAPED_SLASHES);exit;}
if(($_SERVER['REQUEST_METHOD']??'')!=='POST')max_attribution_reply(['ok'=>false,'error'=>'method_not_allowed'],405);
if(strtolower(trim(explode(';',(string)($_SERVER['CONTENT_TYPE']??''),2)[0]))!=='application/json')max_attribution_reply(['ok'=>false,'error'=>'json_required'],415);
if((int)($_SERVER['CONTENT_LENGTH']??0)>8192)max_attribution_reply(['ok'=>false,'error'=>'body_too_large'],413);
$raw=(string)file_get_contents('php://input',false,null,0,8193);
try{
    $level=ob_get_level();ob_start();
    try{require_once __DIR__.'/services/MaxTrackerLookupProtocol.php';}
    finally{while(ob_get_level()>$level)ob_end_clean();}
    // This feature belongs to the owner-approved AnyTour counter only.
    $counter='98615635';
    $data=MaxTrackerLookupProtocol::authenticate($raw,trim((string)($_SERVER['HTTP_X_MAX_ATTRIBUTION_SIGNATURE']??'')),(string)file_get_contents(__DIR__.'/verification.pem'),time(),$counter);
}catch(Throwable $ignored){max_attribution_reply(['ok'=>false,'error'=>'unauthorized'],401);}
try{
    require_once __DIR__.'/services/MaxTrackerLookupRepository.php';
    // Authentication and request bounds precede every database connection/query.
    $repository=MaxTrackerLookupRepository::fromSettings(dirname(__DIR__,2).'/bitrix/.settings.php');
    $users=$repository->lookup($counter,$data['user_ids'],$data['membership']??null);
    max_attribution_reply(['ok'=>true,'version'=>1,'counter_id'=>$counter,'tracker_timezone'=>'Europe/London','users'=>$users],200);
}catch(Throwable $ignored){max_attribution_reply(['ok'=>false,'error'=>'lookup_unavailable'],503);}
