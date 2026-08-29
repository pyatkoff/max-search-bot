<?php

declare(strict_types=1);

$root=dirname(__DIR__);
$api=(string)file_get_contents($root.'/manager/api.php');
$routing=(string)file_get_contents($root.'/manager/routing.php');
$service=(string)file_get_contents($root.'/services/RoutingAdminService.php');

$passed=0;$failed=0;
function sourceCheck(string $name,bool $ok):void{global $passed,$failed;if($ok){echo "PASS  {$name}\n";$passed++;return;}echo "FAIL  {$name}\n";$failed++;}

sourceCheck('manager API returns structured source service result',strpos($api,'RoutingAdminService::saveSourceResult(')!==false&&strpos($api,"$error=(string)($r['error']??'save_failed')")!==false);
sourceCheck('manager API distinguishes validation conflict access and server failures',strpos($api,"$error==='duplicate_source_key'?409")!==false&&strpos($api,"$error==='save_failed'?500:422")!==false&&strpos($api,"['admin_required','project_access_denied']")!==false);
sourceCheck('source service keeps explicit validation errors',strpos($service,"'error'=>'missing_source_key'")!==false&&strpos($service,"'error'=>'invalid_primary_group'")!==false&&strpos($service,"'error'=>'fallback_group_required'")!==false);
sourceCheck('source service distinguishes duplicate key from other database failures',strpos($service,'isDuplicateKeyFailure($e)')!==false&&strpos($service,"'error'=>'duplicate_source_key'")!==false&&strpos($service,"'error'=>'save_failed'")!==false&&strpos($service,'$driverCode===1062')!==false);
sourceCheck('routing UI renders an inline live status region',strpos($routing,'id="sourceStatus"')!==false&&strpos($routing,'aria-live="polite"')!==false&&strpos($routing,'function sourceStatus(')!==false);
sourceCheck('routing UI maps backend source failures to specific messages',strpos($routing,"duplicate_source_key:'Источник с таким кодом уже существует")!==false&&strpos($routing,"invalid_primary_group:'Основная группа недоступна")!==false&&strpos($routing,"save_failed:'Источник не сохранён из-за ошибки сервера")!==false);
sourceCheck('failed source save preserves form instead of clearing it',preg_match("/if\(j\.ok\)\{[^}]*sourceId[^}]*sourceKey[^}]*sourceName/s",$routing)===1&&strpos($routing,'else{sourceStatus(sourceErrorText(j.error))')!==false);

$total=$passed+$failed;
echo "\n--------------------------\nTOTAL {$total} | PASS {$passed} | FAIL {$failed}\n";
exit($failed?1:0);
