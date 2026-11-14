<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/services/ModeledConversionDailyReconciliationReader.php';

/** Disposable synthetic database only. Call last after the other fixture state checks. */
function modeledBacklogAssertions(PDO $p,callable $check):void
{
    foreach(['manager_prepared_replies','max_channel_conversion_outbox','conversations','customer_channels','customers']as$table)
        $p->exec('DROP TABLE IF EXISTS '.$table);
    $p->exec('CREATE TABLE customers(id BIGINT PRIMARY KEY)');
    $p->exec('CREATE TABLE customer_channels(id BIGINT PRIMARY KEY,customer_id BIGINT,project_key VARCHAR(40),channel VARCHAR(16),external_user_id VARCHAR(40),external_chat_id VARCHAR(40))');
    $p->exec('CREATE TABLE conversations(id BIGINT PRIMARY KEY,customer_id BIGINT,customer_channel_id BIGINT,project_key VARCHAR(40),channel VARCHAR(16),external_chat_id VARCHAR(40),is_test INT)');
    $p->exec('CREATE TABLE max_channel_conversion_outbox(id BIGINT PRIMARY KEY,project_key VARCHAR(40),platform VARCHAR(16),conversation_id BIGINT,external_user_id VARCHAR(40),counter_id VARCHAR(20),transport_target VARCHAR(64),value_contract VARCHAR(64),state VARCHAR(24),client_id VARCHAR(40),value_rub DECIMAL(12,2),event_unix BIGINT,next_attempt_unix BIGINT,value_ready_at_unix BIGINT,last_error VARCHAR(64),attempts INT,claim_token VARCHAR(64),claimed_at_unix BIGINT,upload_id VARCHAR(40),upload_status VARCHAR(32),value_receipt_basis VARCHAR(32),value_api_accepted_at_unix BIGINT,value_processed_at_unix BIGINT,tracker_observed_unix BIGINT)');
    foreach([111,333,444,555,666,2]as$id)$p->exec('INSERT INTO customers VALUES('.$id.')');
    $p->exec("INSERT INTO customer_channels VALUES
        (111,111,'anytour','max','111','-111'),(333,333,'anytour','telegram','333','333'),
        (444,999,'anytour','max','444','-444'),(555,555,'anytour','max','555','-555'),
        (666,666,'anytour','max','666','-666'),(2,2,'anytour','max','2','-2')");
    $p->exec("INSERT INTO conversations VALUES
        (111,111,111,'anytour','max','-111',0),(333,333,333,'anytour','telegram','333',0),
        (444,444,444,'anytour','max','-444',0),(555,555,555,'anytour','max','-555',1),
        (666,666,666,'anytour','max','wrong-current-chat',0),(2,2,2,'anytour','max','-2',0)");
    $now=1741089600; // Fixed synthetic clock, unrelated to live delivery.
    $columns=['id','project_key','platform','conversation_id','external_user_id','counter_id','transport_target','value_contract',
        'state','client_id','value_rub','event_unix','next_attempt_unix','value_ready_at_unix','last_error','attempts',
        'claim_token','claimed_at_unix','upload_id','upload_status','value_receipt_basis','value_api_accepted_at_unix',
        'value_processed_at_unix','tracker_observed_unix'];
    $insert=$p->prepare('INSERT INTO max_channel_conversion_outbox('.implode(',',$columns).') VALUES('.implode(',',array_fill(0,count($columns),'?')).')');
    $base=['id'=>1,'project_key'=>'anytour','platform'=>'max','conversation_id'=>111,'external_user_id'=>'111',
        'counter_id'=>'98615635','transport_target'=>'anytour_modeled_value_v1','value_contract'=>ModeledConversionValueContract::VERSION,
        'state'=>'ready','client_id'=>'880000001','value_rub'=>'5.25','event_unix'=>$now-1800,
        'next_attempt_unix'=>0,'value_ready_at_unix'=>$now-600,'last_error'=>'','attempts'=>0,
        'claim_token'=>null,'claimed_at_unix'=>null,'upload_id'=>null,'upload_status'=>null,'value_receipt_basis'=>null,
        'value_api_accepted_at_unix'=>null,'value_processed_at_unix'=>null,'tracker_observed_unix'=>$now-2000];
    $append=static function(array $row)use($insert,$columns,$base):void{
        $row=array_replace($base,$row);$insert->execute(array_map(static fn(string $key):mixed=>$row[$key],$columns));
    };
    $p->beginTransaction();
    for($i=1;$i<=250;$i++)$append(['id'=>$i,'value_rub'=>$i%2===0?'10.10':'5.25',
        'next_attempt_unix'=>$i<=100?$now-1200:($i<=200?$now+600:0),
        'tracker_observed_unix'=>$i<=20?$now-864001:$now-2000,
        'last_error'=>$i<=75?'metrika_visit_unverified':($i<=150?'metrika_visit_window':($i<=175?'metrika_no_prior_visit':''))]);
    for($i=251;$i<=255;$i++)$append(['id'=>$i,'platform'=>'telegram','conversation_id'=>333,'external_user_id'=>'333',
        'state'=>'pending','client_id'=>null,'value_rub'=>'7.77','next_attempt_unix'=>$now-300,'value_ready_at_unix'=>null]);
    $append(['id'=>256,'state'=>'accepted','client_id'=>'880000002','value_rub'=>'12.34','upload_id'=>'220000001',
        'upload_status'=>'UPLOADED','attempts'=>1,'claimed_at_unix'=>$now-100,'value_receipt_basis'=>'post_response',
        'value_api_accepted_at_unix'=>$now-90]);
    $wrong=[
        ['project_key'=>'AnyTour'],['counter_id'=>'98615636'],['transport_target'=>'unrelated_goal'],
        ['value_contract'=>'other_contract'],['platform'=>'Max'],['conversation_id'=>444,'external_user_id'=>'444'],
        ['conversation_id'=>555,'external_user_id'=>'555'],['conversation_id'=>666,'external_user_id'=>'666'],
        ['conversation_id'=>2,'external_user_id'=>'2'],['external_user_id'=>'888'],
        ['state'=>'failed','value_rub'=>'0.00','last_error'=>'price_loss_reconciled'],
    ];
    foreach($wrong as$i=>$row)$append(['id'=>30000+$i]+$row);
    $p->commit();
    $snapshot=static function()use($p):string{
        $rows=[];foreach(['max_channel_conversion_outbox','conversations','customer_channels','customers']as$table)
            $rows[$table]=$p->query('SELECT * FROM '.$table.' ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
        return hash('sha256',json_encode($rows,JSON_THROW_ON_ERROR));
    };
    $before=$snapshot();$reader=new ModeledConversionDailyReconciliationReader($p,static function():void{});
    $r=$reader->readBacklog($now);
    $check('backlog covers every exact-owner row beyond the old 201 suffix',
        ($r['available']??false)&&($r['coverage']['inspected_rows']??null)===256
        &&($r['coverage']['complete_within_bound']??false)===true
        &&($r['total']['events']??null)===256&&($r['total']['conversations']??null)===2
        &&($r['total']['value_rub']??null)===1969.94&&($r['total']['stored_client_ids']??null)===2
        &&($r['by_platform']['max']['events']??null)===251
        &&($r['by_platform']['max']['value_rub']??null)===1931.09
        &&($r['by_platform']['telegram']['events']??null)===5
        &&($r['by_platform']['telegram']['value_rub']??null)===38.85);
    $q=$r['total']['queue']??[];
    $check('backlog separates due future and exact ten-minute queue age boundaries',
        ($q['waiting']['events']??null)===255&&($q['due']['events']??null)===155
        &&($q['held_until_later']['events']??null)===100
        &&($q['overdue_at_least_600_seconds']['events']??null)===150
        &&($q['oldest_due_wait_seconds']??null)===1200
        &&($q['ready_never_claimed']['events']??null)===250
        &&($q['ready_max_source_old_or_missing']??null)===20
        &&($q['saved_hold_reason_counts']['metrika_visit_unverified']??null)===75
        &&($q['saved_hold_reason_counts']['metrika_visit_window']??null)===75
        &&($q['saved_hold_reason_counts']['metrika_no_prior_visit']??null)===25);
    $check('backlog state dialogue counts retain overlap and no stored identifier escapes',
        ($r['by_state']['ready']['conversations']??null)===1
        &&($r['by_state']['accepted']['conversations']??null)===1
        &&($r['by_state']['pending']['conversations']??null)===1
        &&!str_contains(json_encode($r,JSON_THROW_ON_ERROR),'880000001')
        &&!str_contains(json_encode($r,JSON_THROW_ON_ERROR),'220000001')
        &&($r['interpretation']['sender_rechecks_current_eligibility']??false)
        &&($r['interpretation']['no_ids_text_provider_requests_or_writes']??false)
        &&$snapshot()===$before&&!$p->inTransaction());
    $p->beginTransaction();$outer=$reader->readBacklog($now);
    $check('backlog refuses to adopt an existing transaction without rolling it back',
        ($outer['reason']??null)==='outer_transaction'&&$p->inTransaction());$p->rollBack();
    $calls=0;$denied=false;
    try{(new ModeledConversionDailyReconciliationReader($p,static function()use(&$calls):void{
        if(++$calls===3)throw new DomainException('synthetic_access_revoked');
    }))->readBacklog($now);}catch(DomainException $expected){$denied=true;}
    $check('backlog reauthorizes after copying and rolls back on revocation',
        $denied&&$calls===3&&!$p->inTransaction()&&$snapshot()===$before);
    $budget=new ModeledConversionStatusBudget();
    $budgeted=(new ModeledConversionDailyReconciliationReader($p,static function():void{},null,$budget))->readBacklog($now);
    $check('backlog copies every positional scalar aggregate within eight KiB of the shared budget',
        ($budgeted['available']??false)&&$budget->remainingBytes()<ModeledConversionStatusBudget::MAX_BYTES
        &&ModeledConversionStatusBudget::MAX_BYTES-$budget->remainingBytes()<8192
        &&strlen(json_encode($budgeted,JSON_THROW_ON_ERROR))<10000);
    // Reproduce an optional read late in the real shared64KiB envelope. The
    // synthetic authorizer enforces the same4KiB admission floor on every recheck.
    $pressureRead=static function(int $visible)use($p,$now):array{
        $pressure=new ModeledConversionStatusBudget();
        $pressure->consumeBytes(ModeledConversionStatusBudget::MAX_BYTES-8192-4096-$visible);
        return$pressure->withReservedBytes(8192,static function()use($pressure,$p,$now):array{
            return$pressure->withReservedBytes(4096,static function()use($pressure,$p,$now):array{
                $calls=0;$authorize=static function()use($pressure,&$calls):void{
                    if($pressure->remainingBytes()<4096)throw new ModeledConversionStatusLimit('byte_limit');
                    $pressure->consumeBytes(512);++$calls;
                };
                $entry=$pressure->remainingBytes();
                $result=(new ModeledConversionDailyReconciliationReader($p,$authorize,null,$pressure))->readBacklog($now);
                $remaining=$pressure->remainingBytes();$copied=$entry-$remaining-$calls*512;
                // The caller's final permission check also remains mandatory.
                $authorize();
                return['result'=>$result,'remaining'=>$pressure->remainingBytes(),'copied'=>$copied,'auth_calls'=>$calls];
            });
        });
    };
    $tight=$pressureRead(8192);$partial=$pressureRead(12288);
    $check('backlog low-headroom refusal preserves the final authorization floor before any aggregate fetch',
        ($tight['result']['available']??true)===false&&($tight['result']['reason']??null)==='budget_headroom'
        &&$tight['remaining']>=4096&&$tight['copied']===0&&$tight['auth_calls']>=3
        &&!$p->inTransaction()&&$snapshot()===$before);
    $check('backlog stops before another copied bucket and retains charges for the rows already fetched',
        ($partial['result']['available']??true)===false&&($partial['result']['reason']??null)==='budget_headroom'
        &&$partial['remaining']>=4096&&$partial['copied']>0&&$partial['auth_calls']>=4
        &&!$p->inTransaction()&&$snapshot()===$before);
    $p->exec("UPDATE max_channel_conversion_outbox SET next_attempt_unix=-1 WHERE id=1");
    $invalid=$reader->readBacklog($now);
    $check('backlog rejects corrupt pending-ready clocks rather than hiding a due row',
        ($invalid['available']??true)===false&&($invalid['reason']??null)==='invalid_aggregate'&&!$p->inTransaction());
    $p->prepare('UPDATE max_channel_conversion_outbox SET next_attempt_unix=? WHERE id=1')->execute([$now-1200]);
    $check('backlog invalid aggregate read did not change any row',$snapshot()===$before);
    $p->beginTransaction();
    for($i=0;$i<9744;$i++)$append(['id'=>40000+$i,'value_rub'=>'1.01','next_attempt_unix'=>$now+3600]);
    $p->commit();
    $exact=$reader->readBacklog($now);
    $check('backlog exact ten-thousand bound remains complete',
        ($exact['available']??false)&&($exact['coverage']['inspected_rows']??null)===10000
        &&($exact['coverage']['complete_within_bound']??false)===true);
    $append(['id'=>50000]);$append(['id'=>50001]);
    $overflow=$reader->readBacklog($now);
    $check('backlog one-row sentinel explicitly marks overflow and never calls its suffix a total population',
        ($overflow['available']??false)&&($overflow['coverage']['inspected_rows']??null)===10001
        &&($overflow['coverage']['complete_within_bound']??true)===false
        &&($overflow['interpretation']['incomplete_bound_is_not_the_whole_backlog']??false));
    modeledBacklogOperationalGrowthAssertions($p,$check);
}


/** Observe copied rows as well as execution order; copying an unused receipt still costs the shared budget. */
final class ModeledBacklogOperationalStatement extends PDOStatement
{
    public static array $queries=[];
    public static array $settingsCopies=[];
    protected function __construct(){}
    public function execute(?array $params=null):bool
    {
        if(preg_match('/\ASELECT\s/i',$this->queryString)!==1)
            throw new RuntimeException('fixture_operational_status_must_select_only');
        self::$queries[]=$this->queryString;
        return parent::execute($params);
    }
    public function fetchAll(int $mode=PDO::FETCH_DEFAULT,mixed ...$args):array
    {
        $rows=parent::fetchAll($mode,...$args);
        if(str_contains($this->queryString,'FROM modeled_conversion_value_export_settings'))
            self::$settingsCopies[]=$rows;
        return $rows;
    }
}

/** Full portable disposable schema: modeledValueSchema deliberately refuses the native OAuth database name. */
function modeledBacklogOperationalGrowthAssertions(PDO $p,callable $check):void
{
    require_once __DIR__.'/run_modeled_conversion_value_export_regression.php';
    require_once dirname(__DIR__).'/services/ModeledConversionForecastStatusReader.php';
    require_once dirname(__DIR__).'/services/ModeledConversionPredictionFollowup.php';
    $mysql=$p->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql';
    if($mysql){
        $database=(string)$p->query('SELECT DATABASE()')->fetchColumn();
        if($database!=='maxbot_modeled_stream_test'
            &&preg_match('/\Amaxbot_forecast_auth_test_[a-f0-9]{12}\z/D',$database)!==1)
            throw new RuntimeException('unsafe_fixture_database');
    }
    $tables=['modeled_conversion_price_audit','modeled_conversion_value_export_settings',
        'max_channel_conversion_outbox','modeled_conversion_profile_versions','modeled_conversion_profiles_state',
        'modeled_conversion_stream_versions','advertising_visit_observations','conversations','customer_channels','customers'];
    foreach($tables as$table)$p->exec('DROP TABLE IF EXISTS '.$table);
    $auto=$mysql?'BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY':'INTEGER PRIMARY KEY AUTOINCREMENT';
    $json=$mysql?'JSON':'TEXT';
    $profileProject=$mysql?'project_key VARCHAR(40) CHARACTER SET ascii COLLATE ascii_bin':'project_key VARCHAR(40)';
    $p->exec('CREATE TABLE customers(id BIGINT PRIMARY KEY,display_name VARCHAR(191))');
    $p->exec('CREATE TABLE customer_channels(id BIGINT PRIMARY KEY,customer_id BIGINT,project_key VARCHAR(64),channel VARCHAR(16),external_user_id VARCHAR(20),external_chat_id VARCHAR(32))');
    $p->exec('CREATE TABLE conversations(id BIGINT PRIMARY KEY,customer_id BIGINT,customer_channel_id BIGINT,project_key VARCHAR(64),channel VARCHAR(16),is_test INT,external_chat_id VARCHAR(32))');
    $p->exec("CREATE TABLE modeled_conversion_profile_versions(id $auto,$profileProject,platform VARCHAR(16),customer_key VARCHAR(20),conversation_id BIGINT,journal_cursor BIGINT,observed_at_unix BIGINT,input_sha256 CHAR(64),assessment_namespace CHAR(64),assessment_kind VARCHAR(8),profile_json $json)");
    $p->exec('CREATE TABLE modeled_conversion_profiles_state(project_key VARCHAR(40) PRIMARY KEY,enabled INT,last_receipt_json TEXT,last_error_at_unix BIGINT,last_error_category VARCHAR(40))');
    $p->exec("CREATE TABLE modeled_conversion_stream_versions(id BIGINT PRIMARY KEY,project_key VARCHAR(40),source_kind VARCHAR(16),source_id BIGINT,version_no INT,observed_at_unix BIGINT,snapshot_json $json)");
    $p->exec("CREATE TABLE advertising_visit_observations(id $auto,project_key VARCHAR(64),source_key VARCHAR(80),platform VARCHAR(16),counter_id VARCHAR(20),external_user_id VARCHAR(20),identity_status VARCHAR(20),client_id VARCHAR(40),visit_at_unix BIGINT,observed_at_unix BIGINT,received_at_unix BIGINT)");
    // Every field selected by the real operational ValueDelivery reader is present.
    // Keep unused historical/source documents large enough to detect accidental whole-document copies.
    $p->exec("CREATE TABLE modeled_conversion_value_export_settings(project_key VARCHAR(40) PRIMARY KEY,counter_id VARCHAR(20),mode VARCHAR(16),goal_id VARCHAR(20),goal_intent VARCHAR(20),activated_at_unix BIGINT,profile_cursor BIGINT,base_value_rub BIGINT,contract_version VARCHAR(64),last_tick_unix BIGINT,last_receipt_json $json,history_from_unix BIGINT,history_until_unix BIGINT,history_cursor BIGINT,history_ceiling BIGINT,history_completed_at_unix BIGINT,history_scanned BIGINT,history_collected BIGINT,history_stats_json $json,history_fortnight_requested_at_unix BIGINT,history_day_receipt_json $json,last_report_success_unix BIGINT,last_report_success_json $json,report_failure_since_unix BIGINT)");
    $p->exec("CREATE TABLE max_channel_conversion_outbox(id $auto,project_key VARCHAR(40),conversation_id BIGINT,external_user_id VARCHAR(20),counter_id VARCHAR(20),target VARCHAR(100),transport_target VARCHAR(64),event_unix BIGINT,client_id VARCHAR(40),tracker_record_id VARCHAR(40),tracker_observed_unix BIGINT,state VARCHAR(24),next_attempt_unix BIGINT,claim_token VARCHAR(64),claimed_at_unix BIGINT,attempts INT,upload_id VARCHAR(40),upload_status VARCHAR(32),last_error VARCHAR(64),platform VARCHAR(16),identity_source_key VARCHAR(80),identity_observation_id BIGINT,value_rub DECIMAL(12,2),value_profile_id BIGINT,value_stage VARCHAR(40),value_contract VARCHAR(64),value_origin VARCHAR(32),value_reviewed_at_unix BIGINT,value_ready_at_unix BIGINT,value_api_accepted_at_unix BIGINT,value_processed_at_unix BIGINT,value_receipt_basis VARCHAR(32),created_at DATETIME,UNIQUE(project_key,conversation_id,target))");
    $p->exec('CREATE TABLE modeled_conversion_price_audit(outbox_id BIGINT PRIMARY KEY,action VARCHAR(24),original_value_rub BIGINT,applied_value_rub BIGINT,prior_reserved_rub BIGINT,assessed_at_unix BIGINT,basis_json TEXT,basis_sha256 CHAR(64))');
    $now=1741089600; // Fixed synthetic noon; no moving production population or monetary sample.
    $flags=JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR;
    $marker='PRIVATE_GROWTH_FRAME_MUST_NOT_BE_COPIED';
    $frame=['private_marker'=>$marker,'padding'=>str_repeat('h',7200)];
    $receipt=['errors'=>0,'finite_visit_evidence'=>$frame];
    $receiptJson=json_encode($receipt,$flags);$frameJson=json_encode($frame,$flags);
    $p->prepare("INSERT INTO modeled_conversion_value_export_settings(project_key,counter_id,mode,goal_id,goal_intent,activated_at_unix,profile_cursor,base_value_rub,contract_version,last_tick_unix,last_receipt_json,history_stats_json,history_day_receipt_json) VALUES('anytour','98615635','send','678187097','verified',?,0,25000,?,?,?,?,?)")
        ->execute([$now-3600,ModeledConversionValueContract::VERSION,$now,$receiptJson,$frameJson,$frameJson]);
    $state=json_encode(['status'=>'profiled','observed_at_unix'=>$now-60,'profiles_written'=>8],$flags);
    $p->prepare("INSERT INTO modeled_conversion_profiles_state VALUES('anytour',1,?,NULL,NULL)")->execute([$state]);
    $profileInsert=$p->prepare('INSERT INTO modeled_conversion_profile_versions(project_key,platform,customer_key,conversation_id,journal_cursor,observed_at_unix,input_sha256,assessment_namespace,assessment_kind,profile_json) VALUES(?,?,?,?,?,?,?,?,?,?)');
    $messageInsert=$p->prepare("INSERT INTO modeled_conversion_stream_versions VALUES(?,'anytour','message',?,1,?,?)");
    $valueInsert=$p->prepare("INSERT INTO max_channel_conversion_outbox(project_key,conversation_id,external_user_id,counter_id,target,transport_target,event_unix,platform,value_rub,value_profile_id,value_stage,value_contract,state,client_id,tracker_observed_unix,next_attempt_unix,value_ready_at_unix,last_error,attempts,created_at) VALUES('anytour',?,?,'98615635',?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
    $validCapsules=0;$p->beginTransaction();
    for($scopeIndex=1;$scopeIndex<=32;$scopeIndex++){
        $id=81000+$scopeIndex;$platform=$scopeIndex%2===0?'max':'telegram';
        $scope=modeledValueBinding($p,$id,$platform);
        $p->prepare('INSERT INTO customers VALUES(?,?)')->execute([$id,'Synthetic growth customer']);
        $persisted=$now-1200+$scopeIndex;$captured=$persisted+1;
        $snapshot=$scope+['source_status'=>'present','source_created_at_unix'=>$persisted,
            'payload'=>['direction'=>'inbound','sender_type'=>'customer','text'=>'PRIVATE_GROWTH_MESSAGE_TEXT']];
        $messageInsert->execute([$scopeIndex,$scopeIndex,$captured,json_encode($snapshot,$flags)]);
        for($version=0;$version<20;$version++){
            $saved=$captured+2+$version;
            $wrapper=modeledExpertWrapper($scope,$saved,180000);
            $prediction=$wrapper['semantic_prediction'];
            $cursor=(string)$prediction['journal_cursor'];
            $wrapper['processing']['journal_cursor']=$cursor;
            $wrapper['processing']['source_fence_journal_id']=$prediction['source_fence_journal_id'];
            $wrapper['prediction_followup']=ModeledConversionPredictionFollowup::compare($prediction,null);
            $wrapper['early_forecast']=['status'=>'early_assessment'];
            $wrapper['contextual_signals']['engagement']=['customer_turns'=>1,'manager_turns'=>0];
            $counts=array_count_values(array_column($wrapper['contextual_signals']['events'],'goal'));
            foreach(['substantive_interest','general_purchase_intent','agency_purchase_intent']as$goal)
                $wrapper['contextual_signals']['goal_counts'][$goal]=$counts[$goal]??0;
            $wrapper['contextual_signals']['coverage']['context_linking_disabled']=false;
            $wrapper['contextual_signals']['coverage']['prediction_source_continuity']=array_fill_keys([
                'preserved_ai_replies','preserved_handoff_receipts','unresolved_clock_barriers','long_gap_barriers',
                'unavailable_message_barriers','unavailable_event_barriers','other_source_barriers'],0);
            $wrapper['coverage']=['applied_wizard_fields'=>0,'errors'=>['action_or_unknown_message_type'=>0]];
            $wrapper['private_fixture_marker']='PRIVATE_GROWTH_IMMUTABLE_PROFILE';
            $body=json_encode($wrapper,$flags);
            if(ModeledConversionSemanticPrediction::digestMatches($prediction)
                &&($prediction['status']??null)==='predicted'&&is_string($prediction['stage']??null))$validCapsules++;
            $profileInsert->execute(['anytour',$platform,(string)$id,$id,$cursor,$saved,hash('sha256',$body),
                str_repeat('0',64),'legacy',$body]);
            $profile=(int)$p->lastInsertId();
            // Twenty immutable versions per exact native binding exceed the 201-row suffix.
            // Alternating saved queue states retain a full historical backlog and both platforms.
            $ready=$version%2===0;
            $valueInsert->execute([$id,(string)(70000+$id),ModeledConversionValueContract::predictionKey((string)$profile),
                ModeledConversionValueContract::TARGET,$saved+1,$platform,'10.00',$profile,
                ModeledConversionValueContract::STAGES[2],ModeledConversionValueContract::VERSION,
                $ready?'ready':'pending',$ready?'880000'.(70000+$id):null,$ready?$captured:null,
                $now-600,$ready?$saved+1:null,$ready?'metrika_visit_unverified':'identity_not_found',0,
                gmdate('Y-m-d H:i:s',$saved+1)]);
        }
    }
    $p->commit();
    $check('composed growth fixture has all 640 valid immutable predictions and large unused saved frames',
        $validCapsules===640&&strlen($receiptJson)>=7000&&strlen($receiptJson)<=8192&&strlen($frameJson)>=7000);
    $digest=static function()use($p,$tables,$flags):string{
        $hash=hash_init('sha256');
        foreach($tables as$table){
            $order=in_array($table,['modeled_conversion_value_export_settings','modeled_conversion_profiles_state'],true)?'project_key':
                ($table==='modeled_conversion_price_audit'?'outbox_id':'id');
            hash_update($hash,$table.json_encode($p->query('SELECT * FROM '.$table.' ORDER BY '.$order)->fetchAll(PDO::FETCH_ASSOC),$flags));
        }
        return hash_final($hash);
    };
    $before=$digest();$oldClass=$p->getAttribute(PDO::ATTR_STATEMENT_CLASS);
    $oldReadOnly=$mysql?(int)$p->query('SELECT @@session.transaction_read_only')->fetchColumn()
        :(int)$p->query('PRAGMA query_only')->fetchColumn();
    $oldZone=$mysql?(string)$p->query('SELECT @@session.time_zone')->fetchColumn():null;
    $budget=new ModeledConversionStatusBudget();$authCalls=0;$lowestAuthHeadroom=65536;
    $authorize=static function()use($budget,&$authCalls,&$lowestAuthHeadroom):void{
        $headroom=$budget->remainingBytes();$lowestAuthHeadroom=min($lowestAuthHeadroom,$headroom);
        if($headroom<4096)throw new ModeledConversionStatusLimit('byte_limit');
        $budget->consumeBytes(512);$authCalls++;
    };
    $wire=new ModeledValueTestWire($now-60);
    ModeledBacklogOperationalStatement::$queries=[];ModeledBacklogOperationalStatement::$settingsCopies=[];
    try{
        if($mysql){$p->exec("SET time_zone='+00:00'");$p->exec('SET SESSION TRANSACTION READ ONLY');}
        else$p->exec('PRAGMA query_only=ON');
        $p->setAttribute(PDO::ATTR_STATEMENT_CLASS,[ModeledBacklogOperationalStatement::class]);
        $data=(new ModeledConversionForecastStatusReader($p,$authorize,$budget))->readOperational($now);
        $authorize(); // Mandatory caller/result authorization after the complete composed read.
        $remaining=$budget->remainingBytes();$remainingMs=$budget->remainingMilliseconds();
        $queries=ModeledBacklogOperationalStatement::$queries;$copies=ModeledBacklogOperationalStatement::$settingsCopies;
    }finally{
        $p->setAttribute(PDO::ATTR_STATEMENT_CLASS,$oldClass);
        if($p->inTransaction())$p->rollBack();
        if($mysql){$p->exec('SET SESSION TRANSACTION '.($oldReadOnly?'READ ONLY':'READ WRITE'));$p->exec('SET time_zone='.$p->quote($oldZone));}
        else$p->exec('PRAGMA query_only='.($oldReadOnly?'ON':'OFF'));
    }
    $day=$data['daily_reconciliation']??[];$backlog=$data['backlog']??[];$value=$data['value_delivery']??[];
    $check('composed 32-scope status retains the complete 640-row day and full backlog within the unchanged shared budget',
        ($data['available']??false)&&($day['available']??false)&&($backlog['available']??false)
        &&($day['calendar']['accruals']['events']??null)===640
        &&($day['calendar']['accruals']['conversations']??null)===32
        &&($day['calendar']['accruals']['value_rub']??null)===6400
        &&($day['calendar']['accruals']['by_platform']['max']['events']??null)===320
        &&($day['calendar']['accruals']['by_platform']['telegram']['events']??null)===320
        &&($backlog['coverage']['inspected_rows']??null)===640
        &&($backlog['coverage']['complete_within_bound']??false)===true
        &&($backlog['total']['events']??null)===640&&($backlog['total']['conversations']??null)===32
        &&($backlog['total']['value_rub']??null)===6400
        &&($backlog['total']['queue']['waiting']['events']??null)===640
        &&($backlog['total']['queue']['due']['events']??null)===640
        &&($backlog['total']['queue']['ready_never_claimed']['events']??null)===320
        &&($backlog['by_platform']['max']['events']??null)===320
        &&($backlog['by_platform']['telegram']['events']??null)===320
        &&($backlog['by_state']['ready']['events']??null)===320
        &&($backlog['by_state']['pending']['events']??null)===320);
    $check('real operational ValueDelivery remains available and labels its 201-row suffix independently of the complete backlog',
        ($value['available']??false)&&($value['window']['inspected_rows']??null)===201
        &&($value['window']['complete_within_bound']??true)===false
        &&($value['reserved_value_rub']??null)===2010.0
        &&($value['conversations_in_ledger_window']??null)===11
        &&($value['by_state']['pending']['events']??null)===101
        &&($value['by_state']['ready']['events']??null)===100);
    $backlogAt=null;$latencyAt=null;
    foreach($queries as$index=>$sql){
        if(str_contains($sql,' AS packet')&&str_contains($sql,'GROUP BY b.bucket,b.category'))$backlogAt=$index;
        if($latencyAt===null&&str_contains($sql,'modeled_conversion_stream_versions'))$latencyAt=$index;
    }
    $timing=$data['today_latency']??[];
    $timingKnown=($timing['available']??false)
        ?($timing['messages']['conversations']??null)===32&&($timing['profile_sample']['scopes_inspected']??null)===32
            &&($timing['profile_sample']['recorded_prediction_status']['predicted']??null)===32
        :in_array($timing['reason']??null,['budget_headroom','time_headroom','response_budget'],true);
    $core=$data['forecast_core']??[];
    $coreKnown=($core['available']??false)
        ?($data['saved']['valid_v1_versions']??null)===1&&($data['input_coverage']['validated_profiles']??null)===1
        :in_array($core['reason']??null,['budget_headroom','time_headroom','response_budget'],true);
    $check('complete financial backlog executes before any 32-scope optional timing copy and optional omission stays explicit',
        $backlogAt!==null&&($latencyAt===null||$backlogAt<$latencyAt)&&$timingKnown&&$coreKnown);
    $copiedSettings=json_encode($copies,$flags);$result=json_encode($data,$flags);
    $check('unused seven-KiB history and source frames never enter the operational settings copy or response',
        count($copies)===1&&strlen($copiedSettings)<2048
        &&!str_contains($copiedSettings,$marker)&&!str_contains($result,'PRIVATE_GROWTH_'));
    $check('grown composed status stays read-only with no provider/model/upload work and preserves final authorization headroom',
        $authCalls>=10&&$lowestAuthHeadroom>=4096&&$remaining>=4096&&$remaining<65536&&$remainingMs>0
        &&($data['limits']['max_selected_bytes']??null)===65536
        &&($data['limits']['max_execution_seconds']??null)===5&&strlen($result)<=28000
        &&($data['provider_requests_in_reader']??null)===0&&($data['model_calls']??null)===0
        &&($data['conversion_uploads']??null)===0
        &&$wire->creates===0&&$wire->posts===0&&$wire->gets===0&&$wire->visitReads===0
        &&$digest()===$before&&!$p->inTransaction());
}
