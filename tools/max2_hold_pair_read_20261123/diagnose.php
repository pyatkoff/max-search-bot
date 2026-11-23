<?php
declare(strict_types=1);

/**
 * One-off pure diagnosis of the pinned live candidate contract.
 *
 * This does not interpret messages, construct a forecast, select a profile or
 * grant delivery permission. The input must be the two exact immutable JSON
 * records selected by the separately bounded read-only caller.
 *
 * traceLive mirrors only the live evaluate path at the pinned source below.
 * Every call is compared, including the full candidate array, with the actual
 * canonical candidate(). A mismatch produces a fixed failure, never a diagnosis.
 * Native IDs, texts, references, candidate amounts and probabilities stay in
 * memory and are deliberately absent from the returned allowlisted projection.
 */
final class Max2HoldCandidateDiagnosis
{
    public const VERSION = 'max2_original_two_hold_diagnosis_v1';
    public const SOURCE_SHA = '0689691b5aa4b8cd5fbf2f3629d9e9dc76df7e9b';

    // Seven executed validators/literal hash inputs plus their complete static
    // require_once closure. The latter only declares classes; no producer runs.
    public const SOURCE_HASHES = [
        'ModeledConversionValueContract.php' => 'eef5ec867a195316dd897d3d66a646dde59b6e6e17c02a0224faa1a3d4902efb',
        'ModeledConversionValueRegistry.php' => '6fd7a4588a1c9bf9ee2c3dd3657b624b7a89132f199a4594f1eb1a2d6df9f9ee',
        'ModeledConversionDealValue.php' => '1e57fdf4a06c20e5136c968418a151d46aba8516e7f249ebd7c2f705325933da',
        'ModeledConversionSemanticPrediction.php' => 'a56051d3d4baa941db78d75afb613fe30e6620cea3074cf96bea2bd6219347f0',
        'ModeledConversionEarlyForecast.php' => '2d0135423e2034c41aa693ff1a01a2c2821943bef7d5b450993c4599bc088cdf',
        'ArchiveResearchSemanticExtractor.php' => '8ba3388f157887d97813dde8100cce153e76f16e70379736252f27d377cdfe06',
        'ArchivePredictiveRelativeWeights.php' => '5bba3a9401cad2c8962691ba6e6dad9f7f339c66a2cabb125e7cdde18f2ec833',
        'ManagerAiTextRedactor.php' => '7e33e755f09953017203641978c8f499fd390c7a07cf87df45999fd0c7edba03',
        'ModeledConversionOfferReaction.php' => '84cac039637def7a3365f44aa28bff9d6719fa43cb63fa016477835b3177ba16',
        'ConversationRecordClock.php' => '877cb2c608e1fd315b84adef894f22c41d3be8446c6fd17725495245e7a1df30',
        'ModeledConversionAssignedValue.php' => 'c98f95e35cb055a995ff4ca8388d45338aa0302684c8d2f29d4ff69a1741219f',
        'InternalOwnerDialogueScope.php' => 'cf44c20f9f868d82c35c9e4491e060af4c8232105a0b59f89a377f78cecc57d4',
        'ModeledConversionTextSignals.php' => '068eac2f945915bef34005cdd0f3367a6e2b37189d817c3a9dccf9681b1464ec',
        'DestinationHintService.php' => 'e19b71cc8874b6fb8796a41ba2b1020b48e451f53288265f25c95b932641beb4',
        'ModeledConversionQuestionnaireContext.php' => 'efbda7dcffbbbb2587ed4590921abfa9589f0f24cd4e163ade770e59913b0d0f',
    ];

    private const PRE_RESORT_ADAPTER = '2d0135423e2034c41aa693ff1a01a2c2821943bef7d5b450993c4599bc088cdf';
    private const PRE_DEPARTURE_ADAPTER = 'e5c303338fff38c48351881143842894563669484d8dfeb8aef9fbfaa03b62f2';
    private const PRE_DEPARTURE_MODEL = '4e4d7c6908bf8246db08917de1b46dbdc904099b0c95afb92273f833b8c3ab42';
    private const PREVIOUS_ADAPTER = 'a4345e27853b63d30313485134a7a2b2ce92ec11eec860fdc2f79f804f4d46e5';
    private const PREVIOUS_MODEL = 'a5f3a1500da36dc34ad05a7961928ad237687930c1f8322bffe298bf5dcf17e6';
    private const PREVIOUS_DEAL = 'early_deal_commission_7pct_v3';
    private const PREVIOUS_EXTRACTOR = '8ba3388f157887d97813dde8100cce153e76f16e70379736252f27d377cdfe06';
    private static ?string $services = null;

