<?php

declare(strict_types=1);
$root=dirname(__DIR__);
$service=(string)file_get_contents($root.'/services/SalesPipelineService.php');
$kanban=(string)file_get_contents($root.'/manager/assets/workspace-v2-kanban.js');
$stageHistory=(string)file_get_contents($root.'/manager/assets/workspace-v2-stage-history.js');
$css=(string)file_get_contents($root.'/manager/assets/workspace-v2-kanban.css');
$passed=0;$failed=0;
function stageAgeCheck(string $name,bool $ok):void{global$passed,$failed;if($ok){echo "PASS  {$name}\n";$passed++;return;}echo "FAIL  {$name}\n";$failed++;}
stageAgeCheck('lead list batches latest stage transition without per-row history queries',strpos($service,'$stageSince=[]')!==false&&strpos($service,'MAX(id) AS max_id')!==false&&strpos($service,'GROUP BY conversation_id')!==false&&strpos($service,"$r['lead_stage_since_at']=$stageSince[$id]??null")!==false);
stageAgeCheck('kanban consumes read-only stage age projection',strpos($kanban,'function stageSinceText(c)')!==false&&strpos($kanban,'lead_stage_since_at')!==false&&strpos($kanban,'Этап с ${formatted}')!==false&&strpos($kanban,"pipe('set_stage_since'")===false);
stageAgeCheck('kanban reuses lead-card stage timestamp formatter',strpos($kanban,'WorkspaceV2StageHistory?.formatStageSince(raw)')!==false&&strpos($stageHistory,'function formatStageSince')!==false);
stageAgeCheck('stage age remains compact and responsive',strpos($kanban,'kanbanStageSince')!==false&&strpos($css,'.kanbanStageSince{order:7}')!==false&&strpos($css,'.kanbanStageSince{font-size:11px}')!==false);
stageAgeCheck('projection introduces no technical state mutation',strpos($service,'lead_stage_since_at')!==false&&strpos($kanban,'technical_status')===false&&strpos($kanban,"pipe('set_status'")===false);
echo "\n--------------------------\nTOTAL ".($passed+$failed)." | PASS {$passed} | FAIL {$failed}\n";exit($failed?1:0);
