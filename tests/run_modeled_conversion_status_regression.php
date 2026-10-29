<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once __DIR__.'/run_modeled_conversion_early_forecast_regression.php';
require_once dirname(__DIR__).'/services/ModeledConversionStatusReader.php';

final class ForecastStatusObservedStatement extends PDOStatement
{
    public static bool $payloadCopied=false;
    protected function __construct(){}
    public function fetchAll(int $mode=PDO::FETCH_DEFAULT,mixed ...$args):array
    {
        $rows=parent::fetchAll($mode,...$args);
        if(str_contains($this->queryString,' AS early_json,'))self::$payloadCopied=true;
        return $rows;
    }
}
function forecastStatusSchema(PDO $p):void
{
    foreach(['modeled_conversion_profile_versions','modeled_conversion_profiles_state']as$t)$p->exec('DROP TABLE IF EXISTS '.$t);
    $mysql=$p->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql';
    $p->exec('CREATE TABLE modeled_conversion_profiles_state (project_key VARCHAR(40) PRIMARY KEY,enabled INT,ticks BIGINT,last_receipt_json TEXT,last_error_at_unix BIGINT,last_error_category VARCHAR(40))');
    $p->exec('CREATE TABLE modeled_conversion_profile_versions (id '.($mysql?'BIGINT AUTO_INCREMENT':'INTEGER').' PRIMARY KEY,project_key VARCHAR(40),platform VARCHAR(16),customer_key VARCHAR(40),conversation_id VARCHAR(40),journal_cursor BIGINT,observed_at_unix BIGINT,input_sha256 VARCHAR(64),profile_json '.($mysql?'MEDIUMTEXT':'TEXT').')');
    $receipt=['status'=>'idle','observed_at_unix'=>1793004000,'profiles_written'=>0,'pending_revisions'=>0,
        'early_forecast'=>['version'=>ModeledConversionEarlyForecast::VERSION,'snapshots'=>0]];
    $p->prepare('INSERT INTO modeled_conversion_profiles_state VALUES (?,1,9,?,NULL,NULL)')->execute(['anytour',json_encode($receipt,JSON_THROW_ON_ERROR)]);
}
function forecastStatusPacket(array $messages,?string $first=null):array
{
    [$case,$wrapper,$document]=modeledEarlyFixture($messages);
    $packet=ModeledConversionEarlyForecast::freeze($case,$wrapper,$document,$first,1793004000);
    return $wrapper+$packet+['processing'=>['version'=>'admitted_journal_profiles_v1_2','journal_cursor'=>$document['journal_cursor'],'source_fence_journal_id'=>$document['source_fence_journal_id']]];
}
function forecastStatusAppend(PDO $p,array|string $packet,string $project='anytour',string $customer='7',string $conversation='9'):void
{
    $cursor=is_array($packet)?$packet['journal_cursor']:'1';
    $json=is_array($packet)?json_encode($packet,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR):$packet;
    $p->prepare('INSERT INTO modeled_conversion_profile_versions(project_key,platform,customer_key,conversation_id,journal_cursor,observed_at_unix,input_sha256,profile_json) VALUES (?,?,?,?,?,?,?,?)')->execute([$project,'telegram',$customer,$conversation,$cursor,1793003000,str_repeat('a',64),$json]);
}
function forecastStatusScope(array $packet,string $customer,string $conversation):array
{
    $visit=static function(array &$value)use(&$visit,$customer,$conversation):void{
        foreach($value as$key=>&$item){
            if(is_array($item))$visit($item);
            elseif($key==='customer_key')$item=$customer;
            elseif($key==='conversation_id')$item=$conversation;
        }
        unset($item);
    };
    $visit($packet);return $packet;
}
function forecastStatusRead(PDO $p,?callable $authorize=null,?ModeledConversionStatusBudget $budget=null):array
{
    return (new ModeledConversionStatusReader($p,$authorize??static function():void{},$budget))->read();
}
function forecastStatusAssertions(PDO $p,callable $check):void
{
    $initial=forecastStatusPacket([['text'=>'Подберите тур. Бюджет до 200 тысяч.']]);
    $update=forecastStatusPacket([['text'=>'Подберите тур. Бюджет до 200 тысяч.'],['text'=>'Давайте бронировать этот тур']],'1');
    forecastStatusAppend($p,$initial);forecastStatusAppend($p,$update);
    $r=forecastStatusRead($p);
    $check('actual saved paired snapshots prove early signals despite a later idle receipt',
        $r['confirmation']==='saved_early_assessment_with_signals'&&$r['saved']['paired_snapshots']===1
        &&$r['saved']['early_assessments_with_signals']===1&&$r['saved']['customer_context_snapshots']===1&&$r['state']['last_batch_forecast_snapshots']===0
        &&$r['state']['last_tick_status']==='idle');
    $check('snapshot counts are not reported as new people or trips',
        $r['saved']['distinct_conversation_scopes']===1&&$r['saved']['snapshot_kind_counts']['initial_observation']===0
        &&$r['saved']['snapshot_kind_counts']['updated_observation']===1
        &&$r['saved']['basis']==='inspected_saved_versions_not_unique_people_or_trips');
    $check('one chosen version per scope leaves older initial-marker linkage explicitly unverified',
        $r['saved']['first_marker']['initial_self_markers']===0
        &&$r['saved']['first_marker']['updated_links_verified_in_window']===0
        &&$r['saved']['first_marker']['updated_links_outside_checked_window']===1);
    $check('actual freeze range stays distinct from earlier canonical extraction time',
        $r['saved']['frozen_at_range']['earliest']==='2026-10-26T08:40:00Z'
        &&$r['saved']['frozen_at_range']['latest']==='2026-10-26T08:40:00Z');
    $json=json_encode($r,JSON_THROW_ON_ERROR);
    $check('private aggregate has no source identifiers text hashes or arbitrary profile fields',
        !str_contains($json,'journal:')&&!str_contains($json,'customer_key')&&!str_contains($json,'conversation_id')
        &&!str_contains($json,str_repeat('a',64))&&!str_contains($json,'Бюджет')&&!str_contains($json,'source_fence'));
    $p->exec("UPDATE modeled_conversion_profiles_state SET last_error_at_unix=1792990000,last_error_category='processing_failed'");
    $r=forecastStatusRead($p);
    $check('historical processing error retains its time without overriding a later healthy idle tick',
        $r['state']['last_tick_status']==='idle'&&$r['state']['last_recorded_error']['at']===gmdate('Y-m-d\TH:i:s\Z',1792990000)
        &&$r['state']['last_recorded_error']['category']==='processing_failed'
        &&$r['interpretation']['last_recorded_error_is_historical_not_current_health']);
    $receiptBefore=$p->query("SELECT last_receipt_json FROM modeled_conversion_profiles_state WHERE project_key='anytour'")->fetchColumn();
    $ordinaryBudget=new ModeledConversionStatusBudget();forecastStatusRead($p,null,$ordinaryBudget);
    $p->prepare("UPDATE modeled_conversion_profiles_state SET last_receipt_json=? WHERE project_key='anytour'")->execute([str_repeat('x',50000)]);
    $oversizeBudget=new ModeledConversionStatusBudget();$r=forecastStatusRead($p,null,$oversizeBudget);
    $check('oversized state receipt is omitted in SQL before copying while saved forecasts remain readable',
        $r['available']&&$r['saved']['paired_snapshots']===1&&$r['state']['last_tick_status']==='unknown'
        &&$r['state']['last_batch_forecast_snapshots']===null
        &&$oversizeBudget->remainingBytes()>=$ordinaryBudget->remainingBytes()-1024);
    $p->prepare("UPDATE modeled_conversion_profiles_state SET last_receipt_json=? WHERE project_key='anytour'")->execute([$receiptBefore]);
    $before=(int)$p->query('SELECT COUNT(*) FROM modeled_conversion_profile_versions')->fetchColumn();
    if($p->getAttribute(PDO::ATTR_DRIVER_NAME)==='sqlite')$p->exec('PRAGMA query_only=ON');
    $readOnly=forecastStatusRead($p);
    if($p->getAttribute(PDO::ATTR_DRIVER_NAME)==='sqlite')$p->exec('PRAGMA query_only=OFF');
    $check('reader needs no native source tables and preserves stored versions',
        $readOnly['saved']['paired_snapshots']===1&&(int)$p->query('SELECT COUNT(*) FROM modeled_conversion_profile_versions')->fetchColumn()===$before);

    forecastStatusSchema($p);
    forecastStatusAppend($p,$update);
    $r=forecastStatusRead($p);
    $check('an initial marker outside the bounded sample remains unknown',
        $r['saved']['first_marker']['updated_links_verified_in_window']===0
        &&$r['saved']['first_marker']['updated_links_outside_checked_window']===1);
    $p->exec('DELETE FROM modeled_conversion_profile_versions');
    $r=forecastStatusRead($p);
    $check('empty checked tail is no confirmation rather than no sales or failed forecasting',
        $r['confirmation']==='no_confirmation_in_checked_window'&&$r['saved']['paired_snapshots']===0
        &&!$r['interpretation']['all_history_coverage']);

    forecastStatusAppend($p,forecastStatusPacket([['text'=>'Подберите тур'],['role'=>'manager','text'=>'Оплату вижу']]));
    $r=forecastStatusRead($p);
    $check('payment already observed proves saved recording but cannot prove an early purchase forecast',
        $r['saved']['paired_snapshots']===1&&$r['saved']['status_counts']['target_already_observed']===1
        &&$r['saved']['early_assessments_with_signals']===0&&$r['confirmation']==='saved_packet_without_early_signal_confirmation');

    forecastStatusSchema($p);
    $managerPaid=forecastStatusPacket([['role'=>'manager','text'=>'Оплату вижу']]);
    forecastStatusAppend($p,$managerPaid);$r=forecastStatusRead($p);
    $check('manager-only observed payment remains a valid explicit fallback without customer context',
        $r['saved']['paired_snapshots']===1&&$r['saved']['customer_context_snapshots']===0
        &&$r['saved']['status_counts']['target_already_observed']===1);
    forecastStatusSchema($p);
    $managerPaid['early_forecast']['status']='no_usable_customer_context';
    forecastStatusAppend($p,$managerPaid);$r=forecastStatusRead($p);
    $check('an observed payment cannot masquerade as merely missing customer context',
        $r['saved']['paired_snapshots']===0&&$r['window']['invalid_rows']===1);

    $cases=[
        'cursor mismatch'=>static function(array &$v):void{$v['early_forecast']['journal_cursor']='999';},
        'signal fence mismatch'=>static function(array &$v):void{$v['contextual_signals']['source_fence_journal_id']='999';},
        'first marker ahead'=>static function(array &$v):void{$v['early_forecast']['first_snapshot_journal_cursor']='999';},
        'future freeze'=>static function(array &$v):void{$v['early_forecast']['frozen_at']=$v['contextual_signals']['frozen_at']=gmdate('Y-m-d\TH:i:s\Z',time()+3600);},
        'numeric purchase probability'=>static function(array &$v):void{$v['early_forecast']['purchase_with_us']['probability']=0.8;},
        'export enabled'=>static function(array &$v):void{$v['early_forecast']['export_allowed']=true;},
        'unmatched event counts'=>static function(array &$v):void{$v['contextual_signals']['goal_counts']['substantive_interest']++;},
        'future related premise'=>static function(array &$v):void{$v['contextual_signals']['events'][0]['related_source_refs']=['journal:999'];},
        'missing readiness band'=>static function(array &$v):void{unset($v['early_forecast']['purchase_anywhere']['readiness_band']);},
        'invalid seller readiness band'=>static function(array &$v):void{$v['early_forecast']['purchase_with_us']['readiness_band']='private label';},
        'wrong horizon'=>static function(array &$v):void{$v['early_forecast']['horizon_seconds']=1;},
        'wrong horizon end'=>static function(array &$v):void{$v['early_forecast']['horizon_end_at']=$v['early_forecast']['prediction_at'];},
        'denied parent with early assessment'=>static function(array &$v):void{$v['access_status']='denied';},
        'future event beyond fence'=>static function(array &$v):void{$v['contextual_signals']['events'][0]['source_ref']='journal:999';},
        'wrong source namespace'=>static function(array &$v):void{$v['contextual_signals']['events'][0]['source_ref']='archive_message:1';},
        'nested scope substitution'=>static function(array &$v):void{$v['subject']['customer_key']='8';},
        'invented initial marker'=>static function(array &$v):void{$v['early_forecast']['first_snapshot_journal_cursor']='0';},
        'arbitrary event goal'=>static function(array &$v):void{$v['contextual_signals']['events'][0]['goal']=['private text'];},
        'string customer count'=>static function(array &$v):void{$v['contextual_signals']['engagement']['customer_turns']='1';},
        'early assessment without customer'=>static function(array &$v):void{$v['contextual_signals']['engagement']['customer_turns']=0;},
        'too many observed turns'=>static function(array &$v):void{$v['contextual_signals']['engagement']['manager_turns']=50;},
    ];
    foreach($cases as$name=>$mutate){
        forecastStatusSchema($p);$v=$initial;$mutate($v);forecastStatusAppend($p,$v);$r=forecastStatusRead($p);
        $check($name.' cannot become confirmed saved signal',$r['saved']['paired_snapshots']===0&&$r['window']['invalid_rows']===1);
    }
    forecastStatusSchema($p);$v=$initial;$v['early_forecast']['version']='private-unrecognized';forecastStatusAppend($p,$v);$r=forecastStatusRead($p);
    $check('unknown versions are fixed unsupported counts without copying arbitrary labels',
        $r['window']['unsupported_version_rows']===1&&!str_contains(json_encode($r),'private-unrecognized'));
    forecastStatusSchema($p);forecastStatusAppend($p,['journal_cursor'=>'1','private_text'=>'hidden']);$r=forecastStatusRead($p);
    $check('old factual profiles without forecast remain explicitly outside verification',
        $r['window']['without_forecast_rows']===1&&$r['saved']['paired_snapshots']===0);
    forecastStatusSchema($p);forecastStatusAppend($p,'{"broken":');$r=forecastStatusRead($p);
    $check('invalid JSON is rejected using metadata without copying its payload',
        $r['window']['invalid_rows']===1&&$r['window']['profile_rows_read']===0);

    forecastStatusSchema($p);forecastStatusAppend($p,$initial);$v=$initial;$v['contextual_signals']['private_padding']=str_repeat('😀',15000);forecastStatusAppend($p,forecastStatusScope($v,'8','10'),'anytour','8','10');$r=forecastStatusRead($p);
    $check('oversized UTF8 tail is omitted by byte length while a fitting saved snapshot remains verifiable',
        $r['window']['oversize_rows']===1&&$r['saved']['paired_snapshots']===1&&$r['window']['profile_rows_read']===1
        &&$r['window']['selected_document_bytes']<=ModeledConversionStatusReader::PROFILE_BYTES);
    forecastStatusSchema($p);$v=$initial;$v['profile']['private_factual_body']=str_repeat('private fact 😀',20000);forecastStatusAppend($p,$v);$r=forecastStatusRead($p);
    $check('large unrelated factual body is never copied and cannot hide a small saved signal pair',
        $r['saved']['paired_snapshots']===1&&$r['window']['oversize_rows']===0&&$r['window']['selected_document_bytes']<49152
        &&!str_contains(json_encode($r),'private fact'));
    forecastStatusSchema($p);
    for($i=0;$i<8;$i++){$v=$initial;$v['contextual_signals']['private_padding']=str_repeat('\\\\"',1500);forecastStatusAppend($p,forecastStatusScope($v,(string)(20+$i),(string)(30+$i)),'anytour',(string)(20+$i),(string)(30+$i));}
    $budget=new ModeledConversionStatusBudget();$r=forecastStatusRead($p,null,$budget);
    $check('escaped JSON and multiple fitting rows stay within shared selected-byte budget',
        $r['window']['profile_rows_read']<=4&&$r['window']['selected_document_bytes']<=49152
        &&$budget->remainingBytes()>=0&&$r['window']['byte_limit_omissions']+$r['window']['row_limit_omissions']>0);
    forecastStatusSchema($p);
    for($i=0;$i<25;$i++)forecastStatusAppend($p,$initial);
    $r=forecastStatusRead($p);
    $check('repeated versions consume one conversation candidate before the metadata limit',
        $r['window']['metadata_rows']===1&&!$r['window']['metadata_limit_reached']
        &&$r['saved']['paired_snapshots']===1&&$r['saved']['distinct_conversation_scopes']===1);
    forecastStatusSchema($p);
    for($i=0;$i<25;$i++){
        $customer=(string)(100+$i);$conversation=(string)(200+$i);
        forecastStatusAppend($p,forecastStatusScope($initial,$customer,$conversation),'anytour',$customer,$conversation);
    }
    $r=forecastStatusRead($p);
    $check('distinct conversation candidate window remains bounded without claiming all history',
        $r['window']['metadata_rows']===20&&$r['window']['metadata_limit_reached']
        &&$r['saved']['paired_snapshots']===4&&$r['saved']['distinct_conversation_scopes']===4);

    forecastStatusSchema($p);
    $zero=forecastStatusPacket([['text'=>'Спасибо']]);
    $deferred=forecastStatusPacket([['text'=>'Подберите тур'],['text'=>'Пока отложим поездку']]);
    $paid=forecastStatusPacket([['text'=>'Подберите тур'],['role'=>'manager','text'=>'Оплату вижу']]);
    foreach([$update,$zero,$deferred,$paid]as$i=>$packet){
        $customer=(string)(7+$i);$conversation=(string)(9+$i);
        forecastStatusAppend($p,forecastStatusScope($packet,$customer,$conversation),'anytour',$customer,$conversation);
    }
    $empty=forecastStatusPacket([['role'=>'manager','text'=>'Служебное обновление']]);
    for($i=0;$i<35;$i++)forecastStatusAppend($p,$empty);
    $beforeRows=$p->query('SELECT * FROM modeled_conversion_profile_versions ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
    $r=forecastStatusRead($p);
    $check('frequent context-free updates cannot displace four older distinct customer conversations',
        $r['saved']['paired_snapshots']===4&&$r['saved']['distinct_conversation_scopes']===4
        &&$r['saved']['customer_context_snapshots']===4&&$r['window']['customer_context_candidates']===4);
    $check('context sampling retains zero-goal deferred and already-observed cases without positive-outcome filtering',
        $zero['contextual_signals']['goal_counts']===array_fill_keys(ArchiveResearchSemanticExtractor::goalIds(),0)
        &&$r['saved']['status_counts']['early_assessment']===3
        &&$r['saved']['status_counts']['target_already_observed']===1
        &&$r['saved']['early_assessments_with_signals']>=1);
    $check('diverse sampling preserves every saved fixture row',
        $beforeRows===$p->query('SELECT * FROM modeled_conversion_profile_versions ORDER BY id')->fetchAll(PDO::FETCH_ASSOC));

    forecastStatusSchema($p);
    forecastStatusAppend($p,$initial);
    forecastStatusAppend($p,forecastStatusScope($zero,'8','10'),'anytour','8','10');
    forecastStatusAppend($p,forecastStatusScope($empty,'99','99'),'anytour','99','99');
    $r=forecastStatusRead($p);
    $check('newer no-context fallback survives the metadata fence after older contextual priorities',
        $r['saved']['paired_snapshots']===3&&$r['saved']['distinct_conversation_scopes']===3
        &&$r['saved']['customer_context_snapshots']===2
        &&$r['window']['fallback_candidates']===1&&$r['saved']['status_counts']['no_usable_customer_context']===1
        &&$r['window']['changed_or_missing_rows']===0);
    forecastStatusSchema($p);$foreign=$initial;$foreign['subject']['project_key']='foreign';forecastStatusAppend($p,$foreign,'foreign');
    $r=forecastStatusRead($p);
    $check('other project rows are excluded before profile metadata and payload selection',
        $r['window']['metadata_rows']===0&&$r['window']['profile_rows_read']===0);
    forecastStatusSchema($p);forecastStatusAppend($p,$initial);
    $calls=0;$r=forecastStatusRead($p,static function()use(&$calls,$p,$update):void{if(++$calls===3)forecastStatusAppend($p,$update);});
    $check('a later append cannot enter either frozen goal selection or baseline window',
        $r['saved']['paired_snapshots']===1&&$r['saved']['snapshot_kind_counts']['initial_observation']===1
        &&!$r['stage_checks']['agency_purchase_intent']['candidate_found']
        &&(int)$p->query('SELECT COUNT(*) FROM modeled_conversion_profile_versions')->fetchColumn()===2);
    $calls=0;try{forecastStatusRead($p,static function()use(&$calls):void{if(++$calls===3)throw new DomainException('revoked');});$denied=false;}catch(DomainException $e){$denied=$e->getMessage()==='revoked';}
    $check('revocation before payload read returns no private result',$denied);
    $priorStatementClass=$p->getAttribute(PDO::ATTR_STATEMENT_CLASS);
    $p->setAttribute(PDO::ATTR_STATEMENT_CLASS,[ForecastStatusObservedStatement::class]);
    ForecastStatusObservedStatement::$payloadCopied=false;$denied=false;
    try{
        forecastStatusRead($p,static function():void{
            if(ForecastStatusObservedStatement::$payloadCopied)throw new DomainException('revoked-final');
        });
    }catch(DomainException $e){$denied=$e->getMessage()==='revoked-final';}
    finally{$p->setAttribute(PDO::ATTR_STATEMENT_CLASS,$priorStatementClass);}
    $check('authorization is checked again after payload copying before returning aggregates',
        $denied&&ForecastStatusObservedStatement::$payloadCopied);
    $p->beginTransaction();$r=forecastStatusRead($p);$check('caller transaction is refused without commit or rollback',$r['reason']==='outer_transaction'&&$p->inTransaction());$p->rollBack();

    $clock=100.0;$budget=new ModeledConversionStatusBudget(100.0,static function()use(&$clock):float{return $clock;});
    try{forecastStatusRead($p,static function()use(&$clock):void{$clock=106.0;},$budget);$limited=false;}
    catch(ModeledConversionStatusLimit $e){$limited=$e->reason==='time_limit';}
    $check('shared deadline includes authorization and prevents subsequent reads',$limited);
    $budget=new ModeledConversionStatusBudget();$budget->consumeBytes(62000);$calls=0;
    try{forecastStatusRead($p,static function()use(&$calls):void{$calls++;},$budget);$limited=false;}
    catch(ModeledConversionStatusLimit $e){$limited=$e->reason==='byte_limit';}
    $check('insufficient remaining bytes fail before selecting a receipt or profile',$limited&&$calls===0);

    forecastStatusSchema($p);
    $agency=forecastStatusScope($update,'70','90');
    $general=forecastStatusScope(forecastStatusPacket([['text'=>'Хочу купить тур оператора Anex']]),'71','91');
    $paid=forecastStatusScope(forecastStatusPacket([['text'=>'Подберите тур'],['text'=>'Давайте бронировать этот тур'],['role'=>'manager','text'=>'Оплату вижу']]),'70','90');
    $agency['contextual_signals']['extractor_sha256']=str_repeat('b',64);
    unset($paid['contextual_signals']['extractor_sha256']);
    forecastStatusAppend($p,$agency,'anytour','70','90');
    forecastStatusAppend($p,$general,'anytour','71','91');
    forecastStatusAppend($p,$paid,'anytour','70','90');
    for($i=0;$i<30;$i++){
        $customer=(string)(300+$i);$conversation=(string)(400+$i);
        forecastStatusAppend($p,forecastStatusScope($zero,$customer,$conversation),'anytour',$customer,$conversation);
    }
    $beforeStageRows=$p->query('SELECT * FROM modeled_conversion_profile_versions ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
    $r=forecastStatusRead($p);$stages=$r['stage_checks'];
    $check('older general agency and payment strata survive twenty newer context scopes',
        $stages['general_purchase_intent']['validated_saved_evidence']
        &&$stages['agency_purchase_intent']['validated_saved_evidence']
        &&$stages['payment_evidence']['validated_saved_evidence']
        &&$r['window']['metadata_rows']===20&&$r['window']['candidate_version_rows']===20
        &&$r['saved']['paired_snapshots']===4&&$r['saved']['distinct_conversation_scopes']===3);
    $check('readiness is verified at an early prefix separately from its later observed payment',
        $stages['general_purchase_intent']['early_assessment']===true
        &&$stages['agency_purchase_intent']['early_assessment']===true
        &&$stages['payment_evidence']['early_assessment']===false
        &&$stages['payment_evidence']['forecast_status']==='target_already_observed');
    $check('saved rule fingerprints explicitly separate current old and missing code provenance',
        $stages['general_purchase_intent']['rule_code_fingerprint']==='current'
        &&$stages['agency_purchase_intent']['rule_code_fingerprint']==='other'
        &&$stages['payment_evidence']['rule_code_fingerprint']==='unknown'
        &&$r['saved']['rule_code_fingerprint_counts']===['current'=>2,'other'=>1,'unknown'=>1]);
    $check('targeted diagnostics retain transfer limits privacy and read-only fixture invariance',
        $r['window']['profile_rows_read']<=4&&$r['window']['selected_document_bytes']<=49152
        &&$r['window']['selection']==='diagnostic_goal_strata_not_population_frequency'
        &&!str_contains(json_encode($r),str_repeat('b',64))
        &&!str_contains(json_encode($r),'Anex')
        &&$beforeStageRows===$p->query('SELECT * FROM modeled_conversion_profile_versions ORDER BY id')->fetchAll(PDO::FETCH_ASSOC));
    forecastStatusSchema($p);forecastStatusAppend($p,$update);$r=forecastStatusRead($p);
    $check('one stored packet proves both readiness strata without duplicate copying',
        $r['stage_checks']['general_purchase_intent']['validated_saved_evidence']
        &&$r['stage_checks']['agency_purchase_intent']['validated_saved_evidence']
        &&$r['window']['metadata_rows']===2&&$r['window']['candidate_version_rows']===1
        &&$r['window']['duplicate_candidate_rows']===1&&$r['window']['profile_rows_read']===1);
    forecastStatusSchema($p);
    foreach([
        [['text'=>'Подберите тур'],['role'=>'manager','text'=>'Ссылка на оплату готова']],
        [['text'=>'Подберите тур'],['text'=>'Как оплатить картой?']],
        [['text'=>'Подберите тур'],['role'=>'manager','text'=>'Тур подтвержден']],
        [['text'=>'Подберите тур'],['role'=>'manager','text'=>'Документы готовы']],
        [['text'=>'Подберите тур'],['text'=>'Сегодня оплачу']]
    ]as$i=>$messages){
        $packet=forecastStatusScope(forecastStatusPacket($messages),(string)(700+$i),(string)(800+$i));
        forecastStatusAppend($p,$packet,'anytour',(string)(700+$i),(string)(800+$i));
    }
    $r=forecastStatusRead($p);
    $check('payment links terms documents booking and promises do not nominate a paid stage',
        !$r['stage_checks']['payment_evidence']['candidate_found']);
    foreach(['payment_self_report','manager_payment_ack','purchase_supported_candidate']as$goal){
        forecastStatusSchema($p);$packet=$initial;$packet['contextual_signals']['goal_counts'][$goal]='1';
        forecastStatusAppend($p,$packet);$r=forecastStatusRead($p);
        $check('numeric string cannot nominate payment '.$goal,!$r['stage_checks']['payment_evidence']['candidate_found']
            &&$r['saved']['paired_snapshots']===0);
    }
    forecastStatusSchema($p);$packet=$update;$packet['contextual_signals']['goal_counts']['agency_purchase_intent']++;
    forecastStatusAppend($p,$packet);$r=forecastStatusRead($p);
    $check('goal metadata candidate cannot substitute for validated matching event evidence',
        $r['stage_checks']['agency_purchase_intent']['candidate_found']
        &&!$r['stage_checks']['agency_purchase_intent']['validated_saved_evidence']
        &&$r['stage_checks']['agency_purchase_intent']['verification']==='candidate_invalid_or_unsupported');
    $p->exec('DROP TABLE modeled_conversion_profile_versions');$r=forecastStatusRead($p);
    $check('missing derived storage is fixed unavailable rather than an invented zero',$r['available']===false&&$r['reason']==='storage_unavailable'&&$r['saved']===null);
}

if(realpath($_SERVER['SCRIPT_FILENAME']??'')===__FILE__){
    $p=new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
    forecastStatusSchema($p);$passed=0;
    $check=static function(string $name,bool $ok)use(&$passed):void{if(!$ok)throw new RuntimeException('FAIL '.$name);$passed++;echo 'PASS '.$name.PHP_EOL;};
    forecastStatusAssertions($p,$check);echo 'MODELED CONVERSION STATUS PASSED '.$passed.PHP_EOL;
}
