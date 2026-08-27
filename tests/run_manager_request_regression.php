<?php

declare(strict_types=1);

class MaxSearchApi
{
    public static $claim = null;
    public static int $saveCalls = 0;
    public static array $lastSaved = [];

    public static function getLastClaimForChat($chatId)
    {
        return self::$claim;
    }

    public static function getSavedData($chatId): array
    {
        return ['city'=>1,'country'=>4];
    }

    public static function saveClaim($chatId, $savedData): string
    {
        self::$saveCalls++;
        self::$lastSaved = (array)$savedData;
        self::$claim = ['ID'=>99,'UF_NAME'=>$savedData['NAME'] ?? ''];
        return 'https://example.test/claim';
    }
}

require_once __DIR__ . '/../services/ManagerRequestService.php';
require_once __DIR__ . '/../services/ManagerHandoffContextService.php';

$passed=0;$failed=0;
function mrCheck(string $name,$actual,$expected):void{global$passed,$failed;if($actual===$expected){echo"PASS  {$name}\n";$passed++;return;}echo"FAIL  {$name}\n";echo'      expected: '.json_encode($expected,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)."\n";echo'      actual:   '.json_encode($actual,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)."\n";$failed++;}

MaxSearchApi::$claim = ['ID'=>1];
MaxSearchApi::$saveCalls = 0;
$model = ManagerRequestService::prepare(10,'Pavel',false);
mrCheck('existing claim is reused',MaxSearchApi::$saveCalls,0);
mrCheck('default back callback',$model['back_callback'],'back_check');
mrCheck('manual callback',$model['manual_callback'],'phone_manual');
mrCheck('offline text mentions manager',strpos($model['text'],'менеджеру')!==false,true);
mrCheck('offline text asks for phone',strpos($model['text'],'номером телефона')!==false,true);
mrCheck('online text says manager is online',strpos($model['online_text'],'сейчас онлайн')!==false,true);
mrCheck('online text does not require phone',strpos($model['online_text'],'оставлять не нужно')!==false,true);
mrCheck('fallback text available',strpos($model['fallback_text'],'не успел ответить')!==false,true);
mrCheck('outside-hours text available',strpos($model['outside_hours_text'],'следующий рабочий период')!==false,true);

MaxSearchApi::$claim = null;
MaxSearchApi::$saveCalls = 0;
$model2 = ManagerRequestService::prepare(11,'Anna',true);
mrCheck('missing claim created once',MaxSearchApi::$saveCalls,1);
mrCheck('name passed into claim',MaxSearchApi::$lastSaved['NAME']??null,'Anna');
mrCheck('after tours back callback',$model2['back_callback'],'tours_checked');
mrCheck('created marker',$model2['claim_created'],true);
mrCheck('created claim returned',$model2['claim']['ID']??null,99);

$aiContext=[
    'city'=>'Москва','country'=>'Египет','adults'=>1,'children'=>0,
    'stars'=>4,'meal'=>'all_inclusive','nights'=>'6-8','date'=>'30.08.2026',
];
$messages=[
    ['direction'=>'inbound','sender_type'=>'customer','text'=>'pick_date_30.08.2026'],
    ['direction'=>'inbound','sender_type'=>'customer','text'=>'Хочу спокойный отель 18+ со средней территорией'],
    ['direction'=>'inbound','sender_type'=>'customer','text'=>'79158966837'],
];
$summary=ManagerHandoffContextService::build($aiContext,$messages);
mrCheck('handoff summary includes route',strpos($summary,'Маршрут: Москва → Египет')!==false,true);
mrCheck('handoff summary includes tourists',strpos($summary,'Туристы: 1 взр. + 0 реб.')!==false,true);
mrCheck('handoff summary includes hotel and meal',strpos($summary,'Отель: от 4★')!==false&&strpos($summary,'Питание: all_inclusive')!==false,true);
mrCheck('handoff summary preserves meaningful free-text note',strpos($summary,'Дополнение туриста: Хочу спокойный отель 18+ со средней территорией')!==false,true);
mrCheck('handoff context ignores phone as free-text note',strpos($summary,'79158966837')===false,true);
mrCheck('no manager reply is detected before handoff response',ManagerHandoffContextService::hasManagerReply($messages),false);
$messages[]=['direction'=>'outbound','sender_type'=>'manager','text'=>'Здравствуйте'];
mrCheck('manager reply suppresses first-response context injection',ManagerHandoffContextService::hasManagerReply($messages),true);

$managerActionSource = (string)file_get_contents(__DIR__ . '/../actions/ManagerAction.php');
mrCheck('manager action checks live availability',strpos($managerActionSource,'ManagerAvailabilityService::anyWorkingForConversation')!==false,true);
mrCheck('online handoff uses chat response',strpos($managerActionSource,"sendWithButtons(\$chatId, (string)\$model['online_text']")!==false,true);
mrCheck('offline handoff keeps contact request path',strpos($managerActionSource,'DialogueView::managerRequest($chatId, $name, $fromTours, !$withinWorkingHours)')!==false,true);

$managerApiSource=(string)file_get_contents(__DIR__ . '/../manager/api.php');
mrCheck('manager detail builds panel-only handoff context',strpos($managerApiSource,'ManagerHandoffContextService::build')!==false,true);
mrCheck('manager detail labels saved tourist request',strpos($managerApiSource,'📋 Запрос туриста для менеджера')!==false,true);
mrCheck('manager context is not injected after human reply',strpos($managerApiSource,'!ManagerHandoffContextService::hasManagerReply')!==false,true);

$total=$passed+$failed;echo"\n--------------------------\n";echo"TOTAL {$total} | PASS {$passed} | FAIL {$failed}\n";exit($failed>0?1:0);
