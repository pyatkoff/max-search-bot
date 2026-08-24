<?php

declare(strict_types=1);

$base=dirname(__DIR__);
$service=(string)file_get_contents($base.'/services/ManagerConversationService.php');
$api=(string)file_get_contents($base.'/manager/api.php');
$access=(string)file_get_contents($base.'/services/ProjectAccessService.php');
$availability=(string)file_get_contents($base.'/services/ManagerAvailabilityService.php');
$admin=(string)file_get_contents($base.'/services/AdminDirectoryService.php');
$routing=(string)file_get_contents($base.'/services/RoutingAdminService.php');
$diagnostics=(string)file_get_contents($base.'/.github/workflows/publish-conversation-diagnostics.yml');
$auditMigration=(string)file_get_contents($base.'/migrations/006_admin_audit_log.sql');

$passed=0;$failed=0;
function mvCheck(string $name,bool $ok):void{global $passed,$failed;if($ok){echo "PASS  {$name}\n";$passed++;return;}echo "FAIL  {$name}\n";$failed++;}

mvCheck('ordinary all queue includes unassigned plus own',strpos($service,"(c.manager_id IS NULL OR c.manager_id=?)")!==false);
mvCheck('own-only restriction is only for non-admin',strpos($service,"if(!\$isAdmin){\$where[]='(c.manager_id IS NULL OR c.manager_id=?)'")!==false);
mvCheck('admin manager filter is optional',strpos($service,"if(\$isAdmin && \$queue!=='mine')")!==false);
mvCheck('admin unassigned filter exists',strpos($service,"\$managerFilter==='unassigned'")!==false);
mvCheck('admin mine filter exists',strpos($service,"\$managerFilter==='mine'")!==false);
mvCheck('api forwards manager filter only for admin',strpos($api,"\$isAdmin?(string)(\$data['manager_filter']??''):''")!==false);
mvCheck('not-working fallback is explicitly guarded',strpos($api,"!\$isAdmin&&(\$queue==='waiting'||\$queue==='all')&&!ManagerAvailabilityService::isWorking")!==false);

mvCheck('runtime does not auto-promote first manager',strpos($access,"UPDATE managers SET role='admin'")===false);
mvCheck('runtime does not auto-attach managers without project',strpos($access,'mp.manager_id IS NULL')===false);
mvCheck('working status writes audit',strpos($availability,'AuditLogService::record')!==false);
mvCheck('manager and project admin writes audit',substr_count($admin,'AuditLogService::record')>=2);
mvCheck('routing writes audit',substr_count($routing,'AuditLogService::record')>=2);
mvCheck('audit table is versioned migration',strpos($auditMigration,'CREATE TABLE IF NOT EXISTS admin_audit_log')!==false);
mvCheck('production gate checks deployed sha',strpos($diagnostics,'production_sha_mismatch')!==false);
mvCheck('production gate checks migration health',strpos($diagnostics,'migration_health_failed')!==false);
mvCheck('production gate checks manager visibility',strpos($diagnostics,'manager_visibility_health_failed')!==false);

$total=$passed+$failed;
echo "\n--------------------------\nTOTAL {$total} | PASS {$passed} | FAIL {$failed}\n";
exit($failed?1:0);
