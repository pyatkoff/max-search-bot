<?php

declare(strict_types=1);

require_once dirname(__DIR__).'/config.php';
require_once __DIR__.'/lib/ManagerHttp.php';
require_once dirname(__DIR__).'/services/ManagerQuickReplyService.php';

ManagerHttp::startJson();
if (ManagerHttp::managerId()>0) {
    try {ProjectAccessService::initializeReadOnly();}
    catch (Throwable $ignored) {ManagerHttp::respond(['ok'=>false,'error'=>'project_unavailable'],503);}
}
$manager=ManagerHttp::requireManager();
if (($_SERVER['REQUEST_METHOD']??'')!=='POST') ManagerHttp::respond(['ok'=>false,'error'=>'method_not_allowed'],405);
$files=[];
if (str_starts_with(strtolower((string)($_SERVER['CONTENT_TYPE']??'')),'multipart/form-data')) {
    try {$data=json_decode((string)($_POST['command']??''),true,32,JSON_THROW_ON_ERROR);}
    catch (Throwable $ignored) {ManagerHttp::respond(['ok'=>false,'error'=>'invalid_materials'],400);}
    if (!is_array($data)||array_is_list($data)) ManagerHttp::respond(['ok'=>false,'error'=>'invalid_materials'],400);
    if (array_diff(array_keys($_FILES),['materials'])) ManagerHttp::respond(['ok'=>false,'error'=>'invalid_materials'],400);
    $vector=$_FILES['materials']??null;
    if ($vector!==null) {
        if (!is_array($vector)||!is_array($vector['name']??null)||!array_is_list($vector['name'])||count($vector['name'])>10) ManagerHttp::respond(['ok'=>false,'error'=>'invalid_materials'],400);
        foreach (['error','tmp_name','size','type'] as $field) if (!is_array($vector[$field]??null)||array_keys($vector[$field])!==array_keys($vector['name'])) ManagerHttp::respond(['ok'=>false,'error'=>'invalid_materials'],400);
        foreach ($vector['name'] as $i=>$name) {
            $error=$vector['error'][$i]??UPLOAD_ERR_NO_FILE;
            if ($error!==UPLOAD_ERR_OK) ManagerHttp::respond(['ok'=>false,'error'=>in_array($error,[UPLOAD_ERR_INI_SIZE,UPLOAD_ERR_FORM_SIZE],true)?'material_too_large':'invalid_material_file'],400);
            $path=$vector['tmp_name'][$i]??null;
            if (!is_string($name)||!is_string($path)||!is_uploaded_file($path)) ManagerHttp::respond(['ok'=>false,'error'=>'invalid_material_file'],400);
            $files[]=['name'=>$name,'tmp_name'=>$path];
        }
    }
} else $data=ManagerHttp::body();
ManagerHttp::requireCsrf($data);
$actor=(int)$manager['id'];$admin=ManagerHttp::isAdmin($manager);
if (!is_int($data['expected_manager_id']??null)||$data['expected_manager_id']!==$actor) ManagerHttp::respond(['ok'=>false,'error'=>'actor_changed'],409);
if (!is_string($data['project_key']??null)||!preg_match('/^[a-z][a-z0-9_-]{0,63}$/D',$data['project_key'])) ManagerHttp::respond(['ok'=>false,'error'=>'invalid_project'],400);
$action=(string)($data['action']??'');
try {
    if ($action==='list') $result=ManagerQuickReplyService::list($actor,$admin,$data);
    elseif ($action==='toolbar') $result=ManagerQuickReplyService::toolbar($actor,$admin,$data);
    elseif ($action==='operation') $result=ManagerQuickReplyService::operation($actor,(string)($data['operation_id']??''),$data);
    elseif ($action==='prepare_insert') $result=ManagerQuickReplyService::prepareInsert($actor,$data);
    elseif ($action==='material') $result=ManagerQuickReplyService::material($actor,$data);
    else $result=ManagerQuickReplyService::mutate($actor,$admin,$action,$data,$files);
} catch (Throwable $e) {
    // No content, account token or reply body is logged. A lost write response
    // must be reconciled by operation_id instead of starting another command.
    error_log('MANAGER_QUICK_REPLY_FAILURE '.get_class($e));
    ManagerHttp::respond(['ok'=>false,'error'=>'storage_unavailable'],503);
}
if (!empty($result['ok'])&&isset($result['stream'])&&is_resource($result['stream'])) {
    if (session_status()===PHP_SESSION_ACTIVE) session_write_close();
    header('Content-Type: application/octet-stream');
    header('Content-Length: '.(int)$result['size']);
    header("Content-Disposition: attachment; filename=\"attachment\"; filename*=UTF-8''".rawurlencode($result['name']));
    header('X-Content-Type-Options: nosniff');
    header("Content-Security-Policy: default-src 'none'; sandbox");
    header('Cache-Control: no-store, private');
    header('X-Material-SHA256: '.$result['sha256']);
    try {fpassthru($result['stream']);} finally {fclose($result['stream']);}
    exit;
}
$error=(string)($result['error']??'');
$status=!empty($result['ok'])?200:match($error) {
    'unauthorized'=>401,'forbidden'=>403,'not_found'=>404,
    'conflict','operation_mismatch'=>409,'unknown_action'=>400,default=>422,
};
ManagerHttp::respond($result,$status);
