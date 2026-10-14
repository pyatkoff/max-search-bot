<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/services/ChannelPostAdminService.php';

$passed = 0;
function cpaCheck(string $label, bool $ok): void { global $passed; if (!$ok) throw new RuntimeException('FAIL '.$label); $passed++; echo 'PASS '.$label."\n"; }
function cpaSchema(PDO $db): void
{
    $db->exec("CREATE TABLE projects (id INTEGER PRIMARY KEY,project_key TEXT,display_name TEXT,is_active INTEGER);
        CREATE TABLE managers (id INTEGER PRIMARY KEY,login TEXT,display_name TEXT,role TEXT,email TEXT,is_active INTEGER,is_working INTEGER);
        CREATE TABLE manager_projects (manager_id INTEGER,project_id INTEGER);
        CREATE TABLE conversation_sources (id INTEGER PRIMARY KEY,project_id INTEGER,source_key TEXT,display_name TEXT,channel TEXT,handling_mode TEXT,is_active INTEGER);
        CREATE TABLE post_channels (id INTEGER PRIMARY KEY AUTOINCREMENT,project_id INTEGER,source_id INTEGER,platform TEXT,bot_key TEXT,canonical_bot_username TEXT,channel_id TEXT,display_name TEXT,bot_url TEXT,button_text TEXT,is_enabled INTEGER DEFAULT 0,config_version INTEGER DEFAULT 1,created_at TEXT,updated_at TEXT,UNIQUE(platform,channel_id));
        CREATE TABLE post_publications (id INTEGER PRIMARY KEY,post_channel_id INTEGER,source_id INTEGER,external_message_id TEXT,start_payload TEXT,current_version_id INTEGER,created_at TEXT);
        CREATE TABLE post_versions (id INTEGER PRIMARY KEY,publication_id INTEGER,version_number INTEGER,state TEXT,snapshot_json TEXT);
        INSERT INTO projects VALUES (1,'admin-fixture','Synthetic admin project',1),(2,'hidden-project','Hidden project',1);
        INSERT INTO managers VALUES (101,'admin','Synthetic administrator','admin','',1,0),(102,'manager','Synthetic manager','manager','',1,0),(103,'inactive','Synthetic inactive','admin','',0,0),(104,'unscoped','Synthetic unscoped admin','admin','',1,0);
        INSERT INTO manager_projects VALUES (101,1),(102,1),(103,1);
        INSERT INTO conversation_sources VALUES (11,1,'max:manager-fixture','MAX manager fixture','max','manager',1),(12,1,'telegram:manager-fixture','TG manager fixture','telegram','manager',1),(13,1,'max:retired','Retired source','max','manager',0),(14,2,'max:hidden','Hidden source','max','manager',1),(15,1,'max:ask-fixture','MAX ask fixture','max','ask',1),(16,1,'max:ai-fixture','AI source fixture','max','ai',1);");
}
$db = new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]); cpaSchema($db);
$bots = [
    'max'=>['project_key'=>'admin-fixture','platform'=>'max','bot_key'=>'id9704048781_1_bot','bot_url'=>'https://max.ru/id9704048781_1_bot'],
    'telegram'=>['project_key'=>'admin-fixture','platform'=>'telegram','bot_key'=>'Any_tour_bot','bot_url'=>'https://t.me/Any_tour_bot'],
];
$verifyCalls = []; $verification = ['state'=>'ready'];
$service = new ChannelPostAdminService($db,static function(array $channel) use (&$verifyCalls,&$verification): array { $verifyCalls[]=$channel; return $verification; },$bots);
$command = ['project_key'=>'admin-fixture','platform'=>'max','channel_id'=>'80095447388179','source_key'=>'max:manager-fixture','display_name'=>'Synthetic test channel','button_text'=>'Рассчитать на мои даты','is_enabled'=>false,'expected_version'=>0];
cpaCheck('no session, inactive account and manager role cannot access channel administration',$service->snapshot(0)['error']==='unauthorized'&&$service->snapshot(103)['error']==='unauthorized'&&$service->snapshot(102)['error']==='forbidden');
$snapshot = $service->snapshot(101);
cpaCheck('snapshot uses fresh project scope and only exact active platform sources',count($snapshot['projects'])===1&&count($snapshot['sources'])===3&&$snapshot['channels']===[]&&$service->snapshot(104)['projects']===[]);
cpaCheck('no implicit live channel seeds',(int)$db->query('SELECT COUNT(*) FROM post_channels')->fetchColumn()===0);
cpaCheck('unknown project, retired source and mismatched source platform are rejected',$service->save(101,array_replace($command,['project_key'=>'hidden-project']))['error']==='forbidden'&&$service->save(101,array_replace($command,['source_key'=>'max:retired']))['error']==='active_platform_source_required'&&$service->save(101,array_replace($command,['source_key'=>'telegram:manager-fixture']))['error']==='active_platform_source_required');
cpaCheck('source lookup exact string never prefix or SQL wildcard',$service->save(101,array_replace($command,['source_key'=>'max:%']))['error']==='active_platform_source_required');
cpaCheck('AI-mode source cannot be listed or connected without changing source policy',!in_array('max:ai-fixture',array_column($snapshot['sources'],'source_key'),true)&&$service->save(101,array_replace($command,['source_key'=>'max:ai-fixture']))['error']==='active_platform_source_required'&&$db->query("SELECT handling_mode FROM conversation_sources WHERE id=16")->fetchColumn()==='ai');
cpaCheck('input requires boolean enable and integer expected version',$service->save(101,array_replace($command,['is_enabled'=>'true']))['error']==='invalid_enabled'&&$service->save(101,array_replace($command,['expected_version'=>'0']))['error']==='invalid_version');
$saved = $service->save(101,$command+['bot_url'=>'https://max.ru/wrong_bot','bot_key'=>'wrong']);
cpaCheck('explicit disabled setup normalizes channel magnitude, canonical bot identity and config version',$saved['ok']&&$saved['channel']['channel_id']==='-80095447388179'&&$saved['channel']['bot_url']===$bots['max']['bot_url']&&$saved['channel']['config_version']===1&&!$saved['channel']['is_enabled']&&$verifyCalls===[]);
$enable = array_replace($command,['is_enabled'=>true,'expected_version'=>1]);
$verification = ['state'=>'requires_attention','reason'=>'channel_edit_permission_required','private_secret'=>'must not escape'];
$denied = $service->save(101,$enable);
cpaCheck('enable verifies rights without persisting failed attempt or exposing provider data',$denied['error']==='channel_verification_failed'&&$denied['verification']['reason']==='channel_edit_permission_required'&&(int)$db->query('SELECT is_enabled FROM post_channels')->fetchColumn()===0&&!str_contains(json_encode($denied),'must not escape'));
$verification = ['state'=>'unknown','reason'=>'provider_read_failed'];
cpaCheck('provider timeout remains unknown and configuration unchanged',$service->save(101,$enable)['verification']['state']==='unknown'&&(int)$db->query('SELECT config_version FROM post_channels')->fetchColumn()===1);
$verification = ['state'=>'ready']; $enabled = $service->save(101,$enable);
cpaCheck('verified enable persists canonical identity and awaits first publication',$enabled['ok']&&$enabled['channel']['status']==='waiting'&&$enabled['channel']['config_version']===2&&$verifyCalls[count($verifyCalls)-1]['canonical_bot_username']==='id9704048781_1_bot');
$before=count($verifyCalls);
cpaCheck('stale browser cannot overwrite configuration and does not call provider',$service->save(101,$enable)['error']==='channel_configuration_conflict'&&count($verifyCalls)===$before);
$db->exec("INSERT INTO post_publications VALUES (1,1,11,'synthetic-message','p_SYNTHETIC_NOT_REAL',1,'2026-10-14'); INSERT INTO post_versions VALUES (1,1,1,'unknown','{\"private_media_token\":\"NEVER_RETURN\"}');");
$listed=$service->snapshot(101)['channels'][0];
cpaCheck('unknown publication reported as attention with counts and no post/media data',$listed['status']==='attention'&&$listed['publication_count']===1&&$listed['attention_count']===1&&!str_contains(json_encode($listed),'NEVER_RETURN'));
cpaCheck('source becomes immutable after publication exists',$service->save(101,array_replace($command,['source_key'=>'max:ask-fixture','expected_version'=>2]))['error']==='published_channel_source_immutable');
$db->exec("UPDATE post_versions SET state='sent'");
cpaCheck('verified processing reports ready',$service->snapshot(101)['channels'][0]['status']==='ready');
$db->exec("UPDATE conversation_sources SET handling_mode='ai' WHERE id=11");
cpaCheck('source switched to AI is shown as attention without changing its policy',$service->snapshot(101)['channels'][0]['status']==='attention'&&$db->query('SELECT handling_mode FROM conversation_sources WHERE id=11')->fetchColumn()==='ai');
$db->exec("UPDATE conversation_sources SET handling_mode='manager' WHERE id=11");
$db->exec("UPDATE post_versions SET state='pending'");
cpaCheck('pending post does not claim completed processing',$service->snapshot(101)['channels'][0]['status']==='waiting');
$disabled=$service->save(101,array_replace($command,['expected_version'=>2]));
cpaCheck('administrator can disable without provider access, preserving source',!$disabled['channel']['is_enabled']&&$disabled['channel']['source_id']===11);
$revoke = new ChannelPostAdminService($db,static function(array $channel) use ($db): array { $db->exec('DELETE FROM manager_projects WHERE manager_id=101');return ['state'=>'ready']; },$bots);
cpaCheck('project access revoked during verification blocks write',$revoke->save(101,array_replace($enable,['expected_version'=>3]))['error']==='forbidden'&&(int)$db->query('SELECT config_version FROM post_channels')->fetchColumn()===3);
$db->exec('INSERT INTO manager_projects VALUES (101,1)');
$revokeRole = new ChannelPostAdminService($db,static function(array $channel) use ($db): array { $db->exec("UPDATE managers SET role='manager' WHERE id=101");return ['state'=>'ready']; },$bots);
cpaCheck('administrator role revoked during verification blocks write',$revokeRole->save(101,array_replace($enable,['expected_version'=>3]))['error']==='forbidden'&&(int)$db->query('SELECT is_enabled FROM post_channels')->fetchColumn()===0);
$db->exec("UPDATE managers SET role='admin' WHERE id=101");
$casRace = new ChannelPostAdminService($db,static function(array $channel) use ($db): array { $db->exec("UPDATE post_channels SET display_name='Concurrent editor',config_version=config_version+1 WHERE id=1");return ['state'=>'ready']; },$bots);
cpaCheck('concurrent edit during rights verification cannot be overwritten',$casRace->save(101,array_replace($enable,['expected_version'=>3]))['error']==='channel_configuration_conflict'&&$db->query('SELECT display_name FROM post_channels')->fetchColumn()==='Concurrent editor');
$invalidId=$service->save(101,array_replace($command,['channel_id'=>'abc']));
cpaCheck('invalid channel rejected before provider',$invalidId['error']==='invalid_channel_id');
cpaCheck('credentials and bot URLs cannot be supplied from client',$service->snapshot(101)['projects'][0]['bots']['max']['bot_url']===$bots['max']['bot_url']);

