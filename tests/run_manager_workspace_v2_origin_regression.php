<?php

declare(strict_types=1);

require_once dirname(__DIR__).'/services/ManagerLeadInboxService.php';

$root=dirname(__DIR__);
$api=(string)file_get_contents($root.'/manager/pipeline-api.php');
$conversationService=(string)file_get_contents($root.'/services/ManagerConversationService.php');
$leadCard=(string)file_get_contents($root.'/manager/assets/workspace-v2-lead-card.js');
$inbox=(string)file_get_contents($root.'/manager/assets/workspace-v2-inbox.js');
$kanban=(string)file_get_contents($root.'/manager/assets/workspace-v2-kanban.js');
$passed=0;$failed=0;
function originCheck(string $name,bool $ok):void{global$passed,$failed;if($ok){echo "PASS  {$name}\n";$passed++;return;}echo "FAIL  {$name}\n";$failed++;}

originCheck('project label prefers human-readable project name',ManagerLeadInboxService::projectLabel(['project_name'=>'AnyTour','project_key'=>'anytour'])==='AnyTour');
originCheck('project label falls back to project key',ManagerLeadInboxService::projectLabel(['project_name'=>'','project_key'=>'anytour'])==='anytour');
originCheck('origin label prefers explicit entry channel over source',ManagerLeadInboxService::originLabel(['channel'=>'max','entry_channel'=>'max_2','source_name'=>'project:legacy','project_name'=>'Duplicate project'])==='MAX · max_2');
originCheck('origin label uses short TG platform label for entry attribution',ManagerLeadInboxService::originLabel(['channel'=>'telegram','entry_channel'=>'tg_1','source_name'=>'project:legacy'])==='TG · tg_1');
originCheck('origin label still combines channel and source suffix when entry is absent',ManagerLeadInboxService::originLabel(['channel'=>'max','source_name'=>'project:max_2','project_name'=>'Duplicate project'])==='MAX · max_2');
originCheck('origin label falls back to project when source missing',ManagerLeadInboxService::originLabel(['channel'=>'telegram','source_name'=>'','project_name'=>'tg_1'])==='TG · tg_1');
originCheck('conversation list and detail select entry attribution fields',substr_count($conversationService,'c.entry_channel,c.attribution_region,c.attribution_campaign')>=2);
originCheck('detail API exposes canonical project and origin labels',strpos($api,"'origin_label'=>ManagerLeadInboxService::originLabel(\$c)")!==false&&strpos($api,"'project_label'=>ManagerLeadInboxService::projectLabel(\$c)")!==false);
originCheck('inbox card renders customer plus project and separate origin',strpos($inbox,'c.project_label||c.project_name')!==false&&strpos($inbox,'${esc(name)}${project?` · ${esc(project)}`')!==false&&strpos($inbox,'c.origin_label||statusText(c.status)')!==false);
originCheck('kanban card renders customer plus project and separate origin',strpos($kanban,'c.project_label||c.project_name')!==false&&strpos($kanban,'${esc(name)}${project?` · ${esc(project)}`')!==false&&strpos($kanban,'c.origin_label||')!==false);
originCheck('lead card header renders project before channel/source origin',strpos($leadCard,'source.project_label||source.project')!==false&&strpos($leadCard,"[project,origin].filter(Boolean).join(' · ')")!==false&&strpos($leadCard,'leadHeroSource')!==false);
originCheck('lead card service details keep project and origin explicit',strpos($leadCard,"detailRow('Проект',project)")!==false&&strpos($leadCard,"detailRow('Источник',origin)")!==false);
originCheck('raw detail metadata remains available',strpos($api,"'project'=>\$c['project_name']")!==false&&strpos($api,"'source'=>\$c['source_name']")!==false&&strpos($api,"'channel'=>\$c['channel']")!==false&&strpos($api,"'entry_channel'=>\$c['entry_channel']")!==false&&strpos($api,"'attribution_region'=>\$c['attribution_region']")!==false&&strpos($api,"'attribution_campaign'=>\$c['attribution_campaign']")!==false);

echo "\n--------------------------\nTOTAL ".($passed+$failed)." | PASS {$passed} | FAIL {$failed}\n";
exit($failed?1:0);
