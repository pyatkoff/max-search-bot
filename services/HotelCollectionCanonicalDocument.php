<?php
declare(strict_types=1);

/** Pure, bounded append-only overlay. The store owns locks, authority and reserves. */
final class HotelCollectionCanonicalDocument
{
    public const MAX_PACKET_BYTES = 262144;
    public const MAX_ROWS = 128;
    private const MAX_OVERLAY_BYTES = 16777216;
    private const MAX_INDEX_ROWS = 20000;
    private const GROUPS = ['facts', 'sources', 'read_updates', 'questions'];
    private const CONTEXT = ['queue_id', 'operation_id', 'stage_id', 'country', 'scope_id', 'source_packet_id', 'hotel_keys', 'base_revision', 'baseline_sha256'];
    private const ENVELOPE = ['schema_version', 'queue_id', 'operation_id', 'stage_id', 'country', 'scope_id', 'source_packet_id', 'base_revision', 'baseline_sha256', 'facts', 'sources', 'read_updates', 'questions'];

    public static function emptyOverlay(string $baselineSha256): array
    {
        self::sha($baselineSha256);
        return ['schema_version' => 1, 'baseline_sha256' => $baselineSha256,
            'facts' => [], 'sources' => [], 'read_updates' => [], 'questions' => [], 'ledger' => []];
    }

    /** Shape check only: the control-owned index is an attestation about opaque bytes. */
    public static function validateBaselineIndex(array $index, string $baselineSha256): void
    {
        self::sha($baselineSha256); self::baselineIndex($index, $baselineSha256);
    }

    /** The store supplies the pinned baseline index; workers must never supply it. */
    public static function validate(string $rawPacket, array $trustedContext, array $overlay, array $trustedBaselineIndex): array
    {
        $context = self::context($trustedContext);
        $index = self::baselineIndex($trustedBaselineIndex, $context['baseline_sha256']);
        $state = self::state($overlay, $context['baseline_sha256'], $index);
        if (strlen($rawPacket) > self::MAX_PACKET_BYTES) self::invalid('packet_too_large');
        self::rejectDuplicateKeys($rawPacket);
        try { $decoded = json_decode($rawPacket, false, 32, JSON_THROW_ON_ERROR); }
        catch (JsonException $e) { self::invalid('invalid_json'); }
        $packet = self::object($decoded, self::ENVELOPE);
        if ($packet['schema_version'] !== 1) self::invalid('schema_version');
        foreach (array_diff(self::CONTEXT, ['hotel_keys']) as $key) {
            if ($packet[$key] !== $context[$key]) self::conflict('context_mismatch_'.$key);
        }
        $count = 0;
        foreach (self::GROUPS as $group) {
            $rows = self::list($packet[$group], self::MAX_ROWS);
            $clean = [];
            foreach ($rows as $row) {
                $row = self::row($group, $row);
                if (!in_array($row['hotel_key'], $context['hotel_keys'], true)) self::conflict('scope_mismatch');
                if (isset($state[$group][$row['id']]) || isset($clean[$row['id']])) self::conflict('id_already_exists_'.$group);
                $clean[$row['id']] = $row;
            }
            ksort($clean, SORT_STRING);
            $packet[$group] = array_values($clean);
            $count += count($clean);
        }
        if ($count === 0) self::invalid('empty_delta');
        $sources = $state['sources']; $facts = $state['facts'];
        foreach ($packet['sources'] as $row) $sources[$row['id']] = $row + ['country' => $context['country']];
        foreach ($packet['facts'] as $row) $facts[$row['id']] = $row + ['country' => $context['country']];
        self::references($packet, $sources, $facts, $context['country']);
        $counts = []; $content = ['schema_version' => 1, 'country' => $context['country']];
        foreach (self::GROUPS as $group) {
            $counts[$group] = count($packet[$group]); $content[$group] = $packet[$group];
        }
        return ['candidate' => $packet, 'semantic_sha256' => hash('sha256', self::canonical($content)),
            'identity_sha256' => hash('sha256', self::canonical($packet)), 'raw_sha256' => hash('sha256', $rawPacket), 'counts' => $counts];
    }

