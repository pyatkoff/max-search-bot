<?php

declare(strict_types=1);
require_once dirname(__DIR__).'/config.php';
require_once __DIR__.'/lib/ManagerHttp.php';
require_once dirname(__DIR__).'/services/ManagerCustomerAvatarService.php';
ManagerHttp::start();
header('Cache-Control: private, no-store');
header('X-Content-Type-Options: nosniff');
header("Content-Security-Policy: default-src 'none'; sandbox");
ManagerHttp::requireManager();
if (($_SERVER['REQUEST_METHOD']??'')!=='GET') {header('Allow: GET');http_response_code(405);exit;}
$id=filter_var($_GET['conversation_id']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
try {$photo=$id!==false && $id!==null?(new ManagerCustomerAvatarService())->avatar(ManagerHttp::managerId(),$id):null;}
catch (Throwable $e) {$photo=null;}
if (!$photo) {http_response_code(404);exit;}
header('Content-Type: '.$photo['mime']);
header('Content-Length: '.strlen($photo['bytes']));
echo $photo['bytes'];
