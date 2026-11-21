<?php
declare(strict_types=1);

/** Separate signed read purpose; private receipts never grant a claim or delivery. */
final class Max2ValueLookupProtocolV2
{
    public const PURPOSE = 'anytour-max2-own-early-reserve-lookup-v2';
    public const EVIDENCE_HEADER = 'max2-own-early-reserve-v2';
    public const PROJECT = '1538';
    public const COUNTER = 98615635;
    public const MODEL = 'max2_bootstrap_observed_value_v1';
    public const PRODUCER_CONTRACT_SHA256 = '3a563c1b7921915fd8b17c08f585cde88127904170a086c291a5677d49cf365e';
    public const MAX_BODY = 8192;
    public const MAX_RESPONSE = 65536;
    public const MAX_ROWS = 64;
    public const MAX_DB_BYTES = 32768;
    public const CLOCK_TOLERANCE = 90;
    public const MIN_UNIX = 946684800;
    public const COVERAGE = 'all_exact_actor_own_early_reserves_at_observation';
    public const CANDIDATE_KEYS = ['tracker_record_id', 'immutable_tuple_sha256', 'counter_id',
        'metric_project_id', 'external_user_id', 'post_id', 'offer_id', 'client_id', 'early_kopeks',
        'common_kopeks', 'model_version', 'issuance_state', 'recorded_at_unix'];

