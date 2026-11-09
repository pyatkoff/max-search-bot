<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/services/DevelopmentWorkServerRuntime.php';

/** Synthetic provider bytes only. No key, live queue, network or worker is used. */
function devServerFixture(?PDO $pdo=null,bool $arm=true): array
{
    $pdo??=new DevelopmentWorkTestPdo();$clock=(object)['at'=>1794200400];
    $q=new DevelopmentWorkQueue($pdo,static fn():int=>$clock->at);
    $old=devSpec('DEV-CONTROL-COMMISSION-20261028-01');
    $old['owner_task_id']='Automation_76dda46db37c8191815e12bde323b6aa';
    $q->create(1,str_repeat('a',32),['queue_id'=>DevelopmentWorkServerContract::QUEUE,
        'plan_sha256'=>str_repeat('0',64),'tasks'=>[$old]]);
    $s=$q->status(1,str_repeat('a',32),DevelopmentWorkServerContract::QUEUE);
    $q->retire_unclaimed(1,str_repeat('a',32),['queue_id'=>$s['queue_id'],'task_id'=>$s['task_id'],
        'spec_sha256'=>$s['spec_sha256'],'expected_plan_sha256'=>$s['plan_sha256'],
        'expected_pending_event_id'=>$s['expected_pending_event_id'],
        'decision'=>['decision_sha256'=>str_repeat('4',64),'readback_sha256'=>str_repeat('5',64),'reviewer_run_id'=>'SYNTHETIC-RETIRE']]);
    $candidate=$q->status(1,str_repeat('a',32),DevelopmentWorkServerContract::QUEUE)['server_model_candidate'];
    $args=array_intersect_key($candidate,array_flip(['queue_id','expected_plan_sha256','plan_sha256','tasks']));
    if($arm)$q->append(1,str_repeat('a',32),$args);
    return [$pdo,$q,$clock,$args,$candidate];
}
function devServerStatus(DevelopmentWorkQueue $q): array
{return $q->status(1,str_repeat('a',32),DevelopmentWorkServerContract::QUEUE);}
function devServerOrigin(object $clock,string $id=''): array
{return ['trigger'=>'cron','cron_id'=>$id===''?bin2hex(random_bytes(32)):$id,'started_at'=>$clock->at];}
function devServerReport(array $payload,string $role): array
{
    $kind=$payload['input']['kind'];$checks=[];
    foreach(DevelopmentWorkServerContract::checks($kind) as $id)$checks[]=$role==='producer'
        ?['id'=>$id,'state'=>$id==='legacy_unknown'?'unknown':'pass','refs'=>[DevelopmentWorkServerContract::referenceFor($id)],'note'=>'Synthetic checked evidence.']
        :['id'=>$id,'approved'=>true,'note'=>'Synthetic independently checked evidence.'];
    return $role==='producer'?['kind'=>$kind,'observed_accepted_count'=>$kind==='commissioning'?0:1,
        'code_deploy_verified'=>false,'checks'=>$checks,'limitations'=>DevelopmentWorkServerContract::template($kind)['limitations'],'readiness'=>'ready']
        :['approved'=>true,'candidate_sha256'=>DevelopmentWorkServerContract::hash($payload['candidate']),'checks'=>$checks];
}
function devServerResponse(string $wire,int $ordinal=1,?callable $mutate=null): array
{
    $request=json_decode($wire,true,32,JSON_THROW_ON_ERROR);
    $payload=json_decode($request['input'][1]['content'][0]['text'],true,32,JSON_THROW_ON_ERROR);
    $role=$request['text']['format']['name']==='development_server_producer'?'producer':'reviewer';
    $report=devServerReport($payload,$role);
    $raw=['id'=>'resp_SYNTHETIC_'.$ordinal,'status'=>'completed','model'=>$request['model'],
        'usage'=>['input_tokens'=>100,'output_tokens'=>200,'total_tokens'=>300],
        'output'=>[['type'=>'message','role'=>'assistant','status'=>'completed',
            'content'=>[['type'=>'output_text','text'=>DevelopmentWorkServerContract::json($report)]]]]];
    $transport=['wire_request_bytes'=>$wire,'wire_complete'=>true,'http_status'=>200];
    if($mutate!==null)$mutate($raw,$report,$transport);
    $transport['wire_response_bytes']=json_encode($raw,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
    return ['report'=>$report,'transport'=>$transport,'payload'=>$payload];
}
function devServerSender(?callable $mutate=null,?callable $before=null): array
{
    $log=(object)['sent'=>[],'results'=>[]];
    $provider=new DevelopmentWorkServerProvider(static function(string $wire,callable $capture)use($log,$mutate,$before):array{
        $ordinal=count($log->sent)+1;$log->sent[]=$wire;
        if($before!==null)$before($wire,$ordinal);
        $r=devServerResponse($wire,$ordinal,$mutate);$log->results[]=$r;$capture($r['transport']);return $r['report'];
    });
    return [$provider,$log];
}

/** Independent process joins the same real SQLite/MySQL queue; no in-process lock fake. */
function devServerRaceChild(array $argv): void
{
    [$unused,$mode,$file,$out,$go]=array_pad($argv,5,'');
    $pdo=new DevelopmentWorkTestPdo($file==='MYSQL'?null:$file,false);
    $clock=(object)['at'=>1794200400];$q=new DevelopmentWorkQueue($pdo,static fn():int=>$clock->at);
    [$provider,$log]=devServerSender(null,static function()use($pdo,$out):void {
        if($pdo->inTransaction())throw new RuntimeException('network_lock_survived');
        file_put_contents($out.'.sending','reserved-before-send');$until=microtime(true)+10;
        while(!is_file($out.'.release')&&microtime(true)<$until)usleep(1000);
        if(!is_file($out.'.release'))throw new RuntimeException('sender_release_missing');
    });
    file_put_contents($out.'.ready','ready');$until=microtime(true)+10;
    while(!is_file($go)&&microtime(true)<$until)usleep(1000);
    if(!is_file($go))exit(3);
    $r=$q->serverTick($provider,devServerOrigin($clock));
    file_put_contents($out,json_encode(['result'=>$r,'sends'=>count($log->sent)],JSON_THROW_ON_ERROR));exit(0);
}

function devServerChecks(): void
{
    [$pdo,$q,$clock,$args,$candidate]=devServerFixture(null,false);
    $before=$pdo->writes;$again=devServerStatus($q);
    devAssert($before===$pdo->writes&&count($candidate['tasks'])===1
        &&$candidate['tasks'][0]['task_id']===DevelopmentWorkServerContract::A
        &&!isset($candidate['inputs'][DevelopmentWorkServerContract::B])
        &&$again['worker_receipt_count']===0,'server_candidate_is_readonly_A_only_no_future_B_input');
    $bad=$args;$bad['tasks'][]=devSpec(DevelopmentWorkServerContract::B);
    devReject(fn()=>$q->append(1,str_repeat('a',32),$bad),[DevelopmentWorkQueueConflict::class,InvalidArgumentException::class],
        'cannot_prematerialize_B_or_change_exact_admission');
    $q->append(1,str_repeat('a',32),$args);
    devAssert((int)$pdo->query('SELECT COUNT(*) FROM development_work_server_attempts')->fetchColumn()===0
        &&(int)$pdo->query('SELECT COUNT(*) FROM development_work_server_events')->fetchColumn()===0,
        'explicit_append_arms_future_cron_without_inline_send_or_pending');
    $binding=$pdo->query('SELECT * FROM development_work_server_controls')->fetch(PDO::FETCH_ASSOC);
    $legacy=$binding['legacy_sha256'];$s=devServerStatus($q);
    $native=['queue_id'=>$s['queue_id'],'task_id'=>$s['task_id'],'spec_sha256'=>$s['spec_sha256'],'claim_key'=>str_repeat('b',64)];
    foreach([
        fn()=>$q->claim(1,str_repeat('a',32),$native+['expected_pending_event_id'=>'dev_evt_'.str_repeat('e',64)]),
        fn()=>$q->started(1,str_repeat('a',32),$native+['run_id'=>'FAKE']),
        fn()=>$q->complete(1,str_repeat('a',32),$native+['receipt'=>devReceipt()]),
        fn()=>$q->markEventDelivered(1,str_repeat('a',32),$s['queue_id'],'dev_evt_'.str_repeat('e',64)),
        fn()=>$q->nativeEventWrite(1,str_repeat('a',32),$s['queue_id'],static fn()=>null),
        fn()=>$q->append(1,str_repeat('a',32),['queue_id'=>$s['queue_id'],'expected_plan_sha256'=>$s['plan_sha256'],
            'plan_sha256'=>str_repeat('f',64),'tasks'=>[devSpec('EXTRA')]])
    ] as $deny)devReject($deny,DevelopmentWorkQueueConflict::class,'server_bound_queue_fences_native_bypass');
    devAssert($q->pendingEvent(1,str_repeat('a',32),$s['queue_id'])===null,'native_callback_cannot_observe_or_ack_server_pending');
    [$provider,$log]=devServerSender(static function(array &$raw):void {
        $raw['padding']='';$size=strlen(json_encode($raw,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR));
        $raw['padding']=str_repeat('x',8192-$size);
    },static function()use($pdo):void{
        devAssert(!$pdo->inTransaction(),'server_network_outside_transaction');
        $a=$pdo->query('SELECT * FROM development_work_server_attempts ORDER BY cron_at DESC LIMIT 1')->fetch(PDO::FETCH_ASSOC);
        $c=$pdo->query('SELECT * FROM development_work_server_controls')->fetch(PDO::FETCH_ASSOC);
        devAssert($a['state']==='unknown'&&hash('sha256',$a['request_bytes'])===$a['request_sha256']
            &&(int)$c['reserved_calls']>=1&&$c['state']==='unknown','durable_unknown_wire_and_budget_committed_before_send');
    });
    devAssert($q->serverTick($provider,['trigger'=>'manual'])['provider_calls']===0
        &&$log->sent===[],'manual_tick_never_executes_server_control');
    $r=$q->serverTick($provider,devServerOrigin($clock));devAssert($r['state']==='candidate','A_producer_is_not_acceptance');
    $origin=devServerOrigin($clock);
    devAssert($q->serverTick($provider,$origin)['provider_calls']===0&&count($log->sent)===1,
        'same_minute_cannot_send_reviewer');
    $clock->at+=60;$r=$q->serverTick($provider,devServerOrigin($clock));
    $s=devServerStatus($q);$proof=$s['server_control'];
    devAssert($r['accepted_task']===DevelopmentWorkServerContract::A&&$s['accepted_count']===1
        &&$s['task_count']===3&&$s['task_id']===DevelopmentWorkServerContract::B
        &&count($proof['receipts'])===1&&count($proof['events'])===2,'A_accept_atomically_materializes_B_and_one_new_pending');
    $b=$pdo->query("SELECT * FROM development_work_items WHERE task_id='".DevelopmentWorkServerContract::B."'")->fetch(PDO::FETCH_ASSOC);
    $event=$pdo->query("SELECT * FROM development_work_server_events WHERE task_id='".DevelopmentWorkServerContract::B."'")->fetch(PDO::FETCH_ASSOC);
    $review=$pdo->query("SELECT * FROM development_work_server_attempts WHERE task_id='".DevelopmentWorkServerContract::A."' AND role='reviewer'")->fetch(PDO::FETCH_ASSOC);
    devAssert($event['created_cron_id']===$review['cron_id']&&$event['state']==='pending'
        &&$b['claim_sha256']===null&&$b['current_run_id']===null,'B_outbox_commits_unclaimed_with_actual_A_reviewer_origin');
    devAssert($q->serverTick($provider,devServerOrigin($clock))['provider_calls']===0&&count($log->sent)===2,
        'B_not_dispatched_from_A_acceptance_tail_or_same_slot');
    $clock->at+=60;$r=$q->serverTick($provider,devServerOrigin($clock));
    $bp=json_decode(json_decode($log->sent[2],true,32,JSON_THROW_ON_ERROR)['input'][1]['content'][0]['text'],true,32,JSON_THROW_ON_ERROR);
    $receipt=json_decode($proof['receipts'][0]['receipt_json'],true,16,JSON_THROW_ON_ERROR);
    devAssert($r['state']==='candidate'&&$bp['input']['accepted_parent']['receipt_sha256']===$proof['receipts'][0]['receipt_sha256']
        &&$bp['input']['accepted_parent']['result_sha256']===$receipt['result_sha256']
        &&$bp['input']['accepted_parent']['reviewer_run_id']===$receipt['reviewer_run_id']
        &&$bp['execution']['cron_id']!==$event['created_cron_id'],'later_B_consumes_actual_accepted_A_bytes_and_new_cron');
    $clock->at+=60;$q->serverTick($provider,devServerOrigin($clock));$s=devServerStatus($q);
    devAssert($s['task_count']===3&&$s['accepted_count']===2&&$s['blocked_count']===1
        &&$s['retirement_count']===1&&$s['worker_receipt_count']===2&&$s['task_id']===null&&$s['state']==='blocked'
        &&$s['server_control']['state']==='complete','final_truth_preserves_retired_A01_and_two_worker_acceptances');
    devAssert($pdo->query('SELECT queue_id FROM development_work_lane')->fetchColumn()===null
        &&(int)$pdo->query('SELECT COUNT(*) FROM development_work_items WHERE claim_sha256 IS NOT NULL OR current_run_id IS NOT NULL')->fetchColumn()===0
        &&$s['server_control']['reserved_calls']===4&&$legacy===$pdo->query('SELECT legacy_sha256 FROM development_work_server_controls')->fetchColumn(),
        'finish_releases_owned_WIP_retains_legacy_and_four_spent_reservations');
    $clock->at+=86400;$writes=$pdo->writes;
    devAssert($q->serverTick($provider,devServerOrigin($clock))['provider_calls']===0&&count($log->sent)===4&&$writes===$pdo->writes,
        'finished_pair_never_sends_fifth_call_or_changes_ledger');
    foreach($s['server_control']['attempts'] as $i=>$a) {
        foreach(['job','request','response','report'] as $part)
            devAssert(hash('sha256',base64_decode($a[$part.'_base64'],true))===$a[$part.'_sha256'],'full_original_bytes_roundtrip_'.$part);
        devAssert(base64_decode($a['request_base64'],true)===$log->sent[$i],'saved_request_is_actual_sent_wire_not_rebuilt_profile');
        $raw=json_decode(base64_decode($a['response_base64'],true),true,32,JSON_THROW_ON_ERROR);
        devAssert($a['response_id']===$raw['id']&&$raw['id']==='resp_SYNTHETIC_'.($i+1),'response_identity_from_original_transport');
    }
    $last=$s['server_control']['attempts'][3];
    devAssert($q->serverRecord($last['attempt_id'],$log->results[3])['provider_calls']===0,'same_original_result_reconciliation_never_replays');
    $changed=$log->results[3];$changed['transport']['wire_response_bytes'].=' ';
    devReject(fn()=>$q->serverRecord($last['attempt_id'],$changed),DevelopmentWorkQueueConflict::class,'original_accepted_result_bytes_are_immutable');
    devServerWrapperChecks($s);
    $firstPayload=json_decode(json_decode($log->sent[0],true,32,JSON_THROW_ON_ERROR)['input'][1]['content'][0]['text'],true,32,JSON_THROW_ON_ERROR);
    devAssert(isset($firstPayload['evidence']['execution']['implementation'],$firstPayload['evidence']['limits']['profile'],$firstPayload['evidence']['legacy'])
        &&!isset($firstPayload['evidence']['parent'])&&!str_contains($log->sent[0],'resp_SYNTHETIC_1'),'actual_policy_schema_legacy_projection_without_future_own_ID');
    $badReport=devServerReport($firstPayload,'producer');$badReport['checks'][0]['refs']=['parent'];
    devReject(fn()=>DevelopmentWorkServerContract::validateReport($badReport,'producer','commissioning',$firstPayload['facts'],null,$firstPayload['evidence']),
        DomainException::class,'foreign_stage_ref_cannot_substitute_for_real_evidence');
    $badReport=devServerReport($firstPayload,'producer');$missing=$firstPayload['evidence'];unset($missing['execution']);
    devReject(fn()=>DevelopmentWorkServerContract::validateReport($badReport,'producer','commissioning',$firstPayload['facts'],null,$missing),
        DomainException::class,'missing_named_evidence_cannot_receive_acceptance');

    foreach(['timeout','wrong_wire','wrong_model','incomplete','usage','spoof','late','oversize','reject_review','duplicate_id'] as $case) {
        [$p,$queue,$time]=devServerFixture();$ordinal=0;
        [$fake,$seen]=devServerSender(static function(array &$raw,array &$report,array &$transport)use($case,$time,&$ordinal):void{
            ++$ordinal;
            if($case==='timeout')$transport['wire_complete']=false;
            if($case==='wrong_wire')$transport['wire_request_bytes'].=' ';
            if($case==='wrong_model')$raw['model']='not-configured';
            if($case==='incomplete')$raw['status']='incomplete';
            if($case==='usage')$raw['usage']['total_tokens']=99999;
            if($case==='spoof')$report['invented_future_acceptance']='fake';
            if($case==='late')$time->at+=3600;
            if($case==='oversize')$raw['untrusted_padding']=str_repeat('x',9000);
            if($case==='reject_review'&&$ordinal===2) {
                $report['approved']=false;$raw['output'][0]['content'][0]['text']=DevelopmentWorkServerContract::json($report);
            }
            if($case==='duplicate_id')$raw['id']='resp_DUPLICATE';
        });
        $queue->serverTick($fake,devServerOrigin($time));
        if(in_array($case,['reject_review','duplicate_id'],true)){$time->at+=60;$queue->serverTick($fake,devServerOrigin($time));}
        $calls=count($seen->sent);$time->at+=86400;
        $r=$queue->serverTick($fake,devServerOrigin($time));
        $row=$p->query('SELECT * FROM development_work_server_controls')->fetch(PDO::FETCH_ASSOC);
        devAssert($r['provider_calls']===0&&$row['state']==='unknown'&&(int)$row['reserved_calls']===$calls
            &&count($seen->sent)===$calls&&(int)$p->query("SELECT COUNT(*) FROM development_work_items WHERE state='accepted'")->fetchColumn()===0,
            'uncertain_or_rejected_'.$case.'_holds_without_retry_or_credit');
        devAssert((int)$p->query("SELECT COUNT(*) FROM development_work_items WHERE task_id='".DevelopmentWorkServerContract::B."'")->fetchColumn()===0,
            'failed_A_never_materializes_B_'.$case);
        if(in_array($case,['oversize','incomplete','reject_review','late'],true)) {
            $held=devServerStatus($queue);devServerWrapperChecks($held);
            $observed=json_decode($p->query('SELECT metadata_bytes FROM development_work_server_attempts ORDER BY cron_at DESC LIMIT 1')->fetchColumn(),true,32,JSON_THROW_ON_ERROR);
            devAssert(is_array($observed['provider'])&&isset($observed['provider']['response_id'],$observed['provider']['status'],$observed['provider']['usage']),
                'known_terminal_metadata_survives_report_rejection_'.$case);
            if($case==='oversize')devAssert($observed['retained_response_is_complete']===false&&$observed['retained_response_bytes']===8192,
                'oversized_unknown_is_explicit_partial_prefix_with_readable_getter');
        }
        if($case==='wrong_wire')devAssert($p->query('SELECT actual_request_bytes FROM development_work_server_attempts')->fetchColumn()===$seen->results[0]['transport']['wire_request_bytes'],
            'mismatched_actual_request_retained_without_acceptance');
    }
    [$p,$queue,$time]=devServerFixture();
    [$fake,$seen]=devServerSender();$p->beforeExecute=static function(string $sql):void {
        if(str_starts_with($sql,'UPDATE development_work_server_attempts SET response_id='))throw new PDOException('synthetic_storage_loss');
    };
    devReject(fn()=>$queue->serverTick($fake,devServerOrigin($time)),PDOException::class,'result_storage_failure_is_not_retried_or_falsely_zero_calls');
    $p->beforeExecute=null;$time->at+=60;
    devAssert($queue->serverTick($fake,devServerOrigin($time))['provider_calls']===0&&count($seen->sent)===1,
        'lost_result_commit_retains_original_unknown_reservation');
    devServerSubscriptionRaceChecks();
    devServerConcurrentTickCheck();
}

function devServerSubscriptionRaceChecks(): void
{
    require_once dirname(__DIR__).'/services/DevelopmentWorkEventService.php';
    [$pdo,$queue,$clock,$args]=devServerFixture(null,false);$calls=0;
    $webhook=new ManagerPreparationWebhook(static function(string $url,array $headers,string $body,array $destination)use($queue,$args,&$calls):array {
        ++$calls;$data=json_decode($body,true,16,JSON_THROW_ON_ERROR);
        $queue->append(1,str_repeat('a',32),$args);
        return ['status'=>200,'body'=>json_encode(['challenge'=>$data['challenge']],JSON_THROW_ON_ERROR)];
    },static fn():array=>['8.8.8.8'],static fn():int=>$clock->at);
    $events=new DevelopmentWorkEventService($pdo,$webhook,static fn():int=>$clock->at,static fn():bool=>true,$queue);
    $params=['name'=>'development_work.pending','arguments'=>['project_key'=>'anytour','queue_id'=>DevelopmentWorkServerContract::QUEUE],
        'delivery'=>['mode'=>'webhook','url'=>'https://callbacks.example/development','secret'=>'whsec_'.base64_encode(str_repeat('n',32))],'ttlMs'=>60000];
    devReject(fn()=>$events->subscribe($params,1,str_repeat('a',32)),DevelopmentWorkQueueConflict::class,
        'server_admission_during_challenge_fences_subscription_persistence');
    devAssert($calls===1&&(int)$pdo->query('SELECT COUNT(*) FROM development_work_events')->fetchColumn()===0
        &&(int)$pdo->query('SELECT COUNT(*) FROM development_work_server_controls')->fetchColumn()===1,
        'challenge_holds_no_lock_and_cannot_activate_second_transport');
    [$pdo,$queue,$clock,$args]=devServerFixture(null,false);
    $writer=static function()use($pdo,$clock):void {
        $s=$pdo->prepare('INSERT INTO development_work_events(subscription_id,single_consumer_key,admin_id,oauth_connection_id,project_key,queue_id,event_name,arguments_json,callback_url,signing_secret,verified_at,expires_at,is_active,attempts,created_at,updated_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,1,0,?,?)');
        $date=gmdate('Y-m-d H:i:s',$clock->at);
        $s->execute(['synthetic','synthetic',1,str_repeat('a',32),'anytour',DevelopmentWorkServerContract::QUEUE,
            'development_work.pending','{}','https://callbacks.example/development','synthetic',$date,gmdate('Y-m-d H:i:s',$clock->at+600),$date,$date]);
    };
    $queue->nativeEventWrite(1,str_repeat('a',32),DevelopmentWorkServerContract::QUEUE,$writer);
    devReject(fn()=>$queue->append(1,str_repeat('a',32),$args),DevelopmentWorkQueueConflict::class,
        'committed_native_subscription_fences_server_admission');
    devAssert((int)$pdo->query('SELECT COUNT(*) FROM development_work_server_controls')->fetchColumn()===0,
        'failed_server_admission_rolls_back_binding_and_A_task');
}

function devServerConcurrentTickCheck(): void
{
    if(!function_exists('proc_open'))throw new RuntimeException('server_race_requires_processes');
    $dir=sys_get_temp_dir().'/dev-server-race-'.bin2hex(random_bytes(6));
    if(!mkdir($dir,0700))throw new RuntimeException('server_race_directory');
    $processes=[];$pdo=null;$queue=null;
    try {
        $file=getenv('DEVELOPMENT_WORK_NATIVE_MYSQL')==='1'?'MYSQL':$dir.'/queue.sqlite';
        $pdo=new DevelopmentWorkTestPdo($file==='MYSQL'?null:$file);[$pdo,$queue]=devServerFixture($pdo);
        foreach([0,1] as $i) {
            $pipes=[];$proc=proc_open([PHP_BINARY,__DIR__.'/run_development_work_queue_regression.php',
                '--development-server-race-child',$file,$dir.'/out'.$i,$dir.'/go'.$i],
                [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
            if(!is_resource($proc))throw new RuntimeException('server_race_process');
            fclose($pipes[0]);$processes[]=[$proc,$pipes];
        }
        $wait=static function(string $path):bool {
            $until=microtime(true)+10;while(!is_file($path)&&microtime(true)<$until)usleep(1000);return is_file($path);
        };
        devAssert($wait($dir.'/out0.ready')&&$wait($dir.'/out1.ready'),'independent_tick_processes_ready');
        file_put_contents($dir.'/go0','go');devAssert($wait($dir.'/out0.sending'),'first_process_released_locks_before_network');
        file_put_contents($dir.'/go1','go');devAssert($wait($dir.'/out1'),'contender_finishes_during_first_network_send');
        $other=json_decode(file_get_contents($dir.'/out1'),true,16,JSON_THROW_ON_ERROR);
        devAssert($other['sends']===0&&$other['result']['state']==='unknown'
            &&(int)$pdo->query('SELECT COUNT(*) FROM development_work_server_attempts')->fetchColumn()===1,
            'real_concurrent_tick_creates_no_second_attempt_or_send');
        file_put_contents($dir.'/out0.release','release');devAssert($wait($dir.'/out0'),'original_send_finishes');
        foreach($processes as [$proc,$pipes]) {
            $stdout=stream_get_contents($pipes[1]);$stderr=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);
            devAssert(proc_close($proc)===0&&$stdout===''&&$stderr==='','concurrent_tick_child_clean');
        }
        devAssert((int)$pdo->query('SELECT reserved_calls FROM development_work_server_controls')->fetchColumn()===1
            &&(int)$pdo->query('SELECT COUNT(*) FROM development_work_server_attempts')->fetchColumn()===1,
            'lane_lock_and_uniqueness_preserve_one_spent_call');
    } finally {
        foreach($processes as [$proc,$pipes]) {
            foreach($pipes as $pipe)if(is_resource($pipe))fclose($pipe);
            if(is_resource($proc)){proc_terminate($proc,9);proc_close($proc);}
        }
        $queue=null;$pdo=null;foreach(glob($dir.'/*')?:[] as $path)unlink($path);rmdir($dir);
    }
}

function devServerWrapperChecks(array $savedStatus): void
{
    require_once dirname(__DIR__).'/services/DevelopmentWorkMcpService.php';
    require_once dirname(__DIR__).'/services/ManagerPreparationMcpService.php';
    $pdo=new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
    $pdo->exec("CREATE TABLE managers(id INTEGER PRIMARY KEY,role TEXT,is_active INTEGER,is_working INTEGER);
        CREATE TABLE projects(id INTEGER PRIMARY KEY,project_key TEXT,display_name TEXT,is_active INTEGER);
        CREATE TABLE manager_projects(manager_id INTEGER,project_id INTEGER);
        CREATE TABLE manager_preparation_oauth_clients(client_id TEXT PRIMARY KEY,redirect_uri TEXT,registration_bucket TEXT,created_at INTEGER);
        CREATE TABLE manager_preparation_oauth_grants(grant_id TEXT PRIMARY KEY,client_id TEXT,admin_id INTEGER,project_key TEXT,resource TEXT,scopes TEXT,created_at INTEGER,expires_at INTEGER,revoked_at INTEGER);
        CREATE TABLE manager_preparation_oauth_codes(code_hash TEXT PRIMARY KEY,grant_id TEXT,redirect_uri TEXT,code_challenge TEXT,expires_at INTEGER,consumed_at INTEGER);
        CREATE TABLE manager_preparation_oauth_tokens(token_hash TEXT PRIMARY KEY,grant_id TEXT,token_kind TEXT,expires_at INTEGER,consumed_at INTEGER);
        INSERT INTO managers VALUES(1,'admin',1,0);INSERT INTO projects VALUES(1,'anytour','Synthetic',1);INSERT INTO manager_projects VALUES(1,1);");
    $prior=(new ReflectionProperty(ConversationDb::class,'pdo'))->getValue();
    $schema=(new ReflectionProperty(ProjectAccessService::class,'schemaReady'))->getValue();
    (new ReflectionProperty(ConversationDb::class,'pdo'))->setValue(null,$pdo);
    (new ReflectionProperty(ProjectAccessService::class,'schemaReady'))->setValue(null,false);
    ProjectConfig::resetForTests(['id'=>'anytour','brand'=>['name'=>'Synthetic project']]);
    $now=time();$clock=static fn():int=>$now;$scope=ManagerPreparationOAuth::DEVELOPMENT_SCOPE;
    try {
        foreach(['legacy','development'] as $profile) {
            $oauth=new ManagerPreparationOAuth($pdo,$clock,$profile==='legacy'?ManagerPreparationOAuth::PROFILE_LEGACY:ManagerPreparationOAuth::PROFILE_DEVELOPMENT);
            $client=$oauth->registerClient(['redirect_uris'=>[ManagerPreparationOAuth::REDIRECT_URI],'scope'=>$scope],'server-wire-'.$profile)['client_id'];
            $verifier=str_repeat('v',43);$session=['csrf'=>'synthetic-wire'];
            $resource=$profile==='legacy'?ManagerPreparationOAuth::RESOURCE:ManagerPreparationOAuth::DEVELOPMENT_RESOURCE;
            $pending=$oauth->beginAuthorization($session,['client_id'=>$client,'redirect_uri'=>ManagerPreparationOAuth::REDIRECT_URI,
                'resource'=>$resource,'response_type'=>'code','scope'=>$scope,'state'=>'synthetic',
                'code_challenge_method'=>'S256','code_challenge'=>rtrim(strtr(base64_encode(hash('sha256',$verifier,true)),'+/','-_'),'=')]);
            $redirect=$oauth->completeAuthorization($session,$pending,1,$session['csrf'],true);
            parse_str((string)parse_url($redirect,PHP_URL_QUERY),$query);
            $tokens=$oauth->exchange(['grant_type'=>'authorization_code','client_id'=>$client,'redirect_uri'=>ManagerPreparationOAuth::REDIRECT_URI,
                'resource'=>$resource,'code'=>$query['code'],'code_verifier'=>$verifier]);
            $context=$oauth->contextForBearer('Bearer '.$tokens['access_token']);$connection=$context['connection_id'];
            $data=$savedStatus;$calls=0;
            $store=static function()use(&$data,&$calls):array{++$calls;return $data;};
            $service=$profile==='development'?new DevelopmentWorkMcpService($pdo,$store,$clock):
                new ManagerPreparationMcpService($pdo,null,null,$clock,null,null,null,null,null,null,$store);
            $rpc=['jsonrpc'=>'2.0','id'=>str_repeat("\x01",1024),'method'=>'tools/call',
                'params'=>['name'=>'development_work_status','arguments'=>['queue_id'=>DevelopmentWorkServerContract::QUEUE]]];
            $reply=$service->handle($rpc,1,$connection,[$scope]);$wire=json_encode($reply,JSON_THROW_ON_ERROR);
            devAssert(!isset($reply['error'])&&strlen($wire)<=262144
                &&($reply['result']['structuredContent']['server_control']??null)===$savedStatus['server_control'],
                'real_'.$profile.'_handle_reads_full_saved_proof_and_escaped_ID');
            $before=$calls;$rpc['id']=str_repeat('x',1025);
            $rejected=$service->handle($rpc,1,$connection,[$scope]);
            devAssert(($rejected['error']['code']??null)===-32600&&$rejected['id']===null&&$calls===$before,
                'real_'.$profile.'_handle_bounds_ID_before_auth_or_store');
            $rpc['id']=str_repeat("é\\\"\x01/",100);
            $data=['ok'=>true,'escaped'=>str_repeat("é\\\"\x01/",30000)];
            $rejected=$service->handle($rpc,1,$connection,[$scope]);
            devAssert(($rejected['error']['code']??null)===-32603&&strlen(json_encode($rejected,JSON_THROW_ON_ERROR))<=262144,
                'real_'.$profile.'_handle_caps_complete_UTF8_text_structured_envelope');
            $data=$savedStatus;$data['escaped_note']="é\\\"\x01/";
            $reply=$service->handle($rpc,1,$connection,[$scope]);
            devAssert(!isset($reply['error'])&&strlen(json_encode($reply,JSON_THROW_ON_ERROR))<=262144,
                'real_'.$profile.'_handle_preserves_bounded_UTF8_original_proof');
        }
    } finally {
        (new ReflectionProperty(ConversationDb::class,'pdo'))->setValue(null,$prior);
        (new ReflectionProperty(ProjectAccessService::class,'schemaReady'))->setValue(null,$schema);
    }
}
