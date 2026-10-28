<?php
declare(strict_types=1);

require_once __DIR__.'/ConversationRecordClock.php';
require_once __DIR__.'/InternalOwnerDialogueScope.php';
require_once __DIR__.'/DiagnosticLogger.php';

/** Bounded prospective supplier for the existing admitted-journal cron. */
final class ModeledConversionPurchaseStudy
{
    public const VERSION='purchase_study_supplier_v1';
    private const STATE_BYTES=1048576;
    private const FILE_BYTES=2097152;
    private const RAW_BYTES=1048576;
    private PDO $pdo;
    private bool $mysql;
    private string $directory;
    private int $deadline=0;
    private int $rows=0;
    private int $bytes=0;
    private bool $packetBudgetPaused=false;
    private array $counts=[];
    private const COUNTERS=['profile_metadata_rows','profile_packets_read','entry_only_profiles','already_selected_profiles',
        'pre_protocol_profiles','missing_or_invalid_packets','oversize_packets','non_new_lead_profiles',
        'denied_profiles','profile_page_limited','scope_limit_reached','outcome_rows','invalid_outcome_rows',
        'outcome_page_limited','budget_paused','canonical_people_checked','metadata_rows','raw_bytes'];
    private const SUMMARY=['version','target','protocol_sha256','cases','excluded_people','train_people','train_resolved',
        'control_people','control_resolved','unknown_people','model_fitted','model_invalidated','predictions',
        'comparison_available','paired_resolved_people','conditional_score_advantage','paired_brier_delta','capacity_paused',
        'coverage_is_admitted_journal_only','verified_payment_probability','all_lead_calibration_ready','conversion_uploads','deferred_updates','training_revalidation_pending'];

    public function __construct(PDO $pdo,?string $directory=null)
    {
        $this->pdo=$pdo;$this->mysql=$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql';
        if($directory===null){$log=DiagnosticLogger::filePath();if($log==='')self::fail('private_path');$directory=dirname($log).'/purchase-study';}
        $this->directory=$directory;
    }

    /** Pure registry inspection: no registration, source queries, or model execution. */
    public function status():array
    {
        try{
            if(!$this->directory(false))return self::result('unregistered');
            if(@lstat($this->directory.'/state.json')===false)return self::result('unregistered');
            $lock=$this->lock(LOCK_SH);
            if($lock===null)return self::result('busy');
            try{$saved=$this->read();return $saved===null?self::result('unregistered'):self::result('registered')+$saved['receipt'];}
            finally{flock($lock,LOCK_UN);fclose($lock);}
        }catch(Throwable $ignored){return self::result('failed');}
    }

    /** Private administrator projection only; never used by ordinary tick telemetry. */
    public function report():array
    {
        $empty=['registered_at_utc'=>null,'horizon_days'=>30,'first_window_matures_at_utc'=>null,
            'comparison_status'=>'not_available','comparison'=>null];
        try{
            if(!$this->directory(false)||@lstat($this->directory.'/state.json')===false)return self::result('unregistered')+$empty;
            $lock=$this->lock(LOCK_SH);if($lock===null)return self::result('busy')+$empty;
            try{
                $saved=$this->read();if($saved===null)return self::result('unregistered')+$empty;$state=$saved['state'];$first=null;
                foreach($state['cases']as$case){
                    $packet=$case['packet'];$group=$packet['person_group']??'';
                    if(isset($state['exclusions'][$group])||!is_array($case['admission']??null))continue;
                    $proof=$case['admission'];
                    if(($proof['packet_sha256']??null)!==($packet['sha256']??null)||($proof['prior_conversations']??null)!==0
                        ||($proof['earlier_profiles']??null)!==0||($proof['exposure']??null)!=='unseen')continue;
                    $end=self::instant($packet['frozen_at'])+30*86400;$first=$first===null?$end:min($first,$end);
                }
                $pending=$saved['receipt']['training_revalidation_pending'];
                $comparison=null;$stateName=($state['model_invalidated']??false)?'model_invalidated':($pending?'training_revalidation_pending':'not_available');
                if(($state['model_invalidated']??true)===false&&$pending===0&&$saved['receipt']['comparison_available']===1&&is_array($state['model']??null)&&is_array($state['evaluation']??null)){
                    $comparison=$this->evaluation($state['evaluation'],$state['model'],$state['protocol_sha256']);$stateName='available';
                }
                return self::result('registered')+$saved['receipt']+['registered_at_utc'=>$state['manifest']['registered_at'],
                    'horizon_days'=>30,'first_window_matures_at_utc'=>$first===null?null:gmdate('Y-m-d\TH:i:s\Z',$first),
                    'comparison_status'=>$stateName,'comparison'=>$comparison];
            }finally{flock($lock,LOCK_UN);fclose($lock);}
        }catch(Throwable $ignored){return self::result('failed')+$empty;}
    }

