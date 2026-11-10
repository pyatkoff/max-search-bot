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
        foreach($groups as &$g){$m=$pdo->prepare('SELECT m.id,m.login,m.display_name FROM managers m JOIN manager_group_members gm ON gm.manager_id=m.id WHERE gm.group_id=? AND m.is_active=1 ORDER BY COALESCE(m.display_name,m.login)');$m->execute([(int)$g['id']]);$g['members']=$m->fetchAll();$g['territories']=RegionalRoutingScopeService::groupScopes((int)$g['id']);$g['territory_region_ids']=array_column($g['territories'],'region_id');}unset($g);
        $q=$pdo->prepare('SELECT s.id,s.source_key,s.display_name,s.channel,s.handling_mode,s.suppress_channel_offer,s.primary_group_id,s.fallback_mode,s.fallback_group_id,s.fallback_after_minutes,s.is_active FROM conversation_sources s WHERE s.project_id=? ORDER BY s.display_name');$q->execute([$projectId]);
        $managers=$pdo->prepare('SELECT m.id,m.login,m.display_name FROM managers m JOIN manager_projects mp ON mp.manager_id=m.id WHERE mp.project_id=? AND m.is_active=1 ORDER BY COALESCE(m.display_name,m.login)');$managers->execute([$projectId]);
        $result=['groups'=>$groups,'sources'=>$q->fetchAll(),'managers'=>$managers->fetchAll()];
        try{$result['territory_options']=RegionalTerritoryCatalog::options();$result['territory_options_available']=true;}
        catch(Throwable$e){$result['territory_options']=[];$result['territory_options_available']=false;}
        return$result;
    }

    public static function saveGroup(int $managerId,string $projectKey,int $groupId,string $groupKey,string $displayName,array $memberIds,$territoryRegionIds=null): bool
    {
        return !empty(self::saveGroupResult($managerId,$projectKey,$groupId,$groupKey,$displayName,$memberIds,$territoryRegionIds)['ok']);
    }

    /** Canonical append for setup: never replace somebody else's members/scopes. */
    public static function appendMembersResult(int$managerId,string$projectKey,int$groupId,array$memberIds): array
    {
        if(!array_is_list($memberIds)||!$memberIds||count($memberIds)>100||count(array_unique($memberIds,SORT_REGULAR))!==count($memberIds))return['ok'=>false,'error'=>'invalid_group_members'];
        foreach($memberIds as$id)if(!is_int($id)||$id<=0)return['ok'=>false,'error'=>'invalid_group_members'];
        RoutingAccessService::ensureSchema();$pdo=ConversationDb::connection();$projectId=ProjectAccessService::projectIdByKey($projectKey);
        if($projectId<=0)return['ok'=>false,'error'=>'project_not_found'];
        $pdo->beginTransaction();
        try{
            $lock=$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'?' FOR UPDATE':'';
            if(!ManagerAuthService::isAdmin(ManagerAuthService::byId($managerId,true))){$pdo->rollBack();return['ok'=>false,'error'=>'admin_required'];}
            $q=$pdo->prepare('SELECT id FROM projects WHERE id=?'.$lock);$q->execute([$projectId]);$q->fetchColumn();
            if(!ProjectAccessService::canAccess($managerId,$projectKey,true)){$pdo->rollBack();return['ok'=>false,'error'=>'project_access_denied'];}
            $before=self::groupRow($groupId,true);
            if(!$before||(int)$before['project_id']!==$projectId){$pdo->rollBack();return['ok'=>false,'error'=>'group_project_mismatch'];}
            $q=$pdo->prepare('SELECT DISTINCT m.id FROM managers m JOIN manager_projects mp ON mp.manager_id=m.id WHERE m.id IN ('.implode(',',array_fill(0,count($memberIds),'?')).') AND mp.project_id=? AND m.is_active=1'.$lock);$q->execute([...$memberIds,$projectId]);
            $eligible=array_map('intval',array_column($q->fetchAll(),'id'));sort($eligible);$expected=$memberIds;sort($expected);
            if($eligible!==$expected){$pdo->rollBack();return['ok'=>false,'error'=>'invalid_group_members'];}
            $insert=$pdo->prepare('INSERT IGNORE INTO manager_group_members(group_id,manager_id) VALUES(?,?)');foreach($memberIds as$id)$insert->execute([$groupId,$id]);
            $pdo->commit();AuditLogService::record($managerId,'routing_group_members_appended','manager_group',(string)$groupId,$projectKey,$before,self::groupRow($groupId));
            return['ok'=>true,'group_id'=>$groupId];
        }catch(Throwable$e){if($pdo->inTransaction())$pdo->rollBack();return['ok'=>false,'error'=>'save_failed'];}
    }

    public static function saveGroupResult(int $managerId,string $projectKey,int $groupId,string $groupKey,string $displayName,array $memberIds,$territoryRegionIds=null): array
    {
        if(!self::requireAdmin($managerId))return['ok'=>false,'error'=>'admin_required'];
        if(!ProjectAccessService::canAccess($managerId,$projectKey))return['ok'=>false,'error'=>'project_access_denied'];
        RoutingAccessService::ensureSchema();$pdo=ConversationDb::connection();$projectId=ProjectAccessService::projectIdByKey($projectKey);$groupKey=trim($groupKey);$displayName=trim($displayName);
        if($projectId<=0)return['ok'=>false,'error'=>'project_not_found'];
        if($groupKey==='')return['ok'=>false,'error'=>'missing_group_key'];
        if($displayName==='')return['ok'=>false,'error'=>'missing_display_name'];
        if($territoryRegionIds!==null&&!is_array($territoryRegionIds))return['ok'=>false,'error'=>'invalid_territory_regions'];
        try{$scopes=$territoryRegionIds===null?null:RegionalTerritoryCatalog::selected($territoryRegionIds);}
        catch(InvalidArgumentException$e){return['ok'=>false,'error'=>'invalid_territory_regions'];}
        catch(Throwable$e){return['ok'=>false,'error'=>'regional_catalog_unavailable'];}
        $memberIds=array_values(array_unique(array_filter(array_map('intval',$memberIds),static function($v){return $v>0;})));
        if($memberIds){
            $q=$pdo->prepare('SELECT DISTINCT m.id FROM managers m JOIN manager_projects mp ON mp.manager_id=m.id WHERE m.id IN ('.implode(',',array_fill(0,count($memberIds),'?')).') AND mp.project_id=? AND m.is_active=1');
            $q->execute(array_merge($memberIds,[$projectId]));
            $eligibleIds=array_map('intval',array_column($q->fetchAll(),'id'));sort($eligibleIds);$submittedIds=$memberIds;sort($submittedIds);
            if($eligibleIds!==$submittedIds)return['ok'=>false,'error'=>'invalid_group_members'];
        }
        $before=$groupId>0?self::groupRow($groupId):null;
        if($groupId>0&&!$before)return['ok'=>false,'error'=>'group_not_found'];
        if($groupId>0&&(int)($before['project_id']??0)!==$projectId)return['ok'=>false,'error'=>'group_project_mismatch'];
        $pdo->beginTransaction();try{
            $lock=$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'?' FOR UPDATE':'';
            $actor=ManagerAuthService::byId($managerId,true);
            if(!ManagerAuthService::isAdmin($actor)){$pdo->rollBack();return['ok'=>false,'error'=>'admin_required'];}
            $q=$pdo->prepare('SELECT id FROM projects WHERE id=?'.$lock);$q->execute([$projectId]);$q->fetchColumn();
            if(!ProjectAccessService::canAccess($managerId,$projectKey,true)){$pdo->rollBack();return['ok'=>false,'error'=>'project_access_denied'];}
            $before=$groupId>0?self::groupRow($groupId,true):null;
            if($groupId>0&&(!$before||(int)$before['project_id']!==$projectId)){$pdo->rollBack();return['ok'=>false,'error'=>'group_project_mismatch'];}
            if($memberIds){
                $q=$pdo->prepare('SELECT DISTINCT m.id FROM managers m JOIN manager_projects mp ON mp.manager_id=m.id WHERE m.id IN ('.implode(',',array_fill(0,count($memberIds),'?')).') AND mp.project_id=? AND m.is_active=1'.$lock);$q->execute([...$memberIds,$projectId]);
                $eligibleIds=array_map('intval',array_column($q->fetchAll(),'id'));sort($eligibleIds);$submittedIds=$memberIds;sort($submittedIds);
                if($eligibleIds!==$submittedIds){$pdo->rollBack();return['ok'=>false,'error'=>'invalid_group_members'];}
            }
            if($groupId>0){$q=$pdo->prepare('UPDATE manager_groups SET group_key=?,display_name=? WHERE id=? AND project_id=?');$q->execute([$groupKey,$displayName,$groupId,$projectId]);}
            else{$q=$pdo->prepare('INSERT INTO manager_groups (project_id,group_key,display_name) VALUES (?,?,?)');$q->execute([$projectId,$groupKey,$displayName]);$groupId=(int)$pdo->lastInsertId();}
            $pdo->prepare('DELETE FROM manager_group_members WHERE group_id=?')->execute([$groupId]);
            $ins=$pdo->prepare('INSERT IGNORE INTO manager_group_members (group_id,manager_id) VALUES (?,?)');
            foreach($memberIds as $mid)$ins->execute([$groupId,$mid]);
            if($scopes!==null)RegionalRoutingScopeService::replaceGroupScopes($pdo,$groupId,$scopes);
            $pdo->commit();AuditLogService::record($managerId,$before?'routing_group_updated':'routing_group_created','manager_group',(string)$groupId,$projectKey,$before,self::groupRow($groupId));return['ok'=>true,'group_id'=>$groupId];
        }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();if(self::isDuplicateKeyFailure($e))return['ok'=>false,'error'=>'duplicate_group_key'];return['ok'=>false,'error'=>'save_failed'];}
    }

    public static function saveSource(int $managerId,string $projectKey,int $sourceId,string $sourceKey,string $displayName,string $channel,int $primaryGroupId,string $fallbackMode,int $fallbackGroupId,int $fallbackAfter,string $handlingMode='ai',bool $suppressChannelOffer=false): bool
    {
        return !empty(self::saveSourceResult($managerId,$projectKey,$sourceId,$sourceKey,$displayName,$channel,$primaryGroupId,$fallbackMode,$fallbackGroupId,$fallbackAfter,$handlingMode,$suppressChannelOffer)['ok']);
    }

    public static function saveSourceResult(int $managerId,string $projectKey,int $sourceId,string $sourceKey,string $displayName,string $channel,int $primaryGroupId,string $fallbackMode,int $fallbackGroupId,int $fallbackAfter,string $handlingMode='ai',bool $suppressChannelOffer=false): array
    {
        if(!self::requireAdmin($managerId))return['ok'=>false,'error'=>'admin_required'];
        if(!ProjectAccessService::canAccess($managerId,$projectKey))return['ok'=>false,'error'=>'project_access_denied'];
        RoutingAccessService::ensureSchema();
        $pdo=ConversationDb::connection();
        $projectId=ProjectAccessService::projectIdByKey($projectKey);
        $sourceKey=trim($sourceKey);$displayName=trim($displayName);$channel=trim($channel);$handlingMode=trim($handlingMode);$fallbackMode=trim($fallbackMode);
        $fallbackAfter=max(0,$fallbackAfter);
        if($projectId<=0)return['ok'=>false,'error'=>'project_not_found'];
        if($sourceKey==='')return['ok'=>false,'error'=>'missing_source_key'];
        if($displayName==='')return['ok'=>false,'error'=>'missing_display_name'];
        if(!in_array($channel,['max','telegram','website'],true))return['ok'=>false,'error'=>'invalid_channel'];
        if(!in_array($handlingMode,['ai','manager','ask'],true))return['ok'=>false,'error'=>'invalid_handling_mode'];
        if(!in_array($fallbackMode,['none','delayed','immediate'],true))return['ok'=>false,'error'=>'invalid_fallback_mode'];
        $before=$sourceId>0?self::sourceRow($sourceId):null;
        if($sourceId>0&&!$before)return['ok'=>false,'error'=>'source_not_found'];
        if($sourceId>0&&(int)($before['project_id']??0)!==$projectId)return['ok'=>false,'error'=>'source_project_mismatch'];
        $validGroup=function(int $id)use($pdo,$projectId):?int{if($id<=0)return null;$q=$pdo->prepare('SELECT id FROM manager_groups WHERE id=? AND project_id=? AND is_active=1');$q->execute([$id,$projectId]);return$q->fetchColumn()?(int)$id:null;};
        $primary=$validGroup($primaryGroupId);$fallback=$validGroup($fallbackGroupId);
        if($primaryGroupId>0&&$primary===null)return['ok'=>false,'error'=>'invalid_primary_group'];
        if($fallbackMode==='none'){$fallback=null;$fallbackAfter=0;}
        if($fallbackMode==='immediate')$fallbackAfter=0;
        if($fallbackMode!=='none'&&$fallbackGroupId>0&&$fallback===null)return['ok'=>false,'error'=>'invalid_fallback_group'];
        if($fallbackMode!=='none'&&$fallback===null)return['ok'=>false,'error'=>'fallback_group_required'];
        $suppress=$suppressChannelOffer?1:0;
        try{
            if($sourceId>0){
                $q=$pdo->prepare('UPDATE conversation_sources SET source_key=?,display_name=?,channel=?,handling_mode=?,suppress_channel_offer=?,primary_group_id=?,fallback_mode=?,fallback_group_id=?,fallback_after_minutes=? WHERE id=? AND project_id=?');
                $q->execute([$sourceKey,$displayName,$channel,$handlingMode,$suppress,$primary,$fallbackMode,$fallback,$fallbackAfter,$sourceId,$projectId]);
            }else{
                $q=$pdo->prepare('INSERT INTO conversation_sources (project_id,source_key,display_name,channel,handling_mode,suppress_channel_offer,primary_group_id,fallback_mode,fallback_group_id,fallback_after_minutes) VALUES (?,?,?,?,?,?,?,?,?,?)');
                $q->execute([$projectId,$sourceKey,$displayName,$channel,$handlingMode,$suppress,$primary,$fallbackMode,$fallback,$fallbackAfter]);
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

    private static function groupRow(int $id,bool$currentRead=false): ?array
    {
        $pdo=ConversationDb::connection();$lock=$currentRead&&$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'?' FOR UPDATE':'';$q=$pdo->prepare('SELECT id,project_id,group_key,display_name,is_active FROM manager_groups WHERE id=? LIMIT 1'.$lock);$q->execute([$id]);$row=$q->fetch();if(!$row)return null;$m=$pdo->prepare('SELECT manager_id FROM manager_group_members WHERE group_id=? ORDER BY manager_id'.$lock);$m->execute([$id]);$row['member_ids']=array_map('intval',array_column($m->fetchAll(),'manager_id'));$row['territories']=RegionalRoutingScopeService::groupScopes($id);$row['territory_region_ids']=array_column($row['territories'],'region_id');return$row;
    }
    private static function sourceRow(int $id): ?array
    {
        $q=ConversationDb::connection()->prepare('SELECT id,project_id,source_key,display_name,channel,handling_mode,suppress_channel_offer,primary_group_id,fallback_mode,fallback_group_id,fallback_after_minutes,is_active FROM conversation_sources WHERE id=? LIMIT 1');$q->execute([$id]);$row=$q->fetch();return$row?:null;
    }
}