    /** Returns new values only. Packet data cannot supply ledger or reservation fields. */
    public static function apply(string $rawPacket, array $trustedContext, array $overlay, array $trustedBaselineIndex): array
    {
        $result = self::validate($rawPacket, $trustedContext, $overlay, $trustedBaselineIndex);
        $context = self::context($trustedContext);
        $next = $overlay;
        foreach (self::GROUPS as $group) {
            foreach ($result['candidate'][$group] as $row) {
                $next[$group][] = $row + ['country' => $context['country'], 'operation_id' => $context['operation_id']];
            }
        }
        $ledger = ['schema_version' => 1];
        foreach (array_diff(self::CONTEXT, ['hotel_keys']) as $key) $ledger[$key] = $context[$key];
        $ledger += ['packet_semantic_sha256' => $result['semantic_sha256'], 'packet_identity_sha256' => $result['identity_sha256'], 'packet_raw_sha256' => $result['raw_sha256'],
            'counts' => $result['counts'], 'business_accepted' => false, 'native_run_verified' => false];
        $next['ledger'][] = $ledger;
        foreach (array_merge(self::GROUPS, ['ledger']) as $group) self::list($next[$group], self::MAX_INDEX_ROWS);
        if (strlen(self::canonical($next)) > self::MAX_OVERLAY_BYTES) self::invalid('overlay_too_large');
        return $result + ['overlay' => $next, 'ledger_entry' => $ledger];
    }

    private static function context(array $value): array
    {
        $value = self::object($value, self::CONTEXT);
        foreach (['queue_id', 'operation_id', 'stage_id', 'scope_id', 'source_packet_id'] as $key) self::id($value[$key]);
        self::country($value['country']); self::sha($value['baseline_sha256']);
        if (!is_int($value['base_revision']) || $value['base_revision'] < 0) self::invalid('base_revision');
        $value['hotel_keys'] = self::ids($value['hotel_keys'], 32, true);
        return $value;
    }

    private static function baselineIndex(array $value, string $baseline): array
    {
        $value = self::object($value, ['baseline_sha256', 'facts', 'sources', 'read_update_ids', 'question_ids']);
        if ($value['baseline_sha256'] !== $baseline) self::conflict('baseline_index_mismatch');
        $out = ['facts' => [], 'sources' => [], 'read_updates' => [], 'questions' => []];
        foreach (['facts', 'sources'] as $group) {
            $keys = ['id', 'hotel_key', 'country'];
            if ($group === 'sources') $keys[] = 'evidence_sha256';
            foreach (self::list($value[$group], self::MAX_INDEX_ROWS) as $row) {
                $row = self::object($row, $keys);
                self::id($row['id']); self::id($row['hotel_key']); self::country($row['country']);
                if ($group === 'sources') self::sha($row['evidence_sha256']);
                if (isset($out[$group][$row['id']])) self::invalid('duplicate_baseline_id');
                $out[$group][$row['id']] = $row;
            }
        }
        foreach (['read_updates' => 'read_update_ids', 'questions' => 'question_ids'] as $group => $key) {
            foreach (self::ids($value[$key], self::MAX_INDEX_ROWS) as $id) $out[$group][$id] = true;
        }
        return $out;
    }

