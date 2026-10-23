<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

// Real endpoint, authentication, CSRF, visibility and PHP session locks. Only
// database responses and secondary card loaders are synthetic. Two independent
// HTTP workers share the isolated session directory; no provider is contacted.
$root=dirname(__DIR__);$tmp=sys_get_temp_dir().'/manager-card-session-'.bin2hex(random_bytes(8));
$servers=[];$streams=[];$passed=0;$failed=0;
function cardCheck(string $name,bool $ok):void {global $passed,$failed;echo($ok?'PASS ':'FAIL ').$name."\n";$ok?$passed++:$failed++;}
function cardRemove(string $path):void {if(is_dir($path)){foreach(scandir($path)as$n)if($n!=='.'&&$n!=='..')cardRemove($path.'/'.$n);rmdir($path);}else @unlink($path);}
function cardCopy(string $relative):void {
    global $root,$tmp;$source=realpath($root.'/'.$relative);
    if(!$source||!str_starts_with($source,$root.'/'))throw new RuntimeException('Invalid fixture dependency');
    $relative=substr($source,strlen($root)+1);$target=$tmp.'/'.$relative;if(is_file($target))return;
    if(!is_dir(dirname($target)))mkdir(dirname($target),0700,true);copy($source,$target);
    preg_match_all("~require_once\\s+__DIR__\\s*\\.\\s*'([^']+)'~",(string)file_get_contents($source),$matches);
    foreach($matches[1]as$dependency)cardCopy(dirname($relative).'/'.$dependency);
}
function cardStart(string $address,string $path,array $data=[],string $cookie='',string $hold=''):array {
    global $streams;$stream=stream_socket_client('tcp://'.$address,$errno,$error,2);
    if(!$stream)throw new RuntimeException('Cannot connect to fixture');
    $body=json_encode($data,JSON_THROW_ON_ERROR);$headers="POST $path HTTP/1.1\r\nHost: $address\r\nContent-Type: application/json\r\nConnection: close\r\nContent-Length: ".strlen($body)."\r\n";
    if($cookie!=='')$headers.="Cookie: $cookie\r\n";if($hold!=='')$headers.="X-Fixture-Hold: $hold\r\n";
    fwrite($stream,$headers."\r\n".$body);stream_set_blocking($stream,false);$streams[]=$stream;
    return ['stream'=>$stream,'raw'=>'','start'=>hrtime(true)];
}
function cardPoll(array &$request,float $seconds):bool {
    $deadline=microtime(true)+$seconds;
    do {$request['raw'].=(string)stream_get_contents($request['stream']);if(feof($request['stream']))return true;usleep(1000);}while(microtime(true)<$deadline);
    return false;
}
function cardFinish(array &$request):array {
    if(!cardPoll($request,3))throw new RuntimeException('Fixture response timeout');
    [$headers,$body]=array_pad(explode("\r\n\r\n",$request['raw'],2),2,'');preg_match('~^HTTP/[^ ]+ (\d+)~',$headers,$status);
    return ['status'=>(int)($status[1]??0),'body'=>json_decode($body,true),'headers'=>$headers,'ms'=>round((hrtime(true)-$request['start'])/1e6,2)];
}
function cardRequest(string $address,string $path,array $data=[],string $cookie=''):array {$request=cardStart($address,$path,$data,$cookie);return cardFinish($request);}
function cardWait(string $file):void {$deadline=microtime(true)+3;while(!is_file($file)){if(microtime(true)>$deadline)throw new RuntimeException('Fixture gate not reached');usleep(1000);}}

