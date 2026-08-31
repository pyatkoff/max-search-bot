<?php

declare(strict_types=1);
$root=dirname(__DIR__);
$js=(string)file_get_contents($root.'/manager/assets/workspace-v2-task-presets.js');
$kanbanTasks=(string)file_get_contents($root.'/manager/assets/workspace-v2-kanban-tasks.js');
$bootstrap=(string)file_get_contents($root.'/manager/assets/workspace-v2-bootstrap.js');
$index=(string)file_get_contents($root.'/manager/index.php');
$css=(string)file_get_contents($root.'/manager/assets/workspace-v2-tasks.css');
$passed=0;$failed=0;
function dsCheck(string $name,bool $ok):void{global$passed,$failed;if($ok){echo "PASS  {$name}\n";$passed++;return;}echo "FAIL  {$name}\n";$failed++;}

dsCheck('workspace shell loads dedicated task due shortcut module through shared cache busting',strpos($index,"workspaceAsset('workspace-v2-task-presets.js')")!==false);
dsCheck('workspace shell loads shift wrapper before bootstrap through shared cache busting',strpos($index,"workspaceAsset('workspace-v2-shift.js')")!==false&&strpos($index,"workspaceAsset('workspace-v2-shift.js')")<strpos($index,"workspaceAsset('workspace-v2-bootstrap.js')"));
dsCheck('bootstrap no longer owns hard-coded dynamic module versions',strpos($bootstrap,'workspace-v2-task-presets.js')===false&&strpos($bootstrap,'workspace-v2-shift.js')===false&&strpos($bootstrap,'?v=1')===false);
dsCheck('due shortcuts stay a dedicated UI helper instead of task persistence owner',strpos($js,'WorkspaceV2TaskPresets')!==false&&strpos($js,"pipe('")===false&&strpos($js,'fetch(')===false);
dsCheck('create and edit task deadline controls are enhanced',strpos($js,".taskCreate")!==false&&strpos($js,"#leadTaskDue")!==false&&strpos($js,".taskEditForm")!==false&&strpos($js,"[data-task-edit-due]")!==false);
dsCheck('kanban task deadline control is supported by shared preset helper',strpos($js,".kanbanQuickTaskForm")!==false&&strpos($js,".kanbanTaskDue")!==false);
dsCheck('dynamically rendered kanban forms explicitly activate shared due presets',strpos($kanbanTasks,'WorkspaceV2TaskPresets?.enhanceAll(root)')!==false);
dsCheck('kanban task module does not duplicate deadline preset calculations',strpos($kanbanTasks,'Сегодня 18:00')===false&&strpos($kanbanTasks,'Завтра 10:00')===false&&strpos($kanbanTasks,"preset==='hour'")===false);
dsCheck('presets offer one hour evening and tomorrow morning',strpos($js,"preset==='hour'")!==false&&strpos($js,"preset==='evening'")!==false&&strpos($js,"preset==='tomorrow'")!==false&&strpos($js,'Сегодня 18:00')!==false&&strpos($js,'Завтра 18:00')!==false&&strpos($js,'Завтра 10:00')!==false);
dsCheck('evening shortcut label follows the actual local day after 18:00',strpos($js,'function eveningLabel')!==false&&strpos($js,"sameLocalDay(todayAt(now,18),now)?'Сегодня 18:00':'Завтра 18:00'")!==false&&strpos($js,'>${eveningLabel(now)}</button>')!==false);
dsCheck('preset application reuses existing task change flow',strpos($js,"dispatchEvent(new Event('change'")!==false&&strpos($js,'input.value=localInputValue')!==false);
dsCheck('late today preset advances instead of creating a past deadline',strpos($js,'if(d<=from)d.setDate(d.getDate()+1)')!==false);
dsCheck('shortcut controls are accessible and responsive',strpos($js,'role="group" aria-label="Быстро выбрать срок"')!==false&&strpos($css,'.taskDuePresets')!==false&&strpos($css,'@media(max-width:520px)')!==false);

echo "\n--------------------------\nTOTAL ".($passed+$failed)." | PASS {$passed} | FAIL {$failed}\n";exit($failed?1:0);
