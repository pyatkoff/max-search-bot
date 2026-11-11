<?php

declare(strict_types=1);

require_once __DIR__.'/ConversationDb.php';
require_once __DIR__.'/ManagerAuthService.php';
require_once __DIR__.'/ManagerConversationService.php';
require_once __DIR__.'/ManagerQuickReplyMaterials.php';

/** Canonical saved reply library. Never sends a message or changes a conversation. */
final class ManagerQuickReplyService
{
    private const MAX_TOOLBAR_REPLIES=100;

    /** A read never initializes account state or selects any default replies. */
    public static function toolbar(int $actor, bool $admin=false, array $input=[]): array
    {
        if ($actor<=0) return ['ok'=>false,'error'=>'unauthorized'];
        $ctx=self::context($actor,$input['project_key']??null);
        if (!$ctx) return ['ok'=>false,'error'=>'not_found'];
        $result=self::toolbarState(ConversationDb::connection(),$actor,$ctx);
        return self::current($actor,$ctx)?$result:['ok'=>false,'error'=>'not_found'];
    }

    public static function list(int $actor, bool $admin, array $input=[]): array
    {
        if ($actor<=0) return ['ok'=>false,'error'=>'unauthorized'];
        $ctx=self::context($actor,$input['project_key']??null);
        if (!$ctx) return ['ok'=>false,'error'=>'not_found'];
        $admin=$ctx['admin'];
        $pdo=ConversationDb::connection();
        $params=[$actor,$actor];
        $where="(r.scope='shared' OR (r.scope='personal' AND r.owner_manager_id=?)) AND ".self::projectSql($ctx,'r.');
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
        $rows=array_map(static fn(array $r):array=>self::view($r,$actor,$ctx),array_slice($rows,0,$limit));
        $q=$pdo->prepare("SELECT DISTINCT category FROM manager_quick_replies WHERE (scope='shared' OR (scope='personal' AND owner_manager_id=?)) AND ".self::projectSql($ctx)." AND category<>'' ORDER BY category LIMIT 201");
        $q->execute([$actor]);$categories=$q->fetchAll(PDO::FETCH_COLUMN);
        if (!self::current($actor,$ctx)) return ['ok'=>false,'error'=>'not_found'];
        return ['ok'=>true,'project_key'=>$ctx['key'],'legacy_project'=>$ctx['legacy'],'upload_limits'=>ManagerQuickReplyMaterials::uploadLimits(),'replies'=>$rows,'categories'=>array_slice($categories,0,200),'categories_has_more'=>count($categories)>200,'has_more'=>$more,'next_offset'=>$more?$offset+$limit:null];
    }

    /** Recover only the current account's committed receipt. Unknown is never success. */
    public static function operation(int $actor, string $operationId, array $input=[]): array
    {
        if ($actor<=0) return ['ok'=>false,'error'=>'unauthorized'];
        if (!self::validOperationId($operationId)) return ['ok'=>false,'error'=>'invalid_operation_id'];
        $ctx=self::context($actor,$input['project_key']??null);
        if (!$ctx) return ['ok'=>false,'error'=>'not_found'];
        $receipt=self::receipt(ConversationDb::connection(),$actor,$operationId);
        if (!self::current($actor,$ctx)) return ['ok'=>false,'error'=>'not_found'];
        if ($receipt&&!self::receiptProject($receipt,$ctx)) $receipt=null;
        return ['ok'=>true,'found'=>$receipt!==null,'result'=>$receipt['result']??null];
    }

