<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli')exit;
ob_start();
require_once __DIR__.'/fixtures/client_onboarding_fixture.php';
$root=dirname(__DIR__);$directory=(realpath(sys_get_temp_dir())?:sys_get_temp_dir()).'/client-http-'.bin2hex(random_bytes(6));mkdir($directory,0700);$server=null;$passed=0;
function clientHttpCheck(string $label,bool $ok): void {global$passed;if(!$ok)throw new RuntimeException('FAIL '.$label);$passed++;echo 'PASS '.$label."\n";}
try{
    mkdir($directory.'/manager',0700);mkdir($directory.'/sessions',0700);touch($directory.'/synthetic-client-fixture');
    foreach(['services','integrations'] as $area)symlink($root.'/'.$area,$directory.'/'.$area);symlink($root.'/manager/lib',$directory.'/manager/lib');
    copy($root.'/manager/clients-api.php',$directory.'/manager/clients-api.php');file_put_contents($directory.'/config.php',"<?php require_once ".var_export(__DIR__.'/fixtures/client_onboarding_http.php',true).";clientHttpBootstrap(__DIR__);");
    $pdo=new ClientFixturePdo('sqlite:'.$directory.'/fixture.sqlite');$registry=clientFixtureBind($pdo,$directory.'/private');clientFixtureSchema($pdo);
    $pdo->prepare('UPDATE projects SET display_name=? WHERE id=1')->execute([ProjectConfig::get('brand.name',ProjectConfig::projectId())]);
    $sessions=[];session_name('anytour_manager_panel');session_save_path($directory.'/sessions');
    foreach([1,2,3] as $actor){$sid=bin2hex(random_bytes(16));session_id($sid);session_start();$_SESSION=['manager_id'=>$actor,'csrf'=>'synthetic-client-csrf'];session_write_close();$sessions[$actor]=$sid;}
    $socket=stream_socket_server('tcp://127.0.0.1:0',$errno,$error);if(!$socket)throw new RuntimeException('Cannot allocate loopback');$address=stream_socket_get_name($socket,false);fclose($socket);
    $server=proc_open([PHP_BINARY,'-d','session.save_path='.$directory.'/sessions','-S',$address,'-t',$directory],[0=>['pipe','r'],1=>['file',$directory.'/server.log','a'],2=>['file',$directory.'/server.log','a']],$pipes);if(!is_resource($server))throw new RuntimeException('Cannot start loopback');fclose($pipes[0]);
    $ready=false;for($i=0;$i<100;$i++){if($probe=@stream_socket_client('tcp://'.$address,$e,$s,.05)){fclose($probe);$ready=true;break;}usleep(10000);}if(!$ready)throw new RuntimeException('Loopback not ready');
    $request=static function(array $data,?int $actor=1,string $method='POST')use($address,&$sessions):array{
        $headers=['Content-Type: application/json'];if($actor!==null)$headers[]='Cookie: anytour_manager_panel='.$sessions[$actor];
        $response=file_get_contents('http://'.$address.'/manager/clients-api.php',false,stream_context_create(['http'=>['method'=>$method,'header'=>implode("\r\n",$headers),'content'=>json_encode($data,JSON_THROW_ON_ERROR),'ignore_errors'=>true,'timeout'=>5]]));$status=0;foreach($http_response_header??[] as $header)if(preg_match('/^HTTP\/\S+ (\d+)/',$header,$m))$status=(int)$m[1];return ['status'=>$status,'body'=>(string)$response,'json'=>json_decode((string)$response,true)];
    };
    $base=['expected_actor'=>1,'csrf'=>'synthetic-client-csrf'];$create=$base+['action'=>'create','client'=>clientFixtureInput()];
    clientHttpCheck('HTTP source byte exact',hash_file('sha256',$root.'/manager/clients-api.php')===hash_file('sha256',$directory.'/manager/clients-api.php'));
    clientHttpCheck('real endpoint rejects anonymous session',$request($create,null)['status']===401);
    clientHttpCheck('real endpoint requires POST',$request($base+['action'=>'list'],1,'GET')['status']===405);
    clientHttpCheck('real endpoint requires CSRF',$request(array_replace($create,['csrf'=>'wrong']))['status']===403);
    clientHttpCheck('same CSRF cannot cross actor',$request(array_replace($create,['expected_actor'=>3]))['json']['error']==='identity_changed');
    clientHttpCheck('ordinary employee cannot provision',$request(array_replace($create,['expected_actor'=>2]),2)['status']===403);
    clientHttpCheck('operation ID arrays rejected without output warnings',$request($base+['action'=>'receipt','operation_id'=>[]])['json']['error']==='invalid_operation');
    $r=$request($create);clientHttpCheck('actual endpoint creates one scoped company',$r['status']===200&&$r['json']['state']==='created');$created=$r['json'];$id=$created['project_id'];
    clientHttpCheck('HTTP create replay returns original receipt',$request($create)['json']===$created);
    touch($directory.'/read-only');$r=$request($base+['action'=>'receipt','operation_id'=>str_repeat('a',32)]);clientHttpCheck('query-only receipt restores exact result',$r['json']===$created);
    $r=$request($base+['action'=>'list']);clientHttpCheck('list cannot reveal plaintext connection keys',$r['status']===200&&!str_contains($r['body'],'synthetic-private-token')&&!str_contains($r['body'],'credentials_json'));
    clientHttpCheck('existing employee cannot read foreign materials',$request(['expected_actor'=>2,'csrf'=>'synthetic-client-csrf','action'=>'detail','project_id'=>$id],2)['status']===404);
    unlink($directory.'/read-only');
    $employee=$created['manager_ids'][0];$sid=bin2hex(random_bytes(16));session_id($sid);session_start();$_SESSION=['manager_id'=>$employee,'csrf'=>'synthetic-client-csrf'];session_write_close();$sessions[$employee]=$sid;touch($directory.'/read-only');
    $r=$request(['expected_actor'=>$employee,'csrf'=>'synthetic-client-csrf','action'=>'list'],$employee);clientHttpCheck('new staff real session sees only own company',count($r['json']['clients'])===1&&$r['json']['clients'][0]['id']===$id);
    $r=$request(['expected_actor'=>$employee,'csrf'=>'synthetic-client-csrf','action'=>'detail','project_id'=>$id],$employee);clientHttpCheck('own materials readable without database writes',$r['status']===200&&$r['json']['client']['materials']===clientFixtureInput()['materials']);unlink($directory.'/read-only');
    $pdo->exec('DELETE FROM manager_projects WHERE manager_id='.$employee);touch($directory.'/read-only');$r=$request(['expected_actor'=>$employee,'csrf'=>'synthetic-client-csrf','action'=>'detail','project_id'=>$id],$employee);clientHttpCheck('fresh membership revocation removes materials access',$r['status']===404);unlink($directory.'/read-only');
    $pdo->exec('UPDATE managers SET is_active=0 WHERE id='.$employee);clientHttpCheck('deactivated account loses actual session access',$request(['action'=>'me'],$employee)['status']===401);
    echo 'TOTAL '.$passed.' | PASS '.$passed." | FAIL 0\n";
}finally{
    if(is_resource($server)){proc_terminate($server);proc_close($server);}
    foreach(['services','integrations','manager/lib'] as $link)if(is_link($directory.'/'.$link))unlink($directory.'/'.$link);
    if(is_dir($directory)){ $iterator=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);foreach($iterator as $file){if($file->isDir())rmdir($file->getPathname());else unlink($file->getPathname());}rmdir($directory);}
}
