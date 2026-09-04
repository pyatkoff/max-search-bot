<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$contract = json_decode((string)file_get_contents($root . '/docs/departure-city-flow-contract.json'), true);
$callback = (string)file_get_contents($root . '/actions/callbacks/WizardCallbackAction.php');
$editCallback = (string)file_get_contents($root . '/actions/callbacks/EditCallbackAction.php');
$handler = (string)file_get_contents($root . '/handlers/StateMessageHandler.php');
$resolver = (string)file_get_contents($root . '/services/DepartureCityResolver.php');
$directory = (string)file_get_contents($root . '/services/TravelDirectoryRepository.php');
$aiContext = (string)file_get_contents($root . '/services/AiSearchContextService.php');
$aiHandler = (string)file_get_contents($root . '/handlers/AiMessageHandler.php');
$view = (string)file_get_contents($root . '/services/DialogueView.php');
$state = (string)file_get_contents($root . '/services/DialogueStateMachine.php');
$edit = (string)file_get_contents($root . '/services/EditFlowService.php');
$claim = (string)file_get_contents($root . '/services/ClaimRepository.php');
$handoff = (string)file_get_contents($root . '/services/TourSearchHandoffService.php');
$failed = 0;

function departureCityContractCheck(string $name, bool $ok): void
{
    global $failed;
    if ($ok) { echo "PASS  {$name}\n"; return; }
    echo "FAIL  {$name}\n";
    $failed++;
}

$payloadMap = [
    'pick_city_1'=>'1',
    'pick_city_5'=>'5',
    'pick_city_10'=>'10',
    'pick_city_12'=>'12',
    'pick_city_99'=>'99',
];

departureCityContractCheck('contract has stable read-only schema', is_array($contract) && ($contract['schema_version'] ?? null) === 1 && ($contract['flow'] ?? null) === 'departure_city' && ($contract['scope'] ?? null) === 'read_only_inventory');
departureCityContractCheck('city status and semantic storage are explicit', ($contract['state']['status'] ?? null) === 'statusCityChoose' && ($contract['state']['status_id'] ?? null) === 65 && ($contract['state']['semantic_value'] ?? null) === 'active_departure_directory_id');
departureCityContractCheck('callback payloads preserve exact ids', ($contract['callback']['payload_to_storage'] ?? null) === $payloadMap);
departureCityContractCheck('callback write result is currently unchecked', ($contract['callback']['application'] ?? null) === 'MaxSearchApi::saveLastValue' && empty($contract['callback']['application_result_is_checked']) && ($contract['callback']['missing_or_pre_start_step_current_behavior'] ?? null) === 'progresses_without_confirming_write');
departureCityContractCheck('free-text write result is currently unchecked', ($contract['free_text']['application'] ?? null) === 'MaxSearchApi::saveLastValue' && empty($contract['free_text']['application_result_is_checked']) && ($contract['free_text']['missing_or_pre_start_step_current_behavior'] ?? null) === 'progresses_without_confirming_write');
departureCityContractCheck('inventory does not authorize runtime migration', empty($contract['migration_readiness']['runtime_migration_allowed']) && ($contract['migration_readiness']['reason'] ?? null) === 'inventory_only' && count((array)($contract['migration_readiness']['required_before_runtime'] ?? [])) === 7);

foreach ($payloadMap as $payload => $value) {
    departureCityContractCheck("view exposes {$payload}", strpos($view, "'{$payload}'") !== false);
}
departureCityContractCheck('callback strips prefix and writes existing city row', strpos($callback, "str_replace('pick_city_', '', \$q)") !== false && strpos($callback, 'MaxSearchApi::saveLastValue($chatId, MaxSearchApi::$statusCityChoose') !== false);
departureCityContractCheck('callback does not yet check city write result', strpos($callback, 'if (!MaxSearchApi::saveLastValue($chatId, MaxSearchApi::$statusCityChoose') === false);
departureCityContractCheck('city callback stays under shared forward guard', strpos($callback, "InteractionGuard::synchronized(\$chatId, 'wizard.forward'") !== false && strpos($state, "if (strpos(\$payload, 'pick_city_') === 0) return 'city';") !== false);
departureCityContractCheck('manual city payload opens same-state view without value write', strpos($callback, "if (\$q === 'pick_city_other')") !== false && strpos($callback, 'MaxSearchApi::showCityOtherButtons($chatId)') !== false && ($contract['manual_city']['state_remains'] ?? null) === 'city');

