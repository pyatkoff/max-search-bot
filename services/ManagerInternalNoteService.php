<?php

declare(strict_types=1);
require_once __DIR__.'/ConversationDb.php';
require_once __DIR__.'/ManagerConversationService.php';

/** Protected staff annotations, entirely separate from messages and transport. */
final class ManagerInternalNoteService
{
    public static function list(int $actor,int $conversationId,array $input=[]): array
    {
        $pdo=ConversationDb::connection();$manager=self::actor($pdo,$actor);
        if ($manager===null) return self::error('unauthorized');
        if ($conversationId<=0) return self::error('invalid_conversation_id');
        if (ManagerConversationService::visibleConversation($conversationId,$actor)===null) return self::error('forbidden');
        $before=$input['before_id']??0;$limit=$input['page_size']??50;
        if (!is_int($before)||$before<0||!is_int($limit)||$limit<1||$limit>50) return self::error('invalid_page');
        $fetch=$limit+1;$where=$before>0?' AND n.id<?':'';
        $q=$pdo->prepare(self::select().' WHERE n.conversation_id=?'.$where.' ORDER BY n.id DESC LIMIT '.$fetch);
        $q->execute($before>0?[$conversationId,$before]:[$conversationId]);$rows=$q->fetchAll(PDO::FETCH_ASSOC);
        $more=count($rows)>$limit;if ($more) array_pop($rows);$rows=array_reverse($rows);
        return ['ok'=>true,'notes'=>array_map(static fn(array $row):array=>self::view($row,$manager),$rows),'history'=>['has_more'=>$more,'before_id'=>$rows?(int)$rows[0]['id']:0,'page_size'=>$limit,'mode'=>$before>0?'older':'latest']];
    }

    /** Reconcile only this actor+conversation and project CURRENT note state. */
    public static function operation(int $actor,int $conversationId,string $operationId): array
    {
        $pdo=ConversationDb::connection();$manager=self::actor($pdo,$actor);
        if ($manager===null) return self::error('unauthorized');
        if ($conversationId<=0) return self::error('invalid_conversation_id');
        if (ManagerConversationService::visibleConversation($conversationId,$actor)===null) return self::error('forbidden');
        if (!self::validOperation($operationId)) return self::error('invalid_operation_id');
        $receipt=self::receipt($pdo,$actor,$operationId);
        if ($receipt!==null&&$receipt['conversation_id']!==$conversationId) return self::error('operation_mismatch');
        return ['ok'=>true,'found'=>$receipt!==null,'result'=>$receipt===null?null:self::currentResult($pdo,$receipt['result'],$conversationId,$manager)];
    }

