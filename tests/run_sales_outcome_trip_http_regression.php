<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once __DIR__.'/support/sales-outcome-trip-http.php';
$directory=sys_get_temp_dir().'/manager-request-profile-outcome-'.bin2hex(random_bytes(8));mkdir($directory,0700);
$process=null;$log=null;$passed=0;
function outcomeHttpCheck(string $name,bool $ok):void{global$passed;if(!$ok)throw new RuntimeException('FAIL '.$name);++$passed;echo 'PASS '.$name."\n";}
function outcomeHttpRequest(string $address,array $fixture,?int $actor,array $input,string $endpoint='pipeline-api.php',string $method='POST'):array
{
    $headers=['Content-Type: application/json'];if($actor!==null)$headers[]='Cookie: anytour_manager_panel='.$fixture['session_ids'][(string)$actor];
    $context=stream_context_create(['http'=>['method'=>$method,'header'=>implode("\r\n",$headers),'content'=>json_encode($input),'ignore_errors'=>true,'timeout'=>5]]);
    $body=file_get_contents('http://'.$address.'/manager/'.$endpoint,false,$context);$status=0;
    foreach($http_response_header??[] as $header)if(preg_match('/^HTTP\/\S+ (\d+)/',$header,$m))$status=(int)$m[1];
    return[$status,json_decode((string)$body,true),$body];
}
try{
    $fixture=salesOutcomeTripHttpFixture($directory);
    $listener=stream_socket_server('tcp://127.0.0.1:0',$errno,$error);if(!$listener)throw new RuntimeException('fixture_listener_failed');
    $address=stream_socket_get_name($listener,false);fclose($listener);
    $log=fopen($directory.'/server.log','w');
    $process=proc_open([PHP_BINARY,'-d','session.save_path='.$fixture['sessions'],'-S',$address,'-t',$fixture['webroot']],[0=>['pipe','r'],1=>$log,2=>$log],$pipes);
    if(!is_resource($process))throw new RuntimeException('fixture_server_failed');
    for($i=0;$i<100;$i++){if($socket=@stream_socket_client('tcp://'.$address,$e,$s,.05)){fclose($socket);break;}usleep(20000);}
    $base=['csrf'=>'fixture-profile-csrf','conversation_id'=>10];$detail=$base+['action'=>'detail'];$profileGet=$base+['action'=>'get'];
    $db=new PDO('sqlite:'.$fixture['database'],null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
    $count=static fn(string $table):int=>(int)$db->query('SELECT COUNT(*) FROM '.$table)->fetchColumn();
    [$status]=outcomeHttpRequest($address,$fixture,null,[],'pipeline-api.php','GET');outcomeHttpCheck('anonymous GET cannot access the outcome API',$status===401);
    [$status]=outcomeHttpRequest($address,$fixture,null,$detail);outcomeHttpCheck('anonymous outcome context denied',$status===401);
    [$status]=outcomeHttpRequest($address,$fixture,4,$detail);outcomeHttpCheck('inactive actor denied',$status===401);
    [$status]=outcomeHttpRequest($address,$fixture,1,array_replace($detail,['csrf'=>'wrong']));outcomeHttpCheck('private outcome reads require CSRF',$status===403);
    [$status,$r,$raw]=outcomeHttpRequest($address,$fixture,3,$detail);outcomeHttpCheck('foreign project cannot read trip context',in_array($status,[403,404],true)&&!str_contains($raw,'Декабрьский отдых'));
    [$status,$r]=outcomeHttpRequest($address,$fixture,1,$detail);$empty=$r['pipeline']['outcome_trip']??null;
    outcomeHttpCheck('actual detail admits empty unbound context without inventing trip',$status===200&&$r['can_edit_pipeline']&&$empty['available']&&$empty['binding']===null&&$empty['choices']===[]);
    [$status,$view]=outcomeHttpRequest($address,$fixture,1,$profileGet,'request-profile-api.php');
    $first=requestProfileCommand($view)+$base+['action'=>'save'];
    [$status,$r]=outcomeHttpRequest($address,$fixture,1,$first,'request-profile-api.php');outcomeHttpCheck('canonical profile API saves first trip',$status===200&&$r['saved_version']===1);
    [$status,$r]=outcomeHttpRequest($address,$fixture,1,$detail);$state=$r['pipeline']['outcome_trip'];$trip1=$state['choices'][0];
    outcomeHttpCheck('saved trip is offered but never attached automatically',$status===200&&$state['binding']===null&&count($state['choices'])===1&&$trip1['version']===1);
    $save=$base+['action'=>'set_outcome','outcome'=>'won','sale_amount'=>'185000','sale_date'=>'2026-11-07','note'=>'Синтетическая продажа','stage_key'=>'custom_won','expected_stage_key'=>'new',
        'outcome_trip'=>['operation_id'=>bin2hex(random_bytes(16)),'expected_revision'=>$state['revision'],'trip_id'=>$trip1['trip_id'],'profile_version'=>1]];
    [$status,$saved]=outcomeHttpRequest($address,$fixture,1,$save);
    outcomeHttpCheck('actual outcome endpoint atomically binds sale and stage to chosen trip',$status===200&&$saved['ok']&&!$saved['replayed']&&$saved['pipeline']['outcome_trip']['binding']['trip_id']===$trip1['trip_id']&&$saved['pipeline']['stage']['stage_key']==='custom_won'&&$saved['pipeline']['outcome']['sale_amount']==='185000.00');
    $rows=$count('lead_outcome_trip_bindings');$goals=$count('fixture_goal_calls');$events=$count('conversation_events');
    [$status,$r]=outcomeHttpRequest($address,$fixture,1,$save);
    outcomeHttpCheck('lost response retry keeps one write and one original sale signal',$status===200&&$r['replayed']&&$count('lead_outcome_trip_bindings')===$rows&&$count('fixture_goal_calls')===$goals&&$goals===1&&$count('conversation_events')===$events);
    [$status,$r]=outcomeHttpRequest($address,$fixture,1,$base+['action'=>'outcome_operation','operation_id'=>$save['outcome_trip']['operation_id']]);
    outcomeHttpCheck('read-only operation lookup confirms exact chosen trip',$status===200&&$r['found']&&$r['operation']['trip_id']===$trip1['trip_id']&&$count('lead_outcome_trip_bindings')===$rows&&$count('conversation_events')===$events);
    [$status,$r]=outcomeHttpRequest($address,$fixture,1,array_replace($save,['note'=>'Изменённая операция']));outcomeHttpCheck('same operation cannot replace its payload',$status===409&&$r['error']==='operation_mismatch'&&$count('lead_outcome_trip_bindings')===$rows);
    [$status,$r]=outcomeHttpRequest($address,$fixture,1,$detail);$beforeSecond=$r['pipeline']['outcome_trip'];
    requestProfileMessage($db,12,'customer','Новая поездка: январь, без детей.',10);
    [$status,$view]=outcomeHttpRequest($address,$fixture,1,$profileGet,'request-profile-api.php');
    $second=requestProfileCommand($view,['new_trip'=>true,'boundary_message_id'=>12,'label'=>'Январская поездка','fields'=>[]])+$base+['action'=>'save'];
    [$status,$r]=outcomeHttpRequest($address,$fixture,1,$second,'request-profile-api.php');outcomeHttpCheck('explicit second trip creates another saved identity',$status===200&&$r['saved_version']===2);
    [$status,$r]=outcomeHttpRequest($address,$fixture,1,$detail);$afterSecond=$r['pipeline']['outcome_trip'];
    outcomeHttpCheck('new trip does not take the existing sale binding',$status===200&&count($afterSecond['choices'])===2&&$afterSecond['binding']['trip_id']===$trip1['trip_id']&&$afterSecond['binding']['version']===1);
    $stale=$save;$stale['outcome_trip']['operation_id']=bin2hex(random_bytes(16));$stale['outcome_trip']['expected_revision']=$beforeSecond['revision'];unset($stale['stage_key'],$stale['expected_stage_key']);
    [$status,$r]=outcomeHttpRequest($address,$fixture,1,$stale);outcomeHttpCheck('new profile invalidates an already opened outcome form',$status===409&&in_array($r['error'],['conflict','context_changed'],true)&&$count('lead_outcome_trip_bindings')===$rows);
    $unlink=$stale;$unlink['outcome_trip']=['operation_id'=>bin2hex(random_bytes(16)),'expected_revision'=>$afterSecond['revision'],'trip_id'=>null,'profile_version'=>null];
    [$status,$r]=outcomeHttpRequest($address,$fixture,1,$unlink);
    outcomeHttpCheck('explicit unknown trip changes only the binding',$status===200&&$r['pipeline']['outcome_trip']['binding']===null&&$count('lead_outcome_trip_bindings')===$rows+1&&$count('fixture_goal_calls')===$goals&&$count('conversation_events')===$events);
    $rebind=$unlink;$rebind['outcome_trip']=['operation_id'=>bin2hex(random_bytes(16)),'expected_revision'=>$r['pipeline']['outcome_trip']['revision'],'trip_id'=>$trip1['trip_id'],'profile_version'=>1];
    [$status,$r]=outcomeHttpRequest($address,$fixture,1,$rebind);outcomeHttpCheck('historical first trip remains explicitly selectable after second trip',$status===200&&$r['pipeline']['outcome_trip']['binding']['trip_id']===$trip1['trip_id']&&$count('fixture_goal_calls')===$goals);
    $legacy=$save;unset($legacy['outcome_trip'],$legacy['stage_key'],$legacy['expected_stage_key']);$legacy['note']='Старый клиент изменил результат';
    [$status,$r]=outcomeHttpRequest($address,$fixture,1,$legacy);outcomeHttpCheck('legacy writer cannot silently retain a prior trip binding',$status===200&&$r['pipeline']['outcome_trip']['binding']===null);
    $rows=$count('lead_outcome_trip_bindings');$events=$count('conversation_events');
    $db->exec('DELETE FROM manager_projects WHERE manager_id=1');
    [$status]=outcomeHttpRequest($address,$fixture,1,$save);outcomeHttpCheck('revoked access denies even an idempotent replay',in_array($status,[403,404],true)&&$count('lead_outcome_trip_bindings')===$rows);
    [$status,$r,$raw]=outcomeHttpRequest($address,$fixture,1,$base+['action'=>'outcome_operation','operation_id'=>$save['outcome_trip']['operation_id']]);outcomeHttpCheck('revoked access denies private operation read',in_array($status,[403,404],true)&&!str_contains($raw,$trip1['trip_id']));
    outcomeHttpCheck('outcome flow does not send messages or mark the dialogue read',$count('messages')===3&&(int)$db->query('SELECT last_read_message_id FROM manager_conversation_reads WHERE manager_id=1')->fetchColumn()===10&&$count('conversation_events')===$events);
    echo 'TOTAL '.$passed.' | PASS '.$passed.' | FAIL 0'."\n";
}catch(Throwable $e){if(is_file($directory.'/server.log'))fwrite(STDERR,substr((string)file_get_contents($directory.'/server.log'),-3000));throw$e;}
finally{
    if(is_resource($process)){proc_terminate($process);proc_close($process);}if(is_resource($log))fclose($log);
    if(is_dir($directory)){$files=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);foreach($files as $f){if($f->isLink()||!$f->isDir())unlink($f->getPathname());else rmdir($f->getPathname());}rmdir($directory);}
}
