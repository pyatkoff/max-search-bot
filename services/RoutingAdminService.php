<?php
require_once __DIR__ . '/RoutingAccessService.php';
require_once __DIR__ . '/AuditLogService.php';

class RoutingAdminService
{
    private static function requireAdmin(int $managerId): bool
    {
        $m=ManagerAuthService::byId($managerId);
        return $m && (string)($m['role']??'manager')==='admin';
    }

    public static function snapshot(int $managerId,string $projectKey): array
    {
        if(!self::requireAdmin($managerId)||!ProjectAccessService::canAccess($managerId,$projectKey))return[];
        RoutingAccessService::ensureSchema();$pdo=ConversationDb::connection();$projectId=ProjectAccessService::projectIdByKey($projectKey);
        $q=$pdo->prepare('SELECT id,group_key,display_name,is_active FROM manager_groups WHERE project_id=? ORDER BY display_name');$q->execute([$projectId]);$groups=$q->fetchAll();
        foreach($groups as &$g){$m=$pdo->prepare('SELECT m.id,m.login,m.display_name FROM managers m JOIN manager_group_members gm ON gm.manager_id=m.id WHERE gm.group_id=? AND m.is_active=1 ORDER BY COALESCE(m.display_name,m.login)');$m->execute([(int)$g['id']]);$g['members']=$m->fetchAll();}unset($g);
        $q=$pdo->prepare('SELECT s.id,s.source_key,s.display_name,s.channel,s.handling_mode,s.primary_group_id,s.fallback_mode,s.fallback_group_id,s.fallback_after_minutes,s.is_active FROM conversation_sources s WHERE s.project_id=? ORDER BY s.display_name');$q->execute([$projectId]);
        $managers=$pdo->prepare('SELECT m.id,m.login,m.display_name FROM managers m JOIN manager_projects mp ON mp.manager_id=m.id WHERE mp.project_id=? AND m.is_active=1 ORDER BY COALESCE(m.display_name,m.login)');$managers->execute([$projectId]);
        return['groups'=>$groups,'sources'=>$q->fetchAll(),'managers'=>$managers->fetchAll()];
    }

