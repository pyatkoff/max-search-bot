<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/config.php';
require_once __DIR__.'/lib/ManagerHttp.php';
require_once dirname(__DIR__).'/services/ManagerTourSelectionDeliveryService.php';
ManagerHttp::start();$manager=ManagerHttp::requireManager();
header('Cache-Control: private, no-store');header('X-Content-Type-Options: nosniff');header('Referrer-Policy: no-referrer');
if (($_SERVER['REQUEST_METHOD']??'')==='POST') {
    header('Content-Type: application/json; charset=utf-8');
    $limit=intdiv(TourSelectionPhotoStore::MAX_BYTES+2,3)*4+4096;
    $body=(string)file_get_contents('php://input',false,null,0,$limit+1);
    if (strlen($body)>$limit) ManagerHttp::respond(['ok'=>false,'error'=>'invalid_photo'],413);
    $data=json_decode($body,true);if (!is_array($data)) $data=[];
    ManagerHttp::requireCsrf($data);session_write_close();
    $conversation=$data['conversation_id']??null;
    if (($data['action']??'')!=='upload' || !is_int($conversation) || $conversation<=0) ManagerHttp::respond(['ok'=>false,'error'=>'invalid_photo'],422);
    try {$result=ManagerTourSelectionService::uploadPhoto((int)$manager['id'],$conversation,$data);}
    catch (Throwable $e) {$result=['ok'=>false,'error'=>'photo_storage_unavailable'];}
    $status=!empty($result['ok'])?200:match($result['error']??'') {'unauthorized'=>401,'forbidden'=>403,'not_found'=>404,'draft_changed','operation_mismatch'=>409,'photo_storage_unavailable','photo_busy','photo_capacity'=>503,default=>422};
    ManagerHttp::respond($result,$status);
}
if (($_SERVER['REQUEST_METHOD']??'')!=='GET') {http_response_code(405);exit;}
if (isset($_GET['draft_photo'])) {
    $conversation=filter_var($_GET['conversation_id']??'',FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);$key=$_GET['draft_photo'];session_write_close();
    try {$image=$conversation!==false&&is_string($key)?ManagerTourSelectionService::uploadedPhoto((int)$manager['id'],$conversation,$key):null;} catch (Throwable $e) {$image=null;}
    if (!$image) {http_response_code(404);exit;}
    header('Content-Type: '.$image['mime']);header('Content-Length: '.(string)$image['bytes']);readfile($image['path']);exit;
}
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