    private function evaluation(array $value,array $model,string $protocol):array
    {
        if(($value['kind']??null)!=='paired_evaluation'||($value['version']??null)!=='inquiry_outcome_study_v1'
            ||($model['kind']??null)!=='paired_model'||($model['version']??null)!=='inquiry_outcome_study_v1'
            ||!is_string($model['sha256']??null)||preg_match('/\A[a-f0-9]{64}\z/D',$model['sha256'])!==1
            ||($value['target']??null)!=='manager_recorded_inquiry_outcome_30d_v1'||($value['protocol_sha256']??null)!==$protocol
            ||($value['model_sha256']??null)!==($model['sha256']??null)||($value['evaluation_population']??null)!=='same_resolved_conditional_subset'
            ||($value['full_buyer_quality_ready']??true)!==false||!array_key_exists('purchase_probability',$value)||$value['purchase_probability']!==null
            ||($value['export_allowed']??true)!==false)self::fail('evaluation_contract');
        $at=$value['evaluated_at']??'';if(!is_string($at)||self::instant($at)>time())self::fail('evaluation_clock');
        $baseline=$this->scores($value['baseline']??[]);$conditions=$this->scores($value['conditions']??[]);
        if($baseline['n']!==$conditions['n']||$baseline['positives']!==$conditions['positives'])self::fail('evaluation_population');
        $control=$value['control_people']??null;$paired=$value['paired_resolved_people']??null;$unknown=$value['unknown_or_censored_people']??null;
        if(!is_int($control)||!is_int($paired)||!is_int($unknown)||$control<0||$control>256||$paired!==$baseline['n']||$unknown!==$control-$paired)self::fail('evaluation_population');
        $delta=[];$intervals=[];
        foreach(['brier','log_loss','precision_at_k']as$key){
            $limit=$key==='log_loss'?100:1;
            $d=self::reportNumber($value['paired_delta_conditions_minus_baseline'][$key]??null,-$limit,$limit);
            if(abs($d-($conditions[$key]-$baseline[$key]))>1e-10)self::fail('evaluation_delta');
            $low=self::reportNumber($value['paired_person_bootstrap'][$key]['lower']??null,-$limit,$limit);
            $high=self::reportNumber($value['paired_person_bootstrap'][$key]['upper']??null,-$limit,$limit);
            if($low>$high)self::fail('evaluation_interval');$delta[$key]=$d;$intervals[$key]=['lower'=>$low,'upper'=>$high];
        }
        $advantage=$intervals['brier']['upper']<0&&$intervals['log_loss']['upper']<0;
        return['evaluated_at_utc'=>$at,'control_people'=>$control,'paired_resolved_people'=>$paired,'unknown_or_censored_people'=>$unknown,
            'baseline'=>$baseline,'conditions'=>$conditions,'paired_delta_conditions_minus_baseline'=>$delta,
            'paired_person_bootstrap'=>$intervals,'confidence'=>0.95,'population'=>'same_resolved_conditional_subset',
            'comparison_conclusion'=>$advantage?'conditional_score_advantage':'conditional_advantage_not_established',
            'runtime_provenance_requires_independent_verification'=>true,'full_buyer_quality_ready'=>false];
    }

    private function scores(array $value):array
    {
        $n=$value['n']??null;$positive=$value['positives']??null;$top=$value['top_k']??null;
        if(!is_int($n)||$n<1||$n>256||!is_int($positive)||$positive<0||$positive>$n||!is_int($top)||$top!==max(1,(int)ceil($n*0.25))
            ||!is_array($value['calibration']??null)||count($value['calibration'])!==5)self::fail('evaluation_scores');
        $bins=[];$seen=[];$total=0;
        foreach($value['calibration']as$bin){
            if(!is_array($bin))self::fail('evaluation_bins');
            $id=$bin['bin']??null;$count=$bin['n']??null;
            if(!is_int($id)||$id<0||$id>4||isset($seen[$id])||!is_int($count)||$count<0||$count>$n)self::fail('evaluation_bins');
            $seen[$id]=true;$total+=$count;
            $mean=$bin['predicted_mean']??null;$rate=$bin['observed_rate']??null;
            if($count===0){if($mean!==null||$rate!==null)self::fail('evaluation_bins');}
            else{$mean=self::reportNumber($mean,0,1);$rate=self::reportNumber($rate,0,1);}
            $bins[]=['bin'=>$id,'n'=>$count,'predicted_mean'=>$mean,'observed_rate'=>$rate];
        }
        if($total!==$n)self::fail('evaluation_bins');
        return['n'=>$n,'positives'=>$positive,'brier'=>self::reportNumber($value['brier']??null,0,1),
            'log_loss'=>self::reportNumber($value['log_loss']??null,0,100),'top_k'=>$top,
            'precision_at_k'=>self::reportNumber($value['precision_at_k']??null,0,1),'calibration'=>$bins];
    }
    private static function reportNumber($value,float $low,float $high):float
    {
        if((!is_float($value)&&!is_int($value))||!is_finite((float)$value)||$value<$low||$value>$high)self::fail('evaluation_number');return(float)$value;
    }

