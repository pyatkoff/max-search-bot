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
                'profile_cutoff_unix'=>1800000000,'later_profile_events'=>0,'later_profile_value_rub'=>0.0,'observed_events'=>3,'observed_value_rub'=>600,'membership_sha256'=>$membership,
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
    $later=$snapshot;$later['repair_assessments']['new_increment_events']=4;
    $later['repair_assessments']['new_increment_value_rub']=700.0;
    $later['repair_assessments']['processing_frontier']['later_profile_events']=1;
    $later['repair_assessments']['processing_frontier']['later_profile_value_rub']=100.0;
    devAssert(DevelopmentRoadmapSavedResult::evaluate('saved_finance_new_receipts',$capsule,$later,$now)['state']==='candidate',
        'roadmap_later_profiles_reconcile_but_never_join_frozen_cohort');
    $later['repair_assessments']['new_increment_value_rub']=701.0;
    devAssert(DevelopmentRoadmapSavedResult::evaluate('saved_finance_new_receipts',$capsule,$later,$now)['state']==='blocked',
        'roadmap_later_profile_value_mismatch_not_hidden');
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


/** Fresh same-admin metadata read is separate from all write/execution authority. */
function devRoadmapArchiveChecks(): void
{
    [$pdo,$q,$clock]=devServerFixture();[$provider,$log]=devServerSender();
    for($i=0;$i<4;$i++){devServerTestTick($q,$provider,devServerOrigin($clock));$clock->at+=60;}
    $original=devServerStatus($q);$beforeRows=[];
    foreach(['development_work_queues','development_work_items','development_work_server_controls',
        'development_work_server_attempts','development_work_receipts','development_work_resolutions','development_work_retirements']as$table)
        $beforeRows[$table]=$pdo->query('SELECT * FROM '.$table)->fetchAll(PDO::FETCH_ASSOC);
    $fresh=str_repeat('d',32);
    $pdo->exec("INSERT INTO manager_preparation_oauth_grants(grant_id,client_id,admin_id,project_key,resource,scopes,created_at,expires_at,revoked_at)
        SELECT '".$fresh."',client_id,admin_id,project_key,resource,scopes,created_at,expires_at,NULL
        FROM manager_preparation_oauth_grants WHERE grant_id='".str_repeat('a',32)."'");
    $pdo->exec("UPDATE manager_preparation_oauth_grants SET revoked_at=".$clock->at." WHERE grant_id='".str_repeat('a',32)."'");
    $writes=$pdo->writes;
    $projection=$q->status(1,$fresh,DevelopmentWorkServerContract::QUEUE);
    devAssert($projection['execution_kind']==='roadmap_archive_preparation'&&$projection['accepted_count']===2
        &&$projection['roadmap_preparation']['old_control_state']==='complete'
        &&$projection['roadmap_preparation']['old_reserved_calls']===4
        &&$projection['roadmap_preparation']['expected_caller_connection_id']===$fresh,
        'roadmap_fresh_same_admin_reads_only_closed_archive_metadata_without_old_credential');
    devAssert($pdo->writes===$writes&&!isset($projection['server_control'])&&!isset($projection['server_amendment_preparation'])
        &&!isset($projection['expected_pending_event_id'])&&!isset($projection['claim_key'])
        &&$projection['roadmap_preparation']['dispatch_allowed']===false
        &&$projection['roadmap_preparation']['admission_allowed']===false,
        'roadmap_metadata_read_exposes_no_old_raw_provider_payload_claim_or_dispatch_permission');
    $again=$q->status(1,$fresh,DevelopmentWorkServerContract::QUEUE);
    devAssert($projection===$again&&$pdo->writes===$writes,'roadmap_closed_archive_read_is_stable_and_nonmutating');
    require_once dirname(__DIR__).'/services/DevelopmentWorkMcpAdapter.php';
    $adapter=new DevelopmentWorkMcpAdapter($pdo,$q,static fn():int=>$clock->at);
    $wrapped=$adapter->call('development_work_status',['queue_id'=>DevelopmentWorkServerContract::QUEUE],1,$fresh);
    devAssert($wrapped['roadmap_preparation']['archive_sha256']===$projection['roadmap_preparation']['archive_sha256']
        &&$wrapped['subscription_setup']['semantics']==='not_armed_no_native_host_route',
        'roadmap_actual_mcp_status_wrapper_does_not_probe_old_native_subscription');
    devReject(fn()=>$q->append(1,$fresh,['queue_id'=>DevelopmentWorkServerContract::QUEUE,
        'expected_plan_sha256'=>$projection['plan_sha256'],'plan_sha256'=>str_repeat('e',64),
        'tasks'=>[devSpec('GENUINE-FUTURE-TASK')]]),DomainException::class,
        'roadmap_metadata_does_not_authorize_append_or_rearm');
    devReject(fn()=>$q->nativeEventWrite(1,$fresh,DevelopmentWorkServerContract::QUEUE,static fn()=>null),DomainException::class,
        'roadmap_metadata_does_not_authorize_a_callback_or_native_worker');
    devReject(fn()=>$q->status(2,$fresh,DevelopmentWorkServerContract::QUEUE),DomainException::class,
        'roadmap_foreign_admin_cannot_read_closed_archive');
    devReject(fn()=>$q->status(1,str_repeat('e',32),DevelopmentWorkServerContract::QUEUE),DomainException::class,
        'roadmap_unproven_current_grant_cannot_read_closed_archive');
    foreach($beforeRows as$table=>$rows)devAssert($pdo->query('SELECT * FROM '.$table)->fetchAll(PDO::FETCH_ASSOC)===$rows,
        'roadmap_read_keeps_every_original_row_'.$table);
    $pdo->exec("UPDATE manager_preparation_oauth_grants SET revoked_at=".$clock->at." WHERE grant_id='".$fresh."'");
    devReject(fn()=>$q->status(1,$fresh,DevelopmentWorkServerContract::QUEUE),DomainException::class,
        'roadmap_current_revocation_blocks_even_readonly_preparation');
    devAssert(count($log->sent)===4,'roadmap_preparation_never_renews_old_four_call_budget');
}
