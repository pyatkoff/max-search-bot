<?php
session_name('anytour_manager_panel');
session_set_cookie_params(['lifetime'=>60*60*12,'path'=>'/max-search/manager/','secure'=>true,'httponly'=>true,'samesite'=>'Lax']);
session_start();

$baseDir=dirname(__DIR__);
require_once $baseDir.'/services/ManagerAuthService.php';
require_once $baseDir.'/services/ManagerConversationService.php';
require_once $baseDir.'/services/ManagerMediaCache.php';

$managerId=(int)($_SESSION['manager_id']??0);
$manager=$managerId?ManagerAuthService::byId($managerId):null;
if(!$manager){http_response_code(401);exit;}
$id=(string)($_GET['id']??'');$media=ManagerMediaCache::get($id);
if(!$media){http_response_code(404);exit;}
$conversationId=(int)($media['conversation_id']??0);
if($conversationId<=0||!ManagerConversationService::detail($conversationId,$managerId)){http_response_code(403);exit;}
$path=(string)($media['path']??'');if($path===''||!is_file($path)){http_response_code(404);exit;}
$mime=(string)($media['mime']??'application/octet-stream');
$name=(string)($media['name']??'attachment');
header('Content-Type: '.$mime);
header('Content-Length: '.filesize($path));
header('Content-Disposition: inline; filename="'.str_replace(['"','\\'],['_','_'],$name).'"');
header('Cache-Control: private, max-age=3600');
header('X-Content-Type-Options: nosniff');
readfile($path);
