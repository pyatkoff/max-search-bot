<?php

declare(strict_types=1);
$root=dirname(__DIR__);
$kanban=(string)file_get_contents($root.'/manager/assets/workspace-v2-kanban.js');
$css=(string)file_get_contents($root.'/manager/assets/workspace-v2-kanban.css');
$passed=0;$failed=0;
function checkAttention(string $name,bool $ok):void{global$passed,$failed;if($ok){echo "PASS  {$name}\n";$passed++;return;}echo "FAIL  {$name}\n";$failed++;}
checkAttention('kanban keeps canonical action priority as the only ordering owner',strpos($kanban,'function actionPriority(c)')!==false&&strpos($kanban,'function prioritizeRows(rows)')!==false&&strpos($kanban,'prioritizeRows(rows)')!==false);
checkAttention('kanban explains why each actionable lead needs attention',strpos($kanban,'function actionReason(c)')!==false&&strpos($kanban,"return'Без ответа'")!==false&&strpos($kanban,"return'Просрочено'")!==false&&strpos($kanban,"return'Сегодня'")!==false&&strpos($kanban,"return'Без задачи'")!==false&&strpos($kanban,"return'Непрочитано'")!==false);
checkAttention('attention reason precedence mirrors operational priority buckets',strpos($kanban,"if(Number(c.awaiting_first_reply||0)===1)return'Без ответа'")!==false&&strpos($kanban,"if(taskState==='overdue')return'Просрочено'")!==false&&strpos($kanban,"if(taskState==='today')return'Сегодня'")!==false&&strpos($kanban,"if(taskState==='none')return'Без задачи'")!==false&&strpos($kanban,"if(Number(c.unread_count||0)>0)return'Непрочитано'")!==false);
checkAttention('closed outcomes never receive false attention reasons',strpos($kanban,"if(String(c.lead_outcome||'open')!=='open')return''")!==false);
checkAttention('render groups keep fixed manager-facing urgency order',strpos($kanban,"const reasons=['Без ответа','Просрочено','Сегодня','Без задачи','Непрочитано']")!==false&&strpos($kanban,'ordered.forEach(row=>{const reason=actionReason(row)')!==false);
checkAttention('each non-empty attention reason shows a count before its cards',strpos($kanban,'<span>${esc(reason)}</span><strong>${items.length}</strong>')!==false&&strpos($kanban,"items.map(card).join('')")!==false);
checkAttention('steady leads stay separated only when actionable leads exist',strpos($kanban,"if(!actionable)return ordered.map(card).join('')")!==false&&strpos($kanban,'Остальные')!==false&&strpos($kanban,'if(steady.length)')!==false);
checkAttention('attention section headings are accessible',strpos($kanban,'role="heading" aria-level="4"')!==false);
checkAttention('empty stages retain canonical empty state',strpos($kanban,"if(!ordered.length)return'<div class=\"kanbanEmpty\">Нет лидов</div>'")!==false);
checkAttention('render path delegates stage card content to section renderer',substr_count($kanban,'renderCardSections(stageRows)')===1&&substr_count($kanban,'renderCardSections(groups.__other)')===1);
checkAttention('column summary surfaces today workload from canonical operational task state',strpos($kanban,'overdue=0,today=0,missing=0')!==false&&strpos($kanban,"else if(taskState==='today')today+=1")!==false&&strpos($kanban,'if(today)parts.push(`${today} сегодня`)')!==false);
checkAttention('closed outcomes are excluded from actionable overdue and today column counts',strpos($kanban,"const outcome=String(c.lead_outcome||'open')")!==false&&strpos($kanban,"if(outcome==='open'){const taskState=operationalTaskState(c)")!==false&&strpos($kanban,"else if(outcome==='open')missing++")!==false);
checkAttention('section labels remain presentation only and responsive',strpos($css,'.kanbanSectionLabel')!==false&&strpos($css,'.kanbanSectionLabel.attention')!==false&&strpos($css,'.kanbanSectionLabel.steady')!==false&&strpos($css,'@media(max-width:900px)')!==false&&strpos($css,'.kanbanSectionLabel{font-size:11px;min-height:28px}')!==false);
checkAttention('kanban core still owns no task or stage mutation persistence',strpos($kanban,"pipe('set_task_")===false&&strpos($kanban,"pipe('set_stage'")===false&&strpos($kanban,'UPDATE lead_')===false);
echo "\n--------------------------\nTOTAL ".($passed+$failed)." | PASS {$passed} | FAIL {$failed}\n";exit($failed?1:0);
