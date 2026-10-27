<?php
declare(strict_types=1);
require_once __DIR__.'/ManagerPreparationOAuth.php';

/** Hotel metadata tools only. The canonical MCP owner enforces the hotel grant. */
final class HotelWorkMcpAdapter
{
    public const NAMES = ['hotel_work_create','hotel_work_status','hotel_work_claim',
        'hotel_work_started','hotel_work_complete','hotel_work_release',
        'hotel_work_handoff_connection','hotel_work_resolve_existing_run'];

    public function __construct(private PDO $pdo, private $store = null, private $clock = null) {}

    public static function tools(): array
    {
        $id=['type'=>'string','pattern'=>'^[A-Za-z0-9_-]{1,80}$'];
        $sha=['type'=>'string','pattern'=>'^[a-f0-9]{64}$'];
        $nullableSha=['type'=>['string','null'],'pattern'=>'^[a-f0-9]{64}$'];
        $run=['type'=>'string','pattern'=>'^[A-Za-z0-9_-]{1,128}$'];
        $claim=['queue_id'=>$id,'stage_id'=>$sha,'claim_key'=>$sha];
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
            ['hotel_work_status','Read one owned hotel-work queue and its current stage, delivery state and accepted batch count. Metadata only; no hotel files, customer context or worker execution.',
                ['queue_id'=>$id],['queue_id'],true],
            ['hotel_work_claim','Claim the exact ready hotel stage before asking the existing worker to run. After a delivery resume, pass expected_pending_event_id from the actual trusted signed pending event; the server checks it atomically against the current delivery identity. Never manufacture this proof from status metadata. Repeated claims never authorize replay. Preserve the client-held claim key; unknown outcomes remain held.',
                $claim+['expected_pending_event_id'=>['type'=>'string','pattern'=>'^hotel_evt_[a-f0-9]{64}$']],array_keys($claim),false],
            ['hotel_work_started','Attest the actual existing worker run bound to your hotel-stage claim. This does not launch a worker; do not invent a run identifier.',
                $claim+['run_id'=>$run],[...array_keys($claim),'run_id'],false],
            ['hotel_work_complete','Attest one exact worker receipt after independently checking its saved packet and readback. Intake must consume the same research packet. Include due criteria after every second intake. A hash is not independent file proof; use unknown with null evidence for uncertain outcomes, never retry them.',
                $claim+['receipt'=>['type'=>'object','properties'=>$receipt,'required'=>array_keys($receipt),'additionalProperties'=>false]],
                [...array_keys($claim),'receipt'],false],
            ['hotel_work_release','Explicitly withdraw the exact unclaimed hotel stage. Claimed and unknown work cannot be released by age. Does not cancel hosted work.',
                ['queue_id'=>$id,'stage_id'=>$sha],['queue_id','stage_id'],false],
            ['hotel_work_handoff_connection','Move authority for one same-admin hotel queue to this newly consented hotel connection. Default ready mode requires an unclaimed stage without a run and positive server proof that the exact previous binding belongs to the same hotel-authorized admin; its old grant may remain active. When the exact current binding already owns an acknowledged, unattended READY stage, the same-binding ready request may resume delivery once for that queue/stage without changing authority or starting work. A recorded repeat never clears a newer acknowledgement, claim, run or receipt. The consumer must match the newly signed event ID against current expected_pending_event_id and enforce event freshness before claiming. Explicit existing_run mode still requires a revoked or expired old grant, the retained claim key and exact already-known original run; it preserves claimed/UNKNOWN state and permits attention only, never dispatch, release, restart or replay. A missing original run stays held. Pin the stage, old binding and baseline saved in the existing checkpoint. Storage errors and client flags are not proof. No credentials or scopes are changed.',
                ['queue_id'=>$id,'stage_id'=>$sha,'baseline_sha256'=>$sha,
                    'old_connection_id'=>['type'=>'string','pattern'=>'^[a-f0-9]{32}$'],
                    'handoff_mode'=>['type'=>'string','enum'=>['ready','existing_run'],'default'=>'ready'],
                    'claim_key'=>$sha,'expected_run_id'=>$run],
                ['queue_id','stage_id','baseline_sha256','old_connection_id'],false],
            ['hotel_work_resolve_existing_run','Reconcile an UNKNOWN stage only against independently checked saved evidence for its exact already-known original run. Pin the stored unknown receipt hash, expected run and packet. The original unknown receipt remains retained. A missing original run cannot be guessed; never use this operation to dispatch, restart or replay work.',
                $claim+['unknown_receipt_sha256'=>$sha,'expected_run_id'=>$run,'expected_packet_sha256'=>$sha,
                    'receipt'=>['type'=>'object','properties'=>$acceptedReceipt,'required'=>array_keys($acceptedReceipt),'additionalProperties'=>false]],
                [...array_keys($claim),'unknown_receipt_sha256','expected_run_id','expected_packet_sha256','receipt'],false],
        ];
        $out=[];
        foreach ($definitions as [$name,$description,$properties,$required,$readOnly]) {
            $security=[['type'=>'oauth2','scopes'=>['hotel_work:write']]];
            $out[]=['name'=>$name,'description'=>$description,
                'inputSchema'=>['type'=>'object','properties'=>$properties,'required'=>$required,'additionalProperties'=>false],
                'annotations'=>['readOnlyHint'=>$readOnly,'destructiveHint'=>false,'idempotentHint'=>true,'openWorldHint'=>false],
                'securitySchemes'=>$security,'_meta'=>['securitySchemes'=>$security]];
        }
        return $out;
    }

    public function call(string $name,array $args,int $admin,string $connection): array
    {
        if (!in_array($name,self::NAMES,true)||$admin<=0||!preg_match('/\A[a-f0-9]{32}\z/D',$connection)) throw new InvalidArgumentException();
        if (strlen(json_encode($args,JSON_THROW_ON_ERROR))>16384) throw new InvalidArgumentException();
        $operation=substr($name,strlen('hotel_work_'));
        if (in_array($operation,['handoff_connection','resolve_existing_run'],true)) {
            if ($operation==='handoff_connection') {
                $allowed=['queue_id','stage_id','baseline_sha256','old_connection_id'];
                $mode=array_key_exists('handoff_mode',$args)?$args['handoff_mode']:'ready';
                if (!in_array($mode,['ready','existing_run'],true)) throw new InvalidArgumentException();
                if ($mode==='existing_run') $allowed=[...$allowed,'handoff_mode','claim_key','expected_run_id'];
                elseif (array_key_exists('handoff_mode',$args)) $allowed[]='handoff_mode';
            } else $allowed=['queue_id','stage_id','claim_key','unknown_receipt_sha256','expected_run_id','expected_packet_sha256','receipt'];
            if (count($args)!==count($allowed)||array_diff(array_keys($args),$allowed)) throw new InvalidArgumentException();
        }
        if ($operation==='status') {
            if (array_diff(array_keys($args),['queue_id'])||!is_string($args['queue_id']??null)) throw new InvalidArgumentException();
            $arguments=[$admin,$connection,$args['queue_id']];
        } else $arguments=[$admin,$connection,$args];
        if ($operation==='handoff_connection') {
            // This capability is created only by the authenticated server owner.
            // No client-provided retired/expired/active flag reaches the queue.
            $arguments[]=function(string $old,int $sameAdmin,string $mode) use($connection):bool {
                $oauth=new ManagerPreparationOAuth($this->pdo,$this->clock);
                if (hash_equals($old,$connection)) {
                    return $mode==='ready'&&$oauth->connectionAuthorized($connection,$sameAdmin,[ManagerPreparationOAuth::HOTEL_SCOPE]);
                }
                return match ($mode) {
                    'ready'=>$oauth->hotelConnectionKnown($old,$sameAdmin),
                    'existing_run'=>$oauth->connectionRetired($old,$sameAdmin),
                    default=>false,
                };
            };
        }
        try {
            if (is_callable($this->store)) $result=($this->store)($operation,$arguments);
            else {
                if ($this->store===null) {
                    require_once __DIR__.'/HotelWorkQueue.php';
                    $this->store=new HotelWorkQueue($this->pdo,$this->clock);
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
        }
        return $result;
    }
}
