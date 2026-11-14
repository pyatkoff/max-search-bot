<?php
declare(strict_types=1);
require_once __DIR__.'/monitor-price-hook-contract.php';

/** One authorized hold removal. No editor, feed writer, collector, supplier or child-journal write. */
const H1142_ROOT = '/var/www/anytoour/data/channel-post-operations';
const H1142_RUNTIME = '/var/www/anytoour/data/www/app.anytoour.ru';
const H1142_INSTALLED = '4aa59d7355c928382fe3d939731f1348f2b11c7f';
const H1142_ROSTER_SOURCE = '8cfdf5519730de743b3cf5122a83b652ff45d2dd';
const H1142_ROSTER_ROW_SHA256 = '97ca998fbda4ee7ad9971fc9f01a0da91bdf91c2d81fafc5262d5c58bd903374';
const H1142_OPERATION = 'commercial-monitor-price-h1142-41661d209964ffc44d0285473085d0a86d149f35642ec95558769a70d4346839';
const H1142_ORIGINAL_RUN = '43182188959';
const H1142_ORIGINAL_SOURCE = '547e6ba60fd67e4cf12914694dd9b96413995492';
const H1142_CHILD_SHA256 = '8690a4310a61103f25d62c5ca04346e41241405b13ef1c9926ebdeeee666eb4e';
const H1142_MANIFEST = '09c8f7ffa8c81d6cafaf2c7f89a5915a181e619779ba00b78114e8aeb704a920';
const H1142_TERMINAL = [
    'state'=>'abandoned_before_write','reason'=>'expired_observation_after_no_write_failure',
    'prior_sha256'=>'d69c4dbeeca3e566622dc49095750cfd5aa95cd4a849e19aec449b7244f2b835',
    'prior_bytes'=>7253,'prior_lines'=>1,'recorded_at'=>'2026-11-12T08:21:13+00:00',
    'readback_evidence_sha256'=>'ba4e2f63905bc35cdf168dcbd02d98ee1e970da543c06c80b327d469f25b0bef',
];
const H1142_HOLD = ['operation'=>H1142_OPERATION,'reason'=>'abandoned_before_write',
    'terminal_journal_sha256'=>H1142_CHILD_SHA256,'at'=>'2026-11-12T08:21:13+00:00'];