    public static function mutate(int $actor, bool $admin, string $action, array $input, array $files=[]): array
    {
        if ($actor<=0) return ['ok'=>false,'error'=>'unauthorized'];
        if (!in_array($action,['create','update','copy','delete','favorite','reorder'],true)) return ['ok'=>false,'error'=>'unknown_action'];
        $operationId=(string)($input['operation_id']??'');
        if (!self::validOperationId($operationId)) return ['ok'=>false,'error'=>'invalid_operation_id'];
        $ctx=self::context($actor,$input['project_key']??null);
        if (!$ctx) return ['ok'=>false,'error'=>'not_found'];
        try {$uploads=ManagerQuickReplyMaterials::inspectUploads($files);}
        catch (InvalidArgumentException $e) {return ['ok'=>false,'error'=>$e->getMessage()];}
        if ($uploads&&($input['upload_hashes']??null)!==array_column($uploads,'sha256')) return ['ok'=>false,'error'=>'material_changed'];
        if ($uploads&&!in_array($action,['create','update'],true)) return ['ok'=>false,'error'=>'invalid_materials'];
        // CSRF rotates independently; compare only fields belonging to this command.
        $fields=['id','version','title','body','category','scope','is_favorite','ids','toolbar_version','source_label','reviewed','materials','upload_hashes'];$command=['action'=>$action,'project_key'=>$ctx['key'],'uploads'=>ManagerQuickReplyMaterials::wireUploads($uploads)];
        foreach ($fields as $field) if (array_key_exists($field,$input)) $command[$field]=$input[$field];
        $hash=hash('sha256',(string)json_encode($command,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR));
        $pdo=ConversationDb::connection();
        if ($pdo->inTransaction()) throw new LogicException('Quick reply mutation requires its own transaction');
        for ($attempt=0;$attempt<3;$attempt++) {
            $old=self::receipt($pdo,$actor,$operationId);
            if ($old!==null) {
                if (!self::current($actor,$ctx)) return ['ok'=>false,'error'=>'not_found'];
                return self::receiptProject($old,$ctx)?self::replay($old,$hash):['ok'=>false,'error'=>'operation_mismatch'];
            }
            $created=[];
            $pdo->beginTransaction();
            try {
                // Reserve the durable command before transaction reads. This
                // also preserves SQLite's established writer serialization.
                // No reply/file is written until current locked access passes.
                $q=$pdo->prepare('INSERT INTO manager_quick_reply_operations (manager_id,operation_id,request_hash,created_at,project_id) VALUES (?,?,?,?,?)');
                $q->execute([$actor,$operationId,$hash,gmdate('Y-m-d H:i:s'),$ctx['id']]);
                $locked=self::context($actor,$ctx['key'],true);
                if (!$locked) {$pdo->rollBack();return ['ok'=>false,'error'=>'not_found'];}
                $ctx=$locked;$admin=$ctx['admin'];
                self::lockToolbar($pdo,$actor);
                try {$result=self::apply($pdo,$actor,$ctx,$action,$input,$uploads,$created);}
                catch (InvalidArgumentException $e) {$result=['ok'=>false,'error'=>$e->getMessage()];}
                if (!empty($result['ok'])) $result['toolbar']=self::toolbarState($pdo,$actor,$ctx,true);
                $q=$pdo->prepare('UPDATE manager_quick_reply_operations SET result_json=? WHERE manager_id=? AND operation_id=?');
                $q->execute([json_encode($result,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR),$actor,$operationId]);
                $pdo->commit();
                return $result;
            } catch (Throwable $e) {
                // Never delete private bytes after an uncertain commit. Only an
                // acknowledged rollback makes this command's new files unowned.
                if ($pdo->inTransaction()&&$pdo->rollBack()) ManagerQuickReplyMaterials::discard($created);
                // Accepted writes and their exact response commit together. A duplicate
                // request or deadlock retry retains the original command identity.
                if ($e instanceof PDOException && in_array((string)$e->getCode(),['23000','23505'],true)) {
                    $old=self::receipt($pdo,$actor,$operationId);
                    if ($old!==null) {
                        if (!self::current($actor,$ctx)) return ['ok'=>false,'error'=>'not_found'];
                        return self::receiptProject($old,$ctx)?self::replay($old,$hash):['ok'=>false,'error'=>'operation_mismatch'];
                    }
                }
                if ($attempt<2&&self::retryableLockFailure($e)) {usleep(10000*($attempt+1));continue;}
                throw $e;
            }
        }
        throw new LogicException('Unreachable quick reply retry state');
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
        $source=$input['source_label']??'';
        if (!is_string($source)||self::length(trim($source))>500||preg_match('/[\x00-\x1f\x7f]/u',$source)) return ['ok'=>false,'error'=>'invalid_source'];
        if (isset($input['reviewed'])&&!is_bool($input['reviewed'])) return ['ok'=>false,'error'=>'invalid_source'];
        if (!empty($input['reviewed'])&&trim($source)==='') return ['ok'=>false,'error'=>'source_required'];
        return ['ok'=>true,'title'=>$title,'body'=>$body,'category'=>$category,'source_label'=>trim($source),'reviewed'=>!empty($input['reviewed'])];
    }

