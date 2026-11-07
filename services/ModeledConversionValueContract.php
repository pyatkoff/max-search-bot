<?php
declare(strict_types=1);
require_once __DIR__.'/ModeledConversionEarlyForecast.php';
require_once __DIR__.'/ModeledConversionValueRegistry.php';
require_once __DIR__.'/ModeledConversionDealValue.php';

/** Owner-selected illustrative advertising value; no calibrated probability or actual income. */
final class ModeledConversionValueContract
{
    public const VERSION='semantic_stage_value_25000_v1';
    public const TARGET='anytour_modeled_value_v1';
    public const NAME='AnyTour: оценочная ценность обращения v1';
    /** Current monetary envelope; BASE/VALUES below retain the historical stage fractions. */
    public const MAX_VALUE_RUB=ModeledConversionDealValue::CAP;
    public const BASE=25000;
    public const VALUES=[1=>1250,2=>2083,3=>4167,4=>8333,5=>12500,6=>25000];
    public const STAGES=[1=>'interest',2=>'specific_request',3=>'offer_discussion',4=>'purchase_intent',5=>'agency_choice',6=>'payment_report'];
    public static function key(int $rank):string
    {
        if(!isset(self::VALUES[$rank]))throw new InvalidArgumentException('value_rank');
        return 'anytour_value_'.$rank.'_v1';
    }
    public static function isKey(mixed $key):bool{return is_string($key)&&preg_match('/\Aanytour_value_(?:[1-6]|pred_[1-9][0-9]{0,19})_v1\z/D',$key)===1;}
    public static function predictionKey(string $profileId):string
    {if(preg_match('/\A[1-9][0-9]{0,19}\z/D',$profileId)!==1)throw new InvalidArgumentException('value_profile');return 'anytour_value_pred_'.$profileId.'_v1';}

    /** Consume only the immutable admitted projection; never interpret raw text or callback counts. */
    public static function candidate(array $wrapper,array $subject,int $since,int $now):?array
    { return self::evaluate($wrapper,$subject,$since,$now,null,false); }

    /** Explicit owner-authorized day review; original saved profile is never rewritten. */
    public static function historicalCandidate(array $wrapper,array $subject,int $since,int $until,int $now):?array
    {
        if(!self::historyWindow($since,$until)||$until>$now)return null;
        return self::evaluate($wrapper,$subject,$since,$now,$until,true);
    }

    public static function historyWindow(int $since,int $until):bool
    {
        return $until===1793656800&&in_array($since,[1793570400,1792447200],true);
    }

