<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}

// Real PHP HTTP/session lifecycle and a path-aware synthetic cookie jar.
// The isolated loopback API uses the production ManagerHttp/RequestContext;
// fixture identities never reach a database, external host or real account.
$root=dirname(__DIR__);$tmp=sys_get_temp_dir().'/manager2-cookie-'.bin2hex(random_bytes(6));
mkdir($tmp,0700);mkdir($tmp.'/sessions',0700);
$router=$tmp.'/router.php';
file_put_contents($router,"<?php\n\$root=".var_export($root,true).";\n". <<<'PHP'
$path=parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH);
if($path==='/manager/api.php'){
    $_SERVER['SCRIPT_NAME']=$path;
    require $root.'/manager/lib/ManagerHttp.php';
    ManagerHttp::startJson();
    $data=ManagerHttp::body();
    if(($data['action']??'')==='fixture_login'){
        $_SESSION['manager_id']=7;
        ManagerHttp::respond(['ok'=>true,'manager_id'=>ManagerHttp::managerId()]);
    }
    ManagerHttp::respond(['ok'=>ManagerHttp::managerId()===7,'manager_id'=>ManagerHttp::managerId()],ManagerHttp::managerId()===7?200:401);
}
if(in_array($path,['/manager2/','/manager2/index.php','/manager/','/manager/index.php'],true)){
    $_SERVER['SCRIPT_NAME']=str_starts_with($path,'/manager2')?'/manager2/index.php':'/manager/index.php';
    require $root.(str_starts_with($path,'/manager2')?'/manager2/index.php':'/manager/index.php');
    return;
}
http_response_code(404);
PHP);
$socket=stream_socket_server('tcp://127.0.0.1:0',$errno,$error);
if(!$socket)throw new RuntimeException('Cannot reserve loopback port');
$address=stream_socket_get_name($socket,false);fclose($socket);
$server=proc_open([PHP_BINARY,'-d','session.save_path='.$tmp.'/sessions','-S',$address,$router],[0=>['pipe','r'],1=>['file',$tmp.'/server.log','a'],2=>['file',$tmp.'/server.log','a']],$pipes,$root);
if(!is_resource($server))throw new RuntimeException('Cannot start fixture HTTP server');
$base='http://'.$address;$cookies=[];$passed=0;$failed=0;
function requestCookieFixture(string $path,string $action=''):array{
    global $base,$cookies;
    $headers=['Content-Type: application/json'];$sent=[];
    foreach($cookies as $cookie)if(str_starts_with($path,$cookie['path']))$sent[]=$cookie['name'].'='.$cookie['value'];
    if($sent)$headers[]='Cookie: '.implode('; ',$sent);
    $context=stream_context_create(['http'=>['method'=>$action===''?'GET':'POST','header'=>implode("\r\n",$headers),'content'=>$action===''?'':json_encode(['action'=>$action]),'ignore_errors'=>true,'timeout'=>3]]);
    $body=@file_get_contents($base.$path,false,$context);$response=$http_response_header??[];$set=[];
    foreach($response as $line){
        if(!preg_match('/^Set-Cookie:\s*([^=;]+)=([^;]*)(.*)$/i',$line,$m))continue;
        $scope=preg_match('/;\s*path=([^;]+)/i',$m[3],$p)?$p[1]:'/';
        $cookies[$m[1].'|'.$scope]=['name'=>$m[1],'value'=>$m[2],'path'=>$scope];
        $set[]=$line;
    }
    return ['body'=>$body===false?'':$body,'headers'=>$response,'set_cookie'=>$set,'json'=>json_decode($body===false?'':$body,true)];
}
function cookieCheck(string $name,bool $ok):void{global $passed,$failed;echo ($ok?'PASS ':'FAIL ').$name."\n";if($ok)$passed++;else$failed++;}
try{
    $ready=false;
    for($i=0;$i<30;$i++){if($probe=@stream_socket_client('tcp://'.$address,$e,$s,.1)){fclose($probe);$ready=true;break;}usleep(20000);}
    if(!$ready)throw new RuntimeException('Fixture HTTP server did not start');
    $login=requestCookieFixture('/manager/api.php','fixture_login');
    cookieCheck('synthetic login uses the canonical session context',($login['json']['manager_id']??0)===7);
    $header=implode("\n",$login['set_cookie']);
    cookieCheck('canonical cookie retains Manager path and secure attributes',stripos($header,'path=/manager/')!==false&&stripos($header,'secure')!==false&&stripos($header,'httponly')!==false&&stripos($header,'samesite=Lax')!==false);
    cookieCheck('authenticated API works before alternate navigation',(requestCookieFixture('/manager/api.php','me')['json']['manager_id']??0)===7);
    foreach(['/manager2/','/manager2/index.php','/manager2/','/manager/'] as $path){
        $page=requestCookieFixture($path);
        cookieCheck('real shell renders '.$path,str_contains($page['body'],'id="workspaceRoot"'));
        cookieCheck('authentication survives navigation to '.$path,(requestCookieFixture('/manager/api.php','me')['json']['manager_id']??0)===7);
        if(str_starts_with($path,'/manager2'))cookieCheck('alternate HTML does not replace the Manager cookie '.$path,$page['set_cookie']===[]);
    }
    $cookies=[];
    $page=requestCookieFixture('/manager2/');
    cookieCheck('anonymous Manager2 HTML does not mint a Manager session',$page['set_cookie']===[]);
    cookieCheck('anonymous canonical API remains unauthorized',(requestCookieFixture('/manager/api.php','me')['json']['manager_id']??0)===0);
}finally{
    proc_terminate($server);fclose($pipes[0]);proc_close($server);
    foreach(glob($tmp.'/sessions/*') as $file)unlink($file);
    rmdir($tmp.'/sessions');unlink($router);@unlink($tmp.'/server.log');rmdir($tmp);
}
echo 'MANAGER2 HTTP COOKIE CHECKS: '.($passed+$failed).' | PASS '.$passed.' | FAIL '.$failed."\n";
exit($failed?1:0);
