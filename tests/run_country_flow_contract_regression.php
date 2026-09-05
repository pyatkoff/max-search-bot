<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$contract = json_decode((string)file_get_contents($root . '/docs/country-flow-contract.json'), true);
$callback = (string)file_get_contents($root . '/actions/callbacks/WizardCallbackAction.php');
$editCallback = (string)file_get_contents($root . '/actions/callbacks/EditCallbackAction.php');
$handler = (string)file_get_contents($root . '/handlers/StateMessageHandler.php');
$directory = (string)file_get_contents($root . '/services/TravelDirectoryRepository.php');
$aiContext = (string)file_get_contents($root . '/services/AiSearchContextService.php');
$localAi = (string)file_get_contents($root . '/services/LocalAiFallbackService.php');
$defaults = (string)file_get_contents($root . '/services/AiBusinessDefaultsService.php');
$hints = (string)file_get_contents($root . '/services/DestinationHintService.php');
$questions = (string)file_get_contents($root . '/services/MissingFieldQuestionService.php');
$view = (string)file_get_contents($root . '/services/DialogueView.php');
$state = (string)file_get_contents($root . '/services/DialogueStateMachine.php');
$edit = (string)file_get_contents($root . '/services/EditFlowService.php');
$claim = (string)file_get_contents($root . '/services/ClaimRepository.php');
$handoff = (string)file_get_contents($root . '/services/TourSearchHandoffService.php');
$failed = 0;

function countryContractCheck(string $name, bool $ok): void
{
    global $failed;
    if ($ok) { echo "PASS  {$name}\n"; return; }
    echo "FAIL  {$name}\n";
    $failed++;
}

$payloadMap = [
    'pick_country_4'=>'4',
    'pick_country_1'=>'1',
    'pick_country_2'=>'2',
    'pick_country_9'=>'9',
    'pick_country_8'=>'8',
    'pick_country_12'=>'12',
];

countryContractCheck('contract has stable read-only schema', is_array($contract) && ($contract['schema_version'] ?? null) === 1 && ($contract['flow'] ?? null) === 'country' && ($contract['scope'] ?? null) === 'read_only_inventory');
countryContractCheck('country status and semantic storage are explicit', ($contract['state']['status'] ?? null) === 'statusContryChoose' && ($contract['state']['status_id'] ?? null) === 66 && ($contract['state']['semantic_value'] ?? null) === 'active_country_directory_id');
countryContractCheck('executable value contract owns callback and wizard free text', ($contract['executable_value_contract']['owner'] ?? null) === 'services/CountryValueContract.php' && ($contract['executable_value_contract']['runtime_connected'] ?? null) === 'callback_and_wizard_free_text' && ($contract['executable_value_contract']['directory_id_projection'] ?? null) === 'positive_canonical_integer_or_decimal_string_to_exact_decimal_storage_string' && ($contract['executable_value_contract']['callback_payload_parser'] ?? null) === 'pick_country_<positive_canonical_id>' && ($contract['executable_value_contract']['covered_popular_ids'] ?? null) === [1, 2, 4, 8, 9, 12] && ($contract['executable_value_contract']['covered_additional_id'] ?? null) === 347);
countryContractCheck('popular callback payloads preserve current ids', ($contract['callback']['payload_to_storage'] ?? null) === $payloadMap);
foreach ($payloadMap as $payload => $value) {
    countryContractCheck("view exposes {$payload}", strpos($view, "'{$payload}'") !== false);
}
countryContractCheck('callback remains guarded for the country state', strpos($callback, "InteractionGuard::synchronized(\$chatId, 'wizard.forward'") !== false && strpos($state, "if (strpos(\$payload, 'pick_country_') === 0) return 'country';") !== false);
countryContractCheck('callback uses value contract and checked update-only application', strpos($callback, 'CountryValueContract::fromCallbackPayload($q)') !== false && strpos($callback, 'ExistingWizardStepApplicationService::apply(') !== false && strpos($callback, "if (EditFlowService::finishIfNeeded(\$chatId, 'country'))") !== false && ($contract['callback']['payload_projection'] ?? null) === 'CountryValueContract::fromCallbackPayload' && !empty($contract['callback']['application_result_is_checked']) && ($contract['callback']['missing_or_pre_start_step_current_behavior'] ?? null) === 'consumed_without_insert_mutation_or_progression');
countryContractCheck('manual country stays same-state and does not write', strpos($callback, "if (\$q === 'pick_country_other') return DialogueView::manualCountry(\$chatId);") !== false && strpos($view, "ButtonFactory::back('back_pick_country')") !== false && ($contract['callback']['manual_payload_mutates_value'] ?? null) === false && ($contract['manual_country']['back_mutates_trip_value'] ?? null) === false);

