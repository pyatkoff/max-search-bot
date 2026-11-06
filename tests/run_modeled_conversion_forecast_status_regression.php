<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once __DIR__.'/run_modeled_conversion_early_forecast_regression.php';
require_once dirname(__DIR__).'/services/ModeledConversionPredictionFollowup.php';
require_once dirname(__DIR__).'/services/ModeledConversionForecastStatusReader.php';
require_once __DIR__.'/support/archive-predictive-model.php';

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

function forecastV1Assertions(PDO $p,callable $check):void
{
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
    forecastInputDiagnosisAssertions($p,$check);
    forecastPredictiveValueAssertions($p,$check);
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
    $calls=0;$revoked=false;
    try{(new ModeledConversionValueDeliveryReader($p,static function()use(&$calls):void{if(++$calls>=2)throw new DomainException('revoked');},new ModeledConversionStatusBudget()))->predictiveV1($now);}
    catch(DomainException $ignored){$revoked=true;}
    $check('authorization revoked after the scalar read prevents predictive release',$revoked);
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
    $bounded=(new ModeledConversionValueDeliveryReader($p,static function():void{},$tight))->predictiveV1($now);
    $check('oversized multibyte scalar detail is preflighted before copying and retains authorization bytes',
        $bounded===['available'=>false,'reason'=>'budget_headroom']&&$tight->remainingBytes()>=8192);

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
}

if(realpath($_SERVER['SCRIPT_FILENAME']??'')===__FILE__){
    $p=new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);$passed=0;
    $check=static function(string $name,bool $ok)use(&$passed):void{if(!$ok)throw new RuntimeException('FAIL '.$name);$passed++;echo 'PASS '.$name.PHP_EOL;};
    forecastV1Assertions($p,$check);forecastLatencyAssertions($p,$check);forecastTelegramLinkageAssertions($p,$check);echo 'V1 FORECAST STATUS PASSED '.$passed.PHP_EOL;
}
