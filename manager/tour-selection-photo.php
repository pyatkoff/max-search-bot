<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/config.php';
require_once __DIR__.'/lib/ManagerHttp.php';
require_once dirname(__DIR__).'/services/ManagerTourSelectionDeliveryService.php';
ManagerHttp::start();$manager=ManagerHttp::requireManager();
header('Cache-Control: private, no-store');header('X-Content-Type-Options: nosniff');header('Referrer-Policy: no-referrer');
if (($_SERVER['REQUEST_METHOD']??'')!=='GET') {http_response_code(405);exit;}
$conversation=filter_var($_GET['conversation_id']??'',FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
$card=filter_var($_GET['card']??'',FILTER_VALIDATE_INT,['options'=>['min_range'=>0,'max_range'=>19]]);
$photo=filter_var($_GET['photo']??'',FILTER_VALIDATE_INT,['options'=>['min_range'=>0,'max_range'=>2]]);
$id=$_GET['publication_id']??'';
if ($conversation===false || $card===false || $photo===false || !is_string($id)) {http_response_code(404);exit;}
// Release session serialization before optional source I/O.
session_write_close();
try {$image=ManagerTourSelectionDeliveryService::photo((int)$manager['id'],$conversation,$id,$card,$photo);}
catch (Throwable $e) {$image=null;}
if (!$image) {http_response_code(404);exit;}
header('Content-Type: '.$image['mime']);header('Content-Length: '.(string)$image['bytes']);readfile($image['path']);
