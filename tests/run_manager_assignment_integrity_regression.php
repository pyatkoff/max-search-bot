<?php

declare(strict_types=1);

$passed=0;$failed=0;
function maiCheck(string $name,bool $ok):void{global $passed,$failed;if($ok){echo "PASS  {$name}\n";$passed++;return;}echo "FAIL  {$name}\n";$failed++;}

$service=(string)file_get_contents(__DIR__.'/../services/ManagerConversationService.php');
$migration=(string)file_get_contents(__DIR__.'/../migrations/010_manager_assignment_integrity.sql');
$repairMigration=(string)file_get_contents(__DIR__.'/../migrations/026_repair_active_manager_assignments.sql');

maiCheck('take locks conversation before mutation',strpos($service,"accessibleConversation(\$conversationId,\$managerId,true)")!==false);
maiCheck('repeat take by same assigned manager is idempotent',strpos($service,"(string)\$row['status']==='manager' && (int)(\$row['manager_id']??0)===\$managerId")!==false);
maiCheck('idempotent repeat exits before assignment insert',preg_match("/if\(\(string\)\\\$row\['status'\]===\'manager\'.*?return true;.*?INSERT INTO manager_assignments/s",$service)===1);
maiCheck('reassign serializes on canonical conversation row',preg_match('/function reassign.*?accessibleConversation\(\$conversationId,\$adminId,true\)/s',$service)===1);
maiCheck('reassign releases prior active assignment before insert',preg_match('/UPDATE manager_assignments SET released_at=NOW\(\).*?INSERT INTO manager_assignments.*?admin_reassign/s',$service)===1);
maiCheck('reopen serializes on canonical conversation row',preg_match('/function reopen.*?accessibleConversation\(\$conversationId,\$managerId,true\)/s',$service)===1);
maiCheck('manager assignment writes remain centralized in conversation owner',substr_count($service,'INSERT INTO manager_assignments')===3);
maiCheck('legacy migration releases assignments inconsistent with canonical conversation state',strpos($migration,"c.status<>'manager' OR c.manager_id IS NULL OR a.manager_id<>c.manager_id")!==false);
maiCheck('legacy migration deduplicates remaining active rows',strpos($migration,'HAVING COUNT(*)>1')!==false && strpos($migration,'a.id<>duplicates.keep_id')!==false);
maiCheck('repair migration re-applies canonical-state cleanup',strpos($repairMigration,"c.status<>'manager' OR c.manager_id IS NULL OR a.manager_id<>c.manager_id")!==false);
maiCheck('repair migration deduplicates active rows while preserving history',strpos($repairMigration,'HAVING COUNT(*)>1')!==false && strpos($repairMigration,'a.id<>duplicates.keep_id')!==false && stripos($repairMigration,'DELETE FROM manager_assignments')===false);
maiCheck('repair avoids privileged trigger creation',stripos($repairMigration,'CREATE TRIGGER')===false && stripos($repairMigration,'DROP TRIGGER')===false);
maiCheck('repair avoids rebuilding legacy assignment table',stripos($repairMigration,'ALTER TABLE manager_assignments')===false);
maiCheck('repair removes partial guard artifact from failed deploy attempt',strpos($repairMigration,'DROP TABLE IF EXISTS active_manager_assignment_guards')!==false);

$total=$passed+$failed;
echo "\n---------------------------------\nTOTAL {$total} | PASS {$passed} | FAIL {$failed}\n";
exit($failed>0?1:0);
