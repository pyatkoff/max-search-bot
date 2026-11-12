<?php
declare(strict_types=1);

/** Exact ordinary saved receipt; the fixed repair frontier is an exclusion proof only. */
final class DevelopmentRoadmapExactReceipt
{
    public const VERSION='development-roadmap-exact-saved-receipt-v1';
    private const KEYS=['available','goal_id','counter_id','receipt_ref','basis','provider_requests','conversion_uploads',
        'per_receipt_visit_linkage','checked_at','coverage_complete','inspected_rows','state','upload_status','event_at',
        'first_api_observed_at','processed_at','first_api_clock_known','terminal_processing','processed_upload_row'];

    public static function selection(array $v,int $cutoff,int $now): array
    {
        self::need(count($v)===2&&array_diff(array_keys($v),['receipt_ref','baseline_saved_receipt'])===[],'selection_shape');
        self::need(self::sha($v['receipt_ref']??null)&&is_array($v['baseline_saved_receipt']??null),'selection');
        $baseline=self::receipt($v['baseline_saved_receipt'],$v['receipt_ref'],$cutoff,$now);
        self::need(!self::failed($baseline)&&in_array($baseline['state'],['accepted','processed'],true)
            &&in_array($baseline['upload_status'],['UPLOADED','EXPORTED','MATCHED','PROCESSED'],true),'baseline_positive');
        return $v;
    }

    public static function evaluate(string $kind,array $capsule,array $snapshot,int $now,?array $parent,?string $parentHash): array
    {
        try{
            if(($snapshot['available']??null)!==true)return self::result('waiting_evidence','saved_projection_unavailable');
            $c=DevelopmentRoadmapSavedResult::capsule($capsule);$selection=$c['exact_receipt'];$base=$c;unset($base['exact_receipt']);
            self::need(in_array($kind,DevelopmentRoadmapSavedResult::KINDS,true),'kind');
            self::need(($snapshot['available']??null)===true&&count($snapshot)===6
                &&array_diff(array_keys($snapshot),['available','schema','captured_at','provider_requests','repair_assessments','exact_receipt'])===[]
                &&($snapshot['schema']??null)===self::VERSION&&($snapshot['provider_requests']??null)===0,'snapshot_scope');
            $oldSnapshot=$snapshot;unset($oldSnapshot['exact_receipt']);$oldSnapshot['schema']=DevelopmentRoadmapSavedResult::VERSION;
            // Reuse all original membership/23-terminal/3-original/freshness checks without crediting that cohort.
            $exclusion=DevelopmentRoadmapSavedResult::evaluate('saved_finance_new_receipts',$base,$oldSnapshot,$now);
            self::need(in_array($exclusion['reason']??null,[
                'no_new_saved_receipt','new_saved_receipt_not_ready','new_saved_terminal_failure',
                'distinct_review_and_supported_admission_required'],true),'repair_exclusion_evidence');
            foreach($snapshot['repair_assessments']['processing_frontier']['rows']as$row)
                self::need($row[6]!==$selection['receipt_ref'],'target_inside_repair_frontier');
            $current=$snapshot['exact_receipt']??null;
            if(!is_array($current)||($current['available']??null)!==true){
                self::need(is_array($current)&&($current['provider_requests']??null)===0
                    &&($current['conversion_uploads']??null)===0
                    &&($current['receipt_ref']??null)===$selection['receipt_ref'],'unavailable_scope');
                $reason=$current['reason']??null;
                if(in_array($reason,['receipt_not_found_in_saved_goal_scope','saved_projection_unavailable','saved_scope_row_limit'],true))
                    return self::result('waiting_evidence','exact_saved_receipt_unavailable');
                return self::result('blocked','exact_saved_receipt_ambiguous_or_invalid');
            }
            $at=$snapshot['captured_at'];$r=self::receipt($current,$selection['receipt_ref'],$c['api_observed_not_before_unix'],$at);
            $baseline=$selection['baseline_saved_receipt'];
            self::need($r['event_at']===$baseline['event_at']&&$r['first_api_observed_at']===$baseline['first_api_observed_at'],
                'immutable_receipt_clocks_changed');
            self::need(self::instant($r['checked_at'])===$at,'snapshot_clock');
            $target=['receipt_ref'=>$r['receipt_ref'],'event_at'=>$r['event_at'],'first_api_observed_at'=>$r['first_api_observed_at']];
            $ch=DevelopmentRoadmapSavedResult::hash($c);$sh=DevelopmentRoadmapSavedResult::hash($snapshot);
            if($kind==='saved_finance_new_receipts')self::need($parent===null&&$parentHash===null,'unexpected_parent');
            else{
                self::need(is_array($parent)&&self::sha($parentHash)&&DevelopmentRoadmapSavedResult::hash($parent)===$parentHash
                    &&($parent['state']??null)==='candidate'&&($parent['kind']??null)==='saved_finance_new_receipts'
                    &&($parent['schema']??null)===self::VERSION&&($parent['capsule_sha256']??null)===$ch
                    &&($parent['scope']??null)==='ordinary_saved_receipt_outside_repair_frontier'
                    &&($parent['automatic_acceptance_allowed']??null)===false
                    &&is_array($parent['new_receipts']??null)
                    &&DevelopmentRoadmapSavedResult::hash($parent['new_receipts'])===DevelopmentRoadmapSavedResult::hash([$target]),'exact_parent_binding');
            }
            if(self::failed($r))return self::result('blocked','exact_saved_terminal_failure')
                +['capsule_sha256'=>$ch,'snapshot_sha256'=>$sh,'parent_result_sha256'=>$parentHash];
            if(!in_array($r['state'],['accepted','processed'],true)
                ||!in_array($r['upload_status'],['UPLOADED','EXPORTED','MATCHED','PROCESSED'],true))
                return self::result('waiting_evidence','exact_saved_receipt_not_ready');
            if($kind==='saved_finance_processing'&&!$r['processed_upload_row'])
                return self::result('waiting_evidence','exact_saved_processing_pending')
                    +['capsule_sha256'=>$ch,'snapshot_sha256'=>$sh,'parent_result_sha256'=>$parentHash];
            return self::result('candidate','distinct_review_and_supported_admission_required')+[
                'schema'=>self::VERSION,'scope'=>'ordinary_saved_receipt_outside_repair_frontier','kind'=>$kind,
                'capsule_sha256'=>$ch,'snapshot_sha256'=>$sh,'captured_at'=>$at,'parent_result_sha256'=>$parentHash,
                'new_receipts'=>[$target],'new_receipt_clock_basis'=>'original_known_saved_api_clock_after_installation_cutoff',
                'repair_frontier_purpose'=>'unchanged_exclusion_only_no_cohort_or_value_credit',
                'old_terminal_receipts_unchanged'=>count($base['old_receipts']),
                'excluded_original_count'=>count($base['excluded_original_refs']),
                'per_receipt_visit_linkage'=>'not_checked','coding_or_deployment_proof'=>false];
        }catch(InvalidArgumentException|JsonException $invalid){return self::result('blocked','invalid_or_changed_exact_saved_evidence');}
    }

