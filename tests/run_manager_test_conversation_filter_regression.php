<?php

declare(strict_types=1);

$base=dirname(__DIR__);
$service=(string)file_get_contents($base.'/services/ManagerConversationService.php');
$migration=(string)file_get_contents($base.'/migrations/024_test_conversation_provenance.sql');
$passed=0;$failed=0;
function mtcfCheck(string $name,bool $ok):void{global $passed,$failed;if($ok){echo "PASS  {$name}\n";$passed++;return;}echo "FAIL  {$name}\n";$failed++;}

mtcfCheck('test provenance column exists',strpos($migration,'ADD COLUMN is_test TINYINT(1) NOT NULL DEFAULT 0')!==false);
mtcfCheck('manager inbox excludes explicit test conversations',strpos($service,"$where[]='c.is_test=0';")!==false);
mtcfCheck('manager detail does not expose explicit tests',strpos($service,'WHERE c.id=? AND c.is_test=0 LIMIT 1')!==false);
mtcfCheck('manager mutations cannot target explicit tests',strpos($service,'FROM conversations WHERE id=? AND is_test=0 LIMIT 1')!==false);

echo "\nManager test conversation filter regression: {$passed} passed, {$failed} failed.\n";
exit($failed===0?0:1);
