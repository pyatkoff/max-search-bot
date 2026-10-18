<?php
declare(strict_types=1);

// Neutral evidence fixtures only: this contract test does not read an archive,
// interpret customer text, call a model or connect to application storage.
require_once __DIR__.'/../services/ManagerRequestMemoryProjection.php';

$mprChecks = 0;
$mprFailures = 0;
function mprSame(string $name, $actual, $expected): void
{
    global $mprChecks, $mprFailures;
    $mprChecks++;
    if ($actual === $expected) {
        echo "PASS {$name}\n";
        return;
    }
    $mprFailures++;
    echo "FAIL {$name}\n  expected ".var_export($expected, true)."\n  actual ".var_export($actual, true)."\n";
}
function mprCase(string $name, callable $case): void
{
    global $mprChecks, $mprFailures;
    try {
        $case();
    } catch (Throwable $error) {
        $mprChecks++;
        $mprFailures++;
        echo "FAIL {$name}: unexpected ".get_class($error).': '.$error->getMessage()."\n";
    }
}
function mprRejects(string $name, callable $operation, bool $allowTypeError = false): void
{
    global $mprChecks, $mprFailures;
    $mprChecks++;
    try {
        $operation();
    } catch (InvalidArgumentException $error) {
        echo "PASS {$name}\n";
        return;
    } catch (Throwable $error) {
        if ($allowTypeError && $error instanceof TypeError) {
            echo "PASS {$name}\n";
            return;
        }
        $mprFailures++;
        echo "FAIL {$name}: wrong rejection ".get_class($error)."\n";
        return;
    }
    $mprFailures++;
    echo "FAIL {$name}: malformed evidence was accepted\n";
}
function mprEnvelope(array $events = [], int $records = 20): array
{
    $support = [];
    for ($ordinal = 1; $ordinal <= $records; $ordinal++) {
        $support[] = [
            'ordinal' => $ordinal,
            'message_ref' => 'message-'.$ordinal,
            'direction' => $ordinal === 9 ? 'SERVICE' : ($ordinal % 2 ? 'IN' : 'OUT'),
            'date_raw' => $ordinal % 3 ? '2031-04-05 12:34:56' : null,
        ];
    }
    return [
        'schema_version' => 1,
        'conversation_ref' => 'neutral-conversation-A',
        'source_revision' => str_repeat('a', 64),
        'source_record_count' => $records,
        'support' => $support,
        'events' => $events,
    ];
}
function mprOpen(string $request = 'requestA', int $ordinal = 1, array $extra = []): array
{
    return array_merge(['id' => $request.'-open', 'type' => 'open_request',
        'request_id' => $request, 'evidence_ordinals' => [$ordinal]], $extra);
}
function mprRequirement(string $id, string $request, int $ordinal, string $field, $value, array $extra = []): array
{
    return array_merge(['id' => $id, 'type' => 'requirement', 'request_id' => $request,
        'evidence_ordinals' => [$ordinal], 'field' => $field, 'value' => $value,
        'evidence_state' => 'customer_stated'], $extra);
}
function mprOffer(string $id, string $request, int $ordinal, array $fields): array
{
    return ['id' => $id, 'type' => 'offer', 'request_id' => $request,
        'evidence_ordinals' => [$ordinal], 'offer_id' => $id, 'fields' => $fields];
}
function mprLife(string $id, string $request, int $ordinal, string $event, string $operation): array
{
    return ['id' => $id, 'type' => 'lifecycle', 'request_id' => $request,
        'evidence_ordinals' => [$ordinal], 'event' => $event, 'operation_id' => $operation];
}
function mprIntegrityFixture(): array
{
    return mprEnvelope([
        mprOpen(),
        mprRequirement('budgetA', 'requestA', 3, 'budget', ['amount' => 120, 'currency' => null, 'basis' => null]),
        mprOffer('offerA', 'requestA', 4, ['hotel_nights' => 12, 'amount' => 240]),
        ['id' => 'acceptA', 'type' => 'accept_scope', 'request_id' => 'requestA', 'evidence_ordinals' => [5],
            'scope' => 'specific_offer_change', 'offer_id' => 'offerA', 'accepted_fields' => ['hotel_nights']],
        mprLife('paymentA', 'requestA', 6, 'payment_reported', 'operationA'),
    ]);
}

