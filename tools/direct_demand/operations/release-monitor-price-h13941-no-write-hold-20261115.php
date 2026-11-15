<?php
declare(strict_types=1);
require_once __DIR__.'/monitor-price-hook-contract.php';

/** One authorized hold removal. No editor, feed writer, collector, supplier or child-journal write. */
const H13941_ROOT = '/var/www/anytoour/data/channel-post-operations';
const H13941_RUNTIME = '/var/www/anytoour/data/www/app.anytoour.ru';
const H13941_INSTALLED = '62fc5cf8cf5cb3e41b370a808c2281d27ae83da7';
const H13941_ROSTER_SOURCE = 'f773d5bb8ff7b04273e65e6ef2d781d46b3eafda';
const H13941_ROSTER_ROW_SHA256 = 'f3c35e1d8373b315abc4cf9a5cf56414a3c734d7eec1c89477de236d3e36a844';
const H13941_OPERATION = 'commercial-monitor-price-h13941-6e54afb665548e85bb631cc09587f695f7b0c708a39a4add65f22402cc82a3c9';
const H13941_ORIGINAL_RUN = '43654792363';
const H13941_ORIGINAL_SOURCE = 'f773d5bb8ff7b04273e65e6ef2d781d46b3eafda';
const H13941_CHILD_SHA256 = '2bec9d162fd7333f1559cd114ae29d79e6de3338dc9bfa1e4342496df17c7a74';
const H13941_MANIFEST = '4795d2263401c0e7dda6cd96d2ce8e2ee4425f18f4a304d19a1da4a2ead418d1';
const H13941_TERMINAL = ['state'=>'abandoned_before_write','reason'=>'child_preflight_failed_before_write','prior_sha256'=>'5fcf6108372dd8956c5ec467d4574425eb8cb11f435c6006fd9f8f7e2f49ad8a','prior_bytes'=>7418,'prior_lines'=>1,'recorded_at'=>'2026-11-14T23:46:28+00:00','preflight_error'=>'max_read_failed','child_exit_code'=>2];
const H13941_HOLD = ['operation'=>'commercial-monitor-price-h13941-6e54afb665548e85bb631cc09587f695f7b0c708a39a4add65f22402cc82a3c9','reason'=>'abandoned_before_write','terminal_journal_sha256'=>'2bec9d162fd7333f1559cd114ae29d79e6de3338dc9bfa1e4342496df17c7a74','at'=>'2026-11-14T23:46:28+00:00'];
const H13941_ORIGINAL_PUBLICATION = 'commercial-hotel-batch-20261107-12-v1';
const H13941_LOGICAL_ID = 'commercial-blue-pearl-13941-v1';
const H13941_ORIGINAL_TEXT = '1d040aa069bc4cd1610e3e1057d5b738eaf89bd99a3b49e0d9a99739636fc464';
const H13941_COVER = 'b1fc4ea23480be622f06d4c9c16b61159576b963c66305b22f0d2041e6886261';
const H13941_PRIOR_RECORD = '33b7f724aadc7638537fa700b6a00152519eace32577cc4166983039f6555ad0';
const H13941_PRIOR_COMPLETED = ['operation'=>'commercial-monitor-price-h13941-33b7f724aadc7638537fa700b6a00152519eace32577cc4166983039f6555ad0','at'=>'2026-11-14T01:02:56+00:00','feed_operation'=>'commercial-channel-hotel-feeds-20261114-update12174187764312431'];
const H13941_GLOBAL = ['sha256'=>'9ef0201977d5b54db1d4cc8a3de738d8a57e6e7230456afeb3f830eb2cd06fbe','bytes'=>211053266,'lines'=>2609];
const H13941_COMPLETED_SHA256 = '5059293b995932fae3d21cba2c886ac1e5170717c38fe3dd51883488f8dd2f09';
const H13941_CYCLE_SHA256 = '0880c7ee8df57737dbc7eed9e17528fa180d57400e9226f5f43d436b71a731e3';
const H13941_BUTTON = '✈️ Узнать стоимость тура';
// Exact current versions and captions from original maintenance43537828604; live context is checked again before release.
const H13941_TARGETS = ['max'=>['bot'=>'id9704048781_1_bot','channel'=>'-80492244209683','source'=>'max_anytour_msk1','channel_row'=>9,'publication_id'=>587,'previous_version_id'=>2631,'version_id'=>4893,'message_id'=>'mid.ffffb6caf0606fed01a1b642fbe50368','post_url'=>'https://max.ru/channel_anytour/AaG2QvvlA2g','photo_signature'=>'max:49819906568','text_sha256'=>'cdaec0c8523d2f55847d9dacbbece01448e542cc7f903469f823fc834cfbeefc','button_url_sha256'=>'fb2996bd6bfb97f79afb702e07af77c31a8e4efeabe94904a4f4410417a9692a'],'telegram'=>['bot'=>'anyru_bot','channel'=>'-1002262666616','source'=>'tg_anytour_msk','channel_row'=>10,'publication_id'=>578,'previous_version_id'=>2632,'version_id'=>4894,'message_id'=>'242','post_url'=>'https://t.me/any_toour/242','photo_signature'=>'telegram:AQADUTZrG-HPeUt9','text_sha256'=>'9a087a19a3962d85c2110cca2bfa9cfe537171fca62fc7c5f1b747c4599aa093','button_url_sha256'=>'655a35da866cee74415e2e7a786699704b405458d9a5d09a3f046d49f4bcd678']];
const H13941_PINS = ['integrations/ChannelPostApiTransport.php'=>'300634d77cb9fddf51adf9869974f956cca6e3f9','migrations/033_channel_post_context.sql'=>'0530458030a4bfe6b6cc55d1e5e441745c29b947','migrations/036_channel_post_member_delivery.sql'=>'1bda463e52b33130ad12752ddef4ee5c92699c05','migrations/040_channel_post_reconciliation.sql'=>'0f24b838eacd45333c5ade9a2991d060488c6035','services/ChannelPostAdminService.php'=>'b80eefc801054bfe6fdb58ec345ecdf687b2e89a','services/ChannelPostAutomationService.php'=>'f5975ab95de5f45e087eb7a0f768f566c5bca648','services/ChannelPostButtonTransport.php'=>'7b6711f1de63000346d8e5789f2dbb7f725535b8','services/ChannelPostEntryService.php'=>'f0e04e9b4e0ed6859ced96279cbf0619931596e6','services/ChannelPostMaxAlbumRecovery.php'=>'de266cd068587cc3b2ff83f308a966ea66887a04','services/ChannelPostMediaService.php'=>'f794efd0f6f24df916e18909ff6281b0bc928433','services/ChannelPostMemberDeliveryService.php'=>'20c1d7984898b2a5996a5f9e20e4d2d8c20b4778','services/ChannelPostReconciliationService.php'=>'43133f576845c14446102dedfffb7f0de2d4ba1c','services/ChannelPostReconciliationState.php'=>'d9583ceca80d1424a44ade3561c21282a23a52f0','services/ChannelPostRegistryService.php'=>'db8181426091a685a79ee55991cde22671f0d9dd','services/ChannelPostRuntime.php'=>'2f1dd9b77474fdbaa92f0402f3b0aa170c595474','services/ChannelPostTelegramAlbum.php'=>'faa1a14bc279a4af33936282126bf0d46e343040','services/ChannelPostWebhookConfigService.php'=>'d5fdcb056088f1e3a541b4046325d97eef6417d9','services/ConversationDb.php'=>'bb6281faf55aca7beeae7f8a57389d19751936f7','services/MaxTlsConfig.php'=>'032c0211ee3f39c6f0d27c669abd5b467e321dea','services/MaxTransport.php'=>'2cb4f8786b4704a8e62a3a9c40dc2cc73dad6159','services/ProjectConfig.php'=>'63c17c8103412f8cff8cdcc4bbb062e744106af9','services/ProjectConfiguration.php'=>'07019d473c588e3386ace42977db93092e8112e3'];

