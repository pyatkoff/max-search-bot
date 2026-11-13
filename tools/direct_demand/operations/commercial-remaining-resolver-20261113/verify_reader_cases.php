<?php
declare(strict_types=1);
// Pure fixtures only. No configuration, DB connection, HTTP, SSH or post changes.
require __DIR__.'/source/anex-search-mapping-registry.php';
require __DIR__.'/reader_lib.php';
$scope = json_decode(file_get_contents(__DIR__.'/scope.json'), true, 64, JSON_THROW_ON_ERROR);
$passed = 0;
$check = static function (bool $ok, string $why) use (&$passed): void { rrNeed($ok, $why); $passed++; };
$row = static fn(int $anex, int $catalog, array $extra=[]): array => array_replace([
    'anex_hotel_id'=>$anex, 'catalog_hotel_id'=>$catalog, 'existing_catalog_hotel_id'=>$catalog,
    'enabled'=>1, 'scope'=>'preview', 'approval_policy'=>'owner_exact_and_strong_20260908', 'match_class'=>'exact'], $extra);
$mappings = [$row(8520,900001), $row(39875,900002), $row(8589,900003),
    $row(26638,900004), $row(8538,900005), $row(20763,900006,['approval_policy'=>'owner_exact_and_strong_20260908_extra']),
    $row(38120,900007,['scope'=>'production']), $row(39837,900008,['enabled'=>0])];
$decisions = [
    ['anex_hotel_id'=>39875,'decision_status'=>'accepted','catalog_hotel_id'=>900009,'existing_catalog_hotel_id'=>900009],
    ['anex_hotel_id'=>8589,'decision_status'=>'rejected','catalog_hotel_id'=>900003,'existing_catalog_hotel_id'=>900003],
    ['anex_hotel_id'=>26638,'decision_status'=>'accepted','catalog_hotel_id'=>900004,'existing_catalog_hotel_id'=>null],
];
$registry = AnyTourAnexSearchMappingRegistry::fromRows($mappings, $decisions, [['anex_hotel_id'=>8538,'catalog_hotel_id'=>900005]]);
$check($registry->resolve('anex_online',8520,'preview') === 900001, 'fixture_accepted_policy_pair');
$check($registry->resolve('anex_xml',39875,'preview') === 900009, 'fixture_manual_acceptance_precedes_policy');
foreach ([8589,26638,8538,20763,38120,39837] as $id) $check($registry->resolve('anex_online',$id,'preview') === null, 'fixture_blocked_pair_not_admitted');
$check($registry->resolve('anex_online',8520,'production') === null, 'fixture_production_not_enabled');
$check($registry->resolve('tourvisor',8520,'preview') === null, 'fixture_provider_namespace_not_coerced');
$check($registry->resolve('anex_online',900001,'preview') === null, 'fixture_equal_number_not_fallback');
$queries = rrMappingSql($scope['anex_ids']);
foreach ($queries as $label=>[$sql,$params,$limit]) {
    $check(array_slice($params,0,48) === $scope['anex_ids'] && substr_count($sql,'?') === count($params), 'fixture_exact48_sql_parameters');
    $check(str_contains($sql,'anex_hotel_id IN (') && str_ends_with($sql,' LIMIT '.($limit+1)), 'fixture_sql_bounds');
}
$caught = false;
try { rrMappingSql(array_slice($scope['anex_ids'], 1)); } catch (Throwable $e) { $caught = true; }
$check($caught, 'fixture_no_partial_scope');
$caught = false;
try { AnyTourAnexSearchMappingRegistry::fromRows([$row(8520,900001),$row(8520,900002)]); } catch (Throwable $e) { $caught = true; }
$check($caught, 'fixture_ambiguous_registry_closed');
$base = ['text'=>'Hotel name matching is not evidence', 'post_url'=>'https://max.ru/channel_anytour/example',
    'metadata'=>['logical_id'=>'commercial-hotel-319-v1','link'=>['hotel_id'=>319]]];
$plain = rrSnapshot(rrJson($base));
$check($plain['hotel_catalog_id'] === null && count($plain['typed_reference_fields']) === 1
    && !$plain['typed_reference_fields'][0]['accepted_native_field'], 'fixture_logical_or_nested_untyped_id_not_native');
$relation = rrOldRelation($plain, [], 319);
$check($relation['native_relation_proven'] === false && $relation['status'] === 'native_relation_unresolved', 'fixture_button_or_name_not_native');
$base['metadata']['hotel_catalog_id'] = '319';
$typed = rrSnapshot(rrJson($base));
$check(rrOldRelation($typed, [], 319)['native_relation_proven'] === true, 'fixture_current_exact_native_metadata');
$check(rrOldRelation($typed, [], 364)['native_relation_proven'] === false, 'fixture_foreign_native_target_rejected');
$check(rrOldRelation($plain, [['snapshot'=>$typed]], 319)['native_relation_proven'] === false, 'fixture_historical_only_not_current_acceptance');
foreach (['319x','0319',319.0,true,0,-319] as $bad) $check(rrId($bad) === null, 'fixture_no_permissive_numeric_cast');
echo rrJson(['fixture_cases_passed'=>$passed,'db_connections'=>0,'provider_calls'=>0,'external_changes'=>0]), PHP_EOL;
