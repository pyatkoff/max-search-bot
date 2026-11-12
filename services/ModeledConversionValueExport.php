<?php
declare(strict_types=1);
require_once __DIR__.'/ModeledConversionValueContract.php';
require_once __DIR__.'/MaxChannelConversionOutbox.php';
require_once __DIR__.'/MaxTrackerLookupClient.php';
require_once __DIR__.'/MetrikaOfflineClient.php';
require_once __DIR__.'/InternalOwnerDialogueScope.php';
require_once __DIR__.'/ModeledConversionPriceContinuity.php';
require_once __DIR__.'/ModeledConversionTickFailure.php';
require_once __DIR__.'/ModeledConversionAttributionWindow.php';
require_once __DIR__.'/ModeledConversionValueIdentityEvidence.php';
require_once __DIR__.'/ModeledConversionProfiles.php';
require_once __DIR__.'/ModeledConversionValueVisitEvidence.php';
require_once __DIR__.'/ModeledConversionValueVisitRecovery.php';
require_once __DIR__.'/ModeledConversionMaxCounterScopeEvidence.php';
require_once __DIR__.'/ModeledConversionFixedPairDecisionEvidence.php';
require_once __DIR__.'/ModeledConversionReceiptPollEvidence.php';
require_once __DIR__.'/ModeledConversionRetainedAppIdentityEvidence.php';
require_once __DIR__.'/ModeledConversionRetainedTrackerIdentityEvidence.php';

/** One bounded addition to the existing cron process and durable conversion outbox. */
final class ModeledConversionValueExport
{
    private ?string $identityNamespace=null;
    private array $identityRefs=[];
    private array $identityObservations=[];
    private bool $identityCohortAvailable=false;
    private bool $identityComplete=false;
    private ?array $finiteFrame=null;
    private array $finiteRows=[];
    private array $finiteIdentities=[];
    private bool $finiteLookupReturned=false;
    private bool $finiteLookupAttempted=false;
    private int $finiteChecksUsed=0;
    private bool $optionalVisitStopped=false;
    private ?array $recoveryJournal=null;
    private ?array $recoveryOriginal=null;
    private ?array $fixedPairJournal=null;
    private ?string $fixedPairDigest=null;
    private array $fixedPairCases=[];
    private array $ordinaryLookupUsers=[];
    private bool $retainedTrackerConsumed=false;
    private bool $retainedTrackerSourceUnsupported=false;
    private ?array $retainedTrackerReservation=null;
    private array $retainedKnownCandidates=[];
    private ?array $retainedKnownReservation=null;
    private bool $retainedAppAttempted=false;
    private bool $counterScopeConsumed=false;
    private ?array $counterScopeLedger=null;
    private ?array $counterScopeIntent=null;
    private array $fixedPairDecisions=[];
    private ?array $receiptPollEvidence=null;
    private bool $retainedAppConsumed=false;
    public function __construct(private PDO $pdo){}
    public static function settings(?PDO $pdo=null):?array
    {
        try{
            $p=$pdo??ConversationDb::connection();
            $s=$p->query("SELECT * FROM modeled_conversion_value_export_settings WHERE project_key='anytour'")->fetch(PDO::FETCH_ASSOC);
            return is_array($s)&&$s['counter_id']==='98615635'&&in_array($s['mode'],['off','send'],true)?$s:null;
        }catch(Throwable $ignored){return null;}
    }

    /** Finite owner-authorized control; create ambiguity is durably held, never retried blindly. */
    public function configure(string $op,mixed $api,int $now):array
    {
        if(!in_array($op,['status','create-goal','enable','disable','launch-v1','launch-history-20261102','launch-history-fortnight-v1'],true))throw new RuntimeException('invalid_operation');
        $p=$this->pdo;$s=self::settings($p);if(!$s)throw new RuntimeException('value_migration_unavailable');
        if($op==='launch-history-fortnight-v1'){
            $goal=$api->modeledValueGoalStatus();
            if($s['mode']!=='send'||($goal['id']??null)!=='678187097'||$s['goal_id']!=='678187097'
                ||$s['goal_intent']!=='verified'||$now<1793656800)throw new RuntimeException('value_goal_not_verified');
            $p->beginTransaction();
            try{
                $mysql=$p->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql';
                $s=$p->query("SELECT * FROM modeled_conversion_value_export_settings WHERE project_key='anytour'".($mysql?' FOR UPDATE':''))->fetch(PDO::FETCH_ASSOC);
                if(!$s||$s['mode']!=='send'||$s['goal_id']!=='678187097'||!ModeledConversionValueContract::historyWindow((int)($s['history_from_unix']??0),(int)($s['history_until_unix']??0)))
                    throw new RuntimeException('value_configuration_changed');
                // A durable request does not interrupt the first day or reset any cursor.
                $q=$p->prepare("UPDATE modeled_conversion_value_export_settings SET history_fortnight_requested_at_unix=COALESCE(history_fortnight_requested_at_unix,?) WHERE project_key='anytour'");
                $q->execute([$now]);$p->commit();
            }catch(Throwable $error){if($p->inTransaction())$p->rollBack();throw $error;}
            return ['ok'=>true,'operation'=>$op,'goal_id'=>'678187097','history_fortnight_requested'=>true,'contract'=>ModeledConversionValueContract::VERSION];
        }
        if($op==='launch-history-20261102'){
            $goal=$api->modeledValueGoalStatus();
            if($s['mode']!=='send'||($goal['id']??null)!=='678187097'||$s['goal_id']!=='678187097'
                ||$s['goal_intent']!=='verified'||$now<1793656800)throw new RuntimeException('value_goal_not_verified');
            $p->beginTransaction();
            try{
                $mysql=$p->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql';
                $s=$p->query("SELECT * FROM modeled_conversion_value_export_settings WHERE project_key='anytour'".($mysql?' FOR UPDATE':''))->fetch(PDO::FETCH_ASSOC);
                if(!$s||$s['mode']!=='send'||$s['goal_id']!=='678187097')throw new RuntimeException('value_configuration_changed');
                if($s['history_from_unix']===null){
                    $ceiling=(string)$p->query("SELECT COALESCE(MAX(id),0) FROM modeled_conversion_profile_versions WHERE project_key='anytour'")->fetchColumn();
                    $q=$p->prepare("UPDATE modeled_conversion_value_export_settings SET history_from_unix=1793570400,history_until_unix=1793656800,history_cursor=0,history_ceiling=? WHERE project_key='anytour' AND history_from_unix IS NULL");
                    $q->execute([$ceiling]);
                }elseif((int)$s['history_from_unix']!==1793570400||(int)$s['history_until_unix']!==1793656800)throw new RuntimeException('value_configuration_changed');
                $p->commit();
            }catch(Throwable $error){if($p->inTransaction())$p->rollBack();throw $error;}
            return ['ok'=>true,'operation'=>$op,'goal_id'=>'678187097','history_configured'=>true,'contract'=>ModeledConversionValueContract::VERSION];
        }
        if($op==='launch-v1'){
            if($s['activated_at_unix']!==null)return ['ok'=>true,'operation'=>'launch-v1','already_launched'=>true,
                'mode'=>$s['mode'],'goal_id'=>$s['goal_id'],'contract'=>ModeledConversionValueContract::VERSION];
            $this->configure('create-goal',$api,$now);
            $result=$this->configure('enable',$api,$now);$result['operation']='launch-v1';return $result;
        }
        if($op==='disable'){$p->exec("UPDATE modeled_conversion_value_export_settings SET mode='off' WHERE project_key='anytour'");$goal=null;}
        else $goal=$api->modeledValueGoalStatus();
        if($op==='create-goal'){
            if($s['mode']!=='off')throw new RuntimeException('value_off_required');
            if(!$goal){
                if($s['goal_intent']!=='none')throw new RuntimeException('value_goal_reconciliation_required');
                $q=$p->prepare("UPDATE modeled_conversion_value_export_settings SET goal_intent='creating' WHERE project_key='anytour' AND mode='off' AND goal_intent='none'");
                $q->execute();if($q->rowCount()!==1)throw new RuntimeException('value_configuration_changed');
                try{$goal=$api->createModeledValueGoal();}
                catch(Throwable $e){$p->exec("UPDATE modeled_conversion_value_export_settings SET goal_intent='unknown' WHERE project_key='anytour' AND goal_intent='creating'");throw $e;}
            }
            $q=$p->prepare("UPDATE modeled_conversion_value_export_settings SET goal_id=?,goal_intent='verified' WHERE project_key='anytour' AND mode='off'");$q->execute([$goal['id']]);
        }elseif($op==='enable'){
            if(!$goal||$s['goal_id']!==$goal['id']||$s['goal_intent']!=='verified'
                ||(int)$s['base_value_rub']!==25000||$s['contract_version']!==ModeledConversionValueContract::VERSION)throw new RuntimeException('value_goal_not_verified');
            $p->beginTransaction();
            try{
                $mysql=$p->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql';
                $s=$p->query("SELECT * FROM modeled_conversion_value_export_settings WHERE project_key='anytour'".($mysql?' FOR UPDATE':''))->fetch(PDO::FETCH_ASSOC);
                if(!$s||$s['goal_id']!==$goal['id']||$s['goal_intent']!=='verified'||(int)$s['base_value_rub']!==25000
                    ||$s['contract_version']!==ModeledConversionValueContract::VERSION)throw new RuntimeException('value_configuration_changed');
                if($s['activated_at_unix']===null){
                    $floor=(string)$p->query("SELECT COALESCE(MAX(id),0) FROM modeled_conversion_profile_versions WHERE project_key='anytour'")->fetchColumn();
                    $q=$p->prepare("UPDATE modeled_conversion_value_export_settings SET mode='send',activated_at_unix=?,profile_cursor=? WHERE project_key='anytour' AND activated_at_unix IS NULL");$q->execute([$now,$floor]);
                }else $p->exec("UPDATE modeled_conversion_value_export_settings SET mode='send' WHERE project_key='anytour'");
                $p->commit();
            }catch(Throwable $e){if($p->inTransaction())$p->rollBack();throw $e;}
        }elseif($op==='disable')$p->exec("UPDATE modeled_conversion_value_export_settings SET mode='off' WHERE project_key='anytour'");
        $s=self::settings($p);
        $q=$p->prepare("SELECT state,upload_status FROM max_channel_conversion_outbox WHERE project_key='anytour' AND counter_id='98615635' AND transport_target=? AND value_contract=? ORDER BY id DESC LIMIT 1");
        $q->execute([ModeledConversionValueContract::TARGET,ModeledConversionValueContract::VERSION]);$last=$q->fetch(PDO::FETCH_ASSOC);
        return ['ok'=>true,'operation'=>$op,'counter_id'=>'98615635','target'=>ModeledConversionValueContract::TARGET,
            'goal_id'=>$s['goal_id'],'goal_verified'=>$goal!==null&&$goal['id']===$s['goal_id'],'goal_intent'=>$s['goal_intent'],
            'mode'=>$s['mode'],'contract'=>ModeledConversionValueContract::VERSION,'delivery'=>$last['state']??'waiting_for_signal','upload_status'=>$last['upload_status']??null];
    }

