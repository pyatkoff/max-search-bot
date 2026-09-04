<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$contract = json_decode((string)file_get_contents($root . '/docs/meal-callback-contract.json'), true);
$action = (string)file_get_contents($root . '/actions/callbacks/WizardCallbackAction.php');
$view = (string)file_get_contents($root . '/services/WizardStepView.php');
$directory = (string)file_get_contents($root . '/services/TravelDirectoryRepository.php');
$normalizer = (string)file_get_contents($root . '/services/AiSearchContextService.php');
$state = (string)file_get_contents($root . '/services/DialogueStateMachine.php');
$failed = 0;

function mealContractCheck(string $name, bool $ok): void
{
    global $failed;
    if ($ok) { echo "PASS  {$name}\n"; return; }
    echo "FAIL  {$name}\n";
    $failed++;
}

$payloadMap = [
    'meal_999'=>'999',
    'meal_7'=>'7',
    'meal_3'=>'3',
    'meal_4'=>'4',
    'meal_5'=>'5',
];
$canonicalMap = [
    '999'=>'any',
    '7'=>'all_inclusive',
    '3'=>'breakfast',
    '4'=>'half_board',
    '5'=>'full_board',
];

mealContractCheck('contract has the stable meal schema', is_array($contract) && ($contract['schema_version'] ?? null) === 1 && ($contract['field'] ?? null) === 'meal');
mealContractCheck('callback payloads preserve exact directory-id strings', ($contract['storage_representation'] ?? null) === 'directory_id_string' && ($contract['payload_to_storage'] ?? null) === $payloadMap);
mealContractCheck('storage ids have an explicit canonical projection', ($contract['storage_to_canonical'] ?? null) === $canonicalMap);
mealContractCheck('migration target is update-only and fail-closed', ($contract['application']['target_method'] ?? null) === 'ExistingWizardStepApplicationService::apply' && !empty($contract['application']['update_existing_only']) && empty($contract['application']['insert_if_missing']) && empty($contract['application']['transition_on_failed_apply']));
mealContractCheck('normal edit and back progression are explicit', ($contract['progression']['normal_next_state'] ?? null) === 'nights' && ($contract['progression']['edit_return_state'] ?? null) === 'check' && ($contract['progression']['back_payload'] ?? null) === 'back_nights' && empty($contract['progression']['back_mutates_meal']));
mealContractCheck('shared interaction guard contract is explicit', ($contract['guard']['scope'] ?? null) === 'wizard.forward' && ($contract['guard']['expected_state'] ?? null) === 'meal' && !empty($contract['guard']['duplicate_and_stale_callbacks_are_consumed']));

foreach ($payloadMap as $payload => $storageValue) {
    mealContractCheck("view exposes {$payload}", strpos($view, "'{$payload}'") !== false);
    mealContractCheck("directory owns storage id {$storageValue}", strpos($directory, "'{$storageValue}'=>") !== false);
}
foreach ($canonicalMap as $storageValue => $canonicalValue) {
    mealContractCheck("normalizer maps {$canonicalValue} to {$storageValue}", strpos($normalizer, "'{$canonicalValue}'=>'{$storageValue}'") !== false);
}

mealContractCheck('current callback owns only prefix removal and update of meal step', strpos($action, "MaxSearchApi::saveLastValue(\$chatId, MaxSearchApi::\$statusMeal, str_replace('meal_', '', \$q))") !== false);
$editPosition = strpos($action, "EditFlowService::finishIfNeeded(\$chatId, 'meal')");
$nightsPosition = strpos($action, 'WizardStepView::nights($chatId)');
mealContractCheck('current callback keeps edit return before nights rendering', $editPosition !== false && $nightsPosition !== false && $editPosition < $nightsPosition);
mealContractCheck('meal forward state remains meal to nights', strpos($state, "'meal' => ['nights']") !== false && strpos($state, "if (strpos(\$payload, 'meal_') === 0) return 'meal';") !== false);
mealContractCheck('back from nights remains presentation-only in the meal branch', strpos($action, "if (\$q === 'back_nights') MaxSearchApi::deletePrevMessage(\$chatId, true);") !== false);

echo "\n--------------------------\n";
echo $failed === 0 ? "MEAL CALLBACK CONTRACT: OK\n" : "MEAL CALLBACK CONTRACT: FAIL ({$failed})\n";
exit($failed === 0 ? 0 : 1);