mprCase('deadline revision and source prefix', static function (): void {
    $input = mprEnvelope([
        mprOpen(),
        mprRequirement('datesInitial', 'requestA', 1, 'travel_dates', ['window_raw' => 'day 6 through day 14', 'home_deadline' => null], ['evidence_state' => 'ambiguous']),
        mprRequirement('datesRevised', 'requestA', 5, 'travel_dates', ['home_deadline' => 'day 13', 'flexibility' => null], ['strength' => 'hard']),
    ]);
    $input['support'][0]['date_raw'] = '2031-04-05T23:30:00+04:30';
    $before = ManagerRequestMemoryProjection::project($input, 4);
    $after = ManagerRequestMemoryProjection::project($input, 5);
    mprSame('later deadline cannot rewrite earlier view', $before['requests']['requestA']['requirements']['travel_dates']['value']['home_deadline'], null);
    mprSame('corrected deadline becomes known at its evidence', $after['requests']['requestA']['requirements']['travel_dates']['known_after_ordinal'], 5);
    $prior = $after['requests']['requestA']['requirement_history']['travel_dates'][0];
    $supersededBy = $prior['superseded_by'];
    unset($prior['superseded_by']);
    mprSame('prior interpretation is retained', $prior, $before['requests']['requestA']['requirements']['travel_dates']);
    mprSame('revision history identifies superseding event', $supersededBy, 'datesRevised');
    mprSame('raw exported date is not normalized', $before['requests']['requestA']['open_evidence'][0]['date_raw'], '2031-04-05T23:30:00+04:30');
    mprSame('future event does not enter applied IDs', in_array('datesRevised', $before['applied_event_ids'], true), false);
    $prefix = $input;
    array_pop($prefix['events']);
    mprSame('appending a valid future correction preserves the whole prior projection', $before, ManagerRequestMemoryProjection::project($prefix, 4));
});

mprCase('bounded acceptance is separate from requirements', static function (): void {
    $input = mprEnvelope([
        mprOpen(),
        mprRequirement('nightsWanted', 'requestA', 1, 'duration', ['quantity' => 9, 'unit' => 'hotel_nights']),
        mprOffer('offerLonger', 'requestA', 2, ['hotel_nights' => 12, 'departure_day' => 8]),
        ['id' => 'formatYes', 'type' => 'accept_scope', 'request_id' => 'requestA', 'evidence_ordinals' => [3],
            'scope' => 'service_format', 'value' => 'remote_link'],
        ['id' => 'oneChangeYes', 'type' => 'accept_scope', 'request_id' => 'requestA', 'evidence_ordinals' => [5],
            'scope' => 'specific_offer_change', 'offer_id' => 'offerLonger', 'accepted_fields' => ['departure_day'], 'value' => 8],
    ]);
    $format = ManagerRequestMemoryProjection::project($input, 3)['requests']['requestA'];
    $specific = ManagerRequestMemoryProjection::project($input, 5)['requests']['requestA'];
    mprSame('format acceptance leaves requested nine nights', $format['requirements']['duration']['value']['quantity'], 9);
    mprSame('format acceptance has no tour fields', $format['accepted_scopes'][0]['accepted_fields'], []);
    mprSame('format acceptance has no offer binding', $format['accepted_scopes'][0]['offer_id'], null);
    mprSame('format yes does not accept offered twelve nights', $format['offers']['offerLonger']['accepted'], false);
    mprSame('specific acceptance records only the named change', $specific['accepted_scopes'][1]['accepted_fields'], ['departure_day']);
    mprSame('partial acceptance does not mark the whole offer accepted', $specific['offers']['offerLonger']['accepted'], false);
    mprSame('partial acceptance leaves unrelated duration untouched', $specific['requirements']['duration'], $format['requirements']['duration']);
    mprSame('historic price is not current verified price', $specific['offers']['offerLonger']['current_price_verified'], false);
    mprSame('visible offer is not a historical page snapshot', $specific['offers']['offerLonger']['historical_send_snapshot'], false);
});

mprCase('conditional party belongs to its own branch', static function (): void {
    $input = mprEnvelope([
        mprOpen(),
        mprRequirement('partyBaseline', 'requestA', 1, 'party_actual', ['total' => 2]),
        mprOffer('pairPrice', 'requestA', 2, ['quoted_party_total' => 2, 'amount' => 120, 'currency' => null, 'group_total' => null]),
        mprOpen('requestSix', 3, ['relationship' => 'conditional_alternative', 'related_request_id' => 'requestA']),
        mprRequirement('partyConditional', 'requestSix', 3, 'party_actual', ['total' => 6], ['hypothetical' => true]),
        mprOffer('roomProposal', 'requestSix', 4, ['rooms' => 6, 'customer_requirement' => false, 'price_basis' => null]),
    ]);
    $requests = ManagerRequestMemoryProjection::project($input, 4)['requests'];
    mprSame('baseline remains two people', $requests['requestA']['requirements']['party_actual']['value']['total'], 2);
    mprSame('six people are explicitly hypothetical', $requests['requestSix']['requirements']['party_actual']['hypothetical'], true);
    mprSame('alternative retains relationship to baseline', $requests['requestSix']['related_request_id'], 'requestA');
    mprSame('room proposal is not a room requirement', array_key_exists('room_plan', $requests['requestSix']['requirements']), false);
    mprSame('pair quote is not multiplied into group total', $requests['requestA']['offers']['pairPrice']['fields']['group_total'], null);
    mprSame('new branch inherits no baseline offers', array_key_exists('pairPrice', $requests['requestSix']['offers']), false);
});

