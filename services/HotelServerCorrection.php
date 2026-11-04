<?php
declare(strict_types=1);
require_once __DIR__.'/HotelServerInputs.php';

/** Pure validation of a separately authorized correction; never a provider result.
 * The supplemental reviewer approves an exact answer-only transformation of a
 * pinned original result. Saved positive checks cover every unchanged record.
 */
final class HotelServerCorrection
{
    public static function prepare(array $c,array $package,array $plan,array $producer,array $reviewer,
        array $candidate,array $review,array $correction): array
    {
        $continuing=($correction['schema']??null)==='hotel-server-correction-v2';
        self::keys($correction,$continuing?['schema','proposal','review','continuation_admission']:['schema','proposal','review']);
        if(!in_array($correction['schema']??null,['hotel-server-correction-v1','hotel-server-correction-v2'],true)
            ||!is_array($correction['proposal'])||!is_array($correction['review']))
            throw new DomainException('correction_document_required');
        $p=$correction['proposal'];$supplement=$correction['review'];
        self::keys($p,['campaign_id','stage_index','bootstrap_sha256','inputs_sha256','baseline_sha256','plan_sha256',
            'producer_attempt','producer_request_sha256','producer_result_sha256','review_attempt','review_request_sha256','review_result_sha256',
            'scope','answer','context_sha256','criteria_sha256','source_url','source_sha256','author_principal','authorization_ref']);
        self::keys($supplement,['schema','principal','proposal_sha256','approved','evidence','review_reference']);
        foreach(['bootstrap_sha256','inputs_sha256','baseline_sha256','plan_sha256','producer_attempt','producer_request_sha256',
            'producer_result_sha256','review_attempt','review_request_sha256','review_result_sha256','context_sha256','criteria_sha256','source_sha256'] as $field)
            HotelServerInputs::sha($p[$field]);
        foreach(['campaign_id','bootstrap_sha256','inputs_sha256','baseline_sha256'] as $field)
            if($p[$field]!==$c[$field])throw new DomainException('correction_campaign_CAS');
        if(!is_int($p['stage_index'])||$p['stage_index']<0||$p['stage_index']>=count($plan['stages'])
            ||($continuing?$p['stage_index']===count($plan['stages'])-1:$p['stage_index']!==count($plan['stages'])-1)
            ||($plan['research_mode']??null)!=='assigned_retained_excerpts'
            ||$p['plan_sha256']!==hash('sha256',$c['plan_bytes']))throw new DomainException('correction_final_stage_required');
        $identity=$package;unset($identity['inputs_sha256'],$identity['bytes']);
        if(($package['inputs_sha256']??null)!==$c['inputs_sha256']||hash('sha256',HotelServerInputs::json($identity))!==$c['inputs_sha256'])
            throw new DomainException('correction_inputs_hash');
        if(($package['pins']['criteria']??null)!==$p['criteria_sha256']||!is_string($package['bytes']['criteria']??null)
            ||hash('sha256',$package['bytes']['criteria'])!==$p['criteria_sha256'])throw new DomainException('correction_criteria_hash');
        self::principal($p['author_principal']);self::text($p['authorization_ref'],2048);self::text($p['answer'],32768);
        self::principal($supplement['principal']);self::text($supplement['evidence'],4096);self::text($supplement['review_reference'],2048);
        if($supplement['schema']!=='hotel-server-correction-review-v1'||$supplement['approved']!==true
            ||HotelServerInputs::sha($supplement['proposal_sha256'])!==hash('sha256',HotelServerInputs::json($p))
            ||$supplement['principal']===$p['author_principal']||$supplement['principal']===$producer['principal'])
            throw new DomainException('correction_independent_review_required');
        if(!is_array($p['scope']))throw new DomainException('correction_scope_required');
        $target=HotelServerInputs::key($p['scope']);$stage=$p['stage_index'];$scopes=$plan['stages'][$stage];
        $expected=array_map([HotelServerInputs::class,'key'],$scopes);$contexts=[];
        foreach($scopes as $scope){$key=HotelServerInputs::key($scope);$contexts[]=$package['stage_contexts'][$key]??null;}
        $context=$package['stage_contexts'][$target]??null;
        if(!in_array($target,$expected,true)||!is_array($context)||hash('sha256',HotelServerInputs::json($context))!==$p['context_sha256']
            ||HotelServerInputs::json($context['scope']??null)!==HotelServerInputs::json($p['scope']))throw new DomainException('correction_context_hash');
        $requests=[];$results=[];
        foreach(['producer'=>$producer,'reviewer'=>$reviewer] as $role=>$attempt){
            $prefix=$role==='producer'?'producer':'review';
            if($attempt['attempt_id']!==$p[$prefix.'_attempt']||$attempt['request_sha256']!==$p[$prefix.'_request_sha256']
                ||$attempt['result_sha256']!==$p[$prefix.'_result_sha256']||$attempt['state']!=='succeeded'
                ||$attempt['campaign_id']!==$c['campaign_id']||(int)$attempt['stage_index']!==$stage||$attempt['role']!==$role
                ||$attempt['baseline_sha256']!==$c['baseline_sha256']||!is_string($attempt['result_bytes'])
                ||hash('sha256',$attempt['request_bytes'])!==$attempt['request_sha256']||hash('sha256',$attempt['result_bytes'])!==$attempt['result_sha256'])
                throw new DomainException('correction_attempt_CAS');
            $r=json_decode($attempt['request_bytes'],true,64,JSON_THROW_ON_ERROR);$result=json_decode($attempt['result_bytes'],true,64,JSON_THROW_ON_ERROR);
            if(!is_array($r)||!is_array($result)||array_key_exists('provider_outcome',$result))throw new DomainException('correction_known_result_required');
            foreach(['attempt_id','principal','baseline_sha256'] as $field)
                if(($r[$field]??null)!==$attempt[$field]||($result[$field]??null)!==$attempt[$field])throw new DomainException('correction_attempt_lineage');
            if(($r['campaign_id']??null)!==$c['campaign_id']||($r['stage_index']??null)!==$stage||($r['role']??null)!==$role
                ||($r['inputs_sha256']??null)!==$c['inputs_sha256']||($result['inputs_sha256']??null)!==$c['inputs_sha256']
                ||($r['criteria_sha256']??null)!==$p['criteria_sha256']||($r['model']??null)!==$plan['models'][$role]
                ||($r['research_mode']??null)!=='assigned_retained_excerpts'
                ||HotelServerInputs::json($r['scopes']??null)!==HotelServerInputs::json($scopes)
                ||HotelServerInputs::json($r['dependencies']??null)!==HotelServerInputs::json($contexts)
                ||HotelServerInputs::json($r['limits']??null)!==HotelServerInputs::json($plan['limits']))throw new DomainException('correction_attempt_lineage');
            if($continuing&&array_key_exists('continuation_admission_sha256',$r))throw new DomainException('continuation_single_admission_required');
            $requests[$role]=$r;$results[$role]=$result;
        }
        if($producer['principal']===$reviewer['principal']||$producer['attempt_id']===$reviewer['attempt_id']
            ||($requests['producer']['candidate']??null)!==null||($requests['producer']['candidate_sha256']??null)!==null
            ||$candidate['producer_attempt']!==$producer['attempt_id']||$candidate['campaign_id']!==$c['campaign_id']||(int)$candidate['stage_index']!==$stage
            ||hash('sha256',$candidate['candidate_bytes'])!==$candidate['candidate_sha256']
            ||HotelServerInputs::json($results['producer']['candidate']??null)!==$candidate['candidate_bytes']
            ||($requests['reviewer']['candidate_sha256']??null)!==$candidate['candidate_sha256']
            ||HotelServerInputs::json($requests['reviewer']['candidate']??null)!==$candidate['candidate_bytes']
            ||$review['campaign_id']!==$c['campaign_id']||(int)$review['stage_index']!==$stage||$review['review_attempt']!==$reviewer['attempt_id']
            ||(int)$review['approved']!==0||$review['candidate_sha256']!==$candidate['candidate_sha256']||$review['review_bytes']!==$reviewer['result_bytes']
            ||($results['reviewer']['approved']??null)!==false||($results['reviewer']['candidate_sha256']??null)!==$candidate['candidate_sha256']
            ||($results['reviewer']['criteria_sha256']??null)!==$p['criteria_sha256'])throw new DomainException('correction_rejected_candidate_lineage');
        self::text($results['reviewer']['evidence']??null,4096);
        $checks=$results['reviewer']['review_checks']??null;$seen=[];
        if(!is_array($checks)||!array_is_list($checks))throw new DomainException('correction_saved_scope_checks_required');
        foreach($checks as $check){
            if(!is_array($check))throw new DomainException('correction_saved_scope_checks_required');
            self::keys($check,['scope','approved','reason']);$key=HotelServerInputs::key($check['scope']);self::text($check['reason'],2048);
            if(isset($seen[$key])||!in_array($key,$expected,true)||$check['approved']!==($key!==$target))
                throw new DomainException('correction_single_rejected_scope_required');
            $seen[$key]=true;
        }
        if(count($seen)!==count($expected))throw new DomainException('correction_saved_scope_checks_required');
        $source=self::source($context,$p);$document=json_decode($candidate['candidate_bytes'],true,64,JSON_THROW_ON_ERROR);
        if(!is_array($document))throw new DomainException('correction_candidate_required');self::keys($document,['schema','records']);
        if($document['schema']!=='hotel-server-candidate-v1'||!is_array($document['records'])||!array_is_list($document['records']))
            throw new DomainException('correction_candidate_required');
        $seen=[];$originalRecord=null;
        foreach($document['records'] as &$record){
            if(!is_array($record))throw new DomainException('correction_candidate_required');self::keys($record,['scope','answer','evidence']);
            $key=HotelServerInputs::key($record['scope']);self::text($record['answer'],32768);
            if(isset($seen[$key])||!in_array($key,$expected,true)||!is_array($record['evidence'])||!array_is_list($record['evidence'])
                ||!$record['evidence']||count($record['evidence'])>8)throw new DomainException('correction_candidate_scope');
            $seen[$key]=true;
            foreach($record['evidence'] as $e){
                if(!is_array($e))throw new DomainException('correction_candidate_evidence');self::keys($e,['url','quote']);self::text($e['quote'],1024);
                if(!is_string($e['url'])||!filter_var($e['url'],FILTER_VALIDATE_URL)||!str_starts_with($e['url'],'https://'))
                    throw new DomainException('correction_candidate_evidence');
                if($key===$target&&($e['url']!==$p['source_url']||!str_contains($source['excerpt'],$e['quote'])))
                    throw new DomainException('correction_assigned_quote_required');
            }
            if($key===$target){$originalRecord=$record;if($record['answer']===$p['answer'])throw new DomainException('correction_answer_unchanged');$record['answer']=$p['answer'];}
        }
        unset($record);
        if(count($seen)!==count($expected)||$originalRecord===null)throw new DomainException('correction_candidate_scope');
        $bytes=HotelServerInputs::json($document);$correctionBytes=HotelServerInputs::json($correction);
        if(strlen($bytes)>1048576||strlen($correctionBytes)>65536)throw new DomainException('correction_document_bound');
        $continuation=$continuing?self::continuation($c,$package,$plan,$correction,$results):null;
        return ['candidate'=>$document,'candidate_bytes'=>$bytes,'candidate_sha256'=>hash('sha256',$bytes),
            'correction_bytes'=>$correctionBytes,'correction_sha256'=>hash('sha256',$correctionBytes),
            'correction_review_sha256'=>hash('sha256',HotelServerInputs::json($supplement)),'continuation_admission'=>$continuation];
    }

