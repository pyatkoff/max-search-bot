<?php
declare(strict_types=1);
// Fresh feed preparation after the expired, proved pre-file-lock failure. No post, database or provider access.
const FEEDS_ROOT = '/var/www/anytoour/data/channel-post-operations';
const FEEDS_RUNTIME = '/var/www/anytoour/data/www/app.anytoour.ru';
const FEEDS_RUNTIME_SHA = 'c734f6632cdb492c0a5a82736023d5c485e0cea5';
const FEEDS_OPERATION = 'commercial-monitor-price-h2651-3c6637d73d3424159b7d01b9ffcf2e2051fc3fbae084bb645c7b259c33413d11';
const FEEDS_RECORD = '3c6637d73d3424159b7d01b9ffcf2e2051fc3fbae084bb645c7b259c33413d11';
const FEEDS_MANIFEST = '4515961a91d0f940a3df10361f9dab1adad7360d24b3f55b067c4e81411a9784';
const FEEDS_FEED_OPERATION = 'commercial-channel-hotel-feeds-20261112-update16245045683983166';
const FEEDS_GLOBAL_SHA = '1b8177e3c307c901244b9c9836b8d569f2f8d835e0b1c5a35f6428e8d9ee8693';
const FEEDS_POST_SHA = 'cf35ed5eacaccd8939262e1231f43cc00752938b72c1260d8d4751afb50f832d';
const FEEDS_GLOBAL_ORIGINAL_SHA = 'cb30682781ed2a55972564c98604ca26694e0c3154141ad936dd5e06252baf51';
const FEEDS_POST_ORIGINAL_SHA = '2e8404f8804911fa062f458bd2adee0426a35a4428c99a377be4fd5518d06b27';
const FEEDS_SNAPSHOT_SHA = 'e81d1b789ca3a606474a69d13dd5d7d741e84202c084542da2aa66c0174c6296';
const FEEDS_OLD_NOT_AFTER = '2026-11-12T10:32:03Z';
const FEEDS_FILES = [
    ['name'=>'hotel-posts-telegram.xml','sha256'=>'2cc3a222b494dce2e814859f5b9875f9a8d1f6b2c57fe2dedf2493df1199cf67','bytes'=>407346,'rows'=>509,'expected_previous_sha256'=>'58dadb0634b80d4adbc6c9d4d8b6a58f3a45f1dbafadff6bcaa1569615885776'],
    ['name'=>'hotel-posts-max.xml','sha256'=>'95986c1774335130c973706ce1ce50376ba7bd4b35da66a0b146a38cfb2a148e','bytes'=>489747,'rows'=>509,'expected_previous_sha256'=>'b5eb33c4590cd2e29e4bc6dec5c93092251b20f45c8eaf2516716cec500e1477'],
    ['name'=>'hotel-posts-all.xml','sha256'=>'ba6836f8d0929be5c7c4d4e2b1dcf70b016b60722223bfaf1b22192215ab61e2','bytes'=>896720,'rows'=>1018,'expected_previous_sha256'=>'6ae5b82db8549ea261c9969d499fb7ef31f9354f1a7a8e3c706a4a29de5c7449']
];
function feeds_need(bool $ok, string $why): void { if (!$ok) throw new RuntimeException($why); }
function feeds_json(array $data): string { return json_encode($data,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR); }
function feeds_prefix(string $raw, int $length, string $sha): void {
    feeds_need(strlen($raw)>=$length && hash('sha256',substr($raw,0,$length))===$sha,'feeds_original_prefix_changed');
}
function feeds_parse(string $raw, int $bytes, int $lines, string $sha): array {
    feeds_need(strlen($raw)===$bytes && hash('sha256',$raw)===$sha && str_ends_with($raw,"\n"),'feeds_journal_cas_changed');
    $rows=explode("\n",substr($raw,0,-1)); feeds_need(count($rows)===$lines,'feeds_journal_lines_changed'); $last=null;
    foreach($rows as $row){$last=json_decode($row,true,64,JSON_THROW_ON_ERROR);feeds_need(is_array($last),'feeds_journal_invalid');}
    return $last;
}
function feeds_open(string $path, bool $exclusive, array &$locks): array {
    feeds_need(!is_link(dirname($path)) && realpath(dirname($path))===dirname($path) && !is_link($path) && is_file($path)
        && realpath($path)===$path,'feeds_journal_path_changed');
    $stream=fopen($path,$exclusive?'r+b':'rb'); feeds_need(is_resource($stream),'feeds_journal_open_failed'); $locks[]=$stream;
    feeds_need(flock($stream,($exclusive?LOCK_EX:LOCK_SH)|LOCK_NB),'feeds_original_operation_busy');
    $stat=fstat($stream); $named=lstat($path);
    feeds_need(is_array($stat)&&is_array($named)&&$stat['ino']===$named['ino']&&$stat['dev']===$named['dev']
        &&$stat['size']>0&&$stat['size']<100000000,'feeds_journal_identity_changed');
    $raw=stream_get_contents($stream,100000000); feeds_need(is_string($raw)&&strlen($raw)===$stat['size'],'feeds_journal_read_failed');
    return [$stream,$raw];
}
function feeds_runtime(): void {
    feeds_need(realpath(FEEDS_RUNTIME)===FEEDS_RUNTIME && chdir(FEEDS_RUNTIME),'feeds_runtime_path_changed');
    feeds_need(trim((string)shell_exec('git rev-parse --verify HEAD'))===FEEDS_RUNTIME_SHA
        && trim((string)shell_exec('git --no-optional-locks status --porcelain --untracked-files=no'))==='','feeds_runtime_changed');
    $pins=json_decode(base64_decode(HOOK_RUNTIME_PINS_B64,true),true,32,JSON_THROW_ON_ERROR); feeds_need(count($pins)===22,'feeds_runtime_pin_count_changed');
    foreach($pins as $path=>$sha){
        feeds_need(preg_match('~\A(?:services|integrations|migrations)/[A-Za-z0-9_.-]+\z~D',$path)===1&&!is_link($path)&&is_file($path),'feeds_runtime_file_invalid');
        feeds_need(trim((string)shell_exec('git hash-object -- '.escapeshellarg($path)))===$sha,'feeds_runtime_pin_changed');
    }
}
/** Only the observed pre-file-lock failure permits one new writer admission. */
function feeds_pending(array $global): void {
    $p=$global['pending']??null;
    feeds_need(($global['schema']??null)===1&&count($global['completed']??[])===131&&!isset($global['completed'][2651])
        &&is_array($p)&&($p['tourvisor_hotel_id']??null)===2651&&($p['operation']??null)===FEEDS_OPERATION
        &&($p['phase']??null)==='feed_started'&&($p['feed_operation']??null)===FEEDS_FEED_OPERATION
        &&($p['child_failure']??null)===['exit_code'=>2,'error'=>'monitor_receipt_busy']
        &&($p['files']??null)===FEEDS_FILES&&!array_key_exists('feed_continuation',$p),'feeds_original_pending_not_admitted');
}
function feeds_post(array $post, int $now): array {
    feeds_need(($post['operation']??null)===FEEDS_OPERATION&&($post['manifest_hash']??null)===FEEDS_MANIFEST
        &&($post['complete']??null)===true&&($post['configs']??null)===[]&&!isset($post['terminal'])
        &&hash('sha256',feeds_json($post['monitor_snapshot']??[]))===FEEDS_SNAPSHOT_SHA
        &&array_keys($post['posts']??[])===['max:commercial-rose-rayhaan-2651-v1','telegram:commercial-rose-rayhaan-2651-v1'],
        'feeds_original_completed_posts_changed');
    $binding=$post['feed_binding']; $binding['operation']=FEEDS_OPERATION; $binding['manifest_hash']=FEEDS_MANIFEST;
    $update=monitor_price_confirmed_journal($post,$binding,$now);
    feeds_need($update['price']['record_id']===FEEDS_RECORD&&$update['price']['price']===150221,'feeds_original_price_changed');
    foreach(['max'=>[957,3724,2995,'mid.ffffb6caf0606fed01a1b793c24c6a94','ed064236b2c0d041d1e08c5e1c84d55cd3cabe15d617ff2ed35d3842e3a9f65f'],
        'telegram'=>[947,3725,2996,'424','5fbcd056df1e7d7558264ee3612c937a0bb6094069a89e9830dca8c53f7f9301']] as $platform=>$expected){
        $r=$update['receipts'][$platform];
        feeds_need(($r['publication_id']??null)===$expected[0]&&($r['version_id']??null)===$expected[1]
            &&($r['previous_version_id']??null)===$expected[2]&&($r['message_id']??null)===$expected[3]
            &&($r['text_sha256']??null)===$expected[4],'feeds_confirmed_receipt_changed');
    }
    return $update;
}
function feeds_previous(string $current, bool $tmpAbsent, array $file): void {
    feeds_need($current===$file['expected_previous_sha256']&&$current!==$file['sha256']&&$tmpAbsent,'feeds_first_file_write_not_proven');
}
function feeds_current(): array {
    $dir=FEEDS_RUNTIME.'/feed'; feeds_need(!is_link($dir)&&realpath($dir)===$dir,'feeds_directory_changed'); $before=[];
    foreach(FEEDS_FILES as $file){
        $raw=hook_read($dir.'/'.$file['name'],4000000);
        $tmp=$dir.'/.'.FEEDS_FEED_OPERATION.'-'.$file['name'].'.tmp';
        feeds_previous(hash('sha256',$raw),!file_exists($tmp)&&!is_link($tmp),$file); $before[$file['name']]=$raw;
    }
    return $before;
}
function feeds_window(int $generated, int $now): void {
    feeds_need($generated<=$now+60&&$generated>=$now-900,'feeds_input_window_expired');
}
/** Historical proof only: the expired XML/input is never sent to the writer. */
function feeds_reconstruct_old_proof(array $before, array $update): array {
    $matches=[];
    foreach(['2026-11-12T10:17:03Z','2026-11-12T10:17:04Z'] as $stamp){
        $at=monitor_price_time($stamp); $matchesAll=true;
        foreach(FEEDS_FILES as $file){
            $bytes=monitor_price_render_feed($before[$file['name']],[$update],$file['name'],$at);
            if(hash('sha256',$bytes)!==$file['sha256']||strlen($bytes)!==$file['bytes']||substr_count($bytes,'<offer id="')!==$file['rows']) $matchesAll=false;
        }
        if($matchesAll)$matches[]=$stamp;
    }
    feeds_need($matches===['2026-11-12T10:17:03Z','2026-11-12T10:17:04Z'],'feeds_original_intended_bytes_not_reconstructed');
    return ['state'=>'supersession_planned','original_pending_files'=>FEEDS_FILES,'generated_at_candidate'=>$matches[0],
        'matching_seconds'=>$matches,'expires_at'=>FEEDS_OLD_NOT_AFTER,'expired'=>true,
        'exact_old_intended_xml_hashes_verified'=>true,'original_raw_input_sha256_available'=>false,'published'=>false];
}
/** PLAN_AT is fixed by the carrier and cannot extend the historical input. */
function feeds_fresh_clock(string $planAt, int $now): int {
    feeds_need(preg_match('/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z\z/D',$planAt)===1,'feeds_plan_time_invalid');
    $at=monitor_price_time($planAt);
    feeds_need(gmdate('Y-m-d\TH:i:s\Z',$at)===$planAt,'feeds_plan_time_invalid');
    $oldLimit=monitor_price_time(FEEDS_OLD_NOT_AFTER);
    feeds_need($now>$oldLimit&&$at>$oldLimit,'feeds_old_preparation_not_expired');
    feeds_window($at,$now);return $at;
}
function feeds_plan_binding($expected, string $actual): void {
    feeds_need(is_string($expected)&&preg_match('/\A[a-f0-9]{64}\z/D',$expected)===1&&hash_equals($expected,$actual),
        'feeds_fresh_input_not_accepted_by_inspect');
}
function feeds_fresh_input(array $before, array $update, string $planAt, int $now): array {
    $at=feeds_fresh_clock($planAt,$now);$files=[];
    foreach(FEEDS_FILES as $old){
        $bytes=monitor_price_render_feed($before[$old['name']],[$update],$old['name'],$at);$sha=hash('sha256',$bytes);
        feeds_need($sha!==$old['sha256']&&$sha!==$old['expected_previous_sha256']&&strlen($bytes)===$old['bytes']
            &&substr_count($bytes,'<offer id="')===$old['rows'],'feeds_fresh_target_invalid');
        $files[]=['name'=>$old['name'],'sha256'=>$sha,'bytes'=>strlen($bytes),'rows'=>$old['rows'],
            'expected_previous_sha256'=>$old['expected_previous_sha256'],'content_base64'=>base64_encode($bytes)];
    }
    return ['schema_version'=>3,'operation'=>FEEDS_FEED_OPERATION,'files'=>$files,'generated_at'=>$planAt,'price_updates'=>[$update['binding']]];
}
function feeds_self_test(): void {
    $n=0; $check=static function(bool $ok,string $name)use(&$n):void{feeds_need($ok,'feeds_fixture_'.$name);$n++;};
    $reject=static function(callable $f,string $name)use(&$n):void{$failed=false;try{$f();}catch(Throwable $e){$failed=true;}feeds_need($failed,'feeds_fixture_'.$name);$n++;};
    $g=['schema'=>1,'completed'=>array_fill(0,131,[]),'pending'=>['tourvisor_hotel_id'=>2651,'operation'=>FEEDS_OPERATION,
        'phase'=>'feed_started','feed_operation'=>FEEDS_FEED_OPERATION,'child_failure'=>['exit_code'=>2,'error'=>'monitor_receipt_busy'],'files'=>FEEDS_FILES]];
    feeds_pending($g);$check(true,'exact_pre_file_failure');
    $bad=$g;$bad['pending']['child_failure']['error']='static_write_failed';$reject(static fn()=>feeds_pending($bad),'unknown_file_outcome_rejected');
    $bad=$g;$bad['pending']['feed_continuation']=['state'=>'writer_intent'];$reject(static fn()=>feeds_pending($bad),'durable_intent_not_replayed');
    $bad=$g;$bad['pending']['phase']='post_started';$reject(static fn()=>feeds_pending($bad),'phase_never_rewound');
    $bad=$g;$bad['pending']['files'][2]['sha256']=str_repeat('a',64);$reject(static fn()=>feeds_pending($bad),'changed_target_hash');
    feeds_previous(FEEDS_FILES[0]['expected_previous_sha256'],true,FEEDS_FILES[0]);$check(true,'exact_previous_no_tmp');
    $reject(static fn()=>feeds_previous(FEEDS_FILES[0]['sha256'],true,FEEDS_FILES[0]),'already_written_not_replayed');
    $reject(static fn()=>feeds_previous(FEEDS_FILES[0]['expected_previous_sha256'],false,FEEDS_FILES[0]),'existing_tmp_is_unknown');
    $reject(static fn()=>feeds_previous(str_repeat('a',64),true,FEEDS_FILES[0]),'changed_previous_rejected');
    feeds_window(1000,1899);$check(true,'original_window_valid');
    $reject(static fn()=>feeds_window(1000,1901),'window_never_extended');
    $prefix="original\n";feeds_prefix($prefix."later\n",strlen($prefix),hash('sha256',$prefix));$check(true,'append_preserves_prefix');
    $reject(static fn()=>feeds_prefix("changed\nlater\n",strlen($prefix),hash('sha256',$prefix)),'prefix_change_rejected');
    $freshNow=monitor_price_time('2026-11-12T10:33:00Z');
    $check(feeds_fresh_clock('2026-11-12T10:33:00Z',$freshNow)===$freshNow,'fresh_plan_after_expired_old_input');
    $reject(static fn()=>feeds_fresh_clock('2026-11-12T10:32:04Z',monitor_price_time(FEEDS_OLD_NOT_AFTER)),'old_window_must_already_be_expired');
    $reject(static fn()=>feeds_fresh_clock(FEEDS_OLD_NOT_AFTER,$freshNow),'fresh_plan_cannot_reuse_old_deadline');
    $reject(static fn()=>feeds_fresh_clock('2026-11-12T10:31:00Z',$freshNow),'fresh_plan_cannot_predate_old_expiry');
    $reject(static fn()=>feeds_fresh_clock('2026-11-12T10:33:00Z',$freshNow+901),'fresh_input_900_seconds_limit');
    $reject(static fn()=>feeds_fresh_clock('2026-11-12T10:34:01Z',$freshNow),'fresh_input_future_clock_limit');
    $reject(static fn()=>feeds_fresh_clock('2026-11-12T10:33:00+00:00',$freshNow),'plan_requires_canonical_utc');
    $reject(static fn()=>feeds_fresh_clock('2026-11-32T10:33:00Z',$freshNow),'plan_invalid_calendar_rejected');
    feeds_plan_binding(str_repeat('a',64),str_repeat('a',64));$check(true,'same_inspected_input');
    $reject(static fn()=>feeds_plan_binding(str_repeat('a',64),str_repeat('b',64)),'different_inspected_input_rejected');
    $reject(static fn()=>feeds_plan_binding(false,str_repeat('a',64)),'missing_inspected_input_rejected');
    echo 'MONITOR_PENDING_2651_FRESH_FEEDS_SELFTEST_OK cases='.$n." no_network=1 no_writes=1\n";
}
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
if(!defined('HOOK_WRITER_B64')||!function_exists('hook_finish_feed')||!defined('FEEDS_DRIVER_SHA256')){fwrite(STDERR,"feeds_frozen_bundle_required\n");exit(2);}
if(count($argv)===2&&$argv[1]==='--self-test'){feeds_self_test();exit(0);}
$apply=getenv('ANYTOUR_MONITOR_PENDING_FEED_APPLY')==='1';
$out=['ok'=>false,'mode'=>$apply?'apply_fresh_original_feed_preparation':'inspect_fresh_original_feed_preparation','operation'=>FEEDS_OPERATION,
    'checked_at'=>gmdate('c'),'driver_sha256'=>FEEDS_DRIVER_SHA256,'embedded_prefix_sha256'=>FEEDS_EMBEDDED_PREFIX_SHA256,
    'max_puts'=>0,'telegram_edit_attempts'=>0,'database_write_calls'=>0,'post_journal_appends'=>0,'supplier_calls'=>0,'new_posts'=>0,
    'provider_requests'=>0,'saved_monitor_gets'=>0,'feed_writer_attempts'=>0,'global_journal_appends_by_continuation'=>0,'original_completed_pairs_replayed'=>0];
