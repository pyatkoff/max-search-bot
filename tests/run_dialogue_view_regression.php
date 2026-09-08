<?php

declare(strict_types=1);

require_once __DIR__ . '/../services/ButtonFactory.php';
require_once __DIR__ . '/../contracts/MessengerInterface.php';
require_once __DIR__ . '/../services/ProjectConfig.php';
require_once __DIR__ . '/../services/IntegrationRegistry.php';
require_once __DIR__ . '/../services/CallbackGeneration.php';

class ViewTestMessenger implements MessengerInterface {
    public array $sent = [];
    public bool $contactSucceeds = true;
    public bool $sendSucceeds = true;
    public function send($chatId, string $text): bool { $this->sent[]=['chat'=>$chatId,'text'=>$text,'buttons'=>[]]; return true; }
    public function sendWithButtons($chatId, string $text, array $buttons): bool { $this->sent[]=['chat'=>$chatId,'text'=>$text,'buttons'=>$buttons]; return $this->sendSucceeds; }
    public function sendContactRequest($chatId, string $text, string $manualCallback, string $backCallback): bool {
        $this->sent[]=['chat'=>$chatId,'text'=>$text,'contact'=>true,'manual'=>$manualCallback,'back'=>$backCallback];
        return $this->contactSucceeds;
    }
}
class MaxSearchApi {
    public static $statusStart=64,$statusCityChoose=65,$statusContryChoose=66,$statusAdults=67,$statusChild=68,$statusAge=69,$statusStars=70,$statusNights=72,$statusDate=73,$statusCheck=74,$statusPhone=75,$statusAi=76;
    public static array $statuses=[];
    public static array $statusValues=[];
    public static int $deletes=0;
    public static array $events=[];
    public static function deletePrevMessage($chatId,$full=false){self::$deletes++;}
    public static function setStatus($chatId,$status,$mess=false){self::$statuses[]=[(int)$chatId,(int)$status];}
    public static function saveLastValue($chatId,$status,$value){self::$statusValues[(int)$status]=(string)$value;}
    public static function getLastClaimForChat($chatId){return ['ID'=>1];}
    public static function getSavedData($chatId){return [self::$statusDate=>'05.10.2026'];}
    public static function formatSavedData($saved){return ['👥 Туристы: 2 взрослых','🌙 Ночей: 7'];}
    public static function funnelLog($chatId,$event,$details=[]){self::$events[]=$event;return true;}
    public static function saveClaim($chatId,$saved){return 'https://example.test/claim';}
}
require_once __DIR__ . '/../services/DialogueView.php';

$passed=0;$failed=0;
function dvCheck(string $name,$actual,$expected):void{global$passed,$failed;if($actual===$expected){echo"PASS  {$name}\n";$passed++;return;}echo"FAIL  {$name}\n";echo'      expected: '.json_encode($expected,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)."\n";echo'      actual:   '.json_encode($actual,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)."\n";$failed++;}

$m=new ViewTestMessenger();
IntegrationRegistry::resetForTests($m,null,null);

dvCheck('callback button',ButtonFactory::callback('Москва','pick_city_1'),['text'=>'Москва','callback_data'=>'pick_city_1']);
dvCheck('url button',ButtonFactory::url('Сайт','https://example.test'),['text'=>'Сайт','url'=>'https://example.test']);
dvCheck('contact button',ButtonFactory::contact('Телефон'),['text'=>'Телефон','request_contact'=>true]);

DialogueView::start(10);
dvCheck('start sent',count($m->sent),1);
dvCheck('start first payload',$m->sent[0]['buttons'][0][0]['callback_data'],'ai_start');
dvCheck('start second payload',$m->sent[0]['buttons'][1][0]['callback_data'],'start_search');
dvCheck('start status',MaxSearchApi::$statuses[0],[10,64]);

DialogueView::country(11);
dvCheck('country deletes previous',MaxSearchApi::$deletes,1);
dvCheck('country turkey payload',$m->sent[1]['buttons'][0][0]['callback_data'],'pick_country_4');
dvCheck('country status',MaxSearchApi::$statuses[1],[11,66]);

DialogueView::childAges(12,2);
dvCheck('age copy contains count',strpos($m->sent[2]['text'],'2 возраста')!==false,true);
dvCheck('age back payload',$m->sent[2]['buttons'][0][0]['callback_data'],'back_child');

DialogueView::manualPhone(13);
dvCheck('phone back payload',$m->sent[3]['buttons'][0][0]['callback_data'],'tours_checked');
dvCheck('phone status',MaxSearchApi::$statuses[3],[13,75]);

DialogueView::managerRequest(14,'Pavel',false);
dvCheck('manager uses contact contract',$m->sent[4]['contact']??false,true);
dvCheck('manager manual callback',$m->sent[4]['manual']??null,'phone_manual');
dvCheck('manager back callback',$m->sent[4]['back']??null,'back_check');
dvCheck('manager status',MaxSearchApi::$statuses[4],[14,75]);

DialogueView::managerRequest(15,'Pavel',true);
dvCheck('manager after tours back',$m->sent[5]['back']??null,'tours_checked');

