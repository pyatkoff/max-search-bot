<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once __DIR__.'/run_modeled_conversion_early_forecast_regression.php';
require_once __DIR__.'/run_modeled_conversion_value_export_regression.php';
require_once dirname(__DIR__).'/services/ModeledConversionPredictionFollowup.php';
require_once dirname(__DIR__).'/services/ModeledConversionForecastStatusReader.php';
require_once dirname(__DIR__).'/services/MetrikaOfflineClient.php';
require_once dirname(__DIR__).'/services/ModeledConversionFixedPairDecisionEvidence.php';
require_once dirname(__DIR__).'/services/ModeledConversionMaxCounterScopeEvidence.php';
require_once dirname(__DIR__).'/services/ModeledConversionRetainedAppIdentityEvidence.php';
require_once dirname(__DIR__).'/services/ModeledConversionRetainedTrackerIdentityEvidence.php';
require_once dirname(__DIR__).'/services/MaxTrackerLookupRepository.php';
require_once dirname(__DIR__).'/services/ModeledConversionValueExport.php';
require_once __DIR__.'/support/archive-predictive-model.php';
require_once __DIR__.'/support/forecast-primary-source.php';

final class ForecastV1ObservedStatement extends PDOStatement
{
    public static bool $copied=false;
    public static bool $valueCopied=false;
    public static mixed $afterMetadata=null;
    public static mixed $afterNativePoint=null;
    public static mixed $afterInitialApi=null;
    public static mixed $onRetainedSource=null;
    public static mixed $afterRetainedSourceFetch=null;
    public static mixed $beforeRelationClaim=null;
    public static mixed $beforeRelationCache=null;
    public static array $relationOutcomeQueries=[];
    public static array $relationOutcomeCopies=[];
    public static ?array $lastRelationOutcomePacket=null;
    public static ?array $nativePresentationFixtures=null;
    public static bool $observePredictive=false;
    public static int $predictiveExecutions=0;
    public static array $predictiveCopies=[];
    public static array $predictiveStatements=[];
    public static bool $observeCore=false;
    public static array $coreStatements=[];
    public static array $coreCopies=[];
    public static int $diagnosisExecutions=0;
    private array $predictiveBindings=[];
    protected function __construct(){}
    public function bindValue(string|int $param,mixed $value,int $type=PDO::PARAM_STR):bool
    {
        $ok=parent::bindValue($param,$value,$type);
        if(self::$observePredictive||self::$observeCore||str_contains($this->queryString,' AS binding_case,'))$this->predictiveBindings[$param]=[$value,$type];
        return $ok;
    }
    public function execute(?array $params=null):bool
    {
        if(self::$observeCore)foreach(debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS,12)as$frame)
            if(($frame['class']??null)===ModeledConversionInputDiagnosisReader::class){self::$diagnosisExecutions++;break;}
        if(is_callable(self::$onRetainedSource)&&(str_contains($this->queryString,'retained_app_scopes')||str_contains($this->queryString,'retained_app_identity')||str_contains($this->queryString,'retained_click_metadata')||str_contains($this->queryString,'retained_click_point')||str_contains($this->queryString,'retained_click_record')))
            (self::$onRetainedSource)($this->queryString);
        if(is_callable(self::$beforeRelationClaim)&&str_contains($this->queryString,"SET client_id=?,state='uploading'")){
            $hook=self::$beforeRelationClaim;self::$beforeRelationClaim=null;$hook();
        }
        if(is_callable(self::$beforeRelationCache)&&str_contains($this->queryString,'SET outcome_json=?,outcome_basis_sha256=?')){
            $hook=self::$beforeRelationCache;self::$beforeRelationCache=null;$hook();
        }
        if(str_contains($this->queryString,' AS binding_case,'))self::$relationOutcomeQueries[]=['sql'=>$this->queryString,'bindings'=>$this->predictiveBindings];
        $ok=parent::execute($params);
        if(self::$observePredictive){
            self::$predictiveExecutions++;
            self::$predictiveStatements[]=['sql'=>$this->queryString,'bindings'=>$this->predictiveBindings];
        }
        if(self::$observeCore&&str_contains($this->queryString,' AS prediction_json,'))
            self::$coreStatements[]=['sql'=>$this->queryString,'bindings'=>$this->predictiveBindings];
        return $ok;
    }
    public function fetchAll(int $mode=PDO::FETCH_DEFAULT,mixed ...$args):array
    {
        $rows=parent::fetchAll($mode,...$args);
        if(str_contains($this->queryString,' AS binding_case,'))self::$relationOutcomeCopies[]=$rows;
        if(is_callable(self::$afterRetainedSourceFetch)&&(str_contains($this->queryString,'retained_app_scopes')||str_contains($this->queryString,'retained_app_identity')||str_contains($this->queryString,'retained_click_metadata')||str_contains($this->queryString,'retained_click_point')||str_contains($this->queryString,'retained_click_record')))
            (self::$afterRetainedSourceFetch)($this->queryString);
        if(self::$observePredictive)self::$predictiveCopies[]=$rows;
        if(self::$observeCore&&str_contains($this->queryString,' AS prediction_json,'))self::$coreCopies[]=$rows;
        if(str_contains($this->queryString,' AS reserved_rub'))self::$valueCopied=true;
        if(str_contains($this->queryString,' AS prediction_json,'))self::$copied=true;
        if(str_contains($this->queryString,'initial_api_receipt_packets')&&is_callable(self::$afterInitialApi)){
            $hook=self::$afterInitialApi;self::$afterInitialApi=null;$hook();
        }
        if(str_contains($this->queryString,'native_identity_point_packets')&&is_callable(self::$afterNativePoint)){
            $hook=self::$afterNativePoint;self::$afterNativePoint=null;$hook();
        }
        if(str_contains($this->queryString,' AS namespace_matches')&&is_callable(self::$afterMetadata)){
            $hook=self::$afterMetadata;self::$afterMetadata=null;$hook();
        }
        return $rows;
    }
}
function forecastV1Schema(PDO $p):void
{
    foreach(['modeled_conversion_profile_versions','modeled_conversion_profiles_state']as$t)$p->exec('DROP TABLE IF EXISTS '.$t);
    $mysql=$p->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql';
    $p->exec('CREATE TABLE modeled_conversion_profiles_state (project_key VARCHAR(40) PRIMARY KEY,enabled INT,last_receipt_json TEXT,last_error_at_unix BIGINT,last_error_category VARCHAR(40))');
    $p->exec('CREATE TABLE modeled_conversion_profile_versions (id '.($mysql?'BIGINT AUTO_INCREMENT':'INTEGER').' PRIMARY KEY,project_key VARCHAR(40),platform VARCHAR(16),customer_key VARCHAR(40),conversation_id VARCHAR(40),journal_cursor BIGINT,observed_at_unix BIGINT,input_sha256 VARCHAR(64),assessment_namespace CHAR(64) DEFAULT \'0000000000000000000000000000000000000000000000000000000000000000\',assessment_kind VARCHAR(8) DEFAULT \'legacy\',profile_json '.($mysql?'MEDIUMTEXT':'TEXT').')');
    $p->prepare('INSERT INTO modeled_conversion_profiles_state VALUES(?,1,?,NULL,NULL)')->execute(['anytour',
        json_encode(['status'=>'idle','observed_at_unix'=>1793004000,'profiles_written'=>0],JSON_THROW_ON_ERROR)]);
}
function forecastV1Packet(string $customer='7',string $conversation='9',array $messages=[['text'=>'Подберите тур в Турцию на 7 ночей.']],
    string $platform='telegram',int $cutoffOffset=1000,int $freezeOffset=2000,array $deltas=[],int $extractionDelay=0,?array $relativeWeights=null):array
{
    [$case,$wrapper,$document]=modeledEarlyFixture($messages);
    $subject=['project_key'=>'anytour','platform'=>$platform,'customer_key'=>$customer,'conversation_id'=>$conversation];
    $case['subject']=$wrapper['subject']=$subject;$clocks=[];
    foreach($case['rows']as&$row){
        foreach($subject as$k=>$v)$row['snapshot'][$k]=$v;
        $d=$deltas[$row['journal_id']]??0;$row['snapshot']['source_created_at_unix']+=$d;$row['observed_at_unix']+=$d;
        $clocks['journal:'.$row['journal_id']]=['at'=>gmdate('Y-m-d\TH:i:s\Z',$row['snapshot']['source_created_at_unix']),
            'available_at'=>gmdate('Y-m-d\TH:i:s\Z',$row['observed_at_unix'])];
    }
    unset($row);
    $cutoff=gmdate('Y-m-d\TH:i:s\Z',1793002000+$cutoffOffset);
    $document['requested_at']=$cutoff;
    $wrapper['profile']['prediction_at']=$wrapper['profile']['observation_input']['prediction_at']=gmdate('Y-m-d\TH:i:s\Z',1793002000+$cutoffOffset+$extractionDelay);
    $wrapper['profile']['observation_input']['subject']=$subject+['episode_key'=>'unresolved-prefix:'.$conversation];
    foreach($wrapper['profile']['observation_input']['evidence']as&$e){
        $e['subject']=$wrapper['profile']['observation_input']['subject'];
        foreach($clocks[$e['references'][0]['id']]as$k=>$v)$e[$k]=$e['references'][0][$k]=$v;
    }
    unset($e);
    $packet=ModeledConversionEarlyForecast::freeze($case,$wrapper,$document,null,1793002000+$freezeOffset,$relativeWeights);
    $wrapper=array_replace($wrapper,$packet);
    $wrapper['prediction_followup']=ModeledConversionPredictionFollowup::compare($wrapper['semantic_prediction'],null);
    $wrapper['processing']=['version'=>'admitted_journal_profiles_v1_2','source_sha256'=>hash_file('sha256',dirname(__DIR__).'/services/ModeledConversionProfiles.php'),
        'journal_cursor'=>$document['journal_cursor'],'source_fence_journal_id'=>$document['source_fence_journal_id']];
    $wrapper['coverage']['applied_wizard_fields']=0;
    $wrapper['private_fixture_sentinel']='PRIVATE_UNRELATED_PROFILE_MUST_NOT_BE_COPIED';
    return $wrapper;
}
function forecastV1Append(PDO $p,array|string $v):void
{
    $s=is_array($v)?$v['subject']:['project_key'=>'anytour','platform'=>'telegram','customer_key'=>'7','conversation_id'=>'9'];
    $cursor=is_array($v)?$v['journal_cursor']:'1';
    $at=is_array($v)?strtotime($v['profile']['prediction_at']):1793003000;
    $json=is_array($v)?json_encode($v,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR):$v;
    $p->prepare('INSERT INTO modeled_conversion_profile_versions(project_key,platform,customer_key,conversation_id,journal_cursor,observed_at_unix,input_sha256,profile_json) VALUES(?,?,?,?,?,?,?,?)')
        ->execute([$s['project_key'],$s['platform'],$s['customer_key'],$s['conversation_id'],$cursor,$at,hash('sha256',$json),$json]);
}
function forecastV1Read(PDO $p,?callable $auth=null,?ModeledConversionStatusBudget $budget=null):array
{
    $mysql=$p->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql';
    if($mysql)$p->exec('SET SESSION TRANSACTION READ ONLY');
    try{return(new ModeledConversionForecastStatusReader($p,$auth??static function():void{},$budget??new ModeledConversionStatusBudget()))->read();}
    finally{if($mysql)$p->exec('SET SESSION TRANSACTION READ WRITE');}
}
function forecastV1Rows(PDO $p):array
{return $p->query('SELECT * FROM modeled_conversion_profile_versions ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);}

function forecastValueDeliveryAssertions(PDO $p,callable $check):void
{
    forecastV1Schema($p);forecastV1Append($p,forecastV1Packet());$now=time();$act=$now-3600;
    foreach(['modeled_conversion_value_export_settings','max_channel_conversion_outbox']as$table)$p->exec('DROP TABLE IF EXISTS '.$table);
    $p->exec("CREATE TABLE modeled_conversion_value_export_settings(project_key VARCHAR(40) PRIMARY KEY,counter_id VARCHAR(20),mode VARCHAR(16),goal_id VARCHAR(20),goal_intent VARCHAR(20),activated_at_unix BIGINT,last_tick_unix BIGINT,contract_version VARCHAR(64),last_receipt_json TEXT,history_from_unix BIGINT,history_until_unix BIGINT,history_completed_at_unix BIGINT,history_scanned BIGINT,history_collected BIGINT,history_stats_json TEXT,history_cursor BIGINT,history_ceiling BIGINT,history_fortnight_requested_at_unix BIGINT,history_day_receipt_json TEXT,last_report_success_unix BIGINT,last_report_success_json TEXT,report_failure_since_unix BIGINT)");
    $p->exec("CREATE TABLE max_channel_conversion_outbox(id BIGINT PRIMARY KEY,project_key VARCHAR(40),counter_id VARCHAR(20),transport_target VARCHAR(64),value_contract VARCHAR(64),event_unix BIGINT,state VARCHAR(24),value_stage VARCHAR(40),value_rub DECIMAL(12,2),upload_id VARCHAR(20),upload_status VARCHAR(32),last_error VARCHAR(64),private_marker TEXT,value_origin VARCHAR(32),value_reviewed_at_unix BIGINT,platform VARCHAR(16),conversation_id BIGINT,client_id VARCHAR(40),claimed_at_unix BIGINT,value_ready_at_unix BIGINT,value_api_accepted_at_unix BIGINT,value_processed_at_unix BIGINT,value_receipt_basis VARCHAR(24),value_profile_id BIGINT,target VARCHAR(100),external_user_id VARCHAR(20))");
    $report=['status'=>'ok','goal_id'=>'678187097','currency'=>'RUB','fetched_at'=>gmdate('Y-m-d\\TH:i:s\\Z',$now),
        'date1'=>gmdate('Y-m-d',$act-21*86400),'date2'=>gmdate('Y-m-d',$now),'goal_reaches'=>2,'goal_visits'=>1,'revenue_rub'=>2083.0,'sampled'=>false,
        'private_marker'=>'PRIVATE_REPORT_MUST_NOT_LEAVE'];
    $receipt=['errors'=>0,'metrika_report'=>$report];
    $p->prepare("INSERT INTO modeled_conversion_value_export_settings(project_key,counter_id,mode,goal_id,goal_intent,activated_at_unix,last_tick_unix,contract_version,last_receipt_json) VALUES('anytour','98615635','send','678187097','verified',?,?,?,?)")
        ->execute([$act,$now,ModeledConversionValueContract::VERSION,json_encode($receipt,JSON_THROW_ON_ERROR)]);
    $insert=$p->prepare("INSERT INTO max_channel_conversion_outbox(id,project_key,counter_id,transport_target,value_contract,event_unix,state,value_stage,value_rub,upload_id,upload_status,last_error,private_marker) VALUES(?,'anytour','98615635',?,?,?, ?,?,?,?,?,?,'PRIVATE_LEDGER_CLIENT_ID_MUST_NOT_LEAVE')");
    foreach([[1,'processed','interest',1250,'501','PROCESSED',''],[2,'accepted','specific_request',833,'502','UPLOADED',''],
        [3,'unknown','offer_discussion',2084,null,null,'upload_outcome_unknown'],[4,'pending','purchase_intent',4166,null,null,'identity_not_found']]as$row){
        [$id,$state,$stage,$value,$upload,$status,$error]=$row;
        $insert->execute([$id,ModeledConversionValueContract::TARGET,ModeledConversionValueContract::VERSION,$now-10,$state,$stage,$value,$upload,$status,$error]);
    }
    $p->exec("INSERT INTO max_channel_conversion_outbox(id,project_key,counter_id,transport_target,value_contract,event_unix,state,value_stage,value_rub,upload_id,upload_status,last_error,private_marker) VALUES(10,'foreign','98615635','anytour_modeled_value_v1','semantic_stage_value_25000_v1',$now,'accepted','agency_choice',25000,'900','PROCESSED','','FOREIGN')");
    $p->exec("INSERT INTO max_channel_conversion_outbox(id,project_key,counter_id,transport_target,value_contract,event_unix,state,value_stage,value_rub,upload_id,upload_status,last_error,private_marker) VALUES(11,'anytour','999','anytour_modeled_value_v1','semantic_stage_value_25000_v1',$now,'accepted','agency_choice',25000,'901','PROCESSED','','FOREIGN')");
    $insert->execute([12,ModeledConversionValueContract::TARGET,ModeledConversionValueContract::VERSION,$act-1,'accepted','agency_choice',25000,'902','PROCESSED','']);
    $p->exec("UPDATE max_channel_conversion_outbox SET platform=CASE WHEN id<=2 THEN 'max' ELSE 'telegram' END,conversation_id=CASE WHEN id<=2 THEN 10 ELSE id END,client_id=CASE WHEN id<=3 THEN '8800000123' ELSE NULL END");
    $before=json_encode($p->query('SELECT * FROM max_channel_conversion_outbox ORDER BY id')->fetchAll(PDO::FETCH_ASSOC),JSON_THROW_ON_ERROR);
    $r=forecastV1Read($p);$d=$r['value_delivery'];
    $check('private value suffix keeps disjoint queue states and exact incremental sums',$d['available']&&$d['window']['inspected_rows']===4&&$d['reserved_value_rub']===8333.0
        &&$d['by_state']['unknown']['value_rub']===2084.0&&$d['by_state']['pending']['value_rub']===4166.0);
    $check('API acceptance valid rows and visit report are separate',$d['api_accepted_receipts']['events']===2&&$d['api_accepted_receipts']['value_rub']===2083.0
        &&$d['by_state']['processed']['events']===1&&$d['metrika_goal_report']['goal_visits']===1&&$d['metrika_goal_report']['visit_linkage']==='observed_in_goal_session_report');
    $check('private value counts separate identities conversations and channel coverage',
        $d['conversations_in_ledger_window']===3&&$d['identity_resolution']['with_identity']['events']===3
        &&$d['identity_resolution']['with_identity']['value_rub']===4167.0
        &&$d['identity_resolution']['without_identity']['value_rub']===4166.0
        &&$d['by_platform']['max']['conversations']===1&&$d['by_platform']['telegram']['conversations']===2);
    $json=json_encode($r,JSON_THROW_ON_ERROR);
    $check('value read returns no identities upload IDs arbitrary cached text or source markers',!str_contains($json,'PRIVATE_REPORT_MUST_NOT_LEAVE')&&!str_contains($json,'PRIVATE_LEDGER_CLIENT_ID')&&!str_contains($json,'"501"')&&!str_contains($json,'"502"')&&!str_contains($json,'8800000123'));
    $r=forecastV1Read($p);
    $check('repeated private read causes no queue or saved-profile mutation',$before===json_encode($p->query('SELECT * FROM max_channel_conversion_outbox ORDER BY id')->fetchAll(PDO::FETCH_ASSOC),JSON_THROW_ON_ERROR));
    $check('legacy successful tick has an explicit null recorded failure',array_key_exists('last_recorded_failure',$d)&&$d['last_recorded_failure']===null);
    $saveFailureReceipt=$p->prepare("UPDATE modeled_conversion_value_export_settings SET last_receipt_json=?,last_tick_unix=?");
    $failureSnapshot=static fn():array=>[
        $p->query('SELECT * FROM modeled_conversion_value_export_settings ORDER BY project_key')->fetchAll(PDO::FETCH_ASSOC),
        $p->query('SELECT * FROM max_channel_conversion_outbox ORDER BY id')->fetchAll(PDO::FETCH_ASSOC),forecastV1Rows($p)];
    $failureReadOnly=true;
    $readFailure=static function(array $savedReceipt,mixed $lastTick)use($p,$saveFailureReceipt,$failureSnapshot,&$failureReadOnly):array{
        $saveFailureReceipt->execute([json_encode($savedReceipt,JSON_THROW_ON_ERROR),$lastTick]);
        $saved=$failureSnapshot();$read=forecastV1Read($p);$failureReadOnly=$failureReadOnly&&$saved===$failureSnapshot();return $read;
    };
    $failure=['tick_at_unix'=>$now-120,'phase'=>'upload','category'=>'transport_not_sent'];
    $withFailure=$receipt;$withFailure['last_recorded_failure']=$failure+[
        'message'=>'PRIVATE_TICK_MESSAGE_MUST_NOT_LEAVE','client_id'=>'PRIVATE_TICK_ID_MUST_NOT_LEAVE',
        'response'=>['body'=>'PRIVATE_TICK_RESPONSE_MUST_NOT_LEAVE']];
    $r=$readFailure($withFailure,$now-60);$d=$r['value_delivery'];
    $check('healthy current tick retains an older sanitized failure without becoming a current error',
        $d['available']&&$d['last_tick_errors']===0&&$d['last_tick_at']===gmdate('Y-m-d\TH:i:s\Z',$now-60)
        &&$d['last_recorded_failure']===['tick_at'=>gmdate('Y-m-d\TH:i:s\Z',$now-120),'phase'=>'upload','category'=>'transport_not_sent']
        &&$d['reserved_value_rub']===8333.0);
    $check('recorded failure is explicitly historical',$d['interpretation']['last_recorded_failure_is_historical']===true);
    $check('failure projection drops all arbitrary private exception and provider fields',!str_contains(json_encode($r,JSON_THROW_ON_ERROR),'PRIVATE_TICK_'));
    $legacyFailure=$receipt;$legacyFailure['errors']=1;$d=$readFailure($legacyFailure,$now-60)['value_delivery'];
    $check('legacy tick error count cannot fabricate a failure category',$d['last_tick_errors']===1&&$d['last_recorded_failure']===null);
    foreach(['activation'=>$act,'last_tick'=>$now-60]as$boundary=>$at){
        $withFailure['last_recorded_failure']=array_replace($failure,['tick_at_unix'=>$at]);
        $d=$readFailure($withFailure,$now-60)['value_delivery'];
        $check('recorded failure accepts inclusive '.$boundary.' boundary',
            $d['last_recorded_failure']===['tick_at'=>gmdate('Y-m-d\TH:i:s\Z',$at),'phase'=>'upload','category'=>'transport_not_sent']);
    }
    $invalidFailures=['null_record'=>null,'scalar_record'=>'PRIVATE_TICK_INVALID_RECORD','missing_time'=>['phase'=>'upload','category'=>'transport_not_sent']];
    foreach(['numeric_string'=>(string)($now-120),'fractional'=>$now-120.5,'boolean'=>true,'null_time'=>null,
        'pre_activation'=>$act-1,'future'=>$now+3600,'after_last_tick'=>$now-30]as$reason=>$at)
        $invalidFailures[$reason]=array_replace($failure,['tick_at_unix'=>$at]);
    foreach(['phase'=>'UPLOAD','category'=>'PRIVATE_TICK_UNKNOWN_CATEGORY']as$field=>$value)
        $invalidFailures['unknown_'.$field]=array_replace($failure,[$field=>$value]);
    foreach($invalidFailures as$reason=>$invalid){
        $withFailure['last_recorded_failure']=$invalid;$read=$readFailure($withFailure,$now-60);$d=$read['value_delivery'];
        $check('invalid recorded failure '.$reason.' stays null without hiding the ledger',
            $d['available']&&$d['last_recorded_failure']===null&&$d['last_tick_errors']===0&&$d['reserved_value_rub']===8333.0
            &&!str_contains(json_encode($read,JSON_THROW_ON_ERROR),'PRIVATE_TICK_'));
    }
    $withFailure['last_recorded_failure']=$failure;
    foreach(['missing'=>null,'pre_activation'=>$act-1,'future'=>$now+3600]as$reason=>$lastTick){
        $d=$readFailure($withFailure,$lastTick)['value_delivery'];
        $check('invalid last tick '.$reason.' cannot validate a historical failure',
            $d['available']&&$d['last_tick_at']===null&&$d['last_recorded_failure']===null);
    }
    $check('failure status reads never mutate saved settings ledger or profiles',$failureReadOnly);
    $saveFailureReceipt->execute([json_encode($receipt,JSON_THROW_ON_ERROR),$now]);
    $audit=['version'=>'retained_priced_base_v1','checked_at'=>gmdate('Y-m-d\TH:i:s\Z',$now),
        'goal_rows_scanned'=>1001,'complete'=>false,'unprocessed_candidates'=>0,
        'actions'=>['voided'=>['events'=>1001,'removed_rub'=>100100000]],
        'corrections'=>[['action'=>'voided','original_rub'=>100000,'applied_rub'=>0,
            'prior_reserved_rub'=>100000,'assessed_at'=>gmdate('Y-m-d\TH:i:s\Z',$now)]]];
    $saveReceipt=$p->prepare("UPDATE modeled_conversion_value_export_settings SET last_receipt_json=?");
    $withAudit=$receipt;$withAudit['price_continuity_audit']=$audit;
    $saveReceipt->execute([json_encode($withAudit,JSON_THROW_ON_ERROR)]);$d=forecastV1Read($p)['value_delivery'];
    $check('saved price audit accepts the new monetary bounds without expanding its recorded scan window',
        $d['available']&&$d['price_continuity_audit']['available']
        &&$d['price_continuity_audit']['goal_rows_scanned']===1001
        &&$d['price_continuity_audit']['actions']['voided']['removed_rub']===100100000
        &&$d['price_continuity_audit']['corrections'][0]===$audit['corrections'][0]);
    foreach(['correction','aggregate']as$boundary){
        $invalid=$audit;
        if($boundary==='correction')$invalid['corrections'][0]['prior_reserved_rub']=100001;
        else $invalid['actions']['voided']['removed_rub']=100100001;
        $withAudit['price_continuity_audit']=$invalid;$saveReceipt->execute([json_encode($withAudit,JSON_THROW_ON_ERROR)]);
        $d=forecastV1Read($p)['value_delivery'];
        $check('an out-of-bound price audit '.$boundary.' cannot hide the independent ledger',
            $d['available']&&$d['reserved_value_rub']===8333.0
            &&$d['price_continuity_audit']===['available'=>false,'reason'=>'invalid_recorded_audit']);
    }
    $saveReceipt->execute([json_encode($receipt,JSON_THROW_ON_ERROR)]);
    $receipt['metrika_report']['fetched_at']=gmdate('Y-m-d\\TH:i:s\\Z',$now-901);
    $p->prepare("UPDATE modeled_conversion_value_export_settings SET last_receipt_json=?")->execute([json_encode($receipt,JSON_THROW_ON_ERROR)]);
    $r=forecastV1Read($p);
    $check('stale cache never confirms current visit linkage',!$r['value_delivery']['metrika_goal_report']['fresh']&&$r['value_delivery']['metrika_goal_report']['visit_linkage']==='unknown');
    $receipt['metrika_report']=$report;$receipt['metrika_report']['status']='sampled';$receipt['metrika_report']['sampled']=true;
    $p->prepare("UPDATE modeled_conversion_value_export_settings SET last_receipt_json=?")->execute([json_encode($receipt,JSON_THROW_ON_ERROR)]);
    $r=forecastV1Read($p);$check('sampled totals stay explicit and do not prove exact linkage',$r['value_delivery']['metrika_goal_report']['status']==='sampled'&&$r['value_delivery']['metrika_goal_report']['visit_linkage']==='unknown');
    $receipt['metrika_report']=['status'=>'unavailable','goal_id'=>'678187097','currency'=>'RUB','fetched_at'=>$report['fetched_at'],
        'reason'=>'report_api_unavailable','http_status'=>429,'transport_error_code'=>0,'failure_category'=>'rate_limited',
        'private_response'=>'PRIVATE_PROVIDER_BODY_MUST_NOT_LEAVE'];
    $p->prepare("UPDATE modeled_conversion_value_export_settings SET last_receipt_json=?")->execute([json_encode($receipt,JSON_THROW_ON_ERROR)]);
    $observedReport=forecastV1Read($p)['value_delivery']['metrika_goal_report'];
    $check('cached ordinary report exposes only bounded HTTP and transport codes with a closed failure category',
        $observedReport['status']==='unavailable'&&$observedReport['http_status']===429&&$observedReport['transport_error_code']===0
        &&$observedReport['failure_category']==='rate_limited'&&$observedReport['goal_visits']===null
        &&!str_contains(json_encode($observedReport,JSON_THROW_ON_ERROR),'PRIVATE_'));
    $decisionCounts=['within_current_window'=>1,'outside_current_window'=>0,'no_prior_visit'=>1,'visit_report_unavailable'=>1,'unknown'=>0];
    $receipt['visit_gate_decisions']=['v'=>1,'checked_at_unix'=>$now-10,'attempts'=>3,'reason_counts'=>$decisionCounts];
    $p->prepare("UPDATE modeled_conversion_value_export_settings SET last_receipt_json=?")->execute([json_encode($receipt,JSON_THROW_ON_ERROR)]);
    $gateRead=forecastV1Read($p)['value_delivery']['visit_gate_decisions'];
    $check('private status returns only saved ordinary preclaim decision counts without a new assessment or provider request',
        $gateRead['available']&&$gateRead['attempts']===3&&$gateRead['reason_counts']===$decisionCounts
        &&$gateRead['checked_at']===gmdate('Y-m-d\TH:i:s\Z',$now-10)&&$gateRead['provider_requests_in_reader']===0
        &&$gateRead['zero_attempts_does_not_prove_no_visits']);
    $validGate=$receipt['visit_gate_decisions'];
    foreach(['missing','extra','strings','sum','future','oversize']as$kind){
        $bad=$validGate;
        if($kind==='missing')unset($bad['reason_counts']['unknown']);
        elseif($kind==='extra')$bad['private_client_id']='PRIVATE_GATE_ID';
        elseif($kind==='strings')$bad['attempts']='3';
        elseif($kind==='sum')$bad['attempts']=2;
        elseif($kind==='future')$bad['checked_at_unix']=$now+600;
        else$bad['reason_counts']['private']=str_repeat('PRIVATE_GATE_',100);
        $receipt['visit_gate_decisions']=$bad;
        $p->prepare("UPDATE modeled_conversion_value_export_settings SET last_receipt_json=?")->execute([json_encode($receipt,JSON_THROW_ON_ERROR)]);
        $badRead=forecastV1Read($p)['value_delivery']['visit_gate_decisions'];
        $check('malformed saved visit-gate '.$kind.' stays unavailable without leaking private fields',
            !$badRead['available']&&!str_contains(json_encode($badRead,JSON_THROW_ON_ERROR),'PRIVATE_'));
    }
    $receipt['visit_gate_decisions']=['v'=>1,'checked_at_unix'=>$now-10,'attempts'=>0,
        'reason_counts'=>array_fill_keys(array_keys($decisionCounts),0)];
    $p->prepare("UPDATE modeled_conversion_value_export_settings SET last_receipt_json=?")->execute([json_encode($receipt,JSON_THROW_ON_ERROR)]);
    $zeroRead=forecastV1Read($p)['value_delivery']['visit_gate_decisions'];
    $check('zero saved preclaim decisions never imply that visits are absent',
        $zeroRead['available']&&$zeroRead['attempts']===0&&$zeroRead['zero_attempts_does_not_prove_no_visits']);
    $check('legacy saved visit decisions retain their counts while failure stages remain explicitly unrecorded',
        $gateRead['failure_diagnostics']===['available'=>false,'reason'=>'not_recorded_v1']);
    $v2Counts=array_fill_keys(array_keys($decisionCounts),0);$v2Counts['visit_report_unavailable']=3;
    $clockMask=32|1024|8192|1048576;
    $v2=['v'=>2,'checked_at_unix'=>$now-10,'attempts'=>3,'reason_counts'=>$v2Counts,
        'failures'=>[[4,null,28,0,1],[6,400,0,17,1],[8,200,0,$clockMask,1]]];
    $readV2=static function(array $audit)use($p,&$receipt):array{
        $receipt['visit_gate_decisions']=$audit;
        $p->prepare("UPDATE modeled_conversion_value_export_settings SET last_receipt_json=?")->execute([json_encode($receipt,JSON_THROW_ON_ERROR)]);
        return forecastV1Read($p)['value_delivery']['visit_gate_decisions'];
    };
    $diagnosed=$readV2($v2);$details=$diagnosed['failure_diagnostics'];
    $check('saved failure stages distinguish transport scope and structural clock failures without revealing identity or dates',
        $diagnosed['available']&&$details['available']
        &&array_column($details['rows'],'stage')===['report_transport','report_scope','report_row']
        &&$details['rows'][0]['http_status']===null&&$details['rows'][0]['transport_error_code']===28
        &&$details['rows'][1]['detail_flags']===['query_invalid','filter_mismatch']
        &&$details['rows'][2]['detail_flags']===['clock_invalid','selected_clock_format_iso_datetime',
            'name_clock_format_space_datetime','id_clock_format_other_or_missing']
        &&array_sum(array_column($details['rows'],'count'))===3&&$diagnosed['provider_requests_in_reader']===0);
    foreach([[1,null,28,0],[2,403,0,0],[3,200,0,5],[5,503,0,0],[7,200,0,17],
        [8,200,0,4|128],[8,200,0,64|4096|65536|524288],[9,null,null,0]]as$tuple){
        $one=$v2;$one['attempts']=1;$one['reason_counts']['visit_report_unavailable']=1;$one['failures']=[array_merge($tuple,[1])];
        $seen=$readV2($one);
        $check('strict closed attribution failure stage '.$tuple[0].' projects only saved bounded scalar evidence',
            $seen['available']&&$seen['failure_diagnostics']['available']&&count($seen['failure_diagnostics']['rows'])===1);
    }
    foreach(['tuple_shape','stage_string','http_string','curl_range','forbidden_mask','zero_mask','sum','duplicate',
        'cid_flag_without_cause','clock_group_missing','clock_groups_multiple','clock_with_other_cause','clock_flags_without_cause',
        'both_clock_causes','internal_http','private_extra']as$kind){
        $bad=$v2;
        if($kind==='tuple_shape')array_pop($bad['failures'][0]);
        elseif($kind==='stage_string')$bad['failures'][0][0]='4';
        elseif($kind==='http_string')$bad['failures'][0][1]='200';
        elseif($kind==='curl_range')$bad['failures'][0][2]=101;
        elseif($kind==='forbidden_mask')$bad['failures'][1]=[6,400,0,32,1];
        elseif($kind==='zero_mask')$bad['failures'][1]=[6,400,0,0,1];
        elseif($kind==='sum')$bad['failures'][0][4]=2;
        elseif($kind==='duplicate')$bad['failures'][1]=$bad['failures'][0];
        elseif($kind==='cid_flag_without_cause')$bad['failures'][2]=[8,200,0,1|128,1];
        elseif($kind==='clock_group_missing')$bad['failures'][2]=[8,200,0,32|1024|8192,1];
        elseif($kind==='clock_groups_multiple')$bad['failures'][2]=[8,200,0,$clockMask|2048,1];
        elseif($kind==='clock_with_other_cause')$bad['failures'][2]=[8,200,0,$clockMask|1,1];
        elseif($kind==='clock_flags_without_cause')$bad['failures'][2]=[8,200,0,1|1024,1];
        elseif($kind==='both_clock_causes')$bad['failures'][2]=[8,200,0,$clockMask|64,1];
        elseif($kind==='internal_http')$bad['failures'][0]=[9,200,null,0,1];
        else$bad['private_client_id']='PRIVATE_V2_CLIENT';
        $seen=$readV2($bad);
        $check('malformed failure diagnostic '.$kind.' never becomes a guessed provider result',
            !$seen['available']&&!str_contains(json_encode($seen,JSON_THROW_ON_ERROR),'PRIVATE_'));
    }
    $emptyV2=$v2;$emptyV2['attempts']=0;$emptyV2['reason_counts']['visit_report_unavailable']=0;$emptyV2['failures']=[];
    $emptySeen=$readV2($emptyV2);
    $check('empty v2 failure diagnostics retain zero-attempt uncertainty',
        $emptySeen['available']&&$emptySeen['failure_diagnostics']['available']
        &&$emptySeen['failure_diagnostics']['rows']===[]&&$emptySeen['zero_attempts_does_not_prove_no_visits']);
    unset($receipt['visit_gate_decisions']);
    foreach([['429',0,'rate_limited'],[99,-1,'private_provider_error'],[600,101,'unknown'],[null,null,null]]as[$http,$transport,$category]){
        $receipt['metrika_report']['http_status']=$http;$receipt['metrika_report']['transport_error_code']=$transport;
        $receipt['metrika_report']['failure_category']=$category;
        $p->prepare("UPDATE modeled_conversion_value_export_settings SET last_receipt_json=?")->execute([json_encode($receipt,JSON_THROW_ON_ERROR)]);
        $safeReport=forecastV1Read($p)['value_delivery']['metrika_goal_report'];
        $check('invalid or missing report diagnostics never coerce strings or private response text',
            $safeReport['http_status']===null
            &&$safeReport['transport_error_code']===(is_int($transport)&&$transport>=0&&$transport<=100?$transport:null)
            &&$safeReport['failure_category']===(in_array($category,['rate_limited','unknown'],true)?$category:null)
            &&!str_contains(json_encode($safeReport,JSON_THROW_ON_ERROR),'PRIVATE_'));
    }
    $successReport=$report+['http_status'=>200,'transport_error_code'=>0,'failure_category'=>'rate_limited'];
    $receipt['metrika_report']=$successReport;
    $p->prepare("UPDATE modeled_conversion_value_export_settings SET last_receipt_json=?")->execute([json_encode($receipt,JSON_THROW_ON_ERROR)]);
    $successRead=forecastV1Read($p)['value_delivery']['metrika_goal_report'];
    $check('a successful saved report never inherits a failure label',
        $successRead['status']==='ok'&&$successRead['http_status']===200&&$successRead['failure_category']===null);
    $receipt['metrika_report']=['status'=>'forbidden','goal_id'=>'678187097','currency'=>'RUB','fetched_at'=>$report['fetched_at'],'reason'=>'report_scope_or_counter_access'];
    $p->prepare("UPDATE modeled_conversion_value_export_settings SET last_receipt_json=?")->execute([json_encode($receipt,JSON_THROW_ON_ERROR)]);
    $r=forecastV1Read($p);$check('missing reporting permission remains unknown rather than zero',$r['value_delivery']['metrika_goal_report']['status']==='forbidden'&&$r['value_delivery']['metrika_goal_report']['goal_visits']===null);
    $p->exec("UPDATE modeled_conversion_value_export_settings SET last_receipt_json=NULL");$r=forecastV1Read($p);
    $check('missing recorded report is not an invented zero',$r['value_delivery']['metrika_goal_report']['status']==='not_recorded'&&$r['value_delivery']['metrika_goal_report']['revenue_rub']===null);
    $oldClass=$p->getAttribute(PDO::ATTR_STATEMENT_CLASS);$p->setAttribute(PDO::ATTR_STATEMENT_CLASS,[ForecastV1ObservedStatement::class]);ForecastV1ObservedStatement::$valueCopied=false;
    $revoked=false;try{forecastV1Read($p,static function():void{if(ForecastV1ObservedStatement::$valueCopied)throw new DomainException('fixture_revoked_value');});}
    catch(DomainException $ignored){$revoked=true;}finally{$p->setAttribute(PDO::ATTR_STATEMENT_CLASS,$oldClass);}
    $check('revocation after value aggregate copy prevents all release',$revoked&&ForecastV1ObservedStatement::$valueCopied);
    $p->exec("UPDATE max_channel_conversion_outbox SET state='unknown_untrusted_state' WHERE id=1");$r=forecastV1Read($p);
    $check('an unclassified ledger state is unavailable not an incomplete zero',$r['value_delivery']['available']===false&&$r['value_delivery']['reason']==='invalid_ledger');
    $p->exec("UPDATE max_channel_conversion_outbox SET state='processed' WHERE id=1");
    for($i=1000;$i<1064;$i++)$insert->execute([$i,ModeledConversionValueContract::TARGET,ModeledConversionValueContract::VERSION,
        $now-10,'pending','interest',100000,null,null,'identity_not_found']);
    $p->exec("UPDATE max_channel_conversion_outbox SET platform='max',conversation_id=id WHERE id>=1000 AND id<1064");
    $d=forecastV1Read($p)['value_delivery'];
    $check('64 authorized maximum amounts remain readable above the old aggregate money bound',
        $d['available']&&$d['reserved_value_rub']===6408333.0
        &&$d['by_state']['pending']['events']===65&&$d['by_state']['pending']['value_rub']===6404166.0
        &&$d['window']['max_rows']===201&&$d['window']['inspected_rows']===68&&$d['window']['complete_within_bound']);
    for($i=100;$i<303;$i++)$insert->execute([$i,ModeledConversionValueContract::TARGET,ModeledConversionValueContract::VERSION,$now-10,'pending','interest',1250,null,null,'identity_not_found']);
    $r=forecastV1Read($p);$check('value history stays bounded and does not imply complete coverage',$r['value_delivery']['window']['inspected_rows']===201&&!$r['value_delivery']['window']['complete_within_bound']);
    $p->exec("UPDATE modeled_conversion_value_export_settings SET history_from_unix=1793570400,history_until_unix=1793656800,history_cursor=1,history_ceiling=3,history_scanned=1,history_collected=0");
    $p->exec("UPDATE modeled_conversion_profile_versions SET observed_at_unix=1793574000");
    $p->exec("INSERT INTO modeled_conversion_profile_versions(project_key,platform,conversation_id,observed_at_unix,profile_json) VALUES('anytour','telegram','9',1793575000,'PRIVATE_UNUSED_PROFILE'),('anytour','max','20',1793576000,'PRIVATE_UNUSED_PROFILE'),('foreign','max','99',1793577000,'PRIVATE_UNUSED_PROFILE')");
    $r=forecastV1Read($p);$cohort=$r['value_delivery']['historical_review']['saved_cohort'];
    $check('metadata cohort counts distinct saved dialogues rather than repeated profiles',
        $cohort['versions_within_bound']===3&&$cohort['conversations_within_bound']===2
        &&$cohort['reviewed_conversations_within_bound']===1&&$cohort['reviewed_versions_within_bound']===1
        &&$cohort['by_platform']['telegram']['conversations']===1&&$cohort['by_platform']['max']['conversations']===1
        &&$cohort['complete_within_bound']&&!str_contains(json_encode($r,JSON_THROW_ON_ERROR),'PRIVATE_UNUSED_PROFILE'));
    $p->exec('DROP TABLE modeled_conversion_value_export_settings');$r=forecastV1Read($p);
    $check('missing value storage preserves the independent forecast result',$r['available']&&$r['value_delivery']['available']===false&&$r['value_delivery']['reason']==='storage_unavailable');
}

/** Reuse the existing one-profile fixture; all probes are SELECTs on its unchanged saved rows. */
function forecastCoreProjectionBudgetAssertions(PDO $p,callable $check,array $expected):void
{
    $snapshot=static fn():array=>[forecastV1Rows($p),$p->query('SELECT * FROM modeled_conversion_profiles_state ORDER BY project_key')->fetchAll(PDO::FETCH_ASSOC)];
    $before=$snapshot();
    $capture=static function(ModeledConversionStatusBudget $budget,?callable $authorize=null)use($p):array{
        $oldClass=$p->getAttribute(PDO::ATTR_STATEMENT_CLASS);
        $p->setAttribute(PDO::ATTR_STATEMENT_CLASS,[ForecastV1ObservedStatement::class]);
        ForecastV1ObservedStatement::$observeCore=true;ForecastV1ObservedStatement::$coreStatements=[];
        ForecastV1ObservedStatement::$coreCopies=[];ForecastV1ObservedStatement::$diagnosisExecutions=0;
        try{
            $result=forecastV1Read($p,$authorize,$budget);
            return [$result,ForecastV1ObservedStatement::$coreStatements,ForecastV1ObservedStatement::$coreCopies,
                ForecastV1ObservedStatement::$diagnosisExecutions];
        }finally{ForecastV1ObservedStatement::$observeCore=false;$p->setAttribute(PDO::ATTR_STATEMENT_CLASS,$oldClass);}
    };
    $replay=static function(string $sql,array $bindings)use($p):array{
        $q=$p->prepare($sql);
        foreach($bindings as$parameter=>[$value,$type])$q->bindValue($parameter,$value,$type);
        $q->execute();$rows=$q->fetchAll(PDO::FETCH_ASSOC);$q->closeCursor();return $rows;
    };
    $checkpoints=['reader_entry','state','metadata','value_delivery','visit_receipt','first_profile','optional_views','remaining_profiles'];
    $basis='shared_budget_v1';
    $budget=new ModeledConversionStatusBudget(0.0,static fn():float=>0.0);
    [$normal,$statements,$copies,$diagnosisQueries]=$capture($budget);
    $statement=$statements[0];$body=$copies[0];
    $check('core packet replay preserves all four returned fields and frozen checkpoints preserve the original read limits',
        count($statements)===1&&count($copies)===1&&count($body)===1
        &&array_keys($body[0])===['prediction_json','followup_json','deal_json','binding_json']
        &&$replay($statement['sql'],$statement['bindings'])===$body
        &&$normal['saved']===$expected['saved']&&$normal['window']===$expected['window']
        &&$normal['input_coverage']===$expected['input_coverage']&&$snapshot()===$before
        &&$normal['read_budget']===['basis'=>$basis,'remaining_ms'=>array_fill_keys($checkpoints,5000)]
        &&$normal['saved_input_diagnosis']['available']===true&&$diagnosisQueries>0
        &&$normal['limits']['max_selected_bytes']===65536&&$normal['limits']['max_execution_seconds']===5
        &&$budget->remainingBytes()>=8192);
    $size=(int)$statement['bindings'][4][0];
    $changedSize=$statement['bindings'];$changedSize[4][0]=$size+1;
    $tooSmall=$statement['bindings'];$tooSmall[5][0]=$size-1;
    $check('both core packet size guards reject the selected row before any four-field result is copied',
        $replay($statement['sql'],$changedSize)===[]&&$replay($statement['sql'],$tooSmall)===[]&&$snapshot()===$before);
    if($p->getAttribute(PDO::ATTR_DRIVER_NAME)==='sqlite'){
        // Instrument the actual scalar, preserving JSON subtypes of the surrounding binding object.
        $evaluations=0;
        $p->sqliteCreateFunction('forecast_core_scalar_probe',static function(mixed $value)use(&$evaluations):mixed{
            $evaluations++;return $value;
        },1,PDO::SQLITE_DETERMINISTIC);
        $scalar="JSON_EXTRACT(profile_json,'$.access_status')";
        $probeSql=str_replace($scalar,'forecast_core_scalar_probe('.$scalar.')',$statement['sql'],$instrumented);
        $probeRows=$replay($probeSql,$statement['bindings']);
        $check('SQLite materializes the core binding once for both size guards and the returned packet',
            $instrumented>0&&$evaluations===1&&$probeRows===$body&&$snapshot()===$before);
    }
    // Bootstrap consumed 250ms; only the first authorization advances this controlled clock by one second.
    $clock=0.25;$advanced=false;
    $timedBudget=new ModeledConversionStatusBudget(0.0,static function()use(&$clock):float{return $clock;});
    [$timed,,,$timedDiagnosisQueries]=$capture($timedBudget,static function()use(&$clock,&$advanced):void{
        if(!$advanced){$clock+=1.0;$advanced=true;}
    });
    $remaining=array_fill_keys($checkpoints,3750);$remaining['reader_entry']=4750;
    $check('time headroom reports the pre-gate shared remainder and skips diagnosis SQL after authorization consumes time',
        $advanced&&$timed['read_budget']===['basis'=>$basis,'remaining_ms'=>$remaining]
        &&$timed['saved_input_diagnosis']===['available'=>false,'reason'=>'time_headroom','minimum_required_ms'=>4000,'remaining_ms'=>3750]
        &&$timedDiagnosisQueries===0&&$timed['saved']===$normal['saved']&&$timed['input_coverage']===$normal['input_coverage']
        &&$timed['model_calls']===0&&$timed['conversion_uploads']===0&&$timedBudget->remainingBytes()>=8192&&$snapshot()===$before);
    $boundaryBudget=new ModeledConversionStatusBudget(0.0,static fn():float=>1.0);
    [$boundary,,,$boundaryDiagnosisQueries]=$capture($boundaryBudget);
    $check('the unchanged four-second diagnosis boundary admits the real reader without changing saved rows',
        $boundary['read_budget']===['basis'=>$basis,'remaining_ms'=>array_fill_keys($checkpoints,4000)]
        &&$boundary['saved_input_diagnosis']['available']===true&&$boundaryDiagnosisQueries>0
        &&$boundary['saved_input_diagnosis']===$normal['saved_input_diagnosis']&&$boundary['saved']===$normal['saved']
        &&$boundaryBudget->remainingBytes()>=8192&&$snapshot()===$before);
}

function forecastV1Assertions(PDO $p,callable $check):void
{
    forecastReceiptPollEvidenceAssertions($check);
    $projection=new ModeledConversionForecastStatusReader($p,static function():void{},new ModeledConversionStatusBudget());
    $method=new ReflectionMethod(ModeledConversionForecastStatusReader::class,'boundedResponse');
    $large=['value_delivery'=>['predictive_v1'=>['events'=>91,'value_rub'=>1234,'calculation_unavailable_rows'=>8,
        'calculation_basis_counts'=>['probability_prediction'=>80,'stage_fraction'=>3,'unavailable'=>8],
        'stage_comparison_counts'=>['above_stage'=>50,'equal_stage'=>20,'below_stage'=>10,'stage_only'=>3,'unavailable'=>8],
        'calculation_unavailable_reason_counts'=>['profile_binding_unverified'=>0,'probability_prediction_unavailable'=>8,
            'monetary_policy_unavailable'=>0,'scalar_contract_unverified'=>0,'price_base_unverified'=>0,'frozen_value_unverified'=>0,'increment_not_explained'=>0],
        'examples'=>array_fill(0,16,['synthetic'=>str_repeat('x',2000)])]],
        'saved_input_diagnosis'=>['examples'=>array_fill(0,8,['synthetic'=>str_repeat('y',1000)])],
        'visit_delivery_check'=>['cohort_events'=>2,'cohort_value_rub'=>106],
        'saved'=>['deal_value'=>['by_monetary_policy'=>['early_deal_commission_7pct_v2'=>[
            'versions'=>4,'value_cap_rub'=>100000,'unknown_price_base_rub'=>10000,'unknown_price_versions'=>4,
            'estimated_value_versions'=>0,'full_value_base_range_rub'=>['minimum'=>10000,'maximum'=>10000]]]]]];
    $boundedOutput=$method->invoke($projection,$large);
    $check('private response preserves exact totals and visit cohort while explicitly trimming oversized optional examples',
        strlen(json_encode($boundedOutput,JSON_THROW_ON_ERROR))<=28000&&$boundedOutput['value_delivery']['predictive_v1']['events']===91
        &&$boundedOutput['value_delivery']['predictive_v1']['value_rub']===1234
        &&$boundedOutput['value_delivery']['predictive_v1']['calculation_basis_counts']===$large['value_delivery']['predictive_v1']['calculation_basis_counts']
        &&$boundedOutput['value_delivery']['predictive_v1']['stage_comparison_counts']===$large['value_delivery']['predictive_v1']['stage_comparison_counts']
        &&array_sum($boundedOutput['value_delivery']['predictive_v1']['stage_comparison_counts'])===$boundedOutput['value_delivery']['predictive_v1']['events']
        &&$boundedOutput['value_delivery']['predictive_v1']['calculation_unavailable_reason_counts']===$large['value_delivery']['predictive_v1']['calculation_unavailable_reason_counts']
        &&$boundedOutput['value_delivery']['predictive_v1']['examples_omitted_due_to_response_budget']===true
        &&$boundedOutput['visit_delivery_check']===$large['visit_delivery_check']
        &&$boundedOutput['saved']===$large['saved']);
    $currentCases=[
        ['case'=>'synthetic_current_1','state'=>'accepted','increment_rub'=>17,'synthetic'=>str_repeat('p',1600)],
        ['case'=>'synthetic_current_2','state'=>'ready','increment_rub'=>23,'synthetic'=>str_repeat('q',1600)],
        ['case'=>'synthetic_current_3','state'=>'ready','increment_rub'=>31,'synthetic'=>str_repeat('r',1600)],
    ];
    $historyPressure=['synthetic_padding'=>str_repeat('s',18000),'model_calls'=>0,'conversion_uploads'=>0,
        'value_delivery'=>['predictive_v1'=>['events'=>3,'value_rub'=>71,'examples'=>$currentCases]],
        'visit_delivery_check'=>['cohort_events'=>2,'cohort_value_rub'=>106,
            'today_sent_cohort'=>['date'=>'2026-11-02','synthetic_total'=>71],
            'yesterday_sent_cohort'=>['date'=>'2026-11-01','synthetic_total'=>53],
            'followup_sent_cohort'=>['synthetic_total'=>127,'failure_examples'=>array_fill(0,8,['synthetic'=>str_repeat('h',1600)])]]];
    $currentOutput=$method->invoke($projection,$historyPressure);
    $expectedCore=$historyPressure;$actualCore=$currentOutput;
    unset($expectedCore['visit_delivery_check']['followup_sent_cohort']['failure_examples']);
    unset($actualCore['visit_delivery_check']['followup_sent_cohort']['failure_examples'],
        $actualCore['visit_delivery_check']['followup_sent_cohort']['failure_examples_omitted_due_to_response_budget']);
    $check('historic failure detail cannot hide current delivery cases or change exact calendar and cohort totals',
        strlen(json_encode($currentOutput,JSON_THROW_ON_ERROR))<=28000
        &&$currentOutput['value_delivery']['predictive_v1']['examples']===$currentCases
        &&count($currentOutput['visit_delivery_check']['followup_sent_cohort']['failure_examples'])<8
        &&$currentOutput['visit_delivery_check']['followup_sent_cohort']['failure_examples_omitted_due_to_response_budget']===true
        &&$actualCore===$expectedCore);
    unset($historyPressure['synthetic_padding']);
    $check('historic details and current cases remain unchanged when the complete response fits',
        $method->invoke($projection,$historyPressure)===$historyPressure);

    $b=new ModeledConversionStatusBudget();$b->consumeBytes(49536);
    $limited=false;
    try{$b->withReservedBytes(8000,static function()use($b):void{
        $b->consumeBytes(5000);
        if($b->remainingBytes()<4096)throw new ModeledConversionStatusLimit('byte_limit');
    });}catch(ModeledConversionStatusLimit $e){$limited=$e->reason==='byte_limit';}
    $check('optional authorization floor restores reserved headroom without refunding consumed reads',
        $limited&&$b->remainingBytes()===11000);
    $b->consumeBytes(2100);
    $check('mandatory final authorization retains the original four KiB floor',$b->remainingBytes()>=4096);
    $slice=new ModeledConversionStatusBudget();$slice->consumeBytes(53536);
    try{$slice->withReservedBytes(8000,static fn()=>$slice->consumeBytes(4500));}
    catch(ModeledConversionStatusLimit $ignored){}
    $check('an oversized optional copy is fully charged against the original global byte cap',$slice->remainingBytes()===7500);
    $absolute=new ModeledConversionStatusBudget();$absolute->consumeBytes(60000);
    try{$absolute->withReservedBytes(4096,static fn()=>$absolute->consumeBytes(6000));}
    catch(ModeledConversionStatusLimit $ignored){}
    $exhausted=false;
    try{$absolute->check();}catch(ModeledConversionStatusLimit $e){$exhausted=$e->reason==='byte_limit';}
    $check('absolute byte exhaustion cannot be hidden by optional fallback',$exhausted);
    $deniedBudget=new ModeledConversionStatusBudget();$denied=false;
    try{$deniedBudget->withReservedBytes(8192,static function():void{throw new DomainException('revoked_fixture');});}
    catch(DomainException $ignored){$denied=true;}
    $check('permission denial is preserved and reservation cleanup never changes the global cap',
        $denied&&$deniedBudget->remainingBytes()===65536);

    forecastV1Schema($p);forecastV1Append($p,forecastV1Packet());$guardBudget=new ModeledConversionStatusBudget();
    $injected=false;$authChecks=0;
    $guard=static function()use($guardBudget,&$injected,&$authChecks):void{
        $authChecks++;
        if(!$injected){
            foreach(debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS,12)as$frame)
                if(($frame['class']??null)===ModeledConversionInputDiagnosisReader::class){
                    $injected=true;$guardBudget->consumeBytes(max(0,$guardBudget->remainingBytes()-3000));
                    throw new ModeledConversionStatusLimit('byte_limit');
                }
        }
        if($guardBudget->remainingBytes()<4096)throw new ModeledConversionStatusLimit('byte_limit');
        $guardBudget->consumeBytes(128);
    };
    $rowsBeforeBudget=forecastV1Rows($p);$bounded=forecastV1Read($p,$guard,$guardBudget);
    $check('core forecast survives optional diagnostic pressure with fresh final authorization',
        $injected&&$authChecks>4&&$bounded['saved']['prospective_estimates']===1
        &&$bounded['saved_input_diagnosis']===['available'=>false,'reason'=>'budget_headroom']
        &&$guardBudget->remainingBytes()>4096&&forecastV1Rows($p)===$rowsBeforeBudget);
    forecastCoreProjectionBudgetAssertions($p,$check,$bounded);

    forecastV1Schema($p);
    $descriptive=forecastV1Packet(messages:[
        ['text'=>'Подберите тур в Турцию на 7 ночей на следующий год.'],
        ['text'=>'Сравните другие варианты.'],['text'=>'Подумаем, обсудим позже.'],
        ['text'=>'Где находится ваш офис?'],['text'=>'Можно оплатить картой?']]);
    $frozenBefore=json_encode($descriptive,JSON_THROW_ON_ERROR);
    forecastV1Append($p,$descriptive);$beforeRows=forecastV1Rows($p);$stageRead=forecastV1Read($p);
    $check('saved stage-only estimate retains research flags without timing or deferral penalties',
        $stageRead['saved']['prospective_estimates']===1
        &&$stageRead['saved']['weighting_version_counts']===['illustrative'=>0,'stage_baseline'=>1,'archive_relative_weights'=>0]
        &&$stageRead['saved']['estimated_probability_range']===['minimum'=>0.083333,'maximum'=>0.083333]
        &&$descriptive['semantic_prediction']['features']['long_range_planning']
        &&$descriptive['semantic_prediction']['features']['deferral']
        &&$descriptive['semantic_prediction']['features']['office_question']
        &&$descriptive['semantic_prediction']['features']['payment_terms_question']
        &&forecastV1Rows($p)===$beforeRows&&json_encode($descriptive,JSON_THROW_ON_ERROR)===$frozenBefore);

    forecastV1Schema($p);
    $candidate=archivePredictiveSyntheticCandidate();
    $candidate['multipliers']['continued_meaningful_engagement']=1.2;
    $candidate['multipliers']['office_question']=1.1;
    $capsule=ArchivePredictiveRelativeWeights::capsule($candidate,hash('sha256',json_encode($candidate,JSON_THROW_ON_ERROR)));
    $legacy=forecastV1Packet();$legacyBytes=json_encode($legacy,JSON_THROW_ON_ERROR);
    $weighted=forecastV1Packet('81','91',[
        ['text'=>'Подберите тур в Турцию на 7 ночей.'],
        ['text'=>'Где находится ваш офис?']],relativeWeights:$capsule);
    forecastV1Append($p,$legacy);forecastV1Append($p,$weighted);$rowsBefore=forecastV1Rows($p);$weightedRead=forecastV1Read($p);
    $check('saved weights are identified without reinterpreting legacy forecasts',
        $weightedRead['saved']['prospective_estimates']===2
        &&$weightedRead['saved']['weighting_version_counts']===['illustrative'=>0,'stage_baseline'=>1,'archive_relative_weights'=>1]
        &&$weightedRead['saved']['relative_weight_package_counts']===[$capsule['package_sha256']=>1]
        &&json_encode($legacy,JSON_THROW_ON_ERROR)===$legacyBytes&&forecastV1Rows($p)===$rowsBefore);
    $check('new saved office signal and reviewed package remain privately attributable',
        $weighted['semantic_prediction']['features']['office_question']===true
        &&$weighted['semantic_prediction']['relative_weights']===$capsule
        &&!str_contains(json_encode($weightedRead,JSON_THROW_ON_ERROR),'Где находится'));

    forecastV1Schema($p);$a=forecastV1Packet();forecastV1Append($p,$a);forecastV1Append($p,$a);
    forecastV1Append($p,forecastV1Packet('8','10',[['text'=>'Купим тур только у вас.']],'max'));
    $before=forecastV1Rows($p);$auth=0;$r=forecastV1Read($p,static function()use(&$auth):void{$auth++;});
    $check('V1 read confirms actual saved percentages despite idle zero batch',
        $r['confirmation']==='saved_prospective_estimates'&&$r['saved']['prospective_estimates']===2
        &&$r['saved']['estimated_probability_range']['minimum']===0.083333
        &&$r['saved']['estimated_probability_range']['maximum']===0.5
        &&$r['state']['last_tick_status']==='idle'&&$r['state']['last_batch_profiles_written']===0);
    $p->prepare("UPDATE modeled_conversion_profiles_state SET last_receipt_json=? WHERE project_key='anytour'")->execute([json_encode(['status'=>'profiled','observed_at_unix'=>1793004000,'profiles_written'=>8],JSON_THROW_ON_ERROR)]);
    $boundedReceipt=forecastV1Read($p);
    $check('private read accepts eight written profiles without enlarging its four-packet inspection',
        $boundedReceipt['state']['last_batch_profiles_written']===8&&$boundedReceipt['limits']['max_profile_rows']===4);
    $check('V1 read reports separate channels and skips repeated scope versions',
        $r['saved']['channel_counts']===['max'=>1,'telegram'=>1]&&$r['window']['duplicate_scope_rows']===1
        &&$r['saved']['distinct_inspected_scopes']===2);
    $check('input coverage reports frozen human turns and anchors without reinterpreting source text',
        $r['input_coverage']['validated_profiles']===2
        &&$r['input_coverage']['admitted_customer_turns']===2
        &&$r['input_coverage']['admitted_manager_turns']===0
        &&$r['input_coverage']['linking_disabled_profiles']===0&&$r['input_coverage']['linking_metadata_unknown_profiles']===0
        &&$r['input_coverage']['profiles_with_request_anchor']===2
        &&$r['input_coverage']['meaningful_customer_turns']===2
        &&$r['input_coverage']['wizard_applications']['known_profiles']===2
        &&$r['input_coverage']['wizard_applications']['recorded_total']===0);
    $check('source continuity exposes fixed saved counters independently of purchase scoring',
        $r['input_coverage']['source_continuity']['preserved_handoff_receipts']['known_profiles']===2
        &&$r['input_coverage']['source_continuity']['preserved_handoff_receipts']['recorded_total']===0
        &&array_sum($r['window']['invalid_block_reason_counts'])===0);
    $check('V1 read retains actual freeze and input cutoff as different clocks',
        $r['saved']['prediction_at_range']['earliest']==='2026-10-26T08:40:00Z'
        &&$r['saved']['input_cutoff_at_range']['earliest']==='2026-10-26T08:23:20Z');
    $check('fresh customer-feature clocks are reported separately from a newly created snapshot',
        $r['saved']['customer_evidence_occurred_at_range']['latest']==='2026-10-26T08:06:40Z'
        &&$r['saved']['customer_evidence_available_at_range']['latest']==='2026-10-26T08:06:41Z'
        &&$r['saved']['customer_evidence_available_at_range']['latest']<$r['saved']['prediction_at_range']['earliest']);
    $check('V1 read requires repeated authorization and changes no saved rows',$auth>=5&&forecastV1Rows($p)===$before);
    $json=json_encode($r,JSON_THROW_ON_ERROR);
    $check('V1 result returns only aggregates with no scope references hashes or private fields',
        !str_contains($json,'customer_key')&&!str_contains($json,'conversation_id')&&!str_contains($json,'journal:')
        &&!str_contains($json,$a['semantic_prediction']['prediction_digest'])&&!str_contains($json,'PRIVATE_UNRELATED')
        &&!str_contains($json,'Подберите')&&strlen($json)<30000);
    $check('V1 read does not claim calibrated payment or complete new-person coverage',
        !$r['interpretation']['new_inquiry_identity_verified']&&!$r['interpretation']['population_coverage_known']
        &&$r['interpretation']['missing_payment_is_not_negative']&&!$r['interpretation']['export_allowed']
        &&$r['model_calls']===0&&$r['conversion_uploads']===0);

    forecastV1Schema($p);$moneyUnknown=forecastV1Packet('71','91',[['text'=>'Здравствуйте!']]);
    $moneyUnknown['deal_value_prediction']=ModeledConversionDealValue::freeze($moneyUnknown,[]);
    forecastV1Append($p,$moneyUnknown);$moneyRows=forecastV1Rows($p);$moneyRead=forecastV1Read($p);
    $unpricedPolicy=['versions'=>1,'value_cap_rub'=>100000,'unknown_price_base_rub'=>10000,
        'unknown_price_versions'=>1,'estimated_value_versions'=>0,
        'full_value_base_range_rub'=>['minimum'=>10000,'maximum'=>10000]];
    $check('a validated V2 monetary policy is visible without manufacturing a probability or value',
        ($moneyRead['saved']['deal_value']['by_monetary_policy']??null)===['early_deal_commission_7pct_v2'=>$unpricedPolicy]
        &&$moneyRead['saved']['prospective_estimates']===0&&$moneyRead['saved']['status_counts']['insufficient_context']===1
        &&$moneyRead['saved']['estimated_probability_range']===['minimum'=>null,'maximum'=>null]
        &&$moneyRead['saved']['deal_value']['value_range_rub']===['minimum'=>null,'maximum'=>null]);
    $moneyJson=json_encode($moneyRead,JSON_THROW_ON_ERROR);
    $check('monetary policy readback preserves saved bytes private fields and existing read limits',
        forecastV1Rows($p)===$moneyRows&&$moneyRead['model_calls']===0&&$moneyRead['conversion_uploads']===0
        &&$moneyRead['limits']===['max_metadata_rows'=>20,'max_profile_rows'=>4,'max_selected_document_bytes'=>49152,
            'max_selected_bytes'=>65536,'max_execution_seconds'=>5]
        &&strlen($moneyJson)<=30000&&!str_contains($moneyJson,'customer_key')&&!str_contains($moneyJson,'conversation_id')
        &&!str_contains($moneyJson,'journal:')&&!str_contains($moneyJson,'PRIVATE_UNRELATED')
        &&!str_contains($moneyJson,$moneyUnknown['deal_value_prediction']['deal_digest'])
        &&!str_contains($moneyJson,$moneyUnknown['deal_value_prediction']['rules_sha256']));

    foreach([100000=>7000,2000000=>100000]as$price=>$base){
        forecastV1Schema($p);$moneyKnown=forecastV1Packet('72','92');
        $ref=$moneyKnown['profile']['observation_input']['evidence'][0]['references'][0];
        $moneyKnown['profile']['fields']['budget']=['status'=>'known','usable_at_prediction'=>true,
            'available_at'=>$moneyKnown['profile']['prediction_at'],
            'value'=>['minimum'=>$price,'maximum'=>$price,'currency'=>'RUB','unit'=>'party_total'],'references'=>[$ref]];
        $moneyKnown['deal_value_prediction']=ModeledConversionDealValue::freeze($moneyKnown,[]);
        forecastV1Append($p,$moneyKnown);$moneyRows=forecastV1Rows($p);$moneyRead=forecastV1Read($p);
        $check('saved priced V2 base is distinct from its unknown-price assumption '.$price,
            ($moneyRead['saved']['deal_value']['by_monetary_policy']['early_deal_commission_7pct_v2']??null)===
                ['versions'=>1,'value_cap_rub'=>100000,'unknown_price_base_rub'=>10000,'unknown_price_versions'=>0,
                    'estimated_value_versions'=>1,'full_value_base_range_rub'=>['minimum'=>$base,'maximum'=>$base]]
            &&$moneyRead['saved']['deal_value']['estimated_deal_versions']===1
            &&forecastV1Rows($p)===$moneyRows);
    }

    // A synthetic legacy capsule keeps the original policy/hash and its already frozen arithmetic.
    $moneyLegacy=forecastV1Packet('73','93');
    $moneyLegacy['deal_value_prediction']=ModeledConversionDealValue::freeze($moneyLegacy,[]);
    $legacyMoney=&$moneyLegacy['deal_value_prediction'];$legacyMoney['version']='early_deal_commission_7pct_v1';
    $legacyMoney['rules_sha256']='f4f205793ce51a18f7268292df3e0362b152869d60c71a5bc9c62017ca136593';
    $legacyMoney['full_value_base_rub']=25000;$legacyMoney['base_basis']='existing_25000_fallback';
    $legacyMoney['reason']='deal_price_unknown_existing_base';$legacyMoney['value_rub']=2083;
    unset($legacyMoney['deal_digest']);$legacyMoney['deal_digest']=ModeledConversionSemanticPrediction::digest($legacyMoney);unset($legacyMoney);
    forecastV1Schema($p);forecastV1Append($p,$moneyLegacy);forecastV1Append($p,$moneyUnknown);
    $moneyRows=forecastV1Rows($p);$moneyRead=forecastV1Read($p);
    $policies=$moneyRead['saved']['deal_value']['by_monetary_policy']??[];
    $check('mixed monetary capsules retain their own policy parameters and frozen values',
        count($policies)===2&&($policies['early_deal_commission_7pct_v2']??null)===$unpricedPolicy
        &&($policies['early_deal_commission_7pct_v1']??null)===
            ['versions'=>1,'value_cap_rub'=>25000,'unknown_price_base_rub'=>25000,'unknown_price_versions'=>1,
                'estimated_value_versions'=>1,'full_value_base_range_rub'=>['minimum'=>25000,'maximum'=>25000]]
        &&$moneyRead['saved']['deal_value']['value_range_rub']===['minimum'=>2083,'maximum'=>2083]
        &&forecastV1Rows($p)===$moneyRows);

    foreach(['digest','wrong_base','unknown_policy']as$failure){
        $badMoney=$moneyUnknown;
        if($failure==='digest')$badMoney['deal_value_prediction']['deal_digest']=str_repeat('0',64);
        else{
            if($failure==='wrong_base')$badMoney['deal_value_prediction']['full_value_base_rub']=25000;
            else $badMoney['deal_value_prediction']['version']='PRIVATE_UNTRUSTED_POLICY';
            unset($badMoney['deal_value_prediction']['deal_digest']);
            $badMoney['deal_value_prediction']['deal_digest']=ModeledConversionSemanticPrediction::digest($badMoney['deal_value_prediction']);
        }
        forecastV1Schema($p);forecastV1Append($p,$badMoney);$moneyRows=forecastV1Rows($p);$moneyRead=forecastV1Read($p);
        $check('invalid monetary data cannot corroborate a policy while the semantic packet remains visible '.$failure,
            $moneyRead['saved']['valid_v1_versions']===1&&!isset($moneyRead['saved']['deal_value'])
            &&forecastV1Rows($p)===$moneyRows&&!str_contains(json_encode($moneyRead,JSON_THROW_ON_ERROR),'PRIVATE_UNTRUSTED_POLICY'));
    }

    forecastV1Schema($p);$clockSeparated=forecastV1Packet(extractionDelay:17);forecastV1Append($p,$clockSeparated);
    $r=forecastV1Read($p);
    $check('actual writer extraction time may follow input cutoff without invalidating a natural save',
        $r['saved']['prospective_estimates']===1
        &&$r['saved']['profile_prediction_at_range']['earliest']==='2026-10-26T08:23:37Z'
        &&$r['saved']['input_cutoff_at_range']['earliest']==='2026-10-26T08:23:20Z'
        &&$r['saved']['prediction_at_range']['earliest']==='2026-10-26T08:40:00Z');
    $p->exec('UPDATE modeled_conversion_profile_versions SET observed_at_unix=1793003000');
    $r=forecastV1Read($p);
    $check('a parent row using input time instead of the saved extraction clock is rejected',
        $r['saved']['valid_v1_versions']===0&&$r['window']['invalid_blocks']===1);
    forecastV1Schema($p);$guided=forecastV1Packet(messages:[['text'=>'Здравствуйте!']]);
    $guided['coverage']['applied_wizard_fields']=2;
    $guided['contextual_signals']['coverage']['context_linking_disabled']=true;
    $guided['coverage']['errors']['action_or_unknown_message_type']=3;
    forecastV1Append($p,$guided);$r=forecastV1Read($p);
    $check('recorded guided and action counters cannot manufacture a purchase probability',
        $r['saved']['prospective_estimates']===0&&$r['saved']['status_counts']['insufficient_context']===1
        &&$r['input_coverage']['wizard_applications']['recorded_total']===2
        &&$r['input_coverage']['action_or_unknown_type_rows']['recorded_total']===3
        &&$r['input_coverage']['profiles_with_request_anchor']===0
        &&$r['input_coverage']['linking_disabled_profiles']===1);
    forecastV1Schema($p);$wrongSummary=$a;$wrongSummary['contextual_signals']['engagement']['customer_turns']='1';
    forecastV1Append($p,$wrongSummary);$r=forecastV1Read($p);
    $check('untrusted counter types cannot corroborate an otherwise digest-valid model block',
        $r['saved']['valid_v1_versions']===0&&$r['window']['invalid_blocks']===1
        &&$r['input_coverage']['invalid_summary_profiles']===1
        &&$r['window']['invalid_block_reason_counts']['input_summary']===1);
    forecastV1Schema($p);$missingContinuity=$a;
    unset($missingContinuity['contextual_signals']['coverage']['prediction_source_continuity']);
    forecastV1Append($p,$missingContinuity);$r=forecastV1Read($p);
    $check('older frozen input without continuity metadata remains explicitly unknown',
        $r['saved']['prospective_estimates']===1
        &&$r['input_coverage']['source_continuity']['preserved_handoff_receipts']['unknown_profiles']===1
        &&$r['input_coverage']['source_continuity']['preserved_handoff_receipts']['known_profiles']===0);
    forecastV1Schema($p);$wrongContinuity=$a;
    $wrongContinuity['contextual_signals']['coverage']['prediction_source_continuity']['preserved_handoff_receipts']='1';
    forecastV1Append($p,$wrongContinuity);$r=forecastV1Read($p);
    $check('mistyped optional source counters cannot corroborate continuity or alter the saved probability',
        $r['saved']['prospective_estimates']===1&&$r['saved']['estimated_probability_range']['minimum']===0.083333
        &&$r['input_coverage']['source_continuity']['preserved_handoff_receipts']['unknown_profiles']===1
        &&$r['input_coverage']['source_continuity']['preserved_handoff_receipts']['known_profiles']===0);
    $oldPrecision=(string)ini_get('serialize_precision');
    try{
        foreach(['-1','14','17']as$writer)foreach(['-1','14','17']as$reader){
            forecastV1Schema($p);ini_set('serialize_precision',$writer);
            $frozen=forecastV1Packet();forecastV1Append($p,$frozen);$before=forecastV1Rows($p);
            ini_set('serialize_precision',$reader);$r=forecastV1Read($p);
            $check('persisted numeric prediction survives producer consumer precision '.$writer.' '.$reader,
                $r['saved']['prospective_estimates']===1&&$r['window']['invalid_blocks']===0
                &&$r['saved']['estimated_probability_range']['minimum']===0.083333
                &&forecastV1Rows($p)===$before&&ini_get('serialize_precision')===$reader);
        }
    }finally{ini_set('serialize_precision',$oldPrecision);}
    forecastV1Schema($p);$invalidFields=$a;
    $invalidFields['semantic_prediction']['current_customer_turn_count']='1';
    $invalidFields['semantic_prediction']['prediction_digest']=ModeledConversionSemanticPrediction::digest($invalidFields['semantic_prediction']);
    forecastV1Append($p,$invalidFields);$r=forecastV1Read($p);
    $check('digest-valid invalid fields get a fixed field category without numeric confirmation',
        $r['saved']['prospective_estimates']===0&&$r['window']['prediction_contract_reason_counts']['fields']===1
        &&$r['window']['prediction_contract_reason_counts']['digest']===0);
    forecastV1Schema($p);$bad=$a;$bad['semantic_prediction']['estimated_probability']=0.9;forecastV1Append($p,$bad);
    $r=forecastV1Read($p);$check('tampered saved probability is not confirmed by metadata alone',
        $r['saved']['valid_v1_versions']===0&&$r['window']['invalid_blocks']===1
        &&$r['window']['prediction_contract_reason_counts']['digest']===1
        &&$r['window']['prediction_contract_reason_counts']['fields']===0);
    forecastV1Schema($p);$bad=$a;$bad['semantic_prediction']['subject']['customer_key']='99';
    $bad['semantic_prediction']['prediction_digest']=ModeledConversionSemanticPrediction::digest($bad['semantic_prediction']);forecastV1Append($p,$bad);
    $r=forecastV1Read($p);$check('a correctly digested wrong subject still cannot prove a bound forecast',
        $r['saved']['valid_v1_versions']===0&&$r['window']['invalid_blocks']===1);
    foreach(['model','processor','legacy']as$kind){
        forecastV1Schema($p);$bad=$a;
        if($kind==='model')$bad['semantic_prediction']['model_sha256']=str_repeat('0',64);
        elseif($kind==='processor')$bad['processing']['source_sha256']=str_repeat('0',64);
        else unset($bad['semantic_prediction'],$bad['prediction_followup']);
        $bad['profile']['arbitrary_text']=str_repeat('PRIVATE_UNRELATED_PROFILE_MUST_NOT_BE_COPIED',3000);
        forecastV1Append($p,$bad);$r=forecastV1Read($p);
        $check('old namespace is metadata-only and never copied '.$kind,
            $r['saved']['valid_v1_versions']===0&&$r['window']['other_namespace_rows']===1&&$r['window']['profile_rows_read']===0);
    }
    forecastV1Schema($p);$big=$a;$big['semantic_prediction']['unexpected']=str_repeat('x',60000);forecastV1Append($p,$big);
    $r=forecastV1Read($p);$check('oversize current block is omitted before any payload copy',
        $r['window']['oversize_rows']===1&&$r['window']['profile_rows_read']===0&&$r['saved']['valid_v1_versions']===0);
    forecastV1Schema($p);forecastV1Append($p,'{invalid');$r=forecastV1Read($p);
    $check('malformed JSON cannot cause a query error or fabricated forecast',$r['available']&&$r['window']['other_namespace_rows']===1);

    forecastV1Schema($p);
    for($i=0;$i<8;$i++)forecastV1Append($p,forecastV1Packet((string)(100+$i),(string)(200+$i)));
    $r=forecastV1Read($p);$check('the read copies at most four packets within original shared bounds',
        $r['window']['metadata_rows']===8&&$r['window']['profile_rows_read']===4&&$r['window']['row_limit_omissions']===4
        &&$r['window']['selected_document_bytes']<=49152&&$r['saved']['valid_v1_versions']===4);
    forecastV1Schema($p);forecastV1Append($p,$a);
    for($i=0;$i<25;$i++){$old=$a;$old['processing']['source_sha256']=str_repeat('0',64);forecastV1Append($p,$old);}
    $r=forecastV1Read($p);$check('a finite newest suffix never searches history to manufacture a positive',
        $r['window']['metadata_rows']===20&&$r['window']['metadata_limit_reached']&&$r['window']['profile_rows_read']===0
        &&$r['confirmation']==='no_v1_confirmation_in_checked_suffix');

    forecastV1Schema($p);$prior=forecastV1Packet('7','9',[['text'=>'Подберите тур в Турцию на 7 ночей.']],'telegram',1000,1100);
    $current=forecastV1Packet('7','9',[['text'=>'Подберите тур в Турцию на 7 ночей.'],['text'=>'Оплатили тур.']],'telegram',2000,2100,['2'=>1190]);
    $current['prediction_followup']=ModeledConversionPredictionFollowup::compare($current['semantic_prediction'],$prior['semantic_prediction']);
    forecastV1Append($p,$prior);forecastV1Append($p,$current);$before=forecastV1Rows($p);$r=forecastV1Read($p);
    $check('parallel comparison reads a persisted strictly future payment-evidence result',
        $r['saved']['status_counts']['known_outcome']===1&&$r['saved']['prospective_estimates']===0
        &&$r['saved']['followup_status_counts']['observed']===1&&$r['saved']['later_payment_evidence_versions']===1
        &&forecastV1Rows($p)===$before);
    $current['prediction_followup']['evidence'][0]['source_version_no']=2;
    forecastV1Schema($p);forecastV1Append($p,$current);$r=forecastV1Read($p);
    $check('an invalid saved comparison cannot be reported as payment evidence',
        $r['saved']['valid_v1_versions']===1&&$r['saved']['later_payment_evidence_versions']===0&&$r['window']['invalid_followup_blocks']===1);

    forecastV1Schema($p);forecastV1Append($p,$a);
    $before=forecastV1Rows($p);$oldClass=$p->getAttribute(PDO::ATTR_STATEMENT_CLASS);
    $p->setAttribute(PDO::ATTR_STATEMENT_CLASS,[ForecastV1ObservedStatement::class]);ForecastV1ObservedStatement::$copied=false;
    $revoked=false;
    try{forecastV1Read($p,static function():void{if(ForecastV1ObservedStatement::$copied)throw new DomainException('fixture_revoked');});}
    catch(DomainException $ignored){$revoked=true;}
    finally{$p->setAttribute(PDO::ATTR_STATEMENT_CLASS,$oldClass);}
    $check('revocation after payload copy prevents release and preserves all rows',$revoked&&ForecastV1ObservedStatement::$copied&&forecastV1Rows($p)===$before);
    $limited=false;$budget=new ModeledConversionStatusBudget();$budget->consumeBytes(65536);
    try{forecastV1Read($p,null,$budget);}catch(ModeledConversionStatusLimit $e){$limited=$e->reason==='byte_limit';}
    $check('the existing shared byte budget is enforced before a result',$limited);
    $limited=false;$budget=new ModeledConversionStatusBudget(0.0,static fn():float=>5.0);
    try{forecastV1Read($p,null,$budget);}catch(ModeledConversionStatusLimit $e){$limited=$e->reason==='time_limit';}
    $check('the existing five-second deadline is enforced before a query',$limited);
    $p->beginTransaction();try{$r=(new ModeledConversionForecastStatusReader($p,static function():void{},new ModeledConversionStatusBudget()))->read();}
    finally{$p->rollBack();}
    $check('an outer transaction returns explicit unavailable',$r['available']===false&&$r['reason']==='outer_transaction'&&$r['saved']===null);
    $p->exec('DROP TABLE modeled_conversion_profile_versions');$r=forecastV1Read($p);
    $check('missing storage remains unavailable rather than a zero forecast count',
        !$r['available']&&$r['reason']==='storage_unavailable'&&$r['saved']===null);
    forecastValueDeliveryAssertions($p,$check);
    forecastRepairAssessmentAssertions($p,$check);
    $initialApiCohort=forecastInitialApiReceiptAssertions($p,$check);
    $retainedPositive=forecastFiniteVisitEvidenceAssertions($p,$check,$initialApiCohort);
    $knownPositive=forecastKnownTrackerAssertions($p,$check);
    forecastProcessingFrontierAssertions($p,$check,$retainedPositive,$knownPositive);
    forecastInputDiagnosisAssertions($p,$check);
    forecastPredictiveValueAssertions($p,$check);
    forecastRetainedTrackerTickAssertions($p,$check);
    forecastPrimarySourceAssertions($p,$check);
}

function forecastInputDiagnosisAssertions(PDO $p,callable $check):void
{
    // This missing-checkpoint fixture must not inherit a cursor from earlier sender ticks.
    $p->exec('DROP TABLE IF EXISTS modeled_conversion_value_export_settings');
    $p->exec('CREATE TABLE modeled_conversion_value_export_settings(project_key VARCHAR(40) PRIMARY KEY,counter_id VARCHAR(20),goal_id VARCHAR(20))');
    $p->exec("INSERT INTO modeled_conversion_value_export_settings VALUES('anytour','98615635','678187097')");
    forecastV1Schema($p);
    $human=forecastV1Packet('701','801',[['text'=>'Подберите тур в Турцию на 7 ночей.']],'max');
    $human['coverage']['errors']=[];
    // Stored Python emits an object for its omission dictionary.
    $human['coverage']['errors']=(object)[];
    $actions=forecastV1Packet('702','802',[['text'=>'Здравствуйте!']],'telegram');
    $actions['contextual_signals']['engagement']['customer_turns']=0;
    $actions['coverage']['errors']=['action_or_unknown_message_type'=>4];
    $guided=$actions;$guided['subject']['customer_key']='703';$guided['subject']['conversation_id']='803';
    $guided['coverage']['applied_wizard_fields']=3;
    $unknown=$actions;$unknown['subject']['customer_key']='704';$unknown['subject']['conversation_id']='804';
    $unknown['contextual_signals']['engagement']['customer_turns']='PRIVATE_INVALID_COUNTER';
    $unknown['coverage']['errors']=['action_or_unknown_message_type'=>'PRIVATE_INVALID_REASON'];
    $foreign=$human;$foreign['subject']['project_key']='foreign';
    foreach([$human,$human,$actions,$guided,$unknown,$foreign]as$v)forecastV1Append($p,$v);
    $before=forecastV1Rows($p);$auth=0;
    $d=(new ModeledConversionInputDiagnosisReader($p,static function()use(&$auth):void{$auth++;},new ModeledConversionStatusBudget()))->read(1793000000);
    if(($d['available']??false)!==true)echo 'INPUT_DIAGNOSIS_SYNTHETIC '.json_encode(['result'=>$d,'synthetic_sql'=>$p->errorInfo()],JSON_THROW_ON_ERROR).PHP_EOL;
    $check('diverse input diagnosis deduplicates scopes and keeps channel shape groups',
        $d['window']['inspected_versions']===5&&$d['window']['distinct_current_scopes']===4
        &&$d['scope_counts']['max']===1&&$d['scope_counts']['telegram']===3
        &&$d['scope_counts']['with_customer_text']===1&&$d['scope_counts']['with_wizard_applications']===1);
    $check('invalid input counter and omission are unknown rather than promoted or zero',
        $d['scope_counts']['unknown_customer_coverage']===1&&$d['unknown_omission_metadata_scopes']===1
        &&$d['omission_counts']['action_or_unknown_message_type']===8);
    $shapes=array_column($d['examples'],'input_shape');
    $check('anonymous examples distinguish real saved human guided and unavailable input shapes',
        in_array('customer_text',$shapes,true)&&in_array('wizard_without_customer_text',$shapes,true)
        &&in_array('without_customer_text_or_applied_wizard',$shapes,true)&&in_array('unknown',$shapes,true));
    $json=json_encode($d,JSON_THROW_ON_ERROR);
    $check('input diagnosis cannot release private source text scope keys arbitrary reasons or hashes',
        !str_contains($json,'Подберите')&&!str_contains($json,'customer_key')&&!str_contains($json,'conversation_id')
        &&!str_contains($json,'PRIVATE_')&&!str_contains($json,$human['semantic_prediction']['prediction_digest']));
    $check('derived-input read checks authorization and preserves every original profile',$auth>=3&&forecastV1Rows($p)===$before);
    $check('missing checkpoint fields cannot fabricate an empty processing backlog',$d['progress']['profiles_recorded_pending_revisions']===null&&$d['progress']['saved_versions_waiting_for_value_inspection']===null);
    $check('snapshot activation clock is explicitly separate from customer evidence',
        $d['scope_counts']['profiles_frozen_since_value_activation']===4
        &&$d['interpretation']['frozen_since_activation_not_new_customer_evidence']);
    $old=$human;$old['processing']['source_sha256']=str_repeat('0',64);forecastV1Append($p,$old);forecastV1Append($p,'{invalid');
    $d=(new ModeledConversionInputDiagnosisReader($p,static function():void{},new ModeledConversionStatusBudget()))->read();
    $check('invalid JSON and stale namespace are explicit metadata-only categories',
        $d['window']['invalid_json_versions']===1&&$d['window']['other_namespace_versions']===2);
    for($i=0;$i<102;$i++)forecastV1Append($p,$actions);
    $d=(new ModeledConversionInputDiagnosisReader($p,static function():void{},new ModeledConversionStatusBudget()))->read();
    $check('input diagnosis never scans beyond its finite hundred-version suffix',
        $d['window']['inspected_versions']===100&&!$d['window']['complete_within_bound']
        &&$d['window']['distinct_current_scopes']===1&&count($d['examples'])===1);
    $calls=0;$revoked=false;
    try{(new ModeledConversionInputDiagnosisReader($p,static function()use(&$calls):void{if(++$calls>=2)throw new DomainException('revoked_diagnosis');},new ModeledConversionStatusBudget()))->read();}
    catch(DomainException $ignored){$revoked=true;}
    $check('revocation during scalar diagnosis prevents all output',$revoked);
    $budget=new ModeledConversionStatusBudget();$budget->consumeBytes(65536);$limited=false;
    try{(new ModeledConversionInputDiagnosisReader($p,static function():void{},$budget))->read();}
    catch(ModeledConversionStatusLimit $e){$limited=$e->reason==='byte_limit';}
    $check('scalar diagnosis shares the original strict byte budget',$limited);
    // A large irrelevant field must not force repeated JSON interpretation of the whole cohort.
    $p->prepare("UPDATE modeled_conversion_profile_versions SET profile_json=JSON_SET(profile_json,'$.unused_private_padding',?) WHERE JSON_VALID(profile_json)")->execute([str_repeat('PRIVATE_SYNTHETIC_PADDING_',1500)]);
    $large=(new ModeledConversionInputDiagnosisReader($p,static function():void{},new ModeledConversionStatusBudget()))->read();
    $check('large saved source detail is omitted from optional diagnosis after a metadata-only byte check',
        ($large['available']??true)===false&&($large['reason']??null)==='source_detail_budget'
        &&($large['metadata_versions']??null)===100&&($large['inspected_versions']??null)===0
        &&!str_contains(json_encode($large,JSON_THROW_ON_ERROR),'PRIVATE_SYNTHETIC_PADDING'));

}


function forecastLatencyAssertions(PDO $p,callable $check):void
{
    foreach(['modeled_conversion_stream_versions','modeled_conversion_profile_versions','max_channel_conversion_outbox']as$table)$p->exec('DROP TABLE IF EXISTS '.$table);
    $mysql=$p->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql';
    $p->exec("CREATE TABLE modeled_conversion_stream_versions(id BIGINT PRIMARY KEY,project_key VARCHAR(40),source_kind VARCHAR(16),source_id BIGINT,version_no INT,observed_at_unix BIGINT,snapshot_json TEXT)");
    $p->exec("CREATE TABLE modeled_conversion_profile_versions(id BIGINT PRIMARY KEY,project_key VARCHAR(40),platform VARCHAR(16),customer_key VARCHAR(40),conversation_id BIGINT,journal_cursor BIGINT,observed_at_unix BIGINT,profile_json TEXT)");
    $p->exec("CREATE TABLE max_channel_conversion_outbox(id BIGINT PRIMARY KEY,project_key VARCHAR(40),platform VARCHAR(16),conversation_id BIGINT,counter_id VARCHAR(20),transport_target VARCHAR(64),value_contract VARCHAR(64),value_origin VARCHAR(32),value_profile_id BIGINT,event_unix BIGINT,value_rub DECIMAL(12,2),value_stage VARCHAR(32),state VARCHAR(24),client_id VARCHAR(40),upload_id VARCHAR(40),upload_status VARCHAR(32),last_error VARCHAR(64),claimed_at_unix BIGINT,created_at DATETIME)");
    $day=strtotime('2026-11-02T21:00:00Z');$now=$day+43200;
    $oldTimeZone=$mysql?$p->query('SELECT @@session.time_zone')->fetchColumn():null;
    if($mysql)$p->exec("SET time_zone='+00:00'");
    $save=static function(int $id,int $conversation,string $platform,int $persisted,int $captured,int $version=1,string $project='anytour',string $sender='customer',string $status='present')use($p,$day):void{
        $snap=['project_key'=>$project,'platform'=>$platform,'customer_key'=>(string)$conversation,'conversation_id'=>(string)$conversation,
            'source_status'=>$status,'source_created_at_unix'=>$day+$persisted,
            'payload'=>['direction'=>$sender==='customer'?'inbound':'outbound','sender_type'=>$sender,'text'=>'PRIVATE_TOURIST_TEXT_NEVER_RETURN']];
        $p->prepare("INSERT INTO modeled_conversion_stream_versions VALUES(?,?,'message',?,?,?,?)")->execute([$id,$project,$id,$version,$day+$captured,json_encode($snap,JSON_THROW_ON_ERROR)]);
    };
    $save(1,111,'max',60,120);$save(2,111,'max',200,300);$save(3,222,'max',400,450);
    $save(4,333,'telegram',600,660);$save(5,444,'max',-100,2000,2);$save(6,555,'max',-100,2000);
    $save(7,666,'max',100,2000,1,'foreign');$save(8,777,'max',100,2000,1,'anytour','manager');
    $save(9,888,'max',3000,2000);$save(10,999,'max',100,2000,1,'anytour','customer','scope_excluded');
    foreach([[1,111,'max',1,121,'predicted','substantive_dialogue'],[2,111,'max',2,900,'predicted','general_purchase_intent'],[3,333,'telegram',4,1800,'known_outcome',null]]as$row){
        [$id,$conversation,$platform,$cursor,$at,$status,$stage]=$row;
        $p->prepare("INSERT INTO modeled_conversion_profile_versions VALUES(?,'anytour',?,?,?,?,?,?)")->execute([$id,$platform,(string)$conversation,$conversation,$cursor,$day+$at,json_encode(['semantic_prediction'=>['status'=>$status,'stage'=>$stage],'private_marker'=>'PRIVATE_PROFILE_NEVER_RETURN'],JSON_THROW_ON_ERROR)]);
    }
    $insert=$p->prepare("INSERT INTO max_channel_conversion_outbox VALUES(?,'anytour',?,?,'98615635','anytour_modeled_value_v1','semantic_stage_value_25000_v1',?,?,?,?,?,?,?,?,'UPLOADED','',?,?)");
    foreach([[1,'max',111,null,1,121,1250,'interest','accepted','88000001','PRIVATE_RECEIPT_ONE',130,122],
        [2,'max',111,null,2,900,7083,'purchase_intent','accepted','88000001','PRIVATE_RECEIPT_TWO',1205,901],
        [3,'telegram',333,null,3,1800,25000,'payment_report','pending',null,null,null,1801],
        [4,'max',111,'owner_day_review_v1',2,900,4167,'offer_discussion','accepted','88000001','PRIVATE_HISTORY_RECEIPT',1300,902]]as$row){
        [$id,$platform,$conversation,$origin,$profile,$event,$value,$stage,$state,$identity,$upload,$claimed,$queued]=$row;
        $insert->execute([$id,$platform,$conversation,$origin,$profile,$day+$event,$value,$stage,$state,$identity,$upload,$claimed===null?null:$day+$claimed,gmdate('Y-m-d H:i:s',$day+$queued)]);
    }
    $authorizationChecks=0;
    $read=static function()use($p,$now,&$authorizationChecks):array{
        return(new ModeledConversionLatencyReader($p,static function()use(&$authorizationChecks):void{$authorizationChecks++;},new ModeledConversionStatusBudget()))->read($now);
    };
    $before=json_encode($p->query('SELECT * FROM max_channel_conversion_outbox ORDER BY id')->fetchAll(PDO::FETCH_ASSOC),JSON_THROW_ON_ERROR);
    foreach([[-1,'2026-11-02',0],[0,'2026-11-03',0],[120,'2026-11-03',1]]as[$offset,$date,$count]){
        $calendar=(new ModeledConversionLatencyReader($p,static function():void{},new ModeledConversionStatusBudget()))->read($day+$offset);
        $check('latency uses the Moscow day and exact capture cutoff at offset '.$offset,
            ($calendar['available']??false)&&$calendar['date']===$date&&$calendar['timezone']==='Europe/Moscow'
            &&$calendar['messages']['persisted_today']===$count);
    }
    $r=$read();
    $check('today latency separates first messages edits late history and invalid clocks',
        $r['available']&&$r['date']==='2026-11-03'&&$r['messages']['first_customer_message_rows']===6
        &&$r['messages']['persisted_today']===4&&$r['messages']['conversations']===3
        &&$r['messages']['older_source_first_captured_today']===1&&$r['messages']['invalid_clock_rows']===1);
    $check('today latency resolves the first later profile of the same exact scope',
        $r['delays']['capture_to_profile']['observations']===2
        &&$r['delays']['capture_to_profile']['minimum_seconds']===600.0
        &&$r['delays']['capture_to_profile']['maximum_seconds']===1140.0
        &&$r['profile_sample']['waiting_for_profile']===1&&$r['by_platform']['max']['conversations']===2
        &&$r['profile_sample']['recorded_prediction_status']['predicted']===1&&$r['profile_sample']['scopes_inspected']===3);
    $at=static fn(int $offset):string=>gmdate('Y-m-d\\TH:i:s\\Z',$day+$offset);
    $check('timing examples retain each selected scope clock status and stage after bounded projection',
        $r['examples']===[
            ['platform'=>'max','persisted_at'=>$at(400),'captured_at'=>$at(450),'profile_saved_at'=>null,
                'capture_delay_seconds'=>50,'profile_queue_delay_seconds'=>null,'total_profile_delay_seconds'=>null,
                'recorded_prediction_status'=>'unknown','recorded_prediction_stage'=>null],
            ['platform'=>'telegram','persisted_at'=>$at(600),'captured_at'=>$at(660),'profile_saved_at'=>$at(1800),
                'capture_delay_seconds'=>60,'profile_queue_delay_seconds'=>1140,'total_profile_delay_seconds'=>1200,
                'recorded_prediction_status'=>'known_outcome','recorded_prediction_stage'=>null],
            ['platform'=>'max','persisted_at'=>$at(200),'captured_at'=>$at(300),'profile_saved_at'=>$at(900),
                'capture_delay_seconds'=>100,'profile_queue_delay_seconds'=>600,'total_profile_delay_seconds'=>700,
                'recorded_prediction_status'=>'predicted','recorded_prediction_stage'=>'general_purchase_intent']]);
    $check('today live value latency excludes historical review and keeps receipt distinct from processing',
        $r['live_value']['events']===3&&$r['live_value']['value_rub']===33333
        &&$r['live_value']['api_accepted_events']===2&&$r['live_value']['api_accepted_value_rub']===8333
        &&$r['live_value']['unresolved_identity_events']===1&&$r['live_value']['processed_events']===0);
    $check('existing claim tick precision measures same and later intervals without fabricating an API response clock',
        $r['live_value']['accepted_upload_claim_ticks']['same_five_minute_interval']===1
        &&$r['live_value']['accepted_upload_claim_ticks']['later_interval']===1
        &&$r['live_value']['accepted_upload_claim_ticks']['maximum_intervals']===1.0
        &&$r['live_value']['profile_to_queue_seconds']['maximum_seconds']===1.0);
    $json=json_encode($r,JSON_THROW_ON_ERROR);
    $check('today timing output contains no source text profile text native identity or provider IDs',
        !str_contains($json,'PRIVATE_')&&!str_contains($json,'88000001')&&!str_contains($json,'customer_key')&&!str_contains($json,'conversation_id'));
    $read();$check('repeated latency inspection never mutates delivery rows',
        $before===json_encode($p->query('SELECT * FROM max_channel_conversion_outbox ORDER BY id')->fetchAll(PDO::FETCH_ASSOC),JSON_THROW_ON_ERROR));
    $revoked=false;$calls=0;
    try{(new ModeledConversionLatencyReader($p,static function()use(&$calls):void{if(++$calls>=4)throw new DomainException();},new ModeledConversionStatusBudget()))->read($now);}
    catch(DomainException $ignored){$revoked=true;}
    $check('latency reads cannot expose output after authorization is revoked',$revoked);
    for($i=11;$i<=2015;$i++)$save($i,777,'max',100,2000,1,'anytour','manager');
    $r=$read();$check('latency metadata limit is explicit and never labels a truncated suffix as complete',
        !$r['window']['complete_within_bound']&&$r['window']['inspected_message_versions']===ModeledConversionLatencyReader::JOURNAL_LIMIT);
    // Production-like human-message volume, many old profile versions and more
    // distinct scopes than the explicit sample cap. All source text stays unused.
    for($i=0;$i<2005;$i++)$save(3000+$i,4000+($i%128),'max',2000,2050);
    $profileInsert=$p->prepare("INSERT INTO modeled_conversion_profile_versions VALUES(?,'anytour','max',?,?,?,?,?)");
    for($i=0;$i<128;$i++){
        $scope=4000+$i;
        for($n=0;$n<21;$n++){
            $profileInsert->execute([10000+$i*21+$n,(string)$scope,$scope,$n===20?5004:0,$day+($n===20?2100:2001),
                json_encode(['semantic_prediction'=>['status'=>'predicted','stage'=>'concrete_request'],'private_marker'=>'PRIVATE_LARGE_PROFILE_NEVER_RETURN'],JSON_THROW_ON_ERROR)]);
        }
    }
    $authorizationChecks=0;$r=$read();
    $check('large human-message cohort uses an explicit bounded distinct-scope profile sample',
        $r['available']&&$r['messages']['conversations']===128&&$r['profile_sample']['scopes_inspected']===32
        &&$authorizationChecks<=16
        &&!$r['profile_sample']['complete_within_captured_window']
        &&$r['profile_sample']['recorded_prediction_status']['predicted']===32
        &&$r['delays']['capture_to_profile']['maximum_seconds']===50.0
        &&count($r['examples'])===6&&$r['example_selection']==='up_to_three_slow_or_pending_then_recent_distinct_scopes'
        &&!str_contains(json_encode($r,JSON_THROW_ON_ERROR),'PRIVATE_'));
    // The preceding composed views share this same ceiling. Leave 13KiB for the
    // real 32-scope read, including repeated authorization, without fabricating rows.
    $snapshot=static function()use($p):array{
        $out=[];
        foreach(['modeled_conversion_stream_versions','modeled_conversion_profile_versions','max_channel_conversion_outbox']as$table)
            $out[$table]=hash('sha256',json_encode($p->query('SELECT * FROM '.$table.' ORDER BY id')->fetchAll(PDO::FETCH_ASSOC),JSON_THROW_ON_ERROR));
        return$out;
    };
    $beforeCompact=$snapshot();$compactBudget=new ModeledConversionStatusBudget();$compactBudget->consumeBytes(52224);$compactAuthorizations=0;
    $compact=(new ModeledConversionLatencyReader($p,static function()use($compactBudget,&$compactAuthorizations):void{
        if($compactBudget->remainingBytes()<4096)throw new ModeledConversionStatusLimit('byte_limit');
        $compactBudget->consumeBytes(128);$compactAuthorizations++;
    },$compactBudget))->read($now);
    $check('complete 32-scope timing fits the remaining shared budget with unchanged output and authorization floor',
        $compact===$r&&$compactAuthorizations>4&&$compactAuthorizations<=16
        &&$compactBudget->remainingBytes()>=4096&&$compactBudget->remainingBytes()<13312);
    $check('bounded timing projection leaves captured source profiles and value reservations unchanged',$snapshot()===$beforeCompact);
    if($mysql)$p->exec('SET time_zone='.$p->quote((string)$oldTimeZone));
}


function forecastTelegramLinkageAssertions(PDO $p,callable $check):void
{
    foreach(['advertising_visit_observations','max_channel_conversion_outbox']as$table)$p->exec('DROP TABLE IF EXISTS '.$table);
    $p->exec("CREATE TABLE advertising_visit_observations(id BIGINT PRIMARY KEY,project_key VARCHAR(40),source_key VARCHAR(80),platform VARCHAR(16),counter_id VARCHAR(20),external_user_id VARCHAR(32),identity_status VARCHAR(16),client_id VARCHAR(40),visit_at_unix BIGINT,observed_at_unix BIGINT,received_at_unix BIGINT)");
    $p->exec("CREATE INDEX linkage_fixture_identity ON advertising_visit_observations(project_key,source_key,platform,counter_id,external_user_id)");
    $p->exec("CREATE TABLE max_channel_conversion_outbox(id BIGINT PRIMARY KEY,project_key VARCHAR(40),counter_id VARCHAR(20),platform VARCHAR(16),state VARCHAR(24),transport_target VARCHAR(64),value_contract VARCHAR(64),external_user_id VARCHAR(32),event_unix BIGINT,value_rub DECIMAL(12,2),next_attempt_unix BIGINT)");
    $now=1793700000;$event=$now-600;
    $insert=$p->prepare("INSERT INTO max_channel_conversion_outbox VALUES(?,'anytour','98615635','telegram','pending',?,?,?,?,1250,?)");
    for($i=101;$i<=109;$i++)$insert->execute([$i,ModeledConversionValueContract::TARGET,ModeledConversionValueContract::VERSION,(string)$i,$event,$i===105?$now+300:0]);
    $observe=$p->prepare("INSERT INTO advertising_visit_observations VALUES(?,'anytour','tgmini','telegram',?,?,?,?,?,?,?)");
    foreach([
        [1,'98615635','102','unverified','770000001',$event-10,$event-5,$now-5],
        [2,'98615635','103','verified','',$event-10,$event-5,$now-5],
        [3,'98615635','104','verified','770000002',$event+10,$event+20,$now-5],
        [4,'98615635','105','verified','770000003',$event-10,$event-5,$now-5],
        [5,'98615635','105','verified','770000003',$event-10,$event-4,$now-4],
        [6,'98615635','106','verified','770000004',$event-10,$event-5,$now-5],
        [7,'98615635','106','verified','770000005',$event-10,$event-5,$now-5],
        [8,'98615635','107','verified','770000006',$event-10,$now+1,$now+1],
        [9,'123','108','verified','770000007',$event-10,$event-5,$now-5],
        [10,'98615635','109','verified','770000008',$event-10,$now-10,$now-5]
    ]as$row)$observe->execute($row);
    $transport=static fn():array=>['initialized'=>true,'enabled'=>true,'request_public_key'=>'PRIVATE_KEY_MUST_NOT_RETURN',
        'last_run'=>['status'=>'imported','received_at_unix'=>$now-5,'has_more'=>false,'cursor'=>'PRIVATE_CURSOR_MUST_NOT_RETURN']];
    $before=json_encode([$p->query('SELECT * FROM advertising_visit_observations ORDER BY id')->fetchAll(PDO::FETCH_ASSOC),
        $p->query('SELECT * FROM max_channel_conversion_outbox ORDER BY id')->fetchAll(PDO::FETCH_ASSOC)],JSON_THROW_ON_ERROR);
    $read=static fn():array=>(new ModeledConversionTelegramLinkageReader($p,static function():void{},new ModeledConversionStatusBudget(),$transport))->read($now);
    $r=$read();
    $check('Telegram linkage separates missing unverified empty later future unique and ambiguous observations',
        $r['available']&&$r['pending']['events']===9&&$r['pending']['value_rub']===11250
        &&$r['pending']['reason_counts']===['no_scoped_observation'=>2,'identity_unverified'=>1,'client_id_missing'=>1,
            'visit_after_signal'=>1,'candidate_not_available_at_check'=>1,'unique_eligible'=>2,'ambiguous_eligible'=>1]);
    $check('same ClientID repeats stay unique and late enrichment retains the original visit eligibility',
        $r['pending']['unique_retry_deferred']===1&&$r['pending']['reason_counts']['unique_eligible']===2);
    $json=json_encode($r,JSON_THROW_ON_ERROR);
    $check('source and transport diagnostics expose only bounded counts and sanitized readiness',
        $r['source']['rows_in_window']===9&&$r['transport']['enabled']===true
        &&$r['transport']['last_success_at']===gmdate('Y-m-d\\TH:i:s\\Z',$now-5)
        &&!str_contains($json,'PRIVATE_')&&!str_contains($json,'77000000')&&!str_contains($json,'external_user_id'));
    $read();
    $check('repeated identity diagnosis leaves observations outbox values and retry clocks byte-identical',
        $before===json_encode([$p->query('SELECT * FROM advertising_visit_observations ORDER BY id')->fetchAll(PDO::FETCH_ASSOC),
            $p->query('SELECT * FROM max_channel_conversion_outbox ORDER BY id')->fetchAll(PDO::FETCH_ASSOC)],JSON_THROW_ON_ERROR));
    $revoked=false;$calls=0;
    try{(new ModeledConversionTelegramLinkageReader($p,static function()use(&$calls):void{if(++$calls>=3)throw new DomainException('revoked_fixture');},new ModeledConversionStatusBudget(),$transport))->read($now);}
    catch(DomainException $ignored){$revoked=true;}
    $check('revocation after identity metadata selection prevents private release',$revoked);
    $budget=new ModeledConversionStatusBudget();$budget->consumeBytes(60000);
    $limited=(new ModeledConversionTelegramLinkageReader($p,static function():void{},$budget,$transport))->read($now);
    $check('optional identity diagnosis preserves endpoint headroom before source reads',$limited===['available'=>false,'reason'=>'budget_headroom']);
    for($i=200;$i<235;$i++)$insert->execute([$i,ModeledConversionValueContract::TARGET,ModeledConversionValueContract::VERSION,(string)$i,$event,0]);
    $r=$read();
    $check('pending identity window remains explicitly bounded and incomplete at its cap',
        $r['pending']['events']===32&&!$r['pending']['complete_within_bound']);
    $p->exec('DROP TABLE advertising_visit_observations');$r=$read();
    $check('missing identity storage stays explicit without changing other status contracts',$r===['available'=>false,'reason'=>'storage_unavailable']);
}


function forecastPredictiveValueAssertions(PDO $p,callable $check):void
{
    foreach(['max_channel_conversion_outbox','modeled_conversion_profile_versions']as$table)$p->exec('DROP TABLE IF EXISTS '.$table);
    $p->exec("CREATE TABLE modeled_conversion_profile_versions(id BIGINT PRIMARY KEY,project_key VARCHAR(40),platform VARCHAR(16),customer_key VARCHAR(40),conversation_id BIGINT,observed_at_unix BIGINT,profile_json TEXT)");
    $p->exec("CREATE TABLE max_channel_conversion_outbox(id BIGINT PRIMARY KEY,project_key VARCHAR(40),counter_id VARCHAR(20),transport_target VARCHAR(64),value_contract VARCHAR(64),target VARCHAR(100),platform VARCHAR(16),conversation_id BIGINT,value_profile_id BIGINT,value_stage VARCHAR(40),value_rub DECIMAL(12,2),state VARCHAR(24),upload_status VARCHAR(32),event_unix BIGINT,claimed_at_unix BIGINT,value_api_accepted_at_unix BIGINT,value_processed_at_unix BIGINT,upload_id VARCHAR(40),client_id VARCHAR(40))");
    $now=time();
    // Observe actual executions and copies only during this reader call, without matching SQL text.
    $observe=static function(callable $read)use($p):array{
        $oldClass=$p->getAttribute(PDO::ATTR_STATEMENT_CLASS);
        $p->setAttribute(PDO::ATTR_STATEMENT_CLASS,[ForecastV1ObservedStatement::class]);
        ForecastV1ObservedStatement::$observePredictive=true;
        ForecastV1ObservedStatement::$predictiveExecutions=0;ForecastV1ObservedStatement::$predictiveCopies=[];
        ForecastV1ObservedStatement::$predictiveStatements=[];
        try{return $read();}
        finally{ForecastV1ObservedStatement::$observePredictive=false;$p->setAttribute(PDO::ATTR_STATEMENT_CLASS,$oldClass);}
    };
    $transferFlags=JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR;
    $onlyNullPackets=static function(array $copies):bool{
        if(count($copies)!==1||count($copies[0])<1||count($copies[0])>17)return false;
        foreach($copies[0]as$row)if($row!==['packet'=>null])return false;
        return true;
    };
    $emptyBudget=new ModeledConversionStatusBudget();
    $empty=$observe(static fn():array=>(new ModeledConversionValueDeliveryReader($p,static function():void{},$emptyBudget))->predictiveV1($now));
    $check('an empty predictive suffix remains available with zero totals after one empty scalar copy',
        ForecastV1ObservedStatement::$predictiveExecutions===1&&ForecastV1ObservedStatement::$predictiveCopies===[[]]
        &&$empty['available']===true&&$empty['events']===0&&$empty['conversations']===0&&$empty['value_rub']===0
        &&$empty['window']['inspected_predictive_rows']===0&&$empty['window']['complete_within_goal_suffix']===true
        &&$empty['examples']===[]&&$emptyBudget->remainingBytes()>=8192);
    $profile=$p->prepare("INSERT INTO modeled_conversion_profile_versions VALUES(?,'anytour',?,'770001',?,?,?)");
    $row=$p->prepare("INSERT INTO max_channel_conversion_outbox VALUES(?,'anytour','98615635','anytour_modeled_value_v1','semantic_stage_value_25000_v1',?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
    $put=static function(int $id,string $platform,int $scope,?float $prob,?int $price,?int $full,int $delta,string $state,string $status,array $money=[],array $semantic=[])use($profile,$row,$now):void{
        $commission=$price===null?null:(int)round($price*0.07);$base=$price===null?25000:min(25000,$commission);
        $packet=['access_status'=>'allowed','subject'=>['project_key'=>'anytour','platform'=>$platform,'customer_key'=>'770001','conversation_id'=>(string)$scope],
            'semantic_prediction'=>['mode'=>'contextual_advertising_v1','status'=>'predicted','stage'=>'substantive_dialogue','estimated_probability'=>$prob,
                'hypothesis_odds_multiplier'=>1.1,'reasons'=>['expert_dialogue_continued_meaningful_engagement','PRIVATE_REASON_NEVER_RETURN']],
            'deal_value_prediction'=>['version'=>'early_deal_commission_7pct_v1','commission_rate'=>0.07,
                'deal_amount_rub'=>['point'=>$price,'low'=>$price,'high'=>$price,'basis'=>$price===null?'unknown':'customer_budget_ceiling_proxy',
                    'source_kind'=>$price===null?'unknown':'customer_budget'],
                'commission_estimate_rub'=>$commission,'full_value_base_rub'=>$base,'value_rub'=>$full],
            'private_marker'=>'PRIVATE_PROFILE_NEVER_RETURN'];
        $packet['deal_value_prediction']=array_replace($packet['deal_value_prediction'],$money);
        $packet['semantic_prediction']=array_replace($packet['semantic_prediction'],$semantic);
        $profile->execute([$id,$platform,$scope,$now-10,json_encode($packet,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)]);
        $upload=$state==='pending'?null:(string)(800000+$id);
        $row->execute([$id,ModeledConversionValueContract::predictionKey((string)$id),$platform,$scope,$id,'interest',$delta,$state,$status,$now-20,
            $upload===null?null:$now-9,$upload===null?null:$now-8,$state==='processed'?$now-7:null,$upload,'990001PRIVATE_CLIENT']);
    };
    // A legacy reservation must reduce a later predictive amount without appearing as a V1 event.
    $row->execute([1,'anytour_value_1_v1','max',51,null,'interest',1250,'processed','PROCESSED',$now-100,$now-99,$now-98,$now-97,'800001','PRIVATE_OLD_CLIENT']);
    $put(2,'max',51,0.1,200000,1400,150,'accepted','UPLOADED');
    $put(3,'max',51,0.1,null,2500,1100,'processed','PROCESSED');
    $put(4,'telegram',52,0.2,100000,1400,1400,'unmatched','LINKAGE_FAILURE');
    $put(5,'max',53,0.5,200000,25000,25000,'pending','PREPARED'); // Inconsistent frozen arithmetic stays unavailable.
    $put(6,'max',54,0.2,1000000,5000,5000,'accepted','MATCHED');
    $p->exec("INSERT INTO max_channel_conversion_outbox SELECT 9,'foreign',counter_id,transport_target,value_contract,target,platform,conversation_id,value_profile_id,value_stage,value_rub,state,upload_status,event_unix,claimed_at_unix,value_api_accepted_at_unix,value_processed_at_unix,upload_id,client_id FROM max_channel_conversion_outbox WHERE id=6");
    $read=static fn():array=>(new ModeledConversionValueDeliveryReader($p,static function():void{},new ModeledConversionStatusBudget()))->predictiveV1($now);
    $snapshot=static fn():string=>json_encode([$p->query('SELECT * FROM max_channel_conversion_outbox ORDER BY id')->fetchAll(PDO::FETCH_ASSOC),
        $p->query('SELECT * FROM modeled_conversion_profile_versions ORDER BY id')->fetchAll(PDO::FETCH_ASSOC)],JSON_THROW_ON_ERROR);
    $before=$snapshot();$r=$read();
    $check('predictive delivery separates new keys from legacy and foreign reservations',
        $r['available']&&$r['events']===5&&$r['conversations']===4&&$r['value_rub']===32650
        &&$r['api_accepted_events']===4&&$r['api_accepted_value_rub']===7650&&$r['processed_events']===1&&$r['processed_value_rub']===1100);
    $byDelta=[];foreach($r['examples']as$e)$byDelta[$e['increment_rub']]=$e;
    $a=$byDelta[150]['calculation'];$b=$byDelta[1100]['calculation'];$c=$byDelta[5000]['calculation'];
    $check('saved price commission probability and earlier reservation explain the exact positive delta',
        $a!==null&&$a['estimated_deal_rub']===200000&&$a['estimated_commission_rub']===14000.0
        &&$a['estimated_probability']===0.1&&$a['estimated_cumulative_value_rub']===1400&&$a['prior_reserved_rub']===1250);
    $check('unknown price retains explicit existing base and known high price retains commission cap',
        $b!==null&&$b['estimated_deal_rub']===null&&$b['full_value_base_rub']===25000&&$b['prior_reserved_rub']===1400
        &&$c!==null&&$c['estimated_commission_rub']===70000.0&&$c['full_value_base_rub']===25000);
    $check('legacy scalar calculations disclose their original monetary policy and cap',
        $a['monetary_policy']==='early_deal_commission_7pct_v1'&&$a['value_cap_rub']===25000
        &&$b['monetary_policy']==='early_deal_commission_7pct_v1'&&$b['value_cap_rub']===25000
        &&$c['monetary_policy']==='early_deal_commission_7pct_v1'&&$c['value_cap_rub']===25000);
    foreach([150=>[14000,1400,700,700],1100=>[25000,2500,1250,1250],5000=>[25000,5000,1250,3750]]as$delta=>[$base,$context,$baseline,$difference]){
        $comparison=$byDelta[$delta]['stage_comparison']??[];
        $check('legacy same-base comparison uses cumulative values rather than the sent increment '.$delta,
            $comparison===['version'=>'frozen_context_vs_stage_v1','status'=>'available',
                'basis'=>'same_frozen_base_existing_stage_fraction_not_export_history','reason'=>null,
                'monetary_policy'=>'early_deal_commission_7pct_v1','stage_contract'=>'semantic_stage_value_25000_v1',
                'frozen_base_rub'=>$base,'stage_fraction'=>['numerator'=>1250,'denominator'=>25000],
                'contextual_cumulative_value_rub'=>$context,'stage_cumulative_value_rub'=>$baseline,
                'contextual_minus_stage_rub'=>$difference]);
    }
    $check('inconsistent saved arithmetic cannot acquire a made up calculation',
        $r['calculation_unavailable_rows']===1&&$byDelta[25000]['calculation']===null
        &&$byDelta[25000]['calculation_unavailable_reason']==='frozen_value_unverified'
        &&array_sum($r['calculation_unavailable_reason_counts'])===1&&$r['calculation_unavailable_reason_counts']['frozen_value_unverified']===1);
    $comparison=$byDelta[25000]['stage_comparison']??[];
    $check('inconsistent arithmetic cannot become a zero comparison',
        ($comparison['status']??null)==='unavailable'&&($comparison['reason']??null)==='frozen_value_unverified'
        &&array_key_exists('contextual_minus_stage_rub',$comparison)&&$comparison['contextual_minus_stage_rub']===null
        &&$comparison['contextual_cumulative_value_rub']===null&&$comparison['stage_cumulative_value_rub']===null
        &&$comparison['monetary_policy']===null&&$comparison['frozen_base_rub']===null);
    $check('valid frozen V1 calculations have no unavailability reason',
        $byDelta[150]['calculation_unavailable_reason']===null&&$byDelta[1100]['calculation_unavailable_reason']===null
        &&$byDelta[5000]['calculation_unavailable_reason']===null);
    $check('probability calculations retain their original basis and exclude stage-only fields',
        $a['basis']==='frozen_scalar_arithmetic_checked_not_new_model_validation'
        &&!array_key_exists('stage_fraction',$a)&&!array_key_exists('stage_cumulative_value_rub',$a)
        &&$r['calculation_basis_counts']===['probability_prediction'=>4,'stage_fraction'=>0,'unavailable'=>1]);
    $check('upload receipt processing and visitor matching never assert per event visit attribution',
        $byDelta[1400]['state']==='unmatched'&&$byDelta[5000]['upload_status']==='MATCHED'
        &&$byDelta[1100]['state']==='processed'&&array_unique(array_column($r['examples'],'visit_linkage'))===['unverified']);
    $json=json_encode($r,JSON_THROW_ON_ERROR);
    $check('predictive scalar view returns no raw source text native identifiers or untrusted reasons',
        !str_contains($json,'PRIVATE_')&&!str_contains($json,'770001')&&!str_contains($json,'990001')
        &&!str_contains($json,'value_profile_id')&&!str_contains($json,'conversation_id'));
    $read();$check('repeated predictive inspection leaves every profile and outbox row byte identical',$before===$snapshot());
    $observed=$observe($read);$packets=[];
    foreach(ForecastV1ObservedStatement::$predictiveCopies[0]??[]as$copy)$packets[]=json_decode($copy['packet']??'null',true,16,JSON_THROW_ON_ERROR);
    $check('one predictive statement preserves the exact scalar result newest order and original rows',
        ForecastV1ObservedStatement::$predictiveExecutions===1&&count(ForecastV1ObservedStatement::$predictiveCopies)===1
        &&array_column($packets,0)===[6,5,4,3,2]&&array_map('count',$packets)===[38,38,38,38,38]
        &&$observed===$r&&$before===$snapshot());
    if($p->getAttribute(PDO::ATTR_DRIVER_NAME)==='sqlite'){
        // Probe one existing scalar in the actual generated statement; keep every binding and result unchanged.
        $statement=ForecastV1ObservedStatement::$predictiveStatements[0];$evaluations=0;
        $p->sqliteCreateFunction('forecast_scalar_probe',static function(mixed $id)use(&$evaluations):mixed{
            $evaluations++;return $id;
        },1,PDO::SQLITE_DETERMINISTIC);
        $probeSql=str_replace('JSON_ARRAY(o.id,','JSON_ARRAY(forecast_scalar_probe(o.id),',$statement['sql'],$instrumented);
        $probe=$p->prepare($probeSql);
        foreach($statement['bindings']as$parameter=>[$value,$type])$probe->bindValue($parameter,$value,$type);
        $probe->execute();$probeRows=$probe->fetchAll(PDO::FETCH_ASSOC);$probe->closeCursor();
        $check('SQLite materializes each predictive packet once while returning identical scalar bytes',
            $instrumented===1&&$evaluations===5&&$probeRows===ForecastV1ObservedStatement::$predictiveCopies[0]
            &&$before===$snapshot());
    }
    $calls=0;$revoked=false;
    try{(new ModeledConversionValueDeliveryReader($p,static function()use(&$calls):void{if(++$calls>=2)throw new DomainException('revoked');},new ModeledConversionStatusBudget()))->predictiveV1($now);}
    catch(DomainException $ignored){$revoked=true;}
    $check('authorization revoked after the scalar read prevents predictive release',$revoked);
    $copyRevoked=false;
    try{$observe(static fn():array=>(new ModeledConversionValueDeliveryReader($p,static function():void{
        if(ForecastV1ObservedStatement::$predictiveCopies!==[])throw new DomainException('revoked_after_predictive_copy');
    },new ModeledConversionStatusBudget()))->predictiveV1($now));}
    catch(DomainException $ignored){$copyRevoked=true;}
    $copied=ForecastV1ObservedStatement::$predictiveCopies;
    $check('revocation after the actual predictive packet copy prevents release and leaves saved rows unchanged',
        $copyRevoked&&ForecastV1ObservedStatement::$predictiveExecutions===1&&count($copied)===1&&count($copied[0])===5
        &&is_string($copied[0][0]['packet']??null)&&$before===$snapshot());
    $budget=new ModeledConversionStatusBudget();$budget->consumeBytes(50000);
    $limited=(new ModeledConversionValueDeliveryReader($p,static function():void{},$budget))->predictiveV1($now);
    $check('predictive optional read preserves the existing shared authorization headroom',$limited===['available'=>false,'reason'=>'budget_headroom']);
    // Literal V2 monetary fields keep the unchanged V1 fixture producer above independent of policy().
    $v2=['version'=>'early_deal_commission_7pct_v2'];
    $put(30,'max',130,0.5,1000000,35000,35000,'accepted','UPLOADED',$v2+['full_value_base_rub'=>70000]);
    $put(31,'telegram',131,1.0,2000000,100000,100000,'processed','PROCESSED',$v2+['full_value_base_rub'=>100000]);
    $put(32,'max',132,0.1,null,1000,1000,'pending','PREPARED',$v2+['full_value_base_rub'=>10000]);
    $newBefore=$snapshot();$r=$read();$byDelta=[];foreach($r['examples']as$e)$byDelta[$e['increment_rub']]=$e;
    $known=$byDelta[35000]['calculation'];$capped=$byDelta[100000]['calculation'];$unknown=$byDelta[1000]['calculation'];
    $check('V2 known price uses seven percent commission and probability for an increment above 25000',
        $r['available']&&$r['events']===8&&$r['value_rub']===168650
        &&$known!==null&&$known['estimated_deal_rub']===1000000&&$known['estimated_commission_rub']===70000.0
        &&$known['estimated_probability']===0.5&&$known['full_value_base_rub']===70000
        &&$known['estimated_cumulative_value_rub']===35000&&$known['prior_reserved_rub']===0);
    $check('V2 caps a known high commission at 100000 while an unknown price keeps a distinct 10000 base',
        $capped!==null&&$capped['estimated_deal_rub']===2000000&&$capped['estimated_commission_rub']===140000.0
        &&$capped['full_value_base_rub']===100000&&$capped['estimated_cumulative_value_rub']===100000
        &&$unknown!==null&&$unknown['estimated_deal_rub']===null&&$unknown['estimated_commission_rub']===null
        &&$unknown['full_value_base_rub']===10000&&$unknown['estimated_cumulative_value_rub']===1000);
    foreach([$known,$capped,$unknown]as$calculation)$check('a valid V2 calculation names its frozen monetary policy and cap',
        $calculation['monetary_policy']==='early_deal_commission_7pct_v2'&&$calculation['value_cap_rub']===100000);
    foreach([35000=>[70000,35000,3500,31500],100000=>[100000,100000,5000,95000],1000=>[10000,1000,500,500]]as$delta=>[$base,$context,$baseline,$difference]){
        $comparison=$byDelta[$delta]['stage_comparison']??[];
        $check('V2 comparison retains the saved known provisional or capped base '.$base,
            ($comparison['status']??null)==='available'&&$comparison['reason']===null
            &&$comparison['monetary_policy']==='early_deal_commission_7pct_v2'
            &&$comparison['frozen_base_rub']===$base&&$comparison['contextual_cumulative_value_rub']===$context
            &&$comparison['stage_cumulative_value_rub']===$baseline&&$comparison['contextual_minus_stage_rub']===$difference);
    }
    $check('valid frozen V2 calculations have no unavailability reason',
        $byDelta[35000]['calculation_unavailable_reason']===null&&$byDelta[100000]['calculation_unavailable_reason']===null
        &&$byDelta[1000]['calculation_unavailable_reason']===null
        &&$known['basis']==='frozen_scalar_arithmetic_checked_not_new_model_validation'
        &&!array_key_exists('stage_fraction',$known)&&!array_key_exists('stage_cumulative_value_rub',$known)
        &&$r['calculation_basis_counts']===['probability_prediction'=>7,'stage_fraction'=>0,'unavailable'=>1]);
    $check('mixed monetary-policy inspection leaves all stored profiles and reservations unchanged',$newBefore===$snapshot());

    $put(33,'max',133,0.2,null,2000,2000,'pending','PREPARED',['full_value_base_rub'=>10000]);
    $put(34,'max',134,0.12,null,3000,3000,'pending','PREPARED',$v2);
    $put(35,'max',135,0.4,1000000,28000,28000,'pending','PREPARED',['full_value_base_rub'=>70000]);
    $put(36,'max',136,0.3,200000,4200,4200,'pending','PREPARED',['version'=>'early_deal_commission_7pct_v3']);
    $r=$read();$byDelta=[];foreach($r['examples']as$e)$byDelta[$e['increment_rub']]=$e;
    $check('wrong version-specific bases and an unknown policy retain ledger totals but no invented explanation',
        $r['events']===12&&$r['value_rub']===205850&&$r['calculation_unavailable_rows']===5);
    foreach([2000=>'price_base_unverified',3000=>'price_base_unverified',28000=>'scalar_contract_unverified',4200=>'monetary_policy_unavailable']as$delta=>$reason)
        $check('incompatible scalar monetary policy remains unexplained for '.$delta,
            isset($byDelta[$delta])&&$byDelta[$delta]['calculation']===null&&$byDelta[$delta]['calculation_unavailable_reason']===$reason
            &&$byDelta[$delta]['stage_comparison']['status']==='unavailable'&&$byDelta[$delta]['stage_comparison']['reason']===$reason
            &&$byDelta[$delta]['stage_comparison']['contextual_minus_stage_rub']===null
            &&$byDelta[$delta]['stage_comparison']['stage_cumulative_value_rub']===null);
    $put(37,'max',137,1.0,2000000,100001,100001,'pending','PREPARED',$v2+['full_value_base_rub'=>100000]);
    $r=$read();$check('a predictive increment above 100000 is rejected before it contributes to returned totals',
        $r['available']&&$r['invalid_binding_rows']===1&&$r['events']===12&&$r['value_rub']===205850);
    // Remove only this invalid fixture so the original suffix/explanation limits still count valid rows.
    $p->exec('DELETE FROM max_channel_conversion_outbox WHERE id=37');
    $p->exec('DELETE FROM modeled_conversion_profile_versions WHERE id=37');

    // The existing writer can reserve a stage fraction while the saved probability remains null.
    $put(38,'max',138,null,null,null,500,'accepted','UPLOADED',$v2+['full_value_base_rub'=>10000],['status'=>'insufficient_context']);
    // Price continuity may retain a 7000 base: at 20%, target 1400 minus the prior 700 leaves a 700 increment.
    $put(39,'max',139,0.1,100000,700,700,'accepted','UPLOADED',$v2);
    $put(40,'max',139,0.2,null,2000,700,'accepted','UPLOADED',$v2+['full_value_base_rub'=>10000]);
    $put(41,'max',141,0.1,null,1000,1000,'pending','PREPARED',$v2+['full_value_base_rub'=>10000]);
    $p->exec("UPDATE modeled_conversion_profile_versions SET profile_json=JSON_SET(profile_json,'$.subject.customer_key','770099') WHERE id=41");
    $classifiedBefore=$snapshot();$r=$read();
    $byReason=[];foreach($r['examples']as$e)if($e['calculation_unavailable_reason']!==null)$byReason[$e['calculation_unavailable_reason']][]=$e;
    $byDelta=[];foreach($r['examples']as$e)$byDelta[$e['increment_rub']]=$e;
    $stageCalculation=$byDelta[500]['calculation'];
    $check('a V2 stage-fraction row explains its amount while probability and frozen estimate remain null',
        $stageCalculation!==null&&$stageCalculation['basis']==='stage_fraction_arithmetic_checked_not_probability_prediction'
        &&$stageCalculation['estimated_probability']===null&&$stageCalculation['estimated_cumulative_value_rub']===null
        &&$stageCalculation['stage_cumulative_value_rub']===500&&$stageCalculation['stage_fraction']===['numerator'=>1250,'denominator'=>25000]
        &&$byDelta[500]['calculation_unavailable_reason']===null);
    $check('a retained priced-base increment stays accounted without a fabricated frozen-value explanation',
        count($byReason['increment_not_explained'])===1&&$byReason['increment_not_explained'][0]['increment_rub']===700
        &&$byReason['increment_not_explained'][0]['calculation']===null);
    $check('a mismatched saved-profile binding has a fixed reason without discarding a valid ledger row',
        count($byReason['profile_binding_unverified'])===1&&$byReason['profile_binding_unverified'][0]['calculation']===null
        &&$r['invalid_binding_rows']===0&&$r['events']===16&&$r['value_rub']===208750);
    $bindingComparison=$byReason['profile_binding_unverified'][0]['stage_comparison']??[];
    $check('a mismatched profile cannot provide either side of the stage comparison',
        ($bindingComparison['status']??null)==='unavailable'&&($bindingComparison['reason']??null)==='profile_binding_unverified'
        &&$bindingComparison['contextual_cumulative_value_rub']===null&&$bindingComparison['stage_cumulative_value_rub']===null
        &&$bindingComparison['contextual_minus_stage_rub']===null);
    $continuityComparison=$byReason['increment_not_explained'][0]['stage_comparison']??[];
    $check('unexplained price continuity cannot turn into an alternate export calculation',
        ($continuityComparison['status']??null)==='unavailable'&&($continuityComparison['reason']??null)==='increment_not_explained'
        &&$continuityComparison['contextual_cumulative_value_rub']===null&&$continuityComparison['stage_cumulative_value_rub']===null
        &&$continuityComparison['contextual_minus_stage_rub']===null);
    $check('fixed first-failure counts explain every unavailable calculation exactly once',
        $r['calculation_unavailable_reason_counts']===['profile_binding_unverified'=>1,'probability_prediction_unavailable'=>0,
            'monetary_policy_unavailable'=>1,'scalar_contract_unverified'=>1,'price_base_unverified'=>2,'frozen_value_unverified'=>1,'increment_not_explained'=>1]
        &&array_sum($r['calculation_unavailable_reason_counts'])===$r['calculation_unavailable_rows']&&$r['calculation_unavailable_rows']===7
        &&$r['calculation_basis_counts']===['probability_prediction'=>8,'stage_fraction'=>1,'unavailable'=>7]
        &&array_sum($r['calculation_basis_counts'])===$r['events']);
    $check('comparison categories partition every eligible event including stage-only and unavailable rows',
        $r['stage_comparison_counts']===['above_stage'=>8,'equal_stage'=>0,'below_stage'=>0,'stage_only'=>1,'unavailable'=>7]
        &&array_sum($r['stage_comparison_counts'])===$r['events']);
    $read();$check('reason inspection leaves stored profiles and reservations byte identical and omits private binding values',
        $classifiedBefore===$snapshot()&&!str_contains(json_encode($r,JSON_THROW_ON_ERROR),'770099'));
    for($i=10;$i<25;$i++)$put($i,'max',100+$i,0.1,null,2500,2500,'accepted','UPLOADED');
    $r=$read();$check('predictive examples and suffix completeness remain explicitly bounded',
        $r['window']['inspected_predictive_rows']===17&&!$r['window']['complete_within_goal_suffix']&&count($r['examples'])===16);
    $check('comparison totals cover the inspected eligible suffix beyond the displayed examples',
        $r['stage_comparison_counts']===['above_stage'=>10,'equal_stage'=>0,'below_stage'=>0,'stage_only'=>1,'unavailable'=>6]
        &&array_sum($r['stage_comparison_counts'])===$r['events']&&$r['events']>count($r['examples']));
    $p->prepare("UPDATE modeled_conversion_profile_versions SET profile_json=JSON_SET(profile_json,'$.semantic_prediction.reasons',JSON_ARRAY(?))")->execute([str_repeat('я',600)]);
    $tight=new ModeledConversionStatusBudget();$tight->consumeBytes(40000);
    $multibyteBefore=$snapshot();$tightBefore=$tight->remainingBytes();
    $bounded=$observe(static fn():array=>(new ModeledConversionValueDeliveryReader($p,static function():void{},$tight))->predictiveV1($now));
    $check('oversized multibyte scalar detail is preflighted before copying and retains authorization bytes',
        $bounded===['available'=>false,'reason'=>'budget_headroom']&&$tight->remainingBytes()>=8192);
    $copied=ForecastV1ObservedStatement::$predictiveCopies;
    $copyBytes=strlen(json_encode($copied[0]??[],$transferFlags));
    $check('oversized multibyte detail copies only bounded null packets in one statement and charges those exact bytes',
        ForecastV1ObservedStatement::$predictiveExecutions===1&&$onlyNullPackets($copied)
        &&$tightBefore-$tight->remainingBytes()===$copyBytes&&$copyBytes<=512&&$multibyteBefore===$snapshot());
    $postAuthBudget=new ModeledConversionStatusBudget();$authSpent=false;
    $postAuth=$observe(static function()use($p,$postAuthBudget,&$authSpent,$now):array{
        return (new ModeledConversionValueDeliveryReader($p,static function()use($postAuthBudget,&$authSpent):void{
            if(!$authSpent){$postAuthBudget->consumeBytes(40000);$authSpent=true;}
        },$postAuthBudget))->predictiveV1($now);
    });
    $copied=ForecastV1ObservedStatement::$predictiveCopies;
    $copyBytes=strlen(json_encode($copied[0]??[],$transferFlags));
    $check('the scalar transfer gate uses the remaining budget after authorization consumes bytes',
        $authSpent&&$postAuth===['available'=>false,'reason'=>'budget_headroom']
        &&ForecastV1ObservedStatement::$predictiveExecutions===1&&$onlyNullPackets($copied)
        &&65536-$postAuthBudget->remainingBytes()===40000+$copyBytes&&$postAuthBudget->remainingBytes()>=8192
        &&$multibyteBefore===$snapshot());

    // Each synthetic stage case starts after the unchanged suffix/budget fixtures above.
    $stageReadOnly=true;
    $stageRead=static function(array $fixture=[])use($p,$put,$row,$now,$read,$snapshot,&$stageReadOnly):array{
        $p->exec('DELETE FROM max_channel_conversion_outbox');
        $p->exec('DELETE FROM modeled_conversion_profile_versions');
        if(array_key_exists('prior',$fixture))$row->execute([1,'anytour_value_1_v1','max',150,null,'interest',$fixture['prior'],
            'accepted','UPLOADED',$now-100,$now-99,$now-98,null,'800001','PRIVATE_PRIOR_CLIENT']);
        $money=array_replace(['version'=>'early_deal_commission_7pct_v2','full_value_base_rub'=>10000],$fixture['money']??[]);
        $semantic=array_replace(['status'=>'insufficient_context','stage'=>null],$fixture['semantic']??[]);
        $put(50,'max',150,null,$fixture['price']??null,null,(int)($fixture['delta']??500),'accepted','UPLOADED',$money,$semantic);
        $p->prepare('UPDATE max_channel_conversion_outbox SET value_stage=?,value_rub=? WHERE id=50')
            ->execute([$fixture['stage']??'interest',$fixture['delta']??500]);
        $before=$snapshot();$result=$read();$stageReadOnly=$stageReadOnly&&$before===$snapshot();return $result;
    };
    $validStages=[
        'V1 unknown interest'=>[['money'=>['version'=>'early_deal_commission_7pct_v1','full_value_base_rub'=>25000],'delta'=>1250],25000,1250,1250,25000,null],
        'V2 unknown interest'=>[[],10000,500,1250,100000,null],
        'V2 known price below provisional base'=>[['price'=>100000,'money'=>['full_value_base_rub'=>7000],'delta'=>350],7000,350,1250,100000,7000.0],
        'V2 known commission capped at 100000'=>[['price'=>2000000,'money'=>['full_value_base_rub'=>100000],'delta'=>5000],100000,5000,1250,100000,140000.0],
        'V2 prior reservation'=>[['prior'=>200,'delta'=>300],10000,500,1250,100000,null],
        'V2 purchase intent rounding'=>[['stage'=>'purchase_intent','delta'=>3333],10000,3333,8333,100000,null],
        'V2 agency choice'=>[['stage'=>'agency_choice','delta'=>5000],10000,5000,12500,100000,null],
    ];
    foreach($validStages as$name=>[$fixture,$base,$cumulative,$numerator,$cap,$commission]){
        $r=$stageRead($fixture);$e=$r['examples'][0]??[];$c=$e['calculation']??null;
        $check('stage arithmetic explains '.$name.' without inventing a probability or model estimate',
            $r['available']&&$r['events']===1&&$r['value_rub']===($fixture['delta']??500)
            &&$r['calculation_basis_counts']===['probability_prediction'=>0,'stage_fraction'=>1,'unavailable'=>0]
            &&$r['calculation_unavailable_rows']===0&&array_sum($r['calculation_unavailable_reason_counts'])===0
            &&$c!==null&&$c['basis']==='stage_fraction_arithmetic_checked_not_probability_prediction'
            &&$c['estimated_probability']===null&&$c['estimated_cumulative_value_rub']===null
            &&$c['stage_fraction']===['numerator'=>$numerator,'denominator'=>25000]&&$c['stage_cumulative_value_rub']===$cumulative
            &&$c['full_value_base_rub']===$base&&$c['value_cap_rub']===$cap
            &&$c['monetary_policy']===($fixture['money']['version']??'early_deal_commission_7pct_v2')
            &&$c['estimated_deal_rub']===($fixture['price']??null)&&$c['estimated_commission_rub']===$commission
            &&$c['prior_reserved_rub']===($fixture['prior']??0)&&$e['calculation_unavailable_reason']===null
            &&!array_key_exists('odds_multiplier',$c)&&!array_key_exists('expert_factors',$c)
            &&$e['visit_linkage']==='unverified'&&$r['provider_requests']===0
            &&!str_contains(json_encode($r,JSON_THROW_ON_ERROR),'PRIVATE_'));
        $comparison=$e['stage_comparison']??[];
        $check('stage-only '.$name.' exposes its baseline without inventing a contextual estimate or zero difference',
            ($comparison['status']??null)==='stage_only'&&($comparison['reason']??null)==='contextual_prediction_unavailable'
            &&$comparison['version']==='frozen_context_vs_stage_v1'
            &&$comparison['basis']==='same_frozen_base_existing_stage_fraction_not_export_history'
            &&$comparison['monetary_policy']===$c['monetary_policy']&&$comparison['frozen_base_rub']===$base
            &&$comparison['stage_fraction']===$c['stage_fraction']&&$comparison['stage_cumulative_value_rub']===$cumulative
            &&$comparison['contextual_cumulative_value_rub']===null&&$comparison['contextual_minus_stage_rub']===null
            &&$r['stage_comparison_counts']===['above_stage'=>0,'equal_stage'=>0,'below_stage'=>0,'stage_only'=>1,'unavailable'=>0]);
    }
    // Equal values are a real zero difference only when both frozen sides exist.
    // These literal cases also preserve a negative difference and the existing stage-fraction rounding.
    foreach([
        'interest without an uplift'=>[[],0.05,500,500,0],
        'context below its stage fraction'=>[[],0.04,400,500,-100],
        'specific request without an uplift'=>[['stage'=>'specific_request','semantic'=>['stage'=>'concrete_request']],0.083333,833,833,0],
        'specific request differs only by formula precision'=>[['stage'=>'specific_request','semantic'=>['stage'=>'concrete_request'],
            'price'=>2000000,'money'=>['full_value_base_rub'=>100000]],0.083333,8333,8332,1],
        'known base without an uplift'=>[['price'=>222429,'money'=>['full_value_base_rub'=>15570]],0.05,779,779,0],
    ]as$name=>[$fixture,$probability,$context,$baseline,$difference]){
        $fixture['semantic']=array_replace(['status'=>'predicted','stage'=>'substantive_dialogue','estimated_probability'=>$probability],$fixture['semantic']??[]);
        $fixture['money']=array_replace($fixture['money']??[],['value_rub'=>$context]);$fixture['delta']=$context;
        $r=$stageRead($fixture);$e=$r['examples'][0]??[];$comparison=$e['stage_comparison']??[];
        $check('same frozen base comparison preserves '.$name,
            $r['available']&&$r['events']===1&&$r['provider_requests']===0
            &&$e['calculation_unavailable_reason']===null&&($comparison['status']??null)==='available'
            &&$comparison['reason']===null&&$comparison['monetary_policy']==='early_deal_commission_7pct_v2'
            &&$comparison['contextual_cumulative_value_rub']===$context&&$comparison['stage_cumulative_value_rub']===$baseline
            &&$comparison['contextual_minus_stage_rub']===$difference&&$e['visit_linkage']==='unverified'
            &&$r['stage_comparison_counts']===['above_stage'=>$difference>0?1:0,'equal_stage'=>$difference===0?1:0,
                'below_stage'=>$difference<0?1:0,'stage_only'=>0,'unavailable'=>0]);
    }
    foreach(['different stage'=>'concrete_request','missing stage'=>null,'unknown stage'=>'unrecognised']as$name=>$savedStage){
        $r=$stageRead(['semantic'=>['status'=>'predicted','stage'=>$savedStage,'estimated_probability'=>0.05],
            'money'=>['value_rub'=>500]]);$e=$r['examples'][0]??[];$comparison=$e['stage_comparison']??[];
        $check('an unverified saved stage prevents only the new comparison '.$name,
            $r['available']&&$r['events']===1&&$e['calculation']!==null&&$e['calculation_unavailable_reason']===null
            &&($comparison['status']??null)==='unavailable'&&($comparison['reason']??null)==='prediction_stage_unverified'
            &&$comparison['contextual_cumulative_value_rub']===null&&$comparison['stage_cumulative_value_rub']===null
            &&$comparison['contextual_minus_stage_rub']===null
            &&$r['stage_comparison_counts']===['above_stage'=>0,'equal_stage'=>0,'below_stage'=>0,'stage_only'=>0,'unavailable'=>1]);
    }
    $invalidStages=[
        'wrong mode'=>['semantic'=>['mode'=>'another_mode']],
        'wrong status'=>['semantic'=>['status'=>'unavailable']],
        'present probability'=>['semantic'=>['estimated_probability'=>0.05]],
        'present frozen estimate'=>['money'=>['value_rub'=>500]],
        'wrong unknown base'=>['money'=>['full_value_base_rub'=>25000]],
        'unsupported monetary policy'=>['money'=>['version'=>'early_deal_commission_7pct_v3']],
        'wrong commission rate'=>['money'=>['commission_rate'=>0.08]],
        'mismatched increment'=>['delta'=>499],
        'fractional stored increment'=>['delta'=>500.50],
        'negative prior'=>['prior'=>-1],
        'fractional prior'=>['prior'=>200.5,'delta'=>300],
        'prior exceeding stage value'=>['prior'=>600],
        'fractional known price'=>['price'=>100000,'delta'=>350,'money'=>['full_value_base_rub'=>7000,
            'deal_amount_rub'=>['point'=>100000.5,'low'=>100000.5,'high'=>100000.5,'basis'=>'customer_budget_ceiling_proxy','source_kind'=>'customer_budget']]],
        'unsupported specific request stage'=>['stage'=>'specific_request','delta'=>833],
        'unsupported offer discussion stage'=>['stage'=>'offer_discussion','delta'=>1667],
        'payment stage belongs to another path'=>['stage'=>'payment_report','delta'=>10000],
    ];
    // Preserve JSON types via json_encode, including literal "null" versus a true JSON null on both PDO drivers.
    foreach(['null','garbage',false,0]as$bad){
        $label=json_encode($bad,JSON_THROW_ON_ERROR);
        $invalidStages['probability '.$label]=['semantic'=>['estimated_probability'=>$bad]];
        $invalidStages['frozen estimate '.$label]=['money'=>['value_rub'=>$bad]];
        $invalidStages['unknown price '.$label]=['money'=>['deal_amount_rub'=>[
            'point'=>$bad,'low'=>null,'high'=>null,'basis'=>'unknown','source_kind'=>'unknown']]];
        $invalidStages['unknown commission '.$label]=['money'=>['commission_estimate_rub'=>$bad]];
    }
    foreach($invalidStages as$name=>$fixture){
        $r=$stageRead($fixture);$e=$r['examples'][0]??[];
        $check('stage formula remains unavailable for '.$name,
            $r['available']&&$r['events']===1&&$r['calculation_basis_counts']===['probability_prediction'=>0,'stage_fraction'=>0,'unavailable'=>1]
            &&$r['calculation_unavailable_rows']===1&&array_sum($r['calculation_unavailable_reason_counts'])===1
            &&$r['calculation_unavailable_reason_counts']['probability_prediction_unavailable']===1
            &&$e['calculation']===null&&$e['calculation_unavailable_reason']==='probability_prediction_unavailable'
            &&$e['stage_comparison']['status']==='unavailable'&&$e['stage_comparison']['reason']==='probability_prediction_unavailable'
            &&$e['stage_comparison']['contextual_cumulative_value_rub']===null
            &&$e['stage_comparison']['stage_cumulative_value_rub']===null&&$e['stage_comparison']['contextual_minus_stage_rub']===null);
    }
    $r=$stageRead(['semantic'=>['status'=>'predicted']]);
    $check('a predicted status with null probability cannot enter the stage fallback',
        $r['available']&&$r['calculation_basis_counts']===['probability_prediction'=>0,'stage_fraction'=>0,'unavailable'=>1]
        &&$r['examples'][0]['calculation']===null&&$r['examples'][0]['calculation_unavailable_reason']==='scalar_contract_unverified');
    $r=$stageRead(['delta'=>-1]);
    $check('a negative ledger increment is rejected without a stage explanation',
        $r===['available'=>false,'reason'=>'invalid_saved_projection']);
    $check('all stage inspections leave the synthetic profiles and reservation rows byte identical',$stageReadOnly);

    // Seventeen already-inspected events exercise selection before the existing sixteen-example cap.
    $selectionReadOnly=true;
    $selectionRead=static function(array $overrides=[])use($p,$put,$read,$snapshot,&$selectionReadOnly):array{
        $p->exec('DELETE FROM max_channel_conversion_outbox');$p->exec('DELETE FROM modeled_conversion_profile_versions');
        for($id=100;$id<=116;$id++){
            [$probability,$full,$delta,$semantic]=$overrides[$id]??[0.05,500,500,[]];
            $put($id,'max',1000+$id,$probability,null,$full,$delta,'accepted','UPLOADED',
                ['version'=>'early_deal_commission_7pct_v2','full_value_base_rub'=>10000],$semantic);
        }
        $before=$snapshot();$result=$read();$selectionReadOnly=$selectionReadOnly&&$before===$snapshot();return $result;
    };
    $nonComparisons=[115=>[null,null,500,['status'=>'insufficient_context']],114=>[0.05,600,600,[]]];
    $r=$selectionRead($nonComparisons+[100=>[0.06,600,600,[]]]);
    $newestCases=array_map(static fn(int $n):string=>'dialogue_'.$n,range(1,16));
    $check('a difference in the seventeenth inspected row precedes the newest ordinary examples before the sixteen-example cap',
        $r['examples_selection']==='comparison_differences_first_then_newest'
        &&array_column($r['examples'],'case')===array_merge(['dialogue_17'],array_slice($newestCases,0,15))
        &&$r['examples'][0]['stage_comparison']['contextual_minus_stage_rub']===100
        &&$r['events']===17&&$r['conversations']===17&&$r['value_rub']===8700
        &&$r['stage_comparison_counts']===['above_stage'=>1,'equal_stage'=>14,'below_stage'=>0,'stage_only'=>1,'unavailable'=>1]
        &&array_sum($r['stage_comparison_counts'])===$r['events']
        &&$r['window']===['max_goal_rows'=>201,'max_predictive_rows'=>17,'inspected_predictive_rows'=>17,
            'complete_within_goal_suffix'=>false,'global_coverage_known'=>false]);

    // Exercise the real response trimmer with enough space for four actual reader examples.
    $projection=new ModeledConversionForecastStatusReader($p,static function():void{},new ModeledConversionStatusBudget());
    $method=new ReflectionMethod(ModeledConversionForecastStatusReader::class,'boundedResponse');
    $large=['value_delivery'=>['predictive_v1'=>$r],'synthetic_padding'=>''];$four=$large;
    $four['value_delivery']['predictive_v1']['examples']=array_slice($r['examples'],0,4);
    $four['value_delivery']['predictive_v1']['examples_omitted_due_to_response_budget']=true;
    $jsonFlags=JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR;
    $large['synthetic_padding']=str_repeat('x',28000-strlen(json_encode($four,$jsonFlags))-1);
    $bounded=$method->invoke($projection,$large);$kept=$bounded['value_delivery']['predictive_v1'];
    $summary=$r;unset($summary['examples']);$boundedSummary=$kept;
    unset($boundedSummary['examples'],$boundedSummary['examples_omitted_due_to_response_budget']);
    $check('the actual response budget retains the late difference and exact aggregates while trimming to four examples',
        strlen(json_encode($bounded,$jsonFlags))<=28000&&count($kept['examples'])===4
        &&$kept['examples']===array_slice($r['examples'],0,4)&&$kept['examples_omitted_due_to_response_budget']===true
        &&$boundedSummary===$summary&&$kept['examples'][0]['stage_comparison']['contextual_minus_stage_rub']===100);

    $mixed=$selectionRead($nonComparisons+[111=>[0.04,400,400,[]],108=>[0.09,900,900,[]],
        103=>[0.03,300,300,[]],100=>[0.06,600,600,[]]]);
    $check('both signs have equal priority and preserve newest order rather than sorting by sign or magnitude',
        array_column($mixed['examples'],'case')===['dialogue_6','dialogue_9','dialogue_14','dialogue_17',
            'dialogue_1','dialogue_2','dialogue_3','dialogue_4','dialogue_5','dialogue_7','dialogue_8','dialogue_10',
            'dialogue_11','dialogue_12','dialogue_13','dialogue_15']
        &&array_column(array_column(array_slice($mixed['examples'],0,4),'stage_comparison'),'contextual_minus_stage_rub')===[-100,400,-200,100]
        &&$mixed['stage_comparison_counts']===['above_stage'=>2,'equal_stage'=>11,'below_stage'=>2,'stage_only'=>1,'unavailable'=>1]);
    $equal=$selectionRead($nonComparisons);
    $check('without differences the newest order is unchanged and null comparisons are not promoted as nonzero',
        array_column($equal['examples'],'case')===$newestCases
        &&array_column(array_column(array_slice($equal['examples'],0,3),'stage_comparison'),'status')===['available','stage_only','unavailable']
        &&array_column(array_column(array_slice($equal['examples'],0,3),'stage_comparison'),'contextual_minus_stage_rub')===[0,null,null]
        &&$equal['stage_comparison_counts']===['above_stage'=>0,'equal_stage'=>15,'below_stage'=>0,'stage_only'=>1,'unavailable'=>1]);
    $json=json_encode([$r,$kept,$mixed,$equal],JSON_THROW_ON_ERROR);
    $check('prioritized examples retain the existing privacy provider and immutable-row boundaries',
        $selectionReadOnly&&$r['provider_requests']===0&&$mixed['provider_requests']===0&&$equal['provider_requests']===0
        &&!str_contains($json,'PRIVATE_')&&!str_contains($json,'770001')&&!str_contains($json,'990001')
        &&!str_contains($json,'value_profile_id')&&!str_contains($json,'conversation_id'));
}


/** Synthetic repair metadata, read through the existing private delivery reader without journal/model reads. */
function forecastRepairAssessmentAssertions(PDO $p,callable $check):void
{
    foreach(['max_channel_conversion_outbox','modeled_conversion_profile_versions']as$table)$p->exec('DROP TABLE IF EXISTS '.$table);
    $p->exec("CREATE TABLE modeled_conversion_profile_versions(id BIGINT PRIMARY KEY,project_key VARCHAR(40),platform VARCHAR(16),customer_key VARCHAR(40),conversation_id BIGINT,observed_at_unix BIGINT,assessment_namespace CHAR(64),assessment_kind VARCHAR(8),profile_json TEXT)");
    $p->exec("CREATE TABLE max_channel_conversion_outbox(id BIGINT PRIMARY KEY,project_key VARCHAR(40),platform VARCHAR(16),conversation_id BIGINT,counter_id VARCHAR(20),transport_target VARCHAR(64),value_contract VARCHAR(64),target VARCHAR(100),value_profile_id BIGINT,value_rub DECIMAL(12,2),state VARCHAR(24),upload_status VARCHAR(32),upload_id VARCHAR(40),external_user_id VARCHAR(20),event_unix BIGINT,value_stage VARCHAR(40),last_error TEXT,value_ready_at_unix BIGINT,value_api_accepted_at_unix BIGINT,value_processed_at_unix BIGINT)");
    $now=time();$namespace=ModeledConversionProfiles::assessmentNamespace();
    $profile=$p->prepare("INSERT INTO modeled_conversion_profile_versions VALUES(?,'anytour',?,?,?,?,?,?,'PRIVATE_PROFILE_BODY_MUST_NOT_BE_READ')");
    $append=static function(int $id,string $kind='repair',?string $generation=null,string $platform='telegram')use($profile,$namespace,$now):void{
        $profile->execute([$id,$platform,(string)(700000+$id),900000+$id,$now-40,$generation??$namespace,$kind]);
    };
    $insert=$p->prepare("INSERT INTO max_channel_conversion_outbox VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
    $enqueue=static function(int $id,array $change=[])use($insert,$now):void{
        $v=array_replace(['id'=>$id,'project_key'=>'anytour','platform'=>'telegram','conversation_id'=>900000+$id,'counter_id'=>'98615635',
            'transport_target'=>ModeledConversionValueContract::TARGET,'value_contract'=>ModeledConversionValueContract::VERSION,
            'target'=>ModeledConversionValueContract::predictionKey((string)$id),'value_profile_id'=>$id,'value_rub'=>500,
            'state'=>'ready','upload_status'=>null,'upload_id'=>null,'external_user_id'=>(string)(710000+$id),'event_unix'=>$now-30,
            'value_stage'=>'specific_request','last_error'=>'','value_ready_at_unix'=>$now-20,
            'value_api_accepted_at_unix'=>null,'value_processed_at_unix'=>null],$change);
        $insert->execute(array_values($v));
    };
    $append(1,'repair',null,'max');$enqueue(1,['platform'=>'max','value_rub'=>1250,'state'=>'processed','upload_status'=>'PROCESSED','upload_id'=>'81001',
        'value_api_accepted_at_unix'=>$now-15,'value_processed_at_unix'=>$now-10]);
    $append(2);$enqueue(2,['state'=>'pending','last_error'=>'identity_not_found','value_ready_at_unix'=>null]);
    $append(3);$enqueue(3,['value_rub'=>350,'state'=>'unknown','upload_status'=>'LINKAGE_FAILURE','upload_id'=>'81003']);
    $append(4); // No new delta is possible when earlier reserved value already covers the estimate.
    $append(5,'live');$enqueue(5,['value_rub'=>9000,'state'=>'processed','upload_status'=>'PROCESSED','upload_id'=>'ordinary_live']);
    $append(6,'legacy');$enqueue(6,['value_rub'=>777,'state'=>'unknown','upload_id'=>'old_unknown']);
    $append(7,'repair',str_repeat('0',64));$enqueue(7,['value_rub'=>7777,'state'=>'accepted','upload_id'=>'old_generation']);
    $mismatches=[
        ['counter_id'=>'999'],['target'=>'anytour_value_pred_999_v1'],['transport_target'=>'foreign_target'],
        ['value_contract'=>'foreign_contract'],['conversation_id'=>999999],['platform'=>'max'],
        ['project_key'=>'foreign'],['value_profile_id'=>6],
    ];
    foreach($mismatches as$i=>$change){$id=20+$i;$append($id);$enqueue($id,$change);}
    $snapshot=static fn():string=>json_encode([
        $p->query('SELECT * FROM modeled_conversion_profile_versions ORDER BY id')->fetchAll(PDO::FETCH_ASSOC),
        $p->query('SELECT * FROM max_channel_conversion_outbox ORDER BY id')->fetchAll(PDO::FETCH_ASSOC)],JSON_THROW_ON_ERROR);
    $pending=$p->query('SELECT * FROM max_channel_conversion_outbox WHERE id=2')->fetch(PDO::FETCH_ASSOC);
    $ref=ModeledConversionValueIdentityEvidence::reference($pending,$namespace);
    $audit=['v'=>ModeledConversionValueIdentityEvidence::VERSION,'n'=>$namespace,'c'=>true,'r'=>[[$ref,$now-5,1,null]]];
    $before=$snapshot();
    $oldClass=$p->getAttribute(PDO::ATTR_STATEMENT_CLASS);$p->setAttribute(PDO::ATTR_STATEMENT_CLASS,[ForecastV1ObservedStatement::class]);
    ForecastV1ObservedStatement::$observePredictive=true;ForecastV1ObservedStatement::$predictiveExecutions=0;
    ForecastV1ObservedStatement::$predictiveCopies=[];ForecastV1ObservedStatement::$predictiveStatements=[];
    try{$r=(new ModeledConversionValueDeliveryReader($p,static function():void{},new ModeledConversionStatusBudget()))->repairAssessments($now,$audit);}
    finally{ForecastV1ObservedStatement::$observePredictive=false;$p->setAttribute(PDO::ATTR_STATEMENT_CLASS,$oldClass);}
    $check('repair delivery counts only new increments of current finite repair profiles',
        $r['available']&&$r['reassessed_profiles']===12&&$r['profiles_without_new_increment']===9
        &&$r['new_increment_events']===3&&$r['new_increment_value_rub']===2100.0
        &&$r['by_state']['pending']===['events'=>1,'value_rub'=>500.0]
        &&$r['by_state']['unknown']===['events'=>1,'value_rub'=>350.0]
        &&$r['by_platform']['max']===['reassessed_profiles'=>1,'events'=>1,'value_rub'=>1250.0]);
    $check('repair API receipts and processed rows remain distinct from visit proof',
        $r['api_receipts']===['events'=>2,'value_rub'=>1600.0]
        &&$r['processed_upload_rows']===['events'=>1,'value_rub'=>1250.0]
        &&$r['per_event_visit_linkage']==='not_checked'&&$r['provider_requests']===0
        &&$r['interpretation']['no_new_increment_is_not_evidence_of_loss']
        &&$r['interpretation']['api_receipts_include_later_rejected_rows']);
    $cases=$r['exact_cases'];$second=$cases['rows'][1];
    $check('exact repair cases bind the saved identity probe without guessing a missing lookup',
        $cases['available']&&$cases['window']['complete_within_bound']&&count($cases['rows'])===3
        &&$cases['identity_evidence']['cohort_complete_within_bound']===true&&$cases['identity_evidence']['observed_case_count']===1
        &&$second['event_ref']===$ref&&$second['state']==='pending'&&$second['last_error']==='identity_not_found'
        &&$second['first_ready_at']===null&&$second['identity_resolution']['status']==='observed'
        &&$second['identity_resolution']['reason']===ModeledConversionValueIdentityEvidence::reason(1)
        &&$second['identity_resolution']['checked_at']===gmdate('Y-m-d\TH:i:s\Z',$now-5)
        &&$cases['rows'][0]['identity_resolution']['status']==='not_observed'
        &&$cases['rows'][0]['api_receipt_ref']===hash('sha256','modeled_value_upload_receipt_v1|81001'));
    $sql=ForecastV1ObservedStatement::$predictiveStatements[0]['sql']??'';
    $caseSql=ForecastV1ObservedStatement::$predictiveStatements[1]['sql']??'';
    $copy=json_encode(ForecastV1ObservedStatement::$predictiveCopies,JSON_THROW_ON_ERROR);$json=json_encode($r,JSON_THROW_ON_ERROR);
    $check('repair delivery retains one whole-cohort scalar SELECT and one bounded exact packet SELECT without source reads',
        ForecastV1ObservedStatement::$predictiveExecutions===2&&count(ForecastV1ObservedStatement::$predictiveCopies)===2
        &&count(ForecastV1ObservedStatement::$predictiveCopies[0])===1&&!str_contains($sql,'LIMIT')
        &&str_contains($caseSql,'LIMIT 17 OFFSET 0')&&!str_contains($sql.$caseSql,'profile_json')
        &&!str_contains($sql.$caseSql,'snapshot_json')&&!str_contains($copy,'PRIVATE_')
        &&!str_contains($json,'"700001"')&&!str_contains($json,'"710001"')&&!str_contains($json,'"900001"')&&!str_contains($json,'"81001"')
        &&!str_contains($json,'conversation_id')&&!str_contains($json,'value_profile_id')&&$before===$snapshot());
    $read=static fn(mixed $saved=null):array=>(new ModeledConversionValueDeliveryReader($p,static function():void{},new ModeledConversionStatusBudget()))->repairAssessments($now,$saved);
    $unknown=$read();
    $legacy=$audit;$legacy['n']=str_repeat('0',64);$legacy=$read($legacy);
    $check('legacy or absent identity audit is unknown rather than confirmed missing',
        !$unknown['exact_cases']['identity_evidence']['available']
        &&$unknown['exact_cases']['rows'][1]['identity_resolution']['status']==='audit_unavailable'
        &&$unknown['exact_cases']['rows'][1]['identity_resolution']['reason']===null
        &&!$legacy['exact_cases']['identity_evidence']['available']);
    $p->prepare("UPDATE max_channel_conversion_outbox SET state='processed',last_error='',value_ready_at_unix=?,value_api_accepted_at_unix=?,value_processed_at_unix=?,upload_status='PROCESSED',upload_id='81002' WHERE id=2")
        ->execute([$now-4,$now-3,$now-2]);
    $resolvedAudit=$audit;$resolvedAudit['r']=[[$ref,$now-4,7,$now-60]];$resolved=$read($resolvedAudit);
    $old=$resolved['exact_cases']['rows'][1];
    $check('the same original exact event remains visible through resolution receipt and processing',
        $old['event_ref']===$ref&&$old['state']==='processed'&&$old['increment_rub']===500.0
        &&$old['event_at']===$second['event_at']&&$old['first_ready_at']===gmdate('Y-m-d\TH:i:s\Z',$now-4)
        &&$old['api_accepted_at']===gmdate('Y-m-d\TH:i:s\Z',$now-3)&&$old['processed_at']===gmdate('Y-m-d\TH:i:s\Z',$now-2)
        &&$old['api_receipt_ref']===hash('sha256','modeled_value_upload_receipt_v1|81002')
        &&$old['identity_resolution']['reason']===ModeledConversionValueIdentityEvidence::reason(7));
    $p->exec("UPDATE max_channel_conversion_outbox SET last_error='PRIVATE_UNRECOGNIZED_ERROR' WHERE id=3");$safe=$read($resolvedAudit);
    $check('unrecognized private row error never leaves the exact repair view',
        $safe['exact_cases']['rows'][2]['last_error']===null&&$safe['exact_cases']['rows'][2]['last_error_status']==='unrecognized'
        &&!str_contains(json_encode($safe,JSON_THROW_ON_ERROR),'PRIVATE_'));
    $p->exec("UPDATE max_channel_conversion_outbox SET last_error='' WHERE id=3");
    // More than both the old delivery suffix and exact-case cap never hides the oldest original events.
    for($id=100;$id<350;$id++)$append($id);
    for($id=100;$id<120;$id++)$enqueue($id);
    $r=$read($resolvedAudit);$details=$r['exact_cases'];
    $check('whole repair cohort stays independent of the earliest sixteen exact events',
        $r['reassessed_profiles']===262&&$r['profiles_without_new_increment']===239
        &&$r['new_increment_events']===23&&$r['new_increment_value_rub']===12100.0
        &&$r['coverage']['whole_current_namespace_repair_cohort']&&count($details['rows'])===16
        &&$details['window']['inspected_events']===17&&!$details['window']['complete_within_bound']
        &&$details['window']['omitted_due_to_row_limit']&&$details['rows'][1]['event_ref']===$ref);
    $projection=(new ReflectionClass(ModeledConversionForecastStatusReader::class))->newInstanceWithoutConstructor();
    $bounded=new ReflectionMethod(ModeledConversionForecastStatusReader::class,'boundedResponse');
    $small=['value_delivery'=>['repair_assessments'=>$r,'predictive_v1'=>['examples'=>[]]]];
    $padding=max(0,27000-strlen(json_encode($small,JSON_THROW_ON_ERROR)));$small['padding']=str_repeat('x',$padding);
    $small['value_delivery']['predictive_v1']['examples']=[['padding'=>str_repeat('y',4000)]];
    $kept=$bounded->invoke($projection,$small);
    $check('response budget drops optional examples before exact repair evidence',
        strlen(json_encode($kept,JSON_THROW_ON_ERROR))<=28000
        &&$kept['value_delivery']['repair_assessments']['exact_cases']['rows']===$details['rows']
        &&$kept['value_delivery']['predictive_v1']['examples']===[]);
    // Size the fixed prefix using the runtime serializer: new scalar coverage fields
    // may grow, while exact rows remain the part this fixture intentionally removes.
    $responseBytes=static fn(array $v):int=>strlen(json_encode($v,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR));
    $withoutRows=$small;$withoutRows['padding']='';
    $withoutRows['value_delivery']['predictive_v1']['examples']=[];
    $withoutRows['value_delivery']['predictive_v1']['examples_omitted_due_to_response_budget']=true;
    $fullRows=$withoutRows;$withoutRows['value_delivery']['repair_assessments']['exact_cases']['rows']=[];
    $withoutRows['value_delivery']['repair_assessments']['exact_cases']['window']['returned_events']=0;
    $withoutRows['value_delivery']['repair_assessments']['exact_cases']['window']['complete_within_bound']=false;
    $withoutRows['value_delivery']['repair_assessments']['exact_cases']['window']['omitted_due_to_response_budget']=true;
    $withoutRows['value_delivery']['repair_assessments']['exact_cases']['identity_evidence']['observed_case_count']=0;
    $oldFixed=$withoutRows;$oldFixed['padding']=str_repeat('x',25000);
    $oldFixedBytes=$responseBytes($oldFixed);
    $rowBytes=$responseBytes($fullRows)-$responseBytes($withoutRows);
    $fixedTarget=28000-max(1,intdiv($rowBytes,2));
    $small['padding']=str_repeat('x',max(0,$fixedTarget-$responseBytes($withoutRows)));
    $withoutRows['padding']=$small['padding'];$fixedBytes=$responseBytes($withoutRows);
    $beforeBytes=$responseBytes($small);$truncated=$bounded->invoke($projection,$small);
    $afterBytes=$responseBytes($truncated);
    $truncatedCases=$truncated['value_delivery']['repair_assessments']['exact_cases'];
    $check('exact-case response truncation is explicit while whole-cohort totals remain intact'
        .' old_fixed='.$oldFixedBytes.' fixed='.$fixedBytes.' before='.$beforeBytes.' after='.$afterBytes,
        $rowBytes>0&&$fixedBytes<=28000&&$beforeBytes>28000&&$afterBytes<=28000
        &&count($truncatedCases['rows'])<16&&!$truncatedCases['window']['complete_within_bound']
        &&$truncatedCases['window']['omitted_due_to_response_budget']
        &&$truncatedCases['window']['returned_events']===count($truncatedCases['rows'])
        &&$truncatedCases['identity_evidence']['observed_case_count']===count(array_filter($truncatedCases['rows'],static fn(array $row):bool=>$row['identity_resolution']['status']==='observed'))
        &&$truncated['value_delivery']['repair_assessments']['new_increment_events']===23);
    // 59,500 ASCII bytes fit native TEXT but exceed the 64KiB copy budget minus its 8KiB release reserve.
    $p->prepare('UPDATE max_channel_conversion_outbox SET last_error=? WHERE id=1')->execute([str_repeat('PRIVATE_OVERSIZE_',3500)]);
    $oldClass=$p->getAttribute(PDO::ATTR_STATEMENT_CLASS);$p->setAttribute(PDO::ATTR_STATEMENT_CLASS,[ForecastV1ObservedStatement::class]);
    ForecastV1ObservedStatement::$observePredictive=true;ForecastV1ObservedStatement::$predictiveCopies=[];
    try{$oversize=$read($resolvedAudit);}finally{ForecastV1ObservedStatement::$observePredictive=false;$p->setAttribute(PDO::ATTR_STATEMENT_CLASS,$oldClass);}
    $onlyNull=true;foreach(ForecastV1ObservedStatement::$predictiveCopies[1]as$packet)if($packet!==['packet'=>null])$onlyNull=false;
    $check('oversized exact packet is SQL-gated before copying and does not erase the whole-cohort aggregate',
        $oversize['available']&&$oversize['new_increment_events']===23
        &&$oversize['exact_cases']===['available'=>false,'reason'=>'budget_headroom']&&$onlyNull);
    $p->exec("UPDATE max_channel_conversion_outbox SET last_error='' WHERE id=1");
    $p->exec("UPDATE max_channel_conversion_outbox SET state='untrusted_state' WHERE id=2");
    $bad=$read();$check('invalid repair state is unavailable instead of a partial zero',
        $bad===['available'=>false,'reason'=>'invalid_saved_projection']);
    $p->exec("UPDATE max_channel_conversion_outbox SET state='processed' WHERE id=2");
    $oldClass=$p->getAttribute(PDO::ATTR_STATEMENT_CLASS);$p->setAttribute(PDO::ATTR_STATEMENT_CLASS,[ForecastV1ObservedStatement::class]);
    ForecastV1ObservedStatement::$observePredictive=true;ForecastV1ObservedStatement::$predictiveExecutions=0;
    $revoked=false;
    try{(new ModeledConversionValueDeliveryReader($p,static function():void{if(ForecastV1ObservedStatement::$predictiveExecutions>=2)throw new DomainException('revoked');},new ModeledConversionStatusBudget()))->repairAssessments($now,$resolvedAudit);}
    catch(DomainException $ignored){$revoked=true;}
    finally{ForecastV1ObservedStatement::$observePredictive=false;$p->setAttribute(PDO::ATTR_STATEMENT_CLASS,$oldClass);}
    $check('revocation after exact packet copy prevents release of the private repair view',
        $revoked&&ForecastV1ObservedStatement::$predictiveExecutions===2);
    $budget=new ModeledConversionStatusBudget();$budget->consumeBytes(58000);
    $limited=(new ModeledConversionValueDeliveryReader($p,static function():void{},$budget))->repairAssessments($now);
    $check('repair aggregate preserves shared authorization byte headroom',$limited===['available'=>false,'reason'=>'budget_headroom']);
    $p->exec('DROP TABLE modeled_conversion_profile_versions');
    $p->exec('CREATE TABLE modeled_conversion_profile_versions(id BIGINT PRIMARY KEY,project_key VARCHAR(40))');
    $missing=$read();$check('missing repair migration is explicit unavailable rather than no repaired deliveries',
        $missing===['available'=>false,'reason'=>'storage_unavailable']);
}


function forecastReceiptPollEvidenceAssertions(callable $check):void
{
    $at=time();$id='99101';$good=['id'=>$id,'status'=>'UPLOADED','type'=>'BASIC'];$proofs=[];
    $cases=[
        [['http'=>0,'error'=>28,'body'=>''],1,null],
        [['http'=>503,'error'=>0,'body'=>'PRIVATE_PROVIDER_BODY'],2,null],
        [['http'=>200,'error'=>0,'body'=>'{PRIVATE_INVALID_JSON'],3,null],
        [['http'=>200,'error'=>0,'body'=>'{"uploading":null}'],4,null],
        [['http'=>200,'error'=>0,'body'=>json_encode(['uploading'=>['id'=>$id,'status'=>'PRIVATE_BAD_STATUS']],JSON_THROW_ON_ERROR)],5,null],
        [['http'=>200,'error'=>0,'body'=>json_encode(['uploading'=>['id'=>'99102','status'=>'UPLOADED']],JSON_THROW_ON_ERROR)],6,null],
        [['http'=>200,'error'=>0,'body'=>json_encode(['uploading'=>$good],JSON_THROW_ON_ERROR)],7,$good],
    ];
    foreach($cases as[$response,$stage,$expected]){
        $calls=0;$client=new MetrikaOfflineClient('98615635','fixture-only',static function(string $method,string $url,mixed $body)use(&$calls,$response,$id):array{
            $calls++;if($method!=='GET'||!str_ends_with($url,'/offline_conversions/uploading/'.$id))throw new RuntimeException('unexpected_poll_request');return $response;
        });
        $actual=$client->poll($id);$proof=$client->pollEvidence($id);
        $check('receipt poll captures only the existing response stage '.$stage,
            $actual===$expected&&$calls===1&&$proof['stage']===$stage
            &&$proof['receipt_ref']===hash('sha256','modeled_value_upload_receipt_v1|'.$id)
            &&$client->pollEvidence('99102')===null&&!str_contains(json_encode($proof,JSON_THROW_ON_ERROR),'PRIVATE_')
            &&$proof['provider_status']===($stage===7?'UPLOADED':null));
    }
    $legacy=new MetrikaOfflineClient('98615635','fixture-only',static fn()=>['http'=>'200','error'=>'0','body'=>json_encode(['uploading'=>$good],JSON_THROW_ON_ERROR)]);
    $check('poll observation never coerces string HTTP diagnostics or changes existing valid receipt behavior',
        $legacy->poll($id)===$good&&$legacy->pollEvidence($id)['http_status']===null&&$legacy->pollEvidence($id)['transport_error_code']===null);
    $calls=0;$client=new MetrikaOfflineClient('98615635','fixture-only',static function(string $method,string $url,mixed $body)use(&$calls):array{
        $calls++;$upload=basename((string)parse_url($url,PHP_URL_PATH));
        return ['http'=>200,'error'=>0,'body'=>json_encode(['uploading'=>['id'=>$upload,'status'=>'UPLOADED']],JSON_THROW_ON_ERROR)];
    });
    $client->poll('99101');$proofs[]=$client->pollEvidence('99101');
    $client->poll('99102');$proofs[]=$client->pollEvidence('99102');
    $check('receipt observation resets for every call and cannot be read under a previous upload',
        $client->pollEvidence('99101')===null&&$proofs[0]['receipt_ref']!==$proofs[1]['receipt_ref']&&$calls===2);
    $client->poll('0');$check('invalid poll input clears old evidence without a provider request',$client->pollEvidence('99102')===null&&$calls===2);
    $failure=new RuntimeException('PRIVATE_TRANSPORT_EXCEPTION');
    $throwing=new MetrikaOfflineClient('98615635','fixture-only',static function()use($failure):array{throw $failure;});
    $same=false;try{$throwing->poll($id);}catch(Throwable $seen){$same=$seen===$failure;}
    $check('poll capture preserves thrown transport behavior and never exports its message',
        $same&&$throwing->pollEvidence($id)['stage']===1&&!str_contains(json_encode($throwing->pollEvidence($id),JSON_THROW_ON_ERROR),'PRIVATE_'));
    $audit=ModeledConversionReceiptPollEvidence::begin($at);
    foreach($proofs as$proof)$audit=ModeledConversionReceiptPollEvidence::append($audit,$proof,time());
    $view=ModeledConversionReceiptPollEvidence::projection($audit,time());
    $check('two existing poll observations fit one bounded receipt and retain exact receipt hashes without another GET',
        $view['available']&&$view['observed_receipts']===2&&$view['complete_within_capture_bound']
        &&array_column($view['rows'],'provider_status')===['UPLOADED','UPLOADED']&&$calls===2
        &&strlen(json_encode($audit,JSON_THROW_ON_ERROR))<=512&&$view['provider_requests_in_reader']===0);
    $duplicate=ModeledConversionReceiptPollEvidence::append($audit,$proofs[0],time());
    $extra=$proofs[0];$extra['receipt_ref']=str_repeat('f',64);
    $overflow=ModeledConversionReceiptPollEvidence::append($audit,$extra,time());
    $check('duplicate and third poll observations cannot claim complete two-receipt coverage',
        !ModeledConversionReceiptPollEvidence::projection($duplicate,time())['complete_within_capture_bound']
        &&!ModeledConversionReceiptPollEvidence::projection($overflow,time())['complete_within_capture_bound']
        &&count($overflow['r'])===2&&$calls===2);
    $transportAudit=ModeledConversionReceiptPollEvidence::append(ModeledConversionReceiptPollEvidence::begin($at),$throwing->pollEvidence($id),time());
    $transportView=ModeledConversionReceiptPollEvidence::projection($transportAudit,time());
    $check('a real thrown poll transport failure retains null response codes without becoming a valid provider receipt',
        $transportView['available']&&$transportView['complete_within_capture_bound']
        &&$transportView['rows'][0]['stage']==='transport_failure'&&$transportView['rows'][0]['http_status']===null
        &&$transportView['rows'][0]['transport_error_code']===null&&$transportView['rows'][0]['provider_status']===null);
    $nullableFailureClient=new MetrikaOfflineClient('98615635','fixture-only',static fn()=>['http'=>0,'body'=>'']);
    $nullableFailureClient->poll($id);
    $nullableFailureAudit=ModeledConversionReceiptPollEvidence::append(ModeledConversionReceiptPollEvidence::begin($at),$nullableFailureClient->pollEvidence($id),time());
    $nullableFailure=ModeledConversionReceiptPollEvidence::projection($nullableFailureAudit,time());
    $check('legacy missing transport diagnostics can retain a failure observation without guessing HTTP or a provider status',
        $nullableFailure['available']&&$nullableFailure['rows'][0]['stage']==='http_failure'
        &&$nullableFailure['rows'][0]['http_status']===null&&$nullableFailure['rows'][0]['transport_error_code']===null
        &&$nullableFailure['rows'][0]['provider_status']===null);
    foreach([[7,503,28,5],[7,200,null,2],[3,503,0,0],[4,200,28,0],[5,null,0,0],[6,200,null,0],
        [1,200,0,0],[2,200,0,0],[2,503,28,0]]as[$stage,$http,$curl,$status]){
        $contradiction=$audit;$contradiction['r'][0][2]=$stage;$contradiction['r'][0][3]=$http;
        $contradiction['r'][0][4]=$curl;$contradiction['r'][0][5]=$status;
        $unknown=ModeledConversionReceiptPollEvidence::projection($contradiction,time());
        $check('contradictory saved poll stage and response codes remain unknown '.$stage.':'.($http??'null').':'.($curl??'null'),
            !$unknown['available']&&!array_key_exists('rows',$unknown)&&$unknown['provider_requests_in_reader']===0
            &&!str_contains(json_encode($unknown,JSON_THROW_ON_ERROR),'PROCESSED'));
    }
    $contradictoryProof=$proofs[0];$contradictoryProof['stage']=7;$contradictoryProof['http_status']=503;
    $contradictoryProof['transport_error_code']=28;$contradictoryProof['provider_status']='PROCESSED';
    $notCaptured=ModeledConversionReceiptPollEvidence::append(ModeledConversionReceiptPollEvidence::begin($at),$contradictoryProof,time());
    $unknownCapture=ModeledConversionReceiptPollEvidence::projection($notCaptured,time());
    $check('a contradictory incoming poll DTO cannot create a saved processed receipt observation',
        $unknownCapture['available']&&!$unknownCapture['complete_within_capture_bound']&&$unknownCapture['rows']===[]);
    $bad=$proofs[0];$bad['http_status']='200';$invalid=ModeledConversionReceiptPollEvidence::append(ModeledConversionReceiptPollEvidence::begin($at),$bad,time());
    $malformed=$audit;$malformed['r'][0][0]='PRIVATE_RAW_UPLOAD_ID';
    $check('malformed poll scalars and raw identifiers remain unknown instead of becoming valid pending receipts',
        !ModeledConversionReceiptPollEvidence::projection($invalid,time())['complete_within_capture_bound']
        &&!ModeledConversionReceiptPollEvidence::projection($malformed,time())['available']
        &&!str_contains(json_encode(ModeledConversionReceiptPollEvidence::projection($malformed,time()),JSON_THROW_ON_ERROR),'PRIVATE_'));
}

/** A fixed accepted cohort is read from its own immutable repair bindings, not re-polled. */
function forecastInitialApiReceiptAssertions(PDO $p,callable $check):array
{
    foreach(['max_channel_conversion_outbox','modeled_conversion_profile_versions']as$table)$p->exec('DROP TABLE IF EXISTS '.$table);
    $p->exec("CREATE TABLE modeled_conversion_profile_versions(id BIGINT PRIMARY KEY,project_key VARCHAR(40),platform VARCHAR(16),customer_key VARCHAR(40),conversation_id BIGINT,observed_at_unix BIGINT,assessment_namespace CHAR(64),assessment_kind VARCHAR(8),profile_json TEXT)");
    $p->exec("CREATE TABLE max_channel_conversion_outbox(id BIGINT PRIMARY KEY,project_key VARCHAR(40),platform VARCHAR(16),conversation_id BIGINT,counter_id VARCHAR(20),transport_target VARCHAR(64),value_contract VARCHAR(64),target VARCHAR(100),value_profile_id BIGINT,value_rub DECIMAL(12,2),state VARCHAR(24),upload_status VARCHAR(32),upload_id TEXT,external_user_id VARCHAR(20),event_unix BIGINT,value_stage VARCHAR(40),last_error TEXT,value_ready_at_unix BIGINT,value_api_accepted_at_unix BIGINT,value_processed_at_unix BIGINT)");
    $p->exec('CREATE INDEX idx_value_delivery_receipt ON max_channel_conversion_outbox(project_key,counter_id,transport_target,value_contract,value_api_accepted_at_unix)');
    $cutoff=ModeledConversionValueDeliveryReader::INITIAL_API_RECEIPT_CUTOFF;$now=ModeledConversionValueDeliveryReader::ACCEPTED_PREFIX_CUTOFF+600;$ns=ModeledConversionProfiles::assessmentNamespace();
    $profile=$p->prepare("INSERT INTO modeled_conversion_profile_versions VALUES(?,'anytour',?,?,?,?,?,'repair','PRIVATE_PROFILE_PAYLOAD_NOT_READ')");
    $event=$p->prepare("INSERT INTO max_channel_conversion_outbox VALUES(?,'anytour',? ,?,'98615635',?,?,?,?,?,?,?,? ,?,?,'specific_request','',?,?,?)");
    $add=static function(int $id,int $accepted)use($profile,$event,$cutoff,$ns):void{
        $platform=$id===16?'telegram':'max';$born=$cutoff-1000;$processed=$id<=10;
        $profile->execute([$id,$platform,(string)(731000+$id),931000+$id,$born,$ns]);
        $event->execute([$id,$platform,931000+$id,ModeledConversionValueContract::TARGET,ModeledConversionValueContract::VERSION,
            ModeledConversionValueContract::predictionKey((string)$id),$id,500,$processed?'processed':'accepted',
            $processed?'PROCESSED':'UPLOADED',(string)(811000+$id),(string)(741000+$id),$born,$accepted-1,$accepted,$processed?$accepted+1:null]);
    };
    for($id=1;$id<=16;$id++)$add($id,$cutoff-100+$id);
    $audit=ModeledConversionReceiptPollEvidence::begin($now-10);
    $audit=ModeledConversionReceiptPollEvidence::append($audit,['v'=>1,'counter_id'=>'98615635',
        'receipt_ref'=>hash('sha256','modeled_value_upload_receipt_v1|811016'),'checked_at_unix'=>$now-2,'stage'=>7,
        'http_status'=>200,'transport_error_code'=>0,'provider_status'=>'UPLOADED'],$now);
    $read=static fn(?callable $auth=null,?ModeledConversionStatusBudget $budget=null):array=>
        (new ModeledConversionValueDeliveryReader($p,$auth??static function():void{},$budget??new ModeledConversionStatusBudget()))->repairAssessments($now,null,null,null,$audit);
    $snapshot=static fn():string=>json_encode([$p->query('SELECT * FROM modeled_conversion_profile_versions ORDER BY id')->fetchAll(PDO::FETCH_ASSOC),
        $p->query('SELECT * FROM max_channel_conversion_outbox ORDER BY id')->fetchAll(PDO::FETCH_ASSOC)],JSON_THROW_ON_ERROR);
    $before=$snapshot();$old=$p->getAttribute(PDO::ATTR_STATEMENT_CLASS);$p->setAttribute(PDO::ATTR_STATEMENT_CLASS,[ForecastV1ObservedStatement::class]);
    ForecastV1ObservedStatement::$observePredictive=true;ForecastV1ObservedStatement::$predictiveStatements=[];
    try{$out=$read();}finally{ForecastV1ObservedStatement::$observePredictive=false;$p->setAttribute(PDO::ATTR_STATEMENT_CLASS,$old);}
    $cohort=$out['initial_api_receipts'];$sql=implode(' ',array_column(ForecastV1ObservedStatement::$predictiveStatements,'sql'));
    $check('the exact first API receipt cohort includes all sixteen saved bindings and separates current processing from ordinary poll coverage',
        $cohort['available']&&$cohort['complete_within_bound']&&count($cohort['rows'])===16
        &&$cohort['api_receipts']===['events'=>16,'value_rub'=>8000.0]
        &&$cohort['processed_upload_rows']===['events'=>10,'value_rub'=>5000.0]
        &&$cohort['by_state']['accepted']===6&&$cohort['current_poll_receipts_in_cohort']===1
        &&$out['receipt_poll_evidence']['rows'][0]['provider_status']==='UPLOADED'
        &&strlen(json_encode($cohort,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR))<=4096
        &&str_contains($sql,'initial_api_receipt_packets')&&str_contains($sql,'LIMIT 17 OFFSET 0')
        &&str_contains($sql,$p->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'?'FORCE INDEX (idx_value_delivery_receipt)':'INDEXED BY idx_value_delivery_receipt')
        &&($p->getAttribute(PDO::ATTR_DRIVER_NAME)!=='mysql'||str_contains($sql,'MAX_EXECUTION_TIME(250)')));
    $check('receipt cohort read returns no raw upload native identifiers or profile payload and never mutates data',
        !str_contains(json_encode($out,JSON_THROW_ON_ERROR),'"811016"')&&!str_contains(json_encode($out,JSON_THROW_ON_ERROR),'"741001"')
        &&!str_contains(json_encode($out,JSON_THROW_ON_ERROR),'PRIVATE_')&&!str_contains($sql,'profile_json')&&$snapshot()===$before);
    $prefixCutoff=ModeledConversionValueDeliveryReader::ACCEPTED_PREFIX_CUTOFF;
    $add(24,$prefixCutoff-2);$add(25,$prefixCutoff-1);$prefix18=$read()['accepted_prefix'];
    $add(26,$prefixCutoff+1);$later=$read();$frozenPrefix=$later['accepted_prefix'];
    $check('a frozen accepted prefix uses the same whole-cohort aggregate and later API uploads cannot change its known first-receipt membership',
        $prefix18['available']&&$prefix18['api_receipts_with_known_clock']===['events'=>18,'value_rub'=>9000.0]
        &&$frozenPrefix['api_receipts_with_known_clock']===$prefix18['api_receipts_with_known_clock']
        &&$frozenPrefix['by_state']===$prefix18['by_state']&&$frozenPrefix['clock_coverage']['later_known_clock_rows']===1
        &&$frozenPrefix['clock_coverage']['complete']&&$frozenPrefix['other_prefix_rows']===8
        &&array_sum(array_column($frozenPrefix['by_state'],'events'))===18
        &&$later['initial_api_receipts']['rows']===$cohort['rows']);
    $p->exec('UPDATE max_channel_conversion_outbox SET value_api_accepted_at_unix=NULL WHERE id=1');
    $clockMissing=$read()['accepted_prefix'];
    $p->prepare('UPDATE max_channel_conversion_outbox SET value_api_accepted_at_unix=? WHERE id=1')->execute([$now+1]);
    $clockFuture=$read()['accepted_prefix'];
    $p->prepare('UPDATE max_channel_conversion_outbox SET value_api_accepted_at_unix=? WHERE id=1')->execute([$cutoff-99]);
    $check('missing and future first API clocks make frozen-prefix coverage unknown rather than declaring a smaller successful prefix',
        !$clockMissing['available']&&!$clockFuture['available']
        &&$clockMissing['reason']==='first_api_clock_coverage_incomplete'&&$clockFuture['reason']==='first_api_clock_coverage_incomplete'
        &&$clockMissing['clock_coverage']['missing_or_invalid_first_api_clock_rows']===1
        &&$clockFuture['clock_coverage']['missing_or_invalid_first_api_clock_rows']===1);
    $p->exec('UPDATE max_channel_conversion_outbox SET value_processed_at_unix=NULL WHERE id=1');
    $processingClock=$read()['accepted_prefix'];
    $p->prepare('UPDATE max_channel_conversion_outbox SET value_processed_at_unix=? WHERE id=1')->execute([$cutoff-98]);
    $check('processed states and observed processing clocks remain distinct in frozen-prefix coverage',
        $processingClock['processed_upload_rows']['events']===10&&$processingClock['clock_coverage']['processed_clock_rows']===9
        &&!$processingClock['clock_coverage']['processing_clock_coverage_complete']);
    $p->exec('DELETE FROM max_channel_conversion_outbox WHERE id IN (24,25,26)');
    $p->exec('DELETE FROM modeled_conversion_profile_versions WHERE id IN (24,25,26)');
    foreach([17=>'counter_id',18=>'transport_target',19=>'value_contract',20=>'conversation_id',21=>'platform']as$id=>$field){
        $add($id,$cutoff-50);
        $value=match($field){'counter_id'=>'999','transport_target'=>ModeledConversionValueContract::TARGET.' ',
            'value_contract'=>ModeledConversionValueContract::VERSION.' ','conversation_id'=>999999,'platform'=>'telegram'};
        $p->prepare('UPDATE max_channel_conversion_outbox SET '.$field.'=? WHERE id=?')->execute([$value,$id]);
    }
    $add(22,$cutoff+1);$scope=$read()['initial_api_receipts'];
    $check('wrong counter wire contract conversation or platform and later API receipts cannot enter the pinned cohort',
        $scope['available']&&$scope['rows']===$cohort['rows']&&$scope['api_receipts']===$cohort['api_receipts']);
    $add(23,$cutoff-1);$extra=$read()['initial_api_receipts'];$p->exec('DELETE FROM max_channel_conversion_outbox WHERE id=23');$p->exec('DELETE FROM modeled_conversion_profile_versions WHERE id=23');
    $check('a seventeenth accepted binding makes the exact original cohort unknown rather than choosing a convenient sixteen',
        !$extra['available']&&$extra['reason']==='current_initial_api_cohort_unverified');
    $p->exec("UPDATE max_channel_conversion_outbox SET upload_id='811001' WHERE id=2");$duplicate=$read()['initial_api_receipts'];
    $p->exec("UPDATE max_channel_conversion_outbox SET upload_id='811002' WHERE id=2");
    $check('one receipt cannot be counted twice in the pinned original cohort',!$duplicate['available']&&$duplicate['reason']==='receipt_binding_ambiguous');
    $p->prepare('UPDATE max_channel_conversion_outbox SET upload_id=? WHERE id=1')->execute([str_repeat('PRIVATE_OVERSIZE_',3000)]);
    $packetBudget=new ModeledConversionStatusBudget();$packetBudget->consumeBytes(18000);
    $bounded=$read(null,$packetBudget)['initial_api_receipts'];$p->exec("UPDATE max_channel_conversion_outbox SET upload_id='811001' WHERE id=1");
    $check('the escaped packet bound omits oversized receipt metadata before copying it',!$bounded['available']&&$bounded['reason']==='budget_headroom');
    $revoked=false;$denied=false;$old=$p->getAttribute(PDO::ATTR_STATEMENT_CLASS);$p->setAttribute(PDO::ATTR_STATEMENT_CLASS,[ForecastV1ObservedStatement::class]);
    ForecastV1ObservedStatement::$afterInitialApi=static function()use(&$revoked):void{$revoked=true;};
    try{$read(static function()use(&$revoked):void{if($revoked)throw new DomainException('synthetic_api_receipt_revoked');});}
    catch(DomainException $e){$denied=$e->getMessage()==='synthetic_api_receipt_revoked';}
    finally{ForecastV1ObservedStatement::$afterInitialApi=null;$p->setAttribute(PDO::ATTR_STATEMENT_CLASS,$old);}
    $check('final authorization protects already copied exact receipt metadata',$denied);
    $p->exec('DROP INDEX idx_value_delivery_receipt'.($p->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'?' ON max_channel_conversion_outbox':''));
    $missing=$read()['initial_api_receipts'];
    $check('missing receipt point index remains storage unknown without an unbounded fallback',!$missing['available']&&$missing['reason']==='storage_unavailable');
    return $cohort;
}

/** A saved GET-only finite proof is bound to three current immutable rows, with no provider work in this reader. */
function forecastFiniteVisitEvidenceAssertions(PDO $p,callable $check,array $initialApiCohort=[]):array
{
    foreach(['max_channel_conversion_outbox','modeled_conversion_profile_versions']as$table)$p->exec('DROP TABLE IF EXISTS '.$table);
    $p->exec("CREATE TABLE modeled_conversion_profile_versions(id BIGINT PRIMARY KEY,project_key VARCHAR(40),platform VARCHAR(16),customer_key VARCHAR(40),conversation_id BIGINT,observed_at_unix BIGINT,assessment_namespace CHAR(64),assessment_kind VARCHAR(8),profile_json TEXT)");
    $p->exec("CREATE TABLE max_channel_conversion_outbox(id BIGINT PRIMARY KEY,project_key VARCHAR(40),platform VARCHAR(16),conversation_id BIGINT,counter_id VARCHAR(20),transport_target VARCHAR(64),value_contract VARCHAR(64),target VARCHAR(100),value_profile_id BIGINT,value_rub DECIMAL(12,2),state VARCHAR(24),upload_status VARCHAR(32),upload_id VARCHAR(40),external_user_id VARCHAR(20),event_unix BIGINT,value_stage VARCHAR(40),last_error TEXT,value_ready_at_unix BIGINT,value_api_accepted_at_unix BIGINT,value_processed_at_unix BIGINT,identity_source_key VARCHAR(80) DEFAULT '',source_signal_id BIGINT,identity_observation_id BIGINT,attempts INT DEFAULT 0,claimed_at_unix BIGINT,client_id VARCHAR(40),tracker_record_id VARCHAR(20),tracker_observed_unix BIGINT,value_receipt_basis VARCHAR(24),next_attempt_unix BIGINT DEFAULT 0,claim_token VARCHAR(32))");
    $checkpoint=ModeledConversionValueVisitEvidence::CHECKPOINT;$now=$checkpoint+600;$namespace=ModeledConversionProfiles::assessmentNamespace();
    $insertProfile=$p->prepare("INSERT INTO modeled_conversion_profile_versions VALUES(?,'anytour','max',?,?,?,?,'repair','PRIVATE_NATIVE_PROFILE_MUST_NOT_BE_READ')");
    $insertEvent=$p->prepare("INSERT INTO max_channel_conversion_outbox(id,project_key,platform,conversation_id,counter_id,transport_target,value_contract,target,value_profile_id,value_rub,state,upload_status,upload_id,external_user_id,event_unix,value_stage,last_error,value_ready_at_unix,value_api_accepted_at_unix,value_processed_at_unix) VALUES(?,'anytour','max',?,'98615635',?,?,?,?,?,'pending',NULL,NULL,?,?,'specific_request','identity_not_found',NULL,NULL,NULL)");
    for($id=1;$id<=4;$id++){
        $born=$id===4?$checkpoint+1:$checkpoint-120;
        $insertProfile->execute([$id,(string)(730000+$id),930000+$id,$born,$namespace]);
        $insertEvent->execute([$id,930000+$id,ModeledConversionValueContract::TARGET,ModeledConversionValueContract::VERSION,
            ModeledConversionValueContract::predictionKey((string)$id),$id,500,(string)(740000+$id),$born]);
    }
    $ledger=$p->query('SELECT * FROM max_channel_conversion_outbox WHERE id<=3 ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
    $frame=['v'=>ModeledConversionValueVisitEvidence::VERSION,'n'=>$namespace,'b'=>$checkpoint,'r'=>[]];
    foreach($ledger as$row)$frame['r'][]=[ModeledConversionValueIdentityEvidence::reference($row,$namespace),(string)$row['id'],
        0,null,null,0,null,null,null,null,0,null,0,null,0,(int)$row['event_unix']];
    $read=static fn(mixed $saved,?callable $auth=null,?ModeledConversionStatusBudget $budget=null,mixed $recovery=null,mixed $pair=null,int $lastTick=0,mixed $scope=null):array=>
        (new ModeledConversionValueDeliveryReader($p,$auth??static function():void{},$budget??new ModeledConversionStatusBudget()))->repairAssessments($now,null,$saved,$recovery,null,$pair,$lastTick,$scope);
    $snapshot=static fn():string=>json_encode([
        $p->query('SELECT * FROM modeled_conversion_profile_versions ORDER BY id')->fetchAll(PDO::FETCH_ASSOC),
        $p->query('SELECT * FROM max_channel_conversion_outbox ORDER BY id')->fetchAll(PDO::FETCH_ASSOC)],JSON_THROW_ON_ERROR);
    $before=$snapshot();$oldClass=$p->getAttribute(PDO::ATTR_STATEMENT_CLASS);
    $p->setAttribute(PDO::ATTR_STATEMENT_CLASS,[ForecastV1ObservedStatement::class]);
    ForecastV1ObservedStatement::$observePredictive=true;ForecastV1ObservedStatement::$predictiveExecutions=0;
    ForecastV1ObservedStatement::$predictiveCopies=[];ForecastV1ObservedStatement::$predictiveStatements=[];
    try{$r=$read($frame);}finally{ForecastV1ObservedStatement::$observePredictive=false;$p->setAttribute(PDO::ATTR_STATEMENT_CLASS,$oldClass);}
    $proof=$r['finite_visit_evidence'];$statements=ForecastV1ObservedStatement::$predictiveStatements;
    $scalarCopyBytes=strlen(json_encode(ForecastV1ObservedStatement::$predictiveCopies[0],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR));
    $check('finite proof reads exactly three bound original rows before optional exact cases',
        $proof['available']&&count($proof['rows'])===3&&!$proof['complete']
        &&array_column($proof['rows'],'event_ref')===array_column($frame['r'],0)
        &&array_column($proof['rows'],'financial_state')===['pending','pending','pending']
        &&$proof['provider_requests_in_reader']===0&&ForecastV1ObservedStatement::$predictiveExecutions===3
        &&str_contains($statements[1]['sql'],'LIMIT 4 OFFSET 0')&&str_contains($statements[2]['sql'],'LIMIT 17 OFFSET 0'));
    $check('original finite rows expose only their exact saved hold and schedule without a fresh visit assessment',
        $proof['rows'][0]['current_hold_reason']==='identity_not_found'&&$proof['rows'][0]['current_hold_reason_known']
        &&$proof['rows'][0]['next_ordinary_attempt_at']===null&&$proof['rows'][0]['next_ordinary_attempt_clock_known']
        &&$proof['rows'][0]['current_upload_status']===null&&$proof['rows'][0]['current_upload_status_known']);
    $p->prepare("UPDATE max_channel_conversion_outbox SET state='ready',last_error='metrika_visit_unverified',next_attempt_unix=?,upload_status='UPLOADED' WHERE id=1")->execute([$now+300]);
    $held=$read($frame)['finite_visit_evidence']['rows'][0];
    $check('a saved unverified hold stays unverified instead of being reinterpreted as no prior visit or API acceptance',
        $held['financial_state']==='ready'&&$held['current_hold_reason']==='metrika_visit_unverified'&&$held['current_hold_reason_known']
        &&$held['next_ordinary_attempt_at']===gmdate('Y-m-d\TH:i:s\Z',$now+300)
        &&$held['current_upload_status']==='UPLOADED'&&$held['api_accepted_at']===null&&$held['api_receipt_ref']===null);
    $p->exec("UPDATE max_channel_conversion_outbox SET last_error='PRIVATE_UNKNOWN_HOLD',next_attempt_unix=-1,upload_status='PRIVATE_UNKNOWN_UPLOAD' WHERE id=1");
    $unknownHold=$read($frame)['finite_visit_evidence']['rows'][0];
    $check('unrecognized saved hold status and invalid schedule remain explicit unknown without exporting private strings',
        !$unknownHold['current_hold_reason_known']&&$unknownHold['current_hold_reason']===null
        &&!$unknownHold['current_upload_status_known']&&$unknownHold['current_upload_status']===null
        &&!$unknownHold['next_ordinary_attempt_clock_known']&&$unknownHold['next_ordinary_attempt_at']===null
        &&!str_contains(json_encode($unknownHold,JSON_THROW_ON_ERROR),'PRIVATE_'));
    $p->exec("UPDATE max_channel_conversion_outbox SET state='pending',last_error='identity_not_found',next_attempt_unix=0,upload_status=NULL WHERE id=1");
    $sql=implode(' ',array_column($statements,'sql'));$json=json_encode($r,JSON_THROW_ON_ERROR);
    $check('finite proof projection returns no raw ledger customer tracker or source payload identifiers',
        !str_contains($sql,'profile_json')&&!str_contains($sql,'snapshot_json')&&!str_contains($json,'PRIVATE_')
        &&!str_contains($json,'"730001"')&&!str_contains($json,'"740001"')&&!str_contains($json,'"930001"')
        &&!str_contains($json,'external_user_id')&&!str_contains($json,'conversation_id')&&!str_contains($json,'value_profile_id')
        &&!str_contains($json,'client_id')&&!str_contains($json,'record_id')&&$snapshot()===$before);
    $observed=$frame;$event=(int)$observed['r'][0][15];$sourceAt=$checkpoint-30*86400;
    $client='9912345678912345';$record='82101';$candidate=ModeledConversionValueVisitEvidence::candidateReference(
        $observed['r'][0][0],$client,$record,$sourceAt,'canonical_max_lookup');
    $observed['r'][0]=[$observed['r'][0][0],$observed['r'][0][1],2,$now-4,$now-3,1,
        $client,$record,$sourceAt,$candidate,1,true,1,$event-30,1,$event];
    $saved=$read($observed)['finite_visit_evidence'];$savedRow=$saved['rows'][0];
    $public=json_encode($saved,JSON_THROW_ON_ERROR);
    $check('saved recent real-visit evidence stays distinct from old source identity age and financial readiness',
        $saved['available']&&$savedRow['window_allowed']===true&&$savedRow['financial_state']==='pending'
        &&$savedRow['first_ready_at']===null&&$savedRow['api_accepted_at']===null&&$savedRow['processed_at']===null
        &&$savedRow['source_observed_at']===gmdate('Y-m-d\TH:i:s\Z',$sourceAt)
        &&$savedRow['selected_prior_visit_at']===gmdate('Y-m-d\TH:i:s\Z',$event-30)
        &&$savedRow['event_at']===gmdate('Y-m-d\TH:i:s\Z',$event)
        &&$savedRow['candidate_sha256']===$candidate&&$saved['provider_requests_in_reader']===0
        &&!str_contains($public,'"'.$client.'"')&&!str_contains($public,'"'.$record.'"'));

    // Keep the original failed observation intact while a separate two-case forward journal changes.
    $unavailableOriginal=$frame;$oldChecked=$now-20;
    $unavailableOriginal['r'][0][2]=2;$unavailableOriginal['r'][0][4]=$oldChecked;$unavailableOriginal['r'][0][5]=3;
    foreach([1,2]as$i){
        $ref=$unavailableOriginal['r'][$i][0];$id=$unavailableOriginal['r'][$i][1];$event=$unavailableOriginal['r'][$i][15];
        $client='993456789012340'.$i;$record=(string)(84000+$i);$sourceAt=$checkpoint-(20+$i)*86400;
        $candidate=ModeledConversionValueVisitEvidence::candidateReference($ref,$client,$record,$sourceAt,'canonical_max_lookup');
        $unavailableOriginal['r'][$i]=[$ref,$id,2,$oldChecked-1,$oldChecked,1,$client,$record,$sourceAt,$candidate,2,false,4,null,0,$event];
    }
    $originalBytes=json_encode($unavailableOriginal,JSON_THROW_ON_ERROR);
    $pendingRecovery=$read($unavailableOriginal)['finite_visit_recovery'];
    $check('unrecorded forward recovery exposes two pending zero-consumption cases without inventing an absent visit',
        $pendingRecovery['available']&&!$pendingRecovery['recorded']&&$pendingRecovery['checks_consumed']===0
        &&count($pendingRecovery['rows'])===2&&$pendingRecovery['eligible_original_cases']===2&&$pendingRecovery['excluded_original_cases']===1
        &&array_column($pendingRecovery['rows'],'attempt_status')===['pending','pending']
        &&array_column($pendingRecovery['rows'],'window_allowed')===[null,null]
        &&!in_array($frame['r'][0][0],array_column($pendingRecovery['rows'],'event_ref'),true));
    $recovery=ModeledConversionValueVisitRecovery::create($unavailableOriginal,$namespace,$now);
    $doneRecovery=$recovery;
    foreach($doneRecovery['r']as$i=>&$forward){
        $event=(int)$unavailableOriginal['r'][$i+1][15];
        $forward[2]=2;$forward[3]=$now-2;$forward[4]=$now-1;$forward[5]=1;
        $forward[6]=$i===0;$forward[7]=$i===0?1:3;$forward[8]=$i===0?$event-30:null;$forward[9]=$i===0?1:2;$forward[10]=1;
    }unset($forward);
    $oldOnly=$read($unavailableOriginal);$forwardView=$read($unavailableOriginal,null,null,$doneRecovery);
    $newProof=$forwardView['finite_visit_recovery'];$oldProof=$forwardView['finite_visit_evidence'];
    $check('new recovery results stay separate from original unavailable observations and do not grant financial readiness',
        $newProof['available']&&$newProof['recorded']&&$newProof['complete']&&$newProof['checks_consumed']===2
        &&$oldProof===$oldOnly['finite_visit_evidence']
        &&$newProof['rows'][0]['original_report_status']==='unavailable'
        &&$newProof['rows'][0]['original_window_reason']==='visit_report_unavailable'
        &&$newProof['rows'][0]['original_checked_at']===gmdate('Y-m-d\TH:i:s\Z',$oldChecked)
        &&$newProof['rows'][0]['checked_at']===gmdate('Y-m-d\TH:i:s\Z',$now-1)
        &&$newProof['rows'][0]['window_allowed']===true&&$newProof['rows'][1]['window_reason']==='no_prior_visit'
        &&array_column($newProof['rows'],'financial_state')===['pending','pending']
        &&array_column($newProof['rows'],'api_accepted_at')===[null,null]
        &&json_encode($unavailableOriginal,JSON_THROW_ON_ERROR)===$originalBytes);
    foreach(['digest','candidate','excluded_case']as$kind){
        $badRecovery=$doneRecovery;
        if($kind==='digest')$badRecovery['d']=str_repeat('0',64);
        elseif($kind==='candidate')$badRecovery['r'][0][1]=str_repeat('0',64);
        else$badRecovery['r'][0][0]=$frame['r'][0][0];
        $bad=$read($unavailableOriginal,null,null,$badRecovery)['finite_visit_recovery'];
        $check('forward recovery rejects changed '.$kind.' binding without copying private original source data',
            !$bad['available']&&$bad['provider_requests_in_reader']===0);
    }
    $changedOriginal=$unavailableOriginal;$changedOriginal['r'][1][4]++;
    $staleRecovery=$read($changedOriginal,null,null,$doneRecovery)['finite_visit_recovery'];
    $tombstone=$read($unavailableOriginal,null,null,false)['finite_visit_recovery'];
    $unknownRecovery=$recovery;$unknownRecovery['r'][0][2]=1;$unknownRecovery['r'][0][3]=$now-2;$unknownRecovery['r'][0][10]=1;
    $unknown=$read($unavailableOriginal,null,null,$unknownRecovery)['finite_visit_recovery'];
    $check('changed original results stale the new journal while tombstone and consumed intent remain explicit unknown',
        !$staleRecovery['available']&&!$tombstone['available']
        &&$tombstone['reason']==='retained_recovery_tombstone_no_automatic_retry'
        &&$unknown['available']&&$unknown['checks_consumed']===1
        &&$unknown['rows'][0]['attempt_status']==='consumed_unknown'&&$unknown['rows'][0]['report_status']==='unknown');
    $safeRecovery=json_encode($forwardView,JSON_THROW_ON_ERROR);
    $check('separate forward projection contains no private ClientID record ID or extra provider request',
        !str_contains($safeRecovery,'"9934567890123401"')&&!str_contains($safeRecovery,'"84001"')
        &&!str_contains($safeRecovery,'client_id')&&!str_contains($safeRecovery,'record_id')
        &&$newProof['provider_requests_in_reader']===0&&$snapshot()===$before);
    // A distinct native conversation can hold a processed tracker binding for the same actual MAX user.
    $auditRead=static fn(?callable $auth=null,?ModeledConversionStatusBudget $budget=null):array=>
        $read($unavailableOriginal,$auth,$budget,$doneRecovery)['retained_native_identity_evidence'];
    $withoutIndex=$auditRead();
    $check('retained native identity audit never falls back to a scan when its exact point index is absent',
        !$withoutIndex['available']&&$withoutIndex['reason']==='point_index_unavailable');
    $p->exec('CREATE INDEX ix_max_identity_point ON max_channel_conversion_outbox(project_key,platform,counter_id,external_user_id,id)');
    $wrongIndex=$auditRead();$p->exec('DROP INDEX ix_max_identity_point'.($p->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'?' ON max_channel_conversion_outbox':''));
    $check('retained native audit rejects a same-named index with the wrong column order',
        !$wrongIndex['available']&&$wrongIndex['reason']==='point_index_unavailable');
    $p->exec('CREATE INDEX ix_max_identity_point ON max_channel_conversion_outbox(project_key,platform,external_user_id,counter_id,id)');
    $nativeInsert=$p->prepare("INSERT INTO max_channel_conversion_outbox(id,project_key,platform,conversation_id,counter_id,target,transport_target,"
        ."state,upload_status,upload_id,external_user_id,event_unix,last_error,identity_source_key,attempts,claimed_at_unix,client_id,tracker_record_id,tracker_observed_unix)"
        ." VALUES(?,?,?,?,?,?,?,'processed','PROCESSED',?,?,?,'','',1,?,?,?,?)");
    $nativeRow=static function(int $id,array $override=[])use($nativeInsert,$now,$checkpoint):void{
        $values=array_replace(['id'=>$id,'project'=>'anytour','platform'=>'max','conversation'=>990000+$id,'counter'=>'98615635',
            'target'=>'max_manager_reply','transport'=>'max_channel_manager_reply','upload'=>(string)(880000+$id),'user'=>'740001',
            'event'=>$checkpoint-300,'claim'=>$now-10,'client'=>'9912345678901234','record'=>(string)(890000+$id),'source'=>$checkpoint-600],$override);
        $nativeInsert->execute(array_values($values));
    };
    $nativeRow(100);$nativeBefore=$snapshot();
    $oldClass=$p->getAttribute(PDO::ATTR_STATEMENT_CLASS);$p->setAttribute(PDO::ATTR_STATEMENT_CLASS,[ForecastV1ObservedStatement::class]);
    ForecastV1ObservedStatement::$observePredictive=true;ForecastV1ObservedStatement::$predictiveStatements=[];
    try{$native=$auditRead();}finally{ForecastV1ObservedStatement::$observePredictive=false;$p->setAttribute(PDO::ATTR_STATEMENT_CLASS,$oldClass);}
    $nativeSql=implode(' ',array_column(ForecastV1ObservedStatement::$predictiveStatements,'sql'));
    $mysql=$p->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql';
    $check('different native conversation supplies one strictly scoped processed candidate without granting a visit or financial permission',
        $native['available']&&$native['status']==='candidate_observed'&&$native['event_ref']===$frame['r'][0][0]
        &&$native['admitted_native_witnesses']===1&&$native['unique_candidate_count']===1
        &&$native['source_observed_at']===gmdate('Y-m-d\TH:i:s\Z',$checkpoint-600)
        &&$native['source_provenance']==='retained_native_processed_tracker_source'
        &&preg_match('/\A[a-f0-9]{64}\z/D',$native['candidate_sha256'])===1
        &&$native['visit_linkage']==='not_checked'&&!$native['financial_permission']&&$native['acknowledgement_time']==='not_persisted'
        &&$native['provider_requests_in_reader']===0&&$native['complete_within_bound']
        &&str_contains($nativeSql,'native_identity_point_packets')&&str_contains($nativeSql,'LIMIT 65 OFFSET 0')
        &&str_contains($nativeSql,'HEX(external_user_id)=HEX(?)')
        &&str_contains($nativeSql,$mysql?'FORCE INDEX (ix_max_identity_point)':'INDEXED BY ix_max_identity_point')
        &&(!$mysql||str_contains($nativeSql,'MAX_EXECUTION_TIME(250)'))
        &&$snapshot()===$nativeBefore);
    $stable=$auditRead();
    $check('native candidate reference stays stable and its projection omits all raw native identity and receipt identifiers',
        $stable['candidate_sha256']===$native['candidate_sha256']
        &&!str_contains(json_encode($stable,JSON_THROW_ON_ERROR),'9912345678901234')
        &&!str_contains(json_encode($stable,JSON_THROW_ON_ERROR),'880100')
        &&!str_contains(json_encode($stable,JSON_THROW_ON_ERROR),'890100')
        &&!str_contains(json_encode($stable,JSON_THROW_ON_ERROR),'740001'));
    $nativeRow(101,['user'=>'740099']);$nativeRow(102,['counter'=>'98615636']);$nativeRow(103,['project'=>'ANYTOUR']);
    $nativeRow(104,['platform'=>'max ']);$nativeRow(105,['counter'=>'98615635 ']);$nativeRow(106,['user'=>'740001 ']);
    $nativeRow(107,['target'=>'max_manager_reply ']);$nativeRow(108,['transport'=>'max_channel_manager_reply ']);
    $nativeRow(109,['client'=>"9912345678901234\n"]);$nativeRow(110);
    $p->exec("UPDATE max_channel_conversion_outbox SET tracker_observed_unix=NULL WHERE id=110");
    $nativeRow(111);$p->exec("UPDATE max_channel_conversion_outbox SET value_receipt_basis='legacy' WHERE id=111");
    $nativeRow(112,['source'=>$checkpoint+1]);$nativeRow(113,['claim'=>$now+1]);
    $nativeRow(114);$p->exec("UPDATE max_channel_conversion_outbox SET upload_status='UPLOADED' WHERE id=114");
    $nativeRow(115);$p->exec("UPDATE max_channel_conversion_outbox SET attempts=0 WHERE id=115");
    $nativeRow(116);$p->exec("UPDATE max_channel_conversion_outbox SET identity_source_key=' ' WHERE id=116");
    $guarded=$auditRead();
    $check('native identity audit rejects wrong user counter aliases goal aliases legacy missing clocks and incomplete typed receipts',
        $guarded['status']==='candidate_observed'&&$guarded['admitted_native_witnesses']===1
        &&$guarded['candidate_sha256']===$native['candidate_sha256']);
    $check('native first rejection reasons account for every inspected row while independent facets retain source field evidence',
        $guarded['inspected_rows']===12&&array_sum($guarded['first_rejection_reason_counts'])+$guarded['admitted_native_witnesses']===12
        &&$guarded['first_rejection_reason_counts']===['unsupported_goal_pair'=>3,'mixed_family_metadata'=>2,'not_processed_pair'=>1,
            'saved_error_present'=>0,'invalid_upload_receipt_id'=>0,'invalid_attempt_or_claim_clock'=>2,'invalid_client_or_tracker_record'=>1,
            'missing_or_invalid_source_clock'=>1,'source_after_signal_or_claim'=>1]
        &&$guarded['facets']['family']===['native'=>7,'modeled_value'=>0,'other'=>5]
        &&$guarded['facets']['state']['pending']===1&&$guarded['facets']['state']['processed']===11
        &&$guarded['facets']['identity_fields']===['canonical_client_id'=>10,'canonical_tracker_record_id'=>11,
            'valid_source_clock'=>10,'source_clock_at_or_before_event'=>9]);
    $nativeRow(119,['target'=>ModeledConversionValueContract::predictionKey('719'),'transport'=>ModeledConversionValueContract::TARGET]);
    $p->prepare("UPDATE max_channel_conversion_outbox SET identity_source_key='max_tracker',value_profile_id=719,value_rub=12,"
        ."value_stage='specific_request',value_contract=?,state='failed',upload_status='LINKAGE_FAILURE',client_id=NULL,"
        ."tracker_record_id='invalid_record',tracker_observed_unix=NULL WHERE id=119")->execute([ModeledConversionValueContract::VERSION]);
    $nativeRow(120,['client'=>'09912345678901234']);$p->exec("UPDATE max_channel_conversion_outbox SET state='unknown',upload_status='PREPARED',tracker_observed_unix=0 WHERE id=120");
    $mixed=$auditRead();
    $check('exact modeled family and failed or unknown native states remain visible without masking invalid or missing identity fields',
        $mixed['facets']['family']===['native'=>8,'modeled_value'=>1,'other'=>5]
        &&$mixed['facets']['state']['failed']===1&&$mixed['facets']['state']['unknown']===1
        &&$mixed['facets']['identity_fields']['canonical_client_id']===$guarded['facets']['identity_fields']['canonical_client_id']
        &&$mixed['facets']['identity_fields']['canonical_tracker_record_id']===$guarded['facets']['identity_fields']['canonical_tracker_record_id']+1
        &&$mixed['facets']['identity_fields']['valid_source_clock']===$guarded['facets']['identity_fields']['valid_source_clock']
        &&array_sum($mixed['first_rejection_reason_counts'])+$mixed['admitted_native_witnesses']===$mixed['inspected_rows']
        &&array_sum($mixed['facets']['family'])===$mixed['inspected_rows']&&array_sum($mixed['facets']['state'])===$mixed['inspected_rows']
        &&$mixed['admitted_native_witnesses']===1&&$mixed['candidate_sha256']===$native['candidate_sha256']
        &&!str_contains(json_encode($mixed,JSON_THROW_ON_ERROR),'09912345678901234')&&!str_contains(json_encode($mixed,JSON_THROW_ON_ERROR),'invalid_record'));
    $p->prepare("UPDATE max_channel_conversion_outbox SET value_contract=? WHERE id=119")->execute([ModeledConversionValueContract::VERSION.' ']);
    $aliasFamily=$auditRead();
    $check('modeled family classification requires the exact current contract rather than a padded alias',
        $aliasFamily['facets']['family']['modeled_value']===0&&$aliasFamily['facets']['family']['other']===6
        &&$aliasFamily['candidate_sha256']===$native['candidate_sha256']);
    $p->exec('DELETE FROM max_channel_conversion_outbox WHERE id>=101');
    $nativeRow(117);$sameClient=$auditRead();
    $check('two independently processed witnesses for the same ClientID are not mistaken for two candidate identities',
        $sameClient['status']==='candidate_observed'&&$sameClient['admitted_native_witnesses']===2&&$sameClient['unique_candidate_count']===1);
    $nativeRow(118,['client'=>'9912345678901235']);$ambiguous=$auditRead();
    $check('different admitted native ClientIDs are explicit ambiguity and never selected by latest receipt',
        $ambiguous['available']&&$ambiguous['status']==='ambiguous_native_candidates'
        &&$ambiguous['unique_candidate_count']===2&&$ambiguous['candidate_sha256']===null);
    $p->exec('DELETE FROM max_channel_conversion_outbox WHERE id>=117');
    for($id=200;$id<264;$id++)$nativeRow($id,['target'=>'unrelated_native_stage']);
    $rowBound=$auditRead();$p->exec('DELETE FROM max_channel_conversion_outbox WHERE id>=200');
    $check('65th exact-user metadata row makes native candidate completeness unknown even when an earlier valid candidate exists',
        !$rowBound['available']&&$rowBound['reason']==='row_limit'&&!$rowBound['complete_within_bound']);
    $nativeRow(140);$p->prepare('UPDATE max_channel_conversion_outbox SET last_error=? WHERE id=140')->execute([str_repeat('PRIVATE_OVERSIZE_',3000)]);
    $small=new ModeledConversionStatusBudget();$small->consumeBytes(18000);
    $copyBound=$read($unavailableOriginal,null,$small,$doneRecovery);
    $check('native SQL packet budget protects the already bound old and forward evidence from oversized private metadata',
        !$copyBound['retained_native_identity_evidence']['available']
        &&$copyBound['retained_native_identity_evidence']['reason']==='budget_headroom'
        &&$copyBound['finite_visit_evidence']['available']&&$copyBound['finite_visit_recovery']['available']
        &&!str_contains(json_encode($copyBound,JSON_THROW_ON_ERROR),'PRIVATE_OVERSIZE_'));
    $p->exec('DELETE FROM max_channel_conversion_outbox WHERE id=140');
    $denied=false;$revoked=false;$oldClass=$p->getAttribute(PDO::ATTR_STATEMENT_CLASS);
    $p->setAttribute(PDO::ATTR_STATEMENT_CLASS,[ForecastV1ObservedStatement::class]);
    ForecastV1ObservedStatement::$afterNativePoint=static function()use(&$revoked):void{$revoked=true;};
    try{$auditRead(static function()use(&$revoked):void{if($revoked)throw new DomainException('synthetic_native_revoked');});}
    catch(DomainException $error){$denied=$error->getMessage()==='synthetic_native_revoked';}
    finally{ForecastV1ObservedStatement::$afterNativePoint=null;$p->setAttribute(PDO::ATTR_STATEMENT_CLASS,$oldClass);}
    $check('native point evidence remains protected by final authorization after copied metadata',$denied);
    $p->exec('DELETE FROM max_channel_conversion_outbox WHERE id>=100');
    $none=$auditRead();
    $check('no admissible local native witness is explicitly limited evidence rather than a claim that tracker rows or visits are absent',
        $none['available']&&$none['status']==='no_admissible_native_witness'&&$none['candidate_sha256']===null
        &&$none['local_absence_does_not_prove_tracker_or_visit_absence']&&$none['visit_linkage']==='not_checked');

    ForecastV1ObservedStatement::$nativePresentationFixtures=['original'=>$forwardView['finite_visit_evidence'],
        'positive'=>$native,'guarded'=>$guarded,'same_client'=>$sameClient,'ambiguous'=>$ambiguous,
        'no_index'=>$withoutIndex,'row_limit'=>$rowBound,'budget'=>$copyBound['retained_native_identity_evidence'],'none'=>$none];
    forecastNativePresentationAssertions($check);

    $pairRefs=ModeledConversionValueVisitRecovery::originals($unavailableOriginal,$namespace,$now);
    $pairRows=[];foreach($pairRefs as$ref=>$tuple)$pairRows[]=[$ref,$now-2,$now-1,count($pairRows)===0?5:7,
        count($pairRows)===0?$tuple[15]-30:null,count($pairRows)===0?1:0];
    $pairJournal=ModeledConversionFixedPairDecisionEvidence::merge(null,$unavailableOriginal,$namespace,$pairRows,$now);
    $pairRead=$read($unavailableOriginal,null,null,$doneRecovery,$pairJournal,$now-2);
    $pairView=$pairRead['fixed_pair_operational_decisions'];
    $check('current paired decisions reuse the original three SQL bindings and keep positive gates separate from API acceptance',
        $pairView['available']&&$pairView['current_tick_coverage_complete']&&$pairView['observed_in_latest_tick']===2
        &&array_column($pairView['rows'],'current_decision')===['within_current_window','no_prior_visit']
        &&array_column($pairView['rows'],'financial_state')===['pending','pending']
        &&array_column($pairView['rows'],'api_receipt_ref')===[null,null]
        &&$pairView['positive_gate_is_not_api_acceptance']&&!$pairView['financial_permission']&&$pairView['provider_requests_in_reader']===0
        &&$pairRead['finite_visit_recovery']['rows'][0]['original_report_status']==='unavailable');
    $stale=$read($unavailableOriginal,null,null,$doneRecovery,$pairJournal,$now)['fixed_pair_operational_decisions'];
    $untouched=$read($unavailableOriginal,null,null,$doneRecovery,null,$now)['fixed_pair_operational_decisions'];
    $check('untouched or older paired observations never masquerade as current decisions',
        $stale['observed_in_latest_tick']===0&&array_column($stale['rows'],'current_decision')===['unknown','unknown']
        &&array_column($stale['rows'],'saved_decision')===['within_current_window','no_prior_visit']
        &&$untouched['journal_status']==='not_recorded'&&array_column($untouched['rows'],'observation_status')===['not_observed','not_observed']);
    $badPair=$pairJournal;$badPair['r'][0][0]=$unavailableOriginal['r'][0][0];
    $invalidPair=$read($unavailableOriginal,null,null,$doneRecovery,$badPair,$now-2)['fixed_pair_operational_decisions'];
    $check('a foreign operational ref exposes no current decision or extra subject',
        $invalidPair['journal_status']==='invalid_or_omitted'&&$invalidPair['observed_in_latest_tick']===0
        &&array_column($invalidPair['rows'],'event_ref')===array_keys($pairRefs));
    $scopeHash=str_repeat('c',64);
    $scopeIntent=ModeledConversionMaxCounterScopeEvidence::intent($unavailableOriginal,$ledger[0],$namespace,$scopeHash,$now-10);
    $scopeWire=['v'=>1,'counter_id'=>'98615635','request_sha256'=>$scopeHash,'checked_at_unix'=>$now-2,'status'=>'observed',
        'complete_within_bound'=>true,'scoped_rows'=>0,'empty_client_id_rows'=>0,'noncanonical_client_id_rows'=>0,'canonical_client_id_rows'=>0];
    $scopeClient=MaxTrackerLookupClient::validateCounterScopeEvidence($scopeWire,$scopeHash,$now-1);
    $scopeDone=ModeledConversionMaxCounterScopeEvidence::complete($scopeIntent,$unavailableOriginal,$ledger[0],$namespace,$scopeClient,$now);
    $scopeView=$read($unavailableOriginal,null,null,$doneRecovery,$pairJournal,$now-2,$scopeDone)['finite_counter_scope_evidence'];
    $check('counter evidence uses the same already bound missing row with separate server and application clocks and no financial permission',
        $scopeView['available']&&$scopeView['event_ref']===$unavailableOriginal['r'][0][0]&&$scopeView['attempt_status']==='observed'
        &&$scopeView['scoped_rows']===0&&$scopeView['financial_state']==='pending'&&$scopeView['api_receipt_ref']===null
        &&$scopeView['server_checked_at']===gmdate('Y-m-d\TH:i:s\Z',$now-2)
        &&$scopeView['app_received_at']===gmdate('Y-m-d\TH:i:s\Z',$now-1)
        &&$scopeView['local_scope_zero_is_not_global_tracker_absence']&&!$scopeView['financial_permission']);
    $latestRows=$pairRows;foreach($latestRows as&$tuple){$tuple[1]=$now;$tuple[2]=$now;}unset($tuple);
    $latestPair=ModeledConversionFixedPairDecisionEvidence::merge($pairJournal,$unavailableOriginal,$namespace,$latestRows,$now);
    $foreign=$frame;$foreign['n']=str_repeat('0',64);$invalid=$read($foreign)['finite_visit_evidence'];
    $missing=$read(null)['finite_visit_evidence'];$consumed=$read(false)['finite_visit_evidence'];
    $check('missing foreign and retained tombstone frames are explicit unavailable without invented no-visit results',
        !$invalid['available']&&!$missing['available']&&!$consumed['available']
        &&$consumed['reason']==='retained_budget_tombstone_no_automatic_retry'
        &&!array_key_exists('window_allowed',$missing));
    foreach(['ledger','event']as$kind){
        $rebound=$frame;if($kind==='ledger')$rebound['r'][0][1]='999';else$rebound['r'][0][15]--;
        $bad=$read($rebound)['finite_visit_evidence'];
        $check('finite saved '.$kind.' rebinding fails closed',
            !$bad['available']&&$bad['reason']==='saved_frame_binding_mismatch');
    }
    $malformed=$frame;$malformed['raw_client_id']='PRIVATE_UNTRUSTED_CLIENT';
    $bad=$read($malformed)['finite_visit_evidence'];
    $oversize=$frame;$oversize['r'][0][9]=str_repeat('PRIVATE_OVERSIZED_FRAME_',200);
    $large=$read($oversize)['finite_visit_evidence'];
    $check('malformed and oversized finite frames never pass private fields to the result',
        !$bad['available']&&!$large['available']
        &&!str_contains(json_encode([$bad,$large],JSON_THROW_ON_ERROR),'PRIVATE_'));
    $p->prepare('UPDATE modeled_conversion_profile_versions SET observed_at_unix=? WHERE id=4')->execute([$checkpoint-120]);
    $p->prepare('UPDATE max_channel_conversion_outbox SET event_unix=? WHERE id=4')->execute([$checkpoint-120]);
    $tooMany=$read($frame)['finite_visit_evidence'];
    $check('fourth eligible original candidate makes finite selection unavailable rather than choosing three',
        !$tooMany['available']&&$tooMany['reason']==='current_cohort_cardinality_unverified');
    $p->prepare('UPDATE modeled_conversion_profile_versions SET observed_at_unix=? WHERE id=4')->execute([$checkpoint+1]);
    $p->prepare('UPDATE max_channel_conversion_outbox SET event_unix=? WHERE id=4')->execute([$checkpoint+1]);
    $budget=new ModeledConversionStatusBudget();$budget->consumeBytes(65536-(16384+$scalarCopyBytes+64));
    $priority=$read($frame,null,$budget);
    $check('small finite frame keeps priority when shared bytes cannot also copy the optional sixteen-case packet',
        $priority['finite_visit_evidence']['available']&&count($priority['finite_visit_evidence']['rows'])===3
        &&$priority['exact_cases']===['available'=>false,'reason'=>'budget_headroom']);
    $oldClass=$p->getAttribute(PDO::ATTR_STATEMENT_CLASS);$p->setAttribute(PDO::ATTR_STATEMENT_CLASS,[ForecastV1ObservedStatement::class]);
    ForecastV1ObservedStatement::$observePredictive=true;ForecastV1ObservedStatement::$predictiveExecutions=0;$revoked=false;
    try{$read($frame,static function():void{if(ForecastV1ObservedStatement::$predictiveExecutions>=2)throw new DomainException('revoked');});}
    catch(DomainException $ignored){$revoked=true;}
    finally{ForecastV1ObservedStatement::$observePredictive=false;$p->setAttribute(PDO::ATTR_STATEMENT_CLASS,$oldClass);}
    $check('revocation after finite bound-case copy prevents release',
        $revoked&&ForecastV1ObservedStatement::$predictiveExecutions===2);
    $projection=(new ReflectionClass(ModeledConversionForecastStatusReader::class))->newInstanceWithoutConstructor();
    $bound=new ReflectionMethod(ModeledConversionForecastStatusReader::class,'boundedResponse');
    $fixed=$r;unset($fixed['exact_cases']);$fixed=['value_delivery'=>['repair_assessments'=>$fixed,'predictive_v1'=>['examples'=>[]]]];
    $padding=max(0,27000-strlen(json_encode($fixed,JSON_THROW_ON_ERROR)));
    $body=['padding'=>str_repeat('x',$padding),'value_delivery'=>['repair_assessments'=>$r,'predictive_v1'=>['examples'=>[['private_padding'=>str_repeat('x',6000)]]]]];
    $safe=$bound->invoke($projection,$body);
    $check('finite three-case proof survives removal of optional examples and sixteen-case detail under the existing response cap',
        strlen(json_encode($safe,JSON_THROW_ON_ERROR))<=28000
        &&$safe['value_delivery']['repair_assessments']['finite_visit_evidence']===$proof
        &&$safe['value_delivery']['predictive_v1']['examples']===[]);
    $check('finite read operations never mutate the saved financial rows or native profile payloads',$snapshot()===$before);

    // Exercise the complete status call, including real SQL/copy accounting, with a large
    // saved receipt and first profile. The prior order spent these bytes before the frame.
    $p->exec('ALTER TABLE modeled_conversion_profile_versions ADD COLUMN journal_cursor BIGINT DEFAULT 0');
    foreach(['value_origin VARCHAR(32)','value_reviewed_at_unix BIGINT']as$column)
        $p->exec('ALTER TABLE max_channel_conversion_outbox ADD COLUMN '.$column);
    $p->exec('DROP TABLE IF EXISTS modeled_conversion_profiles_state');
    $p->exec('CREATE TABLE modeled_conversion_profiles_state(project_key VARCHAR(40) PRIMARY KEY,enabled INT,last_receipt_json TEXT,last_error_at_unix BIGINT,last_error_category VARCHAR(40))');
    $p->exec("INSERT INTO modeled_conversion_profiles_state VALUES('anytour',1,'{}',NULL,NULL)");
    $p->exec('DROP TABLE IF EXISTS modeled_conversion_value_export_settings');
    $p->exec("CREATE TABLE modeled_conversion_value_export_settings(project_key VARCHAR(40) PRIMARY KEY,counter_id VARCHAR(20),mode VARCHAR(16),goal_id VARCHAR(20),goal_intent VARCHAR(20),activated_at_unix BIGINT,last_tick_unix BIGINT,contract_version VARCHAR(64),last_receipt_json TEXT,history_from_unix BIGINT,history_until_unix BIGINT,history_completed_at_unix BIGINT,history_scanned BIGINT,history_collected BIGINT,history_stats_json TEXT,history_cursor BIGINT,history_ceiling BIGINT,history_fortnight_requested_at_unix BIGINT,history_day_receipt_json TEXT,last_report_success_unix BIGINT,last_report_success_json TEXT,report_failure_since_unix BIGINT)");
    $finished=$unavailableOriginal;
    $receipt=['errors'=>0,'finite_visit_evidence'=>$finished,'finite_visit_recovery'=>$doneRecovery,
        'fixed_pair_operational_decisions'=>$latestPair,'finite_counter_scope_evidence'=>$scopeDone,'private_padding'=>''];
    $receipt['private_padding']=str_repeat('x',max(0,8000-strlen(json_encode($receipt,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR))));
    $p->prepare("INSERT INTO modeled_conversion_value_export_settings(project_key,counter_id,mode,goal_id,goal_intent,activated_at_unix,last_tick_unix,contract_version,last_receipt_json) VALUES('anytour','98615635','send','678187097','verified',?,?,?,?)")
        ->execute([$checkpoint-86400,$now,ModeledConversionValueContract::VERSION,json_encode($receipt,JSON_THROW_ON_ERROR)]);
    $packet=forecastV1Packet('730010','930010',[['text'=>'Подберите тур в Турцию на 7 ночей, 2 взрослых.']],'max');
    $packet['prediction_followup']=['private_padding'=>''];
    $savePacket=$p->prepare("INSERT INTO modeled_conversion_profile_versions(id,project_key,platform,customer_key,conversation_id,observed_at_unix,assessment_namespace,assessment_kind,profile_json,journal_cursor) VALUES(10,'anytour','max','730010','930010',?,?,'live',?,?)");
    $savePacket->execute([strtotime($packet['profile']['prediction_at']),$namespace,json_encode($packet,JSON_THROW_ON_ERROR),$packet['journal_cursor']]);
    $coreReader=new ModeledConversionForecastStatusReader($p,static function():void{},new ModeledConversionStatusBudget());
    $parts=(new ReflectionMethod(ModeledConversionForecastStatusReader::class,'parts'))->invoke($coreReader);
    $mysql=$p->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql';
    $sizes=array_map(static fn(string $part):string=>'COALESCE('.($mysql?'OCTET_LENGTH('.$part.')':'LENGTH(CAST('.$part.' AS BLOB))').',0)',$parts);
    $baseSize=(int)$p->query('SELECT '.implode('+',$sizes).' FROM modeled_conversion_profile_versions WHERE id=10')->fetchColumn();
    $packet['prediction_followup']['private_padding']=str_repeat('x',max(0,9000-$baseSize));
    $p->prepare('UPDATE modeled_conversion_profile_versions SET profile_json=? WHERE id=10')->execute([json_encode($packet,JSON_THROW_ON_ERROR)]);
    $pipelineBefore=$snapshot();$settingsBefore=$p->query('SELECT * FROM modeled_conversion_value_export_settings')->fetchAll(PDO::FETCH_ASSOC);
    $budget=new ModeledConversionStatusBudget();$budget->consumeBytes(26000);
    $oldClass=$p->getAttribute(PDO::ATTR_STATEMENT_CLASS);$p->setAttribute(PDO::ATTR_STATEMENT_CLASS,[ForecastV1ObservedStatement::class]);
    ForecastV1ObservedStatement::$observePredictive=true;ForecastV1ObservedStatement::$predictiveStatements=[];
    try{$pipeline=forecastV1Read($p,null,$budget);}
    finally{ForecastV1ObservedStatement::$observePredictive=false;$p->setAttribute(PDO::ATTR_STATEMENT_CLASS,$oldClass);}
    $frameAt=null;$coreAt=null;
    foreach(ForecastV1ObservedStatement::$predictiveStatements as$i=>$statement){
        if(str_contains($statement['sql'],'finite_bound_packets'))$frameAt??=$i;
        if(str_contains($statement['sql'],' AS prediction_json,'))$coreAt??=$i;
    }
    $priorityProof=$pipeline['value_delivery']['repair_assessments']['finite_visit_evidence']??[];
    $priorityRecovery=$pipeline['value_delivery']['repair_assessments']['finite_visit_recovery']??[];
    $priorityOperational=$pipeline['value_delivery']['repair_assessments']['fixed_pair_operational_decisions']??[];
    $priorityScope=$pipeline['value_delivery']['repair_assessments']['finite_counter_scope_evidence']??[];
    $check('complete status reserves both original three-case and forward two-case journals before expensive optional context copies',
        $pipeline['available']&&($priorityProof['available']??false)&&$priorityProof['complete']
        &&count($priorityProof['rows'])===3&&array_column($priorityProof['rows'],'event_ref')===array_column($finished['r'],0)
        &&($priorityRecovery['available']??false)&&$priorityRecovery['complete']&&count($priorityRecovery['rows'])===2
        &&array_column($priorityRecovery['rows'],'event_ref')===array_column($doneRecovery['r'],0)
        &&($priorityOperational['available']??false)&&$priorityOperational['current_tick_coverage_complete']
        &&($priorityScope['available']??false)&&$priorityScope['attempt_status']==='observed'
        &&$frameAt!==null&&($coreAt===null?$pipeline['window']['byte_limit_omissions']>0:$frameAt<$coreAt)
        &&$budget->remainingBytes()>=8192&&strlen(json_encode($pipeline,JSON_THROW_ON_ERROR))<=28000
        &&$priorityProof['provider_requests_in_reader']===0&&$pipeline['model_calls']===0&&$pipeline['conversion_uploads']===0
        &&$pipeline['limits']['max_selected_bytes']===65536&&$pipeline['limits']['max_execution_seconds']===5
        &&$snapshot()===$pipelineBefore&&$p->query('SELECT * FROM modeled_conversion_value_export_settings')->fetchAll(PDO::FETCH_ASSOC)===$settingsBefore);
    $outputPressure=$pipeline;
    $outputPressure['visit_delivery_check']=['today_sent_cohort'=>['synthetic'=>''],
        'yesterday_sent_cohort'=>['synthetic'=>''],
        'examples'=>array_fill(0,6,['synthetic'=>str_repeat('v',400)]),
        'followup_sent_cohort'=>['failure_examples'=>array_fill(0,10,['synthetic'=>str_repeat('f',1000)])]];
    $responseFlags=JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR;
    $responseBytes=static fn(array $value):int=>strlen(json_encode($value,$responseFlags));
    // Measure the same fixed prefix that remains after every permitted detail omission.
    // The three-case proof is deliberately retained in that prefix.
    $fixedPrefix=static function(array $value):array{
        foreach([['visit_delivery_check','followup_sent_cohort','failure_examples'],['value_delivery','predictive_v1','examples']]as$path){
            if(isset($value[$path[0]][$path[1]][$path[2]])&&$value[$path[0]][$path[1]][$path[2]]!==[]){
                $value[$path[0]][$path[1]][$path[2]]=[];
                $value[$path[0]][$path[1]][$path[2]==='failure_examples'?'failure_examples_omitted_due_to_response_budget':'examples_omitted_due_to_response_budget']=true;
            }
        }
        foreach(['saved_input_diagnosis','visit_delivery_check']as$key)if(!empty($value[$key]['examples'])){
            $value[$key]['examples']=[];$value[$key]['examples_omitted_due_to_response_budget']=true;
        }
        if(!empty($value['value_delivery']['repair_assessments']['exact_cases']['rows'])){
            $cases=&$value['value_delivery']['repair_assessments']['exact_cases'];
            $cases['rows']=[];$cases['window']['returned_events']=0;
            $cases['window']['complete_within_bound']=false;$cases['window']['omitted_due_to_response_budget']=true;
            $cases['identity_evidence']['observed_case_count']=0;unset($cases);
        }
        // Match the existing optional-detail omissions before measuring feasible context.
        // All four current finite proofs remain in this fixed prefix.
        if(isset($value['value_delivery']['repair_assessments']['initial_api_receipts']))
            $value['value_delivery']['repair_assessments']['initial_api_receipts']=[
                'available'=>false,'reason'=>'response_budget','provider_requests_in_reader'=>0];
        if(isset($value['value_delivery']['repair_assessments']['retained_native_identity_evidence']))
            $value['value_delivery']['repair_assessments']['retained_native_identity_evidence']=[
                'available'=>false,'reason'=>'response_budget','provider_requests_in_reader'=>0,'financial_permission'=>false];
        return $value;
    };
    $summaryBaseBytes=$responseBytes($fixedPrefix($outputPressure));
    $summaryHeadroom=28000-$summaryBaseBytes;
    $check('cached visit-summary pressure has measured feasible space after existing optional omissions',$summaryHeadroom>=256);
    $summaryPadding=intdiv($summaryHeadroom,4);
    $outputPressure['visit_delivery_check']['today_sent_cohort']['synthetic']=str_repeat('d',$summaryPadding);
    $outputPressure['visit_delivery_check']['yesterday_sent_cohort']['synthetic']=str_repeat('e',$summaryPadding);
    $oldPressure=$outputPressure;$oldPressure['synthetic_context']=str_repeat('c',12000);
    $oldFixedBytes=$responseBytes($fixedPrefix($oldPressure));
    $oldBounded=$bound->invoke($projection,$oldPressure);
    $prefix=$fixedPrefix($outputPressure);$prefix['synthetic_context']='';
    $outputPressure['synthetic_context']=str_repeat('c',max(0,25000-$responseBytes($prefix)));
    $fixedBytes=$responseBytes($fixedPrefix($outputPressure));$beforeBytes=$responseBytes($outputPressure);
    $bounded=$bound->invoke($projection,$outputPressure);$afterBytes=$responseBytes($bounded);
    $sameProof=$bounded['value_delivery']['repair_assessments']['finite_visit_evidence']===$priorityProof
        &&$bounded['value_delivery']['repair_assessments']['finite_visit_recovery']===$priorityRecovery
        &&$bounded['value_delivery']['repair_assessments']['fixed_pair_operational_decisions']===$priorityOperational
        &&$bounded['value_delivery']['repair_assessments']['finite_counter_scope_evidence']===$priorityScope;
    $omitted=($bounded['visit_delivery_check']['followup_sent_cohort']['failure_examples_omitted_due_to_response_budget']??false)===true;
    $privateAbsent=!str_contains(json_encode($bounded,$responseFlags),'PRIVATE_NATIVE_');
    $validPressure=$summaryPadding>0&&$fixedBytes<=28000&&$beforeBytes>28000&&$afterBytes<=28000&&$sameProof&&$omitted&&$privateAbsent;
    if(!$validPressure)echo 'FINITE_OUTPUT_PRESSURE_SYNTHETIC '.json_encode([
        'old_fixed_bytes'=>$oldFixedBytes,'old_before_bytes'=>$responseBytes($oldPressure),'old_after_bytes'=>$responseBytes($oldBounded),
        'old_proof_same'=>$oldBounded['value_delivery']['repair_assessments']['finite_visit_evidence']===$priorityProof,
        'new_fixed_bytes'=>$fixedBytes,'before_bytes'=>$beforeBytes,'after_bytes'=>$afterBytes,
        'proof_same'=>$sameProof,'examples_omitted'=>$omitted,'private_absent'=>$privateAbsent],JSON_THROW_ON_ERROR).PHP_EOL;
    $check('real saved three-case proof survives feasible cached visit-summary output pressure without raising response limits'
        .' old_fixed_bytes='.$oldFixedBytes.' new_fixed_bytes='.$fixedBytes.' before='.$beforeBytes.' after='.$afterBytes,$validPressure);
    $omittedProof=['available'=>false,'reason'=>'response_budget','provider_requests_in_reader'=>0];
    $originalPriority=['value_delivery'=>['repair_assessments'=>[
        'finite_visit_evidence'=>$priorityProof,'finite_visit_recovery'=>$priorityRecovery]],'synthetic_context'=>''];
    $originalOnly=$originalPriority;$originalOnly['value_delivery']['repair_assessments']['finite_visit_recovery']=$omittedProof;
    $recoveryDelta=$responseBytes($originalPriority)-$responseBytes($originalOnly);
    $originalTarget=28000-max(1,intdiv($recoveryDelta,2));
    $originalPriority['synthetic_context']=str_repeat('o',max(0,$originalTarget-$responseBytes($originalOnly)));
    $originalOnly['synthetic_context']=$originalPriority['synthetic_context'];
    $originalBounded=$bound->invoke($projection,$originalPriority);
    $check('response limit omits the newer journal first and preserves the original proof byte for byte when it fits',
        $responseBytes($originalPriority)>28000&&$responseBytes($originalOnly)<=28000&&$responseBytes($originalBounded)<=28000
        &&$originalBounded['value_delivery']['repair_assessments']['finite_visit_evidence']===$priorityProof
        &&$originalBounded['value_delivery']['repair_assessments']['finite_visit_recovery']===$omittedProof);
    $bothPriority=$originalPriority;
    $bothOmitted=$bothPriority;$bothOmitted['synthetic_context']='';
    $bothOmitted['value_delivery']['repair_assessments']['finite_visit_evidence']=$omittedProof;
    $bothOmitted['value_delivery']['repair_assessments']['finite_visit_recovery']=$omittedProof;
    $originalBase=$bothOmitted;$originalBase['value_delivery']['repair_assessments']['finite_visit_evidence']=$priorityProof;
    $originalDelta=$responseBytes($originalBase)-$responseBytes($bothOmitted);
    $bothTarget=28000-max(1,intdiv($originalDelta,2));
    $bothPriority['synthetic_context']=str_repeat('b',max(0,$bothTarget-$responseBytes($bothOmitted)));
    $onlyRecoveryOmitted=$bothPriority;$onlyRecoveryOmitted['value_delivery']['repair_assessments']['finite_visit_recovery']=$omittedProof;
    $bothBounded=$bound->invoke($projection,$bothPriority);
    $check('original proof is explicitly omitted only when the remaining fixed output still exceeds its unchanged response limit',
        $responseBytes($onlyRecoveryOmitted)>28000&&$responseBytes($bothBounded)<=28000
        &&$bothBounded['value_delivery']['repair_assessments']['finite_visit_evidence']===$omittedProof
        &&$bothBounded['value_delivery']['repair_assessments']['finite_visit_recovery']===$omittedProof);
    $orphan=['value_delivery'=>['repair_assessments'=>[
        'finite_visit_evidence'=>['available'=>false,'reason'=>'original_scope_unavailable'],
        'finite_visit_recovery'=>$priorityRecovery]]];
    $orphan['value_delivery']['repair_assessments']['fixed_pair_operational_decisions']=$priorityOperational;
    $orphan['value_delivery']['repair_assessments']['finite_counter_scope_evidence']=$priorityScope;
    $orphanBounded=$bound->invoke($projection,$orphan);
    $check('response projection never retains an available forward journal without its original bound proof',
        !$orphanBounded['value_delivery']['repair_assessments']['finite_visit_recovery']['available']
        &&$orphanBounded['value_delivery']['repair_assessments']['finite_visit_recovery']['reason']==='original_current_binding_unavailable'
        &&!$orphanBounded['value_delivery']['repair_assessments']['fixed_pair_operational_decisions']['available']
        &&!$orphanBounded['value_delivery']['repair_assessments']['finite_counter_scope_evidence']['available']);

    if($initialApiCohort!==[]){
        $receiptPressure=$outputPressure;$receiptPressure['value_delivery']['repair_assessments']['initial_api_receipts']=$initialApiCohort;
        $receiptBounded=$bound->invoke($projection,$receiptPressure);
        $receiptFixedPrefix=$fixedPrefix($receiptPressure);
        $receiptFixedPrefix['value_delivery']['repair_assessments']['initial_api_receipts']=$initialApiCohort;
        $check('exact sixteen-receipt view yields explicitly before the original and forward journals under the unchanged response ceiling',
            $initialApiCohort['available']&&count($initialApiCohort['rows'])===16&&$responseBytes($receiptFixedPrefix)>28000
            &&$receiptBounded['value_delivery']['repair_assessments']['initial_api_receipts']['reason']==='response_budget'
            &&$receiptBounded['value_delivery']['repair_assessments']['finite_visit_evidence']===$priorityProof
            &&$receiptBounded['value_delivery']['repair_assessments']['finite_visit_recovery']===$priorityRecovery
            &&$receiptBounded['value_delivery']['repair_assessments']['fixed_pair_operational_decisions']===$priorityOperational
            &&$receiptBounded['value_delivery']['repair_assessments']['finite_counter_scope_evidence']===$priorityScope
            &&$responseBytes($receiptBounded)<=28000);
    }

    return forecastRetainedAppIdentityAssertions($p,$check,$unavailableOriginal,$ledger[0],$now);
}




/** Finite derived ledger only: isolated PDO fixture, synthetic source tuples and fake Reports transport. */

/** Real tick/claim path over isolated synthetic rows. Provider/source IO is replaced by bounded doubles. */
function forecastKnownRelationDispatchAssertions(PDO $p,callable $check,array $frame,array $row,array $known,array $proof,int $now,PDO $fault):void
{
    $fetchRows=static function(string $sql)use($p):array{$q=$p->query($sql);try{return $q->fetchAll(PDO::FETCH_ASSOC);}finally{$q->closeCursor();}};
    // This caller owns a deliberately minimal read-only status schema. Use the existing
    // canonical worker fixture in an isolated set of tables, then restore schema and bytes.
    if($p->inTransaction())throw new RuntimeException('relation_fixture_outer_transaction');
    if($p->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'&&$p->query('SELECT DATABASE()')->fetchColumn()!=='maxbot_modeled_stream_test')
        throw new RuntimeException('unsafe_fixture_database');
    $sourceSettings=$fetchRows("SELECT * FROM modeled_conversion_value_export_settings WHERE project_key='anytour'")[0];
    $sourceEvents=$fetchRows('SELECT * FROM max_channel_conversion_outbox ORDER BY id');
    $sourceProfiles=$fetchRows('SELECT * FROM modeled_conversion_profile_versions ORDER BY id');
    $cohortIds=array_fill_keys(array_map(static fn(array $r):string=>(string)$r[1],$frame['r']),true);
    $sourceEvents=array_values(array_filter($sourceEvents,static fn(array $r):bool=>isset($cohortIds[(string)$r['id']])));
    if(count($sourceEvents)!==3)throw new RuntimeException('relation_fixture_original_cohort_unavailable');
    $profileIds=array_fill_keys(array_map(static fn(array $r):string=>(string)$r['value_profile_id'],$sourceEvents),true);
    $sourceProfiles=array_values(array_filter($sourceProfiles,static fn(array $r):bool=>isset($profileIds[(string)$r['id']])));
    $core=['modeled_conversion_price_audit','modeled_conversion_value_export_settings','max_channel_conversion_outbox',
        'max_channel_attribution_settings','modeled_conversion_profile_versions','advertising_visit_observations','conversations','customer_channels'];
    $renamed=[];$ownsSchema=false;$primary=null;$stage='backup';
    $trace=static function(string $stage,Throwable $e):void{
        $info=$e instanceof PDOException?($e->errorInfo??[]):[];$previous=$e->getPrevious();
        fwrite(STDERR,'SYNTHETIC_KNOWN_RELATION_EXCEPTION '.json_encode([
            'stage'=>$stage,'class'=>get_class($e),'line'=>$e->getLine(),'sqlstate'=>$info[0]??null,
            'driver_code'=>(is_int($info[1]??null)||(is_string($info[1]??null)&&preg_match('/\A[0-9]{1,12}\z/D',$info[1])===1))?(string)$info[1]:null,'previous_class'=>$previous?get_class($previous):null,
            'previous_line'=>$previous?->getLine()],JSON_THROW_ON_ERROR).PHP_EOL);
    };
    try{
        foreach($core as$table){
            try{$q=$p->query('SELECT 1 FROM '.$table.' LIMIT 0');$q->closeCursor();}catch(PDOException $missing){continue;}
            $p->exec('ALTER TABLE '.$table.' RENAME TO krd_'.$table);$renamed[]=$table;
        }
        $ownsSchema=true;$stage='canonical_schema';modeledValueSchema($p);
        // The read-only legacy schema permits NULL holds; exercise that contract in the native fixture too.
        if($p->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql')$p->exec("ALTER TABLE max_channel_conversion_outbox MODIFY last_error VARCHAR(64) NULL DEFAULT ''");
        foreach(['assessment_namespace CHAR(64)','assessment_kind VARCHAR(8)','journal_cursor BIGINT DEFAULT 0']as$column)
            $p->exec('ALTER TABLE modeled_conversion_profile_versions ADD COLUMN '.$column);
        $copy=static function(string $table,array $value)use($p):void{
            $q=$p->query('SELECT * FROM '.$table.' LIMIT 0');$names=[];
            for($i=0;$i<$q->columnCount();$i++)$names[]=$q->getColumnMeta($i)['name'];$q->closeCursor();
            $value=array_intersect_key($value,array_fill_keys($names,true));
            $p->prepare('INSERT INTO '.$table.'('.implode(',',array_keys($value)).') VALUES('.implode(',',array_fill(0,count($value),'?')).')')->execute(array_values($value));
        };
        $stage='copy_fixture_settings';
        // The status-reader schema permits NULL cursors; canonical worker-owned defaults are zero.
        // These local cursors/counters are not part of the original financial event or its proof.
        foreach(['history_cursor','history_scanned','history_collected','profile_cursor','base_value_rub']as$key)
            if(array_key_exists($key,$sourceSettings)&&$sourceSettings[$key]===null)unset($sourceSettings[$key]);
        $p->exec("DELETE FROM modeled_conversion_value_export_settings WHERE project_key='anytour'");
        $copy('modeled_conversion_value_export_settings',$sourceSettings);
        $stage='copy_fixture_profiles';
        foreach($sourceProfiles as$profile){$profile['profile_json']='{"access_status":"allowed"}';$copy('modeled_conversion_profile_versions',$profile);}
        $channels=[];$stage='copy_fixture_events';
        foreach($sourceEvents as$event){
            $copy('max_channel_conversion_outbox',$event);$conversation=(int)$event['conversation_id'];
            if(!isset($channels[$conversation])){
                modeledValueBinding($p,$conversation,$event['platform']);$uid=(string)$event['external_user_id'];$chat=$event['platform']==='max'?'-'.$uid:$uid;
                $p->prepare('UPDATE customer_channels SET external_user_id=?,external_chat_id=? WHERE id=?')->execute([$uid,$chat,$conversation]);
                $p->prepare('UPDATE conversations SET external_chat_id=? WHERE id=?')->execute([$chat,$conversation]);$channels[$conversation]=true;
            }
            // Price calculation is a separate fixture: all carried values start with an owned unchanged audit.
            $basis='{"fixture":"unchanged_carried_native_value"}';$money=(int)$event['value_rub'];
            $p->prepare("INSERT INTO modeled_conversion_price_audit(outbox_id,action,original_value_rub,applied_value_rub,prior_reserved_rub,assessed_at_unix,basis_json,basis_sha256) VALUES(?,'unchanged',?,?,0,?,?,?)")
                ->execute([$event['id'],$money,$money,$now-1,$basis,hash('sha256',$basis)]);
        }

    $settings=$fetchRows("SELECT * FROM modeled_conversion_value_export_settings WHERE project_key='anytour'")[0];
    $outbox=$fetchRows('SELECT * FROM max_channel_conversion_outbox ORDER BY id');
    $audits=$fetchRows('SELECT * FROM modeled_conversion_price_audit');
    $ledgerRows=$fetchRows('SELECT * FROM modeled_conversion_known_yclid_relations');
    $statementClass=$p->getAttribute(PDO::ATTR_STATEMENT_CLASS);$id=(string)$row['id'];
    $replace=static function(string $table,array $r,string $key)use($p):void{
        $keys=array_values(array_diff(array_keys($r),[$key]));
        $p->prepare('UPDATE '.$table.' SET '.implode(',',array_map(static fn(string $k):string=>$k.'=?',$keys)).' WHERE '.$key.'=?')
            ->execute([...array_map(static fn(string $k):mixed=>$r[$k],$keys),$r[$key]]);
    };
    $insert=static function(string $table,array $r)use($p):void{
        $p->prepare('INSERT INTO '.$table.'('.implode(',',array_keys($r)).') VALUES('.implode(',',array_fill(0,count($r),'?')).')')->execute(array_values($r));
    };
    $restore=static function()use($p,$settings,$outbox,$audits,$ledgerRows,$replace,$insert):void{
        if($p->inTransaction())$p->rollBack();
        foreach($outbox as$r)$replace('max_channel_conversion_outbox',$r,'id');
        $replace('modeled_conversion_value_export_settings',$settings,'project_key');
        foreach(['modeled_conversion_price_audit'=>$audits,'modeled_conversion_known_yclid_relations'=>$ledgerRows]as$table=>$rows){
            $p->exec('DELETE FROM '.$table);foreach($rows as$r)$insert($table,$r);
        }
    };
    try{
        $p->setAttribute(PDO::ATTR_STATEMENT_CLASS,[ForecastV1ObservedStatement::class]);
        foreach(['positive','null_hold','expired','scope_revoke','price_hold','changed_proof','previous_receipt','claim_race','lost_commit','known_retry']as$case){
            $stage='tick_'.$case;$restore();ForecastV1ObservedStatement::$beforeRelationClaim=null;
            $p->prepare('UPDATE max_channel_conversion_outbox SET next_attempt_unix=? WHERE id<>?')->execute([$now+1000000,$id]);
            $p->prepare("UPDATE max_channel_conversion_outbox SET state='ready',client_id=?,tracker_record_id=?,tracker_observed_unix=?,identity_source_key='max_tracker',attempts=0,claimed_at_unix=NULL,claim_token=NULL,upload_id=NULL,upload_status=NULL,value_receipt_basis=NULL,value_api_accepted_at_unix=NULL,value_processed_at_unix=NULL,last_error='metrika_visit_unverified',value_ready_at_unix=COALESCE(value_ready_at_unix,?),next_attempt_unix=0 WHERE id=?")
                ->execute([$row['client_id'],$row['tracker_record_id'],$row['tracker_observed_unix'],$now-20,$id]);
            $p->exec('DELETE FROM modeled_conversion_known_yclid_relations');$insert('modeled_conversion_known_yclid_relations',$proof);
            $receipt=json_decode($settings['last_receipt_json'],true,32,JSON_THROW_ON_ERROR);$receipt['finite_visit_evidence']=$frame;
            $receipt['finite_visit_recovery']=false;$receipt['finite_counter_scope_evidence']=false;
            $receipt[ModeledConversionRetainedAppIdentityEvidence::RECEIPT_KEY]=false;$receipt[ModeledConversionRetainedTrackerIdentityEvidence::RECEIPT_KEY]=false;
            $receipt[ModeledConversionRetainedTrackerIdentityEvidence::KNOWN_KEYS[0]]=$known;$receipt['metrika_report']=['status'=>'unavailable'];
            $cursor=(string)$p->query("SELECT COALESCE(MAX(id),0) FROM modeled_conversion_profile_versions WHERE project_key='anytour'")->fetchColumn();
            $p->prepare("UPDATE modeled_conversion_value_export_settings SET mode='send',goal_id='678187097',goal_intent='verified',contract_version=?,profile_cursor=?,history_from_unix=NULL,last_receipt_json=? WHERE project_key='anytour'")
                ->execute([ModeledConversionValueContract::VERSION,$cursor,json_encode($receipt,JSON_THROW_ON_ERROR)]);
            $p->prepare('DELETE FROM modeled_conversion_price_audit WHERE outbox_id=?')->execute([$id]);
            $basis='{"fixture":"unchanged_existing_price"}';$value=(int)$row['value_rub'];
            $p->prepare("INSERT INTO modeled_conversion_price_audit(outbox_id,action,original_value_rub,applied_value_rub,prior_reserved_rub,assessed_at_unix,basis_json,basis_sha256) VALUES(?,'unchanged',?,?,0,?,?,?)")
                ->execute([$id,$value,$value,$now-1,$basis,hash('sha256',$basis)]);
            if($case==='null_hold')$p->prepare('UPDATE max_channel_conversion_outbox SET last_error=NULL WHERE id=?')->execute([$id]);
            if($case==='scope_revoke')$p->prepare("UPDATE max_channel_conversion_outbox SET identity_source_key='synthetic_other' WHERE id=?")->execute([$id]);
            if($case==='price_hold')$p->prepare('UPDATE modeled_conversion_price_audit SET applied_value_rub=? WHERE outbox_id=?')->execute([$value+1,$id]);
            if($case==='previous_receipt')$p->prepare("UPDATE max_channel_conversion_outbox SET upload_id='999998',upload_status='UPLOADED' WHERE id=?")->execute([$id]);
            if($case==='changed_proof'){
                $bad=json_decode($proof['r2_json'],true,32,JSON_THROW_ON_ERROR);$bad['response']['data'][0]['dimensions'][0]['name']='771122334455667788';
                $p->prepare('UPDATE modeled_conversion_known_yclid_relations SET r2_json=? WHERE outbox_id=?')->execute([json_encode($bad,JSON_THROW_ON_ERROR),$id]);
            }
            $raceFired=false;$fault->failure='none';$fault->commits=0;$fault->failCommit=PHP_INT_MAX;
            if($case==='claim_race')ForecastV1ObservedStatement::$beforeRelationClaim=static function()use($p,$id,&$raceFired):void{
                $raceFired=true;$p->prepare('UPDATE max_channel_conversion_outbox SET event_unix=event_unix+1 WHERE id=?')->execute([$id]);
            };
            if($case==='lost_commit')ForecastV1ObservedStatement::$beforeRelationClaim=static function()use($fault,&$raceFired):void{
                $raceFired=true;$fault->failure='commit_false';$fault->failCommit=$fault->commits+1;
            };
            $lookup=new class {
                public int $sourceCalls=0;
                public function lookup(string $counter,array $users):array{return [];}
                public function retainedClickRehydrate(array $selection,callable $before):?array{$this->sourceCalls++;throw new RuntimeException('unexpected_source_repeat');}
            };
            $api=new class($case) {
                public array $uploads=[];public int $ordinaryVisits=0;
                public function __construct(private string $case){}
                public function modeledValueGoalStatus():array{return ['id'=>'678187097'];}
                public function uploadModeledValue(array $wire,int $at):array{
                    $this->uploads[]=$wire;
                    if($this->case==='known_retry'&&count($this->uploads)===1)return ['outcome'=>'retry','reason'=>'transport_not_sent'];
                    return ['outcome'=>'accepted','upload_id'=>'991199','status'=>'UPLOADED'];
                }
                public function modeledAttributionVisits(string $cid,int $event,int $now):array{$this->ordinaryVisits++;return ['status'=>'unavailable','visits'=>[]];}
                public function modeledValueReport(int $activated,int $now):array{return ['status'=>'unavailable'];}
                public function poll(string $id):?array{return null;}
                public function findUnknown(array $row):?array{return null;}
            };
            $tick=$case==='expired'?$now+2*86400:$now;
            $result=ModeledConversionValueExport::tick($case==='lost_commit'?$fault:$p,$lookup,$api,$tick,static fn(string $chat):bool=>true,static fn():int=>$tick+1);
            $q=$p->prepare('SELECT * FROM max_channel_conversion_outbox WHERE id=?');$q->execute([$id]);$after=$q->fetch(PDO::FETCH_ASSOC);$q->closeCursor();
            if(in_array($case,['positive','null_hold','known_retry'],true)){
                $wire=$api->uploads[0]??null;$internal=ModeledConversionKnownYclidRelation::dispatchCandidate($proof,$known,$frame,$row,$settings,ModeledConversionProfiles::assessmentNamespace(),$now);
                $same=$wire!==null&&$internal!==null&&$wire['client_id']===$internal['client_id']
                    &&(string)$wire['id']===$id&&(string)$wire['event_unix']===(string)$row['event_unix']
                    &&(int)$wire['value_rub']===(int)$row['value_rub']&&$wire['target']===$row['target']
                    &&$wire['transport_target']===$row['transport_target']&&(string)$wire['value_profile_id']===(string)$row['value_profile_id']
                    &&$wire['tracker_record_id']===$row['tracker_record_id']&&(string)$wire['tracker_observed_unix']===(string)$row['tracker_observed_unix'];
                $pass=$result['status']===($case==='known_retry'?'degraded':'running')
                    &&($case!=='known_retry'||($after['last_error']==='transport_not_sent'&&(int)$after['attempts']===1))&&count($api->uploads)===1&&$same&&$api->ordinaryVisits===0&&$lookup->sourceCalls===0
                    &&$after['client_id']===($internal['client_id']??null)&&($case==='known_retry'?$after['state']==='ready':$after['state']==='accepted');
                if(!$pass){
                    $dq=$p->query("SELECT mode,goal_intent,last_receipt_json FROM modeled_conversion_value_export_settings WHERE project_key='anytour'");
                    $ds=$dq->fetch(PDO::FETCH_ASSOC);$dq->closeCursor();$dr=json_decode($ds['last_receipt_json']??'null',true);
                    $sameFields=[];foreach(['id','event_unix','value_rub','target','transport_target','value_profile_id','tracker_record_id','tracker_observed_unix']as$key)
                        $sameFields[$key]=$wire!==null&&($key==='tracker_record_id'?$wire[$key]===$row[$key]:(string)$wire[$key]===(string)$row[$key]);
                    $hold=$after['last_error']??null;if($hold!==null&&(!is_string($hold)||preg_match('/\A[a-z_]{0,64}\z/D',$hold)!==1))$hold='other';
                    fwrite(STDERR,'SYNTHETIC_KNOWN_RELATION_TICK '.json_encode(['case'=>$case,'tick_status'=>$result['status'],
                        'errors'=>$dr['errors']??null,'failure_phase'=>$dr['last_recorded_failure']['phase']??null,'failure_category'=>$dr['last_recorded_failure']['category']??null,
                        'state'=>$after['state'],'hold'=>$hold,'attempts'=>(int)$after['attempts'],'ready_clock_known'=>$after['value_ready_at_unix']!==null,
                        'uploads'=>count($api->uploads),'ordinary_visits'=>$api->ordinaryVisits,'source_calls'=>$lookup->sourceCalls,
                        'internal_candidate'=>$internal!==null,'same_fields'=>$sameFields,'same_derived_cid'=>$wire!==null&&$internal!==null&&$wire['client_id']===$internal['client_id'],
                        'receipt_bytes'=>is_string($ds['last_receipt_json']??null)?strlen($ds['last_receipt_json']):null,
                        'receipt_json_valid'=>is_array($dr),'goal_verified'=>($ds['goal_intent']??null)==='verified','mode_send'=>($ds['mode']??null)==='send'],JSON_THROW_ON_ERROR).PHP_EOL);
                }
                $check('actual tick claims one identical native event with the exact derived CID '.$case,$pass);
                $next=$case==='known_retry'?$tick+1800:$tick+300;
                ModeledConversionValueExport::tick($p,$lookup,$api,$next,static fn(string $chat):bool=>true,static fn():int=>$next+1);
                $check('accepted native event never uploads twice while proven-not-sent alone may retry '.$case,
                    count($api->uploads)===($case==='known_retry'?2:1)&&$lookup->sourceCalls===0);
                if($case==='known_retry'){
                    $q->execute([$id]);$retried=$q->fetch(PDO::FETCH_ASSOC);$q->closeCursor();
                    ModeledConversionValueExport::tick($p,$lookup,$api,$next+300,static fn(string $chat):bool=>true,static fn():int=>$next+301);
                    $check('the proven-not-sent retry can receive one native acceptance then never upload again',
                        $retried['state']==='accepted'&&(int)$retried['attempts']===2&&count($api->uploads)===2);
                }
            }else{
                $check('actual financial tick rejects '.$case.' without source replay or upload',count($api->uploads)===0&&$lookup->sourceCalls===0);
                if($case==='claim_race')$check('the final immutable event CAS rejects a change after proof revalidation',$raceFired&&$after['state']==='ready'&&$after['client_id']===$row['client_id']);
                if($case==='lost_commit'){
                    $check('an unacknowledged committed claim grants no POST and stays claimed for ordinary UNKNOWN recovery',
                        $raceFired&&$after['state']==='uploading'&&(int)$after['attempts']===1&&is_string($after['claim_token'])&&$after['upload_id']===null);
                    ForecastV1ObservedStatement::$beforeRelationClaim=null;$fault->failure='none';
                    ModeledConversionValueExport::tick($p,$lookup,$api,$tick+600,static fn(string $chat):bool=>true,static fn():int=>$tick+601);
                    $q->execute([$id]);$unknown=$q->fetch(PDO::FETCH_ASSOC);$q->closeCursor();
                    $check('lost claim acknowledgement recovers conservatively without another POST',$unknown['state']==='unknown'&&$api->uploads===[]);
                }
            }
        }
    }catch(Throwable $e){$primary=$e;$trace($stage,$e);throw $e;}
    finally{
        ForecastV1ObservedStatement::$beforeRelationClaim=null;$fault->failure='commit_throw';$fault->commits=0;$fault->failCommit=1;
        try{
            if($p->inTransaction())$p->rollBack();
            $p->exec('DELETE FROM modeled_conversion_known_yclid_relations');foreach($ledgerRows as$r)$insert('modeled_conversion_known_yclid_relations',$r);
            $p->setAttribute(PDO::ATTR_STATEMENT_CLASS,$statementClass);
        }catch(Throwable $cleanup){$trace('ledger_cleanup',$cleanup);if($primary===null)throw $cleanup;}
    }
    }catch(Throwable $e){if($primary===null){$primary=$e;$trace($stage,$e);}throw $e;}
    finally{
        try{
            if($p->inTransaction())$p->rollBack();
            if($ownsSchema)foreach($core as$table)$p->exec('DROP TABLE IF EXISTS '.$table);
            foreach(array_reverse($renamed)as$table)$p->exec('ALTER TABLE krd_'.$table.' RENAME TO '.$table);
        }catch(Throwable $cleanup){$trace('schema_cleanup',$cleanup);if($primary===null)throw $cleanup;}
    }
}

/** The saved-only owner reader copies two opaque caches, never the full private source/report capsules. */
function forecastKnownRelationOutcomeReaderAssertions(PDO $p,callable $check,array $frame,array $rows,array $plans,array $positive,int $now):void
{
    $ns=ModeledConversionProfiles::assessmentNamespace();$snapshot=$p->query('SELECT * FROM modeled_conversion_known_yclid_relations')->fetchAll(PDO::FETCH_ASSOC);
    $statementClass=$p->getAttribute(PDO::ATTR_STATEMENT_CLASS);
    $insert=static function(array $r)use($p):void{
        $p->prepare('INSERT INTO modeled_conversion_known_yclid_relations('.implode(',',array_keys($r)).') VALUES('.implode(',',array_fill(0,count($r),'?')).')')->execute(array_values($r));
    };
    $reset=static function()use($p,$insert,$positive):void{$p->exec('DELETE FROM modeled_conversion_known_yclid_relations');$insert($positive);};
    $method=new ReflectionMethod(ModeledConversionValueDeliveryReader::class,'retainedRelationOutcomes');$bound=[];
    foreach($rows as$r)$bound[ModeledConversionValueIdentityEvidence::reference($r,$ns)]=$r;
    $read=static function(?callable $auth=null,?ModeledConversionStatusBudget $budget=null)use($p,$bound,$method,$frame,$ns,$now):array{
        $reader=new ModeledConversionValueDeliveryReader($p,$auth??static function():void{},$budget??new ModeledConversionStatusBudget());
        (new ReflectionProperty($reader,'boundFiniteRows'))->setValue($reader,$bound);
        return $method->invoke($reader,$frame,['available'=>true],$ns,$now);
    };
    $saveCache=static function(array $v)use($p,$positive):void{$p->prepare('UPDATE modeled_conversion_known_yclid_relations SET outcome_json=? WHERE outbox_id=?')->execute([json_encode($v,JSON_THROW_ON_ERROR),$positive['outbox_id']]);};
    try{
        $p->setAttribute(PDO::ATTR_STATEMENT_CLASS,[ForecastV1ObservedStatement::class]);$reset();
        $foreign=$positive;$foreign['outbox_id']='999999';$foreign['frame_digest']=str_repeat('e',64);$foreign['event_ref']=str_repeat('f',64);
        $foreign['outcome_json']='{"client_id":"PRIVATE_UNRELATED_CACHE_MUST_NOT_LEAK"}';$insert($foreign);
        ForecastV1ObservedStatement::$relationOutcomeQueries=[];ForecastV1ObservedStatement::$relationOutcomeCopies=[];$auth=0;
        $before=$p->query('SELECT * FROM modeled_conversion_known_yclid_relations ORDER BY outbox_id')->fetchAll(PDO::FETCH_ASSOC);
        $got=$read(static function()use(&$auth):void{$auth++;});$json=json_encode($got,JSON_THROW_ON_ERROR);
        ForecastV1ObservedStatement::$lastRelationOutcomePacket=$got;
        $queries=ForecastV1ObservedStatement::$relationOutcomeQueries;$copies=ForecastV1ObservedStatement::$relationOutcomeCopies;
        $bindings=array_map(static fn(array $v):mixed=>$v[0],$queries[0]['bindings']??[]);
        $noRaw=true;foreach($copies as$copy)foreach($copy as$r)if(array_intersect(array_keys($r),['plan_json','source_json','r1_json','r2_json','enrichment_json']))$noRaw=false;
        $check('saved-only relation reader authorizes before and after one exact two-PK bounded query',
            $got['available']&&count($got['rows'])===2&&$got['rows'][0]['status']==='complete_base_window_assessed'
            &&$got['rows'][1]['status']==='not_recorded'&&$auth===2&&count($queries)===1
            &&str_contains($queries[0]['sql'],'r.outbox_id IN (?,?)')&&str_contains($queries[0]['sql'],'LIMIT 3')
            &&array_slice(array_values($bindings),-2)===[(string)$rows[1]['id'],(string)$rows[2]['id']]
            &&($p->getAttribute(PDO::ATTR_DRIVER_NAME)!=='mysql'||str_contains($queries[0]['sql'],'MAX_EXECUTION_TIME(250)')));
        $check('reader output hides raw capsules identifiers money and unrelated cached rows without changing saved data',
            $noRaw&&!str_contains($json,'PRIVATE_UNRELATED_CACHE_MUST_NOT_LEAK')&&!str_contains($json,'119988776655443322')&&!str_contains($json,'991122334455667788')
            &&!isset($got['rows'][0]['client_id'],$got['rows'][0]['outbox_id'],$got['rows'][0]['value_rub'])
            &&$got['rows'][0]['phases_admitted']===['source'=>true,'recorded_day_relation'=>true,'visit_base'=>true,'visit_sources'=>true]
            &&$got['rows'][0]['intent_at_utc']!==null&&$got['rows'][0]['phase_requested_at_utc']['visit_base']!==null
            &&$before===$p->query('SELECT * FROM modeled_conversion_known_yclid_relations ORDER BY outbox_id')->fetchAll(PDO::FETCH_ASSOC));
        $reset();$cache=json_decode($positive['outcome_json'],true,16,JSON_THROW_ON_ERROR);
        foreach(['raw_identity','financial_permission','wrong_case','wrong_clock']as$case){
            $bad=$cache;
            if($case==='raw_identity')$bad['client_id']='PRIVATE_DERIVED_CID_MUST_NOT_LEAK';
            if($case==='financial_permission')$bad['financial_permission']=true;
            if($case==='wrong_case')$bad['case_index']=2;
            if($case==='wrong_clock')$bad['observed_at_utc']=gmdate('Y-m-d\TH:i:s\Z',(int)$positive['intent_at_unix']-1);
            $saveCache($bad);$denied=$read();
            $check('tampered compact outcome is rejected rather than echoed '.$case,
                $denied['rows'][0]['available']===false&&$denied['rows'][0]['reason']==='invalid_saved_outcome_cache'
                &&!str_contains(json_encode($denied,JSON_THROW_ON_ERROR),'PRIVATE_DERIVED_CID_MUST_NOT_LEAK'));
        }
        foreach([-30,30]as$skew){
            $valid=$cache;$valid['source_checked_at_utc']=gmdate('Y-m-d\TH:i:s\Z',(int)$positive['intent_at_unix']+$skew);
            $saveCache($valid);$v=$read();
            $check('cached historical source clock keeps the signed protocol tolerance without changing the visit window '.$skew,
                $v['rows'][0]['available']===true&&$v['rows'][0]['source_checked_at_utc']===$valid['source_checked_at_utc']
                &&$v['rows'][0]['window_days']===10&&$v['rows'][0]['financial_permission']===false);
        }
        $reset();$p->prepare('UPDATE modeled_conversion_known_yclid_relations SET completed_at_unix=completed_at_unix+1 WHERE outbox_id=?')->execute([$positive['outbox_id']]);
        $stale=$read();$check('raw phase mutation invalidates the cached projection in the actual reader',
            $stale['rows'][0]['status']==='saved_outcome_unavailable'&&$stale['rows'][0]['reason']==='cache_missing_or_invalidated');
        $reset();$crash=$positive;$crash['outbox_id']=$plans[2]['outbox_id'];$crash['case_index']=2;$crash['event_ref']=$plans[2]['event_ref'];
        $crash['plan_json']=json_encode($plans[2],JSON_THROW_ON_ERROR);$crash['state']='intent';$crash['intent_sha256']=str_repeat('b',64);
        foreach(['r1_request_sha256','r2_request_sha256','enrichment_request_sha256','r1_requested_at_unix','r2_requested_at_unix','enrichment_requested_at_unix','source_json','r1_json','r2_json','enrichment_json','completed_at_unix','outcome_json','outcome_basis_sha256']as$k)$crash[$k]=null;
        $insert($crash);$unknown=$read();
        $check('crash with a consumed source intent remains distinct from absent evidence and exposes only saved phase flags',
            $unknown['rows'][1]['status']==='consumed_unknown'&&$unknown['rows'][1]['reason']==='cache_missing_or_invalidated'
            &&$unknown['rows'][1]['phases_admitted']===['source'=>true,'recorded_day_relation'=>false,'visit_base'=>false,'visit_sources'=>false]
            &&$unknown['rows'][1]['intent_at_utc']!==null&&$unknown['rows'][1]['completed_at_utc']===null&&!$unknown['financial_permission']);
        ForecastV1ObservedStatement::$relationOutcomeQueries=[];
        $low=new ModeledConversionStatusBudget();$low->consumeBytes(ModeledConversionStatusBudget::MAX_BYTES-12000);
        $tooSmall=$read(null,$low);$slow=new ModeledConversionStatusBudget(0.0,static fn():float=>4.8);$tooLate=$read(null,$slow);
        $check('low shared byte or time headroom omits outcomes before any optional SQL',
            $tooSmall['reason']==='read_budget_headroom'&&$tooLate['reason']==='read_budget_headroom'&&ForecastV1ObservedStatement::$relationOutcomeQueries===[]);
        $revoked=false;$calls=0;
        try{$read(static function()use(&$calls):void{if(++$calls===2)throw new RuntimeException('synthetic_outcome_access_revoked');});}
        catch(RuntimeException $e){$revoked=$e->getMessage()==='synthetic_outcome_access_revoked';}
        $check('authorization revocation after the saved-only read is never downgraded to a successful cache result',$revoked&&$calls===2);
        $p->exec('ALTER TABLE modeled_conversion_known_yclid_relations RENAME TO modeled_conversion_known_yclid_relations_missing_fixture');
        try{$missing=$read();$check('missing unapplied outcome schema is explicit unavailable without any fallback source read',$missing['reason']==='outcome_storage_unavailable');}
        finally{$p->exec('ALTER TABLE modeled_conversion_known_yclid_relations_missing_fixture RENAME TO modeled_conversion_known_yclid_relations');}
    }finally{
        $p->exec('DELETE FROM modeled_conversion_known_yclid_relations');foreach($snapshot as$r)$insert($r);
        $p->setAttribute(PDO::ATTR_STATEMENT_CLASS,$statementClass);
        ForecastV1ObservedStatement::$relationOutcomeQueries=[];ForecastV1ObservedStatement::$relationOutcomeCopies=[];
    }
}

function forecastKnownYclidRelationAssertions(PDO $p,callable $check,array $frame,int $now):void
{
    if($p->getAttribute(PDO::ATTR_DRIVER_NAME)==='sqlite')$p->sqliteCreateFunction('known_relation_sha256',static fn(string $bytes):string=>hash('sha256',$bytes),1);
    $ns=ModeledConversionProfiles::assessmentNamespace();$when=$now+40;$raw='119988776655443322';$oldHash=str_repeat('d',64);
    $before=$p->query("SELECT * FROM modeled_conversion_value_export_settings WHERE project_key='anytour'")->fetch(PDO::FETCH_ASSOC);
    $restore=[];$plans=[];$saved=[];$rows=[];$tuples=[];
    $p->exec('DROP TABLE IF EXISTS modeled_conversion_known_yclid_relations');
    if($p->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql')$p->exec(file_get_contents(dirname(__DIR__).'/migrations/107_retained_known_yclid_relations.sql'));
    else $p->exec("CREATE TABLE modeled_conversion_known_yclid_relations(purpose TEXT NOT NULL CHECK(purpose='retained_known_yclid_two_stage_v1'),outbox_id INTEGER NOT NULL,case_index INTEGER NOT NULL CHECK(case_index IN(1,2)),namespace TEXT,frame_digest TEXT,event_ref TEXT,intent_at_unix INTEGER,intent_sha256 TEXT,state TEXT CHECK(state IN('intent','complete')),source_request_sha256 TEXT,r1_request_sha256 TEXT,r2_request_sha256 TEXT,r1_requested_at_unix INTEGER,r2_requested_at_unix INTEGER,enrichment_request_sha256 TEXT,enrichment_requested_at_unix INTEGER,plan_json TEXT NOT NULL,source_json TEXT,r1_json TEXT,r2_json TEXT,enrichment_json TEXT,completed_at_unix INTEGER,outcome_json TEXT,outcome_basis_sha256 TEXT,PRIMARY KEY(purpose,outbox_id),UNIQUE(purpose,frame_digest,case_index))");
    try{
        $observations=[];foreach(ModeledConversionValueVisitRecovery::originals($frame,$ns,$when)as$ref=>$old)$observations[]=[$ref,$when-10,$when-10,7,null,0];
        $pair=ModeledConversionFixedPairDecisionEvidence::merge(null,$frame,$ns,$observations,$when-5);
        foreach([1,2]as$i){
            $q=$p->prepare('SELECT * FROM max_channel_conversion_outbox WHERE id=?');$q->execute([$frame['r'][$i][1]]);$row=$q->fetch(PDO::FETCH_ASSOC);$restore[$i]=$row;
            $b=ModeledConversionRetainedTrackerIdentityEvidence::knownBinding($frame,$row,$ns,$when);
            $p->prepare("UPDATE max_channel_conversion_outbox SET state='ready',identity_source_key='max_tracker',client_id=?,tracker_record_id=?,tracker_observed_unix=?,attempts=0,claimed_at_unix=NULL,last_error='metrika_visit_unverified',upload_id=NULL,upload_status=NULL,value_receipt_basis=NULL,claim_token=NULL,value_api_accepted_at_unix=NULL,value_processed_at_unix=NULL WHERE id=?")
                ->execute([$b['held_client_id'],$b['held_record_id'],$b['held_source_at'],$row['id']]);
            $q->execute([$row['id']]);$rows[$i]=$q->fetch(PDO::FETCH_ASSOC);$ctx=ModeledConversionRetainedTrackerIdentityEvidence::knownContext($frame,$rows[$i],$ns,$when);
            $year=(int)gmdate('Y',$ctx['event_at_unix']);$at=gmmktime(23,30,0,7,1,$year);if($at>$ctx['event_at_unix'])$at=gmmktime(23,30,0,7,1,$year-1);$tuple=[(string)(7000+$i),$rows[$i]['external_user_id'],'',$raw,
                (new DateTimeImmutable('@'.$at))->setTimezone(new DateTimeZone('Europe/London'))->format('Y-m-d H:i:s'),'111',1];
            $fp=hash('sha256',json_encode($tuple,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR));
            $candidate=hash('sha256',json_encode([MaxTrackerLookupProtocol::RETAINED_CLICK_HEADER,$oldHash,$ctx,2,$raw,$tuple[0],$fp,$at],JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR));
            $intent=ModeledConversionRetainedTrackerIdentityEvidence::knownIntent($frame,$rows[$i],$ns,$oldHash,$when-5,$pair);
            $saved[$i]=ModeledConversionRetainedTrackerIdentityEvidence::knownComplete($intent,$frame,$rows[$i],$ns,
                ['request_sha256'=>$oldHash,'result'=>[1,$when-2,1,0,2,$candidate,$tuple[0],$fp,$at],'received_at_unix'=>$when-1],$when);
            $plans[$i]=ModeledConversionRetainedTrackerIdentityEvidence::knownRehydratePlan($saved[$i],$frame,$rows[$i],$ns,$when);$tuples[$i]=$tuple;
        }
        $receipt=['finite_visit_evidence'=>$frame];
        foreach([1,2]as$i)$receipt[ModeledConversionRetainedTrackerIdentityEvidence::KNOWN_KEYS[$i-1]]=$saved[$i];
        $p->prepare("UPDATE modeled_conversion_value_export_settings SET mode='send',counter_id='98615635',goal_id='678187097',goal_intent='verified',contract_version=?,last_receipt_json=? WHERE project_key='anytour'")
            ->execute([ModeledConversionValueContract::VERSION,json_encode($receipt,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)]);
        $q=$p->prepare('SELECT * FROM max_channel_conversion_outbox WHERE id=?');$q->execute([$frame['r'][0][1]]);$missing=$q->fetch(PDO::FETCH_ASSOC);$q->closeCursor();
        $check('only the two consumed original kind2 selections yield a private rehydration plan; case0 is excluded',
            $plans[1]!==null&&$plans[2]!==null&&$plans[1]['case_index']===1&&$plans[2]['case_index']===2
            &&ModeledConversionRetainedTrackerIdentityEvidence::knownRehydratePlan($saved[1],$frame,$missing,$ns,$when)===null);
        $ledger=new ModeledConversionKnownYclidRelation($p);
        $restoreLive=static function()use($p,$rows):void{
            foreach($rows as$r){
                $keys=['state','identity_source_key','client_id','tracker_record_id','tracker_observed_unix','external_user_id','event_unix','attempts','claimed_at_unix','last_error','upload_id','upload_status','value_receipt_basis','claim_token','value_api_accepted_at_unix','value_processed_at_unix'];
                $q=$p->prepare('UPDATE max_channel_conversion_outbox SET '.implode(',',array_map(static fn(string $k):string=>$k.'=?',$keys)).' WHERE id=?');
                $q->execute([...array_map(static fn(string $k):mixed=>$r[$k],$keys),$r['id']]);
            }
            $p->exec("UPDATE modeled_conversion_value_export_settings SET mode='send',goal_intent='verified' WHERE project_key='anytour'");
        };
        $reset=static function()use($p,$restoreLive):void{$restoreLive();$p->exec('DELETE FROM modeled_conversion_known_yclid_relations');};
        $fault=new class($p) extends PDO {
            public string $failure='commit_throw';public int $commits=0;public int $failCommit=1;
            public function __construct(private PDO $inner){}
            public function getAttribute(int $a):mixed{return $this->inner->getAttribute($a);}
            public function inTransaction():bool{return $this->inner->inTransaction();}
            public function beginTransaction():bool{return $this->failure==='begin_false'?false:$this->inner->beginTransaction();}
            public function rollBack():bool{return $this->inner->rollBack();}
            public function prepare(string $q,array $o=[]):PDOStatement|false{return $this->inner->prepare($q,$o);}
            public function query(string $q,?int $m=null,mixed ...$a):PDOStatement|false{return $m===null?$this->inner->query($q):$this->inner->query($q,$m,...$a);}
            public function commit():bool{
                $ok=$this->inner->commit();$this->commits++;
                if($this->commits!==$this->failCommit)return $ok;
                if($this->failure==='commit_throw')throw new RuntimeException('synthetic lost commit acknowledgement');
                return $this->failure==='commit_false'?false:$ok;
            }
        };
        $faultLedger=new ModeledConversionKnownYclidRelation($fault);
        $q1=ModeledConversionKnownYclidRelation::r1Query($plans[1],$raw,$when);
        $check('R1 stays scoped to the recorded UTC day even though its London and counter+03 calendars differ',
            $q1!==null&&$q1['date1']===gmdate('Y-m-d',$plans[1]['selection']['selected_recorded_at_unix'])
            &&$q1['date1']!==substr($tuples[1][4],0,10)&&$q1['timezone']==='+00:00');
        preg_match("/ym:s:startURL=~'([^']+)'/",$q1['filters'],$match);$regex='~'.$match[1].'~D';
        foreach(['https://example.test/?yclid='.$raw,'https://example.test/?x=1&yclid='.$raw.'&y=2','https://example.test/?x=1&yclid='.$raw]as$url)
            $check('R1 regex accepts first middle and last exact query pairs',preg_match($regex,$url)===1);
        foreach(['https://example.test/path&yclid='.$raw,'https://example.test/#?yclid='.$raw,'https://example.test/?yclid='.$raw.'0',
            'https://example.test/?yclid=991122&other='.$raw,'https://example.test/?other=yclid&value='.$raw]as$url)
            $check('R1 regex rejects path fragment suffix and mismatched parameter pairs',preg_match($regex,$url)===0);

        $calls=[];$committed=[];$currentIntent=null;$derived='991122334455667788';$visits=[[$when+5-9*86400,1]];
        $sourceMode='direct';$relationMode='one';$counterZone='+03:00';
        $api=new MetrikaOfflineClient('98615635','fixture-only',static function(string $method,string $url,mixed $body)use(&$calls,&$committed,&$currentIntent,&$derived,&$visits,&$sourceMode,&$relationMode,$counterZone,$ledger):array{
            if($method!=='GET'||parse_url($url,PHP_URL_PATH)!=='/stat/v1/data'||$body!==null)throw new RuntimeException('unexpected_metadata_or_write');
            parse_str((string)parse_url($url,PHP_URL_QUERY),$q);$dims=explode(',',$q['dimensions']);$stage=count($dims)===1?'r1':(count($dims)===2?'base':'enrichment');$calls[]=$stage;
            $r=$ledger->read($currentIntent['outbox_id']);$committed[]=is_string($r['source_json'])
                &&($stage==='r1'?is_string($r['r1_request_sha256']):($stage==='base'?is_string($r['r2_request_sha256']):is_string($r['enrichment_request_sha256'])&&is_string($r['r2_json'])));
            $zone=new DateTimeZone($q['timezone']??$counterZone);
            if($stage==='r1'){
                if($relationMode==='unavailable')return ['http'=>503,'error'=>0,'body'=>'{}'];
                $data=[['dimensions'=>[['name'=>$derived]],'metrics'=>[3]]];
                if($relationMode==='zero')$data=[];
                if($relationMode==='ambiguous')$data[]=['dimensions'=>[['name'=>'771122334455667788']],'metrics'=>[1]];
            }
            else{
                preg_match("/clientID=='([0-9]+)'/",$q['filters'],$m);$data=[];
                foreach($visits as[$at,$n]){
                    $d=[['name'=>$m[1]],['name'=>(new DateTimeImmutable('@'.$at))->setTimezone($zone)->format('Y-m-d H:i:s')]];
                    if($stage==='enrichment'){$d[]=['id'=>$sourceMode==='direct'?'ad':($sourceMode==='unknown'?null:'direct')];$d[]=['id'=>$sourceMode==='direct'?'ya_direct':null];}
                    $data[]=['dimensions'=>$d,'metrics'=>[$n]];
                }
                if($stage==='enrichment'&&$sourceMode==='empty')$data=[];
                if($stage==='enrichment'&&$sourceMode==='omit_latest')array_shift($data);
            }
            $d=['query'=>['ids'=>[98615635],'metrics'=>['ym:s:visits'],'dimensions'=>$dims,'filters'=>$q['filters'],'timezone'=>$q['timezone']??$counterZone,
                'date1'=>$q['date1'],'date2'=>$q['date2'],'limit'=>(int)$q['limit'],'offset'=>(int)$q['offset']],
                'data'=>$data,'sampled'=>false,'total_rows_rounded'=>false,'contains_sensitive_data'=>false,'total_rows'=>count($data),'data_lag'=>0];
            return ['http'=>200,'error'=>0,'body'=>json_encode($d,JSON_THROW_ON_ERROR)];
        });
        $sourceHash=str_repeat('a',64);$source=['request_sha256'=>$sourceHash,'result'=>[1,$when+1,$tuples[1]],'received_at_unix'=>$when+2];
        $start=static function()use($ledger,$saved,$frame,$rows,$ns,$when,$sourceHash,&$currentIntent):array{
            $currentIntent=$ledger->begin($saved[1],$frame,$rows[1],$ns,$when);
            if($currentIntent===null||!$ledger->admitSource($currentIntent,$sourceHash,$when+1))throw new RuntimeException('fixture_intent');
            return $currentIntent;
        };
        $p->exec("UPDATE modeled_conversion_value_export_settings SET goal_intent='creating' WHERE project_key='anytour'");
        $check('unverified goal refuses even the initial intent',$ledger->begin($saved[1],$frame,$rows[1],$ns,$when)===null);$restoreLive();
        $intent=$start();
        $check('refused R1 makes no provider request',$api->retainedYclidClickDayClients($plans[1],$raw,$when,static fn():bool=>false,static fn():int=>$when+3)===null&&$calls===[]);
        $r1=$api->retainedYclidClickDayClients($plans[1],$raw,$when,static fn(string $h,int $at):bool=>$ledger->admitR1($intent,$source,$h,$at,$when+3),static fn():int=>$when+3);
        $base=$api->retainedIdentityVisitBase($plans[1],$source,$r1,$when,static fn(string $h,int $at):bool=>$ledger->admitR2($intent,$source,$r1,$h,$at,$when+5),static fn():int=>$when+5);
        $q2=ModeledConversionKnownYclidRelation::r2Query($plans[1],$source,$r1,$when,$when+5);
        $qe=ModeledConversionKnownYclidRelation::enrichmentQuery($plans[1],$source,$r1,$when,$base,$when+7);
        $check('base and optional source query share exactly one CID and the frozen base window',
            $q2!==null&&$qe!==null&&$q2['dimensions']==='ym:s:clientID,ym:s:dateTime'
            &&array_diff_assoc($q2,$qe)===['dimensions'=>'ym:s:clientID,ym:s:dateTime']
            &&str_contains($q2['filters'],"clientID=='".$derived."'")&&!str_contains($q2['filters'],"clientID=='".$plans[1]['held_client_id']."'"));
        $enriched=$api->retainedIdentityVisitSources($plans[1],$source,$r1,$base,$when,
            static fn(string $h,int $at):bool=>$ledger->admitEnrichment($intent,$base,$h,$at,$when+7),static fn():int=>$when+7);
        $p->prepare("UPDATE max_channel_conversion_outbox SET state='accepted',upload_id='99002',value_api_accepted_at_unix=? WHERE id=?")->execute([$when+7,$rows[1]['id']]);
        $check('full native JSON base commits before one optional GET and completion survives financial progress',
            $calls===['r1','base','enrichment']&&!in_array(false,$committed,true)&&$ledger->completeEnrichment($intent,$enriched,$when+8)
            &&!$ledger->completeEnrichment($intent,$enriched,$when+9));
        $stored=$ledger->read($intent['outbox_id']);$view=ModeledConversionKnownYclidRelation::projection($stored,$saved[1],$frame,$rows[1],$ns,$when+9);$json=json_encode($view,JSON_THROW_ON_ERROR);
        $check('public projection retains the complete base with proven Direct9 enrichment but no identity or financial authority',
            $view['available']&&$view['eligible_as_of_check']&&$view['source_class']==='yandex_direct'&&$view['coverage_scope']==='complete_unfiltered_base_query_rows'
            &&$view['historical']&&$view['as_of_checked']&&!$view['financial_permission']
            &&!str_contains($json,$raw)&&!str_contains($json,$derived)&&!str_contains($json,$plans[1]['held_client_id'])&&!isset($view['value_rub'],$view['filters']));
        $restoreLive();$settings=$p->query("SELECT * FROM modeled_conversion_value_export_settings WHERE project_key='anytour'")->fetch(PDO::FETCH_ASSOC);
        $candidate=ModeledConversionKnownYclidRelation::dispatchCandidate($stored,$saved[1],$frame,$rows[1],$settings,$ns,$when+9);
        $check('optional outcome cache absence never removes the independently reconstructible complete private proof',
            $stored['outcome_json']===null&&$stored['outcome_basis_sha256']===null&&$candidate!==null);
        $check('private pure candidate returns only exact CID full base proof and original binding',
            $candidate!==null&&$candidate['client_id']===$derived&&$candidate['outbox_id']===(string)$rows[1]['id']
            &&$candidate['event_ref']===$plans[1]['event_ref']&&count($candidate)===5
            &&ModeledConversionAttributionWindow::assess($candidate['visit_report'],$plans[1]['event_unix'],$when+9)['allowed']);
        $check('candidate rechecks actual now without issuing a new report',
            ModeledConversionKnownYclidRelation::dispatchCandidate($stored,$saved[1],$frame,$rows[1],$settings,$ns,$when+2*86400)===null);
        foreach(['unknown','uploading','accepted','processed','unmatched','failed']as$state){$bad=$rows[1];$bad['state']=$state;
            $check('no private candidate exists for terminal uncertain or claimed states '.$state,ModeledConversionKnownYclidRelation::dispatchCandidate($stored,$saved[1],$frame,$bad,$settings,$ns,$when+9)===null);}
        $retry=$rows[1];$retry['client_id']=$derived;$retry['attempts']=1;$retry['claimed_at_unix']=$when+8;$retry['last_error']='transport_not_sent';
        $check('known nonacceptance retry reuses the same proof without identity or event replacement',
            ModeledConversionKnownYclidRelation::dispatchCandidate($stored,$saved[1],$frame,$retry,$settings,$ns,$when+9)['proof_sha256']===$candidate['proof_sha256']);
        $retry['last_error']='upload_outcome_unknown';
        $check('UNKNOWN is never turned into a retry candidate',ModeledConversionKnownYclidRelation::dispatchCandidate($stored,$saved[1],$frame,$retry,$settings,$ns,$when+9)===null);

        // Omitted/empty source rows cannot erase or replace the complete base.
        $extra=$enriched;$extra['response']['data'][0]['dimensions'][1]['name']=gmdate('Y-m-d H:i:s',$visits[0][0]-1);
        $amb=$enriched;$amb['response']['data'][]=$amb['response']['data'][0];$amb['response']['total_rows']=2;
        $empty=$enriched;$empty['response']['data']=[];$empty['response']['total_rows']=0;
        foreach(['extra'=>$extra,'ambiguous'=>$amb,'empty'=>$empty]as$label=>$cap){
            $proof=ModeledConversionKnownYclidRelation::validateEnrichment($plans[1],$source,$r1,$when,$base,$cap);
            $check('source '.$label.' preserves the full unknown3 base',
                count($proof['base_report']['visits'])===1&&!$proof['assessment']['allowed']&&$proof['assessment']['source_class']==='other_or_unknown');
        }
        $two=$base;$latest=$base['response']['data'][0];$latest['dimensions'][1]['name']=gmdate('Y-m-d H:i:s',$when+5-4*86400);
        array_unshift($two['response']['data'],$latest);$two['response']['total_rows']=2;
        $proof=ModeledConversionKnownYclidRelation::validateEnrichment($plans[1],$source,$r1,$when,$two,$enriched);
        $check('an older Direct9 subset cannot hide a later unknown4 base visit',
            count($proof['base_report']['visits'])===2&&!$proof['assessment']['allowed']&&$proof['assessment']['visit_at']===$when+5-4*86400);
        $other=$enriched;$other['response']['data'][0]['dimensions'][2]=['id'=>'direct'];$other['response']['data'][0]['dimensions'][3]=['id'=>null];
        $check('other or undefined source never gains the Direct ten-day window',
            !ModeledConversionKnownYclidRelation::validateEnrichment($plans[1],$source,$r1,$when,$base,$other)['assessment']['allowed']);
        $wrong=$base;$wrong['response']['query']['timezone']='+03:00';
        $check('documented UTC override is verified instead of assuming a UTC counter',
            ModeledConversionKnownYclidRelation::validateR2($plans[1],$source,$r1,$when,$wrong)['status']==='unavailable');
        $hundred=$base;$hundred['response']['data']=[];
        for($i=0;$i<100;$i++){$v=$base['response']['data'][0];$v['dimensions'][1]['name']=gmdate('Y-m-d H:i:s',$plans[1]['event_unix']-$i-1);$hundred['response']['data'][]=$v;}
        $hundred['response']['total_rows']=100;$sentinel=$hundred;$sentinel['response']['data'][]=$sentinel['response']['data'][99];$sentinel['response']['total_rows']=101;
        $check('complete hundred-row base is retained and101 refuses completeness',
            count(ModeledConversionKnownYclidRelation::validateR2($plans[1],$source,$r1,$when,$hundred)['base_report']['visits'])===100
            &&ModeledConversionKnownYclidRelation::validateR2($plans[1],$source,$r1,$when,$sentinel)['reason']==='scoped_row_limit');
        $zero=$base;$zero['response']['data']=[];$zero['response']['total_rows']=0;
        $fresh=$base;$fresh['response']['data'][0]['dimensions'][1]['name']=gmdate('Y-m-d H:i:s',$plans[1]['event_unix']-1);
        $unavailable=$base;$unavailable['http']=503;$unavailable['failure']='http';
        foreach(['empty'=>$zero,'fresh'=>$fresh,'unavailable'=>$unavailable]as$label=>$cap)
            $check('base '.$label.' cannot authorize an unnecessary extra source GET',ModeledConversionKnownYclidRelation::enrichmentQuery($plans[1],$source,$r1,$when,$cap,$when+7)===null);

        $emptyR1=$r1;$emptyR1['response']['data']=[];$emptyR1['response']['total_rows']=0;
        $manyR1=$r1;$manyR1['response']['data'][]=['dimensions'=>[['name'=>'881122334455667799']],'metrics'=>[1]];$manyR1['response']['total_rows']=2;
        $badCidR1=$r1;$badCidR1['response']['data'][0]['dimensions'][0]['name']='18446744073709551616';
        $check('R1 zero is only the recorded-day scope and multiple CID groups never choose an arbitrary identity',
            ModeledConversionKnownYclidRelation::validateR1($plans[1],$raw,$when,$emptyR1)['status']==='no_match_in_recorded_click_utc_day'
            &&ModeledConversionKnownYclidRelation::validateR1($plans[1],$raw,$when,$manyR1)['status']==='ambiguous_recorded_day_relation'
            &&ModeledConversionKnownYclidRelation::validateR1($plans[1],$raw,$when,$badCidR1)['reason']==='invalid_relation_row');

        // Each following case owns a fresh disposable derived ledger, never a runtime reset.
        foreach(['claimed','tuple','identity_source','attempts','status','basis','claimed_clock','lost_ack']as$race){
            $reset();$calls=[];$intent=$start();
            $r1x=$api->retainedYclidClickDayClients($plans[1],$raw,$when,static fn(string $h,int $at):bool=>$ledger->admitR1($intent,$source,$h,$at,$when+3),static fn():int=>$when+3);
            $bx=$api->retainedIdentityVisitBase($plans[1],$source,$r1x,$when,static fn(string $h,int $at):bool=>$ledger->admitR2($intent,$source,$r1x,$h,$at,$when+5),static fn():int=>$when+5);
            if($race==='claimed')$p->prepare("UPDATE max_channel_conversion_outbox SET state='uploading',claim_token='synthetic_claim' WHERE id=?")->execute([$rows[1]['id']]);
            if($race==='tuple')$p->prepare('UPDATE max_channel_conversion_outbox SET client_id=? WHERE id=?')->execute(['991199',$rows[1]['id']]);
            if($race==='identity_source')$p->prepare("UPDATE max_channel_conversion_outbox SET identity_source_key='synthetic_other' WHERE id=?")->execute([$rows[1]['id']]);
            if($race==='attempts')$p->prepare('UPDATE max_channel_conversion_outbox SET attempts=1 WHERE id=?')->execute([$rows[1]['id']]);
            if($race==='status')$p->prepare("UPDATE max_channel_conversion_outbox SET upload_status='UPLOADED' WHERE id=?")->execute([$rows[1]['id']]);
            if($race==='basis')$p->prepare("UPDATE max_channel_conversion_outbox SET value_receipt_basis='accepted' WHERE id=?")->execute([$rows[1]['id']]);
            if($race==='claimed_clock')$p->prepare('UPDATE max_channel_conversion_outbox SET claimed_at_unix=? WHERE id=?')->execute([$when+6,$rows[1]['id']]);
            $fault->failure='commit_throw';$fault->commits=0;$fault->failCommit=1;$owner=$race==='lost_ack'?$faultLedger:$ledger;
            $blocked=$api->retainedIdentityVisitSources($plans[1],$source,$r1x,$bx,$when,
                static fn(string $h,int $at):bool=>$owner->admitEnrichment($intent,$bx,$h,$at,$when+7),static fn():int=>$when+7);
            $check('before-enrichment '.$race.' causes zero extra IO and preserves already observed base',
                $blocked===null&&$calls===['r1','base']&&$ledger->completeR2($intent,$bx,$when+8));
            $captured=$ledger->read($intent['outbox_id']);
            if($race==='lost_ack')$check('lost enrichment acknowledgement remains consumed and can never replay',
                is_string($captured['enrichment_request_sha256'])&&$captured['enrichment_json']===null
                &&!$ledger->admitEnrichment($intent,$bx,ModeledConversionKnownYclidRelation::queryHash('derived_cid_sources',$qe),$when+7,$when+9));
        }


        // Exercise the actual orchestration entry, not only isolated admit/complete calls.
        $makeLookup=static function(array $tuple,array $expectedPlan,string $loss='none')use($ledger,$when,&$currentIntent):object{
            $reply=static function(array $selection,callable $before,object $double)use($ledger,$when,&$currentIntent,$tuple,$expectedPlan,$loss):?array{
                if($selection!==$expectedPlan['selection'])throw new RuntimeException('synthetic_selection_changed');
                $r=$ledger->read($expectedPlan['outbox_id']);
                $currentIntent=['outbox_id'=>$expectedPlan['outbox_id'],'intent_at_unix'=>(int)$r['intent_at_unix'],'intent_sha256'=>$r['intent_sha256']];
                if($loss==='before_callback')throw new RuntimeException('synthetic_signing_failure');
                $body=MaxTrackerLookupProtocol::retainedClickRehydrateBody($selection,$when+10,str_repeat('0',32));$h=hash('sha256',$body);
                if($before($h)!==true)return null;
                $stored=$ledger->read($expectedPlan['outbox_id']);$double->committed=$stored['source_request_sha256']===$h;$double->calls++;
                if($loss==='after_callback')return null;
                return ['request_sha256'=>$h,'result'=>[1,$when+10,$tuple],'received_at_unix'=>$when+10];
            };
            return new class($reply) {
                public int $calls=0;public bool $committed=false;
                public function __construct(private Closure $reply){}
                public function retainedClickRehydrate(array $selection,callable $before):?array{return ($this->reply)($selection,$before,$this);}
            };
        };
        $readCache=static function(string $id)use($p):array{
            $expr=ModeledConversionKnownYclidRelation::projectionBasisSql((string)$p->getAttribute(PDO::ATTR_DRIVER_NAME),'r');
            $bytes=$p->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'?'OCTET_LENGTH(r.outcome_json)':'LENGTH(CAST(r.outcome_json AS BLOB))';
            $q=$p->prepare('SELECT r.state,r.outcome_basis_sha256,'.$expr.' AS actual_basis,'
                .'CASE WHEN r.outcome_basis_sha256='.$expr.' AND '.$bytes.'<=2048 THEN r.outcome_json ELSE NULL END AS outcome_json,'
                .'CASE WHEN r.source_request_sha256 IS NULL THEN 0 ELSE 1 END AS source_admitted'
                .' FROM modeled_conversion_known_yclid_relations r WHERE r.purpose=? AND r.outbox_id=? LIMIT 1');
            $q->execute([ModeledConversionKnownYclidRelation::PURPOSE,$id]);$r=$q->fetch(PDO::FETCH_ASSOC);$q->closeCursor();return $r;
        };

        $reset();$calls=[];$committed=[];$sourceMode='direct';$visits=[[$when+10-9*86400,1]];
        $lookup=$makeLookup($tuples[1],$plans[1]);$allowCalls=0;
        $ledger->captureOnce($saved[1],$frame,$rows[1],$ns,$lookup,$api,$when,static fn():int=>$when+10,
            static function()use(&$allowCalls):bool{$allowCalls++;return true;});
        $captured=$ledger->read($plans[1]['outbox_id']);
        $candidate=ModeledConversionKnownYclidRelation::dispatchCandidate($captured,$saved[1],$frame,$rows[1],$settings,$ns,$when+10);
        $check('actual capture entry commits source R1 fullbase and optional Direct proof before each IO',
            $lookup->calls===1&&$lookup->committed&&$allowCalls===4&&$calls===['r1','base','enrichment']
            &&!in_array(false,$committed,true)&&$captured['state']==='complete'&&$candidate!==null&&$candidate['client_id']===$derived);
        forecastKnownRelationOutcomeReaderAssertions($p,$check,$frame,$rows,$plans,$captured,$when+10);
        $cached=$readCache($plans[1]['outbox_id']);$cachedOutcome=json_decode($cached['outcome_json'],true,32,JSON_THROW_ON_ERROR);
        $check('actual capture finally persists a bounded opaque outcome with SQL/PHP raw-byte basis equality on native JSON',
            $cached['outcome_basis_sha256']===$cached['actual_basis']
            &&$cached['actual_basis']===ModeledConversionKnownYclidRelation::projectionBasisHash($captured)
            &&strlen($cached['outcome_json'])<=2048&&$cachedOutcome['eligible_as_of_check']===true
            &&$cachedOutcome['financial_permission']===false&&$cachedOutcome['current_eligibility']==='not_assessed');
        $check('saved outcome readback selects no raw capsule identity native key or monetary field',
            array_keys($cached)===['state','outcome_basis_sha256','actual_basis','outcome_json','source_admitted']
            &&!str_contains($cached['outcome_json'],$raw)&&!str_contains($cached['outcome_json'],$derived)
            &&!isset($cachedOutcome['client_id'],$cachedOutcome['external_user_id'],$cachedOutcome['yclid'],$cachedOutcome['outbox_id'],$cachedOutcome['value_rub'],$cachedOutcome['filters']));
        $nullCopy=$captured;$emptyCopy=$captured;$nullCopy['r1_json']=null;$emptyCopy['r1_json']='';
        $cacheOnly=$captured;$cacheOnly['outcome_json']='{"ignored":true}';$cacheOnly['outcome_basis_sha256']=str_repeat('0',64);
        $check('basis distinguishes NULL from empty bytes and excludes only the derived cache fields',
            ModeledConversionKnownYclidRelation::projectionBasisHash($nullCopy)!==ModeledConversionKnownYclidRelation::projectionBasisHash($emptyCopy)
            &&ModeledConversionKnownYclidRelation::projectionBasisHash($cacheOnly)===ModeledConversionKnownYclidRelation::projectionBasisHash($captured)
            &&ModeledConversionKnownYclidRelation::projectionBasisSql('mysql','r; DROP TABLE x')===null);

        $ledger->captureOnce($saved[1],$frame,$rows[1],$ns,$lookup,$api,$when+300,static fn():int=>$when+300,static fn():bool=>true);
        $check('a later natural cadence cannot repeat any completed original-case IO',
            $lookup->calls===1&&$calls===['r1','base','enrichment']);

        // Native JSON reorders object keys; the freshness hash must use the actual retrieved database text.
        $mutated=json_decode($captured['r2_json'],true,32,JSON_THROW_ON_ERROR);
        $mutated['response']['native_utf8_probe']=['Ж'=>'a/b','nested'=>['z'=>1,'a'=>2]];
        $p->prepare('UPDATE modeled_conversion_known_yclid_relations SET r2_json=? WHERE outbox_id=?')->execute([json_encode($mutated,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR),$plans[1]['outbox_id']]);
        $stale=$readCache($plans[1]['outbox_id']);$rawMutated=$ledger->read($plans[1]['outbox_id']);
        $check('any full-capsule byte change invalidates the old cached outcome without copying the capsule in the getter',
            $stale['outcome_json']===null&&$stale['actual_basis']!==$stale['outcome_basis_sha256']
            &&$stale['actual_basis']===ModeledConversionKnownYclidRelation::projectionBasisHash($rawMutated));
        // Re-entering a consumed row only refreshes its saved projection; it can never redo any IO.
        $ledger->captureOnce($saved[1],$frame,$rows[1],$ns,$lookup,$api,$when+300,static fn():int=>$when+300,static fn():bool=>true);
        $refreshed=$readCache($plans[1]['outbox_id']);
        $check('production finally refreshes the same saved proof after native JSON normalization with no source/provider replay',
            $refreshed['outcome_json']!==null&&$refreshed['outcome_basis_sha256']===$refreshed['actual_basis']
            &&$lookup->calls===1&&$calls===['r1','base','enrichment']);
        $oldStatement=$p->getAttribute(PDO::ATTR_STATEMENT_CLASS);
        $p->setAttribute(PDO::ATTR_STATEMENT_CLASS,[ForecastV1ObservedStatement::class]);$cacheRace=false;
        ForecastV1ObservedStatement::$beforeRelationCache=static function()use($p,$plans,&$cacheRace):void{
            $cacheRace=true;$p->prepare('UPDATE modeled_conversion_known_yclid_relations SET completed_at_unix=completed_at_unix+1 WHERE outbox_id=?')->execute([$plans[1]['outbox_id']]);
        };
        try{$ledger->captureOnce($saved[1],$frame,$rows[1],$ns,$lookup,$api,$when+301,static fn():int=>$when+301,static fn():bool=>true);}
        finally{ForecastV1ObservedStatement::$beforeRelationCache=null;$p->setAttribute(PDO::ATTR_STATEMENT_CLASS,$oldStatement);}
        $raced=$readCache($plans[1]['outbox_id']);
        $check('a phase change between cache read and UPDATE defeats the single basis CAS and leaves explicit cache unavailability',
            $cacheRace&&$raced['outcome_json']===null&&$raced['actual_basis']!==$raced['outcome_basis_sha256']&&$lookup->calls===1&&$calls===['r1','base','enrichment']);
        $reset();$calls=[];$crash=$ledger->begin($saved[1],$frame,$rows[1],$ns,$when);$ledger->admitSource($crash,$sourceHash,$when+1);
        $crashed=$readCache($plans[1]['outbox_id']);
        $check('a crash before finally exposes consumed scalar intent without a fake saved outcome or another source call',
            $crashed['state']==='intent'&&(int)$crashed['source_admitted']===1&&$crashed['outcome_json']===null&&$calls===[]);

        $reset();$calls=[];$lookup=$makeLookup($tuples[1],$plans[1]);$allowCalls=0;
        $ledger->captureOnce($saved[1],$frame,$rows[1],$ns,$lookup,$api,$when,static fn():int=>$when+10,
            static function()use(&$allowCalls,$p,$rows):bool{
                $allowCalls++;if($allowCalls===4)$p->prepare("UPDATE max_channel_conversion_outbox SET state='uploading',claim_token='synthetic_capture_claim' WHERE id=?")->execute([$rows[1]['id']]);return true;
            });
        $captured=$ledger->read($plans[1]['outbox_id']);
        $check('capture revalidates the live original before enrichment and preserves the already committed complete base',
            $lookup->calls===1&&$calls===['r1','base']&&$captured['state']==='complete'
            &&is_string($captured['r2_json'])&&$captured['enrichment_request_sha256']===null&&$captured['enrichment_json']===null);

        $reset();$calls=[];$lookup=$makeLookup($tuples[1],$plans[1]);$oldDerived=$derived;$derived=$plans[1]['held_client_id'];
        $ledger->captureOnce($saved[1],$frame,$rows[1],$ns,$lookup,$api,$when,static fn():int=>$when+10,static fn():bool=>true);
        $reconfirmed=$ledger->read($plans[1]['outbox_id']);$derived=$oldDerived;
        $view=ModeledConversionKnownYclidRelation::projection($reconfirmed,$saved[1],$frame,$rows[1],$ns,$when+10);
        $check('R1 that only reconfirms the previously checked held CID is terminal with zero repeated CID reports',
            $lookup->calls===1&&$calls===['r1']&&$reconfirmed['state']==='complete'&&$reconfirmed['r2_request_sha256']===null
            &&$view['status']==='relation_reconfirms_previously_checked_held_cid'&&$view['relation_reason']==='recorded_day_cid_matches_held'
            &&$view['r1_coverage_scope']==='recorded_click_utc_day_only'&&$view['r1_day_utc']===$q1['date1']&&!$view['financial_permission']);
        foreach(['zero'=>'no_match_in_recorded_click_utc_day','ambiguous'=>'ambiguous_recorded_day_relation','unavailable'=>'provider_unavailable']as$mode=>$expected){
            $reset();$calls=[];$lookup=$makeLookup($tuples[1],$plans[1]);$relationMode=$mode;
            $ledger->captureOnce($saved[1],$frame,$rows[1],$ns,$lookup,$api,$when,static fn():int=>$when+10,static fn():bool=>true);
            $observed=$readCache($plans[1]['outbox_id']);$view=json_decode($observed['outcome_json'],true,32,JSON_THROW_ON_ERROR);
            $check('saved-only cache distinguishes scoped R1 no-match ambiguity and providerUNKNOWN '.$mode,
                $lookup->calls===1&&$calls===['r1']&&$observed['state']==='complete'
                &&$view['status']==='r1_identity_unavailable'&&$view['relation_reason']===$expected
                &&$view['r1_coverage_scope']==='recorded_click_utc_day_only'&&$view['r1_day_utc']===$q1['date1']
                &&$view['financial_permission']===false&&$view['current_eligibility']==='not_assessed');
        }
        $relationMode='one';
        $reset();$calls=[];$lookup=$makeLookup($tuples[1],$plans[1]);$visits=[[$plans[1]['event_unix']-1,1]];$sourceMode='direct';
        $ledger->captureOnce($saved[1],$frame,$rows[1],$ns,$lookup,$api,$when,static fn():int=>$when+10,static fn():bool=>true);
        $early=$ledger->read($plans[1]['outbox_id']);
        $check('an actual complete recent base uses no optional enrichment GET',
            $lookup->calls===1&&$calls===['r1','base']&&$early['state']==='complete'&&$early['enrichment_request_sha256']===null
            &&ModeledConversionKnownYclidRelation::dispatchCandidate($early,$saved[1],$frame,$rows[1],$settings,$ns,$when+10)!==null);
        $reset();$calls=[];$lookup=$makeLookup($tuples[1],$plans[1]);$visits=[[$when+10-4*86400,1]];$sourceMode='empty';
        $ledger->captureOnce($saved[1],$frame,$rows[1],$ns,$lookup,$api,$when,static fn():int=>$when+10,static fn():bool=>true);
        $omitted=$ledger->read($plans[1]['outbox_id']);$view=ModeledConversionKnownYclidRelation::projection($omitted,$saved[1],$frame,$rows[1],$ns,$when+10);
        $check('actual empty source enrichment retains a complete unknown4 base rather than a false no-prior result',
            $calls===['r1','base','enrichment']&&$view['reason']==='outside_current_window'&&!$view['eligible_as_of_check']
            &&$view['visit_at_utc']===gmdate('Y-m-d\TH:i:s\Z',$visits[0][0])
            &&ModeledConversionKnownYclidRelation::dispatchCandidate($omitted,$saved[1],$frame,$rows[1],$settings,$ns,$when+10)===null);
        $visits=[[$when+10-9*86400,1]];$sourceMode='direct';

        foreach(['before_callback','after_callback']as$loss){
            $reset();$calls=[];$lookup=$makeLookup($tuples[1],$plans[1],$loss);
            $ledger->captureOnce($saved[1],$frame,$rows[1],$ns,$lookup,$api,$when,static fn():int=>$when+10,static fn():bool=>true);
            $consumed=$ledger->read($plans[1]['outbox_id']);
            $ledger->captureOnce($saved[1],$frame,$rows[1],$ns,$lookup,$api,$when+300,static fn():int=>$when+300,static fn():bool=>true);
            $check('capture consumes an unknown source phase without any Reports or automatic retry '.$loss,
                $consumed['state']==='intent'&&$calls===[]&&$lookup->calls===($loss==='after_callback'?1:0)
                &&($loss==='after_callback'?is_string($consumed['source_request_sha256']):$consumed['source_request_sha256']===null));
            $cacheUnknown=$readCache($plans[1]['outbox_id']);$opaqueUnknown=json_decode($cacheUnknown['outcome_json'],true,32,JSON_THROW_ON_ERROR);
            $check('capture finally stores only an explicit consumed-unknown outcome after a lost source response '.$loss,
                $cacheUnknown['outcome_basis_sha256']===$cacheUnknown['actual_basis']&&$opaqueUnknown['status']==='consumed_unknown'
                &&$opaqueUnknown['financial_permission']===false&&$opaqueUnknown['current_eligibility']==='not_assessed');
        }
        foreach(['begin_false'=>1,'commit_false'=>1,'source_commit_false'=>2]as$failure=>$failCommit){
            $reset();$calls=[];$lookup=$makeLookup($tuples[1],$plans[1]);
            $fault->failure=$failure==='begin_false'?'begin_false':'commit_false';$fault->commits=0;$fault->failCommit=$failCommit;
            $faultLedger->captureOnce($saved[1],$frame,$rows[1],$ns,$lookup,$api,$when,static fn():int=>$when+10,static fn():bool=>true);
            $consumed=$ledger->read($plans[1]['outbox_id']);
            $check('an unconfirmed PDO transaction or commit grants zero private IO '.$failure,
                $lookup->calls===0&&$calls===[]&&!$p->inTransaction()
                &&($failure==='begin_false'?$consumed===null:$consumed!==null&&$consumed['state']==='intent'));
            if($failure!=='begin_false'){
                $ledger->captureOnce($saved[1],$frame,$rows[1],$ns,$lookup,$api,$when+300,static fn():int=>$when+300,static fn():bool=>true);
                $check('a committed-but-unacknowledged intent cannot replay '.$failure,$lookup->calls===0&&$calls===[]);
            }
        }
        $fault->failure='commit_throw';$fault->commits=0;$fault->failCommit=1;

        forecastKnownRelationDispatchAssertions($p,$check,$frame,$rows[1],$saved[1],$stored,$when+10,$fault);

        // Rebuild the old selected proof as an independent fixture with an already populated exact source CID.
        foreach(['different'=>'991122334455667788','held'=>$plans[1]['held_client_id'],'invalid'=>'18446744073709551616']as$kind=>$cid){
            $reset();$calls=[];$tuple=$tuples[1];$tuple[2]=$cid;$sv=$saved[1];$ctx=$plans[1]['selection']['selection_context'];
            $fp=hash('sha256',json_encode($tuple,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR));
            $sv['o'][7]=$fp;$sv['o'][5]=hash('sha256',json_encode([MaxTrackerLookupProtocol::RETAINED_CLICK_HEADER,$oldHash,$ctx,2,$raw,$tuple[0],$fp,$sv['o'][8]],JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR));
            $rc=$receipt;$rc[ModeledConversionRetainedTrackerIdentityEvidence::KNOWN_KEYS[0]]=$sv;
            $p->prepare("UPDATE modeled_conversion_value_export_settings SET last_receipt_json=? WHERE project_key='anytour'")->execute([json_encode($rc,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)]);
            $plan=ModeledConversionRetainedTrackerIdentityEvidence::knownRehydratePlan($sv,$frame,$rows[1],$ns,$when);$intent=$ledger->begin($sv,$frame,$rows[1],$ns,$when);$currentIntent=$intent;$ledger->admitSource($intent,$sourceHash,$when+1);
            $sc=['request_sha256'=>$sourceHash,'result'=>[1,$when+1,$tuple],'received_at_unix'=>$when+2];$route=ModeledConversionKnownYclidRelation::sourceIdentityRoute($plan,$sc,$when,$when+3);
            $check('a populated source CID never spends a Yclid R1 request '.$kind,
                !$ledger->admitR1($intent,$sc,ModeledConversionKnownYclidRelation::queryHash('click_day_cid',ModeledConversionKnownYclidRelation::r1Query($plan,$raw,$when)),$when+3,$when+3));
            if($kind==='different'){
                // UTC23:30 is next local+03 calendar day; fake provider honors the requested override.
                $visits=[[(int)strtotime(gmdate('Y-m-d',$plans[1]['event_unix']-86400).' 23:30:00 UTC'),1]];
                $b=$api->retainedIdentityVisitBase($plan,$sc,null,$when,static fn(string $h,int $at):bool=>$ledger->admitR2($intent,$sc,null,$h,$at,$when+5),static fn():int=>$when+5);
                $extraCalls=0;$e=$api->retainedIdentityVisitSources($plan,$sc,null,$b,$when,static function()use(&$extraCalls):bool{$extraCalls++;return true;},static fn():int=>$when+7);
                $check('canonical sourceCID goes straight to complete recent base with0R1 and0extra GET',
                    $route['status']==='ready'&&$calls===['base']&&$e===null&&$extraCalls===0&&$ledger->completeR2($intent,$b,$when+8));
                $candidate=ModeledConversionKnownYclidRelation::dispatchCandidate($ledger->read($intent['outbox_id']),$sv,$frame,$rows[1],$settings,$ns,$when+9);
                $check('sourceCID positive proof and nonUTC counter still produce the exact original-event private candidate',
                    $candidate!==null&&$candidate['client_id']===$cid&&$candidate['event_ref']===$plan['event_ref']
                    &&$candidate['visit_report']['visits'][0]['started_at']===gmdate('Y-m-d\TH:i:s\Z',$visits[0][0]));
                $reset();$calls=[];$lookup=$makeLookup($tuple,$plan);
                $ledger->captureOnce($sv,$frame,$rows[1],$ns,$lookup,$api,$when,static fn():int=>$when+10,static fn():bool=>true);
                $directCapture=$ledger->read($plan['outbox_id']);
                $check('actual capture uses exact-record CID directly with one base GET and no R1 or enrichment',
                    $lookup->calls===1&&$calls===['base']&&$directCapture['r1_request_sha256']===null&&$directCapture['r1_json']===null
                    &&$directCapture['enrichment_request_sha256']===null
                    &&ModeledConversionKnownYclidRelation::dispatchCandidate($directCapture,$sv,$frame,$rows[1],$settings,$ns,$when+10)!==null);
            }else{
                $check('held or invalid nonempty sourceCID forbids all Reports without a Yclid fallback '.$kind,
                    ModeledConversionKnownYclidRelation::r2Query($plan,$sc,null,$when,$when+5)===null&&$calls===[]&&$ledger->completeSource($intent,$sc,$when+4)
                    &&$route['status']===($kind==='held'?'reconfirms_previously_checked_held_cid':'invalid_source_client_id'));
                $reset();$calls=[];$lookup=$makeLookup($tuple,$plan);
                $ledger->captureOnce($sv,$frame,$rows[1],$ns,$lookup,$api,$when,static fn():int=>$when+10,static fn():bool=>true);
                $cached=$readCache($plan['outbox_id']);$out=json_decode($cached['outcome_json'],true,32,JSON_THROW_ON_ERROR);
                $check('source-only terminal cache explains zero Reports without identity rewriting '.$kind,
                    $lookup->calls===1&&$calls===[]&&$out['financial_permission']===false
                    &&($kind==='held'?$out['relation_reason']==='source_client_id_matches_held':$out['status']==='invalid_source_client_id'));

            }
        }
    }finally{
        foreach($restore as$r){$keys=['state','identity_source_key','client_id','tracker_record_id','tracker_observed_unix','external_user_id','event_unix','attempts','claimed_at_unix','last_error','upload_id','upload_status','value_receipt_basis','claim_token','value_api_accepted_at_unix','value_processed_at_unix'];
            $q=$p->prepare('UPDATE max_channel_conversion_outbox SET '.implode(',',array_map(static fn(string $k):string=>$k.'=?',$keys)).' WHERE id=?');$q->execute([...array_map(static fn(string $k):mixed=>$r[$k],$keys),$r['id']]);}
        $p->prepare("UPDATE modeled_conversion_value_export_settings SET mode=?,counter_id=?,goal_id=?,goal_intent=?,contract_version=?,last_receipt_json=? WHERE project_key='anytour'")
            ->execute([$before['mode'],$before['counter_id'],$before['goal_id'],$before['goal_intent'],$before['contract_version'],$before['last_receipt_json']]);
        $p->exec('DROP TABLE IF EXISTS modeled_conversion_known_yclid_relations');
    }
}

/** Isolated synthetic tracker rows only; no HTTP, provider or production source read. */
/** Presentation tests reuse the actual synthetic reader outputs; no extra native point or provider calls. */
function forecastNativePresentationAssertions(callable $check):void
{
    $fixture=ForecastV1ObservedStatement::$nativePresentationFixtures;
    if($fixture===null)throw new RuntimeException('native_presentation_fixture_missing');
    $projection=(new ReflectionClass(ModeledConversionForecastStatusReader::class))->newInstanceWithoutConstructor();
    $views=new ReflectionMethod(ModeledConversionForecastStatusReader::class,'nativeIdentityPresentations');
    $bound=new ReflectionMethod(ModeledConversionForecastStatusReader::class,'boundedResponse');
    $original=$fixture['original'];$native=$fixture['guarded'];
    $format=static fn(array $v,?array $o=null):array=>$views->invoke($projection,$v,$o??$original);
    $small=['value_delivery'=>['repair_assessments'=>['finite_visit_evidence'=>$original,'retained_native_identity_evidence'=>$native]],
        'money_fixture'=>['events'=>5,'value_rub'=>123]];
    $smallSafe=$bound->invoke($projection,$small);
    $check('a feasible complete response preserves actual native projection original binding and money byte for byte',$smallSafe===$small);
    $variants=$format($native);$compact=$variants[1]??[];
    $expected=[3,2,1,0,0,2,1,1,1];
    $check('compact native evidence retains all nine first rejection counts in fixed order and exact current original binding',
        $variants[0]===$native&&$compact['projection']==='compact_native_identity_v1'
        &&$compact['event_ref']===$original['rows'][0]['event_ref']&&$compact['case_index']===0
        &&$compact['binding_state']==='verified_original_and_result'
        &&$compact['first_rejections']===$expected&&$compact['counts']===[12,1,1]
        &&array_sum($compact['first_rejections'])+$compact['counts'][1]===$compact['counts'][0]
        &&$compact['candidate_sha256']===$native['candidate_sha256']&&$compact['source_observed_at']===$native['source_observed_at']
        &&$compact['snapshot_at']===$native['snapshot_at']&&$compact['point_read_execution']==='completed'
        &&$compact['global_absence_proven']===false&&!$compact['financial_permission']
        &&$compact['visit_linkage']==='not_checked'&&$compact['provider_requests_in_reader']===0
        &&strlen(json_encode($compact,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR))<=768);
    $same=$format($fixture['same_client'])[1];$ambiguous=$format($fixture['ambiguous'])[1];$none=$format($fixture['none'])[1];
    $check('same ClientID witnesses preserve one candidate while distinct identities preserve ambiguity without an arbitrary choice',
        $same['counts'][1]===2&&$same['counts'][2]===1&&$same['candidate_sha256']!==null
        &&$ambiguous['status']==='ambiguous_native_candidates'&&$ambiguous['counts'][2]===2
        &&$ambiguous['candidate_sha256']===null&&$ambiguous['source_observed_at']===null
        &&$none['status']==='no_admissible_native_witness'&&$none['counts'][2]===0
        &&!$none['global_absence_proven']&&$none['candidate_sha256']===null);
    foreach(['no_index'=>'point_index_unavailable','row_limit'=>'row_limit','budget'=>'budget_headroom']as$key=>$reason){
        $v=$format($fixture[$key]);$c=$v[1]??[];
        $check('unavailable existing native result retains unknown coverage and no assertion of SELECT execution '.$key,
            $v[0]===$fixture[$key]&&!$c['available']&&$c['reason']===$reason
            &&$c['binding_state']==='original_frame_only'&&$c['snapshot_at']===null
            &&$c['counts']===null&&$c['first_rejections']===null&&$c['point_read_execution']==='not_attested'
            &&!$c['complete_within_bound']&&$c['candidate_sha256']===null&&$c['source_observed_at']===null);
    }
    foreach(['available','duplicate_missing','changed_ref','future_clock','missing_snapshot','scope','raw_field','ambiguous_hash']as$kind){
        $v=$native;$o=$original;
        if($kind==='available')$o['available']=false;
        if($kind==='duplicate_missing'){
            $o['rows'][1]['source_status']=$o['rows'][0]['source_status'];$o['rows'][1]['candidate_sha256']=null;
            $o['rows'][1]['report_status']='not_requested';
        }
        if($kind==='changed_ref')$v['event_ref']=str_repeat('f',64);
        if($kind==='future_clock')$v['source_observed_at']=gmdate('Y-m-d\TH:i:s\Z',strtotime($o['rows'][0]['event_at'])+1);
        if($kind==='missing_snapshot')unset($v['snapshot_at']);
        if($kind==='scope')$v['scope']='OTHER_PRIVATE_SCOPE';
        if($kind==='raw_field')$v['client_id']='PRIVATE_RAW_CLIENT_MUST_NOT_ESCAPE';
        if($kind==='ambiguous_hash'){$v=$fixture['ambiguous'];$v['candidate_sha256']=$native['candidate_sha256'];}
        $got=$format($v,$o)[0];$s=$small;$s['value_delivery']['repair_assessments']['finite_visit_evidence']=$o;
        $s['value_delivery']['repair_assessments']['retained_native_identity_evidence']=$v;
        $safe=$bound->invoke($projection,$s)['value_delivery']['repair_assessments']['retained_native_identity_evidence'];
        $check('invalid or unavailable native binding suppresses candidate even in an otherwise feasible full response '.$kind,
            !$got['available']&&!$safe['available']&&!isset($safe['candidate_sha256'])
            &&!str_contains(json_encode($safe,JSON_THROW_ON_ERROR),'PRIVATE_'));
    }
    foreach(['missing_rejection','null_rejection','negative_rejection','sum','unique','admitted','rows65','null_rows','null_unique','admitted_without_candidate']as$kind){
        $v=$native;
        if($kind==='missing_rejection')unset($v['first_rejection_reason_counts']['saved_error_present']);
        if($kind==='null_rejection')$v['first_rejection_reason_counts']['saved_error_present']=null;
        if($kind==='negative_rejection')$v['first_rejection_reason_counts']['saved_error_present']=-1;
        if($kind==='sum')$v['first_rejection_reason_counts']['saved_error_present']=1;
        if($kind==='unique')$v['unique_candidate_count']=2;
        if($kind==='admitted')$v['admitted_native_witnesses']=13;
        if($kind==='rows65')$v['inspected_rows']=65;
        if($kind==='null_rows')$v['inspected_rows']=null;
        if($kind==='null_unique')$v['unique_candidate_count']=null;
        if($kind==='admitted_without_candidate'){
            $v['unique_candidate_count']=0;$v['status']='no_admissible_native_witness';
            $v['candidate_sha256']=null;$v['source_observed_at']=null;$v['source_provenance']=null;
        }
        $got=$format($v)[0];
        $check('malformed native counts never fabricate zero completeness or candidate '.$kind,
            !$got['available']&&$got['reason']==='invalid_native_projection'&&!isset($got['candidate_sha256'],$got['counts']));
    }
    $encoded=json_encode($compact,JSON_THROW_ON_ERROR);
    foreach(['9912345678901234','880100','890100','740001','client_id','record_id','upload_id']as$private)
        $check('compact native projection does not disclose a raw source field or identifier '.$private,!str_contains($encoded,$private));
}

function forecastRetainedTrackerAssertions(PDO $p,callable $check,array $original,array $row,int $now):void
{
    $ns=ModeledConversionProfiles::assessmentNamespace();$hash=str_repeat('a',64);
    $ctx=ModeledConversionRetainedTrackerIdentityEvidence::context($original,$row,$ns,$now);
    $intent=ModeledConversionRetainedTrackerIdentityEvidence::intent($original,$row,$ns,$hash,$now);
    $wire=['v'=>1,'request_sha256'=>$hash,'result'=>[1,$now,1,0,2,str_repeat('d',64),'1',str_repeat('c',64),(int)$row['event_unix']-10]];
    $response=['request_sha256'=>$hash,'result'=>$wire['result'],'received_at_unix'=>$now];
    $done=ModeledConversionRetainedTrackerIdentityEvidence::complete($intent,$original,$row,$ns,$response,$now);
    $view=ModeledConversionRetainedTrackerIdentityEvidence::projection($done,$original,$row,$ns,$now);
    $check('a distinct reserved tracker intent binds the same original case and never reuses the consumed counter or app keys',
        strlen(json_encode($intent,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR))===768
        &&ModeledConversionRetainedTrackerIdentityEvidence::RECEIPT_KEY!==ModeledConversionMaxCounterScopeEvidence::RECEIPT_KEY
        &&$ctx['event_ref']===$intent['e']&&$ctx['frame_sha256']===$intent['d']
        &&$view['status']==='candidate'&&$view['kind']==='yclid'&&!$view['financial_permission']
        &&$view['first_saved_at_unix']===null&&$view['first_enriched_at_unix']===null&&!isset($view['record_id']));
    $bad=$response;$bad['request_sha256']=str_repeat('b',64);
    $future=$response;$future['result'][8]=(int)$row['event_unix']+1;
    $malformed=$done;unset($malformed['h']);$malformed['PRIVATE_UNKNOWN']='value';
    $check('cross-request future-click and malformed tracker results cannot become a saved candidate',
        ModeledConversionRetainedTrackerIdentityEvidence::complete($intent,$original,$row,$ns,$bad,$now)===null
        &&ModeledConversionRetainedTrackerIdentityEvidence::complete($intent,$original,$row,$ns,$future,$now)===null
        &&!ModeledConversionRetainedTrackerIdentityEvidence::projection($malformed,$original,$row,$ns,$now)['available']);
    $data=['counter_id'=>'98615635','user_ids'=>[$row['external_user_id']],'retained_click_candidate'=>$ctx];
    $check('the signed candidate DTO is one exact subject and cannot be mixed with counter evidence',
        MaxTrackerLookupProtocol::retainedClickContext($data,MaxTrackerLookupProtocol::RETAINED_CLICK_HEADER,$now)===$ctx);
    $mixed=$data;$mixed['counter_scope_evidence']=['v'=>1,'user_id'=>$row['external_user_id']];$refused=false;
    try{MaxTrackerLookupProtocol::retainedClickContext($mixed,MaxTrackerLookupProtocol::RETAINED_CLICK_HEADER,$now);}catch(InvalidArgumentException $e){$refused=true;}
    $check('ordinary and count-only lookup options retain their independent contracts',$refused);
    $selectionFor=static fn(array $context,array $selected):array=>['v'=>1,'selection_context'=>$context,'selection_request_sha256'=>$hash,
        'selected_record_id'=>$selected[6],'selected_kind'=>2,'selected_candidate_sha256'=>$selected[5],
        'selected_record_sha256'=>$selected[7],'selected_recorded_at_unix'=>$selected[8]];
    $tuple=['1',$ctx['user_id'],'','119988776655443322',
        (new DateTimeImmutable('@'.($ctx['event_at_unix']-10)))->setTimezone(new DateTimeZone('Europe/London'))->format('Y-m-d H:i:s'),'111',1];
    $fingerprint=hash('sha256',json_encode($tuple,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR));
    $oldCandidate=hash('sha256',json_encode([MaxTrackerLookupProtocol::RETAINED_CLICK_HEADER,$hash,$ctx,2,$tuple[3],$tuple[0],$fingerprint,$ctx['event_at_unix']-10],JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR));
    $selection=$selectionFor($ctx,[1,$now,1,0,2,$oldCandidate,$tuple[0],$fingerprint,$ctx['event_at_unix']-10]);
    $rehydrateBody=MaxTrackerLookupProtocol::retainedClickRehydrateBody($selection,$now,str_repeat('e',32));
    $rehydrateData=json_decode($rehydrateBody,true,16,JSON_THROW_ON_ERROR);$rehydrateHash=hash('sha256',$rehydrateBody);
    $rehydrateWire=['v'=>1,'request_sha256'=>$rehydrateHash,'result'=>[1,$now,$tuple],'financial_permission'=>false];
    $check('exact-record DTO carries the old saved selection and independently validates both old hashes and London time',
        MaxTrackerLookupProtocol::retainedClickRehydrateContext($rehydrateData,MaxTrackerLookupProtocol::RETAINED_REHYDRATE_HEADER,$now)===$selection
        &&MaxTrackerLookupProtocol::retainedClickRehydrateResult($rehydrateWire,$selection,$rehydrateHash,$now)===[1,$now,$tuple]);
    foreach(['header','kind','user','extra','purpose','future']as$bad){
        $d=$rehydrateData;$header=MaxTrackerLookupProtocol::RETAINED_REHYDRATE_HEADER;
        if($bad==='header')$header=MaxTrackerLookupProtocol::RETAINED_CLICK_HEADER;
        if($bad==='kind')$d['retained_click_rehydrate']['selected_kind']=1;
        if($bad==='user')$d['user_ids'][]='999991';
        if($bad==='extra')$d['retained_click_rehydrate']['raw_yclid']='119988776655443322';
        if($bad==='purpose')$d['retained_click_candidate']=$ctx;
        if($bad==='future')$d['retained_click_rehydrate']['selected_recorded_at_unix']=$ctx['event_at_unix']+1;
        $refused=false;try{MaxTrackerLookupProtocol::retainedClickRehydrateContext($d,$header,$now);}catch(InvalidArgumentException $ignored){$refused=true;}
        $check('exact-record purpose is closed before source access '.$bad,$refused);
    }
    foreach([0=>'9',1=>'999991',2=>'991122',3=>'991133',4=>'2026-10-25 01:30:00',5=>'112',6=>true]as$i=>$value){
        $bad=$rehydrateWire;$bad['result'][2][$i]=$value;
        $check('raw exact-record reply cannot substitute a saved tuple field '.$i,
            MaxTrackerLookupProtocol::retainedClickRehydrateResult($bad,$selection,$rehydrateHash,$now)===null);
    }
    $bad=$rehydrateWire;$bad['request_sha256']=str_repeat('f',64);
    $check('exact-record reply is bound to the new request and never grants financial permission',
        MaxTrackerLookupProtocol::retainedClickRehydrateResult($bad,$selection,$rehydrateHash,$now)===null
        &&$rehydrateWire['financial_permission']===false);
    if($p->getAttribute(PDO::ATTR_DRIVER_NAME)!=='mysql'){
        $r=(new MaxTrackerLookupRepository($p))->retainedClickCandidate($ctx,$hash,$now);
        $check('unsupported tracker execution deadlines refuse source SQL on SQLite',$r['result'][0]===10);
        $exact=(new MaxTrackerLookupRepository($p))->retainedClickRehydrate($selection,$rehydrateHash,$now);
        $check('exact-record SQLite execution is explicit unsupported without a source SELECT',$exact['result']===[3,$now,null]);return;
    }
    foreach(['max_sub_links','b_iblock_element_property','b_iblock_property']as$t)$p->exec('DROP TABLE IF EXISTS '.$t);
    $p->exec('CREATE TABLE max_sub_links(ID BIGINT PRIMARY KEY,UF_CHAT_ID VARCHAR(40),UF_CLIENT_ID TEXT,UF_YCLID TEXT,UF_DATE DATETIME,UF_SOURCE VARCHAR(20),UF_METRIC_SOURCE VARCHAR(20),INDEX idx_chat_id(UF_CHAT_ID(3)))');
    $p->exec('CREATE TABLE b_iblock_property(ID BIGINT PRIMARY KEY,IBLOCK_ID INT,CODE VARCHAR(40))');
    $p->exec('CREATE TABLE b_iblock_element_property(IBLOCK_PROPERTY_ID BIGINT,IBLOCK_ELEMENT_ID BIGINT,VALUE VARCHAR(40),INDEX idx_scope(IBLOCK_ELEMENT_ID,IBLOCK_PROPERTY_ID))');
    $p->exec("INSERT INTO b_iblock_property VALUES(81,8,'YA_METRIK_ID')");
    $p->exec("INSERT INTO b_iblock_element_property VALUES(81,111,'98615635'),(81,112,'12345678')");
    $add=static function(int $id,array $changes=[])use($p,$row):void{
        $at=(new DateTimeImmutable('@'.((int)$row['event_unix']-10)))->setTimezone(new DateTimeZone('Europe/London'))->format('Y-m-d H:i:s');
        $v=array_replace(['id'=>$id,'uid'=>$row['external_user_id'],'cid'=>'','yclid'=>'119988776655443322','at'=>$at,'source'=>'111','metric'=>''],$changes);
        $p->prepare('INSERT INTO max_sub_links VALUES(?,?,?,?,?,?,?)')->execute(array_values($v));
    };
    $repo=new MaxTrackerLookupRepository($p);$read=static fn():array=>$repo->retainedClickCandidate($ctx,$hash,$now)['result'];
    $add(1);$oldClass=$p->getAttribute(PDO::ATTR_STATEMENT_CLASS);$p->setAttribute(PDO::ATTR_STATEMENT_CLASS,[ForecastV1ObservedStatement::class]);
    ForecastV1ObservedStatement::$observePredictive=true;ForecastV1ObservedStatement::$predictiveStatements=[];ForecastV1ObservedStatement::$predictiveCopies=[];
    try{$positive=$read();$sql=ForecastV1ObservedStatement::$predictiveStatements;$copies=ForecastV1ObservedStatement::$predictiveCopies;}
    finally{ForecastV1ObservedStatement::$observePredictive=false;$p->setAttribute(PDO::ATTR_STATEMENT_CLASS,$oldClass);}
    $check('native tracker YCLID-only candidate uses exactly metadata and a bounded prefix before exact counter or clock filters',
        $positive[0]===1&&$positive[4]===2&&count($sql)===2
        &&str_contains($sql[1]['sql'],'UF_CHAT_ID LIKE ? LIMIT 65) s')&&!str_contains($sql[1]['sql'],'ORDER BY')
        &&strlen(json_encode($copies,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR))<=8192);
    $exactSelection=$selectionFor($ctx,$positive);$exactHash=str_repeat('e',64);
    $p->setAttribute(PDO::ATTR_STATEMENT_CLASS,[ForecastV1ObservedStatement::class]);ForecastV1ObservedStatement::$observePredictive=true;
    ForecastV1ObservedStatement::$predictiveStatements=[];ForecastV1ObservedStatement::$predictiveCopies=[];
    try{$exact=$repo->retainedClickRehydrate($exactSelection,$exactHash,$now);$exactSql=ForecastV1ObservedStatement::$predictiveStatements;$exactCopies=ForecastV1ObservedStatement::$predictiveCopies;}
    finally{ForecastV1ObservedStatement::$observePredictive=false;$p->setAttribute(PDO::ATTR_STATEMENT_CLASS,$oldClass);}
    $check('native rehydration performs only one bounded exact primary-key SELECT and returns the same private tuple',
        $exact['result'][0]===1&&$exact['result'][2][3]==='119988776655443322'&&count($exactSql)===1
        &&str_contains($exactSql[0]['sql'],'FORCE INDEX (PRIMARY) WHERE ID=? LIMIT 1) s')
        &&!str_contains($exactSql[0]['sql'],'information_schema')&&!str_contains($exactSql[0]['sql'],'UF_CHAT_ID LIKE')&&!str_contains($exactSql[0]['sql'],'ORDER BY')
        &&strlen(json_encode($exactCopies,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR))<=8192
        &&MaxTrackerLookupProtocol::retainedClickRehydrateResult($exact,$exactSelection,$exactHash,$now)===$exact['result']
        &&!$p->inTransaction()&&$exact['financial_permission']===false);
    $add(2,['yclid'=>'229988776655443322']);
    $check('an additional conflicting source row never reruns or changes the already selected exact record',
        $repo->retainedClickRehydrate($exactSelection,$exactHash,$now)['result']===$exact['result']);
    foreach(['UF_CHAT_ID'=>$row['external_user_id'].' ','UF_CLIENT_ID'=>'991144','UF_YCLID'=>'991155','UF_DATE'=>'2026-10-25 01:30:00',
        'UF_SOURCE'=>'112','UF_METRIC_SOURCE'=>'112']as$field=>$value){
        $p->exec('DELETE FROM max_sub_links WHERE ID=1');$add(1);
        $p->prepare('UPDATE max_sub_links SET '.$field.'=? WHERE ID=1')->execute([$value]);
        $changed=$repo->retainedClickRehydrate($exactSelection,$exactHash,$now);
        $check('changed exact selected tuple is unavailable without any fallback '.$field,$changed['result']===[2,$now,null]&&!$p->inTransaction());
    }
    $p->exec('DELETE FROM max_sub_links WHERE ID=1');
    $check('a missing selected primary key does not fall back to another retained source',
        $repo->retainedClickRehydrate($exactSelection,$exactHash,$now)['result']===[0,$now,null]);
    $add(1,['yclid'=>str_repeat('9',12000)]);
    $check('exact-record oversized source material is rejected by SQL before copying the private tuple',
        $repo->retainedClickRehydrate($exactSelection,$exactHash,$now)['result']===[4,$now,null]);
    $p->exec('DELETE FROM max_sub_links');$add(1);
    $p->setAttribute(PDO::ATTR_STATEMENT_CLASS,[ForecastV1ObservedStatement::class]);
    ForecastV1ObservedStatement::$afterRetainedSourceFetch=static function(string $sql):void{if(str_contains($sql,'retained_click_record'))usleep(510000);};
    try{$lateExact=$repo->retainedClickRehydrate($exactSelection,$exactHash,$now);}
    finally{ForecastV1ObservedStatement::$afterRetainedSourceFetch=null;$p->setAttribute(PDO::ATTR_STATEMENT_CLASS,$oldClass);}
    $check('a late exact-record response cannot publish raw material even after a successful SELECT',
        $lateExact['result']===[5,$now,null]&&!$p->inTransaction());
    $p->beginTransaction();$busyExact=$repo->retainedClickRehydrate($exactSelection,$exactHash,$now);
    $check('exact-record reads never borrow or close a caller transaction',$busyExact['result']===[6,$now,null]&&$p->inTransaction());$p->rollBack();
    $knownId=$original['r'][1][1];$q=$p->prepare('SELECT * FROM max_channel_conversion_outbox WHERE id=? LIMIT 1');$q->execute([$knownId]);$knownRow=$q->fetch(PDO::FETCH_ASSOC);
    $boundary=$now+20;$knownBinding=ModeledConversionRetainedTrackerIdentityEvidence::knownBinding($original,$knownRow,$ns,$boundary);
    $knownContext=ModeledConversionRetainedTrackerIdentityEvidence::knownContext($original,$knownRow,$ns,$boundary);
    $savedNoPrior=[];foreach(ModeledConversionValueVisitRecovery::originals($original,$ns,$boundary)as$ref=>$old)$savedNoPrior[]=[$ref,$boundary,$boundary,7,null,0];
    $knownPair=ModeledConversionFixedPairDecisionEvidence::merge(null,$original,$ns,$savedNoPrior,$boundary);
    $knownIntent=ModeledConversionRetainedTrackerIdentityEvidence::knownIntent($original,$knownRow,$ns,$hash,$boundary,$knownPair);
    $p->exec('DELETE FROM max_sub_links');$selectedYclid='119988776655443322';
    $click=(new DateTimeImmutable('@'.($knownContext['event_at_unix']-1)))->setTimezone(new DateTimeZone('Europe/London'))->format('Y-m-d H:i:s');
    $add(1,['uid'=>$knownRow['external_user_id'],'cid'=>$knownBinding['held_client_id'],'yclid'=>$selectedYclid,'at'=>$click]);
    $mixedWire=$repo->retainedClickCandidate($knownContext,$hash,$boundary+1);
    $mixedDone=ModeledConversionRetainedTrackerIdentityEvidence::knownComplete($knownIntent,$original,$knownRow,$ns,
        ['request_sha256'=>$hash,'result'=>$mixedWire['result'],'received_at_unix'=>$boundary+1],$boundary+1);
    $mixedView=ModeledConversionRetainedTrackerIdentityEvidence::knownProjection($mixedDone,$original,$knownRow,$ns,$boundary+1);
    $check('native CID-heldA plus different YCLID-B returns selectedYCLID mismatch without claiming CID absence invalidity or financial permission',
        $knownBinding['held_client_id']!==$selectedYclid&&$mixedView['kind']==='yclid'&&$mixedView['held_value_match']===false
        &&$p->query('SELECT UF_CLIENT_ID FROM max_sub_links WHERE ID=1')->fetchColumn()===$knownBinding['held_client_id']
        &&!isset($mixedView['cid_absent'])&&!isset($mixedView['invalid_cid'])&&!$mixedView['financial_permission']);
    $p->exec('DELETE FROM max_sub_links');
    $p->exec('ALTER TABLE max_sub_links MODIFY UF_CLIENT_ID BIGINT NULL,MODIFY UF_YCLID BIGINT NULL,MODIFY UF_METRIC_SOURCE BIGINT NULL DEFAULT 0');
    $add(1,['cid'=>'9988776655443322','metric'=>0]);
    $numeric=$read();$ordinary=$repo->lookup('98615635',[$row['external_user_id']]);
    $check('native integer identity fields stay canonical strings and integer0 metric source uses the exact ordinary fallback',
        $numeric[0]===1&&$numeric[4]===2&&$ordinary[0]['found']===true&&$ordinary[0]['client_id']==='9988776655443322');
    $numericExact=$repo->retainedClickRehydrate($selectionFor($ctx,$numeric),$exactHash,$now);
    $check('exact PK materialization preserves numeric identity strings and the original integer0 effective-source fallback',
        $numericExact['result'][0]===1&&$numericExact['result'][2][2]==='9988776655443322'
        &&$numericExact['result'][2][3]==='119988776655443322'&&$numericExact['result'][2][5]==='111');
    $p->exec('DELETE FROM max_sub_links');
    $p->exec('ALTER TABLE max_sub_links MODIFY UF_CLIENT_ID TEXT NULL,MODIFY UF_YCLID TEXT NULL,MODIFY UF_METRIC_SOURCE VARCHAR(20) NULL');
    $add(1);
    $add(2,['at'=>(new DateTimeImmutable('@'.((int)$row['event_unix']+1)))->setTimezone(new DateTimeZone('Europe/London'))->format('Y-m-d H:i:s'),'yclid'=>'229988776655443322']);
    $late=$read();$check('valid post-event tracker clicks are excluded without poisoning an earlier recorded click',$late[0]===1&&$late[3]===1);
    $p->exec('DELETE FROM max_sub_links WHERE ID=2');$add(2,['yclid'=>'229988776655443322']);
    $check('distinct pre-event tracker identities are ambiguous rather than latest-wins',$read()[0]===2);
    $p->exec('DELETE FROM max_sub_links');$add(1,['source'=>'112']);
    $check('a different effective counter supplies no candidate',$read()[0]===0);
    $p->exec('DELETE FROM max_sub_links');$add(1,['uid'=>$row['external_user_id'].' ']);
    $check('padded subject storage never becomes an exact identity witness',$read()[0]===6);
    $p->exec('DELETE FROM max_sub_links');$add(1,['yclid'=>str_repeat('9',12000)]);
    $large=$read();$check('oversized source values are stopped by SQL packet guard before native copy',$large[0]===5);
    $p->exec('DELETE FROM max_sub_links');$prefix=substr($row['external_user_id'],0,3);
    for($i=1;$i<=65;$i++)$add($i,['uid'=>$prefix.str_pad((string)$i,12,'0',STR_PAD_LEFT)]);
    $check('the65 sentinel covers the full prefix before subject counter and date filtering',$read()[0]===4);
    $p->exec('DELETE FROM max_sub_links');$add(1);
    $p->setAttribute(PDO::ATTR_STATEMENT_CLASS,[ForecastV1ObservedStatement::class]);
    ForecastV1ObservedStatement::$afterRetainedSourceFetch=static function(string $sql):void{if(str_contains($sql,'retained_click_point'))usleep(510000);};
    try{$slow=$read();}finally{ForecastV1ObservedStatement::$afterRetainedSourceFetch=null;$p->setAttribute(PDO::ATTR_STATEMENT_CLASS,$oldClass);}
    $check('a late tracker point result is rejected after rollback even when both SQL statements succeeded',$slow[0]===9&&!$p->inTransaction());
    $p->exec('ALTER TABLE max_sub_links DROP INDEX idx_chat_id,MODIFY UF_CHAT_ID BIGINT,ADD INDEX idx_chat_id(UF_CHAT_ID)');
    $p->setAttribute(PDO::ATTR_STATEMENT_CLASS,[ForecastV1ObservedStatement::class]);
    ForecastV1ObservedStatement::$observePredictive=true;ForecastV1ObservedStatement::$predictiveStatements=[];
    try{$numericSubject=$read();$typeQueries=ForecastV1ObservedStatement::$predictiveStatements;}
    finally{ForecastV1ObservedStatement::$observePredictive=false;$p->setAttribute(PDO::ATTR_STATEMENT_CLASS,$oldClass);}
    $check('a full numeric subject index refuses nonsargable LIKE before any point read',
        $numericSubject[0]===3&&count($typeQueries)===1&&str_contains($typeQueries[0]['sql'],'information_schema.COLUMNS'));
    $p->exec('ALTER TABLE max_sub_links DROP INDEX idx_chat_id,MODIFY UF_CHAT_ID TEXT,ADD INDEX idx_chat_id(UF_CHAT_ID(15))');
    $restoredPrefix=$read();
    $check('a text subject with the retained15-character prefix remains supported without index changes',$restoredPrefix[0]===1);
    $p->exec('ALTER TABLE max_sub_links DROP COLUMN UF_YCLID');$unsupported=$read();
    $check('missing actual YCLID schema is explicit unsupported rather than guessed from legacy documentation',$unsupported[0]===3);
    foreach(['max_sub_links','b_iblock_element_property','b_iblock_property']as$t)$p->exec('DROP TABLE '.$t);
}


/** One original case, local immutable witnesses, no actual provider/worker invocation. */
function forecastRetainedAppIdentityAssertions(PDO $p,callable $check,array $original,array $row,int $now):array
{
    forecastRetainedTrackerAssertions($p,$check,$original,$row,$now);
    forecastKnownYclidRelationAssertions($p,$check,$original,$now);
    $ns=ModeledConversionProfiles::assessmentNamespace();$key=ModeledConversionRetainedAppIdentityEvidence::RECEIPT_KEY;
    $intent=ModeledConversionRetainedAppIdentityEvidence::intent($original,$row,$ns,$now);
    $check('retained app intent binds the existing missing case without embedding a raw identity',$intent!==null&&!str_contains(json_encode($intent,JSON_THROW_ON_ERROR),'"'.$row['external_user_id'].'"'));
    foreach(['5.7.44','10.11.7-MariaDB','8.0.33-MariaDB','unknown']as$version){
        $unsupported=new class($version) extends PDO {
            public function __construct(private string $version){}
            public function getAttribute(int $attribute):mixed{return $attribute===PDO::ATTR_DRIVER_NAME?'mysql':$this->version;}
        };
        $refused=ModeledConversionRetainedAppIdentityEvidence::observe($unsupported,$intent,$row,$now);
        $check('an unsupported server cannot execute source SQL under an ignored timeout hint',$refused[0]===11);
    }
    $mysql=$p->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql';
    if(!$mysql){
        $refused=ModeledConversionRetainedAppIdentityEvidence::observe($p,$intent,$row,$now);
        $check('SQLite runtime refuses source reads without a supported execution deadline',$refused[0]===11);
        $synthetic=[1,$now,1,1,0,2,str_repeat('d',64),'1',str_repeat('c',64),(int)$row['event_unix']-30,(int)$row['event_unix']-20,(int)$row['event_unix']-10];
        $done=ModeledConversionRetainedAppIdentityEvidence::complete($intent,$original,$row,$ns,$synthetic,$now);
        $projection=ModeledConversionRetainedAppIdentityEvidence::projection($done,$original,$row,$ns,$now);
        $malformed=$done;unset($malformed['h']);$malformed['PRIVATE_UNKNOWN_KEY']='PRIVATE_VALUE';
        set_error_handler(static function(int $severity,string $message):never{throw new ErrorException($message,0,$severity);});
        try{$bad=ModeledConversionRetainedAppIdentityEvidence::projection($malformed,$original,$row,$ns,$now);}
        finally{restore_error_handler();}
        $check('pure saved candidate projection binds the case stays compact and rejects malformed keys without source SQL',
            $projection['status']==='candidate'&&$projection['kind']==='yclid'&&!$projection['financial_permission']
            &&strlen(json_encode($projection,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR))<=225&&!$bad['available']);
        return $projection;
    }
    foreach(['advertising_visit_observations','advertising_visit_cursors']as$table)$p->exec('DROP TABLE IF EXISTS '.$table);
    $nativeExists=json_decode($p->query('SELECT JSON_ARRAY(EXISTS(SELECT 1 WHERE 1=0),EXISTS(SELECT 1),CASE WHEN EXISTS(SELECT 1 WHERE 1=0) THEN 1 ELSE 0 END,CASE WHEN EXISTS(SELECT 1) THEN 1 ELSE 0 END)')->fetchColumn(),true,8,JSON_THROW_ON_ERROR);
    $nativeTypes=array_map(static fn(mixed $v):string=>get_debug_type($v),$nativeExists);
    $check('native owner-conflict JSON types raw_false='.$nativeTypes[0].' raw_true='.$nativeTypes[1].' with canonical CASE zero and one',
        $nativeExists[2]===0&&$nativeExists[3]===1);
    $collation=$mysql?' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci':' COLLATE RTRIM';
    $p->exec('CREATE TABLE advertising_visit_cursors(project_key VARCHAR(40)'.$collation.',source_key VARCHAR(80)'.$collation.',platform VARCHAR(16)'.$collation.',counter_id VARCHAR(20)'.$collation.',last_cursor VARCHAR(20),PRIMARY KEY(project_key,source_key,platform,counter_id))');
    $p->exec('CREATE TABLE advertising_visit_observations(id BIGINT PRIMARY KEY,project_key VARCHAR(40)'.$collation.',source_key VARCHAR(80)'.$collation.',platform VARCHAR(16)'.$collation.',counter_id VARCHAR(20)'.$collation.',external_user_id VARCHAR(32)'.$collation.',identity_status VARCHAR(16)'.$collation.',source_cursor VARCHAR(20),visit_id VARCHAR(128)'.$collation.',client_id VARCHAR(40),yclid VARCHAR(40),visit_at_unix BIGINT,observed_at_unix BIGINT,received_at_unix BIGINT,payload_sha256 CHAR(64))');
    $p->exec('CREATE INDEX idx_advertising_identity ON advertising_visit_observations(project_key,source_key,platform,counter_id,external_user_id,identity_status,observed_at_unix)');
    $p->exec('CREATE INDEX idx_advertising_visit ON advertising_visit_observations(project_key,source_key,platform,counter_id,visit_id)');
    $scope=static function(string $source='fixture_committed_max',string $project='anytour',string $counter='98615635')use($p):void{
        $p->prepare("INSERT INTO advertising_visit_cursors VALUES(?,?,'max',?,'1')")->execute([$project,$source,$counter]);
    };
    $visit=static function(int $id,array $changes=[])use($p,$row):void{
        $v=array_replace(['id'=>$id,'project_key'=>'anytour','source_key'=>'fixture_committed_max','platform'=>'max','counter_id'=>'98615635',
            'external_user_id'=>$row['external_user_id'],'identity_status'=>'verified','source_cursor'=>(string)$id,'visit_id'=>'fixture_visit_'.$id,
            'client_id'=>'','yclid'=>'119988776655443322','visit_at_unix'=>(int)$row['event_unix']-30,
            'observed_at_unix'=>(int)$row['event_unix']-20,'received_at_unix'=>(int)$row['event_unix']-10,'payload_sha256'=>str_repeat('c',64)],$changes);
        $p->prepare('INSERT INTO advertising_visit_observations VALUES('.implode(',',array_fill(0,count($v),'?')).')')->execute(array_values($v));
    };
    $read=static fn():array=>ModeledConversionRetainedAppIdentityEvidence::observe($p,$intent,$row,$now);
    $clear=static function()use($p):void{$p->exec('DELETE FROM advertising_visit_observations');$p->exec('DELETE FROM advertising_visit_cursors');};
    $none=$read();$check('no committed local MAX scope is unsupported rather than a guessed Bitrix source',$none[0]===3);
    $scope();$visit(1);$oldClass=$p->getAttribute(PDO::ATTR_STATEMENT_CLASS);
    $p->setAttribute(PDO::ATTR_STATEMENT_CLASS,[ForecastV1ObservedStatement::class]);
    ForecastV1ObservedStatement::$observePredictive=true;ForecastV1ObservedStatement::$predictiveStatements=[];ForecastV1ObservedStatement::$predictiveCopies=[];
    try{$single=$read();$sql=ForecastV1ObservedStatement::$predictiveStatements;$copies=ForecastV1ObservedStatement::$predictiveCopies;}
    finally{ForecastV1ObservedStatement::$observePredictive=false;$p->setAttribute(PDO::ATTR_STATEMENT_CLASS,$oldClass);}
    $done=ModeledConversionRetainedAppIdentityEvidence::complete($intent,$original,$row,$ns,$single,$now);
    $projection=ModeledConversionRetainedAppIdentityEvidence::projection($done,$original,$row,$ns,$now);
    $candidateValid=strlen(json_encode($intent,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR))===768
        &&$single[0]===1&&$single[5]===2&&$projection['status']==='candidate'&&$projection['kind']==='yclid'&&!$projection['financial_permission']
        &&strlen(json_encode($done,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR))<=768
        &&strlen(json_encode($projection,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR))<=225
        &&!str_contains(json_encode($projection,JSON_THROW_ON_ERROR),'119988776655443322')&&!isset($projection['observation_id']);
    if(!$candidateValid)echo 'RETAINED_APP_SYNTHETIC '.json_encode([
        'result_code'=>$single[0]??null,'kind_code'=>$single[5]??null,'saved_frame'=>is_array($done),'native_types'=>$nativeTypes,
        'private_bytes'=>strlen(json_encode($done,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)),
        'projection_bytes'=>strlen(json_encode($projection,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR))],JSON_THROW_ON_ERROR).PHP_EOL;
    $check('an immutable pre-event YCLID-only witness is a compact candidate without identity or financial promotion',$candidateValid);
    $malformed=$done;unset($malformed['h']);$malformed['PRIVATE_UNKNOWN_KEY']='PRIVATE_VALUE';
    set_error_handler(static function(int $severity,string $message):never{throw new ErrorException($message,0,$severity);});
    try{$malformedView=ModeledConversionRetainedAppIdentityEvidence::projection($malformed,$original,$row,$ns,$now);}
    finally{restore_error_handler();}
    $changed=$row;$changed['external_user_id'].='1';
    $check('malformed receipt keys produce no PHP warning and a changed subject cannot reuse the original intent',
        !$malformedView['available']&&$malformedView['reason']==='invalid_saved_journal'
        &&ModeledConversionRetainedAppIdentityEvidence::intent($original,$changed,$ns,$now)===null);
    $p->setAttribute(PDO::ATTR_STATEMENT_CLASS,[ForecastV1ObservedStatement::class]);
    $delayedCandidateReads=0;
    ForecastV1ObservedStatement::$afterRetainedSourceFetch=static function(string $sql)use(&$delayedCandidateReads):void{
        if(str_contains($sql,'retained_app_identity')){$delayedCandidateReads++;usleep(510000);}
    };
    try{$lateRead=$read();}
    finally{ForecastV1ObservedStatement::$afterRetainedSourceFetch=null;$p->setAttribute(PDO::ATTR_STATEMENT_CLASS,$oldClass);}
    $check('native delayed fetch cannot publish a late candidate after rollback',$delayedCandidateReads===1&&$lateRead[0]===10&&!$p->inTransaction());
    $copyBytes=array_sum(array_map(static fn(array $r):int=>strlen(json_encode($r,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)),$copies));
    $check('the source read has two bounded indexed SELECTs and one shared guarded8KiB copy budget',
        count($sql)===2&&$copyBytes<=8192&&str_contains($sql[0]['sql'],'LIMIT 33')&&str_contains($sql[1]['sql'],'LIMIT 65')
        &&!str_contains($sql[1]['sql'],"client_id<>")&&!str_contains($sql[1]['sql'],'ORDER BY')
        &&str_contains($sql[1]['sql'],'idx_advertising_identity')&&str_contains($sql[1]['sql'],'idx_advertising_visit'));
    $visit(2,['yclid'=>'228877665544332211','observed_at_unix'=>(int)$row['event_unix']+1,'received_at_unix'=>(int)$row['event_unix']+2]);
    $late=$read();$check('valid late enrichment cannot replace or poison an already saved pre-event witness',$late[0]===1&&$late[4]===1&&$late[6]===$single[6]);
    $p->exec('DELETE FROM advertising_visit_observations WHERE id=2');$visit(2,['yclid'=>'228877665544332211']);
    $check('different pre-event YCLIDs remain ambiguous without choosing the first unordered row',$read()[0]===2);
    $p->exec('DELETE FROM advertising_visit_observations WHERE id=2');$visit(2,['external_user_id'=>'999999','visit_id'=>'fixture_visit_1']);
    $check('a scoped visit owned by another verified user invalidates the local witness',$read()[0]===8);
    $p->exec('DELETE FROM advertising_visit_observations WHERE id=2');
    $p->prepare('UPDATE advertising_visit_observations SET external_user_id=? WHERE id=1')->execute([$row['external_user_id'].' ']);
    $check('padded subject equality cannot pass the exact returned binding check',$read()[0]===7);
    $clear();$scope('fixture_committed_max','anytour ');$check('padded cursor scope cannot grant source authority',$read()[0]===7);
    $clear();$scope('fixture_other','anytour','98615636');$check('a foreign counter never becomes a local source for this case',$read()[0]===3);
    $clear();$scope();for($id=1;$id<=65;$id++)$visit($id);
    $check('the65th retained row is a sentinel rather than a truncated complete witness pool',$read()[0]===5);
    $clear();
    for($i=0;$i<32;$i++)$scope('fixture_'.str_pad((string)$i,70,'a'));
    $check('more than eight target source scopes are unavailable without an observation wildcard',$read()[0]===4);
    $clear();$scope();for($i=0;$i<31;$i++)$scope('fixture_'.str_pad((string)$i,70,'b'),'anytour','98615636');
    for($i=1;$i<=20;$i++)$visit($i,['visit_id'=>'fixture_'.str_repeat('z',110).$i]);
    $check('scope bytes reduce the second packet allowance and oversized packets remain unavailable',$read()[0]===6);
    $clear();$scope();$visit(1);

    // Drive the real optional worker method; all tables and identities here are synthetic.
    $p->exec('ALTER TABLE modeled_conversion_value_export_settings MODIFY last_receipt_json JSON');
    $settings=$p->query("SELECT * FROM modeled_conversion_value_export_settings WHERE project_key='anytour'")->fetch(PDO::FETCH_ASSOC);
    $receipt=['finite_visit_evidence'=>$original,'finite_counter_scope_evidence'=>false,'errors'=>0];
    $p->prepare("UPDATE modeled_conversion_value_export_settings SET last_receipt_json=?,last_tick_unix=? WHERE project_key='anytour'")
        ->execute([json_encode($receipt,JSON_THROW_ON_ERROR),$now]);
    $created=[];
    foreach(['conversations'=>"id BIGINT PRIMARY KEY,customer_id BIGINT,customer_channel_id BIGINT,project_key VARCHAR(40),channel VARCHAR(16),is_test INT,external_chat_id VARCHAR(40)",
        'customer_channels'=>"id BIGINT PRIMARY KEY,customer_id BIGINT,project_key VARCHAR(40),channel VARCHAR(16),external_user_id VARCHAR(32),external_chat_id VARCHAR(40)"]as$table=>$ddl){
        try{$p->query('SELECT 1 FROM '.$table.' LIMIT 1');}catch(PDOException $missing){$p->exec('CREATE TABLE '.$table.'('.$ddl.')');$created[]=$table;}
    }
    $chat='-'.$row['external_user_id'];$insert=$mysql?'INSERT IGNORE':'INSERT OR IGNORE';
    $p->prepare($insert." INTO customer_channels(id,customer_id,project_key,channel,external_user_id,external_chat_id) VALUES(970001,960001,'anytour','max',?,?)")->execute([$row['external_user_id'],$chat]);
    $p->prepare($insert." INTO conversations(id,customer_id,customer_channel_id,project_key,channel,is_test,external_chat_id) VALUES(?,960001,970001,'anytour','max',0,?)")->execute([$row['conversation_id'],$chat]);
    $p->prepare("UPDATE modeled_conversion_profile_versions SET profile_json=? WHERE id=?")->execute(['{"access_status":"allowed"}',$row['value_profile_id']]);
    $p->prepare("UPDATE max_channel_conversion_outbox SET state='pending',attempts=0,identity_source_key='max_tracker',client_id=NULL,tracker_record_id=NULL,tracker_observed_unix=NULL,claim_token=NULL,claimed_at_unix=NULL,upload_id=NULL,upload_status=NULL,value_receipt_basis=NULL,value_ready_at_unix=NULL,value_api_accepted_at_unix=NULL,value_processed_at_unix=NULL WHERE id=?")->execute([$row['id']]);
    $nativeBefore=$p->query('SELECT * FROM max_channel_conversion_outbox ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
    $worker=new ModeledConversionValueExport($p);
    foreach(['finiteFrame'=>$original,'finiteRows'=>[$original['r'][0][0]=>$row]]as$property=>$value)(new ReflectionProperty($worker,$property))->setValue($worker,$value);
    $persistCurrent=new ReflectionMethod($worker,'persistTickReceipt');
    $currentStats=['collected'=>2,'resolved'=>1,'accepted'=>4,'processed'=>1,'unknown'=>0,'errors'=>0,'metrika_report'=>['status'=>'unavailable']];
    $currentCommitted=$persistCurrent->invoke($worker,$currentStats,null,$now);
    $phase=new ReflectionMethod($worker,'runRetainedAppEvidence');$sourceCount=0;$durable=true;
    $p->setAttribute(PDO::ATTR_STATEMENT_CLASS,[ForecastV1ObservedStatement::class]);
    ForecastV1ObservedStatement::$onRetainedSource=static function()use($p,$key,&$sourceCount,&$durable):void{
        $sourceCount++;$r=json_decode($p->query("SELECT last_receipt_json FROM modeled_conversion_value_export_settings WHERE project_key='anytour'")->fetchColumn(),true,32,JSON_THROW_ON_ERROR);
        $durable=$durable&&array_key_exists($key,$r)&&$r[$key]['s']===1&&$r['accepted']===4&&$r['processed']===1&&$r['collected']===2;
    };
    try{
        $p->prepare("UPDATE max_channel_conversion_outbox SET state='ready' WHERE id=?")->execute([$row['id']]);
        $phase->invoke($worker,$now,static fn(string $chat):bool=>true);
        $check('post-financial revalidation prevents source reads after the original row advances',$sourceCount===0);
        $p->prepare("UPDATE max_channel_conversion_outbox SET state='pending' WHERE id=?")->execute([$row['id']]);
        $phase->invoke($worker,$now,static fn(string $chat):bool=>true);
    }
    finally{ForecastV1ObservedStatement::$onRetainedSource=null;$p->setAttribute(PDO::ATTR_STATEMENT_CLASS,$oldClass);}
    $saved=json_decode($p->query("SELECT last_receipt_json FROM modeled_conversion_value_export_settings WHERE project_key='anytour'")->fetchColumn(),true,32,JSON_THROW_ON_ERROR);
    $check('the real optional phase reads only after current financial counts and its distinct intent are committed without changing financial rows',
        $currentCommitted===true&&$sourceCount===2&&$durable&&($saved[$key]['s']??null)===2&&$saved['finite_counter_scope_evidence']===false
        &&$p->query('SELECT * FROM max_channel_conversion_outbox ORDER BY id')->fetchAll(PDO::FETCH_ASSOC)===$nativeBefore);
    $unknown=$saved;$unknown[$key]=$intent;
    $p->prepare("UPDATE modeled_conversion_value_export_settings SET last_receipt_json=? WHERE project_key='anytour'")->execute([json_encode($unknown,JSON_THROW_ON_ERROR)]);
    $again=new ModeledConversionValueExport($p);foreach(['finiteFrame'=>$original,'finiteRows'=>[$original['r'][0][0]=>$row]]as$property=>$value)(new ReflectionProperty($again,$property))->setValue($again,$value);
    $p->setAttribute(PDO::ATTR_STATEMENT_CLASS,[ForecastV1ObservedStatement::class]);$sourceCount=0;
    ForecastV1ObservedStatement::$onRetainedSource=static function()use(&$sourceCount):void{$sourceCount++;};
    try{$phase->invoke($again,$now+1,static fn(string $chat):bool=>true);}
    finally{ForecastV1ObservedStatement::$onRetainedSource=null;$p->setAttribute(PDO::ATTR_STATEMENT_CLASS,$oldClass);}
    $check('an already consumed UNKNOWN intent never retries its source read',$sourceCount===0);
    $blocked=['finite_visit_evidence'=>$original,'padding'=>''];
    $blocked['padding']=str_repeat('b',max(0,8100-strlen(json_encode($blocked,JSON_THROW_ON_ERROR))));
    $p->prepare("UPDATE modeled_conversion_value_export_settings SET last_receipt_json=? WHERE project_key='anytour'")->execute([json_encode($blocked,JSON_THROW_ON_ERROR)]);
    $blockedWorker=new ModeledConversionValueExport($p);foreach(['finiteFrame'=>$original,'finiteRows'=>[$original['r'][0][0]=>$row]]as$property=>$value)(new ReflectionProperty($blockedWorker,$property))->setValue($blockedWorker,$value);
    $p->setAttribute(PDO::ATTR_STATEMENT_CLASS,[ForecastV1ObservedStatement::class]);$sourceCount=0;
    ForecastV1ObservedStatement::$onRetainedSource=static function()use(&$sourceCount):void{$sourceCount++;};
    try{$phase->invoke($blockedWorker,$now+1,static fn(string $chat):bool=>true);}
    finally{ForecastV1ObservedStatement::$onRetainedSource=null;$p->setAttribute(PDO::ATTR_STATEMENT_CLASS,$oldClass);}
    $check('an intent that cannot fit private receipt budget prevents either source read',$sourceCount===0);
    $measure=static function(array $v)use($p):int{
        $q=$p->prepare('SELECT OCTET_LENGTH(CAST(? AS JSON))');$q->execute([json_encode($v,JSON_THROW_ON_ERROR)]);return (int)$q->fetchColumn();
    };
    $compact=$intent;$compact['o']=null;
    $capacity=['finite_visit_evidence'=>$original,$key=>$compact,'padding'=>''];
    $capacity['padding']=str_repeat('r',max(0,8150-$measure($capacity)));
    $larger=$capacity;$larger[$key]=$done;
    $check('the reservation fixture has room for the former compact intent but not its larger completed result',
        $measure($capacity)<=8192&&$measure($larger)>8192);
    unset($capacity[$key]);
    $p->prepare("UPDATE modeled_conversion_value_export_settings SET last_receipt_json=? WHERE project_key='anytour'")->execute([json_encode($capacity,JSON_THROW_ON_ERROR)]);
    $reservedWorker=new ModeledConversionValueExport($p);foreach(['finiteFrame'=>$original,'finiteRows'=>[$original['r'][0][0]=>$row]]as$property=>$value)(new ReflectionProperty($reservedWorker,$property))->setValue($reservedWorker,$value);
    $p->setAttribute(PDO::ATTR_STATEMENT_CLASS,[ForecastV1ObservedStatement::class]);$sourceCount=0;
    ForecastV1ObservedStatement::$onRetainedSource=static function()use(&$sourceCount):void{$sourceCount++;};
    try{$phase->invoke($reservedWorker,$now+1,static fn(string $chat):bool=>true);}
    finally{ForecastV1ObservedStatement::$onRetainedSource=null;$p->setAttribute(PDO::ATTR_STATEMENT_CLASS,$oldClass);}
    $reservationHeld=json_decode($p->query("SELECT last_receipt_json FROM modeled_conversion_value_export_settings WHERE project_key='anytour'")->fetchColumn(),true,32,JSON_THROW_ON_ERROR);
    $check('a full result slot is reserved before source reads rather than losing a completed observation',
        $sourceCount===0&&!array_key_exists($key,$reservationHeld));
    $merge=new ReflectionMethod($worker,'withRetainedAppEvidence');
    $base=['finite_visit_evidence'=>$original,'finite_visit_recovery'=>['immutable'=>'forward'],'fixed_pair_operational_decisions'=>['immutable'=>'pair'],
        'finite_counter_scope_evidence'=>false,'last_recorded_failure'=>['immutable'=>'failure'],'padding'=>''];
    $base['padding']=str_repeat('p',max(0,8150-$measure($base+[$key=>false])));
    $check('native pressure fixture fits its complete protected frame and tombstone but not the full optional result',
        $measure($base+[$key=>false])<=8192&&$measure($base+[$key=>$done])>8192);
    $pressure=$merge->invoke($worker,$base,[$key=>$done],$mysql);
    $check('private receipt pressure keeps a consumed tombstone without erasing old proof or failure',
        $pressure!==null&&$pressure[$key]===false&&$pressure['finite_visit_evidence']===$original
        &&$pressure['finite_visit_recovery']===$base['finite_visit_recovery']&&$pressure['fixed_pair_operational_decisions']===$base['fixed_pair_operational_decisions']
        &&$pressure['finite_counter_scope_evidence']===$base['finite_counter_scope_evidence']&&$pressure['last_recorded_failure']===$base['last_recorded_failure']);
    $lateMerged=$merge->invoke($worker,['errors'=>0],[$key=>$intent],$mysql);
    $check('a delayed healthy writer preserves the previously consumed UNKNOWN token',$lateMerged[$key]===$intent);
    $p->exec('DROP INDEX idx_advertising_identity'.($mysql?' ON advertising_visit_observations':''));
    $failed=$read();$check('source storage failure is explicit metadata and does not throw into the financial sender',$failed[0]===9);
    $p->prepare("UPDATE modeled_conversion_value_export_settings SET last_receipt_json=? WHERE project_key='anytour'")->execute([json_encode($receipt,JSON_THROW_ON_ERROR)]);
    $failedWorker=new ModeledConversionValueExport($p);foreach(['finiteFrame'=>$original,'finiteRows'=>[$original['r'][0][0]=>$row]]as$property=>$value)(new ReflectionProperty($failedWorker,$property))->setValue($failedWorker,$value);
    $phase->invoke($failedWorker,$now+2,static fn(string $chat):bool=>true);
    $persist=new ReflectionMethod($failedWorker,'persistTickReceipt');
    $financial=['collected'=>2,'resolved'=>1,'accepted'=>3,'processed'=>0,'unknown'=>0,'errors'=>0,'metrika_report'=>['status'=>'unavailable']];
    $persist->invoke($failedWorker,$financial,null,$now+2);
    $afterFailure=json_decode($p->query("SELECT last_receipt_json FROM modeled_conversion_value_export_settings WHERE project_key='anytour'")->fetchColumn(),true,32,JSON_THROW_ON_ERROR);
    $lateCommitted=$persist->invoke($failedWorker,['accepted'=>0,'errors'=>0,'metrika_report'=>['status'=>'unavailable']],null,$now+1);
    $afterLate=json_decode($p->query("SELECT last_receipt_json FROM modeled_conversion_value_export_settings WHERE project_key='anytour'")->fetchColumn(),true,32,JSON_THROW_ON_ERROR);
    $check('optional source failure and a late healthy tick preserve successful financial counts and the consumed local token',
        $lateCommitted===false&&$afterFailure['accepted']===3&&$afterFailure['errors']===0&&array_key_exists($key,$afterFailure)
        &&$afterLate[$key]===$afterFailure[$key]&&$afterLate['accepted']===3);


    // The transport double never performs HTTP. Actual receiver SQL is exercised above on the same native database.
    $trackerKey=ModeledConversionRetainedTrackerIdentityEvidence::RECEIPT_KEY;
    $trackerBase=json_decode($p->query("SELECT last_receipt_json FROM modeled_conversion_value_export_settings WHERE project_key='anytour'")->fetchColumn(),true,32,JSON_THROW_ON_ERROR);
    $appIntent=ModeledConversionRetainedAppIdentityEvidence::intent($original,$row,$ns,$now);
    $trackerBase[$key]=ModeledConversionRetainedAppIdentityEvidence::complete($appIntent,$original,$row,$ns,[3,$now,0,null,null,0,null,null,null,null,null,null],$now);
    unset($trackerBase[$trackerKey]);$trackerBase['accepted']=3;$trackerBase['errors']=0;
    $p->prepare("UPDATE modeled_conversion_value_export_settings SET last_receipt_json=? WHERE project_key='anytour'")->execute([json_encode($trackerBase,JSON_THROW_ON_ERROR)]);
    $transport=new class($p,$now,$trackerKey) {
        public int $reads=0;public bool $durable=true;
        public function __construct(private PDO $p,private int $now,private string $key){}
        public function retainedClickCandidate(array $context,callable $beforeRequest):?array{
            $hash=str_repeat('a',64);if(!$beforeRequest($hash))return null;
            $saved=json_decode($this->p->query("SELECT last_receipt_json FROM modeled_conversion_value_export_settings WHERE project_key='anytour'")->fetchColumn(),true,32,JSON_THROW_ON_ERROR);
            $this->durable=$this->durable&&($saved['accepted']??null)===3&&($saved['errors']??null)===0
                &&($saved[$this->key]['s']??null)===1&&strlen(json_encode($saved[$this->key],JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR))===768;
            $this->reads+=2;
            return ['request_sha256'=>$hash,'result'=>[1,$this->now,1,0,2,str_repeat('d',64),'1',str_repeat('c',64),$context['event_at_unix']-10],'received_at_unix'=>$this->now];
        }
    };
    $trackerWorker=new ModeledConversionValueExport($p);
    foreach(['finiteFrame'=>$original,'finiteRows'=>[$original['r'][0][0]=>$row],'retainedTrackerReservation'=>$row,
        'ordinaryLookupUsers'=>array_map(static fn(int $n):string=>(string)(850000+$n),range(1,24))]as$prop=>$value)
        (new ReflectionProperty($trackerWorker,$prop))->setValue($trackerWorker,$value);
    $trackerPhase=new ReflectionMethod($trackerWorker,'runRetainedTrackerEvidence');
    $outboxBefore=$p->query('SELECT * FROM max_channel_conversion_outbox ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
    $trackerPhase->invoke($trackerWorker,$transport,$now,static fn(string $chat):bool=>true);
    $shared=json_decode($p->query("SELECT last_receipt_json FROM modeled_conversion_value_export_settings WHERE project_key='anytour'")->fetchColumn(),true,32,JSON_THROW_ON_ERROR);
    $check('no optional tracker transport or intent may exceed the shared25 lookup budget',$transport->reads===0&&!array_key_exists($trackerKey,$shared));
    (new ReflectionProperty($trackerWorker,'ordinaryLookupUsers'))->setValue($trackerWorker,[]);
    $p->prepare("UPDATE max_channel_conversion_outbox SET state='ready' WHERE id=?")->execute([$row['id']]);
    $trackerPhase->invoke($trackerWorker,$transport,$now,static fn(string $chat):bool=>true);
    $check('post-financial advancement prevents a tracker observation without changing the original delivery row',$transport->reads===0);
    $p->prepare("UPDATE max_channel_conversion_outbox SET state='pending' WHERE id=?")->execute([$row['id']]);
    $trackerPhase->invoke($trackerWorker,$transport,$now,static fn(string $chat):bool=>true);
    $trackerSaved=json_decode($p->query("SELECT last_receipt_json FROM modeled_conversion_value_export_settings WHERE project_key='anytour'")->fetchColumn(),true,32,JSON_THROW_ON_ERROR);
    $trackerView=ModeledConversionRetainedTrackerIdentityEvidence::projection($trackerSaved[$trackerKey]??null,$original,$row,$ns,time());
    $check('current financial counts and a full distinct consumed slot precede candidate transport without identity or financial changes',
        $transport->reads===2&&$transport->durable&&$trackerView['status']==='candidate'&&!$trackerView['financial_permission']
        &&$trackerSaved['accepted']===3&&$trackerSaved['finite_counter_scope_evidence']===($trackerBase['finite_counter_scope_evidence']??null)
        &&$p->query('SELECT * FROM max_channel_conversion_outbox ORDER BY id')->fetchAll(PDO::FETCH_ASSOC)===$outboxBefore);
    $unknownTracker=$trackerSaved;$unknownTracker[$trackerKey]=ModeledConversionRetainedTrackerIdentityEvidence::intent($original,$row,$ns,str_repeat('a',64),$now);
    $p->prepare("UPDATE modeled_conversion_value_export_settings SET last_receipt_json=? WHERE project_key='anytour'")->execute([json_encode($unknownTracker,JSON_THROW_ON_ERROR)]);
    $againTracker=new ModeledConversionValueExport($p);
    foreach(['finiteFrame'=>$original,'finiteRows'=>[$original['r'][0][0]=>$row],'retainedTrackerReservation'=>$row,'ordinaryLookupUsers'=>[]]as$prop=>$value)
        (new ReflectionProperty($againTracker,$prop))->setValue($againTracker,$value);
    $trackerPhase->invoke($againTracker,$transport,$now+1,static fn(string $chat):bool=>true);
    $check('a consumed tracker UNKNOWN cannot repackage the old count or app request as a retry',$transport->reads===2);
    $trackerMerge=new ReflectionMethod($trackerWorker,'withRetainedTrackerEvidence');
    $trackerPressure=$base;$trackerPressure['padding']=str_repeat('q',max(0,8150-$measure(array_replace($base,['padding'=>''],[$trackerKey=>false]))));
    $tombstone=$trackerMerge->invoke($trackerWorker,$trackerPressure,[$trackerKey=>$trackerSaved[$trackerKey]],$mysql);
    $check('native JSON pressure preserves all old proof and failure plus the permanent tracker tombstone',
        $tombstone!==null&&$tombstone[$trackerKey]===false&&$tombstone['finite_visit_evidence']===$base['finite_visit_evidence']
        &&$tombstone['finite_visit_recovery']===$base['finite_visit_recovery']&&$tombstone['finite_counter_scope_evidence']===$base['finite_counter_scope_evidence']
        &&$tombstone['fixed_pair_operational_decisions']===$base['fixed_pair_operational_decisions']&&$tombstone['last_recorded_failure']===$base['last_recorded_failure']);
    $coupled=array_replace($base,['padding'=>'','accepted'=>3,'errors'=>0],[$key=>$appIntent]);
    $coupled['padding']=str_repeat('z',max(0,8180-$measure($coupled)));
    $withMarker=$coupled;$withMarker[$trackerKey]=false;
    $bothMarkers=$withMarker;$bothMarkers[$key]=false;
    $check('the native coupled pressure fixture admits the old full payload alone but only both markers fit after tracker consumption',
        $measure($coupled)<=8192&&$measure($withMarker)>8192&&$measure($bothMarkers)<=8192);
    $incoming=$coupled;unset($incoming[$key]);
    $coupledSaved=$merge->invoke($trackerWorker,$incoming,[$key=>$appIntent,$trackerKey=>$trackerSaved[$trackerKey]],$mysql);
    $check('both optional payloads yield to fresh financial counts while old proofs failure and consumed markers remain',
        $coupledSaved!==null&&$coupledSaved[$key]===false&&$coupledSaved[$trackerKey]===false
        &&$coupledSaved['accepted']===3&&$coupledSaved['errors']===0
        &&$coupledSaved['finite_visit_evidence']===$base['finite_visit_evidence']
        &&$coupledSaved['finite_visit_recovery']===$base['finite_visit_recovery']
        &&$coupledSaved['fixed_pair_operational_decisions']===$base['fixed_pair_operational_decisions']
        &&$coupledSaved['finite_counter_scope_evidence']===$base['finite_counter_scope_evidence']
        &&$coupledSaved['last_recorded_failure']===$base['last_recorded_failure']);
    $lateTracker=$trackerMerge->invoke($trackerWorker,['accepted'=>3],[$trackerKey=>$unknownTracker[$trackerKey]],$mysql);
    $check('a late writer preserves the separate consumed tracker token',$lateTracker[$trackerKey]===$unknownTracker[$trackerKey]);


    $fields=array_keys($settings);$p->prepare('UPDATE modeled_conversion_value_export_settings SET '.implode(',',array_map(static fn(string $c):string=>$c.'=?',$fields))." WHERE project_key='anytour'")->execute(array_values($settings));
    $p->prepare('DELETE FROM conversations WHERE id=?')->execute([$row['conversation_id']]);$p->exec('DELETE FROM customer_channels WHERE id=970001');
    foreach($created as$table)$p->exec('DROP TABLE '.$table);
    return $projection;
}

/** One finite roster uses the existing aggregate; all native identifiers stay inside the read. */
function forecastProcessingFrontierAssertions(PDO $p,callable $check,array $retainedPositive,?array $knownPositive=null):void
{
    foreach(['max_channel_conversion_outbox','modeled_conversion_profile_versions']as$table)$p->exec('DROP TABLE IF EXISTS '.$table);
    $p->exec("CREATE TABLE modeled_conversion_profile_versions(id BIGINT PRIMARY KEY,project_key VARCHAR(40),platform VARCHAR(16),customer_key VARCHAR(40),conversation_id BIGINT,observed_at_unix BIGINT,assessment_namespace CHAR(64),assessment_kind VARCHAR(8),profile_json TEXT)");
    $p->exec("CREATE TABLE max_channel_conversion_outbox(id BIGINT PRIMARY KEY,project_key VARCHAR(40),platform VARCHAR(16),conversation_id BIGINT,counter_id VARCHAR(20),transport_target VARCHAR(64),value_contract VARCHAR(64),target VARCHAR(100),value_profile_id BIGINT,value_rub DECIMAL(12,2),state VARCHAR(24),upload_status VARCHAR(32),upload_id VARCHAR(40),external_user_id VARCHAR(20),event_unix BIGINT,value_stage VARCHAR(40),last_error TEXT,value_ready_at_unix BIGINT,value_api_accepted_at_unix BIGINT,value_processed_at_unix BIGINT)");
    $p->exec('CREATE INDEX idx_value_delivery_receipt ON max_channel_conversion_outbox(project_key,counter_id,transport_target,value_contract,value_api_accepted_at_unix)');
    $profileCut=1794446184;$apiCut=1794437904;$now=max(time(),$profileCut+600);$ns=ModeledConversionProfiles::assessmentNamespace();
    $profile=$p->prepare("INSERT INTO modeled_conversion_profile_versions VALUES(?,'anytour',?,?,?,?,?,'repair','PRIVATE_FRONTIER_PROFILE')");
    $event=$p->prepare("INSERT INTO max_channel_conversion_outbox VALUES(?,'anytour',?,?,'98615635',?,?,?,?,?,?,?,?,?,?,'specific_request',?,?,?,?)");
    $add=static function(int $id,bool $later=false)use($profile,$event,$profileCut,$apiCut,$ns):void{
        $platform=$id%2?'max':'telegram';$born=$later?$profileCut+1:$apiCut-120;$at=$later?$profileCut:$apiCut-180;
        $api=$id<=20?$apiCut-60+$id:($id<=23?$apiCut+60:null);
        $state=$id<=8?'processed':($id===17?'unmatched':($api!==null?'accepted':($id%2?'ready':'pending')));
        $status=$id<=8?'PROCESSED':($id===17?'LINKAGE_FAILURE':($api!==null?'UPLOADED':null));
        $profile->execute([$id,$platform,(string)(100+$id),200+$id,$born,$ns]);
        $event->execute([$id,$platform,200+$id,ModeledConversionValueContract::TARGET,ModeledConversionValueContract::VERSION,
            ModeledConversionValueContract::predictionKey((string)$id),$id,10+$id,$state,$status,$api===null?null:(string)(400+$id),
            (string)(300+$id),$at,$state==='pending'?'identity_not_found':'',$state==='pending'?null:$at+1,$api,$id<=8?$api+1:null]);
    };
    for($id=1;$id<=59;$id++)$add($id);$add(80,true);
    $profile->execute([90,'max','190',290,$apiCut-120,$ns]);
    $poll=ModeledConversionReceiptPollEvidence::begin($now-3);
    $poll=ModeledConversionReceiptPollEvidence::append($poll,['v'=>1,'counter_id'=>'98615635',
        'receipt_ref'=>hash('sha256','modeled_value_upload_receipt_v1|417'),'checked_at_unix'=>$now-1,'stage'=>7,
        'http_status'=>200,'transport_error_code'=>0,'provider_status'=>'LINKAGE_FAILURE'],$now);
    $read=static fn(?ModeledConversionStatusBudget $budget=null):array=>
        (new ModeledConversionValueDeliveryReader($p,static function():void{},$budget??new ModeledConversionStatusBudget()))->repairAssessments($now,null,null,null,$poll);
    $snapshot=static fn():string=>json_encode([$p->query('SELECT * FROM modeled_conversion_profile_versions ORDER BY id')->fetchAll(PDO::FETCH_ASSOC),
        $p->query('SELECT * FROM max_channel_conversion_outbox ORDER BY id')->fetchAll(PDO::FETCH_ASSOC)],JSON_THROW_ON_ERROR);
    $before=$snapshot();$oldClass=$p->getAttribute(PDO::ATTR_STATEMENT_CLASS);$p->setAttribute(PDO::ATTR_STATEMENT_CLASS,[ForecastV1ObservedStatement::class]);
    ForecastV1ObservedStatement::$observePredictive=true;ForecastV1ObservedStatement::$predictiveStatements=[];ForecastV1ObservedStatement::$predictiveCopies=[];
    try{$full=$read();$fullSql=ForecastV1ObservedStatement::$predictiveStatements;$fullCopies=ForecastV1ObservedStatement::$predictiveCopies;}
    finally{ForecastV1ObservedStatement::$observePredictive=false;$p->setAttribute(PDO::ATTR_STATEMENT_CLASS,$oldClass);}
    $r=$full['processing_frontier'];$sum=array_sum(range(11,69));
    $check('the finite primary roster includes every synthetic59 row and its nested savedAPI subset without adding original cases',
        $r['available']&&$r['complete_within_bound']&&count($r['rows'])===59&&$r['observed_events']===59
        &&$r['observed_value_rub']===$sum&&$r['initial_api_subset']['events']===20
        &&$r['later_api_observations']['events']===3&&$r['without_saved_api_receipt']['events']===36
        &&$r['later_profile_events']===1&&$r['later_profile_value_rub']==90
        &&!$r['historical_membership_authenticated']&&$r['late_increment_for_old_profile_can_change_membership']);
    $budget=new ModeledConversionStatusBudget();$budget->consumeBytes(35000);
    $p->setAttribute(PDO::ATTR_STATEMENT_CLASS,[ForecastV1ObservedStatement::class]);ForecastV1ObservedStatement::$observePredictive=true;ForecastV1ObservedStatement::$predictiveStatements=[];
    try{$legacy=$read($budget);$oldSql=ForecastV1ObservedStatement::$predictiveStatements;}
    finally{ForecastV1ObservedStatement::$observePredictive=false;$p->setAttribute(PDO::ATTR_STATEMENT_CLASS,$oldClass);}
    $same=true;foreach(['reassessed_profiles','profiles_without_new_increment','new_increment_events','new_increment_value_rub',
        'api_receipts','processed_upload_rows','by_state','by_platform','accepted_prefix']as$key)if($full[$key]!==$legacy[$key])$same=false;
    $check('the same strict source is read once with identical original aggregate values and no added SQL operation',
        $same&&$full['profiles_without_new_increment']===1&&count($fullSql)===count($oldSql)&&count($fullSql)<=30
        &&substr_count($fullSql[0]['sql'],' FROM modeled_conversion_profile_versions p')===1
        &&str_contains($fullSql[0]['sql'],'ROW_NUMBER()')&&str_contains($fullSql[0]['sql'],'cut_order<=65')
        &&$legacy['processing_frontier']['reason']==='budget_headroom'
        &&!str_contains($fullSql[0]['sql'],'profile_json')
        &&strlen(json_encode($fullCopies[0][0]['frontier_packet'],JSON_THROW_ON_ERROR))<=8192&&$snapshot()===$before);
    $json=json_encode($r,JSON_THROW_ON_ERROR);
    $check('the roster contains hashed references and typed legends but no native identity or private profile payload',
        !str_contains($json,'"301"')&&!str_contains($json,'"417"')&&!str_contains($json,'PRIVATE_')
        &&$r['provider_requests_in_reader']===0&&strlen($r['membership_sha256'])===64
        &&$r['last_saved_terminal_polls'][0]['basis']==='latest_saved_poll_not_first_terminal_clock'
        &&$r['linkage_failure_first_terminal_clock']==='not_persisted');
    $p->prepare('UPDATE max_channel_conversion_outbox SET event_unix=? WHERE id=80')->execute([$now+1]);
    $laterChanged=$read()['processing_frontier'];
    $check('a future event on a later profile cannot invalidate the earlier observed roster',
        $laterChanged['available']&&$laterChanged['membership_sha256']===$r['membership_sha256']
        &&$laterChanged['observed_events']===$r['observed_events']);
    $p->prepare('UPDATE max_channel_conversion_outbox SET event_unix=? WHERE id=80')->execute([$profileCut]);
    $p->prepare('UPDATE max_channel_conversion_outbox SET event_unix=?,last_error=NULL WHERE id=22')->execute([$apiCut-119]);
    $afterProfile=$read()['processing_frontier'];
    $nullHold=array_values(array_filter($afterProfile['rows']??[],static fn(array $v):bool=>$v[2]===32));
    $check('a legitimate event one second after its profile remains valid and NULL hold means none recorded',
        $afterProfile['available']&&count($nullHold)===1&&$nullHold[0][4]===1);
    foreach([$now+1,946684799]as$invalidEvent){
        $p->prepare('UPDATE max_channel_conversion_outbox SET event_unix=? WHERE id=22')->execute([$invalidEvent]);
        $badEvent=$read()['processing_frontier'];
        $check('future and invalid in-cut event clocks omit the entire roster',
            !$badEvent['available']&&$badEvent['reason']==='invalid_event_clock');
    }
    $p->prepare('UPDATE max_channel_conversion_outbox SET event_unix=? WHERE id=22')->execute([$apiCut-180]);
    $p->prepare('UPDATE modeled_conversion_profile_versions SET observed_at_unix=? WHERE id=22')->execute([946684799]);
    $badProfileClock=$read()['processing_frontier'];
    $check('an invalid profile clock inside the cut still omits the entire roster',
        !$badProfileClock['available']&&$badProfileClock['reason']==='invalid_profile_clock');
    $p->prepare('UPDATE modeled_conversion_profile_versions SET observed_at_unix=? WHERE id=22')->execute([$apiCut-120]);
    $p->exec("UPDATE max_channel_conversion_outbox SET state='accepted',upload_status='UPLOADED' WHERE id=17");
    $changed=$read()['processing_frontier'];
    $check('status changes do not silently replace the frozen membership or initial receipt membership',
        $changed['membership_sha256']===$r['membership_sha256']
        &&$changed['initial_api_subset']['membership_sha256']===$r['initial_api_subset']['membership_sha256']);
    $p->exec("UPDATE max_channel_conversion_outbox SET last_error='PRIVATE_HOLD',upload_status='PRIVATE_STATUS' WHERE id=22");
    $unknown=$read()['processing_frontier'];
    $unknownRows=array_filter($unknown['rows']??[],static fn(array $v):bool=>$v[4]===0&&$v[5]===0);
    $check('unrecognised hold and upload enums remain explicit unknown rather than none or private text',
        $unknown['available']&&count($unknownRows)===1&&!str_contains(json_encode($unknown,JSON_THROW_ON_ERROR),'PRIVATE_'));
    $p->exec("UPDATE max_channel_conversion_outbox SET external_user_id='PRIVATE_BINDING' WHERE id=23");
    $bad=$read()['processing_frontier'];$p->exec("UPDATE max_channel_conversion_outbox SET external_user_id='323' WHERE id=23");
    $check('invalid native binding omits the whole roster instead of releasing a partial list',!$bad['available']&&$bad['reason']==='invalid_saved_binding'&&!isset($bad['rows']));
    $p->prepare('UPDATE max_channel_conversion_outbox SET value_api_accepted_at_unix=? WHERE id=1')->execute([$apiCut-181]);
    $badClock=$read()['processing_frontier'];$p->prepare('UPDATE max_channel_conversion_outbox SET value_api_accepted_at_unix=? WHERE id=1')->execute([$apiCut-59]);
    $check('invalid firstAPI clocks produce explicit unavailable without inventing accepted membership',!$badClock['available']&&$badClock['reason']==='invalid_receipt_clock');
    for($id=60;$id<=65;$id++)$add($id);$overflow=$read()['processing_frontier'];
    $check('the65th member is a hard sentinel and never an arbitrary first64 complete roster',!$overflow['available']&&$overflow['reason']==='row_limit'&&$overflow['observed_events']===65&&!isset($overflow['rows']));
    $p->exec('DELETE FROM max_channel_conversion_outbox WHERE id BETWEEN 60 AND 65');$p->exec('DELETE FROM modeled_conversion_profile_versions WHERE id BETWEEN 60 AND 65');
    $projection=(new ReflectionClass(ModeledConversionForecastStatusReader::class))->newInstanceWithoutConstructor();
    $bound=new ReflectionMethod(ModeledConversionForecastStatusReader::class,'boundedResponse');
    $body=['value_delivery'=>['repair_assessments'=>['processing_frontier'=>$r,
        'finite_visit_evidence'=>['available'=>true,'immutable'=>'original'],'finite_visit_recovery'=>['available'=>true,'immutable'=>'forward'],
        'finite_counter_scope_evidence'=>['available'=>true,'immutable'=>'once']]],'padding'=>str_repeat('x',23000)];
    $safe=$bound->invoke($projection,$body);
    $check('response pressure omits the whole new roster before original forward or once-only proof without raising28000',
        strlen(json_encode($safe,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR))<=28000
        &&$safe['value_delivery']['repair_assessments']['processing_frontier']['reason']==='response_budget'
        &&$safe['value_delivery']['repair_assessments']['finite_visit_evidence']===$body['value_delivery']['repair_assessments']['finite_visit_evidence']
        &&$safe['value_delivery']['repair_assessments']['finite_visit_recovery']===$body['value_delivery']['repair_assessments']['finite_visit_recovery']
        &&$safe['value_delivery']['repair_assessments']['finite_counter_scope_evidence']===$body['value_delivery']['repair_assessments']['finite_counter_scope_evidence']);
    $sized=static function(int $bytes,string $char):array{
        $fixture=['fixture'=>''];$fixture['fixture']=str_repeat($char,$bytes-strlen(json_encode($fixture,JSON_THROW_ON_ERROR)));return $fixture;
    };
    $contextual=['value_delivery'=>['repair_assessments'=>$full,'by_state'=>$full['by_state'],
        'predictive_v1'=>$sized(1348,'p'),'historical_review'=>$sized(962,'h')]];
    $proofs=['finite_visit_evidence'=>3238,'finite_visit_recovery'=>2220,
        'fixed_pair_operational_decisions'=>2003,'finite_counter_scope_evidence'=>895];
    foreach($proofs as$key=>$size)$contextual['value_delivery']['repair_assessments'][$key]=['available'=>true]+$sized($size-17,'s');
    foreach(['processing_frontier','exact_cases','initial_api_receipts','retained_native_identity_evidence','retained_identity','retained_tracker_candidate','retained_known_identities']as$key)
        unset($contextual['value_delivery']['repair_assessments'][$key]);
    $contextual['value_delivery']['fixed_output_fixture']='';
    $financialBytes=strlen(json_encode($contextual['value_delivery'],JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR));
    $contextual['value_delivery']['fixed_output_fixture']=str_repeat('f',max(0,18500-$financialBytes));
    $financialBytes=strlen(json_encode($contextual['value_delivery'],JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR));
    $contextual['remaining_context_fixture']=$sized(1800,'c');
    foreach(['today_latency'=>5500,'telegram_linkage'=>1100,'saved_input_diagnosis'=>400,
        'input_coverage'=>1450,'saved'=>950,'window'=>600]as$key=>$size)$contextual[$key]=$sized($size,'x');
    $contextual['value_delivery']['repair_assessments']['processing_frontier']=$r;
    $contextual['value_delivery']['repair_assessments']['retained_identity']=$retainedPositive;
    $contextSafe=$bound->invoke($projection,$contextual);
    $preserved=true;foreach($proofs as$key=>$size)
        if($contextSafe['value_delivery']['repair_assessments'][$key]!==$contextual['value_delivery']['repair_assessments'][$key])$preserved=false;
    $check('a full synthetic financial envelope retains its59-event23-receipt roster all four proofs and totals by explicitly yielding only optional context and duplicate projections',
        $financialBytes===18500&&($retainedPositive['status']??null)==='candidate'
        &&$contextSafe['value_delivery']['repair_assessments']['retained_identity']===$retainedPositive
        &&count(array_filter($r['rows'],static fn(array $v):bool=>$v[6]!==null))===23
        &&strlen(json_encode($contextual,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR))>28000
        &&strlen(json_encode($contextSafe,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR))<=28000
        &&$contextSafe['value_delivery']['repair_assessments']['processing_frontier']===$r&&$preserved
        &&$contextSafe['value_delivery']['by_state']===$contextual['value_delivery']['by_state']
        &&$contextSafe['value_delivery']['repair_assessments']['by_state']===$full['by_state']
        &&$contextSafe['today_latency']['reason']==='response_budget'
        &&$contextSafe['value_delivery']['predictive_v1']['reason']==='response_budget'
        &&$contextSafe['value_delivery']['historical_review']['reason']==='response_budget');

    $outcomePacket=ForecastV1ObservedStatement::$lastRelationOutcomePacket;
    if($outcomePacket===null)throw new RuntimeException('saved_outcome_fixture_not_exercised');
    $small=['value_delivery'=>['repair_assessments'=>['finite_visit_evidence'=>['available'=>true,'fixture'=>'old_binding'],
        'retained_relation_outcomes'=>$outcomePacket],'by_state'=>['ready'=>['events'=>1,'value_rub'=>100]]]];
    $smallSafe=$bound->invoke($projection,$small);
    $check('a feasible response retains the actual two-case saved outcome and old monetary aggregates unchanged',
        $smallSafe===$small&&$smallSafe['value_delivery']['repair_assessments']['retained_relation_outcomes']['provider_requests_in_reader']===0
        &&strlen(json_encode($smallSafe,JSON_THROW_ON_ERROR))<=28000);
    $withOutcome=$contextual;$withOutcome['value_delivery']['repair_assessments']['retained_relation_outcomes']=$outcomePacket;
    $withOutcomeSafe=$bound->invoke($projection,$withOutcome);$allOld=true;
    foreach($proofs as$key=>$size)if($withOutcomeSafe['value_delivery']['repair_assessments'][$key]!==$withOutcome['value_delivery']['repair_assessments'][$key])$allOld=false;
    $compactOutcome=$withOutcomeSafe['value_delivery']['repair_assessments']['retained_relation_outcomes'];
    $check('a new outcome compacts or explicitly yields before the fixed18500 financial envelope full59/23 roster or any old proof',
        $financialBytes===18500&&(($compactOutcome['projection']??null)==='compact_v1'||($compactOutcome['reason']??null)==='response_budget')
        &&$withOutcomeSafe['value_delivery']['repair_assessments']['processing_frontier']===$r&&$allOld
        &&$withOutcomeSafe['value_delivery']['by_state']===$withOutcome['value_delivery']['by_state']
        &&strlen(json_encode($withOutcomeSafe,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR))<=28000);

    $compactMethod=new ReflectionMethod(ModeledConversionForecastStatusReader::class,'compactRetainedRelationOutcomes');
    $compact=$compactMethod->invoke($projection,$outcomePacket);
    $check('compact historical outcomes retain distinct checked and absent cases with admitted phase intent and no authority',
        $compact['available']&&$compact['projection']==='compact_v1'&&$compact['historical']===true
        &&$compact['columns']===['case_index','status','reason','phase_admission_mask']
        &&$compact['rows'][0]===[1,'complete_base_window_assessed',$outcomePacket['rows'][0]['reason'],15]
        &&$compact['rows'][1]===[2,'not_recorded',null,null]
        &&$compact['provider_requests_in_reader']===0&&$compact['financial_permission']===false
        &&$compact['current_eligibility']==='not_assessed'
        &&!str_contains(json_encode($compact,JSON_THROW_ON_ERROR),$outcomePacket['rows'][0]['event_ref']));

    $jsonFlags=JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR;
    $size=static fn(array $v):int=>strlen(json_encode($v,$jsonFlags));
    // Synthetic analogue of the live envelope: only423 bytes remain for this saved-only result.
    $pressure=$contextSafe;unset($pressure['value_delivery']['repair_assessments']['retained_identity']);
    // Keep the property overhead in the measurement while excluding its previous value.
    $pressure['value_delivery']['repair_assessments']['retained_relation_outcomes']=null;
    $pressure['response_pressure_fixture']='';$pressureTarget=28000-423;
    $pressureDeficit=$size($pressure)-4-$pressureTarget;
    if($pressureDeficit>0){
        $padding=&$pressure['remaining_context_fixture']['fixture'];
        if(!is_string($padding)||$pressureDeficit>strlen($padding))throw new RuntimeException('invalid_compact_pressure_fixture');
        $padding=substr($padding,0,strlen($padding)-$pressureDeficit);unset($padding);
    }
    $pressurePadding=$pressureTarget-($size($pressure)-4);
    if($pressurePadding<0)throw new RuntimeException('invalid_compact_pressure_fixture');
    $pressure['response_pressure_fixture']=str_repeat('x',$pressurePadding);
    $pressure['value_delivery']['repair_assessments']['retained_relation_outcomes']=$outcomePacket;
    $pressureSafe=$bound->invoke($projection,$pressure);$pressureOld=true;
    foreach($proofs as$key=>$ignored)
        if($pressureSafe['value_delivery']['repair_assessments'][$key]!==$contextSafe['value_delivery']['repair_assessments'][$key])$pressureOld=false;
    $check('423 byte outcome headroom preserves actual saved outcomes complete59 event23 receipt roster all four proofs and money',
        $size($pressure)-$size($outcomePacket)===28000-423
        &&$size($compact)<=423&&$size($pressure)>28000&&$size($pressureSafe)<=28000
        &&$pressureSafe['value_delivery']['repair_assessments']['retained_relation_outcomes']===$compact
        &&$pressureSafe['value_delivery']['repair_assessments']['processing_frontier']===$r&&$pressureOld
        &&$pressureSafe['value_delivery']['by_state']===$pressure['value_delivery']['by_state']
        &&$pressureSafe['value_delivery']['repair_assessments']['by_state']===$pressure['value_delivery']['repair_assessments']['by_state']);

    $unknownPacket=$outcomePacket;
    $unknownPacket['rows'][0]['status']='consumed_unknown';$unknownPacket['rows'][0]['reason']='cache_missing_or_invalidated';
    $unknownPacket['rows'][0]['phases_admitted']=['source'=>true,'recorded_day_relation'=>false,'visit_base'=>false,'visit_sources'=>false];
    $unknownCompact=$compactMethod->invoke($projection,$unknownPacket);
    $check('compact unknown preserves consumed intent rather than reporting an absent or completed provider operation',
        $unknownCompact['rows'][0]===[1,'consumed_unknown','cache_missing_or_invalidated',1]
        &&$unknownCompact['rows'][1]===[2,'not_recorded',null,null]&&!$unknownCompact['financial_permission']);
    foreach(['source','recorded_day_relation','visit_base','visit_sources']as$phase){
        $uncertain=$unknownPacket;$uncertain['rows'][0]['phases_admitted'][$phase]=null;
        $missing=$unknownPacket;unset($missing['rows'][0]['phases_admitted'][$phase]);
        $check('unknown or missing admission metadata never becomes zero or a completed call '.$phase,
            $compactMethod->invoke($projection,$uncertain)['rows'][0][3]===null
            &&$compactMethod->invoke($projection,$missing)['rows'][0][3]===null);
    }
    $noAdmission=$unknownPacket;$noAdmission['rows'][0]['phases_admitted']=array_fill_keys(['source','recorded_day_relation','visit_base','visit_sources'],false);
    $bothReasons=$outcomePacket;$bothReasons['rows'][0]['reason']='outside_current_window';$bothReasons['rows'][0]['relation_reason']='source_client_id_matches_held';
    $check('zero saved admissions stay zero and a visit barrier is not masked by a relation reason',
        $compactMethod->invoke($projection,$noAdmission)['rows'][0][3]===0
        &&$compactMethod->invoke($projection,$bothReasons)['rows'][0][2]==='outside_current_window');
    $reconfirmed=$outcomePacket;
    foreach($reconfirmed['rows']as&$row){
        $row['status']='relation_reconfirms_previously_checked_held_cid';$row['reason']=null;
        $row['relation_reason']='recorded_day_cid_matches_held';
        $row['phases_admitted']=['source'=>true,'recorded_day_relation'=>true,'visit_base'=>false,'visit_sources'=>false];
    }unset($row);
    $reconfirmedCompact=$compactMethod->invoke($projection,$reconfirmed);
    $check('the longest re-confirmation states retain relation reasons inside423 bytes without implying visit queries',
        $size($reconfirmedCompact)<=423&&$reconfirmedCompact['rows'][0]===[1,'relation_reconfirms_previously_checked_held_cid','recorded_day_cid_matches_held',3]
        &&$reconfirmedCompact['rows'][1]===[2,'relation_reconfirms_previously_checked_held_cid','recorded_day_cid_matches_held',3]);

    $noHeadroom=$pressure;$noHeadroom['response_pressure_fixture'].=str_repeat('x',423-$size($compact)+1);
    $omittedOutcome=$bound->invoke($projection,$noHeadroom);
    $check('insufficient room explicitly omits the compact result before any original financial proof or roster',
        $size($noHeadroom)-$size($outcomePacket)+$size($compact)===28001&&$size($omittedOutcome)<=28000
        &&$omittedOutcome['value_delivery']['repair_assessments']['retained_relation_outcomes']['reason']==='response_budget'
        &&$omittedOutcome['value_delivery']['repair_assessments']['processing_frontier']===$r
        &&$omittedOutcome['value_delivery']['repair_assessments']['finite_visit_evidence']===$contextSafe['value_delivery']['repair_assessments']['finite_visit_evidence']
        &&$omittedOutcome['value_delivery']['by_state']===$noHeadroom['value_delivery']['by_state']);
    foreach(['authority','duplicate_case','unknown_status','unknown_reason']as$case){
        $invalid=$outcomePacket;
        if($case==='authority')$invalid['financial_permission']=true;
        if($case==='duplicate_case')$invalid['rows'][1]['case_index']=1;
        if($case==='unknown_status')$invalid['rows'][0]['status']='PRIVATE UNVALIDATED STATUS';
        if($case==='unknown_reason')$invalid['rows'][0]['reason']='PRIVATE UNVALIDATED REASON';
        $check('compact formatter refuses malformed projection metadata '.$case,$compactMethod->invoke($projection,$invalid)===null);
    }
    $withoutBinding=$small;$withoutBinding['value_delivery']['repair_assessments']['finite_visit_evidence']=['available'=>false,'reason'=>'unbound_fixture'];
    $dependent=$bound->invoke($projection,$withoutBinding);
    $check('outcome display never survives loss of the current original-frame binding',
        $dependent['value_delivery']['repair_assessments']['retained_relation_outcomes']['reason']==='original_current_binding_unavailable'
        &&$dependent['value_delivery']['by_state']===$small['value_delivery']['by_state']);
    $compactWithoutBinding=$pressure;$compactWithoutBinding['value_delivery']['repair_assessments']['retained_relation_outcomes']=$compact;
    $compactWithoutBinding['value_delivery']['repair_assessments']['finite_visit_evidence']=['available'=>false,'reason'=>'unbound_fixture'];
    $compactUnbound=$bound->invoke($projection,$compactWithoutBinding);
    $check('compact output cannot survive an unavailable current original-frame binding',
        $compactUnbound['value_delivery']['repair_assessments']['retained_relation_outcomes']['reason']==='original_current_binding_unavailable'
        &&$compactUnbound['value_delivery']['by_state']===$pressure['value_delivery']['by_state']);

    $trackerPositive=['available'=>true,'case_index'=>0,'status'=>'candidate','financial_permission'=>false,'kind'=>'yclid',
        'candidate_ref'=>str_repeat('d',64),'recorded_click_at_unix'=>$now-100,'first_saved_at_unix'=>null,'first_enriched_at_unix'=>null];
    // Both new candidates are added after the unchanged18500 financial padding; do not hide their cost in that padding.
    $both=$contextual;$both['value_delivery']['repair_assessments']['retained_tracker_candidate']=$trackerPositive;
    $bothSafe=$bound->invoke($projection,$both);
    $check('an infeasible second optional candidate yields explicitly before the complete roster or any old proof',
        $bothSafe['value_delivery']['repair_assessments']['retained_tracker_candidate']['reason']==='response_budget'
        &&$bothSafe['value_delivery']['repair_assessments']['processing_frontier']===$r
        &&$bothSafe['value_delivery']['repair_assessments']['retained_identity']['reason']==='response_budget'
        &&strlen(json_encode($bothSafe,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR))<=28000);
    $normal=$both;$normal['remaining_context_fixture']=$sized(1300,'c');$normalSafe=$bound->invoke($projection,$normal);
    $allProof=true;foreach($proofs as$key=>$size)if($normalSafe['value_delivery']['repair_assessments'][$key]!==$normal['value_delivery']['repair_assessments'][$key])$allProof=false;
    $check('a normally feasible response retains both bound candidate projections the full59/23 roster and all four old proofs',
        $financialBytes===18500&&$allProof&&$normalSafe['value_delivery']['repair_assessments']['retained_tracker_candidate']===$trackerPositive
        &&$normalSafe['value_delivery']['repair_assessments']['retained_identity']===$retainedPositive
        &&$normalSafe['value_delivery']['repair_assessments']['processing_frontier']===$r
        &&strlen(json_encode($normalSafe,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR))<=28000);

    $withKnown=$contextual;$withKnown['value_delivery']['repair_assessments']['retained_known_identities']=$knownPositive;
    $knownSafe=$bound->invoke($projection,$withKnown);
    $check('new pair metadata yields explicitly before the unchanged18500 financial envelope roster or any original proof',
        $knownPositive!==null&&$financialBytes===18500
        &&($knownSafe['value_delivery']['repair_assessments']['retained_known_identities']['reason']??null)==='response_budget'
        &&$knownSafe['value_delivery']['repair_assessments']['processing_frontier']===$r
        &&$knownSafe['value_delivery']['repair_assessments']['finite_visit_evidence']===$contextual['value_delivery']['repair_assessments']['finite_visit_evidence']
        &&strlen(json_encode($knownSafe,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR))<=28000);
    $knownNormal=$withKnown;$knownNormal['remaining_context_fixture']=$sized(900,'c');$knownNormalSafe=$bound->invoke($projection,$knownNormal);
    $knownProofs=true;foreach($proofs as$key=>$size)if($knownNormalSafe['value_delivery']['repair_assessments'][$key]!==$knownNormal['value_delivery']['repair_assessments'][$key])$knownProofs=false;
    $check('a feasible optional-context envelope preserves the compact two-case results full59/23 roster and all four core proofs',
        $knownProofs&&$financialBytes===18500&&$knownNormalSafe['value_delivery']['repair_assessments']['retained_known_identities']===$knownPositive
        &&$knownNormalSafe['value_delivery']['repair_assessments']['processing_frontier']===$r
        &&strlen(json_encode($knownNormalSafe,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR))<=28000);

    // Use the already-read original three-case frame, not the earlier size-only proof stub.
    // Preserve the18500 financial baseline before adding the unchanged59/23 roster and native metadata.
    $nativeBytes=static fn(array $v):int=>strlen(json_encode($v,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR));
    $nativeFixtures=ForecastV1ObservedStatement::$nativePresentationFixtures;
    if($nativeFixtures===null)throw new RuntimeException('native_pressure_fixture_missing');
    $nativeBase=$contextual;
    unset($nativeBase['value_delivery']['repair_assessments']['processing_frontier'],$nativeBase['value_delivery']['repair_assessments']['retained_identity']);
    $nativeBase['value_delivery']['repair_assessments']['finite_visit_evidence']=$nativeFixtures['original'];
    $nativeBase['value_delivery']['fixed_output_fixture']='';
    $nativeFixed=$nativeBytes($nativeBase['value_delivery']);
    if($nativeFixed>18500)throw new RuntimeException('native_financial_fixture_too_large');
    $nativeBase['value_delivery']['fixed_output_fixture']=str_repeat('f',18500-$nativeFixed);
    $nativeFinancialBytes=$nativeBytes($nativeBase['value_delivery']);
    $nativeBase['remaining_context_fixture']=['fixture'=>''];
    $nativeBase['value_delivery']['repair_assessments']['processing_frontier']=$r;
    $nativeOmitted=['available'=>false,'reason'=>'response_budget','provider_requests_in_reader'=>0,'financial_permission'=>false];
    unset($nativeBase['value_delivery']['repair_assessments']['retained_native_identity_evidence']);
    $nativeBaseline=$bound->invoke($projection,$nativeBase);
    // This is an outer omission marker, not a point-reader result. Restore it only for size measurement.
    $nativeBaseline['value_delivery']['repair_assessments']['retained_native_identity_evidence']=$nativeOmitted;
    $nativeMethod=new ReflectionMethod(ModeledConversionForecastStatusReader::class,'nativeIdentityPresentations');
    $nativeFull=$nativeFixtures['guarded'];
    $nativeCompact=$nativeMethod->invoke($projection,$nativeFull,$nativeFixtures['original'])[1];
    $nativePreserved=static function(array $actual,array $expected)use($proofs,$r):bool{
        foreach($proofs as$key=>$ignored)
            if($actual['value_delivery']['repair_assessments'][$key]!==$expected['value_delivery']['repair_assessments'][$key])return false;
        return $actual['value_delivery']['repair_assessments']['processing_frontier']===$r
            &&$actual['value_delivery']['by_state']===$expected['value_delivery']['by_state']
            &&$actual['value_delivery']['repair_assessments']['by_state']===$expected['value_delivery']['repair_assessments']['by_state'];
    };
    foreach([27335,27337]as$targetBytes){
        $nativePressure=$nativeBaseline;$nativePressure['native_pressure_padding']='';
        $nativePad=$targetBytes-$nativeBytes($nativePressure);
        if($nativePad<0)throw new RuntimeException('native_pressure_target_below_financial_fixture');
        $nativePressure['native_pressure_padding']=str_repeat('x',$nativePad);
        $markerBaseline=$nativePressure;
        $nativePressure['value_delivery']['repair_assessments']['retained_native_identity_evidence']=$nativeFull;
        $nativeSafe=$bound->invoke($projection,$nativePressure);
        $check('whole response native replacement fits live-shaped envelope without altering59/23 money or any original proof '.$targetBytes,
            $nativeFinancialBytes===18500&&$nativeBytes($markerBaseline)===$targetBytes&&$nativeBytes($nativeOmitted)===107
            &&$nativeBytes($nativePressure)>28000&&$nativeBytes($nativeSafe)<=28000&&$nativeBytes($nativeCompact)<=768
            &&$nativeBytes($nativeSafe)===$targetBytes-$nativeBytes($nativeOmitted)+$nativeBytes($nativeCompact)
            &&$nativeSafe['value_delivery']['repair_assessments']['retained_native_identity_evidence']===$nativeCompact
            &&$nativePreserved($nativeSafe,$markerBaseline));
        $nativeTooTight=$nativePressure;
        $nativeTooTight['native_pressure_padding'].=str_repeat('x',28001-$nativeBytes($nativeSafe));
        $nativeTightSafe=$bound->invoke($projection,$nativeTooTight);
        $check('whole response one byte beyond native replacement budget keeps an explicit omission and all financial evidence '.$targetBytes,
            $nativeBytes($nativeTooTight)-$nativeBytes($nativeFull)+$nativeBytes($nativeCompact)===28001
            &&$nativeBytes($nativeTightSafe)<=28000
            &&$nativeTightSafe['value_delivery']['repair_assessments']['retained_native_identity_evidence']===$nativeOmitted
            &&$nativePreserved($nativeTightSafe,$markerBaseline));
        $unknownNative=$markerBaseline;
        $unknownNative['value_delivery']['repair_assessments']['retained_native_identity_evidence']=$nativeFixtures['budget'];
        $unknownNativeSafe=$bound->invoke($projection,$unknownNative);
        $check('a feasible existing budget refusal is preserved exactly without implying that the native point SELECT ran '.$targetBytes,
            $unknownNativeSafe['value_delivery']['repair_assessments']['retained_native_identity_evidence']===$nativeFixtures['budget']
            &&$nativeBytes($unknownNativeSafe)<=28000&&$nativePreserved($unknownNativeSafe,$markerBaseline));
        $unboundNative=$nativePressure;
        $unboundNative['value_delivery']['repair_assessments']['finite_visit_evidence']['available']=false;
        $unboundNativeSafe=$bound->invoke($projection,$unboundNative);
        $unboundResult=$unboundNativeSafe['value_delivery']['repair_assessments']['retained_native_identity_evidence'];
        $check('response pressure never restores a candidate after losing the original frame '.$targetBytes,
            !$unboundResult['available']&&$unboundResult['reason']==='original_current_binding_unavailable'
            &&!isset($unboundResult['candidate_sha256'])&&$nativeBytes($unboundNativeSafe)<=28000
            &&$unboundNativeSafe['value_delivery']['repair_assessments']['processing_frontier']===$r
            &&$unboundNativeSafe['value_delivery']['by_state']===$markerBaseline['value_delivery']['by_state']);
    }

    $p->exec('UPDATE max_channel_conversion_outbox SET id=id+100000000000000000,value_profile_id=value_profile_id+100000000000000000,external_user_id=\'99999999999999999999\'');
    $p->exec('UPDATE modeled_conversion_profile_versions SET id=id+100000000000000000');
    $target=$p->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'?"CONCAT('anytour_value_pred_',value_profile_id,'_v1')":"'anytour_value_pred_'||value_profile_id||'_v1'";
    $p->exec('UPDATE max_channel_conversion_outbox SET target='.$target);
    $oversize=$read()['processing_frontier'];
    $check('oversized quoted metadata packets are omitted before native copy without reducing cohort membership',
        !$oversize['available']&&$oversize['reason']==='packet_budget'&&!isset($oversize['rows']));
}



/** A real sender tick with counted transports; source candidates never duplicate a subject lookup in that tick. */
function forecastRetainedTrackerTickAssertions(PDO $p,callable $check):void
{
    foreach(['sole_subject','with_other_financial_user','late_private_pressure','initial_private_pressure']as$case){
    $otherDue=$case==='with_other_financial_user';
    modeledValueSchema($p);
    foreach(['assessment_namespace CHAR(64)','assessment_kind VARCHAR(8)','journal_cursor BIGINT DEFAULT 0']as$column)
        $p->exec('ALTER TABLE modeled_conversion_profile_versions ADD COLUMN '.$column);
    $ns=ModeledConversionProfiles::assessmentNamespace();$born=ModeledConversionValueVisitEvidence::CHECKPOINT-120;$now=time()-1000;
    for($id=1;$id<=3;$id++){
        $bindingId=900+$id;modeledValueBinding($p,$bindingId);
        $p->prepare("INSERT INTO modeled_conversion_profile_versions(id,project_key,platform,customer_key,conversation_id,observed_at_unix,profile_json,assessment_namespace,assessment_kind) VALUES(?,'anytour','max',?,?,?,'{\"access_status\":\"allowed\"}',?,'repair')")
            ->execute([$id,(string)$bindingId,$bindingId,$born,$ns]);
        $p->prepare("INSERT INTO max_channel_conversion_outbox(id,project_key,platform,conversation_id,external_user_id,counter_id,target,transport_target,event_unix,value_rub,value_profile_id,value_stage,value_contract,identity_source_key,state,next_attempt_unix) VALUES(?,'anytour','max',?,?,'98615635',?,?,?,?,?,'specific_request',?,'max_tracker','pending',?)")
            ->execute([$id,$bindingId,(string)(70000+$bindingId),ModeledConversionValueContract::predictionKey((string)$id),ModeledConversionValueContract::TARGET,$born,500,$id,ModeledConversionValueContract::VERSION,($id===1||($otherDue&&$id===2))?0:$now+10000]);
    }
    $rows=$p->query('SELECT * FROM max_channel_conversion_outbox ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);$row=$rows[0];
    $frame=['v'=>ModeledConversionValueVisitEvidence::VERSION,'n'=>$ns,'b'=>ModeledConversionValueVisitEvidence::CHECKPOINT,'r'=>[]];
    foreach($rows as$i=>$r)$frame['r'][]=[ModeledConversionValueIdentityEvidence::reference($r,$ns),(string)$r['id'],2,null,$now-20,$i===0?3:8,null,null,null,null,0,null,0,null,0,$born];
    $appIntent=ModeledConversionRetainedAppIdentityEvidence::intent($frame,$row,$ns,$now-10);
    $app=ModeledConversionRetainedAppIdentityEvidence::complete($appIntent,$frame,$row,$ns,[3,$now-10,0,null,null,0,null,null,null,null,null,null],$now);
    $base=['errors'=>0,'finite_visit_evidence'=>$frame,'finite_visit_recovery'=>false,'finite_counter_scope_evidence'=>false,
        ModeledConversionRetainedAppIdentityEvidence::RECEIPT_KEY=>$app,'metrika_report'=>['status'=>'unavailable']];
    $sizedReceipt=static function(array $r)use($p):array{
        $r['test_financial_padding']='';$mysql=$p->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql';
        $measure=static function(array $v)use($p,$mysql):int{
            $json=json_encode($v,JSON_THROW_ON_ERROR);if(!$mysql)return strlen($json);
            $q=$p->prepare('SELECT OCTET_LENGTH(CAST(? AS JSON))');$q->execute([$json]);return (int)$q->fetchColumn();
        };
        $r['test_financial_padding']=str_repeat('b',max(0,7800-$measure($r)));
        if($measure($r)!==7800)throw new RuntimeException('tracker_fixture_pressure_size');return $r;
    };
    if($case==='initial_private_pressure')$base=$sizedReceipt($base);
    $p->prepare("UPDATE modeled_conversion_value_export_settings SET mode='send',goal_id='678187097',goal_intent='verified',activated_at_unix=?,profile_cursor=3,contract_version=?,last_tick_unix=?,last_receipt_json=? WHERE project_key='anytour'")
        ->execute([$born-60,ModeledConversionValueContract::VERSION,$now-5,json_encode($base,JSON_THROW_ON_ERROR)]);
    $lookup=new class($p,$sizedReceipt) {
        public int $tick=0;public array $perTick=[];public int $candidateCalls=0;public int $ordinaryBatches=0;public bool $durable=true;public bool $pressureBeforeIntent=false;public ?array $pressuredReceipt=null;
        public function __construct(private PDO $p,private Closure $sizedReceipt){}
        private function counted(string $uid,string $kind):void{$this->perTick[$this->tick][$uid][]=$kind;}
        public function lookup(string $counter,array $users):array{$this->ordinaryBatches++;foreach($users as$uid)$this->counted($uid,'ordinary');return [];}
        public function retainedClickCandidate(array $context,callable $beforeRequest):?array{
            if($this->pressureBeforeIntent){
                $raw=$this->p->query("SELECT last_receipt_json FROM modeled_conversion_value_export_settings WHERE project_key='anytour'")->fetchColumn();
                $r=($this->sizedReceipt)(json_decode($raw,true,32,JSON_THROW_ON_ERROR));$this->pressuredReceipt=$r;
                $this->p->prepare("UPDATE modeled_conversion_value_export_settings SET last_receipt_json=? WHERE project_key='anytour'")->execute([json_encode($r,JSON_THROW_ON_ERROR)]);
            }
            $hash=str_repeat('a',64);if(!$beforeRequest($hash))return null;
            $this->counted($context['user_id'],'candidate');$this->candidateCalls++;
            $s=$this->p->query("SELECT last_tick_unix,last_receipt_json FROM modeled_conversion_value_export_settings WHERE project_key='anytour'")->fetch(PDO::FETCH_ASSOC);
            $r=json_decode($s['last_receipt_json'],true,32,JSON_THROW_ON_ERROR);$key=ModeledConversionRetainedTrackerIdentityEvidence::RECEIPT_KEY;
            $this->durable=$this->durable&&(int)$s['last_tick_unix']===$this->tick&&($r['errors']??null)===0&&($r[$key]['s']??null)===1;
            return ['request_sha256'=>$hash,'result'=>[1,$this->tick,1,0,2,str_repeat('d',64),'1',str_repeat('c',64),$context['event_at_unix']-10],'received_at_unix'=>$this->tick];
        }
    };
    $api=new class {
        public int $posts=0;
        public function modeledValueReport(int $activated,int $at):array{return ['status'=>'unavailable'];}
        public function modeledValueGoalStatus():array{throw new RuntimeException('unexpected_financial_goal_call');}
    };
    $gate=static fn(string $chat):bool=>true;$key=ModeledConversionRetainedTrackerIdentityEvidence::RECEIPT_KEY;$uid=$row['external_user_id'];
    $lookup->pressureBeforeIntent=$case==='late_private_pressure';
    $beforeCandidate=$p->query('SELECT * FROM max_channel_conversion_outbox WHERE id=1')->fetch(PDO::FETCH_ASSOC);
    $lookup->tick=$now;$first=ModeledConversionValueExport::tick($p,$lookup,$api,$now,$gate);
    $firstSaved=json_decode(ModeledConversionValueExport::settings($p)['last_receipt_json'],true,32,JSON_THROW_ON_ERROR);
    if($case==='initial_private_pressure'){
        $check('a low-headroom loaded receipt keeps the normal lookup without reserving or consuming a candidate',
            $first['status']==='running'&&($lookup->perTick[$now][$uid]??[])===['ordinary']
            &&$lookup->candidateCalls===0&&!array_key_exists($key,$firstSaved));
        continue;
    }
    if($case==='late_private_pressure'){
        $withoutMarker=$firstSaved;unset($withoutMarker[$key]);
        $check('late full-intent pressure consumes only a no-replay marker with zero source transport and preserves the committed financial receipt',
            $first['status']==='running'&&($firstSaved[$key]??null)===false&&$lookup->candidateCalls===0&&empty($lookup->perTick[$now][$uid])
            &&$lookup->pressuredReceipt!==null&&modeledValueFailureJsonEqual($withoutMarker,$lookup->pressuredReceipt)
            &&$p->query('SELECT * FROM max_channel_conversion_outbox WHERE id=1')->fetch(PDO::FETCH_ASSOC)===$beforeCandidate);
        $lookup->tick=$now+300;$next=ModeledConversionValueExport::tick($p,$lookup,$api,$now+300,$gate);
        $check('after pressure consumes the point marker the next actual300-second tick resumes ordinary lookup without retry',
            $next['status']==='running'&&($lookup->perTick[$now+300][$uid]??[])===['ordinary']&&$lookup->candidateCalls===0);
        continue;
    }
    $view=ModeledConversionRetainedTrackerIdentityEvidence::projection($firstSaved[$key]??null,$frame,$row,$ns,time());
    $check('the first eligible real tick substitutes one candidate for its ordinary lookup without changing the frozen row '.$case,
        $first['status']==='running'&&($firstSaved['errors']??null)===0&&($lookup->perTick[$now][$uid]??[])===['candidate']
        &&$lookup->candidateCalls===1&&$lookup->durable&&$view['status']==='candidate'&&!$view['financial_permission']
        &&$p->query('SELECT * FROM max_channel_conversion_outbox WHERE id=1')->fetch(PDO::FETCH_ASSOC)===$beforeCandidate);
    $check('the reserved pass preserves every other selected financial user and never sends an empty ordinary batch '.$case,
        $lookup->ordinaryBatches===($otherDue?1:0)&&($lookup->perTick[$now]['70902']??[])===($otherDue?['ordinary']:[]));
    $lookup->tick=$now+300;$second=ModeledConversionValueExport::tick($p,$lookup,$api,$now+300,$gate);
    $check('the next actual300-second cycle resumes the normal subject lookup after its consumed candidate '.$case,
        $second['status']==='running'&&($lookup->perTick[$now+300][$uid]??[])===['ordinary']&&$lookup->candidateCalls===1);
    $lookup->tick=$now+600;ModeledConversionValueExport::tick($p,$lookup,$api,$now+600,$gate);
    $once=true;foreach($lookup->perTick as$subjects)foreach($subjects as$calls)if(count($calls)>1)$once=false;
    $check('real300-second ticks keep one call per subject and never repeat the consumed candidate '.$case,
        $once&&$lookup->candidateCalls===1&&($lookup->perTick[$now+600][$uid]??[])===['ordinary']&&$api->posts===0);
    }
}


/** Two original known CIDs, an unchanged installed receiver DTO, and the normal full25-user carrier. */
function forecastKnownTrackerAssertions(PDO $p,callable $check):array
{
    $knownPacket=null;
    foreach(['pair_once','ready_refill']as$scenario){
        modeledValueSchema($p);
        foreach(['assessment_namespace CHAR(64)','assessment_kind VARCHAR(8)','journal_cursor BIGINT DEFAULT 0']as$c)$p->exec('ALTER TABLE modeled_conversion_profile_versions ADD COLUMN '.$c);
        $ns=ModeledConversionProfiles::assessmentNamespace();$born=ModeledConversionValueVisitEvidence::CHECKPOINT-120;$now=time()-2000;
        $last=$scenario==='ready_refill'?29:26;
        for($id=1;$id<=$last;$id++){
            $binding=1900+$id;modeledValueBinding($p,$binding);$at=$id<=3?$born:$now-100;$kind=$id<=3?'repair':'live';
            $p->prepare("INSERT INTO modeled_conversion_profile_versions(id,project_key,platform,customer_key,conversation_id,observed_at_unix,profile_json,assessment_namespace,assessment_kind) VALUES(?,'anytour','max',?,?,?,'{\"access_status\":\"allowed\"}',?,?)")
                ->execute([$id,(string)$binding,$binding,$at,$ns,$kind]);
            $ready=$id===2||$id===3||$id>=27;$cid=$id<=3?'88190'.$id:'99000'.(70000+$binding);$record='44000'.$id;
            $source=$id<=3?$born-10:$now-300;$readyAt=$id<=3?$born+240:$now-80;
            $p->prepare("INSERT INTO max_channel_conversion_outbox(id,project_key,platform,conversation_id,external_user_id,counter_id,target,transport_target,event_unix,value_rub,value_profile_id,value_stage,value_contract,identity_source_key,state,next_attempt_unix,client_id,tracker_record_id,tracker_observed_unix,value_ready_at_unix) VALUES(?,'anytour','max',?,?,'98615635',?,?,?,?,?,'specific_request',?,'max_tracker',?,?,?,?,?,?)")
                ->execute([$id,$binding,(string)(70000+$binding),ModeledConversionValueContract::predictionKey((string)$id),ModeledConversionValueContract::TARGET,$at,500,$id,ModeledConversionValueContract::VERSION,
                    $ready?'ready':'pending',$id===1?$now+10000:0,$ready?$cid:null,$ready?$record:null,$ready?$source:null,$ready?$readyAt:null]);
            // This fixture starts from an already verified continuity audit; the price model is not changed here.
            $basis='{"fixture":"existing_verified_continuity"}';
            $p->prepare("INSERT INTO modeled_conversion_price_audit(outbox_id,action,original_value_rub,applied_value_rub,prior_reserved_rub,assessed_at_unix,basis_json,basis_sha256) VALUES(?,'unchanged',500,500,0,?,?,?)")
                ->execute([$id,$now-10,$basis,hash('sha256',$basis)]);
        }
        $rows=$p->query('SELECT * FROM max_channel_conversion_outbox WHERE id<=3 ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
        $selection=(new ReflectionMethod(ModeledConversionValueExport::class,'finiteSelection'))->invoke(new ModeledConversionValueExport($p),$ns);
        $check('known source fixture preserves three original events before the cutoff with READY recorded after the cutoff',
            count($selection)===3&&(int)$rows[1]['value_ready_at_unix']>ModeledConversionValueVisitEvidence::CHECKPOINT
            &&(int)$rows[2]['value_ready_at_unix']>ModeledConversionValueVisitEvidence::CHECKPOINT);
        $frame=['v'=>ModeledConversionValueVisitEvidence::VERSION,'n'=>$ns,'b'=>ModeledConversionValueVisitEvidence::CHECKPOINT,'r'=>[]];
        foreach($rows as$i=>$r){
            $ref=ModeledConversionValueIdentityEvidence::reference($r,$ns);
            if($i===0)$frame['r'][]=[$ref,(string)$r['id'],2,null,$born+20,3,null,null,null,null,0,null,0,null,0,$born];
            else{$candidate=ModeledConversionValueVisitEvidence::candidateReference($ref,$r['client_id'],$r['tracker_record_id'],(int)$r['tracker_observed_unix'],'canonical_max_lookup');
                $frame['r'][]=[$ref,(string)$r['id'],2,$born+10,$born+20,1,$r['client_id'],$r['tracker_record_id'],(int)$r['tracker_observed_unix'],$candidate,2,false,4,null,0,$born];}
        }
        $recovery=ModeledConversionValueVisitRecovery::create($frame,$ns,$now);
        foreach($recovery['r']as&$r){$r[2]=2;$r[3]=$born+30;$r[4]=$born+40;$r[5]=2;$r[6]=false;$r[7]=4;$r[10]=1;}unset($r);
        $observations=[];foreach(array_slice($frame['r'],1)as$r)$observations[]=[$r[0],$now-600,$now-590,7,null,0];
        $pair=ModeledConversionFixedPairDecisionEvidence::merge(null,$frame,$ns,$observations,$now);
        $oldIntent=ModeledConversionRetainedTrackerIdentityEvidence::intent($frame,$rows[0],$ns,str_repeat('a',64),$now-100);
        $oldCase=ModeledConversionRetainedTrackerIdentityEvidence::complete($oldIntent,$frame,$rows[0],$ns,
            ['request_sha256'=>str_repeat('a',64),'result'=>[0,$now-100,4,1,0,null,null,null,null],'received_at_unix'=>$now-100],$now);
        $base=['errors'=>0,'finite_visit_evidence'=>$frame,'finite_visit_recovery'=>$recovery,'fixed_pair_operational_decisions'=>$pair,
            'finite_counter_scope_evidence'=>false,ModeledConversionRetainedAppIdentityEvidence::RECEIPT_KEY=>false,
            ModeledConversionRetainedTrackerIdentityEvidence::RECEIPT_KEY=>$oldCase,'metrika_report'=>['status'=>'unavailable']];
        $p->prepare("UPDATE modeled_conversion_value_export_settings SET mode='send',goal_id='678187097',goal_intent='verified',activated_at_unix=?,profile_cursor=?,contract_version=?,last_tick_unix=?,last_receipt_json=? WHERE project_key='anytour'")
            ->execute([$born-60,$last,ModeledConversionValueContract::VERSION,$now-5,json_encode($base,JSON_THROW_ON_ERROR)]);
        $a=ModeledConversionRetainedTrackerIdentityEvidence::knownBinding($frame,$rows[1],$ns,$now);
        $b=ModeledConversionRetainedTrackerIdentityEvidence::knownBinding($frame,$rows[2],$ns,$now);
        $check('known source scope is exactly the two original canonical candidates and excludes the missing case',
            $a!==null&&$b!==null&&$a['key']!==$b['key']&&$a['key']!==ModeledConversionRetainedTrackerIdentityEvidence::RECEIPT_KEY
            &&ModeledConversionRetainedTrackerIdentityEvidence::knownBinding($frame,$rows[0],$ns,$now)===null);
        $readyGuard=new ReflectionMethod(ModeledConversionValueExport::class,'knownTrackerReady');$guardWorker=new ModeledConversionValueExport($p);
        (new ReflectionProperty($guardWorker,'finiteFrame'))->setValue($guardWorker,$frame);
        $tupleExact=$readyGuard->invoke($guardWorker,$rows[1],$now);
        foreach(['client_id'=>'99123456789012345','tracker_record_id'=>'998877','tracker_observed_unix'=>(int)$rows[1]['tracker_observed_unix']+1]as$field=>$value){
            $changedTuple=$rows[1];$changedTuple[$field]=$value;
            $tupleExact=$tupleExact&&!$readyGuard->invoke($guardWorker,$changedTuple,$now);
        }
        $check('known READY reservation and callback require the exact held CID record and source clock',$tupleExact);
        $changed=$rows[1];$changed['state']='unknown';$changed['upload_id']='12345';
        $check('known source reservation cannot use an uploading unknown or provider-accepted row',
            !$readyGuard->invoke($guardWorker,$changed,$now));
        $hash=str_repeat('e',64);$intent=ModeledConversionRetainedTrackerIdentityEvidence::knownIntent($frame,$rows[1],$ns,$hash,$now,$pair);
        $context=ModeledConversionRetainedTrackerIdentityEvidence::knownContext($frame,$rows[1],$ns,$now);
        $wire=static function(int $kind,string $value)use($hash,$context,$now):array{
            $record='101';$fingerprint=str_repeat('c',64);$at=$context['event_at_unix']-1;
            $ref=hash('sha256',json_encode([MaxTrackerLookupProtocol::RETAINED_CLICK_HEADER,$hash,$context,$kind,$value,$record,$fingerprint,$at],JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR));
            return ['request_sha256'=>$hash,'result'=>[1,$now,1,0,$kind,$ref,$record,$fingerprint,$at],'received_at_unix'=>$now];
        };
        $views=[];foreach([[1,$a['held_client_id']],[1,'99123456789012345'],[2,'119988776655443322'],[2,$a['held_client_id']]]as[$kind,$value]){
            $done=ModeledConversionRetainedTrackerIdentityEvidence::knownComplete($intent,$frame,$rows[1],$ns,$wire($kind,$value),$now);
            $views[]=ModeledConversionRetainedTrackerIdentityEvidence::knownProjection($done,$frame,$rows[1],$ns,$now);
        }
        $check('one held raw value is compared under the returned CID or YCLID type without guessing any alternate identity',
            array_column($views,'held_value_match')===[true,false,false,true]&&$views[2]['kind']==='yclid'
            &&!str_contains(json_encode($views,JSON_THROW_ON_ERROR),'119988776655443322')&&!$views[0]['financial_permission']
            &&strlen(json_encode($intent,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR))===768);
        $wrong=$rows[1];$wrong['external_user_id']='999999';
        $check('known CID intent rejects missing no-prior proof cross-subject and cross-case reuse',
            ModeledConversionRetainedTrackerIdentityEvidence::knownIntent($frame,$rows[1],$ns,$hash,$now,null)===null
            &&ModeledConversionRetainedTrackerIdentityEvidence::knownBinding($frame,$wrong,$ns,$now)===null
            &&ModeledConversionRetainedTrackerIdentityEvidence::knownComplete($intent,$frame,$rows[2],$ns,$wire(1,$a['held_client_id']),$now)===null);
        $pool=ModeledConversionRetainedTrackerIdentityEvidence::projection($oldCase,$frame,$rows[0],$ns,$now);
        $check('the already saved missing-case result exposes only its prefix pool and saved clocks without another source request',
            $pool['status']==='observed_no_candidate'&&$pool['prefix_rows']===4&&$pool['postevent_rows']===1
            &&$pool['source_checked_at_unix']===$now-100&&$pool['app_received_at_unix']===$now-100&&!$pool['financial_permission']);
        $held=[];foreach([$rows[1],$rows[2]]as$r)$held[$r['external_user_id']]=$r;
        $lookup=new class($p,$held,$oldCase) {
            public int $tick=0;public array $calls=[];public int $sourceCalls=0;public bool $durable=true;
            public function __construct(private PDO $p,private array $held,private array $oldCase){}
            public function lookup(string $counter,array $users):array{
                $out=[];foreach($users as$uid){$this->calls[$this->tick][$uid][]='ordinary';
                    if(isset($this->held[$uid])){$r=$this->held[$uid];$out[$uid]=['external_user_id'=>$uid,'client_id'=>$r['client_id'],'record_id'=>$r['tracker_record_id'],'observed_at_unix'=>(int)$r['tracker_observed_unix']];}}
                return $out;
            }
            public function retainedClickCandidate(array $context,callable $beforeRequest):?array{
                $hash=str_repeat('e',64);if(!$beforeRequest($hash))return null;
                $this->calls[$this->tick][$context['user_id']][]='candidate';$this->sourceCalls++;
                $s=$this->p->query("SELECT last_tick_unix,last_receipt_json FROM modeled_conversion_value_export_settings WHERE project_key='anytour'")->fetch(PDO::FETCH_ASSOC);
                $j=json_decode($s['last_receipt_json'],true,32,JSON_THROW_ON_ERROR);$bound=0;
                foreach(ModeledConversionRetainedTrackerIdentityEvidence::KNOWN_KEYS as$key)if(is_array($j[$key]??null)&&$j[$key]['h']===$hash&&$j[$key]['s']===1)$bound++;
                $this->durable=$this->durable&&(int)$s['last_tick_unix']===$this->tick&&$bound===1
                    &&modeledValueFailureJsonEqual($j[ModeledConversionRetainedTrackerIdentityEvidence::RECEIPT_KEY],$this->oldCase);
                $r=$this->held[$context['user_id']];$kind=$r['id']==2?1:2;$value=$kind===1?$r['client_id']:'119988776655443322';
                $record='101';$fingerprint=str_repeat('c',64);$at=$context['event_at_unix']-1;
                $ref=hash('sha256',json_encode([MaxTrackerLookupProtocol::RETAINED_CLICK_HEADER,$hash,$context,$kind,$value,$record,$fingerprint,$at],JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR));
                return ['request_sha256'=>$hash,'result'=>[1,$this->tick,1,0,$kind,$ref,$record,$fingerprint,$at],'received_at_unix'=>$this->tick];
            }
        };
        $wire=new ModeledValueTestWire($now-150);
        $wire->goals[]=['id'=>'678187097','type'=>'action','name'=>ModeledConversionValueContract::NAME,'conditions'=>[['type'=>'exact','url'=>ModeledConversionValueContract::TARGET]]];
        $request=static function(string $method,string $url,mixed $body)use($wire):array{
            parse_str((string)parse_url($url,PHP_URL_QUERY),$q);
            if(isset($q['dimensions'])){preg_match("/clientID=='([0-9]+)'/",$q['filters']??'',$m);$wire->visitEmpty=str_starts_with($m[1]??'','88190');}
            return $wire->request($method,$url,$body);
        };
        $api=new MetrikaOfflineClient('98615635','fixture-only',$request);$gate=static fn(string $chat):bool=>true;
        $pairRow=static function(array $audit,string $ref):?array{foreach($audit['r']as$r)if($r[0]===$ref)return $r;return null;};
        $before=$p->query('SELECT * FROM max_channel_conversion_outbox WHERE id=2')->fetch(PDO::FETCH_ASSOC);
        $lookup->tick=$now;$result=ModeledConversionValueExport::tick($p,$lookup,$api,$now,$gate);
        $saved=json_decode(ModeledConversionValueExport::settings($p)['last_receipt_json'],true,32,JSON_THROW_ON_ERROR);
        $first=ModeledConversionRetainedTrackerIdentityEvidence::knownProjection($saved[$a['key']]??null,$frame,$rows[1],$ns,time());
        $once=true;foreach($lookup->calls[$now]??[]as$calls)if(count($calls)!==1)$once=false;
        $check('full25 logical users keep24 ordinary users and one separately committed known-CID source pass without promotion',
            $result['status']==='running'&&count($lookup->calls[$now]??[])===25&&$once&&$lookup->sourceCalls===1&&$lookup->durable
            &&$first['held_value_match']===true
            &&$p->query('SELECT * FROM max_channel_conversion_outbox WHERE id=2')->fetch(PDO::FETCH_ASSOC)===$before
            &&modeledValueFailureJsonEqual($saved[ModeledConversionRetainedTrackerIdentityEvidence::RECEIPT_KEY],$oldCase)
            &&modeledValueFailureJsonEqual($saved['finite_visit_evidence'],$frame)&&modeledValueFailureJsonEqual($saved['finite_visit_recovery'],$recovery)
            &&$pairRow($saved['fixed_pair_operational_decisions'],$a['ref'])===$pairRow($pair,$a['ref']));
        if($scenario==='ready_refill'){
            $check('the reserved READY row is excluded before LIMIT3 so all three other admission slots remain available',
                $wire->visitReads===3&&$wire->posts===2
                &&$p->query("SELECT state FROM max_channel_conversion_outbox WHERE id=29")->fetchColumn()==='ready');
            continue;
        }
        $secondPairBefore=$pairRow($saved['fixed_pair_operational_decisions'],$b['ref']);
        $secondBefore=$p->query('SELECT * FROM max_channel_conversion_outbox WHERE id=3')->fetch(PDO::FETCH_ASSOC);
        $lookup->tick=$now+300;$second=ModeledConversionValueExport::tick($p,$lookup,$api,$now+300,$gate);
        $saved=json_decode(ModeledConversionValueExport::settings($p)['last_receipt_json'],true,32,JSON_THROW_ON_ERROR);
        $secondView=ModeledConversionRetainedTrackerIdentityEvidence::knownProjection($saved[$b['key']]??null,$frame,$rows[2],$ns,time());
        $check('the second known case has its own once and reports YCLID presence without replacing its CID or changing the ready row',
            $second['status']==='running'&&$lookup->sourceCalls===2&&$secondView['kind']==='yclid'&&$secondView['held_value_match']===false
            &&$p->query('SELECT * FROM max_channel_conversion_outbox WHERE id=3')->fetch(PDO::FETCH_ASSOC)===$secondBefore
            &&$pairRow($saved['fixed_pair_operational_decisions'],$b['ref'])===$secondPairBefore
            &&$pairRow($saved['fixed_pair_operational_decisions'],$a['ref'])[1]===$now+300);
        $lookup->tick=$now+600;ModeledConversionValueExport::tick($p,$lookup,$api,$now+600,$gate);
        $allOnce=true;foreach($lookup->calls as$users)foreach($users as$calls)if(count($calls)>1)$allOnce=false;
        $check('two consumed known questions never repeat or reset the old case0 or original2+2 epochs',
            $allOnce&&$lookup->sourceCalls===2&&$wire->posts===0);
        $unknownReceipt=json_decode(ModeledConversionValueExport::settings($p)['last_receipt_json'],true,32,JSON_THROW_ON_ERROR);
        $unknownReceipt[$a['key']]=$intent;
        $p->prepare("UPDATE modeled_conversion_value_export_settings SET last_receipt_json=? WHERE project_key='anytour'")->execute([json_encode($unknownReceipt,JSON_THROW_ON_ERROR)]);
        $lookup->tick=$now+900;ModeledConversionValueExport::tick($p,$lookup,$api,$now+900,$gate);
        $afterUnknown=json_decode(ModeledConversionValueExport::settings($p)['last_receipt_json'],true,32,JSON_THROW_ON_ERROR);
        $check('a distinct consumed UNKNOWN known-case intent stays consumed without another source call or a reset of the old epochs',
            $lookup->sourceCalls===2&&ModeledConversionRetainedTrackerIdentityEvidence::knownProjection($afterUnknown[$a['key']],$frame,$rows[1],$ns,time())['status']==='consumed_unknown'
            &&modeledValueFailureJsonEqual($afterUnknown['finite_visit_evidence'],$frame)&&modeledValueFailureJsonEqual($afterUnknown['finite_visit_recovery'],$recovery)
            &&modeledValueFailureJsonEqual($afterUnknown[ModeledConversionRetainedTrackerIdentityEvidence::RECEIPT_KEY],$oldCase));
        $projection=new ModeledConversionValueDeliveryReader($p,static function():void{},new ModeledConversionStatusBudget());
        $bound=[];foreach($rows as$r)$bound[ModeledConversionValueIdentityEvidence::reference($r,$ns)]=$r;
        (new ReflectionProperty($projection,'boundFiniteRows'))->setValue($projection,$bound);
        $knownPacket=(new ReflectionMethod($projection,'knownTrackerObservation'))->invoke($projection,$saved,$frame,$ns,time());
        $check('private reader compactly reports only returned type and the single held-value hash comparison',
            $knownPacket['available']&&count($knownPacket['rows'])===2&&$knownPacket['rows'][0][3]===true&&$knownPacket['rows'][1][3]===false
            &&strlen(json_encode($knownPacket,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR))<=400
            &&!str_contains(json_encode($knownPacket,JSON_THROW_ON_ERROR),'881902')&&!$knownPacket['financial_permission']);
        $merge=new ReflectionMethod(ModeledConversionValueExport::class,'withKnownTrackerEvidence');$worker=new ModeledConversionValueExport($p);
        $protected=['finite_visit_evidence'=>$frame,'finite_visit_recovery'=>$recovery,'fixed_pair_operational_decisions'=>$pair,
            'finite_counter_scope_evidence'=>false,ModeledConversionRetainedTrackerIdentityEvidence::RECEIPT_KEY=>$oldCase,'last_recorded_failure'=>['kept'=>true],'padding'=>''];
        $measure=static function(array $v)use($p):int{
            $json=json_encode($v,JSON_THROW_ON_ERROR);if($p->getAttribute(PDO::ATTR_DRIVER_NAME)!=='mysql')return strlen($json);
            $q=$p->prepare('SELECT OCTET_LENGTH(CAST(? AS JSON))');$q->execute([$json]);return (int)$q->fetchColumn();
        };
        $unrelated=$protected;$unrelated['padding']=str_repeat('p',8192);
        $check('absent known-source keys leave an unrelated oversized receipt unchanged for its existing caller to handle',
            $merge->invoke($worker,$unrelated,[],$p->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql')===$unrelated);
        $markers=$protected;foreach(ModeledConversionRetainedTrackerIdentityEvidence::KNOWN_KEYS as$key)$markers[$key]=false;
        $protected['padding']=str_repeat('p',max(0,8150-$measure($markers)));
        $kept=$merge->invoke($worker,$protected,[$a['key']=>$saved[$a['key']],$b['key']=>$saved[$b['key']]],$p->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql');
        $check('private pressure keeps both new no-replay markers and all old proof including the completed missing-case result',
            $kept!==null&&$kept[$a['key']]===false&&$kept[$b['key']]===false
            &&modeledValueFailureJsonEqual($kept[ModeledConversionRetainedTrackerIdentityEvidence::RECEIPT_KEY],$oldCase)
            &&modeledValueFailureJsonEqual($kept['finite_visit_evidence'],$frame)&&modeledValueFailureJsonEqual($kept['finite_visit_recovery'],$recovery)
            &&$kept['last_recorded_failure']===$protected['last_recorded_failure']);
    }
    if($knownPacket===null)throw new RuntimeException('known_fixture_projection_unavailable');return $knownPacket;
}


if(realpath($_SERVER['SCRIPT_FILENAME']??'')===__FILE__){
    $p=new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);$passed=0;
    $check=static function(string $name,bool $ok)use(&$passed):void{if(!$ok)throw new RuntimeException('FAIL '.$name);$passed++;echo 'PASS '.$name.PHP_EOL;};
    forecastV1Assertions($p,$check);forecastLatencyAssertions($p,$check);forecastTelegramLinkageAssertions($p,$check);echo 'V1 FORECAST STATUS PASSED '.$passed.PHP_EOL;
}
