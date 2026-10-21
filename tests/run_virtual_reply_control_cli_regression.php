<?php

declare(strict_types=1);

require_once dirname(__DIR__).'/services/VirtualReplyControlProtocol.php';

$passed=0;
function controlCliCheck(string $label,bool $valid):void
{
    global $passed;
    if (!$valid) throw new RuntimeException('FAIL '.$label);
    $passed++;echo 'PASS '.$label."\n";
}

function controlCliRun(string $root,array $args):array
{
    $pipes=[];
    $process=proc_open([PHP_BINARY,'-d','display_errors=1',$root.'/tools/virtual_reply_learning.php',...$args],
        [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,$root);
    if (!is_resource($process)) throw new RuntimeException('fixture_process_failed');
    fclose($pipes[0]);$out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);
    fclose($pipes[1]);fclose($pipes[2]);$code=proc_close($process);
    return ['code'=>$code,'out'=>$out,'err'=>$err,'json'=>json_decode($out,true)];
}

function controlCliRemove(string $root):void
{
    foreach (new FilesystemIterator($root,FilesystemIterator::SKIP_DOTS) as $item) {
        if ($item->isDir()&&!$item->isLink()) controlCliRemove($item->getPathname());else unlink($item->getPathname());
    }
    rmdir($root);
}

