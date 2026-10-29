<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once dirname(__DIR__,2).'/services/ManagerPreparationMcpService.php';
require_once dirname(__DIR__).'/run_modeled_conversion_status_regression.php';

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
        'conversations'=>'id','manager_preparation_oauth_clients'=>'client_id',
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
function forecastNativeAuthDispatch(PDO $pdo,array $grant):array
{
    $budget=new ModeledConversionStatusBudget();
    $context=ManagerPreparationOAuth::bearerContext('Bearer '.$grant['token']['access_token'],$budget);
    if(!is_array($context))throw new RuntimeException('fixture_budgeted_bearer_missing');
    $afterBearer=$budget->remainingBytes();
    $response=(new ManagerPreparationMcpService($pdo))->handle(forecastNativeAuthRpc(),
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
            CREATE TABLE conversations(id BIGINT UNSIGNED PRIMARY KEY) ENGINE=InnoDB;
            INSERT INTO managers VALUES(1,'admin',1);
            INSERT INTO projects VALUES(1,'anytour','Native forecast fixture',1);
            INSERT INTO manager_projects VALUES(1,1);
            INSERT INTO conversations VALUES(1)");
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
        $before=forecastNativeAuthSnapshot($pdo);
        $phase='real_bearer_dispatch_reader';
        forecastNativeReadOnly($pdo,static function()use($pdo,$oauth,$forecast,$combined,$legacy,$denied,$check):void{
            $plain=$oauth->contextForBearer('Bearer '.$forecast['token']['access_token']);
            $budgeted=ManagerPreparationOAuth::bearerContext('Bearer '.$forecast['token']['access_token'],new ModeledConversionStatusBudget());
            $check('migrated grant resolves identically through plain and budgeted real bearer',$plain===$budgeted&&$plain===$forecast['context']);
            foreach([$forecast,$combined]as$i=>$grant){
                [$response,$afterBearer,$afterRead]=forecastNativeAuthDispatch($pdo,$grant);
                $saved=$response['result']['structuredContent']??[];
                $check('real dispatcher reads persisted early signals with grant variant '.$i,
                    ($saved['available']??false)&&($saved['confirmation']??null)==='saved_early_assessment_with_signals'
                    &&($saved['saved']['paired_snapshots']??null)===3
                    &&($saved['saved']['early_assessments_with_signals']??null)===1
                    &&($saved['saved']['distinct_conversation_scopes']??null)===3
                    &&($saved['saved']['customer_context_snapshots']??null)===3
                    &&($saved['saved']['goal_counts']['substantive_interest']??0)>0
                    &&($saved['saved']['goal_counts']['agency_purchase_intent']??0)>0
                    &&($saved['stage_checks']['general_purchase_intent']['validated_saved_evidence']??false)
                    &&($saved['stage_checks']['agency_purchase_intent']['validated_saved_evidence']??false)
                    &&($saved['stage_checks']['agency_purchase_intent']['early_assessment']??null)===true
                    &&($saved['stage_checks']['payment_evidence']['validated_saved_evidence']??false)
                    &&($saved['stage_checks']['payment_evidence']['early_assessment']??null)===false
                    &&($saved['state']['last_tick_status']??null)==='idle');
                $check('one budget includes real bearer repeated authorization and reader variant '.$i,
                    $afterBearer<65536&&$afterRead<$afterBearer&&$afterRead>=4096
                    &&strlen(json_encode($response,JSON_THROW_ON_ERROR))<=65536);
            }
            [$response]=forecastNativeAuthDispatch($pdo,$forecast);
            $check('same granted bearer reads again without renewed consent',
                ($response['result']['structuredContent']['saved']['paired_snapshots']??null)===3);
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
                    ($data['available']??null)===false&&($data['reason']??null)==='storage_unavailable'
                    &&array_key_exists('saved',$data)&&$data['saved']===null
                    &&!isset($response['result']['_meta']['mcp/www_authenticate']));
            });
        }finally{$pdo->exec('RENAME TABLE forecast_fixture_profiles_unavailable TO modeled_conversion_profile_versions');}
        $check('all read and fault cases preserve complete fixture state',forecastNativeAuthSnapshot($pdo)===$before);
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