    private static function evaluate(array $wrapper,array $subject,int $since,int $now,?int $until,bool $history):?array
    {
        if(($wrapper['access_status']??null)!=='allowed'||!self::sameSubject($wrapper['subject']??null,$subject)
            ||($subject['project_key']??null)!=='anytour'||!in_array($subject['platform']??null,['max','telegram'],true))return null;
        $s=$wrapper['contextual_signals']??[];
        if(($s['version']??null)!==ModeledConversionEarlyForecast::VERSION
            ||!is_array($s['events']??null)||count($s['events'])>1000)return null;
        if(!$history){
            if(($s['extractor_sha256']??null)!==hash_file('sha256',__DIR__.'/ArchiveResearchSemanticExtractor.php')
                ||($s['adapter_sha256']??null)!==hash_file('sha256',__DIR__.'/ModeledConversionEarlyForecast.php'))return null;
        }else{
            if(($s['extractor_version']??null)!==ArchiveResearchSemanticExtractor::VERSION
                ||!in_array($wrapper['adapter']['version']??null,['admitted_journal_profile_v1_1','admitted_journal_profile_v1_2','admitted_journal_profile_v1_3','admitted_journal_profile_v1_4','admitted_journal_profile_v1_5'],true)
                ||!in_array($wrapper['processing']['version']??null,['admitted_journal_profiles_v1','admitted_journal_profiles_v1_1','admitted_journal_profiles_v1_2'],true))return null;
            foreach(['extractor_sha256','adapter_sha256']as$key)if(!is_string($s[$key]??null)||preg_match('/\\A[a-f0-9]{64}\\z/D',$s[$key])!==1)return null;
        }
        $frozen=self::moment($s['frozen_at']??null);$cutoff=self::moment($s['input_cutoff_at']??null);
        if($frozen===null||$cutoff===null||$cutoff>$frozen||$frozen>$now||$frozen<$since||($until!==null&&$frozen>=$until))return null;
        $input=$wrapper['profile']['observation_input']??[];
        if(!self::sameSubject($input['subject']??null,$subject)||!is_array($input['evidence']??null)||count($input['evidence'])>1000)return null;
        $refs=[];
        foreach($input['evidence']as$e){
            if(!is_array($e)||!in_array($e['kind']??null,['customer_message','manager_message'],true)
                ||!self::sameSubject($e['subject']??null,$subject))continue;
            $r=$e['references']??null;if(!is_array($r)||count($r)!==1)continue;$r=$r[0];
            $at=self::moment($e['at']??null);$available=self::moment($e['available_at']??null);$ref=$r['id']??null;
            if(!is_string($ref)||preg_match('/\Ajournal:[1-9][0-9]{0,19}\z/D',$ref)!==1||($r['source']??null)!=='message'
                ||($e['id']??null)!=='message:'.$ref||($r['at']??null)!==($e['at']??null)
                ||($r['available_at']??null)!==($e['available_at']??null)
                ||$at===null||$available===null||$at>$available||$available>$cutoff)continue;
            $refs[$ref]=['at'=>$at,'available'=>$available,'role'=>$e['kind']==='customer_message'?'customer':'manager'];
        }
        $map=['substantive_interest'=>1,'general_purchase_intent'=>4,'agency_purchase_intent'=>5,'payment_self_report'=>6,'manager_payment_ack'=>6];
        $best=null;$contrary=false;
        foreach($s['events']as$event){
            if(!is_array($event))continue;$r=$refs[$event['source_ref']??'']??null;
            if(!$r||$r['at']<$since||($until!==null&&$r['at']>=$until))continue;
            $goal=$event['goal']??null;
            if(in_array($goal,['payment_cancellation','booking_cancellation','refund_report','purchased_elsewhere'],true)){$contrary=true;continue;}
            $rank=is_string($goal)?($map[$goal]??null):null;
            if($rank===null||($goal==='manager_payment_ack'?$r['role']!=='manager':$r['role']!=='customer')
                ||!in_array($event['product']??null,['tour','unknown'],true))continue;
            if($best===null||$rank>$best['rank'])$best=['rank'=>$rank,'stage'=>self::STAGES[$rank],'value'=>self::VALUES[$rank],
                'event_unix'=>$history?$now:$frozen,'evidence_at'=>$r['at'],'evidence_available_at'=>$r['available'],'source_ref'=>$event['source_ref']];
        }
        $p=$wrapper['semantic_prediction']??null;
        $checked=$history?ModeledConversionValueRegistry::predictionFields($wrapper):null;
        if(is_array($p)&&($p['status']??null)==='predicted'&&self::sameSubject($p['subject']??null,$subject)
            &&($history?($checked['semantic_prediction']['availability']??null)==='available':($p['model_sha256']??null)===hash_file('sha256',__DIR__.'/ModeledConversionSemanticPrediction.php'))
            &&ModeledConversionSemanticPrediction::digestMatches($p)){
            $rank=['substantive_dialogue'=>1,'concrete_request'=>2,'exact_offer_discussion'=>3,
                'general_purchase_intent'=>4,'agency_purchase_intent'=>5][$p['stage']??'']??null;
            $anchor=$p['current_request_anchor']??null;$r=$refs[$anchor??'']??null;
            if($rank!==null&&$r&&$r['role']==='customer'&&$r['at']>=$since
                &&($p['frozen_at']??null)===($s['frozen_at']??null)&&($best===null||$rank>$best['rank']))
                $best=['rank'=>$rank,'stage'=>self::STAGES[$rank],'value'=>self::VALUES[$rank],'event_unix'=>$history?$now:$frozen,
                    'evidence_at'=>$r['at'],'evidence_available_at'=>$r['available'],'source_ref'=>$anchor];
        }
        // Live owner-authorized V1 uses its frozen probability and commission projection.
        // Historical review keeps its original stage-only contract and never re-prices saved rows.
        if(!$history&&array_key_exists('deal_value_prediction',$wrapper)){
            $money=ModeledConversionDealValue::saved($wrapper);
            if($money===null)return null;
            if($best!==null&&$best['rank']===6){
                if($money['payment_hold_observed'])return null;
                $best['value']=$money['full_value_base_rub'];$best['prediction_value']=true;
            }elseif(is_array($p)&&($p['mode']??null)==='contextual_advertising_v1'&&($p['status']??null)==='predicted'){
                $verified=ModeledConversionValueRegistry::predictionFields($wrapper);
                if(($verified['semantic_prediction']['availability']??null)!=='available'||$money['status']!=='estimated')return null;
                $rank=['substantive_dialogue'=>1,'concrete_request'=>2,'exact_offer_discussion'=>3,'general_purchase_intent'=>4,'agency_purchase_intent'=>5][$p['stage']]??null;
                $anchor=$p['current_request_anchor'];$r=$refs[$anchor]??null;
                if($rank===null||!$r||$r['role']!=='customer'||$r['at']<$since)return null;
                $best=['rank'=>$rank,'stage'=>self::STAGES[$rank],'value'=>$money['value_rub'],'prediction_value'=>true,
                    'event_unix'=>$frozen,'evidence_at'=>$r['at'],'evidence_available_at'=>$r['available'],'source_ref'=>$anchor];
            }elseif($best!==null){
                $best['value']=(int)round(self::VALUES[$best['rank']]/self::BASE*$money['full_value_base_rub']);
                $best['prediction_value']=true;
            }
        }
        return $contrary?null:$best;
    }
    public static function sameSubject(mixed $a,array $b):bool
    {
        if(!is_array($a))return false;
        foreach(['project_key','platform','customer_key','conversation_id']as$k)if(!isset($a[$k],$b[$k])||$a[$k]!==$b[$k])return false;
        return true;
    }
    public static function moment(mixed $value):?int
    {
        if(!is_string($value)||preg_match('/\A\d{4}-\d\d-\d\dT\d\d:\d\d:\d\dZ\z/D',$value)!==1)return null;
        $v=strtotime($value);return $v!==false&&gmdate('Y-m-d\TH:i:s\Z',$v)===$value?$v:null;
    }
}
