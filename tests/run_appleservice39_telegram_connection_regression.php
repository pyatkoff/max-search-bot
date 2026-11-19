<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') exit;
ob_start();
require_once __DIR__.'/fixtures/client_onboarding_fixture.php';
require_once dirname(__DIR__).'/services/AppleService39TelegramConnection.php';

function appleConnectionCheck(string $name, bool $ok): void {
    if (!$ok) throw new RuntimeException('FAIL '.$name);
    echo "PASS ".$name."\n";
}
function appleConnectionRefuses(string $reason, callable $operation): void {
    try { $operation(); }
    catch (RuntimeException $e) {
        appleConnectionCheck($reason, $e->getMessage() === $reason);
        return;
    }
    throw new RuntimeException('Missing refusal '.$reason);
}
$root = dirname(__DIR__);
$tmp = realpath(sys_get_temp_dir()).'/apple-telegram-'.bin2hex(random_bytes(8));
mkdir($tmp,0700);
$server = null;
try {
    $pdo = new ClientFixturePdo('sqlite:'.$tmp.'/fixture.sqlite');
    clientFixtureBind($pdo,$tmp.'/legacy-vault');
    clientFixtureSchema($pdo);
    foreach (['suppress_channel_offer','primary_group_id','fallback_group_id','fallback_after_minutes'] as $column)
        $pdo->exec("ALTER TABLE conversation_sources ADD COLUMN ".$column." INTEGER");
    $pdo->exec("CREATE TABLE project_telegram_bot_connections(project_id INTEGER PRIMARY KEY,bot_id TEXT NOT NULL UNIQUE,credentials_json TEXT NOT NULL,version INTEGER NOT NULL,webhook_state TEXT NOT NULL DEFAULT 'not_set',webhook_started_at INTEGER NOT NULL DEFAULT 0)");
    $pdo->exec('ALTER TABLE conversations ADD COLUMN source_id INTEGER');
    $pdo->exec('ALTER TABLE conversations ADD COLUMN channel TEXT');
    $pdo->exec('CREATE TABLE conversation_events(id INTEGER PRIMARY KEY,conversation_id INTEGER,event_type TEXT,payload_json TEXT)');
    $pdo->prepare('UPDATE projects SET display_name=? WHERE id=1')->execute([ProjectConfig::get('brand.name',ProjectConfig::projectId())]);
    $pdo->exec("INSERT INTO projects(id,project_key,display_name) VALUES(2,'appleservice39','AppleService39')");
    $pdo->exec("INSERT INTO manager_projects(manager_id,project_id) VALUES(1,2),(2,2)");
    $token = '123456:'.str_repeat('A',35);
    $next = '123456:'.str_repeat('B',35);
    $vault = new ProjectClientCredentialStore($tmp.'/apple-vault');
    $calls = 0;
    $username = 'Appleservice39_bot';
    $hook = null;
    $reader = static function($candidate,$method,$body) use (&$calls,&$username,&$hook) {
        appleConnectionCheck('only getMe can run', $method === 'getMe' && $body === []);
        $calls++;
        if ($hook) $hook();
        return ['http'=>200,'ok'=>true,'result'=>['is_bot'=>true,'id'=>(int)explode(':',$candidate)[0],'username'=>$username]];
    };
    $connection = new AppleService39TelegramConnection($pdo,$vault,$reader);
    appleConnectionRefuses('forbidden',fn()=>$connection->save(3,0,$token)); // Another platform admin.
    appleConnectionRefuses('forbidden',fn()=>$connection->save(2,0,$token)); // Its own employee.
    appleConnectionCheck('foreign admin and employee cannot trigger provider reads',$calls === 0);

    // Exercise the actual PHP entry point with the repository's local-only session fixture.
    mkdir($tmp.'/manager',0700); mkdir($tmp.'/sessions',0700); touch($tmp.'/synthetic-client-fixture');
    symlink($root.'/services',$tmp.'/services'); symlink($root.'/manager/lib',$tmp.'/manager/lib');
    copy($root.'/manager/appleservice39-telegram.php',$tmp.'/manager/appleservice39-telegram.php');
    $fixture=var_export(__DIR__.'/fixtures/client_onboarding_http.php',true);
    file_put_contents($tmp.'/config.php',"<?php require_once ".$fixture.";clientHttpBootstrap(__DIR__);require_once __DIR__.'/services/DiagnosticLogger.php';(new ReflectionProperty(DiagnosticLogger::class,'file'))->setValue(null,__DIR__.'/fixture.log');");
    session_name('anytour_manager_panel');session_save_path($tmp.'/sessions');$sessions=[];
    foreach ([1,2,3] as $actor) {
        $sid=bin2hex(random_bytes(16)); session_id($sid); session_start();
        $_SESSION=['manager_id'=>$actor,'csrf'=>'synthetic-client-csrf'];session_write_close();$sessions[$actor]=$sid;
    }
    $socket=stream_socket_server('tcp://127.0.0.1:0',$errno,$error);
    if (!$socket) throw new RuntimeException('loopback unavailable');
    $address=stream_socket_get_name($socket,false);fclose($socket);
    $server=proc_open([PHP_BINARY,'-d','session.save_path='.$tmp.'/sessions','-S',$address,'-t',$tmp],
        [0=>['pipe','r'],1=>['file',$tmp.'/server.log','a'],2=>['file',$tmp.'/server.log','a']],$pipes);
    if (!is_resource($server)) throw new RuntimeException('cannot start loopback');
    fclose($pipes[0]);
    for ($i=0;$i<100;$i++) {
        $probe=@stream_socket_client('tcp://'.$address,$errno,$error,.05);
        if ($probe) { fclose($probe); break; }
        usleep(10000);
    }
    $request=static function(int $actor,?array $form=null) use($address,$sessions): array {
        $headers=['Content-Type: application/x-www-form-urlencoded'];
        if ($actor>0) $headers[]='Cookie: anytour_manager_panel='.$sessions[$actor];
        $request=['method'=>$form===null?'GET':'POST','header'=>implode("\r\n",$headers),'ignore_errors'=>true,'timeout'=>5,'follow_location'=>0];
        if ($form!==null) $request['content']=http_build_query($form);
        $body=file_get_contents('http://'.$address.'/manager/appleservice39-telegram.php',false,stream_context_create(['http'=>$request]));
        preg_match('/^HTTP\/\S+\s+(\d+)/',$http_response_header[0]??'',$status);
        return [(int)($status[1]??0),(string)$body];
    };
    appleConnectionCheck('anonymous credentials form denied',$request(0)[0]===401);
    appleConnectionCheck('employee page denied',$request(2)[0]===403);
    appleConnectionCheck('other project administrator denied',$request(3)[0]===403);
    [$http,$form]=$request(1);
    appleConnectionCheck('authorized form has password input and CSRF',$http===200 && str_contains($form,'type="password"') && str_contains($form,'synthetic-client-csrf'));
    $form=['actor'=>'1','csrf'=>'wrong','action'=>'save','version'=>'0','token'=>$token];
    appleConnectionCheck('token and publication actions require CSRF',$request(1,$form)[0]===403 && $request(1,['actor'=>'1','csrf'=>'wrong','action'=>'publish'])[0]===403
        && $request(1,['actor'=>'1','csrf'=>'wrong','action'=>'activate','version'=>'1'])[0]===403);
    $form['csrf']='synthetic-client-csrf';$form['actor']='3';
    appleConnectionCheck('form refuses stale actor',$request(1,$form)[0]===409);
    appleConnectionCheck('publish cannot run until a connection exists',$request(1,['actor'=>'1','csrf'=>'synthetic-client-csrf','action'=>'publish'])[0]===409);

    $username='SomeOther_bot';
    appleConnectionRefuses('identity_mismatch',fn()=>$connection->save(1,0,$token));
    appleConnectionCheck('wrong identity created no binding or source',$connection->binding()===null && (int)$pdo->query('SELECT COUNT(*) FROM conversation_sources')->fetchColumn()===0);
    $username='Appleservice39_bot';
    $hook=static fn()=>$pdo->exec('DELETE FROM manager_projects WHERE manager_id=1 AND project_id=2');
    appleConnectionRefuses('forbidden',fn()=>$connection->save(1,0,$token));
    $hook=null;$pdo->exec('INSERT INTO manager_projects VALUES(1,2)');
    $connection->save(1,0,$token);
    $binding=$connection->binding();
    appleConnectionCheck('verified source, identity and secret',$binding['source_id']>0 && $binding['source_key']==='telegram:appleservice39-main' && $binding['project_key']==='appleservice39' && $binding['bot_id']==='123456' && $binding['bot_token']===$token);
    appleConnectionCheck('public status is limited to configuration and revision',$connection->status(1)===['configured'=>true,'version'=>1,'webhook_state'=>'not_set','received'=>false]);
    $encrypted=$pdo->query('SELECT credentials_json FROM project_telegram_bot_connections')->fetchColumn();
    appleConnectionCheck('only encrypted credentials stored',!str_contains($encrypted,$token) && !str_contains($encrypted,$binding['webhook_secret']));
    appleConnectionRefuses('context_changed',fn()=>$connection->save(1,0,$next));
    appleConnectionRefuses('identity_mismatch',fn()=>$connection->save(1,1,'234567:'.str_repeat('B',35)));
    $connection->save(1,1,$next);$rotated=$connection->binding();
    appleConnectionCheck('rotation preserves verified bot, webhook and source',$rotated['bot_token']===$next && $rotated['webhook_secret']===$binding['webhook_secret'] && $rotated['source_id']===$binding['source_id'] && $connection->status(1)['version']===2);
    [$http,$savedForm]=$request(1);
    appleConnectionCheck('owner can activate saved bot but cannot publish before registration',$http===200
        && str_contains($savedForm,'value="activate"') && !str_contains($savedForm,'value="publish"')
        && !str_contains($savedForm,$next) && !str_contains($savedForm,$rotated['webhook_secret'])
        && $request(1,['actor'=>'1','csrf'=>'synthetic-client-csrf','action'=>'publish'])[0]===409);
    $target = ['url'=>'https://existing.example.invalid/service','allowed_updates'=>['edited_message'],'max_connections'=>24];
    $writes = 0;
    $beforeWrite = null;
    $mode = 'ok';
    $gateway = static function($candidate,$method,$body) use (&$target,&$writes,&$beforeWrite,&$mode,$rotated,$next) {
        appleConnectionCheck('only the verified Apple token reaches registration',$candidate===$next);
        if ($method==='getMe') return ['http'=>200,'ok'=>true,'result'=>['id'=>123456,'is_bot'=>true,'username'=>'Appleservice39_bot']];
        if ($method==='getWebhookInfo') return ['http'=>200,'ok'=>true,'result'=>$target];
        appleConnectionCheck('only bounded webhook setup may mutate',$method==='setWebhook'
            && $body['url']===AppleService39TelegramConnection::WEBHOOK_URL && $body['drop_pending_updates']===false
            && $body['secret_token']===$rotated['webhook_secret'] && $body['allowed_updates']===['message'] && $body['max_connections']===24);
        $writes++;
        if ($beforeWrite) $beforeWrite();
        if ($mode === 'lost') throw new RuntimeException('synthetic timeout');
        $target=['url'=>$body['url'],'allowed_updates'=>$body['allowed_updates'],'max_connections'=>$body['max_connections']];
        return ['http'=>200,'ok'=>true,'result'=>true];
    };
    $active = new AppleService39TelegramConnection($pdo,$vault,$gateway);
    appleConnectionRefuses('forbidden',fn()=>$active->activate(3,2));
    appleConnectionRefuses('webhook_conflict',fn()=>$active->activate(1,2));
    appleConnectionCheck('foreign proxy is preserved and publication blocked',$writes===0 && $active->status(1)['webhook_state']==='not_set');
    $target['url']='';$target['allowed_updates']=[];
    $mode='lost';
    $beforeWrite=static function() use ($active) {
        $inflight=$active->status(1);
        appleConnectionRefuses('webhook_busy',fn()=>$active->activate(1,$inflight['version']));
    };
    appleConnectionRefuses('webhook_unknown',fn()=>$active->activate(1,2));
    $pending=$active->status(1);
    appleConnectionCheck('unacknowledged setup does not enable catalog',$pending['webhook_state']==='unknown' && $writes===1);
    appleConnectionRefuses('connection_required',fn()=>$active->requireReadyForPublication(1));
    $mode='ok';
    $active->activate(1,$pending['version']);
    $verified=$active->status(1);
    appleConnectionCheck('fresh explicit attempt requires Telegram acceptance and exact readback',$verified['webhook_state']==='verified' && !$verified['received'] && $writes===2);
    $active->activate(1,$verified['version']);$active->requireReadyForPublication(1);
    appleConnectionCheck('rechecking registered webhook performs no write',$writes===2);
    $pdo->prepare("INSERT INTO conversations(id,project_key,channel,source_id) VALUES(19,?,'telegram',?)")->execute(['appleservice39',$rotated['source_id']]);
    $event=$pdo->prepare("INSERT INTO conversation_events(id,conversation_id,event_type,payload_json) VALUES(19,19,'repair_telegram_reply',?)");
    $event->execute([json_encode(['bot_id'=>'999999','state'=>'delivered'])]);
    appleConnectionCheck('another bot cannot prove a completed handoff',!$active->status(1)['received']);
    $pdo->prepare('UPDATE conversation_events SET payload_json=? WHERE id=19')->execute([json_encode(['bot_id'=>$rotated['bot_id'],'state'=>'delivered'])]);
    appleConnectionCheck('owner sees only confirmed scoped delivery flag',$active->status(1)['received']);
    $target['url']='https://existing.example.invalid/service';
    appleConnectionRefuses('webhook_conflict',fn()=>$active->requireReadyForPublication(1));
    appleConnectionCheck('provider drift blocks publication and preserves foreign configuration',$writes===2 && $active->status(1)['webhook_state']==='not_set');
    $pdo->exec("UPDATE conversation_sources SET is_active=0 WHERE project_id=2");
    appleConnectionRefuses('source_unavailable',fn()=>$connection->binding());
    appleConnectionCheck('rotation and reads produced no AnyTour binding',(int)$pdo->query('SELECT COUNT(*) FROM project_telegram_bot_connections WHERE project_id=1')->fetchColumn()===0);
    echo "AppleService39 Telegram connection regressions passed\n";
} finally {
    if (is_resource($server)) { proc_terminate($server);proc_close($server); }
    foreach (['services','manager/lib'] as $symlink) if (is_link($tmp.'/'.$symlink)) unlink($tmp.'/'.$symlink);
    $files=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($tmp,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);
    foreach($files as $file) { if ($file->isDir()) rmdir($file->getPathname()); else unlink($file->getPathname()); }
    rmdir($tmp);
}
