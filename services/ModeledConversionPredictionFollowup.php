<?php
declare(strict_types=1);
require_once __DIR__.'/ModeledConversionSemanticPrediction.php';

/**
 * Pure diagnostic comparison of two immutable, admitted prediction blocks.
 * Conversation reports are neither bank settlement nor an independent outcome
 * label. This target is separate from the manager-recorded purchase study.
 */
final class ModeledConversionPredictionFollowup
{
    public const VERSION='semantic_purchase_followup_v1';
    public const PREDICTION_VERSION='semantic_purchase_heuristic_v1';
    public const TARGET='conversation_payment_evidence_30d_v1';
    public const HORIZON_SECONDS=2592000;
    private const KINDS=[
        'payment_self_report','manager_payment_ack','booking_confirmation',
        'purchase_supported_candidate','payment_cancellation','booking_cancellation',
        'refund_report','purchased_elsewhere','document_issuance','known_operational_outcome',
    ];
    private const CONTRARY=['payment_cancellation','booking_cancellation','refund_report','purchased_elsewhere'];

    public static function compare(array $current,?array $prior):array
    {
        $result=self::empty($current);
        // No earlier prediction establishes neither a positive nor a negative.
        if($prior===null)return $result;
        $result=self::unknown($result,'invalid_prior');
        try{
            if(!self::block($current))return self::unknown($result,'invalid_current');
            if($current['status']==='access_denied')return self::unknown($result,'access_denied');
            if(!self::block($prior)||$prior['status']!=='predicted')return $result;
            // Issued documents alone establish neither payment nor settlement.
            // Every other known outcome conservatively excludes a prior prediction.
            foreach($prior['outcome_observations']as$observation)
                if($observation['kind']!=='document_issuance')return $result;
            foreach(['project_key','platform','customer_key','conversation_id']as$key)
                if($prior['subject'][$key]!==$current['subject'][$key])
                    return self::unknown($result,'scope_mismatch');
            if($current['current_request_anchor']===null||$prior['current_request_anchor']===null
                ||$prior['current_request_anchor']!==$current['current_request_anchor'])
                return self::unknown($result,'request_anchor_unmatched');
            if($prior['anchor_source_version_no']!==1||$current['anchor_source_version_no']!==1)
                return self::unknown($result,'edited_request_anchor');
            $freeze=self::moment($prior['frozen_at']);$now=self::moment($current['frozen_at']);
            if($freeze>$now)return $result;
            $cutoff=self::moment($current['input_cutoff_at']);
            $end=$freeze+self::HORIZON_SECONDS;
            // Copy the saved values, never a reconstructed or updated probability.
            $result['prior_prediction_digest']=$prior['prediction_digest'];
            $result['prior_prediction_at']=$prior['prediction_at'];
            $result['prior_estimated_probability']=$prior['estimated_probability'];
            $result['horizon_end_at']=gmdate('Y-m-d\TH:i:s\Z',$end);
            $evidence=[];$problems=[];$nonPayment=false;$contrary=false;
            foreach($current['outcome_observations']as$observation){
                $kind=$observation['kind'];
                $payment=in_array($kind,['payment_self_report','manager_payment_ack'],true);
                $isContrary=in_array($kind,self::CONTRARY,true);
                if(!$payment&&!$isContrary){$nonPayment=true;continue;}
                $occurred=self::moment($observation['occurred_at']);
                $available=self::moment($observation['available_at']);
                if($observation['source_version_no']!==1){
                    $problems['edited_outcome_source']=true;continue;
                }
                // Both clocks must follow actual creation, not the older input cutoff.
                if($occurred<=$freeze||$available<=$freeze){
                    $problems['outcome_not_strictly_after_freeze']=true;continue;
                }
                $id=substr($observation['source_ref'],8);
                if(!self::greater($id,$prior['source_fence_journal_id'])){
                    $problems['outcome_source_not_after_fence']=true;continue;
                }
                if($available>$cutoff){
                    $problems['outcome_not_available_at_cutoff']=true;continue;
                }
                // Contrary evidence never becomes a negative payment label, even
                // when recorded after the payment-evidence horizon.
                if($isContrary){$contrary=true;continue;}
                if($occurred>=$end||$available>=$end){
                    $problems['outcome_outside_horizon']=true;continue;
                }
                $evidence[]=[
                    'label'=>$kind==='payment_self_report'?'self_reported_payment':'manager_confirmed_payment',
                    'source_ref'=>$observation['source_ref'],
                    'occurred_at'=>$observation['occurred_at'],
                    'available_at'=>$observation['available_at'],
                    'source_version_no'=>1,
                ];
            }
            if($contrary)return self::unknown($result,'contrary_signal_after_prediction');
            // An edited, backdated, late-ingested or ambiguous payment source
            // cannot be rescued by another report from the same classifier.
            foreach(['edited_outcome_source','outcome_not_strictly_after_freeze',
                'outcome_source_not_after_fence','outcome_not_available_at_cutoff',
                'outcome_outside_horizon']as$reason)
                if(isset($problems[$reason]))return self::unknown($result,$reason);
            if($evidence){
                usort($evidence,static fn(array $a,array $b):int=>
                    strcmp($a['occurred_at'],$b['occurred_at'])
                    ?:self::refOrder($a['source_ref'],$b['source_ref'])
                    ?:strcmp($a['label'],$b['label']));
                $result['status']='observed';
                $result['reason']='future_conversation_payment_evidence';
                $result['evidence']=$evidence;
                return $result;
            }
            if($nonPayment)return self::unknown($result,'non_payment_outcome_only');
            return self::unknown($result,$now>=$end
                ?'horizon_elapsed_coverage_unknown':'no_eligible_payment_evidence');
        }catch(Throwable $ignored){
            // A bad historical JSON block must not break factual profile writes.
            return self::unknown(self::empty($current),'invalid_prior');
        }
    }

