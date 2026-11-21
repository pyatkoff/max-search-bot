<?php
declare(strict_types=1);
// CLI-only synthetic RSA + callbacks. No native paths, PDO connection, HTTP or DB.
if (PHP_SAPI !== 'cli') exit(1);
$package = dirname(__DIR__);
require_once $package . '/candidate/services/Max2ValueLookupHttpV2.php';
require_once $package . '/candidate/services/Max2ValueLookupRuntimeV2.php';
$p = Max2ValueLookupProtocolV2::class;
$checks = 0;
function scCheck(bool $condition, string $name): void {
    global $checks; $checks++;
    if (!$condition) throw new RuntimeException('ASSERTION_' . $name);
}
function scFails(callable $call, string $name): void {
    $failed = false; try { $call(); } catch (Throwable $ignored) { $failed = true; }
    scCheck($failed, $name);
}
function scEnvelope(string $body, int $now, string $status = 'complete_empty'): array {
    $p = Max2ValueLookupProtocolV2::class;
    $request = $p::decodeBody($body, $now);
    $result = ['version' => 2, 'purpose' => $p::PURPOSE, 'action' => $request['action'],
        'counter_id' => $p::COUNTER, 'metric_project_id' => $p::PROJECT,
        'external_user_id' => $request['external_user_id'], 'context' => $request['context'],
        'request_sha256' => hash('sha256', $body), 'producer_contract_sha256' => $p::PRODUCER_CONTRACT_SHA256,
        'checked_at_unix' => $now, 'tracker_timezone' => 'Europe/London',
        'coverage' => $request['action'] === 'list' ? $p::COVERAGE : 'exact_selected_record_only',
        'own_reserve_only' => true, 'status' => $status, 'complete_within_bound' => true,
        'scanned_rows' => 0, 'scoped_rows' => 0, 'blank_row_count' => 0, 'candidates' => [],
        'set_sha256' => $p::setDigest([]), 'possible_max2_unresolved' => false,
        'reason' => null, 'financial_permission' => false];
    if ($status === 'unknown') return array_replace($result, ['complete_within_bound' => false,
        'scanned_rows' => null, 'scoped_rows' => null, 'blank_row_count' => null,
        'set_sha256' => null, 'possible_max2_unresolved' => true, 'reason' => 'storage_unavailable']);
    if ($status === 'complete' || $status === 'match') {
        $candidate = $request['selection']['candidate'] ?? [
            'tracker_record_id' => '71', 'immutable_tuple_sha256' => hash('sha256', 'synthetic sidecar row'),
            'counter_id' => $p::COUNTER, 'metric_project_id' => $p::PROJECT,
            'external_user_id' => $request['external_user_id'], 'post_id' => '53', 'offer_id' => 'max_61',
            'client_id' => '1234567890123456789', 'early_kopeks' => '2636', 'common_kopeks' => '2636',
            'model_version' => $p::MODEL, 'issuance_state' => 'issued_unconfirmed', 'recorded_at_unix' => $now - 60];
        $result = array_replace($result, ['scanned_rows' => 1, 'scoped_rows' => 1,
            'candidates' => [$candidate], 'set_sha256' => $p::setDigest([$candidate])]);
    }
    return $result;
}

$now = 1795266000;
$context = ['event_ref' => hash('sha256', 'synthetic event'),
    'frame_sha256' => hash('sha256', 'synthetic frame'), 'cutoff_unix' => $now - 30];
$body = $p::listBody('91', $context, $now, str_repeat('a', 32));
$key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 2048]);
if ($key === false || !openssl_pkey_export($key, $private)) throw new RuntimeException('SYNTHETIC_RSA_FAILED');
$public = openssl_pkey_get_details($key)['key'];
$server = ['REQUEST_URI' => Max2ValueLookupHttpV2::PATH, 'QUERY_STRING' => '', 'REQUEST_METHOD' => 'POST',
    'CONTENT_TYPE' => 'application/json', 'CONTENT_LENGTH' => (string)strlen($body),
    'HTTP_X_MAX_ATTRIBUTION_SIGNATURE' => $p::signature($body, $private),
    'HTTP_X_MAX_ATTRIBUTION_EVIDENCE' => $p::EVIDENCE_HEADER];
