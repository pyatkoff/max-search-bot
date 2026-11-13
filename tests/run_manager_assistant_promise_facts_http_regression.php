<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
// The parent CLI rewrites real fixture sessions after PASS output. Its session
// setup must not emit HTTP headers; the separately launched server keeps its
// normal cookie and cache-limiter settings.
ini_set('session.use_cookies','0');
session_cache_limiter('');
require_once __DIR__.'/support/manager-assistant-promise-facts-fixture.php';

// This gate needs real native PHP HTTP/session support. A syntax check or a
// PHP-WASM invocation does not count as an HTTP/session execution.
if(!function_exists('proc_open')||!function_exists('stream_socket_server')||!is_file(PHP_BINARY))throw new RuntimeException('native_php_http_runtime_required');
$directory=sys_get_temp_dir().'/assistant-promise-http-'.bin2hex(random_bytes(8));
if(!mkdir($directory,0700))throw new RuntimeException('http_fixture_directory_failed');
$process=null;$serverLog=null;$passed=0;

function aphCheck(string $name,bool $ok,mixed $detail=null): void
{
    global $passed;if(!$ok)throw new RuntimeException('FAIL '.$name.($detail===null?'':' '.json_encode($detail,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)));++$passed;echo 'PASS '.$name."\n";
}

function aphSession(string $sid,?int $actor,string $csrf='fixture-assistant-promise-csrf'): void
{
    if(session_status()===PHP_SESSION_ACTIVE)session_write_close();session_id($sid);
    if(!session_start())throw new RuntimeException('fixture_session_write_failed');
    $_SESSION=$actor===null?[]:['manager_id'=>$actor,'csrf'=>$csrf];session_write_close();
}

function aphFixture(string $directory): array
{
    $root=dirname(__DIR__);$database=$directory.'/fixture.sqlite';$pdo=assistantPromiseFixture($database);$intent=assistantPromiseAttach($pdo);
    $web=$directory.'/web';mkdir($web.'/manager/lib',0700,true);mkdir($directory.'/sessions',0700);
    foreach(['manager/assistant-api.php','manager/promise-selections-api.php','manager/lib/ManagerHttp.php'] as $file)
        if(!copy($root.'/'.$file,$web.'/'.$file))throw new RuntimeException('fixture_endpoint_copy_failed');
    if(!symlink($root.'/services',$web.'/services'))throw new RuntimeException('fixture_services_link_failed');
    $race=$directory.'/session-race';$raceReceipt=$directory.'/session-race-result.json';$guard=$directory.'/read-guard.json';
    $config="<?php\nrequire_once ".var_export(__DIR__.'/support/manager-assistant-promise-facts-fixture.php',true).";\n";
    $config.='$pdo=assistantPromiseHttpConnect('.var_export($database,true).','.var_export($race,true).','.var_export($raceReceipt,true).");\n";
    $config.='register_shutdown_function(static function() use ($pdo): void {file_put_contents('.var_export($guard,true).",json_encode(['write_attempts'=>\$pdo->writeAttempts],JSON_THROW_ON_ERROR));});\n";
    if(file_put_contents($web.'/config.php',$config)===false)throw new RuntimeException('fixture_config_write_failed');
    session_save_path($directory.'/sessions');session_name('anytour_manager_panel');$sessions=[];
    foreach([7,8,90,91,92] as $actor){$sessions[$actor]=bin2hex(random_bytes(16));aphSession($sessions[$actor],$actor);}
    return ['root'=>$root,'webroot'=>$web,'database'=>$database,'sessions'=>$directory.'/sessions','session_ids'=>$sessions,
        'race'=>$race,'race_receipt'=>$raceReceipt,'guard'=>$guard,'intent'=>$intent];
}

function aphRequest(string $address,array $fixture,?int $actor,array $input,string $endpoint='assistant-api.php',string $method='POST'): array
{
    $headers=['Content-Type: application/json'];if($actor!==null)$headers[]='Cookie: anytour_manager_panel='.$fixture['session_ids'][$actor];
    $context=stream_context_create(['http'=>['method'=>$method,'header'=>implode("\r\n",$headers),'content'=>json_encode($input,JSON_THROW_ON_ERROR),'ignore_errors'=>true,'timeout'=>5]]);
    $body=file_get_contents('http://'.$address.'/manager/'.$endpoint,false,$context);$status=0;$responseHeaders=$http_response_header??[];
    foreach($responseHeaders as $header)if(preg_match('/^HTTP\/\S+ (\d+)/',$header,$m))$status=(int)$m[1];
    return [$status,json_decode((string)$body,true),(string)$body,$responseHeaders];
}

