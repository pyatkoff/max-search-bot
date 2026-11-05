<?php
declare(strict_types=1);
// Fixed, read-only preflight for the two directly authorized publication targets.
ini_set('display_errors','0');ini_set('log_errors','0');
$out=['operation'=>APPROVED_UPDATE_OPERATION,'mode'=>'update_existing_hotel_offer_content','ok'=>false,'post_write_attempts'=>0,'price_block_updates'=>0,'search_link_post_updates'=>0,'search_link_only_post_updates'=>0,'channels'=>[]];

function need(bool $v,string $reason):void{if(!$v)throw new RuntimeException($reason);}
function durable($f,array $s):void{need(fseek($f,0,SEEK_END)===0,'receipt_write_failed');$raw=json_encode($s,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n";need(fwrite($f,$raw)===strlen($raw)&&fflush($f),'receipt_write_failed');if(function_exists('fsync'))need(fsync($f),'receipt_sync_failed');}
function tg(string $method,array $body):array{
 need(in_array($method,['editMessageCaption','editMessageReplyMarkup'],true),'telegram_method_outside_update_scope');
 $ch=curl_init('https://api.telegram.org/bot'.TELEGRAM_BOT_TOKEN.'/'.$method);curl_setopt_array($ch,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>http_build_query($body),CURLOPT_RETURNTRANSFER=>true,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,CURLOPT_CONNECTTIMEOUT=>10,CURLOPT_TIMEOUT=>45]);$raw=curl_exec($ch);$http=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);curl_close($ch);$data=is_string($raw)?json_decode($raw,true):null;return ['http'=>$http,'data'=>is_array($data)?$data:[]];
}
function readMax(string $id):array{need(preg_match('/\A[A-Za-z0-9_.-]{1,191}\z/',$id)===1,'message_id_invalid');$r=ChannelPostApiTransport::buttonRequest('max','GET','/messages/'.$id,[],null);need(empty($r['transport_error'])&&(int)$r['http']===200&&is_array($r['data']),'max_read_failed');return $r['data'];}
function photoSignature(array $snapshot):string{$media=$snapshot['attachments']??[];need(count($media)===1&&($media[0]['type']??'')==='image','expected_one_photo');$m=$media[0];$id=(string)($m['photo_id']??$m['file_unique_id']??'');need($id!==''&&preg_match('/\A[A-Za-z0-9_-]+\z/',$id)===1,'photo_stable_id_missing');return (string)($m['provider']??'').':'.$id;}
function snapshotMatches(array $p,array $snap,array $plan):bool{return ($snap['text']??'')===$plan['text']&&photoSignature($snap)===$plan['photo_signature']&&($snap['post_url']??'')===$plan['post_url']&&ChannelPostIncomingAdapter::containsOnlyButton($snap,ChannelPostButtonTransport::buttonUrl($p),'✈️ Узнать стоимость тура');}
function safeResult(array $p,array $plan):array{return ['logical_id'=>$plan['logical_id'],'platform'=>$p['platform'],'channel_id'=>$p['channel_id'],'post_url'=>$plan['post_url'],'message_id'=>$plan['message_id'],'publication_id'=>(int)$p['publication_id'],'version_id'=>(int)$p['version_id'],'previous_version_id'=>$plan['old_version_id'],'button_url'=>ChannelPostButtonTransport::buttonUrl($p),'button_text'=>'✈️ Узнать стоимость тура','text_sha256'=>hash('sha256',$plan['text']),'photo_signature'=>$plan['photo_signature'],'source_key'=>$p['source_key'],'content_updated'=>$plan['update_text'],'state'=>'sent','body_photo_button_verified'=>true,'existing_message_reused'=>true];}