final class ChannelPostCommitLostResponse extends PDO
{
    public bool $loseResponse = true;
    public function commit(): bool
    {
        $committed = parent::commit();
        if ($this->loseResponse) { $this->loseResponse = false; throw new RuntimeException('synthetic lost response after commit'); }
        return $committed;
    }
}
$lostDb = new ChannelPostCommitLostResponse('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);cpaSchema($lostDb);
$lostService = new ChannelPostAdminService($lostDb,static fn(array $channel):array=>['state'=>'ready'],$bots);$lost=false;
try { $lostService->save(101,$command); } catch (RuntimeException $e) { $lost=true; }
cpaCheck('readback confirms configuration even when post-commit response is lost',$lost&&$lostService->snapshot(101)['channels'][0]['config_version']===1);
cpaCheck('old expected version cannot repeat a committed unknown configuration',$lostService->save(101,$command)['error']==='channel_configuration_conflict'&&(int)$lostDb->query('SELECT COUNT(*) FROM post_channels')->fetchColumn()===1);

// Actual protected HTTP endpoint and ManagerHttp/ManagerAuth. Synthetic database
// and sessions, no provider calls or live configuration changes.
$sourceRoot=dirname(__DIR__);$httpRoot=sys_get_temp_dir().'/channel-post-admin-'.bin2hex(random_bytes(6));
mkdir($httpRoot,0700);mkdir($httpRoot.'/manager',0700);mkdir($httpRoot.'/sessions',0700);$process=null;
try {
    $httpDb=new PDO('sqlite:'.$httpRoot.'/fixture.sqlite',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);cpaSchema($httpDb);
    $fixtureConfig=['id'=>'admin-fixture','brand'=>['name'=>'Synthetic admin project'],'channel_posts'=>['max'=>['bot_key'=>'id9704048781_1_bot','bot_url'=>'https://max.ru/id9704048781_1_bot'],'telegram'=>['bot_key'=>'Any_tour_bot','bot_url'=>'https://t.me/Any_tour_bot']]];
    file_put_contents($httpRoot.'/config.php',"<?php\nrequire_once ".var_export($sourceRoot.'/services/ManagerRequestContext.php',true).";\n".
        '$fixturePdo=new PDO('.var_export('sqlite:'.$httpRoot.'/fixture.sqlite',true).',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);'."\n".
        '(new ReflectionProperty(ConversationDb::class,"pdo"))->setValue(null,$fixturePdo);'."\n".
        'ProjectConfig::resetForTests('.var_export($fixtureConfig,true).');'."\nProjectAccessService::initializeReadOnly();\n");
    copy($sourceRoot.'/manager/post-channels-api.php',$httpRoot.'/manager/post-channels-api.php');copy($sourceRoot.'/manager/publications.php',$httpRoot.'/manager/publications.php');
    symlink($sourceRoot.'/manager/lib',$httpRoot.'/manager/lib');symlink($sourceRoot.'/services',$httpRoot.'/services');
    file_put_contents($httpRoot.'/manager/fixture-session.php','<?php require dirname(__DIR__)."/config.php";require __DIR__."/lib/ManagerHttp.php";ManagerHttp::startJson();$_SESSION["manager_id"]=(int)($_GET["id"]??101);$_SESSION["csrf"]="synthetic-csrf";ManagerHttp::respond(["ok"=>true]);');
    $socket=stream_socket_server('tcp://127.0.0.1:0',$errno,$errstr);if(!$socket)throw new RuntimeException('Loopback allocation failed');$address=(string)stream_socket_get_name($socket,false);fclose($socket);
    $process=proc_open([PHP_BINARY,'-d','session.save_path='.$httpRoot.'/sessions','-S',$address,'-t',$httpRoot],[0=>['pipe','r'],1=>['file',$httpRoot.'/server.log','a'],2=>['file',$httpRoot.'/server.log','a']],$pipes);if(!is_resource($process))throw new RuntimeException('Loopback start failed');fclose($pipes[0]);
    $ready=false;for($i=0;$i<200;$i++){$probe=@stream_socket_client('tcp://'.$address,$errno,$errstr,.05);if($probe){fclose($probe);$ready=true;break;}usleep(10000);}if(!$ready)throw new RuntimeException('Loopback readiness failed');
    $request=static function(string $path,array $data=[],string $cookie='',string $method='POST')use($address):array {
        $headers=['Content-Type: application/json'];if($cookie!=='')$headers[]='Cookie: '.$cookie;
        $context=stream_context_create(['http'=>['method'=>$method,'header'=>implode("\r\n",$headers),'content'=>$method==='POST'?json_encode($data,JSON_THROW_ON_ERROR):'','ignore_errors'=>true,'timeout'=>3]]);
        $raw=file_get_contents('http://'.$address.$path,false,$context);$responseHeaders=$http_response_header??[];preg_match('/^HTTP\/[^ ]+ ([0-9]+)/',(string)($responseHeaders[0]??''),$status);$newCookie='';
        foreach($responseHeaders as $header)if(preg_match('/^Set-Cookie: (anytour_manager_panel=[^;]+)/i',$header,$match))$newCookie=$match[1];
        return ['status'=>(int)($status[1]??0),'body'=>json_decode((string)$raw,true),'raw'=>(string)$raw,'cookie'=>$newCookie,'headers'=>$responseHeaders];
    };
    cpaCheck('real endpoint rejects anonymous API and HTML requests',$request('/manager/post-channels-api.php',['action'=>'list'])['status']===401&&$request('/manager/publications.php',[],'','GET')['status']===401);
    $cookie=$request('/manager/fixture-session.php?id=102',[],'','GET')['cookie'];
    cpaCheck('real fresh ManagerAuth role protects API and page',$request('/manager/post-channels-api.php',['action'=>'list','csrf'=>'synthetic-csrf'],$cookie)['status']===403&&$request('/manager/publications.php',[],$cookie,'GET')['status']===403);
    $new=$request('/manager/fixture-session.php?id=101',[],$cookie,'GET');$cookie=$new['cookie']?:$cookie;
    cpaCheck('real endpoint requires CSRF and POST',$request('/manager/post-channels-api.php',['action'=>'list'],$cookie)['status']===403&&$request('/manager/post-channels-api.php',[],$cookie,'GET')['status']===405);
    $httpSnapshot=$request('/manager/post-channels-api.php',['action'=>'list','csrf'=>'synthetic-csrf'],$cookie);
    cpaCheck('real authorized list reads only scoped catalog',$httpSnapshot['status']===200&&count($httpSnapshot['body']['projects']??[])===1&&count($httpSnapshot['body']['sources']??[])===3);
    $httpSave=$request('/manager/post-channels-api.php',['action'=>'save','csrf'=>'synthetic-csrf']+$command,$cookie);
    cpaCheck('real CSRF-protected explicit disabled save persists canonical channel',$httpSave['status']===200&&($httpSave['body']['channel']['config_version']??0)===1);
    $httpStale=$request('/manager/post-channels-api.php',['action'=>'save','csrf'=>'synthetic-csrf']+$command,$cookie);
    cpaCheck('real concurrent-editor conflict is HTTP409',$httpStale['status']===409&&$httpStale['body']['error']==='channel_configuration_conflict');
    cpaCheck('real project scope denies other project save',$request('/manager/post-channels-api.php',['action'=>'save','csrf'=>'synthetic-csrf']+array_replace($command,['project_key'=>'hidden-project']),$cookie)['status']===403);
    $httpDb->exec('DELETE FROM manager_projects WHERE manager_id=101');
    cpaCheck('live project revocation hides source catalog in same session',($request('/manager/post-channels-api.php',['action'=>'list','csrf'=>'synthetic-csrf'],$cookie)['body']['projects']??null)===[]);
    $httpDb->exec("UPDATE managers SET role='manager' WHERE id=101");
    cpaCheck('live admin-role revocation applies to same session',$request('/manager/post-channels-api.php',['action'=>'list','csrf'=>'synthetic-csrf'],$cookie)['status']===403);
    $httpDb->exec("UPDATE managers SET role='admin' WHERE id=101;INSERT INTO manager_projects VALUES (101,1);");
    $page=$request('/manager/publications.php',[],$cookie,'GET');
    cpaCheck('admin HTML renders labeled one-time controls and private csrf',$page['status']===200&&str_contains($page['raw'],'data-csrf="synthetic-csrf"')&&str_contains($page['raw'],'Добавлять кнопки автоматически'));
    $httpDb->exec('DROP TABLE post_channels');
    $storage=$request('/manager/post-channels-api.php',['action'=>'save','csrf'=>'synthetic-csrf']+$command,$cookie);
    cpaCheck('storage outage returns unknown save without raw diagnostic',$storage['status']===503&&$storage['body']['error']==='save_outcome_unknown'&&!str_contains($storage['raw'],'SQLSTATE'));
} finally {
    if(is_resource($process)){proc_terminate($process);proc_close($process);}
    $files=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($httpRoot,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);
    foreach($files as $file){if($file->isLink()||$file->isFile())unlink($file->getPathname());else rmdir($file->getPathname());}rmdir($httpRoot);
}
echo "TOTAL {$passed} | PASS {$passed}\n";
