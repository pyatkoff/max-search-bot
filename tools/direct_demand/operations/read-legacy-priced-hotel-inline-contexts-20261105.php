<?php
declare(strict_types=1);
ini_set('display_errors','0');ini_set('log_errors','0');
$out=['operation'=>APPROVED_CONTEXT18_OPERATION,'mode'=>'readonly_current_context_for_explicit_noquote_hotel_search_links','ok'=>false,'db_writes'=>0,'gateway_calls'=>0,'provider_post_reads'=>0,'provider_channel_rights_checks'=>0,'message_sends'=>0,'price_edits'=>0,'bindings'=>[],'deferred'=>[],'owned_max_context_proofs'=>[]];
function need(bool $ok,string $why):void{if(!$ok)throw new RuntimeException($why);}
function photoSignature(array $s):string{$a=$s['attachments']??[];need(count($a)===1&&($a[0]['type']??'')==='image','expected_one_existing_image');$id=(string)($a[0]['photo_id']??$a[0]['file_unique_id']??'');need($id!==''&&preg_match('/\\A[A-Za-z0-9_-]+\\z/',$id)===1,'stable_photo_id_missing');return (string)($a[0]['provider']??'').':'.$id;}
function readMax(string $id):array{need(preg_match('/\A[A-Za-z0-9_.-]{1,191}\z/',$id)===1,'message_id_invalid');$r=ChannelPostApiTransport::buttonRequest('max','GET','/messages/'.$id,[],null);need(empty($r['transport_error'])&&(int)$r['http']===200&&is_array($r['data']),'max_read_failed');return $r['data'];}