const H1142_ORIGINAL_PUBLICATION = 'commercial-hotel-batch-20261107-24-v1';
const H1142_LOGICAL_ID = 'commercial-crystal-flora-1142-v1';
const H1142_ORIGINAL_TEXT = '780f5ca52e67611f1aaf3ce5b96a07db8f166bb0e89e859371e5d90b28ef0dcb';
const H1142_COVER = 'ee03f78b6341175b790a8e092432c65562668b6e6e3a90d993042e59e65914bd';
const H1142_TEXT = '3ee77c439602035308c3e94aa47bcbad9dfd647492fc9b1a4381b3ec3254f16f';
const H1142_BUTTON = '✈️ Узнать стоимость тура';
// Accepted review73 batch001 material; version IDs below are historical lower bounds, never current pins.
const H1142_TARGETS = [
    'max'=>['bot'=>'id9704048781_1_bot','channel'=>'-80492244209683','source'=>'max_anytour_msk1','channel_row'=>9,
        'publication_id'=>791,'previous_version_id'=>2833,'message_id'=>'mid.ffffb6caf0606fed01a1b7188309158b',
        'post_url'=>'https://max.ru/channel_anytour/AaG3GIMJFYs','photo_signature'=>'max:49855578632',
        'button_url_sha256'=>'80ec5beb81e2e0625981a34a0ded622d78c5813ac388b71426503a3aa7c7fbc0'],
    'telegram'=>['bot'=>'anyru_bot','channel'=>'-1002262666616','source'=>'tg_anytour_msk','channel_row'=>10,
        'publication_id'=>783,'previous_version_id'=>2834,'message_id'=>'343',
        'post_url'=>'https://t.me/any_toour/343','photo_signature'=>'telegram:AQADyTlrG-HPeUt9',
        'button_url_sha256'=>'d25c7c24293866a35cebd211ab26f37893caf515aa847587d6e51f32dbaf292b'],
];
const H1142_PINS = [
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

function h1142_need(bool $ok,string $code):void { if(!$ok)throw new RuntimeException($code); }
function h1142_json($value):string { return json_encode($value,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR); }
function h1142_hash($value):string { return hash('sha256',h1142_json($value)); }
function h1142_source(string $head,bool $trackedClean,array $pins):void {
    h1142_need($head===H1142_INSTALLED,'h1142_installed_source_changed');
    h1142_need($trackedClean,'h1142_tracked_tree_changed');
    h1142_need(count($pins)===22&&$pins===H1142_PINS,'h1142_publishing_surface_changed');
}
function h1142_terminal(array $journal):void {
    h1142_need(($journal['operation']??null)===H1142_OPERATION&&($journal['manifest_hash']??null)===H1142_MANIFEST
        &&($journal['_terminal_journal_sha256']??null)===H1142_CHILD_SHA256
        &&($journal['terminal']??null)===H1142_TERMINAL,'h1142_original_terminal_changed');
    h1142_need(($journal['posts']??null)===[]&&($journal['configs']??null)===[]
        &&(!array_key_exists('complete',$journal)||$journal['complete']===false),'h1142_original_has_write_intent');
    h1142_need(($journal['feed_binding']['tourvisor_hotel_id']??null)===1142,'h1142_original_hotel_changed');
    foreach(H1142_TARGETS as $platform=>$target)h1142_need(($journal['feed_binding']['posts'][$platform]??null)===$target['post_url'],'h1142_original_pair_changed');
}
function h1142_child(string $raw):array {
    h1142_need(strlen($raw)<=20000&&substr_count($raw,"\n")===2&&hash('sha256',$raw)===H1142_CHILD_SHA256,'h1142_original_child_changed');
    $prefix=substr($raw,0,H1142_TERMINAL['prior_bytes']);$proof=monitor_hook_no_write_prefix($prefix,H1142_OPERATION);
    h1142_need($proof['sha256']===H1142_TERMINAL['prior_sha256']&&$proof['bytes']===7253&&$proof['lines']===1,'h1142_original_prefix_changed');
    $journal=monitor_hook_parse_journal($raw,H1142_OPERATION);h1142_terminal($journal);return $journal;
}
/** Pure transition: preserve all other state, including unknown fields and every other hold. */
function h1142_remove_hold(array $state,array $journal):array {
    h1142_terminal($journal);
    h1142_need(($state['schema']??null)===1&&is_array($state['completed']??null),'h1142_global_state_invalid');
    h1142_need(array_key_exists('pending',$state)&&$state['pending']===null,'h1142_pending_operation_stop');
    h1142_need(is_array($state['holds']??null)&&($state['holds'][1142]??null)===H1142_HOLD,'h1142_exact_hold_changed');
    h1142_need(!array_key_exists(1142,$state['completed']),'h1142_target_already_completed_stop');
    h1142_need(monitor_hook_hold_reason(1142,$state['holds'][1142],$journal)==='abandoned_before_write','h1142_hold_evidence_changed');
    $cycle=$state['nightly_cycle']??null;
    if($cycle!==null){
        h1142_need(is_array($cycle)&&($cycle['state']??null)!=='started','h1142_active_collector_cycle_stop');
        monitor_hook_attempted_collector_runs([h1142_json(['nightly_cycle'=>$cycle])]);
    }
    $next=$state;unset($next['holds'][1142]);
    $before=$state;$after=$next;unset($before['holds'],$after['holds']);
    h1142_need($before===$after,'h1142_unrelated_state_changed');return $next;
}
/** Keep JSON object/array shapes; refuse noncanonical or duplicate-key input instead of normalizing it. */
function h1142_next_line(string $line,array $journal):array {
    h1142_need(str_ends_with($line,"\n")&&substr_count($line,"\n")===1&&strlen($line)<=2000000,'h1142_global_line_invalid');
    $object=json_decode(substr($line,0,-1),false,64,JSON_THROW_ON_ERROR);
    h1142_need($object instanceof stdClass&&h1142_json($object)."\n"===$line,'h1142_global_encoding_changed');
    $state=json_decode($line,true,64,JSON_THROW_ON_ERROR);$next=h1142_remove_hold($state,$journal);
    h1142_need(($object->holds??null) instanceof stdClass&&property_exists($object->holds,'1142'),'h1142_hold_encoding_changed');
    $object->holds=clone $object->holds;unset($object->holds->{'1142'});
    $nextLine=h1142_json($object)."\n";
    h1142_need(json_decode($nextLine,true,64,JSON_THROW_ON_ERROR)===$next,'h1142_transition_encoding_changed');
    return ['state'=>$next,'line'=>$nextLine];
}
function h1142_ready(array $cap,string $platform):void {
    h1142_need(($cap['platform']??null)===$platform&&($cap['state']??null)==='ready'&&($cap['reason']??null)==='capability_verified'
        &&array_key_exists('error',$cap)&&$cap['error']===null&&($cap['request_count']??null)===3
        &&($cap['write_attempts']??null)===0&&($cap['http_results_truncated']??null)===false
        &&is_array($cap['http_results']??null)&&count($cap['http_results'])===3,'h1142_current_channel_not_ready');
    foreach($cap['http_results'] as $http)h1142_need(($http['http']??null)===200&&($http['transport_error']??null)===false,'h1142_capability_http_not_ready');
}
function h1142_no_active(bool $active):void { h1142_need(!$active,'h1142_active_publication_writer_stop'); }
function h1142_context(string $platform,array $proof):void {
    h1142_need(array_key_exists($platform,H1142_TARGETS),'h1142_platform_invalid');$target=H1142_TARGETS[$platform];
    foreach(['publication_id','message_id','post_url','photo_signature','button_url_sha256'] as $field)
        h1142_need(($proof[$field]??null)===$target[$field],'h1142_current_identity_changed');
    h1142_need(is_int($proof['version_id']??null)&&$proof['version_id']>$target['previous_version_id']
        &&($proof['project_key']??null)==='anytour'&&($proof['platform']??null)===$platform&&($proof['bot_key']??null)===$target['bot']
        &&($proof['channel_id']??null)===$target['channel']&&($proof['source_key']??null)===$target['source']
        &&($proof['state']??null)==='sent'&&($proof['write_attempts']??null)===0
        &&array_key_exists('claim_token',$proof)&&$proof['claim_token']===null
        &&array_key_exists('claimed_at',$proof)&&$proof['claimed_at']===null,'h1142_current_publication_unresolved');
    h1142_need(($proof['text_sha256']??null)===H1142_TEXT&&array_key_exists('monitor_price_record_id',$proof)
        &&$proof['monitor_price_record_id']===null,'h1142_current_caption_changed');
    h1142_need(($proof['only_expected_button']??null)===true&&($proof['button_resolves_current_version']??null)===true
        &&is_string($proof['attachments_sha256']??null)&&preg_match('/\A[a-f0-9]{64}\z/D',$proof['attachments_sha256'])===1,'h1142_current_photo_or_button_changed');
}
function h1142_photo(array $snapshot):string {
    $photos=$snapshot['attachments']??null;
    h1142_need(is_array($photos)&&count($photos)===1&&($photos[0]['type']??null)==='image','h1142_current_photo_shape_changed');
    $photo=$photos[0];$id=(string)($photo['photo_id']??$photo['file_unique_id']??'');
    h1142_need($id!==''&&preg_match('/\A[A-Za-z0-9_-]+\z/D',$id)===1,'h1142_current_photo_identity_missing');
    return (string)($photo['provider']??'').':'.$id;
}
function h1142_git(array $args):string {
    $pipes=[];$p=proc_open(array_merge(['git','--no-optional-locks','-C',H1142_RUNTIME],$args),
        [0=>['file','/dev/null','r'],1=>['pipe','w'],2=>['file','/dev/null','w']],$pipes,H1142_RUNTIME,null,['bypass_shell'=>true]);
    h1142_need(is_resource($p),'h1142_git_start_failed');$raw=stream_get_contents($pipes[1],32769);fclose($pipes[1]);$exit=proc_close($p);
    h1142_need($exit===0&&is_string($raw)&&strlen($raw)<=32768,'h1142_git_read_failed');return trim($raw);
}
function h1142_runtime():void {
    h1142_need(!is_link(H1142_RUNTIME)&&realpath(H1142_RUNTIME)===H1142_RUNTIME&&chdir(H1142_RUNTIME),'h1142_runtime_path_invalid');
    foreach(H1142_PINS as $file=>$pin)h1142_need(!is_link(H1142_RUNTIME.'/'.$file)&&realpath(H1142_RUNTIME.'/'.$file)===H1142_RUNTIME.'/'.$file,'h1142_pinned_path_invalid');
    $head=h1142_git(['rev-parse','--verify','HEAD']);$clean=h1142_git(['status','--porcelain','--untracked-files=no'])==='';
    $blobs=explode("\n",h1142_git(array_merge(['hash-object','--'],array_keys(H1142_PINS))));
    h1142_need(count($blobs)===22,'h1142_runtime_pin_count_changed');h1142_source($head,$clean,array_combine(array_keys(H1142_PINS),$blobs));
}
/** Bounded whole-prefix hashing; the global history is streamed, never rewritten or truncated. */
function h1142_read($stream,int $cap,bool $retainRaw=false):array {
    h1142_need(rewind($stream),'h1142_journal_rewind_failed');$ctx=hash_init('sha256');$bytes=0;$lines=0;$last=null;$lastLine='';$raw='';
    while(!feof($stream)){
        $line=fgets($stream,2000002);if($line===false){h1142_need(feof($stream),'h1142_journal_read_failed');break;}
        $bytes+=strlen($line);$lines++;
        h1142_need($bytes<=$cap&&$lines<=10000&&strlen($line)<=2000000&&str_ends_with($line,"\n"),'h1142_journal_partial_or_oversize');
        $last=json_decode($line,true,64,JSON_THROW_ON_ERROR);h1142_need(is_array($last),'h1142_journal_shape_invalid');
        hash_update($ctx,$line);$lastLine=$line;if($retainRaw)$raw.=$line;
    }
    h1142_need($bytes>0&&$lines>0,'h1142_journal_empty');
    return ['meta'=>['sha256'=>hash_final(hash_copy($ctx)),'bytes'=>$bytes,'lines'=>$lines],
        'last'=>$last,'last_line'=>$lastLine,'raw'=>$raw,'hash_context'=>$ctx];
}
/** Global EX, immutable child EX, approved receipts SH. Only the existing global handle is writable. */
function h1142_open(string $path,string $mode,int $lockMode,int $cap,array &$locks):array {
    h1142_need(!is_link(H1142_ROOT)&&realpath(H1142_ROOT)===H1142_ROOT&&!is_link(dirname($path))
        &&realpath(dirname($path))===dirname($path)&&!is_link($path)&&is_file($path)&&realpath($path)===$path,'h1142_private_path_invalid');
    $named=lstat($path);h1142_need(is_array($named)&&($named['mode']&0170000)===0100000&&$named['size']>0&&$named['size']<=$cap,'h1142_private_size_invalid');
    $stream=fopen($path,$mode);h1142_need(is_resource($stream),'h1142_private_open_failed');$locks[]=$stream;
    h1142_need(flock($stream,$lockMode|LOCK_NB),'h1142_existing_writer_busy');$stat=fstat($stream);
    h1142_need(is_array($stat)&&$stat['ino']===$named['ino']&&$stat['dev']===$named['dev']&&$stat['size']===$named['size'],'h1142_private_identity_changed');
    $file=['stream'=>$stream,'path'=>$path,'stat'=>$stat,'cap'=>$cap];h1142_stable($file);return $file;
}
function h1142_stable(array $file):void {
    clearstatcache(true,$file['path']);$named=lstat($file['path']);$opened=fstat($file['stream']);
    h1142_need(is_array($named)&&is_array($opened)&&!is_link($file['path'])&&realpath($file['path'])===$file['path'],'h1142_named_path_changed');
    foreach(['ino','dev','size','mtime','ctime'] as $field)h1142_need($named[$field]===$file['stat'][$field]&&$opened[$field]===$file['stat'][$field],'h1142_locked_file_changed');
}
function h1142_original_receipts(array &$locks):array {
    $result=[];
    foreach(H1142_TARGETS as $platform=>$target){
        $file=h1142_open(H1142_ROOT.'/'.H1142_ORIGINAL_PUBLICATION.'/'.$platform.'-'.H1142_LOGICAL_ID.'-approved.json','rb',LOCK_SH,1048576,$locks);
        $raw=stream_get_contents($file['stream'],1048577);h1142_need(is_string($raw)&&strlen($raw)===$file['stat']['size'],'h1142_original_receipt_read_failed');
        $original=json_decode($raw,true,64,JSON_THROW_ON_ERROR);$r=$original['receipt']??[];
        h1142_need(($original['state']??null)==='confirmed'&&($original['text_sha256']??null)===H1142_ORIGINAL_TEXT
            &&($original['photo_sha256']??null)===H1142_COVER&&($r['logical_id']??null)===H1142_LOGICAL_ID
            &&(int)($r['hotel_id']??0)===1142&&($r['platform']??null)===$platform&&($r['post_url']??null)===$target['post_url']
            &&($r['body_photo_button_verified']??null)===true&&(int)($r['publication_id']??0)===$target['publication_id']
            &&(string)($r['message_id']??'')===$target['message_id'],'h1142_original_receipt_pair_changed');
        h1142_stable($file);$result[$platform]=['file'=>$file,'sha256'=>hash('sha256',$raw)];
    }return $result;
}
function h1142_active_versions(PDO $pdo):void {
    $q=$pdo->prepare("SELECT id FROM post_versions WHERE publication_id IN (791,783) AND (state IN ('pending','claimed','write_started','unknown') OR claim_token IS NOT NULL OR claimed_at IS NOT NULL) LIMIT 1");
    h1142_need($q!==false&&$q->execute(),'h1142_active_writer_read_failed');h1142_no_active($q->fetch(PDO::FETCH_ASSOC)!==false);$q->closeCursor();
}
function h1142_publication(ChannelPostRegistryService $registry,string $platform):array {
    $target=H1142_TARGETS[$platform];$p=$registry->publication($target['publication_id']);
    h1142_need(is_array($p)&&is_array($p['snapshot']??null),'h1142_current_publication_missing');$s=$p['snapshot'];
    $button=ChannelPostButtonTransport::buttonUrl($p);$resolved=$registry->resolveToken('anytour',$platform,$target['bot'],(string)($p['start_payload']??''));
    $proof=[];foreach(['publication_id','version_id','project_key','platform','bot_key','channel_id','source_key','state','write_attempts','claim_token','claimed_at'] as $field){
        h1142_need(array_key_exists($field,$p),'h1142_current_publication_field_missing');$proof[$field]=$p[$field];
    }
    $proof+=['message_id'=>$p['external_message_id']??null,'post_url'=>$s['post_url']??null,
        'text_sha256'=>hash('sha256',(string)($s['text']??'')),'photo_signature'=>h1142_photo($s),
        'attachments_sha256'=>h1142_hash($s['attachments']),'button_url_sha256'=>hash('sha256',$button),
        'monitor_price_record_id'=>$s['metadata']['monitor_price_record_id']??null,
        'only_expected_button'=>ChannelPostIncomingAdapter::containsOnlyButton($s,$button,H1142_BUTTON),
        'button_resolves_current_version'=>is_array($resolved)&&($resolved['publication_id']??null)===$p['publication_id']&&($resolved['version_id']??null)===$p['version_id']];
    h1142_context($platform,$proof);
    return ['proof'=>$proof,'snapshot'=>$s,'button_url'=>$button,'guard_sha256'=>h1142_hash($p)];
}
/** Seven bounded provider reads; only the two exact publication IDs and their versions are read in the DB. */
function h1142_read_current(array &$out,array &$ctx):array {
    ob_start();try{
        require H1142_RUNTIME.'/config.php';
        require_once H1142_RUNTIME.'/services/ChannelPostRuntime.php';
        require_once H1142_RUNTIME.'/services/ChannelPostRegistryService.php';
        require_once H1142_RUNTIME.'/services/ChannelPostButtonTransport.php';
    }finally{ob_end_clean();}
    $pdo=ConversationDb::connection();$ctx['pdo']=$pdo;h1142_need(!$pdo->inTransaction(),'h1142_existing_database_transaction_stop');
    $pdo->exec('SET TRANSACTION ISOLATION LEVEL READ COMMITTED, READ ONLY');$pdo->beginTransaction();
    h1142_need($pdo->inTransaction(),'h1142_read_only_transaction_unavailable');$registry=new ChannelPostRegistryService($pdo);$ctx['registry']=$registry;
    h1142_active_versions($pdo);$proof=['captured_at'=>gmdate('c'),'database_session_read_only'=>true,'isolation'=>'READ COMMITTED',
        'active_publication_versions'=>0,'capabilities'=>[],'publications'=>[]];$ctx['started_at']=time();$ctx['guard_hashes']=[];
    foreach(H1142_TARGETS as $platform=>$target){
        $identity=ChannelPostRuntime::identity($platform);
        h1142_need(is_array($identity)&&($identity['project_key']??null)==='anytour'&&($identity['bot_key']??null)===$target['bot'],'h1142_configured_bot_changed');
        $channel=$registry->channel('anytour',$platform,$target['bot'],$target['channel']);
        h1142_need(is_array($channel)&&(int)($channel['id']??0)===$target['channel_row']&&($channel['source_key']??null)===$target['source']
            &&(int)($channel['config_version']??0)===2&&($channel['button_text']??null)===H1142_BUTTON,'h1142_channel_configuration_changed');
        $cap=monitor_price_channel_preflight($platform,(new ChannelPostButtonTransport())->verifyChannel($channel));
        $out['provider_read_requests']+=is_int($cap['request_count']??null)?$cap['request_count']:0;$proof['capabilities'][$platform]=$cap;
        $out['current_readback']=$proof;h1142_ready($cap,$platform);
        $current=h1142_publication($registry,$platform);$proof['publications'][$platform]=$current['proof'];$ctx['guard_hashes'][$platform]=$current['guard_sha256'];
        if($platform==='max'){
            $out['provider_read_requests']++;
            $response=ChannelPostApiTransport::buttonRequest('max','GET','/messages/'.$target['message_id'],[],null);
            h1142_need(($response['transport_error']??true)===false&&($response['http']??null)===200&&is_array($response['data']??null),'h1142_max_current_read_failed');
            $message=$response['data'];h1142_need(($message['body']['mid']??null)===$target['message_id']
                &&(string)($message['recipient']['chat_id']??'')===$target['channel']&&($message['recipient']['chat_type']??null)==='channel','h1142_max_current_identity_changed');
            $live=ChannelPostIncomingAdapter::maxSnapshot($message);
            h1142_need(is_array($live)&&($live['text']??null)===($current['snapshot']['text']??null)&&($live['post_url']??null)===$target['post_url']
                &&h1142_photo($live)===$target['photo_signature']&&ChannelPostIncomingAdapter::containsOnlyButton($live,$current['button_url'],H1142_BUTTON),'h1142_max_current_content_changed');
            $proof['max_readback']=['http'=>200,'transport_error'=>false,'identity_matches'=>true,'caption_matches'=>true,
                'photo_matches'=>true,'url_matches'=>true,'only_expected_button'=>true];
        }
    }
    h1142_need($out['provider_read_requests']===7,'h1142_provider_read_budget_changed');
    $proof['completed_at']=gmdate('c');$proof['provider_read_requests']=7;$out['current_readback']=$proof;return $proof;
}
function h1142_recheck_current(array $ctx):void {
    h1142_need(time()-$ctx['started_at']<=60,'h1142_current_readback_expired');h1142_active_versions($ctx['pdo']);
    foreach(H1142_TARGETS as $platform=>$target){$current=h1142_publication($ctx['registry'],$platform);
        h1142_need($current['guard_sha256']===$ctx['guard_hashes'][$platform],'h1142_current_registry_cas_changed');}
}
/** Exactly one append attempt. A failed or uncertain write is terminal for this invocation. */
function h1142_append(array $file,string $line,array &$out):array {
    h1142_need($file['path']===H1142_ROOT.'/monitor-price-after-collection-v1.jsonl'&&str_ends_with($line,"\n")
        &&substr_count($line,"\n")===1&&strlen($line)<=2000000,'h1142_append_scope_invalid');
    h1142_stable($file);$current=h1142_read($file['stream'],$file['cap']);
    h1142_need($current['meta']===$file['meta'],'h1142_global_cas_changed');
    $hash=hash_copy($file['hash_context']);hash_update($hash,$line);
    $expected=['sha256'=>hash_final($hash),'bytes'=>$file['meta']['bytes']+strlen($line),'lines'=>$file['meta']['lines']+1];
    h1142_need($expected['bytes']<=$file['cap']&&$expected['lines']<=10000,'h1142_append_capacity_exceeded');
    h1142_need(fseek($file['stream'],0,SEEK_END)===0,'h1142_append_seek_failed');
    $out['journal_write_attempts']++;$out['write_outcome']='unknown_until_exact_readback';
    h1142_need(fwrite($file['stream'],$line)===strlen($line)&&fflush($file['stream']),'h1142_append_failed');
    if(function_exists('fsync'))h1142_need(fsync($file['stream']),'h1142_append_sync_failed');
    $after=h1142_read($file['stream'],$file['cap']);h1142_need($after['meta']===$expected&&$after['last_line']===$line,'h1142_append_readback_failed');
    clearstatcache(true,$file['path']);$named=lstat($file['path']);$opened=fstat($file['stream']);
    h1142_need(is_array($named)&&is_array($opened)&&!is_link($file['path'])&&realpath($file['path'])===$file['path']
        &&$named['ino']===$file['stat']['ino']&&$named['dev']===$file['stat']['dev']&&$opened['ino']===$named['ino']
        &&$opened['dev']===$named['dev']&&$named['size']===$expected['bytes']&&$opened['size']===$expected['bytes'],'h1142_append_identity_changed');
    $out['journal_appends']++;$out['write_outcome']='global_append_verified_child_check_pending';return $expected;
}
function h1142_main(array $argv):int {
    if(PHP_SAPI!=='cli'){http_response_code(404);return 2;}
    ini_set('display_errors','0');ini_set('log_errors','0');
    $out=['ok'=>false,'mode'=>'release_exact_hotel_no_write_hold','operation'=>H1142_OPERATION,'tourvisor_hotel_id'=>1142,
        'original_run_id'=>H1142_ORIGINAL_RUN,'original_run_attempt'=>1,'original_source_sha'=>H1142_ORIGINAL_SOURCE,
        'accepted_roster_source_sha'=>H1142_ROSTER_SOURCE,'accepted_roster_row_sha256'=>H1142_ROSTER_ROW_SHA256,
        'journal_write_attempts'=>0,'journal_appends'=>0,'child_journal_writes'=>0,'database_writes'=>0,'post_edits'=>0,'new_posts'=>0,
        'feed_writes'=>0,'configuration_writes'=>0,'supplier_calls'=>0,'site_writes'=>0,'provider_write_requests'=>0,'provider_read_requests'=>0,
        'write_outcome'=>'not_started','hold_removed'=>false];$locks=[];$ctx=[];
    try{
        h1142_need(count($argv)===1,'h1142_arguments_invalid');h1142_runtime();
        $out['runtime_git_head']=H1142_INSTALLED;$out['runtime_tracked_clean']=true;$out['runtime_pin_count']=22;$out['runtime_pins_sha256']=h1142_hash(H1142_PINS);
        $global=h1142_open(H1142_ROOT.'/monitor-price-after-collection-v1.jsonl','r+b',LOCK_EX,134217728,$locks);
        $global+=h1142_read($global['stream'],$global['cap']);h1142_stable($global);
        $child=h1142_open(H1142_ROOT.'/'.H1142_OPERATION.'/receipt.jsonl','rb',LOCK_EX,20000,$locks);
        $child+=h1142_read($child['stream'],$child['cap'],true);h1142_stable($child);
        $journal=h1142_child($child['raw']);$planned=h1142_next_line($global['last_line'],$journal);$state=$global['last'];
        $out['before']=['global'=>$global['meta'],'child'=>$child['meta'],'hold'=>H1142_HOLD,'pending'=>null,
            'completed_count'=>count($state['completed']),'completed_sha256'=>h1142_hash($state['completed']),
            'cycle_sha256'=>h1142_hash($state['nightly_cycle']??null),'other_holds_sha256'=>h1142_hash($planned['state']['holds'])];
        $out['original_all_history_no_write']=true;$out['original_terminal']=H1142_TERMINAL;
        $receipts=h1142_original_receipts($locks);foreach($receipts as $platform=>$receipt)$out['original_receipts_sha256'][$platform]=$receipt['sha256'];
        $proof=h1142_read_current($out,$ctx);h1142_runtime();
        foreach($receipts as $receipt){h1142_stable($receipt['file']);h1142_need(rewind($receipt['file']['stream']),'h1142_receipt_rewind_failed');
            $raw=stream_get_contents($receipt['file']['stream'],1048577);h1142_need(is_string($raw)&&hash('sha256',$raw)===$receipt['sha256'],'h1142_original_receipt_cas_changed');}
        h1142_stable($child);$childNow=h1142_read($child['stream'],$child['cap'],true);
        h1142_need($childNow['meta']===$child['meta']&&$childNow['raw']===$child['raw'],'h1142_original_child_cas_changed');
        h1142_recheck_current($ctx);
        $after=h1142_append($global,$planned['line'],$out);
        h1142_stable($child);$childAfter=h1142_read($child['stream'],$child['cap'],true);
        h1142_need($childAfter['meta']===$child['meta']&&$childAfter['raw']===$child['raw'],'h1142_original_child_postcheck_failed');
        $next=$planned['state'];$out['after']=['global'=>$after,'child'=>$childAfter['meta'],'hold_present'=>array_key_exists(1142,$next['holds']),
            'pending'=>$next['pending'],'completed_count'=>count($next['completed']),'completed_sha256'=>h1142_hash($next['completed']),
            'cycle_sha256'=>h1142_hash($next['nightly_cycle']??null),'other_holds_sha256'=>h1142_hash($next['holds']),
            'all_other_global_fields_preserved'=>true,'original_history_preserved'=>true,'old_operation_remains_terminal'=>true];
        $out['current_readback_sha256']=h1142_hash($proof);$out['write_outcome']='global_append_verified';$out['recorded_at']=gmdate('c');
        $out['hold_removed']=true;$out['preserved_completed']=true;$out['preserved_completed_count']=count($next['completed']);$out['pending']=null;
        $out['status']='exact_hold_removed_new_observations_require_normal_monitor_pass';$out['ok']=true;
    }catch(Throwable $e){$out['error']=preg_match('/\A[a-z][a-z0-9_]{1,120}\z/D',$e->getMessage())===1?$e->getMessage():'h1142_reconciliation_failed';}
    try{if(($ctx['pdo']??null) instanceof PDO&&$ctx['pdo']->inTransaction())$ctx['pdo']->rollBack();}
    catch(Throwable $e){$out['ok']=false;$out['error']='h1142_read_transaction_cleanup_failed';}
    foreach(array_reverse($locks) as $lock)if(is_resource($lock)){flock($lock,LOCK_UN);fclose($lock);}
    echo 'MONITOR_H1142_HOLD_RELEASE='.h1142_json($out)."\n";return $out['ok']?0:2;
}
if(!defined('H1142_TEST_LIBRARY'))exit(h1142_main($argv));
