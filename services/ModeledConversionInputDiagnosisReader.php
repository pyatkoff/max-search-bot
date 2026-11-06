<?php
declare(strict_types=1);
require_once __DIR__.'/ModeledConversionStatusBudget.php';

/** Fixed scalar diagnosis of saved profiles. No source text, processing or writes. */
final class ModeledConversionInputDiagnosisReader
{
    public const LIMIT=100;
    private const ERRORS=['invalid_message_metadata','action_or_unknown_message_type','source_clock_unresolved',
        'text_unavailable_or_oversize','forwarded_statement_owner_unresolved','payload_unavailable','scope_denied',
        'source_scope_changed_at_information_fence','wizard_input_missing_or_ambiguous','wizard_input_mismatch_or_unresolved',
        'invalid_wizard_application','uninterpreted_event'];
    private bool $mysql;
    public function __construct(private PDO $pdo,private $authorize,private ModeledConversionStatusBudget $budget)
    {$this->mysql=$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql';}

    public function read(?int $activated=null):array
    {
        $this->auth();
        try{
            $model=hash_file('sha256',__DIR__.'/ModeledConversionSemanticPrediction.php');
            $processor=hash_file('sha256',__DIR__.'/ModeledConversionProfiles.php');
            $safe="CASE WHEN JSON_VALID(profile_json) THEN profile_json ELSE '{}' END";
            $get=fn(string $p):string=>"JSON_EXTRACT(".$safe.",'$.".$p."')";
            $scalar=fn(string $p):string=>$this->mysql?'JSON_UNQUOTE('.$get($p).')':$get($p);
            $type=fn(string $p):string=>$this->mysql?'JSON_TYPE('.$get($p).')':"json_type(".$safe.",'$.".$p."')";
            $count=function(string $p,int $limit=1000)use($get,$type):string{
                return 'CASE WHEN '.$type($p)."='".($this->mysql?'INTEGER':'integer')."' AND ".$get($p).' BETWEEN 0 AND '.$limit
                    .' THEN CAST('.$get($p).($this->mysql?' AS UNSIGNED)':' AS INTEGER)').' ELSE NULL END';
            };
            $enum=function(string $p,array $values)use($scalar):string{
                return 'CASE WHEN '.$scalar($p)." IN ('".implode("','",$values)."') THEN ".$scalar($p).' ELSE NULL END';
            };
            $ns='CASE WHEN '.$scalar('semantic_prediction.version')."='semantic_purchase_heuristic_v1' AND "
                .$scalar('semantic_prediction.model_sha256').'=? AND '.$scalar('processing.source_sha256').'=? THEN 1 ELSE 0 END';
            $errorShape=$this->mysql?"JSON_TYPE(".$get('coverage.errors').")='OBJECT'":"json_type(".$safe.",'$.coverage.errors')='object'";
            $errors=[];
            foreach(self::ERRORS as$key){
                $path='coverage.errors.'.$key;
                $errors[]='CASE WHEN '.$errorShape.' THEN CASE WHEN '.$type($path).' IS NULL THEN 0 ELSE '.$count($path).' END ELSE NULL END';
            }
            $columns=['id','platform','customer_key','conversation_id','observed_at_unix',$ns,'JSON_VALID(profile_json)',
                $count('contextual_signals.engagement.customer_turns',50),$count('contextual_signals.engagement.manager_turns',50),
                $count('coverage.applied_wizard_fields',50),$count('coverage.errors.action_or_unknown_message_type'),
                $enum('semantic_prediction.status',['predicted','insufficient_context','known_outcome','access_denied']),
                $enum('semantic_prediction.stage',['substantive_dialogue','concrete_request','exact_offer_discussion','general_purchase_intent','agency_purchase_intent']),
                'JSON_ARRAY('.implode(',',$errors).')',$enum('access_status',['allowed','denied'])];
            $sql='SELECT JSON_ARRAY('.implode(',',$columns).') AS diagnosis_json'
                .' FROM (SELECT id,platform,customer_key,conversation_id,observed_at_unix,profile_json'
                ." FROM modeled_conversion_profile_versions WHERE project_key='anytour' AND platform IN ('max','telegram')"
                .' ORDER BY id DESC LIMIT '.self::LIMIT.') bounded_profiles ORDER BY id DESC';
            // One aggregate preflight avoids copying optional packets or one size record per profile.
            $packetBytes=$this->mysql?'OCTET_LENGTH(JSON_QUOTE(CAST(diagnosis_json AS CHAR)))':'LENGTH(CAST(JSON_QUOTE(CAST(diagnosis_json AS TEXT)) AS BLOB))';
            $estimate=$this->budget->prepare($this->pdo,'SELECT COALESCE(SUM('.$packetBytes.'+24),0)+2 AS packet_bytes FROM ('.$sql.') packet_sizes');
            $estimate->bindValue(1,$model,PDO::PARAM_STR);$estimate->bindValue(2,$processor,PDO::PARAM_STR);$estimate->execute();
            $size=$estimate->fetch(PDO::FETCH_ASSOC);$estimate->closeCursor();$this->auth();
            $this->budget->consumeBytes(strlen(json_encode($size,JSON_THROW_ON_ERROR)));
            if((int)($size['packet_bytes']??PHP_INT_MAX)+8192>$this->budget->remainingBytes())return ['available'=>false,'reason'=>'budget_headroom'];
            $q=$this->budget->prepare($this->pdo,$sql);$q->bindValue(1,$model,PDO::PARAM_STR);$q->bindValue(2,$processor,PDO::PARAM_STR);
            $q->execute();$rows=$q->fetchAll(PDO::FETCH_ASSOC);$q->closeCursor();$this->auth();
            $bytes=strlen(json_encode($rows,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR));
            $this->budget->consumeBytes($bytes);
            $out=['available'=>true,'basis'=>'fixed_recent_saved_profile_counters_not_source_reinterpretation',
                'window'=>['max_versions'=>self::LIMIT,'inspected_versions'=>count($rows),'complete_within_bound'=>count($rows)<self::LIMIT,
                    'current_namespace_versions'=>0,'other_namespace_versions'=>0,'invalid_json_versions'=>0,'distinct_current_scopes'=>0],
                'scope_counts'=>['max'=>0,'telegram'=>0,'with_customer_text'=>0,'without_customer_text'=>0,'unknown_customer_coverage'=>0,
                    'with_wizard_applications'=>0,'without_wizard_applications'=>0,'unknown_wizard_coverage'=>0,
                    'with_recorded_action_rows'=>0,'profiles_frozen_since_value_activation'=>0],
                'recorded_status_counts'=>array_fill_keys(['predicted','insufficient_context','known_outcome','access_denied','unknown'],0),
                'omission_counts'=>array_fill_keys(self::ERRORS,0),'unknown_omission_metadata_scopes'=>0,'examples'=>[],
                'interpretation'=>['counters_not_confirmed_signals'=>true,'repeated_versions_not_people_or_trips'=>true,
                    'frozen_since_activation_not_new_customer_evidence'=>true,'examples_anonymous_distinct_scopes'=>true,
                    'positive_sample_not_export_permission'=>true,'source_text_read'=>false,'population_coverage_known'=>false]];
            $seen=[];$examples=[];$buckets=[];
            foreach($rows as$row){
                $v=json_decode((string)($row['diagnosis_json']??''),true,16,JSON_THROW_ON_ERROR);
                if(!is_array($v)||count($v)!==15||!in_array($v[1],['max','telegram'],true))continue;
                if((int)$v[6]!==1)$out['window']['invalid_json_versions']++;
                if((int)$v[5]!==1){$out['window']['other_namespace_versions']++;continue;}
                $out['window']['current_namespace_versions']++;
                if(!is_string($v[2])||!is_string($v[3])||preg_match('/\A[1-9][0-9]{0,19}\z/D',$v[2])!==1
                    ||preg_match('/\A[1-9][0-9]{0,19}\z/D',$v[3])!==1)continue;
                $key=$v[1].':'.$v[2].':'.$v[3];if(isset($seen[$key]))continue;$seen[$key]=true;
                $out['window']['distinct_current_scopes']++;$out['scope_counts'][$v[1]]++;
                $customer=is_int($v[7])?$v[7]:null;$manager=is_int($v[8])?$v[8]:null;$wizard=is_int($v[9])?$v[9]:null;
                $actions=is_int($v[10])?$v[10]:null;
                $out['scope_counts'][$customer===null?'unknown_customer_coverage':($customer>0?'with_customer_text':'without_customer_text')]++;
                $out['scope_counts'][$wizard===null?'unknown_wizard_coverage':($wizard>0?'with_wizard_applications':'without_wizard_applications')]++;
                if($actions!==null&&$actions>0)$out['scope_counts']['with_recorded_action_rows']++;
                $after=$activated!==null&&is_numeric($v[4])&&(int)$v[4]>=$activated;
                if($after)$out['scope_counts']['profiles_frozen_since_value_activation']++;
                $status=in_array($v[11],['predicted','insufficient_context','known_outcome','access_denied'],true)?$v[11]:'unknown';
                $out['recorded_status_counts'][$status]++;
                $reasons=[];$unknown=false;$ev=$v[13];
                foreach(self::ERRORS as$i=>$reason){
                    $n=is_array($ev)?($ev[$i]??null):null;
                    if(!is_int($n)||$n<0||$n>1000){$unknown=true;continue;}
                    $out['omission_counts'][$reason]+=$n;if($n>0)$reasons[$reason]=$n;
                }
                if($unknown)$out['unknown_omission_metadata_scopes']++;
                $shape=$customer===null?'unknown':($customer>0?'customer_text':($wizard!==null&&$wizard>0?'wizard_without_customer_text':'without_customer_text_or_applied_wizard'));
                $sample=['platform'=>$v[1],'saved_at'=>is_numeric($v[4])&&(int)$v[4]>=946684800&&((int)$v[4])<=time()?gmdate('Y-m-d\TH:i:s\Z',(int)$v[4]):null,
                    'frozen_since_value_activation'=>$activated===null?null:$after,'input_shape'=>$shape,
                    'admitted_customer_turns'=>$customer,'admitted_manager_turns'=>$manager,'applied_wizard_fields'=>$wizard,
                    'recorded_action_or_unknown_type_rows'=>$actions,'recorded_status'=>$status,'recorded_stage'=>$v[12],
                    'recorded_access'=>$v[14],'omission_counts'=>$reasons,'omission_metadata_complete'=>!$unknown];
                $examples[]=$sample;$bucket=$v[1].':'.$shape;
                if(!isset($buckets[$bucket]))$buckets[$bucket]=$sample;
            }
            $selected=array_values($buckets);
            foreach($examples as$example){if(count($selected)>=8)break;if(!in_array($example,$selected,true))$selected[]=$example;}
            $out['examples']=array_slice($selected,0,8);
            $out['progress']=$this->progress();$this->auth();return $out;
        }catch(ModeledConversionStatusLimit $e){throw $e;}
        catch(PDOException|JsonException $ignored){$this->auth();return ['available'=>false,'reason'=>'saved_input_diagnosis_unavailable'];}
    }

    /** Bounded derived checkpoint read; never advances either cursor. */
    private function progress():array
    {
        $out=['profiles_recorded_pending_revisions'=>null,'saved_versions_waiting_for_value_inspection'=>null,
            'value_backlog_complete_within_bound'=>null];
        try{
            $this->auth();
            $safe="CASE WHEN JSON_VALID(last_receipt_json) THEN last_receipt_json ELSE '{}' END";
            $get="JSON_EXTRACT(".$safe.",'$.pending_revisions')";
            $type=$this->mysql?'JSON_TYPE('.$get.')':"json_type(".$safe.",'$.pending_revisions')";
            $sql='SELECT CASE WHEN '.$type."='".($this->mysql?'INTEGER':'integer')."' AND ".$get.' BETWEEN 0 AND 1000000000 THEN CAST('
                .$get.($this->mysql?' AS UNSIGNED)':' AS INTEGER)')." ELSE NULL END AS pending FROM modeled_conversion_profiles_state WHERE project_key='anytour' LIMIT 1";
            $q=$this->budget->prepare($this->pdo,$sql);$q->execute();$row=$q->fetch(PDO::FETCH_ASSOC);$q->closeCursor();$this->auth();
            $this->budget->consumeBytes(strlen(json_encode($row,JSON_THROW_ON_ERROR)));
            if($row!==false&&is_numeric($row['pending']))$out['profiles_recorded_pending_revisions']=(int)$row['pending'];
            $sql="SELECT COUNT(*) AS n FROM (SELECT v.id FROM modeled_conversion_profile_versions v JOIN modeled_conversion_value_export_settings s ON s.project_key=v.project_key"
                ." WHERE s.project_key='anytour' AND s.counter_id='98615635' AND s.goal_id='678187097' AND v.id>s.profile_cursor ORDER BY v.id LIMIT 201) bounded_value_backlog";
            $q=$this->budget->prepare($this->pdo,$sql);$q->execute();$row=$q->fetch(PDO::FETCH_ASSOC);$q->closeCursor();$this->auth();
            $this->budget->consumeBytes(strlen(json_encode($row,JSON_THROW_ON_ERROR)));
            if($row!==false&&is_numeric($row['n'])){$out['saved_versions_waiting_for_value_inspection']=(int)$row['n'];$out['value_backlog_complete_within_bound']=(int)$row['n']<201;}
        }catch(PDOException $ignored){ /* Missing derived storage is unknown, never zero. */ }
        return $out;
    }

    private function auth():void{$this->budget->check();($this->authorize)();$this->budget->check();}
}
