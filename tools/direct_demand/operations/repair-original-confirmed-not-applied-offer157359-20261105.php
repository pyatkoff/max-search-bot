<?php
declare(strict_types=1);
ini_set('display_errors','0');ini_set('log_errors','0');
$out=['operation'=>'commercial-hotel-offer-update-20261105-normal674-h157359-v1-repair1','ok'=>false,'original_operation'=>'commercial-hotel-offer-update-20261105-normal674-h157359-v1','provider_read_requests'=>0,'provider_write_requests'=>0,'gateway_calls'=>0,'new_posts'=>0,'other_publications_touched'=>0,'manual_capture'=>false];
function need(bool $v,string $reason):void{if(!$v)throw new RuntimeException($reason);}
function durable($f,array $s):void{need(fseek($f,0,SEEK_END)===0,'receipt_write_failed');$raw=json_encode($s,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n";need(fwrite($f,$raw)===strlen($raw)&&fflush($f),'receipt_write_failed');if(function_exists('fsync'))need(fsync($f),'receipt_sync_failed');}
function photoSignature(array $snapshot):string{$media=$snapshot['attachments']??[];need(count($media)===1&&($media[0]['type']??'')==='image','expected_one_photo');$m=$media[0];$id=(string)($m['photo_id']??$m['file_unique_id']??'');need($id!==''&&preg_match('/\A[A-Za-z0-9_-]+\z/',$id)===1,'photo_stable_id_missing');return (string)($m['provider']??'').':'.$id;}
function safeResult(array $p,array $plan):array{return ['logical_id'=>$plan['logical_id'],'platform'=>$p['platform'],'channel_id'=>$p['channel_id'],'post_url'=>$plan['post_url'],'message_id'=>$plan['message_id'],'publication_id'=>(int)$p['publication_id'],'version_id'=>(int)$p['version_id'],'previous_version_id'=>$plan['old_version_id'],'button_url'=>ChannelPostButtonTransport::buttonUrl($p),'button_text'=>'✈️ Узнать стоимость тура','text_sha256'=>hash('sha256',$plan['text']),'photo_signature'=>$plan['photo_signature'],'source_key'=>$p['source_key'],'content_updated'=>$plan['update_text'],'state'=>'sent','body_photo_button_verified'=>true,'existing_message_reused'=>true];}
function repairInput157359():array{$raw=base64_decode(APPROVED_REPAIR_INPUT_B64,true);need(is_string($raw)&&hash_equals(APPROVED_REPAIR_INPUT_SHA256,hash('sha256',$raw)),'repair_input_bytes_changed');return json_decode($raw,true,256,JSON_THROW_ON_ERROR);}
function repairPlan157359(array $x):bool{
 $w=$x['prior_reconciliation_witness']??[];return ($x['native_hotel_id']??0)===157359&&($x['original_execution_run']??0)===42000246854&&($x['prior_reconciliation_run']??0)===42001874871&&($x['repair_write_limit']??0)===1&&($x['original_write_attempts']??0)===1&&($x['manifest']['operation']??'')==='commercial-hotel-offer-update-20261105-normal674-h157359-v1'&&($x['manifest']['rows'][0]['hotel_id']??0)===157359&&($x['canonical_context']['binding']['native_hotel_id']??0)===157359&&($w['provider_read_requests']??0)===1&&($w['provider_write_requests']??-1)===0&&($w['owned_read_http']??0)===200&&($w['original_provider_edit_returned']??null)===false&&($w['provider_matches_new_body']??null)===false&&($w['provider_matches_old_body']??false)===true&&($w['registry_before_mode']??'')==='original_old_version';
}
if(getenv('ANYTOUR_REPAIR157359_NEW_INPUT_CASES')==='1'){
 $d=repairInput157359();need(repairPlan157359($d),'actual_confirmed_not_applied_original_required');
 $bad=$d;$bad['native_hotel_id']=67910;need(!repairPlan157359($bad),'foreign_repair_native_accepted');
 $bad=$d;$bad['prior_reconciliation_witness']['provider_matches_new_body']=true;need(!repairPlan157359($bad),'already_applied_write_repaired');
 $bad=$d;$bad['prior_reconciliation_witness']['original_provider_edit_returned']=true;need(!repairPlan157359($bad),'unconfirmed_failure_repaired');
 $bad=$d;$bad['repair_write_limit']=2;need(!repairPlan157359($bad),'unbounded_repair_writes_accepted');
 echo "REPAIR157359_NEW_KNOWN_NOT_APPLIED_ORIGINAL_SCOPE_CASES_OK new_cases=4 old_fixtures0 provider0 db0\n";exit;
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



    require_once getcwd().'/services/HotelDatabase.php';need(HotelDatabase::configured(),'hotel_catalog_connection_not_configured');
    $catalog=HotelDatabase::connection();
 $d=repairInput157359();need(repairPlan157359($d),'original157359_verified_failure_repair_scope_changed');$m=$d['manifest'];$r=$m['rows'][0];$frame=$d['canonical_context'];$b=$frame['binding'];$old=$b['contexts']['max'];
 $pdo=ConversationDb::connection();foreach(['033_channel_post_context.sql','036_channel_post_member_delivery.sql','040_channel_post_reconciliation.sql'] as $name){$q=$pdo->prepare('SELECT checksum FROM schema_migrations WHERE version=?');$q->execute([$name]);need($q->fetchColumn()===hash_file('sha256',getcwd().'/migrations/'.$name),'publishing_schema_not_accepted');}
 $native=$catalog->prepare('SELECT id,name,is_active,country_id FROM catalog_hotels WHERE id=? LIMIT 1');$native->execute([157359]);$h=$native->fetch(PDO::FETCH_ASSOC);need(is_array($h)&&(int)$h['id']===157359&&(int)$h['is_active']===1&&(int)$h['country_id']===(int)$b['expected_country_id']&&$h['name']===$b['catalog_name'],'original_native_identity_changed');
 $dir='/var/www/anytoour/data/channel-post-operations/'.$m['operation'];need(!is_link(dirname($dir))&&!is_link($dir)&&is_dir($dir)&&realpath($dir)===$dir,'original_operation_directory_changed');$path=$dir.'/receipt.jsonl';need(is_file($path)&&!is_link($path),'original_receipt_missing');
 $lock=fopen($path,'r+');need($lock!==false&&flock($lock,LOCK_EX|LOCK_NB),'original_operation_busy');$raw=stream_get_contents($lock);need(is_string($raw)&&$raw!==''&&strlen($raw)<=5242880&&str_ends_with($raw,"\n"),'original_journal_partial');$lines=explode("\n",rtrim($raw,"\n"));$state=json_decode(end($lines),true,128,JSON_THROW_ON_ERROR);need(($state['operation']??'')===$m['operation']&&($state['manifest_hash']??'')===hash('sha256',json_encode($m,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)),'original_journal_manifest_changed');
 $key='max:'.$r['key'];$tgkey='telegram:'.$r['key'];need(isset($state['posts'][$key])&&!isset($state['posts'][$tgkey]),'original_max_only_partial_scope_changed');$member=$state['posts'][$key];$plan=$member['plan']??[];
 need(($plan['platform']??'')==='max'&&(int)($plan['publication_id']??0)===(int)$old['publication_id']&&($plan['message_id']??'')===$old['message_id']&&(int)($plan['old_version_id']??0)===(int)$old['version_id']&&($plan['text']??'')===$r['new_text']&&($plan['photo_signature']??'')===$old['photo_signature']&&($plan['post_url']??'')===$old['post_url']&&hash('sha256',(string)($plan['token']??''))===$old['start_payload_sha256'],'original_write_plan_not_exact');
 $registry=new ChannelPostRegistryService($pdo);$c=$registry->channel('anytour','max','id9704048781_1_bot','-80492244209683');need(is_array($c)&&(int)$c['id']===9&&(int)$c['config_version']===2&&$c['source_key']==='max_anytour_msk1'&&$c['button_text']==='✈️ Узнать стоимость тура','original_channel_changed');
 $p=$registry->publication((int)$plan['publication_id']);need(is_array($p)&&$p['project_key']==='anytour'&&$p['platform']==='max'&&$p['bot_key']==='id9704048781_1_bot'&&$p['channel_id']==='-80492244209683'&&$p['external_message_id']===$plan['message_id']&&$p['source_key']==='max_anytour_msk1'&&hash_equals((string)$p['start_payload'],(string)$plan['token'])&&empty($p['claim_token'])&&empty($p['claimed_at']),'original_publication_or_foreign_lease_changed');
 $event=$m['operation'].':'.$key;$before=$p['snapshot'];$wasOld=(int)$p['version_id']===(int)$plan['old_version_id']&&($before['text']??'')===$r['old_text']&&$p['state']==='sent';$wasOwnNew=($before['text']??'')===$r['new_text']&&($before['metadata']['source_event_id']??'')===$event&&in_array($p['state'],['pending','sent'],true);
 need(($wasOld||$wasOwnNew)&&photoSignature($before)===$plan['photo_signature']&&($before['post_url']??'')===$plan['post_url'],'original_registry_content_changed_by_other_action');
 $out['original_member_phase']=$member['state']??null;$out['registry_before_mode']=$wasOld?'original_old_version':'own_original_new_version';
 need(($member['state']??'')==='text_edit_returned'&&($member['provider_edit_returned']??null)===false&&!isset($member['original_repair_attempt_started_epoch_utc']),'original_known_failed_single_write_required_no_repair_replay');
 $saved=$member['original_reconciliation_snapshot']??null;need(is_array($saved)&&($saved['text']??'')===$r['old_text']&&photoSignature($saved)===$plan['photo_signature'],'original_old_body_primary_proof_missing');
 $get=static function()use(&$out,$plan,$p):array{$out['provider_read_requests']++;$api=ChannelPostApiTransport::buttonRequest('max','GET','/messages/'.$plan['message_id'],[],null);need(empty($api['transport_error'])&&(int)$api['http']===200&&is_array($api['data']),'original_repair_owned_read_failed_no_repeat');$s=ChannelPostIncomingAdapter::maxSnapshot($api['data']);need(is_array($s)&&photoSignature($s)===$plan['photo_signature']&&($s['post_url']??'')===$plan['post_url']&&ChannelPostIncomingAdapter::containsOnlyButton($s,ChannelPostButtonTransport::buttonUrl($p),'✈️ Узнать стоимость тура'),'original_repair_photo_source_button_changed');return $s;};
 $snapshot=$get();$alreadyNew=($snapshot['text']??'')===$r['new_text'];need($alreadyNew||($wasOld&&($snapshot['text']??'')===$r['old_text']),'original_repair_current_body_not_old_or_intended');
 $out['original_first_write_confirmed_not_applied_before_repair']=!$alreadyNew;$out['provider_write_requests']=0;
 if(!$alreadyNew){
  $state['posts'][$key]['original_failed_write_primary_resolution']='CONFIRMED_NOT_APPLIED_OWNED_CURRENT_OLD_BODY_PHOTO_BUTTON';$state['posts'][$key]['original_failed_write_attempts']=1;$state['posts'][$key]['original_repair_attempt_started_epoch_utc']=time();$state['posts'][$key]['original_repair_preflight_snapshot']=$snapshot;$state['posts'][$key]['original_content_write_attempts']=2;$state['posts'][$key]['state']='original_repair_edit_started';durable($lock,$state);
  $parts=explode("\n",$plan['text'],2);$html='<b>'.htmlspecialchars($parts[0],ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8').'</b>'.(isset($parts[1])?"\n".htmlspecialchars($parts[1],ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8'):'');
  $out['provider_write_requests']=1;$response=MaxTransport::request('https://platform-api2.max.ru',MAX_SEARCH_TOKEN,'PUT','/messages',['message_id'=>$plan['message_id']],['text'=>$html,'format'=>'html'],null);$error=MaxTransport::lastError();
  $state['posts'][$key]['original_repair_provider_response_private']=$response;$state['posts'][$key]['original_repair_provider_error_private']=$error;$state['posts'][$key]['original_repair_provider_edit_returned']=is_array($response)||$response===true;$state['posts'][$key]['state']='original_repair_text_edit_returned';durable($lock,$state);
  $out['provider_error_http']=is_array($error)?(int)($error['http_code']??0):null;$out['provider_error_category']=is_array($error)?($error['category']??null):null;$out['provider_error_message_private']=is_array($error)?($error['message']??null):null;$out['repair_provider_edit_returned']=$state['posts'][$key]['original_repair_provider_edit_returned'];
  if(is_array($error)){$msg=(string)($error['message']??'');$out['provider_error_code']=preg_match('/\A[A-Za-z][A-Za-z_.:-]{1,100}\z/D',$msg)?$msg:null;}
  $snapshot=$get();$state['posts'][$key]['original_repair_actual_snapshot']=$snapshot;$state['posts'][$key]['original_repair_actual_observed_epoch_utc']=time();$state['posts'][$key]['original_repair_matches_new_body']=($snapshot['text']??'')===$r['new_text'];$state['posts'][$key]['original_repair_matches_old_body']=($snapshot['text']??'')===$r['old_text'];durable($lock,$state);
  $out['provider_matches_new_body']=$state['posts'][$key]['original_repair_matches_new_body'];$out['provider_matches_old_body']=$state['posts'][$key]['original_repair_matches_old_body'];
  if(!$out['provider_matches_new_body']){
   $declined=is_array($error)&&$out['provider_error_http']>=400&&$out['provider_error_http']<500&&$out['provider_matches_old_body']&&$wasOld;
   $state['posts'][$key]['original_repair_outcome']=$declined?'PROVIDER_DECLINED_CURRENT_OLD_BODY_CONFIRMED_NO_CHANGE':'OUTCOME_UNRESOLVED_NO_MORE_PROVIDER_WRITES';durable($lock,$state);$out['known_not_applied_after_provider_decline']=$declined;$out['telegram_not_attempted']=true;$out['original_total_content_write_attempts']=2;
   throw new RuntimeException($declined?'original_repair_provider_declined_no_change_no_more_writes':'original_repair_outcome_unresolved_no_more_writes');
  }
 }
 if($wasOld){
  $snapshot['content_hash']=ChannelPostIncomingAdapter::contentFingerprint($snapshot,'',(string)$c['bot_url']);$snapshot['metadata']=array_replace($before['metadata']??[],$snapshot['metadata']??[]);$snapshot['metadata']['price_quote_response_sha256']=$m['quote_response_sha256'];$snapshot['metadata']['price_checked_epoch_utc']=(string)$m['quote_epoch_utc'];$snapshot['metadata']['offer_quote_response_sha256']=$m['quote_response_sha256'];$snapshot['metadata']['offer_quote_checked_epoch_utc']=(string)$m['quote_epoch_utc'];$snapshot['metadata']['source_event_id']=$event;$snapshot['metadata']['observation_kind']='authorized_admin_content_edit';$snapshot['metadata']['operation_observed_at_ms']=(string)(int)floor(microtime(true)*1000);$snapshot['metadata']['source_revision_ms']=(string)$member['edit_request_start_ms'];$snapshot['metadata']['revision_clock_origin']='authorized_edit_request_start';
  $p=$registry->registerPublication('anytour','max','id9704048781_1_bot','-80492244209683',$plan['message_id'],$snapshot);$out['own_original_registry_version_registered']=true;
 }
 need(is_array($p)&&($p['snapshot']['text']??'')===$r['new_text']&&($p['snapshot']['metadata']['source_event_id']??'')===$event&&empty($p['claim_token'])&&empty($p['claimed_at'])&&in_array($p['state'],['pending','sent'],true),'own_original_registry_state_changed');
 if($p['state']==='pending'){need($registry->confirmObservedButton((int)$p['publication_id'],(int)$p['version_id'],['state'=>'verified_only_button','original_operation'=>$m['operation'],'verification'=>'original_known_not_applied_single_repair_actual_body_photo_button','observed_at_epoch_utc'=>time(),'content_sha256'=>hash('sha256',$r['new_text'])]),'own_original_observed_button_ack_failed');$out['own_original_registry_confirmation_written']=true;}
 $p=$registry->publication((int)$plan['publication_id']);need(is_array($p)&&$p['state']==='sent'&&($p['snapshot']['text']??'')===$r['new_text']&&photoSignature($p['snapshot'])===$plan['photo_signature']&&hash_equals((string)$p['start_payload'],(string)$plan['token'])&&($p['snapshot']['post_url']??'')===$plan['post_url'],'original_repaired_registry_final_context_mismatch');
 $resolved=ChannelPostEntryService::resolve('max',$plan['token'],$registry);need(is_array($resolved)&&(int)$resolved['version_id']===(int)$p['version_id']&&$resolved['source_key']==='max_anytour_msk1'&&($resolved['snapshot']['text']??'')===$r['new_text'],'original_repaired_token_current_context_mismatch');
 $state['posts'][$key]['state']='confirmed';$state['posts'][$key]['receipt']=safeResult($p,$plan);$state['posts'][$key]['original_write_confirmed_after_primary_resolution']=true;$state['posts'][$key]['original_write_reconciled_without_provider_write']=$alreadyNew;$state['posts'][$key]['original_content_write_attempts']=1+$out['provider_write_requests'];$state['posts'][$key]['reconciliation_phase']='confirmed_original_max_telegram_unattempted_after_primary_resolution';durable($lock,$state);
 $out['max_original_write_confirmed']=true;$out['telegram_not_attempted']=true;$out['original_total_content_write_attempts']=1+$out['provider_write_requests'];$out['same_original_operation_manifest_and_payload_retained']=true;$out['original_write_replayed_blindly']=false;$out['installed_sha']=EXPECTED_INSTALLED_SHA;$out['ok']=true;
}catch(Throwable $e){$out['error']=preg_match('/\A[a-z][a-z0-9_]{1,120}\z/D',$e->getMessage())?$e->getMessage():'original_single_verified_failure_repair_stopped';}
if(isset($lock)&&is_resource($lock)){flock($lock,LOCK_UN);fclose($lock);}
echo json_encode($out,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR),PHP_EOL;exit($out['ok']?0:2);