$locks=[];
try{
    feeds_need(count($argv)===1&&in_array(getenv('ANYTOUR_MONITOR_PENDING_FEED_APPLY'),[false,'','1'],true),'feeds_arguments_invalid');
    $planAt=getenv('ANYTOUR_MONITOR_PENDING_FEED_PLAN_AT');feeds_need(is_string($planAt),'feeds_plan_time_required');
    feeds_fresh_clock($planAt,time());
    feeds_need(FEEDS_EMBEDDED_PREFIX_SHA256==='2e7bb2f6c3c50b789a58a2f692314f6e477103b84bb44950599237fb8a273191','feeds_frozen_prefix_changed');
    feeds_runtime();$out['runtime_sha']=FEEDS_RUNTIME_SHA;
    feeds_need(!is_link(FEEDS_ROOT)&&realpath(FEEDS_ROOT)===FEEDS_ROOT,'feeds_private_root_changed');
    [$globalLock,$globalRaw]=feeds_open(FEEDS_ROOT.'/monitor-price-after-collection-v1.jsonl',$apply,$locks);
    $global=feeds_parse($globalRaw,6388485,400,FEEDS_GLOBAL_SHA);feeds_prefix($globalRaw,6324466,FEEDS_GLOBAL_ORIGINAL_SHA);feeds_pending($global);
    // The post journal is read under SH, never EX. It is closed before the writer obtains its own SH.
    [$postLock,$postRaw]=feeds_open(FEEDS_ROOT.'/'.FEEDS_OPERATION.'/receipt.jsonl',false,$locks);
    $post=feeds_parse($postRaw,123238,8,FEEDS_POST_SHA);feeds_prefix($postRaw,26418,FEEDS_POST_ORIGINAL_SHA);$update=feeds_post($post,time());
    $completedBefore=$global['completed'];$holdsBefore=$global['holds']??[];
    $before=feeds_current();$oldPreparation=feeds_reconstruct_old_proof($before,$update);
    $input=feeds_fresh_input($before,$update,$planAt,time());$inputRaw=feeds_json($input);$inputSha=hash('sha256',$inputRaw);
    $newFiles=array_map(static fn(array $f):array=>array_intersect_key($f,array_flip(['name','sha256','bytes','rows','expected_previous_sha256'])),$input['files']);
    $out['original_manifest_sha256']=FEEDS_MANIFEST;$out['original_post_journal_sha256']=FEEDS_POST_SHA;$out['original_post_prefix_sha256']=FEEDS_POST_ORIGINAL_SHA;
    $out['original_global_journal_sha256']=FEEDS_GLOBAL_SHA;$out['original_global_prefix_sha256']=FEEDS_GLOBAL_ORIGINAL_SHA;$out['original_journal_complete']=true;
    $out['old_preparation']=$oldPreparation;
    $out['fresh_preparation']=['plan_at'=>$planAt,'generated_at'=>$input['generated_at'],
        'expires_at'=>gmdate('Y-m-d\TH:i:s\Z',monitor_price_time($planAt)+900),'schema_version'=>3,'input_sha256'=>$inputSha,'files'=>$newFiles,
        'old_pending_files_preserved'=>true,'same_confirmed_post_receipts'=>true,'same_feed_operation'=>true,
        'price_record_id'=>FEEDS_RECORD,'price_observed_at_preserved'=>$update['price']['observed_at'],'fresh_price_observation_created'=>false,'published'=>false];
    $out['completed_post_receipts']=[$update['receipts']['max'],$update['receipts']['telegram']];
    if(!$apply){$out['ok']=true;$out['ready_for_explicit_feed_apply']=true;}
    else{
        feeds_plan_binding(getenv('ANYTOUR_MONITOR_PENDING_FEED_EXPECTED_INPUT_SHA256'),$inputSha);
        feeds_runtime();feeds_current();feeds_post($post,time());feeds_fresh_clock($planAt,time());
        $oldPreparation['state']='superseded_before_first_file_write';
        $global['pending']['feed_continuation']=['state'=>'writer_intent','kind'=>'fresh_preparation_after_expired_pre_file_failure',
            'at'=>gmdate('c'),'original_global_sha256'=>FEEDS_GLOBAL_SHA,'original_post_sha256'=>FEEDS_POST_SHA,
            'proved_failure'=>['exit_code'=>2,'error'=>'monitor_receipt_busy'],'old_preparation'=>$oldPreparation,
            'fresh_preparation'=>$out['fresh_preparation'],'accepted_inspect_input_sha256'=>$inputSha,
            'writer_attempts'=>1,'post_write_attempts'=>0];
        $global['pending']['files']=$newFiles;
        hook_append($globalLock,$global);$out['global_journal_appends_by_continuation']++;
        $out['old_preparation']=$oldPreparation;$out['fresh_preparation']['inspect_input_sha256_matched']=true;
        feeds_need(flock($postLock,LOCK_UN)&&fclose($postLock),'feeds_post_shared_lock_release_failed');
        $out['post_shared_lock_released_before_writer']=true;$out['feed_writer_attempts']++;
        $failure=null;
        try{
            $child=hook_child(base64_decode(HOOK_WRITER_B64,true),['EXPECTED_INSTALLED_SHA'=>FEEDS_RUNTIME_SHA,
                'PUBLIC_STATIC_FEED_OPERATION'=>FEEDS_FEED_OPERATION,'PUBLIC_STATIC_FEED_INPUT_SHA256'=>$inputSha,'PUBLIC_STATIC_FEED_INPUT_B64'=>base64_encode($inputRaw)],$failure);
        }catch(Throwable $e){
            $global['pending']['feed_continuation']['state']='writer_outcome_unresolved';
            $global['pending']['feed_continuation']['child_failure']=$failure;
            hook_append($globalLock,$global);$out['global_journal_appends_by_continuation']++;$out['child_failure']=$failure;throw $e;
        }
        feeds_need(($child['operation']??null)===FEEDS_FEED_OPERATION&&($child['ok']??null)===true
            &&($child['db_writes']??null)===0&&($child['provider_calls']??null)===0&&($child['message_sends']??null)===0
            &&array_column($child['files']??[],'name')===array_column($newFiles,'name'),'feeds_writer_result_changed');
        foreach($newFiles as $i=>$file){$actual=$child['files'][$i];feeds_need(($actual['sha256']??null)===$file['sha256']&&($actual['bytes']??null)===$file['bytes']
            &&($actual['rows']??null)===$file['rows']&&($actual['write_action']??null)==='replace','feeds_writer_file_result_changed');}
        $global['pending']['feed_continuation']['state']='writer_returned_confirmed';$global['pending']['feed_continuation']['writer_files']=$child['files'];
        hook_append($globalLock,$global);$out['global_journal_appends_by_continuation']++;
        [$postCheckLock,$postCheckRaw]=feeds_open(FEEDS_ROOT.'/'.FEEDS_OPERATION.'/receipt.jsonl',false,$locks);
        $postAgain=feeds_parse($postCheckRaw,123238,8,FEEDS_POST_SHA);feeds_post($postAgain,time());
        feeds_need(($global['pending']['phase']??null)==='feed_started'&&$global['pending']['files']===$newFiles
            &&$global['pending']['feed_continuation']['old_preparation']['original_pending_files']===FEEDS_FILES,'feeds_pending_phase_changed');
        feeds_need(hook_finish_feed($global,$globalLock,FEEDS_RUNTIME_SHA)==='feed_completed','feeds_hash_completion_failed');
        $remaining=$global['completed'];unset($remaining[2651]);
        feeds_need($global['pending']===null&&$remaining===$completedBefore&&($global['holds']??[])===$holdsBefore
            &&($global['completed'][2651]['operation']??null)===FEEDS_OPERATION&&($global['completed'][2651]['feed_operation']??null)===FEEDS_FEED_OPERATION,
            'feeds_unrelated_global_state_changed');
        rewind($globalLock);$finalRaw=stream_get_contents($globalLock,100000000);feeds_need(is_string($finalRaw),'feeds_final_journal_read_failed');
        feeds_prefix($finalRaw,strlen($globalRaw),FEEDS_GLOBAL_SHA);feeds_prefix($postCheckRaw,26418,FEEDS_POST_ORIGINAL_SHA);
        $out['ok']=true;$out['updated_pairs']=1;$out['updates']=[['tourvisor_hotel_id'=>2651,'operation'=>FEEDS_OPERATION,'price'=>150221,
            'record_id'=>FEEDS_RECORD,'posts'=>[$update['receipts']['max'],$update['receipts']['telegram']],'feed_operation'=>FEEDS_FEED_OPERATION]];
        $out['fresh_preparation']['published']=true;
        $out['feed_evidence']=['operation'=>FEEDS_FEED_OPERATION,'files'=>$newFiles,'local_and_public_hashes_verified'=>true,
            'verification_owner'=>'frozen_hook_finish_feed','additional_public_gets'=>0,'appended_global_states_sha256'=>hash('sha256',substr($finalRaw,strlen($globalRaw)))];
        $out['original_post_journal_unchanged']=true;$out['original_prefix_preserved']=true;$out['original_manifest_preserved']=true;
        $out['original_monitor_snapshot_preserved']=true;$out['completed_operations_preserved']=131;$out['pending_cleared']=true;$out['pending']=null;
        $out['completed_original_operation']=$global['completed'][2651];$out['final_global_journal_sha256']=hash('sha256',$finalRaw);
    }
    $out['completed_at']=gmdate('c');
}catch(Throwable $e){$out['error']=preg_match('/\A[a-z][a-z0-9_]{1,120}\z/D',$e->getMessage())?$e->getMessage():'feeds_failed_read_original_journals';}
foreach(array_reverse($locks) as $stream)if(is_resource($stream)){flock($stream,LOCK_UN);fclose($stream);}
echo 'MONITOR_PENDING_2651_FRESH_FEEDS='.feeds_json($out)."\n";exit($out['ok']?0:2);