mprCase('actual ages and literal duration are not tariffs or inferred counts', static function (): void {
    $input = mprEnvelope([
        mprOpen(),
        mprRequirement('actualParty', 'requestA', 1, 'party_actual', ['adults' => 2, 'children_ages' => [13, 15]]),
        mprRequirement('daysWanted', 'requestA', 1, 'duration', ['quantity_range' => [8, 10], 'unit' => 'days']),
        mprOffer('adultTariff', 'requestA', 2, ['tariff_adults' => 4, 'hotel_nights' => 9]),
        mprOpen('requestAmbiguous', 3, ['relationship' => 'parallel_alternative', 'related_request_id' => 'requestA']),
        mprRequirement('ageSpan', 'requestAmbiguous', 3, 'party_actual', ['adults' => 2, 'children_age_raw' => '6–9 years', 'child_count' => null, 'exact_child_ages' => null], ['evidence_state' => 'ambiguous']),
    ]);
    $requests = ManagerRequestMemoryProjection::project($input, 3)['requests'];
    mprSame('child ages survive adult tariff', $requests['requestA']['requirements']['party_actual']['value']['children_ages'], [13, 15]);
    mprSame('days remain days beside a hotel-night offer', $requests['requestA']['requirements']['duration']['value']['unit'], 'days');
    mprSame('ambiguous age span does not imply two children', $requests['requestAmbiguous']['requirements']['party_actual']['value']['child_count'], null);
    mprSame('ambiguous age span does not invent exact ages', $requests['requestAmbiguous']['requirements']['party_actual']['value']['exact_child_ages'], null);
});

mprCase('customer amount and owner default retain different provenance', static function (): void {
    $provenance = [
        'amount' => ['kind' => 'customer_statement', 'evidence_ordinals' => [1]],
        'basis' => ['kind' => 'owner_default', 'source' => 'neutral-policy', 'revision' => 'revision-one'],
        'currency' => ['kind' => 'unknown'],
    ];
    $input = mprEnvelope([
        mprOpen(),
        mprRequirement('budgetMixed', 'requestA', 1, 'budget', ['amount' => 240, 'currency' => null, 'basis' => 'whole_party'],
            ['evidence_state' => 'mixed_customer_and_owner_default', 'subfield_provenance' => $provenance]),
        mprRequirement('budgetLiteral', 'requestA', 3, 'budget', ['raw' => '260', 'amount' => 260, 'currency' => null, 'basis' => null]),
    ]);
    $first = ManagerRequestMemoryProjection::project($input, 1)['requests']['requestA']['requirements']['budget'];
    $later = ManagerRequestMemoryProjection::project($input, 3)['requests']['requestA'];
    mprSame('owner basis is not attributed to customer', $first['subfield_provenance']['basis'], $provenance['basis']);
    mprSame('unknown currency remains unknown', $later['requirements']['budget']['value']['currency'], null);
    mprSame('unknown later price basis remains unknown', $later['requirements']['budget']['value']['basis'], null);
    mprSame('superseded budget retains owner policy revision', $later['requirement_history']['budget'][0]['subfield_provenance']['basis']['revision'], 'revision-one');
});

mprCase('cancellation refund payment and confirmation remain separate reports', static function (): void {
    $input = mprEnvelope([
        mprOpen(),
        mprRequirement('partyOld', 'requestA', 1, 'party_actual', ['total' => 2]),
        mprLife('firstPayment', 'requestA', 2, 'payment_reported', 'paymentA'),
        mprLife('cancelPayment', 'requestA', 4, 'payment_cancellation_reported', 'paymentA'),
        mprLife('refundSeen', 'requestA', 5, 'refund_reported', 'paymentA'),
        mprOpen('requestLater', 7, ['relationship' => 'later_request', 'related_request_id' => 'requestA']),
        mprLife('newPayment', 'requestLater', 8, 'payment_reported', 'paymentB'),
        mprLife('bookingPending', 'requestLater', 10, 'booking_pending_reported', 'bookingB'),
        mprLife('bookingConfirmed', 'requestLater', 12, 'booking_confirmed_reported', 'bookingB'),
        mprLife('documentsSent', 'requestLater', 14, 'documents_sent_reported', 'bookingB'),
        mprLife('tripReported', 'requestLater', 17, 'trip_completed_reported', 'tripB'),
    ]);
    $cancelled = ManagerRequestMemoryProjection::project($input, 4)['requests']['requestA'];
    $refunded = ManagerRequestMemoryProjection::project($input, 5)['requests']['requestA'];
    $pending = ManagerRequestMemoryProjection::project($input, 10)['requests']['requestLater'];
    $final = ManagerRequestMemoryProjection::project($input, 17)['requests'];
    mprSame('payment cancellation does not invent a completed refund', array_column($cancelled['lifecycle_events'], 'event'), ['payment_reported', 'payment_cancellation_reported']);
    mprSame('refund appears only at later report', array_column($refunded['lifecycle_events'], 'event'), ['payment_reported', 'payment_cancellation_reported', 'refund_reported']);
    mprSame('pending view contains no later confirmation', array_column($pending['lifecycle_events'], 'event'), ['payment_reported', 'booking_pending_reported']);
    mprSame('new season inherits no old party', $pending['requirements'], []);
    mprSame('later payment has its own operation', $pending['lifecycle_events'][0]['operation_id'], 'paymentB');
    mprSame('confirmation evidence retains exact source', $final['requestLater']['lifecycle_events'][2]['evidence'][0],
        ['ordinal' => 12, 'message_ref' => 'message-12', 'direction' => 'OUT', 'date_raw' => null]);
    mprSame('refund evidence retains customer direction', $refunded['lifecycle_events'][2]['evidence'][0]['direction'], 'IN');
    foreach ($final as $request) {
        foreach ($request['lifecycle_events'] as $event) {
            mprSame('report does not verify a financial ledger: '.$event['event'], $event['financial_ledger_verified'], false);
            mprSame('lifecycle remains a reported outcome: '.$event['event'], $event['reported_outcome_only'], true);
        }
    }
});

