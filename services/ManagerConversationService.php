<?php
require_once __DIR__ . '/ConversationDb.php';
require_once __DIR__ . '/ConversationControlService.php';
require_once __DIR__ . '/ProjectAccessService.php';
require_once __DIR__ . '/RoutingAccessService.php';
require_once __DIR__ . '/ManagerReadService.php';
require_once __DIR__ . '/ManagerAuthService.php';
require_once __DIR__ . '/ManagerDeliveryStateService.php';
require_once __DIR__ . '/SalesPipelineService.php';

class ManagerConversationService
{
    private static function resolveProject(int $managerId, string $projectKey=''): string
    {
        $projectKey=trim($projectKey);
        if($projectKey==='')$projectKey=ProjectAccessService::defaultProjectKey($managerId);
        return ProjectAccessService::canAccess($managerId,$projectKey)?$projectKey:'';
    }

    private static function latestManagerRequestSql(string $conversationAlias='c'): string
    {
        return "(SELECT MAX(er.created_at) FROM conversation_events er WHERE er.conversation_id={$conversationAlias}.id AND er.event_type='waiting_manager')";
    }

    private static function awaitingFirstReplySql(string $conversationAlias='c'): string
    {
        $request=self::latestManagerRequestSql($conversationAlias);
        return "({$conversationAlias}.status='manager' AND {$request} IS NOT NULL AND NOT EXISTS (SELECT 1 FROM messages mr WHERE mr.conversation_id={$conversationAlias}.id AND mr.direction='outbound' AND mr.sender_type='manager' AND mr.created_at>={$request}))";
    }

    private static function formatWaitAge(int $seconds): string
    {
        $seconds=max(0,$seconds);
        if($seconds<60)return '⏱ Без ответа <1 мин';
        $minutes=(int)floor($seconds/60);
        if($minutes<60)return '⏱ Без ответа '.$minutes.' мин';
        $hours=(int)floor($minutes/60);$rest=$minutes%60;
        return '⏱ Без ответа '.$hours.' ч'.($rest>0?' '.$rest.' мин':'');
    }

