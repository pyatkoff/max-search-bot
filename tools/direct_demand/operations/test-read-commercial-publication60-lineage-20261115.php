<?php
declare(strict_types=1);
define('REWAYA60_LIBRARY_ONLY', true);
require __DIR__.'/read-commercial-publication60-lineage-20261115.php';

$checks = 0;
function check60(bool $ok, string $name): void
{
    global $checks;
    if (!$ok) throw new RuntimeException('fixture_failed_'.$name);
    $checks++;
}
function reject60(callable $action, string $expected, string $name): void
{
    $caught = null;
    try { $action(); } catch (Throwable $e) { $caught = $e->getMessage(); }
    check60($caught === $expected, $name);
}
function version60(int $id, int $number, ?int $native = null, ?string $logical = null): array
{
    $values = array_fill_keys(R60_FIELDS, null);
    $types = array_fill_keys(R60_FIELDS, null);
    if ($native !== null) { $values['hotel_catalog_id'] = (string)$native; $types['hotel_catalog_id'] = 'INTEGER'; }
    if ($logical !== null) { $values['logical_id'] = $logical; $types['logical_id'] = 'STRING'; }
    return ['version_id'=>(string)$id,'publication_id'=>'60','version_number'=>(string)$number,
        'state'=>'sent','content_hash'=>str_repeat('a',64),'write_attempts'=>'1','claim_present'=>'0',
        'snapshot_bytes'=>'1024','snapshot_sha256'=>str_repeat('b',64),'caption_sha256'=>R60_CAPTION,
        'post_url'=>R60_URL,'typed_metadata_json'=>rrJson($values),'typed_metadata_types_json'=>rrJson($types),
        'created_at'=>'2026-11-01 12:00:00','updated_at'=>'2026-11-01 12:00:01'];
}
function header60(array $version, ?int $native = null, ?string $logical = null): array
{
    $fields = ['version_id','version_number','state','content_hash','write_attempts','claim_present','snapshot_bytes','post_url','caption_sha256'];
    return ['publication_id'=>'60','post_channel_id'=>'9','source_id'=>'5','external_message_id'=>'mid.fixture_publication60',
        'current_version_id'=>'72','publication_created_at'=>'2026-11-01 12:00:00']
        + array_intersect_key($version,array_fill_keys($fields,true)) + ['hotel_catalog_id'=>$native,'logical_id'=>$logical];
}
$current = version60(72,2); $header = header60($current); $old = version60(71,1);
$proof = r60Evidence([$header],[$old,$current]);
check60($proof['complete'] && $proof['version_count'] === 2 && $proof['relation']['status'] === 'native_relation_unresolved', 'unresolved_complete');
check60(!$proof['relation']['native_relation_proven'] && !$proof['relation']['new_publication_authorized_by_reader'], 'no_automatic_binding');
$historical = r60Evidence([$header],[version60(71,1,74036),$current]);
check60($historical['relation']['status'] === 'historical_native_only_needs_current_review' && !$historical['relation']['native_relation_proven'], 'historical_only');
$currentThird = array_replace($current,['version_number'=>'3']);
$conflict = r60Evidence([header60($currentThird)],[version60(70,1,74036),version60(71,2,364),$currentThird]);
check60($conflict['relation']['historical_catalog_hotel_ids'] === [74036,364] && $conflict['relation']['historical_native_conflicts_with_candidate'] === [364], 'all_history_conflicts_retained');
foreach (['catalog_hotel_id','tourvisor_id','tourvisorId','native_hotel_id','hotel_id'] as $field) {
    $generic = $old; $values = array_fill_keys(R60_FIELDS,null); $types = $values;
    $values[$field] = '74036'; $types[$field] = 'STRING';
    $generic['typed_metadata_json'] = rrJson($values); $generic['typed_metadata_types_json'] = rrJson($types);
    $p = r60Evidence([$header],[$generic,$current]);
    check60($p['relation']['status'] === 'native_relation_unresolved' && !$p['versions'][0]['snapshot']['typed_reference_fields'][0]['accepted_native_field'], 'untyped_namespace_'.$field);
}
foreach ([[74036,null],[null,'commercial-hotel-74036-v1'],[364,null]] as [$native,$logical]) {
    $drift = version60(72,2,$native,$logical);
    $p = r60Evidence([header60($drift,$native,$logical)],[$old,$drift]);
    check60($p['relation']['status'] === 'current_typed_metadata_changed_since_read4' && !$p['relation']['native_relation_proven'], 'current_drift_needs_review');
}
foreach ([['state'=>'pending'],['claim_present'=>'1']] as $changes) {
    $unstable = array_replace($current,$changes);
    $p = r60Evidence([header60($unstable)],[$old,$unstable]);
    check60($p['relation']['status'] === 'current_publication_not_stable' && !$p['relation']['native_relation_proven'], 'unstable_current');
}
reject60(fn()=>r60Evidence([],[$current]),'anchored_publication_not_unique_or_missing','missing_anchor');
reject60(fn()=>r60Evidence([$header,$header],[$current]),'anchored_publication_not_unique_or_missing','duplicate_anchor');
reject60(fn()=>r60Evidence([$header],[]),'history_coverage_limit_or_missing','missing_history');
reject60(fn()=>r60Evidence([$header],array_fill(0,65,$current)),'history_coverage_limit_or_missing','sentinel_65');
reject60(fn()=>r60Evidence([$header],[$old]),'anchored_current_missing_from_history','current_history_missing');
reject60(fn()=>r60Evidence([$header],[$current,$old]),'history_order_or_identity_changed','unordered_history');
reject60(fn()=>r60Evidence([$header],[$old,array_replace($current,['version_number'=>'1'])]),'history_order_or_identity_changed','duplicate_version_number');
reject60(fn()=>r60Evidence([$header],[array_replace($current,['publication_id'=>'61'])]),'history_publication_scope_changed','foreign_publication');
reject60(fn()=>r60Evidence([array_replace($header,['current_version_id'=>'73'])],[$current]),'header_scope_changed','changed_current_pointer');
reject60(fn()=>r60Evidence([$header],[array_replace($current,['caption_sha256'=>str_repeat('c',64)])]),'current_history_snapshot_conflict','caption_conflict');
reject60(fn()=>r60Evidence([$header],[version60(72,2,74036)]),'current_history_typed_conflict','typed_snapshot_conflict');
foreach ([null,'0','131073'] as $bytes) {
    reject60(fn()=>r60Evidence([$header],[array_replace($current,['snapshot_bytes'=>$bytes])]),'snapshot_missing_or_too_large','snapshot_cap_or_absence');
}
reject60(fn()=>r60Version($current+['receipt_json'=>'forbidden']),'history_projection_changed','unexpected_private_column');
$bad = $current; $values = array_fill_keys(R60_FIELDS,null); $values['metadata_link'] = [];
$bad['typed_metadata_json'] = rrJson($values);
reject60(fn()=>r60Version($bad),'typed_projection_keys_changed','unexpected_metadata_key');
foreach (['74036x','074036','0','2147483648','74036\n'] as $id) {
    check60(rrId($id) === null,'invalid_native_id');
}
foreach (['https://max.ru/foreign/abc','https://max.ru/channel_anytour/abc?token=secret','https://max.ru/channel_anytour/abc#frag',"https://max.ru/channel_anytour/abc\n"] as $url) {
    check60(r60PublicUrl($url) === null,'invalid_public_url');
}
check60(r60Logical("safe\n") === null && r60Logical('a b') === null && r60Logical('commercial-hotel-74036-v1') !== null,'logical_id_validation');
check60(rrSafeError(new RuntimeException('SQL secret raw query'))['error'] === 'bounded_read_failed','exception_redacted');
check60(!str_contains(rrJson($proof),'query_audit') && !str_contains(rrJson($proof),'metadata_link'),'no_extra_payload');

