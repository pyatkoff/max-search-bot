<?php
require_once __DIR__ . '/ConversationDb.php';
require_once __DIR__ . '/LeadTaskService.php';

/** Read-only projection for Manager Workspace V2 inbox cards. */
class ManagerLeadInboxService
{
    public static function decorate(array $rows): array
    {
        if (!$rows) return [];
        $ids=array_values(array_unique(array_filter(array_map(static fn($row)=>(int)($row['id']??0),$rows))));
        if(!$ids)return$rows;
        $in=implode(',',array_fill(0,count($ids),'?'));$pdo=ConversationDb::connection();$lead=[];
        $q=$pdo->prepare("SELECT c.id,c.lead_outcome,c.lead_sale_amount,c.lead_sale_date,cu.phone,cu.email FROM conversations c JOIN customers cu ON cu.id=c.customer_id WHERE c.id IN ({$in})");$q->execute($ids);foreach($q->fetchAll() as $item)$lead[(int)$item['id']]=$item;
        $summaries=[];$q=$pdo->prepare("SELECT conversation_id,text FROM messages WHERE conversation_id IN ({$in}) AND sender_type='ai' AND direction='outbound' AND text LIKE '%Готово! Проверьте параметры%' ORDER BY id DESC");$q->execute($ids);
        foreach($q->fetchAll() as $message){$id=(int)($message['conversation_id']??0);if($id<=0||array_key_exists($id,$summaries))continue;$summaries[$id]=self::cleanTripSummary((string)($message['text']??''));}
        $tasks=[];$operationalStates=[];$openTaskCounts=[];$taskOrder=LeadTaskService::openTaskOrderSql('t');$q=$pdo->prepare("SELECT t.id,t.conversation_id,t.title,t.due_at_utc,t.is_pinned,CASE WHEN t.due_at_utc IS NOT NULL AND t.due_at_utc<UTC_TIMESTAMP() THEN 1 ELSE 0 END AS overdue FROM lead_tasks t WHERE t.conversation_id IN ({$in}) AND t.status='open' ORDER BY t.conversation_id ASC,{$taskOrder}");$q->execute($ids);
        foreach($q->fetchAll() as $task){$id=(int)($task['conversation_id']??0);if($id<=0)continue;$openTaskCounts[$id]=(int)($openTaskCounts[$id]??0)+1;if(!array_key_exists($id,$tasks))$tasks[$id]=$task;$state=LeadTaskService::dueState($task['due_at_utc']??null,!empty($task['overdue']));$rank=LeadTaskService::operationalRank($state,true);$due=trim((string)($task['due_at_utc']??''));$current=$operationalStates[$id]??null;$replace=$current===null||$rank<(int)$current['rank']||($rank===(int)$current['rank']&&$due!==''&&(empty($current['due_at_utc'])||strcmp($due,(string)$current['due_at_utc'])<0));if($replace)$operationalStates[$id]=['rank'=>$rank,'state'=>LeadTaskService::operationalState($state,true),'due_state'=>$state,'due_at_utc'=>$due!==''?$due:null];}
        foreach($rows as &$row){$id=(int)($row['id']??0);$meta=$lead[$id]??[];$task=$tasks[$id]??[];$operational=$operationalStates[$id]??['rank'=>LeadTaskService::operationalRank('none',false),'state'=>LeadTaskService::operationalState('none',false),'due_state'=>'none','due_at_utc'=>null];$row['lead_outcome']=(string)($meta['lead_outcome']?:'open');$row['lead_sale_amount']=$meta['lead_sale_amount']??null;$row['lead_sale_date']=$meta['lead_sale_date']??null;$row['contact_phone']=$meta['phone']??null;$row['contact_email']=$meta['email']??null;$row['trip_summary']=$summaries[$id]??'';$row['next_task_id']=$task['id']??null;$row['next_task_title']=$task['title']??null;$row['next_task_due_at_utc']=$task['due_at_utc']??null;$row['next_task_pinned']=!empty($task['is_pinned']);$row['next_task_overdue']=!empty($task['overdue']);$row['next_task_due_state']=LeadTaskService::dueState($row['next_task_due_at_utc'],$row['next_task_overdue']);$row['open_task_count']=(int)($openTaskCounts[$id]??0);$row['operational_task_state']=$operational['state'];$row['operational_task_due_state']=$operational['due_state'];$row['operational_task_rank']=$operational['rank'];$row['operational_task_due_at_utc']=$operational['due_at_utc']??null;$row['project_label']=self::projectLabel($row);$row['origin_label']=self::originLabel($row);}unset($row);return$rows;
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

    public static function filter(array $rows,string $outcome='',string $search='',string $taskFilter=''): array
    {
        $outcome=trim($outcome);if(!in_array($outcome,['','open','won','lost'],true))$outcome='';$search=trim($search);$taskFilter=trim($taskFilter);if(!in_array($taskFilter,['','action','overdue','today','planned','pinned','none'],true))$taskFilter='';
        return array_values(array_filter($rows,static function(array $row)use($outcome,$search,$taskFilter):bool{
            if($outcome!==''&&(string)($row['lead_outcome']??'open')!==$outcome)return false;
            $hasTask=(int)($row['open_task_count']??0)>0||trim((string)($row['next_task_title']??''))!=='';$overdue=!empty($row['next_task_overdue']);$pinned=!empty($row['next_task_pinned']);$dueState=(string)($row['next_task_due_state']??LeadTaskService::dueState($row['next_task_due_at_utc']??null,$overdue));$operationalDueState=(string)($row['operational_task_due_state']??$dueState);
            if($taskFilter==='action'&&(!$hasTask||!in_array($operationalDueState,['overdue','today'],true)))return false;if($taskFilter==='overdue'&&(!$hasTask||$operationalDueState!=='overdue'))return false;if($taskFilter==='today'&&(!$hasTask||$operationalDueState!=='today'))return false;if($taskFilter==='planned'&&(!$hasTask||$operationalDueState!=='upcoming'))return false;if($taskFilter==='pinned'&&(!$hasTask||!$pinned))return false;if($taskFilter==='none'&&$hasTask)return false;
            if($search==='')return true;
            $haystack=implode(' ',array_filter([$row['display_name']??'',$row['contact_phone']??'',$row['contact_email']??'',$row['project_label']??'',$row['origin_label']??'',$row['manager_name']??'',$row['last_text']??'',$row['trip_summary']??'',$row['next_task_title']??'',$row['lead_sale_amount']??'',$row['lead_sale_date']??''],static fn($v)=>$v!==null&&$v!==''));
            return function_exists('mb_stripos')?mb_stripos($haystack,$search,0,'UTF-8')!==false:stripos($haystack,$search)!==false;
        }));
    }

    private static function cleanTripSummary(string $text): string
    {
        $text=html_entity_decode(strip_tags($text),ENT_QUOTES|ENT_HTML5,'UTF-8');$text=preg_replace('/^\s*✅\s*Готово!\s*Проверьте параметры\s*/ui','',$text)??$text;$text=preg_replace('/\s*Что удобнее дальше\?\s*$/ui','',$text)??$text;$text=preg_replace('/\s+/u',' ',$text)??$text;return trim($text);
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
        $channel=strtoupper(trim((string)($row['channel']??'')));
        $hasSourceId=array_key_exists('source_id',$row);
        $sourceId=$hasSourceId?(int)($row['source_id']??0):null;
        if($hasSourceId&&$sourceId<=0){$unknown='⚠ Источник не определён';return trim($channel.($channel!==''?' · ':'').$unknown);}
        $source=trim((string)($row['source_name']??''));if($source!==''&&strpos($source,':')!==false){[, $short]=explode(':',$source,2);if(trim($short)!=='')$source=trim($short);}if($source==='')$source=self::projectLabel($row);return trim($channel.($channel!==''&&$source!==''?' · ':'').$source);
    }
}