$keyReads = 0; $databaseReads = 0;
$keyReader = static function() use (&$keyReads, $public): string { $keyReads++; return $public; };
$reader = static function(string $raw, int $at) use (&$databaseReads): array {
    $databaseReads++; return scEnvelope($raw, $at);
};
$invoke = static function(array $s, string $raw) use ($now, $keyReader, $reader): array {
    return Max2ValueLookupHttpV2::handle($s, $raw, $now, $keyReader, $reader);
};
$good = $invoke($server, $body);
scCheck($good['status_code'] === 200 && $keyReads === 1 && $databaseReads === 1, 'valid_new_signature_one_read');
scCheck($good['headers']['Cache-Control'] === 'no-store' && $good['headers']['X-Content-Type-Options'] === 'nosniff', 'private_response_no_store');
$empty = $p::validateResult($good['body'], $body, $now);
scCheck($empty['status'] === 'complete_empty' && $empty['scanned_rows'] === 0
    && $empty['own_reserve_only'] && !$empty['financial_permission'], 'complete_zero_only_own_reserve');

foreach ([['REQUEST_METHOD','GET',405],['REQUEST_URI','/new/max-attribution/lookup.php',404],
    ['REQUEST_URI',Max2ValueLookupHttpV2::PATH . '?actor=91',404],['QUERY_STRING','actor=91',404],
    ['CONTENT_TYPE','text/plain',415],['CONTENT_LENGTH','99999999',413],['CONTENT_LENGTH','00',400],
    ['CONTENT_LENGTH',(string)(strlen($body) + 1),400],['HTTP_X_MAX_ATTRIBUTION_EVIDENCE','counter-scope-v1',401]] as [$field,$value,$status]) {
    $keyBefore = $keyReads; $dbBefore = $databaseReads;
    $reply = $invoke(array_replace($server, [$field => $value]), $body);
    scCheck($reply['status_code'] === $status && $keyReads === $keyBefore && $databaseReads === $dbBefore,
        'early_request_rejection_no_key_or_storage_' . $field);
}
$before = $databaseReads;
scCheck($invoke($server, str_repeat('x', 8193))['status_code'] === 413 && $databaseReads === $before, 'oversized_body_no_storage');

foreach (['wrong_signature', 'old_purpose', 'stale', 'counter', 'contract', 'extra', 'noncanonical'] as $case) {
    $badBody = $body; $badServer = $server;
    if ($case === 'old_purpose') {
        openssl_sign("anytour-max-tracker-lookup-v1\n" . $body, $oldSignature, $key, OPENSSL_ALGO_SHA256);
        $badServer['HTTP_X_MAX_ATTRIBUTION_SIGNATURE'] = base64_encode($oldSignature);
    } elseif ($case === 'wrong_signature') {
        $badServer['HTTP_X_MAX_ATTRIBUTION_SIGNATURE'] = base64_encode(str_repeat('x', 256));
    } else {
        $data = json_decode($body, true, 16, JSON_THROW_ON_ERROR);
        if ($case === 'stale') $data['issued_at'] = $now - 91;
        elseif ($case === 'counter') $data['counter_id'] = 111;
        elseif ($case === 'contract') $data['producer_contract_sha256'] = str_repeat('b', 64);
        elseif ($case === 'extra') $data['writer_permission'] = true;
        $badBody = $p::json($data) . ($case === 'noncanonical' ? "\n" : '');
        $badServer['CONTENT_LENGTH'] = (string)strlen($badBody);
        $badServer['HTTP_X_MAX_ATTRIBUTION_SIGNATURE'] = $p::signature($badBody, $private);
    }
    $before = $databaseReads;
    $reply = $invoke($badServer, $badBody);
    scCheck($reply['status_code'] === 401 && $databaseReads === $before, 'auth_before_storage_' . $case);
    scCheck($reply['body'] === '{"ok":false,"error":"unauthorized"}', 'no_private_auth_failure_details_' . $case);
}

$unknownRead = static function(string $raw, int $at) use (&$databaseReads): array {
    $databaseReads++; return scEnvelope($raw, $at, 'unknown');
};
$unknown = Max2ValueLookupHttpV2::handle($server, $body, $now, $keyReader, $unknownRead);
$unknownResult = $p::validateResult($unknown['body'], $body, $now);
scCheck($unknown['status_code'] === 200 && $unknownResult['status'] === 'unknown'
    && $unknownResult['scanned_rows'] === null && $unknownResult['blank_row_count'] === null
    && $unknownResult['possible_max2_unresolved'] === true, 'unknown_never_converted_to_zero');
