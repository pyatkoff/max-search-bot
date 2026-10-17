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
        archiveCheck('inactive and foreign-project managers cannot approve capture', archiveThrows(fn()=>$registry->setCapture('anytour',TelegramArchiveBotConfig::USERNAME,'1000',true,13)) && archiveThrows(fn()=>$registry->setCapture('anytour',TelegramArchiveBotConfig::USERNAME,'1000',true,14)));
        $registry->setCapture('anytour',TelegramArchiveBotConfig::USERNAME,'1000',true,11);
        archiveCheck('approved dedicated account archives incoming data in canonical store', TelegramArchiveBotRuntime::receive($message,$config,$pdo)['stored']===1);
        $registry->setCapture('anytour',TelegramArchiveBotConfig::USERNAME,'1000',true,12);
        $message['business_message']['message_id']=2; $message['update_id']=103;
        TelegramArchiveBotRuntime::receive($message,$config,$pdo);
        $rows=iterator_to_array((new TelegramDialogueArchive($pdo))->export('anytour','1000',['2000']));
        archiveCheck('manager annotation follows explicit account mapping', $rows[1]['manager_id']==='12');
        $registry->setCapture('anytour',TelegramArchiveBotConfig::USERNAME,'1000',true);
        $shared=$registry->listing('anytour',TelegramArchiveBotConfig::USERNAME)[0];
        archiveCheck('shared account approval is independent from a personal manager mapping', $shared['approved'] && $shared['manager_id']===null);
        $missingPermission=(new TelegramDialogueArchive($pdo))->receive('anytour',TelegramArchiveBotConfig::USERNAME,'dedicated-connection',[],null,static fn(string $id):array=>[]);
        archiveCheck('shared capture requires an explicit manager permission key instead of accepting a malformed guard', $missingPermission['state']==='account_not_approved');
        $message['business_message']['message_id']=3; $message['update_id']=104;
        $message['business_message']['text']='Здравствуйте! Менеджер Анна, синтетическая подборка';
        archiveCheck('approved shared account captures an outgoing introduction without assigning its signature', TelegramArchiveBotRuntime::receive($message,$config,$pdo)['stored']===1);
        $incoming=$message; $incoming['update_id']=105; $incoming['business_message']['message_id']=4;
        $incoming['business_message']['from']['id']=2000; $incoming['business_message']['text']='Синтетический запрос туриста';
        TelegramArchiveBotRuntime::receive($incoming,$config,$pdo);
        $rows=iterator_to_array((new TelegramDialogueArchive($pdo))->export('anytour','1000',['2000']));
        archiveCheck('shared messages retain account source and customer direction with no personal attribution', $rows[2]['manager_id']===null && $rows[2]['sender_id']==='1000' && $rows[2]['direction']==='outbound' && $rows[2]['source_kind']==='business_message' && $rows[3]['manager_id']===null && $rows[3]['sender_id']==='2000' && $rows[3]['direction']==='inbound');
        $edited=['update_id'=>106,'edited_business_message'=>$message['business_message']+['edit_date'=>1792231010]];
        $edited['edited_business_message']['message_id']=2; $edited['edited_business_message']['text']='Синтетическое изменение общего аккаунта';
        TelegramArchiveBotRuntime::receive($edited,$config,$pdo);
        $rows=iterator_to_array((new TelegramDialogueArchive($pdo))->export('anytour','1000',['2000']));
        archiveCheck('shared edit does not inherit an earlier personal account annotation', $rows[1]['text']==='Синтетическое изменение общего аккаунта' && $rows[1]['manager_id']===null);
        $deleted=['update_id'=>107,'deleted_business_messages'=>['business_connection_id'=>'dedicated-connection','chat'=>['id'=>2000,'type'=>'private'],'message_ids'=>[3,4]]];
        TelegramArchiveBotRuntime::receive($deleted,$config,$pdo);
        $rows=iterator_to_array((new TelegramDialogueArchive($pdo))->export('anytour','1000',['2000']));
        archiveCheck('shared deletions remove both directions without a personal manager', $rows[2]['is_deleted'] && $rows[2]['text']===null && $rows[2]['manager_id']===null && $rows[3]['is_deleted'] && $rows[3]['text']===null && $rows[3]['manager_id']===null);
        archiveCheck('shared replay cannot resurrect a deleted message', TelegramArchiveBotRuntime::receive($message,$config,$pdo)['stored']===0);
        $registry->setCapture('anytour',TelegramArchiveBotConfig::USERNAME,'1000',true,11);
        $message['business_message']['message_id']=5; $message['update_id']=108;
        TelegramArchiveBotRuntime::receive($message,$config,$pdo);
        $rows=iterator_to_array((new TelegramDialogueArchive($pdo))->export('anytour','1000',['2000']));
        archiveCheck('explicit shared to personal switch annotates subsequent messages', $rows[4]['manager_id']==='11');
        $registry->setCapture('anytour',TelegramArchiveBotConfig::USERNAME,'1000',true);
        $edited['update_id']=109; $edited['edited_business_message']['message_id']=5; $edited['edited_business_message']['edit_date']=1792231012;
        TelegramArchiveBotRuntime::receive($edited,$config,$pdo);
        $rows=iterator_to_array((new TelegramDialogueArchive($pdo))->export('anytour','1000',['2000']));
        archiveCheck('explicit personal to shared switch applies authoritative null to later edits', $rows[4]['manager_id']===null);
        $registry->setCapture('anytour',TelegramArchiveBotConfig::USERNAME,'1000',false);
        $message['business_message']['message_id']=6; $message['update_id']=110;
        archiveCheck('admin stopping shared capture fences later writes', TelegramArchiveBotRuntime::receive($message,$config,$pdo)['stored']===0 && iterator_count((new TelegramDialogueArchive($pdo))->export('anytour','1000',['2000']))===5);
        $guardObserved=false;
        (new TelegramDialogueArchive($pdo))->receive('anytour',TelegramArchiveBotConfig::USERNAME,'dedicated-connection',[],null,static function(string $id) use($pdo,$registry,&$guardObserved){ $guardObserved=$pdo->inTransaction(); return $registry->capturePermission('anytour',TelegramArchiveBotConfig::USERNAME,$id,'dedicated-connection'); });
        archiveCheck('approval is rechecked inside the archive transaction', $guardObserved);
        $registry->setCapture('anytour',TelegramArchiveBotConfig::USERNAME,'1000',true);
        if ($dsn !== false && $dsn !== '') {
            $otherPdo=new PDO($dsn,'root','archive-test-only',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false]); $otherPdo->exec('SET innodb_lock_wait_timeout=1'); $blocked=false;
            (new TelegramDialogueArchive($pdo))->receive('anytour',TelegramArchiveBotConfig::USERNAME,'dedicated-connection',[],null,static function(string $id) use($registry,$otherPdo,&$blocked){
                $permission=$registry->capturePermission('anytour',TelegramArchiveBotConfig::USERNAME,$id,'dedicated-connection');
                try { $otherPdo->exec("UPDATE telegram_archive_accounts SET approved=0 WHERE account_id=1000"); } catch(PDOException $error) { $blocked=(int)($error->errorInfo[1]??0)===1205; }
                return $permission;
            });
            archiveCheck('native MySQL approval row remains locked through archive write boundary', $blocked);
        }
        $revoked=$connection; $revoked['update_id']=111; $revoked['business_connection']['is_enabled']=false;
        TelegramArchiveBotRuntime::receive($revoked,$config,$pdo);
        archiveCheck('Telegram disconnect stops the dedicated bot even for approved shared account', TelegramArchiveBotRuntime::receive($message,$config,$pdo)['state']==='disconnected');
        archiveCheck('disconnected shared account cannot be enabled by an administrator', archiveThrows(fn()=>$registry->setCapture('anytour',TelegramArchiveBotConfig::USERNAME,'1000',true)));
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
        $invalidType=$fixture->request($api,['action'=>'approve','account_id'=>'910001','account_type'=>'unknown','csrf'=>$csrf],$cookie);
        $ambiguous=$fixture->request($api,['action'=>'approve','account_id'=>'910001','account_type'=>'shared','manager_id'=>'12','csrf'=>$csrf],$cookie);
        $missingManager=$fixture->request($api,['action'=>'approve','account_id'=>'910001','account_type'=>'manager','csrf'=>$csrf],$cookie);
        archiveCheck('real endpoint rejects unknown type, ambiguous shared mapping and missing personal manager without enabling capture', $invalidType['status']===409 && $ambiguous['status']===409 && $missingManager['status']===409 && $fixture->request('/__fixture_message')['json']['state']==='account_not_approved');
        $inactiveManager=$fixture->request($api,['action'=>'approve','account_id'=>'910001','account_type'=>'manager','manager_id'=>'13','csrf'=>$csrf],$cookie);
        $foreignManager=$fixture->request($api,['action'=>'approve','account_id'=>'910001','account_type'=>'manager','manager_id'=>'14','csrf'=>$csrf],$cookie);
        archiveCheck('real endpoint still rejects inactive and foreign project managers', $inactiveManager['status']===409 && $foreignManager['status']===409);
        $approved=$fixture->request($api,['action'=>'approve','account_id'=>'910001','manager_id'=>'12','csrf'=>$csrf],$cookie);
        archiveCheck('real admin endpoint enables selected account after project-manager mapping', $approved['status']===200 && $approved['json']['accounts'][0]['approved'] && $fixture->request('/__fixture_message')['json']['stored']===1);
        $sharedApproval=$fixture->request($api,['action'=>'approve','account_id'=>'910001','account_type'=>'shared','csrf'=>$csrf],$cookie);
        archiveCheck('real admin endpoint enables shared capture with an explicit null manager', $sharedApproval['status']===200 && $sharedApproval['json']['accounts'][0]['approved'] && $sharedApproval['json']['accounts'][0]['manager_id']===null);
        $disabled=$fixture->request($api,['action'=>'disable','account_id'=>'910001','csrf'=>$csrf],$cookie);
        archiveCheck('real admin endpoint can stop shared account capture', $disabled['status']===200 && !$disabled['json']['accounts'][0]['approved'] && $fixture->request('/__fixture_message')['json']['state']==='account_not_approved');
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
        $sharedApproval=$fixture->request($api,['action'=>'approve','account_id'=>'910002','account_type'=>'shared','csrf'=>$csrf],$cookie);
        archiveCheck('real admin endpoint switches a named account to shared capture', $sharedApproval['status']===200 && $sharedApproval['json']['accounts'][1]['approved'] && $sharedApproval['json']['accounts'][1]['manager_id']===null);
        $edited=['update_id'=>12,'edited_business_message'=>$incoming['business_message']+['edit_date'=>1792231010]]; $edited['edited_business_message']['text']='SYNTHETIC EDIT';
        $fixture->request('/telegram_archive_webhook.php',$edited,'',$headers);
        $stored=json_decode((string)$privateDb->query('SELECT record_json FROM telegram_dialogue_messages WHERE account_id=910002')->fetchColumn(),true);
        archiveCheck('actual dedicated webhook applies a shared edit without inheriting the former manager', $stored['text']==='SYNTHETIC EDIT' && $stored['manager_id']===null);
        $deleted=['update_id'=>13,'deleted_business_messages'=>['business_connection_id'=>'actual-http-connection','chat'=>['id'=>920002,'type'=>'private'],'message_ids'=>[1]]];
        $fixture->request('/telegram_archive_webhook.php',$deleted,'',$headers);
        $stored=json_decode((string)$privateDb->query('SELECT record_json FROM telegram_dialogue_messages WHERE account_id=910002')->fetchColumn(),true);
        archiveCheck('actual dedicated webhook removes text on shared deletion', $stored['is_deleted'] && $stored['text']===null && $stored['manager_id']===null);
        $outgoing=$incoming; $outgoing['update_id']=14; $outgoing['business_message']['message_id']=2;
        $outgoing['business_message']['from']['id']=910002; $outgoing['business_message']['text']='SYNTHETIC: Менеджер Анна';
        $outgoingDelivered=$fixture->request('/telegram_archive_webhook.php',$outgoing,'',$headers);
        $incoming['update_id']=15; $incoming['business_message']['message_id']=3;
        $incomingDelivered=$fixture->request('/telegram_archive_webhook.php',$incoming,'',$headers);
        $sharedRows=$privateDb->query('SELECT record_json FROM telegram_dialogue_messages WHERE account_id=910002 AND message_id IN (2,3) ORDER BY message_id')->fetchAll(PDO::FETCH_COLUMN);
        $sharedRows=array_map(static fn(string $row):array=>json_decode($row,true),$sharedRows);
        archiveCheck('actual shared webhook captures both directions and preserves signature text without assigning a manager', $outgoingDelivered['status']===200 && $incomingDelivered['status']===200 && count($sharedRows)===2 && $sharedRows[0]['manager_id']===null && $sharedRows[0]['direction']==='outbound' && $sharedRows[0]['text']==='SYNTHETIC: Менеджер Анна' && $sharedRows[1]['manager_id']===null && $sharedRows[1]['direction']==='inbound');
        $fixture->request($api,['action'=>'disable','account_id'=>'910002','csrf'=>$csrf],$cookie);
        $incoming['update_id']=16; $incoming['business_message']['message_id']=4;
        $fixture->request('/telegram_archive_webhook.php',$incoming,'',$headers);
        archiveCheck('actual shared webhook acknowledges stopped capture without storing further content', (int)$privateDb->query('SELECT COUNT(*) FROM telegram_dialogue_messages WHERE account_id=910002')->fetchColumn()===3);
        archiveCheck('dedicated public webhook rejects missing secret and GET requests', $fixture->request('/telegram_archive_webhook.php',[])['status']===403 && $fixture->request('/telegram_archive_webhook.php')['status']===405);
        $page=$fixture->request('/manager/telegram-archive.php',null,$cookie);
        archiveCheck('actual admin HTML contains neither token nor webhook secret', $page['status']===200 && str_contains($page['text'],'tokenForm') && !str_contains($page['text'],$token) && !str_contains($page['text'],'secret_token'));
    } finally { $fixture->close(); }
}