    /** One immutable admission alongside the correction. No clock gate on historical proof. */
    private static function continuation(array $c,array $package,array $plan,array $correction,array $results): array
    {
        $a=$correction['continuation_admission'];
        if(!is_array($a))throw new DomainException('continuation_admission_required');
        self::keys($a,['schema','proposal_sha256','budget_attestation','previous_policy','policy','profile','instruction_sha256']);
        if($a['schema']!=='hotel-server-continuation-admission-v1'
            ||HotelServerInputs::sha($a['proposal_sha256'])!==hash('sha256',HotelServerInputs::json($correction['proposal'])))
            throw new DomainException('continuation_proposal_pin');
        foreach(['budget_attestation','previous_policy','policy','profile','instruction_sha256'] as $key)
            if(!is_array($a[$key])||array_is_list($a[$key]))throw new DomainException('continuation_admission_required');
        $b=$a['budget_attestation'];$old=$a['previous_policy'];$policy=$a['policy'];$profile=$a['profile'];$instructions=$a['instruction_sha256'];
        self::keys($b,['schema','campaign_id','bootstrap_sha256','plan_sha256','correction_stage_index','consumed','remaining','limits','valid_from','valid_until','authorization_ref']);
        $stage=$correction['proposal']['stage_index'];$calls=2*($stage+1);$total=2*count($plan['stages']);$limits=$plan['limits'];
        if($b['schema']!=='hotel-server-continuation-budget-v1'||$b['campaign_id']!==$c['campaign_id']
            ||$b['bootstrap_sha256']!==$c['bootstrap_sha256']||$b['plan_sha256']!==hash('sha256',$c['plan_bytes'])
            ||$b['correction_stage_index']!==$stage||HotelServerInputs::json($b['limits'])!==HotelServerInputs::json($limits)
            ||$limits['max_calls']!==$total||$limits['max_tokens']!==$total*$limits['tokens_per_call']
            ||$limits['max_micros']!==$total*$limits['micros_per_call'])throw new DomainException('continuation_original_budget_required');
        foreach(['consumed'=>$calls,'remaining'=>$total-$calls] as $key=>$count){
            $expected=['calls'=>$count,'tokens'=>$count*$limits['tokens_per_call'],'micros'=>$count*$limits['micros_per_call']];
            if(HotelServerInputs::json($b[$key])!==HotelServerInputs::json($expected))throw new DomainException('continuation_reservations_unchanged');
        }
        self::text($b['authorization_ref'],2048);
        if(!is_int($b['valid_from'])||!is_int($b['valid_until'])||$b['valid_from']<$limits['deadline']
            ||$b['valid_until']<=$b['valid_from']||$b['valid_until']>2000000000)throw new DomainException('continuation_bounded_window_required');
        self::keys($old,['model','profile_sha256','principals','source_mode','input_token_ceiling','request_byte_ceiling','max_scopes',
            'reserved_micros_minimum','max_source_age_seconds','valid_until','budget_attestation_sha256']);
        if($old['source_mode']!=='assigned_retained_excerpts'||$old['valid_until']!==$limits['deadline']
            ||!is_int($old['max_source_age_seconds'])||$old['max_source_age_seconds']<1||$old['max_source_age_seconds']>31536000
            ||!is_array($old['principals'])||HotelServerInputs::sha($old['profile_sha256'])!==hash('sha256',HotelServerInputs::json($profile)))
            throw new DomainException('continuation_previous_policy_required');
        HotelServerInputs::sha($old['budget_attestation_sha256']);self::keys($old['principals'],['producer','reviewer']);
        self::keys($instructions,['producer','reviewer']);foreach($instructions as $hash)HotelServerInputs::sha($hash);
        $expected=$old;$expected['valid_until']=$b['valid_until'];$expected['budget_attestation_sha256']=hash('sha256',HotelServerInputs::json($b));
        if(HotelServerInputs::json($policy)!==HotelServerInputs::json($expected))throw new DomainException('continuation_policy_changed');
        $revision=static fn(array $p):string=>hash('sha256',HotelServerInputs::json([$p,$profile,$instructions['producer'],$instructions['reviewer']]));
        $previousRevision=$revision($old);$nextRevision=$revision($policy);$principals=[];
        foreach(['producer','reviewer'] as $role){
            self::principal($old['principals'][$role]);$r=$results[$role];
            if($old['model']!==$plan['models'][$role]||($profile['model']??null)!==$old['model']
                ||($r['profile_sha256']??null)!==$old['profile_sha256']||($r['provider_policy_sha256']??null)!==$previousRevision
                ||($r['budget_attestation_sha256']??null)!==$old['budget_attestation_sha256']
                ||($r['principal']??null)!==$old['principals'][$role].':'.$previousRevision)
                throw new DomainException('continuation_original_provider_lineage');
            $principals[$role]=$old['principals'][$role].':'.$nextRevision;self::principal($principals[$role]);
        }
        if($principals['producer']===$principals['reviewer'])throw new DomainException('independent_reviewer_required');
        // The unchanged source-age policy bounds the renewed window as well as each provider preflight.
        foreach(array_slice($plan['stages'],$stage+1) as $scopes)foreach($scopes as $scope){
            $context=$package['stage_contexts'][HotelServerInputs::key($scope)]??null;
            if(!is_array($context))throw new DomainException('continuation_assigned_sources_required');
            foreach(self::sources($context) as $source)
                if($source['captured_at']>$b['valid_from']||$b['valid_until']>$source['captured_at']+$old['max_source_age_seconds'])
                    throw new DomainException('continuation_source_window_exceeded');
        }
        return ['sha256'=>hash('sha256',HotelServerInputs::json($a)),'first_stage'=>$stage+1,'last_stage'=>count($plan['stages'])-1,
            'consumed'=>$b['consumed'],'valid_from'=>$b['valid_from'],'valid_until'=>$b['valid_until'],'principals'=>$principals];
    }

