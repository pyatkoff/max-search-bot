<?php
declare(strict_types=1);
require_once __DIR__.'/monitor-price-contract.php';

/** Pure preparation for the existing editor; no search, transport or storage. */
function monitor_hook_draft(string $template,array $price):string {
    $slot='💰 [Минимальная актуальная цена тура — подтвердить перед выпуском]';
    $terms='[Город вылета · даты · ночи · состав туристов · номер · питание · время проверки]';
    monitor_price_require(substr_count($template,$slot)===1&&substr_count($template,$terms)===1,'hook_template_changed');
    $o=$price['offer'];$n=$o['nights'];
    $night=$n>=11&&$n<=14?'ночей':($n%10===1?'ночь':(in_array($n%10,[2,3,4],true)?'ночи':'ночей'));
    $money=number_format((float)$o['price'],floor((float)$o['price'])===(float)$o['price']?0:2,',',' ');
    if(str_contains($money,','))$money=rtrim(rtrim($money,'0'),',');
    $block='💰 От '.$money." ₽ за двоих\n✈️ Вылет из Москвы ".implode('.',array_reverse(explode('-',$o['departureDate']))).' · '.$n.' '.$night."\n👥 2 взрослых\n🛏 ".$o['roomType']."\n🍽 ".monitor_price_meals()[$o['mealId']];
    $stamp=(new DateTimeImmutable($price['observed_at']))->setTimezone(new DateTimeZone('Europe/Moscow'))->format('d.m.Y, H:i');
    $lines=explode("\n",$template);
    foreach($lines as $i=>$line)if($i>=2&&preg_match('/all\s*inclusive|вс[её]\s+включено/iu',$line))unset($lines[$i]);
    $text=str_replace([$slot,$terms],[$block,'Цена найдена мониторингом '.$stamp.' МСК. Стоимость и наличие могут измениться.'],implode("\n",$lines));
    $text=preg_replace('/\n{3,}/',"\n\n",$text);monitor_price_validate_text($text,$price);return $text;
}

/** An explicit two-channel editorial baseline is exact, never a blanket manual-edit waiver. */
function monitor_hook_caption_correction_allowed(array $publication,array $row,string $platform):bool {
    $baselines=$row['caption_correction_baseline']??null;
    if(!is_array($baselines)||count($baselines)!==2||!isset($baselines['max'],$baselines['telegram'])
        ||!in_array($platform,['max','telegram'],true))return false;
    foreach($baselines as $baseline){
        if(!is_array($baseline)||count($baseline)!==4
            ||!is_int($baseline['publication_id']??null)||$baseline['publication_id']<=0
            ||!is_int($baseline['version_id']??null)||$baseline['version_id']<=0
            ||!is_string($baseline['text_sha256']??null)||preg_match('/\A[a-f0-9]{64}\z/D',$baseline['text_sha256'])!==1
            ||!is_string($baseline['operation']??null)||preg_match('/\Acommercial-[a-z0-9-]+-v[1-9][0-9]*\z/D',$baseline['operation'])!==1)return false;
    }
    if($baselines['max']['operation']!==$baselines['telegram']['operation']
        ||$baselines['max']['publication_id']===$baselines['telegram']['publication_id'])return false;
    $baseline=$baselines[$platform];$s=$publication['snapshot']??[];
    return $baseline['publication_id']===(int)($publication['publication_id']??0)
        &&$baseline['version_id']===(int)($publication['version_id']??0)
        &&is_string($s['text']??null)&&hash_equals($baseline['text_sha256'],hash('sha256',$s['text']))
        &&($s['metadata']['caption_correction_operation']??null)===$baseline['operation'];
}

/** A later automated edit still needs its completed original monitor journal. */
function monitor_hook_caption_allowed(array $publication,array $row,string $platform,?array $prior):bool {
    $s=$publication['snapshot']??[];$hash=hash('sha256',$s['text']??'');
    $record=$s['metadata']['monitor_price_record_id']??null;
    if($record===null&&array_key_exists('caption_proof',$row))return monitor_price_caption_initial_caption_matches($publication,$row,$platform);
    if($record===null&&array_key_exists('legacy_proof',$row))return monitor_price_legacy_initial_caption_matches($publication,$row,$platform);
    if($record===null)return !array_key_exists('caption_correction_baseline',$row)
        &&hash_equals($row['expected_text_sha256'][$platform],$hash);
    if(!is_string($record)||!preg_match('/\A[a-f0-9]{64}\z/D',$record)||!$prior||($prior['complete']??false)!==true
        ||($prior['operation']??'')!=='commercial-monitor-price-h'.$row['tourvisor_hotel_id'].'-'.$record
        ||($prior['feed_binding']??null)!==$row['feed_binding'])return false;
    foreach($prior['posts']??[] as $post){$r=$post['receipt']??[];
        if(($post['state']??'')==='confirmed'&&($r['platform']??'')===$platform){
            if(($r['publication_id']??null)!==(int)($publication['publication_id']??0)
                ||($r['message_id']??null)!==($publication['external_message_id']??null)||($r['post_url']??null)!==($s['post_url']??null)
                ||($r['existing_message_reused']??false)!==true||($r['body_photo_button_verified']??false)!==true)return false;
            if(($r['version_id']??null)===(int)($publication['version_id']??0)
                &&($r['text_sha256']??null)===$hash&&($r['text']??null)===($s['text']??null))return true;
            // Preserve the old price proof; never admit an absent, UNKNOWN or corrupt journal.
            return ($r['state']??null)==='sent'&&is_int($r['version_id']??null)&&$r['version_id']>0
                &&is_string($r['text']??null)&&is_string($r['text_sha256']??null)
                &&hash_equals(hash('sha256',$r['text']),$r['text_sha256'])
                &&($r['monitor_price']['record_id']??null)===$record
                &&monitor_hook_caption_correction_allowed($publication,$row,$platform);
        }
    }return false;
}

