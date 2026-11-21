<?php
declare(strict_types=1);
require dirname(__DIR__) . '/services/Max2CommonValueAccountingV2.php';

$assertions = 0;
function policyCheck(bool $condition, string $label): void {
    global $assertions; $assertions++;
    if (!$condition) throw new RuntimeException('ASSERTION_FAILED_' . $label);
}
function subjectFixture(string $platform = 'max'): array {
    return ['project_key' => 'anytour', 'platform' => $platform, 'customer_key' => '17', 'conversation_id' => '29'];
}
function inquiryFixture(array $subject): array {
    return ['subject' => $subject, 'journal_id' => '103', 'native_message_id' => '97', 'kind' => 'substantive_interest',
        'source_version' => 1, 'verified_customer_message' => true, 'recorded_at_unix' => 1795263600];
}
function proofFixture(array $subject, bool $linked): array {
    $key = Max2ValueBindingPolicyV2::inquiryKey($subject, '103');
    $proof = ['version' => 2, 'counter_id' => 98615635, 'subject' => $subject, 'inquiry_key' => $key,
        'source_receipt_sha256' => str_repeat('a', 64), 'complete' => true, 'cutoff_unix' => 1795263600,
        'observed_at_unix' => 1795263650, 'blank_row_count' => 0, 'own_reserve_only' => true,
        'producer_contract_sha256' => Max2ValueBindingPolicyV2::PRODUCER_CONTRACT_SHA256,
        'status' => $linked ? 'complete' : 'complete_empty',
        'coverage' => 'all_exact_actor_own_early_reserves_at_observation',
        'possible_max2_unresolved' => false, 'candidates' => []];
    $proof['actor_binding'] = ['authenticated_actor' => true, 'external_user_id' => '91',
        'subject' => $subject, 'external_chat_id' => '-91', 'customer_channel_id' => '25', 'subject_actor_binding_sha256' => str_repeat('e', 64)];
    if ($subject['platform'] === 'telegram') {
        $proof['coverage'] = 'admitted_origin_without_own_early_reserve';
        $proof['origin'] = ['admitted' => true, 'subject' => $subject, 'inquiry_key' => $key, 'source_sha256' => str_repeat('f', 64)];
        unset($proof['actor_binding']);
    }
    if ($linked) {
        $proof['entry'] = ['authenticated_actor' => true, 'exact_inquiry_context' => true, 'external_user_id' => '91',
            'subject' => $subject, 'external_chat_id' => '-91', 'customer_channel_id' => '25', 'subject_actor_binding_sha256' => str_repeat('e', 64),
            'entry_id' => '41', 'entry_key' => str_repeat('b', 64), 'publication_id' => '53', 'version_id' => '59',
            'pair_mapping_sha256' => str_repeat('c', 64), 'offer_id' => 'max_61'];
        $proof['candidates'][] = ['tracker_record_id' => '71', 'immutable_tuple_sha256' => str_repeat('d', 64),
            'counter_id' => 98615635, 'metric_project_id' => '1538', 'external_user_id' => '91', 'post_id' => '53', 'offer_id' => 'max_61',
            'client_id' => '1234567890123456789', 'early_kopeks' => '2636', 'common_kopeks' => '2636',
            'model_version' => Max2ValueBindingPolicyV2::MAX2_MODEL, 'issuance_state' => 'issued_unconfirmed',
            'recorded_at_unix' => 1795263540, 'binding_state' => 'unbound'];
    }
    return $proof;
}
function accountFixture(array $binding): array {
    return ['counter_id' => 98615635, 'goal_id' => 687941321, 'target' => Max2ValueBindingPolicyV2::TARGET,
        'version' => Max2CommonValueAccountingV2::VERSION, 'inquiry_key' => $binding['inquiry_key'],
        'binding_key' => $binding['binding_key'], 'initial_common_kopeks' => $binding['initial_common_kopeks'], 'events' => []];
}
function estimateFixture(array $subject, $value = '100.00', string $profile = '107'): array {
    return ['admitted_saved_profile' => true, 'subject' => $subject, 'inquiry_journal_id' => '103', 'profile_id' => $profile,
        'profile_sha256' => hash('sha256', 'synthetic-profile:' . $profile), 'model_version' => 'existing_saved_model_v1',
        'currency' => 'RUB', 'value_rub' => $value, 'input_cutoff_unix' => 1795263650];
}
function eventFixture(array $plan, string $state = 'unknown'): array {
    return array_intersect_key($plan, array_flip(['target', 'event_key', 'profile_id', 'profile_sha256', 'model_version', 'total_kopeks', 'delta_kopeks']))
        + ['delivery_state' => $state];
}

