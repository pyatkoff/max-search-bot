<?php

declare(strict_types=1);

$passed=0;$failed=0;
function oqCheck(string $name,$actual,$expected):void{global$passed,$failed;if($actual===$expected){echo"PASS  {$name}\n";$passed++;return;}echo"FAIL  {$name}\n";echo'      expected: '.json_encode($expected)."\n";echo'      actual:   '.json_encode($actual)."\n";$failed++;}

$path=__DIR__.'/../migrations/019_repair_outside_hours_waiting_manager.sql';
$sql=is_file($path)?(string)file_get_contents($path):'';
oqCheck('repair migration exists',$sql!=='',true);
oqCheck('repair only targets waiting manager state',substr_count($sql,"c.status='waiting_manager'")>=2,true);
oqCheck('repair excludes assigned conversations',substr_count($sql,'c.manager_id IS NULL')>=2,true);
oqCheck('repair requires explicit outside-hours evidence',substr_count($sql,'"within_working_hours":false')>=2,true);
oqCheck('repair selects latest waiting event',substr_count($sql,"MAX(e2.id)")>=2,true);
oqCheck('repair restores technical state to ai',strpos($sql,"SET c.status='ai',c.manager_id=NULL")!==false,true);
oqCheck('repair records lifecycle evidence',strpos($sql,"'ai_resumed'")!==false&&strpos($sql,'outside_hours_queue_repair')!==false,true);
oqCheck('repair is generic not conversation-specific',preg_match('/conversation_id\s*=\s*617|c\.id\s*=\s*617/',$sql)===0,true);

$total=$passed+$failed;echo"\n--------------------------\nTOTAL {$total} | PASS {$passed} | FAIL {$failed}\n";exit($failed>0?1:0);