    private static function state(array $value, string $baseline, array $index): array
    {
        $value = self::object($value, ['schema_version', 'baseline_sha256', 'facts', 'sources', 'read_updates', 'questions', 'ledger']);
        if ($value['schema_version'] !== 1 || $value['baseline_sha256'] !== $baseline) self::conflict('overlay_baseline_mismatch');
        if (strlen(self::canonical($value)) > self::MAX_OVERLAY_BYTES) self::invalid('overlay_too_large');
        foreach (self::GROUPS as $group) {
            foreach (self::list($value[$group], self::MAX_INDEX_ROWS) as $row) {
                if (!is_array($row) || !isset($row['country'], $row['operation_id'])) self::invalid('overlay_row');
                $country = $row['country']; $operation = $row['operation_id'];
                self::country($country); self::id($operation);
                unset($row['country'], $row['operation_id']);
                $row = self::row($group, $row) + ['country' => $country, 'operation_id' => $operation];
                if (isset($index[$group][$row['id']])) self::invalid('overlay_duplicate_id');
                $index[$group][$row['id']] = $row;
            }
        }
        foreach (self::list($value['ledger'], self::MAX_INDEX_ROWS) as $row) self::ledger($row, $baseline);
        // Validate stored references too; a caller cannot substitute malformed prior state.
        foreach (self::GROUPS as $group) {
            foreach ($value[$group] as $row) {
                self::references([$group => [$row]], $index['sources'], $index['facts'], $row['country']);
            }
        }
        return $index;
    }

    private static function row(string $group, mixed $value): array
    {
        $fields = match ($group) {
            'sources' => ['id', 'hotel_key', 'url', 'kind', 'publication_date', 'retrieved_at', 'evidence_sha256', 'read_scope', 'limitations'],
            'facts' => ['id', 'hotel_key', 'criterion_id', 'text', 'kind', 'scope', 'source_ids', 'material_limits', 'valid_at_travel'],
            'read_updates' => ['id', 'hotel_key', 'source_id', 'evidence_sha256', 'read_scope', 'read_at', 'limitations'],
            'questions' => ['id', 'hotel_key', 'question_key', 'criterion_id', 'status', 'answer', 'fact_ids', 'remaining_unknowns'],
            default => throw new LogicException('canonical_document_unknown_group'),
        };
        $row = self::object($value, $fields);
        self::id($row['id']); self::id($row['hotel_key']);
        if ($group === 'sources') {
            self::text($row['url'], 2048);
            $url = parse_url($row['url']);
            if (preg_match('/\Ahttps:\/\/[^\s]+\z/Du', $row['url']) !== 1 || !is_array($url)
                || ($url['scheme'] ?? null) !== 'https' || empty($url['host']) || isset($url['user']) || isset($url['pass'])) self::invalid('source_url');
            self::enum($row['kind'], ['official', 'operator', 'retailer', 'guest_observation', 'other']);
            if ($row['publication_date'] !== null) self::date($row['publication_date'], false);
            self::date($row['retrieved_at'], true); self::sha($row['evidence_sha256']);
            self::text($row['read_scope'], 2000); self::text($row['limitations'], 2000, true);
        } elseif ($group === 'facts') {
            self::id($row['criterion_id']); self::text($row['text'], 4000);
            self::enum($row['kind'], ['official_declared', 'operator_declared', 'retailer_declared', 'guest_observation', 'research_interpretation']);
            $row['scope'] = self::object($row['scope'], ['category', 'age', 'season', 'product']);
            foreach ($row['scope'] as $item) if ($item !== null) self::text($item, 500);
            $row['source_ids'] = self::ids($row['source_ids'], 16, true);
            $row['material_limits'] = self::texts($row['material_limits']);
            if ($row['valid_at_travel'] !== 'unverified') self::invalid('future_validity_claim');
        } elseif ($group === 'read_updates') {
            self::id($row['source_id']); self::sha($row['evidence_sha256']);
            self::text($row['read_scope'], 2000); self::date($row['read_at'], true); self::text($row['limitations'], 2000, true);
        } else {
            self::id($row['question_key']); self::id($row['criterion_id']);
            self::enum($row['status'], ['HOLD', 'PARTIAL', 'RESOLVED']); self::text($row['answer'], 4000);
            $row['fact_ids'] = self::ids($row['fact_ids'], 32);
            $row['remaining_unknowns'] = self::texts($row['remaining_unknowns']);
            if ($row['status'] === 'RESOLVED' && (!$row['fact_ids'] || $row['remaining_unknowns'])) self::invalid('resolved_question_inconsistent');
        }
        return $row;
    }

