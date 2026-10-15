<?php
declare(strict_types=1);

require_once __DIR__ . '/../services/MaxSubscriptionAttributionPolicy.php';

$checks = 0;
function check($condition, string $label): void {
    global $checks;
    $checks++;
    if (!$condition) throw new RuntimeException('FAILED: ' . $label);
}
function rejects(callable $call, string $label): void {
    $rejected = false;
    try { $call(); } catch (InvalidArgumentException $expected) { $rejected = true; }
    check($rejected, $label);
}
function candidate(string $recordId, string $clientId, array $overrides = []): array {
    return array_replace([
        'record_id' => $recordId, 'external_user_id' => '234567891234567891',
        'effective_counter_id' => '98615635', 'platform' => 'max',
        'client_id' => $clientId, 'observed_at_unix' => 1792054800,
    ], $overrides);
}
function select(array $rows): ?array {
    return MaxSubscriptionAttributionPolicy::selectLatestClientId($rows, '234567891234567891', '98615635');
}

check(select([]) === null, 'empty input');
$rows = [candidate('7', '11111111111111111111'), candidate('8', '22222222222222222222'),
    candidate('9', ''), candidate('10', '33333333333333333333', ['external_user_id' => '999']),
    candidate('11', '33333333333333333333', ['effective_counter_id' => '51573986']),
    candidate('12', '33333333333333333333', ['platform' => 'telegram'])];
check(select($rows)['record_id'] === '8', 'latest valid CID excludes empty and wrong scope');
check(select(array_reverse($rows)) === select($rows), 'input ordering does not change result');

$beforeEvent = candidate('9', '11111111111111111111', ['observed_at_unix' => 1792050000]);
$afterEvent = candidate('10', '22222222222222222222', ['observed_at_unix' => 1792060000]);
$eventUnix = 1792055000;
check(select([$beforeEvent, $afterEvent])['client_id'] === '22222222222222222222', 'later known touch is permitted');
check(select([candidate('11', '33333333333333333333', ['observed_at_unix' => 1792040000]), $afterEvent])['record_id'] === '11', 'append ID wins over unreliable observed clock');
check(select([candidate('9', '11111111111111111111'), candidate('10', '22222222222222222222')])['record_id'] === '10', 'numeric append ordering is not lexicographic');
check(select([candidate('9223372036854775808', '11111111111111111111'), candidate('18446744073709551615', '22222222222222222222')])['record_id'] === '18446744073709551615', 'record IDs larger than signed integer compare exactly');
check(select([candidate('9999999999999999998', '11111111111111111111'), candidate('9999999999999999999', '22222222222222222222')])['record_id'] === '9999999999999999999', 'same-length large IDs retain last digit');
check(select([candidate('13', '33333333333333333333', ['observed_at_unix' => null])])['record_id'] === '13', 'unknown observation time need not block known append order');
$invalidClock = [candidate('13', '11111111111111111111'), candidate('14', '33333333333333333333', ['observed_at_unix' => 'unverified clock'])];
check(select($invalidClock)['record_id'] === '14' && select($invalidClock)['observed_at_unix'] === null, 'invalid optional observation time never discards latest valid CID');
check(select(array_reverse($invalidClock)) === select($invalidClock), 'invalid optional clock selection is order independent');
check(select([candidate('13', '33333333333333333333'), candidate('13', '33333333333333333333')])['record_id'] === '13', 'identical duplicate is harmless');
$metadataConflict = [candidate('13', '33333333333333333333'), candidate('13', '33333333333333333333', ['observed_at_unix' => 1792054900])];
check(select($metadataConflict)['client_id'] === '33333333333333333333' && select($metadataConflict)['observed_at_unix'] === null, 'same ClientID with conflicting optional clock retains identity and nulls clock');
check(select($metadataConflict) === select(array_reverse($metadataConflict)), 'metadata conflict normalization is order independent');
rejects(function (): void { select([candidate('13', '11111111111111111111'), candidate('13', '22222222222222222222')]); }, 'conflicting duplicate IDs fail closed');
$olderConflict = [candidate('13', '11111111111111111111'), candidate('13', '22222222222222222222'), candidate('14', '33333333333333333333')];
check(select($olderConflict)['record_id'] === '14' && select(array_reverse($olderConflict))['record_id'] === '14', 'older conflicting records do not make latest selection depend on input order');

foreach ([123, 123.0, '01', '0', '-1', '1e10', ' 123', '123 ', '1.0', null] as $badId) {
    $invalid = candidate('14', '11111111111111111111'); $invalid['client_id'] = $badId;
    check(select([$invalid]) === null, 'ClientID must be a canonical string');
    $invalid = candidate('14', '11111111111111111111'); $invalid['record_id'] = $badId;
    check(select([$invalid]) === null, 'record ID must be a canonical string');
}
rejects(function (): void { MaxSubscriptionAttributionPolicy::selectLatestClientId([], 23456789, '98615635'); }, 'requested user is not coerced from integer');
rejects(function (): void { MaxSubscriptionAttributionPolicy::selectLatestClientId([], '23456789', 98615635.0); }, 'requested counter is not coerced from float');

