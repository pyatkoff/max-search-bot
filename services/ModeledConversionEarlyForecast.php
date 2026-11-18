<?php
declare(strict_types=1);

require_once __DIR__.'/ArchiveResearchSemanticExtractor.php';
require_once __DIR__.'/ManagerAiTextRedactor.php';
require_once __DIR__.'/ModeledConversionOfferReaction.php';
require_once __DIR__.'/ModeledConversionSemanticPrediction.php';
require_once __DIR__.'/ConversationRecordClock.php';

/**
 * Pure projection of the already admitted profile prefix.
 * No source reads, model calls, outcome writes or advertising exports.
 */
final class ModeledConversionEarlyForecast
{
    public const VERSION='live_contextual_ordinal_v1';
    public const TARGET_VERSION='purchase_readiness_30d_ordinal_v1';
    public const HORIZON_SECONDS=2592000;

    public static function freeze(array $case,array $wrapper,array $document,?string $firstCursor=null,?int $frozenAt=null,?array $relativeWeights=null,bool $advertisingV1=false):array
    {
        $subject=$wrapper['subject']??null;
        if(!is_array($subject)||!self::sameSubject($case['subject']??null,$subject)||!is_array($case['rows']??null)||count($case['rows'])>50)self::fail('case');
        $profile=$wrapper['profile']??[];
        $at=$profile['prediction_at']??null;$cutoff=self::moment($at);
        $requested=self::moment($document['requested_at']??null);
        $cursor=$document['journal_cursor']??null;$fence=$document['source_fence_journal_id']??null;
        if($cutoff===null||$requested===null||$requested>$cutoff||!self::decimal($cursor)||!self::decimal($fence)
            ||self::greater($cursor,$fence)||($wrapper['journal_cursor']??null)!==$cursor
            ||($wrapper['source_fence_journal_id']??null)!==$fence)self::fail('cutoff');
        if($firstCursor!==null&&(!self::decimal($firstCursor)||self::greater($firstCursor,$cursor)))self::fail('first_cursor');
        $initial=$firstCursor===null;$firstCursor??=$cursor;
        $frozenAt??=time();if($frozenAt<$cutoff)self::fail('frozen_clock');
        $frozen=gmdate('Y-m-d\\TH:i:s\\Z',$frozenAt);
        $denied=($case['access_denied']??null)!==false||($wrapper['access_status']??null)!=='allowed';
        $predictive=new ModeledConversionSemanticPrediction($subject,$at,$document['requested_at'],$frozen,$cursor,$fence,$relativeWeights,$advertisingV1);
        $admitted=[];$input=$profile['observation_input']??[];
        if(!$denied){
            if(!is_array($input['evidence']??null)||count($input['evidence'])>1000||!self::sameSubject($input['subject']??null,$subject)
                ||($input['prediction_at']??null)!==$at)self::fail('evidence');
            foreach($input['evidence']as$e){
                if(!is_array($e)||!in_array($e['kind']??null,['customer_message','manager_message'],true))continue;
                $refs=$e['references']??null;$r=is_array($refs)&&count($refs)===1?$refs[0]:null;
                if(!is_array($r)||($r['source']??null)!=='message'||!self::journalRef($r['id']??null)
                    ||($e['id']??null)!=='message:'.$r['id']||($e['at']??null)!==($r['at']??null)
                    ||($e['available_at']??null)!==($r['available_at']??null)||!self::sameSubject($e['subject']??null,$subject))self::fail('reference');
                $occurred=self::moment($r['at']);$available=self::moment($r['available_at']);
                if($occurred===null||$available===null||$occurred>$available||$available>$requested
                    ||isset($admitted[$r['id']]))self::fail('reference_clock');
                $admitted[$r['id']]=['role'=>$e['kind']==='customer_message'?'customer':'manager','at'=>$occurred,'available_at'=>$available];
            }
        }
        $rows=[];$seen=[];$clockUnresolved=false;
        foreach($case['rows']as$row){
            if(!is_array($row)||!self::decimal($row['journal_id']??null)||isset($seen[$row['journal_id']])
                ||self::greater($row['journal_id'],$fence))self::fail('row');
            $seen[$row['journal_id']]=true;
            $snap=$row['snapshot']??[];$recorded=$snap['source_created_at_unix']??null;
            $available=$row['observed_at_unix']??null;
            $clock=is_int($recorded)&&is_int($available)&&$recorded>=946684800&&$recorded<=$available&&$available<=$requested
                &&in_array($snap['source_timestamp_basis']??null,['database_session_timezone_to_unix','canonical_recording_unix_v1'],true);
            if(!$clock)$clockUnresolved=true;
            $rows[]=['row'=>$row,'ref'=>'journal:'.$row['journal_id'],'at'=>$clock?$recorded:null];
        }
        // Match the canonical Python chronological tie-break; omitted rows remain barriers.
        usort($rows,static fn(array $a,array $b):int=>($a['at']??0)<=>($b['at']??0)?:strcmp($a['ref'],$b['ref']));
        $wizardApplications=$denied||$clockUnresolved?[]:self::admittedWizardApplications($rows,$wrapper,$admitted,$subject,$requested);
        $preservedEntries=$denied||$clockUnresolved?[]:self::admittedPreservedEntries($rows,$subject,$requested);
        $state=ArchiveResearchSemanticExtractor::initialState();$events=[];$current=[];$boundaries=[];
        $flags=['episode_identity_unverified'=>true,'source_history_incomplete'=>true];
        $offerReaction=new ModeledConversionOfferReaction($case,$wrapper,$requested,$denied||$clockUnresolved);
        if($clockUnresolved)$flags['context_linking_disabled_unknown_clock']=true;
        if(($case['window_limited']??null)!==false)$flags['window_limited']=true;
        $counts=array_fill_keys(ArchiveResearchSemanticExtractor::goalIds(),0);
        $turns=['customer'=>0,'manager'=>0];$replySeconds=[];$previous=null;$barriers=0;$matched=0;$semanticOmissions=[];
        $predictionContinuity=array_fill_keys(['preserved_ai_replies','preserved_handoff_receipts','unresolved_clock_barriers',
            'long_gap_barriers','unavailable_message_barriers','unavailable_event_barriers','other_source_barriers'],0);
        foreach($rows as$item){
            $row=$item['row'];$ref=$item['ref'];$snap=$row['snapshot']??[];$permit=$admitted[$ref]??null;
            if($denied)$permit=null;
            $selection=false;$links=false;$text=null;
            if($permit!==null){
                $payload=$snap['payload']??null;
                if(($row['source_kind']??null)!=='message'||($snap['source_kind']??null)!=='message'
                    ||(string)($snap['source_id']??'')!==(string)($row['source_id']??'')
                    ||($snap['source_status']??null)!=='present'||($snap['payload_status']??null)!=='copied'
                    ||!self::sameSubject($snap,$subject)||!is_array($payload)||!is_string($payload['text']??null)
                    ||preg_match('//u',$payload['text'])!==1||$item['at']!==$permit['at']
                    ||($row['observed_at_unix']??null)!==$permit['available_at'])self::fail('admitted_row');
                $matched++;
                // The canonical adapter bounds Unicode characters; semantic rules bound bytes.
                // A valid large prefix must still commit its factual profile and progress cursor.
                if(strlen($payload['text'])<=ArchiveResearchSemanticExtractor::MAX_TEXT_BYTES){
                    $text=ManagerAiTextRedactor::redact($payload['text'],$selection,$links);
                    $text=preg_replace('/(?<![\\pL\\pN_])@[A-Za-z][A-Za-z0-9_]{3,31}\\b/u','[контакт скрыт]',$text)??$text;
                }
                if($text===null||strlen($text)>ArchiveResearchSemanticExtractor::MAX_TEXT_BYTES){
                    $semanticOmissions['text_too_large']=($semanticOmissions['text_too_large']??0)+1;
                    $flags['semantic_text_too_large']=true;$permit=null;
                }
            }
            $operationalKnown=!$denied&&self::knownOperationalOutcome($row,$subject,$requested);
            if($operationalKnown)
                $predictive->knownOperationalOutcome($ref,(int)$item['at'],(int)$row['observed_at_unix'],(int)$row['source_version_no']);
            if($permit===null||$clockUnresolved){
                $longGap=ArchiveResearchSemanticExtractor::hasLongObservationGap($state['last'],$item['at']);
                $serviceKind=null;$observationalBridge=false;$subscriptionBridge=false;$applicationBridge=false;$questionBridge=false;$navigationBridge=false;$entryBridge=false;
                if(!$denied&&!$clockUnresolved){
                    if(self::knownServiceReply($row,$subject,$requested)){
                        $serviceKind='preserved_ai_replies';
                        // A new exact question can begin a clean context after a
                        // gap; it cannot carry the previous trip's criteria.
                        if($longGap&&ModeledConversionQuestionnaireContext::field($snap['payload']['text'])!==null)
                            $predictive->barrier();
                        $questionBridge=$predictive->observeServiceQuestion($snap['payload']['text'],$ref,
                            (int)$item['at'],(int)$row['observed_at_unix'],(int)$row['source_version_no']);
                    }elseif(!$longGap&&self::knownServiceEvent($row,$subject,$requested))$serviceKind='preserved_handoff_receipts';
                    if(!$longGap){
                        $navigationBridge=self::knownTourNavigation($row,$subject,$requested)
                            &&$predictive->preserveNavigationContext((int)$item['at']);
                        $observationalBridge=self::knownObservationalEvent($row,$subject,$requested)
                            &&$predictive->canPreserveCustomerContext((int)$item['at']);
                        $subscriptionBridge=self::knownSubscriptionObservation($row,$wrapper,$subject,$requested)
                            &&$predictive->canPreserveSubscriptionContext((int)$item['at']);
                        $entryBridge=isset($preservedEntries[$ref])&&$predictive->canPreserveEntryContext((int)$item['at']);
                        $inputRef=$wizardApplications[$ref]??null;
                        $applicationBridge=$inputRef!==null
                            &&$predictive->canPreserveCustomerContext((int)$item['at'],$inputRef);
                    }
                }
                $serviceBridge=$serviceKind!==null&&($questionBridge
                    ||(!$longGap&&$predictive->canPreserveCustomerContext((int)$item['at'])));
                if($clockUnresolved||(!$operationalKnown&&!$serviceBridge&&!$observationalBridge&&!$subscriptionBridge&&!$applicationBridge&&!$navigationBridge&&!$entryBridge&&!$offerReaction->bridgesUnavailable($ref,$longGap))){
                    $predictive->barrier();
                    $reason=$clockUnresolved?'unresolved_clock_barriers':($longGap?'long_gap_barriers':
                        match($row['source_kind']??null){'message'=>'unavailable_message_barriers','event'=>'unavailable_event_barriers',default=>'other_source_barriers'});
                    $predictionContinuity[$reason]++;
                }elseif($serviceBridge)$predictionContinuity[$serviceKind]++;
                $barrier=ArchiveResearchSemanticExtractor::step(['read_status'=>'source_unavailable','source_ref'=>$ref,'created_at'=>$item['at']],$state);
                $state=$barrier['state'];$previous=null;$barriers++;
                if($longGap)$flags['long_observation_gap']=true;
            }
            if($permit===null){$offerReaction->unavailable($ref,$longGap);continue;}
            $role=$permit['role'];$turns[$role]++;
            if($role==='customer'&&$previous!==null&&$previous['role']==='manager'&&$permit['at']>=$previous['at'])
                $replySeconds[]=$permit['at']-$previous['at'];
            $previous=['role'=>$role,'at'=>$permit['at']];
            $result=ArchiveResearchSemanticExtractor::step([
                'source_ref'=>$ref,'created_at'=>$permit['at'],'read_status'=>'available','text'=>$text,
                'role'=>$role,'direction'=>$role==='customer'?'inbound':'outbound','role_verified'=>true,
                'text_minimized'=>true,'forwarded'=>false,'media'=>[],'media_contents_read'=>false,
                'selection_links_unread'=>$selection,'links_redacted'=>$links,
            ],$state);
            $state=$result['state'];
            $offerReaction->message($ref,$role,$permit['at'],$permit['available_at'],$text,$result);
            $originalUnlinked=$advertisingV1&&($row['source_version_no']??null)===1
                &&self::plainPackageMessage($snap['payload']['metadata_json']??null);
            $departureAlternatives=$predictive->observeMessage($role,$text,$ref,$permit['at'],$permit['available_at'],
                (int)($row['source_version_no']??0),$result,$originalUnlinked);
            foreach($result['flags']as$key=>$value)if($value===true)$flags[$key]=true;
            if($result['episode_boundary']!==null){
                $boundaries[]=['source_ref'=>$ref,'kind'=>$result['episode_boundary'],'episode'=>$state['e'],'identity'=>'unverified'];
                $current=[];
            }
            if(($result['flags']['unresolved_trip_boundary']??false)===true
                ||($result['flags']['party_change_unresolved']??false)===true)$current=[];
            foreach($result['events']as$event){
                // A verified departure list is not a destination condition in either saved projection.
                if($departureAlternatives&&($event['source_ref']??null)===$ref
                    &&($event['goal']??null)==='substantive_interest'&&($event['rule']??null)==='customer_trip_conditions')continue;
                if(!isset($counts[$event['goal']??'']))self::fail('goal');
                $events[]=$event;$current[]=$event;$counts[$event['goal']]++;
            }
        }
        if(!$denied&&$matched!==count($admitted))self::fail('missing_admitted_row');
        $currentCounts=array_fill_keys(ArchiveResearchSemanticExtractor::goalIds(),0);
        foreach($current as$event)$currentCounts[$event['goal']]++;
        $knownOutcome=false;
        foreach(['payment_self_report','manager_payment_ack','booking_confirmation','purchase_supported_candidate',
            'payment_cancellation','booking_cancellation','refund_report','purchased_elsewhere']as$goal)
            if($counts[$goal]>0)$knownOutcome=true;
        $contrary=[];foreach(['deferral','payment_cancellation','booking_cancellation','refund_report','purchased_elsewhere']as$goal)
            if($currentCounts[$goal]>0)$contrary[]=$goal;
        $status=$denied?'access_denied':($knownOutcome?'target_already_observed':($turns['customer']===0?'no_usable_customer_context':'early_assessment'));
        $any=$currentCounts['general_purchase_intent']>0?'intent':($currentCounts['substantive_interest']>0?'interest':'unknown');
        $agency=$currentCounts['agency_purchase_intent']>0?'intent':(($currentCounts['office_question']+$currentCounts['payment_terms_question']+$currentCounts['documents_for_booking'])>0?'logistics':'unknown');
        if($currentCounts['deferral']>0){$any='deferred';$agency='deferred';}
        if($status!=='early_assessment'){$any='not_assessed';$agency='not_assessed';}
        sort($replySeconds,SORT_NUMERIC);$n=count($replySeconds);
        $median=$n===0?null:($n%2?$replySeconds[intdiv($n,2)]:($replySeconds[intdiv($n,2)-1]+$replySeconds[intdiv($n,2)])/2);
        $coverage=['trip_status'=>'unknown','episode_identity'=>'unverified','source_history_complete'=>false,
            'window_limited'=>($case['window_limited']??null)!==false,'media_contents_read'=>false,
            'context_barriers'=>$barriers,'context_linking_disabled'=>$clockUnresolved,'semantic_omissions'=>$semanticOmissions,
            'source_revision_chronology_unresolved'=>isset($wrapper['coverage']['errors']['source_revision_chronology_unresolved']),
            'prediction_source_continuity'=>$predictionContinuity];
        // This early semantic target is independent of the 30-day purchase hypothesis.
        // Only the canonical adapter's joined, already available immutable offer
        // receipts can establish a preference; sending alone never upgrades it.
        $journey=$wrapper['offer_journey']??null;$preferences=[];
        if(!$denied&&is_array($journey)&&($journey['version']??null)==='admitted_offer_journey_v1'
            &&is_array($journey['offers']??null)&&count($journey['offers'])<=50){
            foreach($journey['offers']as$offer){
                if(!is_array($offer)||($offer['state']??null)!=='liked'
                    ||($offer['customer_response']??null)!=='explicit_offer_preference'
                    ||($offer['snapshot']['is_demo']??null)!==false
                    ||!is_array($offer['delivery']??null)||!is_array($offer['reaction']??null))continue;
                $available=self::moment($offer['available_at']??null);
                if($available===null||$available>$requested)continue;
                $refs=[];
                foreach(['prepared','delivery','reaction']as$key){
                    $r=$offer[$key]??null;
                    if(!is_array($r)||!self::decimal($r['journal_id']??null)||!isset($seen[$r['journal_id']])){ $refs=[];break; }
                    $refs[]='journal:'.$r['journal_id'];
                }
                if(count($refs)===3)$preferences[]=['source_refs'=>$refs,'available_at'=>$offer['available_at']];
            }
        }
        $level='unknown';
        $offerEvidence=$offerReaction->project();
        $currentDiscussion=false;
        foreach($offerEvidence['specific_option_discussions']as$discussion)
            if($discussion['current_candidate'])$currentDiscussion=true;
        if(!$denied&&$status==='early_assessment'&&$contrary===[]){
            if($currentCounts['substantive_interest']>0)$level='substantive_request';
            if($currentDiscussion)$level='specific_options_discussion';
            // Exact text discussion remains a provisional segment candidate, not
            // verified trip identity. A preference alone never upgrades the state.
            if($currentCounts['agency_purchase_intent']>0)$level='agency_booking_intent';
        }
        $quality=['version'=>'request_offer_reaction_v2','state'=>$level,
            'request_offer_links'=>$offerEvidence['request_offer_links'],
            'specific_option_discussions'=>$offerEvidence['specific_option_discussions'],
            'linking_omissions'=>$offerEvidence['linking_omissions'],
            'specific_offer_preferences'=>$preferences,
            'discussion_status'=>$currentDiscussion?'named_offer_price_discussion_candidate_episode_unverified':
                ($preferences?'offer_preference_observed_episode_unverified':'unknown'),
            'trip_status'=>'unknown','evaluation_eligible'=>false,'export_allowed'=>false,
            'meaning'=>'early_rule_candidate_not_purchase_or_payment'];
        $packet=[
            'contextual_signals'=>['version'=>self::VERSION,'extractor_version'=>ArchiveResearchSemanticExtractor::VERSION,
                'extractor_sha256'=>hash_file('sha256',__DIR__.'/ArchiveResearchSemanticExtractor.php'),
                'adapter_sha256'=>hash_file('sha256',__FILE__),'prediction_at'=>$at,
                'input_cutoff_at'=>$document['requested_at'],'frozen_at'=>$frozen,'derived_available_at'=>$frozen,
                'source_fence_journal_id'=>$fence,'events'=>$events,'goal_counts'=>$counts,
                'current_episode_goal_counts'=>$currentCounts,'episode_boundaries'=>$boundaries,'flags'=>$flags,
                'engagement'=>['customer_turns'=>$turns['customer'],'manager_turns'=>$turns['manager'],
                    'observed_customer_replies'=>$n,'median_reply_seconds'=>$median,'meaning'=>'observed_role_switch_intervals_not_causal_interest'],
                'coverage'=>$coverage,'semantics'=>'rule_candidates_not_confirmed_outcomes'],
            'early_forecast'=>['version'=>self::VERSION,'target_version'=>self::TARGET_VERSION,
                'mode'=>'uncalibrated_ordinal','status'=>$status,
                'snapshot_kind'=>$initial?'initial_observation':'updated_observation',
                'first_snapshot_journal_cursor'=>$firstCursor,'journal_cursor'=>$cursor,
                'source_fence_journal_id'=>$fence,'prediction_at'=>$at,'input_cutoff_at'=>$document['requested_at'],'frozen_at'=>$frozen,
                'horizon_seconds'=>self::HORIZON_SECONDS,'horizon_end_at'=>gmdate('Y-m-d\\TH:i:s\\Z',$cutoff+self::HORIZON_SECONDS),
                'horizon_semantics'=>'prospective_30d_purchase_intention_hypothesis_not_validated_purchase_probability',
                'readiness_basis'=>'observed_signals_in_current_candidate_prefix_not_current_willingness',
                'current_readiness_status'=>$contrary?'unknown_after_contrary_signal':'unverified',
                'early_quality'=>$quality,
                'contrary_goal_ids'=>$contrary,'known_outcome_exclusion_scope'=>'any_available_prefix_episode_conservative',
                'purchase_anywhere'=>['readiness_band'=>$any,'probability'=>null],
                'purchase_with_us'=>['readiness_band'=>$agency,'probability'=>null],
                'expected_value_rub'=>null,'calibration_status'=>'not_calibrated','coverage'=>$coverage,
                'evaluation_eligible'=>false,'evaluation_reason'=>'trip_and_verified_outcome_contract_unresolved',
                'export_allowed'=>false,'conversion_uploads'=>0,'model_calls'=>0],
        ];
        $packet['semantic_prediction']=$predictive->finish($packet);
        return $packet;
    }

