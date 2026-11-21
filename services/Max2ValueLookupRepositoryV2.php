<?php
declare(strict_types=1);
require_once __DIR__ . '/Max2ValueLookupProtocolV2.php';

/** New SELECT-only repository. No Bitrix bootstrap, credential loading, claim or sender. */
final class Max2ValueLookupRepositoryV2
{
    private PDO $pdo;
    private string $admittedProducerContract;
    private Closure $clock;
    private const COLUMNS = ['ID', 'UF_CHAT_ID', 'UF_CLIENT_ID', 'UF_SOURCE', 'UF_METRIC_SOURCE',
        'UF_POST_ID', 'UF_OFFER_ID', 'UF_DATE', 'UF_CODE', 'UF_M2_EARLY_KOP', 'UF_M2_COMMON_KOP',
        'UF_M2_VALUE_MODEL', 'UF_M2_VALUE_STATE'];
    private const VALUE_COLUMNS = ['UF_M2_EARLY_KOP', 'UF_M2_COMMON_KOP', 'UF_M2_VALUE_MODEL', 'UF_M2_VALUE_STATE'];

    /** Admission comes from the sealed sidecar configuration, NEVER from the incoming body. */
    public function __construct(PDO $pdo, string $admittedProducerContract, ?callable $clock = null)
    {
        $this->pdo = $pdo; $this->admittedProducerContract = $admittedProducerContract;
        $this->clock = $clock === null ? static fn(): int => hrtime(true) : Closure::fromCallable($clock);
    }
    /** Endpoint must authenticate this exact body with the new protocol before calling. */
    public function read(string $authenticatedBody, int $now): array
    {
        $p = Max2ValueLookupProtocolV2::class;
        $request = $p::decodeBody($authenticatedBody, $now);
        $requestHash = hash('sha256', $authenticatedBody);
        $started = ($this->clock)(); $bytes = 0;
        $response = $this->envelope($request, $requestHash, $now);
        try {
            if (!hash_equals($p::PRODUCER_CONTRACT_SHA256, $this->admittedProducerContract)) {
                throw new UnexpectedValueException('producer_contract_unadmitted');
            }
            if ($this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'mysql' || $this->pdo->inTransaction()) {
                throw new UnexpectedValueException('storage_contract');
            }
            $version = (string)$this->pdo->getAttribute(PDO::ATTR_SERVER_VERSION);
            if (stripos($version, 'mariadb') !== false
                || preg_match('/\A8\.[0-9]+\.[0-9]+(?:[-.][A-Za-z0-9_.-]+)?\z/D', $version) !== 1
                || !in_array($this->pdo->getAttribute(PDO::ATTR_EMULATE_PREPARES), [false, 0], true)) {
                throw new UnexpectedValueException('storage_contract');
            }
            // Applies to this one transaction only; no rights/schema/session-global mutation.
            $this->pdo->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ, READ ONLY');
            $this->pdo->beginTransaction();
            try {
                $index = $this->metadata($started, $bytes);
                $columns = implode(',', self::COLUMNS);
                $tuple = implode(',', array_map(static fn($name): string => 'CAST(s.' . $name . ' AS CHAR)', self::COLUMNS));
                if ($request['action'] === 'list') {
                    $prefix = substr($request['external_user_id'], 0, $index['prefix']);
                    // LIMIT covers the ENTIRE index prefix, before actor/project/value/clock filtering.
                    $inner = 'SELECT ' . $columns . ' FROM max_sub_links FORCE INDEX (`' . $index['name']
                        . '`) WHERE UF_CHAT_ID LIKE ? LIMIT 65';
                    $args = [$prefix . '%'];
                } else {
                    $inner = 'SELECT ' . $columns . ' FROM max_sub_links FORCE INDEX (PRIMARY) WHERE ID=? LIMIT 1';
                    $args = [$request['selection']['candidate']['tracker_record_id']];
                }
                $rows = $this->packets('SELECT JSON_ARRAY(' . $tuple . ') AS packet FROM (' . $inner . ') s',
                    $args, $started, $bytes);
                $candidates = []; $scoped = 0; $blank = 0; $seen = [];
                foreach ($rows as $row) {
                    if (!is_array($row) || !array_is_list($row) || count($row) !== count(self::COLUMNS)
                        || !$p::id($row[0]) || !is_string($row[1]) || isset($seen[$row[0]])) {
                        throw new UnexpectedValueException('invalid_source');
                    }
                    $seen[$row[0]] = true;
                    if ($row[1] !== $request['external_user_id']) continue;
                    // Old blank rows may use source fallback, but a new reserve MUST contain metric project 1538.
                    $effective = $row[4] === null || $row[4] === '' ? $row[3] : $row[4];
                    if ($effective !== $p::PROJECT) {
                        if (is_string($effective) && preg_match('/\A0*1538\z/D', $effective) === 1) {
                            throw new UnexpectedValueException('invalid_source');
                        }
                        continue;
                    }
                    $scoped++;
                    $values = array_slice($row, 9, 4);
                    $empty = count(array_filter($values, static fn($v): bool => $v === null || $v === ''));
                    if ($empty === 4) { $blank++; continue; }
                    if ($empty > 0) throw new UnexpectedValueException('partial_value_fields');
                    $candidates[] = self::candidate($row, $request['external_user_id'], $now);
                }
                usort($candidates, static fn($a, $b): int => $p::decimalCompare($a['tracker_record_id'], $b['tracker_record_id']));
                if ($request['action'] === 'list') $status = $candidates === [] ? 'complete_empty' : 'complete';
                elseif ($rows === []) $status = 'missing';
                elseif (count($candidates) === 1 && $candidates[0] === $request['selection']['candidate']) $status = 'match';
                else { $status = 'changed'; $candidates = []; }
                $response = array_replace($response, ['status' => $status, 'complete_within_bound' => true,
                    'scanned_rows' => count($rows), 'scoped_rows' => $scoped, 'blank_row_count' => $blank,
                    'candidates' => $candidates, 'set_sha256' => $p::setDigest($candidates),
                    'possible_max2_unresolved' => false, 'reason' => null]);
            } finally {
                if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            }
            $this->budget($started, $bytes);
            $encoded = $p::json($response);
            if (strlen($encoded) > $p::MAX_RESPONSE) throw new UnexpectedValueException('packet_budget');
            $p::validateResult($encoded, $authenticatedBody, $now);
            return $response;
        } catch (Throwable $error) {
            $known = ['producer_contract_unadmitted', 'storage_contract', 'point_index_unavailable', 'row_limit',
                'packet_budget', 'time_headroom', 'invalid_source', 'partial_value_fields', 'invalid_value_fields', 'invalid_clock'];
            $reason = $error instanceof UnexpectedValueException && in_array($error->getMessage(), $known, true)
                ? $error->getMessage() : 'storage_unavailable';
            $response = $this->envelope($request, $requestHash, $now);
            $response['reason'] = $reason;
            return $response;
        }
    }
    private function envelope(array $request, string $hash, int $now): array
    {
        $p = Max2ValueLookupProtocolV2::class;
        return ['version' => 2, 'purpose' => $p::PURPOSE, 'action' => $request['action'],
            'counter_id' => $p::COUNTER, 'metric_project_id' => $p::PROJECT, 'external_user_id' => $request['external_user_id'],
            'context' => $request['context'], 'request_sha256' => $hash, 'producer_contract_sha256' => $p::PRODUCER_CONTRACT_SHA256,
            'checked_at_unix' => $now, 'tracker_timezone' => 'Europe/London',
            'coverage' => $request['action'] === 'list' ? $p::COVERAGE : 'exact_selected_record_only',
            'own_reserve_only' => true, 'status' => 'unknown', 'complete_within_bound' => false,
            'scanned_rows' => null, 'scoped_rows' => null, 'blank_row_count' => null, 'candidates' => [],
            'set_sha256' => null, 'possible_max2_unresolved' => true, 'reason' => 'storage_unavailable', 'financial_permission' => false];
    }
    private function metadata(int $started, int &$bytes): array
    {
        $quoted = "'" . implode("','", self::COLUMNS) . "'";
        $sql = "SELECT JSON_ARRAY('column',COLUMN_NAME,DATA_TYPE) AS packet FROM"
            . " (SELECT COLUMN_NAME,DATA_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE()"
            . " AND TABLE_NAME='max_sub_links' AND COLUMN_NAME IN (" . $quoted . ") LIMIT 14) c"
            . " UNION ALL SELECT JSON_ARRAY('actor_index',INDEX_NAME,SUB_PART,INDEX_TYPE) AS packet FROM"
            . " (SELECT INDEX_NAME,SUB_PART,INDEX_TYPE FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE()"
            . " AND TABLE_NAME='max_sub_links' AND SEQ_IN_INDEX=1 AND COLUMN_NAME='UF_CHAT_ID' LIMIT 9) i"
            . " UNION ALL SELECT JSON_ARRAY('primary',COLUMN_NAME,SUB_PART,NON_UNIQUE) AS packet FROM"
            . " (SELECT COLUMN_NAME,SUB_PART,NON_UNIQUE FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE()"
            . " AND TABLE_NAME='max_sub_links' AND INDEX_NAME='PRIMARY' LIMIT 2) p"
            . " UNION ALL SELECT JSON_ARRAY('hl9',TABLE_NAME) AS packet FROM (SELECT TABLE_NAME FROM b_hlblock_entity WHERE ID=9 LIMIT 2) h"
            . " UNION ALL SELECT JSON_ARRAY('engine',ENGINE) AS packet FROM (SELECT ENGINE FROM information_schema.TABLES"
            . " WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='max_sub_links' LIMIT 2) t"
            . " UNION ALL SELECT JSON_ARRAY('counter',CAST(ep.VALUE AS CHAR)) AS packet FROM"
            . " (SELECT ep.VALUE FROM b_iblock_element_property ep JOIN b_iblock_property p ON p.ID=ep.IBLOCK_PROPERTY_ID"
            . " WHERE ep.IBLOCK_ELEMENT_ID=1538 AND p.IBLOCK_ID=8 AND p.CODE='YA_METRIK_ID'"
            . " AND HEX(p.CODE)=HEX('YA_METRIK_ID') LIMIT 2) ep";
        $rows = $this->packets($sql, [], $started, $bytes);
        $columns = []; $indexes = []; $primary = []; $hl = []; $engine = []; $counter = [];
        foreach ($rows as $row) {
            if (!is_array($row) || !array_is_list($row) || !is_string($row[0] ?? null)) throw new UnexpectedValueException('storage_contract');
            switch ($row[0]) {
                case 'column':
                    if (count($row) !== 3 || !is_string($row[1]) || !is_string($row[2]) || isset($columns[$row[1]])) throw new UnexpectedValueException('storage_contract');
                    $columns[$row[1]] = $row[2]; break;
                case 'actor_index':
                    if (count($row) !== 4 || !is_string($row[1]) || preg_match('/\A[A-Za-z0-9_]{1,64}\z/D', $row[1]) !== 1
                        || $row[3] !== 'BTREE' || isset($indexes[$row[1]])) throw new UnexpectedValueException('point_index_unavailable');
                    $part = $row[2];
                    if ($part !== null && (!is_int($part) && (!is_string($part) || preg_match('/\A[1-9][0-9]{0,2}\z/D', $part) !== 1))) throw new UnexpectedValueException('point_index_unavailable');
                    $part = $part === null ? 20 : (int)$part;
                    if ($part < 1 || $part > 255) throw new UnexpectedValueException('point_index_unavailable');
                    $indexes[$row[1]] = min(20, $part); break;
                case 'primary': $primary[] = $row; break;
                case 'hl9': $hl[] = $row; break;
                case 'engine': $engine[] = $row; break;
                case 'counter': $counter[] = $row; break;
                default: throw new UnexpectedValueException('storage_contract');
            }
        }
        if (count($columns) !== count(self::COLUMNS) || array_diff(self::COLUMNS, array_keys($columns))
            || !in_array($columns['ID'] ?? null, ['int', 'bigint'], true)
            || !in_array($columns['UF_CHAT_ID'] ?? null, ['char', 'varchar', 'text', 'tinytext', 'mediumtext', 'longtext'], true)
            || $hl !== [['hl9', 'max_sub_links']] || $engine !== [['engine', 'InnoDB']]
            || $counter !== [['counter', '98615635']]
            || count($primary) !== 1 || count($primary[0]) !== 4 || $primary[0][1] !== 'ID' || $primary[0][2] !== null
            || !in_array($primary[0][3], [0, '0'], true)) throw new UnexpectedValueException('storage_contract');
        foreach (self::VALUE_COLUMNS as $field) if ($columns[$field] !== 'text') throw new UnexpectedValueException('storage_contract');
        if (!$indexes || count($indexes) > 8) throw new UnexpectedValueException('point_index_unavailable');
        arsort($indexes, SORT_NUMERIC); $name = (string)array_key_first($indexes);
        return ['name' => $name, 'prefix' => $indexes[$name]];
    }
    private function packets(string $source, array $args, int $started, int &$bytes): array
    {
        $p = Max2ValueLookupProtocolV2::class; $this->budget($started, $bytes);
        // Window guard limits transferred bytes even when a stored TEXT value is huge.
        $sql = "SELECT /*+ MAX_EXECUTION_TIME(250) */ CASE WHEN COUNT(*) OVER ()>64 THEN JSON_ARRAY('row_limit')"
            . " WHEN SUM(OCTET_LENGTH(JSON_QUOTE(CAST(packet AS CHAR)))+24) OVER ()+2<=? THEN packet ELSE NULL END AS packet"
            . ' FROM (' . $source . ') max2_value_packets LIMIT 65';
        $query = $this->pdo->prepare($sql);
        try {
            foreach ([$p::MAX_DB_BYTES - $bytes, ...$args] as $i => $value) $query->bindValue($i + 1, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
            $query->execute(); $rows = $query->fetchAll(PDO::FETCH_ASSOC);
        } finally { $query->closeCursor(); }
        $bytes += strlen($p::json($rows)); $this->budget($started, $bytes);
        if (count($rows) > $p::MAX_ROWS) throw new UnexpectedValueException('row_limit');
        $out = [];
        foreach ($rows as $row) {
            if (!is_array($row) || array_keys($row) !== ['packet'] || !is_string($row['packet'])) throw new UnexpectedValueException('packet_budget');
            $packet = json_decode($row['packet'], true, 8, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
            if ($packet === ['row_limit']) throw new UnexpectedValueException('row_limit');
            $out[] = $packet;
        }
        $this->budget($started, $bytes);
        return $out;
    }
    private function budget(int $started, int $bytes): void
    {
        if (($this->clock)() - $started >= 500000000) throw new UnexpectedValueException('time_headroom');
        if ($bytes > Max2ValueLookupProtocolV2::MAX_DB_BYTES) throw new UnexpectedValueException('packet_budget');
    }
    private static function candidate(array $tuple, string $actor, int $now): array
    {
        $p = Max2ValueLookupProtocolV2::class;
        foreach ($tuple as $value) if (!is_string($value)) throw new UnexpectedValueException('invalid_value_fields');
        if ($tuple[1] !== $actor || $tuple[4] !== $p::PROJECT || !$p::id($tuple[3])
            || preg_match('/\An2[a-f0-9]{30}\z/D', $tuple[8]) !== 1) throw new UnexpectedValueException('invalid_value_fields');
        $candidate = ['tracker_record_id' => $tuple[0],
            'immutable_tuple_sha256' => hash('sha256', $p::json([$p::PURPOSE, 'immutable-hl9-tuple-v2', $p::COUNTER, $tuple])),
            'counter_id' => $p::COUNTER, 'metric_project_id' => $p::PROJECT, 'external_user_id' => $tuple[1],
            'post_id' => $tuple[5], 'offer_id' => $tuple[6], 'client_id' => $tuple[2], 'early_kopeks' => $tuple[9],
            'common_kopeks' => $tuple[10], 'model_version' => $tuple[11], 'issuance_state' => $tuple[12],
            'recorded_at_unix' => self::sourceTime($tuple[7], $now)];
        try { $p::validateCandidate($candidate, $actor, $now); }
        catch (Throwable $ignored) { throw new UnexpectedValueException('invalid_value_fields'); }
        return $candidate;
    }
    /** Exact retained London wall clock. A DST fold/gap is unknown, never guessed. */
    public static function sourceTime(string $raw, int $now): int
    {
        if (preg_match('/\A[0-9]{4}-[0-9]{2}-[0-9]{2} [0-9]{2}:[0-9]{2}:[0-9]{2}\z/D', $raw) !== 1) throw new UnexpectedValueException('invalid_clock');
        $wall = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $raw, new DateTimeZone('UTC'));
        if (!$wall || $wall->format('Y-m-d H:i:s') !== $raw) throw new UnexpectedValueException('invalid_clock');
        $zone = new DateTimeZone('Europe/London'); $matches = [];
        foreach ($zone->getTransitions($wall->getTimestamp() - 86400, $wall->getTimestamp() + 86400) as $transition) {
            $unix = $wall->getTimestamp() - $transition['offset'];
            if ((new DateTimeImmutable('@' . $unix))->setTimezone($zone)->format('Y-m-d H:i:s') === $raw) $matches[$unix] = true;
        }
        if (count($matches) !== 1) throw new UnexpectedValueException('invalid_clock');
        $unix = (int)array_key_first($matches);
        if ($unix < Max2ValueLookupProtocolV2::MIN_UNIX || $unix > $now) throw new UnexpectedValueException('invalid_clock');
        return $unix;
    }
}
