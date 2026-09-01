<?php

declare(strict_types=1);

require_once __DIR__ . '/../services/ProjectConfig.php';

class MaxSearchApi {
    public static string $channel = 'https://max.ru/join/test';
    public static array $claim = ['UF_CODE'=>'abc123','UF_CITY'=>1,'UF_COUNTRY'=>4];
    public static string $yclid = 'yclid-test';
    public static function buildChannelMiniappUrl($chatId){ return self::$channel; }
    public static function getLastClaimForChat($chatId){ return self::$claim; }
    public static function getLatestYclid($chatId){ return self::$yclid; }
}

require_once __DIR__ . '/../services/PostTourService.php';

$passed=0;$failed=0;
function ptCheck(string $name,$actual,$expected):void{global$passed,$failed;if($actual===$expected){echo"PASS  {$name}\n";$passed++;return;}echo"FAIL  {$name}\n";echo'      expected: '.json_encode($expected,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)."\n";echo'      actual:   '.json_encode($actual,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)."\n";$failed++;}

ProjectConfig::resetForTests([
    'messenger'=>[
        'provider'=>'max',
        'open_channel_path'=>'/max-search/open_channel.php',
    ],
    'search'=>[
        'base_domain'=>'https://example.test',
        'search_path'=>'/poisk-turov/',
    ],
]);

$followup=PostTourService::followupModel();
ptCheck('followup manager payload',$followup['buttons'][0][0]['callback_data']??null,'manager_after_tours');
ptCheck('followup found payload',$followup['buttons'][1][0]['callback_data']??null,'tours_found');
ptCheck('followup edit payload',$followup['buttons'][2][0]['callback_data']??null,'edit_params');

$after=PostTourService::afterToursModel();
ptCheck('after tours found payload',$after['buttons'][0][0]['callback_data']??null,'tours_found');
ptCheck('after tours manager payload',$after['buttons'][1][0]['callback_data']??null,'manager_after_tours');

$offer=PostTourService::channelOfferModel(55,false);
ptCheck('max channel name',PostTourService::channelName(),'MAX-канал');
ptCheck('channel tracked url contains chat',strpos($offer['buttons'][0][0]['url']??'','chat=55')!==false,true);
ptCheck('return tours canonical url',$offer['claim_url'],'https://example.test/poisk-turov/?from=1&country=4&yclid=yclid-test');
ptCheck('return tours button',$offer['buttons'][1][0]['text']??null,'🔥 Вернуться к турам');
ptCheck('non-lead copy mentions max',strpos($offer['text'],'MAX-канал')!==false,true);

$leadOffer=PostTourService::channelOfferModel(55,true);
ptCheck('lead copy confirmation',strpos($leadOffer['text'],'Заявка отправлена')!==false,true);

ProjectConfig::resetForTests([
    'messenger'=>['provider'=>'telegram'],
    'search'=>['base_domain'=>'https://example.test','search_path'=>'/poisk-turov/'],
]);
ptCheck('telegram channel name',PostTourService::channelName(),'Telegram-канал');
$tg=PostTourService::channelOfferModel(55,false);
ptCheck('telegram copy mentions telegram',strpos($tg['text'],'Telegram-канал')!==false,true);

MaxSearchApi::$channel='';
MaxSearchApi::$claim=[];
$empty=PostTourService::channelOfferModel(55,false);
ptCheck('empty offer has no buttons',$empty['buttons'],[]);

ProjectConfig::resetForTests(null);
$total=$passed+$failed;echo"\n--------------------------\n";echo"TOTAL {$total} | PASS {$passed} | FAIL {$failed}\n";exit($failed>0?1:0);