    public static function emptyReceipt():array
    {
        return ['version'=>self::VERSION,'snapshots'=>0,'initial_observations'=>0,'updated_observations'=>0,
            'early_assessments'=>0,'target_already_observed'=>0,'no_usable_customer_context'=>0,'access_denied'=>0,
            'goal_counts'=>array_fill_keys(ArchiveResearchSemanticExtractor::goalIds(),0)];
    }
    public static function accumulate(array $receipt,array $packet):array
    {
        $f=$packet['early_forecast'];$receipt['snapshots']++;
        $receipt[$f['snapshot_kind']==='initial_observation'?'initial_observations':'updated_observations']++;
        $key=$f['status']==='early_assessment'?'early_assessments':$f['status'];$receipt[$key]++;
        foreach($packet['contextual_signals']['goal_counts']as$goal=>$count)$receipt['goal_counts'][$goal]+=$count;
        return $receipt;
    }

    /** Recheck immutable bindings already selected by the canonical Python wizard admission. */
    private static function admittedWizardApplications(array $rows,array $wrapper,array $admitted,array $subject,int $cutoff):array
    {
        $packet=$wrapper['wizard_applications']??null;
        if(!is_array($packet)||count($packet)!==2||($packet['version']??null)!=='admitted_wizard_applications_v1'
            ||!is_array($packet['receipts']??null)||!array_is_list($packet['receipts'])||count($packet['receipts'])>50)return [];
        $byRef=[];foreach($rows as$item)$byRef[$item['ref']]=$item['row'];
        $bindings=[];$seen=[];
        foreach($packet['receipts']as$binding){
            if(!is_array($binding)||count($binding)!==2||!self::journalRef($binding['receipt_ref']??null)
                ||!self::journalRef($binding['input_ref']??null)||isset($seen[$binding['receipt_ref']]))return [];
            $ref=$binding['receipt_ref'];$inputRef=$binding['input_ref'];$seen[$ref]=true;
            $receipt=$byRef[$ref]??null;$input=$byRef[$inputRef]??null;$permit=$admitted[$inputRef]??null;
            if(!is_array($receipt)||!is_array($input)||!is_array($permit)||($permit['role']??null)!=='customer')continue;
            $decoded=[];$valid=true;
            foreach([[$receipt,'event','payload_json'],[$input,'message','metadata_json']]as[$row,$kind,$jsonKey]){
                $s=$row['snapshot']??null;
                if(($row['source_kind']??null)!==$kind||($row['source_version_no']??null)!==1||!is_array($s)
                    ||($s['source_kind']??null)!==$kind||($s['source_status']??null)!=='present'
                    ||($s['payload_status']??null)!=='copied'||!self::sameSubject($s,$subject)
                    ||(string)($s['source_id']??'')!==(string)($row['source_id']??'')
                    ||($s['source_timestamp_basis']??null)!=='canonical_recording_unix_v1'){$valid=false;break;}
                $payload=$s['payload']??null;$raw=is_array($payload)?($payload[$jsonKey]??null):null;
                if(!is_string($raw)||strlen($raw)>32768){$valid=false;break;}
                try{$p=json_decode($raw,true,32,JSON_THROW_ON_ERROR);$at=ConversationRecordClock::read($raw);}
                catch(Throwable $ignored){$valid=false;break;}
                $available=$row['observed_at_unix']??null;
                if(!is_array($p)||array_is_list($p)||!is_int($at)||!is_int($available)
                    ||$at<946684800||$at>$available||$available>$cutoff
                    ||($s['source_created_at_unix']??null)!==$at){$valid=false;break;}
                $decoded[$kind]=$p;
            }
            if(!$valid)continue;
            $event=$receipt['snapshot']['payload'];$message=$input['snapshot']['payload'];$p=$decoded['event'];
            unset($p['_recorded_clock']);ksort($p);
            if(($event['event_type']??null)!=='wizard_value_applied'||($event['actor_type']??null)!=='system'
                ||array_keys($p)!==['field','input_kind','input_message_id','input_text_sha256','origin','storage_value','version']
                ||$p['version']!==1||$p['origin']!=='existing_wizard_step_application'||$p['input_kind']!=='message'
                ||!is_string($p['input_message_id'])||$p['input_message_id']!==(string)$input['source_id']
                ||!in_array($p['field'],['city','country','adults','children','child_ages','stars','meal','nights','date'],true)
                ||!is_string($p['storage_value'])||$p['storage_value']===''||strlen($p['storage_value'])>160
                ||($message['direction']??null)!=='inbound'||($message['sender_type']??null)!=='customer'
                ||!is_string($message['text']??null)||strlen($message['text'])>ArchiveResearchSemanticExtractor::MAX_TEXT_BYTES
                ||preg_match('//u',$message['text'])!==1||!is_string($p['input_text_sha256'])
                ||!hash_equals(hash('sha256',$message['text']),$p['input_text_sha256']))continue;
            $meta=$decoded['message'];
            if(($meta['type']??null)!=='message'||(isset($meta['linked_message'])&&(!is_array($meta['linked_message'])
                ||($meta['linked_message']['type']??null)!=='reply')))continue;
            $inputAt=$input['snapshot']['source_created_at_unix'];$inputAvailable=$input['observed_at_unix'];
            if($inputAt!==$permit['at']||$inputAvailable!==$permit['available_at']
                ||$inputAt>$receipt['snapshot']['source_created_at_unix']||$inputAvailable>$receipt['observed_at_unix'])continue;
            $bindings[$ref]=$inputRef;
        }
        return $bindings;
    }