mprCase('source attribution purity and deterministic detached output', static function (): void {
    $input = mprEnvelope([
        mprOpen('requestA', 2),
        mprRequirement('unattributedDuration', 'requestA', 2, 'duration', ['quantity' => 8, 'unit' => 'hotel_nights'], ['evidence_state' => 'unattributed_request_text']),
        mprLife('serviceReport', 'requestA', 9, 'documents_sent_reported', 'operationA'),
    ]);
    $original = $input;
    $one = ManagerRequestMemoryProjection::project($input, 9);
    $two = ManagerRequestMemoryProjection::project($input, 9);
    mprSame('same supplied evidence produces identical output', $one, $two);
    mprSame('projection does not mutate caller input', $input, $original);
    mprSame('unknown OUT attribution stays explicit', $one['requests']['requestA']['requirements']['duration']['evidence_state'], 'unattributed_request_text');
    mprSame('SERVICE evidence direction is preserved', $one['requests']['requestA']['lifecycle_events'][0]['evidence'][0]['direction'], 'SERVICE');
    mprSame('null source date remains null', $one['requests']['requestA']['lifecycle_events'][0]['evidence'][0]['date_raw'], null);
    $keys = array_keys($one);
    sort($keys);
    $expected = ['schema', 'conversation_ref', 'source_revision', 'cutoff_ordinal', 'requests', 'applied_event_ids', 'production_writes', 'coverage'];
    sort($expected);
    mprSame('projection exposes only the request-evidence envelope', $keys, $expected);
    mprSame('no production operation is authorized', $one['production_writes'], false);
    mprSame('coverage does not claim transcript extraction', $one['coverage'], 'explicit_evidence_events_not_automatic_extraction');
    $one['requests']['requestA']['requirements']['duration']['value']['quantity'] = 99;
    $one['requests']['requestA']['lifecycle_events'][0]['evidence'][0]['message_ref'] = 'changed-output';
    mprSame('mutating a returned nested value leaves the caller untouched', $input, $original);
    mprSame('mutating one projection leaves another detached', $two['requests']['requestA']['requirements']['duration']['value']['quantity'], 8);
});

mprCase('sparse unsorted support is not transcript ordering', static function (): void {
    $input = mprEnvelope([mprOpen(), mprRequirement('jointEvidence', 'requestA', 5, 'travel_dates', ['unknown' => true])]);
    $input['events'][1]['evidence_ordinals'] = [5, 1];
    $input['support'] = [$input['support'][4], $input['support'][0]];
    $before = ManagerRequestMemoryProjection::project($input, 4);
    $after = ManagerRequestMemoryProjection::project($input, 5);
    mprSame('multi-message fact waits for its latest evidence', $before['requests']['requestA']['requirements'], []);
    mprSame('known-after uses maximum rather than first supplied ordinal', $after['requests']['requestA']['requirements']['travel_dates']['known_after_ordinal'], 5);
    mprSame('no future support list is copied into output', array_key_exists('support', $before), false);
    mprSame('future message cannot leak through earlier open evidence', array_column($before['requests']['requestA']['open_evidence'], 'ordinal'), [1]);
});

