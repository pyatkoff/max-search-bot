<?php
declare(strict_types=1);
require_once __DIR__.'/ConversationDb.php';
require_once __DIR__.'/ManagerConversationService.php';

/** Durable execution fence around the existing sender. Never retries transport. */
final class ManagerSendAttemptService
{
    public static function execute(int $actor,int $conversationId,string $operationId,string $text,?string $aiDraftId,callable $send): array
    {
        if (!self::valid($operationId)||$conversationId<=0||trim($text)===''||strlen($text)>80000) return self::error('invalid_send_attempt');
        // Keep the installed text hash and its existing UNKNOWN receipts intact.
        $hash=hash('sha256',json_encode([trim($text),$aiDraftId],JSON_THROW_ON_ERROR));
        return self::executeCommand($actor,$conversationId,$operationId,$hash,'text',[],$send);
    }

    public static function executeMedia(int $actor,int $conversationId,string $operationId,string $path,string $name,string $mime,string $caption,callable $send): array
    {
        if(!self::valid($operationId)||$conversationId<=0||strlen($caption)>80000)return self::error('invalid_send_attempt');
        $size=is_file($path)?filesize($path):false;$digest=$size!==false?hash_file('sha256',$path):false;
        if($size===false||$size<=0||$digest===false)return self::error('invalid_upload');
        $name=trim(basename($name));$name=preg_replace('/[\x00-\x1F\x7F]+/u','',$name)?:'attachment';
        $mime=strtolower(trim($mime));$caption=trim($caption);
        $type=str_starts_with($mime,'image/')?'image':(str_starts_with($mime,'video/')?'video':(str_starts_with($mime,'audio/')?'audio':'file'));
        $hash=hash('sha256',json_encode(['media-v1',$digest,$size,$name,$mime,$caption],JSON_THROW_ON_ERROR));
        return self::executeCommand($actor,$conversationId,$operationId,$hash,'media',['media_type'=>$type,'caption_included'=>$caption!==''],$send);
    }

    private static function executeCommand(int $actor,int $conversationId,string $operationId,string $hash,string $kind,array $descriptor,callable $send): array
    {
        $pdo=ConversationDb::connection();
        if ($pdo->inTransaction()) throw new LogicException('Send attempts require their own transaction');
        try {
            $pdo->beginTransaction();$scope=self::scope($pdo,$actor,$conversationId,true,$kind);
            if ($scope===null) {$pdo->rollBack();return self::error('forbidden');}
            $context=$scope['context'];$row=self::row($pdo,$actor,$operationId);
            if ($row!==null) {$pdo->commit();return self::replay($row,$conversationId,$context,$hash,$kind);}
            if($kind==='media')$descriptor['recipient_hash']=$scope['recipient_hash'];
            self::insert($pdo,$actor,$conversationId,$operationId,$context,$hash,'processing',$kind,$descriptor);
            // The unique fence is committed BEFORE cache/provider work. A crash
            // remains processing/UNKNOWN; no DB lock crosses the network.
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            try {$scope=self::scope($pdo,$actor,$conversationId,false,$kind);$row=self::row($pdo,$actor,$operationId);if ($scope!==null&&$row!==null) return self::replay($row,$conversationId,$scope['context'],$hash,$kind);}catch(Throwable $ignored){}
            return self::error('send_attempt_unavailable');
        }
        $state='unknown';$failure=null;
        try {
            $result=$send($context);
            if (($result['ok']??false)===true) $state='confirmed';
            else {
                $raw=$result['failure']??null;
                if (is_array($raw)) {
                    $category=(string)($raw['category']??'unknown');$code=(int)($raw['http_code']??0);
                    $zero=$kind==='media'&&$category==='not_dispatched'&&in_array($raw['reason']??'',['file_unavailable','context_changed'],true)&&$code===0;
                    $definite=$kind==='media'?self::isDefiniteMediaFailure($raw):(in_array($category,['suspended','blocked','unsupported','attempt_context_changed','connection_unavailable'],true)||($code>=400&&$code<500&&!in_array($code,[408,409,429],true)));
                    if ($definite) {$state='rejected';$failure=['category'=>$zero?'not_dispatched':(in_array($category,['suspended','blocked','unavailable','unsupported','attempt_context_changed','connection_unavailable'],true)?$category:'unknown')];if($zero)$failure['reason']=$raw['reason'];}
                }
            }
        } catch (Throwable $e) { /* No exception/provider payload in the receipt. */ }
        $saved=$descriptor+['failure'=>$failure];
        try {
            $q=$pdo->prepare('UPDATE manager_send_attempts SET state=?,result_json=?,updated_at=? WHERE manager_id=? AND operation_id=? AND state=?');
            $q->execute([$state,json_encode($saved,JSON_THROW_ON_ERROR),gmdate('Y-m-d H:i:s'),$actor,$operationId,'processing']);
            if ($q->rowCount()!==1) return self::error('send_attempt_unavailable');
        } catch(Throwable $e) {return self::error('send_attempt_unavailable');}
        return self::view(['operation_kind'=>$kind,'state'=>$state,'result_json'=>json_encode($saved)]);
    }