function legacyPriceRoleOk(array $b):bool{
 $id=(int)($b['native_hotel_id']??0);if(!in_array($id,[157359,1291,1582,27316,3405,1422,78251],true))return false;
 $map=json_decode(base64_decode(APPROVED_IDENTITY_BINDINGS_B64,true),true,256,JSON_THROW_ON_ERROR);$native=null;foreach($map as $r)if((int)$r['local_tv_id']===$id){$native=$r;break;}if(!is_array($native)||($b['identity_provenance']??'')!==$native['identity_provenance']||(int)($b['expected_country_id']??0)!==(int)$native['expected_country_id'])return false;
 $publisher=$native['identity_provenance']==='accepted_typed_bridge'?(int)($native['anytour_id']??0):$id;if($publisher<1||($b['publisher_hotel_id']??null)!==$publisher)return false;
 return in_array($id,[1582,27316,3405],true)?($b['original_role_proof_kind']??'')==='current_confirmed_own_offer_manifest_and_receipt'&&isset($b['prior_owned_offer']):($b['original_role_proof_kind']??'')==='original_publisher_and_latest_confirmed_caption'&&!isset($b['prior_owned_offer']);
}
function legacyPriceFrameOk(array $f):bool{
 $b=$f['binding']??[];return ($f['capture_operation']??'')==='commercial-price-monitor-20261105-context16-new674-seven'&&($f['capture_run']??0)===41996008763&&($f['capture_source_sha']??'')==='5ca5abcec260c41e73884e08f0f45c0617c21785'&&(int)($f['capture_epoch_utc']??0)>0&&legacyPriceRoleOk($b)&&isset($b['contexts']['max'],$b['contexts']['telegram']);
}
function legacyPriceRowOk(array $r,array $f,int $native,string $sha):bool{
 $b=$f['binding']??[];if(!legacyPriceFrameOk($f))return false;
 return (int)($b['native_hotel_id']??0)===$native&&(int)($r['hotel_id']??0)===$native&&($r['key']??'')===($b['key']??'')&&($r['logical_id']??'')===($b['logical_id']??'')&&($r['old_text']??'')===($b['caption']??'')&&($r['old_text_sha256']??'')===($b['caption_sha256']??'')&&($r['legacy_context_sha256']??'')===$sha&&($r['original_publication_hotel_id']??null)===($b['publisher_hotel_id']??null)&&($r['expected_existing_photo_signatures']??null)===['max'=>$b['contexts']['max']['photo_signature'],'telegram'=>$b['contexts']['telegram']['photo_signature']]&&!array_key_exists('cover_sha256',$r);
}
function renderOffer16(string $base,string $added):string|false{
 $body=rtrim($base,"\r\n");$trail=substr($base,strlen($body));$pos=strrpos($body,"\n");if($pos===false||$added==='')return false;return substr($body,0,$pos)."\n\n".$added."\n\n".substr($body,$pos+1).$trail;
}
function offerBase16(array $r,array $f):string|false{
 $b=$f['binding']??[];if(($r['old_text']??'')!==($b['caption']??''))return false;$own=$b['prior_owned_offer']??null;
 if(!is_array($own))return ($r['render_base_text']??null)===$r['old_text']&&($r['prior_owned_offer_operation']??null)===null&&($r['replaced_owned_offer_text']??null)===null?$r['old_text']:false;
 $id=(int)($b['native_hotel_id']??0);$base=$own['original_hotel_caption']??null;$oldAdded=$own['added_text']??null;
 if(!in_array($id,[1582,27316,3405],true)||($own['operation']??'')!=='commercial-hotel-offer-update-20261105-manual1-h'.$id.'-v1'||($own['update_kind']??'')!=='search_link_only'||!is_string($base)||!is_string($oldAdded)||hash('sha256',$base)!==($own['original_hotel_caption_sha256']??'')||hash('sha256',$oldAdded)!==($own['added_text_sha256']??'')||hash('sha256',$r['old_text'])!==($own['current_whole_caption_sha256']??'')||renderOffer16($base,$oldAdded)!==$r['old_text'])return false;
 if(($r['render_base_text']??null)!==$base||($r['prior_owned_offer_operation']??null)!==$own['operation']||($r['replaced_owned_offer_text']??null)!==$oldAdded||($r['replaced_owned_offer_text_sha256']??null)!==$own['added_text_sha256'])return false;return $base;
}
function offerAddition13(array $r):bool{
 $f=json_decode(base64_decode(APPROVED_LEGACY_CONTEXT_B64,true),true,128,JSON_THROW_ON_ERROR);$base=offerBase16($r,$f);$added=(string)($r['added_text']??'');if($base===false||renderOffer16($base,$added)!==($r['new_text']??''))return false;
 $stripped=preg_replace('~https://[^\s]+~u','',$added);if(!is_string($stripped)||preg_match('/[-\x{2010}-\x{2015}\x{2212}]/u',$stripped)!==0)return false;
 $urls=[];preg_match_all('~https?://[^\s]+~u',$added,$urls);$want=($r['search_link_included']??false)?[(string)$r['search_link_url']]:[];return ($urls[0]??[])===$want;
}
function offerUrl13(array $payload,array $r,array $frame):bool{
 $url=(string)($r['search_link_url']??'');if($url!==base64_decode(APPROVED_SEARCH_URL_B64,true))return false;$u=parse_url($url);
 if(!is_array($u)||($u['scheme']??'')!=='https'||($u['host']??'')!=='anytour.online'||($u['path']??'')!=='/poisk-turov/'||(isset($u['fragment'])||isset($u['user']))||isset($u['pass'])||isset($u['port']))return false;
 parse_str((string)($u['query']??''),$q);$filter=$payload['search_filters']??[];$native=(int)($frame['binding']['native_hotel_id']??0);$country=(int)($frame['binding']['expected_country_id']??0);
 $expected=['days_from'=>(string)($filter['nights']??''),'days_till'=>(string)($filter['nights']??''),'from'=>'1','count_people'=>'2','country'=>(string)$country,'hotel'=>(string)$native,'date_from'=>(string)($filter['departure_date']??''),'date_till'=>(string)($filter['departure_date']??'')];
 return count($q)===count($expected)&&$q===$expected&&$native===APPROVED_UPDATE_HOTEL_ID&&in_array($country,[1,4],true)&&(int)$filter['nights']>=5&&(int)$filter['nights']<=14&&$filter['departure_date']>gmdate('Y-m-d')&&$filter['departure_date']<='2027-02-02';
}
if(getenv('ANYTOUR_NORMAL674_OFFER_NEW_CASES')==='1'){
 $payload=json_decode(base64_decode(MANIFEST_BASE64,true),true,128,JSON_THROW_ON_ERROR);$f=json_decode(base64_decode(APPROVED_LEGACY_CONTEXT_B64,true),true,128,JSON_THROW_ON_ERROR);$r=$payload['rows'][0];
 need(legacyPriceFrameOk($f)&&legacyPriceRowOk($r,$f,APPROVED_UPDATE_HOTEL_ID,APPROVED_LEGACY_CONTEXT_SHA256)&&offerAddition13($r)&&offerUrl13($payload,$r,$f),'new_normal_offer_positive_failed');
 $bad=$f;$bad['binding']['publisher_hotel_id']=0;need(!legacyPriceFrameOk($bad),'new_normal_wrong_publisher_role_accepted');
 $bad=$f;$bad['capture_run']=41979357309;need(!legacyPriceFrameOk($bad),'new_normal_old_capture_run_accepted');
 $bad=$r;$bad['hotel_id']=67910;need(!legacyPriceRowOk($bad,$f,APPROVED_UPDATE_HOTEL_ID,APPROVED_LEGACY_CONTEXT_SHA256),'new_normal_foreign_native_accepted');
 $bad=$r;$bad['render_base_text'].='foreign';need(!offerAddition13($bad),'new_normal_foreign_original_hotel_body_accepted');
 $bad=$r;if(isset($f['binding']['prior_owned_offer'])){$bad['replaced_owned_offer_text'].='foreign';}else{$bad['prior_owned_offer_operation']='foreign';}need(!offerAddition13($bad),'new_normal_foreign_owned_block_accepted');
 $bad=$r;$parts=[];parse_str((string)parse_url($bad['search_link_url'],PHP_URL_QUERY),$parts);$parts['hotel']='67910';$bad['search_link_url']='https://anytour.online/poisk-turov/?'.http_build_query($parts);need(!offerUrl13($payload,$bad,$f),'new_normal_foreign_search_filter_accepted');
 echo "NORMAL674_OFFER_NEW_ROLE_FRAME_NATIVE_BASE_OWNED_BLOCK_LINK_CASES_OK new_cases=6 old_fixtures0 provider0 db0\n";exit;
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


    $legacyRaw=base64_decode(APPROVED_LEGACY_CONTEXT_B64,true);need(is_string($legacyRaw)&&hash_equals(APPROVED_LEGACY_CONTEXT_SHA256,hash('sha256',$legacyRaw)),'approved_legacy_context_bytes_changed');$legacyFrame=json_decode($legacyRaw,true,128,JSON_THROW_ON_ERROR);need(legacyPriceFrameOk($legacyFrame),'approved_legacy_context_roles_invalid');
    $payload=json_decode(base64_decode(MANIFEST_BASE64,true),true,64,JSON_THROW_ON_ERROR);need(($payload['operation']??'')===APPROVED_UPDATE_OPERATION&&count($payload['rows']??[])===1,'manifest_scope_changed');$rows=$payload['rows'];
    foreach($rows as $r)need($r['key']===base64_decode(APPROVED_UPDATE_KEY_B64,true)&&(int)$r['hotel_id']===APPROVED_UPDATE_HOTEL_ID&&$r['logical_id']===base64_decode(APPROVED_UPDATE_LOGICAL_B64,true)&&hash('sha256',$r['old_text'])===$r['old_text_sha256']&&hash('sha256',$r['new_text'])===$r['new_text_sha256']&&offerAddition13($r)&&is_string($r['new_text'])&&strlen(mb_convert_encoding($r['new_text'],'UTF-16LE','UTF-8'))/2<=1024,'prepared_text_invalid');
    need(defined('APPROVED_MANIFEST_SHA256')&&hash('sha256',base64_decode(MANIFEST_BASE64,true))===APPROVED_MANIFEST_SHA256,'approved_manifest_bytes_changed');
    need(($payload['quote_response_sha256']??'')===VERIFIED_QUOTE_RESPONSE_SHA256&&(int)($payload['quote_epoch_utc']??0)===VERIFIED_QUOTE_EPOCH&&(int)$payload['quote_index']===VERIFIED_QUOTE_INDEX,'verified_quote_binding_changed');
    need(time()>=VERIFIED_QUOTE_EPOCH&&time()-VERIFIED_QUOTE_EPOCH<=259200,'verified_quote_too_old_for_edit');
    foreach($rows as $r)need(legacyPriceRowOk($r,$legacyFrame,APPROVED_UPDATE_HOTEL_ID,APPROVED_LEGACY_CONTEXT_SHA256),'legacy_price_native_publication_photo_context_changed');
    need(in_array($payload['update_kind']??'',['price_and_search_link','price_only','search_link_only'],true),'offer_update_kind_changed');
    foreach($rows as $r)need(offerUrl13($payload,$r,$legacyFrame),'native_search_filter_url_changed');
    $hasPrice=in_array($payload['update_kind'],['price_and_search_link','price_only'],true);
    need(($payload['price_total_verified']??null)===$hasPrice&&($payload['fuel_separately_added']??null)===false,'offer_price_verification_mode_changed');
    if($hasPrice)need(VERIFIED_QUOTE_INDEX>=0&&is_string($payload['quote_total_amount']??null)&&$payload['quote_currency']==='RUB','verified_price_payload_missing');
    else need(VERIFIED_QUOTE_INDEX===-1&&($payload['quote_total_amount']??null)===null,'link_only_must_not_claim_price');
    need($payload['search_link_included']===($payload['update_kind']!=='price_only'),'offer_search_link_mode_changed');foreach($rows as $r)need($r['search_link_included']===$payload['search_link_included'],'offer_row_link_mode_changed');
    $out['update_kind']=$payload['update_kind'];
    $pdo=ConversationDb::connection();
    foreach(['033_channel_post_context.sql','036_channel_post_member_delivery.sql','040_channel_post_reconciliation.sql'] as $m){$q=$pdo->prepare('SELECT checksum FROM schema_migrations WHERE version=?');$q->execute([$m]);need($q->fetchColumn()===hash_file('sha256',getcwd().'/migrations/'.$m),'publishing_schema_not_accepted');}
    need(preg_match('/\Acommercial-hotel-offer-update-20261105-normal674-h[1-9][0-9]{0,6}-v1\z/D',APPROVED_UPDATE_OPERATION)===1,'invalid_exact_update_operation');$dir='/var/www/anytoour/data/channel-post-operations/'.APPROVED_UPDATE_OPERATION;need(!is_link(dirname($dir))&&!is_link($dir),'invalid_operation_directory');if(!is_dir($dir))need(mkdir($dir,0700,true),'invalid_operation_directory');need(realpath($dir)===$dir,'invalid_operation_directory');
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
            $snap=$p['snapshot'];
            if($platform==='max'){$actual=ChannelPostIncomingAdapter::maxSnapshot(readMax($mid));need(is_array($actual)&&($actual['text']??'')===$snap['text']&&photoSignature($actual)===photoSignature($snap)&&($actual['post_url']??'')===($snap['post_url']??'')&&ChannelPostIncomingAdapter::containsOnlyButton($actual,ChannelPostButtonTransport::buttonUrl($p),'✈️ Узнать стоимость тура'),'existing_max_readback_changed');}
            $plans[$key]=['platform'=>$platform,'key'=>$r['key'],'logical_id'=>$r['logical_id'],'message_id'=>$mid,'publication_id'=>$id,'old_version_id'=>(int)$p['version_id'],'token'=>$p['start_payload'],'post_url'=>$snap['post_url'],'photo_signature'=>photoSignature($snap),'text'=>$r['new_text'],'update_text'=>$r['update_text']];
        }
    }
    $out['preflight_existing_messages']=count($plans);$out['configuration_write_attempts']=0;$out['content_edit_attempts']=0;$out['button_only_edit_attempts']=0;$out['posts']=[];
    foreach($plans as $key=>$plan){
        $out['current']=['platform'=>$plan['platform'],'logical_id'=>$plan['logical_id']];if(isset($state['posts'][$key])){$out['posts'][]=$state['posts'][$key]['receipt'];continue;}
        $platform=$plan['platform'];$t=$targets[$platform];$p=$registry->publication($plan['publication_id']);$c=$channels[$platform];need(is_array($p)&&(int)$p['version_id']===$plan['old_version_id']&&$p['state']==='sent'&&(int)$p['channel']['config_version']===2&&$p['channel']['button_text']===$payload['button_text']&&hash_equals((string)$p['start_payload'],(string)$plan['token']),'target_changed_before_edit');
        $state['posts'][$key]=['state'=>'edit_started','plan'=>$plan,'edit_request_start_ms'=>(int)floor(microtime(true)*1000)];durable($lock,$state);$requestStart=$state['posts'][$key]['edit_request_start_ms'];
        if($platform==='telegram'){
            $body=['chat_id'=>$t['channel'],'message_id'=>$plan['message_id'],'reply_markup'=>json_encode(['inline_keyboard'=>[[['text'=>$payload['button_text'],'url'=>ChannelPostButtonTransport::buttonUrl($p)]]]],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)];
            if($plan['update_text']){$method='editMessageCaption';$body['caption']=$plan['text'];$out['content_edit_attempts']++;$out['post_write_attempts']++;}else{$method='editMessageReplyMarkup';$out['button_only_edit_attempts']++;}
            $response=tg($method,$body);$state['posts'][$key]['provider_response']=$response;$state['posts'][$key]['state']='provider_response_recorded';durable($lock,$state);
            need($response['http']===200&&($response['data']['ok']??false)===true&&is_array($response['data']['result']??null),'telegram_edit_not_confirmed_no_retry');$message=$response['data']['result'];
            need((string)($message['chat']['id']??'')===$t['channel']&&(string)($message['message_id']??'')===$plan['message_id']&&($message['chat']['username']??'')==='any_toour','telegram_response_identity_changed');
            $snap=ChannelPostIncomingAdapter::telegramSnapshot($message);need(is_array($snap)&&snapshotMatches($p,$snap,$plan),'telegram_body_photo_button_mismatch');
            $snap['content_hash']=ChannelPostIncomingAdapter::contentFingerprint($snap,'',(string)$c['bot_url']);
            $snap['metadata']=array_replace($p['snapshot']['metadata']??[],$snap['metadata']??[]);
            $snap['metadata']['source_event_id']=$payload['operation'].':telegram:'.$plan['message_id'];
            $snap['metadata']['observation_kind']='authorized_admin_content_edit';
            if($hasPrice){$snap['metadata']['price_quote_response_sha256']=$payload['quote_response_sha256'];$snap['metadata']['price_checked_epoch_utc']=(string)$payload['quote_epoch_utc'];}$snap['metadata']['offer_quote_response_sha256']=$payload['quote_response_sha256'];$snap['metadata']['offer_quote_checked_epoch_utc']=(string)$payload['quote_epoch_utc'];
            $p=$registry->registerPublication('anytour','telegram',$t['bot'],$t['channel'],$plan['message_id'],$snap);
            need(is_array($p)&&(int)$p['publication_id']===$plan['publication_id'],'telegram_registry_receive_failed');
            if($p['state']!=='sent'){$button=$automation->processPublication('anytour','telegram',$t['bot'],(int)$p['publication_id'],(int)$p['version_id']);$state['posts'][$key]['button_result']=$button;durable($lock,$state);need(($button['state']??'')==='sent','telegram_button_outcome_not_confirmed');}
            $p=$registry->publication($plan['publication_id']);need(is_array($p)&&$p['state']==='sent','telegram_registry_not_confirmed');
        }else{
            if($plan['update_text']){
                $parts=explode("\n",$plan['text'],2);$html='<b>'.htmlspecialchars($parts[0],ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8').'</b>'.(isset($parts[1])?"\n".htmlspecialchars($parts[1],ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8'):'');$out['content_edit_attempts']++;$out['post_write_attempts']++;
                // No attachments field: exact provider photo and existing keyboard are retained.
                $response=MaxTransport::request('https://platform-api2.max.ru',MAX_SEARCH_TOKEN,'PUT','/messages',['message_id'=>$plan['message_id']],['text'=>$html,'format'=>'html'],null);
                $state['posts'][$key]['provider_edit_returned']=is_array($response)||$response===true;$state['posts'][$key]['state']='text_edit_returned';durable($lock,$state);
                $message=readMax($plan['message_id']);$snap=ChannelPostIncomingAdapter::maxSnapshot($message);need(is_array($snap)&&($snap['text']??'')===$plan['text']&&photoSignature($snap)===$plan['photo_signature']&&($snap['post_url']??'')===$plan['post_url'],'max_content_edit_readback_mismatch');
                $snap['content_hash']=ChannelPostIncomingAdapter::contentFingerprint($snap,'',(string)$c['bot_url']);$snap['metadata']=array_replace($p['snapshot']['metadata']??[],$snap['metadata']??[]);if($hasPrice){$snap['metadata']['price_quote_response_sha256']=$payload['quote_response_sha256'];$snap['metadata']['price_checked_epoch_utc']=(string)$payload['quote_epoch_utc'];}$snap['metadata']['offer_quote_response_sha256']=$payload['quote_response_sha256'];$snap['metadata']['offer_quote_checked_epoch_utc']=(string)$payload['quote_epoch_utc'];$snap['metadata']['source_event_id']=$payload['operation'].':'.$key;$snap['metadata']['observation_kind']='authorized_admin_content_edit';$snap['metadata']['operation_observed_at_ms']=(string)(int)floor(microtime(true)*1000);$snap['metadata']['source_revision_ms']=(string)$requestStart;$snap['metadata']['revision_clock_origin']='authorized_edit_request_start';
                $p=$registry->registerPublication('anytour','max',$t['bot'],$t['channel'],$plan['message_id'],$snap);
            }else{
                $message=readMax($plan['message_id']);$p=$registry->registerProviderObservation('anytour',$t['bot'],$c,$message,1);$out['button_only_edit_attempts']++;
            }
            need(is_array($p)&&(int)$p['publication_id']===$plan['publication_id']&&($p['snapshot']['text']??'')===$plan['text'],'max_registry_version_not_current');
            if($p['state']!=='sent'){$button=$automation->processPublication('anytour','max',$t['bot'],(int)$p['publication_id'],(int)$p['version_id']);$state['posts'][$key]['button_result']=$button;durable($lock,$state);need(($button['state']??'')==='sent','max_button_outcome_not_confirmed');}
            $p=$registry->publication($plan['publication_id']);$snap=ChannelPostIncomingAdapter::maxSnapshot(readMax($plan['message_id']));need(is_array($snap)&&snapshotMatches($p,$snap,$plan),'max_final_photo_body_button_mismatch');
        }
        need($p['state']==='sent'&&$p['platform']===$platform&&hash_equals((string)$p['start_payload'],(string)$plan['token'])&&($p['snapshot']['text']??'')===$plan['text']&&photoSignature($p['snapshot'])===$plan['photo_signature']&&($p['snapshot']['post_url']??'')===$plan['post_url'],'final_registry_context_mismatch');
        $resolved=ChannelPostEntryService::resolve($platform,$plan['token'],$registry);need(is_array($resolved)&&(int)$resolved['version_id']===(int)$p['version_id']&&($resolved['snapshot']['text']??'')===$plan['text']&&$resolved['source_key']===$t['source'],'existing_token_new_version_resolution_failed');
        $receipt=safeResult($p,$plan);$state['posts'][$key]['state']='confirmed';$state['posts'][$key]['receipt']=$receipt;durable($lock,$state);$out['posts'][]=$receipt;if($hasPrice)$out['price_block_updates']++;if($payload['search_link_included'])$out['search_link_post_updates']++;if($payload['update_kind']==='search_link_only')$out['search_link_only_post_updates']++;
        echo json_encode(['progress'=>'existing_post_updated','platform'=>$platform,'logical_id'=>$plan['logical_id'],'post_url'=>$plan['post_url'],'content_updated'=>$plan['update_text']],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),PHP_EOL;flush();usleep(3200000);
    }
    $out['ok']=true;$out['new_posts_created']=0;$out['commercial_text_updates']=count(array_filter($out['posts'],static fn($p)=>$p['content_updated']));$out['price_block_updates']=$hasPrice?$out['commercial_text_updates']:0;$out['search_link_post_updates']=$payload['search_link_included']?$out['commercial_text_updates']:0;$out['search_link_only_post_updates']=$payload['update_kind']==='search_link_only'?$out['commercial_text_updates']:0;$out['button_only_updates']=count($out['posts'])-$out['commercial_text_updates'];$out['installed_sha']=EXPECTED_INSTALLED_SHA;unset($out['current']);$state['complete']=true;durable($lock,$state);
} catch(Throwable $e){$out['error']=preg_match('/\A[a-z][a-z0-9_]{1,100}\z/',$e->getMessage())?$e->getMessage():'bounded_existing_post_update_failed';}
if(isset($lock)&&is_resource($lock)){flock($lock,LOCK_UN);fclose($lock);}
echo json_encode($out,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR),PHP_EOL;exit($out['ok']?0:2);
