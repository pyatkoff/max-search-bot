<?php
declare(strict_types=1);

/**
 * Pure policy over INTERNAL, already authenticated source receipts. This is not
 * a public request validator, signature verifier, DB claim or delivery permission.
 * The future repository must atomically enforce both unique receipt and inquiry.
 */
final class Max2ValueBindingPolicyV2
{
    public const COUNTER = 98615635;
    public const GOAL = 687941321;
    public const TARGET = 'anytour_modeled_value_v2_max2';
    public const CAP_KOPEKS = 10000000;
    public const MAX2_MODEL = 'max2_bootstrap_observed_value_v1';
    public const PRODUCER_CONTRACT_SHA256 = '3a563c1b7921915fd8b17c08f585cde88127904170a086c291a5677d49cf365e';
    public const PRODUCER_CUTOVER_UNIX = 1795262676;

    public static function positiveId($value): bool {
        return is_string($value) && preg_match('/\A[1-9][0-9]{0,19}\z/D', $value) === 1;
    }
    public static function digest($value): bool {
        return is_string($value) && preg_match('/\A[a-f0-9]{64}\z/D', $value) === 1;
    }
    public static function subject($value): bool {
        if (!is_array($value) || count($value) !== 4) return false;
        return ($value['project_key'] ?? null) === 'anytour'
            && in_array($value['platform'] ?? null, ['max', 'telegram'], true)
            && self::positiveId($value['customer_key'] ?? null) && self::positiveId($value['conversation_id'] ?? null);
    }
    public static function sameSubject($a, array $b): bool {
        if (!self::subject($a) || !self::subject($b)) return false;
        foreach (['project_key', 'platform', 'customer_key', 'conversation_id'] as $key) if ($a[$key] !== $b[$key]) return false;
        return true;
    }
    public static function inquiryKey(array $subject, string $journalId): string {
        if (!self::subject($subject) || !self::positiveId($journalId)) throw new InvalidArgumentException('INQUIRY_IDENTITY_REQUIRED');
        return hash('sha256', json_encode(['max2/common/inquiry/v2', '98615635', self::TARGET,
            $subject['project_key'], $subject['platform'], $subject['customer_key'], $subject['conversation_id'], $journalId], JSON_THROW_ON_ERROR));
    }
    public static function hold(string $reason): array { return ['status' => 'HOLD', 'reason' => $reason, 'provider_calls' => 0, 'writes' => 0]; }
    private static function positiveKopeks($value): bool {
        return is_string($value) && preg_match('/\A[1-9][0-9]{0,7}\z/D', $value) === 1 && (int)$value <= self::CAP_KOPEKS;
    }
    private static function actorBinding($context, array $subject): bool {
        return is_array($context) && ($context['authenticated_actor'] ?? null) === true
            && self::sameSubject($context['subject'] ?? null, $subject)
            && self::positiveId($context['external_user_id'] ?? null)
            && ($context['external_chat_id'] ?? null) === '-' . $context['external_user_id']
            && self::positiveId($context['customer_channel_id'] ?? null)
            && self::digest($context['subject_actor_binding_sha256'] ?? null);
    }