$counterMap = ['1516' => '98615635', '1538' => '51573986'];
check(MaxSubscriptionAttributionPolicy::resolveEffectiveCounterId('1516', '1538', $counterMap) === '51573986', 'metric source overrides original source');
check(MaxSubscriptionAttributionPolicy::resolveEffectiveCounterId('1516', '', $counterMap) === '98615635', 'empty metric source falls back to source');
check(MaxSubscriptionAttributionPolicy::resolveEffectiveCounterId('1516', null, $counterMap) === '98615635', 'absent metric source falls back to source');
check(MaxSubscriptionAttributionPolicy::resolveEffectiveCounterId('1516', '9999', $counterMap) === null, 'unknown metric override cannot fall back');
check(MaxSubscriptionAttributionPolicy::resolveEffectiveCounterId('1516', 1538, $counterMap) === null, 'numeric metric override cannot be coerced');
check(MaxSubscriptionAttributionPolicy::resolveEffectiveCounterId('1516', '', ['1516' => 98615635]) === null, 'counter mapping must preserve string IDs');

$utc = new DateTimeZone('UTC'); $moscow = new DateTimeZone('Europe/Moscow'); $london = new DateTimeZone('Europe/London');
check(MaxSubscriptionAttributionPolicy::eventTimeUnix('2026-10-15 10:00:00', $utc) === MaxSubscriptionAttributionPolicy::eventTimeUnix('2026-10-15 13:00:00', $moscow), 'Moscow and UTC refer to same event');
check(MaxSubscriptionAttributionPolicy::eventTimeUnix('2026-07-15 12:00:00', $london) === MaxSubscriptionAttributionPolicy::eventTimeUnix('2026-07-15 11:00:00Z', $utc), 'London summer BST offset');
check(MaxSubscriptionAttributionPolicy::eventTimeUnix('2026-01-15 12:00:00', $london) === MaxSubscriptionAttributionPolicy::eventTimeUnix('2026-01-15T12:00:00Z', $utc), 'London winter GMT offset');

foreach (['2026-07-15 11:00:00Z', '2026-01-15 12:00:00Z', '2026-10-25 00:30:00Z', '2026-10-25 01:30:00Z'] as $time) {
    $actual = MaxSubscriptionAttributionPolicy::eventTimeUnix($time, $utc);
    foreach ([$utc, $moscow, $london] as $zone) {
        $formatted = MaxSubscriptionAttributionPolicy::formatEventTime($actual, $zone);
        check(MaxSubscriptionAttributionPolicy::eventTimeUnix($formatted, $zone) === $actual, 'explicit-offset round trip preserves actual instant');
    }
}
$originalEvent = MaxSubscriptionAttributionPolicy::eventTimeUnix('2026-10-15 10:00:00Z', $utc);
select([$beforeEvent]); select([$beforeEvent, $afterEvent]);
check(MaxSubscriptionAttributionPolicy::eventTimeUnix('2026-10-15 10:00:00Z', $utc) === $originalEvent, 'later CID and repeated processing never replace event timestamp');
check(MaxSubscriptionAttributionPolicy::formatEventTime('1792055000', $utc) === MaxSubscriptionAttributionPolicy::formatEventTime($eventUnix, $utc), 'canonical Unix string accepted without float conversion');

rejects(function () use ($london): void { MaxSubscriptionAttributionPolicy::eventTimeUnix('2026-03-29 01:30:00', $london); }, 'London spring gap rejected');
rejects(function () use ($london): void { MaxSubscriptionAttributionPolicy::eventTimeUnix('2026-10-25 01:30:00', $london); }, 'London fall overlap needs offset');
$early = MaxSubscriptionAttributionPolicy::eventTimeUnix('2026-10-25 01:30:00+01:00', $london);
$late = MaxSubscriptionAttributionPolicy::eventTimeUnix('2026-10-25 01:30:00+00:00', $london);
check($late - $early === 3600, 'explicit offsets disambiguate overlap');
rejects(function () use ($london): void { MaxSubscriptionAttributionPolicy::eventTimeUnix('2026-07-15 12:00:00+00:00', $london); }, 'offset contradicting named zone rejected');
foreach (['2026-02-30 10:00:00', '2026-10-15 24:00:00', '2026-10-15 12:60:00', '2026-10-15 12:00:60', '2026-10-15', 'now', '2026-10-15 10:00:00+99:00', '2026-10-15 10:00:00-00:00', "2026-10-15 10:00:00\n", 1792055000, 1792055000.0] as $badTime) {
    rejects(function () use ($badTime, $utc): void { MaxSubscriptionAttributionPolicy::eventTimeUnix($badTime, $utc); }, 'invalid event input rejected');
}
rejects(function () use ($utc): void { MaxSubscriptionAttributionPolicy::formatEventTime(1792055000.0, $utc); }, 'Unix float rejected');
rejects(function () use ($utc): void { MaxSubscriptionAttributionPolicy::formatEventTime('999999999999999999999999999', $utc); }, 'Unix overflow rejected');

echo 'PASS ' . $checks . " MAX attribution policy checks\n";