    private static function empty(array $current):array
    {
        $frozen=$current['frozen_at']??null;
        $observed=is_string($frozen)&&self::moment($frozen)!==null
            &&($current['prediction_at']??null)===$frozen?$frozen:null;
        $anchor=$current['current_request_anchor']??null;
        return [
            'version'=>self::VERSION,'target'=>self::TARGET,
            'meaning'=>'diagnostic_conversation_evidence_not_bank_settlement',
            'status'=>'waiting','reason'=>'no_earlier_prediction',
            'observed_at'=>$observed,
            'current_request_anchor'=>self::journalRef($anchor)?$anchor:null,
            'prior_prediction_digest'=>null,'prior_prediction_at'=>null,
            'prior_estimated_probability'=>null,
            'horizon_seconds'=>self::HORIZON_SECONDS,'horizon_end_at'=>null,
            'evidence'=>[],
            // No numeric label, calibrated probability, EV or accuracy claim.
            'payment_label'=>null,'calibrated_purchase_probability'=>null,
            'independent_validation'=>false,'source_history_complete'=>false,
            'evaluation_eligible'=>false,
            'evaluation_reason'=>'verified_outcome_and_complete_coverage_unavailable',
            'export_allowed'=>false,'conversion_uploads'=>0,'model_calls'=>0,
        ];
    }

    private static function unknown(array $result,string $reason):array
    {
        $result['status']='unknown';$result['reason']=$reason;$result['evidence']=[];
        return $result;
    }

