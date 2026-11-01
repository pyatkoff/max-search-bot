<?php
declare(strict_types=1);
require_once __DIR__.'/ManagerPreparationOAuth.php';
require_once __DIR__.'/HotelWorkQueueFactory.php';

/** Hotel metadata tools only. The canonical MCP owner enforces the hotel grant. */
final class HotelWorkMcpAdapter
{
    public const COLLECTION_PACKET_BYTES = 24576; // Fits the existing 192 KiB HTTP envelope even escaped.
    public const NAMES = ['hotel_work_create','hotel_work_status','hotel_work_claim',
        'hotel_work_started','hotel_work_complete','hotel_work_release','hotel_work_skip_research',
        'hotel_work_handoff_preflight','hotel_work_handoff_connection','hotel_work_resolve_existing_run',
        'hotel_work_delivery_repair_preflight','hotel_work_repair_delivery',
        'hotel_work_execution_attempt_preflight','hotel_work_prepare_execution_attempt',
        'hotel_work_enter_execution_attempt','hotel_work_bind_execution_attempt',
        'hotel_work_collection_context','hotel_work_collection_candidate','hotel_work_working_read','hotel_work_review_context','hotel_work_review_submit'];

    public function __construct(private PDO $pdo, private $store = null, private $clock = null,
        private ?HotelWorkEventService $events = null, private string $oauthProfile = ManagerPreparationOAuth::PROFILE_LEGACY,
        private ?bool $journalProtection = null)
    {
        if (!in_array($oauthProfile,[ManagerPreparationOAuth::PROFILE_LEGACY,ManagerPreparationOAuth::PROFILE_HOTELS],true)) throw new InvalidArgumentException('invalid_hotel_oauth_profile');
    }

