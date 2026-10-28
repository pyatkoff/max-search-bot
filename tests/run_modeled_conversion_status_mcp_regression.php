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
    forecastMcpCheck('all supported capabilities fit the existing exact grant column',strlen($all)===93&&strlen($all)<=100);
    $budget=new ModeledConversionStatusBudget();
    $context=ManagerPreparationOAuth::bearerContext('Bearer '.$tokens['access_token'],$budget);
    $beforeRead=$budget->remainingBytes();
    $result=$service->handle(forecastMcpRpc(),$context['admin_id'],$context['connection_id'],$context['scopes'],$budget);
    forecastMcpCheck('fresh forecast-only grant reaches the private reader with shared authorization byte accounting',
        ($result['result']['structuredContent']['saved_rows_checked']??null)===1&&$calls===1
        &&$beforeRead<65536&&$budget->remainingBytes()<$beforeRead);
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
    try{
        mkdir($tmp,0700);mkdir($tmp.'/manager',0700);mkdir($tmp.'/services',0700);mkdir($tmp.'-private',0700);
        foreach(['ModeledConversionStatusBudget','ManagerPreparationMcpDiagnostics','DiagnosticLogger']as$name)
            copy(dirname(__DIR__).'/services/'.$name.'.php',$tmp.'/services/'.$name.'.php');
        copy(dirname(__DIR__).'/manager/ai-mcp.php',$tmp.'/manager/ai-mcp.php');
        file_put_contents($tmp.'/config.php',<<<'PHP'
<?php
require_once __DIR__.'/services/ModeledConversionStatusBudget.php';
require_once __DIR__.'/services/DiagnosticLogger.php';
DiagnosticLogger::setFile(__DIR__.'-private/events.log');
if(($_SERVER['HTTP_X_FIXTURE_EXPIRED']??'')==='yes')$preparationMcpStartedAt-=6.0;
final class ManagerPreparationOAuth{
public static function bearerContext(string $header,?ModeledConversionStatusBudget $budget=null):?array{
if($header!=='Bearer '.str_repeat('x',43))return null;
$budget?->consumeBytes(32);
return ['admin_id'=>7,'connection_id'=>str_repeat('a',32),'scopes'=>['forecasts:read']];}
}
final class ManagerPreparationMcpService{
public function handle(array $rpc,int $actor,?string $connection,array $scopes,?ModeledConversionStatusBudget $budget=null):array{
return ['jsonrpc'=>'2.0','id'=>$rpc['id'],'result'=>['ok'=>true,'budget_received'=>$budget!==null,'remaining_bytes'=>$budget?->remainingBytes()]];}}
PHP);
        foreach(['ManagerPreparationOAuth','ManagerPreparationMcpService']as$name)
            file_put_contents($tmp.'/services/'.$name.'.php',"<?php require_once dirname(__DIR__).'/config.php';");
        $socket=stream_socket_server('tcp://127.0.0.1:0',$errno,$error);if(!$socket)throw new RuntimeException('fixture_socket');
        $address=stream_socket_get_name($socket,false);fclose($socket);
        $server=proc_open([PHP_BINARY,'-d','display_errors=0','-S',$address,'-t',$tmp],[0=>['pipe','r'],1=>['file',$tmp.'/server.log','a'],2=>['file',$tmp.'/server.log','a']],$pipes);
        if(!is_resource($server))throw new RuntimeException('fixture_server');fclose($pipes[0]);
        for($i=0;$i<100;$i++){if($socket=@stream_socket_client('tcp://'.$address,$errno,$error,.03)){fclose($socket);break;}usleep(10000);}
        $request=static function(array $rpc,array $extra=[])use($address):array{
            $headers=['Content-Type: application/json','Authorization: Bearer '.str_repeat('x',43),...$extra];
            $ctx=stream_context_create(['http'=>['method'=>'POST','header'=>implode("\r\n",$headers),'content'=>json_encode($rpc,JSON_THROW_ON_ERROR),'ignore_errors'=>true,'timeout'=>10]]);
            $body=@file_get_contents('http://'.$address.'/manager/ai-mcp.php',false,$ctx);
            preg_match('/\\s([0-9]{3})\\s/',$http_response_header[0]??'',$match);
            return ['status'=>(int)($match[1]??0),'body'=>is_string($body)?json_decode($body,true):null];
        };
        $r=$request(forecastMcpRpc());
        forecastMcpCheck('HTTP recognizer shares initial auth accounting with tool dispatch',
            $r['status']===200&&($r['body']['result']['budget_received']??false)
            &&($r['body']['result']['remaining_bytes']??0)===65504);
        $rpc=forecastMcpRpc();$rpc['params']['_meta']=['padding'=>str_repeat('x',66000)];$r=$request($rpc);
        forecastMcpCheck('forecast HTTP request has its own 64 KiB bound',$r['status']===413);
        $r=$request(forecastMcpRpc(),['X-Fixture-Expired: yes']);
        forecastMcpCheck('HTTP entry deadline includes time before bearer lookup',
            $r['status']===503&&($r['body']['error']['data']['reason']??null)==='time_limit'&&!isset($r['body']['result']));
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

forecastMcpAssertions();
forecastMcpHttpAssertions();
echo 'FORECAST STATUS MCP PASSED '.$passed.PHP_EOL;
