<?php
declare(strict_types=1);
if (PHP_SAPI!=='cli') {http_response_code(404);exit;}

// Real canonical ManagerHttp/session/auth/CSRF and production endpoints on an
// isolated child PHP HTTP server. Synthetic push URLs are never contacted.
require_once __DIR__.'/fixtures/manager_push_subscription_fixture.php';
$root=dirname(__DIR__);$tmp=sys_get_temp_dir().'/manager-push-proof-'.bin2hex(random_bytes(6));
mkdir($tmp,0700);mkdir($tmp.'/manager',0700);mkdir($tmp.'/sessions',0700);
$process=null;$db=null;$passed=0;
function pushHttpCheck(string $name,bool $ok): void
{
    global $passed;
    if (!$ok) throw new RuntimeException('FAIL '.$name);
    $passed++;echo 'PASS '.$name."\n";
}
try {
    $db=new PDO('sqlite:'.$tmp.'/fixture.sqlite',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
    managerPushSubscriptionFixtureSeed($db);
    $before=$db->query('SELECT * FROM manager_push_subscriptions ORDER BY id')->fetchAll();
    $managerBefore=$db->query('SELECT * FROM managers ORDER BY id')->fetchAll();
    $projectBefore=$db->query('SELECT * FROM manager_projects ORDER BY manager_id')->fetchAll();
    file_put_contents($tmp.'/config.php',"<?php\nrequire_once ".var_export($root.'/services/ManagerRequestContext.php',true).";\nrequire_once ".var_export(__DIR__.'/fixtures/manager_push_subscription_fixture.php',true).";\n".
        '$fixturePdo=new ManagerPushSubscriptionHttpFixturePDO('.var_export('sqlite:'.$tmp.'/fixture.sqlite',true).');'."\n".
        'managerPushSubscriptionFixture($fixturePdo);'."\n".
        '(new ReflectionProperty(ConversationDb::class,"pdo"))->setValue(null,$fixturePdo);'."\n".
        'ProjectConfig::resetForTests(["id"=>"push-fixture","brand"=>["name"=>"Push fixture"]]);'."\n".
        'ProjectAccessService::initializeReadOnly();'."\n".
        'if (str_contains((string)($_SERVER["SCRIPT_NAME"]??""),"push-status.php")) $fixturePdo->exec("PRAGMA query_only=ON");'."\n");
    foreach (['push-status.php','push.php'] as $entry) copy($root.'/manager/'.$entry,$tmp.'/manager/'.$entry);
    symlink($root.'/manager/lib',$tmp.'/manager/lib');
    mkdir($tmp.'/services',0700);
    foreach (glob($root.'/services/*')?:[] as $service) {
        $target=$tmp.'/services/'.basename($service);
        if (basename($service)==='ManagerPushService.php') copy($service,$target);
        else symlink($service,$target);
    }
    file_put_contents($tmp.'/manager/fixture-session.php','<?php require dirname(__DIR__)."/config.php";require __DIR__."/lib/ManagerHttp.php";ManagerHttp::startJson();$_SESSION["manager_id"]=(int)($_GET["id"]??7);$_SESSION["csrf"]="fixture-csrf-only";ManagerHttp::respond(["ok"=>true]);');
    $socket=stream_socket_server('tcp://127.0.0.1:0',$errno,$error);if (!$socket) throw new RuntimeException('Cannot allocate push-proof loopback fixture');
    $address=(string)stream_socket_get_name($socket,false);fclose($socket);
    $process=proc_open([PHP_BINARY,'-d','session.save_path='.$tmp.'/sessions','-S',$address,'-t',$tmp],[0=>['pipe','r'],1=>['file',$tmp.'/server.log','a'],2=>['file',$tmp.'/server.log','a']],$pipes);
    if (!is_resource($process)) throw new RuntimeException('Cannot start push-proof HTTP fixture');fclose($pipes[0]);
    $ready=false;for ($i=0;$i<100;$i++) {if ($probe=@stream_socket_client('tcp://'.$address,$e,$s,.05)) {fclose($probe);$ready=true;break;}usleep(10000);}
    if (!$ready) throw new RuntimeException('Push-proof HTTP fixture did not start');
    $request=static function(string $path,array $data=[],string $cookie='',string $method='POST') use ($address): array {
        $headers=['Content-Type: application/json'];if ($cookie!=='') $headers[]='Cookie: '.$cookie;
        $ctx=stream_context_create(['http'=>['method'=>$method,'header'=>implode("\r\n",$headers),'content'=>$method==='POST'?json_encode($data):'','ignore_errors'=>true,'timeout'=>3]]);
        $body=(string)file_get_contents('http://'.$address.$path,false,$ctx);$responseHeaders=$http_response_header??[];
        preg_match('/^HTTP\/[^ ]+ ([0-9]+)/',(string)($responseHeaders[0]??''),$status);$responseCookie='';
        foreach ($responseHeaders as $header) if (preg_match('/^Set-Cookie: (anytour_manager_panel=[^;]+)/i',$header,$m)) $responseCookie=$m[1];
        return ['status'=>(int)($status[1]??0),'json'=>json_decode($body,true),'body'=>$body,'cookie'=>$responseCookie,'headers'=>$responseHeaders];
    };
    $endpointA='https://push-fixture.invalid/subscription-a';$endpointB='https://push-fixture.invalid/subscription-b';$endpointC='https://push-fixture.invalid/subscription-c';
    $healthy=['notification_path_usable'=>true,'notification_path_reason'=>'healthy_subscription'];$unhealthy=['notification_path_usable'=>false,'notification_path_reason'=>'subscription_unhealthy'];$missing=['notification_path_usable'=>false,'notification_path_reason'=>'no_subscription'];
    foreach (['GET','POST'] as $method) {
        $anonymous=$request('/manager/push-status.php',['endpoint'=>$endpointA,'csrf'=>'fixture-csrf-only'],'',$method);
        pushHttpCheck('anonymous '.$method.' cannot expose status or current device',$anonymous['status']===401&&$anonymous['json']===['ok'=>false,'error'=>'unauthorized']);
    }
    $cookie=$request('/manager/fixture-session.php?id=7',[],'','GET')['cookie'];pushHttpCheck('fixture uses real canonical Manager session cookie',$cookie!=='');
    $get=$request('/manager/push-status.php',[],$cookie,'GET');
    pushHttpCheck('legacy GET retains aggregate-only JSON contract',$get['status']===200&&array_keys($get['json'])===['ok','push_status']&&$get['json']['push_status']['notification_path_usable']===true&&$get['json']['push_status']['subscription_count']===2);
    $query=$request('/manager/push-status.php?endpoint='.rawurlencode($endpointB).'&manager_id=8',[],$cookie,'GET');
    pushHttpCheck('GET ignores endpoint and alternate manager query parameters',$query['json']===$get['json']);
    foreach (['','invalid'] as $csrf) {
        $denied=$request('/manager/push-status.php',['csrf'=>$csrf,'endpoint'=>$endpointB],$cookie);
        pushHttpCheck('current proof rejects '.($csrf===''?'missing':'invalid').' CSRF before device lookup',$denied['status']===403&&$denied['json']===['ok'=>false,'error'=>'csrf']);
    }
    foreach ([[],['endpoint'=>''],['endpoint'=>'  '],['endpoint'=>[]],['endpoint'=>false],['endpoint'=>null],['endpoint'=>7]] as $bad) {
        $invalid=$request('/manager/push-status.php',['csrf'=>'fixture-csrf-only']+$bad,$cookie);
        pushHttpCheck('invalid current endpoint is 422 '.json_encode($bad),$invalid['status']===422&&$invalid['json']===['ok'=>false,'error'=>'invalid_endpoint']);
    }
    $proof=static fn(string $endpoint,string $session,array $extra=[]): array=>$request('/manager/push-status.php',array_replace(['csrf'=>'fixture-csrf-only','endpoint'=>$endpoint],$extra),$session);
    $valid=$proof($endpointA,$cookie);
    pushHttpCheck('POST confirms exact healthy browser alongside unchanged aggregate',$valid['status']===200&&array_keys($valid['json'])===['ok','push_status','current_subscription']&&$valid['json']['current_subscription']===$healthy&&$valid['json']['push_status']===$get['json']['push_status']);
    $badDevice=$proof($endpointB,$cookie,['manager_id'=>8]);
    pushHttpCheck('unhealthy exact browser cannot borrow healthy other device or posted account',$badDevice['status']===200&&$badDevice['json']['current_subscription']===$unhealthy&&$badDevice['json']['push_status']===$get['json']['push_status']);
    $otherOnly=$proof($endpointC,$cookie);
    pushHttpCheck('other-account-only endpoint is absent for authenticated manager',$otherOnly['status']===200&&$otherOnly['json']['current_subscription']===$missing);
    pushHttpCheck('endpoint whitespace uses same normalized hash as subscription saver',$proof('  '.$endpointA.'  ',$cookie)['json']['current_subscription']===$healthy);
    $cookie8=$request('/manager/fixture-session.php?id=8',[],'','GET')['cookie'];
    pushHttpCheck('same endpoint has independently healthy proof for its actual account',$proof($endpointB,$cookie8)['json']['current_subscription']===$healthy);
    foreach ([$valid,$badDevice,$otherOnly] as $response) {
        $current=$response['json']['current_subscription'];$json=json_encode($current);
        pushHttpCheck('current proof contains no endpoint hash keys timestamps or provider secrets',array_keys($current)===['notification_path_usable','notification_path_reason']&&!preg_match('/private-|https:|endpoint|last_error|last_success|p256dh|auth_secret/',$json));
        pushHttpCheck('current proof response retains no-store JSON headers',count(array_filter($response['headers'],static fn(string $h): bool=>strtolower($h)==='cache-control: no-store'))===1&&count(array_filter($response['headers'],static fn(string $h): bool=>str_starts_with(strtolower($h),'content-type: application/json')))===1);
    }
    pushHttpCheck('all status HTTP cases are read-only across every account',$db->query('SELECT * FROM manager_push_subscriptions ORDER BY id')->fetchAll()===$before);
    $subscription=['endpoint'=>$endpointB,'keys'=>['p256dh'=>'private-p256dh-7-b','auth'=>'private-auth-7-b']];
    $anonymous=$request('/manager/push.php',['action'=>'subscribe','csrf'=>'fixture-csrf-only','subscription'=>$subscription]);
    pushHttpCheck('anonymous subscribe cannot save or return proof',$anonymous['status']===401&&$anonymous['json']===['ok'=>false,'error'=>'unauthorized']);
    $forbidden=$request('/manager/push.php',['action'=>'subscribe','csrf'=>'bad','subscription'=>$subscription],$cookie);
    pushHttpCheck('subscribe still checks CSRF before saving or proof',$forbidden['status']===403&&$forbidden['json']===['ok'=>false,'error'=>'csrf']&&$db->query('SELECT * FROM manager_push_subscriptions ORDER BY id')->fetchAll()===$before);
    $saved=$request('/manager/push.php',['action'=>'subscribe','csrf'=>'fixture-csrf-only','subscription'=>$subscription,'manager_id'=>8],$cookie);
    pushHttpCheck('unchanged unhealthy subscribe response proves saved exact row rather than aggregate',$saved['status']===200&&array_keys($saved['json'])===['ok','push_status','current_subscription']&&$saved['json']['ok']===true&&$saved['json']['push_status']['notification_path_usable']===true&&$saved['json']['current_subscription']===$unhealthy);
    pushHttpCheck('subscribe current proof does not expose submitted secrets or endpoint',!str_contains($saved['body'],'private-p256dh')&&!str_contains($saved['body'],'private-auth')&&!str_contains($saved['body'],$endpointB));
    $subscription['endpoint']=$endpointC;$subscription['keys']=['p256dh'=>'new-private-key','auth'=>'new-private-auth'];
    $new=$request('/manager/push.php',['action'=>'subscribe','csrf'=>'fixture-csrf-only','subscription'=>$subscription],$cookie);
    pushHttpCheck('new saved device is independently confirmed in subscribe response',$new['status']===200&&$new['json']['current_subscription']===$healthy&&$new['json']['push_status']['subscription_count']===3);
    $foreignRows=array_values(array_filter($db->query('SELECT * FROM manager_push_subscriptions ORDER BY id')->fetchAll(),static fn(array $r): bool=>(int)$r['manager_id']===8));
    pushHttpCheck('subscribe never changes matching endpoint rows belonging to another account',$foreignRows===array_values(array_filter($before,static fn(array $r): bool=>(int)$r['manager_id']===8)));
    $invalidSubscribe=$request('/manager/push.php',['action'=>'subscribe','csrf'=>'fixture-csrf-only','subscription'=>[]],$cookie);
    pushHttpCheck('invalid subscribe preserves 409 contract without fabricated proof',$invalidSubscribe['status']===409&&$invalidSubscribe['json']===['ok'=>false]);
    $db->exec('UPDATE managers SET is_active=0 WHERE id=7');$inactive=$proof($endpointA,$cookie);$db->exec('UPDATE managers SET is_active=1 WHERE id=7');
    pushHttpCheck('fresh auth rejects account deactivated after prior healthy read',$inactive['status']===401&&$inactive['json']===['ok'=>false,'error'=>'unauthorized']);
    pushHttpCheck('HTTP proof and subscribe never change manager shifts identities or access',$db->query('SELECT * FROM managers ORDER BY id')->fetchAll()===$managerBefore&&$db->query('SELECT * FROM manager_projects ORDER BY manager_id')->fetchAll()===$projectBefore);
    pushHttpCheck('status and subscribe do not generate VAPID configuration or send push',!is_dir($tmp.'/.runtime'));
} finally {
    if (is_resource($process)) {proc_terminate($process);proc_close($process);}
    $db=null;
    $remove=static function(string $path) use (&$remove): void {if (is_link($path)||is_file($path)) {unlink($path);return;}if (is_dir($path)) {foreach (scandir($path)?:[] as $entry) if ($entry!=='.'&&$entry!=='..') $remove($path.'/'.$entry);rmdir($path);}};
    $remove($tmp);
}
echo "TOTAL {$passed} passed\n";
