<?php
declare(strict_types=1);

/**
 * PRICE660 / Tourvisor 27316 — PURE, SOURCE-ONLY PREVIEW.
 *
 * This is NOT an executable recovery. The input is a projected evidence packet,
 * not a substitute for acquiring original journal locks, current provider reads,
 * a current monitor export, exact DB CAS, or separate owner permission.
 * No file/database/network access, writes, workers, or retries.
 */
function price27316_require(bool $ok, string $reason): void
{
    if (!$ok) throw new RuntimeException($reason);
}

function price27316_resume_preview(array $e, int $now): array
{
    $operation='commercial-monitor-price-h27316-7627b206ea11237db2e1fb365e98be5c1ff11a439b3939637fee633dee1b83d4';
    $prior='commercial-monitor-price-h27316-73871d808f646687f94862c74b7dfb78cc7b0c1cee07b74e63690ad16957198f';
    price27316_require(array_keys($e)===['global','child','max','telegram','prior','source'], 'evidence_schema_changed');
    foreach ($e as $record) price27316_require(is_array($record), 'evidence_type_changed');
    $g=$e['global'];$child=$e['child'];$max=$e['max'];$tg=$e['telegram'];$old=$e['prior'];$src=$e['source'];

    price27316_require(($g['journal_size']??null)===443818242
        &&($g['lastline_sha256']??null)==='5660cc6dad9e6a19900dd251da2cd0d8470182f114ac2dc98a2cccf2e38314b5'
        &&($g['pending_operation']??null)===$operation&&($g['pending_phase']??null)==='post_started'
        &&($g['pending_hotel_id']??null)===27316
        &&($g['child_failure']??null)==='max_button_outcome_not_confirmed'
        &&($g['collector_run_id']??null)==='44012967294'&&($g['cycle_state']??null)==='started'
        &&($g['historically_completed']??null)===589&&($g['held']??null)===0,
        'global_exact_pending_changed');

    price27316_require(($child['operation']??null)===$operation
        &&($child['sha256']??null)==='010c6ecfbdae1156a772160bbadb5fec4cbd84555e14acf10baa791c36da2988'
        &&($child['bytes']??null)===40405&&($child['lines']??null)===4
        &&($child['manifest_sha256']??null)==='94ca6dd9be5c0fb66f0bf3ee6a09ca4e7d8319bb1cd1e3ae2f8a7f7390f89a78'
        &&($child['complete']??null)===false
        &&($child['max_state']??null)==='text_edit_returned'
        &&($child['max_post_intents']??null)===1
        &&($child['telegram_post_intents']??null)===0
        &&($child['feed_intents']??null)===0,
        'child_partial_intent_changed');

    price27316_require(($max['publication_id']??null)===400
        &&($max['version_id']??null)===7024&&($max['original_version_id']??null)===4199
        &&($max['state']??null)==='requires_attention'
        &&($max['reason']??null)==='provider_read_failed'
        &&($max['write_attempts']??null)===0
        &&($max['claimed']??null)===false
        &&($max['saved_failure_receipt_sha256']??null)==='f8a9928b1fa14014fac0f3123317991f28ec3ccb6263c7261ed733bf4b542f51'
        &&($max['content_hash']??null)==='9e189b1293ff17c0598d801e87aa93c538f82b2ef6fc9b8b9687ba291fdce7a1'
        &&($max['provider_get_verified']??null)===true
        &&($max['body_photo_button_verified']??null)===true
        &&($max['built_in_preview_state']??null)==='preview'
        &&($max['built_in_preview_reason']??null)==='already_present'
        &&($max['built_in_preview_gets']??null)===3
        &&($max['built_in_preview_provider_writes']??null)===0
        &&($max['built_in_preview_db_writes']??null)===0,
        'max_confirmation_not_proven');

    price27316_require(($tg['publication_id']??null)===395&&($tg['version_id']??null)===4200
        &&($tg['state']??null)==='sent'&&($tg['claimed']??null)===false
        &&($tg['write_attempts']??null)===0
        &&($tg['post_url']??null)==='https://t.me/any_toour/156'
        &&($tg['text_sha256']??null)==='d0e98b0e1a7565219a7fcc178a121b8b54fa5e534567434bed9cbdcba5bd4bde'
        &&($tg['monitor_record_id']??null)==='73871d808f646687f94862c74b7dfb78cc7b0c1cee07b74e63690ad16957198f'
        &&($tg['photo_signature']??null)==='telegram:AQADQRxrG7t9WEt9'
        &&($tg['button_token_valid']??null)===true,
        'telegram_original_post_changed');

    price27316_require(($old['operation']??null)===$prior
        &&($old['sha256']??null)==='2f6ad68708da0b3cafdc166f292e82f158ac275ad63f8c41210d0e215f21d29b'
        &&($old['bytes']??null)===132335&&($old['lines']??null)===10
        &&($old['complete']??null)===true&&($old['feed_binding_same']??null)===true
        &&($old['max_receipt_version']??null)===4199
        &&($old['telegram_receipt_version']??null)===4200
        &&($old['both_receipts_confirmed']??null)===true,
        'previous_confirmed_operation_changed');

    $generated=strtotime((string)($src['generated_at']??''));
    $expires=strtotime((string)($src['expires_at']??''));
    $age=$src['age_seconds']??null;
    price27316_require(($src['record_id']??null)==='7627b206ea11237db2e1fb365e98be5c1ff11a439b3939637fee633dee1b83d4'
        &&($src['all_three_offers_immutable']??null)===true
        &&($src['selected_offer_immutable']??null)===true
        &&($src['scope_immutable']??null)===true
        &&($src['original_offer_count']??null)===3
        &&($src['current_offer_count']??null)===3
        &&($src['scope_from']??null)==='2026-11-18'
        &&($src['scope_to']??null)==='2027-10-31'
        &&is_int($age)&&$age>=0&&$age<129600
        &&is_int($generated)&&$generated<=$now&&$now-$generated<900
        &&is_int($expires)&&$expires>$now&&$expires<=$generated+900,
        'saved_record_not_fresh_or_immutable');

    return [
        'ok'=>true,
        'mode'=>'SOURCE_ONLY_PREPARATION_NOT_APPLY',
        'operation'=>$operation,
        'allowed_sequence'=>[
            'repeat exact locks and original hash/version checks at execution',
            'existing MAX confirmReadFailure via exact CAS, no MAX PUT',
            'verify CAS readback and original MAX content/photo/button',
            'prepare and verify one original Telegram edit including linked price entities',
            'perform at most one first Telegram edit, never retry UNKNOWN',
            'append confirmed original child receipt only after both channel proofs',
            'use existing three-feed writer and independently read all three hashes',
            'close old scheduled cycle as interrupted_reconciled, never completed',
        ],
        'new_posts_allowed'=>0,
        'max_provider_writes_allowed'=>0,
        'telegram_first_edits_allowed'=>1,
        'manual_collector_dispatch_allowed'=>false,
        'database_or_journal_writes_authorized'=>false,
        'requires_separate_exact_owner_approval'=>true,
        'requires_fresh_15_minute_source_at_apply'=>true,
        'all_660_completed'=>false,
    ];
}

