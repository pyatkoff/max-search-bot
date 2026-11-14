<?php
declare(strict_types=1);
// Bundled by build-monitor-price-hook.py and invoked once by the existing collector.
// No public handler, scheduler, supplier client, new publication or Site write.
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
ini_set('display_errors','0');ini_set('log_errors','0');
require_once __DIR__.'/monitor-price-hook-contract.php';

function hook_need(bool $ok,string $why):void{monitor_price_require($ok,$why);}
function hook_read(string $path,int $max=10000000):string {
    hook_need(!is_link($path)&&is_file($path)&&realpath($path)===$path&&filesize($path)<=$max,'hook_receipt_path_invalid');
    $raw=file_get_contents($path);hook_need(is_string($raw),'hook_read_failed');return $raw;
}
function hook_journal(string $op):?array {
    hook_need(preg_match('/\Acommercial-monitor-price-h[1-9][0-9]*-[a-f0-9]{64}\z/D',$op)===1,'hook_operation_invalid');
    $dir='/var/www/anytoour/data/channel-post-operations/'.$op;hook_need(!is_link($dir),'hook_receipt_path_invalid');
    if(!file_exists($dir.'/receipt.jsonl'))return null;
    $raw=hook_read($dir.'/receipt.jsonl');hook_need($raw!==''&&str_ends_with($raw,"\n"),'hook_incomplete_journal_stop');
    return monitor_hook_parse_journal($raw,$op);
}
function hook_append($lock,array $state):void {
    $raw=json_encode($state,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n";
    hook_need(fseek($lock,0,SEEK_END)===0&&fwrite($lock,$raw)===strlen($raw)&&fflush($lock),'hook_journal_write_failed');
    if(function_exists('fsync'))hook_need(fsync($lock),'hook_journal_sync_failed');
}
function hook_get(string $url,int $max=4000000):string {
    $u=parse_url($url);hook_need(($u['scheme']??'')==='https'&&in_array($u['host']??'',['anytoour.ru','app.anytoour.ru'],true),'hook_read_host_invalid');
    $raw='';$c=curl_init($url);curl_setopt_array($c,[CURLOPT_FOLLOWLOCATION=>false,CURLOPT_CONNECTTIMEOUT=>10,CURLOPT_TIMEOUT=>40,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_HTTPHEADER=>['Cache-Control: no-cache'],CURLOPT_WRITEFUNCTION=>static function($c,string $chunk)use(&$raw,$max):int{if(strlen($raw)+strlen($chunk)>$max)return 0;$raw.=$chunk;return strlen($chunk);}]);
    $ok=curl_exec($c);$http=curl_getinfo($c,CURLINFO_HTTP_CODE);curl_close($c);hook_need($ok===true&&$http===200,'hook_http_read_failed');return $raw;
}
function hook_child(string $source,array $constants,?array &$failure=null):array {
    $failure=null;
    $prefix="<?php\ndeclare(strict_types=1);\n";$header='';foreach($constants as $k=>$v)$header.='const '.$k.'='.var_export($v,true).";\n";
    $script=$prefix.$header.base64_decode(HOOK_CONTRACT_B64,true)."\n".$source;
    $pipes=[];$p=proc_open([PHP_BINARY], [['pipe','r'],['pipe','w'],['pipe','w']],$pipes,getcwd(),null,['bypass_shell'=>true]);
    hook_need(is_resource($p),'hook_child_start_failed');$written=0;while($written<strlen($script)){$n=fwrite($pipes[0],substr($script,$written));hook_need(is_int($n)&&$n>0,'hook_child_input_failed');$written+=$n;}fclose($pipes[0]);
    $raw=stream_get_contents($pipes[1]);fclose($pipes[1]);$errors=stream_get_contents($pipes[2]);fclose($pipes[2]);$exit=proc_close($p);
    hook_need(is_string($raw)&&strlen($raw)<2000000,'hook_child_output_invalid');$lines=explode("\n",rtrim($raw,"\n"));$result=json_decode(end($lines),true,64,JSON_THROW_ON_ERROR);
    if($exit!==0||($result['ok']??false)!==true){
        $failure=monitor_hook_child_failure($exit,is_array($result)?$result:[]);
        throw new RuntimeException('hook_child_failed_read_original_journal');
    }return $result;
}
/** The child has exited. Append a no-write terminal only under its original lock. */
function hook_abandon_failed_preflight(string $operation,array $failure):array {
    hook_need(monitor_hook_local_preflight_failure($failure),'hook_no_write_child_failure_not_admitted');
    $dir='/var/www/anytoour/data/channel-post-operations/'.$operation;$path=$dir.'/receipt.jsonl';
    hook_need(preg_match('/\Acommercial-monitor-price-h[1-9][0-9]*-[a-f0-9]{64}\z/D',$operation)===1
        &&!is_link($dir)&&realpath($dir)===$dir&&!is_link($path)&&is_file($path)&&realpath($path)===$path,'hook_no_write_journal_missing_stop');
    $journalLock=fopen($path,'r+b');hook_need(is_resource($journalLock),'hook_no_write_journal_open_failed');
    try{
        hook_need(flock($journalLock,LOCK_EX|LOCK_NB),'hook_no_write_journal_busy_stop');
        $stat=fstat($journalLock);$named=lstat($path);
        hook_need(is_array($stat)&&is_array($named)&&$stat['ino']===$named['ino']&&$stat['dev']===$named['dev']
            &&$stat['size']>0&&$stat['size']<=10000000,'hook_no_write_journal_changed_stop');
        $prefix=stream_get_contents($journalLock,10000001);hook_need(is_string($prefix)&&strlen($prefix)===$stat['size'],'hook_no_write_journal_changed_stop');
        $line=monitor_hook_terminal_line($prefix,$operation,gmdate('c'),'child_preflight_failed_before_write',
            ['preflight_error'=>$failure['error'],'child_exit_code'=>$failure['exit_code']]);
        hook_need(strlen($prefix.$line)<=10000000,'hook_no_write_journal_limit_stop');
        $terminal=monitor_hook_parse_journal($prefix.$line,$operation);
        hook_append($journalLock,json_decode($line,true,64,JSON_THROW_ON_ERROR));
        hook_need(rewind($journalLock)&&stream_get_contents($journalLock,10000001)===$prefix.$line,'hook_no_write_terminal_readback_failed');
        return $terminal;
    }finally{flock($journalLock,LOCK_UN);fclose($journalLock);}
}
/** Original completed caption batches are read once per pass; the editor independently rechecks them. */
function hook_caption_source(array $row):array {
    static $cache=[];
    $binding=monitor_price_caption_binding($row);$source=$binding['source'];$key=$source['operation'];
    if(!isset($cache[$key])){
        $dir='/var/www/anytoour/data/channel-post-operations/'.$source['operation'];$path=$dir.'/receipt.jsonl';
        hook_need(!is_link(dirname($dir))&&!is_link($dir)&&realpath($dir)===$dir
            &&!is_link($path)&&is_file($path)&&realpath($path)===$path,'canonical_original_receipt_missing');
        $stream=fopen($path,'rb');hook_need(is_resource($stream),'canonical_original_receipt_missing');
        try{
            hook_need(flock($stream,LOCK_SH|LOCK_NB),'hook_caption_source_unresolved_stop');
            $before=fstat($stream);$named=lstat($path);
            hook_need(is_array($before)&&is_array($named)&&$before['ino']===$named['ino']&&$before['dev']===$named['dev']
                &&$before['size']>0&&$before['size']<=134217728,'hook_caption_source_incomplete_stop');
            $journal=monitor_price_caption_journal($stream,$source);
            clearstatcache(true,$path);$after=fstat($stream);$named=lstat($path);
            foreach(['ino','dev','size','mtime','ctime'] as $field)
                hook_need(($after[$field]??null)===$before[$field]&&($named[$field]??null)===$before[$field],'hook_caption_source_unresolved_stop');
            $cache[$key]=['source'=>$source,'journal'=>$journal];
        }finally{flock($stream,LOCK_UN);fclose($stream);}
    }
    hook_need($cache[$key]['source']===$source,'canonical_original_receipt_mismatch');
    return monitor_price_caption_receipts($row,$cache[$key]['journal']);
}
function hook_context(array $row,ChannelPostRegistryService $registry):array {
    $result=[];
    $legacyProofs=null;$captionProofs=null;
    if(array_key_exists('legacy_proof',$row)){
        $legacy=monitor_price_legacy_binding($row);
        $dir='/var/www/anytoour/data/channel-post-operations/'.$legacy['prior_price']['operation'];
        hook_need(!is_link(dirname($dir))&&!is_link($dir)&&realpath($dir)===$dir,'canonical_original_receipt_missing');
        $legacyProofs=monitor_price_legacy_receipts($row,hook_read($dir.'/receipt.jsonl'));
    }
    if(array_key_exists('caption_proof',$row))$captionProofs=hook_caption_source($row);
    foreach(['max','telegram'] as $platform){
        if($captionProofs!==null){$proof=$captionProofs[$platform];}elseif($legacyProofs!==null){$proof=$legacyProofs[$platform];}else{
        $dir='/var/www/anytoour/data/channel-post-operations/'.$row['original_publication_operation'];
        hook_need(!is_link($dir),'hook_original_directory_invalid');
        $original=json_decode(hook_read($dir.'/'.$platform.'-'.$row['logical_id'].'-approved.json',1048576),true,64,JSON_THROW_ON_ERROR);$proof=$original['receipt']??[];
        hook_need(($original['state']??'')==='confirmed'&&($original['text_sha256']??'')===$row['original_text_sha256']&&($original['photo_sha256']??'')===$row['cover_sha256']
            &&($proof['logical_id']??'')===$row['logical_id']&&(int)($proof['hotel_id']??0)===$row['hotel_id']&&($proof['platform']??'')===$platform
            &&($proof['post_url']??'')===$row['posts'][$platform]&&($proof['body_photo_button_verified']??false)===true,'hook_original_pair_mismatch');
        }
        $p=$registry->publication((int)$proof['publication_id']);
        hook_need(is_array($p)&&$p['project_key']==='anytour'&&$p['platform']===$platform&&$p['state']==='sent'
            &&$p['external_message_id']===(string)$proof['message_id']&&($p['snapshot']['post_url']??'')===$row['posts'][$platform],'hook_publication_changed');
        if($legacyProofs!==null)hook_need(monitor_price_legacy_publication_matches($p,$row,$platform,ChannelPostButtonTransport::buttonUrl($p)),'existing_publication_identity_changed');
        if($captionProofs!==null)hook_need(monitor_price_caption_publication_matches($p,$row,$platform,ChannelPostButtonTransport::buttonUrl($p),$proof),'existing_publication_identity_changed');
        $record=$p['snapshot']['metadata']['monitor_price_record_id']??null;$prior=null;
        if($record!==null){hook_need(is_string($record)&&preg_match('/\A[a-f0-9]{64}\z/D',$record)===1,'hook_record_invalid');$prior=hook_journal('commercial-monitor-price-h'.$row['tourvisor_hotel_id'].'-'.$record);}
        hook_need(monitor_hook_caption_allowed($p,$row,$platform,$prior),'hook_manual_caption_changed_hold');
        $result[$platform]=$p;
    }return $result;
}
function hook_prepare_feed(array $journal,string $installed):array {
    $binding=$journal['feed_binding'];$binding['operation']=$journal['operation'];$binding['manifest_hash']=$journal['manifest_hash'];
    $update=monitor_price_confirmed_journal($journal,$binding,time());$at=time();$files=[];
    foreach(['hotel-posts-telegram.xml','hotel-posts-max.xml','hotel-posts-all.xml'] as $name){
        $before=hook_read(getcwd().'/feed/'.$name,4000000);$after=monitor_price_render_feed($before,[$update],$name,$at);
        $files[]=['name'=>$name,'sha256'=>hash('sha256',$after),'bytes'=>strlen($after),'rows'=>substr_count($after,'<offer id="'),'expected_previous_sha256'=>hash('sha256',$before),'content_base64'=>base64_encode($after)];
    }
    return ['schema_version'=>3,'operation'=>monitor_hook_feed_operation($journal['operation'],gmdate('Ymd')),'files'=>$files,'generated_at'=>gmdate('Y-m-d\TH:i:s\Z',$at),'price_updates'=>[$binding]];
}
/** Same saved monitor reader as before, in batches of 100; no supplier search or writes. */
function hook_snapshots(array $roster,DateTimeImmutable $today):array {
    $snapshots=[];
    foreach(array_chunk(array_column($roster,'tourvisor_hotel_id'),100) as $ids){
        $query=['tourvisorHotelIds'=>$ids,'departureId'=>1,'dateFrom'=>$today->modify('+1 day')->format('Y-m-d'),'dateTo'=>$today->modify('first day of this month')->modify('+12 months')->modify('-1 day')->format('Y-m-d')];
        $url='https://anytoour.ru/data/hotel-monitor-prices-read-v1.php?'.http_build_query($query,'','&',PHP_QUERY_RFC3986);
        $payload=json_decode(hook_get($url),true,64,JSON_THROW_ON_ERROR);
        foreach($ids as $tv)$snapshots[$tv]=monitor_hook_source($payload,$tv,$url);
    }
    return $snapshots;
}
function hook_output_and_exit(array $out,$lock=null):void {
    if(is_resource($lock)){flock($lock,LOCK_UN);fclose($lock);}
    echo 'MONITOR_POST_FEED_RESULT='.json_encode($out,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n";
    exit($out['ok']?0:2);
}

function hook_finish_feed(array &$state,$lock,string $installed):string {
    $pending=$state['pending'];$journal=hook_journal($pending['operation']);
    if(is_array($journal)){
        $held=monitor_hook_apply_abandonment($state,$journal);
        if($held!==null){$state=$held;hook_append($lock,$state);return 'held_before_write';}
    }
    hook_need(is_array($journal)&&($journal['complete']??false)===true,'hook_post_outcome_unresolved_stop');
    if($pending['phase']==='post_started'){
        $input=hook_prepare_feed($journal,$installed);$raw=json_encode($input,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
        $state['pending']['phase']='feed_started';$state['pending']['feed_operation']=$input['operation'];
        $state['pending']['files']=array_map(static fn($r)=>array_intersect_key($r,array_flip(['name','sha256','bytes','rows','expected_previous_sha256'])),$input['files']);hook_append($lock,$state);
        $failure=null;
        try{hook_child(base64_decode(HOOK_WRITER_B64,true),['EXPECTED_INSTALLED_SHA'=>$installed,'PUBLIC_STATIC_FEED_OPERATION'=>$input['operation'],'PUBLIC_STATIC_FEED_INPUT_SHA256'=>hash('sha256',$raw),'PUBLIC_STATIC_FEED_INPUT_B64'=>base64_encode($raw)],$failure);}
        catch(Throwable $e){if($failure!==null){$state['pending']['child_failure']=$failure;hook_append($lock,$state);}throw $e;}
    }
    // Unknown feed outcome is reconciled by exact local + public hashes only, never replayed.
    foreach($state['pending']['files'] as $file){
        hook_need(hash('sha256',hook_read(getcwd().'/feed/'.$file['name'],4000000))===$file['sha256'],'hook_feed_outcome_unresolved_stop');
        hook_need(hash('sha256',hook_get('https://app.anytoour.ru/feed/'.$file['name']))===$file['sha256'],'hook_public_feed_readback_pending');
    }
    $state['completed'][$pending['tourvisor_hotel_id']]=['operation'=>$pending['operation'],'at'=>gmdate('c'),'feed_operation'=>$state['pending']['feed_operation']];
    $state['pending']=null;hook_append($lock,$state);
    return 'feed_completed';
}

$inspect=getenv('ANYTOUR_MONITOR_HOOK_INSPECT')==='1'||($argv[1]??'')==='--inspect';
$startedAt=time();$nightly=($argv[1]??'')==='--nightly';
$out=['ok'=>false,'mode'=>$inspect?'read_only_hook_inspection':'existing_monitor_post_feed_hook','new_posts'=>0,'supplier_calls'=>0,'site_writes'=>0,'updated_pairs'=>0,'unchanged_pairs'=>0,'eligible_pairs'=>0,'held_pairs'=>0,'verified_ids'=>[],'eligible_ids'=>[],'unchanged_ids'=>[],'held_ids'=>[],'updates'=>[],'deferred'=>[],'scanned_pairs'=>0];
try{
    hook_need(count($argv)===1||(($inspect||$nightly)&&count($argv)===2),'hook_argument_invalid');
    hook_need(!($inspect&&$nightly),'hook_argument_invalid');
    if($nightly){
        $nightGate=monitor_hook_nightly_gate([],$startedAt,getenv('ANYTOUR_MONITOR_COLLECTION_STARTED_AT')?:null,getenv('ANYTOUR_MONITOR_COLLECTOR_RUN_ID')?:null);
        $out['run_policy']='collector_saved_monitor';$out['night_date']=$nightGate['night_date'];$out['budget_seconds']=1800;
        if(!$nightGate['due']){
            $out['ok']=true;$out['skipped']=$nightGate['reason'];$out['nightly_cycle_completed']=false;$out['duration_seconds']=time()-$startedAt;
            hook_output_and_exit($out);
        }
    }
    hook_need(defined('HOOK_ROSTER_GZIP_B64')&&defined('HOOK_RUNTIME_PINS_B64'),'hook_bundle_required');
    $root='/var/www/anytoour/data/www/app.anytoour.ru';hook_need(realpath($root)===$root&&chdir($root),'hook_bot_root_unavailable');
    $installed=trim((string)shell_exec('git rev-parse HEAD'));hook_need(preg_match('/\A[a-f0-9]{40}\z/D',$installed)===1&&trim((string)shell_exec('git status --porcelain --untracked-files=no'))==='','hook_runtime_changed');
    exec('git merge-base --is-ancestor '.escapeshellarg(HOOK_ACCEPTED_BASE).' HEAD',$unused,$ancestor);hook_need($ancestor===0,'hook_runtime_not_accepted_descendant');
    foreach(json_decode(base64_decode(HOOK_RUNTIME_PINS_B64,true),true,32,JSON_THROW_ON_ERROR) as $file=>$hash)hook_need(trim((string)shell_exec('git hash-object -- '.escapeshellarg($file)))===$hash,'hook_publishing_surface_changed');
    $private='/var/www/anytoour/data/channel-post-operations';hook_need(!is_link($private)&&realpath($private)===$private,'hook_private_root_changed');
    $path=$private.'/monitor-price-after-collection-v1.jsonl';hook_need(!is_link($path),'hook_journal_symlink');$existed=is_file($path);umask(0077);
    if($inspect){$raw=$existed?hook_read($path,100000000):'';}
    else{$lock=fopen($path,'c+');hook_need($lock!==false&&flock($lock,LOCK_EX|LOCK_NB),'hook_already_running');$raw=stream_get_contents($lock);}
    hook_need(is_string($raw)&&(!$existed||$raw!=='')&&($raw===''||str_ends_with($raw,"\n")),'hook_journal_incomplete_stop');
    $state=['schema'=>1,'pending'=>null,'completed'=>[]];if($raw!==''){$lines=explode("\n",rtrim($raw,"\n"));$state=json_decode(end($lines),true,64,JSON_THROW_ON_ERROR);}elseif(!$inspect)hook_append($lock,$state);
    if($nightly){
        $cycleRecovery=monitor_hook_cycle_recovery_proof();
        if(($state['nightly_cycle']['collector_run_id']??null)===$cycleRecovery['collector_run_id']&&($state['nightly_cycle']['state']??null)==='started'){
            $childRaw=hook_read($private.'/'.$cycleRecovery['operation'].'/receipt.jsonl');
            $closed=monitor_hook_close_reconciled_cycle($state,monitor_hook_cycle_journal_pin($raw),monitor_hook_cycle_journal_pin($childRaw),
                monitor_hook_parse_journal($childRaw,$cycleRecovery['operation']),$startedAt);
            hook_need($closed!==null,'hook_cycle_recovery_terminal_missing');
            hook_append($lock,$closed);$state=$closed;
            $out['reconciled_prior_collector']=['collector_run_id'=>$cycleRecovery['collector_run_id'],'state'=>'interrupted_reconciled','post_writes'=>0,'feed_writes'=>0];
        }
        $attemptedRuns=$raw===''?[]:monitor_hook_attempted_collector_runs($lines);
        $nightGate=monitor_hook_nightly_gate($state,$startedAt,getenv('ANYTOUR_MONITOR_COLLECTION_STARTED_AT')?:null,getenv('ANYTOUR_MONITOR_COLLECTOR_RUN_ID')?:null,$attemptedRuns);
        if(!$nightGate['due']){
            $out['ok']=!$nightGate['blocked'];$out['skipped']=$nightGate['reason'];$out['nightly_cycle_completed']=false;
            $out['prior_nightly_cycle']=$nightGate['cycle']??null;$out['duration_seconds']=time()-$startedAt;
            if($nightGate['blocked'])$out['error']=$nightGate['reason'];
            hook_output_and_exit($out,$lock);
        }
        $state['nightly_cycle']=$nightGate['cycle'];hook_append($lock,$state);
    }
    if($state['pending']!==null){hook_need(!$inspect,'hook_pending_operation_inspect_journal');$reconciled=hook_finish_feed($state,$lock,$installed);$out[$reconciled==='held_before_write'?'reconciled_prior_hold':'reconciled_prior_feed']=true;}
    hook_need(!isset($state['holds'])||is_array($state['holds']),'hook_hold_state_invalid');
    ob_start();try{require $root.'/config.php';require_once $root.'/services/ChannelPostRuntime.php';require_once $root.'/services/ChannelPostAdminService.php';}finally{ob_end_clean();}
    $registry=new ChannelPostRegistryService(ConversationDb::connection());$roster=json_decode(gzdecode(base64_decode(HOOK_ROSTER_GZIP_B64,true)),true,64,JSON_THROW_ON_ERROR)['rows'];
    hook_need(count($roster)===HOOK_ROSTER_COUNT&&count(array_unique(array_column($roster,'tourvisor_hotel_id')))===HOOK_ROSTER_COUNT,'hook_roster_scope_changed');
    $roster=monitor_hook_order($roster,$state['completed']);$policy=monitor_hook_pass_policy($nightly,count($roster),$nightly?$startedAt:time());
    $out['budget_seconds']=$policy['budget_seconds'];$out['finish_reserve_seconds']=$policy['finish_reserve_seconds'];
    $today=new DateTimeImmutable('today',new DateTimeZone('Europe/Moscow'));
    $snapshotsFetchedAt=time();$snapshots=hook_snapshots($roster,$today);$out['snapshot_reads']=1;
    foreach($roster as $row){
        if(!$inspect&&!monitor_hook_can_start_pair($policy,time(),$out['updated_pairs'])){$out['bounded_pass_stopped']=true;break;}
        if($nightly&&monitor_hook_snapshot_refresh_due($snapshotsFetchedAt,time())){
            $snapshotsFetchedAt=time();$snapshots=hook_snapshots($roster,$today);$out['snapshot_reads']++;
            if(!monitor_hook_can_start_pair($policy,time(),$out['updated_pairs'])){$out['bounded_pass_stopped']=true;break;}
        }
        $out['scanned_pairs']++;
        $tv=$row['tourvisor_hotel_id'];
        if(isset($state['holds'][$tv])){
            $hold=$state['holds'][$tv];hook_need(is_array($hold)&&is_string($hold['operation']??null),'hook_hold_state_invalid');
            $reason=monitor_hook_hold_reason($tv,$hold,hook_journal($hold['operation']));
            $out['held_pairs']++;$out['held_ids'][]=$tv;$out['deferred'][]=['tourvisor_hotel_id'=>$tv,'reason'=>$reason];continue;
        }
        try{
            $context=$inspect?hook_context($row,$registry):null;
            if($inspect)$out['verified_ids'][]=$tv;
            $snapshot=$snapshots[$tv];
            if(($snapshot['item']['state']??'')==='no_fresh_observation'){$out['deferred'][]=['tourvisor_hotel_id'=>$tv,'reason'=>'no_fresh_observation'];continue;}
            $price=monitor_price_observation($snapshot,$tv,time());$op=monitor_price_operation($price);$context=$context??hook_context($row,$registry);$prior=hook_journal($op);
            if(!$inspect)$out['verified_ids'][]=$tv;
            $knownFailure=monitor_hook_known_failure($row,$price);hook_need($knownFailure===null,$knownFailure??'hook_known_failure');
            if($prior!==null){
                hook_need(($prior['complete']??false)===true,'hook_post_outcome_unresolved_stop');
                foreach($context as $p)hook_need(($p['snapshot']['metadata']['monitor_price_record_id']??null)===$price['record_id'],'hook_completed_record_no_longer_current');
                $out['unchanged_pairs']++;$out['unchanged_ids'][]=$tv;continue;
            }
            $r=array_intersect_key($row,array_flip(['key','hotel_id','tourvisor_hotel_id','logical_id','original_publication_operation','cover_sha256']));$r['channel_texts']=[];
            if(array_key_exists('legacy_proof',$row)){$r['legacy_proof']=$row['legacy_proof'];$r['posts']=$row['posts'];}
            if(array_key_exists('caption_proof',$row)){$r['caption_proof']=$row['caption_proof'];$r['posts']=$row['posts'];}
            foreach($context as $channel=>$p){$text=monitor_hook_draft($row['templates'][$channel],$price);$old=$p['snapshot']['text'];
                $r['channel_texts'][$channel]=['old_text'=>$old,'old_text_sha256'=>hash('sha256',$old),'new_text'=>$text,'new_text_sha256'=>hash('sha256',$text)];
                if(!array_key_exists('legacy_proof',$row)&&!array_key_exists('caption_proof',$row))$r['channel_texts'][$channel]['original_text_sha256']=$row['original_text_sha256'];
                $r['channel_texts'][$channel]['expected_version_id']=(int)$p['version_id'];$r['channel_texts'][$channel]['expected_post_url']=$row['posts'][$channel];}
            $input=['operation'=>$op,'price_basis'=>'scheduled_monitor_result','monitor_snapshot'=>$snapshot,'button_text'=>'✈️ Узнать стоимость тура','feed_binding'=>$row['feed_binding'],'rows'=>[$r]];
            $raw=json_encode($input,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
        }catch(Throwable $e){
            if(str_contains($e->getMessage(),'unresolved')||str_contains($e->getMessage(),'incomplete'))throw $e;
            $out['deferred'][]=['tourvisor_hotel_id'=>$tv,'reason'=>preg_match('/\A[a-z0-9_]+\z/D',$e->getMessage())?$e->getMessage():'hook_candidate_invalid'];continue;
        }
        $out['eligible_pairs']++;$out['eligible_ids'][]=$tv;if($inspect)continue;
        $state['pending']=['tourvisor_hotel_id'=>$tv,'operation'=>$op,'phase'=>'post_started','at'=>gmdate('c')];hook_append($lock,$state);
        $failure=null;
        try{hook_child(base64_decode(HOOK_EDITOR_B64,true),['EXPECTED_INSTALLED_SHA'=>$installed,'APPROVED_UPDATE_OPERATION'=>$op,'APPROVED_UPDATE_TOURVISOR_ID'=>$tv,'APPROVED_UPDATE_HOTEL_ID'=>$row['hotel_id'],'APPROVED_UPDATE_KEY_B64'=>base64_encode($row['key']),'APPROVED_UPDATE_LOGICAL_B64'=>base64_encode($row['logical_id']),'APPROVED_MANIFEST_SHA256'=>hash('sha256',$raw),'MANIFEST_BASE64'=>base64_encode($raw)],$failure);}
        catch(Throwable $e){
            if($failure!==null){
                $state['pending']['child_failure']=$failure;hook_append($lock,$state);
                if(monitor_hook_local_preflight_failure($failure)){
                    $terminal=hook_abandon_failed_preflight($op,$failure);$held=monitor_hook_apply_abandonment($state,$terminal);
                    hook_need($held!==null,'hook_no_write_terminal_missing_stop');$state=$held;hook_append($lock,$state);
                    $out['held_pairs']++;$out['held_ids'][]=$tv;
                    $out['deferred'][]=['tourvisor_hotel_id'=>$tv,'reason'=>'abandoned_before_write','preflight_error'=>$failure['error']];continue;
                }
            }throw $e;
        }
        hook_finish_feed($state,$lock,$installed);$out['updated_pairs']++;
        $confirmed=hook_journal($op);$postReceipts=[];
        foreach($confirmed['posts'] as $post)$postReceipts[]=$post['receipt'];
        $out['updates'][]=['tourvisor_hotel_id'=>$tv,'operation'=>$op,'price'=>$price['price'],'record_id'=>$price['record_id'],'posts'=>$postReceipts,'feed_operation'=>$state['completed'][$tv]['feed_operation']];
    }
    if($nightly){
        $state['nightly_cycle']['state']=!empty($out['bounded_pass_stopped'])?'budget_exhausted':'completed';
        $state['nightly_cycle']['finished_at']=gmdate('c');$state['nightly_cycle']['duration_seconds']=time()-$startedAt;
        foreach(['updated_pairs','unchanged_pairs','eligible_pairs','held_pairs','scanned_pairs'] as $key)$state['nightly_cycle'][$key]=$out[$key];
        $state['nightly_cycle']['remaining_unscanned_pairs']=count($roster)-$out['scanned_pairs'];hook_append($lock,$state);
        $out['nightly_cycle_completed']=$state['nightly_cycle']['state']==='completed';$out['nightly_cycle']=$state['nightly_cycle'];
    }
    $out['ok']=true;$out['installed_sha']=$installed;$out['roster_pairs']=count($roster);$out['completed_at']=gmdate('c');
    $out['existing_monitor_home_matches']=realpath((string)getenv('HOME').'/www/anytoour.ru/data')==='/var/www/anytoour/data/www/anytoour.ru/data';
}catch(Throwable $e){$out['error']=preg_match('/\A[a-z0-9_]+\z/D',$e->getMessage())?$e->getMessage():'hook_failed_read_original_journal';if(is_array($state['pending']['child_failure']??null))$out['child_failure']=$state['pending']['child_failure'];}
$out['duration_seconds']=time()-$startedAt;
$out['remaining_unscanned_pairs']=is_array($roster??null)?count($roster)-$out['scanned_pairs']:null;
$out['deferred_counts']=[];$out['error_counts']=[];
foreach($out['deferred'] as $item){$reason=$item['reason'];$out['deferred_counts'][$reason]=($out['deferred_counts'][$reason]??0)+1;
    if(!in_array($reason,['no_fresh_observation','abandoned_before_write'],true))$out['error_counts'][$reason]=($out['error_counts'][$reason]??0)+1;}
if(isset($out['error']))$out['error_counts'][$out['error']]=($out['error_counts'][$out['error']]??0)+1;
$out['budget_overrun_seconds']=max(0,$out['duration_seconds']-($out['budget_seconds']??480));
if($nightly&&($out['ok']??false)!==true)$out['nightly_cycle_completed']=false;
hook_output_and_exit($out,$lock??null);


