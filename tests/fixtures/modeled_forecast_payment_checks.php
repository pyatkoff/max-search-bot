<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once dirname(__DIR__,2).'/services/ArchiveResearchSemanticExtractor.php';
require_once dirname(__DIR__,2).'/services/ModeledConversionSemanticPrediction.php';
require_once dirname(__DIR__,2).'/services/ModeledConversionForecastPaymentReader.php';
require_once dirname(__DIR__,2).'/services/ModeledConversionForecastPaymentView.php';
require_once dirname(__DIR__,2).'/services/ModeledConversionForecastPaymentAggregateView.php';

/** Synthetic-only fixtures for both SQLite memory and the registered disposable MySQL suite. */
function forecastPaymentFixtureSchema(PDO $p):void
{
    $driver=$p->getAttribute(PDO::ATTR_DRIVER_NAME);$mysql=$driver==='mysql';
    if($mysql){
        if($p->query('SELECT DATABASE()')->fetchColumn()!=='maxbot_modeled_stream_test')throw new RuntimeException('unsafe_fixture_database');
    }elseif($driver==='sqlite'){
        foreach($p->query('PRAGMA database_list')->fetchAll(PDO::FETCH_ASSOC)as$db)
            if(($db['name']??null)==='main'&&($db['file']??'')!=='')throw new RuntimeException('unsafe_fixture_database');
    }else throw new RuntimeException('unsafe_fixture_driver');
    if($p->inTransaction())throw new RuntimeException('fixture_outer_transaction');
    foreach(['modeled_conversion_profile_versions','conversations','customer_channels','customers']as$table)$p->exec('DROP TABLE IF EXISTS '.$table);
    $auto=$mysql?'BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY':'INTEGER PRIMARY KEY AUTOINCREMENT';
    $p->exec("CREATE TABLE customers(id BIGINT PRIMARY KEY,display_name VARCHAR(1024))");
    $p->exec("CREATE TABLE customer_channels(id BIGINT PRIMARY KEY,customer_id BIGINT,project_key VARCHAR(64),channel VARCHAR(16),external_user_id VARCHAR(20),external_chat_id VARCHAR(32))");
    $p->exec("CREATE TABLE conversations(id BIGINT PRIMARY KEY,customer_id BIGINT,customer_channel_id BIGINT,project_key VARCHAR(64),channel VARCHAR(16),is_test INT,external_chat_id VARCHAR(32))");
    $p->exec("CREATE TABLE modeled_conversion_profile_versions(id $auto,project_key VARCHAR(64),platform VARCHAR(16),customer_key VARCHAR(20),conversation_id VARCHAR(40),journal_cursor VARCHAR(20),observed_at_unix BIGINT,input_sha256 VARCHAR(64),profile_json ".($mysql?'JSON':'TEXT').")");
}
function forecastPaymentFixtureBinding(PDO $p,int $id,string $platform='max',string $name='Турист',int $test=0):array
{
    $user=(string)(900000+$id);$chat=$platform==='max'?'-'.$user:$user;
    $p->prepare("INSERT INTO customers VALUES(?,?)")->execute([$id,$name]);
    $p->prepare("INSERT INTO customer_channels VALUES(?,?,'anytour',?,?,?)")->execute([$id,$id,$platform,$user,$chat]);
    $p->prepare("INSERT INTO conversations VALUES(?,?,?,'anytour',?,?,?)")->execute([$id,$id,$id,$platform,$test,$chat]);
    return['project_key'=>'anytour','platform'=>$platform,'customer_key'=>(string)$id,'conversation_id'=>(string)$id];
}
function forecastPaymentFixtureWrapper(array $s,int $clock,int $anchorAt,string $mode='request',int $fence=100):array
{
    $stamp=gmdate('Y-m-d\TH:i:s\Z',$clock);$cutoff=gmdate('Y-m-d\TH:i:s\Z',$clock-1);
    $model=new ModeledConversionSemanticPrediction($s,$stamp,$cutoff,$stamp,(string)$fence,(string)$fence);
    $state=ArchiveResearchSemanticExtractor::initialState();
    $messages=[['customer','Подберите тур в Турцию на 7 ночей.','journal:10',$anchorAt,1]];
    if($mode==='intent')$messages[]=['customer','Купим тур только у вас.','journal:110',$clock-10,1];
    if($mode==='payment')$messages[]=['customer','Оплатили тур.','journal:150',$clock-10,1];
    if(in_array($mode,['double_payment','refunded_payment'],true)){
        $messages[]=['customer','Оплатили тур.','journal:150',$clock-20,1];
        $messages[]=$mode==='double_payment'
            ?['manager','Оплату получил.','journal:160',$clock-10,1]
            :['customer','Деньги вернулись.','journal:160',$clock-10,1];
    }
    if($mode==='edited_payment')$messages[]=['customer','Оплатили тур.','journal:150',$clock-10,2];
    if($mode==='documents')$messages[]=['manager','Документы готовы.','journal:150',$clock-10,1];
    foreach($messages as[$role,$text,$ref,$at,$version]){
        $r=ArchiveResearchSemanticExtractor::step(['source_ref'=>$ref,'created_at'=>$at,'read_status'=>'available',
            'text'=>$text,'role'=>$role,'direction'=>$role==='customer'?'inbound':'outbound','role_verified'=>true,
            'text_minimized'=>true,'forwarded'=>false,'media'=>[],'media_contents_read'=>false,
            'selection_links_unread'=>false,'links_redacted'=>false],$state);
        $state=$r['state'];$model->observeMessage($role,$text,$ref,$at,$at+1,$version,$r);
    }
    if($mode==='crm')$model->knownOperationalOutcome('journal:150',$clock-10,$clock-9,1);
    $block=$model->finish(['early_forecast'=>['status'=>'early_assessment','early_quality'=>[
        'version'=>'request_offer_reaction_v2','specific_option_discussions'=>[]]]]);
    return['subject'=>$s,'access_status'=>'allowed','profile'=>['prediction_at'=>$stamp],
        'processing'=>['version'=>'admitted_journal_profiles_v1_2','source_sha256'=>str_repeat('a',64),
            'journal_cursor'=>(string)$fence,'source_fence_journal_id'=>(string)$fence],
        'early_forecast'=>['version'=>'live_contextual_ordinal_v1','target_version'=>'purchase_readiness_30d_ordinal_v1'],
        'semantic_prediction'=>$block];
}
function forecastPaymentFixtureSave(PDO $p,array $w,?int $observed=null):string
{
    $s=$w['subject'];$json=json_encode($w,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
    $p->prepare("INSERT INTO modeled_conversion_profile_versions(project_key,platform,customer_key,conversation_id,journal_cursor,observed_at_unix,input_sha256,profile_json) VALUES(?,?,?,?,?,?,?,?)")
        ->execute([$s['project_key'],$s['platform'],$s['customer_key'],$s['conversation_id'],$w['processing']['journal_cursor'],
            $observed??strtotime($w['profile']['prediction_at']),hash('sha256',$json),$json]);
    return(string)$p->lastInsertId();
}
function forecastPaymentFixtureDigest(PDO $p):string
{
    $data=[];
    foreach(['customers','customer_channels','conversations','modeled_conversion_profile_versions']as$table)
        $data[$table]=$p->query('SELECT * FROM '.$table.' ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
    return hash('sha256',json_encode($data,JSON_THROW_ON_ERROR));
}

/** One aggregate case is a first admitted request, never a saved revision or evidence item. */
function forecastPaymentAggregateAssertions(PDO $p,callable $check):void
{
    $now=strtotime('2026-11-21T12:00:00Z');$key=str_repeat('aggregate-fixture-key-',3);
    $authCalls=0;$auth=static function()use(&$authCalls):void{$authCalls++;};
    $reader=new ModeledConversionForecastPaymentReader($p,$auth,$key);
    $filters=['date'=>'2026-11-21','period'=>90,'platform'=>'all'];
    forecastPaymentFixtureSchema($p);
    $empty=$reader->readAggregate([],$now);
    $check('forecast aggregate empty complete range is distinct from unavailable',($empty['available']??false)===true
        &&($empty['version']??null)===ModeledConversionForecastPaymentReader::AGGREGATE_VERSION
        &&($empty['filters']['period']??null)===1&&($empty['totals_available']??false)===true
        &&($empty['selection_exhausted']??false)===true&&($empty['cohort_membership_complete']??false)===true
        &&($empty['totals']['first_forecasts']??null)===0&&$empty['inspected']===$empty['totals']);
    $check('forecast aggregate empty groups have no invented mean',
        count($empty['totals']['probability_bins']??[])===6
        &&array_reduce($empty['totals']['probability_bins']??[],static fn(bool $ok,array $bin):bool=>
            $ok&&($bin['n']??null)===0&&array_key_exists('predicted_probability_mean',$bin)&&$bin['predicted_probability_mean']===null,true));

    $probabilities=[];$privateNames=[];$privateProfiles=[];
    $outcomes=[0=>'double_payment',1=>'payment',2=>'refunded_payment',3=>'crm',5=>'edited_payment',6=>'payment',7=>'documents'];
    for($n=0;$n<12;$n++){
        $name='COHORT_PRIVATE_NAME_'.(84001+$n).'_<script>private</script>';$privateNames[]=$name;
        $scope=forecastPaymentFixtureBinding($p,84001+$n,$n%2?'telegram':'max',$name);
        $firstAt=$n<6?$now-35*86400:$now-18000;$anchor=$firstAt-100;
        $first=forecastPaymentFixtureWrapper($scope,$firstAt,$anchor,$n===11?'intent':'request',$n===11?200:100);
        $probabilities[]=$first['semantic_prediction']['estimated_probability'];
        $privateProfiles[]=$first['semantic_prediction']['prediction_digest'];
        forecastPaymentFixtureSave($p,$first);
        // Every request has a later prediction. It must neither replace the early p nor add a case.
        forecastPaymentFixtureSave($p,forecastPaymentFixtureWrapper($scope,$firstAt+60,$anchor,'intent',220));
        if(isset($outcomes[$n]))forecastPaymentFixtureSave($p,forecastPaymentFixtureWrapper($scope,$firstAt+3600,$anchor,$outcomes[$n],300));
    }
    $before=forecastPaymentFixtureDigest($p);$page=$reader->readAggregate($filters,$now);$totals=$page['totals']??[];
    $check('forecast aggregate finishes more than one card page in one bounded read',($page['available']??false)===true
        &&($page['selection_exhausted']??false)===true&&($page['cohort_membership_complete']??false)===true
        &&($page['totals_available']??false)===true&&($totals['first_forecasts']??null)===12
        &&($page['coverage']['scanned_profiles']??null)===24&&$page['inspected']===$totals);
    $check('forecast aggregate repeat versions are omissions rather than new inquiries',
        ($page['coverage']['omitted_by_reason']['not_global_first']??null)===12
        &&($page['coverage']['invalid_earlier_profiles_by_reason']??null)===[]);
    $check('forecast aggregate maturity partitions inquiries and never converts absence into loss',
        ($totals['window_open']??null)===6&&($totals['horizon_elapsed']??null)===6
        &&($totals['later_evidence_observed']??null)===3&&($totals['followup_unknown']??null)===9
        &&($totals['followup_waiting']??null)===0&&array_key_exists('payment_label',$page)&&$page['payment_label']===null
        &&($page['verified_payment']??null)===false&&($page['evaluation_eligible']??null)===false&&($page['probability_calibrated']??null)===false);
    $check('forecast aggregate self report plus manager acknowledgment counts one observed case',
        ($totals['evidence_cases']['self_reported_payment']??null)===3
        &&($totals['evidence_cases']['manager_confirmed_payment']??null)===1
        &&$totals['later_evidence_observed']===3);
    $check('forecast aggregate contrary CRM documents and edited evidence remain explicit unknowns',
        ($totals['unknown_by_reason']['contrary_signal_after_prediction']??null)===1
        &&($totals['unknown_by_reason']['non_payment_outcome_only']??null)===2
        &&($totals['unknown_by_reason']['edited_outcome_source']??null)===1
        &&($totals['unknown_by_reason']['no_eligible_payment_evidence']??null)===5);
    $check('forecast aggregate channel and stage groups reconcile to the same twelve first requests',
        ($totals['by_platform']['max']['n']??null)===6&&($totals['by_platform']['telegram']['n']??null)===6
        &&($totals['by_platform']['max']['later_evidence_observed']??null)===2
        &&($totals['by_platform']['telegram']['later_evidence_observed']??null)===1
        &&($totals['by_stage']['concrete_request']['n']??null)===11
        &&($totals['by_stage']['agency_purchase_intent']['n']??null)===1
        &&array_sum(array_column($totals['by_stage']??[],'n'))===12);
    $mean=array_sum($probabilities)/count($probabilities);$bins=$totals['probability_bins']??[];
    $weighted=0.0;foreach($bins as$bin)$weighted+=($bin['predicted_probability_mean']??0)*($bin['n']??0);
    $check('forecast aggregate bins use the frozen first probability including the half-open 0.5 edge',
        count($bins)===6&&array_sum(array_column($bins,'n'))===12
        &&($bins[1]['n']??null)===11&&($bins[5]['n']??null)===1
        &&abs($weighted/12-$mean)<0.000001
        &&abs((float)($bins[1]['predicted_probability_mean']??-1)-$probabilities[0])<0.000001
        &&abs((float)($bins[5]['predicted_probability_mean']??-1)-0.5)<0.000001
        &&(float)($bins[5]['lower']??-1)===0.5&&(float)($bins[5]['upper']??-1)===1.0
        &&($bins[5]['upper_inclusive']??null)===true&&($bins[4]['upper_inclusive']??null)===false);
    $check('forecast aggregate freezes honest accounting units and resource ceilings',
        ($page['unit']??null)==='canonical_scope_and_request_anchor'
        &&($page['cohort_basis']??null)==='first_admitted_prediction_profile_observed_clock'
        &&($page['coverage']['source_history_complete']??null)===false&&($page['coverage']['trip_identity']??null)==='unverified'
        &&($page['coverage']['max_scan_profiles']??null)===128&&($page['coverage']['max_seconds']??null)===15
        &&($page['coverage']['max_bytes']??null)===4194304);
    $privateKeys=['rows','customer_key','customer_name','conversation_id','profile_id','request_anchor','prediction_digest',
        'model_sha256','producer_sha256','source_fence_journal_id','source_ref','observations','evidence','next_cursor','client_id','yclid'];
    $clean=static function($value)use(&$clean,$privateKeys):bool{
        if(!is_array($value))return true;
        foreach($value as$k=>$child)if(in_array((string)$k,$privateKeys,true)||!$clean($child))return false;
        return true;
    };
    $json=json_encode($page,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
    $html=ModeledConversionForecastPaymentAggregateView::render($page,$filters);$privateAbsent=$clean($page);
    foreach([...$privateNames,...$privateProfiles,'84001','84012','journal:10','journal:150']as$private)
        $privateAbsent=$privateAbsent&&!str_contains($json,$private)&&!str_contains($html,$private);
    $check('forecast aggregate DTO and rendered HTML omit private rows IDs names anchors and hashes',
        $privateAbsent&&!str_contains($html,'/manager2/#conversation=')&&!str_contains($html,'<script>private')
        &&str_contains($html,'forecast-summary'));
    $check('forecast aggregate and renderer preserve all saved source bytes and finish the snapshot',
        forecastPaymentFixtureDigest($p)===$before&&!$p->inTransaction()&&$authCalls>10);
    $tg=$reader->readAggregate(['date'=>'2026-11-21','period'=>90,'platform'=>'telegram'],$now);
    $check('forecast aggregate platform filter uses the same first-request cohort',($tg['totals_available']??false)===true
        &&($tg['totals']['first_forecasts']??null)===6&&($tg['totals']['by_platform']['max']['n']??null)===0
        &&($tg['totals']['later_evidence_observed']??null)===1);

    forecastPaymentFixtureSchema($p);$from=strtotime('2026-11-20T21:00:00Z');$until=strtotime('2026-11-21T21:00:00Z');
    foreach([$from-1,$from,$until-1,$until]as$n=>$at){
        $scope=forecastPaymentFixtureBinding($p,85001+$n);
        forecastPaymentFixtureSave($p,forecastPaymentFixtureWrapper($scope,$at,$at-100));
    }
    $old=forecastPaymentFixtureBinding($p,85005);$oldAt=$from-86400;
    forecastPaymentFixtureSave($p,forecastPaymentFixtureWrapper($old,$oldAt,$oldAt-100));
    forecastPaymentFixtureSave($p,forecastPaymentFixtureWrapper($old,$from+100,$oldAt-100,'intent',220));
    $day=['date'=>'2026-11-21','period'=>1,'platform'=>'all'];$calendar=$reader->readAggregate($day,$until+3600);
    $check('forecast aggregate one-day Moscow cohort has half-open bounds and rejects later reentry',
        ($calendar['totals_available']??false)===true&&($calendar['totals']['first_forecasts']??null)===2
        &&($calendar['coverage']['omitted_by_reason']['not_global_first']??null)===1
        &&$calendar['window']===['from'=>gmdate('Y-m-d\TH:i:s\Z',$from),'until'=>gmdate('Y-m-d\TH:i:s\Z',$until),'timezone'=>'Europe/Moscow']);
    $ordinary=$reader->read([],$now);$legacyOneDay=$reader->read($day,$now);
    $check('forecast aggregate does not change default card period or admit one-day card cursors',
        ($ordinary['filters']['period']??null)===30&&($legacyOneDay['available']??true)===false
        &&($legacyOneDay['reason']??null)==='invalid_filters');

    forecastPaymentFixtureSchema($p);$scope=forecastPaymentFixtureBinding($p,86001);
    $bad=forecastPaymentFixtureWrapper($scope,$from-60,$from-100);$bad['semantic_prediction']['estimated_probability']=0.99;
    forecastPaymentFixtureSave($p,$bad);
    forecastPaymentFixtureSave($p,forecastPaymentFixtureWrapper($scope,$from+60,$from-100,'request',220));
    $invalid=$reader->readAggregate($day,$now);
    $check('forecast aggregate invalid earlier prediction outside the window prevents a complete total',
        ($invalid['available']??false)===true&&($invalid['selection_exhausted']??false)===true
        &&($invalid['cohort_membership_complete']??true)===false&&($invalid['totals_available']??true)===false
        &&array_key_exists('totals',$invalid)&&$invalid['totals']===null&&($invalid['inspected']['first_forecasts']??null)===1
        &&($invalid['coverage']['invalid_earlier_profiles_by_reason']['prediction_admission']??null)===1);
    $denied=forecastPaymentFixtureWrapper($scope,$from+120,$from-100,'request',230);$denied['access_status']='denied';
    forecastPaymentFixtureSave($p,$denied);$revoked=$reader->readAggregate($day,$now);
    $check('forecast aggregate current scope revocation withholds totals and the affected request',
        ($revoked['available']??false)===true&&($revoked['totals_available']??true)===false&&$revoked['totals']===null
        &&($revoked['inspected']['first_forecasts']??null)===0
        &&($revoked['coverage']['omitted_by_reason']['current_scope_unavailable']??0)>0);

    forecastPaymentFixtureSchema($p);$scope=forecastPaymentFixtureBinding($p,87001);
    for($n=0;$n<128;$n++)forecastPaymentFixtureSave($p,forecastPaymentFixtureWrapper($scope,$now-5000+$n,$now-5100));
    $boundary=$reader->readAggregate($day,$now);
    $check('forecast aggregate exact 128-candidate boundary can finish a deduplicated cohort',
        ($boundary['totals_available']??false)===true&&($boundary['selection_exhausted']??false)===true
        &&($boundary['coverage']['scanned_profiles']??null)===128&&($boundary['totals']['first_forecasts']??null)===1);
    forecastPaymentFixtureSave($p,forecastPaymentFixtureWrapper($scope,$now-4000,$now-5100));
    $limited=$reader->readAggregate($day,$now);
    $check('forecast aggregate candidate 129 withholds totals instead of summing a truncated prefix',
        ($limited['available']??false)===true&&($limited['selection_exhausted']??true)===false
        &&($limited['cohort_membership_complete']??true)===false&&($limited['totals_available']??true)===false
        &&$limited['totals']===null&&($limited['inspected']['first_forecasts']??null)===1
        &&($limited['coverage']['scanned_profiles']??null)===128&&($limited['coverage']['stopped_reason']??null)==='scan_limit'
        &&!array_key_exists('next_cursor',$limited));

    $before=forecastPaymentFixtureDigest($p);
    foreach(['bytes'=>ModeledConversionForecastPaymentReader::MAX_BYTES+1,'started'=>microtime(true)-ModeledConversionForecastPaymentReader::MAX_SECONDS-1]as$field=>$value){
        $insideCalls=0;$budgetReader=null;
        $budgetReader=new ModeledConversionForecastPaymentReader($p,static function()use($p,&$budgetReader,&$insideCalls,$field,$value):void{
            if($p->inTransaction()&&++$insideCalls===2)(new ReflectionProperty(ModeledConversionForecastPaymentReader::class,$field))->setValue($budgetReader,$value);
        },$key);
        $bounded=$budgetReader->readAggregate($day,$now);
        $check('forecast aggregate '.$field.' budget preserves partial semantics without a complete total',
            ($bounded['available']??false)===true&&($bounded['totals_available']??true)===false&&$bounded['totals']===null
            &&($bounded['cohort_membership_complete']??true)===false&&($bounded['coverage']['stopped_reason']??null)==='read_limit'
            &&!$p->inTransaction()&&forecastPaymentFixtureDigest($p)===$before);
    }
    $seenSnapshot=false;$finalDenied=false;
    $revoking=new ModeledConversionForecastPaymentReader($p,static function()use($p,&$seenSnapshot):void{
        if($p->inTransaction())$seenSnapshot=true;elseif($seenSnapshot)throw new RuntimeException('SYNTHETIC_FINAL_ACCESS_REVOKED');
    },$key);
    try{$revoking->readAggregate($day,$now);}catch(RuntimeException $e){$finalDenied=$e->getMessage()==='SYNTHETIC_FINAL_ACCESS_REVOKED';}
    $check('forecast aggregate final authorization revocation prevents disclosure after rollback',
        $seenSnapshot&&$finalDenied&&!$p->inTransaction()&&forecastPaymentFixtureDigest($p)===$before);
    $initialDenied=false;
    try{(new ModeledConversionForecastPaymentReader($p,static function():void{throw new RuntimeException('SYNTHETIC_INITIAL_ACCESS_REVOKED');},$key))->readAggregate($day,$now);}
    catch(RuntimeException $e){$initialDenied=$e->getMessage()==='SYNTHETIC_INITIAL_ACCESS_REVOKED';}
    $check('forecast aggregate authorization refusal occurs before opening a snapshot',$initialDenied&&!$p->inTransaction());
    foreach([$day+['cursor'=>'synthetic-cursor'],$day+['unexpected'=>'x'],['date'=>'2026-11-21','period'=>2]]as$input){
        $bad=$reader->readAggregate($input,$now);
        $check('forecast aggregate rejects unsupported pagination or filters',($bad['available']??true)===false
            &&($bad['reason']??null)==='invalid_filters'&&array_key_exists('inspected',$bad)&&$bad['inspected']===null&&$bad['totals']===null);
    }
    $p->beginTransaction();$outer=$reader->readAggregate($day,$now);$still=$p->inTransaction();$p->rollBack();
    $check('forecast aggregate preserves a caller transaction and returns no totals',($outer['available']??true)===false
        &&($outer['reason']??null)==='outer_transaction'&&$outer['totals']===null&&$outer['inspected']===null&&$still);
    $p->exec('DROP TABLE modeled_conversion_profile_versions');$missing=$reader->readAggregate($day,$now);
    $check('forecast aggregate missing storage cannot become a successful zero cohort',($missing['available']??true)===false
        &&($missing['reason']??null)==='storage_unavailable'&&$missing['totals']===null&&$missing['inspected']===null&&!$p->inTransaction());
}

function forecastPaymentAssertions(PDO $p,callable $check):void
{
    $now=strtotime('2026-11-05T12:00:00Z');$key=str_repeat('fixture-key-',4);$authCalls=0;
    $auth=static function()use(&$authCalls):void{$authCalls++;};
    $reader=new ModeledConversionForecastPaymentReader($p,$auth,$key);
    $filters=['date'=>'2026-11-05','period'=>90,'platform'=>'all'];
    forecastPaymentFixtureSchema($p);
    try{
        foreach([
            ['2026-11-05T20:59:59Z','2026-11-05','05.11.2026 23:59:59'],
            ['2026-11-05T21:00:00Z','2026-11-06','06.11.2026 00:00:00'],
            ['2026-11-05T21:30:00Z','2026-11-06','06.11.2026 00:30:00'],
            ['2026-12-31T20:59:59Z','2026-12-31','31.12.2026 23:59:59'],
            ['2026-12-31T21:00:00Z','2027-01-01','01.01.2027 00:00:00'],
            ['2026-12-31T21:00:01Z','2027-01-01','01.01.2027 00:00:01'],
        ]as[$instant,$date,$display]){
            $at=strtotime($instant);$automatic=$reader->read([],$at);$explicit=$reader->read(['date'=>$date],$at);
            $check('forecast default and explicit Moscow day '.$instant,($automatic['available']??false)&&$automatic['filters']['date']===$date
                &&$automatic['window']['timezone']==='Europe/Moscow'&&($explicit['available']??false)&&$automatic['window']===$explicit['window']);
            $html=ModeledConversionForecastPaymentView::render($automatic);
            $check('forecast Moscow midnight presentation '.$instant,str_contains($html,$display)&&str_contains($html,'время по Москве (МСК)'));
        }
        $future=$reader->read(['date'=>'2027-01-01'],strtotime('2026-12-31T20:59:59Z'));
        $check('forecast next Moscow day is rejected before midnight',($future['reason']??null)==='invalid_filters');
        foreach([7=>'2026-12-25T21:00:00Z',30=>'2026-12-02T21:00:00Z',90=>'2026-10-03T21:00:00Z']as$period=>$start){
            forecastPaymentFixtureSchema($p);$end='2027-01-01T21:00:00Z';
            foreach([strtotime($start)-1,strtotime($start),strtotime($end)-1,strtotime($end)]as$i=>$at){
                $scope=forecastPaymentFixtureBinding($p,83000+$i,$i%2?'max':'telegram');
                forecastPaymentFixtureSave($p,forecastPaymentFixtureWrapper($scope,$at,$at-100));
            }
            $calendarBefore=forecastPaymentFixtureDigest($p);
            $calendar=$reader->read(['date'=>'2027-01-01','period'=>$period],strtotime('2027-01-02T12:00:00Z'));
            $check('forecast '.$period.' day Moscow cohort has half-open boundaries',($calendar['available']??false)
                &&$calendar['window']===['from'=>$start,'until'=>$end,'timezone'=>'Europe/Moscow']
                &&array_column($calendar['rows'],'conversation_id')===['83001','83002']&&$calendar['has_more']===false);
            $check('forecast '.$period.' day projection preserves saved UTC profiles and identities',forecastPaymentFixtureDigest($p)===$calendarBefore
                &&$calendar['rows'][0]['forecast']['profile_observed_at']===$start
                &&$calendar['rows'][1]['forecast']['profile_observed_at']==='2027-01-01T20:59:59Z'&&!$p->inTransaction());
        }
        forecastPaymentFixtureSchema($p);
        $s=forecastPaymentFixtureBinding($p,81001,'max','<script>alert("synthetic")</script>');
        $base=forecastPaymentFixtureWrapper($s,$now-14400,$now-14500);
        $admission=ModeledConversionValueRegistry::predictionFields($base);
        $check('forecast synthetic producer block passes shared admission',($admission['semantic_prediction']['availability']??null)==='available');
        $baselineId=forecastPaymentFixtureSave($p,$base);
        forecastPaymentFixtureSave($p,forecastPaymentFixtureWrapper($s,$now-10800,$now-14500,'intent',120));
        forecastPaymentFixtureSave($p,forecastPaymentFixtureWrapper($s,$now-3600,$now-14500,'payment',200));
        $tg=forecastPaymentFixtureBinding($p,81002,'telegram');
        forecastPaymentFixtureSave($p,forecastPaymentFixtureWrapper($tg,$now-3500,$now-3600));
        $mature=forecastPaymentFixtureBinding($p,81003);
        forecastPaymentFixtureSave($p,forecastPaymentFixtureWrapper($mature,$now-35*86400,$now-35*86400-100));
        $clock=forecastPaymentFixtureBinding($p,81004);
        forecastPaymentFixtureSave($p,forecastPaymentFixtureWrapper($clock,$now-5000,$now-5100),$now-5001);
        $clockGood=forecastPaymentFixtureSave($p,forecastPaymentFixtureWrapper($clock,$now-4000,$now-5100,'request',110));
        $tampered=forecastPaymentFixtureBinding($p,81005);
        $bad=forecastPaymentFixtureWrapper($tampered,$now-5000,$now-5100);$bad['semantic_prediction']['estimated_probability']=0.99;
        forecastPaymentFixtureSave($p,$bad);
        $tamperGood=forecastPaymentFixtureSave($p,forecastPaymentFixtureWrapper($tampered,$now-4000,$now-5100,'request',110));
        $peer=forecastPaymentFixtureBinding($p,81006);
        forecastPaymentFixtureSave($p,forecastPaymentFixtureWrapper($peer,$now-3500,$now-3600));
        $p->exec("UPDATE customer_channels SET external_chat_id='-99999999' WHERE id=81006");
        $test=forecastPaymentFixtureBinding($p,81007,'max','Synthetic test',1);
        forecastPaymentFixtureSave($p,forecastPaymentFixtureWrapper($test,$now-3500,$now-3600));
        $crm=forecastPaymentFixtureBinding($p,81008);
        forecastPaymentFixtureSave($p,forecastPaymentFixtureWrapper($crm,$now-5000,$now-5100));
        forecastPaymentFixtureSave($p,forecastPaymentFixtureWrapper($crm,$now-4000,$now-5100,'crm',200));
        $doc=forecastPaymentFixtureBinding($p,81009);
        forecastPaymentFixtureSave($p,forecastPaymentFixtureWrapper($doc,$now-5000,$now-5100));
        forecastPaymentFixtureSave($p,forecastPaymentFixtureWrapper($doc,$now-4000,$now-5100,'documents',200));
        $revoked=forecastPaymentFixtureBinding($p,81010);
        forecastPaymentFixtureSave($p,forecastPaymentFixtureWrapper($revoked,$now-5000,$now-5100));
        $deny=forecastPaymentFixtureWrapper($revoked,$now-4000,$now-5100);$deny['access_status']='denied';
        forecastPaymentFixtureSave($p,$deny);
        $edited=forecastPaymentFixtureBinding($p,81011);
        forecastPaymentFixtureSave($p,forecastPaymentFixtureWrapper($edited,$now-5000,$now-5100));
        forecastPaymentFixtureSave($p,forecastPaymentFixtureWrapper($edited,$now-4000,$now-5100,'edited_payment',200));
        $old=forecastPaymentFixtureBinding($p,81012);
        forecastPaymentFixtureSave($p,forecastPaymentFixtureWrapper($old,$now-45*86400,$now-45*86400-100));
        forecastPaymentFixtureSave($p,forecastPaymentFixtureWrapper($old,$now-4000,$now-45*86400-100,'request',110));
        $before=forecastPaymentFixtureDigest($p);$all=[];$input=$filters;$pages=0;$syntheticPages=[];
        do{
            $page=$reader->read($input,$now);$pages++;
            $syntheticPages[]=['available'=>$page['available']??null,'reason'=>$page['reason']??null,'coverage'=>$page['coverage']??null,'has_more'=>$page['has_more']??null];
            if(($page['available']??false)!==true)fwrite(STDERR,'SYNTHETIC_ONLY_FORECAST_PAGES '.json_encode($syntheticPages,JSON_THROW_ON_ERROR).PHP_EOL);
            $check('forecast payment page available '.$pages,$page['available']===true);
            if(!$page['available'])break;
            foreach($page['rows']as$row)$all[]=$row;
            $check('forecast payment page shape '.$pages,count($page['rows'])<=8&&$page['coverage']['scanned_profiles']<=128
                &&$page['coverage']['returned_rows']===count($page['rows'])&&$page['coverage']['scanned_prefix_complete']===!$page['has_more']);
            $input=$filters+($page['has_more']?['cursor'=>$page['next_cursor']]:[]);
        }while($page['has_more']&&$pages<10);
        $by=[];foreach($all as$row)$by[$row['conversation_id']]=$row;
        $earliestOnce=!($page['has_more']??true)&&count($all)===count($by)&&count(array_filter($all,static fn(array $r):bool=>$r['conversation_id']==='81001'))===1;
        if(!$earliestOnce)fwrite(STDERR,'SYNTHETIC_ONLY_FORECAST_PAGES '.json_encode($syntheticPages,JSON_THROW_ON_ERROR).PHP_EOL);
        $check('forecast earliest baseline occurs once across all pages',$earliestOnce);
        $first=$by['81001']??[];
        $check('forecast immutable first estimate survives stronger later stage',($first['profile_id']??null)===$baselineId
            &&($first['forecast']['estimated_probability']??null)===$base['semantic_prediction']['estimated_probability']
            &&($first['forecast']['prediction_digest']??null)===$base['semantic_prediction']['prediction_digest']);
        $check('forecast real comparator admits later synthetic self report',($first['followup']['status']??null)==='observed'
            &&($first['followup']['evidence'][0]['label']??null)==='self_reported_payment'
            &&($first['followup']['prior_prediction_digest']??null)===$base['semantic_prediction']['prediction_digest']
            &&($first['verified_payment']??null)===false&&($first['evaluation_eligible']??null)===false);
        $check('forecast native observed clock mismatch cannot set cohort',($by['81004']['profile_id']??null)===$clockGood
            &&($by['81004']['forecast']['invalid_earlier_profiles']['clock_or_fence']??null)===1);
        $check('forecast tampered digest is skipped with visible coverage',($by['81005']['profile_id']??null)===$tamperGood
            &&($by['81005']['forecast']['invalid_earlier_profiles']['prediction_admission']??null)===1);
        $check('forecast mature absence remains unknown',($by['81003']['maturity']['state']??null)==='elapsed_coverage_unknown'
            &&($by['81003']['followup']['status']??null)==='unknown'&&array_key_exists('payment_label',$by['81003']['followup'])
            &&$by['81003']['followup']['payment_label']===null);
        $check('forecast CRM win and documents are not payment',($by['81008']['followup']['status']??null)==='unknown'
            &&($by['81008']['followup']['reason']??null)==='non_payment_outcome_only'
            &&($by['81009']['followup']['status']??null)==='unknown');
        $check('forecast edited payment stays unknown',($by['81011']['followup']['status']??null)==='unknown'
            &&($by['81011']['followup']['reason']??null)==='edited_outcome_source');
        $check('forecast native peer test and revoked access excluded',!isset($by['81006'])&&!isset($by['81007'])&&!isset($by['81010']));
        $tgPage=$reader->read(['date'=>'2026-11-05','period'=>90,'platform'=>'telegram'],$now);
        $check('forecast platform filter isolates Telegram',($tgPage['available']??false)&&count($tgPage['rows'])===1
            &&$tgPage['rows'][0]['conversation_id']==='81002'&&$tgPage['rows'][0]['platform']==='telegram');
        $short=$reader->read(['date'=>'2026-11-05','period'=>7,'platform'=>'all'],$now);$shortIds=array_column($short['rows']??[],'conversation_id');
        $shortPages=1;while(($short['has_more']??false)===true&&$shortPages++<10){
            $short=$reader->read(['date'=>'2026-11-05','period'=>7,'platform'=>'all','cursor'=>$short['next_cursor']],$now);
            $shortIds=[...$shortIds,...array_column($short['rows']??[],'conversation_id')];
        }
        $check('forecast global earliest before date filter prevents cohort reentry',($short['available']??false)&&!($short['has_more']??true)&&!in_array('81012',$shortIds,true));
        $view=ModeledConversionForecastPaymentView::render($tgPage,['date'=>'2026-11-05','period'=>90,'platform'=>'telegram']);
        $check('forecast owner view renders safe useful fields',str_contains($view,'Ранний прогноз')&&str_contains($view,'/manager2/#conversation=81002'));
        $oldCalendar=$tgPage;$oldCalendar['window']['timezone']='Europe/Kaliningrad';
        $check('forecast old cohort cannot be labelled Moscow',str_contains(ModeledConversionForecastPaymentView::render($oldCalendar),'Сопоставление сейчас недоступно'));
        $namePage=$reader->read($filters,$now);$html=ModeledConversionForecastPaymentView::render($namePage,$filters);
        $check('forecast customer name is escaped in private view',!str_contains($html,'<script>alert(')
            &&str_contains($html,'&lt;script&gt;')&&str_contains($html,'/manager2/#conversation=81001'));
        $check('forecast read and pure view do not write fixtures',forecastPaymentFixtureDigest($p)===$before&&!$p->inTransaction()&&$authCalls>10);

        forecastPaymentFixtureSchema($p);$baselineIds=[];
        for($n=1;$n<=10;$n++){
            $s=forecastPaymentFixtureBinding($p,82000+$n,$n%2?'max':'telegram');
            $baselineIds[]=forecastPaymentFixtureSave($p,forecastPaymentFixtureWrapper($s,$now-5000,$now-5100));
            forecastPaymentFixtureSave($p,forecastPaymentFixtureWrapper($s,$now-4000,$now-5100,'intent',120));
        }
        $firstPage=$reader->read($filters,$now);$cursor=$firstPage['next_cursor']??null;
        $check('forecast stable paging exposes bounded continuation',($firstPage['available']??false)&&count($firstPage['rows'])===8&&is_string($cursor)&&$firstPage['has_more']===true);
        if(is_string($cursor)){
            $parts=explode('.',$cursor);
            $legacy='fpr1.'.$parts[1].'.'.hash_hmac('sha256',"forecast-payment-owner-v1\n".$parts[1],$key);
            $bad=$reader->read($filters+['cursor'=>$legacy],$now);
            $check('forecast rejects correctly signed legacy Kaliningrad pagination',($bad['available']??true)===false&&($bad['reason']??null)==='invalid_cursor');
            $late=forecastPaymentFixtureBinding($p,82011);
            forecastPaymentFixtureSave($p,forecastPaymentFixtureWrapper($late,$now-5000,$now-5100));
            $secondPage=$reader->read($filters+['cursor'=>$cursor],$now+10);
            $joined=[...$firstPage['rows'],...($secondPage['rows']??[])];$ids=array_column($joined,'profile_id');
            $check('forecast cursor freezes asof fence and deduplicates late versions',($secondPage['available']??false)
                &&count($ids)===10&&count(array_unique($ids))===10&&array_diff($ids,$baselineIds)===[]
                &&($secondPage['as_of']??null)===$firstPage['as_of']);
            $tampered=substr($cursor,0,-1).(substr($cursor,-1)==='0'?'1':'0');
            $bad=$reader->read($filters+['cursor'=>$tampered],$now+10);
            $check('forecast forged continuation rejected',($bad['available']??true)===false&&($bad['reason']??null)==='invalid_cursor');
            $bad=$reader->read(['date'=>'2026-11-05','period'=>7,'platform'=>'all','cursor'=>$cursor],$now+10);
            $check('forecast cursor cannot change cohort',($bad['available']??true)===false&&($bad['reason']??null)==='invalid_cursor');
            $bad=(new ModeledConversionForecastPaymentReader($p,$auth,str_repeat('another-key-',4)))->read($filters+['cursor'=>$cursor],$now+10);
            $check('forecast cursor is bound to owner secret',($bad['available']??true)===false);
            $bad=$reader->read($filters+['cursor'=>$cursor],$now+3601);
            $check('forecast cursor expires closed',($bad['available']??true)===false&&($bad['reason']??null)==='invalid_cursor');
        }
        $bad=$reader->read($filters+['unknown'=>'x'],$now);
        $check('forecast unexpected input rejected',($bad['available']??true)===false&&($bad['reason']??null)==='invalid_filters');
        $denied=false;try{(new ModeledConversionForecastPaymentReader($p,static function():void{throw new RuntimeException('denied');},$key))->read($filters,$now);}catch(RuntimeException $e){$denied=true;}
        $check('forecast authorization fails before reading data',$denied&&!$p->inTransaction());
        $p->beginTransaction();$bad=$reader->read($filters,$now);$still=$p->inTransaction();$p->rollBack();
        $check('forecast reader rejects and preserves caller transaction',($bad['reason']??null)==='outer_transaction'&&$still);
        $p->exec('DROP TABLE modeled_conversion_profile_versions');$bad=$reader->read($filters,$now);
        $check('forecast missing storage is unavailable rather than empty success',($bad['available']??true)===false&&($bad['reason']??null)==='storage_unavailable'&&!$p->inTransaction());
        forecastPaymentAggregateAssertions($p,$check);
    }finally{
        if($p->inTransaction())$p->rollBack();
        foreach(['modeled_conversion_profile_versions','conversations','customer_channels','customers']as$table)$p->exec('DROP TABLE IF EXISTS '.$table);
    }
}
