<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/config.php';
require_once __DIR__.'/lib/ManagerHttp.php';
ManagerHttp::startJson();$manager=ManagerHttp::requireManager();
if (($_SERVER['REQUEST_METHOD']??'')!=='POST') ManagerHttp::respond(['ok'=>false,'error'=>'method_not_allowed'],405);
$data=ManagerHttp::body();ManagerHttp::requireCsrf($data);
// A new tab cannot silently become another account's writer after shared-cookie login.
if (!is_int($data['editor_actor']??null) || $data['editor_actor']!==(int)$manager['id']) ManagerHttp::respond(['ok'=>false,'error'=>'stale_actor'],409);
if (($data['action']??null)==='editor_context') {
    $id=$data['conversation_id']??null;
    if (!is_int($id) || $id<=0) ManagerHttp::respond(['ok'=>false,'error'=>'invalid_conversation_id'],422);
    session_write_close();
    require_once dirname(__DIR__).'/services/ManagerTourSelectionService.php';
    try {
        $r=ManagerTourSelectionService::get((int)$manager['id'],$id);
        if (empty($r['ok'])) ManagerHttp::respond($r,($r['error']??'')==='not_found'?404:403);
        $c=ManagerConversationService::visibleConversation($id,(int)$manager['id']);
        if (!$c) ManagerHttp::respond(['ok'=>false,'error'=>'not_found'],404);
        ManagerHttp::respond(['ok'=>true,'context'=>['id'=>$id,'actor'=>(int)$manager['id'],'display_name'=>(string)($c['display_name']??'Турист'),'channel'=>(string)$c['channel'],'status'=>(string)$c['status'],'can_edit'=>$r['selection']['can_edit']]]);
    } catch (Throwable $ignored) {ManagerHttp::respond(['ok'=>false,'error'=>'storage_unavailable'],503);}
}
// The canonical controllers re-read the original JSON and retain every fresh
// auth/CSRF/project/source/assignment/version/recipient fence and receipt owner.
$resource=$data['editor_resource']??null;
if ($resource==='tour-selections-api.php') require __DIR__.'/tour-selections-api.php';
elseif ($resource==='tour-selection-photo.php') require __DIR__.'/tour-selection-photo.php';
else ManagerHttp::respond(['ok'=>false,'error'=>'invalid_editor_resource'],422);