function monitor_hook_feed_operation(string $postOperation,string $date):string {
    // Stable across retries; within the existing feed writer's operation grammar.
    return 'commercial-channel-hotel-feeds-'.$date.'-update'.(string)hexdec(substr(hash('sha256',$postOperation),0,14));
}

function monitor_hook_source(array $payload,int $tv,string $url):array {
    monitor_price_require(($payload['ok']??false)===true,'hook_monitor_read_failed');
    $items=$payload['items']??[];$matches=array_values(array_filter($items,static fn($x)=>($x['tourvisorHotelId']??null)===$tv));
    monitor_price_require(count($matches)===1,'hook_monitor_identity_missing');
    unset($payload['ok'],$payload['items']);$payload['item']=$matches[0];$payload['sourceUrl']=$url;return $payload;
}

/** Oldest successful edit last; unprocessed hotels cannot starve behind the pilot. */
function monitor_hook_order(array $rows,array $completed):array {
    usort($rows,static function(array $a,array $b)use($completed):int{
        $aAt=strtotime($completed[$a['tourvisor_hotel_id']]['at']??'1970-01-01T00:00:00Z');
        $bAt=strtotime($completed[$b['tourvisor_hotel_id']]['at']??'1970-01-01T00:00:00Z');
        return ($aAt<=>$bAt)?:($a['tourvisor_hotel_id']<=>$b['tourvisor_hotel_id']);
    });return $rows;
}

function monitor_hook_known_failure(array $row,array $price):?string {
    foreach($row['known_failures']??[] as $failure){
        if($failure['tour_id']===$price['tour_id']&&strtotime($failure['at'])>=strtotime($price['observed_at']))return $failure['reason'];
    }return null;
}


