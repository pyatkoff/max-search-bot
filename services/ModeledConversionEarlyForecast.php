<?php
declare(strict_types=1);

require_once __DIR__.'/ArchiveResearchSemanticExtractor.php';
require_once __DIR__.'/ManagerAiTextRedactor.php';

/**
 * Pure projection of the already admitted profile prefix.
 * No source reads, model calls, outcome writes or advertising exports.
 */
final class ModeledConversionEarlyForecast
{
    public const VERSION='live_contextual_ordinal_v1';
    public const TARGET_VERSION='purchase_readiness_30d_ordinal_v1';
    public const HORIZON_SECONDS=2592000;

    public static function freeze(array $case,array $wrapper,array $document,?string $firstCursor=null,?int $frozenAt=null):array
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
        $state=ArchiveResearchSemanticExtractor::initialState();$events=[];$current=[];$boundaries=[];
        $flags=['episode_identity_unverified'=>true,'source_history_incomplete'=>true];
        if($clockUnresolved)$flags['context_linking_disabled_unknown_clock']=true;
        if(($case['window_limited']??null)!==false)$flags['window_limited']=true;
        $counts=array_fill_keys(ArchiveResearchSemanticExtractor::goalIds(),0);
        $turns=['customer'=>0,'manager'=>0];$replySeconds=[];$previous=null;$barriers=0;$matched=0;$semanticOmissions=[];
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
            if($permit===null||$clockUnresolved){
                $barrier=ArchiveResearchSemanticExtractor::step(['read_status'=>'source_unavailable','source_ref'=>$ref,'created_at'=>$item['at']],$state);
                $state=$barrier['state'];$previous=null;$barriers++;
            }
            if($permit===null)continue;
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
            foreach($result['flags']as$key=>$value)if($value===true)$flags[$key]=true;
            if($result['episode_boundary']!==null){
                $boundaries[]=['source_ref'=>$ref,'kind'=>$result['episode_boundary'],'episode'=>$state['e'],'identity'=>'unverified'];
                $current=[];
            }
            if(($result['flags']['unresolved_trip_boundary']??false)===true
                ||($result['flags']['party_change_unresolved']??false)===true)$current=[];
            foreach($result['events']as$event){
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
            'source_revision_chronology_unresolved'=>isset($wrapper['coverage']['errors']['source_revision_chronology_unresolved'])];
        return [
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
                'contrary_goal_ids'=>$contrary,'known_outcome_exclusion_scope'=>'any_available_prefix_episode_conservative',
                'purchase_anywhere'=>['readiness_band'=>$any,'probability'=>null],
                'purchase_with_us'=>['readiness_band'=>$agency,'probability'=>null],
                'expected_value_rub'=>null,'calibration_status'=>'not_calibrated','coverage'=>$coverage,
                'evaluation_eligible'=>false,'evaluation_reason'=>'trip_and_verified_outcome_contract_unresolved',
                'export_allowed'=>false,'conversion_uploads'=>0,'model_calls'=>0],
        ];
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
