<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once __DIR__.'/support/manager-request-profile-http.php';
$directory=sys_get_temp_dir().'/manager-request-profile-'.bin2hex(random_bytes(8));mkdir($directory,0700);$fixture=managerRequestProfileHttpFixture($directory);
$listener=stream_socket_server('tcp://127.0.0.1:0',$errno,$error);if(!$listener)throw new RuntimeException('fixture_listener_failed');$address=stream_socket_get_name($listener,false);fclose($listener);
$log=fopen($directory.'/server.log','w');$process=proc_open([PHP_BINARY,'-d','session.save_path='.$fixture['sessions'],'-S',$address,'-t',$fixture['webroot']],[0=>['pipe','r'],1=>$log,2=>$log],$pipes);
if(!is_resource($process))throw new RuntimeException('fixture_server_failed');
$passed=0;
function profileHttpCheck(string $name,bool $ok):void{global$passed;if(!$ok)throw new RuntimeException('FAIL '.$name);++$passed;echo 'PASS '.$name."\n";}
function profileHttpRequest(string $address,array $fixture,?int $actor,array $input,string $method='POST'):array
{
    $headers=['Content-Type: application/json'];if($actor!==null)$headers[]='Cookie: anytour_manager_panel='.$fixture['session_ids'][(string)$actor];
    $context=stream_context_create(['http'=>['method'=>$method,'header'=>implode("\r\n",$headers),'content'=>json_encode($input),'ignore_errors'=>true,'timeout'=>5]]);
    $body=file_get_contents('http://'.$address.'/manager/request-profile-api.php',false,$context);$status=0;foreach($http_response_header??[] as $header)if(preg_match('/^HTTP\/\S+ (\d+)/',$header,$m))$status=(int)$m[1];
    return[$status,json_decode((string)$body,true),$body];
}
try{
    for($i=0;$i<100;$i++){if($socket=@stream_socket_client('tcp://'.$address,$e,$s,.05)){fclose($socket);break;}usleep(20000);}
    [$status]=profileHttpRequest($address,$fixture,null,[],'GET');profileHttpCheck('POST only',$status===405);
    [$status,$r,$body]=profileHttpRequest($address,$fixture,null,['action'=>'get']);profileHttpCheck('anonymous private read denied',$status===401&&!str_contains($body,'Даты подходят'));
    [$status]=profileHttpRequest($address,$fixture,4,['action'=>'identity']);profileHttpCheck('inactive session denied',$status===401);
    [$status,$identity]=profileHttpRequest($address,$fixture,1,['action'=>'identity']);profileHttpCheck('canonical identity and CSRF',$status===200&&$identity['viewer_id']===1&&$identity['csrf']==='fixture-profile-csrf');
    $get=['action'=>'get','csrf'=>$identity['csrf'],'conversation_id'=>1];
    [$status]=profileHttpRequest($address,$fixture,1,array_replace($get,['csrf'=>'wrong']));profileHttpCheck('read requires CSRF',$status===403);
    [$status]=profileHttpRequest($address,$fixture,1,array_replace($get,['conversation_id'=>'1']));profileHttpCheck('strict numeric conversation',$status===422);
    [$status,$r,$body]=profileHttpRequest($address,$fixture,3,$get);profileHttpCheck('foreign owner cannot read project',$status===403&&!str_contains($body,'Даты подходят'));
    [$status,$view]=profileHttpRequest($address,$fixture,1,$get);profileHttpCheck('actual API returns empty profile and sources',$status===200&&$view['profile']['version']===0&&count($view['sources'])===2);
    $save=requestProfileCommand($view)+['action'=>'save','csrf'=>$identity['csrf'],'conversation_id'=>1];
    [$status,$r]=profileHttpRequest($address,$fixture,1,$save);profileHttpCheck('actual API persists profile',$status===200&&$r['saved_version']===1&&!$r['replayed']);
    [$status,$r]=profileHttpRequest($address,$fixture,1,$save);profileHttpCheck('transport replay is idempotent',$status===200&&$r['saved_version']===1&&$r['replayed']);
    [$status,$r]=profileHttpRequest($address,$fixture,1,['action'=>'operation','csrf'=>$identity['csrf'],'conversation_id'=>1,'operation_id'=>$save['operation_id']]);profileHttpCheck('lost acknowledgement can be reconciled',$status===200&&$r['found']&&$r['saved_version']===1);
    [$status,$view]=profileHttpRequest($address,$fixture,1,$get);profileHttpCheck('readback contains exact date provenance',$status===200&&$view['profile']['fields']['dates']['citation']['message_id']===10&&$view['profile']['status']==='fresh');
    $db=new PDO('sqlite:'.$fixture['database'],null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
    requestProfileMessage($db,12,'customer','На те же даты только на 3-х, 2 взрослых и один ребёнок 12 лет');
    $stale=requestProfileCommand($view)+['action'=>'save','csrf'=>$identity['csrf'],'conversation_id'=>1];
    [$status,$r]=profileHttpRequest($address,$fixture,1,$stale);profileHttpCheck('new message prevents stale confirmation',$status===409&&$r['error']==='context_changed');
    [$status,$view]=profileHttpRequest($address,$fixture,1,$get);profileHttpCheck('latest party correction is bounded candidate',$status===200&&$view['profile']['status']==='needs_review'&&$view['party_suggestion']['value']['child_ages']===[12]);
    $command=requestProfileCommand($view);$s=$view['party_suggestion'];$command['fields']['party']=['value'=>$s['value'],'kind'=>'customer_statement','importance'=>'hard','note'=>'','source_id'=>$s['source_id'],'quote'=>$s['quote']];
    [$status,$r]=profileHttpRequest($address,$fixture,1,$command+['action'=>'save','csrf'=>$identity['csrf'],'conversation_id'=>1]);profileHttpCheck('explicit correction saves v2',$status===200&&$r['saved_version']===2);
    $db->exec('DELETE FROM manager_projects WHERE manager_id=1');
    [$status,$r,$body]=profileHttpRequest($address,$fixture,1,$get);profileHttpCheck('revoked project blocks later private read',$status===403&&!str_contains($body,'ребёнок'));
    [$status]=profileHttpRequest($address,$fixture,1,$save);profileHttpCheck('revoked project blocks idempotent replay',$status===403);
    profileHttpCheck('profile endpoints never send or advance read markers',(int)$db->query('SELECT COUNT(*) FROM messages')->fetchColumn()===3&&(int)$db->query('SELECT last_read_message_id FROM manager_conversation_reads WHERE manager_id=1')->fetchColumn()===10);
    echo 'TOTAL '.$passed.' | PASS '.$passed.' | FAIL 0'."\n";
}catch(Throwable $error){fwrite(STDERR,substr((string)file_get_contents($directory.'/server.log'),-5000));throw$error;}finally{
    proc_terminate($process);proc_close($process);fclose($log);
    $files=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);
    foreach($files as $file){if($file->isLink()||!$file->isDir())unlink($file->getPathname());else rmdir($file->getPathname());}rmdir($directory);
}