$s = subjectFixture(); $q = inquiryFixture($s);
$non = Max2ValueBindingPolicyV2::resolve($s, $q, proofFixture($s, false));
$linked = Max2ValueBindingPolicyV2::resolve($s, $q, proofFixture($s, true));
policyCheck($non['status'] === 'PROVEN_NO_NEW_EARLY_RESERVE' && $non['initial_common_kopeks'] === 0, 'broad_common_no_new_early_reserve_branch');
policyCheck($linked['status'] === 'UNIQUE_OBSERVED_MAX2' && $linked['initial_common_kopeks'] === 2636, 'exact_unique_max2_branch');
$full = Max2CommonValueAccountingV2::plan($non, estimateFixture($s), accountFixture($non));
$delta = Max2CommonValueAccountingV2::plan($linked, estimateFixture($s), accountFixture($linked));
policyCheck($full['delta_kopeks'] === 10000 && $full['goal_id'] === 687941321 && !$full['financial_delivery_permission'], 'nonmax2_full_proposal');
policyCheck($delta['delta_kopeks'] === 7364 && $delta['already_reserved_kopeks'] === 2636, 'max2_positive_delta_only');
$tg = subjectFixture('telegram');
$tgBinding = Max2ValueBindingPolicyV2::resolve($tg, inquiryFixture($tg), proofFixture($tg, false));
policyCheck(Max2CommonValueAccountingV2::plan($tgBinding, estimateFixture($tg), accountFixture($tgBinding))['delta_kopeks'] === 10000, 'admitted_nonmax2_telegram_supported');
$legacy = proofFixture($s, false); $legacy['blank_row_count'] = 4;
policyCheck(Max2ValueBindingPolicyV2::resolve($s, $q, $legacy)['status'] === 'PROVEN_NO_NEW_EARLY_RESERVE', 'legacy_blank_rows_prove_no_own_new_reserve');
$legacy['producer_contract_sha256'] = str_repeat('0', 64);
policyCheck(Max2ValueBindingPolicyV2::resolve($s, $q, $legacy)['status'] === 'HOLD', 'blank_rows_without_actual_producer_contract_not_absence');
$legacy = proofFixture($s, true); $legacy['blank_row_count'] = 4;
policyCheck(Max2ValueBindingPolicyV2::resolve($s, $q, $legacy)['status'] === 'UNIQUE_OBSERVED_MAX2', 'blank_rows_do_not_create_positive_ambiguity');
foreach (['1','10000000'] as $unsupported) {
    $bad = proofFixture($s, true); $bad['candidates'][0]['early_kopeks'] = $unsupported; $bad['candidates'][0]['common_kopeks'] = $unsupported;
    policyCheck(Max2ValueBindingPolicyV2::resolve($s, $q, $bad)['status'] === 'HOLD', 'fixed_bootstrap_model_cannot_invent_other_amounts');
}
$otherAllowed = proofFixture($s, true); $otherAllowed['candidates'][0]['early_kopeks'] = '1799'; $otherAllowed['candidates'][0]['common_kopeks'] = '1799';
policyCheck(Max2ValueBindingPolicyV2::resolve($s, $q, $otherAllowed)['initial_common_kopeks'] === 1799, 'other_actual_bootstrap_amount_allowed');

$account = accountFixture($linked); $account['events'][] = eventFixture($delta);
$repeat = Max2CommonValueAccountingV2::plan($linked, estimateFixture($s), $account);
$raised = Max2CommonValueAccountingV2::plan($linked, estimateFixture($s, '150.00', '109'), $account);
policyCheck($repeat['status'] === 'NOOP' && $repeat['delta_kopeks'] === 0, 'same_profile_no_repeat');
policyCheck($raised['delta_kopeks'] === 5000, 'unknown_prior_delta_stays_reserved');
foreach (['reserved', 'attempted', 'accepted', 'processed', 'unknown', 'failed'] as $state) {
    $snapshot = accountFixture($linked); $snapshot['events'][] = eventFixture($delta, $state);
    policyCheck(Max2CommonValueAccountingV2::plan($linked, estimateFixture($s, 150, '109'), $snapshot)['delta_kopeks'] === 5000, 'all_states_reserve_' . $state);
}
policyCheck(Max2CommonValueAccountingV2::plan($linked, estimateFixture($s, '20.00', '109'), $account)['delta_kopeks'] === 0, 'decline_no_negative_or_release');
policyCheck(Max2CommonValueAccountingV2::plan($linked, estimateFixture($s, '101.00'), $account)['status'] === 'HOLD', 'same_profile_changed_amount_conflict');
$secondPlan = Max2CommonValueAccountingV2::plan($linked, estimateFixture($s), accountFixture($linked));
policyCheck($secondPlan['event_key'] === $delta['event_key'] && $secondPlan['requires_atomic_unique_reservation'], 'concurrent_plans_need_one_unique_db_claim');