    public static function tick(?PDO $pdo=null,mixed $lookup=null,mixed $api=null,?int $now=null,?callable $gate=null,?callable $observationClock=null,?callable $localClock=null):array
    {
        if($pdo===null&&ProjectConfig::projectId()!=='anytour')return ['status'=>'off'];
        $s=self::settings($pdo);if(!$s||$s['mode']!=='send')return ['status'=>'off'];
        $p=$pdo??ConversationDb::connection();$injectedClock=$now!==null;$now??=time();$self=new self($p);
        $clock=$observationClock??($injectedClock?static fn():int=>$now:static fn():int=>time());
        if(!$gate)$gate=static function(string $chat):bool{
            require_once dirname(__DIR__).'/maxsearchclass.php';
            return MaxSearchApi::metrikaGoalAllowed($chat);
        };
        $stats=['collected'=>0,'resolved'=>0,'accepted'=>0,'processed'=>0,'unknown'=>0,'errors'=>0];
        $stats['visit_gate_decisions']=['v'=>2,'checked_at_unix'=>$now,'attempts'=>0,'reason_counts'=>[
            'within_current_window'=>0,'outside_current_window'=>0,'no_prior_visit'=>0,'visit_report_unavailable'=>0,'unknown'=>0],'failures'=>[]];
        $failure=null;$phase='recover';
        try{
            $repo=new MaxChannelConversionOutbox($p);$repo->recover($now);
            $phase='price_continuity';
            $priceGuard=new ModeledConversionPriceContinuity($p);
            $stats['price_continuity_audit']=$priceGuard->reconcile($now,fn(array $r):bool=>$self->eligible($r,$gate));
            // Drain only local saved-profile pages. The original network phases run once below.
            $phase='collect_profiles';
            $profileFence=(string)$p->query("SELECT COALESCE(MAX(id),0) FROM modeled_conversion_profile_versions WHERE project_key='anytour'")->fetchColumn();
            $localClock??=static fn():float=>hrtime(true)/1e9;
            $localStarted=$localClock();
            for($batch=0;$batch<4;$batch++){
                // Soft admission deadline: never interrupt an in-flight transaction.
                if($batch>0&&$localClock()-$localStarted>=5.0)break;
                $pendingSettings=self::settings($p);
                if(!$pendingSettings||$pendingSettings['mode']!=='send'||(string)$pendingSettings['profile_cursor']===$profileFence)break;
                $before=(string)$pendingSettings['profile_cursor'];
                // Preserve committed-page counts if a later page fails and the outer catch stops delivery.
                $stats['collected']+=$self->collect($pendingSettings,$now,$gate,$profileFence);
                $after=self::settings($p);
                if(!$after||$after['mode']!=='send'||(string)$after['profile_cursor']===$before)break;
            }
            $phase='collect_history';
            $stats['historical_collected']=$self->collectHistory($now,$gate);
            $phase='resolve_identity';
            // Optional scalar metadata cannot block the existing financial lane.
            $self->prepareIdentityEvidence();
            $finite=$self->prepareFiniteEvidence($now,$gate);
            $recovery=$self->prepareRecoveryEvidence($now);
            $pair=$self->prepareFixedPair($now);
            $reserved=[];foreach(array_merge($finite,$recovery,$pair)as$row)$reserved[(string)$row['id']]=$row;
            $pending=$self->rows('pending',$now,MaxTrackerLookupProtocol::MAX_USERS-count($reserved),array_keys($reserved));
            foreach($reserved as$row)if($row['state']==='pending'
                &&(is_int($row['next_attempt_unix'])||is_string($row['next_attempt_unix']))
                &&preg_match('/\A(?:0|[1-9][0-9]*)\z/D',(string)$row['next_attempt_unix'])===1
                &&(int)$row['next_attempt_unix']<=$now)$pending[]=$row;
            $users=[];foreach($pending as$r)if($r['platform']==='max'&&$self->eligible($r,$gate))$users[]=$r['external_user_id'];
            foreach(array_merge($finite,$recovery,$pair)as$r)if($r['platform']==='max'&&$self->finiteEligible($r,$gate))$users[]=$r['external_user_id'];
            if($users){$lookup??=new MaxTrackerLookupClient();$users=$self->reserveRetainedTracker($lookup,$users,array_merge($pending,$finite,$recovery,$pair),$now);
                $users=$self->reserveKnownTracker($lookup,$users,array_merge($pending,$finite,$recovery,$pair),$now);}
            $identities=[];$lookupReturned=false;
            if($users){$lookup??=new MaxTrackerLookupClient();$self->finiteLookupAttempted=(bool)($finite||$recovery);
                $batch=array_values(array_unique($users));$self->ordinaryLookupUsers=$batch;$missing=$self->counterScopeCandidate($batch,$now);
                if($missing!==null&&method_exists($lookup,'lookupWithCounterScopeEvidence')){
                    $response=$lookup->lookupWithCounterScopeEvidence('98615635',$batch,$missing['external_user_id'],
                        fn(string $hash):bool=>$self->beginCounterScope($missing,$hash,$gate,self::observedAt($clock,$now)));
                    if(!is_array($response['identities']??null))throw new RuntimeException('tracker_response_invalid');
                    $identities=$response['identities'];
                    if($self->counterScopeIntent!==null){
                        try{$self->finishCounterScope($response['counter_scope_evidence']??null,self::observedAt($clock,$now));}
                        catch(Throwable $ignored){ /* A missing optional observation clock keeps the committed intent UNKNOWN. */ }
                    }
                }else $identities=$lookup->lookup('98615635',$batch);
                $lookupReturned=true;$self->finiteIdentities=$identities;$self->finiteLookupReturned=true;}
            foreach($pending as$r){
                if($self->retainedTrackerReservation!==null&&(string)$r['id']===(string)$self->retainedTrackerReservation['id'])continue;
                if($self->resolve($r,$identities,$now,$gate,$lookupReturned))$stats['resolved']++;
            }
            $ready=$self->rows('ready',$now,3,$self->retainedKnownReservation===null?[]:[(string)$self->retainedKnownReservation['row']['id']]);
            $receipts=array_merge($self->rows('accepted',$now,2),$self->rows('unknown',$now,2));
            if($ready||$receipts){
                $phase='goal_check';
                if(!$api){
                    if(!defined('METRIKA_COUNTER_ID')||(string)METRIKA_COUNTER_ID!=='98615635'||!defined('METRIKA_OAUTH_TOKEN'))throw new RuntimeException('value_api_unavailable');
                    $api=new MetrikaOfflineClient('98615635',(string)METRIKA_OAUTH_TOKEN);
                }
                $goal=$api->modeledValueGoalStatus();
                if(($goal['id']??null)!==$s['goal_id'])throw new RuntimeException('value_goal_changed');
                $phase='upload';
                foreach($ready as$r){
                    if((self::settings($p)['mode']??'off')!=='send')break;
                    if(!$self->eligible($r,$gate)){$self->observeFixedPairDecision($r,$now,$injectedClock?$now:time(),1);self::failRow($p,$r,'scope_unavailable');continue;}
                    if(!$priceGuard->maySend($r)){
                        // Preserve the price hold while letting later eligible rows advance.
                        $repo->defer((string)$r['id'],'ready',($injectedClock?$now:time())+300);
                        $self->observeFixedPairDecision($r,$now,$injectedClock?$now:time(),2);
                        continue;
                    }
                    // Revalidate the retained MAX source at dispatch, not only at the earlier signal.
                    $sendAt=$injectedClock?$now:time();$sourceAt=(int)($r['tracker_observed_unix']??0);
                    $pairMember=$self->fixedPairMember($r,$sendAt)!==null;
                    $pairSource=$self->fixedPairCandidate($r,$identities,$lookupReturned,$sendAt);
                    if($r['platform']==='max'&&($sourceAt<946684800||$sourceAt>(int)$r['event_unix']
                        ||$sourceAt>$sendAt||($pairMember&&!$pairSource)||($sendAt-$sourceAt>ModeledConversionAttributionWindow::MAX_SECONDS&&!$pairSource))){
                        // This exact pair may wait ready for a successful canonical refresh; it is never claimed on missing/changed source.
                        $self->observeFixedPairDecision($r,$now,$sendAt,3);
                        $pairHold=$pairMember;
                        $preserveReason=$pairMember&&$self->fixedPairRow($r,$sendAt)===null;
                        $hold=$p->prepare("UPDATE max_channel_conversion_outbox SET ".($pairHold?'':"state='pending',")."next_attempt_unix=?".($preserveReason?'':",last_error='identity_not_found'")
                            ." WHERE id=? AND state='ready' AND (upload_id IS NULL OR upload_id='') AND (claim_token IS NULL OR claim_token='')");
                        $hold->execute([$sendAt+300,$r['id']]);continue;
                    }
                    $collision=$p->prepare("SELECT id FROM max_channel_conversion_outbox WHERE project_key='anytour' AND counter_id='98615635'"
                        ." AND transport_target=? AND client_id=? AND event_unix=? AND id<>? AND value_rub>0"
                        ." AND ((upload_id IS NOT NULL AND upload_id<>'') OR state IN ('uploading','unknown')) LIMIT 1");
                    $collision->execute([ModeledConversionValueContract::TARGET,$r['client_id'],$r['event_unix'],$r['id']]);
                    $hasCollision=$collision->fetchColumn()!==false;$collision->closeCursor();
                    if($hasCollision){
                        $self->observeFixedPairDecision($r,$now,$injectedClock?$now:time(),4);
                        $hold=$p->prepare("UPDATE max_channel_conversion_outbox SET next_attempt_unix=?,last_error='wire_key_collision' WHERE id=? AND state='ready'");
                        $hold->execute([$sendAt+3600,$r['id']]);continue;
                    }
                    // Confirm the actual preceding Metrika visit before claiming a financial send.
                    try{$visitReport=method_exists($api,'modeledAttributionVisits')?$api->modeledAttributionVisits((string)$r['client_id'],(int)$r['event_unix'],$sendAt):['status'=>'unavailable','visits'=>[]];}catch(Throwable $ignoredWindow){$visitReport=['status'=>'unavailable','visits'=>[]];}
                    $sendAt=$injectedClock?$now:time();
                    $window=ModeledConversionAttributionWindow::assess($visitReport,(int)$r['event_unix'],$sendAt);
                    $self->observeFixedPairDecision($r,$now,$sendAt,['within_current_window'=>5,'outside_current_window'=>6,'no_prior_visit'=>7,'visit_report_unavailable'=>8][$window['reason']]??8,$window);
                    // Aggregate only this already executed financial preclaim decision; no extra visitor read.
                    $reason=array_key_exists($window['reason'],$stats['visit_gate_decisions']['reason_counts'])?$window['reason']:'unknown';
                    $stats['visit_gate_decisions']['attempts']++;
                    $stats['visit_gate_decisions']['reason_counts'][$reason]++;
                    if($reason==='visit_report_unavailable'){
                        $tuple=self::normalizeVisitFailure($visitReport['attribution_failure']??null);$found=false;
                        foreach($stats['visit_gate_decisions']['failures']as&$savedFailure){
                            if(array_slice($savedFailure,0,4)===$tuple){$savedFailure[4]++;$found=true;break;}
                        }
                        unset($savedFailure);
                        if(!$found)$stats['visit_gate_decisions']['failures'][]=[...$tuple,1];
                    }
                    if(!$window['allowed']){
                        $hold=$p->prepare("UPDATE max_channel_conversion_outbox SET next_attempt_unix=?,last_error=? WHERE id=? AND state='ready' AND (upload_id IS NULL OR upload_id='') AND (claim_token IS NULL OR claim_token='')");
                        $hold->execute([$sendAt+300,$window['reason']==='outside_current_window'?'metrika_visit_window':'metrika_visit_unverified',$r['id']]);continue;
                    }
                    $token=bin2hex(random_bytes(16));
                    [$pairWhere,$pairParams]=$pairSource?$self->fixedPairSqlGuard($r,false):['',[]];
                    $q=$p->prepare("UPDATE max_channel_conversion_outbox SET state='uploading',claim_token=?,claimed_at_unix=?,attempts=attempts+1 WHERE id=? AND state='ready' AND target=? AND transport_target=? AND value_rub=?".$pairWhere);
                    $q->execute([$token,$now,$r['id'],$r['target'],ModeledConversionValueContract::TARGET,$r['value_rub'],...$pairParams]);
                    if($q->rowCount()!==1)continue;
                    try{$response=$api->uploadModeledValue($r,$now);}catch(Throwable $ignored){$response=['outcome'=>'unknown','reason'=>'upload_outcome_unknown'];}
                    $self->finishObserved($repo,(string)$r['id'],$token,$response,$now,self::observedAt($clock,$now));
                    if(($response['outcome']??null)==='accepted')$repo->defer((string)$r['id'],'accepted',$now+300);
                    $key=($response['outcome']??'')==='accepted'?'accepted':(($response['outcome']??'')==='unknown'?'unknown':'errors');$stats[$key]++;
                    if($key==='errors')$failure??=ModeledConversionTickFailure::fromUpload($now,$response);
                }
                $phase='reconcile_receipts';
                foreach($receipts as$r){
                    if($r['state']==='accepted'){
                        try{$status=$api->poll((string)$r['upload_id']);}
                        finally{$self->observeReceiptPoll($api,(string)$r['upload_id'],$now);}
                    }else $status=$api->findUnknown($r);
                    if($status){$self->statusObserved($repo,$r,$status,$now,self::observedAt($clock,$now));if(($status['status']??'')==='PROCESSED')$stats['processed']++;}
                    else $repo->defer((string)$r['id'],$r['state'],$now+300);
                }
            }
        }catch(Throwable $error){$stats['errors']++;$failure??=ModeledConversionTickFailure::fromException($now,$phase,$error);}
        // Independent GET-only observation. A report failure never changes delivery/retry decisions.
        $stats['metrika_report']=['status'=>'unavailable','goal_id'=>'678187097','currency'=>'RUB',
            'fetched_at'=>gmdate('Y-m-d\\TH:i:s\\Z',$now),'reason'=>'report_unavailable'];
        try{
            if(($s['goal_id']??null)!=='678187097')throw new RuntimeException('report_goal_mismatch');
            if(!$api){
                if(!defined('METRIKA_COUNTER_ID')||(string)METRIKA_COUNTER_ID!=='98615635'||!defined('METRIKA_OAUTH_TOKEN'))throw new RuntimeException('report_config_unavailable');
                $api=new MetrikaOfflineClient('98615635',(string)METRIKA_OAUTH_TOKEN);
            }
            if(method_exists($api,'modeledValueReport'))$stats['metrika_report']=$api->modeledValueReport((int)$s['activated_at_unix'],$now);
        }catch(Throwable $ignoredReport){ /* Keep fixed unavailable, never a fabricated zero. */ }
        // Finite GET-only evidence follows ordinary financial work and cannot alter its decisions.
        $self->runFiniteEvidence($api,$now,$gate,$clock);
        $self->runRecoveryEvidence($api,$now,$gate,$clock,$stats['metrika_report']);
        if($self->persistTickReceipt($stats,$failure,$now)){
            try{$self->runRetainedAppEvidence(self::observedAt($clock,$now),$gate);}
            catch(Throwable $ignoredRetained){ /* Optional clock/evidence never changes the committed financial outcome. */ }
            try{$self->runRetainedTrackerEvidence($lookup,self::observedAt($clock,$now),$gate);}
            catch(Throwable $ignoredTracker){ /* A separate source observation cannot change committed financial work. */ }
            try{$self->runRetainedTrackerEvidence($lookup,self::observedAt($clock,$now),$gate,true);}
            catch(Throwable $ignoredKnown){ /* The finite known-ID question never changes committed financial outcomes. */ }
        }
        // Private counts stay in the DB; ordinary diagnostic logs get technical state only.
        return ['status'=>$stats['errors']?'degraded':'running','contract'=>ModeledConversionValueContract::VERSION];
    }

    /** Merge after all network work, so overlapping healthy ticks cannot erase failure evidence. */
    private function persistTickReceipt(array $stats,?array $failure,int $now):bool
    {
        $decisions=$stats['visit_gate_decisions']??null;
        // New optional counters must never consume space reserved for existing proof or failures.
        unset($stats['visit_gate_decisions'],$stats[ModeledConversionFixedPairDecisionEvidence::RECEIPT_KEY],$stats['receipt_poll_evidence'],$stats[ModeledConversionMaxCounterScopeEvidence::RECEIPT_KEY]);
        $p=$this->pdo;
        if($p->inTransaction())throw new RuntimeException('value_tick_receipt_outer_transaction');
        $p->beginTransaction();
        try{
            $mysql=$p->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql';
            $s=$p->query("SELECT activated_at_unix,last_tick_unix,last_receipt_json FROM modeled_conversion_value_export_settings WHERE project_key='anytour'".($mysql?' FOR UPDATE':''))->fetch(PDO::FETCH_ASSOC);
            if(!$s){$p->commit();return false;}
            $receipt=null;$unreadableReceipt=$s['last_receipt_json']!==null;
            if(is_string($s['last_receipt_json'])&&strlen($s['last_receipt_json'])<=8192&&str_starts_with(ltrim($s['last_receipt_json']),'{')){
                try{$receipt=json_decode($s['last_receipt_json'],true,32,JSON_THROW_ON_ERROR);$unreadableReceipt=!is_array($receipt);}
                catch(JsonException $ignored){}
            }
            $lastTick=(int)($s['last_tick_unix']??0);
            $previous=ModeledConversionTickFailure::normalize(is_array($receipt)?($receipt['last_recorded_failure']??null):null,(int)$s['activated_at_unix'],$lastTick);
            $incoming=ModeledConversionTickFailure::normalize($failure,(int)$s['activated_at_unix'],$now);
            // Equal-second failures retain the first saved record; a healthy tick never clears it.
            $retained=$incoming!==null&&($previous===null||$incoming['tick_at_unix']>$previous['tick_at_unix'])?$incoming:$previous;
            $audit=null;
            try{$audit=$this->identityEvidence(is_array($receipt)?($receipt['identity_resolution_evidence']??null):null,max($now,$lastTick));}
            catch(Throwable $ignored){ /* Optional metadata cannot stop delivery receipts. */ }
            if($lastTick>$now){
                // A delayed tick may enrich metadata, never replace newer counts or report clocks.
                if(is_array($receipt)&&!$unreadableReceipt){
                    $updated=$receipt;
                    if($retained!==null&&$retained!==$previous){
                        $updated['last_recorded_failure']=$retained;
                        unset($updated['visit_gate_decisions'],$updated[ModeledConversionFixedPairDecisionEvidence::RECEIPT_KEY],$updated['receipt_poll_evidence'],$updated[ModeledConversionMaxCounterScopeEvidence::RECEIPT_KEY],$updated[ModeledConversionRetainedAppIdentityEvidence::RECEIPT_KEY],$updated[ModeledConversionRetainedTrackerIdentityEvidence::RECEIPT_KEY],$updated[ModeledConversionRetainedTrackerIdentityEvidence::KNOWN_KEYS[0]],$updated[ModeledConversionRetainedTrackerIdentityEvidence::KNOWN_KEYS[1]]);
                        // The new forward journal yields before immutable original evidence or failure history.
                        unset($updated['finite_visit_recovery']);
                        // Only the winning saved audit may yield space to a newly retained failure.
                        if(array_key_exists('identity_resolution_evidence',$receipt)){
                            $savedAudit=$receipt['identity_resolution_evidence'];$validAudit=false;
                            try{$validAudit=is_array($savedAudit)&&is_string($savedAudit['n']??null)
                                &&ModeledConversionValueIdentityEvidence::normalize($savedAudit,$savedAudit['n'],$lastTick)!==null;}
                            catch(Throwable $ignored){}
                            if($validAudit)$updated=$this->withIdentityEvidence($updated,$savedAudit,$mysql);
                            else{
                                unset($updated['identity_resolution_evidence']);
                                $updated['identity_resolution_evidence_incomplete']=true;
                            }
                        }
                    }
                    if($retained!==null&&$retained!==$previous){
                        $withoutRecovery=$receipt;unset($withoutRecovery['finite_visit_recovery']);
                        $updated=$this->withFiniteEvidence($updated,$withoutRecovery,$mysql);
                        $updated=$this->withRecoveryEvidence($updated,$receipt,$mysql);
                        $updated=$this->withCounterScopeEvidence($updated,$receipt,$mysql,max($now,$lastTick));
                        if($updated===null){$p->commit();return false;}
                    }
                    // A delayed cohort is not authoritative for the newer receipt's membership or completeness.
                    $encoded=json_encode($updated,JSON_THROW_ON_ERROR);
                    $storedBytes=0;
                    if($updated!==$receipt&&$this->receiptFits($encoded,$mysql,$storedBytes)){
                        [$updated,$storedBytes]=$this->withFixedPairDecisions($updated,$receipt,$storedBytes,max($now,$lastTick),false);
                        [$updated,$storedBytes]=$this->withReceiptPollEvidence($updated,$receipt['receipt_poll_evidence']??null,$storedBytes,max($now,$lastTick));
                        $updated=self::withVisitGateDecisions($updated,$receipt['visit_gate_decisions']??null,$storedBytes,$lastTick);
                        $encoded=json_encode($updated,JSON_THROW_ON_ERROR);
                        $q=$p->prepare("UPDATE modeled_conversion_value_export_settings SET last_receipt_json=? WHERE project_key='anytour'");
                        $q->execute([$encoded]);
                    }
                }
            }else{
                $stats['last_recorded_failure']=$retained;
                $stats=$this->withIdentityEvidence($stats,$audit,$mysql);
                $stats=$this->withFiniteEvidence($stats,$unreadableReceipt?['finite_visit_evidence'=>false]:(is_array($receipt)?$receipt:[]),$mysql);
                $stats=$this->withRecoveryEvidence($stats,$unreadableReceipt?['finite_visit_recovery'=>false]:(is_array($receipt)?$receipt:[]),$mysql);
                $stats=$this->withCounterScopeEvidence($stats,$unreadableReceipt?[ModeledConversionMaxCounterScopeEvidence::RECEIPT_KEY=>false,ModeledConversionRetainedAppIdentityEvidence::RECEIPT_KEY=>false,ModeledConversionRetainedTrackerIdentityEvidence::RECEIPT_KEY=>false,ModeledConversionRetainedTrackerIdentityEvidence::KNOWN_KEYS[0]=>false,ModeledConversionRetainedTrackerIdentityEvidence::KNOWN_KEYS[1]=>false]:(is_array($receipt)?$receipt:[]),$mysql,$now);
                if($stats===null){$p->commit();return false;}
                $encoded=json_encode($stats,JSON_THROW_ON_ERROR);
                // Optional audit yields before the established historical failure fallback.
                $storedBytes=0;$fits=$this->receiptFits($encoded,$mysql,$storedBytes);
                if(!$fits){unset($stats['last_recorded_failure']);$encoded=json_encode($stats,JSON_THROW_ON_ERROR);}
                else{
                    [$stats,$storedBytes]=$this->withFixedPairDecisions($stats,is_array($receipt)?$receipt:[],$storedBytes,$now,true);
                    [$stats,$storedBytes]=$this->withReceiptPollEvidence($stats,$this->receiptPollEvidence,$storedBytes,$now);
                    $stats=self::withVisitGateDecisions($stats,$decisions,$storedBytes,$now);
                    $encoded=json_encode($stats,JSON_THROW_ON_ERROR);
                }
                $report=$stats['metrika_report'];$reportOk=in_array($report['status']??null,['ok','sampled'],true);
                $q=$p->prepare("UPDATE modeled_conversion_value_export_settings SET last_tick_unix=?,last_receipt_json=?,"
                    ."last_report_success_unix=CASE WHEN ?=1 THEN ? ELSE last_report_success_unix END,"
                    ."last_report_success_json=CASE WHEN ?=1 THEN ? ELSE last_report_success_json END,"
                    ."report_failure_since_unix=CASE WHEN ?=1 THEN NULL ELSE COALESCE(report_failure_since_unix,?) END WHERE project_key='anytour' AND (last_tick_unix IS NULL OR last_tick_unix<=?)");
                $q->execute([$now,$encoded,$reportOk?1:0,$now,$reportOk?1:0,
                    $reportOk?json_encode($report,JSON_THROW_ON_ERROR):null,$reportOk?1:0,$now,$now]);
            }
            $p->commit();
            return $lastTick<=$now;
        }catch(Throwable $error){if($p->inTransaction())$p->rollBack();throw $error;}
    }

