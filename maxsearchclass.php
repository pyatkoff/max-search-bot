<?php
require_once(__DIR__ . '/config.php');
require_once(__DIR__ . '/maxsearchbaseclass.php');
require_once(__DIR__ . '/services/ProjectConfig.php');
require_once(__DIR__ . '/services/TrafficAttributionService.php');
require_once(__DIR__ . '/services/AnalyticsService.php');
require_once(__DIR__ . '/services/MaxTransport.php');
require_once(__DIR__ . '/services/FollowupQueueService.php');
require_once(__DIR__ . '/services/AiSearchContextService.php');
require_once(__DIR__ . '/services/ConversationStateRepository.php');
require_once(__DIR__ . '/services/ClaimRepository.php');
require_once(__DIR__ . '/services/LeadPayloadService.php');
require_once(__DIR__ . '/services/LeadDeliveryGateway.php');
require_once(__DIR__ . '/services/TravelDirectoryRepository.php');
require_once(__DIR__ . '/services/DialogueView.php');
require_once(__DIR__ . '/services/TourResultsService.php');
require_once(__DIR__ . '/services/NativeDateService.php');

class MaxSearchApi extends MaxSearchBase
{
    static $TV_API_URL = 'https://platform-api2.max.ru';

    static $HL = 32;
    static $claimHL = 33;
    static $yclidHL = 34;
    static $chanelSendHL = 35;

    static $statusStart = 64;
    static $statusCityChoose = 65;
    static $statusContryChoose = 66;
    static $statusAdults = 67;
    static $statusChild = 68;
    static $statusAge = 69;
    static $statusStars = 70;
    static $statusMeal = 71;
    static $statusNights = 72;
    static $statusDate = 73;
    static $statusCheck = 74;
    static $statusPhone = 75;
    static $statusAi = 76;

    static $baseDomain = 'https://anytour.online';
    static $chanelUrl = 'https://max.ru/anytour';
    static $channelMiniappBotUrl = 'https://max.ru/id9704048781_2_bot';
    static $isAnyOnline = true;
    static $uonSourceId = 36;

    private static function statusMap()
    {
        return [
            'city'=>static::$statusCityChoose,'country'=>static::$statusContryChoose,
            'adults'=>static::$statusAdults,'children'=>static::$statusChild,
            'child_ages'=>static::$statusAge,'stars'=>static::$statusStars,
            'meal'=>static::$statusMeal,'nights'=>static::$statusNights,'date'=>static::$statusDate,
        ];
    }

    public static function showStart($chatID){ return DialogueView::start($chatID); }
    public static function showAiStart($chatID){ return DialogueView::aiStart($chatID); }
    public static function showCityButtons($chatID){ return DialogueView::city($chatID); }
    public static function showCityOtherButtons($chatID){ return DialogueView::cityOther($chatID); }
    public static function showCountryButtons($chatID){ return DialogueView::country($chatID); }
    public static function showAdultsButtons($chatID){ return DialogueView::adults($chatID); }
    public static function showChildButtons($chatID){ return DialogueView::children($chatID); }
    public static function showAgeButtons($chatID,$child=1){ return DialogueView::childAges($chatID,(int)$child); }
    public static function showStarsButtons($chatID){ return DialogueView::stars($chatID); }
    public static function showToursChoice($chatID,$name=''){
        static::funnelLog($chatID,'show_tours');
        $model = TourResultsService::build($chatID,(string)$name);
        $sent = DialogueView::tourResults($chatID,$model);
        if($sent) static::linkSentYclid($chatID);
        return (string)($model['claim_url'] ?? '');
    }
    public static function showFinishButtons($chatID,$name=''){ return static::showToursChoice($chatID,$name); }

    public static function getCityByID($city){ return TravelDirectoryRepository::cityById(static::$depHL,$city); }
    public static function getCityFromByID($city){ return TravelDirectoryRepository::cityFromById(static::$depHL,$city); }
    public static function getCityByName($name){ return TravelDirectoryRepository::cityByName(static::$depHL,$name); }
    public static function getCountryByID($country){ return TravelDirectoryRepository::countryById(static::$contryHL,$country); }
    public static function getCountryByName($name){ return TravelDirectoryRepository::countryByName(static::$contryHL,$name); }
    public static function getMealArr(){ return TravelDirectoryRepository::mealMap(); }

