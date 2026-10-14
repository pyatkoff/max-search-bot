<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

// Exercise the real endpoint and session/auth/cache/access services in two PHP
// workers. Only the database is synthetic. A stopped reader holds the private
// file transfer open, without sleeps in production code or provider requests.
$root = dirname(__DIR__);
$tmp = sys_get_temp_dir().'/manager-media-session-'.bin2hex(random_bytes(6));
$passed = 0; $failed = 0;
function mediaSessionCheck(string $name, bool $ok): void {
    global $passed, $failed;
    echo ($ok ? 'PASS ' : 'FAIL ').$name."\n";
    if ($ok) $passed++; else $failed++;
}
function mediaSessionRemove(string $path): void {
    if (is_dir($path)) {
        foreach (scandir($path) as $name) if ($name !== '.' && $name !== '..') mediaSessionRemove($path.'/'.$name);
        rmdir($path);
    } else @unlink($path);
}
function mediaSessionCopy(string $relative): void {
    global $root, $tmp;
    $source = realpath($root.'/'.$relative);
    if ($source === false || !str_starts_with($source, $root.'/')) throw new RuntimeException('Invalid fixture dependency');
    $relative = substr($source, strlen($root)+1);
    $target = $tmp.'/'.$relative;
    if (is_file($target)) return;
    if (!is_dir(dirname($target))) mkdir(dirname($target),0700,true);
    copy($source,$target);
    $text = (string)file_get_contents($source);
    preg_match_all("~require_once\s+__DIR__\s*\.\s*'([^']+)'~",$text,$matches);
    foreach ($matches[1] as $dependency) mediaSessionCopy(dirname($relative).'/'.$dependency);
}
function mediaSessionWorker(string $mode,string $session,string $query,string $result): array {
    global $tmp;
    $process=proc_open([PHP_BINARY,'-d','session.save_path='.$tmp.'/sessions','-d','session.cache_limiter=',
        $tmp.'/request.php',$mode,$session,$query,$result],
        [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,$tmp);
    if (!is_resource($process)) throw new RuntimeException('Cannot start media fixture worker');
    fclose($pipes[0]);stream_set_blocking($pipes[1],false);stream_set_blocking($pipes[2],false);
    return ['process'=>$process,'out'=>$pipes[1],'err'=>$pipes[2]];
}
function mediaSessionFinish(array &$worker,string $initial=''): array {
    $body=$initial;$errors='';$deadline=microtime(true)+4;
    do {
        $body.=(string)stream_get_contents($worker['out']);$errors.=(string)stream_get_contents($worker['err']);
        $running=proc_get_status($worker['process'])['running'];
        if (!$running && feof($worker['out']) && feof($worker['err'])) break;
        if (microtime(true)>$deadline) throw new RuntimeException('Media fixture worker timed out');
        usleep(1000);
    } while (true);
    fclose($worker['out']);fclose($worker['err']);proc_close($worker['process']);$worker=[];
    return ['body'=>$body,'errors'=>$errors];
}
function mediaSessionStop(array &$worker): void {
    if (!$worker) return;
    proc_terminate($worker['process']);fclose($worker['out']);fclose($worker['err']);proc_close($worker['process']);$worker=[];
}

try {
    mkdir($tmp,0700);mkdir($tmp.'/sessions',0700);mkdir($tmp.'/runtime/incoming-media',0700,true);
    foreach (['manager/media-file.php','manager/lib/ManagerHttp.php','services/ManagerRequestContext.php',
        'services/ManagerConversationService.php','services/ManagerMediaCache.php',
        'services/ManagerMaxMediaService.php','services/ManagerTelegramMediaService.php'] as $dependency) mediaSessionCopy($dependency);
    file_put_contents($tmp.'/services/ConversationDb.php', <<<'PHP'
<?php
class ConversationDb {
    static function isConfigured(): bool { return true; }
    static function connection() { return new MediaSessionDatabase; }
}
class MediaSessionDatabase {
    function prepare(string $sql) { return new MediaSessionStatement(preg_replace('/\s+/',' ',trim($sql))); }
}
class MediaSessionStatement {
    private array $rows=[];
    function __construct(private string $sql) {}
    function execute(array $args=[]): bool {
        $sql=$this->sql;$this->rows=[];
        file_put_contents(__DIR__.'/../db-trace.jsonl',json_encode(['sql'=>$sql,'args'=>$args,'active'=>session_status()===PHP_SESSION_ACTIVE])."\n",FILE_APPEND);
        if(str_starts_with($sql,'INSERT INTO projects ')) return true;
        if($sql==='SELECT id FROM projects WHERE project_key=? LIMIT 1') {$this->rows=[[1]];return true;}
        if(str_starts_with($sql,'SELECT id,login,display_name,role,email,is_active,is_working FROM managers ')) {
            if(in_array((int)$args[0],[7,8],true))$this->rows=[['id'=>(int)$args[0],'login'=>'fixture','display_name'=>'Fixture','role'=>'manager','is_active'=>(int)$args[0]===7?1:0,'is_working'=>1]];
            return true;
        }
        if(str_starts_with($sql,'SELECT p.id,p.project_key,p.display_name FROM projects p JOIN manager_projects ')) {$this->rows=[['id'=>1,'project_key'=>'fixture','display_name'=>'Fixture']];return true;}
        if(str_starts_with($sql,'SELECT 1 FROM manager_projects mp JOIN projects ')) {$this->rows=(int)$args[0]===7&&$args[1]==='fixture'?[[1]]:[];return true;}
        if(str_starts_with($sql,'SELECT c.id,c.project_key,c.source_id,c.channel,')) {
            $id=(int)$args[0];
            if(in_array($id,[42,43,44],true))$this->rows=[['id'=>$id,'project_key'=>$id===43?'denied':'fixture','source_id'=>$id===44?99:0,'channel'=>'max','status'=>'manager','manager_id'=>7]];
            return true;
        }
        if(str_starts_with($sql,'SELECT id,primary_group_id,fallback_mode,fallback_group_id,fallback_after_minutes FROM conversation_sources ')) {$this->rows=[['id'=>99,'primary_group_id'=>123,'fallback_mode'=>'none','fallback_group_id'=>0]];return true;}
        if(str_starts_with($sql,'SELECT group_id FROM manager_group_members ')) return true;
        if($sql==='SELECT COALESCE(MAX(id),0) FROM messages WHERE conversation_id=?') {$this->rows=[[99]];return true;}
        if(str_starts_with($sql,'INSERT INTO manager_conversation_reads ')) return true;
        if($sql==='SELECT id,direction,sender_type,text,created_at FROM messages WHERE conversation_id=? ORDER BY id ASC LIMIT 500') return true;
        if(in_array($sql,['SELECT conversation_id,channel,direction,metadata_json FROM messages WHERE id=? LIMIT 1',
            'SELECT channel,direction,external_message_id,metadata_json FROM messages WHERE id=? LIMIT 1'],true)) {
            $id=(int)$args[0];
            if(in_array($id,[99,101],true))$this->rows=[['conversation_id'=>$id===101?43:42,'channel'=>'max','direction'=>'inbound','external_message_id'=>'',
                'metadata_json'=>json_encode(['attachments'=>[['type'=>'image','provider'=>'max','local_media'=>['id'=>str_repeat('a',32),'type'=>'image','size'=>filesize(__DIR__.'/../runtime/incoming-media/'.str_repeat('a',32).'.bin'),'name'=>'fixture.png']]]])]];
            return true;
        }
        throw new RuntimeException('Unexpected synthetic database query: '.$sql);
    }
    function fetch() { return $this->rows[0]??false; }
    function fetchAll(): array { return $this->rows; }
    function fetchColumn(int $column=0) { $row=$this->rows[0]??[];return array_values($row)[$column]??false; }
}
PHP);
    file_put_contents($tmp.'/config.php', <<<'PHP'
<?php
require_once __DIR__.'/services/ProjectConfig.php';
ProjectConfig::resetForTests(['id'=>'fixture','brand'=>['name'=>'Fixture']]);
putenv('MAX_SEARCH_INCOMING_MEDIA_DIR='.__DIR__.'/runtime/incoming-media');
PHP);
    file_put_contents($tmp.'/request.php', <<<'PHP'
<?php
declare(strict_types=1);
$_SERVER['SCRIPT_NAME']='/manager/media-file.php';
$_COOKIE['anytour_manager_panel']=$argv[2];session_id($_COOKIE['anytour_manager_panel']);
parse_str($argv[3],$_GET);$result=$argv[4];
register_shutdown_function(static function()use($result){file_put_contents($result,json_encode(['status'=>http_response_code()?:200,'error'=>error_get_last()]));});
require __DIR__.'/config.php';require __DIR__.'/manager/lib/ManagerHttp.php';
if($argv[1]==='seed'){
    ManagerHttp::start();$_SESSION['manager_id']=(int)$_GET['manager'];$_SESSION['csrf']='fixture-csrf';session_write_close();exit;
}
if($argv[1]==='sibling'){
    $start=microtime(true);ManagerHttp::start();$wait=(microtime(true)-$start)*1000;
    $manager=ManagerHttp::requireManager();ManagerHttp::requireCsrf(['csrf'=>$_GET['csrf']??'']);
    file_put_contents($result.'.ready',json_encode(['manager_id'=>$manager['id'],'wait_ms'=>round($wait,2)]));session_write_close();exit;
}
if($argv[1]==='cache'){
    require __DIR__.'/services/ManagerMediaCache.php';$ids=[];
    foreach(['allowed'=>42,'project-denied'=>43,'routing-denied'=>44,'unknown-conversation'=>45,'invalid-conversation'=>0,'missing-path'=>42,'missing-file'=>42,'expired'=>42] as $key=>$conversation){
        // Invalid conversation metadata is exercised after a normal cache store.
        $entry=ManagerMediaCache::store(max(1,$conversation),7,__DIR__.'/fixture.png','fixture.png','image/png');
        if(!$entry)throw new RuntimeException('Cannot create synthetic media cache');
        $ids[$key]=$entry['id'];$path=__DIR__.'/runtime/manager-media/'.$entry['id'];
        $meta=json_decode(file_get_contents($path.'.json'),true);
        if($key==='invalid-conversation')$meta['conversation_id']=0;
        if($key==='missing-path')$meta['path']=__DIR__.'/absent.bin';
        if($key==='expired')$meta['created_at']=time()-604801;
        file_put_contents($path.'.json',json_encode($meta));
        if($key==='missing-file')unlink($path.'.bin');
    }
    echo json_encode($ids);exit;
}
require __DIR__.'/manager/media-file.php';
PHP);
    $bytes=base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+a/2kAAAAASUVORK5CYII=')
        .str_repeat('synthetic-private-media-',180000);
    file_put_contents($tmp.'/fixture.png',$bytes);
    file_put_contents($tmp.'/runtime/incoming-media/'.str_repeat('a',32).'.bin',$bytes);
    $sessions=['manager'=>'fixture7','inactive'=>'fixture8','anonymous'=>'fixture0'];
    foreach (['manager'=>7,'inactive'=>8,'anonymous'=>0] as $label=>$id) {
        $worker=mediaSessionWorker('seed',$sessions[$label],'manager='.$id,$tmp.'/seed-'.$id.'.json');
        $seed=mediaSessionFinish($worker);if($seed['errors']!=='')throw new RuntimeException($seed['errors']);
    }
    $worker=mediaSessionWorker('cache',$sessions['manager'],'',$tmp.'/cache.json');
    $cache=mediaSessionFinish($worker);if($cache['errors']!=='')throw new RuntimeException($cache['errors']);
    $ids=json_decode($cache['body'],true,512,JSON_THROW_ON_ERROR);

    foreach (['cached'=>'id='.$ids['allowed'],'provider'=>'message_id=99&attachment=0'] as $label=>$query) {
        $media=[];$sibling=[];
        try {
            $status=$tmp.'/'.$label.'.json';$ready=$tmp.'/'.$label.'-sibling.json';
            $media=mediaSessionWorker('media',$sessions['manager'],$query,$status);
            $first='';$deadline=microtime(true)+3;
            while($first===''&&microtime(true)<$deadline){$first=(string)fread($media['out'],8192);if($first==='')usleep(1000);}
            if($first==='')throw new RuntimeException('Private media fixture did not start streaming');
            mediaSessionCheck($label.' stream remains active while output is held',proc_get_status($media['process'])['running']&&!is_file($status));
            $sibling=mediaSessionWorker('sibling',$sessions['manager'],'csrf=fixture-csrf',$ready);
            $deadline=microtime(true)+2;
            while(!is_file($ready.'.ready')&&microtime(true)<$deadline)usleep(1000);
            $unblocked=is_file($ready.'.ready');
            mediaSessionCheck($label.' same-session authenticated CSRF request finishes before file transfer',
                $unblocked&&proc_get_status($media['process'])['running']&&!is_file($status));
            // Draining is the explicit release: a baseline blocked sibling can
            // now finish too, so neither failed assertions nor fixtures hang CI.
            $response=mediaSessionFinish($media,$first);$other=mediaSessionFinish($sibling);
            $info=json_decode((string)file_get_contents($status),true);
            $otherInfo=json_decode((string)file_get_contents($ready.'.ready'),true);
            mediaSessionCheck($label.' private bytes and HTTP 200 are unchanged',($info['status']??0)===200
                &&($info['error']??null)===null&&$response['errors']===''&&hash_equals(hash('sha256',$bytes),hash('sha256',$response['body'])));
            mediaSessionCheck($label.' sibling retains manager identity',($otherInfo['manager_id']??0)===7&&$other['errors']==='');
            echo 'INFO '.$label.' sibling session wait '.($otherInfo['wait_ms']??'unknown')." ms\n";
        } finally {mediaSessionStop($media);mediaSessionStop($sibling);}
    }
    $cases=[
        ['invalid cached id','manager','id=invalid',404],
        ['missing cached id','manager','id='.str_repeat('0',32),404],
        ['missing cached file','manager','id='.$ids['missing-file'],404],
        ['missing validated path','manager','id='.$ids['missing-path'],404],
        ['expired cached file','manager','id='.$ids['expired'],404],
        ['denied project','manager','id='.$ids['project-denied'],403],
        ['denied routing group','manager','id='.$ids['routing-denied'],403],
        ['unknown conversation','manager','id='.$ids['unknown-conversation'],403],
        ['invalid conversation','manager','id='.$ids['invalid-conversation'],403],
        ['anonymous manager','anonymous','id='.$ids['allowed'],401],
        ['inactive manager','inactive','id='.$ids['allowed'],401],
        ['missing provider message','manager','message_id=100&attachment=0',404],
        ['denied provider project','manager','message_id=101&attachment=0',404],
        ['invalid provider attachment','manager','message_id=99&attachment=21',404],
        ['invalid provider message id','manager','message_id=invalid&attachment=0',404],
        ['anonymous provider request','anonymous','message_id=99&attachment=0',401],
        ['invalid sibling CSRF','manager','csrf=invalid',403],
    ];
    foreach($cases as $index=>[$label,$session,$query,$expected]){
        $status=$tmp.'/denied-'.$index.'.json';$worker=[];
        try {
            $worker=mediaSessionWorker($label==='invalid sibling CSRF'?'sibling':'media',$sessions[$session],$query,$status);
            $response=mediaSessionFinish($worker);$info=json_decode((string)file_get_contents($status),true);
            mediaSessionCheck($label.' keeps HTTP '.$expected.' without private file bytes',($info['status']??0)===$expected
                &&($info['error']??null)===null&&$response['errors']===''&&!str_contains($response['body'],'synthetic-private-media-'));
        } finally {mediaSessionStop($worker);}
    }
    $trace=array_map(static fn($line)=>json_decode($line,true),file($tmp.'/db-trace.jsonl',FILE_IGNORE_NEW_LINES));
    $protected=array_filter($trace,static fn($row)=>str_starts_with($row['sql'],'SELECT c.id,c.project_key,')||str_starts_with($row['sql'],'INSERT INTO manager_conversation_reads '));
    mediaSessionCheck('conversation authorization and read marker still run inside the session lock',
        count($protected)>0&&count(array_filter($protected,static fn($row)=>!$row['active']))===0);
} catch(Throwable $error) {
    mediaSessionCheck('bounded media session fixture completes',false);echo $error->getMessage()."\n";
} finally {
    if(isset($worker)&&is_array($worker))mediaSessionStop($worker);
    if(isset($media)&&is_array($media))mediaSessionStop($media);
    if(isset($sibling)&&is_array($sibling))mediaSessionStop($sibling);
    if(is_dir($tmp))mediaSessionRemove($tmp);
}
echo 'MANAGER MEDIA SESSION CHECKS: '.($passed+$failed).' | PASS '.$passed.' | FAIL '.$failed."\n";
exit($failed?1:0);
