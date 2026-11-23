<?php
declare(strict_types=1);
require_once __DIR__.'/ModeledConversionStatusBudget.php';
require_once __DIR__.'/InternalOwnerDialogueScope.php';

/** Private saved-only observation. Never imports a worker, model, client or state writer. */
final class Max2CommonValueStatusReaderV2
{
    public const VERSION='max2_common_saved_delivery_status_v2_1';
    public const EVENT_LIMIT=16;
    public const PROFILE_LIMIT=16;
    private const TARGET='anytour_modeled_value_v2_max2';
    private const CONTRACT='max2_common_observed_inquiry_value_v2';
    private const PRODUCER='3a563c1b7921915fd8b17c08f585cde88127904170a086c291a5677d49cf365e';
    private const STATES=['not_needed','reserved','attempted','accepted','processed','unknown','failed'];
    private const TICK=['profiles_read','reserved','evaluated','held','unknown','lookup_list','lookup_recheck','goal_reads','send_attempts','provider_polls'];
    private bool $mysql;
    private int $selected=0;
    private int $started=0;
    public function __construct(private PDO $pdo,private $authorize,private ModeledConversionStatusBudget $budget)
    {$this->mysql=$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql';}

    public function read(int $now):array
    {
        $this->auth();$this->started=hrtime(true);$this->selected=0;
        if($now<946684800||$now>time())return$this->done($this->unavailable('invalid_clock'));
        if($this->pdo->inTransaction())return$this->done($this->unavailable('outer_transaction'));
        $own=false;
        try{
            if($this->mysql){$this->pdo->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');$this->pdo->exec('SET TRANSACTION READ ONLY');}
            if(!$this->pdo->beginTransaction())throw new PDOException('snapshot_unavailable');$own=true;
            $out=$this->inside($now);
        }catch(PDOException $ignored){$out=$this->unavailable('storage_unavailable');}
        catch(UnexpectedValueException $ignored){$out=$this->unavailable($ignored->getMessage()==='saved_receipt_conflict'?'saved_receipt_conflict':'invalid_saved_state');}
        catch(OverflowException $ignored){$out=$this->unavailable('read_limit');}
        finally{if($own&&$this->pdo->inTransaction())$this->pdo->rollBack();}
        // A revoked grant or exhausted shared budget remains an error, never an empty success.
        return$this->done($out);
    }
    private function inside(int $now):array
    {
        $len=$this->mysql?'OCTET_LENGTH(last_tick_summary_json)':'LENGTH(CAST(last_tick_summary_json AS BLOB))';
        $rows=$this->query('SELECT project_key,counter_id,goal_id,target,mode,producer_contract_sha256,activation_sha256,activation_source_sha,activation_request_sha256,'
            .'activated_at_unix,initial_profile_max_id,profile_cursor,active_tick_key,lease_until_unix,last_tick_at_unix,'
            .'CASE WHEN last_tick_summary_json IS NULL THEN NULL WHEN '.$len."<=4096 THEN last_tick_summary_json ELSE 'INVALID_SAVED_SUMMARY' END FROM max2_common_settings_v2 WHERE id=1",[],1);
        if(count($rows)!==1)throw new UnexpectedValueException();
        [$project,$counter,$goal,$target,$mode,$producer,$epoch,$sourceSha,$requestSha,$activation,$floor,$cursor,$active,$lease,$tickAt,$tickJson]=$rows[0];
        if($project!=='anytour'||(string)$counter!=='98615635'||(string)$goal!=='687941321'||$target!==self::TARGET
            ||$producer!==self::PRODUCER||!in_array($mode,['off','collect','send'],true)||!self::id($floor,true)||!self::id($cursor,true)
            ||($active!==null&&!self::sha($active)))throw new UnexpectedValueException();
        $lease=self::number($lease);$activated=$epoch!==null;if($active===null&&$lease!==0)throw new UnexpectedValueException();
        if($activated){
            $activation=self::number($activation);
            if(!self::sha($epoch)||!self::sha($requestSha)||!is_string($sourceSha)||preg_match('/\A[a-f0-9]{40}\z/D',$sourceSha)!==1
                ||$activation<=1795262676||$activation>$now||self::compare($cursor,$floor)<0)throw new UnexpectedValueException();
        }elseif($mode!=='off'||$activation!==null||(string)$floor!=='0'||(string)$cursor!=='0'||$active!==null)throw new UnexpectedValueException();
        $out=['available'=>true,'version'=>self::VERSION,'counter_id'=>98615635,'goal_id'=>687941321,'target'=>self::TARGET,
            'as_of'=>self::date($now),'currency'=>'RUB','state'=>['mode'=>$mode,'activated_at'=>$activated?self::date($activation):null,
                'cursor_advanced'=>$activated&&self::compare($cursor,$floor)>0,'active_tick'=>$active!==null,'lease_expired'=>$active!==null&&$lease<=$now],
            'last_tick'=>$this->tick($tickAt,$tickJson,$activated?$activation:null,$now),
            'interpretation'=>['saved_current_activation_only'=>true,'amounts_are_selected_suffix_not_daily_totals'=>true,
                'initial_reserve_is_only_bound_accounts_in_suffix'=>true,'attempt_is_durable_intent_not_network_confirmation'=>true,
                'cumulative_proof_groups_overlap_current_states'=>true,'provider_processing_is_not_visit_linkage'=>true,
                'original_actor_not_revalidated'=>true],
            'provider_requests'=>0,'model_calls'=>0,'conversion_uploads'=>0,'writes'=>0];
        if(!$activated){$out['ledger']=['available'=>false,'reason'=>'not_activated'];$out['profile_outcomes']=['available'=>false,'reason'=>'not_activated'];$out['examples']=[];return$out;}
        $fields='a.id,a.platform,a.initial_common_kopeks,a.activated_at_unix,a.created_at_unix,a.inquiry_recorded_at_unix,a.inquiry_key,'
            .'e.profile_id,e.event_key,e.total_kopeks,e.delta_kopeks,e.delivery_state,e.event_at_unix,e.created_at_unix,e.attempt_count,'
            .'e.attempt_key,e.csv_sha256,e.attempt_started_at_unix,e.upload_id,e.provider_status,e.source_quantity,e.line_quantity,'
            .'e.accepted_at_unix,e.processed_at_unix,e.transport_receipt_sha256';
        $base=' FROM max2_common_value_events_v2 e JOIN max2_common_value_accounts_v2 a ON a.id=e.account_id'
            .$this->nativeJoin('a')." WHERE a.counter_id=98615635 AND a.goal_id=687941321 AND HEX(a.target)=HEX('".self::TARGET."')"
            ." AND HEX(a.version)=HEX('".self::CONTRACT."') AND HEX(a.activation_sha256)=HEX(?)"
            ." AND e.counter_id=a.counter_id AND e.goal_id=a.goal_id AND HEX(e.target)=HEX(a.target)".$this->nativeWhere('a');
        $events=$this->query('SELECT '.$fields.$base.' ORDER BY e.id DESC LIMIT '.(self::EVENT_LIMIT+1),[$epoch],self::EVENT_LIMIT+1);
        [$out['ledger'],$out['examples']]=$this->ledger($events,$activation,$now);
        $out['profile_outcomes']=$this->profiles($epoch,$activation,$now);
        return$out;
    }
    private function nativeJoin(string $source):string
    {
        return ' JOIN conversations c ON c.id='.$source.'.conversation_id AND HEX(CAST(c.id AS '.($this->mysql?'CHAR':'TEXT').'))=HEX('.$source.'.conversation_id) AND HEX(c.project_key)=HEX('.$source.'.project_key)'
            .' AND HEX(c.channel)=HEX('.$source.'.platform) AND HEX(CAST(c.customer_id AS '.($this->mysql?'CHAR':'TEXT').'))=HEX('.$source.'.customer_key)'
            .' JOIN customer_channels cc ON cc.id=c.customer_channel_id AND cc.customer_id=c.customer_id'
            .' AND HEX(cc.project_key)=HEX(c.project_key) AND HEX(cc.channel)=HEX(c.channel) JOIN customers cu ON cu.id=c.customer_id';
    }
    private function nativeWhere(string $source):string
    {
        return " AND HEX(".$source.".project_key)=HEX('anytour') AND ".$source.".platform IN ('max','telegram')"
            .' AND c.is_test=0 AND NOT '.InternalOwnerDialogueScope::conversationSql('c');
    }
    private function ledger(array $rows,int $activation,int $now):array
    {
        $complete=count($rows)<=self::EVENT_LIMIT;$rows=array_slice($rows,0,self::EVENT_LIMIT);
        $states=[];foreach(self::STATES as$state)$states[$state]=['events'=>0,'delta_kopeks'=>0];
        $proof=['attempt_intents'=>['events'=>0,'delta_kopeks'=>0],'api_accepted'=>['events'=>0,'delta_kopeks'=>0],'processed'=>['events'=>0,'delta_kopeks'=>0]];
        $accounts=[];$examples=[];$processedExample=null;$total=0;
        foreach($rows as$r){
            [$account,$platform,$initial,$at,$created,$inquiryAt,$inquiry,$profile,$event,$estimate,$delta,$state,$eventAt,$savedAt,
                $attempts,$attempt,$csv,$attemptAt,$upload,$provider,$sourceQty,$lineQty,$acceptedAt,$processedAt,$receipt]=$r;
            foreach([$account,$profile]as$id)if(!self::id($id))throw new UnexpectedValueException();
            foreach([$inquiry,$event]as$hash)if(!self::sha($hash))throw new UnexpectedValueException();
            $initial=self::number($initial,10000000);$estimate=self::number($estimate,10000000);$delta=self::number($delta,10000000);
            $at=self::number($at);$created=self::number($created);$inquiryAt=self::number($inquiryAt);
            $eventAt=self::number($eventAt);$savedAt=self::number($savedAt);$attempts=self::number($attempts,1);
            if($at!==$activation||$inquiryAt<$at||$created<$inquiryAt||$eventAt<$at||$savedAt<$eventAt||$savedAt<$created||$savedAt>$now
                ||$delta>$estimate||!in_array($state,self::STATES,true)||(($delta===0)!==($state==='not_needed'))
                ||$event!==hash('sha256','max2/common/profile-delta/v2|'.$inquiry.'|'.$profile))throw new UnexpectedValueException();
            if(isset($accounts[$account])&&$accounts[$account]!==$initial)throw new UnexpectedValueException();$accounts[$account]=$initial;
            $hasAttempt=$attempts===1;$hasApi=false;$processed=false;
            if($hasAttempt){
                $attemptAt=self::number($attemptAt);
                if(!self::sha($attempt)||!self::sha($csv)||$attempt!==hash('sha256','max2/common/attempt/v2|'.$event.'|'.$csv)
                    ||$attemptAt<$savedAt||$attemptAt>$now||in_array($state,['not_needed','reserved'],true))throw new UnexpectedValueException('saved_receipt_conflict');
            }elseif(!in_array($state,['not_needed','reserved'],true)||$attempt!==null||$csv!==null||$attemptAt!==null||$upload!==null||$acceptedAt!==null||$processedAt!==null
                ||$provider!==null||$sourceQty!==null||$lineQty!==null||$receipt!==null)
                throw new UnexpectedValueException('saved_receipt_conflict');
            if($acceptedAt!==null){
                $acceptedAt=self::number($acceptedAt);$hasApi=true;
                if(!$hasAttempt||!self::id($upload,false,40)||!self::sha($receipt)||$acceptedAt<$attemptAt||$acceptedAt>$now
                    ||!in_array($state,['accepted','processed','failed'],true))throw new UnexpectedValueException('saved_receipt_conflict');
            }elseif($upload!==null||in_array($state,['accepted','processed'],true))throw new UnexpectedValueException('saved_receipt_conflict');
            if($provider!==null&&(!$hasApi||!in_array($provider,['PREPARED','UPLOADED','EXPORTED','MATCHED','PROCESSED','LINKAGE_FAILURE'],true)))throw new UnexpectedValueException('saved_receipt_conflict');
            foreach([$sourceQty,$lineQty]as$quantity)if($quantity!==null&&!in_array((string)$quantity,['0','1'],true))throw new UnexpectedValueException('saved_receipt_conflict');
            if($state==='processed'){
                $processedAt=self::number($processedAt);$processed=true;
                if(!$hasApi||$provider!=='PROCESSED'||(string)$sourceQty!=='1'||(string)$lineQty!=='1'||$processedAt<$acceptedAt||$processedAt>$now)
                    throw new UnexpectedValueException('saved_receipt_conflict');
            }elseif($processedAt!==null||$provider==='PROCESSED')throw new UnexpectedValueException('saved_receipt_conflict');
            $states[$state]['events']++;$states[$state]['delta_kopeks']+=$delta;$total+=$delta;
            foreach(['attempt_intents'=>$hasAttempt,'api_accepted'=>$hasApi,'processed'=>$processed]as$key=>$yes)if($yes){$proof[$key]['events']++;$proof[$key]['delta_kopeks']+=$delta;}
            $example=['reference'=>hash('sha256','max2/private/status/v2|'.$event),'platform'=>$platform,
                'state'=>$state,'delta_kopeks'=>$delta,'saved_total_kopeks'=>$estimate,'initial_common_kopeks'=>$initial,
                'event_at'=>self::date($eventAt),'api_accepted_at'=>$hasApi?self::date($acceptedAt):null,
                'processed_at'=>$processed?self::date($processedAt):null,'provider_status'=>$provider];
            if(count($examples)<3)$examples[]=$example;if($processed&&$processedExample===null)$processedExample=$example;
        }
        if($processedExample!==null)$examples=array_slice([$processedExample,...array_values(array_filter($examples,
            static fn(array $e):bool=>$e['reference']!==$processedExample['reference']))],0,3);
        return[['available'=>true,'scope'=>'current_native_anytour_non_test_accounts_current_activation',
            'coverage'=>['basis'=>'newest_event_primary_keys','max_events'=>self::EVENT_LIMIT,'selected_events'=>count($rows),'complete_current_scope'=>$complete],
            'selected_accounts'=>count($accounts),'bound_initial_common_kopeks'=>array_sum($accounts),'late_reserved_kopeks'=>$total,
            'current_states'=>$states,'cumulative_saved_proof'=>$proof],$examples];
    }
    private function profiles(string $epoch,int $activation,int $now):array
    {
        $cast=$this->mysql?'CHAR':'TEXT';
        $rows=$this->query('SELECT w.outcome,w.reason,w.recorded_at_unix FROM max2_common_profile_work_v2 w'
            .' JOIN modeled_conversion_profile_versions p ON p.id=w.profile_id AND HEX(CAST(p.id AS '.$cast.'))=HEX(w.profile_id)'
            .$this->nativeJoin('p').' WHERE w.activation_sha256=? AND HEX(w.activation_sha256)=HEX(?)'
            .' AND p.observed_at_unix>=?'.$this->nativeWhere('p').' ORDER BY p.id DESC LIMIT '.(self::PROFILE_LIMIT+1),[$epoch,$epoch,$activation],self::PROFILE_LIMIT+1);
        $complete=count($rows)<=self::PROFILE_LIMIT;$rows=array_slice($rows,0,self::PROFILE_LIMIT);$counts=[];$reasons=[];$latest=null;
        foreach($rows as[$outcome,$reason,$at]){
            $at=self::number($at);if(!in_array($outcome,['RESERVED','EVALUATED','ALREADY_RECORDED','HOLD','UNKNOWN'],true)
                ||!is_string($reason)||preg_match('/\A[A-Z_]{1,80}\z/D',$reason)!==1||$at<$activation||$at>$now)throw new UnexpectedValueException();
            $counts[$outcome]=($counts[$outcome]??0)+1;
            if(in_array($outcome,['HOLD','UNKNOWN'],true))$reasons[$reason]=($reasons[$reason]??0)+1;
            $latest??=['outcome'=>$outcome,'reason'=>$reason,'recorded_at'=>self::date($at)];
        }
        return['available'=>true,'basis'=>'newest_saved_profile_ids_not_unique_inquiries','max_profiles'=>self::PROFILE_LIMIT,
            'selected_profiles'=>count($rows),'complete_current_scope'=>$complete,'outcome_counts'=>$counts,'hold_reason_counts'=>$reasons,'latest'=>$latest];
    }
    private function tick($at,$json,?int $activation,int $now):array
    {
        if($at===null&&$json===null)return['available'=>false,'reason'=>'no_completed_tick'];
        try{
            $at=self::number($at);if($activation===null||$at<$activation||$at>$now||!is_string($json))throw new UnexpectedValueException();
            $v=json_decode($json,true,8,JSON_THROW_ON_ERROR);if(!is_array($v)||count($v)!==count(self::TICK))throw new UnexpectedValueException();
            $safe=[];foreach(self::TICK as$key){if(!is_int($v[$key]??null)||$v[$key]<0||$v[$key]>100)throw new UnexpectedValueException();$safe[$key]=$v[$key];}
            return['available'=>true,'at'=>self::date($at),'basis'=>'saved_completed_tick_action_counts_not_money','counts'=>$safe];
        }catch(UnexpectedValueException|JsonException $ignored){return['available'=>false,'reason'=>'invalid_saved_summary'];}
    }
    private function query(string $sql,array $args,int $maxRows):array
    {
        $this->slice();if(!str_starts_with($sql,'SELECT '))throw new LogicException('select_only');
        if($this->mysql)$sql='SELECT /*+ MAX_EXECUTION_TIME('.min(250,$this->budget->remainingMilliseconds(),max(1,600-(int)((hrtime(true)-$this->started)/1000000))).') */ '.substr($sql,7);
        $q=$this->pdo->prepare($sql);$q->execute($args);$rows=[];
        try{while(($row=$q->fetch(PDO::FETCH_NUM))!==false){
            $bytes=strlen(json_encode($row,JSON_THROW_ON_ERROR));$this->budget->consumeBytes($bytes);$this->selected+=$bytes;$this->slice();
            if(count($rows)>=$maxRows)throw new OverflowException();$rows[]=$row;
        }}finally{$q->closeCursor();}
        $this->slice();return$rows;
    }
    private function slice():void{$this->budget->check();if($this->selected>12288||hrtime(true)-$this->started>600000000)throw new OverflowException();}
    private function auth():void{$this->budget->check();($this->authorize)();$this->budget->check();}
    private function done(array $out):array{$this->auth();return$out;}
    private function unavailable(string $reason):array{return['available'=>false,'version'=>self::VERSION,'goal_id'=>687941321,'target'=>self::TARGET,'reason'=>$reason];}
    private static function number($v,int $max=9999999999):int{if((!is_int($v)&&!is_string($v))||preg_match('/\A(?:0|[1-9][0-9]{0,10})\z/D',(string)$v)!==1||(int)$v>$max)throw new UnexpectedValueException();return(int)$v;}
    private static function id($v,bool $zero=false,int $length=20):bool{return(is_int($v)||is_string($v))&&preg_match('/\A'.($zero?'(?:0|[1-9][0-9]{0,'.($length-1).'})':'[1-9][0-9]{0,'.($length-1).'}').'\z/D',(string)$v)===1;}
    private static function sha($v):bool{return is_string($v)&&preg_match('/\A[a-f0-9]{64}\z/D',$v)===1;}
    private static function compare($a,$b):int{$a=(string)$a;$b=(string)$b;return strlen($a)<=>strlen($b)?:strcmp($a,$b);}
    private static function date(int $v):string{return gmdate('Y-m-d\TH:i:s\Z',$v);}
}
