<?php

declare(strict_types=1);
$root=dirname(__DIR__);
$stage=(string)file_get_contents($root.'/manager/assets/workspace-v2-kanban-stage.js');
$api=(string)file_get_contents($root.'/manager/pipeline-api.php');
$passed=0;$failed=0;
function dragCheck(string $name,bool $ok):void{global$passed,$failed;if($ok){echo "PASS  {$name}\n";$passed++;return;}echo "FAIL  {$name}\n";$failed++;}
dragCheck('editable kanban stage control exposes explicit drag handle',strpos($stage,'function dragHandle(c,currentStage)')!==false&&strpos($stage,'draggable="true"')!==false&&strpos($stage,'kanbanDragHandle')!==false&&strpos($stage,'Переместить')!==false);
dragCheck('drag is desktop fine-pointer enhancement only',strpos($stage,"matchMedia?.('(pointer:fine) and (min-width:901px)')")!==false&&strpos($stage,"el.hidden=!enabled")!==false&&strpos($stage,'if(!enabled)return')!==false);
dragCheck('stage select remains available as accessible fallback',strpos($stage,'kanbanStageSelect')!==false&&strpos($stage,"el.onchange=()=>change(el)")!==false);
dragCheck('drop targets are pipeline columns and exclude synthetic no-stage column',strpos($stage,".kanbanColumn[data-stage-key] .kanbanCards")!==false&&strpos($stage,"stageKey==='__other'")!==false&&strpos($stage,'ondragover')!==false&&strpos($stage,'ondrop=async')!==false);
dragCheck('drop does not persist when lead stays in same stage',strpos($stage,'dragState.currentStage===stageKey')!==false&&strpos($stage,'nextStage===previousStage')!==false);
dragCheck('select and drag share one authorized stage mutation owner',strpos($stage,'async function saveStage')!==false&&substr_count($stage,"pipe('set_stage'")===1&&strpos($stage,"source:'drag'")!==false&&strpos($stage,"source:'select'")!==false);
dragCheck('stage mutation keeps backend conversation edit authorization',strpos($api,"if(\$action==='set_stage'")!==false&&strpos($api,'ManagerHttp::requireConversationEdit($c,$m);')!==false);
dragCheck('failed drag keeps current board state and reports failure',strpos($stage,"feedback('Этап не сохранён','error')")!==false&&strpos($stage,"if(!j?.ok)")!==false&&strpos($stage,'await window.WorkspaceV2Inbox.load()')!==false);
dragCheck('concurrent stage writes for one lead are guarded',strpos($stage,'const stageMutations=new Set()')!==false&&strpos($stage,'stageMutations.has(id)')!==false&&strpos($stage,'stageMutations.add(id)')!==false&&strpos($stage,'stageMutations.delete(id)')!==false);
echo "\n--------------------------\nTOTAL ".($passed+$failed)." | PASS {$passed} | FAIL {$failed}\n";exit($failed?1:0);
