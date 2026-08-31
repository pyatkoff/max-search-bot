<?php

declare(strict_types=1);
require_once dirname(__DIR__).'/services/ManagerLeadInboxService.php';
$root=dirname(__DIR__);
$workspace=(string)file_get_contents($root.'/manager/index.php');
$core=(string)file_get_contents($root.'/manager/assets/workspace-v2.js');
$pipeline=(string)file_get_contents($root.'/manager/assets/workspace-v2-pipeline.js');
$inbox=(string)file_get_contents($root.'/manager/assets/workspace-v2-inbox.js');
$api=(string)file_get_contents($root.'/manager/pipeline-api.php');
$passed=0;$failed=0;
function tfCheck(string $name,bool $ok):void{global$passed,$failed;if($ok){echo "PASS  {$name}\n";$passed++;}else{echo "FAIL  {$name}\n";$failed++;}}
tfCheck('workspace exposes task filter',strpos($workspace,'id="leadTaskFilter"')!==false&&strpos($workspace,'Просроченные')!==false&&strpos($workspace,'Сегодня')!==false&&strpos($workspace,'Запланированные')!==false&&strpos($workspace,'В приоритете')!==false&&strpos($workspace,'Без задачи')!==false);
tfCheck('workspace exposes today task shortcut',strpos($workspace,'data-task-filter="today"')!==false&&strpos($workspace,'● Сегодня')!==false);
tfCheck('workspace exposes planned task shortcut',strpos($workspace,'data-task-filter="planned"')!==false&&strpos($workspace,'🗓 Запланировано')!==false);
tfCheck('workspace exposes pinned task shortcut',strpos($workspace,'data-task-filter="pinned"')!==false&&strpos($workspace,'📌 В приоритете')!==false);
tfCheck('workspace exposes no-task shortcut',strpos($workspace,'data-task-filter="none"')!==false&&strpos($workspace,'＋ Без задачи')!==false);
tfCheck('task filter state and binding exist',strpos($core,"leadTaskFilter:''")!==false&&strpos($pipeline,"S.leadTaskFilter=$('leadTaskFilter').value")!==false);
tfCheck('inbox sends task filter',strpos($inbox,'lead_task_filter:S.leadTaskFilter')!==false);
tfCheck('pipeline API passes task filter to projection',strpos($api,"(string)(\$data['lead_task_filter']??'')")!==false);
tfCheck('inbox filters and search persist for current browser session',strpos($pipeline,"FILTER_STORAGE_KEY='anytour.manager.workspace.filters.v1'")!==false&&strpos($pipeline,'sessionStorage.getItem(FILTER_STORAGE_KEY)')!==false&&strpos($pipeline,'sessionStorage.setItem(FILTER_STORAGE_KEY')!==false&&strpos($pipeline,"S.leadSearch=String(saved.search||'').slice(0,200)")!==false);
tfCheck('restored search is reflected in visible input',strpos($pipeline,"if(search)search.value=S.leadSearch")!==false);
tfCheck('clear filters also clears restored search state',strpos($pipeline,"S.leadTaskFilter='';S.leadSearch='';renderFilters();saveFilterState()")!==false);
$rows=[
 ['id'=>1,'lead_outcome'=>'open','next_task_title'=>'Позвонить','next_task_overdue'=>1,'next_task_pinned'=>0,'next_task_due_state'=>'overdue'],
 ['id'=>2,'lead_outcome'=>'open','next_task_title'=>'Отправить варианты','next_task_overdue'=>0,'next_task_pinned'=>1,'next_task_due_state'=>'future'],
 ['id'=>3,'lead_outcome'=>'open','next_task_title'=>null,'next_task_overdue'=>0,'next_task_pinned'=>0,'next_task_due_state'=>'none'],
 ['id'=>4,'lead_outcome'=>'open','next_task_title'=>'Написать сегодня','next_task_overdue'=>0,'next_task_pinned'=>0,'next_task_due_state'=>'today'],
];
tfCheck('overdue filter is deterministic',array_column(ManagerLeadInboxService::filter($rows,'','','overdue'),'id')===[1]);
tfCheck('today filter is deterministic',array_column(ManagerLeadInboxService::filter($rows,'','','today'),'id')===[4]);
tfCheck('action filter includes overdue and today only',array_column(ManagerLeadInboxService::filter($rows,'','','action'),'id')===[1,4]);
tfCheck('planned filter excludes overdue',array_column(ManagerLeadInboxService::filter($rows,'','','planned'),'id')===[2,4]);
tfCheck('pinned filter is deterministic',array_column(ManagerLeadInboxService::filter($rows,'','','pinned'),'id')===[2]);
tfCheck('no-task filter is deterministic',array_column(ManagerLeadInboxService::filter($rows,'','','none'),'id')===[3]);
tfCheck('unknown task filter fails open',count(ManagerLeadInboxService::filter($rows,'','','unexpected'))===4);
echo "\n--------------------------\nTOTAL ".($passed+$failed)." | PASS {$passed} | FAIL {$failed}\n";exit($failed?1:0);