<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once __DIR__.'/fixtures/manager_send_attempt_fixture.php';
require_once __DIR__.'/fixtures/manager_media_attempt_http_fixture.php';

$root=dirname(__DIR__);$directory=sys_get_temp_dir().'/manager-media-http-'.bin2hex(random_bytes(8));
mkdir($directory,0700);$server=null;$passed=0;
function mediaHttpCheck(string $name,bool $ok):void{global$passed;if(!$ok)throw new RuntimeException('FAIL media HTTP '.$name);$passed++;echo 'PASS media HTTP '.$name."\n";}
function mediaHttpId(int $id):string{return str_pad(dechex($id),32,'0',STR_PAD_LEFT);}
function mediaHttpLines(string $path):array{return array_map(static fn($line)=>json_decode($line,true),is_file($path)?file($path,FILE_IGNORE_NEW_LINES|FILE_SKIP_EMPTY_LINES):[]);}
function mediaHttpState(PDO $pdo):array
{
    $state=[];foreach(['projects','managers','manager_projects','customers','conversations','conversation_sources','manager_group_members','messages','manager_conversation_reads'] as $table)$state[$table]=$pdo->query('SELECT * FROM '.$table.' ORDER BY 1,2')->fetchAll(PDO::FETCH_ASSOC);return $state;
}
try{
    $actual=mediaAttemptHttpApp($directory);
    $pdo=new PDO('sqlite:'.$directory.'/fixture.sqlite',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
    sendAttemptSchema($pdo);sendAttemptBind($pdo);
    $file=$directory.'/synthetic-upload.txt';file_put_contents($file,'Synthetic immutable upload bytes');
    foreach([1=>'confirmed',2=>'unknown',3=>'processing',4=>'rejected'] as $id=>$state){
        ManagerSendAttemptService::executeMedia(7,101,mediaHttpId($id),$file,'private-local-name.txt','text/plain','Private caption',static fn()=>['ok'=>$state==='confirmed','failure'=>$state==='rejected'?['category'=>'blocked','http_code'=>403,'message'=>'Private provider payload']:null]);
        if($state==='processing')$pdo->prepare('UPDATE manager_send_attempts SET state=? WHERE operation_id=?')->execute([$state,mediaHttpId($id)]);
    }
    ManagerSendAttemptService::execute(7,101,mediaHttpId(5),'Legacy private text',null,static fn()=>['ok'=>true]);
    $sessions=[];
    session_name('anytour_manager_panel');session_save_path($directory.'/sessions');
    foreach([7,8,9,10] as $actor){$sid=bin2hex(random_bytes(16));session_id($sid);session_start();$_SESSION=['manager_id'=>$actor,'csrf'=>'synthetic-media-csrf'];session_write_close();$sessions[$actor]=$sid;}
    foreach($actual as $path)mediaHttpCheck('exact current '.$path,hash_file('sha256',$root.'/'.$path)===hash_file('sha256',$directory.'/'.$path));
    $socket=stream_socket_server('tcp://127.0.0.1:0',$errno,$error);if(!$socket)throw new RuntimeException('Cannot allocate loopback');$address=stream_socket_get_name($socket,false);fclose($socket);
    $server=proc_open([PHP_BINARY,'-d','session.save_path='.$directory.'/sessions','-d','upload_tmp_dir='.$directory,'-S',$address,'-t',$directory],[0=>['pipe','r'],1=>['file',$directory.'/server.log','a'],2=>['file',$directory.'/server.log','a']],$pipes);if(!is_resource($server))throw new RuntimeException('Cannot start loopback');fclose($pipes[0]);
    $ready=false;for($i=0;$i<100;$i++){if($probe=@stream_socket_client('tcp://'.$address,$e,$s,.05)){fclose($probe);$ready=true;break;}usleep(10000);}if(!$ready)throw new RuntimeException('Loopback not ready');
    $request=static function(string $path,array $data,?int $actor=7,?array $upload=null)use($address,$sessions):array{
        $headers=[];if($actor!==null)$headers[]='Cookie: anytour_manager_panel='.$sessions[$actor];
        if($upload===null){$headers[]='Content-Type: application/json';$body=json_encode($data,JSON_THROW_ON_ERROR);}else{
            $boundary='fixture-'.bin2hex(random_bytes(12));$headers[]='Content-Type: multipart/form-data; boundary='.$boundary;$body='';
            foreach($data as $key=>$value)$body.='--'.$boundary."\r\nContent-Disposition: form-data; name=\"".$key."\"\r\n\r\n".$value."\r\n";
            if(isset($upload['bytes']))$body.='--'.$boundary."\r\nContent-Disposition: form-data; name=\"file\"; filename=\"private-local-name.txt\"\r\nContent-Type: image/jpeg\r\n\r\n".$upload['bytes']."\r\n";
            $body.='--'.$boundary."--\r\n";
        }
        $context=stream_context_create(['http'=>['method'=>'POST','header'=>implode("\r\n",$headers),'content'=>$body,'ignore_errors'=>true,'timeout'=>5]]);
        $response=file_get_contents('http://'.$address.$path,false,$context);$status=0;foreach($http_response_header??[]as$header)if(preg_match('/^HTTP\/\S+ (\d+)/',$header,$m))$status=(int)$m[1];
        return ['status'=>$status,'json'=>json_decode((string)$response,true),'body'=>(string)$response];
    };
    $base=['csrf'=>'synthetic-media-csrf','expected_manager_id'=>7,'conversation_id'=>101,'operation_id'=>mediaHttpId(1)];
    $api=static fn(array $data,?int $actor=7)=>$request('/manager/api.php',$data,$actor);
    $lookup=$base+['action'=>'media_receipt'];$protected=mediaHttpState($pdo);$journal=$pdo->query('SELECT * FROM manager_send_attempts ORDER BY operation_id')->fetchAll();
    touch($directory.'/read-only');
    foreach([1=>'confirmed',2=>'unknown',3=>'processing',4=>'rejected']as$id=>$state){
        $r=$api(array_replace($lookup,['operation_id'=>mediaHttpId($id)]));
        mediaHttpCheck('query_only actual receipt '.$state,$r['status']===200&&$r['json']['ok']===true&&$r['json']['attempt']===['kind'=>'media','state'=>$state,'media_type'=>'file','caption_included'=>true]);
        mediaHttpCheck('receipt omits raw caption filename recipient and provider payload',!preg_match('/Private|private-local|synthetic101|recipient_hash|request_hash|context_hash/',$r['body']));
    }
    mediaHttpCheck('receipt needs actual session',$api($lookup,null)['status']===401);
    mediaHttpCheck('inactive session cannot read a receipt',$api($lookup,10)['status']===401);
    foreach(['','wrong']as$csrf)mediaHttpCheck('receipt requires CSRF',$api(array_replace($lookup,['csrf'=>$csrf]))['status']===403);
    $r=$api(array_replace($lookup,['conversation_id'=>'101']));mediaHttpCheck('JSON conversation ID remains strictly integer',$r['status']===400&&$r['json']['error']==='invalid_send_attempt');
    foreach([8,9]as$actor){$r=$api(array_replace($lookup,['expected_manager_id'=>$actor]),$actor);mediaHttpCheck('another visible actor cannot read owner receipt',$r['status']===200&&$r['json']===['ok'=>true,'attempt'=>['kind'=>'media','state'=>'not_found']]);}
    foreach([102,104,105]as$cid){$r=$api(array_replace($lookup,['conversation_id'=>$cid]));mediaHttpCheck('wrong conversation project or source cannot expose receipt',$r['status']===409&&!isset($r['json']['attempt']));}
    $r=$api(array_replace($lookup,['operation_id'=>mediaHttpId(5)]));mediaHttpCheck('text receipt is never projected as media',$r['status']===409&&$r['json']['error']==='send_attempt_mismatch');
    $r=$api(array_replace($lookup,['operation_id'=>mediaHttpId(99)]));mediaHttpCheck('not_found has no implied delivery or cancellation',$r['json']===['ok'=>true,'attempt'=>['kind'=>'media','state'=>'not_found']]);
    foreach(['media_receipt','media_cancel']as$action){
        foreach([null,0,'7',9]as$expected){$r=$api(array_replace($lookup,['action'=>$action,'expected_manager_id'=>$expected]));mediaHttpCheck($action.' rejects missing invalid or switched actor with unchanged valid CSRF',$r['status']===409&&$r['json']===['ok'=>false,'error'=>'actor_changed']);}
    }
    mediaHttpCheck('all receipt and rejection requests leave database byte values unchanged',mediaHttpState($pdo)===$protected&&$pdo->query('SELECT * FROM manager_send_attempts ORDER BY operation_id')->fetchAll()===$journal);
    mediaHttpCheck('receipt does not read transcript or read markers or create a cache',!preg_match('/\b(?:FROM|JOIN)\s+(?:messages|manager_conversation_reads)\b/i',implode("\n",mediaHttpLines($directory.'/queries.jsonl')))&&!is_dir($directory.'/runtime')&&mediaHttpLines($directory.'/effects.jsonl')===[]);
    unlink($directory.'/read-only');
    $pdo->exec('DELETE FROM manager_projects WHERE manager_id=7');$revoked=mediaHttpState($pdo);touch($directory.'/read-only');$r=$api($lookup);mediaHttpCheck('fresh project revocation blocks old receipt without writes',$r['status']===409&&$r['json']['error']==='forbidden'&&mediaHttpState($pdo)===$revoked);unlink($directory.'/read-only');$pdo->exec('INSERT INTO manager_projects VALUES(7,1)');
    $pdo->exec("UPDATE conversations SET external_chat_id='different-recipient' WHERE id=101");touch($directory.'/read-only');$r=$api($lookup);mediaHttpCheck('changed actual recipient blocks old receipt',$r['status']===409&&$r['json']['error']==='send_attempt_mismatch');unlink($directory.'/read-only');$pdo->exec("UPDATE conversations SET external_chat_id='synthetic101' WHERE id=101");
    $cancel=array_replace($lookup,['action'=>'media_cancel','operation_id'=>mediaHttpId(99)]);$r=$api($cancel);mediaHttpCheck('one explicit cancel acknowledges exactly one typed tombstone',$r['status']===200&&$r['json']===['ok'=>true,'attempt'=>['kind'=>'media','state'=>'cancelled']]&&(int)$pdo->query('SELECT COUNT(*) FROM manager_send_attempts')->fetchColumn()===6);
    foreach([1=>'confirmed',2=>'unknown',3=>'processing']as$id=>$state){$r=$api(array_replace($cancel,['operation_id'=>mediaHttpId($id)]));mediaHttpCheck('cancel cannot reverse '.$state,$r['status']===200&&$r['json']['attempt']['state']===$state);}
    mediaHttpCheck('cancel changes no protected business state or provider/cache',mediaHttpState($pdo)===$protected&&mediaHttpLines($directory.'/effects.jsonl')===[]&&!is_dir($directory.'/runtime'));
    $upload=['csrf'=>'synthetic-media-csrf','expected_manager_id'=>'7','conversation_id'=>101,'operation_id'=>mediaHttpId(200),'caption'=>'Private caption <test>'];$bytes=['bytes'=>(string)file_get_contents($file)];
    mediaHttpCheck('anonymous multipart upload is rejected',$request('/manager/media-upload.php',$upload,null,$bytes)['status']===401);
    mediaHttpCheck('multipart upload requires CSRF',$request('/manager/media-upload.php',array_replace($upload,['csrf'=>'wrong']),7,$bytes)['status']===403);
    mediaHttpCheck('upload requires durable operation ID',$request('/manager/media-upload.php',array_replace($upload,['operation_id'=>'bad']),7,$bytes)['status']===400);
    mediaHttpCheck('upload requires real multipart file',$request('/manager/media-upload.php',$upload,7,[])['status']===400);
    foreach(['','0','07','9']as$expected){$r=$request('/manager/media-upload.php',array_replace($upload,['expected_manager_id'=>$expected]),7,$bytes);mediaHttpCheck('multipart rejects noncanonical or switched actor despite unchanged valid CSRF',$r['status']===409&&$r['json']===['ok'=>false,'error'=>'actor_changed']);}
    mediaHttpCheck('invalid upload cannot reserve call provider or create preview',(int)$pdo->query('SELECT COUNT(*) FROM manager_send_attempts')->fetchColumn()===6&&mediaHttpLines($directory.'/effects.jsonl')===[]&&!is_dir($directory.'/runtime'));
    $r=$request('/manager/media-upload.php',$upload,7,$bytes);mediaHttpCheck('actual multipart reaches existing sender and typed confirmed outcome',$r['status']===200&&$r['json']===['ok'=>true,'attempt'=>['kind'=>'media','state'=>'confirmed','media_type'=>'file','caption_included'=>true]]);
    $effects=mediaHttpLines($directory.'/effects.jsonl');$provider=$effects[0]??[];
    mediaHttpCheck('sender sees committed processing and released session',$provider['event']==='provider'&&$provider['committed_processing']&&$provider['transaction']===false&&$provider['session_active']===false);
    mediaHttpCheck('actual upload bytes MIME and caption reach synthetic adapter',$provider['digest']===hash_file('sha256',$file)&&$provider['mime']==='text/plain'&&$provider['type']==='file'&&$provider['caption']==='Private caption &lt;test&gt;'&&array_column($effects,'event')===['provider','mirror']);
    $r=$request('/manager/media-upload.php',$upload,7,$bytes);mediaHttpCheck('duplicate multipart never calls adapter again',$r['status']===200&&$r['json']['attempt']['state']==='confirmed'&&mediaHttpLines($directory.'/effects.jsonl')===$effects);
    $r=$request('/manager/media-upload.php',array_replace($upload,['caption'=>'Changed']),7,$bytes);mediaHttpCheck('changed caption cannot reuse uploaded operation',$r['status']===409&&$r['json']['error']==='send_attempt_mismatch'&&mediaHttpLines($directory.'/effects.jsonl')===$effects);
    $r=$request('/manager/media-upload.php',array_replace($upload,['operation_id'=>mediaHttpId(99)]),7,$bytes);mediaHttpCheck('delayed real upload remains cancelled after tombstone',$r['status']===200&&$r['json']['attempt']['state']==='cancelled'&&mediaHttpLines($directory.'/effects.jsonl')===$effects);
    $cacheBefore=glob($directory.'/runtime/manager-media/*');touch($directory.'/read-only');$r=$api(array_replace($lookup,['operation_id'=>mediaHttpId(200)]));mediaHttpCheck('post-upload reload recovers exact result without dispatch or cache changes',$r['status']===200&&$r['json']['attempt']['state']==='confirmed'&&mediaHttpLines($directory.'/effects.jsonl')===$effects&&glob($directory.'/runtime/manager-media/*')===$cacheBefore);
    mediaHttpCheck('HTTP recovery and cancel preserve transcript read markers assignment and shifts',mediaHttpState($pdo)===$protected);
    echo 'TOTAL '.$passed.' | PASS '.$passed.' | FAIL 0'."\n";
}catch(Throwable $error){if(is_file($directory.'/server.log'))fwrite(STDERR,substr((string)file_get_contents($directory.'/server.log'),-6000));throw$error;}finally{
    if(is_resource($server)){proc_terminate($server);proc_close($server);}
    $remove=static function(string $path)use(&$remove):void{if(is_file($path)||is_link($path)){unlink($path);return;}if(is_dir($path)){foreach(scandir($path)as$name)if($name!=='.'&&$name!=='..')$remove($path.'/'.$name);rmdir($path);}};$remove($directory);
}
