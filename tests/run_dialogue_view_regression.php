<?php

declare(strict_types=1);

require_once __DIR__ . '/../services/ButtonFactory.php';
require_once __DIR__ . '/../contracts/MessengerInterface.php';
require_once __DIR__ . '/../services/ProjectConfig.php';
require_once __DIR__ . '/../services/IntegrationRegistry.php';
require_once __DIR__ . '/../services/CallbackGeneration.php';

class ViewTestMessenger implements MessengerInterface {
    public array $sent = [];
    public function send($chatId, string $text): bool { $this->sent[]=['chat'=>$chatId,'text'=>$text,'buttons'=>[]]; return true; }
    public function sendWithButtons($chatId, string $text, array $buttons): bool { $this->sent[]=['chat'=>$chatId,'text'=>$text,'buttons'=>$buttons]; return true; }
    public function sendContactRequest($chatId, string $text, string $manualCallback, string $backCallback): bool {
        $this->sent[]=['chat'=>$chatId,'text'=>$text,'contact'=>true,'manual'=>$manualCallback,'back'=>$backCallback];
        return true;
    }
}
class MaxSearchApi {
    public static $statusStart=64,$statusCityChoose=65,$statusContryChoose=66,$statusAdults=67,$statusChild=68,$statusAge=69,$statusStars=70,$statusNights=72,$statusDate=73,$statusCheck=74,$statusPhone=75,$statusAi=76;
    public static array $statuses=[];
    public static array $statusValues=[];
    public static int $deletes=0;
    public static function deletePrevMessage($chatId,$full=false){self::$deletes++;}
    public static function setStatus($chatId,$status,$mess=false){self::$statuses[]=[(int)$chatId,(int)$status];}
    public static function saveLastValue($chatId,$status,$value){self::$statusValues[(int)$status]=(string)$value;}
    public static function getLastClaimForChat($chatId){return ['ID'=>1];}
    public static function getSavedData($chatId){return [self::$statusDate=>'05.10.2026'];}
    public static function formatSavedData($saved){return ['👥 Туристы: 2 взрослых','🌙 Ночей: 7'];}
    public static function funnelLog($chatId,$event,$details=[]){return true;}
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

DialogueView::check(16);
$checkButtons=$m->sent[6]['buttons']??[];
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

IntegrationRegistry::resetForTests();
ProjectConfig::resetForTests(null);
$total=$passed+$failed;echo"\n--------------------------\n";echo"TOTAL {$total} | PASS {$passed} | FAIL {$failed}\n";exit($failed>0?1:0);
