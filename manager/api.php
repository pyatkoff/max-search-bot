<?php
session_name('anytour_manager_panel');
session_set_cookie_params(['lifetime'=>60*60*12,'path'=>'/max-search/manager/','secure'=>true,'httponly'=>true,'samesite'=>'Lax']);
session_start();

$baseDir = dirname(__DIR__);
require_once $baseDir . '/config.php';
require_once $baseDir . '/maxsearchclass.php';
require_once $baseDir . '/services/ManagerAuthService.php';
require_once $baseDir . '/services/ManagerConversationService.php';
require_once $baseDir . '/services/ManagerOutboundService.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function out(array $data, int $status=200): void { http_response_code($status); echo json_encode($data, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES); exit; }
function body(): array { $raw=(string)file_get_contents('php://input'); $v=json_decode($raw,true); return is_array($v)?$v:[]; }
function manager(): ?array { $id=(int)($_SESSION['manager_id']??0); return $id?ManagerAuthService::byId($id):null; }
function csrf(): string { if(empty($_SESSION['csrf'])) $_SESSION['csrf']=bin2hex(random_bytes(24)); return (string)$_SESSION['csrf']; }
function requireCsrf(array $data): void { if(!hash_equals(csrf(),(string)($data['csrf']??''))) out(['ok'=>false,'error'=>'csrf'],403); }

$data=body(); $action=(string)($data['action']??'');
if($action==='login'){
    $m=ManagerAuthService::authenticate((string)($data['login']??''),(string)($data['password']??''));
    if(!$m) out(['ok'=>false,'error'=>'invalid_credentials'],401);
    session_regenerate_id(true); $_SESSION['manager_id']=(int)$m['id'];
    out(['ok'=>true,'manager'=>$m,'csrf'=>csrf()]);
}

$m=manager(); if(!$m) out(['ok'=>false,'error'=>'unauthorized'],401);
if($action==='me') out(['ok'=>true,'manager'=>$m,'csrf'=>csrf()]);
requireCsrf($data);

if($action==='logout'){ $_SESSION=[]; session_destroy(); out(['ok'=>true]); }
if($action==='list') out(['ok'=>true,'conversations'=>ManagerConversationService::list((int)$m['id'],(string)($data['status']??''),100)]);
if($action==='detail'){
    $d=ManagerConversationService::detail((int)($data['conversation_id']??0));
    if(!$d) out(['ok'=>false,'error'=>'not_found'],404); out(['ok'=>true]+$d);
}
if($action==='take') out(['ok'=>ManagerConversationService::take((int)($data['conversation_id']??0),(int)$m['id'])]);
if($action==='release') out(['ok'=>ManagerConversationService::release((int)($data['conversation_id']??0),(int)$m['id'])]);
if($action==='close') out(['ok'=>ManagerConversationService::close((int)($data['conversation_id']??0),(int)$m['id'])]);
if($action==='send'){
    $ok=ManagerOutboundService::send((int)($data['conversation_id']??0),(int)$m['id'],(string)($data['text']??''));
    out(['ok'=>$ok],$ok?200:409);
}
out(['ok'=>false,'error'=>'unknown_action'],400);
