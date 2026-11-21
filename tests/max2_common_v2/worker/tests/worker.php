<?php
declare(strict_types=1);
require __DIR__.'/support.php';
$checks=0;
function workerCheck(bool $ok,string $label):void {global $checks;$checks++;if(!$ok)throw new RuntimeException('ASSERTION_FAILED_'.$label);}
function workerFactory(V2WorkerMemoryPdo $db,Max2CommonValueStateV2 $state,int &$clock,array &$calls,
    ?callable $projection=null,?callable $lookupTransport=null,bool $validGoal=true,int $pageSize=1): Max2CommonValueWorkerV2 {
    $now=static function()use(&$clock):int{return $clock;};
    $lookup=v2TestLookup($lookupTransport??static function(array $wire)use(&$calls,$now,$db):array {
        $calls['lookup']++;
        $q=$db->prepare('SELECT state FROM max2_common_lookup_intents_v2 WHERE request_sha256=?');$q->execute([hash('sha256',$wire['body'])]);
        if ($q->fetchColumn()!=='consumed'||$db->inTransaction()) throw new RuntimeException('LOOKUP_CALLED_BEFORE_COMMITTED_INTENT');
        return v2TestLookupReply($wire,$now());
    },$now);
    $provider=static function()use(&$calls,$db,$validGoal):Max2CommonValueMetrikaClientV2 {
        $calls['factory']++;
        return new Max2CommonValueMetrikaClientV2('synthetic-token-not-a-live-secret',static function(string $method,string $url,?string $csv)use(&$calls,$db,$validGoal):array {
            if (!str_starts_with($url,'https://api-metrika.yandex.net/management/v1/counter/98615635/')) throw new RuntimeException('WRONG_COUNTER');
            if ($method==='GET'&&str_ends_with($url,'/goals')) {
                $calls['goals']++;
                return ['http'=>200,'error'=>0,'body'=>json_encode(['goals'=>[['id'=>687941321,
                    'name'=>$validGoal?'anytour_modeled_value_v2_max2':'wrong','type'=>'action',
                    'conditions'=>[['type'=>'exact','url'=>'anytour_modeled_value_v2_max2']]]]],JSON_THROW_ON_ERROR)];
            }
            if ($method==='POST') {
                $calls['post']++;
                $q=$db->prepare('SELECT attempt_count,delivery_state FROM max2_common_value_events_v2 WHERE csv_sha256=?');$q->execute([hash('sha256',(string)$csv)]);$stored=$q->fetch(PDO::FETCH_ASSOC);
                if (!is_array($stored)||(int)$stored['attempt_count']!==1||$stored['delivery_state']!=='attempted'||$db->inTransaction()) throw new RuntimeException('POST_BEFORE_COMMITTED_INTENT');
                $id=(string)(500+$calls['post']);
                return ['http'=>202,'error'=>0,'body'=>json_encode(['uploading'=>['id'=>$id,'status'=>'UPLOADED','type'=>'BASIC','client_id_type'=>'CLIENT_ID']],JSON_THROW_ON_ERROR)];
            }
            if ($method==='GET'&&preg_match('~/offline_conversions/uploading/([1-9][0-9]*)\z~',$url,$m)) {
                $calls['poll']++;
                return ['http'=>200,'error'=>0,'body'=>json_encode(['uploading'=>['id'=>$m[1],'status'=>'PROCESSED','type'=>'BASIC',
                    'client_id_type'=>'CLIENT_ID','source_quantity'=>1,'line_quantity'=>1,'processed_quantity'=>1]],JSON_THROW_ON_ERROR)];
            }
            throw new RuntimeException('SYNTHETIC_TRANSPORT_SCOPE');
        });
    };
    $project=$projection??static fn(array $row,$gateway,array $activation):array=>v2TestProjection($row['profile_id'],$row['profile_id']==='109'?'150.00':'100.00');
    return new Max2CommonValueWorkerV2($state,static fn(array $activation):Max2CommonValueRepositoryV2=>new Max2CommonValueRepositoryV2($db,$activation,true),
        $lookup,$provider,v2TestPage($db),$project,$now,$pageSize);
}
function workerCalls():array {return ['lookup'=>0,'factory'=>0,'goals'=>0,'post'=>0,'poll'=>0];}

