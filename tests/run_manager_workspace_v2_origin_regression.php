<?php

declare(strict_types=1);

require_once dirname(__DIR__).'/services/ManagerLeadInboxService.php';

$root=dirname(__DIR__);
$api=(string)file_get_contents($root.'/manager/pipeline-api.php');
$leadCard=(string)file_get_contents($root.'/manager/assets/workspace-v2-lead-card.js');
$passed=0;$failed=0;
function originCheck(string $name,bool $ok):void{global$passed,$failed;if($ok){echo "PASS  {$name}\n";$passed++;return;}echo "FAIL  {$name}\n";$failed++;}

originCheck('origin label combines channel and source suffix',ManagerLeadInboxService::originLabel(['channel'=>'max','source_name'=>'project:max_2','project_name'=>'Duplicate project'])==='MAX · max_2');
originCheck('origin label falls back to project when source missing',ManagerLeadInboxService::originLabel(['channel'=>'telegram','source_name'=>'','project_name'=>'tg_1'])==='TELEGRAM · tg_1');
originCheck('detail API exposes canonical origin owner',strpos($api,"'origin_label'=>ManagerLeadInboxService::originLabel(\$c)")!==false);
originCheck('lead card renders one human-readable source field',substr_count($leadCard,'source.origin_label')>=1&&strpos($leadCard,'leadHeroSource')!==false&&strpos($leadCard,"tripField('Проект',source.project)")===false&&strpos($leadCard,"tripField('Канал',source.channel)")===false&&strpos($leadCard,'source.project')===false&&strpos($leadCard,'source.channel')===false);
originCheck('raw detail metadata remains available without UI duplication',strpos($api,"'project'=>\$c['project_name']")!==false&&strpos($api,"'source'=>\$c['source_name']")!==false&&strpos($api,"'channel'=>\$c['channel']")!==false);

echo "\n--------------------------\nTOTAL ".($passed+$failed)." | PASS {$passed} | FAIL {$failed}\n";
exit($failed?1:0);