    /** Best effort, and never a transaction owner for caller/customer work. */
    public function tick(int $now):array
    {
        $lock=null;
        try{
            if($this->pdo->inTransaction()||$now<946684800||$now>time()||time()-$now>60)self::fail('caller_context');
            $this->directory(true);$lock=$this->lock(LOCK_EX);
            if($lock===null)return self::result('busy');
            $saved=$this->read();$state=$saved['state']??null;
            $this->rows=0;$this->bytes=0;$this->packetBudgetPaused=false;$this->counts=array_fill_keys(self::COUNTERS,0);
            $request=$this->collect($state,$saved['state_json']??null);
            if($request===null)return self::result('off');
            $reply=$this->execute($request);
            if(!is_array($reply)||array_keys($reply)!==['state_json','summary']){
                // JSON member ordering is immaterial; only these two members exist.
                if(!is_array($reply)||count($reply)!==2||!array_key_exists('state_json',$reply)||!array_key_exists('summary',$reply))self::fail('bridge_contract');
            }
            if(!is_string($reply['state_json'])||strlen($reply['state_json'])>self::STATE_BYTES)self::fail('state_size');
            $next=$this->decodeState($reply['state_json']);
            if(self::compare($next['profile_cursor'],$request['profile_cursor'])>0
                ||self::compare($next['profile_cursor'],$state['profile_cursor']??'0')<0)self::fail('bridge_cursor');
            $summary=$this->summary($reply['summary']);
            if($summary['protocol_sha256']!==$next['protocol_sha256'])self::fail('bridge_protocol');
            $this->counts['metadata_rows']=$this->rows;$this->counts['raw_bytes']=$this->bytes;
            $receipt=$summary+$this->counts+['observed_at_utc'=>gmdate('Y-m-d\TH:i:s\Z')];
            $this->write($reply['state_json'],$receipt);
            return self::result($state===null?'registered':'advanced')+$receipt;
        }catch(Throwable $ignored){
            try{DiagnosticLogger::log('modeled_purchase_study','runtime_failed',['coverage'=>'unknown'],null,'warning');}catch(Throwable $ignoredLog){}
            return self::result('failed');
        }finally{if(is_resource($lock)){flock($lock,LOCK_UN);fclose($lock);}}
    }