// Explicit local fixture checks only. Normal invocation does not execute any recovery.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
if (count($argv) === 2 && $argv[1] === '--self-test') {
    $now=strtotime('2026-11-17T08:36:51Z');
    $fixture=[
      'global'=>['journal_size'=>443818242,'lastline_sha256'=>'5660cc6dad9e6a19900dd251da2cd0d8470182f114ac2dc98a2cccf2e38314b5',
        'pending_operation'=>'commercial-monitor-price-h27316-7627b206ea11237db2e1fb365e98be5c1ff11a439b3939637fee633dee1b83d4',
        'pending_phase'=>'post_started','pending_hotel_id'=>27316,'child_failure'=>'max_button_outcome_not_confirmed',
        'collector_run_id'=>'44012967294','cycle_state'=>'started','historically_completed'=>589,'held'=>0],
      'child'=>['operation'=>'commercial-monitor-price-h27316-7627b206ea11237db2e1fb365e98be5c1ff11a439b3939637fee633dee1b83d4',
        'sha256'=>'010c6ecfbdae1156a772160bbadb5fec4cbd84555e14acf10baa791c36da2988','bytes'=>40405,
        'lines'=>4,'manifest_sha256'=>'94ca6dd9be5c0fb66f0bf3ee6a09ca4e7d8319bb1cd1e3ae2f8a7f7390f89a78',
        'complete'=>false,'max_state'=>'text_edit_returned','max_post_intents'=>1,'telegram_post_intents'=>0,'feed_intents'=>0],
      'max'=>['publication_id'=>400,'version_id'=>7024,'original_version_id'=>4199,'state'=>'requires_attention',
        'reason'=>'provider_read_failed','write_attempts'=>0,'claimed'=>false,
        'saved_failure_receipt_sha256'=>'f8a9928b1fa14014fac0f3123317991f28ec3ccb6263c7261ed733bf4b542f51',
        'content_hash'=>'9e189b1293ff17c0598d801e87aa93c538f82b2ef6fc9b8b9687ba291fdce7a1',
        'provider_get_verified'=>true,'body_photo_button_verified'=>true,'built_in_preview_state'=>'preview',
        'built_in_preview_reason'=>'already_present','built_in_preview_gets'=>3,
        'built_in_preview_provider_writes'=>0,'built_in_preview_db_writes'=>0],
      'telegram'=>['publication_id'=>395,'version_id'=>4200,'state'=>'sent','claimed'=>false,'write_attempts'=>0,
        'post_url'=>'https://t.me/any_toour/156','text_sha256'=>'d0e98b0e1a7565219a7fcc178a121b8b54fa5e534567434bed9cbdcba5bd4bde',
        'monitor_record_id'=>'73871d808f646687f94862c74b7dfb78cc7b0c1cee07b74e63690ad16957198f',
        'photo_signature'=>'telegram:AQADQRxrG7t9WEt9','button_token_valid'=>true],
      'prior'=>['operation'=>'commercial-monitor-price-h27316-73871d808f646687f94862c74b7dfb78cc7b0c1cee07b74e63690ad16957198f',
        'sha256'=>'2f6ad68708da0b3cafdc166f292e82f158ac275ad63f8c41210d0e215f21d29b','bytes'=>132335,
        'lines'=>10,'complete'=>true,'feed_binding_same'=>true,'max_receipt_version'=>4199,
        'telegram_receipt_version'=>4200,'both_receipts_confirmed'=>true],
      'source'=>['record_id'=>'7627b206ea11237db2e1fb365e98be5c1ff11a439b3939637fee633dee1b83d4',
        'all_three_offers_immutable'=>true,'selected_offer_immutable'=>true,'scope_immutable'=>true,
        'original_offer_count'=>3,'current_offer_count'=>3,'scope_from'=>'2026-11-18','scope_to'=>'2027-10-31',
        'age_seconds'=>16897,'generated_at'=>'2026-11-17T08:36:51Z','expires_at'=>'2026-11-17T08:51:51Z'],
    ];
    $plan=price27316_resume_preview($fixture,$now);
    if (!$plan['ok']||$plan['database_or_journal_writes_authorized']||$plan['new_posts_allowed']!==0) throw new RuntimeException('selftest_normal_failed');
    $changes=[
      ['global','pending_phase','feed_started'],['global','lastline_sha256','missing'],
      ['global','historically_completed',590],['global','cycle_state','completed'],
      ['child','telegram_post_intents',1],['child','max_state','confirmed'],['child','complete',true],
      ['max','state','sent'],['max','reason','provider_rejected_edit'],['max','write_attempts',1],
      ['max','built_in_preview_provider_writes',1],['max','content_hash','invalid'],
      ['telegram','version_id',4201],['telegram','state','unknown'],
      ['telegram','monitor_record_id','wrong'],['telegram','button_token_valid',false],
      ['prior','complete',false],['prior','feed_binding_same',false],
      ['source','all_three_offers_immutable',false],['source','selected_offer_immutable',false],
      ['source','age_seconds',129600],['source','record_id','wrong'],
    ];
    $rejected=0;
    foreach ($changes as [$section,$key,$value]) {
        $case=$fixture;$case[$section][$key]=$value;
        try { price27316_resume_preview($case,$now); }
        catch (Throwable $e) { $rejected++;continue; }
        throw new RuntimeException('selftest_unsafe_admitted_'.$section.'_'.$key);
    }
    foreach ([['source','expires_at','2026-11-17T08:36:51Z'],
              ['source','generated_at','2026-11-17T08:16:51Z']] as [$section,$key,$value]) {
        $case=$fixture;$case[$section][$key]=$value;
        try { price27316_resume_preview($case,$now); }
        catch (Throwable $e) { $rejected++;continue; }
        throw new RuntimeException('selftest_expired_admitted_'.$key);
    }
    echo 'PRICE27316_PURE_RESUME_READINESS_OK controls='.($rejected+1)
        .' no_production_writes=1 no_provider_requests=1 no_replay=1',PHP_EOL;
    exit(0);
}
fwrite(STDERR,"This is a source-only preview. Run only --self-test; production apply is not implemented.\n");
exit(2);
