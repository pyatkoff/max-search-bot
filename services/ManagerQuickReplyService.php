<?php

declare(strict_types=1);

require_once __DIR__.'/ConversationDb.php';

/** Canonical saved reply library. Never sends a message or changes a conversation. */
final class ManagerQuickReplyService
{
    public static function list(int $actor, bool $admin, array $input=[]): array
    {
        if ($actor<=0) return ['ok'=>false,'error'=>'unauthorized'];
        $pdo=ConversationDb::connection();
        $params=[$actor,$actor];
        $where="(r.scope='shared' OR (r.scope='personal' AND r.owner_manager_id=?))";
        $scope=(string)($input['scope']??'all');
        if (!in_array($scope,['all','personal','shared'],true)) return ['ok'=>false,'error'=>'invalid_scope'];
        if ($scope!=='all') {$where.=' AND r.scope=?';$params[]=$scope;}
        $category=trim((string)($input['category']??''));
        if ($category!=='') {$where.=' AND r.category=?';$params[]=$category;}
        if (!empty($input['favorites_only'])) $where.=' AND f.reply_id IS NOT NULL';
        $search=trim((string)($input['search']??''));
        if (self::length($search)>120) return ['ok'=>false,'error'=>'invalid_search'];
        if ($search!=='') {
            $term='%'.str_replace(['!','%','_'],['!!','!%','!_'],$search).'%';
            $where.=" AND (r.title LIKE ? ESCAPE '!' OR r.body LIKE ? ESCAPE '!' OR r.category LIKE ? ESCAPE '!')";
            array_push($params,$term,$term,$term);
        }
        $offset=max(0,(int)($input['offset']??0));
        if ($offset>10000) return ['ok'=>false,'error'=>'invalid_offset'];
        $limit=max(1,min(100,(int)($input['limit']??50)));$fetch=$limit+1;
        $q=$pdo->prepare("SELECT r.*,CASE WHEN f.reply_id IS NULL THEN 0 ELSE 1 END AS is_favorite FROM manager_quick_replies r LEFT JOIN manager_quick_reply_favorites f ON f.reply_id=r.id AND f.manager_id=? WHERE {$where} ORDER BY is_favorite DESC,r.id DESC LIMIT {$fetch} OFFSET {$offset}");
        $q->execute($params);$rows=$q->fetchAll(PDO::FETCH_ASSOC);$more=count($rows)>$limit;
        $rows=array_map(static fn(array $r):array=>self::view($r,$actor,$admin),array_slice($rows,0,$limit));
        $q=$pdo->prepare("SELECT DISTINCT category FROM manager_quick_replies WHERE (scope='shared' OR (scope='personal' AND owner_manager_id=?)) AND category<>'' ORDER BY category LIMIT 201");
        $q->execute([$actor]);$categories=$q->fetchAll(PDO::FETCH_COLUMN);
        return ['ok'=>true,'replies'=>$rows,'categories'=>array_slice($categories,0,200),'categories_has_more'=>count($categories)>200,'has_more'=>$more,'next_offset'=>$more?$offset+$limit:null];
    }

    /** Recover only the current account's committed receipt. Unknown is never success. */
    public static function operation(int $actor, string $operationId): array
    {
        if ($actor<=0) return ['ok'=>false,'error'=>'unauthorized'];
        if (!self::validOperationId($operationId)) return ['ok'=>false,'error'=>'invalid_operation_id'];
        $receipt=self::receipt(ConversationDb::connection(),$actor,$operationId);
        return ['ok'=>true,'found'=>$receipt!==null,'result'=>$receipt['result']??null];
    }

