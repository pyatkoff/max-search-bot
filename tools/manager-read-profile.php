<?php
declare(strict_types=1);

// Diagnostic-only, never an HTTP endpoint or an alternative application store.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

final class ManagerReadProfile
{
    private static bool $capturing = false;
    private bool $active = true;
    private int $statementCount = 0;
    private int $executeCount = 0;
    private array $records = [];
    private ?array $planQuery = null;

    /** The callback uses its original connection, runs exactly once and returns unchanged. */
    public static function capture(PDO $pdo, callable $load, ?array &$profile = null, bool $explain = true): mixed
    {
        if (self::$capturing) { throw new LogicException('Nested manager read profiling is unsupported'); }
        self::$capturing = true;
        $observer = new self();
        $installed = false;
        $original = null;
        $started = hrtime(true);
        try {
            $original = $pdo->getAttribute(PDO::ATTR_STATEMENT_CLASS);
            // Replacing another subclass could change the callback's semantics.
            if ($original === [PDOStatement::class] && !$pdo->getAttribute(PDO::ATTR_PERSISTENT)) {
                $installed = $pdo->setAttribute(PDO::ATTR_STATEMENT_CLASS, [ManagerReadProfileStatement::class, [$observer]]);
            }
        } catch (Throwable $ignored) {
            // Unsupported observers still run the canonical callback, without claimed coverage.
        }
        $started = hrtime(true);
        $completed = false;
        try {
            $result = $load();
            $completed = true;
        } finally {
            $duration = (hrtime(true) - $started) / 1e6;
            $observer->active = false;
            if (!$completed) { $observer->planQuery = null; }
            self::$capturing = false;
            if ($installed && !$pdo->setAttribute(PDO::ATTR_STATEMENT_CLASS, $original)) {
                throw new RuntimeException('Manager read profiler could not restore statement class');
            }
            $profile = $observer->export($installed, $duration);
        }
        // EXPLAIN is not part of load_ms, is not observed, and never uses ANALYZE.
        if ($installed && $explain) { $profile['plan'] = $observer->explain($pdo); }
        elseif ($installed) { $profile['plan']['reason'] = 'disabled'; }
        $observer->planQuery = null;
        return $result;
    }

    public function register(string $sql): ?int
    {
        if (!$this->active) { return null; }
        ++$this->statementCount;
        if (count($this->records) >= 64) { return null; }
        $category = 'other';
        if (preg_match('/\binformation_schema\.|^\s*SHOW\b/i', $sql)) { $category = 'metadata'; }
        elseif ($this->isCanonicalList($sql)) { $category = 'manager_list'; }
        elseif (preg_match('/\b(?:managers|manager_project_access|manager_group_members|manager_groups)\b/i', $sql)) { $category = 'permissions'; }
        elseif (preg_match('/\b(?:manager_delivery_failures|conversation_delivery_failures)\b/i', $sql)) { $category = 'delivery'; }
        $this->records[] = ['category'=>$category,'execute_count'=>0,'execute_ms'=>null,'fetch_ms'=>0.0,'fetched_rows'=>0,'value_bytes'=>0,'failed'=>false];
        return array_key_last($this->records);
    }

    public function executed(?int $record, string $sql, ?array $params, float $ms, bool $failed): void
    {
        if (!$this->active) { return; }
        ++$this->executeCount;
        if ($record !== null) {
            ++$this->records[$record]['execute_count'];
            $this->records[$record]['execute_ms'] = ($this->records[$record]['execute_ms'] ?? 0.0) + $ms;
            $this->records[$record]['failed'] = $this->records[$record]['failed'] || $failed;
        }
        if (!$failed && $this->isCanonicalList($sql)) {
            // Retain at most one bounded query privately; never export it or its parameters.
            $safeParams = $params;
            if ($params !== null && (count($params) > 256 || self::valueBytes($params) > 1048576)) { $safeParams = null; }
            $this->planQuery = [$sql, $safeParams];
        }
    }

    public function fetched(?int $record, mixed $value, float $ms, bool $many, bool $failed): void
    {
        if (!$this->active || $record === null) { return; }
        $this->records[$record]['fetch_ms'] += $ms;
        $this->records[$record]['failed'] = $this->records[$record]['failed'] || $failed;
        if (!$failed && $value !== false) {
            $this->records[$record]['fetched_rows'] += $many && is_array($value) ? count($value) : 1;
            $this->records[$record]['value_bytes'] += self::valueBytes($value);
        }
    }

    private static function valueBytes(mixed $value): int
    {
        if (is_array($value)) {
            $bytes = 0;
            foreach ($value as $cell) { $bytes += self::valueBytes($cell); }
            return $bytes;
        }
        return is_scalar($value) ? strlen((string)$value) : 0;
    }

    private function isCanonicalList(string $sql): bool
    {
        return strlen($sql) <= 65536
            && !preg_match('/;|--|\/\*|\*\/|#/', $sql)
            && preg_match('/^\s*SELECT\s+c\.id\s*,\s*c\.project_key\b/i', $sql) === 1
            && preg_match('/\bAS\s+unread_count\b/i', $sql) === 1
            && preg_match('/\bFROM\s+conversations\s+c\b/i', $sql) === 1
            && preg_match('/\bLIMIT\s+(?:[1-9][0-9]?|1[0-9]{2}|200)\s*$/i', $sql) === 1;
    }

