<?php

declare(strict_types=1);
$root=dirname(__DIR__);
$js=(string)file_get_contents($root.'/manager/assets/workspace-v2-task-presets.js');
$bootstrap=(string)file_get_contents($root.'/manager/assets/workspace-v2-bootstrap.js');
$css=(string)file_get_contents($root.'/manager/assets/workspace-v2-tasks.css');
$passed=0;$failed=0;
function dsCheck(string $name,bool $ok):void{global$passed,$failed;if($ok){echo "PASS  {$name}\n";$passed++;return;}echo "FAIL  {$name}\n";$failed++;}

dsCheck('workspace bootstrap loads dedicated task due shortcut module',strpos($bootstrap,'workspace-v2-task-presets.js')!==false);
dsCheck('due shortcuts stay a dedicated UI helper instead of task persistence owner',strpos($js,'WorkspaceV2TaskPresets')!==false&&strpos($js,"pipe('")===false&&strpos($js,'fetch(')===false);
dsCheck('create and edit task deadline controls are enhanced',strpos($js,".taskCreate")!==false&&strpos($js,"#leadTaskDue")!==false&&strpos($js,".taskEditForm")!==false&&strpos($js,"[data-task-edit-due]")!==false);
dsCheck('presets offer one hour today evening and tomorrow morning',strpos($js,"preset==='hour'")!==false&&strpos($js,"preset==='evening'")!==false&&strpos($js,"preset==='tomorrow'")!==false&&strpos($js,'Сегодня 18:00')!==false&&strpos($js,'Завтра 10:00')!==false);
dsCheck('preset application reuses existing task change flow',strpos($js,"dispatchEvent(new Event('change'")!==false&&strpos($js,'input.value=localInputValue')!==false);
dsCheck('late today preset advances instead of creating a past deadline',strpos($js,'if(d<=from)d.setDate(d.getDate()+1)')!==false);
dsCheck('shortcut controls are accessible and responsive',strpos($js,'role=\"group\" aria-label=\"Быстро выбрать срок\"')!==false&&strpos($css,'.taskDuePresets')!==false&&strpos($css,'@media(max-width:520px)')!==false);

echo "\n--------------------------\nTOTAL ".($passed+$failed)." | PASS {$passed} | FAIL {$failed}\n";exit($failed?1:0);
