<?php
declare(strict_types=1);

/** Pure input/receipt checks shared by the existing post editor and static-feed writer.
 * No supplier calls, database writes, credentials, scheduler or publication transport.
 */
function monitor_price_require(bool $ok,string $reason):void {
    if(!$ok)throw new RuntimeException($reason);
}
/** Bounded verifyChannel evidence only; never retain provider payloads, paths or identities. */
function monitor_price_channel_preflight(string $platform,array $verification):array {
    $states=['ready','unknown','requires_attention'];
    $denied=['channel_admin_required','channel_read_permission_required','channel_edit_permission_required'];
    $identity=['unsupported_platform','invalid_channel_id','canonical_bot_url_mismatch','invalid_expected_public_channel_url',
        'canonical_bot_identity_mismatch','membership_identity_mismatch','channel_type_mismatch','public_channel_identity_mismatch'];
    $reasons=array_merge(['capability_verified','provider_read_failed','verification_failure','request_budget_exhausted'],$denied,$identity);
    $state=in_array($verification['state']??null,$states,true)?$verification['state']:'unknown';
    $reason=in_array($verification['reason']??null,$reasons,true)?$verification['reason']:'unrecognized_verification_result';
    // Accept the same safe projection when carrying the child result into its parent journal.
    $receipt=array_key_exists('receipt',$verification)?$verification['receipt']:$verification;
    $receipt=is_array($receipt)?$receipt:[];$http=$receipt['http_results']??null;
    $reads=$receipt['request_count']??null;$writes=$receipt['write_attempts']??null;
    $out=['platform'=>in_array($platform,['max','telegram'],true)?$platform:'unknown','state'=>$state,'reason'=>$reason,
        'request_count'=>is_int($reads)&&$reads>=0&&$reads<=5?$reads:null,
        'write_attempts'=>is_int($writes)&&$writes>=0&&$writes<=1?$writes:null,
        'http_results'=>[],'http_results_truncated'=>($receipt['http_results_truncated']??false)===true||(is_array($http)&&count($http)>5)];
    $valid=is_array($http)&&array_is_list($http)&&!$out['http_results_truncated']&&$out['platform']!=='unknown'
        &&($verification['state']??null)===$state&&($verification['reason']??null)===$reason
        &&(!array_key_exists('receipt_valid',$receipt)||$receipt['receipt_valid']===true)
        &&$out['request_count']!==null&&$out['request_count']===count($http)&&$out['write_attempts']===0;
    foreach(is_array($http)?array_slice($http,0,5):[] as $item){
        $item=is_array($item)?$item:[];
        $operation=in_array($item['operation']??null,['bot_identity','channel_rights','channel_type'],true)?$item['operation']:'unknown';
        $status=$item['http']??null;$transport=$item['transport_error']??null;
        $status=is_int($status)&&$status>=0&&$status<=599?$status:null;$transport=is_bool($transport)?$transport:null;
        $valid=$valid&&$operation!=='unknown'&&$status!==null&&$transport!==null;
        $out['http_results'][]=['operation'=>$operation,'http'=>$status,'transport_error'=>$transport];
    }
    $error='provider_channel_verification_failed';
    if($state==='unknown'&&in_array($reason,['provider_read_failed','verification_failure'],true))$error='provider_channel_preflight_unavailable';
    elseif($state==='requires_attention'&&in_array($reason,$denied,true))$error='provider_channel_permission_denied';
    elseif($state==='requires_attention'&&in_array($reason,$identity,true))$error='provider_channel_identity_mismatch';
    elseif($state==='requires_attention'&&$reason==='request_budget_exhausted')$error='provider_channel_preflight_unavailable';
    elseif($state==='ready'&&$reason==='capability_verified'&&$valid)$error=null;
    $out['receipt_valid']=$valid;$out['error']=$error;return $out;
}
/** Exact compatibility for five accepted pre-batch publications; publisher null is a real role. */
function monitor_price_legacy_binding(array $row):array {
    $roles=[310=>null,469=>null,498=>228,1971=>null,1981=>877];
    $tv=$row['tourvisor_hotel_id']??null;$b=$row['legacy_proof']??null;
    $why='canonical_original_receipt_mismatch';
    monitor_price_require(is_int($tv)&&array_key_exists($tv,$roles)&&is_array($b)
        &&($b['schema_version']??null)===1&&($b['kind']??null)==='confirmed_legacy_price_receipt_v1'
        &&($b['native_hotel_id']??null)===$tv&&($b['expected_country_id']??null)===1
        &&array_key_exists('hotel_id',$row)&&$row['hotel_id']===$roles[$tv]
        &&array_key_exists('publisher_hotel_id',$b)&&$b['publisher_hotel_id']===$roles[$tv]
        &&($b['identity_provenance']??null)===($roles[$tv]===null?'accepted_native_catalog_read':'accepted_typed_bridge')
        &&!array_key_exists('original_publication_operation',$row)&&!array_key_exists('cover_sha256',$row)
        &&!array_key_exists('original_text_sha256',$row),'canonical_original_receipt_mismatch');
    $prior=$b['prior_price']??[];$contexts=$b['contexts']??[];
    monitor_price_require(($prior['operation']??null)==='commercial-price-update-20261105-legacy9-pass1-h'.$tv.'-v1'
        &&($prior['source_key']??null)==='legacy-'.$tv
        &&is_string($prior['manifest_hash']??null)&&preg_match('/\A[a-f0-9]{64}\z/D',$prior['manifest_hash'])===1
        &&is_string($prior['text_sha256']??null)&&preg_match('/\A[a-f0-9]{64}\z/D',$prior['text_sha256'])===1
        &&is_string($b['context_frame_sha256']??null)&&preg_match('/\A[a-f0-9]{64}\z/D',$b['context_frame_sha256'])===1
        &&is_array($contexts)&&count($contexts)===2&&isset($contexts['max'],$contexts['telegram'])
        &&($row['key']??null)==='prior-priced-search-link-'.$tv&&is_string($row['logical_id']??null),$why);
    foreach(['max','telegram'] as $platform){
        $p=$contexts[$platform];$source=$platform==='max'?'max_anytour_msk1':'tg_anytour_msk';
        monitor_price_require(is_array($p)&&is_int($p['publication_id']??null)&&$p['publication_id']>0
            &&is_int($p['version_id']??null)&&$p['version_id']>0
            &&is_int($p['baseline_version_id']??null)&&$p['baseline_version_id']>$p['version_id']
            &&($p['logical_id']??null)===$row['logical_id']&&($p['source_key']??null)===$source
            &&is_string($p['message_id']??null)&&preg_match('/\A[A-Za-z0-9_.-]{1,191}\z/D',$p['message_id'])===1
            &&is_string($p['photo_signature']??null)&&preg_match('/\A'. $platform.':[A-Za-z0-9_-]+\z/D',$p['photo_signature'])===1
            &&is_string($p['post_url']??null)&&($row['posts'][$platform]??null)===$p['post_url'],$why);
        foreach(['start_payload_sha256','button_url_sha256','baseline_text_sha256'] as $field)
            monitor_price_require(is_string($p[$field]??null)&&preg_match('/\A[a-f0-9]{64}\z/D',$p[$field])===1,$why);
        $prefix=$platform==='max'?'https://max.ru/channel_anytour/':'https://t.me/any_toour/';
        monitor_price_require(str_starts_with($p['post_url'],$prefix)
            &&preg_match('/\A[A-Za-z0-9_-]+\z/D',substr($p['post_url'],strlen($prefix)))===1,$why);
        if(array_key_exists('expected_text_sha256',$row))monitor_price_require(($row['expected_text_sha256'][$platform]??null)===$p['baseline_text_sha256'],$why);
    }
    monitor_price_require($contexts['max']['publication_id']!==$contexts['telegram']['publication_id'],$why);
    return $b;
}

