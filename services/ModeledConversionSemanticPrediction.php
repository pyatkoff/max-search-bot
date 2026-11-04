<?php
declare(strict_types=1);

/**
 * Pure, bounded first useful prediction over already admitted and redacted messages.
 * The caller owns authorization, chronological ordering and the canonical extractor.
 * These illustrative rules are neither trained nor calibrated; no text is retained.
 */
final class ModeledConversionSemanticPrediction
{
    public const VERSION='semantic_purchase_heuristic_v1';
    public const ASSUMPTIONS_VERSION='illustrative_funnel_2026_11_02_v1';
    public const HORIZON_SECONDS=2592000;
    public const MAX_BYTES=16384;
    /** Continuity policy is part of this model's source digest, never customer evidence. */
    public const OBSERVATIONAL_EVENT_TARGETS=[
        'metrika_max_bot_activity_start'=>'max_bot_activity_start',
        'metrika_max_manager_request'=>'max_manager_request',
        'metrika_max_manager_reply'=>'max_manager_reply',
        'metrika_max_customer_reply_after_manager'=>'max_customer_reply_after_manager',
    ];
    private const POSITIVE_GOALS=['substantive_interest','general_purchase_intent','agency_purchase_intent'];
    private const OUTCOME_GOALS=['payment_self_report','manager_payment_ack','booking_confirmation',
        'purchase_supported_candidate','payment_cancellation','booking_cancellation','refund_report',
        'purchased_elsewhere','document_issuance'];
    private const CONTRARY_OUTCOMES=['payment_cancellation','booking_cancellation','refund_report','purchased_elsewhere'];
    private const PRIORS=['substantive_dialogue'=>0.05,'concrete_request'=>0.083333,
        'exact_offer_discussion'=>0.166667,'general_purchase_intent'=>0.333333,'agency_purchase_intent'=>0.5];
    private array $subject;
    private int $cutoff;
    private int $freeze;
    private ?string $anchor=null;
    private ?int $anchorVersion=null;
    private ?array $anchorEvidence=null;
    private int $customerTurns=0;
    private int $meaningfulTurns=0;
    private ?int $lastMeaningfulAt=null;
    private array $positives=[];
    private array $criteria=[];
    private array $evidence=[];
    private array $messages=[];
    private array $outcomes=[];
    private bool $operationalOutcome=false;
    private bool $currentOutcome=false;
    private bool $urgency=false;
    private bool $longRange=false;
    private bool $deferred=false;
    private bool $declined=false;
    private bool $comparison=false;
    private bool $modification=false;
    private bool $priceAlternative=false;
    private int $barriers=0;

    public function __construct(array $subject,private string $profilePredictionAt,
        private string $inputCutoffAt,private string $frozenAt,private string $cursor,private string $fence)
    {
        if(($subject['project_key']??null)!=='anytour'||!in_array($subject['platform']??null,['max','telegram'],true)
            ||!self::decimal($subject['customer_key']??null,false)||!self::decimal($subject['conversation_id']??null,false))
            self::fail('subject');
        $this->subject=['project_key'=>$subject['project_key'],'platform'=>$subject['platform'],
            'customer_key'=>$subject['customer_key'],'conversation_id'=>$subject['conversation_id']];
        $profile=self::moment($profilePredictionAt);$cutoff=self::moment($inputCutoffAt);$freeze=self::moment($frozenAt);
        if($profile===null||$cutoff===null||$freeze===null||$cutoff>$profile||$profile>$freeze
            ||$freeze>253402300799-self::HORIZON_SECONDS||!self::decimal($cursor)||!self::decimal($fence)
            ||self::greater($cursor,$fence))self::fail('clock_or_cursor');
        $this->cutoff=$cutoff;$this->freeze=$freeze;
    }