    /** Only positive receipts from the actual no-reset MAX entry branch preserve customer meaning. */
    private static function admittedPreservedEntries(array $rows,array $subject,int $cutoff):array
    {
        if($subject['platform']!=='max')return [];
        $counts=[];$entries=[];$allowed=[];
        foreach($rows as$item){
            $row=$item['row'];$id=$row['source_id']??null;
            if(($row['source_kind']??null)==='event'&&is_string($id))$counts[$id]=($counts[$id]??0)+1;
        }
        foreach($rows as$item){
            $row=$item['row'];$s=$row['snapshot']??null;$id=$row['source_id']??null;
            if(($row['source_kind']??null)!=='event'||($row['source_version_no']??null)!==1
                ||!is_string($id)||$id==='0'||!self::decimal($id)||($counts[$id]??0)!==1
                ||!is_array($s)||($s['source_kind']??null)!=='event'||($s['source_id']??null)!==$id
                ||($s['source_status']??null)!=='present'||($s['payload_status']??null)!=='copied'
                ||!self::sameSubject($s,$subject)||($s['source_timestamp_basis']??null)!=='canonical_recording_unix_v1')continue;
            $payload=$s['payload']??null;$raw=is_array($payload)?($payload['payload_json']??null):null;
            $type=is_array($payload)?($payload['event_type']??null):null;
            if(!in_array($type,['bot_started','channel_post_entry'],true)||!is_string($raw)||strlen($raw)>32768
                ||($payload['actor_type']??null)!==($type==='bot_started'?'system':'customer'))continue;
            try{
                $p=json_decode($raw,true,32,JSON_THROW_ON_ERROR);$at=ConversationRecordClock::read($raw);
                if(!is_array($p)||array_is_list($p)
                    ||json_encode($p,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)!==$raw)continue;
                unset($p['_recorded_clock']);ksort($p);
                $keys=$type==='bot_started'?['context_preserved','source_key']:
                    ['context_preserved','entry_id','publication_id','source_key','version_id'];
                if(array_keys($p)!==$keys||!is_string($p['source_key'])
                    ||preg_match('/\A[A-Za-z0-9_.:\/-]{1,191}\z/D',$p['source_key'])!==1)continue;
                $proof=$p['context_preserved'];
                if(!is_array($proof)||array_is_list($proof))continue;
                ksort($proof);$proofKeys=$type==='bot_started'?['conversation_id','origin','reason','version']:
                    ['conversation_id','origin','reason','start_event_id','version'];
                if(array_keys($proof)!==$proofKeys||$proof['version']!==1
                    ||$proof['origin']!=='max_start_source_policy'||$proof['reason']!=='existing_handoff_without_reset'
                    ||$proof['conversation_id']!==$subject['conversation_id'])continue;
                if($type==='channel_post_entry'){
                    foreach(['entry_id','publication_id','version_id']as$key)if(!is_int($p[$key])||$p[$key]<=0)continue 2;
                    if(!is_string($proof['start_event_id'])||$proof['start_event_id']==='0'
                        ||!self::decimal($proof['start_event_id']))continue;
                }
            }catch(Throwable $ignored){continue;}
            $available=$row['observed_at_unix']??null;
            if(!is_int($at)||!is_int($available)||$at<946684800||$at>$available||$available>$cutoff
                ||($s['source_created_at_unix']??null)!==$at)continue;
            $entries[$id]=['type'=>$type,'ref'=>$item['ref'],'at'=>$at,'available'=>$available,
                'source_key'=>$p['source_key'],'start_event_id'=>$proof['start_event_id']??null];
            if($type==='bot_started')$allowed[$item['ref']]=true;
        }
        foreach($entries as$id=>$entry){
            if($entry['type']!=='channel_post_entry')continue;
            $start=$entries[$entry['start_event_id']]??null;
            if(!is_array($start)||$start['type']!=='bot_started'||$start['source_key']!==$entry['source_key']
                ||!self::greater((string)$id,$entry['start_event_id'])
                ||$start['at']>$entry['at']||$start['available']>$entry['available'])continue;
            $allowed[$entry['ref']]=true;
        }
        return $allowed;
    }