    /** A consumed optional request can yield its payload, never its permanent no-replay marker. */
    private function withCounterScopeEvidence(array $stats,array $previous,bool $mysql,int $now):?array
    {
        $key=ModeledConversionMaxCounterScopeEvidence::RECEIPT_KEY;
        if(!array_key_exists($key,$previous))return $this->withRetainedAppEvidence($stats,$previous,$mysql);
        $saved=$previous[$key];$keep=false;
        try{
            $validationNow=time();$ledger=$this->counterScopeLedger??$this->counterScopeBindingRow($validationNow);
            if($saved!==false&&$this->finiteFrame!==null&&$ledger!==null
                &&(ModeledConversionMaxCounterScopeEvidence::projection($saved,$this->finiteFrame,$ledger,$this->finiteFrame['n'],$validationNow)['available']??false)===true)$keep=$saved;
            $stats[$key]=$keep;
            if(!$this->receiptFits(json_encode($stats,JSON_THROW_ON_ERROR),$mysql))$stats[$key]=false;
            if(!$this->receiptFits(json_encode($stats,JSON_THROW_ON_ERROR),$mysql)&&is_array($stats['identity_resolution_evidence']??null))
                $stats=$this->withIdentityEvidence($stats,$stats['identity_resolution_evidence'],$mysql);
            if(!$this->receiptFits(json_encode($stats,JSON_THROW_ON_ERROR),$mysql)&&isset($stats['price_continuity_audit']['corrections'])){
                $stats['price_continuity_audit']['corrections']=[];$stats['price_continuity_audit']['complete']=false;
            }
            if(!$this->receiptFits(json_encode($stats,JSON_THROW_ON_ERROR),$mysql))unset($stats['price_continuity_audit']);
            if(!$this->receiptFits(json_encode($stats,JSON_THROW_ON_ERROR),$mysql))return null;
            return $this->withRetainedAppEvidence($stats,$previous,$mysql);
        }catch(Throwable $ignored){return null;}
    }


    /** Optional consumed evidence yields its payload before old proof or failures, but never its no-replay marker. */
    private function withRetainedAppEvidence(array $stats,array $previous,bool $mysql):?array
    {
        $key=ModeledConversionRetainedAppIdentityEvidence::RECEIPT_KEY;
        if(!array_key_exists($key,$previous))return $this->withRetainedTrackerEvidence($stats,$previous,$mysql);
        $saved=$previous[$key];$stats[$key]=is_array($saved)&&strlen(json_encode($saved,JSON_THROW_ON_ERROR))<=768?$saved:false;
        if($this->receiptFits(json_encode($stats,JSON_THROW_ON_ERROR),$mysql))return $this->withRetainedTrackerEvidence($stats,$previous,$mysql);
        $stats[$key]=false;
        if(!$this->receiptFits(json_encode($stats,JSON_THROW_ON_ERROR),$mysql)&&is_array($stats['identity_resolution_evidence']??null))
            $stats=$this->withIdentityEvidence($stats,$stats['identity_resolution_evidence'],$mysql);
        if(!$this->receiptFits(json_encode($stats,JSON_THROW_ON_ERROR),$mysql)&&isset($stats['price_continuity_audit']))unset($stats['price_continuity_audit']);
        return $this->receiptFits(json_encode($stats,JSON_THROW_ON_ERROR),$mysql)?$this->withRetainedTrackerEvidence($stats,$previous,$mysql):null;
    }

    /** One local-only observation after ordinary financial work, never an identity resolution or delivery path. */
    private function runRetainedAppEvidence(int $now,callable $gate):void
    {
        if($this->retainedAppConsumed||$this->finiteFrame===null||$this->pdo->inTransaction())return;
        $selected=$this->counterScopeBindingRow($now);if($selected===null)return;
        $p=$this->pdo;$key=ModeledConversionRetainedAppIdentityEvidence::RECEIPT_KEY;$intent=null;$row=null;
        try{
            $p->beginTransaction();$mysql=$p->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql';$lock=$mysql?' FOR UPDATE':'';
            $s=$p->query("SELECT counter_id,mode,goal_id,goal_intent,contract_version,last_receipt_json FROM modeled_conversion_value_export_settings WHERE project_key='anytour'".$lock)->fetch(PDO::FETCH_ASSOC);
            $raw=$s['last_receipt_json']??null;
            if(!$s||$s['counter_id']!=='98615635'||$s['mode']!=='send'||$s['goal_id']!=='678187097'||$s['goal_intent']!=='verified'
                ||$s['contract_version']!==ModeledConversionValueContract::VERSION||!is_string($raw)||strlen($raw)>8192||!str_starts_with(ltrim($raw),'{')){$p->rollBack();return;}
            $receipt=json_decode($raw,true,32,JSON_THROW_ON_ERROR);
            if(!is_array($receipt)||array_key_exists($key,$receipt)){$this->retainedAppConsumed=true;$p->rollBack();return;}
            $names=['id','value_profile_id','project_key','platform','conversation_id','external_user_id','counter_id','target','transport_target',
                'value_contract','event_unix','value_rub','state','attempts','identity_source_key','client_id','tracker_record_id','tracker_observed_unix',
                'claim_token','claimed_at_unix','upload_id','upload_status','value_receipt_basis','value_ready_at_unix','value_api_accepted_at_unix','value_processed_at_unix'];
            $q=$p->prepare('SELECT '.implode(',',$names)." FROM max_channel_conversion_outbox WHERE id=? AND project_key='anytour' AND counter_id='98615635' LIMIT 1".$lock);
            $q->execute([$selected['id']]);$row=$q->fetch(PDO::FETCH_ASSOC);$q->closeCursor();
            $ns=$this->finiteFrame['n'];
            if(!$row||ModeledConversionValueIdentityEvidence::reference($row,$ns)!==ModeledConversionValueIdentityEvidence::reference($selected,$ns)
                ||$row['state']!=='pending'||(string)$row['attempts']!=='0'||!$this->eligible($row,$gate)){$p->rollBack();return;}
            foreach(['client_id','tracker_record_id','tracker_observed_unix','claim_token','claimed_at_unix','upload_id','upload_status','value_receipt_basis',
                'value_ready_at_unix','value_api_accepted_at_unix','value_processed_at_unix']as$field)
                if(!in_array($row[$field],[null,''],true)){$p->rollBack();return;}
            $intent=ModeledConversionRetainedAppIdentityEvidence::intent($receipt['finite_visit_evidence']??null,$row,$ns,$now);
            if($intent===null||ModeledConversionRetainedAppIdentityEvidence::binding($this->finiteFrame,$row,$ns,$now)!==
                ModeledConversionRetainedAppIdentityEvidence::binding($receipt['finite_visit_evidence']??null,$row,$ns,$now)){$p->rollBack();return;}
            $receipt[$key]=$intent;$encoded=json_encode($receipt,JSON_THROW_ON_ERROR);
            if(!$this->receiptFits($encoded,$mysql)){$p->rollBack();return;}
            $q=$p->prepare("UPDATE modeled_conversion_value_export_settings SET last_receipt_json=? WHERE project_key='anytour'");$q->execute([$encoded]);$p->commit();
            $this->retainedAppConsumed=true;$this->retainedAppAttempted=true;
            // Both source SELECTs happen only after that committed intent; binding/intent IO is counted separately.
            $result=ModeledConversionRetainedAppIdentityEvidence::observe($p,$intent,$row,$now);
            $p->beginTransaction();
            $s=$p->query("SELECT last_receipt_json FROM modeled_conversion_value_export_settings WHERE project_key='anytour'".$lock)->fetch(PDO::FETCH_ASSOC);
            $raw=$s['last_receipt_json']??null;
            if(!is_string($raw)||strlen($raw)>8192||!str_starts_with(ltrim($raw),'{')){$p->rollBack();return;}
            $receipt=json_decode($raw,true,32,JSON_THROW_ON_ERROR);
            if(!is_array($receipt)||!is_array($receipt[$key]??null)){$p->rollBack();return;}
            $current=$receipt[$key];$expected=$intent;ksort($current,SORT_STRING);ksort($expected,SORT_STRING);
            if($current!==$expected){$p->rollBack();return;}
            $done=ModeledConversionRetainedAppIdentityEvidence::complete($intent,$receipt['finite_visit_evidence']??null,$row,$ns,$result,$now);
            if($done===null){$p->rollBack();return;}
            $receipt[$key]=$done;$encoded=json_encode($receipt,JSON_THROW_ON_ERROR);
            if(!$this->receiptFits($encoded,$mysql)){$p->rollBack();return;}
            $q=$p->prepare("UPDATE modeled_conversion_value_export_settings SET last_receipt_json=? WHERE project_key='anytour'");$q->execute([$encoded]);$p->commit();
        }catch(Throwable $ignored){if($p->inTransaction())$p->rollBack();}
    }


    private function withRetainedTrackerEvidence(array $stats,array $previous,bool $mysql):?array
    {
        $key=ModeledConversionRetainedTrackerIdentityEvidence::RECEIPT_KEY;
        if(!array_key_exists($key,$previous))return $this->withKnownTrackerEvidence($stats,$previous,$mysql);
        $saved=$previous[$key];$stats[$key]=is_array($saved)&&strlen(json_encode($saved,JSON_THROW_ON_ERROR))<=768?$saved:false;
        if($this->receiptFits(json_encode($stats,JSON_THROW_ON_ERROR),$mysql))return $this->withKnownTrackerEvidence($stats,$previous,$mysql);
        $stats[$key]=false;
        $appKey=ModeledConversionRetainedAppIdentityEvidence::RECEIPT_KEY;
        if(!$this->receiptFits(json_encode($stats,JSON_THROW_ON_ERROR),$mysql)&&is_array($stats[$appKey]??null))$stats[$appKey]=false;
        return $this->receiptFits(json_encode($stats,JSON_THROW_ON_ERROR),$mysql)?$this->withKnownTrackerEvidence($stats,$previous,$mysql):null;
    }



    private function withKnownTrackerEvidence(array $stats,array $previous,bool $mysql):?array
    {
        foreach(ModeledConversionRetainedTrackerIdentityEvidence::KNOWN_KEYS as$key)if(array_key_exists($key,$previous)){
            $saved=$previous[$key];$stats[$key]=is_array($saved)&&strlen(json_encode($saved,JSON_THROW_ON_ERROR))<=768?$saved:false;
        }
        if($this->receiptFits(json_encode($stats,JSON_THROW_ON_ERROR),$mysql))return $stats;
        foreach(ModeledConversionRetainedTrackerIdentityEvidence::KNOWN_KEYS as$key)if(array_key_exists($key,$stats))$stats[$key]=false;
        if(!$this->receiptFits(json_encode($stats,JSON_THROW_ON_ERROR),$mysql)&&is_array($stats['identity_resolution_evidence']??null))
            $stats=$this->withIdentityEvidence($stats,$stats['identity_resolution_evidence'],$mysql);
        if(!$this->receiptFits(json_encode($stats,JSON_THROW_ON_ERROR),$mysql))unset($stats['price_continuity_audit']);
        return $this->receiptFits(json_encode($stats,JSON_THROW_ON_ERROR),$mysql)?$stats:null;
    }
    private function knownTrackerReady(array $r,int $now):bool
    {
        if(($r['project_key']??null)!=='anytour'||($r['platform']??null)!=='max'||($r['counter_id']??null)!=='98615635'
            ||($r['identity_source_key']??null)!=='max_tracker'||($r['state']??null)!=='ready'||(string)($r['attempts']??'')!=='0'
            ||!MaxTrackerLookupProtocol::id($r['client_id']??null,40)||!MaxTrackerLookupProtocol::id($r['tracker_record_id']??null))return false;
        if($this->finiteFrame===null)return false;
        $held=ModeledConversionRetainedTrackerIdentityEvidence::knownBinding($this->finiteFrame,$r,$this->finiteFrame['n'],$now);
        $source=$r['tracker_observed_unix']??null;
        if($held===null||$r['client_id']!==$held['held_client_id']||$r['tracker_record_id']!==$held['held_record_id']
            ||(!is_int($source)&&!is_string($source))||preg_match('/\A[1-9][0-9]{8,11}\z/D',(string)$source)!==1||(int)$source!==$held['held_source_at'])return false;
        foreach(['claim_token','claimed_at_unix','upload_id','upload_status','value_receipt_basis','value_api_accepted_at_unix','value_processed_at_unix']as$key)
            if(!array_key_exists($key,$r)||!in_array($r[$key],[null,''],true))return false;
        $ready=$r['value_ready_at_unix']??null;
        return (is_int($ready)||is_string($ready))&&preg_match('/\A[1-9][0-9]{8,11}\z/D',(string)$ready)===1&&(int)$ready<=$now;
    }
    /** One case-bound replacement; it never adds a second call for the same logical user. */
    private function reserveKnownTracker(object $lookup,array $users,array $rows,int $now):array
    {
        if($this->retainedTrackerReservation!==null||!method_exists($lookup,'retainedClickCandidate')
            ||count(array_unique($users))>MaxTrackerLookupProtocol::MAX_USERS)return $users;
        foreach($this->retainedKnownCandidates as$candidate){
            $selected=$candidate['row'];$uid=$selected['external_user_id'];
            if(!$this->knownTrackerReady($selected,$now)||!in_array($uid,$users,true))continue;$seen=false;$conflict=false;
            foreach($rows as$r)if($r['platform']==='max'&&$r['external_user_id']===$uid){
                if((string)$r['id']!==(string)$selected['id']){$conflict=true;break;}$seen=true;
            }
            if(!$seen||$conflict)continue;$this->retainedKnownReservation=$candidate;
            return array_values(array_filter($users,static fn(string $user):bool=>$user!==$uid));
        }return $users;
    }

    /** A single in-memory replacement for this exact pending subject, never another financial user's slot. */
    private function reserveRetainedTracker(object $lookup,array $users,array $rows,int $now):array
    {
        $selected=$this->counterScopeLedger;$unique=array_values(array_unique($users));
        if($this->retainedTrackerConsumed||!$this->retainedTrackerSourceUnsupported||$selected===null
            ||!method_exists($lookup,'retainedClickCandidate')||count($unique)>MaxTrackerLookupProtocol::MAX_USERS-1
            ||!in_array($selected['external_user_id'],$unique,true)||!$this->counterScopeAdmissible($selected,$now))return $users;
        $seen=false;
        foreach($rows as$r)if($r['platform']==='max'&&$r['external_user_id']===$selected['external_user_id']){
            if((string)$r['id']!==(string)$selected['id']||!$this->counterScopeAdmissible($r,$now)
                ||ModeledConversionValueIdentityEvidence::reference($r,$this->finiteFrame['n'])!==ModeledConversionValueIdentityEvidence::reference($selected,$this->finiteFrame['n']))return $users;
            $seen=true;
        }
        if(!$seen)return $users;$this->retainedTrackerReservation=$selected;
        return array_values(array_filter($users,static fn(string $user):bool=>$user!==$selected['external_user_id']));
    }

