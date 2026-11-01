<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once __DIR__.'/support/manager-request-profile-http.php';
// The canonical no-argument runner includes both isolated scenarios; callers
// can still select one mode for a focused local diagnostic.
if(!isset($argv[1])){
    foreach(['default','hotel_focus'] as $selected){
        $child=proc_open([PHP_BINARY,__FILE__,$selected],[0=>STDIN,1=>['pipe','w'],2=>['pipe','w']],$childPipes);
        if(!is_resource($child))throw new RuntimeException('fixture_child_failed');
        $output=stream_get_contents($childPipes[1]);$errors=stream_get_contents($childPipes[2]);
        fclose($childPipes[1]);fclose($childPipes[2]);echo $output;fwrite(STDERR,$errors);
        $exit=proc_close($child);if($exit!==0)exit($exit>0?$exit:1);
    }
    exit(0);
}
$mode=$argv[1];
$directory=sys_get_temp_dir().'/manager-request-profile-'.bin2hex(random_bytes(8));mkdir($directory,0700);$fixture=managerRequestProfileHttpFixture($directory,false,$mode);
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
    if($mode==='hotel_focus'){profileHttpHotelFocusChecks($address,$fixture,$get,$identity);}else{
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
    }
    echo 'TOTAL '.$passed.' | PASS '.$passed.' | FAIL 0'."\n";
}catch(Throwable $error){fwrite(STDERR,substr((string)file_get_contents($directory.'/server.log'),-5000));throw$error;}finally{
    proc_terminate($process);proc_close($process);fclose($log);
    $files=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);
    foreach($files as $file){if($file->isLink()||!$file->isDir())unlink($file->getPathname());else rmdir($file->getPathname());}rmdir($directory);
}

