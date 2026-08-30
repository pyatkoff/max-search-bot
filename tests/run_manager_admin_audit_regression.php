<?php

declare(strict_types=1);

$root=dirname(__DIR__);
$audit=(string)file_get_contents($root.'/services/AuditLogService.php');
$directory=(string)file_get_contents($root.'/services/AdminDirectoryService.php');
$api=(string)file_get_contents($root.'/manager/api.php');
$admin=(string)file_get_contents($root.'/manager/admin.php');
$css=(string)file_get_contents($root.'/manager/assets/admin.css');
$js=(string)file_get_contents($root.'/manager/assets/admin.js');
$accessBackfill=(string)file_get_contents($root.'/migrations/020_backfill_active_admin_project_access.sql');

$passed=0;$failed=0;
function maaCheck(string $name,bool $ok):void{global $passed,$failed;if($ok){echo "PASS  {$name}\n";$passed++;return;}echo "FAIL  {$name}\n";$failed++;}

maaCheck('audit service exposes a bounded read-only admin projection',strpos($audit,'function recentSummaries')!==false&&strpos($audit,'min(100,$limit)')!==false&&strpos($audit,'ORDER BY a.id DESC LIMIT')!==false);
maaCheck('admin audit projection resolves actor identity',strpos($audit,'LEFT JOIN managers m ON m.id=a.actor_manager_id')!==false&&strpos($audit,'actor_name')!==false&&strpos($audit,'actor_login')!==false);
maaCheck('admin audit projection is data minimized',strpos($audit,'recentSummaries')!==false&&strpos($audit,'a.before_json')===false&&strpos($audit,'a.after_json')===false);
maaCheck('admin directory snapshot includes recent audit summaries',strpos($directory,"'audit'=>AuditLogService::recentSummaries(50)")!==false);
maaCheck('admin snapshot remains server-side role gated',strpos($api,"if(\$action==='admin_snapshot')")!==false&&strpos($api,'requireAdmin($m);')!==false);
maaCheck('admin UI renders the bounded audit projection',strpos($admin,'id="audit"')!==false&&strpos($js,'function renderAudit')!==false&&strpos($js,'S.data.audit||[]')!==false);
maaCheck('admin UI does not request raw audit payloads',strpos($js,'before_json')===false&&strpos($js,'after_json')===false&&strpos($js,"api('admin_snapshot')")!==false);
maaCheck('admin audit keeps actor action target and timestamp visible',strpos($js,'auditActor')!==false&&strpos($js,'auditAction')!==false&&strpos($js,'auditTarget')!==false&&strpos($js,'created_at')!==false);
maaCheck('admin styles are extracted from the php monolith',strpos($admin,'assets/admin.css')!==false&&strpos($admin,'<style>')===false&&strpos($css,'.auditRow')!==false);
maaCheck('admin javascript is extracted from the php monolith',strpos($admin,'assets/admin.js')!==false&&strpos($admin,'<script>')===false&&strpos($js,"api('me')")!==false&&strpos($js,"api('admin_snapshot')")!==false);
maaCheck('admin audit has responsive mobile layout',strpos($css,'@media(max-width:700px)')!==false&&strpos($css,'.auditRow{grid-template-columns:1fr')!==false);
maaCheck('new active projects grant access to every active admin',strpos($directory,'if($active)self::grantProjectToActiveAdmins($pdo,$id);')!==false&&strpos($directory,"WHERE role='admin' AND is_active=1")!==false);
maaCheck('project creation and admin ACL update are atomic',strpos($directory,'public static function saveProject')!==false&&strpos($directory,'$pdo->beginTransaction();')!==false&&strpos($directory,'if($pdo->inTransaction())$pdo->rollBack();')!==false);
maaCheck('existing active admins are backfilled to every active project',strpos($accessBackfill,'INSERT IGNORE INTO manager_projects')!==false&&strpos($accessBackfill,'CROSS JOIN projects p')!==false&&strpos($accessBackfill,"m.role = 'admin'")!==false&&strpos($accessBackfill,'m.is_active = 1')!==false&&strpos($accessBackfill,'p.is_active = 1')!==false);

echo "\n--------------------------\nTOTAL ".($passed+$failed)." | PASS {$passed} | FAIL {$failed}\n";
exit($failed?1:0);