<?php
declare(strict_types=1);
// Only synthetic SQLite memory and fake provider receipts. No native database.
if (PHP_SAPI!=='cli') exit(1);
$package=dirname(__DIR__); $siblings=dirname($package);
$policy=$siblings.'/policy/services/Max2CommonValueAccountingV2.php';
if (!is_file($policy)) $policy=$siblings.'/max2-common-v2-policy-20261121/services/Max2CommonValueAccountingV2.php';
$client=$siblings.'/delivery/services/Max2CommonValueMetrikaClientV2.php';
if (!is_file($client)) $client=$siblings.'/max2-common-v2-delivery-20261121/services/Max2CommonValueMetrikaClientV2.php';
require_once $policy;
require_once $client;
require_once $package.'/services/Max2CommonValueRepositoryV2.php';

$checks=0;
function ledgerCheck(bool $ok,string $label): void {
    global $checks; $checks++; if (!$ok) throw new RuntimeException('ASSERTION_FAILED_'.$label);
}
final class CommitUnknownMemoryPdo extends PDO {
    public bool $loseAfterCommit=false;
    public function commit(): bool {
        $result=parent::commit();
        if ($this->loseAfterCommit) { $this->loseAfterCommit=false; throw new PDOException('synthetic uncertain commit, no real data'); }
        return $result;
    }
}
function ledgerDb(): CommitUnknownMemoryPdo {
    $pdo=new CommitUnknownMemoryPdo('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
    $pdo->exec('PRAGMA foreign_keys=ON');
    $sql=file_get_contents(dirname(__DIR__).'/migrations/112_max2_common_value_v2.sql');
    // Only the dialect differs; use the actual forward migration's constraints.
    $sql=preg_replace('/BIGINT UNSIGNED NOT NULL AUTO_INCREMENT/','INTEGER PRIMARY KEY AUTOINCREMENT',$sql,-1,$auto);
    $sql=str_replace(' CHARACTER SET ascii COLLATE ascii_bin','',$sql);
    $sql=preg_replace('/^\s*PRIMARY KEY \(id\),\n/m','',$sql);
    $sql=preg_replace('/UNIQUE KEY ([a-z0-9_]+) \(/','CONSTRAINT $1 UNIQUE (',$sql);
    $sql=preg_replace('/^\s*KEY ix_m2_common_delivery \(delivery_state,id\),\n/m','',$sql);
    $sql=preg_replace('/\) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;/',' );',$sql);
    if ($auto!==2||!is_string($sql)) throw new RuntimeException('SYNTHETIC_SCHEMA_TRANSLATION_FAILED');
    $pdo->exec($sql);
    $pdo->exec('CREATE TABLE max_channel_conversion_outbox (id INTEGER PRIMARY KEY,value_rub INTEGER,target TEXT)');
    $pdo->exec("INSERT INTO max_channel_conversion_outbox VALUES (1,300,'anytour_modeled_value_v1')");
    return $pdo;
}
function ledgerActivation(string $mode='collect'): array {
    return ['mode'=>$mode,'version'=>1,'counter_id'=>98615635,'goal_id'=>687941321,
        'target'=>Max2ValueBindingPolicyV2::TARGET,'producer_contract_sha256'=>Max2ValueBindingPolicyV2::PRODUCER_CONTRACT_SHA256,
        'activation_sha256'=>hash('sha256','synthetic activation only, not a live release'), 'activated_at_unix'=>1795263000];
}
function ledgerCase(bool $linked=true,string $conversation='29',string $tracker='71',string $journal='103'): array {
    $s=['project_key'=>'anytour','platform'=>'max','customer_key'=>'17','conversation_id'=>$conversation];
    $q=['subject'=>$s,'journal_id'=>$journal,'native_message_id'=>'97','kind'=>'substantive_interest',
        'source_version'=>1,'verified_customer_message'=>true,'recorded_at_unix'=>1795263600];
    $key=Max2ValueBindingPolicyV2::inquiryKey($s,$journal);
    $actor=['authenticated_actor'=>true,'subject'=>$s,'external_user_id'=>'91','external_chat_id'=>'-91',
        'customer_channel_id'=>'25','subject_actor_binding_sha256'=>str_repeat('e',64)];
    $proof=['version'=>2,'counter_id'=>98615635,'subject'=>$s,'inquiry_key'=>$key,
        'source_receipt_sha256'=>str_repeat('a',64),'complete'=>true,'cutoff_unix'=>1795263600,
        'observed_at_unix'=>1795263650,'blank_row_count'=>4,'own_reserve_only'=>true,
        'producer_contract_sha256'=>Max2ValueBindingPolicyV2::PRODUCER_CONTRACT_SHA256,
        'status'=>$linked?'complete':'complete_empty','coverage'=>'all_exact_actor_own_early_reserves_at_observation',
        'possible_max2_unresolved'=>false,'candidates'=>[],'actor_binding'=>$actor];
    if ($linked) {
        $proof['entry']=$actor+['exact_inquiry_context'=>true,'entry_id'=>'41','entry_key'=>str_repeat('b',64),
            'publication_id'=>'53','version_id'=>'59','pair_mapping_sha256'=>str_repeat('c',64),'offer_id'=>'max_61'];
        $proof['candidates'][]=['tracker_record_id'=>$tracker,'immutable_tuple_sha256'=>hash('sha256','synthetic tuple '.$tracker),
            'counter_id'=>98615635,'metric_project_id'=>'1538','external_user_id'=>'91','post_id'=>'53','offer_id'=>'max_61',
            'client_id'=>'1234567890123456789','early_kopeks'=>'2636','common_kopeks'=>'2636',
            'model_version'=>Max2ValueBindingPolicyV2::MAX2_MODEL,'issuance_state'=>'issued_unconfirmed',
            'recorded_at_unix'=>1795263540,'binding_state'=>'unbound'];
    }
    $binding=Max2ValueBindingPolicyV2::resolve($s,$q,$proof);
    if (($binding['status']??null)==='HOLD') throw new RuntimeException('SYNTHETIC_BINDING_INVALID');
    $estimate=['admitted_saved_profile'=>true,'subject'=>$s,'inquiry_journal_id'=>$journal,'profile_id'=>'107',
        'profile_sha256'=>hash('sha256','synthetic profile 107'),'model_version'=>'synthetic_saved_model_v1',
        'currency'=>'RUB','value_rub'=>'100.00','input_cutoff_unix'=>1795263650,'event_unix'=>1795263655,
        'profile_observed_at_unix'=>1795263660];
    $identity=['admitted'=>true,'subject'=>$s,'inquiry_key'=>$key,'client_id'=>'1234567890123456789',
        'source_sha256'=>hash('sha256','synthetic identity'),'basis'=>$linked?'bound_max2_receipt':'saved_v1_identity_read_only'];
    $recheck=$linked?['status'=>'match','action'=>'recheck','coverage'=>'exact_selected_record_only',
        'tracker_record_id'=>$tracker,'immutable_tuple_sha256'=>$binding['immutable_tuple_sha256'],
        'source_sha256'=>hash('sha256','synthetic recheck'),'checked_at_unix'=>1795263655]:[];
    return [$binding,$estimate,$identity,$recheck,1795263660];
}
function ledgerNext(array $args,$total='150.00',string $profile='109'): array {
    $args[1]['value_rub']=$total; $args[1]['profile_id']=$profile;
    $args[1]['profile_sha256']=hash('sha256','synthetic profile '.$profile); return $args;
}
function ledgerCount(PDO $pdo,string $table): int {
    if (!in_array($table,['max2_common_value_accounts_v2','max2_common_value_events_v2'],true)) throw new LogicException('TEST_TABLE');
    return (int)$pdo->query('SELECT COUNT(*) FROM '.$table)->fetchColumn();
}

$db=ledgerDb(); $args=ledgerCase();
$off=new Max2CommonValueRepositoryV2($db,['mode'=>'off'],true);
ledgerCheck($off->reserve(...$args)['reason']==='V2_COLLECTION_OFF'&&ledgerCount($db,'max2_common_value_accounts_v2')===0,'default_off_no_reservation');
$repo=new Max2CommonValueRepositoryV2($db,ledgerActivation(),true);
$first=$repo->reserve(...$args);
ledgerCheck($first['status']==='RESERVED'&&$first['delta_kopeks']===7364&&!$first['delivery_permitted'],'linked_reserve_committed_without_delivery');
$second=new Max2CommonValueRepositoryV2($db,ledgerActivation(),true);
ledgerCheck($second->reserve(...$args)['status']==='ALREADY_RECORDED'&&ledgerCount($db,'max2_common_value_events_v2')===1,'two_workers_observe_single_unique_profile_reservation');
$next=$repo->reserve(...ledgerNext($args));
ledgerCheck($next['delta_kopeks']===5000&&$next['status']==='RESERVED','next_saved_total_only_positive_delta');
ledgerCheck($repo->receiptClaim('71',$args[0]['inquiry_key'])['binding_state']==='same_inquiry','same_receipt_claim_observed');
ledgerCheck($repo->receiptClaim('71',str_repeat('f',64))['binding_state']==='other_inquiry','other_receipt_claim_observed');
ledgerCheck($repo->receiptClaim('99',$args[0]['inquiry_key'])['binding_state']==='unbound','unbound_claim_is_exact_read');
ledgerCheck($repo->readback($args[0]['inquiry_key'],$first['event_key'])['record']['delta_kopeks']===7364,'exact_reserved_readback');
ledgerCheck($repo->readback($args[0]['inquiry_key'],str_repeat('0',64))['absence_is_not_retry_permission']===true,'missing_readback_never_retry_permission');

$bad=ledgerNext($args,'101.00','107');
ledgerCheck($repo->reserve(...$bad)['reason']==='IMMUTABLE_PROFILE_CONFLICT','same_profile_changed_total_rejected');
$bad=$args; $bad[1]['event_unix']++;
ledgerCheck($repo->reserve(...$bad)['reason']==='IMMUTABLE_EVENT_CLOCK_CONFLICT','same_profile_changed_clock_rejected');
$bad=$args; $bad[3]=[];
ledgerCheck($repo->reserve(...$bad)['reason']==='EXACT_RECEIPT_RECHECK_UNPROVED','missing_point_recheck_no_write');
$bad=$args; $bad[0]['goal_id']=678187097;
ledgerCheck($repo->reserve(...$bad)['status']==='HOLD','old_goal_not_admitted');
$bad=$args; $bad[0]['inquiry_recorded_at_unix']=1795262000;
ledgerCheck($repo->reserve(...$bad)['status']==='HOLD','prospective_floor_no_backfill');
foreach ([['input_cutoff_unix',1795263599],['input_cutoff_unix',1795263656],['event_unix',1795263661],['profile_observed_at_unix',1795263654],['profile_observed_at_unix',1795263661]] as [$clock,$invalid]) {
    $bad=ledgerNext($args,'150.00','119'); $bad[1][$clock]=$invalid;
    ledgerCheck($repo->reserve(...$bad)['reason']==='PROSPECTIVE_SOURCE_UNPROVED','saved_profile_clock_order_or_future_rejected');
}
$bad=ledgerCase(true,'30','71','105'); $bad=ledgerNext($bad,'100.00','111');
ledgerCheck($repo->reserve(...$bad)['status']==='HOLD'&&ledgerCount($db,'max2_common_value_accounts_v2')===1,'unique_receipt_rejects_second_inquiry');
$bad=ledgerCase(true,'29','72','103');
ledgerCheck($repo->reserve(...$bad)['reason']==='IMMUTABLE_INQUIRY_ACCOUNT_CONFLICT','new_receipt_cannot_rebind_claimed_inquiry');
$bad=ledgerCase(true,'29','71','106');
ledgerCheck($repo->reserve(...$bad)['reason']==='IMMUTABLE_INQUIRY_ACCOUNT_CONFLICT','one_accounted_observed_inquiry_per_conversation');

$fullDb=ledgerDb(); $fullRepo=new Max2CommonValueRepositoryV2($fullDb,ledgerActivation(),true);
$fullArgs=ledgerCase(false); $full=$fullRepo->reserve(...$fullArgs);
ledgerCheck($full['delta_kopeks']===10000,'complete_blank_rows_full_valued_signal');
ledgerCheck($fullRepo->reserve(...ledgerCase(true))['reason']==='IMMUTABLE_INQUIRY_ACCOUNT_CONFLICT','full_first_cannot_rebind_to_later_max2');
ledgerCheck($repo->reserve(...ledgerCase(false))['reason']==='IMMUTABLE_INQUIRY_ACCOUNT_CONFLICT','linked_first_cannot_become_full_fallback');
$zeroArgs=ledgerNext($args,'20.00','113');
ledgerCheck($repo->reserve(...$zeroArgs)['status']==='EVALUATED','decline_zero_evaluation_persisted');
ledgerCheck($repo->reserve(...$zeroArgs)['status']==='ALREADY_RECORDED','zero_same_profile_idempotent');
ledgerCheck($repo->reserve(...ledgerNext($args,'160.00','113'))['reason']==='IMMUTABLE_PROFILE_CONFLICT','zero_profile_immutable');

$failureDb=ledgerDb(); $failureRepo=new Max2CommonValueRepositoryV2($failureDb,ledgerActivation(),true);
$failureDb->exec("CREATE TRIGGER synthetic_fail_event BEFORE INSERT ON max2_common_value_events_v2 BEGIN SELECT RAISE(ABORT,'synthetic event failure'); END");
ledgerCheck($failureRepo->reserve(...ledgerCase())['status']==='HOLD'&&ledgerCount($failureDb,'max2_common_value_accounts_v2')===0
    &&ledgerCount($failureDb,'max2_common_value_events_v2')===0,'event_failure_rolls_back_new_account');
$unknownDb=ledgerDb(); $unknownRepo=new Max2CommonValueRepositoryV2($unknownDb,ledgerActivation(),true);
$unknownDb->loseAfterCommit=true; $unknown=$unknownRepo->reserve(...ledgerCase());
ledgerCheck($unknown['status']==='UNKNOWN'&&$unknown['automatic_retry']===false&&!$unknown['delivery_permitted'],'commit_ack_unknown_not_permission');
ledgerCheck($unknownRepo->readback($args[0]['inquiry_key'],$unknown['event_key'])['status']==='OBSERVED','unknown_commit_can_be_reconciled_read_only');

$send=new Max2CommonValueRepositoryV2($db,ledgerActivation('send'),true);
ledgerCheck(count($send->pendingReservations(1795263661)['events'])===2,'pending_only_positive_unattempted');
ledgerCheck($repo->beginAttempt($first['event_key'],1795263661)['status']==='HOLD','collect_mode_cannot_claim_send');
$intent=$send->beginAttempt($first['event_key'],1795263661);
ledgerCheck($intent['status']==='ATTEMPT_CLAIMED'&&$intent['delivery_permitted']&&$intent['event']['attempt_count']===1,'one_durable_intent_before_sendable_event');
$wire=$intent['event'];
ledgerCheck(hash('sha256',Max2CommonValueMetrikaClientV2::csv($wire,1795263661))===$wire['csv_sha256'],'persisted_intent_matches_actual_new_client_csv');
$competing=new Max2CommonValueRepositoryV2($db,ledgerActivation('send'),true);
$again=$competing->beginAttempt($first['event_key'],1795263662);
ledgerCheck($again['status']==='ALREADY_ATTEMPTED'&&!isset($again['event'])&&!$again['delivery_permitted'],'competing_sender_gets_no_sendable_row');
$result=['state'=>'unknown','csv_sha256'=>$wire['csv_sha256'],'response_sha256'=>hash('sha256','synthetic unknown'),
    'upload_id'=>null,'http_status'=>0,'code'=>'transport_unknown'];
ledgerCheck($send->recordAttemptResult($wire['event_key'],$wire['attempt_key'],$result,1795263663)['delivery_state']==='unknown','unknown_transport_preserves_intent');
ledgerCheck($send->beginAttempt($wire['event_key'],1795263664)['status']==='ALREADY_ATTEMPTED','unknown_never_reissued');
ledgerCheck(count($send->pendingReservations(1795263664)['events'])===1,'unknown_not_pending_send');
$raised=$send->reserve(...ledgerNext($args,'160.00','115'));
ledgerCheck($raised['delta_kopeks']===1000,'unknown_attempt_still_in_reserved_sum');
$accepted=['state'=>'accepted','csv_sha256'=>$wire['csv_sha256'],'response_sha256'=>hash('sha256','synthetic accepted'),
    'upload_id'=>'555','http_status'=>200,'code'=>'accepted_upload'];
ledgerCheck($send->recordAttemptResult($wire['event_key'],$wire['attempt_key'],$accepted,1795263665)['delivery_state']==='accepted','same_attempt_acceptance_reconciliation');
ledgerCheck(count($send->pendingProviderReceipts(1795263665)['receipts'])===1,'only_known_accepted_receipt_can_be_polled');
$badAccepted=$accepted; $badAccepted['upload_id']='556';
ledgerCheck($send->recordAttemptResult($wire['event_key'],$wire['attempt_key'],$badAccepted,1795263666)['status']==='HOLD','accepted_upload_id_immutable');
$processed=['upload_id'=>'555','status'=>'PROCESSED','source_quantity'=>1,'line_quantity'=>1,'response_sha256'=>hash('sha256','synthetic processed')];
$badProcessed=$processed; $badProcessed['line_quantity']=0;
ledgerCheck($send->recordProviderStatus($wire['event_key'],$wire['attempt_key'],$badProcessed,1795263667)['status']==='HOLD','processed_requires_exact_one_line');
$badProcessed=$processed; $badProcessed['upload_id']='556';
ledgerCheck($send->recordProviderStatus($wire['event_key'],$wire['attempt_key'],$badProcessed,1795263667)['status']==='HOLD','processed_requires_exact_upload');
ledgerCheck($send->recordProviderStatus($wire['event_key'],$wire['attempt_key'],$processed,1795263667)['delivery_state']==='processed','separate_exact_provider_processing_receipt');
ledgerCheck($send->pendingProviderReceipts(1795263667)['receipts']===[],'processed_receipt_leaves_pending_poll');
ledgerCheck($send->recordProviderStatus($wire['event_key'],$wire['attempt_key'],$processed,1795263668)['status']==='ALREADY_RECORDED','processed_clock_not_rewritten');
$processRow=$db->query("SELECT processed_at_unix FROM max2_common_value_events_v2 WHERE upload_id='555'")->fetchColumn();
ledgerCheck((int)$processRow===1795263667,'original_processed_clock_frozen');

$intentUnknownDb=ledgerDb(); $intentUnknownRepo=new Max2CommonValueRepositoryV2($intentUnknownDb,ledgerActivation('send'),true);
$reserve=$intentUnknownRepo->reserve(...ledgerCase()); $intentUnknownDb->loseAfterCommit=true;
$uncertain=$intentUnknownRepo->beginAttempt($reserve['event_key'],1795263661);
ledgerCheck($uncertain['status']==='UNKNOWN'&&!isset($uncertain['event'])&&!$uncertain['delivery_permitted'],'unknown_intent_commit_never_returns_sendable_row');
ledgerCheck($intentUnknownRepo->beginAttempt($reserve['event_key'],1795263662)['status']==='ALREADY_ATTEMPTED','committed_uncertain_intent_not_reissued');
$old=$db->query('SELECT value_rub,target FROM max_channel_conversion_outbox WHERE id=1')->fetch(PDO::FETCH_ASSOC);
ledgerCheck((int)$old['value_rub']===300&&$old['target']==='anytour_modeled_value_v1','v1_sentinel_unchanged');

echo 'PASS ',$checks," synthetic memory-ledger assertions; no native DB/provider calls\n";
echo "MySQL engine/row-lock concurrency and real activation remain native preflight gates; SQLite is not their evidence.\n";