    /** Exact recorded search navigation is not a new trip or customer statement. */
    private static function knownTourNavigation(array $row,array $subject,int $cutoff):bool
    {
        $s=$row['snapshot']??null;
        if(($row['source_kind']??null)!=='message'||($row['source_version_no']??null)!==1||!is_array($s)
            ||($s['source_kind']??null)!=='message'||($s['source_status']??null)!=='present'
            ||($s['payload_status']??null)!=='copied'||!self::sameSubject($s,$subject)
            ||(string)($s['source_id']??'')!==(string)($row['source_id']??'')
            ||($s['source_timestamp_basis']??null)!=='canonical_recording_unix_v1')return false;
        $p=$s['payload']??null;$raw=is_array($p)?($p['metadata_json']??null):null;
        if(!is_array($p)||($p['direction']??null)!=='inbound'||($p['sender_type']??null)!=='customer'
            ||!is_string($p['text']??null)||preg_match('/\\A(?:g1_[a-f0-9]{8}_)?show_tours\\z/D',$p['text'])!==1
            ||!is_string($raw)||strlen($raw)>32768)return false;
        try{
            $meta=json_decode($raw,true,32,JSON_THROW_ON_ERROR);
            if(!is_array($meta)||($meta['type']??null)!=='callback'||isset($meta['linked_message']))return false;
            $at=ConversationRecordClock::read($raw);
        }catch(Throwable $ignored){return false;}
        $available=$row['observed_at_unix']??null;
        return is_int($at)&&is_int($available)&&$at>=946684800&&$at<=$available&&$available<=$cutoff
            &&($s['source_created_at_unix']??null)===$at;
    }