    public static function tools(): array
    {
        $id=['type'=>'string','pattern'=>'^[A-Za-z0-9_-]{1,80}$'];
        $sha=['type'=>'string','pattern'=>'^[a-f0-9]{64}$'];
        $nullableSha=['type'=>['string','null'],'pattern'=>'^[a-f0-9]{64}$'];
        $run=['type'=>'string','pattern'=>'^[A-Za-z0-9_-]{1,128}$'];
        $journalId=['type'=>'string','pattern'=>'^[A-Za-z0-9_.:-]{1,128}$'];
        $collection=['queue_id'=>$id,'stage_id'=>$sha,'generation'=>['type'=>'integer','minimum'=>1],
            'operation_id'=>$sha,'worker_capability'=>$sha,'instance_id'=>$journalId];
        $claim=['queue_id'=>$id,'stage_id'=>$sha,'claim_key'=>$sha];
        $repairIdentity=['queue_id'=>$id,'stage_id'=>$sha,'baseline_sha256'=>$sha,
            'expected_caller_connection_id'=>['type'=>'string','pattern'=>'^[a-f0-9]{32}$']];
        $repairPins=['expected_pending_event_id'=>['type'=>'string','pattern'=>'^hotel_evt_[a-f0-9]{64}$'],
            'expected_resume_sha256'=>$sha,'expected_recovery_sha256'=>$sha,
            'expected_ack_sha256'=>$sha,'expected_state_sha256'=>$sha];
        $repair=$repairIdentity+$repairPins+['owner_request_sha256'=>$sha,'consumer_prompt_sha256'=>$sha];
        $attemptIdentity=$repairIdentity+['expected_current_run_id'=>$run];
        $attemptPreparation=$attemptIdentity+['claim_key'=>$sha,'expected_state_sha256'=>$sha,
            'operation_id'=>$run,'attempt_token'=>$sha,'scope_sha256'=>$sha,'owner_decision_sha256'=>$sha,
            'prompt_readback_sha256'=>$sha,'assignment_readback_sha256'=>$sha,'fencing_readback_sha256'=>$sha,
            'fencing_status'=>['type'=>'string','enum'=>['old_guards_fail_closed','writes_isolated']],
            'prior_attempts'=>['type'=>'array','minItems'=>2,'maxItems'=>2,'items'=>['type'=>'object',
                'properties'=>['ordinal'=>['type'=>'integer','enum'=>[1,2]],
                    'status'=>['type'=>'string','enum'=>['unknown','terminal_hold_no_work']],
                    'run_id'=>['type'=>['string','null'],'pattern'=>$run['pattern']], 'receipt_sha256'=>$sha],
                'required'=>['ordinal','status','run_id','receipt_sha256'],'additionalProperties'=>false]],
            'dispatch_ceiling'=>['type'=>'integer','const'=>3]];
        $attemptEntry=['queue_id'=>$id,'stage_id'=>$sha,'attempt_token'=>$sha,'instance_id'=>$sha,
            'operation_id'=>$run,'scope_sha256'=>$sha,'prompt_readback_sha256'=>$sha,'assignment_readback_sha256'=>$sha];
        $attemptBinding=$attemptIdentity+['claim_key'=>$sha,'expected_state_sha256'=>$sha,
            'attempt_token'=>$sha,'instance_id'=>$sha,'entry_sha256'=>$sha,'producer_run_id'=>$run,
            'packet_sha256'=>$sha,'native_result_readback_sha256'=>$sha];
        $criteria=['type'=>['object','null'],'properties'=>['readback_sha256'=>$sha,
            'case_count'=>['type'=>'integer','minimum'=>1,'maximum'=>20],
            'done_count'=>['type'=>'integer','minimum'=>0,'maximum'=>20],
            'partial_count'=>['type'=>'integer','minimum'=>0,'maximum'=>20]],
            'required'=>['readback_sha256','case_count','done_count','partial_count'],'additionalProperties'=>false];
        $receipt=['run_id'=>['type'=>['string','null'],'pattern'=>$run['pattern']],
            'outcome'=>['type'=>'string','enum'=>['accepted_readback','unknown']],
            'packet_sha256'=>$nullableSha,'readback_sha256'=>$nullableSha,'ledger_sha256'=>$nullableSha,
            'hotel_count'=>['type'=>['integer','null'],'minimum'=>0,'maximum'=>8],
            'record_count'=>['type'=>['integer','null'],'minimum'=>0,'maximum'=>200],
            'source_count'=>['type'=>['integer','null'],'minimum'=>0,'maximum'=>200],
            'read_update_count'=>['type'=>['integer','null'],'minimum'=>0,'maximum'=>200], 'criteria'=>$criteria];
        $acceptedReceipt=$receipt;$acceptedReceipt['outcome']=['type'=>'string','const'=>'accepted_readback'];
        $acceptedReceipt['run_id']=$run;
        foreach (['packet_sha256','readback_sha256','ledger_sha256'] as $key) $acceptedReceipt[$key]=$sha;
        foreach (['hotel_count'=>8,'record_count'=>200,'source_count'=>200,'read_update_count'=>200] as $key=>$max) {
            $acceptedReceipt[$key]=['type'=>'integer','minimum'=>in_array($key,['hotel_count','record_count'],true)?1:0,'maximum'=>$max];
        }
        $definitions=[
            ['hotel_work_create','Create an explicitly authorized hotel-work metadata plan on the one shared hotel lane. Finite mode defaults to three batches; continuous mode requires an explicit request. Does not start a hosted task or write hotel facts.',
                ['queue_id'=>$id,'mode'=>['type'=>'string','enum'=>['finite','continuous'],'default'=>'finite'],
                    'batch_limit'=>['type'=>['integer','null'],'minimum'=>1,'maximum'=>10],
                    'start_country'=>['type'=>'string','enum'=>['EG','TH','VN','TR'],'default'=>'EG'],
                    'phase_counter'=>['type'=>'integer','minimum'=>0,'maximum'=>1,'default'=>0], 'baseline_sha256'=>$sha],
                ['queue_id','baseline_sha256'],false],
            ['hotel_work_status','Read one owned hotel-work queue, current stage, delivery state, accepted batch count and bounded private subscription metadata. Subscription state is not proof of host delivery or worker execution. Metadata only; no hotel files, callbacks, secrets or customer context.',
                ['queue_id'=>$id],['queue_id'],true],
            ['hotel_work_claim','Claim the exact ready hotel stage before asking the existing worker to run. After a delivery resume, pass expected_pending_event_id from the actual trusted signed pending event; the server checks it atomically against the current delivery identity. Never manufacture this proof from status metadata. Repeated claims never authorize replay. Preserve the client-held claim key; unknown outcomes remain held.',
                $claim+['expected_pending_event_id'=>['type'=>'string','pattern'=>'^hotel_evt_[a-f0-9]{64}$']],array_keys($claim),false],
            ['hotel_work_started','Attest the actual existing worker run bound to your hotel-stage claim. This does not launch a worker; do not invent a run identifier.',
                $claim+['run_id'=>$run],[...array_keys($claim),'run_id'],false],
            ['hotel_work_complete','Attest one exact worker receipt after independently checking its saved packet and readback. Intake must consume the same research packet. Include due criteria after every second intake. A hash is not independent file proof; use unknown with null evidence for uncertain outcomes, never retry them. When an execution permit exists, accepted output requires producer_association_sha256 and the actual associated producer run ID and packet; the earlier canonical HOLD run cannot complete through the legacy branch.',
                $claim+['receipt'=>['type'=>'object','properties'=>$receipt,'required'=>array_keys($receipt),'additionalProperties'=>false],
                    'producer_association_sha256'=>$sha],
                [...array_keys($claim),'receipt'],false],
            ['hotel_work_release','Explicitly withdraw the exact unclaimed hotel stage. Claimed and unknown work cannot be released by age. Does not cancel hosted work.',
                ['queue_id'=>$id,'stage_id'=>$sha],['queue_id','stage_id'],false],
            ['hotel_work_skip_research','Explicitly abandon one owner-approved research batch and its unperformed intake. First independently verify that the exact original run has stopped or all of its external writes are isolated; hashes only attest that check and this operation cannot stop a hosted run or fence hotel files. Pin the queue, stage, baseline, old binding, original run and any UNKNOWN receipt. Both grants must belong to the same active administrator. Preserve history and accepted counters, move only this queue to the actual caller, and leave OAuth/reply permissions intact. A recorded repeat never skips another batch. Continue only from a fresh signed next-stage event, never replay the abandoned run.',
                ['queue_id'=>$id,'stage_id'=>$sha,'baseline_sha256'=>$sha,
                    'old_connection_id'=>['type'=>'string','pattern'=>'^[a-f0-9]{32}$'],
                    'expected_caller_connection_id'=>['type'=>'string','pattern'=>'^[a-f0-9]{32}$'],
                    'expected_run_id'=>$run,'expected_unknown_receipt_sha256'=>$nullableSha,'decision_sha256'=>$sha,
                    'external_effects'=>['type'=>'object','properties'=>['status'=>['type'=>'string','enum'=>['stopped','isolated']],
                        'readback_sha256'=>$sha],'required'=>['status','readback_sha256'],'additionalProperties'=>false]],
                ['queue_id','stage_id','baseline_sha256','old_connection_id','expected_caller_connection_id','expected_run_id',
                    'expected_unknown_receipt_sha256','decision_sha256','external_effects'],false],
            ['hotel_work_handoff_preflight','Read-only diagnostics for an exact same-admin hotel queue after a connection change with an already-bound worker run. Checks both canonical grant identities and pinned queue/stage/baseline/run. A positive result is not claim-key proof, access transfer, subscription, signed event or dispatch authorization.',
                ['queue_id'=>$id,'stage_id'=>$sha,'baseline_sha256'=>$sha,
                    'old_connection_id'=>['type'=>'string','pattern'=>'^[a-f0-9]{32}$'],
                    'expected_run_id'=>$run,
                    'expected_caller_connection_id'=>['type'=>'string','pattern'=>'^[a-f0-9]{32}$']],
                ['queue_id','stage_id','baseline_sha256','old_connection_id','expected_run_id','expected_caller_connection_id'],true],
            ['hotel_work_handoff_connection','Move authority for one same-admin hotel queue to this newly consented hotel connection. Default ready mode requires an unclaimed stage without a run and positive server proof that the exact previous binding belongs to the same hotel-authorized admin; its old grant may remain active. When the exact current binding already owns an acknowledged, unattended READY stage, the same-binding ready request may resume delivery once for that queue/stage without changing authority or starting work. A recorded repeat never clears a newer acknowledgement, claim, run or receipt. The consumer must match the newly signed event ID against current expected_pending_event_id and enforce event freshness before claiming. Explicit existing_run mode still requires a revoked or expired old grant, the retained claim key and exact already-known original run; it preserves claimed/UNKNOWN state and permits attention only, never dispatch, release, restart or replay. Explicit claim_authority mode restores access to a CLAIMED stage with no bound run and no current-stage receipt using the retained claim key and positive same-admin old hotel-grant proof. It preserves all claim, execution, acknowledgement and watchdog state; it never authorizes dispatch or proves that no external dispatch occurred. expected_run_id is forbidden in this mode. The original owner must independently reconcile its durable intent and any original run before continuing; an uncertain outcome remains held without replay. Pin the stage, old binding and baseline saved in the existing checkpoint. Storage errors and client flags are not proof. No credentials, scopes or subscriptions are changed.',
                ['queue_id'=>$id,'stage_id'=>$sha,'baseline_sha256'=>$sha,
                    'old_connection_id'=>['type'=>'string','pattern'=>'^[a-f0-9]{32}$'],
                    'handoff_mode'=>['type'=>'string','enum'=>['ready','existing_run','claim_authority'],'default'=>'ready'],
                    'claim_key'=>$sha,'expected_run_id'=>$run],
                ['queue_id','stage_id','baseline_sha256','old_connection_id'],false],
            ['hotel_work_resolve_existing_run','Reconcile an UNKNOWN stage only against independently checked saved evidence for its exact already-known original run. Pin the stored unknown receipt hash, expected run and packet. The original unknown receipt remains retained. A missing original run cannot be guessed; never use this operation to dispatch, restart or replay work.',
                $claim+['unknown_receipt_sha256'=>$sha,'expected_run_id'=>$run,'expected_packet_sha256'=>$sha,
                    'receipt'=>['type'=>'object','properties'=>$acceptedReceipt,'required'=>array_keys($acceptedReceipt),'additionalProperties'=>false]],
                [...array_keys($claim),'unknown_receipt_sha256','expected_run_id','expected_packet_sha256','receipt'],false],
            ['hotel_work_delivery_repair_preflight','Read-only exact-owner diagnostics and CAS pins for an acknowledged, unclaimed hotel delivery after its original one-time recovery is spent. Reads validated resume/recovery audit and ACK metadata without secrets or claim keys. Does not emit an event, claim work, inspect hosted jobs or authorize dispatch. A positive result is server metadata, not host or checkpoint proof.',
                $repairIdentity,array_keys($repairIdentity),true],
            ['hotel_work_repair_delivery','Record one explicitly authorized fresh delivery generation for a corrected consumer on this exact queue/stage after a successful current read-only preflight. Pin the returned event, original resume/recovery, ACK and state digests. Owner-request and corrected-prompt digests are attestations, not host proof; reconcile the original private intents, worker and reservation guards before applying. Preserves original ACKs and audits, stage, counters, claims, receipts and hotel data. Hard-once per queue/stage: an exact repeat returns its stored receipt even after later work; changed repeats are held. Ordinary cron alone signs and delivers the new event. This operation never claims, dispatches, replays a run, resumes the old budget or creates a subscription. An uncertain commit must be reconciled using the same exact request.',
                $repair,array_keys($repair),false],
            ['hotel_work_execution_attempt_preflight','Read-only current owner, stage, claim and canonical-run metadata for one explicitly authorized execution attempt. Returns exact state pins and bounded permit/entry/producer metadata. This does not prove native termination, authorize a HOST dispatch or inspect hotel data.',
                $attemptIdentity,array_keys($attemptIdentity),true],
            ['hotel_work_prepare_execution_attempt','Append the sole additional execution permit for ordinal 3 with a total ceiling of 3, preserving the current claim, canonical terminal-HOLD run and earlier UNKNOWN history. The literal token must already be frozen in the saved worker instructions and assignment. External fencing and owner-decision digests are attestations, not independent HOST proof. A repeated receipt never authorizes another dispatch; UNKNOWN never refunds the permit. Does not launch, rearm or replay a worker.',
                $attemptPreparation,array_keys($attemptPreparation),false],
            ['hotel_work_enter_execution_attempt','Atomically consume the precommitted literal token with one fresh per-invocation instance before any web, hotel fact or file write. The first entry admits one cooperating producer; another instance is held. An exact repeat is receipt reconciliation and must not start work again. Never adopt a token or another instance from the current checkpoint. Native run ID is associated later from the actual result.',
                $attemptEntry,array_keys($attemptEntry),false],
            ['hotel_work_bind_execution_attempt','Append one authenticated-owner association between the winning token/instance, its actual native producer run and exact saved packet after independently checking the native result. Preserve canonical current_run_id and UNKNOWN request origin. This association is not HOST authentication or a dispatch. Complete must use the actual producer ID, exact packet and returned association digest; never label new output as the earlier HOLD run.',
                $attemptBinding,array_keys($attemptBinding),false],
            ['hotel_work_collection_context','Read the admitted producer assignment, pinned baseline index and typed canonical overlay for an explicitly enrolled server-owned collection. Requires current hotel authority and the exact journal capability and admitted instance. Does not decode legacy WORKING, read Library, dispatch work or establish business acceptance.',
                $collection,array_keys($collection),true],
            ['hotel_work_collection_candidate','Save one strictly typed candidate (at most 24 KiB of raw JSON bytes) for the admitted producer assignment. Only server-pinned scope and canonical references are accepted; no Library writes, reservation changes, queue advancement or business acceptance. An exact repeat reconciles the candidate receipt and never authorizes another run.',
                $collection+['request_id'=>$journalId,'packet_json'=>['type'=>'string','maxLength'=>self::COLLECTION_PACKET_BYTES]],
                [...array_keys($collection),'request_id','packet_json'],false],
        ];
        $review=['queue_id'=>$id,'stage_id'=>$sha,'generation'=>['type'=>'integer','minimum'=>1],'review_capability'=>$sha,'instance_id'=>$journalId];
        $definitions[]=['hotel_work_working_read','Read an exact immutable WORKING record within the admitted producer scope. Byte cursor returns all preserved variants without Library fallback.',
            $collection+['data_sha256'=>$sha,'pointer'=>['type'=>'string','maxLength'=>512],'offset'=>['type'=>'integer','minimum'=>0]],
            [...array_keys($collection),'data_sha256','pointer','offset'],true];
        $definitions[]=['hotel_work_review_context','Read the exact producer candidate using a separate reviewer capability; first call binds one reviewer instance.', $review,array_keys($review),false];
        $definitions[]=['hotel_work_review_submit','Persist one immutable independent review verdict. Does not itself commit data or advance the queue.',
            $review+['verdict'=>['type'=>'object']], [...array_keys($review),'verdict'],false];
        $out=[];
        foreach ($definitions as [$name,$description,$properties,$required,$readOnly]) {
            $security=[['type'=>'oauth2','scopes'=>['hotel_work:write']]];
            $out[]=['name'=>$name,'description'=>$description,
                'inputSchema'=>['type'=>'object','properties'=>$properties,'required'=>$required,'additionalProperties'=>false],
                'annotations'=>['readOnlyHint'=>$readOnly,'destructiveHint'=>$name==='hotel_work_skip_research','idempotentHint'=>true,'openWorldHint'=>false],
                'securitySchemes'=>$security,'_meta'=>['securitySchemes'=>$security]];
        }
        return $out;
    }

