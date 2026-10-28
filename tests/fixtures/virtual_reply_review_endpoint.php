<?php
declare(strict_types=1);
if (PHP_SAPI!=='cli') {http_response_code(404);exit;}
/** Execute exact endpoint/session source with a disposable synthetic SQLite app. */
function journalEndpointChecks(callable $check): void
{
    $root=dirname(__DIR__,2);$tmp=sys_get_temp_dir().'/journal-http-'.bin2hex(random_bytes(6));
    mkdir($tmp,0700);mkdir($tmp.'/manager',0700);mkdir($tmp.'/manager/lib',0700);mkdir($tmp.'/sessions',0700);
    symlink($root.'/services',$tmp.'/services');
    copy($root.'/manager/virtual-reply-reviews-api.php',$tmp.'/manager/virtual-reply-reviews-api.php');
    copy($root.'/manager/lib/ManagerHttp.php',$tmp.'/manager/lib/ManagerHttp.php');
    $bootstrap=<<<'PHP'
<?php
require_once ROOT.'/services/ConversationDb.php';
require_once ROOT.'/services/ProjectConfig.php';
require_once ROOT.'/services/ManagerRequestContext.php';
require_once ROOT.'/tests/fixtures/virtual_reply_sampling.php';
ProjectConfig::resetForTests(['id'=>'anytour','brand'=>['name'=>'AnyTour']]);
$pdo=new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
samplingSchema($pdo);
$pdo->exec("CREATE TABLE projects(id INTEGER PRIMARY KEY,project_key TEXT,display_name TEXT,is_active INTEGER);INSERT INTO projects VALUES(1,'anytour','AnyTour',1),(2,'other','Other',1)");
$pdo->exec("CREATE TABLE managers(id INTEGER PRIMARY KEY,login TEXT,display_name TEXT,role TEXT,email TEXT,is_active INTEGER,is_working INTEGER);INSERT INTO managers VALUES(1,'owner','Owner','admin','',1,0),(2,'manager','Manager','manager','',1,0),(3,'other-admin','Other','admin','',1,0),(4,'inactive','Inactive','admin','',0,0)");
$pdo->exec('CREATE TABLE manager_projects(manager_id INTEGER,project_id INTEGER);INSERT INTO manager_projects VALUES(1,1),(2,1),(3,2),(4,1)');
$ref=new ReflectionProperty(ConversationDb::class,'pdo');$ref->setValue(null,$pdo);
ini_set('session.save_path',SESSIONS);ManagerRequestContext::startSession();$_SESSION['manager_id']=(int)$argv[2];
if(($argv[4]??'')==='broken')$pdo->exec('DROP TABLE virtual_reply_jobs');
PHP;
    $bootstrap=str_replace(['ROOT','SESSIONS'],[var_export($root,true),var_export($tmp.'/sessions',true)],$bootstrap);
    file_put_contents($tmp.'/config.php',$bootstrap);
    file_put_contents($tmp.'/request.php',"<?php\nob_start();register_shutdown_function(function(){\$body=ob_get_clean();echo json_encode(['status'=>http_response_code(),'body'=>json_decode(\$body,true),'raw'=>json_decode(\$body,true)===null?\$body:null]);});\n\$_SERVER['REQUEST_METHOD']=\$argv[1];\$_GET=json_decode(\$argv[3],true);require __DIR__.'/manager/virtual-reply-reviews-api.php';\n");
    $run=static function(string $method,int $actor,array $args=[],string $mode='')use($tmp):array {
        $proc=proc_open([PHP_BINARY,$tmp.'/request.php',$method,(string)$actor,json_encode($args,JSON_THROW_ON_ERROR),$mode],[1=>['pipe','w'],2=>['pipe','w']],$pipes);
        if(!is_resource($proc))throw new RuntimeException('journal_fixture_process');
        $out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);$code=proc_close($proc);
        if($code!==0||$err!=='')throw new RuntimeException('journal_fixture_failed: '.$err);
        return json_decode($out,true,512,JSON_THROW_ON_ERROR);
    };
    try {
        foreach([['GET',0,401],['POST',0,405],['GET',2,403],['GET',3,403],['GET',4,401]] as [$method,$actor,$status])$check('exact endpoint rejects unauthorized method/actor',$run($method,$actor)['status']===$status);
        $valid=$run('GET',1);$check('assigned admin reaches exact endpoint with read-only project initialization',$valid['status']===200&&$valid['body']===['ok'=>true,'reviews'=>[],'has_more'=>false,'next_cursor'=>null]);
        foreach([['cursor'=>'bad'],['purpose'=>'control'],['case_id'=>'bad'],['limit'=>1000]] as $bad)$check('exact endpoint rejects invalid and control inputs',$run('GET',1,$bad)['status']===422);
        $gone=$run('GET',1,['case_id'=>str_repeat('f',64)]);$check('missing case gives sanitized unavailable response',$gone['status']===404&&$gone['body']===['ok'=>false,'error'=>'review_unavailable']);
        $broken=$run('GET',1,[],'broken');$check('technical failure is not empty successful history',$broken['status']===503&&$broken['body']===['ok'=>false,'error'=>'storage_unavailable']);
    } finally {
        foreach(glob($tmp.'/sessions/*')?:[] as $f)unlink($f);
        unlink($tmp.'/manager/lib/ManagerHttp.php');rmdir($tmp.'/manager/lib');unlink($tmp.'/manager/virtual-reply-reviews-api.php');rmdir($tmp.'/manager');
        unlink($tmp.'/services');unlink($tmp.'/config.php');unlink($tmp.'/request.php');rmdir($tmp.'/sessions');rmdir($tmp);
    }
}
