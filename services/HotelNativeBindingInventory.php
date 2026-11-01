<?php
declare(strict_types=1);
require_once __DIR__.'/HotelNativeRecoveryContext.php';
require_once __DIR__.'/HotelWorkEventService.php';

/** Read-only, no model, callback, subscribe, credential export or activation. */
final class HotelNativeBindingInventory
{
    public function __construct(private HotelNativeRecoveryContext $c){}
    public function read(string $queue):array
    {
        $this->c->autocommit();$this->c->owned($queue);
        $s=$this->c->pdo->prepare('SELECT subscription_id,admin_id,oauth_connection_id,event_name,arguments_json,is_active,verified_at,expires_at FROM hotel_work_events WHERE project_key=? AND queue_id=? AND admin_id=? AND oauth_connection_id=? ORDER BY event_name,subscription_id LIMIT 21');
        $s->execute(['anytour',$queue,$this->c->admin,$this->c->connection]);$rows=$s->fetchAll(PDO::FETCH_ASSOC);
        if(count($rows)>20)throw new RuntimeException('native_inventory_row_limit');
        $out=[];
        foreach($rows as$row){
            $args=json_decode($row['arguments_json'],true,8,JSON_THROW_ON_ERROR);
            $expected=['project_key'=>'anytour','queue_id'=>$queue];
            $task=$args['task_id']??null;
            if(in_array($row['event_name'],HotelWorkEventService::WORKER_EVENTS,true))$expected['task_id']=$task;
            $valid=$args===$expected&&($task===null||is_string($task)&&preg_match('/\A[a-f0-9]{32}\z/D',$task)===1);
            $v=strtotime($row['verified_at'].' UTC');$e=strtotime($row['expires_at'].' UTC');
            $out[]=['subscription_id'=>$row['subscription_id'],'event'=>$row['event_name'],
                'filters'=>$valid?$expected:null,'filters_valid'=>$valid,'subscriber_task_id'=>$task,
                'eligible'=>(int)$row['is_active']===1&&$v!==false&&$v<=$this->c->now()&&$e!==false&&$e>$this->c->now(),
                'host_task_verified'=>false,'host_trigger_id'=>null];
        }
        $this->c->owned($queue);
        return ['queue_id'=>$queue,'connection_id'=>$this->c->connection,'observed_at'=>gmdate('Y-m-d\TH:i:s\Z',$this->c->now()),
            'subscriptions'=>$out,'dispatch_authority'=>false,'provenance'=>'server_registration_not_host_task_proof'];
    }

    /** Validate a *trusted operator's* exact native host export against server
     * registrations. Caller supplies provenance; this parser cannot authenticate
     * the host. A subscriber's desired task_id never substitutes for native IDs.
     */
    public function verifyHostExport(string $queue,array $expectedTasks,string $connector,array $export):array
    {
        if(array_keys($expectedTasks)!==['producer','reviewer']||$expectedTasks['producer']===$expectedTasks['reviewer'])throw new InvalidArgumentException('native_expected_roles');
        foreach($expectedTasks as$task)if(!is_string($task)||preg_match('/\A[a-f0-9]{32}\z/D',$task)!==1)throw new InvalidArgumentException('native_expected_task');
        if(preg_match('/\A[A-Za-z0-9_.:-]{1,128}\z/D',$connector)!==1)throw new InvalidArgumentException('native_connector_identity');
        $inventory=$this->read($queue);
        $hold=static fn(string$why):array=>['state'=>'HOLD','reason'=>$why,'dispatch_authority'=>false,'native_run_verified'=>false];
        if(array_diff(array_keys($export),['source','observed_at','bindings'])||($export['source']??null)!=='native_host_export'
            ||!is_string($export['observed_at']??null)||!is_array($export['bindings']??null))return$hold('exact_host_export_required');
        $when=strtotime($export['observed_at']);
        if($when===false||$when>$this->c->now()||$when<$this->c->now()-300)return$hold('stale_host_export');
        if(!array_is_list($export['bindings'])||count($export['bindings'])!==2)return$hold('exact_two_role_bindings_required');
        $evidence=[];$roles=[];
        foreach($export['bindings']as$b){
            $keys=['task_id','trigger_id','subscription_id','connector_id','event','arguments'];
            if(!is_array($b)||count($b)!==count($keys)||array_diff(array_keys($b),$keys))return$hold('host_fields_not_exposed');
            foreach(['task_id','trigger_id','subscription_id','connector_id','event']as$key)if(!is_string($b[$key])||$b[$key]==='')return$hold('host_fields_not_exposed');
            if(preg_match('/\A[A-Za-z0-9_-]{1,128}\z/D',$b['trigger_id'])!==1)return$hold('host_trigger_identity_invalid');
            $role=array_search($b['task_id'],$expectedTasks,true);
            if($role===false||isset($roles[$role])||$b['connector_id']!==$connector)return$hold('host_role_connector_mismatch');
            $roles[$role]=true;$event=$role==='producer'?HotelWorkEventService::WORKER_EVENTS[0]:HotelWorkEventService::WORKER_EVENTS[1];
            $args=['project_key'=>'anytour','queue_id'=>$queue,'task_id'=>$expectedTasks[$role]];
            if($b['event']!==$event||$b['arguments']!==$args)return$hold('host_filters_mismatch');
            $rows=array_values(array_filter($inventory['subscriptions'],static fn(array$r):bool=>$r['event']===$event&&$r['eligible']&&$r['filters_valid']));
            if(count($rows)!==1||$rows[0]['subscription_id']!==$b['subscription_id']||$rows[0]['subscriber_task_id']!==$b['task_id'])return$hold('server_subscription_absent_ambiguous_or_mismatched');
            $evidence[$role]=['task_id'=>$b['task_id'],'subscription_id'=>$b['subscription_id'],'trigger_id'=>$b['trigger_id'],
                'event'=>$event,'project_key'=>'anytour','queue_id'=>$queue,'connection_id'=>$this->c->connection,
                'evidence_sha256'=>hash('sha256',HotelNativeRecoveryContext::json($export))];
        }
        return ['state'=>'HOST_EXPORT_MATCHES_SERVER','evidence'=>$evidence,'dispatch_authority'=>false,'native_run_verified'=>false,
            'provenance'=>'trusted_operator_host_export_not_independent_host_authentication','binding_applied'=>false];
    }
}
