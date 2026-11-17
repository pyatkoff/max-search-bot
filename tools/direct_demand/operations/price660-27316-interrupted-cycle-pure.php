<?php
declare(strict_types=1);

/**
 * PRICE660 / TV27316, pure projection of the FINAL scheduled-cycle closure.
 *
 * Does not read a server, trust an unverified caller, send a post, write a feed,
 * append a journal or install a publisher. Production adoption requires a
 * separate reviewed hook-contract validator, original locks and trusted pins.
 */
function price27316_cycle_need(bool $condition, string $reason): void {
    if (!$condition) throw new RuntimeException($reason);
}
function price27316_cycle_hash($value): string {
    return hash('sha256', json_encode($value, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR));
}
function price27316_cycle_iso($value): bool {
    return is_string($value)
        && preg_match('/\A20[0-9]{2}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}\+00:00\z/D', $value) === 1
        && strtotime($value) !== false
        && gmdate('c', strtotime($value)) === $value;
}
function price27316_cycle_sha($value): bool {
    return is_string($value) && preg_match('/\A[a-f0-9]{64}\z/D', $value) === 1;
}
function price27316_cycle_receipt(array $post, string $platform, int $id, int $prior): array {
    $r = $post['receipt'] ?? null;
    price27316_cycle_need(($post['state']??null)==='confirmed'&&is_array($r)
        &&($r['state']??null)==='sent'&&($r['platform']??null)===$platform
        &&($r['publication_id']??null)===$id&&is_int($r['version_id']??null)
        &&$r['version_id']>$prior
        &&($r['existing_message_reused']??null)===true
        &&($r['body_photo_button_verified']??null)===true
        &&($r['price_link_verified']??null)===true
        &&($r['monitor_price']['record_id']??null)==='7627b206ea11237db2e1fb365e98be5c1ff11a439b3939637fee633dee1b83d4'
        &&price27316_cycle_sha($r['text_sha256']??null)
        &&($r['button_text']??null)==='✈️ Узнать стоимость тура',
        'confirmed_'.$platform.'_receipt_invalid');
    if ($platform==='max') {
        price27316_cycle_need($r['version_id']===7024
            &&($r['post_url']??null)==='https://max.ru/channel_anytour/AaGjuI2pXao',
            'confirmed_max_id_changed');
    } else {
        price27316_cycle_need(($r['post_url']??null)==='https://t.me/any_toour/156'
            &&($r['message_id']??null)==='156','confirmed_telegram_id_changed');
    }
    return $r;
}
/**
 * Construct a terminal state for the *old collector only after* one exact
 * original pair and all three original feed receipts have been verified.
 * The source array is a trust boundary: live implementation must populate it
 * from locked canonical journals, current registry and independent readbacks.
 */
