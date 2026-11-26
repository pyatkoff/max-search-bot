<?php
declare(strict_types=1);

/** Pure aggregate of verified metadata and saved visit candidates. No attribution winner. */
final class TelegramBusinessIdentitySummary
{
    public const METRICS=['prior_visit','prior_visit_21d','verified_prior_match','verified_prior_match_21d','prior_visit_available_at_message',
        'unique_client_id','multiple_client_ids','client_id_missing','click_present','same_observation_pair',
        'window_21d_unique_client_id','window_21d_multiple_client_ids','window_21d_click_present',
        'window_21d_same_observation_pair','identity_conflict','late_observed','late_imported',
        'future_visit','invalid_history','missing_history','no_prior_visit'];

    /** Events and candidates remain private process data; only counters leave this method. */
    public static function aggregate(array $events,array $history,int $now):array
    {
        $counts=array_fill_keys(self::METRICS,0);$peers=array_fill_keys(self::METRICS,[]);
        foreach($events as$event){
            $id=$event['peer'];$at=$event['at'];$rows=$history[$id]??[];
            $flags=array_fill_keys(self::METRICS,false);$prior=[];$window=[];
            $priorPair=$windowPair=$priorClick=$windowClick=false;
            $flags['missing_history']=$rows===[];
            foreach($rows as$row){
                $conflict=$row['identity_conflict']??null;
                if($conflict===1||$conflict==='1')$flags['identity_conflict']=true;
                $visit=self::unix($row['visit_at_unix']??null,$now);
                $observed=self::unix($row['observed_at_unix']??null,$now);
                $received=self::unix($row['received_at_unix']??null,$now);
                $client=$row['client_id']??null;$click=$row['yclid_present']??null;
                if($visit===null||$observed===null||$received===null||$visit>$observed||$observed>$received+300
                    ||!is_string($client)||($client!==''&&preg_match('/\A[1-9][0-9]{0,39}\z/D',$client)!==1)
                    ||!in_array($conflict,[0,1,'0','1'],true)||!in_array($click,[0,1,'0','1'],true)){
                    $flags['invalid_history']=true;continue;
                }
                if($visit>$at){$flags['future_visit']=true;continue;}
                $flags['prior_visit']=true;
                $within=$at-$visit<=21*86400;
                if($within)$flags['prior_visit_21d']=true;
                if($observed<=$at&&$received<=$at)$flags['prior_visit_available_at_message']=true;
                if($observed>$at)$flags['late_observed']=true;
                if($received>$at)$flags['late_imported']=true;
                if($client!==''){$prior[$client]=true;if($within)$window[$client]=true;}
                if((int)$click===1){$priorClick=true;if($within)$windowClick=true;}
                if($client!==''&&(int)$click===1){$priorPair=true;if($within)$windowPair=true;}
            }
            $usable=!$flags['identity_conflict']&&!$flags['invalid_history'];
            $flags['verified_prior_match']=$usable&&$flags['prior_visit'];
            $flags['verified_prior_match_21d']=$usable&&$flags['prior_visit_21d'];
            $flags['unique_client_id']=$usable&&count($prior)===1;
            $flags['multiple_client_ids']=count($prior)>1;
            $flags['client_id_missing']=$usable&&$flags['prior_visit']&&$prior===[];
            $flags['click_present']=$priorClick;
            $flags['same_observation_pair']=$flags['unique_client_id']&&$priorPair;
            $flags['window_21d_unique_client_id']=$usable&&count($window)===1;
            $flags['window_21d_multiple_client_ids']=count($window)>1;
            $flags['window_21d_click_present']=$windowClick;
            $flags['window_21d_same_observation_pair']=$flags['window_21d_unique_client_id']&&$windowPair;
            $flags['no_prior_visit']=!$flags['invalid_history']&&!$flags['prior_visit'];
            foreach($flags as$name=>$yes)if($yes){$counts[$name]++;$peers[$name][$id]=true;}
        }
        return ['events'=>$counts,'unique_peers'=>array_map('count',$peers),
            'candidate_history_complete'=>true,'candidate_observation_rows'=>array_sum(array_map('count',$history)),
            'candidate_history_scope'=>'current_saved_rows_for_selected_peers_not_full_source_history',
            'metrics_overlap'=>true,'event_unit'=>'selected_verified_inbound_record',
            'peer_unit'=>'telegram_peer_not_unique_person','window_days'=>21,
            'clock_basis'=>'visit_not_later_than_message; equality_is_not_causality',
            'identity_basis'=>'current_saved_verified_history; late_enrichment_is_separate',
            'prior_visit_basis'=>'candidate_presence; verified_prior_match_excludes_conflicts_and_invalid_history',
            'click_basis'=>'valid_click_presence_only; click_uniqueness_not_established',
            'attribution_decision'=>false];
    }

    private static function unix(mixed $value,int $now):?int
    {
        if(!is_int($value)&&(!is_string($value)||preg_match('/\A[1-9][0-9]{0,11}\z/D',$value)!==1))return null;
        $n=(int)$value;return $n>=946684800&&$n<=$now?$n:null;
    }
}
