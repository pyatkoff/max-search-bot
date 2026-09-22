<?php

declare(strict_types=1);

require_once __DIR__ . '/../services/ManagerHandoffContextService.php';

$passed=0;$failed=0;
function mhjCheck(string $name,$actual,$expected):void{global$passed,$failed;if($actual===$expected){echo"PASS  {$name}\n";$passed++;return;}echo"FAIL  {$name}\n";echo'      expected: '.json_encode($expected,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)."\n";echo'      actual:   '.json_encode($actual,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)."\n";$failed++;}

$context=[
    'city'=>'Москва','country'=>'Турция','adults'=>2,'children'=>0,
    'stars'=>5,'meal'=>'all_inclusive','nights'=>'7','date'=>'10.10.2026',
    'budget'=>['max'=>250000,'currency'=>'RUB','basis'=>'total'],
    'preferences'=>['спокойный отель'],
    'negative_preferences'=>['шумные вечеринки'],
];
$messages=[
    ['direction'=>'inbound','sender_type'=>'customer','text'=>'Нужен спокойный отель, без шумных вечеринок'],
];

$before=ManagerHandoffContextService::build($context,$messages,[]);
mhjCheck('before-results handoff does not invent shown tours',strpos($before,'Показано/реакция:')===false,true);
mhjCheck('before-results handoff does not invent a reaction step',strpos($before,'Следующее действие: продолжить от уже показанной выдачи')===false,true);

$after=ManagerHandoffContextService::build($context,$messages,['from_tours'=>true]);
mhjCheck('post-results handoff is explicit',strpos($after,'Показано/реакция: запрос менеджера сделан после экрана с турами')!==false,true);
mhjCheck('post-results handoff keeps exact viewed variant unknown',strpos($after,'конкретный просмотр, выбор или реакция не зафиксированы')!==false,true);
mhjCheck('post-results next action is conditional, not a mandatory new questionnaire item',strpos($after,'реакцию на варианты уточнять только если она нужна')!==false,true);
mhjCheck('known budget remains visible alongside journey context',strpos($after,'Бюджет: до 250 000 RUB на всех')!==false,true);
mhjCheck('positive preference remains separate',strpos($after,'Пожелания: спокойный отель')!==false,true);
mhjCheck('negative preference remains separate',strpos($after,'Не подходит: шумные вечеринки')!==false,true);

$eventSource=(string)file_get_contents(__DIR__ . '/../services/ManagerHandoffEventContextService.php');
$apiSource=(string)file_get_contents(__DIR__ . '/../manager/api.php');
$callbackSource=(string)file_get_contents(__DIR__ . '/../actions/callbacks/ManagerCallbackAction.php');
$aiManagerSource=(string)file_get_contents(__DIR__ . '/../actions/ManagerAction.php');

mhjCheck('projection reads only latest waiting-manager event',strpos($eventSource,"event_type='waiting_manager' ORDER BY id DESC LIMIT 1")!==false,true);
mhjCheck('projection consumes existing from_tours payload',strpos($eventSource,"'from_tours'=>!empty(\$payload['from_tours'])")!==false,true);
mhjCheck('projection is read-only',preg_match('/\b(?:UPDATE|INSERT|DELETE|REPLACE)\b/i',$eventSource)===0,true);
mhjCheck('callback handoff already records from_tours',strpos($callbackSource,"'from_tours'=>\$afterTours")!==false,true);
mhjCheck('AI handoff already records from_tours',strpos($aiManagerSource,"'from_tours'=>\$fromTours")!==false,true);
mhjCheck('manager detail reads latest journey context',strpos($apiSource,'ManagerHandoffEventContextService::latestManagerRequest($conversationId)')!==false,true);
mhjCheck('manager detail passes journey context into summary builder',strpos($apiSource,'$d[\'messages\'],$handoffContext')!==false,true);

$total=$passed+$failed;echo"\n--------------------------\n";echo"TOTAL {$total} | PASS {$passed} | FAIL {$failed}\n";exit($failed>0?1:0);