    /** Read-only and freshly fenced; absence is not proof of non-delivery. */
    public static function receipt(int $actor,int $conversationId,string $operationId): array
    {
        if (!self::valid($operationId)||$conversationId<=0) return self::error('invalid_send_attempt');
        $pdo=ConversationDb::connection();$context=self::context($pdo,$actor,$conversationId);
        if ($context===null) return self::error('forbidden');
        $row=self::row($pdo,$actor,$operationId);
        if ($row===null) return ['ok'=>true,'attempt'=>['state'=>'not_found']];
        $result=self::replay($row,$conversationId,$context);
        if (isset($result['attempt'])) $result['ok']=true;
        return $result;
    }

    public static function mediaReceipt(int $actor,int $conversationId,string $operationId): array
    {
        if(!self::valid($operationId)||$conversationId<=0)return self::error('invalid_send_attempt');
        $pdo=ConversationDb::connection();$scope=self::scope($pdo,$actor,$conversationId,false,'media',true);
        if($scope===null)return self::error('forbidden');
        $row=self::row($pdo,$actor,$operationId);
        $fresh=self::scope($pdo,$actor,$conversationId,false,'media',true);
        if($fresh===null||!hash_equals($scope['context'],$fresh['context']))return self::error('forbidden');
        if($row===null)return ['ok'=>true,'attempt'=>['kind'=>'media','state'=>'not_found']];
        if(($row['operation_kind']??'text')!=='media'||(int)$row['conversation_id']!==$conversationId)return self::error('send_attempt_mismatch');
        $saved=json_decode((string)$row['result_json'],true);
        // Reading a historical result uses current ordinary visibility. It may
        // survive reassignment/closure, but never a different actual recipient.
        if(!is_array($saved)||!hash_equals((string)($saved['recipient_hash']??''),$fresh['recipient_hash']))return self::error('send_attempt_mismatch');
        return ['ok'=>true]+array_diff_key(self::view($row),['ok'=>true]);
    }

