<?php
declare(strict_types=1);

require_once __DIR__.'/ModeledConversionTextSignals.php';
require_once __DIR__.'/ArchivePredictiveRelativeWeights.php';
require_once __DIR__.'/ModeledConversionQuestionnaireContext.php';

/**
 * Pure, bounded first useful prediction over already admitted and redacted messages.
 * The caller owns authorization, chronological ordering and the canonical extractor.
 * Stage priors are neither trained nor calibrated. Extra signals are recorded for research; no text is retained.
 */
final class ModeledConversionSemanticPrediction
{
    public const VERSION='semantic_purchase_heuristic_v1';
    public const ASSUMPTIONS_VERSION='illustrative_funnel_2026_11_02_v1';
    public const STAGE_BASELINE_ASSUMPTIONS='stage_baseline_2026_11_06_v1';
    public const ADVERTISING_ASSUMPTIONS='early_dialogue_ads_2026_11_06_v1';
    public const ADVERTISING_WEIGHTS=['continued_meaningful_engagement'=>1.10,'continued_offer_search'=>1.10,'office_question'=>1.10,'payment_terms_question'=>1.15];
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
    private ?array $questionnaireQuestion=null;
    private array $questionnaireAnswers=[];
    private ?int $lastQuestionnaireAt=null;
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
    private bool $officeQuestion=false;
    private bool $paymentTermsQuestion=false;
    private ?array $relativeWeights;

    public function __construct(array $subject,private string $profilePredictionAt,
        private string $inputCutoffAt,private string $frozenAt,private string $cursor,private string $fence,?array $relativeWeights=null,private bool $advertisingV1=false)
    {
        if($relativeWeights!==null&&!ArchivePredictiveRelativeWeights::validCapsule($relativeWeights))self::fail('relative_weights');
        if($advertisingV1&&$relativeWeights!==null)self::fail('conflicting_policy');
        $this->relativeWeights=$relativeWeights;
        if(($subject['project_key']??null)!=='anytour'||!in_array($subject['platform']??null,['max','telegram'],true)
            ||!self::decimal($subject['customer_key']??null,false)||!self::decimal($subject['conversation_id']??null,false))
            self::fail('subject');
        $this->subject=['project_key'=>$subject['project_key'],'platform'=>$subject['platform'],
            'customer_key'=>$subject['customer_key'],'conversation_id'=>$subject['conversation_id']];
        $profile=self::moment($profilePredictionAt);$cutoff=self::moment($inputCutoffAt);$freeze=self::moment($frozenAt);
        if($profile===null||$cutoff===null||$freeze===null||$cutoff>$profile||$profile>$freeze
            ||$freeze>253402300799-self::HORIZON_SECONDS||!self::decimal($cursor)||!self::decimal($fence)
            ||self::greater($cursor,$fence))self::fail('clock_or_cursor');
        if($relativeWeights!==null&&$relativeWeights['issued_at_unix']>$freeze)self::fail('weights_after_forecast');
        $this->cutoff=$cutoff;$this->freeze=$freeze;
    }