DialogueView::managerRequest(17,'Pavel',false,true);
dvCheck('outside-hours manager uses truthful copy',strpos($m->sent[6]['text']??'','следующий рабочий период')!==false,true);
$deletesBeforeFallback=MaxSearchApi::$deletes;
DialogueView::managerPhoneFallback(18,true);
dvCheck('fallback uses delayed response copy',strpos($m->sent[7]['text']??'','не успел ответить')!==false,true);
dvCheck('fallback preserves after-tours back callback',$m->sent[7]['back']??null,'tours_checked');
dvCheck('fallback does not delete the preceding chat message',MaxSearchApi::$deletes,$deletesBeforeFallback);
dvCheck('contact paths share one canonical renderer',substr_count((string)file_get_contents(__DIR__ . '/../services/DialogueView.php'),'ManagerRequestService::prepare')===1,true);
$statusesBeforeFailure=count(MaxSearchApi::$statuses);
$m->contactSucceeds=false;
dvCheck('failed fallback reports transport failure',DialogueView::managerPhoneFallback(19,false),false);
dvCheck('failed fallback does not advance phone status',count(MaxSearchApi::$statuses),$statusesBeforeFailure);
$m->contactSucceeds=true;

DialogueView::check(16);
$checkButtons=$m->sent[9]['buttons']??[];
$checkPayloads=[
    $checkButtons[0][0]['callback_data']??'',
    $checkButtons[1][0]['callback_data']??'',
    $checkButtons[2][0]['callback_data']??'',
];
$parsed=array_map(static fn(string $payload)=>CallbackGeneration::parse($payload),$checkPayloads);
$generations=array_values(array_unique(array_map(static fn($item)=>(string)($item['generation']??''),$parsed)));
dvCheck('final check emits three versioned callbacks',count(array_filter($parsed))===3,true);
dvCheck('final check show tours keeps normalized action',$parsed[0]['payload']??null,'show_tours');
dvCheck('final check manager request keeps normalized action',$parsed[1]['payload']??null,'manager_request');
dvCheck('final check edit keeps normalized action',$parsed[2]['payload']??null,'edit_params');
dvCheck('final check buttons share one generation',count($generations),1);
dvCheck('final check generation is persisted on check state',MaxSearchApi::$statusValues[74]??null,$generations[0]??null);
dvCheck('final check status remains canonical',MaxSearchApi::$statuses[count(MaxSearchApi::$statuses)-1]??null,[16,74]);

// Advertising entry reuses MAX2's configured MiniApp and the canonical payload builder.
$config=ProjectConfig::all();
foreach (['1234567890123456_region_213_campaign_42','1234567890123456_213_campaign_42','1234567890123456_entry_paid_region_213_campaign_42'] as $payload) {
    $meta=TrafficAttributionService::parseStartPayload($payload);
    $statusesBefore=count(MaxSearchApi::$statuses);
    $eventsBefore=count(MaxSearchApi::$events);
    dvCheck('paid start delivered: '.$payload,DialogueView::start(-900000001,$meta),true);
    $sent=$m->sent[count($m->sent)-1];
    dvCheck('paid start preserves AI button',$sent['buttons'][0][0]['callback_data'],'ai_start');
    dvCheck('paid start preserves wizard button',$sent['buttons'][1][0]['callback_data'],'start_search');
    dvCheck('paid start opens existing MAX2 with all attribution',$sent['buttons'][2][0]['url'],
        'https://max.ru/id9704048781_2_bot?startapp=1234567890123456_region_213_campaign_42');
    dvCheck('subscription is explicitly optional',strpos($sent['text'],'Подписка по желанию')!==false,true);
    dvCheck('paid start changes status once',count(MaxSearchApi::$statuses),$statusesBefore+1);
    dvCheck('paid start retains start state',MaxSearchApi::$statuses[$statusesBefore],[-900000001,64]);
    dvCheck('offer is not a subscription conversion',array_slice(MaxSearchApi::$events,$eventsBefore),['channel_offer_start']);
}
foreach (['','0','ordinary_entry','12345_region_213_campaign_42','000000_region_213_campaign_42','123456789_region_0_campaign_42','123456789_region_unknown_campaign_42','123456789_region_213_campaign_unknown'] as $payload) {
    DialogueView::start(-900000001,TrafficAttributionService::parseStartPayload($payload));
    dvCheck('non-ad start keeps two search buttons: '.$payload,count($m->sent[count($m->sent)-1]['buttons']),2);
}
DialogueView::start(-900000001);
dvCheck('ordinary restart does not reuse preceding paid entry',count($m->sent[count($m->sent)-1]['buttons']),2);
dvCheck('yclid-only entry uses documented MAX2 defaults',ChannelOfferService::startUrl(TrafficAttributionService::parseStartPayload('123456789')),
    'https://max.ru/id9704048781_2_bot?startapp=123456789_region_1_campaign_0');
$meta=TrafficAttributionService::parseStartPayload('1234567890123456_region_213_campaign_42');
$config['messenger']['provider']='telegram';
ProjectConfig::resetForTests($config);
DialogueView::start(900000001,$meta);
dvCheck('Telegram entry is unchanged',count($m->sent[count($m->sent)-1]['buttons']),2);
$config['messenger']['provider']='max';
$config['messenger']['miniapp_bot_url']='';
ProjectConfig::resetForTests($config);
DialogueView::start(-900000001,$meta);
dvCheck('missing MiniApp configuration keeps search usable',count($m->sent[count($m->sent)-1]['buttons']),2);
ProjectConfig::resetForTests(null);
$m->sendSucceeds=false;
$statusesBefore=count(MaxSearchApi::$statuses);
$eventsBefore=count(MaxSearchApi::$events);
dvCheck('failed paid greeting reports delivery failure',DialogueView::start(-900000001,$meta),false);
dvCheck('failed paid greeting does not advance state',count(MaxSearchApi::$statuses),$statusesBefore);
dvCheck('failed paid greeting does not record an offer',count(MaxSearchApi::$events),$eventsBefore);

IntegrationRegistry::resetForTests();
ProjectConfig::resetForTests(null);
$total=$passed+$failed;echo"\n--------------------------\n";echo"TOTAL {$total} | PASS {$passed} | FAIL {$failed}\n";exit($failed>0?1:0);