    public static function cancel(int $actor,int $conversationId,string $operationId): array
    {
        return self::cancelCommand($actor,$conversationId,$operationId,'text');
    }
    public static function mediaCancel(int $actor,int $conversationId,string $operationId): array
    {
        $result=self::cancelCommand($actor,$conversationId,$operationId,'media');
        // The command was reconciled; delivery is described only by its state.
        if(isset($result['attempt']))$result['ok']=true;
        return $result;
    }
    public static function isDefiniteMediaFailure(?array $failure): bool
    {
        if($failure===null)return false;$category=(string)($failure['category']??'unknown');$code=(int)($failure['http_code']??0);
        return ($category==='not_dispatched'&&$code===0&&in_array($failure['reason']??'',['file_unavailable','context_changed'],true))
            ||in_array($category,['suspended','blocked','unsupported','attempt_context_changed','connection_unavailable'],true)
            ||($code>=400&&$code<500&&!in_array($code,[408,409,429],true));
    }
    /** Cancellation only fences a delayed request which never reserved its ID. */
    private static function cancelCommand(int $actor,int $conversationId,string $operationId,string $kind): array
    {
        if (!self::valid($operationId)||$conversationId<=0) return self::error('invalid_send_attempt');
        $pdo=ConversationDb::connection();
        if ($pdo->inTransaction()) throw new LogicException('Send cancellation requires its own transaction');
        try {
            $pdo->beginTransaction();$scope=self::scope($pdo,$actor,$conversationId,true,$kind);
            if ($scope===null) {$pdo->rollBack();return self::error('forbidden');}
            $row=self::row($pdo,$actor,$operationId);
            if ($row===null) {$descriptor=$kind==='media'?['recipient_hash'=>$scope['recipient_hash']]:[];self::insert($pdo,$actor,$conversationId,$operationId,$scope['context'],null,'cancelled',$kind,$descriptor);$result=self::view(['operation_kind'=>$kind,'state'=>'cancelled','result_json'=>json_encode($descriptor)]);}
            else $result=self::replay($row,$conversationId,$scope['context'],null,$kind);
            $pdo->commit();return $result;
        } catch(Throwable $e) {if ($pdo->inTransaction()) $pdo->rollBack();return self::error('send_attempt_unavailable');}
    }
    private static function valid(string $id): bool {return preg_match('/\A[a-f0-9]{32}\z/D',$id)===1;}
    private static function context(PDO $pdo,int $actor,int $conversationId,bool $lock=false): ?string
    {
        $suffix=$lock&&$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'?' FOR UPDATE':'';
        $q=$pdo->prepare('SELECT id,is_active FROM managers WHERE id=?'.$suffix);$q->execute([$actor]);$m=$q->fetch(PDO::FETCH_ASSOC);
        if (!$m||(int)$m['is_active']!==1) return null;
        if ($lock) {$q=$pdo->prepare('SELECT id FROM conversations WHERE id=?'.$suffix);$q->execute([$conversationId]);if (!$q->fetchColumn()) return null;}
        $c=ManagerConversationService::visibleConversation($conversationId,$actor);
        if (!$c||(string)$c['status']!=='manager'||(int)$c['manager_id']!==$actor) return null;
        return self::contextHash($c);
    }
    private static function scope(PDO $pdo,int $actor,int $conversationId,bool $lock,string $kind,bool $readOnly=false): ?array
    {
        if($kind==='text'){$context=self::context($pdo,$actor,$conversationId,$lock);return $context===null?null:['context'=>$context];}
        $suffix=$lock&&$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'?' FOR UPDATE':'';
        $q=$pdo->prepare('SELECT id,role,is_active FROM managers WHERE id=?'.$suffix);$q->execute([$actor]);$manager=$q->fetch(PDO::FETCH_ASSOC);
        if(!$manager||(int)$manager['is_active']!==1)return null;
        if($lock){$q=$pdo->prepare('SELECT id,project_key,source_id FROM conversations WHERE id=?'.$suffix);$q->execute([$conversationId]);$raw=$q->fetch(PDO::FETCH_ASSOC);if(!$raw||!RoutingAccessService::lockConversationVisibilityInputs($actor,$raw))return null;}
        $c=ManagerConversationService::visibleConversation($conversationId,$actor);
        if(!$c||(!$readOnly&&((string)$c['status']!=='manager'||(int)$c['manager_id']!==$actor)))return null;
        $recipient=self::contextHash($c);
        return ['context'=>self::mediaContextHash($c,$manager),'recipient_hash'=>$recipient];
    }
    /** Use THIS freshly authorized row for the adapter target. */
    public static function dispatchAllowed(array $conversation,int $actor,string $expected,string $kind='text'): bool
    {
        if($actor<=0||(string)($conversation['status']??'')!=='manager'||(int)($conversation['manager_id']??0)!==$actor)return false;
        if($kind==='text'){
            if(!hash_equals($expected,self::contextHash($conversation)))return false;
            $q=ConversationDb::connection()->prepare('SELECT is_active FROM managers WHERE id=?');$q->execute([$actor]);return (int)$q->fetchColumn()===1;
        }
        $q=ConversationDb::connection()->prepare('SELECT id,role,is_active FROM managers WHERE id=?');$q->execute([$actor]);$m=$q->fetch(PDO::FETCH_ASSOC);
        return $m&&(int)$m['is_active']===1&&hash_equals($expected,self::mediaContextHash($conversation,$m));
    }
    private static function mediaContextHash(array $c,array $manager): string
    {
        return hash('sha256',json_encode(['media-v1',(int)$manager['id'],(string)$manager['role'],(string)$c['status'],(int)$c['manager_id'],self::contextHash($c)],JSON_THROW_ON_ERROR));
    }
    private static function contextHash(array $c): string
    {
        return hash('sha256',json_encode([(int)$c['id'],(string)$c['project_key'],(int)$c['source_id'],(string)$c['channel'],(string)$c['external_chat_id']],JSON_THROW_ON_ERROR));
    }
    private static function row(PDO $pdo,int $actor,string $id): ?array
    {
        $q=$pdo->prepare('SELECT conversation_id,context_hash,request_hash,operation_kind,state,result_json FROM manager_send_attempts WHERE manager_id=? AND operation_id=?');$q->execute([$actor,$id]);return $q->fetch(PDO::FETCH_ASSOC)?:null;
    }
    private static function insert(PDO $pdo,int $actor,int $conversationId,string $id,string $context,?string $hash,string $state,string $kind,array $descriptor): void
    {
        $now=gmdate('Y-m-d H:i:s');$q=$pdo->prepare('INSERT INTO manager_send_attempts (manager_id,operation_id,conversation_id,context_hash,request_hash,operation_kind,state,result_json,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?,?,?)');$q->execute([$actor,$id,$conversationId,$context,$hash,$kind,$state,$descriptor?json_encode($descriptor,JSON_THROW_ON_ERROR):null,$now,$now]);
    }
    private static function replay(array $row,int $conversationId,string $context,?string $hash=null,string $kind='text'): array
    {
        if (($row['operation_kind']??'text')!==$kind||(int)$row['conversation_id']!==$conversationId||($hash!==null&&$row['state']!=='cancelled'&&!hash_equals((string)$row['request_hash'],$hash))) return self::error('send_attempt_mismatch');
        $saved=json_decode((string)($row['result_json']??''),true);
        $zeroDispatch=$row['state']==='rejected'&&(($saved['failure']['category']??'')==='attempt_context_changed'||($kind==='media'&&($saved['failure']['category']??'')==='not_dispatched'));
        if(!hash_equals((string)$row['context_hash'],$context)&&!$zeroDispatch)return self::error('send_attempt_mismatch');
        return self::view($row);
    }
    private static function view(array $row): array
    {
        $state=(string)$row['state'];$result=json_decode((string)($row['result_json']??''),true);$attempt=['state'=>$state];
        if(($row['operation_kind']??'text')==='media'){$attempt=['kind'=>'media','state'=>$state];foreach(['media_type','caption_included'] as $key)if(array_key_exists($key,$result??[]))$attempt[$key]=$result[$key];}
        return ['ok'=>$state==='confirmed','attempt'=>$attempt,...(is_array($result['failure']??null)?['failure'=>$result['failure']]:[])];
    }
    private static function error(string $error): array {return ['ok'=>false,'error'=>$error];}
}
