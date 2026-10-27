<?php
require_once __DIR__ . '/ConversationDb.php';
require_once __DIR__ . '/LeadTaskService.php';
require_once __DIR__ . '/SalesPipelineService.php';
require_once __DIR__ . '/TripSummaryProjection.php';

/** Read-only projection for Manager Workspace V2 inbox cards. */
class ManagerLeadInboxService
{
    public static function decorate(array $rows, int $actor = 0): array
    {
        if (!$rows) return [];
        $ids=array_values(array_unique(array_filter(array_map(static fn($row)=>(int)($row['id']??0),$rows))));
        if(!$ids)return$rows;
        $in=implode(',',array_fill(0,count($ids),'?'));$pdo=ConversationDb::connection();$lead=[];
        $q=$pdo->prepare("SELECT c.id,c.lead_outcome,c.lead_close_reason,c.lead_outcome_note,c.lead_sale_amount,c.lead_sale_date,cu.phone,cu.email FROM conversations c JOIN customers cu ON cu.id=c.customer_id WHERE c.id IN ({$in})");$q->execute($ids);foreach($q->fetchAll() as $item)$lead[(int)$item['id']]=$item;
        $closeReasons=SalesPipelineService::closeReasonOptions();
        $summaries=self::latestSummaries($pdo,$ids);
        // One displayed task and one deadline/count aggregate per conversation.
        // Pinning chooses the card's task; the earliest deadline independently
        // chooses its operational urgency through the existing task owner.
        $tasks=[];$taskRows=[];$openTaskCounts=[];$taskOrder=LeadTaskService::openTaskOrderSql('t');
        $q=$pdo->prepare("SELECT t.id,t.conversation_id,t.title,t.due_at_utc,t.is_pinned,
            CASE WHEN t.due_at_utc IS NOT NULL AND t.due_at_utc<UTC_TIMESTAMP() THEN 1 ELSE 0 END AS overdue,
            totals.open_task_count,totals.first_due_at_utc,
            CASE WHEN totals.first_due_at_utc IS NOT NULL AND totals.first_due_at_utc<UTC_TIMESTAMP() THEN 1 ELSE 0 END AS first_overdue
            FROM (SELECT conversation_id,COUNT(*) AS open_task_count,MIN(due_at_utc) AS first_due_at_utc
                FROM lead_tasks WHERE conversation_id IN ({$in}) AND status='open' GROUP BY conversation_id) totals
            JOIN lead_tasks t ON t.id=(SELECT t.id FROM lead_tasks t WHERE t.conversation_id=totals.conversation_id AND t.status='open' ORDER BY {$taskOrder} LIMIT 1)
            ORDER BY t.conversation_id ASC");$q->execute($ids);
        foreach($q->fetchAll() as $task){$id=(int)$task['conversation_id'];$openTaskCounts[$id]=(int)$task['open_task_count'];$tasks[$id]=$task;$taskRows[$id]=[$task];if($task['first_due_at_utc']!==null)$taskRows[$id][]=['due_at_utc'=>$task['first_due_at_utc'],'overdue'=>$task['first_overdue']];}
        $operationalStates=[];foreach($taskRows as $id=>$rowsForLead)$operationalStates[$id]=LeadTaskService::operationalProjection($rowsForLead);
        foreach($rows as &$row){$id=(int)($row['id']??0);$meta=$lead[$id]??[];$task=$tasks[$id]??[];$operational=$operationalStates[$id]??LeadTaskService::operationalProjection([]);$row['lead_outcome']=(string)($meta['lead_outcome']?:'open');$closedOutcome=$row['lead_outcome']!=='open';$row['lead_close_reason']=$meta['lead_close_reason']??null;$row['lead_close_reason_label']=$row['lead_close_reason']!==null?($closeReasons[(string)$row['lead_close_reason']]??(string)$row['lead_close_reason']):null;$row['lead_outcome_note']=$meta['lead_outcome_note']??null;$row['lead_sale_amount']=$meta['lead_sale_amount']??null;$row['lead_sale_date']=$meta['lead_sale_date']??null;$row['contact_phone']=$meta['phone']??null;$row['contact_email']=$meta['email']??null;$row['trip_summary']=$summaries[$id]??'';$row['next_task_id']=$task['id']??null;$row['next_task_title']=$task['title']??null;$row['next_task_due_at_utc']=$task['due_at_utc']??null;$row['next_task_pinned']=!empty($task['is_pinned']);$row['next_task_overdue']=!empty($task['overdue']);$row['next_task_due_state']=LeadTaskService::dueState($row['next_task_due_at_utc'],$row['next_task_overdue']);$row['open_task_count']=(int)($openTaskCounts[$id]??0);$row['operational_task_state']=$operational['state'];$row['operational_task_due_state']=$closedOutcome?'closed':$operational['due_state'];$row['operational_task_rank']=$closedOutcome?4:$operational['rank'];$row['operational_task_due_at_utc']=$operational['due_at_utc']??null;$row['project_label']=self::projectLabel($row);$row['origin_label']=self::originLabel($row);}unset($row);
        if ($actor>0) {require_once __DIR__.'/ManagerAiInboxStatusService.php';$rows=ManagerAiInboxStatusService::decorate($rows,$actor);}
        return $rows;
    }

    /** Read newest candidates in bounded pages; only unresolved leads continue. */
    private static function latestSummaries(PDO $pdo,array $ids): array
    {
        $summaries=[];$pending=[];$before=null;$batchSize=256;
        $in=implode(',',array_fill(0,count($ids),'?'));
        $candidate="sender_type='ai' AND direction='outbound' AND (text LIKE '%Готово! Проверьте параметры%' OR metadata_json LIKE '%\"trip_summary\"%')";
        $q=$pdo->prepare("SELECT m.id,m.conversation_id,m.text,m.metadata_json FROM messages m
            JOIN (SELECT conversation_id,MAX(id) AS last_id FROM messages WHERE conversation_id IN ({$in}) AND {$candidate} GROUP BY conversation_id) latest
            ON latest.last_id=m.id AND latest.conversation_id=m.conversation_id");$q->execute($ids);
        foreach($q->fetchAll() as $message){
            $id=(int)$message['conversation_id'];$summary=TripSummaryProjection::fromMessage($message);
            if($summary!==null)$summaries[$id]=$summary;else$pending[$id]=true;
        }
        $q->closeCursor();
        // Unsupported/malformed latest candidates still fall back through the
        // canonical parser, including a newer legacy or valid empty snapshot.
        while($pending){
            $args=array_keys($pending);$in=implode(',',array_fill(0,count($args),'?'));
            $cursor=$before===null?'':' AND id<?';if($before!==null)$args[]=$before;
            $q=$pdo->prepare("SELECT id,conversation_id,text,metadata_json FROM messages WHERE conversation_id IN ({$in}) AND {$candidate}{$cursor} ORDER BY id DESC LIMIT {$batchSize}");$q->execute($args);
            $messages=$q->fetchAll();$q->closeCursor();
            foreach($messages as $message){
                $before=(int)$message['id'];$id=(int)$message['conversation_id'];
                if(!isset($pending[$id]))continue;
                $summary=TripSummaryProjection::fromMessage($message);
                if($summary!==null){$summaries[$id]=$summary;unset($pending[$id]);}
            }
            if(count($messages)<$batchSize)break;
        }
        return$summaries;
    }

    /** Compatibility projection; LeadTaskService owns task urgency semantics. */
    public static function taskDueState($dueAtUtc,bool $overdue=false,?DateTimeImmutable $nowUtc=null): string
    {
        return LeadTaskService::dueState($dueAtUtc,$overdue,$nowUtc);
    }

    public static function sortOperational(array $rows): array
    {
        if(count($rows)<2)return$rows;
        $indexed=[];foreach($rows as $index=>$row)$indexed[]=['row'=>$row,'index'=>$index];
        usort($indexed,static function(array $a,array $b):int{
            $aRow=$a['row'];$bRow=$b['row'];
            $aRank=isset($aRow['operational_task_rank'])?(int)$aRow['operational_task_rank']:LeadTaskService::operationalRank((string)($aRow['next_task_due_state']??''),trim((string)($aRow['next_task_title']??''))!=='');
            $bRank=isset($bRow['operational_task_rank'])?(int)$bRow['operational_task_rank']:LeadTaskService::operationalRank((string)($bRow['next_task_due_state']??''),trim((string)($bRow['next_task_title']??''))!=='');
            if($aRank!==$bRank)return $aRank<=>$bRank;
            $aDue=trim((string)($aRow['operational_task_due_at_utc']??$aRow['next_task_due_at_utc']??''));$bDue=trim((string)($bRow['operational_task_due_at_utc']??$bRow['next_task_due_at_utc']??''));
            if($aDue!==$bDue){if($aDue==='')return 1;if($bDue==='')return -1;return strcmp($aDue,$bDue);}
            return (int)$a['index']<=>(int)$b['index'];
        });
        return array_map(static fn($item)=>$item['row'],$indexed);
    }

    /** Keep task urgency within each group, with unanswered owned conversations first. */
    public static function sortMine(array $rows): array
    {
        $pending=[];$other=[];
        foreach(self::sortOperational($rows) as $row){
            if(!empty($row['awaiting_manager_reply']))$pending[]=$row;else $other[]=$row;
        }
        return array_merge($pending,$other);
    }

    public static function filter(array $rows,string $outcome='',string $search='',string $taskFilter=''): array
    {
        $outcome=trim($outcome);if(!in_array($outcome,['','open','won','lost'],true))$outcome='';$search=trim($search);$taskFilter=trim($taskFilter);if(!in_array($taskFilter,['','action','overdue','today','planned','pinned','none'],true))$taskFilter='';
        $filtered=array_values(array_filter($rows,static function(array $row)use($outcome,$search,$taskFilter):bool{
            if($outcome!==''&&(string)($row['lead_outcome']??'open')!==$outcome)return false;
            $hasTask=(int)($row['open_task_count']??0)>0||trim((string)($row['next_task_title']??''))!=='';$overdue=!empty($row['next_task_overdue']);$pinned=!empty($row['next_task_pinned']);$dueState=(string)($row['next_task_due_state']??LeadTaskService::dueState($row['next_task_due_at_utc']??null,$overdue));$operationalDueState=(string)($row['operational_task_due_state']??$dueState);
            if($taskFilter==='action'&&(!$hasTask||!in_array($operationalDueState,['overdue','today'],true)))return false;if($taskFilter==='overdue'&&(!$hasTask||$operationalDueState!=='overdue'))return false;if($taskFilter==='today'&&(!$hasTask||$operationalDueState!=='today'))return false;if($taskFilter==='planned'&&(!$hasTask||$operationalDueState!=='upcoming'))return false;if($taskFilter==='pinned'&&(!$hasTask||!$pinned))return false;if($taskFilter==='none'&&($hasTask||(string)($row['lead_outcome']??'open')!=='open'))return false;
            if($search==='')return true;
            $haystack=implode(' ',array_filter([$row['display_name']??'',$row['original_display_name']??'',$row['working_name']??'',$row['contact_phone']??'',$row['contact_email']??'',$row['project_label']??'',$row['origin_label']??'',$row['manager_name']??'',$row['last_text']??'',$row['trip_summary']??'',$row['next_task_title']??'',$row['lead_close_reason']??'',$row['lead_close_reason_label']??'',$row['lead_outcome_note']??'',$row['lead_sale_amount']??'',$row['lead_sale_date']??''],static fn($v)=>$v!==null&&$v!==''));
            return function_exists('mb_stripos')?mb_stripos($haystack,$search,0,'UTF-8')!==false:stripos($haystack,$search)!==false;
        }));
        return $taskFilter===''?$filtered:self::sortOperational($filtered);
    }

    public static function projectLabel(array $row): string
    {
        $project=trim((string)($row['project_name']??''));
        if($project!=='')return$project;
        return trim((string)($row['project_key']??''));
    }

    /** Canonical human-readable origin used across Manager Workspace V2 surfaces. */
    public static function originLabel(array $row): string
    {
        $channelRaw=strtolower(trim((string)($row['channel']??'')));
        $channel=$channelRaw==='telegram'?'TG':strtoupper($channelRaw);
        $entry=trim((string)($row['entry_channel']??''));
        if($entry!=='')return trim($channel.($channel!==''?' · ':'').$entry);
        $hasSourceId=array_key_exists('source_id',$row);
        $sourceId=$hasSourceId?(int)($row['source_id']??0):null;
        if($hasSourceId&&$sourceId<=0){$unknown='⚠ Источник не определён';return trim($channel.($channel!==''?' · ':'').$unknown);}
        $source=trim((string)($row['source_name']??''));if($source!==''&&strpos($source,':')!==false){[, $short]=explode(':',$source,2);if(trim($short)!=='')$source=trim($short);}if($source==='')$source=self::projectLabel($row);return trim($channel.($channel!==''&&$source!==''?' · ':'').$source);
    }
}