    public static function list(int $managerId, string $queue='waiting', int $limit=100, string $projectKey='*', string $managerFilter='', string $leadStageKey='', int $leadTagId=0): array
    {
        RoutingAccessService::ensureSchema();ManagerReadService::ensureSchema();
        $manager=ManagerAuthService::byId($managerId);$isAdmin=ManagerAuthService::isAdmin($manager);
        $limit=max(1,min(200,$limit));$where=[];$args=[];
        if($projectKey==='*' || trim($projectKey)===''){
            $projects=ProjectAccessService::projectsForManager($managerId);
            $keys=array_values(array_filter(array_map(static function($p){return (string)($p['project_key']??'');},$projects)));
            if(!$keys)return[];$where[]='c.project_key IN ('.implode(',',array_fill(0,count($keys),'?')).')';foreach($keys as $key)$args[]=$key;
        }else{
            $projectKey=self::resolveProject($managerId,$projectKey);if($projectKey==='')return[];$where[]='c.project_key=?';$args[]=$projectKey;
        }
        if($queue==='attention' || $queue==='waiting'){
            $where[]="((c.status='waiting_manager' AND c.manager_id IS NULL) OR ".self::awaitingFirstReplySql('c').')';
            if(!$isAdmin){$where[]='(c.manager_id IS NULL OR c.manager_id=?)';$args[]=$managerId;}
        }
        elseif($queue==='mine'){$where[]='c.status=?';$args[]='manager';$where[]='c.manager_id=?';$args[]=$managerId;}
        elseif($queue==='closed'){$where[]='c.status=?';$args[]='closed';}
        else{
            $where[]='c.status<>?';$args[]='closed';
            if(!$isAdmin){$where[]='(c.manager_id IS NULL OR c.manager_id=?)';$args[]=$managerId;}
        }

        if($isAdmin && $queue!=='mine'){
            $managerFilter=trim($managerFilter);
            if($managerFilter==='unassigned'){$where[]='c.manager_id IS NULL';}
            elseif($managerFilter==='mine'){$where[]='c.manager_id=?';$args[]=$managerId;}
            elseif(ctype_digit($managerFilter) && (int)$managerFilter>0){$where[]='c.manager_id=?';$args[]=(int)$managerFilter;}
        }

        $leadStageKey=trim($leadStageKey);
        if($leadStageKey!==''){$where[]='c.lead_stage_key=?';$args[]=$leadStageKey;}
        if($leadTagId>0){$where[]='EXISTS (SELECT 1 FROM conversation_lead_tags clt_filter WHERE clt_filter.conversation_id=c.id AND clt_filter.tag_id=?)';$args[]=$leadTagId;}

        $mid=(int)$managerId;
        $requestSql=self::latestManagerRequestSql('c');
        $awaitingSql=self::awaitingFirstReplySql('c');
        $sql='SELECT c.id,c.project_key,c.source_id,c.channel,c.status,c.lead_stage_key,c.manager_id,c.started_at,c.last_message_at,c.closed_at,'
            .'cu.display_name,m.display_name AS manager_name,p.display_name AS project_name,s.display_name AS source_name,'
            .$requestSql.' AS manager_request_at,CASE WHEN '.$awaitingSql.' THEN 1 ELSE 0 END AS awaiting_first_reply,'
            .'GREATEST(TIMESTAMPDIFF(SECOND,'.$requestSql.',NOW()),0) AS wait_age_seconds,'
            .'(SELECT mm.text FROM messages mm WHERE mm.conversation_id=c.id ORDER BY mm.id DESC LIMIT 1) AS last_text,'
            .'(SELECT mm.direction FROM messages mm WHERE mm.conversation_id=c.id ORDER BY mm.id DESC LIMIT 1) AS last_direction,'
            .'(SELECT COUNT(*) FROM messages um WHERE um.conversation_id=c.id AND um.direction=\'inbound\' AND um.sender_type=\'customer\' AND um.id>COALESCE((SELECT rr.last_read_message_id FROM manager_conversation_reads rr WHERE rr.manager_id='.$mid.' AND rr.conversation_id=c.id LIMIT 1),0)) AS unread_count '
            .'FROM conversations c JOIN customers cu ON cu.id=c.customer_id LEFT JOIN managers m ON m.id=c.manager_id LEFT JOIN projects p ON p.project_key=c.project_key LEFT JOIN conversation_sources s ON s.id=c.source_id WHERE '.implode(' AND ',$where)
            .' ORDER BY '.(($queue==='attention'||$queue==='waiting')?'COALESCE(manager_request_at,c.last_message_at,c.started_at) ASC':'COALESCE(c.last_message_at,c.started_at) DESC').' LIMIT 200';
        $q=ConversationDb::connection()->prepare($sql);$q->execute($args);$rows=$q->fetchAll();
        $rows=array_values(array_filter($rows,static function($row)use($managerId){return RoutingAccessService::canSeeConversation($managerId,$row);}));
        $rows=SalesPipelineService::decorateConversationRows($rows);
        $failures=ManagerDeliveryStateService::activeFailures(array_map(static function($row){return(int)($row['id']??0);},$rows));
        foreach($rows as &$row){
            $id=(int)($row['id']??0);$failure=$failures[$id]??null;$row['delivery_failure_category']=$failure['category']??null;
            if($failure){$preview=trim((string)($row['last_text']??''));$row['last_text']='🔴 Клиент недоступен в MAX'.($preview!==''?' · '.$preview:'');continue;}
            if(!empty($row['awaiting_first_reply'])){$preview=trim((string)($row['last_text']??''));$marker=self::formatWaitAge((int)($row['wait_age_seconds']??0));$row['last_text']=$marker.($preview!==''?' · '.$preview:'');}
        }unset($row);
        return array_slice($rows,0,$limit);
    }

