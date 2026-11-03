<?php
declare(strict_types=1);
require_once __DIR__.'/ModeledConversionStatusBudget.php';
require_once __DIR__.'/ModeledConversionValueRegistry.php';
require_once __DIR__.'/ModeledConversionValueDeliveryReader.php';
require_once __DIR__.'/ModeledConversionInputDiagnosisReader.php';
require_once __DIR__.'/ModeledConversionLatencyReader.php';
require_once __DIR__.'/ModeledConversionTelegramLinkageReader.php';

/** Launch readback of current V1 derived blocks only. Never reads a transcript or runs processing. */
final class ModeledConversionForecastStatusReader
{
    public const VERSION='saved_semantic_prediction_status_v1_10';
    public const METADATA_LIMIT=20;
    public const PROFILE_LIMIT=4;
    public const PROFILE_BYTES=49152;
    private const RELEASE_RESERVE=8192;
    private const CONTINUITY_FIELDS=['preserved_ai_replies','preserved_handoff_receipts','unresolved_clock_barriers',
        'long_gap_barriers','unavailable_message_barriers','unavailable_event_barriers','other_source_barriers'];
    private bool $mysql;
    private int $copied=0;
    public function __construct(private PDO $pdo,private $authorize,private ModeledConversionStatusBudget $budget)
    {$this->mysql=$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql';}

    public function read():array
    {
        $this->auth();
        if($this->pdo->inTransaction())return $this->unavailable('outer_transaction');
        try{
            $this->copied=0;$out=$this->emptyResult();
            $out['state']=$this->state();
            $out['value_delivery']=(new ModeledConversionValueDeliveryReader($this->pdo,$this->authorize,$this->budget))->read(time());
            $activation=$out['value_delivery']['activated_at']??null;
            $activation=is_string($activation)?strtotime($activation):false;
            $out['saved_input_diagnosis']=(new ModeledConversionInputDiagnosisReader($this->pdo,$this->authorize,$this->budget))->read($activation===false?null:$activation);
            $out['today_latency']=(new ModeledConversionLatencyReader($this->pdo,$this->authorize,$this->budget))->read(time());
            $model=hash_file('sha256',__DIR__.'/ModeledConversionSemanticPrediction.php');
            $processor=hash_file('sha256',__DIR__.'/ModeledConversionProfiles.php');
            if(!is_string($model)||!is_string($processor))return $this->unavailable('source_unavailable');
            $parts=$this->parts();$sum=implode('+',array_map(fn(string $s):string=>'COALESCE('.$this->length($s).',0)',array_values($parts)));
            $bytes='CASE WHEN JSON_VALID(profile_json) THEN '.$sum.' ELSE 0 END';
            $matches='CASE WHEN JSON_VALID(profile_json) THEN CASE WHEN '
                .$this->scalar('semantic_prediction.version').'=? AND '.$this->scalar('semantic_prediction.model_sha256').'=?'
                .' AND '.$this->scalar('processing.source_sha256').'=? THEN 1 ELSE 0 END ELSE 0 END';
            // LIMIT precedes JSON inspection: bounded recent immutable suffix, not a corpus/goal scan.
            $meta=$this->query('SELECT id,platform,customer_key,conversation_id,journal_cursor,observed_at_unix,'
                .$bytes.' AS packet_bytes,'.$matches.' AS namespace_matches'
                .' FROM (SELECT id,platform,customer_key,conversation_id,journal_cursor,observed_at_unix,profile_json'
                .' FROM modeled_conversion_profile_versions WHERE project_key=? AND platform IN (?,?) ORDER BY id DESC LIMIT '.self::METADATA_LIMIT.') v ORDER BY id DESC',
                ['semantic_purchase_heuristic_v1',$model,$processor,'anytour','max','telegram']);
            $out['window']['metadata_rows']=count($meta);$out['window']['metadata_limit_reached']=count($meta)===self::METADATA_LIMIT;
            $fence=$meta?(string)$meta[0]['id']:'0';$scopes=[];
            foreach($meta as$row){
                $this->budget->check();
                if(!$this->validMeta($row)){$out['window']['invalid_metadata_rows']++;continue;}
                if((string)$row['namespace_matches']!=='1'){$out['window']['other_namespace_rows']++;continue;}
                $out['window']['current_v1_candidates']++;
                $key=$row['platform'].'|'.$row['customer_key'].'|'.$row['conversation_id'];
                if(isset($scopes[$key])){$out['window']['duplicate_scope_rows']++;continue;}
                $scopes[$key]=true;
                $size=(int)$row['packet_bytes'];
                if($size<2||$size>self::PROFILE_BYTES){$out['window']['oversize_rows']++;continue;}
                if($out['window']['profile_rows_read']>=self::PROFILE_LIMIT){$out['window']['row_limit_omissions']++;continue;}
                if($this->copied+$size>self::PROFILE_BYTES||$size*2+2048>$this->budget->remainingBytes()-self::RELEASE_RESERVE){
                    $out['window']['byte_limit_omissions']++;continue;
                }
                $columns=[];
                foreach($parts as$name=>$sql)$columns[]='CASE WHEN JSON_VALID(profile_json) THEN '.$sql.' ELSE NULL END AS '.$name;
                $body=$this->query('SELECT '.implode(',',$columns).' FROM modeled_conversion_profile_versions WHERE project_key=?'
                    .' AND id=? AND id<=? AND '.$bytes.'=? AND '.$bytes.'<=? AND JSON_VALID(profile_json) LIMIT 1',
                    ['anytour',(string)$row['id'],$fence,$size,self::PROFILE_BYTES]);
                $actual=0;
                if(count($body)===1)foreach(array_keys($parts)as$name){
                    $v=$body[0][$name]??null;if($v!==null&&!is_string($v)){$actual=-1;break;}$actual+=strlen($v??'');
                }
                if(count($body)!==1||$actual!==$size){$out['window']['changed_or_missing_rows']++;continue;}
                $this->copied+=$actual;$out['window']['profile_rows_read']++;
                try{
                    $p=json_decode($body[0]['binding_json'],true,32,JSON_THROW_ON_ERROR);
                    $p['semantic_prediction']=json_decode($body[0]['prediction_json']??'null',true,32,JSON_THROW_ON_ERROR);
                    $p['prediction_followup']=json_decode($body[0]['followup_json']??'null',true,32,JSON_THROW_ON_ERROR);
                }catch(JsonException $ignored){$out['window']['invalid_blocks']++;$out['window']['invalid_block_reason_counts']['invalid_json']++;continue;}
                $subject=['project_key'=>'anytour','platform'=>$row['platform'],
                    'customer_key'=>(string)$row['customer_key'],'conversation_id'=>(string)$row['conversation_id']];
                $s=$p['semantic_prediction']??null;
                if(!is_array($p)||!self::sameSubject($p['subject']??null,$subject)||!is_array($s)
                    ||($p['processing']['source_sha256']??null)!==$processor||($s['model_sha256']??null)!==$model
                    ||($p['processing']['journal_cursor']??null)!==(string)$row['journal_cursor']
                    ||($s['journal_cursor']??null)!==(string)$row['journal_cursor']
                    ||($p['processing']['source_fence_journal_id']??null)!==($s['source_fence_journal_id']??null)
                    ||($s['profile_prediction_at']??null)!==gmdate('Y-m-d\TH:i:s\Z',(int)$row['observed_at_unix'])
                    ||!is_string($s['frozen_at']??null)||($frozen=strtotime($s['frozen_at']))===false||$frozen>time()){
                    $out['window']['invalid_blocks']++;$out['window']['invalid_block_reason_counts']['parent_binding']++;continue;
                }
                $fields=ModeledConversionValueRegistry::predictionFields($p);
                if($fields['semantic_prediction']['availability']!=='available'){
                    $out['window']['invalid_blocks']++;$out['window']['invalid_block_reason_counts']['prediction_contract']++;
                    $reason=is_array($s)&&ModeledConversionSemanticPrediction::digestMatches($s)?'fields':'digest';
                    $out['window']['prediction_contract_reason_counts'][$reason]++;continue;
                }
                $s=$fields['semantic_prediction']['value'];
                if(!$this->inputCoverage($out['input_coverage'],$p['input_summary']??null,$s)){
                    $out['window']['invalid_blocks']++;$out['window']['invalid_block_reason_counts']['input_summary']++;continue;
                }

                $out['saved']['valid_v1_versions']++;$out['saved']['channel_counts'][$row['platform']]++;
                $out['saved']['status_counts'][$s['status']]++;
                if($s['stage']!==null)$out['saved']['stage_counts'][$s['stage']]++;
                foreach(['prediction_at','input_cutoff_at','profile_prediction_at']as$k)$this->range($out['saved'][$k.'_range'],$s[$k]);
                if($s['status']==='predicted'){
                    $out['saved']['prospective_estimates']++;
                    $this->probabilityRange($out['saved']['estimated_probability_range'],$s['estimated_probability']);
                    // These are validated current-segment customer feature clocks, not a new source read.
                    $lastOccurred=null;$lastAvailable=null;
                    foreach($s['evidence']as$e){
                        if($lastOccurred===null||$e['occurred_at']>$lastOccurred)$lastOccurred=$e['occurred_at'];
                        if($lastAvailable===null||$e['available_at']>$lastAvailable)$lastAvailable=$e['available_at'];
                    }
                    if($lastOccurred!==null)$this->range($out['saved']['customer_evidence_occurred_at_range'],$lastOccurred);
                    if($lastAvailable!==null)$this->range($out['saved']['customer_evidence_available_at_range'],$lastAvailable);

                }
                if($fields['prediction_followup']['availability']==='available'){
                    $f=$fields['prediction_followup']['value'];$out['saved']['followup_status_counts'][$f['status']]++;
                    if($f['status']==='observed')$out['saved']['later_payment_evidence_versions']++;
                }else $out['window']['invalid_followup_blocks']++;
            }
            $out['window']['selected_document_bytes']=$this->copied;
            $out['saved']['distinct_inspected_scopes']=$out['saved']['valid_v1_versions'];
            $out['confirmation']=$out['saved']['prospective_estimates']>0?'saved_prospective_estimates':
                ($out['saved']['valid_v1_versions']>0?'saved_v1_without_prospective_estimate':'no_v1_confirmation_in_checked_suffix');
            $out['telegram_linkage']=$this->budget->remainingBytes()<8192?['available'=>false,'reason'=>'budget_headroom']:
                (new ModeledConversionTelegramLinkageReader($this->pdo,$this->authorize,$this->budget))->read(time());
            $this->auth();return $out;
        }catch(ModeledConversionStatusLimit $error){throw $error;}
        catch(PDOException $ignored){return $this->unavailable('storage_unavailable');}
    }

    private function state():array
    {
        $length=$this->length('last_receipt_json');
        $r=$this->query('SELECT enabled,last_error_at_unix,CASE WHEN last_error_category=? THEN 1 ELSE 0 END AS processing_error,'
            .'CASE WHEN '.$length.'<=4096 THEN last_receipt_json ELSE NULL END AS receipt_json'
            .' FROM modeled_conversion_profiles_state WHERE project_key=? LIMIT 1',['processing_failed','anytour']);
        $out=['available'=>false,'enabled'=>null,'last_tick_status'=>'unknown','last_tick_at'=>null,
            'last_batch_profiles_written'=>null,'last_recorded_error_at'=>null];
        if(!$r)return $out;
        $r=$r[0];$out['available']=true;$out['enabled']=in_array((string)$r['enabled'],['0','1'],true)?(string)$r['enabled']==='1':null;
        if((string)$r['processing_error']==='1'&&is_numeric($r['last_error_at_unix'])&&(int)$r['last_error_at_unix']>=946684800
            &&(int)$r['last_error_at_unix']<=time())$out['last_recorded_error_at']=gmdate('Y-m-d\TH:i:s\Z',(int)$r['last_error_at_unix']);
        $json=$r['receipt_json']??null;
        if(!is_string($json)||strlen($json)>4096)return $out;
        try{$v=json_decode($json,true,32,JSON_THROW_ON_ERROR);}catch(JsonException $ignored){return $out;}
        if(!is_array($v)||!in_array($v['status']??null,['profiled','idle'],true))return $out;
        $out['last_tick_status']=$v['status'];
        if(is_int($v['observed_at_unix']??null)&&$v['observed_at_unix']>=946684800&&$v['observed_at_unix']<=time())
            $out['last_tick_at']=gmdate('Y-m-d\TH:i:s\Z',$v['observed_at_unix']);
        if(is_int($v['profiles_written']??null)&&$v['profiles_written']>=0&&$v['profiles_written']<=8)
            $out['last_batch_profiles_written']=$v['profiles_written'];
        return $out;
    }
    private function parts():array
    {
        $s=fn(string $k):string=>"JSON_EXTRACT(profile_json,'$.".$k."')";
        $status=$this->scalar('early_forecast.status');
        $continuity=implode('',array_map(fn(string $k):string=>",'".$k."',".$this->boundedCount('contextual_signals.coverage.prediction_source_continuity.'.$k,50),self::CONTINUITY_FIELDS));
        $summary="JSON_OBJECT('customer_turns',".$this->boundedCount('contextual_signals.engagement.customer_turns',50)
            .",'manager_turns',".$this->boundedCount('contextual_signals.engagement.manager_turns',50)
            .",'substantive_interest',".$this->boundedCount('contextual_signals.goal_counts.substantive_interest',800)
            .",'general_purchase_intent',".$this->boundedCount('contextual_signals.goal_counts.general_purchase_intent',800)
            .",'agency_purchase_intent',".$this->boundedCount('contextual_signals.goal_counts.agency_purchase_intent',800)
            .",'linking_disabled',".$this->boundedBoolean('contextual_signals.coverage.context_linking_disabled')
            .",'wizard_applications',".$this->boundedCount('coverage.applied_wizard_fields',50)
            .",'action_or_unknown_type_rows',".$this->boundedCount('coverage.errors.action_or_unknown_message_type',1000)
            .",'legacy_status',CASE WHEN ".$status." IN ('early_assessment','target_already_observed','no_usable_customer_context','access_denied') THEN ".$status." ELSE NULL END".$continuity.")";
        return ['prediction_json'=>$s('semantic_prediction'),'followup_json'=>$s('prediction_followup'),
            'binding_json'=>"JSON_OBJECT('access_status',".$s('access_status').",'subject',JSON_OBJECT('project_key',".$s('subject.project_key')
                .",'platform',".$s('subject.platform').",'customer_key',".$s('subject.customer_key').",'conversation_id',".$s('subject.conversation_id')
                ."),'profile',JSON_OBJECT('prediction_at',".$s('profile.prediction_at')
                ."),'processing',JSON_OBJECT('source_sha256',".$s('processing.source_sha256').",'journal_cursor',".$s('processing.journal_cursor')
                .",'source_fence_journal_id',".$s('processing.source_fence_journal_id')."),'input_summary',".$summary.")"];
    }
    private function boundedCount(string $path,int $maximum):string
    {
        $value="JSON_EXTRACT(profile_json,'$.".$path."')";
        $type=$this->mysql?'JSON_TYPE('.$value.')':"JSON_TYPE(profile_json,'$.".$path."')";
        $integer=$this->mysql?"'INTEGER'":"'integer'";$cast=$this->mysql?'SIGNED':'INTEGER';
        return 'CASE WHEN '.$type.'='.$integer.' AND CAST('.$value.' AS '.$cast.') BETWEEN 0 AND '.$maximum
            .' THEN CAST('.$value.' AS '.$cast.') ELSE NULL END';
    }
    private function boundedBoolean(string $path):string
    {
        $value="JSON_EXTRACT(profile_json,'$.".$path."')";
        if($this->mysql)return "CASE WHEN JSON_TYPE(".$value.")='BOOLEAN' THEN CASE WHEN JSON_UNQUOTE(".$value.")='true' THEN 1 ELSE 0 END ELSE NULL END";
        $type="JSON_TYPE(profile_json,'$.".$path."')";
        return "CASE WHEN ".$type."='true' THEN 1 WHEN ".$type."='false' THEN 0 ELSE NULL END";
    }
    /** Reconcile fixed saved counters; they describe input coverage, never create intent or probability. */
    private function inputCoverage(array &$out,mixed $raw,array $prediction):bool
    {
        $limits=['customer_turns'=>50,'manager_turns'=>50,'substantive_interest'=>800,
            'general_purchase_intent'=>800,'agency_purchase_intent'=>800];
        if(!is_array($raw)){$out['invalid_summary_profiles']++;return false;}
        $valid=true;
        foreach($limits as$k=>$max)if(!is_int($raw[$k]??null)||$raw[$k]<0||$raw[$k]>$max)$valid=false;
        if(!$valid||$raw['customer_turns']+$raw['manager_turns']>50
            ||$raw['substantive_interest']+$raw['general_purchase_intent']+$raw['agency_purchase_intent']>800
            ||!in_array($raw['legacy_status']??null,['early_assessment','target_already_observed','no_usable_customer_context','access_denied'],true)
            ||$prediction['current_customer_turn_count']>$raw['customer_turns']
            ||($prediction['status']==='predicted'&&$raw['legacy_status']!=='early_assessment')){
            $out['invalid_summary_profiles']++;return false;
        }
        $out['validated_profiles']++;$out['admitted_customer_turns']+=$raw['customer_turns'];
        $out['admitted_manager_turns']+=$raw['manager_turns'];
        if($raw['customer_turns']>0)$out['profiles_with_admitted_customer_text']++;
        if($raw['customer_turns']+$raw['manager_turns']===0)$out['profiles_without_admitted_human_text']++;
        $out['legacy_status_counts'][$raw['legacy_status']]++;
        if(in_array($raw['linking_disabled']??null,[0,1],true))$out['linking_disabled_profiles']+=$raw['linking_disabled'];
        else $out['linking_metadata_unknown_profiles']++;
        foreach(['substantive_interest','general_purchase_intent','agency_purchase_intent']as$k)$out['recorded_goal_counts'][$k]+=$raw[$k];
        $out['current_customer_turns']+=$prediction['current_customer_turn_count'];
        $out['meaningful_customer_turns']+=$prediction['features']['meaningful_customer_turns'];
        $out['context_reset_count']+=($prediction['features']['context_barriers']??0);
        if($prediction['current_request_anchor']!==null)$out['profiles_with_request_anchor']++;
        foreach(self::CONTINUITY_FIELDS as$k){
            $v=$raw[$k]??null;
            if(is_int($v)&&$v>=0&&$v<=50){$out['source_continuity'][$k]['known_profiles']++;$out['source_continuity'][$k]['recorded_total']+=$v;}
            else $out['source_continuity'][$k]['unknown_profiles']++;
        }
        foreach(['wizard_applications'=>50,'action_or_unknown_type_rows'=>1000]as$k=>$max){
            $v=$raw[$k]??null;
            if(is_int($v)&&$v>=0&&$v<=$max){$out[$k]['known_profiles']++;$out[$k]['recorded_total']+=$v;}
            else $out[$k]['unknown_profiles']++;
        }
        return true;
    }
    private function scalar(string $path):string
    {$v="JSON_EXTRACT(profile_json,'$.".$path."')";return $this->mysql?'JSON_UNQUOTE('.$v.')':$v;}
    private function length(string $s):string{return $this->mysql?'OCTET_LENGTH('.$s.')':'LENGTH(CAST('.$s.' AS BLOB))';}
    private function validMeta(array $r):bool
    {
        foreach(['id','customer_key','conversation_id']as$k)if(preg_match('/\A[1-9][0-9]{0,19}\z/D',(string)($r[$k]??''))!==1)return false;
        return in_array($r['platform']??null,['max','telegram'],true)
            &&preg_match('/\A(?:0|[1-9][0-9]{0,19})\z/D',(string)($r['journal_cursor']??''))===1
            &&is_numeric($r['packet_bytes']??null)&&(float)$r['packet_bytes']>=0
            &&is_numeric($r['observed_at_unix']??null)&&(int)$r['observed_at_unix']>=946684800&&(int)$r['observed_at_unix']<=time();
    }
    private function emptyResult():array
    {
        return ['available'=>true,'reader_version'=>self::VERSION,'checked_at'=>gmdate('Y-m-d\TH:i:s\Z'),
            'model_version'=>ModeledConversionSemanticPrediction::VERSION,'confirmation'=>'no_v1_confirmation_in_checked_suffix',
            'state'=>null,'window'=>['basis'=>'newest20_immutable_versions_current_model_and_processor_only',
                'metadata_limit'=>20,'metadata_rows'=>0,'metadata_limit_reached'=>false,'current_v1_candidates'=>0,
                'other_namespace_rows'=>0,'duplicate_scope_rows'=>0,'profile_rows_read'=>0,'invalid_metadata_rows'=>0,
                'oversize_rows'=>0,'row_limit_omissions'=>0,'byte_limit_omissions'=>0,'changed_or_missing_rows'=>0,
                'invalid_blocks'=>0,'invalid_followup_blocks'=>0,'selected_document_bytes'=>0,
                'invalid_block_reason_counts'=>array_fill_keys(['invalid_json','parent_binding','prediction_contract','input_summary'],0),
                'prediction_contract_reason_counts'=>['digest'=>0,'fields'=>0]],
            'saved'=>['valid_v1_versions'=>0,'prospective_estimates'=>0,'distinct_inspected_scopes'=>0,
                'channel_counts'=>['max'=>0,'telegram'=>0],
                'status_counts'=>array_fill_keys(['predicted','insufficient_context','known_outcome','access_denied'],0),
                'stage_counts'=>array_fill_keys(['substantive_dialogue','concrete_request','exact_offer_discussion','general_purchase_intent','agency_purchase_intent'],0),
                'estimated_probability_range'=>['minimum'=>null,'maximum'=>null],
                'prediction_at_range'=>['earliest'=>null,'latest'=>null],'input_cutoff_at_range'=>['earliest'=>null,'latest'=>null],
                'profile_prediction_at_range'=>['earliest'=>null,'latest'=>null],
                'customer_evidence_occurred_at_range'=>['earliest'=>null,'latest'=>null],
                'customer_evidence_available_at_range'=>['earliest'=>null,'latest'=>null],
                'followup_status_counts'=>['waiting'=>0,'unknown'=>0,'observed'=>0],'later_payment_evidence_versions'=>0],
            'input_coverage'=>['basis'=>'recorded_frozen_counters_not_replayed_source_or_purchase_evidence',
                'validated_profiles'=>0,'invalid_summary_profiles'=>0,'admitted_customer_turns'=>0,'admitted_manager_turns'=>0,
                'profiles_with_admitted_customer_text'=>0,'profiles_without_admitted_human_text'=>0,
                'linking_disabled_profiles'=>0,'linking_metadata_unknown_profiles'=>0,
                'legacy_status_counts'=>array_fill_keys(['early_assessment','target_already_observed','no_usable_customer_context','access_denied'],0),
                'recorded_goal_counts'=>array_fill_keys(['substantive_interest','general_purchase_intent','agency_purchase_intent'],0),
                'current_customer_turns'=>0,'meaningful_customer_turns'=>0,'profiles_with_request_anchor'=>0,'context_reset_count'=>0,
                'wizard_applications'=>['known_profiles'=>0,'unknown_profiles'=>0,'recorded_total'=>0],
                'action_or_unknown_type_rows'=>['known_profiles'=>0,'unknown_profiles'=>0,'recorded_total'=>0],
                'source_continuity'=>array_fill_keys(self::CONTINUITY_FIELDS,['known_profiles'=>0,'unknown_profiles'=>0,'recorded_total'=>0])],
            'interpretation'=>['basis'=>'bounded_saved_versions_not_new_people_or_trips','new_inquiry_identity_verified'=>false,
                'idle_tick_does_not_mean_no_saved_forecasts'=>true,'last_error_is_historical'=>true,
                'probabilities'=>'untrained_illustrative_hypotheses','payment'=>'conversation_evidence_not_bank_settlement',
                'missing_payment_is_not_negative'=>true,'population_coverage_known'=>false,'export_allowed'=>false],
            'limits'=>['max_metadata_rows'=>20,'max_profile_rows'=>4,'max_selected_document_bytes'=>49152,
                'max_selected_bytes'=>65536,'max_execution_seconds'=>5],'model_calls'=>0,'conversion_uploads'=>0];
    }
    private static function sameSubject(mixed $s,array $expected):bool
    {
        if(!is_array($s)||count($s)!==4)return false;
        foreach($expected as$k=>$v)if(($s[$k]??null)!==$v)return false;
        return true;
    }
    private function probabilityRange(array &$r,int|float $v):void
    {if($r['minimum']===null||$v<$r['minimum'])$r['minimum']=$v;if($r['maximum']===null||$v>$r['maximum'])$r['maximum']=$v;}
    private function range(array &$r,int|float|string $v):void
    {if($r['earliest']===null||$v<$r['earliest'])$r['earliest']=$v;if($r['latest']===null||$v>$r['latest'])$r['latest']=$v;}
    private function unavailable(string $reason):array
    {$this->auth();return ['available'=>false,'reader_version'=>self::VERSION,'reason'=>$reason,'confirmation'=>'unknown','saved'=>null];}
    private function auth():void{$this->budget->check();($this->authorize)();$this->budget->check();}
    private function query(string $sql,array $args):array
    {
        $this->auth();$q=$this->budget->prepare($this->pdo,$sql);
        foreach($args as$i=>$v)$q->bindValue($i+1,$v,is_int($v)?PDO::PARAM_INT:PDO::PARAM_STR);
        $q->execute();$rows=$q->fetchAll(PDO::FETCH_ASSOC);$q->closeCursor();$this->budget->check();
        $this->budget->consumeBytes(strlen(json_encode($rows,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)));
        return $rows;
    }
}
