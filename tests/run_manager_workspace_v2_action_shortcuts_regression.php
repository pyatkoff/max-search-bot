<?php

declare(strict_types=1);

$root=dirname(__DIR__);
$page=(string)file_get_contents($root.'/manager/index.php');
$pipeline=(string)file_get_contents($root.'/manager/assets/workspace-v2-pipeline.js');
$css=(string)file_get_contents($root.'/manager/assets/workspace-v2-inbox.css');
$passed=0;$failed=0;
function shortcutCheck(string $name,bool $ok):void{global$passed,$failed;if($ok){echo "PASS  {$name}\n";$passed++;return;}echo "FAIL  {$name}\n";$failed++;}

shortcutCheck('inbox exposes prominent action and overdue task shortcuts',strpos($page,'data-task-filter="action"')!==false&&strpos($page,'data-task-filter="overdue"')!==false&&strpos($page,'Нужно действие')!==false&&strpos($page,'Просрочено')!==false);
shortcutCheck('advanced task filter remains canonical and complete',strpos($page,'id="leadTaskFilter"')!==false&&strpos($page,'value="action"')!==false&&strpos($page,'value="overdue"')!==false&&strpos($page,'value="planned"')!==false&&strpos($page,'value="none"')!==false);
shortcutCheck('shortcuts reuse canonical lead task filter state',strpos($pipeline,'S.leadTaskFilter=next')!==false&&strpos($pipeline,"$('leadTaskFilter')")!==false&&strpos($pipeline,'setTaskShortcut')!==false);
shortcutCheck('shortcut interaction is toggleable instead of sticky',strpos($pipeline,"===String(value||'')?'':String(value||'')")!==false);
shortcutCheck('shortcut state stays synchronized with advanced filter',strpos($pipeline,'syncTaskShortcuts()')!==false&&strpos($pipeline,"button.setAttribute('aria-pressed',String(active))")!==false);
shortcutCheck('shortcut change reloads through existing inbox filter boundary',strpos($pipeline,'await applyFilters()')!==false&&strpos($pipeline,'WorkspaceV2Inbox.load({preserveScroll:false})')!==false);
shortcutCheck('clear filters also clears shortcut state',strpos($pipeline,"S.leadTaskFilter=''")!==false&&strpos($pipeline,'renderFilters();')!==false&&strpos($pipeline,'await applyFilters()')!==false);
shortcutCheck('shortcuts remain keyboard visible and mobile scroll safe',strpos($css,'.taskShortcut:focus-visible')!==false&&strpos($css,'.taskShortcuts{display:flex')!==false&&strpos($css,'overflow-x:auto')!==false);
shortcutCheck('overdue shortcut has distinct urgency styling',strpos($css,'.taskShortcut[data-task-filter="overdue"].active')!==false);
$all=$page."\n".$pipeline."\n".$css;
shortcutCheck('presentation shortcut does not mutate task persistence routing or analytics',strpos($all,"create_task")===false&&stripos($all,'set_working')===false&&stripos($all,'routing_bonus')===false&&stripos($all,'metrika')===false&&strpos($all,'LeadDestination')===false);

echo "\n--------------------------\nTOTAL ".($passed+$failed)." | PASS {$passed} | FAIL {$failed}\n";
exit($failed?1:0);