    public static function mutate(int $actor, bool $admin, string $action, array $input): array
    {
        if ($actor<=0) return ['ok'=>false,'error'=>'unauthorized'];
        if (!in_array($action,['create','update','copy','delete','favorite'],true)) return ['ok'=>false,'error'=>'unknown_action'];
        $operationId=(string)($input['operation_id']??'');
        if (!self::validOperationId($operationId)) return ['ok'=>false,'error'=>'invalid_operation_id'];
        // CSRF rotates independently; compare only fields belonging to this command.
        $fields=['id','version','title','body','category','scope','is_favorite'];$command=['action'=>$action];
        foreach ($fields as $field) if (array_key_exists($field,$input)) $command[$field]=$input[$field];
        $hash=hash('sha256',(string)json_encode($command,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR));
        $pdo=ConversationDb::connection();
        $old=self::receipt($pdo,$actor,$operationId);
        if ($old!==null) return self::replay($old,$hash);
        if ($pdo->inTransaction()) throw new LogicException('Quick reply mutation requires its own transaction');
        $pdo->beginTransaction();
        try {
            $q=$pdo->prepare('INSERT INTO manager_quick_reply_operations (manager_id,operation_id,request_hash,created_at) VALUES (?,?,?,?)');
            $q->execute([$actor,$operationId,$hash,gmdate('Y-m-d H:i:s')]);
            $result=self::apply($pdo,$actor,$admin,$action,$input);
            $q=$pdo->prepare('UPDATE manager_quick_reply_operations SET result_json=? WHERE manager_id=? AND operation_id=?');
            $q->execute([json_encode($result,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR),$actor,$operationId]);
            $pdo->commit();
            return $result;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            // A simultaneous retry waits on the unique key; after the other transaction
            // commits, its receipt is the authoritative result (no second application).
            if ($e instanceof PDOException && in_array((string)$e->getCode(),['23000','23505'],true)) {
                $old=self::receipt($pdo,$actor,$operationId);
                if ($old!==null) return self::replay($old,$hash);
            }
            throw $e;
        }
    }

