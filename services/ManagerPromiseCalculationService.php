<?php
declare(strict_types=1);
require_once __DIR__.'/ManagerPromiseTaskService.php';
require_once __DIR__.'/ManagerReplyQueueService.php';
require_once __DIR__.'/LeadTaskService.php';
require_once __DIR__.'/ConversationRecorder.php';

/** Explicit composer composition. SendAttempt owns transport; LeadTask owns completion. */
final class ManagerPromiseCalculationService
{
    public const MESSAGE_META='_manager_calculation';
    private const FLAGS=JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_LINE_TERMINATORS|JSON_THROW_ON_ERROR;

    public static function catalog(int $actor,int $cid): array
    {
        return self::transaction($actor,$cid,static function(PDO $pdo,array $s)use($cid):array{
            $q=$pdo->prepare("SELECT p.task_id FROM manager_task_promises p JOIN lead_tasks t ON t.id=p.task_id AND t.conversation_id=p.conversation_id LEFT JOIN manager_promise_calculation_intents i ON i.active_promise_id=p.promise_id WHERE p.conversation_id=? AND p.kind='send_calculation' ORDER BY CASE WHEN i.intent_id IS NOT NULL THEN 0 WHEN t.status='open' THEN 1 ELSE 2 END,p.task_id DESC LIMIT 50");
            $q->execute([$cid]);$ids=array_map('intval',$q->fetchAll(PDO::FETCH_COLUMN));$q->closeCursor();
            $bindings=ManagerPromiseTaskService::readBindings($pdo,$s['conversation'],$ids);$entries=[];
            // The provenance reader intentionally orders by task ID; retain
            // this catalog's active/open priority after that bounded batch read.
            foreach($ids as $id){
                $binding=$bindings[$id]??null;if(!$binding)continue;
                $task=self::task($pdo,$cid,$id);if(!$task)continue;
                $history=self::history($pdo,$cid,$binding['promise_id']);$reason=null;
                if($history){try{self::disclose($history,$s['conversation']);}catch(DomainException $e){$history=null;}}
                try{self::capture($pdo,$s,$id,$binding['promise_id']);}
                catch(DomainException $e){$reason=$e->getMessage();}
                $entries[]=['task_id'=>$id,'promise_id'=>$binding['promise_id'],'kind'=>'send_calculation','task'=>$task,
                    'can_arm'=>$reason===null,'reason'=>$reason,'intent'=>$history?self::view($pdo,$history):null];
            }
            return ['ok'=>true,'can_edit'=>$s['can_send'],'entries'=>$entries];
        });
    }

    public static function preview(int $actor,int $cid,int $taskId,string $promiseId,array $composition): array
    {
        try{$composition=self::composition($composition);}catch(DomainException $e){return self::error($e->getMessage());}
        if($taskId<=0||!self::hex($promiseId,64))return self::error('invalid_input');
        return self::transaction($actor,$cid,static function(PDO $pdo,array $s)use($taskId,$promiseId,$composition):array{
            $capture=self::capture($pdo,$s,$taskId,$promiseId);
            return self::previewView($capture,$composition);
        });
    }