mprCase('borrowed PHP references cannot alias a returned snapshot', static function (): void {
    $quantity = 9;
    $nested = ['label' => 'neutral'];
    $messageRef = 'message-borrowed';
    $dateRaw = '2031-04-05 raw time';
    $value = ['quantity' => &$quantity, 'unit' => 'days', 'nested' => &$nested];
    $input = mprEnvelope([mprOpen(), mprRequirement('borrowedValue', 'requestA', 1, 'duration', $value)]);
    $input['support'][0]['message_ref'] = &$messageRef;
    $input['support'][0]['date_raw'] = &$dateRaw;
    $changed = ManagerRequestMemoryProjection::project($input, 1);
    $witness = ManagerRequestMemoryProjection::project($input, 1);
    $changed['requests']['requestA']['requirements']['duration']['value']['quantity'] = 99;
    $changed['requests']['requestA']['requirements']['duration']['value']['nested']['label'] = 'changed-output';
    $changed['requests']['requestA']['open_evidence'][0]['message_ref'] = 'changed-output';
    $changed['requests']['requestA']['requirements']['duration']['evidence'][0]['date_raw'] = 'changed-output';
    mprSame('output mutation cannot change borrowed caller scalar', $quantity, 9);
    mprSame('output mutation cannot change borrowed caller array', $nested, ['label' => 'neutral']);
    mprSame('output mutation cannot change borrowed source references', [$messageRef, $dateRaw], ['message-borrowed', '2031-04-05 raw time']);
    mprSame('input retains its borrowed value after output mutation', $input['events'][1]['value']['quantity'], 9);
    $quantity = 7;
    $nested['label'] = 'changed-caller';
    $messageRef = 'changed-caller';
    $dateRaw = 'changed-caller';
    mprSame('later caller scalar change cannot rewrite old projection', $witness['requests']['requestA']['requirements']['duration']['value']['quantity'], 9);
    mprSame('later caller nested change cannot rewrite old projection', $witness['requests']['requestA']['requirements']['duration']['value']['nested'], ['label' => 'neutral']);
    mprSame('later caller source change cannot rewrite old proof', $witness['requests']['requestA']['open_evidence'][0],
        ['ordinal' => 1, 'message_ref' => 'message-borrowed', 'direction' => 'IN', 'date_raw' => '2031-04-05 raw time']);
});