    public const GATES = [
        'WRAPPER_SCOPE' => 'source_structure', 'CONTEXTUAL_SIGNAL_SCHEMA' => 'source_structure',
        'EXTRACTOR_SOURCE' => 'source_provenance', 'PRE_DEPARTURE_PRODUCER_TUPLE' => 'source_provenance',
        'LEGACY_PRODUCER_TUPLE' => 'source_provenance', 'FROZEN_TIME_FORMAT' => 'time_inconsistent',
        'CUTOFF_TIME_FORMAT' => 'time_inconsistent', 'CUTOFF_AFTER_FREEZE' => 'time_inconsistent',
        'FREEZE_AFTER_EVALUATION' => 'time_inconsistent', 'FREEZE_BEFORE_ACTIVATION' => 'time_prospective',
        'OBSERVATION_INPUT_SCHEMA' => 'source_structure', 'LEGACY_PREDICTION_CAPSULE' => 'prediction_capsule',
        'SAVED_MONEY_CAPSULE' => 'money_capsule', 'PAYMENT_HOLD' => 'contrary_outcome',
        'ADVERTISING_PREDICTION_CAPSULE' => 'prediction_capsule', 'MONEY_NOT_ESTIMATED' => 'money_capsule',
        'ADVERTISING_STAGE_UNSUPPORTED' => 'prediction_capsule', 'ADVERTISING_ANCHOR_UNRESOLVED' => 'source_structure',
        'ADVERTISING_ANCHOR_ROLE' => 'source_structure', 'ADVERTISING_ANCHOR_BEFORE_ACTIVATION' => 'time_prospective',
        'CONTRARY_EVENT' => 'contrary_outcome', 'NO_ELIGIBLE_SIGNAL' => 'no_admitted_estimate', 'CANDIDATE_AVAILABLE' => 'admitted',
    ];
    public const BOOLEAN_CHECKS = [
        'access_allowed', 'wrapper_subject_matches', 'project_anytour', 'platform_supported',
        'contextual_version_matches', 'events_are_array', 'events_within_bound', 'extractor_matches',
        'adapter_current', 'adapter_pre_departure', 'producer_legacy_path', 'legacy_adapter_matches',
        'legacy_extractor_matches', 'legacy_model_matches', 'legacy_advertising_mode', 'legacy_money_version_matches',
        'legacy_frozen_matches', 'legacy_cutoff_matches', 'frozen_time_valid', 'cutoff_time_valid',
        'cutoff_not_after_freeze', 'freeze_not_after_evaluation', 'freeze_since_activation', 'observation_subject_matches',
        'evidence_is_array', 'evidence_within_bound', 'contrary_event_present', 'semantic_capsule_available',
        'prediction_is_array', 'prediction_status_predicted', 'prediction_subject_matches', 'prediction_model_admitted',
        'prediction_digest_checked', 'prediction_digest_valid', 'prediction_stage_supported', 'prediction_anchor_found',
        'prediction_anchor_customer', 'prediction_anchor_since_activation', 'prediction_freeze_matches',
        'money_capsule_present', 'money_capsule_valid', 'payment_hold_observed', 'money_status_estimated',
        'prediction_missing', 'prediction_insufficient_context', 'prediction_known_outcome', 'prediction_access_denied',
        'money_object_present', 'money_prediction_present', 'money_size_valid', 'money_version_string',
        'money_policy_supported', 'money_rules_match', 'money_subject_matches', 'money_prediction_digest_matches',
        'money_commission_rate_matches', 'money_currency_matches', 'money_freeze_matches', 'money_cutoff_matches',
        'money_anchor_matches', 'money_payment_hold_boolean', 'money_digest_string', 'money_fields_exact',
        'money_fixed_metadata_valid', 'money_deal_shape_valid', 'money_source_refs_valid', 'money_digest_valid',
        'money_base_arithmetic_valid', 'money_probability_binding_valid', 'money_amount_arithmetic_valid',
    ];
    public const COUNT_CHECKS = [
        'evidence_rows', 'evidence_scope_rejected', 'evidence_reference_count_rejected',
        'evidence_reference_format_rejected', 'evidence_time_rejected', 'evidence_admitted', 'event_rows',
        'events_without_admitted_reference', 'events_before_activation', 'events_role_or_product_rejected',
        'events_without_value_stage', 'positive_event_candidates',
    ];

    public static function initialize(string $servicesDirectory): void
    {
        $real = realpath($servicesDirectory);
        if ($real === false || !is_dir($real)) throw new RuntimeException('DIAG_SOURCE_UNPROVED');
        if (self::$services !== null && self::$services !== $real) throw new RuntimeException('DIAG_SOURCE_CHANGED');
        self::$services = $real;
        self::assertSources();
        require_once $real . '/ModeledConversionValueContract.php';
        foreach (array_keys(self::SOURCE_HASHES) as $file) {
            $class = substr($file, 0, -4);
            $reflection = new ReflectionClass($class);
            if ($reflection->getFileName() !== $real . '/' . $class . '.php') throw new RuntimeException('DIAG_CLASS_SOURCE_UNPROVED');
        }
        self::assertSources();
    }