    public function observeMessage(string $role,string $redactedText,string $ref,int $occurred,
        int $available,int $sourceVersion,array $canonicalSemanticResult):void
    {
        $meta=$this->metadata($ref,$occurred,$available,$sourceVersion);
        if(!in_array($role,['customer','manager'],true)||strlen($redactedText)>32768
            ||preg_match('//u',$redactedText)!==1)self::fail('message');
        $r=$canonicalSemanticResult;
        if(($r['version']??null)!=='ru_archive_contextual_v2'||($r['status']??null)!=='scanned'
            ||!is_array($r['flags']??null)||!is_array($r['events']??null)||count($r['events'])>32){
            $this->barrier();return;
        }
        $flags=$r['flags'];
        $reset=($r['episode_boundary']??null)!==null;
        foreach(['long_observation_gap','unresolved_trip_boundary','party_change_unresolved',
            'product_changed_or_alternative','source_namespace_barrier','context_barrier','reversed_clock','unknown_time']as$key)
            if(($flags[$key]??false)===true)$reset=true;
        if($reset)$this->barrier();
        if(($flags['reversed_clock']??false)===true||($flags['unknown_time']??false)===true
            ||($flags['context_barrier']??false)===true)return;
        if(isset($this->messages[$ref]))self::fail('duplicate_message');
        if(count($this->messages)>=50)self::fail('message_limit');
        $this->messages[$ref]=['role'=>$role,...$meta];
        if($role==='customer')$this->customerTurns++;
        $unsafe=false;
        foreach(['quoted_or_relayed','past_trip','conditional','negation']as$key)
            if(($flags[$key]??false)===true)$unsafe=true;
        $directCustomer=$role==='customer'&&!$unsafe;
        $goals=[];
        foreach($r['events']as$event){
            if(!is_array($event)||($event['source_ref']??null)!==$ref||!is_string($event['goal']??null))continue;
            $goal=$event['goal'];
            if(in_array($goal,self::OUTCOME_GOALS,true)){
                if(($goal==='payment_self_report'||$goal==='purchased_elsewhere')&&$role!=='customer')continue;
                if(in_array($goal,['manager_payment_ack','booking_confirmation','document_issuance'],true)&&$role!=='manager')continue;
                // Canonical outcomes already apply their own direct-statement guards.
                $this->outcome($goal,$meta);
                $this->currentOutcome=true;
            }
            $canonicalDirectIntent=$role==='customer'&&in_array($goal,['general_purchase_intent','agency_purchase_intent'],true)
                &&in_array($event['rule']??null,['direct_customer_booking_or_payment_intent','response_to_current_booking_context',
                    'explicit_seller_choice','customer_accepts_current_booking_mode'],true);
            if(($directCustomer||$canonicalDirectIntent)&&in_array($goal,self::POSITIVE_GOALS,true))$goals[$goal]=true;
            if($role==='customer'&&$goal==='deferral'
                &&($flags['quoted_or_relayed']??false)!==true&&($flags['past_trip']??false)!==true){
                $this->deferred=true;$this->evidence('deferral',$meta);
            }
        }
        $families=$directCustomer?self::criteriaFamilies($redactedText):[];
        $comp=$directCustomer&&self::hit('сравн\pL*|чем\s+отлича\pL*|какой\s+лучше|другие\s+вариант\pL*|ещ[её]\s+вариант\pL*',$redactedText);
        $modify=$directCustomer&&self::hit('поменя\pL*|замен\pL*|измен\pL*|давайте\s+(?:другой|другую|другие)|подберите\s+(?:нам\s+)?друг',$redactedText);
        $cheaper=$directCustomer&&self::hit('дорого\pL*|дороже|дорог(?:ой|ая|ие|ие|оват\pL*)',$redactedText)
            &&self::hit('(?:найдите|подберите|поищите|давайте)(?s:.{0,80})(?:дешевле|бюджетн\pL*)',$redactedText);
        $this->messages[$ref]['price_alternative']=$cheaper;
        // Bare logistics and greetings do not establish a trip or seller choice.
        $meaningful=$role==='customer'&&($goals!==[]||($directCustomer&&(count($families)>=2
            ||($this->anchor!==null&&($families!==[]||$comp||$modify||$cheaper)))));
        if($meaningful){
            if($this->anchor===null){
                $this->anchor=$ref;$this->anchorVersion=$sourceVersion;
                $this->anchorEvidence=['feature'=>'current_request_anchor',...$meta];
            }
            $this->meaningfulTurns++;$this->lastMeaningfulAt=$occurred;
            foreach($goals as$goal=>$yes){$this->positives[$goal]=true;$this->evidence($goal,$meta);}
            foreach($families as$family){$this->criteria[$family]=true;$this->evidence('criteria_'.$family,$meta);}
            if($comp){$this->comparison=true;$this->evidence('explicit_comparison',$meta);}
            if($modify){$this->modification=true;$this->evidence('explicit_modification',$meta);}
            if($cheaper){$this->priceAlternative=true;$this->evidence('price_alternative_continued_search',$meta);}
            if(isset($goals['general_purchase_intent'])||isset($goals['agency_purchase_intent'])){
                $this->deferred=false;$this->declined=false;
            }
            $near=$directCustomer&&self::hit('срочно|как\s+можно\s+скорее|в\s+ближайшие\s+дни|на\s+этой\s+неделе|(?:вылет|улететь|решить|купить|бронировать)(?s:.{0,35})(?:сегодня|завтра)',$redactedText);
            $far=$directCustomer&&self::hit('на\s+следующ\pL*\s+год|в\s+следующ\pL*\s+году|через\s+(?:полгода|год|[3-9]\s+месяц\pL*|1[0-2]\s+месяц\pL*)|за\s+год\s+заранее',$redactedText);
            if($near||$far){$this->urgency=$near&&!$far;$this->longRange=$far;
                $this->evidence($far?'long_range_planning':'near_term_urgency',$meta);}
        }
        // A direct refusal reduces the hypothesis; a price objection alone does not.
        if($role==='customer'&&($flags['quoted_or_relayed']??false)!==true
            &&($flags['past_trip']??false)!==true&&($flags['conditional']??false)!==true
            &&($flags['question']??false)!==true&&self::hit('не\s+будем\s+(?:покупать|бронировать|ехать)|отказываемся\s+от\s+(?:тура|поездки)|поездка\s+отменяется|тур\s+(?:нам\s+)?не\s+нужен',$redactedText)){
            $this->declined=true;$this->evidence('explicit_decline',$meta);
        }
    }