// Every mutation below is checked at cutoff 1. Malformed future data must fail
// the entire envelope instead of being skipped or returning a partial prefix.
$invalid = [
    'unsupported envelope authority' => static function (array $x): array { $x['approved_company_facts'] = []; return $x; },
    'wrong schema type' => static function (array $x): array { $x['schema_version'] = '1'; return $x; },
    'unsupported schema version' => static function (array $x): array { $x['schema_version'] = 2; return $x; },
    'blank conversation reference' => static function (array $x): array { $x['conversation_ref'] = ''; return $x; },
    'uppercase revision hash' => static function (array $x): array { $x['source_revision'] = str_repeat('A', 64); return $x; },
    'short revision hash' => static function (array $x): array { $x['source_revision'] = 'a'; return $x; },
    'zero source count' => static function (array $x): array { $x['source_record_count'] = 0; return $x; },
    'boolean source count' => static function (array $x): array { $x['source_record_count'] = true; return $x; },
    'floating source count' => static function (array $x): array { $x['source_record_count'] = 20.0; return $x; },
    'support must be a list' => static function (array $x): array { $x['support'] = ['first' => $x['support'][0]]; return $x; },
    'events must be a list' => static function (array $x): array { $x['events'] = ['first' => $x['events'][0]]; return $x; },
    'unknown support metadata' => static function (array $x): array { $x['support'][19]['sender_name'] = 'neutral'; return $x; },
    'duplicate support ordinal' => static function (array $x): array { $x['support'][19]['ordinal'] = 1; return $x; },
    'support beyond source count' => static function (array $x): array { $x['support'][19]['ordinal'] = 21; return $x; },
    'zero support ordinal' => static function (array $x): array { $x['support'][19]['ordinal'] = 0; return $x; },
    'boolean support ordinal' => static function (array $x): array { $x['support'][19]['ordinal'] = true; return $x; },
    'duplicate message reference' => static function (array $x): array { $x['support'][19]['message_ref'] = 'message-1'; return $x; },
    'blank message reference' => static function (array $x): array { $x['support'][19]['message_ref'] = ''; return $x; },
    'unknown source direction' => static function (array $x): array { $x['support'][19]['direction'] = 'CUSTOMER'; return $x; },
    'non-string non-null source date' => static function (array $x): array { $x['support'][19]['date_raw'] = 100; return $x; },
    'duplicate future event ID' => static function (array $x): array { $x['events'][4]['id'] = $x['events'][0]['id']; return $x; },
    'numeric-looking event ID' => static function (array $x): array { $x['events'][4]['id'] = '12'; return $x; },
    'overlong event ID' => static function (array $x): array { $x['events'][4]['id'] = str_repeat('e', 65); return $x; },
    'unknown future event type' => static function (array $x): array { $x['events'][4]['type'] = 'create_task'; return $x; },
    'unknown future event key' => static function (array $x): array { $x['events'][4]['task_id'] = 'taskA'; return $x; },
    'unknown future requirement field' => static function (array $x): array { $x['events'][1]['field'] = 'manager_profile'; return $x; },
    'unknown evidence state' => static function (array $x): array { $x['events'][1]['evidence_state'] = 'verified_company'; return $x; },
    'invalid requirement strength' => static function (array $x): array { $x['events'][1]['strength'] = 'mandatory_guess'; return $x; },
    'hypothetical baseline mutation' => static function (array $x): array { $x['events'][1]['hypothetical'] = true; return $x; },
    'non-boolean hypothetical flag' => static function (array $x): array { $x['events'][1]['hypothetical'] = 1; return $x; },
    'event without earlier request' => static function (array $x): array { $x['events'][1]['request_id'] = 'requestMissing'; return $x; },
    'numeric request key' => static function (array $x): array { $x['events'][0]['request_id'] = '12'; return $x; },
    'duplicate request opening' => static function (array $x): array { $x['events'][1] = mprOpen('requestA', 3, ['id' => 'secondOpen']); return $x; },
    'self-related request' => static function (array $x): array { $x['events'][0]['related_request_id'] = 'requestA'; return $x; },
    'future related request' => static function (array $x): array { $x['events'][0]['related_request_id'] = 'requestLater'; $x['events'][] = mprOpen('requestLater', 7); return $x; },
    'unsupported relationship' => static function (array $x): array { $x['events'][0]['relationship'] = 'merge_everything'; return $x; },
    'empty evidence' => static function (array $x): array { $x['events'][4]['evidence_ordinals'] = []; return $x; },
    'duplicate evidence' => static function (array $x): array { $x['events'][4]['evidence_ordinals'] = [6, 6]; return $x; },
    'unknown evidence reference' => static function (array $x): array { $x['events'][4]['evidence_ordinals'] = [21]; return $x; },
    'non-integer evidence reference' => static function (array $x): array { $x['events'][4]['evidence_ordinals'] = ['6']; return $x; },
    'non-list evidence' => static function (array $x): array { $x['events'][4]['evidence_ordinals'] = ['source' => 6]; return $x; },
    'future source order inversion' => static function (array $x): array { $x['events'][4]['evidence_ordinals'] = [4]; return $x; },
    'empty offer map' => static function (array $x): array { $x['events'][2]['fields'] = []; return $x; },
    'list instead of offer map' => static function (array $x): array { $x['events'][2]['fields'] = [12, 240]; return $x; },
    'numeric offer field key' => static function (array $x): array { $x['events'][2]['fields'] = [12 => 'not-a-field']; return $x; },
    'numeric offer ID' => static function (array $x): array { $x['events'][2]['offer_id'] = '12'; return $x; },
    'reused offer version' => static function (array $x): array { $x['events'][4] = mprOffer('offerA', 'requestA', 6, ['hotel_nights' => 10]); $x['events'][4]['id'] = 'offerA-repeat'; return $x; },
    'unknown acceptance scope' => static function (array $x): array { $x['events'][3]['scope'] = 'whole_tour'; return $x; },
    'acceptance from OUT evidence' => static function (array $x): array { $x['events'][3]['evidence_ordinals'] = [4]; return $x; },
    'acceptance mixing IN and OUT evidence' => static function (array $x): array { $x['events'][3]['evidence_ordinals'] = [4, 5]; return $x; },
    'acceptance references missing offer' => static function (array $x): array { $x['events'][3]['offer_id'] = 'offerMissing'; return $x; },
    'acceptance of absent offer field' => static function (array $x): array { $x['events'][3]['accepted_fields'] = ['airport']; return $x; },
    'acceptance with empty field list' => static function (array $x): array { $x['events'][3]['accepted_fields'] = []; return $x; },
    'acceptance with repeated field' => static function (array $x): array { $x['events'][3]['accepted_fields'] = ['hotel_nights', 'hotel_nights']; return $x; },
    'format acceptance with offer binding' => static function (array $x): array { $x['events'][3] = ['id' => 'badFormat', 'type' => 'accept_scope', 'request_id' => 'requestA', 'evidence_ordinals' => [5], 'scope' => 'service_format', 'offer_id' => 'offerA']; return $x; },
    'format acceptance with even empty accepted_fields key' => static function (array $x): array { $x['events'][3] = ['id' => 'badFormat', 'type' => 'accept_scope', 'request_id' => 'requestA', 'evidence_ordinals' => [5], 'scope' => 'service_format', 'accepted_fields' => []]; return $x; },
    'unsupported lifecycle event' => static function (array $x): array { $x['events'][4]['event'] = 'refund_verified'; return $x; },
    'empty operation identifier' => static function (array $x): array { $x['events'][4]['operation_id'] = ''; return $x; },
    'object in future value' => static function (array $x): array { $x['events'][1]['value'] = new stdClass(); return $x; },
    'infinite future number' => static function (array $x): array { $x['events'][1]['value'] = INF; return $x; },
    'NaN future number' => static function (array $x): array { $x['events'][1]['value'] = NAN; return $x; },
    'invalid UTF8 in future payload' => static function (array $x): array { $x['events'][1]['value'] = "\xC3\x28"; return $x; },
    'over-limit Unicode string' => static function (array $x): array { $x['events'][1]['value'] = str_repeat('Ж', 4001); return $x; },
    'customer subfield lacks evidence' => static function (array $x): array { $x['events'][1]['subfield_provenance'] = ['amount' => ['kind' => 'customer_statement']]; return $x; },
    'present null provenance is not an optional JSON map' => static function (array $x): array { $x['events'][1]['subfield_provenance'] = null; return $x; },
    'subfield evidence outside event proof' => static function (array $x): array { $x['events'][1]['subfield_provenance'] = ['amount' => ['kind' => 'customer_statement', 'evidence_ordinals' => [1]]]; return $x; },
    'owner default lacks policy revision' => static function (array $x): array { $x['events'][1]['subfield_provenance'] = ['basis' => ['kind' => 'owner_default', 'source' => 'neutral-policy']]; return $x; },
    'owner default has blank source' => static function (array $x): array { $x['events'][1]['subfield_provenance'] = ['basis' => ['kind' => 'owner_default', 'source' => '', 'revision' => 'one']]; return $x; },
    'customer-stated value cannot relabel owner default' => static function (array $x): array { $x['events'][1]['subfield_provenance'] = ['basis' => ['kind' => 'owner_default', 'source' => 'neutral-policy', 'revision' => 'one']]; return $x; },
    'unknown provenance kind' => static function (array $x): array { $x['events'][1]['subfield_provenance'] = ['basis' => ['kind' => 'approved_by_model']]; return $x; },
    'unknown provenance key' => static function (array $x): array { $x['events'][1]['subfield_provenance'] = ['basis' => ['kind' => 'unknown', 'approved' => true]]; return $x; },
    'mixed state missing owner default' => static function (array $x): array { $x['events'][1]['evidence_state'] = 'mixed_customer_and_owner_default'; $x['events'][1]['subfield_provenance'] = ['amount' => ['kind' => 'customer_statement', 'evidence_ordinals' => [3]]]; return $x; },
    'mixed state missing customer statement' => static function (array $x): array { $x['events'][1]['evidence_state'] = 'mixed_customer_and_owner_default'; $x['events'][1]['subfield_provenance'] = ['basis' => ['kind' => 'owner_default', 'source' => 'neutral-policy', 'revision' => 'one']]; return $x; },
];
foreach ($invalid as $name => $change) {
    $input = $change(mprIntegrityFixture());
    mprRejects('full envelope rejects '.$name.' before its cutoff', static fn() => ManagerRequestMemoryProjection::project($input, 1));
}
foreach (array_keys(mprEnvelope()) as $key) {
    $input = mprIntegrityFixture();
    unset($input[$key]);
    mprRejects('required envelope key '.$key, static fn() => ManagerRequestMemoryProjection::project($input, 1));
}
foreach (['id', 'type', 'request_id', 'evidence_ordinals'] as $key) {
    $input = mprIntegrityFixture();
    unset($input['events'][4][$key]);
    mprRejects('future event requires '.$key, static fn() => ManagerRequestMemoryProjection::project($input, 1));
}
foreach ([1 => ['field', 'value', 'evidence_state'], 2 => ['offer_id', 'fields'],
    3 => ['scope', 'offer_id', 'accepted_fields'], 4 => ['event']] as $eventIndex => $keys) {
    foreach ($keys as $key) {
        $input = mprIntegrityFixture();
        unset($input['events'][$eventIndex][$key]);
        mprRejects('future '.$eventIndex.' requires typed key '.$key, static fn() => ManagerRequestMemoryProjection::project($input, 1));
    }
}
foreach (['ordinal', 'message_ref', 'direction', 'date_raw'] as $key) {
    $input = mprIntegrityFixture();
    unset($input['support'][19][$key]);
    mprRejects('future support requires '.$key, static fn() => ManagerRequestMemoryProjection::project($input, 1));
}
foreach ([0, -1, 21, true, 1.0, '1'] as $cutoff) {
    mprRejects('invalid cutoff '.var_export($cutoff, true), static fn() => ManagerRequestMemoryProjection::project(mprIntegrityFixture(), $cutoff), !is_int($cutoff));
}

