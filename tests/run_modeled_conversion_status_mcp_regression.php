<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once dirname(__DIR__).'/services/ManagerPreparationMcpService.php';

$passed=0;
function forecastMcpCheck(string $name,bool $ok):void
{
    global $passed;if(!$ok)throw new RuntimeException('FAIL '.$name);++$passed;echo 'PASS '.$name.PHP_EOL;
}
function forecastMcpFixture():array
{
    $pdo=new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
    $pdo->exec("CREATE TABLE managers(id INTEGER PRIMARY KEY,role TEXT,is_active INTEGER);
        CREATE TABLE projects(id INTEGER PRIMARY KEY,project_key TEXT,display_name TEXT,is_active INTEGER);
        CREATE TABLE manager_projects(manager_id INTEGER,project_id INTEGER);
        CREATE TABLE manager_preparation_oauth_clients(client_id TEXT PRIMARY KEY,redirect_uri TEXT,registration_bucket TEXT,created_at INTEGER);
        CREATE TABLE manager_preparation_oauth_grants(grant_id TEXT PRIMARY KEY,client_id TEXT,admin_id INTEGER,project_key TEXT,resource TEXT,scopes TEXT,created_at INTEGER,expires_at INTEGER,revoked_at INTEGER);
        CREATE TABLE manager_preparation_oauth_codes(code_hash TEXT PRIMARY KEY,grant_id TEXT,redirect_uri TEXT,code_challenge TEXT,expires_at INTEGER,consumed_at INTEGER);
        CREATE TABLE manager_preparation_oauth_tokens(token_hash TEXT PRIMARY KEY,grant_id TEXT,token_kind TEXT,expires_at INTEGER,consumed_at INTEGER);
        INSERT INTO managers VALUES(1,'admin',1),(2,'manager',1),(3,'admin',0),(4,'admin',1);
        INSERT INTO projects VALUES(1,'anytour','Forecast fixture',1),(2,'other','Other',1);
        INSERT INTO manager_projects VALUES(1,1),(2,1),(3,1),(4,2);");
    (new ReflectionProperty(ConversationDb::class,'pdo'))->setValue(null,$pdo);
    (new ReflectionProperty(ProjectAccessService::class,'schemaReady'))->setValue(null,false);
    ProjectConfig::resetForTests(['id'=>'anytour','brand'=>['name'=>'Forecast fixture']]);
    $oauth=new ManagerPreparationOAuth($pdo);
    $client=$oauth->registerClient(['redirect_uris'=>[ManagerPreparationOAuth::REDIRECT_URI]],'forecast-fixture')['client_id'];
    return [$pdo,$oauth,$client];
}
function forecastMcpGrant(ManagerPreparationOAuth $oauth,string $client,string $scope,string $resource=ManagerPreparationOAuth::RESOURCE):array
{
    $session=['csrf'=>'forecast-csrf'];$verifier=str_repeat('a',43);
    $request=['client_id'=>$client,'redirect_uri'=>ManagerPreparationOAuth::REDIRECT_URI,'response_type'=>'code',
        'resource'=>$resource,'scope'=>$scope,'state'=>'forecast-state',
        'code_challenge_method'=>'S256','code_challenge'=>rtrim(strtr(base64_encode(hash('sha256',$verifier,true)),'+/','-_'),'=')];
    $pending=$oauth->beginAuthorization($session,$request);$oauth->pendingAuthorization($session,$pending,1);
    $redirect=$oauth->completeAuthorization($session,$pending,1,$session['csrf'],true);
    parse_str((string)parse_url($redirect,PHP_URL_QUERY),$query);
    $tokens=$oauth->exchange(['grant_type'=>'authorization_code','client_id'=>$client,'redirect_uri'=>ManagerPreparationOAuth::REDIRECT_URI,
        'resource'=>$resource,'code'=>$query['code'],'code_verifier'=>$verifier]);
    return [$tokens,$oauth->connectionForBearer('Bearer '.$tokens['access_token'])];
}
function forecastMcpRpc(string $method='tools/call',array $params=[]):array
{
    return ['jsonrpc'=>'2.0','id'=>1,'method'=>$method,'params'=>$params?:['name'=>ModeledConversionStatusMcpAdapter::NAME,'arguments'=>[]]];
}
function forecastMcpService(PDO $pdo,callable $store):ManagerPreparationMcpService
{
    return new ManagerPreparationMcpService($pdo,null,null,null,null,null,null,null,null,null,null,null,$store);
}
function forecastMcpAssertions():void
{
    [$pdo,$oauth,$client]=forecastMcpFixture();
    [$legacyTokens,$legacy]=forecastMcpGrant($oauth,$client,'queue:read prepared:write archive:read');
    [$tokens,$family]=forecastMcpGrant($oauth,$client,ManagerPreparationOAuth::FORECAST_SCOPE);
    $calls=0;$store=static function()use(&$calls):array{++$calls;return ['ok'=>true,'status'=>'verified','saved_rows_checked'=>1];};
    $service=forecastMcpService($pdo,$store);
    $listed=$service->handle(forecastMcpRpc('tools/list',['_meta'=>[]]),1,$legacy,['queue:read','prepared:write','archive:read']);
    $byName=array_column($listed['result']['tools'],'name');
    $tool=ModeledConversionStatusMcpAdapter::tools()[0];
    forecastMcpCheck('existing connection discovers an explicit separate read-only capability',
        in_array(ModeledConversionStatusMcpAdapter::NAME,$byName,true)
        &&$tool['securitySchemes']===[['type'=>'oauth2','scopes'=>['forecasts:read']]]
        &&$tool['annotations']['readOnlyHint']&&$tool['annotations']['idempotentHint']&&!$tool['annotations']['openWorldHint']);
    $before=$pdo->query('SELECT * FROM manager_preparation_oauth_grants ORDER BY grant_id')->fetchAll();
    $challenge=$service->handle(forecastMcpRpc(),1,$legacy,['queue:read','prepared:write','archive:read']);
    $auth=$challenge['result']['_meta']['mcp/www_authenticate'][0]??'';
    forecastMcpCheck('archive and reply permissions require fresh consent before any forecast read',
        ($challenge['result']['isError']??false)&&str_contains($auth,'forecasts:read')
        &&str_contains($auth,'queue:read prepared:write archive:read')&&$calls===0
        &&$before===$pdo->query('SELECT * FROM manager_preparation_oauth_grants ORDER BY grant_id')->fetchAll());
    $all=implode(' ',ManagerPreparationOAuth::SUPPORTED_SCOPES);
    forecastMcpCheck('all supported capabilities fit the forward160-byte grant column',strlen($all)===152&&strlen($all)<=160);
    $forecastTools=$service->handle(forecastMcpRpc('tools/list',['_meta'=>[]]),1,$family,[ManagerPreparationOAuth::FORECAST_SCOPE]);
    forecastMcpCheck('forecast-only discovery exposes its aggregate and separate dialogue-read consent metadata',
        array_column($forecastTools['result']['tools']??[],'name')===array_merge([ModeledConversionStatusMcpAdapter::NAME],DialogueReviewMcpAdapter::NAMES,ManagerTourSelectionMcpAdapter::NAMES)
        &&$calls===0&&!$oauth->connectionAuthorized($family,1,[ManagerPreparationOAuth::DIALOGUE_SCOPE]));
    $budget=new ModeledConversionStatusBudget();
    $context=ManagerPreparationOAuth::bearerContext('Bearer '.$tokens['access_token'],$budget);
    $beforeRead=$budget->remainingBytes();
    $result=$service->handle(forecastMcpRpc(),$context['admin_id'],$context['connection_id'],$context['scopes'],$budget);
    forecastMcpCheck('fresh forecast-only grant reaches the private reader with shared authorization byte accounting',
        ($result['result']['structuredContent']['saved_rows_checked']??null)===1&&$calls===1
        &&$beforeRead<65536&&$budget->remainingBytes()<$beforeRead);
    $credentialRows=static fn():array=>[
        $pdo->query('SELECT * FROM manager_preparation_oauth_grants ORDER BY grant_id')->fetchAll(),
        $pdo->query('SELECT * FROM manager_preparation_oauth_tokens ORDER BY token_hash')->fetchAll()];
    $credentialsBefore=$credentialRows();
    for($repeat=0;$repeat<2;$repeat++){
        $repeatBudget=new ModeledConversionStatusBudget();
        $repeatContext=ManagerPreparationOAuth::bearerContext('Bearer '.$tokens['access_token'],$repeatBudget);
        $repeatResult=$service->handle(forecastMcpRpc(),$repeatContext['admin_id'],$repeatContext['connection_id'],$repeatContext['scopes'],$repeatBudget);
        forecastMcpCheck('repeated read uses the same unchanged authorized grant '.$repeat,
            $repeatContext['connection_id']===$family&&($repeatResult['result']['structuredContent']['saved_rows_checked']??null)===1
            &&$credentialRows()===$credentialsBefore);
    }
    foreach([['query'=>'private'],['limit'=>100],['path'=>'/etc/passwd']]as$args){
        $count=$calls;$r=$service->handle(forecastMcpRpc('tools/call',['name'=>ModeledConversionStatusMcpAdapter::NAME,'arguments'=>$args]),1,$family,['forecasts:read']);
        forecastMcpCheck('status has no arbitrary query path or scope argument '.array_key_first($args),($r['error']['code']??null)===-32602&&$calls===$count);
    }
    foreach([0,2,3,4]as$actor){
        $count=$calls;$r=$service->handle(forecastMcpRpc(),$actor,$family,['forecasts:read']);
        forecastMcpCheck('current active AnyTour administrator is required '.$actor,($r['error']['code']??null)===-32001&&$calls===$count);
    }
    $r=$service->handle(forecastMcpRpc(),1,$legacy,['forecasts:read']);
    forecastMcpCheck('caller-supplied scopes cannot elevate an old grant',($r['error']['code']??null)===-32001);
    $r=$service->handle(forecastMcpRpc(),1,null,['forecasts:read']);
    forecastMcpCheck('a real active OAuth connection is mandatory',($r['error']['code']??null)===-32001);
    $refresh=['grant_type'=>'refresh_token','client_id'=>$client,'resource'=>ManagerPreparationOAuth::RESOURCE,
        'refresh_token'=>$legacyTokens['refresh_token'],'scope'=>'queue:read prepared:write archive:read forecasts:read'];
    try{$oauth->exchange($refresh);$raised=false;}catch(InvalidArgumentException $e){$raised=true;}
    forecastMcpCheck('refresh cannot silently add forecast permission',$raised&&!$oauth->connectionAuthorized($legacy,1,['forecasts:read']));
    foreach(['admin','project','grant']as$kind){
        [$p,$o,$c]=forecastMcpFixture();[, $g]=forecastMcpGrant($o,$c,'forecasts:read');
        $s=forecastMcpService($p,static function()use($p,$g,$kind):array{
            if($kind==='admin')$p->exec('UPDATE managers SET is_active=0 WHERE id=1');
            elseif($kind==='project')$p->exec('DELETE FROM manager_projects WHERE manager_id=1');
            else $p->prepare('UPDATE manager_preparation_oauth_grants SET revoked_at=? WHERE grant_id=?')->execute([time(),$g]);
            return ['ok'=>true,'private'=>'PRIVATE_MUST_NOT_ESCAPE'];
        });
        $r=$s->handle(forecastMcpRpc(),1,$g,['forecasts:read']);
        forecastMcpCheck('revocation during read suppresses the whole private response '.$kind,
            ($r['error']['code']??null)===-32001&&!str_contains(json_encode($r),'PRIVATE_MUST_NOT_ESCAPE'));
    }
    [$p,$o,$c]=forecastMcpFixture();[$t,$g]=forecastMcpGrant($o,$c,'forecasts:read');
    $time=1.0;$b=new ModeledConversionStatusBudget(1.0,static function()use(&$time):float{return $time;});
    $s=forecastMcpService($p,static function()use(&$time):array{$time=6.1;return ['ok'=>true,'private'=>'PRIVATE_TOO_LATE'];});
    $r=$s->handle(forecastMcpRpc(),1,$g,['forecasts:read'],$b);
    forecastMcpCheck('deadline remains shared through authorization read and final response',
        ($r['error']['data']['reason']??null)==='time_limit'&&!str_contains(json_encode($r),'PRIVATE_TOO_LATE'));
    $b=new ModeledConversionStatusBudget(1.0,static fn():float=>7.0);
    try{ManagerPreparationOAuth::bearerContext('Bearer '.$t['access_token'],$b);$limited=false;}
    catch(ModeledConversionStatusLimit $e){$limited=$e->reason==='time_limit';}
    forecastMcpCheck('expired execution budget also stops the initial bearer lookup',$limited);
    $s=forecastMcpService($p,static fn():array=>['ok'=>true,'private'=>str_repeat('PRIVATE_OVERSIZE',3000)]);
    $r=$s->handle(forecastMcpRpc(),1,$g,['forecasts:read']);
    forecastMcpCheck('oversized aggregate cannot escape through either MCP representation',
        ($r['error']['data']['reason']??null)==='byte_limit'&&!str_contains(json_encode($r),'PRIVATE_OVERSIZE'));
    $b=new ModeledConversionStatusBudget();$b->consumeBytes(65536);
    try{$b->consumeBytes(1);$limited=false;}catch(ModeledConversionStatusLimit $e){$limited=$e->reason==='byte_limit';}
    forecastMcpCheck('read budget is cumulative across all readers',$limited);
    $p->prepare('UPDATE manager_preparation_oauth_grants SET resource=? WHERE grant_id=?')->execute(['https://app.anytoour.ru/manager/development-mcp.php',$g]);
    $r=forecastMcpService($p,$store)->handle(forecastMcpRpc(),1,$g,['forecasts:read']);
    forecastMcpCheck('development audience cannot access the legacy forecast resource',($r['error']['code']??null)===-32001);
    if(defined(ManagerPreparationOAuth::class.'::PROFILE_DEVELOPMENT')){
        forecastMcpCheck('development-only OAuth metadata never advertises forecast reads',
            !in_array('forecasts:read',ManagerPreparationOAuth::authorizationMetadata(ManagerPreparationOAuth::PROFILE_DEVELOPMENT)['scopes_supported'],true));
        $development=new ManagerPreparationOAuth($p,null,ManagerPreparationOAuth::PROFILE_DEVELOPMENT);
        $devClient=$development->registerClient(['redirect_uris'=>[ManagerPreparationOAuth::REDIRECT_URI]],'forecast-development-fixture')['client_id'];
        [$devTokens,$devFamily]=forecastMcpGrant($development,$devClient,ManagerPreparationOAuth::DEVELOPMENT_SCOPE,ManagerPreparationOAuth::DEVELOPMENT_RESOURCE);
        $r=forecastMcpService($p,$store)->handle(forecastMcpRpc(),1,$devFamily,[ManagerPreparationOAuth::DEVELOPMENT_SCOPE]);
        forecastMcpCheck('a real development-only grant cannot authenticate or read the private forecast resource',
            ManagerPreparationOAuth::bearerContext('Bearer '.$devTokens['access_token'])===null&&($r['error']['code']??null)===-32001);
        try{forecastMcpGrant($development,$devClient,'forecasts:read',ManagerPreparationOAuth::DEVELOPMENT_RESOURCE);$rejected=false;}
        catch(InvalidArgumentException $e){$rejected=true;}
        forecastMcpCheck('development issuer cannot issue forecast permission',$rejected);
    }
    [$faultPdo,$faultOauth,$faultClient]=forecastMcpFixture();
    [$faultTokens]=forecastMcpGrant($faultOauth,$faultClient,'forecasts:read');
    $faultPdo->exec('ALTER TABLE manager_preparation_oauth_tokens RENAME TO unavailable_tokens');
    try{
        try{ManagerPreparationOAuth::bearerContext('Bearer '.$faultTokens['access_token'],new ModeledConversionStatusBudget());$technical=false;}
        catch(ManagerPreparationOAuthLookupUnavailable $e){$technical=$e->getMessage()==='forecast_authorization_unavailable'&&$e->getPrevious()===null;}
        forecastMcpCheck('technical forecast lookup failure has a fixed noncredential error',$technical);
        forecastMcpCheck('legacy lookup failure compatibility is retained',ManagerPreparationOAuth::bearerContext('Bearer '.$faultTokens['access_token'])===null);
    }finally{$faultPdo->exec('ALTER TABLE unavailable_tokens RENAME TO manager_preparation_oauth_tokens');}
    forecastMcpCheck('invalid forecast credential remains ordinary authentication denial',
        ManagerPreparationOAuth::bearerContext('Bearer '.str_repeat('v',43),new ModeledConversionStatusBudget())===null);
    $endpoint=file_get_contents(dirname(__DIR__).'/manager/ai-mcp.php');
    forecastMcpCheck('endpoint passes one entry-time budget into bearer and dispatch',
        str_contains($endpoint,'new ModeledConversionStatusBudget($preparationMcpStartedAt)')
        &&str_contains($endpoint,'bearerContext($header,$preparationMcpForecastBudget)')
        &&str_contains($endpoint,"$"."authorization['scopes'],$"."preparationMcpForecastBudget)"));
}

/** Exact HTTP entry point with isolated synthetic auth/dispatch and no production bootstrap. */
function forecastMcpHttpAssertions():void
{
    $tmp=sys_get_temp_dir().'/forecast-status-http-'.bin2hex(random_bytes(6));$server=null;
    $canonicalChallenge=(new ReflectionMethod(ManagerPreparationMcpService::class,'capabilityScopeChallenge'))
        ->invoke(null,['queue:read','prepared:write','archive:read'],'forecasts:read');
    try{
        mkdir($tmp,0700);mkdir($tmp.'/manager',0700);mkdir($tmp.'/services',0700);mkdir($tmp.'-private',0700);
        foreach(['ModeledConversionStatusBudget','DiagnosticLogger']as$name)
            copy(dirname(__DIR__).'/services/'.$name.'.php',$tmp.'/services/'.$name.'.php');
        $diagnostics=(string)file_get_contents(dirname(__DIR__).'/services/ManagerPreparationMcpDiagnostics.php');
        file_put_contents($tmp.'/services/ActualMcpDiagnostics.php',str_replace('final class ManagerPreparationMcpDiagnostics','final class ForecastFixtureDiagnostics',$diagnostics));
        file_put_contents($tmp.'/services/ManagerPreparationMcpDiagnostics.php',<<<'PHP'
<?php
require_once __DIR__.'/ActualMcpDiagnostics.php';
final class ManagerPreparationMcpDiagnostics{
public static function record(array $server,?array $rpc,int $status,string $stage,?int $error,string $surface='legacy'):void{
ForecastFixtureDiagnostics::record($server,$rpc,$status,$stage,$error,$surface);
if(($server['HTTP_X_FIXTURE_EXPIRE_AFTER_RECORD']??'')==='yes'&&$GLOBALS['preparationMcpForecastBudget']!==null)
    (new ReflectionProperty(ModeledConversionStatusBudget::class,'startedAt'))->setValue($GLOBALS['preparationMcpForecastBudget'],microtime(true)-6.0);
}}
PHP);
        copy(dirname(__DIR__).'/manager/ai-mcp.php',$tmp.'/manager/ai-mcp.php');
        file_put_contents($tmp.'/challenge.php',"<?php return ".var_export($canonicalChallenge,true).";");
        file_put_contents($tmp.'/config.php',<<<'PHP'
<?php
require_once __DIR__.'/services/ModeledConversionStatusBudget.php';
require_once __DIR__.'/services/DiagnosticLogger.php';
DiagnosticLogger::setFile(__DIR__.'-private/events.log');
if(($_SERVER['HTTP_X_FIXTURE_EXPIRED']??'')==='yes')$preparationMcpStartedAt-=6.0;
final class ManagerPreparationOAuthLookupUnavailable extends RuntimeException{}
final class ManagerPreparationOAuth{
public static function bearerContext(string $header,?ModeledConversionStatusBudget $budget=null):?array{
if(($_SERVER['HTTP_X_FIXTURE_STORAGE_ERROR']??'')==='yes')throw new ManagerPreparationOAuthLookupUnavailable('PRIVATE_SQL_MUST_NOT_ESCAPE');
if($header==='Bearer '.str_repeat('l',43))return ['admin_id'=>7,'connection_id'=>str_repeat('b',32),'scopes'=>['queue:read','prepared:write','archive:read']];
if($header!=='Bearer '.str_repeat('x',43))return null;
$budget?->consumeBytes(32);
return ['admin_id'=>7,'connection_id'=>str_repeat('a',32),'scopes'=>['forecasts:read']];}
}
final class ManagerPreparationMcpService{
public function handle(array $rpc,int $actor,?string $connection,array $scopes,?ModeledConversionStatusBudget $budget=null):array{
if(!in_array('forecasts:read',$scopes,true))return ['jsonrpc'=>'2.0','id'=>$rpc['id'],'result'=>require __DIR__.'/challenge.php'];
return ['jsonrpc'=>'2.0','id'=>$rpc['id'],'result'=>['ok'=>true,'budget_received'=>$budget!==null,'remaining_bytes'=>$budget?->remainingBytes()]];}}
PHP);
        foreach(['ManagerPreparationOAuth','ManagerPreparationMcpService']as$name)
            file_put_contents($tmp.'/services/'.$name.'.php',"<?php require_once dirname(__DIR__).'/config.php';");
        $socket=stream_socket_server('tcp://127.0.0.1:0',$errno,$error);if(!$socket)throw new RuntimeException('fixture_socket');
        $address=stream_socket_get_name($socket,false);fclose($socket);
        $server=proc_open([PHP_BINARY,'-d','display_errors=0','-S',$address,'-t',$tmp],[0=>['pipe','r'],1=>['file',$tmp.'/server.log','a'],2=>['file',$tmp.'/server.log','a']],$pipes);
        if(!is_resource($server))throw new RuntimeException('fixture_server');fclose($pipes[0]);
        for($i=0;$i<100;$i++){if($socket=@stream_socket_client('tcp://'.$address,$errno,$error,.03)){fclose($socket);break;}usleep(10000);}
        $request=static function(array $rpc,array $extra=[],?string $authorization=null)use($address):array{
            $authorization??='Bearer '.str_repeat('x',43);
            $headers=['Content-Type: application/json',...($authorization!==''?['Authorization: '.$authorization]:[]),...$extra];
            $ctx=stream_context_create(['http'=>['method'=>'POST','header'=>implode("\r\n",$headers),'content'=>json_encode($rpc,JSON_THROW_ON_ERROR),'ignore_errors'=>true,'timeout'=>10]]);
            $body=@file_get_contents('http://'.$address.'/manager/ai-mcp.php',false,$ctx);
            preg_match('/\s([0-9]{3})\s/',$http_response_header[0]??'',$match);
            $challenge=null;foreach($http_response_header??[]as$header)if(stripos($header,'WWW-Authenticate:')===0)$challenge=trim(substr($header,17));
            return ['status'=>(int)($match[1]??0),'body'=>is_string($body)?json_decode($body,true):null,'challenge'=>$challenge];
        };
        $r=$request(forecastMcpRpc());
        forecastMcpCheck('HTTP recognizer shares initial auth accounting with tool dispatch',
            $r['status']===200&&($r['body']['result']['budget_received']??false)
            &&($r['body']['result']['remaining_bytes']??0)===65504&&$r['challenge']===null);
        foreach(['','malformed','Bearer '.str_repeat('y',43)]as$i=>$credential){
            $r=$request(forecastMcpRpc(),[],$credential);
            forecastMcpCheck('absent malformed and invalid forecast credentials request only forecast consent '.$i,
                $r['status']===401&&$r['challenge']==='Bearer resource_metadata="https://app.anytoour.ru/manager/ai-oauth.php?action=resource", scope="forecasts:read"'
                &&($r['body']['error']['code']??null)===-32001&&!isset($r['body']['result']));
        }
        $r=$request(['jsonrpc'=>'2.0','id'=>1,'method'=>'tools/list'],[],'');
        forecastMcpCheck('ordinary legacy bootstrap challenge is unchanged',$r['status']===401
            &&$r['challenge']==='Bearer resource_metadata="https://app.anytoour.ru/manager/ai-oauth.php?action=resource", scope="queue:read prepared:write"');
        $r=$request(forecastMcpRpc(),[],'Bearer '.str_repeat('l',43));
        forecastMcpCheck('forecast scope challenge mirrors canonical union in HTTP and preserves MCP body',
            $r['status']===403&&$r['challenge']===$canonicalChallenge['_meta']['mcp/www_authenticate'][0]
            &&($r['body']['result']??null)===$canonicalChallenge);
        $records=array_filter(array_map(static fn(string $line):mixed=>json_decode($line,true),file($tmp.'-private/events.log',FILE_IGNORE_NEW_LINES)?:[]));
        $last=end($records);
        forecastMcpCheck('scope challenge uses existing bounded diagnostic categories',
            ($last['data']['rpc_method']??null)==='tools/call'&&($last['data']['http_status']??null)===403
            &&($last['data']['stage']??null)==='credentials'&&($last['data']['rpc_error']??null)===-32001);
        $r=$request(forecastMcpRpc(),['X-Fixture-Storage-Error: yes']);
        forecastMcpCheck('technical forecast lookup failure returns 503 without an auth challenge',
            $r['status']===503&&$r['challenge']===null&&($r['body']['error']['code']??null)===-32603
            &&!str_contains(json_encode($r),'PRIVATE_SQL_MUST_NOT_ESCAPE'));
        $r=$request(forecastMcpRpc(),['X-Fixture-Expire-After-Record: yes'],'');
        forecastMcpCheck('late deadline after a 401 removes the previously prepared OAuth header',
            $r['status']===503&&$r['challenge']===null&&($r['body']['error']['data']['reason']??null)==='time_limit');
        $r=$request(forecastMcpRpc(),['X-Fixture-Expire-After-Record: yes'],'Bearer '.str_repeat('l',43));
        forecastMcpCheck('late deadline after scope denial removes the mirrored OAuth header',
            $r['status']===503&&$r['challenge']===null&&($r['body']['error']['data']['reason']??null)==='time_limit');
        $rpc=forecastMcpRpc();$rpc['params']['_meta']=['padding'=>str_repeat('x',66000)];$r=$request($rpc);
        forecastMcpCheck('forecast HTTP request has its own 64 KiB bound',$r['status']===413);
        $r=$request(forecastMcpRpc(),['X-Fixture-Expired: yes']);
        forecastMcpCheck('HTTP entry deadline includes time before bearer lookup',
            $r['status']===503&&$r['challenge']===null&&($r['body']['error']['data']['reason']??null)==='time_limit'&&!isset($r['body']['result']));
        $records=array_filter(array_map(static fn(string $line):mixed=>json_decode($line,true),file($tmp.'-private/events.log',FILE_IGNORE_NEW_LINES)?:[]));
        $last=end($records);
        forecastMcpCheck('HTTP budget failure retains its actual fixed error code in saved diagnostics',
            ($last['data']['http_status']??null)===$r['status']
            &&($last['data']['rpc_error']??null)===-32016
            &&$last['data']['rpc_error']===($r['body']['error']['code']??null));
    }finally{
        if(is_resource($server)){proc_terminate($server);proc_close($server);}
        if(is_dir($tmp)){
            $it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($tmp,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);
            foreach($it as$file){if($file->isDir())rmdir($file->getPathname());else unlink($file->getPathname());}
            rmdir($tmp);
        }
        @unlink($tmp.'-private/events.log');@rmdir($tmp.'-private');
    }
}


function forecastReceiptArgumentAssertions():void
{
    [$pdo,$oauth,$client]=forecastMcpFixture();
    [, $family]=forecastMcpGrant($oauth,$client,ManagerPreparationOAuth::FORECAST_SCOPE);
    $calls=0;$seen=null;$reference=hash('sha256','modeled_value_upload_receipt_v1|770010');
    $store=static function(int $admin,string $connection,ModeledConversionStatusBudget $budget,array $args)use(&$calls,&$seen):array{
        ++$calls;$seen=$args;return ['available'=>true,'receipt_ref'=>$args['receipt_ref'],'provider_requests'=>0,'upload_status'=>'PROCESSED'];
    };
    $service=forecastMcpService($pdo,$store);
    $rpc=forecastMcpRpc('tools/call',['name'=>ModeledConversionStatusMcpAdapter::NAME,'arguments'=>['receipt_ref'=>$reference]]);
    $r=$service->handle($rpc,1,$family,[ManagerPreparationOAuth::FORECAST_SCOPE]);
    forecastMcpCheck('receipt selection uses the unchanged forecast consent and exact opaque reference',
        ($r['result']['structuredContent']['receipt_ref']??null)===$reference&&$calls===1&&$seen===['receipt_ref'=>$reference]);
    foreach([['receipt_ref'=>strtoupper($reference)],['receipt_ref'=>null],['receipt_ref'=>$reference,'limit'=>20],['receipt_ref'=>'770010']]as$args){
        $before=$calls;$bad=forecastMcpRpc('tools/call',['name'=>ModeledConversionStatusMcpAdapter::NAME,'arguments'=>$args]);
        $r=$service->handle($bad,1,$family,[ManagerPreparationOAuth::FORECAST_SCOPE]);
        forecastMcpCheck('receipt input refuses aliases nativeIDs mixedscope or malformed references '.json_encode(array_keys($args)),
            ($r['error']['code']??null)===-32602&&$calls===$before);
    }
    [, $legacy]=forecastMcpGrant($oauth,$client,'queue:read prepared:write archive:read');$before=$calls;
    $old=$service->handle($rpc,1,$legacy,[ManagerPreparationOAuth::FORECAST_SCOPE]);
    forecastMcpCheck('the receipt argument never elevates an old archive-only connection',
        ($old['error']['code']??null)===-32001&&$calls===$before);
    $pdo->exec('UPDATE managers SET is_active=0 WHERE id=1');$before=$calls;
    $r=$service->handle($rpc,1,$family,[ManagerPreparationOAuth::FORECAST_SCOPE]);
    forecastMcpCheck('receipt selection cannot bypass a revoked administrator',($r['error']['code']??null)===-32001&&$calls===$before);
}


function forecastVisitReconciliationAssertions():void
{
    [$pdo,$oauth,$client]=forecastMcpFixture();
    [, $family]=forecastMcpGrant($oauth,$client,ManagerPreparationOAuth::FORECAST_SCOPE);
    $calls=0;$service=forecastMcpService($pdo,static function()use(&$calls):array{++$calls;return ['available'=>true];});
    $tool=ModeledConversionStatusMcpAdapter::tools()[0];
    forecastMcpCheck('saved visit selection advertises only the existing read-only forecast capability',
        ($tool['inputSchema']['properties']['visit_reconciliation']['type']??null)==='boolean'
        &&($tool['inputSchema']['properties']['visit_reconciliation']['const']??null)===true
        &&$tool['securitySchemes']===[['type'=>'oauth2','scopes'=>[ManagerPreparationOAuth::FORECAST_SCOPE]]]);
    foreach([['visit_reconciliation'=>false],['visit_reconciliation'=>1],['visit_reconciliation'=>'true'],
        ['visit_reconciliation'=>null],['visit_reconciliation'=>true,'receipt_ref'=>str_repeat('a',64)],
        ['visit_reconciliation'=>true,'primary_source_review'=>true],['visit_reconciliation'=>true,'path'=>'PRIVATE_VISIT_PATH']]as$args){
        $r=$service->handle(forecastMcpRpc('tools/call',['name'=>ModeledConversionStatusMcpAdapter::NAME,'arguments'=>$args]),1,$family,['forecasts:read']);
        forecastMcpCheck('saved visit selection rejects coercion and mixed arguments '.json_encode(array_keys($args)),
            ($r['error']['code']??null)===-32602&&$calls===0&&!str_contains(json_encode($r),'PRIVATE_VISIT_PATH'));
    }
    [, $legacy]=forecastMcpGrant($oauth,$client,'queue:read prepared:write archive:read');
    $rpc=forecastMcpRpc('tools/call',['name'=>ModeledConversionStatusMcpAdapter::NAME,'arguments'=>['visit_reconciliation'=>true]]);
    $denied=$service->handle($rpc,1,$legacy,['forecasts:read']);
    forecastMcpCheck('saved visit selection cannot elevate an archive-only connection',($denied['error']['code']??null)===-32001&&$calls===0);

    // Relocate unchanged runtime files, never the fixed private cache of this checkout.
    $tmp=sys_get_temp_dir().'/forecast-visit-cache-'.bin2hex(random_bytes(6));
    try{
        mkdir($tmp,0700);mkdir($tmp.'/app',0700);mkdir($tmp.'/app/services',0700);
        foreach(glob(dirname(__DIR__).'/services/*.php')?:[]as$file)
            file_put_contents($tmp.'/app/services/'.basename($file),'<?php require_once '.var_export($file,true).';');
        foreach(['ManagerPreparationMcpService','ModeledConversionStatusMcpAdapter','ModeledConversionVisitProof']as$name)
            copy(dirname(__DIR__).'/services/'.$name.'.php',$tmp.'/app/services/'.$name.'.php');
        $script="<?php\ndeclare(strict_types=1);\nrequire __DIR__.'/app/services/ManagerPreparationMcpService.php';\n";
        $source=file(__FILE__);
        foreach(['forecastMcpFixture','forecastMcpGrant','forecastMcpRpc']as$name){
            $f=new ReflectionFunction($name);$script.=implode('',array_slice($source,$f->getStartLine()-1,$f->getEndLine()-$f->getStartLine()+1))."\n";
        }
        $script.= <<<'PHP'
[$pdo,$oauth,$client]=forecastMcpFixture();
[$tokens,$family]=forecastMcpGrant($oauth,$client,ManagerPreparationOAuth::FORECAST_SCOPE);
require_once __DIR__.'/app/services/ModeledConversionVisitProof.php';
$now=time();$at=gmdate('Y-m-d\TH:i:s\Z',$now);$checkpoint=gmdate('Y-m-d\TH:i:s\Z',$now-300);
$events=[];
foreach([[140,'PROCESSED','unique_visit_reconciled',true],[260,'PROCESSED','unverified',true],
    [330,'UPLOADED','not_checked',true],[470,'PROCESSED','unique_visit_reconciled',true],[1100,'PROCESSED','unique_visit_reconciled',false]]as$i=>$v){
    $events[]=['outbox_id'=>(string)(810001+$i),'conversation_id'=>(string)(820001+$i),
        'client_id'=>'PRIVATE_VISIT_CLIENT','upload_id'=>'PRIVATE_VISIT_UPLOAD','fingerprint'=>hash('sha256','synthetic-visit-'.$i),
        'platform'=>'max','stage'=>'specific_request','increment_rub'=>$v[0],'signal_at'=>$checkpoint,'cohort'=>$v[3],
        'checked_at'=>$at,'upload_status'=>$v[1],'linkage'=>$v[2],
        'reason'=>$v[2]==='unique_visit_reconciled'?'client_and_visit_start_count_and_value_reconciled':($v[2]==='unverified'?'other_receipts_unavailable':'provider_processing'),
        'visit_started_at'=>null,'visit_goal_reaches'=>null,'visit_goal_value_rub'=>null,'expected_goal_reaches'=>null,'expected_goal_value_rub'=>null];
}
$receipt=['version'=>ModeledConversionVisitProof::VERSION,'project_key'=>'anytour','counter_id'=>'98615635','goal_id'=>'678187097','currency'=>'RUB',
    'checked_at'=>$at,'checkpoint_at'=>$checkpoint,'basis'=>'unique_client_and_second_precision_visit_start_reconciliation_not_native_visit_id_or_event_log',
    'ledger_complete'=>true,'selected_rows'=>5,'other_rows_omitted'=>0,'events'=>$events,'provider_write_requests'=>0,'source_writes'=>0,
    'private_fixture_padding'=>str_repeat('x',32000)];
$encoded=json_encode($receipt,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
$raw=json_encode(['receipt'=>$receipt,'sha256'=>hash('sha256',$encoded)],JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
$path=ModeledConversionVisitProof::path();mkdir(dirname($path),0700);file_put_contents($path,$raw);chmod($path,0600);
$tables=$pdo->query("SELECT name FROM sqlite_master WHERE type='table' ORDER BY name")->fetchAll(PDO::FETCH_COLUMN);
$snapshot=static function()use($pdo,$tables):array{$out=[];foreach($tables as$t)$out[$t]=$pdo->query('SELECT * FROM '.$t)->fetchAll();return$out;};
$before=$snapshot();$files=scandir(dirname($path));$pdo->exec('PRAGMA query_only=ON');
$budget=new ModeledConversionStatusBudget();$context=ManagerPreparationOAuth::bearerContext('Bearer '.$tokens['access_token'],$budget);
$rpc=forecastMcpRpc('tools/call',['name'=>ModeledConversionStatusMcpAdapter::NAME,'arguments'=>['visit_reconciliation'=>true]]);
$service=new ManagerPreparationMcpService($pdo);
$response=$service->handle($rpc,$context['admin_id'],$context['connection_id'],$context['scopes'],$budget);
$data=$response['result']['structuredContent']??[];$json=json_encode($response);
$checks=[];
$checks['real OAuth dispatcher reads the isolated saved cache without any business tables']=($data['available']??false)===true
    &&($data['checkpoint_at']??null)===$checkpoint&&($data['cohort_events']??null)===4&&($data['cohort_value_rub']??null)===1200;
$checks['saved visit projection preserves processed reconciled and unresolved outcomes of the original cohort']=
    ($data['processed_events']??null)===3&&($data['processed_value_rub']??null)===870
    &&($data['reconciled_events']??null)===2&&($data['reconciled_value_rub']??null)===610
    &&array_column($data['examples']??[],'linkage')===['unique_visit_reconciled','unverified','not_checked','unique_visit_reconciled'];
$checks['native identities unrelated events and private cache padding never leave the saved projection']=
    !str_contains($json,'PRIVATE_VISIT')&&!str_contains($json,'81000')&&!str_contains($json,'82000')
    &&!str_contains($json,$events[0]['fingerprint'])&&!str_contains($json,'private_fixture_padding')
    &&!in_array(1100,array_column($data['examples']??[],'increment_rub'),true);
$checks['saved visit read preserves cache and OAuth rows while charging the unchanged shared budget']=
    file_get_contents($path)===$raw&&scandir(dirname($path))===$files&&$snapshot()===$before
    &&$budget->remainingBytes()<65536-strlen($raw)&&$budget->remainingBytes()>0;
$limited=new ModeledConversionStatusBudget();$limited->consumeBytes(30000);
$r=$service->handle($rpc,1,$family,['forecasts:read'],$limited);
$checks['saved cache retains explicit headroom refusal instead of uncharged data']=
    ($r['result']['structuredContent']['available']??true)===false&&($r['result']['structuredContent']['reason']??null)==='budget_headroom';
$pdo->exec('PRAGMA query_only=OFF');$revoked=false;$revocationBudget=null;
$bytes=new ReflectionProperty(ModeledConversionStatusBudget::class,'bytes');
$revocationBudget=new ModeledConversionStatusBudget(microtime(true),static function()use($pdo,$family,&$revoked,&$revocationBudget,$bytes,$raw):float{
    if(!$revoked&&$revocationBudget!==null&&$bytes->getValue($revocationBudget)>=strlen($raw)){
        $pdo->prepare('UPDATE manager_preparation_oauth_grants SET revoked_at=? WHERE grant_id=?')->execute([time(),$family]);$revoked=true;
    }
    return microtime(true);
});
$r=$service->handle($rpc,1,$family,['forecasts:read'],$revocationBudget);
$checks['grant revoked after the actual cache read suppresses the complete private response']=$revoked
    &&($r['error']['code']??null)===-32001&&!isset($r['result'])&&!str_contains(json_encode($r),'cohort_value_rub');
echo json_encode($checks,JSON_THROW_ON_ERROR)."\n";
PHP;
        file_put_contents($tmp.'/fixture.php',$script);
        $process=proc_open([PHP_BINARY,'-d','allow_url_fopen=0','-d','disable_functions=curl_init,curl_exec,curl_multi_exec,fsockopen,pfsockopen,stream_socket_client',
            $tmp.'/fixture.php'],[0=>['pipe','r'],1=>['pipe','w'],2=>['file',$tmp.'/error.log','w']],$pipes);
        if(!is_resource($process))throw new RuntimeException('visit_fixture_process');fclose($pipes[0]);
        $output=stream_get_contents($pipes[1]);fclose($pipes[1]);$exit=proc_close($process);$checks=json_decode($output,true);
        forecastMcpCheck('actual saved visit fixture completes with provider transports disabled',$exit===0&&is_array($checks)&&count($checks)===6);
        foreach($checks as$name=>$ok)forecastMcpCheck($name,$ok===true);
    }finally{
        if(is_dir($tmp)){
            $it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($tmp,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);
            foreach($it as$file){if($file->isDir())rmdir($file->getPathname());else unlink($file->getPathname());}rmdir($tmp);
        }
    }
}

function forecastPrimarySourceArgumentAssertions():void
{
    [$pdo,$oauth,$client]=forecastMcpFixture();
    [, $family]=forecastMcpGrant($oauth,$client,ManagerPreparationOAuth::FORECAST_SCOPE);
    $calls=0;$seen=null;
    $store=static function(int $admin,string $connection,ModeledConversionStatusBudget $budget,array $args)use(&$calls,&$seen):array{
        ++$calls;$seen=$args;return ['available'=>true,'primary_source_review'=>true,'financial_permission'=>false,'provider_requests'=>0];
    };
    $service=forecastMcpService($pdo,$store);
    $rpc=forecastMcpRpc('tools/call',['name'=>ModeledConversionStatusMcpAdapter::NAME,'arguments'=>['primary_source_review'=>true]]);
    $tool=ModeledConversionStatusMcpAdapter::tools()[0];
    $r=$service->handle($rpc,1,$family,[ManagerPreparationOAuth::FORECAST_SCOPE]);
    forecastMcpCheck('primary source review uses the same forecast grant and literal single-purpose selection',
        ($r['result']['structuredContent']['primary_source_review']??null)===true&&$calls===1&&$seen===['primary_source_review'=>true]
        &&$tool['inputSchema']['properties']['primary_source_review']['const']===true
        &&$tool['securitySchemes']===[['type'=>'oauth2','scopes'=>[ManagerPreparationOAuth::FORECAST_SCOPE]]]);
    foreach([['primary_source_review'=>false],['primary_source_review'=>1],['primary_source_review'=>'true'],
        ['primary_source_review'=>null],['primary_source_review'=>true,'receipt_ref'=>str_repeat('a',64)],
        ['primary_source_review'=>true,'path'=>'PRIVATE_PATH_MUST_NOT_ESCAPE']]as$args){
        $before=$calls;$bad=forecastMcpRpc('tools/call',['name'=>ModeledConversionStatusMcpAdapter::NAME,'arguments'=>$args]);
        $r=$service->handle($bad,1,$family,[ManagerPreparationOAuth::FORECAST_SCOPE]);
        forecastMcpCheck('primary selection rejects coercion mixed receipt and arbitrary paths before any store '.json_encode(array_keys($args)),
            ($r['error']['code']??null)===-32602&&$calls===$before&&!str_contains(json_encode($r),'PRIVATE_PATH'));
    }
    [, $legacy]=forecastMcpGrant($oauth,$client,'queue:read prepared:write archive:read');$before=$calls;
    $denied=$service->handle($rpc,1,$legacy,[ManagerPreparationOAuth::FORECAST_SCOPE]);
    forecastMcpCheck('primary review never expands an old archive-only connection',
        ($denied['error']['code']??null)===-32001&&$calls===$before);
    $revoking=forecastMcpService($pdo,static function()use($pdo):array{
        $pdo->exec('UPDATE managers SET is_active=0 WHERE id=1');
        return ['private'=>'PRIMARY_SOURCE_MUST_NOT_ESCAPE'];
    });
    $r=$revoking->handle($rpc,1,$family,[ManagerPreparationOAuth::FORECAST_SCOPE]);
    forecastMcpCheck('primary review rechecks authorization after private access and withholds a revoked result',
        ($r['error']['code']??null)===-32001&&!str_contains(json_encode($r),'PRIMARY_SOURCE_MUST_NOT_ESCAPE'));
}

require_once __DIR__.'/modeled_conversion_saved_receipt_fixture.php';
forecastMcpAssertions();
forecastMcpHttpAssertions();
forecastReceiptArgumentAssertions();
forecastPrimarySourceArgumentAssertions();
forecastVisitReconciliationAssertions();
forecastSavedReceiptAssertions(new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]),'forecastMcpCheck');
echo 'FORECAST STATUS MCP PASSED '.$passed.PHP_EOL;
