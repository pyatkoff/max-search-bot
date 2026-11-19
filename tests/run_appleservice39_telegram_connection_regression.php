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
    $pdo->exec("CREATE TABLE project_telegram_bot_connections(project_id INTEGER PRIMARY KEY,bot_id TEXT NOT NULL UNIQUE,credentials_json TEXT NOT NULL,version INTEGER NOT NULL)");
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
    appleConnectionCheck('token and publication actions require CSRF',$request(1,$form)[0]===403 && $request(1,['actor'=>'1','csrf'=>'wrong','action'=>'publish'])[0]===403);
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
    appleConnectionCheck('public status is limited to configuration and revision',$connection->status(1)===['configured'=>true,'version'=>1]);
    $encrypted=$pdo->query('SELECT credentials_json FROM project_telegram_bot_connections')->fetchColumn();
    appleConnectionCheck('only encrypted credentials stored',!str_contains($encrypted,$token) && !str_contains($encrypted,$binding['webhook_secret']));
    appleConnectionRefuses('context_changed',fn()=>$connection->save(1,0,$next));
    appleConnectionRefuses('identity_mismatch',fn()=>$connection->save(1,1,'234567:'.str_repeat('B',35)));
    $connection->save(1,1,$next);$rotated=$connection->binding();
    appleConnectionCheck('rotation preserves verified bot, webhook and source',$rotated['bot_token']===$next && $rotated['webhook_secret']===$binding['webhook_secret'] && $rotated['source_id']===$binding['source_id'] && $connection->status(1)['version']===2);
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