$db=v2TestDb();$clock=1795263660;$state=new Max2CommonValueStateV2($db,static function()use(&$clock):int{return $clock;},true);$calls=workerCalls();
$worker=workerFactory($db,$state,$clock,$calls);
workerCheck($worker->tick()['status']==='OFF'&&array_sum($calls)===0,'off_never_reads_source_or_provider');

$db=v2TestDb();$clock=0;$state=v2TestState($db,$clock);$calls=workerCalls();
$db->exec("INSERT INTO modeled_conversion_profile_versions VALUES(107,'anytour'),(109,'anytour')");
$worker=workerFactory($db,$state,$clock,$calls,null,null,true,3);
$tick=$worker->tick();
workerCheck($tick['status']==='COMPLETED'&&$tick['summary']['profiles_read']===2&&$tick['summary']['reserved']===2,'collect_bounded_source_page_and_positive_deltas');
workerCheck(array_sum($calls)===0&&$state->settings()['cursor']==='109','collect_has_no_provider_calls');
workerCheck((int)$db->query('SELECT SUM(delta_kopeks) FROM max2_common_value_events_v2')->fetchColumn()===15000,'same_inquiry_cumulative_total_once');
workerCheck((int)$db->query('SELECT COUNT(*) FROM max2_common_profile_work_v2')->fetchColumn()===2,'profile_outcomes_durable');
$state->setMode('send',hash('sha256','synthetic send mode'));
$worker=workerFactory($db,$state,$clock,$calls);
$sent=$worker->tick();
workerCheck($sent['status']==='COMPLETED'&&$calls['goals']===1&&$calls['post']===1&&$calls['poll']===0,'send_tick_one_goal_gate_one_post');
workerCheck($sent['summary']['send_attempts']===1&&(int)$db->query("SELECT COUNT(*) FROM max2_common_value_events_v2 WHERE delivery_state='accepted'")->fetchColumn()===1,'real202_outcome_normalized_into_own_accepted_receipt');
$again=$worker->tick();
workerCheck($calls['post']===2&&$calls['poll']===1&&$again['summary']['send_attempts']===1&&$again['summary']['provider_polls']===1,'next_tick_one_poll_one_distinct_send');
$final=$worker->tick();
workerCheck($calls['post']===2&&$calls['poll']===2&&$final['summary']['send_attempts']===0,'known_uploads_polled_without_resending');
workerCheck((int)$db->query("SELECT COUNT(*) FROM max2_common_value_events_v2 WHERE delivery_state='processed'")->fetchColumn()===2,'only_exact_processed_receipts_complete_rows');
workerCheck((int)$db->query('SELECT value_rub FROM max_channel_conversion_outbox')->fetchColumn()===300,'v1_untouched');

$badDb=v2TestDb();$badClock=0;$badState=v2TestState($badDb,$badClock,'send');$badCalls=workerCalls();
$badDb->exec("INSERT INTO modeled_conversion_profile_versions VALUES(107,'anytour')");
$badWorker=workerFactory($badDb,$badState,$badClock,$badCalls,null,null,false);
$badWorker->tick();
workerCheck($badCalls['goals']===1&&$badCalls['post']===0&&(int)$badDb->query('SELECT attempt_count FROM max2_common_value_events_v2')->fetchColumn()===0,'goal_readiness_before_attempt_claim');

$unknownDb=v2TestDb();$unknownClock=0;$unknownState=v2TestState($unknownDb,$unknownClock,'send');$unknownCalls=workerCalls();
$unknownDb->exec("INSERT INTO modeled_conversion_profile_versions VALUES(107,'anytour')");
// Tick lease, reserve, cursor, then send intent: lose only its commit acknowledgement.
$unknownDb->loseCommit=$unknownDb->commits+4;
$unknownWorker=workerFactory($unknownDb,$unknownState,$unknownClock,$unknownCalls);
$unknown=$unknownWorker->tick();
workerCheck($unknown['status']==='UNKNOWN'&&$unknownCalls['goals']===1&&$unknownCalls['post']===0,'intent_commit_unknown_prevents_post');
workerCheck((int)$unknownDb->query('SELECT attempt_count FROM max2_common_value_events_v2')->fetchColumn()===1,'uncertain_committed_intent_retained');
$unknownWorker->tick();
workerCheck($unknownCalls['post']===0,'uncertain_intent_not_returned_to_send_queue');

