<?php

declare(strict_types=1);

$passed=0;$failed=0;
function maiCheck(string $name,bool $ok):void{global $passed,$failed;if($ok){echo "PASS  {$name}\n";$passed++;return;}echo "FAIL  {$name}\n";$failed++;}

$service=(string)file_get_contents(__DIR__.'/../services/ManagerConversationService.php');
$migration=(string)file_get_contents(__DIR__.'/../migrations/010_manager_assignment_integrity.sql');
$uniqueMigration=(string)file_get_contents(__DIR__.'/../migrations/026_unique_active_manager_assignment.sql');

maiCheck('take locks conversation before mutation',strpos($service,"accessibleConversation(\$conversationId,\$managerId,true)")!==false);
maiCheck('repeat take by same assigned manager is idempotent',strpos($service,"(string)\$row['status']==='manager' && (int)(\$row['manager_id']??0)===\$managerId")!==false);
maiCheck('idempotent repeat exits before assignment insert',preg_match("/if\(\(string\)\\\$row\['status'\]===\'manager\'.*?return true;.*?INSERT INTO manager_assignments/s",$service)===1);
maiCheck('migration releases assignments inconsistent with canonical conversation state',strpos($migration,"c.status<>'manager' OR c.manager_id IS NULL OR a.manager_id<>c.manager_id")!==false);
maiCheck('migration deduplicates remaining active rows',strpos($migration,'HAVING COUNT(*)>1')!==false && strpos($migration,'a.id<>duplicates.keep_id')!==false);
maiCheck('migration preserves one active assignment instead of deleting history',strpos($migration,'SET a.released_at=NOW()')!==false && stripos($migration,'DELETE FROM manager_assignments')===false);
maiCheck('forward migration cleans inconsistent active assignments before adding invariant',strpos($uniqueMigration,"c.status<>'manager' OR c.manager_id IS NULL OR a.manager_id<>c.manager_id")!==false && strpos($uniqueMigration,'HAVING COUNT(*)>1')!==false);
maiCheck('compatibility guard avoids rebuilding legacy manager assignments table',strpos($uniqueMigration,'ALTER TABLE manager_assignments')===false && strpos($uniqueMigration,'CREATE TABLE IF NOT EXISTS active_manager_assignment_guards')!==false);
maiCheck('guard primary key enforces one active assignment per conversation',strpos($uniqueMigration,'PRIMARY KEY (conversation_id)')!==false);
maiCheck('active insert reserves guard key',strpos($uniqueMigration,'BEFORE INSERT ON manager_assignments')!==false && strpos($uniqueMigration,'WHERE NEW.released_at IS NULL')!==false);
maiCheck('release removes guard key',strpos($uniqueMigration,'AFTER UPDATE ON manager_assignments')!==false && strpos($uniqueMigration,'NEW.released_at IS NOT NULL')!==false);
maiCheck('delete removes guard key',strpos($uniqueMigration,'AFTER DELETE ON manager_assignments')!==false);
maiCheck('failed migration retry is trigger-idempotent',substr_count($uniqueMigration,'DROP TRIGGER IF EXISTS')===4);
maiCheck('forward repair preserves assignment history',stripos($uniqueMigration,'DELETE FROM manager_assignments')===false && strpos($uniqueMigration,'SET a.released_at=NOW()')!==false);

$total=$passed+$failed;
echo "\n---------------------------------\nTOTAL {$total} | PASS {$passed} | FAIL {$failed}\n";
exit($failed>0?1:0);