    public static function queueCounts(int $managerId,string $projectKey='*'): array
    {
        $out=[];$rowsByQueue=[];
        foreach(['waiting','mine'] as $queue){
            $rows=self::list($managerId,$queue,200,$projectKey);$rowsByQueue[$queue]=$rows;
            $unreadRows=$queue==='waiting'?array_filter($rows,static function($r){return empty($r['manager_id']);}):$rows;
            $out[$queue]=['count'=>count($rows),'unread'=>array_sum(array_map(static function($r){return(int)($r['unread_count']??0);},$unreadRows))];
        }
        $unique=[];
        foreach(array_merge($rowsByQueue['waiting'],$rowsByQueue['mine']) as $row){
            $id=(int)($row['id']??0);if($id<=0)continue;
            $unique[$id]=max((int)($unique[$id]??0),(int)($row['unread_count']??0));
        }
        $out['notification_unread']=array_sum($unique);
        return$out;
    }

    public static function filterManagers(int $managerId): array
    {
        $manager=ManagerAuthService::byId($managerId);if(!ManagerAuthService::isAdmin($manager))return[];
        $projects=ProjectAccessService::projectsForManager($managerId);$projectIds=array_values(array_filter(array_map(static function($p){return(int)($p['id']??0);},$projects)));
        $pdo=ConversationDb::connection();
        if(!$projectIds){$q=$pdo->query('SELECT id,login,display_name FROM managers WHERE is_active=1 ORDER BY COALESCE(display_name,login),id');return$q->fetchAll();}
        $sql='SELECT DISTINCT m.id,m.login,m.display_name FROM managers m LEFT JOIN manager_projects mp ON mp.manager_id=m.id WHERE m.is_active=1 AND (mp.project_id IN ('.implode(',',array_fill(0,count($projectIds),'?')).') OR m.role=\'admin\') ORDER BY COALESCE(m.display_name,m.login),m.id';
        $q=$pdo->prepare($sql);$q->execute($projectIds);return$q->fetchAll();
    }

    public static function detail(int $conversationId,int $managerId): ?array
    {
        RoutingAccessService::ensureSchema();ManagerReadService::ensureSchema();
        $q=ConversationDb::connection()->prepare('SELECT c.id,c.project_key,c.source_id,c.channel,c.status,c.lead_stage_key,c.manager_id,c.started_at,c.last_message_at,c.closed_at,c.external_chat_id,cu.display_name,cu.phone,cu.email,m.display_name AS manager_name,p.display_name AS project_name,s.display_name AS source_name FROM conversations c JOIN customers cu ON cu.id=c.customer_id LEFT JOIN managers m ON m.id=c.manager_id LEFT JOIN projects p ON p.project_key=c.project_key LEFT JOIN conversation_sources s ON s.id=c.source_id WHERE c.id=? LIMIT 1');
        $q->execute([$conversationId]);$conversation=$q->fetch();if(!$conversation||!RoutingAccessService::canSeeConversation($managerId,$conversation))return null;
        ManagerReadService::markRead($managerId,$conversationId);
        $q=ConversationDb::connection()->prepare('SELECT id,direction,sender_type,text,created_at FROM messages WHERE conversation_id=? ORDER BY id ASC LIMIT 500');$q->execute([$conversationId]);$messages=$q->fetchAll();
        foreach($messages as &$message){if(($message['sender_type']??'')==='manager')$message['text']=html_entity_decode((string)$message['text'],ENT_QUOTES|ENT_HTML5,'UTF-8');}unset($message);
        return['conversation'=>$conversation,'messages'=>$messages];
    }