    private function export(bool $available, float $duration): array
    {
        $records = $this->records;
        foreach ($records as &$record) {
            if ($record['execute_ms'] !== null) { $record['execute_ms'] = round($record['execute_ms'], 3); }
            $record['fetch_ms'] = round($record['fetch_ms'], 3);
        }
        unset($record);
        return [
            'available'=>$available, 'reason'=>$available ? null : 'unsupported_connection',
            'scope'=>'instrumented_service_list', 'sample_count'=>1, 'cache_state'=>'uncontrolled',
            'coverage'=>$available ? 'prepared_statements_and_explicit_execute_fetch_only' : 'unavailable',
            'load_ms'=>round($duration, 3), 'statement_count'=>$this->statementCount,
            'timed_execute_count'=>$this->executeCount, 'records_truncated'=>$this->statementCount > 64,
            'statements'=>$records,
            'plan'=>['available'=>false,'reason'=>'no_canonical_list_query','duration_ms'=>0.0,'tables'=>[],'truncated'=>false],
        ];
    }

    private function explain(PDO $pdo): array
    {
        $plan = ['available'=>false,'reason'=>'no_canonical_list_query','duration_ms'=>0.0,'tables'=>[],'truncated'=>false];
        if ($this->planQuery === null) { return $plan; }
        [$sql, $params] = $this->planQuery;
        if ($params === null) { $plan['reason'] = 'parameters_unavailable'; return $plan; }
        $started = hrtime(true);
        try {
            $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
            if (!in_array($driver, ['mysql','sqlite'], true)) { $plan['reason'] = 'unsupported_driver'; return $plan; }
            $statement = $pdo->prepare(($driver === 'mysql' ? 'EXPLAIN FORMAT=JSON ' : 'EXPLAIN QUERY PLAN ') . $sql);
            $statement->execute($params);
            if ($driver === 'mysql') {
                $raw = json_decode((string)$statement->fetchColumn(), true, 64, JSON_THROW_ON_ERROR);
                $walk = function (array $node) use (&$walk, &$plan): void {
                    if (isset($node['table']) && is_array($node['table'])) {
                        if (count($plan['tables']) >= 64) { $plan['truncated'] = true; return; }
                        $table = $node['table'];
                        $access = strtoupper((string)($table['access_type'] ?? ''));
                        $safe = ['access'=>in_array($access, ['SYSTEM','CONST','EQ_REF','REF','FULLTEXT','REF_OR_NULL','INDEX_MERGE','UNIQUE_SUBQUERY','INDEX_SUBQUERY','RANGE','INDEX','ALL'], true) ? strtolower($access) : 'other'];
                        foreach (['rows_examined_per_scan','rows_produced_per_join','filtered'] as $key) {
                            if (isset($table[$key]) && is_numeric($table[$key])) { $safe[$key] = (float)$table[$key]; }
                        }
                        $plan['tables'][] = $safe;
                    }
                    foreach ($node as $child) { if (is_array($child)) { $walk($child); } }
                };
                $walk(is_array($raw) ? $raw : []);
            } else {
                while (($row = $statement->fetch(PDO::FETCH_ASSOC)) !== false) {
                    if (count($plan['tables']) >= 64) { $plan['truncated'] = true; break; }
                    $detail = (string)($row['detail'] ?? '');
                    $plan['tables'][] = ['access'=>preg_match('/^SCAN\b/i', $detail) ? 'scan' : (preg_match('/^SEARCH\b/i', $detail) ? 'search' : 'other')];
                }
            }
            $plan['available'] = true; $plan['reason'] = null;
        } catch (Throwable $ignored) { $plan['reason'] = 'plan_unavailable'; }
        finally { $plan['duration_ms'] = round((hrtime(true) - $started) / 1e6, 3); }
        return $plan;
    }
}

final class ManagerReadProfileStatement extends PDOStatement
{
    private ?int $record;
    protected function __construct(private ManagerReadProfile $observer) { $this->record = $observer->register($this->queryString); }

    public function execute(?array $params = null): bool
    {
        $started = hrtime(true); $failed = true;
        try { $result = parent::execute($params); $failed = !$result; return $result; }
        finally { $this->observer->executed($this->record, $this->queryString, $params, (hrtime(true) - $started) / 1e6, $failed); }
    }

    public function fetch(int $mode = PDO::FETCH_DEFAULT, int $cursorOrientation = PDO::FETCH_ORI_NEXT, int $cursorOffset = 0): mixed
    {
        $started = hrtime(true); $failed = true; $value = false;
        try { $value = parent::fetch($mode, $cursorOrientation, $cursorOffset); $failed = false; return $value; }
        finally { $this->observer->fetched($this->record, $value, (hrtime(true) - $started) / 1e6, false, $failed); }
    }

    public function fetchAll(int $mode = PDO::FETCH_DEFAULT, mixed ...$args): array
    {
        $started = hrtime(true); $failed = true; $value = [];
        try { $value = parent::fetchAll($mode, ...$args); $failed = false; return $value; }
        finally { $this->observer->fetched($this->record, $value, (hrtime(true) - $started) / 1e6, true, $failed); }
    }

    public function fetchColumn(int $column = 0): mixed
    {
        $started = hrtime(true); $failed = true; $value = false;
        try { $value = parent::fetchColumn($column); $failed = false; return $value; }
        finally { $this->observer->fetched($this->record, $value, (hrtime(true) - $started) / 1e6, false, $failed); }
    }
}