    /** Known branch:24 ordinary users+1 source user<=25, one lookup/user/tick; receiver SELECTs are not user slots.
     * The old missing-case0 branch retains its existing conservative guard. */
    private function runRetainedTrackerEvidence(?object $lookup,int $now,callable $gate,bool $known=false):void
    {
        $reservation=$known?$this->retainedKnownReservation:$this->retainedTrackerReservation;
        if($this->finiteFrame===null||$reservation===null||$this->pdo->inTransaction()||(!$known&&$this->retainedTrackerConsumed))return;
        if($known?count($this->ordinaryLookupUsers)+1>MaxTrackerLookupProtocol::MAX_USERS:
            count($this->ordinaryLookupUsers)+($this->counterScopeIntent!==null?2:0)+($this->retainedAppAttempted?2:0)>MaxTrackerLookupProtocol::MAX_USERS-2)return;
        $selected=$known?$reservation['row']:$this->counterScopeBindingRow($now);$reservedRow=$known?$reservation['row']:$reservation;
        if($selected===null||in_array($selected['external_user_id'],$this->ordinaryLookupUsers,true)
            ||ModeledConversionValueIdentityEvidence::reference($selected,$this->finiteFrame['n'])!==ModeledConversionValueIdentityEvidence::reference($reservedRow,$this->finiteFrame['n']))return;
        $lookup??=new MaxTrackerLookupClient();if(!method_exists($lookup,'retainedClickCandidate'))return;
        $context=$known?ModeledConversionRetainedTrackerIdentityEvidence::knownContext($this->finiteFrame,$selected,$this->finiteFrame['n'],$now):
            ModeledConversionRetainedTrackerIdentityEvidence::context($this->finiteFrame,$selected,$this->finiteFrame['n'],$now);
        if($context===null)return;$intent=null;$row=null;$p=$this->pdo;$key=$known?$reservation['key']:ModeledConversionRetainedTrackerIdentityEvidence::RECEIPT_KEY;
        try{
            $response=$lookup->retainedClickCandidate($context,function(string $hash)use($selected,$gate,$now,$p,$key,$known,&$intent,&$row):bool{
                try{
                    if($p->inTransaction())return false;$p->beginTransaction();$mysql=$p->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql';$lock=$mysql?' FOR UPDATE':'';
                    $s=$p->query("SELECT counter_id,mode,goal_id,goal_intent,contract_version,last_receipt_json FROM modeled_conversion_value_export_settings WHERE project_key='anytour'".$lock)->fetch(PDO::FETCH_ASSOC);
                    $raw=$s['last_receipt_json']??null;
                    if(!$s||$s['counter_id']!=='98615635'||$s['mode']!=='send'||$s['goal_id']!=='678187097'||$s['goal_intent']!=='verified'
                        ||$s['contract_version']!==ModeledConversionValueContract::VERSION||!is_string($raw)||strlen($raw)>8192||!str_starts_with(ltrim($raw),'{')){$p->rollBack();return false;}
                    $receipt=json_decode($raw,true,32,JSON_THROW_ON_ERROR);
                    if(!is_array($receipt)||array_key_exists($key,$receipt)){if(!$known)$this->retainedTrackerConsumed=true;$p->rollBack();return false;}
                    if($known&&!array_key_exists(ModeledConversionRetainedTrackerIdentityEvidence::RECEIPT_KEY,$receipt)){$p->rollBack();return false;}
                    $names=['id','value_profile_id','project_key','platform','conversation_id','external_user_id','counter_id','target','transport_target',
                        'value_contract','event_unix','value_rub','state','attempts','identity_source_key','client_id','tracker_record_id','tracker_observed_unix',
                        'claim_token','claimed_at_unix','upload_id','upload_status','value_receipt_basis','value_ready_at_unix','value_api_accepted_at_unix','value_processed_at_unix'];
                    $q=$p->prepare('SELECT '.implode(',',$names)." FROM max_channel_conversion_outbox WHERE id=? AND project_key='anytour' AND counter_id='98615635' LIMIT 1".$lock);
                    $q->execute([$selected['id']]);$row=$q->fetch(PDO::FETCH_ASSOC);$q->closeCursor();$ns=$this->finiteFrame['n'];
                    if(!$row||$row['identity_source_key']!=='max_tracker'||($known?!$this->knownTrackerReady($row,$now):($row['state']!=='pending'||(string)$row['attempts']!=='0'))
                        ||!$this->eligible($row,$gate)||ModeledConversionValueIdentityEvidence::reference($row,$ns)!==ModeledConversionValueIdentityEvidence::reference($selected,$ns)){$p->rollBack();return false;}
                    $empty=$known?['claim_token','claimed_at_unix','upload_id','upload_status','value_receipt_basis','value_api_accepted_at_unix','value_processed_at_unix']:['client_id','tracker_record_id','tracker_observed_unix','claim_token','claimed_at_unix','upload_id','upload_status','value_receipt_basis',
                        'value_ready_at_unix','value_api_accepted_at_unix','value_processed_at_unix'];
                    foreach($empty as$field)
                        if(!in_array($row[$field],[null,''],true)){$p->rollBack();return false;}
                    $original=$receipt['finite_visit_evidence']??null;
                    if($known){
                        if(ModeledConversionRetainedTrackerIdentityEvidence::knownContext($original,$row,$ns,$now)!==
                            ModeledConversionRetainedTrackerIdentityEvidence::knownContext($this->finiteFrame,$selected,$ns,$now)){$p->rollBack();return false;}
                        $b=ModeledConversionRetainedTrackerIdentityEvidence::knownBinding($original,$row,$ns,$now);
                        if($b===null||$b['key']!==$key){$p->rollBack();return false;}
                        $intent=ModeledConversionRetainedTrackerIdentityEvidence::knownIntent($original,$row,$ns,$hash,$now,$receipt[ModeledConversionFixedPairDecisionEvidence::RECEIPT_KEY]??null);
                    }else{
                        $app=ModeledConversionRetainedAppIdentityEvidence::projection($receipt[ModeledConversionRetainedAppIdentityEvidence::RECEIPT_KEY]??null,$original,$row,$ns,$now);
                        if(($app['available']??false)!==true||($app['status']??null)!=='unsupported_source'
                            ||ModeledConversionRetainedTrackerIdentityEvidence::context($original,$row,$ns,$now)!==
                              ModeledConversionRetainedTrackerIdentityEvidence::context($this->finiteFrame,$selected,$ns,$now)){$p->rollBack();return false;}
                        $intent=ModeledConversionRetainedTrackerIdentityEvidence::intent($original,$row,$ns,$hash,$now);
                    }
                    if($intent===null){$p->rollBack();return false;}
                    $receipt[$key]=$intent;$encoded=json_encode($receipt,JSON_THROW_ON_ERROR);
                    $admitted=$this->receiptFits($encoded,$mysql);
                    if(!$admitted){$receipt[$key]=false;$encoded=json_encode($receipt,JSON_THROW_ON_ERROR);
                        if(!$this->receiptFits($encoded,$mysql)){$p->rollBack();return false;}}
                    $q=$p->prepare("UPDATE modeled_conversion_value_export_settings SET last_receipt_json=? WHERE project_key='anytour'");$q->execute([$encoded]);$p->commit();
                    if(!$known)$this->retainedTrackerConsumed=true;return $admitted;
                }catch(Throwable $ignored){if($p->inTransaction())$p->rollBack();return false;}
            });
            if($intent===null||$row===null||$response===null)return;
            $p->beginTransaction();$mysql=$p->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql';$lock=$mysql?' FOR UPDATE':'';
            $s=$p->query("SELECT last_receipt_json FROM modeled_conversion_value_export_settings WHERE project_key='anytour'".$lock)->fetch(PDO::FETCH_ASSOC);
            $raw=$s['last_receipt_json']??null;
            if(!is_string($raw)||strlen($raw)>8192||!str_starts_with(ltrim($raw),'{')){$p->rollBack();return;}
            $receipt=json_decode($raw,true,32,JSON_THROW_ON_ERROR);$current=$receipt[$key]??null;$expected=$intent;
            if(!is_array($current)){$p->rollBack();return;}ksort($current,SORT_STRING);ksort($expected,SORT_STRING);
            if($current!==$expected){$p->rollBack();return;}
            $done=$known?ModeledConversionRetainedTrackerIdentityEvidence::knownComplete($intent,$receipt['finite_visit_evidence']??null,$row,$this->finiteFrame['n'],$response,time()):
                ModeledConversionRetainedTrackerIdentityEvidence::complete($intent,$receipt['finite_visit_evidence']??null,$row,$this->finiteFrame['n'],$response,time());
            if($done===null){$p->rollBack();return;}$receipt[$key]=$done;$encoded=json_encode($receipt,JSON_THROW_ON_ERROR);
            if(!$this->receiptFits($encoded,$mysql)){$p->rollBack();return;}
            $q=$p->prepare("UPDATE modeled_conversion_value_export_settings SET last_receipt_json=? WHERE project_key='anytour'");$q->execute([$encoded]);$p->commit();
        }catch(Throwable $ignored){if($p->inTransaction())$p->rollBack();}
    }

    /** Pure final attachment uses the existing native byte measurement; it cannot crowd out money or once-only proof. */
    private function withFixedPairDecisions(array $stats,array $previous,int $storedBytes,int $now,bool $includeCurrent):array
    {
        try{
            if($this->finiteFrame===null)return [$stats,$storedBytes];
            $key=ModeledConversionFixedPairDecisionEvidence::RECEIPT_KEY;
            $frame=ModeledConversionFixedPairDecisionEvidence::merge($previous[$key]??null,$this->finiteFrame,$this->finiteFrame['n'],
                $includeCurrent?array_values($this->fixedPairDecisions):[],time());
            if($frame===null)return [$stats,$storedBytes];
            $wrapper=json_encode([$key=>$frame],JSON_THROW_ON_ERROR);$upper=$storedBytes+2*strlen($wrapper)+4;
            if(strlen(json_encode($frame,JSON_THROW_ON_ERROR))>ModeledConversionFixedPairDecisionEvidence::MAX_BYTES||$upper>8192)return [$stats,$storedBytes];
            $stats[$key]=$frame;return [$stats,$upper];
        }catch(Throwable $ignored){return [$stats,$storedBytes];}
    }

    /** Metadata getter observes the preceding accepted-row poll only; UNKNOWN reconciliation is never relabelled as a poll. */
    private function observeReceiptPoll(mixed $api,string $uploadId,int $tick):void
    {
        try{
            if(!method_exists($api,'pollEvidence'))return;
            $audit=$this->receiptPollEvidence??ModeledConversionReceiptPollEvidence::begin($tick);
            $proof=$api->pollEvidence($uploadId);
            $this->receiptPollEvidence=ModeledConversionReceiptPollEvidence::append($audit,$proof,time());
        }catch(Throwable $ignored){ /* The poll result and its existing financial handling stay authoritative. */ }
    }

    private function withReceiptPollEvidence(array $stats,mixed $audit,int $storedBytes,int $now):array
    {
        try{
            if($audit===null||(ModeledConversionReceiptPollEvidence::projection($audit,time())['available']??false)!==true)return [$stats,$storedBytes];
            $wrapper=json_encode(['receipt_poll_evidence'=>$audit],JSON_THROW_ON_ERROR);$upper=$storedBytes+2*strlen($wrapper)+4;
            if(strlen(json_encode($audit,JSON_THROW_ON_ERROR))>ModeledConversionReceiptPollEvidence::MAX_BYTES||$upper>8192)return [$stats,$storedBytes];
            $stats['receipt_poll_evidence']=$audit;return [$stats,$upper];
        }catch(Throwable $ignored){return [$stats,$storedBytes];}
    }

    private function receiptFits(string $encoded,bool $mysql,?int &$storedBytes=null):bool
    {
        $storedBytes=strlen($encoded);
        if($storedBytes>8192)return false;
        if(!$mysql)return true;
        // Native JSON adds structural whitespace; use the same byte measure as the reader.
        $q=$this->pdo->prepare('SELECT OCTET_LENGTH(CAST(? AS JSON))');$q->execute([$encoded]);
        $storedBytes=(int)$q->fetchColumn();
        return $storedBytes<=8192;
    }

    /** Attach last, using the existing byte measurement; no new SQL or loss of prior evidence. */
    private static function withVisitGateDecisions(array $stats,mixed $audit,int $storedBytes,int $tick):array
    {
        if(!is_array($audit)||!in_array($audit['v']??null,[1,2],true)
            ||count($audit)!==(($audit['v']??null)===2?5:4)||!is_int($audit['checked_at_unix']??null)
            ||$audit['checked_at_unix']<946684800||$audit['checked_at_unix']>253402300799||$audit['checked_at_unix']!==$tick
            ||!is_int($audit['attempts']??null)||$audit['attempts']<0||$audit['attempts']>3
            ||!is_array($audit['reason_counts']??null))return $stats;
        $counts=[];$total=0;
        foreach(['within_current_window','outside_current_window','no_prior_visit','visit_report_unavailable','unknown']as$key){
            $count=$audit['reason_counts'][$key]??null;
            if(!is_int($count)||$count<0||$count>3)return $stats;
            $counts[$key]=$count;$total+=$count;
        }
        if(count($audit['reason_counts'])!==count($counts)||$total!==$audit['attempts'])return $stats;
        $clean=['v'=>$audit['v'],'checked_at_unix'=>$audit['checked_at_unix'],'attempts'=>$audit['attempts'],'reason_counts'=>$counts];
        if($audit['v']===2){
            $failures=$audit['failures']??null;$unavailable=0;$seen=[];
            if(!is_array($failures)||!array_is_list($failures)||count($failures)>3)return $stats;
            foreach($failures as$tuple){
                if(!is_array($tuple)||!array_is_list($tuple)||count($tuple)!==5
                    ||!is_int($tuple[4])||$tuple[4]<1||$tuple[4]>3
                    ||self::normalizeVisitFailure(array_slice($tuple,0,4))!==array_slice($tuple,0,4))return $stats;
                $key=json_encode(array_slice($tuple,0,4),JSON_THROW_ON_ERROR);
                if(isset($seen[$key]))return $stats;$seen[$key]=true;
                $unavailable+=$tuple[4];
            }
            if($unavailable!==$counts['visit_report_unavailable'])return $stats;
            $clean['failures']=$failures;
        }
        $wrapper=json_encode(['visit_gate_decisions'=>$clean],JSON_THROW_ON_ERROR);
        // This closed integer/string object has at most one native space per separator.
        // Twice its compact bytes plus framing safely bounds growth without another DB query.
        if(strlen($wrapper)>512||$storedBytes<0||$storedBytes+2*strlen($wrapper)+4>8192)return $stats;
        $stats['visit_gate_decisions']=$clean;
        return $stats;
    }

    /** Only closed same-response scalars; a missing or malformed diagnostic is honest unknown. */
    private static function normalizeVisitFailure(mixed $value):array
    {
        $unknown=[9,null,null,0];
        if(!is_array($value)||!array_is_list($value)||count($value)!==4
            ||!is_int($value[0])||$value[0]<1||$value[0]>9
            ||($value[1]!==null&&(!is_int($value[1])||$value[1]<100||$value[1]>599))
            ||($value[2]!==null&&(!is_int($value[2])||$value[2]<0||$value[2]>100))
            ||!is_int($value[3]))return $unknown;
        [$stage,$http,$curl,$mask]=$value;
        if($stage===9)return $http===null&&$curl===null&&$mask===0?$value:$unknown;
        if(in_array($stage,[1,2,4,5],true))return $mask===0?$value:$unknown;
        if($stage===3)return $mask>=1&&$mask<=15?$value:$unknown;
        if(in_array($stage,[6,7],true))return $mask>=1&&$mask<=31?$value:$unknown;
        if($mask<1||$mask>2097151||($mask&127)===0)return $unknown;
        $clock=$mask&96;
        if($clock!==0){
            if(!in_array($mask&127,[32,64],true)||($mask&384)!==0)return $unknown;
            foreach([9,13,17]as$shift){
                $group=($mask>>$shift)&15;
                if($group===0||($group&($group-1))!==0)return $unknown;
            }
        }elseif(($mask&~511)!==0)return $unknown;
        if(($mask&384)!==0&&($mask&127)!==4)return $unknown;
        return $value;
    }

    private static function observedAt(callable $clock,int $notBefore):int
    {
        $at=$clock();
        if(!is_int($at)||$at<$notBefore||$at>253402300799)throw new RuntimeException('delivery_observation_clock');
        return $at;
    }

    /** No network in this transaction: receipt and its observation clock commit together. */
    private function finishObserved(MaxChannelConversionOutbox $repo,string $id,string $token,array $response,int $tick,int $at):void
    {
        if($this->pdo->inTransaction())throw new RuntimeException('value_receipt_outer_transaction');
        $this->pdo->beginTransaction();
        try{
            $repo->finish($id,$token,$response,$tick);
            if(($response['outcome']??null)==='accepted'){
                $q=$this->pdo->prepare("UPDATE max_channel_conversion_outbox SET "
                    ."value_receipt_basis=CASE WHEN value_api_accepted_at_unix IS NULL THEN 'post_response' ELSE value_receipt_basis END,"
                    ."value_api_accepted_at_unix=COALESCE(value_api_accepted_at_unix,?)"
                    ." WHERE id=? AND project_key='anytour' AND counter_id='98615635' AND transport_target=? AND value_contract=?"
                    ." AND state='accepted' AND upload_id IS NOT NULL AND upload_id<>''");
                $q->execute([$at,$id,ModeledConversionValueContract::TARGET,ModeledConversionValueContract::VERSION]);
            }
            $this->pdo->commit();
        }catch(Throwable $error){if($this->pdo->inTransaction())$this->pdo->rollBack();throw $error;}
    }

    private function statusObserved(MaxChannelConversionOutbox $repo,array $row,array $status,int $tick,int $at):void
    {
        if($this->pdo->inTransaction())throw new RuntimeException('value_status_outer_transaction');
        $this->pdo->beginTransaction();
        try{
            $lock=$this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'?' FOR UPDATE':'';
            $q=$this->pdo->prepare("SELECT state,upload_id FROM max_channel_conversion_outbox WHERE id=? AND project_key='anytour'"
                ." AND counter_id='98615635' AND transport_target=? AND value_contract=?".$lock);
            $q->execute([$row['id'],ModeledConversionValueContract::TARGET,ModeledConversionValueContract::VERSION]);$current=$q->fetch(PDO::FETCH_ASSOC);$q->closeCursor();
            if(!$current||($current['state']??null)!==($row['state']??null)
                ||(string)($current['upload_id']??'')!==(string)($row['upload_id']??'')){
                $this->pdo->commit();return;
            }
            if(($current['upload_id']??null)!==null&&(string)$current['upload_id']!==''
                &&(string)($status['id']??'')!==(string)$current['upload_id'])throw new RuntimeException('value_receipt_changed');
            $repo->status($row,$status,$tick);
            // A GET may first reconcile an unknown POST. Polling a legacy known
            // receipt does not invent its missing historical acceptance time.
            if(($current['state']??null)==='unknown'){
                $q=$this->pdo->prepare("UPDATE max_channel_conversion_outbox SET "
                    ."value_receipt_basis=CASE WHEN value_api_accepted_at_unix IS NULL THEN 'get_reconciliation' ELSE value_receipt_basis END,"
                    ."value_api_accepted_at_unix=COALESCE(value_api_accepted_at_unix,?)"
                    ." WHERE id=? AND project_key='anytour' AND counter_id='98615635' AND transport_target=? AND value_contract=?"
                    ." AND state IN ('accepted','processed','failed','unmatched') AND upload_id IS NOT NULL AND upload_id<>''");
                $q->execute([$at,$row['id'],ModeledConversionValueContract::TARGET,ModeledConversionValueContract::VERSION]);
            }
            if(($status['status']??null)==='PROCESSED'&&(int)($status['source_quantity']??0)===1&&(int)($status['line_quantity']??0)===1){
                $q=$this->pdo->prepare("UPDATE max_channel_conversion_outbox SET value_receipt_basis=COALESCE(value_receipt_basis,'get_reconciliation'),value_processed_at_unix=COALESCE(value_processed_at_unix,?)"
                    ." WHERE id=? AND project_key='anytour' AND counter_id='98615635' AND transport_target=? AND value_contract=?"
                    ." AND state='processed' AND upload_id IS NOT NULL AND upload_id<>''");
                $q->execute([$at,$row['id'],ModeledConversionValueContract::TARGET,ModeledConversionValueContract::VERSION]);
            }
            $this->pdo->commit();
        }catch(Throwable $error){if($this->pdo->inTransaction())$this->pdo->rollBack();throw $error;}
    }

