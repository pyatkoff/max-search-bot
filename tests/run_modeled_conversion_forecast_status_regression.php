<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once __DIR__.'/run_modeled_conversion_early_forecast_regression.php';
require_once dirname(__DIR__).'/services/ModeledConversionPredictionFollowup.php';
require_once dirname(__DIR__).'/services/ModeledConversionForecastStatusReader.php';

final class ForecastV1ObservedStatement extends PDOStatement
{
    public static bool $copied=false;
    public static mixed $afterMetadata=null;
    protected function __construct(){}
    public function fetchAll(int $mode=PDO::FETCH_DEFAULT,mixed ...$args):array
    {
        $rows=parent::fetchAll($mode,...$args);
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
        &&$r['input_coverage']['invalid_summary_profiles']===1);
    forecastV1Schema($p);$bad=$a;$bad['semantic_prediction']['estimated_probability']=0.9;forecastV1Append($p,$bad);
    $r=forecastV1Read($p);$check('tampered saved probability is not confirmed by metadata alone',
        $r['saved']['valid_v1_versions']===0&&$r['window']['invalid_blocks']===1);
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
}
if(realpath($_SERVER['SCRIPT_FILENAME']??'')===__FILE__){
    $p=new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);$passed=0;
    $check=static function(string $name,bool $ok)use(&$passed):void{if(!$ok)throw new RuntimeException('FAIL '.$name);$passed++;echo 'PASS '.$name.PHP_EOL;};
    forecastV1Assertions($p,$check);echo 'V1 FORECAST STATUS PASSED '.$passed.PHP_EOL;
}
