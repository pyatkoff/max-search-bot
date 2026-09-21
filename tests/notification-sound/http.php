<?php
/** Isolated CI HTTP/session acceptance. Never accepts application DB parameters. */
declare(strict_types=1);
if(getenv('CI')!=='true'||getenv('SOUND_MYSQL_TEST')!=='1')throw new RuntimeException('isolated_ci_only');
$root=dirname(__DIR__,2);
$tmp=sys_get_temp_dir().'/sound-http-'.bin2hex(random_bytes(6));
mkdir($tmp.'/manager/lib',0700,true);mkdir($tmp.'/sessions',0700);
symlink($root.'/services',$tmp.'/services');
copy($root.'/manager/notification-events.php',$tmp.'/manager/notification-events.php');
copy($root.'/manager/lib/ManagerHttp.php',$tmp.'/manager/lib/ManagerHttp.php');
$config="<?php\nini_set('session.save_path',__DIR__.'/sessions');\ndefine('CONVERSATION_DB_HOST','127.0.0.1');define('CONVERSATION_DB_NAME','sound_test');define('CONVERSATION_DB_USER','root');define('CONVERSATION_DB_PASS','sound-local-test-only');\nrequire_once __DIR__.'/services/ProjectConfig.php';ProjectConfig::resetForTests(['id'=>'allowed','brand'=>['name'=>'fixture']]);\n";
file_put_contents($tmp.'/config.php',$config);
session_save_path($tmp.'/sessions');session_name('anytour_manager_panel');session_id('soundfixture123');session_start();
$_SESSION=['manager_id'=>7,'csrf'=>'fixture-csrf'];session_write_close();
$pdo=new PDO('mysql:host=127.0.0.1;dbname=sound_test;charset=utf8mb4','root','sound-local-test-only',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$process=proc_open([PHP_BINARY,'-d','display_errors=0','-S','127.0.0.1:4191','-t',$tmp],[0=>['file','/dev/null','r'],1=>['file',$tmp.'/http.log','a'],2=>['file',$tmp.'/http.log','a']],$pipes);
if(!is_resource($process))throw new RuntimeException('fixture_server_failed');
$count=0;
function httpCheck(string $name,bool $ok):void{global$count;if(!$ok)throw new RuntimeException($name);$count++;echo "PASS $name\n";}
function requestFixture(array $data,bool $session=true):array{
    $headers="Content-Type: application/json\r\n".($session?"Cookie: anytour_manager_panel=soundfixture123\r\n":'');
    $context=stream_context_create(['http'=>['method'=>'POST','header'=>$headers,'content'=>json_encode($data),'ignore_errors'=>true,'timeout'=>3,'follow_location'=>0]]);
    $body=@file_get_contents('http://127.0.0.1:4191/manager/notification-events.php',false,$context);
    preg_match('/^HTTP\/\S+ (\d+)/',$http_response_header[0]??'',$match);
    return ['status'=>(int)($match[1]??0),'json'=>json_decode((string)$body,true),'headers'=>$http_response_header??[],'body'=>(string)$body];
}
try{
    for($i=0;$i<30;$i++){if(requestFixture([],false)['status']===401)break;usleep(100000);}
    $data=['action'=>'poll','csrf'=>'fixture-csrf','cursor'=>null];
    $anonymous=requestFixture($data,false);httpCheck('anonymous HTTP request is denied',$anonymous['status']===401&&!isset($anonymous['json']['events']));
    $csrf=requestFixture(['action'=>'poll','csrf'=>'incorrect','cursor'=>null]);httpCheck('real session does not bypass CSRF',$csrf['status']===403);
    $invalid=requestFixture(['action'=>'poll','csrf'=>'fixture-csrf','cursor'=>'1']);httpCheck('invalid cursor is rejected with422',$invalid['status']===422);
    $before=$pdo->query('SELECT * FROM manager_reads')->fetchAll(PDO::FETCH_ASSOC);
    $ok=requestFixture($data);httpCheck('authenticated HTTP endpoint returns the isolated two arrivals',$ok['status']===200&&$ok['json']['manager_id']===7&&count($ok['json']['events'])===2);
    httpCheck('HTTP response is noncacheable',str_contains(strtolower(implode("\n",$ok['headers'])),'cache-control: no-store'));
    httpCheck('HTTP event projection contains no message text or media metadata',array_keys($ok['json']['events'][0])===['conversation_id','message_id']);
    httpCheck('HTTP poll preserves stored read cursor',$pdo->query('SELECT * FROM manager_reads')->fetchAll(PDO::FETCH_ASSOC)===$before);
    $pdo->exec('UPDATE managers SET is_active=0 WHERE id=7');$disabled=requestFixture($data);
    httpCheck('disabled manager session is denied by actual HTTP auth',$disabled['status']===401);
    $pdo->exec('UPDATE managers SET is_active=1 WHERE id=7');
    $pdo->exec('DELETE FROM manager_projects WHERE manager_id=7');$revoked=requestFixture($data);
    httpCheck('project revocation is effective on the next HTTP poll',$revoked['status']===200&&$revoked['json']['events']===[]);
    $pdo->exec('INSERT INTO manager_projects VALUES(7,1)');
    $pdo->exec('CREATE TABLE manager_group_members(manager_id INTEGER,group_id INTEGER)');
    $pdo->exec("INSERT INTO conversation_sources VALUES(10,44,'none',0,0,1)");$source=requestFixture($data);
    httpCheck('source-group access is enforced by the actual policy',$source['status']===200&&$source['json']['events']===[]);
    $pdo->exec('INSERT INTO manager_group_members VALUES(7,44)');
    httpCheck('authorized source membership restores only eligible events',count(requestFixture($data)['json']['events'])===2);
    $pdo->exec('UPDATE projects SET is_active=0 WHERE id=1');$drift=requestFixture($data);
    httpCheck('inactive project fails closed without hidden repair',$drift['status']===503&&$drift['json']['error']==='notification_feed_unavailable');
    httpCheck('bootstrap did not reactivate the project',(int)$pdo->query('SELECT is_active FROM projects WHERE id=1')->fetchColumn()===0);
    httpCheck('HTTP errors contain no SQL details',!str_contains($drift['body'],'SELECT')&&!str_contains($drift['body'],'SQLSTATE'));
    $pdo->exec('UPDATE projects SET is_active=1 WHERE id=1');
    httpCheck('manager shift remains untouched',(int)$pdo->query('SELECT is_working FROM managers WHERE id=7')->fetchColumn()===1);
    echo "TOTAL $count HTTP CHECKS PASSED\n";
}finally{
    proc_terminate($process);proc_close($process);
    unlink($tmp.'/services');
    foreach(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($tmp,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST) as $file){if($file->isDir())rmdir($file->getPathname());else unlink($file->getPathname());}
    rmdir($tmp);
}
