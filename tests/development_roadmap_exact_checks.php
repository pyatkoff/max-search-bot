<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/services/DevelopmentRoadmapExactReceipt.php';

function devRoadmapExactSavedRow(string $reference,int $now,int $api): array
{
    return ['available'=>true,'goal_id'=>'678187097','counter_id'=>'98615635','receipt_ref'=>$reference,
        'basis'=>'current_primary_saved_outbox_receipt_status','provider_requests'=>0,'conversion_uploads'=>0,
        'per_receipt_visit_linkage'=>'not_checked','checked_at'=>gmdate('Y-m-d\TH:i:s\Z',$now),
        'coverage_complete'=>true,'inspected_rows'=>24,'state'=>'accepted','upload_status'=>'UPLOADED',
        'event_at'=>gmdate('Y-m-d\TH:i:s\Z',$api-30),'first_api_observed_at'=>gmdate('Y-m-d\TH:i:s\Z',$api),
        'processed_at'=>null,'first_api_clock_known'=>true,'terminal_processing'=>false,'processed_upload_row'=>false];
}
function devRoadmapExactEvaluateChecks(): void
{
    [$base,$s,$now]=devRoadmapSavedFixture();
    $ref=hash('sha256','synthetic-ordinary-outside-repair-frontier');
    $row=devRoadmapExactSavedRow($ref,$now,$now-5);$c=$base;$c['verification_not_before_unix']=$now;
    $c['exact_receipt']=['receipt_ref'=>$ref,'baseline_saved_receipt'=>$row];
    $s['schema']=DevelopmentRoadmapExactReceipt::VERSION;$s['exact_receipt']=$row;
    $a=DevelopmentRoadmapSavedResult::evaluate('saved_finance_new_receipts',$c,$s,$now);
    devAssert($a['state']==='candidate'&&$a['schema']===DevelopmentRoadmapExactReceipt::VERSION
        &&count($a['new_receipts'])===1&&$a['new_receipts'][0]['receipt_ref']===$ref
        &&!isset($a['new_receipt_value_rub'])&&$a['repair_frontier_purpose']==='unchanged_exclusion_only_no_cohort_or_value_credit',
        'roadmap_exact_ordinary_receipt_has_separate_scope_without_false_frozen_cohort_or_money_credit');
    $hash=DevelopmentRoadmapSavedResult::hash($a);
    $pending=DevelopmentRoadmapSavedResult::evaluate('saved_finance_processing',$c,$s,$now,$a,$hash);
    devAssert($pending['state']==='waiting_evidence'&&$pending['reason']==='exact_saved_processing_pending',
        'roadmap_exact_API_observation_never_proves_final_processing');
    $done=$s;$done['captured_at']=$now+60;$done['exact_receipt']['checked_at']=gmdate('Y-m-d\TH:i:s\Z',$now+60);
    $done['exact_receipt']['state']='processed';$done['exact_receipt']['upload_status']='PROCESSED';
    $done['exact_receipt']['processed_at']=gmdate('Y-m-d\TH:i:s\Z',$now+50);
    $done['exact_receipt']['terminal_processing']=true;$done['exact_receipt']['processed_upload_row']=true;
    $b=DevelopmentRoadmapSavedResult::evaluate('saved_finance_processing',$c,$done,$now+60,$a,$hash);
    devAssert($b['state']==='candidate'&&$b['new_receipts']===$a['new_receipts']&&$b['parent_result_sha256']===$hash,
        'roadmap_exact_processing_consumes_only_its_immutable_parent_receipt_and_clocks');
    foreach(['old_terminal','membership','inside_frontier','wrong_counter','unknown_API','changed_API','missing_processed_clock','provider_request','false_terminal','wrong_snapshot_clock']as$case){
        $bad=$done;
        if($case==='old_terminal')$bad['repair_assessments']['processing_frontier']['rows'][0][2]++;
        if($case==='membership')$bad['repair_assessments']['processing_frontier']['membership_sha256']=str_repeat('f',64);
        if($case==='inside_frontier')$bad['repair_assessments']['processing_frontier']['rows'][2][6]=$ref;
        if($case==='wrong_counter')$bad['exact_receipt']['counter_id']='98615636';
        if($case==='unknown_API'){$bad['exact_receipt']['first_api_observed_at']=null;$bad['exact_receipt']['first_api_clock_known']=false;}
        if($case==='changed_API')$bad['exact_receipt']['first_api_observed_at']=gmdate('Y-m-d\TH:i:s\Z',$now-4);
        if($case==='missing_processed_clock')$bad['exact_receipt']['processed_at']=null;
        if($case==='provider_request')$bad['exact_receipt']['provider_requests']=1;
        if($case==='false_terminal')$bad['exact_receipt']['terminal_processing']=false;
        if($case==='wrong_snapshot_clock')$bad['exact_receipt']['checked_at']=gmdate('Y-m-d\TH:i:s\Z',$now+59);
        devAssert(DevelopmentRoadmapSavedResult::evaluate('saved_finance_processing',$c,$bad,$now+60,$a,$hash)['state']==='blocked',
            'roadmap_exact_rejects_'.$case);
    }
    $badParent=$a;$badParent['new_receipts'][0]['receipt_ref']=str_repeat('f',64);
    devAssert(DevelopmentRoadmapSavedResult::evaluate('saved_finance_processing',$c,$done,$now+60,$badParent,
        DevelopmentRoadmapSavedResult::hash($badParent))['state']==='blocked','roadmap_exact_rejects_other_receipt_even_with_matching_forged_parent_hash');
    $failure=$s;$failure['exact_receipt']['state']='unmatched';$failure['exact_receipt']['upload_status']='LINKAGE_FAILURE';
    $failure['exact_receipt']['terminal_processing']=true;
    devAssert(DevelopmentRoadmapSavedResult::evaluate('saved_finance_new_receipts',$c,$failure,$now)['reason']==='exact_saved_terminal_failure',
        'roadmap_exact_terminal_failure_blocks_without_review_or_next_task');
    $missing=$s;$missing['exact_receipt']=['available'=>false,'receipt_ref'=>$ref,'reason'=>'receipt_not_found_in_saved_goal_scope',
        'provider_requests'=>0,'conversion_uploads'=>0];
    devAssert(DevelopmentRoadmapSavedResult::evaluate('saved_finance_new_receipts',$c,$missing,$now)['state']==='waiting_evidence',
        'roadmap_exact_known_saved_unavailable_waits_without_financial_operations');
    $missing['exact_receipt']['reason']='ambiguous_saved_receipt';
    devAssert(DevelopmentRoadmapSavedResult::evaluate('saved_finance_new_receipts',$c,$missing,$now)['state']==='blocked',
        'roadmap_exact_ambiguous_saved_identity_cannot_wait_into_acceptance');
    $unknown=$c;$unknown['exact_receipt']['baseline_saved_receipt']['first_api_observed_at']=null;
    devReject(fn()=>DevelopmentRoadmapSavedResult::capsule($unknown),InvalidArgumentException::class,
        'roadmap_exact_admission_never_invents_an_unknown_historical_API_clock');
    $beforeCutoff=$c;$beforeCutoff['exact_receipt']['baseline_saved_receipt']['first_api_observed_at']=gmdate('Y-m-d\TH:i:s\Z',$base['api_observed_not_before_unix']-1);
    devReject(fn()=>DevelopmentRoadmapSavedResult::capsule($beforeCutoff),InvalidArgumentException::class,
        'roadmap_exact_old_API_cannot_be_relabelled_new_after_installation');
}