departureCityContractCheck('free text tries exact directory then field resolver', strpos($handler, 'MaxSearchApi::getCityByName($city)') !== false && strpos($handler, 'DepartureCityResolver::resolveFieldValue($city)') !== false);
departureCityContractCheck('free text stores directory id and advances', strpos($handler, 'MaxSearchApi::saveLastValue($chat_id,MaxSearchApi::$statusCityChoose,$cityRes["ID"])') !== false && strpos($handler, 'MaxSearchApi::showCountryButtons($chat_id)') !== false);
departureCityContractCheck('rich city text switches to AI while short unknown stays error', strpos($handler, 'elseif(self::shouldRouteFreeTextToAi($city))') !== false && strpos($handler, 'self::routeFreeTextToAi($message,$chat_id)') !== false && strpos($handler, 'Не нашла такой город вылета') !== false);
departureCityContractCheck('field resolver is active-directory and ambiguity bounded', strpos($resolver, 'TravelDirectoryRepository::activeDepartures()') !== false && strpos($resolver, "return count(\$matches) === 1 ? reset(\$matches) : false;") !== false && strpos($resolver, "self::textLength(\$inputToken['value']) < 3") !== false);
departureCityContractCheck('directory lookups are active-only and return directory ids', strpos($directory, 'FROM catalog_departures WHERE name = :name AND is_active = 1') !== false && strpos($directory, "return ['NAME' => \$row['name'], 'ID' => \$row['id']];") !== false);

departureCityContractCheck('AI normalizer owns fixed departure aliases including no-flight', strpos($aiContext, "'москва'=>1") !== false && strpos($aiContext, "'санкт-петербург'=>5") !== false && strpos($aiContext, "'казань'=>10") !== false && strpos($aiContext, "'красноярск'=>12") !== false && strpos($aiContext, "'без перелета'=>99") !== false && strpos($aiContext, "'без перелёта'=>99") !== false);
departureCityContractCheck('AI city values are normalized to integer ids', strpos($aiContext, "if (\$cityId) \$out['city'] = (int)\$cityId;") !== false);
departureCityContractCheck('explicit rich departure uses resolver and canonical application', strpos($aiHandler, 'DepartureCityResolver::resolveAndStore($chat_id, $userText)') !== false && strpos($resolver, "NeedApplicationService::applyParameters(\$chatId, ['city' => \$best['city']])") !== false);
departureCityContractCheck('rich preseed preserves explicit-departure requirement', strpos($aiHandler, "unset(\$richLocalParams['city']);") !== false && !empty($contract['ai']['rich_preseed_suppresses_implicit_moscow_without_explicit_departure']));

departureCityContractCheck('no-flight remains id 99 without a post-storage special branch', ($contract['no_flight']['id'] ?? null) === 99 && ($contract['no_flight']['button_payload'] ?? null) === 'pick_city_99' && empty($contract['no_flight']['special_runtime_branch_after_storage']) && ($contract['no_flight']['search_query']['value'] ?? null) === 99);
departureCityContractCheck('claim preserves city and search emits integer from', strpos($claim, "'UF_CITY' => !empty(\$savedData[\$statusMap['city']])") !== false && strpos($handoff, "'from' => (int)(\$claim['UF_CITY'] ?? 0)") !== false && strpos($handoff, "'from' => (int)(\$savedData[\$statusMap['city']] ?? 0)") !== false);

departureCityContractCheck('city edit captures snapshot and returns through city mode', strpos($editCallback, "'edit_city'=>['city','showCityButtons']") !== false && strpos($edit, "case 'city': return [MaxSearchApi::\$statusCityChoose];") !== false && strpos($callback, "EditFlowService::finishIfNeeded(\$chatId, 'city')") !== false);
departureCityContractCheck('back paths do not own a city value mutation', ($contract['edit_and_back']['back_pick_city']['mutates_trip_value'] ?? null) === false && ($contract['edit_and_back']['back_pick_country']['mutates_trip_value'] ?? null) === false && strpos($callback, "\$q === 'start_search' || \$q === 'back_pick_city'") !== false && strpos($callback, "strpos(\$q, 'pick_city_') === 0 || \$q === 'back_pick_country'") !== false);

$protected = (array)($contract['protected_non_goals'] ?? []);
departureCityContractCheck('payload directory URL and protected mechanisms stay frozen', in_array('callback payload changes', $protected, true) && in_array('directory lookup changes', $protected, true) && in_array('URL or Tourvisor value changes', $protected, true) && in_array('Yandex Metrica or goal changes', $protected, true) && in_array('lead delivery changes', $protected, true) && in_array('manager shift or routing changes', $protected, true));

echo "\n--------------------------------\n";
echo $failed === 0 ? "DEPARTURE CITY FLOW CONTRACT: OK\n" : "DEPARTURE CITY FLOW CONTRACT: FAIL ({$failed})\n";
exit($failed === 0 ? 0 : 1);
