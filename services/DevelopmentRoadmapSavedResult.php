<?php
declare(strict_types=1);

/**
 * Finite saved-result adapters for roadmap preparation.
 * No admission, timer, model request, callback, source edit, upload or acceptance.
 */
final class DevelopmentRoadmapSavedResult
{
    public const VERSION='development-roadmap-saved-finance-v1';
    public const KINDS=['saved_finance_new_receipts','saved_finance_processing'];
    private const STATES=['unknown','pending','ready','uploading','accepted','processed','unknown','failed','expired','unmatched'];
    private const UPLOADS=['unknown','not_recorded','PREPARED','UPLOADED','EXPORTED','MATCHED','PROCESSED','LINKAGE_FAILURE'];
    private const COLUMNS=['event_ref','platform','value_rub','state','hold_reason','upload_status','receipt_ref','api_first_unix','processed_unix'];

    public static function capability(string $kind): array
    {
        return ['kind'=>$kind,'supported'=>in_array($kind,self::KINDS,true),'execution_kind'=>'saved_first_party_read_only',
            'dispatch_allowed'=>false,'automatic_acceptance_allowed'=>false,
            'reason'=>in_array($kind,self::KINDS,true)?'inert_adapter_requires_separate_admission':'unsupported_genuine_execution_route'];
    }