/** Read-only parser for the original own price journal, not a replacement sender receipt. */
function monitor_price_legacy_receipts(array $row,string $raw):array {
    $b=monitor_price_legacy_binding($row);$prior=$b['prior_price'];$why='canonical_original_receipt_mismatch';
    monitor_price_require($raw!==''&&strlen($raw)<=10000000&&str_ends_with($raw,"\n"),'hook_legacy_source_incomplete_stop');
    $last=null;$completed=null;
    foreach(explode("\n",substr($raw,0,-1)) as $line){
        try{$entry=json_decode($line,true,64,JSON_THROW_ON_ERROR);}catch(Throwable $e){throw new RuntimeException('hook_legacy_source_incomplete_stop');}
        monitor_price_require(is_array($entry)&&($entry['operation']??null)===$prior['operation']
            &&($entry['manifest_hash']??null)===$prior['manifest_hash'],$why);
        monitor_price_require($completed===null||$completed===$entry,'hook_legacy_source_unresolved_stop');
        if(($entry['complete']??false)===true)$completed=$entry;
        $last=$entry;
    }
    monitor_price_require(($last['complete']??false)===true&&is_array($last['posts']??null)
        &&count($last['posts'])===2&&is_array($last['configs']??null),'hook_legacy_source_unresolved_stop');
    foreach($last['configs'] as $config)monitor_price_require(($config['state']??null)==='confirmed','hook_legacy_source_unresolved_stop');
    $receipts=[];
    foreach(['max','telegram'] as $platform){
        $member=$last['posts'][$platform.':'.$prior['source_key']]??[];$r=$member['receipt']??[];$c=$b['contexts'][$platform];
        monitor_price_require(($member['state']??null)==='confirmed','hook_legacy_source_unresolved_stop');
        foreach(['publication_id','message_id','version_id','logical_id','source_key','post_url','photo_signature'] as $field)
            monitor_price_require(($r[$field]??null)===$c[$field],$why);
        monitor_price_require(($r['platform']??null)===$platform&&($r['state']??null)==='sent'
            &&($r['body_photo_button_verified']??null)===true&&($r['existing_message_reused']??null)===true
            &&($r['text_sha256']??null)===$prior['text_sha256']&&is_string($r['button_url']??null)
            &&hash_equals($c['button_url_sha256'],hash('sha256',$r['button_url'])),$why);
        $receipts[$platform]=$r;
    }
    return $receipts;
}

