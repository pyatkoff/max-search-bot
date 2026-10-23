<?php
declare(strict_types=1);
require_once dirname(__DIR__,2).'/services/ManagerSendAttemptService.php';
function sendAttemptBind(PDO $pdo): void
{
    (new ReflectionProperty(ConversationDb::class,'pdo'))->setValue(null,$pdo);
    foreach([ProjectAccessService::class,ManagerAuthService::class,RoutingAccessService::class] as $class)(new ReflectionProperty($class,'schemaReady'))->setValue(null,true);
}
function sendAttemptSchema(PDO $pdo,bool $mysql=false): void
{
    $tables=[
        'projects'=>'id INTEGER PRIMARY KEY,project_key VARCHAR(64),display_name VARCHAR(100),is_active INTEGER',
        'manager_projects'=>'manager_id INTEGER,project_id INTEGER',
        'managers'=>'id INTEGER PRIMARY KEY,login VARCHAR(64),display_name VARCHAR(100),role VARCHAR(32),email VARCHAR(100),is_active INTEGER,is_working INTEGER',
        'customers'=>'id INTEGER PRIMARY KEY,display_name VARCHAR(100),phone VARCHAR(50),email VARCHAR(100)',
        'manager_customer_names'=>'customer_id INTEGER PRIMARY KEY,working_name VARCHAR(100),version INTEGER',
        'conversations'=>'id INTEGER PRIMARY KEY,customer_id INTEGER,project_key VARCHAR(64),source_id INTEGER,channel VARCHAR(20),entry_channel VARCHAR(20),attribution_region VARCHAR(64),attribution_campaign VARCHAR(64),status VARCHAR(32),lead_stage_key VARCHAR(64),manager_id INTEGER,started_at DATETIME,last_message_at DATETIME,closed_at DATETIME NULL,external_chat_id VARCHAR(64)',
        'conversation_sources'=>'id INTEGER PRIMARY KEY,display_name VARCHAR(100),primary_group_id INTEGER,fallback_mode VARCHAR(32),fallback_group_id INTEGER,fallback_after_minutes INTEGER,is_active INTEGER',
        'manager_group_members'=>'manager_id INTEGER,group_id INTEGER',
        'messages'=>'id INTEGER PRIMARY KEY,conversation_id INTEGER,text TEXT',
        'manager_conversation_reads'=>'manager_id INTEGER,conversation_id INTEGER,last_read_message_id INTEGER',
        'provider_calls'=>'operation_id VARCHAR(32)',
    ];
    foreach($tables as $table=>$definition)$pdo->exec('CREATE TABLE '.$table.' ('.$definition.')'.($mysql?' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4':''));
    if($mysql)$pdo->exec((string)file_get_contents(dirname(__DIR__,2).'/migrations/059_manager_send_attempts.sql'));
    else $pdo->exec('CREATE TABLE manager_send_attempts(manager_id INTEGER,operation_id TEXT,conversation_id INTEGER,context_hash TEXT,request_hash TEXT,state TEXT,result_json TEXT,created_at TEXT,updated_at TEXT,PRIMARY KEY(manager_id,operation_id))');
    $pdo->exec("INSERT INTO projects VALUES(1,'anytour','Synthetic send fixture',1),(2,'other','Other',1);
INSERT INTO managers VALUES(7,'synthetic-owner','Owner','manager','',1,1),(8,'synthetic-colleague','Colleague','manager','',1,1),(9,'synthetic-admin','Admin','admin','',1,1),(10,'synthetic-inactive','Inactive','manager','',0,1);
INSERT INTO manager_projects VALUES(7,1),(8,1),(9,1),(10,1);
INSERT INTO customers VALUES(1,'Synthetic customer','','');
INSERT INTO conversation_sources VALUES(1,'Allowed',0,'none',0,0,1),(2,'Restricted',101,'none',0,0,1);
INSERT INTO conversations(id,customer_id,project_key,source_id,channel,status,manager_id,external_chat_id) VALUES(101,1,'anytour',1,'max','manager',7,'synthetic101'),(102,1,'anytour',1,'telegram','manager',7,'synthetic102'),(103,1,'anytour',1,'max','manager',8,'synthetic103'),(104,1,'other',1,'max','manager',7,'synthetic104'),(105,1,'anytour',2,'max','manager',7,'synthetic105');
INSERT INTO messages VALUES(1,101,'Untouched synthetic transcript');
INSERT INTO manager_conversation_reads VALUES(7,101,0)");
}
function sendAttemptProtected(PDO $pdo): array
{
    $state=[];foreach(['managers','manager_projects','conversations','messages','manager_conversation_reads'] as $table)$state[$table]=$pdo->query('SELECT * FROM '.$table.' ORDER BY 1,2')->fetchAll(PDO::FETCH_ASSOC);return $state;
}
