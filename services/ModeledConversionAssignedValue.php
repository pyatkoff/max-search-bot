<?php
declare(strict_types=1);

/** Business-assigned current value, never purchase probability or advertising revenue. */
final class ModeledConversionAssignedValue
{
    public const VERSION='business_early_value_v1';
    public const WEIGHTS=['subscription'=>50,'retention_30m'=>25,'dialogue'=>50,'specific_request'=>50,'offer_reply'=>100];

    public static function profile(array $wrapper,?array $case=null):array
    {
        $cutoff=$wrapper['profile']['prediction_at']??null;
        $result=self::empty($wrapper['subject']??[],$cutoff);
        if(($wrapper['access_status']??null)!=='allowed')return $result;
        $signals=$wrapper['profile']['early_signals']??[];
        foreach(['subscription','retention_30m']as$key){
            $s=$signals[$key]??[];
            if(($s['status']??null)==='known'&&($s['state']??null)===($key==='subscription'?'subscribed':'retained'))
                {
                self::award($result,$key,[$s['receipt_id']??null],$s['available_at']??null,$s['at']??$s['checked_at']??null);
                if($result['stages'][$key]['status']==='awarded')$result['stages'][$key]['recorded_at']=$s['recorded_at']??null;
            }
        }
        $context=$wrapper['contextual_signals']??[];
        $dialogueRefs=[];
        if($case!==null)foreach($case['rows']??[]as$row){
            $payload=$row['snapshot']['payload']??[];$text=$payload['text']??null;
            $metadata=json_decode($payload['metadata_json']??'{}',true);
            if(($row['source_kind']??null)==='message'&&($payload['direction']??null)==='inbound'
                &&($payload['sender_type']??null)==='customer'&&($metadata['type']??null)==='message'
                &&is_string($text)&&trim($text)!==''&&!str_starts_with(ltrim($text),'/'))
                $dialogueRefs[]='journal:'.$row['journal_id'];
        }
        elseif(($wrapper['assigned_value']['rules_version']??null)===self::VERSION
            &&($wrapper['assigned_value']['stages']['dialogue']['status']??null)==='awarded')
            $dialogueRefs=$wrapper['assigned_value']['stages']['dialogue']['source_refs'];
        foreach($wrapper['profile']['observation_input']['evidence']??[]as$event){
            if(($event['kind']??null)!=='customer_message')continue;
            $refs=array_column($event['references']??[],'id');
            if(array_intersect($refs,$dialogueRefs))self::award($result,'dialogue',$refs,$event['available_at']??null,$event['at']??null);
        }
        foreach($context['events']??[]as$event)if(($event['goal']??null)==='substantive_interest'){
            $ref=$event['source_ref']??null;
            foreach($wrapper['profile']['observation_input']['evidence']??[]as$e)
                if(($e['kind']??null)==='customer_message'&&in_array($ref,array_column($e['references']??[],'id'),true))
                {
                    self::award($result,'dialogue',[$ref],$e['available_at']??null,$e['at']??null);
                    self::award($result,'specific_request',[$ref],$e['available_at']??null,$e['at']??null);
                }
        }
        // Reuse the installed exact request/preparation/delivery/text linkage. No new trip parser.
        foreach($wrapper['early_forecast']['early_quality']['specific_option_discussions']??[]as$reply){
            if(($reply['current_candidate']??null)!==true)continue;
            $reactionAt=null;$reactionRef=array_slice($reply['source_refs']??[],-1)[0]??null;
            foreach($wrapper['profile']['observation_input']['evidence']??[]as$e)
                if(($e['kind']??null)==='customer_message'&&in_array($reactionRef,array_column($e['references']??[],'id'),true))$reactionAt=$e['at']??null;
            self::award($result,'offer_reply',$reply['source_refs']??[],$reply['available_at']??null,$reactionAt);
            $result['reaction_type']=$reply['reaction']??'unknown';
        }
        $result['coverage']['offer_reply']='exact_linked_text_discussions_only_other_replies_unknown';
        return self::finish($result);
    }

    /** Current existing-producer membership row, including people with no conversation. */
    public static function member(array $row,int $now):array
    {
        $scope=['project_key'=>'anytour','platform'=>'max','external_user_id'=>$row['external_user_id']??'',
            'membership_source_id'=>$row['source_id']??''];
        $result=self::empty($scope,gmdate('Y-m-d\TH:i:s\Z',$now));
        $sub=$row['subscribed_at_unix']??null;
        if(($row['state']??null)==='subscribed'&&is_int($sub)&&$sub>=946684800&&$sub<=$now){
            $ref='membership:'.($row['record_id']??'');
            self::award($result,'subscription',[$ref],$result['evaluated_at'],gmdate('Y-m-d\TH:i:s\Z',$sub));
            $check=$row['retention_checked_at_unix']??null;
            if(is_int($check)&&$check>=$sub+1800&&$check<=$now&&is_string($row['retention_record_id']??null))
                self::award($result,'retention_30m',['retention:'.$row['retention_record_id'],$ref],$result['evaluated_at'],gmdate('Y-m-d\TH:i:s\Z',$check));
        }
        $result['coverage']['membership_available_at']='current_observation_not_historical_availability';
        return self::finish($result);
    }

    /** Merge one proven same-person current profile; repeated versions never add totals. */
    public static function merge(array $member,array $profile):array
    {
        foreach(['dialogue','specific_request','offer_reply']as$key)
            if(($profile['stages'][$key]['status']??null)==='awarded')$member['stages'][$key]=$profile['stages'][$key];
        $member['reaction_type']=$profile['reaction_type']??'unknown';
        return self::finish($member);
    }

    private static function empty(array $scope,mixed $cutoff):array
    {
        $stages=[];foreach(self::WEIGHTS as$key=>$weight)$stages[$key]=['status'=>'unknown','weight_rub'=>$weight,'source_refs'=>[],'occurred_at'=>null,'recorded_at'=>null,'available_at'=>null];
        return ['rules_version'=>self::VERSION,'value_scope'=>$scope,'evaluated_at'=>$cutoff,'currency'=>'RUB',
            'known_subtotal_rub'=>0,'completeness'=>'partial','stages'=>$stages,'reaction_type'=>'unknown',
            'coverage'=>[],'meaning'=>'assigned_business_weights_not_purchase_probability',
            'discussion_extra'=>['weight_rub'=>75,'enabled'=>false,'approval'=>'not_approved'],
            'export_allowed'=>false,'conversion_uploads'=>0];
    }
    private static function award(array &$r,string $key,array $refs,mixed $available,mixed $occurred):void
    {
        if(($r['stages'][$key]['status']??null)==='awarded'||!is_string($available)||!is_string($r['evaluated_at'])
            ||$available>$r['evaluated_at']||!$refs)return;
        foreach($refs as$ref)if(!is_string($ref)||$ref==='')return;
        $r['stages'][$key]=['status'=>'awarded','weight_rub'=>self::WEIGHTS[$key],
            'source_refs'=>array_values(array_unique($refs)),'occurred_at'=>$occurred,'recorded_at'=>null,'available_at'=>$available];
    }
    private static function finish(array $r):array
    {
        $r['known_subtotal_rub']=0;$complete=true;
        foreach($r['stages']as$s){if($s['status']==='awarded')$r['known_subtotal_rub']+=$s['weight_rub'];else$complete=false;}
        $r['completeness']=$complete?'all_five_awarded':'partial';return$r;
    }
}
