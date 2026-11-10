<?php
declare(strict_types=1);
require_once __DIR__.'/ModeledConversionValueContract.php';
require_once __DIR__.'/MaxChannelConversionOutbox.php';
require_once __DIR__.'/MaxTrackerLookupClient.php';
require_once __DIR__.'/MetrikaOfflineClient.php';
require_once __DIR__.'/InternalOwnerDialogueScope.php';
require_once __DIR__.'/ModeledConversionPriceContinuity.php';
require_once __DIR__.'/ModeledConversionTickFailure.php';

/** One bounded addition to the existing cron process and durable conversion outbox. */
final class ModeledConversionValueExport
{
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
            $pending=$self->rows('pending',$now,5);
            $users=[];foreach($pending as$r)if($r['platform']==='max'&&$self->eligible($r,$gate))$users[]=$r['external_user_id'];
            $identities=[];
            if($users){$lookup??=new MaxTrackerLookupClient();$identities=$lookup->lookup('98615635',array_values(array_unique($users)));}
            foreach($pending as$r)if($self->resolve($r,$identities,$now,$gate))$stats['resolved']++;
            $ready=$self->rows('ready',$now,3);
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
                    if(!$self->eligible($r,$gate)){self::failRow($p,$r,'scope_unavailable');continue;}
                    if(!$priceGuard->maySend($r))continue;
                    // Revalidate the retained MAX source at dispatch, not only at the earlier signal.
                    $sendAt=$injectedClock?$now:time();$sourceAt=(int)($r['tracker_observed_unix']??0);
                    if($r['platform']==='max'&&($sourceAt<946684800||$sourceAt>(int)$r['event_unix']
                        ||$sourceAt>$sendAt||$sendAt-$sourceAt>21*86400)){
                        $hold=$p->prepare("UPDATE max_channel_conversion_outbox SET state='pending',next_attempt_unix=?,last_error='identity_not_found'"
                            ." WHERE id=? AND state='ready' AND (upload_id IS NULL OR upload_id='') AND (claim_token IS NULL OR claim_token='')");
                        $hold->execute([$sendAt+300,$r['id']]);continue;
                    }
                    $token=bin2hex(random_bytes(16));
                    $q=$p->prepare("UPDATE max_channel_conversion_outbox SET state='uploading',claim_token=?,claimed_at_unix=?,attempts=attempts+1 WHERE id=? AND state='ready' AND target=? AND transport_target=? AND value_rub=?");
                    $q->execute([$token,$now,$r['id'],$r['target'],ModeledConversionValueContract::TARGET,$r['value_rub']]);
                    if($q->rowCount()!==1)continue;
                    try{$response=$api->uploadModeledValue($r,$now);}catch(Throwable $ignored){$response=['outcome'=>'unknown','reason'=>'upload_outcome_unknown'];}
                    $self->finishObserved($repo,(string)$r['id'],$token,$response,$now,self::observedAt($clock,$now));
                    if(($response['outcome']??null)==='accepted')$repo->defer((string)$r['id'],'accepted',$now+300);
                    $key=($response['outcome']??'')==='accepted'?'accepted':(($response['outcome']??'')==='unknown'?'unknown':'errors');$stats[$key]++;
                    if($key==='errors')$failure??=ModeledConversionTickFailure::fromUpload($now,$response);
                }
                $phase='reconcile_receipts';
                foreach($receipts as$r){
                    $status=$r['state']==='accepted'?$api->poll((string)$r['upload_id']):$api->findUnknown($r);
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
        $self->persistTickReceipt($stats,$failure,$now);
        // Private counts stay in the DB; ordinary diagnostic logs get technical state only.
        return ['status'=>$stats['errors']?'degraded':'running','contract'=>ModeledConversionValueContract::VERSION];
    }

    /** Merge after all network work, so overlapping healthy ticks cannot erase failure evidence. */
    private function persistTickReceipt(array $stats,?array $failure,int $now):void
    {
        $p=$this->pdo;
        if($p->inTransaction())throw new RuntimeException('value_tick_receipt_outer_transaction');
        $p->beginTransaction();
        try{
            $mysql=$p->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql';
            $s=$p->query("SELECT activated_at_unix,last_tick_unix,last_receipt_json FROM modeled_conversion_value_export_settings WHERE project_key='anytour'".($mysql?' FOR UPDATE':''))->fetch(PDO::FETCH_ASSOC);
            if(!$s){$p->commit();return;}
            $receipt=null;
            if(is_string($s['last_receipt_json'])&&strlen($s['last_receipt_json'])<=8192){
                try{$receipt=json_decode($s['last_receipt_json'],true,32,JSON_THROW_ON_ERROR);}catch(JsonException $ignored){}
            }
            $lastTick=(int)($s['last_tick_unix']??0);
            $previous=ModeledConversionTickFailure::normalize(is_array($receipt)?($receipt['last_recorded_failure']??null):null,(int)$s['activated_at_unix'],$lastTick);
            $incoming=ModeledConversionTickFailure::normalize($failure,(int)$s['activated_at_unix'],$now);
            // Equal-second failures retain the first saved record; a healthy tick never clears it.
            $retained=$incoming!==null&&($previous===null||$incoming['tick_at_unix']>$previous['tick_at_unix'])?$incoming:$previous;
            if($lastTick>$now){
                // A delayed failed tick may enrich a newer receipt, never replace its counts or report clocks.
                if(is_array($receipt)&&$retained!==null&&$retained!==$previous){
                    $receipt['last_recorded_failure']=$retained;
                    $encoded=json_encode($receipt,JSON_THROW_ON_ERROR);
                    if($this->receiptFits($encoded,$mysql)){
                        $q=$p->prepare("UPDATE modeled_conversion_value_export_settings SET last_receipt_json=? WHERE project_key='anytour'");
                        $q->execute([$encoded]);
                    }
                }
            }else{
                $stats['last_recorded_failure']=$retained;
                $encoded=json_encode($stats,JSON_THROW_ON_ERROR);
                // Historical detail must not make otherwise readable current stats exceed the reader's cap.
                if(!$this->receiptFits($encoded,$mysql)){unset($stats['last_recorded_failure']);$encoded=json_encode($stats,JSON_THROW_ON_ERROR);}
                $report=$stats['metrika_report'];$reportOk=in_array($report['status']??null,['ok','sampled'],true);
                $q=$p->prepare("UPDATE modeled_conversion_value_export_settings SET last_tick_unix=?,last_receipt_json=?,"
                    ."last_report_success_unix=CASE WHEN ?=1 THEN ? ELSE last_report_success_unix END,"
                    ."last_report_success_json=CASE WHEN ?=1 THEN ? ELSE last_report_success_json END,"
                    ."report_failure_since_unix=CASE WHEN ?=1 THEN NULL ELSE COALESCE(report_failure_since_unix,?) END WHERE project_key='anytour' AND (last_tick_unix IS NULL OR last_tick_unix<=?)");
                $q->execute([$now,$encoded,$reportOk?1:0,$now,$reportOk?1:0,
                    $reportOk?json_encode($report,JSON_THROW_ON_ERROR):null,$reportOk?1:0,$now,$now]);
            }
            $p->commit();
        }catch(Throwable $error){if($p->inTransaction())$p->rollBack();throw $error;}
    }

    private function receiptFits(string $encoded,bool $mysql):bool
    {
        if(strlen($encoded)>8192)return false;
        if(!$mysql)return true;
        // Native JSON adds structural whitespace; use the same byte measure as the reader.
        $q=$this->pdo->prepare('SELECT OCTET_LENGTH(CAST(? AS JSON))');$q->execute([$encoded]);
        return (int)$q->fetchColumn()<=8192;
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
    private function rows(string $state,int $now,int $limit):array
    {
        $q=$this->pdo->prepare("SELECT * FROM max_channel_conversion_outbox WHERE project_key='anytour' AND counter_id='98615635' AND state=? AND transport_target=? AND value_contract=? AND next_attempt_unix<=? ORDER BY next_attempt_unix,id LIMIT ".min(5,max(1,$limit)));
        $q->execute([$state,ModeledConversionValueContract::TARGET,ModeledConversionValueContract::VERSION,$now]);return$q->fetchAll(PDO::FETCH_ASSOC);
    }
    private function resolve(array $r,array $identities,int $now,callable $gate):bool
    {
        if(!$this->eligible($r,$gate)){self::failRow($this->pdo,$r,'scope_unavailable');return false;}
        $client=null;$record=null;$observation=null;$identityReason='identity_not_found';
        if($r['platform']==='max'){
            $record=$identities[$r['external_user_id']]??null;
            // A retained MAX click can outlive the provider's attribution window.
            // Hold unresolved identities without rewriting any accepted receipt or forecast.
            $observed=is_array($record)?($record['observed_at_unix']??null):null;
            if(is_array($record)&&($record['external_user_id']??null)===$r['external_user_id']
                &&is_int($observed)&&$observed>=946684800&&$observed<=(int)$r['event_unix']
                &&(int)$r['event_unix']-$observed<=21*86400&&$now-$observed<=21*86400)$client=$record['client_id']??null;
        }else{
            $q=$this->pdo->prepare("SELECT client_id,MIN(id) AS observation_id FROM advertising_visit_observations WHERE project_key='anytour' AND source_key='tgmini' AND platform='telegram' AND counter_id='98615635' AND external_user_id=? AND identity_status='verified' AND client_id<>'' AND visit_at_unix<=? AND observed_at_unix<=? AND received_at_unix<=? GROUP BY client_id LIMIT 2");
            $q->execute([$r['external_user_id'],$r['event_unix'],$now,$now]);$ids=$q->fetchAll(PDO::FETCH_ASSOC);if(count($ids)>1)$identityReason='identity_ambiguous';if(count($ids)===1){$client=$ids[0]['client_id'];$observation=$ids[0]['observation_id'];}
        }
        if(!MaxTrackerLookupProtocol::id($client,40)){
            $q=$this->pdo->prepare("UPDATE max_channel_conversion_outbox SET next_attempt_unix=?,last_error=? WHERE id=? AND state='pending'");$q->execute([$now+300,$identityReason,$r['id']]);return false;
        }
        $q=$this->pdo->prepare("UPDATE max_channel_conversion_outbox SET client_id=?,tracker_record_id=?,tracker_observed_unix=?,identity_observation_id=?,state='ready',value_ready_at_unix=COALESCE(value_ready_at_unix,?),next_attempt_unix=0,last_error='' WHERE id=? AND state='pending'");$q->execute([$client,$record['record_id']??null,$record['observed_at_unix']??null,$observation,$now,$r['id']]);return $q->rowCount()===1;
    }
    private static function failRow(PDO $p,array $r,string $reason):void
    {
        $q=$p->prepare("UPDATE max_channel_conversion_outbox SET state='failed',last_error=? WHERE id=? AND state IN ('pending','ready')");$q->execute([$reason,$r['id']]);
    }
}
