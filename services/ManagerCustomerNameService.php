<?php

declare(strict_types=1);
require_once __DIR__.'/ConversationDb.php';
require_once __DIR__.'/ManagerConversationService.php';
require_once __DIR__.'/ManagerConversationAccessPolicy.php';

/** Manager-only client labels. Does not change identity, transcript or lead payload. */
final class ManagerCustomerNameService
{
    public static function get(int $actor, int $conversationId, array $input=[]): array
    {
        $c=ManagerConversationService::visibleConversation($conversationId,$actor);
        if (!$c) return ['ok'=>false,'error'=>'not_found'];
        $pdo=ConversationDb::connection();$manager=ManagerAuthService::byId($actor);
        if (!$manager) return ['ok'=>false,'error'=>'unauthorized'];
        $name=self::snapshot($pdo,$c,$manager);
        $limit=max(1,min(50,(int)($input['limit']??20)));$fetch=$limit+1;
        $where='h.customer_id=?';$params=[(int)$c['customer_id']];
        $before=(int)($input['before_version']??0);
        if ($before>0) {$where.=' AND h.version<?';$params[]=$before;}
        $q=$pdo->prepare("SELECT h.version,h.before_name,h.after_name,h.created_at,m.display_name AS actor_name,m.login AS actor_login FROM manager_customer_name_history h LEFT JOIN managers m ON m.id=h.actor_manager_id WHERE {$where} ORDER BY h.version DESC LIMIT {$fetch}");
        $q->execute($params);$rows=$q->fetchAll(PDO::FETCH_ASSOC);$more=count($rows)>$limit;
        $history=array_map(static fn(array $r):array=>['version'=>(int)$r['version'],'before_name'=>$r['before_name'],'after_name'=>$r['after_name'],'actor_name'=>(string)($r['actor_name']?:$r['actor_login']?:'Сотрудник'),'created_at'=>(string)$r['created_at']],array_slice($rows,0,$limit));
        return ['ok'=>true,'name'=>$name,'history'=>$history,'history_has_more'=>$more];
    }

    public static function operation(int $actor, int $conversationId, string $operationId): array
    {
        if (!self::validOperation($operationId)) return ['ok'=>false,'error'=>'invalid_operation_id'];
        $c=ManagerConversationService::visibleConversation($conversationId,$actor);
        if (!$c) return ['ok'=>false,'error'=>'not_found'];
        $receipt=self::receipt(ConversationDb::connection(),$actor,$operationId,(int)$c['customer_id']);
        return ['ok'=>true,'found'=>$receipt!==null,'result'=>$receipt['result']??null];
    }

