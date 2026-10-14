<?php

declare(strict_types=1);
require_once dirname(__DIR__).'/services/ManagerCustomerAvatarService.php';
define('TELEGRAM_BOT_TOKEN','123456:synthetic_avatar_token');
ProjectConfig::resetForTests(['id'=>'anytour']);
$pdo=new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
(new ReflectionProperty(ConversationDb::class,'pdo'))->setValue(null,$pdo);
foreach ([ProjectAccessService::class,ManagerAuthService::class,RoutingAccessService::class] as $class) (new ReflectionProperty($class,'schemaReady'))->setValue(null,true);
$pdo->exec("CREATE TABLE projects(id INTEGER PRIMARY KEY,project_key TEXT,display_name TEXT,is_active INTEGER);
CREATE TABLE manager_projects(manager_id INTEGER,project_id INTEGER);
CREATE TABLE managers(id INTEGER PRIMARY KEY,login TEXT,display_name TEXT,role TEXT,email TEXT,is_active INTEGER,is_working INTEGER);
CREATE TABLE conversations(id INTEGER PRIMARY KEY,customer_id INTEGER,customer_channel_id INTEGER,project_key TEXT,source_id INTEGER,status TEXT,manager_id INTEGER,started_at TEXT,last_message_at TEXT,channel TEXT);
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
    avCheck('MAX unsupported private profile falls back without network',$service->avatar(1,300)===null && count($calls)===$before);
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
    copy($sourceRoot.'/manager/customer-avatar.php',$httpRoot.'/manager/customer-avatar.php');symlink($sourceRoot.'/manager/lib',$httpRoot.'/manager/lib');
    $config="<?php\nrequire_once ".var_export($sourceRoot.'/services/ManagerRequestContext.php',true).";\nrequire_once ".var_export($sourceRoot.'/services/ProjectConfig.php',true).";\n".
        '$fixturePdo=new PDO('.var_export('sqlite:'.$httpRoot.'/fixture.sqlite',true).',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);' ."\n".
        '(new ReflectionProperty(ConversationDb::class,"pdo"))->setValue(null,$fixturePdo);' ."\n".
        'foreach ([ProjectAccessService::class,ManagerAuthService::class,RoutingAccessService::class] as $class) (new ReflectionProperty($class,"schemaReady"))->setValue(null,true);' ."\n".
        'ProjectConfig::resetForTests(["id"=>"anytour"]);';
    file_put_contents($httpRoot.'/config.php',$config);
    file_put_contents($httpRoot.'/manager/fixture-session.php','<?php require dirname(__DIR__)."/config.php";require __DIR__."/lib/ManagerHttp.php";ManagerHttp::startJson();$_SESSION["manager_id"]=(int)($_GET["id"]??1);ManagerHttp::respond(["ok"=>true]);');
    $httpCache=sys_get_temp_dir().'/max-search-bot-manager-avatars-'.substr(hash('sha256',$httpRoot),0,16);mkdir($httpCache,0700);
    $key=hash('sha256',json_encode(['anytour','telegram','123'],JSON_THROW_ON_ERROR));
    file_put_contents($httpCache.'/'.$slot().'.json',json_encode(['key'=>$key,'expires'=>time()+60,'bytes'=>base64_encode($png)]));chmod($httpCache.'/'.$slot().'.json',0600);
    $process=null;
    try {
        $socket=stream_socket_server('tcp://127.0.0.1:0',$errno,$errstr);if (!$socket) throw new RuntimeException('Cannot allocate loopback fixture');
        $address=stream_socket_get_name($socket,false);fclose($socket);
        $process=proc_open([PHP_BINARY,'-d','session.save_path='.$httpRoot.'/sessions','-S',$address,'-t',$httpRoot],[0=>['pipe','r'],1=>['file',$httpRoot.'/server.log','a'],2=>['file',$httpRoot.'/server.log','a']],$pipes);
        if (!is_resource($process)) throw new RuntimeException('Cannot start loopback PHP');fclose($pipes[0]);
        $ready=false;for ($wait=0;$wait<200;$wait++) {$probe=@stream_socket_client('tcp://'.$address,$errno,$errstr,0.05);if ($probe) {fclose($probe);$ready=true;break;}usleep(10000);}
        if (!$ready) throw new RuntimeException('Loopback PHP not ready');
        $request=static function(string $path,string $cookie='',string $method='GET') use ($address):array {
            $context=stream_context_create(['http'=>['method'=>$method,'header'=>$cookie!==''?'Cookie: '.$cookie:'','ignore_errors'=>true,'timeout'=>3]]);
            $body=file_get_contents('http://'.$address.$path,false,$context);$headers=$http_response_header??[];
            preg_match('/^HTTP\/[^ ]+ ([0-9]+)/',(string)($headers[0]??''),$status);$responseCookie='';
            foreach ($headers as $h) if (preg_match('/^Set-Cookie: (anytour_manager_panel=[^;]+)/i',$h,$match)) $responseCookie=$match[1];
            return ['status'=>(int)($status[1]??0),'bytes'=>(string)$body,'headers'=>implode("\n",$headers),'cookie'=>$responseCookie];
        };
        $endpoint='/manager/customer-avatar.php?conversation_id=100';$anon=$request($endpoint);
        avCheck('real HTTP anonymous avatar remains unauthorized',$anon['status']===401 && !str_contains($anon['bytes'],$png));
        $cookie=$request('/manager/fixture-session.php?id=1')['cookie'];$binary=$request($endpoint,$cookie);
        avCheck('real HTTP authenticated cache returns exact binary',$binary['status']===200 && $binary['bytes']===$png);
        avCheck('binary endpoint has private no-store nosniff sandbox headers',str_contains($binary['headers'],'Cache-Control: private, no-store') && str_contains($binary['headers'],'Content-Type: image/png') && str_contains($binary['headers'],'X-Content-Type-Options: nosniff') && str_contains($binary['headers'],"Content-Security-Policy: default-src 'none'; sandbox"));
        avCheck('binary HTTP endpoint rejects write methods',$request($endpoint,$cookie,'POST')['status']===405);
        avCheck('binary HTTP endpoint rejects invalid conversation IDs',$request('/manager/customer-avatar.php?conversation_id=100x',$cookie)['status']===404);
        $httpDb->exec('DELETE FROM manager_projects WHERE manager_id=1');
        $revoked=$request($endpoint,$cookie);avCheck('real HTTP ACL revocation defeats prewarmed cache',$revoked['status']===404 && $revoked['bytes']==='');
        $httpDb->exec('INSERT INTO manager_projects VALUES(1,1)');
        $outside=$request('/manager/fixture-session.php?id=2',$cookie);$outsideCookie=$outside['cookie']?:$cookie;
        avCheck('real HTTP account switch cannot expose prior portrait',$request($endpoint,$outsideCookie)['status']===404);
    } finally {
        if (is_resource($process)) {proc_terminate($process);proc_close($process);}
        foreach (glob($httpCache.'/*') as $file) unlink($file);rmdir($httpCache);
    }
    echo 'TOTAL '.$passed." | FAIL 0\n";
} finally {
    $remove=static function(string $dir) use(&$remove):void {foreach (scandir($dir) as $name) {if ($name==='.' || $name==='..') continue;$p=$dir.'/'.$name;if (is_dir($p) && !is_link($p)) $remove($p);else unlink($p);}rmdir($dir);};$remove($root);
}
