<?php

declare(strict_types=1);
if (PHP_SAPI !== 'cli') {http_response_code(404);exit;}

// Exercise the real endpoint, ManagerHttp, fresh canonical visibility policy and
// registry SQL on loopback. All identities, sessions and captions are synthetic.
// SQLite query_only makes an incidental auth bootstrap/read-state write a failure.
$root=dirname(__DIR__);
$tmp=sys_get_temp_dir().'/manager-post-context-'.bin2hex(random_bytes(6));
mkdir($tmp,0700);mkdir($tmp.'/manager',0700);mkdir($tmp.'/sessions',0700);
$process=null;$db=null;$passed=0;
function pcCheck(string $name,bool $ok):void {global $passed;if (!$ok) throw new RuntimeException('FAIL '.$name);$passed++;echo 'PASS '.$name."\n";}
try {
    $db=new PDO('sqlite:'.$tmp.'/fixture.sqlite',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
    $db->exec("CREATE TABLE projects (id INTEGER PRIMARY KEY,project_key TEXT,display_name TEXT,is_active INTEGER);
        CREATE TABLE managers (id INTEGER PRIMARY KEY,login TEXT,display_name TEXT,role TEXT,email TEXT,is_active INTEGER,is_working INTEGER);
        CREATE TABLE manager_projects (manager_id INTEGER,project_id INTEGER);
        CREATE TABLE customers (id INTEGER PRIMARY KEY,display_name TEXT,phone TEXT,email TEXT);
        CREATE TABLE manager_customer_names (customer_id INTEGER PRIMARY KEY,working_name TEXT,version INTEGER,updated_by_manager_id INTEGER,updated_at TEXT);
        CREATE TABLE conversation_sources (id INTEGER PRIMARY KEY,project_id INTEGER,source_key TEXT,display_name TEXT,channel TEXT,is_active INTEGER,primary_group_id INTEGER,fallback_mode TEXT,fallback_group_id INTEGER,fallback_after_minutes INTEGER);
        CREATE TABLE manager_group_members (manager_id INTEGER,group_id INTEGER);
        CREATE TABLE conversations (id INTEGER PRIMARY KEY,project_key TEXT,source_id INTEGER,channel TEXT,entry_channel TEXT,attribution_region TEXT,attribution_campaign TEXT,status TEXT,lead_stage_key TEXT,manager_id INTEGER,started_at TEXT,last_message_at TEXT,closed_at TEXT,external_chat_id TEXT,customer_id INTEGER);
        CREATE TABLE post_channels (id INTEGER PRIMARY KEY,project_id INTEGER,platform TEXT,channel_id TEXT,display_name TEXT);
        CREATE TABLE post_publications (id INTEGER PRIMARY KEY,post_channel_id INTEGER,source_id INTEGER,external_message_id TEXT,current_version_id INTEGER);
        CREATE TABLE post_versions (id INTEGER PRIMARY KEY,publication_id INTEGER,version_number INTEGER,snapshot_json TEXT);
        CREATE TABLE post_entries (id INTEGER PRIMARY KEY,conversation_id INTEGER,publication_id INTEGER,version_id INTEGER,availability_reason TEXT DEFAULT '',created_at TEXT);
        INSERT INTO projects VALUES (1,'post-fixture','Synthetic workspace',1),(2,'foreign-project','Foreign workspace',1);
        INSERT INTO managers VALUES (7,'fixture-manager','Manager','manager','',1,1),(8,'other','Other','manager','',1,1),(9,'admin','Admin','admin','',1,1),(10,'disabled','Inactive','manager','',0,1);
        INSERT INTO manager_projects VALUES (7,1),(8,2),(9,1),(10,1);
        INSERT INTO customers VALUES (1,'Synthetic tourist','','');
        INSERT INTO conversation_sources VALUES (11,1,'max_anytour_msk1','Москва','max',1,0,'none',0,0),(12,2,'foreign_source','Foreign','max',1,0,'none',0,0);
        INSERT INTO conversations VALUES (101,'post-fixture',11,'max','max','','','manager','new',7,'2026-10-14 09:00:00','2026-10-14 09:20:00',NULL,'fixture-only',1),(102,'foreign-project',12,'max','max','','','manager','new',8,'2026-10-14 09:00:00','2026-10-14 09:20:00',NULL,'foreign-only',1),(103,'post-fixture',11,'max','max','','','manager','new',7,'2026-10-14 09:00:00','2026-10-14 09:20:00',NULL,'empty-only',1);
        INSERT INTO post_channels VALUES (1,1,'max','-80095447388179','Тест публикаций'),(2,2,'max','-99999','Чужой канал');
        INSERT INTO post_publications VALUES (1,1,11,'AaE53iyDMmw',2),(2,2,12,'foreignpost',3);
        INSERT INTO post_entries VALUES (1,101,1,1,'','2026-10-14 09:20:00'),(2,102,2,3,'','2026-10-14 09:21:00');");
    $snapshot=['title'=>'Турция — октябрь','text'=>'Цена из публикации: 150 000 ₽ за двоих.','post_url'=>'https://max.ru/c/-80095447388179/AaE53iyDMmw','format'=>'html','published_at'=>'2026-10-14 09:00:00','attachments'=>[['type'=>'image','url'=>'https://private.test/signed?token=fixture-secret']], 'metadata'=>['token'=>'fixture-secret']];
    $insert=$db->prepare('INSERT INTO post_versions VALUES (?,?,?,?)');
    $insert->execute([1,1,1,json_encode($snapshot,JSON_UNESCAPED_UNICODE)]);
    $snapshot['text']='Изменённый позднее пост: 170 000 ₽ за двоих.';
    $insert->execute([2,1,2,json_encode($snapshot,JSON_UNESCAPED_UNICODE)]);
    $snapshot['text']='Секрет чужого проекта';$insert->execute([3,2,1,json_encode($snapshot,JSON_UNESCAPED_UNICODE)]);
    file_put_contents($tmp.'/config.php',"<?php\nrequire_once ".var_export($root.'/services/ManagerRequestContext.php',true).";\n".
        '$fixturePdo=new PDO('.var_export('sqlite:'.$tmp.'/fixture.sqlite',true).',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);' . "\n".
        '(new ReflectionProperty(ConversationDb::class,"pdo"))->setValue(null,$fixturePdo);' . "\n".
        'ProjectConfig::resetForTests(["id"=>"post-fixture","brand"=>["name"=>"Synthetic workspace"]]);' . "\n".
        '$fixturePdo->exec("PRAGMA query_only=ON");' . "\n");
    copy($root.'/manager/post-context-api.php',$tmp.'/manager/post-context-api.php');
    symlink($root.'/manager/lib',$tmp.'/manager/lib');symlink($root.'/services',$tmp.'/services');
    file_put_contents($tmp.'/manager/fixture-session.php','<?php require dirname(__DIR__)."/config.php";require __DIR__."/lib/ManagerHttp.php";ManagerHttp::startJson();$_SESSION["manager_id"]=(int)($_GET["id"]??7);$_SESSION["csrf"]="fixture-csrf-only";ManagerHttp::respond(["ok"=>true]);');
    $socket=stream_socket_server('tcp://127.0.0.1:0',$errno,$errstr);if (!$socket) throw new RuntimeException('Cannot allocate loopback fixture');
    $address=(string)stream_socket_get_name($socket,false);fclose($socket);
    $process=proc_open([PHP_BINARY,'-d','session.save_path='.$tmp.'/sessions','-S',$address,'-t',$tmp],[0=>['pipe','r'],1=>['file',$tmp.'/server.log','a'],2=>['file',$tmp.'/server.log','a']],$pipes);
    if (!is_resource($process)) throw new RuntimeException('Cannot start fixture HTTP server');fclose($pipes[0]);
    $ready=false;for ($i=0;$i<100;$i++) {if($probe=@stream_socket_client('tcp://'.$address,$e,$s,.05)){fclose($probe);$ready=true;break;}usleep(10000);}
    if (!$ready) throw new RuntimeException('Fixture HTTP server did not start');
    $request=static function(string $path,array $data=[],string $cookie='',string $method='POST') use ($address):array {
        $headers=['Content-Type: application/json'];if ($cookie!=='') $headers[]='Cookie: '.$cookie;
        $ctx=stream_context_create(['http'=>['method'=>$method,'header'=>implode("\r\n",$headers),'content'=>$method==='POST'?json_encode($data):'','ignore_errors'=>true,'timeout'=>3]]);
        $body=(string)file_get_contents('http://'.$address.$path,false,$ctx);$responseHeaders=$http_response_header??[];
        preg_match('/^HTTP\/[^ ]+ ([0-9]+)/',(string)($responseHeaders[0]??''),$status);$responseCookie='';
        foreach ($responseHeaders as $header) if (preg_match('/^Set-Cookie: (anytour_manager_panel=[^;]+)/i',$header,$m)) $responseCookie=$m[1];
        return ['status'=>(int)($status[1]??0),'json'=>json_decode($body,true),'body'=>$body,'cookie'=>$responseCookie,'headers'=>$responseHeaders];
    };
    $read=static fn(int $id,string $cookie,array $extra=[]):array=>$request('/manager/post-context-api.php',array_replace(['action'=>'detail','csrf'=>'fixture-csrf-only','conversation_id'=>$id],$extra),$cookie);
    $anonymous=$request('/manager/post-context-api.php',['action'=>'detail','conversation_id'=>101]);
    pcCheck('anonymous endpoint read is 401 without any post content',$anonymous['status']===401&&$anonymous['json']['error']==='unauthorized'&&!str_contains($anonymous['body'],'150 000'));
    $cookie=$request('/manager/fixture-session.php?id=7',[],'','GET')['cookie'];
    pcCheck('fixture uses canonical Manager session cookie',$cookie!=='');
    $csrf=$read(101,$cookie,['csrf'=>'']);pcCheck('missing CSRF cannot read private post context',$csrf['status']===403&&$csrf['json']['error']==='csrf');
    $valid=$read(101,$cookie);
    pcCheck('real fresh auth and ACL read succeeds under query-only PDO',$valid['status']===200&&$valid['json']['ok']&&$valid['json']['conversation_id']===101);
    pcCheck('entry displays immutable version at entry rather than changed current post',$valid['json']['context']['current']['version_number']===1&&str_contains($valid['json']['context']['current']['text'],'150 000')&&!str_contains($valid['body'],'170 000'));
    pcCheck('protected JSON never contains raw media URLs or metadata tokens',!str_contains($valid['body'],'private.test')&&!str_contains($valid['body'],'fixture-secret')&&$valid['json']['context']['current']['media_count']===1);
    pcCheck('response uses no-store JSON headers',count(array_filter($valid['headers'],static fn(string $h):bool=>str_starts_with(strtolower($h),'cache-control: no-store')))===1);
    $empty=$read(103,$cookie);pcCheck('legacy conversation returns honest empty context',$empty['status']===200&&$empty['json']['context']===['current'=>null,'history'=>[]]);
    $foreign=$read(102,$cookie);pcCheck('known foreign conversation ID cannot cross project ACL',$foreign['status']===404&&$foreign['json']['error']==='not_found'&&!str_contains($foreign['body'],'Секрет'));
    $db->exec('DELETE FROM manager_projects WHERE manager_id=7');
    pcCheck('project permission revocation takes effect on the very next read',$read(101,$cookie)['status']===404);
    $db->exec('INSERT INTO manager_projects VALUES (7,1);UPDATE conversation_sources SET primary_group_id=20 WHERE id=11');
    pcCheck('source group restriction is rechecked despite prior authorized read',$read(101,$cookie)['status']===404);
    $db->exec('INSERT INTO manager_group_members VALUES (7,20)');pcCheck('fresh source membership restores exactly the permitted context',$read(101,$cookie)['status']===200);
    $db->exec("UPDATE conversations SET status='waiting_manager' WHERE id=101;UPDATE managers SET is_working=0 WHERE id=7");
    pcCheck('out-of-shift manager cannot read waiting source context',$read(101,$cookie)['status']===404);
    $db->exec("UPDATE conversations SET status='manager' WHERE id=101;UPDATE managers SET is_working=1 WHERE id=7");
    $db->exec('UPDATE managers SET is_active=0 WHERE id=7');pcCheck('disabled account is rejected before reading stored post',$read(101,$cookie)['status']===401);$db->exec('UPDATE managers SET is_active=1 WHERE id=7');
    foreach ([0,-1,'101',[],null] as $badId) { $bad=$read(101,$cookie,['conversation_id'=>$badId]);pcCheck('malformed conversation identifier rejected '.json_encode($badId),$bad['status']===422); }
    $method=$request('/manager/post-context-api.php',[],$cookie,'GET');pcCheck('read endpoint rejects unsupported HTTP methods',$method['status']===405);
    $db->exec("INSERT INTO post_entries VALUES (3,101,1,2,'','2026-10-14 09:25:00')");
    $later=$read(101,$cookie);pcCheck('new entry becomes current while prior version remains separate history',$later['json']['context']['current']['version_number']===2&&$later['json']['context']['history'][0]['version_number']===1);
    $db->exec("INSERT INTO post_entries VALUES (4,101,NULL,NULL,'unavailable','2026-10-14 09:26:00')");
    $unavailable=$read(101,$cookie);pcCheck('unknown latest post marks unavailable and never promotes a previous confirmed post',$unavailable['json']['context']['current']['unavailable']===true&&$unavailable['json']['context']['current']['text']===''&&count($unavailable['json']['context']['history'])===2);
    for ($i=5;$i<=14;$i++) $db->exec("INSERT INTO post_entries VALUES ({$i},101,1,2,'','2026-10-14 09:27:00')");
    $bounded=$read(101,$cookie);pcCheck('manager context is bounded to current plus four previous entries',count($bounded['json']['context']['history'])===4&&$bounded['json']['context']['current']['entry_id']===14);
    $adminCookie=$request('/manager/fixture-session.php?id=9',[],'','GET')['cookie'];pcCheck('admin retains project scope rather than universal post access',$read(102,$adminCookie)['status']===404);
    $db->exec('DROP TABLE post_entries');$failure=$read(101,$cookie);pcCheck('storage failure is 503 without stale/empty success or exception details',$failure['status']===503&&$failure['json']===['ok'=>false,'error'=>'post_context_unavailable']);
} finally {
    if (is_resource($process)) {proc_terminate($process);proc_close($process);}
    $db=null;
    $remove=static function(string $path) use (&$remove):void {if (is_link($path)||is_file($path)) {unlink($path);return;}if (is_dir($path)) {foreach (scandir($path)?:[] as $entry) if ($entry!=='.'&&$entry!=='..') $remove($path.'/'.$entry);rmdir($path);}};
    $remove($tmp);
}
echo "TOTAL {$passed} passed\n";