/** A child error is a fixed code only; never retain its stdout, stderr or provider body. */
function monitor_hook_fixed_child_error($value):?string {
    return is_string($value)&&preg_match('/\A[a-z][a-z0-9_]{1,100}\z/D',$value)===1?$value:null;
}
/** Keep the bounded read-only preflight, without expanding any local failure admission. */
function monitor_hook_child_failure(int $exit,array $result):array {
    $failure=['exit_code'=>$exit,'error'=>monitor_hook_fixed_child_error($result['error']??null)];
    if(is_array($result['channel_preflight']??null)){
        $preflight=$result['channel_preflight'];
        $failure['channel_preflight']=monitor_price_channel_preflight(is_string($preflight['platform']??null)?$preflight['platform']:'',$preflight);
    }
    return $failure;
}
/** Global channel failures stop this pass; a typed error alone is never a no-write proof. */
function monitor_hook_channel_preflight_failure(array $failure):bool {
    $p=$failure['channel_preflight']??null;
    if(array_keys($failure)!==['exit_code','error','channel_preflight']||($failure['exit_code']??null)!==2||!is_array($p)
        ||!in_array($failure['error']??null,['provider_channel_preflight_unavailable','provider_channel_permission_denied','provider_channel_identity_mismatch'],true)
        ||!in_array($p['platform']??null,['max','telegram'],true)||!in_array($p['state']??null,['unknown','requires_attention'],true)
        ||$p!==monitor_price_channel_preflight($p['platform'],$p)||($p['error']??null)!==$failure['error']
        ||!is_int($p['request_count']??null)||$p['request_count']<0||$p['request_count']>5||($p['write_attempts']??null)!==0
        ||($p['receipt_valid']??null)!==true||($p['http_results_truncated']??null)!==false||!is_array($p['http_results']??null)||!array_is_list($p['http_results'])
        ||count($p['http_results'])!==$p['request_count'])return false;
    foreach($p['http_results'] as $http){
        if(!is_array($http)||array_keys($http)!==['operation','http','transport_error']
            ||!in_array($http['operation'],['bot_identity','channel_rights','channel_type'],true)
            ||!is_int($http['http'])||$http['http']<0||$http['http']>599||!is_bool($http['transport_error']))return false;
    }
    return true;
}
/** Hotel-local preflight failures only. Runtime/schema/channel/auth gates stay closed. */
function monitor_hook_local_preflight_failure(array $failure):bool {
    return ($failure['exit_code']??null)===2&&in_array($failure['error']??null,[
        'monitor_price_expired','canonical_original_receipt_missing','canonical_original_receipt_mismatch',
        'existing_publication_identity_changed','monitor_publication_version_changed','existing_caption_changed',
        'existing_max_readback_changed','max_read_failed','message_id_invalid','expected_one_photo',
        'photo_stable_id_missing','target_changed_before_edit',
    ],true);
}
/** Verify every original line, not an emptied last state. No storage or transport. */
function monitor_hook_no_write_prefix(string $raw,string $operation):array {
    monitor_price_require($raw!==''&&str_ends_with($raw,"\n"),'hook_no_write_prefix_incomplete_stop');
    monitor_price_require(preg_match('/\Acommercial-monitor-price-h([1-9][0-9]*)-[a-f0-9]{64}\z/D',$operation,$match)===1,'hook_operation_invalid');
    $lines=explode("\n",substr($raw,0,-1));$last=null;$manifest=null;
    foreach($lines as $line){
        try{$entry=json_decode($line,true,64,JSON_THROW_ON_ERROR);}catch(Throwable $e){throw new RuntimeException('hook_no_write_prefix_invalid_stop');}
        monitor_price_require(is_array($entry)&&($entry['operation']??null)===$operation
            &&is_string($entry['manifest_hash']??null)&&preg_match('/\A[a-f0-9]{64}\z/D',$entry['manifest_hash'])===1
            &&($entry['posts']??null)===[]&&($entry['configs']??null)===[]
            &&(!array_key_exists('complete',$entry)||$entry['complete']===false)
            &&!array_key_exists('terminal',$entry)&&!array_key_exists('_terminal_journal_sha256',$entry),'hook_no_write_prefix_has_intent_stop');
        $manifest=$manifest??$entry['manifest_hash'];
        monitor_price_require($entry['manifest_hash']===$manifest&&is_array($entry['monitor_snapshot']??null)
            &&is_array($entry['feed_binding']??null)&&($entry['feed_binding']['tourvisor_hotel_id']??null)===(int)$match[1],'hook_no_write_prefix_scope_changed');
        if($last!==null)monitor_price_require($entry===$last,'hook_no_write_prefix_state_changed');
        $last=$entry;
    }
    // Validate the saved observation at its original export, never as a fresh quotation.
    $price=monitor_price_observation($last['monitor_snapshot'],(int)$match[1],monitor_price_time($last['monitor_snapshot']['generatedAt']??null),false);
    monitor_price_require(monitor_price_operation($price)===$operation,'hook_no_write_prefix_record_changed');
    return ['state'=>$last,'sha256'=>hash('sha256',$raw),'bytes'=>strlen($raw),'lines'=>count($lines)];
}
/** Return only the append-only terminal line; the caller owns locking and persistence. */
function monitor_hook_terminal_line(string $prefix,string $operation,string $recordedAt,string $reason,array $evidence):string {
    $proof=monitor_hook_no_write_prefix($prefix,$operation);$state=$proof['state'];$at=monitor_price_time($recordedAt);
    if($reason==='expired_observation_after_no_write_failure'){
        monitor_price_require(array_keys($evidence)===['readback_evidence_sha256']&&is_string($evidence['readback_evidence_sha256'])
            &&preg_match('/\A[a-f0-9]{64}\z/D',$evidence['readback_evidence_sha256'])===1,'hook_no_write_readback_evidence_invalid');
        monitor_price_require($at>monitor_price_time($state['monitor_snapshot']['item']['offers'][0]['expiresAt']??null),'hook_no_write_observation_not_expired');
    }elseif($reason==='child_preflight_failed_before_write'){
        monitor_price_require(array_keys($evidence)===['preflight_error','child_exit_code']
            &&monitor_hook_local_preflight_failure(['error'=>$evidence['preflight_error'],'exit_code'=>$evidence['child_exit_code']]),'hook_no_write_child_failure_not_admitted');
    }elseif($reason==='channel_preflight_blocked_before_write'){
        monitor_price_require(array_keys($evidence)===['preflight_error','child_exit_code','channel_preflight']
            &&monitor_hook_channel_preflight_failure(['exit_code'=>$evidence['child_exit_code'],'error'=>$evidence['preflight_error'],
                'channel_preflight'=>$evidence['channel_preflight']]),'hook_no_write_channel_failure_not_admitted_stop');
    }else throw new RuntimeException('hook_no_write_terminal_reason_invalid');
    $state['terminal']=['state'=>'abandoned_before_write','reason'=>$reason,'prior_sha256'=>$proof['sha256'],
        'prior_bytes'=>$proof['bytes'],'prior_lines'=>$proof['lines'],'recorded_at'=>$recordedAt]+$evidence;
    return json_encode($state,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n";
}
/** Existing complete/unknown journals retain their state; only a proved terminal is new. */
function monitor_hook_parse_journal(string $raw,string $operation):array {
    monitor_price_require($raw!==''&&str_ends_with($raw,"\n"),'hook_incomplete_journal_stop');
    $end=substr($raw,0,-1);$split=strrpos($end,"\n");$line=$split===false?$end:substr($end,$split+1);
    try{$last=json_decode($line,true,64,JSON_THROW_ON_ERROR);}catch(Throwable $e){throw new RuntimeException('hook_journal_invalid_stop');}
    monitor_price_require(is_array($last)&&($last['operation']??null)===$operation
        &&!array_key_exists('_terminal_journal_sha256',$last),'hook_journal_operation_changed');
    if(!array_key_exists('terminal',$last))return $last;
    $terminal=$last['terminal'];
    monitor_price_require(is_array($terminal)&&($terminal['state']??null)==='abandoned_before_write'&&$split!==false,'hook_terminal_state_unknown_stop');
    $prefix=substr($raw,0,$split+1);$proof=monitor_hook_no_write_prefix($prefix,$operation);
    monitor_price_require(($terminal['prior_sha256']??null)===$proof['sha256']
        &&($terminal['prior_bytes']??null)===$proof['bytes']&&($terminal['prior_lines']??null)===$proof['lines'],'hook_terminal_prefix_changed');
    $evidence=array_diff_key($terminal,array_flip(['state','reason','prior_sha256','prior_bytes','prior_lines','recorded_at']));
    $expected=monitor_hook_terminal_line($prefix,$operation,(string)($terminal['recorded_at']??''),(string)($terminal['reason']??''),$evidence);
    monitor_price_require($line."\n"===$expected,'hook_terminal_state_changed');
    $last['_terminal_journal_sha256']=hash('sha256',$raw);
    return $last;
}
/** Resolve only the old pending metadata; successful counts and receipts remain intact. */
function monitor_hook_apply_abandonment(array $state,array $journal):?array {
    if(!isset($journal['terminal']))return null;
    monitor_price_require(($journal['terminal']['reason']??null)!=='channel_preflight_blocked_before_write','hook_channel_preflight_requires_global_reconciliation_stop');
    $pending=$state['pending']??null;$operation=$journal['operation']??'';
    monitor_price_require(($journal['terminal']['state']??null)==='abandoned_before_write'
        &&is_string($journal['_terminal_journal_sha256']??null)&&preg_match('/\A[a-f0-9]{64}\z/D',$journal['_terminal_journal_sha256'])===1
        &&is_array($pending)&&($pending['phase']??null)==='post_started'&&($pending['operation']??null)===$operation
        &&($state['schema']??null)===1&&is_array($state['completed']??null),'hook_terminal_pending_phase_invalid');
    $tv=$pending['tourvisor_hotel_id']??null;
    monitor_price_require(is_int($tv)&&$tv>0&&preg_match('/\Acommercial-monitor-price-h'.$tv.'-[a-f0-9]{64}\z/D',$operation)===1
        &&($journal['feed_binding']['tourvisor_hotel_id']??null)===$tv,'hook_terminal_pending_identity_changed');
    monitor_price_require(($state['completed'][$tv]['operation']??null)!==$operation,'hook_terminal_already_completed_stop');
    $hold=['operation'=>$operation,'reason'=>'abandoned_before_write','terminal_journal_sha256'=>$journal['_terminal_journal_sha256'],'at'=>$journal['terminal']['recorded_at']];
    monitor_price_require(!isset($state['holds'])||is_array($state['holds']),'hook_hold_state_invalid');
    $state['holds']=$state['holds']??[];
    monitor_price_require(!isset($state['holds'][$tv])||$state['holds'][$tv]===$hold,'hook_hold_conflict_stop');
    $state['holds'][$tv]=$hold;$state['pending']=null;return $state;
}
/** A held hotel is independent of any newer recordId; removal needs explicit reconciliation. */
function monitor_hook_hold_reason(int $tv,array $hold,?array $journal):string {
    monitor_price_require($tv>0&&is_array($journal)&&($hold['reason']??null)==='abandoned_before_write'
        &&($journal['terminal']['state']??null)==='abandoned_before_write'&&($hold['operation']??null)===($journal['operation']??null)
        &&preg_match('/\Acommercial-monitor-price-h'.$tv.'-[a-f0-9]{64}\z/D',(string)$hold['operation'])===1
        &&is_string($journal['_terminal_journal_sha256']??null)&&($hold['terminal_journal_sha256']??null)===$journal['_terminal_journal_sha256']
        &&($hold['at']??null)===($journal['terminal']['recorded_at']??null),'hook_hold_evidence_changed_stop');
    return 'abandoned_before_write';
}

/** Exact original collector identity, or null for a standalone bounded pass. */
function monitor_hook_preflight_cycle_identity(array $cycle):array {
    return array_intersect_key($cycle,array_flip(['night_date','collector_run_id','collection_started_at','started_at']));
}
function monitor_hook_preflight_utc_time($value):?int {
    if(!is_string($value)||preg_match('/\A[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}\+00:00\z/D',$value)!==1)return null;
    try{$time=monitor_price_time($value);return gmdate('c',$time)===$value?$time:null;}catch(Throwable $e){return null;}
}
function monitor_hook_preflight_cycle_identity_valid(array $identity):bool {
    if(array_keys($identity)!==['night_date','collector_run_id','collection_started_at','started_at']
        ||!is_string($identity['collector_run_id'])||preg_match('/\A[1-9][0-9]{0,19}\z/D',$identity['collector_run_id'])!==1)return false;
    $collection=monitor_hook_preflight_utc_time($identity['collection_started_at']);$started=monitor_hook_preflight_utc_time($identity['started_at']);
    return $collection!==null&&$started!==null&&$collection<=$started+60&&$started-$collection<=3600
        &&$identity['night_date']===(new DateTimeImmutable('@'.$collection))->setTimezone(new DateTimeZone('Europe/Moscow'))->format('Y-m-d');
}
/** Progress is captured in the failure append before any child-terminal append. */
function monitor_hook_preflight_progress(array $out,int $rosterCount,int $startedAt,int $now):array {
    $progress=['roster_pairs'=>$rosterCount];
    foreach(['updated_pairs','unchanged_pairs','eligible_pairs','held_pairs','scanned_pairs'] as $key)$progress[$key]=$out[$key]??null;
    $progress['remaining_unscanned_pairs']=$rosterCount-($out['scanned_pairs']??0);
    $progress['duration_seconds']=$now-$startedAt;$progress['recorded_at']=gmdate('c',$now);
    monitor_price_require(monitor_hook_preflight_progress_valid($progress),'hook_channel_preflight_progress_invalid_stop');return $progress;
}
function monitor_hook_preflight_progress_valid(array $progress):bool {
    if(array_keys($progress)!==['roster_pairs','updated_pairs','unchanged_pairs','eligible_pairs','held_pairs','scanned_pairs',
        'remaining_unscanned_pairs','duration_seconds','recorded_at'])return false;
    foreach(['roster_pairs','updated_pairs','unchanged_pairs','eligible_pairs','held_pairs','scanned_pairs','remaining_unscanned_pairs'] as $key)
        if(!is_int($progress[$key])||$progress[$key]<0||$progress[$key]>1000)return false;
    return $progress['roster_pairs']>0&&$progress['scanned_pairs']>0&&$progress['eligible_pairs']>0
        &&$progress['scanned_pairs']<=$progress['roster_pairs']&&$progress['eligible_pairs']<=$progress['scanned_pairs']
        &&$progress['updated_pairs']<=$progress['eligible_pairs']
        &&$progress['updated_pairs']+$progress['unchanged_pairs']+$progress['held_pairs']<=$progress['scanned_pairs']
        &&$progress['remaining_unscanned_pairs']===$progress['roster_pairs']-$progress['scanned_pairs']
        &&is_int($progress['duration_seconds'])&&$progress['duration_seconds']>=0&&$progress['duration_seconds']<=86400
        &&monitor_hook_preflight_utc_time($progress['recorded_at'])!==null;
}
/** Bounded durable evidence. The transition below also requires the canonically parsed child. */
function monitor_hook_preflight_block_valid(array $block):bool {
    if(array_keys($block)!==['operation','tourvisor_hotel_id','pending_at','child_failure','collector_cycle','progress','terminal','terminal_journal_sha256','closed_at']
        ||!is_int($block['tourvisor_hotel_id'])||$block['tourvisor_hotel_id']<=0||!is_string($block['operation'])
        ||preg_match('/\Acommercial-monitor-price-h'.$block['tourvisor_hotel_id'].'-[a-f0-9]{64}\z/D',$block['operation'])!==1
        ||!is_array($block['child_failure'])||!monitor_hook_channel_preflight_failure($block['child_failure'])
        ||!is_array($block['progress'])||!monitor_hook_preflight_progress_valid($block['progress'])
        ||!is_string($block['terminal_journal_sha256'])||preg_match('/\A[a-f0-9]{64}\z/D',$block['terminal_journal_sha256'])!==1
        ||($block['collector_cycle']!==null&&(!is_array($block['collector_cycle'])||!monitor_hook_preflight_cycle_identity_valid($block['collector_cycle']))))return false;
    $terminal=$block['terminal'];$failure=$block['child_failure'];
    if(!is_array($terminal)||array_keys($terminal)!==['state','reason','prior_sha256','prior_bytes','prior_lines','recorded_at','preflight_error','child_exit_code','channel_preflight']
        ||$terminal['state']!=='abandoned_before_write'||$terminal['reason']!=='channel_preflight_blocked_before_write'
        ||!is_string($terminal['prior_sha256'])||preg_match('/\A[a-f0-9]{64}\z/D',$terminal['prior_sha256'])!==1
        ||!is_int($terminal['prior_bytes'])||$terminal['prior_bytes']<1||$terminal['prior_bytes']>10000000
        ||!is_int($terminal['prior_lines'])||$terminal['prior_lines']<1||$terminal['prior_lines']>$terminal['prior_bytes']
        ||$terminal['preflight_error']!==$failure['error']||$terminal['child_exit_code']!==$failure['exit_code']
        ||$terminal['channel_preflight']!==$failure['channel_preflight'])return false;
    $pendingAt=monitor_hook_preflight_utc_time($block['pending_at']);$recordedAt=monitor_hook_preflight_utc_time($terminal['recorded_at']);
    $closedAt=monitor_hook_preflight_utc_time($block['closed_at']);$progressAt=monitor_hook_preflight_utc_time($block['progress']['recorded_at']);
    return $pendingAt!==null&&$recordedAt!==null&&$closedAt!==null&&$pendingAt<=$progressAt&&$progressAt<=$recordedAt&&$recordedAt<=$closedAt
        &&($block['collector_cycle']===null||monitor_hook_preflight_utc_time($block['collector_cycle']['started_at'])<=$pendingAt);
}
function monitor_hook_preflight_cycle_valid(array $cycle):bool {
    $block=$cycle['preflight_block']??null;
    if(($cycle['state']??null)!=='preflight_blocked'||!is_array($block)||!monitor_hook_preflight_block_valid($block)
        ||$block['collector_cycle']===null||monitor_hook_preflight_cycle_identity($cycle)!==$block['collector_cycle']
        ||($cycle['budget_seconds']??null)!==1800||($cycle['finish_reserve_seconds']??null)!==300||array_key_exists('finished_at',$cycle)
        ||($cycle['blocked_at']??null)!==$block['terminal']['recorded_at']||($cycle['preflight_error']??null)!==$block['child_failure']['error'])return false;
    foreach(['updated_pairs','unchanged_pairs','eligible_pairs','held_pairs','scanned_pairs','remaining_unscanned_pairs','duration_seconds'] as $key)
        if(($cycle[$key]??null)!==$block['progress'][$key])return false;
    return true;
}
/** No hotel hold or completion. Close only the exact cycle that owned this pending child. */
function monitor_hook_close_channel_preflight(array $state,array $journal,int $now):?array {
    if(($journal['terminal']['reason']??null)!=='channel_preflight_blocked_before_write')return null;
    $pending=$state['pending']??null;$operation=$journal['operation']??null;
    monitor_price_require(($state['schema']??null)===1&&is_array($state['completed']??null)&&is_array($pending)
        &&($pending['phase']??null)==='post_started'&&($pending['operation']??null)===$operation
        &&array_key_exists('collector_cycle',$pending)&&is_array($pending['child_failure']??null)
        &&monitor_hook_channel_preflight_failure($pending['child_failure'])&&is_array($pending['preflight_progress']??null)
        &&($journal['posts']??null)===[]&&($journal['configs']??null)===[]&&($journal['complete']??false)===false
        &&($journal['feed_binding']['tourvisor_hotel_id']??null)===($pending['tourvisor_hotel_id']??null),'hook_channel_preflight_pending_invalid_stop');
    $tv=$pending['tourvisor_hotel_id'];
    monitor_price_require(($state['completed'][$tv]['operation']??null)!==$operation,'hook_channel_preflight_already_completed_stop');
    $block=['operation'=>$operation,'tourvisor_hotel_id'=>$tv,'pending_at'=>$pending['at']??null,'child_failure'=>$pending['child_failure'],
        'collector_cycle'=>$pending['collector_cycle'],'progress'=>$pending['preflight_progress'],'terminal'=>$journal['terminal'],
        'terminal_journal_sha256'=>$journal['_terminal_journal_sha256']??null,'closed_at'=>gmdate('c',$now)];
    monitor_price_require(monitor_hook_preflight_block_valid($block),'hook_channel_preflight_evidence_invalid_stop');
    if($block['collector_cycle']!==null){
        $cycle=$state['nightly_cycle']??null;
        monitor_price_require(is_array($cycle)&&($cycle['state']??null)==='started'
            &&monitor_hook_preflight_cycle_identity($cycle)===$block['collector_cycle'],'hook_channel_preflight_cycle_changed_stop');
        $cycle['state']='preflight_blocked';$cycle['preflight_block']=$block;$cycle['blocked_at']=$block['terminal']['recorded_at'];
        $cycle['preflight_error']=$block['child_failure']['error'];
        foreach(['updated_pairs','unchanged_pairs','eligible_pairs','held_pairs','scanned_pairs','remaining_unscanned_pairs','duration_seconds'] as $key)$cycle[$key]=$block['progress'][$key];
        monitor_price_require(monitor_hook_preflight_cycle_valid($cycle),'hook_channel_preflight_cycle_invalid_stop');$state['nightly_cycle']=$cycle;
    }
    $state['last_preflight_block']=$block;$state['pending']=null;return $state;
}

/** Original failed collector and independently accepted recovery; no new post/feed operation. */
function monitor_hook_cycle_recovery_proof():array {
    return ['collector_run_id'=>'43336135923','recovery_run_id'=>'43352649982',
        'recovery_result_sha256'=>'2af5d5895ad75df1d3d538dabba726f937a245155065146ddc0bc43e01be112f',
        'operation'=>'commercial-monitor-price-h112914-d7617e5a491183b8f8e73d26a8b419b6147d6a8323b84799d0b6057819f93e70',
        'feed_operation'=>'commercial-channel-hotel-feeds-20261113-update39435617341790398',
        'global'=>['sha256'=>'cf1bf2656c1370baa8cec1b1daa2167c223a7a501d12e9fe599acf25f97fdec3','bytes'=>26249468,'lines'=>816],
        'child'=>['sha256'=>'ff01bfb9a14667c8a89822d8c2e6edc7baa946a499bfd8b9d0c8e5c32341bef0','bytes'=>143160,'lines'=>10]];
}
function monitor_hook_cycle_journal_pin(string $raw):array {
    monitor_price_require($raw!==''&&str_ends_with($raw,"\n"),'hook_cycle_recovery_journal_incomplete');
    return ['sha256'=>hash('sha256',$raw),'bytes'=>strlen($raw),'lines'=>substr_count($raw,"\n")];
}
function monitor_hook_reconciled_cycle_valid(array $cycle):bool {
    return ($cycle['state']??null)==='interrupted_reconciled'
        &&($cycle['collector_run_id']??null)==='43336135923'&&($cycle['night_date']??null)==='2026-11-13'
        &&($cycle['recovery']??null)===monitor_hook_cycle_recovery_proof()
        &&is_string($cycle['reconciled_at']??null)
        &&preg_match('/\A[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}\+00:00\z/D',$cycle['reconciled_at'])===1
        &&$cycle['reconciled_at']>='2026-11-13T02:31:07+00:00';
}
/** A forward-only metadata line for one proved recovery; an interrupted sweep is never completed. */
function monitor_hook_close_reconciled_cycle(array $state,array $globalPin,array $childPin,array $child,int $now):?array {
    $cycle=$state['nightly_cycle']??null;$proof=monitor_hook_cycle_recovery_proof();
    if(!is_array($cycle)||($cycle['collector_run_id']??null)!==$proof['collector_run_id']||($cycle['state']??null)!=='started')return null;
    monitor_price_require($globalPin===$proof['global']&&$childPin===$proof['child'],'hook_cycle_recovery_prefix_changed');
    monitor_price_require(array_key_exists('pending',$state)&&$state['pending']===null
        &&($state['schema']??null)===1&&($cycle['night_date']??null)==='2026-11-13'
        &&($child['operation']??null)===$proof['operation']&&($child['complete']??false)===true
        &&($state['completed'][112914]['operation']??null)===$proof['operation']
        &&($state['completed'][112914]['feed_operation']??null)===$proof['feed_operation'],'hook_cycle_recovery_not_terminal');
    monitor_price_require($now>=monitor_price_time('2026-11-13T02:31:07+00:00'),'hook_cycle_recovery_clock_invalid');
    $state['nightly_cycle']['state']='interrupted_reconciled';
    $state['nightly_cycle']['recovery']=$proof;$state['nightly_cycle']['reconciled_at']=gmdate('c',$now);
    return $state;
}

/** Separate exact receipt for the accepted TV512 no-write retirement; never a legacy-error waiver. */
function monitor_hook_expired_cycle_recovery_proof():array {
    return ['collector_run_id'=>'43520564593','recovery_run_id'=>'43536956032',
        'recovery_source_sha'=>'acf2114e994f20b6a2f0694e252b8856da5ed8c2',
        'recovery_result_sha256'=>'81faf4ff1d490fba41986089e28205847deef61542c06633191f81788cebbd43',
        'operation'=>'commercial-monitor-price-h512-3d068cf2c5aeecbe17f5d682ba8c5b19b01b69a49ff5f34159b33e86e0485dfc',
        'global'=>['sha256'=>'6808a4f735b01ca1e241052dcd9a119883dc53a624c2de491093068895071105','bytes'=>66389613,'lines'=>1315],
        'child'=>['sha256'=>'47243d4322d82ca9758537fa1ccff3f5d5050f30f7263db7cecdce26afbc28a5','bytes'=>15077,'lines'=>2],
        'completed_count'=>371,'completed_tuple_sha256'=>'66d62626f15847ea576171fa7c397a250ba145d9bfb1724ef0252940703bd021',
        'cycle_tuple_sha256'=>'6a931e905b8a83ba58c5f2eac574feae5628e315d5bbc88d799064ea9caeb72d',
        'terminal'=>['state'=>'abandoned_before_write','reason'=>'expired_observation_after_no_write_failure',
            'prior_sha256'=>'66af374c6e5a1e50331b80d106c9a514f77382692bfdd1fd931de89cb327e167','prior_bytes'=>7362,'prior_lines'=>1,
            'recorded_at'=>'2026-11-14T00:52:27+00:00','readback_evidence_sha256'=>'a1df3c521b21ace94148f01f58d35c621cd8e3d2d105e5f052ebd2b495e335fe']];
}
function monitor_hook_expired_reconciled_cycle_valid(array $cycle):bool {
    $proof=monitor_hook_expired_cycle_recovery_proof();$at=monitor_hook_preflight_utc_time($cycle['reconciled_at']??null);
    if(($cycle['state']??null)!=='interrupted_reconciled'||($cycle['recovery']??null)!==$proof||$at===null
        ||$at<monitor_price_time($proof['terminal']['recorded_at']))return false;
    unset($cycle['recovery'],$cycle['reconciled_at']);$cycle['state']='started';
    return hash('sha256',json_encode($cycle,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR))===$proof['cycle_tuple_sha256'];
}
/** Actual pinned post-retirement journals admit one metadata append, including in standalone maintenance. */
function monitor_hook_close_expired_reconciled_cycle(array $state,array $globalPin,array $childPin,array $child,int $now):?array {
    $cycle=$state['nightly_cycle']??null;$proof=monitor_hook_expired_cycle_recovery_proof();
    if(!is_array($cycle)||($cycle['collector_run_id']??null)!==$proof['collector_run_id']||($cycle['state']??null)!=='started')return null;
    monitor_price_require($globalPin===$proof['global']&&$childPin===$proof['child'],'hook_expired_cycle_recovery_prefix_changed');
    monitor_price_require(($state['schema']??null)===1&&array_key_exists('pending',$state)&&$state['pending']===null
        &&is_array($state['completed']??null)&&count($state['completed'])===$proof['completed_count']
        &&(!isset($state['holds'])||is_array($state['holds']))&&!array_key_exists(512,$state['holds']??[])
        &&($child['operation']??null)===$proof['operation']&&($child['complete']??false)===false
        &&($child['posts']??null)===[]&&($child['configs']??null)===[]&&($child['feed_binding']['tourvisor_hotel_id']??null)===512
        &&($child['terminal']??null)===$proof['terminal']&&($child['_terminal_journal_sha256']??null)===$proof['child']['sha256']
        &&hash('sha256',json_encode($cycle,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR))===$proof['cycle_tuple_sha256'],
        'hook_expired_cycle_recovery_not_terminal');
    monitor_price_require($now>=monitor_price_time($proof['terminal']['recorded_at']),'hook_expired_cycle_recovery_clock_invalid');
    $state['nightly_cycle']['state']='interrupted_reconciled';$state['nightly_cycle']['recovery']=$proof;
    $state['nightly_cycle']['reconciled_at']=gmdate('c',$now);return $state;
}

/** Reuse the original append-only journal; a later collector never erases replay history. */
function monitor_hook_attempted_collector_runs(array $journalLines):array {
    $runs=[];
    foreach($journalLines as $line){
        try{$entry=json_decode($line,true,64,JSON_THROW_ON_ERROR);}catch(Throwable $e){throw new RuntimeException('hook_nightly_history_invalid');}
        monitor_price_require(is_array($entry),'hook_nightly_history_invalid');
        $cycle=$entry['nightly_cycle']??null;if($cycle===null)continue;
        monitor_price_require(is_array($cycle)&&is_string($cycle['collector_run_id']??null)
            &&preg_match('/\A[1-9][0-9]{0,19}\z/D',$cycle['collector_run_id'])===1
            &&(in_array($cycle['state']??null,['started','completed','budget_exhausted'],true)
                ||monitor_hook_reconciled_cycle_valid($cycle)||monitor_hook_expired_reconciled_cycle_valid($cycle)
                ||monitor_hook_preflight_cycle_valid($cycle)),'hook_nightly_history_invalid');
        $runs['run:'.$cycle['collector_run_id']]=true;
    }
    return $runs;
}
/** Legacy name/state retained; maintenance follows each original successful collector at any hour. */
function monitor_hook_nightly_gate(array $state,int $now,?string $collectionStartedAt,?string $collectorRunId,array $attemptedRuns=[]):array {
    monitor_price_require(is_string($collectorRunId)&&preg_match('/\A[1-9][0-9]{0,19}\z/D',$collectorRunId)===1,'hook_nightly_collector_run_invalid');
    $collectionAt=monitor_price_time($collectionStartedAt);
    monitor_price_require($collectionAt<=$now+60&&$now-$collectionAt<=3600,'hook_nightly_collection_time_invalid');
    $local=(new DateTimeImmutable('@'.$collectionAt))->setTimezone(new DateTimeZone('Europe/Moscow'));
    $night=$local->format('Y-m-d');
    if(($state['pending']??null)!==null)return ['due'=>false,'blocked'=>true,'reason'=>'hook_pending_operation_requires_reconciliation','night_date'=>$night];
    $prior=$state['nightly_cycle']??null;
    if($prior!==null){
        monitor_price_require(is_array($prior)&&is_string($prior['night_date']??null)
            &&preg_match('/\A[0-9]{4}-[0-9]{2}-[0-9]{2}\z/D',$prior['night_date'])===1
            &&(in_array($prior['state']??null,['started','completed','budget_exhausted'],true)||monitor_hook_reconciled_cycle_valid($prior)
                ||monitor_hook_expired_reconciled_cycle_valid($prior)||monitor_hook_preflight_cycle_valid($prior))
            &&is_string($prior['collector_run_id']??null)&&preg_match('/\A[1-9][0-9]{0,19}\z/D',$prior['collector_run_id'])===1
            &&is_string($prior['collection_started_at']??null),'hook_nightly_cycle_invalid');
        monitor_price_require($prior['night_date']<=$night,'hook_nightly_clock_moved_back');
        if($prior['state']==='started')return ['due'=>false,'blocked'=>true,
            'reason'=>'hook_nightly_original_attempt_unresolved',
            'night_date'=>$night,'cycle'=>$prior];
    }
    if(isset($attemptedRuns['run:'.$collectorRunId])||($prior['collector_run_id']??null)===$collectorRunId)
        return ['due'=>false,'blocked'=>false,'reason'=>'collector_cycle_already_attempted','night_date'=>$night,'cycle'=>$prior];
    if($prior!==null)monitor_price_require($collectionAt>=monitor_price_time($prior['collection_started_at']),'hook_nightly_clock_moved_back');
    return ['due'=>true,'blocked'=>false,'night_date'=>$night,'cycle'=>[
        'night_date'=>$night,'state'=>'started','collector_run_id'=>$collectorRunId,
        'collection_started_at'=>gmdate('c',$collectionAt),'started_at'=>gmdate('c',$now),
        'budget_seconds'=>1800,'finish_reserve_seconds'=>300,
    ]];
}
/** Stop admitting new pairs early enough to finish the current original pair. No writer is killed. */
function monitor_hook_pass_policy(bool $nightly,int $rosterCount,int $startedAt):array {
    monitor_price_require($rosterCount>=1&&$rosterCount<=1000,'hook_roster_scope_changed');
    return ['pair_limit'=>$nightly?$rosterCount:30,'budget_seconds'=>$nightly?1800:480,
        'finish_reserve_seconds'=>$nightly?300:0,'admit_until'=>$startedAt+($nightly?1500:480)];
}
function monitor_hook_can_start_pair(array $policy,int $now,int $updatedPairs):bool {
    return $now<$policy['admit_until']&&$updatedPairs<$policy['pair_limit'];
}
/** Saved exports last 15 minutes; refresh the read-only snapshot before that boundary. */
function monitor_hook_snapshot_refresh_due(int $fetchedAt,int $now):bool {
    return $now-$fetchedAt>=600;
}



