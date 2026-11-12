<?php
declare(strict_types=1);
// Explicit owner-authorized replacement. The original UNKNOWN attempt is retained.
ini_set('display_errors','0');
$root='/var/www/anytoour/data/www/app.anytoour.ru';$expected='6e1c61915d0afc35ec8198d59021fe5a6bddadb6';
$originalHash='a15e7de90307b95d5f118cdd082a16bf52076cb78d15b1e962c48a8ebdd61577';
$channel='-80917954947727';$pdo=null;$claimed=false;$retry=null;$store=null;
function check(bool $ok,string $reason):void{if(!$ok)throw new RuntimeException($reason);}
function out(array $value):void{echo json_encode($value,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR).PHP_EOL;}
function json(array $value):string{return json_encode($value,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);}
function gitRead(array $args):string{
    $p=proc_open(array_merge(['git','-C','/var/www/anytoour/data/www/app.anytoour.ru'],$args),[['pipe','r'],['pipe','w'],['pipe','w']],$pipes);
    check(is_resource($p),'checkout_unavailable');fclose($pipes[0]);$s=stream_get_contents($pipes[1]);fclose($pipes[1]);stream_get_contents($pipes[2]);fclose($pipes[2]);check(proc_close($p)===0,'checkout_unavailable');return trim($s);
}
function invoke(object $target,string $method,...$args){return(new ReflectionMethod($target,$method))->invoke($target,...$args);}
function ack(array $r):array{
    $code=$r['data']['code']??null;$mid=$r['data']['message']['body']['mid']??null;
    return ['http'=>(int)($r['http']??0),'transport_error'=>($r['transport_error']??true)===true,
        'code'=>is_string($code)&&preg_match('/\A[A-Za-z0-9_.-]{1,100}\z/D',$code)?$code:null,
        'message_id'=>is_string($mid)&&preg_match('/\A[A-Za-z0-9_.-]{1,191}\z/D',$mid)?$mid:null];
}
function annotate(PDO $pdo,AppleService39PilotPublicationStore $store,array $original,array $retry):array{
    $current=$store->operation('model-iphone-8','create');
    check(is_array($current)&&$current['id']===$original['id']&&$current['payload_sha256']===$original['payload_sha256']&&$current['state']==='unknown'&&(int)$current['write_attempts']===1,'original_changed');
    $outcome=$current['outcome'];$outcome['owner_authorized_retry']=$retry;
    $q=$pdo->prepare("UPDATE appleservice39_pilot_operations SET outcome_json=?,updated_at=? WHERE id=? AND payload_sha256=? AND state='unknown' AND write_attempts=1 AND outcome_json=?");
    $q->execute([json($outcome),gmdate('Y-m-d H:i:s'),$current['id'],$current['payload_sha256'],$current['outcome_json']]);
    check($q->rowCount()===1,'retry_annotation_conflict');return $store->operation('model-iphone-8','create');
}
try{
    check(PHP_SAPI==='cli'&&realpath($root)===$root,'cli_target_required');
    $provenance=static fn():bool=>gitRead(['rev-parse','HEAD'])===$expected&&gitRead(['status','--porcelain','--untracked-files=no'])==='';
    check($provenance(),'checkout_changed');
    require_once $root.'/config.php';require_once $root.'/services/ConversationDb.php';require_once $root.'/services/AppleService39PilotPublisher.php';
    $private='/var/www/anytoour/data/config/max-search.php';check(is_file($private)&&!is_link($private),'protected_config_unavailable');$configHash=hash_file('sha256',$private);
    $safe=static fn():bool=>$provenance()&&is_file($private)&&!is_link($private)&&hash_file('sha256',$private)===$configHash;
    $pdo=ConversationDb::connection();check(!$pdo->inTransaction(),'autocommit_required');$store=new AppleService39PilotPublicationStore($pdo);
    $row=$store->operation('model-iphone-8','create');
    check(is_array($row)&&$row['state']==='unknown'&&(int)$row['write_attempts']===1&&$row['external_message_id']===null&&$row['public_url']===null&&$row['payload_sha256']===$originalHash&&(int)$row['started_ms']===1794482908633&&!isset($row['outcome']['owner_authorized_retry']),'original_unknown_changed');
    $request=$store->coverageRequest('model-iphone-8','create');check($request===$row['request']&&hash('sha256',json($request))===$originalHash,'original_request_changed');
    $manifest=AppleService39PilotManifest::installed();
    $publisher=new AppleService39PilotPublisher($pdo,$manifest,$channel,$safe,null,null,new AppleService39CatalogueRevision($manifest),new AppleService39SearchIntentRevision($manifest));
    $store->assertBinding(invoke($publisher,'preflight'));
    $connection=ProjectMaxConnection::resolve('appleservice39');check($connection!==null,'connection_unavailable');$transport=new AppleService39ChannelTransport($connection,$channel);
    check($safe(),'checkout_changed');
    $retry=['protocol'=>'owner-authorized-replacement-v1','authorization'=>'Pavel: duplicate post permitted, finish AppleService39 (2026-11-12)',
        'original_run_id'=>43208426540,'original_started_ms'=>1794482908633,'original_request_sha256'=>$originalHash,'original_state'=>'unknown',
        'original_outcome'=>$row['outcome'],'original_write_attempts'=>1,'replacement_write_attempts'=>1,'total_write_attempts'=>2,
        'replacement_started_ms'=>(int)floor(microtime(true)*1000),'state'=>'write_started'];
    $current=$row;$currentOutcome=$current['outcome'];$currentOutcome['owner_authorized_retry']=$retry;
    $q=$pdo->prepare("UPDATE appleservice39_pilot_operations SET outcome_json=?,updated_at=? WHERE id=? AND payload_sha256=? AND state='unknown' AND write_attempts=1 AND external_message_id IS NULL AND public_url IS NULL AND outcome_json=?");
    $q->execute([json($currentOutcome),gmdate('Y-m-d H:i:s'),$row['id'],$originalHash,$row['outcome_json']]);check($q->rowCount()===1,'replacement_already_claimed');$claimed=true;
    $response=$transport->create($request['body']);$receiptAck=ack($response);$retry['provider_ack']=$receiptAck;$retry['state']='response_received';
    annotate($pdo,$store,$row,$retry);out(['stage'=>'owner_replacement_ack','post_id'=>'model-iphone-8','provider'=>$receiptAck,'original_attempt_retained'=>true,'replacement_write_attempts'=>1]);
    check(AppleService39ChannelTransport::accepted($response)&&is_array($response['data']['message']??null),'replacement_not_accepted');
    $message=$response['data']['message'];$mid=$message['body']['mid']??null;check(is_string($mid),'replacement_mid_missing');
    foreach($store->rows() as $other)check($other['external_message_id']!==$mid,'replacement_identity_collision');
    $url=invoke($publisher,'verifyMessageContent',$message,$mid,$request['body'],$request);$image=invoke($publisher,'imagePayload',$message);$photo=(string)($image['photo_id']??'');
    check(preg_match('/\A[1-9][0-9]{0,18}\z/D',$photo)===1,'replacement_photo_invalid');
    $retry['response_photo_id']=$photo;$retry['response_message_sha256']=hash('sha256',json($message));$retry['response_url']=$url;annotate($pdo,$store,$row,$retry);
    $wire=$transport->message($mid);check(AppleService39ChannelTransport::accepted($wire),'replacement_readback_failed');$native=$wire['data']['message']??$wire['data'];
    check(is_array($native),'replacement_readback_failed');$nativeUrl=invoke($publisher,'verifyMessageContent',$native,$mid,$request['body'],$request);$nativeImage=invoke($publisher,'imagePayload',$native);
    check($nativeUrl===$url&&(string)($nativeImage['photo_id']??'')===$photo,'replacement_readback_changed');$store->assertBinding(invoke($publisher,'preflight'));check($safe(),'checkout_changed');
    $row=$store->operation('model-iphone-8','create');$store->rememberMessage($row,$mid,$url);
    $imageReceipt=['kind'=>'create_response','message_id'=>$mid,'photo_id'=>$photo,'source_sha256'=>$request['source_sha256'],'upload_token_sha256'=>$request['upload_token_sha256'],'request_sha256'=>$originalHash,'revision_sha256'=>$request['revision_sha256']];
    $row=$store->operation('model-iphone-8','create');$store->rememberCoverageImageReceipt($row,$imageReceipt);$retry['state']='verified';$retry['replacement_message_id']=$mid;$retry['replacement_url']=$url;
    $row=$store->operation('model-iphone-8','create');$store->finish($row,'verified',['readback'=>true,'revision_sha256'=>$request['revision_sha256'],'original_attempt_outcome'=>'unknown','owner_authorized_retry'=>$retry]);
    out(['ok'=>true,'stage'=>'owner_replacement_verified','post_id'=>'model-iphone-8','url'=>$url,'original_attempt_outcome'=>'unknown','total_write_attempts'=>2,'provider_writes'=>1]);
    // All later sends remain in the installed executor and its durable one-attempt claims.
    $wireTap=static function(array $r)use($transport):array{
        $url=parse_url($r['url']);check(($url['scheme']??'')==='https'&&($url['host']??'')==='platform-api2.max.ru','transport_target_changed');$path=$url['path']??'';$query=[];parse_str($url['query']??'',$query);
        if($r['method']==='GET'){
            if($path==='/me')return $transport->me();if($path==='/chats/-80917954947727')return $transport->channel();if($path==='/chats/-80917954947727/members/me')return $transport->membership();if($path==='/chats/-80917954947727/pin')return $transport->pin();
            if(preg_match('~\A/messages/([A-Za-z0-9_.-]{1,191})\z~D',$path,$m))return $transport->message($m[1]);
        }
        if($r['method']==='POST'&&$path==='/messages'&&($query['chat_id']??'')==='-80917954947727'){$v=$transport->create($r['body']);out(['stage'=>'canonical_create_ack','provider'=>ack($v)]);return $v;}
        if($r['method']==='PUT'&&$path==='/messages'&&is_string($query['message_id']??null)){$v=$transport->edit($query['message_id'],$r['body']);out(['stage'=>'canonical_menu_ack','provider'=>ack($v)]);return $v;}
        throw new RuntimeException('unexpected_transport_operation');
    };
    $publisher=new AppleService39PilotPublisher($pdo,$manifest,$channel,$safe,$wireTap,null,new AppleService39CatalogueRevision($manifest),new AppleService39SearchIntentRevision($manifest));
    out(['stage'=>'coverage_apply_started','original_attempt_retained'=>true]);$result=$publisher->run('coverage_apply');out($result);
    check(($result['coverage_publication_complete']??false)===true,'coverage_incomplete');
}catch(Throwable $e){
    if($claimed&&is_array($retry)&&$store instanceof AppleService39PilotPublicationStore&&$pdo instanceof PDO){try{$current=$store->operation('model-iphone-8','create');if($current['state']==='unknown'){$retry['state']='stopped';$retry['failure_class']='replacement_or_readback_failed';annotate($pdo,$store,$current,$retry);}}catch(Throwable $ignored){}}
    $code=$e->getMessage();out(['ok'=>false,'state'=>'stopped','reason'=>preg_match('/\A[a-z][a-z0-9_]{1,99}\z/D',$code)?$code:'owner_replacement_failed','automatic_retry'=>false]);exit(1);
}