$bad = proofFixture($s, true); $bad['candidates'][] = $bad['candidates'][0];
policyCheck(Max2ValueBindingPolicyV2::resolve($s, $q, $bad)['status'] === 'HOLD', 'multiple_possible_receipts_hold');
$bad = proofFixture($s, false); $bad['complete'] = false;
policyCheck(Max2ValueBindingPolicyV2::resolve($s, $q, $bad)['status'] === 'HOLD', 'incomplete_zero_is_not_nonmax2');
$bad = proofFixture($s, false); $bad['coverage'] = 'exact_actor_publication_offer';
policyCheck(Max2ValueBindingPolicyV2::resolve($s, $q, $bad)['reason'] === 'NO_PROOF_OF_NO_NEW_EARLY_RESERVE', 'empty_post_filter_is_not_global_absence');
$bad = proofFixture($s, false); $bad['possible_max2_unresolved'] = true;
policyCheck(Max2ValueBindingPolicyV2::resolve($s, $q, $bad)['status'] === 'HOLD', 'possible_max2_never_full_fallback');
foreach (['external_user_id' => '92', 'post_id' => '54', 'offer_id' => 'max_62', 'common_kopeks' => '2637',
          'metric_project_id' => '1516', 'issuance_state' => 'processed', 'recorded_at_unix' => 1795263700] as $key => $value) {
    $bad = proofFixture($s, true); $bad['candidates'][0][$key] = $value;
    policyCheck(Max2ValueBindingPolicyV2::resolve($s, $q, $bad)['status'] === 'HOLD', 'wrong_receipt_' . $key);
}
$bad = proofFixture($s, true); $bad['entry']['subject']['conversation_id'] = '30';
policyCheck(Max2ValueBindingPolicyV2::resolve($s, $q, $bad)['status'] === 'HOLD', 'actor_must_belong_to_exact_subject');
$bad = proofFixture($s, true); $bad['candidates'][0]['binding_state'] = 'other_inquiry';
policyCheck(Max2ValueBindingPolicyV2::resolve($s, $q, $bad)['status'] === 'HOLD', 'one_receipt_not_reused_for_other_inquiry');
$bad = proofFixture($s, true); $bad['candidates'][0]['binding_state'] = 'same_inquiry'; $bad['candidates'][0]['bound_inquiry_key'] = $linked['inquiry_key'];
policyCheck(Max2ValueBindingPolicyV2::resolve($s, $q, $bad)['status'] === 'UNIQUE_OBSERVED_MAX2', 'exact_binding_reinspection');
$later = proofFixture($s, true); $later['candidates'][0]['recorded_at_unix'] = 1795263610;
policyCheck(Max2ValueBindingPolicyV2::resolve($s, $q, $later)['reason'] === 'RECEIPT_AFTER_INQUIRY', 'reverse_order_no_full_fallback_or_late_rebinding');
$fullAccount = accountFixture($non); $fullAccount['events'][] = eventFixture($full);
policyCheck(Max2CommonValueAccountingV2::plan($linked, estimateFixture($s, '150.00', '109'), $fullAccount)['reason'] === 'V2_ACCOUNT_CONFLICT', 'claimed_full_inquiry_cannot_become_linked');
$linkedAccount = accountFixture($linked); $linkedAccount['events'][] = eventFixture($delta);
policyCheck(Max2CommonValueAccountingV2::plan($non, estimateFixture($s, '150.00', '109'), $linkedAccount)['reason'] === 'V2_ACCOUNT_CONFLICT', 'claimed_linked_inquiry_cannot_become_full');
$zero = Max2CommonValueAccountingV2::plan($linked, estimateFixture($s, 20, '109'), $account);
$zeroAccount = $account; $zeroAccount['events'][] = eventFixture($zero, 'not_needed');
policyCheck(Max2CommonValueAccountingV2::plan($linked, estimateFixture($s, 20, '109'), $zeroAccount)['status'] === 'NOOP', 'zero_evaluation_is_durable_and_repeatable');
policyCheck(Max2CommonValueAccountingV2::plan($linked, estimateFixture($s, 150, '109'), $zeroAccount)['reason'] === 'IMMUTABLE_PROFILE_CONFLICT', 'zero_evaluation_changed_profile_conflict');

foreach ([1.2, '1.2', '01.00', '-1.00', '1e2', '100000.01', null] as $value) {
    policyCheck(Max2CommonValueAccountingV2::rubToKopeks($value) === null, 'mixed_units_or_noncanonical_money_rejected');
}
policyCheck(Max2CommonValueAccountingV2::rubToKopeks('0.01') === 1 && Max2CommonValueAccountingV2::rubToKopeks(100000) === 10000000, 'exact_minor_units_and_cap');
$wrong = accountFixture($linked); $wrong['target'] = 'anytour_modeled_value_v1';
policyCheck(Max2CommonValueAccountingV2::plan($linked, estimateFixture($s), $wrong)['status'] === 'HOLD', 'old_v1_account_rejected');
$wrong = $account; $wrong['events'][0]['target'] = 'anytour_modeled_value_v1';
policyCheck(Max2CommonValueAccountingV2::plan($linked, estimateFixture($s, 150, '109'), $wrong)['status'] === 'HOLD', 'old_v1_reserve_rejected');
policyCheck(Max2CommonValueAccountingV2::plan(['status' => 'HOLD'], estimateFixture($s), accountFixture($linked))['status'] === 'HOLD', 'hold_not_zero_or_full');
echo 'PASS ', $assertions, " pure common-v2 policy assertions; no DB/provider/runtime calls\n";