    private static function accessibleConversation(int $conversationId,int $managerId,bool $forUpdate=false): ?array
    {
        RoutingAccessService::ensureSchema();$sql='SELECT id,project_key,source_id,status,manager_id,external_chat_id,started_at,last_message_at FROM conversations WHERE id=? LIMIT 1'.($forUpdate?' FOR UPDATE':'');
        $q=ConversationDb::connection()->prepare($sql);$q->execute([$conversationId]);$row=$q->fetch();if(!$row||!RoutingAccessService::canSeeConversation($managerId,$row))return null;return$row;
    }

    public static function take(int $conversationId,int $managerId): bool
    {
        $pdo=ConversationDb::connection();
        $pdo->beginTransaction();
        try{
            $row=self::accessibleConversation($conversationId,$managerId,true);
            if(!$row||(string)$row['status']==='closed'||(!empty($row['manager_id'])&&(int)$row['manager_id']!==$managerId)){
                $pdo->rollBack();
                return false;
            }
            if((string)$row['status']==='manager' && (int)($row['manager_id']??0)===$managerId){
                $pdo->commit();
                return true;
            }
            $pdo->prepare('UPDATE conversations SET status=?,manager_id=? WHERE id=?')->execute(['manager',$managerId,$conversationId]);
            $pdo->prepare('INSERT INTO manager_assignments (conversation_id,manager_id,assignment_type) VALUES (?,?,?)')->execute([$conversationId,$managerId,'manual']);
            ConversationControlService::event($conversationId,'manager_taken','manager',$managerId);
            $pdo->commit();
            return true;
        }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw$e;}
    }
    public static function release(int $conversationId,int $managerId): bool
    {
        $pdo=ConversationDb::connection();$row=self::accessibleConversation($conversationId,$managerId);if(!$row||(int)($row['manager_id']??0)!==$managerId||(string)$row['status']!=='manager')return false;$chatId=$row['external_chat_id'];$pdo->prepare('UPDATE conversations SET status=?,manager_id=NULL WHERE id=?')->execute(['ai',$conversationId]);$pdo->prepare('UPDATE manager_assignments SET released_at=NOW() WHERE conversation_id=? AND manager_id=? AND released_at IS NULL')->execute([$conversationId,$managerId]);if(class_exists('MaxSearchApi')){try{MaxSearchApi::setStatus($chatId,MaxSearchApi::$statusAi);}catch(Throwable $ignored){}}ConversationControlService::event($conversationId,'manager_released','manager',$managerId);return true;
    }
    public static function close(int $conversationId,int $managerId): bool
    {
        $pdo=ConversationDb::connection();$row=self::accessibleConversation($conversationId,$managerId);if(!$row||(string)$row['status']==='closed'||(!empty($row['manager_id'])&&(int)$row['manager_id']!==$managerId))return false;$chatId=$row['external_chat_id'];$pdo->prepare('UPDATE conversations SET status=?,closed_at=NOW() WHERE id=?')->execute(['closed',$conversationId]);$pdo->prepare('UPDATE manager_assignments SET released_at=NOW() WHERE conversation_id=? AND released_at IS NULL')->execute([$conversationId]);if(class_exists('MaxSearchApi')){try{MaxSearchApi::deleteAllStatus($chatId);}catch(Throwable $ignored){}}ConversationControlService::event($conversationId,'conversation_closed','manager',$managerId);return true;
    }
    public static function reopen(int $conversationId,int $managerId): bool
    {
        $pdo=ConversationDb::connection();$pdo->beginTransaction();try{$row=self::accessibleConversation($conversationId,$managerId,true);if(!$row||(string)$row['status']!=='closed'){$pdo->rollBack();return false;}$pdo->prepare('UPDATE conversations SET status=?,manager_id=?,closed_at=NULL WHERE id=?')->execute(['manager',$managerId,$conversationId]);$pdo->prepare('INSERT INTO manager_assignments (conversation_id,manager_id,assignment_type) VALUES (?,?,?)')->execute([$conversationId,$managerId,'reopen']);ConversationControlService::event($conversationId,'conversation_reopened','manager',$managerId);$pdo->commit();return true;}catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw$e;}
    }
}
