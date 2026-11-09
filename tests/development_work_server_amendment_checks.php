<?php
declare(strict_types=1);

/** Synthetic wire shape only. Never copy private original response or opaque bytes into fixtures. */
function devAmendmentCapture(string $raw,int $limit): array
{
    $retained='';$oversized=false;
    foreach(str_split($raw,257) as $chunk)
        if(ManagerDraftClient::captureDevelopmentChunk($retained,$oversized,$chunk,$limit)===0)break;
    $meta=null;$report=ManagerDraftClient::parseHotelResponse($retained,200,static function($m)use(&$meta):void{$meta=$m;});
    return ['raw'=>$retained,'wire_complete'=>!$oversized,'report'=>$report,'provider'=>$meta,
        'response_capture'=>$oversized?'prefix_truncated_at_budget':'complete'];
}
function devAmendmentSizedResponse(string $wire,int $ordinal,int $target,bool $usageExtra=false): array
{
    $r=devServerResponse($wire,$ordinal);$raw=json_decode($r['transport']['wire_response_bytes'],true,32,JSON_THROW_ON_ERROR);
    if($usageExtra)$raw['usage']['SYNTHETIC_UNKNOWN_USAGE_EXTRA']=str_repeat('?',8000);
    $raw['synthetic_padding']='';
    $base=json_encode($raw,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
    if(strlen($base)>$target)throw new RuntimeException('synthetic_envelope_target_too_small');
    $raw['synthetic_padding']=str_repeat('S',$target-strlen($base));
    $r['transport']['wire_response_bytes']=json_encode($raw,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
    return $r;
}
function devAmendmentLostReview(): string
{
    return json_encode(['id'=>'resp_SYNTHETIC_INCOMPLETE','status'=>'incomplete','completed_at'=>null,'error'=>null,
        'incomplete_details'=>['reason'=>'max_output_tokens'],'model'=>'gpt-6.1-sol',
        'output'=>[['type'=>'reasoning','encrypted_content'=>str_repeat('SYNTHETIC_OPAQUE_',640),'summary'=>[]],
            ['type'=>'message','role'=>'assistant','content'=>[['type'=>'output_text','text'=>str_repeat(" \n",1200)]]]],
        'usage'=>['input_tokens'=>1800,'output_tokens'=>2200,'total_tokens'=>4000]],
        JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
}
function devAmendmentFixture(): array
{
    [$p,$q,$clock]=devServerFixture();$log=(object)['sent'=>[],'results'=>[]];
    $provider=new DevelopmentWorkServerProvider(static function(string $wire,callable $capture)use($log):array{
        $n=count($log->sent)+1;$log->sent[]=$wire;
        if($n===2){
            $captured=devAmendmentCapture(devAmendmentLostReview(),8192);
            $transport=['wire_request_bytes'=>$wire,'wire_response_bytes'=>$captured['raw'],
                'wire_complete'=>false,'http_status'=>200,'response_capture_limit'=>8192,'response_capture'=>'prefix_truncated_at_budget'];
            $capture($transport);$log->results[]=$transport;return $captured['report'];
        }
        $r=devAmendmentSizedResponse($wire,$n,$n===1?6162:16384,$n>=3);
        $capture($r['transport']);$log->results[]=$r;return $r['report'];
    });
    $q->serverTick($provider,devServerOrigin($clock));$clock->at+=60;
    $q->serverTick($provider,devServerOrigin($clock));$clock->at+=60;
    $s=devServerStatus($q);$proof=$s['server_control'];$old=json_decode(base64_decode($proof['binding_base64'],true),true,32,JSON_THROW_ON_ERROR);
    $producer=$proof['attempts'][0];$reviewer=$proof['attempts'][1];
    $o=['queue_id'=>$s['queue_id'],'task_id'=>DevelopmentWorkServerContract::A,'plan_sha256'=>$s['plan_sha256'],
        'spec_sha256'=>$s['spec_sha256'],'input_sha256'=>$s['input_sha256'],'acceptance_contract_sha256'=>$s['acceptance_contract_sha256'],
        'binding_sha256'=>$proof['binding_sha256'],'legacy_sha256'=>$old['proposal']['legacy_sha256'],
        'unknown_receipt_sha256'=>$s['unknown_receipt_sha256'],'old_deadline_at'=>$old['deadline_at'],
        'producer_run_id'=>$producer['response_id'],'producer_cron_at'=>(int)$producer['cron_at'],'producer_cron_id'=>$producer['cron_id']];
    foreach(['producer'=>$producer,'reviewer'=>$reviewer] as $role=>$row){
        $o[$role.'_attempt_id']=$row['attempt_id'];
        foreach(['job','request','response'] as $part)$o[$role.'_'.$part.'_sha256']=$row[$part.'_sha256'];
    }
    $o['producer_report_sha256']=$producer['report_sha256'];
    $m=['schema'=>'development-one-a02-amendment-v1','operative'=>true,'original'=>$o,
        'review'=>['reviewer_task_id'=>DevelopmentWorkServerContract::REVIEWER,'artifact_sha256'=>str_repeat('e',64),
            'turn_id'=>'SYNTHETIC_ROOT_REVIEW_DONE','state'=>'completed','error'=>null,'completed_at'=>$clock->at,
            'source_task_id'=>DevelopmentWorkServerContract::SOURCE,'canonical_terminal_readback_sha256'=>str_repeat('d',64),
            'frozen_stateless_contract_succeeded'=>false],
        'future'=>['activation_window_seconds'=>86400,'run_window_seconds'=>3600,'max_total_calls'=>4,'already_charged_calls'=>2,
            'remaining_calls'=>2,'policy'=>$s['server_amendment_preparation']['future_policy'],
            'implementation'=>$s['server_amendment_preparation']['future_implementation']]];
    return [$p,$q,$clock,$provider,$log,$m,$s];
}
function devAmendmentGate(array $m,object $state): DevelopmentWorkServerAmendmentGate
{
    $sha=str_repeat('c',40);$stages=[];
    foreach(['provenance','key','bundle','sync','migration','max_webhook','metrika_cron','smoke','install_checks','diagnostics'] as $k)
        $stages[$k]=['execution'=>'performed','result'=>'success'];
    $gates=[];
    foreach(['deploy.yml','retired-domain-guard.yml','max-tls-preflight.yml','max-production-health.yml','telegram-production-health.yml',
        'publish-conversation-diagnostics.yml','live-session-diagnostics.yml','tour-selection-host.yml','modeled-conversion-monitor.yml',
        'modeled-pipeline-monitor-activation.yml'] as $i=>$path)
        $gates[]=['path'=>'.github/workflows/'.$path,'head_sha'=>$sha,'run_attempt'=>1,'run_id'=>(string)(100+$i),
            'conclusion'=>$i===9?'skipped':'success'];
    $status=['schema'=>2,'sha'=>$sha,'run_id'=>'100','install_attempt'=>1,'smoke_attempt'=>1,'diagnostics_attempt'=>1];
    foreach(array_keys($stages) as $k)$status[$k]='success';
    $terminal='PR9999: установлено, выпуск свободен';
    $status['repository_release']=['schema'=>1,'phase'=>'pending_terminal','kind'=>'installed','application_sha'=>$sha,'repository_sha'=>$sha,
        'release'=>['event'=>'push','run_id'=>'100','run_attempt'=>1],'stages'=>$stages,'closure'=>[
            'binding'=>['comment_id'=>6483628958,'owner_id'=>226193297,'pr'=>9999,'updated_at'=>'2026-11-09T05:00:00Z'],
            'job'=>'release-slot','run_id'=>'200','run_attempt'=>1,'terminal_step'=>'Release only the admitted publisher after all original mandatory gates',
            'expected_terminal'=>$terminal,'gates'=>$gates]];
    $comment=['id'=>6483628958,'user'=>['id'=>226193297],'issue_url'=>'https://api.github.com/repos/pyatkoff/max-search-bot/issues/1122',
        'author_association'=>'OWNER','body'=>$terminal,'updated_at'=>'2026-11-09T05:10:00Z'];
    $run=static function(int $id,string $path,string $event)use($sha):array{
        return ['id'=>$id,'run_attempt'=>1,'path'=>$path,'event'=>$event,'head_sha'=>$sha,'head_branch'=>'main',
            'head_repository'=>['full_name'=>'pyatkoff/max-search-bot'],'created_at'=>'2026-11-09T05:05:00Z',
            'status'=>'completed','conclusion'=>'success','display_title'=>'deploy-proof:v1:100:1'];
    };
    $step=static fn(int $n,string $name):array=>['number'=>$n,'name'=>$name,'status'=>'completed','conclusion'=>'success'];
    $deployJobs=[];
    foreach(['verify-inventory','verify-syntax','verify / application','verify / diagnostics','deploy','verify'] as $i=>$name){
        $names=$name==='deploy'?['Build exact deployment bundle','Sync canonical production checkout',
            'Apply database migrations and fence the old hotel contour','Reconcile canonical MAX webhook ownership',
            'Ensure Metrika offline uploader cron','Apply profile and retain exact diagnostic snapshot']:
            ($name==='verify'?['Verify installed production checks','Download exact installed diagnostic snapshot','Publish verification stage status']:[]);
        $steps=[];foreach($names as $n=>$value)$steps[]=$step($n+1,$value);
        $deployJobs[]=['id'=>1000+$i,'name'=>$name,'run_id'=>100,'head_sha'=>$sha,'status'=>'completed','conclusion'=>'success','steps'=>$steps];
    }
    $state->requests??=0;$state->sha??=$sha;
    $mode=$state->mode??'';
    if($mode==='wrong_sha')$status['sha']=str_repeat('b',40);
    if($mode==='attempt2')$status['repository_release']['release']['run_attempt']=2;
    if($mode==='missing_gate')array_pop($status['repository_release']['closure']['gates']);
    if($mode==='failed_gate')$status['repository_release']['closure']['gates'][1]['conclusion']='failure';
    if($mode==='unchanged')$status['repository_release']['kind']='application_unchanged';
    if($mode==='publisher_busy')$comment['body']='PR9999: выпуск занят';
    if($mode==='foreign_publisher')$comment['user']['id']=2;
    $digest=hash('sha256',DevelopmentWorkServerContract::json($status)."\n");
    $closerJobs=['total_count'=>1,'jobs'=>[['id'=>2000,'run_id'=>200,'head_sha'=>$sha,'name'=>'release-slot',
        'status'=>'completed','conclusion'=>'success','steps'=>[
            $step(4,'Bind the terminal candidate to the original closer run — '.$digest),
            $step(5,'Release only the admitted publisher after all original mandatory gates')]]]];
    if($mode==='wrong_digest')$closerJobs['jobs'][0]['steps'][0]['name']='Bind the terminal candidate to the original closer run — '.str_repeat('0',64);
    if($mode==='terminal_step_failed')$closerJobs['jobs'][0]['steps'][1]['conclusion']='failure';
    if($mode==='install_not_performed')$deployJobs[4]['steps'][0]['conclusion']='skipped';
    $primary=[
        '/actions/runs/100'=>$run(100,'.github/workflows/deploy.yml','push'),
        '/actions/runs/200'=>$run(200,'.github/workflows/modeled-conversion-value-export.yml','workflow_run'),
        '/actions/runs/100/attempts/1/jobs?per_page=100'=>['total_count'=>count($deployJobs),'jobs'=>$deployJobs],
        '/actions/runs/200/attempts/1/jobs?per_page=100'=>$closerJobs];
    if($mode==='closer_wrong_origin')$primary['/actions/runs/200']['display_title']='SYNTHETIC_WRONG_ORIGIN';
    if($mode==='closer_not_original')$primary['/actions/runs/200']['run_attempt']=2;
    return new DevelopmentWorkServerAmendmentGate($m,static function(string $url)use($state,$status,$comment,$primary):array{
        if(isset($state->pdo)&&$state->pdo->inTransaction())throw new RuntimeException('synthetic_network_lock_survived');
        ++$state->requests;if(!($state->released??true))throw new DomainException('amendment_publisher_not_released');
        if(($state->mode??'')==='source_drift'&&$state->requests===2)$state->sha=str_repeat('b',40);
        if(isset($state->beforeRead))($state->beforeRead)($url,$state->requests);
        if(str_contains($url,'raw.githubusercontent.com'))return $status;
        if(str_contains($url,'/issues/comments/'))return $comment;
        if(($state->mode??'')==='unproven_publication')return [];
        $key=substr($url,strlen('https://api.github.com/repos/pyatkoff/max-search-bot'));
        return $primary[$key]??[];
    },static fn():string=>$state->sha);
}
function devServerAmendmentChecks(): void
{
    devServerRawBoundaryStorageChecks();
    // The real callback sees a small report plus a large opaque envelope, not a tiny fake report only.
    [$p,$q,$clock,$provider,$log,$m,$before]=devAmendmentFixture();
    $oldProducer=base64_decode($before['server_control']['attempts'][0]['response_base64'],true);
    $lost=devAmendmentLostReview();$v1=devAmendmentCapture($lost,8192);$v2=devAmendmentCapture($lost,16384);
    devAssert(strlen($oldProducer)===6162&&strlen($v1['raw'])===8192&&!$v1['wire_complete']&&$v1['provider']===null,
        'real_shaped_opaque_and_whitespace_incomplete_reproduces_original_8192_capture_loss');
    devAssert($v2['wire_complete']&&$v2['provider']['status']==='incomplete'&&isset($v2['report']['_error']),
        'complete_incomplete_envelope_is_retained_but_never_accepted');
    $fake=devServerResponse($log->sent[0],8);$body=json_decode($fake['transport']['wire_response_bytes'],true);
    $body['output']=array_merge([['type'=>'reasoning','encrypted_content'=>str_repeat('SYNTHETIC_OPAQUE_',540),'summary'=>[]]],$body['output']);
    $wire=json_encode($body,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
    $small=devAmendmentCapture($wire,8192);$full=devAmendmentCapture($wire,16384);
    devAssert(!$small['wire_complete']&&$full['wire_complete']&&$full['provider']['status']==='completed'
        &&$full['raw']===$wire&&DevelopmentWorkServerContract::hash($full['report'])===DevelopmentWorkServerContract::hash($fake['report']),
        'valid_small_report_with_large_opaque_envelope_has_lossless_v2_transport');
    $b='';$large=false;ManagerDraftClient::captureDevelopmentChunk($b,$large,str_repeat('x',8191),8192);
    $written=ManagerDraftClient::captureDevelopmentChunk($b,$large,'é',8192);
    devAssert($written===0&&$large&&strlen($b)===8192,'actual_chunk_boundary_retains_exact_bytes_even_when_prefix_is_not_UTF8');
    $state=(object)['released'=>false,'pdo'=>$p];$gate=devAmendmentGate($m,$state);
    $held=$q->serverTick($provider,devServerOrigin($clock),$gate);
    devAssert($held['provider_calls']===0&&$held['work_accepted']===false&&$held['execution_status']==='held_existing_unknown'
        &&count($log->sent)===2&&devServerStatus($q)['accepted_count']===0,
        'pending_publication_uses_real_held_proof_without_work_credit_or_circular_deploy_gate');
    $calls=$state->requests;$q->serverTick($provider,devServerOrigin($clock),$gate);
    devAssert($calls===$state->requests,'public_release_metadata_probe_is_once_per_minute');
    $state->released=true;$clock->at+=60;$applied=$q->serverTick($provider,devServerOrigin($clock),$gate);$accepted=devServerStatus($q);
    devAssert($applied['provider_calls']===0&&$applied['acceptance_kind']==='supplementary_original_ROOT_review'
        &&$accepted['accepted_count']===1&&$accepted['task_count']===3&&$accepted['task_id']===DevelopmentWorkServerContract::B
        &&count($log->sent)===2,'one_real_original_review_amendment_accepts_A_and_materializes_B_without_model_send');
    devAssert($accepted['server_control']['attempts'][1]===$before['server_control']['attempts'][1]
        &&$accepted['server_control']['binding_base64']===$before['server_control']['binding_base64']
        &&$accepted['server_control']['reserved_calls']===2&&$accepted['worker_receipt_count']===1
        &&$accepted['last_resolution']['outcome']==='accepted_readback'
        &&$accepted['server_control']['amendment']['supplementary_acceptance_count']===1,
        'old_UNKNOWN_original_bytes_binding_and_charged_budget_survive_distinct_amendment_resolution');
    ++$clock->at;$q->serverTick($provider,devServerOrigin($clock),$gate);
    devAssert(count($log->sent)===2,'B_never_dispatches_in_A_acceptance_cron_or_minute_slot');
    $clock->at+=60;$q->serverTick($provider,devServerOrigin($clock),$gate);
    $clock->at+=60;$q->serverTick($provider,devServerOrigin($clock),$gate);$done=devServerStatus($q);
    $reviewWire=json_decode($log->sent[3],true,32,JSON_THROW_ON_ERROR);
    devAssert($reviewWire['text']['format']['schema']['properties']['candidate_sha256']['enum']===[$done['server_control']['attempts'][2]['report_sha256']],
        'reviewer_lineage_hash_is_fixed_from_original_candidate_not_recomputed_by_model');
    devAssert(count($log->sent)===4&&$done['accepted_count']===2&&$done['blocked_count']===1&&$done['retirement_count']===1
        &&$done['state']==='blocked'&&$done['task_id']===null&&$done['server_control']['state']==='complete'
        &&$done['worker_receipt_count']===2&&$done['server_control']['reserved_calls']===4,
        'same_four_charged_slots_finish_B_by_two_distinct_later_completed_original_calls_and_stop');
    $clock->at+=86400;$q->serverTick($provider,devServerOrigin($clock),$gate);
    devAssert(count($log->sent)===4,'no_future_retry_or_new_pair_after_amended_completion');
    DevelopmentWorkServerContract::assertReadback($done);devServerWrapperChecks($done);
    devAssert(strlen($done['server_control']['attempts'][2]['response_base64'])===21848
        &&strlen($done['server_control']['attempts'][3]['response_base64'])===21848,
        'four_retained_attempts_include_actual_old_sizes_and_full_16KiB_future_envelopes');
    foreach([2,3] as $n){
        $a=$done['server_control']['attempts'][$n];$raw=json_decode(base64_decode($a['response_base64'],true),true,32,JSON_THROW_ON_ERROR);
        devAssert(strlen($raw['usage']['SYNTHETIC_UNKNOWN_USAGE_EXTRA'])===8000
            &&!str_contains($a['metadata_bytes'],'SYNTHETIC_UNKNOWN_USAGE_EXTRA')
            &&strlen($a['metadata_bytes'])<=2048
            &&hash('sha256',base64_decode($a['response_base64'],true))===$a['response_sha256'],
            'unknown_usage_extra_is_full_original_raw_only_bounded_metadata_native_'.$n);
    }
    $max=$done;
    $max['server_control']['amendment']['manifest_base64']=base64_encode(str_repeat('?',8192));
    $max['server_control']['amendment']['release_proof_base64']=base64_encode(str_repeat('?',12288));
    $max['server_control']['amendment']['future_binding_base64']=base64_encode(str_repeat('?',2048));
    $max['server_control']['amendment']['receipt_base64']=base64_encode(str_repeat('?',1024));
    DevelopmentWorkServerContract::assertReadback($max);devServerWrapperChecks($max);
    $wire=['jsonrpc'=>'2.0','id'=>str_repeat("\x01",1024),'result'=>DevelopmentWorkServerContract::toolResult($max),
        'reserved_status_metadata'=>str_repeat('x',16384)];
    echo 'PROOF_MAX_JSON='.strlen(DevelopmentWorkServerContract::json($max)).' PROOF_MAX_RPC='.strlen(json_encode($wire,JSON_THROW_ON_ERROR))."\n";
    $tool=DevelopmentWorkServerContract::toolResult($done);
    devAssert(strlen($tool['content'][0]['text'])<=1024&&$tool['structuredContent']===$done
        &&!str_contains($tool['content'][0]['text'],$done['server_control']['attempts'][2]['response_base64']),
        'full_original_proof_is_single_copy_structured_content_with_short_explicit_digest_summary');
    // Fail the actual complete post-credit readback and prove atomic rollback of all business state.
    [$p,$q,$clock,$provider,$log,$m,$old]=devAmendmentFixture();$gate=devAmendmentGate($m,(object)['pdo'=>$p]);$armed=false;
    $p->beforeExecute=static function(string $sql)use(&$armed):void{
        if(str_starts_with($sql,'UPDATE development_work_server_controls SET state='))$armed=true;
        if($armed&&str_starts_with($sql,'SELECT COUNT(*) AS n FROM development_work_receipts WHERE queue_id=?'))
            throw new DomainException('synthetic_complete_readback_failure');
    };
    try{$q->serverTick($provider,devServerOrigin($clock),$gate);}catch(Throwable $error){}
    $p->beforeExecute=null;$status=devServerStatus($q);
    devAssert($status['state']==='unknown'&&$status['accepted_count']===0&&$status['task_count']===2
        &&$status['server_control']['attempts']===$old['server_control']['attempts']&&count($log->sent)===2
        &&$p->query('SELECT COUNT(*) FROM development_work_resolutions WHERE task_id="'.DevelopmentWorkServerContract::A.'"')->fetchColumn()==0,
        'complete_saved_readback_failure_rolls_back_A_credit_claim_release_resolution_and_B');
    [$p,$q,$clock,$provider,$log,$m]=devAmendmentFixture();$gate=devAmendmentGate($m,(object)['pdo'=>$p]);
    $q->serverTick($provider,devServerOrigin($clock),$gate);$clock->at+=60;
    $q->serverTick($provider,devServerOrigin($clock),$gate);$clock->at+=60;$armed=false;
    $p->beforeExecute=static function(string $sql,?array $params=null)use(&$armed):void{
        if(str_starts_with($sql,'UPDATE development_work_server_controls SET state=')&&($params[0]??null)==='complete')$armed=true;
        if($armed&&str_starts_with($sql,'SELECT COUNT(*) AS n FROM development_work_receipts WHERE queue_id=?'))
            throw new DomainException('synthetic_provider_post_credit_readback_failure');
    };
    $q->serverTick($provider,devServerOrigin($clock),$gate);$p->beforeExecute=null;$failure=devServerStatus($q);
    $saved=$failure['server_control']['attempts'][3];
    devAssert(count($log->sent)===4&&$failure['state']==='unknown'&&$failure['accepted_count']===1
        &&$saved['state']==='unknown'&&base64_decode($saved['response_base64'],true)===$log->results[3]['transport']['wire_response_bytes']
        &&$saved['report_sha256']!==null,
        'provider_post_credit_readback_failure_keeps_original_raw_report_but_rolls_back_B_credit');
    $clock->at+=60;$q->serverTick($provider,devServerOrigin($clock),$gate);
    devAssert(count($log->sent)===4,'saved_result_readback_failure_never_repeats_original_provider');
    foreach(['wrong_sha','attempt2','missing_gate','failed_gate','unchanged','publisher_busy','foreign_publisher','source_drift',
        'unproven_publication','wrong_digest','terminal_step_failed','install_not_performed','closer_wrong_origin','closer_not_original'] as $case){
        [$p,$q,$clock,$provider,$log,$m,$old]=devAmendmentFixture();
        $gate=devAmendmentGate($m,(object)['mode'=>$case,'pdo'=>$p]);
        try{$q->serverTick($provider,devServerOrigin($clock),$gate);}catch(Throwable $error){}
        $status=devServerStatus($q);
        devAssert($status['state']==='unknown'&&$status['accepted_count']===0&&$status['task_count']===2
            &&$status['server_control']['attempts']===$old['server_control']['attempts']&&count($log->sent)===2,
            'public_release_gate_'.$case.'_cannot_amend_unknown_or_spend_provider_budget');
    }
    [$p,$q,$clock,$provider,$log,$m]=devAmendmentFixture();$state=(object)['released'=>false,'pdo'=>$p];$gate=devAmendmentGate($m,$state);
    for($i=0;$i<31;++$i){$q->serverTick($provider,devServerOrigin($clock),$gate);$clock->at+=60;}
    devAssert($state->requests===30&&count($log->sent)===2&&devServerStatus($q)['accepted_count']===0,
        'fixed_public_read_probe_budget_stops_without_work_retry_or_authority_reset');
    foreach(['root_not_terminal','root_error','other_task','changed_binding','changed_old_review','new_policy','expired','malformed_claim','claim_changed_during_read'] as $case){
        [$p,$q,$clock,$provider,$log,$m]=devAmendmentFixture();
        if($case==='root_not_terminal')$m['review']['state']='in_progress';
        if($case==='root_error')$m['review']['error']='SYNTHETIC';
        if($case==='other_task')$m['original']['task_id']=DevelopmentWorkServerContract::B;
        if($case==='changed_binding')$m['original']['binding_sha256']=str_repeat('0',64);
        if($case==='changed_old_review')$m['original']['reviewer_response_sha256']=str_repeat('0',64);
        if($case==='new_policy')$m['future']['policy']['max_calls']=5;
        if($case==='expired')$clock->at=$m['review']['completed_at']+86400;
        $gateState=(object)[];
        if($case==='malformed_claim')$p->exec("UPDATE development_work_items SET claim_sha256='malformed' WHERE task_id='".DevelopmentWorkServerContract::A."'");
        if($case==='claim_changed_during_read')$gateState->beforeRead=static function(string $url,int $n)use($p):void{
            if($n===2){$s=$p->prepare('UPDATE development_work_items SET claim_sha256=? WHERE task_id=?');$s->execute([str_repeat('f',64),DevelopmentWorkServerContract::A]);}
        };
        $gate=devAmendmentGate($m,$gateState);$result=null;
        try{$result=$q->serverTick($provider,devServerOrigin($clock),$gate);}catch(Throwable $error){}
        $state=devServerStatus($q);
        devAssert($state['accepted_count']===0&&$state['task_count']===2&&count($log->sent)===2,
            'amendment_negative_'.$case.'_cannot_accept_replay_or_create_B');
    }
}


/** Real PDO roundtrip, including native MySQL: the last retained byte is deliberately invalid UTF8. */
function devServerRawBoundaryStorageChecks(): void
{
    foreach([8192,16384] as $limit){
        if($limit===8192){[$p,$q,$clock]=devServerFixture();$previous=null;$beforeSends=0;$gate=null;}
        else{
            [$p,$q,$clock,$unused,$oldLog,$m,$previous]=devAmendmentFixture();
            $gate=devAmendmentGate($m,(object)['pdo'=>$p]);$q->serverTick($unused,devServerOrigin($clock),$gate);
            $clock->at+=60;$beforeSends=2;
        }
        $retained='';$oversized=false;
        ManagerDraftClient::captureDevelopmentChunk($retained,$oversized,str_repeat('x',$limit-1),$limit);
        ManagerDraftClient::captureDevelopmentChunk($retained,$oversized,'é',$limit);
        devAssert(strlen($retained)===$limit&&preg_match('//u',$retained)===false,'synthetic_boundary_really_contains_invalid_UTF8_'.$limit);
        $sent=0;
        $provider=new DevelopmentWorkServerProvider(static function(string $wire,callable $capture)use(&$sent,$retained,$limit):array{
            ++$sent;$capture(['wire_request_bytes'=>$wire,'wire_response_bytes'=>$retained,'http_status'=>200,
                'wire_complete'=>false,'response_capture_limit'=>$limit,'response_capture'=>'prefix_truncated_at_budget']);
            return ['_error'=>'provider_unavailable'];
        });
        $q->serverTick($provider,devServerOrigin($clock),$gate);$s=devServerStatus($q);
        $attempt=$s['server_control']['attempts'][array_key_last($s['server_control']['attempts'])];
        devAssert($sent===1&&$s['state']==='unknown'&&$s['accepted_count']===($limit===8192?0:1)
            &&base64_decode($attempt['response_base64'],true)===$retained&&$attempt['response_sha256']===hash('sha256',$retained)
            &&($attempt['response_storage']??null)==='byte_exact_blob',
            'actual_'.$p->getAttribute(PDO::ATTR_DRIVER_NAME).'_serverRecord_getter_retains_exact_invalid_UTF8_'.$limit);
        $row=$p->query('SELECT response_blob FROM development_work_server_raw_bytes')->fetch(PDO::FETCH_ASSOC);
        $bytes=$row['response_blob'];if(is_resource($bytes))$bytes=stream_get_contents($bytes);
        devAssert($bytes===$retained,'PDO_LOB_storage_has_same_last_invalid_byte_'.$limit);
        if($previous!==null)devAssert($s['server_control']['attempts'][0]===$previous['server_control']['attempts'][0]
            &&$s['server_control']['attempts'][1]===$previous['server_control']['attempts'][1]
            &&$s['server_control']['receipts'][0]===$previous['server_control']['receipts'][0],
            'old_6162_8192_attempt_and_UNKNOWN_receipt_bytes_unchanged_after_B_blob_failure');
        $clock->at+=60;$q->serverTick($provider,devServerOrigin($clock),$gate);
        devAssert($sent===1,'invalid_UTF8_failure_never_dispatches_again_'.$limit);
        devServerWrapperChecks($s);
    }
}