    private static function block(array $value):bool
    {
        $nodes=0;
        if(!self::bounded($value,0,$nodes)||($value['version']??null)!==self::PREDICTION_VERSION
            ||!self::subject($value['subject']??null)
            ||!array_key_exists('current_request_anchor',$value)
            ||($value['current_request_anchor']!==null&&!self::journalRef($value['current_request_anchor']))
            ||!array_key_exists('anchor_source_version_no',$value)
            ||($value['current_request_anchor']===null?$value['anchor_source_version_no']!==null:
                (!is_int($value['anchor_source_version_no'])||$value['anchor_source_version_no']<1))
            ||!in_array($value['status']??null,['predicted','insufficient_context','known_outcome','access_denied'],true)
            ||!self::decimal($value['source_fence_journal_id']??null)
            ||!self::decimal($value['journal_cursor']??null)
            ||self::greater($value['journal_cursor'],$value['source_fence_journal_id'])
            ||!is_array($value['outcome_observations']??null)||!array_is_list($value['outcome_observations'])
            ||count($value['outcome_observations'])>8
            ||!is_string($value['prediction_digest']??null)
            ||preg_match('/\A[a-f0-9]{64}\z/D',$value['prediction_digest'])!==1)return false;
        $freeze=self::moment($value['frozen_at']??null);$cutoff=self::moment($value['input_cutoff_at']??null);
        if($freeze===null||$cutoff===null||($value['prediction_at']??null)!==$value['frozen_at']
            ||$cutoff>$freeze)return false;
        $anchor=$value['current_request_anchor'];
        if($anchor!==null&&self::greater(substr($anchor,8),$value['source_fence_journal_id']))return false;
        if(!array_key_exists('estimated_probability',$value))return false;
        $probability=$value['estimated_probability'];
        if($value['status']==='predicted'){
            // JSON round trips may represent an endpoint probability as an int.
            if((!is_float($probability)&&!is_int($probability))
                ||!is_finite((float)$probability)||$probability<0||$probability>1)return false;
        }elseif($probability!==null)return false;
        $seen=[];
        foreach($value['outcome_observations']as$observation){
            if(!is_array($observation)||count($observation)!==5
                ||!in_array($observation['kind']??null,self::KINDS,true)
                ||!self::journalRef($observation['source_ref']??null)
                ||!is_int($observation['source_version_no']??null)||$observation['source_version_no']<1)return false;
            $occurred=self::moment($observation['occurred_at']??null);
            $available=self::moment($observation['available_at']??null);
            $key=$observation['kind'].'|'.$observation['source_ref'];
            if($occurred===null||$available===null||$occurred>$available||$available>$freeze
                ||self::greater(substr($observation['source_ref'],8),$value['source_fence_journal_id'])
                ||isset($seen[$key]))return false;
            $seen[$key]=true;
        }
        // Bound the unchanged saved block; use the producer's shared digest verifier
        // across native key order and CLI/web numeric formatting.
        $whole=json_encode(self::canonical($value),JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
        return strlen($whole)<=16384&&ModeledConversionSemanticPrediction::digestMatches($value);
    }

    private static function canonical(array $value):array
    {
        if(!array_is_list($value))ksort($value,SORT_STRING);
        foreach($value as$key=>$item)if(is_array($item))$value[$key]=self::canonical($item);
        return $value;
    }

    private static function bounded(mixed $value,int $depth,int &$nodes):bool
    {
        if(++$nodes>512||$depth>8)return false;
        if(is_array($value)){
            if(count($value)>64)return false;
            foreach($value as$key=>$item){
                if(is_string($key)&&strlen($key)>128)return false;
                if(!self::bounded($item,$depth+1,$nodes))return false;
            }
            return true;
        }
        return $value===null||is_bool($value)||is_int($value)
            ||(is_float($value)&&is_finite($value))||(is_string($value)&&strlen($value)<=4096);
    }

    private static function subject(mixed $value):bool
    {
        return is_array($value)&&count($value)===4&&($value['project_key']??null)==='anytour'
            &&in_array($value['platform']??null,['max','telegram'],true)
            &&self::positive($value['customer_key']??null)&&self::positive($value['conversation_id']??null);
    }
    private static function decimal(mixed $value):bool
    {return is_string($value)&&preg_match('/\A(?:0|[1-9][0-9]{0,19})\z/D',$value)===1;}
    private static function positive(mixed $value):bool
    {return is_string($value)&&preg_match('/\A[1-9][0-9]{0,19}\z/D',$value)===1;}
    private static function journalRef(mixed $value):bool
    {return is_string($value)&&preg_match('/\Ajournal:[1-9][0-9]{0,19}\z/D',$value)===1;}
    private static function greater(string $a,string $b):bool
    {return strlen($a)!==strlen($b)?strlen($a)>strlen($b):strcmp($a,$b)>0;}
    private static function refOrder(string $a,string $b):int
    {$a=substr($a,8);$b=substr($b,8);return strlen($a)<=>strlen($b)?:strcmp($a,$b);}
    private static function moment(mixed $value):?int
    {
        if(!is_string($value)||preg_match('/\A[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}Z\z/D',$value)!==1)return null;
        $time=strtotime($value);
        return $time!==false&&$time>=946684800&&gmdate('Y-m-d\TH:i:s\Z',$time)===$value?$time:null;
    }
}
