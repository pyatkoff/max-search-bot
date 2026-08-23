<?php

declare(strict_types=1);

require_once __DIR__ . '/../services/RulesEngine.php';
require_once __DIR__ . '/../services/V2ActionExecutor.php';
require_once __DIR__ . '/../services/ProjectConfig.php';

$passed=0;$failed=0;
function vaCheck(string $name,$actual,$expected):void{global$passed,$failed;if($actual===$expected){echo"PASS  {$name}\n";$passed++;return;}echo"FAIL  {$name}\n";echo'      expected: '.json_encode($expected,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)."\n";echo'      actual:   '.json_encode($actual,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)."\n";$failed++;}

$state=[
 'departure'=>['city_id'=>1,'city'=>'Москва'],
 'destination'=>['country_id'=>4,'country'=>'Турция','region'=>null,'resort'=>null],
 'dates'=>['from'=>'10.09.2026','to'=>'13.09.2026','month'=>'2026-09'],
 'nights'=>['min'=>7,'max'=>10],
 'tourists'=>['adults'=>2,'children'=>1,'children_ages'=>[6]],
 'budget'=>['max'=>180000,'currency'=>'RUB'],
 'hotel'=>['stars_min'=>5,'meal'=>'all_inclusive','line'=>null],
 'preferences'=>['первая линия','детский клуб'],
 'negative_preferences'=>['шумный отель'],
];

$ask=V2ActionExecutor::plan(['action'=>RulesEngine::ASK,'next_field'=>'children_ages','missing'=>['children_ages']],$state);
vaCheck('ask action',$ask['action'],RulesEngine::ASK);
vaCheck('ask field',$ask['field'],'children_ages');
vaCheck('ask question',$ask['text'],'Сколько лет детям на момент поездки?');

$search=V2ActionExecutor::plan(['action'=>RulesEngine::OPEN_SEARCH,'missing'=>[]],$state);
vaCheck('search ready',$search['ready'],true);
vaCheck('search budget',$search['request']['budget_max'],180000);
vaCheck('search preferences',$search['request']['preferences'],['первая линия','детский клуб']);

$manager=V2ActionExecutor::plan(['action'=>RulesEngine::MANAGER,'missing'=>[]],$state,['region'=>5,'campaign'=>123]);
vaCheck('manager action',$manager['action'],'MANAGER');
vaCheck('manager summary has route',strpos($manager['summary'],'Москва')!==false,true);
vaCheck('manager summary has budget',strpos($manager['summary'],'180')!==false,true);

$advice=V2ActionExecutor::plan(['action'=>RulesEngine::SHOW_OPTIONS,'missing'=>[]],$state);
vaCheck('advice city',$advice['departure_city'],'Москва');
vaCheck('advice period',$advice['period'],'2026-09');

ProjectConfig::resetForTests(['messenger'=>['channel_url'=>'https://example.test/channel']]);
$channel=ChannelAction::plan(123);
vaCheck('channel fallback url',$channel['url'],'https://example.test/channel');
vaCheck('channel ready',$channel['ready'],true);
ProjectConfig::resetForTests(null);

$total=$passed+$failed;echo"\n--------------------------\n";echo"TOTAL {$total} | PASS {$passed} | FAIL {$failed}\n";exit($failed>0?1:0);
