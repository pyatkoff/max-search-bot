<?php
declare(strict_types=1);
require_once __DIR__.'/ManagerPreparationOAuth.php';
require_once __DIR__.'/DevelopmentWorkQueue.php';

/** Separate development metadata capability; never a source/publisher lease. */
final class DevelopmentWorkMcpAdapter
{
    public const NAMES=['development_work_create','development_work_append','development_work_status','development_work_claim','development_work_started','development_work_complete','development_work_resolve_existing_run','development_work_retire_unclaimed'];
    public function __construct(private PDO $pdo,private $store=null,private $clock=null) {}
    public static function tools(): array
    {
        $id=['type'=>'string','pattern'=>'^[A-Za-z0-9_-]{1,80}$'];
        $run=['type'=>'string','pattern'=>'^[A-Za-z0-9_-]{1,128}$'];$sha=['type'=>'string','pattern'=>'^[a-f0-9]{64}$'];
        $nullableSha=['type'=>['string','null'],'pattern'=>$sha['pattern']];$nullableRun=['type'=>['string','null'],'pattern'=>$run['pattern']];
        $task=['type'=>'object','properties'=>['task_id'=>$id,'owner_task_id'=>$run,'reviewer_task_id'=>$run,'input_sha256'=>$sha,'acceptance_contract_sha256'=>$sha,
            'deps'=>['type'=>'array','items'=>$id,'minItems'=>0,'maxItems'=>20,'uniqueItems'=>true],'priority'=>['type'=>'integer','minimum'=>0,'maximum'=>100]],
            'required'=>['task_id','owner_task_id','reviewer_task_id','input_sha256','acceptance_contract_sha256','deps','priority'],'additionalProperties'=>false];
        $tasks=['type'=>'array','items'=>$task,'minItems'=>1,'maxItems'=>20];
        $claim=['queue_id'=>$id,'task_id'=>$id,'spec_sha256'=>$sha,'claim_key'=>$sha];
        $receipt=['type'=>'object','properties'=>['run_id'=>$nullableRun,'outcome'=>['type'=>'string','enum'=>['accepted_readback','terminal_blocked','unknown']],
            'result_sha256'=>$nullableSha,'readback_sha256'=>$nullableSha,'acceptance_sha256'=>$nullableSha,'reviewer_run_id'=>$nullableRun,'owned_claims_released'=>['type'=>'boolean']],
            'required'=>['run_id','outcome','result_sha256','readback_sha256','acceptance_sha256','reviewer_run_id','owned_claims_released'],'additionalProperties'=>false];
        $resolved=$receipt;
        $resolved['properties']['outcome']=['type'=>'string','enum'=>['accepted_readback','terminal_blocked']];$resolved['properties']['run_id']=$run;
        foreach (['result_sha256','readback_sha256'] as $field) $resolved['properties'][$field]=$sha;
        $resolved['properties']['owned_claims_released']=['type'=>'boolean','const'=>true];
        $definitions=[
            ['development_work_create','Register one explicitly authorized immutable development plan on the shared DEVELOPMENT lane. Tasks name existing distinct owners/reviewers and immutable input/acceptance hashes; dependencies must already exist or occur earlier in this batch. Metadata only: no source edits, worker dispatch, merge or deploy.',
                ['queue_id'=>$id,'plan_sha256'=>$sha,'tasks'=>$tasks],['queue_id','plan_sha256','tasks'],false],
            ['development_work_append','Append 1–20 new immutable tasks with an expected-plan CAS. An exact recorded batch is idempotent and never resets a task, acknowledgement, claim or run. Current admitted work is preserved. No worker is started.',
                ['queue_id'=>$id,'expected_plan_sha256'=>$sha,'plan_sha256'=>$sha,'tasks'=>$tasks],['queue_id','expected_plan_sha256','plan_sha256','tasks'],false],
            ['development_work_status','Read one owned development metadata queue and its current task. Hashes and counters are authenticated attestations, not independent artifact, CI or production proof. No claim key, source content or publisher permission is returned.',
                ['queue_id'=>$id],['queue_id'],true],
            ['development_work_claim','Claim the exact current task using expected_pending_event_id from the actual fresh trusted signed development_work.pending delivery. Never manufacture that evidence from status. Check the existing owner, private UNKNOWN, active jobs/files and source/publisher gates first. An already-claimed result never authorizes another dispatch.',
                $claim+['expected_pending_event_id'=>['type'=>'string','pattern'=>'^dev_evt_[a-f0-9]{64}$']],[...array_keys($claim),'expected_pending_event_id'],false],
            ['development_work_started','Attest the actual original worker run for this exact claim once. Does not start a worker or replace source/publisher ownership. An uncertain or absent run must not be guessed or restarted.',
                $claim+['run_id'=>$run],[...array_keys($claim),'run_id'],false],
            ['development_work_complete','Record one exact independently verified terminal result. Accepted results require the original run, result/readback/acceptance hashes, a distinct reviewer run and released owned claims. Terminal blocked does not satisfy dependencies. UNKNOWN holds WIP indefinitely; hashes alone never prove acceptance, CI or installation.',
                $claim+['receipt'=>$receipt],[...array_keys($claim),'receipt'],false],
            ['development_work_resolve_existing_run','Resolve a held UNKNOWN only against its immutable original receipt hash, retained claim and already-known original run. Preserve any previously known result hash. Independently verify either accepted_readback with a distinct reviewer or terminal_blocked with null acceptance/reviewer fields, and released owned claims. Blocked never satisfies dependencies. Never dispatch, retry or invent a run.',
                $claim+['unknown_receipt_sha256'=>$sha,'expected_run_id'=>$run,'receipt'=>$resolved],[...array_keys($claim),'unknown_receipt_sha256','expected_run_id','receipt'],false],
            ['development_work_retire_unclaimed','Retire only exact unclaimed READY metadata after an independent saved decision/readback. Pin the current plan and pending delivery identity as CAS values, not dispatch evidence. Verify the existing owner/route and no private UNKNOWN or unresolved claim/dispatch intent first. No age, worker run, retry or publication is authorized. The task becomes blocked and never satisfies dependencies; only a later ordinary pending may admit another independent task.',
                ['queue_id'=>$id,'task_id'=>$id,'spec_sha256'=>$sha,'expected_plan_sha256'=>$sha,'expected_pending_event_id'=>['type'=>'string','pattern'=>'^dev_evt_[a-f0-9]{64}$'],
                    'decision'=>['type'=>'object','properties'=>['decision_sha256'=>$sha,'readback_sha256'=>$sha,'reviewer_run_id'=>$run],
                        'required'=>['decision_sha256','readback_sha256','reviewer_run_id'],'additionalProperties'=>false]],
                ['queue_id','task_id','spec_sha256','expected_plan_sha256','expected_pending_event_id','decision'],false],
        ];
        $out=[];$security=[['type'=>'oauth2','scopes'=>[ManagerPreparationOAuth::DEVELOPMENT_SCOPE]]];
        foreach ($definitions as [$name,$description,$properties,$required,$readOnly]) $out[]=['name'=>$name,'description'=>$description,
            'inputSchema'=>['type'=>'object','properties'=>$properties,'required'=>$required,'additionalProperties'=>false],
            'annotations'=>['readOnlyHint'=>$readOnly,'destructiveHint'=>false,'idempotentHint'=>true,'openWorldHint'=>false],
            'securitySchemes'=>$security,'_meta'=>['securitySchemes'=>$security]];
        return $out;
    }
    public function call(string $name,array $args,int $admin,string $connection): array
    {
        if (!in_array($name,self::NAMES,true)||$admin<1||preg_match('/\A[a-f0-9]{32}\z/D',$connection)!==1) throw new InvalidArgumentException('invalid_development_work_call');
        if (strlen(json_encode($args,JSON_THROW_ON_ERROR))>32768) throw new InvalidArgumentException('development_work_arguments_too_large');
        $definition=null;
        foreach (self::tools() as $tool) if ($tool['name']===$name) {$definition=$tool;break;}
        if ($definition===null) throw new InvalidArgumentException('invalid_development_work_call');
        $remaining=2048;self::validateInput($args,$definition['inputSchema'],0,$remaining);
        $operation=substr($name,strlen('development_work_'));
        if ($operation==='status') {
            if (count($args)!==1||!is_string($args['queue_id']??null)) throw new InvalidArgumentException('invalid_development_work_fields');
            $arguments=[$admin,$connection,$args['queue_id']];
        } else $arguments=[$admin,$connection,$args];
        try {
            if (is_callable($this->store)) $result=($this->store)($operation,$arguments);
            else {
                if ($this->store===null) $this->store=new DevelopmentWorkQueue($this->pdo,$this->clock);
                $result=$this->store->$operation(...$arguments);
            }
        } catch (DevelopmentWorkQueueConflict $error) {return ['ok'=>false,'reason'=>$error->reason];}
        if (!is_array($result)) throw new RuntimeException('invalid_development_work_result');
        if ($operation==='status') {
            require_once __DIR__.'/DevelopmentWorkHealth.php';
            $result['cron_health']=DevelopmentWorkHealth::snapshot($this->clock!==null?(int)($this->clock)():null);
            require_once __DIR__.'/DevelopmentWorkEventService.php';
            $result['subscription_setup']=DevelopmentWorkEventService::unavailableSubscriptionSetup();
            if ($this->store instanceof DevelopmentWorkQueue) {
                try {
                    $reader=new DevelopmentWorkEventService($this->pdo,null,$this->clock,null,$this->store);
                    $result['subscription_setup']=$reader->subscriptionSetup($admin,$connection,$args['queue_id']);
                } catch (RuntimeException $unavailable) {
                    $result['subscription_setup']=DevelopmentWorkEventService::unavailableSubscriptionSetup();
                }
            }
        }
        return $result;
    }
    private static function validateInput(mixed $value,array $schema,int $depth,int &$remaining): void
    {
        if ($depth>8||--$remaining<0) throw new InvalidArgumentException('invalid_development_work_fields');
        $types=is_array($schema['type']??null)?$schema['type']:[$schema['type']??null];$matched=false;
        foreach ($types as $type) {
            $matched=$matched||match ($type) {
                'object'=>is_array($value)&&($value===[]||!array_is_list($value)),
                'array'=>is_array($value)&&array_is_list($value),'string'=>is_string($value),'integer'=>is_int($value),
                'boolean'=>is_bool($value),'null'=>$value===null,default=>false,
            };
        }
        if (!$matched||(isset($schema['enum'])&&!in_array($value,$schema['enum'],true))
            ||(array_key_exists('const',$schema)&&$value!==$schema['const'])) throw new InvalidArgumentException('invalid_development_work_fields');
        if (is_string($value)) {
            if ((isset($schema['minLength'])&&strlen($value)<$schema['minLength'])||(isset($schema['maxLength'])&&strlen($value)>$schema['maxLength'])
                ||(isset($schema['pattern'])&&preg_match('~'.str_replace('~','\~',$schema['pattern']).'~D',$value)!==1)) throw new InvalidArgumentException('invalid_development_work_fields');
        }
        if (is_int($value)&&((isset($schema['minimum'])&&$value<$schema['minimum'])||(isset($schema['maximum'])&&$value>$schema['maximum']))) throw new InvalidArgumentException('invalid_development_work_fields');
        if (in_array('object',$types,true)&&is_array($value)&&($value===[]||!array_is_list($value))) {
            $properties=$schema['properties']??[];
            if (($schema['additionalProperties']??true)===false&&array_diff(array_keys($value),array_keys($properties))) throw new InvalidArgumentException('invalid_development_work_fields');
            foreach ($schema['required']??[] as $field) if (!array_key_exists($field,$value)) throw new InvalidArgumentException('invalid_development_work_fields');
            foreach ($value as $field=>$child) if (isset($properties[$field])) self::validateInput($child,$properties[$field],$depth+1,$remaining);
        } elseif (in_array('array',$types,true)&&is_array($value)&&array_is_list($value)) {
            if ((isset($schema['minItems'])&&count($value)<$schema['minItems'])||(isset($schema['maxItems'])&&count($value)>$schema['maxItems'])) throw new InvalidArgumentException('invalid_development_work_fields');
            $seen=[];
            foreach ($value as $child) {
                if (($schema['uniqueItems']??false)===true) {
                    $key=json_encode($child,JSON_THROW_ON_ERROR);
                    if (isset($seen[$key])) throw new InvalidArgumentException('invalid_development_work_fields');$seen[$key]=true;
                }
                if (isset($schema['items'])) self::validateInput($child,$schema['items'],$depth+1,$remaining);
            }
        }
    }
}
