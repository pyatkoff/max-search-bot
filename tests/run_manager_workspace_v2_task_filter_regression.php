<?php

declare(strict_types=1);
require_once dirname(__DIR__).'/services/ManagerLeadInboxService.php';
$root=dirname(__DIR__);
$workspace=(string)file_get_contents($root.'/manager/index.php');
$core=(string)file_get_contents($root.'/manager/assets/workspace-v2.js');
$filters=(string)file_get_contents($root.'/manager/assets/workspace-v2-filters.js');
$pipeline=(string)file_get_contents($root.'/manager/assets/workspace-v2-pipeline.js');
$inbox=(string)file_get_contents($root.'/manager/assets/workspace-v2-inbox.js');
$inboxCss=(string)file_get_contents($root.'/manager/assets/workspace-v2-inbox.css');
$api=(string)file_get_contents($root.'/manager/pipeline-api.php');
$projection=(string)file_get_contents($root.'/services/ManagerLeadInboxService.php');
$taskService=(string)file_get_contents($root.'/services/LeadTaskService.php');
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
tfCheck('unfiltered manager work queue keeps canonical operational ordering at API boundary',strpos($api,"if(\$queue==='mine'&&trim(\$taskFilter)==='')\$rows=ManagerLeadInboxService::sortOperational(\$rows)")!==false);
tfCheck('explicit task filters reuse canonical operational ordering',strpos($projection,"return \$taskFilter===''?\$filtered:self::sortOperational(\$filtered);")!==false);
tfCheck('LeadTaskService owns operational rank and state business rule',strpos($taskService,'public static function operationalRank')!==false&&strpos($taskService,'public static function operationalState')!==false&&strpos($projection,'public static function operationalTaskRank')===false&&strpos($projection,'public static function operationalTaskState')===false);
tfCheck('inbox projection delegates open-lead task semantics to LeadTaskService',strpos($taskService,'public static function operationalProjection')!==false&&strpos($projection,'LeadTaskService::operationalProjection($rowsForLead)')!==false&&strpos($projection,'LeadTaskService::operationalProjection([])')!==false);
tfCheck('closed sales outcomes are demoted out of operational urgency without deleting task facts',strpos($projection,"\$closedOutcome=\$row['lead_outcome']!=='open'")!==false&&strpos($projection,"\$row['operational_task_due_state']=\$closedOutcome?'closed':\$operational['due_state']")!==false&&strpos($projection,"\$row['operational_task_rank']=\$closedOutcome?4:\$operational['rank']")!==false&&strpos($projection,"\$row['next_task_title']=\$task['title']??null")!==false);
tfCheck('manager mine list groups canonical urgency into visible task sections',strpos($inbox,'function taskSectionMeta(c)')!==false&&strpos($inbox,"label:'Просрочено'")!==false&&strpos($inbox,"label:'Сегодня'")!==false&&strpos($inbox,"label:'Запланировано'")!==false&&strpos($inbox,"label:'Без срока'")!==false&&strpos($inbox,"label:'Без задачи'")!==false);
tfCheck('closed leads have a distinct non-actionable queue section',strpos($inbox,"if(due==='closed')return{key:'closed',label:'Закрытые лиды'}")!==false);
tfCheck('task section labels appear only for unfiltered manager work queue',strpos($inbox,"showTaskSections=S.queue==='mine'&&!String(S.leadTaskFilter||'').trim()")!==false&&strpos($inbox,'if(showTaskSections){const section=taskSectionMeta(c)')!==false);
tfCheck('task sections are accessible headings and do not replace lead cards',strpos($inbox,'header.className=`taskQueueSection ${section.key}`')!==false&&strpos($inbox,"header.setAttribute('role','heading')")!==false&&strpos($inbox,"header.setAttribute('aria-level','3')")!==false&&strpos($inbox,"frag.appendChild(el)")!==false);
tfCheck('task section presentation is compact sticky and responsive',strpos($inboxCss,'.taskQueueSection{position:sticky')!==false&&strpos($inboxCss,'.taskQueueSection.overdue')!==false&&strpos($inboxCss,'.taskQueueSection.today')!==false&&strpos($inboxCss,'@media(max-width:520px)')!==false&&strpos($inboxCss,'.taskQueueSection{padding:6px 7px;font-size:9px}')!==false);
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
tfCheck('no-task projection filter rejects closed outcomes',strpos($projection,"if(\$taskFilter==='none'&&(\$hasTask||(string)(\$row['lead_outcome']??'open')!=='open'))return false")!==false);
$rows=[
 ['id'=>1,'lead_outcome'=>'open','next_task_title'=>'Позвонить','next_task_overdue'=>1,'next_task_pinned'=>0,'next_task_due_state'=>'overdue','open_task_count'=>1,'operational_task_due_state'=>'overdue','operational_task_rank'=>0],
 ['id'=>2,'lead_outcome'=>'open','next_task_title'=>'Отправить варианты','next_task_overdue'=>0,'next_task_pinned'=>1,'next_task_due_state'=>'upcoming','open_task_count'=>1,'operational_task_due_state'=>'upcoming','operational_task_rank'=>2],
 ['id'=>3,'lead_outcome'=>'open','next_task_title'=>null,'next_task_overdue'=>0,'next_task_pinned'=>0,'next_task_due_state'=>'none','open_task_count'=>0,'operational_task_due_state'=>'none','operational_task_rank'=>3],
 ['id'=>4,'lead_outcome'=>'open','next_task_title'=>'Написать сегодня','next_task_overdue'=>0,'next_task_pinned'=>0,'next_task_due_state'=>'today','open_task_count'=>1,'operational_task_due_state'=>'today','operational_task_rank'=>1],
 ['id'=>5,'lead_outcome'=>'open','next_task_title'=>'Закреплённая будущая','next_task_overdue'=>0,'next_task_pinned'=>1,'next_task_due_state'=>'upcoming','open_task_count'=>2,'operational_task_due_state'=>'overdue','operational_task_rank'=>0],
 ['id'=>6,'lead_outcome'=>'won','next_task_title'=>null,'next_task_overdue'=>0,'next_task_pinned'=>0,'next_task_due_state'=>'none','open_task_count'=>0,'operational_task_due_state'=>'closed','operational_task_rank'=>4],
 ['id'=>7,'lead_outcome'=>'won','next_task_title'=>'Старая просроченная','next_task_overdue'=>1,'next_task_pinned'=>0,'next_task_due_state'=>'overdue','open_task_count'=>1,'operational_task_due_state'=>'closed','operational_task_rank'=>4,'operational_task_due_at_utc'=>'2026-09-01 08:00:00'],
 ['id'=>8,'lead_outcome'=>'lost','next_task_title'=>'Старая сегодня','next_task_overdue'=>0,'next_task_pinned'=>0,'next_task_due_state'=>'today','open_task_count'=>1,'operational_task_due_state'=>'closed','operational_task_rank'=>4,'operational_task_due_at_utc'=>'2026-09-02 12:00:00'],
 ['id'=>9,'lead_outcome'=>'won','next_task_title'=>'Закреплённая закрытая','next_task_overdue'=>0,'next_task_pinned'=>1,'next_task_due_state'=>'upcoming','open_task_count'=>1,'operational_task_due_state'=>'closed','operational_task_rank'=>4,'operational_task_due_at_utc'=>'2026-09-03 09:00:00'],
];
tfCheck('overdue filter keeps only open actionable leads',array_column(ManagerLeadInboxService::filter($rows,'','','overdue'),'id')===[1,5]);
tfCheck('today filter keeps only open actionable leads',array_column(ManagerLeadInboxService::filter($rows,'','','today'),'id')===[4]);
tfCheck('action filter excludes closed leads with stale urgent tasks',array_column(ManagerLeadInboxService::filter($rows,'','','action'),'id')===[1,5,4]);
tfCheck('planned filter excludes closed cleanup tasks',array_column(ManagerLeadInboxService::filter($rows,'','','planned'),'id')===[2]);
tfCheck('pinned filter keeps closed cleanup tasks discoverable',array_column(ManagerLeadInboxService::filter($rows,'','','pinned'),'id')===[5,2,9]);
tfCheck('no-task filter returns only open leads missing follow-up work',array_column(ManagerLeadInboxService::filter($rows,'','','none'),'id')===[3]);
tfCheck('unknown task filter fails open',count(ManagerLeadInboxService::filter($rows,'','','unexpected'))===9);
tfCheck('canonical operational state maps overdue today soon and no-action buckets',LeadTaskService::operationalState('overdue',true)==='overdue'&&LeadTaskService::operationalState('today',true)==='today'&&LeadTaskService::operationalState('upcoming',true)==='soon'&&LeadTaskService::operationalState('unscheduled',true)==='soon'&&LeadTaskService::operationalState('none',false)==='none');
tfCheck('canonical operational rank maps overdue today soon and no-action buckets',LeadTaskService::operationalRank('overdue',true)===0&&LeadTaskService::operationalRank('today',true)===1&&LeadTaskService::operationalRank('upcoming',true)===2&&LeadTaskService::operationalRank('unscheduled',true)===2&&LeadTaskService::operationalRank('none',false)===3);
$operational=LeadTaskService::operationalProjection([
 ['due_at_utc'=>'2026-09-03 10:00:00','overdue'=>0],
 ['due_at_utc'=>'2026-09-01 08:00:00','overdue'=>1],
]);
tfCheck('canonical operational projection selects overdue work across open tasks',($operational['due_state']??'')==='overdue'&&($operational['rank']??null)===0&&($operational['due_at_utc']??'')==='2026-09-01 08:00:00');
tfCheck('manager work queue sorts overdue today soon no-action then closed',array_column(ManagerLeadInboxService::sortOperational([$rows[6],$rows[2],$rows[1],$rows[3],$rows[0]]),'id')===[1,4,2,3,7]);
tfCheck('operational sort fallback delegates rank to canonical task owner',strpos($projection,"LeadTaskService::operationalRank((string)(\$aRow['next_task_due_state']??'')")!==false&&array_column(ManagerLeadInboxService::sortOperational([['id'=>3,'next_task_title'=>null],['id'=>2,'next_task_title'=>'Позже','next_task_due_state'=>'upcoming'],['id'=>1,'next_task_title'=>'Сейчас','next_task_due_state'=>'overdue']]),'id')===[1,2,3]);
$dueRows=[
 ['id'=>1,'next_task_title'=>'Позже просрочено','operational_task_rank'=>0,'operational_task_due_at_utc'=>'2026-09-01 08:00:00'],
 ['id'=>2,'next_task_title'=>'Раньше просрочено','operational_task_rank'=>0,'operational_task_due_at_utc'=>'2026-09-01 07:00:00'],
 ['id'=>3,'next_task_title'=>'Сегодня позже','operational_task_rank'=>1,'operational_task_due_at_utc'=>'2026-09-01 15:00:00'],
 ['id'=>4,'next_task_title'=>'Сегодня раньше','operational_task_rank'=>1,'operational_task_due_at_utc'=>'2026-09-01 12:00:00'],
 ['id'=>5,'next_task_title'=>'Без срока','operational_task_rank'=>2,'operational_task_due_at_utc'=>null],
 ['id'=>6,'next_task_title'=>'Со сроком','operational_task_rank'=>2,'operational_task_due_at_utc'=>'2026-09-03 09:00:00'],
];
tfCheck('manager work queue orders equal urgency by earliest operational due time and leaves unscheduled last in bucket',array_column(ManagerLeadInboxService::sortOperational($dueRows),'id')===[2,1,4,3,6,5]);
echo "\n--------------------------\nTOTAL ".($passed+$failed)." | PASS {$passed} | FAIL {$failed}\n";exit($failed?1:0);