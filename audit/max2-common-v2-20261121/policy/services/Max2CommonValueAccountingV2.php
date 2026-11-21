<?php
declare(strict_types=1);
require_once __DIR__ . '/Max2ValueBindingPolicyV2.php';

/** Pure reservation plan. Does not read v1 sums, send a goal or mutate any ledger. */
final class Max2CommonValueAccountingV2
{
    public const VERSION = 'max2_common_observed_inquiry_value_v2';
    private const RESERVED_STATES = ['reserved', 'attempted', 'accepted', 'processed', 'unknown', 'failed'];

    public static function rubToKopeks($value): ?int
    {
        if (is_int($value)) $value = (string)$value;
        if (!is_string($value) || preg_match('/\A(0|[1-9][0-9]{0,5})(?:\.([0-9]{2}))?\z/D', $value, $m) !== 1) return null;
        $amount = (int)$m[1] * 100 + (isset($m[2]) ? (int)$m[2] : 0);
        return $amount <= Max2ValueBindingPolicyV2::CAP_KOPEKS ? $amount : null;
    }
    private static function hold(string $reason): array { return Max2ValueBindingPolicyV2::hold($reason); }
    public static function plan(array $binding, array $estimate, array $account): array
    {
        if (!in_array($binding['status'] ?? null, ['PROVEN_NO_NEW_EARLY_RESERVE', 'UNIQUE_OBSERVED_MAX2'], true)
            || ($binding['counter_id'] ?? null) !== Max2ValueBindingPolicyV2::COUNTER
            || ($binding['goal_id'] ?? null) !== Max2ValueBindingPolicyV2::GOAL || ($binding['target'] ?? null) !== Max2ValueBindingPolicyV2::TARGET
            || !Max2ValueBindingPolicyV2::digest($binding['inquiry_key'] ?? null) || !Max2ValueBindingPolicyV2::digest($binding['binding_key'] ?? null)) {
            return self::hold('LINKAGE_HOLD');
        }
        $baseline = $binding['initial_common_kopeks'] ?? null;
        if (!is_int($baseline) || $baseline < 0 || $baseline > Max2ValueBindingPolicyV2::CAP_KOPEKS
            || ($binding['status'] === 'PROVEN_NO_NEW_EARLY_RESERVE' && $baseline !== 0)
            || ($binding['status'] === 'UNIQUE_OBSERVED_MAX2' && $baseline === 0)) return self::hold('BASELINE_CONFLICT');
        $total = self::rubToKopeks($estimate['value_rub'] ?? null);
        if (($estimate['admitted_saved_profile'] ?? null) !== true || ($estimate['currency'] ?? null) !== 'RUB' || $total === null
            || !Max2ValueBindingPolicyV2::sameSubject($estimate['subject'] ?? null, $binding['subject'] ?? [])
            || ($estimate['inquiry_journal_id'] ?? null) !== ($binding['inquiry_journal_id'] ?? null)
            || !Max2ValueBindingPolicyV2::positiveId($estimate['profile_id'] ?? null)
            || !Max2ValueBindingPolicyV2::digest($estimate['profile_sha256'] ?? null)
            || !is_string($estimate['model_version'] ?? null) || preg_match('/\A[A-Za-z0-9][A-Za-z0-9_.:-]{0,95}\z/D', $estimate['model_version']) !== 1
            || !is_int($estimate['input_cutoff_unix'] ?? null)
            || $estimate['input_cutoff_unix'] < ($binding['inquiry_recorded_at_unix'] ?? PHP_INT_MAX)) return self::hold('MODELED_TOTAL_UNPROVED');
        if (($account['counter_id'] ?? null) !== Max2ValueBindingPolicyV2::COUNTER || ($account['goal_id'] ?? null) !== Max2ValueBindingPolicyV2::GOAL
            || ($account['target'] ?? null) !== Max2ValueBindingPolicyV2::TARGET || ($account['version'] ?? null) !== self::VERSION
            || ($account['inquiry_key'] ?? null) !== $binding['inquiry_key'] || ($account['binding_key'] ?? null) !== $binding['binding_key']
            || ($account['initial_common_kopeks'] ?? null) !== $baseline
            || !is_array($account['events'] ?? null) || !array_is_list($account['events']) || count($account['events']) > 1000) return self::hold('V2_ACCOUNT_CONFLICT');
        $eventKey = hash('sha256', 'max2/common/profile-delta/v2|' . $binding['inquiry_key'] . '|' . $estimate['profile_id']);
        $reserved = $baseline; $seen = []; $duplicate = false;
        foreach ($account['events'] as $event) {
            if (!is_array($event) || ($event['target'] ?? null) !== Max2ValueBindingPolicyV2::TARGET
                || !Max2ValueBindingPolicyV2::digest($event['event_key'] ?? null) || isset($seen[$event['event_key']])
                || !Max2ValueBindingPolicyV2::positiveId($event['profile_id'] ?? null)
                || !Max2ValueBindingPolicyV2::digest($event['profile_sha256'] ?? null)
                || !is_string($event['model_version'] ?? null) || preg_match('/\A[A-Za-z0-9][A-Za-z0-9_.:-]{0,95}\z/D', $event['model_version']) !== 1
                || $event['event_key'] !== hash('sha256', 'max2/common/profile-delta/v2|' . $binding['inquiry_key'] . '|' . $event['profile_id'])
                || !is_int($event['delta_kopeks'] ?? null) || $event['delta_kopeks'] < 0
                || $event['delta_kopeks'] > Max2ValueBindingPolicyV2::CAP_KOPEKS
                || !is_int($event['total_kopeks'] ?? null) || $event['total_kopeks'] < $event['delta_kopeks']
                || $event['total_kopeks'] > Max2ValueBindingPolicyV2::CAP_KOPEKS
                || ($event['delta_kopeks'] === 0
                    ? ($event['delivery_state'] ?? null) !== 'not_needed'
                    : !in_array($event['delivery_state'] ?? null, self::RESERVED_STATES, true))) return self::hold('V2_RESERVED_EVENT_CONFLICT');
            $seen[$event['event_key']] = true; $reserved += $event['delta_kopeks'];
            if ($reserved > Max2ValueBindingPolicyV2::CAP_KOPEKS) return self::hold('V2_RESERVE_OVER_CAP');
            if ($event['event_key'] === $eventKey) {
                if (($event['profile_id'] ?? null) !== $estimate['profile_id'] || ($event['profile_sha256'] ?? null) !== $estimate['profile_sha256']
                    || ($event['model_version'] ?? null) !== $estimate['model_version'] || ($event['total_kopeks'] ?? null) !== $total) {
                    return self::hold('IMMUTABLE_PROFILE_CONFLICT');
                }
                $duplicate = true;
            }
        }
        $delta = $duplicate ? 0 : max(0, $total - $reserved);
        return ['status' => $delta > 0 ? 'RESERVE_PROPOSAL' : 'NOOP', 'reason' => $duplicate ? 'PROFILE_ALREADY_RESERVED' : ($delta > 0 ? 'POSITIVE_INCREMENT' : 'NO_POSITIVE_INCREMENT'),
            'version' => self::VERSION, 'counter_id' => Max2ValueBindingPolicyV2::COUNTER, 'goal_id' => Max2ValueBindingPolicyV2::GOAL,
            'target' => Max2ValueBindingPolicyV2::TARGET, 'inquiry_key' => $binding['inquiry_key'], 'binding_key' => $binding['binding_key'],
            'event_key' => $eventKey, 'profile_id' => $estimate['profile_id'], 'profile_sha256' => $estimate['profile_sha256'],
            'model_version' => $estimate['model_version'], 'total_kopeks' => $total, 'already_reserved_kopeks' => $reserved,
            'delta_kopeks' => $delta, 'requires_atomic_unique_reservation' => true, 'financial_delivery_permission' => false,
            'provider_calls' => 0, 'writes' => 0];
    }
}
