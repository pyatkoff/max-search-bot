<?php

declare(strict_types=1);

$base=dirname(__DIR__);
$source=(string)file_get_contents($base.'/tools/production_snapshot.php');
$passed=0;$failed=0;
function mdsCheck(string $name,bool $ok):void{global $passed,$failed;if($ok){echo "PASS  {$name}\n";$passed++;return;}echo "FAIL  {$name}\n";$failed++;}

mdsCheck('snapshot exposes delivery failure collection',strpos($source,"'recent_manager_delivery_failures'=>[]")!==false);
mdsCheck('snapshot selects manager_message_failed events',strpos($source,"event_type='manager_message_failed'")!==false);
mdsCheck('snapshot includes failure payload for MAX reason',strpos($source,"json_decode((string)(\$failure['payload_json']??''),true)")!==false);
mdsCheck('raw payload_json is removed after decoding',strpos($source,"unset(\$failure['payload_json'])")!==false);

$total=$passed+$failed;
echo "\n--------------------------\nTOTAL {$total} | PASS {$passed} | FAIL {$failed}\n";
exit($failed?1:0);
