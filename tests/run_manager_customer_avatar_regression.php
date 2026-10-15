<?php

declare(strict_types=1);
// Both thumbnail and full-photo cases acquire a real session lock. Retain this
// small diagnostic output until both have finished, before HTTP headers are sent.
ob_start();
require_once dirname(__DIR__).'/services/ManagerCustomerAvatarService.php';
define('TELEGRAM_BOT_TOKEN','123456:synthetic_avatar_token');
define('MAX_SEARCH_TOKEN','synthetic_max_avatar_token');
ProjectConfig::resetForTests(['id'=>'anytour']);
$pdo=new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
(new ReflectionProperty(ConversationDb::class,'pdo'))->setValue(null,$pdo);
$pdo->sqliteCreateFunction('JSON_UNQUOTE',static fn($value)=>$value);
foreach ([ProjectAccessService::class,ManagerAuthService::class,RoutingAccessService::class] as $class) (new ReflectionProperty($class,'schemaReady'))->setValue(null,true);
$pdo->exec("CREATE TABLE projects(id INTEGER PRIMARY KEY,project_key TEXT,display_name TEXT,is_active INTEGER);
CREATE TABLE manager_projects(manager_id INTEGER,project_id INTEGER);
CREATE TABLE managers(id INTEGER PRIMARY KEY,login TEXT,display_name TEXT,role TEXT,email TEXT,is_active INTEGER,is_working INTEGER);
CREATE TABLE conversations(id INTEGER PRIMARY KEY,customer_id INTEGER,customer_channel_id INTEGER,project_key TEXT,source_id INTEGER,status TEXT,manager_id INTEGER,started_at TEXT,last_message_at TEXT,channel TEXT);
CREATE TABLE messages(id INTEGER PRIMARY KEY,conversation_id INTEGER,channel TEXT,direction TEXT,sender_type TEXT,sender_id TEXT,external_message_id TEXT,metadata_json TEXT);
CREATE INDEX idx_messages_conversation ON messages(conversation_id,id);
CREATE TABLE customer_channels(id INTEGER PRIMARY KEY,customer_id INTEGER,project_key TEXT,channel TEXT,external_user_id TEXT);
CREATE TABLE conversation_sources(id INTEGER PRIMARY KEY,primary_group_id INTEGER,fallback_mode TEXT,fallback_group_id INTEGER,fallback_after_minutes INTEGER,is_active INTEGER);
CREATE TABLE manager_group_members(manager_id INTEGER,group_id INTEGER);
INSERT INTO projects VALUES(1,'anytour','Fixture',1),(2,'other','Other',1);
INSERT INTO managers VALUES(1,'one','One','manager','',1,1),(2,'outside','Outside','admin','',1,1),(3,'inactive','Inactive','manager','',0,1);
INSERT INTO manager_projects VALUES(1,1),(2,2),(3,1);
INSERT INTO customer_channels VALUES(10,10,'anytour','telegram','123'),(20,20,'anytour','telegram','456'),(30,30,'anytour','max','123'),(40,40,'other','telegram','123');
INSERT INTO conversations VALUES(100,10,10,'anytour',0,'manager',1,'2026-10-14','2026-10-14','telegram'),(200,20,20,'anytour',0,'manager',1,'2026-10-14','2026-10-14','telegram'),(300,30,30,'anytour',0,'manager',1,'2026-10-14','2026-10-14','max'),(400,40,40,'other',0,'manager',2,'2026-10-14','2026-10-14','telegram');");
$passed=0;
function avCheck(string $name,bool $ok):void {global $passed;if (!$ok) throw new RuntimeException('FAIL '.$name);$passed++;echo 'PASS '.$name."\n";}
$root=sys_get_temp_dir().'/manager-avatar-test-'.bin2hex(random_bytes(8));mkdir($root,0700);
$png=base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jpd0AAAAASUVORK5CYII=');
$calls=[];$mode='ok';$path='photos/avatar.png';$sessionReleased=true;
$fetch=static function(string $url,?array $post,int $limit,int $budget) use(&$calls,&$mode,&$path,&$sessionReleased,$png): ?string {
    $sessionReleased=$sessionReleased && session_status()!==PHP_SESSION_ACTIVE;
    $calls[]=['method'=>basename($url),'post'=>$post,'limit'=>$limit,'budget'=>$budget];
    if ($mode==='throw') throw new RuntimeException('synthetic secret provider error');
    if ($mode==='fail') return null;
    if (str_ends_with($url,'getUserProfilePhotos')) return json_encode(['ok'=>true,'result'=>['photos'=>$mode==='empty'?[]:[[['file_id'=>'synthetic-photo','width'=>64,'height'=>64,'file_size'=>200]]]]]);
    if (str_ends_with($url,'getFile')) return json_encode(['ok'=>true,'result'=>['file_path'=>$path,'file_size'=>$mode==='large'?ManagerCustomerAvatarService::MAX_BYTES+1:strlen($png)]]);
    if ($mode==='svg') return '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>';
    if ($mode==='oversize') return str_repeat('a',ManagerCustomerAvatarService::MAX_BYTES+1);
    if ($mode==='invalid') return "\x89PNG\r\n\x1a\ninvalid";
    if ($mode==='dimensions') return substr_replace($png,pack('N',2048),16,4);
    return $png;
};
$service=new ManagerCustomerAvatarService($root.'/cache',$fetch);
$slot=static fn(string $channel='telegram',string $user='123'):string=>(string)(hexdec(substr(hash('sha256',json_encode(['anytour',$channel,$user],JSON_THROW_ON_ERROR)),0,4))%ManagerCustomerAvatarService::CACHE_SLOTS);
try {
    mkdir($root.'/sessions',0700);session_save_path($root.'/sessions');session_id('avatar-fixture-'.bin2hex(random_bytes(8)));session_start();
    $photo=$service->avatar(1,100);
    avCheck('Telegram portrait is validated binary PNG',$photo!==null && $photo['bytes']===$png && $photo['mime']==='image/png');
    avCheck('session lock released before every provider operation',$sessionReleased && session_status()!==PHP_SESSION_ACTIVE);
    avCheck('bounded first photo lookup and download use three requests',count($calls)===3 && $calls[0]['post']===['user_id'=>'123','offset'=>0,'limit'=>1] && $calls[1]['post']===['file_id'=>'synthetic-photo'] && $calls[2]['limit']===ManagerCustomerAvatarService::MAX_BYTES);
    avCheck('all provider requests share short decreasing deadline',min(array_column($calls,'budget'))>0 && max(array_column($calls,'budget'))<=5000 && $calls[2]['budget']<=$calls[0]['budget']);
    $before=count($calls);avCheck('positive cache performs zero provider requests',$service->avatar(1,100)===$photo && count($calls)===$before);
    avCheck('anonymous inactive and outside-project callers cannot read cached photo',$service->avatar(0,100)===null && $service->avatar(3,100)===null && $service->avatar(2,100)===null && count($calls)===$before);
    $pdo->exec('DELETE FROM manager_projects WHERE manager_id=1');
    avCheck('revoked project membership cannot read existing cache',$service->avatar(1,100)===null && count($calls)===$before);
    $pdo->exec('INSERT INTO manager_projects VALUES(1,1)');
    $pdo->exec('UPDATE managers SET is_active=0 WHERE id=1');
    avCheck('deactivated account cannot read existing cache',$service->avatar(1,100)===null && count($calls)===$before);
    $pdo->exec('UPDATE managers SET is_active=1 WHERE id=1');
    $pdo->exec("INSERT INTO conversation_sources VALUES(77,1,'none',0,0,1);UPDATE conversations SET source_id=77 WHERE id=100");
    avCheck('routing visibility revocation is checked on cache hits',$service->avatar(1,100)===null && count($calls)===$before);
    $pdo->exec('UPDATE conversations SET source_id=0 WHERE id=100');
    avCheck('other-project token is never tried despite authorized admin',$service->avatar(2,400)===null && count($calls)===$before);
    avCheck('MAX without an eligible saved message falls back without network',$service->avatar(1,300)===null && count($calls)===$before);
    avCheck('same customer channel mismatch fails closed',($pdo->exec('UPDATE conversations SET customer_id=99 WHERE id=100')!==false) && $service->avatar(1,100)===null);
    $pdo->exec('UPDATE conversations SET customer_id=10 WHERE id=100');
    $before=count($calls);$other=$service->avatar(1,200);
    avCheck('different external identity makes its own provider lookup',$other!==null && count($calls)===$before+3 && $calls[$before]['post']['user_id']==='456');
    foreach (glob($root.'/cache/*') as $file) avCheck('cache file is private '.basename($file),(fileperms($file)&0777)===0600);
    avCheck('cache directory is private',(fileperms($root.'/cache')&0777)===0700);
    $record=json_decode(file_get_contents($root.'/cache/'.$slot().'.json'),true);
    avCheck('cache stores only identity hash expiry and binary without token or URL',isset($record['key'],$record['expires'],$record['bytes']) && strlen($record['key'])===64 && !str_contains(json_encode($record),'synthetic_avatar_token') && !str_contains(json_encode($record),'https://'));
    $record['expires']=time()-1;file_put_contents($root.'/cache/'.$slot().'.json',json_encode($record));$before=count($calls);$service->avatar(1,100);
    avCheck('expired photo refreshes once',count($calls)===$before+3);
    $record=json_decode(file_get_contents($root.'/cache/'.$slot().'.json'),true);$record['key']=str_repeat('f',64);file_put_contents($root.'/cache/'.$slot().'.json',json_encode($record));$before=count($calls);$service->avatar(1,100);
    avCheck('colliding slot never serves mismatched identity hash',count($calls)===$before+3);
    $handle=fopen($root.'/cache/'.$slot().'.lock','c');flock($handle,LOCK_EX);$before=count($calls);$start=microtime(true);
    avCheck('busy refresh lock falls back immediately without duplicate request',$service->avatar(1,100)===null && count($calls)===$before && microtime(true)-$start<0.1);flock($handle,LOCK_UN);fclose($handle);
    foreach (['fail','throw','empty','svg','oversize','large','invalid','dimensions'] as $testMode) {
        $mode=$testMode;$failure=new ManagerCustomerAvatarService($root.'/failure-'.$testMode,$fetch);$before=count($calls);
        avCheck('provider '.$testMode.' never emits unsafe photo',$failure->avatar(1,100)===null);
        $first=count($calls);avCheck('provider '.$testMode.' is negatively cached',$failure->avatar(1,100)===null && $first>$before && count($calls)===$first);
    }
    $mode='ok';
    foreach (['../../secret','https://127.0.0.1/a','photos/a?secret=x','photos/a#x','photos/%2e%2e/a','photos/a\\b'] as $index=>$badPath) {
        $path=$badPath;$before=count($calls);$unsafe=new ManagerCustomerAvatarService($root.'/unsafe-'.$index,$fetch);
        avCheck('unsafe provider file path rejected '.$index,$unsafe->avatar(1,100)===null && count($calls)===$before+2);
    }
    $path='photos/avatar.png';
    $inside=new ManagerCustomerAvatarService(dirname(__DIR__).'/forbidden-avatar-cache',$fetch);$before=count($calls);
    avCheck('application-tree cache rejected before directory creation',$inside->avatar(1,100)===null && !is_dir(dirname(__DIR__).'/forbidden-avatar-cache') && count($calls)===$before);
    symlink($root.'/cache',$root.'/alias');$linked=new ManagerCustomerAvatarService($root.'/alias',$fetch);
    avCheck('symlink cache directory rejected',$linked->avatar(1,100)===null);
    mkdir($root.'/symlink-record',0700);file_put_contents($root.'/private-sentinel','sentinel');symlink($root.'/private-sentinel',$root.'/symlink-record/'.$slot().'.json');
    $linked=new ManagerCustomerAvatarService($root.'/symlink-record',$fetch);
    avCheck('symlink record rejected without touching target',$linked->avatar(1,100)===null && file_get_contents($root.'/private-sentinel')==='sentinel');
    file_put_contents($root.'/not-directory','x');$badStore=new ManagerCustomerAvatarService($root.'/not-directory',$fetch);$before=count($calls);
    avCheck('unavailable cache storage falls back without provider work',$badStore->avatar(1,100)===null && count($calls)===$before);
    // More identities than slots cannot create unbounded locks or records.
    $bounded=new ManagerCustomerAvatarService($root.'/bounded',$fetch);
    for ($i=1;$i<=ManagerCustomerAvatarService::CACHE_SLOTS+80;$i++) {$pdo->exec("UPDATE customer_channels SET external_user_id='".(10000+$i)."' WHERE id=10");$bounded->avatar(1,100);}
    avCheck('cache records and locks remain bounded across many identities',count(glob($root.'/bounded/*'))<=ManagerCustomerAvatarService::CACHE_SLOTS*2);
    foreach (glob($root.'/bounded/*.json') as $file) if (filesize($file)>351000) throw new RuntimeException('unbounded record');
    avCheck('all records remain byte bounded',true);
    $pdo->exec("UPDATE customer_channels SET external_user_id='123' WHERE id=10");
    require __DIR__.'/fixtures/manager_full_avatar_regression.php';
    // Exercise the actual protected binary endpoint with canonical auth, a
    // separate SQLite DB/session and prewarmed synthetic binary cache. No token,
    // production config, customer send or external network is involved.
    $httpRoot=$root.'/http';mkdir($httpRoot,0700);mkdir($httpRoot.'/manager',0700);mkdir($httpRoot.'/services',0700);mkdir($httpRoot.'/sessions',0700);
    $httpDb=new PDO('sqlite:'.$httpRoot.'/fixture.sqlite',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
    foreach ($pdo->query("SELECT sql FROM sqlite_master WHERE type='table'")->fetchAll(PDO::FETCH_COLUMN) as $schema) $httpDb->exec($schema);
    foreach (['projects','manager_projects','managers','conversations','customer_channels'] as $table) foreach ($pdo->query('SELECT * FROM '.$table)->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $q=$httpDb->prepare('INSERT INTO '.$table.' VALUES ('.implode(',',array_fill(0,count($row),'?')).')');$q->execute(array_values($row));
    }
    $httpDb->exec("UPDATE customer_channels SET external_user_id='123' WHERE id=10");
    $sourceRoot=dirname(__DIR__);
    foreach (glob($sourceRoot.'/services/*.php') as $file) {
        if (basename($file)==='ManagerCustomerAvatarService.php') copy($file,$httpRoot.'/services/'.basename($file));
        else symlink($file,$httpRoot.'/services/'.basename($file));
    }
    symlink($sourceRoot.'/integrations',$httpRoot.'/integrations');
    copy($sourceRoot.'/manager/customer-avatar.php',$httpRoot.'/manager/customer-avatar.php');symlink($sourceRoot.'/manager/lib',$httpRoot.'/manager/lib');
    $config="<?php\nrequire_once ".var_export($sourceRoot.'/services/ManagerRequestContext.php',true).";\nrequire_once ".var_export($sourceRoot.'/services/ProjectConfig.php',true).";\n".
        '$fixturePdo=new PDO('.var_export('sqlite:'.$httpRoot.'/fixture.sqlite',true).',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);' ."\n".
        '(new ReflectionProperty(ConversationDb::class,"pdo"))->setValue(null,$fixturePdo);' ."\n".
        'foreach ([ProjectAccessService::class,ManagerAuthService::class,RoutingAccessService::class] as $class) (new ReflectionProperty($class,"schemaReady"))->setValue(null,true);' ."\n".
        'ProjectConfig::resetForTests(["id"=>"anytour"]);';
    file_put_contents($httpRoot.'/config.php',$config);
    file_put_contents($httpRoot.'/manager/fixture-session.php','<?php require dirname(__DIR__)."/config.php";require __DIR__."/lib/ManagerHttp.php";ManagerHttp::startJson();$_SESSION["manager_id"]=(int)($_GET["id"]??1);ManagerHttp::respond(["ok"=>true]);');
    $httpDb->exec("INSERT INTO managers VALUES(4,'same-project','Same project','admin','',1,1);INSERT INTO manager_projects VALUES(4,1)");
    $httpCache=sys_get_temp_dir().'/max-search-bot-manager-avatars-'.substr(hash('sha256',$httpRoot),0,16);mkdir($httpCache,0700);
    $key=hash('sha256',json_encode(['anytour','telegram','123'],JSON_THROW_ON_ERROR));
    file_put_contents($httpCache.'/'.$slot().'.json',json_encode(['key'=>$key,'expires'=>time()+60,'bytes'=>base64_encode($png)]));chmod($httpCache.'/'.$slot().'.json',0600);
    $maxKey=hash('sha256',json_encode(['max-private-v1','anytour','max','123',300,30],JSON_THROW_ON_ERROR));
    $maxSlot=(string)(hexdec(substr($maxKey,0,4))%ManagerCustomerAvatarService::CACHE_SLOTS);
    file_put_contents($httpCache.'/'.$maxSlot.'.json',json_encode(['key'=>$maxKey,'expires'=>time()+60,'bytes'=>base64_encode($png)]));chmod($httpCache.'/'.$maxSlot.'.json',0600);
    $fullKey=hash('sha256',json_encode(['full-v1','anytour','telegram','123',0],JSON_THROW_ON_ERROR));
    $fullSlot='full-'.(string)(hexdec(substr($fullKey,0,4))%ManagerCustomerAvatarService::FULL_CACHE_SLOTS);
    $fullRecord=['key'=>$fullKey,'expires'=>time()+60,'bytes'=>base64_encode($png),'photo_count'=>2,'limited'=>false];
    file_put_contents($httpCache.'/'.$fullSlot.'.json',json_encode($fullRecord));chmod($httpCache.'/'.$fullSlot.'.json',0600);
    $process=null;
    try {
        $socket=stream_socket_server('tcp://127.0.0.1:0',$errno,$errstr);if (!$socket) throw new RuntimeException('Cannot allocate loopback fixture');
        $address=stream_socket_get_name($socket,false);fclose($socket);
        $process=proc_open([PHP_BINARY,'-d','session.save_path='.$httpRoot.'/sessions','-S',$address,'-t',$httpRoot],[0=>['pipe','r'],1=>['file',$httpRoot.'/server.log','a'],2=>['file',$httpRoot.'/server.log','a']],$pipes);
        if (!is_resource($process)) throw new RuntimeException('Cannot start loopback PHP');fclose($pipes[0]);
        $ready=false;for ($wait=0;$wait<200;$wait++) {$probe=@stream_socket_client('tcp://'.$address,$errno,$errstr,0.05);if ($probe) {fclose($probe);$ready=true;break;}usleep(10000);}
        if (!$ready) throw new RuntimeException('Loopback PHP not ready');
        $request=static function(string $path,string $cookie='',string $method='GET',array $extraHeaders=[]) use ($address):array {
            $requestHeaders=$cookie!==''?['Cookie: '.$cookie]:[];
            $context=stream_context_create(['http'=>['method'=>$method,'header'=>implode("\r\n",array_merge($requestHeaders,$extraHeaders)),'ignore_errors'=>true,'timeout'=>3]]);
            $body=file_get_contents('http://'.$address.$path,false,$context);$headers=$http_response_header??[];
            preg_match('/^HTTP\/[^ ]+ ([0-9]+)/',(string)($headers[0]??''),$status);$responseCookie='';
            foreach ($headers as $h) if (preg_match('/^Set-Cookie: (anytour_manager_panel=[^;]+)/i',$h,$match)) $responseCookie=$match[1];
            return ['status'=>(int)($status[1]??0),'bytes'=>(string)$body,'headers'=>implode("\n",$headers),'cookie'=>$responseCookie];
        };
        $endpoint='/manager/customer-avatar.php?conversation_id=100';$anon=$request($endpoint);
        $header=static function(array $response,string $name):?string {if (preg_match('/^'.preg_quote($name,'/').':\s*(.+)$/mi',$response['headers'],$match)) return trim($match[1]);return null;};
        avCheck('real HTTP anonymous avatar remains unauthorized and uncacheable',$anon['status']===401 && !str_contains($anon['bytes'],$png) && str_contains($anon['headers'],'no-store') && $header($anon,'ETag')===null);
        $cookie=$request('/manager/fixture-session.php?id=1')['cookie'];$binary=$request($endpoint,$cookie);
        avCheck('real HTTP authenticated cache returns exact binary',$binary['status']===200 && $binary['bytes']===$png);
        $tag=$header($binary,'ETag');
        avCheck('real HTTP image exposes bounded opaque validator',is_string($tag) && preg_match('/^"[a-f0-9]{64}"$/D',$tag)===1);
        $unchanged=$request($endpoint,$cookie,'GET',['If-None-Match: '.$tag]);
        avCheck('real HTTP matching conditional image returns empty 304',$unchanged['status']===304 && $unchanged['bytes']==='' && $header($unchanged,'ETag')===$tag && str_contains($unchanged['headers'],'private, no-cache, must-revalidate') && $header($unchanged,'Vary')==='Cookie' && $header($unchanged,'Content-Type')==='image/png');
        $mismatch=$request($endpoint,$cookie,'GET',['If-None-Match: "unrelated"']);
        avCheck('real HTTP mismatched validator returns full 200',$mismatch['status']===200 && $mismatch['bytes']===$png);
        $weak=$request($endpoint,$cookie,'GET',['If-None-Match: "unrelated", W/'.$tag]);
        avCheck('real HTTP weak validator list matches only authorized representation',$weak['status']===304 && $weak['bytes']==='');
        $accountOnly=$request($endpoint.'&account=1',$cookie);
        avCheck('real HTTP legacy account-only URL still revalidates',$accountOnly['status']===200 && $header($accountOnly,'Cache-Control')==='private, no-cache, must-revalidate');
        $dailyEndpoint=$endpoint.'&account=1&channel=telegram';$daily=$request($dailyEndpoint,$cookie);$dailyTag=$header($daily,'ETag');
        avCheck('real HTTP current-account and channel URL receives private daily cache',$daily['status']===200 && $daily['bytes']===$png && $header($daily,'Cache-Control')==='private, max-age=86400' && $header($daily,'Vary')==='Cookie' && $dailyTag!==$tag && $header($daily,'Pragma')===null && $header($daily,'Expires')===null);
        $dailyConditional=$request($dailyEndpoint,$cookie,'GET',['If-None-Match: '.$dailyTag]);
        avCheck('real HTTP stale scoped validator remains authorized before empty 304',$dailyConditional['status']===304 && $dailyConditional['bytes']==='' && $header($dailyConditional,'Cache-Control')==='private, max-age=86400');
        foreach (['2','0','01','-1','1.0','1e0','9223372036854775808',''] as $wrongAccount) {
            $wrong=$request($endpoint.'&account='.rawurlencode($wrongAccount),$cookie,'GET',['If-None-Match: '.$tag]);
            avCheck('real HTTP rejects foreign or malformed account scope '.json_encode($wrongAccount),$wrong['status']===404 && $wrong['bytes']==='' && str_contains($wrong['headers'],'no-store') && $header($wrong,'ETag')===null);
        }
        $arrayAccount=$request($endpoint.'&account[]=1',$cookie,'GET',['If-None-Match: '.$tag]);
        avCheck('real HTTP rejects array account scope without caching',$arrayAccount['status']===404 && str_contains($arrayAccount['headers'],'no-store') && $header($arrayAccount,'ETag')===null);
        foreach (['max','website','Telegram',''] as $wrongChannel) {
            $wrong=$request($endpoint.'&account=1&channel='.rawurlencode($wrongChannel),$cookie,'GET',['If-None-Match: '.$dailyTag]);
            avCheck('real HTTP foreign or malformed channel scope fails closed '.json_encode($wrongChannel),$wrong['status']===404 && $wrong['bytes']==='' && str_contains($wrong['headers'],'no-store') && $header($wrong,'ETag')===null);
        }
        $arrayChannel=$request($endpoint.'&account=1&channel[]=telegram',$cookie,'GET',['If-None-Match: '.$dailyTag]);
        avCheck('real HTTP rejects array channel scope without caching',$arrayChannel['status']===404 && str_contains($arrayChannel['headers'],'no-store') && $header($arrayChannel,'ETag')===null);
        $fullEndpoint=$endpoint.'&size=full&photo=0';$fullBinary=$request($fullEndpoint,$cookie);
        $fullTag=$header($fullBinary,'ETag');
        avCheck('real HTTP explicit full photo returns gallery count and separate validator',$fullBinary['status']===200 && $fullBinary['bytes']===$png && $header($fullBinary,'X-Avatar-Photo-Count')==='2' && $header($fullBinary,'X-Avatar-Photo-Limited')===null && $fullTag!==$tag);
        $dailyFull=$request($fullEndpoint.'&account=1&channel=telegram',$cookie);
        avCheck('real HTTP current-account full photo receives daily cache and count',$dailyFull['status']===200 && $header($dailyFull,'Cache-Control')==='private, max-age=86400' && $header($dailyFull,'X-Avatar-Photo-Count')==='2');
        $fullUnchanged=$request($fullEndpoint,$cookie,'GET',['If-None-Match: '.$fullTag]);
        avCheck('real HTTP full image conditional hit preserves gallery metadata',$fullUnchanged['status']===304 && $fullUnchanged['bytes']==='' && $header($fullUnchanged,'X-Avatar-Photo-Count')==='2');
        $fullRecord['photo_count']=1000;$fullRecord['limited']=true;file_put_contents($httpCache.'/'.$fullSlot.'.json',json_encode($fullRecord));
        $limited=$request($fullEndpoint,$cookie,'GET',['If-None-Match: '.$fullTag]);
        avCheck('real HTTP changed count and limit flag invalidate same-byte photo',$limited['status']===200 && $header($limited,'X-Avatar-Photo-Count')==='1000' && $header($limited,'X-Avatar-Photo-Limited')==='1' && $header($limited,'ETag')!==$fullTag);
        $fullRecord['bytes']=base64_encode('invalid-image');file_put_contents($httpCache.'/'.$fullSlot.'.json',json_encode($fullRecord));
        $invalidCached=$request($fullEndpoint,$cookie,'GET',['If-None-Match: '.$header($limited,'ETag')]);
        avCheck('real HTTP conditional hit validates cached image before 304',$invalidCached['status']===404 && $invalidCached['bytes']==='' && str_contains($invalidCached['headers'],'no-store') && $header($invalidCached,'ETag')===null);
        $maxBinary=$request('/manager/customer-avatar.php?conversation_id=300',$cookie);
        avCheck('real HTTP MAX cache returns validated binary through canonical endpoint',$maxBinary['status']===200 && $maxBinary['bytes']===$png && str_contains($maxBinary['headers'],'Content-Type: image/png'));
        avCheck('binary endpoint requires private auth-first revalidation with nosniff sandbox headers',str_contains($binary['headers'],'Cache-Control: private, no-cache, must-revalidate') && $header($binary,'Vary')==='Cookie' && str_contains($binary['headers'],'Content-Type: image/png') && str_contains($binary['headers'],'X-Content-Type-Options: nosniff') && str_contains($binary['headers'],"Content-Security-Policy: default-src 'none'; sandbox"));
        avCheck('binary HTTP endpoint rejects write methods',$request($endpoint,$cookie,'POST')['status']===405);
        avCheck('binary HTTP endpoint rejects invalid conversation IDs',$request('/manager/customer-avatar.php?conversation_id=100x',$cookie)['status']===404);
        foreach (['&size=large','&size[]=full','&size=full&photo=-1','&size=full&photo=1000','&size=full&photo=1.5','&size=full&photo[]=1','&photo=1'] as $query) {
            $invalid=$request($endpoint.$query,$cookie,'GET',['If-None-Match: '.$tag]);
            avCheck('binary HTTP rejects malformed size or index '.$query,$invalid['status']===404 && $invalid['bytes']==='' && str_contains($invalid['headers'],'no-store') && $header($invalid,'ETag')===null);
        }
        $httpDb->exec('DELETE FROM manager_projects WHERE manager_id=1');
        $revoked=$request($endpoint,$cookie,'GET',['If-None-Match: '.$tag]);avCheck('real HTTP ACL revocation defeats conditional prewarmed cache',$revoked['status']===404 && $revoked['bytes']==='' && str_contains($revoked['headers'],'no-store') && $header($revoked,'ETag')===null);
        $revokedDaily=$request($dailyEndpoint,$cookie,'GET',['If-None-Match: '.$dailyTag]);
        avCheck('real HTTP revoked scoped request cannot receive photo or 304',$revokedDaily['status']===404 && $revokedDaily['bytes']==='' && str_contains($revokedDaily['headers'],'no-store') && $header($revokedDaily,'ETag')===null);
        $httpDb->exec('INSERT INTO manager_projects VALUES(1,1)');
        $otherAllowed=$request('/manager/fixture-session.php?id=4',$cookie);$otherAllowedCookie=$otherAllowed['cookie']?:$cookie;
        $otherAccount=$request($endpoint,$otherAllowedCookie,'GET',['If-None-Match: '.$tag]);
        avCheck('real HTTP another authorized account receives its own validator and 200',$otherAccount['status']===200 && $otherAccount['bytes']===$png && $header($otherAccount,'ETag')!==$tag);
        $oldAccountScope=$request($dailyEndpoint,$otherAllowedCookie,'GET',['If-None-Match: '.$tag]);
        avCheck('real HTTP account switch on same cookie rejects previous scoped URL',$oldAccountScope['status']===404 && $oldAccountScope['bytes']==='' && str_contains($oldAccountScope['headers'],'no-store') && $header($oldAccountScope,'ETag')===null);
        $newAccountScope=$request($endpoint.'&account=4&channel=telegram',$otherAllowedCookie);
        avCheck('real HTTP switched account receives its own daily URL',$newAccountScope['status']===200 && $newAccountScope['bytes']===$png && $header($newAccountScope,'Cache-Control')==='private, max-age=86400' && $header($newAccountScope,'ETag')!==$tag);
        $outside=$request('/manager/fixture-session.php?id=2',$cookie);$outsideCookie=$outside['cookie']?:$cookie;
        $deniedAccount=$request($endpoint,$outsideCookie,'GET',['If-None-Match: '.$tag]);
        avCheck('real HTTP account switch cannot expose prior portrait or 304',$deniedAccount['status']===404 && str_contains($deniedAccount['headers'],'no-store') && $header($deniedAccount,'ETag')===null);
        $expired=$request('/manager/fixture-session.php?id=0',$outsideCookie);$expiredCookie=$expired['cookie']?:$outsideCookie;
        $expiredPhoto=$request($endpoint,$expiredCookie,'GET',['If-None-Match: '.$tag]);
        avCheck('real HTTP expired session defeats cached conditional image',$expiredPhoto['status']===401 && str_contains($expiredPhoto['headers'],'no-store') && $header($expiredPhoto,'ETag')===null);
        $expiredDaily=$request($dailyEndpoint,$expiredCookie,'GET',['If-None-Match: '.$tag]);
        avCheck('real HTTP expired session rejects account-scoped conditional request',$expiredDaily['status']===401 && str_contains($expiredDaily['headers'],'no-store') && $header($expiredDaily,'ETag')===null);
    } finally {
        if (is_resource($process)) {proc_terminate($process);proc_close($process);}
        foreach (glob($httpCache.'/*') as $file) unlink($file);rmdir($httpCache);
    }
    require __DIR__.'/fixtures/manager_max_avatar_regression.php';
    echo 'TOTAL '.$passed." | FAIL 0\n";
} finally {
    $remove=static function(string $dir) use(&$remove):void {foreach (scandir($dir) as $name) {if ($name==='.' || $name==='..') continue;$p=$dir.'/'.$name;if (is_dir($p) && !is_link($p)) $remove($p);else unlink($p);}rmdir($dir);};$remove($root);
    ob_end_flush();
}