countryContractCheck('free text uses exact active directory lookup', strpos($handler, 'MaxSearchApi::getCountryByName($country)') !== false && strpos($directory, 'FROM catalog_countries WHERE name = :name AND is_active = 1') !== false && strpos($directory, "return ['NAME' => \$row['name'], 'ID' => \$row['id']];") !== false);
countryContractCheck('free text uses value contract and checked update-only application', strpos($handler, 'CountryValueContract::fromDirectoryId($countryRes["ID"] ?? null)') !== false && strpos($handler, 'ExistingWizardStepApplicationService::apply(') !== false && !empty($contract['free_text']['application_result_is_checked']) && ($contract['free_text']['directory_id_projection'] ?? null) === 'CountryValueContract::fromDirectoryId' && ($contract['free_text']['missing_or_pre_start_step_current_behavior'] ?? null) === 'fails_closed_without_insert_mutation_or_progression');
countryContractCheck('free text preserves adults progression and edit return', strpos($handler, "EditFlowService::finishIfNeeded(\$chat_id,'country')") !== false && strpos($handler, 'MaxSearchApi::showAdultsButtons($chat_id)') !== false);
countryContractCheck('short unknown and rich request keep distinct owners', strpos($handler, 'elseif(self::shouldRouteFreeTextToAi($country))') !== false && strpos($handler, 'self::routeFreeTextToAi($message,$chat_id)') !== false && strpos($handler, 'Не нашла это направление в поиске') !== false);

countryContractCheck('AI aliases preserve the six popular country ids', strpos($aiContext, "'турция'=>4") !== false && strpos($aiContext, "'египет'=>1") !== false && strpos($aiContext, "'таиланд'=>2") !== false && strpos($aiContext, "'оаэ'=>9") !== false && strpos($aiContext, "'мальдивы'=>8") !== false && strpos($aiContext, "'шри-ланка'=>12") !== false);
countryContractCheck('AI directory fallback and integer projection remain canonical', strpos($aiContext, '$countryId = $resolveCountry($countryName);') !== false && strpos($aiContext, "if (\$countryId) \$out['country'] = (int)\$countryId;") !== false);
countryContractCheck('local country parser remains a canonical-name stem map', strpos($localAi, "'турц'=>'Турция'") !== false && strpos($localAi, "'егип'=>'Египет'") !== false && strpos($localAi, "'оаэ'=>'ОАЭ'") !== false && strpos($localAi, "\$params['country'] = \$name;") !== false);
countryContractCheck('resort hints and Turkey/Egypt defaults keep separate owners', strpos($hints, 'public static function seedCountry') !== false && strpos($defaults, 'DestinationHintService::seedCountry') !== false && strpos($defaults, "['турция', 'египет']") !== false && strpos($defaults, "\$p['meal'] = 'all_inclusive';") !== false && strpos($defaults, "\$p['stars'] = 4;") !== false);
countryContractCheck('country question wording keeps explicit policy option', strpos($questions, "'В какую страну хотите поехать?'") !== false && strpos($questions, "'Куда хотите поехать?'") !== false && ($contract['ai']['implicit_country_default'] ?? null) === false);

countryContractCheck('country edit captures snapshot and returns through country mode', strpos($editCallback, "'edit_country'=>['country','showCountryButtons']") !== false && strpos($edit, "case 'country': return [MaxSearchApi::\$statusContryChoose];") !== false && ($contract['edit_and_back']['edit_country']['successful_selection_return'] ?? null) === 'check');
countryContractCheck('back paths do not own a country value mutation', empty($contract['edit_and_back']['back_pick_country']['mutates_trip_value']) && empty($contract['edit_and_back']['back_adults']['mutates_trip_value']) && strpos($callback, "\$q === 'back_pick_country'") !== false && strpos($callback, "\$q === 'back_adults'") !== false);
countryContractCheck('claim preserves country and search emits integer country', strpos($claim, "'UF_COUNTRY' => !empty(\$savedData[\$statusMap['country']])") !== false && strpos($handoff, "'country' => (int)(\$claim['UF_COUNTRY'] ?? 0)") !== false && strpos($handoff, "'country' => (int)(\$savedData[\$statusMap['country']] ?? 0)") !== false);

$protected = (array)($contract['protected_non_goals'] ?? []);
countryContractCheck('country callback and wizard free-text migration is complete', !empty($contract['migration_readiness']['runtime_migration_allowed']) && ($contract['migration_readiness']['reason'] ?? null) === 'callback_and_wizard_free_text_use_the_executable_update_only_contract' && count((array)($contract['migration_readiness']['required_before_runtime'] ?? [])) === 1);
countryContractCheck('payload directory AI URL and protected mechanisms stay frozen', in_array('callback payload changes', $protected, true) && in_array('directory query changes', $protected, true) && in_array('AI normalization or default changes', $protected, true) && in_array('URL or Tourvisor projection changes', $protected, true) && in_array('Yandex Metrica or goal changes', $protected, true) && in_array('lead delivery changes', $protected, true) && in_array('manager shift or routing changes', $protected, true));

echo "\n--------------------------------\n";
echo $failed === 0 ? "COUNTRY FLOW CONTRACT: OK\n" : "COUNTRY FLOW CONTRACT: FAIL ({$failed})\n";
exit($failed === 0 ? 0 : 1);
