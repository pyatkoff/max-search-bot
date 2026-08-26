<?php
require_once __DIR__ . '/ConversationDb.php';
require_once __DIR__ . '/AuditLogService.php';
require_once __DIR__ . '/RoutingAccessService.php';

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
        $pdo=ConversationDb::connection();
        $check=$pdo->prepare('SELECT 1 FROM managers WHERE id=? LIMIT 1');$check->execute([$managerId]);if(!$check->fetchColumn())return['ok'=>false,'error'=>'manager_not_found'];
        $before=$id>0?self::ruleRow($id):null;
        if($id>0){$q=$pdo->prepare('UPDATE manager_priority_rules SET manager_id=?,rule_type=?,rule_value=?,bonus=?,is_active=?,comment=? WHERE id=?');$q->execute([$managerId,$type,$value,$bonus,$active,$comment!==''?$comment:null,$id]);if(!$q->rowCount()&&!self::ruleRow($id))return['ok'=>false,'error'=>'not_found'];}
        else{$q=$pdo->prepare('INSERT INTO manager_priority_rules (manager_id,rule_type,rule_value,bonus,is_active,comment) VALUES (?,?,?,?,?,?)');$q->execute([$managerId,$type,$value,$bonus,$active,$comment!==''?$comment:null]);$id=(int)$pdo->lastInsertId();}
        AuditLogService::record($actorManagerId,$before?'manager_priority_rule_updated':'manager_priority_rule_created','manager_priority_rule',(string)$id,'',$before,self::ruleRow($id));
        return['ok'=>true,'rule_id'=>$id];
    }

    public static function scoreBreakdown(array $managerIds,array $conversation): array
    {
        $managerIds=array_values(array_unique(array_filter(array_map('intval',$managerIds),static fn($v)=>$v>0)));if(!$managerIds)return[];
        $pdo=ConversationDb::connection();$in=implode(',',array_fill(0,count($managerIds),'?'));
        $q=$pdo->prepare("SELECT id,priority FROM managers WHERE id IN ($in)");$q->execute($managerIds);$details=[];
        foreach($q->fetchAll() as $row){$mid=(int)$row['id'];$base=(int)($row['priority']??0);$details[$mid]=['base'=>$base,'matched_rules'=>[],'final'=>$base];}
        $q=$pdo->prepare("SELECT id,manager_id,rule_type,rule_value,bonus,comment FROM manager_priority_rules WHERE is_active=1 AND manager_id IN ($in) ORDER BY id");$q->execute($managerIds);
        foreach($q->fetchAll() as $rule){$mid=(int)$rule['manager_id'];if(!isset($details[$mid])||!self::matches($rule,$conversation))continue;$bonus=(int)$rule['bonus'];$details[$mid]['matched_rules'][]=['rule_id'=>(int)$rule['id'],'type'=>(string)$rule['rule_type'],'value'=>(string)$rule['rule_value'],'bonus'=>$bonus,'comment'=>$rule['comment']??null];$details[$mid]['final']+=$bonus;}
        return$details;
    }

    public static function scores(array $managerIds,array $conversation): array
    {
        $scores=[];foreach(self::scoreBreakdown($managerIds,$conversation) as $mid=>$detail)$scores[(int)$mid]=(int)$detail['final'];return$scores;
    }

    public static function preferred(array $managerIds,array $conversation): array
    {
        return self::preferredFromScores(self::scores($managerIds,$conversation));
    }

    public static function notificationSelection(array $conversation): array
    {
        $eligible=[];$pdo=ConversationDb::connection();
        $q=$pdo->query('SELECT id FROM managers WHERE is_active=1 AND is_working=1');
        foreach($q->fetchAll() as $row){$id=(int)$row['id'];if(RoutingAccessService::canSeeConversation($id,$conversation))$eligible[]=$id;}
        $scoreBreakdown=self::scoreBreakdown($eligible,$conversation);$scores=[];foreach($scoreBreakdown as $mid=>$detail)$scores[(int)$mid]=(int)$detail['final'];$selected=self::preferredFromScores($scores);
        return['eligible_manager_ids'=>$eligible,'selected_manager_ids'=>$selected,'scores'=>$scores,'score_breakdown'=>$scoreBreakdown];
    }

    public static function notificationSelectionForConversation(int $conversationId): array
    {
        $q=ConversationDb::connection()->prepare('SELECT c.id,c.project_key,c.source_id,c.status,c.manager_id,c.channel,c.entry_channel,c.attribution_region,c.attribution_campaign,s.source_key FROM conversations c LEFT JOIN conversation_sources s ON s.id=c.source_id WHERE c.id=? LIMIT 1');
        $q->execute([$conversationId]);$conversation=$q->fetch();if(!$conversation)return['conversation'=>null,'eligible_manager_ids'=>[],'selected_manager_ids'=>[],'scores'=>[],'score_breakdown'=>[]];
        if((string)$conversation['status']==='manager'&&!empty($conversation['manager_id']))return['conversation'=>$conversation,'eligible_manager_ids'=>[(int)$conversation['manager_id']],'selected_manager_ids'=>[(int)$conversation['manager_id']],'scores'=>[],'score_breakdown'=>[]];
        if((string)$conversation['status']!=='waiting_manager')return['conversation'=>$conversation,'eligible_manager_ids'=>[],'selected_manager_ids'=>[],'scores'=>[],'score_breakdown'=>[]];
        return['conversation'=>$conversation]+self::notificationSelection($conversation);
    }

    private static function preferredFromScores(array $scores): array
    {
        if(!$scores)return[];$max=max($scores);
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
