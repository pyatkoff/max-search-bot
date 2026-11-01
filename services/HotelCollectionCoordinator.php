<?php
declare(strict_types=1);
require_once __DIR__.'/HotelCollectionCanonicalService.php';
require_once __DIR__.'/HotelWorkOperationJournalPrototype.php';
require_once __DIR__.'/HotelCollectionDispatchAdapter.php';
/** Server-only transport boundary. Adapter must target the existing native task.
 * No default adapter, timer, retry, run-ID invention or runtime enablement.
 */
final class HotelCollectionCoordinator
{
    public function __construct(private PDO $pdo,private int $admin,private string $connection,private $adapter,private $clock=null)
    {if(!is_callable($adapter))throw new InvalidArgumentException('existing_native_adapter_required');}
    public function dispatch(string $queue,string $stage,int $generation,string $control,string $event,array $intent):array
    {
        if(array_diff(array_keys($intent),['operation_id','task_id','request_id','worker_capability'])||count($intent)!==4)throw new InvalidArgumentException('dispatch_identity');
        $service=new HotelCollectionCanonicalService($this->pdo,$this->admin,$this->connection,$this->clock);
        $identity=$service->dispatchIdentity($queue,$stage,$generation,$control,$intent['operation_id'],$intent['worker_capability'],$intent['task_id']);
        $request=['queue_id'=>$queue,'stage_id'=>$stage,'generation'=>$generation,'operation_id'=>$intent['operation_id'],'task_id'=>$intent['task_id'],'worker_capability'=>$intent['worker_capability'],'role'=>$identity['role']];
        // Fail before burning a permit when the concrete route is not installed.
        if($this->adapter instanceof HotelCollectionDispatchAdapter)$this->adapter->assertReady($request);
        $permit=$service->consumeDispatch($queue,$stage,$generation,$control,$event);
        if(!$permit['dispatch_authority'])return$permit;
        $journal=new HotelWorkOperationJournalPrototype($this->pdo,$this->admin,$this->connection);
        if($identity['role']==='producer')$journal->dispatchIntent($queue,$stage,$generation,$control,$intent['operation_id'],$intent['request_id']);
        // The irreversible send is outside SQL. Its intent already remains UNKNOWN.
        // Exception/timeout never refunds either permit; another call cannot resend.
        try{$ack=($this->adapter)($request);}
        catch(Throwable $unknown){return ['state'=>'UNKNOWN','dispatch_authority'=>false,'native_run_verified'=>false];}
        return ['state'=>'transport_acknowledged','ack'=>$ack,'dispatch_authority'=>false,'native_run_verified'=>false];
    }
}