    /** A linked reply can concern an earlier object, even when both human messages are admitted. */
    private static function plainPackageMessage(mixed $raw):bool
    {
        if(!is_string($raw)||strlen($raw)>32768)return false;
        $metadata=json_decode($raw,true,32);
        if(!is_array($metadata)||($metadata!==[]&&array_is_list($metadata)))return false;
        $link=$metadata['linked_message']??null;
        return ($link===null||$link===[])&&empty($metadata['forwarded'])&&empty($metadata['forward_origin']);
    }

    /** Canonical AI output is not a customer omission and never supplies customer evidence. */
    private static function knownServiceReply(array $row,array $subject,int $cutoff):bool
    {
        $s=$row['snapshot']??null;
        if(($row['source_kind']??null)!=='message'||($row['source_version_no']??null)!==1||!is_array($s)
            ||($s['source_kind']??null)!=='message'||($s['source_status']??null)!=='present'
            ||($s['payload_status']??null)!=='copied'||!self::sameSubject($s,$subject)
            ||(string)($s['source_id']??'')!==(string)($row['source_id']??'')
            ||($s['source_timestamp_basis']??null)!=='canonical_recording_unix_v1')return false;
        $p=$s['payload']??null;$raw=is_array($p)?($p['metadata_json']??null):null;
        if(!is_array($p)||($p['direction']??null)!=='outbound'||($p['sender_type']??null)!=='ai'
            ||!is_string($p['text']??null)||strlen($p['text'])>ArchiveResearchSemanticExtractor::MAX_TEXT_BYTES
            ||preg_match('//u',$p['text'])!==1||!is_string($raw)||strlen($raw)>32768)return false;
        try{
            $meta=json_decode($raw,true,32,JSON_THROW_ON_ERROR);
            if(!is_array($meta)||!in_array($meta['type']??null,[null,'message'],true))return false;
            if(isset($meta['linked_message'])&&(!is_array($meta['linked_message'])
                ||($meta['linked_message']['type']??null)!=='reply'))return false;
            $at=ConversationRecordClock::read($raw);
        }catch(Throwable $ignored){return false;}
        $available=$row['observed_at_unix']??null;
        return is_int($at)&&is_int($available)&&$at>=946684800&&$at<=$available&&$available<=$cutoff
            &&($s['source_created_at_unix']??null)===$at;
    }

