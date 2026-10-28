<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once dirname(__DIR__).'/services/ManagerPreparationMcpDiagnostics.php';
$tmp=sys_get_temp_dir().'/preparation-mcp-http-'.bin2hex(random_bytes(6));
$server=null;$passed=0;
function preparationHttpCheck(string $name,bool $ok):void{global $passed;if(!$ok)throw new RuntimeException('FAIL '.$name);++$passed;echo "PASS $name\n";}
try{
    mkdir($tmp,0700);mkdir($tmp.'/manager',0700);mkdir($tmp.'/services',0700);
    mkdir($tmp.'-private',0700);DiagnosticLogger::setFile($tmp.'-private/events.log');
    file_put_contents($tmp.'/config.php',<<<'PHP'
<?php
require_once __DIR__.'/services/DiagnosticLogger.php';
DiagnosticLogger::setFile(__DIR__.'-private/events.log');
final class ManagerPreparationOAuth{
public const PROFILE_LEGACY='legacy',PROFILE_DEVELOPMENT='development';
public const DEVELOPMENT_ENDPOINT='https://app.anytoour.ru/manager/development-oauth.php';
public function __construct($pdo=null,$clock=null,private string $profile=self::PROFILE_LEGACY){}
public function contextForBearer(string $header):?array{return $this->profile===self::PROFILE_DEVELOPMENT&&$header==='Bearer '.str_repeat('d',43)?['admin_id'=>7,'connection_id'=>str_repeat('d',32),'scopes'=>['development_work:write']]:null;}
public static function authenticateBearer(string $header):?int{return $header==='Bearer '.str_repeat('x',43)?7:null;}
public static function connectionIdForBearer(string $header):?string{return self::authenticateBearer($header)===7?str_repeat('a',32):null;}
public static function bearerContext(string $header):?array{
if($header==='Bearer '.str_repeat('y',43))return ['admin_id'=>7,'connection_id'=>str_repeat('b',32),'scopes'=>['hotel_work:write']];
if($header==='Bearer '.str_repeat('w',43))return ['admin_id'=>7,'connection_id'=>str_repeat('c',32),'scopes'=>['archive:read']];
if($header==='Bearer '.str_repeat('v',43))return ['admin_id'=>7,'connection_id'=>str_repeat('e',32),'scopes'=>['development_work:write']];
return self::authenticateBearer($header)===7?['admin_id'=>7,'connection_id'=>str_repeat('a',32),'scopes'=>['queue:read','prepared:write']]:null;}
}
final class ManagerPreparationMcpService{
public function handle(array $rpc,int $actor,?string $connection,array $scopes):array{
file_put_contents(__DIR__.'/dispatch',json_encode(['actor'=>$actor,'connection'=>$connection,'scopes'=>$scopes]));
return ['jsonrpc'=>'2.0','id'=>$rpc['id'],'result'=>['authenticated'=>true,'method'=>$rpc['method']??'']];
}}
final class DevelopmentWorkMcpService{
public function handle(array $rpc,int $actor,?string $connection,array $scopes):array{
file_put_contents(__DIR__.'/development-dispatch',json_encode(['actor'=>$actor,'connection'=>$connection,'scopes'=>$scopes]));
return ['jsonrpc'=>'2.0','id'=>$rpc['id'],'result'=>['development_authenticated'=>true,'method'=>$rpc['method']??'']];
}}
PHP);
    foreach(['ManagerPreparationOAuth','ManagerPreparationMcpService','DevelopmentWorkMcpService']as $name)file_put_contents($tmp.'/services/'.$name.'.php',"<?php require_once dirname(__DIR__).'/config.php';");
    foreach(['ManagerPreparationMcpDiagnostics','DiagnosticLogger']as $name)copy(dirname(__DIR__).'/services/'.$name.'.php',$tmp.'/services/'.$name.'.php');
    copy(dirname(__DIR__).'/manager/ai-mcp.php',$tmp.'/manager/ai-mcp.php');
    copy(dirname(__DIR__).'/manager/development-mcp.php',$tmp.'/manager/development-mcp.php');
    // Simulate CGI representations while keeping the actual incoming header
    // available to PHP's native header API. This router exists only in /tmp.
    file_put_contents($tmp.'/router.php',<<<'PHP'
<?php
$mode=$_SERVER['HTTP_X_FIXTURE_HEADER_PATH']??'';
if($mode==='redirect'){$_SERVER['REDIRECT_HTTP_AUTHORIZATION']=$_SERVER['HTTP_AUTHORIZATION']??'';unset($_SERVER['HTTP_AUTHORIZATION']);}
if($mode==='header_api')unset($_SERVER['HTTP_AUTHORIZATION']);
if($mode==='conflict')$_SERVER['REDIRECT_HTTP_AUTHORIZATION']='Bearer '.str_repeat('x',43);
if($mode==='development_conflict')$_SERVER['REDIRECT_HTTP_AUTHORIZATION']='Bearer '.str_repeat('d',43);
if($mode==='forwarded')$_SERVER['HTTP_X_FORWARDED_AUTHORIZATION']='Bearer '.str_repeat('x',43);
$path=parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH);
if(!in_array($path,['/manager/ai-mcp.php','/manager/development-mcp.php'],true)){http_response_code(404);exit;}
require __DIR__.$path;
PHP);
    $socket=stream_socket_server('tcp://127.0.0.1:0',$errno,$error);if(!$socket)throw new RuntimeException('socket_unavailable');
    $address=stream_socket_get_name($socket,false);fclose($socket);
    $server=proc_open([PHP_BINARY,'-d','display_errors=0','-S',$address,'-t',$tmp,$tmp.'/router.php'],[0=>['pipe','r'],1=>['file',$tmp.'/server.log','a'],2=>['file',$tmp.'/server.log','a']],$pipes);
    if(!is_resource($server))throw new RuntimeException('server_unavailable');fclose($pipes[0]);
    for($i=0;$i<100;++$i){if($s=@stream_socket_client('tcp://'.$address,$e,$m,.03)){fclose($s);break;}usleep(10000);}
    $request=static function(string $body,string $authorization='',string $method='POST',string $origin='',string $fixtureMode='',string $resourcePath='/manager/ai-mcp.php',string $extraHeaders='')use($address):array{
        $headers="Content-Type: application/json\r\n";
        if($authorization!=='')$headers.='Authorization: '.$authorization."\r\n";
        if($origin!=='')$headers.='Origin: '.$origin."\r\n";
        if($fixtureMode!=='')$headers.='X-Fixture-Header-Path: '.$fixtureMode."\r\n";
        $headers.=$extraHeaders;
        $ctx=stream_context_create(['http'=>['method'=>$method,'header'=>$headers,'content'=>$body,'ignore_errors'=>true,'timeout'=>3]]);
        $raw=file_get_contents('http://'.$address.$resourcePath,false,$ctx);
        $responseHeaders=$http_response_header??[];
        preg_match('~HTTP/\S+\s+(\d+)~',$responseHeaders[0]??'',$match);
        return ['status'=>(int)($match[1]??0),'headers'=>implode("\n",$responseHeaders),'json'=>json_decode((string)$raw,true),'body'=>(string)$raw];
    };
    $rpc=json_encode(['jsonrpc'=>'2.0','id'=>1,'method'=>'tools/list']);$token='Bearer '.str_repeat('x',43);
    foreach(['','Bearer '.str_repeat('z',43)]as $auth){
        $r=$request($rpc,$auth);
        preparationHttpCheck('unauthenticated request cannot discover private tools',$r['status']===401&&!file_exists($tmp.'/dispatch'));
        preparationHttpCheck('OAuth resource discovery challenge is present',str_contains($r['headers'],'WWW-Authenticate: Bearer resource_metadata="https://app.anytoour.ru/manager/ai-oauth.php?action=resource"'));
        preparationHttpCheck('rejected bearer is not echoed',!str_contains($r['body'],str_repeat('z',43)));
    }
    $r=$request($rpc,$token,'POST','https://hostile.example');
    preparationHttpCheck('hostile browser origin blocked before dispatch',$r['status']===403&&!file_exists($tmp.'/dispatch'));
    $r=$request($rpc,$token,'GET');
    preparationHttpCheck('authenticated GET cannot invoke tools',$r['status']===405&&!file_exists($tmp.'/dispatch'));
    foreach(['{','[]','null',json_encode(['jsonrpc'=>'1.0','id'=>1,'method'=>'tools/list']),json_encode(['jsonrpc'=>'2.0','method'=>'tools/call']),json_encode(['jsonrpc'=>'2.0','id'=>true,'method'=>'tools/list'])]as $bad){
        $r=$request($bad,$token);preparationHttpCheck('invalid RPC is refused before dispatch',$r['status']===400&&!file_exists($tmp.'/dispatch'));
    }
    $r=$request(str_repeat('x',196609),$token);
    preparationHttpCheck('oversized authenticated request is bounded',$r['status']===413&&!file_exists($tmp.'/dispatch'));
    $r=$request(json_encode(['jsonrpc'=>'2.0','method'=>'notifications/initialized']),$token);
    preparationHttpCheck('initialization notification acknowledges without tools',$r['status']===202&&$r['body']===''&&!file_exists($tmp.'/dispatch'));
    $r=$request($rpc,$token,'POST','https://chatgpt.com');
    preparationHttpCheck('OAuth bearer and stable connection reach exact service',$r['status']===200&&$r['json']===['jsonrpc'=>'2.0','id'=>1,'result'=>['authenticated'=>true,'method'=>'tools/list']]
        &&json_decode(file_get_contents($tmp.'/dispatch'),true)===['actor'=>7,'connection'=>str_repeat('a',32),'scopes'=>['queue:read','prepared:write']]);
    preparationHttpCheck('transport remains private and is not cached',str_contains($r['headers'],'Cache-Control: no-store')&&!str_contains($r['headers'],'Set-Cookie'));
    $hotel=$request($rpc,'Bearer '.str_repeat('y',43));
    preparationHttpCheck('exact HTTP endpoint forwards hotel-only stored grant without adding reply scopes',
        $hotel['status']===200&&json_decode(file_get_contents($tmp.'/dispatch'),true)===['actor'=>7,'connection'=>str_repeat('b',32),'scopes'=>['hotel_work:write']]);
    $archive=$request($rpc,'Bearer '.str_repeat('w',43));
    preparationHttpCheck('exact HTTP endpoint forwards archive-only stored grant without adding other capabilities',
        $archive['status']===200&&json_decode(file_get_contents($tmp.'/dispatch'),true)===['actor'=>7,'connection'=>str_repeat('c',32),'scopes'=>['archive:read']]);
    $observed=ManagerPreparationMcpDiagnostics::snapshot()['recent_attempts'];
    preparationHttpCheck('actual HTTP diagnostics distinguish successful discovery and missing credentials',
        ($observed[0]['rpc_method']??null)==='tools/list'&&$observed[0]['http_status']===200
        &&$observed[0]['credential_header']==='bearer'&&end($observed)['credential_header']==='missing');
    $log=(string)file_get_contents($tmp.'-private/events.log');
    preparationHttpCheck('actual HTTP log never includes bearer or hostile origin',
        !str_contains($log,str_repeat('x',43))&&!str_contains($log,str_repeat('z',43))&&!str_contains($log,'hostile.example'));
    foreach(['redirect','header_api']as $mode){
        @unlink($tmp.'/dispatch');$r=$request($rpc,$token,'POST','',$mode);
        preparationHttpCheck('real Authorization survives CGI representation '.$mode,
            $r['status']===200&&($r['json']['result']['authenticated']??false)===true&&file_exists($tmp.'/dispatch'));
    }
    @unlink($tmp.'/dispatch');$r=$request($rpc,'Bearer '.str_repeat('z',43),'POST','','conflict');
    preparationHttpCheck('invalid direct bearer cannot be replaced by a valid redirect value',$r['status']===401&&!file_exists($tmp.'/dispatch'));
    $r=$request($rpc,'','POST','','forwarded');
    preparationHttpCheck('forwarded authorization cannot authenticate',$r['status']===401&&!file_exists($tmp.'/dispatch'));
    // A separate audience executes the actual new ingress while all identity
    // stand-ins remain synthetic; real grant checks live in the OAuth suite.
    @unlink($tmp.'/dispatch');@unlink($tmp.'/development-dispatch');
    $devPath='/manager/development-mcp.php';$devToken='Bearer '.str_repeat('d',43);
    $devChallenge='WWW-Authenticate: Bearer resource_metadata="https://app.anytoour.ru/manager/development-oauth.php?action=resource", scope="development_work:write"';
    foreach(['','Bearer '.str_repeat('z',43),$token,'Bearer '.str_repeat('y',43),'Bearer '.str_repeat('w',43),'Bearer '.str_repeat('v',43)]as $auth){
        $r=$request($rpc,$auth,'POST','', '',$devPath);
        preparationHttpCheck('new development ingress rejects missing or other-resource bearer before dispatch',
            $r['status']===401&&!file_exists($tmp.'/development-dispatch')&&!file_exists($tmp.'/dispatch'));
        preparationHttpCheck('new development challenge names only its own resource and capability',
            str_contains($r['headers'],$devChallenge)&&!str_contains($r['headers'],'scope="queue:read'));
        preparationHttpCheck('new rejected bearer is not reflected',$auth===''||!str_contains($r['body'],substr($auth,7)));
    }
    $r=$request($rpc,'','POST','','',$devPath,"Cookie: anytour_manager_panel=synthetic-browser-admin\r\n");
    preparationHttpCheck('browser cookie cannot replace the dedicated development bearer',$r['status']===401&&!file_exists($tmp.'/development-dispatch'));
    $r=$request($rpc,$devToken,'POST','https://hostile.example','',$devPath);
    preparationHttpCheck('development hostile origin is refused before dispatch',$r['status']===403&&!file_exists($tmp.'/development-dispatch'));
    foreach(['','Accept: text/event-stream'."\r\n"]as $accept){
        $r=$request($rpc,$devToken,'GET','','',$devPath,$accept);
        preparationHttpCheck('development GET and SSE cannot start a session or dispatch tools',
            $r['status']===405&&!file_exists($tmp.'/development-dispatch')&&!str_contains($r['headers'],'Set-Cookie'));
    }
    foreach(['{','[]','null',json_encode(['jsonrpc'=>'1.0','id'=>1,'method'=>'tools/list']),json_encode(['jsonrpc'=>'2.0','method'=>'tools/call']),json_encode(['jsonrpc'=>'2.0','id'=>true,'method'=>'tools/list'])]as $bad){
        $r=$request($bad,$devToken,'POST','','',$devPath);
        preparationHttpCheck('malformed development RPC cannot reach the service',$r['status']===400&&!file_exists($tmp.'/development-dispatch'));
    }
    $r=$request(str_repeat('x',196609),$devToken,'POST','','',$devPath);
    preparationHttpCheck('development request bytes have the same strict bound',$r['status']===413&&!file_exists($tmp.'/development-dispatch'));
    $r=$request(json_encode(['jsonrpc'=>'2.0','method'=>'notifications/initialized']),$devToken,'POST','','',$devPath);
    preparationHttpCheck('development initialization acknowledgement remains non-mutating',$r['status']===202&&$r['body']===''&&!file_exists($tmp.'/development-dispatch'));
    $r=$request($rpc,$devToken,'POST','https://chatgpt.com','',$devPath);
    preparationHttpCheck('new ingress selects fixed development profile and exact dedicated service',
        $r['status']===200&&($r['json']['result']['development_authenticated']??false)===true
        &&json_decode(file_get_contents($tmp.'/development-dispatch'),true)===['actor'=>7,'connection'=>str_repeat('d',32),'scopes'=>['development_work:write']]
        &&!file_exists($tmp.'/dispatch'));
    preparationHttpCheck('development response remains private without browser session cookies',
        str_contains($r['headers'],'Cache-Control: no-store')&&!str_contains($r['headers'],'Set-Cookie'));
    foreach(['redirect','header_api']as $mode){
        @unlink($tmp.'/development-dispatch');$r=$request($rpc,$devToken,'POST','',$mode,$devPath);
        preparationHttpCheck('development real Authorization survives CGI representation '.$mode,
            $r['status']===200&&file_exists($tmp.'/development-dispatch'));
    }
    @unlink($tmp.'/development-dispatch');
    $r=$request($rpc,'Bearer '.str_repeat('z',43),'POST','','development_conflict',$devPath);
    preparationHttpCheck('development invalid direct bearer cannot be replaced by a valid redirect',$r['status']===401&&!file_exists($tmp.'/development-dispatch'));
    $r=$request($rpc,'','POST','','forwarded',$devPath);
    preparationHttpCheck('development forwarded authorization is not a credential',$r['status']===401&&!file_exists($tmp.'/development-dispatch'));
    @unlink($tmp.'/dispatch');
    $r=$request($rpc,$devToken);
    preparationHttpCheck('new-audience bearer cannot enter the retained legacy endpoint',$r['status']===401&&!file_exists($tmp.'/dispatch'));
    $r=$request($rpc,'Bearer '.str_repeat('v',43));
    preparationHttpCheck('old-resource development grant still reaches its original service',
        $r['status']===200&&json_decode(file_get_contents($tmp.'/dispatch'),true)===['actor'=>7,'connection'=>str_repeat('e',32),'scopes'=>['development_work:write']]);

    // The real diagnostic matcher must not borrow another surface's probe.
    $started=time();$legacyProbe=['at'=>gmdate('c',$started),'surface'=>'legacy','client_kind'=>'header_probe',
        'credential_header'=>'bearer','http_status'=>401,'stage'=>'credentials','rpc_error'=>-32001];
    preparationHttpCheck('fresh legacy success cannot certify the development ingress',
        !ManagerPreparationMcpDiagnostics::headerProbeDelivered(['recent_attempts'=>[$legacyProbe]],$started,'development'));
    $devProbe=array_replace($legacyProbe,['surface'=>'development']);
    preparationHttpCheck('each known probe surface certifies only itself',
        ManagerPreparationMcpDiagnostics::headerProbeDelivered(['recent_attempts'=>[$devProbe]],$started,'development')
        &&ManagerPreparationMcpDiagnostics::headerProbeDelivered(['recent_attempts'=>[$legacyProbe]],$started,'legacy'));
    foreach(['absent','other','legacy']as $surface)preparationHttpCheck('development probe refuses a mismatching surface '.$surface,
        !ManagerPreparationMcpDiagnostics::headerProbeDelivered(['recent_attempts'=>[array_replace($devProbe,['surface'=>$surface])]],$started,'development'));
    foreach(['redirect_only','missing']as $kind)preparationHttpCheck('development probe requires a delivered Authorization header '.$kind,
        !ManagerPreparationMcpDiagnostics::headerProbeDelivered(['recent_attempts'=>[array_replace($devProbe,['credential_header'=>$kind])]],$started,'development'));
    try {
        ManagerPreparationMcpDiagnostics::headerProbeDelivered(['recent_attempts'=>[$devProbe]],$started,'untrusted');
        preparationHttpCheck('unknown expected probe surface fails closed',false);
    } catch (InvalidArgumentException $error) {
        preparationHttpCheck('unknown expected probe surface fails closed',$error->getMessage()==='invalid_mcp_surface');
    }
    foreach([['http_status'=>200],['stage'=>'complete'],['rpc_error'=>null],['at'=>gmdate('c',$started-1)]]as $change)
        preparationHttpCheck('development probe cannot use an incomplete or stale observation',
            !ManagerPreparationMcpDiagnostics::headerProbeDelivered(['recent_attempts'=>[array_replace($devProbe,$change)]],$started,'development'));
    $serverMeta=['REQUEST_METHOD'=>'POST','HTTP_AUTHORIZATION'=>'Bearer '.str_repeat('z',43),'HTTP_USER_AGENT'=>'AnyTour-MCP-Header-Probe/1.0',
        'REQUEST_URI'=>$devPath,'HTTP_X_MCP_SURFACE'=>'development'];
    preparationHttpCheck('client paths and headers cannot manufacture the trusted surface',
        ManagerPreparationMcpDiagnostics::metadata($serverMeta,null,401,'credentials',-32001)['surface']==='legacy'
        &&ManagerPreparationMcpDiagnostics::metadata($serverMeta,null,401,'credentials',-32001,'development')['surface']==='development');
    $probeRequestStart=time();
    $r=$request($rpc,'Bearer '.str_repeat('z',43),'POST','','',$devPath,"User-Agent: AnyTour-MCP-Header-Probe/1.0\r\n");
    preparationHttpCheck('actual development ingress records its own failed-bearer header delivery',
        $r['status']===401&&ManagerPreparationMcpDiagnostics::headerProbeDelivered(ManagerPreparationMcpDiagnostics::snapshot(),$probeRequestStart,'development'));
    $privateLog=(string)file_get_contents($tmp.'-private/events.log');
    preparationHttpCheck('two-surface diagnostics contain no bearer, cookie, URI or hostile origin',
        !str_contains($privateLog,str_repeat('d',43))&&!str_contains($privateLog,str_repeat('z',43))
        &&!str_contains($privateLog,'synthetic-browser-admin')&&!str_contains($privateLog,$devPath)&&!str_contains($privateLog,'hostile.example'));
    echo 'TOTAL '.$passed." preparation endpoint checks passed\n";
}catch(Throwable $error){fwrite(STDERR,$error->getMessage()."\n");exit(1);}finally{
    if(is_resource($server)){proc_terminate($server);proc_close($server);}
    DiagnosticLogger::setFile('');@unlink($tmp.'-private/events.log');@rmdir($tmp.'-private');
    if(is_dir($tmp)){$it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($tmp,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);foreach($it as $f){$f->isDir()?rmdir($f->getPathname()):unlink($f->getPathname());}rmdir($tmp);}
}
