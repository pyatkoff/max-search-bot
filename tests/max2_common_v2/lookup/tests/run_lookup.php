<?php
declare(strict_types=1);
require_once __DIR__ . '/../services/Max2ValueLookupRepositoryV2.php';
require_once __DIR__ . '/../services/Max2ValueLookupClientV2.php';

/** Real protocol/normalization code, fake PDO and fake HTTPS only. No external calls. */
final class LookupFixturePDO extends PDO
{
    public array $rows = [];
    public array $metadata;
    public array $calls = [];
    public string $driver = 'mysql';
    public string $server = '8.0.43';
    public bool $active = false;
    public bool $emulated = false;
    public bool $nullPacket = false;
    public bool $failQuery = false;
    public function __construct(array $rows = [])
    {
        $this->rows = $rows;
        $columns = ['ID', 'UF_CHAT_ID', 'UF_CLIENT_ID', 'UF_SOURCE', 'UF_METRIC_SOURCE', 'UF_POST_ID',
            'UF_OFFER_ID', 'UF_DATE', 'UF_CODE', 'UF_M2_EARLY_KOP', 'UF_M2_COMMON_KOP', 'UF_M2_VALUE_MODEL', 'UF_M2_VALUE_STATE'];
        $this->metadata = array_map(static fn($c): array => ['column', $c,
            $c === 'ID' ? 'int' : ($c === 'UF_DATE' ? 'datetime' : 'text')], $columns);
        array_push($this->metadata, ['actor_index', 'ix_max_actor', 3, 'BTREE'], ['primary', 'ID', null, 0],
            ['hl9', 'max_sub_links'], ['engine', 'InnoDB'], ['counter', '98615635']);
    }
    public function getAttribute(int $attribute): mixed
    {
        return match ($attribute) {PDO::ATTR_DRIVER_NAME => $this->driver, PDO::ATTR_SERVER_VERSION => $this->server,
            PDO::ATTR_EMULATE_PREPARES => $this->emulated, default => null};
    }
    public function inTransaction(): bool { return $this->active; }
    public function exec(string $statement): int|false
    {
        $this->calls[] = ['exec', $statement];
        if ($statement !== 'SET TRANSACTION ISOLATION LEVEL REPEATABLE READ, READ ONLY') throw new RuntimeException('forbidden write');
        return 0;
    }
    public function beginTransaction(): bool { $this->calls[] = ['begin']; $this->active = true; return true; }
    public function rollBack(): bool { $this->calls[] = ['rollback']; $this->active = false; return true; }
    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        $this->calls[] = ['prepare', $query];
        if (!str_starts_with($query, 'SELECT /*+ MAX_EXECUTION_TIME(250) */')
            || preg_match('/\b(?:INSERT|UPDATE|DELETE|REPLACE|ALTER|GRANT|DROP|COMMIT)\b/i', $query)) throw new RuntimeException('not bounded select');
        return new LookupFixtureStatement($this, $query);
    }
}
final class LookupFixtureStatement extends PDOStatement
{
    private LookupFixturePDO $owner;
    private string $sql;
    private array $bound = [];
    public function __construct(LookupFixturePDO $owner, string $sql) { $this->owner = $owner; $this->sql = $sql; }
    public function bindValue(string|int $param, mixed $value, int $type = PDO::PARAM_STR): bool
    {
        $this->bound[$param] = [$value, $type]; return true;
    }
    public function execute(?array $params = null): bool
    {
        $this->owner->calls[] = ['execute', $this->bound];
        if ($this->owner->failQuery) throw new RuntimeException('PRIVATE_DATABASE_EXCEPTION_MUST_NOT_LEAK');
        return true;
    }
    public function fetchAll(int $mode = PDO::FETCH_DEFAULT, mixed ...$args): array
    {
        $rows = str_contains($this->sql, 'information_schema.COLUMNS') ? $this->owner->metadata : $this->owner->rows;
        if ($this->owner->nullPacket && !str_contains($this->sql, 'information_schema.COLUMNS')) return [['packet' => null]];
        return array_map(static fn($row): array => ['packet' => Max2ValueLookupProtocolV2::json($row)], $rows);
    }
    public function closeCursor(): bool { $this->owner->calls[] = ['close']; return true; }
}
function demand(bool $yes, string $message = 'assertion'): void { if (!$yes) throw new RuntimeException($message); }
function fail(callable $f): void { try { $f(); } catch (Throwable $e) { return; } throw new RuntimeException('expected rejection'); }
function unixNow(): int { return (new DateTimeImmutable('2026-11-21T13:00:00+00:00'))->getTimestamp(); }
function context(): array { return ['event_ref' => str_repeat('a', 64), 'frame_sha256' => str_repeat('b', 64), 'cutoff_unix' => unixNow() - 60]; }
function body(): string { return Max2ValueLookupProtocolV2::listBody('12345', context(), unixNow(), str_repeat('c', 32)); }
function row(string $id = '1001'): array
{
    return [$id, '12345', '987654321234567890', '1539', '1538', '740', 'max_551', '2026-11-21 12:10:00',
        'n2' . str_repeat('d', 30), '2636', '2636', Max2ValueLookupProtocolV2::MODEL, 'issued_unconfirmed'];
}
function readFixture(LookupFixturePDO $db, ?string $request = null, ?callable $clock = null, ?string $admission = null): array
{
    $reader = new Max2ValueLookupRepositoryV2($db, $admission ?? Max2ValueLookupProtocolV2::PRODUCER_CONTRACT_SHA256, $clock ?? static fn(): int => 0);
    return $reader->read($request ?? body(), unixNow());
}
function valid(array $result, ?string $request = null): array
{
    return Max2ValueLookupProtocolV2::validateResult(Max2ValueLookupProtocolV2::json($result), $request ?? body(), unixNow());
}
function unknown(array $r, ?string $reason = null): void
{
    demand($r['status'] === 'unknown' && $r['complete_within_bound'] === false && $r['candidates'] === []
        && $r['set_sha256'] === null && $r['scoped_rows'] === null && $r['blank_row_count'] === null
        && $r['possible_max2_unresolved'] === true, 'unknown must not look empty');
    if ($reason !== null) demand($r['reason'] === $reason, 'unknown reason: ' . $r['reason']);
    valid($r);
}
$tests = [];
$tests['single_exact_receipt_64_digit_cid'] = static function(): void {
    $r = row(); $r[2] = str_repeat('9', 64); $result = valid(readFixture(new LookupFixturePDO([$r])));
    demand($result['status'] === 'complete' && count($result['candidates']) === 1 && $result['blank_row_count'] === 0);
    $c = $result['candidates'][0]; demand($c['client_id'] === $r[2] && $c['post_id'] === '740'
        && $c['offer_id'] === 'max_551' && $c['common_kopeks'] === '2636' && strlen($c['immutable_tuple_sha256']) === 64);
};
$tests['complete_empty_is_own_reserve_only'] = static function(): void {
    $r = valid(readFixture(new LookupFixturePDO()));
    demand($r['status'] === 'complete_empty' && $r['complete_within_bound'] && $r['own_reserve_only']
        && $r['candidates'] === [] && $r['scanned_rows'] === 0 && $r['financial_permission'] === false);
};
$tests['blank_legacy_rows_do_not_become_historical_absence'] = static function(): void {
    $r = row(); $r[8] = 'legacy'; $r[9] = null; $r[10] = ''; $r[11] = null; $r[12] = '';
    $out = valid(readFixture(new LookupFixturePDO([$r])));
    demand($out['status'] === 'complete_empty' && $out['blank_row_count'] === 1 && $out['scoped_rows'] === 1
        && $out['producer_contract_sha256'] === Max2ValueLookupProtocolV2::PRODUCER_CONTRACT_SHA256);
};
$tests['blank_plus_multiple_positive_no_latest_selection'] = static function(): void {
    $blank = row('1000'); for ($i = 9; $i <= 12; $i++) $blank[$i] = '';
    $out = valid(readFixture(new LookupFixturePDO([row('1003'), $blank, row('1002')])));
    demand(array_column($out['candidates'], 'tracker_record_id') === ['1002', '1003'] && $out['blank_row_count'] === 1);
};
$tests['entire_prefix_bounded_before_exact_actor_project_filter'] = static function(): void {
    $otherActor = row('1002'); $otherActor[1] = '123456'; $otherProject = row('1003'); $otherProject[4] = '1539';
    $db = new LookupFixturePDO([$otherActor, $otherProject, row()]); $out = valid(readFixture($db));
    demand($out['scanned_rows'] === 3 && $out['scoped_rows'] === 1 && count($out['candidates']) === 1);
    $prepares = array_values(array_filter($db->calls, static fn($c): bool => $c[0] === 'prepare'));
    demand(count($prepares) === 2 && str_contains($prepares[1][1], 'FORCE INDEX (`ix_max_actor`) WHERE UF_CHAT_ID LIKE ? LIMIT 65')
        && !str_contains($prepares[1][1], 'ORDER BY') && !str_contains($prepares[1][1], 'UF_METRIC_SOURCE='));
    $executes = array_values(array_filter($db->calls, static fn($c): bool => $c[0] === 'execute'));
    demand($executes[1][1][2] === ['123%', PDO::PARAM_STR]);
};
$tests['65_prefix_rows_unknown_even_foreign_rows'] = static function(): void {
    $rows = []; for ($i = 1; $i <= 65; $i++) { $r = row((string)$i); $r[1] = '123456'; $rows[] = $r; }
    unknown(readFixture(new LookupFixturePDO($rows)), 'row_limit');
};
$tests['64_rows_preserved_within_bound'] = static function(): void {
    $rows = []; for ($i = 1; $i <= 64; $i++) $rows[] = row((string)$i);
    $out = valid(readFixture(new LookupFixturePDO($rows))); demand(count($out['candidates']) === 64 && $out['scoped_rows'] === 64);
};
$tests['every_partial_field_is_unknown_not_zero'] = static function(): void {
    for ($i = 9; $i <= 12; $i++) { $r = row(); $r[$i] = null; unknown(readFixture(new LookupFixturePDO([$r])), 'partial_value_fields'); }
};
$tests['invalid_value_model_state_amounts_and_cid_are_unknown'] = static function(): void {
    foreach ([[9, '0'], [9, '02636'], [9, '10000001'], [10, '1799'], [11, 'other_model'], [12, 'delivered'],
        [2, str_repeat('9', 65)], [5, '0740'], [6, 'offer_551'], [8, 'invalid_code']] as [$index, $value]) {
        $r = row(); $r[$index] = $value; unknown(readFixture(new LookupFixturePDO([$r])), 'invalid_value_fields');
    }
    foreach (['1', '100', '1798', '1800', '2635', '2637', '10000000'] as $amount) {
        $r = row(); $r[9] = $r[10] = $amount; unknown(readFixture(new LookupFixturePDO([$r])), 'invalid_value_fields');
    }
    $r = row(); $r[9] = $r[10] = '1799'; demand(valid(readFixture(new LookupFixturePDO([$r])))['status'] === 'complete');
};
$tests['after_inquiry_positive_retained_not_filtered'] = static function(): void {
    $r = row(); $r[7] = '2026-11-21 12:59:30';
    $out = valid(readFixture(new LookupFixturePDO([$r])));
    demand($out['candidates'][0]['recorded_at_unix'] > context()['cutoff_unix']);
};
$tests['future_malformed_and_dst_ambiguous_clock_unknown'] = static function(): void {
    foreach (['2026-11-21 13:00:01', '2026-02-30 12:00:00', '2026-10-25 01:30:00', '2026-03-29 01:30:00', 'bad'] as $date) {
        $r = row(); $r[7] = $date; unknown(readFixture(new LookupFixturePDO([$r])), 'invalid_clock');
    }
};
$tests['metadata_primary_actor_index_hl9_counter_fail_closed'] = static function(): void {
    foreach (['primary', 'actor_index', 'hl9', 'counter', 'engine'] as $tag) {
        $db = new LookupFixturePDO([row()]); $db->metadata = array_values(array_filter($db->metadata, static fn($m): bool => $m[0] !== $tag));
        unknown(readFixture($db), $tag === 'actor_index' ? 'point_index_unavailable' : 'storage_contract');
    }
    $db = new LookupFixturePDO(); $db->metadata[] = ['counter', '98615635']; unknown(readFixture($db), 'storage_contract');
};
$tests['missing_new_field_or_non_text_field_unknown'] = static function(): void {
    $db = new LookupFixturePDO(); $db->metadata = array_values(array_filter($db->metadata,
        static fn($m): bool => !($m[0] === 'column' && $m[1] === 'UF_M2_COMMON_KOP')));
    unknown(readFixture($db), 'storage_contract');
    $db = new LookupFixturePDO(); foreach ($db->metadata as &$m) if ($m[0] === 'column' && $m[1] === 'UF_M2_COMMON_KOP') $m[2] = 'int'; unset($m);
    unknown(readFixture($db), 'storage_contract');
};
$tests['driver_transaction_emulation_and_unadmitted_contract_stop'] = static function(): void {
    foreach (['driver' => 'sqlite', 'server' => '8.0.43-MariaDB', 'active' => true, 'emulated' => true] as $field => $value) {
        $db = new LookupFixturePDO(); $db->$field = $value; unknown(readFixture($db), 'storage_contract'); demand($db->calls === []);
    }
    $db = new LookupFixturePDO(); unknown(readFixture($db, null, null, str_repeat('f', 64)), 'producer_contract_unadmitted'); demand($db->calls === []);
};
$tests['sql_only_snapshot_no_writes_commit_or_secret_errors'] = static function(): void {
    $db = new LookupFixturePDO([row()]); valid(readFixture($db));
    demand($db->calls[0] === ['exec', 'SET TRANSACTION ISOLATION LEVEL REPEATABLE READ, READ ONLY']
        && end($db->calls) === ['rollback'] && $db->active === false);
    $db = new LookupFixturePDO(); $db->failQuery = true; $out = readFixture($db); unknown($out, 'storage_unavailable');
    demand(!str_contains(json_encode($out), 'PRIVATE_DATABASE_EXCEPTION') && end($db->calls) === ['rollback']);
};
$tests['db_byte_and_wall_time_bounds_unknown'] = static function(): void {
    $db = new LookupFixturePDO(); $db->nullPacket = true; unknown(readFixture($db), 'packet_budget');
    $r = row(); $r[2] = str_repeat('9', 40000); unknown(readFixture(new LookupFixturePDO([$r])), 'packet_budget');
    $ticks = 0; $clock = static function() use (&$ticks): int { return $ticks++ * 600000000; };
    unknown(readFixture(new LookupFixturePDO(), null, $clock), 'time_headroom');
};
$tests['duplicate_source_id_or_noncanonical_project_unknown'] = static function(): void {
    unknown(readFixture(new LookupFixturePDO([row(), row()])), 'invalid_source');
    $r = row(); $r[4] = '01538'; unknown(readFixture(new LookupFixturePDO([$r])), 'invalid_source');
};
$tests['point_recheck_exact_row_match_changed_missing_separate_coverage'] = static function(): void {
    $lookup = valid(readFixture(new LookupFixturePDO([row()])));
    $request = Max2ValueLookupProtocolV2::recheckBody($lookup, hash('sha256', Max2ValueLookupProtocolV2::json($lookup)), '1001', unixNow());
    $db = new LookupFixturePDO([row()]); $out = valid(readFixture($db, $request), $request);
    demand($out['status'] === 'match' && $out['coverage'] === 'exact_selected_record_only');
    $sql = array_values(array_filter($db->calls, static fn($c): bool => $c[0] === 'prepare'))[1][1];
    demand(str_contains($sql, 'FORCE INDEX (PRIMARY) WHERE ID=? LIMIT 1') && !str_contains($sql, 'LIKE ?'));
    $changed = row(); $changed[2] = '123456789';
    demand(valid(readFixture(new LookupFixturePDO([$changed]), $request), $request)['status'] === 'changed');
    demand(valid(readFixture(new LookupFixturePDO(), $request), $request)['status'] === 'missing');
};
$tests['protocol_request_exact_scope_extras_duplicates_replay'] = static function(): void {
    $p = Max2ValueLookupProtocolV2::class; $request = json_decode(body(), true);
    foreach (['counter_id' => '98615635', 'metric_project_id' => '1539', 'external_user_id' => '012345',
        'purpose' => 'anytour-max-tracker-lookup-v1', 'issued_at' => unixNow() - 91, 'extra' => 'ignored'] as $field => $value) {
        fail(static fn() => $p::decodeBody($p::json(array_replace($request, [$field => $value])), unixNow()));
    }
    fail(static fn() => $p::decodeBody(substr(body(), 0, -1) . ',"counter_id":98615635}', unixNow()));
};
$tests['response_binding_contract_counts_and_private_projection'] = static function(): void {
    $p = Max2ValueLookupProtocolV2::class; $out = valid(readFixture(new LookupFixturePDO([row()])));
    foreach (['request_sha256' => str_repeat('f', 64), 'producer_contract_sha256' => str_repeat('f', 64),
        'scoped_rows' => 0, 'status' => 'complete_empty', 'financial_permission' => true] as $field => $value) {
        fail(static fn() => $p::validateResult($p::json(array_replace($out, [$field => $value])), body(), unixNow()));
    }
    $public = $p::json($p::publicSummary($out));
    foreach (['12345', '987654321234567890', 'max_551', 'tracker_record_id', 'client_id', 'immutable_tuple_sha256', 'source_receipt_sha256'] as $secret) demand(!str_contains($public, $secret));
};
$tests['rsa_new_purpose_authentication_and_old_purpose_rejected'] = static function(): void {
    $p = Max2ValueLookupProtocolV2::class; $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 2048]);
    openssl_pkey_export($key, $private); $public = openssl_pkey_get_details($key)['key'];
    $signature = $p::signature(body(), $private);
    demand($p::authenticate(body(), $signature, $public, unixNow(), $p::EVIDENCE_HEADER)['action'] === 'list');
    openssl_sign("anytour-max-tracker-lookup-v1\n" . body(), $old, $key, OPENSSL_ALGO_SHA256);
    fail(static fn() => $p::authenticate(body(), base64_encode($old), $public, unixNow(), $p::EVIDENCE_HEADER));
    fail(static fn() => $p::authenticate(body(), $signature, $public, unixNow(), 'retained-click-rehydrate-v1'));
};
$tests['client_one_acknowledged_bounded_transport_and_exact_recheck'] = static function(): void {
    $p = Max2ValueLookupProtocolV2::class; $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 2048]);
    openssl_pkey_export($key, $private); $public = openssl_pkey_get_details($key)['key']; $calls = [];
    $transport = static function(array $request) use (&$calls, $p, $public): array {
        $calls[] = $request;
        $p::authenticate($request['body'], $request['signature'], $public, unixNow(), $request['evidence_header']);
        demand($request['max_response_bytes'] === 65536 && $request['timeout_seconds'] === 12
            && $request['connect_timeout_seconds'] === 5 && $request['follow_redirects'] === false
            && $request['require_https'] && $request['verify_tls_peer'] && $request['verify_tls_host'] && $request['request_count'] === 1);
        return ['status_code' => 200, 'body' => $p::json(readFixture(new LookupFixturePDO([row()]), $request['body'])),
            'request_count' => 1, 'redirects_followed' => 0, 'tls_verified' => true];
    };
    $client = new Max2ValueLookupClientV2($transport, static fn(): string => $private, static fn(): int => unixNow());
    demand($client->lookup('12345', context(), static fn(): bool => false) === null && $calls === []);
    $lookup = $client->lookup('12345', context(), static fn($hash): bool => $p::hash($hash));
    demand(is_array($lookup) && count($calls) === 1 && $lookup['source_receipt_sha256'] === hash('sha256', $p::json($lookup['receipt'])));
    $recheck = $client->recheck($lookup, '1001', static fn(): bool => true);
    demand(is_array($recheck) && $recheck['receipt']['status'] === 'match' && count($calls) === 2);
    $lookup['receipt']['candidates'][0]['client_id'] = '111';
    demand($client->recheck($lookup, '1001', static fn(): bool => true) === null && count($calls) === 2);
};
$tests['client_transport_failure_never_means_zero'] = static function(): void {
    $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 2048]); openssl_pkey_export($key, $private);
    foreach (['oversize', 'redirect', 'tls', 'twice', 'exception'] as $mode) {
        $calls = 0;
        $transport = static function(array $request) use ($mode, &$calls): array {
            $calls++; if ($mode === 'exception') throw new RuntimeException('SECRET_TRANSPORT_ERROR');
            return ['status_code' => 200, 'body' => $mode === 'oversize' ? str_repeat('x', 65537) : '{}',
                'request_count' => $mode === 'twice' ? 2 : 1, 'redirects_followed' => $mode === 'redirect' ? 1 : 0,
                'tls_verified' => $mode !== 'tls'];
        };
        $client = new Max2ValueLookupClientV2($transport, static fn(): string => $private, static fn(): int => unixNow());
        demand($client->lookup('12345', context(), static fn(): bool => true) === null && $calls === 1);
    }
};
$tests['actual_producer_contract_file_is_the_exact_admitted_pin'] = static function(): void {
    $raw = file_get_contents(__DIR__ . '/../producer-contract.json');
    demand(hash('sha256', $raw) === Max2ValueLookupProtocolV2::PRODUCER_CONTRACT_SHA256);
    $c = json_decode($raw, true, 16, JSON_THROW_ON_ERROR);
    demand($c['installed_source_sha'] === '05322884a0704b4363b31f572707345a148f798d'
        && $c['runtime_sha256']['production.php'] === 'a7ab4e07f23b07ca4c29c03dc051a11c712530ba76794e62b0345ecdeb4a1493'
        && $c['semantics']['all_four_blank_proves_no_historical_max2'] === false
        && $c['semantics']['delivery_confirmed'] === false);
};
$passed = 0;
foreach ($tests as $name => $test) {
    try { $test(); $passed++; }
    catch (Throwable $error) { fwrite(STDERR, 'FAIL ' . $name . ': ' . $error->getMessage() . "\n"); exit(1); }
}
echo json_encode(['status' => 'PASS', 'tests' => $passed, 'network_calls' => 0, 'database_connections' => 0,
    'provider_calls' => 0, 'runtime_files_modified' => 0], JSON_THROW_ON_ERROR) . "\n";