    /** Calls the canonical SELECT-only reader under one real read-only snapshot. */
    public static function read(PDO $pdo,callable $authorize,int $now): array
    {
        if($now<946684800||$pdo->inTransaction())throw new InvalidArgumentException('roadmap_read_context');
        require_once __DIR__.'/ModeledConversionValueDeliveryReader.php';
        $auth=static function()use($authorize):void {if($authorize()===false)throw new DomainException('roadmap_authority_unavailable');};
        $auth();$owned=false;
        try {
            $mysql=$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql';
            if($mysql){$pdo->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');$pdo->exec('SET TRANSACTION READ ONLY');}
            $pdo->beginTransaction();$owned=true;
            $reader=new ModeledConversionValueDeliveryReader($pdo,$auth,new ModeledConversionStatusBudget());
            $repair=$reader->repairAssessments($now);
            $pdo->rollBack();$owned=false;$auth();
            if(!is_array($repair)||($repair['available']??null)!==true)
                return ['available'=>false,'reason'=>'canonical_saved_projection_unavailable','provider_requests'=>0];
            return ['available'=>true,'schema'=>self::VERSION,'captured_at'=>$now,'provider_requests'=>0,
                'repair_assessments'=>self::projection($repair)];
        } finally {if($owned&&$pdo->inTransaction())$pdo->rollBack();}
    }

    /** Complete contract input; unrelated source/audit histories are excluded before storage. */
    public static function projection(array $repair): array
    {
        $r=array_intersect_key($repair,array_flip(['available','assessment_namespace','provider_requests','coverage',
            'new_increment_events','new_increment_value_rub','processing_frontier']));
        if(is_array($r['processing_frontier']??null))$r['processing_frontier']=array_intersect_key($r['processing_frontier'],array_flip([
            'available','complete_within_bound','provider_requests_in_reader','profile_cutoff_unix','later_profile_events',
            'later_profile_value_rub','observed_events','observed_value_rub','membership_sha256','columns','legend','rows']));
        if(strlen(json_encode($r,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR))>20000)throw new DomainException('roadmap_snapshot_budget');
        return $r;
    }
    /**
     * Validates exact private, owner-reviewed input. No defaults select a cohort.
     * Receipt and event references are opaque hashes; no customer/credential data.
     */
    public static function capsule(array $v): array
    {
        self::keys($v,['schema','namespace_sha256','membership_sha256','profile_cutoff_unix','events','value_rub',
            'old_receipts','excluded_original_refs','api_observed_not_before_unix','verification_not_before_unix','max_snapshot_age_seconds']);
        self::need(($v['schema']??null)===self::VERSION,'capsule_schema');
        foreach(['namespace_sha256','membership_sha256']as$k)self::need(self::sha($v[$k]??null),'capsule_digest');
        foreach(['profile_cutoff_unix','api_observed_not_before_unix','verification_not_before_unix']as$k)self::need(self::instant($v[$k]??null),'capsule_clock');
        self::need(is_int($v['events'])&&$v['events']>=1&&$v['events']<=64,'capsule_row_bound');
        self::need(is_int($v['value_rub'])&&$v['value_rub']>0&&$v['value_rub']<=1600000,'capsule_value');
        self::need(is_int($v['max_snapshot_age_seconds'])&&$v['max_snapshot_age_seconds']>=1
            &&$v['max_snapshot_age_seconds']<=60,'capsule_freshness');
        self::need(is_array($v['old_receipts'])&&array_is_list($v['old_receipts'])
            &&count($v['old_receipts'])<=64,'capsule_old_receipts');
        $events=[];$receipts=[];
        foreach($v['old_receipts']as$r){
            self::row($r,PHP_INT_MAX);
            self::need($r[6]!==null&&in_array(self::UPLOADS[$r[5]],['PROCESSED','LINKAGE_FAILURE'],true),'capsule_old_terminal');
            self::need(!isset($events[$r[0]])&&!isset($receipts[$r[6]]),'capsule_old_duplicate');
            $events[$r[0]]=true;$receipts[$r[6]]=true;
        }
        foreach($v['old_receipts']as$row)self::need($row[7]<$v['api_observed_not_before_unix'],'capsule_old_cutoff');
        self::need(count($events)<=$v['events'],'capsule_old_count');
        self::need(is_array($v['excluded_original_refs'])&&array_is_list($v['excluded_original_refs'])
            &&count($v['excluded_original_refs'])<=3,'capsule_exclusions');
        $seen=[];foreach($v['excluded_original_refs']as$ref){
            self::need(self::sha($ref)&&!isset($seen[$ref])&&!isset($events[$ref]),'capsule_exclusion_binding');$seen[$ref]=true;
        }
        return $v;
    }

    /**
     * A candidate is evidence for a distinct saved-result review, never acceptance.
     * Processing consumes the exact parent result hash and its saved new receipts.
     */
    public static function evaluate(string $kind,array $capsule,array $snapshot,int $now,?array $parent=null,?string $parentHash=null): array
    {
        if(!in_array($kind,self::KINDS,true))return self::result('blocked','unsupported_genuine_execution_route');
        try {
            $c=self::capsule($capsule);
            self::need(self::instant($now),'now');
            if(($snapshot['available']??false)!==true)return self::result('waiting_evidence','saved_projection_unavailable');
            self::keys($snapshot,['available','schema','captured_at','provider_requests','repair_assessments']);
            self::need(($snapshot['schema']??null)===self::VERSION&&($snapshot['provider_requests']??null)===0,'snapshot_kind');
            $at=$snapshot['captured_at']??null;
            self::need(self::instant($at)&&$at>=$c['verification_not_before_unix']&&$at<=$now
                &&$now-$at<=$c['max_snapshot_age_seconds'],'snapshot_freshness');
            $r=$snapshot['repair_assessments']??null;
            self::need(is_array($r)&&($r['available']??null)===true
                &&($r['assessment_namespace']??null)===$c['namespace_sha256']
                &&($r['coverage']['whole_current_namespace_repair_cohort']??null)===true
                &&($r['provider_requests']??null)===0,'cohort_scope');
            $f=$r['processing_frontier']??null;
            self::need(is_array($f)&&is_int($f['later_profile_events']??null)&&$f['later_profile_events']>=0
                &&self::wholeRub($f['later_profile_value_rub']??null)!==null
                &&($r['new_increment_events']??null)===$c['events']+$f['later_profile_events']
                &&self::wholeRub($r['new_increment_value_rub']??null)===$c['value_rub']+self::wholeRub($f['later_profile_value_rub']),
                'cohort_totals');
            self::need(is_array($f)&&($f['available']??null)===true&&($f['complete_within_bound']??null)===true
                &&($f['provider_requests_in_reader']??null)===0
                &&($f['profile_cutoff_unix']??null)===$c['profile_cutoff_unix']
                &&($f['observed_events']??null)===$c['events']
                &&($f['observed_value_rub']??null)===$c['value_rub']
                &&($f['membership_sha256']??null)===$c['membership_sha256']
                &&($f['columns']??null)===self::COLUMNS
                &&($f['legend']['state']??null)===self::STATES
                &&($f['legend']['upload_status']??null)===self::UPLOADS,'frontier_scope');
            self::need(is_array($f['rows']??null)&&array_is_list($f['rows'])&&count($f['rows'])===$c['events'],'frontier_complete');
            $rows=[];$receipts=[];$members=[];$sum=0;
            foreach($f['rows']as$row){
                self::row($row,$at);$ref=$row[0];
                self::need(!isset($rows[$ref]),'duplicate_event');$rows[$ref]=$row;
                if($row[6]!==null){self::need(!isset($receipts[$row[6]]),'duplicate_receipt');$receipts[$row[6]]=true;}
                $members[$ref]=[$ref,$row[1],$row[2]];$sum+=$row[2];
            }
            ksort($members,SORT_STRING);
            $membership=hash('sha256',json_encode(['repair_processing_frontier_v1',$c['namespace_sha256'],
                $c['profile_cutoff_unix'],array_values($members)],JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR));
            self::need($sum===$c['value_rub']&&$membership===$c['membership_sha256'],'actual_membership_digest');
            $old=[];
            foreach($c['old_receipts']as$row){
                self::need(isset($rows[$row[0]])&&$rows[$row[0]]===$row,'old_terminal_changed');$old[$row[6]]=true;
            }
            $excluded=array_fill_keys($c['excluded_original_refs'],true);
            foreach($rows as$row)if($row[6]!==null&&$row[7]<$c['api_observed_not_before_unix']&&!isset($excluded[$row[0]]))
                self::need(isset($old[$row[6]]),'preinstall_receipt_omitted_from_baseline');
            foreach($excluded as$ref=>$unused)self::need(isset($rows[$ref]),'excluded_original_missing');
            $cHash=self::hash($c);$sHash=self::hash($snapshot);$new=[];
            if($kind==='saved_finance_new_receipts'){
                self::need($parent===null&&$parentHash===null,'unexpected_parent');
                $observed=[];$failed=false;$pending=false;
                foreach($rows as$row)if($row[6]!==null&&!isset($old[$row[6]])&&!isset($excluded[$row[0]])
                    &&$row[7]>=$c['api_observed_not_before_unix']){
                    $observed[]=['event_ref'=>$row[0],'receipt_ref'=>$row[6],'value_rub'=>$row[2]];
                    if(self::UPLOADS[$row[5]]==='LINKAGE_FAILURE'||in_array(self::STATES[$row[3]],['failed','unmatched'],true))
                        $failed=true;
                    if(!in_array(self::STATES[$row[3]],['accepted','processed'],true)
                        ||!in_array(self::UPLOADS[$row[5]],['UPLOADED','EXPORTED','MATCHED','PROCESSED'],true))$pending=true;
                }
                if(!$observed)return self::result('waiting_evidence','no_new_saved_receipt')+['capsule_sha256'=>$cHash,'snapshot_sha256'=>$sHash];
                if($failed)return self::result('blocked','new_saved_terminal_failure')
                    +['capsule_sha256'=>$cHash,'snapshot_sha256'=>$sHash,'new_receipts'=>$observed];
                if($pending)return self::result('waiting_evidence','new_saved_receipt_not_ready')
                    +['capsule_sha256'=>$cHash,'snapshot_sha256'=>$sHash,'new_receipts'=>$observed];
                $new=$observed;
            } else {
                self::need(is_array($parent)&&self::sha($parentHash)&&self::hash($parent)===$parentHash,'exact_parent_hash');
                self::need(($parent['state']??null)==='candidate'&&($parent['kind']??null)==='saved_finance_new_receipts'
                    &&($parent['capsule_sha256']??null)===$cHash&&($parent['automatic_acceptance_allowed']??null)===false
                    &&is_array($parent['new_receipts']??null)&&array_is_list($parent['new_receipts'])
                    &&count($parent['new_receipts'])>=1&&count($parent['new_receipts'])<=64,'parent_scope');
                $seen=[];$seenReceipts=[];$targetRows=[];
                foreach($parent['new_receipts']as$target){
                    self::keys($target,['event_ref','receipt_ref','value_rub']);$ref=$target['event_ref']??null;$receipt=$target['receipt_ref']??null;
                    self::need(self::sha($ref)&&self::sha($receipt)&&!isset($seen[$ref])&&!isset($seenReceipts[$receipt])
                        &&!isset($excluded[$ref])&&!isset($old[$receipt]),'parent_target');
                    $seen[$ref]=true;$seenReceipts[$receipt]=true;$row=$rows[$ref]??null;
                    self::need(is_array($row)&&$row[6]===$receipt&&$row[2]===($target['value_rub']??null)
                        &&$row[7]>=$c['api_observed_not_before_unix'],'parent_target_changed');
                    $targetRows[]=$row;$new[]=$target;
                }
                // Validate every binding before considering pending status or a terminal failure.
                $failed=false;$pending=false;
                foreach($targetRows as$row){
                    if(self::UPLOADS[$row[5]]==='LINKAGE_FAILURE'||in_array(self::STATES[$row[3]],['failed','unmatched'],true))$failed=true;
                    if(self::STATES[$row[3]]!=='processed'||self::UPLOADS[$row[5]]!=='PROCESSED'||$row[8]===null)$pending=true;
                }
                if($failed)return self::result('blocked','new_saved_linkage_failure')
                    +['capsule_sha256'=>$cHash,'snapshot_sha256'=>$sHash,'parent_result_sha256'=>$parentHash];
                if($pending)return self::result('waiting_evidence','new_saved_processing_pending')
                    +['capsule_sha256'=>$cHash,'snapshot_sha256'=>$sHash,'parent_result_sha256'=>$parentHash];
            }
            usort($new,static fn(array $a,array $b):int=>strcmp($a['event_ref'],$b['event_ref']));
            return self::result('candidate','distinct_review_and_supported_admission_required')+[
                'schema'=>self::VERSION,'kind'=>$kind,'capsule_sha256'=>$cHash,'snapshot_sha256'=>$sHash,
                'captured_at'=>$at,'parent_result_sha256'=>$parentHash,'new_receipts'=>$new,
                'new_receipt_value_rub'=>array_sum(array_column($new,'value_rub')),
                'old_terminal_receipts_unchanged'=>count($old),'excluded_original_count'=>count($excluded),
                'per_receipt_visit_linkage'=>'not_checked','coding_or_deployment_proof'=>false];
        }catch(InvalidArgumentException|JsonException $invalid){
            return self::result('blocked','invalid_or_changed_saved_evidence');
        }
    }

    public static function hash(array $v): string
    {return hash('sha256',json_encode(self::canonical($v),JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR));}
    private static function canonical(mixed $v): mixed
    {if(!is_array($v))return $v;if(!array_is_list($v))ksort($v,SORT_STRING);foreach($v as&$item)$item=self::canonical($item);return $v;}
    private static function result(string $state,string $reason): array
    {return ['state'=>$state,'reason'=>$reason,'dispatch_allowed'=>false,'automatic_acceptance_allowed'=>false,
        'provider_requests'=>0,'mutation_performed'=>false];}
    private static function keys(array $v,array $keys): void
    {self::need(count($v)===count($keys)&&array_diff(array_keys($v),$keys)===[],'shape');}
    private static function row(mixed $v,int $now): void
    {
        self::need(is_array($v)&&array_is_list($v)&&count($v)===9&&self::sha($v[0]),'row');
        self::need(is_int($v[1])&&in_array($v[1],[1,2],true)&&is_int($v[2])&&$v[2]>0&&$v[2]<=25000,'row_value');
        self::need(is_int($v[3])&&array_key_exists($v[3],self::STATES)&&is_int($v[4])&&$v[4]>=0&&$v[4]<=15
            &&is_int($v[5])&&array_key_exists($v[5],self::UPLOADS),'row_state');
        self::need($v[6]===null||self::sha($v[6]),'row_receipt');
        foreach([7,8]as$i)self::need($v[$i]===null||(self::instant($v[$i])&&$v[$i]<=$now),'row_clock');
        self::need(($v[6]===null&&$v[7]===null&&$v[8]===null)
            ||($v[6]!==null&&$v[7]!==null&&($v[8]===null||$v[8]>=$v[7])),'row_receipt_clock');
        if(self::STATES[$v[3]]==='processed'&&self::UPLOADS[$v[5]]==='PROCESSED')self::need($v[8]!==null,'row_processed_clock');
    }
    private static function wholeRub(mixed $v): ?int
    {
        if(is_int($v))return $v;
        if(is_float($v)&&is_finite($v)&&$v>=0&&$v<=1000000000000&&$v===floor($v))return (int)$v;
        return null;
    }
    private static function instant(mixed $v): bool{return is_int($v)&&$v>=946684800;}
    private static function sha(mixed $v): bool{return is_string($v)&&preg_match('/\A[a-f0-9]{64}\z/D',$v)===1;}
    private static function need(bool $ok,string $reason): void{if(!$ok)throw new InvalidArgumentException('roadmap_'.$reason);}
}