    public static function resolve(array $subject, array $inquiry, array $proof): array
    {
        if (!self::subject($subject) || !self::sameSubject($inquiry['subject'] ?? null, $subject)
            || !self::positiveId($inquiry['journal_id'] ?? null) || !self::positiveId($inquiry['native_message_id'] ?? null)
            || ($inquiry['kind'] ?? null) !== 'substantive_interest' || ($inquiry['source_version'] ?? null) !== 1
            || ($inquiry['verified_customer_message'] ?? null) !== true
            || !is_int($inquiry['recorded_at_unix'] ?? null) || $inquiry['recorded_at_unix'] < 946684800) return self::hold('INQUIRY_UNPROVED');
        $key = self::inquiryKey($subject, $inquiry['journal_id']);
        if (($proof['version'] ?? null) !== 2 || ($proof['counter_id'] ?? null) !== self::COUNTER
            || !self::sameSubject($proof['subject'] ?? null, $subject) || ($proof['inquiry_key'] ?? null) !== $key
            || !self::digest($proof['source_receipt_sha256'] ?? null)
            || ($proof['complete'] ?? null) !== true
            || ($proof['own_reserve_only'] ?? null) !== true
            || ($proof['producer_contract_sha256'] ?? null) !== self::PRODUCER_CONTRACT_SHA256
            || !is_int($proof['observed_at_unix'] ?? null)
            || $proof['observed_at_unix'] < $inquiry['recorded_at_unix']
            || $proof['observed_at_unix'] <= self::PRODUCER_CUTOVER_UNIX
            || !is_int($proof['blank_row_count'] ?? null) || $proof['blank_row_count'] < 0 || $proof['blank_row_count'] > 64
            || ($proof['cutoff_unix'] ?? null) !== $inquiry['recorded_at_unix']
            || !is_array($proof['candidates'] ?? null) || !array_is_list($proof['candidates'])) return self::hold('LINKAGE_COVERAGE_UNPROVED');
        $candidates = $proof['candidates'];
        if (count($candidates) > 1) return self::hold('POSSIBLE_MAX2_AMBIGUOUS');
        if (($proof['status'] ?? null) !== ($candidates === [] ? 'complete_empty' : 'complete')) return self::hold('LINKAGE_COVERAGE_UNPROVED');
        $actorScope = ($proof['coverage'] ?? null) === 'all_exact_actor_own_early_reserves_at_observation';
        if ($actorScope && ($subject['platform'] !== 'max' || !self::actorBinding($proof['actor_binding'] ?? null, $subject))) {
            return self::hold('ACTOR_SCOPE_UNPROVED');
        }
        $base = ['counter_id' => self::COUNTER, 'goal_id' => self::GOAL, 'target' => self::TARGET,
            'subject' => $subject, 'inquiry_key' => $key, 'inquiry_journal_id' => $inquiry['journal_id'],
            'inquiry_native_message_id' => $inquiry['native_message_id'],
            'inquiry_recorded_at_unix' => $inquiry['recorded_at_unix'], 'proof_sha256' => $proof['source_receipt_sha256'],
            'producer_contract_sha256' => self::PRODUCER_CONTRACT_SHA256,
            'proof_observed_at_unix' => $proof['observed_at_unix'],
            'provider_calls' => 0, 'writes' => 0];
        if ($candidates === []) {
            // Complete legacy/blank rows mean ZERO OWN NEW reserve under the pinned
            // same-add producer. This makes no claim about historical MAX2 traffic.
            // An empty post-filter or identity lookup cannot prove this absence.
            if (!$actorScope) {
                $origin = $proof['origin'] ?? null;
                if (($proof['coverage'] ?? null) !== 'admitted_origin_without_own_early_reserve'
                    || !is_array($origin) || ($origin['admitted'] ?? null) !== true
                    || !self::sameSubject($origin['subject'] ?? null, $subject)
                    || ($origin['inquiry_key'] ?? null) !== $key || !self::digest($origin['source_sha256'] ?? null)) {
                    return self::hold('NO_PROOF_OF_NO_NEW_EARLY_RESERVE');
                }
            }
            if (($proof['possible_max2_unresolved'] ?? null) !== false) return self::hold('POSSIBLE_MAX2_UNRESOLVED');
            return $base + ['status' => 'PROVEN_NO_NEW_EARLY_RESERVE', 'initial_common_kopeks' => 0,
                'binding_key' => hash('sha256', 'max2/common/no-early-reserve/v2|' . $key), 'tracker_record_id' => null];
        }
        if (!$actorScope
            || ($proof['possible_max2_unresolved'] ?? null) !== false) return self::hold('LINKAGE_CONTEXT_UNPROVED');
        $context = $proof['entry'] ?? null; $receipt = $candidates[0];
        if (!self::actorBinding($context, $subject) || !is_array($receipt)
            || ($context['external_user_id'] ?? null) !== $proof['actor_binding']['external_user_id']
            || ($context['customer_channel_id'] ?? null) !== $proof['actor_binding']['customer_channel_id']
            || ($context['exact_inquiry_context'] ?? null) !== true
            || !self::positiveId($context['entry_id'] ?? null) || !self::digest($context['entry_key'] ?? null)
            || !self::positiveId($context['publication_id'] ?? null) || !self::positiveId($context['version_id'] ?? null)
            || !self::digest($context['pair_mapping_sha256'] ?? null)
            || !is_string($context['offer_id'] ?? null) || preg_match('/\Amax_[1-9][0-9]{0,18}\z/D', $context['offer_id']) !== 1) {
            return self::hold('ENTRY_CONTEXT_UNPROVED');
        }
        if (!self::positiveId($receipt['tracker_record_id'] ?? null) || !self::digest($receipt['immutable_tuple_sha256'] ?? null)
            || ($receipt['counter_id'] ?? null) !== self::COUNTER || ($receipt['metric_project_id'] ?? null) !== '1538'
            || ($receipt['external_user_id'] ?? null) !== $context['external_user_id']
            || ($receipt['post_id'] ?? null) !== $context['publication_id'] || ($receipt['offer_id'] ?? null) !== $context['offer_id']
            || !is_string($receipt['client_id'] ?? null) || preg_match('/\A[1-9][0-9]{0,63}\z/D', $receipt['client_id']) !== 1
            || !self::positiveKopeks($receipt['early_kopeks'] ?? null)
            || !in_array($receipt['early_kopeks'] ?? null, ['1799','2636'], true)
            || ($receipt['common_kopeks'] ?? null) !== $receipt['early_kopeks']
            || ($receipt['model_version'] ?? null) !== self::MAX2_MODEL || ($receipt['issuance_state'] ?? null) !== 'issued_unconfirmed'
            || !is_int($receipt['recorded_at_unix'] ?? null) || $receipt['recorded_at_unix'] < self::PRODUCER_CUTOVER_UNIX) return self::hold('MAX2_RECEIPT_CONFLICT');
        // Reader must retain later rows. A later launch is not an earlier inquiry's
        // receipt, and cannot turn a previously full-valued inquiry into a binding.
        if ($receipt['recorded_at_unix'] > $inquiry['recorded_at_unix']) return self::hold('RECEIPT_AFTER_INQUIRY');
        if (!in_array($receipt['binding_state'] ?? null, ['unbound', 'same_inquiry'], true)
            || (($receipt['binding_state'] ?? null) === 'same_inquiry' && ($receipt['bound_inquiry_key'] ?? null) !== $key)) {
            return self::hold('MAX2_RECEIPT_ALREADY_BOUND_ELSEWHERE');
        }
        return $base + ['status' => 'UNIQUE_OBSERVED_MAX2', 'initial_common_kopeks' => (int)$receipt['common_kopeks'],
            'binding_key' => hash('sha256', json_encode(['max2/common/binding/v2', $key, $receipt['tracker_record_id'],
                $receipt['immutable_tuple_sha256']], JSON_THROW_ON_ERROR)),
            'tracker_record_id' => $receipt['tracker_record_id'], 'client_id' => $receipt['client_id'],
            'immutable_tuple_sha256' => $receipt['immutable_tuple_sha256'],
            'external_user_id' => $context['external_user_id'], 'customer_channel_id' => $context['customer_channel_id'],
            'entry_id' => $context['entry_id'], 'publication_id' => $context['publication_id'], 'version_id' => $context['version_id'],
            'offer_id' => $context['offer_id'], 'max2_model_version' => $receipt['model_version']];
    }
}