    public static function mutate(int $actor,int $conversationId,string $action,array $input): array
    {
        $pdo=ConversationDb::connection();$manager=self::actor($pdo,$actor);
        if ($manager===null) return self::error('unauthorized');
        if ($conversationId<=0) return self::error('invalid_conversation_id');
        if (ManagerConversationService::visibleConversation($conversationId,$actor)===null) return self::error('forbidden');
        if (!in_array($action,['create','update','delete'],true)) return self::error('unknown_action');
        $operationId=$input['operation_id']??null;
        if (!is_string($operationId)||!self::validOperation($operationId)) return self::error('invalid_operation_id');
        $id=$input['id']??0;$version=$input['version']??0;
        if (!is_int($id)||$id<0||!is_int($version)||$version<0||($action!=='create'&&($id<=0||$version<=0))) return self::error('invalid_version');
        if ($action==='create'&&($id!==0||$version!==0)) return self::error('invalid_version');
        $body='';
        if ($action!=='delete') {
            $normalized=self::normalize($input['body']??null);if (!$normalized['ok']) return $normalized;$body=$normalized['body'];
        }
        $hash=hash('sha256',json_encode(['action'=>$action,'conversation_id'=>$conversationId,'id'=>$id,'version'=>$version,'body'=>$body],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR));
        if ($pdo->inTransaction()) throw new LogicException('Internal notes require their own transaction');
        for ($attempt=0;$attempt<3;$attempt++) {
            $receipt=self::receipt($pdo,$actor,$operationId);
            if ($receipt!==null) return self::replay($pdo,$receipt,$hash,$conversationId,$manager);
            $pdo->beginTransaction();
            try {
                $manager=self::actor($pdo,$actor,true);
                if ($manager===null) {$pdo->rollBack();return self::error('unauthorized');}
                if (ManagerConversationService::visibleConversation($conversationId,$actor)===null) {$pdo->rollBack();return self::error('forbidden');}
                $q=$pdo->prepare('INSERT INTO manager_internal_note_operations (manager_id,operation_id,conversation_id,request_hash,created_at) VALUES (?,?,?,?,?)');$q->execute([$actor,$operationId,$conversationId,$hash,gmdate('Y-m-d H:i:s')]);
                $now=gmdate('Y-m-d H:i:s');
                if ($action==='create') {
                    $q=$pdo->prepare('INSERT INTO manager_internal_notes (conversation_id,author_manager_id,body,version,created_at,updated_at) VALUES (?,?,?,1,?,?)');$q->execute([$conversationId,$actor,$body,$now,$now]);
                    $result=['ok'=>true,'id'=>(int)$pdo->lastInsertId(),'saved_version'=>1];
                } else {
                    $row=self::note($pdo,$conversationId,$id,true);
                    if ($row===null) $result=self::error('not_found');
                    elseif ((int)$row['author_manager_id']!==$actor&&$manager['role']!=='admin') $result=self::error('forbidden');
                    elseif ($row['deleted_at']!==null) $result=self::error('deleted')+['id'=>$id];
                    elseif ((int)$row['version']!==$version) $result=self::error('conflict')+['id'=>$id];
                    else {
                        if ($action==='delete') {$q=$pdo->prepare('UPDATE manager_internal_notes SET body=?,deleted_at=?,updated_at=?,version=version+1 WHERE id=? AND conversation_id=? AND version=?');$q->execute(['',$now,$now,$id,$conversationId,$version]);}
                        elseif ($body===$row['body']) $q=null;
                        else {$q=$pdo->prepare('UPDATE manager_internal_notes SET body=?,updated_at=?,version=version+1 WHERE id=? AND conversation_id=? AND version=?');$q->execute([$body,$now,$id,$conversationId,$version]);}
                        if ($q!==null&&$q->rowCount()!==1) throw new RuntimeException('internal_note_revision_failure');
                        // Immutable command provenance is separate from the
                        // current note projected on replay. A newer edit must
                        // never silently rebase an UNKNOWN caller's draft.
                        $result=['ok'=>true,'id'=>$id,'saved_version'=>$q===null?$version:$version+1];
                    }
                }
                $q=$pdo->prepare('UPDATE manager_internal_note_operations SET result_json=? WHERE manager_id=? AND operation_id=?');$q->execute([json_encode($result,JSON_THROW_ON_ERROR),$actor,$operationId]);
                $response=self::currentResult($pdo,$result,$conversationId,$manager);$pdo->commit();return $response;
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                if ($e instanceof PDOException&&in_array((string)$e->getCode(),['23000','23505'],true)) {
                    $receipt=self::receipt($pdo,$actor,$operationId);if ($receipt!==null) return self::replay($pdo,$receipt,$hash,$conversationId,$manager);
                }
                if ($attempt<2&&$e instanceof PDOException&&((string)$e->getCode()==='40001'||in_array((int)($e->errorInfo[1]??0),[5,6,1213],true))) {usleep(10000*($attempt+1));continue;}
                throw $e;
            }
        }
        throw new LogicException('Unreachable internal note retry');
    }

