<?php

declare(strict_types=1);
$root=dirname(__DIR__);
$kanban=(string)file_get_contents($root.'/manager/assets/workspace-v2-kanban.js');
$css=(string)file_get_contents($root.'/manager/assets/workspace-v2-kanban.css');
$passed=0;$failed=0;
function checkAttention(string $name,bool $ok):void{global$passed,$failed;if($ok){echo "PASS  {$name}\n";$passed++;return;}echo "FAIL  {$name}\n";$failed++;}
checkAttention('kanban separates actionable leads without changing priority ownership',strpos($kanban,'function renderCardSections(rows)')!==false&&strpos($kanban,'actionPriority(row)>0')!==false&&strpos($kanban,'actionPriority(row)===0')!==false&&strpos($kanban,'prioritizeRows(rows)')!==false);
checkAttention('attention section uses clear manager-facing copy and count',strpos($kanban,'Требует внимания')!==false&&strpos($kanban,'${attention.length}')!==false&&strpos($kanban,'Остальные')!==false);
checkAttention('empty stages retain canonical empty state',strpos($kanban,"if(!ordered.length)return'<div class=\"kanbanEmpty\">Нет лидов</div>'")!==false);
checkAttention('render path delegates stage card content to section renderer',substr_count($kanban,'renderCardSections(stageRows)')===1&&substr_count($kanban,'renderCardSections(groups.__other)')===1);
checkAttention('section labels are presentation only and responsive',strpos($css,'.kanbanSectionLabel')!==false&&strpos($css,'.kanbanSectionLabel.attention')!==false&&strpos($css,'.kanbanSectionLabel.steady')!==false&&strpos($css,'@media(max-width:900px)')!==false&&strpos($css,'.kanbanSectionLabel{font-size:11px;min-height:28px}')!==false);
checkAttention('kanban core still owns no task or stage mutation persistence',strpos($kanban,"pipe('set_task_")===false&&strpos($kanban,"pipe('set_stage'")===false&&strpos($kanban,'UPDATE lead_')===false);
echo "\n--------------------------\nTOTAL ".($passed+$failed)." | PASS {$passed} | FAIL {$failed}\n";exit($failed?1:0);