$fixture=sys_get_temp_dir().'/virtual-reply-control-cli-'.bin2hex(random_bytes(6));
mkdir($fixture,0700);mkdir($fixture.'/tools',0700);mkdir($fixture.'/services',0700);
try {
    copy(dirname(__DIR__).'/tools/virtual_reply_learning.php',$fixture.'/tools/virtual_reply_learning.php');
    foreach (['VirtualReplyControlProtocol.php','VirtualReplyProtocol.php','TourConsultationPolicy.php'] as $file) copy(dirname(__DIR__).'/services/'.$file,$fixture.'/services/'.$file);
    file_put_contents($fixture.'/config.php',<<<'CONFIG'
<?php
file_put_contents(__DIR__.'/config-loaded','yes');
final class ProjectConfig { public static function projectId():string { return is_file(__DIR__.'/other-project')?'other':'anytour'; } }
final class ConversationDb { public static function connection():PDO { return new PDO('sqlite::memory:'); } }
CONFIG);
    file_put_contents($fixture.'/services/VirtualReplyStore.php',<<<'STORE'
<?php
final class VirtualReplyStore {
    public function __construct(PDO $pdo) {}
    public function capture(string $source,string $lane,int $limit):array {
        file_put_contents(dirname(__DIR__).'/delegated',json_encode(['capture',$source,$lane,$limit]));
        return ['scanned'=>7,'captured'=>2,'unchanged'=>5,'invalidated'=>0,'payload'=>'PRIVATE synthetic customer'];
    }
    public function reconcile(int $limit):array {
        file_put_contents(dirname(__DIR__).'/delegated',json_encode(['reconcile',$limit]));
        return ['checked'=>9,'recaptured'=>1,'invalidated'=>1,'expired'=>0,'claim_token'=>'PRIVATE synthetic token'];
    }
}
STORE);
    file_put_contents($fixture.'/services/VirtualReplyControlStore.php',<<<'CONTROL'
<?php
final class VirtualReplyControlStore {
    public function __construct(PDO $pdo) {
        if (is_file(dirname(__DIR__).'/fail-store')) throw new RuntimeException('PRIVATE synthetic exception https://private.test');
    }
    public function register(array $baseline,array $candidate,int $limit,int $adminId):array {
        file_put_contents(dirname(__DIR__).'/delegated',json_encode(['register',$baseline,$candidate,$limit,$adminId]));
        return ['purpose'=>'control','run_id'=>str_repeat('a',64),'registered_cases'=>3,'requested_cases'=>$limit,'ineligible_candidates'=>1,'has_more'=>true,
            'sampling'=>['status'=>'enabled','groups'=>['development'=>6,'reserved'=>4,'contaminated'=>1,'payload'=>'PRIVATE'],
                'legacy_pending'=>0,'enrollment_started_at'=>'2026-10-21 19:00:00','payload'=>'PRIVATE'],
            'jobs'=>[['job_id'=>'PRIVATE','claim_token'=>'PRIVATE']],'payload'=>'PRIVATE','instructions'=>'PRIVATE'];
    }
    public function status():array {
        file_put_contents(dirname(__DIR__).'/delegated',json_encode(['status']));
        if (is_file(dirname(__DIR__).'/missing-migration')) return ['purpose'=>'control','status'=>'migration_unavailable'];
        return ['purpose'=>'control','status'=>'enabled','runs'=>[['run_id'=>str_repeat('b',64),'registered_cases'=>3,'created_at'=>'PRIVATE','payload'=>'PRIVATE']],
            'reconciliation'=>['checked'=>3,'invalidated'=>1,'payload'=>'PRIVATE'],
            'quotas'=>['day_utc'=>'PRIVATE','limit_per_role'=>200,'author_used'=>8,'reviewer_used'=>4,'claim_token'=>'PRIVATE'],
            'sampling'=>['status'=>'enabled','groups'=>['development'=>6,'reserved'=>4,'contaminated'=>1],'legacy_pending'=>0,'private'=>'PRIVATE'],
            'payload'=>'PRIVATE','instructions'=>'PRIVATE'];
    }
}
CONTROL);
    $baseline=VirtualReplyControlProtocol::authorContract('SYNTHETIC frozen baseline instruction');
    $candidate=VirtualReplyControlProtocol::authorContract('SYNTHETIC frozen candidate instruction');
    $baseFile=$fixture.'/baseline.json';$candidateFile=$fixture.'/candidate.json';
    file_put_contents($baseFile,json_encode($baseline,JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE));
    file_put_contents($candidateFile,json_encode($candidate,JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE));
    $invalid=[[],['unknown'],['control-status','extra'],['control-register'],['capture','conversation','fresh','21'],
        ['capture','conversation','fresh','1','extra'],['reconcile','0'],['reconcile','1','extra'],
        ['control-register',$baseFile,$candidateFile,'0','1'],['control-register',$baseFile,$candidateFile,'21','1'],
        ['control-register',$baseFile,$candidateFile,'1','0'],['control-register',$baseFile,$candidateFile,'1','01'],
        ['control-register',$baseFile,$candidateFile,'1','-1'],['control-register',$baseFile,$candidateFile,'1',(string)PHP_INT_MAX.'0']];
    $all=true;
    foreach ($invalid as $args) {$result=controlCliRun($fixture,$args);$all=$all&&$result['code']===2&&$result['out']===''&&!is_file($fixture.'/config-loaded');}
    controlCliCheck('invalid modes and arguments exit2 before loading configuration',$all);
    file_put_contents($fixture.'/invalid.json',"{\"instructions\":\"PRIVATE broken\"}");
    file_put_contents($fixture.'/unicode.json',"\xff");file_put_contents($fixture.'/oversized.json',str_repeat(' ',262145));
    file_put_contents($fixture.'/trailing.json',(string)file_get_contents($candidateFile).' {}');
    $bad=$candidate;$bad['instruction_revision']=str_repeat('c',64);file_put_contents($fixture.'/revision.json',json_encode($bad,JSON_THROW_ON_ERROR));
    $bad=$candidate;$bad['profile']['model']='weaker';$bad['instruction_revision']=VirtualReplyControlProtocol::contractRevision('author',$bad['instructions'],$bad['schema'],$bad['profile']);file_put_contents($fixture.'/profile.json',json_encode($bad,JSON_THROW_ON_ERROR));
    symlink($candidateFile,$fixture.'/linked.json');
    $all=true;
    foreach ([$fixture.'/missing.json',$fixture.'/invalid.json',$fixture.'/unicode.json',$fixture.'/oversized.json',$fixture.'/trailing.json',$fixture.'/revision.json',$fixture.'/profile.json',$fixture.'/linked.json',$fixture,'php://memory',$baseFile] as $path) {
        $result=controlCliRun($fixture,['control-register',$baseFile,$path,'1','1']);
        $all=$all&&$result['code']===2&&$result['out']===''&&!is_file($fixture.'/config-loaded')&&!str_contains($result['err'],'PRIVATE');
    }
    controlCliCheck('nonregular unbounded malformed Unicode or unsupported contracts fail before config',$all);
    $result=controlCliRun($fixture,['capture','telegram_archive','fresh','20']);
    controlCliCheck('capture delegates unchanged and prints only its original aggregate projection',$result['code']===0&&$result['err']===''
        &&json_decode((string)file_get_contents($fixture.'/delegated'),true)===['capture','telegram_archive','fresh',20]
        &&$result['json']===['ok'=>true,'operation'=>'capture','counts'=>['scanned'=>7,'captured'=>2,'unchanged'=>5,'invalidated'=>0],'provider_calls'=>0]);
    $result=controlCliRun($fixture,['reconcile','4']);
    controlCliCheck('reconcile delegates unchanged and does not export claims',$result['code']===0&&$result['err']===''
        &&json_decode((string)file_get_contents($fixture.'/delegated'),true)===['reconcile',4]
        &&$result['json']===['ok'=>true,'operation'=>'reconcile','counts'=>['invalidated'=>1,'checked'=>9,'recaptured'=>1,'expired'=>0],'provider_calls'=>0]);
    $result=controlCliRun($fixture,['control-register',$baseFile,$candidateFile,'7','42']);
    controlCliCheck('explicit registration receives exact supported frozen contracts and bounded owner arguments',$result['code']===0&&$result['err']===''
        &&json_decode((string)file_get_contents($fixture.'/delegated'),true)===['register',$baseline,$candidate,7,42]
        &&$result['json']['run_id']===str_repeat('a',64)&&$result['json']['revisions']===['baseline'=>$baseline['instruction_revision'],'candidate'=>$candidate['instruction_revision'],'reviewer'=>VirtualReplyControlProtocol::revision()]
        &&$result['json']['counts']===['registered_cases'=>3,'requested_cases'=>7,'ineligible_candidates'=>1]&&$result['json']['provider_calls']===0);
    controlCliCheck('registration projects only counts revisions run identity and safe sampling aggregates',!str_contains($result['out'],'PRIVATE')
        &&!str_contains($result['out'],'SYNTHETIC')&&!str_contains($result['out'],'claim_token')&&!str_contains($result['out'],'job_id')&&!str_contains($result['out'],'payload'));
    $result=controlCliRun($fixture,['control-status']);
    controlCliCheck('status requires no private arguments and exports bounded aggregate rows',$result['code']===0&&$result['err']===''
        &&json_decode((string)file_get_contents($fixture.'/delegated'),true)===['status']
        &&$result['json']['runs']===[['run_id'=>str_repeat('b',64),'registered_cases'=>3]]
        &&$result['json']['quotas']===['limit_per_role'=>200,'author_used'=>8,'reviewer_used'=>4]
        &&$result['json']['counts']===['checked'=>3,'invalidated'=>1]&&!str_contains($result['out'],'PRIVATE')&&!str_contains($result['out'],'payload'));
    file_put_contents($fixture.'/missing-migration','yes');$result=controlCliRun($fixture,['control-status']);unlink($fixture.'/missing-migration');
    controlCliCheck('missing control migration is reported as unavailable without pretending registration',$result['code']===0
        &&$result['json']===['ok'=>true,'operation'=>'control-status','purpose'=>'control','status'=>'migration_unavailable','provider_calls'=>0]);
    file_put_contents($fixture.'/other-project','yes');unlink($fixture.'/delegated');$result=controlCliRun($fixture,['control-status']);unlink($fixture.'/other-project');
    controlCliCheck('a different configured project fails closed before store delegation',$result['code']===1&&$result['out']===''
        &&$result['err']==="virtual_reply_learning_failed\n"&&!is_file($fixture.'/delegated'));
    file_put_contents($fixture.'/fail-store','yes');$result=controlCliRun($fixture,['control-status']);unlink($fixture.'/fail-store');
    controlCliCheck('runtime exceptions are sanitized without source paths or private exception text',$result['code']===1&&$result['out']===''
        &&$result['err']==="virtual_reply_learning_failed\n");
    unlink($fixture.'/config.php');$result=controlCliRun($fixture,['control-status']);
    controlCliCheck('missing configuration is also a single sanitized runtime failure',$result['code']===1&&$result['out']===''
        &&$result['err']==="virtual_reply_learning_failed\n");
    echo "Virtual reply control CLI regression: {$passed} passed, 0 failed\n";
} finally { controlCliRemove($fixture); }