    public static function normalize($body): array
    {
        if (!is_string($body)||preg_match('//u',$body)!==1) return self::error('invalid_body');
        $body=trim(str_replace(["\r\n","\r"],"\n",$body));
        $length=function_exists('mb_strlen')?mb_strlen($body,'UTF-8'):preg_match_all('/./us',$body);
        if ($body===''||$length>4000||preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/u',$body)) return self::error('invalid_body');
        return ['ok'=>true,'body'=>$body];
    }
    private static function actor(PDO $pdo,int $id,bool $lock=false): ?array
    {
        if ($id<=0) return null;$suffix=$lock&&$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'?' FOR UPDATE':'';
        $q=$pdo->prepare('SELECT id,role,is_active FROM managers WHERE id=?'.$suffix);$q->execute([$id]);$row=$q->fetch(PDO::FETCH_ASSOC);
        return !$row||(int)$row['is_active']!==1?null:['id'=>(int)$row['id'],'role'=>(string)$row['role']];
    }
    private static function select(): string {return 'SELECT n.*,m.display_name AS author_name,m.login AS author_login FROM manager_internal_notes n LEFT JOIN managers m ON m.id=n.author_manager_id';}
    private static function note(PDO $pdo,int $conversationId,int $id,bool $lock=false): ?array
    {
        $suffix=$lock&&$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'?' FOR UPDATE':'';
        // Lock only the note, not an outer-joined colleague account. Two editors
        // already hold their own actor locks; joining another actor here would
        // introduce an avoidable cross-account lock cycle.
        if ($lock) {
            $q=$pdo->prepare('SELECT * FROM manager_internal_notes WHERE id=? AND conversation_id=?'.$suffix);$q->execute([$id,$conversationId]);$row=$q->fetch(PDO::FETCH_ASSOC);
            if (!$row) return null;
            return $row;
        }
        $q=$pdo->prepare(self::select().' WHERE n.id=? AND n.conversation_id=?');$q->execute([$id,$conversationId]);$row=$q->fetch(PDO::FETCH_ASSOC);return $row?:null;
    }
    private static function view(array $row,array $manager): array
    {
        return ['id'=>(int)$row['id'],'conversation_id'=>(int)$row['conversation_id'],'body'=>$row['deleted_at']===null?(string)$row['body']:'','version'=>(int)$row['version'],'author_id'=>(int)$row['author_manager_id'],'author_name'=>(string)($row['author_name']?:($row['author_login']?:'Сотрудник')),'created_at'=>(string)$row['created_at'],'updated_at'=>(string)$row['updated_at'],'deleted'=>$row['deleted_at']!==null,'can_edit'=>$row['deleted_at']===null&&((int)$row['author_manager_id']===$manager['id']||$manager['role']==='admin')];
    }
    private static function receipt(PDO $pdo,int $actor,string $id): ?array
    {
        $q=$pdo->prepare('SELECT conversation_id,request_hash,result_json FROM manager_internal_note_operations WHERE manager_id=? AND operation_id=?');$q->execute([$actor,$id]);$row=$q->fetch(PDO::FETCH_ASSOC);
        return !$row||$row['result_json']===null?null:['conversation_id'=>(int)$row['conversation_id'],'hash'=>$row['request_hash'],'result'=>json_decode((string)$row['result_json'],true,32,JSON_THROW_ON_ERROR)];
    }
    private static function currentResult(PDO $pdo,array $result,int $conversationId,array $manager): array
    {
        if (isset($result['id'])) {
            // MySQL REPEATABLE READ: permission reads may establish an older
            // snapshot before another editor commits. Keep the actual current
            // locking read for conflict/deletion projection, never re-read its
            // body/version through that older non-locking snapshot.
            $note=self::note($pdo,$conversationId,(int)$result['id'],$pdo->inTransaction());
            if ($note!==null&&$pdo->inTransaction()) {
                $q=$pdo->prepare('SELECT display_name AS author_name,login AS author_login FROM managers WHERE id=?');$q->execute([(int)$note['author_manager_id']]);
                $note=array_merge($note,$q->fetch(PDO::FETCH_ASSOC)?:['author_name'=>null,'author_login'=>null]);
            }
            $result['note']=$note===null?null:self::view($note,$manager);
        }
        return $result;
    }
    private static function replay(PDO $pdo,array $receipt,string $hash,int $conversationId,array $manager): array
    {
        if ($receipt['conversation_id']!==$conversationId||!hash_equals($receipt['hash'],$hash)) return self::error('operation_mismatch');
        return self::currentResult($pdo,$receipt['result'],$conversationId,$manager);
    }
    private static function validOperation(string $id): bool {return preg_match('/^[A-Za-z0-9_-]{16,96}$/D',$id)===1;}
    private static function error(string $error): array {return ['ok'=>false,'error'=>$error];}
}