    public function collect(array $s,int $now,callable $gate,?string $profileFence=null):int
    {
        if($this->pdo->inTransaction())throw new RuntimeException('value_outer_transaction');
        if($profileFence!==null&&!preg_match('/\A(?:0|[1-9][0-9]{0,19})\z/D',$profileFence))throw new RuntimeException('value_profile_fence');
        $mysql=$this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql';$this->pdo->beginTransaction();
        try{
            $q=$this->pdo->query("SELECT * FROM modeled_conversion_value_export_settings WHERE project_key='anytour'".($mysql?' FOR UPDATE':''));
            $s=$q->fetch(PDO::FETCH_ASSOC);if(!$s||$s['mode']!=='send'){$this->pdo->rollBack();return 0;}
            $q=$this->pdo->prepare("SELECT id,project_key,platform,customer_key,conversation_id,CASE WHEN ".($mysql?'OCTET_LENGTH(profile_json)':'LENGTH(CAST(profile_json AS BLOB))')."<=131072 THEN profile_json ELSE NULL END AS profile_json FROM modeled_conversion_profile_versions WHERE project_key='anytour' AND id>?".($profileFence===null?'':' AND id<=?')." ORDER BY id LIMIT 8");
            $q->execute($profileFence===null?[$s['profile_cursor']]:[$s['profile_cursor'],$profileFence]);$rows=$q->fetchAll(PDO::FETCH_ASSOC);$after=(string)$s['profile_cursor'];$written=0;
            foreach($rows as$r){
                $after=(string)$r['id'];if(!is_string($r['profile_json']))continue;
                try{$w=json_decode($r['profile_json'],true,64,JSON_THROW_ON_ERROR);}catch(Throwable $ignored){continue;}
                $subject=['project_key'=>'anytour','platform'=>$r['platform'],'customer_key'=>(string)$r['customer_key'],'conversation_id'=>(string)$r['conversation_id']];
                if(($w['processing']['source_sha256']??null)!==hash_file('sha256',__DIR__.'/ModeledConversionProfiles.php'))continue;
                $candidate=ModeledConversionValueContract::candidate($w,$subject,(int)$s['activated_at_unix'],$now);
                if(!$candidate)continue;
                $binding=$this->binding($r['conversation_id'],$r['platform']);
                if(!$binding||(string)$binding['customer_id']!==(string)$r['customer_key']||!$gate($binding['external_chat_id']))continue;
                $q=$this->pdo->prepare("SELECT COALESCE(SUM(value_rub),0) FROM max_channel_conversion_outbox WHERE project_key='anytour' AND conversation_id=? AND transport_target=? AND value_contract=?");
                $q->execute([$r['conversation_id'],ModeledConversionValueContract::TARGET,ModeledConversionValueContract::VERSION]);
                $reserved=(int)$q->fetchColumn();$originalTarget=$candidate['value'];
                $guard=new ModeledConversionPriceContinuity($this->pdo);$decision=$guard->adjust($w,$candidate);$candidate=$decision['candidate'];
                $delta=$candidate['value']-$reserved;if($delta<=0)continue;
                $insert=$mysql?'INSERT IGNORE':'INSERT OR IGNORE';
                $q=$this->pdo->prepare($insert." INTO max_channel_conversion_outbox(project_key,conversation_id,external_user_id,counter_id,target,transport_target,event_unix,platform,identity_source_key,value_rub,value_profile_id,value_stage,value_contract) VALUES('anytour',?,?,'98615635',?,?,?,?,?,?,?,?,?)");
                $q->execute([$r['conversation_id'],$binding['external_user_id'],!empty($candidate['prediction_value'])?ModeledConversionValueContract::predictionKey((string)$r['id']):ModeledConversionValueContract::key($candidate['rank']),
                    ModeledConversionValueContract::TARGET,$candidate['event_unix'],$r['platform'],$r['platform']==='telegram'?'tgmini':'max_tracker',
                    $delta,$r['id'],$candidate['stage'],ModeledConversionValueContract::VERSION]);
                if($q->rowCount()===1){$written++;if(!empty($candidate['prediction_value']))$guard->remember((string)$this->pdo->lastInsertId(),$decision['basis']===null?'unchanged':'rebased',max(0,$originalTarget-$reserved),$delta,$reserved,$decision['basis']??['version'=>ModeledConversionPriceContinuity::VERSION,'reason'=>'no_price_loss_increase'],$now);}
            }
            $q=$this->pdo->prepare("UPDATE modeled_conversion_value_export_settings SET profile_cursor=? WHERE project_key='anytour'");$q->execute([$after]);
            $this->pdo->commit();return $written;
        }catch(Throwable $e){if($this->pdo->inTransaction())$this->pdo->rollBack();throw $e;}
    }