function h13941_need(bool $ok,string $code):void { if(!$ok)throw new RuntimeException($code); }
function h13941_json($value):string { return json_encode($value,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR); }
function h13941_hash($value):string { return hash('sha256',h13941_json($value)); }
function h13941_source(string $head,bool $trackedClean,array $pins):void {
    h13941_need($head===H13941_INSTALLED,'h13941_installed_source_changed');
    h13941_need($trackedClean,'h13941_tracked_tree_changed');
    h13941_need(count($pins)===22&&$pins===H13941_PINS,'h13941_publishing_surface_changed');
}
function h13941_terminal(array $journal):void {
    h13941_need(($journal['operation']??null)===H13941_OPERATION&&($journal['manifest_hash']??null)===H13941_MANIFEST
        &&($journal['_terminal_journal_sha256']??null)===H13941_CHILD_SHA256
        &&($journal['terminal']??null)===H13941_TERMINAL,'h13941_original_terminal_changed');
    h13941_need(($journal['posts']??null)===[]&&($journal['configs']??null)===[]
        &&(!array_key_exists('complete',$journal)||$journal['complete']===false),'h13941_original_has_write_intent');
    h13941_need(($journal['feed_binding']['tourvisor_hotel_id']??null)===13941,'h13941_original_hotel_changed');
    foreach(H13941_TARGETS as $platform=>$target)h13941_need(($journal['feed_binding']['posts'][$platform]??null)===$target['post_url'],'h13941_original_pair_changed');
}
function h13941_child(string $raw):array {
    h13941_need(strlen($raw)===15144&&substr_count($raw,"\n")===2&&hash('sha256',$raw)===H13941_CHILD_SHA256,'h13941_original_child_changed');
    $prefix=substr($raw,0,H13941_TERMINAL['prior_bytes']);$proof=monitor_hook_no_write_prefix($prefix,H13941_OPERATION);
    h13941_need($proof['sha256']===H13941_TERMINAL['prior_sha256']&&$proof['bytes']===7418&&$proof['lines']===1,'h13941_original_prefix_changed');
    $journal=monitor_hook_parse_journal($raw,H13941_OPERATION);h13941_terminal($journal);return $journal;
}
/** Pure transition: preserve all other state, including unknown fields and every other hold. */
function h13941_remove_hold(array $state,array $journal):array {
    h13941_terminal($journal);
    h13941_need(($state['schema']??null)===1&&is_array($state['completed']??null),'h13941_global_state_invalid');
    h13941_need(array_key_exists('pending',$state)&&$state['pending']===null,'h13941_pending_operation_stop');
    h13941_need(is_array($state['holds']??null)&&($state['holds'][13941]??null)===H13941_HOLD,'h13941_exact_hold_changed');
    h13941_need(($state['completed'][13941]??null)===H13941_PRIOR_COMPLETED,'h13941_prior_completed_changed_stop');
    h13941_need(monitor_hook_hold_reason(13941,$state['holds'][13941],$journal)==='abandoned_before_write','h13941_hold_evidence_changed');
    $cycle=$state['nightly_cycle']??null;
    if($cycle!==null){
        h13941_need(is_array($cycle)&&($cycle['state']??null)!=='started','h13941_active_collector_cycle_stop');
        monitor_hook_attempted_collector_runs([h13941_json(['nightly_cycle'=>$cycle])]);
    }
    $next=$state;unset($next['holds'][13941]);
    $before=$state;$after=$next;unset($before['holds'],$after['holds']);
    h13941_need($before===$after,'h13941_unrelated_state_changed');return $next;
}
/** Keep JSON object/array shapes; refuse noncanonical or duplicate-key input instead of normalizing it. */
function h13941_next_line(string $line,array $journal):array {
    h13941_need(str_ends_with($line,"\n")&&substr_count($line,"\n")===1&&strlen($line)<=2000000,'h13941_global_line_invalid');
    $object=json_decode(substr($line,0,-1),false,64,JSON_THROW_ON_ERROR);
    h13941_need($object instanceof stdClass&&h13941_json($object)."\n"===$line,'h13941_global_encoding_changed');
    $state=json_decode($line,true,64,JSON_THROW_ON_ERROR);$next=h13941_remove_hold($state,$journal);
    h13941_need(($object->holds??null) instanceof stdClass&&property_exists($object->holds,'13941'),'h13941_hold_encoding_changed');
    $object->holds=clone $object->holds;unset($object->holds->{'13941'});
    $nextLine=h13941_json($object)."\n";
    h13941_need(json_decode($nextLine,true,64,JSON_THROW_ON_ERROR)===$next,'h13941_transition_encoding_changed');
    return ['state'=>$next,'line'=>$nextLine];
}
function h13941_ready(array $cap,string $platform):void {
    h13941_need(($cap['platform']??null)===$platform&&($cap['state']??null)==='ready'&&($cap['reason']??null)==='capability_verified'
        &&array_key_exists('error',$cap)&&$cap['error']===null&&($cap['request_count']??null)===3
        &&($cap['write_attempts']??null)===0&&($cap['http_results_truncated']??null)===false
        &&is_array($cap['http_results']??null)&&count($cap['http_results'])===3,'h13941_current_channel_not_ready');
    foreach($cap['http_results'] as $http)h13941_need(($http['http']??null)===200&&($http['transport_error']??null)===false,'h13941_capability_http_not_ready');
}
function h13941_no_active(bool $active):void { h13941_need(!$active,'h13941_active_publication_writer_stop'); }
function h13941_context(string $platform,array $proof):void {
    h13941_need(array_key_exists($platform,H13941_TARGETS),'h13941_platform_invalid');$target=H13941_TARGETS[$platform];
    foreach(['publication_id','message_id','post_url','photo_signature','button_url_sha256'] as $field)
        h13941_need(($proof[$field]??null)===$target[$field],'h13941_current_identity_changed');
    h13941_need(is_int($proof['version_id']??null)&&$proof['version_id']===$target['version_id']
        &&($proof['project_key']??null)==='anytour'&&($proof['platform']??null)===$platform&&($proof['bot_key']??null)===$target['bot']
        &&($proof['channel_id']??null)===$target['channel']&&($proof['source_key']??null)===$target['source']
        &&($proof['state']??null)==='sent'&&($proof['write_attempts']??null)===0
        &&array_key_exists('claim_token',$proof)&&$proof['claim_token']===null
        &&array_key_exists('claimed_at',$proof)&&$proof['claimed_at']===null,'h13941_current_publication_unresolved');
    h13941_need(($proof['text_sha256']??null)===$target['text_sha256']&&array_key_exists('monitor_price_record_id',$proof)
        &&$proof['monitor_price_record_id']===H13941_PRIOR_RECORD,'h13941_current_caption_changed');
    h13941_need(($proof['only_expected_button']??null)===true&&($proof['button_resolves_current_version']??null)===true
        &&is_string($proof['attachments_sha256']??null)&&preg_match('/\A[a-f0-9]{64}\z/D',$proof['attachments_sha256'])===1,'h13941_current_photo_or_button_changed');
}
function h13941_photo(array $snapshot):string {
    $photos=$snapshot['attachments']??null;
    h13941_need(is_array($photos)&&count($photos)===1&&($photos[0]['type']??null)==='image','h13941_current_photo_shape_changed');
    $photo=$photos[0];$id=(string)($photo['photo_id']??$photo['file_unique_id']??'');
    h13941_need($id!==''&&preg_match('/\A[A-Za-z0-9_-]+\z/D',$id)===1,'h13941_current_photo_identity_missing');
    return (string)($photo['provider']??'').':'.$id;
}
function h13941_git(array $args):string {
    $pipes=[];$p=proc_open(array_merge(['git','--no-optional-locks','-C',H13941_RUNTIME],$args),
        [0=>['file','/dev/null','r'],1=>['pipe','w'],2=>['file','/dev/null','w']],$pipes,H13941_RUNTIME,null,['bypass_shell'=>true]);
    h13941_need(is_resource($p),'h13941_git_start_failed');$raw=stream_get_contents($pipes[1],32769);fclose($pipes[1]);$exit=proc_close($p);
    h13941_need($exit===0&&is_string($raw)&&strlen($raw)<=32768,'h13941_git_read_failed');return trim($raw);
}
function h13941_runtime():void {
    h13941_need(!is_link(H13941_RUNTIME)&&realpath(H13941_RUNTIME)===H13941_RUNTIME&&chdir(H13941_RUNTIME),'h13941_runtime_path_invalid');
    foreach(H13941_PINS as $file=>$pin)h13941_need(!is_link(H13941_RUNTIME.'/'.$file)&&realpath(H13941_RUNTIME.'/'.$file)===H13941_RUNTIME.'/'.$file,'h13941_pinned_path_invalid');
    $head=h13941_git(['rev-parse','--verify','HEAD']);$clean=h13941_git(['status','--porcelain','--untracked-files=no'])==='';
    $blobs=explode("\n",h13941_git(array_merge(['hash-object','--'],array_keys(H13941_PINS))));
    h13941_need(count($blobs)===22,'h13941_runtime_pin_count_changed');h13941_source($head,$clean,array_combine(array_keys(H13941_PINS),$blobs));
}
/** Bounded whole-prefix hashing; the global history is streamed, never rewritten or truncated. */
function h13941_read($stream,int $cap,bool $retainRaw=false):array {
    h13941_need(rewind($stream),'h13941_journal_rewind_failed');$ctx=hash_init('sha256');$bytes=0;$lines=0;$last=null;$lastLine='';$raw='';
    while(!feof($stream)){
        $line=fgets($stream,2000002);if($line===false){h13941_need(feof($stream),'h13941_journal_read_failed');break;}
        $bytes+=strlen($line);$lines++;
        h13941_need($bytes<=$cap&&$lines<=10000&&strlen($line)<=2000000&&str_ends_with($line,"\n"),'h13941_journal_partial_or_oversize');
        $last=json_decode($line,true,64,JSON_THROW_ON_ERROR);h13941_need(is_array($last),'h13941_journal_shape_invalid');
        hash_update($ctx,$line);$lastLine=$line;if($retainRaw)$raw.=$line;
    }
    h13941_need($bytes>0&&$lines>0,'h13941_journal_empty');
    return ['meta'=>['sha256'=>hash_final(hash_copy($ctx)),'bytes'=>$bytes,'lines'=>$lines],
        'last'=>$last,'last_line'=>$lastLine,'raw'=>$raw,'hash_context'=>$ctx];
}
/** Global EX, immutable child EX, approved receipts SH. Only the existing global handle is writable. */
function h13941_open(string $path,string $mode,int $lockMode,int $cap,array &$locks):array {
    h13941_need(!is_link(H13941_ROOT)&&realpath(H13941_ROOT)===H13941_ROOT&&!is_link(dirname($path))
        &&realpath(dirname($path))===dirname($path)&&!is_link($path)&&is_file($path)&&realpath($path)===$path,'h13941_private_path_invalid');
    $named=lstat($path);h13941_need(is_array($named)&&($named['mode']&0170000)===0100000&&$named['size']>0&&$named['size']<=$cap,'h13941_private_size_invalid');
    $stream=fopen($path,$mode);h13941_need(is_resource($stream),'h13941_private_open_failed');$locks[]=$stream;
    h13941_need(flock($stream,$lockMode|LOCK_NB),'h13941_existing_writer_busy');$stat=fstat($stream);
    h13941_need(is_array($stat)&&$stat['ino']===$named['ino']&&$stat['dev']===$named['dev']&&$stat['size']===$named['size'],'h13941_private_identity_changed');
    $file=['stream'=>$stream,'path'=>$path,'stat'=>$stat,'cap'=>$cap];h13941_stable($file);return $file;
}
function h13941_stable(array $file):void {
    clearstatcache(true,$file['path']);$named=lstat($file['path']);$opened=fstat($file['stream']);
    h13941_need(is_array($named)&&is_array($opened)&&!is_link($file['path'])&&realpath($file['path'])===$file['path'],'h13941_named_path_changed');
    foreach(['ino','dev','size','mtime','ctime'] as $field)h13941_need($named[$field]===$file['stat'][$field]&&$opened[$field]===$file['stat'][$field],'h13941_locked_file_changed');
}
function h13941_original_receipts(array &$locks):array {
    $result=[];
    foreach(H13941_TARGETS as $platform=>$target){
        $file=h13941_open(H13941_ROOT.'/'.H13941_ORIGINAL_PUBLICATION.'/'.$platform.'-'.H13941_LOGICAL_ID.'-approved.json','rb',LOCK_SH,1048576,$locks);
        $raw=stream_get_contents($file['stream'],1048577);h13941_need(is_string($raw)&&strlen($raw)===$file['stat']['size'],'h13941_original_receipt_read_failed');
        $original=json_decode($raw,true,64,JSON_THROW_ON_ERROR);$r=$original['receipt']??[];
        h13941_need(($original['state']??null)==='confirmed'&&($original['text_sha256']??null)===H13941_ORIGINAL_TEXT
            &&($original['photo_sha256']??null)===H13941_COVER&&($r['logical_id']??null)===H13941_LOGICAL_ID
            &&(int)($r['hotel_id']??0)===13941&&($r['platform']??null)===$platform&&($r['post_url']??null)===$target['post_url']
            &&($r['body_photo_button_verified']??null)===true&&(int)($r['publication_id']??0)===$target['publication_id']
            &&(string)($r['message_id']??'')===$target['message_id'],'h13941_original_receipt_pair_changed');
        h13941_stable($file);$result[$platform]=['file'=>$file,'sha256'=>hash('sha256',$raw)];
    }return $result;
}
function h13941_active_versions(PDO $pdo):void {
    $q=$pdo->prepare("SELECT id FROM post_versions WHERE publication_id IN (587,578) AND (state IN ('pending','claimed','write_started','unknown') OR claim_token IS NOT NULL OR claimed_at IS NOT NULL) LIMIT 1");
    h13941_need($q!==false&&$q->execute(),'h13941_active_writer_read_failed');h13941_no_active($q->fetch(PDO::FETCH_ASSOC)!==false);$q->closeCursor();
}
function h13941_publication(ChannelPostRegistryService $registry,string $platform):array {
    $target=H13941_TARGETS[$platform];$p=$registry->publication($target['publication_id']);
    h13941_need(is_array($p)&&is_array($p['snapshot']??null),'h13941_current_publication_missing');$s=$p['snapshot'];
    $button=ChannelPostButtonTransport::buttonUrl($p);$resolved=$registry->resolveToken('anytour',$platform,$target['bot'],(string)($p['start_payload']??''));
    $proof=[];foreach(['publication_id','version_id','project_key','platform','bot_key','channel_id','source_key','state','write_attempts','claim_token','claimed_at'] as $field){
        h13941_need(array_key_exists($field,$p),'h13941_current_publication_field_missing');$proof[$field]=$p[$field];
    }
    $proof+=['message_id'=>$p['external_message_id']??null,'post_url'=>$s['post_url']??null,
        'text_sha256'=>hash('sha256',(string)($s['text']??'')),'photo_signature'=>h13941_photo($s),
        'attachments_sha256'=>h13941_hash($s['attachments']),'button_url_sha256'=>hash('sha256',$button),
        'monitor_price_record_id'=>$s['metadata']['monitor_price_record_id']??null,
        'only_expected_button'=>ChannelPostIncomingAdapter::containsOnlyButton($s,$button,H13941_BUTTON),
        'button_resolves_current_version'=>is_array($resolved)&&($resolved['publication_id']??null)===$p['publication_id']&&($resolved['version_id']??null)===$p['version_id']];
    h13941_context($platform,$proof);
    return ['proof'=>$proof,'snapshot'=>$s,'button_url'=>$button,'guard_sha256'=>h13941_hash($p)];
}

