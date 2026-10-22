<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
define('MANAGER_PREPARED_FIXTURE_ONLY',true);
require dirname(__DIR__).'/run_manager_prepared_reply_regression.php';
require __DIR__.'/manager-supervision-regression.php';
$root=dirname(__DIR__,2);$tmp=sys_get_temp_dir().'/manager-supervisor-http-'.bin2hex(random_bytes(8));$server=null;
$check=static function(string $name,bool $ok): void {if(!$ok)throw new RuntimeException('FAIL supervisor HTTP '.$name);echo 'PASS supervisor HTTP '.$name."\n";};
try{
    mkdir($tmp,0700);mkdir($tmp.'/manager',0700);mkdir($tmp.'/manager/lib',0700);mkdir($tmp.'/services',0700);mkdir($tmp.'/sessions',0700);
    $p=managerSupervisorFixture();$p->guard=false;$db=$tmp.'/fixture.sqlite';$p->exec("VACUUM INTO ".$p->quote($db));
    foreach(['manager/supervision-api.php','manager/lib/ManagerHttp.php','services/ManagerRequestContext.php'] as $path)copy($root.'/'.$path,$tmp.'/'.$path);
    $config='<?php define("MANAGER_PREPARED_FIXTURE_ONLY",true);require '.var_export(dirname(__DIR__).'/run_manager_prepared_reply_regression.php',true).';ProjectConfig::resetForTests(["id"=>"anytour","brand"=>["name"=>"Synthetic project"]]);$p=new PDO('.var_export('sqlite:'.$db,true).',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_STRINGIFY_FETCHES=>false]);(new ReflectionProperty(ConversationDb::class,"pdo"))->setValue(null,$p);';
    file_put_contents($tmp.'/config.php',$config);
    foreach(['ManagerAuthService','ManagerConversationAccessPolicy','ManagerSupervisorAccessService','ManagerDialogueAnalyticsService'] as $name)file_put_contents($tmp.'/services/'.$name.'.php','<?php require_once '.var_export($root.'/services/'.$name.'.php',true).';');
    file_put_contents($tmp.'/manager/session.php','<?php require dirname(__DIR__)."/config.php";require __DIR__."/lib/ManagerHttp.php";ManagerHttp::startJson();$_SESSION["manager_id"]=(int)($_GET["id"]??0);$_SESSION["csrf"]="synthetic-csrf";ManagerHttp::respond(["ok"=>true]);');
    $sock=stream_socket_server('tcp://127.0.0.1:0');$address=stream_socket_get_name($sock,false);fclose($sock);
    $server=proc_open([PHP_BINARY,'-d','session.save_path='.$tmp.'/sessions','-S',$address,'-t',$tmp],[0=>['pipe','r'],1=>['file',$tmp.'/server.log','a'],2=>['file',$tmp.'/server.log','a']],$pipes);fclose($pipes[0]);
    for($i=0;$i<100;$i++){if($s=@stream_socket_client('tcp://'.$address,$en,$er,.05)){fclose($s);break;}usleep(10000);}
    $request=static function(string $path,array $data=[],string $cookie='',string $method='POST')use($address):array{
        $headers="Content-Type: application/json\r\n".($cookie!==''?"Cookie: $cookie\r\n":'');
        $context=stream_context_create(['http'=>['method'=>$method,'header'=>$headers,'content'=>json_encode($data),'ignore_errors'=>true,'timeout'=>10]]);
        $body=file_get_contents('http://'.$address.$path,false,$context);$status=(int)explode(' ',$http_response_header[0])[1];$new='';foreach($http_response_header as $header)if(preg_match('/^Set-Cookie: ([^;]+)/i',$header,$m))$new=$m[1];
        return ['status'=>$status,'json'=>json_decode((string)$body,true),'cookie'=>$new];
    };
    $login=static fn(int $id)=>$request('/manager/session.php?id='.$id,[],'','GET')['cookie'];
    $call=static fn(array $d,string $cookie)=>$request('/manager/supervision-api.php',$d,$cookie);
    $admin=$login(2);$viewer=$login(4);$colleague=$login(5);$base=['csrf'=>'synthetic-csrf'];$grant=$base+['action'=>'set','manager_id'=>4,'project_id'=>1,'source_ids'=>[11],'include_unsourced'=>false,'enabled'=>true,'expected_version'=>0];
    $check('anonymous refused',$call($base+['action'=>'analytics'],'')['status']===401);
    $check('GET has no operation',$request('/manager/supervision-api.php',[],$admin,'GET')['status']===405);
    $check('CSRF required for explicit grant',$call(array_replace($grant,['csrf'=>'wrong']),$admin)['status']===403);
    $check('ordinary colleague cannot grant',$call($grant,$colleague)['status']===403);
    $check('ordinary analytics refused',$call($base+['action'=>'analytics','from'=>'2026-10-22','to'=>'2026-10-22'],$colleague)['status']===403);
    $r=$call($grant,$admin);$check('actual canonical audited command saves',$r['status']===200&&$r['json']['version']===1);
    $r=$call($base+['action'=>'directory'],$admin);$g=array_values(array_filter($r['json']['grants'],static fn(array $g)=>$g['manager_id']==4))[0]??null;$check('privileged readback proves exact target/source',$g&&$g['version']==1&&$g['source_ids']===[11]);
    $r=$call($base+['action'=>'scope'],$viewer);$check('viewer has only explicit project/source',$r['status']===200&&count($r['json']['scopes'])===1&&array_column($r['json']['scopes'][0]['sources'],'id')===[11]);
    $check('viewer cannot enumerate admin directory',$call($base+['action'=>'directory'],$viewer)['status']===403);
    $r=$call($base+['action'=>'analytics','from'=>'2026-10-19','to'=>'2026-10-22'],$viewer);$check('actual endpoint returns read model',$r['status']===200&&$r['json']['totals']['conversations']===1);
    $check('stale command does not replay',$call($grant,$admin)['status']===409);
    $check('strict false is not string false',$call(array_replace($grant,['enabled'=>'false']),$admin)['status']===422);
    $r=$call(array_replace($grant,['enabled'=>false,'expected_version'=>1]),$admin);$check('explicit revoke succeeds',$r['status']===200&&$r['json']['version']===2);
    $check('revoked viewer denied immediately',$call($base+['action'=>'analytics','from'=>'2026-10-19','to'=>'2026-10-22'],$viewer)['status']===403);
    $p=new PDO('sqlite:'.$db);$check('reads/grants never mutate assignment/read/messages',(int)$p->query('SELECT manager_id FROM conversations WHERE id=101')->fetchColumn()===1&&(int)$p->query('SELECT COUNT(*) FROM messages')->fetchColumn()===2&&(int)$p->query('SELECT COUNT(*) FROM manager_conversation_reads')->fetchColumn()===1&&(int)$p->query('SELECT COUNT(*) FROM admin_audit_log')->fetchColumn()===2);
}finally{
    if(is_resource($server)){proc_terminate($server);proc_close($server);}
    $remove=static function(string $p)use(&$remove):void{if(is_file($p)){unlink($p);return;}if(is_dir($p)){foreach(scandir($p) as $n)if($n!=='.'&&$n!=='..')$remove($p.'/'.$n);rmdir($p);}};$remove($tmp);
}
