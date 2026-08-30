<?php

declare(strict_types=1);

$root=dirname(__DIR__);
require_once $root.'/services/AdminProjectAccessHealth.php';

$passed=0;$failed=0;
function apahCheck(string $name,bool $ok):void{global$passed,$failed;if($ok){echo "PASS  {$name}\n";$passed++;return;}echo "FAIL  {$name}\n";$failed++;}

$pdo=new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE,PDO::FETCH_ASSOC);
$pdo->exec('CREATE TABLE managers (id INTEGER PRIMARY KEY, login TEXT, role TEXT, is_active INTEGER)');
$pdo->exec('CREATE TABLE projects (id INTEGER PRIMARY KEY, project_key TEXT, is_active INTEGER)');
$pdo->exec('CREATE TABLE manager_projects (manager_id INTEGER, project_id INTEGER, PRIMARY KEY(manager_id,project_id))');
$pdo->exec("INSERT INTO managers VALUES (1,'admin-a','admin',1),(2,'admin-b','admin',1),(3,'manager-a','manager',1),(4,'admin-off','admin',0)");
$pdo->exec("INSERT INTO projects VALUES (10,'p1',1),(20,'p2',1),(30,'p-off',0)");
$pdo->exec('INSERT INTO manager_projects VALUES (1,10),(1,20),(2,10),(3,10),(4,10),(4,20)');

$bad=AdminProjectAccessHealth::collect($pdo);
apahCheck('health detects one missing active-admin active-project link',$bad['ok']===false&&(int)$bad['missing_count']===1);
apahCheck('health ignores ordinary managers inactive admins and inactive projects',(int)$bad['active_admins']===2&&(int)$bad['active_projects']===2&&(int)$bad['expected_links']===4);
apahCheck('health reports bounded missing identity',count($bad['missing'])===1&&(int)$bad['missing'][0]['manager_id']===2&&(int)$bad['missing'][0]['project_id']===20);

$pdo->exec('INSERT INTO manager_projects VALUES (2,20)');
$good=AdminProjectAccessHealth::collect($pdo);
apahCheck('health becomes green when every active admin has every active project',$good['ok']===true&&(int)$good['missing_count']===0&&$good['missing']===[]);

$snapshot=(string)file_get_contents($root.'/tools/production_snapshot.php');
apahCheck('production snapshot exposes admin project access health',strpos($snapshot,"AdminProjectAccessHealth.php")!==false&&strpos($snapshot,"'admin_project_access_health'")!==false);
apahCheck('production snapshot publishes admin project access gate',strpos($snapshot,"'admin_project_access_ok'=>false")!==false&&strpos($snapshot,"$snapshot['health']['admin_project_access_ok']=$adminProjectAccessHealth['ok'];")!==false);

echo "\n--------------------------\nTOTAL ".($passed+$failed)." | PASS {$passed} | FAIL {$failed}\n";
exit($failed?1:0);