    public static function getCurentStatus($chatID){ return ConversationStateRepository::currentStatus(static::$HL,$chatID); }
    public static function setStatus($chatID,$statusID,$messID=false){ ConversationStateRepository::addStatus(static::$HL,$chatID,$statusID,$messID); }
    public static function deletePrevMessage($chatID,$fullDelete=false){
        $row=ConversationStateRepository::latestMessageRow(static::$HL,$chatID); if(!$row)return;
        static::MaxRequest('deleteMessage',['chat_id'=>$chatID,'message_id'=>$row['UF_MESSID']??'']);
        if($fullDelete) ConversationStateRepository::deleteRow(static::$HL,$row['ID']);
    }
    public static function deleteAllStatus($chatID){ ConversationStateRepository::deleteAll(static::$HL,$chatID); }
    public static function saveLastValue($chatID,$status,$value){
        if(in_array($status,[static::$statusAge,static::$statusDate],true)&&static::getLastValue($chatID,$status)===false) static::setStatus($chatID,$status);
        ConversationStateRepository::saveLastValue(static::$HL,$chatID,$status,$value);
    }
    public static function getLastValue($chatID,$status){ return ConversationStateRepository::lastValue(static::$HL,$chatID,$status); }
    public static function getSavedData($chatID){ return ConversationStateRepository::savedData(static::$HL,$chatID,static::$statusStart,static::$statusCheck); }
    public static function upsertStatusValue($chatID,$status,$value){ return ConversationStateRepository::upsertValue(static::$HL,$chatID,$status,$value); }

    public static function getAiSearchContext($chatID){
        return AiSearchContextService::contextFromSaved((array)static::getSavedData($chatID),static::statusMap(),function($id){return static::getCityByID($id);},function($id){return static::getCountryByID($id);});
    }
    public static function getAiMissingFields($chatID){ return AiSearchContextService::missingFromSaved((array)static::getSavedData($chatID),static::statusMap()); }
    public static function applyAiParameters($chatID,array $params){
        $normalized=AiSearchContextService::normalizeParameters($params,function($name){$r=static::getCityByName($name);return $r?($r['ID']??null):null;},function($name){$r=static::getCountryByName($name);return $r?($r['ID']??null):null;},function($date){return NativeDateService::isTodayOrFuture((string)$date);});
        $storageMap=AiSearchContextService::storageMap(static::statusMap()); $applied=[];
        foreach($normalized as $field=>$value){ if(!isset($storageMap[$field]))continue; static::upsertStatusValue($chatID,$storageMap[$field],$value); $applied[$field]=true; }
        return $applied;
    }

    public static function saveClaim($chatID,$savedData){
        $code=randString(10,['abcdefghijklnmopqrstuvwxyz','0123456789']);
        ClaimRepository::create((int)ProjectConfig::get('leads.claim_hl',static::$claimHL),$chatID,(array)$savedData,static::statusMap(),$code);
        return ProjectConfig::claimUrl($code,static::getLatestYclid($chatID));
    }
    public static function getLastClaimForChat($chatID){ return ClaimRepository::latestForChat((int)ProjectConfig::get('leads.claim_hl',static::$claimHL),$chatID); }
    public static function getClaimByCode($code){ return ClaimRepository::byCode((int)ProjectConfig::get('leads.claim_hl',static::$claimHL),$code); }

    public static function savePhone($chatID,$phone){
        $claim=ClaimRepository::latestForChat((int)ProjectConfig::get('leads.claim_hl',static::$claimHL),$chatID); if(!$claim)return false;
        ClaimRepository::setPhone((int)ProjectConfig::get('leads.claim_hl',static::$claimHL),$claim['ID']??0,$phone);
        $name=(string)($claim['UF_NAME']??''); $createdAt=date('d.m.Y H:i:s');
        $from=static::getCityByID($claim['UF_CITY']??0); $country=static::getCountryByID($claim['UF_COUNTRY']??0);
        $people=LeadPayloadService::peopleString($claim); $meal=LeadPayloadService::mealString($claim,static::getMealArr());
        $dateWindow=NativeDateService::leadWindow((string)($claim['UF_DATE_DEPART']??''));
        $leadData=['name'=>$name,'phone'=>$phone,'clean_phone'=>static::cleanPhone($phone),'created_at'=>$createdAt,'from'=>$from,'country'=>$country,'people'=>$people,'stars'=>$claim['UF_STARS']??'','meal'=>$meal,'dates'=>$dateWindow['from'].' - '.$dateWindow['to'],'nights'=>$claim['UF_NIGHTS']??'','status'=>(int)ProjectConfig::get('leads.status_id',static::$claimStatusIDQueue)];
        $uon=(int)ProjectConfig::get('leads.uon_source_id',static::$uonSourceId); if($uon>0)$leadData['source']=$uon;
        if(static::$isAnyOnline)$leadData['is_anytour_online']=CSiteParams::$isAnytourOnline;
        $props=LeadPayloadService::properties($leadData);
        $element=LeadPayloadService::iblockElement(['iblock_id'=>(int)ProjectConfig::get('leads.iblock_id',static::$claimIB),'section_id'=>(int)ProjectConfig::get('leads.section_id',static::$botSearchSection),'properties'=>$props,'created_at'=>$createdAt]);
        $leadId=LeadDeliveryGateway::create($element);
        static::phoneSentYclid($chatID); if($leadId){static::queueMetrikaGoal($chatID,'max_phone');static::funnelLog($chatID,'phone_received',['lead_id'=>(int)$leadId]);}
        return $leadId?true:false;
    }