    public static function arm(int $actor,int $cid,array $input): array
    {
        if(!self::keys($input,['operation_id','task_id','promise_id','composition','preview_sha256'])
            ||!self::hex($input['operation_id']??null,32)||!is_int($input['task_id']??null)||$input['task_id']<=0
            ||!self::hex($input['promise_id']??null,64)||!self::hex($input['preview_sha256']??null,64)
            ||!is_array($input['composition']??null))return self::error('invalid_input');
        try{$input['composition']=self::composition($input['composition']);}catch(DomainException $e){return self::error($e->getMessage());}
        $hash=self::hash(['arm',$cid,$input['operation_id'],$input['task_id'],$input['promise_id'],$input['composition'],$input['preview_sha256']]);
        return self::transaction($actor,$cid,static function(PDO $pdo,array $s)use($actor,$cid,$input,$hash):array{
            $receipt=self::operationRow($pdo,$actor,$input['operation_id']);
            if($receipt)return self::replay($pdo,$cid,$receipt,'arm',$hash,$s['conversation']);
            $capture=self::capture($pdo,$s,$input['task_id'],$input['promise_id']);$view=self::previewView($capture,$input['composition']);
            if(!hash_equals($view['preview_sha256'],$input['preview_sha256']))throw new DomainException('preview_changed');
            $id=bin2hex(random_bytes(16));$now=gmdate('Y-m-d H:i:s');$p=$capture['profile'];$source=$capture['source'];
            $q=$pdo->prepare('INSERT INTO manager_promise_calculation_intents (intent_id,conversation_id,promise_id,task_id,task_revision,trip_id,profile_version,profile_sha256,source_message_id,source_message_revision,source_author_manager_id,scope_json,composition_json,manifest_sha256,created_by_manager_id,state,reason,active_promise_id,evidence_json,created_at,updated_at,completed_at,cancelled_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
            $q->execute([$id,$cid,$input['promise_id'],$input['task_id'],$capture['task']['business_revision'],$p['trip_id'],$p['version'],$p['sha256'],
                $source['id'],$source['revision'],$source['author_manager_id'],self::json($capture['scope']),self::json($input['composition']),
                $view['manifest_sha256'],$actor,'armed',null,$input['promise_id'],null,$now,$now,null,null]);
            self::writeOperation($pdo,$actor,$cid,$input['operation_id'],'arm',$id,$hash);
            return ['ok'=>true,'replayed'=>false,'intent'=>self::view($pdo,self::intent($pdo,$cid,$id))];
        });
    }

    public static function cancel(int $actor,int $cid,array $input): array
    {
        if(!self::keys($input,['operation_id','intent_id'])||!self::hex($input['operation_id']??null,32)||!self::hex($input['intent_id']??null,32))return self::error('invalid_input');
        $hash=self::hash(['cancel',$cid,$input['operation_id'],$input['intent_id']]);
        return self::transaction($actor,$cid,static function(PDO $pdo,array $s)use($actor,$cid,$input,$hash):array{
            $receipt=self::operationRow($pdo,$actor,$input['operation_id']);if($receipt)return self::replay($pdo,$cid,$receipt,'cancel',$hash,$s['conversation']);
            if(!$s['can_edit'])throw new DomainException('forbidden');
            $row=self::intent($pdo,$cid,$input['intent_id']);self::disclose($row,$s['conversation']);
            if($row['state']==='armed')self::terminal($pdo,$row,'cancelled',null);
            self::writeOperation($pdo,$actor,$cid,$input['operation_id'],'cancel',$row['intent_id'],$hash);
            return ['ok'=>true,'replayed'=>false,'intent'=>self::view($pdo,self::intent($pdo,$cid,$row['intent_id']))];
        });
    }

    public static function operation(int $actor,int $cid,string $id): array
    {
        if(!self::hex($id,32))return self::error('invalid_input');
        return self::transaction($actor,$cid,static function(PDO $pdo,array $s)use($actor,$cid,$id):array{
            $r=self::operationRow($pdo,$actor,$id);
            if(!$r)return ['ok'=>true,'found'=>false,'action'=>null,'request_sha256'=>null,'intent'=>null];
            if((int)$r['conversation_id']!==$cid)throw new DomainException('operation_mismatch');
            $intent=self::intent($pdo,$cid,$r['intent_id']);self::disclose($intent,$s['conversation']);
            return ['ok'=>true,'found'=>true,'action'=>$r['action'],'request_sha256'=>$r['request_sha256'],'intent'=>self::view($pdo,$intent)];
        });
    }

    public static function status(int $actor,int $cid,string $id): array
    {
        if(!self::hex($id,32))return self::error('invalid_input');
        return self::transaction($actor,$cid,static function(PDO $pdo,array $s)use($cid,$id):array{
            $row=self::intent($pdo,$cid,$id);self::disclose($row,$s['conversation']);
            return ['ok'=>true,'intent'=>self::view($pdo,$row)];
        });
    }

    /**
     * Optional, finite facts for an already-authorized task projection. The
     * caller owns the surrounding ACL, task/profile snapshot and final fences.
     * No composition, native identity or operation is returned to the plan.
     */
    public static function forTaskProjection(PDO $pdo,array $c,array $binding,array $task,?array $boundary,array $stale,int &$remaining): array
    {
        self::requireTransaction($pdo);
        $empty=static fn(string $availability,?string $reason=null):array=>['availability'=>$availability,'reason'=>$reason,
            'intent'=>null,'stale_reasons'=>[],'progress'=>null];
        if(($binding['kind']??null)!=='send_calculation')return $empty('not_applicable');
        try{
            $cid=(int)$c['id'];$binary=$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'?'BINARY':'BLOB';
            $q=$pdo->prepare('SELECT intent_id,LENGTH(CAST(composition_json AS '.$binary.'))+LENGTH(CAST(scope_json AS '.$binary.'))+COALESCE(LENGTH(CAST(evidence_json AS '.$binary.')),0)+2048 AS read_bytes FROM manager_promise_calculation_intents WHERE conversation_id=? AND task_id=? AND promise_id=? ORDER BY CASE WHEN active_promise_id IS NOT NULL THEN 0 ELSE 1 END,created_at DESC,intent_id DESC LIMIT 1'.self::lock($pdo));
            $q->execute([$cid,(int)$binding['task_id'],$binding['promise_id']]);$meta=$q->fetch(PDO::FETCH_ASSOC);$q->closeCursor();
            if(!$meta)return $empty('none');
            self::projectionBytes($remaining,(int)$meta['read_bytes']);
            $intent=self::intent($pdo,$cid,$meta['intent_id']);
            foreach(['promise_id','trip_id','profile_sha256','source_message_revision'] as $key)
                if($intent[$key]!==$binding[$key])throw new RuntimeException('calculation_binding_changed');
            foreach(['task_id','profile_version','source_message_id','source_author_manager_id'] as $key)
                if((int)$intent[$key]!== (int)$binding[$key])throw new RuntimeException('calculation_binding_changed');
            if(($intent['state']==='armed')!==($intent['active_promise_id']===$intent['promise_id'])
                ||($intent['state']!=='armed'&&$intent['active_promise_id']!==null)
                ||($intent['reason']!==null&&(!is_string($intent['reason'])||strlen($intent['reason'])>40)))throw new RuntimeException('invalid_calculation_state');
            $issues=$stale;$identity=null;
            try{$identity=self::senderIdentity($c);}catch(DomainException $e){}
            $stored=$intent['_scope']['identity'];
            if($identity!==$stored)$issues[]='scope_changed';
            $private=$identity!==null&&$binding['_scope']===self::promiseIdentity($c)
                &&array_diff_key($identity,['manager_id'=>true,'status'=>true])===array_diff_key($stored,['manager_id'=>true,'status'=>true]);
            if(!$private)return $empty('unavailable','scope_changed');
            if($boundary===null)$issues[]='context_unavailable';
            elseif($boundary!==$intent['_scope']['reply_boundary'])$issues[]='context_changed';
            $expectedRevision=$intent['state']==='completed'?self::projectionCompletionRevision($intent):(int)$intent['task_revision'];
            if($task['business_revision']!==$expectedRevision)$issues[]='task_changed';
            if($intent['state']==='armed'&&$task['status']!=='open')$issues[]='task_closed';
            $result=['availability'=>'available','reason'=>null,'intent'=>array_intersect_key($intent,array_flip(['intent_id','state','reason','created_at','completed_at','cancelled_at'])),
                'stale_reasons'=>[],'progress'=>['availability'=>'unavailable','reason'=>'scope_changed','confirmed'=>null,'total'=>count(self::parts($intent['_composition']))]];
            try{$result['progress']=self::projectionProgress($pdo,$intent,$remaining);}
            catch(Throwable $e){$code=self::projectionFailure($e);$result['progress']=['availability'=>'unavailable','reason'=>$code,'confirmed'=>null,'total'=>count(self::parts($intent['_composition']))];$issues[]=$code;}
            // The existing outer profile fence also rechecks transport. This
            // exact identity includes the customer-channel row itself.
            if($identity!==null&&self::senderIdentity($c)!==$identity)throw new DomainException('scope_changed');
            $result['stale_reasons']=array_values(array_unique($issues));return $result;
        }catch(Throwable $e){return $empty('unavailable',self::projectionFailure($e));}
    }

    private static function projectionProgress(PDO $pdo,array $intent,int &$remaining): array
    {
        $binary=$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'?'BINARY':'BLOB';$parts=self::parts($intent['_composition']);
        $q=$pdo->prepare('SELECT d.manager_id,d.operation_id,d.part_index,LENGTH(CAST(d.content_json AS '.$binary.'))+COALESCE(LENGTH(CAST(d.native_json AS '.$binary.')),0)+1024 AS read_bytes FROM manager_promise_calculation_deliveries d WHERE d.intent_id=? AND d.active_part=1 ORDER BY d.part_index LIMIT 11'.self::lock($pdo));
        $q->execute([$intent['intent_id']]);$metadata=$q->fetchAll(PDO::FETCH_ASSOC);$q->closeCursor();
        if(count($metadata)>count($parts))throw new RuntimeException('calculation_part_inventory_changed');
        $rows=[];
        foreach($metadata as $meta){
            $index=(int)$meta['part_index'];
            if(!isset($parts[$index])||isset($rows[$index]))throw new RuntimeException('calculation_part_inventory_changed');
            self::projectionBytes($remaining,(int)$meta['read_bytes']);
            $row=self::delivery($pdo,(int)$meta['manager_id'],(int)$intent['conversation_id'],$meta['operation_id']);
            if($row['intent_id']!==$intent['intent_id']||(int)$row['part_index']!==$index||(int)$row['active_part']!==1
                ||(int)$row['manager_id']!==(int)$intent['created_by_manager_id']||!self::hex($row['operation_id'],32)
                ||!self::hex($row['request_sha256'],64))throw new RuntimeException('calculation_part_identity_changed');
            $rows[$index]=$row;
        }
        $confirmed=0;$reason=null;$priority=0;$evidence=[];
        foreach($parts as $part){
            $row=$rows[$part['index']]??null;if(!$row)continue;
            $content=json_decode($row['content_json'],true,16,JSON_THROW_ON_ERROR);
            if(!is_array($content)||!self::contentMatches($part,$content)||$row['operation_kind']!==$part['kind'])throw new RuntimeException('calculation_part_content_changed');
            $q=$pdo->prepare('SELECT conversation_id,operation_kind,request_hash,state FROM manager_send_attempts WHERE manager_id=? AND operation_id=?'.self::lock($pdo));
            $q->execute([$row['manager_id'],$row['operation_id']]);$attempt=$q->fetch(PDO::FETCH_ASSOC);$q->closeCursor();
            if(!$attempt||(int)$attempt['conversation_id']!==(int)$intent['conversation_id']||$attempt['operation_kind']!==$row['operation_kind']
                ||!is_string($attempt['request_hash'])||!hash_equals($row['request_sha256'],$attempt['request_hash'])
                ||!in_array($attempt['state'],['pending','processing','unknown','confirmed','rejected','cancelled'],true))throw new RuntimeException('calculation_attempt_changed');
            $rank=0;$code=null;
            if($row['native_json']!==null){
                if(!self::hex($row['native_sha256'],64)||!hash_equals($row['native_sha256'],hash('sha256',$row['native_json'])))throw new RuntimeException('calculation_native_changed');
                $native=json_decode($row['native_json'],true,32,JSON_THROW_ON_ERROR);
                if(!is_array($native))throw new RuntimeException('calculation_native_changed');
                self::validateNative($native,$intent,$content,(int)$row['manager_id'],$row['operation_id'],$part['index']);
                if($row['native_identity_sha256']!==self::hash([$native['channel'],$native['identity']['project_key'],$native['identity']['external_chat_id'],$native['native_mid']]))throw new RuntimeException('calculation_native_changed');
                if($row['message_id']===null){
                    if($row['message_sha256']!==null||!in_array($attempt['state'],['processing','unknown'],true))throw new RuntimeException('calculation_message_proof_changed');
                    [$rank,$code]=[2,'delivery_unverified'];
                }else{
                    $q=$pdo->prepare('SELECT LENGTH(CAST(text AS '.$binary.'))+COALESCE(LENGTH(CAST(metadata_json AS '.$binary.')),0)+1024 AS read_bytes FROM messages WHERE id=?'.self::lock($pdo));
                    $q->execute([$row['message_id']]);$bytes=$q->fetchColumn();$q->closeCursor();
                    if($bytes===false)throw new RuntimeException('calculation_message_missing');self::projectionBytes($remaining,(int)$bytes);
                    if($attempt['state']!=='confirmed'||!self::messageMatches($pdo,$row))throw new RuntimeException('calculation_message_proof_changed');
                    ++$confirmed;$evidence[]=['index'=>$part['index'],'manager_id'=>(int)$row['manager_id'],'operation_id'=>$row['operation_id'],'native_mid'=>$native['native_mid'],
                        'message_id'=>(int)$row['message_id'],'message_sha256'=>$row['message_sha256'],'content_sha256'=>self::hash($content)];
                }
            }else{
                if($row['message_id']!==null||$row['message_sha256']!==null||$row['native_sha256']!==null||$row['native_identity_sha256']!==null)throw new RuntimeException('calculation_native_missing');
                [$rank,$code]=match($attempt['state']){'processing','unknown','confirmed'=>[3,'delivery_unknown'],'rejected'=>[1,'delivery_rejected'],default=>[0,null]};
            }
            if($rank>$priority){$priority=$rank;$reason=$code;}
        }
        if($intent['state']==='completed'){
            $proof=json_decode($intent['evidence_json']??'null',true,32,JSON_THROW_ON_ERROR);
            if(!is_array($proof)||!self::keys($proof,['version','manifest_sha256','parts','actor','task_outcome','task_revision'])
                ||$proof['version']!==1||$proof['manifest_sha256']!==$intent['manifest_sha256']||$proof['parts']!==$evidence
                ||$proof['actor']!==(int)$intent['created_by_manager_id']||$proof['task_outcome']!=='applied'
                ||$proof['task_revision']!==(int)$intent['task_revision']+1||$confirmed!==count($parts))throw new RuntimeException('invalid_calculation_completion_proof');
        }
        return ['availability'=>'available','reason'=>$reason,'confirmed'=>$confirmed,'total'=>count($parts)];
    }

    private static function projectionBytes(int &$remaining,int $bytes): void
    {if($bytes<0||$bytes>$remaining)throw new LengthException('coverage_limit');$remaining-=$bytes;}
    private static function projectionCompletionRevision(array $intent): int
    {
        $proof=json_decode($intent['evidence_json']??'null',true,32,JSON_THROW_ON_ERROR);$parts=self::parts($intent['_composition']);
        if(!is_array($proof)||!self::keys($proof,['version','manifest_sha256','parts','actor','task_outcome','task_revision'])
            ||$proof['version']!==1||$proof['manifest_sha256']!==$intent['manifest_sha256']||$proof['actor']!==(int)$intent['created_by_manager_id']
            ||$proof['task_outcome']!=='applied'||$proof['task_revision']!==(int)$intent['task_revision']+1
            ||!is_array($proof['parts'])||!array_is_list($proof['parts'])||count($proof['parts'])!==count($parts)
            ||!is_string($intent['completed_at'])||$intent['completed_at']==='')throw new RuntimeException('invalid_calculation_completion_proof');
        foreach($proof['parts'] as $index=>$part)if(!is_array($part)||!self::keys($part,['index','manager_id','operation_id','native_mid','message_id','message_sha256','content_sha256'])
            ||$part['index']!==$index||$part['manager_id']!==(int)$intent['created_by_manager_id']||!self::hex($part['operation_id'],32)
            ||!is_string($part['native_mid'])||$part['native_mid']===''||!is_int($part['message_id'])||$part['message_id']<=0
            ||!self::hex($part['message_sha256'],64)||!self::hex($part['content_sha256'],64))throw new RuntimeException('invalid_calculation_completion_proof');
        return $proof['task_revision'];
    }
    private static function projectionFailure(Throwable $e): string
    {return $e instanceof PDOException?'storage_unavailable':($e instanceof LengthException?'coverage_limit':($e instanceof DomainException&&$e->getMessage()==='scope_changed'?'scope_changed':'delivery_evidence_changed'));}

    /** An explicit local recovery command. Status/operation/send receipts never write. */
    public static function recover(int $actor,int $cid,string $id): array
    {
        if(!self::hex($id,32))return self::error('invalid_input');
        $read=self::transaction($actor,$cid,static function(PDO $pdo,array $s)use($cid,$id):array{
            if(!$s['can_edit'])throw new DomainException('forbidden');
            $row=self::intent($pdo,$cid,$id);self::disclose($row,$s['conversation']);
            $q=$pdo->prepare('SELECT manager_id,operation_id FROM manager_promise_calculation_deliveries WHERE intent_id=? AND native_json IS NOT NULL AND message_id IS NULL ORDER BY part_index LIMIT 10');
            $q->execute([$id]);return ['ok'=>true,'pending'=>$q->fetchAll(PDO::FETCH_ASSOC)];
        });
        if(empty($read['ok']))return $read;
        try{foreach($read['pending'] as $part)self::finalizeNative((int)$part['manager_id'],$cid,$part['operation_id']);}
        catch(Throwable $e){return self::error('storage_unavailable');}
        return self::status($actor,$cid,$id);
    }

    /** Called inside the existing execution reservation, before any provider call. */
    public static function reserveInTransaction(PDO $pdo,int $actor,int $cid,string $operation,string $request,array $reference,array $content): array
    {
        self::requireTransaction($pdo);self::reference($reference);
        $row=self::intent($pdo,$cid,$reference['intent_id']);
        if($row['state']!=='armed'||(int)$row['created_by_manager_id']!==$actor)throw new DomainException('intent_conflict');
        if(!self::lockVisibility($pdo,$actor,$cid))throw new DomainException('forbidden');
        $c=ManagerConversationService::visibleConversation($cid,$actor,false,true);
        $m=ManagerAuthService::byId($actor,true);
        if(!$c||!$m||$c['status']!=='manager'||(int)$c['manager_id']!==$actor||!ManagerConversationAccessPolicy::canEditVisibleConversation($c,$m))throw new DomainException('forbidden');
        $reason=self::stale($pdo,$row,$c,$m);if($reason!==null)throw new DomainException($reason);
        $parts=self::parts($row['_composition']);$part=$parts[$reference['part_index']]??null;
        if(!$part||!self::contentMatches($part,$content))throw new DomainException('composition_changed');
        $q=$pdo->prepare('SELECT d.manager_id,d.operation_id,d.native_json,a.state FROM manager_promise_calculation_deliveries d LEFT JOIN manager_send_attempts a ON a.manager_id=d.manager_id AND a.operation_id=d.operation_id WHERE d.intent_id=? AND d.part_index=? AND d.active_part=1'.self::lock($pdo));
        $q->execute([$row['intent_id'],$reference['part_index']]);$old=$q->fetch(PDO::FETCH_ASSOC);$q->closeCursor();
        if($old){
            if($old['native_json']!==null||!in_array($old['state'],['rejected','cancelled'],true))throw new DomainException('part_unresolved');
            $q=$pdo->prepare('UPDATE manager_promise_calculation_deliveries SET active_part=NULL WHERE manager_id=? AND operation_id=?');$q->execute([$old['manager_id'],$old['operation_id']]);
        }
        $q=$pdo->prepare('INSERT INTO manager_promise_calculation_deliveries (manager_id,operation_id,conversation_id,intent_id,part_index,operation_kind,request_sha256,content_json,active_part,native_json,native_sha256,message_id,message_sha256,created_at,accepted_at,completed_at) VALUES (?,?,?,?,?,?,?,?,1,NULL,NULL,NULL,NULL,?,NULL,NULL)');
        $q->execute([$actor,$operation,$cid,$row['intent_id'],$reference['part_index'],$content['kind'],$request,self::json($content),gmdate('Y-m-d H:i:s')]);
        return $row['_scope']['identity'];
    }

    public static function verifyReplay(PDO $pdo,int $actor,int $cid,string $operation,string $request,array $reference): void
    {
        self::reference($reference);$row=self::delivery($pdo,$actor,$cid,$operation);
        if($row['intent_id']!==$reference['intent_id']||(int)$row['part_index']!==$reference['part_index']||!hash_equals($row['request_sha256'],$request))throw new DomainException('send_attempt_mismatch');
    }

    /** Trusted internal adapter-success hook. No public API accepts these fields. */
    public static function acceptNative(int $actor,int $cid,string $operation,array $proof): void
    {
        $pdo=ConversationDb::connection();if($pdo->inTransaction())throw new LogicException('native_proof_transaction_required');
        try{
            if($pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql')$pdo->exec('SET TRANSACTION ISOLATION LEVEL READ COMMITTED');
            $pdo->beginTransaction();self::lockNativeOwner($pdo,$actor,$cid);
            $row=self::delivery($pdo,$actor,$cid,$operation);$intent=self::intent($pdo,$cid,$row['intent_id']);
            $content=json_decode($row['content_json'],true,16,JSON_THROW_ON_ERROR);
            self::validateNative($proof,$intent,$content,$actor,$operation,(int)$row['part_index']);
            $raw=self::json($proof);if(strlen($raw)>1048576)throw new RuntimeException('native_proof_too_large');
            if($row['native_json']!==null){if(!hash_equals($row['native_sha256'],hash('sha256',$raw)))throw new RuntimeException('native_proof_changed');}
            else{
                // A native message cannot be repurposed from another part or a
                // historical ordinary send, even when its visible text matches.
                $q=$pdo->prepare('SELECT m.id FROM messages m JOIN conversations c ON c.id=m.conversation_id WHERE m.channel=? AND m.external_message_id=? AND c.project_key=? AND c.external_chat_id=? LIMIT 1');
                $q->execute([$proof['channel'],$proof['native_mid'],$proof['identity']['project_key'],$proof['identity']['external_chat_id']]);$exists=$q->fetchColumn();$q->closeCursor();
                if($exists!==false)throw new RuntimeException('native_message_already_recorded');
                $nativeIdentity=self::hash([$proof['channel'],$proof['identity']['project_key'],$proof['identity']['external_chat_id'],$proof['native_mid']]);
                $q=$pdo->prepare('UPDATE manager_promise_calculation_deliveries SET native_json=?,native_sha256=?,native_identity_sha256=?,accepted_at=? WHERE manager_id=? AND operation_id=? AND native_json IS NULL');
                $q->execute([$raw,hash('sha256',$raw),$nativeIdentity,gmdate('Y-m-d H:i:s'),$actor,$operation]);if($q->rowCount()!==1)throw new RuntimeException('native_proof_unconfirmed');
            }
            $pdo->commit();
        }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
    }

    /** Durable native proof precedes this atomic canonical mirror/receipt/task commit. */
    public static function finalizeNative(int $actor,int $cid,string $operation): void
    {
        $pdo=ConversationDb::connection();if($pdo->inTransaction())throw new LogicException('native_finalize_transaction_required');
        try{
            if($pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql')$pdo->exec('SET TRANSACTION ISOLATION LEVEL READ COMMITTED');
            $pdo->beginTransaction();self::lockNativeOwner($pdo,$actor,$cid);
            $visibility=self::lockVisibility($pdo,$actor,$cid);
            $row=self::delivery($pdo,$actor,$cid,$operation);$intent=self::intent($pdo,$cid,$row['intent_id']);
            if($row['native_json']===null)throw new RuntimeException('native_proof_missing');
            if(!hash_equals($row['native_sha256'],hash('sha256',$row['native_json'])))throw new RuntimeException('native_proof_corrupt');
            $proof=json_decode($row['native_json'],true,32,JSON_THROW_ON_ERROR);$content=json_decode($row['content_json'],true,16,JSON_THROW_ON_ERROR);
            self::validateNative($proof,$intent,$content,$actor,$operation,(int)$row['part_index']);
            if($row['message_id']===null){
                $metadata=$proof['metadata'];$metadata[self::MESSAGE_META]=['version'=>1,'intent_id'=>$intent['intent_id'],'part_index'=>(int)$row['part_index'],'operation_id'=>$operation,'content_sha256'=>self::hash($content)];
                $messageId=null;
                if(!ConversationRecorder::outboundForConversation($cid,$proof['channel'],$proof['text'],'manager',(string)$actor,$metadata,$proof['native_mid'],$proof['initial_ai_text']??null,$messageId)||!is_int($messageId)||$messageId<=0)throw new RuntimeException('calculation_mirror_unconfirmed');
                $message=self::message($pdo,$messageId);$hash=self::hash($message);
                $q=$pdo->prepare('UPDATE manager_promise_calculation_deliveries SET message_id=?,message_sha256=?,completed_at=? WHERE manager_id=? AND operation_id=? AND message_id IS NULL');
                $q->execute([$messageId,$hash,gmdate('Y-m-d H:i:s'),$actor,$operation]);if($q->rowCount()!==1)throw new RuntimeException('calculation_message_proof_unconfirmed');
                $q=$pdo->prepare('INSERT INTO conversation_events (conversation_id,event_type,actor_type,actor_id,payload_json,created_at) VALUES (?,?,?,?,?,?)');
                $event=['channel'=>$proof['channel'],'project_key'=>'anytour'];
                if($content['kind']==='media')$event['media_type']=$proof['metadata']['attachments'][0]['type'];
                $q->execute([$cid,'manager_message','manager',(string)$actor,self::json($event),gmdate('Y-m-d H:i:s')]);
            }elseif(!self::messageMatches($pdo,$row))throw new RuntimeException('calculation_message_proof_changed');
            $q=$pdo->prepare('SELECT operation_kind,state,result_json,request_hash FROM manager_send_attempts WHERE manager_id=? AND operation_id=?'.self::lock($pdo));$q->execute([$actor,$operation]);$attempt=$q->fetch(PDO::FETCH_ASSOC);$q->closeCursor();
            if(!$attempt||!hash_equals($attempt['request_hash'],$row['request_sha256'])||$attempt['operation_kind']!==$row['operation_kind']||!in_array($attempt['state'],['processing','unknown','confirmed'],true))throw new RuntimeException('calculation_attempt_changed');
            $result=json_decode($attempt['result_json']??'null',true)??[];$result['failure']=null;
            $q=$pdo->prepare("UPDATE manager_send_attempts SET state='confirmed',result_json=?,updated_at=? WHERE manager_id=? AND operation_id=?");$q->execute([self::json($result),gmdate('Y-m-d H:i:s'),$actor,$operation]);
            self::completeIfReady($pdo,$intent,$actor,$visibility);
            $pdo->commit();
        }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
    }

    public static function senderIdentity(array $c): array
    {
        $pdo=ConversationDb::connection();
        $q=$pdo->prepare('SELECT cc.id,cc.external_user_id,cc.external_chat_id FROM conversations c JOIN customer_channels cc ON cc.id=c.customer_channel_id AND cc.customer_id=c.customer_id AND cc.project_key=c.project_key AND cc.channel=c.channel WHERE c.id=?');
        $q->execute([(int)$c['id']]);$channel=$q->fetch(PDO::FETCH_ASSOC);$q->closeCursor();
        if(!$channel||(string)$channel['external_chat_id']!==(string)$c['external_chat_id']||trim((string)$channel['external_user_id'])==='')throw new DomainException('scope_changed');
        return ['conversation_id'=>(int)$c['id'],'project_key'=>(string)$c['project_key'],'customer_id'=>(int)$c['customer_id'],
            'customer_channel_id'=>(int)$channel['id'],'external_user_id'=>(string)$channel['external_user_id'],'source_id'=>(int)($c['source_id']??0),
            'channel'=>(string)$c['channel'],'external_chat_id'=>(string)$c['external_chat_id'],
            'manager_id'=>(int)($c['manager_id']??0),'status'=>(string)$c['status']];
    }

    private static function completeIfReady(PDO $pdo,array $intent,int $actor,bool $visibility): void
    {
        if($intent['state']!=='armed')return;
        $manager=$visibility?ManagerAuthService::byId($actor,true):null;$c=$manager?ManagerConversationService::visibleConversation((int)$intent['conversation_id'],$actor,false,true):null;
        $reason=$c&&$manager?self::stale($pdo,$intent,$c,$manager):'access_changed';
        if($reason!==null){self::terminal($pdo,$intent,'blocked',$reason);return;}
        $evidence=[];$parts=self::parts($intent['_composition']);
        foreach($parts as $part){
            $q=$pdo->prepare('SELECT * FROM manager_promise_calculation_deliveries WHERE intent_id=? AND part_index=? AND active_part=1'.self::lock($pdo));$q->execute([$intent['intent_id'],$part['index']]);$row=$q->fetch(PDO::FETCH_ASSOC);$q->closeCursor();
            if(!$row||$row['native_json']===null||$row['message_id']===null)return;
            if(!self::messageMatches($pdo,$row)){self::terminal($pdo,$intent,'blocked','delivery_evidence_changed');return;}
            $content=json_decode($row['content_json'],true,16,JSON_THROW_ON_ERROR);
            if(!self::contentMatches($part,$content)||!hash_equals($row['native_sha256'],hash('sha256',$row['native_json'])))throw new RuntimeException('invalid_calculation_evidence');
            $native=json_decode($row['native_json'],true,32,JSON_THROW_ON_ERROR);
            self::validateNative($native,$intent,$content,(int)$row['manager_id'],$row['operation_id'],$part['index']);
            $evidence[]=['index'=>$part['index'],'manager_id'=>(int)$row['manager_id'],'operation_id'=>$row['operation_id'],'native_mid'=>$native['native_mid'],
                'message_id'=>(int)$row['message_id'],'message_sha256'=>$row['message_sha256'],'content_sha256'=>self::hash($content)];
        }
        $completion=LeadTaskService::completeForPromise($pdo,(int)$intent['conversation_id'],(int)$intent['task_id'],(int)$intent['task_revision'],$actor);
        $proof=['version'=>1,'manifest_sha256'=>$intent['manifest_sha256'],'parts'=>$evidence,'actor'=>$actor,'task_outcome'=>$completion['outcome'],'task_revision'=>$completion['business_revision']];
        if($completion['outcome']==='applied')self::terminal($pdo,$intent,'completed',null,$proof);
        else self::terminal($pdo,$intent,'blocked',['already_done'=>'task_closed','changed'=>'task_changed','missing'=>'task_missing'][$completion['outcome']]??'task_changed',$proof);
    }

    private static function capture(PDO $pdo,array $s,int $taskId,string $promiseId): array
    {
        $c=$s['conversation'];$cid=(int)$c['id'];
        if(!$s['can_send'])throw new DomainException('forbidden');
        if(!in_array($c['channel'],['max','telegram'],true))throw new DomainException('unsupported_channel');
        $bindings=ManagerPromiseTaskService::readBindings($pdo,$c,[$taskId]);$binding=$bindings[$taskId]??null;
        if(!$binding||$binding['promise_id']!==$promiseId||$binding['kind']!=='send_calculation')throw new DomainException('not_found');
        $task=self::task($pdo,$cid,$taskId);if(!$task)throw new DomainException('task_missing');
        if($task['status']!=='open')throw new DomainException('task_closed');
        $pins=self::pins($pdo,$c,(int)$binding['source_message_id']);
        if($binding['_scope']!==self::promiseIdentity($c))throw new DomainException('scope_changed');
        if($pins['profile']['trip_id']!==$binding['trip_id']||$pins['profile']['version']!==(int)$binding['profile_version']||!hash_equals($pins['profile']['sha256'],$binding['profile_sha256']))throw new DomainException('profile_changed');
        if(!hash_equals($pins['source']['revision'],$binding['source_message_revision'])||$pins['source']['author_manager_id']!==(int)$binding['source_author_manager_id'])throw new DomainException('source_changed');
        $q=$pdo->prepare('SELECT intent_id FROM manager_promise_calculation_intents WHERE conversation_id=? AND promise_id=? AND active_promise_id IS NOT NULL LIMIT 1'.self::lock($pdo));$q->execute([$cid,$promiseId]);$active=$q->fetchColumn();$q->closeCursor();
        if($active!==false)throw new DomainException('intent_conflict');
        // A genuinely reopened task may authorize a NEW explicit result. Its
        // old immutable completion can never be replayed onto that generation.
        $q=$pdo->prepare("SELECT MAX(task_revision) FROM manager_promise_calculation_intents WHERE conversation_id=? AND promise_id=? AND state='completed'");$q->execute([$cid,$promiseId]);$completedRevision=$q->fetchColumn();$q->closeCursor();
        if($completedRevision!==null&&$completedRevision!==false&&$task['business_revision']<=(int)$completedRevision+1)throw new DomainException('already_completed');
        $q=$pdo->prepare("SELECT d.operation_id FROM manager_promise_calculation_deliveries d JOIN manager_promise_calculation_intents i ON i.intent_id=d.intent_id JOIN manager_send_attempts a ON a.manager_id=d.manager_id AND a.operation_id=d.operation_id WHERE i.conversation_id=? AND i.promise_id=? AND a.state IN ('processing','unknown') LIMIT 1");$q->execute([$cid,$promiseId]);$unknown=$q->fetchColumn();$q->closeCursor();if($unknown!==false)throw new DomainException('part_unresolved');
        return ['actor'=>$s['actor'],'promise'=>['promise_id'=>$promiseId,'kind'=>'send_calculation','deadline'=>$binding['_deadline']],
            'task'=>$task,'profile'=>$pins['profile'],'source'=>$pins['source'],
            'scope'=>['identity'=>self::senderIdentity($c),'reply_boundary'=>ManagerReplyQueueService::captureSendBoundary($cid)]];
    }

    private static function previewView(array $capture,array $composition): array
    {
        $manifest=self::hash($composition);
        return ['ok'=>true,'can_arm'=>true,'reason'=>null,'preview_sha256'=>self::hash(['version'=>1,'capture'=>$capture,'manifest_sha256'=>$manifest]),
            'manifest_sha256'=>$manifest,'composition'=>$composition,'parts'=>self::parts($composition),
            'task'=>$capture['task'],'promise'=>$capture['promise'],'source'=>$capture['source'],'profile'=>$capture['profile']];
    }

    private static function stale(PDO $pdo,array $row,array $c,array $manager): ?string
    {
        if($c['status']!=='manager'||(int)$c['manager_id']!==(int)$row['created_by_manager_id']||!ManagerConversationAccessPolicy::canEditVisibleConversation($c,$manager))return 'access_changed';
        try{if(self::senderIdentity($c)!==$row['_scope']['identity'])return 'scope_changed';}catch(DomainException $e){return 'scope_changed';}
        if(ManagerReplyQueueService::captureSendBoundary((int)$row['conversation_id'])!==$row['_scope']['reply_boundary'])return 'context_changed';
        try{$pins=self::pins($pdo,$c,(int)$row['source_message_id']);}catch(DomainException $e){return $e->getMessage();}
        $p=$pins['profile'];$source=$pins['source'];
        if($p['trip_id']!==$row['trip_id']||$p['version']!==(int)$row['profile_version']||!hash_equals($p['sha256'],$row['profile_sha256']))return 'profile_changed';
        if(!hash_equals($source['revision'],$row['source_message_revision'])||$source['author_manager_id']!==(int)$row['source_author_manager_id'])return 'source_changed';
        $task=self::task($pdo,(int)$row['conversation_id'],(int)$row['task_id']);
        if(!$task)return 'task_missing';if($task['status']!=='open')return 'task_closed';
        return $task['business_revision']!==(int)$row['task_revision']?'task_changed':null;
    }

    private static function pins(PDO $pdo,array $c,int $sourceId): array
    {
        $pins=ManagerRequestProfileService::forTaskBinding($pdo,$c,$sourceId);$source=$pins['source'];
        if(!$source||$source['unavailable']||$sourceId<$pins['profile']['boundary_message_id'])throw new DomainException('source_unavailable');
        $binary=$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'?'BINARY':'BLOB';
        $q=$pdo->prepare('SELECT direction,sender_type,sender_id,channel,external_message_id,SUBSTR(CAST(metadata_json AS '.$binary.'),1,8193) AS metadata FROM messages WHERE conversation_id=? AND id=?'.self::lock($pdo));$q->execute([(int)$c['id'],$sourceId]);$row=$q->fetch(PDO::FETCH_ASSOC);$q->closeCursor();
        $evidence=ManagerPromiseTaskService::sourceEvidence($row?:[],$c,$source,true);
        if(!$evidence)throw new DomainException('source_unavailable');
        $q=$pdo->prepare('SELECT id FROM managers WHERE id=?');$q->execute([$evidence['author_manager_id']]);if(!$q->fetchColumn())throw new DomainException('source_unavailable');$q->closeCursor();
        $pins['source']=$evidence;return $pins;
    }

    private static function composition(array $value): array
    {
        if(!self::keys($value,['text','files'])||!is_string($value['text'])||strlen($value['text'])>80000||!mb_check_encoding($value['text'],'UTF-8')
            ||!is_array($value['files'])||!array_is_list($value['files'])||count($value['files'])>10)throw new DomainException('invalid_composition');
        $out=['text'=>trim($value['text']),'files'=>[]];
        foreach($value['files'] as $file){
            if(!is_array($file)||!self::keys($file,['sha256','size','name'])||!self::hex($file['sha256']??null,64)||!is_int($file['size']??null)||$file['size']<=0
                ||!is_string($file['name']??null)||strlen($file['name'])>1024||!mb_check_encoding($file['name'],'UTF-8'))throw new DomainException('invalid_composition');
            $name=preg_replace('/[\x00-\x1F\x7F]+/u','',trim(basename($file['name'])))?:'attachment';
            $out['files'][]=['sha256'=>$file['sha256'],'size'=>$file['size'],'name'=>$name];
        }
        if($out['text']===''&&!$out['files'])throw new DomainException('empty_composition');
        return $out;
    }

    private static function parts(array $composition): array
    {
        if(!$composition['files'])return [['index'=>0,'kind'=>'text','text_sha256'=>hash('sha256',$composition['text'])]];
        $out=[];foreach($composition['files'] as $i=>$file)$out[]=['index'=>$i,'kind'=>'media','file_sha256'=>$file['sha256'],'size'=>$file['size'],'name'=>$file['name'],'caption_sha256'=>hash('sha256',$i===0?$composition['text']:'')];return $out;
    }

    private static function contentMatches(array $part,array $content): bool
    {
        if(($content['kind']??null)!==$part['kind'])return false;
        if($part['kind']==='text')return self::keys($content,['kind','text'])&&is_string($content['text'])&&hash_equals($part['text_sha256'],hash('sha256',$content['text']));
        return self::keys($content,['kind','file_sha256','size','name','mime','caption'])&&$content['file_sha256']===$part['file_sha256']&&$content['size']===$part['size']&&$content['name']===$part['name']
            &&is_string($content['mime'])&&strlen($content['mime'])<=255&&is_string($content['caption'])&&hash_equals($part['caption_sha256'],hash('sha256',$content['caption']));
    }

    private static function validateNative(array $proof,array $intent,array $content,int $actor,string $operation,int $index): void
    {
        if(!self::keys($proof,['channel','native_mid','identity','text','metadata','initial_ai_text'])||!is_string($proof['native_mid'])||!preg_match('/\A[A-Za-z0-9_.-]{1,191}\z/D',$proof['native_mid'])
            ||$proof['channel']!==$intent['_scope']['identity']['channel']||($proof['channel']==='telegram'&&!preg_match('/\A[1-9][0-9]{0,18}\z/D',$proof['native_mid']))
            ||$proof['identity']!==$intent['_scope']['identity']||(int)$intent['created_by_manager_id']!==$actor
            ||!is_string($proof['text'])||!is_array($proof['metadata'])||($proof['initial_ai_text']!==null&&!is_string($proof['initial_ai_text']))
            ||($proof['metadata']['project_key']??null)!=='anytour'||array_key_exists(self::MESSAGE_META,$proof['metadata'])
            ||!isset($proof['metadata'][ManagerReplyQueueService::SEND_BOUNDARY]))throw new RuntimeException('invalid_calculation_native_proof');
        $boundary=$proof['metadata'][ManagerReplyQueueService::SEND_BOUNDARY];
        if(!is_array($boundary)||!self::keys($boundary,['version','customer_message_id','request_event_id'])||$boundary['version']!==1
            ||!is_int($boundary['customer_message_id'])||$boundary['customer_message_id']<0||!is_int($boundary['request_event_id'])||$boundary['request_event_id']<0)throw new RuntimeException('invalid_calculation_send_boundary');
        $part=self::parts($intent['_composition'])[$index]??null;if(!$part||!self::contentMatches($part,$content))throw new RuntimeException('calculation_content_changed');
        $raw=$content['kind']==='text'?$content['text']:$content['caption'];$expected=htmlspecialchars($raw,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
        if($content['kind']==='media'){
            $attachments=$proof['metadata']['attachments']??null;
            if(!is_array($attachments)||!array_is_list($attachments)||count($attachments)!==1||!in_array($attachments[0]['type']??null,['image','video','audio','file'],true)
                ||($attachments[0]['name']??null)!==$content['name']||($attachments[0]['mime_type']??null)!==$content['mime'])throw new RuntimeException('invalid_calculation_media_proof');
            if($expected==='')$expected=ConversationRecorder::attachmentPreview([['type'=>$attachments[0]['type']]]);
        }elseif(isset($proof['metadata']['attachments']))throw new RuntimeException('unexpected_calculation_attachments');
        if($proof['text']!==$expected)throw new RuntimeException('calculation_native_text_mismatch');
    }

    private static function view(PDO $pdo,array $intent): array
    {
        $parts=self::parts($intent['_composition']);$confirmed=0;$recover=false;
        foreach($parts as &$part){
            $q=$pdo->prepare('SELECT d.*,a.state FROM manager_promise_calculation_deliveries d LEFT JOIN manager_send_attempts a ON a.manager_id=d.manager_id AND a.operation_id=d.operation_id WHERE d.intent_id=? AND d.part_index=? AND d.active_part=1');
            $q->execute([$intent['intent_id'],$part['index']]);$row=$q->fetch(PDO::FETCH_ASSOC);$q->closeCursor();$state=$row['state']??'pending';
            if($row&&$row['native_json']!==null&&$row['message_id']!==null&&self::messageMatches($pdo,$row)){$state='confirmed';++$confirmed;}
            elseif($state==='confirmed')$state='unknown';
            if(!in_array($state,['pending','processing','unknown','confirmed','rejected','cancelled'],true))$state='unknown';
            if($row&&$row['native_json']!==null&&$row['message_id']===null)$recover=true;
            $part+=['state'=>$state,'operation_id'=>$row['operation_id']??null];
        }unset($part);
        $task=self::task($pdo,(int)$intent['conversation_id'],(int)$intent['task_id']);
        return ['intent_id'=>$intent['intent_id'],'promise_id'=>$intent['promise_id'],'task_id'=>(int)$intent['task_id'],'state'=>$intent['state'],'reason'=>$intent['reason'],
            'manifest_sha256'=>$intent['manifest_sha256'],'parts'=>$parts,'total'=>count($parts),'confirmed'=>$confirmed,'can_recover'=>$recover,
            'task_revision'=>(int)$intent['task_revision'],'task_status'=>$task['status']??null,'created_at'=>$intent['created_at'],'completed_at'=>$intent['completed_at']];
    }

    private static function message(PDO $pdo,int $id): array
    {
        $q=$pdo->prepare('SELECT id,conversation_id,direction,sender_type,sender_id,channel,external_message_id,text,metadata_json FROM messages WHERE id=?');$q->execute([$id]);$r=$q->fetch(PDO::FETCH_ASSOC);$q->closeCursor();
        if(!$r)throw new RuntimeException('calculation_message_missing');$r['id']=(int)$r['id'];$r['conversation_id']=(int)$r['conversation_id'];return $r;
    }
    private static function messageMatches(PDO $pdo,array $row): bool
    {
        if($row['message_id']===null||$row['native_json']===null||!self::hex($row['message_sha256'],64))return false;
        try{$message=self::message($pdo,(int)$row['message_id']);$native=json_decode($row['native_json'],true,32,JSON_THROW_ON_ERROR);
            $meta=json_decode($message['metadata_json'],true,32,JSON_THROW_ON_ERROR);$pin=$meta[self::MESSAGE_META]??null;
            return hash_equals($row['message_sha256'],self::hash($message))&&$message['conversation_id']===(int)$row['conversation_id']&&$message['direction']==='outbound'&&$message['sender_type']==='manager'
                &&(string)$message['sender_id']===(string)$row['manager_id']&&$message['external_message_id']===$native['native_mid']&&$message['channel']===$native['channel']&&$message['text']===$native['text']
                &&$pin===['version'=>1,'intent_id'=>$row['intent_id'],'part_index'=>(int)$row['part_index'],'operation_id'=>$row['operation_id'],'content_sha256'=>hash('sha256',$row['content_json'])];
        }catch(Throwable $e){return false;}
    }

    private static function intent(PDO $pdo,int $cid,string $id): array
    {
        $q=$pdo->prepare('SELECT * FROM manager_promise_calculation_intents WHERE conversation_id=? AND intent_id=?'.self::lock($pdo));$q->execute([$cid,$id]);$r=$q->fetch(PDO::FETCH_ASSOC);$q->closeCursor();if(!$r)throw new DomainException('not_found');
        if(strlen($r['composition_json'])>524288||strlen($r['scope_json'])>8192)throw new RuntimeException('oversize_calculation_intent');
        $r['_composition']=self::composition(json_decode($r['composition_json'],true,32,JSON_THROW_ON_ERROR));$r['_scope']=json_decode($r['scope_json'],true,16,JSON_THROW_ON_ERROR);
        if(!self::hex($r['intent_id'],32)||!self::hex($r['promise_id'],64)||!self::hex($r['trip_id'],32)||!self::hex($r['profile_sha256'],64)||!self::hex($r['source_message_revision'],64)
            ||(int)$r['conversation_id']!==$cid||(int)$r['task_id']<=0||(int)$r['task_revision']<1||(int)$r['task_revision']>=PHP_INT_MAX
            ||(int)$r['profile_version']<1||(int)$r['source_message_id']<1||(int)$r['source_author_manager_id']<1||(int)$r['created_by_manager_id']<1
            ||!self::hex($r['manifest_sha256'],64)||!hash_equals($r['manifest_sha256'],self::hash($r['_composition']))||!in_array($r['state'],['armed','completed','blocked','cancelled'],true)
            ||!self::keys($r['_scope'],['identity','reply_boundary'])||!is_array($r['_scope']['identity']??null)||!is_array($r['_scope']['reply_boundary']??null)
            ||($r['_scope']['identity']['conversation_id']??null)!==$cid||($r['_scope']['identity']['project_key']??null)!=='anytour'
            ||($r['_scope']['identity']['manager_id']??null)!==(int)$r['created_by_manager_id'])throw new RuntimeException('invalid_calculation_intent');return $r;
    }
    private static function history(PDO $pdo,int $cid,string $promise): ?array
    {
        $q=$pdo->prepare('SELECT intent_id FROM manager_promise_calculation_intents WHERE conversation_id=? AND promise_id=? ORDER BY CASE WHEN active_promise_id IS NOT NULL THEN 0 ELSE 1 END,created_at DESC,intent_id DESC LIMIT 1');$q->execute([$cid,$promise]);$id=$q->fetchColumn();$q->closeCursor();return $id===false?null:self::intent($pdo,$cid,$id);
    }
    private static function delivery(PDO $pdo,int $actor,int $cid,string $operation): array
    {
        $q=$pdo->prepare('SELECT * FROM manager_promise_calculation_deliveries WHERE manager_id=? AND operation_id=?'.self::lock($pdo));$q->execute([$actor,$operation]);$r=$q->fetch(PDO::FETCH_ASSOC);$q->closeCursor();
        if(!$r||(int)$r['conversation_id']!==$cid)throw new DomainException('send_attempt_mismatch');return $r;
    }
    private static function task(PDO $pdo,int $cid,int $id): ?array
    {
        $q=$pdo->prepare('SELECT id,conversation_id,title,due_at_utc,status,is_pinned,assigned_manager_id,business_revision FROM lead_tasks WHERE conversation_id=? AND id=?'.self::lock($pdo));$q->execute([$cid,$id]);$r=$q->fetch(PDO::FETCH_ASSOC);$q->closeCursor();if(!$r)return null;
        if(!in_array($r['status'],['open','done'],true)||(int)$r['business_revision']<1)throw new RuntimeException('invalid_task_storage');
        foreach(['id','conversation_id','business_revision'] as $key)$r[$key]=(int)$r[$key];$r['is_pinned']=(bool)$r['is_pinned'];$r['assigned_manager_id']=$r['assigned_manager_id']===null?null:(int)$r['assigned_manager_id'];return $r;
    }
    private static function disclose(array $row,array $c): void
    {
        $stored=$row['_scope']['identity'];$now=self::senderIdentity($c);unset($stored['manager_id'],$stored['status'],$now['manager_id'],$now['status']);
        if($stored!==$now)throw new DomainException('scope_changed');
    }
    private static function terminal(PDO $pdo,array $row,string $state,?string $reason,?array $proof=null): void
    {
        $now=gmdate('Y-m-d H:i:s');$q=$pdo->prepare('UPDATE manager_promise_calculation_intents SET state=?,reason=?,active_promise_id=NULL,evidence_json=?,updated_at=?,completed_at=?,cancelled_at=? WHERE intent_id=? AND state=?');
        $q->execute([$state,$reason,$proof===null?null:self::json($proof),$now,$state==='completed'?$now:null,$state==='cancelled'?$now:null,$row['intent_id'],'armed']);
        if($q->rowCount()!==1)throw new RuntimeException('calculation_terminal_unconfirmed');
    }
    private static function operationRow(PDO $pdo,int $actor,string $id): ?array
    {$q=$pdo->prepare('SELECT * FROM manager_promise_calculation_operations WHERE manager_id=? AND operation_id=?');$q->execute([$actor,$id]);$r=$q->fetch(PDO::FETCH_ASSOC);$q->closeCursor();return $r?:null;}
    private static function writeOperation(PDO $pdo,int $actor,int $cid,string $operation,string $action,string $intent,string $hash): void
    {$q=$pdo->prepare('INSERT INTO manager_promise_calculation_operations (manager_id,operation_id,conversation_id,action,intent_id,request_sha256,created_at) VALUES (?,?,?,?,?,?,?)');$q->execute([$actor,$operation,$cid,$action,$intent,$hash,gmdate('Y-m-d H:i:s')]);}
    private static function replay(PDO $pdo,int $cid,array $r,string $action,string $hash,array $c): array
    {if((int)$r['conversation_id']!==$cid||$r['action']!==$action||!hash_equals($r['request_sha256'],$hash))throw new DomainException('operation_mismatch');$intent=self::intent($pdo,$cid,$r['intent_id']);self::disclose($intent,$c);return ['ok'=>true,'replayed'=>true,'intent'=>self::view($pdo,$intent)];}
    private static function reference(array $r): void
    {if(!self::keys($r,['intent_id','part_index'])||!self::hex($r['intent_id']??null,32)||!is_int($r['part_index']??null)||$r['part_index']<0||$r['part_index']>9)throw new DomainException('invalid_calculation_reference');}
    private static function lockNativeOwner(PDO $pdo,int $actor,int $cid): void
    {foreach([['managers',$actor],['conversations',$cid]] as [$table,$id]){$q=$pdo->prepare('SELECT id FROM '.$table.' WHERE id=?'.self::lock($pdo));$q->execute([$id]);if(!$q->fetchColumn())throw new RuntimeException('calculation_owner_missing');$q->closeCursor();}}
    private static function lockVisibility(PDO $pdo,int $actor,int $cid): bool
    {$q=$pdo->prepare('SELECT id,project_key,source_id FROM conversations WHERE id=?'.self::lock($pdo));$q->execute([$cid]);$c=$q->fetch(PDO::FETCH_ASSOC);$q->closeCursor();return $c&&RoutingAccessService::lockConversationVisibilityInputs($actor,$c);}
    private static function transaction(int $actor,int $cid,callable $work): array
    {
        if($actor<=0||$cid<=0)return self::error('forbidden');$pdo=null;
        try{
            $pdo=ConversationDb::connection();if($pdo->inTransaction())return self::error('storage_unavailable');ProjectAccessService::initializeReadOnly();
            if($pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql')$pdo->exec('SET TRANSACTION ISOLATION LEVEL READ COMMITTED');
            $pdo->beginTransaction();$manager=ManagerAuthService::byId($actor,true);if(!$manager)throw new DomainException('forbidden');
            $q=$pdo->prepare('SELECT id FROM conversations WHERE id=?'.self::lock($pdo));$q->execute([$cid]);$exists=$q->fetchColumn();$q->closeCursor();
            if(!$exists||!self::lockVisibility($pdo,$actor,$cid))throw new DomainException('forbidden');
            $c=$exists?ManagerConversationService::visibleConversation($cid,$actor,false,true):null;if(!$c||$c['project_key']!=='anytour')throw new DomainException('forbidden');
            $canEdit=$c['status']!=='closed'&&ManagerConversationAccessPolicy::canEditVisibleConversation($c,$manager);
            $result=$work($pdo,['actor'=>$actor,'conversation'=>$c,'can_edit'=>$canEdit,'can_send'=>$canEdit&&$c['status']==='manager'&&(int)$c['manager_id']===$actor]);
            if(empty($result['ok']))$pdo->rollBack();else{$pdo->commit();$result+=['viewer_id'=>$actor,'conversation_id'=>$cid];}return $result;
        }catch(DomainException $e){if($pdo&&$pdo->inTransaction())$pdo->rollBack();return self::error($e->getMessage());}
        catch(Throwable $e){if($pdo&&$pdo->inTransaction())$pdo->rollBack();return self::error('storage_unavailable');}
    }
    private static function requireTransaction(PDO $pdo): void {if($pdo!==ConversationDb::connection()||!$pdo->inTransaction())throw new LogicException('calculation_transaction_required');}
    private static function promiseIdentity(array $c): array {return ['conversation_id'=>(int)$c['id'],'project_key'=>(string)$c['project_key'],'customer_id'=>(int)$c['customer_id'],'channel'=>(string)$c['channel'],'external_chat_id'=>(string)$c['external_chat_id']];}
    private static function lock(PDO $pdo): string {return $pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'?' FOR UPDATE':'';}
    private static function keys(array $a,array $keys): bool {return count($a)===count($keys)&&array_diff(array_keys($a),$keys)===[];}
    private static function hex(mixed $s,int $n): bool {return is_string($s)&&strlen($s)===$n&&preg_match('/\A[a-f0-9]+\z/D',$s)===1;}
    private static function json(mixed $v): string {return json_encode($v,self::FLAGS);}
    private static function hash(mixed $v): string {return hash('sha256',self::json($v));}
    private static function error(string $error): array {return ['ok'=>false,'error'=>$error];}
}
