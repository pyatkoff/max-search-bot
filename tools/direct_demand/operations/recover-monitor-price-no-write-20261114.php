<?php
declare(strict_types=1);
// One exact expired TV512 preflight retirement. Never invokes the editor, feed writer or collector.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
ini_set('display_errors', '0');
ini_set('log_errors', '0');
require_once __DIR__.'/monitor-price-hook-contract.php';

const RECOVERY_ROOT = '/var/www/anytoour/data/channel-post-operations';
const RECOVERY_RUNTIME = '/var/www/anytoour/data/www/app.anytoour.ru';
const RECOVERY_INSTALLED = '9a8c8dc93e2abb102be663e48b93a097d98927ea';
const RECOVERY_DIAGNOSTIC_RUN = '43535778714';
const RECOVERY_DIAGNOSTIC_SOURCE = 'b3d3272a5df7094fdb0de690da33def0f918765e';
const RECOVERY_COLLECTOR = '43520564593';
const RECOVERY_OPERATION = 'commercial-monitor-price-h512-3d068cf2c5aeecbe17f5d682ba8c5b19b01b69a49ff5f34159b33e86e0485dfc';
const RECOVERY_GLOBAL = ['sha256'=>'33f00a0a91563c26e058f3c4d075146e2b7e2d755338e56daa10d1ab6fab579b','bytes'=>66301866,'lines'=>1314];
const RECOVERY_CHILD = ['sha256'=>'66af374c6e5a1e50331b80d106c9a514f77382692bfdd1fd931de89cb327e167','bytes'=>7362,'lines'=>1];
const RECOVERY_MANIFEST = '097931437dd4785350a4ca0e5129f6feb439535fec36800f8d0e68a919ca00d5';
const RECOVERY_SNAPSHOT = 'e1341a260488139d68b6e1fd44559f26c780b1d7941c29c2691459cb56f41e68';
const RECOVERY_FEED_BINDING = '85ee74a8c2b86e070f8d5b0e0e151a67192c85e494303f8c8b6756ba05da459f';
const RECOVERY_COMPLETED = '66d62626f15847ea576171fa7c397a250ba145d9bfb1724ef0252940703bd021';
const RECOVERY_PENDING_HASH = 'ac96ef502c903db1038323f05e77672e2673cd9268cc911d8c3e4e1b72ee5b0b';
const RECOVERY_CYCLE_HASH = '6a931e905b8a83ba58c5f2eac574feae5628e315d5bbc88d799064ea9caeb72d';
const RECOVERY_PENDING = ['tourvisor_hotel_id'=>512,'operation'=>RECOVERY_OPERATION,'phase'=>'post_started',
    'at'=>'2026-11-13T23:02:31+00:00','child_failure'=>['exit_code'=>2,'error'=>'provider_channel_rights_changed']];
