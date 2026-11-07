<?php
declare(strict_types=1);
require_once __DIR__.'/ModeledConversionAssignedValue.php';
require_once __DIR__.'/ModeledConversionSemanticPrediction.php';
require_once __DIR__.'/InternalOwnerDialogueScope.php';

/** Private read-time current registry over existing membership and profile stores. No writes. */
final class ModeledConversionValueRegistry
{
    public function __construct(private PDO $pdo){}

    public function members(array $page):array
    {
        $rows=[];$omitted=0;
        foreach($page['members']as$member){
            // Use exact bound MAX identity; no global linked-person or source guess.
            $q=$this->pdo->prepare("SELECT c.id,c.customer_id,c.is_test,".InternalOwnerDialogueScope::conversationSql('c')." AS internal_owner FROM conversations c JOIN customer_channels cc ON cc.id=c.customer_channel_id AND cc.customer_id=c.customer_id AND cc.project_key=c.project_key AND cc.channel=c.channel WHERE c.project_key='anytour' AND c.channel='max' AND cc.external_user_id=? AND c.external_chat_id=? ORDER BY c.id DESC LIMIT 2");
            $q->execute([$member['external_user_id'],'-'.$member['external_user_id']]);$bindings=$q->fetchAll(PDO::FETCH_ASSOC);
            if(array_filter($bindings,static fn(array $r):bool=>(bool)$r['is_test']||(bool)$r['internal_owner'])){$omitted++;continue;}
            $value=ModeledConversionAssignedValue::member($member,$page['observed_at_unix']);
            $value=array_replace($value,self::predictionFields([]));
            $value['attribution']=$member['attribution'];$value['profile_binding']=count($bindings)>1?'ambiguous':'no_dialogue';
            if(count($bindings)===1){
                $p=$this->latestProfile((string)$bindings[0]['id'],(string)$bindings[0]['customer_id'],$page['observed_at_unix']);
                if($p!==null&&($p['access_status']??null)==='allowed'){
                    $value=ModeledConversionAssignedValue::merge($value,ModeledConversionAssignedValue::profile($p));
                    $value=array_replace($value,self::predictionFields($p));
                    $value['profile_binding']='exact_private_chat_current_profile';
                    $value['coverage']['dialogue_scope']='conversation_prefix_not_verified_current_trip';
                }
            }
            $rows[]=$value;
        }
        return ['rules_version'=>ModeledConversionAssignedValue::VERSION,'rows'=>$rows,'next_after'=>$page['next_after'],
            'has_more'=>$page['has_more'],'omitted_owner_or_test'=>$omitted,'coverage'=>$page['coverage'],
            'telegram_membership'=>'not_available_from_max_bridge','export_allowed'=>false,'conversion_uploads'=>0];
    }