$lookupDb=v2TestDb();$lookupClock=0;$lookupState=v2TestState($lookupDb,$lookupClock);$lookupCalls=workerCalls();
$lookupDb->exec("INSERT INTO modeled_conversion_profile_versions VALUES(107,'anytour')");
$context=['event_ref'=>hash('sha256','synthetic inquiry'),'frame_sha256'=>hash('sha256','synthetic exact frame'),'cutoff_unix'=>1795263600];
$withLookup=static function(array $row,$gateway,array $activation)use($context):array {
    $receipt=$gateway->lookup('91',$context);
    if ($receipt===null) return ['status'=>'HOLD','reason'=>'LOOKUP_UNAVAILABLE'];
    return v2TestProjection($row['profile_id']);
};
$lookupWorker=workerFactory($lookupDb,$lookupState,$lookupClock,$lookupCalls,$withLookup);
$lookupResult=$lookupWorker->tick();
workerCheck($lookupCalls['lookup']===1&&$lookupResult['summary']['lookup_list']===1,'actual_signed_client_uses_committed_lookup_intent');
workerCheck($lookupDb->query('SELECT state FROM max2_common_lookup_intents_v2')->fetchColumn()==='observed','validated_lookup_result_digest_durable');

$failedDb=v2TestDb();$failedClock=0;$failedState=v2TestState($failedDb,$failedClock,'send');$failedCalls=workerCalls();
$failedDb->exec("INSERT INTO modeled_conversion_profile_versions VALUES(107,'anytour')");
$fallback=static function(array $row,$gateway,array $activation)use($context):array {
    $gateway->lookup('91',$context); return v2TestProjection($row['profile_id']); // deliberately bad adapter fallback
};
$invalidTransport=static function(array $wire)use(&$failedCalls):array {$failedCalls['lookup']++;return ['status_code'=>500,'body'=>'','request_count'=>1,'redirects_followed'=>0,'tls_verified'=>true];};
$failedWorker=workerFactory($failedDb,$failedState,$failedClock,$failedCalls,$fallback,$invalidTransport);
$failed=$failedWorker->tick();
workerCheck($failedCalls['lookup']===1&&$failedCalls['post']===0&&(int)$failedDb->query('SELECT COUNT(*) FROM max2_common_value_accounts_v2')->fetchColumn()===0,'unknown_lookup_cannot_turn_into_full_fallback');
workerCheck($failedDb->query('SELECT state FROM max2_common_lookup_intents_v2')->fetchColumn()==='unknown','unknown_lookup_not_zero_evidence');

$commitDb=v2TestDb();$commitClock=0;$commitState=v2TestState($commitDb,$commitClock,'send');$commitCalls=workerCalls();
$commitDb->exec("INSERT INTO modeled_conversion_profile_versions VALUES(107,'anytour')");
$commitDb->loseCommit=$commitDb->commits+2; // lease succeeds, lookup-intent commit acknowledgement is lost
$commitWorker=workerFactory($commitDb,$commitState,$commitClock,$commitCalls,$fallback);
$commit=$commitWorker->tick();
workerCheck($commit['status']==='UNKNOWN'&&$commitCalls['lookup']===0&&$commitCalls['post']===0,'lookup_commit_unknown_permits_no_external_call');
workerCheck($commitDb->query('SELECT state FROM max2_common_lookup_intents_v2')->fetchColumn()==='consumed','uncertain_lookup_intent_not_cleared');
echo 'PASS ',$checks," synthetic worker assertions with actual V2 classes and fake transports; no native DB/provider calls\n";
