<?php

declare(strict_types=1);

require_once dirname(__DIR__).'/services/ManagerConversationService.php';

/** Count actual service SQL calls against synthetic identity/routing data only. */
final class InboxAccessPdo extends PDO
{
    public int $executions=0;
    public array $manager=['id'=>7,'login'=>'fixture','display_name'=>'Fixture','role'=>'admin','email'=>'','is_active'=>1,'is_working'=>1];
    public array $projects=['anytour'];
    public array $sources=[];
    public array $groups=[];
    public array $rows=[];
    public function __construct(){}
    public function allowsProject(string $key):bool{foreach($this->projects as $allowed){if(strcasecmp(rtrim($allowed),rtrim($key))===0)return true;}return false;}
    public function prepare(string $query,array $options=[]):PDOStatement|false{return new InboxAccessStatement($query,$this);}
    public function query(string $query,?int $fetchMode=null,mixed ...$fetchModeArgs):PDOStatement|false{$statement=$this->prepare($query);$statement->execute();return$statement;}
}

final class InboxAccessStatement extends PDOStatement
{
    private mixed $result=[];
    public function __construct(private string $sql,private InboxAccessPdo $database){}
    public function execute(?array $params=null):bool
    {
        $params=$params??[];$db=$this->database;$db->executions++;
        if(!str_starts_with($this->sql,'SELECT'))throw new RuntimeException('unexpected_permission_write');
        if(str_contains($this->sql,'FROM managers WHERE id='))$this->result=(int)($params[0]??0)===$db->manager['id']?$db->manager:false;
        elseif(str_contains($this->sql,'FROM projects p JOIN manager_projects'))$this->result=array_map(static fn(string $key):array=>['project_key'=>$key],$db->projects);
        elseif(str_starts_with($this->sql,'SELECT ? AS project_key WHERE EXISTS')){$this->result=[];foreach(array_chunk($params,3) as [$key,$managerId,$lookup]){if((int)$managerId===$db->manager['id']&&$db->allowsProject($lookup))$this->result[]=['project_key'=>$key];}}
        elseif(str_contains($this->sql,'SELECT 1 FROM manager_projects'))$this->result=$db->allowsProject((string)($params[1]??''))?1:false;
        elseif(str_contains($this->sql,'FROM conversation_sources WHERE id IN'))$this->result=array_values(array_filter($db->sources,static fn(array $source):bool=>in_array((int)$source['id'],$params,true)&&!empty($source['is_active'])));
        elseif(str_contains($this->sql,'FROM conversation_sources WHERE id='))$this->result=$db->sources[(int)($params[0]??0)]??false;
        elseif(str_contains($this->sql,'SELECT group_id FROM manager_group_members'))$this->result=array_map(static fn(int $id):array=>['group_id'=>$id],array_values(array_intersect($db->groups,array_slice($params,1))));
        elseif(str_contains($this->sql,'SELECT 1 FROM manager_group_members'))$this->result=in_array((int)($params[1]??0),$db->groups,true)?1:false;
        elseif(str_starts_with($this->sql,'SELECT c.id,c.project_key'))$this->result=$db->rows;
        elseif(str_contains($this->sql,'FROM lead_stages')||str_contains($this->sql,'FROM conversation_lead_tags')||str_contains($this->sql,'FROM lead_stage_history')||str_contains($this->sql,'FROM conversation_events e JOIN'))$this->result=[];
        else throw new RuntimeException('unexpected_permission_query: '.$this->sql);
        return true;
    }
    public function fetch(int $mode=PDO::FETCH_DEFAULT,int $orientation=PDO::FETCH_ORI_NEXT,int $offset=0):mixed{return$this->result;}
    public function fetchAll(int $mode=PDO::FETCH_DEFAULT,mixed ...$args):array{return is_array($this->result)?$this->result:[];}
    public function fetchColumn(int $column=0):mixed{return$this->result;}
}

$pdo=new InboxAccessPdo();
(new ReflectionProperty(ConversationDb::class,'pdo'))->setValue(null,$pdo);
foreach([ProjectAccessService::class,ManagerAuthService::class,RoutingAccessService::class] as $class)(new ReflectionProperty($class,'schemaReady'))->setValue(null,true);
$passed=0;$failed=0;
function batchAccessCheck(string $name,bool $ok):void{global$passed,$failed;if($ok){$passed++;echo "PASS {$name}\n";}else{$failed++;echo "FAIL {$name}\n";}}
function batchAccessRow(int $id,array $extra=[]):array{return$extra+['id'=>$id,'project_key'=>'anytour','source_id'=>0,'status'=>'manager','manager_id'=>7,'started_at'=>gmdate('Y-m-d H:i:s',time()-7200),'last_message_at'=>gmdate('Y-m-d H:i:s',time()-3600)];}
function batchAccessSource(int $id,string $mode='none',int $primary=101,int $fallback=102,int $minutes=10):array{return['id'=>$id,'is_active'=>1,'primary_group_id'=>$primary,'fallback_mode'=>$mode,'fallback_group_id'=>$fallback,'fallback_after_minutes'=>$minutes];}

