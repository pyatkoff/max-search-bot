<?php
$baseDir=dirname(__DIR__);
require_once $baseDir.'/config.php';
require_once $baseDir.'/maxsearchclass.php';
require_once __DIR__.'/lib/ManagerHttp.php';
require_once $baseDir.'/services/ManagerOutboundService.php';
require_once $baseDir.'/services/ManagerMediaCache.php';

ManagerHttp::startJson();
ManagerHttp::requireManager();
ManagerHttp::requireCsrf($_POST);
$managerId=ManagerHttp::managerId();

$conversationId=(int)($_POST['conversation_id']??0);
if($conversationId<=0)ManagerHttp::respond(['ok'=>false,'error'=>'invalid_conversation'],400);
if(empty($_FILES['file'])||!is_array($_FILES['file']))ManagerHttp::respond(['ok'=>false,'error'=>'file_required'],400);
$file=$_FILES['file'];$uploadError=(int)($file['error']??UPLOAD_ERR_NO_FILE);
if($uploadError!==UPLOAD_ERR_OK){
    $error=$uploadError===UPLOAD_ERR_INI_SIZE||$uploadError===UPLOAD_ERR_FORM_SIZE?'file_too_large':'upload_failed';
    ManagerHttp::respond(['ok'=>false,'error'=>$error],400);
}
$tmp=(string)($file['tmp_name']??'');if($tmp===''||!is_uploaded_file($tmp))ManagerHttp::respond(['ok'=>false,'error'=>'invalid_upload'],400);
$name=trim(basename((string)($file['name']??'attachment')));$name=preg_replace('/[\x00-\x1F\x7F]+/u','',$name)?:'attachment';
$mime='application/octet-stream';if(class_exists('finfo')){$finfo=new finfo(FILEINFO_MIME_TYPE);$detected=$finfo->file($tmp);if(is_string($detected)&&$detected!=='')$mime=$detected;}
$caption=trim((string)($_POST['caption']??''));
$preview=ManagerMediaCache::store($conversationId,$managerId,$tmp,$name,$mime);
$previewUrl=is_array($preview)?(string)($preview['url']??''):'';
$ok=ManagerOutboundService::sendMedia($conversationId,$managerId,$tmp,$name,$mime,$caption,$previewUrl);
if($ok)ManagerHttp::respond(['ok'=>true,'media_type'=>ManagerOutboundService::attachmentTypeForMime($mime)]);
if(is_array($preview)&&!empty($preview['id']))ManagerMediaCache::remove((string)$preview['id']);
$failure=ManagerOutboundService::lastFailure();
ManagerHttp::respond(['ok'=>false,'error'=>'delivery_failed','failure'=>$failure,'error_message'=>$failure?ManagerOutboundService::failureNotice($failure):'Медиа не доставлено'],409);