    private static function assertSources(): void
    {
        if (self::$services === null) throw new RuntimeException('DIAG_NOT_INITIALIZED');
        foreach (self::SOURCE_HASHES as $name => $hash) {
            $path = self::$services . '/' . $name;
            clearstatcache(true, $path);
            if (!is_file($path) || is_link($path) || realpath($path) !== $path
                || hash_file('sha256', $path) !== $hash) throw new RuntimeException('DIAG_SOURCE_UNPROVED');
        }
    }

    /** No timestamp in the profile is substituted for the historical call time. */
    public static function inspect(array $wrapper, array $subject, int $activation, int $tickStarted, int $holdRecorded): array
    {
        self::assertSources();
        if ($activation <= 0 || $tickStarted < $activation || $holdRecorded < $tickStarted
            || $holdRecorded - $tickStarted > 300) throw new RuntimeException('DIAG_TIME_SCOPE_UNPROVED');
        $input = json_encode([$wrapper, $subject], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        if (strlen($input) > 132096) throw new RuntimeException('DIAG_INPUT_BOUND');
        $before = hash('sha256', $input);
        $left = self::checked($wrapper, $subject, $activation, $tickStarted);
        $right = $tickStarted === $holdRecorded ? $left : self::checked($wrapper, $subject, $activation, $holdRecorded);
        $same = $left['candidate'] === $right['candidate'] && $left['gate'] === $right['gate']
            && $left['category'] === $right['category'];
        if ($before !== hash('sha256', json_encode([$wrapper, $subject], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR))) {
            throw new RuntimeException('DIAG_INPUT_MUTATED');
        }
        self::assertSources();
        $out = [
            'version' => self::VERSION,
            'temporal_result' => $same ? 'invariant_over_saved_interval' : 'original_evaluation_time_unresolved',
            'original_null_reproduced' => $same && $left['candidate'] === null,
            'first_failed_gate' => $same ? $left['gate'] : 'EVALUATION_TIME_BOUNDARY',
            'category' => $same ? $left['category'] : 'time_boundary_unresolved',
            'at_tick_start' => self::publicResult($left),
            'at_hold_recorded' => self::publicResult($right),
            'canonical_parity_verified' => true,
            'source_hashes_verified' => true,
            'input_unchanged' => true,
            'model_calls' => 0,
            'provider_requests' => 0,
            'writes' => 0,
        ];
        if (!self::validateProjection($out)) throw new RuntimeException('DIAG_OUTPUT_ALLOWLIST_FAILURE');
        return $out;
    }

    /** Independent callers must reject anything outside this fixed public shape. */
    public static function validateProjection(mixed $out): bool
    {
        if (!is_array($out) || !self::exactKeys($out, ['version', 'temporal_result', 'original_null_reproduced',
            'first_failed_gate', 'category', 'at_tick_start', 'at_hold_recorded', 'canonical_parity_verified',
            'source_hashes_verified', 'input_unchanged', 'model_calls', 'provider_requests', 'writes'])
            || $out['version'] !== self::VERSION || !is_bool($out['original_null_reproduced'])
            || $out['canonical_parity_verified'] !== true || $out['source_hashes_verified'] !== true
            || $out['input_unchanged'] !== true || $out['model_calls'] !== 0 || $out['provider_requests'] !== 0 || $out['writes'] !== 0) return false;
        foreach (['at_tick_start', 'at_hold_recorded'] as $key) {
            $r = $out[$key];
            if (!is_array($r) || !self::exactKeys($r, ['canonical_result', 'first_gate', 'category', 'checks'])
                || !is_string($r['first_gate']) || !isset(self::GATES[$r['first_gate']])
                || self::GATES[$r['first_gate']] !== $r['category']
                || $r['canonical_result'] !== ($r['first_gate'] === 'CANDIDATE_AVAILABLE' ? 'candidate' : 'null')
                || !is_array($r['checks'])) return false;
            foreach ($r['checks'] as $name => $value) {
                if (in_array($name, self::BOOLEAN_CHECKS, true)) { if (!is_bool($value)) return false; }
                elseif (in_array($name, self::COUNT_CHECKS, true)) { if (!is_int($value) || $value < 0 || $value > 1000) return false; }
                else return false;
            }
        }
        $a = $out['at_tick_start']; $b = $out['at_hold_recorded'];
        if ($out['temporal_result'] === 'invariant_over_saved_interval') {
            return $a === $b && $out['first_failed_gate'] === $a['first_gate'] && $out['category'] === $a['category']
                && $out['original_null_reproduced'] === ($a['canonical_result'] === 'null');
        }
        return $out['temporal_result'] === 'original_evaluation_time_unresolved'
            && $out['first_failed_gate'] === 'EVALUATION_TIME_BOUNDARY' && $out['category'] === 'time_boundary_unresolved'
            && $out['original_null_reproduced'] === false
            && ($a['canonical_result'] !== $b['canonical_result'] || $a['first_gate'] !== $b['first_gate'] || $a['category'] !== $b['category']);
    }

    private static function exactKeys(array $value, array $keys): bool
    {
        return count($value) === count($keys) && array_diff(array_keys($value), $keys) === [];
    }

    private static function publicResult(array $r): array
    {
        return ['canonical_result' => $r['candidate'] === null ? 'null' : 'candidate',
            'first_gate' => $r['gate'], 'category' => $r['category'], 'checks' => $r['checks']];
    }

    private static function checked(array $w, array $subject, int $floor, int $now): array
    {
        try {
            $canonical = ModeledConversionValueContract::candidate($w, $subject, $floor, $now);
        } catch (Throwable $ignored) {
            throw new RuntimeException('DIAG_CANONICAL_EXCEPTION');
        }
        try {
            $trace = self::traceLive($w, $subject, $floor, $now);
        } catch (Throwable $ignored) {
            throw new RuntimeException('DIAG_TRACE_EXCEPTION');
        }
        if ($canonical !== $trace['candidate']) throw new RuntimeException('DIAG_CANONICAL_PARITY_MISMATCH');
        return $trace;
    }

    private static function result(?array $candidate, string $gate, string $category, array $checks): array
    {
        return ['candidate' => $candidate, 'gate' => $gate, 'category' => $category, 'checks' => $checks];
    }

    /** Branch order follows the pinned evaluate(..., null, false), without the historical-review path. */
    private static function traceLive(array $w, array $subject, int $floor, int $now): array
    {
        $c = [];
        $c['access_allowed'] = ($w['access_status'] ?? null) === 'allowed';
        $c['wrapper_subject_matches'] = ModeledConversionValueContract::sameSubject($w['subject'] ?? null, $subject);
        $c['project_anytour'] = ($subject['project_key'] ?? null) === 'anytour';
        $c['platform_supported'] = in_array($subject['platform'] ?? null, ['max', 'telegram'], true);
        if (!$c['access_allowed'] || !$c['wrapper_subject_matches'] || !$c['project_anytour'] || !$c['platform_supported']) {
            return self::result(null, 'WRAPPER_SCOPE', 'source_structure', $c);
        }
        $s = $w['contextual_signals'] ?? [];
        $c['contextual_version_matches'] = ($s['version'] ?? null) === ModeledConversionEarlyForecast::VERSION;
        $c['events_are_array'] = is_array($s['events'] ?? null);
        $c['events_within_bound'] = $c['events_are_array'] && count($s['events']) <= 1000;
        if (!$c['contextual_version_matches'] || !$c['events_within_bound']) {
            return self::result(null, 'CONTEXTUAL_SIGNAL_SCHEMA', 'source_structure', $c);
        }
        $p = $w['semantic_prediction'] ?? null;
        $c['extractor_matches'] = ($s['extractor_sha256'] ?? null) === self::SOURCE_HASHES['ArchiveResearchSemanticExtractor.php'];
        if (!$c['extractor_matches']) return self::result(null, 'EXTRACTOR_SOURCE', 'source_provenance', $c);
        $adapter = $s['adapter_sha256'] ?? null;
        $model = self::SOURCE_HASHES['ModeledConversionSemanticPrediction.php'];
        $preDeparture = $adapter === self::PRE_DEPARTURE_ADAPTER;
        $c['adapter_current'] = $adapter === self::SOURCE_HASHES['ModeledConversionEarlyForecast.php'];
        $c['adapter_pre_departure'] = $preDeparture;
        if ($preDeparture && ($s['extractor_sha256'] !== self::PREVIOUS_EXTRACTOR
            || in_array($p['model_sha256'] ?? null, [$model, ModeledConversionSemanticPrediction::PRE_RESORT_MODEL_SHA256], true))) {
            return self::result(null, 'PRE_DEPARTURE_PRODUCER_TUPLE', 'source_provenance', $c);
        }
        $stageModel = $preDeparture ? self::PRE_DEPARTURE_MODEL : $model;
        if ($adapter === self::PRE_RESORT_ADAPTER
            && ($p['model_sha256'] ?? null) === ModeledConversionSemanticPrediction::PRE_RESORT_MODEL_SHA256) {
            $stageModel = ModeledConversionSemanticPrediction::PRE_RESORT_MODEL_SHA256;
        }
        $previousProducer = !$preDeparture && !$c['adapter_current'];
        $c['producer_legacy_path'] = $previousProducer;
        if ($previousProducer) {
            $c['legacy_adapter_matches'] = $adapter === self::PREVIOUS_ADAPTER;
            $c['legacy_extractor_matches'] = $s['extractor_sha256'] === self::PREVIOUS_EXTRACTOR;
            $c['legacy_model_matches'] = ($p['model_sha256'] ?? null) === self::PREVIOUS_MODEL;
            $c['legacy_advertising_mode'] = ($p['mode'] ?? null) === 'contextual_advertising_v1';
            $c['legacy_money_version_matches'] = ($w['deal_value_prediction']['version'] ?? null) === self::PREVIOUS_DEAL;
            $c['legacy_frozen_matches'] = ($p['frozen_at'] ?? null) === ($s['frozen_at'] ?? null);
            $c['legacy_cutoff_matches'] = ($p['input_cutoff_at'] ?? null) === ($s['input_cutoff_at'] ?? null);
            if (!$c['legacy_adapter_matches'] || !$c['legacy_extractor_matches'] || !$c['legacy_model_matches']
                || !$c['legacy_advertising_mode'] || !$c['legacy_money_version_matches']
                || !$c['legacy_frozen_matches'] || !$c['legacy_cutoff_matches']) {
                return self::result(null, 'LEGACY_PRODUCER_TUPLE', 'source_provenance', $c);
            }
        }
        $frozen = ModeledConversionValueContract::moment($s['frozen_at'] ?? null);
        $cutoff = ModeledConversionValueContract::moment($s['input_cutoff_at'] ?? null);
        $c['frozen_time_valid'] = $frozen !== null;
        $c['cutoff_time_valid'] = $cutoff !== null;
        if ($frozen === null) return self::result(null, 'FROZEN_TIME_FORMAT', 'time_inconsistent', $c);
        if ($cutoff === null) return self::result(null, 'CUTOFF_TIME_FORMAT', 'time_inconsistent', $c);
        $c['cutoff_not_after_freeze'] = $cutoff <= $frozen;
        if ($cutoff > $frozen) return self::result(null, 'CUTOFF_AFTER_FREEZE', 'time_inconsistent', $c);
        $c['freeze_not_after_evaluation'] = $frozen <= $now;
        if ($frozen > $now) return self::result(null, 'FREEZE_AFTER_EVALUATION', 'time_inconsistent', $c);
        $c['freeze_since_activation'] = $frozen >= $floor;
        if ($frozen < $floor) return self::result(null, 'FREEZE_BEFORE_ACTIVATION', 'time_prospective', $c);
        $input = $w['profile']['observation_input'] ?? [];
        $c['observation_subject_matches'] = ModeledConversionValueContract::sameSubject($input['subject'] ?? null, $subject);
        $c['evidence_is_array'] = is_array($input['evidence'] ?? null);
        $c['evidence_within_bound'] = $c['evidence_is_array'] && count($input['evidence']) <= 1000;
        if (!$c['observation_subject_matches'] || !$c['evidence_within_bound']) {
            return self::result(null, 'OBSERVATION_INPUT_SCHEMA', 'source_structure', $c);
        }
        $refs = [];
        $c += ['evidence_rows' => count($input['evidence']), 'evidence_scope_rejected' => 0,
            'evidence_reference_count_rejected' => 0, 'evidence_reference_format_rejected' => 0,
            'evidence_time_rejected' => 0, 'evidence_admitted' => 0];
        foreach ($input['evidence'] as $e) {
            if (!is_array($e) || !in_array($e['kind'] ?? null, ['customer_message', 'manager_message'], true)
                || !ModeledConversionValueContract::sameSubject($e['subject'] ?? null, $subject)) {
                $c['evidence_scope_rejected']++; continue;
            }
            $r = $e['references'] ?? null;
            if (!is_array($r) || count($r) !== 1) { $c['evidence_reference_count_rejected']++; continue; }
            $r = $r[0];
            $at = ModeledConversionValueContract::moment($e['at'] ?? null);
            $available = ModeledConversionValueContract::moment($e['available_at'] ?? null);
            $ref = $r['id'] ?? null;
            if (!is_string($ref) || preg_match('/\Ajournal:[1-9][0-9]{0,19}\z/D', $ref) !== 1
                || ($r['source'] ?? null) !== 'message' || ($e['id'] ?? null) !== 'message:' . $ref
                || ($r['at'] ?? null) !== ($e['at'] ?? null) || ($r['available_at'] ?? null) !== ($e['available_at'] ?? null)) {
                $c['evidence_reference_format_rejected']++; continue;
            }
            if ($at === null || $available === null || $at > $available || $available > $cutoff) {
                $c['evidence_time_rejected']++; continue;
            }
            $refs[$ref] = ['at' => $at, 'available' => $available, 'role' => $e['kind'] === 'customer_message' ? 'customer' : 'manager'];
            $c['evidence_admitted']++;
        }
        $map = ['substantive_interest' => 1, 'general_purchase_intent' => 4, 'agency_purchase_intent' => 5,
            'payment_self_report' => 6, 'manager_payment_ack' => 6];
        $best = null; $contrary = false;
        $c += ['event_rows' => count($s['events']), 'events_without_admitted_reference' => 0,
            'events_before_activation' => 0, 'events_role_or_product_rejected' => 0,
            'events_without_value_stage' => 0, 'positive_event_candidates' => 0, 'contrary_event_present' => false];
        foreach ($s['events'] as $event) {
            if (!is_array($event)) continue;
            $r = $refs[$event['source_ref'] ?? ''] ?? null;
            if (!$r) { $c['events_without_admitted_reference']++; continue; }
            if ($r['at'] < $floor) { $c['events_before_activation']++; continue; }
            $goal = $event['goal'] ?? null;
            if (in_array($goal, ['payment_cancellation', 'booking_cancellation', 'refund_report', 'purchased_elsewhere'], true)) {
                $contrary = true; $c['contrary_event_present'] = true; continue;
            }
            $rank = is_string($goal) ? ($map[$goal] ?? null) : null;
            if ($rank === null) { $c['events_without_value_stage']++; continue; }
            if (($goal === 'manager_payment_ack' ? $r['role'] !== 'manager' : $r['role'] !== 'customer')
                || !in_array($event['product'] ?? null, ['tour', 'unknown'], true)) {
                $c['events_role_or_product_rejected']++; continue;
            }
            $c['positive_event_candidates']++;
            if ($best === null || $rank > $best['rank']) $best = ['rank' => $rank,
                'stage' => ModeledConversionValueContract::STAGES[$rank], 'value' => ModeledConversionValueContract::VALUES[$rank],
                'event_unix' => $frozen, 'evidence_at' => $r['at'], 'evidence_available_at' => $r['available'],
                'source_ref' => $event['source_ref']];
        }
        $checked = $previousProducer ? ModeledConversionValueRegistry::predictionFields($w) : null;
        if ($previousProducer && ($checked['semantic_prediction']['availability'] ?? null) !== 'available') {
            $c['semantic_capsule_available'] = false;
            return self::result(null, 'LEGACY_PREDICTION_CAPSULE', 'prediction_capsule', $c);
        }
        $c['prediction_is_array'] = is_array($p);
        $c['prediction_missing'] = !array_key_exists('semantic_prediction', $w);
        $c['prediction_insufficient_context'] = is_array($p) && ($p['status'] ?? null) === 'insufficient_context';
        $c['prediction_known_outcome'] = is_array($p) && ($p['status'] ?? null) === 'known_outcome';
        $c['prediction_access_denied'] = is_array($p) && ($p['status'] ?? null) === 'access_denied';
        $c['prediction_status_predicted'] = is_array($p) && ($p['status'] ?? null) === 'predicted';
        $c['prediction_subject_matches'] = is_array($p) && ModeledConversionValueContract::sameSubject($p['subject'] ?? null, $subject);
        $c['prediction_model_admitted'] = is_array($p) && ($checked !== null
            ? ($checked['semantic_prediction']['availability'] ?? null) === 'available'
            : ($p['model_sha256'] ?? null) === $stageModel);
        // Match short-circuit validation: do not evaluate a digest on a capsule that the canonical branch never uses.
        $predictionReady = $c['prediction_status_predicted'] && $c['prediction_subject_matches'] && $c['prediction_model_admitted'];
        $c['prediction_digest_checked'] = $predictionReady;
        $c['prediction_digest_valid'] = $predictionReady && ModeledConversionSemanticPrediction::digestMatches($p);
        if ($predictionReady && $c['prediction_digest_valid']) {
            $rank = ['substantive_dialogue' => 1, 'concrete_request' => 2, 'exact_offer_discussion' => 3,
                'general_purchase_intent' => 4, 'agency_purchase_intent' => 5][$p['stage'] ?? ''] ?? null;
            $anchor = $p['current_request_anchor'] ?? null; $r = $refs[$anchor ?? ''] ?? null;
            $c['prediction_stage_supported'] = $rank !== null;
            $c['prediction_anchor_found'] = (bool)$r;
            $c['prediction_anchor_customer'] = $r && $r['role'] === 'customer';
            $c['prediction_anchor_since_activation'] = $r && $r['at'] >= $floor;
            $c['prediction_freeze_matches'] = ($p['frozen_at'] ?? null) === ($s['frozen_at'] ?? null);
            if ($rank !== null && $r && $r['role'] === 'customer' && $r['at'] >= $floor
                && $c['prediction_freeze_matches'] && ($best === null || $rank > $best['rank'])) {
                $best = ['rank' => $rank, 'stage' => ModeledConversionValueContract::STAGES[$rank],
                    'value' => ModeledConversionValueContract::VALUES[$rank], 'event_unix' => $frozen,
                    'evidence_at' => $r['at'], 'evidence_available_at' => $r['available'], 'source_ref' => $anchor];
            }
        }
        $c['money_capsule_present'] = array_key_exists('deal_value_prediction', $w);
        if ($c['money_capsule_present']) {
            $money = ModeledConversionDealValue::saved($w);
            $c['money_capsule_valid'] = $money !== null;
            if ($money === null) {
                $c += self::moneyFacts($w);
                return self::result(null, 'SAVED_MONEY_CAPSULE', 'money_capsule', $c);
            }
            if ($best !== null && $best['rank'] === 6) {
                $c['payment_hold_observed'] = $money['payment_hold_observed'];
                if ($money['payment_hold_observed']) return self::result(null, 'PAYMENT_HOLD', 'contrary_outcome', $c);
                $best['value'] = $money['full_value_base_rub']; $best['prediction_value'] = true;
            } elseif (is_array($p) && ($p['mode'] ?? null) === 'contextual_advertising_v1' && ($p['status'] ?? null) === 'predicted') {
                $verified = $checked ?? ModeledConversionValueRegistry::predictionFields($w);
                $c['semantic_capsule_available'] = ($verified['semantic_prediction']['availability'] ?? null) === 'available';
                $c['money_status_estimated'] = $money['status'] === 'estimated';
                if (!$c['semantic_capsule_available']) return self::result(null, 'ADVERTISING_PREDICTION_CAPSULE', 'prediction_capsule', $c);
                if (!$c['money_status_estimated']) return self::result(null, 'MONEY_NOT_ESTIMATED', 'money_capsule', $c);
                $rank = ['substantive_dialogue' => 1, 'concrete_request' => 2, 'exact_offer_discussion' => 3,
                    'general_purchase_intent' => 4, 'agency_purchase_intent' => 5][$p['stage']] ?? null;
                $anchor = $p['current_request_anchor']; $r = $refs[$anchor] ?? null;
                $c['prediction_stage_supported'] = $rank !== null;
                $c['prediction_anchor_found'] = (bool)$r;
                $c['prediction_anchor_customer'] = $r && $r['role'] === 'customer';
                $c['prediction_anchor_since_activation'] = $r && $r['at'] >= $floor;
                if ($rank === null) return self::result(null, 'ADVERTISING_STAGE_UNSUPPORTED', 'prediction_capsule', $c);
                if (!$r) return self::result(null, 'ADVERTISING_ANCHOR_UNRESOLVED', 'source_structure', $c);
                if ($r['role'] !== 'customer') return self::result(null, 'ADVERTISING_ANCHOR_ROLE', 'source_structure', $c);
                if ($r['at'] < $floor) return self::result(null, 'ADVERTISING_ANCHOR_BEFORE_ACTIVATION', 'time_prospective', $c);
                $best = ['rank' => $rank, 'stage' => ModeledConversionValueContract::STAGES[$rank], 'value' => $money['value_rub'],
                    'prediction_value' => true, 'event_unix' => $frozen, 'evidence_at' => $r['at'],
                    'evidence_available_at' => $r['available'], 'source_ref' => $anchor];
            } elseif ($best !== null) {
                $best['value'] = (int)round(ModeledConversionValueContract::VALUES[$best['rank']] / ModeledConversionValueContract::BASE * $money['full_value_base_rub']);
                $best['prediction_value'] = true;
            }
        }
        if ($contrary) return self::result(null, 'CONTRARY_EVENT', 'contrary_outcome', $c);
        if ($best === null) return self::result(null, 'NO_ELIGIBLE_SIGNAL', 'no_admitted_estimate', $c);
        return self::result($best, 'CANDIDATE_AVAILABLE', 'admitted', $c);
    }

    /** Fixed scalar probes only after canonical saved() has returned null. No amount leaves this method. */
    private static function moneyFacts(array $w): array
    {
        $v = $w['deal_value_prediction'] ?? null; $p = $w['semantic_prediction'] ?? null;
        $c = ['money_object_present' => is_array($v), 'money_prediction_present' => is_array($p)];
        if (!is_array($v) || !is_array($p)) return $c;
        $c['money_size_valid'] = strlen(json_encode($v, JSON_THROW_ON_ERROR)) <= 8192;
        $c['money_version_string'] = is_string($v['version'] ?? null);
        $policy = $c['money_version_string'] ? ModeledConversionDealValue::policy($v['version']) : null;
        $rules = match ($v['version'] ?? null) {
            ModeledConversionDealValue::VERSION => self::SOURCE_HASHES['ModeledConversionDealValue.php'],
            'early_deal_commission_7pct_v2' => '8a35e248d8237b9042d6aa94eca975c00aae8ae2dc6b3ef42c61b50ee4273669',
            'early_deal_commission_7pct_v1' => 'f4f205793ce51a18f7268292df3e0362b152869d60c71a5bc9c62017ca136593',
            default => null,
        };
        $c['money_policy_supported'] = $policy !== null;
        $c['money_rules_match'] = ($v['rules_sha256'] ?? null) === $rules;
        $a = $v['subject'] ?? null; $b = $w['subject'] ?? null;
        $same = is_array($a) && is_array($b) && count($a) === 4 && count($b) === 4;
        if ($same) foreach (['project_key', 'platform', 'customer_key', 'conversation_id'] as $key) {
            if (($a[$key] ?? null) !== ($b[$key] ?? null)) { $same = false; break; }
        }
        $c['money_subject_matches'] = $same;
        $c['money_prediction_digest_matches'] = ($v['semantic_prediction_digest'] ?? null) === ($p['prediction_digest'] ?? null);
        $c['money_commission_rate_matches'] = ($v['commission_rate'] ?? null) === ModeledConversionDealValue::COMMISSION_RATE;
        $c['money_currency_matches'] = ($v['currency'] ?? null) === 'RUB';
        $c['money_freeze_matches'] = ($v['frozen_at'] ?? null) === ($p['frozen_at'] ?? null);
        $c['money_cutoff_matches'] = ($v['input_cutoff_at'] ?? null) === ($p['input_cutoff_at'] ?? null);
        $c['money_anchor_matches'] = ($v['current_request_anchor'] ?? null) === ($p['current_request_anchor'] ?? null);
        $c['money_payment_hold_boolean'] = is_bool($v['payment_hold_observed'] ?? null);
        $c['money_digest_string'] = is_string($v['deal_digest'] ?? null);
        $fields = ['version', 'rules_sha256', 'subject', 'semantic_prediction_digest', 'current_request_anchor', 'profile_prediction_at',
            'input_cutoff_at', 'frozen_at', 'commission_rate', 'currency', 'status', 'reason', 'early_window', 'age_seconds', 'deal_amount_rub',
            'commission_estimate_rub', 'full_value_base_rub', 'base_basis', 'estimated_probability', 'value_rub', 'payment_hold_observed',
            'calibration_status', 'price_verified', 'trip_identity', 'export_allowed', 'model_calls', 'conversion_uploads', 'deal_digest'];
        $c['money_fields_exact'] = self::exactKeys($v, $fields);
        $c['money_fixed_metadata_valid'] = ($v['calibration_status'] ?? null) === 'expert_estimate_not_calibrated'
            && ($v['price_verified'] ?? null) === false && ($v['trip_identity'] ?? null) === 'unverified'
            && ($v['export_allowed'] ?? null) === false && ($v['model_calls'] ?? null) === 0 && ($v['conversion_uploads'] ?? null) === 0
            && in_array($v['early_window'] ?? null, ['unknown', 'first_hour', 'first_three_hours', 'first_day', 'later_update'], true);
        $d = $v['deal_amount_rub'] ?? null;
        $shape = is_array($d) && self::exactKeys($d, ['low', 'high', 'point', 'basis', 'source_kind', 'source_refs'])
            && in_array($d['source_kind'] ?? null, ['unknown', 'customer_budget', 'delivered_offer', 'native_hotel_inquiry', 'channel_post_range'], true)
            && is_array($d['source_refs'] ?? null) && count($d['source_refs']) <= 50;
        $c['money_deal_shape_valid'] = $shape;
        $refsValid = $shape;
        if ($shape) foreach ($d['source_refs'] as $ref) {
            if (!is_string($ref) || preg_match('/\Ajournal:[1-9][0-9]{0,19}\z/D', $ref) !== 1) { $refsValid = false; break; }
        }
        $c['money_source_refs_valid'] = $refsValid;
        $copy = $v; unset($copy['deal_digest']);
        $c['money_digest_valid'] = $c['money_digest_string'] && hash_equals($v['deal_digest'], ModeledConversionSemanticPrediction::digest($copy));
        $point = is_array($d) ? ($d['point'] ?? null) : null;
        if ($policy !== null && $point === null) {
            $c['money_base_arithmetic_valid'] = ($v['commission_estimate_rub'] ?? null) === null
                && ($v['full_value_base_rub'] ?? null) === $policy['unknown_base_rub']
                && ($v['base_basis'] ?? null) === $policy['unknown_base_basis'];
        } elseif ($policy !== null && is_array($d)) {
            $lo = $d['low'] ?? null; $hi = $d['high'] ?? null;
            $range = ($lo !== null || $hi !== null)
                && ($lo === null || (is_int($lo) && $lo >= 10000 && $lo <= 10000000))
                && ($hi === null || (is_int($hi) && $hi >= 10000 && $hi <= 10000000))
                && ($lo === null || $hi === null || $lo <= $hi);
            $c['money_base_arithmetic_valid'] = is_int($point) && $range && ($lo === null || $point >= $lo) && ($hi === null || $point <= $hi)
                && ($v['commission_estimate_rub'] ?? null) === (int)round($point * ModeledConversionDealValue::COMMISSION_RATE)
                && ($v['full_value_base_rub'] ?? null) === min($policy['cap_rub'], (int)round($point * ModeledConversionDealValue::COMMISSION_RATE));
        }
        if (($v['status'] ?? null) === 'estimated') {
            $prob = $p['estimated_probability'] ?? null; $base = $v['full_value_base_rub'] ?? null;
            $c['money_probability_binding_valid'] = ($p['status'] ?? null) === 'predicted'
                && ($v['estimated_probability'] ?? null) === $prob;
            $c['money_amount_arithmetic_valid'] = (is_int($prob) || is_float($prob)) && is_int($base)
                && ($v['value_rub'] ?? null) === (int)round($prob * $base);
        } else {
            $c['money_probability_binding_valid'] = ($v['estimated_probability'] ?? null) === null;
            $c['money_amount_arithmetic_valid'] = ($v['value_rub'] ?? null) === null;
        }
        return $c;
    }
}