/** Only bounded status/type facts: no headers, tokens, body or exception strings. */
function h13941_max_diagnostic(array $response):array {
    $http=$response['http']??null;$transport=$response['transport_error']??null;
    return ['attempted'=>true,'request_count'=>1,'method'=>'GET','message_id'=>H13941_TARGETS['max']['message_id'],
        'http'=>is_int($http)&&$http>=0&&$http<=599?$http:null,
        'transport_error'=>is_bool($transport)?$transport:null,'data_is_array'=>is_array($response['data']??null),
        'identity_matches'=>null,'caption_matches'=>null,'photo_matches'=>null,'url_matches'=>null,'only_expected_button'=>null];
}

/** Seven bounded provider reads; only the two exact publication IDs and their versions are read in the DB. */
function h13941_read_current(array &$out,array &$ctx):array {
    ob_start();try{
        require H13941_RUNTIME.'/config.php';
        require_once H13941_RUNTIME.'/services/ChannelPostRuntime.php';
        require_once H13941_RUNTIME.'/services/ChannelPostRegistryService.php';
        require_once H13941_RUNTIME.'/services/ChannelPostButtonTransport.php';
    }finally{ob_end_clean();}
    $pdo=ConversationDb::connection();$ctx['pdo']=$pdo;h13941_need(!$pdo->inTransaction(),'h13941_existing_database_transaction_stop');
    $pdo->exec('SET TRANSACTION ISOLATION LEVEL READ COMMITTED, READ ONLY');$pdo->beginTransaction();
    h13941_need($pdo->inTransaction(),'h13941_read_only_transaction_unavailable');$registry=new ChannelPostRegistryService($pdo);$ctx['registry']=$registry;
    h13941_active_versions($pdo);$proof=['captured_at'=>gmdate('c'),'database_session_read_only'=>true,'isolation'=>'READ COMMITTED',
        'active_publication_versions'=>0,'capabilities'=>[],'publications'=>[],
        'telegram_message_evidence'=>'stored_registry_snapshot_and_original_receipt_not_new_native_read'];$ctx['started_at']=time();$ctx['guard_hashes']=[];
    foreach(H13941_TARGETS as $platform=>$target){
        $identity=ChannelPostRuntime::identity($platform);
        h13941_need(is_array($identity)&&($identity['project_key']??null)==='anytour'&&($identity['bot_key']??null)===$target['bot'],'h13941_configured_bot_changed');
        $channel=$registry->channel('anytour',$platform,$target['bot'],$target['channel']);
        h13941_need(is_array($channel)&&(int)($channel['id']??0)===$target['channel_row']&&($channel['source_key']??null)===$target['source']
            &&(int)($channel['config_version']??0)===2&&($channel['button_text']??null)===H13941_BUTTON,'h13941_channel_configuration_changed');
        $cap=monitor_price_channel_preflight($platform,(new ChannelPostButtonTransport())->verifyChannel($channel));
        $out['provider_read_requests']+=is_int($cap['request_count']??null)?$cap['request_count']:0;$proof['capabilities'][$platform]=$cap;
        $out['current_readback']=$proof;h13941_ready($cap,$platform);
        $current=h13941_publication($registry,$platform);$proof['publications'][$platform]=$current['proof'];$ctx['guard_hashes'][$platform]=$current['guard_sha256'];
        if($platform==='max'){
            $out['provider_read_requests']++;
            $response=ChannelPostApiTransport::buttonRequest('max','GET','/messages/'.$target['message_id'],[],null);
            $proof['max_readback']=h13941_max_diagnostic($response);$out['current_readback']=$proof;
            h13941_need($proof['max_readback']['transport_error']===false&&$proof['max_readback']['http']===200
                &&$proof['max_readback']['data_is_array'],'h13941_max_current_read_failed');
            $message=$response['data'];$proof['max_readback']['identity_matches']=($message['body']['mid']??null)===$target['message_id']
                &&(string)($message['recipient']['chat_id']??'')===$target['channel']&&($message['recipient']['chat_type']??null)==='channel';
            $out['current_readback']=$proof;h13941_need($proof['max_readback']['identity_matches'],'h13941_max_current_identity_changed');
            $live=ChannelPostIncomingAdapter::maxSnapshot($message);
            $proof['max_readback']['caption_matches']=is_array($live)&&($live['text']??null)===($current['snapshot']['text']??null);
            $proof['max_readback']['url_matches']=is_array($live)&&($live['post_url']??null)===$target['post_url'];
            $proof['max_readback']['only_expected_button']=is_array($live)&&ChannelPostIncomingAdapter::containsOnlyButton($live,$current['button_url'],H13941_BUTTON);
            $out['current_readback']=$proof;
            $proof['max_readback']['photo_matches']=is_array($live)&&h13941_photo($live)===$target['photo_signature'];
            $out['current_readback']=$proof;
            h13941_need($proof['max_readback']['caption_matches']&&$proof['max_readback']['photo_matches']
                &&$proof['max_readback']['url_matches']&&$proof['max_readback']['only_expected_button'],'h13941_max_current_content_changed');
        }
    }
    h13941_need($out['provider_read_requests']===7,'h13941_provider_read_budget_changed');
    $proof['completed_at']=gmdate('c');$proof['provider_read_requests']=7;$out['current_readback']=$proof;return $proof;
}
function h13941_recheck_current(array $ctx):void {
    h13941_need(time()-$ctx['started_at']<=60,'h13941_current_readback_expired');h13941_active_versions($ctx['pdo']);
    foreach(H13941_TARGETS as $platform=>$target){$current=h13941_publication($ctx['registry'],$platform);
        h13941_need($current['guard_sha256']===$ctx['guard_hashes'][$platform],'h13941_current_registry_cas_changed');}
}
/** Exactly one append attempt. A failed or uncertain write is terminal for this invocation. */
function h13941_append(array $file,string $line,array &$out):array {
    h13941_need($file['path']===H13941_ROOT.'/monitor-price-after-collection-v1.jsonl'&&str_ends_with($line,"\n")
        &&substr_count($line,"\n")===1&&strlen($line)<=2000000,'h13941_append_scope_invalid');
    h13941_stable($file);$current=h13941_read($file['stream'],$file['cap']);
    h13941_need($current['meta']===$file['meta'],'h13941_global_cas_changed');
    $hash=hash_copy($file['hash_context']);hash_update($hash,$line);
    $expected=['sha256'=>hash_final($hash),'bytes'=>$file['meta']['bytes']+strlen($line),'lines'=>$file['meta']['lines']+1];
    h13941_need($file['meta']===H13941_GLOBAL&&$expected['bytes']===H13941_GLOBAL['bytes']+strlen($line)
        &&$expected['lines']===H13941_GLOBAL['lines']+1&&$expected['lines']<=10000,'h13941_append_capacity_exceeded');
    h13941_need(fseek($file['stream'],0,SEEK_END)===0,'h13941_append_seek_failed');
    $out['journal_write_attempts']++;$out['write_outcome']='unknown_until_exact_readback';
    h13941_need(fwrite($file['stream'],$line)===strlen($line)&&fflush($file['stream']),'h13941_append_failed');
    if(function_exists('fsync'))h13941_need(fsync($file['stream']),'h13941_append_sync_failed');
    $after=h13941_read($file['stream'],$expected['bytes']);h13941_need($after['meta']===$expected&&$after['last_line']===$line,'h13941_append_readback_failed');
    clearstatcache(true,$file['path']);$named=lstat($file['path']);$opened=fstat($file['stream']);
    h13941_need(is_array($named)&&is_array($opened)&&!is_link($file['path'])&&realpath($file['path'])===$file['path']
        &&$named['ino']===$file['stat']['ino']&&$named['dev']===$file['stat']['dev']&&$opened['ino']===$named['ino']
        &&$opened['dev']===$named['dev']&&$named['size']===$expected['bytes']&&$opened['size']===$expected['bytes'],'h13941_append_identity_changed');
    $out['journal_appends']++;$out['write_outcome']='global_append_verified_child_check_pending';return $expected;
}
function h13941_main(array $argv):int {
    if(PHP_SAPI!=='cli'){http_response_code(404);return 2;}
    ini_set('display_errors','0');ini_set('log_errors','0');
    $out=['ok'=>false,'mode'=>'release_exact_hotel_no_write_hold','operation'=>H13941_OPERATION,'tourvisor_hotel_id'=>13941,
        'original_run_id'=>H13941_ORIGINAL_RUN,'original_run_attempt'=>1,'original_source_sha'=>H13941_ORIGINAL_SOURCE,
        'original_ci_conclusion'=>'failure','read_run_id'=>'43656538207','read_source_sha'=>'52f4c8ff746d42a1a686cc799fc9acbde71bda8b',
        'read_result_sha256'=>'e150e148cdba131eb65dec6c7f32c9bf227ea2f208921443dc0ab40d834be619',
        'accepted_roster_source_sha'=>H13941_ROSTER_SOURCE,'accepted_roster_row_sha256'=>H13941_ROSTER_ROW_SHA256,
        'journal_write_attempts'=>0,'journal_appends'=>0,'child_journal_writes'=>0,'database_writes'=>0,'post_edits'=>0,'new_posts'=>0,
        'feed_writes'=>0,'configuration_writes'=>0,'supplier_calls'=>0,'site_writes'=>0,'provider_write_requests'=>0,'provider_read_requests'=>0,
        'write_outcome'=>'not_started','hold_removed'=>false];$locks=[];$ctx=[];
    try{
        h13941_need(count($argv)===1,'h13941_arguments_invalid');h13941_runtime();
        $out['runtime_git_head']=H13941_INSTALLED;$out['runtime_tracked_clean']=true;$out['runtime_pin_count']=22;$out['runtime_pins_sha256']=h13941_hash(H13941_PINS);
        $global=h13941_open(H13941_ROOT.'/monitor-price-after-collection-v1.jsonl','r+b',LOCK_EX,H13941_GLOBAL['bytes'],$locks);
        $global+=h13941_read($global['stream'],$global['cap']);h13941_stable($global);
        h13941_need($global['meta']===H13941_GLOBAL,'h13941_exact_global_changed');
        h13941_need(count($global['last']['completed']??[])===523&&h13941_hash($global['last']['completed'])===H13941_COMPLETED_SHA256
            &&h13941_hash($global['last']['nightly_cycle']??null)===H13941_CYCLE_SHA256,'h13941_global_completed_or_cycle_changed');
        $child=h13941_open(H13941_ROOT.'/'.H13941_OPERATION.'/receipt.jsonl','rb',LOCK_EX,20000,$locks);
        $child+=h13941_read($child['stream'],$child['cap'],true);h13941_stable($child);
        $journal=h13941_child($child['raw']);$planned=h13941_next_line($global['last_line'],$journal);$state=$global['last'];
        $out['before']=['global'=>$global['meta'],'child'=>$child['meta'],'hold'=>H13941_HOLD,'pending'=>null,
            'completed_count'=>count($state['completed']),'completed_sha256'=>h13941_hash($state['completed']),
            'cycle_sha256'=>h13941_hash($state['nightly_cycle']??null),'other_holds_sha256'=>h13941_hash($planned['state']['holds'])];
        $out['original_all_history_no_write']=true;$out['original_terminal']=H13941_TERMINAL;
        $receipts=h13941_original_receipts($locks);foreach($receipts as $platform=>$receipt)$out['original_receipts_sha256'][$platform]=$receipt['sha256'];
        $proof=h13941_read_current($out,$ctx);h13941_runtime();
        foreach($receipts as $receipt){h13941_stable($receipt['file']);h13941_need(rewind($receipt['file']['stream']),'h13941_receipt_rewind_failed');
            $raw=stream_get_contents($receipt['file']['stream'],1048577);h13941_need(is_string($raw)&&hash('sha256',$raw)===$receipt['sha256'],'h13941_original_receipt_cas_changed');}
        h13941_stable($child);$childNow=h13941_read($child['stream'],$child['cap'],true);
        h13941_need($childNow['meta']===$child['meta']&&$childNow['raw']===$child['raw'],'h13941_original_child_cas_changed');
        h13941_recheck_current($ctx);
        $after=h13941_append($global,$planned['line'],$out);
        h13941_stable($child);$childAfter=h13941_read($child['stream'],$child['cap'],true);
        h13941_need($childAfter['meta']===$child['meta']&&$childAfter['raw']===$child['raw'],'h13941_original_child_postcheck_failed');
        $next=$planned['state'];$out['after']=['global'=>$after,'child'=>$childAfter['meta'],'hold_present'=>array_key_exists(13941,$next['holds']),
            'pending'=>$next['pending'],'completed_count'=>count($next['completed']),'completed_sha256'=>h13941_hash($next['completed']),
            'cycle_sha256'=>h13941_hash($next['nightly_cycle']??null),'other_holds_sha256'=>h13941_hash($next['holds']),
            'all_other_global_fields_preserved'=>true,'original_history_preserved'=>true,'old_operation_remains_terminal'=>true];
        $out['current_readback_sha256']=h13941_hash($proof);$out['write_outcome']='global_append_verified';$out['recorded_at']=gmdate('c');
        $out['hold_removed']=true;$out['preserved_completed']=true;$out['preserved_completed_count']=count($next['completed']);$out['preserved_prior_completed_hotel']=$next['completed'][13941];$out['pending']=null;
        $out['status']='exact_hold_removed_new_observations_require_normal_monitor_pass';$out['ok']=true;
    }catch(Throwable $e){$out['error']=preg_match('/\A[a-z][a-z0-9_]{1,120}\z/D',$e->getMessage())===1?$e->getMessage():'h13941_reconciliation_failed';}
    try{if(($ctx['pdo']??null) instanceof PDO&&$ctx['pdo']->inTransaction())$ctx['pdo']->rollBack();}
    catch(Throwable $e){$out['ok']=false;$out['error']='h13941_read_transaction_cleanup_failed';}
    foreach(array_reverse($locks) as $lock)if(is_resource($lock)){flock($lock,LOCK_UN);fclose($lock);}
    echo 'MONITOR_H13941_HOLD_RELEASE='.h13941_json($out)."\n";return $out['ok']?0:2;
}
if(!defined('H13941_TEST_LIBRARY'))exit(h13941_main($argv));
