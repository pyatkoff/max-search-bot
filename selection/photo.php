<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/config.php';
require_once dirname(__DIR__).'/services/ManagerTourSelectionShareService.php';
header('Cache-Control: private, no-store, max-age=0');header('X-Robots-Tag: noindex, nofollow, noarchive');header('Referrer-Policy: no-referrer');header('X-Content-Type-Options: nosniff');
$method=$_SERVER['REQUEST_METHOD']??'';
if (!in_array($method,['GET','HEAD'],true)) {header('Allow: GET, HEAD');http_response_code(405);exit;}
$token=is_string($_GET['t']??null)?$_GET['t']:'';
$version=filter_var($_GET['v']??'',FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
$card=filter_var($_GET['card']??'',FILTER_VALIDATE_INT,['options'=>['min_range'=>0,'max_range'=>19]]);
$photo=filter_var($_GET['photo']??'',FILTER_VALIDATE_INT,['options'=>['min_range'=>0,'max_range'=>2]]);
if ($version===false || $card===false || $photo===false) {http_response_code(404);exit;}
try {$image=ManagerTourSelectionShareService::photo($token,$version,$card,$photo);}
catch (Throwable $e) {$image=null;}
if (!$image) {http_response_code(404);exit;}
header('Content-Type: '.$image['mime']);header('Content-Length: '.(string)$image['bytes']);
if ($method==='GET') readfile($image['path']);