    /** Finite explicitly authorized review; no source replay or frozen-profile rewrite. */
    public function collectHistory(int $now,callable $gate):int
    {
        if($this->pdo->inTransaction())throw new RuntimeException('value_outer_transaction');
        $p=$this->pdo;$mysql=$p->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql';$p->beginTransaction();
        try{
            $s=$p->query("SELECT * FROM modeled_conversion_value_export_settings WHERE project_key='anytour'".($mysql?' FOR UPDATE':''))->fetch(PDO::FETCH_ASSOC);
            if(!$s||$s['mode']!=='send'||$s['goal_id']!=='678187097'||($s['history_from_unix']??null)===null){$p->rollBack();return 0;}
            if(($s['history_completed_at_unix']??null)!==null){
                if((int)$s['history_from_unix']!==1793570400||(int)$s['history_until_unix']!==1793656800
                    ||(int)$s['history_completed_at_unix']>$now||($s['history_fortnight_requested_at_unix']??null)===null
                    ||($s['history_day_receipt_json']??null)!==null){$p->rollBack();return 0;}
                // Preserve the completed first-day receipt before the sole finite expansion.
                $receipt=[];
                foreach(['history_from_unix','history_until_unix','history_cursor','history_ceiling','history_completed_at_unix','history_scanned','history_collected','history_stats_json']as$key)$receipt[$key]=$s[$key];
                $ceiling=(string)$p->query("SELECT COALESCE(MAX(id),0) FROM modeled_conversion_profile_versions WHERE project_key='anytour'")->fetchColumn();
                $q=$p->prepare("UPDATE modeled_conversion_value_export_settings SET history_day_receipt_json=?,history_from_unix=1792447200,history_until_unix=1793656800,history_cursor=0,history_ceiling=?,history_completed_at_unix=NULL,history_scanned=0,history_collected=0,history_stats_json=NULL WHERE project_key='anytour' AND history_day_receipt_json IS NULL");
                $q->execute([json_encode($receipt,JSON_THROW_ON_ERROR),$ceiling]);
                $s['history_from_unix']=1792447200;$s['history_cursor']=0;$s['history_ceiling']=$ceiling;$s['history_stats_json']=null;
            }
            $since=(int)$s['history_from_unix'];$until=(int)$s['history_until_unix'];
            if(!ModeledConversionValueContract::historyWindow($since,$until)||$until>$now)throw new RuntimeException('value_history_window');
            $length=$mysql?'OCTET_LENGTH(profile_json)':'LENGTH(CAST(profile_json AS BLOB))';
            $q=$p->prepare("SELECT id,project_key,platform,customer_key,conversation_id,observed_at_unix,".$length." AS profile_bytes"
                ." FROM modeled_conversion_profile_versions WHERE project_key='anytour' AND id>? AND id<=? AND observed_at_unix>=? AND observed_at_unix<? ORDER BY id LIMIT 40");
            $q->execute([$s['history_cursor'],$s['history_ceiling'],$since,$until]);$rows=$q->fetchAll(PDO::FETCH_ASSOC);
            $after=(string)$s['history_cursor'];$written=0;$scanned=0;$bytes=0;$limited=false;
            $reasons=json_decode((string)($s['history_stats_json']??'{}'),true);
            $keys=['customer_text_profiles','eligible_signal_profiles','invalid_packet','no_day_signal','binding_or_scope_held','already_reserved'];
            $reasons=is_array($reasons)?array_intersect_key($reasons,array_fill_keys($keys,true)):[];
            foreach($keys as$key)if(!is_int($reasons[$key]??null)||$reasons[$key]<0)$reasons[$key]=0;
            foreach($rows as$r){
                $size=(int)$r['profile_bytes'];
                if($size<=131072&&$bytes+$size>2097152){$limited=true;break;}
                $after=(string)$r['id'];$scanned++;
                if($size<1||$size>131072){$reasons['invalid_packet']++;continue;}
                $payload=$p->prepare("SELECT profile_json FROM modeled_conversion_profile_versions WHERE project_key='anytour' AND id=?");
                $payload->execute([$r['id']]);$json=$payload->fetchColumn();
                if(!is_string($json)||strlen($json)!==$size){$reasons['invalid_packet']++;continue;}$bytes+=strlen($json);
                try{$w=json_decode($json,true,64,JSON_THROW_ON_ERROR);}catch(Throwable $ignored){$reasons['invalid_packet']++;continue;}
                if(ModeledConversionValueContract::moment($w['profile']['prediction_at']??null)!==(int)$r['observed_at_unix']){$reasons['invalid_packet']++;continue;}
                $subject=['project_key'=>'anytour','platform'=>$r['platform'],'customer_key'=>(string)$r['customer_key'],'conversation_id'=>(string)$r['conversation_id']];
                foreach($w['profile']['observation_input']['evidence']??[]as$input)if(($input['kind']??null)==='customer_message'){$reasons['customer_text_profiles']++;break;}
                $candidate=ModeledConversionValueContract::historicalCandidate($w,$subject,$since,$until,$now);
                if(!$candidate){$reasons['no_day_signal']++;continue;}$reasons['eligible_signal_profiles']++;
                $binding=$this->binding($r['conversation_id'],$r['platform']);
                if(!$binding||(string)$binding['customer_id']!==(string)$r['customer_key']||!$gate($binding['external_chat_id'])
                    ||!$this->historyScopeAllowed((string)$r['conversation_id'],$r['platform'])){$reasons['binding_or_scope_held']++;continue;}
                $q=$p->prepare("SELECT COALESCE(SUM(value_rub),0) FROM max_channel_conversion_outbox WHERE project_key='anytour' AND conversation_id=? AND transport_target=? AND value_contract=?");
                $q->execute([$r['conversation_id'],ModeledConversionValueContract::TARGET,ModeledConversionValueContract::VERSION]);
                $delta=$candidate['value']-(int)$q->fetchColumn();if($delta<=0){$reasons['already_reserved']++;continue;}
                $insert=$mysql?'INSERT IGNORE':'INSERT OR IGNORE';
                $q=$p->prepare($insert." INTO max_channel_conversion_outbox(project_key,conversation_id,external_user_id,counter_id,target,transport_target,event_unix,platform,identity_source_key,value_rub,value_profile_id,value_stage,value_contract,value_origin,value_reviewed_at_unix)"
                    ." VALUES('anytour',?,?,'98615635',?,?,?,?,?,?,?,?,?,?,?)");
                $q->execute([$r['conversation_id'],$binding['external_user_id'],ModeledConversionValueContract::key($candidate['rank']),
                    ModeledConversionValueContract::TARGET,$candidate['event_unix'],$r['platform'],$r['platform']==='telegram'?'tgmini':'max_tracker',
                    $delta,$r['id'],$candidate['stage'],ModeledConversionValueContract::VERSION,$since===1793570400?'owner_day_review_v1':'owner_fortnight_review_v1',$now]);
                $written+=$q->rowCount()===1?1:0;
            }
            $done=!$limited&&count($rows)<40;
            $q=$p->prepare("UPDATE modeled_conversion_value_export_settings SET history_cursor=?,history_scanned=history_scanned+?,history_collected=history_collected+?,history_completed_at_unix=?,history_stats_json=? WHERE project_key='anytour'");
            $q->execute([$after,$scanned,$written,$done?$now:null,json_encode($reasons,JSON_THROW_ON_ERROR)]);$p->commit();return $written;
        }catch(Throwable $error){if($p->inTransaction())$p->rollBack();throw $error;}
    }
    private function historyScopeAllowed(string $conversation,string $platform):bool
    {
        $q=$this->pdo->prepare("SELECT CASE WHEN JSON_VALID(profile_json) AND ".($this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'?'OCTET_LENGTH(profile_json)':'LENGTH(CAST(profile_json AS BLOB))')."<=131072 THEN profile_json ELSE NULL END FROM modeled_conversion_profile_versions"
            ." WHERE project_key='anytour' AND platform=? AND conversation_id=? ORDER BY id DESC LIMIT 1");
        $q->execute([$platform,$conversation]);$raw=$q->fetchColumn();if(!is_string($raw)||strlen($raw)>131072)return false;
        try{$w=json_decode($raw,true,64,JSON_THROW_ON_ERROR);}catch(Throwable $ignored){return false;}
        if(($w['access_status']??null)!=='allowed'||($w['subject']['project_key']??null)!=='anytour'
            ||($w['subject']['conversation_id']??null)!==$conversation||($w['subject']['platform']??null)!==$platform
            ||($w['semantic_prediction']['features']['explicit_decline']??false)===true)return false;
        foreach($w['contextual_signals']['events']??[]as$event)
            if(in_array($event['goal']??null,['payment_cancellation','booking_cancellation','refund_report','purchased_elsewhere'],true))return false;
        return true;
    }

    private function binding(mixed $id,string $platform):?array
    {
        if(!in_array($platform,['max','telegram'],true))return null;
        $q=$this->pdo->prepare("SELECT c.customer_id,c.external_chat_id,cc.external_user_id,cc.external_chat_id AS user_chat FROM conversations c JOIN customer_channels cc ON cc.id=c.customer_channel_id AND cc.customer_id=c.customer_id AND cc.project_key=c.project_key AND cc.channel=c.channel WHERE c.id=? AND c.project_key='anytour' AND c.channel=? AND c.is_test=0 AND NOT ".InternalOwnerDialogueScope::conversationSql('c'));
        $q->execute([$id,$platform]);$r=$q->fetch(PDO::FETCH_ASSOC);
        if(!$r||!MaxTrackerLookupProtocol::id((string)$r['external_user_id']))return null;
        $chat=$platform==='max'?'-'.$r['external_user_id']:$r['external_user_id'];
        return (string)$r['external_chat_id']===$chat&&(string)$r['user_chat']===$chat?$r:null;
    }
    private function eligible(array $r,callable $gate):bool
    {
        if(($r['project_key']??null)!=='anytour'||($r['counter_id']??null)!=='98615635'
            ||!ModeledConversionValueContract::isKey($r['target']??null)||($r['transport_target']??null)!==ModeledConversionValueContract::TARGET
            ||($r['value_contract']??null)!==ModeledConversionValueContract::VERSION
            ||($r['identity_source_key']??null)!==(($r['platform']??null)==='telegram'?'tgmini':'max_tracker'))return false;
        if(in_array($r['value_origin']??null,['owner_day_review_v1','owner_fortnight_review_v1'],true)&&!$this->historyScopeAllowed((string)$r['conversation_id'],$r['platform']))return false;
        $b=$this->binding($r['conversation_id'],$r['platform']);
        if(!$b||(string)$b['external_user_id']!==(string)$r['external_user_id']||!$gate($b['external_chat_id']))return false;
        $safe="CASE WHEN JSON_VALID(profile_json) THEN profile_json ELSE '{}' END";
        $expr="JSON_EXTRACT(".$safe.",'$.access_status')";if($this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql')$expr='JSON_UNQUOTE('.$expr.')';
        $q=$this->pdo->prepare("SELECT ".$expr." FROM modeled_conversion_profile_versions WHERE project_key='anytour' AND platform=? AND conversation_id=? ORDER BY id DESC LIMIT 1");
        $q->execute([$r['platform'],$r['conversation_id']]);return $q->fetchColumn()==='allowed';
    }
    private function rows(string $state,int $now,int $limit,array $excluded=[]):array
    {
        $excluded=in_array($state,['pending','ready'],true)?array_values(array_unique(array_map('strval',$excluded))):[];
        if(count($excluded)>3)throw new RuntimeException('finite_source_bound');
        foreach($excluded as$id)if(preg_match('/\A[1-9][0-9]{0,19}\z/D',$id)!==1)throw new RuntimeException('finite_source_bound');
        $skip=$excluded?' AND id NOT IN ('.implode(',',array_fill(0,count($excluded),'?')).')':'';
        $q=$this->pdo->prepare("SELECT * FROM max_channel_conversion_outbox WHERE project_key='anytour' AND counter_id='98615635' AND state=? AND transport_target=? AND value_contract=? AND next_attempt_unix<=?".$skip." ORDER BY next_attempt_unix,id LIMIT ".min($state==='pending'?MaxTrackerLookupProtocol::MAX_USERS:5,max(1,$limit)));
        $q->execute([$state,ModeledConversionValueContract::TARGET,ModeledConversionValueContract::VERSION,$now,...$excluded]);return$q->fetchAll(PDO::FETCH_ASSOC);
    }
    private function resolve(array $r,array $identities,int $now,callable $gate,bool $lookupReturned=false):bool
    {
        if(!$this->eligible($r,$gate)){self::failRow($this->pdo,$r,'scope_unavailable');return false;}
        $client=null;$record=null;$observation=null;$identityReason='identity_not_found';$pairSource=false;
        if($r['platform']==='max'){
            $record=$identities[$r['external_user_id']]??null;
            // A retained MAX click can outlive the provider's attribution window.
            // Hold unresolved identities without rewriting any accepted receipt or forecast.
            $observed=is_array($record)?($record['observed_at_unix']??null):null;
            $pairMember=$this->fixedPairMember($r,$now)!==null;
            if($pairMember)$pairSource=$this->fixedPairCandidate($r,$identities,$lookupReturned,$now);
            if(is_array($record)&&($record['external_user_id']??null)===$r['external_user_id']
                &&is_int($observed)&&$observed>=946684800&&$observed<=(int)$r['event_unix']
                &&($pairMember?$pairSource:((int)$r['event_unix']-$observed<=ModeledConversionAttributionWindow::MAX_SECONDS&&$now-$observed<=ModeledConversionAttributionWindow::MAX_SECONDS)))$client=$record['client_id']??null;
        }else{
            $q=$this->pdo->prepare("SELECT client_id,MIN(id) AS observation_id FROM advertising_visit_observations WHERE project_key='anytour' AND source_key='tgmini' AND platform='telegram' AND counter_id='98615635' AND external_user_id=? AND identity_status='verified' AND client_id<>'' AND visit_at_unix<=? AND observed_at_unix<=? AND received_at_unix<=? GROUP BY client_id LIMIT 2");
            $q->execute([$r['external_user_id'],$r['event_unix'],$now,$now]);$ids=$q->fetchAll(PDO::FETCH_ASSOC);if(count($ids)>1)$identityReason='identity_ambiguous';if(count($ids)===1){$client=$ids[0]['client_id'];$observation=$ids[0]['observation_id'];}
        }
        if(!MaxTrackerLookupProtocol::id($client,40)){
            $q=$this->pdo->prepare("UPDATE max_channel_conversion_outbox SET next_attempt_unix=?,last_error=? WHERE id=? AND state='pending'");$q->execute([$now+300,$identityReason,$r['id']]);
            if($q->rowCount()===1)$this->observeIdentity($r,$identities,$lookupReturned,$now);
            return false;
        }
        [$pairWhere,$pairParams]=$pairSource?$this->fixedPairSqlGuard($r,true):['',[]];
        $q=$this->pdo->prepare("UPDATE max_channel_conversion_outbox SET client_id=?,tracker_record_id=?,tracker_observed_unix=?,identity_observation_id=?,state='ready',value_ready_at_unix=COALESCE(value_ready_at_unix,?),next_attempt_unix=0,last_error='' WHERE id=? AND state='pending'".$pairWhere);$q->execute([$client,$record['record_id']??null,$record['observed_at_unix']??null,$observation,$now,$r['id'],...$pairParams]);
        $changed=$q->rowCount()===1;if($changed)$this->observeIdentity($r,$identities,$lookupReturned,$now,$pairSource);return $changed;
    }

    /** Fixed earliest repair increments only, without loading profile or native payloads. */
    private function prepareIdentityEvidence():void
    {
        try{
            $this->identityNamespace=ModeledConversionProfiles::assessmentNamespace();
            $mysql=$this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql';
            $eq=static fn(string $a,string $b):string=>$mysql?'BINARY '.$a.'=BINARY '.$b:'('.$a.') COLLATE BINARY=('.$b.') COLLATE BINARY';
            $key=$mysql?"CONCAT('anytour_value_pred_',p.id,'_v1')":"'anytour_value_pred_'||p.id||'_v1'";
            $fields=['id','value_profile_id','project_key','platform','conversation_id','external_user_id','counter_id',
                'target','transport_target','value_contract','event_unix','value_rub'];
            $sql='SELECT '.($mysql?'/*+ MAX_EXECUTION_TIME(250) */ ':'').implode(',',array_map(static fn(string $v):string=>'o.'.$v,$fields))
                .' FROM modeled_conversion_profile_versions p JOIN max_channel_conversion_outbox o ON o.value_profile_id=p.id'
                .' AND o.project_key=p.project_key AND o.conversation_id=p.conversation_id AND o.target='.$key
                .' AND '.$eq('o.project_key','p.project_key').' AND '.$eq('o.platform','p.platform')
                .' AND '.$eq('o.conversation_id','p.conversation_id').' AND '.$eq('o.target',$key)
                ." WHERE p.project_key='anytour' AND p.assessment_namespace=? AND p.assessment_kind='repair'"
                ." AND p.platform IN ('max','telegram') AND o.counter_id='98615635'"
                .' AND '.$eq('o.transport_target','?').' AND '.$eq('o.value_contract','?')
                .' AND o.value_rub>0 AND o.value_rub<='.ModeledConversionValueContract::MAX_VALUE_RUB.' ORDER BY o.id LIMIT 17';
            $q=$this->pdo->prepare($sql);$q->execute([$this->identityNamespace,ModeledConversionValueContract::TARGET,ModeledConversionValueContract::VERSION]);
            $rows=$q->fetchAll(PDO::FETCH_ASSOC);$q->closeCursor();
            $this->identityComplete=count($rows)<=ModeledConversionValueIdentityEvidence::MAX_ROWS;
            foreach(array_slice($rows,0,ModeledConversionValueIdentityEvidence::MAX_ROWS)as$row){
                $ref=ModeledConversionValueIdentityEvidence::reference($row,$this->identityNamespace);
                if($ref===null){$this->identityComplete=false;continue;}
                $this->identityRefs[$ref]=true;
            }
            $this->identityCohortAvailable=true;
        }catch(Throwable $ignored){$this->identityComplete=false;}
    }

    /** Evidence follows the existing successful state update; never changes its result. */
    private function observeIdentity(array $row,array $identities,bool $lookupReturned,int $now,bool $fixedPairResolved=false):void
    {
        try{
            if(!$lookupReturned||($row['platform']??null)!=='max'||$this->identityNamespace===null)return;
            $ref=ModeledConversionValueIdentityEvidence::reference($row,$this->identityNamespace);
            if($ref===null||!isset($this->identityRefs[$ref]))return;
            $present=array_key_exists($row['external_user_id'],$identities);
            [$reason,$observed]=ModeledConversionValueIdentityEvidence::classifyMax($row,$identities[$row['external_user_id']]??null,$present,$now);
            $this->identityObservations[$ref]=[$ref,$now,$fixedPairResolved?7:$reason,$observed];
        }catch(Throwable $ignored){$this->identityComplete=false;}
    }

    /** Retain prior observations by immutable ref; delayed ticks cannot replace later checks. */
    private function identityEvidence(mixed $previous,int $now):?array
    {
        if($this->identityNamespace===null)return null;
        $prior=ModeledConversionValueIdentityEvidence::normalize($previous,$this->identityNamespace,$now);
        if(!$this->identityCohortAvailable&&$prior===null&&!$this->identityObservations)return null;
        $values=[];
        foreach($prior['rows']??[]as$row)$values[$row['event_ref']]=[$row['event_ref'],$row['checked_at_unix'],$row['reason_code'],$row['source_observed_unix']];
        foreach($this->identityObservations as$ref=>$row)if(!isset($values[$ref])||$row[1]>$values[$ref][1])$values[$ref]=$row;
        $refs=$this->identityCohortAvailable?array_keys($this->identityRefs):array_keys($values);
        $rows=[];foreach(array_slice($refs,0,ModeledConversionValueIdentityEvidence::MAX_ROWS)as$ref)if(isset($values[$ref]))$rows[]=$values[$ref];
        $audit=['v'=>ModeledConversionValueIdentityEvidence::VERSION,'n'=>$this->identityNamespace,
            'c'=>$this->identityCohortAvailable&&$this->identityComplete,'r'=>$rows];
        while(strlen(json_encode($audit,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR))>ModeledConversionValueIdentityEvidence::MAX_BYTES&&$audit['r']){
            array_pop($audit['r']);$audit['c']=false;
        }
        return $audit;
    }

    /** Optional evidence yields first; original counts, clocks and failure retention keep priority. */
    private function withIdentityEvidence(array $receipt,?array $audit,bool $mysql):array
    {
        if($audit===null)return $receipt;
        $base=$receipt;unset($base['identity_resolution_evidence'],$base['identity_resolution_evidence_incomplete']);
        $receipt=$base;$receipt['identity_resolution_evidence']=$audit;
        try{while(!$this->receiptFits(json_encode($receipt,JSON_THROW_ON_ERROR),$mysql)){
            if($receipt['identity_resolution_evidence']['r']){
                array_pop($receipt['identity_resolution_evidence']['r']);
                $receipt['identity_resolution_evidence']['c']=false;
            }else{
                $base['identity_resolution_evidence_incomplete']=true;
                if($this->receiptFits(json_encode($base,JSON_THROW_ON_ERROR),$mysql))return $base;
                unset($base['identity_resolution_evidence_incomplete']);return $base;
            }
        }
        return $receipt;}catch(Throwable $ignored){return $base;}
    }


    private function finiteEligible(array $row,callable $gate):bool
    {try{return $this->eligible($row,$gate);}catch(Throwable $ignored){return false;}}

    /** Exact finite checkpoint cohort. No source text, identity replacement or monetary classification. */
    private function finiteSelection(string $namespace):array
    {
        $mysql=$this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql';
        $eq=static fn(string $a,string $b):string=>$mysql?'BINARY '.$a.'=BINARY '.$b:'('.$a.') COLLATE BINARY=('.$b.') COLLATE BINARY';
        $key=$mysql?"CONCAT('anytour_value_pred_',p.id,'_v1')":"'anytour_value_pred_'||p.id||'_v1'";
        $fields=['id','value_profile_id','project_key','platform','conversation_id','external_user_id','counter_id',
            'target','transport_target','value_contract','event_unix','value_rub','state','upload_id','upload_status',
            'identity_source_key','value_origin','next_attempt_unix','value_ready_at_unix','value_api_accepted_at_unix',
            'client_id','tracker_record_id','tracker_observed_unix','attempts','claim_token','claimed_at_unix','value_processed_at_unix','value_receipt_basis','last_error'];
        $sql='SELECT '.($mysql?'/*+ MAX_EXECUTION_TIME(250) */ ':'').implode(',',array_map(static fn(string $v):string=>'o.'.$v,$fields))
            .' FROM modeled_conversion_profile_versions p JOIN max_channel_conversion_outbox o ON o.value_profile_id=p.id'
            .' AND o.project_key=p.project_key AND o.conversation_id=p.conversation_id AND o.target='.$key
            .' AND '.$eq('o.project_key','p.project_key').' AND '.$eq('o.platform','p.platform')
            .' AND '.$eq('o.conversation_id','p.conversation_id').' AND '.$eq('o.target',$key)
            ." WHERE p.project_key='anytour' AND p.assessment_namespace=? AND p.assessment_kind='repair'"
            ." AND p.platform IN ('max','telegram') AND o.counter_id='98615635'"
            .' AND '.$eq('o.transport_target','?').' AND '.$eq('o.value_contract','?')
            .' AND o.value_rub>0 AND o.value_rub<='.ModeledConversionValueContract::MAX_VALUE_RUB
            .' AND p.observed_at_unix>=946684800 AND p.observed_at_unix<=? AND o.event_unix>=946684800 AND o.event_unix<=?'
            .' AND (o.value_ready_at_unix IS NULL OR o.value_ready_at_unix>?)'
            .' AND (o.value_api_accepted_at_unix IS NULL OR o.value_api_accepted_at_unix>?) ORDER BY o.id LIMIT 4';
        $at=ModeledConversionValueVisitEvidence::CHECKPOINT;
        $q=$this->pdo->prepare($sql);$q->execute([$namespace,ModeledConversionValueContract::TARGET,ModeledConversionValueContract::VERSION,$at,$at,$at,$at]);
        $rows=$q->fetchAll(PDO::FETCH_ASSOC);$q->closeCursor();
        if(count($rows)!==3)return [];
        $refs=[];$ids=[];
        foreach($rows as$row){
            $ref=ModeledConversionValueIdentityEvidence::reference($row,$namespace);
            if($ref===null||isset($refs[$ref])||isset($ids[(string)$row['id']]))return [];
            $refs[$ref]=$row;$ids[(string)$row['id']]=true;
        }
        return $refs;
    }

    /** Pin once under the existing settings lock. A malformed or consumed saved key is never repinned. */
    private function prepareFiniteEvidence(int $now,callable $gate):array
    {
        if($now<ModeledConversionValueVisitEvidence::CHECKPOINT)return [];
        $p=$this->pdo;
        try{
            if($p->inTransaction())return [];
            $namespace=$this->identityNamespace??ModeledConversionProfiles::assessmentNamespace();
            $rows=$this->finiteSelection($namespace);if(count($rows)!==3)return [];
            $p->beginTransaction();$mysql=$p->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql';
            $q=$p->query("SELECT counter_id,mode,goal_id,goal_intent,contract_version,last_receipt_json FROM modeled_conversion_value_export_settings WHERE project_key='anytour'".($mysql?' FOR UPDATE':''));
            $settings=$q->fetch(PDO::FETCH_ASSOC);
            if(!$settings||$settings['counter_id']!=='98615635'||$settings['mode']!=='send'||$settings['goal_id']!=='678187097'
                ||$settings['goal_intent']!=='verified'||$settings['contract_version']!==ModeledConversionValueContract::VERSION){$p->rollBack();return [];}
            $raw=$settings['last_receipt_json'];
            if($raw===null)$receipt=[];
            elseif(is_string($raw)&&strlen($raw)<=8192&&str_starts_with(ltrim($raw),'{'))$receipt=json_decode($raw,true,32,JSON_THROW_ON_ERROR);
            else{$p->rollBack();return [];}
            if(!is_array($receipt)){$p->rollBack();return [];}
            if(!array_key_exists('finite_visit_evidence',$receipt)){
                $frame=['v'=>ModeledConversionValueVisitEvidence::VERSION,'n'=>$namespace,'b'=>ModeledConversionValueVisitEvidence::CHECKPOINT,'r'=>[]];
                foreach($rows as$ref=>$row)$frame['r'][]=[$ref,(string)$row['id'],0,null,null,0,null,null,null,null,0,null,0,null,0,(int)$row['event_unix']];
                $receipt['finite_visit_evidence']=$frame;$encoded=json_encode($receipt,JSON_THROW_ON_ERROR);
                if(!$this->receiptFits($encoded,$mysql)){$p->rollBack();return [];}
                $q=$p->prepare("UPDATE modeled_conversion_value_export_settings SET last_receipt_json=? WHERE project_key='anytour'");$q->execute([$encoded]);
            }
            $frame=ModeledConversionValueVisitEvidence::decode($receipt['finite_visit_evidence'],$namespace,$now);
            $p->commit();if($frame===null)return [];
            foreach($frame['r']as$r)if(!isset($rows[$r[0]])||(string)$rows[$r[0]]['id']!==$r[1]||(int)$rows[$r[0]]['event_unix']!==$r[15])return [];
            $this->finiteFrame=$frame;$this->finiteRows=$rows;
            $this->counterScopeConsumed=array_key_exists(ModeledConversionMaxCounterScopeEvidence::RECEIPT_KEY,$receipt);
            $this->retainedAppConsumed=array_key_exists(ModeledConversionRetainedAppIdentityEvidence::RECEIPT_KEY,$receipt);
            $this->retainedTrackerConsumed=array_key_exists(ModeledConversionRetainedTrackerIdentityEvidence::RECEIPT_KEY,$receipt);
            $this->counterScopeLedger=$this->counterScopeBindingRow($now);
            if($this->counterScopeConsumed&&!$this->retainedTrackerConsumed&&$this->counterScopeLedger!==null){
                $app=ModeledConversionRetainedAppIdentityEvidence::projection($receipt[ModeledConversionRetainedAppIdentityEvidence::RECEIPT_KEY]??null,$frame,$this->counterScopeLedger,$namespace,$now);
                $this->retainedTrackerSourceUnsupported=($app['available']??false)===true&&($app['status']??null)==='unsupported_source'&&strlen($raw)<=8192-1024;
            }
            if($this->retainedTrackerConsumed&&is_string($raw)&&strlen($raw)<=8192-1024){
                foreach($rows as$row){
                    $b=ModeledConversionRetainedTrackerIdentityEvidence::knownBinding($frame,$row,$namespace,$now);
                    if($b!==null&&!array_key_exists($b['key'],$receipt)
                        &&ModeledConversionRetainedTrackerIdentityEvidence::knownIntent($frame,$row,$namespace,str_repeat('0',64),$now,$receipt[ModeledConversionFixedPairDecisionEvidence::RECEIPT_KEY]??null)!==null)
                        $this->retainedKnownCandidates[]=['row'=>$row,'key'=>$b['key']];
                }
            }
            $this->fixedPairJournal=ModeledConversionValueVisitRecovery::decode($receipt['finite_visit_recovery']??null,$frame,$namespace,$now);
            $pending=[];foreach($frame['r']as$r)if($r[2]===0)$pending[]=$rows[$r[0]];
            return $pending;
        }catch(Throwable $ignored){if($p->inTransaction())$p->rollBack();return [];}
    }

    /** Only the one original missing identity; this selection never appends a user to the ordinary batch. */
    private function counterScopeBindingRow(int $now):?array
    {
        if($this->finiteFrame===null)return null;$missing=[];
        foreach($this->finiteFrame['r']as$old)if($old[2]===2&&$old[5]===3)$missing[]=$old;
        if(count($missing)!==1)return null;$old=$missing[0];$row=$this->finiteRows[$old[0]]??null;
        if(!is_array($row)||ModeledConversionValueIdentityEvidence::reference($row,$this->finiteFrame['n'])!==$old[0]
            ||(string)$row['id']!==$old[1]||(int)$row['event_unix']!==$old[15])return null;
        return $row;
    }

    private function counterScopeAdmissible(array $row,int $now):bool
    {
        if(($row['project_key']??null)!=='anytour'||($row['platform']??null)!=='max'||($row['counter_id']??null)!=='98615635'
            ||($row['identity_source_key']??null)!=='max_tracker'||($row['state']??null)!=='pending'
            ||(!is_int($row['attempts']??null)&&!is_string($row['attempts']??null))||(string)$row['attempts']!=='0')return false;
        foreach(['value_ready_at_unix','claimed_at_unix','value_api_accepted_at_unix','value_processed_at_unix']as$key)
            if(!array_key_exists($key,$row)||$row[$key]!==null)return false;
        foreach(['client_id','tracker_record_id','tracker_observed_unix','claim_token','upload_id','upload_status','value_receipt_basis']as$key)
            if(!array_key_exists($key,$row)||!in_array($row[$key],[null,''],true))return false;
        $due=$row['next_attempt_unix']??null;
        return (is_int($due)||is_string($due))&&preg_match('/\A(?:0|[1-9][0-9]*)\z/D',(string)$due)===1&&(int)$due<=$now;
    }

    private function counterScopeCandidate(array $ordinaryUsers,int $now):?array
    {
        $row=$this->counterScopeLedger;
        if($this->counterScopeConsumed||$row===null||!$this->counterScopeAdmissible($row,$now)
            ||!in_array($row['external_user_id'],$ordinaryUsers,true))return null;
        return $row;
    }

    /** ABSENT-key intent commits before the client may send its optional header. No lock is held over HTTP. */
    private function beginCounterScope(array $selected,string $requestHash,callable $gate,int $now):bool
    {
        $p=$this->pdo;
        try{
            if($p->inTransaction()||$this->finiteFrame===null||$this->counterScopeConsumed)return false;
            $p->beginTransaction();$mysql=$p->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql';$lock=$mysql?' FOR UPDATE':'';
            $s=$p->query("SELECT counter_id,mode,goal_id,goal_intent,contract_version,last_receipt_json FROM modeled_conversion_value_export_settings WHERE project_key='anytour'".$lock)->fetch(PDO::FETCH_ASSOC);
            $raw=$s['last_receipt_json']??null;
            if(!$s||$s['counter_id']!=='98615635'||$s['mode']!=='send'||$s['goal_id']!=='678187097'||$s['goal_intent']!=='verified'
                ||$s['contract_version']!==ModeledConversionValueContract::VERSION||!is_string($raw)||strlen($raw)>8192||!str_starts_with(ltrim($raw),'{')){$p->rollBack();return false;}
            $receipt=json_decode($raw,true,32,JSON_THROW_ON_ERROR);$key=ModeledConversionMaxCounterScopeEvidence::RECEIPT_KEY;
            if(!is_array($receipt)||array_key_exists($key,$receipt)){$p->rollBack();return false;}
            $ns=$this->finiteFrame['n'];$current=$this->finiteSelection($ns);
            $ref=ModeledConversionValueIdentityEvidence::reference($selected,$ns);$row=$current[$ref??'']??null;
            $original=$receipt['finite_visit_evidence']??null;
            if(!$row||!$this->counterScopeAdmissible($row,$now)||!$this->eligible($row,$gate)
                ||ModeledConversionValueIdentityEvidence::reference($row,$ns)!==ModeledConversionValueIdentityEvidence::reference($selected,$ns)
                ||ModeledConversionValueVisitRecovery::originalDigest($original,$ns,$now)!==ModeledConversionValueVisitRecovery::originalDigest($this->finiteFrame,$ns,$now)){$p->rollBack();return false;}
            $intent=ModeledConversionMaxCounterScopeEvidence::intent($original,$row,$ns,$requestHash,$now);
            if($intent===null){$p->rollBack();return false;}
            $receipt[$key]=$intent;$encoded=json_encode($receipt,JSON_THROW_ON_ERROR);
            if(!$this->receiptFits($encoded,$mysql)){$p->rollBack();return false;}
            $q=$p->prepare("UPDATE modeled_conversion_value_export_settings SET last_receipt_json=? WHERE project_key='anytour'");$q->execute([$encoded]);
            if($q->rowCount()!==1){$p->rollBack();return false;}$p->commit();
            $this->counterScopeConsumed=true;$this->counterScopeIntent=$intent;$this->counterScopeLedger=$row;
            return true;
        }catch(Throwable $ignored){if($p->inTransaction())$p->rollBack();return false;}
    }

    /** Only the one same-response packet may finish; errors keep the durable consumed-UNKNOWN intent. */
    private function finishCounterScope(mixed $evidence,int $now):void
    {
        if($this->counterScopeIntent===null||$this->counterScopeLedger===null||$this->finiteFrame===null)return;$p=$this->pdo;
        try{
            if($p->inTransaction())return;$p->beginTransaction();$mysql=$p->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql';$lock=$mysql?' FOR UPDATE':'';
            $settings=$p->query("SELECT counter_id,mode,goal_id,goal_intent,contract_version,last_receipt_json FROM modeled_conversion_value_export_settings WHERE project_key='anytour'".$lock)->fetch(PDO::FETCH_ASSOC);
            $raw=$settings['last_receipt_json']??null;
            if(!$settings||$settings['counter_id']!=='98615635'||$settings['mode']!=='send'||$settings['goal_id']!=='678187097'
                ||$settings['goal_intent']!=='verified'||$settings['contract_version']!==ModeledConversionValueContract::VERSION
                ||!is_string($raw)||strlen($raw)>8192||!str_starts_with(ltrim($raw),'{')){$p->rollBack();return;}
            $receipt=json_decode($raw,true,32,JSON_THROW_ON_ERROR);$key=ModeledConversionMaxCounterScopeEvidence::RECEIPT_KEY;$ns=$this->finiteFrame['n'];
            $current=$this->finiteSelection($ns);$ref=ModeledConversionValueIdentityEvidence::reference($this->counterScopeLedger,$ns);$row=$current[$ref??'']??null;
            if(!is_array($receipt)||!array_key_exists($key,$receipt)||!$row
                ||ModeledConversionValueIdentityEvidence::reference($row,$ns)!==ModeledConversionValueIdentityEvidence::reference($this->counterScopeLedger,$ns)){$p->rollBack();return;}
            $completed=ModeledConversionMaxCounterScopeEvidence::complete($receipt[$key],$receipt['finite_visit_evidence']??null,$row,$ns,$evidence,$now);
            if($completed===null){$p->rollBack();return;}$receipt[$key]=$completed;$encoded=json_encode($receipt,JSON_THROW_ON_ERROR);
            if(!$this->receiptFits($encoded,$mysql)){$p->rollBack();return;}
            $q=$p->prepare("UPDATE modeled_conversion_value_export_settings SET last_receipt_json=? WHERE project_key='anytour'");$q->execute([$encoded]);$p->commit();
        }catch(Throwable $ignored){if($p->inTransaction())$p->rollBack();}
    }

    /** Only the already executed ordinary decision is recorded; a positive visit gate is not an API receipt. */
    private function observeFixedPairDecision(array $row,int $tick,int $checked,int $code,?array $window=null):void
    {
        if($this->retainedKnownReservation!==null&&(string)$row['id']===(string)$this->retainedKnownReservation['row']['id'])return;
        try{
            $old=$this->fixedPairMember($row,$checked);if($old===null||$this->finiteFrame===null)return;
            $visit=$window['visit_at']??null;$class=$visit===null?0:(($window['source_class']??null)==='yandex_direct'?1:2);
            $tuple=[$old[0],$tick,$checked,$code,$visit,$class];
            if(ModeledConversionFixedPairDecisionEvidence::merge(null,$this->finiteFrame,$this->finiteFrame['n'],[$tuple],$checked)!==null)
                $this->fixedPairDecisions[$old[0]]=$tuple;
        }catch(Throwable $ignored){ /* Optional same-response metadata cannot change a financial decision. */ }
    }

    /** The already consumed two canonical cases get no new diagnostic epoch or request. */
    private function prepareFixedPair(int $now):array
    {
        $this->fixedPairCases=[];$this->fixedPairDigest=null;
        if($this->finiteFrame===null||$this->fixedPairJournal===null)return [];
        $ns=$this->finiteFrame['n'];$cases=ModeledConversionValueVisitRecovery::originals($this->finiteFrame,$ns,$now);
        $digest=ModeledConversionValueVisitRecovery::originalDigest($this->finiteFrame,$ns,$now);
        $journal=ModeledConversionValueVisitRecovery::decode($this->fixedPairJournal,$this->finiteFrame,$ns,$now);
        if($cases===null||$digest===null||$journal===null||$journal['d']!==$digest)return [];
        foreach($cases as$old)if($old[5]!==1)return [];
        foreach($journal['r']as$entry)if($entry[2]!==2||$entry[5]!==2||$entry[10]!==1)return [];
        $this->fixedPairCases=$cases;$this->fixedPairDigest=$digest;$due=[];
        foreach($cases as$ref=>$old){
            $row=$this->finiteRows[$ref]??null;
            if(!is_array($row)||$this->fixedPairRow($row,$now)===null)continue;
            $at=$row['next_attempt_unix']??null;
            if((is_int($at)||is_string($at))&&preg_match('/\A(?:0|[1-9][0-9]*)\z/D',(string)$at)===1&&(int)$at<=$now)$due[]=$row;
        }
        return $due;
    }

    /** Membership survives a changed ready identity; such a member cannot fall back to a different young source. */
    private function fixedPairMember(array $row,int $now):?array
    {
        if($this->finiteFrame===null||$this->fixedPairDigest===null
            ||ModeledConversionValueVisitRecovery::originalDigest($this->finiteFrame,$this->finiteFrame['n'],$now)!==$this->fixedPairDigest)return null;
        $ref=ModeledConversionValueIdentityEvidence::reference($row,$this->finiteFrame['n']);
        return $this->fixedPairCases[$ref??'']??null;
    }

    /** Exact source and first-send or durably known nonacceptance states only; UNKNOWN is never ready. */
    private function fixedPairRow(array $row,int $now):?array
    {
        $old=$this->fixedPairMember($row,$now);
        $attempts=$row['attempts']??null;
        if(!array_key_exists('claimed_at_unix',$row))return null;
        if($old===null||($row['platform']??null)!=='max'||($row['identity_source_key']??null)!=='max_tracker'
            ||(string)($row['id']??'')!==$old[1]||(int)($row['event_unix']??0)!==$old[15]
            ||(!is_int($attempts)&&!is_string($attempts))||preg_match('/\A(?:0|[1-9][0-9]{0,18})\z/D',(string)$attempts)!==1
            ||(string)(int)$attempts!==(string)$attempts)return null;
        foreach(['claim_token','upload_id','upload_status','value_receipt_basis']as$key)if(!array_key_exists($key,$row)||!in_array($row[$key],[null,''],true))return null;
        foreach(['value_api_accepted_at_unix','value_processed_at_unix']as$key)if(!array_key_exists($key,$row)||$row[$key]!==null)return null;
        if(($row['state']??null)==='pending'){
            if((int)$attempts!==0||($row['claimed_at_unix']??null)!==null)return null;
            foreach(['value_ready_at_unix','client_id','tracker_record_id','tracker_observed_unix']as$key)if(!array_key_exists($key,$row)||$row[$key]!==null)return null;
        }elseif(($row['state']??null)==='ready'){
            $ready=$row['value_ready_at_unix']??null;
            if((!is_int($ready)&&!is_string($ready))||preg_match('/\A[1-9][0-9]{0,11}\z/D',(string)$ready)!==1
                ||(int)$ready<$old[15]||(int)$ready>$now||($row['client_id']??null)!==$old[6]
                ||($row['tracker_record_id']??null)!==$old[7]
                ||(!is_int($row['tracker_observed_unix']??null)&&!is_string($row['tracker_observed_unix']??null))
                ||(string)$row['tracker_observed_unix']!==(string)$old[8])return null;
            $claimed=$row['claimed_at_unix']??null;
            if((int)$attempts===0){if($claimed!==null)return null;}
            else{
                if((!is_int($claimed)&&!is_string($claimed))||preg_match('/\A[1-9][0-9]{0,11}\z/D',(string)$claimed)!==1
                    ||(int)$claimed<(int)$ready||(int)$claimed>$now
                    ||!in_array($row['last_error']??null,['transport_not_sent','rate_limited','identity_not_found','metrika_visit_unverified','metrika_visit_window','wire_key_collision'],true))return null;
            }
        }else return null;
        return $old;
    }

    /** Source age is not visit age. Only the exact current canonical record may use this exception. */
    private function fixedPairCandidate(array $row,array $identities,bool $returned,int $now):bool
    {
        $old=$this->fixedPairRow($row,$now);if($old===null||!$returned)return false;
        $record=$identities[$row['external_user_id']]??null;
        return is_array($record)&&($record['external_user_id']??null)===$row['external_user_id']
            &&($record['client_id']??null)===$old[6]&&($record['record_id']??null)===$old[7]
            &&is_int($record['observed_at_unix']??null)&&$record['observed_at_unix']===$old[8]
            &&$old[8]<=$old[15]&&$old[15]<=$now
            &&ModeledConversionValueVisitEvidence::candidateReference($old[0],$record['client_id'],$record['record_id'],$record['observed_at_unix'],'canonical_max_lookup')===$old[9];
    }

    /** Reuse the existing UPDATE/claim with an exact first-send or known-nonacceptance CAS for this pair only. */
    private function fixedPairSqlGuard(array $row,bool $pending):array
    {
        $mysql=$this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql';$where='';$values=[];
        foreach(['project_key','platform','external_user_id','counter_id','target','transport_target','value_contract','identity_source_key']as$key){
            $where.=$mysql?' AND BINARY '.$key.'=BINARY ?':' AND '.$key.' COLLATE BINARY=? COLLATE BINARY';$values[]=$row[$key];
        }
        foreach(['conversation_id','value_profile_id','event_unix','value_rub']as$key){$where.=' AND '.$key.'=?';$values[]=$row[$key];}
        $where.=" AND (claim_token IS NULL OR claim_token='')"
            ." AND (upload_id IS NULL OR upload_id='') AND (upload_status IS NULL OR upload_status='')"
            ." AND value_api_accepted_at_unix IS NULL AND value_processed_at_unix IS NULL AND (value_receipt_basis IS NULL OR value_receipt_basis='')";
        if($pending)$where.=' AND attempts=0 AND claimed_at_unix IS NULL AND value_ready_at_unix IS NULL AND client_id IS NULL AND tracker_record_id IS NULL AND tracker_observed_unix IS NULL';
        else{
            $where.=' AND attempts=?';$values[]=$row['attempts'];
            if($row['claimed_at_unix']===null)$where.=' AND claimed_at_unix IS NULL';
            else{$where.=' AND claimed_at_unix=?';$values[]=$row['claimed_at_unix'];}
            $where.=$mysql?' AND BINARY last_error=BINARY ?':' AND last_error COLLATE BINARY=? COLLATE BINARY';$values[]=$row['last_error'];
        }
        if(!$pending)foreach(['value_ready_at_unix','client_id','tracker_record_id','tracker_observed_unix']as$key){
            $where.=$mysql?' AND BINARY '.$key.'=BINARY ?':' AND '.$key.' COLLATE BINARY=? COLLATE BINARY';$values[]=$row[$key];
        }
        return [$where,$values];
    }

    /** One per-entry CAS within the existing JSON/settings lock. Never locks during network work. */
    private function finiteSave(array $replacement,int $expectedState,?string $candidate,int $now):bool
    {
        $p=$this->pdo;
        try{
            if($p->inTransaction()||$this->finiteFrame===null)return false;
            $p->beginTransaction();$mysql=$p->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql';
            $q=$p->query("SELECT counter_id,mode,goal_id,goal_intent,contract_version,last_receipt_json FROM modeled_conversion_value_export_settings WHERE project_key='anytour'".($mysql?' FOR UPDATE':''));
            $settings=$q->fetch(PDO::FETCH_ASSOC);
            if(!$settings||$settings['counter_id']!=='98615635'||$settings['mode']!=='send'||$settings['goal_id']!=='678187097'
                ||$settings['goal_intent']!=='verified'||$settings['contract_version']!==ModeledConversionValueContract::VERSION){$p->rollBack();return false;}
            $raw=$settings['last_receipt_json'];
            $receipt=is_string($raw)&&strlen($raw)<=8192?json_decode($raw,true,32,JSON_THROW_ON_ERROR):null;
            $frame=is_array($receipt)?ModeledConversionValueVisitEvidence::decode($receipt['finite_visit_evidence']??null,$this->finiteFrame['n'],$now):null;
            if($frame===null){$p->rollBack();return false;}$changed=false;
            foreach($frame['r']as$i=>$r)if($r[0]===$replacement[0]){
                if($r[1]!==$replacement[1]||$r[15]!==$replacement[15]||$r[2]!==$expectedState
                    ||($candidate!==null&&$r[9]!==$candidate)){$p->rollBack();return false;}
                $frame['r'][$i]=$replacement;$changed=true;break;
            }
            if(!$changed||ModeledConversionValueVisitEvidence::decode($frame,$frame['n'],$now)===null){$p->rollBack();return false;}
            $receipt['finite_visit_evidence']=$frame;$encoded=json_encode($receipt,JSON_THROW_ON_ERROR);
            if(!$this->receiptFits($encoded,$mysql)){$p->rollBack();return false;}
            $q=$p->prepare("UPDATE modeled_conversion_value_export_settings SET last_receipt_json=? WHERE project_key='anytour'");$q->execute([$encoded]);
            $p->commit();$this->finiteFrame=$frame;return true;
        }catch(Throwable $ignored){if($p->inTransaction())$p->rollBack();return false;}
    }

    /** A successful historical receipt is an identity-source witness, never a current-visit witness. */
    private function finiteRetainedSource(array $row,int $now):array
    {
        $mysql=$this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql';
        $eq=static fn(string $a,string $b):string=>$mysql?'BINARY '.$a.'=BINARY '.$b:'('.$a.') COLLATE BINARY=('.$b.') COLLATE BINARY';
        $id=static fn(string $column,int $digits):string=>$mysql?"(".$column." REGEXP '^[1-9][0-9]{0,".($digits-1)."}$' AND ".$column." NOT REGEXP '[^0-9]')":
            "(LENGTH(".$column.") BETWEEN 1 AND ".$digits." AND ".$column." NOT GLOB '*[^0-9]*' AND SUBSTR(".$column.",1,1)<>'0')";
        $where=" FROM max_channel_conversion_outbox WHERE project_key='anytour' AND platform='max' AND counter_id='98615635'"
            .' AND '.$eq('project_key',"'anytour'").' AND '.$eq('platform',"'max'").' AND '.$eq('counter_id',"'98615635'")
            .' AND '.$eq('external_user_id','?')." AND identity_source_key='max_tracker' AND state IN ('accepted','processed')"
            .' AND '.$eq('identity_source_key',"'max_tracker'").' AND ('.$eq('state',"'accepted'").' OR '.$eq('state',"'processed'").')'
            ." AND value_receipt_basis IN ('post_response','get_reconciliation')"
            .' AND ('.$eq('value_receipt_basis',"'post_response'").' OR '.$eq('value_receipt_basis',"'get_reconciliation'").') AND '.$id('upload_id',20)
            .' AND value_api_accepted_at_unix>=946684800 AND value_api_accepted_at_unix<=?'
            .' AND tracker_observed_unix>=946684800 AND tracker_observed_unix<=?'
            .' AND '.$id('client_id',40).' AND '.$id('tracker_record_id',20);
        $sql='SELECT '.($mysql?'/*+ MAX_EXECUTION_TIME(250) */ ':'').'client_id,MIN(id) AS source_row'.$where.' GROUP BY client_id LIMIT 2';
        $q=$this->pdo->prepare($sql);$q->execute([$row['external_user_id'],$now,$row['event_unix']]);$ids=$q->fetchAll(PDO::FETCH_ASSOC);$q->closeCursor();
        if(count($ids)!==1)return [count($ids)>1?4:3,null];
        $q=$this->pdo->prepare('SELECT client_id,tracker_record_id,tracker_observed_unix'.$where.' AND id=? LIMIT 1');
        $q->execute([$row['external_user_id'],$now,$row['event_unix'],$ids[0]['source_row']]);$source=$q->fetch(PDO::FETCH_ASSOC);
        if(!$source)return [5,null];
        $at=(string)$source['tracker_observed_unix'];
        if(!MaxTrackerLookupProtocol::id($source['client_id'],40)||!MaxTrackerLookupProtocol::id($source['tracker_record_id'])
            ||preg_match('/\A[0-9]{10}\z/D',$at)!==1||(int)$at>(int)$row['event_unix'])return [5,null];
        return [2,[$source['client_id'],$source['tracker_record_id'],(int)$at]];
    }

    private function runFiniteEvidence(mixed $api,int $tick,callable $gate,callable $clock):void
    {
        if($this->finiteFrame===null)return;$checks=0;
        foreach($this->finiteFrame['r']as$entry){
            if($entry[2]!==0)continue;
            try{
                $now=self::observedAt($clock,$tick);$row=$this->finiteRows[$entry[0]]??null;if(!$row)continue;
                // Re-read only this exact immutable ledger binding and its current dispatch state.
                $q=$this->pdo->prepare('SELECT id,value_profile_id,project_key,platform,conversation_id,external_user_id,counter_id,target,transport_target,value_contract,event_unix,value_rub,state,upload_id,upload_status,identity_source_key,value_origin FROM max_channel_conversion_outbox WHERE id=? LIMIT 1');$q->execute([$entry[1]]);$current=$q->fetch(PDO::FETCH_ASSOC);
                if(!$current||ModeledConversionValueIdentityEvidence::reference($current,$this->finiteFrame['n'])!==$entry[0])continue;
                $end=$entry;$end[2]=2;$end[4]=$now;
                if(!empty($current['upload_id'])||in_array($current['state'],['uploading','unknown','accepted','processed'],true)){
                    $end[2]=3;$end[5]=7;$this->finiteSave($end,0,null,$now);continue;
                }
                if($current['platform']!=='max'||!$this->finiteEligible($current,$gate)){$end[5]=8;$this->finiteSave($end,0,null,$now);continue;}
                if(!$this->finiteLookupReturned){
                    if($this->finiteLookupAttempted){$end[5]=5;$this->finiteSave($end,0,null,$now);}continue;
                }
                $user=$current['external_user_id'];$source=null;$code=6;
                if(array_key_exists($user,$this->finiteIdentities)){
                    $record=$this->finiteIdentities[$user];
                    if($record===null){try{[$code,$source]=$this->finiteRetainedSource($current,$now);}catch(Throwable $ignored){$code=5;}}
                    elseif(is_array($record)&&($record['external_user_id']??null)===$user
                        &&MaxTrackerLookupProtocol::id($record['client_id']??null,40)&&MaxTrackerLookupProtocol::id($record['record_id']??null)
                        &&is_int($record['observed_at_unix']??null)&&$record['observed_at_unix']>=946684800
                        &&$record['observed_at_unix']<=(int)$current['event_unix']){
                        $code=1;$source=[$record['client_id'],$record['record_id'],$record['observed_at_unix']];
                    }
                }
                if($source===null){$end[5]=$code;$this->finiteSave($end,0,null,$now);continue;}
                if($checks>=2)continue;
                if(!is_object($api)||!method_exists($api,'modeledAttributionVisits')){$end[5]=5;$this->finiteSave($end,0,null,$now);continue;}
                // Intent is durable before the first provider read. An interrupted intent is consumed UNKNOWN.
                $intent=$entry;$intent[2]=1;$intent[3]=$now;$intent[5]=$code;
                [$intent[6],$intent[7],$intent[8]]=$source;
                $kind=$code===1?'canonical_max_lookup':'retained_accepted_processed_max_binding';
                $intent[9]=ModeledConversionValueVisitEvidence::candidateReference($entry[0],$source[0],$source[1],$source[2],$kind);
                if(!$this->finiteEligible($current,$gate)||!$this->finiteSave($intent,0,null,$now))continue;
                $checks++;$this->finiteChecksUsed++;$report=['status'=>'unavailable','visits'=>[]];$extra=0;
                try{$report=$api->modeledAttributionVisits($source[0],(int)$current['event_unix'],$now,
                    static function()use(&$extra):void{if(++$extra>1)throw new RuntimeException('finite_read_budget');});}
                catch(Throwable $ignored){}
                $checked=self::observedAt($clock,$tick);
                $window=ModeledConversionAttributionWindow::assess(is_array($report)?$report:[],(int)$current['event_unix'],$checked);
                $done=$intent;$done[2]=2;$done[4]=$checked;
                if(($report['status']??null)!=='ok'||$window['reason']==='visit_report_unavailable'){
                    $done[10]=2;$done[11]=false;$done[12]=4;
                }else{
                    $done[10]=1;$done[11]=$window['allowed'];$done[12]=['within_current_window'=>1,'outside_current_window'=>2,'no_prior_visit'=>3][$window['reason']];
                    $done[13]=$window['visit_at'];$done[14]=$window['source_class']==='yandex_direct'?1:2;
                }
                $this->finiteSave($done,1,$intent[9],$checked);
                if($done[10]===2){$this->optionalVisitStopped=true;break;}
            }catch(Throwable $ignored){ /* The consumed intent, if any, is never retried. */ }
        }
    }

    /** Financial counts/failure clocks win; a retained false tombstone permanently forbids automatic replay. */
    private function withFiniteEvidence(array $stats,array $previous,bool $mysql):array
    {
        if(!array_key_exists('finite_visit_evidence',$previous))return $stats;
        $audit=$stats['identity_resolution_evidence']??null;
        $out=$stats;unset($out['identity_resolution_evidence'],$out['identity_resolution_evidence_incomplete']);
        $out['finite_visit_evidence']=$previous['finite_visit_evidence'];
        try{
            if(!$this->receiptFits(json_encode($out,JSON_THROW_ON_ERROR),$mysql))$out['finite_visit_evidence']=false;
            if(!$this->receiptFits(json_encode($out,JSON_THROW_ON_ERROR),$mysql)&&isset($out['price_continuity_audit']['corrections'])){
                $out['price_continuity_audit']['corrections']=[];$out['price_continuity_audit']['complete']=false;
            }
            if(!$this->receiptFits(json_encode($out,JSON_THROW_ON_ERROR),$mysql))unset($out['price_continuity_audit']);
            return $this->withIdentityEvidence($out,is_array($audit)?$audit:null,$mysql);
        }catch(Throwable $ignored){$out['finite_visit_evidence']=false;return $out;}
    }


    /** Only a recent healthy ordinary response may reserve optional source slots; it never authorizes a GET. */
    private function prepareRecoveryEvidence(int $now):array
    {
        if($this->finiteFrame===null)return [];$p=$this->pdo;
        try{
            $s=self::settings($p);$raw=$s['last_receipt_json']??null;
            if(!is_string($raw)||strlen($raw)>8192||!str_starts_with(ltrim($raw),'{'))return [];
            $prior=json_decode($raw,true,32,JSON_THROW_ON_ERROR);
            if(!is_array($prior)||!ModeledConversionValueVisitRecovery::healthyOrdinary($prior['metrika_report']??null,$now,600))return [];
            $namespace=$this->finiteFrame['n'];$expected=ModeledConversionValueVisitRecovery::originalDigest($this->finiteFrame,$namespace,$now);
            if($expected===null||ModeledConversionValueVisitRecovery::create($prior['finite_visit_evidence']??null,$namespace,$now)===null)return [];
            if($p->inTransaction())return [];$p->beginTransaction();$mysql=$p->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql';
            $q=$p->query("SELECT counter_id,mode,goal_id,goal_intent,contract_version,last_receipt_json FROM modeled_conversion_value_export_settings WHERE project_key='anytour'".($mysql?' FOR UPDATE':''));
            $s=$q->fetch(PDO::FETCH_ASSOC);$raw=$s['last_receipt_json']??null;
            if(!$s||$s['counter_id']!=='98615635'||$s['mode']!=='send'||$s['goal_id']!=='678187097'
                ||$s['goal_intent']!=='verified'||$s['contract_version']!==ModeledConversionValueContract::VERSION
                ||!is_string($raw)||strlen($raw)>8192||!str_starts_with(ltrim($raw),'{')){$p->rollBack();return [];}
            $receipt=json_decode($raw,true,32,JSON_THROW_ON_ERROR);$original=$receipt['finite_visit_evidence']??null;
            if(!ModeledConversionValueVisitRecovery::healthyOrdinary($receipt['metrika_report']??null,$now,600)
                ||ModeledConversionValueVisitRecovery::originalDigest($original,$namespace,$now)!==$expected){$p->rollBack();return [];}
            if(!array_key_exists('finite_visit_recovery',$receipt)){
                $created=ModeledConversionValueVisitRecovery::create($original,$namespace,$now);
                if($created===null){$p->rollBack();return [];}
                $receipt['finite_visit_recovery']=$created;$encoded=json_encode($receipt,JSON_THROW_ON_ERROR);
                if(!$this->receiptFits($encoded,$mysql)){$p->rollBack();return [];}
                $q=$p->prepare("UPDATE modeled_conversion_value_export_settings SET last_receipt_json=? WHERE project_key='anytour'");$q->execute([$encoded]);
            }
            $journal=ModeledConversionValueVisitRecovery::decode($receipt['finite_visit_recovery'],$original,$namespace,$now);
            $p->commit();if($journal===null)return [];
            $this->recoveryJournal=$journal;$this->recoveryOriginal=$original;$pending=[];
            foreach($journal['r']as$row)if($row[2]===0&&isset($this->finiteRows[$row[0]]))$pending[]=$this->finiteRows[$row[0]];
            return $pending;
        }catch(Throwable $ignored){if($p->inTransaction())$p->rollBack();return [];}
    }

    /** One forward journal entry CAS. The original v1 frame is only read and must retain its exact digest. */
    private function recoverySave(array $replacement,int $expectedState,int $now):bool
    {
        $p=$this->pdo;
        try{
            if($this->recoveryJournal===null||$p->inTransaction())return false;
            $p->beginTransaction();$mysql=$p->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql';
            $q=$p->query("SELECT counter_id,mode,goal_id,goal_intent,contract_version,last_receipt_json FROM modeled_conversion_value_export_settings WHERE project_key='anytour'".($mysql?' FOR UPDATE':''));
            $s=$q->fetch(PDO::FETCH_ASSOC);$raw=$s['last_receipt_json']??null;
            if(!$s||$s['counter_id']!=='98615635'||$s['mode']!=='send'||$s['goal_id']!=='678187097'
                ||$s['goal_intent']!=='verified'||$s['contract_version']!==ModeledConversionValueContract::VERSION
                ||!is_string($raw)||strlen($raw)>8192||!str_starts_with(ltrim($raw),'{')){$p->rollBack();return false;}
            $receipt=json_decode($raw,true,32,JSON_THROW_ON_ERROR);$original=$receipt['finite_visit_evidence']??null;
            $journal=ModeledConversionValueVisitRecovery::decode($receipt['finite_visit_recovery']??null,$original,$this->recoveryJournal['n'],$now);
            if($journal===null||$journal['d']!==$this->recoveryJournal['d']){$p->rollBack();return false;}$changed=false;
            foreach($journal['r']as$i=>$row)if($row[0]===$replacement[0]){
                if($row[1]!==$replacement[1]||$row[2]!==$expectedState){$p->rollBack();return false;}
                $journal['r'][$i]=$replacement;$changed=true;break;
            }
            if(!$changed||ModeledConversionValueVisitRecovery::decode($journal,$original,$journal['n'],$now)===null){$p->rollBack();return false;}
            $receipt['finite_visit_recovery']=$journal;$encoded=json_encode($receipt,JSON_THROW_ON_ERROR);
            if(!$this->receiptFits($encoded,$mysql)){$p->rollBack();return false;}
            $q=$p->prepare("UPDATE modeled_conversion_value_export_settings SET last_receipt_json=? WHERE project_key='anytour'");$q->execute([$encoded]);
            $p->commit();$this->recoveryJournal=$journal;return true;
        }catch(Throwable $ignored){if($p->inTransaction())$p->rollBack();return false;}
    }

    /** The actual ordinary response of this invocation is the sole provider-health authority. */
    private function runRecoveryEvidence(mixed $api,int $tick,callable $gate,callable $clock,mixed $ordinary):void
    {
        if($this->recoveryJournal===null||$this->recoveryOriginal===null||$this->optionalVisitStopped
            ||!ModeledConversionValueVisitRecovery::healthyOrdinary($ordinary,$tick)
            ||!$this->finiteLookupReturned||!is_object($api)||!method_exists($api,'modeledAttributionVisits'))return;
        $cases=ModeledConversionValueVisitRecovery::originals($this->recoveryOriginal,$this->recoveryJournal['n'],$tick);
        if($cases===null)return;
        foreach($this->recoveryJournal['r']as$entry){
            if($entry[2]!==0||$this->finiteChecksUsed>=2||$this->optionalVisitStopped)continue;
            try{
                $now=self::observedAt($clock,$tick);$old=$cases[$entry[0]];
                $q=$this->pdo->prepare('SELECT id,value_profile_id,project_key,platform,conversation_id,external_user_id,counter_id,target,transport_target,value_contract,event_unix,value_rub,state,upload_id,upload_status,identity_source_key,value_origin FROM max_channel_conversion_outbox WHERE id=? LIMIT 1');
                $q->execute([$old[1]]);$current=$q->fetch(PDO::FETCH_ASSOC);if(!$current)continue;
                if(ModeledConversionValueIdentityEvidence::reference($current,$this->recoveryJournal['n'])!==$entry[0]
                    ||!$this->finiteEligible($current,$gate))continue;
                if(!empty($current['upload_id'])||in_array($current['state'],['uploading','unknown','accepted','processed'],true)){
                    $skip=$entry;$skip[2]=3;$skip[4]=$now;$skip[10]=4;$this->recoverySave($skip,0,$now);continue;
                }
                $user=$current['external_user_id'];
                // An absent key is not a canonical not-found result and grants no source observation.
                if(!array_key_exists($user,$this->finiteIdentities))continue;
                $record=$this->finiteIdentities[$user];$changed=$entry;$changed[2]=3;$changed[4]=$now;
                if($record===null){$changed[10]=2;$this->recoverySave($changed,0,$now);continue;}
                if(!is_array($record)||($record['external_user_id']??null)!==$user
                    ||!MaxTrackerLookupProtocol::id($record['client_id']??null,40)||!MaxTrackerLookupProtocol::id($record['record_id']??null)
                    ||!is_int($record['observed_at_unix']??null)||$record['observed_at_unix']<946684800||$record['observed_at_unix']>$now)continue;
                $kind=$old[5]===1?'canonical_max_lookup':'retained_accepted_processed_max_binding';
                $same=$record['observed_at_unix']<=(int)$current['event_unix']
                    &&ModeledConversionValueVisitEvidence::candidateReference($entry[0],$record['client_id'],$record['record_id'],$record['observed_at_unix'],$kind)===$entry[1];
                if(!$same){$changed[10]=3;$this->recoverySave($changed,0,$now);continue;}
                $intent=$entry;$intent[2]=1;$intent[3]=$now;$intent[10]=1;
                if(!$this->finiteEligible($current,$gate)||!$this->recoverySave($intent,0,$now))continue;
                $this->finiteChecksUsed++;$report=['status'=>'unavailable','visits'=>[]];$extra=0;
                try{$report=$api->modeledAttributionVisits($old[6],(int)$current['event_unix'],$now,
                    static function()use(&$extra):void{if(++$extra>1)throw new RuntimeException('finite_read_budget');});}
                catch(Throwable $ignored){}
                $checked=self::observedAt($clock,$tick);
                $window=ModeledConversionAttributionWindow::assess(is_array($report)?$report:[],(int)$current['event_unix'],$checked);
                $done=$intent;$done[2]=2;$done[4]=$checked;
                if(($report['status']??null)!=='ok'||$window['reason']==='visit_report_unavailable'){
                    $done[5]=2;$done[6]=false;$done[7]=4;$this->optionalVisitStopped=true;
                }else{
                    $done[5]=1;$done[6]=$window['allowed'];$done[7]=['within_current_window'=>1,'outside_current_window'=>2,'no_prior_visit'=>3][$window['reason']];
                    $done[8]=$window['visit_at'];$done[9]=$window['source_class']==='yandex_direct'?1:2;
                }
                $this->recoverySave($done,1,$checked);
                if($this->optionalVisitStopped)break;
            }catch(Throwable $ignored){ /* A committed new intent is consumed UNKNOWN, never reset. */ }
        }
    }

    /** Only the new journal may yield to financial metadata and the original v1 frame. */
    private function withRecoveryEvidence(array $stats,array $previous,bool $mysql):array
    {
        if(!array_key_exists('finite_visit_recovery',$previous))return $stats;
        $audit=$stats['identity_resolution_evidence']??null;
        $out=$stats;unset($out['identity_resolution_evidence'],$out['identity_resolution_evidence_incomplete']);
        $journal=$previous['finite_visit_recovery'];
        try{
            if(!is_array($journal)||strlen(json_encode($journal,JSON_THROW_ON_ERROR))>ModeledConversionValueVisitRecovery::MAX_BYTES)$journal=false;
            $out['finite_visit_recovery']=$journal;
            if(!$this->receiptFits(json_encode($out,JSON_THROW_ON_ERROR),$mysql))$out['finite_visit_recovery']=false;
            if(!$this->receiptFits(json_encode($out,JSON_THROW_ON_ERROR),$mysql)&&isset($out['price_continuity_audit']['corrections'])){
                $out['price_continuity_audit']['corrections']=[];$out['price_continuity_audit']['complete']=false;
            }
            if(!$this->receiptFits(json_encode($out,JSON_THROW_ON_ERROR),$mysql))unset($out['price_continuity_audit']);
            return $this->withIdentityEvidence($out,is_array($audit)?$audit:null,$mysql);
        }catch(Throwable $ignored){$out['finite_visit_recovery']=false;return $out;}
    }

    private static function failRow(PDO $p,array $r,string $reason):void
    {
        $q=$p->prepare("UPDATE max_channel_conversion_outbox SET state='failed',last_error=? WHERE id=? AND state IN ('pending','ready')");$q->execute([$reason,$r['id']]);
    }
}
