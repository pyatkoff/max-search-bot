<?php
require_once __DIR__ . '/ConversationDb.php';
require_once __DIR__ . '/SalesPipelineService.php';
require_once __DIR__ . '/AuditLogService.php';

final class SalesPipelineCatalogAdminService
{
    public static function snapshot(): array
    {
        $stages=SalesPipelineService::stages(false);
        $stageUsage=self::stageUsageCounts();
        foreach($stages as &$stage)$stage['usage_count']=$stageUsage[(string)$stage['stage_key']]??0;
        unset($stage);
        $tags=SalesPipelineService::tags(false);
        $tagUsage=self::tagUsageCounts();
        foreach($tags as &$tag)$tag['usage_count']=$tagUsage[(int)$tag['id']]??0;
        unset($tag);
        return ['stages'=>$stages,'tags'=>$tags];
    }

    public static function saveStage(array $data,int $actor): array
    {
        $key=trim((string)($data['stage_key']??''));
        $name=trim((string)($data['display_name']??''));
        $color=self::color((string)($data['color']??'#64748b'));
        $sort=(int)($data['sort_order']??0);
        $active=!empty($data['is_active'])?1:0;
        $terminal=!empty($data['is_terminal'])?1:0;
        $won=!empty($data['is_won'])?1:0;
        if(!preg_match('/^[a-z0-9_-]{1,32}$/',$key))return['ok'=>false,'error'=>'invalid_stage_key'];
        if($name===''||mb_strlen($name)>96)return['ok'=>false,'error'=>'invalid_display_name'];
        if($won)$terminal=1;
        $pdo=ConversationDb::connection();
        $q=$pdo->prepare('SELECT * FROM lead_stages WHERE stage_key=? LIMIT 1');$q->execute([$key]);$before=$q->fetch()?:null;
        if($before && !$active && !empty($before['is_active'])){
            $usage=self::stageUsageCount($key);
            if($usage>0)return['ok'=>false,'error'=>'stage_in_use','usage_count'=>$usage];
        }
        try{
            if($before){$q=$pdo->prepare('UPDATE lead_stages SET display_name=?,color=?,sort_order=?,is_active=?,is_terminal=?,is_won=? WHERE stage_key=?');$q->execute([$name,$color,$sort,$active,$terminal,$won,$key]);}
            else{$q=$pdo->prepare('INSERT INTO lead_stages(stage_key,display_name,color,sort_order,is_active,is_terminal,is_won) VALUES(?,?,?,?,?,?,?)');$q->execute([$key,$name,$color,$sort,$active,$terminal,$won]);}
        }catch(Throwable $e){return['ok'=>false,'error'=>self::isDuplicateKeyFailure($e)?'duplicate_stage_key':'save_failed'];}
        $after=self::stage($key);AuditLogService::record($actor,$before?'lead_stage_updated':'lead_stage_created','lead_stage',$key,null,$before,$after);
        return['ok'=>true,'stage'=>$after];
    }

    public static function saveTag(array $data,int $actor): array
    {
        $id=(int)($data['id']??0);$key=trim((string)($data['tag_key']??''));$name=trim((string)($data['display_name']??''));
        $color=self::color((string)($data['color']??'#64748b'));$sort=(int)($data['sort_order']??0);$active=!empty($data['is_active'])?1:0;
        if(!preg_match('/^[a-z0-9_-]{1,64}$/',$key))return['ok'=>false,'error'=>'invalid_tag_key'];
        if($name===''||mb_strlen($name)>96)return['ok'=>false,'error'=>'invalid_display_name'];
        $pdo=ConversationDb::connection();$before=null;
        if($id>0){$q=$pdo->prepare('SELECT * FROM lead_tags WHERE id=? LIMIT 1');$q->execute([$id]);$before=$q->fetch()?:null;if(!$before)return['ok'=>false,'error'=>'not_found'];}
        if($before && !$active && !empty($before['is_active'])){
            $usage=self::tagUsageCount($id);
            if($usage>0)return['ok'=>false,'error'=>'tag_in_use','usage_count'=>$usage];
        }
        try{
            if($before){$q=$pdo->prepare('UPDATE lead_tags SET tag_key=?,display_name=?,color=?,sort_order=?,is_active=? WHERE id=?');$q->execute([$key,$name,$color,$sort,$active,$id]);}
            else{$q=$pdo->prepare('INSERT INTO lead_tags(tag_key,display_name,color,sort_order,is_active) VALUES(?,?,?,?,?)');$q->execute([$key,$name,$color,$sort,$active]);$id=(int)$pdo->lastInsertId();}
        }catch(Throwable $e){return['ok'=>false,'error'=>self::isDuplicateKeyFailure($e)?'duplicate_tag_key':'save_failed'];}
        $after=self::tag($id);AuditLogService::record($actor,$before?'lead_tag_updated':'lead_tag_created','lead_tag',(string)$id,null,$before,$after);
        return['ok'=>true,'tag'=>$after];
    }

    private static function stageUsageCounts(): array
    {
        $rows=ConversationDb::connection()->query("SELECT lead_stage_key,COUNT(*) AS usage_count FROM conversations WHERE lead_stage_key IS NOT NULL AND lead_stage_key<>'' GROUP BY lead_stage_key")->fetchAll();
        $out=[];foreach($rows as $row)$out[(string)$row['lead_stage_key']]=(int)$row['usage_count'];return$out;
    }
    private static function tagUsageCounts(): array
    {
        $rows=ConversationDb::connection()->query('SELECT tag_id,COUNT(*) AS usage_count FROM conversation_lead_tags GROUP BY tag_id')->fetchAll();
        $out=[];foreach($rows as $row)$out[(int)$row['tag_id']]=(int)$row['usage_count'];return$out;
    }
    private static function stageUsageCount(string $key): int{$q=ConversationDb::connection()->prepare('SELECT COUNT(*) FROM conversations WHERE lead_stage_key=?');$q->execute([$key]);return(int)$q->fetchColumn();}
    private static function tagUsageCount(int $id): int{$q=ConversationDb::connection()->prepare('SELECT COUNT(*) FROM conversation_lead_tags WHERE tag_id=?');$q->execute([$id]);return(int)$q->fetchColumn();}
    private static function isDuplicateKeyFailure(Throwable $e): bool
    {
        if(!$e instanceof PDOException)return false;
        $info=is_array($e->errorInfo??null)?$e->errorInfo:[];
        $state=(string)($info[0]??$e->getCode());
        $driverCode=(int)($info[1]??0);
        return $driverCode===1062||in_array($driverCode,[19,1555,2067],true)||in_array($state,['23000','23505'],true);
    }
    private static function color(string $v): string{return preg_match('/^#[0-9a-fA-F]{6}$/',$v)?strtolower($v):'#64748b';}
    private static function stage(string $key): ?array{$q=ConversationDb::connection()->prepare('SELECT stage_key,display_name,color,sort_order,is_active,is_terminal,is_won FROM lead_stages WHERE stage_key=?');$q->execute([$key]);return$q->fetch()?:null;}
    private static function tag(int $id): ?array{$q=ConversationDb::connection()->prepare('SELECT id,tag_key,display_name,color,sort_order,is_active FROM lead_tags WHERE id=?');$q->execute([$id]);return$q->fetch()?:null;}
}