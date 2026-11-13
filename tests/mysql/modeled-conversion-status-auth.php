<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once dirname(__DIR__,2).'/services/ManagerPreparationMcpService.php';
require_once dirname(__DIR__).'/run_modeled_conversion_status_regression.php';
require_once dirname(__DIR__).'/run_modeled_conversion_forecast_status_regression.php';

/** Real migrations and real OAuth/dispatcher/reader; only a fresh disposable database. */
function forecastNativeAuthGrant(ManagerPreparationOAuth $oauth,string $client,string $scope,string $resource=ManagerPreparationOAuth::RESOURCE):array
{
    $session=['csrf'=>'native-forecast-fixture'];$verifier=str_repeat('a',43);
    $request=['client_id'=>$client,'redirect_uri'=>ManagerPreparationOAuth::REDIRECT_URI,
        'response_type'=>'code','resource'=>$resource,'scope'=>$scope,'state'=>'native-fixture',
        'code_challenge_method'=>'S256',
        'code_challenge'=>rtrim(strtr(base64_encode(hash('sha256',$verifier,true)),'+/','-_'),'=')];
    $pending=$oauth->beginAuthorization($session,$request);
    $oauth->pendingAuthorization($session,$pending,1);
    $redirect=$oauth->completeAuthorization($session,$pending,1,$session['csrf'],true);
    parse_str((string)parse_url($redirect,PHP_URL_QUERY),$query);
    $token=$oauth->exchange(['grant_type'=>'authorization_code','client_id'=>$client,
        'redirect_uri'=>ManagerPreparationOAuth::REDIRECT_URI,'resource'=>$resource,
        'code'=>$query['code'],'code_verifier'=>$verifier]);
    $context=$oauth->contextForBearer('Bearer '.$token['access_token']);
    if(!is_array($context))throw new RuntimeException('fixture_grant_failed');
    return ['token'=>$token,'context'=>$context];
}
function forecastNativeAuthSnapshot(PDO $pdo):array
{
    $tables=['managers'=>'id','projects'=>'id','manager_projects'=>'manager_id,project_id',
        'conversations'=>'id','customers'=>'id','customer_channels'=>'id','manager_preparation_oauth_clients'=>'client_id',
        'manager_preparation_oauth_grants'=>'grant_id','manager_preparation_oauth_codes'=>'code_hash',
        'manager_preparation_oauth_tokens'=>'token_hash','manager_prepared_replies'=>'job_id',
        'manager_preparation_events'=>'subscription_id','manager_preparation_cursors'=>'project_key,lane',
        'modeled_conversion_profiles_state'=>'project_key',
        'modeled_conversion_profile_sources'=>'project_key,source_kind,source_id',
        'modeled_conversion_profile_versions'=>'id'];
    $out=[];foreach($tables as$table=>$order)$out[$table]=$pdo->query('SELECT * FROM '.$table.' ORDER BY '.$order)->fetchAll(PDO::FETCH_ASSOC);
    return $out;
}
function forecastNativeReadOnly(PDO $pdo,callable $read):mixed
{
    // Autocommit SELECTs remain outside an outer transaction, as the reader requires.
    $pdo->exec('SET SESSION TRANSACTION READ ONLY');
    try{return $read();}finally{$pdo->exec('SET SESSION TRANSACTION READ WRITE');}
}
function forecastNativeAuthRpc():array
{
    return ['jsonrpc'=>'2.0','id'=>1,'method'=>'tools/call',
        'params'=>['name'=>ModeledConversionStatusMcpAdapter::NAME,'arguments'=>[]]];
}
function forecastNativeAuthDispatch(PDO $pdo,array $grant,?string $receipt=null):array
{
    $budget=new ModeledConversionStatusBudget();
    $context=ManagerPreparationOAuth::bearerContext('Bearer '.$grant['token']['access_token'],$budget);
    if(!is_array($context))throw new RuntimeException('fixture_budgeted_bearer_missing');
    $afterBearer=$budget->remainingBytes();
    $rpc=forecastNativeAuthRpc();if($receipt!==null)$rpc['params']['arguments']=['receipt_ref'=>$receipt];
    $response=(new ManagerPreparationMcpService($pdo))->handle($rpc,
        $context['admin_id'],$context['connection_id'],$context['scopes'],$budget);
    return [$response,$afterBearer,$budget->remainingBytes()];
}
function forecastNativeEmptyUpdate(string $cursor):array
{
    [$case,$wrapper,$document]=modeledEarlyFixture([['role'=>'manager','text'=>'Служебное обновление']]);
    $case['rows'][0]['journal_id']=$cursor;
    $wrapper['journal_cursor']=$document['journal_cursor']=$cursor;
    $wrapper['source_fence_journal_id']=$document['source_fence_journal_id']=$cursor;
    foreach($wrapper['profile']['observation_input']['evidence']as&$e){
        $e['id']='message:journal:'.$cursor;
        $e['references'][0]['id']='journal:'.$cursor;
    }
    unset($e);
    $packet=ModeledConversionEarlyForecast::freeze($case,$wrapper,$document,'1',1793004000);
    return $wrapper+$packet+['processing'=>['version'=>'admitted_journal_profiles_v1_2',
        'journal_cursor'=>$cursor,'source_fence_journal_id'=>$cursor]];
}
/** Exact four-row owner day, independent of moving suffix or optional forecasts. */
function forecastNativeOperationalDay(array $data):bool
{
    $day=$data['daily_reconciliation']??[];
    return ($day['available']??false)===true&&($day['timezone']??null)==='Europe/Moscow'
        &&($day['calendar']['accruals']['events']??null)===4
        &&($day['calendar']['accruals']['conversations']??null)===2
        &&($day['calendar']['accruals']['value_rub']??null)===37500
        &&($day['calendar']['accruals']['by_platform']['max']??null)===['events'=>3,'conversations'=>1,'value_rub'=>12500]
        &&($day['calendar']['accruals']['by_platform']['telegram']??null)===['events'=>1,'conversations'=>1,'value_rub'=>25000]
        &&($day['calendar']['upload_claims']['events']??null)===3
        &&($day['calendar']['api_receipts']['events']??null)===0
        &&($day['calendar']['processed']['events']??null)===0
        &&($day['coverage']['api_receipts_without_observed_clock']['events']??null)===3
        &&($day['coverage']['api_receipts_without_observed_clock']['conversations']??null)===1
        &&($day['coverage']['api_receipts_without_observed_clock']['value_rub']??null)===12500
        &&($day['coverage']['per_event_visit_match']['status']??null)==='unavailable';
}
function forecastNativeOptionalBudget(array $section):bool
{
    return ($section['available']??null)===false
        &&in_array($section['reason']??null,['budget_headroom','time_headroom','response_budget'],true);
}
function forecastNativeMonetaryUnknown(array $core):bool
{
    $money=$core['saved']['deal_value']['by_monetary_policy'][ModeledConversionDealValue::VERSION]??[];
    return ($core['available']??false)===true&&($money['versions']??null)===1
        &&($money['estimated_value_versions']??null)===0
        &&($money['value_cap_rub']??null)===100000&&($money['unknown_price_base_rub']??null)===10000
        &&($money['unknown_price_versions']??null)===1
        &&($money['full_value_base_range_rub']??null)===['minimum'=>10000,'maximum'=>10000]
        &&($core['saved']['deal_value']['value_range_rub']??null)===['minimum'=>null,'maximum'=>null];
}
function forecastNativeAuthRegression():void
{
    $admin=null;$pdo=null;$created=false;$database='';$previousConnection=null;$connectionProperty=null;
    $phase='fixture';$passed=0;
    $check=static function(string $name,bool $ok)use(&$passed):void{
        if(!$ok)throw new RuntimeException('fixture_assertion');
        ++$passed;echo 'PASS native MySQL forecast auth '.$name.PHP_EOL;
    };
    try{
        $dsn=(string)getenv('PREPARED_REPLY_MYSQL_DSN');
        if(preg_match('/\Amysql:host=127\.0\.0\.1;port=[0-9]+;dbname=maxbot_prepared_test;charset=utf8mb4\z/D',$dsn)!==1)
            throw new RuntimeException('unsafe_fixture_database');
        $user=getenv('PREPARED_REPLY_MYSQL_USER')?:null;$pass=getenv('PREPARED_REPLY_MYSQL_PASS')?:null;
        $options=[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false,
            PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC];
        $admin=new PDO($dsn,$user,$pass,$options);
        if($admin->getAttribute(PDO::ATTR_DRIVER_NAME)!=='mysql'||$admin->query('SELECT DATABASE()')->fetchColumn()!=='maxbot_prepared_test')
            throw new RuntimeException('unsafe_fixture_database');
        $database='maxbot_forecast_auth_test_'.bin2hex(random_bytes(6));
        $admin->exec('CREATE DATABASE '.$database.' CHARACTER SET utf8mb4');$created=true;
        $pdo=new PDO(str_replace('dbname=maxbot_prepared_test;','dbname='.$database.';',$dsn),$user,$pass,$options);
        if($pdo->query('SELECT DATABASE()')->fetchColumn()!==$database)throw new RuntimeException('unsafe_fixture_database');
        $pdo->exec("CREATE TABLE managers(id BIGINT UNSIGNED PRIMARY KEY,role VARCHAR(32),is_active TINYINT) ENGINE=InnoDB;
            CREATE TABLE projects(id BIGINT UNSIGNED PRIMARY KEY,project_key VARCHAR(64),display_name VARCHAR(191),is_active TINYINT) ENGINE=InnoDB;
            CREATE TABLE manager_projects(manager_id BIGINT UNSIGNED,project_id BIGINT UNSIGNED,PRIMARY KEY(manager_id,project_id)) ENGINE=InnoDB;
            CREATE TABLE customers(id BIGINT UNSIGNED PRIMARY KEY,display_name VARCHAR(191)) ENGINE=InnoDB;
            CREATE TABLE customer_channels(id BIGINT UNSIGNED PRIMARY KEY,customer_id BIGINT UNSIGNED,project_key VARCHAR(64),channel VARCHAR(16),external_user_id VARCHAR(32),external_chat_id VARCHAR(32)) ENGINE=InnoDB;
            CREATE TABLE conversations(id BIGINT UNSIGNED PRIMARY KEY,customer_id BIGINT UNSIGNED,customer_channel_id BIGINT UNSIGNED,project_key VARCHAR(64),channel VARCHAR(16),is_test INT DEFAULT 0,external_chat_id VARCHAR(32)) ENGINE=InnoDB;
            INSERT INTO managers VALUES(1,'admin',1);
            INSERT INTO projects VALUES(1,'anytour','Native forecast fixture',1);
            INSERT INTO manager_projects VALUES(1,1);
            INSERT INTO conversations(id) VALUES(1)");
        foreach(['044_manager_prepared_replies.sql','045_manager_preparation_oauth.sql',
            '046_manager_preparation_events.sql','050_manager_preparation_cursors.sql',
            '066_modeled_conversion_profiles.sql']as$file)
            $pdo->exec(file_get_contents(dirname(__DIR__,2).'/migrations/'.$file));
        $connectionProperty=new ReflectionProperty(ConversationDb::class,'pdo');
        $previousConnection=$connectionProperty->getValue();$connectionProperty->setValue(null,$pdo);
        ProjectConfig::resetForTests(['id'=>'anytour','brand'=>['name'=>'Native forecast fixture']]);
        $oauth=new ManagerPreparationOAuth($pdo);
        $client=$oauth->registerClient(['redirect_uris'=>[ManagerPreparationOAuth::REDIRECT_URI]],'native-forecast-fixture')['client_id'];
        $legacy=forecastNativeAuthGrant($oauth,$client,'queue:read prepared:write archive:read');
        $forecast=forecastNativeAuthGrant($oauth,$client,'forecasts:read');
        $combined=forecastNativeAuthGrant($oauth,$client,'queue:read prepared:write archive:read forecasts:read');
        $denied=[];
        foreach(['expired','revoked','wrong_resource']as$kind){
            $denied[$kind]=forecastNativeAuthGrant($oauth,$client,'forecasts:read');
            $family=$denied[$kind]['context']['connection_id'];
            if($kind==='expired')$pdo->prepare('UPDATE manager_preparation_oauth_tokens SET expires_at=? WHERE grant_id=?')->execute([time()-1,$family]);
            elseif($kind==='revoked')$pdo->prepare('UPDATE manager_preparation_oauth_grants SET revoked_at=? WHERE grant_id=?')->execute([time(),$family]);
            else $pdo->prepare('UPDATE manager_preparation_oauth_grants SET resource=? WHERE grant_id=?')->execute([ManagerPreparationOAuth::DEVELOPMENT_RESOURCE,$family]);
        }
        $development=new ManagerPreparationOAuth($pdo,null,ManagerPreparationOAuth::PROFILE_DEVELOPMENT);
        $devClient=$development->registerClient(['redirect_uris'=>[ManagerPreparationOAuth::REDIRECT_URI]],'native-development-fixture')['client_id'];
        $denied['development_profile']=forecastNativeAuthGrant($development,$devClient,ManagerPreparationOAuth::DEVELOPMENT_SCOPE,ManagerPreparationOAuth::DEVELOPMENT_RESOURCE);
        $now=time();$date=gmdate('Y-m-d H:i:s',$now);$expires=gmdate('Y-m-d H:i:s',$now+3600);
        $q=$pdo->prepare("INSERT INTO manager_prepared_replies(job_id,conversation_id,manager_id,project_key,context_revision,instruction_revision,latest_message_id,status,created_at,expires_at) VALUES(?,1,1,'anytour',?,?,2,?,?,?)");
        foreach(['pending','ready']as$i=>$status)$q->execute([str_repeat((string)($i+1),64),str_repeat('a',64),str_repeat('b',64),$status,$date,$expires]);
        $pdo->exec("INSERT INTO manager_preparation_cursors VALUES('anytour','waiting',42,7)");
        $pdo->prepare('INSERT INTO manager_preparation_events(subscription_id,admin_id,oauth_connection_id,project_key,event_name,arguments_json,callback_url,signing_secret,verified_at,expires_at,observed_jobs_json,pending_jobs_json,created_at,updated_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?)')
            ->execute(['sub_'.str_repeat('c',64),1,$legacy['context']['connection_id'],'anytour','manager_replies.pending','{"project_key":"anytour"}',
                'https://fixture.invalid/no-network','synthetic-fixture-secret',$date,$expires,'[]','[]',$date,$date]);
        $receipt=['status'=>'idle','observed_at_unix'=>1793004000,'profiles_written'=>0,'pending_revisions'=>0,
            'early_forecast'=>['version'=>ModeledConversionEarlyForecast::VERSION,'snapshots'=>0]];
        $pdo->prepare("INSERT INTO modeled_conversion_profiles_state(project_key,enabled,journal_cursor,ticks,last_receipt_json) VALUES('anytour',1,2,9,?)")
            ->execute([json_encode($receipt,JSON_THROW_ON_ERROR)]);
        forecastStatusAppend($pdo,forecastStatusPacket([['text'=>'Подберите тур. Бюджет до 200 тысяч.']]));
        forecastStatusAppend($pdo,forecastStatusPacket([['text'=>'Подберите тур. Бюджет до 200 тысяч.'],['text'=>'Давайте бронировать этот тур']],'1'));
        $context=forecastStatusPacket([['text'=>'Спасибо']]);
        forecastStatusAppend($pdo,forecastStatusScope($context,'8','10'),'anytour','8','10');
        $payment=forecastStatusPacket([['text'=>'Подберите тур'],['text'=>'Оплатила'],['role'=>'manager','text'=>'Оплату вижу']]);
        forecastStatusAppend($pdo,forecastStatusScope($payment,'9','11'),'anytour','9','11');
        for($i=0;$i<25;$i++)forecastStatusAppend($pdo,forecastNativeEmptyUpdate((string)(100+$i)));
        // A new current-model/processor V1 packet proves the new default reader without replaying old ordinal strata.
        forecastV1Append($pdo,forecastV1Packet('10','12',extractionDelay:17));
        $before=forecastNativeAuthSnapshot($pdo);
        $phase='real_bearer_dispatch_reader';
        forecastNativeReadOnly($pdo,static function()use($pdo,$oauth,$forecast,$combined,$legacy,$denied,$check):void{
            $plain=$oauth->contextForBearer('Bearer '.$forecast['token']['access_token']);
            $budgeted=ManagerPreparationOAuth::bearerContext('Bearer '.$forecast['token']['access_token'],new ModeledConversionStatusBudget());
            $check('migrated grant resolves identically through plain and budgeted real bearer',$plain===$budgeted&&$plain===$forecast['context']);
            foreach([$forecast,$combined]as$i=>$grant){
                [$response,$afterBearer,$afterRead]=forecastNativeAuthDispatch($pdo,$grant);
                $saved=$response['result']['structuredContent']??[];
                $check('real operational dispatcher retains validated current V1 percentages with grant variant '.$i,
                    ($saved['available']??false)&&($saved['view']??null)==='operational_delivery_v1'
                    &&($saved['forecast_core']['available']??false)
                    &&($saved['reader_version']??null)===ModeledConversionForecastStatusReader::VERSION
                    &&($saved['confirmation']??null)==='saved_prospective_estimates'
                    &&($saved['saved']['prospective_estimates']??null)===1
                    &&($saved['saved']['valid_v1_versions']??null)===1
                    &&($saved['saved']['estimated_probability_range']['minimum']??null)===0.083333
                    &&($saved['saved']['channel_counts']['telegram']??null)===1
                    &&($saved['state']['last_tick_status']??null)==='idle');
                $check('missing initial delivery schema stays explicitly unknown without hiding a valid profile core variant '.$i,
                    ($saved['daily_reconciliation']['available']??true)===false
                    &&($saved['daily_reconciliation']['reason']??null)==='storage_unavailable'
                    &&($saved['value_delivery']['available']??true)===false
                    &&($saved['value_delivery']['reason']??null)==='storage_unavailable');
                $check('one budget includes real bearer repeated authorization and reader variant '.$i,
                    $afterBearer<65536&&$afterRead<$afterBearer&&$afterRead>=4096
                    &&strlen(json_encode($response,JSON_THROW_ON_ERROR))<=65536);
            }
            [$response]=forecastNativeAuthDispatch($pdo,$forecast);
            $check('same granted bearer reads again without renewed consent',
                ($response['result']['structuredContent']['saved']['prospective_estimates']??null)===1);
            [$response]=forecastNativeAuthDispatch($pdo,$legacy);
            $challenge=$response['result']['_meta']['mcp/www_authenticate'][0]??'';
            $check('old real grant receives explicit scope union without a private result',
                ($response['result']['isError']??false)
                &&str_contains($challenge,'queue:read prepared:write archive:read forecasts:read')
                &&!isset($response['result']['structuredContent']));
            foreach(['','Bearer invalid','Bearer '.str_repeat('z',43)]as$i=>$header)
                $check('missing malformed or unknown bearer is a genuine denial '.$i,
                    ManagerPreparationOAuth::bearerContext($header,new ModeledConversionStatusBudget())===null);
            foreach($denied as$kind=>$grant)
                $check('real rejected grant remains a genuine denial '.$kind,
                    ManagerPreparationOAuth::bearerContext('Bearer '.$grant['token']['access_token'],new ModeledConversionStatusBudget())===null);
            $expired=new ModeledConversionStatusBudget(1.0,static fn():float=>7.0);$limited=false;
            try{ManagerPreparationOAuth::bearerContext('Bearer '.$forecast['token']['access_token'],$expired);}
            catch(ModeledConversionStatusLimit $error){$limited=$error->reason==='time_limit';}
            $check('budget exhaustion is distinct from authentication denial',$limited);
        });
        $check('all grants tokens codes queues cursors and saved profiles remain byte-identical',
            forecastNativeAuthSnapshot($pdo)===$before);
        $phase='oauth_storage_failure';
        $pdo->exec('RENAME TABLE manager_preparation_oauth_clients TO forecast_fixture_clients_unavailable');
        try{
            forecastNativeReadOnly($pdo,static function()use($forecast,$check):void{
                $header='Bearer '.$forecast['token']['access_token'];$unavailable=false;
                try{ManagerPreparationOAuth::bearerContext($header,new ModeledConversionStatusBudget());}
                catch(ManagerPreparationOAuthLookupUnavailable $error){$unavailable=true;}
                $check('real fast auth SQL failure is typed unavailable instead of absent consent',$unavailable);
                $check('legacy unbudgeted bearer retains its original failure behavior',ManagerPreparationOAuth::bearerContext($header)===null);
            });
        }finally{$pdo->exec('RENAME TABLE forecast_fixture_clients_unavailable TO manager_preparation_oauth_clients');}
        $check('auth fault restoration preserves every fixture row',forecastNativeAuthSnapshot($pdo)===$before);
        $phase='derived_storage_failure';
        $pdo->exec('RENAME TABLE modeled_conversion_profile_versions TO forecast_fixture_profiles_unavailable');
        try{
            forecastNativeReadOnly($pdo,static function()use($pdo,$forecast,$check):void{
                [$response]=forecastNativeAuthDispatch($pdo,$forecast);
                $data=$response['result']['structuredContent']??[];
                $check('missing derived storage remains authorized explicit unknown without a consent challenge',
                    ($data['available']??null)===true&&($data['view']??null)==='operational_delivery_v1'
                    &&($data['forecast_core']['available']??true)===false
                    &&($data['forecast_core']['reason']??null)==='storage_unavailable'
                    &&array_key_exists('saved',$data)&&$data['saved']===null
                    &&!isset($response['result']['_meta']['mcp/www_authenticate']));
            });
        }finally{$pdo->exec('RENAME TABLE forecast_fixture_profiles_unavailable TO modeled_conversion_profile_versions');}
        $check('all read and fault cases preserve complete fixture state',forecastNativeAuthSnapshot($pdo)===$before);
        $phase='composed_status_budget';
        forecastLatencyAssertions($pdo,$check);
        $pdo->exec('ALTER TABLE max_channel_conversion_outbox ADD COLUMN target VARCHAR(100)');
        $pdo->exec('ALTER TABLE max_channel_conversion_outbox ADD COLUMN value_reviewed_at_unix BIGINT NULL');
        $pdo->exec('ALTER TABLE max_channel_conversion_outbox ADD COLUMN external_user_id VARCHAR(32)');
        $pdo->exec('ALTER TABLE max_channel_conversion_outbox ADD COLUMN next_attempt_unix BIGINT DEFAULT 0');
        $pdo->exec('ALTER TABLE max_channel_conversion_outbox ADD value_ready_at_unix BIGINT NULL,ADD value_api_accepted_at_unix BIGINT NULL,ADD value_processed_at_unix BIGINT NULL,ADD value_receipt_basis VARCHAR(24) NULL');
        $pdo->exec('UPDATE max_channel_conversion_outbox SET external_user_id=CAST(conversation_id AS CHAR)');
        $pdo->exec("INSERT INTO customers VALUES(111,'Synthetic MAX owner'),(333,'Synthetic Telegram owner');
            INSERT INTO customer_channels VALUES(111,111,'anytour','max','111','-111'),(333,333,'anytour','telegram','333','333');
            INSERT INTO conversations(id,customer_id,customer_channel_id,project_key,channel,is_test,external_chat_id)
                VALUES(111,111,111,'anytour','max',0,'-111'),(333,333,333,'anytour','telegram',0,'333')");
        $targets=$pdo->query('SELECT id,value_stage FROM max_channel_conversion_outbox ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
        $bindTarget=$pdo->prepare('UPDATE max_channel_conversion_outbox SET target=? WHERE id=?');
        foreach($targets as$target){
            $rank=array_search($target['value_stage'],ModeledConversionValueContract::STAGES,true);
            if($rank===false)throw new RuntimeException('fixture_unknown_stage');
            $bindTarget->execute([ModeledConversionValueContract::key($rank),$target['id']]);
        }
        $pdo->exec("CREATE TABLE advertising_visit_observations(id BIGINT PRIMARY KEY,project_key VARCHAR(40),source_key VARCHAR(80),platform VARCHAR(16),counter_id VARCHAR(20),external_user_id VARCHAR(32),identity_status VARCHAR(16),client_id VARCHAR(40),visit_at_unix BIGINT,observed_at_unix BIGINT,received_at_unix BIGINT)");
        // Keep the synthetic visit before its pending signal when the fixture calendar changes.
        $pdo->exec("INSERT INTO advertising_visit_observations SELECT 1,'anytour','tgmini','telegram','98615635','333','verified','88000003',event_unix-1300,event_unix-1200,event_unix-1100 FROM max_channel_conversion_outbox WHERE id=3");

    $pdo->exec("CREATE TABLE modeled_conversion_value_export_settings(project_key VARCHAR(40) PRIMARY KEY,counter_id VARCHAR(20),mode VARCHAR(16),goal_id VARCHAR(20),goal_intent VARCHAR(20),activated_at_unix BIGINT,last_tick_unix BIGINT,contract_version VARCHAR(64),last_receipt_json TEXT,history_from_unix BIGINT,history_until_unix BIGINT,history_completed_at_unix BIGINT,history_scanned BIGINT,history_collected BIGINT,history_stats_json TEXT,history_cursor BIGINT,history_ceiling BIGINT,history_fortnight_requested_at_unix BIGINT,history_day_receipt_json TEXT,last_report_success_unix BIGINT,last_report_success_json TEXT,report_failure_since_unix BIGINT)");
        $pdo->prepare("INSERT INTO modeled_conversion_value_export_settings(project_key,counter_id,mode,goal_id,goal_intent,activated_at_unix,last_tick_unix,contract_version,last_receipt_json,history_from_unix,history_until_unix,history_cursor,history_ceiling) VALUES('anytour','98615635','send','678187097','verified',1793656800,?,?,'{}',1793570400,1793656800,1,30099)")->execute([time(),ModeledConversionValueContract::VERSION]);
        // All subviews exist together: 32 sampled scopes, 100 rich saved packets and a real migrated OAuth grant.
        $insert=$pdo->prepare("INSERT INTO modeled_conversion_profile_versions(id,project_key,platform,customer_key,conversation_id,journal_cursor,observed_at_unix,profile_json) VALUES(?,'anytour','telegram',?,?,?,?,?)");
        for($i=0;$i<100;$i++){
            $person=(string)(9000+$i%32);$conversation=(string)(10000+$i%32);
            $packet=forecastV1Packet($person,$conversation);
            $insert->execute([30000+$i,$person,$conversation,$packet['journal_cursor'],strtotime($packet['profile']['prediction_at']),json_encode($packet,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)]);
        }
        // The component above keeps its fixed Nov 3 clock and exact delay assertions.
        // The real OAuth dispatcher reads time(); only its dense synthetic cohort
        // must be inside the current Moscow day, including its first second.
        $composedDay=(new DateTimeImmutable('@'.time()))->setTimezone(new DateTimeZone('Europe/Moscow'))->setTime(0,0);
        $composedDayStart=$composedDay->getTimestamp();
        // These four existing value rows, not the bounded recent suffix, are the complete current owner day.
        $pdo->prepare('UPDATE max_channel_conversion_outbox SET event_unix=?,claimed_at_unix=CASE WHEN claimed_at_unix IS NULL THEN NULL ELSE ? END')
            ->execute([$composedDayStart,$composedDayStart]);
        $pdo->prepare('UPDATE advertising_visit_observations SET visit_at_unix=?,observed_at_unix=?,received_at_unix=? WHERE id=1')
            ->execute([$composedDayStart-1300,$composedDayStart-1200,$composedDayStart-1100]);
        $alignSource=$pdo->prepare("UPDATE modeled_conversion_stream_versions SET observed_at_unix=?,snapshot_json=JSON_SET(snapshot_json,'$.source_created_at_unix',CAST(? AS UNSIGNED)) WHERE project_key='anytour' AND source_kind='message' AND id BETWEEN 3000 AND 5004");
        $alignSource->execute([$composedDayStart,$composedDayStart]);
        $alignProfiles=$pdo->prepare("UPDATE modeled_conversion_profile_versions SET observed_at_unix=? WHERE project_key='anytour' AND platform='max' AND id BETWEEN 10000 AND 12687");
        $alignProfiles->execute([$composedDayStart]);
        $alignment=$pdo->prepare("SELECT (SELECT COUNT(*) FROM modeled_conversion_stream_versions WHERE project_key='anytour' AND source_kind='message' AND id BETWEEN 3000 AND 5004 AND observed_at_unix=? AND JSON_TYPE(JSON_EXTRACT(snapshot_json,'$.source_created_at_unix'))='INTEGER' AND CAST(JSON_UNQUOTE(JSON_EXTRACT(snapshot_json,'$.source_created_at_unix')) AS UNSIGNED)=?) AS messages,(SELECT COUNT(*) FROM modeled_conversion_profile_versions WHERE project_key='anytour' AND platform='max' AND id BETWEEN 10000 AND 12687 AND observed_at_unix=?) AS profiles");
        $alignment->execute([$composedDayStart,$composedDayStart,$composedDayStart]);
        $aligned=$alignment->fetch(PDO::FETCH_ASSOC);
        $check('composed synthetic timing cohort is aligned with the real current calendar day',
            (int)$aligned['messages']===2005&&(int)$aligned['profiles']===2688);
        $before=forecastNativeAuthSnapshot($pdo);
        $phase='composed_dispatch_and_contract';
        forecastNativeReadOnly($pdo,static function()use($pdo,$forecast,$check):void{
            [$response,$afterBearer,$afterRead]=forecastNativeAuthDispatch($pdo,$forecast);
            $data=$response['result']['structuredContent']??[];
            // Only this disposable fixture's technical contract flags, never token/row/response contents.
            $reason=$response['error']['data']['reason']??null;
            echo 'COMPOSED_FIXTURE_TECHNICAL '.json_encode([
                'error_code'=>$response['error']['code']??null,
                'budget_reason'=>in_array($reason,['byte_limit','time_limit'],true)?$reason:null,
                'remaining_after_bearer'=>$afterBearer,'remaining_after_read'=>$afterRead,
                'available'=>$data['available']??null,'value_available'=>$data['value_delivery']['available']??null,
                'day_available'=>$data['daily_reconciliation']['available']??null,
                'core_available'=>$data['forecast_core']['available']??null,'core_reason'=>$data['forecast_core']['reason']??null,
                'timing_available'=>$data['today_latency']['available']??null,
                'linkage_available'=>$data['telegram_linkage']['available']??null,
                'result_bytes'=>strlen(json_encode($data,JSON_THROW_ON_ERROR)),
                'rpc_bytes'=>strlen(json_encode($response,JSON_THROW_ON_ERROR))],JSON_THROW_ON_ERROR).PHP_EOL;
            $check('composed real OAuth operational read prioritizes the complete owner day and delivery under the shared budget',
                ($data['available']??false)&&($data['view']??null)==='operational_delivery_v1'
                &&($data['value_delivery']['available']??false)&&forecastNativeOperationalDay($data)
                &&is_array($data['external_monitor']??null)&&array_key_exists('pipeline',$data['external_monitor'])
                &&($data['provider_requests_in_reader']??null)===0
                &&($data['model_calls']??null)===0&&($data['conversion_uploads']??null)===0
                &&$afterRead>=4096&&$afterRead<$afterBearer
                &&strlen(json_encode($data,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR))<=28000
                &&strlen(json_encode($response,JSON_THROW_ON_ERROR))<=65536);
            $core=$data['forecast_core']??[];
            $check('dense operational profile context is one validated saved version or an explicit budget omission',
                (($core['available']??false)&&($data['saved']['valid_v1_versions']??null)===1
                    &&($core['basis']??null)==='at_most_one_current_immutable_profile_core_after_operational_priorities')
                ||(forecastNativeOptionalBudget($core)&&array_key_exists('saved',$data)&&$data['saved']===null));
            $timing=$data['today_latency']??[];$linkage=$data['telegram_linkage']??[];
            $check('optional timing and Telegram context retain exact observations or explicitly yield to day and monitor priorities',
                ((($timing['available']??false)&&($timing['profile_sample']['scopes_inspected']??null)===32)
                    ||forecastNativeOptionalBudget($timing))
                &&((($linkage['available']??false)&&($linkage['pending']['events']??null)===1
                    &&($linkage['pending']['reason_counts']['unique_eligible']??null)===1)
                    ||forecastNativeOptionalBudget($linkage)));
        });
        $check('combined private status preserves every OAuth and saved profile row',forecastNativeAuthSnapshot($pdo)===$before);

        $phase='composed_monetary_policy';
        // Day and monitor remain mandatory priorities; an optional one-profile core
        // must never invent a price when its source price is unknown.
        $packet=forecastV1Packet($person,$conversation,[['text'=>'Здравствуйте!']]);
        $packet['deal_value_prediction']=ModeledConversionDealValue::freeze($packet,[]);
        $pdo->prepare("UPDATE modeled_conversion_profile_versions SET journal_cursor=?,observed_at_unix=?,profile_json=? WHERE id=30099 AND project_key='anytour' AND platform='telegram'")
            ->execute([$packet['journal_cursor'],strtotime($packet['profile']['prediction_at']),json_encode($packet,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)]);
        $before=forecastNativeAuthSnapshot($pdo);
        $deliverySnapshot=static function()use($pdo):array{
            return ['settings'=>$pdo->query('SELECT * FROM modeled_conversion_value_export_settings ORDER BY project_key')->fetchAll(PDO::FETCH_ASSOC),
                'outbox'=>$pdo->query('SELECT * FROM max_channel_conversion_outbox ORDER BY id')->fetchAll(PDO::FETCH_ASSOC)];
        };
        $deliveryBefore=$deliverySnapshot();
        forecastNativeReadOnly($pdo,static function()use($pdo,$forecast,$check):void{
            [$response,$afterBearer,$afterRead]=forecastNativeAuthDispatch($pdo,$forecast);
            $data=$response['result']['structuredContent']??[];
            $core=$data['forecast_core']??[];
            $check('composed real OAuth retains exact V2 unknown-price policy when the optional core fits',
                (($core['available']??false)&&forecastNativeMonetaryUnknown($data))
                ||(forecastNativeOptionalBudget($core)&&array_key_exists('saved',$data)&&$data['saved']===null));
            $check('monetary operational output preserves complete daily coverage and final OAuth headroom',
                !isset($response['error'])&&($data['available']??false)&&($data['value_delivery']['available']??false)
                &&forecastNativeOperationalDay($data)&&is_array($data['external_monitor']??null)
                &&($data['model_calls']??null)===0&&($data['conversion_uploads']??null)===0
                &&$afterRead>=4096&&$afterRead<$afterBearer
                &&strlen(json_encode($data,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR))<=28000
                &&strlen(json_encode($response,JSON_THROW_ON_ERROR))<=65536);
            echo 'MONETARY_COMPOSED_FIXTURE_TECHNICAL '.json_encode([
                'remaining_after_bearer'=>$afterBearer,'remaining_after_read'=>$afterRead,
                'daily_available'=>$data['daily_reconciliation']['available']??null,
                'core_available'=>$core['available']??null,'core_reason'=>$core['reason']??null,
                'timing_available'=>$data['today_latency']['available']??null,
                'linkage_available'=>$data['telegram_linkage']['available']??null,
                'result_bytes'=>strlen(json_encode($data,JSON_THROW_ON_ERROR)),
                'rpc_bytes'=>strlen(json_encode($response,JSON_THROW_ON_ERROR))],JSON_THROW_ON_ERROR).PHP_EOL;
            // The exact canonical profile validator is still proved separately, with
            // its unchanged budget and no delivery subview or fabricated numeric price.
            $profile=(new ModeledConversionForecastStatusReader($pdo,static function():void{},new ModeledConversionStatusBudget()))->read(false);
            $check('canonical one-profile validation preserves V2 policy and numeric unknown on the real native JSON representation',
                forecastNativeMonetaryUnknown($profile)&&($profile['model_calls']??null)===0
                &&($profile['conversion_uploads']??null)===0);
        });
        $check('monetary composed read preserves every OAuth and saved profile row',forecastNativeAuthSnapshot($pdo)===$before);
        $check('monetary daily coverage preserves saved delivery settings and every outbox row',$deliverySnapshot()===$deliveryBefore);
        require_once dirname(__DIR__).'/modeled_conversion_saved_receipt_fixture.php';
        forecastSavedReceiptAssertions($pdo,$check,static function(string $ref)use($pdo,$forecast):array{
            [$rpc]=forecastNativeAuthDispatch($pdo,$forecast,$ref);
            $data=$rpc['result']['structuredContent']??null;if(!is_array($data))throw new RuntimeException('fixture_receipt_rpc_failed');
            return $data;
        });
        echo 'NATIVE FORECAST AUTH PASSED '.$passed.PHP_EOL;
    }catch(Throwable $error){
        // Fixed phases and sanitized codes only: no tokens, SQL, fixture texts or connection details.
        $state=$error instanceof PDOException?(string)($error->errorInfo[0]??$error->getCode()):'unknown';
        $driver=$error instanceof PDOException?(string)($error->errorInfo[1]??''):'unknown';
        $state=preg_match('/\A[A-Z0-9]{5}\z/D',$state)===1?$state:'unknown';
        $driver=preg_match('/\A[0-9]{1,10}\z/D',$driver)===1?$driver:'unknown';
        fwrite(STDERR,'FAIL native forecast auth phase='.$phase.' sqlstate='.$state.' driver='.$driver.PHP_EOL);
        throw new RuntimeException('native_forecast_auth_failed');
    }finally{
        if($connectionProperty instanceof ReflectionProperty)$connectionProperty->setValue(null,$previousConnection);
        if($created&&$admin instanceof PDO)$admin->exec('DROP DATABASE '.$database);
    }
}
forecastNativeAuthRegression();