function priorPriceSnapshot24Ok(array $p,array $proof,string $caption,string $captionHash,array $priceMeta):bool{
 if((int)($p['version_id']??0)!==(int)($proof['version_id']??0)||($p['snapshot']['text']??null)!==$caption||($proof['text_sha256']??null)!==$captionHash||hash('sha256',$caption)!==$captionHash)return false;
 foreach($priceMeta as $k=>$v)if(($p['snapshot']['metadata'][$k]??null)!==$v)return false;return true;
}
function legacyOrigin28Ok(array $x,array $h,int $id,array $role):bool{
 $m=$x['owned_price_payload']??[];$r=$m['rows'][0]??[];$p=$x['legacy_role_proof']??[];
 if(!array_key_exists('publisher_hotel_id',$role)||!array_key_exists('publisher_hotel_id',$x)||!array_key_exists('publisher_hotel_id',$p)||!array_key_exists('original_publication_hotel_id',$r))return false;
 $pub=$role['publisher_hotel_id'];
 if($pub===null){if(($h['identity_provenance']??'')!=='accepted_native_catalog_read')return false;}
 elseif(!is_int($pub)||$pub<1||(($h['identity_provenance']??'')==='accepted_typed_bridge'?($h['anytour_id']??null)!==$pub:$id!==$pub))return false;
 return $x['publisher_hotel_id']===$pub&&$r['original_publication_hotel_id']===$pub&&$p['publisher_hotel_id']===$pub&&($p['native_hotel_id']??null)===$id&&($p['identity_provenance']??null)===($h['identity_provenance']??null)&&($h['identity_provenance']??null)===($role['identity_provenance']??null)&&($p['expected_country_id']??null)===($h['expected_country_id']??null)&&($h['expected_country_id']??null)===($role['expected_country_id']??null)&&($p['capture_operation']??null)===($role['capture_operation']??null)&&($p['capture_run']??null)===($role['capture_run']??null)&&($p['capture_source_sha']??null)===APPROVED_CONTEXT18_LEGACY_ORIGIN_SOURCE&&preg_match('/\A[0-9a-f]{64}\z/D',(string)($p['capture_input_sha256']??''))===1&&preg_match('/\A[0-9a-f]{64}\z/D',(string)($p['origin_frame_sha256']??''))===1&&($p['origin_frame_sha256']??null)===($r['legacy_context_sha256']??null)&&($p['original_photo_signatures']??null)===($r['expected_existing_photo_signatures']??null)&&array_keys($p['original_photo_signatures']??[])===['max','telegram']&&($p['origin_witness_only_not_current_publication']??null)===true;
}
function contextRole18(array $x,array $scope):bool{
 $h=$x['hotel']??[];$id=$h['local_tv_id']??0;$map=json_decode(base64_decode(APPROVED_CONTEXT18_IDENTITIES_B64,true),true,256,JSON_THROW_ON_ERROR);$ops=json_decode(base64_decode(APPROVED_CONTEXT18_PRICE_OPS_B64,true),true,256,JSON_THROW_ON_ERROR);$roles=json_decode(base64_decode(APPROVED_CONTEXT18_LEGACY_ROLES_B64,true),true,256,JSON_THROW_ON_ERROR);$expected=null;
 foreach($map as $v)if(($v['local_tv_id']??0)===$id){$expected=$v;break;}
 if(!is_int($id)||!in_array($id,$scope,true)||!in_array($id,APPROVED_CONTEXT18_NATIVE_IDS,true)||!is_array($expected)||$h!==$expected||!in_array($h['expected_country_id']??0,[1,4],true)||($x['kind']??'')!=='current_confirmed_own_legacy_price_manifest_and_receipt'||($x['quote_state']??'NOQUOTE')!=='NOQUOTE'||!is_array($roles[(string)$id]??null)||!legacyOrigin28Ok($x,$h,$id,$roles[(string)$id]))return false;
 $m=$x['owned_price_payload']??[];$r=$m['rows'][0]??[];
 return ($m['operation']??'')===($ops[(string)$id]??null)&&count($m['rows']??[])===1&&($r['hotel_id']??0)===$id&&is_string($r['old_text']??null)&&hash('sha256',$r['old_text'])===($r['old_text_sha256']??'')&&is_string($r['new_text']??null)&&hash('sha256',$r['new_text'])===($r['new_text_sha256']??'')&&!str_contains($r['new_text'],'https://anytour.online/poisk-turov/')&&($r['update_text']??null)===true&&!empty($r['key'])&&!empty($r['logical_id'])&&preg_match('/\A[0-9a-f]{64}\z/D',(string)($m['quote_response_sha256']??''))===1&&is_int($m['quote_epoch_utc']??null)&&$m['quote_epoch_utc']>0&&hash('sha256',json_encode($m,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR))===($x['source_manifest_hash']??'')&&preg_match('/\A[0-9a-f]{64}\z/D',(string)($x['owned_price_manifest_sha256']??''))===1;
}
function contextPartition18Ok(array $d,array $ids,int $linked):bool{
 $n=count($ids);if(!array_is_list($ids)||$n<1||$n>10||count(array_unique($ids,SORT_REGULAR))!==$n)return false;
 foreach($ids as $id)if(!is_int($id)||$id<1)return false;
 $items=$d['items']??null;$deferred=$d['deferred_before_server']??null;$excluded=$d['exclude_already_linked_native_ids']??null;
 if(($d['scope_native_ids']??null)!==$ids||!is_array($items)||!is_array($deferred)||!is_array($excluded)||count($items)<1||count($items)+count($deferred)!==$n||count($excluded)!==$linked)return false;
 $parts=[];foreach($items as $x)$parts[]=$x['hotel']['local_tv_id']??null;foreach($deferred as $x)$parts[]=$x['native_hotel_id']??null;
 foreach($parts as $id)if(!is_int($id)||$id<1)return false;
 if(count(array_unique($parts,SORT_REGULAR))!==$n)return false;sort($parts,SORT_NUMERIC);$expected=$ids;sort($expected,SORT_NUMERIC);return $parts===$expected;
}
function contextInput18():array{
 $raw=base64_decode(APPROVED_CONTEXT18_INPUT_B64,true);need(is_string($raw)&&hash_equals(APPROVED_CONTEXT18_INPUT_SHA256,hash('sha256',$raw)),'link_context_input_bytes_changed');$d=json_decode($raw,true,256,JSON_THROW_ON_ERROR);
 need(($d['operation']??'')===APPROVED_CONTEXT18_OPERATION&&($d['quote_state']??'')==='NOQUOTE'&&contextPartition18Ok($d,APPROVED_CONTEXT18_NATIVE_IDS,APPROVED_CONTEXT18_LINKED_COUNT),'finite_link_context_scope_changed');
 $seen=[];foreach($d['items'] as $x){need(contextRole18($x,$d['scope_native_ids']),'native_publisher_noquote_context_invalid');$id=(int)$x['hotel']['local_tv_id'];need(!isset($seen[$id])&&!in_array($id,$d['exclude_already_linked_native_ids'],true),'duplicate_or_already_linked_context');$seen[$id]=true;}return $d;
}

