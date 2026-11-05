<?php
declare(strict_types=1);
ini_set('display_errors','0');ini_set('log_errors','0');
$out=['operation'=>'commercial-hotel-offer-update-20261105-manual1-h273-v1-reconcile1','ok'=>false,'original_operation'=>'commercial-hotel-offer-update-20261105-manual1-h273-v1','provider_read_requests'=>0,'provider_write_requests'=>0,'gateway_calls'=>0,'new_posts'=>0,'other_publications_touched'=>0,'manual_capture'=>false];
function need(bool $v,string $reason):void{if(!$v)throw new RuntimeException($reason);}
function durable($f,array $s):void{need(fseek($f,0,SEEK_END)===0,'receipt_write_failed');$raw=json_encode($s,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n";need(fwrite($f,$raw)===strlen($raw)&&fflush($f),'receipt_write_failed');if(function_exists('fsync'))need(fsync($f),'receipt_sync_failed');}
function photoSignature(array $snapshot):string{$media=$snapshot['attachments']??[];need(count($media)===1&&($media[0]['type']??'')==='image','expected_one_photo');$m=$media[0];$id=(string)($m['photo_id']??$m['file_unique_id']??'');need($id!==''&&preg_match('/\A[A-Za-z0-9_-]+\z/',$id)===1,'photo_stable_id_missing');return (string)($m['provider']??'').':'.$id;}
function safeResult(array $p,array $plan):array{return ['logical_id'=>$plan['logical_id'],'platform'=>$p['platform'],'channel_id'=>$p['channel_id'],'post_url'=>$plan['post_url'],'message_id'=>$plan['message_id'],'publication_id'=>(int)$p['publication_id'],'version_id'=>(int)$p['version_id'],'previous_version_id'=>$plan['old_version_id'],'button_url'=>ChannelPostButtonTransport::buttonUrl($p),'button_text'=>'✈️ Узнать стоимость тура','text_sha256'=>hash('sha256',$plan['text']),'photo_signature'=>$plan['photo_signature'],'source_key'=>$p['source_key'],'content_updated'=>$plan['update_text'],'state'=>'sent','body_photo_button_verified'=>true,'existing_message_reused'=>true];}
function reconcileInput273():array{$raw=base64_decode(APPROVED_RECONCILE_INPUT_B64,true);need(is_string($raw)&&hash_equals(APPROVED_RECONCILE_INPUT_SHA256,hash('sha256',$raw)),'reconcile_input_bytes_changed');return json_decode($raw,true,256,JSON_THROW_ON_ERROR);}
function reconcilePlan273(array $x):bool{return ($x['native_hotel_id']??0)===273&&($x['original_execution_run']??0)===41984228461&&($x['original_error']??'')==='max_read_failed'&&($x['original_write_attempts']??0)===1&&($x['manifest']['operation']??'')==='commercial-hotel-offer-update-20261105-manual1-h273-v1'&&count($x['manifest']['rows']??[])===1&&($x['manifest']['rows'][0]['hotel_id']??0)===273&&($x['canonical_context']['binding']['native_hotel_id']??0)===273;}
if(getenv('ANYTOUR_RECONCILE273_NEW_INPUT_CASES')==='1'){
 $d=reconcileInput273();need(reconcilePlan273($d),'actual_original273_scope_missing');
 $bad=$d;$bad['native_hotel_id']=1422;need(!reconcilePlan273($bad),'foreign_original_reconcile_accepted');
 $bad=$d;$bad['original_execution_run']=0;need(!reconcilePlan273($bad),'foreign_original_run_accepted');
 $bad=$d;$bad['original_write_attempts']=2;need(!reconcilePlan273($bad),'already_completed_two_post_scope_accepted');
 echo "RECONCILE273_ORIGINAL_OWN_WRITE_CASES_OK new_cases=3 old_fixtures0 provider0 db0\\n";exit;
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
 $d=reconcileInput273();need(reconcilePlan273($d),'original273_reconcile_scope_changed');$m=$d['manifest'];$r=$m['rows'][0];$frame=$d['canonical_context'];$b=$frame['binding'];$old=$b['contexts']['max'];
 $pdo=ConversationDb::connection();foreach(['033_channel_post_context.sql','036_channel_post_member_delivery.sql','040_channel_post_reconciliation.sql'] as $name){$q=$pdo->prepare('SELECT checksum FROM schema_migrations WHERE version=?');$q->execute([$name]);need($q->fetchColumn()===hash_file('sha256',getcwd().'/migrations/'.$name),'publishing_schema_not_accepted');}
 $native=$catalog->prepare('SELECT id,name,is_active,country_id FROM catalog_hotels WHERE id=273 LIMIT 1');$native->execute();$h=$native->fetch(PDO::FETCH_ASSOC);need(is_array($h)&&(int)$h['is_active']===1&&(int)$h['country_id']===1&&$h['name']===$b['catalog_name'],'original_native_identity_changed');
 $dir='/var/www/anytoour/data/channel-post-operations/'.$m['operation'];need(!is_link(dirname($dir))&&!is_link($dir)&&is_dir($dir)&&realpath($dir)===$dir,'original_operation_directory_changed');$path=$dir.'/receipt.jsonl';need(is_file($path)&&!is_link($path),'original_receipt_missing');
 $lock=fopen($path,'r+');need($lock!==false&&flock($lock,LOCK_EX|LOCK_NB),'original_operation_busy');$raw=stream_get_contents($lock);need(is_string($raw)&&$raw!==''&&strlen($raw)<=5242880&&str_ends_with($raw,"\n"),'original_journal_partial');$lines=explode("\n",rtrim($raw,"\n"));$state=json_decode(end($lines),true,128,JSON_THROW_ON_ERROR);need(($state['operation']??'')===$m['operation']&&($state['manifest_hash']??'')===hash('sha256',json_encode($m,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)),'original_journal_manifest_changed');
 $key='max:'.$r['key'];$tgkey='telegram:'.$r['key'];need(isset($state['posts'][$key])&&!isset($state['posts'][$tgkey]),'original_max_only_partial_scope_changed');$member=$state['posts'][$key];$plan=$member['plan']??[];
 need(($plan['platform']??'')==='max'&&(int)($plan['publication_id']??0)===(int)$old['publication_id']&&($plan['message_id']??'')===$old['message_id']&&(int)($plan['old_version_id']??0)===(int)$old['version_id']&&($plan['text']??'')===$r['new_text']&&($plan['photo_signature']??'')===$old['photo_signature']&&($plan['post_url']??'')===$old['post_url']&&hash('sha256',(string)($plan['token']??''))===$old['start_payload_sha256'],'original_write_plan_not_exact');
 $registry=new ChannelPostRegistryService($pdo);$c=$registry->channel('anytour','max','id9704048781_1_bot','-80492244209683');need(is_array($c)&&(int)$c['id']===9&&(int)$c['config_version']===2&&$c['source_key']==='max_anytour_msk1'&&$c['button_text']==='✈️ Узнать стоимость тура','original_channel_changed');
 $p=$registry->publication((int)$plan['publication_id']);need(is_array($p)&&$p['project_key']==='anytour'&&$p['platform']==='max'&&$p['bot_key']==='id9704048781_1_bot'&&$p['channel_id']==='-80492244209683'&&$p['external_message_id']===$plan['message_id']&&$p['source_key']==='max_anytour_msk1'&&hash_equals((string)$p['start_payload'],(string)$plan['token'])&&empty($p['claim_token'])&&empty($p['claimed_at']),'original_publication_or_foreign_lease_changed');
 $event=$m['operation'].':'.$key;$before=$p['snapshot'];$wasOld=(int)$p['version_id']===(int)$plan['old_version_id']&&($before['text']??'')===$r['old_text']&&$p['state']==='sent';$wasOwnNew=($before['text']??'')===$r['new_text']&&($before['metadata']['source_event_id']??'')===$event&&in_array($p['state'],['pending','sent'],true);
 need(($wasOld||$wasOwnNew)&&photoSignature($before)===$plan['photo_signature']&&($before['post_url']??'')===$plan['post_url'],'original_registry_content_changed_by_other_action');
 $out['original_member_phase']=$member['state']??null;$out['original_provider_edit_returned']=$member['provider_edit_returned']??null;$out['registry_before_mode']=$wasOld?'original_old_version':'own_original_new_version';
 if(($member['state']??'')==='confirmed'){need(!empty($member['original_write_reconciled_without_provider_write'])&&$wasOwnNew&&$p['state']==='sent','unexpected_already_confirmed_original_scope');$out['max_original_write_confirmed']=true;$out['telegram_not_attempted']=true;$out['already_reconciled_no_provider_read']=true;$out['ok']=true;}
 else{
  need(($member['state']??'')==='text_edit_returned','original_unknown_phase_not_supported_without_primary_evidence');
  $snapshot=null;if(isset($member['original_reconciliation_snapshot'])){$snapshot=$member['original_reconciliation_snapshot'];need(time()-(int)$member['original_reconciliation_observed_epoch_utc']<=300,'retained_own_read_proof_requires_fresh_review');$out['reused_original_one_owned_read']=true;}
  else{
   $mid=(string)$plan['message_id'];need(preg_match('/\\A[A-Za-z0-9_.-]{1,191}\\z/D',$mid)===1,'original_message_id_invalid');$out['provider_read_requests']=1;$api=ChannelPostApiTransport::buttonRequest('max','GET','/messages/'.$mid,[],null);$out['owned_read_http']=(int)$api['http'];need(empty($api['transport_error'])&&(int)$api['http']===200&&is_array($api['data']),'original_owned_read_not_confirmed_no_retry');$snapshot=ChannelPostIncomingAdapter::maxSnapshot($api['data']);
  }
  need(is_array($snapshot)&&photoSignature($snapshot)===$plan['photo_signature']&&($snapshot['post_url']??'')===$plan['post_url']&&ChannelPostIncomingAdapter::containsOnlyButton($snapshot,ChannelPostButtonTransport::buttonUrl($p),'✈️ Узнать стоимость тура'),'original_owned_photo_source_button_not_exact');
  $out['provider_matches_new_body']=($snapshot['text']??'')===$r['new_text'];$out['provider_matches_old_body']=($snapshot['text']??'')===$r['old_text'];need($out['provider_matches_new_body'],'original_provider_not_new_expected_body_no_duplicate_write');
  $state['posts'][$key]['original_reconciliation_snapshot']=$snapshot;$state['posts'][$key]['original_reconciliation_observed_epoch_utc']=time();$state['posts'][$key]['reconciliation_phase']='owned_current_body_photo_button_verified_before_registry_ack';durable($lock,$state);
  if($wasOld){
   $snapshot['content_hash']=ChannelPostIncomingAdapter::contentFingerprint($snapshot,'',(string)$c['bot_url']);$snapshot['metadata']=array_replace($before['metadata']??[],$snapshot['metadata']??[]);$snapshot['metadata']['price_quote_response_sha256']=$m['quote_response_sha256'];$snapshot['metadata']['price_checked_epoch_utc']=(string)$m['quote_epoch_utc'];$snapshot['metadata']['source_event_id']=$event;$snapshot['metadata']['observation_kind']='authorized_admin_content_edit';$snapshot['metadata']['operation_observed_at_ms']=(string)(int)floor(microtime(true)*1000);$snapshot['metadata']['source_revision_ms']=(string)$member['edit_request_start_ms'];$snapshot['metadata']['revision_clock_origin']='authorized_edit_request_start';
   $p=$registry->registerPublication('anytour','max','id9704048781_1_bot','-80492244209683',$plan['message_id'],$snapshot);$out['own_original_registry_version_registered']=true;
  }
  need(is_array($p)&&($p['snapshot']['text']??'')===$r['new_text']&&($p['snapshot']['metadata']['source_event_id']??'')===$event&&empty($p['claim_token'])&&empty($p['claimed_at'])&&in_array($p['state'],['pending','sent'],true),'own_registry_ack_not_current_or_foreign_lease');
  if($p['state']==='pending'){need($registry->confirmObservedButton((int)$p['publication_id'],(int)$p['version_id'],['state'=>'verified_only_button','original_operation'=>$m['operation'],'verification'=>'original_content_write_owned_read_body_photo_button','observed_at_epoch_utc'=>time(),'content_sha256'=>hash('sha256',$r['new_text'])]),'own_observed_button_ack_failed');$out['own_original_registry_confirmation_written']=true;}
  $p=$registry->publication((int)$plan['publication_id']);need(is_array($p)&&$p['state']==='sent'&&($p['snapshot']['text']??'')===$r['new_text']&&photoSignature($p['snapshot'])===$plan['photo_signature']&&hash_equals((string)$p['start_payload'],(string)$plan['token'])&&($p['snapshot']['post_url']??'')===$plan['post_url'],'original_registry_final_context_not_verified');
  $resolved=ChannelPostEntryService::resolve('max',$plan['token'],$registry);need(is_array($resolved)&&(int)$resolved['version_id']===(int)$p['version_id']&&$resolved['source_key']==='max_anytour_msk1'&&($resolved['snapshot']['text']??'')===$r['new_text'],'original_token_current_new_version_not_verified');
  $receipt=safeResult($p,$plan);$state['posts'][$key]['state']='confirmed';$state['posts'][$key]['receipt']=$receipt;$state['posts'][$key]['original_write_reconciled_without_provider_write']=true;$state['posts'][$key]['original_content_write_attempts']=1;$state['posts'][$key]['reconciliation_phase']='confirmed_original_max_only_telegram_unattempted';durable($lock,$state);
  $out['max_original_write_confirmed']=true;$out['telegram_not_attempted']=true;$out['old_caption_photo_button_source_token_preserved']=true;$out['ok']=true;
 }
 $out['installed_sha']=EXPECTED_INSTALLED_SHA;$out['registry_acknowledgement_only_not_provider_retry']=true;$out['original_manifest_sha256']=$d['manifest_sha256'];
}catch(Throwable $e){$out['error']=preg_match('/\\A[a-z][a-z0-9_]{1,120}\\z/D',$e->getMessage())?$e->getMessage():'original273_reconciliation_stopped_no_provider_write';}
if(isset($lock)&&is_resource($lock)){flock($lock,LOCK_UN);fclose($lock);}
echo json_encode($out,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR),PHP_EOL;exit($out['ok']?0:2);
