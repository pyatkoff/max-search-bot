<?php
session_name('anytour_manager_panel');
session_set_cookie_params(['lifetime'=>60*60*12,'path'=>'/max-search/manager/','secure'=>true,'httponly'=>true,'samesite'=>'Lax']);
session_start();

$baseDir = dirname(__DIR__);
require_once $baseDir . '/config.php';
require_once $baseDir . '/maxsearchclass.php';
require_once $baseDir . '/services/ManagerAuthService.php';
require_once $baseDir . '/services/ManagerAvailabilityService.php';
require_once $baseDir . '/services/ManagerConversationService.php';
require_once $baseDir . '/services/ManagerOutboundService.php';
require_once $baseDir . '/services/ProjectAccessService.php';
require_once $baseDir . '/services/RoutingAdminService.php';
require_once $baseDir . '/services/AdminDirectoryService.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function out(array $data, int $status=200): void { http_response_code($status); echo json_encode($data, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES); exit; }
function body(): array { $raw=(string)file_get_contents('php://input'); $v=json_decode($raw,true); return is_array($v)?$v:[]; }
function manager(): ?array { $id=(int)($_SESSION['manager_id']??0); return $id?ManagerAuthService::byId($id):null; }
function csrf(): string { if(empty($_SESSION['csrf'])) $_SESSION['csrf']=bin2hex(random_bytes(24)); return (string)$_SESSION['csrf']; }
function requireCsrf(array $data): void { if(!hash_equals(csrf(),(string)($data['csrf']??''))) out(['ok'=>false,'error'=>'csrf'],403); }
function requireAdmin(array $m): void { if((string)($m['role']??'manager')!=='admin') out(['ok'=>false,'error'=>'forbidden'],403); }

$data=body(); $action=(string)($data['action']??'');
if($action==='login'){
    $m=ManagerAuthService::authenticate((string)($data['login']??''),(string)($data['password']??''));
    if(!$m) out(['ok'=>false,'error'=>'invalid_credentials'],401);
    session_regenerate_id(true); $_SESSION['manager_id']=(int)$m['id'];
    out(['ok'=>true,'manager'=>$m,'projects'=>$m['projects']??[],'csrf'=>csrf()]);
}

$m=manager(); if(!$m) out(['ok'=>false,'error'=>'unauthorized'],401);
$isAdmin=(string)($m['role']??'manager')==='admin';
if($action==='me') out(['ok'=>true,'manager'=>$m,'projects'=>$m['projects']??[],'csrf'=>csrf()]);
requireCsrf($data);

if($action==='logout'){ $_SESSION=[]; session_destroy(); out(['ok'=>true]); }
if($action==='set_working'){
    $ok=ManagerAvailabilityService::setWorking((int)$m['id'],!empty($data['working']));
    $fresh=ManagerAuthService::byId((int)$m['id']);
    out(['ok'=>$ok,'manager'=>$fresh]);
}
if($action==='projects') out(['ok'=>true,'projects'=>ProjectAccessService::projectsForManager((int)$m['id'])]);
if($action==='manager_filters'){ requireAdmin($m); out(['ok'=>true,'managers'=>ManagerConversationService::filterManagers((int)$m['id'])]); }
if($action==='admin_snapshot'){ requireAdmin($m); out(['ok'=>true,'admin'=>AdminDirectoryService::snapshot()]); }
if($action==='save_project'){ requireAdmin($m); $r=AdminDirectoryService::saveProject($data); out($r,$r['ok']?200:409); }
if($action==='save_manager'){ requireAdmin($m); $r=AdminDirectoryService::saveManager($data,(int)$m['id']); out($r,$r['ok']?200:409); }
if($action==='routing_snapshot') out(['ok'=>true,'routing'=>RoutingAdminService::snapshot((int)$m['id'],(string)($data['project_key']??''))]);
if($action==='save_group'){
    $ok=RoutingAdminService::saveGroup((int)$m['id'],(string)($data['project_key']??''),(int)($data['group_id']??0),(string)($data['group_key']??''),(string)($data['display_name']??''),(array)($data['member_ids']??[]));out(['ok'=>$ok],$ok?200:403);
}
if($action==='save_source'){
    $ok=RoutingAdminService::saveSource((int)$m['id'],(string)($data['project_key']??''),(int)($data['source_id']??0),(string)($data['source_key']??''),(string)($data['display_name']??''),(string)($data['channel']??''),(int)($data['primary_group_id']??0),(string)($data['fallback_mode']??'none'),(int)($data['fallback_group_id']??0),(int)($data['fallback_after_minutes']??0));out(['ok'=>$ok],$ok?200:403);
}
if($action==='list'){
    $queue=(string)($data['queue']??'waiting');
    if(!$isAdmin&&($queue==='waiting'||$queue==='all')&&!ManagerAvailabilityService::isWorking((int)$m['id'])) out(['ok'=>true,'conversations'=>$queue==='all'?ManagerConversationService::list((int)$m['id'],'mine',100,(string)($data['project_key']??'*')):[]]);
    out(['ok'=>true,'conversations'=>ManagerConversationService::list((int)$m['id'],$queue,100,(string)($data['project_key']??'*'),$isAdmin?(string)($data['manager_filter']??''):'')]);
}
if($action==='counts'){
    $counts=ManagerConversationService::queueCounts((int)$m['id'],(string)($data['project_key']??'*'));
    if(!$isAdmin&&!ManagerAvailabilityService::isWorking((int)$m['id'])) $counts['waiting']=['count'=>0,'unread'=>0];
    out(['ok'=>true,'counts'=>$counts]);
}
if($action==='detail'){
    $d=ManagerConversationService::detail((int)($data['conversation_id']??0),(int)$m['id']);
    if(!$d) out(['ok'=>false,'error'=>'not_found'],404); out(['ok'=>true]+$d);
}
if($action==='take'){
    if(!$isAdmin&&!ManagerAvailabilityService::isWorking((int)$m['id'])) out(['ok'=>false,'error'=>'not_working'],409);
    out(['ok'=>ManagerConversationService::take((int)($data['conversation_id']??0),(int)$m['id'])]);
}
if($action==='release') out(['ok'=>ManagerConversationService::release((int)($data['conversation_id']??0),(int)$m['id'])]);
if($action==='close') out(['ok'=>ManagerConversationService::close((int)($data['conversation_id']??0),(int)$m['id'])]);
if($action==='reopen') out(['ok'=>ManagerConversationService::reopen((int)($data['conversation_id']??0),(int)$m['id'])]);
if($action==='send'){
    $ok=ManagerOutboundService::send((int)($data['conversation_id']??0),(int)$m['id'],(string)($data['text']??''));
    out(['ok'=>$ok],$ok?200:409);
}
out(['ok'=>false,'error'=>'unknown_action'],400);
