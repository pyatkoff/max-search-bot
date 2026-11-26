<?php
declare(strict_types=1);
if (!class_exists('Max2ValueBindingPolicyV2',false)) require_once __DIR__.'/Max2ValueBindingPolicyV2.php';

/** Own runtime state. Internal activation methods have no public/CLI entry point. */
final class Max2CommonValueStateV2
{
    public const LEASE_SECONDS=180;
    private PDO $pdo;
    private Closure $clock;
    private string $lock;
    private bool $changed=false;
    public function __construct(PDO $pdo, ?callable $clock=null, bool $syntheticMemoryOnly=false)
    {
        $driver=$pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        if ($syntheticMemoryOnly) {
            if ($driver!=='sqlite') throw new InvalidArgumentException('SYNTHETIC_SQLITE_REQUIRED');
            foreach ($pdo->query('PRAGMA database_list')->fetchAll(PDO::FETCH_ASSOC) as $db) if (($db['file']??null)!=='') throw new InvalidArgumentException('SYNTHETIC_MEMORY_ONLY');
        } elseif ($driver!=='mysql') throw new InvalidArgumentException('MYSQL_STORAGE_REQUIRED');
        if ($pdo->getAttribute(PDO::ATTR_ERRMODE)!==PDO::ERRMODE_EXCEPTION) throw new InvalidArgumentException('EXCEPTION_MODE_REQUIRED');
        $this->pdo=$pdo; $this->clock=$clock===null?static fn():int=>time():Closure::fromCallable($clock); $this->lock=$driver==='mysql'?' FOR UPDATE':'';
    }
    public static function cursor($value): bool { return is_string($value)&&preg_match('/\A(?:0|[1-9][0-9]{0,19})\z/D',$value)===1; }
    public static function compare(string $a,string $b): int { return strlen($a)<=>strlen($b) ?: strcmp($a,$b); }
    private static function hash($v): bool { return Max2ValueBindingPolicyV2::digest($v); }
    private static function hold(string $reason): array { return ['status'=>'HOLD','reason'=>$reason,'call_permitted'=>false,'provider_calls'=>0]; }
    private function query(string $sql,array $args=[]): PDOStatement { $q=$this->pdo->prepare($sql); $q->execute($args); return $q; }
    private function write(string $sql,array $args=[]): PDOStatement { $this->changed=true; return $this->query($sql,$args); }
    private function tx(callable $body): array
    {
        if ($this->pdo->inTransaction()) return self::hold('OUTER_TRANSACTION_REJECTED');
        $this->changed=false; $commit=false;
        try {
            if ($this->pdo->beginTransaction()!==true) throw new RuntimeException('TRANSACTION_NOT_STARTED');
            $result=$body();
            if (($result['status']??null)==='HOLD'||!$this->changed) {
                if ($this->pdo->rollBack()!==true) throw new RuntimeException('ROLLBACK_UNCONFIRMED');
                return $result;
            }
            $commit=true; if ($this->pdo->commit()!==true) throw new RuntimeException('COMMIT_UNCONFIRMED');
            return $result;
        } catch (Throwable $error) {
            $rolled=false; try { if ($this->pdo->inTransaction()) $rolled=$this->pdo->rollBack(); } catch (Throwable $ignored) {}
            if ($commit||($this->changed&&!$rolled)) return ['status'=>'UNKNOWN','reason'=>'V2_STATE_COMMIT_UNCONFIRMED',
                'call_permitted'=>false,'requires_readback'=>true,'automatic_retry'=>false,'provider_calls'=>0];
            $reason=$error instanceof DomainException&&preg_match('/\A[A-Z_]{1,80}\z/D',$error->getMessage())===1?$error->getMessage():'V2_STATE_UNAVAILABLE';
            return self::hold($reason);
        }
    }
    private function normalize($row): array
    {
        if (!is_array($row)) throw new DomainException('V2_SETTINGS_SCOPE_CONFLICT');
        foreach (['project_key','counter_id','goal_id','target','producer_contract_sha256','mode','profile_cursor',
            'initial_profile_max_id','activation_sha256','activation_request_sha256','activation_source_sha','activated_at_unix',
            'active_tick_key','lease_until_unix'] as $key) if (!array_key_exists($key,$row)) throw new DomainException('V2_SETTINGS_SCOPE_CONFLICT');
        if (!is_array($row)||$row['project_key']!=='anytour'||(string)$row['counter_id']!=='98615635'
            ||(string)$row['goal_id']!=='687941321'||$row['target']!==Max2ValueBindingPolicyV2::TARGET
            ||$row['producer_contract_sha256']!==Max2ValueBindingPolicyV2::PRODUCER_CONTRACT_SHA256
            ||!in_array($row['mode'],['off','collect','send'],true)||!self::cursor($row['profile_cursor'])
            ||!self::cursor($row['initial_profile_max_id'])) throw new DomainException('V2_SETTINGS_SCOPE_CONFLICT');
        $activation=null;
        if ($row['activation_sha256']!==null) {
            if (!self::hash($row['activation_sha256'])||!self::hash($row['activation_request_sha256'])
                ||!is_string($row['activation_source_sha'])||preg_match('/\A[a-f0-9]{40}\z/D',$row['activation_source_sha'])!==1
                ||(int)$row['activated_at_unix']<=Max2ValueBindingPolicyV2::PRODUCER_CUTOVER_UNIX
                ||self::compare($row['profile_cursor'],$row['initial_profile_max_id'])<0) throw new DomainException('V2_ACTIVATION_CONFLICT');
            $activation=['version'=>1,'mode'=>$row['mode'],'counter_id'=>98615635,'goal_id'=>687941321,
                'target'=>Max2ValueBindingPolicyV2::TARGET,'producer_contract_sha256'=>Max2ValueBindingPolicyV2::PRODUCER_CONTRACT_SHA256,
                'activation_sha256'=>$row['activation_sha256'],'activated_at_unix'=>(int)$row['activated_at_unix'],
                'initial_profile_max_id'=>$row['initial_profile_max_id']];
        } elseif ($row['mode']!=='off'||$row['activated_at_unix']!==null||$row['profile_cursor']!=='0'||$row['initial_profile_max_id']!=='0') throw new DomainException('V2_UNINITIALIZED_STATE_CONFLICT');
        return ['status'=>'OBSERVED','mode'=>$row['mode'],'activation'=>$activation,'cursor'=>$row['profile_cursor'],
            'initial_profile_max_id'=>$row['initial_profile_max_id'],'active_tick_key'=>$row['active_tick_key'],
            'lease_until_unix'=>(int)$row['lease_until_unix'],'provider_calls'=>0];
    }
    private function lockedSettings(): array {
        return $this->query('SELECT * FROM max2_common_settings_v2 WHERE id=1'.$this->lock)->fetch(PDO::FETCH_ASSOC) ?: [];
    }
    public function settings(): array
    {
        try { return $this->normalize($this->query('SELECT * FROM max2_common_settings_v2 WHERE id=1')->fetch(PDO::FETCH_ASSOC)); }
        catch (Throwable $ignored) { return ['status'=>'UNKNOWN','reason'=>'V2_SETTINGS_UNAVAILABLE','mode'=>'off','call_permitted'=>false,'provider_calls'=>0]; }
    }
    /** Root's future admitted operator path owns authorization. Hashes below are provenance only. */
    public function activate(string $sourceSha,string $requestSha): array
    {
        if (preg_match('/\A[a-f0-9]{40}\z/D',$sourceSha)!==1||!self::hash($requestSha)) return self::hold('EXACT_ACTIVATION_PROVENANCE_REQUIRED');
        return $this->tx(function() use($sourceSha,$requestSha):array {
            $row=$this->lockedSettings(); $settings=$this->normalize($row);
            if ($settings['activation']!==null) return $row['activation_request_sha256']===$requestSha&&$row['activation_source_sha']===$sourceSha
                ? ['status'=>'ALREADY_ACTIVATED','settings'=>$settings,'call_permitted'=>false,'provider_calls'=>0]
                : self::hold('ACTIVATION_EPOCH_IMMUTABLE');
            $now=($this->clock)();
            if (!is_int($now)||$now<=Max2ValueBindingPolicyV2::PRODUCER_CUTOVER_UNIX) throw new DomainException('ACTIVATION_CLOCK_UNPROVED');
            // The CURRENT maximum is read inside this transaction, never supplied by JSON or a caller.
            $maximum=(string)$this->query('SELECT COALESCE(MAX(id),0) FROM modeled_conversion_profile_versions WHERE project_key=?',['anytour'])->fetchColumn();
            if (!self::cursor($maximum)) throw new DomainException('CURRENT_PROFILE_MAX_UNPROVED');
            $epoch=hash('sha256',json_encode(['max2/common/activation/v2','98615635',Max2ValueBindingPolicyV2::TARGET,
                $sourceSha,$requestSha,$now,$maximum,Max2ValueBindingPolicyV2::PRODUCER_CONTRACT_SHA256],JSON_THROW_ON_ERROR));
            $this->write('UPDATE max2_common_settings_v2 SET mode=?,activation_sha256=?,activation_source_sha=?,activation_request_sha256=?,mode_request_sha256=?,activated_at_unix=?,initial_profile_max_id=?,profile_cursor=? WHERE id=1 AND activation_sha256 IS NULL',
                ['collect',$epoch,$sourceSha,$requestSha,$requestSha,$now,$maximum,$maximum]);
            $saved=$this->normalize($this->lockedSettings());
            if ($saved['activation']['activation_sha256']!==$epoch||$saved['cursor']!==$maximum) throw new DomainException('ACTIVATION_READBACK_CONFLICT');
            return ['status'=>'ACTIVATED','settings'=>$saved,'call_permitted'=>false,'provider_calls'=>0];
        });
    }
    public function setMode(string $mode,string $requestSha): array
    {
        if (!in_array($mode,['off','collect','send'],true)||!self::hash($requestSha)) return self::hold('MODE_TRANSITION_UNPROVED');
        return $this->tx(function() use($mode,$requestSha):array {
            $row=$this->lockedSettings(); $s=$this->normalize($row);
            if ($s['activation']===null) return self::hold('ACTIVATION_REQUIRED');
            if ($s['active_tick_key']!==null&&$s['lease_until_unix']>($this->clock)()) return self::hold('ACTIVE_TICK_MODE_TRANSITION_HELD');
            $this->write('UPDATE max2_common_settings_v2 SET mode=?,mode_request_sha256=? WHERE id=1',[$mode,$requestSha]);
            $saved=$this->normalize($this->lockedSettings());
            if ($saved['mode']!==$mode||$saved['activation']['activation_sha256']!==$s['activation']['activation_sha256']||$saved['cursor']!==$s['cursor']) throw new DomainException('MODE_READBACK_CONFLICT');
            return ['status'=>'MODE_SET','settings'=>$saved,'call_permitted'=>false,'provider_calls'=>0];
        });
    }
    public function beginTick(int $now): array
    {
        return $this->tx(function() use($now):array {
            $s=$this->normalize($this->lockedSettings());
            if ($s['mode']==='off') return ['status'=>'OFF','provider_calls'=>0,'call_permitted'=>false];
            if ($s['activation']===null||$now<$s['activation']['activated_at_unix']) return self::hold('ACTIVE_EPOCH_REQUIRED');
            if ($s['active_tick_key']!==null&&$s['lease_until_unix']>$now) return ['status'=>'BUSY','provider_calls'=>0,'call_permitted'=>false];
            if ($s['active_tick_key']!==null) $this->write('UPDATE max2_common_ticks_v2 SET state=?,finished_at_unix=? WHERE tick_key=? AND state=?',['unknown',$now,$s['active_tick_key'],'running']);
            $tick=hash('sha256',$s['activation']['activation_sha256'].'|'.$now.'|'.bin2hex(random_bytes(32)));
            $this->write('INSERT INTO max2_common_ticks_v2 (tick_key,activation_sha256,state,cursor_start,cursor_end,started_at_unix) VALUES (?,?,?,?,?,?)',
                [$tick,$s['activation']['activation_sha256'],'running',$s['cursor'],$s['cursor'],$now]);
            $this->write('UPDATE max2_common_settings_v2 SET active_tick_key=?,lease_until_unix=? WHERE id=1',[$tick,$now+self::LEASE_SECONDS]);
            $saved=$this->normalize($this->lockedSettings());
            if ($saved['active_tick_key']!==$tick||$saved['lease_until_unix']!==$now+self::LEASE_SECONDS) throw new DomainException('TICK_READBACK_CONFLICT');
            return ['status'=>'ACQUIRED','tick_key'=>$tick,'activation'=>$s['activation'],'cursor'=>$s['cursor'],
                'lease_until_unix'=>$now+self::LEASE_SECONDS,'provider_calls'=>0,'call_permitted'=>false];
        });
    }
    private function tick(string $tickKey,int $now): array
    {
        if (!self::hash($tickKey)) throw new DomainException('EXACT_TICK_REQUIRED');
        $s=$this->normalize($this->lockedSettings());
        if ($s['activation']===null||$s['mode']==='off'||$s['active_tick_key']!==$tickKey||$s['lease_until_unix']<=$now) throw new DomainException('TICK_LEASE_NOT_OWNED');
        return $s;
    }
    public function advanceProfile(string $tickKey,string $profileId,array $outcome,int $now): array
    {
        if (!Max2ValueBindingPolicyV2::positiveId($profileId)||!in_array($outcome['status']??null,['RESERVED','EVALUATED','ALREADY_RECORDED','HOLD','UNKNOWN'],true)) return self::hold('PROFILE_OUTCOME_UNPROVED');
        $reason=$outcome['reason']??$outcome['status'];
        if (!is_string($reason)||preg_match('/\A[A-Z_]{1,80}\z/D',$reason)!==1) $reason='PROFILE_OUTCOME_RECORDED';
        return $this->tx(function() use($tickKey,$profileId,$outcome,$reason,$now):array {
            $s=$this->tick($tickKey,$now);
            if (self::compare($profileId,$s['cursor'])<=0) return self::hold('PROFILE_CURSOR_NOT_FORWARD');
            $this->write('INSERT INTO max2_common_profile_work_v2 (activation_sha256,profile_id,tick_key,outcome,reason,recorded_at_unix) VALUES (?,?,?,?,?,?)',
                [$s['activation']['activation_sha256'],$profileId,$tickKey,$outcome['status'],$reason,$now]);
            $this->write('UPDATE max2_common_settings_v2 SET profile_cursor=? WHERE id=1 AND active_tick_key=?',[$profileId,$tickKey]);
            $this->write('UPDATE max2_common_ticks_v2 SET cursor_end=? WHERE tick_key=?',[$profileId,$tickKey]);
            if ($this->normalize($this->lockedSettings())['cursor']!==$profileId) throw new DomainException('CURSOR_READBACK_CONFLICT');
            return ['status'=>'ADVANCED','cursor'=>$profileId,'provider_calls'=>0,'call_permitted'=>false];
        });
    }
    public function consumeLookupIntent(string $tickKey,string $profileId,string $action,string $requestSha,int $now): array
    {
        if (!Max2ValueBindingPolicyV2::positiveId($profileId)||!in_array($action,['list','recheck'],true)||!self::hash($requestSha)) return self::hold('LOOKUP_INTENT_UNPROVED');
        return $this->tx(function() use($tickKey,$profileId,$action,$requestSha,$now):array {
            $s=$this->tick($tickKey,$now); $epoch=$s['activation']['activation_sha256'];
            if (self::compare($profileId,$s['cursor'])<=0) return self::hold('LOOKUP_PROFILE_NOT_PROSPECTIVE');
            $key=hash('sha256','max2/common/lookup-intent/v2|'.$epoch.'|'.$profileId.'|'.$action);
            $prior=$this->query('SELECT intent_key FROM max2_common_lookup_intents_v2 WHERE activation_sha256=? AND profile_id=? AND action=?'.$this->lock,[$epoch,$profileId,$action])->fetchColumn();
            if ($prior!==false) return ['status'=>'ALREADY_CONSUMED','call_permitted'=>false,'provider_calls'=>0];
            $this->write('INSERT INTO max2_common_lookup_intents_v2 (intent_key,activation_sha256,profile_id,action,tick_key,request_sha256,state,consumed_at_unix) VALUES (?,?,?,?,?,?,?,?)',
                [$key,$epoch,$profileId,$action,$tickKey,$requestSha,'consumed',$now]);
            $saved=$this->query('SELECT request_sha256,state FROM max2_common_lookup_intents_v2 WHERE intent_key=?'.$this->lock,[$key])->fetch(PDO::FETCH_ASSOC);
            if (!is_array($saved)||$saved['request_sha256']!==$requestSha||$saved['state']!=='consumed') throw new DomainException('LOOKUP_INTENT_READBACK_CONFLICT');
            return ['status'=>'CONSUMED','intent_key'=>$key,'call_permitted'=>true,'provider_calls'=>0];
        });
    }
    public function finishLookupIntent(string $tickKey,string $profileId,string $action,string $requestSha,?string $responseSha,string $status,int $now): array
    {
        if (!Max2ValueBindingPolicyV2::positiveId($profileId)||!in_array($action,['list','recheck'],true)||!self::hash($requestSha)
            ||!in_array($status,['observed','unknown'],true)||($status==='observed'?!self::hash($responseSha):$responseSha!==null)) return self::hold('LOOKUP_RESULT_UNPROVED');
        return $this->tx(function() use($tickKey,$profileId,$action,$requestSha,$responseSha,$status,$now):array {
            $s=$this->tick($tickKey,$now);
            $row=$this->query('SELECT * FROM max2_common_lookup_intents_v2 WHERE activation_sha256=? AND profile_id=? AND action=?'.$this->lock,
                [$s['activation']['activation_sha256'],$profileId,$action])->fetch(PDO::FETCH_ASSOC);
            if (!is_array($row)||$row['tick_key']!==$tickKey||$row['request_sha256']!==$requestSha||$row['state']!=='consumed') return self::hold('LOOKUP_RESULT_IDENTITY_CONFLICT');
            $this->write('UPDATE max2_common_lookup_intents_v2 SET state=?,response_sha256=?,finished_at_unix=? WHERE intent_key=?',[$status,$responseSha,$now,$row['intent_key']]);
            $saved=$this->query('SELECT state,response_sha256 FROM max2_common_lookup_intents_v2 WHERE intent_key=?'.$this->lock,[$row['intent_key']])->fetch(PDO::FETCH_ASSOC);
            if (!is_array($saved)||$saved['state']!==$status||$saved['response_sha256']!==$responseSha) throw new DomainException('LOOKUP_RESULT_READBACK_CONFLICT');
            return ['status'=>'RECORDED','call_permitted'=>false,'provider_calls'=>0];
        });
    }
    public function finishTick(string $tickKey,array $summary,int $now): array
    {
        $allowed=['profiles_read','reserved','evaluated','held','unknown','lookup_list','lookup_recheck','goal_reads','send_attempts','provider_polls'];
        if (count($summary)!==count($allowed)) return self::hold('TICK_SUMMARY_UNPROVED');
        foreach ($allowed as $key) if (!is_int($summary[$key]??null)||$summary[$key]<0||$summary[$key]>100) return self::hold('TICK_SUMMARY_UNPROVED');
        $json=json_encode($summary,JSON_THROW_ON_ERROR);
        return $this->tx(function() use($tickKey,$json,$summary,$now):array {
            $s=$this->normalize($this->lockedSettings());
            if ($s['active_tick_key']!==$tickKey||!self::hash($tickKey)) return self::hold('TICK_LEASE_NOT_OWNED');
            $this->write('UPDATE max2_common_ticks_v2 SET state=?,finished_at_unix=?,summary_json=? WHERE tick_key=? AND state=?',['completed',$now,$json,$tickKey,'running']);
            $this->write('UPDATE max2_common_settings_v2 SET active_tick_key=NULL,lease_until_unix=0,last_tick_at_unix=?,last_tick_summary_json=? WHERE id=1',[$now,$json]);
            return ['status'=>'COMPLETED','summary'=>$summary,'provider_calls'=>0,'call_permitted'=>false];
        });
    }
}
