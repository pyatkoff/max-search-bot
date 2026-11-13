<?php
declare(strict_types=1);
const EXPECTED_INSTALLED_SHA='cc58204bcaae562d237351e7dd8d23c6be9e3e36';
const READINESS_OPERATION='commercial-channel-hotel-feeds-20261113-update10-readiness1';
// Inline helper only. Caller holds the existing global EX and child SH locks.
// No journal, registry, observation or provider write is performed here.
function monitor_native_read_need(bool $ok,string $reason):void {
    if(!$ok)throw new RuntimeException($reason);
}
function monitor_native_read_photo(array $snapshot):string {
    $media=$snapshot['attachments']??[];
    monitor_native_read_need(is_array($media)&&count($media)===1&&($media[0]['type']??null)==='image','native_expected_one_photo');
    $m=$media[0];$id=(string)($m['photo_id']??$m['file_unique_id']??'');
    monitor_native_read_need($id!==''&&preg_match('/\A[A-Za-z0-9_-]+\z/D',$id)===1,'native_photo_stable_id_missing');
    return (string)($m['provider']??'').':'.$id;
}
function monitor_native_read_code($value):?string {
    return is_string($value)&&preg_match('/\A[a-z][a-z0-9_]{0,100}\z/D',$value)===1?$value:null;
}
function monitor_pending_native_read(array $pending,array $journal,string $expectedInstalledSha):array {
    $out=['attempted'=>false,'ok'=>false,'native_confirmed'=>false,'database_session_read_only'=>false,
        'db_write_attempts'=>0,'journal_write_attempts'=>0,'post_write_attempts'=>0,'provider_write_attempts'=>0,
        'provider_gets'=>0,'before_write_callback_calls'=>0,'feed_continuation_authorized'=>false];
    $pdo=null;$observed=null;$providerRawHash=null;
    try {
        $root='/var/www/anytoour/data/www/app.anytoour.ru';
        monitor_native_read_need(PHP_SAPI==='cli'&&realpath(getcwd())===$root&&preg_match('/\A[a-f0-9]{40}\z/D',$expectedInstalledSha)===1
            &&trim((string)shell_exec('git rev-parse HEAD'))===$expectedInstalledSha,'native_runtime_changed');
        $op=$pending['operation']??null;$tv=$pending['tourvisor_hotel_id']??null;
        monitor_native_read_need(is_string($op)&&is_int($tv)&&$tv>0&&preg_match('/\Acommercial-monitor-price-h([1-9][0-9]*)-([a-f0-9]{64})\z/D',$op,$match)===1
            &&(int)$match[1]===$tv&&($journal['operation']??null)===$op&&($pending['phase']??null)==='post_started','native_pending_identity_changed');
        $record=$match[2];$manifest=$journal['manifest_hash']??null;
        monitor_native_read_need(is_string($manifest)&&preg_match('/\A[a-f0-9]{64}\z/D',$manifest)===1
            &&($journal['complete']??null)===false&&!isset($journal['terminal'])&&is_array($journal['posts']??null),'native_original_journal_not_incomplete');
        $matches=[];
        foreach($journal['posts'] as $key=>$row) {
            monitor_native_read_need(is_string($key)&&is_array($row),'native_original_post_shape_changed');
            if(str_starts_with($key,'max:'))$matches[$key]=$row;
        }
        monitor_native_read_need(count($matches)===1,'native_exact_max_plan_required');
        $key=array_key_first($matches);$row=$matches[$key];$plan=$row['plan']??null;
        monitor_native_read_need(is_array($plan)&&($plan['platform']??null)==='max'&&is_string($plan['key']??null)
            &&$key==='max:'.$plan['key']&&($plan['logical_id']??null)===$plan['key'],'native_max_plan_identity_changed');
        foreach(['publication_id','old_version_id'] as $field)monitor_native_read_need(is_int($plan[$field]??null)&&$plan[$field]>0,'native_plan_version_invalid');
        $id=$plan['publication_id'];$mid=$plan['message_id']??null;$token=$plan['token']??null;$text=$plan['text']??null;
        monitor_native_read_need(is_string($mid)&&preg_match('/\A[A-Za-z0-9_.-]{1,191}\z/D',$mid)===1
            &&is_string($token)&&preg_match('/\Ap_[A-Za-z0-9_-]{24}\z/D',$token)===1
            &&is_string($text)&&$text!==''&&is_string($plan['photo_signature']??null)
            &&is_string($plan['post_url']??null)&&preg_match('~\Ahttps://max\.ru/(?:channel_anytour|c/-80492244209683)/[A-Za-z0-9_-]{1,100}\z~D',$plan['post_url'])===1
            &&($plan['update_text']??null)===true,'native_max_plan_fields_changed');
        $price=$plan['monitor_price']??null;$snapshot=$journal['monitor_snapshot']??null;
        monitor_native_read_need(is_array($price)&&($price['record_id']??null)===$record&&($price['tourvisor_hotel_id']??null)===$tv
            &&is_array($snapshot)&&($snapshot['item']['tourvisorHotelId']??null)===$tv
            &&($snapshot['item']['offers'][0]['recordId']??null)===$record,'native_original_price_identity_changed');
        monitor_native_read_need(($row['state']??null)==='text_edit_returned'&&is_int($row['edit_request_start_ms']??null)
            &&$row['edit_request_start_ms']>0&&is_array($row['button_result']??null),'native_max_button_failure_stage_changed');
        $buttonResult=$row['button_result'];
        $out+=['tourvisor_hotel_id'=>$tv,'operation'=>$op,'manifest_hash'=>$manifest,'record_id'=>$record,
            'publication_id'=>$id,'message_id'=>$mid,'previous_version_id'=>$plan['old_version_id'],
            'expected_text_sha256'=>hash('sha256',$text),'expected_photo_signature_sha256'=>hash('sha256',$plan['photo_signature']),
            'original_plan_sha256'=>hash('sha256',json_encode($plan,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)),
            'original_button_state'=>monitor_native_read_code($buttonResult['state']??null),
            'original_button_reason'=>monitor_native_read_code($buttonResult['reason']??null),
            'original_button_reconcile_only'=>is_bool($buttonResult['reconcile_only']??null)?$buttonResult['reconcile_only']:null,
            'original_button_result_sha256'=>hash('sha256',json_encode($buttonResult,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR))];
        $originalReceipt=is_array($buttonResult['receipt']??null)?$buttonResult['receipt']:[];
        $out['original_button_receipt']=['request_count'=>is_int($originalReceipt['request_count']??null)?$originalReceipt['request_count']:null,
            'write_attempts'=>is_int($originalReceipt['write_attempts']??null)?$originalReceipt['write_attempts']:null,'http_results'=>[]];
        foreach(array_slice(is_array($originalReceipt['http_results']??null)?$originalReceipt['http_results']:[],0,5) as $http) {
            if(!is_array($http))continue;
            $out['original_button_receipt']['http_results'][]=['operation'=>monitor_native_read_code($http['operation']??null),
                'http'=>is_int($http['http']??null)?$http['http']:null,'transport_error'=>($http['transport_error']??null)===true];
        }
        ob_start();
        try {require_once $root.'/config.php';require_once $root.'/services/ChannelPostRuntime.php';require_once $root.'/services/ChannelPostButtonTransport.php';}
        finally {ob_end_clean();}
        $pdo=ConversationDb::connection();
        monitor_native_read_need($pdo instanceof PDO&&!$pdo->inTransaction()&&$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql','native_read_connection_invalid');
        $pdo->exec('SET SESSION TRANSACTION READ ONLY');
        $pdo->exec('SET TRANSACTION ISOLATION LEVEL READ COMMITTED');
        $pdo->beginTransaction();$out['database_session_read_only']=true;
        $registry=new ChannelPostRegistryService($pdo);$p=$registry->publication($id);
        monitor_native_read_need(is_array($p)&&($p['project_key']??null)==='anytour'&&($p['platform']??null)==='max'
            &&($p['bot_key']??null)==='id9704048781_1_bot'&&($p['channel_id']??null)==='-80492244209683'
            &&($p['source_key']??null)==='max_anytour_msk1'&&(int)($p['publication_id']??0)===$id
            &&($p['external_message_id']??null)===$mid&&hash_equals($token,(string)($p['start_payload']??'')),'native_registry_identity_changed');
        $channel=$p['channel']??null;$saved=$p['snapshot']??null;
        monitor_native_read_need(is_array($channel)&&(int)($channel['id']??0)===9&&(int)($channel['config_version']??0)===2
            &&($channel['button_text']??null)==='✈️ Узнать стоимость тура'&&($channel['bot_url']??null)==='https://max.ru/id9704048781_1_bot'
            &&is_array($saved)&&($saved['text']??null)===$text&&monitor_native_read_photo($saved)===$plan['photo_signature']
            &&($saved['post_url']??null)===$plan['post_url'],'native_registry_body_or_configuration_changed');
        $meta=$saved['metadata']??[];$version=(int)($p['version_id']??0);
        monitor_native_read_need($version>$plan['old_version_id']&&($meta['monitor_price_record_id']??null)===$record
            &&($meta['source_event_id']??null)===$op.':'.$key&&($meta['observation_kind']??null)==='authorized_admin_content_edit'
            &&($meta['source_revision_ms']??null)===(string)$row['edit_request_start_ms']
            &&($meta['revision_clock_origin']??null)==='authorized_edit_request_start','native_current_version_not_original_edit');
        if(isset($buttonResult['publication_id'])||isset($buttonResult['version_id']))monitor_native_read_need(
            ($buttonResult['publication_id']??null)===$id&&($buttonResult['version_id']??null)===$version,'native_original_button_version_changed');
        $out+=['version_id'=>$version,'registry_state'=>monitor_native_read_code($p['state']??null),
            'registry_write_attempts'=>is_int($p['write_attempts']??null)?$p['write_attempts']:null,
            'registry_claim_present'=>!empty($p['claim_token'])||!empty($p['claimed_at']),
            'registry_text_sha256'=>hash('sha256',$saved['text']),'original_button_version_bound'=>isset($buttonResult['version_id'])];
        $expectedPaths=['/me','/chats/-80492244209683/members/me','/messages/'.rawurlencode($mid)];$getCount=0;
        $request=static function(string $platform,string $method,string $path,array $query,?array $body)use($expectedPaths,&$getCount,&$observed,&$providerRawHash):array {
            monitor_native_read_need($platform==='max'&&$method==='GET'&&$query===[]&&$body===null
                &&isset($expectedPaths[$getCount])&&$path===$expectedPaths[$getCount],'native_provider_get_scope_changed');
            $getCount++;
            $response=ChannelPostApiTransport::buttonRequest($platform,$method,$path,$query,$body);
            if($getCount===3&&empty($response['transport_error'])&&(int)($response['http']??0)===200&&is_array($response['data']??null)) {
                $providerRawHash=hash('sha256',json_encode($response['data'],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR));
                $observed=ChannelPostIncomingAdapter::maxSnapshot($response['data']);
            }
            return $response;
        };
        $out['attempted']=true;$transport=new ChannelPostButtonTransport($request);
        $before=static function()use(&$out):bool{$out['before_write_callback_calls']++;return false;};
        $result=$transport->attempt($p,$before,true);$out['provider_gets']=$getCount;
        $receipt=$result['receipt']??null;
        monitor_native_read_need(is_array($receipt)&&($receipt['write_attempts']??null)===0
            &&($receipt['request_count']??null)===$getCount&&$getCount<=3&&$out['before_write_callback_calls']===0,'native_readonly_transport_boundary_changed');
        $out['transport_state']=monitor_native_read_code($result['state']??null);$out['transport_reason']=monitor_native_read_code($result['reason']??null);
        $out['http_results']=[];
        foreach(($receipt['http_results']??[]) as $http)$out['http_results'][]=['operation'=>monitor_native_read_code($http['operation']??null),
            'http'=>is_int($http['http']??null)?$http['http']:null,'transport_error'=>($http['transport_error']??null)===true];
        $out['native_snapshot_present']=is_array($observed);$out['provider_response_sha256']=$providerRawHash;
        $livePhoto=null;if(is_array($observed)){try{$livePhoto=monitor_native_read_photo($observed);}catch(Throwable $ignored){}}
        $out['actual_text_sha256']=is_array($observed)&&is_string($observed['text']??null)?hash('sha256',$observed['text']):null;
        $out['text_matches']=is_array($observed)&&($observed['text']??null)===$text;
        $out['photo_matches']=$livePhoto===$plan['photo_signature'];
        $out['url_matches']=is_array($observed)&&($observed['post_url']??null)===$plan['post_url'];
        $out['only_expected_button']=is_array($observed)&&ChannelPostIncomingAdapter::containsOnlyButton($observed,ChannelPostButtonTransport::buttonUrl($p),'✈️ Узнать стоимость тура');
        $fresh=$registry->publication($id);
        monitor_native_read_need($fresh===$p,'native_registry_changed_during_read');
        $out['registry_unchanged_during_read']=true;
        $out['native_confirmed']=$getCount===3&&($result['state']??null)==='sent'&&($result['reason']??null)==='already_present'
            &&$out['text_matches']&&$out['photo_matches']&&$out['url_matches']&&$out['only_expected_button'];
        $out['read_at_utc']=gmdate('c');$out['ok']=true;
    }catch(Throwable $e) {
        $out['error']=monitor_native_read_code($e->getMessage())??'native_read_unavailable';
    }finally {
        if($pdo instanceof PDO&&$pdo->inTransaction())$pdo->rollBack();
    }
    return $out;
}

// Functions and main below are bundled locally with the source-pinned GET-only helper.
function pending_read_need(bool $ok,string $why):void{if(!$ok)throw new RuntimeException($why);}
function pending_read_last(string $path,int $cap,int $kind,array &$locks,?callable $each=null):array{
 pending_read_need(!is_link(dirname($path))&&realpath(dirname($path))===dirname($path)&&!is_link($path)&&is_file($path)&&realpath($path)===$path,'journal_path_invalid');
 $before=lstat($path);$stream=fopen($path,'rb');pending_read_need(is_array($before)&&is_resource($stream),'journal_open_failed');$locks[]=$stream;
 pending_read_need(flock($stream,$kind|LOCK_NB),'existing_journal_busy');$locked=fstat($stream);
 pending_read_need(is_array($locked)&&$locked['ino']===$before['ino']&&$locked['dev']===$before['dev']&&($locked['mode']&0170000)===0100000&&$locked['size']>0&&$locked['size']<=$cap,'journal_identity_changed');
 $hash=hash_init('sha256');$bytes=0;$last=null;$lines=0;
 while(!feof($stream)){$line=fgets($stream,16777218);if($line===false){pending_read_need(feof($stream),'journal_read_failed');break;}$bytes+=strlen($line);$lines++;pending_read_need($bytes<=$cap&&strlen($line)<=16777216&&str_ends_with($line,"\n"),'journal_incomplete');hash_update($hash,$line);$last=$line;if($each!==null){$entry=json_decode($line,true,128,JSON_THROW_ON_ERROR);pending_read_need(is_array($entry),'journal_state_invalid');$each($entry);}}
 clearstatcache(true,$path);$after=fstat($stream);$named=lstat($path);
 pending_read_need(is_array($after)&&is_array($named)&&$bytes===$locked['size']&&$after['ino']===$locked['ino']&&$after['dev']===$locked['dev']&&$after['size']===$locked['size']&&$named['ino']===$locked['ino']&&$named['dev']===$locked['dev']&&$named['size']===$locked['size']&&!is_link($path)&&realpath($path)===$path&&is_string($last),'journal_changed_during_read');
 $state=json_decode($last,true,128,JSON_THROW_ON_ERROR);pending_read_need(is_array($state),'journal_state_invalid');
 return ['state'=>$state,'sha256'=>hash_final($hash),'last_line_sha256'=>hash('sha256',$last),'bytes'=>$bytes,'lines'=>$lines];
}
function pending_read_child_history(array $entry,string $operation,array &$history):void{
 pending_read_need(($entry['operation']??null)===$operation&&is_array($entry['posts']??null)&&count($entry['posts'])<=2,'child_history_scope_changed');
 foreach($entry['posts'] as $key=>$post){
  pending_read_need(is_string($key)&&preg_match('/\A(max|telegram):[A-Za-z0-9_-]{1,191}\z/D',$key,$match)===1&&is_array($post),'child_history_post_invalid');
  $history['post_keys'][$key]=true;pending_read_need(count($history['post_keys'])<=2,'child_history_pair_scope_changed');
  if($match[1]==='telegram')$history['telegram_intent_seen']=true;
 }
 $history['lines_scanned']++;
}
function pending_read_object_hash(array $object):string{return hash('sha256',json_encode($object,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR));}
function pending_safe_fields(array $row):array{
 $out=[];
 foreach(['state','phase','operation','tourvisor_hotel_id','hotel_id','platform','channel_id','post_channel_id','publication_id','version_id','expected_version_id','old_version_id','current_version_id','new_version_id','message_id','external_message_id','logical_id','source_key','record_id','manifest_hash','text_sha256','old_text_sha256','new_text_sha256','photo_sha256','attachments_sha256','button_verified','body_photo_button_verified','verified','content_preserved','reconcile_only','request_count','write_attempts','http','status','purpose','method','post_write_attempts','button_write_attempts','configuration_write_attempts','write_started','complete','reason','error','exit_code','at','recorded_at','prior_sha256','feed_operation'] as $key){if(array_key_exists($key,$row)&&(is_scalar($row[$key])||$row[$key]===null))$out[$key]=$row[$key];}
 foreach(['receipt','child_failure','terminal','plan','button_result'] as $key)if(is_array($row[$key]??null))$out[$key]=pending_safe_fields($row[$key]);
 return $out;
}
$locks=[];$out=['operation'=>READINESS_OPERATION,'mode'=>'read_existing_nightly_pending_and_exact_native_max','ok'=>false,'global_lock_available'=>false,'global_pending_is_null'=>null,'db_write_attempts'=>0,'journal_write_attempts'=>0,'post_write_attempts'=>0,'configuration_write_attempts'=>0,'provider_write_attempts'=>0,'feed_write_attempts'=>0,'feed_continuation_authorized'=>false];
try{
 pending_read_need(PHP_SAPI==='cli'&&trim((string)shell_exec('git rev-parse HEAD'))===EXPECTED_INSTALLED_SHA,'installed_source_changed');
 pending_read_need(trim((string)shell_exec('git status --porcelain --untracked-files=no'))==='','tracked_tree_not_clean');
 $root='/var/www/anytoour/data/channel-post-operations';
 $global=pending_read_last($root.'/monitor-price-after-collection-v1.jsonl',100000000,LOCK_EX,$locks);$out['global_lock_available']=true;
 $state=$global['state'];pending_read_need(($state['schema']??null)===1&&array_key_exists('pending',$state),'global_journal_schema_changed');
 $pending=$state['pending'];$out['global_journal']=['sha256'=>$global['sha256'],'last_line_sha256'=>$global['last_line_sha256'],'bytes'=>$global['bytes'],'lines'=>$global['lines'],'state_keys'=>array_keys($state),'completed_pair_count'=>is_array($state['completed']??null)?count($state['completed']):null];
 pending_read_need($pending===null||is_array($pending),'pending_state_invalid');
 $out['global_pending_is_null']=$pending===null;$out['pending']=$pending===null?null:pending_safe_fields($pending);
 if($pending!==null){
  pending_read_need(is_array($pending)&&is_int($pending['tourvisor_hotel_id']??null)&&$pending['tourvisor_hotel_id']>0&&is_string($pending['operation']??null),'pending_identity_invalid');
  pending_read_need(preg_match('/\Acommercial-monitor-price-h([1-9][0-9]*)-[a-f0-9]{64}\z/D',$pending['operation'],$match)===1&&(int)$match[1]===$pending['tourvisor_hotel_id'],'pending_operation_invalid');
  $out['pending_keys']=array_keys($pending);
  $history=['post_keys'=>[],'telegram_intent_seen'=>false,'lines_scanned'=>0];
  $child=pending_read_last($root.'/'.$pending['operation'].'/receipt.jsonl',10000000,LOCK_SH,$locks,static function(array $entry)use($pending,&$history):void{pending_read_child_history($entry,$pending['operation'],$history);});$journal=$child['state'];
  pending_read_need(($journal['operation']??null)===$pending['operation'],'child_operation_changed');
  pending_read_need(is_array($journal['posts']??[])&&count($journal['posts']??[])<=2,'child_pair_scope_changed');
  pending_read_need(is_array($journal['monitor_snapshot']??null)&&is_array($journal['feed_binding']??null)&&$history['lines_scanned']===$child['lines'],'child_history_or_bindings_invalid');
  $historicalPostKeys=array_keys($history['post_keys']);sort($historicalPostKeys,SORT_STRING);
  $posts=[];foreach(($journal['posts']??[]) as $key=>$post){pending_read_need(is_array($post),'child_post_state_invalid');$posts[]=['journal_key'=>(string)$key,'fields'=>pending_safe_fields($post),'field_keys'=>array_keys($post)];}
  $out['child_journal']=['sha256'=>$child['sha256'],'last_line_sha256'=>$child['last_line_sha256'],'bytes'=>$child['bytes'],'lines'=>$child['lines'],'state_keys'=>array_keys($journal),'fields'=>pending_safe_fields($journal),'post_count'=>count($posts),'posts'=>$posts,'complete'=>($journal['complete']??null),'monitor_snapshot_sha256'=>pending_read_object_hash($journal['monitor_snapshot']),'feed_binding_sha256'=>pending_read_object_hash($journal['feed_binding']),'historical_post_keys'=>$historicalPostKeys,'telegram_intent_seen'=>$history['telegram_intent_seen'],'history_lines_scanned'=>$history['lines_scanned']];
  $out['child_disposition']=($journal['complete']??null)===true?'complete_original_journal':(($journal['terminal']['state']??null)==='abandoned_before_write'?'abandoned_before_write':'incomplete_original_journal_requires_canonical_reconciliation');
  $out['native_max']=monitor_pending_native_read($pending,$journal,EXPECTED_INSTALLED_SHA);
 }else{$out['child_journal']=null;$out['native_max']=['attempted'=>false,'reason'=>'no_pending_operation'];}
 $out['installed_sha']=EXPECTED_INSTALLED_SHA;$out['read_at_utc']=gmdate('c');$out['ok']=true;
}catch(Throwable $e){$out['error']=preg_match('/\A[a-z][a-z0-9_]{1,100}\z/D',$e->getMessage())?$e->getMessage():'bounded_read_failed';}
finally{foreach(array_reverse($locks) as $lock)if(is_resource($lock)){flock($lock,LOCK_UN);fclose($lock);}}
echo json_encode($out,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR),PHP_EOL;exit($out['ok']?0:2);
