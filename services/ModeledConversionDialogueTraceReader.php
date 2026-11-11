<?php
declare(strict_types=1);

require_once __DIR__.'/ModeledConversionValueContract.php';

/** Private saved scalars only, inside the caller's authorized READ ONLY snapshot. */
final class ModeledConversionDialogueTraceReader
{
    public const MAX_ROWS=32;
    public const MAX_BYTES=16384;
    public function __construct(private $query){}

    public function read(array $wrapper,array $subject,string $profileId,int $cutoff):array
    {
        if(($wrapper['access_status']??null)!=='allowed'||!self::sameSubject($wrapper['subject']??null,$subject)
            ||!self::decimal($profileId)||($subject['project_key']??null)!=='anytour'
            ||!in_array($subject['platform']??null,['max','telegram'],true)
            ||!self::decimal($subject['customer_key']??null)||!self::decimal($subject['conversation_id']??null))
            return['available'=>false,'reason'=>'profile_binding_unavailable'];
        $out=['available'=>true,'version'=>'saved_dialogue_value_trace_v1','profile_id'=>$profileId,
            'basis'=>'frozen_profile_and_saved_ledger_not_source_replay_or_new_prediction',
            'as_of'=>gmdate('Y-m-d\TH:i:s\Z',$cutoff),'currency'=>'RUB','complete'=>true,
            'source_assurance'=>'original_profile_admission_not_independently_revalidated_here',
            'frozen_profile'=>['prediction_at'=>$wrapper['profile']['prediction_at']??null,
                'input_cutoff_at'=>$wrapper['semantic_prediction']['input_cutoff_at']??null,
                'journal_cursor'=>self::id($wrapper['journal_cursor']??null),
                'source_fence_journal_id'=>self::id($wrapper['source_fence_journal_id']??null),
                'fields'=>[],'party'=>[]],
            'wizard_applications'=>['available'=>false,'receipts'=>[],'complete'=>false],
            'collector'=>['available'=>false,'reason'=>'settings_not_configured'],
            'ledger'=>['rows'=>[],'complete'=>true,'max_rows'=>self::MAX_ROWS,
                'consistency'=>'current_read_snapshot_not_frozen_with_profile','totals_basis'=>'returned_conversation_rows_only','value_rub'=>0,'exact_profile_rows'=>0],
            'visit_linkage'=>['status'=>'not_checked','reason'=>'no_live_provider_or_visit_cache_read'],
            'candidate_evaluation'=>'not_recomputed','database_writes'=>0,'model_calls'=>0,'conversion_uploads'=>0];
        foreach(['dates','duration','departure_reference','destination_reference','meal_reference','hotel_class_reference','budget']as$key)
            $out['frozen_profile']['fields'][$key]=self::facts($wrapper['profile']['fields'][$key]??null);
        foreach(['adults','children','child_ages']as$key)
            $out['frozen_profile']['party'][$key]=self::facts($wrapper['profile']['party'][$key]??null);
        $summary=[
            'customer_turns'=>$wrapper['contextual_signals']['engagement']['customer_turns']??null,
            'manager_turns'=>$wrapper['contextual_signals']['engagement']['manager_turns']??null,
            'context_barriers'=>$wrapper['contextual_signals']['coverage']['context_barriers']??null,
            'wizard_applications'=>$wrapper['coverage']['applied_wizard_fields']??null,
            'action_or_unknown_type_rows'=>$wrapper['coverage']['errors']['action_or_unknown_message_type']??null,
        ];
        $out['recorded_input_summary']=self::counts($summary);
        $bindings=$wrapper['wizard_applications']??null;
        if(is_array($bindings)&&($bindings['version']??null)==='admitted_wizard_applications_v1'&&is_array($bindings['receipts']??null)){
            $out['wizard_applications']=['available'=>true,'recorded_count'=>count($bindings['receipts']),
                'receipts'=>[],'complete'=>count($bindings['receipts'])<=16];
            foreach(array_slice($bindings['receipts'],0,16)as$b)if(is_array($b)&&self::ref($b['receipt_ref']??null)&&self::ref($b['input_ref']??null))
                $out['wizard_applications']['receipts'][]=['receipt_ref'=>$b['receipt_ref'],'input_ref'=>$b['input_ref']];
            if(count($out['wizard_applications']['receipts'])!==min(16,count($bindings['receipts'])))
                $out['wizard_applications']['complete']=false;
        }
        $settings=($this->query)('SELECT mode,goal_id,goal_intent,contract_version,activated_at_unix,profile_cursor'
            .' FROM modeled_conversion_value_export_settings WHERE project_key=? AND counter_id=? LIMIT 1',
            ['anytour','98615635'])->fetch(PDO::FETCH_ASSOC);
        if($settings){
            $cursor=self::id($settings['profile_cursor']??null,true);
            $out['collector']=['available'=>true,'mode'=>in_array($settings['mode'],['off','send'],true)?$settings['mode']:'unknown',
                'goal_matches'=>($settings['goal_id']??null)==='678187097',
                'intent_verified'=>($settings['goal_intent']??null)==='verified',
                'contract_matches'=>($settings['contract_version']??null)===ModeledConversionValueContract::VERSION,
                'activated_at'=>self::instant($settings['activated_at_unix']??null,$cutoff),
                'profile_cursor'=>$cursor,'cursor_at_or_after_selected_profile'=>$cursor===null?null:!self::greater($profileId,$cursor),
                'current_processor_namespace'=>'not_checked',
                'meaning'=>'cursor_position_not_proof_of_candidate_eligibility_or_export'];
        }
        $rows=($this->query)('SELECT o.id,o.platform,o.conversation_id,o.value_profile_id,o.value_rub,o.value_stage,o.state,o.upload_status,'
            ."CASE WHEN o.last_error IN ('identity_not_found','identity_ambiguous','metrika_visit_window','metrika_visit_unverified','wire_key_collision','scope_unavailable','api_rejected','api_row_rejected','upload_outcome_unknown','event_expired','transport_not_sent','rate_limited') THEN o.last_error ELSE NULL END AS last_error,"
            .'o.event_unix,o.claimed_at_unix,o.value_api_accepted_at_unix,o.value_processed_at_unix,'
            ."CASE WHEN o.upload_id IS NOT NULL AND o.upload_id<>'' THEN 1 ELSE 0 END AS receipt_present,"
            ."CASE WHEN o.client_id IS NOT NULL AND o.client_id<>'' THEN 1 ELSE 0 END AS identity_present,"
            .'a.action AS price_action,a.original_value_rub,a.applied_value_rub,a.prior_reserved_rub,p.id AS bound_profile_id'
            .' FROM max_channel_conversion_outbox o LEFT JOIN modeled_conversion_price_audit a ON a.outbox_id=o.id'
            .' LEFT JOIN modeled_conversion_profile_versions p ON p.id=o.value_profile_id AND p.project_key=o.project_key'
            .' AND p.platform=o.platform AND p.conversation_id=o.conversation_id AND p.customer_key=?'
            .' WHERE o.project_key=? AND o.counter_id=? AND o.platform=? AND o.conversation_id=?'
            .' AND o.transport_target=? AND o.value_contract=? ORDER BY o.id DESC LIMIT '.(self::MAX_ROWS+1),
            [$subject['customer_key'],'anytour','98615635',$subject['platform'],$subject['conversation_id'],ModeledConversionValueContract::TARGET,ModeledConversionValueContract::VERSION])
            ->fetchAll(PDO::FETCH_ASSOC);
        $out['ledger']['complete']=count($rows)<=self::MAX_ROWS;
        $cents=0;
        foreach(array_slice($rows,0,self::MAX_ROWS)as$r){
            $value=self::cents($r['value_rub']??null);
            if(!self::decimal((string)($r['id']??''))||$value===null){$out['ledger']['complete']=false;continue;}
            $bound=self::id($r['bound_profile_id']??null);
            $exact=$bound===$profileId&&(string)($r['value_profile_id']??'')===$profileId;
            $item=['event_id'=>(string)$r['id'],'value_profile_id'=>self::id($r['value_profile_id']??null),
                'exact_selected_profile'=>$exact,'profile_subject_verified'=>$bound!==null,'value_rub'=>$value/100,
                'stage'=>self::label($r['value_stage']??null),'state'=>self::label($r['state']??null),
                'upload_status'=>self::label($r['upload_status']??null),'reason'=>self::label($r['last_error']??null),
                'api_receipt_present'=>(int)$r['receipt_present']===1,'identity_resolved'=>(int)$r['identity_present']===1,
                'signal_at'=>self::instant($r['event_unix']??null,$cutoff),'upload_claim_at'=>self::instant($r['claimed_at_unix']??null,$cutoff),
                'api_accepted_observed_at'=>self::instant($r['value_api_accepted_at_unix']??null,$cutoff),
                'processed_observed_at'=>self::instant($r['value_processed_at_unix']??null,$cutoff),
                'price_audit'=>['action'=>self::label($r['price_action']??null)]];
            foreach(['original_value_rub','applied_value_rub','prior_reserved_rub']as$key){
                $n=self::cents($r[$key]??null);$item['price_audit'][$key]=$n===null?null:$n/100;
            }
            $out['ledger']['rows'][]=$item;$cents+=$value;if($exact)$out['ledger']['exact_profile_rows']++;
        }
        $out['ledger']['value_rub']=$cents/100;
        $out['complete']=$out['ledger']['complete']&&($out['wizard_applications']['complete']??false);
        while(strlen(self::json($out))>self::MAX_BYTES&&$out['ledger']['rows']){
            array_pop($out['ledger']['rows']);$out['ledger']['complete']=false;$out['complete']=false;
            $out['ledger']['rows_omitted_due_to_response_budget']=true;
        }
        $out['ledger']['value_rub']=array_sum(array_map(static fn(array $r):int=>self::cents($r['value_rub'])??0,$out['ledger']['rows']))/100;
        $out['ledger']['exact_profile_rows']=count(array_filter($out['ledger']['rows'],static fn(array $r):bool=>$r['exact_selected_profile']));
        if(strlen(self::json($out))>self::MAX_BYTES){
            $out['frozen_profile']['fields']=[];$out['frozen_profile']['party']=[];
            $out['wizard_applications']['receipts']=[];$out['complete']=false;$out['response_omissions']=true;
        }
        return$out;
    }

