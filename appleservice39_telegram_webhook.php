<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
if(($_SERVER['REQUEST_METHOD']??'')!=='POST'){
    header('Allow: POST');http_response_code(405);echo '{"ok":false}';exit;
}
if(!is_file(__DIR__.'/config.php')){
    http_response_code(503);echo '{"ok":false}';exit;
}
require_once __DIR__.'/config.php';
require_once __DIR__.'/services/AppleService39TelegramIngress.php';

try{
    $connection=AppleService39TelegramConnection::runtimeBinding();
    if(!AppleService39TelegramIngress::authenticated($connection,(string)($_SERVER['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN']??''))){
        http_response_code(403);echo '{"ok":false}';exit;
    }
    $body=file_get_contents('php://input',false,null,0,262145);
    $update=is_string($body)&&strlen($body)<=262144?json_decode($body,true,32,JSON_BIGINT_AS_STRING):null;
    if(!is_array($update)||array_is_list($update)){http_response_code(400);echo '{"ok":false}';exit;}
    $status=AppleService39TelegramIngress::handle($connection,$update);
}catch(Throwable $ignored){$status=503;}
http_response_code($status);
echo $status===200?'{"ok":true}':'{"ok":false}';
