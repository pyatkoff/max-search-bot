<?php
declare(strict_types=1);
require_once __DIR__.'/ManagerAuthService.php';
require_once __DIR__.'/ProjectAccessService.php';

/** Explicit project/source read capability. Never a routing or reply permission. */
final class ManagerSupervisorAccessService
{
    public static function scopes(int $actor): array
    {
        if ($actor<=0) return [];
        try {
            ProjectAccessService::initializeReadOnly();
            $pdo=ConversationDb::connection();
            // The existing administrator role is the workspace owner's full
            // read capability. Resolve its current projects on every request;
            // a separate employee-supervision grant must not narrow it.
            $q=$pdo->prepare("SELECT p.id AS project_id,p.project_key,p.display_name,m.role FROM projects p JOIN manager_projects mp ON mp.project_id=p.id JOIN managers m ON m.id=mp.manager_id WHERE m.id=? AND m.is_active=1 AND m.role='admin' AND p.is_active=1 ORDER BY p.id");
            $q->execute([$actor]);$owned=array_values(array_filter($q->fetchAll(PDO::FETCH_ASSOC),static fn(array $row):bool=>ManagerAuthService::isAdmin($row)));
            if($owned){
                $q=$pdo->prepare('SELECT s.project_id,s.id,s.display_name,s.channel FROM conversation_sources s JOIN manager_projects mp ON mp.project_id=s.project_id WHERE mp.manager_id=? AND s.is_active=1 ORDER BY s.id');
                $q->execute([$actor]);$sources=[];
                foreach($q->fetchAll(PDO::FETCH_ASSOC) as $row)$sources[(int)$row['project_id']][]=['id'=>(int)$row['id'],'name'=>(string)$row['display_name'],'channel'=>(string)($row['channel']??'')];
                foreach($owned as &$scope){unset($scope['role']);$scope['project_id']=(int)$scope['project_id'];$scope['version']=0;$scope['include_unsourced']=true;$scope['access_mode']='owner';$scope['sources']=$sources[$scope['project_id']]??[];}
                return $owned;
            }
            $q=$pdo->prepare('SELECT g.project_id,g.version,g.include_unsourced,p.project_key,p.display_name FROM manager_supervision g JOIN managers m ON m.id=g.manager_id JOIN manager_projects mp ON mp.manager_id=g.manager_id AND mp.project_id=g.project_id JOIN projects p ON p.id=g.project_id WHERE g.manager_id=? AND m.is_active=1 AND p.is_active=1 AND g.enabled=1 ORDER BY p.id');
            $q->execute([$actor]);$scopes=$q->fetchAll(PDO::FETCH_ASSOC);
            if (!$scopes) return [];
            $q=$pdo->prepare('SELECT s.project_id,s.id,s.display_name,s.channel FROM conversation_sources s JOIN manager_supervision_sources gs ON gs.source_id=s.id AND gs.project_id=s.project_id WHERE gs.manager_id=? AND s.is_active=1 ORDER BY s.id');
            $q->execute([$actor]);$sources=[];
            foreach($q->fetchAll(PDO::FETCH_ASSOC) as $row)$sources[(int)$row['project_id']][]=['id'=>(int)$row['id'],'name'=>(string)$row['display_name'],'channel'=>(string)($row['channel']??'')];
            foreach($scopes as &$scope){$scope['project_id']=(int)$scope['project_id'];$scope['version']=(int)$scope['version'];$scope['include_unsourced']=(bool)$scope['include_unsourced'];$scope['sources']=$sources[$scope['project_id']]??[];}
            return $scopes;
        } catch(Throwable $ignored){return [];}
    }

    public static function permits(array $scopes,array $conversation): bool
    {
        foreach($scopes as $scope){
            if ((string)($conversation['project_key']??'')!==$scope['project_key']) continue;
            if(($scope['access_mode']??'')==='owner')return true;
            $source=(int)($conversation['source_id']??0);
            if ($source===0) return $scope['include_unsourced'];
            return in_array($source,array_column($scope['sources'],'id'),true);
        }
        return false;
    }

    public static function canRead(int $actor,array $conversation): bool
    {
        return self::permits(self::scopes($actor),$conversation);
    }

    /** An exact SQL intersection for complete analytics; no bounded Inbox input. */
    public static function predicate(array $scopes,string $alias,array &$args): string
    {
        if (!in_array($alias,['c'],true)) throw new InvalidArgumentException('invalid_alias');
        $parts=[];
        foreach($scopes as $scope){
            if(($scope['access_mode']??'')==='owner'){$parts[]="$alias.project_key=?";$args[]=$scope['project_key'];continue;}
            $ids=array_column($scope['sources'],'id');$sources=[];
            if($ids){$sources[]="$alias.source_id IN (".implode(',',array_fill(0,count($ids),'?')).')';}
            if($scope['include_unsourced'])$sources[]="($alias.source_id IS NULL OR $alias.source_id=0)";
            if(!$sources)continue;
            $parts[]="($alias.project_key=? AND (".implode(' OR ',$sources).'))';$args[]=$scope['project_key'];array_push($args,...$ids);
        }
        return $parts?'('.implode(' OR ',$parts).')':'1=0';
    }

