<?php

declare(strict_types=1);

/**
 * Bounded, detached projection of caller-supplied typed evidence.
 *
 * This contract does not extract facts from text, verify reports, grant send
 * authority or own any runtime/customer/company state. The entire envelope,
 * including evidence after the requested cutoff, must pass validation first.
 */
final class ManagerRequestMemoryProjection
{
    private const MAX_BYTES = 262144;
    private const MAX_DEPTH = 12;
    private const MAX_STRING_CHARS = 4000;
    private const COMMON_KEYS = ['id', 'type', 'request_id', 'evidence_ordinals'];
    private const REQUIREMENT_FIELDS = [
        'product_mode', 'trip_and_revision', 'party_actual', 'room_plan',
        'destination_constraints', 'travel_dates', 'duration',
        'departure_and_connections', 'flight_requirements', 'budget',
        'meal_and_room_features', 'hard_needs_and_eligibility',
        'open_questions_and_outcome',
    ];
    private const RELATIONSHIPS = [
        'unknown', 'new_request', 'conditional_alternative',
        'parallel_alternative', 'later_request',
    ];
    private const EVIDENCE_STATES = [
        'customer_stated', 'unattributed_request_text', 'ambiguous',
        'mixed_customer_and_owner_default',
    ];
    private const LIFECYCLE_EVENTS = [
        'payment_reported', 'payment_cancellation_reported', 'refund_reported',
        'booking_pending_reported', 'booking_confirmed_reported',
        'documents_sent_reported', 'trip_completed_reported',
    ];