function price27316_interrupted_cycle_project(
    array $global, array $child, array $feeds, array $proof, int $now
): array {
    $operation='commercial-monitor-price-h27316-7627b206ea11237db2e1fb365e98be5c1ff11a439b3939637fee633dee1b83d4';
    price27316_cycle_need($now>=strtotime('2026-11-17T04:09:10+00:00'), 'clock_before_original');

    $cycle=$global['nightly_cycle']??null;
    price27316_cycle_need(($global['schema']??null)===1
        &&array_key_exists('pending',$global)&&$global['pending']===null
        &&is_array($cycle)&&($cycle['state']??null)==='started'
        &&($cycle['night_date']??null)==='2026-11-17'
        &&($cycle['collector_run_id']??null)==='44012967294'
        &&($cycle['budget_seconds']??null)===1800
        &&($cycle['finish_reserve_seconds']??null)===300
        &&price27316_cycle_iso($cycle['started_at']??null)
        &&price27316_cycle_iso($cycle['collection_started_at']??null)
        &&strtotime($cycle['collection_started_at'])<=strtotime($cycle['started_at'])
        &&count($global['holds']??[])===0
        &&is_array($global['completed']??null)
        &&count($global['completed'])===590,
        'source_cycle_not_original_started');

    $completed=$global['completed'][27316]??null;
    price27316_cycle_need(is_array($completed)&&($completed['operation']??null)===$operation
        &&is_string($completed['feed_operation']??null)
        &&preg_match('/\Acommercial-channel-hotel-feeds-[0-9]{8}-update[0-9]+\z/D',$completed['feed_operation'])===1
        &&price27316_cycle_iso($completed['at']??null),
        'source_completed_pair_not_proven');
    $previous=$global['completed'];
    unset($previous[27316]);
    price27316_cycle_need(count($previous)===589
        &&price27316_cycle_sha($proof['previous_completed_sha256']??null)
        &&price27316_cycle_hash($previous)===$proof['previous_completed_sha256'],
        'previous_589_completions_changed');

    $maxKey='max:commercial-regnum-carya-27316-v1';
    $tgKey='telegram:commercial-regnum-carya-27316-v1';
    price27316_cycle_need(($child['operation']??null)===$operation
        &&($child['manifest_hash']??null)==='94ca6dd9be5c0fb66f0bf3ee6a09ca4e7d8319bb1cd1e3ae2f8a7f7390f89a78'
        &&($child['complete']??null)===true
        &&($child['feed_binding']['tourvisor_hotel_id']??null)===27316
        &&is_array($child['posts']??null)&&count($child['posts'])===2,
        'source_child_not_complete');
    // The exact original logical key must be supplied by a pinned source
    // roster at implementation time. Do not invent it from a hotel name.
    price27316_cycle_need(($proof['original_logical_key']??null)!==null
        &&is_string($proof['original_logical_key'])
        &&preg_match('/\A[a-z0-9-]{10,160}\z/D',$proof['original_logical_key'])===1,
        'original_logical_key_unproven');
    $actualMax='max:'.$proof['original_logical_key'];
    $actualTg='telegram:'.$proof['original_logical_key'];
    price27316_cycle_need(array_key_exists($actualMax,$child['posts'])
        &&array_key_exists($actualTg,$child['posts']),
        'source_original_pair_keys_changed');
    $max=price27316_cycle_receipt($child['posts'][$actualMax], 'max',400,4199);
    $tg=price27316_cycle_receipt($child['posts'][$actualTg], 'telegram',395,4200);
    price27316_cycle_need(($child['reconciliation']['max_puts']??null)===0
        &&($child['reconciliation']['telegram_first_edit_attempts']??null)===1
        &&($child['reconciliation']['new_posts']??null)===0
        &&($child['reconciliation']['original_prefix_sha256']??null)==='010c6ecfbdae1156a772160bbadb5fec4cbd84555e14acf10baa791c36da2988',
        'reconciliation_provenance_invalid');

    price27316_cycle_need(($feeds['operation']??null)===$completed['feed_operation']
        &&array_keys($feeds['files']??[])===['hotel-posts-telegram.xml','hotel-posts-max.xml','hotel-posts-all.xml']
        &&($feeds['writer']??null)==='original_existing_feed_writer'
        &&($feeds['original_operation']??null)===$operation
        &&($feeds['new_offer_ids']??null)===0
        &&($feeds['duplicate_native_ids']??null)===0,
        'feed_source_or_uniqueness_changed');
    foreach (['hotel-posts-telegram.xml'=>660,'hotel-posts-max.xml'=>660,'hotel-posts-all.xml'=>1320] as $name=>$rows) {
        $file=$feeds['files'][$name];
        price27316_cycle_need(is_array($file)&&($file['rows']??null)===$rows
            &&price27316_cycle_sha($file['sha256']??null)
            &&($file['local_sha256']??null)===$file['sha256']
            &&($file['public_sha256']??null)===$file['sha256'],
            'feed_hash_or_rows_unconfirmed');
    }
    price27316_cycle_need(($proof['original_roster_pairs']??null)===660
        &&($proof['original_scanned_pairs']??null)===132
        &&($proof['original_updated_pairs']??null)===12
        &&($proof['original_no_fresh_pairs']??null)===119
        &&($proof['original_remaining_unscanned_pairs']??null)===528
        &&($proof['original_editor_error']??null)==='max_button_outcome_not_confirmed'
        &&($proof['max_put_attempts_during_recovery']??null)===0
        &&($proof['telegram_first_edits_during_recovery']??null)===1
        &&($proof['new_posts_during_recovery']??null)===0
        &&price27316_cycle_sha($proof['original_result_sha256']??null)
        &&price27316_cycle_sha($proof['original_child_final_sha256']??null)
        &&price27316_cycle_sha($proof['original_global_after_feed_sha256']??null)
        &&price27316_cycle_iso($proof['telegram_fresh_admission_at']??null)
        &&price27316_cycle_iso($proof['telegram_first_edit_at']??null)
        &&strtotime($proof['telegram_fresh_admission_at'])<=strtotime($proof['telegram_first_edit_at'])
        &&strtotime($proof['telegram_first_edit_at'])-strtotime($proof['telegram_fresh_admission_at'])<900,
        'recovery_external_proof_missing');

    $next=$global;
    $next['nightly_cycle']['state']='interrupted_reconciled';
    $next['nightly_cycle']['recovery']=[
        'kind'=>'price660_exact_original_partial_pair',
        'collector_run_id'=>'44012967294',
        'operation'=>$operation,
        'original_result_sha256'=>$proof['original_result_sha256'],
        'original_child_final_sha256'=>$proof['original_child_final_sha256'],
        'original_global_after_feed_sha256'=>$proof['original_global_after_feed_sha256'],
        'original_roster_pairs'=>660,
        'original_scanned_pairs'=>132,
        'original_updated_pairs'=>12,
        'original_no_fresh_pairs'=>119,
        'original_remaining_unscanned_pairs'=>528,
        'recovered_one_pair'=>true,
        'feed_operation'=>$completed['feed_operation'],
        'feed_files_sha256'=>price27316_cycle_hash($feeds['files']),
        'previous_completed_sha256'=>$proof['previous_completed_sha256'],
        'max_provider_puts'=>0,
        'telegram_first_edits'=>1,
        'new_posts'=>0,
    ];
    $next['nightly_cycle']['reconciled_at']=gmdate('c',$now);

    price27316_cycle_need($next['pending']===null&&$next['completed']===$global['completed']
        &&($next['holds']??[])===($global['holds']??[])
        &&($next['nightly_cycle']['state']??null)!=='completed',
        'unrelated_state_mutated');
    return $next;
}

