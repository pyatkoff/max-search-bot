<?php
declare(strict_types=1);

function devRoadmapFixture(bool $newReceipt=true): array
{
    require_once __DIR__.'/fixtures/modeled_conversion_frontier.php';
    $pdo=new DevelopmentRoadmapTestPdo();[$pdo,$q,$clock]=devServerFixture($pdo);[$provider,$oldLog]=devServerSender();
    for($i=0;$i<4;$i++){devServerTestTick($q,$provider,devServerOrigin($clock));$clock->at+=60;}
    $old=[];
    foreach(['development_work_server_controls','development_work_server_attempts','development_work_server_raw_bytes',
        'development_work_resolutions','development_work_retirements','development_work_connection_handoffs']as$table)
        $old[$table]=$pdo->query('SELECT * FROM '.$table)->fetchAll(PDO::FETCH_ASSOC);
    [$profileCut,$apiCut,$now,$ns]=$pdo->financial(static fn(PDO $db):array=>forecastProcessingFrontierFixture($db));
    $pdo->exec("UPDATE max_channel_conversion_outbox SET state='processed',upload_status='PROCESSED',
        value_processed_at_unix=value_api_accepted_at_unix+1 WHERE upload_id IS NOT NULL AND id<>17");
    $clock->at=$now+60;$fresh=str_repeat('d',32);
    $scopes=ManagerPreparationOAuth::DEVELOPMENT_SCOPE.' '.ManagerPreparationOAuth::FORECAST_SCOPE;
    $pdo->exec("INSERT INTO manager_preparation_oauth_grants
        SELECT '".$fresh."',client_id,admin_id,project_key,resource,".$pdo->quote($scopes).",created_at,expires_at,NULL
        FROM manager_preparation_oauth_grants WHERE grant_id='".str_repeat('a',32)."'");
    $snapshot=DevelopmentRoadmapSavedResult::read($pdo,static function():void{},$clock->at);
    $frontier=$snapshot['repair_assessments']['processing_frontier'];
    $excluded=[];foreach([25,26,27]as$id){
        $row=$pdo->query('SELECT * FROM max_channel_conversion_outbox WHERE id='.$id)->fetch(PDO::FETCH_ASSOC);
        foreach(['id','value_profile_id','conversation_id','external_user_id','counter_id']as$key)$row[$key]=(string)$row[$key];
        $excluded[]=ModeledConversionValueIdentityEvidence::reference($row,$ns);
    }
    $base=['schema'=>DevelopmentRoadmapSavedResult::VERSION,'namespace_sha256'=>$ns,'membership_sha256'=>$frontier['membership_sha256'],
        'profile_cutoff_unix'=>$profileCut,'events'=>59,'value_rub'=>$frontier['observed_value_rub'],
        'old_receipts'=>array_values(array_filter($frontier['rows'],static fn(array $r):bool=>$r[6]!==null)),
        'excluded_original_refs'=>$excluded,'api_observed_not_before_unix'=>$now+10,'max_snapshot_age_seconds'=>30];
    if($newReceipt)$pdo->financial(static fn(PDO $db)=>$db->prepare("UPDATE max_channel_conversion_outbox
        SET state='accepted',upload_status='UPLOADED',upload_id='900024',value_api_accepted_at_unix=?,last_error='' WHERE id=24")->execute([$now+20]));
    $pre=$q->status(1,$fresh,DevelopmentWorkServerContract::QUEUE);
    $install=['application_sha'=>str_repeat('e',40),'original_deploy_run_id'=>1504,'verified_at_unix'=>$now+10,
        'technical_terminal_at_unix'=>$now+11,'receipt_sha256'=>str_repeat('e',64)];
    $manifest=['schema'=>DevelopmentRoadmapReview::VERSION,'queue_id'=>$pre['queue_id'],
        'archive_sha256'=>$pre['roadmap_preparation']['archive_sha256'],
        'implementation_sha256'=>$pre['roadmap_preparation']['implementation_sha256'],
        'financial_installation'=>$install,'financial_input'=>$base,'policy'=>DevelopmentRoadmapReview::policy(),
        'external_release_evidence'=>'independently_checked_owner_attestation_not_server_workflow_proof'];
    $args=['queue_id'=>$pre['queue_id'],'expected_archive_sha256'=>$pre['roadmap_preparation']['archive_sha256'],
        'expected_plan_sha256'=>$pre['plan_sha256'],'expected_caller_connection_id'=>$fresh,
        'implementation_sha256'=>$pre['roadmap_preparation']['implementation_sha256'],
        'financial_installation'=>$install,'financial_input'=>$base,'review'=>[
            'manifest_sha256'=>DevelopmentRoadmapSavedResult::hash($manifest),'decision_sha256'=>str_repeat('1',64),'readback_sha256'=>str_repeat('2',64)]];
    return [$pdo,$q,$clock,$fresh,$args,$old,$oldLog];
}
function devRoadmapSender(?callable $before=null,?callable $mutate=null): array
{
    $log=(object)['sent'=>[],'results'=>[]];
    $sender=static function(string $wire,callable $capture)use($log,$before,$mutate):array{
        $n=count($log->sent)+1;$log->sent[]=$wire;if($before!==null)$before($wire,$n);
        $request=json_decode($wire,true,32,JSON_THROW_ON_ERROR);
        $payload=json_decode($request['input'][1]['content'][0]['text'],true,32,JSON_THROW_ON_ERROR);
        $report=['approved'=>true,'candidate_sha256'=>DevelopmentRoadmapSavedResult::hash($payload['candidate']),
            'checks'=>array_map(static fn(string $id):array=>['id'=>$id,'approved'=>true,'note'=>'Synthetic independent evidence.'],DevelopmentRoadmapReview::CHECKS)];
        $raw=['id'=>'resp_SYNTHETIC_ROADMAP_'.$n,'status'=>'completed','model'=>$request['model'],
            'usage'=>['input_tokens'=>1000,'output_tokens'=>200,'total_tokens'=>1200],
            'output'=>[['type'=>'message','role'=>'assistant','status'=>'completed',
                'content'=>[['type'=>'output_text','text'=>json_encode($report,JSON_THROW_ON_ERROR)]]]]];
        $transport=['wire_request_bytes'=>$wire,'wire_complete'=>true,'http_status'=>200];
        if($mutate!==null)$mutate($raw,$report,$transport);
        $transport['wire_response_bytes']=json_encode($raw,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
        $log->results[]=['report'=>$report,'transport'=>$transport];$capture($transport);return $report;
    };
    return [$sender,$log];
}
function devRoadmapStatus(DevelopmentWorkQueue $q,string $fresh): array
{return $q->status(1,$fresh,DevelopmentWorkServerContract::QUEUE);}
function devRoadmapPhaseChecks(): void
{
    [$pdo,$q,$clock,$fresh,$args,$old,$oldLog]=devRoadmapFixture();[$sender,$log]=devRoadmapSender(
        static function()use($pdo):void{devAssert(!$pdo->inTransaction(),'roadmap_no_transaction_spans_real_transport');});
    $armed=$q->roadmap_admit(1,$fresh,$args);
    devAssert($armed['server_roadmap']['review_calls']===0&&count($armed['server_roadmap']['steps'])===1
        &&$armed['roadmap_admission_status']==='admitted_no_send'&&count($log->sent)===0,
        'roadmap_admission_creates_only_first_real_task_without_inline_send');
    $again=$q->roadmap_admit(1,$fresh,$args);
    devAssert($again['roadmap_admission_status']==='already_recorded_do_not_replay'
        &&$again['task_count']===$armed['task_count'],'roadmap_exact_admission_repeat_does_not_reset_budget_or_task');
    $changed=$args;$changed['review']['decision_sha256']=str_repeat('3',64);
    devReject(fn()=>$q->roadmap_admit(1,$fresh,$changed),DevelopmentWorkQueueConflict::class,'roadmap_conflicting_arm_is_immutable');
    $before=$q->roadmapTick(['trigger'=>'manual'], $sender);
    devAssert($before['reason']==='ordinary_cron_required'&&count($log->sent)===0,'roadmap_manual_origin_never_dispatches');
    $firstOrigin=devServerOrigin($clock);$r=$q->roadmapTick($firstOrigin,$sender);
    devAssert($r['state']==='candidate'&&count($log->sent)===0,'roadmap_first_real_canonical_read_is_only_candidate');
    $s=devRoadmapStatus($q,$fresh);$a=$s['server_roadmap']['steps'][0];
    devAssert($a['producer_run_id']!==null&&$s['accepted_count']===2&&count($s['server_roadmap']['steps'])===1,
        'roadmap_no_B_before_independent_acceptance');
    $q->roadmapTick(devServerOrigin($clock),$sender);
    devAssert(count($log->sent)===0,'roadmap_same_minute_cannot_send_review');
    $clock->at+=60;$reviewOrigin=devServerOrigin($clock);$r=$q->roadmapTick($reviewOrigin,$sender);
    devAssert($r['state']==='ready'&&count($log->sent)===1,'roadmap_A_independent_review_automatically_materializes_B');
    $s=devRoadmapStatus($q,$fresh);$steps=$s['server_roadmap']['steps'];$b=$steps[1];
    $input=json_decode(base64_decode($b['input_base64'],true),true,32,JSON_THROW_ON_ERROR);
    devAssert($steps[0]['state']==='accepted'&&$s['accepted_count']===3&&$b['state']==='ready'
        &&$b['producer_run_id']===null&&$input['parent_task_id']===$a['task_id']
        &&$input['parent_result_sha256']===$steps[0]['candidate_sha256']
        &&$input['parent_receipt_sha256']===$s['last_receipt']['receipt_sha256'],
        'roadmap_B_input_binds_actual_accepted_immutable_parent_ledger');
    $q->roadmapTick($reviewOrigin,$sender);devAssert(count($log->sent)===1,'roadmap_acceptance_tail_never_runs_B_same_cron');
    $clock->at+=60;$waiting=$q->roadmapTick(devServerOrigin($clock),$sender);
    devAssert($waiting['state']==='waiting_evidence'&&count($log->sent)===1,'roadmap_B_waits_saved_processing_without_new_send');
    $pdo->financial(static fn(PDO $db)=>$db->prepare("UPDATE max_channel_conversion_outbox SET state='processed',
        upload_status='PROCESSED',value_processed_at_unix=? WHERE id=24")->execute([$clock->at+20]));
    $clock->at+=60;$r=$q->roadmapTick(devServerOrigin($clock),$sender);
    devAssert($r['state']==='candidate'&&count($log->sent)===1,'roadmap_B_uses_later_actual_canonical_processed_snapshot');
    $clock->at+=60;$r=$q->roadmapTick(devServerOrigin($clock),$sender);
    $done=devRoadmapStatus($q,$fresh);$phase=$done['server_roadmap'];
    devAssert($r['state']==='complete'&&$phase['state']==='complete'&&$phase['review_calls']===2
        &&$phase['reserved_tokens']===32768&&$phase['reserved_micros']===2000000&&$phase['read_count']===3
        &&$done['accepted_count']===4&&$done['blocked_count']===1&&$done['task_count']===5
        &&$done['state']==='blocked'&&$done['current_run_id']===null
        &&$pdo->query("SELECT queue_id FROM development_work_lane WHERE lane_key='DEVELOPMENT'")->fetchColumn()===null,
        'roadmap_two_real_acceptances_release_claims_and_lane_keep_old_overall_blocked_and_finite_budget');
    for($i=0;$i<4;$i++){$clock->at+=60;$q->roadmapTick(devServerOrigin($clock),$sender);}
    devAssert(count($log->sent)===2,'roadmap_complete_never_adds_third_task_or_fifth_old_call');
    foreach($old as$table=>$rows)devAssert($pdo->query('SELECT * FROM '.$table)->fetchAll(PDO::FETCH_ASSOC)===$rows,
        'roadmap_every_historical_control_row_unchanged_'.$table);
    devAssert(count($oldLog->sent)===4,'roadmap_consumed_old_once_never_reused');
    require_once dirname(__DIR__).'/services/DevelopmentWorkMcpAdapter.php';
    $adapter=new DevelopmentWorkMcpAdapter($pdo,$q,static fn():int=>$clock->at);
    $status=$adapter->call('development_work_status',['queue_id'=>$args['queue_id']],1,$fresh);
    DevelopmentWorkServerContract::assertReadback($status);$tool=DevelopmentWorkServerContract::toolResult($status);
    devAssert(strlen($tool['content'][0]['text'])<=1024&&isset($tool['structuredContent']['server_roadmap']),
        'roadmap_full_original_bytes_are_structured_once_with_bounded_summary');
    $aRaw=$pdo->query('SELECT response_bytes FROM development_work_roadmap_reviews ORDER BY started_at')->fetchColumn();
    devAssert($aRaw===$log->results[0]['transport']['wire_response_bytes'],'roadmap_original_review_bytes_roundtrip_exact');
    $pdo->exec("UPDATE development_work_roadmap_steps SET candidate_bytes='{}' WHERE ordinal=1");
    devReject(fn()=>devRoadmapStatus($q,$fresh),DomainException::class,'roadmap_accepted_parent_tampering_is_not_hidden_by_receipt_hash');
}
function devRoadmapFailureChecks(): void
{
    [$pdo,$q,$clock,$fresh,$args]=devRoadmapFixture(false);[$sender,$log]=devRoadmapSender();$q->roadmap_admit(1,$fresh,$args);
    for($i=0;$i<3;$i++){$r=$q->roadmapTick(devServerOrigin($clock),$sender);$clock->at+=60;}
    $s=devRoadmapStatus($q,$fresh);
    devAssert($r['state']==='waiting_evidence'&&count($log->sent)===0&&$s['server_roadmap']['review_calls']===0
        &&count($s['server_roadmap']['steps'])===1&&$s['server_roadmap']['read_count']===3,
        'roadmap_no_new_receipt_wait_spends_no_review_budget_or_B');
    $clock->at=$s['server_roadmap']['deadline_at'];$r=$q->roadmapTick(devServerOrigin($clock),$sender);
    devAssert($r['state']==='hold'&&$r['reason']==='finite_deadline_reached'&&count($log->sent)===0,
        'roadmap_fixed_deadline_stops_instead_of_renewing_audit_forever');

    foreach(['refusal','revocation','overlap','changed_wire','duplicate_checks']as$mode){
        [$pdo,$q,$clock,$fresh,$args]=devRoadmapFixture();
        $nested=null;
        [$sender,$log]=devRoadmapSender(static function()use($mode,$pdo,$q,$clock,$fresh,&$nested):void{
            if($mode==='revocation')$pdo->exec("UPDATE manager_preparation_oauth_grants SET revoked_at=".$clock->at." WHERE grant_id='".$fresh."'");
            if($mode==='overlap'){$clock->at+=60;$nested=$q->roadmapTick(devServerOrigin($clock),static function():array{throw new RuntimeException('second_send_forbidden');});}
        },static function(array &$raw,array &$report,array &$transport)use($mode):void{
            if($mode==='refusal')$raw['status']='incomplete';
            if($mode==='changed_wire')$transport['wire_request_bytes'].=' ';
            if($mode==='duplicate_checks'){$report['checks'][1]=$report['checks'][0];$raw['output'][0]['content'][0]['text']=json_encode($report,JSON_THROW_ON_ERROR);}
        });
        $q->roadmap_admit(1,$fresh,$args);$q->roadmapTick(devServerOrigin($clock),$sender);
        $clock->at+=60;$q->roadmapTick(devServerOrigin($clock),$sender);
        $p=$pdo->query('SELECT * FROM development_work_roadmap_phases')->fetch(PDO::FETCH_ASSOC);
        $review=$pdo->query('SELECT * FROM development_work_roadmap_reviews')->fetch(PDO::FETCH_ASSOC);
        devAssert($p['state']==='unknown'&&(int)$p['review_calls']===1&&count($log->sent)===1
            &&$review['response_bytes']===$log->results[0]['transport']['wire_response_bytes']
            &&(int)$pdo->query('SELECT COUNT(*) FROM development_work_roadmap_steps')->fetchColumn()===1,
            'roadmap_original_unknown_retains_raw_and_blocks_B_'.$mode);
        for($i=0;$i<2;$i++){$clock->at+=60;$q->roadmapTick(devServerOrigin($clock),$sender);}
        devAssert(count($log->sent)===1,'roadmap_unknown_never_replayed_'.$mode);
    }
    [$pdo,$q,$clock,$fresh,$args]=devRoadmapFixture();[$sender,$log]=devRoadmapSender();$q->roadmap_admit(1,$fresh,$args);
    $pdo->exec("UPDATE max_channel_conversion_outbox SET state='unmatched',upload_status='LINKAGE_FAILURE' WHERE id=24");
    $r=$q->roadmapTick(devServerOrigin($clock),$sender);$s=devRoadmapStatus($q,$fresh);
    devAssert($r['state']==='blocked'&&$r['reason']==='new_saved_terminal_failure'&&count($log->sent)===0
        &&$s['blocked_count']===2&&count($s['server_roadmap']['steps'])===1&&$s['current_run_id']===null,
        'roadmap_actual_saved_failure_has_terminal_receipt_released_claim_and_no_successor');
}

function devRoadmapReviewOutcomeChecks(): void
{
    foreach(['approved_false','negative_check']as$mode){
        [$pdo,$q,$clock,$fresh,$args]=devRoadmapFixture();$q->roadmap_admit(1,$fresh,$args);
        [$sender,$log]=devRoadmapSender(null,static function(array &$raw,array &$report)use($mode):void{
            if($mode==='approved_false')$report['approved']=false;else $report['checks'][2]['approved']=false;
            $raw['output'][0]['content'][0]['text']=json_encode($report,JSON_THROW_ON_ERROR);
        });
        $q->roadmapTick(devServerOrigin($clock),$sender);$clock->at+=60;
        $r=$q->roadmapTick(devServerOrigin($clock),$sender);$s=devRoadmapStatus($q,$fresh);
        devAssert($r['state']==='blocked'&&$r['reason']==='independent_review_rejected'&&$s['blocked_count']===2
            &&count($s['server_roadmap']['steps'])===1&&$s['server_roadmap']['review_calls']===1
            &&$s['current_run_id']===null&&$pdo->query("SELECT queue_id FROM development_work_lane WHERE lane_key='DEVELOPMENT'")->fetchColumn()===null,
            'roadmap_known_completed_reviewer_rejection_releases_owned_claims_without_B_'.$mode);
        $clock->at+=60;$q->roadmapTick(devServerOrigin($clock),$sender);
        devAssert(count($log->sent)===1,'roadmap_completed_negative_is_not_retried_'.$mode);
    }
    foreach(['http503','non_json','partial']as$mode){
        [$pdo,$q,$clock,$fresh,$args]=devRoadmapFixture();$q->roadmap_admit(1,$fresh,$args);$log=(object)['sent'=>0];
        $raw=$mode==='non_json'?"invalid JSON \\xFF":($mode==='partial'?'{"id":"resp_PARTIAL':'{"error":"synthetic_unavailable"}');
        $sender=static function(string $wire,callable $capture)use($mode,$raw,$log):array{
            ++$log->sent;$capture(['wire_request_bytes'=>$wire,'wire_response_bytes'=>$raw,
                'wire_complete'=>$mode!=='partial','http_status'=>$mode==='http503'?503:200,
                'response_capture'=>$mode==='partial'?'incomplete_transport':'complete']);
            return ['_error'=>$mode==='partial'?'provider_timeout':'provider_unavailable'];
        };
        $q->roadmapTick(devServerOrigin($clock),$sender);$clock->at+=60;$q->roadmapTick(devServerOrigin($clock),$sender);
        $saved=$pdo->query('SELECT * FROM development_work_roadmap_reviews')->fetch(PDO::FETCH_ASSOC);
        $meta=json_decode($saved['metadata_json'],true,16,JSON_THROW_ON_ERROR);
        devAssert($saved['response_bytes']===$raw&&$meta['http_status']===($mode==='http503'?503:200)
            &&$meta['wire_complete']===($mode!=='partial')
            &&$meta['error_category']===($mode==='partial'?'provider_timeout':'provider_unavailable'),
            'roadmap_original_failed_transport_status_category_and_exact_bytes_preserved_'.$mode);
        $clock->at+=60;$q->roadmapTick(devServerOrigin($clock),$sender);
        devAssert($log->sent===1,'roadmap_failed_original_transport_never_replayed_'.$mode);
    }

    [$pdo,$q,$clock,$fresh,$args]=devRoadmapFixture();$q->roadmap_admit(1,$fresh,$args);
    [$sender,$log]=devRoadmapSender(null,static function(array &$raw):void{
        $raw['synthetic_padding']='';
        $wire=json_encode($raw,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
        $raw['synthetic_padding']=str_repeat('P',DevelopmentRoadmapReview::MAX_RESPONSE-strlen($wire));
    });
    $q->roadmapTick(devServerOrigin($clock),$sender);$clock->at+=60;
    $first=$q->roadmapTick(devServerOrigin($clock),$sender);$firstStatus=devRoadmapStatus($q,$fresh);
    devAssert($first['state']==='ready'&&$firstStatus['server_roadmap']['steps'][0]['state']==='accepted'
        &&strlen($log->results[0]['transport']['wire_response_bytes'])===DevelopmentRoadmapReview::MAX_RESPONSE,
        'roadmap_populated59_accepts_full_maximum_original_response');
    $r=$firstStatus['server_roadmap']['steps'][0]['review'];
    devAssert($r['actual_request_equals_prepared']===true&&$r['actual_request_base64']===null
        &&base64_decode($r['request_base64'],true)===$log->sent[0],
        'roadmap_complete_equal_wire_alias_preserves_exact_original_without_duplicate_bytes');
    $clock->at+=60;$pdo->financial(static fn(PDO $db)=>$db->prepare("UPDATE max_channel_conversion_outbox SET state='processed',
        upload_status='PROCESSED',value_processed_at_unix=? WHERE id=24")->execute([$clock->at]));
    $q->roadmapTick(devServerOrigin($clock),$sender);$clock->at+=60;$last=$q->roadmapTick(devServerOrigin($clock),$sender);
    $s=devRoadmapStatus($q,$fresh);
    devAssert(($last['state']==='complete'&&count($log->sent)===2)
        ||($last['state']==='hold'&&$last['reason']==='full_readback_budget_before_send'&&count($log->sent)===1
            &&$s['server_roadmap']['review_calls']===1),
        'roadmap_complete_maximum_proof_or_refusal_before_IO_without_consuming_second_once');
    DevelopmentWorkServerContract::assertReadback($s);
    devAssert(strlen(DevelopmentWorkServerContract::json($s))<=DevelopmentRoadmapReview::MAX_PROOF,
        'roadmap_all_original_proof_bytes_fit_supported_complete_wire');
}
