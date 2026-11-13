<?php
declare(strict_types=1);
require_once __DIR__.'/ModeledConversionStatusBudget.php';
require_once __DIR__.'/ModeledConversionValueRegistry.php';
require_once __DIR__.'/ModeledConversionValueDeliveryReader.php';
require_once __DIR__.'/ModeledConversionInputDiagnosisReader.php';
require_once __DIR__.'/ModeledConversionLatencyReader.php';
require_once __DIR__.'/ModeledConversionTelegramLinkageReader.php';
require_once __DIR__.'/ModeledConversionDailyDeliveryReader.php';
require_once __DIR__.'/ModeledConversionDeliveryHealth.php';
require_once __DIR__.'/ModeledConversionDealValue.php';
require_once __DIR__.'/ModeledConversionVisitProof.php';
require_once __DIR__.'/ModeledConversionDailyReconciliationReader.php';

/** Launch readback of current V1 derived blocks only. Never reads a transcript or runs processing. */
final class ModeledConversionForecastStatusReader
{
    public const VERSION='saved_semantic_prediction_status_v1_20';
    public const METADATA_LIMIT=20;
    public const PROFILE_LIMIT=4;
    public const PROFILE_BYTES=49152;
    // Keep the final reader/adapter/dispatcher OAuth checks above their 4KiB authorization floor.
    private const RELEASE_RESERVE=8192;
    // Each optional reader retains its own unchanged 4KiB OAuth floor in addition to this reserve.
    private const OPTIONAL_RESERVE=4096;
    private const CONTINUITY_FIELDS=['preserved_ai_replies','preserved_handoff_receipts','unresolved_clock_barriers',
        'long_gap_barriers','unavailable_message_barriers','unavailable_event_barriers','other_source_barriers'];
    private bool $mysql;
    private int $copied=0;
    public function __construct(private PDO $pdo,private $authorize,private ModeledConversionStatusBudget $budget)
    {$this->mysql=$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql';}

    /**
     * Existing MCP default: current Moscow-day movement and monitor health first.
     * Historical detailed readers remain explicit internal audits and are not executed here.
     */
    public function readOperational(?int $now=null):array
    {
        $now??=time();$entryRemaining=$this->budget->remainingMilliseconds();$this->auth();
        if($now<946684800||$now>time())return $this->unavailable('invalid_clock');
        if($this->pdo->inTransaction())return $this->unavailable('outer_transaction');
        try{
            return $this->budget->withReservedBytes(self::RELEASE_RESERVE,function()use($now,$entryRemaining):array{
                $out=['available'=>true,'reader_version'=>self::VERSION,'view'=>'operational_delivery_v1',
                    'checked_at'=>gmdate('Y-m-d\TH:i:s\Z',$now),'timezone'=>'Europe/Moscow',
                    'model_version'=>ModeledConversionSemanticPrediction::VERSION,
                    'confirmation'=>'saved_processing_state_not_semantic_validation',
                    'state'=>$this->state(),
                    'interpretation'=>['daily_totals_use_exact_current_owner_scope'=>true,
                        'bounded_ledger_suffix_is_not_a_daily_total'=>true,
                        'receipt_and_processing_are_not_individual_visit_linkage'=>true,
                        'idle_tick_does_not_mean_no_saved_forecasts'=>true,
                        'historical_audits_not_executed'=>true],
                    'limits'=>['max_selected_bytes'=>65536,'max_execution_seconds'=>5,'max_response_bytes'=>28000],
                    'provider_requests_in_reader'=>0,'model_calls'=>0,'conversion_uploads'=>0,
                    'read_budget'=>['basis'=>'shared_budget_v1','remaining_ms'=>['reader_entry'=>$entryRemaining]]];
                $out['daily_reconciliation']=$this->optionalRead(fn():array=>
                    (new ModeledConversionDailyReconciliationReader($this->pdo,$this->authorize,null,$this->budget))->read([],$now));
                $out['read_budget']['remaining_ms']['daily_reconciliation']=$this->budget->remainingMilliseconds();
                $out['external_monitor']=$this->optionalRead(fn():array=>
                    ModeledConversionDeliveryHealth::externalStatus($this->budget,$this->authorize,$now));
                $out['value_delivery']=$this->optionalRead(fn():array=>
                    (new ModeledConversionValueDeliveryReader($this->pdo,$this->authorize,$this->budget))->read($now,false));
                $out['read_budget']['remaining_ms']['value_delivery']=$this->budget->remainingMilliseconds();
                $out['today_latency']=$this->optionalRead(fn():array=>
                    (new ModeledConversionLatencyReader($this->pdo,$this->authorize,$this->budget))->read($now));
                $out['telegram_linkage']=$this->optionalRead(fn():array=>
                    (new ModeledConversionTelegramLinkageReader($this->pdo,$this->authorize,$this->budget))->read($now));
                $out['visit_delivery_check']=$this->optionalRead(fn():array=>ModeledConversionVisitProof::privateSummary($now,$this->budget));
                $out['read_budget']['remaining_ms']['optional_views']=$this->budget->remainingMilliseconds();
                $out=$this->boundedOperationalResponse($out);$this->auth();return$out;
            });
        }catch(ModeledConversionStatusLimit|DomainException $error){throw$error;}
        catch(PDOException $ignored){return$this->unavailable('storage_unavailable');}
    }
    private function boundedOperationalResponse(array $out):array
    {
        $bytes=static fn(array $v):int=>strlen(json_encode($v,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR));
        foreach([['visit_delivery_check','followup_sent_cohort','failure_examples'],['visit_delivery_check','examples']]as$path){
            if(count($path)===3){
                while($bytes($out)>28000&&!empty($out[$path[0]][$path[1]][$path[2]])){
                    array_pop($out[$path[0]][$path[1]][$path[2]]);
                    $out[$path[0]][$path[1]]['examples_omitted_due_to_response_budget']=true;
                }
            }else{
                while($bytes($out)>28000&&!empty($out[$path[0]][$path[1]])){
                    array_pop($out[$path[0]][$path[1]]);
                    $out[$path[0]]['examples_omitted_due_to_response_budget']=true;
                }
            }
        }
        foreach(['telegram_linkage','today_latency','visit_delivery_check']as$key){
            if($bytes($out)<=28000)break;
            $automatic=$key==='visit_delivery_check'?($out[$key]['automatic']??null):null;
            $out[$key]=['available'=>false,'reason'=>'response_budget'];
            if(is_array($automatic))$out[$key]['automatic']=$automatic;
        }
        foreach(['historical_review','price_continuity_audit']as$key)
            if($bytes($out)>28000&&isset($out['value_delivery'][$key]))
                $out['value_delivery'][$key]=['available'=>false,'reason'=>'response_budget'];
        if($bytes($out)>28000)throw new ModeledConversionStatusLimit('byte_limit');
        return$out;
    }

    public function read():array
    {
        $entryRemaining=$this->budget->remainingMilliseconds();
        $this->auth();
        if($this->pdo->inTransaction())return $this->unavailable('outer_transaction');
        try{
            $this->copied=0;$out=$this->emptyResult();
            $out['read_budget']=['basis'=>'shared_budget_v1',
                'remaining_ms'=>['reader_entry'=>$entryRemaining]];
            $out['state']=$this->state();
            $out['read_budget']['remaining_ms']['state']=$this->budget->remainingMilliseconds();
            $model=hash_file('sha256',__DIR__.'/ModeledConversionSemanticPrediction.php');
            $processor=hash_file('sha256',__DIR__.'/ModeledConversionProfiles.php');
            if(!is_string($model)||!is_string($processor))return $this->unavailable('source_unavailable');
            $parts=$this->parts();$sum=implode('+',array_map(fn(string $s):string=>'COALESCE('.$this->length($s).',0)',array_values($parts)));
            $bodyBytes=implode('+',array_map(fn(string $name):string=>'COALESCE('.$this->length($name).',0)',array_keys($parts)));
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
            $out['read_budget']['remaining_ms']['metadata']=$this->budget->remainingMilliseconds();
            $out['window']['metadata_rows']=count($meta);$out['window']['metadata_limit_reached']=count($meta)===self::METADATA_LIMIT;
            $fence=$meta?(string)$meta[0]['id']:'0';$scopes=[];
            $next=0;
            $readProfiles=function(int $limit)use(&$out,&$scopes,&$next,$meta,$parts,$bodyBytes,$fence,$model,$processor):void{
                for(;$next<count($meta);$next++){
                    // Inspect one core packet after priority delivery evidence; use spare budget for the remaining candidates.
                    if($limit<self::PROFILE_LIMIT&&$out['window']['profile_rows_read']>=$limit)break;
                    $row=$meta[$next];
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
                    // Materialize the four parts once; both pre-copy size guards reuse their bounded aliases.
                    $body=$this->query('SELECT '.implode(',',array_keys($parts)).' FROM (SELECT '.implode(',',$columns)
                        .' FROM modeled_conversion_profile_versions WHERE project_key=? AND id=? AND id<=?'
                        .' AND JSON_VALID(profile_json) LIMIT 1 OFFSET 0) core_packet'
                        .' WHERE '.$bodyBytes.'=? AND '.$bodyBytes.'<=? LIMIT 1',
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
                        $p['deal_value_prediction']=json_decode($body[0]['deal_json']??'null',true,32,JSON_THROW_ON_ERROR);
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

                    if(is_array($p['deal_value_prediction']??null)){
                        $money=ModeledConversionDealValue::saved($p);
                        if($money!==null){
                            if(!isset($out['saved']['deal_value']))$out['saved']['deal_value']=['versions'=>0,'estimated_deal_versions'=>0,
                                'source_counts'=>[],'commission_rate'=>0.07,'deal_point_range_rub'=>['minimum'=>null,'maximum'=>null],
                                'value_range_rub'=>['minimum'=>null,'maximum'=>null],'early_window_counts'=>[],'by_monetary_policy'=>[]];
                            $mv=&$out['saved']['deal_value'];$mv['versions']++;
                            $key=$money['deal_amount_rub']['source_kind'];$mv['source_counts'][$key]=($mv['source_counts'][$key]??0)+1;
                            $key=$money['early_window'];$mv['early_window_counts'][$key]=($mv['early_window_counts'][$key]??0)+1;
                            if(is_int($money['deal_amount_rub']['point'])){$mv['estimated_deal_versions']++;$this->probabilityRange($mv['deal_point_range_rub'],$money['deal_amount_rub']['point']);}
                            if(is_int($money['value_rub']))$this->probabilityRange($mv['value_range_rub'],$money['value_rub']);
                            $version=$money['version'];$policy=ModeledConversionDealValue::policy($version);
                            if(!isset($mv['by_monetary_policy'][$version]))$mv['by_monetary_policy'][$version]=[
                                'versions'=>0,'value_cap_rub'=>$policy['cap_rub'],'unknown_price_base_rub'=>$policy['unknown_base_rub'],
                                'unknown_price_versions'=>0,'estimated_value_versions'=>0,
                                'full_value_base_range_rub'=>['minimum'=>null,'maximum'=>null]];
                            $policySummary=&$mv['by_monetary_policy'][$version];$policySummary['versions']++;
                            if($money['deal_amount_rub']['point']===null)$policySummary['unknown_price_versions']++;
                            if(is_int($money['value_rub']))$policySummary['estimated_value_versions']++;
                            $this->probabilityRange($policySummary['full_value_base_range_rub'],$money['full_value_base_rub']);
                            unset($policySummary,$mv);
                        }
                    }
                    $out['saved']['valid_v1_versions']++;$out['saved']['channel_counts'][$row['platform']]++;
                    $out['saved']['status_counts'][$s['status']]++;
                    $weighting=($s['mode']??null)==='contextual_advertising_v1'?'expert_dialogue':(($s['mode']??null)==='contextual_stage_baseline'?'stage_baseline':
                        (($s['training_status']??null)==='archive_relative_weights'?'archive_relative_weights':'illustrative'));
                    $out['saved']['weighting_version_counts'][$weighting]=($out['saved']['weighting_version_counts'][$weighting]??0)+1;
                    if($weighting==='archive_relative_weights'){
                        $weightSha=$s['relative_weights']['package_sha256'];
                        $out['saved']['relative_weight_package_counts'][$weightSha]=($out['saved']['relative_weight_package_counts'][$weightSha]??0)+1;
                    }
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
            };
            // Saved financial and three-case visit evidence has priority over optional cached context and profile bodies.
            $out['value_delivery']=$this->optionalRead(fn():array=>(new ModeledConversionValueDeliveryReader($this->pdo,$this->authorize,$this->budget))->read(time()));
            $out['read_budget']['remaining_ms']['value_delivery']=$this->budget->remainingMilliseconds();
            $out['visit_delivery_check']=$this->optionalRead(fn():array=>ModeledConversionVisitProof::privateSummary(time(),$this->budget));
            $out['read_budget']['remaining_ms']['visit_receipt']=$this->budget->remainingMilliseconds();
            $readProfiles(1);
            $out['read_budget']['remaining_ms']['first_profile']=$this->budget->remainingMilliseconds();
            $activation=$out['value_delivery']['activated_at']??null;
            $activation=is_string($activation)?strtotime($activation):false;
            $out['saved_input_diagnosis']=$this->optionalRead(fn():array=>(new ModeledConversionInputDiagnosisReader($this->pdo,$this->authorize,$this->budget))->read($activation===false?null:$activation),4000);
            $reportNow=time(); // Both calendar sections belong to the same Moscow day.
            $out['today_latency']=$this->optionalRead(fn():array=>(new ModeledConversionLatencyReader($this->pdo,$this->authorize,$this->budget))->read($reportNow));
            $out['daily_delivery']=$this->optionalRead(fn():array=>(new ModeledConversionDailyDeliveryReader($this->pdo,$this->authorize,$this->budget))->read($reportNow));
            // This bounded reader already owns an 8KiB admission gate; do not reserve that headroom twice.
            $out['telegram_linkage']=(new ModeledConversionTelegramLinkageReader($this->pdo,$this->authorize,$this->budget))->read(time());
            $out['external_monitor']=$this->optionalRead(fn():array=>ModeledConversionDeliveryHealth::externalStatus($this->budget,$this->authorize,time()));
            $out['read_budget']['remaining_ms']['optional_views']=$this->budget->remainingMilliseconds();
            $readProfiles(self::PROFILE_LIMIT);
            $out['read_budget']['remaining_ms']['remaining_profiles']=$this->budget->remainingMilliseconds();
            $out['window']['selected_document_bytes']=$this->copied;
            $out['saved']['distinct_inspected_scopes']=$out['saved']['valid_v1_versions'];
            $out['confirmation']=$out['saved']['prospective_estimates']>0?'saved_prospective_estimates':
                ($out['saved']['valid_v1_versions']>0?'saved_v1_without_prospective_estimate':'no_v1_confirmation_in_checked_suffix');
            $out=$this->boundedResponse($out);
            $this->auth();return $out;
        }catch(ModeledConversionStatusLimit $error){throw $error;}
        catch(PDOException $ignored){return $this->unavailable('storage_unavailable');}
    }

    /** Keep the unchanged adapter ceiling; counts survive while optional examples are explicitly shortened. */
    private function boundedResponse(array $out):array
    {
        $bytes=static fn(array $v):int=>strlen(json_encode($v,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR));
        $native=$out['value_delivery']['repair_assessments']['retained_native_identity_evidence']??null;
        if(is_array($native)){
            // Presentation only: this reader result has already been computed. Never query it again.
            $views=$this->nativeIdentityPresentations($native,$out['value_delivery']['repair_assessments']['finite_visit_evidence']??[]);
            $out['value_delivery']['repair_assessments']['retained_native_identity_evidence']=$views[0];
        }
        // Current delivery calculations take precedence over optional historic failure details.
        while($bytes($out)>28000&&!empty($out['visit_delivery_check']['followup_sent_cohort']['failure_examples'])){
            array_pop($out['visit_delivery_check']['followup_sent_cohort']['failure_examples']);
            $out['visit_delivery_check']['followup_sent_cohort']['failure_examples_omitted_due_to_response_budget']=true;
        }
        foreach([['value_delivery','predictive_v1','examples'],['saved_input_diagnosis','examples'],['visit_delivery_check','examples']]as$path){
            if(count($path)===3){
                while($bytes($out)>28000&&!empty($out[$path[0]][$path[1]][$path[2]])){
                    array_pop($out[$path[0]][$path[1]][$path[2]]);
                    $out[$path[0]][$path[1]]['examples_omitted_due_to_response_budget']=true;
                }
            }else{
                while($bytes($out)>28000&&!empty($out[$path[0]][$path[1]])){
                    array_pop($out[$path[0]][$path[1]]);
                    $out[$path[0]]['examples_omitted_due_to_response_budget']=true;
                }
            }
        }
        // Exact repair evidence wins over optional examples above. If fixed output
        // still cannot fit, omit only the last cases and report the loss explicitly.
        while($bytes($out)>28000&&!empty($out['value_delivery']['repair_assessments']['exact_cases']['rows'])){
            $removed=array_pop($out['value_delivery']['repair_assessments']['exact_cases']['rows']);
            if(($removed['identity_resolution']['status']??null)==='observed')
                $out['value_delivery']['repair_assessments']['exact_cases']['identity_evidence']['observed_case_count']--;
            $window=&$out['value_delivery']['repair_assessments']['exact_cases']['window'];
            $window['returned_events']=count($out['value_delivery']['repair_assessments']['exact_cases']['rows']);
            $window['complete_within_bound']=false;$window['omitted_due_to_response_budget']=true;unset($window);
        }
        if($bytes($out)>28000&&isset($out['value_delivery']['repair_assessments']['initial_api_receipts']))
            $out['value_delivery']['repair_assessments']['initial_api_receipts']=['available'=>false,'reason'=>'response_budget','provider_requests_in_reader'=>0];
        if($bytes($out)>28000&&isset($out['value_delivery']['repair_assessments']['retained_native_identity_evidence']))
            $out['value_delivery']['repair_assessments']['retained_native_identity_evidence']=[
                'available'=>false,'reason'=>'response_budget','provider_requests_in_reader'=>0,'financial_permission'=>false];
        if(($out['value_delivery']['repair_assessments']['processing_frontier']['available']??false)===true){
            foreach(['today_latency','telegram_linkage','saved_input_diagnosis','input_coverage','saved','window']as$key)
                if($bytes($out)>28000&&isset($out[$key]))
                    $out[$key]=['available'=>false,'reason'=>'response_budget'];
            foreach(['predictive_v1','historical_review']as$key)
                if($bytes($out)>28000&&isset($out['value_delivery'][$key]))
                    $out['value_delivery'][$key]=['available'=>false,'reason'=>'response_budget'];
        }
        if(isset($out['value_delivery']['repair_assessments']['retained_tracker_candidate'])||isset($out['value_delivery']['repair_assessments']['retained_known_identities']))
            foreach(['retained_known_identities','retained_tracker_candidate','retained_identity']as$key)
                if($bytes($out)>28000&&isset($out['value_delivery']['repair_assessments'][$key]))
                    $out['value_delivery']['repair_assessments'][$key]=['available'=>false,'reason'=>'response_budget','financial_permission'=>false];
        if($bytes($out)>28000&&isset($out['value_delivery']['repair_assessments']['retained_relation_outcomes'])){
            // Preserve the two saved phase outcomes before omitting their optional detail.
            // This is formatting only: the owner reader already authenticated both bindings and caches.
            $compact=$this->compactRetainedRelationOutcomes($out['value_delivery']['repair_assessments']['retained_relation_outcomes']);
            if($compact!==null)$out['value_delivery']['repair_assessments']['retained_relation_outcomes']=$compact;
            if($bytes($out)>28000)$out['value_delivery']['repair_assessments']['retained_relation_outcomes']=[
                'available'=>false,'reason'=>'response_budget','provider_requests_in_reader'=>0,'financial_permission'=>false,'current_eligibility'=>'not_assessed'];
        }
        if($bytes($out)>28000&&isset($out['value_delivery']['repair_assessments']['processing_frontier']))
            $out['value_delivery']['repair_assessments']['processing_frontier']=['available'=>false,'reason'=>'response_budget','provider_requests_in_reader'=>0];
        // Preserve the original three-case proof when omitting the newer forward journal is sufficient.
        if($bytes($out)>28000&&isset($out['value_delivery']['repair_assessments']['finite_visit_recovery']))
            $out['value_delivery']['repair_assessments']['finite_visit_recovery']=['available'=>false,'reason'=>'response_budget','provider_requests_in_reader'=>0];
        foreach(['finite_counter_scope_evidence','fixed_pair_operational_decisions']as$key)
            if($bytes($out)>28000&&isset($out['value_delivery']['repair_assessments'][$key]))
                $out['value_delivery']['repair_assessments'][$key]=['available'=>false,'reason'=>'response_budget','financial_permission'=>false,'provider_requests_in_reader'=>0];
        if($bytes($out)>28000&&isset($out['value_delivery']['repair_assessments']['finite_visit_evidence']))
            $out['value_delivery']['repair_assessments']['finite_visit_evidence']=['available'=>false,'reason'=>'response_budget','provider_requests_in_reader'=>0];
        if(($out['value_delivery']['repair_assessments']['finite_visit_recovery']['available']??false)===true
            &&($out['value_delivery']['repair_assessments']['finite_visit_evidence']['available']??false)!==true)
            $out['value_delivery']['repair_assessments']['finite_visit_recovery']=['available'=>false,'reason'=>'original_current_binding_unavailable','provider_requests_in_reader'=>0];
        foreach(['fixed_pair_operational_decisions','finite_counter_scope_evidence']as$key)
            if(($out['value_delivery']['repair_assessments'][$key]['available']??false)===true
                &&($out['value_delivery']['repair_assessments']['finite_visit_evidence']['available']??false)!==true)
                $out['value_delivery']['repair_assessments'][$key]=['available'=>false,'reason'=>'original_current_binding_unavailable','financial_permission'=>false,'provider_requests_in_reader'=>0];
        if(($out['value_delivery']['repair_assessments']['retained_relation_outcomes']['available']??false)===true
            &&($out['value_delivery']['repair_assessments']['finite_visit_evidence']['available']??false)!==true)
            $out['value_delivery']['repair_assessments']['retained_relation_outcomes']=[
                'available'=>false,'reason'=>'original_current_binding_unavailable','financial_permission'=>false,
                'current_eligibility'=>'not_assessed','provider_requests_in_reader'=>0];
        if(is_array($native)){
            // Mandatory financial evidence retains priority. Recheck the visible original binding.
            foreach($this->nativeIdentityPresentations($native,$out['value_delivery']['repair_assessments']['finite_visit_evidence']??[])as$view){
                $next=$out;$next['value_delivery']['repair_assessments']['retained_native_identity_evidence']=$view;
                if($bytes($next)<=28000){$out=$next;break;}
            }
        }
        return $out;
    }

    /**
     * Compact only the authenticated historical two-case projection, never private capsules.
     * Admission-mask bits: source=1, recorded-day relation=2, visit base=4, enrichment=8.
     * A set bit records durable intent, not proof of provider execution; unknown metadata stays null.
     * Clocks, hashes and other detail remain available only in the unchanged full projection.
     */
    private function compactRetainedRelationOutcomes(array $value):?array
    {
        if(($value['available']??null)!==true||($value['financial_permission']??null)!==false
            ||($value['current_eligibility']??null)!=='not_assessed'||($value['provider_requests_in_reader']??null)!==0
            ||!is_array($value['rows']??null)||count($value['rows'])!==2)return null;
        $rows=[];$seen=[];
        foreach($value['rows']as$row){
            if(!is_array($row)||!in_array($row['case_index']??null,[1,2],true)||isset($seen[$row['case_index']])
                ||($row['historical']??null)!==true||($row['financial_permission']??null)!==false
                ||($row['current_eligibility']??null)!=='not_assessed'
                ||!is_string($row['status']??null)||preg_match('/\A[a-z][a-z0-9_]{0,63}\z/D',$row['status'])!==1)return null;
            $seen[$row['case_index']]=true;
            $reason=$row['reason']??$row['relation_reason']??null;
            if($reason!==null&&(!is_string($reason)||preg_match('/\A[a-z][a-z0-9_]{0,63}\z/D',$reason)!==1))return null;
            $mask=0;
            foreach(['source'=>1,'recorded_day_relation'=>2,'visit_base'=>4,'visit_sources'=>8]as$phase=>$bit){
                $flag=$row['phases_admitted'][$phase]??null;
                if(!is_bool($flag)){$mask=null;break;}
                if($flag)$mask|=$bit;
            }
            $rows[]=[$row['case_index'],$row['status'],$reason,$mask];
        }
        return ['available'=>true,'projection'=>'compact_v1','historical'=>true,
            'columns'=>['case_index','status','reason','phase_admission_mask'],'rows'=>$rows,
            'provider_requests_in_reader'=>0,'financial_permission'=>false,'current_eligibility'=>'not_assessed'];
    }

    /**
     * Format one existing local point-reader result; no PDO, file or provider access.
     * counts: inspected rows, admitted native witnesses, distinct candidate ClientIDs.
     * first_rejections uses the fixed first-failed-guard order below, including zeroes.
     * A frame-only binding and missing counts never attest that the point SELECT executed.
     */
    private function nativeIdentityPresentations(array $value,array $original):array
    {
        $fail=static fn(string $reason):array=>[['available'=>false,'reason'=>$reason,
            'provider_requests_in_reader'=>0,'financial_permission'=>false,'visit_linkage'=>'not_checked']];
        $clock=static function(mixed $v):?int{
            if(!is_string($v)||preg_match('/\A[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}Z\z/D',$v)!==1)return null;
            $d=DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s\Z',$v,new DateTimeZone('UTC'));
            return $d&&$d->format('Y-m-d\TH:i:s\Z')===$v&&$d->getTimestamp()>=946684800?$d->getTimestamp():null;
        };
        if(($original['available']??null)!==true||!is_array($original['rows']??null)
            ||!array_is_list($original['rows'])||count($original['rows'])!==3)return $fail('original_current_binding_unavailable');
        $missing=[];$seen=[];
        foreach($original['rows']as$i=>$row){
            if(!is_array($row))return $fail('original_current_binding_unavailable');
            $ref=$row['event_ref']??null;
            if(!is_string($ref)||preg_match('/\A[a-f0-9]{64}\z/D',$ref)!==1||isset($seen[$ref]))
                return $fail('original_current_binding_unavailable');
            $seen[$ref]=true;
            if(($row['source_status']??null)==='no_valid_canonical_identity_and_no_admissible_retained_identity'
                &&array_key_exists('candidate_sha256',$row)&&$row['candidate_sha256']===null
                &&($row['report_status']??null)==='not_requested')$missing[$i]=$row;
        }
        if(count($missing)!==1||!isset($missing[0])||($eventAt=$clock($missing[0]['event_at']??null))===null)
            return $fail('original_current_binding_unavailable');
        $ref=$missing[0]['event_ref'];
        if(($value['basis']??null)!=='bounded_local_native_processed_receipt_metadata'
            ||($value['scope']??null)!=='same_project_platform_external_user_and_counter_across_conversations'
            ||($value['provider_requests_in_reader']??null)!==0||($value['financial_permission']??null)!==false
            ||($value['visit_linkage']??null)!=='not_checked'||($value['acknowledgement_time']??null)!=='not_persisted'
            ||($value['max_rows']??null)!==64||!is_bool($value['available']??null))return $fail('invalid_native_projection');
        $common=['available','basis','scope','provider_requests_in_reader','financial_permission','visit_linkage',
            'acknowledgement_time','max_rows','complete_within_bound'];
        $compact=['available'=>$value['available'],'projection'=>'compact_native_identity_v1','case_index'=>0,'event_ref'=>$ref,
            'binding_state'=>$value['available']?'verified_original_and_result':'original_frame_only',
            'snapshot_at'=>null,'status'=>null,'scope'=>'same_subject_outbox_point_v1','max_rows'=>64,
            'complete_within_bound'=>false,'point_read_execution'=>$value['available']?'completed':'not_attested','counts'=>null,'first_rejections'=>null,
            'candidate_sha256'=>null,'source_observed_at'=>null,'global_absence_proven'=>false,
            'financial_permission'=>false,'visit_linkage'=>'not_checked','provider_requests_in_reader'=>0];
        if(!$value['available']){
            $reasons=['original_current_binding_unavailable','single_missing_original_identity_not_verified','original_scope_unavailable',
                'budget_headroom','point_index_unavailable','row_limit','point_storage_unavailable','invalid_point_projection'];
            if(array_diff(array_keys($value),array_merge($common,['reason']))
                ||($value['complete_within_bound']??null)!==false||!in_array($value['reason']??null,$reasons,true))
                return $fail('invalid_native_projection');
            $compact['reason']=$value['reason'];
            // budget_headroom covers both the pre-query guard and a packet-copy refusal.
            return [$value,$compact];
        }
        $fields=['event_ref','snapshot_at','inspected_rows','admitted_native_witnesses','first_rejection_reason_counts','facets',
            'classification_basis','unique_candidate_count','candidate_sha256','source_observed_at','source_provenance',
            'local_absence_does_not_prove_tracker_or_visit_absence','status'];
        if(array_diff(array_keys($value),array_merge($common,$fields))||($value['event_ref']??null)!==$ref
            ||($value['complete_within_bound']??null)!==true
            ||($value['classification_basis']??null)!=='independent_field_facets_and_first_failed_native_witness_guard_not_identity_permission'
            ||($value['local_absence_does_not_prove_tracker_or_visit_absence']??null)!==true
            ||($snapshot=$clock($value['snapshot_at']??null))===null)return $fail('invalid_native_projection');
        $counts=[];
        foreach(['inspected_rows','admitted_native_witnesses','unique_candidate_count']as$key){
            $n=$value[$key]??null;if(!is_int($n)||$n<0||$n>64)return $fail('invalid_native_projection');$counts[]=$n;
        }
        if($counts[2]>$counts[1]||$counts[1]>$counts[0]||($counts[1]>0&&$counts[2]===0))return $fail('invalid_native_projection');
        $order=['unsupported_goal_pair','mixed_family_metadata','not_processed_pair','saved_error_present','invalid_upload_receipt_id',
            'invalid_attempt_or_claim_clock','invalid_client_or_tracker_record','missing_or_invalid_source_clock','source_after_signal_or_claim'];
        $rejections=$value['first_rejection_reason_counts']??null;
        if(!is_array($rejections)||count($rejections)!==9||array_diff(array_keys($rejections),$order))return $fail('invalid_native_projection');
        $rejects=[];
        foreach($order as$key){$n=$rejections[$key]??null;if(!is_int($n)||$n<0||$n>64)return $fail('invalid_native_projection');$rejects[]=$n;}
        if(array_sum($rejects)+$counts[1]!==$counts[0])return $fail('invalid_native_projection');
        $facetKeys=['family'=>['native','modeled_value','other'],'state'=>['pending','ready','uploading','accepted','processed','unknown','failed','expired','unmatched','other'],
            'identity_fields'=>['canonical_client_id','canonical_tracker_record_id','valid_source_clock','source_clock_at_or_before_event']];
        if(!is_array($value['facets']??null)||count($value['facets'])!==3||array_diff(array_keys($value['facets']),array_keys($facetKeys)))
            return $fail('invalid_native_projection');
        foreach($facetKeys as$group=>$keys){
            $f=$value['facets'][$group]??null;if(!is_array($f)||count($f)!==count($keys)||array_diff(array_keys($f),$keys))return $fail('invalid_native_projection');
            foreach($keys as$key)if(!is_int($f[$key]??null)||$f[$key]<0||$f[$key]>$counts[0])return $fail('invalid_native_projection');
            if($group!=='identity_fields'&&array_sum($f)!==$counts[0])return $fail('invalid_native_projection');
        }
        $expected=$counts[2]===1?'candidate_observed':($counts[2]>1?'ambiguous_native_candidates':'no_admissible_native_witness');
        if(($value['status']??null)!==$expected)return $fail('invalid_native_projection');
        if($counts[2]===1){
            $hash=$value['candidate_sha256']??null;$source=$clock($value['source_observed_at']??null);
            if(!is_string($hash)||preg_match('/\A[a-f0-9]{64}\z/D',$hash)!==1||$source===null||$source>$eventAt||$source>$snapshot
                ||($value['source_provenance']??null)!=='retained_native_processed_tracker_source')return $fail('invalid_native_projection');
            $compact['candidate_sha256']=$hash;$compact['source_observed_at']=$value['source_observed_at'];
        }elseif(($value['candidate_sha256']??null)!==null||($value['source_observed_at']??null)!==null
            ||($value['source_provenance']??null)!==null)return $fail('invalid_native_projection');
        $compact['snapshot_at']=$value['snapshot_at'];$compact['status']=$expected;$compact['complete_within_bound']=true;
        $compact['counts']=$counts;$compact['first_rejections']=$rejects;
        if(strlen(json_encode($compact,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR))>768)
            return $fail('invalid_native_projection');
        return [$value,$compact];
    }

    /** Resource omission is explicit; access denial and the shared deadline still fail closed. */
    private function optionalRead(callable $read,int $minimumMilliseconds=0):array
    {
        $remaining=$this->budget->remainingMilliseconds();
        if($remaining<$minimumMilliseconds)return ['available'=>false,'reason'=>'time_headroom',
            'minimum_required_ms'=>$minimumMilliseconds,'remaining_ms'=>$remaining];
        if($this->budget->remainingBytes()<self::OPTIONAL_RESERVE+4096)
            return ['available'=>false,'reason'=>'budget_headroom'];
        try{return $this->budget->withReservedBytes(self::OPTIONAL_RESERVE,$read);}
        catch(ModeledConversionStatusLimit $error){
            if($error->reason!=='byte_limit')throw $error;
            $this->budget->check();
            return ['available'=>false,'reason'=>'budget_headroom'];
        }
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
        return ['prediction_json'=>$s('semantic_prediction'),'followup_json'=>$s('prediction_followup'),'deal_json'=>$s('deal_value_prediction'),
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
                'weighting_version_counts'=>['illustrative'=>0,'stage_baseline'=>0,'archive_relative_weights'=>0],'relative_weight_package_counts'=>[],
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