    /** Unknown or unavailable source content cannot preserve current positive context. */
    public function barrier():void
    {
        $this->anchor=null;$this->anchorVersion=null;$this->anchorEvidence=null;
        $this->customerTurns=0;$this->meaningfulTurns=0;$this->lastMeaningfulAt=null;$this->positives=[];$this->criteria=[];
        $this->evidence=[];$this->messages=[];$this->currentOutcome=false;
        $this->urgency=false;$this->longRange=false;$this->deferred=false;$this->declined=false;
        $this->comparison=false;$this->modification=false;$this->priceAlternative=false;
        $this->barriers=min(1000000,$this->barriers+1);
        // Known operational outcomes are a conservative global exclusion, never bank truth.
    }

    /** A verified service reply or receipt may preserve, never establish or refresh, recent customer meaning. */
    public function canPreserveCustomerContext(int $occurred):bool
    {
        return $this->anchor!==null&&$this->lastMeaningfulAt!==null
            &&$occurred>=$this->lastMeaningfulAt&&$occurred-$this->lastMeaningfulAt<=86400;
    }

    public function knownOperationalOutcome(string $ref,int $occurred,int $available,int $sourceVersion):void
    {
        $meta=$this->metadata($ref,$occurred,$available,$sourceVersion);
        $this->operationalOutcome=true;$this->outcome('known_operational_outcome',$meta);
    }