    private static function receipt(array $r,string $reference,int $cutoff,int $now): array
    {
        self::need(count($r)===count(self::KEYS)&&array_diff(array_keys($r),self::KEYS)===[],'receipt_shape');
        self::need($r['available']===true&&$r['goal_id']==='678187097'&&$r['counter_id']==='98615635'
            &&$r['receipt_ref']===$reference&&$r['basis']==='current_primary_saved_outbox_receipt_status'
            &&$r['provider_requests']===0&&$r['conversion_uploads']===0
            &&$r['per_receipt_visit_linkage']==='not_checked'&&$r['coverage_complete']===true
            &&is_int($r['inspected_rows'])&&$r['inspected_rows']>=1&&$r['inspected_rows']<1001,'receipt_scope');
        self::need(in_array($r['state'],['pending','ready','uploading','accepted','processed','unknown','failed','expired','unmatched'],true)
            &&in_array($r['upload_status'],['unknown','not_recorded','PREPARED','UPLOADED','EXPORTED','MATCHED','PROCESSED','LINKAGE_FAILURE'],true)
            &&is_bool($r['terminal_processing'])&&is_bool($r['processed_upload_row'])&&$r['first_api_clock_known']===true,'receipt_state');
        $event=self::instant($r['event_at']);$api=self::instant($r['first_api_observed_at']);$checked=self::instant($r['checked_at']);
        $processed=$r['processed_at']===null?null:self::instant($r['processed_at']);
        self::need($event<=$api&&$api>=$cutoff&&$api<=$checked&&$checked<=$now
            &&($processed===null||($processed>=$api&&$processed<=$checked)),'receipt_clocks');
        self::need($r['terminal_processing']===in_array($r['state'],['processed','unmatched','failed'],true)
            &&$r['processed_upload_row']===($r['state']==='processed'&&$r['upload_status']==='PROCESSED')
            &&(!$r['processed_upload_row']||$processed!==null),'terminal_consistency');
        return $r;
    }
    private static function failed(array $r): bool
    {return in_array($r['state'],['failed','unmatched','expired'],true)||$r['upload_status']==='LINKAGE_FAILURE';}
    private static function instant(mixed $v): int
    {
        self::need(is_string($v)&&preg_match('/\A\d{4}-\d\d-\d\dT\d\d:\d\d:\d\dZ\z/D',$v)===1,'clock_format');
        $at=strtotime($v);self::need(is_int($at)&&$at>=946684800&&gmdate('Y-m-d\TH:i:s\Z',$at)===$v,'clock_value');return $at;
    }
    private static function sha(mixed $v): bool
    {return is_string($v)&&preg_match('/\A[a-f0-9]{64}\z/D',$v)===1;}
    private static function need(bool $ok,string $reason): void
    {if(!$ok)throw new InvalidArgumentException('roadmap_exact_'.$reason);}
    private static function result(string $state,string $reason): array
    {return ['state'=>$state,'reason'=>$reason,'dispatch_allowed'=>false,'automatic_acceptance_allowed'=>false,
        'provider_requests'=>0,'mutation_performed'=>false];}
}