    public function observeMessage(string $role,string $redactedText,string $ref,int $occurred,
        int $available,int $sourceVersion,array $canonicalSemanticResult):void
    {
        $meta=$this->metadata($ref,$occurred,$available,$sourceVersion);
        if($this->lastQuestionnaireAt!==null&&($occurred<$this->lastQuestionnaireAt
            ||$occurred-$this->lastQuestionnaireAt>86400))$this->barrier();
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
        // Any admitted human turn consumes the pending question, including an
        // unrecognised reply or an intervening manager message.
        $question=$this->questionnaireQuestion;$this->questionnaireQuestion=null;
        $questionnaireAnswer=$role==='customer'&&$sourceVersion===1&&$question!==null
            &&self::moment($question['occurred_at'])<=$occurred
            &&self::moment($question['available_at'])<=$available
            &&$occurred-self::moment($question['occurred_at'])<=86400
            &&($flags['quoted_or_relayed']??false)!==true&&($flags['past_trip']??false)!==true
            &&($flags['conditional']??false)!==true
            &&ModeledConversionQuestionnaireContext::accepts($question['field'],$redactedText);
        if($questionnaireAnswer)$this->questionnaireAnswer($question['field'],$meta);
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
        $textSignals=ModeledConversionTextSignals::scan($redactedText);
        $families=$directCustomer?$textSignals['criteria_families']:[];
        $comp=$directCustomer&&$textSignals['explicit_comparison'];
        $modify=$directCustomer&&$textSignals['explicit_modification'];
        $cheaper=$directCustomer&&$textSignals['price_alternative_continued_search'];
        $this->messages[$ref]['price_alternative']=$cheaper;
        // Bare logistics and greetings do not establish a trip or seller choice.
        $meaningful=$role==='customer'&&!$questionnaireAnswer&&($goals!==[]||($directCustomer&&(count($families)>=2
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
            $near=$directCustomer&&$textSignals['near_term_urgency'];
            $far=$directCustomer&&$textSignals['long_range_planning'];
            if($near||$far){$this->urgency=$near&&!$far;$this->longRange=$far;
                $this->evidence($far?'long_range_planning':'near_term_urgency',$meta);}
        }
        if($role==='customer'&&$this->anchor!==null
            &&($flags['quoted_or_relayed']??false)!==true&&($flags['past_trip']??false)!==true
            &&($flags['conditional']??false)!==true){
            foreach(['office_question'=>'officeQuestion','payment_terms_question'=>'paymentTermsQuestion']as$goal=>$property){
                foreach($r['events']as$event)if(($event['source_ref']??null)===$ref&&($event['goal']??null)===$goal){
                    $this->$property=true;
                    $this->evidence($goal,$meta);
                    break;
                }
            }
        }
        // A direct refusal reduces the hypothesis; a price objection alone does not.
        if($role==='customer'&&!$questionnaireAnswer&&($flags['quoted_or_relayed']??false)!==true
            &&($flags['past_trip']??false)!==true&&($flags['conditional']??false)!==true
            &&($flags['question']??false)!==true&&$textSignals['explicit_decline']){
            $this->declined=true;$this->evidence('explicit_decline',$meta);
        }
    }

    /** Unknown or unavailable source content cannot preserve current positive context. */
    public function barrier():void
    {
        $this->anchor=null;$this->anchorVersion=null;$this->anchorEvidence=null;
        $this->customerTurns=0;$this->meaningfulTurns=0;$this->lastMeaningfulAt=null;$this->positives=[];$this->criteria=[];
        $this->evidence=[];$this->messages=[];$this->currentOutcome=false;
        $this->questionnaireQuestion=null;$this->questionnaireAnswers=[];$this->lastQuestionnaireAt=null;
        $this->urgency=false;$this->longRange=false;$this->deferred=false;$this->declined=false;
        $this->comparison=false;$this->modification=false;$this->priceAlternative=false;
        $this->officeQuestion=false;$this->paymentTermsQuestion=false;
        $this->barriers=min(1000000,$this->barriers+1);
        // Known operational outcomes are a conservative global exclusion, never bank truth.
    }

    /** An applied-input receipt also needs its admitted customer input in this current context. */
    public function canPreserveCustomerContext(int $occurred,?string $inputRef=null):bool
    {
        $anchored=$this->anchor!==null&&$this->lastMeaningfulAt!==null
            &&$occurred>=$this->lastMeaningfulAt&&$occurred-$this->lastMeaningfulAt<=86400
            &&($inputRef===null||($this->messages[$inputRef]['role']??null)==='customer');
        $answer=$this->lastQuestionnaireAt!==null&&$occurred>=$this->lastQuestionnaireAt
            &&$occurred-$this->lastQuestionnaireAt<=86400
            &&($inputRef===null||isset($this->questionnaireAnswers[$inputRef]));
        $question=$inputRef===null&&$this->questionnaireQuestion!==null
            &&$occurred>=self::moment($this->questionnaireQuestion['occurred_at'])
            &&$occurred-self::moment($this->questionnaireQuestion['occurred_at'])<=86400;
        return $anchored||$answer||$question;
    }

    /**
     * Called only after the adapter validates an original native AI source and
     * its recording receipt. AI supplies question context, never a criterion.
     */
    public function observeServiceQuestion(string $text,string $ref,int $occurred,int $available,int $sourceVersion):bool
    {
        $meta=$this->metadata($ref,$occurred,$available,$sourceVersion);
        $this->questionnaireQuestion=null;
        $field=ModeledConversionQuestionnaireContext::field($text);
        if($sourceVersion!==1||$field===null)return false;
        if($this->lastQuestionnaireAt!==null&&($occurred<$this->lastQuestionnaireAt
            ||$occurred-$this->lastQuestionnaireAt>86400))$this->barrier();
        $this->questionnaireQuestion=['field'=>$field,...$meta];
        return true;
    }

    private function questionnaireAnswer(string $field,array $meta):void
    {
        $family=$field==='nights'?'dates':'party';
        $this->questionnaireAnswers[$meta['source_ref']]=$meta;
        $this->lastQuestionnaireAt=self::moment($meta['occurred_at']);
        $this->criteria[$family]=true;$this->evidence('criteria_'.$family,$meta);
        if($this->anchor===null&&count($this->criteria)>=2){
            $first=array_values($this->questionnaireAnswers)[0];
            $this->anchor=$first['source_ref'];$this->anchorVersion=$first['source_version_no'];
            $this->anchorEvidence=['feature'=>'current_request_anchor',...$first];
        }
        if($this->anchor!==null)$this->lastMeaningfulAt=max($this->lastMeaningfulAt??0,self::moment($meta['occurred_at']));
        // This inference adds no extra meaningful turn, outcome or multiplier.
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
        $weights=$legacyStatus==='access_denied'?null:$this->relativeWeights;
        if($weights!==null){
            $active=['continued_meaningful_engagement'=>$this->meaningfulTurns>=2,
                'near_term_urgency'=>$this->urgency,'long_range_planning'=>$this->longRange,
                'deferral'=>$this->deferred,'continued_offer_search'=>$this->comparison||$this->modification||$this->priceAlternative,
                'office_question'=>$this->officeQuestion,'payment_terms_question'=>$this->paymentTermsQuestion];
            foreach($active as$feature=>$present)if($present){
                $odds*=$weights['multipliers'][$feature];$reasons[]='archive_weight_'.$feature;
            }
        }
        // Owner-authorized early advertising hypotheses; timing and deferral have no penalty.
        if($this->advertisingV1&&$weights===null&&!$this->declined){
            $active=['continued_meaningful_engagement'=>$this->meaningfulTurns>=2,
                'continued_offer_search'=>$this->comparison||$this->modification||$this->priceAlternative,
                'office_question'=>$this->officeQuestion,'payment_terms_question'=>$this->paymentTermsQuestion];
            foreach(self::ADVERTISING_WEIGHTS as$feature=>$multiplier)if($active[$feature]){
                $odds*=$multiplier;$reasons[]='expert_dialogue_'.$feature;
            }
        }
        if($this->declined){$odds*=0.25;$reasons[]=$weights!==null?'hypothesis_explicit_decline':'explicit_decline_guard';}
        $odds=max(0.25,min(1.3,$odds));
        $probability=$status==='predicted'?round(($base*$odds)/(1-$base+$base*$odds),6):null;
        if($status!=='predicted')$reasons[]=$status;
        $families=array_keys($this->criteria);sort($families,SORT_STRING);
        $evidence=$this->anchorEvidence===null?[]:[$this->anchorEvidence];
        if($discussion!==null)$evidence[]=$discussion;
        foreach($this->evidence as$item){if(count($evidence)>=16)break;$evidence[]=$item;}
        $p=[
            'version'=>self::VERSION,'model_sha256'=>hash_file('sha256',__FILE__),'mode'=>'contextual_stage_baseline','target'=>'payment_with_current_agency_30d',
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
                'context_barriers'=>$this->barriers,'office_question'=>$this->officeQuestion,'payment_terms_question'=>$this->paymentTermsQuestion],
            'assumptions_version'=>self::STAGE_BASELINE_ASSUMPTIONS,'training_status'=>'not_trained',
            'calibration_status'=>'not_calibrated','probability_meaning'=>'illustrative_stage_prior_with_explicit_decline_guard',
            'newness_status'=>'first_v1_observation_not_new_person_or_trip',
            'trip_identity'=>'unverified','source_history_complete'=>false,
            'known_operational_outcome_meaning'=>'crm_state_not_verified_payment',
            'evaluation_eligible'=>false,'export_allowed'=>false,'expected_value_rub'=>null,
            'provider_calls'=>0,'model_calls'=>0,'conversion_uploads'=>0,
        ];
        if($this->advertisingV1){
            $p['mode']='contextual_advertising_v1';
            $p['assumptions_version']=self::ADVERTISING_ASSUMPTIONS;
            $p['probability_meaning']='expert_stage_and_dialogue_estimate_for_advertising';
        }
        // Denied callers receive no private message provenance or contextual features.
        if($weights!==null){
            $p['mode']='contextual_rules_with_archive_relative_weights';
            $p['assumptions_version']=ArchivePredictiveRelativeWeights::VERSION;
            $p['training_status']='archive_relative_weights';
            $p['probability_meaning']='illustrative_purchase_prior_with_retrospective_evidence_weights';
            $p['relative_weights']=$weights;
            $p['features']['office_question']=$this->officeQuestion;
            $p['features']['payment_terms_question']=$this->paymentTermsQuestion;
        }
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
