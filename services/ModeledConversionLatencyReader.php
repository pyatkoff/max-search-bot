<?php
declare(strict_types=1);
require_once __DIR__.'/ModeledConversionStatusBudget.php';
require_once __DIR__.'/ModeledConversionValueContract.php';

/** Private timing of stored metadata only; never executes capture, prediction or delivery. */
final class ModeledConversionLatencyReader
{
    public const JOURNAL_LIMIT=2001;
    public const VALUE_LIMIT=201;
    private bool $mysql;
    public function __construct(private PDO $pdo,private $authorize,private ModeledConversionStatusBudget $budget)
    {$this->mysql=$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql';}

    public function read(int $now):array
    {
        $this->auth();
        $day=(new DateTimeImmutable('@'.$now))->setTimezone(new DateTimeZone('Europe/Moscow'))->setTime(0,0);
        $since=$day->getTimestamp();
        try{
            $journal="SELECT id,source_id,version_no,observed_at_unix,snapshot_json FROM modeled_conversion_stream_versions WHERE project_key='anytour' AND source_kind='message' AND observed_at_unix>=".$since." AND observed_at_unix<=".$now." ORDER BY id DESC LIMIT ".self::JOURNAL_LIMIT;
            $safe="CASE WHEN JSON_VALID(j.snapshot_json) THEN j.snapshot_json ELSE '{}' END";
            $get=fn(string $path):string=>$this->scalar($safe,$path);
            $created=$get('source_created_at_unix');
            $integer=$this->mysql?"JSON_TYPE(JSON_EXTRACT(".$safe.",'$.source_created_at_unix'))='INTEGER'":"json_type(".$safe.",'$.source_created_at_unix')='integer'";
            $base="SELECT j.id,j.source_id,j.observed_at_unix AS captured,CASE WHEN ".$integer." THEN CAST(".$created." AS ".($this->mysql?'SIGNED':'INTEGER').") ELSE NULL END AS persisted,"
                .$get('platform')." AS platform,".$get('customer_key')." AS customer_key,".$get('conversation_id')." AS conversation_id"
                ." FROM (".$journal.") j WHERE j.version_no=1 AND ".$get('project_key')."='anytour' AND ".$get('source_status')."='present'"
                ." AND ".$get('platform')." IN ('max','telegram') AND ".$get('payload.direction')."='inbound' AND ".$get('payload.sender_type')."='customer'";
            $valid="persisted>=".$since." AND persisted<=captured";
            $columns=['COUNT(*) AS initial_customer_message_rows',
                "COALESCE(SUM(CASE WHEN ".$valid." THEN 1 ELSE 0 END),0) AS current_rows",
                "COUNT(DISTINCT CASE WHEN ".$valid." THEN conversation_id ELSE NULL END) AS current_conversations",
                "COALESCE(SUM(CASE WHEN persisted<".$since." THEN 1 ELSE 0 END),0) AS older_source_rows",
                "COALESCE(SUM(CASE WHEN persisted IS NULL OR persisted>captured THEN 1 ELSE 0 END),0) AS invalid_clock_rows",
                "(SELECT COUNT(*) FROM (".$journal.") counted_journal) AS inspected_versions"];
            foreach(['max','telegram']as$platform)$columns[]="COUNT(DISTINCT CASE WHEN ".$valid." AND platform='".$platform."' THEN conversation_id ELSE NULL END) AS ".$platform."_conversations";
            $columns=array_merge($columns,$this->delayColumns('persistence_to_capture','captured-persisted',$valid));
            $r=$this->query('SELECT '.implode(',',$columns).' FROM ('.$base.') messages')[0];
            $out=['available'=>true,'date'=>$day->format('Y-m-d'),'timezone'=>'Europe/Moscow',
                'basis'=>'server_persistence_and_immutable_capture_profile_metadata_not_device_send_time',
                'window'=>['max_message_versions'=>self::JOURNAL_LIMIT,'inspected_message_versions'=>$this->count($r['inspected_versions']),
                    'complete_within_bound'=>(int)$r['inspected_versions']<self::JOURNAL_LIMIT],
                'messages'=>['first_customer_message_rows'=>$this->count($r['initial_customer_message_rows']),
                    'persisted_today'=>$this->count($r['current_rows']),'conversations'=>$this->count($r['current_conversations']),
                    'older_source_first_captured_today'=>$this->count($r['older_source_rows']),'invalid_clock_rows'=>$this->count($r['invalid_clock_rows'])],
                'by_platform'=>[],'profile_sample'=>['max_scopes'=>32,'scopes_inspected'=>0,'selection'=>'latest_first_customer_message_per_recent_distinct_scope',
                    'complete_within_captured_window'=>(int)$r['current_conversations']<=32,'waiting_for_profile'=>0,'oldest_waiting_seconds'=>null,
                    'invalid_scope_rows'=>0,'recorded_prediction_status'=>array_fill_keys(['predicted','insufficient_context','known_outcome','access_denied','unknown'],0)],
                'delays'=>['persistence_to_capture'=>$this->delay($r,'persistence_to_capture')],'examples'=>[]];
            foreach(['max','telegram']as$platform)$out['by_platform'][$platform]=['conversations'=>$this->count($r[$platform.'_conversations'])];
            // Aggregate capture counters need no profile join. Only distinct recent scopes
            // use parameter-bound indexed lookups; JSON collations cannot force a global scan.
            $ranked="SELECT b.*,ROW_NUMBER() OVER(PARTITION BY platform,customer_key,conversation_id ORDER BY id DESC) AS scope_row FROM (".$base.") b WHERE ".$valid;
            // Compact wire aliases avoid charging repeated field names for every sampled row.
            // Restore the original internal shape before validation, bindings or timing arithmetic.
            $sample=array_map(static fn(array $r):array=>['id'=>$r['i'],'platform'=>$r['p'],'customer_key'=>$r['k'],
                'conversation_id'=>$r['c'],'persisted'=>$r['t'],'captured'=>$r['u']],
                $this->query("SELECT id AS i,platform AS p,customer_key AS k,conversation_id AS c,persisted AS t,captured AS u FROM (".$ranked.") latest"
                ." WHERE scope_row=1 ORDER BY captured DESC,id DESC LIMIT 32"));
            $profileSafe="CASE WHEN JSON_VALID(profile_json) THEN profile_json ELSE '{}' END";
            $status=$this->scalar($profileSafe,'semantic_prediction.status');$stage=$this->scalar($profileSafe,'semantic_prediction.stage');
            $profileSql="SELECT observed_at_unix AS s,CASE WHEN ".$status." IN ('predicted','insufficient_context','known_outcome','access_denied') THEN ".$status." ELSE 'unknown' END AS q,"
                ."CASE WHEN ".$stage." IN ('substantive_dialogue','concrete_request','exact_offer_discussion','general_purchase_intent','agency_purchase_intent') THEN ".$stage." ELSE NULL END AS g"
                ." FROM modeled_conversion_profile_versions WHERE project_key='anytour' AND platform=? AND customer_key=? AND conversation_id=?"
                ." AND journal_cursor>=? AND observed_at_unix>=? AND observed_at_unix<=".$now." ORDER BY id LIMIT 1";
            // One bounded statement preserves each scope's indexed lookup and avoids repeating
            // the same budgeted authorization for every selected profile.
            $terms=[];$bindings=[];$validScopes=[];
            foreach($sample as$index=>$meta){
                $valid=true;foreach(['customer_key','conversation_id']as$key)if(preg_match('/\\A[1-9][0-9]{0,19}\\z/D',(string)$meta[$key])!==1)$valid=false;
                if(!$valid)continue;
                $validScopes[$index]=true;
                $terms[]='SELECT '.$index.' AS i,selected_profile.* FROM ('.$profileSql.') selected_profile';
                array_push($bindings,$meta['platform'],(string)$meta['customer_key'],(string)$meta['conversation_id'],(int)$meta['id'],(int)$meta['captured']);
            }
            $profiles=[];
            if($terms)foreach($this->query(implode(' UNION ALL ',$terms),$bindings)as$profile)$profiles[(int)$profile['i']]=[
                'sample_index'=>$profile['i'],'saved'=>$profile['s'],'prediction_status'=>$profile['q'],'prediction_stage'=>$profile['g']];
            $profileDelays=[];$totalDelays=[];$examples=[];
            foreach($sample as$index=>$meta){
                $out['profile_sample']['scopes_inspected']++;
                if(!isset($validScopes[$index])){$out['profile_sample']['invalid_scope_rows']++;continue;}
                $p=$profiles[$index]??null;
                if($p===null){
                    $out['profile_sample']['waiting_for_profile']++;
                    $age=$now-(int)$meta['captured'];
                    $out['profile_sample']['oldest_waiting_seconds']=max($out['profile_sample']['oldest_waiting_seconds']??0,$age);
                }else{
                    $out['profile_sample']['recorded_prediction_status'][$p['prediction_status']]++;
                    $profileDelays[]=(int)$p['saved']-(int)$meta['captured'];
                    $totalDelays[]=(int)$p['saved']-(int)$meta['persisted'];
                }
                $entry=['platform'=>$meta['platform'],'persisted_at'=>$this->at($meta['persisted']),
                    'captured_at'=>$this->at($meta['captured']),'profile_saved_at'=>$this->at($p['saved']??null),
                    'capture_delay_seconds'=>$this->difference($meta['captured'],$meta['persisted']),
                    'profile_queue_delay_seconds'=>$this->difference($p['saved']??null,$meta['captured']),
                    'total_profile_delay_seconds'=>$this->difference($p['saved']??null,$meta['persisted']),
                    'recorded_prediction_status'=>$p['prediction_status']??'unknown','recorded_prediction_stage'=>$p['prediction_stage']??null];
                $examples[]=['journal_id'=>(string)$meta['id'],'missing'=>$p===null?1:0,'delay'=>$entry['total_profile_delay_seconds']??$now-(int)$meta['persisted'],'entry'=>$entry];
            }
            $recent=$examples; // Sample rows already arrive newest captured first, one per scope.
            usort($examples,static fn(array $a,array $b):int=>($b['missing']<=>$a['missing'])?:($b['delay']<=>$a['delay']));
            $selected=array_slice($examples,0,3);
            foreach($recent as$example){
                if(count($selected)>=6)break;
                if(!in_array($example,$selected,true))$selected[]=$example;
            }
            foreach($selected as$example)$out['examples'][]=$example['entry'];
            $out['example_selection']='up_to_three_slow_or_pending_then_recent_distinct_scopes';
            $out['delays']['capture_to_profile']=$this->sampleDelay($profileDelays);
            $out['delays']['persistence_to_profile']=$this->sampleDelay($totalDelays);
            $out['live_value']=$this->values($since,$now);
            $out['interpretation']=['edits_not_timed_from_original_creation'=>true,'captured_suffix_not_all_native_messages'=>true,
                'profile_delay_is_a_bounded_recent_distinct_scope_sample'=>true,
                'profile_saved_is_not_proof_each_message_was_admitted_as_a_feature'=>true,
                'recorded_status_is_not_new_semantic_validation'=>true,'missing_profile_does_not_prove_queued_processing'=>true,
                'historical_value_review_excluded'=>true,'upload_time_is_existing_claim_tick_not_exact_api_response_time'=>true,
                'api_receipt_processed_and_visits_are_separate'=>true,'saved_hold_reason_is_not_a_current_eligibility_check'=>true,
                'visit_unverified_combines_absent_visit_and_unavailable_report'=>true,
                'next_check_not_before_is_not_a_scheduled_execution_promise'=>true,'no_ids_or_text_returned'=>true];
            $this->auth();return $out;
        }catch(ModeledConversionStatusLimit $e){throw $e;}
        catch(PDOException|UnexpectedValueException $ignored){$this->auth();return ['available'=>false,'reason'=>'today_latency_storage_or_clock_unavailable'];}
    }

    private function sampleDelay(array $values):array
    {
        $out=['basis'=>'latest_message_per_sampled_distinct_scope','observations'=>count($values),
            'minimum_seconds'=>$values?(float)min($values):null,'average_seconds'=>$values?round(array_sum($values)/count($values),1):null,
            'maximum_seconds'=>$values?(float)max($values):null];
        foreach([60,300,600,900]as$bound)$out['within_'.$bound.'_seconds']=count(array_filter($values,static fn(int $v):bool=>$v<=$bound));
        return $out;
    }

    private function values(int $since,int $now):array
    {
        $queued=$this->mysql?'UNIX_TIMESTAMP(o.created_at)':"CAST(strftime('%s',o.created_at) AS INTEGER)";
        $base="SELECT o.id,o.platform,o.state,o.value_stage,o.value_rub,o.claimed_at_unix,o.upload_id,o.upload_status,o.last_error,o.next_attempt_unix,"
            ."CASE WHEN o.client_id IS NOT NULL AND o.client_id<>'' THEN 1 ELSE 0 END AS identity_present,"
            .$queued." AS queued,p.observed_at_unix AS saved FROM max_channel_conversion_outbox o"
            ." JOIN modeled_conversion_profile_versions p ON p.id=o.value_profile_id AND p.project_key=o.project_key AND p.platform=o.platform AND p.conversation_id=o.conversation_id"
            ." WHERE o.project_key='anytour' AND o.counter_id='98615635' AND o.transport_target='anytour_modeled_value_v1'"
            ." AND o.value_contract='semantic_stage_value_25000_v1' AND o.value_origin IS NULL AND o.event_unix>=".$since." AND o.event_unix<=".$now
            ." ORDER BY o.id DESC LIMIT ".self::VALUE_LIMIT;
        $cols=['COUNT(*) AS events','COALESCE(SUM(value_rub),0) AS value_rub',
            "COALESCE(SUM(CASE WHEN upload_id IS NOT NULL AND upload_id<>'' THEN 1 ELSE 0 END),0) AS accepted_events",
            "COALESCE(SUM(CASE WHEN upload_id IS NOT NULL AND upload_id<>'' THEN value_rub ELSE 0 END),0) AS accepted_rub",
            "COALESCE(SUM(CASE WHEN state='processed' THEN 1 ELSE 0 END),0) AS processed_events",
            "COALESCE(SUM(CASE WHEN identity_present=0 THEN 1 ELSE 0 END),0) AS unresolved_identity_events",
            "COALESCE(SUM(CASE WHEN state='ready' THEN 1 ELSE 0 END),0) AS ready_events",
            "MAX(CASE WHEN state='ready' AND queued<=".$now." THEN ".$now."-queued ELSE NULL END) AS oldest_ready_seconds"];
        $cols=array_merge($cols,$this->delayColumns('profile_to_queue','queued-saved','queued>=saved AND queued<='.$now));
        $floor=fn(string $v):string=>$this->mysql?'FLOOR('.$v.'/300)':'CAST('.$v.'/300 AS INTEGER)';
        $cycles=$floor('claimed_at_unix').'-'.$floor('saved');
        $outcome="upload_id IS NOT NULL AND upload_id<>'' AND claimed_at_unix>0 AND claimed_at_unix<=".$now." AND ".$cycles.">=0";
        $cols[]="COALESCE(SUM(CASE WHEN ".$outcome." AND ".$cycles."=0 THEN 1 ELSE 0 END),0) AS same_tick";
        $cols[]="COALESCE(SUM(CASE WHEN ".$outcome." AND ".$cycles.">0 THEN 1 ELSE 0 END),0) AS later_tick";
        $cols[]="MAX(CASE WHEN ".$outcome." THEN ".$cycles." ELSE NULL END) AS max_tick_intervals";
        $r=$this->query('SELECT '.implode(',',$cols).' FROM ('.$base.') live_values')[0];
        $out=['max_rows'=>self::VALUE_LIMIT,'events'=>$this->count($r['events']),'complete_within_bound'=>(int)$r['events']<self::VALUE_LIMIT,
            'value_rub'=>$this->count($r['value_rub']),'api_accepted_events'=>$this->count($r['accepted_events']),
            'api_accepted_value_rub'=>$this->count($r['accepted_rub']),'processed_events'=>$this->count($r['processed_events']),
            'unresolved_identity_events'=>$this->count($r['unresolved_identity_events']),'ready_events'=>$this->count($r['ready_events']),
            'oldest_ready_seconds'=>$this->nullable($r['oldest_ready_seconds']),'profile_to_queue_seconds'=>$this->delay($r,'profile_to_queue'),
            'accepted_upload_claim_ticks'=>['same_five_minute_interval'=>$this->count($r['same_tick']),
                'later_interval'=>$this->count($r['later_tick']),'maximum_intervals'=>$this->nullable($r['max_tick_intervals'])],'examples'=>[]];
        $rows=$this->query("SELECT platform,state,value_stage,value_rub,saved,queued,claimed_at_unix,identity_present,last_error,next_attempt_unix,"
            ."CASE WHEN upload_id IS NOT NULL AND upload_id<>'' THEN 1 ELSE 0 END AS api_receipt FROM (".$base.") examples ORDER BY id DESC LIMIT 6");
        foreach($rows as$e)$out['examples'][]=['platform'=>$e['platform'],'stage'=>in_array($e['value_stage'],ModeledConversionValueContract::STAGES,true)?$e['value_stage']:null,'increment_rub'=>$this->count($e['value_rub']),
            'profile_saved_at'=>$this->at($e['saved']),'value_queued_at'=>$this->at($e['queued']),
            'upload_claim_tick_at'=>$this->at($e['claimed_at_unix']),'state'=>in_array($e['state'],['pending','ready','uploading','accepted','processed','unknown','failed','expired','unmatched'],true)?$e['state']:'unrecognized_state',
            'identity_resolved'=>(int)$e['identity_present']===1,'api_receipt_present'=>(int)$e['api_receipt']===1,
            'last_recorded_hold_reason'=>in_array($e['last_error'],['identity_not_found','identity_ambiguous','metrika_visit_window','metrika_visit_unverified','wire_key_collision','scope_unavailable','event_expired'],true)?$e['last_error']:null,
            'next_check_not_before'=>$this->at($e['next_attempt_unix']),
            'overdue_for_saved_check_seconds'=>in_array($e['state'],['pending','ready'],true)&&(int)$e['next_attempt_unix']>=946684800&&(int)$e['next_attempt_unix']<=$now?$now-(int)$e['next_attempt_unix']:null];
        return $out;
    }
    private function delayColumns(string $key,string $value,string $condition):array
    {
        $out=["COALESCE(SUM(CASE WHEN ".$condition." THEN 1 ELSE 0 END),0) AS ".$key."_n",
            "MIN(CASE WHEN ".$condition." THEN ".$value." ELSE NULL END) AS ".$key."_min",
            "AVG(CASE WHEN ".$condition." THEN ".$value." ELSE NULL END) AS ".$key."_avg",
            "MAX(CASE WHEN ".$condition." THEN ".$value." ELSE NULL END) AS ".$key."_max"];
        foreach([60,300,600,900]as$bound)$out[]="COALESCE(SUM(CASE WHEN ".$condition." AND ".$value."<=".$bound." THEN 1 ELSE 0 END),0) AS ".$key."_le".$bound;
        return $out;
    }
    private function delay(array $r,string $key):array
    {
        $out=['observations'=>$this->count($r[$key.'_n']),'minimum_seconds'=>$this->nullable($r[$key.'_min']),
            'average_seconds'=>$this->nullable($r[$key.'_avg']),'maximum_seconds'=>$this->nullable($r[$key.'_max'])];
        foreach([60,300,600,900]as$bound)$out['within_'.$bound.'_seconds']=$this->count($r[$key.'_le'.$bound]);
        return $out;
    }
    private function scalar(string $safe,string $path):string
    {
        $s="JSON_EXTRACT(".$safe.",'$.".$path."')";return $this->mysql?'JSON_UNQUOTE('.$s.')':$s;
    }
    private function count(mixed $n):int
    {
        if(!is_numeric($n)||!is_finite((float)$n)||(float)$n<0||(float)$n>1000000000||(float)$n!==floor((float)$n))throw new UnexpectedValueException();
        return (int)$n;
    }
    private function nullable(mixed $n):int|float|null
    {
        if($n===null)return null;
        if(!is_numeric($n)||!is_finite((float)$n)||(float)$n<0||(float)$n>1000000000)throw new UnexpectedValueException();
        return round((float)$n,1);
    }
    private function difference(mixed $later,mixed $earlier):int|null
    {return is_numeric($later)&&is_numeric($earlier)&&(int)$later>=(int)$earlier?(int)$later-(int)$earlier:null;}
    private function at(mixed $n):?string
    {return is_numeric($n)&&(int)$n>=946684800?gmdate('Y-m-d\\TH:i:s\\Z',(int)$n):null;}
    private function query(string $sql,array $args=[]):array
    {
        $this->auth();$q=$this->budget->prepare($this->pdo,$sql);foreach($args as$i=>$v)$q->bindValue($i+1,$v,is_int($v)?PDO::PARAM_INT:PDO::PARAM_STR);$q->execute();$rows=$q->fetchAll(PDO::FETCH_ASSOC);$q->closeCursor();
        $this->budget->consumeBytes(strlen(json_encode($rows,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)));$this->auth();return $rows;
    }
    private function auth():void{$this->budget->check();($this->authorize)();$this->budget->check();}
}