    /** Typed canonical delivery/handoff receipts contain no new customer meaning. */
    private static function knownServiceEvent(array $row,array $subject,int $cutoff):bool
    {
        $s=$row['snapshot']??null;
        if(($row['source_kind']??null)!=='event'||($row['source_version_no']??null)!==1||!is_array($s)
            ||($s['source_kind']??null)!=='event'||($s['source_status']??null)!=='present'
            ||($s['payload_status']??null)!=='copied'||!self::sameSubject($s,$subject)
            ||(string)($s['source_id']??'')!==(string)($row['source_id']??'')
            ||($s['source_timestamp_basis']??null)!=='canonical_recording_unix_v1')return false;
        $payload=$s['payload']??null;$raw=is_array($payload)?($payload['payload_json']??null):null;
        $type=$payload['event_type']??null;$actor=$payload['actor_type']??null;
        $actors=['manager_message'=>['manager'],'manager_taken'=>['manager'],
            'manager_request'=>['ai','system'],'manager_request_deferred'=>['ai'],'waiting_manager'=>['customer'],
            'source_handling_choice'=>['system']];
        if(!is_string($type)||!isset($actors[$type])||!in_array($actor,$actors[$type],true)||!is_string($raw)||strlen($raw)>32768)return false;
        try{
            $p=json_decode($raw,true,32,JSON_THROW_ON_ERROR);$at=ConversationRecordClock::read($raw);
            if(!is_array($p)||array_is_list($p))return false;
            unset($p['_recorded_clock']);ksort($p);$keys=array_keys($p);
            if($type==='manager_message'){
                if($keys!==['channel','project_key']||$p['channel']!==$subject['platform']||$p['project_key']!=='anytour')return false;
            }elseif($type==='manager_taken'){
                if($p!==[])return false;
            }elseif($type==='source_handling_choice'){
                if($keys!==['choice','reason']||$p['choice']!=='manager'||$p['reason']!=='source_needs_complete')return false;
            }else{
                $withSummary=$keys===['from_tours','manager_available','summary','within_working_hours'];
                $withSource=$keys===['manager_available','source','within_working_hours'];
                if((!$withSummary&&!$withSource)||!is_bool($p['manager_available'])||!is_bool($p['within_working_hours']))return false;
                if($withSummary&&(!is_bool($p['from_tours'])||!is_string($p['summary'])||strlen($p['summary'])>4096||preg_match('//u',$p['summary'])!==1))return false;
                if($withSource&&(!is_string($p['source'])||strlen($p['source'])>64||preg_match('//u',$p['source'])!==1))return false;
            }
        }catch(Throwable $ignored){return false;}
        $available=$row['observed_at_unix']??null;
        return is_int($at)&&is_int($available)&&$at>=946684800&&$at<=$available&&$available<=$cutoff
            &&($s['source_created_at_unix']??null)===$at;
    }

