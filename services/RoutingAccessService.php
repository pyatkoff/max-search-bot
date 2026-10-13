<?php
require_once __DIR__ . '/ConversationDb.php';
require_once __DIR__ . '/ProjectAccessService.php';
require_once __DIR__ . '/ManagerAuthService.php';

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

    public static function canSeeConversation(int $managerId, array $conversation): bool
    {
        self::ensureSchema();
        $projectKey=(string)($conversation['project_key']??'');
        if(!ProjectAccessService::canAccess($managerId,$projectKey))return false;

        $manager=ManagerAuthService::byId($managerId);
        $isAdmin=ManagerAuthService::isAdmin($manager);
        $isWorking=ManagerAuthService::isWorking($manager);
        $sourceIds=(!$isAdmin && ($isWorking || (string)($conversation['status']??'')!=='waiting_manager'))
            ? [(int)($conversation['source_id']??0)] : [];
        $sources=self::visibilitySources($sourceIds);
        $groups=self::visibilityGroups($managerId,$sources);
        return self::visibleInRoutingSnapshot($conversation,$isAdmin,$isWorking,$sources,$groups,time());
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
        return array_values(array_filter($rows,static fn(array $row):bool=>self::visibleInRoutingSnapshot($row,$isAdmin,$isWorking,$sources,$groups,$now)));
    }

    private static function visibilitySources(array $sourceIds): array
    {
        $ids=array_values(array_unique(array_filter(array_map('intval',$sourceIds),static fn(int $id):bool=>$id>0)));
        if(!$ids)return[];
        $q=ConversationDb::connection()->prepare('SELECT id,primary_group_id,fallback_mode,fallback_group_id,fallback_after_minutes FROM conversation_sources WHERE id IN ('.implode(',',array_fill(0,count($ids),'?')).') AND is_active=1');
        $q->execute($ids);$sources=[];
        foreach($q->fetchAll() as $source)$sources[(int)$source['id']]=$source;
        return$sources;
    }

    private static function visibilityGroups(int $managerId,array $sources): array
    {
        $groupIds=[];
        foreach($sources as $source){foreach(['primary_group_id','fallback_group_id'] as $key){$id=(int)($source[$key]??0);if($id>0)$groupIds[$id]=$id;}}
        if(!$groupIds)return[];
        // Existing visibility depends on membership, without an extra group-active gate.
        $q=ConversationDb::connection()->prepare('SELECT group_id FROM manager_group_members WHERE manager_id=? AND group_id IN ('.implode(',',array_fill(0,count($groupIds),'?')).')');
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
