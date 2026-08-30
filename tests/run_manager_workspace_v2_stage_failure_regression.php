<?php

declare(strict_types=1);

$root=dirname(__DIR__);
$pipelineJs=(string)file_get_contents($root.'/manager/assets/workspace-v2-pipeline.js');
$leadCardJs=(string)file_get_contents($root.'/manager/assets/workspace-v2-lead-card.js');
$passed=0;$failed=0;
function sfCheck(string $name,bool $ok):void{global$passed,$failed;if($ok){echo "PASS  {$name}\n";$passed++;return;}echo "FAIL  {$name}\n";$failed++;}

sfCheck('pipeline owns sales stage mutation request',strpos($pipelineJs,"pipe('set_stage',{conversation_id:target,stage_key:wanted})")!==false&&strpos($leadCardJs,"pipe('set_stage'")===false);
sfCheck('lead card delegates sales editor binding to pipeline module',strpos($leadCardJs,'WorkspaceV2Pipeline?.bindSalesEditor({canEdit,pipeline})')!==false&&strpos($pipelineJs,'function bindSalesEditor(')!==false);
sfCheck('stage mutation catches rejected async requests',strpos($pipelineJs,"catch(e){if(stage.isConnected)stage.value=previousStage;if(sameLead(target))setSalesSaveState('Не удалось изменить этап','error');return false}")!==false);
sfCheck('stage mutation restores previous value for explicit API failure',substr_count($pipelineJs,'stage.value=previousStage')>=2);
sfCheck('stage mutation reports failure for both false result and rejection',substr_count($pipelineJs,"setSalesSaveState('Не удалось изменить этап','error')")>=2);
sfCheck('stage mutation always restores control availability',strpos($pipelineJs,'finally{if(stage.isConnected)stage.disabled=false}')!==false);
sfCheck('stage mutation refreshes lead data only after successful write',strpos($pipelineJs,"await refreshAfterSave(target);if(sameLead(target))setSalesSaveState('Этап сохранён','success')")!==false);

echo "\n--------------------------\nTOTAL ".($passed+$failed)." | PASS {$passed} | FAIL {$failed}\n";
exit($failed?1:0);
