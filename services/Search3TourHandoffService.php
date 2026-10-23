<?php
declare(strict_types=1);
require_once __DIR__.'/ConversationDb.php';
require_once __DIR__.'/ChannelPostRuntime.php';
require_once __DIR__.'/Search3TourHandoffSnapshot.php';
require_once __DIR__.'/Search3TourHandoffTrust.php';

/** Short-lived entry capabilities; TourChoiceService retains native inquiry ownership. */
final class Search3TourHandoffService
{
    public const MAX_BODY=32768;
    private static function error(string $code): array {return ['ok'=>false,'error'=>$code];}
    private static function suffix(PDO $pdo): string {return $pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'?' FOR UPDATE':'';}
    public static function lockProject(PDO $pdo): bool
    {
        if (!$pdo->inTransaction()) throw new LogicException('Search entry requires owning transaction');
        $q=$pdo->prepare('SELECT is_active FROM projects WHERE project_key=?'.self::suffix($pdo));$q->execute([ProjectConfig::projectId()]);return (int)$q->fetchColumn()===1;
    }
    /** Signature checked even for direct PHP callers. Authentication precedes SQL. */
    public static function execute(string $raw,array $server): array
    {
        if (!$raw||strlen($raw)>self::MAX_BODY) return self::error('invalid_request');
        $auth=Search3TourHandoffTrust::verify($raw,$server);if (empty($auth['ok'])) return $auth;
        $data=json_decode($raw,true,24);
        if (!is_array($data)||array_is_list($data)||!is_string($data['operation_id']??null)||!preg_match('/\A[a-f0-9]{32}\z/D',$data['operation_id'])) return self::error('invalid_request');
        $action=$data['action']??null;$keys=$action==='create'?['action','operation_id','binding','observation']:['action','operation_id','request_hash'];
        if (!in_array($action,['create','status','revoke'],true)||array_diff(array_keys($data),$keys)||array_diff($keys,array_keys($data))) return self::error('invalid_request');
        $snapshot=$action==='create'?Search3TourHandoffSnapshot::normalize(['binding'=>$data['binding'],'observation'=>$data['observation']]):null;
        if ($action==='create'&&$snapshot===null) return self::error('invalid_snapshot');
        $hash=$action==='create'?hash('sha256',$raw):$data['request_hash'];if (!is_string($hash)||!preg_match('/\A[a-f0-9]{64}\z/D',$hash)) return self::error('invalid_request');
        $pdo=ConversationDb::connection();if ($pdo->inTransaction()) throw new LogicException('Producer command owns transaction');$pdo->beginTransaction();
        try {
            $kid=$auth['producer']['id'];
            if (!self::lockProject($pdo)||!Search3TourHandoffTrust::producer($kid)) {$pdo->rollBack();return self::error('unavailable');}
            $q=$pdo->prepare('SELECT * FROM search3_tour_handoffs WHERE project_key=? AND operation_id=?'.self::suffix($pdo));$q->execute([ProjectConfig::projectId(),$data['operation_id']]);$row=$q->fetch(PDO::FETCH_ASSOC);
            if ($row) {
                if ($row['producer_key']!==$kid||!hash_equals($row['request_hash'],$hash)) {$pdo->rollBack();return self::error('operation_mismatch');}
                if ($action==='revoke') {$q=$pdo->prepare('UPDATE search3_tour_handoffs SET is_active=0 WHERE handoff_id=?');$q->execute([$row['handoff_id']]);$row['is_active']=0;}
                $out=self::result($row);$pdo->commit();return $out+['duplicate'=>true];
            }
            if ($action!=='create') {$pdo->commit();return ['ok'=>true,'state'=>'missing'];}
            $now=time();$captured=$snapshot['observation']['capturedAt'];if ($captured<($now-900)*1000||$captured>($now+60)*1000) {$pdo->rollBack();return self::error('stale_observation');}
            $bindings=ChannelPostRuntime::botIdentities();if (count($bindings)!==2) {$pdo->rollBack();return self::error('bots_unavailable');}
            $q=$pdo->prepare('SELECT COUNT(*) FROM search3_tour_handoffs WHERE project_key=? AND created_at>=?');$q->execute([ProjectConfig::projectId(),gmdate('Y-m-d H:i:s',$now-86400)]);
            if ((int)$q->fetchColumn()>=5000) {$pdo->rollBack();return self::error('rate_limited');}
            $q=$pdo->prepare('SELECT handoff_id FROM search3_tour_handoffs WHERE project_key=? AND created_at<? ORDER BY created_at,handoff_id LIMIT 500');$q->execute([ProjectConfig::projectId(),gmdate('Y-m-d H:i:s',$now-7*86400)]);
            $delete=$pdo->prepare('DELETE FROM search3_tour_handoffs WHERE handoff_id=? AND project_key=?');foreach ($q->fetchAll(PDO::FETCH_COLUMN) as $id) $delete->execute([$id,ProjectConfig::projectId()]);
            $row=['handoff_id'=>bin2hex(random_bytes(16)),'project_key'=>ProjectConfig::projectId(),'producer_key'=>$kid,'operation_id'=>$data['operation_id'],'request_hash'=>$hash,
                'snapshot_json'=>json_encode($snapshot,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),'telegram_token'=>'s3_'.bin2hex(random_bytes(16)),'max_token'=>'s3_'.bin2hex(random_bytes(16)),
                'bot_bindings_json'=>json_encode($bindings,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR),'is_active'=>1,'created_at'=>gmdate('Y-m-d H:i:s',$now),'expires_at'=>gmdate('Y-m-d H:i:s',$now+86400)];
            $q=$pdo->prepare('INSERT INTO search3_tour_handoffs(handoff_id,project_key,producer_key,operation_id,request_hash,snapshot_json,telegram_token,max_token,bot_bindings_json,is_active,created_at,expires_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?)');$q->execute(array_values($row));
            $out=self::result($row);$pdo->commit();return $out+['duplicate'=>false];
        } catch (Throwable $e) {if ($pdo->inTransaction()) $pdo->rollBack();throw $e;}
    }
    private static function result(array $row): array
    {
        $out=['ok'=>true,'context_id'=>$row['handoff_id'],'request_hash'=>$row['request_hash'],'expires_at'=>$row['expires_at'],'state'=>(int)$row['is_active']===1?'ready':'revoked'];
        if ($row['expires_at']<=gmdate('Y-m-d H:i:s')) $out['state']='expired';$bindings=json_decode($row['bot_bindings_json'],true);
        foreach (['telegram','max'] as $platform) if (($bindings[$platform]??null)!==ChannelPostRuntime::identity($platform)) $out['state']='unavailable';
        if ($out['state']==='ready') foreach ($bindings as $platform=>$identity) $out['links'][$platform]=$identity['bot_url'].'?start='.rawurlencode($row[$platform.'_token']);
        return $out;
    }
    public static function botEntry(string $platform,string $payload,bool $lock=false): ?array
    {
        if (!Search3TourHandoffTrust::keys()||!in_array($platform,['telegram','max'],true)||!preg_match('/\As3_[a-f0-9]{32}\z/D',$payload)) return null;
        $identity=ChannelPostRuntime::identity($platform);if (!$identity) return null;$pdo=ConversationDb::connection();
        if ($lock&&!$pdo->inTransaction()) throw new LogicException('Native search entry requires owning transaction');
        $q=$pdo->prepare('SELECT * FROM search3_tour_handoffs WHERE project_key=? AND '.$platform.'_token=? AND is_active=1 AND expires_at>?'.($lock?self::suffix($pdo):''));$q->execute([ProjectConfig::projectId(),$payload,gmdate('Y-m-d H:i:s')]);$row=$q->fetch(PDO::FETCH_ASSOC);
        if (!$row||!Search3TourHandoffTrust::producer($row['producer_key'])) return null;
        $q=$pdo->prepare('SELECT is_active FROM projects WHERE project_key=?');$q->execute([ProjectConfig::projectId()]);if ((int)$q->fetchColumn()!==1) return null;
        $bindings=json_decode($row['bot_bindings_json'],true);$snapshot=json_decode($row['snapshot_json'],true);
        if (($bindings[$platform]??null)!==$identity||!is_array($snapshot)||Search3TourHandoffSnapshot::normalize($snapshot)===null) return null;
        return ['origin'=>'search3','handoff_id'=>$row['handoff_id'],'snapshot'=>$snapshot,'public_offer'=>['hotel'=>$snapshot['observation']['hotel']['name']]];
    }
}