const RECOVERY_PINS = [
    'integrations/ChannelPostApiTransport.php'=>'300634d77cb9fddf51adf9869974f956cca6e3f9',
    'migrations/033_channel_post_context.sql'=>'0530458030a4bfe6b6cc55d1e5e441745c29b947',
    'migrations/036_channel_post_member_delivery.sql'=>'1bda463e52b33130ad12752ddef4ee5c92699c05',
    'migrations/040_channel_post_reconciliation.sql'=>'0f24b838eacd45333c5ade9a2991d060488c6035',
    'services/ChannelPostAdminService.php'=>'b80eefc801054bfe6fdb58ec345ecdf687b2e89a',
    'services/ChannelPostAutomationService.php'=>'f5975ab95de5f45e087eb7a0f768f566c5bca648',
    'services/ChannelPostButtonTransport.php'=>'7b6711f1de63000346d8e5789f2dbb7f725535b8',
    'services/ChannelPostEntryService.php'=>'f0e04e9b4e0ed6859ced96279cbf0619931596e6',
    'services/ChannelPostMaxAlbumRecovery.php'=>'de266cd068587cc3b2ff83f308a966ea66887a04',
    'services/ChannelPostMediaService.php'=>'f794efd0f6f24df916e18909ff6281b0bc928433',
    'services/ChannelPostMemberDeliveryService.php'=>'20c1d7984898b2a5996a5f9e20e4d2d8c20b4778',
    'services/ChannelPostReconciliationService.php'=>'43133f576845c14446102dedfffb7f0de2d4ba1c',
    'services/ChannelPostReconciliationState.php'=>'d9583ceca80d1424a44ade3561c21282a23a52f0',
    'services/ChannelPostRegistryService.php'=>'db8181426091a685a79ee55991cde22671f0d9dd',
    'services/ChannelPostRuntime.php'=>'2f1dd9b77474fdbaa92f0402f3b0aa170c595474',
    'services/ChannelPostTelegramAlbum.php'=>'faa1a14bc279a4af33936282126bf0d46e343040',
    'services/ChannelPostWebhookConfigService.php'=>'d5fdcb056088f1e3a541b4046325d97eef6417d9',
    'services/ConversationDb.php'=>'bb6281faf55aca7beeae7f8a57389d19751936f7',
    'services/MaxTlsConfig.php'=>'032c0211ee3f39c6f0d27c669abd5b467e321dea',
    'services/MaxTransport.php'=>'2cb4f8786b4704a8e62a3a9c40dc2cc73dad6159',
    'services/ProjectConfig.php'=>'63c17c8103412f8cff8cdcc4bbb062e744106af9',
    'services/ProjectConfiguration.php'=>'07019d473c588e3386ace42977db93092e8112e3',
];
const RECOVERY_TARGETS = [
    'max'=>['bot'=>'id9704048781_1_bot','channel'=>'-80492244209683','source'=>'max_anytour_msk1','channel_row'=>9,
        'publication_id'=>550,'version_id'=>4425,'message_id'=>'mid.ffffb6caf0606fed01a1b62272a447b7',
        'post_url'=>'https://max.ru/channel_anytour/AaG2InKkR7c',
        'attachments_sha256'=>'2857ee8cfcea2274efcd2928aafd77f3d17c6ae575571e3bd791c9be1a324e55'],
    'telegram'=>['bot'=>'anyru_bot','channel'=>'-1002262666616','source'=>'tg_anytour_msk','channel_row'=>10,
        'publication_id'=>545,'version_id'=>4426,'message_id'=>'223','post_url'=>'https://t.me/any_toour/223',
        'attachments_sha256'=>'b92d8e76712945c4f24f2e74852948db9ef746b690c787b6d54c87a2035ac3d2'],
];
const RECOVERY_TEXT_SHA = 'e37ad2faf6bad095d85ac4c6eb8a10f0637c38780d8e027caf20fdc7acf932d4';