function devRoadmapExactPhaseFixture(): array
{
    [$pdo,$q,$clock,$fresh,$args,$old,$oldLog]=devRoadmapFixture(false);
    if($pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='sqlite'){
        $pdo->sqliteCreateFunction('CONCAT',static fn(...$parts)=>in_array(null,$parts,true)?null:implode('',array_map('strval',$parts)),-1);
        $pdo->sqliteCreateFunction('SHA2',static fn($s,$bits)=>$s===null?null:($bits===256?hash('sha256',$s):null),2);
    }
    $api=$args['financial_installation']['verified_at_unix']+10;$ref=hash('sha256','modeled_value_upload_receipt_v1|990001');
    $pdo->financial(static function(PDO $db)use($api):void{
        $db->prepare("INSERT INTO max_channel_conversion_outbox SELECT 9001,project_key,platform,9001,counter_id,
            transport_target,value_contract,'SYNTHETIC_ORDINARY',NULL,118,'accepted','UPLOADED','990001','999',
            ?,'specific_request','',?,?,NULL FROM max_channel_conversion_outbox WHERE id=24")->execute([$api-30,$api-29,$api]);
    });
    $receipt=(new ModeledConversionValueDeliveryReader($pdo,static function():void{},new ModeledConversionStatusBudget()))->savedReceiptStatus($ref,$clock->at);
    devAssert(($receipt['available']??false)&&$receipt['first_api_clock_known']&&!$receipt['terminal_processing'],
        'roadmap_exact_fixture_uses_actual_canonical_primary_ledger_reader_not_a_handmade_status');
    $args['financial_input']['exact_receipt']=['receipt_ref'=>$ref,'baseline_saved_receipt'=>$receipt];
    $manifest=['schema'=>DevelopmentRoadmapReview::VERSION,'queue_id'=>$args['queue_id'],'archive_sha256'=>$args['expected_archive_sha256'],
        'implementation_sha256'=>$args['implementation_sha256'],'financial_installation'=>$args['financial_installation'],
        'financial_input'=>$args['financial_input'],'policy'=>DevelopmentRoadmapReview::policy(),
        'external_release_evidence'=>'independently_checked_owner_attestation_not_server_workflow_proof'];
    $args['review']['manifest_sha256']=DevelopmentRoadmapSavedResult::hash($manifest);
    return [$pdo,$q,$clock,$fresh,$args,$old,$oldLog];
}
function devRoadmapExactPhaseChecks(): void
{
    [$pdo,$q,$clock,$fresh,$args,$old,$oldLog]=devRoadmapExactPhaseFixture();[$sender,$log]=devRoadmapSender();
    $before=$pdo->query('SELECT * FROM max_channel_conversion_outbox ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
    $rpc=devRoadmapRpc($pdo,$q,$clock,$fresh,'development_work_roadmap_admit',$args);
    $armed=$rpc['result']['structuredContent']??[];
    devAssert(($armed['roadmap_admission_status']??null)==='admitted_no_send'&&count($log->sent)===0,
        'roadmap_exact_real_MCP_admission_retains_strict_optional_schema_and_sends_nothing');
    $origin=devServerOrigin($clock);$a=$q->roadmapTick($origin,$sender);$q->roadmapTick($origin,$sender);
    devAssert($a['state']==='candidate'&&count($log->sent)===0
        &&$before===$pdo->query('SELECT * FROM max_channel_conversion_outbox ORDER BY id')->fetchAll(PDO::FETCH_ASSOC),
        'roadmap_exact_actual_mysql_sqlite_read_only_snapshot_produces_one_candidate_no_duplicate_or_business_write');
    $clock->at+=60;$q->roadmapTick(devServerOrigin($clock),$sender);
    $s=devRoadmapStatus($q,$fresh);$steps=$s['server_roadmap']['steps'];
    devAssert(count($steps)===2&&$steps[0]['state']==='accepted'&&$steps[1]['state']==='ready'&&count($log->sent)===1,
        'roadmap_exact_real_acceptance_transaction_creates_only_one_next_processing_task');
    $clock->at+=60;$pending=$q->roadmapTick(devServerOrigin($clock),$sender);
    devAssert($pending['state']==='waiting_evidence'&&count($log->sent)===1,
        'roadmap_exact_ordinary_pending_processing_consumes_no_review_call');
    $pdo->financial(static fn(PDO $db)=>$db->prepare("UPDATE max_channel_conversion_outbox SET state='processed',
        upload_status='PROCESSED',value_processed_at_unix=? WHERE id=9001")->execute([$clock->at+20]));
    $clock->at+=60;$q->roadmapTick(devServerOrigin($clock),$sender);
    $clock->at+=60;$last=$q->roadmapTick(devServerOrigin($clock),$sender);$done=devRoadmapStatus($q,$fresh);
    devAssert($last['state']==='complete'&&$done['server_roadmap']['review_calls']===2&&$done['server_roadmap']['read_count']===3
        &&$done['accepted_count']===4&&$done['task_count']===5&&$done['current_run_id']===null
        &&count($log->sent)===2&&$done['server_roadmap']['steps'][1]['state']==='accepted',
        'roadmap_exact_two_actual_saved_tasks_accept_then_stop_with_original_finite_limits');
    $originalProof=$done['server_roadmap'];$clock->at+=60;$q->roadmapTick(devServerOrigin($clock),$sender);
    devAssert(devRoadmapStatus($q,$fresh)['server_roadmap']===$originalProof&&count($log->sent)===2,
        'roadmap_exact_later_cron_has_no_third_task_or_duplicate_review');
    foreach($old as$table=>$rows)devAssert($pdo->query('SELECT * FROM '.$table)->fetchAll(PDO::FETCH_ASSOC)===$rows,
        'roadmap_exact_old_control_and_unknown_unchanged_'.$table);
    devAssert(count($oldLog->sent)===4,'roadmap_exact_does_not_reuse_the_old_four_call_permission');
}
