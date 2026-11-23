<?php
declare(strict_types=1);

/**
 * One fixed, owner-authorized historical read. The outer admitted operator owns
 * authentication, installed-source verification and loading the canonical scope.
 * No public/request selector, worker, writer, provider or model generation.
 */
final class Max2OriginalHoldPairReader
{
    public const VERSION = 'max2_original_hold_pair_read_v1';
    public const ACTIVATED_AT = 1795387176;
    public const FINISHED_AT = [1795400583, 1795400885];
    public const EPOCH = '5afe07ec09092f928926fb64543af19fd5ab14b921eab1d22cf82f0fba91669f';
    public const INITIAL_PROFILE_FLOOR = '17653';
    public const PROFILE_PRODUCER_SHA256 = '722a0064f46f7f03fc78b3a8c830b136a6fd143029dad01f143b0651fafec21d';
    private const SOURCE_HASHES = [
        'InternalOwnerDialogueScope.php'=>'cf44c20f9f868d82c35c9e4491e060af4c8232105a0b59f89a377f78cecc57d4',
        'Max2ModeledValueReaderV2.php'=>'4ac74d360889db0776575219e8779f09e59129fd04bf93a1d4c1411351b233d3',
        'ModeledConversionProfiles.php'=>self::PROFILE_PRODUCER_SHA256,
    ];
    private const PRODUCER = '3a563c1b7921915fd8b17c08f585cde88127904170a086c291a5677d49cf365e';
    private const TARGET = 'anytour_modeled_value_v2_max2';
    private const MAX_PROFILE_BYTES = 131072;
    private const MAX_SELECTED_BYTES = 278528;
    private const MAX_NS = 5000000000;
    private const TICK = ['profiles_read'=>1,'reserved'=>0,'evaluated'=>0,'held'=>1,'unknown'=>0,
        'lookup_list'=>0,'lookup_recheck'=>0,'goal_reads'=>0,'send_attempts'=>0,'provider_polls'=>0];
    private const FAILURES = ['ACCESS_UNAVAILABLE','UNSUPPORTED_STORAGE','OUTER_TRANSACTION',
        'READ_TIME_BOUND','READ_BYTE_BOUND','READ_QUERY_BOUND','READ_ONLY_SNAPSHOT_UNAVAILABLE',
        'SETTINGS_SCOPE_UNPROVED','PAIR_NOT_EXACT_OR_NOT_CURRENTLY_ADMITTED','ORIGINAL_TICK_UNPROVED',
        'ORIGINAL_WORK_UNPROVED','PROFILE_SCOPE_OR_BOUND','PROFILE_JSON_INVALID',
        'PROFILE_NOT_CURRENT_ADMITTED_SOURCE','SUBJECT_NOT_ADMITTED','CURRENT_ACCESS_DENIED',
        'CURRENT_ACCESS_UNAVAILABLE','INSTALLED_SOURCE_UNPROVED','DIAGNOSIS_UNAVAILABLE',
        'DIAGNOSIS_PROJECTION_UNSAFE','SAVED_STORAGE_UNAVAILABLE','SNAPSHOT_ROLLBACK_UNCONFIRMED'];
    private bool $mysql;
    private $authorize;
    private $diagnose;
    private $clock;
    private int $began = 0;
    private int $selected = 0;
    private int $queries = 0;
    private array $recheck = [];

    /** Injected callbacks are internal test seams, never request authorization. */
    public function __construct(private PDO $pdo, callable $authorize, ?callable $diagnose = null,
        ?callable $monotonicClock = null)
    {
        $this->mysql = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
        $this->authorize = $authorize;
        $this->diagnose = $diagnose ?? static fn(array $w,array $s,int $floor,int $start,int $end):array =>
            Max2HoldCandidateDiagnosis::inspect($w,$s,$floor,$start,$end);
        $this->clock = $monotonicClock ?? static fn():int => hrtime(true);
    }