    public static function saveGroup(int $managerId,string $projectKey,int $groupId,string $groupKey,string $displayName,array $memberIds): bool
    {
        if(!self::requireAdmin($managerId)||!ProjectAccessService::canAccess($managerId,$projectKey))return false;
        RoutingAccessService::ensureSchema();$pdo=ConversationDb::connection();$projectId=ProjectAccessService::projectIdByKey($projectKey);$groupKey=trim($groupKey);$displayName=trim($displayName);
        if($projectId<=0||$groupKey===''||$displayName==='')return false;
        $before=$groupId>0?self::groupRow($groupId):null;
        $pdo->beginTransaction();try{
            if($groupId>0){$q=$pdo->prepare('UPDATE manager_groups SET group_key=?,display_name=? WHERE id=? AND project_id=?');$q->execute([$groupKey,$displayName,$groupId,$projectId]);if(!$q->rowCount()){ $check=$pdo->prepare('SELECT id FROM manager_groups WHERE id=? AND project_id=?');$check->execute([$groupId,$projectId]);if(!$check->fetchColumn())throw new RuntimeException('group_not_found');}}
            else{$q=$pdo->prepare('INSERT INTO manager_groups (project_id,group_key,display_name) VALUES (?,?,?)');$q->execute([$projectId,$groupKey,$displayName]);$groupId=(int)$pdo->lastInsertId();}
            $pdo->prepare('DELETE FROM manager_group_members WHERE group_id=?')->execute([$groupId]);
            $ins=$pdo->prepare('INSERT IGNORE INTO manager_group_members (group_id,manager_id) SELECT ?,m.id FROM managers m JOIN manager_projects mp ON mp.manager_id=m.id WHERE m.id=? AND mp.project_id=? AND m.is_active=1');
            foreach(array_unique(array_map('intval',$memberIds)) as $mid)if($mid>0)$ins->execute([$groupId,$mid,$projectId]);
            $pdo->commit();AuditLogService::record($managerId,$before?'routing_group_updated':'routing_group_created','manager_group',(string)$groupId,$projectKey,$before,self::groupRow($groupId));return true;
        }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();return false;}
    }

    public static function saveSource(int $managerId,string $projectKey,int $sourceId,string $sourceKey,string $displayName,string $channel,int $primaryGroupId,string $fallbackMode,int $fallbackGroupId,int $fallbackAfter,string $handlingMode='ai'): bool
    {
        return !empty(self::saveSourceResult($managerId,$projectKey,$sourceId,$sourceKey,$displayName,$channel,$primaryGroupId,$fallbackMode,$fallbackGroupId,$fallbackAfter,$handlingMode)['ok']);
    }

    public static function saveSourceResult(int $managerId,string $projectKey,int $sourceId,string $sourceKey,string $displayName,string $channel,int $primaryGroupId,string $fallbackMode,int $fallbackGroupId,int $fallbackAfter,string $handlingMode='ai'): array
    {
        if(!self::requireAdmin($managerId))return['ok'=>false,'error'=>'admin_required'];
        if(!ProjectAccessService::canAccess($managerId,$projectKey))return['ok'=>false,'error'=>'project_access_denied'];
        RoutingAccessService::ensureSchema();
        $pdo=ConversationDb::connection();
        $projectId=ProjectAccessService::projectIdByKey($projectKey);
        $sourceKey=trim($sourceKey);$displayName=trim($displayName);$channel=trim($channel);$handlingMode=trim($handlingMode);
        $fallbackMode=in_array($fallbackMode,['none','delayed','immediate'],true)?$fallbackMode:'none';$fallbackAfter=max(0,$fallbackAfter);
        if($projectId<=0)return['ok'=>false,'error'=>'project_not_found'];
        if($sourceKey==='')return['ok'=>false,'error'=>'missing_source_key'];
        if($displayName==='')return['ok'=>false,'error'=>'missing_display_name'];
        if(!in_array($channel,['max','telegram','website'],true))return['ok'=>false,'error'=>'invalid_channel'];
        if(!in_array($handlingMode,['ai','manager','ask'],true))return['ok'=>false,'error'=>'invalid_handling_mode'];
        $before=$sourceId>0?self::sourceRow($sourceId):null;
        if($sourceId>0&&!$before)return['ok'=>false,'error'=>'source_not_found'];
        $validGroup=function(int $id)use($pdo,$projectId):?int{if($id<=0)return null;$q=$pdo->prepare('SELECT id FROM manager_groups WHERE id=? AND project_id=? AND is_active=1');$q->execute([$id,$projectId]);return$q->fetchColumn()?(int)$id:null;};
        $primary=$validGroup($primaryGroupId);$fallback=$validGroup($fallbackGroupId);
        if($primaryGroupId>0&&$primary===null)return['ok'=>false,'error'=>'invalid_primary_group'];
        if($fallbackMode==='none'){$fallback=null;$fallbackAfter=0;}
        if($fallbackMode==='immediate')$fallbackAfter=0;
        if($fallbackMode!=='none'&&$fallbackGroupId>0&&$fallback===null)return['ok'=>false,'error'=>'invalid_fallback_group'];
        if($fallbackMode!=='none'&&$fallback===null)return['ok'=>false,'error'=>'fallback_group_required'];
        try{
            if($sourceId>0){
                $q=$pdo->prepare('UPDATE conversation_sources SET source_key=?,display_name=?,channel=?,handling_mode=?,primary_group_id=?,fallback_mode=?,fallback_group_id=?,fallback_after_minutes=? WHERE id=? AND project_id=?');
                $q->execute([$sourceKey,$displayName,$channel,$handlingMode,$primary,$fallbackMode,$fallback,$fallbackAfter,$sourceId,$projectId]);
            }else{
                $q=$pdo->prepare('INSERT INTO conversation_sources (project_id,source_key,display_name,channel,handling_mode,primary_group_id,fallback_mode,fallback_group_id,fallback_after_minutes) VALUES (?,?,?,?,?,?,?,?,?)');
                $q->execute([$projectId,$sourceKey,$displayName,$channel,$handlingMode,$primary,$fallbackMode,$fallback,$fallbackAfter]);
                $sourceId=(int)$pdo->lastInsertId();
            }
        }catch(Throwable $e){
            if(self::isDuplicateKeyFailure($e))return['ok'=>false,'error'=>'duplicate_source_key'];
            return['ok'=>false,'error'=>'save_failed'];
        }
        AuditLogService::record($managerId,$before?'routing_source_updated':'routing_source_created','conversation_source',(string)$sourceId,$projectKey,$before,self::sourceRow($sourceId));
        return['ok'=>true,'source_id'=>$sourceId];
    }

    private static function isDuplicateKeyFailure(Throwable $e): bool
    {
        if(!$e instanceof PDOException)return false;
        $driverCode=(int)($e->errorInfo[1]??0);
        if($driverCode===1062)return true;
        return (string)$e->getCode()==='23000'&&stripos($e->getMessage(),'duplicate')!==false;
    }

    private static function groupRow(int $id): ?array
    {
        $pdo=ConversationDb::connection();$q=$pdo->prepare('SELECT id,project_id,group_key,display_name,is_active FROM manager_groups WHERE id=? LIMIT 1');$q->execute([$id]);$row=$q->fetch();if(!$row)return null;$m=$pdo->prepare('SELECT manager_id FROM manager_group_members WHERE group_id=? ORDER BY manager_id');$m->execute([$id]);$row['member_ids']=array_map('intval',array_column($m->fetchAll(),'manager_id'));return$row;
    }
    private static function sourceRow(int $id): ?array
    {
        $q=ConversationDb::connection()->prepare('SELECT id,project_id,source_key,display_name,channel,handling_mode,primary_group_id,fallback_mode,fallback_group_id,fallback_after_minutes,is_active FROM conversation_sources WHERE id=? LIMIT 1');$q->execute([$id]);$row=$q->fetch();return$row?:null;
    }
}