    public function call(string $name,array $args,int $admin,string $connection): array
    {
        if (!in_array($name,self::NAMES,true)||$admin<=0||!preg_match('/\A[a-f0-9]{32}\z/D',$connection)) throw new InvalidArgumentException();
        $operation=substr($name,strlen('hotel_work_'));
        if(in_array($operation,['working_read','review_context','review_submit'],true)) {
            $fields=$operation==='working_read'?['queue_id','stage_id','generation','operation_id','worker_capability','instance_id','data_sha256','pointer','offset']:
                ['queue_id','stage_id','generation','review_capability','instance_id'];
            if($operation==='review_submit')$fields[]='verdict';
            if(count($args)!==count($fields)||array_diff(array_keys($args),$fields)||strlen(json_encode($args,JSON_THROW_ON_ERROR))>16384)throw new InvalidArgumentException();
            require_once __DIR__.'/HotelCollectionCanonicalService.php';
            $service=new HotelCollectionCanonicalService($this->pdo,$admin,$connection,$this->clock);
            if($operation==='working_read'){
                $identity=array_diff_key($args,array_flip(['data_sha256','pointer','offset']));
                return $service->workerWorkingRead($identity,$args['data_sha256'],$args['pointer'],$args['offset']);
            }
            if($operation==='review_context')return $service->reviewContext($args);
            $identity=array_diff_key($args,['verdict'=>true]);return $service->submitReview($identity,$args['verdict']);
        }
        $isCollection=in_array($operation,['collection_context','collection_candidate'],true);
        // A JSON string may expand sixfold on the wire; the raw packet has its own byte cap.
        $wireLimit=$operation==='collection_candidate'?6*self::COLLECTION_PACKET_BYTES+4096:16384;
        if (strlen(json_encode($args,JSON_THROW_ON_ERROR))>$wireLimit) throw new InvalidArgumentException();
        if ($isCollection) {
            $required=['queue_id','stage_id','generation','operation_id','worker_capability','instance_id'];
            if ($operation==='collection_candidate') $required=[...$required,'request_id','packet_json'];
            if (count($args)!==count($required)||array_diff(array_keys($args),$required)) throw new InvalidArgumentException();
            foreach (['queue_id'=>'/\A[A-Za-z0-9_-]{1,80}\z/D','stage_id'=>'/\A[a-f0-9]{64}\z/D',
                'operation_id'=>'/\A[a-f0-9]{64}\z/D','worker_capability'=>'/\A[a-f0-9]{64}\z/D',
                'instance_id'=>'/\A[A-Za-z0-9_.:-]{1,128}\z/D'] as $field=>$pattern) {
                if (!is_string($args[$field])||!preg_match($pattern,$args[$field])) throw new InvalidArgumentException();
            }
            if (!is_int($args['generation'])||$args['generation']<1) throw new InvalidArgumentException();
            if ($operation==='collection_candidate'&&(!is_string($args['request_id'])
                ||!preg_match('/\A[A-Za-z0-9_.:-]{1,128}\z/D',$args['request_id'])
                ||!is_string($args['packet_json'])||strlen($args['packet_json'])>self::COLLECTION_PACKET_BYTES)) throw new InvalidArgumentException();
        }
        $attemptFields=['queue_id','stage_id','baseline_sha256','expected_caller_connection_id','expected_current_run_id'];
        $attemptAllowed=match ($operation) {
            'execution_attempt_preflight'=>$attemptFields,
            'prepare_execution_attempt'=>[...$attemptFields,'claim_key','expected_state_sha256','operation_id',
                'attempt_token','scope_sha256','owner_decision_sha256','prompt_readback_sha256',
                'assignment_readback_sha256','fencing_readback_sha256','fencing_status','prior_attempts','dispatch_ceiling'],
            'enter_execution_attempt'=>['queue_id','stage_id','attempt_token','instance_id','operation_id',
                'scope_sha256','prompt_readback_sha256','assignment_readback_sha256'],
            'bind_execution_attempt'=>[...$attemptFields,'claim_key','expected_state_sha256','attempt_token',
                'instance_id','entry_sha256','producer_run_id','packet_sha256','native_result_readback_sha256'],
            default=>null,
        };
        if ($attemptAllowed!==null&&(count($args)!==count($attemptAllowed)||array_diff(array_keys($args),$attemptAllowed))) throw new InvalidArgumentException();
        if (in_array($operation,['delivery_repair_preflight','repair_delivery'],true)) {
            $allowed=['queue_id','stage_id','baseline_sha256','expected_caller_connection_id'];
            if ($operation==='repair_delivery') $allowed=[...$allowed,'expected_pending_event_id','expected_resume_sha256',
                'expected_recovery_sha256','expected_ack_sha256','expected_state_sha256','owner_request_sha256','consumer_prompt_sha256'];
            if (count($args)!==count($allowed)||array_diff(array_keys($args),$allowed)) throw new InvalidArgumentException();
        }
        if (in_array($operation,['handoff_preflight','handoff_connection','resolve_existing_run','skip_research'],true)) {
            if ($operation==='skip_research') {
                $allowed=['queue_id','stage_id','baseline_sha256','old_connection_id','expected_caller_connection_id',
                    'expected_run_id','expected_unknown_receipt_sha256','decision_sha256','external_effects'];
            } elseif ($operation==='handoff_preflight') {
                $allowed=['queue_id','stage_id','baseline_sha256','old_connection_id','expected_run_id','expected_caller_connection_id'];
            } elseif ($operation==='handoff_connection') {
                $allowed=['queue_id','stage_id','baseline_sha256','old_connection_id'];
                $mode=array_key_exists('handoff_mode',$args)?$args['handoff_mode']:'ready';
                if (!in_array($mode,['ready','existing_run','claim_authority'],true)) throw new InvalidArgumentException();
                if ($mode==='existing_run') $allowed=[...$allowed,'handoff_mode','claim_key','expected_run_id'];
                elseif ($mode==='claim_authority') $allowed=[...$allowed,'handoff_mode','claim_key'];
                elseif (array_key_exists('handoff_mode',$args)) $allowed[]='handoff_mode';
            } else $allowed=['queue_id','stage_id','claim_key','unknown_receipt_sha256','expected_run_id','expected_packet_sha256','receipt'];
            if (count($args)!==count($allowed)||array_diff(array_keys($args),$allowed)) throw new InvalidArgumentException();
        }
        if ($operation==='status') {
            if (array_diff(array_keys($args),['queue_id'])||!is_string($args['queue_id']??null)) throw new InvalidArgumentException();
            $arguments=[$admin,$connection,$args['queue_id']];
        } else $arguments=[$admin,$connection,$args];
        if (in_array($operation,['handoff_preflight','handoff_connection','skip_research'],true)) {
            // This capability is created only by the authenticated server owner.
            // No client-provided retired/expired/active flag reaches the queue.
            $arguments[]=function(string $old,int $sameAdmin,string $mode) use($connection):bool {
                $oauth=new ManagerPreparationOAuth($this->pdo,$this->clock,$this->oauthProfile);
                if (hash_equals($old,$connection)) {
                    return in_array($mode,['ready','current'],true)&&$oauth->connectionAuthorized($connection,$sameAdmin,[ManagerPreparationOAuth::HOTEL_SCOPE]);
                }
                return match ($mode) {
                    'ready','claim_authority'=>$oauth->hotelConnectionKnown($old,$sameAdmin),
                    'existing_run'=>$oauth->connectionRetired($old,$sameAdmin),
                    default=>false,
                };
            };
        }
        try {
            if (is_callable($this->store)) $result=($this->store)($operation,$arguments);
            elseif ($isCollection) {
                require_once __DIR__.'/HotelCollectionCanonicalService.php';
                $service=new HotelCollectionCanonicalService($this->pdo,$admin,$connection,$this->clock);
                $result=$operation==='collection_context'?$service->workerRead($args):$service->workerSubmit($args);
            } else {
                if ($this->store===null) {
                    require_once __DIR__.'/HotelWorkQueue.php';
                    $this->store=HotelWorkQueueFactory::create($this->pdo,$this->clock,$this->journalProtection);
                }
                $result=$this->store->$operation(...$arguments);
            }
        } catch (HotelWorkQueueConflict $error) {
            return ['ok'=>false,'reason'=>$error->reason];
        }
        if (!is_array($result)) throw new RuntimeException('invalid_hotel_work_result');
        if ($operation==='status') {
            require_once __DIR__.'/HotelWorkHealth.php';
            $result['cron_health']=HotelWorkHealth::snapshot($this->clock!==null?(int)($this->clock)():null);
            require_once __DIR__.'/HotelWorkEventService.php';
            $result['subscription_setup']=HotelWorkEventService::unavailableSubscriptionSetup();
            if ($this->store instanceof HotelWorkQueue) {
                $reader=$this->events??new HotelWorkEventService($this->pdo,null,$this->clock,null,$this->store);
                $result['subscription_setup']=$reader->subscriptionSetup($admin,$connection,$args['queue_id']);
            }
        }
        return $result;
    }
}