    public static function trafficFile($chatID){ return TrafficAttributionService::file(__DIR__,$chatID); }
    public static function saveTrafficMeta($chatID,$yclid='',$region='',$campaign='',$raw=''){ return TrafficAttributionService::save(__DIR__,$chatID,$yclid,$region,$campaign,$raw); }
    public static function getTrafficMeta($chatID){ return TrafficAttributionService::get(__DIR__,$chatID); }
    public static function buildChannelMiniappUrl($chatID){
        $bot=(string)ProjectConfig::get('messenger.miniapp_bot_url',static::$channelMiniappBotUrl);
        return TrafficAttributionService::buildMiniappUrl($bot,static::getTrafficMeta($chatID),static::getLatestYclid($chatID));
    }
    public static function funnelLog($chatID,$event,$details=[]){$meta=[];try{$meta=static::getTrafficMeta($chatID);}catch(\Throwable $e){}if(!is_array($meta))$meta=[];return AnalyticsService::funnel(__DIR__,$chatID,$event,(array)$details,$meta);}

    private static function metrikaExcludedDestination($chatID){
        $countryId=0;try{$saved=static::getSavedData($chatID);if(!empty($saved[static::$statusContryChoose]))$countryId=(int)$saved[static::$statusContryChoose];}catch(\Throwable $e){}
        if($countryId<=0){try{$claim=static::getLastClaimForChat($chatID);if($claim&&!empty($claim['UF_COUNTRY']))$countryId=(int)$claim['UF_COUNTRY'];}catch(\Throwable $e){}}
        if($countryId<=0)return false;$country='';try{$country=(string)static::getCountryByID($countryId);}catch(\Throwable $e){}
        $countryNorm=function_exists('mb_strtolower')?mb_strtolower(trim($country),'UTF-8'):strtolower(trim($country));$countryNorm=str_replace('ё','е',$countryNorm);
        return in_array($countryNorm,['россия','абхазия'],true)?['country_id'=>$countryId,'country'=>$country]:false;
    }
    public static function queueMetrikaGoal($chatID,$target){
        $target=trim((string)$target);if($target==='')return false;$excluded=static::metrikaExcludedDestination($chatID);
        if($excluded){static::funnelLog($chatID,'metrika_skipped_destination',['target'=>$target,'country_id'=>$excluded['country_id'],'country'=>$excluded['country']]);return false;}
        $meta=static::getTrafficMeta($chatID);$yclid=static::getLatestYclid($chatID);if($yclid==='')$yclid=trim((string)($meta['yclid']??''));if($yclid==='')return false;
        $dir=__DIR__.'/metrika_dedupe';if(!is_dir($dir))@mkdir($dir,0755,true);$key=hash('sha256',$yclid.'|'.$target);$file=$dir.'/'.$key.'.lock';$fp=@fopen($file,'c+');if(!$fp)return AnalyticsService::queueMetrika(__DIR__,$chatID,$yclid,$target,$meta);
        $result=false;if(flock($fp,LOCK_EX)){rewind($fp);$done=trim((string)stream_get_contents($fp));if($done!=='')$result=true;else{$result=AnalyticsService::queueMetrika(__DIR__,$chatID,$yclid,$target,$meta);if($result){ftruncate($fp,0);rewind($fp);fwrite($fp,date('c'));fflush($fp);}}flock($fp,LOCK_UN);}fclose($fp);return $result;
    }

    private static function maxTransportLogFile(){ return __DIR__.'/tmp_max_search.txt'; }
    public static function MaxRequest($method,$parameters=[]){if($method==='deleteMessage')return MaxTransport::deleteMessage(static::$TV_API_URL,MAX_SEARCH_TOKEN,$parameters['message_id']??'',static::maxTransportLogFile());return false;}
    public static function MaxRequestJson($method,$parameters=[]){return static::MaxRequest($method,$parameters);}
    public static function MaxSend($text,$chat_id){$mid=MaxTransport::send(static::$TV_API_URL,MAX_SEARCH_TOKEN,$chat_id,$text,static::maxTransportLogFile());if($mid)$_SESSION['last_message_id']=$mid;return $mid;}
    public static function MaxSendWithButtons($text,$chat_id,$buttons,$unused=false){$mid=MaxTransport::sendWithButtons(static::$TV_API_URL,MAX_SEARCH_TOKEN,$chat_id,$text,$buttons,static::maxTransportLogFile());if($mid)$_SESSION['last_message_id']=$mid;return $mid;}
    public static function MaxSendWithMenuButtons($text,$chat_id){return static::MaxSend($text,$chat_id);}
    public static function answerCallback($callbackId){return !empty($callbackId);}
    public static function maxLog($data){MaxTransport::log(static::maxTransportLogFile(),$data);}
    public static function followupDir(){return FollowupQueueService::dir(__DIR__);}
    public static function scheduleToursFollowup($chatID,$delaySeconds=180){return FollowupQueueService::schedule(__DIR__,$chatID,(int)$delaySeconds);}
    public static function cancelToursFollowup($chatID){return FollowupQueueService::cancel(__DIR__,$chatID);}
}