    /** A read-only history projection, deliberately separate from assigned-value members. */
    public function historyMembers(array $page,string $sourceAfter,string $cursorKey,int $now):array
    {
        require_once __DIR__.'/MaxTrackerLookupClient.php';
        require_once __DIR__.'/MaxMemberHistoryViewCursor.php';
        $page=MaxTrackerLookupClient::validateHistoryMemberPage(['ok'=>true,'version'=>1,'counter_id'=>'98615635',
            'tracker_timezone'=>'Europe/London','history_member_page'=>$page],'98615635',$sourceAfter,$now);
        if($this->pdo->inTransaction())throw new RuntimeException('member_history_auth_snapshot');
        if($this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql')
            $this->pdo->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ, READ ONLY');
        $this->pdo->beginTransaction();
        try{
            $rows=[];$omitted=0;
            foreach($page['rows']as$member){
                $base=" FROM conversations c JOIN customer_channels cc ON cc.id=c.customer_channel_id AND cc.customer_id=c.customer_id"
                    ." AND cc.project_key=c.project_key AND cc.channel=c.channel WHERE c.project_key='anytour' AND c.channel='max'"
                    ." AND cc.external_user_id=? AND c.external_chat_id=?";
                $args=[$member['external_user_id'],'-'.$member['external_user_id']];
                $q=$this->pdo->prepare("SELECT 1".$base." AND (c.is_test=1 OR ".InternalOwnerDialogueScope::conversationSql('c').") LIMIT 1");
                $q->execute($args);$blocked=$q->fetchColumn()!==false;$q->closeCursor();
                if($blocked){$omitted++;continue;}
                $q=$this->pdo->prepare("SELECT c.id,(SELECT SUBSTR(COALESCE(cu.display_name,''),1,120) FROM customers cu WHERE cu.id=c.customer_id) AS display_name"
                    .$base." AND c.is_test=0 ORDER BY c.id DESC LIMIT 2");
                $q->execute($args);$bindings=$q->fetchAll(PDO::FETCH_ASSOC);$q->closeCursor();
                $label='Пользователь MAX '.$member['external_user_id'];
                if(count($bindings)===1&&is_string($bindings[0]['display_name'])&&trim($bindings[0]['display_name'])!==''
                    &&strlen($bindings[0]['display_name'])<=480&&preg_match('//u',$bindings[0]['display_name'])===1)
                    $label=trim($bindings[0]['display_name']);
                $rows[]=['member'=>$member,'display_name'=>$label];
            }
            $next=$page['next_cursor']===null?null:MaxMemberHistoryViewCursor::wrap($page['next_cursor'],$cursorKey,$now);
            return ['basis'=>$page['basis'],'rows'=>$rows,'cutoff_unix'=>$page['cutoff_unix'],
                'history_fence_id'=>$page['history_fence_id'],'next_after'=>$next,'has_more'=>$page['has_more'],
                'scanned_event_count'=>$page['scanned_event_count'],'omitted_unknown_count'=>$page['omitted_unknown_count'],
                'omitted_owner_or_test'=>$omitted,'coverage'=>$page['coverage']];
        }finally{if($this->pdo->inTransaction())$this->pdo->rollBack();}
    }

    /** Existing admitted current dialogue snapshots; member identity is not guessed. */
    public function profiles(string $after,int $now):array
    {
        if(preg_match('/\A(?:0|[1-9][0-9]{0,19})\z/D',$after)!==1)throw new InvalidArgumentException('value_registry_cursor');
        $length=$this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'?'OCTET_LENGTH(p.profile_json)':'LENGTH(CAST(p.profile_json AS BLOB))';
        $q=$this->pdo->prepare('SELECT p.id,CASE WHEN '.$length.'<=131072 THEN p.profile_json ELSE NULL END AS profile_json FROM modeled_conversion_profile_versions p'
            .' JOIN conversations c ON c.id=p.conversation_id AND c.customer_id=p.customer_key AND c.project_key=p.project_key AND c.channel=p.platform'
            .' JOIN customer_channels cc ON cc.id=c.customer_channel_id AND cc.customer_id=c.customer_id AND cc.project_key=c.project_key AND cc.channel=c.channel'
            .' WHERE p.project_key=? AND p.id>? AND p.observed_at_unix<=? AND c.is_test=0 AND NOT '.InternalOwnerDialogueScope::conversationSql('c')
            .' AND NOT EXISTS (SELECT 1 FROM modeled_conversion_profile_versions n WHERE n.project_key=p.project_key AND n.platform=p.platform AND n.customer_key=p.customer_key AND n.conversation_id=p.conversation_id AND n.id>p.id AND n.observed_at_unix<=?) ORDER BY p.id LIMIT 6');
        $q->execute(['anytour',$after,$now,$now]);$page=$q->fetchAll(PDO::FETCH_ASSOC);$more=count($page)>5;$rows=[];$omitted=0;
        foreach(array_slice($page,0,5)as$r){
            $after=(string)$r['id'];if(!is_string($r['profile_json'])){$omitted++;continue;}
            $p=json_decode($r['profile_json'],true,64,JSON_THROW_ON_ERROR);
            if(($p['access_status']??null)!=='allowed'){$omitted++;continue;}
            $v=array_replace(ModeledConversionAssignedValue::profile($p),self::predictionFields($p));$v['attribution']=['status'=>'unknown','reason'=>'profile_does_not_prove_advertising_visit'];
            $v['coverage']['unit']='unresolved_conversation_prefix_not_unique_person';$rows[]=$v;
        }
        return ['rows'=>$rows,'next_after'=>$after,'has_more'=>$more,'omitted'=>$omitted,
            'rules_version'=>ModeledConversionAssignedValue::VERSION,'coverage'=>'bounded_current_admitted_profiles_not_complete_cohort',
            'do_not_sum_with_member_rows'=>'unresolved_identity_or_same_stage_may_overlap',
            'export_allowed'=>false,'conversion_uploads'=>0];
    }


    /** Pure private projection: bounded, digest-checked and fixed-field only. */
    public static function predictionFields(array $wrapper):array
    {
        $subject=self::predictionSubject($wrapper['subject']??null);
        $result=['prediction_subject'=>$subject,'semantic_prediction'=>['availability'=>'not_saved','value'=>null],
            'prediction_followup'=>['availability'=>'not_saved','value'=>null]];
        $present=array_key_exists('semantic_prediction',$wrapper);
        $followupPresent=array_key_exists('prediction_followup',$wrapper);
        if(!$present&&!$followupPresent)return$result;
        if(($wrapper['access_status']??null)!=='allowed'||$subject===null){
            if($present)$result['semantic_prediction']['availability']='unavailable';
            if($followupPresent)$result['prediction_followup']['availability']='unavailable';
            return$result;
        }
        $prediction=$present?self::validPrediction($wrapper['semantic_prediction'],$subject,$wrapper['profile']['prediction_at']??null):null;
        if($present)$result['semantic_prediction']=['availability'=>$prediction===null?'unavailable':'available','value'=>$prediction];
        if($followupPresent){
            $followup=$prediction===null?null:self::validFollowup($wrapper['prediction_followup'],$prediction,$subject);
            $result['prediction_followup']=['availability'=>$followup===null?'unavailable':'available','value'=>$followup];
        }
        return$result;
    }

    /** The pure renderer rechecks the capsule instead of trusting arbitrary row strings. */
    public static function predictionFieldsForView(array $row):array
    {
        $p=$row['semantic_prediction']??null;$f=$row['prediction_followup']??null;
        $rawPrediction=is_array($p)&&is_array($p['value']??null)?$p['value']:null;
        $wrapper=['subject'=>$row['prediction_subject']??null,'access_status'=>'allowed',
            'profile'=>['prediction_at'=>$rawPrediction['profile_prediction_at']??null]];
        foreach(['semantic_prediction'=>$p,'prediction_followup'=>$f]as$key=>$capsule){
            if(is_array($capsule)&&($capsule['availability']??null)==='available')$wrapper[$key]=$capsule['value']??null;
        }
        $result=self::predictionFields($wrapper);
        foreach(['semantic_prediction'=>$p,'prediction_followup'=>$f]as$key=>$capsule){
            if($capsule===null)continue;
            if(!is_array($capsule)||!in_array($capsule['availability']??null,['not_saved','unavailable','available'],true)
                ||($capsule['availability']??null)==='unavailable')$result[$key]=['availability'=>'unavailable','value'=>null];
        }
        return$result;
    }

    private static function validPrediction(mixed $p,array $subject,mixed $profileAt):?array
    {
        try{
            if(!is_array($p)||array_is_list($p)||!self::boundedJson($p,16384)
                ||!self::samePredictionSubject($p['subject']??null,$subject)
                ||!self::sha($p['prediction_digest']??null)||!self::sha($p['model_sha256']??null)
                ||!ModeledConversionSemanticPrediction::digestMatches($p))return null;
            $fixed=['version'=>'semantic_purchase_heuristic_v1','mode'=>'contextual_rules_untrained',
                'target'=>'payment_with_current_agency_30d','horizon_seconds'=>2592000,
                'assumptions_version'=>'illustrative_funnel_2026_11_02_v1','training_status'=>'not_trained',
                'calibration_status'=>'not_calibrated','probability_meaning'=>'illustrative_semantic_hypothesis_not_observed_payment',
                'newness_status'=>'first_v1_observation_not_new_person_or_trip','trip_identity'=>'unverified',
                'source_history_complete'=>false,'known_operational_outcome_meaning'=>'crm_state_not_verified_payment',
                'evaluation_eligible'=>false,'export_allowed'=>false,'expected_value_rub'=>null,
                'provider_calls'=>0,'model_calls'=>0,'conversion_uploads'=>0];
            $relative=($p['training_status']??null)==='archive_relative_weights';
            $stageOnly=($p['mode']??null)==='contextual_stage_baseline';
            $advertising=($p['mode']??null)==='contextual_advertising_v1';
            if($advertising){
                if($relative)return null;
                $fixed['mode']='contextual_advertising_v1';
                $fixed['assumptions_version']=ModeledConversionSemanticPrediction::ADVERTISING_ASSUMPTIONS;
                $fixed['probability_meaning']='expert_stage_and_dialogue_estimate_for_advertising';
            }
            if($stageOnly){
                if($relative)return null;
                $fixed['mode']='contextual_stage_baseline';
                $fixed['assumptions_version']=ModeledConversionSemanticPrediction::STAGE_BASELINE_ASSUMPTIONS;
                $fixed['probability_meaning']='illustrative_stage_prior_with_explicit_decline_guard';
            }
            if($relative){
                if(!ArchivePredictiveRelativeWeights::validCapsule($p['relative_weights']??null))return null;
                $fixed['mode']='contextual_rules_with_archive_relative_weights';
                $fixed['assumptions_version']=ArchivePredictiveRelativeWeights::VERSION;
                $fixed['training_status']='archive_relative_weights';
                $fixed['probability_meaning']='illustrative_purchase_prior_with_retrospective_evidence_weights';
            }
            foreach($fixed as$key=>$value)if(!array_key_exists($key,$p)||$p[$key]!==$value)return null;
            $fields=[...array_keys($fixed),'model_sha256','subject','current_request_anchor','anchor_source_version_no',
                'current_customer_turn_count','journal_cursor','source_fence_journal_id','profile_prediction_at',
                'prediction_at','input_cutoff_at','frozen_at','horizon_end_at','status','stage','base_probability',
                'estimated_probability','hypothesis_odds_multiplier','reasons','evidence','outcome_observations','features','prediction_digest'];
            if($relative)$fields[]='relative_weights';
            if(count($p)!==count($fields)||array_diff(array_keys($p),$fields))return null;
            $cutoff=self::predictionMoment($p['input_cutoff_at']);$profile=self::predictionMoment($p['profile_prediction_at']);
            $freeze=self::predictionMoment($p['frozen_at']);$end=self::predictionMoment($p['horizon_end_at']);
            if($cutoff===null||$profile===null||$freeze===null||$end===null||$cutoff>$profile||$profile>$freeze
                ||$p['profile_prediction_at']!==$profileAt||$p['prediction_at']!==$p['frozen_at']||$end-$freeze!==2592000
                ||!self::predictionDecimal($p['journal_cursor'])||!self::predictionDecimal($p['source_fence_journal_id'])
                ||self::predictionGreater($p['journal_cursor'],$p['source_fence_journal_id']))return null;
            if($relative&&$p['relative_weights']['issued_at_unix']>$freeze)return null;
            $fence=$p['source_fence_journal_id'];$anchor=$p['current_request_anchor'];
            if(!is_int($p['current_customer_turn_count'])||$p['current_customer_turn_count']<0||$p['current_customer_turn_count']>50
                ||($anchor===null&&$p['anchor_source_version_no']!==null)
                ||($anchor!==null&&(!self::predictionRef($anchor,$fence)||!self::sourceVersion($p['anchor_source_version_no'])
                    ||$p['current_customer_turn_count']<1)))return null;
            $priors=['substantive_dialogue'=>0.05,'concrete_request'=>0.083333,'exact_offer_discussion'=>0.166667,
                'general_purchase_intent'=>0.333333,'agency_purchase_intent'=>0.5];
            if(!in_array($p['status'],['predicted','insufficient_context','known_outcome','access_denied'],true)
                ||($p['stage']!==null&&!array_key_exists($p['stage'],$priors)))return null;
            if($p['status']==='predicted'){
                if($anchor===null||$p['stage']===null||$p['outcome_observations']!==[]||$p['base_probability']!==$priors[$p['stage']]
                    ||!self::probability($p['estimated_probability'])||!self::number($p['hypothesis_odds_multiplier'])
                    ||$p['hypothesis_odds_multiplier']<0.25||$p['hypothesis_odds_multiplier']>1.3)return null;
            }elseif($p['base_probability']!==null||$p['estimated_probability']!==null||$p['hypothesis_odds_multiplier']!==null)return null;
            $allowedReasons=['exact_current_offer_price_alternative','price_alternative_is_continued_search',
                'hypothesis_near_term_urgency','hypothesis_continued_meaningful_engagement','hypothesis_long_range_planning',
                'hypothesis_deferral','hypothesis_explicit_decline','predicted','insufficient_context','known_outcome','access_denied'];
            if($stageOnly||$advertising){
                $allowedReasons=array_values(array_filter($allowedReasons,static fn(string $v):bool=>!str_starts_with($v,'hypothesis_')));
                $allowedReasons[]='explicit_decline_guard';
            }
            if($advertising)foreach(array_keys(ModeledConversionSemanticPrediction::ADVERTISING_WEIGHTS)as$feature)$allowedReasons[]='expert_dialogue_'.$feature;
            foreach(array_keys($priors)as$stage)$allowedReasons[]='illustrative_prior_'.$stage;
            if($relative)foreach(ArchivePredictiveRelativeWeights::FEATURES as$feature)$allowedReasons[]='archive_weight_'.$feature;
            if(!self::codes($p['reasons'],$allowedReasons,16))return null;
            $features=$p['features'];$criteria=['destination','dates','party','budget','requirements'];
            $boolFeatures=['explicit_comparison','explicit_modification','price_alternative_continued_search',
                'near_term_urgency','long_range_planning','deferral','explicit_decline'];
            if($relative||$stageOnly||$advertising)$boolFeatures=[...$boolFeatures,'office_question','payment_terms_question'];
            if(!is_array($features)||!self::codes($features['criteria_families']??null,$criteria,5)
                ||!is_int($features['meaningful_customer_turns']??null)||$features['meaningful_customer_turns']<0
                ||$features['meaningful_customer_turns']>$p['current_customer_turn_count'])return null;
            $featureKeys=['criteria_families','meaningful_customer_turns'];
            if($p['status']!=='access_denied'){
                foreach($boolFeatures as$key)if(!is_bool($features[$key]??null))return null;
                if(!is_int($features['context_barriers']??null)||$features['context_barriers']<0||$features['context_barriers']>1000000)return null;
                $featureKeys=[...$featureKeys,...$boolFeatures,'context_barriers'];
            }
            if(count($features)!==count($featureKeys)||array_diff(array_keys($features),$featureKeys))return null;
            if(($stageOnly||$advertising)&&$p['status']!=='access_denied'){
                if(in_array('explicit_decline_guard',$p['reasons'],true)!==$features['explicit_decline'])return null;
                if($p['status']==='predicted'){
                    $odds=1.0;
                    if($advertising&&!$features['explicit_decline']){
                        $flags=['continued_meaningful_engagement'=>$features['meaningful_customer_turns']>=2,
                            'continued_offer_search'=>$features['explicit_comparison']||$features['explicit_modification']||$features['price_alternative_continued_search'],
                            'office_question'=>$features['office_question'],'payment_terms_question'=>$features['payment_terms_question']];
                        foreach(ModeledConversionSemanticPrediction::ADVERTISING_WEIGHTS as$key=>$multiplier)if($flags[$key])$odds*=$multiplier;
                    }
                    if($features['explicit_decline'])$odds*=0.25;
                    $odds=max(0.25,min(1.3,$odds));$base=$priors[$p['stage']];
                    if(abs((float)$p['hypothesis_odds_multiplier']-$odds)>0.000000001
                        ||abs((float)$p['estimated_probability']-round(($base*$odds)/(1-$base+$base*$odds),6))>0.000000001)return null;
                }
            }
            if($relative&&$p['status']==='predicted'){
                $flags=['continued_meaningful_engagement'=>$features['meaningful_customer_turns']>=2,
                    'near_term_urgency'=>$features['near_term_urgency'],'long_range_planning'=>$features['long_range_planning'],
                    'deferral'=>$features['deferral'],'continued_offer_search'=>$features['explicit_comparison']
                        ||$features['explicit_modification']||$features['price_alternative_continued_search'],
                    'office_question'=>$features['office_question'],'payment_terms_question'=>$features['payment_terms_question']];
                $odds=1.0;foreach($flags as$key=>$present)if($present)$odds*=$p['relative_weights']['multipliers'][$key];
                if($features['explicit_decline'])$odds*=0.25;
                $odds=max(0.25,min(1.3,$odds));$base=$priors[$p['stage']];
                if(abs((float)$p['hypothesis_odds_multiplier']-round($odds,6))>0.000000001
                    ||abs((float)$p['estimated_probability']-round(($base*$odds)/(1-$base+$base*$odds),6))>0.000000001)return null;
            }
            $evidenceKinds=['current_request_anchor','substantive_interest','general_purchase_intent','agency_purchase_intent',
                'explicit_comparison','explicit_modification','price_alternative_continued_search','near_term_urgency',
                'long_range_planning','deferral','explicit_decline','exact_current_offer_discussion'];
            foreach($criteria as$family)$evidenceKinds[]='criteria_'.$family;
            if($relative||$stageOnly||$advertising)$evidenceKinds=[...$evidenceKinds,'office_question','payment_terms_question'];
            if(!self::evidenceList($p['evidence'],'feature',$evidenceKinds,16,$fence,$cutoff)
                ||!self::evidenceList($p['outcome_observations'],'kind',['payment_self_report','manager_payment_ack',
                    'booking_confirmation','purchase_supported_candidate','payment_cancellation','booking_cancellation',
                    'refund_report','purchased_elsewhere','document_issuance','known_operational_outcome'],8,$fence,$cutoff))return null;
            // All fields, strings and nested arrays are fixed above. Unknown raw fields never leave this boundary.
            return array_intersect_key($p,array_fill_keys($fields,true));
        }catch(Throwable $ignored){return null;}
    }


    private static function validFollowup(mixed $f,array $prediction,array $subject):?array
    {
        try{
            if(!is_array($f)||array_is_list($f)||!self::boundedJson($f,16384)
                ||!self::samePredictionSubject($prediction['subject'],$subject))return null;
            $fixed=['version'=>'semantic_purchase_followup_v1','target'=>'conversation_payment_evidence_30d_v1',
                'meaning'=>'diagnostic_conversation_evidence_not_bank_settlement','horizon_seconds'=>2592000,
                'payment_label'=>null,'calibrated_purchase_probability'=>null,'independent_validation'=>false,
                'source_history_complete'=>false,'evaluation_eligible'=>false,
                'evaluation_reason'=>'verified_outcome_and_complete_coverage_unavailable',
                'export_allowed'=>false,'conversion_uploads'=>0,'model_calls'=>0];
            foreach($fixed as$key=>$value)if(!array_key_exists($key,$f)||$f[$key]!==$value)return null;
            $fields=[...array_keys($fixed),'status','reason','observed_at','current_request_anchor',
                'prior_prediction_digest','prior_prediction_at','prior_estimated_probability','horizon_end_at','evidence'];
            if(count($f)!==count($fields)||array_diff(array_keys($f),$fields)
                ||!in_array($f['status'],['waiting','unknown','observed'],true)
                ||!in_array($f['reason'],['no_earlier_prediction','invalid_prior','invalid_current','access_denied',
                    'scope_mismatch','request_anchor_unmatched','edited_request_anchor','contrary_signal_after_prediction',
                    'edited_outcome_source','outcome_not_strictly_after_freeze','outcome_source_not_after_fence',
                    'outcome_not_available_at_cutoff','outcome_outside_horizon','future_conversation_payment_evidence',
                    'non_payment_outcome_only','horizon_elapsed_coverage_unknown','no_eligible_payment_evidence'],true)
                ||$f['observed_at']!==$prediction['frozen_at']
                ||$f['current_request_anchor']!==$prediction['current_request_anchor'])return null;
            $priorAt=self::predictionMoment($f['prior_prediction_at']);$end=self::predictionMoment($f['horizon_end_at']);
            $hasPrior=$f['prior_prediction_digest']!==null;
            if($hasPrior){
                if(!self::sha($f['prior_prediction_digest'])||$priorAt===null||$end===null
                    ||$end-$priorAt!==2592000||$priorAt>self::predictionMoment($prediction['frozen_at'])
                    ||!self::probability($f['prior_estimated_probability']))return null;
            }elseif($f['prior_prediction_at']!==null||$f['prior_estimated_probability']!==null||$f['horizon_end_at']!==null)return null;
            if(!is_array($f['evidence'])||!array_is_list($f['evidence'])||count($f['evidence'])>8)return null;
            if($f['status']==='waiting'&&($f['reason']!=='no_earlier_prediction'||$hasPrior||$f['evidence']!==[]))return null;
            if($f['status']==='unknown'&&$f['evidence']!==[])return null;
            if($f['status']==='observed'&&(!$hasPrior||$prediction['current_request_anchor']===null
                ||$prediction['anchor_source_version_no']!==1||$f['reason']!=='future_conversation_payment_evidence'||$f['evidence']===[]))return null;
            foreach($f['evidence']as$e){
                if(!is_array($e)||count($e)!==5||array_diff(array_keys($e),['label','source_ref','occurred_at','available_at','source_version_no'])
                    ||!in_array($e['label']??null,['self_reported_payment','manager_confirmed_payment'],true)
                    ||!self::predictionRef($e['source_ref']??null,$prediction['source_fence_journal_id'])
                    ||($e['source_version_no']??null)!==1)return null;
                $occurred=self::predictionMoment($e['occurred_at']??null);$available=self::predictionMoment($e['available_at']??null);
                if(!$hasPrior||$occurred===null||$available===null||$occurred<=$priorAt||$available<=$priorAt
                    ||$occurred>$available||$available>= $end||$available>self::predictionMoment($prediction['input_cutoff_at']))return null;
                $kind=$e['label']==='self_reported_payment'?'payment_self_report':'manager_payment_ack';$matched=false;
                foreach($prediction['outcome_observations']as$o)
                    if($o['kind']===$kind&&$o['source_ref']===$e['source_ref']&&$o['occurred_at']===$e['occurred_at']
                        &&$o['available_at']===$e['available_at']&&$o['source_version_no']===1)$matched=true;
                if(!$matched)return null;
            }
            if($f['status']==='observed')foreach($prediction['outcome_observations']as$o)
                if(in_array($o['kind'],['payment_cancellation','booking_cancellation','refund_report','purchased_elsewhere'],true)
                    &&self::predictionMoment($o['occurred_at'])>$priorAt&&self::predictionMoment($o['available_at'])>$priorAt)return null;
            return array_intersect_key($f,array_fill_keys($fields,true));
        }catch(Throwable $ignored){return null;}
    }

    private static function predictionSubject(mixed $subject):?array
    {
        if(!is_array($subject)||($subject['project_key']??null)!=='anytour'
            ||!in_array($subject['platform']??null,['max','telegram'],true)
            ||!self::predictionDecimal($subject['customer_key']??null,false)
            ||!self::predictionDecimal($subject['conversation_id']??null,false))return null;
        return ['project_key'=>'anytour','platform'=>$subject['platform'],'customer_key'=>$subject['customer_key'],
            'conversation_id'=>$subject['conversation_id']];
    }
    private static function samePredictionSubject(mixed $value,array $subject):bool
    {
        $canonical=self::predictionSubject($value);
        return$canonical!==null&&count($value)===4&&$canonical===$subject;
    }
    private static function evidenceList(mixed $list,string $kind,array $allowed,int $max,string $fence,int $cutoff):bool
    {
        if(!is_array($list)||!array_is_list($list)||count($list)>$max)return false;
        foreach($list as$e){
            if(!is_array($e)||count($e)!==5||array_diff(array_keys($e),[$kind,'source_ref','occurred_at','available_at','source_version_no'])
                ||!is_string($e[$kind]??null)||!in_array($e[$kind],$allowed,true)
                ||!self::predictionRef($e['source_ref']??null,$fence)||!self::sourceVersion($e['source_version_no']??null))return false;
            $occurred=self::predictionMoment($e['occurred_at']??null);$available=self::predictionMoment($e['available_at']??null);
            if($occurred===null||$available===null||$occurred>$available||$available>$cutoff)return false;
        }
        return true;
    }
    private static function boundedJson(array $value,int $bytes):bool
    {return strlen(json_encode($value,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR))<=$bytes;}
    private static function codes(mixed $value,array $allowed,int $max):bool
    {
        if(!is_array($value)||!array_is_list($value)||count($value)>$max)return false;
        foreach($value as$item)if(!is_string($item)||!in_array($item,$allowed,true))return false;
        return count(array_unique($value))===count($value);
    }
    private static function sourceVersion(mixed $value):bool{return is_int($value)&&$value>=1&&$value<=1000000;}
    private static function predictionRef(mixed $value,string $fence):bool
    {return is_string($value)&&preg_match('/\Ajournal:[1-9][0-9]{0,19}\z/D',$value)===1&&!self::predictionGreater(substr($value,8),$fence);}
    private static function predictionDecimal(mixed $value,bool $zero=true):bool
    {return is_string($value)&&preg_match($zero?'/\A(?:0|[1-9][0-9]{0,19})\z/D':'/\A[1-9][0-9]{0,19}\z/D',$value)===1;}
    private static function predictionGreater(string $a,string $b):bool
    {return strlen($a)!==strlen($b)?strlen($a)>strlen($b):strcmp($a,$b)>0;}
    private static function predictionMoment(mixed $value):?int
    {
        if(!is_string($value)||preg_match('/\A[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}Z\z/D',$value)!==1)return null;
        $time=strtotime($value);return$time!==false&&gmdate('Y-m-d\TH:i:s\Z',$time)===$value?$time:null;
    }
    private static function sha(mixed $value):bool{return is_string($value)&&preg_match('/\A[a-f0-9]{64}\z/D',$value)===1;}
    private static function number(mixed $value):bool{return(is_int($value)||is_float($value))&&is_finite((float)$value);}
    private static function probability(mixed $value):bool{return self::number($value)&&$value>=0&&$value<=1;}

    private function latestProfile(string $conversation,string $customer,int $now):?array
    {
        // SQL bounds private source bytes before copying; no transcript or native message table read.
        $length=$this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'?'OCTET_LENGTH(profile_json)':'LENGTH(CAST(profile_json AS BLOB))';
        $q=$this->pdo->prepare('SELECT CASE WHEN '.$length.'<=131072 THEN profile_json ELSE NULL END AS profile_json FROM modeled_conversion_profile_versions WHERE project_key=? AND platform=? AND conversation_id=? AND customer_key=? AND observed_at_unix<=? ORDER BY id DESC LIMIT 1');
        $q->execute(['anytour','max',$conversation,$customer,$now]);$json=$q->fetchColumn();
        if(!is_string($json))return null;
        $p=json_decode($json,true,64,JSON_THROW_ON_ERROR);
        return ($p['subject']['project_key']??null)==='anytour'&&($p['subject']['platform']??null)==='max'
            &&(string)($p['subject']['conversation_id']??'')===$conversation
            &&(string)($p['subject']['customer_key']??'')===$customer?$p:null;
    }
}