// Invoke the real inbox service. The pre-fix per-row policy performs 608 SELECTs here.
foreach([1,200] as $size){
    $pdo->rows=array_map(static fn(int $id):array=>batchAccessRow($id),range(1,$size));
    $pdo->executions=0;$rows=ManagerConversationService::list(7,'all',200,'*');$queries=$pdo->executions;
    batchAccessCheck("{$size} admin inbox rows remain visible",count($rows)===$size);
    batchAccessCheck("{$size} admin inbox rows use at most 12 SQL calls",$queries<=12);
    echo "EVIDENCE inbox_rows={$size} sql_calls={$queries}\n";
}
if(!method_exists(ManagerConversationAccessPolicy::class,'visibleRows')){
    batchAccessCheck('batch authorization boundary exists',false);
    exit(1);
}
batchAccessCheck('empty and missing identity batches issue no permission reads',ManagerConversationAccessPolicy::visibleRows(7,[])===[]&&ManagerConversationAccessPolicy::visibleRows(0,[batchAccessRow(1)])===[]);
batchAccessCheck('admin still requires granted project',ManagerConversationAccessPolicy::visibleRows(7,[batchAccessRow(1),batchAccessRow(2,['project_key'=>'other'])])===[batchAccessRow(1)]);
$caseRows=[batchAccessRow(1,['project_key'=>'AnyTour']),batchAccessRow(2,['project_key'=>'anytour ']),batchAccessRow(3,['project_key'=>'inactive']),batchAccessRow(4,['project_key'=>'other'])];
batchAccessCheck('singleton uses case-insensitive SQL project access',ManagerConversationAccessPolicy::canView(7,$caseRows[0])&&ManagerConversationAccessPolicy::canView(7,$caseRows[1]));
$pdo->executions=0;
batchAccessCheck('batch preserves SQL project collation and denies inactive/unrelated projects',ManagerConversationAccessPolicy::visibleRows(7,$caseRows)===array_slice($caseRows,0,2));
batchAccessCheck('all unmatched project keys share one SQL fallback',$pdo->executions===3);
$pdo->rows=array_map(static fn(int $id):array=>batchAccessRow($id,['project_key'=>'AnyTour']),range(1,200));$pdo->executions=0;
batchAccessCheck('200 historical project keys stay visible with bounded SQL',count(ManagerConversationService::list(7,'all',200,'*'))===200&&$pdo->executions<=12);

$pdo->manager['role']='manager';$pdo->groups=[101,102];
$pdo->sources=[
    1=>batchAccessSource(1),2=>batchAccessSource(2,'none',103),3=>batchAccessSource(3,'immediate',103),
    4=>batchAccessSource(4,'delayed',103),5=>batchAccessSource(5,'unknown',103),6=>batchAccessSource(6,'immediate',0),
    7=>batchAccessSource(7,'immediate',103,104),8=>batchAccessSource(8,'delayed',103,102,-10),
    9=>batchAccessSource(9,'immediate',103,0),10=>array_replace(batchAccessSource(10,'none',103),['is_active'=>0]),
];
$past=gmdate('Y-m-d H:i:s',time()-3600);$future=gmdate('Y-m-d H:i:s',time()+3600);
$cases=[
    [batchAccessRow(1),true,'unrestricted source'],
    [batchAccessRow(2,['project_key'=>'other']),false,'ungranted project'],
    [batchAccessRow(3,['source_id'=>1]),true,'primary membership'],
    [batchAccessRow(4,['source_id'=>2]),false,'fallback none'],
    [batchAccessRow(5,['source_id'=>3]),true,'immediate fallback'],
    [batchAccessRow(6,['source_id'=>4]),true,'expired delayed fallback'],
    [batchAccessRow(7,['source_id'=>4,'waiting_since'=>$future]),false,'future waiting anchor'],
    [batchAccessRow(8,['source_id'=>4,'waiting_since'=>$past,'last_message_at'=>$future]),true,'waiting anchor outranks last activity'],
    [batchAccessRow(9,['source_id'=>4,'last_message_at'=>null,'started_at'=>$past]),true,'started-at fallback'],
    [batchAccessRow(10,['source_id'=>4,'last_message_at'=>'']),false,'empty anchor is not bypassed'],
    [batchAccessRow(11,['source_id'=>4,'waiting_since'=>'invalid']),false,'invalid delayed date'],
    [batchAccessRow(12,['source_id'=>5]),false,'unknown fallback mode'],
    [batchAccessRow(13,['source_id'=>6]),true,'no primary group'],
    [batchAccessRow(14,['source_id'=>7]),false,'missing fallback membership'],
    [batchAccessRow(15,['source_id'=>8]),true,'negative delay clamps to zero'],
    [batchAccessRow(16,['source_id'=>9]),false,'missing fallback group'],
    [batchAccessRow(17,['source_id'=>10]),true,'inactive source keeps unrestricted legacy semantics'],
    [batchAccessRow(18,['source_id'=>99]),true,'missing source keeps unrestricted legacy semantics'],
];
$input=array_column($cases,0);$expected=[];
foreach($cases as [$row,$visible,$label]){if($visible)$expected[]=$row;batchAccessCheck('single authorization: '.$label,ManagerConversationAccessPolicy::canView(7,$row)===$visible);}
$before=$input;$pdo->executions=0;$actual=ManagerConversationAccessPolicy::visibleRows(7,$input);
batchAccessCheck('batch preserves exact routing visibility and row order',$actual===$expected);
batchAccessCheck('batch leaves source rows intact',$input===$before);
batchAccessCheck('sourced manager batch with an ungranted key uses five SQL reads',$pdo->executions===5);
$pdo->rows=array_map(static fn(int $id):array=>batchAccessRow($id,['source_id'=>3]),range(1,200));$pdo->executions=0;
$rows=ManagerConversationService::list(7,'all',200,'*');
batchAccessCheck('200 sourced manager inbox rows stay bounded',count($rows)===200&&$pdo->executions<=14);
echo 'EVIDENCE sourced_inbox_rows=200 sql_calls='.$pdo->executions."\n";

