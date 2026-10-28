<?php
declare(strict_types=1);

require_once __DIR__.'/ModeledConversionStatusBudget.php';
require_once __DIR__.'/ModeledConversionEarlyForecast.php';

/** Private, fixed-window aggregate readback of immutable derived profiles. Never reads source messages. */
final class ModeledConversionStatusReader
{
    public const VERSION='saved_forecast_status_v1';
    public const METADATA_LIMIT=20;
    public const PROFILE_LIMIT=4;
    public const PROFILE_BYTES=49152;
    public const RECEIPT_BYTES=4096;
    private PDO $pdo;
    private bool $mysql;
    private $authorize;
    private ModeledConversionStatusBudget $budget;
    private int $profileBytes=0;

    public function __construct(PDO $pdo,callable $authorize,?ModeledConversionStatusBudget $budget=null)
    {
        $this->pdo=$pdo;$this->mysql=$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql';
        $this->authorize=$authorize;$this->budget=$budget??new ModeledConversionStatusBudget();
    }
    public function read():array
    {
        $this->budget->check();
        if($this->pdo->inTransaction())return $this->unavailable('outer_transaction');
        try{
            $this->profileBytes=0;
            $state=$this->readState();
            $parts=$this->projection();
            $projectedLength=implode('+',array_map(fn(string $sql):string=>'COALESCE('.$this->length($sql).',0)',array_values($parts)));
            $length='CASE WHEN JSON_VALID(profile_json) THEN '.$projectedLength.' ELSE 0 END';
            $this->reserve(16384);
            $rows=$this->query('SELECT id,platform,customer_key,conversation_id,journal_cursor,observed_at_unix,'.$length.' AS profile_bytes,JSON_VALID(profile_json) AS json_valid FROM modeled_conversion_profile_versions WHERE project_key=? AND platform IN (?,?) ORDER BY id DESC LIMIT '.self::METADATA_LIMIT,['anytour','max','telegram']);
            $out=$this->emptyResult($state,count($rows));
            $fence=$rows?(string)$rows[0]['id']:'0';$loaded=[];$scopes=[];
            foreach($rows as$row){
                $this->budget->check();
                if(!$this->validMeta($row)){$out['window']['invalid_rows']++;continue;}
                $bytes=(int)$row['profile_bytes'];
                if($bytes>self::PROFILE_BYTES){$out['window']['oversize_rows']++;continue;}
                if((string)($row['json_valid']??'')!=='1'){$out['window']['invalid_rows']++;continue;}
                if($out['window']['profile_rows_read']>=self::PROFILE_LIMIT){$out['window']['row_limit_omissions']++;continue;}
                // A valid JSON string encoded inside a query-result frame is at most twice its raw bytes.
                // Reserve final authorization and protocol accounting before copying any private payload.
                if($this->profileBytes+$bytes>self::PROFILE_BYTES||$bytes*2+1024>$this->budget->remainingBytes()-8192){
                    $out['window']['byte_limit_omissions']++;continue;
                }
                $columns=[];foreach($parts as$name=>$sql)$columns[]='CASE WHEN JSON_VALID(profile_json) THEN '.$sql.' ELSE NULL END AS '.$name;
                $body=$this->query('SELECT '.implode(',',$columns).' FROM modeled_conversion_profile_versions WHERE project_key=? AND id=? AND id<=? AND '.$length.'=? AND '.$length.'<=? AND JSON_VALID(profile_json) LIMIT 1',['anytour',(string)$row['id'],$fence,$bytes,self::PROFILE_BYTES]);
                $actual=0;
                if(count($body)===1)foreach(array_keys($parts)as$name){$value=$body[0][$name]??null;if($value!==null&&!is_string($value)){$actual=-1;break;}$actual+=strlen($value??'');}
                if(count($body)!==1||$actual!==$bytes){$out['window']['changed_or_missing_rows']++;continue;}
                $this->profileBytes+=$bytes;$out['window']['profile_rows_read']++;
                try{
                    $packet=json_decode($body[0]['binding_json'],true,32,JSON_THROW_ON_ERROR);
                    $packet['early_forecast']=$body[0]['early_json']===null?null:json_decode($body[0]['early_json'],true,64,JSON_THROW_ON_ERROR);
                    $packet['contextual_signals']=$body[0]['signals_json']===null?null:json_decode($body[0]['signals_json'],true,64,JSON_THROW_ON_ERROR);
                }catch(JsonException $e){$out['window']['invalid_rows']++;continue;}
                if(!is_array($packet)||!isset($packet['early_forecast'],$packet['contextual_signals'])){
                    $out['window']['without_forecast_rows']++;continue;
                }
                if(($packet['early_forecast']['version']??null)!==ModeledConversionEarlyForecast::VERSION
                    ||($packet['contextual_signals']['version']??null)!==ModeledConversionEarlyForecast::VERSION){
                    $out['window']['unsupported_version_rows']++;continue;
                }
                $valid=$this->paired($packet,$row);
                if($valid===null){$out['window']['invalid_rows']++;continue;}
                $f=$packet['early_forecast'];$s=$packet['contextual_signals'];
                $out['saved']['paired_snapshots']++;
                $out['saved']['status_counts'][$f['status']]++;
                $out['saved']['snapshot_kind_counts'][$f['snapshot_kind']]++;
                if($valid['has_signals'])$out['saved']['signal_bearing_snapshots']++;
                if($f['status']==='early_assessment'&&$valid['has_signals'])$out['saved']['early_assessments_with_signals']++;
                foreach($s['goal_counts']as$goal=>$count)$out['saved']['goal_counts'][$goal]+=$count;
                $scope=$row['platform'].':'.$row['customer_key'].':'.$row['conversation_id'];$scopes[$scope]=true;
                $loaded[]=['scope'=>$scope,'cursor'=>(string)$row['journal_cursor'],'first'=>$f['first_snapshot_journal_cursor'],'kind'=>$f['snapshot_kind']];
                foreach(['prediction_at','input_cutoff_at','frozen_at']as$key){
                    $out['saved'][$key.'_range']['earliest']=$out['saved'][$key.'_range']['earliest']===null?$f[$key]:min($out['saved'][$key.'_range']['earliest'],$f[$key]);
                    $out['saved'][$key.'_range']['latest']=$out['saved'][$key.'_range']['latest']===null?$f[$key]:max($out['saved'][$key.'_range']['latest'],$f[$key]);
                }
            }
            foreach($loaded as$item){
                if($item['kind']==='initial_observation'){$out['saved']['first_marker']['initial_self_markers']++;continue;}
                $found=false;
                foreach($loaded as$first)if($first['scope']===$item['scope']&&$first['kind']==='initial_observation'&&$first['cursor']===$item['first']){$found=true;break;}
                $out['saved']['first_marker'][$found?'updated_links_verified_in_window':'updated_links_outside_checked_window']++;
            }
            $out['saved']['distinct_conversation_scopes']=count($scopes);
            $out['window']['selected_document_bytes']=$this->profileBytes;
            $out['confirmation']=$out['saved']['early_assessments_with_signals']>0?'saved_early_assessment_with_signals':
                ($out['saved']['paired_snapshots']>0?'saved_packet_without_early_signal_confirmation':'no_confirmation_in_checked_window');
            $this->authorize();
            $this->budget->check();
            if(strlen(self::json($out))>32768)throw new ModeledConversionStatusLimit('byte_limit');
            return $out;
        }catch(PDOException $e){
            return $this->unavailable('storage_unavailable');
        }catch(JsonException $e){
            return $this->unavailable('invalid_storage');
        }
    }
    private function projection():array
    {
        return ['early_json'=>"JSON_EXTRACT(profile_json,'$.early_forecast')",
            'signals_json'=>"JSON_EXTRACT(profile_json,'$.contextual_signals')",
            'binding_json'=>"JSON_OBJECT('access_status',JSON_EXTRACT(profile_json,'$.access_status'),'subject',JSON_EXTRACT(profile_json,'$.subject'),'journal_cursor',JSON_EXTRACT(profile_json,'$.journal_cursor'),'source_fence_journal_id',JSON_EXTRACT(profile_json,'$.source_fence_journal_id'),'processing',JSON_OBJECT('journal_cursor',JSON_EXTRACT(profile_json,'$.processing.journal_cursor'),'source_fence_journal_id',JSON_EXTRACT(profile_json,'$.processing.source_fence_journal_id')),'profile',JSON_OBJECT('prediction_at',JSON_EXTRACT(profile_json,'$.profile.prediction_at')))"];
    }
    private function length(string $sql):string{return $this->mysql?'OCTET_LENGTH('.$sql.')':'LENGTH(CAST('.$sql.' AS BLOB))';}
    private function readState():array
    {
        $length=$this->mysql?'OCTET_LENGTH(last_receipt_json)':'LENGTH(CAST(last_receipt_json AS BLOB))';
        $this->reserve(self::RECEIPT_BYTES*6+1024);
        $rows=$this->query('SELECT enabled,last_error_at_unix,last_error_category,'.$length.' AS receipt_bytes,CASE WHEN '.$length.'<=? THEN last_receipt_json ELSE NULL END AS receipt_json FROM modeled_conversion_profiles_state WHERE project_key=? LIMIT 1',[self::RECEIPT_BYTES,'anytour']);
        if(!$rows)return ['available'=>false,'enabled'=>null,'last_tick_status'=>'unknown','last_tick_at'=>null,'last_batch_profiles_written'=>null,'last_batch_forecast_snapshots'=>null,'pending_revisions'=>null,'last_recorded_error'=>['at'=>null,'category'=>'unknown']];
        $r=$rows[0];$error=null;
        if($r['last_error_at_unix']!==null){
            $at=(int)$r['last_error_at_unix'];
            $error=['at'=>$at>=946684800&&$at<=time()?gmdate('Y-m-d\TH:i:s\Z',$at):null,
                'category'=>(string)$r['last_error_category']==='processing_failed'?'processing_failed':'unknown'];
        }
        $out=['available'=>true,'enabled'=>in_array((string)$r['enabled'],['0','1'],true)?(string)$r['enabled']==='1':null,'last_tick_status'=>'unknown','last_tick_at'=>null,'last_batch_profiles_written'=>null,'last_batch_forecast_snapshots'=>null,'pending_revisions'=>null,'last_recorded_error'=>$error];
        if(!is_string($r['receipt_json']??null)||strlen($r['receipt_json'])>self::RECEIPT_BYTES)return $out;
        try{$v=json_decode($r['receipt_json'],true,32,JSON_THROW_ON_ERROR);}catch(JsonException $e){return $out;}
        if(!is_array($v)||!in_array($v['status']??null,['profiled','idle'],true))return $out;
        $out['last_tick_status']=$v['status'];
        if(is_int($v['observed_at_unix']??null)&&$v['observed_at_unix']>=946684800&&$v['observed_at_unix']<=time())$out['last_tick_at']=gmdate('Y-m-d\TH:i:s\Z',$v['observed_at_unix']);
        foreach(['profiles_written'=>'last_batch_profiles_written','pending_revisions'=>'pending_revisions']as$in=>$key)
            if(is_int($v[$in]??null)&&$v[$in]>=0&&$v[$in]<=1000000000)$out[$key]=$v[$in];
        if(($v['early_forecast']['version']??null)===ModeledConversionEarlyForecast::VERSION&&is_int($v['early_forecast']['snapshots']??null)&&$v['early_forecast']['snapshots']>=0&&$v['early_forecast']['snapshots']<=4)$out['last_batch_forecast_snapshots']=$v['early_forecast']['snapshots'];
        return $out;
    }
    private function paired(array $p,array $row):?array
    {
        $f=$p['early_forecast'];$s=$p['contextual_signals'];
        if(!is_array($f)||!is_array($s)||!is_array($p['subject']??null))return null;
        $subject=$p['subject'];
        foreach(['platform','customer_key','conversation_id']as$key)if(!is_string($subject[$key]??null)||$subject[$key]!==(string)$row[$key])return null;
        if(($subject['project_key']??null)!=='anytour'||($f['target_version']??null)!==ModeledConversionEarlyForecast::TARGET_VERSION
            ||($f['mode']??null)!=='uncalibrated_ordinal'||($f['calibration_status']??null)!=='not_calibrated'
            ||($f['export_allowed']??null)!==false||($f['evaluation_eligible']??null)!==false
            ||($f['model_calls']??null)!==0||($f['conversion_uploads']??null)!==0
            ||!array_key_exists('expected_value_rub',$f)||$f['expected_value_rub']!==null)return null;
        foreach(['purchase_anywhere','purchase_with_us']as$key)
            if(!is_array($f[$key]??null)||!array_key_exists('probability',$f[$key])||$f[$key]['probability']!==null)return null;
        if(!in_array($f['status']??null,['early_assessment','target_already_observed','no_usable_customer_context','access_denied'],true)
            ||!in_array($f['snapshot_kind']??null,['initial_observation','updated_observation'],true)
            ||($f['coverage']['trip_status']??null)!=='unknown'||($s['coverage']['trip_status']??null)!=='unknown')return null;
        $any=$f['purchase_anywhere']['readiness_band']??null;$agency=$f['purchase_with_us']['readiness_band']??null;
        if(!in_array($any,['unknown','interest','intent','deferred','not_assessed'],true)
            ||!in_array($agency,['unknown','logistics','intent','deferred','not_assessed'],true)
            ||($f['status']==='early_assessment'&&($any==='not_assessed'||$agency==='not_assessed'))
            ||($f['status']!=='early_assessment'&&($any!=='not_assessed'||$agency!=='not_assessed'))
            ||!in_array($p['access_status']??null,['allowed','denied'],true)
            ||(($p['access_status']==='denied')!==($f['status']==='access_denied')))return null;
        $cursor=(string)$row['journal_cursor'];$fence=$f['source_fence_journal_id']??null;$first=$f['first_snapshot_journal_cursor']??null;
        if(!self::decimal($fence)||!self::decimal($first)||self::greater($cursor,$fence)||self::greater($first,$cursor)
            ||($f['journal_cursor']??null)!==$cursor||($p['journal_cursor']??null)!==$cursor
            ||($p['source_fence_journal_id']??null)!==$fence||($s['source_fence_journal_id']??null)!==$fence
            ||($p['processing']['journal_cursor']??null)!==$cursor||($p['processing']['source_fence_journal_id']??null)!==$fence
            ||($f['snapshot_kind']==='initial_observation'&&$first!==$cursor))return null;
        foreach(['prediction_at','input_cutoff_at','frozen_at']as$key)
            if(!is_string($f[$key]??null)||$f[$key]!==($s[$key]??null)||self::moment($f[$key])===null)return null;
        $prediction=self::moment($f['prediction_at']);$cutoff=self::moment($f['input_cutoff_at']);$freeze=self::moment($f['frozen_at']);
        if($cutoff>$prediction||$prediction>$freeze||$freeze>time()||$freeze<(int)$row['observed_at_unix']
            ||($s['derived_available_at']??null)!==$f['frozen_at']||($p['profile']['prediction_at']??null)!==$f['prediction_at']
            ||($s['extractor_version']??null)!==ArchiveResearchSemanticExtractor::VERSION)return null;
        if(($f['horizon_seconds']??null)!==ModeledConversionEarlyForecast::HORIZON_SECONDS
            ||self::moment($f['horizon_end_at']??null)!==$prediction+ModeledConversionEarlyForecast::HORIZON_SECONDS)return null;
        $goals=ArchiveResearchSemanticExtractor::goalIds();$counts=$s['goal_counts']??null;$events=$s['events']??null;
        if(!is_array($counts)||count($counts)!==count($goals)||!is_array($events)||count($events)>800)return null;
        $fromEvents=array_fill_keys($goals,0);
        foreach($events as$event){
            if(!is_array($event)||!is_string($event['goal']??null)||!isset($fromEvents[$event['goal']])||($event['certainty']??null)!=='rule_candidate'
                ||!is_string($event['source_ref']??null)||preg_match('/\Ajournal:[1-9][0-9]{0,19}\z/D',$event['source_ref'])!==1
                ||self::greater(substr($event['source_ref'],8),$fence))return null;
            $related=$event['related_source_refs']??null;
            if(!is_array($related)||!array_is_list($related)||count($related)>8)return null;
            foreach($related as$reference)if(!is_string($reference)||preg_match('/\Ajournal:[1-9][0-9]{0,19}\z/D',$reference)!==1
                ||self::greater(substr($reference,8),$fence))return null;
            $fromEvents[$event['goal']]++;
        }
        foreach($goals as$goal)if(!isset($counts[$goal])||!is_int($counts[$goal])||$counts[$goal]<0||$counts[$goal]>800||$counts[$goal]!==$fromEvents[$goal])return null;
        $observedOutcome=false;
        foreach(['payment_self_report','manager_payment_ack','booking_confirmation','purchase_supported_candidate','payment_cancellation','booking_cancellation','refund_report','purchased_elsewhere']as$goal)
            if($counts[$goal]>0)$observedOutcome=true;
        if($f['status']==='early_assessment'&&$observedOutcome)return null;
        if($f['status']==='target_already_observed'&&!$observedOutcome)return null;
        if($f['status']==='access_denied'&&count($events)!==0)return null;
        return ['has_signals'=>count($events)>0];
    }
    private function emptyResult(array $state,int $metadataRows):array
    {
        return ['available'=>true,'reader_version'=>self::VERSION,'checked_at'=>gmdate('Y-m-d\TH:i:s\Z'),
            'forecast_version'=>ModeledConversionEarlyForecast::VERSION,'rule_version'=>ArchiveResearchSemanticExtractor::VERSION,
            'state'=>$state,'confirmation'=>'no_confirmation_in_checked_window',
            'window'=>['basis'=>'latest_saved_anytour_profile_versions','metadata_limit'=>self::METADATA_LIMIT,'metadata_rows'=>$metadataRows,'metadata_limit_reached'=>$metadataRows===self::METADATA_LIMIT,'profile_rows_read'=>0,'selected_document_bytes'=>0,'oversize_rows'=>0,'byte_limit_omissions'=>0,'row_limit_omissions'=>0,'changed_or_missing_rows'=>0,'without_forecast_rows'=>0,'unsupported_version_rows'=>0,'invalid_rows'=>0],
            'saved'=>['basis'=>'inspected_saved_versions_not_unique_people_or_trips','signal_basis'=>'contextual_goal_events_not_engagement_intervals','paired_snapshots'=>0,'distinct_conversation_scopes'=>0,'signal_bearing_snapshots'=>0,'early_assessments_with_signals'=>0,
                'status_counts'=>array_fill_keys(['early_assessment','target_already_observed','no_usable_customer_context','access_denied'],0),
                'snapshot_kind_counts'=>array_fill_keys(['initial_observation','updated_observation'],0),
                'goal_counts'=>array_fill_keys(ArchiveResearchSemanticExtractor::goalIds(),0),
                'prediction_at_range'=>['earliest'=>null,'latest'=>null],'input_cutoff_at_range'=>['earliest'=>null,'latest'=>null],'frozen_at_range'=>['earliest'=>null,'latest'=>null],
                'first_marker'=>['initial_self_markers'=>0,'updated_links_verified_in_window'=>0,'updated_links_outside_checked_window'=>0]],
            'interpretation'=>['idle_tick_does_not_mean_no_saved_forecasts'=>true,'last_recorded_error_is_historical_not_current_health'=>true,'new_snapshot_is_not_new_customer_or_trip'=>true,'saved_evidence_is_not_current_source_authorization'=>true,'purchase_probability_calibrated'=>false,'monetary_export'=>false,'all_history_coverage'=>false],
            'limits'=>['max_selected_bytes'=>65536,'max_selected_document_bytes'=>self::PROFILE_BYTES,'max_profile_rows'=>self::PROFILE_LIMIT,'max_receipt_bytes'=>self::RECEIPT_BYTES,'max_execution_seconds'=>5,'byte_basis'=>'serialized_selected_query_results_not_database_pages','deadline_basis'=>'shared_server_execution_budget_not_network_or_bootstrap_guarantee']];
    }
    private function unavailable(string $reason):array
    {
        $this->authorize();$this->budget->check();
        return ['available'=>false,'reader_version'=>self::VERSION,'reason'=>$reason,'confirmation'=>'unknown','saved'=>null];
    }
    private function query(string $sql,array $args):array
    {
        $this->authorize();$this->budget->check();$q=$this->budget->prepare($this->pdo,$sql);$q->execute($args);
        $rows=$q->fetchAll(PDO::FETCH_ASSOC);$q->closeCursor();$this->budget->check();
        $this->budget->consumeBytes(strlen(self::json($rows)));return $rows;
    }
    private function reserve(int $bytes):void{if($this->budget->remainingBytes()<$bytes+8192)throw new ModeledConversionStatusLimit('byte_limit');}
    private function authorize():void{$this->budget->check();($this->authorize)();$this->budget->check();}
    private function validMeta(array $r):bool
    {
        foreach(['id','customer_key','conversation_id']as$key)if(preg_match('/\A[1-9][0-9]{0,19}\z/D',(string)($r[$key]??''))!==1)return false;
        if(!self::decimal((string)($r['journal_cursor']??'')))return false;
        return in_array($r['platform']??null,['max','telegram'],true)&&is_numeric($r['profile_bytes']??null)&&(int)$r['profile_bytes']>=2
            &&is_numeric($r['observed_at_unix']??null)&&(int)$r['observed_at_unix']>=946684800&&(int)$r['observed_at_unix']<=time();
    }
    private static function decimal(mixed $v):bool{return is_string($v)&&preg_match('/\A(?:0|[1-9][0-9]{0,19})\z/D',$v)===1;}
    private static function greater(string $a,string $b):bool{return strlen($a)!==strlen($b)?strlen($a)>strlen($b):strcmp($a,$b)>0;}
    private static function moment(mixed $v):?int
    {
        if(!is_string($v)||preg_match('/\A[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}Z\z/D',$v)!==1)return null;
        $t=strtotime($v);return $t!==false&&gmdate('Y-m-d\TH:i:s\Z',$t)===$v?$t:null;
    }
    private static function json(array $v):string{return json_encode($v,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);}
}