mprCase('all thirteen request groups are bounded evidence containers', static function (): void {
    $groups = ['product_mode', 'trip_and_revision', 'party_actual', 'room_plan', 'destination_constraints',
        'travel_dates', 'duration', 'departure_and_connections', 'flight_requirements', 'budget',
        'meal_and_room_features', 'hard_needs_and_eligibility', 'open_questions_and_outcome'];
    $events = [mprOpen()];
    foreach ($groups as $index => $group) {
        $events[] = mprRequirement('group-'.$index, 'requestA', 1, $group, null);
    }
    $input = mprEnvelope($events);
    $requirements = ManagerRequestMemoryProjection::project($input, 1)['requests']['requestA']['requirements'];
    $actual = array_keys($requirements);
    sort($actual);
    sort($groups);
    mprSame('supported requirement groups are neither dropped nor renamed', $actual, $groups);
    mprSame('explicit null value remains evidence of an unknown value', $requirements['product_mode']['value'], null);
    $empty = mprEnvelope();
    $empty['support'] = [];
    $projected = ManagerRequestMemoryProjection::project($empty, 1);
    mprSame('empty explicit event list invents no request', $projected['requests'], []);
    mprSame('empty event list invents no applied evidence', $projected['applied_event_ids'], []);
});

mprCase('documented size boundaries accept complete evidence', static function (): void {
    $unicode = str_repeat('Ж', 4000);
    $input = mprEnvelope([mprOpen(), mprRequirement('unicodeValue', 'requestA', 1, 'open_questions_and_outcome', ['raw' => $unicode])]);
    mprSame('four thousand Unicode characters are not four thousand bytes', ManagerRequestMemoryProjection::project($input, 1)['requests']['requestA']['requirements']['open_questions_and_outcome']['value']['raw'], $unicode);
    $input = mprEnvelope([mprOpen()], 1200);
    mprSame('support item cap is inclusive', count(ManagerRequestMemoryProjection::project($input, 1200)['requests']), 1);
    $events = [mprOpen()];
    for ($n = 1; $n <= 999; $n++) {
        $events[] = mprRequirement('revision-'.$n, 'requestA', 1, 'budget', $n);
    }
    $input = mprEnvelope($events, 1);
    $result = ManagerRequestMemoryProjection::project($input, 1);
    mprSame('one thousand events are processed without truncation', count($result['applied_event_ids']), 1000);
    mprSame('last permitted event affects the value', $result['requests']['requestA']['requirements']['budget']['value'], 999);
    $nested = 'leaf';
    for ($n = 0; $n < 9; $n++) { $nested = ['item' => $nested]; }
    $input = mprEnvelope([mprOpen(), mprRequirement('depthBoundary', 'requestA', 1, 'open_questions_and_outcome', $nested)]);
    mprSame('twelve JSON container levels are permitted', ManagerRequestMemoryProjection::project($input, 1)['requests']['requestA']['requirements']['open_questions_and_outcome']['value'], $nested);
});

