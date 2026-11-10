<?php
require_once __DIR__ . '/ConversationDb.php';
require_once __DIR__ . '/ProjectAccessService.php';
require_once __DIR__ . '/ManagerAuthService.php';
require_once __DIR__ . '/RegionalRoutingScopeService.php';

class RoutingAccessService
{
    private static $schemaReady=false;

    public static function ensureSchema(): void
    {
        if(self::$schemaReady)return;
        ProjectAccessService::ensureSchema();
        // Schema is managed by versioned migrations.
        self::$schemaReady=true;
    }

    public static function canSeeConversation(int $managerId,array$conversation,bool$currentRead=false): bool
    {
        try{return self::authorize($managerId,$conversation,$currentRead,false);}
        catch(Throwable$e){return false;}
    }

    /** Recipient eligibility only; never used to expose another owner's dialogue. */
    public static function canReceiveConversation(int$managerId,array$conversation,bool$currentRead=false): bool
    {
        try{return self::authorize($managerId,$conversation,$currentRead,true);}
        catch(Throwable$e){return false;}
    }

    private static function authorize(int $managerId,array$conversation,bool$currentRead,bool$receiving): bool
    {
        self::ensureSchema();
        $projectKey=(string)($conversation['project_key']??'');
        if(!ProjectAccessService::canAccess($managerId,$projectKey,$currentRead))return false;

        $manager=ManagerAuthService::byId($managerId,$currentRead);
        $isAdmin=ManagerAuthService::isAdmin($manager);
        $isWorking=ManagerAuthService::isWorking($manager);
        $sourceIds=(!$isAdmin && ($isWorking || (string)($conversation['status']??'')!=='waiting_manager'))
            ? [(int)($conversation['source_id']??0)] : [];
        $sources=self::visibilitySources($sourceIds,$currentRead);
        $groups=self::visibilityGroups($managerId,$sources,$currentRead);
        if(!self::visibleInRoutingSnapshot($conversation,$isAdmin,$isWorking,$sources,$groups,time()))return false;
        if($isAdmin)return true;
        try{return RegionalRoutingScopeService::visibleRows($managerId,[$conversation],RegionalRoutingScopeService::context($managerId,$manager!==null?(array)$manager['projects']:null,$currentRead),$currentRead,$receiving)!==[];}
        catch(Throwable$e){return false;}
    }

    /** Pin only the inputs of the EXISTING visibility policy for a staff mutation.
     * Call after actor/conversation locking and before the first consistent read.
     * Current locking reads avoid a stale MySQL REPEATABLE READ grant snapshot;
     * revocation/config changes then serialize with the mutation's commit.
     */
    public static function lockConversationVisibilityInputs(int $managerId,array $conversation): bool
    {
        $pdo=ConversationDb::connection();if(!$pdo->inTransaction())throw new LogicException('Visibility locks require a transaction');
        if($pdo->getAttribute(PDO::ATTR_DRIVER_NAME)!=='mysql')return true;
        $q=$pdo->prepare('SELECT id,is_active FROM managers WHERE id=? FOR UPDATE');$q->execute([$managerId]);$actor=$q->fetch();if(!$actor||(int)$actor['is_active']!==1)return false;
        // The project row is the common serialization fence even when a scope,
        // membership or durable opt-in row does not exist yet (insert/revoke).
        $q=$pdo->prepare('SELECT id FROM projects WHERE project_key=? FOR UPDATE');$q->execute([(string)($conversation['project_key']??'')]);$projectId=(int)$q->fetchColumn();if($projectId<=0)return false;
        $q=$pdo->prepare('SELECT p.id,p.is_active FROM projects p JOIN manager_projects mp ON mp.project_id=p.id WHERE mp.manager_id=? AND p.project_key=? ORDER BY p.id FOR UPDATE');
        $q->execute([$managerId,(string)($conversation['project_key']??'')]);$project=$q->fetch();
        if(!$project||(int)$project['is_active']!==1)return false;
        $q=$pdo->prepare('SELECT project_id,regional_scope_required FROM manager_regional_access WHERE manager_id=? AND project_id=? FOR UPDATE');$q->execute([$managerId,$projectId]);$q->fetchAll();
        $q=$pdo->prepare('SELECT id FROM manager_groups WHERE project_id=? ORDER BY id FOR UPDATE');$q->execute([$projectId]);$regionalGroups=array_map('intval',array_column($q->fetchAll(),'id'));
        if($regionalGroups){
            $placeholders=implode(',',array_fill(0,count($regionalGroups),'?'));
            $q=$pdo->prepare('SELECT group_id FROM manager_group_members WHERE manager_id=? AND group_id IN ('.$placeholders.') ORDER BY group_id FOR UPDATE');$q->execute([$managerId,...$regionalGroups]);$q->fetchAll();
            $q=$pdo->prepare('SELECT group_id,territory_region_id FROM manager_group_territories WHERE group_id IN ('.$placeholders.') ORDER BY group_id,territory_region_id FOR UPDATE');$q->execute($regionalGroups);$q->fetchAll();
        }
        $source=(int)($conversation['source_id']??0);if($source<=0)return true;
        // Lock the actual row even when inactive: the normal policy decides how
        // missing/inactive sources behave. No new source/group eligibility rule.
        $q=$pdo->prepare('SELECT id,primary_group_id,fallback_group_id FROM conversation_sources WHERE id=? FOR UPDATE');$q->execute([$source]);$row=$q->fetch();
        if(!$row)return true;
        $groups=array_values(array_unique(array_filter([(int)$row['primary_group_id'],(int)$row['fallback_group_id']],static fn($id)=>$id>0)));sort($groups,SORT_NUMERIC);
        if($groups){$q=$pdo->prepare('SELECT group_id FROM manager_group_members WHERE manager_id=? AND group_id IN ('.implode(',',array_fill(0,count($groups),'?')).') ORDER BY group_id FOR UPDATE');$q->execute([$managerId,...$groups]);$q->fetchAll();}
        return true;
    }

