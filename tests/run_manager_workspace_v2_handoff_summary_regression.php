<?php

declare(strict_types=1);
$root=dirname(__DIR__);
$api=(string)file_get_contents($root.'/manager/pipeline-api.php');
$js=(string)file_get_contents($root.'/manager/assets/workspace-v2-lead-card.js');
$css=(string)file_get_contents($root.'/manager/assets/workspace-v2-lead-card.css');
$passed=0;$failed=0;
function hsCheck(string $name,bool $ok):void{global$passed,$failed;if($ok){echo "PASS  {$name}\n";$passed++;}else{echo "FAIL  {$name}\n";$failed++;}}
hsCheck('detail exposes active delivery failure read-only',strpos($api,"require_once \$baseDir.'/services/ManagerDeliveryStateService.php'")!==false&&strpos($api,"pipelineDetailPart('delivery_failure',\$id,static fn()=>ManagerDeliveryStateService::activeFailure(\$id)")!==false&&strpos($api,"'delivery_failure'=>\$deliveryFailure")!==false);
hsCheck('lead card renders prominent handoff strip',strpos($js,'function handoffMarkup(handoff)')!==false&&strpos($js,'${handoffMarkup(handoff)}')!==false&&strpos($js,'Ответственный: ${manager}')!==false);
hsCheck('suspended MAX delivery is explicit',strpos($js,'Клиент недоступен в MAX')!==false&&strpos($js,'failure.notice||failure.message')!==false&&strpos($js,'leadHandoffStrip ${failure?')!==false);
hsCheck('handoff strip has mobile-safe styling',strpos($css,'.leadHandoffStrip{')!==false&&strpos($css,'.leadHandoffStrip.isError')!==false&&strpos($css,'@media(max-width:520px)')!==false);
echo "\n--------------------------\nTOTAL ".($passed+$failed)." | PASS {$passed} | FAIL {$failed}\n";
exit($failed?1:0);
