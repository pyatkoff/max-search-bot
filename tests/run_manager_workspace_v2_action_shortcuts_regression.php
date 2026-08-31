<?php

declare(strict_types=1);

$root=dirname(__DIR__);
$page=(string)file_get_contents($root.'/manager/index.php');
$filters=(string)file_get_contents($root.'/manager/assets/workspace-v2-filters.js');
$pipeline=(string)file_get_contents($root.'/manager/assets/workspace-v2-pipeline.js');
$inbox=(string)file_get_contents($root.'/manager/assets/workspace-v2-inbox.js');
$css=(string)file_get_contents($root.'/manager/assets/workspace-v2-inbox.css');
$passed=0;$failed=0;
function shortcutCheck(string $name,bool $ok):void{global$passed,$failed;if($ok){echo "PASS  {$name}\n";$passed++;return;}echo "FAIL  {$name}\n";$failed++;}

shortcutCheck('inbox exposes prominent action and overdue task shortcuts',strpos($page,'data-task-filter="action"')!==false&&strpos($page,'data-task-filter="overdue"')!==false&&strpos($page,'Нужно действие')!==false&&strpos($page,'Просрочено')!==false);
shortcutCheck('advanced task filter remains canonical and complete',strpos($page,'id="leadTaskFilter"')!==false&&strpos($page,'value="action"')!==false&&strpos($page,'value="overdue"')!==false&&strpos($page,'value="planned"')!==false&&strpos($page,'value="none"')!==false);
shortcutCheck('shortcuts reuse canonical lead task filter state',strpos($filters,'S.leadTaskFilter=next')!==false&&strpos($filters,"$('leadTaskFilter')")!==false&&strpos($filters,'setTaskShortcut')!==false);
shortcutCheck('shortcut interaction is toggleable instead of sticky',strpos($filters,"===String(value||'')?'':String(value||'')")!==false);
shortcutCheck('shortcut state stays synchronized with advanced filter',strpos($filters,'syncTaskShortcuts()')!==false&&strpos($filters,"button.setAttribute('aria-pressed',String(active))")!==false);
shortcutCheck('inbox owns queue selection and tab synchronization',strpos($inbox,'function setQueue(queue,{reload=false}={})')!==false&&strpos($inbox,'S.queue=queue')!==false&&strpos($inbox,"classList.toggle('active',x===button)")!==false&&strpos($inbox,'await setQueue(b.dataset.q')!==false);
shortcutCheck('task filters share one manager work queue helper',strpos($filters,"function ensureTaskQueue(){if(S.leadTaskFilter)window.WorkspaceV2Inbox?.setQueue('mine',{reload:false})}")!==false);
shortcutCheck('activating a quick task shortcut switches into manager work queue',strpos($filters,'S.leadTaskFilter=next')!==false&&strpos($filters,'ensureTaskQueue();await apply()')!==false);
shortcutCheck('toggling an active shortcut off keeps current queue',strpos($filters,"===String(value||'')?'':String(value||'')")!==false&&strpos($filters,"function ensureTaskQueue(){if(S.leadTaskFilter)")!==false);
shortcutCheck('advanced task select uses the same manager work queue semantics',strpos($filters,"$('leadTaskFilter').onchange=async()=>{S.leadTaskFilter=$('leadTaskFilter').value;ensureTaskQueue();await apply()}")!==false);
shortcutCheck('task filter change reloads through existing inbox filter boundary',strpos($filters,'await apply()')!==false&&strpos($filters,'WorkspaceV2Inbox.load({preserveScroll:false})')!==false);
shortcutCheck('clear filters also clears shortcut state',strpos($filters,"S.leadTaskFilter=''")!==false&&strpos($filters,'render();')!==false&&strpos($filters,'await apply()')!==false);
shortcutCheck('pipeline module stays focused on sales mutations',strpos($pipeline,'setTaskShortcut')===false&&strpos($pipeline,'FILTER_STORAGE_KEY')===false&&strpos($pipeline,'bindSalesEditor')!==false);
shortcutCheck('shortcuts remain keyboard visible and mobile scroll safe',strpos($css,'.taskShortcut:focus-visible')!==false&&strpos($css,'.taskShortcuts{display:flex')!==false&&strpos($css,'overflow-x:auto')!==false);
shortcutCheck('overdue shortcut has distinct urgency styling',strpos($css,'.taskShortcut[data-task-filter="overdue"].active')!==false);
$all=$page."\n".$filters."\n".$pipeline."\n".$inbox."\n".$css;
shortcutCheck('presentation shortcut does not mutate task persistence routing or analytics',strpos($all,"create_task")===false&&stripos($all,'set_working')===false&&stripos($all,'routing_bonus')===false&&stripos($all,'metrika')===false&&strpos($all,'LeadDestination')===false);

echo "\n--------------------------\nTOTAL ".($passed+$failed)." | PASS {$passed} | FAIL {$failed}\n";
exit($failed?1:0);