    private static function apply(PDO $pdo, int $actor, array $ctx, string $action, array $input, array $uploads, array &$created): array
    {
        $admin=$ctx['admin'];
        $now=gmdate('Y-m-d H:i:s');
        if ($action==='reorder') return self::reorder($pdo,$actor,$ctx,$input);
        if ($action==='create') {
            $scope=(string)($input['scope']??'personal');
            if (!in_array($scope,['personal','shared'],true)) return ['ok'=>false,'error'=>'invalid_scope'];
            if ($scope==='shared'&&!$admin) return ['ok'=>false,'error'=>'forbidden'];
            $value=self::normalize($input);if (!$value['ok']) return $value;
            $plan=ManagerQuickReplyMaterials::plan($input['materials']??null,[],$uploads);
            $value['materials']=ManagerQuickReplyMaterials::persist($ctx['id'],$plan,$uploads,$created);
            return self::insert($pdo,$actor,$ctx,$scope,$value,$now);
        }
        $id=(int)($input['id']??0);
        $row=self::get($pdo,$actor,$id,$ctx,true);
        if (!$row) return ['ok'=>false,'error'=>'not_found'];
        if ($action==='copy') return self::insert($pdo,$actor,$ctx,'personal',[
            'title'=>$row['title'],'body'=>$row['body'],'category'=>$row['category'],
            'source_label'=>$row['source_label'],'reviewed_at'=>$row['reviewed_at'],
            'materials'=>ManagerQuickReplyMaterials::manifest($row['materials_json'])],$now);
        if ($action==='favorite') {
            if (!isset($input['is_favorite'])||!is_bool($input['is_favorite'])) return ['ok'=>false,'error'=>'invalid_favorite'];
            $toolbar=self::toolbarState($pdo,$actor,$ctx,true);
            if (array_key_exists('toolbar_version',$input)&&(!is_string($input['toolbar_version'])||!hash_equals($toolbar['version'],$input['toolbar_version']))) return ['ok'=>false,'error'=>'conflict','toolbar'=>$toolbar];
            if ($input['is_favorite']===!empty($row['is_favorite'])) return ['ok'=>true,'reply'=>self::view($row,$actor,$ctx)];
            if ($input['is_favorite']) {
                if (count($toolbar['replies'])>=self::MAX_TOOLBAR_REPLIES) return ['ok'=>false,'error'=>'toolbar_limit_exceeded','toolbar'=>$toolbar];
                $q=$pdo->prepare('SELECT COALESCE(MAX(sort_order),0)+1 FROM manager_quick_reply_favorites WHERE manager_id=?');$q->execute([$actor]);$position=(int)$q->fetchColumn();
                $q=$pdo->prepare('INSERT INTO manager_quick_reply_favorites (manager_id,reply_id,sort_order,created_at) VALUES (?,?,?,?)');$q->execute([$actor,$id,$position,$now]);
            } else {$q=$pdo->prepare('DELETE FROM manager_quick_reply_favorites WHERE manager_id=? AND reply_id=?');$q->execute([$actor,$id]);}
            self::advanceToolbar($pdo,$actor);
            return ['ok'=>true,'reply'=>self::view(self::get($pdo,$actor,$id,$ctx),$actor,$ctx)];
        }
        if ($row['scope']==='shared'&&!$admin) return ['ok'=>false,'error'=>'forbidden'];
        if ((int)($input['version']??0)!==(int)$row['version']) return ['ok'=>false,'error'=>'conflict','current'=>self::view($row,$actor,$ctx)];
        if ($action==='delete') {
            $q=$pdo->prepare('DELETE FROM manager_quick_reply_favorites WHERE reply_id=?');$q->execute([$id]);
            $q=$pdo->prepare('DELETE FROM manager_quick_replies WHERE id=? AND version=?');$q->execute([$id,(int)$row['version']]);
            self::audit($pdo,$actor,$ctx,'deleted',$row,null);
            return ['ok'=>true,'deleted_id'=>$id];
        }
        $value=self::normalize($input+['source_label'=>$row['source_label']]);if (!$value['ok']) return $value;
        $plan=ManagerQuickReplyMaterials::plan($input['materials']??null,ManagerQuickReplyMaterials::manifest($row['materials_json']),$uploads);
        $materials=ManagerQuickReplyMaterials::persist($ctx['id'],$plan,$uploads,$created);
        $q=$pdo->prepare('UPDATE manager_quick_replies SET title=?,body=?,category=?,project_id=?,source_label=?,reviewed_at=?,materials_json=?,version=version+1,updated_by_manager_id=?,updated_at=? WHERE id=? AND version=?');
        $q->execute([$value['title'],$value['body'],$value['category'],$ctx['id'],$value['source_label'],$value['reviewed']?$now:null,json_encode($materials,JSON_THROW_ON_ERROR),$actor,$now,$id,(int)$row['version']]);
        if ($q->rowCount()!==1) return ['ok'=>false,'error'=>'conflict'];
        $after=self::get($pdo,$actor,$id,$ctx);
        self::audit($pdo,$actor,$ctx,'updated',$row,$after);
        return ['ok'=>true,'reply'=>self::view($after,$actor,$ctx)];
    }