/** Full private HTTP -> stored profile -> canonical model input; no provider invocation. */
function profileHttpHotelFocusChecks(string $address,array $fixture,array $get,array $identity): void
{
    require_once dirname(__DIR__).'/services/ManagerAiDraftService.php';
    $db=new PDO('sqlite:'.$fixture['database'],null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
    (new ReflectionProperty(ConversationDb::class,'pdo'))->setValue(null,$db);
    [$status,$view]=profileHttpRequest($address,$fixture,1,$get);
    $candidate=$view['hotel_focus_candidates'][0]??null;
    profileHttpCheck('hotel focus actual API offers exact earlier native card after ordinary clarification',
        $status===200&&count($view['hotel_focus_candidates'])===1&&$candidate['message_id']===12&&$view['latest_message_id']===13
        &&$candidate['hotel_ref']['catalog']==='anytour'&&preg_match('/^[a-f0-9]{64}$/',$candidate['revision'])===1);
    profileHttpCheck('hotel focus native candidate is not automatically a saved current binding',empty($view['profile']['hotel_focus']));
    $make=static function(array $v,?array $focus)use($identity):array{
        $command=requestProfileCommand($v);$suggestion=$v['party_suggestion']??null;
        if($suggestion)$command['fields']['party']=['value'=>$suggestion['value'],'kind'=>'customer_statement','importance'=>'hard','note'=>'',
            'source_id'=>$suggestion['source_id'],'quote'=>$suggestion['quote']];
        else $command['fields']['party']=['value'=>['adults'=>2,'children'=>1,'child_ages'=>[12]],'kind'=>'customer_statement','importance'=>'hard','note'=>'',
            'source_id'=>13,'quote'=>'На те же даты только на 3-х, 2 взрослых и один ребёнок 12 лет.'];
        return $command+['action'=>'save','csrf'=>$identity['csrf'],'conversation_id'=>1,'hotel_focus'=>$focus];
    };
    $wire=static function():array{
        $snapshot=ManagerAiDraftContextService::snapshot(1,1);
        if(empty($snapshot['ok']))throw new RuntimeException('hotel focus canonical capture unavailable');
        $input=ManagerAiDraftService::prepareInput($snapshot['context'],$snapshot['company_knowledge'],null,$snapshot['reference_context']);
        return [$snapshot,$input['context']??[]];
    };
    [$before,$beforeInput]=$wire();
    profileHttpCheck('hotel focus unconfirmed ordinary followup keeps hotel facts held',($beforeInput['hotel_reference']['status']??'')==='held'&&empty($beforeInput['hotel_reference']['facts']));
    $bad=$make($view,['message_id'=>12,'revision'=>str_repeat('0',64)]);
    [$status,$r]=profileHttpRequest($address,$fixture,1,$bad);
    profileHttpCheck('hotel focus mismatched source revision cannot write profile',in_array($status,[409,422],true)&&(int)$db->query('SELECT COUNT(*) FROM manager_request_profiles')->fetchColumn()===0);
    $command=$make($view,['message_id'=>$candidate['message_id'],'revision'=>$candidate['revision']]);
    [$status,$r]=profileHttpRequest($address,$fixture,1,$command);
    profileHttpCheck('hotel focus explicit actual HTTP confirmation saves once',$status===200&&$r['saved_version']===1&&!$r['replayed']);
    [$status,$r]=profileHttpRequest($address,$fixture,1,$command);
    profileHttpCheck('hotel focus exact lost-ack retry does not duplicate confirmation',$status===200&&$r['replayed']&&(int)$db->query('SELECT COUNT(*) FROM manager_request_profiles')->fetchColumn()===1);
    [$status,$confirmed]=profileHttpRequest($address,$fixture,1,$get);$focus=$confirmed['profile']['hotel_focus'];
    profileHttpCheck('hotel focus readback binds actual family while preserving independent date citation and quoted two-person tariff',
        $status===200&&$focus['status']==='confirmed'&&$confirmed['profile']['fields']['party']['value']===['adults'=>2,'children'=>1,'child_ages'=>[12]]
        &&$confirmed['profile']['fields']['dates']['citation']['message_id']===10&&$candidate['public_offer']['adults']===2&&$candidate['public_offer']['children']===0);
    [$saved,$savedInput]=$wire();$reference=$savedInput['hotel_reference']??[];
    profileHttpCheck('hotel focus confirmed latest clarification reaches canonical model input with approved facts and source limits',
        ($reference['status']??null)==='available'&&$reference['hotel_ref']===$candidate['hotel_ref']&&!empty($reference['facts'])&&!empty($reference['sources'])
        &&!empty($reference['material_limits'])&&$reference['coverage']['complete']===false&&$reference['price_authority']==='none'
        &&$reference['future_operation_verified']===false&&$saved['revision']!==$before['revision']);
    profileHttpCheck('hotel focus exact input contains latest ordinary tourist clarification and no donor binding',
        in_array(13,array_column($savedInput['messages'],'id'),true)&&!str_contains(json_encode($reference),'external_user_id')&&!str_contains(json_encode($reference),'binding_json'));
    requestProfileMessage($db,14,'customer','А питание для ребёнка тоже нужно проверить.');
    [$status,$later]=profileHttpRequest($address,$fixture,1,$get);[$stale,$staleInput]=$wire();
    profileHttpCheck('hotel focus next clarification invalidates earlier confirmation and prepared context revision',
        $status===200&&$later['profile']['hotel_focus']['status']==='needs_review'&&($staleInput['hotel_reference']['status']??'')==='held'
        &&$stale['revision']!==$saved['revision']&&$stale['preparation_revision']!==$saved['preparation_revision']);
    $staleCommand=$make($confirmed,['message_id'=>12,'revision'=>$candidate['revision']]);
    [$status,$r]=profileHttpRequest($address,$fixture,1,$staleCommand);
    profileHttpCheck('hotel focus stale observed context cannot silently renew acknowledgement',$status===409&&$r['error']==='context_changed');
    $fresh=$later['hotel_focus_candidates'][0];
    [$status,$r]=profileHttpRequest($address,$fixture,1,$make($later,['message_id'=>12,'revision'=>$fresh['revision']]));
    profileHttpCheck('hotel focus explicit recheck after followup reuses exact offer safely',$status===200&&$r['saved_version']===2);
    [$status,$view]=profileHttpRequest($address,$fixture,1,$get);[$again,$againInput]=$wire();
    profileHttpCheck('hotel focus reconfirmed canonical facts preserve property source and recorded tariff limitations',
        $view['profile']['hotel_focus']['status']==='confirmed'&&$againInput['hotel_reference']['facts']===$reference['facts']
        &&$againInput['hotel_reference']['sources']===$reference['sources']&&$againInput['hotel_reference']['material_limits']===$reference['material_limits']);
    $db->exec("UPDATE messages SET text=text||' Источник изменён.' WHERE id=12");
    [$status,$changed]=profileHttpRequest($address,$fixture,1,$get);[, $changedInput]=$wire();
    profileHttpCheck('hotel focus same-id native source edit withdraws applied hotel facts',
        $status===200&&$changed['profile']['hotel_focus']['status']==='source_changed'&&($changedInput['hotel_reference']['status']??'')==='held');
    [$status,$r]=profileHttpRequest($address,$fixture,1,$make($changed,null));
    [$readStatus,$cleared]=profileHttpRequest($address,$fixture,1,$get);
    profileHttpCheck('hotel focus explicit clear removes current binding without guessing another offer',$status===200&&$readStatus===200&&empty($cleared['profile']['hotel_focus']));
    $new=$make($cleared,null);$new['new_trip']=true;$new['boundary_message_id']=14;$new['label']='Отдельная новая поездка';$new['fields']=[];
    [$status,$r]=profileHttpRequest($address,$fixture,1,$new);[$readStatus,$newView]=profileHttpRequest($address,$fixture,1,$get);
    profileHttpCheck('hotel focus separate trip does not inherit earlier hotel or pre-boundary candidate',
        $status===200&&$readStatus===200&&$newView['profile']['trip_id']!==$cleared['profile']['trip_id']&&empty($newView['profile']['hotel_focus'])&&$newView['hotel_focus_candidates']===[]);
    $db->exec('DELETE FROM manager_projects WHERE manager_id=1');[$status,$r,$body]=profileHttpRequest($address,$fixture,1,$get);
    profileHttpCheck('hotel focus access revoked before lazy read hides hotel private profile and source data',$status===403&&!str_contains($body,$candidate['selected_hotel']));
    profileHttpCheck('hotel focus HTTP flow performs no customer send or read-marker advancement',
        (int)$db->query("SELECT COUNT(*) FROM messages WHERE sender_type='manager'")->fetchColumn()===1
        &&(int)$db->query('SELECT last_read_message_id FROM manager_conversation_reads WHERE manager_id=1')->fetchColumn()===10);
}