    public function finish(array $legacyPacket):array
    {
        $legacy=$legacyPacket['early_forecast']??[];
        $legacyStatus=$legacy['status']??null;
        $discussion=$this->currentOfferDiscussion($legacy['early_quality']??null);
        $stage=null;$base=null;
        if($this->anchor!==null){
            $stage='substantive_dialogue';
            if(count($this->criteria)>=2)$stage='concrete_request';
            if($discussion!==null)$stage='exact_offer_discussion';
            if(isset($this->positives['general_purchase_intent']))$stage='general_purchase_intent';
            if(isset($this->positives['agency_purchase_intent']))$stage='agency_purchase_intent';
            $base=self::PRIORS[$stage];
        }
        $status='predicted';
        if($legacyStatus==='access_denied')$status='access_denied';
        elseif($this->operationalOutcome||$this->currentOutcome||$this->outcomes!==[]||$legacyStatus==='target_already_observed')$status='known_outcome';
        elseif($legacyStatus!=='early_assessment'||$base===null)$status='insufficient_context';
        $reasons=[];$odds=1.0;
        if($stage!==null)$reasons[]='illustrative_prior_'.$stage;
        if($discussion!==null)$reasons[]='exact_current_offer_price_alternative';
        if($this->priceAlternative)$reasons[]='price_alternative_is_continued_search';
        if($this->urgency){$odds*=1.2;$reasons[]='hypothesis_near_term_urgency';}
        if($this->meaningfulTurns>=2){$odds*=1.1;$reasons[]='hypothesis_continued_meaningful_engagement';}
        if($this->longRange){$odds*=0.65;$reasons[]='hypothesis_long_range_planning';}
        if($this->deferred){$odds*=0.5;$reasons[]='hypothesis_deferral';}
        if($this->declined){$odds*=0.25;$reasons[]='hypothesis_explicit_decline';}
        $odds=max(0.25,min(1.3,$odds));
        $probability=$status==='predicted'?round(($base*$odds)/(1-$base+$base*$odds),6):null;
        if($status!=='predicted')$reasons[]=$status;
        $families=array_keys($this->criteria);sort($families,SORT_STRING);
        $evidence=$this->anchorEvidence===null?[]:[$this->anchorEvidence];
        if($discussion!==null)$evidence[]=$discussion;
        foreach($this->evidence as$item){if(count($evidence)>=16)break;$evidence[]=$item;}
        $p=[
            'version'=>self::VERSION,'model_sha256'=>hash_file('sha256',__FILE__),'mode'=>'contextual_rules_untrained','target'=>'payment_with_current_agency_30d',
            'subject'=>$this->subject,'current_request_anchor'=>$this->anchor,
            'anchor_source_version_no'=>$this->anchorVersion,'current_customer_turn_count'=>$this->customerTurns,
            'journal_cursor'=>$this->cursor,'source_fence_journal_id'=>$this->fence,
            'profile_prediction_at'=>$this->profilePredictionAt,'prediction_at'=>$this->frozenAt,
            'input_cutoff_at'=>$this->inputCutoffAt,'frozen_at'=>$this->frozenAt,
            'horizon_seconds'=>self::HORIZON_SECONDS,
            'horizon_end_at'=>gmdate('Y-m-d\TH:i:s\Z',$this->freeze+self::HORIZON_SECONDS),
            'status'=>$status,'stage'=>$stage,'base_probability'=>$status==='predicted'?$base:null,'estimated_probability'=>$probability,
            'hypothesis_odds_multiplier'=>$status==='predicted'?round($odds,6):null,
            'reasons'=>array_slice($reasons,0,16),'evidence'=>$evidence,
            'outcome_observations'=>array_values($this->outcomes),
            'features'=>['criteria_families'=>$families,'meaningful_customer_turns'=>$this->meaningfulTurns,
                'explicit_comparison'=>$this->comparison,'explicit_modification'=>$this->modification,
                'price_alternative_continued_search'=>$this->priceAlternative,'near_term_urgency'=>$this->urgency,
                'long_range_planning'=>$this->longRange,'deferral'=>$this->deferred,'explicit_decline'=>$this->declined,
                'context_barriers'=>$this->barriers],
            'assumptions_version'=>self::ASSUMPTIONS_VERSION,'training_status'=>'not_trained',
            'calibration_status'=>'not_calibrated','probability_meaning'=>'illustrative_semantic_hypothesis_not_observed_payment',
            'newness_status'=>'first_v1_observation_not_new_person_or_trip',
            'trip_identity'=>'unverified','source_history_complete'=>false,
            'known_operational_outcome_meaning'=>'crm_state_not_verified_payment',
            'evaluation_eligible'=>false,'export_allowed'=>false,'expected_value_rub'=>null,
            'provider_calls'=>0,'model_calls'=>0,'conversion_uploads'=>0,
        ];
        // Denied callers receive no private message provenance or contextual features.
        if($status==='access_denied'){
            $p['current_request_anchor']=null;$p['anchor_source_version_no']=null;$p['current_customer_turn_count']=0;
            $p['stage']=null;$p['base_probability']=null;$p['reasons']=['access_denied'];$p['evidence']=[];
            $p['outcome_observations']=[];$p['features']=['criteria_families'=>[],'meaningful_customer_turns'=>0];
        }
        $p['prediction_digest']=self::digest($p);
        if(strlen(json_encode($p,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR))>self::MAX_BYTES)self::fail('output_limit');
        return $p;
    }

