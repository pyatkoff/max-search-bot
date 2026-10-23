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
        $hash=hash('sha256',json_encode([trim($text),$aiDraftId],JSON_THROW_ON_ERROR));
        $pdo=ConversationDb::connection();
        if ($pdo->inTransaction()) throw new LogicException('Send attempts require their own transaction');
        try {
            $pdo->beginTransaction();$context=self::context($pdo,$actor,$conversationId,true);
            if ($context===null) {$pdo->rollBack();return self::error('forbidden');}
            $row=self::row($pdo,$actor,$operationId);
            if ($row!==null) {$pdo->commit();return self::replay($row,$conversationId,$context,$hash);}
            self::insert($pdo,$actor,$conversationId,$operationId,$context,$hash,'processing');
            // Commit the unique fence BEFORE any external call, without holding
            // a conversation lock over the network. Crash/timeout stays UNKNOWN.
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            // A duplicate key can race across different conversations. Never
            // fail open into a second transport call, even if readback fails.
            try {$context=self::context($pdo,$actor,$conversationId);$row=self::row($pdo,$actor,$operationId);if ($context!==null&&$row!==null) return self::replay($row,$conversationId,$context,$hash);}catch(Throwable $ignored){}
            return self::error('send_attempt_unavailable');
        }
        $state='unknown';$failure=null;
        try {
            $result=$send();
            if (($result['ok']??false)===true) $state='confirmed';
            else {
                $raw=$result['failure']??null;
                if (is_array($raw)) {
                    $category=(string)($raw['category']??'unknown');$code=(int)($raw['http_code']??0);
                    $definite=in_array($category,['suspended','blocked','unsupported'],true)||($code>=400&&$code<500&&!in_array($code,[408,409,429],true));
                    if ($definite) {$state='rejected';$failure=['category'=>in_array($category,['suspended','blocked','unavailable','unsupported'],true)?$category:'unknown'];}
                }
            }
        } catch (Throwable $e) { /* No exception/provider payload in the receipt. */ }
        try {
            $q=$pdo->prepare('UPDATE manager_send_attempts SET state=?,result_json=?,updated_at=? WHERE manager_id=? AND operation_id=? AND state=?');
            $q->execute([$state,json_encode(['failure'=>$failure],JSON_THROW_ON_ERROR),gmdate('Y-m-d H:i:s'),$actor,$operationId,'processing']);
            if ($q->rowCount()!==1) return self::error('send_attempt_unavailable');
        } catch(Throwable $e) {return self::error('send_attempt_unavailable');}
        return self::view(['state'=>$state,'result_json'=>json_encode(['failure'=>$failure])]);
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

    /** Explicit cancellation only if execution has never reserved this ID.
     * A durable tombstone fences a delayed original request, not a provider call.
     */
    public static function cancel(int $actor,int $conversationId,string $operationId): array
    {
        if (!self::valid($operationId)||$conversationId<=0) return self::error('invalid_send_attempt');
        $pdo=ConversationDb::connection();
        if ($pdo->inTransaction()) throw new LogicException('Send cancellation requires its own transaction');
        try {
            $pdo->beginTransaction();$context=self::context($pdo,$actor,$conversationId,true);
            if ($context===null) {$pdo->rollBack();return self::error('forbidden');}
            $row=self::row($pdo,$actor,$operationId);
            if ($row===null) {self::insert($pdo,$actor,$conversationId,$operationId,$context,null,'cancelled');$row=['state'=>'cancelled','result_json'=>null];$result=self::view($row);}
            else $result=self::replay($row,$conversationId,$context);
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
        return hash('sha256',json_encode([$conversationId,(string)$c['project_key'],(int)$c['source_id'],(string)$c['channel'],(string)$c['external_chat_id']],JSON_THROW_ON_ERROR));
    }
    private static function row(PDO $pdo,int $actor,string $id): ?array
    {
        $q=$pdo->prepare('SELECT conversation_id,context_hash,request_hash,state,result_json FROM manager_send_attempts WHERE manager_id=? AND operation_id=?');$q->execute([$actor,$id]);return $q->fetch(PDO::FETCH_ASSOC)?:null;
    }
    private static function insert(PDO $pdo,int $actor,int $conversationId,string $id,string $context,?string $hash,string $state): void
    {
        $now=gmdate('Y-m-d H:i:s');$q=$pdo->prepare('INSERT INTO manager_send_attempts (manager_id,operation_id,conversation_id,context_hash,request_hash,state,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?)');$q->execute([$actor,$id,$conversationId,$context,$hash,$state,$now,$now]);
    }
    private static function replay(array $row,int $conversationId,string $context,?string $hash=null): array
    {
        if ((int)$row['conversation_id']!==$conversationId||!hash_equals((string)$row['context_hash'],$context)||($hash!==null&&$row['state']!=='cancelled'&&!hash_equals((string)$row['request_hash'],$hash))) return self::error('send_attempt_mismatch');
        return self::view($row);
    }
    private static function view(array $row): array
    {
        $state=(string)$row['state'];$result=json_decode((string)($row['result_json']??''),true);
        return ['ok'=>$state==='confirmed','attempt'=>['state'=>$state],...(is_array($result['failure']??null)?['failure'=>$result['failure']]:[])];
    }
    private static function error(string $error): array {return ['ok'=>false,'error'=>$error];}
}