/** Immutable physical identity/photo/token; initial caption is pinned separately from later monitor receipts. */
function monitor_price_legacy_publication_matches(array $p,array $row,string $platform,string $buttonUrl):bool {
    $b=monitor_price_legacy_binding($row);$c=$b['contexts'][$platform]??null;$s=$p['snapshot']??[];
    if(!is_array($c))return false;
    $media=$s['attachments']??[];$m=$media[0]??[];
    if(!is_array($media)||count($media)!==1||($m['type']??null)!=='image')return false;
    $photo=(string)($m['provider']??'').':'.(string)($m['photo_id']??$m['file_unique_id']??'');
    return ($p['project_key']??null)==='anytour'&&($p['platform']??null)===$platform&&($p['state']??null)==='sent'
        &&($p['bot_key']??null)===($platform==='max'?'id9704048781_1_bot':'anyru_bot')
        &&($p['channel_id']??null)===($platform==='max'?'-80492244209683':'-1002262666616')
        &&(int)($p['publication_id']??0)===$c['publication_id']&&($p['external_message_id']??null)===$c['message_id']
        &&($p['source_key']??null)===$c['source_key']&&($s['post_url']??null)===$c['post_url']
        &&empty($p['claim_token'])&&empty($p['claimed_at'])&&$photo===$c['photo_signature']
        &&is_string($p['start_payload']??null)&&$p['start_payload']!==''
        &&hash_equals($c['start_payload_sha256'],hash('sha256',$p['start_payload']))
        &&hash_equals($c['button_url_sha256'],hash('sha256',$buttonUrl));
}
function monitor_price_legacy_initial_caption_matches(array $p,array $row,string $platform):bool {
    $b=monitor_price_legacy_binding($row);$c=$b['contexts'][$platform]??null;$s=$p['snapshot']??[];
    return is_array($c)&&($s['metadata']['monitor_price_record_id']??null)===null
        &&(int)($p['publication_id']??0)===$c['publication_id']&&(int)($p['version_id']??0)===$c['baseline_version_id']
        &&is_string($s['text']??null)&&hash_equals($c['baseline_text_sha256'],hash('sha256',$s['text']));
}
/** The original caption editor used manifest_sha256, never a publisher hotel identity. */
function monitor_price_caption_source(array $source):void {
    $pins=[
        'commercial-content-quality-20261108-cohort159-batch002-v1'=>'323ae3b92705ab9b97035227da5df7353f942b1790aae087795607e8d8e34403',
        'commercial-content-quality-20261108-cohort159-batch006-v1'=>'f8e313cbdd57bd7df1431cc5301c4d9bb6839dfcfa24b0dcc44cbe8e4ad3bd78',
    ];
    monitor_price_require(count($source)===4&&is_string($source['operation']??null)&&isset($pins[$source['operation']])
        &&($source['manifest_sha256']??null)===$pins[$source['operation']]
        &&($source['installed_sha']??null)==='160e019701165cd53dd5a9be0fa3db533d82120e'
        &&($source['row_count']??null)===50,'canonical_original_receipt_mismatch');
}
function monitor_price_caption_receipt_fields():array {
    return ['hotel_id','platform','publication_id','previous_version_id','version_id','message_id','post_url',
        'text_sha256','previous_text_sha256','photo_signature','previous_attachments_sha256','attachments_sha256',
        'button_url_sha256','button_text','source_key','state','body_photo_button_verified','formatting_verified',
        'existing_message_reused','existing_token_resolves_to_current_version'];
}
/** Only these three imported pairs lack an original sender; native identity is independently pinned. */
function monitor_price_caption_binding(array $row):array {
    $scope=[381=>['rehana','at-hotel-rehana',71,104,'002'],67910=>['rixos','at-hotel-rixos',70,101,'006'],68323=>['solara','at-hotel-solara',72,105,'006']];
    $tv=$row['tourvisor_hotel_id']??null;$b=$row['caption_proof']??null;$why='canonical_original_receipt_mismatch';
    monitor_price_require(is_int($tv)&&isset($scope[$tv])&&is_array($b)
        &&($b['schema_version']??null)===1&&($b['kind']??null)==='confirmed_caption_receipt_v1'
        &&($b['native_hotel_id']??null)===$tv&&array_key_exists('hotel_id',$row)&&$row['hotel_id']===null
        &&array_key_exists('publisher_hotel_id',$b)&&$b['publisher_hotel_id']===null
        &&($b['identity_provenance']??null)==='accepted_native592_post_binding'
        &&($b['native_projection_sha256']??null)==='f017b9445949557199053b852cec3881bfeb0c36602aef8ad18f9db55cdcb19f'
        &&($row['key']??null)===$scope[$tv][0]&&($row['logical_id']??null)===$scope[$tv][1],$why);
    foreach(['original_publication_operation','original_text_sha256','cover_sha256','legacy_proof'] as $field)
        monitor_price_require(!array_key_exists($field,$row),$why);
    monitor_price_require(is_array($b['source']??null),$why);monitor_price_caption_source($b['source']);
    monitor_price_require($b['source']['operation']==='commercial-content-quality-20261108-cohort159-batch'.$scope[$tv][4].'-v1'
        &&is_array($b['contexts']??null)&&count($b['contexts'])===2&&isset($b['contexts']['max'],$b['contexts']['telegram']),$why);
    foreach(['max','telegram'] as $i=>$platform){
        $c=$b['contexts'][$platform];
        monitor_price_require(is_array($c)&&count($c)===count(monitor_price_caption_receipt_fields())
            &&count(array_diff(monitor_price_caption_receipt_fields(),array_keys($c)))===0
            &&$c['hotel_id']===$tv&&$c['platform']===$platform&&$c['publication_id']===$scope[$tv][$i+2]
            &&is_int($c['previous_version_id'])&&$c['previous_version_id']>0&&is_int($c['version_id'])&&$c['version_id']>$c['previous_version_id']
            &&is_string($c['message_id'])&&preg_match('/\A[A-Za-z0-9_.-]{1,191}\z/D',$c['message_id'])===1
            &&is_string($c['post_url'])&&($row['posts'][$platform]??null)===$c['post_url']
            &&is_string($c['photo_signature'])&&preg_match('/\A'.$platform.':[A-Za-z0-9_-]+\z/D',$c['photo_signature'])===1
            &&$c['source_key']===($platform==='max'?'max_anytour_msk1':'tg_anytour_msk')
            &&$c['state']==='sent'&&$c['button_text']==='✈️ Узнать стоимость тура',$why);
        foreach(['text_sha256','previous_text_sha256','previous_attachments_sha256','attachments_sha256','button_url_sha256'] as $field)
            monitor_price_require(is_string($c[$field])&&preg_match('/\A[a-f0-9]{64}\z/D',$c[$field])===1,$why);
        foreach(['body_photo_button_verified','formatting_verified','existing_message_reused','existing_token_resolves_to_current_version'] as $field)
            monitor_price_require($c[$field]===true,$why);
        $prefix=$platform==='max'?'https://max.ru/channel_anytour/':'https://t.me/any_toour/';
        monitor_price_require(str_starts_with($c['post_url'],$prefix)&&preg_match('/\A[A-Za-z0-9_-]+\z/D',substr($c['post_url'],strlen($prefix)))===1
            &&($platform!=='telegram'||$c['post_url']===$prefix.$c['message_id']),$why);
        if(array_key_exists('expected_text_sha256',$row))monitor_price_require(($row['expected_text_sha256'][$platform]??null)===$c['text_sha256'],$why);
    }
    return $b;
}
/** Read a caller-owned locked stream; no paths, writes or network. Cache the returned terminal by source pins. */
function monitor_price_caption_journal($stream,array $source):array {
    monitor_price_caption_source($source);$why='hook_caption_source_incomplete_stop';
    monitor_price_require(is_resource($stream)&&get_resource_type($stream)==='stream'&&ftell($stream)===0,$why);
    $stat=fstat($stream);monitor_price_require(is_array($stat)&&is_int($stat['size']??null)&&$stat['size']>0&&$stat['size']<=134217728,$why);
    $bytes=0;$last=null;$terminal=null;
    while(($line=fgets($stream))!==false){
        $bytes+=strlen($line);monitor_price_require($bytes<=134217728&&str_ends_with($line,"\n"),$why);
        try{$entry=json_decode($line,true,128,JSON_THROW_ON_ERROR);}catch(Throwable $e){throw new RuntimeException($why);}
        monitor_price_require(is_array($entry),'canonical_original_receipt_mismatch');
        foreach($source as $field=>$value)monitor_price_require(($entry[$field]??null)===$value,'canonical_original_receipt_mismatch');
        monitor_price_require(is_bool($entry['complete']??null)&&is_array($entry['posts']??null)&&count($entry['posts'])<=$source['row_count']
            &&empty($entry['error']),'hook_caption_source_unresolved_stop');
        monitor_price_require($terminal===null||$terminal===$entry,'hook_caption_source_unresolved_stop');
        foreach($entry['posts'] as $post)monitor_price_require(is_array($post)&&in_array($post['state']??null,['edit_started','provider_response_recorded','text_edit_returned','confirmed'],true),'hook_caption_source_unresolved_stop');
        if($entry['complete'])$terminal=$entry;
        $last=$entry;
    }
    monitor_price_require(feof($stream)&&$bytes===$stat['size'],$why);
    monitor_price_require(is_array($last)&&($last['complete']??false)===true&&count($last['posts'])===$source['row_count'],'hook_caption_source_unresolved_stop');
    foreach($last['posts'] as $key=>$post){$r=$post['receipt']??[];
        monitor_price_require(($post['state']??null)==='confirmed'&&is_array($r)&&($r['state']??null)==='sent'
            &&in_array($r['platform']??null,['max','telegram'],true)&&is_int($r['publication_id']??null)&&$r['publication_id']>0
            &&$key===$r['platform'].':'.$r['publication_id'],'hook_caption_source_unresolved_stop');
        foreach(['body_photo_button_verified','formatting_verified','existing_message_reused','existing_token_resolves_to_current_version'] as $field)
            monitor_price_require(($r[$field]??null)===true,'hook_caption_source_unresolved_stop');
    }
    return $last;
}
/** Select the exact pair from the original full terminal, retaining only a hash of its saved token. */
function monitor_price_caption_receipts(array $row,array $journal):array {
    $b=monitor_price_caption_binding($row);$why='canonical_original_receipt_mismatch';
    foreach($b['source'] as $field=>$value)monitor_price_require(($journal[$field]??null)===$value,$why);
    monitor_price_require(($journal['complete']??false)===true&&is_array($journal['posts']??null)&&count($journal['posts'])===$b['source']['row_count'],'hook_caption_source_unresolved_stop');
    $receipts=[];
    foreach(['max','telegram'] as $platform){
        $c=$b['contexts'][$platform];$post=$journal['posts'][$platform.':'.$c['publication_id']]??[];$r=$post['receipt']??[];$plan=$post['plan']??[];
        monitor_price_require(($post['state']??null)==='confirmed'&&is_array($r)&&count($r)===count($c)&&is_array($plan),$why);
        foreach($c as $field=>$value)monitor_price_require(array_key_exists($field,$r)&&$r[$field]===$value,$why);
        foreach(['hotel_id','platform','publication_id','message_id','post_url','source_key','photo_signature','button_url_sha256','button_text'] as $field)
            monitor_price_require(($plan[$field]??null)===$c[$field],$why);
        monitor_price_require(($plan['version_id']??null)===$c['previous_version_id']
            &&($plan['new_text_sha256']??null)===$c['text_sha256']&&($plan['old_text_sha256']??null)===$c['previous_text_sha256']
            &&($plan['attachments_sha256']??null)===$c['previous_attachments_sha256']
            &&is_string($plan['new_text']??null)&&hash_equals($c['text_sha256'],hash('sha256',$plan['new_text']))
            &&is_string($plan['old_text']??null)&&hash_equals($c['previous_text_sha256'],hash('sha256',$plan['old_text']))
            &&is_string($plan['token']??null)&&preg_match('/\Ap_[A-Za-z0-9_-]{8,60}\z/D',$plan['token'])===1
            &&is_string($plan['button_url']??null)&&hash_equals($c['button_url_sha256'],hash('sha256',$plan['button_url']))
            &&str_ends_with($plan['button_url'],'?start='.rawurlencode($plan['token'])),$why);
        $r['start_payload_sha256']=hash('sha256',$plan['token']);$receipts[$platform]=$r;
    }
    return $receipts;
}
function monitor_price_caption_publication_matches(array $p,array $row,string $platform,string $buttonUrl,array $sourceReceipt):bool {
    $b=monitor_price_caption_binding($row);$c=$b['contexts'][$platform]??null;$s=$p['snapshot']??[];
    if(!is_array($c))return false;
    foreach($c as $field=>$value)if(($sourceReceipt[$field]??null)!==$value)return false;
    $media=$s['attachments']??[];$m=$media[0]??[];
    if(!is_array($media)||count($media)!==1||($m['type']??null)!=='image')return false;
    $photo=(string)($m['provider']??'').':'.(string)($m['photo_id']??$m['file_unique_id']??'');
    return ($p['project_key']??null)==='anytour'&&($p['platform']??null)===$platform&&($p['state']??null)==='sent'
        &&($p['bot_key']??null)===($platform==='max'?'id9704048781_1_bot':'anyru_bot')
        &&($p['channel_id']??null)===($platform==='max'?'-80492244209683':'-1002262666616')
        &&(int)($p['publication_id']??0)===$c['publication_id']&&($p['external_message_id']??null)===$c['message_id']
        &&($p['source_key']??null)===$c['source_key']&&($s['post_url']??null)===$c['post_url']&&$photo===$c['photo_signature']
        &&empty($p['claim_token'])&&empty($p['claimed_at'])&&is_string($p['start_payload']??null)
        &&is_string($sourceReceipt['start_payload_sha256']??null)
        &&hash_equals($sourceReceipt['start_payload_sha256'],hash('sha256',$p['start_payload']))
        &&hash_equals($c['button_url_sha256'],hash('sha256',$buttonUrl));
}
function monitor_price_caption_initial_caption_matches(array $p,array $row,string $platform):bool {
    $b=monitor_price_caption_binding($row);$c=$b['contexts'][$platform]??null;$s=$p['snapshot']??[];
    return is_array($c)&&($s['metadata']['monitor_price_record_id']??null)===null
        &&(int)($p['publication_id']??0)===$c['publication_id']&&(int)($p['version_id']??0)===$c['version_id']
        &&is_string($s['text']??null)&&hash_equals($c['text_sha256'],hash('sha256',$s['text']));
}
function monitor_price_time($value):int {
    monitor_price_require(is_string($value)&&preg_match('/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+-]\d{2}:\d{2})\z/D',$value)===1,'monitor_time_invalid');
    $time=strtotime($value);monitor_price_require($time!==false,'monitor_time_invalid');return $time;
}
function monitor_price_observation(array $snapshot,int $tourvisorId,int $now,bool $requireExportFresh=true):array {
    monitor_price_require(($snapshot['format']??null)==='anytour-hotel-monitor-prices-v1'
        &&($snapshot['identityNamespace']??null)==='tourvisor'&&($snapshot['source']??null)==='scheduled_monitor'
        &&($snapshot['observationWindowHours']??null)===24&&($snapshot['supplierRequests']??null)===0&&($snapshot['writes']??null)===0
        &&($snapshot['timeBasis']??null)==='database-relative; stored observation timezone is not asserted','monitor_source_invalid');
    $source=parse_url((string)($snapshot['sourceUrl']??''));
    monitor_price_require(is_array($source)&&($source['scheme']??'')==='https'&&($source['host']??'')==='anytoour.ru'
        &&($source['path']??'')==='/data/hotel-monitor-prices-read-v1.php'&&!isset($source['user'])&&!isset($source['pass'])&&!isset($source['port'])&&!isset($source['fragment']),'monitor_source_url_invalid');
    $scope=$snapshot['scope']??[];$item=$snapshot['item']??[];$offers=$item['offers']??[];
    monitor_price_require($tourvisorId>0&&in_array($tourvisorId,$scope['tourvisorHotelIds']??[],true)&&($item['tourvisorHotelId']??null)===$tourvisorId
        &&($item['state']??null)==='observed'&&count($offers)>=1&&count($offers)<=3,'monitor_identity_invalid');
    monitor_price_require(($scope['departureId']??null)===1&&($scope['adults']??null)===2&&($scope['childrenCount']??null)===0
        &&($scope['currency']??null)==='RUB'&&($scope['nightsFrom']??null)===5&&($scope['nightsTo']??null)===14,'monitor_scope_invalid');
    $generated=monitor_price_time($snapshot['generatedAt']??null);$previous=0.;$seen=[];
    foreach($offers as $offer){
        $price=$offer['price']??null;$id=$offer['recordId']??null;
        monitor_price_require((is_int($price)||is_float($price))&&is_finite((float)$price)&&$price>0&&$price>=$previous
            &&abs($price*100-round($price*100))<0.00001&&is_string($id)&&preg_match('/\A[a-f0-9]{64}\z/D',$id)===1&&!isset($seen[$id]),'monitor_price_invalid');
        $previous=(float)$price;$seen[$id]=true;
        monitor_price_require(($offer['tourvisorHotelId']??null)===$tourvisorId&&($offer['departureId']??null)===1
            &&($offer['adults']??null)===2&&($offer['childrenCount']??null)===0&&($offer['currency']??null)==='RUB'
            &&is_int($offer['nights']??null)&&$offer['nights']>=5&&$offer['nights']<=14
            &&is_string($offer['tourId']??null)&&$offer['tourId']!==''&&is_string($offer['roomType']??null)&&trim($offer['roomType'])!==''
            &&isset(monitor_price_meals()[$offer['mealId']??0]),'monitor_terms_invalid');
        $day=$offer['departureDate']??'';
        monitor_price_require(is_string($day)&&preg_match('/\A\d{4}-\d{2}-\d{2}\z/D',$day)===1
            &&gmdate('Y-m-d',strtotime($day)?:0)===$day&&$day>=($scope['dateFrom']??'9999')&&$day<=($scope['dateTo']??''),'monitor_departure_invalid');
        foreach(['currentPriceVerified','fullPackagePriceVerified','availabilityVerified'] as $flag)monitor_price_require(($offer[$flag]??null)===false,'monitor_verification_claim_invalid');
        monitor_price_require(array_key_exists('fuelIncluded',$offer)&&$offer['fuelIncluded']===null
            &&($offer['source']??null)==='scheduled_monitor'&&($offer['priceKind']??null)==='listing_observation','monitor_price_basis_invalid');
        $age=$offer['ageSecondsAtExport']??null;$expiry=monitor_price_time($offer['expiresAt']??null);
        monitor_price_require(is_int($age)&&$age>=0&&$age<86400&&$generated<=$now+60&&$age+max(0,$now-$generated)<86400
            &&$expiry>=$generated&&$expiry-$generated<=min(900,86400-$age)&&(!$requireExportFresh||$expiry>$now),'monitor_price_expired');
    }
    $minimum=$offers[0];
    return ['record_id'=>$minimum['recordId'],'tourvisor_hotel_id'=>$tourvisorId,'tour_id'=>$minimum['tourId'],'price'=>$minimum['price'],'currency'=>'RUB',
        'observed_at'=>gmdate('Y-m-d\TH:i:s\Z',$generated-$minimum['ageSecondsAtExport']),
        'price_basis'=>'scheduled_monitor_result','fuel_added_separately'=>false,'full_package_price_verified'=>false,'availability_verified'=>false,'offer'=>$minimum];
}
function monitor_price_meals():array {return [2=>'Без питания',3=>'Завтраки',4=>'Завтрак и ужин',5=>'Полный пансион',7=>'Всё включено',9=>'Ультра всё включено'];}
function monitor_price_amount($price):string {return rtrim(rtrim(number_format((float)$price,2,'.',''),'0'),'.');}
function monitor_price_operation(array $price):string {return 'commercial-monitor-price-h'.$price['tourvisor_hotel_id'].'-'.$price['record_id'];}
function monitor_price_validate_text(string $text,array $price):void {
    $o=$price['offer'];$normal=str_replace(["\u{00a0}","\u{202f}"],' ',$text);
    $amount=number_format((float)$price['price'],(floor((float)$price['price'])===(float)$price['price'])?0:2,',',' ');
    $amount=rtrim(str_contains($amount,',')?rtrim($amount,'0'):$amount,',');
    $day=implode('.',array_reverse(explode('-',$o['departureDate'])));$n=$o['nights'];$night=$n>=11&&$n<=14?'ночей':($n%10===1?'ночь':(in_array($n%10,[2,3,4],true)?'ночи':'ночей'));
    $block="💰 От $amount ₽ за двоих\n✈️ Вылет из Москвы $day · $n $night\n👥 2 взрослых\n🛏 ".$o['roomType']."\n🍽 ".monitor_price_meals()[$o['mealId']];
    $closings=[
        140=>'✈️ Подберём категорию под состав семьи и рассчитаем тур в Golden Beach с перелётом на ваши даты.',
        386=>'✈️ Подберём категорию и расположение номера, рассчитаем тур с перелётом на ваши даты.',
        1033=>'✈️ Подберём семейный номер в Barut Hemera и рассчитаем тур с перелётом на ваши даты.',
        1095=>'✈️ Подберём тур в Side Star Resort с перелётом и подходящим номером. Рассчитаем полную стоимость на вашу семью.',
        8053=>'✈️ Подберём номер и питание, рассчитаем тур с перелётом на ваши даты.',
        33313=>'✈️ Подберём номер и питание в Sugar Marina Surf, рассчитаем тур с перелётом на ваши даты и состав семьи.',
        58987=>'✈️ Подберём номер и питание в Seashells, рассчитаем тур с перелётом на ваши даты.',
    ];
    $ctaCount=substr_count($normal,'Узнать стоимость тура')
        +substr_count($normal,'✈️ Подберём подходящий номер и рассчитаем тур с перелётом на ваши даты.');
    $closingAllowed=true;
    foreach($closings as $tv=>$closing){$n=substr_count($normal,$closing);$ctaCount+=$n;
        if($n>0&&(($price['tourvisor_hotel_id']??null)!==$tv||!str_ends_with($normal,"\n\n".$closing)))$closingAllowed=false;
    }
    monitor_price_require(preg_match('/\A✈️ Туры в [^\r\n]+\n/u',$normal)===1&&!preg_match('/с перел[её]том/u',explode("\n",$normal)[0])
        &&substr_count($normal,$block)===1&&substr_count($normal,'💰')===1&&$ctaCount===1&&$closingAllowed
        &&str_contains($normal,'Цена найдена мониторингом '),'monitor_text_conditions_mismatch');
    monitor_price_require(mb_strlen($text,'UTF-8')>0&&strlen(mb_convert_encoding($text,'UTF-16LE','UTF-8'))/2<=1024,'monitor_caption_too_long');
}
/** Feed accepts only a completed two-channel journal from the existing editor. */
function monitor_price_confirmed_journal(array $journal,array $binding,int $now):array {
    monitor_price_require(($journal['complete']??false)===true&&isset($journal['monitor_snapshot'])&&is_array($journal['monitor_snapshot']),'monitor_posts_not_confirmed');
    $price=monitor_price_observation($journal['monitor_snapshot'],(int)($binding['tourvisor_hotel_id']??0),$now,false);
    monitor_price_require(($journal['operation']??'')===monitor_price_operation($price)&&($binding['operation']??'')===$journal['operation']
        &&is_string($binding['manifest_hash']??null)&&hash_equals($journal['manifest_hash']??'',$binding['manifest_hash']),'monitor_journal_binding_changed');
    $expectedBinding=$binding;unset($expectedBinding['operation'],$expectedBinding['manifest_hash']);
    monitor_price_require(($journal['feed_binding']??null)===$expectedBinding,'monitor_feed_binding_changed');
    $receipts=[];
    foreach($journal['posts']??[] as $post){
        $r=$post['receipt']??[];$channel=$r['platform']??'';
        monitor_price_require(($post['state']??'')==='confirmed'&&in_array($channel,['max','telegram'],true)&&!isset($receipts[$channel])
            &&($r['state']??'')==='sent'&&($r['existing_message_reused']??false)===true&&($r['body_photo_button_verified']??false)===true
            &&($r['monitor_price']??null)===$price&&is_string($r['text']??null)&&hash('sha256',$r['text'])===($r['text_sha256']??'')
            &&($r['post_url']??'')===($binding['posts'][$channel]??null),'monitor_receipt_mismatch');
        monitor_price_validate_text($r['text'],$price);$receipts[$channel]=$r;
    }
    monitor_price_require(count($receipts)===2,'monitor_posts_not_confirmed');
    return ['price'=>$price,'binding'=>$binding,'receipts'=>$receipts];
}
/** Exact patch of target offer blocks. Other URLs, IDs, photos and blocks stay byte-identical. */
function monitor_price_render_feed(string $xml,array $updates,string $name,int $at):string {
    monitor_price_require(in_array($name,['hotel-posts-max.xml','hotel-posts-telegram.xml','hotel-posts-all.xml'],true)
        &&stripos($xml,'<!DOCTYPE')===false&&stripos($xml,'<!ENTITY')===false&&stripos($xml,'available=')===false,'monitor_feed_invalid');
    $dom=new DOMDocument();$prior=libxml_use_internal_errors(true);
    try{$loaded=$dom->loadXML($xml,LIBXML_NONET);}finally{libxml_clear_errors();libxml_use_internal_errors($prior);}
    monitor_price_require($loaded&&$dom->documentElement?->tagName==='yml_catalog','monitor_feed_invalid');
    $elements=[];foreach($dom->getElementsByTagName('offer') as $e){$id=$e->getAttribute('id');monitor_price_require($id!==''&&!isset($elements[$id]),'monitor_feed_duplicate');$elements[$id]=$e;}
    $targets=[];
    foreach($updates as $update)foreach(['max','telegram'] as $channel){
        if(($name==='hotel-posts-max.xml'&&$channel!=='max')||($name==='hotel-posts-telegram.xml'&&$channel!=='telegram'))continue;
        $b=$update['binding'];$id=$b['offer_ids'][$channel]??'';$url=$b['destinations'][$channel]??'';
        monitor_price_require(is_string($id)&&preg_match('/\A'.($channel==='max'?'max':'tg').'_[A-Za-z0-9_-]+\z/D',$id)===1&&!isset($targets[$id])&&isset($elements[$id]),'monitor_feed_target_invalid');
        $e=$elements[$id];$urls=$e->getElementsByTagName('url');
        monitor_price_require($urls->length===1&&$urls->item(0)->textContent===$url&&$e->getElementsByTagName('description')->length===1
            &&$e->getElementsByTagName('price')->length<=1&&$e->getElementsByTagName('currencyId')->length<=1,'monitor_feed_destination_changed');
        $text=$update['receipts'][$channel]['text'];$targets[$id]=['price'=>$update['price']['price'],'text'=>$text];
    }
    monitor_price_require(count($targets)>0,'monitor_feed_targets_empty');$found=[];
    $next=preg_replace_callback('/<offer id="([A-Za-z0-9_-]+)">.*?<\/offer>/s',static function(array $m)use($targets,&$found):string{
        $id=$m[1];if(!isset($targets[$id]))return $m[0];monitor_price_require(!isset($found[$id]),'monitor_feed_duplicate');$found[$id]=true;
        $block=preg_replace('/\s*<(price|currencyId|description)>.*?<\/\1>/s','',$m[0]);
        monitor_price_require(is_string($block)&&substr_count($block,'</url>')===1,'monitor_feed_block_invalid');
        $value=$targets[$id];$addition="\n        <price>".monitor_price_amount($value['price'])."</price>\n        <currencyId>RUB</currencyId>\n        <description>".htmlspecialchars($value['text'],ENT_XML1|ENT_QUOTES,'UTF-8').'</description>';
        return str_replace('</url>','</url>'.$addition,$block);
    },$xml);
    monitor_price_require(is_string($next)&&count($found)===count($targets),'monitor_feed_target_not_patched');
    $date=(new DateTimeImmutable('@'.$at))->setTimezone(new DateTimeZone('Europe/Moscow'))->format('Y-m-d H:i');
    $next=preg_replace('/<yml_catalog date="[^"<>]+">/','<yml_catalog date="'.$date.'">',$next,1,$changed);
    monitor_price_require(is_string($next)&&$changed===1,'monitor_feed_date_invalid');return $next;
}

