<?php
declare(strict_types=1);

require_once __DIR__.'/../services/AdminDirectoryService.php';
require_once __DIR__.'/../services/RoutingAccessService.php';

foreach(['HOST','NAME','USER','PASS'] as $key)define('CONVERSATION_DB_'.$key,'isolated-sqlite-fixture');
ProjectConfig::resetForTests(['id'=>'anytour','brand'=>['name'=>'AnyTour']]);
$pdo=new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE,PDO::FETCH_ASSOC);
$pdo->exec('PRAGMA foreign_keys=ON');
$pdo->sqliteCreateFunction('DATABASE',static fn()=>'fixture');
(new ReflectionProperty(ConversationDb::class,'pdo'))->setValue(null,$pdo);

// Keep the migrated ownership keys: sources and groups refer to projects.id,
// while projects alone owns project_key. No query or service SQL is rewritten.
$pdo->exec("CREATE TABLE projects(id INTEGER PRIMARY KEY,project_key TEXT NOT NULL UNIQUE,display_name TEXT NOT NULL,is_active INTEGER NOT NULL DEFAULT 1);
CREATE TABLE managers(id INTEGER PRIMARY KEY,login TEXT NOT NULL UNIQUE,display_name TEXT,role TEXT NOT NULL DEFAULT 'manager',email TEXT,is_active INTEGER NOT NULL DEFAULT 1,is_working INTEGER NOT NULL DEFAULT 0,last_login_at TEXT,priority INTEGER NOT NULL DEFAULT 0);
CREATE TABLE manager_projects(manager_id INTEGER NOT NULL REFERENCES managers(id),project_id INTEGER NOT NULL REFERENCES projects(id),PRIMARY KEY(manager_id,project_id));
CREATE TABLE manager_groups(id INTEGER PRIMARY KEY,project_id INTEGER NOT NULL REFERENCES projects(id),group_key TEXT NOT NULL,display_name TEXT NOT NULL,is_active INTEGER NOT NULL DEFAULT 1,UNIQUE(project_id,group_key));
CREATE TABLE manager_group_members(group_id INTEGER NOT NULL REFERENCES manager_groups(id),manager_id INTEGER NOT NULL REFERENCES managers(id),PRIMARY KEY(group_id,manager_id));
CREATE TABLE conversation_sources(id INTEGER PRIMARY KEY,project_id INTEGER NOT NULL REFERENCES projects(id),source_key TEXT NOT NULL,display_name TEXT NOT NULL,channel TEXT,primary_group_id INTEGER REFERENCES manager_groups(id),fallback_mode TEXT NOT NULL DEFAULT 'immediate',fallback_group_id INTEGER REFERENCES manager_groups(id),fallback_after_minutes INTEGER NOT NULL DEFAULT 0,is_active INTEGER NOT NULL DEFAULT 1,UNIQUE(project_id,source_key));
CREATE TABLE manager_push_subscriptions(id INTEGER PRIMARY KEY,manager_id INTEGER NOT NULL REFERENCES managers(id),last_success_at TEXT,last_error_at TEXT,last_error TEXT);
CREATE TABLE admin_audit_log(id INTEGER PRIMARY KEY,actor_manager_id INTEGER REFERENCES managers(id),action TEXT,entity_type TEXT,entity_id TEXT,project_key TEXT,before_json TEXT,after_json TEXT,created_at TEXT);
ATTACH DATABASE ':memory:' AS information_schema;
CREATE TABLE information_schema.tables(table_schema TEXT,table_name TEXT);
INSERT INTO information_schema.tables VALUES('fixture','managers'),('fixture','manager_push_subscriptions');
INSERT INTO projects VALUES(1,'anytour','AnyTour',1),(2,'beta','Beta',1),(3,'dormant','Dormant',0),(4,'empty','Empty',1);
INSERT INTO managers VALUES(5,'admin','Admin','admin','',1,0,NULL,90),(6,'inactive','Inactive','manager','',0,1,NULL,10),(7,'primary','Primary','manager','',1,1,NULL,3),(8,'fallback','Fallback','manager','',1,1,NULL,2),(9,'other','Other','manager','',1,1,NULL,1),(10,'offshift','Off shift','manager','',1,0,NULL,0);
INSERT INTO manager_projects VALUES(5,1),(5,2),(5,3),(5,4),(7,1),(8,1),(9,2),(10,1);
INSERT INTO manager_groups VALUES(101,1,'primary','Primary group',1),(102,1,'fallback','Fallback group',1),(201,2,'other','Beta group',1);
INSERT INTO manager_group_members VALUES(101,7),(102,8),(201,9),(101,10);
INSERT INTO conversation_sources VALUES(11,1,'shared','Zeta','max',101,'delayed',102,5,1),(12,2,'shared','Alpha','telegram',201,'none',NULL,0,1),(13,1,'inactive','Alpha',NULL,NULL,'none',NULL,0,0),(14,3,'historical','Hidden','max',NULL,'none',NULL,0,1),(15,1,'immediate','Alpha','website',101,'immediate',102,0,1);
INSERT INTO manager_push_subscriptions VALUES(1,7,'2026-10-14 00:00:00',NULL,NULL),(2,8,NULL,'2026-10-14 00:00:00','provider failed');
INSERT INTO admin_audit_log VALUES(1,5,'source_updated','source','11','anytour','{\"secret\":\"before-only\"}','{\"secret\":\"after-only\"}','2026-10-14 00:00:00');");

// Initialize the existing read-only contract once so the test does not invoke
// the legacy project UPSERT while exercising the real snapshot and policies.
ProjectAccessService::initializeReadOnly();
ManagerAuthService::ensureSchema();

$passed=0;$failed=0;
function snapshotCheck(string $name,$actual,$expected):void{
    global $passed,$failed;
    if($actual===$expected){$passed++;echo "PASS {$name}\n";return;}
    $failed++;echo "FAIL {$name}\n";
    echo '  expected '.json_encode($expected,JSON_UNESCAPED_UNICODE).' actual '.json_encode($actual,JSON_UNESCAPED_UNICODE)."\n";
}
function directoryRows(PDO $pdo):array{
    $out=[];
    foreach(['projects','managers','manager_projects','manager_groups','manager_group_members','conversation_sources','manager_push_subscriptions','admin_audit_log'] as $table)$out[$table]=$pdo->query('SELECT * FROM '.$table.' ORDER BY rowid')->fetchAll();
    return $out;
}
function routingChecks():array{
    $row=['project_key'=>'anytour','source_id'=>11,'status'=>'waiting_manager'];
    $recent=$row+['waiting_since'=>gmdate('Y-m-d H:i:s',time()-60)];
    $old=$row+['waiting_since'=>gmdate('Y-m-d H:i:s',time()-600)];
    return [
        'primary'=>RoutingAccessService::canSeeConversation(7,$recent),
        'fallback_before'=>RoutingAccessService::canSeeConversation(8,$recent),
        'fallback_after'=>RoutingAccessService::canSeeConversation(8,$old),
        'wrong_project'=>RoutingAccessService::canSeeConversation(9,$old),
        'off_shift'=>RoutingAccessService::canSeeConversation(10,$old),
        'admin'=>RoutingAccessService::canSeeConversation(5,$recent),
        'immediate_fallback'=>RoutingAccessService::canSeeConversation(8,array_replace($recent,['source_id'=>15])),
    ];
}

$sourceColumns=array_column($pdo->query('PRAGMA table_info(conversation_sources)')->fetchAll(),'name');
snapshotCheck('canonical source ownership is project_id, never an invented project_key',[in_array('project_id',$sourceColumns,true),in_array('project_key',$sourceColumns,true)],[true,false]);
snapshotCheck('fixture follows the canonical migration source foreign key',array_column($pdo->query('PRAGMA foreign_key_list(conversation_sources)')->fetchAll(),'from'),['fallback_group_id','primary_group_id','project_id']);
$before=directoryRows($pdo);$routingBefore=routingChecks();
snapshotCheck('actual current role/group/fallback policy is exercised',$routingBefore,['primary'=>true,'fallback_before'=>false,'fallback_after'=>true,'wrong_project'=>false,'off_shift'=>false,'admin'=>true,'immediate_fallback'=>true]);

try{$snapshot=AdminDirectoryService::snapshot();}
catch(Throwable $e){snapshotCheck('actual admin snapshot executes against the canonical migrated schema',get_class($e).': '.$e->getMessage(),'success');echo "TOTAL ".($passed+$failed)." | PASS {$passed} | FAIL {$failed}\n";exit(1);}
snapshotCheck('actual admin snapshot includes the same top-level contract',array_keys($snapshot),['projects','managers','sources','audit']);
snapshotCheck('source rows keep deterministic project/name/id ordering',array_column($snapshot['sources'],'id'),[13,15,11,12,14]);
$sources=[];foreach($snapshot['sources'] as $source)$sources[(int)$source['id']]=$source;
snapshotCheck('same source key in two projects resolves by project_id',[$sources[11]['project_key'],$sources[12]['project_key']],['anytour','beta']);
snapshotCheck('inactive source remains present and unchanged',$sources[13],['id'=>13,'project_key'=>'anytour','source_key'=>'inactive','display_name'=>'Alpha','channel'=>null,'is_active'=>0]);
snapshotCheck('inactive project source remains available to administrator',$sources[14]['project_key'],'dormant');
snapshotCheck('projects without sources remain in the projects directory',array_column($snapshot['projects'],'project_key'),['anytour','beta','dormant','empty']);
snapshotCheck('source projection keeps its existing six public fields',array_keys($sources[11]),['id','project_key','source_key','display_name','channel','is_active']);
$managers=[];foreach($snapshot['managers'] as $manager)$managers[(int)$manager['id']]=$manager;
snapshotCheck('admin role and project links are retained',[$managers[5]['role'],$managers[5]['project_ids'],$managers[5]['is_working']],['admin',[1,2,3,4],false]);
snapshotCheck('inactive manager is retained without role/shift normalization',[$managers[6]['is_active'],$managers[6]['role'],$managers[6]['is_working']],[0,'manager',true]);
snapshotCheck('healthy push signal is independent from manager role',[$managers[7]['role'],$managers[7]['is_reachable'],$managers[7]['reachability_reason']],['manager',true,'healthy_subscription']);
snapshotCheck('unhealthy push keeps working state truthful',[$managers[8]['is_working'],$managers[8]['is_reachable'],$managers[8]['reachability_reason']],[true,false,'subscription_unhealthy']);
snapshotCheck('existing audit summary is preserved',[$snapshot['audit'][0]['actor_name'],$snapshot['audit'][0]['entity_id'],$snapshot['audit'][0]['project_key']],['Admin','11','anytour']);
snapshotCheck('snapshot does not leak audit before/after payloads',strpos(json_encode($snapshot),'before-only')===false&&strpos(json_encode($snapshot),'after-only')===false,true);
snapshotCheck('directory snapshot does not change any data or permissions',directoryRows($pdo),$before);
snapshotCheck('role/group/fallback results remain identical after snapshot',routingChecks(),$routingBefore);

$pdo->exec('DELETE FROM conversation_sources');
$empty=AdminDirectoryService::snapshot();
snapshotCheck('no sources is a valid empty result, not a failed directory',$empty['sources'],[]);
snapshotCheck('empty source list does not discard projects or managers',[count($empty['projects']),count($empty['managers'])],[4,6]);
echo "TOTAL ".($passed+$failed)." | PASS {$passed} | FAIL {$failed}\n";
exit($failed?1:0);