    /** Only the selected, canonically admitted MAX subscription may preserve an existing request. */
    private static function knownSubscriptionObservation(array $row,array $wrapper,array $subject,int $cutoff):bool
    {
        $s=$row['snapshot']??null;
        if($subject['platform']!=='max'||($row['source_kind']??null)!=='event'
            ||($row['source_version_no']??null)!==1||!is_array($s)
            ||($s['source_kind']??null)!=='event'||($s['source_status']??null)!=='present'
            ||($s['payload_status']??null)!=='copied'||!self::sameSubject($s,$subject)
            ||!is_string($row['source_id']??null)||$row['source_id']==='0'||!self::decimal($row['source_id'])
            ||(string)($s['source_id']??'')!==$row['source_id']
            ||($s['source_timestamp_basis']??null)!=='canonical_recording_unix_v1')return false;
        $payload=$s['payload']??null;$raw=is_array($payload)?($payload['payload_json']??null):null;
        if(!is_array($payload)||($payload['event_type']??null)!=='channel_subscription_observed'
            ||($payload['actor_type']??null)!=='system'||!is_string($raw)||strlen($raw)>32768)return false;
        try{
            $p=json_decode($raw,true,32,JSON_THROW_ON_ERROR);$at=ConversationRecordClock::read($raw);
            if(!is_array($p)||array_is_list($p)
                ||json_encode($p,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)!==$raw)return false;
            unset($p['_recorded_clock']);ksort($p);
            if(array_keys($p)!==['identity_scope','occurred_at_unix','origin','source','state','version']
                ||$p['version']!==1||$p['origin']!=='existing_membership_producer'
                ||$p['source']!=='max_membership_history'||$p['identity_scope']!=='same_chat_and_source'
                ||$p['state']!=='subscribed'||!is_int($p['occurred_at_unix'])
                ||$p['occurred_at_unix']<946684800)return false;
        }catch(Throwable $ignored){return false;}
        $available=$row['observed_at_unix']??null;
        if(!is_int($at)||!is_int($available)||$p['occurred_at_unix']>$at||$at>$available
            ||$available>$cutoff||($s['source_created_at_unix']??null)!==$at)return false;
        $profile=$wrapper['profile']??null;$signals=is_array($profile)?($profile['early_signals']??null):null;
        $receipt=is_array($signals)?($signals['subscription']??null):null;
        return is_array($profile)&&self::sameSubject($profile['subject']??null,$subject)
            &&is_array($signals)&&($signals['version']??null)==='membership_early_signals_v1'
            &&($signals['prediction_at']??null)===($profile['prediction_at']??null)
            &&is_array($receipt)&&count($receipt)===7
            &&($receipt['status']??null)==='known'&&($receipt['state']??null)==='subscribed'
            &&($receipt['source']??null)==='max_membership_history'
            &&($receipt['receipt_id']??null)==='journal:'.$row['journal_id']
            &&self::moment($receipt['at']??null)===$p['occurred_at_unix']
            &&self::moment($receipt['recorded_at']??null)===$at
            &&self::moment($receipt['available_at']??null)===$available;
    }