    private static function references(array $packet, array $sources, array $facts, string $country): void
    {
        foreach ($packet['facts'] ?? [] as $row) {
            foreach ($row['source_ids'] as $id) self::reference($sources, $id, $row['hotel_key'], $country);
        }
        foreach ($packet['read_updates'] ?? [] as $row) {
            $source = self::reference($sources, $row['source_id'], $row['hotel_key'], $country);
            if ($row['evidence_sha256'] !== $source['evidence_sha256']) self::conflict('source_evidence_mismatch');
        }
        foreach ($packet['questions'] ?? [] as $row) {
            foreach ($row['fact_ids'] as $id) self::reference($facts, $id, $row['hotel_key'], $country);
        }
    }

    private static function reference(array $rows, string $id, string $hotel, string $country): array
    {
        $row = $rows[$id] ?? null;
        if (!is_array($row) || $row['hotel_key'] !== $hotel || $row['country'] !== $country) self::conflict('reference_scope_mismatch');
        return $row;
    }

    private static function ledger(mixed $value, string $baseline): void
    {
        $keys = array_merge(['schema_version'], array_values(array_diff(self::CONTEXT, ['hotel_keys'])),
            ['packet_semantic_sha256', 'packet_identity_sha256', 'packet_raw_sha256', 'counts', 'business_accepted', 'native_run_verified']);
        $row = self::object($value, $keys);
        if ($row['schema_version'] !== 1 || $row['baseline_sha256'] !== $baseline || $row['business_accepted'] !== false || $row['native_run_verified'] !== false) self::invalid('overlay_ledger');
        foreach (['queue_id', 'operation_id', 'stage_id', 'scope_id', 'source_packet_id'] as $key) self::id($row[$key]);
        self::country($row['country']); self::sha($row['packet_semantic_sha256']); self::sha($row['packet_identity_sha256']); self::sha($row['packet_raw_sha256']);
        if (!is_int($row['base_revision']) || $row['base_revision'] < 0) self::invalid('overlay_ledger_revision');
        $counts = self::object($row['counts'], self::GROUPS);
        foreach ($counts as $count) if (!is_int($count) || $count < 0 || $count > self::MAX_ROWS) self::invalid('overlay_ledger_counts');
    }