    /** One batch snapshot only; never retain permissions across requests or mutations. */
    public static function visibleConversations(int $managerId, array $conversations): array
    {
        if($managerId<=0 || !$conversations)return[];
        self::ensureSchema();
        $manager=ManagerAuthService::byId($managerId);
        $projects=$manager!==null ? (array)($manager['projects']??[]) : ProjectAccessService::projectsForManager($managerId);
        $projectKeys=[];
        foreach($projects as $project){$key=(string)($project['project_key']??'');if($key!=='')$projectKeys[$key]=true;}
        // A historical conversation key may differ in case/spacing under the DB
        // collation. Resolve unmatched keys with the original SQL access predicate.
        $unmatched=[];
        foreach($conversations as $row){$key=(string)($row['project_key']??'');if($key!==''&&!isset($projectKeys[$key]))$unmatched[$key]=$key;}
        if($unmatched){
            $parts=[];$args=[];
            foreach($unmatched as $key){$parts[]='SELECT ? AS project_key WHERE EXISTS (SELECT 1 FROM manager_projects mp JOIN projects p ON p.id=mp.project_id WHERE mp.manager_id=? AND p.project_key=? AND p.is_active=1)';array_push($args,$key,$managerId,$key);}
            $q=ConversationDb::connection()->prepare(implode(' UNION ALL ',$parts));$q->execute($args);
            foreach($q->fetchAll() as $project)$projectKeys[(string)$project['project_key']]=true;
        }
        $rows=array_values(array_filter($conversations,static fn(array $row):bool=>isset($projectKeys[(string)($row['project_key']??'')])));
        if(!$rows)return[];

        $isAdmin=ManagerAuthService::isAdmin($manager);
        $isWorking=ManagerAuthService::isWorking($manager);
        $sourceIds=[];
        if(!$isAdmin){foreach($rows as $row){if($isWorking || (string)($row['status']??'')!=='waiting_manager')$sourceIds[]=(int)($row['source_id']??0);}}
        $sources=self::visibilitySources($sourceIds);
        $groups=self::visibilityGroups($managerId,$sources);
        $now=time();
        $rows=array_values(array_filter($rows,static fn(array $row):bool=>self::visibleInRoutingSnapshot($row,$isAdmin,$isWorking,$sources,$groups,$now)));
        if($isAdmin)return$rows;
        try{return RegionalRoutingScopeService::visibleRows($managerId,$rows,RegionalRoutingScopeService::context($managerId,$projects));}
        catch(Throwable$e){return[];}
    }

