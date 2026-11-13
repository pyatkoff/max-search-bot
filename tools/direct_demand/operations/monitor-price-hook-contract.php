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
                ||monitor_hook_reconciled_cycle_valid($cycle)),'hook_nightly_history_invalid');
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
            &&(in_array($prior['state']??null,['started','completed','budget_exhausted'],true)||monitor_hook_reconciled_cycle_valid($prior))
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


