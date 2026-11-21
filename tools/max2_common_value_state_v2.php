<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

/**
 * Finite operator CLI for the NEW common-goal state only. Authorization belongs
 * to the existing admitted CLI/SSH caller and its reviewed outer operation.
 * Source/request/epoch hashes here are provenance and preconditions, not grants.
 */
final class Max2CommonValueStateCliV2
{
    public const MAX_REQUEST_BYTES = 8192;
    public const TARGET = 'anytour_modeled_value_v2_max2';
    public const TABLES = ['max2_common_entry_frames_v2','max2_common_lookup_intents_v2','max2_common_profile_work_v2',
        'max2_common_settings_v2','max2_common_ticks_v2','max2_common_value_accounts_v2','max2_common_value_events_v2'];
    private const OPS = ['status','activate','off','collect','send'];
    private static function sha(mixed $v, int $length = 64): bool
    { return is_string($v) && preg_match('/\A[a-f0-9]{'.$length.'}\z/D',$v) === 1; }
    private static function request(array $r): void
    {
        $keys = array_keys($r); sort($keys);
        $want = ['version','operation','project_key','counter_id','goal_id','target','source_sha','expected_epoch']; sort($want);
        if ($keys !== $want || ($r['version'] ?? null) !== 1 || !in_array($r['operation'] ?? null,self::OPS,true)
            || ($r['project_key'] ?? null) !== 'anytour' || ($r['counter_id'] ?? null) !== 98615635
            || ($r['goal_id'] ?? null) !== 687941321 || ($r['target'] ?? null) !== self::TARGET
            || !self::sha($r['source_sha'] ?? null,40)
            || (($r['expected_epoch'] ?? null) !== null && !self::sha($r['expected_epoch'])))
            throw new DomainException('V2_REQUEST_SCOPE');
        if ($r['operation'] === 'activate' && $r['expected_epoch'] !== null) throw new DomainException('V2_ACTIVATE_REQUIRES_EMPTY_EPOCH');
        if (in_array($r['operation'],['off','collect','send'],true) && !self::sha($r['expected_epoch'])) throw new DomainException('V2_EXACT_EPOCH_REQUIRED');
    }
    /** Exact argv: tool, operation, expected installed Git SHA, raw stdin SHA256. */
    public static function parse(array $argv, string $raw): array
    {
        if (count($argv) !== 4 || !in_array($argv[1] ?? null,self::OPS,true)
            || !self::sha($argv[2] ?? null,40) || !self::sha($argv[3] ?? null)) throw new DomainException('V2_ARGUMENTS');
        if ($raw === '' || strlen($raw) > self::MAX_REQUEST_BYTES || !hash_equals($argv[3],hash('sha256',$raw)))
            throw new DomainException('V2_REQUEST_BYTES_OR_HASH');
        try { $r = json_decode($raw,true,16,JSON_THROW_ON_ERROR); }
        catch (Throwable $ignored) { throw new DomainException('V2_REQUEST_JSON'); }
        if (!is_array($r) || array_is_list($r)) throw new DomainException('V2_REQUEST_JSON');
        self::request($r);
        $encoded = json_encode($r,JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        if (($raw !== $encoded && $raw !== $encoded."\n") || $r['operation'] !== $argv[1] || $r['source_sha'] !== $argv[2])
            throw new DomainException('V2_REQUEST_ARGV_OR_CANONICAL');
        return ['request'=>$r,'request_sha256'=>$argv[3]];
    }
    private static function boundedFile(string $path, int $limit): string
    {
        if (!is_file($path) || is_link($path) || filesize($path) < 1 || filesize($path) > $limit)
            throw new DomainException('V2_INSTALLED_GIT_UNAVAILABLE');
        $raw = file_get_contents($path,false,null,0,$limit+1);
        if (!is_string($raw) || $raw === '' || strlen($raw)>$limit) throw new DomainException('V2_INSTALLED_GIT_UNAVAILABLE');
        return $raw;
    }
    /** Reads actual Git metadata. No shell, env HEAD override or caller path option. */
    public static function installedHead(string $appRoot): string
    {
        $app = realpath($appRoot);
        if (!is_string($app) || !is_dir($app)) throw new DomainException('V2_INSTALLED_GIT_UNAVAILABLE');
        $entry = $app.'/.git';
        if (is_link($entry)) throw new DomainException('V2_INSTALLED_GIT_UNAVAILABLE');
        if (is_dir($entry)) $git = realpath($entry);
        else {
            $raw = self::boundedFile($entry,4096);
            if (preg_match('/\Agitdir: ([^\r\n\x00]+)\n?\z/D',$raw,$m) !== 1) throw new DomainException('V2_INSTALLED_GIT_UNAVAILABLE');
            $git = realpath(str_starts_with($m[1],'/') ? $m[1] : $app.'/'.$m[1]);
        }
        if (!is_string($git) || !is_dir($git)) throw new DomainException('V2_INSTALLED_GIT_UNAVAILABLE');
        $head = trim(self::boundedFile($git.'/HEAD',512));
        if (self::sha($head,40)) return $head;
        if (preg_match('/\Aref: (refs\/heads\/[A-Za-z0-9_\/.\-]{1,240})\z/D',$head,$m) !== 1
            || str_contains($m[1],'..') || str_contains($m[1],'//') || str_ends_with($m[1],'/')) throw new DomainException('V2_INSTALLED_GIT_UNAVAILABLE');
        $ref = $m[1]; $common = $git;
        if (file_exists($git.'/commondir')) {
            $relative = trim(self::boundedFile($git.'/commondir',4096));
            if ($relative === '' || str_contains($relative,"\0") || str_contains($relative,"\n") || str_contains($relative,"\r"))
                throw new DomainException('V2_INSTALLED_GIT_UNAVAILABLE');
            $common = realpath(str_starts_with($relative,'/') ? $relative : $git.'/'.$relative);
        }
        if (!is_string($common) || !is_dir($common)) throw new DomainException('V2_INSTALLED_GIT_UNAVAILABLE');
        $loose = $common.'/'.$ref;
        if (file_exists($loose)) {
            $actual = realpath($loose);
            if (!is_string($actual) || !str_starts_with($actual,$common.'/')) throw new DomainException('V2_INSTALLED_GIT_UNAVAILABLE');
            $value = trim(self::boundedFile($loose,128));
            if (!self::sha($value,40)) throw new DomainException('V2_INSTALLED_GIT_UNAVAILABLE');
            return $value;
        }
        $packed = self::boundedFile($common.'/packed-refs',1048576); $matches = [];
        foreach (explode("\n",$packed) as $line) {
            if ($line === '' || str_starts_with($line,'#') || str_starts_with($line,'^')) continue;
            if (preg_match('/\A([a-f0-9]{40}) (refs\/[^\s]+)\z/D',$line,$m) === 1 && $m[2] === $ref) $matches[] = $m[1];
        }
        if (count($matches) !== 1) throw new DomainException('V2_INSTALLED_GIT_UNAVAILABLE');
        return $matches[0];
    }
    /** Only observed table presence/engine, not complete schema validation. */
    public static function observedTables(array $rows): array
    {
        $base = ['status'=>'TABLES_UNAVAILABLE','expected_count'=>7,'observed_count'=>count($rows)<=8?count($rows):null,
            'full_schema_validation'=>false,'business_rows_read'=>0];
        if (!array_is_list($rows) || count($rows)!==7) return $base;
        $names=[];
        foreach ($rows as $row) {
            if (!is_array($row) || count($row)!==2 || !in_array($row['table_name'] ?? null,self::TABLES,true)
                || ($row['engine'] ?? null)!=='InnoDB') return $base;
            $names[]=$row['table_name'];
        }
        sort($names);
        if ($names!==self::TABLES) return $base;
        $base['status']='OBSERVED_TABLES_READY'; $base['tables']=$names; $base['engine']='InnoDB';
        return $base;
    }
    public static function tableReadiness(PDO $pdo): array
    {
        if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME)!=='mysql') throw new DomainException('V2_MYSQL_REQUIRED');
        $q=$pdo->prepare('SELECT /*+ MAX_EXECUTION_TIME(500) */ TABLE_NAME AS table_name,ENGINE AS engine FROM information_schema.TABLES'
            .' WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN (?,?,?,?,?,?,?) ORDER BY TABLE_NAME ASC LIMIT 8');
        $q->execute(self::TABLES); $rows=$q->fetchAll(PDO::FETCH_ASSOC); $q->closeCursor();
        return self::observedTables($rows);
    }
    private static function state(array $s): array
    {
        $out = array_intersect_key($s,array_flip(['status','reason','mode','activation','cursor','initial_profile_max_id','active_tick_key','lease_until_unix']));
        if (is_array($out['activation'] ?? null)) $out['activation'] = array_intersect_key($out['activation'],array_flip([
            'version','mode','counter_id','goal_id','target','producer_contract_sha256','activation_sha256','activated_at_unix','initial_profile_max_id']));
        if (strlen(json_encode($out,JSON_THROW_ON_ERROR)) > 4096) throw new DomainException('V2_STATE_READBACK_BOUND');
        return $out;
    }
    private static function result(array $r, string $requestSha, string $head, array $tables, array $state,
        string $status, ?string $reason, bool $mutation, bool $readback): array
    {
        return ['version'=>1,'operation'=>$r['operation'],'status'=>$status,'ok'=>in_array($status,['OBSERVED','ACTIVATED','ALREADY_ACTIVATED','MODE_SET'],true),
            'reason'=>$reason,'scope'=>['project_key'=>'anytour','counter_id'=>98615635,'goal_id'=>687941321,'target'=>self::TARGET],
            'admission'=>['source_sha'=>$r['source_sha'],'observed_source_sha'=>$head,'request_sha256'=>$requestSha,'expected_epoch'=>$r['expected_epoch']],
            'tables'=>$tables,'state'=>self::state($state),'readback'=>$readback,'mutation_attempted'=>$mutation,
            'automatic_retry'=>false,'provider_calls'=>0,'tick_invoked'=>false,'v1_invoked'=>false];
    }
    /** Internal test seam; no caller-supplied authorization flag or network action. */
    public static function dispatch(array $r, string $requestSha, string $head, array $tables, Max2CommonValueStateV2 $state): array
    {
        self::request($r);
        if (!self::sha($requestSha) || $head!==$r['source_sha']) throw new DomainException('V2_INSTALLED_SOURCE_MISMATCH');
        $before=$state->settings();
        if (($before['status'] ?? null)!=='OBSERVED') return self::result($r,$requestSha,$head,$tables,$before,'HOLD','V2_SETTINGS_UNAVAILABLE',false,false);
        if (($tables['status'] ?? null)!=='OBSERVED_TABLES_READY') return self::result($r,$requestSha,$head,$tables,$before,'HOLD','V2_OWN_TABLES_UNAVAILABLE',false,true);
        $current=$before['activation']['activation_sha256'] ?? null;
        if (($r['expected_epoch']!==null && $r['expected_epoch']!==$current) || ($r['operation']==='activate' && $current!==null))
            return self::result($r,$requestSha,$head,$tables,$before,'HOLD','V2_EXPECTED_EPOCH_CONFLICT',false,true);
        if ($r['operation']==='status') return self::result($r,$requestSha,$head,$tables,$before,'OBSERVED',null,false,true);
        $result=$r['operation']==='activate' ? $state->activate($r['source_sha'],$requestSha) : $state->setMode($r['operation'],$requestSha);
        $after=$state->settings(); $status=$result['status'] ?? 'UNKNOWN';
        if ($status==='HOLD') return self::result($r,$requestSha,$head,$tables,$after,'HOLD',$result['reason'] ?? 'V2_STATE_HELD',true,($after['status'] ?? null)==='OBSERVED');
        if ($status==='UNKNOWN') return self::result($r,$requestSha,$head,$tables,$after,'UNKNOWN','V2_STATE_COMMIT_UNCONFIRMED',true,($after['status'] ?? null)==='OBSERVED');
        $wantedMode=$r['operation']==='activate'?'collect':$r['operation'];
        $expectedResult=$r['operation']==='activate'?['ACTIVATED','ALREADY_ACTIVATED']:['MODE_SET'];
        $wantedEpoch=$r['operation']==='activate'?($result['settings']['activation']['activation_sha256'] ?? null):$r['expected_epoch'];
        if (!in_array($status,$expectedResult,true) || !self::sha($wantedEpoch) || ($after['status'] ?? null)!=='OBSERVED'
            || ($after['mode'] ?? null)!==$wantedMode || ($after['activation']['activation_sha256'] ?? null)!==$wantedEpoch
            || ($after['activation']['mode'] ?? null)!==$wantedMode)
            return self::result($r,$requestSha,$head,$tables,$after,'UNKNOWN','V2_STATE_READBACK_CONFLICT',true,false);
        return self::result($r,$requestSha,$head,$tables,$after,$status,null,true,true);
    }
    /** Actual CLI entry point. No business tick, v1 exporter, provider or runtime DDL. */
    public static function main(array $argv): int
    {
        ini_set('display_errors','0'); ini_set('log_errors','0'); error_reporting(0);
        $mutation=false; $buffer=false;
        try {
            $raw=stream_get_contents(STDIN,self::MAX_REQUEST_BYTES+1);
            if (!is_string($raw)) throw new DomainException('V2_REQUEST_BYTES_OR_HASH');
            $input=self::parse($argv,$raw); unset($raw); $request=$input['request'];
            $root=dirname(__DIR__); $head=self::installedHead($root);
            if ($head!==$request['source_sha']) throw new DomainException('V2_INSTALLED_SOURCE_MISMATCH');
            ob_start(); $buffer=true;
            require_once $root.'/config.php';
            require_once $root.'/services/ProjectAccessService.php';
            require_once $root.'/services/Max2CommonValueStateV2.php';
            if (ProjectConfig::projectId()!=='anytour' || !defined('METRIKA_COUNTER_ID') || (string)METRIKA_COUNTER_ID!=='98615635')
                throw new DomainException('V2_CONFIG_SCOPE');
            ProjectAccessService::initializeReadOnly();
            $pdo=ConversationDb::connection(); $tables=self::tableReadiness($pdo); $state=new Max2CommonValueStateV2($pdo);
            $bootstrap=ob_get_clean(); $buffer=false;
            if ($bootstrap!=='') throw new DomainException('V2_BOOTSTRAP_OUTPUT');
            if (self::installedHead($root)!==$head) throw new DomainException('V2_INSTALLED_SOURCE_MISMATCH');
            $mutation=$request['operation']!=='status';
            $out=self::dispatch($request,$input['request_sha256'],$head,$tables,$state);
            if (self::installedHead($root)!==$head) {
                $out['status']=$out['mutation_attempted']?'UNKNOWN':'HOLD'; $out['ok']=false;
                $out['reason']='V2_INSTALLED_SOURCE_CHANGED'; $out['readback']=false;
            }
            echo json_encode($out,JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n";
            return $out['ok']?0:($out['status']==='UNKNOWN'?3:2);
        } catch (Throwable $error) {
            if ($buffer) ob_end_clean();
            $allowed=['V2_ARGUMENTS','V2_REQUEST_BYTES_OR_HASH','V2_REQUEST_JSON','V2_REQUEST_SCOPE','V2_ACTIVATE_REQUIRES_EMPTY_EPOCH',
                'V2_EXACT_EPOCH_REQUIRED','V2_REQUEST_ARGV_OR_CANONICAL','V2_INSTALLED_GIT_UNAVAILABLE','V2_INSTALLED_SOURCE_MISMATCH',
                'V2_MYSQL_REQUIRED','V2_STATE_READBACK_BOUND','V2_CONFIG_SCOPE','V2_BOOTSTRAP_OUTPUT'];
            $reason=$error instanceof DomainException && in_array($error->getMessage(),$allowed,true)?$error->getMessage():'V2_STATE_CLI_UNAVAILABLE';
            echo json_encode(['version'=>1,'status'=>$mutation?'UNKNOWN':'HOLD','ok'=>false,'reason'=>$reason,
                'mutation_attempted'=>$mutation,'automatic_retry'=>false,'provider_calls'=>0,'tick_invoked'=>false,'v1_invoked'=>false],JSON_THROW_ON_ERROR)."\n";
            return $mutation?3:2;
        }
    }
}

if (realpath((string)($_SERVER['SCRIPT_FILENAME'] ?? '')) === realpath(__FILE__)) exit(Max2CommonValueStateCliV2::main($argv));