    public static function json(mixed $value): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }
    public static function keys(mixed $value, array $expected): bool
    {
        if (!is_array($value)) return false;
        $keys = array_keys($value); sort($keys); sort($expected);
        return $keys === $expected;
    }
    public static function id(mixed $value, int $digits = 20): bool
    {
        return is_string($value) && strlen($value) <= $digits && preg_match('/\A[1-9][0-9]*\z/D', $value) === 1;
    }
    public static function hash(mixed $value): bool
    {
        return is_string($value) && preg_match('/\A[a-f0-9]{64}\z/D', $value) === 1 && $value !== str_repeat('0', 64);
    }
    public static function need(bool $condition, string $reason): void
    {
        if (!$condition) throw new InvalidArgumentException($reason);
    }
    public static function context(array $context, int $now): void
    {
        self::need(self::keys($context, ['event_ref', 'frame_sha256', 'cutoff_unix'])
            && self::hash($context['event_ref']) && self::hash($context['frame_sha256'])
            && is_int($context['cutoff_unix']) && $context['cutoff_unix'] >= self::MIN_UNIX
            && $context['cutoff_unix'] <= $now, 'invalid_context');
    }
    public static function listBody(string $actor, array $context, int $now, ?string $nonce = null): string
    {
        $request = ['version' => 2, 'purpose' => self::PURPOSE, 'action' => 'list',
            'metric_project_id' => self::PROJECT, 'counter_id' => self::COUNTER,
            'external_user_id' => $actor, 'context' => $context,
            'producer_contract_sha256' => self::PRODUCER_CONTRACT_SHA256,
            'issued_at' => $now, 'nonce' => $nonce ?? bin2hex(random_bytes(16))];
        self::validateRequest($request, $now);
        return self::json($request);
    }
    /** Caller supplies the already validated private lookup receipt and selected immutable row. */
    public static function recheckBody(array $lookup, string $sourceReceiptSha, string $recordId,
        int $now, ?string $nonce = null): string
    {
        self::need(self::hash($sourceReceiptSha) && self::id($recordId)
            && ($lookup['action'] ?? null) === 'list' && ($lookup['status'] ?? null) === 'complete'
            && ($lookup['complete_within_bound'] ?? null) === true
            && ($lookup['producer_contract_sha256'] ?? null) === self::PRODUCER_CONTRACT_SHA256
            && self::hash($lookup['request_sha256'] ?? null) && self::hash($lookup['set_sha256'] ?? null)
            && is_int($lookup['checked_at_unix'] ?? null) && abs($now - $lookup['checked_at_unix']) <= self::CLOCK_TOLERANCE,
            'invalid_selection');
        $matches = array_values(array_filter($lookup['candidates'] ?? [],
            static fn($c): bool => is_array($c) && ($c['tracker_record_id'] ?? null) === $recordId));
        self::need(count($matches) === 1, 'invalid_selection');
        $request = json_decode(self::listBody($lookup['external_user_id'], $lookup['context'], $now, $nonce), true, 16, JSON_THROW_ON_ERROR);
        $request['action'] = 'recheck';
        $request['selection'] = ['lookup_request_sha256' => $lookup['request_sha256'],
            'source_receipt_sha256' => $sourceReceiptSha, 'set_sha256' => $lookup['set_sha256'],
            'checked_at_unix' => $lookup['checked_at_unix'], 'candidate' => $matches[0]];
        self::validateRequest($request, $now);
        return self::json($request);
    }
    public static function validateRequest(array $request, int $now): void
    {
        $keys = ['version', 'purpose', 'action', 'metric_project_id', 'counter_id', 'external_user_id',
            'context', 'producer_contract_sha256', 'issued_at', 'nonce'];
        if (($request['action'] ?? null) === 'recheck') $keys[] = 'selection';
        self::need(self::keys($request, $keys) && $request['version'] === 2 && $request['purpose'] === self::PURPOSE
            && in_array($request['action'], ['list', 'recheck'], true) && $request['metric_project_id'] === self::PROJECT
            && $request['counter_id'] === self::COUNTER && self::id($request['external_user_id'])
            && is_int($request['issued_at']) && $now >= self::MIN_UNIX
            && abs($now - $request['issued_at']) <= self::CLOCK_TOLERANCE
            && $request['producer_contract_sha256'] === self::PRODUCER_CONTRACT_SHA256
            && is_string($request['nonce']) && preg_match('/\A[a-f0-9]{32}\z/D', $request['nonce']) === 1
            && is_array($request['context']), 'invalid_request');
        self::context($request['context'], $now);
        if ($request['action'] === 'recheck') {
            $s = $request['selection'];
            self::need(self::keys($s, ['lookup_request_sha256', 'source_receipt_sha256', 'set_sha256', 'checked_at_unix', 'candidate'])
                && self::hash($s['lookup_request_sha256']) && self::hash($s['source_receipt_sha256']) && self::hash($s['set_sha256'])
                && is_int($s['checked_at_unix']) && abs($now - $s['checked_at_unix']) <= self::CLOCK_TOLERANCE,
                'invalid_selection');
            self::validateCandidate($s['candidate'], $request['external_user_id'], $now);
        }
        self::need(strlen(self::json($request)) <= self::MAX_BODY, 'body_bound');
    }
    public static function decodeBody(string $body, int $now): array
    {
        self::need($body !== '' && strlen($body) <= self::MAX_BODY, 'body_bound');
        $request = json_decode($body, true, 16, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
        self::need(is_array($request) && self::json($request) === $body, 'noncanonical_body');
        self::validateRequest($request, $now);
        return $request;
    }
    public static function signature(string $body, string $privateKey): string
    {
        self::need($body !== '' && strlen($body) <= self::MAX_BODY, 'body_bound');
        $key = @openssl_pkey_get_private($privateKey);
        $details = $key ? openssl_pkey_get_details($key) : false;
        self::need(is_array($details) && $details['type'] === OPENSSL_KEYTYPE_RSA && $details['bits'] >= 2048
            && openssl_sign(self::PURPOSE . "\n" . $body, $signature, $key, OPENSSL_ALGO_SHA256), 'auth_missing');
        return base64_encode($signature);
    }
    public static function authenticate(string $body, string $signature, string $publicKey, int $now, string $header): array
    {
        self::need(strlen($signature) <= 1368 && strlen($body) <= self::MAX_BODY, 'unauthorized');
        $raw = base64_decode($signature, true);
        $key = @openssl_pkey_get_public($publicKey); $details = $key ? openssl_pkey_get_details($key) : false;
        self::need($header === self::EVIDENCE_HEADER && $body !== '' && strlen($body) <= self::MAX_BODY
            && is_string($raw) && strlen($raw) <= 1024 && is_array($details)
            && $details['type'] === OPENSSL_KEYTYPE_RSA && $details['bits'] >= 2048
            && openssl_verify(self::PURPOSE . "\n" . $body, $raw, $key, OPENSSL_ALGO_SHA256) === 1, 'unauthorized');
        return self::decodeBody($body, $now);
    }
    public static function validateCandidate(mixed $candidate, string $actor, int $now): void
    {
        self::need(self::keys($candidate, self::CANDIDATE_KEYS) && self::id($candidate['tracker_record_id'])
            && self::hash($candidate['immutable_tuple_sha256']) && $candidate['counter_id'] === self::COUNTER
            && $candidate['metric_project_id'] === self::PROJECT && $candidate['external_user_id'] === $actor
            && self::id($candidate['post_id'], 19) && is_string($candidate['offer_id'])
            && preg_match('/\Amax_[1-9][0-9]{0,18}\z/D', $candidate['offer_id']) === 1
            && self::id($candidate['client_id'], 64) && self::id($candidate['early_kopeks'], 8)
            && (int)$candidate['early_kopeks'] <= 10000000 && in_array($candidate['early_kopeks'], ['1799', '2636'], true)
            && $candidate['common_kopeks'] === $candidate['early_kopeks']
            && $candidate['model_version'] === self::MODEL && $candidate['issuance_state'] === 'issued_unconfirmed'
            && is_int($candidate['recorded_at_unix']) && $candidate['recorded_at_unix'] >= self::MIN_UNIX
            && $candidate['recorded_at_unix'] <= $now, 'invalid_candidate');
    }
    public static function setDigest(array $candidates): string
    {
        // Fixed field order avoids associative-key-order fingerprints.
        $rows = [];
        foreach ($candidates as $candidate) {
            self::need(self::keys($candidate, self::CANDIDATE_KEYS), 'invalid_candidate');
            $rows[] = array_map(static fn($k) => $candidate[$k], self::CANDIDATE_KEYS);
        }
        return hash('sha256', self::json([self::PURPOSE, 'candidate-set-v2', $rows]));
    }
    public static function validateResult(string $raw, string $requestBody, int $received): array
    {
        self::need(strlen($raw) > 0 && strlen($raw) <= self::MAX_RESPONSE, 'response_bound');
        $request = self::decodeBody($requestBody, $received);
        $r = json_decode($raw, true, 20, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
        $keys = ['version', 'purpose', 'action', 'counter_id', 'metric_project_id', 'external_user_id', 'context',
            'request_sha256', 'producer_contract_sha256', 'checked_at_unix', 'tracker_timezone', 'coverage',
            'own_reserve_only', 'status', 'complete_within_bound', 'scanned_rows', 'scoped_rows', 'blank_row_count',
            'candidates', 'set_sha256', 'possible_max2_unresolved', 'reason', 'financial_permission'];
        self::need(self::keys($r, $keys) && self::json($r) === $raw && $r['version'] === 2 && $r['purpose'] === self::PURPOSE
            && $r['action'] === $request['action'] && $r['counter_id'] === self::COUNTER && $r['metric_project_id'] === self::PROJECT
            && $r['external_user_id'] === $request['external_user_id'] && $r['context'] === $request['context']
            && $r['request_sha256'] === hash('sha256', $requestBody) && $r['producer_contract_sha256'] === self::PRODUCER_CONTRACT_SHA256
            && is_int($r['checked_at_unix']) && $r['checked_at_unix'] >= self::MIN_UNIX
            && abs($r['checked_at_unix'] - $received) <= self::CLOCK_TOLERANCE
            && $r['tracker_timezone'] === 'Europe/London' && $r['coverage'] === ($request['action'] === 'list' ? self::COVERAGE : 'exact_selected_record_only')
            && $r['own_reserve_only'] === true && $r['financial_permission'] === false
            && is_array($r['candidates']) && array_is_list($r['candidates']) && count($r['candidates']) <= self::MAX_ROWS,
            'invalid_response');
        if ($r['status'] === 'unknown') {
            self::need($r['complete_within_bound'] === false && $r['candidates'] === [] && $r['set_sha256'] === null
                && $r['possible_max2_unresolved'] === true && $r['scanned_rows'] === null && $r['scoped_rows'] === null
                && $r['blank_row_count'] === null && in_array($r['reason'], ['invalid_request', 'producer_contract_unadmitted',
                    'storage_unavailable', 'storage_contract', 'point_index_unavailable', 'row_limit', 'packet_budget',
                    'time_headroom', 'invalid_source', 'partial_value_fields', 'invalid_value_fields', 'invalid_clock'], true),
                'invalid_unknown_response');
            return $r;
        }
        $statuses = $request['action'] === 'list' ? ['complete', 'complete_empty'] : ['match', 'missing', 'changed'];
        self::need(in_array($r['status'], $statuses, true) && $r['reason'] === null
            && $r['complete_within_bound'] === true && $r['possible_max2_unresolved'] === false
            && is_int($r['scanned_rows']) && $r['scanned_rows'] >= 0 && $r['scanned_rows'] <= self::MAX_ROWS
            && is_int($r['scoped_rows']) && $r['scoped_rows'] >= 0 && $r['scoped_rows'] <= $r['scanned_rows']
            && is_int($r['blank_row_count']) && $r['blank_row_count'] >= 0 && $r['blank_row_count'] <= $r['scoped_rows']
            && $r['set_sha256'] === self::setDigest($r['candidates']), 'invalid_complete_response');
        $last = null;
        foreach ($r['candidates'] as $candidate) {
            self::validateCandidate($candidate, $request['external_user_id'], $r['checked_at_unix']);
            $id = $candidate['tracker_record_id'];
            self::need($last === null || self::decimalCompare($last, $id) < 0, 'unordered_or_duplicate_candidates');
            $last = $id;
        }
        if ($request['action'] === 'list') {
            self::need(count($r['candidates']) + $r['blank_row_count'] === $r['scoped_rows']
                && ($r['status'] === 'complete_empty') === ($r['candidates'] === []), 'incomplete_candidate_set');
        } else {
            self::need($r['scanned_rows'] <= 1 && $r['scoped_rows'] <= 1, 'recheck_scope');
            if ($r['status'] === 'match') self::need(count($r['candidates']) === 1
                && $r['candidates'][0] === $request['selection']['candidate'], 'recheck_changed');
            else self::need($r['candidates'] === [], 'recheck_scope');
        }
        return $r;
    }
    public static function decimalCompare(string $a, string $b): int
    {
        return strlen($a) <=> strlen($b) ?: strcmp($a, $b);
    }
    /** Only this projection may be logged or shown. Raw actor/CID/post/record keys stay private. */
    public static function publicSummary(array $validated): array
    {
        return ['version' => 2, 'status' => $validated['status'], 'complete_within_bound' => $validated['complete_within_bound'],
            'candidate_count' => count($validated['candidates']), 'scanned_rows' => $validated['scanned_rows'],
            'blank_row_count' => $validated['blank_row_count'], 'reason' => $validated['reason'],
            'own_reserve_only' => true, 'financial_permission' => false];
    }
}
