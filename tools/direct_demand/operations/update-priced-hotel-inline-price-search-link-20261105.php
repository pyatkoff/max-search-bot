<?php
declare(strict_types=1);
// Fixed, read-only preflight for the two directly authorized publication targets.
ini_set('display_errors','0');ini_set('log_errors','0');
$out=['operation'=>APPROVED_UPDATE_OPERATION,'mode'=>'update_existing_hotel_search_link_explicit_noquote','ok'=>false,'post_write_attempts'=>0,'price_block_updates'=>0,'search_link_post_updates'=>0,'search_link_only_post_updates'=>0,'channels'=>[]];

function need(bool $v,string $reason):void{if(!$v)throw new RuntimeException($reason);}
function durable($f,array $s):void{need(fseek($f,0,SEEK_END)===0,'receipt_write_failed');$raw=json_encode($s,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n";need(fwrite($f,$raw)===strlen($raw)&&fflush($f),'receipt_write_failed');if(function_exists('fsync'))need(fsync($f),'receipt_sync_failed');}
function tg(string $method,array $body):array{
 need(in_array($method,['editMessageCaption','editMessageReplyMarkup'],true),'telegram_method_outside_update_scope');
 $ch=curl_init('https://api.telegram.org/bot'.TELEGRAM_BOT_TOKEN.'/'.$method);curl_setopt_array($ch,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>http_build_query($body),CURLOPT_RETURNTRANSFER=>true,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,CURLOPT_CONNECTTIMEOUT=>10,CURLOPT_TIMEOUT=>45]);$raw=curl_exec($ch);$http=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);curl_close($ch);$data=is_string($raw)?json_decode($raw,true):null;return ['http'=>$http,'data'=>is_array($data)?$data:[]];
}
function readMax(string $id):array{need(preg_match('/\A[A-Za-z0-9_.-]{1,191}\z/',$id)===1,'message_id_invalid');$r=ChannelPostApiTransport::buttonRequest('max','GET','/messages/'.$id,[],null);need(empty($r['transport_error'])&&(int)$r['http']===200&&is_array($r['data']),'max_read_failed');return $r['data'];}
function photoSignature(array $snapshot):string{$media=$snapshot['attachments']??[];need(count($media)===1&&($media[0]['type']??'')==='image','expected_one_photo');$m=$media[0];$id=(string)($m['photo_id']??$m['file_unique_id']??'');need($id!==''&&preg_match('/\A[A-Za-z0-9_-]+\z/',$id)===1,'photo_stable_id_missing');return (string)($m['provider']??'').':'.$id;}
function canonInline25($v){if(!is_array($v))return $v;if(!array_is_list($v))ksort($v,SORT_STRING);foreach($v as $k=>$x)$v[$k]=canonInline25($x);return $v;}
function sameInlineMarkup25(array $a,array $b):bool{
 $sort=static function(array $v):array{$v=array_map('canonInline25',$v);usort($v,static fn($x,$y)=>strcmp(json_encode($x),json_encode($y)));return $v;};return $sort($a)===$sort($b);
}
function inlineSpan25(string $text,array $m,string $platform,string $anchor):bool{
 $k=$platform==='max'?'from':'offset';if(!is_int($m[$k]??null)||!is_int($m['length']??null)||$m[$k]<0||$m['length']<1)return false;
 $utf=mb_convert_encoding($text,'UTF-16LE','UTF-8');$piece=substr($utf,$m[$k]*2,$m['length']*2);
 if(mb_check_encoding($piece,'UTF-16LE')&&mb_convert_encoding($piece,'UTF-8','UTF-16LE')===$anchor)return true;
 return $platform==='max'&&mb_substr($text,$m[$k],$m['length'],'UTF-8')===$anchor;
}
function inlineSnapshot25(string $platform,array $snap,array $r):bool{
 if(($snap['text']??null)!==$r['new_text'])return false;$marks=$snap['markup']??[];if(!is_array($marks))return false;
 if($platform==='telegram')return sameInlineMarkup25($marks,$r['telegram_new_markup']??[]);
 $rest=[];$found=0;foreach($marks as $m){if(($m['type']??'')==='link'&&($m['url']??'')===$r['search_link_url']){if(!inlineSpan25($snap['text'],$m,'max',$r['price_anchor_text']))return false;$found++;}else $rest[]=$m;}
 return $found===1&&sameInlineMarkup25($rest,$r['expected_old_markup']['max']);
}
function inlineMaxHtml25(array $r):string{
 $text=$r['new_text'];$anchor=$r['price_anchor_text'];$byte=(int)$r['price_anchor_byte_offset'];need(substr($text,$byte,strlen($anchor))===$anchor,'inline_anchor_bytes_changed');
 $old=$r['expected_old_markup']['max'];$first=explode("\n",$text,2)[0];$bold=false;
 if(count($old)===1){$m=$old[0];need(($m['type']??'')==='strong'&&($m['from']??-1)===0&&inlineSpan25($text,$m,'max',$first),'unsupported_existing_max_format');$bold=true;}else need($old===[],'unsupported_existing_max_entities');
 $esc=static fn($s)=>htmlspecialchars($s,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
 $prefix=substr($text,0,$byte);if($bold){need($byte>strlen($first),'inline_anchor_overlaps_heading');$prefix='<b>'.$esc($first).'</b>'.$esc(substr($prefix,strlen($first)));}else $prefix=$esc($prefix);
 return $prefix.'<a href="'.$esc($r['search_link_url']).'">'.$esc($anchor).'</a>'.$esc(substr($text,$byte+strlen($anchor)));
}
function snapshotMatches(array $p,array $snap,array $plan):bool{return inlineSnapshot25($plan['platform'],$snap,$plan['inline_row'])&&photoSignature($snap)===$plan['photo_signature']&&($snap['post_url']??'')===$plan['post_url']&&ChannelPostIncomingAdapter::containsOnlyButton($snap,ChannelPostButtonTransport::buttonUrl($p),'✈️ Узнать стоимость тура');}
function safeResult(array $p,array $plan):array{return ['logical_id'=>$plan['logical_id'],'platform'=>$p['platform'],'channel_id'=>$p['channel_id'],'post_url'=>$plan['post_url'],'message_id'=>$plan['message_id'],'publication_id'=>(int)$p['publication_id'],'version_id'=>(int)$p['version_id'],'previous_version_id'=>$plan['old_version_id'],'button_url'=>ChannelPostButtonTransport::buttonUrl($p),'button_text'=>'✈️ Узнать стоимость тура','text_sha256'=>hash('sha256',$plan['text']),'photo_signature'=>$plan['photo_signature'],'source_key'=>$p['source_key'],'content_updated'=>$plan['update_text'],'state'=>'sent','body_photo_button_verified'=>true,'existing_message_reused'=>true];}



function linkIdentityMap18Ok(array $map):bool{
 if(!array_is_list($map)||count($map)<1||count($map)>10)return false;$seen=[];
 foreach($map as $r){$id=$r['local_tv_id']??null;if(!is_int($id)||$id<1||isset($seen[$id]))return false;$seen[$id]=true;}return true;
}
function priceMetadata24Ok(array $snapshot,array $expected):bool{
 if(array_keys($expected)!==['price_quote_response_sha256','price_checked_epoch_utc']||preg_match('/\\A[0-9a-f]{64}\\z/D',(string)$expected['price_quote_response_sha256'])!==1||!is_string($expected['price_checked_epoch_utc'])||preg_match('/\\A[1-9][0-9]*\\z/D',$expected['price_checked_epoch_utc'])!==1)return false;
 foreach($expected as $k=>$v)if(($snapshot['metadata'][$k]??null)!==$v)return false;return true;
}
function linkPriorPrice24Ok(array $b):bool{
 $p=$b['prior_owned_price']??[];$meta=$p['price_metadata']??[];
 if(($p['confirmed_own_receipt_and_current_version']??null)!==true||($p['operation']??null)!==($b['source_current_owned_price_operation']??null)||preg_match('/\\A[a-z0-9-]{1,150}\\z/D',(string)($p['operation']??''))!==1||empty($p['source_key'])||($p['current_whole_caption_sha256']??null)!==($b['caption_sha256']??null)||hash('sha256',(string)($b['caption']??''))!==($b['caption_sha256']??null))return false;
 foreach(['manifest_sha256','manifest_journal_hash'] as $k)if(preg_match('/\\A[0-9a-f]{64}\\z/D',(string)($p[$k]??''))!==1)return false;
 if(!priceMetadata24Ok(['metadata'=>$meta],$meta))return false;
 foreach(['max','telegram'] as $platform)if(($b['contexts'][$platform]['prior_price_metadata']??null)!==$meta||($b['contexts'][$platform]['old_text_sha256']??null)!==($b['caption_sha256']??null)||($b['contexts'][$platform]['old_text']??null)!==($b['caption']??null))return false;
 return true;
}
function linkRole18Ok(array $b):bool{
 $id=(int)($b['native_hotel_id']??0);$map=json_decode(base64_decode(APPROVED_IDENTITY_BINDINGS_B64,true),true,256,JSON_THROW_ON_ERROR);$native=null;$seen=[];
 if(!is_array($map)||!linkIdentityMap18Ok($map))return false;foreach($map as $r){$key=$r['local_tv_id'];if($key===$id)$native=$r;}
 if(!is_array($native)||($b['quote_state']??'')!=='NOQUOTE'||isset($b['prior_owned_offer'])||($b['identity_provenance']??'')!==$native['identity_provenance']||(int)($b['expected_country_id']??0)!==(int)$native['expected_country_id'])return false;
 $publisher=$native['identity_provenance']==='accepted_typed_bridge'?(int)($native['anytour_id']??0):$id;
 return $publisher>0&&($b['publisher_hotel_id']??null)===$publisher&&($b['original_role_proof_kind']??'')==='current_confirmed_own_price_manifest_and_receipt'&&linkPriorPrice24Ok($b);
}
function linkFrame18Ok(array $f):bool{
 $b=$f['binding']??[];return ($f['capture_operation']??'')===APPROVED_LINK_CAPTURE_OPERATION&&($f['capture_run']??0)===APPROVED_LINK_CAPTURE_RUN&&($f['capture_source_sha']??'')===APPROVED_LINK_CAPTURE_SOURCE&&(int)($f['capture_epoch_utc']??0)>0&&linkRole18Ok($b)&&isset($b['contexts']['max'],$b['contexts']['telegram']);
}
function linkRow17Ok(array $r,array $f,int $native,string $sha):bool{
 $b=$f['binding']??[];if(!linkFrame18Ok($f))return false;
 return (int)($b['native_hotel_id']??0)===$native&&(int)($r['hotel_id']??0)===$native&&($r['key']??'')===($b['key']??'')&&($r['logical_id']??'')===($b['logical_id']??'')&&($r['old_text']??'')===($b['caption']??'')&&($r['old_text_sha256']??'')===($b['caption_sha256']??'')&&($r['legacy_context_sha256']??'')===$sha&&($r['original_publication_hotel_id']??null)===($b['publisher_hotel_id']??null)&&($r['expected_existing_photo_signatures']??null)===['max'=>$b['contexts']['max']['photo_signature'],'telegram'=>$b['contexts']['telegram']['photo_signature']]&&!array_key_exists('cover_sha256',$r)&&($r['expected_prior_price_metadata']??null)===($b['prior_owned_price']['price_metadata']??null);
}
function noQuote17Ok(array $p):bool{
 if(($p['quote_state']??'')!=='NOQUOTE'||($p['update_kind']??'')!=='search_link_only'||($p['price_total_verified']??null)!==false||($p['search_link_included']??null)!==true)return false;
 foreach(['quote_response_sha256','quote_epoch_utc','quote_index','quote_total_amount','quote_currency','quote_origin_operation'] as $k)if(array_key_exists($k,$p))return false;return true;
}
function linkAddition17Ok(array $r):bool{
 $f=json_decode(base64_decode(APPROVED_LEGACY_CONTEXT_B64,true),true,128,JSON_THROW_ON_ERROR);$base=$f['binding']['caption'];
 if(($r['old_text']??null)!==$base||($r['new_text']??null)!==$base||($r['added_text']??null)!==''||($r['search_link_included']??null)!==true)return false;
 foreach(['max','telegram'] as $p)if(!sameInlineMarkup25($r['expected_old_markup'][$p]??[],$f['binding']['contexts'][$p]['native_markup']??[]))return false;
 $anchor=$r['price_anchor_text']??'';$byte=$r['price_anchor_byte_offset']??null;if(!is_int($byte)||$byte<0||!is_string($anchor)||$anchor===''||substr($base,$byte,strlen($anchor))!==$anchor)return false;
 if(preg_match('/\A[0-9][0-9 \x{00A0}\x{202F}]*\s*₽\z/u',$anchor)!==1)return false;
 $before=substr($base,0,$byte);$offset=(int)(strlen(mb_convert_encoding($before,'UTF-16LE','UTF-8'))/2);$length=(int)(strlen(mb_convert_encoding($anchor,'UTF-16LE','UTF-8'))/2);
 $expected=$r['expected_old_markup']['telegram'];$expected[]=['type'=>'text_link','offset'=>$offset,'length'=>$length,'url'=>$r['search_link_url']];
 return sameInlineMarkup25($r['telegram_new_markup']??[],$expected)&&inlineSnapshot25('telegram',['text'=>$base,'markup'=>$r['telegram_new_markup']],$r)&&inlineMaxHtml25($r)!=='';
}
function linkUrl17Ok(array $p,array $r,array $f):bool{
 $url=(string)($r['search_link_url']??'');if($url!==base64_decode(APPROVED_SEARCH_URL_B64,true))return false;$u=parse_url($url);
 if(!is_array($u)||($u['scheme']??'')!=='https'||($u['host']??'')!=='anytour.online'||($u['path']??'')!=='/poisk-turov/'||isset($u['fragment'])||isset($u['user'])||isset($u['pass'])||isset($u['port']))return false;
 parse_str((string)($u['query']??''),$q);$b=$f['binding'];$filter=$p['search_filters']??[];$preset=$b['search_presets']??[];
 $expected=['days_from'=>(string)($preset['nights_from']??''),'days_till'=>(string)($preset['nights_to']??''),'from'=>'1','count_people'=>'2','country'=>(string)$b['expected_country_id'],'hotel'=>(string)$b['native_hotel_id'],'date_from'=>(string)($preset['departure_date_from']??''),'date_till'=>(string)($preset['departure_date_to']??'')];
 return $q===$expected&&$filter===$expected&&(int)$q['hotel']===APPROVED_UPDATE_HOTEL_ID&&in_array((int)$q['country'],[1,4],true)&&(int)$q['days_from']===5&&(int)$q['days_till']===14&&$q['date_from']>gmdate('Y-m-d')&&$q['date_till']>=$q['date_from']&&$q['date_till']<='2027-02-02';
}


function linkUpdateOperationOk(string $op,int $native):bool{
 return $native===APPROVED_UPDATE_HOTEL_ID&&$native>0&&$op===APPROVED_LINK_UPDATE_PREFIX.$native.APPROVED_LINK_UPDATE_SUFFIX&&preg_match('/\\A[a-z0-9-]{1,150}\\z/D',$op)===1;
}
if(getenv('ANYTOUR_INLINE_PRICE_LINK_NEW_CASES')==='1'){
 $p=json_decode(base64_decode(MANIFEST_BASE64,true),true,128,JSON_THROW_ON_ERROR);$f=json_decode(base64_decode(APPROVED_LEGACY_CONTEXT_B64,true),true,128,JSON_THROW_ON_ERROR);$r=$p['rows'][0];
 need(linkUpdateOperationOk($p['operation'],APPROVED_UPDATE_HOTEL_ID)&&noQuote17Ok($p)&&linkFrame18Ok($f)&&linkRow17Ok($r,$f,APPROVED_UPDATE_HOTEL_ID,APPROVED_LEGACY_CONTEXT_SHA256)&&linkAddition17Ok($r)&&linkUrl17Ok($p,$r,$f),'inline_actual_input_positive_failed');
 $bad=$r;$bad['new_text'].='foreign';need(!linkAddition17Ok($bad),'inline_body_mutation_accepted');
 $bad=$r;$bad['telegram_new_markup'][count($bad['telegram_new_markup'])-1]['url'].='&foreign=1';need(!linkAddition17Ok($bad),'inline_wrong_tg_url_accepted');
 $bad=$r;$bad['price_anchor_byte_offset']++;need(!linkAddition17Ok($bad),'inline_wrong_anchor_offset_accepted');
 $bad=$r;$bad['expected_old_markup']['max'][]=['type'=>'strong','from'=>0,'length'=>1];need(!linkAddition17Ok($bad),'inline_owner_markup_mutation_accepted');
 $bad=$r;$bad['expected_prior_price_metadata']['price_checked_epoch_utc']='0';need(!linkRow17Ok($bad,$f,APPROVED_UPDATE_HOTEL_ID,APPROVED_LEGACY_CONTEXT_SHA256),'inline_price_epoch_changed');
 $bad=$p;$bad['quote_epoch_utc']=1;need(!noQuote17Ok($bad),'inline_fake_new_quote_accepted');
 $snap=['text'=>$r['new_text'],'markup'=>$r['telegram_new_markup']];need(inlineSnapshot25('telegram',$snap,$r),'inline_native_tg_positive_failed');$snap['markup']=[];need(!inlineSnapshot25('telegram',$snap,$r),'inline_missing_tg_anchor_accepted');
 $mx=$r['expected_old_markup']['max'];$new=end($r['telegram_new_markup']);$mx[]=['type'=>'link','from'=>$new['offset'],'length'=>$new['length'],'url'=>$r['search_link_url']];need(inlineSnapshot25('max',['text'=>$r['new_text'],'markup'=>$mx],$r),'inline_native_max_positive_failed');
 $mx[count($mx)-1]['from']=0;need(!inlineSnapshot25('max',['text'=>$r['new_text'],'markup'=>$mx],$r),'inline_max_wrong_span_accepted');
 echo "INLINE_PRICE_LINK_NEW_NATIVE_CASES_OK new_cases=9 old_fixtures0 db0 provider0\n";exit;
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


    $legacyRaw=base64_decode(APPROVED_LEGACY_CONTEXT_B64,true);need(is_string($legacyRaw)&&hash_equals(APPROVED_LEGACY_CONTEXT_SHA256,hash('sha256',$legacyRaw)),'approved_legacy_context_bytes_changed');$legacyFrame=json_decode($legacyRaw,true,128,JSON_THROW_ON_ERROR);need(linkFrame18Ok($legacyFrame),'approved_legacy_context_roles_invalid');
    $payload=json_decode(base64_decode(MANIFEST_BASE64,true),true,64,JSON_THROW_ON_ERROR);need(($payload['operation']??'')===APPROVED_UPDATE_OPERATION&&count($payload['rows']??[])===1,'manifest_scope_changed');$rows=$payload['rows'];
    foreach($rows as $r)need($r['key']===base64_decode(APPROVED_UPDATE_KEY_B64,true)&&(int)$r['hotel_id']===APPROVED_UPDATE_HOTEL_ID&&$r['logical_id']===base64_decode(APPROVED_UPDATE_LOGICAL_B64,true)&&hash('sha256',$r['old_text'])===$r['old_text_sha256']&&hash('sha256',$r['new_text'])===$r['new_text_sha256']&&linkAddition17Ok($r)&&is_string($r['new_text'])&&strlen(mb_convert_encoding($r['new_text'],'UTF-16LE','UTF-8'))/2<=1024,'prepared_text_invalid');
    need(defined('APPROVED_MANIFEST_SHA256')&&hash('sha256',base64_decode(MANIFEST_BASE64,true))===APPROVED_MANIFEST_SHA256,'approved_manifest_bytes_changed');
    need(noQuote17Ok($payload),'noquote_payload_claimed_quote_or_price');
    need(time()>=(int)$legacyFrame['capture_epoch_utc']&&time()-(int)$legacyFrame['capture_epoch_utc']<=7200,'current_link_context_too_old_for_edit');
    foreach($rows as $r)need(linkRow17Ok($r,$legacyFrame,APPROVED_UPDATE_HOTEL_ID,APPROVED_LEGACY_CONTEXT_SHA256)&&linkUrl17Ok($payload,$r,$legacyFrame),'noquote_current_native_context_or_link_changed');
    $hasPrice=false;
    require_once getcwd().'/services/HotelDatabase.php';need(HotelDatabase::configured(),'hotel_catalog_connection_not_configured');
    $map=json_decode(base64_decode(APPROVED_IDENTITY_BINDINGS_B64,true),true,256,JSON_THROW_ON_ERROR);$nativeExpected=null;foreach($map as $v)if((int)$v['local_tv_id']===APPROVED_UPDATE_HOTEL_ID){$nativeExpected=$v;break;}need(is_array($nativeExpected),'noquote_native_expected_identity_missing');
    $identity=HotelDatabase::connection()->prepare('SELECT id,name,is_active,country_id FROM catalog_hotels WHERE id=? LIMIT 1');$identity->execute([APPROVED_UPDATE_HOTEL_ID]);$currentNative=$identity->fetch(PDO::FETCH_ASSOC);
    $fold=static function(string $v):string{$v=preg_replace('/\\s*\\(.*?\\)/u','',$v)??$v;return trim(preg_replace('/[^\\p{L}\\p{N}]+/u',' ',mb_strtolower($v,'UTF-8'))??'');};
    need(is_array($currentNative)&&(int)$currentNative['is_active']===1&&(int)$currentNative['country_id']===(int)$nativeExpected['expected_country_id']&&$fold($currentNative['name'])===$fold($nativeExpected['expected_name']),'noquote_current_native_country_active_name_changed');
    $out['update_kind']=$payload['update_kind'];
    $pdo=ConversationDb::connection();
    foreach(['033_channel_post_context.sql','036_channel_post_member_delivery.sql','040_channel_post_reconciliation.sql'] as $m){$q=$pdo->prepare('SELECT checksum FROM schema_migrations WHERE version=?');$q->execute([$m]);need($q->fetchColumn()===hash_file('sha256',getcwd().'/migrations/'.$m),'publishing_schema_not_accepted');}
    need(linkUpdateOperationOk(APPROVED_UPDATE_OPERATION,APPROVED_UPDATE_HOTEL_ID),'invalid_exact_update_operation');$dir='/var/www/anytoour/data/channel-post-operations/'.APPROVED_UPDATE_OPERATION;need(!is_link(dirname($dir))&&!is_link($dir),'invalid_operation_directory');if(!is_dir($dir))need(mkdir($dir,0700,true),'invalid_operation_directory');need(realpath($dir)===$dir,'invalid_operation_directory');
    $receiptPath=$dir.'/receipt.jsonl';need(!is_link($receiptPath),'invalid_receipt_path');$receiptExisted=is_file($receiptPath);$lock=fopen($receiptPath,'c+');need($lock!==false&&flock($lock,LOCK_EX|LOCK_NB),'operation_busy');$journal=stream_get_contents($lock);need(is_string($journal)&&(!$receiptExisted||$journal!==''),'empty_existing_receipt_unresolved');need($journal===''||str_ends_with($journal,"\n"),'partial_receipt_unresolved');$lines=$journal===''?[]:explode("\n",rtrim($journal,"\n"));$raw=$lines?end($lines):'';$manifestHash=hash('sha256',json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR));
    $state=$raw===''?['operation'=>$payload['operation'],'manifest_hash'=>$manifestHash,'configs'=>[],'posts'=>[]]:json_decode($raw,true,64,JSON_THROW_ON_ERROR);need(($state['operation']??'')===$payload['operation']&&($state['manifest_hash']??'')===$manifestHash,'operation_scope_changed');durable($lock,$state);
    foreach($state['configs'] as $r)need(($r['state']??'')==='confirmed','prior_configuration_outcome_unresolved');foreach($state['posts'] as $r)need(($r['state']??'')==='confirmed','prior_edit_outcome_unresolved');
    $registry=new ChannelPostRegistryService($pdo);$automation=new ChannelPostAutomationService($registry);
    $targets=['max'=>['bot'=>'id9704048781_1_bot','channel'=>'-80492244209683','source'=>'max_anytour_msk1','id'=>9],'telegram'=>['bot'=>'anyru_bot','channel'=>'-1002262666616','source'=>'tg_anytour_msk','id'=>10]];$channels=[];$plans=[];
    // Resolve the two existing messages and exact retained photos before any provider/configuration write.
    foreach($targets as $platform=>$t){
        $identity=ChannelPostRuntime::identity($platform);need(is_array($identity)&&$identity['project_key']==='anytour'&&$identity['bot_key']===$t['bot'],'configured_bot_changed');
        $channel=$registry->channel('anytour',$platform,$t['bot'],$t['channel']);need(is_array($channel)&&(int)$channel['id']===$t['id']&&$channel['source_key']===$t['source']&&$channel['button_text']===$payload['button_text']&&(int)$channel['config_version']===2,'channel_configuration_changed');
        need(((new ChannelPostButtonTransport())->verifyChannel($channel)['state']??'')==='ready','provider_channel_rights_changed');$channels[$platform]=$channel;
        foreach($rows as $r){
            $proof=$legacyFrame['binding']['contexts'][$platform]??null;need(is_array($proof)&&$proof['logical_id']===$r['logical_id']&&$proof['old_text_sha256']===$r['old_text_sha256']&&$proof['old_text']===$r['old_text'],'captured_legacy_platform_context_mismatch');
            $id=(int)$proof['publication_id'];$mid=(string)$proof['message_id'];
            $p=$registry->publication($id);need(is_array($p)&&$p['project_key']==='anytour'&&$p['platform']===$platform&&$p['bot_key']===$t['bot']&&$p['channel_id']===$t['channel']&&$p['external_message_id']===$mid&&$p['source_key']===$t['source']&&$p['state']==='sent','existing_publication_identity_changed');
            $key=$platform.':'.$r['key'];$done=$state['posts'][$key]??null;
            need(photoSignature($p['snapshot'])===$proof['photo_signature']&&hash('sha256',$p['start_payload'])===$proof['start_payload_sha256']&&hash('sha256',ChannelPostButtonTransport::buttonUrl($p))===$proof['button_url_sha256']&&($p['snapshot']['post_url']??'')===$proof['post_url'],'captured_legacy_photo_token_button_url_changed');
            if(!$done)need((int)$p['version_id']===(int)$proof['version_id'],'captured_legacy_version_changed');
            need(($p['snapshot']['text']??'')===($done?$r['new_text']:$r['old_text']),'existing_caption_changed');
            $snap=$p['snapshot'];need(priceMetadata24Ok($snap,$r['expected_prior_price_metadata']),'current_prior_price_metadata_changed_before_link');need($done?inlineSnapshot25($platform,$snap,$r):sameInlineMarkup25($snap['markup']??[],$r['expected_old_markup'][$platform]),'existing_native_markup_changed');
            if($platform==='max'){$actual=ChannelPostIncomingAdapter::maxSnapshot(readMax($mid));need(is_array($actual)&&sameInlineMarkup25($actual['markup']??[],$snap['markup']??[])&&($actual['text']??'')===$snap['text']&&photoSignature($actual)===photoSignature($snap)&&($actual['post_url']??'')===($snap['post_url']??'')&&ChannelPostIncomingAdapter::containsOnlyButton($actual,ChannelPostButtonTransport::buttonUrl($p),'✈️ Узнать стоимость тура'),'existing_max_readback_changed');}
            $plans[$key]=['platform'=>$platform,'key'=>$r['key'],'logical_id'=>$r['logical_id'],'message_id'=>$mid,'publication_id'=>$id,'old_version_id'=>(int)$p['version_id'],'token'=>$p['start_payload'],'post_url'=>$snap['post_url'],'photo_signature'=>photoSignature($snap),'text'=>$r['new_text'],'update_text'=>$r['update_text'],'inline_row'=>$r];
        }
    }
    $out['preflight_existing_messages']=count($plans);$out['configuration_write_attempts']=0;$out['content_edit_attempts']=0;$out['button_only_edit_attempts']=0;$out['posts']=[];
    foreach($plans as $key=>$plan){
        $out['current']=['platform'=>$plan['platform'],'logical_id'=>$plan['logical_id']];if(isset($state['posts'][$key])){$out['posts'][]=$state['posts'][$key]['receipt'];continue;}
        $platform=$plan['platform'];$t=$targets[$platform];$p=$registry->publication($plan['publication_id']);$c=$channels[$platform];need(is_array($p)&&(int)$p['version_id']===$plan['old_version_id']&&$p['state']==='sent'&&(int)$p['channel']['config_version']===2&&$p['channel']['button_text']===$payload['button_text']&&hash_equals((string)$p['start_payload'],(string)$plan['token']),'target_changed_before_edit');
        $state['posts'][$key]=['state'=>'edit_started','plan'=>$plan,'edit_request_start_ms'=>(int)floor(microtime(true)*1000)];durable($lock,$state);$requestStart=$state['posts'][$key]['edit_request_start_ms'];
        if($platform==='telegram'){
            $body=['chat_id'=>$t['channel'],'message_id'=>$plan['message_id'],'reply_markup'=>json_encode(['inline_keyboard'=>[[['text'=>$payload['button_text'],'url'=>ChannelPostButtonTransport::buttonUrl($p)]]]],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)];
            if($plan['update_text']){$method='editMessageCaption';$body['caption']=$plan['text'];$body['caption_entities']=json_encode($plan['inline_row']['telegram_new_markup'],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);$out['content_edit_attempts']++;$out['post_write_attempts']++;}else{$method='editMessageReplyMarkup';$out['button_only_edit_attempts']++;}
            $response=tg($method,$body);$state['posts'][$key]['provider_response']=$response;$state['posts'][$key]['state']='provider_response_recorded';durable($lock,$state);
            need($response['http']===200&&($response['data']['ok']??false)===true&&is_array($response['data']['result']??null),'telegram_edit_not_confirmed_no_retry');$message=$response['data']['result'];
            need((string)($message['chat']['id']??'')===$t['channel']&&(string)($message['message_id']??'')===$plan['message_id']&&($message['chat']['username']??'')==='any_toour','telegram_response_identity_changed');
            $snap=ChannelPostIncomingAdapter::telegramSnapshot($message);need(is_array($snap)&&snapshotMatches($p,$snap,$plan),'telegram_body_photo_button_mismatch');
            $snap['content_hash']=ChannelPostIncomingAdapter::contentFingerprint($snap,'',(string)$c['bot_url']);
            $snap['metadata']=array_replace($p['snapshot']['metadata']??[],$snap['metadata']??[]);
            $snap['metadata']['source_event_id']=$payload['operation'].':telegram:'.$plan['message_id'];
            $snap['metadata']['observation_kind']='authorized_admin_content_edit';
            $snap['metadata']['search_link_quote_state']='NOQUOTE';$snap['metadata']['search_link_url_sha256']=hash('sha256',$r['search_link_url']);$snap['metadata']['search_link_operation']=$payload['operation'];
            $p=$registry->registerPublication('anytour','telegram',$t['bot'],$t['channel'],$plan['message_id'],$snap);
            need(is_array($p)&&(int)$p['publication_id']===$plan['publication_id'],'telegram_registry_receive_failed');
            if($p['state']!=='sent'){$button=$automation->processPublication('anytour','telegram',$t['bot'],(int)$p['publication_id'],(int)$p['version_id']);$state['posts'][$key]['button_result']=$button;durable($lock,$state);need(($button['state']??'')==='sent','telegram_button_outcome_not_confirmed');}
            $p=$registry->publication($plan['publication_id']);need(is_array($p)&&$p['state']==='sent','telegram_registry_not_confirmed');
        }else{
            if($plan['update_text']){
                $html=inlineMaxHtml25($plan['inline_row']);$out['content_edit_attempts']++;$out['post_write_attempts']++;
                // No attachments field: exact provider photo and existing keyboard are retained.
                $response=MaxTransport::request('https://platform-api2.max.ru',MAX_SEARCH_TOKEN,'PUT','/messages',['message_id'=>$plan['message_id']],['text'=>$html,'format'=>'html'],null);
                $state['posts'][$key]['provider_error_private']=MaxTransport::lastError();$state['posts'][$key]['provider_response_private']=$response;$state['posts'][$key]['provider_edit_returned']=is_array($response)||$response===true;$state['posts'][$key]['state']='text_edit_returned';durable($lock,$state);
                $message=readMax($plan['message_id']);$snap=ChannelPostIncomingAdapter::maxSnapshot($message);need(is_array($snap)&&inlineSnapshot25('max',$snap,$plan['inline_row'])&&photoSignature($snap)===$plan['photo_signature']&&($snap['post_url']??'')===$plan['post_url'],'max_content_edit_readback_mismatch');
                $snap['content_hash']=ChannelPostIncomingAdapter::contentFingerprint($snap,'',(string)$c['bot_url']);$snap['metadata']=array_replace($p['snapshot']['metadata']??[],$snap['metadata']??[]);$snap['metadata']['search_link_quote_state']='NOQUOTE';$snap['metadata']['search_link_url_sha256']=hash('sha256',$r['search_link_url']);$snap['metadata']['search_link_operation']=$payload['operation'];$snap['metadata']['source_event_id']=$payload['operation'].':'.$key;$snap['metadata']['observation_kind']='authorized_admin_content_edit';$snap['metadata']['operation_observed_at_ms']=(string)(int)floor(microtime(true)*1000);$snap['metadata']['source_revision_ms']=(string)$requestStart;$snap['metadata']['revision_clock_origin']='authorized_edit_request_start';
                $p=$registry->registerPublication('anytour','max',$t['bot'],$t['channel'],$plan['message_id'],$snap);
            }else{
                $message=readMax($plan['message_id']);$p=$registry->registerProviderObservation('anytour',$t['bot'],$c,$message,1);$out['button_only_edit_attempts']++;
            }
            need(is_array($p)&&(int)$p['publication_id']===$plan['publication_id']&&($p['snapshot']['text']??'')===$plan['text'],'max_registry_version_not_current');
            if($p['state']!=='sent'){$button=$automation->processPublication('anytour','max',$t['bot'],(int)$p['publication_id'],(int)$p['version_id']);$state['posts'][$key]['button_result']=$button;durable($lock,$state);need(($button['state']??'')==='sent','max_button_outcome_not_confirmed');}
            $p=$registry->publication($plan['publication_id']);$snap=ChannelPostIncomingAdapter::maxSnapshot(readMax($plan['message_id']));need(is_array($snap)&&snapshotMatches($p,$snap,$plan),'max_final_photo_body_button_mismatch');
        }
        need($p['state']==='sent'&&$p['platform']===$platform&&hash_equals((string)$p['start_payload'],(string)$plan['token'])&&($p['snapshot']['text']??'')===$plan['text']&&photoSignature($p['snapshot'])===$plan['photo_signature']&&($p['snapshot']['post_url']??'')===$plan['post_url'],'final_registry_context_mismatch');
        need(priceMetadata24Ok($p['snapshot'],$r['expected_prior_price_metadata']),'prior_price_metadata_changed_after_link');
        $resolved=ChannelPostEntryService::resolve($platform,$plan['token'],$registry);need(is_array($resolved)&&(int)$resolved['version_id']===(int)$p['version_id']&&($resolved['snapshot']['text']??'')===$plan['text']&&$resolved['source_key']===$t['source'],'existing_token_new_version_resolution_failed');
        $receipt=safeResult($p,$plan);$state['posts'][$key]['state']='confirmed';$state['posts'][$key]['receipt']=$receipt;durable($lock,$state);$out['posts'][]=$receipt;if($hasPrice)$out['price_block_updates']++;if($payload['search_link_included'])$out['search_link_post_updates']++;if($payload['update_kind']==='search_link_only')$out['search_link_only_post_updates']++;
        echo json_encode(['progress'=>'existing_post_updated','platform'=>$platform,'logical_id'=>$plan['logical_id'],'post_url'=>$plan['post_url'],'content_updated'=>$plan['update_text']],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),PHP_EOL;flush();usleep(3200000);
    }
    $out['ok']=true;$out['new_posts_created']=0;$out['commercial_text_updates']=count(array_filter($out['posts'],static fn($p)=>$p['content_updated']));$out['price_block_updates']=$hasPrice?$out['commercial_text_updates']:0;$out['search_link_post_updates']=$payload['search_link_included']?$out['commercial_text_updates']:0;$out['search_link_only_post_updates']=$payload['update_kind']==='search_link_only'?$out['commercial_text_updates']:0;$out['button_only_updates']=count($out['posts'])-$out['commercial_text_updates'];$out['installed_sha']=EXPECTED_INSTALLED_SHA;unset($out['current']);$state['complete']=true;durable($lock,$state);
} catch(Throwable $e){$out['error']=preg_match('/\A[a-z][a-z0-9_]{1,100}\z/',$e->getMessage())?$e->getMessage():'bounded_existing_post_update_failed';}
if(isset($lock)&&is_resource($lock)){flock($lock,LOCK_UN);fclose($lock);}
echo json_encode($out,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR),PHP_EOL;exit($out['ok']?0:2);
