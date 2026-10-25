<?php
declare(strict_types=1);
require_once __DIR__.'/ManagerSupervisorAccessService.php';
require_once __DIR__.'/InternalOwnerDialogueScope.php';
require_once __DIR__.'/ManagerAiDraftFeedbackReport.php';

/** Owner review of recorded real sends. Never generates, sends or acknowledges chat. */
final class ManagerAiUsageService
{
    public const PAGE_SIZE=20;
    private const BATCH_SIZE=40;
    private const SCAN_LIMIT=1000;

    public static function read(int $actor,array $input,?DateTimeImmutable $now=null): array
    {
        $pdo=null;$started=false;$now=($now??new DateTimeImmutable('now',new DateTimeZone('UTC')))->setTimezone(new DateTimeZone('UTC'));
        try{
            ProjectAccessService::initializeReadOnly();$pdo=ConversationDb::connection();
            if($pdo->inTransaction())return self::error('unavailable');
            $scopes=self::ownerScopes($actor);if(!$scopes)return self::error('forbidden');
            $project=$input['project_key']??'';$mode=$input['mode']??'new';$days=$input['days']??7;$changed=$input['changed_only']??false;
            if(!is_string($project)||strlen($project)>64||!in_array($mode,['new','all'],true)||!in_array($days,[7,30],true)||!is_bool($changed))return self::error('invalid_request');
            $accessRevision=hash('sha256',json_encode($scopes,JSON_THROW_ON_ERROR));
            $filters=[$project,$mode,$days,$changed];$before=null;$end=$now->format('Y-m-d H:i:s');$since=$now->modify('-'.$days.' days')->format('Y-m-d H:i:s');
            if(($input['cursor']??null)!==null){
                $cursor=self::cursor($input['cursor']);
                if(!$cursor||$cursor['actor']!==$actor||$cursor['access']!==$accessRevision||$cursor['filters']!==$filters
                    ||$cursor['end']>$end||strtotime($end.' UTC')-strtotime($cursor['end'].' UTC')>3600
                    ||$cursor['since']!==(new DateTimeImmutable($cursor['end'],new DateTimeZone('UTC')))->modify('-'.$days.' days')->format('Y-m-d H:i:s'))return self::error('stale_cursor');
                $before=$cursor['before'];$since=$cursor['since'];$end=$cursor['end'];
            }
            if($pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'){$pdo->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');$pdo->exec('SET TRANSACTION READ ONLY');}
            $pdo->beginTransaction();$started=true;$items=[];$scanned=0;$more=false;
            while(count($items)<self::PAGE_SIZE&&$scanned<self::SCAN_LIMIT){
                $args=[$actor];$predicate=ManagerSupervisorAccessService::predicate($scopes,'c',$args);
                $where="c.is_test=0 AND NOT ".InternalOwnerDialogueScope::conversationSql('c')." AND {$predicate} AND m.direction='outbound' AND m.sender_type='manager' AND m.created_at>=? AND m.created_at<? AND m.metadata_json LIKE '%\"ai_draft\"%'";
                array_push($args,$since,$end);if($project!==''){$where.=' AND c.project_key=?';$args[]=$project;}if($before!==null){$where.=' AND m.id<?';$args[]=$before;}
                $batch=min(self::BATCH_SIZE,self::SCAN_LIMIT-$scanned);
                $q=$pdo->prepare(self::messageSql()." WHERE {$where} ORDER BY m.id DESC LIMIT ".($batch+1));$q->execute($args);$rows=$q->fetchAll(PDO::FETCH_ASSOC);$q->closeCursor();
                if(!$rows){$more=false;break;}
                $drafts=self::drafts($pdo,$rows);$more=count($rows)>$batch;
                foreach(array_slice($rows,0,$batch) as $index=>$row){
                    $before=(int)$row['id'];++$scanned;
                    if(!ManagerSupervisorAccessService::permits($scopes,$row)||($project!==''&&$row['project_key']!==$project))continue;
                    $draftId=self::draftId($row['metadata_json']);$case=$draftId!==null&&isset($drafts[$draftId])?ManagerAiDraftFeedbackReport::inspectUsage($row,$drafts[$draftId],$row['project_key'],$now):null;
                    if(!$case||($changed&&$case['recorded_text_matches_proposal']&&$case['initial_text_available']))continue;
                    $reviewed=is_string($row['reviewed_revision'])&&hash_equals($row['reviewed_revision'],$case['case_revision']);
                    if($mode==='new'&&$reviewed)continue;
                    $case+=['display_name'=>(string)$row['display_name'],'manager_name'=>(string)$row['manager_name'],'project_key'=>$row['project_key'],
                        'channel'=>$row['channel'],'reviewed'=>$reviewed,'updated_since_review'=>$row['reviewed_revision']!==null&&!$reviewed];
                    $items[]=$case;
                    if(count($items)===self::PAGE_SIZE){$more=$more||$index<count($rows)-1;break;}
                }
                if(count($rows)<=$batch&&!$more)break;
            }
            $limited=$scanned===self::SCAN_LIMIT&&$more;
            $items=self::questions($pdo,$items);
            $pdo->commit();$started=false;
            if($scopes!==self::ownerScopes($actor))return self::error('forbidden');
            $cursor=$more&&$before!==null?'v1.'.base64_encode(json_encode(['actor'=>$actor,'access'=>$accessRevision,'filters'=>$filters,'before'=>$before,'since'=>$since,'end'=>$end],JSON_THROW_ON_ERROR)):null;
            return ['ok'=>true,'viewer_id'=>$actor,'access_revision'=>$accessRevision,'items'=>$items,'has_more'=>$more,'next_cursor'=>$cursor,'scan_limited'=>$limited,
                'as_of'=>$end.'Z','days'=>$days,'projects'=>array_map(static fn($s)=>['key'=>$s['project_key'],'name'=>$s['display_name']],$scopes)];
        }catch(Throwable $ignored){return self::error('unavailable');}
        finally{if($started&&$pdo instanceof PDO&&$pdo->inTransaction())$pdo->rollBack();}
    }

    public static function review(int $actor,int $messageId,string $revision,?DateTimeImmutable $now=null): array
    {
        if($messageId<=0||preg_match('/\A[a-f0-9]{64}\z/D',$revision)!==1)return self::error('invalid_request');
        $pdo=null;$started=false;$now=$now??new DateTimeImmutable('now',new DateTimeZone('UTC'));
        try{
            ProjectAccessService::initializeReadOnly();$pdo=ConversationDb::connection();if($pdo->inTransaction())return self::error('unavailable');
            $pdo->beginTransaction();$started=true;
            $lock=$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'?' FOR UPDATE':'';
            $q=$pdo->prepare('SELECT role,is_active FROM managers WHERE id=?'.$lock);$q->execute([$actor]);$manager=$q->fetch(PDO::FETCH_ASSOC);$q->closeCursor();
            if(!$manager||(int)$manager['is_active']!==1||$manager['role']!=='admin')return self::error('forbidden');
            $scopes=self::ownerScopes($actor);if(!$scopes)return self::error('forbidden');
            $args=[$actor];$predicate=ManagerSupervisorAccessService::predicate($scopes,'c',$args);$args[]=$messageId;
            $q=$pdo->prepare(self::messageSql()." WHERE c.is_test=0 AND NOT ".InternalOwnerDialogueScope::conversationSql('c')." AND {$predicate} AND m.id=? AND m.direction='outbound' AND m.sender_type='manager'".$lock);$q->execute($args);$row=$q->fetch(PDO::FETCH_ASSOC);$q->closeCursor();
            if(!$row||!ManagerSupervisorAccessService::permits($scopes,$row))return self::error('forbidden');
            $draftId=self::draftId($row['metadata_json']);$drafts=self::drafts($pdo,[$row]);
            $case=$draftId!==null&&isset($drafts[$draftId])?ManagerAiDraftFeedbackReport::inspectUsage($row,$drafts[$draftId],$row['project_key'],$now):null;
            if(!$case)return self::error('not_found');if(!hash_equals($case['case_revision'],$revision))return self::error('case_changed');
            if($row['reviewed_revision']!==$revision){
                $sql='INSERT INTO manager_ai_usage_reviews (manager_id,message_id,case_revision,reviewed_at) VALUES (?,?,?,?)';
                $sql.=$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'?' ON DUPLICATE KEY UPDATE case_revision=VALUES(case_revision),reviewed_at=VALUES(reviewed_at)':' ON CONFLICT(manager_id,message_id) DO UPDATE SET case_revision=excluded.case_revision,reviewed_at=excluded.reviewed_at';
                $pdo->prepare($sql)->execute([$actor,$messageId,$revision,$now->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s')]);
            }
            $pdo->commit();$started=false;if($scopes!==self::ownerScopes($actor))return self::error('forbidden');
            return ['ok'=>true,'viewer_id'=>$actor,'message_id'=>$messageId,'case_revision'=>$revision,'reviewed'=>true];
        }catch(Throwable $ignored){return self::error('unavailable');}
        finally{if($started&&$pdo instanceof PDO&&$pdo->inTransaction())$pdo->rollBack();}
    }

    private static function ownerScopes(int $actor): array
    {
        if($actor<=0)return[];
        return array_values(array_filter(ManagerSupervisorAccessService::scopes($actor),static fn($s)=>($s['access_mode']??'')==='owner'));
    }
    private static function messageSql(): string
    {
        return "SELECT m.id,m.conversation_id,m.sender_id,m.sender_type,m.direction,m.channel,m.created_at,SUBSTR(m.text,1,120001) AS text,SUBSTR(m.metadata_json,1,8193) AS metadata_json,
            c.project_key,c.channel AS conversation_channel,c.source_id,COALESCE(cn.working_name,cu.display_name,'Турист') AS display_name,COALESCE(a.display_name,a.login,'Менеджер') AS manager_name,r.case_revision AS reviewed_revision
            FROM messages m JOIN conversations c ON c.id=m.conversation_id JOIN customers cu ON cu.id=c.customer_id
            LEFT JOIN manager_customer_names cn ON cn.customer_id=c.customer_id LEFT JOIN managers a ON a.id=m.sender_id
            LEFT JOIN manager_ai_usage_reviews r ON r.message_id=m.id AND r.manager_id=?";
    }
    private static function draftId($raw): ?string
    {
        if(!is_string($raw)||strlen($raw)>8192)return null;
        $meta=json_decode($raw,true,16);$id=$meta['ai_draft']['draft_id']??null;
        return is_string($id)&&preg_match('/\A[a-f0-9]{32}\z/D',$id)===1?$id:null;
    }
    private static function drafts(PDO $pdo,array $rows): array
    {
        $ids=array_values(array_unique(array_filter(array_map(static fn($r)=>self::draftId($r['metadata_json']),$rows))));if(!$ids)return[];
        $q=$pdo->prepare('SELECT draft_id,conversation_id,manager_id,project_key,context_revision,instruction_revision,latest_message_id,preparation_job_id,SUBSTR(proposal_json,1,32769) AS proposal_json,created_at,insert_requested_at FROM manager_ai_drafts WHERE draft_id IN ('.implode(',',array_fill(0,count($ids),'?')).')');
        $q->execute($ids);$out=[];foreach($q->fetchAll(PDO::FETCH_ASSOC) as $row)$out[$row['draft_id']]=$row;$q->closeCursor();return$out;
    }
    /** Hydrate only the accepted page, retaining each use's own history cutoff. */
    private static function questions(PDO $pdo,array $cases): array
    {
        if(!$cases)return[];
        $targets=[];$args=[];$idType=$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'?'UNSIGNED':'INTEGER';
        foreach($cases as $case){
            $targets[]="SELECT CAST(? AS {$idType}) AS message_id,CAST(? AS {$idType}) AS conversation_id,CAST(? AS {$idType}) AS latest_message_id";
            array_push($args,$case['message_id'],$case['conversation_id'],$case['latest_message_id']);
        }
        $sql='SELECT targets.message_id AS case_message_id,m.id,SUBSTR(m.text,1,8001) AS text,SUBSTR(m.metadata_json,1,8193) AS metadata_json FROM ('.implode(' UNION ALL ',$targets).") targets
            LEFT JOIN messages m ON m.id=(SELECT qm.id FROM messages qm
                WHERE qm.conversation_id=targets.conversation_id AND qm.direction='inbound' AND qm.sender_type='customer'
                    AND qm.id<=targets.latest_message_id ORDER BY qm.id DESC LIMIT 1)";
        $q=$pdo->prepare($sql);$q->execute($args);$rows=[];
        foreach($q->fetchAll(PDO::FETCH_ASSOC) as $row)$rows[(int)$row['case_message_id']]=$row;
        $q->closeCursor();
        foreach($cases as &$case){
            $row=$rows[$case['message_id']]??null;
            $case+=self::questionFields($row&&$row['id']!==null?$row:null,$case['generated_at']);
            unset($case['latest_message_id']);
        }unset($case);
        return$cases;
    }
    private static function questionFields(?array $row,string $generatedAt): array
    {
        if(!$row)return['question_text'=>null,'question_edited'=>false,'question_truncated'=>false];
        $text=htmlspecialchars_decode((string)$row['text'],ENT_QUOTES);if(preg_match('//u',$text)!==1)return['question_text'=>null,'question_edited'=>false,'question_truncated'=>false];
        $meta=json_decode((string)$row['metadata_json'],true,16);$edited=is_array($meta)&&is_string($meta['edited_at']??null)&&$meta['edited_at']>=$generatedAt;
        return['question_text'=>mb_substr($text,0,8000,'UTF-8'),'question_edited'=>$edited,'question_truncated'=>mb_strlen($text,'UTF-8')>8000];
    }
    private static function cursor($encoded): ?array
    {
        if(!is_string($encoded)||strlen($encoded)>2048||!str_starts_with($encoded,'v1.'))return null;
        $raw=base64_decode(substr($encoded,3),true);if($raw===false)return null;$v=json_decode($raw,true,8);
        if(!is_array($v)||count($v)!==6||!is_int($v['actor']??null)||!is_int($v['before']??null)||$v['before']<=0||!is_string($v['access']??null)||!is_array($v['filters']??null))return null;
        foreach(['since','end'] as $key){if(!is_string($v[$key]??null))return null;$d=DateTimeImmutable::createFromFormat('!Y-m-d H:i:s',$v[$key],new DateTimeZone('UTC'));if(!$d||$d->format('Y-m-d H:i:s')!==$v[$key])return null;}
        return$v;
    }
    private static function error(string $error): array{return['ok'=>false,'error'=>$error];}
}
