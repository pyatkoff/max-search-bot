<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once __DIR__.'/support/manager-promise-task-fixture.php';

// This is a real localhost HTTP/session test. A native PHP CLI with PDO SQLite
// is required; neither a mocked dispatcher nor a WASM-only run proves this gate.
if(!function_exists('proc_open')||!function_exists('stream_socket_server')||!is_file(PHP_BINARY))throw new RuntimeException('native_php_http_runtime_required');
$directory=sys_get_temp_dir().'/manager-request-profile-'.bin2hex(random_bytes(8));
if(!mkdir($directory,0700))throw new RuntimeException('fixture_directory_failed');
$process=null;$log=null;$passed=0;
function promiseHttpCheck(string $name,bool $ok,mixed $detail=null): void
{
    global $passed;
    if(!$ok)throw new RuntimeException('FAIL '.$name.($detail===null?'':' '.json_encode($detail,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)));
    ++$passed;echo 'PASS '.$name."\n";
}
function promiseHttpRequest(string $address,array $fixture,?int $actor,array $input,string $method='POST'): array
{
    $headers=['Content-Type: application/json'];
    if($actor!==null)$headers[]='Cookie: anytour_manager_panel='.$fixture['session_ids'][(string)$actor];
    $options=['method'=>$method,'header'=>implode("\r\n",$headers),'content'=>json_encode($input,JSON_THROW_ON_ERROR),'ignore_errors'=>true,'timeout'=>5];
    $body=file_get_contents('http://'.$address.'/manager/pipeline-api.php',false,stream_context_create(['http'=>$options]));
    $status=0;foreach($http_response_header??[] as $header)if(preg_match('/^HTTP\/\S+ (\d+)/',$header,$m))$status=(int)$m[1];
    return [$status,json_decode((string)$body,true),(string)$body];
}
try{
    $fixture=promiseTaskHttpFixture($directory);
    $listener=stream_socket_server('tcp://127.0.0.1:0',$errno,$error);
    if(!$listener)throw new RuntimeException('fixture_listener_failed');
    $address=stream_socket_get_name($listener,false);fclose($listener);
    $log=fopen($directory.'/server.log','w');
    $process=proc_open([PHP_BINARY,'-d','session.save_path='.$fixture['sessions'],'-S',$address,'-t',$fixture['webroot']],
        [0=>['pipe','r'],1=>$log,2=>$log],$pipes);
    if(!is_resource($process))throw new RuntimeException('fixture_server_failed');
    fclose($pipes[0]);$ready=false;
    for($i=0;$i<100;++$i){if($socket=@stream_socket_client('tcp://'.$address,$e,$s,.05)){fclose($socket);$ready=true;break;}usleep(20000);}
    if(!$ready)throw new RuntimeException('fixture_server_not_ready');
    $db=new PDO('sqlite:'.$fixture['database'],null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
    $before=promiseTaskCanonicalSnapshot($db);
    $get=['action'=>'promise_context','csrf'=>'fixture-profile-csrf','conversation_id'=>1,'task_id'=>101,'source_message_id'=>12,'kind'=>'send_calculation'];
    promiseHttpCheck('HTTP fixture executes the byte-exact current pipeline API',hash_file('sha256',$fixture['root'].'/manager/pipeline-api.php')===hash_file('sha256',$fixture['webroot'].'/manager/pipeline-api.php'));
    [$status]=promiseHttpRequest($address,$fixture,null,$get,'GET');promiseHttpCheck('promise action is POST-only',$status===405);
    [$status,$r,$body]=promiseHttpRequest($address,$fixture,null,$get);
    promiseHttpCheck('anonymous read cannot reveal promise or trip evidence',$status===401&&!str_contains($body,'Пришлю расчёт'),$r);
    [$status]=promiseHttpRequest($address,$fixture,4,$get);promiseHttpCheck('inactive session is not authorized',$status===401);
    [$status]=promiseHttpRequest($address,$fixture,1,array_replace($get,['csrf'=>'wrong']));promiseHttpCheck('private read requires current CSRF',$status===403);
    [$status,$r,$body]=promiseHttpRequest($address,$fixture,3,$get);
    promiseHttpCheck('foreign-project administrator cannot read private evidence',$status===403&&!str_contains($body,'Пришлю расчёт'),$r);
    foreach([['conversation_id'=>'1'],['task_id'=>'101'],['source_message_id'=>'12'],['kind'=>true],['extra'=>true]] as $changes){
        [$status,$r]=promiseHttpRequest($address,$fixture,1,array_replace($get,$changes));
        promiseHttpCheck('context rejects coercion or extra command members '.json_encode($changes),$status===422&&empty($r['ok']),$r);
    }
    [$status,$context]=promiseHttpRequest($address,$fixture,1,$get);
    promiseHttpCheck('actual HTTP context returns current actor, trip and native manager source',
        $status===200&&!empty($context['ok'])&&$context['viewer_id']===1&&$context['can_bind']===true
        &&$context['task']['id']===101&&$context['source']['id']===12&&$context['source']['author_manager_id']===1
        &&$context['profile']['version']===1,$context);
    $command=promiseTaskCommand($context,['deadline'=>['kind'=>'date','value'=>'2030-11-20','zone'=>'Europe/Moscow']]);
    $bind=$command+['action'=>'bind_promise','csrf'=>'fixture-profile-csrf','conversation_id'=>1];
    [$status]=promiseHttpRequest($address,$fixture,1,array_replace($bind,['csrf'=>'wrong']));promiseHttpCheck('bind also requires current CSRF',$status===403);
    [$status,$r]=promiseHttpRequest($address,$fixture,5,$bind);promiseHttpCheck('visible but unassigned manager cannot create a binding',$status===403,$r);
    [$status,$r]=promiseHttpRequest($address,$fixture,1,$bind+['title'=>'Unsupported task writer']);
    promiseHttpCheck('bind exposes no task-writer escape hatch',$status===422&&$r['error']==='invalid_binding',$r);
    promiseHttpCheck('rejected HTTP requests write neither tasks nor provenance',promiseTaskCounts($db)===[0,0]&&promiseTaskCanonicalSnapshot($db)===$before);
    [$status,$bound]=promiseHttpRequest($address,$fixture,1,$bind);
    promiseHttpCheck('actual HTTP bind links the existing task once',
        $status===200&&!empty($bound['ok'])&&$bound['id']===101&&$bound['replayed']===false&&$bound['reused']===false&&promiseTaskCounts($db)===[1,1],$bound);
    promiseHttpCheck('HTTP date-only binding preserves precision and every canonical business row',
        json_decode((string)$db->query('SELECT deadline_json FROM manager_task_promises')->fetchColumn(),true)===$command['deadline']
        &&promiseTaskCanonicalSnapshot($db)===$before);
    [$status,$replay]=promiseHttpRequest($address,$fixture,1,$bind);
    promiseHttpCheck('lost HTTP acknowledgement replays one receipt without duplication',
        $status===200&&$replay['replayed']===true&&$replay['promise_id']===$bound['promise_id']&&promiseTaskCounts($db)===[1,1],$replay);
    [$status,$r]=promiseHttpRequest($address,$fixture,1,array_replace($bind,['deadline'=>null]));
    promiseHttpCheck('same HTTP operation with another payload is a conflict',$status===409&&$r['error']==='operation_mismatch',$r);
    $operation=['action'=>'promise_operation','csrf'=>'fixture-profile-csrf','conversation_id'=>1,'operation_id'=>$command['operation_id']];
    [$status]=promiseHttpRequest($address,$fixture,1,array_replace($operation,['csrf'=>'wrong']));promiseHttpCheck('receipt lookup also requires CSRF',$status===403);
    [$status,$r]=promiseHttpRequest($address,$fixture,1,$operation);
    promiseHttpCheck('actual receipt endpoint reconciles the same task',$status===200&&$r['found']===true&&$r['promise_id']===$bound['promise_id']&&$r['task_id']===101,$r);
    [$status,$r]=promiseHttpRequest($address,$fixture,1,array_replace($operation,['operation_id'=>str_repeat('f',32)]));
    promiseHttpCheck('unknown operation is a read-only absence',$status===200&&$r['found']===false,$r);
    [$status,$r]=promiseHttpRequest($address,$fixture,2,$operation);promiseHttpCheck('another actor cannot borrow an operation id',$status===200&&$r['found']===false,$r);
    [$status,$r]=promiseHttpRequest($address,$fixture,1,$operation+['task_id'=>101]);promiseHttpCheck('receipt command rejects extra members',$status===422,$r);
    [$status,$r]=promiseHttpRequest($address,$fixture,1,array_replace($get,['task_id'=>201]));promiseHttpCheck('cross-conversation task maps to not-found',$status===404&&$r['error']==='task_not_found',$r);
    [$status,$r]=promiseHttpRequest($address,$fixture,1,array_replace($bind,['operation_id'=>bin2hex(random_bytes(16)),'task_id'=>102]));
    promiseHttpCheck('same promise cannot be redirected through HTTP',$status===409&&$r['error']==='binding_conflict'&&promiseTaskCounts($db)===[1,1],$r);
    promiseTaskManualEdit($db,"UPDATE lead_tasks SET title='Ручная окончательная правка',due_at_utc='2031-02-03 09:00:00',status='done',is_pinned=0,completed_at_utc='2031-02-03 08:00:00' WHERE id=101");
    requestProfileMessage($db,21,'customer','Спасибо');$afterManual=promiseTaskCanonicalSnapshot($db);
    [$status,$r]=promiseHttpRequest($address,$fixture,1,$bind);
    promiseHttpCheck('HTTP replay after completion and newer context returns current manual state',
        $status===200&&$r['replayed']===true&&$r['task']['status']==='done'&&$r['task']['title']==='Ручная окончательная правка',$r);
    [$status,$r]=promiseHttpRequest($address,$fixture,1,array_replace($bind,['operation_id'=>bin2hex(random_bytes(16))]));
    promiseHttpCheck('new operation cannot reuse stale HTTP context pins',$status===409&&$r['error']==='context_changed',$r);
    [$status,$fresh]=promiseHttpRequest($address,$fixture,1,$get);
    promiseHttpCheck('fresh context honestly exposes done task and needs-review trip',$status===200&&$fresh['can_bind']===false&&$fresh['task']['status']==='done'&&$fresh['profile']['status']==='needs_review',$fresh);
    $reuse=promiseTaskCommand($fresh,['deadline'=>$command['deadline']])+['action'=>'bind_promise','csrf'=>'fixture-profile-csrf','conversation_id'=>1];
    [$status,$r]=promiseHttpRequest($address,$fixture,1,$reuse);
    promiseHttpCheck('fresh explicit retry reuses completed binding without reopening',
        $status===200&&$r['reused']===true&&$r['replayed']===false&&$r['task']['status']==='done'&&promiseTaskCounts($db)===[1,2],$r);
    promiseHttpCheck('HTTP reconciliation never rewrites manual fields, sends a message, or advances read markers',promiseTaskCanonicalSnapshot($db)===$afterManual);
    $db->exec('DELETE FROM manager_projects WHERE manager_id=1');
    foreach(['context'=>$get,'replay'=>$bind,'operation'=>$operation] as $name=>$request){
        [$status,$r,$body]=promiseHttpRequest($address,$fixture,1,$request);
        promiseHttpCheck('fresh revoked ACL blocks '.$name.' and private data',$status===403&&!str_contains($body,'Пришлю расчёт')&&!str_contains($body,'Ручная окончательная правка'),$r);
    }
    promiseHttpCheck('revoked HTTP calls create no further receipts',promiseTaskCounts($db)===[1,2]);
    echo 'TOTAL '.$passed.' | PASS '.$passed.' | FAIL 0'."\n";
}catch(Throwable $error){
    if(is_file($directory.'/server.log'))fwrite(STDERR,substr((string)file_get_contents($directory.'/server.log'),-5000));
    throw $error;
}finally{
    if(is_resource($process)){proc_terminate($process);proc_close($process);}if(is_resource($log))fclose($log);
    $files=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);
    foreach($files as $file){if($file->isLink()||!$file->isDir())unlink($file->getPathname());else rmdir($file->getPathname());}rmdir($directory);
}
