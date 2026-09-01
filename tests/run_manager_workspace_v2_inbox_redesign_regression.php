<?php

declare(strict_types=1);

$root=dirname(__DIR__);
$page=(string)file_get_contents($root.'/manager/index.php');
$core=(string)file_get_contents($root.'/manager/assets/workspace-v2.js');
$js=(string)file_get_contents($root.'/manager/assets/workspace-v2-inbox.js');
$filters=(string)file_get_contents($root.'/manager/assets/workspace-v2-filters.js');
$pipeline=(string)file_get_contents($root.'/manager/assets/workspace-v2-pipeline.js');
$pipelineApi=(string)file_get_contents($root.'/manager/pipeline-api.php');
$filterService=(string)file_get_contents($root.'/services/ManagerWorkspaceFilterService.php');
$leadInbox=(string)file_get_contents($root.'/services/ManagerLeadInboxService.php');
$sourceHandling=(string)file_get_contents($root.'/services/SourceHandlingService.php');
$css=(string)file_get_contents($root.'/manager/assets/workspace-v2-inbox.css');
$passed=0;$failed=0;
function inboxCheck(string $name,bool $ok):void{global$passed,$failed;if($ok){echo "PASS  {$name}\n";$passed++;return;}echo "FAIL  {$name}\n";$failed++;}
function inboxAssetLoaded(string $html,string $file):bool{return strpos($html,'assets/'.$file)!==false||strpos($html,"workspaceAsset('{$file}')")!==false;}