$before = $databaseReads;
$brokenKey = Max2ValueLookupHttpV2::handle($server, $body, $now,
    static function(): string { throw new RuntimeException('synthetic private key path detail'); }, $reader);
scCheck($brokenKey['status_code'] === 503 && $databaseReads === $before, 'key_unavailable_no_storage');
$readThrows = static function(string $raw, int $at) use (&$databaseReads): array {
    $databaseReads++; throw new RuntimeException('synthetic PRIVATE_DATABASE_EXCEPTION');
};
$before = $databaseReads;
$brokenRead = Max2ValueLookupHttpV2::handle($server, $body, $now, $keyReader, $readThrows);
scCheck($brokenRead['status_code'] === 503 && $databaseReads === $before + 1
    && $brokenRead['body'] === '{"ok":false,"error":"lookup_unavailable"}', 'one_failed_read_no_retry_or_private_leak');
foreach (['counter_id' => 111, 'financial_permission' => true, 'scanned_rows' => 1, 'reason' => str_repeat('x', 65537)] as $field => $value) {
    $reply = Max2ValueLookupHttpV2::handle($server, $body, $now, $keyReader,
        static fn(string $raw, int $at): array => array_replace(scEnvelope($raw, $at, 'unknown'), [$field => $value]));
    scCheck($reply['status_code'] === 503, 'invalid_response_not_exposed_' . $field);
}

$lookup = scEnvelope($body, $now, 'complete');
$recheckBody = $p::recheckBody($lookup, hash('sha256', $p::json($lookup)), '71', $now, str_repeat('c', 32));
$recheckServer = array_replace($server, ['CONTENT_LENGTH' => (string)strlen($recheckBody),
    'HTTP_X_MAX_ATTRIBUTION_SIGNATURE' => $p::signature($recheckBody, $private)]);
$recheck = Max2ValueLookupHttpV2::handle($recheckServer, $recheckBody, $now, $keyReader,
    static fn(string $raw, int $at): array => scEnvelope($raw, $at, 'match'));
$point = $p::validateResult($recheck['body'], $recheckBody, $now);
scCheck($recheck['status_code'] === 200 && $point['status'] === 'match'
    && $point['coverage'] === 'exact_selected_record_only' && count($point['candidates']) === 1,
    'authenticated_recheck_retains_exact_point_scope');

$der = base64_decode(preg_replace('/-----BEGIN PUBLIC KEY-----|-----END PUBLIC KEY-----|\s/', '', $public), true);
$pin = hash('sha256', $der);
scCheck(Max2ValueLookupRuntimeV2::validatePublicKey($public, $pin) === $public, 'public_spki_der_pin');
scFails(static fn() => Max2ValueLookupRuntimeV2::validatePublicKey($public, str_repeat('0', 64)), 'wrong_public_key_pin_rejected');
scFails(static fn() => Max2ValueLookupRuntimeV2::validatePublicKey($private, $pin), 'private_key_never_accepted_on_tracker');
scCheck(Max2ValueLookupRuntimeV2::VERIFICATION_FILE === '/var/www/www-root/data/www/tour-max.ru/new/max-attribution/verification.pem'
    && Max2ValueLookupRuntimeV2::SETTINGS_FILE === '/var/www/www-root/data/www/tour-max.ru/bitrix/.settings.php', 'fixed_existing_dependencies');
$settings = ['connections' => ['value' => ['default' => ['host' => 'localhost', 'database' => 'synthetic_db',
    'login' => 'synthetic_user', 'password' => 'SYNTHETIC_NOT_A_REAL_SECRET']]]];
$args = Max2ValueLookupRuntimeV2::connectionArguments($settings);
scCheck($args['dsn'] === 'mysql:host=localhost;dbname=synthetic_db;charset=utf8mb4', 'existing_connection_dsn_validation_only');
foreach (['host' => 'localhost;port=9999', 'database' => "synthetic\nwrong", 'login' => [], 'password' => null] as $field => $value) {
    $bad = $settings; $bad['connections']['value']['default'][$field] = $value;
    scFails(static fn() => Max2ValueLookupRuntimeV2::connectionArguments($bad), 'connection_shape_' . $field);
}
unset($private, $key, $args, $settings);
echo 'PASS ', $checks, " synthetic sidecar assertions; no native paths, database connection or network calls\n";
