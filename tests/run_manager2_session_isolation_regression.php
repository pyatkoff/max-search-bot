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
        $_SESSION['manager_id']=($data['manager_id']??7)===8?8:7;
        ManagerHttp::respond(['ok'=>true,'manager_id'=>ManagerHttp::managerId(),'csrf'=>ManagerHttp::csrf(true)]);
    }
    if(ManagerHttp::managerId()<=0)ManagerHttp::respond(['ok'=>false,'error'=>'unauthorized'],401);
    if(($data['action']??'')==='admin_snapshot'){
        // Synthetic identity only; production HTTP/CSRF/role helpers own the gates.
        ManagerHttp::requireCsrf($data);
        ManagerHttp::requireAdmin(['role'=>ManagerHttp::managerId()===7?'admin':'manager']);
    }
    ManagerHttp::respond(['ok'=>true,'manager_id'=>ManagerHttp::managerId(),'csrf'=>ManagerHttp::csrf(true)]);
}
if(in_array($path,['/manager2/','/manager2/index.php','/manager/','/manager/index.php','/manager2/admin.php','/manager/admin.php'],true)){
    $entry=str_ends_with($path,'admin.php')?$path:(str_starts_with($path,'/manager2')?'/manager2/index.php':'/manager/index.php');
    $_SERVER['SCRIPT_NAME']=$entry;
    require $root.$entry;
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
function requestCookieFixture(string $path,string $action='',array $data=[],?string $method=null):array{
    global $base,$cookies;
    $headers=['Content-Type: application/json'];$sent=[];
    foreach($cookies as $cookie)if(str_starts_with($path,$cookie['path']))$sent[]=$cookie['name'].'='.$cookie['value'];
    if($sent)$headers[]='Cookie: '.implode('; ',$sent);
    $context=stream_context_create(['http'=>['method'=>$method??($action===''?'GET':'POST'),'follow_location'=>0,'header'=>implode("\r\n",$headers),'content'=>$action===''?'':json_encode(['action'=>$action]+$data),'ignore_errors'=>true,'timeout'=>3]]);
    $body=@file_get_contents($base.$path,false,$context);$response=$http_response_header??[];$set=[];
    foreach($response as $line){
        if(!preg_match('/^Set-Cookie:\s*([^=;]+)=([^;]*)(.*)$/i',$line,$m))continue;
        $scope=preg_match('/;\s*path=([^;]+)/i',$m[3],$p)?$p[1]:'/';
        $cookies[$m[1].'|'.$scope]=['name'=>$m[1],'value'=>$m[2],'path'=>$scope];
        $set[]=$line;
    }
    preg_match('/^HTTP\/\S+ (\d+)/',$response[0]??'',$status);
    return ['status'=>(int)($status[1]??0),'body'=>$body===false?'':$body,'headers'=>$response,'set_cookie'=>$set,'json'=>json_decode($body===false?'':$body,true)];
}
function cookieCheck(string $name,bool $ok):void{global $passed,$failed;echo ($ok?'PASS ':'FAIL ').$name."\n";if($ok)$passed++;else$failed++;}
try{
    $ready=false;
    for($i=0;$i<30;$i++){if($probe=@stream_socket_client('tcp://'.$address,$e,$s,.1)){fclose($probe);$ready=true;break;}usleep(20000);}
    if(!$ready)throw new RuntimeException('Fixture HTTP server did not start');
    $login=requestCookieFixture('/manager/api.php','fixture_login');
    cookieCheck('synthetic login uses the canonical session context',($login['json']['manager_id']??0)===7);
    $header=implode("\n",$login['set_cookie']);
    $sessionCookies=$cookies;$csrf=$login['json']['csrf']??'';
    cookieCheck('canonical cookie retains Manager path and secure attributes',stripos($header,'path=/manager/')!==false&&stripos($header,'secure')!==false&&stripos($header,'httponly')!==false&&stripos($header,'samesite=Lax')!==false);
    cookieCheck('authenticated API works before alternate navigation',(requestCookieFixture('/manager/api.php','me')['json']['manager_id']??0)===7);
    cookieCheck('canonical CSRF token is issued by protected API',strlen($csrf)===48);
    foreach(['/manager/','/manager/index.php','/manager/?conversation_id=41&view=all','/manager/index.php?conversation_id=41&view=all'] as $path){
        $redirect=requestCookieFixture($path);$location='/manager2/'.(str_contains($path,'?')?'?'.explode('?',$path,2)[1]:'');
        cookieCheck('legacy navigation redirects to canonical Manager2 '.$path,$redirect['status']===302&&in_array('Location: '.$location,$redirect['headers'],true));
        cookieCheck('redirect sends no legacy shell or session replacement '.$path,$redirect['body']===''&&$redirect['set_cookie']===[]&&$cookies===$sessionCookies);
        cookieCheck('redirect is not permanently cached '.$path,in_array('Cache-Control: no-store',$redirect['headers'],true));
        $target=requestCookieFixture($location);
        cookieCheck('redirect destination renders shared Manager2 without a loop '.$path,$target['status']===200&&str_contains($target['body'],'<body class="manager2">'));
        $me=requestCookieFixture('/manager/api.php','me');
        cookieCheck('canonical authentication and CSRF survive legacy redirect '.$path,($me['json']['manager_id']??0)===7&&($me['json']['csrf']??'')===$csrf);
    }
    $head=requestCookieFixture('/manager/', '', [], 'HEAD');
    cookieCheck('HEAD redirects without rendering or replacing session',$head['status']===302&&$head['body']===''&&$head['set_cookie']===[]&&in_array('Location: /manager2/',$head['headers'],true));
    foreach(['/manager2/','/manager2/index.php','/manager2/admin.php','/manager2/admin.php','/manager/admin.php','/manager2/'] as $path){
        $page=requestCookieFixture($path);
        $admin=str_ends_with($path,'admin.php');
        cookieCheck('real shell renders '.$path,$page['status']===200&&str_contains($page['body'],$admin?'id="adminSection-managers"':'id="workspaceRoot"'));
        $me=requestCookieFixture('/manager/api.php','me');
        cookieCheck('authentication and CSRF survive navigation to '.$path,($me['json']['manager_id']??0)===7&&($me['json']['csrf']??'')===$csrf);
        if(str_starts_with($path,'/manager2')){
            cookieCheck('alternate HTML does not replace the Manager cookie '.$path,$page['set_cookie']===[]&&$cookies===$sessionCookies);
            cookieCheck('alternate shell contains no private identity or CSRF '.$path,!str_contains($page['body'],$csrf));
        }
        if($admin)cookieCheck('canonical protected admin read works after '.$path,requestCookieFixture('/manager/api.php','admin_snapshot',['csrf'=>$csrf])['status']===200);
    }
    foreach(['','wrong-csrf'] as $token)cookieCheck('admin read rejects '.($token===''?'missing':'incorrect').' CSRF',requestCookieFixture('/manager/api.php','admin_snapshot',['csrf'=>$token])['status']===403);
    requestCookieFixture('/manager/api.php','fixture_login',['manager_id'=>8]);
    $page=requestCookieFixture('/manager2/admin.php');
    cookieCheck('non-admin HTML navigation does not replace canonical cookie',$page['set_cookie']===[]&&$cookies===$sessionCookies);
    $denied=requestCookieFixture('/manager/api.php','admin_snapshot',['csrf'=>$csrf]);
    cookieCheck('non-admin protected read remains forbidden after alternate navigation',$denied['status']===403&&($denied['json']['error']??'')==='forbidden');
    $cookies=[];
    $anonymousRedirect=requestCookieFixture('/manager/');
    cookieCheck('anonymous legacy navigation redirects without minting a session',$anonymousRedirect['status']===302&&$anonymousRedirect['set_cookie']===[]&&$cookies===[]);
    foreach(['/manager2/','/manager2/admin.php'] as $path){
        $cookies=[];$page=requestCookieFixture($path);
        cookieCheck('anonymous alternate HTML does not mint a Manager session '.$path,$page['set_cookie']===[]&&$cookies===[]);
        foreach(['me','admin_snapshot'] as $action){
            $response=requestCookieFixture('/manager/api.php',$action,['csrf'=>$csrf]);
            cookieCheck('anonymous canonical '.$action.' remains unauthorized after '.$path,$response['status']===401&&($response['json']['error']??'')==='unauthorized');
        }
    }
}finally{
    proc_terminate($server);fclose($pipes[0]);proc_close($server);
    foreach(glob($tmp.'/sessions/*') as $file)unlink($file);
    rmdir($tmp.'/sessions');unlink($router);@unlink($tmp.'/server.log');rmdir($tmp);
}
echo 'MANAGER2 HTTP COOKIE CHECKS: '.($passed+$failed).' | PASS '.$passed.' | FAIL '.$failed."\n";
exit($failed?1:0);
