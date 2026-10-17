<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__.'/../services/TelegramArchiveBotRuntime.php';
require_once __DIR__.'/../services/TelegramArchiveBotSetup.php';
require_once __DIR__.'/fixtures/TelegramArchiveHttpFixture.php';

function runTelegramArchiveSetupChecks(PDO $pdo, $dsn): void
{
    $directory = sys_get_temp_dir().'/telegram-archive-config-'.bin2hex(random_bytes(8));
    $store = new TelegramArchiveBotConfig($directory);
    $token = '55555:ABCDEFGHIJKLMNOPQRSTUVWX';
    $calls = []; $provider = ['url'=>'','allowed_updates'=>[],'has_custom_certificate'=>false,'max_connections'=>23];
    $mode = 'success'; $business = true;
    $request = static function(string $provided, string $method, array $body) use (&$calls,&$provider,&$mode,&$business,$token): array {
        $calls[] = ['method'=>$method,'body'=>$body];
        if ($method === 'getMe') return ['http'=>200,'ok'=>true,'result'=>['id'=>55555,'is_bot'=>true,'username'=>$mode === 'wrong_bot' ? 'Other_bot' : 'Anytourtg_bot','can_connect_to_business'=>$business]];
        if ($method === 'getWebhookInfo') return ['http'=>200,'ok'=>true,'result'=>$provider];
        if ($mode === 'unknown') return ['http'=>0,'ok'=>false];
        $provider = ['url'=>$body['url'],'allowed_updates'=>$body['allowed_updates'],'has_custom_certificate'=>false,'max_connections'=>$body['max_connections']];
        return ['http'=>200,'ok'=>true,'result'=>true];
    };
    $setup = new TelegramArchiveBotSetup($store,$request);
    try {
        archiveCheck('dedicated bot begins unconfigured without creating storage', !$store->publicStatus()['configured'] && !file_exists($directory));
        $mode='wrong_bot';
        archiveCheck('dedicated setup rejects another bot before webhook access or credential write', archiveThrows(fn()=>$setup->configure($token)) && count($calls)===1 && $calls[0]['method']==='getMe' && $store->load()===null);
        $mode='success'; $calls=[]; $provider['url']='https://other.invalid/webhook';
        archiveCheck('dedicated setup refuses to take over a different webhook', archiveThrows(fn()=>$setup->configure($token)) && count($calls)===2 && $store->load()===null);
        $provider['url']=''; $calls=[];
        $status=$setup->configure($token); $saved=$store->load();
        archiveCheck('dedicated setup verifies selected bot and webhook within five calls', $status['configured'] && $status['webhook_ready'] && $status['business_mode_enabled'] && count($calls)===5);
        $writes=array_values(array_filter($calls,static fn(array $call):bool=>$call['method']==='setWebhook'));
        archiveCheck('dedicated webhook receives exactly four Business types without dropping queue', count($writes)===1 && $writes[0]['body']['allowed_updates']===TelegramDialogueNormalizer::UPDATE_TYPES && $writes[0]['body']['drop_pending_updates']===false && $writes[0]['body']['max_connections']===23);
        archiveCheck('token and own webhook secret never appear in public status', !str_contains(json_encode($status),$token) && !str_contains(json_encode($status),$saved['secret']));
        clearstatcache();
        archiveCheck('dedicated credentials live outside checkout in owner-only storage', (fileperms($directory)&0777)===0700 && (fileperms($directory.'/bot.json')&0777)===0600 && $saved['secret']!==TELEGRAM_WEBHOOK_SECRET);
        $setup->configure($token);
        archiveCheck('repeat setup reads verified target without another provider write', count(array_filter($calls,static fn(array $call):bool=>$call['method']==='setWebhook'))===1);
        $business=false; $read=$setup->inspect();
        archiveCheck('Secretary Mode state refresh is read-only and exposes disabled mode', !$read['business_mode_enabled'] && count(array_filter($calls,static fn(array $call):bool=>$call['method']==='setWebhook'))===1);
        archiveCheck('dedicated endpoint rejects the ordinary bots webhook secret', !TelegramArchiveBotRuntime::authenticated($saved,TELEGRAM_WEBHOOK_SECRET) && TelegramArchiveBotRuntime::authenticated($saved,$saved['secret']));
        $other=new TelegramArchiveBotConfig($directory.'/unknown'); $unknown=new TelegramArchiveBotSetup($other,$request); $provider['url']=''; $mode='unknown'; $calls=[];
        archiveCheck('lost setup response leaves private UNKNOWN marker', archiveThrows(fn()=>$unknown->configure($token)) && $other->publicStatus()['needs_readback']);
        archiveCheck('UNKNOWN setup is not blindly sent a second time', archiveThrows(fn()=>$unknown->configure($token)) && count(array_filter($calls,static fn(array $call):bool=>$call['method']==='setWebhook'))===1);
        $other->authenticatedEvent($other->load()['secret']);
        archiveCheck('authenticated provider delivery can resolve UNKNOWN without replaying setup', $other->publicStatus()['webhook_ready']);
        archiveCheck('credential path inside checkout is rejected', archiveThrows(fn()=>new TelegramArchiveBotConfig(dirname(__DIR__).'/unsafe-archive-config')));
        symlink($directory.'/bot.json',$directory.'/linked.json');
        rename($directory.'/bot.json',$directory.'/retained.json'); symlink($directory.'/retained.json',$directory.'/bot.json');
        archiveCheck('credential symlinks are rejected without reading a substituted token', archiveThrows(fn()=>$store->load()));
        unlink($directory.'/bot.json'); rename($directory.'/retained.json',$directory.'/bot.json');

        if ($dsn !== false && $dsn !== '') {
            $sql=(string)file_get_contents(__DIR__.'/../migrations/042_telegram_archive_accounts.sql'); $pdo->exec($sql); $pdo->exec($sql);
        } else $pdo->exec('CREATE TABLE telegram_archive_accounts (project_key TEXT,bot_key TEXT,account_id TEXT,connection_id TEXT,display_name TEXT,username TEXT,connection_date INTEGER,last_update_id INTEGER,provider_enabled INTEGER,approved INTEGER,manager_id INTEGER,PRIMARY KEY(project_key,bot_key,account_id),UNIQUE(project_key,bot_key,connection_id))');
        $pdo->exec('CREATE TABLE managers (id INTEGER PRIMARY KEY,login VARCHAR(64),display_name VARCHAR(255),role VARCHAR(32),email VARCHAR(255),is_active INTEGER,is_working INTEGER)');
        $pdo->exec('CREATE TABLE projects (id INTEGER PRIMARY KEY,project_key VARCHAR(64),display_name VARCHAR(255),is_active INTEGER)');
        $pdo->exec('CREATE TABLE manager_projects (manager_id INTEGER,project_id INTEGER)');
        $pdo->exec("INSERT INTO projects VALUES (1,'anytour','anytour',1),(2,'foreign','foreign',1)");
        $pdo->exec("INSERT INTO managers VALUES (11,'first','Первый','manager','',1,0),(12,'second','Второй','manager','',1,0),(13,'inactive','Неактивный','manager','',0,0),(14,'foreign','Чужой','manager','',1,0)");
        $pdo->exec('INSERT INTO manager_projects VALUES (11,1),(12,1),(13,1),(14,2)');
        $property=new ReflectionProperty(ConversationDb::class,'pdo'); $property->setValue(null,$pdo); ProjectAccessService::initializeReadOnly();
        $registry=new TelegramArchiveAccountRegistry($pdo);
        $config=$saved; $_SERVER['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN']=$config['secret'];
        $connection=['update_id'=>101,'business_connection'=>['id'=>'dedicated-connection','user'=>['id'=>1000,'first_name'=>'Тестовый менеджер','username'=>'test_manager'],'date'=>1792231000,'is_enabled'=>true]];
        archiveCheck('dedicated account connection registers metadata pending approval', TelegramArchiveBotRuntime::receive($connection,$config,$pdo)['state']==='connection_registered' && !$registry->listing('anytour',TelegramArchiveBotConfig::USERNAME)[0]['approved']);
        $message=['update_id'=>102,'business_message'=>['business_connection_id'=>'dedicated-connection','message_id'=>1,'date'=>1792231001,'chat'=>['id'=>2000,'type'=>'private'],'from'=>['id'=>1000],'text'=>'Синтетическая подборка']];
        archiveCheck('pending account cannot capture messages', TelegramArchiveBotRuntime::receive($message,$config,$pdo)['state']==='account_not_approved' && iterator_count((new TelegramDialogueArchive($pdo))->export('anytour','1000',['2000']))===0);
        archiveCheck('inactive and foreign-project managers cannot approve capture', archiveThrows(fn()=>$registry->approve('anytour',TelegramArchiveBotConfig::USERNAME,'1000',13)) && archiveThrows(fn()=>$registry->approve('anytour',TelegramArchiveBotConfig::USERNAME,'1000',14)));
        $registry->approve('anytour',TelegramArchiveBotConfig::USERNAME,'1000',11);
        archiveCheck('approved dedicated account archives incoming data in canonical store', TelegramArchiveBotRuntime::receive($message,$config,$pdo)['stored']===1);
        $registry->approve('anytour',TelegramArchiveBotConfig::USERNAME,'1000',12);
        $message['business_message']['message_id']=2; $message['update_id']=103;
        TelegramArchiveBotRuntime::receive($message,$config,$pdo);
        $rows=iterator_to_array((new TelegramDialogueArchive($pdo))->export('anytour','1000',['2000']));
        archiveCheck('manager annotation follows explicit account mapping', $rows[1]['manager_id']==='12');
        $registry->approve('anytour',TelegramArchiveBotConfig::USERNAME,'1000',null);
        $message['business_message']['message_id']=3; $message['update_id']=104;
        archiveCheck('admin stopping capture fences later writes', TelegramArchiveBotRuntime::receive($message,$config,$pdo)['stored']===0 && iterator_count((new TelegramDialogueArchive($pdo))->export('anytour','1000',['2000']))===2);
        $guardObserved=false;
        (new TelegramDialogueArchive($pdo))->receive('anytour',TelegramArchiveBotConfig::USERNAME,'dedicated-connection',[],null,static function(string $id) use($pdo,$registry,&$guardObserved){ $guardObserved=$pdo->inTransaction(); return $registry->capturePermission('anytour',TelegramArchiveBotConfig::USERNAME,$id,'dedicated-connection'); });
        archiveCheck('approval is rechecked inside the archive transaction', $guardObserved);
        $registry->approve('anytour',TelegramArchiveBotConfig::USERNAME,'1000',11);
        if ($dsn !== false && $dsn !== '') {
            $otherPdo=new PDO($dsn,'root','archive-test-only',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false]); $otherPdo->exec('SET innodb_lock_wait_timeout=1'); $blocked=false;
            (new TelegramDialogueArchive($pdo))->receive('anytour',TelegramArchiveBotConfig::USERNAME,'dedicated-connection',[],null,static function(string $id) use($registry,$otherPdo,&$blocked){
                $permission=$registry->capturePermission('anytour',TelegramArchiveBotConfig::USERNAME,$id,'dedicated-connection');
                try { $otherPdo->exec("UPDATE telegram_archive_accounts SET approved=0 WHERE account_id=1000"); } catch(PDOException $error) { $blocked=(int)($error->errorInfo[1]??0)===1205; }
                return $permission;
            });
            archiveCheck('native MySQL approval row remains locked through archive write boundary', $blocked);
        }
        $revoked=$connection; $revoked['update_id']=105; $revoked['business_connection']['is_enabled']=false;
        TelegramArchiveBotRuntime::receive($revoked,$config,$pdo);
        archiveCheck('Telegram disconnect stops the dedicated bot even for approved account', TelegramArchiveBotRuntime::receive($message,$config,$pdo)['state']==='disconnected');
        $_SERVER['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN']=TELEGRAM_WEBHOOK_SECRET;
        archiveCheck('ordinary bot secret cannot call dedicated runtime directly', archiveThrows(fn()=>TelegramArchiveBotRuntime::receive($message,$config,$pdo)));
    } finally {
        if (is_dir($directory)) {
            $files=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);
            foreach($files as $file) { if($file->isDir()&&!$file->isLink()) rmdir($file->getPathname()); else unlink($file->getPathname()); } rmdir($directory);
        }
    }

    $fixture=new TelegramArchiveHttpFixture();
    try {
        $api='/manager/telegram-archive-api.php';
        $result=$fixture->request($api,['action'=>'configure','token'=>$token]);
        archiveCheck('real setup endpoint rejects anonymous credential writes before provider access', $result['status']===401 && !file_exists($fixture->directory.'/calls.jsonl'));
        $agent=$fixture->request('/__fixture_login?role=manager');
        $result=$fixture->request($api,['action'=>'configure','token'=>$token,'csrf'=>$agent['json']['csrf']],$agent['cookie']);
        archiveCheck('real setup endpoint rejects manager role credential writes', $result['status']===403 && !file_exists($fixture->directory.'/calls.jsonl'));
        $foreign=$fixture->request('/__fixture_login?role=foreign');
        archiveCheck('real setup endpoint rejects admin from adjacent project', $fixture->request($api,null,$foreign['cookie'])['status']===403);
        $admin=$fixture->request('/__fixture_login?role=admin'); $cookie=$admin['cookie']; $csrf=$admin['json']['csrf'];
        archiveCheck('real setup endpoint requires current CSRF before reading token', $fixture->request($api,['action'=>'configure','token'=>$token,'csrf'=>'wrong'],$cookie)['status']===403 && !file_exists($fixture->directory.'/calls.jsonl'));
        $wrong=$fixture->request($api,['action'=>'configure','token'=>'66666:ABCDEFGHIJKLMNOPQRSTUVWX','csrf'=>$csrf],$cookie);
        archiveCheck('real endpoint never echoes a submitted token in identity error', $wrong['status']===409 && $wrong['json']['error']==='archive_bot_mismatch' && !str_contains($wrong['text'],'ABCDEFGHIJKLMNOP'));
        $configured=$fixture->request($api,['action'=>'configure','token'=>$token,'csrf'=>$csrf],$cookie);
        archiveCheck('real admin endpoint configures a separate bot with redacted response', $configured['status']===200 && $configured['json']['bot']['webhook_ready'] && !str_contains($configured['text'],$token) && !str_contains($configured['text'],'secret'));
        $fixture->request('/__fixture_connect'); $before=$fixture->request('/__fixture_message');
        archiveCheck('actual HTTP fixture retains pending account without storing messages', $before['json']['state']==='account_not_approved');
        $approved=$fixture->request($api,['action'=>'approve','account_id'=>'910001','manager_id'=>'12','csrf'=>$csrf],$cookie);
        archiveCheck('real admin endpoint enables selected account after project-manager mapping', $approved['status']===200 && $approved['json']['accounts'][0]['approved'] && $fixture->request('/__fixture_message')['json']['stored']===1);
        $disabled=$fixture->request($api,['action'=>'disable','account_id'=>'910001','csrf'=>$csrf],$cookie);
        archiveCheck('real admin endpoint can stop account capture', $disabled['status']===200 && !$disabled['json']['accounts'][0]['approved']);
        $privateConfig=(new TelegramArchiveBotConfig($fixture->directory.'/credentials'))->load();
        $headers=['X-Telegram-Bot-Api-Secret-Token: '.$privateConfig['secret']];
        $connected=['update_id'=>10,'business_connection'=>['id'=>'actual-http-connection','user'=>['id'=>910002,'first_name'=>'Второй тестовый аккаунт'],'date'=>1792231000,'is_enabled'=>true]];
        archiveCheck('actual dedicated webhook accepts its own authenticated connection event', $fixture->request('/telegram_archive_webhook.php',$connected,'',$headers)['status']===200);
        $fixture->request($api,['action'=>'approve','account_id'=>'910002','manager_id'=>'12','csrf'=>$csrf],$cookie);
        $incoming=['update_id'=>11,'business_message'=>['business_connection_id'=>'actual-http-connection','message_id'=>1,'date'=>1792231001,'chat'=>['id'=>920002,'type'=>'private'],'from'=>['id'=>920002],'text'=>'SYNTHETIC REQUEST']];
        $delivered=$fixture->request('/telegram_archive_webhook.php',$incoming,'',$headers);
        $privateDb=new PDO('sqlite:'.$fixture->directory.'/fixture.sqlite');
        $stored=json_decode((string)$privateDb->query('SELECT record_json FROM telegram_dialogue_messages WHERE account_id=910002')->fetchColumn(),true);
        archiveCheck('actual dedicated webhook persists approved incoming message using separate secret', $delivered['status']===200 && $stored['text']==='SYNTHETIC REQUEST' && $stored['manager_id']==='12');
        $edited=['update_id'=>12,'edited_business_message'=>$incoming['business_message']+['edit_date'=>1792231010]]; $edited['edited_business_message']['text']='SYNTHETIC EDIT';
        $fixture->request('/telegram_archive_webhook.php',$edited,'',$headers);
        $stored=json_decode((string)$privateDb->query('SELECT record_json FROM telegram_dialogue_messages WHERE account_id=910002')->fetchColumn(),true);
        archiveCheck('actual dedicated webhook applies edit without ordinary bot processing', $stored['text']==='SYNTHETIC EDIT');
        $deleted=['update_id'=>13,'deleted_business_messages'=>['business_connection_id'=>'actual-http-connection','chat'=>['id'=>920002,'type'=>'private'],'message_ids'=>[1]]];
        $fixture->request('/telegram_archive_webhook.php',$deleted,'',$headers);
        $stored=json_decode((string)$privateDb->query('SELECT record_json FROM telegram_dialogue_messages WHERE account_id=910002')->fetchColumn(),true);
        archiveCheck('actual dedicated webhook removes text on deletion', $stored['is_deleted'] && $stored['text']===null);
        archiveCheck('dedicated public webhook rejects missing secret and GET requests', $fixture->request('/telegram_archive_webhook.php',[])['status']===403 && $fixture->request('/telegram_archive_webhook.php')['status']===405);
        $page=$fixture->request('/manager/telegram-archive.php',null,$cookie);
        archiveCheck('actual admin HTML contains neither token nor webhook secret', $page['status']===200 && str_contains($page['text'],'tokenForm') && !str_contains($page['text'],$token) && !str_contains($page['text'],'secret_token'));
    } finally { $fixture->close(); }
}