inboxCheck('workspace loads dedicated inbox stylesheet',inboxAssetLoaded($page,'workspace-v2-inbox.css'));
inboxCheck('workspace loads dedicated filter behavior module',inboxAssetLoaded($page,'workspace-v2-filters.js'));
inboxCheck('search is primary and advanced filters are collapsible',strpos($page,'id="leadSearch"')!==false&&strpos($page,'id="filtersToggle"')!==false&&strpos($page,'id="filtersPanel" class="filtersPanel hidden"')!==false);
inboxCheck('advanced filters preserve stage tag outcome and task semantics',strpos($page,'id="leadStageFilter"')!==false&&strpos($page,'id="leadTagFilter"')!==false&&strpos($page,'id="leadOutcomeFilter"')!==false&&strpos($page,'id="leadTaskFilter"')!==false);
inboxCheck('advanced filters expose project source and assigned manager',strpos($page,'id="leadProjectFilter"')!==false&&strpos($page,'id="leadSourceFilter"')!==false&&strpos($page,'id="leadManagerFilter"')!==false&&strpos($page,'Не назначен')!==false);
inboxCheck('filter badge and reset are explicit',strpos($page,'id="filtersCount"')!==false&&strpos($page,'id="clearFilters"')!==false&&strpos($filters,'activeFilterCount')!==false&&strpos($filters,"S.leadProjectFilter='*'")!==false&&strpos($filters,"S.leadManagerFilter=''")!==false);
inboxCheck('directory filters persist in workspace session state',strpos($filters,'project:S.leadProjectFilter')!==false&&strpos($filters,'source:Number(S.leadSourceFilter')!==false&&strpos($filters,'manager:S.leadManagerFilter')!==false&&strpos($filters,'saved.project')!==false&&strpos($filters,'saved.source')!==false&&strpos($filters,'saved.manager')!==false);
inboxCheck('source choices follow selected project',strpos($filters,'function sourceRowsForProject')!==false&&strpos($filters,"String(s.project_key||'')===project")!==false&&strpos($filters,'S.leadSourceFilter=0')!==false);
inboxCheck('unknown source is a first-class filter choice',strpos($filters,'<option value="-1">⚠ Источник не определён</option>')!==false&&strpos($filters,'sourceFilter!==-1')!==false&&strpos($filters,'current===-1')!==false);
inboxCheck('unknown source filter matches only conversations without source id',strpos($pipelineApi,'$sourceId===-1')!==false&&strpos($pipelineApi,"(int)(\$r['source_id']??0)<=0")!==false);
inboxCheck('unknown source is visibly marked on cards and details',strpos($leadInbox,'⚠ Источник не определён')!==false&&strpos($leadInbox,"\$sourceId<=0")!==false);
inboxCheck('unknown source keeps AI as the safe initial behavior',strpos($sourceHandling,"COALESCE(s.handling_mode,'ai') AS handling_mode")!==false&&strpos($sourceHandling,"mode===self::AI")!==false);
inboxCheck('manager picker is admin-only in UI',strpos($filters,"const isAdmin=S.manager?.role==='admin'")!==false&&strpos($filters,"managerWrap.classList.toggle('hidden',!isAdmin)")!==false);
inboxCheck('workspace loads read-only filter directory alongside pipeline catalog',strpos($core,"pipe('filter_options')")!==false&&strpos($core,'S.filterSources')!==false&&strpos($core,'S.filterManagers')!==false);
inboxCheck('inbox sends project source and current-owner filters',strpos($js,"project_key:S.leadProjectFilter||'*'")!==false&&strpos($js,'source_id:Number(S.leadSourceFilter||0)')!==false&&strpos($js,"manager_filter:S.manager?.role==='admin'")!==false);
inboxCheck('pipeline API gates manager filtering to admin role',strpos($pipelineApi,'$managerFilter=$isAdmin?')!==false&&strpos($pipelineApi,'manager_filter')!==false);
inboxCheck('pipeline API applies concrete source id without changing routing',strpos($pipelineApi,"data['source_id']")!==false&&strpos($pipelineApi,"r['source_id']")!==false&&strpos($pipelineApi,'===$sourceId')!==false);
inboxCheck('filter directory limits sources to accessible projects',strpos($filterService,'ProjectAccessService::projectsForManager')!==false&&strpos($filterService,'WHERE s.is_active=1 AND p.project_key IN')!==false);
inboxCheck('filter directory exposes managers only to admins',strpos($filterService,'ManagerAuthService::isAdmin($manager)?ManagerConversationService::filterManagers')!==false);
inboxCheck('lead cards use compact identity trip preview action hierarchy',strpos($js,'leadPrimary')!==false&&strpos($js,'leadAvatar')!==false&&strpos($js,'leadTrip')!==false&&strpos($js,'leadPreview')!==false&&strpos($js,'leadActionRow')!==false);
inboxCheck('lead cards keep urgency unread stage outcome and task evidence',strpos($js,'unreadBadge')!==false&&strpos($js,'leadStageCompact')!==false&&strpos($js,'leadTaskCompact')!==false&&strpos($js,'leadWaitCompact')!==false&&strpos($js,'leadOutcomeCompact')!==false);
inboxCheck('lead cards expose last activity without another backend projection',strpos($js,'function formatLastActivity')!==false&&strpos($js,"c.last_message_at||c.started_at||''")!==false&&strpos($js,'leadActivityTime')!==false&&strpos($js,'Последняя активность')!==false);
inboxCheck('activity formatting is timezone-neutral and compact',strpos($js,'`${m[3]}.${m[2]} · ${m[4]}:${m[5]}`')!==false&&strpos($js,"new Date(value.replace(' ','T')")!==false);
inboxCheck('opened conversations can clear their local unread badge without reloading the list',strpos($js,'function markRead(id=S.current)')!==false&&strpos($js,"el.querySelector('.unreadBadge')?.remove()")!==false&&strpos($js,'markRead,waitUrgency')!==false);
inboxCheck('ordinary open outcome is not rendered as permanent visual noise',strpos($js,"outcome==='won'||outcome==='lost'")!==false);
inboxCheck('inbox refresh still preserves scroll after stability fix',strpos($js,'scrollTop=box.scrollTop')!==false&&strpos($js,'if(preserveScroll)box.scrollTop=scrollTop')!==false);
inboxCheck('mobile inbox has dedicated compact treatment',strpos($css,'@media(max-width:520px)')!==false&&strpos($css,'.inboxSearchRow')!==false&&strpos($css,'.filtersToggle:before')!==false&&strpos($css,'.leadPrimary')!==false);
inboxCheck('redesign does not mutate shifts metrika or routing bonuses',stripos($js.$filters.$pipeline.$pipelineApi.$filterService.$leadInbox.$css,'set_working')===false&&stripos($js.$filters.$pipeline.$filterService.$leadInbox.$css,'metrika')===false&&stripos($js.$filters.$pipeline.$filterService.$leadInbox.$css,'yclid')===false&&stripos($filterService.$leadInbox,'bonus')===false);

echo "\n--------------------------\nTOTAL ".($passed+$failed)." | PASS {$passed} | FAIL {$failed}\n";
exit($failed?1:0);
