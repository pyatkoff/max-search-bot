<?php
$baseDir=dirname(__DIR__);
require_once $baseDir.'/config.php';
require_once $baseDir.'/maxsearchclass.php';
require_once __DIR__.'/lib/ManagerHttp.php';
require_once $baseDir.'/services/ManagerOutboundService.php';
require_once $baseDir.'/services/ManagerMediaCache.php';

ManagerHttp::startJson();
if(ManagerHttp::managerId()>0){
    try{ProjectAccessService::initializeReadOnly();}
    catch(Throwable $ignored){ManagerHttp::respond(['ok'=>false,'error'=>'project_unavailable'],503);}
}
ManagerHttp::requireManager();
ManagerHttp::requireCsrf($_POST);
$managerId=ManagerHttp::managerId();
$expectedManager=$_POST['expected_manager_id']??null;
if(!is_string($expectedManager)||!preg_match('/\A[1-9][0-9]*\z/D',$expectedManager)
    ||filter_var($expectedManager,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]])!==$managerId){
    ManagerHttp::respond(['ok'=>false,'error'=>'actor_changed'],409);
}

$conversationId=(int)($_POST['conversation_id']??0);
if($conversationId<=0)ManagerHttp::respond(['ok'=>false,'error'=>'invalid_conversation'],400);
$operationId=$_POST['operation_id']??null;
if(!is_string($operationId)||!preg_match('/\A[a-f0-9]{32}\z/D',$operationId))ManagerHttp::respond(['ok'=>false,'error'=>'invalid_send_attempt'],400);
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
// Authentication/CSRF and actor identity are captured. Release the browser
// session before the durable reservation/provider so read-only lookup can run.
if(session_status()===PHP_SESSION_ACTIVE)session_write_close();
$result=ManagerOutboundService::sendMediaAttempt($conversationId,$managerId,$operationId,$tmp,$name,$mime,$caption);
ManagerHttp::respond($result,isset($result['attempt'])?200:409);
