<?php
declare(strict_types=1);
require_once __DIR__.'/ManagerTourSelectionService.php';

/** Revocable connection metadata, not authentication. Every caller still has a Manager session. */
final class ManagerTourSelectionCollectorSession
{
    public static function binding(array $c,array $draft): string
    {
        return hash('sha256',json_encode([array_intersect_key($c,array_flip(['id','project_key','source_id','channel','external_chat_id','customer_channel_id','manager_id','started_at'])),$draft['title']],JSON_THROW_ON_ERROR));
    }

    public static function view(int $actor,int $id,string $session,string $device): array
    {
        $selection=ManagerTourSelectionService::get($actor,$id);
        if (empty($selection['ok'])) return $selection;
        if (empty($selection['selection']['can_edit'])) return ['ok'=>false,'error'=>'forbidden'];
        $c=ManagerConversationService::visibleConversation($id,$actor);
        if (!$c) return ['ok'=>false,'error'=>'not_found'];
        if (!self::activeBindings(ConversationDb::connection(),$c)) return ['ok'=>false,'error'=>'forbidden'];
        $draft=$selection['selection']['draft'];
        $result=['ok'=>true,'target'=>self::target($actor,$c,$selection['selection']),'connection'=>null];
        if (!self::id($device) || !self::session($session)) return $result;
        $q=ConversationDb::connection()->prepare('SELECT connection_id,binding_hash,expires_at FROM manager_tour_selection_collectors WHERE manager_id=? AND conversation_id=? AND device_id=? AND session_hash=? AND revoked_at IS NULL AND expires_at>? ORDER BY created_at DESC,connection_id DESC LIMIT 1');
        $q->execute([$actor,$id,$device,$session,gmdate('Y-m-d H:i:s')]);$row=$q->fetch(PDO::FETCH_ASSOC);
        if ($row && hash_equals($row['binding_hash'],self::binding($c,$draft))) $result['connection']=['id'=>$row['connection_id'],'expires_at'=>$row['expires_at']];
        return $result;
    }

    public static function connect(int $actor,int $id,string $session,array $input): array
    {
        $device=$input['device_id']??'';$connection=$input['connection_id']??'';
        if (!self::id($device) || !self::id($connection) || !self::session($session) || ($input['confirm_current_trip']??false)!==true) return ['ok'=>false,'error'=>'invalid_connection'];
        $pdo=ConversationDb::connection();$pdo->beginTransaction();
        try {
            $suffix=$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'?' FOR UPDATE':'';
            $q=$pdo->prepare('SELECT id FROM managers WHERE id=? AND is_active=1'.$suffix);$q->execute([$actor]);
            if (!$q->fetchColumn()) {$pdo->rollBack();return ['ok'=>false,'error'=>'unauthorized'];}
            $q=$pdo->prepare('SELECT id FROM conversations WHERE id=?'.$suffix);$q->execute([$id]);
            if (!$q->fetchColumn()) {$pdo->rollBack();return ['ok'=>false,'error'=>'not_found'];}
            $view=self::view($actor,$id,$session,$device);
            if (empty($view['ok'])) {$pdo->rollBack();return $view;}
            if (!hash_equals((string)($input['binding']??''),$view['target']['binding'])) {$pdo->rollBack();return ['ok'=>false,'error'=>'trip_changed'];}
            $q=$pdo->prepare('SELECT * FROM manager_tour_selection_collectors WHERE connection_id=?'.$suffix);$q->execute([$connection]);$old=$q->fetch(PDO::FETCH_ASSOC);
            if ($old) {
                if ((int)$old['manager_id']!==$actor || (int)$old['conversation_id']!==$id || $old['device_id']!==$device || !hash_equals($old['session_hash'],$session) || $old['revoked_at']!==null || $old['expires_at']<=gmdate('Y-m-d H:i:s') || !hash_equals($old['binding_hash'],$view['target']['binding'])) {$pdo->rollBack();return ['ok'=>false,'error'=>'connection_changed'];}
            } else {
                $now=gmdate('Y-m-d H:i:s');$until=gmdate('Y-m-d H:i:s',time()+43200);
                $q=$pdo->prepare('UPDATE manager_tour_selection_collectors SET revoked_at=? WHERE manager_id=? AND conversation_id=? AND device_id=? AND revoked_at IS NULL');$q->execute([$now,$actor,$id,$device]);
                $q=$pdo->prepare('INSERT INTO manager_tour_selection_collectors(connection_id,manager_id,conversation_id,device_id,session_hash,binding_hash,created_at,expires_at) VALUES(?,?,?,?,?,?,?,?)');$q->execute([$connection,$actor,$id,$device,$session,$view['target']['binding'],$now,$until]);
            }
            $result=self::view($actor,$id,$session,$device);$pdo->commit();return $result;
        } catch(Throwable $e) {if($pdo->inTransaction())$pdo->rollBack();throw $e;}
    }