    /** Canonical receipt keeps the negative original and supplemental approval distinct. */
    public static function receipt(array $c,int $stage,array $prepared,array $candidate,array $producer,array $reviewer,
        string $baseline,?array $admission): array
    {
        $receipt=['schema'=>'hotel-server-correction-acceptance-v1','campaign_id'=>$c['campaign_id'],'stage_index'=>$stage,
            'candidate_sha256'=>$prepared['candidate_sha256'],'original_candidate_sha256'=>$candidate['candidate_sha256'],
            'producer_attempt'=>$producer['attempt_id'],'review_attempt'=>$reviewer['attempt_id'],
            'correction_sha256'=>$prepared['correction_sha256'],'correction_review_sha256'=>$prepared['correction_review_sha256'],
            'previous_baseline_sha256'=>$c['baseline_sha256'],'baseline_sha256'=>$baseline,'inputs_sha256'=>$c['inputs_sha256'],
            'new_acceptance_credit'=>1,'historical_accepted'=>19];
        if($admission!==null)$receipt['retained_admission']=$admission;
        return $receipt;
    }

    private static function source(array $context,array $p): array
    {
        if(!is_string($p['source_url'])||!filter_var($p['source_url'],FILTER_VALIDATE_URL)||!str_starts_with($p['source_url'],'https://'))
            throw new DomainException('correction_source_url');
        foreach(self::sources($context) as $source)
            if($source['url']===$p['source_url']&&$source['sha256']===$p['source_sha256'])return $source;
        throw new DomainException('correction_source_hash');
    }

