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
maaCheck('admin forms expose accessible inline status regions',strpos($admin,'id="projectFormStatus"')!==false&&strpos($admin,'id="managerFormStatus"')!==false&&strpos($admin,'id="ruleFormStatus"')!==false&&substr_count($admin,'role="status"')>=3&&substr_count($admin,'aria-live="polite"')>=3);
maaCheck('project save is duplicate guarded and recoverable',strpos($js,"if($('saveProject').disabled)return")!==false&&strpos($js,"withBusyButton('saveProject',true)")!==false&&strpos($js,"withBusyButton('saveProject',false)")!==false&&strpos($js,"setFormStatus('projectFormStatus'")!==false);
maaCheck('successful project save resets stale edit state to new-project defaults',strpos($js,'function resetProjectForm()')!==false&&strpos($js,"$('projectActive').checked=true")!==false&&strpos($js,'if(j.ok){resetProjectForm();')!==false);
maaCheck('successful manager save resets role active priority and project selection defaults',strpos($js,'function resetManagerForm()')!==false&&strpos($js,"$('managerRole').value='manager'")!==false&&strpos($js,"$('managerActive').checked=true")!==false&&strpos($js,"$('managerPriority').value=0")!==false&&strpos($js,"document.querySelectorAll('#managerProjects input').forEach(x=>x.checked=false)")!==false&&strpos($js,'if(j.ok){resetManagerForm();')!==false);
maaCheck('priority rule save is availability and duplicate guarded and recoverable',strpos($js,"if(!S.priorityAvailable||$('saveRule').disabled)return")!==false&&strpos($js,"withBusyButton('saveRule',true)")!==false&&strpos($js,"withBusyButton('saveRule',false)")!==false&&strpos($js,"setFormStatus('ruleFormStatus'")!==false);
maaCheck('successful priority rule save resets stale target and rule type defaults',strpos($js,'function resetRuleForm()')!==false&&strpos($js,"$('ruleId').value=''")!==false&&strpos($js,"manager.selectedIndex=0")!==false&&strpos($js,"type.selectedIndex=0")!==false&&strpos($js,'if(j.ok){resetRuleForm();')!==false);
maaCheck('admin project and rule failures preserve forms without alert fallbacks',strpos($js,"alert(errorText(j,'Не удалось сохранить проект')")===false&&strpos($js,"alert('Не удалось сохранить правило')")===false&&strpos($js,"Данные формы сохранены")!==false);
maaCheck('shared admin form feedback has success error and busy styles',strpos($css,'.formStatus')!==false&&strpos($css,'.formStatus.success')!==false&&strpos($css,'.formStatus.error')!==false&&strpos($css,'.btn:disabled')!==false);
maaCheck('new active projects grant access to every active admin',strpos($directory,'if($active)self::grantProjectToActiveAdmins($pdo,$id);')!==false&&strpos($directory,"WHERE role='admin' AND is_active=1")!==false);
maaCheck('project creation and admin ACL update are atomic',strpos($directory,'public static function saveProject')!==false&&strpos($directory,'$pdo->beginTransaction();')!==false&&strpos($directory,'if($pdo->inTransaction())$pdo->rollBack();')!==false);
maaCheck('existing active admins are backfilled to every active project',strpos($accessBackfill,'INSERT IGNORE INTO manager_projects')!==false&&strpos($accessBackfill,'CROSS JOIN projects p')!==false&&strpos($accessBackfill,"m.role = 'admin'")!==false&&strpos($accessBackfill,'m.is_active = 1')!==false&&strpos($accessBackfill,'p.is_active = 1')!==false);
maaCheck('manager save validates every submitted project before mutation',strpos($directory,"SELECT id FROM projects WHERE id IN (")!==false&&strpos($directory,"'error'=>'invalid_project_selection'")!==false&&strpos($directory,'$existingProjectIds!==$submittedProjectIds')!==false);
maaCheck('manager project links no longer silently skip stale ids',strpos($directory,'foreach($projectIds as $projectId)$ins->execute([$id,$projectId]);')!==false&&strpos($directory,'if(self::projectExists($projectId))$ins->execute')===false);
maaCheck('manager save classifies duplicate login separately from generic database failure',strpos($directory,'self::isDuplicateKeyError($e)')!==false&&strpos($directory,"?'duplicate_login':'manager_save_failed'")!==false);
maaCheck('manager duplicate classifier checks SQLSTATE integrity and MySQL duplicate code',strpos($directory,'(string)($info[0]??\'\')===\'23000\'')!==false&&strpos($directory,'(int)($info[1]??0)===1062')!==false);

echo "\n--------------------------\nTOTAL ".($passed+$failed)." | PASS {$passed} | FAIL {$failed}\n";
exit($failed?1:0);