function aphTask(array $item,int $id=101): ?array{return assistantPromiseTaskRow($item,$id);}

try{
    $fixture=aphFixture($directory);$listener=stream_socket_server('tcp://127.0.0.1:0',$errno,$error);
    if(!$listener)throw new RuntimeException('fixture_http_listener_failed');$address=stream_socket_get_name($listener,false);fclose($listener);
    $serverLog=fopen($directory.'/server.log','w');
    $process=proc_open([PHP_BINARY,'-d','session.save_path='.$fixture['sessions'],'-S',$address,'-t',$fixture['webroot']],[0=>['pipe','r'],1=>$serverLog,2=>$serverLog],$pipes);
    if(!is_resource($process))throw new RuntimeException('fixture_http_server_failed');fclose($pipes[0]);$ready=false;
    for($i=0;$i<100;++$i){if($socket=@stream_socket_client('tcp://'.$address,$e,$s,.05)){fclose($socket);$ready=true;break;}usleep(20000);}
    if(!$ready)throw new RuntimeException('fixture_http_not_ready');
    $db=new PDO('sqlite:'.$fixture['database'],null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
    $before=assistantPromiseSnapshot($db);$detail=['action'=>'detail','conversation_id'=>1,'csrf'=>'fixture-assistant-promise-csrf'];
    $list=['action'=>'list','scope'=>'mine','limit'=>20,'csrf'=>'fixture-assistant-promise-csrf'];
    foreach(['assistant-api.php','promise-selections-api.php'] as $endpoint)
        aphCheck('HTTP runs byte-exact '.$endpoint,hash_file('sha256',$fixture['root'].'/manager/'.$endpoint)===hash_file('sha256',$fixture['webroot'].'/manager/'.$endpoint));
    [$status]=aphRequest($address,$fixture,null,$detail,'assistant-api.php','GET');aphCheck('private Assistant facts are POST-only',$status===405);
    [$status,$r,$body]=aphRequest($address,$fixture,null,$detail);aphCheck('anonymous read has no task or promise disclosure',$status===401&&!isset($r['item'])&&!str_contains($body,'Отправить согласованную'),$r);
    [$status]=aphRequest($address,$fixture,92,$detail);aphCheck('inactive session cannot read private facts',$status===401);
    [$status]=aphRequest($address,$fixture,7,array_replace($detail,['csrf'=>'wrong']));aphCheck('initial private read requires current CSRF',$status===403);
    [$status,$r]=aphRequest($address,$fixture,7,array_replace($detail,['conversation_id'=>'1']));aphCheck('detail does not coerce a string conversation ID',$status===400&&empty($r['ok']),$r);
    foreach([8,91] as $actor){[$status,$r,$body]=aphRequest($address,$fixture,$actor,$detail);aphCheck('actor '.$actor.' cannot disclose another scoped task facts',$status===404&&!isset($r['item'])&&!str_contains($body,'promise_id'),$r);}
    [$status,$r]=aphRequest($address,$fixture,7,array_replace($list,['scope'=>'team']));aphCheck('employee cannot widen read into team scope',$status===403,$r);
    [$status,$r,$body,$headers]=aphRequest($address,$fixture,7,$list);
    aphCheck('list explicitly reports not-loaded promise facts with the authenticated viewer',$status===200&&$r['viewer_id']===7&&count($r['items'])===2&&array_unique(array_column($r['items'],'promise_coverage'))===['not_loaded'],$r);
    aphCheck('private list responses are not cacheable',preg_match('/\bcache-control:\s*no-store\b/i',implode("\n",$headers))===1);
    [$status,$r]=aphRequest($address,$fixture,7,$detail);$row=isset($r['item'])?aphTask($r['item']):null;
    aphCheck('detail returns checked facts for the canonical existing open task',$status===200&&$r['viewer_id']===7&&$r['item']['promise_coverage']==='checked'&&$row['title']==='Отправить согласованную подборку'&&$row['status']==='open'&&$row['promise']['binding']['promise_id']===$fixture['intent']['promise_id']&&$row['promise']['selection']['intent']['intent_id']===$fixture['intent']['intent_id'],$r);
    [$status,$r]=aphRequest($address,$fixture,90,$detail);
    aphCheck('authorized administrator sees facts without impersonating recommendation decisions',$status===200&&$r['viewer_id']===90&&$r['item']['can_decide']===false&&aphTask($r['item'])['promise']['availability']==='available',$r);

    $intent=$fixture['intent'];$view=['action'=>'view','conversation_id'=>1,'task_id'=>101,'promise_id'=>$intent['promise_id'],'intent_id'=>$intent['intent_id'],'publication_id'=>$intent['publication_id'],'csrf'=>'fixture-assistant-promise-csrf'];
    [$status,$r]=aphRequest($address,$fixture,7,$view,'promise-selections-api.php');
    aphCheck('exact task view returns only its requested publication and projection',$status===200&&$r['viewer_id']===7&&$r['task_id']===101&&$r['promise_id']===$intent['promise_id']&&$r['intent_id']===$intent['intent_id']&&$r['publication_id']===$intent['publication_id']&&$r['publication']['card_count']===2,$r);
    foreach([['task_id'=>'101'],['extra'=>true],['intent_id'=>null]] as $change){[$status,$r]=aphRequest($address,$fixture,7,array_replace($view,$change),'promise-selections-api.php');aphCheck('task view rejects coercion or extra input '.json_encode($change),$status===422&&empty($r['ok']),$r);}
    foreach([['task_id'=>102],['promise_id'=>str_repeat('0',64)],['intent_id'=>str_repeat('0',32)],['publication_id'=>str_repeat('0',32)]] as $change){
        [$status,$r,$body]=aphRequest($address,$fixture,7,array_replace($view,$change),'promise-selections-api.php');
        aphCheck('mismatched tuple '.key($change).' never reveals or substitutes a publication',$status===404&&!isset($r['publication'])&&!isset($r['projection'])&&!str_contains($body,'Синтетический отель'),$r);
    }
    [$status,$r,$body]=aphRequest($address,$fixture,91,$view,'promise-selections-api.php');aphCheck('foreign project cannot read frozen publication evidence',$status===403&&!isset($r['publication'])&&!str_contains($body,'Синтетический отель'),$r);
    aphCheck('successful and rejected reads create no task intent receipt or transcript changes',assistantPromiseSnapshot($db)===$before);

    foreach(['list'=>$list,'detail'=>$detail] as $action=>$request)foreach(['logout','actor','csrf'] as $mode){
        aphSession($fixture['session_ids'][7],7);if(is_file($fixture['race_receipt']))unlink($fixture['race_receipt']);file_put_contents($fixture['race'],$mode);
        [$status,$r,$body]=aphRequest($address,$fixture,7,$request);
        $receipt=is_file($fixture['race_receipt'])?json_decode((string)file_get_contents($fixture['race_receipt']),true):null;
        aphCheck($action.' observes a real '.$mode.' session change during task read',is_array($receipt)&&$receipt===['mode'=>$mode,'before_actor'=>7,'changed'=>true]&&!is_file($fixture['race']),$receipt);
        aphCheck($action.' final session fence removes stale '.$mode.' private facts',$status===401&&($r['error']??null)==='unauthorized'&&!isset($r['item'])&&!isset($r['items'])&&!str_contains($body,'promise_id')&&!str_contains($body,'Отправить согласованную'),$r);
    }
    aphSession($fixture['session_ids'][7],7);[$status,$r]=aphRequest($address,$fixture,7,$detail);
    aphCheck('unchanged valid session can read again without rebuilding or resubmitting anything',$status===200&&$r['viewer_id']===7&&$r['item']['promise_coverage']==='checked',$r);
    $guard=json_decode((string)file_get_contents($fixture['guard']),true);
    aphCheck('private read code attempted no SQL mutations',$guard===['write_attempts'=>0]&&assistantPromiseSnapshot($db)===$before,$guard);
    $db->exec('DELETE FROM manager_projects WHERE manager_id=7');
    [$status,$r,$body]=aphRequest($address,$fixture,7,$detail);aphCheck('fresh project revocation prevents new private detail disclosure',$status===404&&!isset($r['item'])&&!str_contains($body,'promise_id'),$r);
    [$status,$r,$body]=aphRequest($address,$fixture,7,$view,'promise-selections-api.php');aphCheck('same revocation blocks exact historical view',$status===403&&!isset($r['publication'])&&!str_contains($body,'Синтетический отель'),$r);
    aphCheck('session races and ACL denial do not mutate canonical business data',assistantPromiseSnapshot($db)===$before);
    echo 'TOTAL '.$passed.' | PASS '.$passed.' | FAIL 0'."\n";
}catch(Throwable $e){
    if(is_file($directory.'/server.log'))fwrite(STDERR,substr((string)file_get_contents($directory.'/server.log'),-4000));throw $e;
}finally{
    if(is_resource($process)){proc_terminate($process);proc_close($process);}if(is_resource($serverLog))fclose($serverLog);
    if(is_dir($directory)){
        $files=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);
        foreach($files as $file){if($file->isLink()||!$file->isDir())unlink($file->getPathname());else rmdir($file->getPathname());}rmdir($directory);
    }
    assistantPromiseCleanup();
}
