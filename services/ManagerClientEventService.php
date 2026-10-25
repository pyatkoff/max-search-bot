<?php
declare(strict_types=1);

require_once __DIR__.'/ManagerConversationService.php';

/** Read-only view of existing records. Never a second event or audit store. */
final class ManagerClientEventService
{
    private const SOURCES = ['task'=>1,'note'=>2,'name'=>3,'stage'=>4,'event'=>5];
    private const EVENTS = [
        'waiting_manager'=>'Клиент запросил менеджера',
        'ai_resumed'=>'Диалог возвращён помощнику',
        'manager_taken'=>'Менеджер принял диалог',
        'manager_reassigned'=>'Ответственный менеджер изменён',
        'manager_released'=>'Менеджер освободил диалог',
        'conversation_closed'=>'Диалог закрыт',
        'conversation_reopened'=>'Диалог открыт повторно',
        'manager_message'=>'Ответ менеджера записан в переписку',
        'manager_message_failed'=>'Зафиксирована ошибка отправки ответа',
        'manager_message_edited'=>'Ответ менеджера изменён',
        'channel_post_entry'=>'Переход из публикации',
    ];

    public static function list(int $actor, int $conversationId, array $input=[]): array
    {
        $limit=$input['page_size']??30;
        if (!is_int($limit)||$limit<1||$limit>50) return self::error('invalid_page');
        if ($conversationId<=0) return self::error('invalid_conversation_id');
        $manager=ManagerAuthService::byId($actor);
        if (!$manager||empty($manager['is_active'])) return self::error('unauthorized');
        // The ordinary visibility boundary also protects notes and working-name
        // history. Extended supervision alone must not expose those records.
        $conversation=ManagerConversationService::visibleConversation($conversationId,$actor);
        if ($conversation===null) return self::error('forbidden');
        $cursor=self::cursor(array_key_exists('cursor',$input)?$input['cursor']:'', $actor, $conversationId);
        if ($cursor===false) return self::error('invalid_cursor');
        $pdo=ConversationDb::connection();
        $sources=self::sources($conversationId,(int)$conversation['customer_id']);
        $ceilings=$cursor['ceilings']??self::ceilings($pdo,$sources);
        $rows=[];
        foreach ($sources as $kind=>$source) {
            $rank=self::SOURCES[$kind];
            $where=$source['where'].' AND '.$source['id'].'<=?';
            $params=array_merge($source['params'],[$ceilings[$kind]]);
            if ($cursor!==null) {
                // Stable ties across source tables, without OFFSET. High-water
                // IDs exclude newer inserts even when their time is backdated.
                if ($rank<$cursor['rank']) {$where.=' AND '.$source['time'].'<=?';$params[]=$cursor['at'];}
                elseif ($rank>$cursor['rank']) {$where.=' AND '.$source['time'].'<?';$params[]=$cursor['at'];}
                else {$where.=' AND ('.$source['time'].'<? OR ('.$source['time'].'=? AND '.$source['id'].'<?))';array_push($params,$cursor['at'],$cursor['at'],$cursor['id']);}
            }
            $q=$pdo->prepare($source['select'].' WHERE '.$where.' ORDER BY '.$source['time'].' DESC,'.$source['id'].' DESC LIMIT '.($limit+1));
            $q->execute($params);
            foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $row['_kind']=$kind;$row['_rank']=$rank;$rows[]=$row;
            }
        }
        usort($rows,static fn(array $a,array $b):int=>strcmp((string)$b['created_at'],(string)$a['created_at'])?:($b['_rank']<=>$a['_rank'])?:((int)$b['id']<=>(int)$a['id']));
        $hasMore=count($rows)>$limit;$rows=array_slice($rows,0,$limit);
        // A lazy secondary read can outlive a role/source/assignment change.
        // Recheck after the bounded source queries, before releasing any text.
        $manager=ManagerAuthService::byId($actor);
        if (!$manager||empty($manager['is_active'])) return self::error('unauthorized');
        if (ManagerConversationService::visibleConversation($conversationId,$actor)===null) return self::error('forbidden');
        $names=[];
        if (($manager['role']??'')==='admin') {
            $ids=[];
            foreach ($rows as $row) if (($row['actor_type']??'manager')==='manager'&&preg_match('/^[1-9][0-9]*$/D',(string)($row['actor_id']??''))) $ids[]=(int)$row['actor_id'];
            $ids=array_values(array_unique($ids));
            if ($ids) {
                $q=$pdo->prepare('SELECT id,display_name,login FROM managers WHERE id IN ('.implode(',',array_fill(0,count($ids),'?')).')');$q->execute($ids);
                foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $name) $names[(int)$name['id']]=(string)($name['display_name']?:$name['login']?:'Менеджер');
            }
        }
        $last=$rows?end($rows):null;
        $next=$hasMore&&$last!==null?self::encode(['v'=>1,'actor'=>$actor,'conversation'=>$conversationId,'ceilings'=>$ceilings,'at'=>$last['created_at'],'rank'=>$last['_rank'],'id'=>(int)$last['id']]):'';
        return ['ok'=>true,'conversation_id'=>$conversationId,'items'=>array_map(static fn(array $row):array=>self::view($row,$actor,$names),$rows),'history'=>['has_more'=>$hasMore,'cursor'=>$next,'page_size'=>$limit]];
    }

    private static function sources(int $conversationId,int $customerId): array
    {
        $types=implode(',',array_map(static fn(string $type):string=>"'".$type."'",array_keys(self::EVENTS)));
        return [
            'event'=>['select'=>'SELECT e.id,e.created_at,e.actor_type,e.actor_id,e.event_type FROM conversation_events e','where'=>'e.conversation_id=? AND e.event_type IN ('.$types.')','params'=>[$conversationId],'id'=>'e.id','time'=>'e.created_at','from'=>'conversation_events e'],
            'stage'=>['select'=>'SELECT h.id,h.created_at,h.changed_by_manager_id AS actor_id,h.from_stage_key,h.to_stage_key,fs.display_name AS from_name,ts.display_name AS to_name FROM lead_stage_history h LEFT JOIN lead_stages fs ON fs.stage_key=h.from_stage_key LEFT JOIN lead_stages ts ON ts.stage_key=h.to_stage_key','where'=>'h.conversation_id=?','params'=>[$conversationId],'id'=>'h.id','time'=>'h.created_at','from'=>'lead_stage_history h'],
            'name'=>['select'=>'SELECT h.version AS id,h.created_at,h.actor_manager_id AS actor_id,h.before_name,h.after_name FROM manager_customer_name_history h','where'=>'h.customer_id=? AND h.conversation_id=?','params'=>[$customerId,$conversationId],'id'=>'h.version','time'=>'h.created_at','from'=>'manager_customer_name_history h'],
            'note'=>['select'=>'SELECT n.id,n.created_at,n.author_manager_id AS actor_id,n.body,n.updated_at,n.deleted_at FROM manager_internal_notes n','where'=>'n.conversation_id=?','params'=>[$conversationId],'id'=>'n.id','time'=>'n.created_at','from'=>'manager_internal_notes n'],
            'task'=>['select'=>'SELECT t.id,t.created_at,t.created_by_manager_id AS actor_id,t.title,t.due_at_utc,t.status,t.updated_at FROM lead_tasks t','where'=>'t.conversation_id=?','params'=>[$conversationId],'id'=>'t.id','time'=>'t.created_at','from'=>'lead_tasks t'],
        ];
    }

    private static function ceilings(PDO $pdo,array $sources): array
    {
        $parts=[];$params=[];
        foreach ($sources as $kind=>$source) {
            $parts[]='(SELECT COALESCE(MAX('.$source['id'].'),0) FROM '.$source['from'].' WHERE '.$source['where'].') AS '.$kind.'_ceiling';
            $params=array_merge($params,$source['params']);
        }
        $q=$pdo->prepare('SELECT '.implode(',',$parts));$q->execute($params);$row=$q->fetch(PDO::FETCH_ASSOC);$result=[];
        foreach (self::SOURCES as $kind=>$rank) $result[$kind]=(int)$row[$kind.'_ceiling'];
        return $result;
    }

    private static function view(array $row,int $actor,array $names): array
    {
        $kind=$row['_kind'];$type=(string)($row['actor_type']??'manager');$actorId=(string)($row['actor_id']??'');
        $who=$type==='customer'?'Клиент':($type==='manager'?($actorId===(string)$actor?'Вы':($names[(int)$actorId]??'Менеджер')):'Система');
        $view=['key'=>$kind.':'.(int)$row['id'],'kind'=>$kind,'created_at'=>(string)$row['created_at'],'actor'=>$who,'title'=>'','details'=>[]];
        if ($kind==='event') $view['title']=self::EVENTS[$row['event_type']];
        elseif ($kind==='stage') {
            $view['title']='Изменён этап работы';
            $view['details'][]=['label'=>'Этап','value'=>(string)($row['from_name']?:$row['from_stage_key']?:'Не указан').' → '.(string)($row['to_name']?:$row['to_stage_key'])];
        } elseif ($kind==='name') {
            $view['title']=$row['after_name']===null?'Восстановлено исходное имя клиента':'Изменено рабочее имя клиента';
            $view['details'][]=['label'=>'Было','value'=>$row['before_name']===null?'Исходное имя':(string)$row['before_name']];
            $view['details'][]=['label'=>'Стало','value'=>$row['after_name']===null?'Исходное имя':(string)$row['after_name']];
        } elseif ($kind==='note') {
            $view['title']='Добавлена внутренняя заметка';
            $view['current_record']=true;$view['updated_at']=(string)$row['updated_at'];
            $view['details'][]=['label'=>'Текущее содержание','value'=>$row['deleted_at']!==null?'Заметка удалена':(string)$row['body']];
        } elseif ($kind==='task') {
            $view['title']='Создана задача';$view['current_record']=true;$view['updated_at']=(string)$row['updated_at'];
            $view['details'][]=['label'=>'Текущая задача','value'=>(string)$row['title']];
            $view['details'][]=['label'=>'Сейчас','value'=>$row['status']==='done'?'Выполнена':($row['status']==='open'?'Открыта':'Другой статус')];
            if ($row['due_at_utc']!==null) $view['due_at']=(string)$row['due_at_utc'];
        }
        return $view;
    }

    private static function cursor($value,int $actor,int $conversationId): array|false|null
    {
        if ($value==='') return null;
        if (!is_string($value)||strlen($value)>1024||!preg_match('/^[A-Za-z0-9_-]+$/D',$value)) return false;
        $decoded=base64_decode(strtr($value,'-_','+/'),true);
        if ($decoded===false) return false;
        try {$cursor=json_decode($decoded,true,8,JSON_THROW_ON_ERROR);} catch (Throwable $e) {return false;}
        if (!is_array($cursor)||($cursor['v']??null)!==1||($cursor['actor']??null)!==$actor||($cursor['conversation']??null)!==$conversationId||!is_array($cursor['ceilings']??null)||!is_int($cursor['id']??null)||$cursor['id']<1||!is_int($cursor['rank']??null)||!in_array($cursor['rank'],self::SOURCES,true)) return false;
        foreach (self::SOURCES as $kind=>$rank) if (!is_int($cursor['ceilings'][$kind]??null)||$cursor['ceilings'][$kind]<0) return false;
        $at=$cursor['at']??null;
        if (!is_string($at)||!preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/D',$at)) return false;
        $date=DateTimeImmutable::createFromFormat('!Y-m-d H:i:s',$at,new DateTimeZone('UTC'));
        return !$date||$date->format('Y-m-d H:i:s')!==$at?false:$cursor;
    }
    private static function encode(array $cursor): string {return rtrim(strtr(base64_encode(json_encode($cursor,JSON_THROW_ON_ERROR)),'+/','-_'),'=');}
    private static function error(string $error): array {return ['ok'=>false,'error'=>$error];}
}
