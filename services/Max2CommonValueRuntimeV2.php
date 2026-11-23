<?php
declare(strict_types=1);
require_once __DIR__.'/Max2CommonValueStateV2.php';

/** Optional integration of the two explicitly authorized new goals only. */
final class Max2CommonValueRuntimeV2
{
    public const ENTRY_PRODUCER_SHA256 = 'd9a17f8b0607f0db24c49e1d174d1ad8146b70b7fc75b176b9636c78a43596ce';
    private static function scope(): bool
    {
        return class_exists('ProjectConfig') && ProjectConfig::projectId()==='anytour'
            && class_exists('ConversationDb') && ConversationDb::isConfigured();
    }
    private static function map(): string { return dirname(__DIR__).'/resources/max2-common-v2/canonical-map-v1055.json'; }
    private static function entryClockAdmitted(): bool
    {
        // Both native entry INSERTs explicitly use this producer's gmdate().
        // A new producer must be reviewed before its timestamps enter our window.
        $path=__DIR__.'/ChannelPostRegistryService.php';
        return is_file($path) && !is_link($path) && hash_file('sha256',$path)===self::ENTRY_PRODUCER_SHA256;
    }
    private static function activation(PDO $pdo): ?array
    {
        $s=(new Max2CommonValueStateV2($pdo))->settings();
        return in_array($s['mode'] ?? null,['collect','send'],true) && is_array($s['activation'] ?? null) ? $s['activation'] : null;
    }
    /** Called after the existing canonical entry transaction has committed. */
    public static function captureEntry(array $record,array $conversation,array $resolved,string $eventKey): void
    {
        try {
            if (PHP_SAPI==='cli' || !self::scope() || !self::entryClockAdmitted()) return;
            $pdo=ConversationDb::connection(); $activation=self::activation($pdo); if ($activation===null) return;
            require_once __DIR__.'/Max2CommonEntryCaptureV2.php';
            (new Max2CommonEntryCaptureV2($pdo,$activation,self::map()))->captureCurrentHttp($record,$conversation,$resolved,$eventKey,time());
        } catch (Throwable $ignored) { /* This optional observer never changes the customer result. */ }
    }
    /** Called only after the existing MAX handler has returned normally. No source/provider request. */
    public static function captureMessage(): void
    {
        try {
            if (PHP_SAPI==='cli' || !self::scope() || !self::entryClockAdmitted() || http_response_code()!==200) return;
            $pdo=ConversationDb::connection(); $activation=self::activation($pdo); if ($activation===null) return;
            require_once __DIR__.'/Max2CommonMessageCaptureV2.php';
            (new Max2CommonMessageCaptureV2($pdo,$activation))->captureCurrentHttp(time());
        } catch (Throwable $ignored) { /* The existing webhook reply remains complete. */ }
    }
    private static function rows(PDO $pdo,string $sql,array $args): array
    {
        $q=$pdo->prepare(preg_replace('/\ASELECT /','SELECT /*+ MAX_EXECUTION_TIME(250) */ ',$sql,1));
        $q->execute($args);$rows=$q->fetchAll(PDO::FETCH_ASSOC);$q->closeCursor();return $rows;
    }
    /** Reuses the installed scheduler after its existing v1 work; default OFF. */
    public static function tick(): array
    {
        $off=['status'=>'OFF','provider_calls'=>0,'old_stream_invoked'=>false];
        try {
            if (PHP_SAPI!=='cli' || !self::scope()) return $off;
            $pdo=ConversationDb::connection();$state=new Max2CommonValueStateV2($pdo);$s=$state->settings();
            if (($s['status'] ?? null)!=='OBSERVED') return ['status'=>'HOLD','reason'=>'V2_SETTINGS_UNAVAILABLE','provider_calls'=>0,'old_stream_invoked'=>false];
            if (($s['mode'] ?? null)==='off') return $off;
            if (!in_array($s['mode'] ?? null,['collect','send'],true) || !is_array($s['activation'] ?? null)
                || !self::entryClockAdmitted()) return ['status'=>'HOLD','reason'=>'V2_RUNTIME_SOURCE_UNADMITTED','provider_calls'=>0,'old_stream_invoked'=>false];
            require_once __DIR__.'/Max2CommonValueWorkerV2.php';
            require_once __DIR__.'/Max2ModeledValueReaderV2.php';
            require_once __DIR__.'/Max2CommonValueBindingV2.php';
            require_once __DIR__.'/Max2CommonIdentityReadV2.php';
            require_once __DIR__.'/Max2ValueLookupHttpV2.php';
            require_once __DIR__.'/MaxTrackerLookupConfig.php';
            require_once dirname(__DIR__).'/maxsearchclass.php';
            $lookup=new Max2ValueLookupClientV2(new Max2ValueLookupHttpV2(),[MaxTrackerLookupConfig::class,'privateKey']);
            $ledger=static fn(array $a):Max2CommonValueRepositoryV2=>new Max2CommonValueRepositoryV2($pdo,$a);
            $provider=static function():Max2CommonValueMetrikaClientV2 {
                if (!defined('METRIKA_COUNTER_ID') || (string)METRIKA_COUNTER_ID!=='98615635'
                    || !defined('METRIKA_OAUTH_TOKEN') || !is_string(METRIKA_OAUTH_TOKEN)) throw new RuntimeException('V2_PROVIDER_SCOPE_UNAVAILABLE');
                return new Max2CommonValueMetrikaClientV2(METRIKA_OAUTH_TOKEN);
            };
            $page=static function(string $cursor,int $limit,int $floor) use($pdo):array {
                if (!Max2CommonValueStateV2::cursor($cursor) || $limit<1 || $limit>3) throw new RuntimeException('V2_PAGE_SCOPE');
                $rows=self::rows($pdo,"SELECT id FROM modeled_conversion_profile_versions WHERE project_key='anytour' AND platform IN ('max','telegram') AND id>? AND observed_at_unix>=? ORDER BY id ASC LIMIT ".$limit,[$cursor,$floor]);
                return array_map(static fn(array $r):array=>['profile_id'=>(string)$r['id']],$rows);
            };
            $project=static function(array $row,Max2CommonValueLookupGatewayV2 $gateway,array $activation) use($pdo):array {
                $reader=new Max2ModeledValueReaderV2($pdo,static fn(string $chat):bool=>MaxSearchApi::metrikaGoalAllowed($chat));
                $saved=$reader->read($row['profile_id'],$activation['activated_at_unix'],time());
                if (($saved['status'] ?? null)!=='ADMITTED_SAVED_VALUE') return $saved;
                $subject=$saved['subject'];
                $accounts=self::rows($pdo,'SELECT inquiry_journal_id,activation_sha256 FROM max2_common_value_accounts_v2 WHERE counter_id=? AND target=? AND project_key=? AND platform=? AND customer_key=? AND conversation_id=? LIMIT 2',
                    [98615635,'anytour_modeled_value_v2_max2','anytour',$subject['platform'],$subject['customer_key'],$subject['conversation_id']]);
                if (count($accounts)>1 || (count($accounts)===1 && $accounts[0]['activation_sha256']!==$activation['activation_sha256']))
                    return ['status'=>'HOLD','reason'=>'OWN_INQUIRY_ACCOUNT_CONFLICT'];
                if ($accounts!==[] && (string)$accounts[0]['inquiry_journal_id']!==$saved['inquiry']['journal_id']) {
                    $saved=$reader->read($row['profile_id'],$activation['activated_at_unix'],time(),(string)$accounts[0]['inquiry_journal_id']);
                    if (($saved['status'] ?? null)!=='ADMITTED_SAVED_VALUE') return $saved;
                }
                $identity=new Max2CommonIdentityReadV2($pdo);
                $binder=new Max2CommonValueBindingV2($pdo,self::map(),static fn(array $value,string $key):?array=>$identity->read($value,$key,time()));
                return $binder->project($saved,$activation,$gateway,new Max2CommonValueRepositoryV2($pdo,$activation),time());
            };
            return (new Max2CommonValueWorkerV2($state,$ledger,$lookup,$provider,$page,$project))->tick();
        } catch (Throwable $ignored) {
            return ['status'=>'HOLD','reason'=>'V2_RUNTIME_UNAVAILABLE','provider_calls'=>null,'old_stream_invoked'=>false];
        }
    }
}
