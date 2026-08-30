<?php

declare(strict_types=1);

$root=dirname(__DIR__);
$migration=(string)file_get_contents($root.'/migrations/020_backfill_active_admin_project_access.sql');
$directory=(string)file_get_contents($root.'/services/AdminDirectoryService.php');

$passed=0;$failed=0;
function apaCheck(string $name,bool $ok):void{global$passed,$failed;if($ok){echo "PASS  {$name}\n";$passed++;return;}echo "FAIL  {$name}\n";$failed++;}

apaCheck('backfill is additive and idempotent',strpos($migration,'INSERT IGNORE INTO manager_projects')!==false);
apaCheck('backfill covers every active admin',strpos($migration,"m.is_active = 1")!==false&&strpos($migration,"m.role = 'admin'")!==false);
apaCheck('backfill grants every active project',strpos($migration,'CROSS JOIN projects p')!==false&&strpos($migration,'p.is_active = 1')!==false);
apaCheck('project creation keeps future active-admin access synchronized',strpos($directory,"INSERT IGNORE INTO manager_projects (manager_id,project_id) SELECT id,? FROM managers WHERE is_active=1 AND role='admin'")!==false);

echo "\n--------------------------\nTOTAL ".($passed+$failed)." | PASS {$passed} | FAIL {$failed}\n";
exit($failed?1:0);
