<?php
declare(strict_types=1);

function rrNeed(bool $ok, string $reason): void
{
    if (!$ok) throw new RuntimeException($reason);
}

function rrJson($value): string
{
    return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
}

function rrId($value): ?int
{
    if (!is_int($value) && !is_string($value)) return null;
    $text = (string)$value;
    if (!preg_match('/\A[1-9][0-9]{0,9}\z/D', $text) || (float)$text > 2147483647) return null;
    return (int)$text;
}

function rrRows(PDO $pdo, string $sql, array $parameters, int $limit, string $label): array
{
    rrNeed(str_starts_with($sql, 'SELECT ') && !str_contains($sql, 'FOR UPDATE'), 'read_query_rejected');
    $q = $pdo->prepare($sql);
    $q->execute($parameters);
    $rows = $q->fetchAll(PDO::FETCH_ASSOC);
    rrNeed(count($rows) <= $limit, $label.'_coverage_limit_reached');
    $GLOBALS['RR_QUERY_AUDIT'][] = ['label'=>$label, 'sql_sha256'=>hash('sha256', $sql),
        'parameter_count'=>count($parameters), 'row_count'=>count($rows), 'row_limit'=>$limit];
    return $rows;
}

function rrStartRead(PDO $pdo): void
{
    rrNeed($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql', 'mysql_read_boundary_required');
    rrNeed(!$pdo->inTransaction(), 'unexpected_existing_transaction');
    $pdo->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
    $pdo->exec('START TRANSACTION READ ONLY');
    rrNeed($pdo->inTransaction(), 'read_only_transaction_not_started');
}

function rrCatalogTables(PDO $db): array
{
    $tables = ['catalog_hotels','anex_hotel_search_mappings','anex_hotel_decisions','anex_review_pair_exclusions'];
    $rows = rrRows($db, "SELECT TABLE_NAME,TABLE_TYPE,ENGINE FROM information_schema.TABLES WHERE BINARY TABLE_SCHEMA=BINARY DATABASE() AND TABLE_NAME IN (?,?,?,?) ORDER BY TABLE_NAME LIMIT 5", $tables, 4, 'catalog_tables');
    $found = [];
    foreach ($rows as $row) {
        rrNeed(in_array($row['TABLE_NAME'], $tables, true) && !isset($found[$row['TABLE_NAME']]), 'catalog_table_identity_changed');
        rrNeed($row['TABLE_TYPE'] === 'BASE TABLE' && $row['ENGINE'] === 'InnoDB', 'catalog_consistent_table_required');
        $found[$row['TABLE_NAME']] = true;
    }
    foreach (array_slice($tables, 0, 3) as $table) rrNeed(isset($found[$table]), 'required_catalog_table_missing');
    // An absent exclusions table is the same legacy exception as the accepted
    // resolver. A view, permission error or broken dependency does not bypass it.
    return ['exclusions_table_present'=>isset($found['anex_review_pair_exclusions']), 'tables'=>$rows];
}

function rrPolicySql(): array
{
    $pairs = (new ReflectionClass(AnyTourAnexSearchMappingRegistry::class))->getConstant('APPROVED_CLASSES');
    rrNeed(is_array($pairs) && count($pairs) === 6, 'accepted_policy_contract_changed');
    $clauses = []; $parameters = [];
    foreach ($pairs as $policy=>$classes) {
        rrNeed(is_string($policy) && is_array($classes) && count($classes) >= 1, 'accepted_policy_contract_invalid');
        $clauses[] = '(m.approval_policy=? AND m.match_class IN ('.implode(',', array_fill(0, count($classes), '?')).'))';
        $parameters[] = $policy;
        foreach ($classes as $class) $parameters[] = $class;
    }
    return [implode(' OR ', $clauses), $parameters, $pairs];
}

function rrMappingSql(array $ids): array
{
    rrNeed(count($ids) === 48 && count(array_unique($ids, SORT_REGULAR)) === 48, 'anex_scope_changed');
    foreach ($ids as $id) rrNeed(is_int($id) && rrId($id) === $id, 'anex_scope_invalid');
    $marks = implode(',', array_fill(0, count($ids), '?'));
    [$policy, $parameters] = rrPolicySql();
    return [
        'mappings'=>['SELECT m.anex_hotel_id,m.catalog_hotel_id,m.match_class,m.approval_policy,m.enabled,m.scope,h.id AS existing_catalog_hotel_id FROM anex_hotel_search_mappings m INNER JOIN catalog_hotels h ON h.id=m.catalog_hotel_id WHERE m.anex_hotel_id IN ('.$marks.") AND m.enabled=1 AND m.scope='preview' AND (".$policy.') ORDER BY m.anex_hotel_id LIMIT 49', array_merge($ids, $parameters), 48],
        'mapping_diagnostics'=>['SELECT m.anex_hotel_id,m.catalog_hotel_id,m.match_class,m.approval_policy,m.enabled,m.scope,h.id AS existing_catalog_hotel_id FROM anex_hotel_search_mappings m LEFT JOIN catalog_hotels h ON h.id=m.catalog_hotel_id WHERE m.anex_hotel_id IN ('.$marks.') ORDER BY m.anex_hotel_id LIMIT 49', $ids, 48],
        'decisions'=>['SELECT d.anex_hotel_id,d.decision_status,d.catalog_hotel_id,h.id AS existing_catalog_hotel_id FROM anex_hotel_decisions d LEFT JOIN catalog_hotels h ON h.id=d.catalog_hotel_id WHERE d.anex_hotel_id IN ('.$marks.') ORDER BY d.anex_hotel_id LIMIT 49', $ids, 48],
        'exclusions'=>['SELECT anex_hotel_id,catalog_hotel_id FROM anex_review_pair_exclusions WHERE anex_hotel_id IN ('.$marks.') ORDER BY anex_hotel_id,catalog_hotel_id LIMIT 769', $ids, 768],
    ];
}

function rrReadCatalog(PDO $db, array $scope): array
{
    $ids = $scope['anex_ids'];
    $tableInfo = rrCatalogTables($db);
    $rows = []; $legacyExclusionsAbsent = false;
    foreach (rrMappingSql($ids) as $label=>[$sql,$parameters,$limit]) {
        try {
            $rows[$label] = rrRows($db, $sql, $parameters, $limit, $label);
        } catch (PDOException $error) {
            $info = $error->errorInfo ?? [];
            $missing = $label === 'exclusions' && ($info[0] ?? null) === '42S02'
                && (int)($info[1] ?? 0) === 1146 && !$tableInfo['exclusions_table_present'];
            if (!$missing) throw $error;
            $rows[$label] = []; $legacyExclusionsAbsent = true;
        }
        foreach ($rows[$label] as $row) rrNeed(in_array(rrId($row['anex_hotel_id'] ?? null), $ids, true), 'foreign_anex_row');
    }
    $policy = AnyTourAnexSearchMappingRegistry::fromRows($rows['mappings']);
    $manual = AnyTourAnexSearchMappingRegistry::fromRows($rows['mappings'], $rows['decisions']);
    $accepted = AnyTourAnexSearchMappingRegistry::fromRows($rows['mappings'], $rows['decisions'], $rows['exclusions']);
    $items = []; $targets = [];
    foreach ($ids as $id) {
        $target = $accepted->resolve('anex_online', $id, 'preview');
        rrNeed($target === $accepted->resolve('anex_xml', $id, 'preview'), 'provider_resolver_disagreement');
        rrNeed($accepted->resolve('anex_online', $id, 'production') === null, 'production_scope_unexpectedly_enabled');
        if ($target !== null) $targets[$target] = true;
        $select = static fn(array $list): array => array_values(array_filter($list, static fn(array $r): bool => rrId($r['anex_hotel_id']) === $id));
        $decision = $select($rows['decisions']);
        $beforeExclusions = $manual->resolve('anex_online', $id, 'preview');
        $reason = $target !== null ? ($decision !== [] ? 'manual_accepted' : 'approved_policy_pair')
            : ($beforeExclusions !== null ? 'accepted_pair_excluded' : ($decision !== [] ? 'manual_decision_blocks_or_target_missing' : 'approved_mapping_absent'));
        $items[] = ['anex_id'=>$id, 'status'=>$target === null ? 'unresolved' : 'accepted_mapping',
            'catalog_hotel_id'=>$target, 'reason'=>$reason,
            'policy_target_before_manual'=>$policy->resolve('anex_online', $id, 'preview'),
            'target_before_exclusions'=>$beforeExclusions,
            'mapping_rows'=>$select($rows['mapping_diagnostics']), 'accepted_policy_rows'=>$select($rows['mappings']),
            'manual_decision_rows'=>$decision, 'pair_exclusions'=>$select($rows['exclusions'])];
    }
    $nativeIds = array_map('intval', array_keys($targets));
    foreach ($scope['old_max_posts'] as $old) $nativeIds[] = $old['candidate_catalog_hotel_id'];
    $nativeIds = array_values(array_unique($nativeIds)); sort($nativeIds, SORT_NUMERIC);
    rrNeed(count($nativeIds) <= 51, 'native_target_scope_exceeded');
    $native = rrRows($db, 'SELECT id,name,country_id,country_name,region_id,region_name,subregion_name,category,is_active,last_seen_at FROM catalog_hotels WHERE id IN ('.implode(',', array_fill(0, count($nativeIds), '?')).') ORDER BY id LIMIT 52', $nativeIds, 51, 'resolved_native_targets');
    $seen = [];
    foreach ($native as $r) { $n = rrId($r['id']); rrNeed(in_array($n, $nativeIds, true) && !isset($seen[$n]), 'native_target_row_invalid'); $seen[$n] = true; }
    foreach (array_keys($targets) as $id) rrNeed(isset($seen[$id]), 'accepted_native_target_disappeared');
    return ['status'=>'complete', 'complete'=>true, 'source_namespace'=>'anex_hotel_id',
        'target_namespace'=>'legacy_catalog/catalog_hotels.id', 'resolver_scope'=>'preview',
        'resolver_blob_sha'=>REMAINING_RESOLVER_BLOB_SHA, 'resolver_sha256'=>REMAINING_RESOLVER_SHA256,
        'exclusions_state'=>$legacyExclusionsAbsent ? 'confirmed_legacy_table_absent' : 'read_complete',
        'policy_class_pairs'=>rrPolicySql()[2], 'schema_tables'=>$tableInfo['tables'],
        'input_count'=>48, 'accepted_source_count'=>count(array_filter($items, static fn(array $r): bool => $r['status'] === 'accepted_mapping')),
        'unique_target_count'=>count($targets), 'unresolved_source_count'=>count(array_filter($items, static fn(array $r): bool => $r['status'] === 'unresolved')),
        'rows'=>$items, 'native_targets'=>$native, 'native_post_lookup_ids'=>$nativeIds,
        'binding_writes'=>0, 'resolved_identity_is_not_publication_readiness'=>true];
}

function rrTypedReferences(array $value, string $path = '$.metadata', int $depth = 0): array
{
    rrNeed($depth <= 8 && count($value) <= 128, 'public_metadata_structure_too_large');
    $out = [];
    foreach ($value as $key=>$child) {
        if (!is_string($key) && !is_int($key)) continue;
        $next = $path.'.'.$key;
        if (in_array($key, ['hotel_catalog_id','catalog_hotel_id','tourvisor_id','tourvisorId','native_hotel_id','hotel_id'], true)) {
            $id = rrId($child);
            if ($id !== null) $out[] = ['path'=>$next, 'id'=>$id,
                'accepted_native_field'=>$next === '$.metadata.hotel_catalog_id',
                'namespace'=>$next === '$.metadata.hotel_catalog_id' ? 'legacy_catalog/catalog_hotels.id' : 'unresolved_reference_namespace'];
        }
        if (is_array($child)) $out = array_merge($out, rrTypedReferences($child, $next, $depth + 1));
    }
    return $out;
}

function rrSnapshot(string $raw): array
{
    rrNeed(strlen($raw) <= 131072, 'snapshot_byte_limit_reached');
    $s = json_decode($raw, true, 64, JSON_THROW_ON_ERROR);
    rrNeed(is_array($s) && is_array($s['metadata'] ?? []), 'public_snapshot_invalid');
    $meta = $s['metadata'] ?? [];
    $logical = $meta['logical_id'] ?? null;
    if (!is_string($logical) || preg_match('/\A[A-Za-z0-9_.:-]{1,191}\z/D', $logical) !== 1) $logical = null;
    $url = $s['post_url'] ?? null;
    if (!is_string($url) || preg_match('~\Ahttps://(?:max\.ru/channel_anytour/[A-Za-z0-9_-]+|t\.me/any_toour/[1-9][0-9]*)\z~D', $url) !== 1) $url = null;
    $sourceEvent = $meta['source_event_id'] ?? null;
    return ['snapshot_sha256'=>hash('sha256', $raw), 'caption_sha256'=>hash('sha256', (string)($s['text'] ?? '')),
        'post_url'=>$url, 'hotel_catalog_id'=>rrId($meta['hotel_catalog_id'] ?? null), 'logical_id'=>$logical,
        'metadata_field_names'=>array_keys($meta), 'typed_reference_fields'=>rrTypedReferences($meta),
        'source_event_id_sha256'=>is_string($sourceEvent) ? hash('sha256', $sourceEvent) : null,
        'observation_kind'=>in_array($meta['observation_kind'] ?? null, ['provider_read','webhook'], true) ? $meta['observation_kind'] : null,
        'metadata_link_present'=>isset($meta['link']),
        'metadata_link_sha256'=>isset($meta['link']) ? hash('sha256', rrJson($meta['link'])) : null,
        'metadata_link_field_names'=>is_array($meta['link'] ?? null) ? array_keys($meta['link']) : [],
        'public_caption_or_name_used_as_native_binding'=>false];
}

function rrOldRelation(array $current, array $history, int $candidate): array
{
    $id = $current['hotel_catalog_id'];
    $historyIds = [];
    foreach ($history as $r) if ($r['snapshot']['hotel_catalog_id'] !== null) $historyIds[$r['snapshot']['hotel_catalog_id']] = true;
    $state = $id === $candidate ? 'current_exact_native_metadata_confirmed'
        : ($id !== null ? 'current_native_metadata_conflicts_with_candidate'
            : (isset($historyIds[$candidate]) ? 'historical_native_only_needs_current_review' : 'native_relation_unresolved'));
    return ['status'=>$state, 'candidate_catalog_hotel_id'=>$candidate,
        'current_catalog_hotel_id'=>$id, 'historical_catalog_hotel_ids'=>array_map('intval', array_keys($historyIds)),
        'native_relation_proven'=>$id === $candidate,
        'evidence_field'=>$id === $candidate ? 'post_versions.snapshot_json:$.metadata.hotel_catalog_id' : null,
        'button_token_alone_proves_hotel_identity'=>false, 'new_publication_authorized_by_reader'=>false];
}

function rrReadOldMax(PDO $pdo, array $scope): array
{
    $registry = new ChannelPostRegistryService($pdo);
    $channel = $registry->channel('anytour', 'max', 'id9704048781_1_bot', '-80492244209683');
    rrNeed(is_array($channel) && (int)$channel['id'] === 9 && (int)$channel['source_id'] === 5
        && $channel['source_key'] === 'max_anytour_msk1', 'old_max_channel_boundary_changed');
    $out = [];
    foreach ($scope['old_max_posts'] as $expected) {
        $id = $expected['publication_id'];
        $currentSize = rrRows($pdo, 'SELECT v.id AS version_id,LENGTH(v.snapshot_json) AS snapshot_bytes,LENGTH(v.receipt_json) AS receipt_bytes FROM post_publications pp JOIN post_versions v ON v.id=pp.current_version_id AND v.publication_id=pp.id WHERE pp.id=? LIMIT 2', [$id], 1, 'old_max_current_size_'.$id);
        rrNeed(count($currentSize) === 1 && (int)$currentSize[0]['snapshot_bytes'] <= 131072
            && (int)$currentSize[0]['receipt_bytes'] <= 131072, 'old_max_current_snapshot_too_large');
        $p = $registry->publication($id);
        rrNeed(is_array($p) && (int)$p['publication_id'] === $id && $p['project_key'] === 'anytour'
            && $p['platform'] === 'max' && $p['bot_key'] === 'id9704048781_1_bot'
            && (string)$p['channel_id'] === '-80492244209683'
            && (int)$p['source_id'] === 5 && $p['source_key'] === 'max_anytour_msk1'
            && $p['external_message_id'] === $expected['external_message_id'], 'old_max_exact_publication_changed');
        rrNeed(hash_equals($expected['start_payload_sha256'], hash('sha256', $p['start_payload'])), 'old_max_token_changed');
        rrNeed(($p['snapshot']['post_url'] ?? null) === $expected['post_url'], 'old_max_permalink_changed');
        $resolved = $registry->resolveToken('anytour', 'max', 'id9704048781_1_bot', $p['start_payload']);
        rrNeed(is_array($resolved) && (int)$resolved['publication_id'] === $id && (int)$resolved['version_id'] === (int)$p['version_id'], 'old_max_exact_token_resolution_failed');
        $buttonUrl = rtrim((string)$channel['bot_url'], '/').'?start='.rawurlencode($p['start_payload']);
        $sql = 'SELECT id AS version_id,publication_id,version_number,state,content_hash,write_attempts,CASE WHEN claim_token IS NULL AND claimed_at IS NULL THEN 0 ELSE 1 END AS claim_present,CASE WHEN LENGTH(snapshot_json)<=131072 THEN snapshot_json ELSE NULL END AS snapshot_json FROM post_versions WHERE publication_id=? ORDER BY id LIMIT 65';
        $versions = rrRows($pdo, $sql, [$id], 64, 'old_max_versions_'.$id);
        $history = []; $current = null; $archive = null;
        foreach ($versions as $v) {
            rrNeed((int)$v['publication_id'] === $id && is_string($v['snapshot_json']), 'old_max_version_unavailable');
            $v['snapshot'] = rrSnapshot($v['snapshot_json']); unset($v['snapshot_json']);
            $v['version_id'] = (int)$v['version_id']; $v['publication_id'] = (int)$v['publication_id'];
            $v['version_number'] = (int)$v['version_number']; $v['claim_present'] = (bool)$v['claim_present'];
            if ($v['version_id'] === (int)$p['version_id']) $current = $v;
            if ($v['version_id'] === $expected['expected_archival_version_id']) $archive = $v;
            $history[] = $v;
        }
        rrNeed($current !== null && $archive !== null && hash_equals($expected['archive_caption_sha256'], $archive['snapshot']['caption_sha256']), 'old_max_immutable_archive_changed');
        $relation = rrOldRelation($current['snapshot'], $history, $expected['candidate_catalog_hotel_id']);
        if ($p['state'] !== 'sent' || !empty($p['claim_token']) || !empty($p['claimed_at'])) {
            $relation['status'] = 'current_publication_not_stable';
            $relation['native_relation_proven'] = false;
        }
        $out[] = ['publication_id'=>$id, 'current_version_id'=>(int)$p['version_id'],
            'expected_archival_version_id'=>$expected['expected_archival_version_id'],
            'external_message_id'=>$expected['external_message_id'], 'post_url'=>$expected['post_url'],
            'current_state'=>$p['state'], 'current_claim_present'=>!empty($p['claim_token']) || !empty($p['claimed_at']),
            'start_payload_sha256'=>$expected['start_payload_sha256'],
            'exact_button_resolves_publication'=>true,
            'button_observed_in_current_snapshot'=>ChannelPostIncomingAdapter::containsOnlyButton($p['snapshot'], $buttonUrl, (string)$channel['button_text']),
            'button_label'=>$channel['button_text'], 'button_observation_source'=>'stored_registry_snapshot_no_live_provider_read',
            'relation'=>$relation, 'versions'=>$history];
    }
    return ['status'=>'complete', 'complete'=>true, 'rows'=>$out, 'publication_count'=>3,
        'proven_current_native_relations'=>count(array_filter($out, static fn(array $r): bool => $r['relation']['native_relation_proven'])),
        'provider_calls'=>0, 'customer_rows_read'=>0, 'identity_bindings_written'=>0];
}

function rrReadNativePosts(PDO $pdo, array $ids): array
{
    rrNeed(count($ids) >= 3 && count($ids) <= 51 && count(array_unique($ids, SORT_REGULAR)) === count($ids), 'native_post_scope_invalid');
    $native = "JSON_UNQUOTE(JSON_EXTRACT(v.snapshot_json,'$.metadata.hotel_catalog_id'))";
    $logical = "JSON_UNQUOTE(JSON_EXTRACT(v.snapshot_json,'$.metadata.logical_id'))";
    $marks = implode(',', array_fill(0, count($ids), '?'));
    $sql = "SELECT pp.id AS publication_id,pc.id AS post_channel_id,pc.platform,pc.bot_key,pc.channel_id,s.source_key,pc.is_enabled,s.is_active AS source_active,p.is_active AS project_active,s.handling_mode,v.id AS version_id,v.state,CASE WHEN v.claim_token IS NULL AND v.claimed_at IS NULL THEN 0 ELSE 1 END AS claim_present,".$native.' AS hotel_catalog_id,'.$logical." AS logical_id,JSON_UNQUOTE(JSON_EXTRACT(v.snapshot_json,'$.post_url')) AS post_url FROM post_publications pp JOIN post_channels pc ON pc.id=pp.post_channel_id JOIN projects p ON p.id=pc.project_id JOIN conversation_sources s ON s.id=pp.source_id AND s.id=pc.source_id AND s.project_id=pc.project_id AND s.channel=pc.platform JOIN post_versions v ON v.id=pp.current_version_id AND v.publication_id=pp.id WHERE p.project_key='anytour' AND ((pc.id=9 AND pc.platform='max' AND pc.bot_key='id9704048781_1_bot' AND pc.channel_id='-80492244209683') OR (pc.id=10 AND pc.platform='telegram' AND pc.bot_key='anyru_bot' AND pc.channel_id='-1002262666616')) AND (BINARY ".$native.' IN ('.$marks.') OR BINARY '.$logical.' IN ('.$marks.')) ORDER BY pp.id LIMIT 513';
    $params = array_merge(array_map('strval', $ids), array_map(static fn(int $id): string => 'commercial-hotel-'.$id.'-v1', $ids));
    $rows = rrRows($pdo, $sql, $params, 512, 'current_native_publications');
    foreach ($rows as &$row) {
        $id = rrId($row['hotel_catalog_id']);
        $row['hotel_catalog_id'] = $id;
        $row['publication_id'] = (int)$row['publication_id']; $row['version_id'] = (int)$row['version_id'];
        $row['claim_present'] = (bool)$row['claim_present'];
        $row['exact_native_metadata_match'] = $id !== null && in_array($id, $ids, true);
        $row['logical_id_alone_accepted_as_native_binding'] = false;
        $row['source_active_and_manager_routed'] = (int)$row['is_enabled'] === 1 && (int)$row['source_active'] === 1
            && (int)$row['project_active'] === 1 && in_array($row['handling_mode'], ['manager','ask'], true);
    }
    unset($row);
    return ['status'=>'complete', 'complete'=>true, 'lookup_catalog_hotel_ids'=>$ids,
        'rows'=>$rows, 'row_count'=>count($rows), 'absence_is_not_full_alias_or_duplicate_clearance'=>true];
}

function rrSafeError(Throwable $error): array
{
    $message = $error->getMessage();
    return ['status'=>'error', 'complete'=>false, 'error_class'=>get_class($error),
        'error'=>preg_match('/\A[a-z][a-z0-9_]{1,100}\z/D', $message) === 1 ? $message : 'bounded_read_failed',
        'sqlstate'=>$error instanceof PDOException ? ($error->errorInfo[0] ?? null) : null,
        'failure_is_not_absence'=>true];
}