    public static function revoke(int $actor,int $id,string $session): array
    {
        $view=ManagerTourSelectionService::get($actor,$id);
        if (empty($view['ok'])) return $view;
        if (empty($view['selection']['can_edit'])) return ['ok'=>false,'error'=>'forbidden'];
        $q=ConversationDb::connection()->prepare('UPDATE manager_tour_selection_collectors SET revoked_at=? WHERE manager_id=? AND conversation_id=? AND session_hash=? AND revoked_at IS NULL');$q->execute([gmdate('Y-m-d H:i:s'),$actor,$id,$session]);
        return ['ok'=>true];
    }

    /** Called under canonical manager + conversation locks in the draft transaction. */
    public static function authorize(PDO $pdo,int $actor,int $id,string $session,string $connection,array $c,array $draft): bool
    {
        if (!self::id($connection) || !self::session($session) || !self::activeBindings($pdo,$c)) return false;
        $q=$pdo->prepare('SELECT binding_hash,expires_at,revoked_at FROM manager_tour_selection_collectors WHERE connection_id=? AND manager_id=? AND conversation_id=? AND session_hash=?'.($pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'?' FOR UPDATE':''));
        $q->execute([$connection,$actor,$id,$session]);$r=$q->fetch(PDO::FETCH_ASSOC);
        return $r && $r['revoked_at']===null && $r['expires_at']>gmdate('Y-m-d H:i:s') && hash_equals($r['binding_hash'],self::binding($c,$draft));
    }

    private static function target(int $actor,array $c,array $selection): array
    {
        $draft=$selection['draft'];$dates=[];
        foreach ($draft['offers'] as $offer) if ($offer['date_from']!=='') $dates[$offer['date_from']]=true;
        return ['actor'=>$actor,'conversation_id'=>(int)$c['id'],'client'=>(string)($c['display_name']??'Турист'),'channel'=>(string)$c['channel'],'trip'=>$draft['title'],'started_at'=>(string)$c['started_at'],'dates'=>array_keys($dates),'count'=>count($draft['offers']),'version'=>$selection['version'],'binding'=>self::binding($c,$draft)];
    }
    private static function activeBindings(PDO $pdo,array $c): bool
    {
        $q=$pdo->prepare('SELECT id FROM projects WHERE project_key=? AND is_active=1');$q->execute([(string)$c['project_key']]);
        if (!$q->fetchColumn()) return false;
        if ((int)($c['source_id']??0)<=0) return true;
        $q=$pdo->prepare('SELECT id FROM conversation_sources WHERE id=? AND is_active=1');$q->execute([(int)$c['source_id']]);return (bool)$q->fetchColumn();
    }
    private static function id($s): bool {return is_string($s)&&preg_match('/\A[a-f0-9]{32}\z/D',$s)===1;}
    private static function session(string $s): bool {return preg_match('/\A[a-f0-9]{64}\z/D',$s)===1;}
}
