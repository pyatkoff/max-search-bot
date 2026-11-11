<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once __DIR__.'/support/sales-outcome-trip-http.php';
$directory=sys_get_temp_dir().'/manager-request-profile-entry-region-'.bin2hex(random_bytes(8));mkdir($directory,0700);
$process=null;$log=null;$passed=0;
function entryHttpCheck(string $name,bool $ok):void
{
    global$passed;if(!$ok)throw new RuntimeException('FAIL '.$name);++$passed;echo 'PASS '.$name."\n";
}
function entryHttpReceipt(string $id):array
{
    return ['version'=>1,'yandex_region_id'=>$id,'name'=>'','kind'=>'advertising','source'=>'telegram_start_payload',
        'platform'=>'telegram','recorded_at_unix'=>1794300000,'receipt_sha256'=>hash('sha256','synthetic-http-entry-'.$id)];
}
function entryHttpRequest(string $address,array $fixture,?int $actor,array $input,string $method='POST'):array
{
    $headers=['Content-Type: application/json'];if($actor!==null)$headers[]='Cookie: anytour_manager_panel='.$fixture['session_ids'][(string)$actor];
    $context=stream_context_create(['http'=>['method'=>$method,'header'=>implode("\r\n",$headers),'content'=>json_encode($input),'ignore_errors'=>true,'timeout'=>5]]);
    $body=file_get_contents('http://'.$address.'/manager/pipeline-api.php',false,$context);$status=0;
    foreach($http_response_header??[]as$header)if(preg_match('/^HTTP\/\S+ (\d+)/',$header,$m))$status=(int)$m[1];
    return[$status,json_decode((string)$body,true),$body];
}
try{
    $fixture=salesOutcomeTripHttpFixture($directory);
    $db=new PDO('sqlite:'.$fixture['database'],null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
    $db->exec('ALTER TABLE conversations ADD COLUMN metadata_json TEXT');
    $first=entryHttpReceipt('62');$latest=entryHttpReceipt('51');
    $metadata=['first_entry_region'=>$first,'entry_region'=>$latest,'private_token'=>'PRIVATE_HTTP_METADATA_TOKEN',
        'repair_context'=>['entry_code'=>'PRIVATE_HTTP_REPAIR_CODE']];
    $save=static function(array $value)use($db):void{$db->prepare('UPDATE conversations SET metadata_json=?,attribution_region=? WHERE id=10')->execute([json_encode($value,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),'213']);};
    $save($metadata);
    file_put_contents($fixture['webroot'].'/maxsearchclass.php',"<?php\nclass MaxSearchApi { public static function getAiSearchContext(\$chat):array {return ['city'=>'Москва','no_flight'=>true];} }\n");
    $listener=stream_socket_server('tcp://127.0.0.1:0',$errno,$error);if(!$listener)throw new RuntimeException('fixture_listener_failed');
    $address=stream_socket_get_name($listener,false);fclose($listener);
    $log=fopen($directory.'/server.log','w');
    $process=proc_open([PHP_BINARY,'-d','session.save_path='.$fixture['sessions'],'-S',$address,'-t',$fixture['webroot']],[0=>['pipe','r'],1=>$log,2=>$log],$pipes);
    if(!is_resource($process))throw new RuntimeException('fixture_server_failed');
    for($i=0;$i<100;$i++){if($socket=@stream_socket_client('tcp://'.$address,$e,$s,.05)){fclose($socket);break;}usleep(20000);}
    $detail=['csrf'=>'fixture-profile-csrf','conversation_id'=>10,'action'=>'detail'];
    $before=$db->query('SELECT * FROM conversations ORDER BY id')->fetchAll();
    $readBefore=$db->query('SELECT * FROM manager_conversation_reads ORDER BY manager_id,conversation_id')->fetchAll();
    $messagesBefore=$db->query('SELECT * FROM messages ORDER BY id')->fetchAll();
    $eventsBefore=$db->query('SELECT * FROM conversation_events ORDER BY id')->fetchAll();
    foreach([[null,$detail,401],[4,$detail,401],[1,array_replace($detail,['csrf'=>'wrong']),403],[3,$detail,404]]as[$actor,$input,$expected]){
        [$status,$r,$raw]=entryHttpRequest($address,$fixture,$actor,$input);
        entryHttpCheck('Unauthorized inactive and foreign actors receive no entry geography '.json_encode($actor),
            $status===$expected&&!str_contains($raw,'Красноярск')&&!str_contains($raw,'first_entry_region')&&!str_contains($raw,'PRIVATE_HTTP_'));
    }
    [$status]=entryHttpRequest($address,$fixture,null,[],'GET');entryHttpCheck('Anonymous GET remains unauthorized',$status===401);
    [$status,$r,$raw]=entryHttpRequest($address,$fixture,1,$detail);$source=$r['source']??[];
    entryHttpCheck('Actual authorized pipeline detail exposes the first and latest entry independently',
        $status===200&&$source['first_entry_region']['label']==='Красноярск · Красноярский край'
        &&$source['first_entry_region']['yandex_region_id']==='62'&&$source['latest_entry_region']['yandex_region_id']==='51'
        &&$source['latest_entry_region']['label']==='Самара · Самарская область'&&$source['attribution_region']==='213');
    entryHttpCheck('Entry display does not replace the tourist departure or no-flight state',($r['trip']??null)===['city'=>'Москва','no_flight'=>true]);
    entryHttpCheck('Actual JSON response has no raw receipt provenance hashes tokens or metadata',
        !str_contains($raw,'PRIVATE_HTTP_')&&!str_contains($raw,'receipt_sha256')&&!str_contains($raw,'recorded_at_unix')
        &&!str_contains($raw,'telegram_start_payload')&&!str_contains($raw,'metadata_json')&&!str_contains($raw,$first['receipt_sha256'])
        &&array_keys($source['first_entry_region'])===['yandex_region_id','name','label','hierarchy','known']);
    [$status,$admin]=entryHttpRequest($address,$fixture,2,$detail);
    entryHttpCheck('An authorized project administrator receives the same minimized projection',$status===200&&$admin['source']['first_entry_region']===$source['first_entry_region']);
    [$status,$colleague,$raw]=entryHttpRequest($address,$fixture,5,$detail);
    entryHttpCheck('Existing same-project colleague read access retains minimized geography without sales edit rights',
        $status===200&&$colleague['source']['first_entry_region']===$source['first_entry_region']&&!$colleague['can_edit_pipeline']
        &&!str_contains($raw,'PRIVATE_HTTP_')&&!str_contains($raw,'receipt_sha256'));
    entryHttpCheck('Detail and denied reads cannot mutate the conversation read frontier events or messages',
        $db->query('SELECT * FROM conversations ORDER BY id')->fetchAll()===$before
        &&$db->query('SELECT * FROM manager_conversation_reads ORDER BY manager_id,conversation_id')->fetchAll()===$readBefore
        &&$db->query('SELECT * FROM messages ORDER BY id')->fetchAll()===$messagesBefore
        &&$db->query('SELECT * FROM conversation_events ORDER BY id')->fetchAll()===$eventsBefore);

    $save(['entry_region'=>$latest]);[$status,$r]=entryHttpRequest($address,$fixture,1,$detail);
    entryHttpCheck('A latest-only HTTP detail leaves first entry absent',$status===200&&$r['source']['first_entry_region']===null&&$r['source']['latest_entry_region']['yandex_region_id']==='51');
    $unknown=entryHttpReceipt('99999999999999999999');$save(['first_entry_region'=>$unknown]);[$status,$r]=entryHttpRequest($address,$fixture,1,$detail);
    entryHttpCheck('Unknown IDs stay strings and display no invented geography in actual detail',
        $status===200&&$r['source']['first_entry_region']===['yandex_region_id'=>'99999999999999999999','name'=>'','label'=>'Регион не определён','hierarchy'=>[],'known'=>false]
        &&$r['source']['latest_entry_region']===null);
    $bad=$first;$bad['platform']='max';$save(['first_entry_region'=>$bad,'entry_region'=>$latest]);[$status,$r]=entryHttpRequest($address,$fixture,1,$detail);
    entryHttpCheck('Cross-platform first receipt cannot borrow a valid later entry through HTTP',$status===200&&$r['source']['first_entry_region']===null&&$r['source']['latest_entry_region']['yandex_region_id']==='51');
    $save(['first_entry_region'=>$first,'padding'=>str_repeat('я',131073)]);[$status,$r]=entryHttpRequest($address,$fixture,1,$detail);
    entryHttpCheck('Oversized Unicode metadata is unavailable while the rest of the card remains editable',
        $status===200&&$r['source']['first_entry_region']===null&&$r['source']['latest_entry_region']===null&&$r['can_edit_pipeline']);
    $db->exec('ALTER TABLE conversations RENAME COLUMN metadata_json TO fixture_private_metadata');[$status,$r]=entryHttpRequest($address,$fixture,1,$detail);
    entryHttpCheck('Optional geography storage failure cannot disable sales editing or the remaining card',
        $status===200&&$r['source']['first_entry_region']===null&&$r['source']['latest_entry_region']===null&&$r['can_edit_pipeline']
        &&!in_array('entry_region',$r['degraded_components'],true)&&$r['trip']===['city'=>'Москва','no_flight'=>true]);
    $db->exec('DELETE FROM manager_projects WHERE manager_id=1');[$status,$r,$raw]=entryHttpRequest($address,$fixture,1,$detail);
    entryHttpCheck('Revoked project access immediately denies the whole entry display',$status===404&&!str_contains($raw,'first_entry_region'));
    echo 'TOTAL '.$passed.' | PASS '.$passed.' | FAIL 0'."\n";
}catch(Throwable $e){if(is_file($directory.'/server.log'))fwrite(STDERR,substr((string)file_get_contents($directory.'/server.log'),-3000));throw$e;}
finally{
    if(is_resource($process)){proc_terminate($process);proc_close($process);}if(is_resource($log))fclose($log);
    if(is_dir($directory)){$files=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);foreach($files as$f){if($f->isLink()||!$f->isDir())unlink($f->getPathname());else rmdir($f->getPathname());}rmdir($directory);}
}