    private static function insert(PDO $pdo, int $actor, array $ctx, string $scope, array $value, string $now): array
    {
        $q=$pdo->prepare('INSERT INTO manager_quick_replies (owner_manager_id,scope,title,body,category,version,created_by_manager_id,updated_by_manager_id,created_at,updated_at,project_id,source_label,reviewed_at,materials_json) VALUES (?,?,?,?,?,1,?,?,?,?,?,?,?,?)');
        $q->execute([$scope==='personal'?$actor:null,$scope,$value['title'],$value['body'],$value['category'],$actor,$actor,$now,$now,$ctx['id'],$value['source_label'],$value['reviewed_at']??(!empty($value['reviewed'])?$now:null),json_encode($value['materials'],JSON_THROW_ON_ERROR)]);
        $id=(int)$pdo->lastInsertId();$after=self::get($pdo,$actor,$id,$ctx);
        self::audit($pdo,$actor,$ctx,'created',null,$after);
        return ['ok'=>true,'reply'=>self::view($after,$actor,$ctx)];
    }

    /** Exact membership is mandatory: a reorder may never select somebody else's
     * private reply, re-add a removed item or silently drop a concurrent selection. */
    private static function reorder(PDO $pdo, int $actor, array $ctx, array $input): array
    {
        $ids=$input['ids']??null;
        if (!is_array($ids)||!array_is_list($ids)||count($ids)>self::MAX_TOOLBAR_REPLIES) return ['ok'=>false,'error'=>'invalid_ids'];
        foreach ($ids as $id) if (!is_int($id)||$id<=0) return ['ok'=>false,'error'=>'invalid_ids'];
        if (count(array_unique($ids))!==count($ids)) return ['ok'=>false,'error'=>'invalid_ids'];
        $toolbar=self::toolbarState($pdo,$actor,$ctx,true);
        if (!$toolbar['ok']) return ['ok'=>false,'error'=>'toolbar_limit_exceeded','toolbar'=>$toolbar];
        if (!isset($input['toolbar_version'])||!is_string($input['toolbar_version'])||!hash_equals($toolbar['version'],$input['toolbar_version'])) return ['ok'=>false,'error'=>'conflict','toolbar'=>$toolbar];
        $current=array_column($toolbar['replies'],'id');$a=$ids;$b=$current;sort($a,SORT_NUMERIC);sort($b,SORT_NUMERIC);
        if ($a!==$b) return ['ok'=>false,'error'=>'conflict','toolbar'=>$toolbar];
        if ($ids!==$current) {
            // One bounded statement, not a query/write per toolbar button.
            $cases=[];$params=[];
            foreach ($ids as $position=>$id) {$cases[]='WHEN ? THEN ?';array_push($params,$id,$position+1);}
            $params[]=$actor;
            $q=$pdo->prepare('UPDATE manager_quick_reply_favorites SET sort_order=CASE reply_id '.implode(' ',$cases).' ELSE sort_order END WHERE manager_id=?');$q->execute($params);
            self::advanceToolbar($pdo,$actor);
        }
        return ['ok'=>true];
    }