try {
    mkdir($tmp,0700);mkdir($tmp.'/sessions',0700);mkdir($tmp.'/manager',0700);
    foreach(['manager/pipeline-api.php','manager/lib/ManagerHttp.php','services/ManagerRequestContext.php','services/ManagerConversationService.php',
        'services/ManagerLeadInboxService.php','services/ManagerQueueProjectionService.php','services/ManagerWorkspaceFilterService.php',
        'services/SalesPipelineCatalogAdminService.php','services/LeadTaskService.php']as$dependency)cardCopy($dependency);
    file_put_contents($tmp.'/config.php',"<?php require_once __DIR__.'/services/ProjectConfig.php';ProjectConfig::resetForTests(['id'=>'fixture','brand'=>['name'=>'Fixture']]);\n");
    file_put_contents($tmp.'/maxsearchclass.php','<?php class MaxSearchApi {static function getAiSearchContext($chat):array{return [];} }');
    file_put_contents($tmp.'/services/ConversationDb.php', <<<'PHP'
<?php
class ConversationDb {static function connection(){return new CardSessionDb;}}
class CardSessionDb {function prepare($sql){return new CardSessionStatement(preg_replace('/\s+/',' ',trim($sql)));}}
class CardSessionStatement {
    private array $rows=[];function __construct(private string $sql){}
    function execute(array $args=[]):bool {
        $sql=$this->sql;$this->rows=[];
        file_put_contents(__DIR__.'/../queries.jsonl',json_encode(['sql'=>$sql,'active'=>session_status()===PHP_SESSION_ACTIVE])."\n",FILE_APPEND);
        if(str_starts_with($sql,'INSERT INTO projects '))return true;
        if($sql==='SELECT id FROM projects WHERE project_key=? LIMIT 1'){$this->rows=[[1]];return true;}
        if(str_starts_with($sql,'SELECT id,login,display_name,role,email,is_active,is_working FROM managers ')){
            $id=(int)$args[0];if(in_array($id,[7,8,9],true))$this->rows=[['id'=>$id,'login'=>'fixture','display_name'=>'Fixture','role'=>$id===9?'admin':'manager','is_active'=>$id===8?0:1,'is_working'=>1]];return true;
        }
        if(str_starts_with($sql,'SELECT p.id,p.project_key,p.display_name FROM projects p JOIN manager_projects ')){$this->rows=[['id'=>1,'project_key'=>'fixture','display_name'=>'Fixture']];return true;}
        if(str_starts_with($sql,'SELECT 1 FROM manager_projects mp JOIN projects ')){$this->rows=$args[1]==='fixture'?[[1]]:[];return true;}
        if(str_starts_with($sql,'SELECT c.id,c.project_key,c.source_id,c.channel,')){
            $id=(int)$args[0];if(in_array($id,[42,43,44,45],true))$this->rows=[['id'=>$id,'project_key'=>$id===43?'denied':'fixture','source_id'=>$id===44?99:0,'channel'=>'max','status'=>'manager','manager_id'=>$id===45?9:7,'external_chat_id'=>'synthetic','phone'=>null,'email'=>null]];return true;
        }
        if(str_starts_with($sql,'SELECT id,primary_group_id,fallback_mode,fallback_group_id,fallback_after_minutes FROM conversation_sources ')){$this->rows=[['id'=>99,'primary_group_id'=>123,'fallback_mode'=>'none','fallback_group_id'=>0]];return true;}
        if(str_starts_with($sql,'SELECT group_id FROM manager_group_members '))return true;
        if(str_starts_with($sql,'SELECT p.id AS project_id,p.project_key,p.display_name,m.role FROM projects ')){
            if((int)$args[0]===9)$this->rows=[['project_id'=>1,'project_key'=>'fixture','display_name'=>'Fixture','role'=>'admin']];return true;
        }
        if(str_starts_with($sql,'SELECT s.project_id,s.id,s.display_name,s.channel FROM conversation_sources ')
            ||str_starts_with($sql,'SELECT g.project_id,g.version,g.include_unsourced,p.project_key,p.display_name FROM manager_supervision '))return true;
        throw new RuntimeException('Unexpected synthetic query: '.$sql);
    }
    function fetch(){return $this->rows[0]??false;}function fetchAll(...$args):array{return $this->rows;}
    function fetchColumn(int $column=0){return array_values($this->rows[0]??[])[$column]??false;}
}
PHP);
    file_put_contents($tmp.'/services/SalesPipelineService.php', <<<'PHP'
<?php
function cardFixtureGate(string $kind):void {
    $directory=dirname(__DIR__);file_put_contents($directory.'/loads.jsonl',json_encode(['kind'=>$kind,'action'=>$GLOBALS['action']??'','active'=>session_status()===PHP_SESSION_ACTIVE])."\n",FILE_APPEND);
    if(($_SERVER['HTTP_X_FIXTURE_HOLD']??'')!==$kind)return;
    touch($directory.'/'.$kind.'.entered');$deadline=microtime(true)+5;
    while(!is_file($directory.'/'.$kind.'.release')){if(microtime(true)>$deadline)throw new RuntimeException('Held fixture timeout');usleep(1000);}
}
class SalesPipelineService {
    static function conversationSnapshot($id):array{cardFixtureGate('read');return ['stage'=>null,'stage_history'=>[],'tags'=>[],'outcome'=>['outcome'=>'open']];}
    static function setStage(...$args):bool{cardFixtureGate('write');return true;}
}
PHP);
    file_put_contents($tmp.'/services/LeadTaskService.php','<?php class LeadTaskService {static function listForConversation($id):array{if(($_SERVER["HTTP_X_FIXTURE_HOLD"]??"")==="degraded")throw new RuntimeException("Synthetic card failure");return [];} }');
    file_put_contents($tmp.'/services/ManagerDeliveryStateService.php','<?php class ManagerDeliveryStateService {static function activeFailure($id){return null;} }');
    file_put_contents($tmp.'/manager/fixture-session.php', <<<'PHP'
<?php
require_once __DIR__.'/lib/ManagerHttp.php';session_id((string)$_GET['session']);ManagerHttp::startJson();
$_SESSION['manager_id']=(int)$_GET['manager'];$_SESSION['csrf']='fixture-csrf';ManagerHttp::respond(['ok'=>true]);
PHP);
    file_put_contents($tmp.'/manager/fixture-transcript.php', <<<'PHP'
<?php
require_once dirname(__DIR__).'/config.php';require_once __DIR__.'/lib/ManagerHttp.php';require_once dirname(__DIR__).'/services/ManagerConversationService.php';
ManagerHttp::startJson();$m=ManagerHttp::requireManager();ManagerHttp::requireCsrf(ManagerHttp::body());
$c=ManagerConversationService::visibleConversation(42,(int)$m['id'],true);
ManagerHttp::respond(['ok'=>$c!==null,'actor'=>$m['id'],'session_active'=>session_status()===PHP_SESSION_ACTIVE]);
PHP);
    for($i=0;$i<2;$i++){
        $socket=stream_socket_server('tcp://127.0.0.1:0',$errno,$error);if(!$socket)throw new RuntimeException('No loopback port');
        $address=stream_socket_get_name($socket,false);fclose($socket);
        $process=proc_open([PHP_BINARY,'-d','session.save_path='.$tmp.'/sessions','-d','session.cache_limiter=','-S',$address,'-t',$tmp],
            [0=>['pipe','r'],1=>['file',$tmp.'/server.log','a'],2=>['file',$tmp.'/server.log','a']],$pipes,$tmp);
        if(!is_resource($process))throw new RuntimeException('No PHP HTTP worker');fclose($pipes[0]);$servers[]=['process'=>$process,'address'=>$address];
        $deadline=microtime(true)+3;do{$probe=@stream_socket_client('tcp://'.$address,$errno,$error,0.05);if($probe){fclose($probe);break;}if(microtime(true)>$deadline)throw new RuntimeException('HTTP worker not ready');usleep(1000);}while(true);
    }
    [$first,$second]=array_column($servers,'address');$cookies=[];
    foreach(['own'=>7,'other'=>7,'inactive'=>8,'admin'=>9]as$key=>$manager){
        $session=bin2hex(random_bytes(16));$seed=cardRequest($second,'/manager/fixture-session.php?session='.$session.'&manager='.$manager);
        if($seed['status']!==200)throw new RuntimeException('Cannot seed isolated session');$cookies[$key]='anytour_manager_panel='.$session;
    }
    $data=['action'=>'detail','conversation_id'=>42,'csrf'=>'fixture-csrf'];$samples=[];
    for($cycle=0;$cycle<3;$cycle++){
        @unlink($tmp.'/read.entered');@unlink($tmp.'/read.release');$held=cardStart($first,'/manager/pipeline-api.php',$data,$cookies['own'],'read');cardWait($tmp.'/read.entered');
        $other=cardRequest($second,'/manager/fixture-transcript.php',['csrf'=>'fixture-csrf'],$cookies['other']);
        cardCheck('different session remains available during card load '.$cycle,$other['status']===200&&$other['body']['ok']);
        $sibling=cardStart($second,'/manager/fixture-transcript.php',['csrf'=>'fixture-csrf'],$cookies['own']);
        $completed=cardPoll($sibling,0.75);$elapsed=round((hrtime(true)-$sibling['start'])/1e6,2);
        cardCheck('same-session transcript completes before held card '.$cycle,$completed);
        touch($tmp.'/read.release');$card=cardFinish($held);$siblingResult=cardFinish($sibling);
        cardCheck('card and transcript retain HTTP payload and actor '.$cycle,$card['status']===200&&$card['body']['ok']&&$card['body']['can_edit_pipeline']&&$siblingResult['status']===200&&$siblingResult['body']['actor']===7);
        $samples[]=['same_session_ms'=>$elapsed,'completed_while_card_held'=>$completed,'different_session_ms'=>$other['ms']];
    }
    echo 'SYNTHETIC_HTTP_SAMPLES '.json_encode($samples,JSON_THROW_ON_ERROR)."\n";
    foreach([['anonymous','',42,'fixture-csrf',401],['inactive',$cookies['inactive'],42,'fixture-csrf',401],
        ['bad CSRF',$cookies['own'],42,'wrong',403],['denied project',$cookies['own'],43,'fixture-csrf',404],
        ['denied routing group',$cookies['own'],44,'fixture-csrf',404],['missing conversation',$cookies['own'],999,'fixture-csrf',404]]as[$label,$cookie,$id,$csrf,$status]){
        $response=cardRequest($second,'/manager/pipeline-api.php',['action'=>'detail','conversation_id'=>$id,'csrf'=>$csrf],$cookie);
        cardCheck($label.' stays denied without card payload',$response['status']===$status&&!isset($response['body']['pipeline']));
    }
    $degraded=cardStart($second,'/manager/pipeline-api.php',$data,$cookies['own'],'degraded');$degraded=cardFinish($degraded);
    cardCheck('secondary failure preserves degraded read-only card',$degraded['status']===200&&$degraded['body']['degraded_components']===['tasks']&&!$degraded['body']['can_edit_pipeline']);
    $forbidden=cardRequest($second,'/manager/pipeline-api.php',['action'=>'set_stage','conversation_id'=>45,'stage_key'=>'open','csrf'=>'fixture-csrf'],$cookies['own']);
    cardCheck('visible foreign conversation still forbids ordinary manager writes',$forbidden['status']===403);
    $write=cardStart($first,'/manager/pipeline-api.php',['action'=>'set_stage','conversation_id'=>42,'stage_key'=>'open','csrf'=>'fixture-csrf'],$cookies['own'],'write');cardWait($tmp.'/write.entered');
    $sibling=cardStart($second,'/manager/fixture-transcript.php',['csrf'=>'fixture-csrf'],$cookies['own']);
    cardCheck('write action retains same-session serialization',!cardPoll($sibling,0.1));touch($tmp.'/write.release');
    $write=cardFinish($write);$sibling=cardFinish($sibling);cardCheck('write and following authenticated read finish normally',$write['status']===200&&$write['body']['ok']&&$sibling['status']===200);
    $loads=array_map(static fn($line)=>json_decode($line,true),file($tmp.'/loads.jsonl',FILE_IGNORE_NEW_LINES));
    cardCheck('only card reads release the session before secondary loaders',array_filter($loads,static fn($row)=>$row['action']==='detail'&&$row['kind']==='read'&&$row['active'])===[]&&count(array_filter($loads,static fn($row)=>$row['kind']==='write'&&$row['active']))===1);
    $queries=array_map(static fn($line)=>json_decode($line,true),file($tmp.'/queries.jsonl',FILE_IGNORE_NEW_LINES));
    cardCheck('conversation visibility is checked while session is still locked',array_filter($queries,static fn($row)=>str_starts_with($row['sql'],'SELECT c.id,c.project_key,c.source_id,c.channel,')&&!$row['active'])===[]);
} catch(Throwable $error){cardCheck('bounded actual HTTP/session fixture completes',false);echo $error->getMessage()."\n";if(is_file($tmp.'/server.log'))echo substr((string)file_get_contents($tmp.'/server.log'),-4000);}
finally {
    if(is_dir($tmp)){touch($tmp.'/read.release');touch($tmp.'/write.release');}
    foreach($streams as$stream)if(is_resource($stream))fclose($stream);
    foreach($servers as$server){proc_terminate($server['process']);proc_close($server['process']);}
    if(is_dir($tmp))cardRemove($tmp);
}
echo "TOTAL ".($passed+$failed)." | PASS $passed | FAIL $failed\n";exit($failed?1:0);
