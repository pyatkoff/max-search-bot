<?php
declare(strict_types=1);
require_once __DIR__.'/ConversationDb.php';

/** Account-owned browser intent/receipts. Native subscriptions and sound remain browser-owned. */
final class ManagerPushConnectionService
{
    public static function activeSubscriptions(PDO $pdo, array $managers): array
    {
        if (!$managers) return [];
        $in=implode(',',array_fill(0,count($managers),'?'));
        $q=$pdo->prepare("SELECT s.* FROM manager_push_subscriptions s JOIN manager_push_installations i ON i.id=s.installation_id AND i.manager_id=s.manager_id
            WHERE s.manager_id IN ($in) AND s.is_active=1 AND i.enabled=1 AND s.intent_revision=i.revision ORDER BY s.id");
        $q->execute(array_values($managers));
        return $q->fetchAll()?:[];
    }

    public static function isCurrent(PDO $pdo, array $sub): bool
    {
        $q=$pdo->prepare('SELECT s.id FROM manager_push_subscriptions s JOIN manager_push_installations i ON i.id=s.installation_id AND i.manager_id=s.manager_id WHERE s.id=? AND s.manager_id=? AND s.revision=? AND s.intent_revision=? AND s.is_active=1 AND i.enabled=1 AND i.revision=s.intent_revision');
        $q->execute([(int)$sub['id'],(int)$sub['manager_id'],(int)$sub['revision'],(int)$sub['intent_revision']]);return (bool)$q->fetch();
    }

    public static function connections(int $manager, string $token='', string $endpoint=''): array
    {
        $pdo=ConversationDb::connection();
        $q=$pdo->prepare('SELECT i.*,s.endpoint_hash,s.is_active,s.intent_revision,s.last_success_at,s.last_error_at
            FROM manager_push_installations i LEFT JOIN manager_push_subscriptions s ON s.id=(SELECT p.id FROM manager_push_subscriptions p WHERE p.installation_id=i.id AND p.manager_id=i.manager_id ORDER BY p.is_active DESC,p.updated_at DESC,p.id DESC LIMIT 1)
            WHERE i.manager_id=? ORDER BY i.id DESC LIMIT 101');
        $q->execute([$manager]);$rows=$q->fetchAll()?:[];$truncated=count($rows)>100;$rows=array_slice($rows,0,100);
        return ['ok'=>true,'connections'=>array_map(static function(array $row) use ($token,$endpoint): array {
            $current=self::token($token)&&is_string($row['token_hash'])&&hash_equals($row['token_hash'],hash('sha256',$token));
            if (!$current&&$endpoint!==''&&empty($row['token_hash'])) $current=hash_equals((string)($row['endpoint_hash']??''),hash('sha256',trim($endpoint)));
            return self::view($row)+['current'=>$current];
        },$rows),'truncated'=>$truncated];
    }

    /** Read-only current intent, without exposing its browser capability. */
    public static function current(int $manager, string $token='', string $endpoint=''): ?array
    {
        $pdo=ConversationDb::connection();$row=null;
        if (self::token($token)) {
            $q=$pdo->prepare('SELECT * FROM manager_push_installations WHERE manager_id=? AND token_hash=? LIMIT 1');
            $q->execute([$manager,hash('sha256',$token)]);$row=$q->fetch()?:null;
        }
        if (!$row&&$endpoint!=='') {
            $q=$pdo->prepare('SELECT i.* FROM manager_push_installations i JOIN manager_push_subscriptions s ON s.installation_id=i.id AND s.manager_id=i.manager_id WHERE i.manager_id=? AND s.endpoint_hash=? LIMIT 1');
            $q->execute([$manager,hash('sha256',trim($endpoint))]);$row=$q->fetch()?:null;
        }
        return $row?['id'=>(int)$row['id'],'revision'=>(int)$row['revision'],'enabled'=>(bool)$row['enabled']]:null;
    }

    public static function operation(int $manager, string $operation): array
    {
        if (!self::token($operation)) return self::error('invalid_operation',422);
        $q=ConversationDb::connection()->prepare('SELECT result_json FROM manager_push_operations WHERE manager_id=? AND operation_id=?');
        $q->execute([$manager,$operation]);$json=$q->fetchColumn();
        // Absence is not proof that an in-flight request cannot commit later.
        if ($json===false) return ['ok'=>true,'found'=>false];
        return self::response($manager,json_decode((string)$json,true));
    }

    public static function mutate(int $manager, string $action, array $input, string $userAgent=''): array
    {
        if (!in_array($action,['enable_intent','subscribe','revoke'],true)) return self::error('unknown_action',400);
        $operation=$input['operation_id']??null;
        if (!is_string($operation)||!self::token($operation)) return self::error('installation_required',409);
        $fields=$action==='revoke'?['connection_id','expected_revision']:['installation_token','expected_revision','subscription'];
        $command=['action'=>$action];foreach ($fields as $field) if (array_key_exists($field,$input)) $command[$field]=$input[$field];
        $hash=hash('sha256',self::json($command));$pdo=ConversationDb::connection();
        if ($pdo->inTransaction()) throw new LogicException('Push connection requires its own transaction');
        $pdo->beginTransaction();
        try {
            // Serializes endpoint adoption, intent, revoke, and receipt within this account.
            $q=$pdo->prepare('SELECT id FROM managers WHERE id=? AND is_active=1 FOR UPDATE');$q->execute([$manager]);
            if (!$q->fetch()) {$pdo->rollBack();return self::error('unauthorized',401);}
            $q=$pdo->prepare('SELECT request_hash,result_json FROM manager_push_operations WHERE manager_id=? AND operation_id=?');$q->execute([$manager,$operation]);$old=$q->fetch();
            if ($old) {
                $pdo->commit();
                return hash_equals($old['request_hash'],$hash)?self::response($manager,json_decode($old['result_json'],true)):self::error('operation_conflict',409);
            }
            $result=self::apply($pdo,$manager,$action,$input,$userAgent);
            $receipt=['id'=>$operation,'action'=>$action,'result'=>$result];
            $q=$pdo->prepare('INSERT INTO manager_push_operations (manager_id,operation_id,request_hash,result_json,created_at) VALUES (?,?,?,?,?)');
            $q->execute([$manager,$operation,$hash,self::json($receipt),gmdate('Y-m-d H:i:s')]);$pdo->commit();
            return self::response($manager,$receipt);
        } catch (Throwable $error) {if ($pdo->inTransaction()) $pdo->rollBack();throw $error;}
    }

    private static function apply(PDO $pdo, int $manager, string $action, array $input, string $agent): array
    {
        $revision=$input['expected_revision']??null;
        if (!is_int($revision)||$revision<0) return self::error('invalid_revision',422);
        $now=gmdate('Y-m-d H:i:s');
        if ($action==='revoke') {
            $id=$input['connection_id']??null;
            if (!is_int($id)||$id<=0) return self::error('not_found',404);
            $row=self::installation($pdo,$manager,$id);
            if (!$row) return self::error('not_found',404);
            if (!(bool)$row['enabled']) return ['ok'=>true,'connection_id'=>$id,'revision'=>(int)$row['revision'],'enabled'=>false];
            if ((int)$row['revision']!==$revision) return self::error('revision_conflict',409)+['connection_id'=>$id];
            $q=$pdo->prepare('UPDATE manager_push_installations SET enabled=0,revision=revision+1,updated_at=? WHERE manager_id=? AND id=?');$q->execute([$now,$manager,$id]);
            return ['ok'=>true,'connection_id'=>$id,'revision'=>$revision+1,'enabled'=>false];
        }
        $token=$input['installation_token']??null;
        if (!is_string($token)||!self::token($token)) return self::error('installation_required',409);
        $q=$pdo->prepare('SELECT * FROM manager_push_installations WHERE manager_id=? AND token_hash=? LIMIT 1');$q->execute([$manager,hash('sha256',$token)]);$row=$q->fetch()?:null;
        $subscription=$input['subscription']??null;
        if ($action==='enable_intent') {
            // A legacy/lost-storage installation can be claimed only with native key material,
            // never with a public connection ID from the remote list.
            if (!$row&&is_array($subscription)&&self::subscription($subscription)) {
                $q=$pdo->prepare('SELECT i.*,s.p256dh,s.auth_secret FROM manager_push_installations i JOIN manager_push_subscriptions s ON s.installation_id=i.id AND s.manager_id=i.manager_id WHERE i.manager_id=? AND s.endpoint_hash=? LIMIT 1');
                $q->execute([$manager,hash('sha256',trim($subscription['endpoint']))]);$candidate=$q->fetch();
                if ($candidate) {
                    if (!hash_equals((string)$candidate['p256dh'],(string)$subscription['keys']['p256dh'])||!hash_equals((string)$candidate['auth_secret'],(string)$subscription['keys']['auth'])) return self::error('installation_mismatch',409);
                    $row=$candidate;
                }
            }
            if ($row&&(int)$row['revision']!==$revision||!$row&&$revision!==0) return self::error('revision_conflict',409);
            if ($row) {
                $id=(int)$row['id'];$revision++;
                $q=$pdo->prepare('UPDATE manager_push_installations SET token_hash=?,enabled=1,revision=?,user_agent=?,last_seen_at=?,updated_at=? WHERE manager_id=? AND id=?');
                $q->execute([hash('sha256',$token),$revision,mb_substr($agent,0,500),$now,$now,$manager,$id]);
            } else {
                $q=$pdo->prepare('SELECT COUNT(*) FROM manager_push_installations WHERE manager_id=?');$q->execute([$manager]);if ((int)$q->fetchColumn()>=100) return self::error('connection_limit',409);
                $q=$pdo->prepare('INSERT INTO manager_push_installations (manager_id,token_hash,revision,enabled,user_agent,last_seen_at,created_at,updated_at) VALUES (?,?,1,1,?,?,?,?)');
                $q->execute([$manager,hash('sha256',$token),mb_substr($agent,0,500),$now,$now,$now]);$id=(int)$pdo->lastInsertId();$revision=1;
            }
            return ['ok'=>true,'connection_id'=>$id,'revision'=>$revision,'enabled'=>true];
        }
        if (!$row||!(bool)$row['enabled']||(int)$row['revision']!==$revision) return self::error('subscription_revoked',409);
        if (!is_array($subscription)||!self::subscription($subscription)) return self::error('invalid_subscription',422);
        $id=(int)$row['id'];$endpoint=trim($subscription['endpoint']);$keys=$subscription['keys'];
        $q=$pdo->prepare('SELECT * FROM manager_push_subscriptions WHERE manager_id=? AND endpoint_hash=? LIMIT 1');$q->execute([$manager,hash('sha256',$endpoint)]);$old=$q->fetch();
        if ($old&&$old['installation_id']!==null&&(int)$old['installation_id']!==$id) return self::error('installation_mismatch',409);
        $q=$pdo->prepare('UPDATE manager_push_subscriptions SET is_active=0,revision=revision+1 WHERE manager_id=? AND installation_id=? AND is_active=1');$q->execute([$manager,$id]);
        if ($old) {
            $changed=!hash_equals((string)$old['p256dh'],(string)$keys['p256dh'])||!hash_equals((string)$old['auth_secret'],(string)$keys['auth']);
            $q=$pdo->prepare('UPDATE manager_push_subscriptions SET installation_id=?,endpoint=?,p256dh=?,auth_secret=?,user_agent=?,intent_revision=?,is_active=1,revision=revision+1,updated_at=?,last_error_at=?,last_error=? WHERE manager_id=? AND id=?');
            $q->execute([$id,$endpoint,$keys['p256dh'],$keys['auth'],mb_substr($agent,0,500),$revision,$now,$changed?null:$old['last_error_at'],$changed?null:$old['last_error'],$manager,(int)$old['id']]);
        } else {
            $q=$pdo->prepare('INSERT INTO manager_push_subscriptions (manager_id,installation_id,intent_revision,endpoint,endpoint_hash,p256dh,auth_secret,user_agent,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?,?,?)');
            $q->execute([$manager,$id,$revision,$endpoint,hash('sha256',$endpoint),$keys['p256dh'],$keys['auth'],mb_substr($agent,0,500),$now,$now]);
        }
        $q=$pdo->prepare('UPDATE manager_push_installations SET user_agent=?,last_seen_at=?,updated_at=? WHERE manager_id=? AND id=?');$q->execute([mb_substr($agent,0,500),$now,$now,$manager,$id]);
        return ['ok'=>true,'connection_id'=>$id,'revision'=>$revision,'enabled'=>true];
    }

    /** A provider result may only annotate the exact still-current captured row. */
    public static function deliveryResult(PDO $pdo, array $sub, int $code, string $error=''): void
    {
        $success=$code>=200&&$code<300;$expired=in_array($code,[404,410],true);
        $set=$success?'last_success_at=?,last_error=NULL':($expired?'is_active=0,revision=revision+1,last_error_at=?,last_error=?':'last_error_at=?,last_error=?');
        $params=[gmdate('Y-m-d H:i:s')];if (!$success) $params[]=mb_substr('HTTP '.$code.' '.$error,0,500);
        array_push($params,(int)$sub['id'],(int)$sub['manager_id'],(int)$sub['revision'],(int)$sub['intent_revision'],(int)$sub['installation_id'],(int)$sub['manager_id'],(int)$sub['intent_revision']);
        $q=$pdo->prepare('UPDATE manager_push_subscriptions SET '.$set.' WHERE id=? AND manager_id=? AND revision=? AND intent_revision=? AND is_active=1 AND EXISTS (SELECT 1 FROM manager_push_installations i WHERE i.id=? AND i.manager_id=? AND i.enabled=1 AND i.revision=?)');$q->execute($params);
    }

    private static function installation(PDO $pdo, int $manager, int $id): ?array
    {
        $q=$pdo->prepare('SELECT i.*,s.is_active,s.intent_revision,s.last_success_at,s.last_error_at FROM manager_push_installations i LEFT JOIN manager_push_subscriptions s ON s.id=(SELECT p.id FROM manager_push_subscriptions p WHERE p.installation_id=i.id AND p.manager_id=i.manager_id ORDER BY p.is_active DESC,p.updated_at DESC,p.id DESC LIMIT 1) WHERE i.manager_id=? AND i.id=? LIMIT 1');$q->execute([$manager,$id]);return $q->fetch()?:null;
    }
    private static function response(int $manager, array $receipt): array
    {
        $result=$receipt['result'];$id=(int)($result['connection_id']??0);$row=$id?self::installation(ConversationDb::connection(),$manager,$id):null;
        return $result+['found'=>true,'operation'=>$receipt,'current_connection'=>$row?self::view($row):null];
    }
    private static function view(array $row): array
    {
        $enabled=(bool)$row['enabled'];$active=!empty($row['is_active'])&&(int)($row['intent_revision']??0)===(int)$row['revision'];
        $healthy=$active&&(empty($row['last_error_at'])||!empty($row['last_success_at'])&&$row['last_success_at']>=$row['last_error_at']);
        return ['id'=>(int)$row['id'],'revision'=>(int)$row['revision'],'enabled'=>$enabled,'browser'=>self::browser((string)($row['user_agent']??'')),
            'status'=>!$enabled?'revoked':($active?($healthy?'saved':'attention'):'not_connected'),
            'last_activity_at'=>empty($row['last_seen_at'])?null:str_replace(' ','T',(string)$row['last_seen_at']).'Z'];
    }
    private static function browser(string $agent): string
    {
        $browser=preg_match('/Edg[A-Z]*\//i',$agent)?'Edge':(preg_match('/Firefox\/|FxiOS\//i',$agent)?'Firefox':(preg_match('/Chrome\/|CriOS\//i',$agent)?'Chrome':(str_contains($agent,'Safari/')?'Safari':'Браузер')));
        $os=preg_match('/iPhone|iPad|iPod/i',$agent)?'iOS':(str_contains($agent,'Android')?'Android':(str_contains($agent,'Windows')?'Windows':(str_contains($agent,'Mac OS')?'macOS':(str_contains($agent,'Linux')?'Linux':''))));
        return $browser.($os!==''?' · '.$os:'');
    }
    private static function token(string $token): bool {return preg_match('/\A[a-f0-9]{64}\z/D',$token)===1;}
    private static function subscription(array $sub): bool
    {
        return is_string($sub['endpoint']??null)&&strlen($sub['endpoint'])<=4096&&str_starts_with(trim($sub['endpoint']),'https://')&&is_string($sub['keys']['p256dh']??null)&&$sub['keys']['p256dh']!==''&&strlen($sub['keys']['p256dh'])<=255&&is_string($sub['keys']['auth']??null)&&$sub['keys']['auth']!==''&&strlen($sub['keys']['auth'])<=255;
    }
    private static function error(string $error, int $http): array {return ['ok'=>false,'error'=>$error,'http_status'=>$http];}
    private static function json(array $data): string {return json_encode($data,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);}
}