    private static function toolbarState(PDO $pdo, int $actor, array $ctx, bool $lock=false): array
    {
        $limit=self::MAX_TOOLBAR_REPLIES+1;
        $suffix=$lock&&$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'?' FOR UPDATE':'';
        $q=$pdo->prepare('SELECT revision FROM manager_quick_reply_toolbar_states WHERE manager_id=?'.$suffix);$q->execute([$actor]);$revision=(int)($q->fetchColumn()?:0);
        $q=$pdo->prepare("SELECT r.*,1 AS is_favorite,f.sort_order FROM manager_quick_reply_favorites f INNER JOIN manager_quick_replies r ON r.id=f.reply_id WHERE f.manager_id=? AND (r.scope='shared' OR (r.scope='personal' AND r.owner_manager_id=?)) AND ".self::projectSql($ctx,'r.')." ORDER BY f.sort_order ASC,f.created_at ASC,f.reply_id ASC LIMIT {$limit}{$suffix}");
        $q->execute([$actor,$actor]);$rows=$q->fetchAll(PDO::FETCH_ASSOC);$overflow=count($rows)>self::MAX_TOOLBAR_REPLIES;
        $version=hash('sha256',json_encode(['project_id'=>$ctx['id'],'revision'=>$revision,'rows'=>array_map(static fn(array $r):array=>[(int)$r['id'],(int)$r['version'],(int)$r['sort_order']],$rows)],JSON_THROW_ON_ERROR));
        $result=['ok'=>!$overflow,'project_key'=>$ctx['key'],'legacy_project'=>$ctx['legacy'],'upload_limits'=>ManagerQuickReplyMaterials::uploadLimits(),'replies'=>array_map(static fn(array $r):array=>self::view($r,$actor,$ctx),array_slice($rows,0,self::MAX_TOOLBAR_REPLIES)),'version'=>$version,'max_replies'=>self::MAX_TOOLBAR_REPLIES];
        if ($overflow) {$result['error']='toolbar_limit_exceeded';$result['has_more']=true;$result['count_at_least']=$limit;}
        return $result;
    }

    private static function lockToolbar(PDO $pdo, int $actor): void
    {
        $insert=$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'?'INSERT IGNORE':'INSERT OR IGNORE';
        $q=$pdo->prepare($insert.' INTO manager_quick_reply_toolbar_states (manager_id,revision) VALUES (?,0)');$q->execute([$actor]);
        $suffix=$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'?' FOR UPDATE':'';
        $q=$pdo->prepare('SELECT revision FROM manager_quick_reply_toolbar_states WHERE manager_id=?'.$suffix);$q->execute([$actor]);$q->fetchColumn();
    }

    private static function advanceToolbar(PDO $pdo, int $actor): void
    {
        $q=$pdo->prepare('UPDATE manager_quick_reply_toolbar_states SET revision=revision+1 WHERE manager_id=?');$q->execute([$actor]);
    }

    private static function retryableLockFailure(Throwable $e): bool
    {
        if (!$e instanceof PDOException) return false;
        return (string)$e->getCode()==='40001'||in_array((int)($e->errorInfo[1]??0),[5,6,1213],true);
    }

