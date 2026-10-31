<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once __DIR__.'/support/manager-ai-usage-http.php';
$directory=sys_get_temp_dir().'/manager-ai-usage-'.bin2hex(random_bytes(8));mkdir($directory,0700);$fixture=managerAiUsageHttpFixture($directory);
$listener=stream_socket_server('tcp://127.0.0.1:0',$errno,$error);if(!$listener)throw new RuntimeException('fixture_listener_failed');$address=stream_socket_get_name($listener,false);fclose($listener);
$log=fopen($directory.'/server.log','w');$process=proc_open([PHP_BINARY,'-d','session.save_path='.$fixture['sessions'],'-S',$address,'-t',$fixture['webroot']],[0=>['pipe','r'],1=>$log,2=>$log],$pipes);
if(!is_resource($process))throw new RuntimeException('fixture_server_failed');
$passed=0;
function usageHttpCheck(string $name,bool $ok):void{global$passed;if(!$ok)throw new RuntimeException('FAIL '.$name);++$passed;echo 'PASS '.$name."\n";}
function usageHttpRequest(string $address,array $fixture,?int $actor,array $input,string $method='POST'):array
{
    $headers=['Content-Type: application/json'];if($actor!==null)$headers[]='Cookie: anytour_manager_panel='.$fixture['session_ids'][(string)$actor];
    $context=stream_context_create(['http'=>['method'=>$method,'header'=>implode("\r\n",$headers),'content'=>json_encode($input),'ignore_errors'=>true,'timeout'=>5]]);
    $body=file_get_contents('http://'.$address.'/manager/ai-usage-api.php',false,$context);$status=0;foreach($http_response_header??[] as $header)if(preg_match('/^HTTP\/\S+ (\d+)/',$header,$m))$status=(int)$m[1];
    return[$status,json_decode((string)$body,true),$body];
}
try{
    for($i=0;$i<100;$i++){if($socket=@stream_socket_client('tcp://'.$address,$e,$s,.05)){fclose($socket);break;}usleep(20000);}
    [$status,$r]=usageHttpRequest($address,$fixture,null,[],'GET');usageHttpCheck('only POST is accepted',$status===405);
    [$status,$r,$body]=usageHttpRequest($address,$fixture,null,['action'=>'list']);usageHttpCheck('anonymous read is401 with no private text',$status===401&&$r['error']==='unauthorized'&&!str_contains($body,'AI proposal'));
    [$status,$r]=usageHttpRequest($address,$fixture,3,['action'=>'identity']);usageHttpCheck('ordinary manager cannot enter owner report',$status===403&&$r['error']==='forbidden');
    [$status,$r]=usageHttpRequest($address,$fixture,4,['action'=>'identity']);usageHttpCheck('inactive owner cannot enter report',$status===401);
    [$status,$identity]=usageHttpRequest($address,$fixture,1,['action'=>'identity']);usageHttpCheck('identity reuses canonical session and CSRF',$status===200&&$identity['viewer_id']===1&&$identity['csrf']==='fixture-usage-csrf');
    [$status,$r]=usageHttpRequest($address,$fixture,1,['action'=>'list','csrf'=>'wrong']);usageHttpCheck('read requires existing CSRF',$status===403&&$r['error']==='csrf');
    [$status,$r]=usageHttpRequest($address,$fixture,1,['action'=>'list','csrf'=>$identity['csrf']]);usageHttpCheck('actual endpoint returns bounded owner cases',$status===200&&count($r['items'])===20&&$r['viewer_id']===1);$case=$r['items'][0];
    $inputRequest=['action'=>'input','csrf'=>$identity['csrf'],'message_id'=>$case['message_id'],'case_revision'=>$case['case_revision']];
    [$status,$r,$body]=usageHttpRequest($address,$fixture,null,$inputRequest);usageHttpCheck('anonymous input read contains no frozen text',$status===401&&!str_contains($body,'Question 47'));
    [$status,$r]=usageHttpRequest($address,$fixture,1,array_replace($inputRequest,['csrf'=>'wrong']));usageHttpCheck('input read requires CSRF',$status===403&&$r['error']==='csrf');
    [$status,$r]=usageHttpRequest($address,$fixture,2,$inputRequest);usageHttpCheck('input read cannot cross project ownership',$status===403);
    [$status,$r]=usageHttpRequest($address,$fixture,3,$inputRequest);usageHttpCheck('ordinary managers cannot request private input evidence',$status===403);
    [$status,$r]=usageHttpRequest($address,$fixture,1,array_replace($inputRequest,['message_id'=>(string)$case['message_id']]));usageHttpCheck('input read requires strict numeric message identity',$status===422);
    [$status,$r]=usageHttpRequest($address,$fixture,1,array_replace($inputRequest,['case_revision'=>str_repeat('0',64)]));usageHttpCheck('input read rejects a stale comparison',$status===409&&$r['error']==='case_changed');
    [$status,$originalInput]=usageHttpRequest($address,$fixture,1,$inputRequest);usageHttpCheck('protected endpoint returns complete frozen text with exact caller and comparison binding',
        $status===200&&$originalInput['available']&&$originalInput['viewer_id']===1
        &&$originalInput['case_revision']===$case['case_revision']&&$originalInput['message_id']===$case['message_id']
        &&$originalInput['input_evidence']['context']['messages'][0]['text']==='Question 47'
        &&$originalInput['input_evidence']['instructions']==='Synthetic context-specific instructions');
    $db=new PDO('sqlite:'.$fixture['database'],null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
    $db->exec("UPDATE messages SET text='Changed source after generation' WHERE id=1047");
    [$status,$r]=usageHttpRequest($address,$fixture,1,$inputRequest);usageHttpCheck('HTTP read never reconstructs its input from the edited source',$status===200&&$r===$originalInput);
    usageHttpCheck('input requests do not mark a comparison reviewed',(int)$db->query('SELECT COUNT(*) FROM manager_ai_usage_reviews')->fetchColumn()===0);
    [$status,$r]=usageHttpRequest($address,$fixture,2,['action'=>'review','csrf'=>'fixture-usage-csrf','message_id'=>$case['message_id'],'case_revision'=>$case['case_revision']]);usageHttpCheck('review cannot cross current project permissions',$status===403);
    [$status,$r]=usageHttpRequest($address,$fixture,1,['action'=>'review','csrf'=>'wrong','message_id'=>$case['message_id'],'case_revision'=>$case['case_revision']]);usageHttpCheck('review requires CSRF',$status===403);
    [$status,$r]=usageHttpRequest($address,$fixture,1,['action'=>'review','csrf'=>$identity['csrf'],'message_id'=>(string)$case['message_id'],'case_revision'=>$case['case_revision']]);usageHttpCheck('review request has strict message identity',$status===422);
    [$status,$r]=usageHttpRequest($address,$fixture,1,['action'=>'review','csrf'=>$identity['csrf'],'message_id'=>$case['message_id'],'case_revision'=>$case['case_revision']]);usageHttpCheck('explicit review is confirmed by actual protected endpoint',$status===200&&$r['reviewed']===true&&$r['message_id']===$case['message_id']);
    [$status,$r]=usageHttpRequest($address,$fixture,1,['action'=>'list','csrf'=>$identity['csrf']]);usageHttpCheck('new report no longer returns the reviewed revision',$status===200&&!in_array($case['message_id'],array_column($r['items'],'message_id'),true));
    $meta=json_decode($db->query('SELECT metadata_json FROM messages WHERE id=10047')->fetchColumn(),true);$meta['edited_at']=gmdate('Y-m-d H:i:s');
    $db->prepare('UPDATE messages SET text=?,metadata_json=? WHERE id=10047')->execute(['Later edited text',json_encode($meta)]);
    [$status,$page]=usageHttpRequest($address,$fixture,1,['action'=>'list','csrf'=>$identity['csrf']]);$edited=array_column($page['items'],null,'message_id')[10047];
    $sentRequest=['action'=>'sent_text','csrf'=>$identity['csrf'],'message_id'=>10047,'case_revision'=>$edited['case_revision']];
    [$status,$r]=usageHttpRequest($address,$fixture,1,$sentRequest);usageHttpCheck('protected endpoint preserves initial manager text after edit',$status===200&&$r['available']&&$r['text']==='AI proposal 47'&&$r['case_revision']===$edited['case_revision']&&$r['viewer_id']===1);
    foreach([null,2,3,4] as $actor){[$status,$r,$body]=usageHttpRequest($address,$fixture,$actor,$sentRequest);usageHttpCheck('initial text is not exposed outside current owner authority '.(string)$actor,in_array($status,[401,403],true)&&!str_contains($body,'AI proposal 47'));}
    [$status,$r]=usageHttpRequest($address,$fixture,1,array_replace($sentRequest,['csrf'=>'wrong']));usageHttpCheck('initial text requires CSRF',$status===403);
    [$status,$r]=usageHttpRequest($address,$fixture,1,array_replace($sentRequest,['case_revision'=>$case['case_revision']]));usageHttpCheck('initial text requires refreshed comparison after edit',$status===409&&$r['error']==='case_changed');
    [$status,$r]=usageHttpRequest($address,$fixture,1,array_replace($sentRequest,['message_id'=>'10047']));usageHttpCheck('initial text requires strict numeric identity',$status===422);
    echo 'TOTAL '.$passed.' | PASS '.$passed.' | FAIL 0'."\n";
}catch(Throwable $error){fwrite(STDERR,substr((string)file_get_contents($directory.'/server.log'),-5000));throw$error;}finally{
    proc_terminate($process);proc_close($process);fclose($log);
    $files=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);
    foreach($files as $file){if($file->isLink()||!$file->isDir())unlink($file->getPathname());else rmdir($file->getPathname());}rmdir($directory);
}
