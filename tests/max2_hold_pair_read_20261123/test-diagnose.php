<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') exit(1);
require_once dirname(__DIR__, 2) . '/tools/max2_hold_pair_read_20261123/diagnose.php';

// Synthetic only; these records are never written to any database.
function diagTestCheck(bool $ok, string $code): void
{
    if (!$ok) throw new RuntimeException($code);
}
function diagTestWrapper(array $subject, int $frozen, int $event, string $goal = 'substantive_interest'): array
{
    $at = gmdate('Y-m-d\TH:i:s\Z', $event);
    $freeze = gmdate('Y-m-d\TH:i:s\Z', $frozen);
    $ref = 'journal:900000000001';
    return ['access_status' => 'allowed', 'subject' => $subject,
        'contextual_signals' => ['version' => ModeledConversionEarlyForecast::VERSION,
            'extractor_sha256' => Max2HoldCandidateDiagnosis::SOURCE_HASHES['ArchiveResearchSemanticExtractor.php'],
            'adapter_sha256' => Max2HoldCandidateDiagnosis::SOURCE_HASHES['ModeledConversionEarlyForecast.php'],
            'frozen_at' => $freeze, 'input_cutoff_at' => $freeze,
            'events' => [['goal' => $goal, 'source_ref' => $ref, 'product' => 'tour']]],
        'profile' => ['observation_input' => ['subject' => $subject,
            'evidence' => [['kind' => 'customer_message', 'subject' => $subject,
                'id' => 'message:' . $ref, 'at' => $at, 'available_at' => $at,
                'references' => [['source' => 'message', 'id' => $ref, 'at' => $at, 'available_at' => $at]]]]]],
        'synthetic_privacy_sentinel' => 'MUST_NOT_OUTPUT_PERSONAL_TEXT_900000000002'];
}
function diagTestMoney(array $w, bool $paymentHold = false): array
{
    $s = $w['contextual_signals'];
    $p = ['prediction_digest' => str_repeat('a', 64), 'frozen_at' => $s['frozen_at'],
        'profile_prediction_at' => $s['frozen_at'], 'input_cutoff_at' => $s['input_cutoff_at'],
        'current_request_anchor' => 'journal:900000000001', 'status' => 'insufficient_context',
        'estimated_probability' => null];
    $v = ['version' => ModeledConversionDealValue::VERSION,
        'rules_sha256' => Max2HoldCandidateDiagnosis::SOURCE_HASHES['ModeledConversionDealValue.php'],
        'subject' => $w['subject'], 'semantic_prediction_digest' => $p['prediction_digest'],
        'current_request_anchor' => $p['current_request_anchor'], 'profile_prediction_at' => $p['profile_prediction_at'],
        'input_cutoff_at' => $p['input_cutoff_at'], 'frozen_at' => $p['frozen_at'],
        'commission_rate' => ModeledConversionDealValue::COMMISSION_RATE, 'currency' => 'RUB',
        'status' => 'unavailable', 'reason' => 'insufficient_context', 'early_window' => 'first_hour', 'age_seconds' => 0,
        'deal_amount_rub' => ['low' => null, 'high' => null, 'point' => null, 'basis' => 'unknown', 'source_kind' => 'unknown', 'source_refs' => []],
        'commission_estimate_rub' => null, 'full_value_base_rub' => ModeledConversionDealValue::UNKNOWN_BASE,
        'base_basis' => 'provisional_commission_10000', 'estimated_probability' => null, 'value_rub' => null,
        'payment_hold_observed' => $paymentHold, 'calibration_status' => 'expert_estimate_not_calibrated',
        'price_verified' => false, 'trip_identity' => 'unverified', 'export_allowed' => false, 'model_calls' => 0, 'conversion_uploads' => 0];
    $v['deal_digest'] = ModeledConversionSemanticPrediction::digest($v);
    $w['semantic_prediction'] = $p; $w['deal_value_prediction'] = $v;
    return $w;
}

