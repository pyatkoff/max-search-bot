<?php

declare(strict_types=1);

$root=dirname(__DIR__);
$leadCardJs=(string)file_get_contents($root.'/manager/assets/workspace-v2-lead-card.js');
$pipelineJs=(string)file_get_contents($root.'/manager/assets/workspace-v2-pipeline.js');
$js=$leadCardJs."\n".$pipelineJs;
$css=(string)file_get_contents($root.'/manager/assets/workspace-v2.css');
$api=(string)file_get_contents($root.'/manager/pipeline-api.php');
$service=(string)file_get_contents($root.'/services/SalesPipelineService.php');
$context=(string)file_get_contents($root.'/services/ManagerRequestContext.php');
$passed=0;$failed=0;
function outcomeCheck(string $name,bool $ok):void{global$passed,$failed;if($ok){echo "PASS  {$name}\n";$passed++;return;}echo "FAIL  {$name}\n";$failed++;}

outcomeCheck('catalog exposes outcomes and close reasons',strpos($api,"'outcomes'=>SalesPipelineService::outcomeOptions()")!==false&&strpos($api,"'close_reasons'=>SalesPipelineService::closeReasonOptions()")!==false);
outcomeCheck('detail snapshot includes outcome',strpos($service,"'outcome'=>self::outcomeForConversation")!==false);
outcomeCheck('workspace renders outcome controls',strpos($js,'id="leadOutcome"')!==false&&strpos($js,'id="leadCloseReason"')!==false&&strpos($js,'id="leadOutcomeNote"')!==false);
outcomeCheck('lost outcome requires structured reason',strpos($js,"outcome==='lost'&&!closeReason")!==false&&strpos($service,"\$outcome==='lost'")!==false);
outcomeCheck('workspace persists outcome through pipeline API',strpos($js,"pipe('set_outcome'")!==false&&strpos($api,"\$action==='set_outcome'")!==false&&strpos($api,'SalesPipelineService::setOutcome')!==false);
outcomeCheck('outcome controls respect shared pipeline ownership',strpos($js,"canEdit?'':'disabled'")!==false&&strpos($api,'ManagerRequestContext::canEditAssignedConversation')!==false&&strpos($context,'canEditAssignedConversation')!==false);
outcomeCheck('outcome UI has dedicated styling',strpos($css,'.outcomeBox')!==false&&strpos($css,'.outcomeNote')!==false&&strpos($css,'.outcomeSave')!==false);

echo "\n--------------------------\nTOTAL ".($passed+$failed)." | PASS {$passed} | FAIL {$failed}\n";
exit($failed?1:0);
