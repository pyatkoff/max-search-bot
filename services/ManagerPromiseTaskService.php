<?php
declare(strict_types=1);

require_once __DIR__.'/ManagerRequestProfileService.php';
require_once __DIR__.'/ConversationRecordClock.php';

/** Explicit provenance for existing tasks. This owner never writes lead_tasks. */
final class ManagerPromiseTaskService
{
    private const FLAGS=JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR;
    private const KINDS=['send_calculation','send_selection'];
    private const COMMAND=['operation_id','task_id','kind','trip_id','profile_version','profile_sha256',
        'profile_context_sha256','source_message_id','source_message_revision','deadline'];

    public static function context(int $actor,int $cid,int $taskId,int $sourceId,string $kind): array
    {
        if($taskId<=0||$sourceId<=0||!in_array($kind,self::KINDS,true))return self::error('invalid_binding');
        return self::transaction($actor,$cid,static function(PDO $pdo,array $scope)use($actor,$cid,$taskId,$sourceId,$kind):array{
            $task=self::task($pdo,$cid,$taskId);$pins=self::pins($pdo,$scope['conversation'],$sourceId);
            $id=self::promiseId($scope['conversation'],$pins['profile']['trip_id'],$kind,$sourceId);
            $record=self::record($pdo,$id);$bound=self::taskBinding($pdo,$taskId);
            return ['ok'=>true,'viewer_id'=>$actor,'conversation_id'=>$cid,
                'can_bind'=>$scope['can_edit']&&$task['status']==='open'&&(!$bound||$bound['promise_id']===$id)
                    &&(!$record||(int)$record['task_id']===$taskId),
                'task'=>$task,'profile'=>$pins['profile'],'source'=>$pins['source'],
                'binding'=>$record?self::bindingView($pdo,$scope['conversation'],$record):null];
        });
    }