    private static function facts(mixed $capsule):array
    {
        if(!is_array($capsule))return['available'=>false];
        $out=['available'=>true,'status'=>self::label($capsule['status']??null),'facts'=>[]];
        $facts=$capsule['observations']??$capsule['facts']??(array_key_exists('status',$capsule)?[$capsule]:[]);
        // Some profile versions carry the fact list directly.
        if(array_is_list($capsule))$facts=$capsule;
        if(!is_array($facts))$facts=[];
        $out['recorded_count']=count($facts);$out['complete']=count($facts)<=4;
        foreach(array_slice($facts,0,4)as$f){
            if(!is_array($f)){$out['complete']=false;continue;}
            $refs=[];
            foreach(array_slice(is_array($f['references']??null)?$f['references']:[],0,4)as$r)
                if(is_array($r)&&self::ref($r['id']??null))$refs[]=['id'=>$r['id'],'source'=>self::label($r['source']??null)];
            $out['facts'][]=['value'=>self::value($f['value']??null),'basis'=>self::label($f['basis']??null),'references'=>$refs];
        }
        return$out;
    }
    private static function value(mixed $v):mixed
    {
        if(is_int($v)||is_float($v)||is_bool($v)||$v===null)return$v;
        if(is_string($v))return preg_match('/\A(?:[0-9.-]{1,40}|RUB|nights|departure|unresolved)\z/D',$v)===1?$v:null;
        if(!is_array($v)||count($v)>12)return null;
        $out=[];foreach($v as$k=>$x){
            if(is_int($k)||in_array($k,['minimum','maximum','unit','start_day','start_month','end_day','end_month','year','window_kind','id','amount','low','high','currency','label_status'],true))
                $out[$k]=self::value(is_array($x)?null:$x);
        }return$out;
    }
    private static function counts(mixed $s):array
    {
        if(!is_array($s))return['available'=>false];
        $out=['available'=>true];
        foreach(['customer_turns','manager_turns','context_barriers','wizard_applications','action_or_unknown_type_rows']as$k)
            if(is_int($s[$k]??null)&&$s[$k]>=0)$out[$k]=$s[$k];
        return$out;
    }
    private static function cents(mixed $v):?int
    {
        if(!is_int($v)&&!is_float($v)&&!is_string($v))return null;
        $s=(string)$v;if(preg_match('/\A([0-9]{1,9})(?:\.([0-9]{1,2}))?\z/D',$s,$m)!==1)return null;
        return(int)$m[1]*100+(int)str_pad($m[2]??'',2,'0');
    }
    private static function label(mixed $v):?string
    {return is_string($v)&&preg_match('/\A[A-Za-z][A-Za-z0-9_]{0,79}\z/D',$v)===1?$v:null;}
    private static function ref(mixed $v):bool
    {return is_string($v)&&preg_match('/\Ajournal:[1-9][0-9]{0,19}\z/D',$v)===1;}
    private static function sameSubject(mixed $a,array $b):bool
    {if(!is_array($a)||count($a)!==count($b))return false;foreach($b as$k=>$v)if(($a[$k]??null)!==$v)return false;return true;}
    private static function decimal(mixed $v):bool
    {return is_string($v)&&preg_match('/\A[1-9][0-9]{0,19}\z/D',$v)===1;}
    private static function id(mixed $v,bool $zero=false):?string
    {$s=is_int($v)?(string)$v:$v;return is_string($s)&&preg_match($zero?'/\A(?:0|[1-9][0-9]{0,19})\z/D':'/\A[1-9][0-9]{0,19}\z/D',$s)===1?$s:null;}
    private static function greater(string $a,string $b):bool
    {return strlen($a)!==strlen($b)?strlen($a)>strlen($b):strcmp($a,$b)>0;}
    private static function instant(mixed $v,int $cutoff):?string
    {$id=self::id($v);return$id!==null&&(int)$id>=946684800&&(int)$id<=$cutoff?gmdate('Y-m-d\TH:i:s\Z',(int)$id):null;}
    private static function json(array $v):string
    {return json_encode($v,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);}
}