$tooManySupport = mprEnvelope([mprOpen()], 1201);
mprRejects('support cap rejects without silently dropping messages', static fn() => ManagerRequestMemoryProjection::project($tooManySupport, 1));
$tooManyEvents = [mprOpen()];
for ($n = 1; $n <= 1000; $n++) { $tooManyEvents[] = mprRequirement('revision-'.$n, 'requestA', 1, 'budget', $n); }
$tooManyEvents = mprEnvelope($tooManyEvents, 1);
mprRejects('event cap rejects without a partial projection', static fn() => ManagerRequestMemoryProjection::project($tooManyEvents, 1));
$oversized = [mprOpen()];
for ($n = 1; $n <= 80; $n++) { $oversized[] = mprRequirement('bulk-'.$n, 'requestA', 1, 'open_questions_and_outcome', str_repeat('x', 4000)); }
$oversized = mprEnvelope($oversized, 1);
mprRejects('encoded input byte cap rejects otherwise bounded strings', static fn() => ManagerRequestMemoryProjection::project($oversized, 1));
$deep = 'leaf';
for ($n = 0; $n < 10; $n++) { $deep = ['item' => $deep]; }
$deep = mprEnvelope([mprOpen(), mprRequirement('tooDeep', 'requestA', 1, 'open_questions_and_outcome', $deep)]);
mprRejects('thirteen JSON container levels are rejected', static fn() => ManagerRequestMemoryProjection::project($deep, 1));
$resource = fopen('php://memory', 'r+');
if ($resource === false) { throw new RuntimeException('Unable to create neutral memory resource fixture'); }
$resourceInput = mprEnvelope([mprOpen(), mprRequirement('resourceValue', 'requestA', 1, 'budget', $resource)]);
mprRejects('resource values are not JSON evidence', static fn() => ManagerRequestMemoryProjection::project($resourceInput, 1));
fclose($resource);
$recursive = [];
$recursive['again'] = &$recursive;
$recursiveInput = mprEnvelope([mprOpen(), mprRequirement('recursiveValue', 'requestA', 1, 'budget', $recursive)]);
mprRejects('cyclic arrays are not bounded JSON evidence', static fn() => ManagerRequestMemoryProjection::project($recursiveInput, 1));
unset($recursiveInput, $recursive);

echo "TOTAL {$mprChecks} | FAIL {$mprFailures}\n";
exit($mprFailures ? 1 : 0);
