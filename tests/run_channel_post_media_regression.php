<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/services/ChannelPostMediaService.php';
require_once __DIR__.'/fixtures/channel_post_registry_fixture.php';

$passed = 0;
function postImageCheck(string $name, bool $condition): void { global $passed; if (!$condition) throw new RuntimeException('FAIL '.$name); $passed++; echo 'PASS '.$name."\n"; }
function postImageStream(string $bytes) { $stream = tmpfile(); fwrite($stream, $bytes); rewind($stream); return $stream; }
function postImageBytes(?array $file): ?string { if ($file === null) return null; try { return (string)stream_get_contents($file['stream']); } finally { fclose($file['stream']); } }
function postImageRemoveTree(string $path): void { if (is_link($path) || is_file($path)) { unlink($path); return; } if (!is_dir($path)) return; foreach (new DirectoryIterator($path) as $child) if (!$child->isDot()) postImageRemoveTree($child->getPathname()); rmdir($path); }

$root = dirname(__DIR__);
$temp = sys_get_temp_dir().'/channel-post-media-test-'.bin2hex(random_bytes(6));
mkdir($temp, 0700); mkdir($temp.'/public', 0700);
$_SERVER['DOCUMENT_ROOT'] = $temp.'/public';
putenv('MAX_SEARCH_CHANNEL_POST_MEDIA_DIR='.$temp.'/private');
define('TELEGRAM_BOT_TOKEN', '123456:FIXTURE_ONLY_NO_NETWORK');
$images = array_map(static fn($value)=>base64_decode($value, true), require __DIR__.'/fixtures/channel_post_media_images.php');
$dbPath = $temp.'/fixture.sqlite';
$pdo = new PDO('sqlite:'.$dbPath, null, null, [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
// The protected endpoint executes the real manager authentication and routing ACL.
$pdo->exec('CREATE TABLE conversations (id INTEGER PRIMARY KEY,project_key TEXT,channel TEXT,status TEXT,manager_id INTEGER,source_id INTEGER,entry_channel TEXT,external_chat_id TEXT,customer_id INTEGER,attribution_region TEXT,attribution_campaign TEXT,lead_stage_key TEXT,started_at TEXT,last_message_at TEXT,closed_at TEXT)');
$pdo->exec('CREATE TABLE conversation_sources (id INTEGER PRIMARY KEY,project_id INTEGER,source_key TEXT,display_name TEXT,channel TEXT,is_active INTEGER DEFAULT 1,handling_mode TEXT DEFAULT "manager",primary_group_id INTEGER,fallback_mode TEXT,fallback_group_id INTEGER,fallback_after_minutes INTEGER)');
createChannelPostRegistryFixture($pdo);
$pdo->exec("CREATE TABLE managers (id INTEGER PRIMARY KEY,login TEXT,display_name TEXT,role TEXT,email TEXT,is_active INTEGER,is_working INTEGER);
CREATE TABLE manager_projects (manager_id INTEGER,project_id INTEGER);
CREATE TABLE manager_group_members (manager_id INTEGER,group_id INTEGER);
CREATE TABLE customers (id INTEGER PRIMARY KEY,display_name TEXT,phone TEXT,email TEXT);
INSERT INTO projects VALUES (1,'anytour','AnyTour',1),(2,'other','Other',1);
INSERT INTO conversation_sources (id,project_id,source_key,display_name,channel,is_active,primary_group_id,fallback_mode) VALUES (11,1,'max-source','MAX test','max',1,9,'none'),(12,1,'tg-source','TG test','telegram',1,NULL,'none');
INSERT INTO managers VALUES (7,'manager','Manager','manager','',1,1),(8,'other','Other','manager','',1,1),(9,'inactive','Inactive','manager','',0,1);
INSERT INTO manager_projects VALUES (7,1),(8,2);
INSERT INTO manager_group_members VALUES (7,9);
INSERT INTO customers VALUES (1,'Fixture customer','','');
INSERT INTO conversations (id,project_key,channel,status,manager_id,source_id,customer_id) VALUES (100,'anytour','max','manager',7,11,1),(101,'anytour','telegram','manager',7,12,1),(200,'other','max','manager',8,NULL,1);");
$registry = new ChannelPostRegistryService($pdo);
$registry->configureChannel(['project_key'=>'anytour','platform'=>'max','bot_key'=>'max_test_bot','channel_id'=>'-123','source_key'=>'max-source','bot_url'=>'https://max.ru/max_test_bot','is_enabled'=>true]);
$registry->configureChannel(['project_key'=>'anytour','platform'=>'telegram','bot_key'=>'tg_test_bot','channel_id'=>'-100123','source_key'=>'tg-source','bot_url'=>'https://t.me/tg_test_bot','is_enabled'=>true]);
$identity = static fn($platform)=>['project_key'=>'anytour','platform'=>$platform,'bot_key'=>$platform==='max' ? 'max_test_bot' : 'tg_test_bot'];
$calls = []; $messageCalls = []; $fileCalls = []; $responseBytes = $images['image/png']; $remote = null; $expired = false;
$fetch = static function ($url, $limit, $policy) use (&$calls, &$responseBytes, &$expired) {
    $calls[] = [$url,$limit,$policy];
    if ($expired && str_contains($url, 'saved.png')) return null;
    return postImageStream($responseBytes);
};
$fetchMessage = static function ($mid) use (&$messageCalls, &$remote) { $messageCalls[] = $mid; return $remote; };
$fetchFile = static function ($fileId) use (&$fileCalls) { $fileCalls[] = $fileId; return ['file_path'=>'photos/photo-original.png','file_size'=>1000]; };
$dns = static fn($host)=>['93.184.216.34'];
$service = new ChannelPostMediaService($registry, $fetch, $fetchMessage, $fetchFile, $dns, $identity);
$counter = 0;
$makePost = function (array $attachment, string $platform = 'max', ?string $mid = null) use ($registry, &$counter): array {
    $counter++; $mid = $mid ?? ($platform==='max' ? 'mid.fixture'.$counter : (string)$counter);
    $post = $registry->registerPublication('anytour', $platform, $platform==='max' ? 'max_test_bot' : 'tg_test_bot', $platform==='max' ? '-123' : '-100123', $mid,
        ['text'=>'Original test post '.$counter,'attachments'=>[$attachment], 'metadata'=>['source_event_id'=>'event-'.$counter]]);
    $entry = $registry->recordEntry($platform==='max' ? 100 : 101, $post, 'entry-'.$counter);
    return [$post,$entry];
};
$image = static fn($url='https://cdn.example.com/saved.png', $id='901')=>['type'=>'image','provider'=>'max','photo_id'=>$id,'token'=>'PRIVATE_TOKEN','url'=>$url];
try {
    foreach ($images as $mime=>$bytes) {
        $responseBytes = $bytes;
        [$post,$entry] = $makePost($image());
        $file = $service->openEntry(100,'anytour',$entry['entry_id']);
        postImageCheck('actual '.$mime.' bytes served with verified MIME and bounded length', $file!==null && $file['mime']===$mime && $file['size']===strlen($bytes) && postImageBytes($file)===$bytes);
    }
    postImageCheck('read-only download policy pins public DNS, strict TLS, no redirects and 5MiB cap', $calls[0][1]===ChannelPostMediaService::MAX_IMAGE_BYTES && $calls[0][2]===['host'=>'cdn.example.com','pinned_ip'=>'93.184.216.34','strict_tls'=>true,'redirects'=>false,'timeout'=>6]);
    $responseBytes = $images['image/png'];
    [$original,$originalEntry] = $makePost($image());
    postImageCheck('original image archives before any later publication edit', $service->archiveVersion($original));
    $oldBytes = postImageBytes($service->openEntry(100,'anytour',$originalEntry['entry_id']));
    $changed = $original['snapshot']; $changed['attachments'][0]['photo_id']='902'; $changed['attachments'][0]['url']='https://cdn.example.com/changed.jpg'; $changed['metadata']['source_event_id']='new-photo';
    $updated = $registry->registerPublication('anytour','max','max_test_bot','-123',$original['external_message_id'],$changed);
    $newEntry = $registry->recordEntry(100,$updated,'entry-new-photo'); $responseBytes=$images['image/jpeg'];
    $beforeCalls=count($calls);
    postImageCheck('old entry still serves original cached photo after different current photo', postImageBytes($service->openEntry(100,'anytour',$originalEntry['entry_id']))===$oldBytes && count($calls)===$beforeCalls);
    postImageCheck('new version uses separate image cache without replacing old photo', postImageBytes($service->openEntry(100,'anytour',$newEntry['entry_id']))===$images['image/jpeg']);
    $beforeCalls=count($calls);
    postImageCheck('wrong conversation, entry, project or attachment never reaches provider', $service->openEntry(200,'other',$originalEntry['entry_id'])===null && $service->openEntry(100,'other',$originalEntry['entry_id'])===null && $service->openEntry(101,'anytour',$originalEntry['entry_id'])===null && $service->openEntry(100,'anytour',999999)===null && $service->openEntry(100,'anytour',$originalEntry['entry_id'],1)===null && count($calls)===$beforeCalls);
    $wrongRuntime = new ChannelPostMediaService($registry,$fetch,$fetchMessage,$fetchFile,$dns,static fn($p)=>['project_key'=>'other','platform'=>$p,'bot_key'=>'max_test_bot']);
    postImageCheck('cached image still refuses mismatched runtime project/bot credentials', $wrongRuntime->openEntry(100,'anytour',$originalEntry['entry_id'])===null && count($calls)===$beforeCalls);
    $wrongBot = new ChannelPostMediaService($registry,$fetch,$fetchMessage,$fetchFile,$dns,static fn($p)=>['project_key'=>'anytour','platform'=>$p,'bot_key'=>'other_bot']);
    postImageCheck('another runtime bot cannot recover or read this version cache', $wrongBot->openEntry(100,'anytour',$originalEntry['entry_id'])===null && count($calls)===$beforeCalls);

    $expired=true; $responseBytes=$images['image/png'];
    [$recovery,$recoveryEntry]=$makePost($image());
    $remote=['body'=>['mid'=>$recovery['external_message_id'],'attachments'=>[['type'=>'image','payload'=>['photo_id'=>901,'url'=>'https://cdn.example.com/fresh.png']]]],'recipient'=>['chat_id'=>-123,'chat_type'=>'channel']];
    postImageCheck('expired saved URL rehydrates actual MID only for identical stable photo ID', postImageBytes($service->openEntry(100,'anytour',$recoveryEntry['entry_id']))===$images['image/png'] && end($messageCalls)===$recovery['external_message_id']);
    [$changedPost,$changedEntry]=$makePost($image());
    $remote['body']['mid']=$changedPost['external_message_id']; $remote['body']['attachments'][0]['payload']['photo_id']=902;
    $beforeCalls=count($calls);
    postImageCheck('current different photo is never substituted for an expired old snapshot', $service->openEntry(100,'anytour',$changedEntry['entry_id'])===null && count($calls)===$beforeCalls+1);
    [$wrongChannel,$wrongChannelEntry]=$makePost($image());
    $remote['body']['mid']=$wrongChannel['external_message_id']; $remote['body']['attachments'][0]['payload']['photo_id']=901; $remote['recipient']['chat_id']=-999;
    postImageCheck('rehydration refuses a different channel despite matching stable image', $service->openEntry(100,'anytour',$wrongChannelEntry['entry_id'])===null);
    [$missingStable,$missingEntry]=$makePost($image('',''));
    $beforeMessage=count($messageCalls);
    postImageCheck('missing stable MAX photo ID never fetches potentially edited current post', $service->openEntry(100,'anytour',$missingEntry['entry_id'])===null && count($messageCalls)===$beforeMessage);
    $expired=false; $remote=null;

    foreach (['http://cdn.example.com/a.png','https://127.0.0.1/a.png','https://[::1]/a.png','https://localhost/a.png','https://cdn.example.com:8443/a.png','https://user:password@cdn.example.com/a.png','https://cdn.example.com/a.png#fragment','https://cdn.example.com/a.png' . "\n"] as $url) {
        [$bad,$badEntry]=$makePost($image($url)); $beforeCalls=count($calls);
        postImageCheck('unsafe URL refuses network: '.str_replace("\n",' newline',$url), $service->openEntry(100,'anytour',$badEntry['entry_id'])===null && count($calls)===$beforeCalls);
    }
    foreach ([['127.0.0.1'],['10.1.2.3'],['169.254.169.254'],['100.64.1.1'],['192.0.2.1'],['198.18.0.1'],['::ffff:127.0.0.1'],['2002:7f00:0001::'],['2001::7f00:1'],['93.184.216.34','192.168.1.1']] as $ips) {
        [$bad,$badEntry]=$makePost($image()); $beforeCalls=count($calls);
        $private = new ChannelPostMediaService($registry,$fetch,$fetchMessage,$fetchFile,static fn($host)=>$ips,$identity);
        postImageCheck('private/reserved/mixed DNS answer refuses network: '.implode(',',$ips), $private->openEntry(100,'anytour',$badEntry['entry_id'])===null && count($calls)===$beforeCalls);
    }
    foreach (['<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>','<html><script>alert(1)</script></html>',str_repeat('a',ChannelPostMediaService::MAX_IMAGE_BYTES+1)] as $unsafeBytes) {
        $responseBytes=$unsafeBytes; [$bad,$badEntry]=$makePost($image());
        postImageCheck('SVG/HTML/oversized image bytes are never cached or served', $service->openEntry(100,'anytour',$badEntry['entry_id'])===null);
    }
    $responseBytes=$images['image/png'];
    [$tg,$tgEntry]=$makePost(['type'=>'image','provider'=>'telegram','telegram_file_id'=>'immutable_saved_file_id','file_unique_id'=>'saved_unique'],'telegram');
    postImageCheck('Telegram resolves the saved version file_id server-side', postImageBytes($service->openEntry(101,'anytour',$tgEntry['entry_id']))===$images['image/png'] && end($fileCalls)==='immutable_saved_file_id' && str_contains(end($calls)[0],'/photos/photo-original.png'));
    foreach (['../escape.png','photos/../../escape.png','https://private.local/p.png','photos/a.png?x=1','photos/a.png#x'] as $badPath) {
        [$bad,$badEntry]=$makePost(['type'=>'image','provider'=>'telegram','telegram_file_id'=>'another_file_id'],'telegram'); $beforeCalls=count($calls);
        $unsafe = new ChannelPostMediaService($registry,$fetch,$fetchMessage,static fn($id)=>['file_path'=>$badPath],$dns,$identity);
        postImageCheck('Telegram provider file path validation rejects '.$badPath, $unsafe->openEntry(101,'anytour',$badEntry['entry_id'])===null && count($calls)===$beforeCalls);
    }
    $cacheFiles=glob($temp.'/private/*.bin');
    postImageCheck('image cache files are distinct from customer media and private 0700/0600', (fileperms($temp.'/private') & 0777)===0700 && count($cacheFiles)>=6 && (fileperms($cacheFiles[0]) & 0777)===0600 && basename($cacheFiles[0])!==substr(hash('sha256','max-inbound-image:'.$original['version_id'].':0'),0,32).'.bin');
    $cacheCopy=$temp.'/public/exposed'; mkdir($cacheCopy,0700);
    putenv('MAX_SEARCH_CHANNEL_POST_MEDIA_DIR='.$cacheCopy); $beforeCalls=count($calls);
    postImageCheck('a cache directory inside document root fails before fetching', $service->openEntry(100,'anytour',$originalEntry['entry_id'])===null && count($calls)===$beforeCalls);
    symlink($cacheCopy,$temp.'/public-cache-link'); putenv('MAX_SEARCH_CHANNEL_POST_MEDIA_DIR='.$temp.'/public-cache-link');
    postImageCheck('symlink cannot put private image cache inside document root', $service->openEntry(100,'anytour',$originalEntry['entry_id'])===null && count($calls)===$beforeCalls);
    putenv('MAX_SEARCH_CHANNEL_POST_MEDIA_DIR='.$temp.'/private');
    mkdir($temp.'/full',0700); putenv('MAX_SEARCH_CHANNEL_POST_MEDIA_DIR='.$temp.'/full');
    for ($i=0;$i<ChannelPostMediaService::MAX_CACHE_FILES;$i++) file_put_contents($temp.'/full/'.$i.'.bin','x');
    [$limit,$limitEntry]=$makePost($image());
    postImageCheck('total cache file bound cannot be exceeded', $service->openEntry(100,'anytour',$limitEntry['entry_id'])===null && count(glob($temp.'/full/*.bin'))===ChannelPostMediaService::MAX_CACHE_FILES);
    foreach (glob($temp.'/full/*.bin') as $path) unlink($path);
    $sparse=fopen($temp.'/full/total.bin','wb'); ftruncate($sparse,ChannelPostMediaService::MAX_CACHE_BYTES); fclose($sparse);
    postImageCheck('total cache byte bound cannot be exceeded', $service->openEntry(100,'anytour',$limitEntry['entry_id'])===null);
    putenv('MAX_SEARCH_CHANNEL_POST_MEDIA_DIR='.$temp.'/private');

    // Run the actual GET endpoint in isolated PHP processes with canonical ACLs.
    mkdir($temp.'/public/manager',0700); mkdir($temp.'/public/services',0700);
    symlink($root.'/manager/lib',$temp.'/public/manager/lib'); symlink($root.'/integrations',$temp.'/public/integrations');
    foreach (glob($root.'/services/*.php') as $path) if (!in_array(basename($path),['ChannelPostMediaService.php','ChannelPostRuntime.php'],true)) symlink($path,$temp.'/public/services/'.basename($path));
    copy($root.'/services/ChannelPostMediaService.php',$temp.'/public/services/ChannelPostMediaService.php');
    copy($root.'/manager/post-context-media.php',$temp.'/public/manager/post-context-media.php');
    file_put_contents($temp.'/public/services/ChannelPostRuntime.php', '<?php final class ChannelPostRuntime { public static function identity(string $p): ?array { return ["project_key"=>"anytour","platform"=>$p,"bot_key"=>$p==="max" ? "max_test_bot" : "tg_test_bot"]; } }');
    $config='<?php require_once '.var_export($root.'/services/ConversationDb.php',true).'; require_once '.var_export($root.'/services/ProjectConfig.php',true).';'
        .'ProjectConfig::resetForTests(["id"=>"anytour","brand"=>["name"=>"AnyTour"]]);'
        .'$db=new PDO('.var_export('sqlite:'.$dbPath,true).',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);'
        .'$r=new ReflectionProperty(ConversationDb::class,"pdo");$r->setAccessible(true);$r->setValue(null,$db);'
        .'session_save_path('.var_export($temp,true).');session_name("fixture");session_start();$_SESSION=["manager_id"=>(int)getenv("POST_IMAGE_TEST_MANAGER")];'
        .'$_GET=json_decode(base64_decode(getenv("POST_IMAGE_TEST_QUERY")),true);$_SERVER["REQUEST_METHOD"]=getenv("POST_IMAGE_TEST_METHOD");$_SERVER["DOCUMENT_ROOT"]='.var_export($temp.'/public',true).';'
        .'register_shutdown_function(static function(){file_put_contents(getenv("POST_IMAGE_TEST_STATUS"),json_encode(["status"=>http_response_code() ?: 200,"session_active"=>session_status()===PHP_SESSION_ACTIVE]));});';
    file_put_contents($temp.'/public/config.php',$config);
    $endpoint=function (int $manager,array $query,string $method='GET') use ($temp): array {
        $statusPath=$temp.'/status-'.bin2hex(random_bytes(4)).'.json';
        $env=getenv(); $env['POST_IMAGE_TEST_MANAGER']=(string)$manager; $env['POST_IMAGE_TEST_QUERY']=base64_encode(json_encode($query)); $env['POST_IMAGE_TEST_METHOD']=$method; $env['POST_IMAGE_TEST_STATUS']=$statusPath;
        $process=proc_open([PHP_BINARY,$temp.'/public/manager/post-context-media.php'],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,null,$env);
        fclose($pipes[0]); $body=stream_get_contents($pipes[1]); $errors=stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]); $exit=proc_close($process);
        if ($exit!==0 || $errors!=='') throw new RuntimeException('endpoint fixture failed: '.$errors);
        return json_decode((string)file_get_contents($statusPath),true)+['body'=>$body];
    };
    $query=['conversation_id'=>100,'entry_id'=>$originalEntry['entry_id'],'attachment'=>0];
    $before=$pdo->query('SELECT * FROM conversations ORDER BY id')->fetchAll(); $dbHash=hash_file('sha256',$dbPath);
    $good=$endpoint(7,$query);
    postImageCheck('authorized canonical manager GET serves exact private cached original image after releasing session', $good['status']===200 && $good['body']===$images['image/png'] && !$good['session_active']);
    postImageCheck('unauthenticated and inactive manager GET return safe 401', $endpoint(0,$query)['status']===401 && $endpoint(9,$query)['status']===401);
    postImageCheck('another account loses access even if exact cache URL is known', $endpoint(8,$query)['status']===404);
    postImageCheck('authorized manager cannot attach another conversation entry or nonexistent entry', $endpoint(7,array_replace($query,['conversation_id'=>101]))['status']===404 && $endpoint(7,array_replace($query,['entry_id'=>999999]))['status']===404);
    postImageCheck('GET rejects unsupported attachment and mutation method', $endpoint(7,array_replace($query,['attachment'=>1]))['status']===404 && $endpoint(7,$query,'POST')['status']===405);
    postImageCheck('protected endpoint reads never alter registry, project or conversation database', hash_file('sha256',$dbPath)===$dbHash);
    $pdo->exec('DELETE FROM manager_group_members WHERE manager_id=7');
    $revoked=$endpoint(7,$query);
    postImageCheck('fresh routing ACL revocation blocks previously cached image', $revoked['status']===404 && !str_contains($revoked['body'],'PRIVATE_TOKEN'));
    $pdo->exec('INSERT INTO manager_group_members VALUES (7,9)');
    postImageCheck('image reads preserve conversations and provider snapshot without database writes', $pdo->query('SELECT * FROM conversations ORDER BY id')->fetchAll()===$before && $registry->versionForEntry(100,'anytour',$originalEntry['entry_id'])['snapshot']['attachments'][0]['photo_id']==='901');
    $missing=$endpoint(7,['conversation_id'=>100,'entry_id'=>$missingEntry['entry_id'],'attachment'=>0]);
    postImageCheck('unavailable image uses safe 502 without signed URL, token or raw error', $missing['status']===502 && $missing['body']==='{"ok":false,"error":"post_image_unavailable"}');
    echo 'CHANNEL POST MEDIA OK ('.$passed." checks)\n";
} finally { postImageRemoveTree($temp); putenv('MAX_SEARCH_CHANNEL_POST_MEDIA_DIR'); }