function recovery_need(bool $ok,string $code):void { if(!$ok)throw new RuntimeException($code); }
function recovery_json($value):string { return json_encode($value,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR); }
function recovery_hash($value):string { return hash('sha256',recovery_json($value)); }
function recovery_git(array $args):string {
    $pipes=[];$p=proc_open(array_merge(['git','--no-optional-locks','-C',RECOVERY_RUNTIME],$args),
        [0=>['file','/dev/null','r'],1=>['pipe','w'],2=>['file','/dev/null','w']],$pipes,RECOVERY_RUNTIME,null,['bypass_shell'=>true]);
    recovery_need(is_resource($p),'recovery_git_start_failed');$raw=stream_get_contents($pipes[1],32769);fclose($pipes[1]);$exit=proc_close($p);
    recovery_need($exit===0&&is_string($raw)&&strlen($raw)<=32768,'recovery_git_read_failed');return trim($raw);
}
function recovery_runtime():void {
    recovery_need(!is_link(RECOVERY_RUNTIME)&&realpath(RECOVERY_RUNTIME)===RECOVERY_RUNTIME&&chdir(RECOVERY_RUNTIME),'recovery_runtime_invalid');
    recovery_need(recovery_git(['rev-parse','--verify','HEAD'])===RECOVERY_INSTALLED,'recovery_installed_source_changed');
    recovery_need(recovery_git(['status','--porcelain','--untracked-files=no'])==='','recovery_tracked_tree_changed');
    recovery_need(count(RECOVERY_PINS)===22,'recovery_pin_count_invalid');
    foreach(RECOVERY_PINS as $file=>$pin)recovery_need(!is_link(RECOVERY_RUNTIME.'/'.$file)
        &&realpath(RECOVERY_RUNTIME.'/'.$file)===RECOVERY_RUNTIME.'/'.$file,'recovery_pinned_path_invalid');
    recovery_need(explode("\n",recovery_git(array_merge(['hash-object','--'],array_keys(RECOVERY_PINS))))===array_values(RECOVERY_PINS),'recovery_publishing_surface_changed');
}
/** Stream the 66MB global journal; never duplicate its whole prefix in memory. */
function recovery_read_locked($stream,bool $retainRaw=false):array {
    recovery_need(rewind($stream),'recovery_journal_rewind_failed');$ctx=hash_init('sha256');$bytes=0;$lines=0;$last=null;$raw='';
    while(!feof($stream)){
        $line=fgets($stream,2000002);
        if($line===false){recovery_need(feof($stream),'recovery_journal_read_failed');break;}
        $bytes+=strlen($line);recovery_need($bytes<=100000000&&strlen($line)<=2000000&&str_ends_with($line,"\n"),'recovery_journal_partial_or_oversize');
        hash_update($ctx,$line);$entry=json_decode($line,true,64,JSON_THROW_ON_ERROR);recovery_need(is_array($entry),'recovery_journal_shape_invalid');
        if($retainRaw){recovery_need($bytes<=10000000,'recovery_child_size_invalid');$raw.=$line;}
        $last=$entry;$lines++;
    }
    return ['meta'=>['sha256'=>hash_final(hash_copy($ctx)),'bytes'=>$bytes,'lines'=>$lines],
        'last'=>$last,'raw'=>$raw,'hash_context'=>$ctx];
}
/** Global EX lock precedes child EX lock. Existing regular files only; no creation or truncation. */
function recovery_open(string $path,array $expected,bool $retainRaw,array &$locks):array {
    recovery_need(!is_link(RECOVERY_ROOT)&&realpath(RECOVERY_ROOT)===RECOVERY_ROOT
        &&!is_link(dirname($path))&&realpath(dirname($path))===dirname($path)
        &&!is_link($path)&&is_file($path)&&realpath($path)===$path,'recovery_journal_path_invalid');
    $named=lstat($path);recovery_need(is_array($named)&&($named['mode']&0170000)===0100000&&$named['size']===$expected['bytes'],'recovery_journal_size_changed');
    $stream=fopen($path,'r+b');recovery_need(is_resource($stream),'recovery_journal_open_failed');$locks[]=$stream;
    recovery_need(flock($stream,LOCK_EX|LOCK_NB),'recovery_original_operation_busy');
    $stat=fstat($stream);recovery_need(is_array($stat)&&$stat['ino']===$named['ino']&&$stat['dev']===$named['dev']&&$stat['size']===$named['size'],'recovery_journal_identity_changed');
    $read=recovery_read_locked($stream,$retainRaw);recovery_need($read['meta']===$expected,'recovery_original_cas_changed');
    $file=['stream'=>$stream,'path'=>$path,'stat'=>$stat]+$read;recovery_stable($file);return $file;
}
function recovery_stable(array $file):void {
    clearstatcache(true,$file['path']);$named=lstat($file['path']);$opened=fstat($file['stream']);
    recovery_need(is_array($named)&&is_array($opened)&&!is_link($file['path'])&&realpath($file['path'])===$file['path'],'recovery_named_path_changed');
    foreach(['ino','dev','size','mtime','ctime'] as $key)recovery_need($named[$key]===$file['stat'][$key]&&$opened[$key]===$file['stat'][$key],'recovery_journal_changed');
}
/** No hold is created: this retires one observation and preserves every other state field. */
function recovery_pending_only(array $state,array $expectedPending):array {
    recovery_need(($state['schema']??null)===1&&($state['pending']??null)===$expectedPending&&is_array($state['completed']??null),'recovery_pending_changed');
    recovery_need(!isset($state['holds'][512]),'recovery_existing_hotel_hold_stop');
    $next=$state;$next['pending']=null;$before=$state;$after=$next;unset($before['pending'],$after['pending']);
    recovery_need($before===$after,'recovery_unrelated_state_changed');return $next;
}
function recovery_photo(array $snapshot):string {
    $photos=$snapshot['attachments']??[];recovery_need(is_array($photos)&&count($photos)===1&&($photos[0]['type']??null)==='image','recovery_photo_shape_changed');
    $photo=$photos[0];$id=(string)($photo['photo_id']??$photo['file_unique_id']??'');
    recovery_need($id!==''&&preg_match('/\A[A-Za-z0-9_-]+\z/D',$id)===1,'recovery_photo_identity_missing');
    return (string)($photo['provider']??'').':'.$id;
}
function recovery_require_ready(array $cap):void {
    recovery_need(($cap['state']??null)==='ready'&&($cap['reason']??null)==='capability_verified'
        &&array_key_exists('error',$cap)&&$cap['error']===null&&($cap['request_count']??null)===3
        &&($cap['write_attempts']??null)===0&&($cap['http_results_truncated']??null)===false
        &&is_array($cap['http_results']??null)&&count($cap['http_results'])===3,'recovery_current_channel_not_ready');
    foreach($cap['http_results'] as $http)recovery_need(($http['http']??null)===200
        &&($http['transport_error']??null)===false,'recovery_capability_http_not_ready');
}
function recovery_current_readback(array $binding,array &$out):array {
    ob_start();try{
        require RECOVERY_RUNTIME.'/config.php';
        require_once RECOVERY_RUNTIME.'/services/ChannelPostRuntime.php';
        require_once RECOVERY_RUNTIME.'/services/ChannelPostRegistryService.php';
        require_once RECOVERY_RUNTIME.'/services/ChannelPostButtonTransport.php';
    }finally{ob_end_clean();}
    $pdo=ConversationDb::connection();$pdo->exec('SET SESSION TRANSACTION READ ONLY');$pdo->beginTransaction();
    try{
        recovery_need($pdo->inTransaction(),'recovery_read_only_transaction_unavailable');$registry=new ChannelPostRegistryService($pdo);
        $proof=['captured_at'=>gmdate('c'),'runtime_sha'=>RECOVERY_INSTALLED,'database_session_read_only'=>true,'capabilities'=>[],'publications'=>[]];
        foreach(RECOVERY_TARGETS as $platform=>$t){
            recovery_need(($binding['posts'][$platform]??null)===$t['post_url'],'recovery_original_post_binding_changed');
            $identity=ChannelPostRuntime::identity($platform);recovery_need(is_array($identity)&&($identity['project_key']??null)==='anytour'&&($identity['bot_key']??null)===$t['bot'],'recovery_configured_bot_changed');
            $channel=$registry->channel('anytour',$platform,$t['bot'],$t['channel']);
            recovery_need(is_array($channel)&&(int)$channel['id']===$t['channel_row']&&$channel['source_key']===$t['source']
                &&(int)$channel['config_version']===2&&$channel['button_text']==='✈️ Узнать стоимость тура','recovery_channel_configuration_changed');
            $cap=monitor_price_channel_preflight($platform,(new ChannelPostButtonTransport())->verifyChannel($channel));
            $out['provider_read_requests']+=is_int($cap['request_count']??null)?$cap['request_count']:0;$proof['capabilities'][$platform]=$cap;
            $out['current_readback']=$proof;
            recovery_require_ready($cap);
            $p=$registry->publication($t['publication_id']);
            recovery_need(is_array($p)&&$p['project_key']==='anytour'&&$p['platform']===$platform&&$p['bot_key']===$t['bot']
                &&$p['channel_id']===$t['channel']&&$p['source_key']===$t['source']&&$p['state']==='sent'
                &&(int)$p['publication_id']===$t['publication_id']&&(int)$p['version_id']===$t['version_id']
                &&$p['external_message_id']===$t['message_id']&&(int)$p['write_attempts']===0,'recovery_current_publication_changed');
            $s=$p['snapshot'];recovery_need(($s['post_url']??null)===$t['post_url']&&hash('sha256',(string)($s['text']??''))===RECOVERY_TEXT_SHA
                &&recovery_hash($s['attachments']??[])===$t['attachments_sha256']&&($s['metadata']['monitor_price_record_id']??null)===null,'recovery_current_content_changed');
            $proof['publications'][$platform]=['publication_id'=>$t['publication_id'],'version_id'=>$t['version_id'],
                'message_id'=>$t['message_id'],'post_url'=>$t['post_url'],'state'=>'sent','text_sha256'=>RECOVERY_TEXT_SHA,
                'attachments_sha256'=>$t['attachments_sha256'],'monitor_price_record_id'=>null,'write_attempts'=>0];
            if($platform==='max'){
                $out['provider_read_requests']++;
                $response=ChannelPostApiTransport::buttonRequest('max','GET','/messages/'.$t['message_id'],[],null);
                recovery_need(($response['transport_error']??true)===false&&($response['http']??null)===200&&is_array($response['data']??null),'recovery_current_max_read_failed');
                $message=$response['data'];recovery_need(($message['body']['mid']??null)===$t['message_id']
                    &&(string)($message['recipient']['chat_id']??'')===$t['channel']&&($message['recipient']['chat_type']??null)==='channel','recovery_current_max_identity_changed');
                $live=ChannelPostIncomingAdapter::maxSnapshot($message);
                recovery_need(is_array($live)&&($live['text']??null)===($s['text']??null)&&($live['post_url']??null)===$t['post_url']
                    &&recovery_photo($live)===recovery_photo($s)
                    &&ChannelPostIncomingAdapter::containsOnlyButton($live,ChannelPostButtonTransport::buttonUrl($p),'✈️ Узнать стоимость тура'),'recovery_current_max_content_changed');
                $proof['max_readback']=['http'=>200,'transport_error'=>false,'identity_matches'=>true,'text_matches'=>true,
                    'photo_matches'=>true,'url_matches'=>true,'only_expected_button'=>true];
            }
        }
        recovery_need($out['provider_read_requests']===7,'recovery_read_request_budget_changed');
        $proof['completed_at']=gmdate('c');$proof['provider_read_requests']=$out['provider_read_requests'];$out['current_readback']=$proof;return $proof;
    }finally{if($pdo->inTransaction())$pdo->rollBack();}
}
/** One append attempt, fsync, then exact whole-file readback. An uncertain write is never retried. */
function recovery_append(array $file,string $line,array &$out):array {
    recovery_need($line!==''&&str_ends_with($line,"\n")&&substr_count($line,"\n")===1&&strlen($line)<=2000000,'recovery_append_line_invalid');
    recovery_stable($file);$ctx=hash_copy($file['hash_context']);hash_update($ctx,$line);
    $expected=['sha256'=>hash_final($ctx),'bytes'=>$file['meta']['bytes']+strlen($line),'lines'=>$file['meta']['lines']+1];
    recovery_need(fseek($file['stream'],0,SEEK_END)===0,'recovery_append_seek_failed');
    $out['journal_write_attempts']++;$out['write_outcome']='unknown_until_exact_readback';
    recovery_need(fwrite($file['stream'],$line)===strlen($line)&&fflush($file['stream']),'recovery_append_failed');
    if(function_exists('fsync'))recovery_need(fsync($file['stream']),'recovery_sync_failed');
    $readback=recovery_read_locked($file['stream']);recovery_need($readback['meta']===$expected,'recovery_append_readback_failed');
    clearstatcache(true,$file['path']);$named=lstat($file['path']);$stat=fstat($file['stream']);
    recovery_need(is_array($named)&&is_array($stat)&&!is_link($file['path'])&&realpath($file['path'])===$file['path']
        &&$named['ino']===$file['stat']['ino']&&$named['dev']===$file['stat']['dev']&&$stat['ino']===$named['ino']
        &&$stat['dev']===$named['dev']&&$named['size']===$expected['bytes']&&$stat['size']===$expected['bytes'],'recovery_append_identity_changed');
    $out['journal_appends']++;$out['write_outcome']='last_append_verified';return $expected;
}
/** Pure transition regressions. No runtime, DB, provider, filesystem or journal access. */
function recovery_self_test():void {
    $state=['schema'=>1,'pending'=>RECOVERY_PENDING,'completed'=>[195=>['operation'=>'synthetic_completed']],
        'holds'=>[1142=>['operation'=>'synthetic_other_hold']],'nightly_cycle'=>['state'=>'started','collector_run_id'=>RECOVERY_COLLECTOR],
        'retained_unknown'=>['unchanged'=>true]];
    $saved=recovery_json($state);$next=recovery_pending_only($state,RECOVERY_PENDING);
    recovery_need($next['pending']===null&&recovery_json($state)===$saved,'self_test_changed_input');
    recovery_need($next['completed']===$state['completed']&&$next['holds']===$state['holds']&&!isset($next['holds'][512]),'self_test_created_hold_or_completion');
    recovery_need($next['nightly_cycle']===$state['nightly_cycle']&&$next['retained_unknown']===$state['retained_unknown'],'self_test_closed_cycle_or_changed_history');
    $cases=3;
    foreach(['pending','schema','hold'] as $case){$bad=$state;
        if($case==='pending')$bad['pending']['phase']='feed_started';elseif($case==='schema')$bad['schema']=2;else $bad['holds'][512]=['operation'=>'synthetic_existing_hold'];
        $failed=false;try{recovery_pending_only($bad,RECOVERY_PENDING);}catch(RuntimeException $e){$failed=true;}
        recovery_need($failed,'self_test_accepted_changed_'.$case);$cases++;
    }
    $verification=['state'=>'ready','reason'=>'capability_verified','receipt'=>['request_count'=>3,'write_attempts'=>0,
        'http_results'=>array_map(static fn($operation)=>['operation'=>$operation,'http'=>200,'transport_error'=>false],
            ['bot_identity','channel_rights','channel_type'])]];
    $cap=monitor_price_channel_preflight('max',$verification);recovery_require_ready($cap);$cases++;
    foreach(['missing_error','unavailable','unexpected_write'] as $case){$bad=$cap;
        if($case==='missing_error')unset($bad['error']);elseif($case==='unavailable')$bad['state']='unknown';else $bad['write_attempts']=1;
        $failed=false;try{recovery_require_ready($bad);}catch(RuntimeException $e){$failed=true;}
        recovery_need($failed,'self_test_accepted_capability_'.$case);$cases++;
    }
    echo 'MONITOR_NO_WRITE_RECOVERY_SELF_TEST='.recovery_json(['ok'=>true,'cases'=>$cases,'filesystem_writes'=>0,'database_writes'=>0,'provider_requests'=>0])."\n";
}
if(count($argv)===2&&$argv[1]==='--self-test'){recovery_self_test();exit(0);}