    private static function object(mixed $value, array $keys): array
    {
        if ($value instanceof stdClass) $value = get_object_vars($value);
        if (!is_array($value) || count($value) !== count($keys) || array_diff(array_keys($value), $keys) || array_diff($keys, array_keys($value))) self::invalid('object_keys');
        return $value;
    }
    private static function list(mixed $value, int $max): array
    {
        if (!is_array($value) || !array_is_list($value) || count($value) > $max) self::invalid('list_shape_or_limit');
        return $value;
    }
    private static function ids(mixed $value, int $max, bool $nonempty = false): array
    {
        $value = self::list($value, $max);
        if ($nonempty && !$value) self::invalid('empty_id_list');
        foreach ($value as $id) self::id($id);
        if (count(array_unique($value, SORT_STRING)) !== count($value)) self::invalid('duplicate_id');
        sort($value, SORT_STRING); return $value;
    }
    private static function texts(mixed $value): array
    {
        $value = self::list($value, 16);
        foreach ($value as $text) self::text($text, 2000);
        return $value;
    }
    private static function id(mixed $value): void
    {
        if (!is_string($value) || preg_match('/\A[a-zA-Z0-9][a-zA-Z0-9_.:-]{0,127}\z/D', $value) !== 1) self::invalid('id');
    }
    private static function country(mixed $value): void { self::enum($value, ['TH', 'VN', 'TR', 'EG']); }
    private static function sha(mixed $value): void
    {
        if (!is_string($value) || preg_match('/\A[a-f0-9]{64}\z/D', $value) !== 1) self::invalid('sha256');
    }
    private static function enum(mixed $value, array $values): void { if (!in_array($value, $values, true)) self::invalid('enum'); }
    private static function text(mixed $value, int $max, bool $empty = false): void
    {
        if (!is_string($value) || strlen($value) > $max || (!$empty && trim($value) === '') || preg_match('//u', $value) !== 1 || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', $value)) self::invalid('text');
    }
    private static function date(mixed $value, bool $timestamp): void
    {
        self::text($value, 20);
        $format = $timestamp ? '!Y-m-d\TH:i:s\Z' : '!Y-m-d';
        $date = DateTimeImmutable::createFromFormat($format, $value, new DateTimeZone('UTC'));
        if (!$date || $date->format($timestamp ? 'Y-m-d\TH:i:s\Z' : 'Y-m-d') !== $value) self::invalid('date');
    }
    private static function canonical(array $value): string
    {
        $sort = static function (array $row) use (&$sort): array {
            if (!array_is_list($row)) ksort($row, SORT_STRING);
            foreach ($row as &$value) if (is_array($value)) $value = $sort($value);
            return $row;
        };
        try { return json_encode($sort($value), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR); }
        catch (JsonException $e) { self::invalid('invalid_json_value'); }
    }

    /** Check decoded object keys before json_decode can overwrite duplicates. */
    private static function rejectDuplicateKeys(string $raw): void
    {
        $i = 0; self::scan($raw, $i, 0); self::space($raw, $i);
        if ($i !== strlen($raw)) self::invalid('invalid_json');
    }
    private static function scan(string $raw, int &$i, int $depth): void
    {
        if ($depth > 24) self::invalid('json_depth');
        self::space($raw, $i); $c = $raw[$i] ?? '';
        if ($c === '{') {
            ++$i; self::space($raw, $i); $keys = [];
            if (($raw[$i] ?? '') === '}') { ++$i; return; }
            while (true) {
                self::space($raw, $i); $key = self::scanString($raw, $i);
                if (isset($keys['k:'.$key])) self::invalid('duplicate_json_key');
                $keys['k:'.$key] = true; self::space($raw, $i);
                if (($raw[$i++] ?? '') !== ':') self::invalid('invalid_json');
                self::scan($raw, $i, $depth + 1); self::space($raw, $i); $end = $raw[$i++] ?? '';
                if ($end === '}') return;
                if ($end !== ',') self::invalid('invalid_json');
            }
        }
        if ($c === '[') {
            ++$i; self::space($raw, $i);
            if (($raw[$i] ?? '') === ']') { ++$i; return; }
            while (true) {
                self::scan($raw, $i, $depth + 1); self::space($raw, $i); $end = $raw[$i++] ?? '';
                if ($end === ']') return;
                if ($end !== ',') self::invalid('invalid_json');
            }
        }
        if ($c === '"') { self::scanString($raw, $i); return; }
        $start = $i;
        while (isset($raw[$i]) && !str_contains(" \t\r\n,]}", $raw[$i])) ++$i;
        if ($start === $i) self::invalid('invalid_json');
        try { json_decode(substr($raw, $start, $i - $start), false, 2, JSON_THROW_ON_ERROR); }
        catch (JsonException $e) { self::invalid('invalid_json'); }
    }
    private static function scanString(string $raw, int &$i): string
    {
        if (($raw[$i] ?? '') !== '"') self::invalid('invalid_json');
        $start = $i++;
        while (isset($raw[$i])) {
            if ($raw[$i] === '\\') { $i += 2; continue; }
            if ($raw[$i++] === '"') {
                try { $value = json_decode(substr($raw, $start, $i - $start), false, 2, JSON_THROW_ON_ERROR); }
                catch (JsonException $e) { self::invalid('invalid_json'); }
                if (!is_string($value)) self::invalid('invalid_json');
                return $value;
            }
        }
        self::invalid('invalid_json');
    }
    private static function space(string $raw, int &$i): void { while (isset($raw[$i]) && str_contains(" \t\r\n", $raw[$i])) ++$i; }
    private static function invalid(string $reason): never { throw new InvalidArgumentException('canonical_document_'.$reason); }
    private static function conflict(string $reason): never { throw new DomainException('canonical_document_'.$reason); }
}
