<?php
declare(strict_types=1);
require_once dirname(__DIR__,2).'/services/ManagerConversationAccessPolicy.php';

final class ReadonlyStatement extends PDOStatement {
    private $fixture;
    public function __construct(private string $sql, private ReadonlyPdo $database) {}
    public function execute(?array $params = null): bool {
        $this->database->executed[] = [$this->sql,$params];
        if (!preg_match('/^SELECT\\b/', $this->sql)) throw new RuntimeException('write_forbidden');
        if (str_contains($this->sql,'SELECT id,display_name,is_active FROM projects')) $this->fixture=$this->database->project;
        elseif(str_contains($this->sql,'FROM managers WHERE id')) $this->fixture=$this->database->manager;
        elseif(str_contains($this->sql,'FROM projects p JOIN manager_projects')) $this->fixture=$this->database->projectAllowed ? [['id'=>1,'project_key'=>ProjectConfig::projectId(),'display_name'=>'fixture']] : [];
        elseif(str_contains($this->sql,'SELECT 1 FROM manager_projects')) $this->fixture=$this->database->projectAllowed ? 1 : false;
        else throw new RuntimeException('unexpected_query');
        return true;
    }
    public function fetch(int $mode=PDO::FETCH_DEFAULT,int $orientation=PDO::FETCH_ORI_NEXT,int $offset=0): mixed { return $this->fixture; }
    public function fetchAll(int $mode=PDO::FETCH_DEFAULT,mixed ...$args): array { return is_array($this->fixture)?$this->fixture:[]; }
    public function fetchColumn(int $column=0): mixed { return $this->fixture; }
}
final class ReadonlyPdo extends PDO {
    public array $executed=[];
    public mixed $project;
    public array $manager=['id'=>7,'role'=>'manager','is_active'=>1,'is_working'=>1];
    public bool $projectAllowed=true;
    public function __construct() { $this->project=['id'=>1,'display_name'=>(string)ProjectConfig::get('brand.name',ProjectConfig::projectId()),'is_active'=>1]; }
    public function prepare(string $query,array $options=[]): PDOStatement|false { return new ReadonlyStatement($query,$this); }
}
$count=0;
function checkRo(string $name,bool $ok):void {global$count;if(!$ok)throw new RuntimeException($name);$count++;echo "PASS $name\n";}
function resetRo(ReadonlyPdo $pdo):void {
    (new ReflectionProperty(ConversationDb::class,'pdo'))->setValue(null,$pdo);
    foreach([ProjectAccessService::class,ManagerAuthService::class,RoutingAccessService::class] as $class) (new ReflectionProperty($class,'schemaReady'))->setValue(null,false);
}
$legacy=new ReadonlyPdo();resetRo($legacy);$legacyBlocked=false;
try { ManagerAuthService::byId(7); } catch(RuntimeException $e) { $legacyBlocked=$e->getMessage()==='write_forbidden'; }
checkRo('unmodified auth bootstrap reproduces forbidden project UPSERT',$legacyBlocked);
checkRo('legacy attempt is a project insert not a permission read',str_starts_with($legacy->executed[0][0]??'','INSERT INTO projects'));
$pdo=new ReadonlyPdo();resetRo($pdo);
ProjectAccessService::initializeReadOnly();
checkRo('initializer executes exactly one SELECT',count($pdo->executed)===1);
ProjectAccessService::ensureSchema();
checkRo('normal bootstrap recognizes verified existing postcondition',count($pdo->executed)===1);
$manager=ManagerAuthService::byId(7);
checkRo('actual manager auth still requires active identity',$manager!==null&&$manager['id']===7);
checkRo('actual project scopes still returned',count($manager['projects'])===1);
checkRo('canonical policy allows existing authorized project',ManagerConversationAccessPolicy::canView(7,['project_key'=>ProjectConfig::projectId(),'source_id'=>0,'status'=>'manager']));
$pdo->projectAllowed=false;
checkRo('canonical policy still denies revoked project access',!ManagerConversationAccessPolicy::canView(7,['project_key'=>ProjectConfig::projectId(),'source_id'=>0,'status'=>'manager']));
$pdo->projectAllowed=true;$pdo->manager['is_active']=0;
checkRo('disabled manager still fails actual authentication',ManagerAuthService::byId(7)===null);
checkRo('no writes through complete bootstrap/auth/project chain',!array_filter($pdo->executed,static fn($r)=>!str_starts_with($r[0],'SELECT')));
foreach([false,['id'=>0,'display_name'=>'fixture','is_active'=>1],['id'=>1,'display_name'=>'fixture','is_active'=>0],['id'=>1,'display_name'=>'wrong-name','is_active'=>1]] as $case){
    $db=new ReadonlyPdo();$db->project=$case;resetRo($db);$failed=false;
    try{ProjectAccessService::initializeReadOnly();}catch(RuntimeException $e){$failed=$e->getMessage()==='read_only_project_not_ready';}
    checkRo('missing/inactive/drifted project fails without repair',$failed);
    checkRo('failed initialization does not mark schema ready',!(new ReflectionProperty(ProjectAccessService::class,'schemaReady'))->getValue());
    checkRo('failed initialization made only one SELECT',count($db->executed)===1);
}
$endpoint=file_get_contents(dirname(__DIR__,2).'/manager/notification-events.php');
checkRo('DB enforces read-only transaction',str_contains($endpoint,"SET TRANSACTION READ ONLY")&&str_contains($endpoint,'$pdo->beginTransaction()'));
checkRo('initializer precedes active manager auth',strpos($endpoint,'ProjectAccessService::initializeReadOnly()')<strpos($endpoint,'    ManagerHttp::requireManager()'));
checkRo('transaction is rolled back before successful response',strpos($endpoint,'$pdo->rollBack();',strpos($endpoint,'$result ='))<strpos($endpoint,"ManagerHttp::respond(['ok'=>true]"));
echo "TOTAL $count passed\n";
