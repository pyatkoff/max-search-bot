<?php
declare(strict_types=1);
require_once __DIR__.'/ConversationDb.php';
require_once __DIR__.'/ConversationRecordClock.php';
require_once __DIR__.'/ManagerConversationService.php';

/** One operational reply policy. Never sends, acknowledges reading or closes a conversation. */
final class ManagerReplyQueueService
{
    private const EVENT='manager_reply_queue';
    public const SEND_BOUNDARY='_manager_reply_boundary';

    private static function alias(string $alias): string {return preg_match('/\A[a-zA-Z_][a-zA-Z0-9_]*\z/D',$alias)?$alias:'c';}
    private static function json(string $column,string $path): string {return "JSON_UNQUOTE(JSON_EXTRACT(CASE WHEN JSON_VALID($column) THEN $column ELSE '{}' END,'$path'))";}
    public static function humanSql(string $alias='mc',bool $mysql=true): string
    {
        $alias=self::alias($alias);$type=$mysql?self::json($alias.'.metadata_json','$.type'):"JSON_EXTRACT(CASE WHEN JSON_VALID($alias.metadata_json) THEN $alias.metadata_json ELSE '{}' END,'$.type')";
        return "$alias.direction='inbound' AND $alias.sender_type='customer' AND COALESCE($type,'message') NOT IN ('callback','start','bot_started')";
    }
    private static function maximum(string $a,string $b): string {return "(CASE WHEN ($a)>($b) THEN ($a) ELSE ($b) END)";}
    private static function manualSql(string $alias,string $field): string
    {
        $value=self::json('rq.payload_json','$.current.'.$field);
        return "COALESCE((SELECT CAST(COALESCE($value,0) AS UNSIGNED) FROM conversation_events rq WHERE rq.conversation_id=$alias.id AND rq.event_type='".self::EVENT."' ORDER BY rq.id DESC LIMIT 1),0)";
    }
    /** New replies carry the pre-transport frontier. Historical replies retain their recorded semantics. */
    private static function sentSql(string $alias,bool $request): string
    {
        $present=self::json('ro.metadata_json','$.'.self::SEND_BOUNDARY.'.version');
        $field=$request?'request_event_id':'customer_message_id';$cutoff=self::json('ro.metadata_json','$.'.self::SEND_BOUNDARY.'.'.$field);
        $legacy=$request?"COALESCE((SELECT MAX(re.id) FROM conversation_events re WHERE re.conversation_id=$alias.id AND re.event_type='waiting_manager' AND re.created_at<=ro.created_at),0)":'ro.id';
        return "COALESCE((SELECT MAX(CASE WHEN $present IS NULL THEN $legacy WHEN $present=1 THEN CAST(COALESCE($cutoff,0) AS UNSIGNED) ELSE 0 END) FROM messages ro WHERE ro.conversation_id=$alias.id AND ro.direction='outbound' AND ro.sender_type='manager'),0)";
    }
    private static function cutoffSql(string $alias,bool $request): string
    {
        return self::maximum(self::manualSql($alias,$request?'through_request_event_id':'through_message_id'),self::sentSql($alias,$request));
    }
    private static function requestSql(string $alias): string
    {
        return "COALESCE((SELECT MAX(rr.id) FROM conversation_events rr WHERE rr.conversation_id=$alias.id AND rr.event_type='waiting_manager'),0)";
    }
    private static function legacyWaitingSql(string $alias): string
    {
        $operation=self::json('rl.payload_json','$.current.operation_id');
        $active="COALESCE((SELECT $operation FROM conversation_events rl WHERE rl.conversation_id=$alias.id AND rl.event_type='".self::EVENT."' ORDER BY rl.id DESC LIMIT 1),'')";
        // Preserve an existing unassigned handoff when historical event capture
        // is missing. Old outgoing rows cannot prove this request was answered.
        return "($alias.status='waiting_manager' AND ".self::requestSql($alias)."=0 AND $active='')";
    }
    public static function neededSql(string $alias='c',bool $mysql=true): string
    {
        $alias=self::alias($alias);$message=self::cutoffSql($alias,false);$request=self::cutoffSql($alias,true);
        $suspended=ManagerDeliveryStateService::activeSuspensionSql($alias);$human=self::humanSql('ri');
        $sql="($alias.status IN ('manager','waiting_manager') AND NOT ($suspended) AND (".self::legacyWaitingSql($alias).' OR '.self::requestSql($alias).">$request OR EXISTS(SELECT 1 FROM messages ri WHERE ri.conversation_id=$alias.id AND $human AND ri.id>$message)))";
        return $mysql?$sql:str_replace('JSON_UNQUOTE(','(',$sql);
    }
    public static function waitStartedSql(string $alias='c',bool $mysql=true): string
    {
        $alias=self::alias($alias);$request=self::requestSql($alias);$resolved=self::cutoffSql($alias,true);$message=self::cutoffSql($alias,false);
        $requestClock=ConversationRecordClock::sql('s.payload_json',$mysql);$messageClock=ConversationRecordClock::sql('s.metadata_json',$mysql);$human=self::humanSql('s');
        // Before the first reply the manager's request starts the clock, not earlier bot collection.
        $sql='(CASE WHEN '.self::neededSql($alias).' THEN CASE WHEN '.self::legacyWaitingSql($alias)." THEN NULL WHEN $request>$resolved THEN (SELECT $requestClock FROM conversation_events s WHERE s.id=$request AND s.conversation_id=$alias.id) ELSE (SELECT $messageClock FROM messages s WHERE s.conversation_id=$alias.id AND $human AND s.id>$message ORDER BY s.id LIMIT 1) END ELSE NULL END)";
        return $mysql?$sql:str_replace('JSON_UNQUOTE(','(',$sql);
    }
    public static function decorate(array $rows,?int $now=null): array
    {
        $now??=time();foreach($rows as &$row){$raw=$row['reply_wait_started_unix']??null;$unix=is_numeric($raw)?(int)$raw:0;$known=$unix>=946684800&&$unix<=$now;
            $row['reply_wait_started_unix']=$known?$unix:null;$row['reply_wait_started_at']=$known?gmdate('Y-m-d\TH:i:s\Z',$unix):null;
            $row['reply_wait_age_seconds']=$known?$now-$unix:null;$row['reply_wait_time_known']=$known;
        }unset($row);return $rows;
    }
    /** Capture before transport, not after a late inbound has acquired a lower INSERT ID than the reply. */
    public static function captureSendBoundary(int $conversationId): array
    {
        $frontier=self::frontiers($conversationId,false);
        return ['version'=>1,'customer_message_id'=>$frontier['customer_message_id'],'request_event_id'=>$frontier['request_event_id']];
    }
    private static function frontiers(int $conversationId,bool $forSnapshot=true): array
    {
        $pdo=ConversationDb::connection();$human=self::humanSql('m',$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql');
        $sql="SELECT (SELECT COALESCE(MAX(m.id),0) FROM messages m WHERE m.conversation_id=? AND $human) AS customer_message_id,(SELECT COALESCE(MAX(id),0) FROM conversation_events WHERE conversation_id=? AND event_type='waiting_manager') AS request_event_id";
        $args=[$conversationId,$conversationId];
        if($forSnapshot){
            $sql.=",(SELECT COALESCE(MAX(id),0) FROM messages WHERE conversation_id=? AND direction='outbound' AND sender_type='manager') AS manager_message_id,(SELECT COALESCE(MAX(id),0) FROM conversation_events WHERE conversation_id=?) AS queue_event_id";
            array_push($args,$conversationId,$conversationId);
        }
        $q=$pdo->prepare($sql);$q->execute($args);return array_map('intval',$q->fetch(PDO::FETCH_ASSOC));
    }
    public static function snapshot(int $actor,int $conversationId): array
    {
        $pdo=ConversationDb::connection();$manager=self::actor($pdo,$actor);$c=$manager?ManagerConversationService::visibleConversation($conversationId,$actor):null;
        if(!$c)return self::error('forbidden');$view=self::view($pdo,$manager,$c);
        $fresh=ManagerConversationService::visibleConversation($conversationId,$actor);$freshManager=self::actor($pdo,$actor);
        if(!$fresh||!$freshManager||self::context($fresh,$freshManager)!==self::context($c,$manager))return self::error('forbidden');
        return ['ok'=>true,'reply_queue'=>$view];
    }
    public static function operation(int $actor,int $conversationId,string $operationId): array
    {
        if(!self::validOperation($operationId))return self::error('invalid_request');
        $snapshot=self::snapshot($actor,$conversationId);if(!$snapshot['ok'])return $snapshot;
        $receipt=self::receipt(ConversationDb::connection(),$actor,$operationId);
        if($receipt&&$receipt['conversation_id']!==$conversationId)return self::error('operation_mismatch');
        $snapshot=self::snapshot($actor,$conversationId);if(!$snapshot['ok'])return $snapshot;
        return ['ok'=>true,'found'=>$receipt!==null,'result'=>$receipt?self::publicResult($receipt['result']):null,'reply_queue'=>$snapshot['reply_queue']];
    }
    public static function mutate(int $actor,int $conversationId,string $action,array $input): array
    {
        $op=$input['operation_id']??null;$context=$input['context']??null;$version=$input['expected_version']??null;
        if(!in_array($action,['resolve','undo'],true)||!is_string($op)||!self::validOperation($op)||!is_string($context)||!preg_match('/\A[a-f0-9]{64}\z/D',$context)||!is_int($version)||$version<0)return self::error('invalid_request');
        $message=$input['through_message_id']??null;$request=$input['through_request_event_id']??null;
        if($action==='resolve'&&(!is_int($message)||$message<0||!is_int($request)||$request<0))return self::error('invalid_request');
        $undoActor=$input['resolution_actor_id']??null;$undoOp=$input['resolution_operation_id']??null;
        if($action==='undo'&&(!is_int($undoActor)||$undoActor<=0||!is_string($undoOp)||!self::validOperation($undoOp)))return self::error('invalid_request');
        $command=['conversation_id'=>$conversationId,'action'=>$action,'context'=>$context,'expected_version'=>$version]+($action==='resolve'?['through_message_id'=>$message,'through_request_event_id'=>$request]:['resolution_actor_id'=>$undoActor,'resolution_operation_id'=>$undoOp]);
        $hash=hash('sha256',json_encode($command,JSON_THROW_ON_ERROR));$pdo=ConversationDb::connection();
        if($pdo->inTransaction())throw new LogicException('Reply decisions require their own transaction');
        $pdo->beginTransaction();
        try{
            $manager=self::actor($pdo,$actor,true);$suffix=$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'?' FOR UPDATE':'';
            $q=$pdo->prepare('SELECT id,project_key,source_id,status,manager_id,channel,external_chat_id,started_at,last_message_at FROM conversations WHERE id=?'.$suffix);$q->execute([$conversationId]);$raw=$q->fetch(PDO::FETCH_ASSOC);
            $pinned=$manager&&$raw&&RoutingAccessService::lockConversationVisibilityInputs($actor,$raw);
            $c=$pinned?ManagerConversationService::visibleConversation($conversationId,$actor):null;
            if(!$c){$pdo->rollBack();return self::error('forbidden');}
            $receipt=self::receipt($pdo,$actor,$op);
            if($receipt){if($receipt['conversation_id']!==$conversationId||!hash_equals($receipt['hash'],$hash)){$pdo->rollBack();return self::error('operation_mismatch');}$result=self::publicResult($receipt['result']);$result['reply_queue']=self::view($pdo,$manager,$c);$pdo->commit();return $result;}
            if(!self::canMutate($manager,$c)){$pdo->rollBack();return self::error('forbidden');}
            if(!hash_equals(self::context($c,$manager),$context)){$pdo->rollBack();return self::error('context_changed');}
            $state=self::state($pdo,$conversationId);if($state['version']!==$version){$view=self::view($pdo,$manager,$c);$pdo->rollBack();return self::error('conflict')+['reply_queue'=>$view];}
            $previous=$state['current'];$now=time();
            if($action==='resolve'){
                if(!self::validFrontier($pdo,$conversationId,$message,$request)){$pdo->rollBack();return self::error('invalid_request');}
                if($message===0&&$request===0){$frontier=self::frontiers($conversationId);if($c['status']!=='waiting_manager'||$previous!==null||$frontier['request_event_id']!==0){$pdo->rollBack();return self::error('invalid_request');}}
                $current=['operation_id'=>$op,'actor_id'=>$actor,'recorded_at'=>$now,'through_message_id'=>$message,'through_request_event_id'=>$request];
            }else{
                if(!$previous||$previous['actor_id']!==$undoActor||$previous['operation_id']!==$undoOp){$pdo->rollBack();return self::error('conflict');}
                $origin=self::receipt($pdo,$undoActor,$undoOp);
                if(!$origin||$origin['conversation_id']!==$conversationId||!array_key_exists('before_resolution',$origin['result']))throw new RuntimeException('reply_queue_origin_unavailable');
                $current=self::resolution($origin['result']['before_resolution']);
            }
            ConversationControlService::event($conversationId,self::EVENT,'manager',$actor,['schema'=>1,'action'=>$action,'operation_id'=>$op,'current'=>$current]);
            $saved=(int)$pdo->lastInsertId();$result=['ok'=>true,'saved_version'=>$saved,'before_resolution'=>$previous];
            $q=$pdo->prepare('INSERT INTO manager_reply_queue_operations (manager_id,operation_id,conversation_id,request_hash,result_json,created_at) VALUES (?,?,?,?,?,?)');
            $q->execute([$actor,$op,$conversationId,$hash,json_encode($result,JSON_THROW_ON_ERROR),gmdate('Y-m-d H:i:s',$now)]);
            // Recheck after all source reads, before exposing the current projection.
            $fresh=ManagerConversationService::visibleConversation($conversationId,$actor);$freshManager=self::actor($pdo,$actor);
            if(!$fresh||!self::canMutate($freshManager,$fresh)||!hash_equals(self::context($fresh,$freshManager),$context)){$pdo->rollBack();return self::error('forbidden');}
            $result=self::publicResult($result);$result['reply_queue']=self::view($pdo,$freshManager,$fresh);$pdo->commit();return $result;
        }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
    }
    private static function validFrontier(PDO $pdo,int $cid,int $message,int $request): bool
    {
        if($message){$q=$pdo->prepare('SELECT id FROM messages m WHERE m.conversation_id=? AND m.id=? AND '.self::humanSql('m'));$q->execute([$cid,$message]);if(!$q->fetchColumn())return false;}
        if($request){$q=$pdo->prepare("SELECT id FROM conversation_events WHERE conversation_id=? AND id=? AND event_type='waiting_manager'");$q->execute([$cid,$request]);if(!$q->fetchColumn())return false;}
        return true;
    }
    private static function view(PDO $pdo,array $manager,array $c): array
    {
        $cid=(int)$c['id'];$state=self::state($pdo,$cid);$frontier=self::frontiers($cid);
        $mysql=$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql';
        $q=$pdo->prepare('SELECT CASE WHEN '.self::neededSql('c',$mysql).' THEN 1 ELSE 0 END AS needed,'.self::waitStartedSql('c',$mysql).' AS reply_wait_started_unix FROM conversations c WHERE c.id=?');$q->execute([$cid]);$row=self::decorate([$q->fetch(PDO::FETCH_ASSOC)])[0];
        $resolution=$state['current'];if($resolution){$q=$pdo->prepare('SELECT display_name,login FROM managers WHERE id=?');$q->execute([$resolution['actor_id']]);$name=$q->fetch(PDO::FETCH_ASSOC);$resolution['actor_name']=(string)(($name['display_name']??'')?:($name['login']??'Сотрудник'));}
        $can=self::canMutate($manager,$c);
        return ['schema'=>1,'context'=>self::context($c,$manager),'version'=>$state['version'],'needed'=>(bool)$row['needed'],'customer_message_id'=>$frontier['customer_message_id'],'request_event_id'=>$frontier['request_event_id'],'manager_message_id'=>$frontier['manager_message_id'],'queue_event_id'=>$frontier['queue_event_id'],
            'waiting_since_unix'=>$row['reply_wait_started_unix'],'waiting_since'=>$row['reply_wait_started_at'],'wait_age_seconds'=>$row['reply_wait_age_seconds'],'wait_time_known'=>$row['reply_wait_time_known'],
            'can_resolve'=>$can&&(bool)$row['needed'],'can_undo'=>$can&&$resolution!==null,'resolution'=>$resolution];
    }
    private static function state(PDO $pdo,int $cid): array
    {
        $q=$pdo->prepare("SELECT id,payload_json FROM conversation_events WHERE conversation_id=? AND event_type=? ORDER BY id DESC LIMIT 1");$q->execute([$cid,self::EVENT]);$r=$q->fetch(PDO::FETCH_ASSOC);
        if(!$r)return ['version'=>0,'current'=>null];$payload=json_decode((string)$r['payload_json'],true,32,JSON_THROW_ON_ERROR);
        if(($payload['schema']??null)!==1||!array_key_exists('current',$payload))throw new RuntimeException('reply_queue_state_invalid');
        return ['version'=>(int)$r['id'],'current'=>self::resolution($payload['current'])];
    }
    private static function resolution($value): ?array
    {
        if($value===null)return null;
        if(!is_array($value)||!is_int($value['actor_id']??null)||$value['actor_id']<=0||!is_string($value['operation_id']??null)||!self::validOperation($value['operation_id'])||!is_int($value['recorded_at']??null)||!is_int($value['through_message_id']??null)||$value['through_message_id']<0||!is_int($value['through_request_event_id']??null)||$value['through_request_event_id']<0)throw new RuntimeException('reply_queue_resolution_invalid');
        return array_intersect_key($value,array_flip(['actor_id','operation_id','recorded_at','through_message_id','through_request_event_id']));
    }
    private static function receipt(PDO $pdo,int $actor,string $op): ?array
    {
        $q=$pdo->prepare('SELECT conversation_id,request_hash,result_json FROM manager_reply_queue_operations WHERE manager_id=? AND operation_id=?');$q->execute([$actor,$op]);$r=$q->fetch(PDO::FETCH_ASSOC);
        return $r?['conversation_id'=>(int)$r['conversation_id'],'hash'=>(string)$r['request_hash'],'result'=>json_decode($r['result_json'],true,32,JSON_THROW_ON_ERROR)]:null;
    }
    private static function actor(PDO $pdo,int $id,bool $lock=false): ?array
    {
        $suffix=$lock&&$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'?' FOR UPDATE':'';$q=$pdo->prepare('SELECT id,role,is_active FROM managers WHERE id=?'.$suffix);$q->execute([$id]);$r=$q->fetch(PDO::FETCH_ASSOC);return $r&&(int)$r['is_active']===1?$r:null;
    }
    private static function canMutate(?array $manager,array $c): bool {return $manager!==null&&in_array($c['status'],['manager','waiting_manager'],true)&&ManagerConversationAccessPolicy::canEditVisibleConversation($c,$manager);}
    private static function context(array $c,array $manager): string {return hash('sha256',json_encode(['actor_id'=>(string)$manager['id'],'actor_role'=>(string)$manager['role'],'conversation'=>array_map(static fn($k)=>(string)($c[$k]??''),['id','manager_id','project_key','source_id','channel','status','external_chat_id'])],JSON_THROW_ON_ERROR));}
    private static function validOperation(string $op): bool {return preg_match('/\A[A-Za-z0-9_-]{16,96}\z/D',$op)===1;}
    private static function publicResult(array $result): array {unset($result['before_resolution']);return $result;}
    private static function error(string $error): array {return ['ok'=>false,'error'=>$error];}
}
