<?php
declare(strict_types=1);
require_once __DIR__.'/ManagerSupervisorAccessService.php';
require_once __DIR__.'/InternalOwnerDialogueScope.php';

/** Full server-side read model, independent of Inbox/board caps and model work. */
final class ManagerDialogueAnalyticsService
{
    public const PAGE_SIZE=50;

    public static function read(int $actor,array $filters): array
    {
        $pdo=null;$started=false;
        try {
            ProjectAccessService::initializeReadOnly();
            $pdo=ConversationDb::connection();if($pdo->inTransaction())return ['ok'=>false,'error'=>'analytics_unavailable'];
            if($pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'){$pdo->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');$pdo->exec('SET TRANSACTION READ ONLY');}
            $pdo->beginTransaction();$started=true;
            $scopes=ManagerSupervisorAccessService::scopes($actor);
            if(!$scopes)return ['ok'=>false,'error'=>'forbidden'];
            $from=self::date($filters['from']??null);$to=self::date($filters['to']??null);
            if(!$from||!$to||$to<$from||$to->getTimestamp()-$from->getTimestamp()>92*86400)return ['ok'=>false,'error'=>'invalid_period'];
            $end=$to->modify('+1 day');$startUtc=$from->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');$endUtc=$end->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
            $mode=$filters['mode']??'started';$metric=$filters['metric']??'all';
            if(!in_array($mode,['started','requested','active'],true)||!in_array($metric,['all','requested','replied','continued','waiting'],true))return ['ok'=>false,'error'=>'invalid_request'];
            $manager=self::integer($filters['manager_id']??0);$source=self::integer($filters['source_id']??0);$cursor=self::integer($filters['cursor']??0);
            $project=$filters['project_key']??'';$channel=$filters['channel']??'';
            if($manager===null||$source===null||$cursor===null||!is_string($project)||!is_string($channel)||!in_array($channel,['','max','telegram','website'],true))return ['ok'=>false,'error'=>'invalid_request'];
            $args=[];$access=ManagerSupervisorAccessService::predicate($scopes,'c',$args);
            $where=['c.is_test=0','NOT '.InternalOwnerDialogueScope::conversationSql('c'),$access];
            if($project!==''){$where[]='c.project_key=?';$args[]=$project;}
            if($source>0){$where[]='c.source_id=?';$args[]=$source;}
            if($channel!==''){$where[]='c.channel=?';$args[]=$channel;}
            if($manager>0){$where[]='c.manager_id=?';$args[]=$manager;}
            $pdo=ConversationDb::connection();$mysql=$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql';
            $baseWhere=implode(' AND ',$where);$baseArgs=$args;
            $request="(SELECT MIN(r.created_at) FROM conversation_events r WHERE r.conversation_id=c.id AND r.event_type='waiting_manager')";
            if($mode==='started'){$where[]='c.started_at>=? AND c.started_at<?';array_push($args,$startUtc,$endUtc);}
            elseif($mode==='requested'){$where[]=$request.'>=? AND '.$request.'<?';array_push($args,$startUtc,$endUtc);}
            else $where[]="c.status IN ('waiting_manager','manager')";
            $human=self::humanSql('mc',$mysql);
            $reply="(SELECT mr.id FROM messages mr WHERE mr.conversation_id=c.id AND mr.direction='outbound' AND mr.sender_type='manager' AND mr.created_at>=$request ORDER BY mr.created_at,mr.id LIMIT 1)";
            $continuation="EXISTS(SELECT 1 FROM messages mc WHERE mc.conversation_id=c.id AND mc.id>fr.id AND mc.created_at>=fr.created_at AND mc.direction='inbound' AND mc.sender_type='customer' AND $human)";
            $latency=$mysql?'TIMESTAMPDIFF(SECOND,'.$request.',fr.created_at)':"(CAST(strftime('%s',fr.created_at) AS INTEGER)-CAST(strftime('%s',$request) AS INTEGER))";
            $ready="EXISTS(SELECT 1 FROM manager_prepared_replies pr WHERE pr.conversation_id=c.id AND pr.project_key=c.project_key AND pr.manager_id=c.manager_id AND pr.status='ready' AND pr.instruction_revision=? AND pr.created_at<=? AND pr.prepared_at<=? AND pr.expires_at>? AND pr.latest_message_id=(SELECT MAX(lm.id) FROM messages lm WHERE lm.conversation_id=c.id))";
            $now=gmdate('Y-m-d H:i:s');
            require_once __DIR__.'/ManagerAiDraftService.php';
            $prefix=[hash('sha256',ManagerAiDraftService::instructions()),$now,$now,$now];
            $lastRequest="(SELECT MAX(lr.created_at) FROM conversation_events lr WHERE lr.conversation_id=c.id AND lr.event_type='waiting_manager')";
            $lastCustomer="(SELECT MAX(im.id) FROM messages im WHERE im.conversation_id=c.id AND im.direction='inbound' AND im.sender_type='customer' AND ".self::humanSql('im',$mysql).")";
            $lastReply="(SELECT MAX(om.id) FROM messages om WHERE om.conversation_id=c.id AND om.direction='outbound' AND om.sender_type='manager')";
            $waiting="(c.status='waiting_manager' OR (c.status='manager' AND (($lastRequest IS NOT NULL AND NOT EXISTS(SELECT 1 FROM messages ar WHERE ar.conversation_id=c.id AND ar.direction='outbound' AND ar.sender_type='manager' AND ar.created_at>=$lastRequest)) OR COALESCE($lastCustomer,0)>COALESCE($lastReply,0))))";
            $base="SELECT c.id,c.project_key,c.source_id,c.channel,c.manager_id,c.status,c.started_at,COALESCE(cn.working_name,cu.display_name,'Турист') AS display_name,COALESCE(m.display_name,m.login,'Без назначения') AS manager_name,$request AS requested_at,fr.created_at AS first_reply_at,fr.sender_id AS first_reply_manager_id,CASE WHEN fr.id IS NOT NULL THEN $latency END AS first_reply_seconds,CASE WHEN $continuation THEN 1 ELSE 0 END AS continued,CASE WHEN $waiting THEN 1 ELSE 0 END AS waiting,CASE WHEN $ready THEN 1 ELSE 0 END AS ready_candidate,CASE WHEN c.lead_outcome_updated_at IS NOT NULL AND c.lead_outcome IN ('won','lost') THEN c.lead_outcome END AS outcome,CASE WHEN EXISTS(SELECT 1 FROM lead_stage_history sh WHERE sh.conversation_id=c.id AND sh.to_stage_key=c.lead_stage_key) THEN c.lead_stage_key END AS recorded_stage FROM conversations c JOIN customers cu ON cu.id=c.customer_id LEFT JOIN manager_customer_names cn ON cn.customer_id=c.customer_id LEFT JOIN managers m ON m.id=c.manager_id LEFT JOIN messages fr ON fr.id=$reply WHERE ".implode(' AND ',$where);
            $params=array_merge($prefix,$args);
            $aggregate="COUNT(*) AS conversations,SUM(CASE WHEN manager_id IS NOT NULL THEN 1 ELSE 0 END) AS assigned,SUM(CASE WHEN requested_at IS NOT NULL THEN 1 ELSE 0 END) AS requested,SUM(CASE WHEN first_reply_at IS NOT NULL THEN 1 ELSE 0 END) AS replied,SUM(continued) AS continued,SUM(waiting) AS waiting,SUM(ready_candidate) AS ready_candidates,SUM(CASE WHEN outcome='won' THEN 1 ELSE 0 END) AS won,SUM(CASE WHEN outcome='lost' THEN 1 ELSE 0 END) AS lost,SUM(CASE WHEN recorded_stage IS NOT NULL THEN 1 ELSE 0 END) AS recorded_stage,SUM(CASE WHEN first_reply_seconds IS NOT NULL THEN 1 ELSE 0 END) AS reply_n,AVG(first_reply_seconds) AS reply_mean_seconds";
            $totals=self::one($pdo,"SELECT $aggregate FROM ($base) a",$params);
            foreach($totals as $key=>$value)$totals[$key]=$key==='reply_mean_seconds'?($value===null?null:(float)$value):(int)$value;
            $byManager=self::all($pdo,"SELECT manager_id,manager_name,$aggregate FROM ($base) a GROUP BY manager_id,manager_name ORDER BY conversations DESC,manager_id",$params);
            $n=$totals['reply_n'];$percentiles=['n'=>$n,'p50_seconds'=>null,'p95_seconds'=>null];
            if($n>0)foreach(['p50_seconds'=>.5,'p95_seconds'=>.95] as $key=>$p){$offset=max(0,(int)ceil($n*$p)-1);$row=self::one($pdo,"SELECT first_reply_seconds FROM ($base) a WHERE first_reply_seconds IS NOT NULL ORDER BY first_reply_seconds,id LIMIT 1 OFFSET $offset",$params);$percentiles[$key]=(int)$row['first_reply_seconds'];}
            $metricWhere=match($metric){'requested'=>'requested_at IS NOT NULL','replied'=>'first_reply_at IS NOT NULL','continued'=>'continued=1','waiting'=>'waiting=1',default=>'1=1'};
            $pageArgs=$params;$pageWhere=$metricWhere;
            if($cursor>0){$pageWhere.=' AND id<?';$pageArgs[]=$cursor;}
            $rows=self::all($pdo,"SELECT * FROM ($base) a WHERE $pageWhere ORDER BY id DESC LIMIT ".(self::PAGE_SIZE+1),$pageArgs);
            $more=count($rows)>self::PAGE_SIZE;if($more)array_pop($rows);
            foreach($rows as &$row){foreach(['id','source_id','manager_id','continued','waiting','ready_candidate'] as $key)$row[$key]=$row[$key]===null?null:(int)$row[$key];$row['first_reply_seconds']=$row['first_reply_seconds']===null?null:(int)$row['first_reply_seconds'];}unset($row);
            // Period events use the same allowed population, not the started-cohort denominator.
            $eventArgs=array_merge($baseArgs,[$startUtc,$endUtc]);
            $requests=self::one($pdo,"SELECT COUNT(*) AS n FROM conversation_events e JOIN conversations c ON c.id=e.conversation_id WHERE $baseWhere AND e.event_type='waiting_manager' AND e.created_at>=? AND e.created_at<?",$eventArgs);
            $assignments=self::one($pdo,"SELECT COUNT(*) AS n FROM manager_assignments ma JOIN conversations c ON c.id=ma.conversation_id WHERE $baseWhere AND ma.assigned_at>=? AND ma.assigned_at<?",$eventArgs);
            $replies=self::one($pdo,"SELECT COUNT(*) AS n,COUNT(DISTINCT c.id) AS conversations FROM messages mr JOIN conversations c ON c.id=mr.conversation_id WHERE $baseWhere AND mr.direction='outbound' AND mr.sender_type='manager' AND mr.created_at>=? AND mr.created_at<?",$eventArgs);
            $failures=self::one($pdo,"SELECT COUNT(*) AS n FROM conversation_events e JOIN conversations c ON c.id=e.conversation_id WHERE $baseWhere AND e.event_type='manager_message_failed' AND e.created_at>=? AND e.created_at<?",$eventArgs);
            $stageRows=self::all($pdo,"SELECT recorded_stage AS stage,COUNT(*) AS n FROM ($base) a WHERE recorded_stage IS NOT NULL GROUP BY recorded_stage ORDER BY recorded_stage",$params);
            $pdo->commit();$started=false;
            // Do not return an earlier permission snapshot after revoke/account/project/source changes.
            if($scopes!==ManagerSupervisorAccessService::scopes($actor))return ['ok'=>false,'error'=>'forbidden'];
            return ['ok'=>true,'access_revision'=>hash('sha256',json_encode($scopes,JSON_THROW_ON_ERROR)),'as_of'=>$now.'Z','timezone'=>'Europe/Moscow','period'=>['from'=>$filters['from'],'to'=>$filters['to'],'start_utc'=>$startUtc,'end_exclusive_utc'=>$endUtc],
                'mode'=>$mode,'metric'=>$metric,'totals'=>$totals,'first_reply'=>$percentiles,'by_manager'=>$byManager,
                'period_events'=>['requests'=>(int)$requests['n'],'assignments'=>(int)$assignments['n'],'reply_messages'=>(int)$replies['n'],'reply_conversations'=>(int)$replies['conversations'],'delivery_failures'=>(int)$failures['n']],
                'stages'=>$stageRows,'coverage'=>['population'=>'all_authorized_non_test_conversations','ai'=>'latest_ttl_instruction_candidates_only_full_revision_checked_on_open','verified_fresh_ai'=>null],
                'rows'=>$rows,'has_more'=>$more,'next_cursor'=>$more&&$rows?(int)$rows[count($rows)-1]['id']:null,
                'definitions'=>['attribution'=>'current_assigned_manager; first reply sender returned separately; transfers are not retroactively attributed to former managers',
                    'first_reply'=>'first recorded successful manager message at or after the first recorded manager request, lifetime of selected conversation; repeated requests are separate period events, not new conversations',
                    'cohort'=>'started/requested select the first such timestamp in period; active selects current waiting/manager state regardless of period',
                    'sales'=>'only explicit won/lost outcomes and stages present in canonical history; defaults and replies are not sales']];
        }catch(Throwable $ignored){return ['ok'=>false,'error'=>'analytics_unavailable'];}
        finally{if($started&&$pdo instanceof PDO&&$pdo->inTransaction())$pdo->rollBack();}
    }

    private static function date($v): ?DateTimeImmutable
    {
        if(!is_string($v)||!preg_match('/\A\d{4}-\d{2}-\d{2}\z/D',$v))return null;
        $d=DateTimeImmutable::createFromFormat('!Y-m-d',$v,new DateTimeZone('Europe/Moscow'));
        return $d&&$d->format('Y-m-d')===$v?$d:null;
    }
    private static function integer($v): ?int {return (is_int($v)||(is_string($v)&&preg_match('/\A(?:0|[1-9][0-9]*)\z/D',$v)))&&filter_var($v,FILTER_VALIDATE_INT,['options'=>['min_range'=>0]])!==false?(int)$v:null;}
    private static function humanSql(string $alias,bool $mysql): string
    {
        $json="CASE WHEN JSON_VALID($alias.metadata_json) THEN $alias.metadata_json ELSE '{}' END";
        $type=$mysql?"JSON_UNQUOTE(JSON_EXTRACT($json,'$.type'))":"JSON_EXTRACT($json,'$.type')";
        return "COALESCE($type,'message') NOT IN ('callback','start','bot_started')";
    }
    private static function all(PDO $p,string $sql,array $args): array {$q=$p->prepare($sql);$q->execute($args);return $q->fetchAll(PDO::FETCH_ASSOC);}
    private static function one(PDO $p,string $sql,array $args): array {return self::all($p,$sql,$args)[0]??[];}
}
