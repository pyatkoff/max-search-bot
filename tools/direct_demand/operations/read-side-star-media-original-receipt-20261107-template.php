<?php
declare(strict_types=1);
ini_set('display_errors','0');ini_set('log_errors','0');
$out=['operation'=>'commercial-side-star-media-20261107-1-read1','mode'=>'read_original_media_receipt_and_current_state_only','ok'=>false,'provider_writes'=>0,'registry_writes'=>0,'new_posts_created'=>0];
function need(bool $v,string $reason):void{if(!$v)throw new RuntimeException($reason);}
function photoSignature(array $snapshot):string{$media=$snapshot['attachments']??[];need(count($media)===1&&($media[0]['type']??'')==='image','expected_one_photo');$m=$media[0];$id=(string)($m['photo_id']??$m['file_unique_id']??'');need($id!==''&&preg_match('/\A[A-Za-z0-9_-]+\z/',$id)===1,'photo_stable_id_missing');return (string)($m['provider']??'').':'.$id;}
function sameContext(array $p,array $s,array $proof,string $text):bool{return ($s['text']??'')===$text&&($s['post_url']??'')===$proof['post_url']&&ChannelPostButtonTransport::buttonUrl($p)===$proof['button_url']&&ChannelPostIncomingAdapter::containsOnlyButton($s,$proof['button_url'],'✈️ Узнать стоимость тура');}
function readMax(string $mid):array{
 need(preg_match('/\A[A-Za-z0-9_.-]{1,191}\z/',$mid)===1,'invalid_saved_message_id');$r=ChannelPostApiTransport::buttonRequest('max','GET','/messages/'.$mid,[],null);
 need(empty($r['transport_error'])&&(int)$r['http']===200&&is_array($r['data']),'provider_post_read_failed');return $r['data'];
}
try {
    if(PHP_SAPI!=='cli'||trim((string)shell_exec('git rev-parse HEAD'))!==EXPECTED_INSTALLED_SHA)throw new RuntimeException('installed_source_changed');
    // Inspect only the unchanged, previously accepted publishing surface.
    $accepted=[
        "integrations/ChannelPostApiTransport.php"=>"300634d77cb9fddf51adf9869974f956cca6e3f9",
        "migrations/033_channel_post_context.sql"=>"0530458030a4bfe6b6cc55d1e5e441745c29b947",
        "migrations/036_channel_post_member_delivery.sql"=>"1bda463e52b33130ad12752ddef4ee5c92699c05",
        "migrations/040_channel_post_reconciliation.sql"=>"0f24b838eacd45333c5ade9a2991d060488c6035",
        "services/ChannelPostAdminService.php"=>"b80eefc801054bfe6fdb58ec345ecdf687b2e89a",
        "services/ChannelPostAutomationService.php"=>"f5975ab95de5f45e087eb7a0f768f566c5bca648",
        "services/ChannelPostButtonTransport.php"=>"b6c123069810ad490391e825315abd8567c55310",
        "services/ChannelPostEntryService.php"=>"f0e04e9b4e0ed6859ced96279cbf0619931596e6",
        "services/ChannelPostMaxAlbumRecovery.php"=>"de266cd068587cc3b2ff83f308a966ea66887a04",
        "services/ChannelPostMediaService.php"=>"f794efd0f6f24df916e18909ff6281b0bc928433",
        "services/ChannelPostMemberDeliveryService.php"=>"20c1d7984898b2a5996a5f9e20e4d2d8c20b4778",
        "services/ChannelPostReconciliationService.php"=>"2b559b067a91abb9402a9dc2f3ce75d1ddedc7c3",
        "services/ChannelPostReconciliationState.php"=>"d9583ceca80d1424a44ade3561c21282a23a52f0",
        "services/ChannelPostRegistryService.php"=>"a407757b9ac54a9e286900d34385d519d22b8c84",
        "services/ChannelPostRuntime.php"=>"2f1dd9b77474fdbaa92f0402f3b0aa170c595474",
        "services/ChannelPostTelegramAlbum.php"=>"faa1a14bc279a4af33936282126bf0d46e343040",
        "services/ChannelPostWebhookConfigService.php"=>"d5fdcb056088f1e3a541b4046325d97eef6417d9",
        "services/ConversationDb.php"=>"bb6281faf55aca7beeae7f8a57389d19751936f7",
        "services/MaxTlsConfig.php"=>"032c0211ee3f39c6f0d27c669abd5b467e321dea",
        "services/MaxTransport.php"=>"bfa583167347ff42ed632c52a552e5585bb97de9",
        "services/ProjectConfig.php"=>"538b596981f2c61fbf524e18756bf672578948f6",
    ];
    foreach($accepted as $path=>$blob)if(trim((string)shell_exec('git hash-object -- '.escapeshellarg($path)))!==$blob)throw new RuntimeException('publishing_surface_changed');
    ob_start();try {
        require getcwd().'/config.php';
        require_once getcwd().'/services/ChannelPostRuntime.php';
        require_once getcwd().'/services/ChannelPostAdminService.php';
        require_once getcwd().'/services/ChannelPostEntryService.php';
        require_once getcwd().'/services/MaxTransport.php';
        require_once getcwd().'/integrations/ChannelPostApiTransport.php';
        require_once getcwd().'/services/ChannelPostButtonTransport.php';
    } finally {ob_end_clean();}


    $raw=base64_decode(MANIFEST_BASE64,true);need(is_string($raw)&&hash('sha256',$raw)===APPROVED_MANIFEST_SHA256,'approved_media_input_changed');$in=json_decode($raw,true,128,JSON_THROW_ON_ERROR);
    need(APPROVED_MEDIA_OPERATION==='commercial-side-star-media-20261107-1-v1'&&($in['operation']??'')===APPROVED_MEDIA_OPERATION,'media_operation_changed');
    $row=$in['media'];need($row['hotel_id']===1095&&$row['logical_id']==='commercial-side-star-resort-1095-v1'&&$row['photo_sha256']==='0a117604ce61e6f0d30e6cc16fe90da418ff674298ceec04ee6ce736eff78733'&&$row['photo_bytes']===698890,'approved_side_star_photo_changed');
    need($row['photo_url']==='https://raw.githubusercontent.com/pyatkoff/max-search-bot/1cc100a9817085458305aedc90db5fb950e947f0/.github/traffic-publish/commercial-side-star-media-20261107-1/1095.png','immutable_media_url_changed');
    need(hash('sha256',$row['text'])===$row['text_sha256']&&$row['text_sha256']==='b9835538a3ac268c97257375988a96681242a5173d75e8b137dc18306a976e45','current_cta_caption_changed');
    $prior=$in['prior_result'];need(($prior['operation']??'')==='commercial-hotel-caption-cta-dedup-20261107-1-v1'&&($prior['ok']??null)===true&&($prior['commercial_text_updates']??null)===6&&($prior['new_posts_created']??null)===0,'prior_cta_receipt_invalid');
    $proofs=[];foreach($prior['posts'] as $r)if($r['logical_id']===$row['logical_id']){$platform=$r['platform'];need(in_array($platform,['max','telegram'],true)&&!isset($proofs[$platform])&&$r['text_sha256']===$row['text_sha256']&&$r['body_photo_button_verified']===true&&$r['existing_message_reused']===true,'prior_side_star_binding_changed');$proofs[$platform]=$r;}
    need(count($proofs)===2&&[$proofs['max']['publication_id'],$proofs['max']['version_id']]===[458,929]&&[$proofs['telegram']['publication_id'],$proofs['telegram']['version_id']]===[455,932],'exact_two_current_versions_changed');
    $pdo=ConversationDb::connection();
    foreach(['033_channel_post_context.sql','036_channel_post_member_delivery.sql','040_channel_post_reconciliation.sql'] as $m){$q=$pdo->prepare('SELECT checksum FROM schema_migrations WHERE version=?');$q->execute([$m]);need($q->fetchColumn()===hash_file('sha256',getcwd().'/migrations/'.$m),'publishing_schema_not_accepted');}
    $dir='/var/www/anytoour/data/channel-post-operations/'.APPROVED_MEDIA_OPERATION;
    need(is_dir($dir)&&!is_link($dir)&&realpath($dir)===$dir,'original_operation_directory_missing');
    $receipt=$dir.'/receipt.jsonl';need(is_file($receipt)&&!is_link($receipt)&&filesize($receipt)<2097152,'original_receipt_invalid');
    $f=fopen($receipt,'r');need($f!==false&&flock($f,LOCK_SH|LOCK_NB),'original_operation_busy');
    $raw=stream_get_contents($f);flock($f,LOCK_UN);fclose($f);$lines=array_values(array_filter(explode("\n",$raw)));$last=json_decode(end($lines),true,128,JSON_THROW_ON_ERROR);
    need(($last['operation']??'')===APPROVED_MEDIA_OPERATION&&($last['posts']['max']['state']??'')==='provider_returned'&&!isset($last['posts']['telegram'])&&($last['max_upload_confirmed']??false)===true,'original_operation_state_changed');
    $uploadPath=$dir.'/max-upload-'.$row['photo_sha256'].'.json';need(is_file($uploadPath)&&!is_link($uploadPath)&&filesize($uploadPath)<131072,'original_upload_receipt_invalid');$upload=json_decode(file_get_contents($uploadPath),true,32,JSON_THROW_ON_ERROR);need($upload['state']==='confirmed'&&$upload['photo_sha256']===$row['photo_sha256'],'original_upload_not_confirmed');
    $registry=new ChannelPostRegistryService($pdo);$bindings=[];
    foreach(['max'=>458,'telegram'=>455] as $platform=>$id){$p=$registry->publication($id);need(is_array($p),'current_publication_missing');$proof=$proofs[$platform];$bindings[$platform]=['publication_id'=>$id,'version_id'=>(int)$p['version_id'],'state'=>$p['state'],'message_id'=>$p['external_message_id'],'photo_signature'=>photoSignature($p['snapshot']),'text_sha256'=>hash('sha256',$p['snapshot']['text']??''),'post_url'=>$p['snapshot']['post_url']??'','button_url'=>ChannelPostButtonTransport::buttonUrl($p),'start_payload'=>$p['start_payload'],'prior_context_preserved'=>sameContext($p,$p['snapshot'],$proof,$row['text'])];}
    $message=readMax($proofs['max']['message_id']);$snap=ChannelPostIncomingAdapter::maxSnapshot($message);need(is_array($snap),'max_snapshot_invalid');
    $active=$pdo->query("SELECT id,publication_id,state FROM post_versions WHERE publication_id IN (455,458) AND (state IN ('claimed','write_started','unknown') OR claim_token IS NOT NULL OR claimed_at IS NOT NULL)")->fetchAll(PDO::FETCH_ASSOC);
    $out['original_receipt_sha256']=hash('sha256',$raw);$out['original_receipt_lines']=count($lines);$out['original_receipt_last']=$last;$out['original_upload']=$upload;$out['current_max_message']=$message;$out['current_registry']=$bindings;$out['active_operations']=$active;
    $out['max_readback_summary']=['photo_signature'=>photoSignature($snap),'text_sha256'=>hash('sha256',$snap['text']??''),'caption_button_post_url_preserved'=>sameContext($registry->publication(458),$snap,$proofs['max'],$row['text'])];
    // One current read-only dedup projection for the four explicitly bound next candidates; no content or provider request.
    try{
        $candidates=[['hotel_id'=>24462,'aliases'=>['UGA JUNGLE BEACH','Jungle Beach an Uga Experience','Jungle Beach']],['hotel_id'=>43236,'aliases'=>['SUN SIYAM PASIKUDAH']],['hotel_id'=>101694,'aliases'=>['NOOE MALDIVES KUNAAVASHI','NOOE KUNAAVASHI']],['hotel_id'=>2469,'aliases'=>['NIKA ISLAND RESORT','NIKA ISLAND']]];
        $q=$pdo->query("SELECT pc.platform,v.state,JSON_UNQUOTE(JSON_EXTRACT(v.snapshot_json,'$.metadata.hotel_catalog_id')) AS native_id,SUBSTRING_INDEX(JSON_UNQUOTE(JSON_EXTRACT(v.snapshot_json,'$.text')),CHAR(10),1) AS first_line,JSON_UNQUOTE(JSON_EXTRACT(v.snapshot_json,'$.post_url')) AS post_url FROM post_publications pp JOIN post_channels pc ON pc.id=pp.post_channel_id JOIN post_versions v ON v.id=pp.current_version_id AND v.publication_id=pp.id WHERE pp.post_channel_id IN (9,10) ORDER BY pp.id LIMIT 2000");$all=$q->fetchAll(PDO::FETCH_ASSOC);need(count($all)<2000,'candidate_registry_limit_reached');$fold=static fn($v)=>preg_replace('/[^\pL\pN]/u','',mb_strtolower((string)$v,'UTF-8'));
        $clear=[];foreach($candidates as $candidate){$matches=[];foreach($all as $r){$hit=(int)$r['native_id']===$candidate['hotel_id'];$title=$fold($r['first_line']);foreach($candidate['aliases'] as $alias)if(str_contains($title,$fold($alias)))$hit=true;if($hit)$matches[]=['platform'=>$r['platform'],'state'=>$r['state'],'post_url'=>$r['post_url']];}$clear[]=['native_hotel_id'=>$candidate['hotel_id'],'matches'=>$matches,'clear'=>count($matches)===0];}$out['next_candidate_registry_clearance']=['state'=>'CURRENT_BOUNDED_REGISTRY_READ','read_at_utc'=>gmdate('c'),'rows'=>$clear,'provider_calls'=>0,'writes'=>0];
    }catch(Throwable $e){$out['next_candidate_registry_clearance']=['state'=>'READ_NOT_CONFIRMED','error'=>preg_match('/\A[a-z][a-z0-9_]{1,100}\z/D',$e->getMessage())?$e->getMessage():'candidate_registry_read_failed','provider_calls'=>0,'writes'=>0];}
    $out['ok']=true;
}catch(Throwable $e){$out['error']=preg_match('/\A[a-z][a-z0-9_]{1,100}\z/D',$e->getMessage())?$e->getMessage():'bounded_media_readback_failed';}
echo json_encode($out,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR),PHP_EOL;exit($out['ok']?0:2);
