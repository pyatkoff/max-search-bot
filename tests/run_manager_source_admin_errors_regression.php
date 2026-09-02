<?php

declare(strict_types=1);

$root=dirname(__DIR__);
$api=(string)file_get_contents($root.'/manager/api.php');
$routing=(string)file_get_contents($root.'/manager/routing.php');
$routingJs=(string)file_get_contents($root.'/manager/assets/routing.js');
$service=(string)file_get_contents($root.'/services/RoutingAdminService.php');
$directoryService=(string)file_get_contents($root.'/services/AdminDirectoryService.php');

$passed=0;$failed=0;
function sourceCheck(string $name,bool $ok):void{global $passed,$failed;if($ok){echo "PASS  {$name}\n";$passed++;return;}echo "FAIL  {$name}\n";$failed++;}

sourceCheck('manager API returns structured source service result',strpos($api,'RoutingAdminService::saveSourceResult(')!==false&&strpos($api,'$error=(string)($r[\'error\']??\'save_failed\')')!==false);
sourceCheck('manager API distinguishes validation conflict access and server failures',strpos($api,'$error===\'duplicate_source_key\'?409')!==false&&strpos($api,'$error===\'save_failed\'?500:422')!==false&&strpos($api,"['admin_required','project_access_denied']")!==false);
sourceCheck('source service keeps explicit validation errors',strpos($service,"'error'=>'missing_source_key'")!==false&&strpos($service,"'error'=>'invalid_primary_group'")!==false&&strpos($service,"'error'=>'fallback_group_required'")!==false);
sourceCheck('source edit rejects ids owned by another project before update and audit',strpos($service,"(int)(\$before['project_id']??0)!==\$projectId")!==false&&strpos($service,"'error'=>'source_project_mismatch'")!==false&&strpos($service,'UPDATE conversation_sources SET')!==false);
sourceCheck('source service distinguishes duplicate key from other database failures',strpos($service,'isDuplicateKeyFailure($e)')!==false&&strpos($service,"'error'=>'duplicate_source_key'")!==false&&strpos($service,"'error'=>'save_failed'")!==false&&strpos($service,'$driverCode===1062')!==false);
sourceCheck('routing group validates submitted members before clearing membership',strpos($service,'SELECT DISTINCT m.id FROM managers m JOIN manager_projects mp ON mp.manager_id=m.id WHERE m.id IN (')!==false&&strpos($service,'$eligibleIds!==$submittedIds')!==false&&strpos($service,"DELETE FROM manager_group_members WHERE group_id=?")!==false);
sourceCheck('routing group inserts only the already validated member set',strpos($service,"INSERT IGNORE INTO manager_group_members (group_id,manager_id) VALUES (?,?)")!==false&&strpos($service,'foreach($memberIds as $mid)$ins->execute([$groupId,$mid]);')!==false&&strpos($service,'SELECT ?,m.id FROM managers m JOIN manager_projects')===false);
sourceCheck('routing group keeps bool compatibility wrapper over structured result',strpos($service,'public static function saveGroup(')!==false&&strpos($service,'self::saveGroupResult(')!==false);
sourceCheck('routing group exposes stale member group and duplicate errors',strpos($service,"'error'=>'invalid_group_members'")!==false&&strpos($service,"'error'=>'group_not_found'")!==false&&strpos($service,"'error'=>'group_project_mismatch'")!==false&&strpos($service,"'error'=>'duplicate_group_key'")!==false);
sourceCheck('manager API returns structured routing group result with statuses',strpos($api,'RoutingAdminService::saveGroupResult(')!==false&&strpos($api,"$error==='duplicate_group_key'?409")!==false&&strpos($api,"['project_not_found','group_not_found']")!==false);
sourceCheck('admin directory project save distinguishes duplicate key from other database failures',strpos($directoryService,"self::isDuplicateKeyError(\$e)?'duplicate_project_key':'project_save_failed'")!==false);
sourceCheck('manager API maps admin directory validation conflict not-found and server errors separately',strpos($api,'function adminDirectorySaveStatus(array $result): int')!==false&&strpos($api,"['duplicate_project_key','duplicate_login']")!==false&&strpos($api,"['project_save_failed','manager_save_failed']")!==false&&strpos($api,"if(\$error==='not_found')return 404")!==false&&substr_count($api,'adminDirectorySaveStatus($r)')===2);
sourceCheck('routing UI explains structured group failures',strpos($routingJs,'function groupErrorText(')!==false&&strpos($routingJs,"invalid_group_members:'Состав менеджеров изменился")!==false&&strpos($routingJs,"duplicate_group_key:'Группа с таким кодом уже существует")!==false&&strpos($routingJs,"group_project_mismatch:'Группа относится к другому проекту")!==false);
sourceCheck('failed group save preserves form and uses structured message',strpos($routingJs,'groupStatus(groupErrorText(j.error))')!==false&&strpos($routingJs,"if(j.ok){resetGroupForm();")!==false);
sourceCheck('routing UI renders an inline live status region',strpos($routing,'id="sourceStatus"')!==false&&strpos($routing,'aria-live="polite"')!==false&&strpos($routingJs,'function sourceStatus(')!==false);
sourceCheck('routing UI maps backend source failures to specific messages',strpos($routingJs,"duplicate_source_key:'Источник с таким кодом уже существует")!==false&&strpos($routingJs,"invalid_primary_group:'Основная группа недоступна")!==false&&strpos($routingJs,"source_project_mismatch:'Источник относится к другому проекту")!==false&&strpos($routingJs,"save_failed:'Источник не сохранён из-за ошибки сервера")!==false);
$sourceResetOwnsFields=strpos($routingJs,'function resetSourceForm()')!==false&&strpos($routingJs,"$('sourceId').value='';$('sourceKey').value='';$('sourceName').value='';")!==false;
$successUsesReset=preg_match('/if\(j\.ok\)\{resetSourceForm\(\);sourceStatus\(\'Источник сохранён\.\',\'success\'\);await load\(\)\}/s',$routingJs)===1;
sourceCheck('failed source save preserves form instead of clearing it',$sourceResetOwnsFields&&$successUsesReset&&strpos($routingJs,'else{sourceStatus(sourceErrorText(j.error))')!==false);
sourceCheck('routing editors track independent in-flight saves',strpos($routingJs,"saving:{group:false,source:false}")!==false&&strpos($routingJs,'function editorBusy(kind)')!==false&&strpos($routingJs,'function setFormSaving(kind,saving)')!==false);
sourceCheck('group editor cannot switch or cancel while its save is in flight',strpos($routingJs,"window.editGroup=id=>{if(editorBusy('group'))return;")!==false&&strpos($routingJs,"cancelGroupEdit').onclick=()=>{if(editorBusy('group'))return;resetGroupForm()}")!==false&&strpos($routingJs,"setFormSaving('group',true)")!==false&&strpos($routingJs,"setFormSaving('group',false)")!==false);
sourceCheck('source editor cannot switch or cancel while its save is in flight',strpos($routingJs,"window.editSource=id=>{if(editorBusy('source'))return;")!==false&&strpos($routingJs,"cancelSourceEdit').onclick=()=>{if(editorBusy('source'))return;resetSourceForm()}")!==false&&strpos($routingJs,"setFormSaving('source',true)")!==false&&strpos($routingJs,"setFormSaving('source',false)")!==false);

$total=$passed+$failed;
echo "\n--------------------------\nTOTAL {$total} | PASS {$passed} | FAIL {$failed}\n";
exit($failed?1:0);