    /** Explicit admin command, optimistic version + mandatory atomic audit. No default grants. */
    public static function set(int $actor,int $target,int $projectId,array $sourceIds,bool $includeUnsourced,bool $enabled,int $expected): array
    {
        if($actor<=0||$target<=0||$projectId<=0||$expected<0||!array_is_list($sourceIds)||count($sourceIds)>1000)return ['ok'=>false,'error'=>'invalid_request'];
        foreach($sourceIds as $id)if(!is_int($id)||$id<=0)return ['ok'=>false,'error'=>'invalid_request'];
        $sourceIds=array_values(array_unique($sourceIds));sort($sourceIds);
        $pdo=null;$started=false;
        try {
            ProjectAccessService::initializeReadOnly();$pdo=ConversationDb::connection();
            if($pdo->inTransaction())return ['ok'=>false,'error'=>'access_unavailable'];
            $pdo->beginTransaction();$started=true;
            $suffix=$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'?' FOR UPDATE':'';
            $q=$pdo->prepare('SELECT id,role,is_active FROM managers WHERE id=?'.$suffix);$q->execute([$actor]);$admin=$q->fetch(PDO::FETCH_ASSOC);
            if(!$admin||(int)$admin['is_active']!==1||$admin['role']!=='admin')return ['ok'=>false,'error'=>'forbidden'];
            $q=$pdo->prepare('SELECT p.project_key FROM projects p JOIN manager_projects a ON a.project_id=p.id AND a.manager_id=? JOIN manager_projects t ON t.project_id=p.id AND t.manager_id=? JOIN managers m ON m.id=t.manager_id WHERE p.id=? AND p.is_active=1 AND m.is_active=1'.$suffix);
            $q->execute([$actor,$target,$projectId]);$key=$q->fetchColumn();
            if(!is_string($key))return ['ok'=>false,'error'=>'project_access_required'];
            if($sourceIds){$q=$pdo->prepare('SELECT id FROM conversation_sources WHERE project_id=? AND is_active=1 AND id IN ('.implode(',',array_fill(0,count($sourceIds),'?')).')');$q->execute(array_merge([$projectId],$sourceIds));if(count($q->fetchAll())!==count($sourceIds))return ['ok'=>false,'error'=>'source_access_denied'];}
            if($enabled&&!$sourceIds&&!$includeUnsourced)return ['ok'=>false,'error'=>'empty_scope'];
            $q=$pdo->prepare('SELECT enabled,include_unsourced,version FROM manager_supervision WHERE manager_id=? AND project_id=?'.$suffix);$q->execute([$target,$projectId]);$before=$q->fetch(PDO::FETCH_ASSOC);
            if((int)($before['version']??0)!==$expected)return ['ok'=>false,'error'=>'access_conflict'];
            $q=$pdo->prepare('SELECT source_id FROM manager_supervision_sources WHERE manager_id=? AND project_id=? ORDER BY source_id');$q->execute([$target,$projectId]);$oldSources=array_map('intval',$q->fetchAll(PDO::FETCH_COLUMN));
            $next=$expected+1;$at=gmdate('Y-m-d H:i:s');
            if($before){$q=$pdo->prepare('UPDATE manager_supervision SET enabled=?,include_unsourced=?,version=?,updated_at=? WHERE manager_id=? AND project_id=? AND version=?');$q->execute([(int)$enabled,(int)$includeUnsourced,$next,$at,$target,$projectId,$expected]);if($q->rowCount()!==1)return ['ok'=>false,'error'=>'access_conflict'];}
            else{$q=$pdo->prepare('INSERT INTO manager_supervision (manager_id,project_id,enabled,include_unsourced,version,updated_at) VALUES (?,?,?,?,?,?)');$q->execute([$target,$projectId,(int)$enabled,(int)$includeUnsourced,$next,$at]);}
            $pdo->prepare('DELETE FROM manager_supervision_sources WHERE manager_id=? AND project_id=?')->execute([$target,$projectId]);
            $q=$pdo->prepare('INSERT INTO manager_supervision_sources (manager_id,project_id,source_id) VALUES (?,?,?)');foreach($sourceIds as $source)$q->execute([$target,$projectId,$source]);
            $after=['enabled'=>$enabled,'include_unsourced'=>$includeUnsourced,'version'=>$next,'source_ids'=>$sourceIds];
            // Audit failure rolls back the permission, unlike best-effort operational logging.
            $q=$pdo->prepare('INSERT INTO admin_audit_log (actor_manager_id,action,entity_type,entity_id,project_key,before_json,after_json) VALUES (?,?,?,?,?,?,?)');
            $q->execute([$actor,'supervisor_access_changed','manager_supervision',(string)$target,$key,json_encode($before?($before+['source_ids'=>$oldSources]):null,JSON_THROW_ON_ERROR),json_encode($after,JSON_THROW_ON_ERROR)]);
            $pdo->commit();$started=false;return ['ok'=>true,'manager_id'=>$target,'project_id'=>$projectId]+$after;
        }catch(Throwable $ignored){return ['ok'=>false,'error'=>'access_unavailable'];}
        finally{if($started&&$pdo instanceof PDO&&$pdo->inTransaction())$pdo->rollBack();}
    }
}
