<?php
declare(strict_types=1);
require_once __DIR__.'/ConversationDb.php';
require_once __DIR__.'/ConversationEntryRegionService.php';
require_once __DIR__.'/RegionalTerritoryCatalog.php';

/** Additional opt-in ACL only; source routing and assignments keep their owners. */
final class RegionalRoutingScopeService
{
    private static function key(string$key): string{return strtolower(trim($key));}
    private static function currentRead(PDO$pdo,bool$currentRead): string{return$currentRead&&$pdo->inTransaction()&&$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'?' FOR UPDATE':'';}

    public static function regionalProjectIds(int$managerId): array
    {
        $q=ConversationDb::connection()->prepare('SELECT project_id FROM manager_regional_access WHERE manager_id=? AND regional_scope_required<>0 ORDER BY project_id');$q->execute([$managerId]);
        return array_map('intval',array_column($q->fetchAll(),'project_id'));
    }

    /** Request-local only. Missing/invalid scopes never remove an opted-in mode. */
    public static function context(int$managerId,?array$projects=null,bool$currentRead=false): array
    {
        $pdo=ConversationDb::connection();
        if($projects===null){$q=$pdo->prepare('SELECT p.project_key,ra.regional_scope_required FROM manager_regional_access ra JOIN projects p ON p.id=ra.project_id WHERE ra.manager_id=? AND ra.regional_scope_required<>0'.self::currentRead($pdo,$currentRead));$q->execute([$managerId]);$projects=$q->fetchAll();}
        $required=[];foreach($projects as$row){if(!array_key_exists('regional_scope_required',$row))throw new RuntimeException('regional_mode_missing');if((string)$row['regional_scope_required']!=='0')$required[self::key((string)$row['project_key'])]=(string)$row['regional_scope_required']==='1';}
        if(!$required)return['required'=>[],'scopes'=>[]];
        $q=$pdo->prepare('SELECT t.*,g.project_id,p.project_key FROM manager_group_territories t JOIN manager_groups g ON g.id=t.group_id JOIN manager_group_members gm ON gm.group_id=g.id JOIN projects p ON p.id=g.project_id WHERE gm.manager_id=? AND g.is_active=1 ORDER BY g.id,t.territory_region_id'.self::currentRead($pdo,$currentRead));$q->execute([$managerId]);
        $scopes=[];foreach($q->fetchAll()as$row){$key=self::key((string)$row['project_key']);if(($required[$key]??false)&&RegionalTerritoryCatalog::validHeader($row))$scopes[$key][(int)$row['group_id'].':'.$row['territory_region_id']]=$row;}
        return['required'=>$required,'scopes'=>$scopes];
    }

    /** Receipt candidate gate BEFORE Inbox LIMIT; fresh canonical validation follows. */
    public static function candidatePredicate(int$managerId,string$alias,array&$args,?array$projects=null): string
    {
        if(!preg_match('/\A[a-z][a-z0-9_]*\z/D',$alias))throw new InvalidArgumentException('Invalid conversation alias');
        $context=self::context($managerId,$projects);if(!$context['required'])return'1=1';
        $pdo=ConversationDb::connection();
        $json="CASE WHEN JSON_VALID($alias.metadata_json) THEN $alias.metadata_json ELSE '{}' END";
        $mysql=$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql';
        $value=static function(string$field)use($json,$mysql):string{$extract="JSON_EXTRACT($json,'$.first_entry_region.$field')";return$mysql?'(CONVERT(JSON_UNQUOTE('.$extract.') USING utf8mb4) COLLATE utf8mb4_bin)':$extract.' COLLATE BINARY';};
        $type=static fn(string$field):string=>$mysql?"JSON_TYPE(JSON_EXTRACT($json,'$.first_entry_region.$field'))":"JSON_TYPE($json,'$.first_entry_region.$field')";
        $stringType=$mysql?'STRING':'text';$intType=$mysql?'INTEGER':'integer';
        $region=$value('yandex_region_id');$sha=$value('receipt_sha256');$name=$value('name');
        $bytes=static fn(string$expression):string=>$mysql?'OCTET_LENGTH('.$expression.')':'LENGTH(CAST('.$expression.' AS BLOB))';
        $receipt=[
            $bytes($alias.'.metadata_json').'<=262144',
            $type('version')."='$intType'",$value('version').'=1',
            $type('kind')."='$stringType'",$value('kind')."='advertising'",
            "$alias.channel IN ('max','telegram','website')",$type('platform')."='$stringType'",$value('platform')."=$alias.channel",
            $type('source')."='$stringType'",$value('source')." IN ('max_start_payload','telegram_start_payload','website_entry','incoming_attribution')",
            $type('yandex_region_id')."='$stringType'",$type('name')."='$stringType'",$bytes($name).'<=400',
            $type('recorded_at_unix')."='$intType'",$value('recorded_at_unix').' BETWEEN 946684800 AND 4102444800',
            $type('receipt_sha256')."='$stringType'",'LENGTH('.$sha.')=64',
            $mysql?$sha." NOT REGEXP '[^a-f0-9]'":$sha." NOT GLOB '*[^a-f0-9]*'",
        ];
        $args[]=$managerId;$args[]=$managerId;
        $sql="(NOT EXISTS (SELECT 1 FROM manager_regional_access ra JOIN projects rp ON rp.id=ra.project_id WHERE ra.manager_id=? AND ra.regional_scope_required<>0 AND rp.project_key=$alias.project_key) OR $alias.manager_id=?";
        $pairs=[];
        foreach($context['scopes']as$scopes)foreach($scopes as$scope){$pairs[]='(tr.group_id=? AND tr.territory_region_id=?)';$args[]=(int)$scope['group_id'];$args[]=$scope['territory_region_id'];}
        if($pairs)$sql.=" OR (($alias.manager_id IS NULL OR $alias.manager_id=0) AND ".implode(' AND ',$receipt)." AND EXISTS (SELECT 1 FROM manager_group_territory_regions tr JOIN manager_groups rg ON rg.id=tr.group_id WHERE rg.project_id=(SELECT id FROM projects WHERE project_key=$alias.project_key) AND tr.region_id=$region AND (".implode(' OR ',$pairs).')))';
        return$sql.')';
    }

    /** One fresh receipt hydration + one set membership query, including minimal callers. */
    public static function visibleRows(int$managerId,array$rows,?array$context=null,bool$currentRead=false,bool$receiving=false): array
    {
        $context=$context??self::context($managerId,null,$currentRead);if(!$context['required']||!$rows)return$rows;
        $ids=[];foreach($rows as$row){$id=(int)($row['id']??$row['conversation_id']??0);if($id>0)$ids[$id]=$id;}
        if(!$ids)return[];
        $pdo=ConversationDb::connection();$q=$pdo->prepare('SELECT id,project_key,source_id,status,manager_id,channel,metadata_json FROM conversations WHERE id IN ('.implode(',',array_fill(0,count($ids),'?')).') ORDER BY id'.self::currentRead($pdo,$currentRead));$q->execute(array_values($ids));$actual=[];$regions=[];
        foreach($q->fetchAll()as$row){$id=(int)$row['id'];$actual[$id]=$row;$receipt=ConversationEntryRegionService::firstReceipt($row['metadata_json']??null,(string)$row['channel']);if($receipt)$regions[$id]=$receipt['yandex_region_id'];}
        $wanted=array_values(array_unique(array_values($regions)));$allowed=[];$pairs=[];$args=[];
        foreach($context['scopes']as$scopes)foreach($scopes as$scope){$pairs[]='(group_id=? AND territory_region_id=?)';$args[]=(int)$scope['group_id'];$args[]=$scope['territory_region_id'];}
        if($wanted&&$pairs){
            $q=$pdo->prepare('SELECT group_id,territory_region_id,region_id FROM manager_group_territory_regions WHERE ('.implode(' OR ',$pairs).') AND region_id IN ('.implode(',',array_fill(0,count($wanted),'?')).') ORDER BY group_id,territory_region_id,region_id'.self::currentRead($pdo,$currentRead));$q->execute([...$args,...$wanted]);
            foreach($q->fetchAll()as$row)$allowed[(int)$row['group_id'].':'.$row['territory_region_id']][(string)$row['region_id']]=true;
        }
        return array_values(array_filter($rows,static function(array$row)use($actual,$managerId,$context,$allowed,$regions,$receiving):bool{
            $id=(int)($row['id']??$row['conversation_id']??0);$stored=$actual[$id]??null;if(!$stored)return false;
            foreach($receiving?['project_key','source_id','channel']:['project_key','source_id','status','manager_id','channel']as$field)if(array_key_exists($field,$row)&&(string)($row[$field]??'')!==(string)($stored[$field]??''))return false;
            $key=self::key((string)$stored['project_key']);if(!array_key_exists($key,$context['required']))return true;
            if((int)($stored['manager_id']??0)===$managerId)return true;
            if(!$receiving&&(int)($stored['manager_id']??0)>0)return false;
            $region=$regions[$id]??null;if($region===null||$context['required'][$key]!==true)return false;
            foreach($context['scopes'][$key]??[]as$scopeKey=>$scope)if(isset($allowed[$scopeKey][$region]))return true;
            return false;
        }));
    }

    public static function groupScopes(int$groupId): array
    {
        $q=ConversationDb::connection()->prepare('SELECT t.*,(SELECT COUNT(*) FROM manager_group_territory_regions r WHERE r.group_id=t.group_id AND r.territory_region_id=t.territory_region_id) AS descendant_count FROM manager_group_territories t WHERE t.group_id=? ORDER BY t.territory_region_id');$q->execute([$groupId]);$result=[];
        foreach($q->fetchAll()as$row)$result[]=['region_id'=>(string)$row['territory_region_id'],'name'=>(string)((json_decode($row['prefix_json'],true)??[])[1]??''),'hierarchy'=>json_decode($row['anchor_hierarchy_json'],true),'prefix'=>json_decode($row['prefix_json'],true),'catalog_sha256'=>$row['catalog_sha256'],'descendant_count'=>(int)$row['descendant_count'],'valid'=>RegionalTerritoryCatalog::validHeader($row)];
        return$result;
    }

    public static function replaceGroupScopes(PDO$pdo,int$groupId,array$scopes): void
    {
        if(!$pdo->inTransaction())throw new LogicException('Territory changes require the group transaction');
        $pdo->prepare('DELETE FROM manager_group_territory_regions WHERE group_id=?')->execute([$groupId]);
        $pdo->prepare('DELETE FROM manager_group_territories WHERE group_id=?')->execute([$groupId]);
        $header=$pdo->prepare('INSERT INTO manager_group_territories(group_id,territory_region_id,anchor_hierarchy_json,prefix_json,catalog_sha256) VALUES(?,?,?,?,?)');
        $member=$pdo->prepare('INSERT INTO manager_group_territory_regions(group_id,territory_region_id,region_id) VALUES(?,?,?)');
        foreach($scopes as$scope){
            if($scope['catalog_sha256']!==RegionalTerritoryCatalog::manifestHash())throw new RuntimeException('regional_catalog_changed');
            $header->execute([$groupId,$scope['region_id'],json_encode($scope['hierarchy'],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),json_encode($scope['prefix'],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),$scope['catalog_sha256']]);
            foreach($scope['region_ids']as$id)$member->execute([$groupId,$scope['region_id'],$id]);
        }
    }
}
