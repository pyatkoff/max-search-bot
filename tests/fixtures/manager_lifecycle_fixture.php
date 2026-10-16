<?php
declare(strict_types=1);

// Synthetic data only. SQLite strips FOR UPDATE for sequential behavior tests;
// real locking is exercised separately by the isolated native MySQL fixture.
require_once dirname(__DIR__,2).'/services/ManagerConversationService.php';

final class LifecycleFixturePdo extends PDO
{
    public array $statements=[];
    public function __construct(string $dsn)
    {
        parent::__construct($dsn,null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
        $this->sqliteCreateFunction('NOW',static fn()=>gmdate('Y-m-d H:i:s'));
    }
    public function prepare(string $query,array $options=[]): PDOStatement|false
    {
        $this->statements[]=$query;
        return parent::prepare(str_replace(' FOR UPDATE','',$query),$options);
    }
}

// Do not load the real provider class/config in any fixture process.
if(!class_exists('MaxSearchApi',false)){
    class MaxSearchApi
    {
        public static string $statusAi='synthetic-ai';
        public static array $calls=[];
        public static bool $fail=false;
        public static function setStatus($chat,$status): void {self::$calls[]=['setStatus',$chat,$status];if(self::$fail)throw new RuntimeException('Synthetic provider unavailable');}
        public static function deleteAllStatus($chat): void {self::$calls[]=['deleteAllStatus',$chat];if(self::$fail)throw new RuntimeException('Synthetic provider unavailable');}
    }
}

function lifecycleFixtureBind(PDO $pdo): void
{
    (new ReflectionProperty(ConversationDb::class,'pdo'))->setValue(null,$pdo);
    foreach([ProjectAccessService::class,ManagerAuthService::class,RoutingAccessService::class] as $class){
        (new ReflectionProperty($class,'schemaReady'))->setValue(null,true);
    }
}

function lifecycleFixtureSchema(PDO $pdo,bool $mysql=false): void
{
    $id=$mysql?'BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY':'INTEGER PRIMARY KEY AUTOINCREMENT';
    $suffix=$mysql?' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4':'';
    $tables=[
        'projects'=>'id INTEGER PRIMARY KEY,project_key VARCHAR(64),display_name VARCHAR(100),is_active INTEGER',
        'manager_projects'=>'manager_id INTEGER,project_id INTEGER',
        'managers'=>'id INTEGER PRIMARY KEY,login VARCHAR(64),display_name VARCHAR(100),role VARCHAR(32),email VARCHAR(100),is_active INTEGER,is_working INTEGER,fixture_marker VARCHAR(64)',
        'conversation_sources'=>'id INTEGER PRIMARY KEY,primary_group_id INTEGER,fallback_mode VARCHAR(32),fallback_group_id INTEGER,fallback_after_minutes INTEGER,is_active INTEGER',
        'manager_group_members'=>'manager_id INTEGER,group_id INTEGER',
        'conversations'=>'id INTEGER PRIMARY KEY,project_key VARCHAR(64),source_id INTEGER,status VARCHAR(32),manager_id INTEGER NULL,external_chat_id VARCHAR(64),started_at DATETIME,last_message_at DATETIME,closed_at DATETIME NULL',
        'manager_assignments'=>"id {$id},conversation_id INTEGER,manager_id INTEGER,assignment_type VARCHAR(32),released_at DATETIME NULL",
        'conversation_events'=>"id {$id},conversation_id INTEGER,event_type VARCHAR(64),actor_type VARCHAR(32),actor_id VARCHAR(32),payload_json TEXT NULL,created_at DATETIME NULL",
        // Sentinels prove lifecycle/state checks do not read/mark/modify messages.
        'messages'=>'id INTEGER PRIMARY KEY,conversation_id INTEGER,text TEXT',
        'manager_conversation_reads'=>'manager_id INTEGER,conversation_id INTEGER,last_read_message_id INTEGER',
    ];
    foreach($tables as $table=>$definition)$pdo->exec('CREATE TABLE '.$table.' ('.$definition.')'.$suffix);
}

function lifecycleFixtureSeed(PDO $pdo): void
{
    foreach(['manager_assignments','conversation_events','conversations','manager_projects','projects','managers','conversation_sources','manager_group_members','messages','manager_conversation_reads'] as $table)$pdo->exec('DELETE FROM '.$table);
    $pdo->exec("INSERT INTO projects VALUES(1,'anytour','Synthetic lifecycle',1),(2,'other','Private fixture',1)");
    $pdo->exec("INSERT INTO managers VALUES(1,'one','One','manager','',1,1,'manager-lifecycle-native-fixture'),(2,'two','Two','manager','',1,1,'manager-lifecycle-native-fixture'),(3,'admin','Admin','admin','',1,0,'manager-lifecycle-native-fixture'),(4,'off','Off','manager','',1,0,'manager-lifecycle-native-fixture'),(5,'outside','Outside','admin','',1,1,'manager-lifecycle-native-fixture'),(6,'inactive','Inactive','manager','',0,1,'manager-lifecycle-native-fixture')");
    $pdo->exec('INSERT INTO manager_projects VALUES(1,1),(2,1),(3,1),(4,1),(5,2)');
    $pdo->exec("INSERT INTO conversation_sources VALUES(1,90,'none',NULL,0,1)");
    $insert=$pdo->prepare('INSERT INTO conversations VALUES(?,?,?,?,?,?,?,?,?)');
    foreach([[11,'waiting_manager',null],[12,'manager',2],[13,'closed',2],[14,'ai',1],[15,'ai',null],[16,'manager',1],[17,'waiting_manager',null],[18,'manager',1],[19,'custom',null],[21,'manager',1],[22,'closed',1]] as [$id,$status,$owner]){
        $insert->execute([$id,$id===18?'other':'anytour',$id===17?1:0,$status,$owner,'synthetic-'.$id,'2026-10-16 08:00:00','2026-10-16 08:01:00',$status==='closed'?'2026-10-16 09:00:00':null]);
        if($owner!==null&&$status==='manager')$pdo->prepare('INSERT INTO manager_assignments(conversation_id,manager_id,assignment_type) VALUES(?,?,?)')->execute([$id,$owner,'synthetic']);
    }
    $pdo->exec("INSERT INTO messages VALUES(1,16,'Synthetic untouched original message')");
    $pdo->exec('INSERT INTO manager_conversation_reads VALUES(1,16,0)');
    MaxSearchApi::$calls=[];MaxSearchApi::$fail=false;
    if($pdo instanceof LifecycleFixturePdo)$pdo->statements=[];
}

function lifecycleFixtureSnapshot(PDO $pdo,array $tables=['conversations','manager_assignments','conversation_events','managers','manager_projects','messages','manager_conversation_reads']): array
{
    $result=[];foreach($tables as $table)$result[$table]=$pdo->query('SELECT * FROM '.$table.' ORDER BY 1,2')->fetchAll();return $result;
}
