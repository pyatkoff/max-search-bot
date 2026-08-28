<?php
$baseDir=dirname(__DIR__);
require_once $baseDir.'/config.php';
require_once __DIR__.'/lib/ManagerHttp.php';
require_once $baseDir.'/services/ConversationDb.php';
require_once $baseDir.'/services/ManagerPushHealth.php';

ManagerHttp::startJson();
ManagerHttp::requireManager();
$managerId=ManagerHttp::managerId();

$status=ManagerPushHealth::statusForManager(ConversationDb::connection(),$managerId);
ManagerHttp::respond(['ok'=>true,'push_status'=>$status]);
