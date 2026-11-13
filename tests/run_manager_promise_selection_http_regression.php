<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once __DIR__.'/support/manager-promise-selection-fixture.php';
require_once dirname(__DIR__).'/services/ManagerPromiseSelectionService.php';

// Actual endpoint, sessions, SQL and ACL. A native PHP CLI with PDO SQLite is
// required; syntax checks or a WASM-only run do not satisfy this HTTP gate.
if(!function_exists('proc_open')||!function_exists('stream_socket_server')||!is_file(PHP_BINARY))throw new RuntimeException('native_php_http_runtime_required');
$directory=sys_get_temp_dir().'/manager-promise-selection-'.bin2hex(random_bytes(8));
if(!mkdir($directory,0700))throw new RuntimeException('fixture_directory_failed');
$process=null;$log=null;$passed=0;

function selectionHttpCheck(string $name,bool $ok,mixed $detail=null): void
{
    global $passed;
    if(!$ok)throw new RuntimeException('FAIL '.$name.($detail===null?'':' '.json_encode($detail,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)));
    ++$passed;echo 'PASS '.$name."\n";
}

function selectionHttpFixture(string $directory): array
{
    $root=dirname(__DIR__);$database=$directory.'/fixture.sqlite';$pdo=promiseSelectionFixture($database);
    $pdo->exec("INSERT INTO projects VALUES(2,'appleservice','Other synthetic project',1);
INSERT INTO managers VALUES(91,'foreign-admin','Other project administrator','admin','',1,1),(92,'inactive','Inactive manager','manager','',0,1);
INSERT INTO manager_projects VALUES(91,2),(92,1);");
    $web=$directory.'/web';mkdir($web.'/manager/lib',0700,true);mkdir($directory.'/sessions',0700);
    foreach(['manager/promise-selections-api.php','manager/lib/ManagerHttp.php'] as $file){
        if(!copy($root.'/'.$file,$web.'/'.$file))throw new RuntimeException('fixture_copy_failed');
    }
    if(!symlink($root.'/services',$web.'/services'))throw new RuntimeException('fixture_service_link_failed');
    $config="<?php\nrequire_once ".var_export($root.'/services/ManagerPromiseSelectionService.php',true).";\n";
    $config.='$pdo=new PDO('.var_export('sqlite:'.$database,true).",null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);\n";
    $config.='$pdo->exec(\'PRAGMA foreign_keys=ON\');'."\n";
    $config.='$pdo->sqliteCreateFunction(\'UTC_TIMESTAMP\',static fn():string=>\'2026-11-13 12:00:00\');'."\n";
    $config.='$pdo->sqliteCreateFunction(\'NOW\',static fn():string=>gmdate(\'Y-m-d H:i:s\'));'."\n";
    $config.='(new ReflectionProperty(ConversationDb::class,\'pdo\'))->setValue(null,$pdo);'."\n";
    $config.='foreach([ProjectAccessService::class,ManagerAuthService::class,RoutingAccessService::class] as $class)(new ReflectionProperty($class,\'schemaReady\'))->setValue(null,true);'."\n";
    $config.="ProjectConfig::resetForTests(['id'=>'anytour','brand'=>['name'=>'AnyTour']]);\n";
    if(file_put_contents($web.'/config.php',$config)===false)throw new RuntimeException('fixture_config_failed');
    session_save_path($directory.'/sessions');session_name('anytour_manager_panel');$sessions=[];
    foreach([7,8,90,91,92] as $actor){
        $sid=bin2hex(random_bytes(16));session_id($sid);session_start();
        $_SESSION=['manager_id'=>$actor,'csrf'=>'fixture-selection-csrf'];session_write_close();$sessions[(string)$actor]=$sid;
    }
    return ['root'=>$root,'webroot'=>$web,'database'=>$database,'sessions'=>$directory.'/sessions','session_ids'=>$sessions,'ids'=>promiseSelectionIds($pdo)];
}

function selectionHttpRequest(string $address,array $fixture,?int $actor,array $input,string $method='POST'): array
{
    $headers=['Content-Type: application/json'];
    if($actor!==null)$headers[]='Cookie: anytour_manager_panel='.$fixture['session_ids'][(string)$actor];
    $options=['method'=>$method,'header'=>implode("\r\n",$headers),'content'=>json_encode($input,JSON_THROW_ON_ERROR),'ignore_errors'=>true,'timeout'=>5];
    $body=file_get_contents('http://'.$address.'/manager/promise-selections-api.php',false,stream_context_create(['http'=>$options]));
    $status=0;foreach($http_response_header??[] as $header)if(preg_match('/^HTTP\/\S+ (\d+)/',$header,$m))$status=(int)$m[1];
    return [$status,json_decode((string)$body,true),(string)$body];
}

function selectionHttpCanonicalSnapshot(PDO $pdo): array
{
    $snapshot=[];
    foreach(['conversations','lead_tasks','manager_task_promises','manager_task_promise_operations','manager_request_profiles','messages','manager_conversation_reads','manager_tour_selection_drafts','manager_tour_selection_operations','manager_tour_selection_publications','manager_tour_selection_cards','manager_tour_selection_deliveries','conversation_events'] as $table){
        $snapshot[$table]=$pdo->query('SELECT * FROM '.$table.' ORDER BY rowid')->fetchAll(PDO::FETCH_ASSOC);
    }
    return $snapshot;
}

function selectionHttpCounts(PDO $pdo): array
{
    return [(int)$pdo->query('SELECT COUNT(*) FROM manager_promise_selection_intents')->fetchColumn(),(int)$pdo->query('SELECT COUNT(*) FROM manager_promise_selection_operations')->fetchColumn()];
}

try{
    $fixture=selectionHttpFixture($directory);
    $listener=stream_socket_server('tcp://127.0.0.1:0',$errno,$error);
    if(!$listener)throw new RuntimeException('fixture_listener_failed');
    $address=stream_socket_get_name($listener,false);fclose($listener);
    $log=fopen($directory.'/server.log','w');
    $process=proc_open([PHP_BINARY,'-d','session.save_path='.$fixture['sessions'],'-S',$address,'-t',$fixture['webroot']],[0=>['pipe','r'],1=>$log,2=>$log],$pipes);
    if(!is_resource($process))throw new RuntimeException('fixture_server_failed');
    fclose($pipes[0]);$ready=false;
    for($i=0;$i<100;++$i){if($socket=@stream_socket_client('tcp://'.$address,$e,$s,.05)){fclose($socket);$ready=true;break;}usleep(20000);}
    if(!$ready)throw new RuntimeException('fixture_server_not_ready');
    $db=new PDO('sqlite:'.$fixture['database'],null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
    $before=selectionHttpCanonicalSnapshot($db);
    $catalog=['action'=>'catalog','csrf'=>'fixture-selection-csrf','conversation_id'=>1];
    $preview=$catalog+$fixture['ids'];$preview['action']='preview';
    selectionHttpCheck('HTTP fixture executes the byte-exact production API',hash_file('sha256',$fixture['root'].'/manager/promise-selections-api.php')===hash_file('sha256',$fixture['webroot'].'/manager/promise-selections-api.php'));
    [$status]=selectionHttpRequest($address,$fixture,null,$catalog,'GET');selectionHttpCheck('all private actions are POST-only',$status===405);
    [$status,$r,$body]=selectionHttpRequest($address,$fixture,null,$preview);
    selectionHttpCheck('anonymous read cannot reveal task or source',$status===401&&!str_contains($body,'Пришлю подборку'),$r);
    [$status]=selectionHttpRequest($address,$fixture,92,$preview);selectionHttpCheck('inactive session is unauthorized',$status===401);
    [$status]=selectionHttpRequest($address,$fixture,7,array_replace($preview,['csrf'=>'wrong']));selectionHttpCheck('private read requires current CSRF',$status===403);
    [$status,$r,$body]=selectionHttpRequest($address,$fixture,91,$preview);
    selectionHttpCheck('foreign-project administrator cannot read evidence',$status===403&&!str_contains($body,'Пришлю подборку'),$r);
    foreach([['conversation_id'=>'1'],['promise_id'=>1],['publication_id'=>true],['extra'=>true]] as $changes){
        [$status,$r]=selectionHttpRequest($address,$fixture,7,array_replace($preview,$changes));
        selectionHttpCheck('preview rejects coercion or extra members '.json_encode($changes),$status===422&&empty($r['ok']),$r);
    }
    [$status,$r]=selectionHttpRequest($address,$fixture,7,$catalog);
    selectionHttpCheck('catalog returns current actor and explicit candidates',$status===200&&$r['viewer_id']===7&&$r['conversation_id']===1&&count($r['promises'])===1&&count($r['publications'])===1&&$r['intents']===[],$r);
    [$status,$view]=selectionHttpRequest($address,$fixture,7,$preview);
    selectionHttpCheck('review contains existing task revision, original manager source and full composition',$status===200&&$view['can_attach']===true&&$view['task']['id']===101&&$view['task']['business_revision']===1&&$view['source']['id']===12&&$view['source']['author_manager_id']===7&&$view['publication']['card_count']===2&&count($view['publication']['cards'])===2,$view);
    selectionHttpCheck('reads create no association or business mutation',selectionHttpCounts($db)===[0,0]&&selectionHttpCanonicalSnapshot($db)===$before);
    $attach=$catalog+['operation_id'=>bin2hex(random_bytes(16)),...$fixture['ids'],'preview_sha256'=>$view['preview_sha256']];$attach['action']='attach';
    [$status]=selectionHttpRequest($address,$fixture,7,array_replace($attach,['csrf'=>'wrong']));selectionHttpCheck('attach also requires current CSRF',$status===403);
    [$status,$r]=selectionHttpRequest($address,$fixture,8,$attach);selectionHttpCheck('visible unassigned colleague cannot arm a task',$status===403,$r);
    [$status,$r]=selectionHttpRequest($address,$fixture,7,$attach+['task_id'=>103]);selectionHttpCheck('attach cannot choose an arbitrary task or delivery action',$status===422&&empty($r['ok']),$r);
    [$status,$r]=selectionHttpRequest($address,$fixture,7,array_replace($attach,['preview_sha256'=>str_repeat('f',64)]));selectionHttpCheck('stale review hash cannot arm a task',$status===409&&$r['error']==='preview_changed',$r);
    selectionHttpCheck('rejected writes preserve canonical data and empty intent storage',selectionHttpCounts($db)===[0,0]&&selectionHttpCanonicalSnapshot($db)===$before);
    [$status,$attached]=selectionHttpRequest($address,$fixture,7,$attach);
    $intent=$attached['intent']['intent_id']??'';
    selectionHttpCheck('explicit HTTP attach records one armed intent',$status===200&&$attached['replayed']===false&&$attached['intent']['state']==='armed'&&$attached['intent']['task_id']===101&&selectionHttpCounts($db)===[1,1],$attached);
    selectionHttpCheck('arming changes no task, message, publication or read marker',selectionHttpCanonicalSnapshot($db)===$before);
    [$status,$r]=selectionHttpRequest($address,$fixture,7,$attach);
    selectionHttpCheck('lost acknowledgement replays the same intent once',$status===200&&$r['replayed']===true&&$r['intent']['intent_id']===$intent&&selectionHttpCounts($db)===[1,1],$r);
    [$status,$r]=selectionHttpRequest($address,$fixture,7,array_replace($attach,['preview_sha256'=>str_repeat('e',64)]));selectionHttpCheck('operation cannot be reused for changed input',$status===409&&$r['error']==='operation_mismatch',$r);
    $operation=$catalog+['operation_id'=>$attach['operation_id']];$operation['action']='operation';
    [$status,$r]=selectionHttpRequest($address,$fixture,7,$operation);
    selectionHttpCheck('receipt lookup reconciles the actual association',$status===200&&$r['found']===true&&$r['action']==='attach'&&$r['intent']['intent_id']===$intent,$r);
    [$status,$r]=selectionHttpRequest($address,$fixture,8,$operation);selectionHttpCheck('operation receipt is scoped to the original actor',$status===200&&$r['found']===false,$r);
    [$status,$r]=selectionHttpRequest($address,$fixture,7,array_replace($operation,['operation_id'=>str_repeat('f',32)]));selectionHttpCheck('unknown operation is read-only absence',$status===200&&$r['found']===false,$r);
    [$status,$r]=selectionHttpRequest($address,$fixture,7,array_replace($operation,['conversation_id'=>2]));selectionHttpCheck('receipt cannot cross conversation scope',$status===409&&$r['error']==='operation_mismatch',$r);
    $statusRequest=$catalog+['intent_id'=>$intent];$statusRequest['action']='status';
    [$status,$r]=selectionHttpRequest($address,$fixture,7,$statusRequest);
    selectionHttpCheck('status reports armed and no delivered members',$status===200&&$r['intent']['state']==='armed'&&$r['intent']['delivered_count']===0,$r);
    [$status,$r]=selectionHttpRequest($address,$fixture,7,$statusRequest+['complete'=>true]);selectionHttpCheck('status exposes no completion command',$status===422,$r);
    $cancel=$catalog+['operation_id'=>bin2hex(random_bytes(16)),'intent_id'=>$intent];$cancel['action']='cancel';
    [$status,$r]=selectionHttpRequest($address,$fixture,8,$cancel);selectionHttpCheck('unassigned colleague cannot cancel the association',$status===403,$r);
    [$status,$r]=selectionHttpRequest($address,$fixture,7,$cancel);
    selectionHttpCheck('explicit cancellation disarms the association',$status===200&&$r['intent']['state']==='cancelled'&&selectionHttpCounts($db)===[1,2],$r);
    [$status,$r]=selectionHttpRequest($address,$fixture,7,$cancel);selectionHttpCheck('cancel replay records no second operation',$status===200&&$r['replayed']===true&&selectionHttpCounts($db)===[1,2],$r);
    selectionHttpCheck('cancellation preserves the open task and all canonical rows',selectionHttpCanonicalSnapshot($db)===$before);
    [$status,$r]=selectionHttpRequest($address,$fixture,7,$operation);selectionHttpCheck('original attach receipt reports current cancelled state',$status===200&&$r['intent']['state']==='cancelled',$r);
    $db->exec('DELETE FROM manager_projects WHERE manager_id=7');
    foreach(['catalog'=>$catalog,'preview'=>$preview,'attach replay'=>$attach,'cancel replay'=>$cancel,'operation'=>$operation,'status'=>$statusRequest] as $name=>$request){
        [$status,$r,$body]=selectionHttpRequest($address,$fixture,7,$request);
        selectionHttpCheck('fresh revoked ACL blocks '.$name.' and private evidence',$status===403&&!str_contains($body,'Пришлю подборку')&&!str_contains($body,'Отправить согласованную'),$r);
    }
    selectionHttpCheck('revoked HTTP requests create no receipts or business changes',selectionHttpCounts($db)===[1,2]&&selectionHttpCanonicalSnapshot($db)===$before);
    echo 'TOTAL '.$passed.' | PASS '.$passed.' | FAIL 0'."\n";
}catch(Throwable $error){
    if(is_file($directory.'/server.log'))fwrite(STDERR,substr((string)file_get_contents($directory.'/server.log'),-5000));
    throw $error;
}finally{
    if(is_resource($process)){proc_terminate($process);proc_close($process);}if(is_resource($log))fclose($log);
    $files=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);
    foreach($files as $file){if($file->isLink()||!$file->isDir())unlink($file->getPathname());else rmdir($file->getPathname());}rmdir($directory);
}
