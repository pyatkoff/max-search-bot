<?php

declare(strict_types=1);

$root=dirname(__DIR__);
$pipelineJs=(string)file_get_contents($root.'/manager/assets/workspace-v2-pipeline.js');
$leadCardJs=(string)file_get_contents($root.'/manager/assets/workspace-v2-lead-card.js');
$kanbanStageJs=(string)file_get_contents($root.'/manager/assets/workspace-v2-kanban-stage.js');
$passed=0;$failed=0;
function sfCheck(string $name,bool $ok):void{global$passed,$failed;if($ok){echo "PASS  {$name}\n";$passed++;return;}echo "FAIL  {$name}\n";$failed++;}

sfCheck('pipeline owns sales stage mutation request',strpos($pipelineJs,"pipe('set_stage',{conversation_id:target,stage_key:wanted})")!==false&&strpos($leadCardJs,"pipe('set_stage'")===false);
sfCheck('lead card delegates sales editor binding to pipeline module',strpos($leadCardJs,'WorkspaceV2Pipeline?.bindSalesEditor({canEdit,pipeline})')!==false&&strpos($pipelineJs,'function bindSalesEditor(')!==false);
sfCheck('stage mutation catches rejected async requests',strpos($pipelineJs,"catch(e){if(stage.isConnected)stage.value=previousStage;if(sameLead(target))setSalesSaveState('Не удалось изменить этап','error');return false}")!==false);
sfCheck('stage mutation restores previous value for explicit API failure',substr_count($pipelineJs,'stage.value=previousStage')>=2);
sfCheck('stage mutation reports failure for both false result and rejection',substr_count($pipelineJs,"setSalesSaveState('Не удалось изменить этап','error')")>=2);
sfCheck('stage mutation restores only the captured connected control availability',strpos($pipelineJs,'finally{stageSavingLeads.delete(target);if(stage.isConnected)stage.disabled=false}')!==false&&strpos($pipelineJs,"document.querySelector('#leadStage')")===false);
sfCheck('stage mutation refreshes lead data only after successful write',strpos($pipelineJs,"await refreshAfterSave(target);if(sameLead(target))setSalesSaveState('Этап сохранён','success')")!==false);
sfCheck('sales editor preserves an inactive current stage instead of visually selecting the first active stage',strpos($pipelineJs,'function ensureCurrentStageOption(stage,pipeline={})')!==false&&strpos($pipelineJs,"+' (неактивен)'")!==false&&strpos($pipelineJs,'option.disabled=true;option.selected=true;stage.prepend(option)')!==false&&strpos($pipelineJs,'ensureCurrentStageOption(stage,pipeline);let confirmedStage=String(pipeline.stage?.stage_key||stage.value)')!==false);
sfCheck('kanban selector also preserves inactive current stage and labels it explicitly',strpos($kanbanStageJs,'function stageOptions(currentStage,currentLabel=')!==false&&strpos($kanbanStageJs,'(неактивен)</option>')!==false&&strpos($kanbanStageJs,'stageOptions(currentStage,String(c.lead_stage?.display_name')!==false);
sfCheck('inactive stage placeholders stay disabled so only active destinations are selectable',substr_count($pipelineJs,'option.disabled=true')>=1&&strpos($kanbanStageJs,'selected disabled')!==false);
sfCheck('sales editor tracks the last confirmed stage instead of the initial render forever',strpos($pipelineJs,"let confirmedStage=String(pipeline.stage?.stage_key||stage.value)")!==false&&strpos($pipelineJs,'if(await saveStage(stage,confirmedStage))confirmedStage=wanted')!==false);
sfCheck('stage rollback state advances only after a successful write',strpos($pipelineJs,'const wanted=stage.value;if(await saveStage(stage,confirmedStage))confirmedStage=wanted')!==false);
sfCheck('legacy immutable previousStage binding is gone',strpos($pipelineJs,'const previousStage=String(pipeline.stage?.stage_key||stage.value)')===false);

echo "\n--------------------------\nTOTAL ".($passed+$failed)." | PASS {$passed} | FAIL {$failed}\n";
exit($failed?1:0);
