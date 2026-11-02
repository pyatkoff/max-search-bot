<?php
declare(strict_types=1);
require_once __DIR__.'/ModeledConversionValueContract.php';
require_once __DIR__.'/MaxChannelConversionOutbox.php';
require_once __DIR__.'/MaxTrackerLookupClient.php';
require_once __DIR__.'/MetrikaOfflineClient.php';
require_once __DIR__.'/InternalOwnerDialogueScope.php';

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
        if(!in_array($op,['status','create-goal','enable','disable','launch-v1'],true))throw new RuntimeException('invalid_operation');
        $p=$this->pdo;$s=self::settings($p);if(!$s)throw new RuntimeException('value_migration_unavailable');
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

    public static function tick(?PDO $pdo=null,mixed $lookup=null,mixed $api=null,?int $now=null,?callable $gate=null):array
    {
        if($pdo===null&&ProjectConfig::projectId()!=='anytour')return ['status'=>'off'];
        $s=self::settings($pdo);if(!$s||$s['mode']!=='send')return ['status'=>'off'];
        $p=$pdo??ConversationDb::connection();$now??=time();$self=new self($p);
        if(!$gate)$gate=static function(string $chat):bool{
            require_once dirname(__DIR__).'/maxsearchclass.php';
            return MaxSearchApi::metrikaGoalAllowed($chat);
        };
        $stats=['collected'=>0,'resolved'=>0,'accepted'=>0,'processed'=>0,'unknown'=>0,'errors'=>0];
        try{
            $repo=new MaxChannelConversionOutbox($p);$repo->recover($now);
            $stats['collected']=$self->collect($s,$now,$gate);
            $pending=$self->rows('pending',$now,5);
            $users=[];foreach($pending as$r)if($r['platform']==='max'&&$self->eligible($r,$gate))$users[]=$r['external_user_id'];
            $identities=[];
            if($users){$lookup??=new MaxTrackerLookupClient();$identities=$lookup->lookup('98615635',array_values(array_unique($users)));}
            foreach($pending as$r)if($self->resolve($r,$identities,$now,$gate))$stats['resolved']++;
            $ready=$self->rows('ready',$now,3);
            $receipts=array_merge($self->rows('accepted',$now,2),$self->rows('unknown',$now,2));
            if($ready||$receipts){
                if(!$api){
                    if(!defined('METRIKA_COUNTER_ID')||(string)METRIKA_COUNTER_ID!=='98615635'||!defined('METRIKA_OAUTH_TOKEN'))throw new RuntimeException('value_api_unavailable');
                    $api=new MetrikaOfflineClient('98615635',(string)METRIKA_OAUTH_TOKEN);
                }
                $goal=$api->modeledValueGoalStatus();
                if(($goal['id']??null)!==$s['goal_id'])throw new RuntimeException('value_goal_changed');
                foreach($ready as$r){
                    if((self::settings($p)['mode']??'off')!=='send')break;
                    if(!$self->eligible($r,$gate)){self::failRow($p,$r,'scope_unavailable');continue;}
                    $token=bin2hex(random_bytes(16));
                    $q=$p->prepare("UPDATE max_channel_conversion_outbox SET state='uploading',claim_token=?,claimed_at_unix=?,attempts=attempts+1 WHERE id=? AND state='ready' AND target=? AND transport_target=?");
                    $q->execute([$token,$now,$r['id'],$r['target'],ModeledConversionValueContract::TARGET]);
                    if($q->rowCount()!==1)continue;
                    try{$response=$api->uploadModeledValue($r,$now);}catch(Throwable $ignored){$response=['outcome'=>'unknown','reason'=>'upload_outcome_unknown'];}
                    $repo->finish((string)$r['id'],$token,$response,$now);
                    if(($response['outcome']??null)==='accepted')$repo->defer((string)$r['id'],'accepted',$now+300);
                    $key=($response['outcome']??'')==='accepted'?'accepted':(($response['outcome']??'')==='unknown'?'unknown':'errors');$stats[$key]++;
                }
                foreach($receipts as$r){
                    $status=$r['state']==='accepted'?$api->poll((string)$r['upload_id']):$api->findUnknown($r);
                    if($status){$repo->status($r,$status,$now);if(($status['status']??'')==='PROCESSED')$stats['processed']++;}
                    else $repo->defer((string)$r['id'],$r['state'],$now+300);
                }
            }
        }catch(Throwable $ignored){$stats['errors']++;}
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
        $q=$p->prepare("UPDATE modeled_conversion_value_export_settings SET last_tick_unix=?,last_receipt_json=? WHERE project_key='anytour'");
        $q->execute([$now,json_encode($stats,JSON_THROW_ON_ERROR)]);
        // Private counts stay in the DB; ordinary diagnostic logs get technical state only.
        return ['status'=>$stats['errors']?'degraded':'running','contract'=>ModeledConversionValueContract::VERSION];
    }

    public function collect(array $s,int $now,callable $gate):int
    {
        if($this->pdo->inTransaction())throw new RuntimeException('value_outer_transaction');
        $mysql=$this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql';$this->pdo->beginTransaction();
        try{
            $q=$this->pdo->query("SELECT * FROM modeled_conversion_value_export_settings WHERE project_key='anytour'".($mysql?' FOR UPDATE':''));
            $s=$q->fetch(PDO::FETCH_ASSOC);if(!$s||$s['mode']!=='send'){$this->pdo->rollBack();return 0;}
            $q=$this->pdo->prepare("SELECT id,project_key,platform,customer_key,conversation_id,CASE WHEN ".($mysql?'OCTET_LENGTH(profile_json)':'LENGTH(CAST(profile_json AS BLOB))')."<=131072 THEN profile_json ELSE NULL END AS profile_json FROM modeled_conversion_profile_versions WHERE project_key='anytour' AND id>? ORDER BY id LIMIT 8");
            $q->execute([$s['profile_cursor']]);$rows=$q->fetchAll(PDO::FETCH_ASSOC);$after=(string)$s['profile_cursor'];$written=0;
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
                $reserved=(int)$q->fetchColumn();$delta=$candidate['value']-$reserved;if($delta<=0)continue;
                $insert=$mysql?'INSERT IGNORE':'INSERT OR IGNORE';
                $q=$this->pdo->prepare($insert." INTO max_channel_conversion_outbox(project_key,conversation_id,external_user_id,counter_id,target,transport_target,event_unix,platform,identity_source_key,value_rub,value_profile_id,value_stage,value_contract) VALUES('anytour',?,?,'98615635',?,?,?,?,?,?,?,?,?)");
                $q->execute([$r['conversation_id'],$binding['external_user_id'],ModeledConversionValueContract::key($candidate['rank']),
                    ModeledConversionValueContract::TARGET,$candidate['event_unix'],$r['platform'],$r['platform']==='telegram'?'tgmini':'max_tracker',
                    $delta,$r['id'],$candidate['stage'],ModeledConversionValueContract::VERSION]);$written+=$q->rowCount()===1?1:0;
            }
            $q=$this->pdo->prepare("UPDATE modeled_conversion_value_export_settings SET profile_cursor=? WHERE project_key='anytour'");$q->execute([$after]);
            $this->pdo->commit();return $written;
        }catch(Throwable $e){if($this->pdo->inTransaction())$this->pdo->rollBack();throw $e;}
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
        $b=$this->binding($r['conversation_id'],$r['platform']);
        if(!$b||(string)$b['external_user_id']!==(string)$r['external_user_id']||!$gate($b['external_chat_id']))return false;
        $safe="CASE WHEN JSON_VALID(profile_json) THEN profile_json ELSE '{}' END";
        $expr="JSON_EXTRACT(".$safe.",'$.access_status')";if($this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql')$expr='JSON_UNQUOTE('.$expr.')';
        $q=$this->pdo->prepare("SELECT ".$expr." FROM modeled_conversion_profile_versions WHERE project_key='anytour' AND platform=? AND conversation_id=? ORDER BY id DESC LIMIT 1");
        $q->execute([$r['platform'],$r['conversation_id']]);return $q->fetchColumn()==='allowed';
    }
    private function rows(string $state,int $now,int $limit):array
    {
        $q=$this->pdo->prepare("SELECT * FROM max_channel_conversion_outbox WHERE project_key='anytour' AND counter_id='98615635' AND state=? AND transport_target=? AND value_contract=? AND next_attempt_unix<=? ORDER BY id LIMIT ".min(5,max(1,$limit)));
        $q->execute([$state,ModeledConversionValueContract::TARGET,ModeledConversionValueContract::VERSION,$now]);return$q->fetchAll(PDO::FETCH_ASSOC);
    }
    private function resolve(array $r,array $identities,int $now,callable $gate):bool
    {
        if(!$this->eligible($r,$gate)){self::failRow($this->pdo,$r,'scope_unavailable');return false;}
        $client=null;$record=null;$observation=null;$identityReason='identity_not_found';
        if($r['platform']==='max'){
            $record=$identities[$r['external_user_id']]??null;
            if(is_array($record)&&($record['external_user_id']??null)===$r['external_user_id'])$client=$record['client_id']??null;
        }else{
            $q=$this->pdo->prepare("SELECT client_id,MIN(id) AS observation_id FROM advertising_visit_observations WHERE project_key='anytour' AND source_key='tgmini' AND platform='telegram' AND counter_id='98615635' AND external_user_id=? AND identity_status='verified' AND client_id<>'' AND visit_at_unix<=? AND observed_at_unix<=? AND received_at_unix<=? GROUP BY client_id LIMIT 2");
            $q->execute([$r['external_user_id'],$r['event_unix'],$now,$now]);$ids=$q->fetchAll(PDO::FETCH_ASSOC);if(count($ids)>1)$identityReason='identity_ambiguous';if(count($ids)===1){$client=$ids[0]['client_id'];$observation=$ids[0]['observation_id'];}
        }
        if(!MaxTrackerLookupProtocol::id($client,40)){
            $q=$this->pdo->prepare("UPDATE max_channel_conversion_outbox SET next_attempt_unix=?,last_error=? WHERE id=? AND state='pending'");$q->execute([$now+300,$identityReason,$r['id']]);return false;
        }
        $q=$this->pdo->prepare("UPDATE max_channel_conversion_outbox SET client_id=?,tracker_record_id=?,tracker_observed_unix=?,identity_observation_id=?,state='ready',next_attempt_unix=0,last_error='' WHERE id=? AND state='pending'");$q->execute([$client,$record['record_id']??null,$record['observed_at_unix']??null,$observation,$r['id']]);return $q->rowCount()===1;
    }
    private static function failRow(PDO $p,array $r,string $reason):void
    {
        $q=$p->prepare("UPDATE max_channel_conversion_outbox SET state='failed',last_error=? WHERE id=? AND state IN ('pending','ready')");$q->execute([$reason,$r['id']]);
    }
}