    private static function get(PDO $pdo, int $actor, int $id, array $ctx, bool $lock=false): ?array
    {
        if ($id<=0) return null;
        $suffix=$lock&&$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'?' FOR UPDATE':'';
        // Lock the reply row itself. Favorites are loaded separately only for a
        // singleton command; lists use one joined query, never N+1.
        $q=$pdo->prepare("SELECT * FROM manager_quick_replies WHERE id=? AND (scope='shared' OR (scope='personal' AND owner_manager_id=?)) AND ".self::projectSql($ctx).$suffix);
        $q->execute([$id,$actor]);$row=$q->fetch(PDO::FETCH_ASSOC);if (!$row) return null;
        $q=$pdo->prepare('SELECT 1 FROM manager_quick_reply_favorites WHERE manager_id=? AND reply_id=?');$q->execute([$actor,$id]);
        $row['is_favorite']=(bool)$q->fetchColumn();return $row;
    }

    private static function view(array $row, int $actor, array $ctx): array
    {
        return ['id'=>(int)$row['id'],'project_key'=>$ctx['key'],'title'=>(string)$row['title'],'body'=>(string)$row['body'],'category'=>(string)$row['category'],'scope'=>(string)$row['scope'],'version'=>(int)$row['version'],'is_favorite'=>!empty($row['is_favorite']),'can_edit'=>$row['scope']==='shared'?$ctx['admin']:(int)$row['owner_manager_id']===$actor,'created_at'=>(string)$row['created_at'],'updated_at'=>(string)$row['updated_at'],
            'source_label'=>(string)$row['source_label'],'reviewed_at'=>$row['reviewed_at']?str_replace(' ','T',(string)$row['reviewed_at']).'Z':null,
            'requires_review'=>empty($row['reviewed_at'])||trim((string)$row['source_label'])==='',
            'materials'=>ManagerQuickReplyMaterials::manifest($row['materials_json'])];
    }

    /** Shared edits have a canonical audit entry in the same transaction. Personal
     * reply content stays account-owned; durable operation receipts track those writes. */
    private static function audit(PDO $pdo, int $actor, array $ctx, string $verb, ?array $before, ?array $after): void
    {
        $row=$after??$before;
        if (!$row||$row['scope']!=='shared') return;
        $summary=static fn(?array $r):?array=>$r===null?null:['title'=>$r['title'],'category'=>$r['category'],'version'=>(int)$r['version'],'scope'=>$r['scope'],'source_label'=>$r['source_label'],'reviewed_at'=>$r['reviewed_at'],'material_count'=>count(ManagerQuickReplyMaterials::manifest($r['materials_json']))];
        $q=$pdo->prepare('INSERT INTO admin_audit_log (actor_manager_id,action,entity_type,entity_id,project_key,before_json,after_json) VALUES (?,?,?,?,?,?,?)');
        $q->execute([$actor,'quick_reply_'.$verb,'quick_reply',(string)$row['id'],$ctx['key'],$before===null?null:json_encode($summary($before),JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),$after===null?null:json_encode($summary($after),JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)]);
    }

    private static function receipt(PDO $pdo, int $actor, string $operationId): ?array
    {
        $q=$pdo->prepare('SELECT request_hash,result_json,project_id FROM manager_quick_reply_operations WHERE manager_id=? AND operation_id=?');$q->execute([$actor,$operationId]);$row=$q->fetch(PDO::FETCH_ASSOC);
        if (!$row||$row['result_json']===null) return null;
        return ['hash'=>(string)$row['request_hash'],'project_id'=>$row['project_id']===null?null:(int)$row['project_id'],'result'=>json_decode((string)$row['result_json'],true,512,JSON_THROW_ON_ERROR)];
    }

    private static function replay(array $receipt, string $hash): array
    {
        return hash_equals($receipt['hash'],$hash)?$receipt['result']:['ok'=>false,'error'=>'operation_mismatch'];
    }

    private static function receiptProject(array $receipt,array $ctx): bool
    {
        return $receipt['project_id']===$ctx['id']||($receipt['project_id']===null&&$ctx['legacy']);
    }