if(getenv('ANYTOUR_CURRENT_LINK_CONTEXT_NEW_INPUT_CASES')==='1'){
 $d=contextInput18();$tests=0;
 foreach($d['items'] as $x){
  need(contextRole18($x,$d['scope_native_ids']),'legacy_current_price_role_positive_failed');$tests++;
  foreach(['operation','caption','quote_metadata','native','role','identity','publisher_forced','publisher_missing','origin_missing','origin_frame','origin_photo'] as $case){$bad=$x;
   if($case==='operation')$bad['owned_price_payload']['operation'].='-foreign';
   elseif($case==='caption')$bad['owned_price_payload']['rows'][0]['new_text'].='foreign';
   elseif($case==='quote_metadata')$bad['owned_price_payload']['quote_epoch_utc']++;
   elseif($case==='native')$bad['owned_price_payload']['rows'][0]['hotel_id']=999999999;
   elseif($case==='role')$bad['kind']='current_confirmed_own_price_manifest_and_receipt';
   elseif($case==='identity')$bad['hotel']['expected_name'].=' foreign';
   elseif($case==='publisher_forced')$bad['publisher_hotel_id']=$x['publisher_hotel_id']===null?$x['hotel']['local_tv_id']:null;
   elseif($case==='publisher_missing')unset($bad['publisher_hotel_id']);
   elseif($case==='origin_missing')unset($bad['legacy_role_proof']);
   elseif($case==='origin_frame')$bad['legacy_role_proof']['origin_frame_sha256']=str_repeat('0',64);
   else $bad['legacy_role_proof']['original_photo_signatures']['max']='foreign';
   need(!contextRole18($bad,$d['scope_native_ids']),'new_legacy_price_role_negative_accepted');$tests++;
  }
 }
 $x=$d['items'][0];$m=$x['owned_price_payload'];$r=$m['rows'][0];$meta=['price_quote_response_sha256'=>$m['quote_response_sha256'],'price_checked_epoch_utc'=>(string)$m['quote_epoch_utc']];
 $p=['version_id'=>1,'snapshot'=>['text'=>$r['new_text'],'metadata'=>$meta]];$proof=['version_id'=>1,'text_sha256'=>$r['new_text_sha256']];
 need(priorPriceSnapshot24Ok($p,$proof,$r['new_text'],$r['new_text_sha256'],$meta),'prior_price_snapshot_positive_failed');$tests++;
 foreach(['version','epoch','hash','caption'] as $case){$bad=$p;if($case==='version')$bad['version_id']=2;elseif($case==='epoch')$bad['snapshot']['metadata']['price_checked_epoch_utc']='0';elseif($case==='hash')$bad['snapshot']['metadata']['price_quote_response_sha256']=str_repeat('0',64);else $bad['snapshot']['text'].='foreign';need(!priorPriceSnapshot24Ok($bad,$proof,$r['new_text'],$r['new_text_sha256'],$meta),'prior_price_snapshot_negative_accepted');$tests++;}
 $text=$r['new_text'];$first=explode("\n",$text,2)[0];$n=(int)(strlen(mb_convert_encoding($first,'UTF-16LE','UTF-8'))/2);$mx=[['type'=>'strong','from'=>0,'length'=>$n]];$tg=[['type'=>'bold','offset'=>0,'length'=>$n]];
 need(nativeMarkup25Ok('max',$mx,$text)&&nativeMarkup25Ok('telegram',$tg,$text),'new_legacy_markup_positive_failed');$tests++;
 $bad=$mx;$bad[0]['length']=(int)(strlen(mb_convert_encoding($text,'UTF-16LE','UTF-8'))/2)+1;need(!nativeMarkup25Ok('max',$bad,$text),'new_legacy_markup_overrun_accepted');$tests++;
 need(sameMarkup25($mx,[array_reverse($mx[0],true)]),'new_legacy_markup_key_order_changed_semantics');$tests++;
 $bad=$mx;$bad[0]['length']--;need(!sameMarkup25($mx,$bad),'new_legacy_markup_changed_span_accepted');$tests++;
 need($tests===12*count($d['items'])+9,'new_legacy_case_count_changed');
 echo json_encode(['operation'=>$d['operation'],'new_native_input_cases'=>$tests,'old_fixtures'=>0,'provider_calls'=>0,'db_calls'=>0]),PHP_EOL;exit;
}
function sourceDir13(string $op,array $allowed):string{need(in_array($op,$allowed,true)&&preg_match('/\\A[a-z0-9-]{1,150}\\z/D',$op)===1,'unapproved_source_operation');$dir='/var/www/anytoour/data/channel-post-operations/'.$op;need(!is_link(dirname($dir))&&!is_link($dir)&&is_dir($dir)&&realpath($dir)===$dir,'canonical_operation_directory_changed');return $dir;}
function nativeMarkup25Ok(string $platform,array $marks,string $text):bool{
 if(!array_is_list($marks))return false;$utf=mb_convert_encoding($text,'UTF-16LE','UTF-8');$units=strlen($utf)/2;
 foreach($marks as $m){$k=$platform==='max'?'from':'offset';if(!is_array($m)||!is_string($m['type']??null)||!is_int($m[$k]??null)||!is_int($m['length']??null)||$m[$k]<0||$m['length']<1||$m[$k]+$m['length']>$units||!mb_check_encoding(substr($utf,2*$m[$k],2*$m['length']),'UTF-16LE'))return false;}return true;
}
function canonicalMarkup25($v){if(!is_array($v))return $v;if(!array_is_list($v))ksort($v,SORT_STRING);foreach($v as $k=>$x)$v[$k]=canonicalMarkup25($x);return $v;}
function sameMarkup25(array $a,array $b):bool{return canonicalMarkup25($a)===canonicalMarkup25($b);}
function atomicPublisher13(string $path):array{static $cache=[];if(isset($cache[$path]))return $cache[$path];need(!is_link($path)&&is_file($path),'original_publisher_receipt_missing');$n=filesize($path);need(is_int($n)&&$n>0&&$n<=1048576,'publisher_receipt_size');$raw=file_get_contents($path);need(is_string($raw)&&strlen($raw)===$n,'publisher_receipt_partial');$d=json_decode($raw,true,128,JSON_THROW_ON_ERROR);need(is_array($d),'publisher_atomic_json_invalid');$GLOBALS['context13_receipt_reads']=($GLOBALS['context13_receipt_reads']??0)+1;return $cache[$path]=$d;}
function copyJournal13(string $path):array{static $cache=[];if(isset($cache[$path]))return $cache[$path];need(!is_link($path)&&is_file($path),'copy_receipt_missing');$n=filesize($path);need(is_int($n)&&$n>0&&$n<=5242880,'copy_receipt_size');$raw=file_get_contents($path);need(is_string($raw)&&strlen($raw)===$n&&str_ends_with($raw,"\n"),'copy_journal_partial');$lines=explode("\n",rtrim($raw,"\n"));$d=json_decode(end($lines),true,128,JSON_THROW_ON_ERROR);need(is_array($d),'copy_journal_invalid');$GLOBALS['context13_receipt_reads']=($GLOBALS['context13_receipt_reads']??0)+1;return $cache[$path]=$d;}
function emptyStoredButton13(array $s):bool{foreach(['keyboard','reply_markup','buttons'] as $k)if(!empty($s[$k]))return false;return true;}
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



    require_once getcwd().'/services/HotelDatabase.php';need(HotelDatabase::configured(),'hotel_catalog_connection_not_configured');
    $catalog=HotelDatabase::connection();

 $d=contextInput18();$pdo=ConversationDb::connection();
 foreach(['033_channel_post_context.sql','036_channel_post_member_delivery.sql','040_channel_post_reconciliation.sql'] as $name){$q=$pdo->prepare('SELECT checksum FROM schema_migrations WHERE version=?');$q->execute([$name]);need($q->fetchColumn()===hash_file('sha256',getcwd().'/migrations/'.$name),'publishing_schema_not_accepted');}
 $shared=$catalog===$pdo;$catalog->exec('START TRANSACTION READ ONLY');if(!$shared)$pdo->exec('START TRANSACTION READ ONLY');$out['readonly_connection_mode']=$shared?'shared':'separate';
 $registry=new ChannelPostRegistryService($pdo);$targets=['max'=>['bot'=>'id9704048781_1_bot','channel'=>'-80492244209683','source'=>'max_anytour_msk1','id'=>9],'telegram'=>['bot'=>'anyru_bot','channel'=>'-1002262666616','source'=>'tg_anytour_msk','id'=>10]];$channels=[];
 foreach($targets as $platform=>$t){$bot=ChannelPostRuntime::identity($platform);need(is_array($bot)&&$bot['project_key']==='anytour'&&$bot['bot_key']===$t['bot'],'configured_bot_changed');$c=$registry->channel('anytour',$platform,$t['bot'],$t['channel']);need(is_array($c)&&(int)$c['id']===$t['id']&&$c['source_key']===$t['source']&&(int)$c['config_version']===2&&$c['button_text']==='✈️ Узнать стоимость тура','channel_configuration_changed');ob_start();try{$right=(new ChannelPostButtonTransport())->verifyChannel($c);}finally{ob_end_clean();}$out['provider_channel_rights_checks']++;need(($right['state']??'')==='ready','provider_channel_rights_changed');$channels[$platform]=$c;}
 $fold=static function(string $s):string{$s=preg_replace('/\\s*\\(.*?\\)/u','',$s)??$s;return trim(preg_replace('/[^\\p{L}\\p{N}]+/u',' ',mb_strtolower($s,'UTF-8'))??'');};$identity=$catalog->prepare('SELECT id,name,is_active,country_id FROM catalog_hotels WHERE id=? LIMIT 1');

 foreach($d['items'] as $x){$h=$x['hotel'];$id=(int)$h['local_tv_id'];try{
  $identity->execute([$id]);$native=$identity->fetch(PDO::FETCH_ASSOC);need(is_array($native)&&(int)$native['is_active']===1&&(int)$native['country_id']===(int)$h['expected_country_id']&&$fold($native['name'])===$fold($h['expected_name']),'current_native_country_active_name_changed');
  $m=$x['owned_price_payload'];$r=$m['rows'][0];$ownOp=$m['operation'];$journal=copyJournal13(sourceDir13($ownOp,$d['allowed_source_operations']).'/receipt.jsonl');
  need(($journal['operation']??'')===$ownOp&&($journal['complete']??false)===true&&hash_equals($x['source_manifest_hash'],(string)($journal['manifest_hash']??'')),'confirmed_own_price_operation_not_exact');
  $caption=$r['new_text'];$captionHash=$r['new_text_sha256'];$logical=$r['logical_id'];$publisher=$x['publisher_hotel_id'];$contexts=[];
  $priceMeta=['price_quote_response_sha256'=>$m['quote_response_sha256'],'price_checked_epoch_utc'=>(string)$m['quote_epoch_utc']];
  foreach($targets as $platform=>$t){
   $member=$journal['posts'][$platform.':'.$r['key']]??[];$proof=$member['receipt']??[];
   need(($member['state']??'')==='confirmed'&&($proof['logical_id']??'')===$logical&&($proof['platform']??'')===$platform&&($proof['text_sha256']??'')===$captionHash&&!empty($proof['body_photo_button_verified']),'current_own_price_confirmed_receipt_missing');
   $p=$registry->publication((int)($proof['publication_id']??0));need(is_array($p)&&$p['project_key']==='anytour'&&$p['platform']===$platform&&$p['bot_key']===$t['bot']&&$p['channel_id']===$t['channel']&&$p['external_message_id']===(string)($proof['message_id']??'')&&$p['source_key']===$t['source']&&$p['state']==='sent'&&empty($p['claim_token'])&&empty($p['claimed_at']),'current_publication_identity_or_outcome_not_accepted');
   $snap=$p['snapshot'];need(nativeMarkup25Ok($platform,(array)($snap['markup']??[]),$caption),'current_native_markup_invalid');$sig=photoSignature($snap);$button=ChannelPostButtonTransport::buttonUrl($p);
   need((int)$p['version_id']===(int)($proof['version_id']??0)&&($snap['text']??'')===$caption&&($proof['photo_signature']??'')===$sig&&$sig===($r['expected_existing_photo_signatures'][$platform]??null),'current_own_price_caption_version_photo_changed');
   need(priorPriceSnapshot24Ok($p,$proof,$caption,$captionHash,$priceMeta),'current_prior_price_check_metadata_changed');
   need(($proof['source_key']??'')===$t['source']&&($proof['post_url']??'')===($snap['post_url']??'')&&($proof['button_url']??'')===$button&&is_string($p['start_payload'])&&$p['start_payload']!==''&&hash('sha256',(string)($snap['text']??''))===$captionHash,'current_source_token_canonical_url_or_caption_changed');
   $storedOk=ChannelPostIncomingAdapter::containsOnlyButton($snap,$button,'✈️ Узнать стоимость тура');need($storedOk||emptyStoredButton13($snap),'nonempty_conflicting_keyboard_stop');
   $kind='CONFIRMED_PRIOR_PRICE_EXACT_MANIFEST_OWN_RECEIPT_CURRENT_VERSION_AND_PRICE_METADATA_STABLE_PROVIDER_PHOTO_SIGNATURE';
   if($platform==='max'){$out['provider_post_reads']++;$actual=ChannelPostIncomingAdapter::maxSnapshot(readMax($p['external_message_id']));need(is_array($actual)&&nativeMarkup25Ok('max',(array)($actual['markup']??[]),$caption)&&sameMarkup25((array)($actual['markup']??[]),(array)($snap['markup']??[]))&&($actual['text']??'')===$caption&&photoSignature($actual)===$sig&&($actual['post_url']??'')===($snap['post_url']??'')&&ChannelPostIncomingAdapter::containsOnlyButton($actual,$button,'✈️ Узнать стоимость тура'),'new_related_max_owned_current_body_photo_button_mismatch');
    $out['owned_max_context_proofs'][]=['native_hotel_id'=>$id,'publication_id'=>(int)$p['publication_id'],'version_id'=>(int)$p['version_id'],'message_id'=>$p['external_message_id'],'snapshot'=>$actual,'caption_sha256'=>$captionHash,'photo_signature'=>$sig,'button_url_sha256'=>hash('sha256',$button)];$kind.='_NEW_RELATED_OWNED_MAX_BODY_PHOTO_ONLYBUTTON_PROOF';
   }else $kind.='_EXACT_CURRENT_TG_CONFIRMED_TRANSPORT_PROOF';
   $contexts[$platform]=['publication_id'=>(int)$p['publication_id'],'message_id'=>$p['external_message_id'],'version_id'=>(int)$p['version_id'],'logical_id'=>$logical,'source_key'=>$p['source_key'],'start_payload_sha256'=>hash('sha256',$p['start_payload']),'button_url_sha256'=>hash('sha256',$button),'post_url'=>$snap['post_url'],'photo_signature'=>$sig,'old_text'=>$snap['text'],'old_text_sha256'=>$captionHash,'native_markup'=>$snap['markup']??[],'native_markup_sha256'=>hash('sha256',json_encode(canonicalMarkup25($snap['markup']??[]),JSON_THROW_ON_ERROR)),'canonical_proof_kind'=>$kind,'original_publication_hotel_id'=>$publisher,'original_photo_sha256'=>null,'prior_price_metadata'=>$priceMeta];
  }
  need(count($contexts)===2&&$contexts['max']['old_text']===$contexts['telegram']['old_text'],'two_platform_current_contexts_differ');
  $prior=['operation'=>$ownOp,'manifest_sha256'=>$x['owned_price_manifest_sha256'],'manifest_journal_hash'=>$x['source_manifest_hash'],'source_key'=>$r['key'],'current_whole_caption_sha256'=>$captionHash,'price_metadata'=>$priceMeta,'confirmed_own_receipt_and_current_version'=>true];
  $legacyRoles=json_decode(base64_decode(APPROVED_CONTEXT18_LEGACY_ROLES_B64,true),true,256,JSON_THROW_ON_ERROR);$prior['origin_context_sha256']=$r['legacy_context_sha256'];
  $binding=['native_hotel_id'=>$id,'expected_country_id'=>(int)$h['expected_country_id'],'catalog_name'=>$native['name'],'identity_provenance'=>$h['identity_provenance'],'publisher_hotel_id'=>$publisher,'key'=>'prior-priced-search-link-'.$id,'logical_id'=>$logical,'caption_sha256'=>$captionHash,'caption'=>$caption,'source_current_owned_price_operation'=>$ownOp,'contexts'=>$contexts,'original_role_proof_kind'=>$x['kind'],'legacy_origin_role_proof'=>$x['legacy_role_proof'],'legacy_price_role'=>$legacyRoles[(string)$id],'quote_state'=>'NOQUOTE','search_presets'=>$d['presets'],'prior_owned_price'=>$prior];
  $frame=['capture_operation'=>$out['operation'],'capture_run'=>APPROVED_CAPTURE_RUN,'capture_epoch_utc'=>time(),'capture_source_sha'=>EXPECTED_INSTALLED_SHA,'capture_input_sha256'=>APPROVED_CONTEXT18_INPUT_SHA256,'binding'=>$binding];$out['bindings'][]=['hotel'=>$h,'canonical_context'=>$frame];
 }catch(Throwable $e){$out['deferred'][]=['native_hotel_id'=>$id,'reason'=>preg_match('/\\A[a-z][a-z0-9_]{1,120}\\z/D',$e->getMessage())?$e->getMessage():'prior_price_current_canonical_context_not_admitted'];}}
 if(!$shared)$pdo->rollBack();$catalog->rollBack();$out['canonical_source_receipts_read']=$GLOBALS['context13_receipt_reads']??0;$out['captured_epoch_utc']=time();$out['installed_sha']=EXPECTED_INSTALLED_SHA;$out['input_sha256']=APPROVED_CONTEXT18_INPUT_SHA256;$out['deferred_before_server']=$d['deferred_before_server'];$out['quote_state']='NOQUOTE';$out['ok']=true;
}catch(Throwable $e){if(isset($pdo)&&$pdo instanceof PDO&&$pdo->inTransaction())$pdo->rollBack();if(isset($catalog)&&$catalog instanceof PDO&&$catalog->inTransaction())$catalog->rollBack();$out['error']=preg_match('/\\A[a-z][a-z0-9_]{1,120}\\z/D',$e->getMessage())?$e->getMessage():'noquote_current_context_read_failed';}
echo json_encode($out,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR),PHP_EOL;exit($out['ok']?0:2);
