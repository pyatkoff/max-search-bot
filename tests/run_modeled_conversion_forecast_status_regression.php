<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once __DIR__.'/run_modeled_conversion_early_forecast_regression.php';
require_once dirname(__DIR__).'/services/ModeledConversionPredictionFollowup.php';
require_once dirname(__DIR__).'/services/ModeledConversionForecastStatusReader.php';

final class ForecastV1ObservedStatement extends PDOStatement
{
    public static bool $copied=false;
    public static bool $valueCopied=false;
    public static mixed $afterMetadata=null;
    protected function __construct(){}
    public function fetchAll(int $mode=PDO::FETCH_DEFAULT,mixed ...$args):array
    {
        $rows=parent::fetchAll($mode,...$args);
        if(str_contains($this->queryString,' AS reserved_rub'))self::$valueCopied=true;
        if(str_contains($this->queryString,' AS prediction_json,'))self::$copied=true;
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
    $p->exec('CREATE TABLE modeled_conversion_profile_versions (id '.($mysql?'BIGINT AUTO_INCREMENT':'INTEGER').' PRIMARY KEY,project_key VARCHAR(40),platform VARCHAR(16),customer_key VARCHAR(40),conversation_id VARCHAR(40),journal_cursor BIGINT,observed_at_unix BIGINT,input_sha256 VARCHAR(64),profile_json '.($mysql?'MEDIUMTEXT':'TEXT').')');
    $p->prepare('INSERT INTO modeled_conversion_profiles_state VALUES(?,1,?,NULL,NULL)')->execute(['anytour',
        json_encode(['status'=>'idle','observed_at_unix'=>1793004000,'profiles_written'=>0],JSON_THROW_ON_ERROR)]);
}
function forecastV1Packet(string $customer='7',string $conversation='9',array $messages=[['text'=>'Подберите тур в Турцию на 7 ночей.']],
    string $platform='telegram',int $cutoffOffset=1000,int $freezeOffset=2000,array $deltas=[],int $extractionDelay=0):array
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
    $packet=ModeledConversionEarlyForecast::freeze($case,$wrapper,$document,null,1793002000+$freezeOffset);
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
    $p->exec("CREATE TABLE max_channel_conversion_outbox(id BIGINT PRIMARY KEY,project_key VARCHAR(40),counter_id VARCHAR(20),transport_target VARCHAR(64),value_contract VARCHAR(64),event_unix BIGINT,state VARCHAR(24),value_stage VARCHAR(40),value_rub DECIMAL(12,2),upload_id VARCHAR(20),upload_status VARCHAR(32),last_error VARCHAR(64),private_marker TEXT,value_origin VARCHAR(32),value_reviewed_at_unix BIGINT,platform VARCHAR(16),conversation_id BIGINT,client_id VARCHAR(40),claimed_at_unix BIGINT,value_ready_at_unix BIGINT,value_api_accepted_at_unix BIGINT,value_processed_at_unix BIGINT,value_receipt_basis VARCHAR(24))");
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
    $receipt['metrika_report']['fetched_at']=gmdate('Y-m-d\\TH:i:s\\Z',$now-901);
    $p->prepare("UPDATE modeled_conversion_value_export_settings SET last_receipt_json=?")->execute([json_encode($receipt,JSON_THROW_ON_ERROR)]);
    $r=forecastV1Read($p);
    $check('stale cache never confirms current visit linkage',!$r['value_delivery']['metrika_goal_report']['fresh']&&$r['value_delivery']['metrika_goal_report']['visit_linkage']==='unknown');
    $receipt['metrika_report']=$report;$receipt['metrika_report']['status']='sampled';$receipt['metrika_report']['sampled']=true;
    $p->prepare("UPDATE modeled_conversion_value_export_settings SET last_receipt_json=?")->execute([json_encode($receipt,JSON_THROW_ON_ERROR)]);
    $r=forecastV1Read($p);$check('sampled totals stay explicit and do not prove exact linkage',$r['value_delivery']['metrika_goal_report']['status']==='sampled'&&$r['value_delivery']['metrika_goal_report']['visit_linkage']==='unknown');
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

function forecastV1Assertions(PDO $p,callable $check):void
{
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
    forecastInputDiagnosisAssertions($p,$check);
}

function forecastInputDiagnosisAssertions(PDO $p,callable $check):void
{
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
}


function forecastLatencyAssertions(PDO $p,callable $check):void
{
    foreach(['modeled_conversion_stream_versions','modeled_conversion_profile_versions','max_channel_conversion_outbox']as$table)$p->exec('DROP TABLE IF EXISTS '.$table);
    $mysql=$p->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql';
    $p->exec("CREATE TABLE modeled_conversion_stream_versions(id BIGINT PRIMARY KEY,project_key VARCHAR(40),source_kind VARCHAR(16),source_id BIGINT,version_no INT,observed_at_unix BIGINT,snapshot_json TEXT)");
    $p->exec("CREATE TABLE modeled_conversion_profile_versions(id BIGINT PRIMARY KEY,project_key VARCHAR(40),platform VARCHAR(16),customer_key VARCHAR(40),conversation_id BIGINT,journal_cursor BIGINT,observed_at_unix BIGINT,profile_json TEXT)");
    $p->exec("CREATE TABLE max_channel_conversion_outbox(id BIGINT PRIMARY KEY,project_key VARCHAR(40),platform VARCHAR(16),conversation_id BIGINT,counter_id VARCHAR(20),transport_target VARCHAR(64),value_contract VARCHAR(64),value_origin VARCHAR(32),value_profile_id BIGINT,event_unix BIGINT,value_rub DECIMAL(12,2),value_stage VARCHAR(32),state VARCHAR(24),client_id VARCHAR(40),upload_id VARCHAR(40),upload_status VARCHAR(32),last_error VARCHAR(64),claimed_at_unix BIGINT,created_at DATETIME)");
    $day=1793656800;$now=$day+43200;
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

if(realpath($_SERVER['SCRIPT_FILENAME']??'')===__FILE__){
    $p=new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);$passed=0;
    $check=static function(string $name,bool $ok)use(&$passed):void{if(!$ok)throw new RuntimeException('FAIL '.$name);$passed++;echo 'PASS '.$name.PHP_EOL;};
    forecastV1Assertions($p,$check);forecastLatencyAssertions($p,$check);forecastTelegramLinkageAssertions($p,$check);echo 'V1 FORECAST STATUS PASSED '.$passed.PHP_EOL;
}