if(PHP_SAPI!=='cli') {http_response_code(404);exit;}
if(count($argv)===2&&$argv[1]==='--self-test') {
    $now=strtotime('2026-11-17T10:10:00+00:00');
    $op='commercial-monitor-price-h27316-7627b206ea11237db2e1fb365e98be5c1ff11a439b3939637fee633dee1b83d4';
    $logical='fixture-original-logical-27316';
    $past=[];
    foreach(range(1,589) as $i) $past[$i]=['operation'=>'old-'.$i,'at'=>'2026-11-16T20:00:00+00:00'];
    $completed=$past;
    $completed[27316]=['operation'=>$op,'at'=>'2026-11-17T10:05:00+00:00',
        'feed_operation'=>'commercial-channel-hotel-feeds-20261117-update123456'];
    $global=['schema'=>1,'pending'=>null,'completed'=>$completed,'holds'=>[],
        'nightly_cycle'=>['night_date'=>'2026-11-17','state'=>'started',
            'collector_run_id'=>'44012967294','collection_started_at'=>'2026-11-17T03:50:17+00:00',
            'started_at'=>'2026-11-17T04:07:12+00:00','budget_seconds'=>1800,'finish_reserve_seconds'=>300]];
    $record='7627b206ea11237db2e1fb365e98be5c1ff11a439b3939637fee633dee1b83d4';
    $receipt=static fn(string $p,int $id,int $v,string $url,string $mid):array=>[
        'state'=>'confirmed','receipt'=>['state'=>'sent','platform'=>$p,
            'publication_id'=>$id,'version_id'=>$v,'post_url'=>$url,'message_id'=>$mid,
            'existing_message_reused'=>true,'body_photo_button_verified'=>true,'price_link_verified'=>true,
            'monitor_price'=>['record_id'=>$record],
            'text_sha256'=>str_repeat('a',64),'button_text'=>'✈️ Узнать стоимость тура']];
    $child=['operation'=>$op,
        'manifest_hash'=>'94ca6dd9be5c0fb66f0bf3ee6a09ca4e7d8319bb1cd1e3ae2f8a7f7390f89a78',
        'complete'=>true,'feed_binding'=>['tourvisor_hotel_id'=>27316],
        'posts'=>[
            'max:'.$logical=>$receipt('max',400,7024,'https://max.ru/channel_anytour/AaGjuI2pXao','mid.fixture'),
            'telegram:'.$logical=>$receipt('telegram',395,4201,'https://t.me/any_toour/156','156')],
        'reconciliation'=>['max_puts'=>0,'telegram_first_edit_attempts'=>1,'new_posts'=>0,
            'original_prefix_sha256'=>'010c6ecfbdae1156a772160bbadb5fec4cbd84555e14acf10baa791c36da2988']];
    $files=[];
    foreach (['hotel-posts-telegram.xml'=>660,'hotel-posts-max.xml'=>660,'hotel-posts-all.xml'=>1320] as $name=>$num)
        $files[$name]=['rows'=>$num,'sha256'=>str_repeat('b',64),
            'local_sha256'=>str_repeat('b',64),'public_sha256'=>str_repeat('b',64)];
    $feeds=['operation'=>'commercial-channel-hotel-feeds-20261117-update123456',
        'writer'=>'original_existing_feed_writer','original_operation'=>$op,
        'new_offer_ids'=>0,'duplicate_native_ids'=>0,'files'=>$files];
    $proof=['previous_completed_sha256'=>price27316_cycle_hash($past),
        'original_logical_key'=>$logical,'original_roster_pairs'=>660,'original_scanned_pairs'=>132,
        'original_updated_pairs'=>12,'original_no_fresh_pairs'=>119,
        'original_remaining_unscanned_pairs'=>528,'original_editor_error'=>'max_button_outcome_not_confirmed',
        'max_put_attempts_during_recovery'=>0,'telegram_first_edits_during_recovery'=>1,
        'new_posts_during_recovery'=>0,'original_result_sha256'=>str_repeat('c',64),
        'original_child_final_sha256'=>str_repeat('d',64),
        'original_global_after_feed_sha256'=>str_repeat('e',64),
        'telegram_fresh_admission_at'=>'2026-11-17T10:03:00+00:00',
        'telegram_first_edit_at'=>'2026-11-17T10:04:00+00:00'];
    $next=price27316_interrupted_cycle_project($global,$child,$feeds,$proof,$now);
    price27316_cycle_need(($next['nightly_cycle']['state']??null)==='interrupted_reconciled'
        &&($next['nightly_cycle']['recovery']['original_remaining_unscanned_pairs']??null)===528
        &&$next['completed']===$global['completed']&&$next['pending']===null
        &&($next['nightly_cycle']['recovery']['recovered_one_pair']??null)===true,
        'valid_projection_failed');
    $mutations=[
        ['global','pending','non_null'],['global','holds',[27316=>['reason'=>'ignored']]],
        ['global','completed',array_slice($completed,0,589,true)],
        ['child','complete',false],['child','reconciliation',[]],
        ['feeds','new_offer_ids',1],['feeds','duplicate_native_ids',1],
        ['proof','original_scanned_pairs',660],['proof','original_remaining_unscanned_pairs',0],
        ['proof','max_put_attempts_during_recovery',1],
        ['proof','telegram_first_edits_during_recovery',2],
        ['proof','previous_completed_sha256',str_repeat('f',64)],
        ['proof','telegram_first_edit_at','2026-11-17T10:20:00+00:00'],
    ];
    $rejected=0;
    foreach($mutations as [$section,$key,$value]) {
        $arguments=['global'=>$global,'child'=>$child,'feeds'=>$feeds,'proof'=>$proof];
        $arguments[$section][$key]=$value;
        try {
            price27316_interrupted_cycle_project($arguments['global'],$arguments['child'],$arguments['feeds'],$arguments['proof'],$now);
        } catch(Throwable $e) { $rejected++; continue; }
        throw new RuntimeException('unsafe_mutation_admitted_'.$section.'_'.$key);
    }
    foreach(['max','telegram'] as $platform) {
        $wrong=$child;
        $wrong['posts'][$platform.':'.$logical]['state']='edit_started';
        try {price27316_interrupted_cycle_project($global,$wrong,$feeds,$proof,$now);}
        catch(Throwable $e) {$rejected++;continue;}
        throw new RuntimeException('unconfirmed_'.$platform.'_was_admitted');
    }
    foreach(['hotel-posts-telegram.xml','hotel-posts-max.xml','hotel-posts-all.xml'] as $name) {
        $wrong=$feeds;$wrong['files'][$name]['public_sha256']=str_repeat('1',64);
        try {price27316_interrupted_cycle_project($global,$child,$wrong,$proof,$now);}
        catch(Throwable $e) {$rejected++;continue;}
        throw new RuntimeException('unread_back_feed_admitted_'.$name);
    }
    echo 'PRICE27316_PARTIAL_CYCLE_PURE_OK controls='.($rejected+1)
        .' no_writes=1 no_sender=1 no_replay=1 original_cycle_not_completed=1',PHP_EOL;
    exit(0);
}
fwrite(STDERR,"Pure projected state only; --self-test required. No production apply.\n");
exit(2);