$out=['ok'=>false,'mode'=>'reconcile_exact_expired_monitor_no_write_operation','operation'=>RECOVERY_OPERATION,
    'tourvisor_hotel_id'=>512,'original_collector_run_id'=>RECOVERY_COLLECTOR,
    'diagnostic_run_id'=>RECOVERY_DIAGNOSTIC_RUN,'diagnostic_source_sha'=>RECOVERY_DIAGNOSTIC_SOURCE,
    'journal_write_attempts'=>0,'journal_appends'=>0,'database_writes'=>0,'post_edits'=>0,'new_posts'=>0,'feed_writes'=>0,
    'configuration_writes'=>0,'supplier_calls'=>0,'site_writes'=>0,'provider_write_requests'=>0,'provider_read_requests'=>0,
    'write_outcome'=>'not_started','historical_capability_reason'=>'not_recorded_by_original_editor'];
$locks=[];
try{
    recovery_need(count($argv)===1,'recovery_arguments_invalid');recovery_runtime();
    $out['runtime_git_head']=RECOVERY_INSTALLED;$out['runtime_tracked_clean']=true;
    $out['runtime_pin_count']=count(RECOVERY_PINS);$out['runtime_pins_sha256']=recovery_hash(RECOVERY_PINS);
    $global=recovery_open(RECOVERY_ROOT.'/monitor-price-after-collection-v1.jsonl',RECOVERY_GLOBAL,false,$locks);
    $child=recovery_open(RECOVERY_ROOT.'/'.RECOVERY_OPERATION.'/receipt.jsonl',RECOVERY_CHILD,true,$locks);
    $state=$global['last'];$original=$child['last'];
    recovery_need(($state['schema']??null)===1&&($state['pending']??null)===RECOVERY_PENDING
        &&recovery_hash($state['pending'])===RECOVERY_PENDING_HASH,'recovery_exact_pending_changed');
    recovery_need(is_array($state['completed']??null)&&count($state['completed'])===371
        &&strlen(recovery_json($state['completed']))===87178&&recovery_hash($state['completed'])===RECOVERY_COMPLETED,'recovery_completed_tuple_changed');
    $cycle=$state['nightly_cycle']??null;
    recovery_need(is_array($cycle)&&($cycle['collector_run_id']??null)===RECOVERY_COLLECTOR&&($cycle['state']??null)==='started'
        &&($cycle['night_date']??null)==='2026-11-14'&&recovery_hash($cycle)===RECOVERY_CYCLE_HASH,'recovery_original_cycle_changed');
    $noWrite=monitor_hook_no_write_prefix($child['raw'],RECOVERY_OPERATION);
    recovery_need($noWrite['sha256']===RECOVERY_CHILD['sha256']&&$noWrite['bytes']===RECOVERY_CHILD['bytes']&&$noWrite['lines']===1
        &&($original['manifest_hash']??null)===RECOVERY_MANIFEST&&recovery_hash($original['monitor_snapshot']??null)===RECOVERY_SNAPSHOT
        &&recovery_hash($original['feed_binding']??null)===RECOVERY_FEED_BINDING,'recovery_no_write_source_changed');
    $offer=$original['monitor_snapshot']['item']['offers'][0];
    recovery_need(($offer['expiresAt']??null)==='2026-11-13T23:16:58Z'&&monitor_price_time($offer['expiresAt'])<time(),'recovery_original_observation_not_expired');
    $out['before']=['global'=>RECOVERY_GLOBAL,'child'=>RECOVERY_CHILD,'pending_tuple_sha256'=>RECOVERY_PENDING_HASH,
        'completed_count'=>371,'completed_tuple_sha256'=>RECOVERY_COMPLETED,'cycle_tuple_sha256'=>RECOVERY_CYCLE_HASH];
    $out['original_all_history_no_write']=true;$out['original_manifest_sha256']=RECOVERY_MANIFEST;
    $out['original_snapshot_sha256']=RECOVERY_SNAPSHOT;$out['original_feed_binding_sha256']=RECOVERY_FEED_BINDING;
    $out['original_observation_expires_at']=$offer['expiresAt'];$out['original_observation_expired']=true;
    $next=recovery_pending_only($state,RECOVERY_PENDING);
    $proof=recovery_current_readback($original['feed_binding'],$out);$proofSha=recovery_hash($proof);
    // Revalidate runtime and unchanged locked files immediately before either append.
    recovery_runtime();recovery_stable($global);recovery_stable($child);
    $recordedAt=gmdate('c');
    $terminalLine=monitor_hook_terminal_line($child['raw'],RECOVERY_OPERATION,$recordedAt,
        'expired_observation_after_no_write_failure',['readback_evidence_sha256'=>$proofSha]);
    $parsed=monitor_hook_parse_journal($child['raw'].$terminalLine,RECOVERY_OPERATION);
    recovery_need(($parsed['terminal']['state']??null)==='abandoned_before_write'&&($parsed['complete']??false)!==true,'recovery_terminal_invalid');
    $nextLine=recovery_json($next)."\n";
    $afterChild=recovery_append($child,$terminalLine,$out);
    $out['after_child']=$afterChild;
    $afterGlobal=recovery_append($global,$nextLine,$out);
    $out['after']=['global'=>$afterGlobal,'child'=>$afterChild,'pending'=>null,'completed_count'=>count($next['completed']),
        'completed_tuple_sha256'=>recovery_hash($next['completed']),'cycle_tuple_sha256'=>recovery_hash($next['nightly_cycle']),
        'original_cycle_state'=>$next['nightly_cycle']['state'],'hotel_512_hold_present'=>isset($next['holds'][512]),
        'all_other_global_fields_preserved'=>true];
    $out['terminal']=$parsed['terminal'];$out['readback_evidence_sha256']=$proofSha;$out['readback_evidence']=$proof;unset($out['current_readback']);
    $out['installed_sha']=RECOVERY_INSTALLED;$out['recorded_at']=$recordedAt;$out['write_outcome']='both_appends_verified';
    $out['status']='pending_retired_original_cycle_awaiting_source_closure';$out['ok']=true;
}catch(Throwable $e){$out['error']=preg_match('/\A[a-z][a-z0-9_]{1,120}\z/D',$e->getMessage())?$e->getMessage():'recovery_failed_read_original_journals';}
foreach(array_reverse($locks) as $lock)if(is_resource($lock)){flock($lock,LOCK_UN);fclose($lock);}
echo 'MONITOR_NO_WRITE_RECONCILIATION='.recovery_json($out)."\n";exit($out['ok']?0:2);
