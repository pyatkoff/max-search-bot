<?php
declare(strict_types=1);
require_once __DIR__.'/ModeledConversionValueContract.php';
require_once __DIR__.'/InternalOwnerDialogueScope.php';

/** Owner-only recorded-value reconciliation. SELECT-only; never sends or resolves identities. */
final class ModeledConversionDailyReconciliationReader
{
    public const VERSION='modeled_daily_reconciliation_v1';
    public const MAX_SECONDS=15;
    public const MAX_BYTES=131072;
    private const STATES=['pending','ready','uploading','accepted','processed','unknown','failed','expired','unmatched'];
    private $clock;
    private float $started;
    private int $bytes=0;
    public function __construct(private PDO $pdo,private $authorize,?callable $monotonic=null)
    {$this->clock=$monotonic??static fn():float=>microtime(true);}
    public function read(array $input,int $now):array
    {
        $this->started=($this->clock)();$this->bytes=0;$this->auth();
        try{
            if($now<946684800||array_diff(array_keys($input),['date','platform']))throw new InvalidArgumentException();
            $zone=new DateTimeZone('Europe/Kaliningrad');
            $date=$input['date']??(new DateTimeImmutable('@'.$now))->setTimezone($zone)->format('Y-m-d');
            $platform=$input['platform']??'all';
            if(!is_string($date)||preg_match('/\A20[0-9]{2}-[0-9]{2}-[0-9]{2}\z/D',$date)!==1
                ||!in_array($platform,['all','max','telegram'],true))throw new InvalidArgumentException();
            $day=DateTimeImmutable::createFromFormat('!Y-m-d',$date,$zone);
            if(!$day||$day->format('Y-m-d')!==$date||$day->getTimestamp()>$now)throw new InvalidArgumentException();
        }catch(InvalidArgumentException $ignored){return$this->done(['available'=>false,'reason'=>'invalid_request']);}
        if($this->pdo->inTransaction())return$this->done(['available'=>false,'reason'=>'outer_transaction']);
        $own=false;
        try{
            $mysql=$this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql';
            if($mysql){$this->pdo->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');$this->pdo->exec('SET TRANSACTION READ ONLY');}
            $this->pdo->beginTransaction();$own=true;
            $base=$this->base($mysql);
            $fence=$this->query('SELECT COALESCE(MAX(o.id),0) AS fence '.$base,[],1)[0]['fence'];
            if(!preg_match('/\A(?:0|[1-9][0-9]{0,19})\z/D',(string)$fence))throw new UnexpectedValueException();
            $common=$base.' AND o.id<=?';$commonArgs=[(string)$fence];
            if($platform!=='all'){$common.=' AND o.platform=?';$commonArgs[]=$platform;}
            $from=$day->getTimestamp();$until=$day->modify('+1 day')->getTimestamp();
            $receipt="o.upload_id IS NOT NULL AND o.upload_id<>''";
            $history="o.value_origin IN ('owner_day_review_v1','owner_fortnight_review_v1')";
            $pairs=[];
            foreach(ModeledConversionValueContract::STAGES as$rank=>$stage)
                $pairs[]="(HEX(o.target)=HEX('".ModeledConversionValueContract::key($rank)."') AND HEX(o.value_stage)=HEX('".$stage."'))";
            $states=implode(',',array_map(static fn(string $v):string=>"HEX('".$v."')",self::STATES));
            $bad="o.target IS NULL OR o.value_stage IS NULL OR NOT (".implode(' OR ',$pairs).")"
                ." OR o.platform IS NULL OR HEX(o.platform) NOT IN (HEX('max'),HEX('telegram'))"
                ." OR o.state IS NULL OR HEX(o.state) NOT IN (".$states.")"
                ." OR (o.value_origin IS NOT NULL AND HEX(o.value_origin) NOT IN (HEX('owner_day_review_v1'),HEX('owner_fortnight_review_v1')))"
                ." OR o.value_rub IS NULL OR o.value_rub<=0 OR o.value_rub>25000"
                ." OR (HEX(o.state) IN (HEX('accepted'),HEX('processed')) AND NOT (".$receipt."))"
                ." OR o.event_unix IS NULL OR o.event_unix<946684800 OR o.event_unix>".$now
                ." OR (o.value_receipt_basis IS NOT NULL AND HEX(o.value_receipt_basis) NOT IN (HEX('post_response'),HEX('get_reconciliation')))";
            foreach(['claimed_at_unix','value_api_accepted_at_unix','value_processed_at_unix']as$clock){
                $bad.=" OR (o.$clock IS NOT NULL AND (o.$clock<946684800 OR o.$clock>".$now;
                if($clock!=='claimed_at_unix')$bad.=" OR NOT (".$receipt.") OR o.value_receipt_basis IS NULL";
                $bad.='))';
            }
            $metric=static function(string $condition,string $prefix):array{
                return ['COALESCE(SUM(CASE WHEN '.$condition.' THEN 1 ELSE 0 END),0) AS '.$prefix.'n',
                    'COUNT(DISTINCT CASE WHEN '.$condition.' THEN o.conversation_id ELSE NULL END) AS '.$prefix.'c',
                    'COALESCE(SUM(CASE WHEN '.$condition.' THEN o.value_rub ELSE 0 END),0) AS '.$prefix.'v'];
            };
            $columns=[...$metric('1=1',''),...$metric("o.platform='max'",'m_'),...$metric("o.platform='telegram'",'t_'),
                ...$metric('o.value_origin IS NULL','l_'),...$metric($history,'h_'),
                'COALESCE(SUM(CASE WHEN '.$bad.' THEN 1 ELSE 0 END),0) AS bad'];
            $statusConditions=[];
            foreach(self::STATES as$state)$statusConditions['state_'.$state]="o.state='".$state."'";
            $statusConditions['waiting']="o.state IN ('pending','ready','uploading')";
            $statusConditions['problems']="o.state IN ('unknown','failed','expired','unmatched')";
            $statusConditions['waiting_identity']=$statusConditions['waiting']." AND (o.client_id IS NULL OR o.client_id='')";
            $cohorts=[
                'accruals'=>['o.event_unix','1=1'],
                'upload_claims'=>['o.claimed_at_unix','1=1'],
                'api_receipts'=>['o.value_api_accepted_at_unix',$receipt],
                'processed'=>['o.value_processed_at_unix',$receipt],
                'legacy_api_receipts'=>[null,$receipt.' AND o.value_api_accepted_at_unix IS NULL'],
                'legacy_processed'=>[null,$receipt." AND o.state='processed' AND o.value_processed_at_unix IS NULL"],
            ];
            $queries=[];$args=[];
            foreach($cohorts as$key=>[$clock,$condition]){
                $fields=$columns;
                foreach($statusConditions as$label=>$stateCondition)
                    $fields=[...$fields,...($key==='accruals'?$metric($stateCondition,$label.'_'):
                        ['0 AS '.$label.'_n','0 AS '.$label.'_c','0 AS '.$label.'_v'])];
                $where=$common.' AND ('.$condition.')';$rowArgs=$commonArgs;
                if($clock!==null){$where.=' AND '.$clock.'>=? AND '.$clock.'<? AND '.$clock.'<=?';array_push($rowArgs,$from,$until,$now);}
                $queries[]="SELECT '".$key."' AS cohort,".implode(',',$fields).' '.$where;
                array_push($args,...$rowArgs);
            }
            $records=$this->query(implode(' UNION ALL ',$queries),$args,6);
            if(count($records)!==6)throw new UnexpectedValueException();
            $groups=[];$status=[];
            foreach($records as$r){
                $key=$r['cohort']??null;
                if(!is_string($key)||!array_key_exists($key,$cohorts)||isset($groups[$key])||self::count($r['bad']??null)!==0)
                    throw new UnexpectedValueException();
                $groups[$key]=self::group($r);
                if($key==='accruals')foreach($statusConditions as$label=>$unused)$status[$label]=self::summary($r,$label.'_');
            }
            $events=0;$cents=0;
            foreach(self::STATES as$state){$events+=$status['state_'.$state]['events'];$cents+=self::cents($status['state_'.$state]['value_rub']);}
            if($events!==$groups['accruals']['events']||$cents!==self::cents($groups['accruals']['value_rub'])
                ||$status['waiting_identity']['events']>$status['waiting']['events'])throw new UnexpectedValueException();
            $calendar=[];$basis=['accruals'=>'saved_assignment_event_unix','upload_claims'=>'last_current_persisted_claim_not_http_send_count',
                'api_receipts'=>'first_server_observation_of_api_receipt','processed'=>'first_server_observation_of_processed_rows'];
            foreach($basis as$key=>$value)$calendar[$key]=['basis'=>$value]+$groups[$key];
            $byState=[];foreach(self::STATES as$state)$byState[$state]=$status['state_'.$state];
            $this->pdo->rollBack();$own=false;
            return$this->done(['available'=>true,'schema_version'=>1,'version'=>self::VERSION,
                'date'=>$date,'timezone'=>'Europe/Kaliningrad','platform'=>$platform,'currency'=>'RUB',
                'as_of'=>gmdate('Y-m-d\TH:i:s\Z',$now),
                'window'=>['from'=>gmdate('Y-m-d\TH:i:s\Z',$from),'until_exclusive'=>gmdate('Y-m-d\TH:i:s\Z',$until)],
                'calendar'=>$calendar,'current_statuses'=>['basis'=>'current_state_of_selected_day_accruals','by_state'=>$byState,
                    'waiting'=>$status['waiting'],'problems'=>$status['problems'],'waiting_identity'=>$status['waiting_identity']],
                'coverage'=>[
                    'api_receipts_without_observed_clock'=>['basis'=>'all_current_scope_receipts_not_assigned_to_any_day']+$groups['legacy_api_receipts'],
                    'processed_without_observed_clock'=>['basis'=>'all_current_scope_processed_receipts_not_assigned_to_any_day']+$groups['legacy_processed'],
                    'per_event_visit_match'=>['status'=>'unavailable','reason'=>'not_recorded_per_conversion'],
                    'scope'=>'current_exact_owner_admitted_native_bindings'],
                'interpretation'=>['calendar_cohorts_overlap_do_not_sum'=>true,'states_are_current_not_end_of_selected_day'=>true,
                    'events_are_value_rows_not_http_requests_people_or_purchases'=>true,'dialogue_counts_overlap_across_states_and_origins'=>true,
                    'receipt_and_processing_are_not_individual_visit_linkage'=>true,'claim_is_last_persisted_claim_not_all_attempts'=>true,
                    'legacy_clock_is_unknown_not_claim_time'=>true,'historical_review_is_origin_not_delivery_day'=>true,
                    'current_binding_changes_can_change_historical_scope'=>true,'identity_is_stored_presence_not_a_new_attribution_check'=>true,
                    'no_ids_text_or_external_requests'=>true],
                'limits'=>['seconds'=>self::MAX_SECONDS,'read_bytes'=>self::MAX_BYTES]]);
        }catch(PDOException $ignored){
            if($own&&$this->pdo->inTransaction())$this->pdo->rollBack();
            return$this->done(['available'=>false,'reason'=>'storage_unavailable']);
        }catch(UnexpectedValueException $ignored){
            if($own&&$this->pdo->inTransaction())$this->pdo->rollBack();
            return$this->done(['available'=>false,'reason'=>'invalid_aggregate']);
        }catch(OverflowException $ignored){
            if($own&&$this->pdo->inTransaction())$this->pdo->rollBack();
            $this->auth();return['available'=>false,'reason'=>'read_limit'];
        }catch(Throwable $error){if($own&&$this->pdo->inTransaction())$this->pdo->rollBack();throw$error;}
    }
    private function base(bool $mysql):string
    {
        $cast=$mysql?'CHAR':'TEXT';$peer=$mysql?"CONCAT('-',o.external_user_id)":"'-'||o.external_user_id";
        return 'FROM max_channel_conversion_outbox o JOIN conversations c ON c.id=o.conversation_id AND HEX(c.project_key)=HEX(o.project_key) AND HEX(c.channel)=HEX(o.platform)'
            .' JOIN customer_channels cc ON cc.id=c.customer_channel_id AND cc.customer_id=c.customer_id AND HEX(cc.project_key)=HEX(c.project_key) AND HEX(cc.channel)=HEX(c.channel)'
            .' JOIN customers cu ON cu.id=c.customer_id'
            ." WHERE o.project_key='anytour' AND o.counter_id='98615635' AND o.transport_target='anytour_modeled_value_v1' AND o.value_contract='semantic_stage_value_25000_v1'"
            ." AND HEX(o.project_key)=HEX('anytour') AND HEX(o.transport_target)=HEX('anytour_modeled_value_v1') AND HEX(o.value_contract)=HEX('semantic_stage_value_25000_v1')"
            ." AND o.platform IN ('max','telegram') AND c.is_test=0 AND NOT ".InternalOwnerDialogueScope::conversationSql('c')
            .' AND HEX(CAST(cc.external_user_id AS '.$cast.'))=HEX(CAST(o.external_user_id AS '.$cast.'))'
            .' AND HEX(CAST(cc.external_chat_id AS '.$cast.'))=HEX(CAST(c.external_chat_id AS '.$cast.'))'
            ." AND HEX(CAST(c.external_chat_id AS ".$cast."))=HEX(CAST(CASE WHEN o.platform='max' THEN ".$peer.' ELSE o.external_user_id END AS '.$cast.'))';
    }
    private static function group(array $r):array
    {
        $all=self::summary($r);$max=self::summary($r,'m_');$tg=self::summary($r,'t_');
        $live=self::summary($r,'l_');$history=self::summary($r,'h_');
        if($max['events']+$tg['events']!==$all['events']||$max['conversations']+$tg['conversations']!==$all['conversations']
            ||self::cents($max['value_rub'])+self::cents($tg['value_rub'])!==self::cents($all['value_rub'])
            ||$live['events']+$history['events']!==$all['events']
            ||self::cents($live['value_rub'])+self::cents($history['value_rub'])!==self::cents($all['value_rub'])
            ||max($live['conversations'],$history['conversations'])>$all['conversations'])throw new UnexpectedValueException();
        return$all+['by_platform'=>['max'=>$max,'telegram'=>$tg],'origins'=>['live'=>$live,'historical_review'=>$history]];
    }
    private static function summary(array $r,string $prefix=''):array
    {
        $n=self::count($r[$prefix.'n']??null);$c=self::count($r[$prefix.'c']??null);$v=self::cents($r[$prefix.'v']??null);
        if($c>$n||$n>$c*6||($n===0&&($c!==0||$v!==0))||($n>0&&($v<$n||$v>$n*2500000)))throw new UnexpectedValueException();
        return['events'=>$n,'conversations'=>$c,'value_rub'=>self::rub($v)];
    }
    private static function count($v):int
    {
        if((!is_int($v)&&!is_string($v))||preg_match('/\A(?:0|[1-9][0-9]{0,9})\z/D',(string)$v)!==1||(float)$v>1000000000)
            throw new UnexpectedValueException();
        return(int)$v;
    }
    private static function cents(mixed $v):int
    {
        if(is_float($v)){
            if(!is_finite($v)||$v<0||$v>1000000000000)throw new UnexpectedValueException();
            $n=round($v*100);if(abs($v*100-$n)>0.01)throw new UnexpectedValueException();return(int)$n;
        }
        if(is_int($v))$v=(string)$v;
        if(!is_string($v)||preg_match('/\A([0-9]{1,13})(?:\.([0-9]{1,2}))?\z/D',$v,$m)!==1||(float)$v>1000000000000)throw new UnexpectedValueException();
        return(int)$m[1]*100+(int)str_pad($m[2]??'',2,'0');
    }
    private static function rub(int $cents):int|float{return$cents%100===0?intdiv($cents,100):$cents/100;}
    private function query(string $sql,array $args,int $limit):array
    {
        $this->guard();$this->auth();
        if($this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'){
            $left=(int)floor((self::MAX_SECONDS-(($this->clock)()-$this->started))*1000);
            if($left<1)throw new OverflowException();
            $sql=preg_replace('/\ASELECT /','SELECT /*+ MAX_EXECUTION_TIME('.min(4500,$left).') */ ',$sql,1);
        }
        $q=$this->pdo->prepare($sql);$q->execute($args);$rows=[];
        try{while(($r=$q->fetch(PDO::FETCH_ASSOC))!==false){
            if(count($rows)>=$limit)throw new UnexpectedValueException();
            $this->bytes+=strlen(json_encode($r,JSON_THROW_ON_ERROR));$this->guard();$rows[]=$r;
        }}finally{$q->closeCursor();}
        $this->auth();$this->guard();return$rows;
    }
    private function guard():void
    {if(($this->clock)()-$this->started>self::MAX_SECONDS||$this->bytes>self::MAX_BYTES)throw new OverflowException();}
    private function done(array $result):array
    {$this->auth();$this->guard();return$result;}
    private function auth():void{($this->authorize)();}
}
