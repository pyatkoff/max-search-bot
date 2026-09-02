<?php

declare(strict_types=1);

$base=dirname(__DIR__);
$service=(string)file_get_contents($base.'/services/ManagerConversationService.php');
$queues=(string)file_get_contents($base.'/services/ManagerQueueProjectionService.php');
$migration=(string)file_get_contents($base.'/migrations/024_test_conversation_provenance.sql');
$passed=0;$failed=0;
function mtcfCheck(string $name,bool $ok):void{global $passed,$failed;if($ok){echo "PASS  {$name}\n";$passed++;return;}echo "FAIL  {$name}\n";$failed++;}

mtcfCheck('test provenance schema defaults normal conversations to visible',strpos($migration,'ADD COLUMN is_test TINYINT(1) NOT NULL DEFAULT 0')!==false);
mtcfCheck('manager inbox excludes explicit test conversations',strpos($service,"\$where=['c.is_test=0'];")!==false);
mtcfCheck('queue counts reuse the filtered inbox list through the canonical projection owner',strpos($queues,"ManagerConversationService::list(\$managerId,'waiting',200,\$projectKey)")!==false && strpos($queues,"ManagerConversationService::list(\$managerId,'mine',200,\$projectKey)")!==false);
mtcfCheck('test rows are not deleted by manager filtering',strpos($service,'DELETE FROM conversations')===false);
mtcfCheck('direct detail remains available for controlled audit and recovery',strpos($service,'WHERE c.id=? LIMIT 1')!==false);

$total=$passed+$failed;
echo "\n--------------------------\nTOTAL {$total} | PASS {$passed} | FAIL {$failed}\n";
exit($failed?1:0);
