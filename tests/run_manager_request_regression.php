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

$passed=0;$failed=0;
function mrCheck(string $name,$actual,$expected):void{global$passed,$failed;if($actual===$expected){echo"PASS  {$name}\n";$passed++;return;}echo"FAIL  {$name}\n";echo'      expected: '.json_encode($expected,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)."\n";echo'      actual:   '.json_encode($actual,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)."\n";$failed++;}

MaxSearchApi::$claim = ['ID'=>1];
MaxSearchApi::$saveCalls = 0;
$model = ManagerRequestService::prepare(10,'Pavel',false);
mrCheck('existing claim is reused',MaxSearchApi::$saveCalls,0);
mrCheck('default back callback',$model['back_callback'],'back_check');
mrCheck('manual callback',$model['manual_callback'],'phone_manual');
mrCheck('text mentions manager',strpos($model['text'],'менеджеру')!==false,true);

MaxSearchApi::$claim = null;
MaxSearchApi::$saveCalls = 0;
$model2 = ManagerRequestService::prepare(11,'Anna',true);
mrCheck('missing claim created once',MaxSearchApi::$saveCalls,1);
mrCheck('name passed into claim',MaxSearchApi::$lastSaved['NAME']??null,'Anna');
mrCheck('after tours back callback',$model2['back_callback'],'tours_checked');
mrCheck('created marker',$model2['claim_created'],true);
mrCheck('created claim returned',$model2['claim']['ID']??null,99);

$total=$passed+$failed;echo"\n--------------------------\n";echo"TOTAL {$total} | PASS {$passed} | FAIL {$failed}\n";exit($failed>0?1:0);
