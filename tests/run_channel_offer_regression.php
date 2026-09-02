<?php

declare(strict_types=1);

require_once __DIR__ . '/../services/ProjectConfig.php';
require_once __DIR__ . '/../services/ChannelOfferService.php';

$passed=0;$failed=0;
function coCheck(string $name,$actual,$expected):void{global $passed,$failed;if($actual===$expected){echo "PASS  {$name}\n";$passed++;return;}echo "FAIL  {$name}\n";echo ' expected: '.json_encode($expected,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)."\n";echo ' actual: '.json_encode($actual,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)."\n";$failed++;}

ProjectConfig::resetForTests(['messenger'=>['channel_offer'=>[
    'telegram_url'=>'https://t.me/Any_tour_bot?startapp={yclid}',
    'max_url'=>'https://max.ru/id9704048781_2_bot?startapp={yclid}_region_{region_id}',
]]]);

$external=ChannelOfferService::model(['yclid'=>'123456','region_id'=>'7','entry_channel'=>''],'');
coCheck('external source sees both channels',count($external['buttons']),2);
coCheck('MAX link preserves yclid and region',$external['buttons'][0][0]['url'],'https://max.ru/id9704048781_2_bot?startapp=123456_region_7');
coCheck('Telegram link preserves yclid',$external['buttons'][1][0]['url'],'https://t.me/Any_tour_bot?startapp=123456');

$fromMax=ChannelOfferService::model(['yclid'=>'123456','region_id'=>'7','entry_channel'=>'max_1'],'');
coCheck('MAX source is not offered MAX again',count($fromMax['buttons']),1);
coCheck('MAX source gets Telegram',$fromMax['buttons'][0][0]['text'],'Подписаться в Telegram');

$fromTg=ChannelOfferService::model(['yclid'=>'123456','region_id'=>'7','entry_channel'=>'telegram_anytour'],'');
coCheck('Telegram source is not offered Telegram again',count($fromTg['buttons']),1);
coCheck('Telegram source gets MAX',$fromTg['buttons'][0][0]['text'],'Подписаться в MAX');

$unknown=ChannelOfferService::model(['entry_channel'=>'direct'],'777');
coCheck('unknown/external source gets both channels',count($unknown['buttons']),2);
coCheck('latest yclid wins',$unknown['buttons'][0][0]['url'],'https://max.ru/id9704048781_2_bot?startapp=777_region_0');
coCheck('offer copy fixed',$unknown['text'],'А пока можете подписаться на наш канал — там публикуем горящие туры и интересные снижения цен 🔥');

coCheck('MAX entry family',ChannelOfferService::entryFamily('entry-max'),'max');
coCheck('TG short entry family',ChannelOfferService::entryFamily('tg_main'),'telegram');
coCheck('unrelated entry family',ChannelOfferService::entryFamily('yandex_direct'),'');

$total=$passed+$failed;echo "\n--------------------------\nTOTAL {$total} | PASS {$passed} | FAIL {$failed}\n";exit($failed>0?1:0);
