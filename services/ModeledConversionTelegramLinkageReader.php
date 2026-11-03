<?php
declare(strict_types=1);
require_once __DIR__.'/ModeledConversionStatusBudget.php';
require_once __DIR__.'/ModeledConversionValueContract.php';

/** Private fixed-scope scalar diagnosis only; never pulls, resolves, sends or writes. */
final class ModeledConversionTelegramLinkageReader
{
    public const PENDING_LIMIT=32;
    public const SOURCE_LIMIT=1001;
    public function __construct(private PDO $pdo,private $authorize,private ModeledConversionStatusBudget $budget,private $transportStatus=null){}
    public function read(int $now):array
    {
        $this->auth();
        if($this->budget->remainingBytes()<8192)return ['available'=>false,'reason'=>'budget_headroom'];
        try{
            $scope="o.project_key='anytour' AND o.source_key='tgmini' AND o.platform='telegram' AND o.counter_id='98615635' AND o.external_user_id=p.external_user_id";
            $verified=$scope." AND o.identity_status='verified'";
            $client=$verified." AND o.client_id<>''";
            $prior=$client.' AND o.visit_at_unix<=p.event_unix';
            $eligible=$prior.' AND o.observed_at_unix<='.$now.' AND o.received_at_unix<='.$now;
            $exists=static fn(string $where):string=>'EXISTS(SELECT 1 FROM advertising_visit_observations o WHERE '.$where.')';
            $pending="SELECT external_user_id,event_unix,value_rub,next_attempt_unix FROM max_channel_conversion_outbox"
                ." WHERE project_key='anytour' AND counter_id='98615635' AND platform='telegram' AND state='pending'"
                ." AND transport_target=? AND value_contract=? ORDER BY id DESC LIMIT ".self::PENDING_LIMIT;
            $scored='SELECT p.value_rub,p.next_attempt_unix,'.$exists($scope).' AS has_observation,'
                .$exists($verified).' AS has_verified,'.$exists($client).' AS has_client,'.$exists($prior).' AS has_prior,'
                .'(SELECT COUNT(DISTINCT o.client_id) FROM advertising_visit_observations o WHERE '.$eligible.') AS variants'
                .' FROM ('.$pending.') p';
            $reasons=[
                'no_scoped_observation'=>'has_observation=0',
                'identity_unverified'=>'has_observation=1 AND has_verified=0',
                'client_id_missing'=>'has_verified=1 AND has_client=0',
                'visit_after_signal'=>'has_client=1 AND has_prior=0',
                'candidate_not_available_at_check'=>'has_prior=1 AND variants=0',
                'unique_eligible'=>'variants=1',
                'ambiguous_eligible'=>'variants>1'];
            $columns=['COUNT(*) AS pending_events','COALESCE(SUM(value_rub),0) AS pending_value_rub'];
            foreach($reasons as$key=>$condition)$columns[]='COALESCE(SUM(CASE WHEN '.$condition.' THEN 1 ELSE 0 END),0) AS '.$key;
            $columns[]='COALESCE(SUM(CASE WHEN variants=1 AND next_attempt_unix>'.$now.' THEN 1 ELSE 0 END),0) AS unique_retry_deferred';
            $source="SELECT identity_status,client_id,received_at_unix FROM advertising_visit_observations"
                ." WHERE project_key='anytour' AND source_key='tgmini' AND platform='telegram' AND counter_id='98615635' ORDER BY id DESC LIMIT ".self::SOURCE_LIMIT;
            $sql='SELECT pending_summary.*,source_summary.* FROM (SELECT '.implode(',',$columns).' FROM ('.$scored.') scored) pending_summary'
                ." CROSS JOIN (SELECT COUNT(*) AS source_rows,COALESCE(SUM(CASE WHEN identity_status='verified' AND client_id<>'' THEN 1 ELSE 0 END),0) AS source_verified_client_rows,MAX(received_at_unix) AS last_received FROM (".$source.') source_window) source_summary';
            $this->auth();$q=$this->budget->prepare($this->pdo,$sql);
            $q->execute([ModeledConversionValueContract::TARGET,ModeledConversionValueContract::VERSION]);
            $rows=$q->fetchAll(PDO::FETCH_ASSOC);$q->closeCursor();
            $this->budget->consumeBytes(strlen(json_encode($rows,JSON_THROW_ON_ERROR)));$this->auth();
            if(count($rows)!==1)throw new UnexpectedValueException();
            $r=$rows[0];$counts=[];
            foreach($reasons as$key=>$unused)$counts[$key]=self::count($r[$key]);
            $sum=array_sum($counts);$pendingCount=self::count($r['pending_events']);
            if($sum!==$pendingCount||$pendingCount>self::PENDING_LIMIT)throw new UnexpectedValueException();
            $out=['available'=>true,'scope'=>['project_key'=>'anytour','source_key'=>'tgmini','platform'=>'telegram','counter_id'=>'98615635'],
                'basis'=>'stored_observations_and_pending_goal_rows_not_a_new_attribution_decision',
                'pending'=>['max_rows'=>self::PENDING_LIMIT,'events'=>$pendingCount,'value_rub'=>self::count($r['pending_value_rub']),
                    'complete_within_bound'=>$pendingCount<self::PENDING_LIMIT,'reason_counts'=>$counts,'unique_retry_deferred'=>self::count($r['unique_retry_deferred'])],
                'source'=>['max_rows'=>self::SOURCE_LIMIT,'rows_in_window'=>self::count($r['source_rows']),
                    'complete_within_bound'=>(int)$r['source_rows']<self::SOURCE_LIMIT,'verified_with_client_rows'=>self::count($r['source_verified_client_rows']),
                    'last_received_at'=>self::at($r['last_received'],$now)],
                'transport'=>$this->transport($now),
                'interpretation'=>['no_observation_does_not_prove_no_website_visit'=>true,'later_visit_cannot_explain_earlier_signal'=>true,
                    'unique_candidate_does_not_bypass_sender_scope_checks'=>true,'transport_last_success_is_not_current_source_health'=>true,
                    'no_ids_text_or_keys_returned'=>true],'model_calls'=>0,'conversion_uploads'=>0];
            $this->auth();return $out;
        }catch(ModeledConversionStatusLimit $error){throw $error;}
        catch(PDOException $ignored){return ['available'=>false,'reason'=>'storage_unavailable'];}
        catch(UnexpectedValueException $ignored){return ['available'=>false,'reason'=>'invalid_metadata'];}
    }
    private function transport(int $now):array
    {
        try{
            if($this->transportStatus!==null)$v=($this->transportStatus)();
            else{require_once __DIR__.'/AdvertisingVisitTransport.php';$v=(new AdvertisingVisitTransport())->status();}
            if(!is_array($v)||!is_bool($v['initialized']??null)||!is_bool($v['enabled']??null))throw new UnexpectedValueException();
            $last=is_array($v['last_run']??null)?$v['last_run']:[];
            $at=($last['status']??null)==='imported'?self::at($last['received_at_unix']??null,$now):null;
            $out=['available'=>true,'initialized'=>$v['initialized'],'enabled'=>$v['enabled'],
                'last_success_at'=>$at,'has_more_at_last_success'=>$at!==null&&is_bool($last['has_more']??null)?$last['has_more']:null];
            $this->budget->consumeBytes(strlen(json_encode($out,JSON_THROW_ON_ERROR)));return $out;
        }catch(ModeledConversionStatusLimit $error){throw $error;}
        catch(Throwable $ignored){return ['available'=>false,'reason'=>'status_unavailable'];}
    }
    private static function count(mixed $n):int
    {if(!is_numeric($n)||!is_finite((float)$n)||(float)$n<0||(float)$n>1000000000||floor((float)$n)!==(float)$n)throw new UnexpectedValueException();return(int)$n;}
    private static function at(mixed $n,int $now):?string
    {return is_numeric($n)&&(int)$n>=946684800&&(int)$n<=$now?gmdate('Y-m-d\\TH:i:s\\Z',(int)$n):null;}
    private function auth():void{$this->budget->check();($this->authorize)();$this->budget->check();}
}
