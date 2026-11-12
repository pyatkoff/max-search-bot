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

/** A later automated edit is admissible only through its completed original journal. */
function monitor_hook_caption_allowed(array $publication,array $row,string $platform,?array $prior):bool {
    $s=$publication['snapshot']??[];$hash=hash('sha256',$s['text']??'');
    $record=$s['metadata']['monitor_price_record_id']??null;
    if($record===null)return hash_equals($row['expected_text_sha256'][$platform],$hash);
    if(!is_string($record)||!preg_match('/\A[a-f0-9]{64}\z/D',$record)||!$prior||($prior['complete']??false)!==true
        ||($prior['operation']??'')!=='commercial-monitor-price-h'.$row['tourvisor_hotel_id'].'-'.$record
        ||($prior['feed_binding']??null)!==$row['feed_binding'])return false;
    foreach($prior['posts']??[] as $post){$r=$post['receipt']??[];
        if(($post['state']??'')==='confirmed'&&($r['platform']??'')===$platform)
            return ($r['version_id']??null)===(int)($publication['version_id']??0)&&($r['publication_id']??null)===(int)($publication['publication_id']??0)
                &&($r['message_id']??null)===($publication['external_message_id']??null)&&($r['post_url']??null)===($s['post_url']??null)
                &&($r['text_sha256']??null)===$hash&&($r['text']??null)===($s['text']??null)
                &&($r['existing_message_reused']??false)===true&&($r['body_photo_button_verified']??false)===true;
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


/** A child result is a fixed code only; never retain its stdout, stderr or provider body. */
function monitor_hook_fixed_child_error($value):?string {
    return is_string($value)&&preg_match('/\A[a-z][a-z0-9_]{1,100}\z/D',$value)===1?$value:null;
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


