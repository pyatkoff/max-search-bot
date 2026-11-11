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
    $lines=explode("\n",rtrim($raw,"\n"));return json_decode(end($lines),true,64,JSON_THROW_ON_ERROR);
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
function hook_child(string $source,array $constants):array {
    $prefix="<?php\ndeclare(strict_types=1);\n";$header='';foreach($constants as $k=>$v)$header.='const '.$k.'='.var_export($v,true).";\n";
    $script=$prefix.$header.base64_decode(HOOK_CONTRACT_B64,true)."\n".$source;
    $pipes=[];$p=proc_open([PHP_BINARY], [['pipe','r'],['pipe','w'],['pipe','w']],$pipes,getcwd(),null,['bypass_shell'=>true]);
    hook_need(is_resource($p),'hook_child_start_failed');$written=0;while($written<strlen($script)){$n=fwrite($pipes[0],substr($script,$written));hook_need(is_int($n)&&$n>0,'hook_child_input_failed');$written+=$n;}fclose($pipes[0]);
    $raw=stream_get_contents($pipes[1]);fclose($pipes[1]);$errors=stream_get_contents($pipes[2]);fclose($pipes[2]);$exit=proc_close($p);
    hook_need(is_string($raw)&&strlen($raw)<2000000,'hook_child_output_invalid');$lines=explode("\n",rtrim($raw,"\n"));$result=json_decode(end($lines),true,64,JSON_THROW_ON_ERROR);
    hook_need($exit===0&&($result['ok']??false)===true,'hook_child_failed_read_original_journal');return $result;
}
function hook_context(array $row,ChannelPostRegistryService $registry):array {
    $result=[];
    foreach(['max','telegram'] as $platform){
        $dir='/var/www/anytoour/data/channel-post-operations/'.$row['original_publication_operation'];
        hook_need(!is_link($dir),'hook_original_directory_invalid');
        $original=json_decode(hook_read($dir.'/'.$platform.'-'.$row['logical_id'].'-approved.json',1048576),true,64,JSON_THROW_ON_ERROR);$proof=$original['receipt']??[];
        hook_need(($original['state']??'')==='confirmed'&&($original['text_sha256']??'')===$row['original_text_sha256']&&($original['photo_sha256']??'')===$row['cover_sha256']
            &&($proof['logical_id']??'')===$row['logical_id']&&(int)($proof['hotel_id']??0)===$row['hotel_id']&&($proof['platform']??'')===$platform
            &&($proof['post_url']??'')===$row['posts'][$platform]&&($proof['body_photo_button_verified']??false)===true,'hook_original_pair_mismatch');
        $p=$registry->publication((int)$proof['publication_id']);
        hook_need(is_array($p)&&$p['project_key']==='anytour'&&$p['platform']===$platform&&$p['state']==='sent'
            &&$p['external_message_id']===(string)$proof['message_id']&&($p['snapshot']['post_url']??'')===$row['posts'][$platform],'hook_publication_changed');
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
function hook_finish_feed(array &$state,$lock,string $installed):void {
    $pending=$state['pending'];$journal=hook_journal($pending['operation']);
    hook_need(is_array($journal)&&($journal['complete']??false)===true,'hook_post_outcome_unresolved_stop');
    if($pending['phase']==='post_started'){
        $input=hook_prepare_feed($journal,$installed);$raw=json_encode($input,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
        $state['pending']['phase']='feed_started';$state['pending']['feed_operation']=$input['operation'];
        $state['pending']['files']=array_map(static fn($r)=>array_intersect_key($r,array_flip(['name','sha256','bytes','rows','expected_previous_sha256'])),$input['files']);hook_append($lock,$state);
        hook_child(base64_decode(HOOK_WRITER_B64,true),['EXPECTED_INSTALLED_SHA'=>$installed,'PUBLIC_STATIC_FEED_OPERATION'=>$input['operation'],'PUBLIC_STATIC_FEED_INPUT_SHA256'=>hash('sha256',$raw),'PUBLIC_STATIC_FEED_INPUT_B64'=>base64_encode($raw)]);
    }
    // Unknown feed outcome is reconciled by exact local + public hashes only, never replayed.
    foreach($state['pending']['files'] as $file){
        hook_need(hash('sha256',hook_read(getcwd().'/feed/'.$file['name'],4000000))===$file['sha256'],'hook_feed_outcome_unresolved_stop');
        hook_need(hash('sha256',hook_get('https://app.anytoour.ru/feed/'.$file['name']))===$file['sha256'],'hook_public_feed_readback_pending');
    }
    $state['completed'][$pending['tourvisor_hotel_id']]=['operation'=>$pending['operation'],'at'=>gmdate('c'),'feed_operation'=>$state['pending']['feed_operation']];
    $state['pending']=null;hook_append($lock,$state);
}

$inspect=getenv('ANYTOUR_MONITOR_HOOK_INSPECT')==='1'||($argv[1]??'')==='--inspect';
$out=['ok'=>false,'mode'=>$inspect?'read_only_hook_inspection':'existing_monitor_post_feed_hook','new_posts'=>0,'supplier_calls'=>0,'site_writes'=>0,'updated_pairs'=>0,'unchanged_pairs'=>0,'eligible_pairs'=>0,'verified_ids'=>[],'eligible_ids'=>[],'unchanged_ids'=>[],'updates'=>[],'deferred'=>[]];
try{
    hook_need(count($argv)===1||($inspect&&count($argv)===2),'hook_argument_invalid');
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
    if($state['pending']!==null){hook_need(!$inspect,'hook_pending_operation_inspect_journal');hook_finish_feed($state,$lock,$installed);$out['reconciled_prior_feed']=true;}
    ob_start();try{require $root.'/config.php';require_once $root.'/services/ChannelPostRuntime.php';require_once $root.'/services/ChannelPostAdminService.php';}finally{ob_end_clean();}
    $registry=new ChannelPostRegistryService(ConversationDb::connection());$roster=json_decode(gzdecode(base64_decode(HOOK_ROSTER_GZIP_B64,true)),true,64,JSON_THROW_ON_ERROR)['rows'];
    hook_need(count($roster)===HOOK_ROSTER_COUNT&&count(array_unique(array_column($roster,'tourvisor_hotel_id')))===HOOK_ROSTER_COUNT,'hook_roster_scope_changed');
    $roster=monitor_hook_order($roster,$state['completed']);$deadline=time()+480;$passLimit=30;
    $today=new DateTimeImmutable('today',new DateTimeZone('Europe/Moscow'));
    $snapshots=[];
    foreach(array_chunk(array_column($roster,'tourvisor_hotel_id'),100) as $ids){
        $query=['tourvisorHotelIds'=>$ids,'departureId'=>1,'dateFrom'=>$today->modify('+1 day')->format('Y-m-d'),'dateTo'=>$today->modify('first day of this month')->modify('+12 months')->modify('-1 day')->format('Y-m-d')];
        $url='https://anytoour.ru/data/hotel-monitor-prices-read-v1.php?'.http_build_query($query,'','&',PHP_QUERY_RFC3986);
        $payload=json_decode(hook_get($url),true,64,JSON_THROW_ON_ERROR);
        foreach($ids as $tv)$snapshots[$tv]=monitor_hook_source($payload,$tv,$url);
    }
    foreach($roster as $row){
        if(!$inspect&&($out['updated_pairs']>=$passLimit||time()>=$deadline)){$out['bounded_pass_stopped']=true;break;}
        $tv=$row['tourvisor_hotel_id'];
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
            foreach($context as $channel=>$p){$text=monitor_hook_draft($row['templates'][$channel],$price);$old=$p['snapshot']['text'];
                $r['channel_texts'][$channel]=['old_text'=>$old,'old_text_sha256'=>hash('sha256',$old),'new_text'=>$text,'new_text_sha256'=>hash('sha256',$text),'original_text_sha256'=>$row['original_text_sha256'],'expected_version_id'=>(int)$p['version_id'],'expected_post_url'=>$row['posts'][$channel]];}
            $input=['operation'=>$op,'price_basis'=>'scheduled_monitor_result','monitor_snapshot'=>$snapshot,'button_text'=>'✈️ Узнать стоимость тура','feed_binding'=>$row['feed_binding'],'rows'=>[$r]];
            $raw=json_encode($input,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
        }catch(Throwable $e){
            if(str_contains($e->getMessage(),'unresolved')||str_contains($e->getMessage(),'incomplete'))throw $e;
            $out['deferred'][]=['tourvisor_hotel_id'=>$tv,'reason'=>preg_match('/\A[a-z0-9_]+\z/D',$e->getMessage())?$e->getMessage():'hook_candidate_invalid'];continue;
        }
        $out['eligible_pairs']++;$out['eligible_ids'][]=$tv;if($inspect)continue;
        $state['pending']=['tourvisor_hotel_id'=>$tv,'operation'=>$op,'phase'=>'post_started','at'=>gmdate('c')];hook_append($lock,$state);
        hook_child(base64_decode(HOOK_EDITOR_B64,true),['EXPECTED_INSTALLED_SHA'=>$installed,'APPROVED_UPDATE_OPERATION'=>$op,'APPROVED_UPDATE_TOURVISOR_ID'=>$tv,'APPROVED_UPDATE_HOTEL_ID'=>$row['hotel_id'],'APPROVED_UPDATE_KEY_B64'=>base64_encode($row['key']),'APPROVED_UPDATE_LOGICAL_B64'=>base64_encode($row['logical_id']),'APPROVED_MANIFEST_SHA256'=>hash('sha256',$raw),'MANIFEST_BASE64'=>base64_encode($raw)]);
        hook_finish_feed($state,$lock,$installed);$out['updated_pairs']++;
        $confirmed=hook_journal($op);$postReceipts=[];
        foreach($confirmed['posts'] as $post)$postReceipts[]=$post['receipt'];
        $out['updates'][]=['tourvisor_hotel_id'=>$tv,'operation'=>$op,'price'=>$price['price'],'record_id'=>$price['record_id'],'posts'=>$postReceipts,'feed_operation'=>$state['completed'][$tv]['feed_operation']];
    }
    $out['ok']=true;$out['installed_sha']=$installed;$out['roster_pairs']=count($roster);$out['completed_at']=gmdate('c');
    $out['existing_monitor_home_matches']=realpath((string)getenv('HOME').'/www/anytoour.ru/data')==='/var/www/anytoour/data/www/anytoour.ru/data';
}catch(Throwable $e){$out['error']=preg_match('/\A[a-z0-9_]+\z/D',$e->getMessage())?$e->getMessage():'hook_failed_read_original_journal';}
if(isset($lock)&&is_resource($lock)){flock($lock,LOCK_UN);fclose($lock);}
echo 'MONITOR_POST_FEED_RESULT='.json_encode($out,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR),"\n";exit($out['ok']?0:2);