    /** Stable across native JSON object order and CLI/web float-format settings. */
    public static function digest(array $prediction):string
    {
        unset($prediction['prediction_digest']);
        return hash('sha256',self::encodeDigestValue(self::ordered($prediction),false));
    }

    /** Earlier V1 hashes/capsules may use six-decimal JSON; current code cannot downgrade its codec. */
    public static function digestMatches(array $prediction):bool
    {
        try{
            $given=$prediction['prediction_digest']??null;
            if(!is_string($given)||preg_match('/\\A[a-f0-9]{64}\\z/D',$given)!==1)return false;
            if(hash_equals($given,self::digest($prediction)))return true;
            $current=hash_file('sha256',__FILE__);
            if(!is_string($current))return false;
            if(array_key_exists('model_sha256',$prediction)&&(!is_string($prediction['model_sha256'])
                ||$prediction['model_sha256']===$current))return false;
            unset($prediction['prediction_digest']);
            return hash_equals($given,hash('sha256',self::encodeDigestValue(self::ordered($prediction),true)));
        }catch(Throwable $ignored){return false;}
    }

    /** Explicit finite-float spelling: no global/request ini or locale mutation. */
    private static function encodeDigestValue(mixed $value,bool $legacySixDecimals):string
    {
        if(is_array($value)){
            $list=array_is_list($value);$parts=[];
            foreach($value as$key=>$item){
                $encoded=self::encodeDigestValue($item,$legacySixDecimals);
                $parts[]=$list?$encoded:json_encode((string)$key,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR).':'.$encoded;
            }
            return ($list?'[':'{').implode(',',$parts).($list?']':'}');
        }
        if(is_float($value)){
            if(!is_finite($value))self::fail('digest_float');
            if($legacySixDecimals){
                if($value!==round($value,6)||abs($value)>1.3)self::fail('legacy_digest_precision');
                return rtrim(rtrim(sprintf('%.6F',$value),'0'),'.');
            }
            return sprintf('%.17h',$value);
        }
        if(!is_int($value)&&!is_bool($value)&&!is_string($value)&&$value!==null)self::fail('digest_type');
        return json_encode($value,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
    }

    private function currentOfferDiscussion(mixed $quality):?array
    {
        if($this->anchor===null||!is_array($quality)||($quality['version']??null)!=='request_offer_reaction_v2'
            ||!is_array($quality['specific_option_discussions']??null)||count($quality['specific_option_discussions'])>50)return null;
        foreach($quality['specific_option_discussions']as$d){
            if(!is_array($d)||($d['current_candidate']??null)!==true||($d['reaction']??null)!=='price_alternative_request'
                ||($d['search_continuation']??null)!=='explicit_request'||!is_array($d['source_refs']??null)
                ||count($d['source_refs'])!==4||!array_is_list($d['source_refs'])||$d['source_refs'][0]!==$this->anchor)continue;
            $valid=true;foreach($d['source_refs']as$ref)if(!self::ref($ref)||self::greater(substr($ref,8),$this->fence))$valid=false;
            if(!$valid||count(array_unique($d['source_refs']))!==4)continue;
            $last=$d['source_refs'][3];$message=$this->messages[$last]??null;
            $available=self::moment($d['available_at']??null);
            if(!is_array($message)||$message['role']!=='customer'||$available===null||$available>$this->cutoff
                ||$available<self::moment($message['available_at'])||!$this->priceAlternative)continue;
            // Match the current direct, nonquoted reaction, not a historical global count.
            if(($message['price_alternative']??false)!==true)continue;
            return ['feature'=>'exact_current_offer_discussion','source_ref'=>$last,'occurred_at'=>$message['occurred_at'],
                'available_at'=>gmdate('Y-m-d\TH:i:s\Z',$available),'source_version_no'=>$message['source_version_no']];
        }
        return null;
    }

    private function metadata(string $ref,int $occurred,int $available,int $version):array
    {
        if(!self::ref($ref)||self::greater(substr($ref,8),$this->fence)||$occurred<946684800
            ||$occurred>$available||$available>$this->cutoff||$version<1||$version>1000000)self::fail('source');
        return ['source_ref'=>$ref,'occurred_at'=>gmdate('Y-m-d\TH:i:s\Z',$occurred),
            'available_at'=>gmdate('Y-m-d\TH:i:s\Z',$available),'source_version_no'=>$version];
    }
    private function evidence(string $feature,array $meta):void
    {
        $this->evidence[$feature]=['feature'=>$feature,...$meta];
        while(count($this->evidence)>15)array_shift($this->evidence);
    }
    private function outcome(string $kind,array $meta):void
    {
        $key=$kind.'|'.$meta['source_ref'];$this->outcomes[$key]=['kind'=>$kind,...$meta];
        while(count($this->outcomes)>8){
            $remove=null;
            foreach($this->outcomes as$oldKey=>$observation)
                if(!in_array($observation['kind'],self::CONTRARY_OUTCOMES,true)
                    &&$observation['kind']!=='known_operational_outcome'){$remove=$oldKey;break;}
            if($remove===null)array_shift($this->outcomes);else unset($this->outcomes[$remove]);
        }
    }
    private static function criteriaFamilies(string $text):array
    {
        $rules=[
            'destination'=>'Турци\pL*|Египет\pL*|Таиланд\pL*|Мальдив\pL*|ОАЭ|Дуба[йи]\pL*|Тунис\pL*|Кипр\pL*|Греци\pL*|Испани\pL*|Итали\pL*|Вьетнам\pL*|Индонези\pL*|Бали|Куб[ауе]|Шри[- ]Ланк\pL*|Гоа|Сочи|Абхази\pL*',
            'dates'=>'январ\pL*|феврал\pL*|март\pL*|апрел\pL*|(?<!\pL)ма[йяе](?!\pL)|июн\pL*|июл\pL*|август\pL*|сентябр\pL*|октябр\pL*|ноябр\pL*|декабр\pL*|[0-3]?[0-9][./][01]?[0-9](?:[./][0-9]{2,4})?|\d+\s*(?:ноч(?:ь|и|ей)|дн(?:я|ей))|сегодня|завтра|на\s+этой\s+неделе|следующ\pL*\s+год|через\s+(?:полгода|год)',
            'party'=>'\d+\s*(?:взросл\pL*|дет\pL*|реб[её]н\pL*|чел\pL*|турист\pL*)|двое|трое|семь[её]й|с\s+(?:реб[её]нком|детьми)',
            'budget'=>'бюджет\pL*|\d[\d ,.]*\s*(?:тыс(?:яч\pL*)?|руб\pL*|₽|доллар\pL*|евро)|до\s+\d+\s*[$€]',
            'requirements'=>'вс[её]\s+включено|ультра\s+вс[её]|первая\s+лини\pL*|первой\s+лини\pL*|песчан\pL*\s+пляж|подогрева\pL*\s+бассейн|аквапарк|детск\pL*\s+(?:клуб|меню)|[345]\s*(?:зв[её]зд\pL*|\*)|без\s+пересад\pL*',
        ];
        $out=[];foreach($rules as$family=>$pattern)if(self::hit($pattern,$text))$out[]=$family;return$out;
    }
    private static function ordered(array $value):array
    {
        if(!array_is_list($value))ksort($value,SORT_STRING);
        foreach($value as&$item)if(is_array($item))$item=self::ordered($item);
        return$value;
    }
    private static function moment(mixed $value):?int
    {
        if(!is_string($value)||preg_match('/\A[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}Z\z/D',$value)!==1)return null;
        $time=strtotime($value);return$time!==false&&gmdate('Y-m-d\TH:i:s\Z',$time)===$value?$time:null;
    }
    private static function decimal(mixed $value,bool $zero=true):bool
    {return is_string($value)&&preg_match($zero?'/\A(?:0|[1-9][0-9]{0,19})\z/D':'/\A[1-9][0-9]{0,19}\z/D',$value)===1;}
    private static function ref(mixed $value):bool
    {return is_string($value)&&preg_match('/\Ajournal:[1-9][0-9]{0,19}\z/D',$value)===1;}
    private static function greater(string $a,string $b):bool
    {return strlen($a)!==strlen($b)?strlen($a)>strlen($b):strcmp($a,$b)>0;}
    private static function hit(string $pattern,string $text):bool{return preg_match('~'.$pattern.'~iu',$text)===1;}
    private static function fail(string $reason):never{throw new InvalidArgumentException('modeled_semantic_prediction_'.$reason);}
}
