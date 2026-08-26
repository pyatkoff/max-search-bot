<?php

declare(strict_types=1);
require_once __DIR__.'/../services/TrafficAttributionService.php';

$passed=0;$failed=0;
function mpCheck(string $name,$actual,$expected):void{global $passed,$failed;if($actual===$expected){echo "PASS  {$name}\n";$passed++;return;}echo "FAIL  {$name}\n  expected=".var_export($expected,true)."\n  actual=".var_export($actual,true)."\n";$failed++;}

$entry=TrafficAttributionService::parseStartPayload('entry_max_1');
mpCheck('entry-only payload identifies MAX entry channel',$entry['entry_channel']??null,'max_1');
mpCheck('entry-only payload has no invented yclid',$entry['yclid']??null,'');

$full=TrafficAttributionService::parseStartPayload('123456789_entry_max_2_region_39_campaign_710891647');
mpCheck('combined payload keeps yclid',$full['yclid']??null,'123456789');
mpCheck('combined payload keeps entry channel',$full['entry_channel']??null,'max_2');
mpCheck('combined payload keeps region',$full['region_id']??null,'39');
mpCheck('combined payload keeps campaign',$full['campaign_id']??null,'710891647');

$legacy=TrafficAttributionService::parseStartPayload('123456789_region_5_campaign_77');
mpCheck('legacy payload yclid remains supported',$legacy['yclid']??null,'123456789');
mpCheck('legacy payload region remains supported',$legacy['region_id']??null,'5');
mpCheck('legacy payload campaign remains supported',$legacy['campaign_id']??null,'77');

$url=TrafficAttributionService::buildMiniappUrl('https://max.ru/example_bot',['yclid'=>'123456789','entry_channel'=>'max_3','region_id'=>'4','campaign_id'=>'88']);
mpCheck('miniapp URL preserves entry channel',str_contains(rawurldecode($url),'123456789_entry_max_3_region_4_campaign_88'),true);

$base=dirname(__DIR__);
$migration=(string)file_get_contents($base.'/migrations/009_manager_priority_and_entry_attribution.sql');
$priority=(string)file_get_contents($base.'/services/ManagerPriorityService.php');
$push=(string)file_get_contents($base.'/services/ManagerPushService.php');
$admin=(string)file_get_contents($base.'/manager/admin.php');
$api=(string)file_get_contents($base.'/manager/api.php');
$dispatcher=(string)file_get_contents($base.'/services/IncomingUpdateDispatcher.php');

mpCheck('migration adds manager base priority',str_contains($migration,'ADD COLUMN priority INT NOT NULL DEFAULT 0'),true);
mpCheck('migration adds conversation entry channel',str_contains($migration,'ADD COLUMN entry_channel VARCHAR(64)'),true);
mpCheck('migration adds priority rules table',str_contains($migration,'CREATE TABLE IF NOT EXISTS manager_priority_rules'),true);
mpCheck('priority rules support entry channel',str_contains($priority,"'entry_channel'"),true);
mpCheck('priority scoring exposes base score',str_contains($priority,"'base'=>\$base"),true);
mpCheck('priority scoring records matched rule identity',str_contains($priority,"'rule_id'=>(int)\$rule['id']"),true);
mpCheck('priority scoring records matched bonus',str_contains($priority,"'bonus'=>\$bonus"),true);
mpCheck('priority scoring exposes final score',str_contains($priority,"\$details[\$mid]['final']+=\$bonus"),true);
mpCheck('push diagnostics include score breakdown',str_contains($push,"'score_breakdown'=>\$scoreBreakdown"),true);
mpCheck('waiting push is restricted to working managers',str_contains($push,'is_active=1 AND is_working=1'),true);
mpCheck('waiting push selects highest priority ties',str_contains($push,'ManagerPriorityService::preferred($eligible,$c)'),true);
mpCheck('admin exposes base priority control',str_contains($admin,'Базовый приоритет'),true);
mpCheck('admin exposes MAX entry channel rule',str_contains($admin,'MAX-канал входа'),true);
mpCheck('admin API saves priority rules',str_contains($api,"\$action==='save_priority_rule'"),true);
mpCheck('incoming dispatcher syncs traffic attribution',str_contains($dispatcher,'ConversationAttributionService::syncByChat($platform,$chatId)'),true);

$total=$passed+$failed;
echo "\n--------------------------\nTOTAL {$total} | PASS {$passed} | FAIL {$failed}\n";
exit($failed?1:0);