// An in-memory PDO double proves the single existing transaction and query order.
// It opens no database, files, socket or provider connection.
final class Read60Statement extends PDOStatement
{
    public function __construct(private Read60Pdo $owner, private string $sql) {}
    public function execute(?array $params = null): bool { $this->owner->seen[] = [$this->sql,$params]; return true; }
    public function fetchAll(int $mode = PDO::FETCH_DEFAULT, mixed ...$args): array { return array_shift($this->owner->answers); }
}
final class Read60Pdo extends PDO
{
    public array $seen = []; public array $answers; private bool $active = false;
    public function __construct(array $answers) { $this->answers = $answers; }
    public function getAttribute(int $attribute): mixed { return 'mysql'; }
    public function inTransaction(): bool { return $this->active; }
    public function exec(string $statement): int|false { $this->seen[] = [$statement]; if ($statement === 'START TRANSACTION READ ONLY') $this->active = true; return 0; }
    public function prepare(string $query, array $options = []): PDOStatement|false { return new Read60Statement($this,$query); }
    public function rollBack(): bool { $this->seen[] = ['ROLLBACK']; $this->active = false; return true; }
}
$root = getenv('REWAYA60_FIXTURE_SOURCE_ROOT') ?: dirname(__DIR__,3);
$answers = [];
foreach (['033_channel_post_context.sql','036_channel_post_member_delivery.sql','040_channel_post_reconciliation.sql'] as $m) {
    $sha = hash_file('sha256',$root.'/migrations/'.$m);
    check60(is_string($sha) && strlen($sha) === 64,'local_migration_fixture_present');
    $answers[] = [['checksum'=>$sha]];
}
$pdo = new Read60Pdo(array_merge($answers,[[$header],[$old,$current]]));
$transaction = null;
$read = r60Read($pdo,$root,$transaction);
check60($read['lineage']['complete'] && !$pdo->inTransaction(),'transaction_closed_success');
check60($transaction === ['started'=>true,'rollback_attempted'=>true,'rolled_back'=>true],'actual_transaction_receipt');
check60($pdo->seen[0] === ['SET TRANSACTION ISOLATION LEVEL REPEATABLE READ'] && $pdo->seen[1] === ['START TRANSACTION READ ONLY'] && end($pdo->seen) === ['ROLLBACK'],'read_only_repeatable_rollback');
check60(count($pdo->seen) === 8 && $pdo->seen[5] === [R60_HEADER_SQL,[60,72,R60_URL,R60_CAPTION]] && $pdo->seen[6] === [R60_HISTORY_SQL,[60]],'only_schema3_and_exact_two_selects');
check60(!isset($GLOBALS['RR_QUERY_AUDIT']),'query_audit_not_retained');
$pdo = new Read60Pdo(array_merge($answers,[[$header],array_fill(0,65,$current)]));
reject60(fn()=>r60Read($pdo,$root),'publication60_versions_coverage_limit_reached','transaction_sentinel_refusal');
check60(!$pdo->inTransaction() && end($pdo->seen) === ['ROLLBACK'],'transaction_closed_on_refusal');
$pdo = new Read60Pdo([[['checksum'=>str_repeat('0',64)]]]);
reject60(fn()=>r60Read($pdo,$root),'publishing_schema_not_accepted','schema_guard');
check60(count($pdo->seen) === 4 && end($pdo->seen) === ['ROLLBACK'],'schema_mismatch_stops_before_scoped_queries');
$active = new Read60Pdo([]); $active->exec('START TRANSACTION READ ONLY');
$transaction = null;
try { r60Read($active,$root,$transaction); } catch (Throwable $e) { check60($e->getMessage() === 'unexpected_existing_transaction','preexisting_transaction_refused'); }
check60($transaction === ['started'=>false,'rollback_attempted'=>false,'rolled_back'=>false] && $active->inTransaction(),'preexisting_transaction_not_owned_or_rolled_back');
echo 'REWAYA60_LINEAGE_SELF_TEST=', rrJson(['ok'=>true,'checks'=>$checks,'filesystem_writes'=>0,'database_connections'=>0,'database_writes'=>0,'provider_requests'=>0]), PHP_EOL;