$name = 'initialization';
try {
    if (count($argv) !== 2) throw new RuntimeException('TEST_SERVICES_ARGUMENT_REQUIRED');
    set_error_handler(static function (): never { throw new RuntimeException('TEST_PHP_WARNING'); });
    Max2HoldCandidateDiagnosis::initialize($argv[1]);
    $floor = 1795387176;
    $start = $floor + 600;
    $recorded = $start + 3;
    $subject = ['project_key' => 'anytour', 'platform' => 'max', 'customer_key' => '900000000002', 'conversation_id' => '900000000003'];
    $good = diagTestWrapper($subject, $start - 1, $floor + 10);
    $cases = [];
    $cases['valid_positive'] = [$good, 'CANDIDATE_AVAILABLE'];
    $w = $good; $w['contextual_signals']['events'] = [];
    $cases['no_saved_signal'] = [$w, 'NO_ELIGIBLE_SIGNAL'];
    $w = $good; $w['contextual_signals']['extractor_sha256'] = str_repeat('f', 64);
    $cases['changed_extractor'] = [$w, 'EXTRACTOR_SOURCE'];
    $w = $good; $w['contextual_signals']['adapter_sha256'] = str_repeat('f', 64);
    $cases['unsupported_producer_tuple'] = [$w, 'LEGACY_PRODUCER_TUPLE'];
    $w = $good; $w['contextual_signals']['frozen_at'] = 'invalid';
    $cases['invalid_freeze_timestamp'] = [$w, 'FROZEN_TIME_FORMAT'];
    $w = $good; $w['contextual_signals']['input_cutoff_at'] = gmdate('Y-m-d\TH:i:s\Z', $start + 20);
    $cases['cutoff_after_freeze'] = [$w, 'CUTOFF_AFTER_FREEZE'];
    $w = diagTestWrapper($subject, $start + 1, $floor + 10);
    $w['contextual_signals']['input_cutoff_at'] = gmdate('Y-m-d\TH:i:s\Z', $start + 2);
    $cases['earlier_cutoff_failure_invariant_across_call_time'] = [$w, 'CUTOFF_AFTER_FREEZE'];
    $w = diagTestWrapper($subject, $floor - 1, $floor - 2);
    $cases['frozen_before_activation'] = [$w, 'FREEZE_BEFORE_ACTIVATION'];
    $w = diagTestWrapper($subject, $start - 1, $floor - 1);
    $cases['anchor_before_activation'] = [$w, 'NO_ELIGIBLE_SIGNAL'];
    $w = $good; $w['profile']['observation_input']['evidence'] = [];
    $cases['missing_reference'] = [$w, 'NO_ELIGIBLE_SIGNAL'];
    $w = $good; $w['contextual_signals']['events'][] = ['goal' => 'refund_report', 'source_ref' => 'journal:900000000001', 'product' => 'tour'];
    $cases['refund_suppresses_value'] = [$w, 'CONTRARY_EVENT'];
    $w = diagTestMoney($good);
    $cases['unknown_price_stage_fallback'] = [$w, 'CANDIDATE_AVAILABLE'];
    $w['deal_value_prediction']['deal_digest'] = str_repeat('f', 64);
    $cases['corrupt_money_capsule'] = [$w, 'SAVED_MONEY_CAPSULE'];
    $w = diagTestMoney(diagTestWrapper($subject, $start - 1, $floor + 10, 'payment_self_report'), true);
    $cases['payment_hold'] = [$w, 'PAYMENT_HOLD'];
    $w = $good; $w['profile']['observation_input']['evidence'][0]['kind'] = 'manager_message';
    $cases['wrong_actor_role'] = [$w, 'NO_ELIGIBLE_SIGNAL'];
    $w = $good; $w['contextual_signals']['events'][0]['product'] = 'flight';
    $cases['non_tour_product'] = [$w, 'NO_ELIGIBLE_SIGNAL'];
    $checks = 0;
    foreach ($cases as $name => [$w, $gate]) {
        $before = serialize($w);
        $out = Max2HoldCandidateDiagnosis::inspect($w, $subject, $floor, $start, $recorded);
        diagTestCheck($out['first_failed_gate'] === $gate, 'TEST_GATE_MISMATCH');
        diagTestCheck($out['temporal_result'] === 'invariant_over_saved_interval', 'TEST_TEMPORAL_RESULT');
        diagTestCheck(Max2HoldCandidateDiagnosis::validateProjection($out), 'TEST_PUBLIC_SCHEMA');
        diagTestCheck(serialize($w) === $before, 'TEST_MUTATION');
        $encoded = json_encode($out, JSON_THROW_ON_ERROR);
        foreach (['900000000001', '900000000002', '900000000003', 'MUST_NOT_OUTPUT', 'journal:', '"source_ref":', '"value_rub":', '"estimated_probability":'] as $needle) {
            diagTestCheck(!str_contains($encoded, $needle), 'TEST_PRIVACY');
        }
        if ($name === 'corrupt_money_capsule') diagTestCheck($out['at_tick_start']['checks']['money_digest_valid'] === false, 'TEST_MONEY_DIGEST_REASON');
        $checks++;
    }
    $name = 'v1_v2_floor_difference';
    $old = diagTestWrapper($subject, $start - 1, $floor - 1);
    $v1 = Max2HoldCandidateDiagnosis::inspect($old, $subject, $floor - 3600, $start, $recorded);
    $v2 = Max2HoldCandidateDiagnosis::inspect($old, $subject, $floor, $start, $recorded);
    diagTestCheck($v1['first_failed_gate'] === 'CANDIDATE_AVAILABLE' && $v2['original_null_reproduced'] === true
        && $v2['at_tick_start']['checks']['events_before_activation'] === 1, 'TEST_INDEPENDENT_FLOORS'); $checks++;

    $name = 'exact_activation_boundary';
    $atFloor = diagTestWrapper($subject, $start - 1, $floor);
    $out = Max2HoldCandidateDiagnosis::inspect($atFloor, $subject, $floor, $start, $recorded);
    diagTestCheck($out['first_failed_gate'] === 'CANDIDATE_AVAILABLE', 'TEST_EQUAL_FLOOR'); $checks++;

    $name = 'historical_clock_boundary';
    $boundary = diagTestWrapper($subject, $start + 1, $floor + 10);
    $out = Max2HoldCandidateDiagnosis::inspect($boundary, $subject, $floor, $start, $recorded);
    diagTestCheck($out['temporal_result'] === 'original_evaluation_time_unresolved'
        && $out['original_null_reproduced'] === false && $out['first_failed_gate'] === 'EVALUATION_TIME_BOUNDARY'
        && $out['at_tick_start']['first_gate'] === 'FREEZE_AFTER_EVALUATION'
        && $out['at_hold_recorded']['first_gate'] === 'CANDIDATE_AVAILABLE', 'TEST_CLOCK_UNCERTAINTY'); $checks++;

    $name = 'output_allowlist_rejects_sensitive_extra';
    $bad = $out; $bad['subject'] = $subject;
    diagTestCheck(!Max2HoldCandidateDiagnosis::validateProjection($bad), 'TEST_EXTRA_OUTPUT');
    $bad = $out; $bad['at_tick_start']['checks']['client_id'] = '900000000003';
    diagTestCheck(!Max2HoldCandidateDiagnosis::validateProjection($bad), 'TEST_EXTRA_CHECK');
    $bad = $out; $bad['at_tick_start']['checks']['events_before_activation'] = 1001;
    diagTestCheck(!Max2HoldCandidateDiagnosis::validateProjection($bad), 'TEST_COUNT_BOUND'); $checks++;

    $name = 'bad_time_scope'; $rejected = false;
    try { Max2HoldCandidateDiagnosis::inspect($good, $subject, $floor, $start, $start + 301); }
    catch (RuntimeException $e) { $rejected = $e->getMessage() === 'DIAG_TIME_SCOPE_UNPROVED'; }
    diagTestCheck($rejected, 'TEST_TIME_SCOPE'); $checks++;
    restore_error_handler();
    echo json_encode(['status' => 'PASS', 'checks' => $checks, 'actual_canonical_candidate_executed' => true,
        'pure_synthetic_profiles' => true, 'model_ticks' => 0, 'provider_calls' => 0, 'database_connections' => 0, 'writes' => 0], JSON_THROW_ON_ERROR), "\n";
} catch (Throwable $e) {
    // Only static test names and fixed exception labels; never print inputs or stack traces.
    $allowed = preg_match('/\A(?:TEST|DIAG)_[A-Z_]{1,80}\z/D', $e->getMessage()) === 1 ? $e->getMessage() : 'TEST_UNEXPECTED_FAILURE';
    fwrite(STDERR, json_encode(['status' => 'FAIL', 'case' => $name, 'reason' => $allowed], JSON_THROW_ON_ERROR) . "\n");
    exit(1);
}