    /** The optional default serves trusted legacy PHP callers only. The HTTP
     * endpoint requires an explicit company and the expected current account. */
    private static function context(int $actor,$key,bool $lock=false): ?array
    {
        if ($actor<=0) return null;
        $key=$key??ProjectConfig::projectId();
        if (!is_string($key)||!preg_match('/^[a-z][a-z0-9_-]{0,63}$/D',$key)) return null;
        $manager=ManagerAuthService::byId($actor,$lock);
        if (!$manager) return null;
        foreach ($manager['projects'] as $project) {
            if ((string)$project['project_key']===$key&&(int)$project['id']>0) return [
                'id'=>(int)$project['id'],'key'=>$key,'legacy'=>$key===ProjectConfig::projectId(),
                'admin'=>ManagerAuthService::isAdmin($manager)];
        }
        return null;
    }

    private static function current(int $actor,array $ctx): bool
    {
        return self::context($actor,$ctx['key'],true)===$ctx;
    }

    private static function projectSql(array $ctx,string $prefix=''): string
    {
        // Both literals originate from the authenticated project projection,
        // never from request text. Legacy rows belong only to the original app.
        return '('.$prefix.'project_id='.(int)$ctx['id'].' OR ('.$prefix.'project_id IS NULL AND '.($ctx['legacy']?'1':'0').'=1))';
    }

    public static function prepareInsert(int $actor,array $input): array
    {
        foreach (['id','version','conversation_id'] as $field) if (!is_int($input[$field]??null)||$input[$field]<=0) return ['ok'=>false,'error'=>'invalid_materials'];
        $ctx=self::context($actor,$input['project_key']??null);
        $conversation=self::insertionConversation($actor,$ctx,(int)($input['conversation_id']??0));
        if (!$ctx||!$conversation) return ['ok'=>false,'error'=>'not_found'];
        $row=self::get(ConversationDb::connection(),$actor,(int)($input['id']??0),$ctx);
        if (!$row||!self::current($actor,$ctx)||!self::insertionConversation($actor,$ctx,(int)$conversation['id'])) return ['ok'=>false,'error'=>'not_found'];
        if ((int)($input['version']??0)!==(int)$row['version']) return ['ok'=>false,'error'=>'conflict'];
        require_once __DIR__.'/ManagerQuickReplyMediaPolicy.php';
        return ['ok'=>true,'reply'=>self::view($row,$actor,$ctx),
            'constraints'=>ManagerQuickReplyMediaPolicy::forChannel((string)$conversation['channel'])];
    }

    /** The controller streams only after a second current authorization. */
    public static function material(int $actor,array $input): array
    {
        $prepared=self::prepareInsert($actor,$input);
        if (empty($prepared['ok'])) return $prepared;
        $item=null;
        foreach ($prepared['reply']['materials'] as $candidate) {
            if ($candidate['id']===($input['material_id']??null)) {$item=$candidate;break;}
        }
        if (!$item) return ['ok'=>false,'error'=>'not_found'];
        $ctx=self::context($actor,$input['project_key']??null);
        if (!$ctx) return ['ok'=>false,'error'=>'not_found'];
        $file=ManagerQuickReplyMaterials::open($ctx['id'],$item);
        $current=self::prepareInsert($actor,$input);
        if (empty($current['ok'])||$current['reply']['materials']!==$prepared['reply']['materials']) {
            fclose($file['stream']);return ['ok'=>false,'error'=>'not_found'];
        }
        return ['ok'=>true]+$file;
    }

    private static function insertionConversation(int $actor,?array $ctx,int $id): ?array
    {
        if (!$ctx||$id<=0) return null;
        $row=ManagerConversationService::visibleConversation($id,$actor,false,true);
        return $row&&(string)$row['project_key']===$ctx['key']&&(int)$row['manager_id']===$actor
            &&(string)$row['status']==='manager'?$row:null;
    }

    private static function validOperationId(string $id): bool {return preg_match('/^[A-Za-z0-9_-]{16,64}$/D',$id)===1;}
    private static function length(string $value): int {return mb_strlen($value,'UTF-8');}
}
