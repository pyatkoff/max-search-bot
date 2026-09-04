<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$contract = json_decode((string)file_get_contents($root . '/docs/children-ages-flow-contract.json'), true);
$callback = (string)file_get_contents($root . '/actions/callbacks/WizardCallbackAction.php');
$handler = (string)file_get_contents($root . '/handlers/StateMessageHandler.php');
$view = (string)file_get_contents($root . '/services/DialogueView.php');
$state = (string)file_get_contents($root . '/services/DialogueStateMachine.php');
$edit = (string)file_get_contents($root . '/services/EditFlowService.php');
$resolver = (string)file_get_contents($root . '/services/NeedValueResolver.php');
$failed = 0;

function childrenAgesContractCheck(string $name, bool $ok): void
{
    global $failed;
    if ($ok) { echo "PASS  {$name}\n"; return; }
    echo "FAIL  {$name}\n";
    $failed++;
}

$payloadMap = ['child_0'=>'0', 'child_1'=>'1', 'child_2'=>'2', 'child_3'=>'3'];

childrenAgesContractCheck('contract has stable flow schema', is_array($contract) && ($contract['schema_version'] ?? null) === 1 && ($contract['flow'] ?? null) === 'children_and_child_ages');
childrenAgesContractCheck('children payloads preserve exact decimal strings', ($contract['children']['storage_representation'] ?? null) === 'decimal_string' && ($contract['children']['payload_to_storage'] ?? null) === $payloadMap);
childrenAgesContractCheck('children target is update-only and fail-closed', ($contract['children']['target_application'] ?? null) === 'ExistingWizardStepApplicationService::apply' && !empty($contract['children']['target_update_existing_only']) && empty($contract['children']['target_insert_if_missing']) && empty($contract['children']['target_transition_on_failed_apply']));
childrenAgesContractCheck('ages use one comma-space joined status value', ($contract['child_ages']['storage_cardinality'] ?? null) === 'single_status_row' && ($contract['child_ages']['storage_representation'] ?? null) === 'comma_space_joined_decimal_string' && ($contract['child_ages']['storage_examples'] ?? null) === ['6', '3, 7', '0']);
childrenAgesContractCheck('age range and cardinality are explicit', ($contract['child_ages']['accepted_age_min'] ?? null) === 0 && ($contract['child_ages']['accepted_age_max'] ?? null) === 17 && !empty($contract['child_ages']['accepted_count_must_equal_children']));
childrenAgesContractCheck('ages target is update-only and fail-closed', ($contract['child_ages']['target_application'] ?? null) === 'ExistingWizardStepApplicationService::apply' && !empty($contract['child_ages']['target_update_existing_only']) && empty($contract['child_ages']['target_insert_if_missing']) && empty($contract['child_ages']['target_transition_on_failed_apply']));
childrenAgesContractCheck('resolver-to-storage projection remains an explicit blocker', !empty($contract['child_ages']['target_projection_required']) && ($contract['child_ages']['resolver_output_representation'] ?? null) === 'integer_array' && ($contract['child_ages']['application_input_representation'] ?? null) === 'comma_space_joined_decimal_string');
childrenAgesContractCheck('zero-children stale-age policy remains an explicit blocker', empty($contract['unresolved_runtime_semantics']['runtime_migration_allowed']) && str_contains((string)($contract['unresolved_runtime_semantics']['zero_children_existing_age_value'] ?? ''), 'does not clear'));

foreach ($payloadMap as $payload => $value) {
    childrenAgesContractCheck("view exposes {$payload}", strpos($view, "'{$payload}'") !== false);
}
childrenAgesContractCheck('current child callback owns prefix removal and legacy update', strpos($callback, "\$child = str_replace('child_', '', \$q);") !== false && strpos($callback, 'MaxSearchApi::saveLastValue($chatId, MaxSearchApi::$statusChild, $child);') !== false);
childrenAgesContractCheck('zero children skip ages and continue to stars', strpos($callback, 'if ((int)$child === 0)') !== false && strpos($callback, "EditFlowService::finishIfNeeded(\$chatId, 'tourists')") !== false && strpos($callback, 'MaxSearchApi::showStarsButtons($chatId)') !== false);
childrenAgesContractCheck('positive children open age input with exact count', strpos($callback, 'MaxSearchApi::showAgeButtons($chatId, (int)$child)') !== false);
childrenAgesContractCheck('children callback remains under shared forward guard', strpos($callback, "InteractionGuard::synchronized(\$chatId, 'wizard.forward'") !== false && strpos($state, "if (strpos(\$payload, 'child_') === 0) return 'children';") !== false);

childrenAgesContractCheck('current age parser bounds every value to 0 through 17', strpos($handler, 'if($ageItem<0 || $ageItem>17)') !== false);
childrenAgesContractCheck('current age parser requires exact child count', strpos($handler, 'count($ageOut)!=$childCount') !== false);
childrenAgesContractCheck('current age storage is one comma-space string', strpos($handler, '$ageOut = implode(", ",$ageOut);') !== false && strpos($handler, 'MaxSearchApi::saveLastValue($chat_id,MaxSearchApi::$statusAge,$ageOut);') !== false);
childrenAgesContractCheck('valid ages finish tourists edit or continue to stars', strpos($handler, "EditFlowService::finishIfNeeded(\$chat_id,'tourists')") !== false && strpos($handler, 'MaxSearchApi::showStarsButtons($chat_id)') !== false);
childrenAgesContractCheck('deterministic child-age resolver requires child-count context', strpos($resolver, "if (\$field === 'child_ages')") !== false && strpos($resolver, "\$childrenCount = (int)(\$context['children'] ?? 0);") !== false);

childrenAgesContractCheck('state machine owns conditional children progression', strpos($state, "'children' => ['child_ages', 'stars']") !== false && strpos($state, "'child_ages' => ['stars']") !== false);
childrenAgesContractCheck('back transitions preserve children and age structure', strpos($state, "'child_ages' => ['children']") !== false && strpos($state, "'stars' => ['children', 'child_ages']") !== false);
childrenAgesContractCheck('tourists edit owns adults children and ages together', strpos($edit, "case 'tourists': return [MaxSearchApi::\$statusAdults, MaxSearchApi::\$statusChild, MaxSearchApi::\$statusAge];") !== false);

childrenAgesContractCheck('contract-only slice leaves current application results unchecked', empty($contract['children']['current_application_result_is_checked']) && empty($contract['child_ages']['current_application_result_is_checked']));

echo "\n--------------------------\n";
echo $failed === 0 ? "CHILDREN/AGES FLOW CONTRACT: OK\n" : "CHILDREN/AGES FLOW CONTRACT: FAIL ({$failed})\n";
exit($failed === 0 ? 0 : 1);