    /** Exact bookkeeping receipts from MetrikaConversionGoalService::emitOnce contain no customer meaning. */
    private static function knownObservationalEvent(array $row,array $subject,int $cutoff):bool
    {
        $s=$row['snapshot']??null;
        if(($row['source_kind']??null)!=='event'||($row['source_version_no']??null)!==1||!is_array($s)
            ||($s['source_kind']??null)!=='event'||($s['source_status']??null)!=='present'
            ||($s['payload_status']??null)!=='copied'||!self::sameSubject($s,$subject)
            ||(string)($s['source_id']??'')!==(string)($row['source_id']??'')
            ||($s['source_clock']??null)!=='database_persistence_not_provider_occurrence'
            ||($s['source_timestamp_basis']??null)!=='database_session_timezone_to_unix')return false;
        $p=$s['payload']??null;$type=is_array($p)?($p['event_type']??null):null;
        $target=is_string($type)?(ModeledConversionSemanticPrediction::OBSERVATIONAL_EVENT_TARGETS[$type]??null):null;
        if($target===null||($p['actor_type']??null)!=='system'
            ||($p['payload_json']??null)!==json_encode(['target'=>$target],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES))return false;
        // Retain the stream's normalized recording clock; do not reinterpret native DATETIME as UTC or occurrence time.
        $at=$s['source_created_at_unix']??null;$available=$row['observed_at_unix']??null;
        return is_int($at)&&is_int($available)&&$at>=946684800&&$at<=$available&&$available<=$cutoff;
    }

    /** A known closed operational assertion is an exclusion, never payment proof. */
    private static function knownOperationalOutcome(array $row,array $subject,int $cutoff):bool
    {
        $s=$row['snapshot']??null;
        if(($row['source_kind']??null)!=='event'||($row['source_version_no']??null)!==1||!is_array($s)
            ||($s['source_kind']??null)!=='event'||($s['source_status']??null)!=='present'
            ||($s['payload_status']??null)!=='copied'||!self::sameSubject($s,$subject)
            ||(string)($s['source_id']??'')!==(string)($row['source_id']??'')
            ||($s['source_timestamp_basis']??null)!=='canonical_recording_unix_v1')return false;
        $payload=$s['payload']??null;$raw=is_array($payload)?($payload['payload_json']??null):null;
        if(($payload['event_type']??null)!=='sales_pipeline_outcome_observed'||!is_string($raw)||strlen($raw)>32768)return false;
        try{$p=json_decode($raw,true,32,JSON_THROW_ON_ERROR);}catch(Throwable $ignored){return false;}
        if(!is_array($p)||($p['version']??null)!==1||($p['origin']??null)!=='existing_sales_pipeline'
            ||($p['producer']??null)!=='sales_pipeline_manager_assertion'
            ||($p['evidence_basis']??null)!=='manager_operational_assertion'
            ||!self::sameSubject($p['scope']??null,$subject)||!in_array($p['outcome']??null,['won','lost'],true)
            ||!in_array($p['actor_role']??null,['manager','system'],true)
            ||($payload['actor_type']??null)!==$p['actor_role']
            ||!is_string($p['operation_id']??null)||preg_match('/\\A[a-f0-9]{32}\\z/D',$p['operation_id'])!==1)return false;
        foreach(['inquiry_binding','profile_id','episode_key','previous_outcome']as$key)
            if(!array_key_exists($key,$p)||$p[$key]!==null)return false;
        $at=ConversationRecordClock::read($raw);$available=$row['observed_at_unix']??null;
        return is_int($at)&&is_int($available)&&$at>=946684800&&$at<=$available&&$available<=$cutoff
            &&($s['source_created_at_unix']??null)===$at;
    }
    private static function sameSubject(mixed $value,array $subject):bool
    {
        if(!is_array($value))return false;
        foreach(['project_key','platform','customer_key','conversation_id']as$key)
            if(!array_key_exists($key,$value)||!array_key_exists($key,$subject)||(string)$value[$key]!==$subject[$key])return false;
        return true;
    }
    private static function decimal(mixed $value):bool{return is_string($value)&&preg_match('/\\A(?:0|[1-9][0-9]{0,19})\\z/D',$value)===1;}
    private static function journalRef(mixed $value):bool{return is_string($value)&&preg_match('/\\Ajournal:[1-9][0-9]{0,19}\\z/D',$value)===1;}
    private static function greater(string $a,string $b):bool{return strlen($a)!==strlen($b)?strlen($a)>strlen($b):strcmp($a,$b)>0;}
    private static function moment(mixed $value):?int
    {
        if(!is_string($value)||preg_match('/\\A[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}Z\\z/D',$value)!==1)return null;
        $time=strtotime($value);return $time!==false&&gmdate('Y-m-d\\TH:i:s\\Z',$time)===$value?$time:null;
    }
    private static function fail(string $reason):never{throw new RuntimeException('modeled_early_forecast_'.$reason);}
}