    public static function project(array $input, int $cutoffOrdinal): array
    {
        self::keys($input, [
            'schema_version', 'conversation_ref', 'source_revision',
            'source_record_count', 'support', 'events',
        ]);
        self::listValue($input['support'], 1200);
        self::listValue($input['events'], 1000);

        // A fresh recursive copy also removes PHP reference aliases. Its small
        // running lower byte bound stops oversized/cyclic input before encoding.
        $minimumBytes = 0;
        $input = self::copyJson($input, 0, $minimumBytes);
        try {
            $encoded = json_encode($input, JSON_UNESCAPED_UNICODE
                | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION
                | JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            self::invalid('JSON encoding');
        }
        if (strlen($encoded) > self::MAX_BYTES) self::invalid('encoded size');
        if ($input['schema_version'] !== 1) self::invalid('schema version');
        self::nonemptyString($input['conversation_ref']);
        if (!is_string($input['source_revision'])
            || preg_match('/\A[a-f0-9]{64}\z/', $input['source_revision']) !== 1) {
            self::invalid('source revision');
        }
        $recordCount = $input['source_record_count'];
        if (!is_int($recordCount) || $recordCount < 1) self::invalid('record count');
        if ($cutoffOrdinal < 1 || $cutoffOrdinal > $recordCount) {
            self::invalid('cutoff');
        }

        $support = self::support($input['support'], $recordCount);
        $events = self::events($input['events'], $support, $recordCount);
        $requests = [];
        $applied = [];
        foreach ($events as $event) {
            if ($event['known_after_ordinal'] > $cutoffOrdinal) continue;
            $requestId = $event['request_id'];
            $evidence = $event['evidence'];
            switch ($event['type']) {
                case 'open_request':
                    $requests[$requestId] = [
                        'relationship' => $event['relationship'],
                        'related_request_id' => $event['related_request_id'],
                        'open_evidence' => $evidence,
                        'requirements' => [],
                        'requirement_history' => [],
                        'offers' => [],
                        'accepted_scopes' => [],
                        'lifecycle_events' => [],
                    ];
                    break;
                case 'requirement':
                    $field = $event['field'];
                    if (array_key_exists($field, $requests[$requestId]['requirements'])) {
                        $prior = $requests[$requestId]['requirements'][$field];
                        $prior['superseded_by'] = $event['id'];
                        $requests[$requestId]['requirement_history'][$field][] = $prior;
                    }
                    $requests[$requestId]['requirements'][$field] = [
                        'value' => $event['value'],
                        'evidence_state' => $event['evidence_state'],
                        'strength' => $event['strength'],
                        'hypothetical' => $event['hypothetical'],
                        'known_after_ordinal' => $event['known_after_ordinal'],
                        'event_id' => $event['id'],
                        'evidence' => $evidence,
                        'subfield_provenance' => $event['subfield_provenance'],
                    ];
                    break;
                case 'offer':
                    $requests[$requestId]['offers'][$event['offer_id']] = [
                        'fields' => $event['fields'],
                        'evidence' => $evidence,
                        'accepted' => false,
                        'current_price_verified' => false,
                        'historical_send_snapshot' => false,
                    ];
                    break;
                case 'accept_scope':
                    // Acceptance records only its explicit scope. Neither a
                    // whole offer nor a requirement is implicitly rewritten.
                    $requests[$requestId]['accepted_scopes'][] = [
                        'scope' => $event['scope'],
                        'value' => $event['value'] ?? null,
                        'offer_id' => $event['offer_id'] ?? null,
                        'accepted_fields' => $event['accepted_fields'] ?? [],
                        'evidence' => $evidence,
                    ];
                    break;
                case 'lifecycle':
                    $requests[$requestId]['lifecycle_events'][] = [
                        'event' => $event['event'],
                        'evidence' => $evidence,
                        'operation_id' => $event['operation_id'] ?? null,
                        'financial_ledger_verified' => false,
                        'reported_outcome_only' => true,
                    ];
                    break;
            }
            $applied[] = $event['id'];
        }

        return [
            'schema' => 'manager_request_memory_projection_v1',
            'conversation_ref' => $input['conversation_ref'],
            'source_revision' => $input['source_revision'],
            'cutoff_ordinal' => $cutoffOrdinal,
            'requests' => $requests,
            'applied_event_ids' => $applied,
            'production_writes' => false,
            'coverage' => 'explicit_evidence_events_not_automatic_extraction',
        ];
    }

    private static function support(array $items, int $recordCount): array
    {
        $support = [];
        $messageRefs = [];
        foreach ($items as $item) {
            self::mapValue($item);
            self::keys($item, ['ordinal', 'message_ref', 'direction', 'date_raw']);
            $ordinal = $item['ordinal'];
            if (!is_int($ordinal) || $ordinal < 1 || $ordinal > $recordCount
                || isset($support[$ordinal])) self::invalid('support ordinal');
            self::nonemptyString($item['message_ref']);
            $refKey = 'ref:' . $item['message_ref'];
            if (isset($messageRefs[$refKey])) self::invalid('duplicate message reference');
            self::enumValue($item['direction'], ['IN', 'OUT', 'SERVICE']);
            if ($item['date_raw'] !== null && !is_string($item['date_raw'])) {
                self::invalid('raw source date');
            }
            $support[$ordinal] = [
                'ordinal' => $ordinal,
                'message_ref' => $item['message_ref'],
                'direction' => $item['direction'],
                'date_raw' => $item['date_raw'],
            ];
            $messageRefs[$refKey] = true;
        }
        return $support;
    }

    /** Validate the complete graph before any cutoff-dependent projection. */
    private static function events(array $items, array $support, int $recordCount): array
    {
        $eventIds = [];
        $requests = [];
        $offers = [];
        $events = [];
        $previousKnown = 0;
        foreach ($items as $event) {
            self::mapValue($event);
            foreach (self::COMMON_KEYS as $key) {
                if (!array_key_exists($key, $event)) self::invalid('missing event key');
            }
            self::identifier($event['id']);
            self::identifier($event['request_id']);
            if (isset($eventIds[$event['id']])) self::invalid('duplicate event id');
            self::enumValue($event['type'], [
                'open_request', 'requirement', 'offer', 'accept_scope', 'lifecycle',
            ]);
            $ordinals = self::ordinals($event['evidence_ordinals'], $support, $recordCount);
            $known = max($ordinals);
            if ($known < $previousKnown) self::invalid('event source order');
            $previousKnown = $known;
            $requestId = $event['request_id'];
            if ($event['type'] !== 'open_request' && !isset($requests[$requestId])) {
                self::invalid('request must be opened earlier');
            }

            switch ($event['type']) {
                case 'open_request':
                    self::keys($event, self::COMMON_KEYS, ['relationship', 'related_request_id']);
                    if (isset($requests[$requestId])) self::invalid('duplicate request id');
                    $relationship = $event['relationship'] ?? 'unknown';
                    // A present null is not an omitted optional enum.
                    if (array_key_exists('relationship', $event)) {
                        self::enumValue($event['relationship'], self::RELATIONSHIPS);
                    }
                    if (array_key_exists('related_request_id', $event)) {
                        self::identifier($event['related_request_id']);
                        if (!isset($requests[$event['related_request_id']])) {
                            self::invalid('related request must be opened earlier');
                        }
                    }
                    $event['relationship'] = $relationship;
                    $event['related_request_id'] = $event['related_request_id'] ?? null;
                    $requests[$requestId] = $relationship;
                    break;
                case 'requirement':
                    self::keys($event, array_merge(self::COMMON_KEYS,
                        ['field', 'value', 'evidence_state']),
                        ['strength', 'hypothetical', 'subfield_provenance']);
                    self::enumValue($event['field'], self::REQUIREMENT_FIELDS);
                    self::enumValue($event['evidence_state'], self::EVIDENCE_STATES);
                    if (array_key_exists('strength', $event)) {
                        self::enumValue($event['strength'], ['hard', 'preferred', 'unknown']);
                    }
                    if (array_key_exists('hypothetical', $event)
                        && !is_bool($event['hypothetical'])) self::invalid('hypothetical flag');
                    if (($event['hypothetical'] ?? false)
                        && $requests[$requestId] !== 'conditional_alternative') {
                        self::invalid('hypothetical requirement outside conditional request');
                    }
                    $event['strength'] = $event['strength'] ?? 'unknown';
                    $event['hypothetical'] = $event['hypothetical'] ?? false;
                    if (array_key_exists('subfield_provenance', $event)) {
                        self::mapValue($event['subfield_provenance']);
                    }
                    $event['subfield_provenance'] = self::provenance(
                        $event['subfield_provenance'] ?? [], $ordinals, $support,
                        $recordCount, $event['evidence_state']);
                    break;
                case 'offer':
                    self::keys($event, array_merge(self::COMMON_KEYS, ['offer_id', 'fields']));
                    self::identifier($event['offer_id']);
                    if (isset($offers[$event['offer_id']])) self::invalid('duplicate offer id');
                    self::mapValue($event['fields'], false);
                    foreach ($event['fields'] as $field => $value) self::identifier($field);
                    $offers[$event['offer_id']] = [
                        'request_id' => $requestId, 'fields' => $event['fields'],
                    ];
                    break;
                case 'accept_scope':
                    self::keys($event, array_merge(self::COMMON_KEYS, ['scope']),
                        ['value', 'offer_id', 'accepted_fields']);
                    self::enumValue($event['scope'], ['service_format', 'specific_offer_change']);
                    foreach ($ordinals as $ordinal) {
                        if ($support[$ordinal]['direction'] !== 'IN') {
                            self::invalid('acceptance requires inbound evidence');
                        }
                    }
                    if ($event['scope'] === 'service_format') {
                        if (array_key_exists('offer_id', $event)
                            || array_key_exists('accepted_fields', $event)) {
                            self::invalid('service acceptance has no offer fields');
                        }
                    } else {
                        if (!array_key_exists('offer_id', $event)
                            || !array_key_exists('accepted_fields', $event)) {
                            self::invalid('specific acceptance requires offer fields');
                        }
                        self::identifier($event['offer_id']);
                        if (!isset($offers[$event['offer_id']])
                            || $offers[$event['offer_id']]['request_id'] !== $requestId) {
                            self::invalid('acceptance offer reference');
                        }
                        self::listValue($event['accepted_fields'], self::MAX_BYTES);
                        if ($event['accepted_fields'] === []) self::invalid('empty accepted fields');
                        $accepted = [];
                        foreach ($event['accepted_fields'] as $field) {
                            self::identifier($field);
                            if (isset($accepted[$field])
                                || !array_key_exists($field, $offers[$event['offer_id']]['fields'])) {
                                self::invalid('accepted field reference');
                            }
                            $accepted[$field] = true;
                        }
                    }
                    break;
                case 'lifecycle':
                    self::keys($event, array_merge(self::COMMON_KEYS, ['event']), ['operation_id']);
                    self::enumValue($event['event'], self::LIFECYCLE_EVENTS);
                    if (array_key_exists('operation_id', $event)) {
                        self::nonemptyString($event['operation_id']);
                    }
                    break;
            }
            $event['known_after_ordinal'] = $known;
            $event['evidence'] = [];
            foreach ($ordinals as $ordinal) $event['evidence'][] = $support[$ordinal];
            $eventIds[$event['id']] = true;
            $events[] = $event;
        }
        return $events;
    }

    private static function provenance($value, array $eventOrdinals, array $support,
        int $recordCount, string $state): array
    {
        self::mapValue($value);
        $customer = false;
        $owner = false;
        foreach ($value as $field => $entry) {
            self::identifier($field);
            self::mapValue($entry);
            self::keys($entry, ['kind'], ['evidence_ordinals', 'source', 'revision']);
            self::enumValue($entry['kind'], ['customer_statement', 'owner_default', 'unknown']);
            foreach (['source', 'revision'] as $key) {
                if (array_key_exists($key, $entry) && !is_string($entry[$key])) {
                    self::invalid('provenance string');
                }
            }
            if (array_key_exists('evidence_ordinals', $entry)) {
                $ordinals = self::ordinals($entry['evidence_ordinals'], $support, $recordCount, false);
                foreach ($ordinals as $ordinal) {
                    if (!in_array($ordinal, $eventOrdinals, true)) {
                        self::invalid('provenance outside event evidence');
                    }
                }
            }
            if ($entry['kind'] === 'customer_statement') {
                if (empty($entry['evidence_ordinals'])) self::invalid('customer provenance evidence');
                $customer = true;
            }
            if ($entry['kind'] === 'owner_default') {
                self::nonemptyString($entry['source'] ?? null);
                self::nonemptyString($entry['revision'] ?? null);
                $owner = true;
            }
        }
        if ($state === 'mixed_customer_and_owner_default' && (!$customer || !$owner)) {
            self::invalid('mixed provenance requires customer and owner');
        }
        if ($state === 'customer_stated' && $owner) {
            self::invalid('owner default cannot be customer stated');
        }
        return $value;
    }

    private static function ordinals($value, array $support, int $recordCount,
        bool $nonempty = true): array
    {
        self::listValue($value, 1200);
        if ($nonempty && $value === []) self::invalid('empty evidence');
        $seen = [];
        foreach ($value as $ordinal) {
            if (!is_int($ordinal) || $ordinal < 1 || $ordinal > $recordCount
                || !isset($support[$ordinal]) || isset($seen[$ordinal])) {
                self::invalid('evidence ordinal reference');
            }
            $seen[$ordinal] = true;
        }
        return $value;
    }

    /** Root array depth is one; only nested containers increase JSON depth. */
    private static function copyJson($value, int $depth, int &$minimumBytes)
    {
        if (is_array($value)) {
            if (++$depth > self::MAX_DEPTH) self::invalid('JSON nesting');
            self::addBytes($minimumBytes, 2 + max(0, count($value) - 1));
            $list = self::isList($value);
            $copy = [];
            foreach ($value as $key => $child) {
                if (!$list) {
                    if (!is_string($key)) self::invalid('JSON map key');
                    self::stringValue($key);
                    self::addBytes($minimumBytes, strlen($key) + 3);
                }
                $copy[$key] = self::copyJson($child, $depth, $minimumBytes);
            }
            return $copy;
        }
        if (is_string($value)) {
            self::stringValue($value);
            self::addBytes($minimumBytes, strlen($value) + 2);
        } elseif (is_int($value)) {
            self::addBytes($minimumBytes, strlen((string)$value));
        } elseif (is_float($value)) {
            if (!is_finite($value)) self::invalid('nonfinite number');
            self::addBytes($minimumBytes, 1);
        } elseif (is_bool($value)) {
            self::addBytes($minimumBytes, $value ? 4 : 5);
        } elseif ($value === null) {
            self::addBytes($minimumBytes, 4);
        } else {
            self::invalid('non-JSON value');
        }
        return $value;
    }

    private static function addBytes(int &$total, int $bytes): void
    {
        $total += $bytes;
        if ($total > self::MAX_BYTES) self::invalid('encoded size');
    }

    private static function stringValue($value): void
    {
        if (!is_string($value)
            || preg_match('/\A.{0,' . self::MAX_STRING_CHARS . '}\z/us', $value) !== 1) {
            self::invalid('UTF-8 string or string length');
        }
    }

    private static function nonemptyString($value): void
    {
        self::stringValue($value);
        if ($value === '') self::invalid('empty string');
    }

    private static function identifier($value): void
    {
        if (!is_string($value)
            || preg_match('/\A[A-Za-z][A-Za-z0-9_-]{0,63}\z/', $value) !== 1) {
            self::invalid('identifier');
        }
    }

    private static function enumValue($value, array $allowed): void
    {
        if (!is_string($value) || !in_array($value, $allowed, true)) {
            self::invalid('unsupported enum');
        }
    }

    private static function isList(array $value): bool
    {
        $next = 0;
        foreach ($value as $key => $child) {
            if ($key !== $next++) return false;
        }
        return true;
    }

    private static function listValue($value, int $limit): void
    {
        if (!is_array($value) || count($value) > $limit || !self::isList($value)) {
            self::invalid('bounded list');
        }
    }

    private static function mapValue($value, bool $allowEmpty = true): void
    {
        // PHP's [] is also the supported representation of an empty JSON map.
        if (!is_array($value) || ($value === [] ? !$allowEmpty : self::isList($value))) {
            self::invalid('JSON map');
        }
    }

    private static function keys(array $value, array $required, array $optional = []): void
    {
        foreach ($required as $key) {
            if (!array_key_exists($key, $value)) self::invalid('missing key');
        }
        $allowed = array_merge($required, $optional);
        foreach ($value as $key => $child) {
            if (!is_string($key) || !in_array($key, $allowed, true)) {
                self::invalid('unsupported key');
            }
        }
    }

    private static function invalid(string $reason): void
    {
        // Never include caller evidence or personal literals in diagnostics.
        throw new InvalidArgumentException('Invalid request memory evidence: ' . $reason . '.');
    }
}