    private static function visibilitySources(array $sourceIds,bool$currentRead=false): array
    {
        $ids=array_values(array_unique(array_filter(array_map('intval',$sourceIds),static fn(int $id):bool=>$id>0)));
        if(!$ids)return[];
        $pdo=ConversationDb::connection();$lock=$currentRead&&$pdo->inTransaction()&&$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'?' FOR UPDATE':'';
        $q=$pdo->prepare('SELECT id,primary_group_id,fallback_mode,fallback_group_id,fallback_after_minutes FROM conversation_sources WHERE id IN ('.implode(',',array_fill(0,count($ids),'?')).') AND is_active=1'.$lock);
        $q->execute($ids);$sources=[];
        foreach($q->fetchAll() as $source)$sources[(int)$source['id']]=$source;
        return$sources;
    }

    private static function visibilityGroups(int $managerId,array $sources,bool$currentRead=false): array
    {
        $groupIds=[];
        foreach($sources as $source){foreach(['primary_group_id','fallback_group_id'] as $key){$id=(int)($source[$key]??0);if($id>0)$groupIds[$id]=$id;}}
        if(!$groupIds)return[];
        // Existing visibility depends on membership, without an extra group-active gate.
        $pdo=ConversationDb::connection();$lock=$currentRead&&$pdo->inTransaction()&&$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'?' FOR UPDATE':'';
        $q=$pdo->prepare('SELECT group_id FROM manager_group_members WHERE manager_id=? AND group_id IN ('.implode(',',array_fill(0,count($groupIds),'?')).')'.$lock);
        $q->execute(array_merge([$managerId],array_values($groupIds)));$groups=[];
        foreach($q->fetchAll() as $row)$groups[(int)$row['group_id']]=true;
        return$groups;
    }

    /** Pure routing decision shared by singleton authorization and inbox batches. */
    private static function visibleInRoutingSnapshot(array $conversation,bool $isAdmin,bool $isWorking,array $sources,array $groups,int $now): bool
    {
        if($isAdmin)return true;
        if((string)($conversation['status']??'')==='waiting_manager' && !$isWorking)return false;

        $sourceId=(int)($conversation['source_id']??0);
        if($sourceId<=0)return true;
        $source=$sources[$sourceId]??null;
        if(!$source)return true;

        $primary=(int)($source['primary_group_id']??0);
        if($primary<=0)return true;
        if(isset($groups[$primary]))return true;

        $mode=(string)($source['fallback_mode']??'immediate');
        if($mode==='none')return false;
        $fallback=(int)($source['fallback_group_id']??0);
        if($fallback<=0 || !isset($groups[$fallback]))return false;
        if($mode==='immediate')return true;
        if($mode!=='delayed')return false;

        $minutes=max(0,(int)($source['fallback_after_minutes']??0));
        $started=(string)($conversation['waiting_since']??$conversation['last_message_at']??$conversation['started_at']??'');
        if($started==='')return false;
        $ts=strtotime($started);if($ts===false)return false;
        return $now >= $ts + ($minutes*60);
    }

    public static function sourceId(string $projectKey,string $sourceKey,string $channel=''): int
    {
        self::ensureSchema();
        $projectId=ProjectAccessService::projectIdByKey($projectKey);if($projectId<=0)return 0;
        $sourceKey=trim($sourceKey);if($sourceKey==='')return 0;
        $pdo=ConversationDb::connection();
        $q=$pdo->prepare('SELECT id FROM conversation_sources WHERE project_id=? AND source_key=? AND is_active=1 LIMIT 1');
        $q->execute([$projectId,$sourceKey]);$id=(int)$q->fetchColumn();if($id)return$id;
        $q=$pdo->prepare('INSERT INTO conversation_sources (project_id,source_key,display_name,channel,fallback_mode) VALUES (?,?,?,?,?)');
        $q->execute([$projectId,$sourceKey,$sourceKey,$channel!==''?$channel:null,'immediate']);
        return(int)$pdo->lastInsertId();
    }

    public static function groupsForManager(int $managerId): array
    {
        self::ensureSchema();
        $q=ConversationDb::connection()->prepare('SELECT g.id,g.project_id,g.group_key,g.display_name FROM manager_groups g JOIN manager_group_members gm ON gm.group_id=g.id WHERE gm.manager_id=? AND g.is_active=1 ORDER BY g.display_name');
        $q->execute([$managerId]);return$q->fetchAll();
    }

}