    public static function save(int $actor, int $conversationId, array $input): array
    {
        $operationId=(string)($input['operation_id']??'');
        if (!self::validOperation($operationId)) return ['ok'=>false,'error'=>'invalid_operation_id'];
        if (!array_key_exists('working_name',$input)) return ['ok'=>false,'error'=>'invalid_name'];
        $value=self::normalize($input['working_name']);if (!$value['ok']) return $value;
        if (!isset($input['version'])||!is_int($input['version'])||$input['version']<0) return ['ok'=>false,'error'=>'invalid_version'];
        $version=$input['version'];$workingName=$value['working_name'];
        // Bind the receipt to the exact customer resolved under the row lock,
        // as well as the source conversation; raw customer IDs are never input.
        $hash='';
        $pdo=ConversationDb::connection();
        if ($pdo->inTransaction()) throw new LogicException('Customer name mutation requires its own transaction');
        $pdo->beginTransaction();
        try {
            $lock=$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'?' FOR UPDATE':'';
            // This locks the customer too, serializing first writes from different
            // conversations before the optional name row exists. Assignment cannot
            // change between the fresh permission check and commit.
            $q=$pdo->prepare('SELECT c.id,c.customer_id,c.project_key,c.source_id,c.status,c.manager_id,c.started_at,c.last_message_at,cu.display_name AS original_display_name FROM conversations c JOIN customers cu ON cu.id=c.customer_id WHERE c.id=?'.$lock);
            $q->execute([$conversationId]);$c=$q->fetch(PDO::FETCH_ASSOC);
            $manager=ManagerAuthService::byId($actor);
            if (!$manager||!$c||!ManagerConversationAccessPolicy::canView($actor,$c)) {$pdo->rollBack();return ['ok'=>false,'error'=>'not_found'];}
            if (!ManagerConversationAccessPolicy::canEditVisibleConversation($c,$manager)) {$pdo->rollBack();return ['ok'=>false,'error'=>'forbidden'];}
            $hash=hash('sha256',json_encode([$conversationId,(int)$c['customer_id'],$version,$workingName],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR));
            $old=self::receipt($pdo,$actor,$operationId);
            if ($old!==null) {$pdo->rollBack();return hash_equals($old['hash'],$hash)?$old['result']:['ok'=>false,'error'=>'operation_mismatch'];}
            $current=self::snapshot($pdo,$c,$manager);
            if ($current['version']!==$version) {$pdo->rollBack();return ['ok'=>false,'error'=>'conflict','current'=>$current];}
            $newVersion=$version+1;$now=gmdate('Y-m-d H:i:s');
            if ($version===0) {
                $q=$pdo->prepare('INSERT INTO manager_customer_names (customer_id,working_name,version,updated_by_manager_id,updated_at) VALUES (?,?,?,?,?)');
                $q->execute([(int)$c['customer_id'],$workingName,$newVersion,$actor,$now]);
            } else {
                $q=$pdo->prepare('UPDATE manager_customer_names SET working_name=?,version=?,updated_by_manager_id=?,updated_at=? WHERE customer_id=? AND version=?');
                $q->execute([$workingName,$newVersion,$actor,$now,(int)$c['customer_id'],$version]);
                if ($q->rowCount()!==1) {$pdo->rollBack();return ['ok'=>false,'error'=>'conflict'];}
            }
            $after=self::snapshot($pdo,$c,$manager);$result=['ok'=>true,'name'=>$after];
            $q=$pdo->prepare('INSERT INTO manager_customer_name_history (customer_id,version,conversation_id,actor_manager_id,operation_id,request_hash,before_name,after_name,result_json,created_at) VALUES (?,?,?,?,?,?,?,?,?,?)');
            $q->execute([(int)$c['customer_id'],$newVersion,$conversationId,$actor,$operationId,$hash,$current['working_name'],$workingName,json_encode($result,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),$now]);
            // A canonical audit insert participates in this transaction; failure
            // cannot leave a saved label with a failed or missing confirmation.
            $q=$pdo->prepare('INSERT INTO admin_audit_log (actor_manager_id,action,entity_type,entity_id,project_key,before_json,after_json) VALUES (?,?,?,?,?,?,?)');
            $q->execute([$actor,$workingName===null?'customer_name_restored':'customer_name_updated','customer_name',(string)$c['customer_id'],(string)$c['project_key'],json_encode(['working_name'=>$current['working_name'],'version'=>$version],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),json_encode(['working_name'=>$workingName,'version'=>$newVersion],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)]);
            $pdo->commit();return $result;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            // Two distinct customers may race with the same operation key. The
            // unique account key rolls the loser back; never expose its receipt.
            if ($e instanceof PDOException&&in_array((string)$e->getCode(),['23000','23505'],true)) {
                $hash=hash('sha256',json_encode([$conversationId,(int)$c['customer_id'],$version,$workingName],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR));
            $old=self::receipt($pdo,$actor,$operationId);
                if ($old!==null) return hash_equals($old['hash'],$hash)?$old['result']:['ok'=>false,'error'=>'operation_mismatch'];
            }
            throw $e;
        }
    }

    public static function normalize($name): array
    {
        if ($name===null) return ['ok'=>true,'working_name'=>null];
        if (!is_string($name)||!mb_check_encoding($name,'UTF-8')) return ['ok'=>false,'error'=>'invalid_name'];
        $name=trim($name);
        if ($name===''||mb_strlen($name,'UTF-8')>120||preg_match('/[\x00-\x1f\x7f]/u',$name)) return ['ok'=>false,'error'=>'invalid_name'];
        return ['ok'=>true,'working_name'=>$name];
    }

    private static function snapshot(PDO $pdo, array $c, array $manager): array
    {
        $q=$pdo->prepare('SELECT n.working_name,n.version,n.updated_at,m.display_name AS updated_by_manager_name,m.login AS updated_by_manager_login FROM manager_customer_names n LEFT JOIN managers m ON m.id=n.updated_by_manager_id WHERE n.customer_id=?');
        $q->execute([(int)$c['customer_id']]);$n=$q->fetch(PDO::FETCH_ASSOC)?:[];
        $original=(string)($c['original_display_name']??'');$working=$n['working_name']??null;
        return ['customer_id'=>(int)$c['customer_id'],'original_display_name'=>$original,'working_name'=>$working,'display_name'=>$working??($original!==''?$original:'Турист'),'version'=>(int)($n['version']??0),'updated_at'=>$n['updated_at']??null,'updated_by_manager_name'=>$n?($n['updated_by_manager_name']?:$n['updated_by_manager_login']?:'Сотрудник'):null,'can_edit'=>ManagerConversationAccessPolicy::canEditVisibleConversation($c,$manager)];
    }

    private static function receipt(PDO $pdo, int $actor, string $operationId, ?int $customerId=null): ?array
    {
        $where='actor_manager_id=? AND operation_id=?';$params=[$actor,$operationId];
        if ($customerId!==null) {$where.=' AND customer_id=?';$params[]=$customerId;}
        $q=$pdo->prepare('SELECT request_hash,result_json FROM manager_customer_name_history WHERE '.$where.' LIMIT 1');$q->execute($params);$row=$q->fetch(PDO::FETCH_ASSOC);
        return $row?['hash'=>(string)$row['request_hash'],'result'=>json_decode((string)$row['result_json'],true,512,JSON_THROW_ON_ERROR)]:null;
    }
    private static function validOperation(string $id): bool {return preg_match('/^[A-Za-z0-9_-]{16,64}$/D',$id)===1;}
}
