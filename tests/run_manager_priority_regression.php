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

// Owner-provided MAX25 handoff: YCLID_REGION_campaign_CAMPAIGN (synthetic IDs).
foreach ([
    ['1234567890123456_213_campaign_42','1234567890123456','213','42'],
    ['123456_0_campaign_0','123456','0','0'],
    ['000123456_0213_campaign_0042','000123456','0213','0042'],
    ['18446744073709551616_213_campaign_9007199254740993','18446744073709551616','213','9007199254740993'],
    ['  ya123456789_213_campaign_42  ','123456789','213','42'],
    ['123456789_213_CAMPAIGN_42','123456789','213','42'],
] as [$payload,$yclid,$region,$campaign]) {
    mpCheck('MAX25 preserves all fields: '.trim($payload),TrafficAttributionService::parseStartPayload($payload),[
        'yclid'=>$yclid,'region_id'=>$region,'campaign_id'=>$campaign,'entry_channel'=>'','raw'=>trim($payload),
    ]);
}

// Do not reinterpret malformed/partial positional payloads as a numeric region.
// Their existing yclid/campaign fallback behavior is intentionally not tightened.
foreach ([
    '12345_213_campaign_42', '123456789__campaign_42', '123456789_-213_campaign_42',
    '123456789_moscow_campaign_42', '123456789_213_campaign_', '123456789_213_campaign_text',
    '123456789_213_campaign_42_extra', '123456789_213_campaign_42?x=1',
    '123456789_213_campaign_42' . "\nextra", '{yclid}_{region_id}_campaign_{campaign_id}',
] as $payload) {
    mpCheck('MAX25 does not invent a region: '.json_encode($payload),TrafficAttributionService::parseStartPayload($payload)['region_id'],'');
}

foreach ([
    ['123456789_key_any_entry_213_campaign_42','123456789','213','42'],
    ['_123456789_r_213_c_42','123456789','213','42'],
    ['123456789_r_213','123456789','213',''],
    ['123456789_region_custom_campaign_test','123456789','custom','test'],
    ['123456789_region__campaign_42','123456789','','42'],
    ['123456789','123456789','',''],
    ['','','',''],
] as [$payload,$yclid,$region,$campaign]) {
    $meta=TrafficAttributionService::parseStartPayload($payload);
    mpCheck('legacy traffic fields stay unchanged: '.$payload,[$meta['yclid'],$meta['region_id'],$meta['campaign_id']],[$yclid,$region,$campaign]);
}

$max25=TrafficAttributionService::parseStartPayload('1234567890123456_213_campaign_42');
mpCheck('MAX25 uses the existing outbound miniapp format',TrafficAttributionService::buildMiniappUrl('https://max.ru/example_bot',$max25),
    'https://max.ru/example_bot?startapp=1234567890123456_region_213_campaign_42');

// Exercise the existing save/get boundary in an isolated temporary directory only.
$trafficTestDir=sys_get_temp_dir().'/max25-attribution-'.bin2hex(random_bytes(8));
if (!mkdir($trafficTestDir,0700)) throw new RuntimeException('Cannot create isolated attribution test directory');
try {
    $saved=TrafficAttributionService::save($trafficTestDir,-900000001,$max25['yclid'],$max25['region_id'],$max25['campaign_id'],$max25['raw'],$max25['entry_channel']);
    mpCheck('MAX25 traffic save succeeds',is_array($saved),true);
    $readBack=TrafficAttributionService::get($trafficTestDir,-900000001);
    mpCheck('MAX25 region reaches existing traffic storage',$readBack['region_id']??null,'213');
    mpCheck('MAX25 yclid reaches existing traffic storage',$readBack['yclid']??null,'1234567890123456');
    mpCheck('MAX25 campaign reaches existing traffic storage',$readBack['campaign_id']??null,'42');
    mpCheck('MAX25 raw payload stays unchanged in storage',$readBack['raw']??null,'1234567890123456_213_campaign_42');
    mpCheck('MAX25 does not invent a manager entry channel',$readBack['entry_channel']??null,'');
} finally {
    foreach (glob($trafficTestDir.'/traffic/*') ?: [] as $file) unlink($file);
    if (is_dir($trafficTestDir.'/traffic')) rmdir($trafficTestDir.'/traffic');
    rmdir($trafficTestDir);
}

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
