<?php
session_name('anytour_manager_panel');
session_set_cookie_params(['lifetime'=>60*60*12,'path'=>'/max-search/manager/','secure'=>true,'httponly'=>true,'samesite'=>'Lax']);
session_start();

$baseDir=dirname(__DIR__);
require_once $baseDir.'/config.php';
require_once $baseDir.'/maxsearchclass.php';
require_once $baseDir.'/services/ManagerAuthService.php';
require_once $baseDir.'/services/ManagerOutboundService.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
function mediaOut(array $data,int $status=200):void{http_response_code($status);echo json_encode($data,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);exit;}

$managerId=(int)($_SESSION['manager_id']??0);
$manager=$managerId?ManagerAuthService::byId($managerId):null;
if(!$manager)mediaOut(['ok'=>false,'error'=>'unauthorized'],401);
$csrf=(string)($_SESSION['csrf']??'');
if($csrf===''||!hash_equals($csrf,(string)($_POST['csrf']??'')))mediaOut(['ok'=>false,'error'=>'csrf'],403);
$conversationId=(int)($_POST['conversation_id']??0);
if($conversationId<=0)mediaOut(['ok'=>false,'error'=>'invalid_conversation'],400);
if(empty($_FILES['file'])||!is_array($_FILES['file']))mediaOut(['ok'=>false,'error'=>'file_required'],400);
$file=$_FILES['file'];$uploadError=(int)($file['error']??UPLOAD_ERR_NO_FILE);
if($uploadError!==UPLOAD_ERR_OK){
    $error=$uploadError===UPLOAD_ERR_INI_SIZE||$uploadError===UPLOAD_ERR_FORM_SIZE?'file_too_large':'upload_failed';
    mediaOut(['ok'=>false,'error'=>$error],400);
}
$tmp=(string)($file['tmp_name']??'');if($tmp===''||!is_uploaded_file($tmp))mediaOut(['ok'=>false,'error'=>'invalid_upload'],400);
$name=trim(basename((string)($file['name']??'attachment')));$name=preg_replace('/[\x00-\x1F\x7F]+/u','',$name)?:'attachment';
$mime='application/octet-stream';if(class_exists('finfo')){$finfo=new finfo(FILEINFO_MIME_TYPE);$detected=$finfo->file($tmp);if(is_string($detected)&&$detected!=='')$mime=$detected;}
$caption=trim((string)($_POST['caption']??''));
$ok=ManagerOutboundService::sendMedia($conversationId,$managerId,$tmp,$name,$mime,$caption);
if($ok)mediaOut(['ok'=>true,'media_type'=>ManagerOutboundService::attachmentTypeForMime($mime)]);
$failure=ManagerOutboundService::lastFailure();
mediaOut(['ok'=>false,'error'=>'delivery_failed','failure'=>$failure,'error_message'=>$failure?ManagerOutboundService::failureNotice($failure):'Медиа не доставлено'],409);