    private function collect(?array $state,?string $rawState):?array
    {
        $this->deadline=hrtime(true)+5000000000;
        if($this->mysql){$this->pdo->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');$this->pdo->exec('SET TRANSACTION READ ONLY');}
        $this->pdo->beginTransaction();
        try{
            $profiles=$this->query("SELECT enabled,journal_cursor FROM modeled_conversion_profiles_state WHERE project_key='anytour'");
            $stream=$this->query("SELECT enabled,last_receipt_json FROM modeled_conversion_stream_state WHERE project_key='anytour'");
            if(count($profiles)!==1||count($stream)!==1||(string)$profiles[0]['enabled']!=='1'||(string)$stream[0]['enabled']!=='1')return null;
            $fence=self::id($this->query("SELECT COALESCE(MAX(id),0) AS fence FROM modeled_conversion_profile_versions WHERE project_key='anytour'")[0]['fence']);
            $journalFence=self::id($this->query("SELECT COALESCE(MAX(id),0) AS fence FROM modeled_conversion_stream_versions WHERE project_key='anytour'")[0]['fence']);
            $request=['operation'=>$state===null?'init':'advance','state_json'=>$rawState,'now'=>'',
                'reader_sha256'=>self::readerSha(),'profile_fence'=>$fence,
                'profile_cursor'=>$state===null?$fence:$state['profile_cursor'],'profiles'=>[],'updates'=>[]];
            if($state===null){$request['now']=gmdate('Y-m-d\TH:i:s\Z');return$request;}
            $registered=self::instant($state['manifest']['registered_at']);$at=time();
            $seen=[];foreach($state['cases']as$case){$s=self::scope($case['packet']['scope']);$seen[$s['customer_key']]=true;}
            $page=$this->query("SELECT id,platform,customer_key,conversation_id,observed_at_unix FROM modeled_conversion_profile_versions WHERE project_key='anytour' AND id>? AND id<=? ORDER BY id LIMIT 40",[$state['profile_cursor'],$fence]);
            $this->counts['profile_metadata_rows']=count($page);$this->counts['profile_page_limited']=(int)(count($page)===40);
            $selected=[];$touched=[];$newLimit=$state['cases']?2:4;
            foreach($page as$meta){
                $id=self::id($meta['id']);$observed=(int)$meta['observed_at_unix'];
                if($observed<$registered||$observed>$at){$this->counts['pre_protocol_profiles']++;$request['profile_cursor']=$id;continue;}
                try{$s=self::scope(['project_key'=>'anytour','platform'=>$meta['platform'],'customer_key'=>(string)$meta['customer_key'],'conversation_id'=>(string)$meta['conversation_id']]);}
                catch(Throwable $ignored){$this->counts['denied_profiles']++;$request['profile_cursor']=$id;continue;}
                if(isset($seen[$s['customer_key']])||isset($selected[$s['customer_key']])){$this->counts['already_selected_profiles']++;$request['profile_cursor']=$id;continue;}
                if(!isset($touched[$s['customer_key']])&&count($touched)>=$newLimit){$this->counts['scope_limit_reached']=1;break;}
                $touched[$s['customer_key']]=true;
                $native=$this->native($s);
                if($native===null){$this->counts['denied_profiles']++;$request['profile_cursor']=$id;continue;}
                $created=$native['created_unix'];
                $prior=$this->query('SELECT 1 AS present FROM conversations WHERE customer_id=? AND id<>? LIMIT 1',[$s['customer_key'],$s['conversation_id']]);
                $old=$this->query("SELECT 1 AS present FROM modeled_conversion_profile_versions WHERE project_key='anytour' AND customer_key=? AND id<? AND observed_at_unix<? LIMIT 1",[$s['customer_key'],$id,$registered]);
                if($created===null||$created<=$registered||$created>$observed||$prior||$old){$this->counts['non_new_lead_profiles']++;$request['profile_cursor']=$id;continue;}
                $safe=$this->safe('profile_json');$activity=$this->mysql?"JSON_UNQUOTE(JSON_EXTRACT($safe,'$.purchase_study.baseline[5]'))":"json_extract($safe,'$.purchase_study.baseline[5]')";
                $earlier=$this->query("SELECT 1 AS present FROM modeled_conversion_profile_versions WHERE project_key='anytour' AND customer_key=? AND id<? AND observed_at_unix>=? AND ($activity)=1 LIMIT 1",[$s['customer_key'],$id,$registered]);
                if($earlier){$this->counts['non_new_lead_profiles']++;$request['profile_cursor']=$id;continue;}
                $packet=$this->packet($id);
                if($packet===null){if($this->packetBudgetPaused)break;$request['profile_cursor']=$id;continue;}
                try{$packetScope=is_array($packet['scope']??null)?self::scope($packet['scope']):null;}catch(Throwable $ignored){$packetScope=null;}
                if($packetScope!==$s||($packet['frozen_at']??null)!==gmdate('Y-m-d\TH:i:s\Z',$observed)
                    ||($packet['source_access']??null)!=='allowed'||!is_array($packet['baseline']??null)
                    ||!preg_match('/\A[a-f0-9]{64}\z/D',(string)($packet['sha256']??''))){$this->counts['missing_or_invalid_packets']++;$request['profile_cursor']=$id;continue;}
                if(($packet['baseline'][5]??null)!==1&&($packet['baseline'][5]??null)!==1.0){$this->counts['entry_only_profiles']++;$request['profile_cursor']=$id;continue;}
                $evidence=['scope'=>$s,'created_unix'=>$created,'prior_conversations'=>0,'earlier_profiles'=>0,'profile_id'=>$id,'fence'=>$fence];
                $proof=['scope'=>$s,'packet_sha256'=>$packet['sha256'],'profile_id'=>$id,'reader_sha256'=>$request['reader_sha256'],
                    'run_id'=>'runtime:'.bin2hex(random_bytes(12)),'observed_at'=>gmdate('Y-m-d\TH:i:s\Z',$at),
                    'conversation_created_at'=>gmdate('Y-m-d\TH:i:s\Z',$created),'person_first_seen_at'=>gmdate('Y-m-d\TH:i:s\Z',$created),
                    'prior_conversations'=>0,'earlier_profiles'=>0,'exposure'=>'unseen','exposure_ledger_sha256'=>self::digest($evidence),
                    'source_reference'=>['kind'=>'native_inquiry_metadata','id'=>$s['conversation_id'],'sha256'=>self::digest($native),'information_fence'=>$fence]];
                $request['profiles'][]=['db_profile_id'=>$id,'observed_at'=>gmdate('Y-m-d\TH:i:s\Z',$observed),
                    'wrapper'=>['subject'=>$s,'access_status'=>'allowed','purchase_study'=>$packet],'admission'=>$proof];
                $selected[$s['customer_key']]=['packet'=>$packet];$request['profile_cursor']=$id;
            }
            $monitored=array_values($selected);$cases=$state['cases'];$count=count($cases);$start=$count?$state['monitor_cursor']%$count:0;
            for($i=0;$i<$count&&count($touched)<4;$i++){
                $case=$cases[($start+$i)%$count];$s=self::scope($case['packet']['scope']);
                if(!isset($touched[$s['customer_key']])){$monitored[]=$case;$touched[$s['customer_key']]=true;}
            }
            $last=json_decode((string)($stream[0]['last_receipt_json']??''),true);
            $caughtUp=self::compare(self::id($profiles[0]['journal_cursor']),$journalFence)>=0
                &&is_array($last)&&($last['limit_reached']??true)===false
                &&is_int($last['observed_at_unix']??null)&&$last['observed_at_unix']>=$at-600&&$last['observed_at_unix']<=$at;
            foreach($monitored as$case)$request['updates'][]=$this->outcomes($case['packet'],$registered,$journalFence,$at,$caughtUp);
            $this->counts['canonical_people_checked']=count($touched);
            $request['now']=gmdate('Y-m-d\TH:i:s\Z');$this->remaining();return$request;
        }finally{if($this->pdo->inTransaction())$this->pdo->rollBack();}
    }

    private function native(array $s):?array
    {
        $clock=ConversationRecordClock::sql('c.metadata_json',$this->mysql);
        $r=$this->query("SELECT c.id,c.customer_id,c.channel,$clock AS created_unix FROM conversations c JOIN customer_channels cc ON cc.id=c.customer_channel_id AND cc.customer_id=c.customer_id AND cc.project_key=c.project_key AND cc.channel=c.channel WHERE c.id=? AND c.customer_id=? AND c.project_key='anytour' AND c.channel=? AND c.is_test=0 AND NOT ".InternalOwnerDialogueScope::conversationSql('c').' LIMIT 2',[$s['conversation_id'],$s['customer_key'],$s['platform']]);
        if(count($r)!==1)return null;
        return['scope'=>$s,'created_unix'=>$r[0]['created_unix']===null?null:(int)$r[0]['created_unix']];
    }

    private function packet(string $id):?array
    {
        $safe=$this->safe('profile_json');$raw=$this->mysql?"JSON_EXTRACT($safe,'$.purchase_study')":"json_extract($safe,'$.purchase_study')";
        $length=$this->mysql?'OCTET_LENGTH':'LENGTH';
        $size=$this->query("SELECT $length($raw) AS bytes FROM modeled_conversion_profile_versions WHERE project_key='anytour' AND id=?",[$id]);
        $n=(int)($size[0]['bytes']??0);
        if($n<1){$this->counts['missing_or_invalid_packets']++;return null;}
        if($n>self::RAW_BYTES){$this->counts['oversize_packets']++;return null;}
        if($this->bytes+$n>self::RAW_BYTES){$this->packetBudgetPaused=true;$this->counts['budget_paused']++;return null;}
        $r=$this->query("SELECT $raw AS packet FROM modeled_conversion_profile_versions WHERE project_key='anytour' AND id=?",[$id]);
        $json=$r[0]['packet']??null;
        if(!is_string($json)||strlen($json)!==$n)self::fail('packet_changed');
        $this->bytes+=$n;$this->counts['profile_packets_read']++;
        try{$packet=json_decode($json,true,128,JSON_THROW_ON_ERROR);}catch(Throwable $ignored){$packet=null;}
        if(!is_array($packet)){$this->counts['missing_or_invalid_packets']++;return null;}
        return$packet;
    }

    private function outcomes(array $packet,int $registered,string $fence,int $at,bool $caughtUp):array
    {
        $s=self::scope($packet['scope']);$group=$packet['person_group'];
        if($this->native($s)===null)return['person_group'=>$group,'outcomes'=>[],'coverage'=>$this->coverage($registered,$at,$fence,false,[]),'access_status'=>'denied'];
        $initial=$this->safe('first.snapshot_json');$get=fn(string $path):string=>$this->mysql?"JSON_UNQUOTE(JSON_EXTRACT($initial,'$path'))":"CAST(json_extract($initial,'$path') AS TEXT)";
        $length=$this->mysql?'OCTET_LENGTH':'LENGTH';
        $sql="SELECT v.id,v.source_id,v.version_no,v.observed_at_unix,ss.version_no AS current_version,$length(v.snapshot_json) AS bytes FROM modeled_conversion_stream_versions v JOIN modeled_conversion_stream_versions first ON first.project_key=v.project_key AND first.source_kind=v.source_kind AND first.source_id=v.source_id AND first.version_no=1 LEFT JOIN modeled_conversion_stream_sources ss ON ss.project_key=v.project_key AND ss.source_kind=v.source_kind AND ss.source_id=v.source_id WHERE v.project_key='anytour' AND v.source_kind='event' AND v.id<=? AND v.observed_at_unix>=? AND v.observed_at_unix<=? AND first.observed_at_unix>=? AND ".$get('$.payload.event_type')."='sales_pipeline_outcome_observed' AND ".$get('$.project_key')."='anytour' AND ".$get('$.platform').'=? AND '.$get('$.customer_key').'=? AND '.$get('$.conversation_id')."=? ORDER BY v.id LIMIT 17";
        $r=$this->query($sql,[$fence,$registered,$at,$registered,$s['platform'],$s['customer_key'],$s['conversation_id']]);
        $complete=$caughtUp&&count($r)<17;$receipts=[];$evidence=[];$contrary=false;
        if(count($r)===17)$this->counts['outcome_page_limited']++;
        foreach($r as$meta){
            $this->counts['outcome_rows']++;
            if((int)$meta['version_no']!==1||(int)($meta['current_version']??0)!==1){$complete=false;$contrary=true;$this->counts['invalid_outcome_rows']++;continue;}
            $n=(int)$meta['bytes'];
            if($n<1||$n>self::RAW_BYTES||$this->bytes+$n>self::RAW_BYTES){$complete=false;$this->counts['budget_paused']++;continue;}
            $raw=$this->query("SELECT snapshot_json FROM modeled_conversion_stream_versions WHERE project_key='anytour' AND id=?",[(string)$meta['id']]);
            $json=$raw[0]['snapshot_json']??null;if(!is_string($json)||strlen($json)!==$n)self::fail('outcome_changed');
            $this->bytes+=$n;$evidence[]=[(string)$meta['id'],hash('sha256',$json)];
            try{$snap=json_decode($json,true,64,JSON_THROW_ON_ERROR);}catch(Throwable $ignored){$snap=null;}
            try{$snapScope=is_array($snap)?self::scope(array_intersect_key($snap,$s)):null;}catch(Throwable $ignored){$snapScope=null;}
            if(!is_array($snap)||($snap['source_status']??null)!=='present'||($snap['payload_status']??null)!=='copied'||$snapScope!==$s){$complete=false;$contrary=true;$this->counts['invalid_outcome_rows']++;continue;}
            if(($snap['payload']['event_type']??null)!=='sales_pipeline_outcome_observed')continue;
            $payload=$snap['payload']['payload_json']??null;
            try{$p=is_string($payload)?json_decode($payload,true,32,JSON_THROW_ON_ERROR):null;}catch(Throwable $ignored){$p=null;}
            $recorded=is_array($p)?ConversationRecordClock::read($payload):null;
            try{$payloadScope=is_array($p['scope']??null)?self::scope($p['scope']):null;}catch(Throwable $ignored){$payloadScope=null;}
            $keys=['version','origin','producer','evidence_basis','scope','outcome','reason_key','sale_amount','sale_amount_basis',
                'sale_date','sale_date_precision','actor_role','inquiry_binding','profile_id','episode_key','previous_outcome','operation_id','_recorded_clock'];
            $validDate=true;
            if(is_array($p)&&($p['sale_date']??null)!==null){
                $date=is_string($p['sale_date'])?DateTimeImmutable::createFromFormat('!Y-m-d',$p['sale_date']):false;
                $validDate=$date&&$date->format('Y-m-d')===$p['sale_date'];
            }
            if(!is_array($p)||($p['version']??null)!==1||($p['origin']??null)!=='existing_sales_pipeline'
                ||($p['producer']??null)!=='sales_pipeline_manager_assertion'||($p['evidence_basis']??null)!=='manager_operational_assertion'
                ||count($p)!==count($keys)||array_diff($keys,array_keys($p))||$payloadScope!==$s
                ||!in_array($p['actor_role']??null,['manager','system'],true)||($snap['payload']['actor_type']??null)!==$p['actor_role']
                ||!in_array($p['outcome']??null,['won','lost','open'],true)||$p['sale_amount_basis']!=='sale_revenue_assertion_not_commission'
                ||($p['sale_amount']!==null&&(!is_string($p['sale_amount'])||preg_match('/\A(?:0|[1-9][0-9]{0,9})\.[0-9]{2}\z/D',$p['sale_amount'])!==1))
                ||($p['reason_key']!==null&&(!is_string($p['reason_key'])||preg_match('/\A[a-z0-9_-]{1,64}\z/D',$p['reason_key'])!==1))
                ||$p['sale_date_precision']!==($p['sale_date']===null?'unknown':'date_only')
                ||($p['sale_date']!==null&&(!is_string($p['sale_date'])||preg_match('/\A[0-9]{4}-[0-9]{2}-[0-9]{2}\z/D',$p['sale_date'])!==1))
                ||!$validDate||($p['outcome']!=='won'&&($p['sale_amount']!==null||$p['sale_date']!==null))||($p['outcome']!=='lost'&&$p['reason_key']!==null)
                ||$p['inquiry_binding']!==null||$p['profile_id']!==null||$p['episode_key']!==null||$p['previous_outcome']!==null
                ||!is_string($p['operation_id'])||preg_match('/\A[a-f0-9]{32}\z/D',$p['operation_id'])!==1
                ||$recorded===null||($snap['source_timestamp_basis']??null)!=='canonical_recording_unix_v1'
                ||($snap['source_created_at_unix']??null)!==$recorded||$recorded>(int)$meta['observed_at_unix']
                ||!preg_match('/\A[a-f0-9]{64}\z/D',(string)($snap['payload_sha256']??''))){$complete=false;$contrary=true;$this->counts['invalid_outcome_rows']++;continue;}
            if(count($receipts)>=16){$complete=false;continue;}
            $receipt=['receipt_id'=>'event:'.self::id($meta['source_id']),'scope'=>$s,'event_type'=>'sales_pipeline_outcome_observed','payload'=>$p,
                'recorded_at'=>gmdate('Y-m-d\TH:i:s\Z',$recorded),'available_at'=>gmdate('Y-m-d\TH:i:s\Z',(int)$meta['observed_at_unix']),
                'source_reference'=>['kind'=>'conversation_event','id'=>(string)$meta['source_id'],'sha256'=>$snap['payload_sha256'],'journal_id'=>(string)$meta['id']]];
            $receipt['sha256']=self::digest($receipt);$receipts[]=$receipt;
        }
        return['person_group'=>$group,'outcomes'=>$receipts,'coverage'=>$this->coverage($registered,$at,$fence,$complete,$evidence),'access_status'=>$contrary?'unknown':'allowed'];
    }

    private function coverage(int $from,int $through,string $fence,bool $complete,array $evidence):array
    {
        return['from'=>gmdate('Y-m-d\TH:i:s\Z',$from),'through'=>gmdate('Y-m-d\TH:i:s\Z',$through),
            'available_at'=>gmdate('Y-m-d\TH:i:s\Z'),'status'=>$complete?'complete':'partial',
            'source_reference'=>['sha256'=>self::digest(['admitted_journal_fence'=>$fence,'scope_rows'=>$evidence]),'information_fence'=>$fence]];
    }

    private function query(string $sql,array $args=[]):array
    {
        $ms=$this->remaining();if($this->mysql)$this->pdo->exec('SET SESSION MAX_EXECUTION_TIME='.$ms);
        $q=$this->pdo->prepare($sql);$this->remaining();$q->execute($args);$r=$q->fetchAll(PDO::FETCH_ASSOC);$this->remaining();
        $this->rows+=count($r);if($this->rows>500)self::fail('row_budget');return$r;
    }
    private function remaining():int{$ms=(int)floor(($this->deadline-hrtime(true))/1000000);if($ms<50)self::fail('deadline');return min(4000,$ms-25);}
    private function safe(string $column):string{return"CASE WHEN JSON_VALID($column) THEN $column ELSE '{}' END";}
    private static function scope(array $s):array
    {
        if(array_keys($s)!==['project_key','platform','customer_key','conversation_id']){
            if(count($s)!==4||array_diff(['project_key','platform','customer_key','conversation_id'],array_keys($s)))self::fail('scope');
        }
        if(($s['project_key']??null)!=='anytour'||!in_array($s['platform']??null,['max','telegram'],true))self::fail('scope');
        $s=['project_key'=>'anytour','platform'=>$s['platform'],'customer_key'=>self::id($s['customer_key']),'conversation_id'=>self::id($s['conversation_id'])];
        if($s['customer_key']==='0'||$s['conversation_id']==='0')self::fail('scope');return$s;
    }
    private static function id($id):string{if(!is_string($id)&&!is_int($id))self::fail('id');$id=(string)$id;if(preg_match('/\A(?:0|[1-9][0-9]{0,19})\z/D',$id)!==1)self::fail('id');return$id;}
    private static function compare(string $a,string $b):int{return strlen($a)<=>strlen($b)?:strcmp($a,$b);}
    private static function instant(string $at):int{$t=DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s\Z',$at,new DateTimeZone('UTC'));if(!$t||$t->format('Y-m-d\TH:i:s\Z')!==$at)self::fail('clock');return$t->getTimestamp();}
    private static function digest(array $value):string
    {
        $sort=function($v)use(&$sort){if(!is_array($v))return$v;if(!array_is_list($v))ksort($v,SORT_STRING);foreach($v as$k=>$x)$v[$k]=$sort($x);return$v;};
        return hash('sha256',json_encode($sort($value),JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR));
    }
    private static function readerSha():string
    {
        $root=dirname(__DIR__);$hashes=[];
        foreach(['services/ModeledConversionPurchaseStudy.php','services/ModeledConversionOutcomeReceipt.php',
            'tools/purchase_conversion_study.py','tools/purchase_study_runtime.py','tools/profile_conversion_journal.py',
            'tools/extract_conversion_profiles.py']as$path){
            $hash=hash_file('sha256',$root.'/'.$path);if(!is_string($hash))self::fail('reader_source');$hashes[$path]=$hash;
        }
        return self::digest($hashes);
    }
    private static function result(string $status):array{return['status'=>$status,'supplier_version'=>self::VERSION,'model_calls'=>0,'conversion_uploads'=>0,'export_allowed'=>false];}
    private static function fail(string $category):void{throw new RuntimeException('purchase_study_'.$category);}

    private function directory(bool $create):bool
    {
        $path=$this->directory;$root=realpath(dirname(__DIR__));
        if($root===false||$path===''||$path[0]!=='/'||str_contains($path,"\0")||$path===$root||str_starts_with($path,$root.'/'))self::fail('private_path');
        $walk='';foreach(explode('/',$path)as$part){if($part==='')continue;if(in_array($part,['.','..'],true))self::fail('private_path');$walk.='/'.$part;if(is_link($walk))self::fail('private_link');}
        if(!file_exists($path)){if(!$create)return false;if(!@mkdir($path,0700))self::fail('private_storage');}
        if(!is_dir($path)||is_link($path))self::fail('private_storage');
        $mode=fileperms($path);if($mode===false||($mode&0777)!==0700)self::fail('private_mode');return true;
    }
    private function lock(int $mode)
    {
        $path=$this->directory.'/lock';$old=@lstat($path);
        if($mode===LOCK_SH&&$old===false)self::fail('lock_missing');
        if($old!==false&&(($old['mode']&0170000)!==0100000||($old['mode']&0777)!==0600||$old['nlink']!==1))self::fail('lock_file');
        $h=@fopen($path,'c+b');if(!is_resource($h))self::fail('lock_file');
        clearstatcache(true,$path);$stat=lstat($path);$open=fstat($h);
        if(!$stat||!$open||$stat['ino']!==$open['ino']||$stat['dev']!==$open['dev']||($stat['mode']&0170000)!==0100000||is_link($path)||!@chmod($path,0600)){fclose($h);self::fail('lock_file');}
        if(!flock($h,$mode|LOCK_NB)){fclose($h);return null;}return$h;
    }
    private function read():?array
    {
        $path=$this->directory.'/state.json';$stat=@lstat($path);if($stat===false)return null;
        if(($stat['mode']&0170000)!==0100000||($stat['mode']&0777)!==0600||$stat['nlink']!==1||$stat['size']>self::FILE_BYTES)self::fail('state_file');
        $h=@fopen($path,'rb');if(!is_resource($h))self::fail('state_file');
        try{$open=fstat($h);if(!$open||$open['ino']!==$stat['ino']||$open['dev']!==$stat['dev'])self::fail('state_file');$raw=stream_get_contents($h,self::FILE_BYTES+1);}finally{fclose($h);}
        if(!is_string($raw)||strlen($raw)>self::FILE_BYTES)self::fail('state_file');
        $saved=json_decode($raw,true,8,JSON_THROW_ON_ERROR);
        if(!is_array($saved)||count($saved)!==5||($saved['schema_version']??null)!==1||!is_string($saved['state_json']??null)||!is_string($saved['receipt_json']??null)
            ||!hash_equals((string)($saved['state_sha256']??''),hash('sha256',$saved['state_json']))||!hash_equals((string)($saved['receipt_sha256']??''),hash('sha256',$saved['receipt_json'])))self::fail('state_file');
        $state=$this->decodeState($saved['state_json']);$receipt=json_decode($saved['receipt_json'],true,8,JSON_THROW_ON_ERROR);$this->receipt($receipt);
        return['state'=>$state,'state_json'=>$saved['state_json'],'receipt'=>$receipt];
    }
    private function decodeState(string $raw):array
    {
        if(strlen($raw)>self::STATE_BYTES)self::fail('state_size');$state=json_decode($raw,true,256,JSON_THROW_ON_ERROR);
        if(!is_array($state)||($state['kind']??null)!=='purchase_study_state'||($state['version']??null)!=='purchase_study_runtime_v1'
            ||!is_array($state['manifest']??null)||($state['manifest']['reader_kind']??null)!=='reviewed_runtime_reader'
            ||($state['manifest']['reader_sha256']??null)!==self::readerSha()||!is_array($state['cases']??null)||count($state['cases'])>256
            ||!is_int($state['monitor_cursor']??null)||$state['monitor_cursor']<0)self::fail('state_contract');
        self::instant($state['manifest']['registered_at']);self::id($state['profile_cursor']);self::id($state['profile_fence']);
        foreach($state['cases']as$c)if(!is_array($c)||!is_array($c['packet']??null)||!is_array($c['packet']['scope']??null))self::fail('state_contract');
        return$state;
    }
    private function summary(array $summary):array
    {
        if(count($summary)!==count(self::SUMMARY)||array_diff(self::SUMMARY,array_keys($summary)))self::fail('summary');
        foreach($summary as$k=>$v){
            if(in_array($k,['version','target'],true)){if(!is_string($v)||preg_match('/\A[a-z0-9_]{1,100}\z/D',$v)!==1)self::fail('summary');}
            elseif($k==='protocol_sha256'){if(!is_string($v)||preg_match('/\A[a-f0-9]{64}\z/D',$v)!==1)self::fail('summary');}
            elseif($k==='paired_brier_delta'){if($v!==null&&((!is_float($v)&&!is_int($v))||!is_finite((float)$v)||$v< -1||$v>1))self::fail('summary');}
            elseif(!is_int($v)||$v<0||$v>10000000)self::fail('summary');
        }
        if($summary['conversion_uploads']!==0||$summary['verified_payment_probability']!==0||$summary['all_lead_calibration_ready']!==0)self::fail('financial_output');
        return$summary;
    }
    private function receipt(array $receipt):void
    {
        $base=array_intersect_key($receipt,array_flip(self::SUMMARY));$this->summary($base);
        if(count($receipt)!==count(self::SUMMARY)+count(self::COUNTERS)+1)self::fail('receipt');
        foreach(self::COUNTERS as$k)if(!isset($receipt[$k])||!is_int($receipt[$k])||$receipt[$k]<0||$receipt[$k]>10000000)self::fail('receipt');
        self::instant($receipt['observed_at_utc']);
    }
    private function write(string $state,array $receipt):void
    {
        $this->receipt($receipt);$receiptJson=json_encode($receipt,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES);
        $raw=json_encode(['schema_version'=>1,'state_json'=>$state,'state_sha256'=>hash('sha256',$state),'receipt_json'=>$receiptJson,'receipt_sha256'=>hash('sha256',$receiptJson)],JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES);
        if(strlen($raw)>self::FILE_BYTES)self::fail('registry_size');
        $path=$this->directory.'/state.json';$existing=@lstat($path);
        if($existing!==false&&(($existing['mode']&0170000)!==0100000||($existing['mode']&0777)!==0600||$existing['nlink']!==1))self::fail('state_file');
        $temp=$this->directory.'/state-'.bin2hex(random_bytes(12)).'.tmp';$h=@fopen($temp,'x+b');if(!is_resource($h))self::fail('state_file');
        try{
            if(!@chmod($temp,0600))self::fail('state_mode');$offset=0;
            while($offset<strlen($raw)){$n=fwrite($h,substr($raw,$offset));if($n===false||$n===0)self::fail('state_write');$offset+=$n;}
            if(!fflush($h)||(function_exists('fsync')&&!fsync($h)))self::fail('state_flush');
            fclose($h);$h=null;if(!@rename($temp,$path))self::fail('state_publish');
        }finally{if(is_resource($h))fclose($h);if(is_file($temp))unlink($temp);}
    }
    private function execute(array $request):array
    {
        $input=json_encode($request,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRESERVE_ZERO_FRACTION|JSON_THROW_ON_ERROR);
        if(strlen($input)>self::FILE_BYTES)self::fail('input_size');
        $dir=$this->directory.'/run-'.bin2hex(random_bytes(12));if(!mkdir($dir,0700))self::fail('temporary_storage');$proc=null;
        try{
            foreach(['input','output','error']as$name)if(file_put_contents($dir.'/'.$name,$name==='input'?$input:'')===false||!chmod($dir.'/'.$name,0600))self::fail('temporary_storage');
            $proc=proc_open(['python3',dirname(__DIR__).'/tools/purchase_study_runtime.py'],[0=>['file',$dir.'/input','r'],1=>['file',$dir.'/output','w'],2=>['file',$dir.'/error','w']],$pipes);
            if(!is_resource($proc))self::fail('interpreter');$until=hrtime(true)+5000000000;$exit=null;
            do{$s=proc_get_status($proc);if(!$s['running']){$exit=$s['exitcode'];break;}
                clearstatcache(true,$dir.'/output');clearstatcache(true,$dir.'/error');
                if(filesize($dir.'/output')>self::FILE_BYTES||filesize($dir.'/error')>65536||hrtime(true)>$until){proc_terminate($proc,9);self::fail('interpreter_limit');}usleep(10000);
            }while(true);
            proc_close($proc);$proc=null;clearstatcache(true,$dir.'/output');if($exit!==0||filesize($dir.'/output')>self::FILE_BYTES)self::fail('interpreter_failed');
            return json_decode((string)file_get_contents($dir.'/output'),true,256,JSON_THROW_ON_ERROR);
        }finally{if(is_resource($proc)){proc_terminate($proc,9);proc_close($proc);}foreach(['input','output','error']as$name)if(is_file($dir.'/'.$name))unlink($dir.'/'.$name);rmdir($dir);}
    }
}
