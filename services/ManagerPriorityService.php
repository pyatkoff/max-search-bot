<?php
require_once __DIR__ . '/ConversationDb.php';
require_once __DIR__ . '/AuditLogService.php';

class ManagerPriorityService
{
    private const TYPES=['project_key','source_key','platform','entry_channel','region_id','campaign_id'];

    public static function snapshot(): array
    {
        $pdo=ConversationDb::connection();
        $rules=$pdo->query('SELECT id,manager_id,rule_type,rule_value,bonus,is_active,comment FROM manager_priority_rules ORDER BY manager_id,is_active DESC,id')->fetchAll();
        return ['rule_types'=>self::TYPES,'rules'=>$rules];
    }

    public static function saveRule(array $data,int $actorManagerId): array
    {
        $id=(int)($data['rule_id']??0);$managerId=(int)($data['manager_id']??0);
        $type=trim((string)($data['rule_type']??''));$value=trim((string)($data['rule_value']??''));
        $bonus=max(-100000,min(100000,(int)($data['bonus']??0)));$active=!empty($data['is_active'])?1:0;
        $comment=trim((string)($data['comment']??''));
        if($managerId<=0||!in_array($type,self::TYPES,true)||$value===''||mb_strlen($value)>191)return['ok'=>false,'error'=>'invalid_rule'];
        $pdo=ConversationDb::connection();$before=$id>0?self::ruleRow($id):null;
        if($id>0){$q=$pdo->prepare('UPDATE manager_priority_rules SET manager_id=?,rule_type=?,rule_value=?,bonus=?,is_active=?,comment=? WHERE id=?');$q->execute([$managerId,$type,$value,$bonus,$active,$comment!==''?$comment:null,$id]);if(!$q->rowCount()&&!self::ruleRow($id))return['ok'=>false,'error'=>'not_found'];}
        else{$q=$pdo->prepare('INSERT INTO manager_priority_rules (manager_id,rule_type,rule_value,bonus,is_active,comment) VALUES (?,?,?,?,?,?)');$q->execute([$managerId,$type,$value,$bonus,$active,$comment!==''?$comment:null]);$id=(int)$pdo->lastInsertId();}
        AuditLogService::record($actorManagerId,$before?'manager_priority_rule_updated':'manager_priority_rule_created','manager_priority_rule',(string)$id,'',$before,self::ruleRow($id));
        return['ok'=>true,'rule_id'=>$id];
    }

    public static function scores(array $managerIds,array $conversation): array
    {
        $managerIds=array_values(array_unique(array_filter(array_map('intval',$managerIds),static fn($v)=>$v>0)));if(!$managerIds)return[];
        $pdo=ConversationDb::connection();$in=implode(',',array_fill(0,count($managerIds),'?'));
        $q=$pdo->prepare("SELECT id,priority FROM managers WHERE id IN ($in)");$q->execute($managerIds);$scores=[];
        foreach($q->fetchAll() as $row)$scores[(int)$row['id']]=(int)($row['priority']??0);
        $q=$pdo->prepare("SELECT manager_id,rule_type,rule_value,bonus FROM manager_priority_rules WHERE is_active=1 AND manager_id IN ($in)");$q->execute($managerIds);
        foreach($q->fetchAll() as $rule){$mid=(int)$rule['manager_id'];if(!array_key_exists($mid,$scores))continue;if(self::matches($rule,$conversation))$scores[$mid]+=(int)$rule['bonus'];}
        return$scores;
    }

    public static function preferred(array $managerIds,array $conversation): array
    {
        $scores=self::scores($managerIds,$conversation);if(!$scores)return[];$max=max($scores);
        return array_values(array_map('intval',array_keys(array_filter($scores,static fn($score)=>$score===$max))));
    }

    private static function matches(array $rule,array $conversation): bool
    {
        $type=(string)$rule['rule_type'];$expected=mb_strtolower(trim((string)$rule['rule_value']));
        $actual='';
        if($type==='project_key')$actual=(string)($conversation['project_key']??'');
        elseif($type==='source_key')$actual=(string)($conversation['source_key']??'');
        elseif($type==='platform')$actual=(string)($conversation['channel']??'');
        elseif($type==='entry_channel')$actual=(string)($conversation['entry_channel']??'');
        elseif($type==='region_id')$actual=(string)($conversation['attribution_region']??'');
        elseif($type==='campaign_id')$actual=(string)($conversation['attribution_campaign']??'');
        return mb_strtolower(trim($actual))===$expected;
    }

    private static function ruleRow(int $id): ?array
    {
        $q=ConversationDb::connection()->prepare('SELECT id,manager_id,rule_type,rule_value,bonus,is_active,comment FROM manager_priority_rules WHERE id=? LIMIT 1');$q->execute([$id]);$row=$q->fetch();return$row?:null;
    }
}