    public static function bind(int $actor,int $cid,array $input): array
    {
        try{$command=self::command($input);}catch(DomainException $e){return self::error($e->getMessage());}
        $request=self::hash($command);
        return self::transaction($actor,$cid,static function(PDO $pdo,array $scope)use($actor,$cid,$command,$request):array{
            // Reconcile an admitted operation before testing a newer transcript/profile.
            $q=$pdo->prepare('SELECT conversation_id,promise_id,request_sha256 FROM manager_task_promise_operations WHERE manager_id=? AND operation_id=?'.self::lock($pdo));
            $q->execute([$actor,$command['operation_id']]);$receipt=$q->fetch(PDO::FETCH_ASSOC);$q->closeCursor();
            if($receipt){
                self::validateReceipt($receipt);
                if((int)$receipt['conversation_id']!==$cid||!hash_equals((string)$receipt['request_sha256'],$request))return self::error('operation_mismatch');
                $record=self::record($pdo,(string)$receipt['promise_id']);
                if(!$record)throw new RuntimeException('promise_receipt_without_binding');
                return self::result($pdo,$scope['conversation'],$record,true,true);
            }
            if(!$scope['can_edit'])return self::error('forbidden');
            $pins=self::pins($pdo,$scope['conversation'],$command['source_message_id']);$profile=$pins['profile'];
            if($command['trip_id']!==$profile['trip_id']||$command['profile_version']!==$profile['version']
                ||!hash_equals($command['profile_sha256'],$profile['sha256']))return self::error('profile_changed');
            if(!hash_equals($command['profile_context_sha256'],$profile['context_sha256']))return self::error('context_changed');
            if(!hash_equals($command['source_message_revision'],$pins['source']['revision']))return self::error('source_changed');
            $id=self::promiseId($scope['conversation'],$command['trip_id'],$command['kind'],$command['source_message_id']);
            $record=self::record($pdo,$id);
            $deadline=json_encode($command['deadline'],self::FLAGS);$reused=$record!==null;
            if($record){
                if((int)$record['task_id']!==$command['task_id']||$record['deadline_json']!==$deadline)return self::error('binding_conflict');
                if(!hash_equals($record['source_message_revision'],$command['source_message_revision']))return self::error('source_changed');
                // Title, deadline, assignment, pin and done/reopen remain entirely task-owned.
                self::bindingView($pdo,$scope['conversation'],$record);
            }else{
                $task=self::task($pdo,$cid,$command['task_id']);
                if(self::taskBinding($pdo,$command['task_id']))return self::error('binding_conflict');
                if($task['status']!=='open')return self::error('task_closed');
                $identity=json_encode(self::identity($scope['conversation']),self::FLAGS);
                if(strlen($identity)>2048)throw new RuntimeException('promise_scope_too_large');
                $q=$pdo->prepare('INSERT INTO manager_task_promises (promise_id,conversation_id,trip_id,kind,source_message_id,source_message_revision,source_author_manager_id,profile_version,profile_sha256,profile_context_sha256,task_id,deadline_json,scope_json,created_by_manager_id,created_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
                $q->execute([$id,$cid,$command['trip_id'],$command['kind'],$command['source_message_id'],
                    $command['source_message_revision'],$pins['source']['author_manager_id'],$command['profile_version'],
                    $command['profile_sha256'],$command['profile_context_sha256'],$command['task_id'],$deadline,$identity,$actor,gmdate('Y-m-d H:i:s')]);
                if($q->rowCount()!==1)throw new RuntimeException('promise_binding_unconfirmed');
                $record=self::record($pdo,$id);if(!$record)throw new RuntimeException('promise_binding_missing');
            }
            $q=$pdo->prepare('INSERT INTO manager_task_promise_operations (manager_id,operation_id,conversation_id,promise_id,request_sha256,created_at) VALUES (?,?,?,?,?,?)');
            $q->execute([$actor,$command['operation_id'],$cid,$id,$request,gmdate('Y-m-d H:i:s')]);
            if($q->rowCount()!==1)throw new RuntimeException('promise_operation_unconfirmed');
            return self::result($pdo,$scope['conversation'],$record,false,$reused);
        });
    }

    public static function operation(int $actor,int $cid,string $operation): array
    {
        if(!self::hex($operation,32))return self::error('invalid_operation');
        return self::transaction($actor,$cid,static function(PDO $pdo,array $scope)use($actor,$cid,$operation):array{
            $q=$pdo->prepare('SELECT conversation_id,promise_id,request_sha256 FROM manager_task_promise_operations WHERE manager_id=? AND operation_id=?');
            $q->execute([$actor,$operation]);$receipt=$q->fetch(PDO::FETCH_ASSOC);$q->closeCursor();
            if(!$receipt)return ['ok'=>true,'found'=>false,'promise_id'=>null,'task_id'=>null,'task'=>null];
            self::validateReceipt($receipt);
            if((int)$receipt['conversation_id']!==$cid)return self::error('operation_mismatch');
            $record=self::record($pdo,(string)$receipt['promise_id']);if(!$record)throw new RuntimeException('promise_receipt_without_binding');
            $view=self::bindingView($pdo,$scope['conversation'],$record);
            return ['ok'=>true,'found'=>true,'promise_id'=>$record['promise_id'],'task_id'=>$view['task_id'],'task'=>$view['task']];
        });
    }

    /** Bounded immutable records for an already authorized read transaction. */
    public static function readBindings(PDO $pdo,array $conversation,array $taskIds): array
    {
        if(!$pdo->inTransaction()||$pdo!==ConversationDb::connection())throw new LogicException('promise_read_transaction_required');
        $cid=(int)($conversation['id']??0);
        if($cid<=0||($conversation['project_key']??null)!=='anytour'||count($taskIds)>50)throw new InvalidArgumentException('invalid_promise_read');
        foreach($taskIds as $id)if(!is_int($id)||$id<=0)throw new InvalidArgumentException('invalid_promise_read');
        $taskIds=array_values(array_unique($taskIds));if(!$taskIds)return [];
        $binary=$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'?'BINARY':'BLOB';
        $q=$pdo->prepare('SELECT promise_id,conversation_id,trip_id,kind,source_message_id,source_message_revision,source_author_manager_id,profile_version,profile_sha256,profile_context_sha256,task_id,created_by_manager_id,created_at,SUBSTR(CAST(scope_json AS '.$binary.'),1,2049) AS scope_json,SUBSTR(CAST(deadline_json AS '.$binary.'),1,513) AS deadline_json FROM manager_task_promises WHERE conversation_id=? AND task_id IN ('.implode(',',array_fill(0,count($taskIds),'?')).') ORDER BY task_id LIMIT 50');
        $q->execute([$cid,...$taskIds]);$records=$q->fetchAll(PDO::FETCH_ASSOC);$q->closeCursor();$out=[];
        foreach($records as $row){
            if((int)$row['conversation_id']!==$cid||!self::hex($row['promise_id'],64)||!self::hex($row['trip_id'],32)
                ||!in_array($row['kind'],self::KINDS,true)||!self::hex($row['source_message_revision'],64)
                ||!self::hex($row['profile_sha256'],64)||!self::hex($row['profile_context_sha256'],64)
                ||(int)$row['task_id']<=0||(int)$row['source_message_id']<=0||(int)$row['source_author_manager_id']<=0
                ||(int)$row['profile_version']<1||strlen($row['scope_json'])>2048||strlen($row['deadline_json'])>512
                ||$row['promise_id']!==self::promiseId($conversation,$row['trip_id'],$row['kind'],(int)$row['source_message_id']))throw new RuntimeException('invalid_promise_storage');
            $stored=json_decode($row['scope_json'],true,16,JSON_THROW_ON_ERROR);
            if(!is_array($stored)||array_keys($stored)!==array_keys(self::identity($conversation))
                ||($stored['conversation_id']??null)!==$cid||($stored['project_key']??null)!=='anytour'
                ||!is_int($stored['customer_id']??null)||$stored['customer_id']<=0
                ||!is_string($stored['channel']??null)||!is_string($stored['external_chat_id']??null))throw new RuntimeException('invalid_promise_scope');
            // A historical recipient/source mismatch is data for the reader,
            // never authority to rebind or silently present it as current.
            $row['_scope']=$stored;$row['_deadline']=self::deadline(json_decode($row['deadline_json'],true,8,JSON_THROW_ON_ERROR));
            $out[(int)$row['task_id']]=$row;
        }
        return $out;
    }

    private static function transaction(int $actor,int $cid,callable $work): array
    {
        if($actor<=0||$cid<=0)return self::error('forbidden');
        $pdo=null;
        try{
            $pdo=ConversationDb::connection();if($pdo->inTransaction())return self::error('storage_unavailable');
            ProjectAccessService::initializeReadOnly();
            if($pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql')$pdo->exec('SET TRANSACTION ISOLATION LEVEL READ COMMITTED');
            $pdo->beginTransaction();
            // Same lock order as manager profile saves; ACL rows use their current-read owner.
            $manager=ManagerAuthService::byId($actor,true);
            if(!$manager){$pdo->rollBack();return self::error('forbidden');}
            $q=$pdo->prepare('SELECT id FROM conversations WHERE id=?'.self::lock($pdo));$q->execute([$cid]);$exists=$q->fetchColumn();$q->closeCursor();
            $conversation=$exists?ManagerConversationService::visibleConversation($cid,$actor,false,true):null;
            if(!$conversation||($conversation['project_key']??null)!=='anytour'){$pdo->rollBack();return self::error('forbidden');}
            $scope=['conversation'=>$conversation,'can_edit'=>$conversation['status']!=='closed'
                &&ManagerConversationAccessPolicy::canEditVisibleConversation($conversation,$manager)];
            $result=$work($pdo,$scope);
            if(empty($result['ok']))$pdo->rollBack();else $pdo->commit();
            return $result;
        }catch(DomainException $e){if($pdo&&$pdo->inTransaction())$pdo->rollBack();return self::error($e->getMessage());}
        catch(Throwable $e){if($pdo&&$pdo->inTransaction())$pdo->rollBack();return self::error('storage_unavailable');}
    }

    private static function pins(PDO $pdo,array $conversation,int $sourceId): array
    {
        $snapshot=ManagerRequestProfileService::forTaskBinding($pdo,$conversation,$sourceId);
        $source=$snapshot['source'];$profile=$snapshot['profile'];
        if(!$source||$source['unavailable']||$sourceId<$profile['boundary_message_id'])throw new DomainException('source_unavailable');
        $binary=$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'?'BINARY':'BLOB';
        $q=$pdo->prepare('SELECT direction,sender_type,sender_id,channel,external_message_id,SUBSTR(CAST(metadata_json AS '.$binary.'),1,8193) AS metadata FROM messages WHERE conversation_id=? AND id=?'.self::lock($pdo));
        $q->execute([(int)$conversation['id'],$sourceId]);$row=$q->fetch(PDO::FETCH_ASSOC);$q->closeCursor();
        $evidence=self::sourceEvidence($row?:[],$conversation,$source,true);
        if($evidence===null)throw new DomainException('source_unavailable');
        $q=$pdo->prepare('SELECT id FROM managers WHERE id=?');$q->execute([$evidence['author_manager_id']]);$known=$q->fetchColumn();$q->closeCursor();
        if(!$known)throw new DomainException('source_unavailable');
        return ['profile'=>$profile,'source'=>$evidence];
    }

    /** Pure shared admission; caller owns the authorized snapshot and author lookup. */
    public static function sourceEvidence(array $row,array $conversation,array $source,bool $knownAuthor): ?array
    {
        $raw=(string)($row['metadata']??'');$meta=$raw===''?[]:json_decode($raw,true,32);
        $author=(string)($row['sender_id']??'');$native=(string)($row['external_message_id']??'');
        if(!$knownAuthor||($row['direction']??null)!=='outbound'||($row['sender_type']??null)!=='manager'||!ctype_digit($author)||(int)$author<=0
            ||($row['channel']??null)!==($conversation['channel']??null)||trim($native)===''||strlen($native)>255
            ||strlen($raw)>8192||!is_array($meta)||($meta['type']??'message')!=='message'
            ||!empty($meta['linked_message'])||!empty($meta['forwarded'])||!empty($meta['forward_origin'])
            ||($source['unavailable']??true)!==false||!is_int($source['id']??null)||$source['id']<=0
            ||!self::hex($source['sha256']??null,64)||!is_string($source['text']??null)
            ||trim($source['text'])===''||mb_strlen($source['text'])>6000)return null;
        $clock=ConversationRecordClock::read($raw);
        return ['id'=>$source['id'],'author_manager_id'=>(int)$author,
            'revision'=>self::hash([$source['sha256'],$row['channel'],$author,$native]),'text'=>$source['text'],
            'recorded_at_utc'=>$clock===null?null:gmdate('Y-m-d\TH:i:s\Z',$clock)];
    }

    private static function command(array $input): array
    {
        if(count($input)!==count(self::COMMAND)||array_diff(array_keys($input),self::COMMAND))throw new DomainException('invalid_binding');
        if(!self::hex($input['operation_id']??null,32)||!is_int($input['task_id']??null)||$input['task_id']<=0
            ||!in_array($input['kind']??null,self::KINDS,true)||!self::hex($input['trip_id']??null,32)
            ||!is_int($input['profile_version']??null)||$input['profile_version']<1
            ||!self::hex($input['profile_sha256']??null,64)||!self::hex($input['profile_context_sha256']??null,64)
            ||!is_int($input['source_message_id']??null)||$input['source_message_id']<=0
            ||!self::hex($input['source_message_revision']??null,64))throw new DomainException('invalid_binding');
        $out=[];foreach(self::COMMAND as $key)$out[$key]=$input[$key];$out['deadline']=self::deadline($input['deadline']);return $out;
    }

    private static function deadline(mixed $value): ?array
    {
        if($value===null)return null;
        if(!is_array($value)||!is_string($value['value']??null))throw new DomainException('invalid_deadline');
        $date=$value['value'];
        if(($value['kind']??null)==='date'){
            if(count($value)!==3||($value['zone']??null)!=='Europe/Moscow'
                ||!preg_match('/\A(\d{4})-(\d{2})-(\d{2})\z/D',$date,$m)||(int)$m[1]<1000
                ||!checkdate((int)$m[2],(int)$m[3],(int)$m[1]))throw new DomainException('invalid_deadline');
            return ['kind'=>'date','value'=>$date,'zone'=>'Europe/Moscow'];
        }
        if(($value['kind']??null)!=='instant'||count($value)!==2
            ||!preg_match('/\A(\d{4})-(\d{2})-(\d{2})T(?:[01]\d|2[0-3]):[0-5]\d:[0-5]\d(?:Z|[+-](?:0\d|1[0-4]):[0-5]\d)\z/D',$date,$m)
            ||(int)$m[1]<1000||!checkdate((int)$m[2],(int)$m[3],(int)$m[1])
            ||preg_match('/[+-]14:(?!00)/',$date))throw new DomainException('invalid_deadline');
        return ['kind'=>'instant','value'=>$date];
    }

    private static function task(PDO $pdo,int $cid,int $id): array
    {
        $q=$pdo->prepare('SELECT id,conversation_id,title,due_at_utc,status,is_pinned,assigned_manager_id FROM lead_tasks WHERE id=? AND conversation_id=?'.self::lock($pdo));
        $q->execute([$id,$cid]);$row=$q->fetch(PDO::FETCH_ASSOC);$q->closeCursor();
        if(!$row)throw new DomainException('task_not_found');
        if(!in_array($row['status'],['open','done'],true))throw new RuntimeException('invalid_task_state');
        $row['id']=(int)$row['id'];$row['conversation_id']=(int)$row['conversation_id'];$row['is_pinned']=(bool)$row['is_pinned'];
        $row['assigned_manager_id']=$row['assigned_manager_id']===null?null:(int)$row['assigned_manager_id'];return $row;
    }

    private static function record(PDO $pdo,string $id): ?array
    {
        $q=$pdo->prepare('SELECT * FROM manager_task_promises WHERE promise_id=?'.self::lock($pdo));$q->execute([$id]);
        $row=$q->fetch(PDO::FETCH_ASSOC);$q->closeCursor();return $row?:null;
    }
    private static function taskBinding(PDO $pdo,int $task): ?array
    {
        $q=$pdo->prepare('SELECT promise_id FROM manager_task_promises WHERE task_id=?'.self::lock($pdo));$q->execute([$task]);
        $row=$q->fetch(PDO::FETCH_ASSOC);$q->closeCursor();return $row?:null;
    }
    private static function validateReceipt(array $row): void
    {
        if((int)$row['conversation_id']<=0||!self::hex($row['promise_id'],64)||!self::hex($row['request_sha256'],64))throw new RuntimeException('invalid_promise_receipt');
    }
    private static function bindingView(PDO $pdo,array $conversation,array $record): array
    {
        $cid=(int)$conversation['id'];
        if((int)$record['conversation_id']!==$cid||!self::hex($record['promise_id'],64)||!self::hex($record['trip_id'],32)
            ||!in_array($record['kind'],self::KINDS,true)||!self::hex($record['source_message_revision'],64)
            ||!self::hex($record['profile_sha256'],64)||!self::hex($record['profile_context_sha256'],64)
            ||(int)$record['task_id']<=0||(int)$record['source_message_id']<=0||(int)$record['source_author_manager_id']<=0
            ||(int)$record['profile_version']<1||strlen($record['scope_json'])>2048||strlen($record['deadline_json'])>512
            ||json_decode($record['scope_json'],true,16,JSON_THROW_ON_ERROR)!==self::identity($conversation)
            ||$record['promise_id']!==self::promiseId($conversation,$record['trip_id'],$record['kind'],(int)$record['source_message_id']))throw new RuntimeException('invalid_promise_storage');
        try{$deadline=self::deadline(json_decode($record['deadline_json'],true,8,JSON_THROW_ON_ERROR));}
        catch(Throwable $e){throw new RuntimeException('invalid_promise_deadline',0,$e);}
        try{$task=self::task($pdo,$cid,(int)$record['task_id']);}catch(DomainException $e){throw new RuntimeException('promise_task_missing',0,$e);}
        return ['promise_id'=>$record['promise_id'],'conversation_id'=>$cid,'trip_id'=>$record['trip_id'],'kind'=>$record['kind'],
            'task_id'=>(int)$record['task_id'],'source_message_id'=>(int)$record['source_message_id'],
            'source_message_revision'=>$record['source_message_revision'],'source_author_manager_id'=>(int)$record['source_author_manager_id'],
            'profile_version'=>(int)$record['profile_version'],'profile_sha256'=>$record['profile_sha256'],
            'deadline'=>$deadline,'created_by_manager_id'=>(int)$record['created_by_manager_id'],'created_at'=>$record['created_at'],'task'=>$task];
    }
    private static function result(PDO $pdo,array $conversation,array $record,bool $replayed,bool $reused): array
    {
        $binding=self::bindingView($pdo,$conversation,$record);
        return ['ok'=>true,'id'=>$binding['task_id'],'promise_id'=>$binding['promise_id'],'replayed'=>$replayed,'reused'=>$reused,
            'binding'=>$binding,'task'=>$binding['task']];
    }
    private static function identity(array $c): array
    {return ['conversation_id'=>(int)$c['id'],'project_key'=>(string)$c['project_key'],'customer_id'=>(int)$c['customer_id'],
        'channel'=>(string)$c['channel'],'external_chat_id'=>(string)$c['external_chat_id']];}
    private static function promiseId(array $c,string $trip,string $kind,int $source): string
    {return self::hash([(string)$c['project_key'],(int)$c['id'],$trip,$kind,$source]);}
    private static function hash(array $value): string{return hash('sha256',json_encode($value,self::FLAGS));}
    private static function hex(mixed $value,int $length): bool{return is_string($value)&&preg_match('/\A[a-f0-9]{'.$length.'}\z/D',$value)===1;}
    private static function lock(PDO $pdo): string{return $pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'?' FOR UPDATE':'';}
    private static function error(string $code): array{return ['ok'=>false,'error'=>$code];}
}
