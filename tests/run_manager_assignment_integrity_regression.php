<?php

declare(strict_types=1);

$passed=0;$failed=0;
function maiCheck(string $name,bool $ok):void{global $passed,$failed;if($ok){echo "PASS  {$name}\n";$passed++;return;}echo "FAIL  {$name}\n";$failed++;}

$service=(string)file_get_contents(__DIR__.'/../services/ManagerConversationService.php');
$migration=(string)file_get_contents(__DIR__.'/../migrations/010_manager_assignment_integrity.sql');

maiCheck('take locks conversation before mutation',strpos($service,"accessibleConversation(\$conversationId,\$managerId,true)")!==false);
maiCheck('repeat take by same assigned manager is idempotent',strpos($service,"(string)\$row['status']==='manager' && (int)(\$row['manager_id']??0)===\$managerId")!==false);
maiCheck('idempotent repeat exits before assignment insert',preg_match("/if\(\(string\)\\\$row\['status'\]===\'manager\'.*?return true;.*?INSERT INTO manager_assignments/s",$service)===1);
maiCheck('migration releases assignments inconsistent with canonical conversation state',strpos($migration,"c.status<>'manager' OR c.manager_id IS NULL OR a.manager_id<>c.manager_id")!==false);
maiCheck('migration deduplicates remaining active rows',strpos($migration,'HAVING COUNT(*)>1')!==false && strpos($migration,'a.id<>duplicates.keep_id')!==false);
maiCheck('migration preserves one active assignment instead of deleting history',strpos($migration,'SET a.released_at=NOW()')!==false && stripos($migration,'DELETE FROM manager_assignments')===false);

$total=$passed+$failed;
echo "\n---------------------------------\nTOTAL {$total} | PASS {$passed} | FAIL {$failed}\n";
exit($failed>0?1:0);
