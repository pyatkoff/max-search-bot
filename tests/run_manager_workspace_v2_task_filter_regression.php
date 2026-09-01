<?php

declare(strict_types=1);
require_once dirname(__DIR__).'/services/ManagerLeadInboxService.php';
$root=dirname(__DIR__);
$workspace=(string)file_get_contents($root.'/manager/index.php');
$core=(string)file_get_contents($root.'/manager/assets/workspace-v2.js');
$filters=(string)file_get_contents($root.'/manager/assets/workspace-v2-filters.js');
$pipeline=(string)file_get_contents($root.'/manager/assets/workspace-v2-pipeline.js');
$inbox=(string)file_get_contents($root.'/manager/assets/workspace-v2-inbox.js');
$api=(string)file_get_contents($root.'/manager/pipeline-api.php');
$passed=0;$failed=0;
function tfCheck(string $name,bool $ok):void{global$passed,$failed;if($ok){echo "PASS  {$name}\n";$passed++;}else{echo "FAIL  {$name}\n";$failed++;}}
tfCheck('workspace exposes task filter',strpos($workspace,'id="leadTaskFilter"')!==false&&strpos($workspace,'Просроченные')!==false&&strpos($workspace,'Сегодня')!==false&&strpos($workspace,'Запланированные')!==false&&strpos($workspace,'В приоритете')!==false&&strpos($workspace,'Без задачи')!==false);
tfCheck('workspace loads dedicated filter module',strpos($workspace,"workspace-v2-filters.js")!==false&&strpos($core,'WorkspaceV2Filters?.render()')!==false&&strpos($core,'WorkspaceV2Filters?.bind()')!==false);
tfCheck('workspace exposes today task shortcut',strpos($workspace,'data-task-filter="today"')!==false&&strpos($workspace,'● Сегодня')!==false);
tfCheck('workspace exposes planned task shortcut',strpos($workspace,'data-task-filter="planned"')!==false&&strpos($workspace,'🗓 Запланировано')!==false);
tfCheck('workspace exposes pinned task shortcut',strpos($workspace,'data-task-filter="pinned"')!==false&&strpos($workspace,'📌 В приоритете')!==false);
tfCheck('workspace exposes no-task shortcut',strpos($workspace,'data-task-filter="none"')!==false&&strpos($workspace,'＋ Без задачи')!==false);
tfCheck('task filter state and binding exist',strpos($core,"leadTaskFilter:''")!==false&&strpos($filters,"S.leadTaskFilter=$('leadTaskFilter').value")!==false);
tfCheck('all non-empty task filter entry points switch to manager work queue',strpos($filters,"function ensureTaskQueue(){if(S.leadTaskFilter)window.WorkspaceV2Inbox?.setQueue('mine',{reload:false})}")!==false&&substr_count($filters,'ensureTaskQueue();')>=3&&strpos($filters,"$('leadTaskFilter').onchange=async()=>{S.leadTaskFilter=$('leadTaskFilter').value;ensureTaskQueue();await apply()}")!==false);
tfCheck('restored task filter also restores manager work queue before first list load',strpos($filters,'if(search)search.value=S.leadSearch;ensureTaskQueue();syncFilterUi()')!==false);
tfCheck('pipeline module no longer owns inbox filter lifecycle',strpos($pipeline,'FILTER_STORAGE_KEY')===false&&strpos($pipeline,'setTaskShortcut')===false&&strpos($pipeline,'bindFilters')===false);
tfCheck('inbox sends task filter',strpos($inbox,'lead_task_filter:S.leadTaskFilter')!==false);
tfCheck('pipeline API passes task filter to projection',strpos($api,"(string)(\$data['lead_task_filter']??'')")!==false);
tfCheck('manager work queue gets operational task ordering only without explicit task filter',strpos($api,"if(\$queue==='mine'&&trim(\$taskFilter)==='')\$rows=ManagerLeadInboxService::sortOperational(\$rows)")!==false);
tfCheck('inbox filters and search persist for current browser session',strpos($filters,"FILTER_STORAGE_KEY='anytour.manager.workspace.filters.v1'")!==false&&strpos($filters,'sessionStorage.getItem(FILTER_STORAGE_KEY)')!==false&&strpos($filters,'sessionStorage.setItem(FILTER_STORAGE_KEY')!==false&&strpos($filters,"S.leadSearch=String(saved.search||'').slice(0,200)")!==false);
tfCheck('restored search is reflected in visible input',strpos($filters,"if(search)search.value=S.leadSearch")!==false);
tfCheck('pipeline catalog outage is explicit instead of looking like an empty catalog',strpos($filters,"setCatalogSelectState(stage,false,'Этапы временно недоступны')")!==false&&strpos($filters,"setCatalogSelectState(tag,false,'Теги временно недоступны')")!==false&&strpos($filters,"setCatalogSelectState(outcome,false,'Исходы временно недоступны')")!==false);
tfCheck('unavailable pipeline catalog filters are disabled without disabling task filter',strpos($filters,'select.disabled=!ready')!==false&&strpos($filters,"task.value=S.leadTaskFilter")!==false&&strpos($filters,"setCatalogSelectState(task")===false);
tfCheck('saved pipeline filters reconcile against the current catalog before inbox load',strpos($filters,'function reconcileCatalogFilters(stages,tags,outcomes)')!==false&&strpos($filters,"S.leadStageFilter&&!stages.some")!==false&&strpos($filters,'Number(S.leadTagFilter||0)>0&&!tags.some')!==false&&strpos($filters,"S.leadOutcomeFilter&&!Object.prototype.hasOwnProperty.call(outcomes")!==false&&strpos($filters,'reconcileCatalogFilters(stages,tags,outcomes);if(stageReady)')!==false);
tfCheck('stale catalog filters fail open and persist the repaired browser state',strpos($filters,"S.leadStageFilter='';changed=true")!==false&&strpos($filters,'S.leadTagFilter=0;changed=true')!==false&&strpos($filters,"S.leadOutcomeFilter='';changed=true")!==false&&strpos($filters,'if(changed)saveFilterState();return changed')!==false);
tfCheck('catalog reconciliation does not clear task or search filters',strpos($filters,'function reconcileCatalogFilters(stages,tags,outcomes)')!==false&&strpos($filters,"S.leadTaskFilter='';changed=true")===false&&strpos($filters,"S.leadSearch='';changed=true")===false);
tfCheck('clear filters also clears restored search state',strpos($filters,"S.leadTaskFilter='';S.leadSearch='';render();saveFilterState()")!==false);
tfCheck('open leads without a task are visible in the inbox row',strpos($inbox,"taskMissing=!taskTitle&&outcome==='open'")!==false&&strpos($inbox,'Следующее действие не назначено')!==false&&strpos($inbox,'＋ Без задачи')!==false);
tfCheck('closed outcomes do not get a false missing-task signal',strpos($inbox,"taskMissing=!taskTitle&&outcome==='open'")!==false);
$rows=[
 ['id'=>1,'lead_outcome'=>'open','next_task_title'=>'Позвонить','next_task_overdue'=>1,'next_task_pinned'=>0,'next_task_due_state'=>'overdue','operational_task_rank'=>0],
 ['id'=>2,'lead_outcome'=>'open','next_task_title'=>'Отправить варианты','next_task_overdue'=>0,'next_task_pinned'=>1,'next_task_due_state'=>'future','operational_task_rank'=>2],
 ['id'=>3,'lead_outcome'=>'open','next_task_title'=>null,'next_task_overdue'=>0,'next_task_pinned'=>0,'next_task_due_state'=>'none','operational_task_rank'=>3],
 ['id'=>4,'lead_outcome'=>'open','next_task_title'=>'Написать сегодня','next_task_overdue'=>0,'next_task_pinned'=>0,'next_task_due_state'=>'today','operational_task_rank'=>1],
];
tfCheck('overdue filter is deterministic',array_column(ManagerLeadInboxService::filter($rows,'','','overdue'),'id')===[1]);
tfCheck('today filter is deterministic',array_column(ManagerLeadInboxService::filter($rows,'','','today'),'id')===[4]);
tfCheck('action filter includes overdue and today only',array_column(ManagerLeadInboxService::filter($rows,'','','action'),'id')===[1,4]);
tfCheck('planned filter excludes overdue',array_column(ManagerLeadInboxService::filter($rows,'','','planned'),'id')===[2,4]);
tfCheck('pinned filter is deterministic',array_column(ManagerLeadInboxService::filter($rows,'','','pinned'),'id')===[2]);
tfCheck('no-task filter is deterministic',array_column(ManagerLeadInboxService::filter($rows,'','','none'),'id')===[3]);
tfCheck('unknown task filter fails open',count(ManagerLeadInboxService::filter($rows,'','','unexpected'))===4);
tfCheck('operational state maps overdue today soon and no-action buckets',ManagerLeadInboxService::operationalTaskState('overdue',true)==='overdue'&&ManagerLeadInboxService::operationalTaskState('today',true)==='today'&&ManagerLeadInboxService::operationalTaskState('upcoming',true)==='soon'&&ManagerLeadInboxService::operationalTaskState('unscheduled',true)==='soon'&&ManagerLeadInboxService::operationalTaskState('none',false)==='none');
tfCheck('manager work queue sorts overdue today soon then no next action',array_column(ManagerLeadInboxService::sortOperational([$rows[2],$rows[1],$rows[3],$rows[0]]),'id')===[1,4,2,3]);
tfCheck('operational sort fallback derives rank without projection metadata',array_column(ManagerLeadInboxService::sortOperational([['id'=>3,'next_task_title'=>null],['id'=>2,'next_task_title'=>'Позже','next_task_due_state'=>'upcoming'],['id'=>1,'next_task_title'=>'Сейчас','next_task_due_state'=>'overdue']]),'id')===[1,2,3]);
echo "\n--------------------------\nTOTAL ".($passed+$failed)." | PASS {$passed} | FAIL {$failed}\n";exit($failed?1:0);