    private static function sources(array $context): array
    {
        $raw=$context['dependencies']['archives']??null;
        if(!is_string($raw)||strlen($raw)>8192)throw new DomainException('correction_assigned_source_required');
        $slice=json_decode($raw,true,32,JSON_THROW_ON_ERROR);
        if(!is_array($slice))throw new DomainException('correction_assigned_source_required');self::keys($slice,['schema','sources']);
        if($slice['schema']!=='hotel-assigned-source-slice-v1'||!is_array($slice['sources'])||!array_is_list($slice['sources'])
            ||!$slice['sources']||count($slice['sources'])>8)throw new DomainException('correction_assigned_source_required');
        $seen=[];
        foreach($slice['sources'] as $source){
            if(!is_array($source))throw new DomainException('correction_assigned_source_required');self::keys($source,['url','excerpt','sha256','captured_at','kind']);
            if(!is_string($source['url'])||isset($seen[$source['url']])||!is_string($source['excerpt'])||$source['excerpt']===''||strlen($source['excerpt'])>4096
                ||$source['kind']!=='retained_excerpt'||!is_int($source['captured_at'])||$source['captured_at']<1
                ||HotelServerInputs::sha($source['sha256'])!==hash('sha256',$source['excerpt']))throw new DomainException('correction_assigned_source_required');
            $seen[$source['url']]=true;
        }
        return $slice['sources'];
    }

    private static function keys(array $value,array $expected): void
    {
        $actual=array_keys($value);sort($actual);sort($expected);
        if($actual!==$expected)throw new DomainException('correction_strict_document_required');
    }

    private static function text(mixed $text,int $max): void
    {
        if(!is_string($text)||trim($text)===''||strlen($text)>$max||preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/',$text))
            throw new DomainException('correction_bounded_text_required');
    }

    private static function principal(mixed $principal): void
    {
        if(!is_string($principal)||!preg_match('/\A[A-Za-z0-9][A-Za-z0-9_.:\/@-]{0,255}\z/D',$principal))
            throw new DomainException('correction_canonical_principal_required');
    }
}