    public function read(): array
    {
        $this->began = ($this->clock)(); $this->selected = 0; $this->queries = 0; $this->recheck = [];
        $own = false; $sqlitePrevious = null; $out = null;
        try {
            $this->auth();
            $this->assertSources();
            if (!in_array($this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME),['mysql','sqlite'],true)) self::fail('UNSUPPORTED_STORAGE');
            if ($this->pdo->inTransaction()) self::fail('OUTER_TRANSACTION');
            if ($this->mysql) {
                $this->pdo->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
                $this->pdo->exec('SET TRANSACTION READ ONLY');
            } else {
                // Test/runtime SQLite gets the same enforced no-write property.
                $sqlitePrevious = (int)$this->pdo->query('PRAGMA query_only')->fetchColumn();
                $this->pdo->exec('PRAGMA query_only = ON');
            }
            if (!$this->pdo->beginTransaction()) self::fail('READ_ONLY_SNAPSHOT_UNAVAILABLE');
            $own = true;
            $out = $this->inside();
            $this->slice();
        } catch (Max2OriginalHoldPairFailure $e) {
            $out = $this->unavailable(in_array($e->getMessage(),self::FAILURES,true)?$e->getMessage():'SAVED_STORAGE_UNAVAILABLE');
        } catch (Throwable $ignored) {
            $out = $this->unavailable('SAVED_STORAGE_UNAVAILABLE');
        } finally {
            try {
                if ($own) {
                    if (!$this->pdo->inTransaction() || !$this->pdo->rollBack()) self::fail('SNAPSHOT_ROLLBACK_UNCONFIRMED');
                }
            } catch (Throwable $ignored) {
                $out = $this->unavailable('SNAPSHOT_ROLLBACK_UNCONFIRMED');
            }
        }
        try {
            // A snapshot cannot observe a concurrent revocation. Fresh bounded
            // scalars after rollback are authorization only, never a candidate.
            if (($out['status']??null)==='OBSERVED') {
                foreach ($this->recheck as $row) $this->currentAccess($row);
                $this->assertSources();
            }
            $this->auth();
        }
        catch (Max2OriginalHoldPairFailure $e) {
            $out = $this->unavailable(in_array($e->getMessage(),self::FAILURES,true)?$e->getMessage():'ACCESS_UNAVAILABLE');
        } catch (Throwable $ignored) { $out = $this->unavailable('CURRENT_ACCESS_UNAVAILABLE'); }
        finally {
            $this->recheck = [];
            try { if ($sqlitePrevious !== null) $this->pdo->exec('PRAGMA query_only = '.($sqlitePrevious===0?'OFF':'ON')); }
            catch (Throwable $ignored) { $out = $this->unavailable('SNAPSHOT_ROLLBACK_UNCONFIRMED'); }
        }
        if (!is_array($out)) return $this->unavailable('SAVED_STORAGE_UNAVAILABLE');
        if (($out['status']??null)==='OBSERVED') {
            $out['snapshot_rolled_back'] = true;
            $out['current_access_rechecked_after_snapshot'] = true;
            $out['installed_source_hashes_verified'] = true;
            $out['queries'] = $this->queries; $out['selected_bytes'] = $this->selected;
        }
        return $out;
    }

    private function inside(): array
    {
        $settings = $this->query('SELECT project_key,counter_id,goal_id,target,producer_contract_sha256,activation_sha256,'
            .'activated_at_unix,initial_profile_max_id,profile_cursor FROM max2_common_settings_v2 WHERE id=1 LIMIT 2',[],2);
        if (count($settings)!==1) self::fail('SETTINGS_SCOPE_UNPROVED');
        $s=$settings[0]; $epoch=$s['activation_sha256']??null;
        if (($s['project_key']??null)!=='anytour'||(string)($s['counter_id']??'')!=='98615635'
            ||(string)($s['goal_id']??'')!=='687941321'||($s['target']??null)!==self::TARGET
            ||($s['producer_contract_sha256']??null)!==self::PRODUCER||$epoch!==self::EPOCH
            ||self::integer($s['activated_at_unix']??null)!==self::ACTIVATED_AT
            ||(string)($s['initial_profile_max_id']??'')!==self::INITIAL_PROFILE_FLOOR||!self::id($s['profile_cursor']??null,true)
            ||self::compare($s['profile_cursor'],$s['initial_profile_max_id'])<0) self::fail('SETTINGS_SCOPE_UNPROVED');
        $cast=$this->mysql?'CHAR':'TEXT';
        $profileBytes=$this->mysql?'OCTET_LENGTH(p.profile_json)':'LENGTH(CAST(p.profile_json AS BLOB))';
        $tickBytes=$this->mysql?'OCTET_LENGTH(t.summary_json)':'LENGTH(CAST(t.summary_json AS BLOB))';
        $sql='SELECT t.tick_key,t.activation_sha256 AS tick_epoch,t.state AS tick_state,t.cursor_start,t.cursor_end,'
            .'t.started_at_unix,t.finished_at_unix,CASE WHEN '.$tickBytes.'<=4096 THEN t.summary_json ELSE NULL END AS summary_json,'
            .'w.activation_sha256 AS work_epoch,w.profile_id,w.outcome,w.reason,w.recorded_at_unix,'
            .'p.id,p.project_key,p.platform,p.customer_key,p.conversation_id,p.observed_at_unix,p.input_sha256,'
            .$profileBytes.' AS profile_bytes,c.customer_channel_id,c.external_chat_id,'
            .'cc.external_user_id,cc.external_chat_id AS channel_chat'
            .' FROM max2_common_profile_work_v2 w JOIN max2_common_ticks_v2 t ON t.tick_key=w.tick_key AND HEX(t.tick_key)=HEX(w.tick_key)'
            .' AND w.activation_sha256=t.activation_sha256 AND HEX(w.activation_sha256)=HEX(t.activation_sha256)'
            .' JOIN modeled_conversion_profile_versions p ON p.id=w.profile_id AND HEX(CAST(p.id AS '.$cast.'))=HEX(w.profile_id)'
            .$this->nativeJoin(). ' WHERE w.activation_sha256=? AND HEX(w.activation_sha256)=HEX(?) AND t.finished_at_unix IN (?,?)'
            .$this->nativeWhere().' ORDER BY t.finished_at_unix,p.id LIMIT 3';
        $rows=$this->query($sql,[$epoch,$epoch,...self::FINISHED_AT],3);
        if (count($rows)!==2) self::fail('PAIR_NOT_EXACT_OR_NOT_CURRENTLY_ADMITTED');
        $seen=[]; $cases=[];
        foreach ($rows as $i=>$row) {
            $this->metadata($row,$s,$epoch,self::FINISHED_AT[$i]);
            if (isset($seen[(string)$row['profile_id']])) self::fail('ORIGINAL_WORK_UNPROVED');
            $seen[(string)$row['profile_id']]=true;
            $this->currentAccess($row);
            $this->recheck[]=array_intersect_key($row,array_flip([
                'platform','customer_key','conversation_id','external_user_id','external_chat_id','channel_chat']));
        }
        foreach ($rows as $i=>$row) {
            $len=$this->mysql?'OCTET_LENGTH(profile_json)':'LENGTH(CAST(profile_json AS BLOB))';
            $jsonRows=$this->query('SELECT id,CASE WHEN '.$len.'<='.self::MAX_PROFILE_BYTES.' THEN profile_json ELSE NULL END AS profile_json'
                .' FROM modeled_conversion_profile_versions WHERE id=? AND HEX(CAST(id AS '.$cast.'))=HEX(?) LIMIT 2',
                [(string)$row['profile_id'],(string)$row['profile_id']],2);
            if (count($jsonRows)!==1||(string)$jsonRows[0]['id']!==(string)$row['profile_id']
                ||!is_string($jsonRows[0]['profile_json'])||strlen($jsonRows[0]['profile_json'])!==(int)$row['profile_bytes']) self::fail('PROFILE_SCOPE_OR_BOUND');
            try { $wrapper=json_decode($jsonRows[0]['profile_json'],true,64,JSON_THROW_ON_ERROR); }
            catch (Throwable $ignored) { self::fail('PROFILE_JSON_INVALID'); }
            unset($jsonRows);
            $subject=['project_key'=>'anytour','platform'=>$row['platform'],'customer_key'=>(string)$row['customer_key'],
                'conversation_id'=>(string)$row['conversation_id']];
            if (!is_array($wrapper)||array_is_list($wrapper)||($wrapper['access_status']??null)!=='allowed'
                ||!self::same($wrapper['subject']??null,$subject)
                ||($wrapper['processing']['source_sha256']??null)!==self::PROFILE_PRODUCER_SHA256
                ||($wrapper['processing']['assessment_kind']??null)!=='live') self::fail('PROFILE_NOT_CURRENT_ADMITTED_SOURCE');
            try {
                $diagnosis=($this->diagnose)($wrapper,$subject,self::ACTIVATED_AT,(int)$row['started_at_unix'],(int)$row['recorded_at_unix']);
            } catch (Throwable $ignored) { self::fail('DIAGNOSIS_UNAVAILABLE'); }
            unset($wrapper,$subject);
            try { if (Max2HoldCandidateDiagnosis::validateProjection($diagnosis)!==true) self::fail('DIAGNOSIS_PROJECTION_UNSAFE'); }
            catch (Throwable $ignored) { self::fail('DIAGNOSIS_PROJECTION_UNSAFE'); }
            $this->slice();
            $cases[]=['case'=>$i===0?'A':'B','saved_outcome'=>'HOLD','saved_reason'=>'SAVED_VALUE_UNAVAILABLE',
                'tick_started_at'=>gmdate('Y-m-d\TH:i:s\Z',(int)$row['started_at_unix']),
                'hold_recorded_at'=>gmdate('Y-m-d\TH:i:s\Z',(int)$row['recorded_at_unix']),
                'tick_finished_at'=>gmdate('Y-m-d\TH:i:s\Z',(int)$row['finished_at_unix']),
                'current_native_scope_admitted'=>true,'profile_source_admitted'=>true,'diagnosis'=>$diagnosis];
        }
        return ['version'=>self::VERSION,'status'=>'OBSERVED','fixed_historical_pair'=>true,
            'activation_at'=>gmdate('Y-m-d\TH:i:s\Z',self::ACTIVATED_AT),'selected_profiles'=>2,
            'cases'=>$cases,'read_only_snapshot'=>true,'snapshot_rolled_back'=>false,
            'queries'=>$this->queries,'selected_bytes'=>$this->selected,
            'writes'=>0,'provider_requests'=>0,'model_calls'=>0,'worker_calls'=>0,'conversion_uploads'=>0,
            'financial_delivery_permission'=>false];
    }

    private function metadata(array $r,array $s,string $epoch,int $finished): void
    {
        $start=self::integer($r['started_at_unix']??null);$end=self::integer($r['recorded_at_unix']??null);
        if (!self::sha($r['tick_key']??null)||($r['tick_epoch']??null)!==$epoch||($r['tick_state']??null)!=='completed'
            ||self::integer($r['finished_at_unix']??null)!==$finished||$start===null||$end===null
            ||$start<self::ACTIVATED_AT||$end<$start||$finished<$end||$finished-$start>300
            ||!self::id($r['cursor_start']??null,true)||!self::id($r['cursor_end']??null)
            ||!is_string($r['summary_json']??null)) self::fail('ORIGINAL_TICK_UNPROVED');
        try { $summary=json_decode($r['summary_json'],true,8,JSON_THROW_ON_ERROR); }
        catch (Throwable $ignored) { self::fail('ORIGINAL_TICK_UNPROVED'); }
        if (!is_array($summary)||count($summary)!==count(self::TICK)) self::fail('ORIGINAL_TICK_UNPROVED');
        foreach (self::TICK as $key=>$expected) if (($summary[$key]??null)!==$expected) self::fail('ORIGINAL_TICK_UNPROVED');
        if (($r['work_epoch']??null)!==$epoch||!self::id($r['profile_id']??null)||!self::id($r['id']??null)
            ||(string)$r['id']!==(string)$r['profile_id']||($r['outcome']??null)!=='HOLD'
            ||($r['reason']??null)!=='SAVED_VALUE_UNAVAILABLE'||(string)$r['cursor_end']!==(string)$r['profile_id']
            ||self::compare($r['cursor_start'],$r['profile_id'])>=0
            ||self::compare($r['profile_id'],$s['initial_profile_max_id'])<=0
            ||self::compare($r['profile_id'],$s['profile_cursor'])>0) self::fail('ORIGINAL_WORK_UNPROVED');
        $observed=self::integer($r['observed_at_unix']??null);$bytes=self::integer($r['profile_bytes']??null);
        if (($r['project_key']??null)!=='anytour'||!in_array($r['platform']??null,['max','telegram'],true)
            ||!self::id($r['customer_key']??null)||!self::id($r['conversation_id']??null)
            ||!self::sha($r['input_sha256']??null)||$observed===null||$observed<self::ACTIVATED_AT||$observed>$end
            ||$bytes===null||$bytes<2||$bytes>self::MAX_PROFILE_BYTES) self::fail('PROFILE_SCOPE_OR_BOUND');
        $actor=$r['external_user_id']??null;$chat=$r['external_chat_id']??null;
        if (!self::id($r['customer_channel_id']??null)||!is_string($actor)||!self::id($actor)
            ||!is_string($chat)||$chat!==($r['platform']==='max'?'-'.$actor:$actor)
            ||($r['channel_chat']??null)!==$chat) self::fail('SUBJECT_NOT_ADMITTED');
    }

    private function nativeJoin(): string
    {
        $cast=$this->mysql?'CHAR':'TEXT';
        return ' JOIN conversations c ON c.id=p.conversation_id AND HEX(CAST(c.id AS '.$cast.'))=HEX(CAST(p.conversation_id AS '.$cast.'))'
            .' AND HEX(c.project_key)=HEX(p.project_key) AND HEX(c.channel)=HEX(p.platform)'
            .' AND HEX(CAST(c.customer_id AS '.$cast.'))=HEX(p.customer_key)'
            .' JOIN customer_channels cc ON cc.id=c.customer_channel_id AND cc.customer_id=c.customer_id'
            .' AND HEX(cc.project_key)=HEX(c.project_key) AND HEX(cc.channel)=HEX(c.channel) JOIN customers cu ON cu.id=c.customer_id';
    }
    private function nativeWhere(): string
    {
        return " AND HEX(p.project_key)=HEX('anytour') AND p.platform IN ('max','telegram') AND c.is_test=0 AND NOT "
            .InternalOwnerDialogueScope::conversationSql('c');
    }
    /** Existing latest exact-subject access scalar fence; never loads its JSON. */
    private function currentAccess(array $r): void
    {
        $len=$this->mysql?'OCTET_LENGTH(p.profile_json)':'LENGTH(CAST(p.profile_json AS BLOB))';
        $safe='CASE WHEN '.$len.'<='.self::MAX_PROFILE_BYTES." AND JSON_VALID(p.profile_json) THEN p.profile_json ELSE '{}' END";
        $status=$this->mysql?"JSON_UNQUOTE(JSON_EXTRACT($safe,'$.access_status'))":"JSON_EXTRACT($safe,'$.access_status')";
        $statusLen=$this->mysql?'OCTET_LENGTH('.$status.')':'LENGTH(CAST('.$status.' AS BLOB))';
        $cast=$this->mysql?'CHAR':'TEXT';
        $force=$this->mysql?' FORCE INDEX (ix_profile_scope_time)':'';
        $sql='SELECT CASE WHEN '.$statusLen.'<=32 THEN '.$status.' ELSE NULL END AS access_status'
            .' FROM modeled_conversion_profile_versions p'.$force.$this->nativeJoin()
            .' WHERE p.project_key=? AND HEX(p.project_key)=HEX(?) AND p.platform=? AND HEX(p.platform)=HEX(?)'
            .' AND p.customer_key=? AND HEX(p.customer_key)=HEX(?)'
            .' AND p.conversation_id=? AND HEX(CAST(p.conversation_id AS '.$cast.'))=HEX(?)'
            .$this->nativeWhere()
            .' AND HEX(cc.external_user_id)=HEX(?) AND HEX(c.external_chat_id)=HEX(?) AND HEX(cc.external_chat_id)=HEX(?)'
            .' ORDER BY p.id DESC LIMIT 1';
        try {
            $rows=$this->query($sql,['anytour','anytour',$r['platform'],$r['platform'],
                (string)$r['customer_key'],(string)$r['customer_key'],(string)$r['conversation_id'],(string)$r['conversation_id'],
                $r['external_user_id'],$r['external_chat_id'],$r['channel_chat']],1);
        } catch (Max2OriginalHoldPairFailure $e) { throw $e; }
        catch (Throwable $ignored) { self::fail('CURRENT_ACCESS_UNAVAILABLE'); }
        if (count($rows)!==1) self::fail('CURRENT_ACCESS_UNAVAILABLE');
        $status=$rows[0]['access_status']??null;
        if (in_array($status,['blocked','revoked','denied'],true)) self::fail('CURRENT_ACCESS_DENIED');
        if ($status!=='allowed') self::fail('CURRENT_ACCESS_UNAVAILABLE');
    }
    private function assertSources(): void
    {
        try {
            $reflection=new ReflectionClass(InternalOwnerDialogueScope::class);
            $scope=$reflection->getFileName();
            if (!is_string($scope)||!str_ends_with($scope,'/InternalOwnerDialogueScope.php')) self::fail('INSTALLED_SOURCE_UNPROVED');
            $dir=dirname($scope);
            foreach (self::SOURCE_HASHES as $name=>$hash) {
                $path=$dir.'/'.$name; clearstatcache(true,$path);
                if (!is_file($path)||is_link($path)||realpath($path)!==$path||hash_file('sha256',$path)!==$hash)
                    self::fail('INSTALLED_SOURCE_UNPROVED');
            }
        } catch (Throwable $ignored) { self::fail('INSTALLED_SOURCE_UNPROVED'); }
        $this->slice();
    }
    private function query(string $sql,array $args,int $maxRows): array
    {
        $this->auth();
        if (!str_starts_with($sql,'SELECT ')||++$this->queries>8) self::fail('READ_QUERY_BOUND');
        if ($this->mysql) $sql='SELECT /*+ MAX_EXECUTION_TIME(250) */ '.substr($sql,7);
        $q=$this->pdo->prepare($sql);$q->execute($args);$rows=[];
        try {
            while (($row=$q->fetch(PDO::FETCH_ASSOC))!==false) {
                if (count($rows)>=$maxRows) self::fail('READ_QUERY_BOUND');
                foreach ($row as $v) $this->selected+=is_string($v)?strlen($v):(is_int($v)?20:($v===null?4:32));
                $this->slice();$rows[]=$row;
            }
        } finally { $q->closeCursor(); }
        return $rows;
    }
    private function auth(): void
    {
        $this->slice();
        try { ($this->authorize)(); }
        catch (Throwable $ignored) { self::fail('ACCESS_UNAVAILABLE'); }
        $this->slice();
    }
    private function slice(): void
    {
        if (($this->clock)()-$this->began>self::MAX_NS) self::fail('READ_TIME_BOUND');
        if ($this->selected>self::MAX_SELECTED_BYTES) self::fail('READ_BYTE_BOUND');
    }
    private static function id(mixed $v,bool $zero=false): bool
    { return (is_int($v)||is_string($v))&&preg_match('/\A'.($zero?'(?:0|[1-9][0-9]{0,19})':'[1-9][0-9]{0,19}').'\z/D',(string)$v)===1; }
    private static function sha(mixed $v): bool
    { return is_string($v)&&preg_match('/\A[a-f0-9]{64}\z/D',$v)===1; }
    private static function integer(mixed $v): ?int
    { return (is_int($v)||is_string($v))&&preg_match('/\A(?:0|[1-9][0-9]{0,11})\z/D',(string)$v)===1?(int)$v:null; }
    private static function compare(mixed $a,mixed $b): int
    { return strlen((string)$a)<=>strlen((string)$b)?:strcmp((string)$a,(string)$b); }
    private static function same(mixed $a,array $b): bool
    { if (!is_array($a)) return false; foreach (['project_key','platform','customer_key','conversation_id'] as $k) if (($a[$k]??null)!==$b[$k]) return false; return true; }
    private static function fail(string $code): never
    { throw new Max2OriginalHoldPairFailure($code); }
    private function unavailable(string $reason): array
    { return ['version'=>self::VERSION,'status'=>'UNAVAILABLE','reason'=>$reason,'fixed_historical_pair'=>true,
        'writes'=>0,'provider_requests'=>0,'model_calls'=>0,'worker_calls'=>0,'conversion_uploads'=>0,'financial_delivery_permission'=>false]; }
}

final class Max2OriginalHoldPairFailure extends RuntimeException {}