$boundary=new ReflectionMethod(RoutingAccessService::class,'visibleInRoutingSnapshot');$now=1800000000;$sources=[4=>$pdo->sources[4]];
$atBoundary=batchAccessRow(1,['source_id'=>4,'waiting_since'=>gmdate('Y-m-d H:i:s',$now-600)]);
batchAccessCheck('delayed fallback opens at the exact boundary',$boundary->invoke(null,$atBoundary,false,true,$sources,[102=>true],$now)===true);
batchAccessCheck('delayed fallback remains closed one second before the boundary',$boundary->invoke(null,$atBoundary,false,true,$sources,[102=>true],$now-1)===false);

$offShift=[batchAccessRow(1,['status'=>'waiting_manager']),batchAccessRow(2)];$pdo->manager['is_working']=0;
batchAccessCheck('off-shift gate hides waiting but preserves assigned dialogue',ManagerConversationAccessPolicy::visibleRows(7,$offShift)===[$offShift[1]]);
$pdo->manager['is_working']=1;
batchAccessCheck('next batch sees the updated shift',ManagerConversationAccessPolicy::visibleRows(7,$offShift)===$offShift);
$memberRow=batchAccessRow(1,['source_id'=>1]);$pdo->groups=[];
batchAccessCheck('next batch sees revoked membership',ManagerConversationAccessPolicy::visibleRows(7,[$memberRow])===[]);
$pdo->groups=[101];
batchAccessCheck('next batch sees restored membership',ManagerConversationAccessPolicy::visibleRows(7,[$memberRow])===[$memberRow]);
$pdo->sources[1]['primary_group_id']=103;
batchAccessCheck('next batch sees source routing mutation',ManagerConversationAccessPolicy::visibleRows(7,[$memberRow])===[]);
$pdo->projects=[];
batchAccessCheck('next batch sees revoked project access',ManagerConversationAccessPolicy::visibleRows(7,[batchAccessRow(1)])===[]);
$pdo->projects=['anytour'];$pdo->manager['role']='admin';
batchAccessCheck('next batch sees updated admin role',ManagerConversationAccessPolicy::visibleRows(7,[$memberRow])===[$memberRow]);
$pdo->manager['role']='manager';
batchAccessCheck('next batch sees admin-role revocation',ManagerConversationAccessPolicy::visibleRows(7,[$memberRow])===[]);

// Also execute the prepared unmatched-key fallback on a real SQL engine.
$sqlPdo=new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
$sqlPdo->exec("CREATE TABLE projects(id INTEGER PRIMARY KEY,project_key TEXT COLLATE NOCASE,display_name TEXT,is_active INTEGER);
CREATE TABLE manager_projects(manager_id INTEGER,project_id INTEGER);
CREATE TABLE managers(id INTEGER PRIMARY KEY,login TEXT,display_name TEXT,role TEXT,email TEXT,is_active INTEGER,is_working INTEGER);
INSERT INTO projects VALUES(1,'anytour','Fixture',1),(2,'inactive','Inactive',0);
INSERT INTO manager_projects VALUES(7,1),(7,2);
INSERT INTO managers VALUES(7,'fixture','Fixture','admin','',1,1);");
(new ReflectionProperty(ConversationDb::class,'pdo'))->setValue(null,$sqlPdo);
$sqlRows=[batchAccessRow(1,['project_key'=>'AnyTour']),batchAccessRow(2,['project_key'=>'inactive']),batchAccessRow(3,['project_key'=>'other'])];
batchAccessCheck('real NOCASE singleton permits historical casing',ManagerConversationAccessPolicy::canView(7,$sqlRows[0]));
batchAccessCheck('real SQL batch fallback preserves casing and active-project access',ManagerConversationAccessPolicy::visibleRows(7,$sqlRows)===[$sqlRows[0]]);
$sqlPdo->exec('DELETE FROM manager_projects WHERE project_id=1');
batchAccessCheck('real SQL fallback cannot reuse a revoked permission',ManagerConversationAccessPolicy::visibleRows(7,$sqlRows)===[]);

echo 'TOTAL '.($passed+$failed)." | PASS {$passed} | FAIL {$failed}\n";
exit($failed?1:0);