    public static function normalize(array $input): array
    {
        foreach (['title','body','category'] as $field) if (isset($input[$field])&&!is_string($input[$field])) return ['ok'=>false,'error'=>'invalid_'.$field];
        $title=trim((string)($input['title']??''));
        $body=trim(str_replace(["\r\n","\r"],"\n",(string)($input['body']??'')));
        $category=trim((string)($input['category']??''));
        if ($title===''||self::length($title)>120||preg_match('/[\x00-\x1f\x7f]/u',$title)) return ['ok'=>false,'error'=>'invalid_title'];
        if ($body===''||self::length($body)>8000||preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/u',$body)) return ['ok'=>false,'error'=>'invalid_body'];
        if (self::length($category)>64||preg_match('/[\x00-\x1f\x7f]/u',$category)) return ['ok'=>false,'error'=>'invalid_category'];
        return ['ok'=>true,'title'=>$title,'body'=>$body,'category'=>$category];
    }

    private static function apply(PDO $pdo, int $actor, bool $admin, string $action, array $input): array
    {
        $now=gmdate('Y-m-d H:i:s');
        if ($action==='create') {
            $scope=(string)($input['scope']??'personal');
            if (!in_array($scope,['personal','shared'],true)) return ['ok'=>false,'error'=>'invalid_scope'];
            if ($scope==='shared'&&!$admin) return ['ok'=>false,'error'=>'forbidden'];
            $value=self::normalize($input);if (!$value['ok']) return $value;
            return self::insert($pdo,$actor,$admin,$scope,$value,$now);
        }
        $id=(int)($input['id']??0);
        $row=self::get($pdo,$actor,$id,true);
        if (!$row) return ['ok'=>false,'error'=>'not_found'];
        if ($action==='copy') return self::insert($pdo,$actor,$admin,'personal',['title'=>$row['title'],'body'=>$row['body'],'category'=>$row['category']],$now);
        if ($action==='favorite') {
            if (!isset($input['is_favorite'])||!is_bool($input['is_favorite'])) return ['ok'=>false,'error'=>'invalid_favorite'];
            $q=$pdo->prepare('DELETE FROM manager_quick_reply_favorites WHERE manager_id=? AND reply_id=?');$q->execute([$actor,$id]);
            if ($input['is_favorite']) {$q=$pdo->prepare('INSERT INTO manager_quick_reply_favorites (manager_id,reply_id,created_at) VALUES (?,?,?)');$q->execute([$actor,$id,$now]);}
            return ['ok'=>true,'reply'=>self::view(self::get($pdo,$actor,$id),$actor,$admin)];
        }
        if ($row['scope']==='shared'&&!$admin) return ['ok'=>false,'error'=>'forbidden'];
        if ((int)($input['version']??0)!==(int)$row['version']) return ['ok'=>false,'error'=>'conflict','current'=>self::view($row,$actor,$admin)];
        if ($action==='delete') {
            $q=$pdo->prepare('DELETE FROM manager_quick_reply_favorites WHERE reply_id=?');$q->execute([$id]);
            $q=$pdo->prepare('DELETE FROM manager_quick_replies WHERE id=? AND version=?');$q->execute([$id,(int)$row['version']]);
            self::audit($pdo,$actor,'deleted',$row,null);
            return ['ok'=>true,'deleted_id'=>$id];
        }
        $value=self::normalize($input);if (!$value['ok']) return $value;
        $q=$pdo->prepare('UPDATE manager_quick_replies SET title=?,body=?,category=?,version=version+1,updated_by_manager_id=?,updated_at=? WHERE id=? AND version=?');
        $q->execute([$value['title'],$value['body'],$value['category'],$actor,$now,$id,(int)$row['version']]);
        if ($q->rowCount()!==1) return ['ok'=>false,'error'=>'conflict'];
        $after=self::get($pdo,$actor,$id);
        self::audit($pdo,$actor,'updated',$row,$after);
        return ['ok'=>true,'reply'=>self::view($after,$actor,$admin)];
    }

    private static function insert(PDO $pdo, int $actor, bool $admin, string $scope, array $value, string $now): array
    {
        $q=$pdo->prepare('INSERT INTO manager_quick_replies (owner_manager_id,scope,title,body,category,version,created_by_manager_id,updated_by_manager_id,created_at,updated_at) VALUES (?,?,?,?,?,1,?,?,?,?)');
        $q->execute([$scope==='personal'?$actor:null,$scope,$value['title'],$value['body'],$value['category'],$actor,$actor,$now,$now]);
        $id=(int)$pdo->lastInsertId();$after=self::get($pdo,$actor,$id);
        self::audit($pdo,$actor,'created',null,$after);
        return ['ok'=>true,'reply'=>self::view($after,$actor,$admin)];
    }

    private static function get(PDO $pdo, int $actor, int $id, bool $lock=false): ?array
    {
        if ($id<=0) return null;
        $suffix=$lock&&$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'?' FOR UPDATE':'';
        // Lock the reply row itself. Favorites are loaded separately only for a
        // singleton command; lists use one joined query, never N+1.
        $q=$pdo->prepare("SELECT * FROM manager_quick_replies WHERE id=? AND (scope='shared' OR (scope='personal' AND owner_manager_id=?)){$suffix}");
        $q->execute([$id,$actor]);$row=$q->fetch(PDO::FETCH_ASSOC);if (!$row) return null;
        $q=$pdo->prepare('SELECT 1 FROM manager_quick_reply_favorites WHERE manager_id=? AND reply_id=?');$q->execute([$actor,$id]);
        $row['is_favorite']=(bool)$q->fetchColumn();return $row;
    }

    private static function view(array $row, int $actor, bool $admin): array
    {
        return ['id'=>(int)$row['id'],'title'=>(string)$row['title'],'body'=>(string)$row['body'],'category'=>(string)$row['category'],'scope'=>(string)$row['scope'],'version'=>(int)$row['version'],'is_favorite'=>!empty($row['is_favorite']),'can_edit'=>$row['scope']==='shared'?$admin:(int)$row['owner_manager_id']===$actor,'created_at'=>(string)$row['created_at'],'updated_at'=>(string)$row['updated_at']];
    }

    /** Shared edits have a canonical audit entry in the same transaction. Personal
     * reply content stays account-owned; durable operation receipts track those writes. */
    private static function audit(PDO $pdo, int $actor, string $verb, ?array $before, ?array $after): void
    {
        $row=$after??$before;
        if (!$row||$row['scope']!=='shared') return;
        $summary=static fn(?array $r):?array=>$r===null?null:['title'=>$r['title'],'category'=>$r['category'],'version'=>(int)$r['version'],'scope'=>$r['scope']];
        $q=$pdo->prepare('INSERT INTO admin_audit_log (actor_manager_id,action,entity_type,entity_id,project_key,before_json,after_json) VALUES (?,?,?,?,?,?,?)');
        $q->execute([$actor,'quick_reply_'.$verb,'quick_reply',(string)$row['id'],null,$before===null?null:json_encode($summary($before),JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),$after===null?null:json_encode($summary($after),JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)]);
    }

    private static function receipt(PDO $pdo, int $actor, string $operationId): ?array
    {
        $q=$pdo->prepare('SELECT request_hash,result_json FROM manager_quick_reply_operations WHERE manager_id=? AND operation_id=?');$q->execute([$actor,$operationId]);$row=$q->fetch(PDO::FETCH_ASSOC);
        if (!$row||$row['result_json']===null) return null;
        return ['hash'=>(string)$row['request_hash'],'result'=>json_decode((string)$row['result_json'],true,512,JSON_THROW_ON_ERROR)];
    }

    private static function replay(array $receipt, string $hash): array
    {
        return hash_equals($receipt['hash'],$hash)?$receipt['result']:['ok'=>false,'error'=>'operation_mismatch'];
    }

    private static function validOperationId(string $id): bool {return preg_match('/^[A-Za-z0-9_-]{16,64}$/D',$id)===1;}
    private static function length(string $value): int {return mb_strlen($value,'UTF-8');}
}
