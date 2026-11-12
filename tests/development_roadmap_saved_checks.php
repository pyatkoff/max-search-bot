<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/services/DevelopmentRoadmapSavedResult.php';

function devRoadmapSavedFixture(): array
{
    $now=1800000100;$ns=hash('sha256','synthetic-roadmap-namespace');
    $ref=static fn(string $s):string=>hash('sha256','synthetic-'.$s);
    $rows=[
        [$ref('old'),1,100,5,1,6,$ref('old-receipt'),1799999900,1799999990],
        [$ref('excluded'),1,200,1,2,1,null,null,null],
        [$ref('new'),2,300,4,1,3,$ref('new-receipt'),1800000098,null],
    ];
    $members=[];foreach($rows as$r)$members[$r[0]]=[$r[0],$r[1],$r[2]];ksort($members,SORT_STRING);
    $membership=hash('sha256',json_encode(['repair_processing_frontier_v1',$ns,1800000000,array_values($members)],JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR));
    $capsule=['schema'=>DevelopmentRoadmapSavedResult::VERSION,'namespace_sha256'=>$ns,'membership_sha256'=>$membership,
        'profile_cutoff_unix'=>1800000000,'events'=>3,'value_rub'=>600,'old_receipts'=>[$rows[0]],
        'excluded_original_refs'=>[$rows[1][0]],'not_before_unix'=>1800000090,'max_snapshot_age_seconds'=>30];
    $snapshot=['available'=>true,'schema'=>DevelopmentRoadmapSavedResult::VERSION,'captured_at'=>$now,'provider_requests'=>0,
        'repair_assessments'=>['available'=>true,'assessment_namespace'=>$ns,'provider_requests'=>0,
            'coverage'=>['whole_current_namespace_repair_cohort'=>true],'new_increment_events'=>3,'new_increment_value_rub'=>600.0,
            'processing_frontier'=>['available'=>true,'complete_within_bound'=>true,'provider_requests_in_reader'=>0,
                'profile_cutoff_unix'=>1800000000,'observed_events'=>3,'observed_value_rub'=>600,'membership_sha256'=>$membership,
                'columns'=>['event_ref','platform','value_rub','state','hold_reason','upload_status','receipt_ref','api_first_unix','processed_unix'],
                'legend'=>['state'=>['unknown','pending','ready','uploading','accepted','processed','unknown','failed','expired','unmatched'],
                    'upload_status'=>['unknown','not_recorded','PREPARED','UPLOADED','EXPORTED','MATCHED','PROCESSED','LINKAGE_FAILURE']],
                'rows'=>$rows]]];
    return [$capsule,$snapshot,$now];
}
function devRoadmapSavedChecks(): void
{
    [$capsule,$snapshot,$now]=devRoadmapSavedFixture();
    $candidate=DevelopmentRoadmapSavedResult::evaluate('saved_finance_new_receipts',$capsule,$snapshot,$now);
    devAssert($candidate['state']==='candidate'&&count($candidate['new_receipts'])===1
        &&$candidate['new_receipt_value_rub']===300&&$candidate['old_terminal_receipts_unchanged']===1,
        'roadmap_real_saved_new_receipt_excludes_old_and_original_case');
    devAssert($candidate['automatic_acceptance_allowed']===false&&$candidate['dispatch_allowed']===false
        &&$candidate['provider_requests']===0&&$candidate['mutation_performed']===false
        &&$candidate['coding_or_deployment_proof']===false&&$candidate['per_receipt_visit_linkage']==='not_checked',
        'roadmap_saved_candidate_never_impersonates_owner_acceptance_coding_deploy_or_visit');
    $hash=DevelopmentRoadmapSavedResult::hash($candidate);
    $done=$snapshot;$done['captured_at']=$now+60;
    $done['repair_assessments']['processing_frontier']['rows'][2][3]=5;
    $done['repair_assessments']['processing_frontier']['rows'][2][5]=6;
    $done['repair_assessments']['processing_frontier']['rows'][2][8]=$now+40;
    $terminal=DevelopmentRoadmapSavedResult::evaluate('saved_finance_processing',$capsule,$done,$now+60,$candidate,$hash);
    devAssert($terminal['state']==='candidate'&&$terminal['parent_result_sha256']===$hash
        &&$terminal['new_receipts']===$candidate['new_receipts']&&$terminal['new_receipt_value_rub']===300,
        'roadmap_processing_uses_exact_saved_parent_targets_not_a_new_cohort');
    $waiting=DevelopmentRoadmapSavedResult::evaluate('saved_finance_processing',$capsule,$snapshot,$now,$candidate,$hash);
    devAssert($waiting['state']==='waiting_evidence'&&$waiting['reason']==='new_saved_processing_pending',
        'roadmap_api_acceptance_is_not_final_processing');
    $failed=$snapshot;$failed['repair_assessments']['processing_frontier']['rows'][2][3]=7;
    $failed['repair_assessments']['processing_frontier']['rows'][2][5]=7;
    $failure=DevelopmentRoadmapSavedResult::evaluate('saved_finance_processing',$capsule,$failed,$now,$candidate,$hash);
    devAssert($failure['state']==='blocked'&&$failure['reason']==='new_saved_linkage_failure',
        'roadmap_actual_linkage_failure_does_not_earn_success');
    $unavailable=DevelopmentRoadmapSavedResult::evaluate('saved_finance_new_receipts',$capsule,['available'=>false],$now);
    devAssert($unavailable['state']==='waiting_evidence','roadmap_unavailable_read_does_not_invent_result');
    $noNew=$snapshot;$noNew['repair_assessments']['processing_frontier']['rows'][2][6]=null;
    $noNew['repair_assessments']['processing_frontier']['rows'][2][7]=null;
    devAssert(DevelopmentRoadmapSavedResult::evaluate('saved_finance_new_receipts',$capsule,$noNew,$now)['state']==='waiting_evidence',
        'roadmap_old_receipt_and_counts_alone_do_not_create_new_financial_result');
    $excluded=$noNew;$excluded['repair_assessments']['processing_frontier']['rows'][1][3]=4;
    $excluded['repair_assessments']['processing_frontier']['rows'][1][5]=3;
    $excluded['repair_assessments']['processing_frontier']['rows'][1][6]=hash('sha256','synthetic-excluded-receipt');
    $excluded['repair_assessments']['processing_frontier']['rows'][1][7]=$now-1;
    devAssert(DevelopmentRoadmapSavedResult::evaluate('saved_finance_new_receipts',$capsule,$excluded,$now)['state']==='waiting_evidence',
        'roadmap_original_excluded_operation_is_never_adopted_as_new_work');
    $cases=[];
    $x=$snapshot;$x['repair_assessments']['processing_frontier']['rows'][2][6]=$x['repair_assessments']['processing_frontier']['rows'][0][6];$cases['duplicate_receipt']=$x;
    $x=$snapshot;$x['repair_assessments']['processing_frontier']['rows'][2]=$x['repair_assessments']['processing_frontier']['rows'][0];$cases['duplicate_event']=$x;
    $x=$snapshot;$x['repair_assessments']['processing_frontier']['rows'][0][4]=2;$cases['old_terminal_changed']=$x;
    $x=$snapshot;$x['repair_assessments']['processing_frontier']['rows'][2][2]=301;$cases['value_changed']=$x;
    $x=$snapshot;array_pop($x['repair_assessments']['processing_frontier']['rows']);$cases['truncated_roster']=$x;
    $x=$snapshot;$x['repair_assessments']['processing_frontier']['complete_within_bound']=false;$cases['incomplete_roster']=$x;
    $x=$snapshot;$x['repair_assessments']['assessment_namespace']=str_repeat('e',64);$cases['foreign_namespace']=$x;
    $x=$snapshot;$x['repair_assessments']['processing_frontier']['membership_sha256']=str_repeat('e',64);$cases['foreign_membership']=$x;
    $x=$snapshot;$x['repair_assessments']['processing_frontier']['legend']['state'][5]='ready';$cases['changed_legend']=$x;
    $x=$snapshot;$x['provider_requests']=1;$cases['hidden_provider_operation']=$x;
    $x=$snapshot;$x['captured_at']=$now+1;$cases['future_capture']=$x;
    $x=$snapshot;$x['captured_at']=$now-31;$cases['stale_capture']=$x;
    $x=$snapshot;$x['repair_assessments']['processing_frontier']['rows'][2][8]=$now+10;$cases['future_processing_clock']=$x;
    $x=$snapshot;$x['unexpected']='extra';$cases['unreviewed_snapshot_field']=$x;
    foreach($cases as$name=>$bad)devAssert(DevelopmentRoadmapSavedResult::evaluate('saved_finance_new_receipts',$capsule,$bad,$now)['state']==='blocked','roadmap_reject_'.$name);
    $wrong=$candidate;$wrong['new_receipts'][0]['value_rub']++;
    devAssert(DevelopmentRoadmapSavedResult::evaluate('saved_finance_processing',$capsule,$done,$now+60,$wrong,$hash)['state']==='blocked',
        'roadmap_changed_parent_never_reuses_old_result_hash');
    $wrong=$candidate;$wrong['new_receipts'][0]['event_ref']=$capsule['excluded_original_refs'][0];
    devAssert(DevelopmentRoadmapSavedResult::evaluate('saved_finance_processing',$capsule,$done,$now+60,$wrong,DevelopmentRoadmapSavedResult::hash($wrong))['state']==='blocked',
        'roadmap_hash_alone_does_not_authorize_excluded_parent_target');
    $changed=$capsule;$changed['old_receipts'][]=$changed['old_receipts'][0];
    devAssert(DevelopmentRoadmapSavedResult::evaluate('saved_finance_new_receipts',$changed,$snapshot,$now)['state']==='blocked',
        'roadmap_duplicate_frozen_terminal_rejected');
    foreach(['coding','deploy','hotel_research','metrika_upload','native_owner_dispatch']as$kind){
        $status=DevelopmentRoadmapSavedResult::capability($kind);
        devAssert(!$status['supported']&&!$status['dispatch_allowed']
            &&DevelopmentRoadmapSavedResult::evaluate($kind,$capsule,$snapshot,$now)['state']==='blocked','roadmap_no_fake_route_'.$kind);
    }
    $pdo=new PDO('sqlite::memory:');$pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
    devReject(static fn()=>DevelopmentRoadmapSavedResult::read($pdo,static fn():bool=>false,$now),DomainException::class,
        'roadmap_explicit_false_authorization_stops_before_business_read');
    $pdo->beginTransaction();
    devReject(static fn()=>DevelopmentRoadmapSavedResult::read($pdo,static fn():bool=>true,$now),InvalidArgumentException::class,
        'roadmap_never_joins_a_foreign_business_transaction');
    devAssert($pdo->inTransaction(),'roadmap_foreign_transaction_is_not_rolled_back');
    $pdo->rollBack();$authCalls=0;
    $read=DevelopmentRoadmapSavedResult::read($pdo,static function()use(&$authCalls):void{++$authCalls;},$now);
    devAssert($read['available']===false&&$read['provider_requests']===0&&$authCalls>=2&&!$pdo->inTransaction(),
        'roadmap_missing_real_schema_remains_unavailable_and_own_snapshot_is_closed');
    devAssert((int)$pdo->query("SELECT COUNT(*) FROM sqlite_master WHERE type='table'")->fetchColumn()===0,
        'roadmap_canonical_reader_creates_no_business_table_or_write');
}
