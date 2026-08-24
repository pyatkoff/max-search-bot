<?php

declare(strict_types=1);

$base=dirname(__DIR__);
$service=(string)file_get_contents($base.'/services/ManagerConversationService.php');
$api=(string)file_get_contents($base.'/manager/api.php');

$passed=0;$failed=0;
function mvCheck(string $name,bool $ok):void{global$passed,$failed;if($ok){echo "PASS  {$name}\n";$passed++;return;}echo "FAIL  {$name}\n";$failed++;}

mvCheck('ordinary all queue includes unassigned plus own',strpos($service,"(c.manager_id IS NULL OR c.manager_id=?)")!==false);
mvCheck('own-only restriction is only for non-admin',strpos($service,"if(!$isAdmin){$where[]='(c.manager_id IS NULL OR c.manager_id=?)'")!==false);
mvCheck('admin manager filter is optional',strpos($service,"if($isAdmin && $queue!=='mine')")!==false);
mvCheck('admin unassigned filter exists',strpos($service,"$managerFilter==='unassigned'")!==false);
mvCheck('admin mine filter exists',strpos($service,"$managerFilter==='mine'")!==false);
mvCheck('api forwards manager filter only for admin',strpos($api,"$isAdmin?(string)($data['manager_filter']??''):'")!==false);
mvCheck('not-working fallback is explicitly guarded',strpos($api,"!$isAdmin&&($queue==='waiting'||$queue==='all')&&!ManagerAvailabilityService::isWorking")!==false);

$total=$passed+$failed;
echo "\n--------------------------\nTOTAL {$total} | PASS {$passed} | FAIL {$failed}\n";
exit($failed?1:0);
