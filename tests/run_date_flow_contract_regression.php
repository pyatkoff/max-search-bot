<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$contract = json_decode((string)file_get_contents($root . '/docs/date-flow-contract.json'), true);
$callback = (string)file_get_contents($root . '/actions/callbacks/WizardCallbackAction.php');
$editCallback = (string)file_get_contents($root . '/actions/callbacks/EditCallbackAction.php');
$handler = (string)file_get_contents($root . '/handlers/StateMessageHandler.php');
$aiHandler = (string)file_get_contents($root . '/handlers/AiMessageHandler.php');
$aiPolicy = (string)file_get_contents($root . '/services/AiDateContextService.php');
$dateResolver = (string)file_get_contents($root . '/services/DateContextResolver.php');
$dateParser = (string)file_get_contents($root . '/services/DateParser.php');
$calendar = (string)file_get_contents($root . '/services/CalendarViewModel.php');
$view = (string)file_get_contents($root . '/services/DialogueView.php');
$edit = (string)file_get_contents($root . '/services/EditFlowService.php');
$maxSearch = (string)file_get_contents($root . '/maxsearchclass.php');
$aiContext = (string)file_get_contents($root . '/services/AiSearchContextService.php');
$claim = (string)file_get_contents($root . '/services/ClaimRepository.php');
$handoff = (string)file_get_contents($root . '/services/TourSearchHandoffService.php');
$summary = (string)file_get_contents($root . '/services/SearchDateSummary.php');
$tripState = (string)file_get_contents($root . '/services/TripStateService.php');
$failed = 0;

function dateContractCheck(string $name, bool $ok): void
{
    global $failed;
    if ($ok) { echo "PASS  {$name}\n"; return; }
    echo "FAIL  {$name}\n";
    $failed++;
}

dateContractCheck('contract has stable read-only schema', is_array($contract) && ($contract['schema_version'] ?? null) === 1 && ($contract['flow'] ?? null) === 'date' && ($contract['scope'] ?? null) === 'read_only_inventory');
dateContractCheck('date storage representation and status are explicit', ($contract['state']['status'] ?? null) === 'statusDate' && ($contract['state']['status_id'] ?? null) === 73 && ($contract['state']['stored_representation'] ?? null) === 'DD.MM.YYYY_string');

dateContractCheck('date selection stays under its dedicated expected-status guard', strpos($callback, "'date_selection'") !== false && strpos($callback, '(int)MaxSearchApi::$statusDate') !== false && strpos($callback, 'STALE_DATE_CALLBACK_SKIPPED') !== false && ($contract['selection_callback']['guard'] ?? null) === 'InteractionGuard::runExpectedStatusCallback');
dateContractCheck('date selection remains a direct unchecked write', strpos($callback, "MaxSearchApi::saveLastValue(\$chatId, MaxSearchApi::\$statusDate, str_replace('pick_date_', '', \$q));") !== false && ($contract['selection_callback']['application'] ?? null) === 'MaxSearchApi::saveLastValue' && ($contract['selection_callback']['application_result_checked'] ?? null) === false);
dateContractCheck('date selection still returns to check or edit check', strpos($callback, "EditFlowService::finishIfNeeded(\$chatId, 'date')") !== false && strpos($callback, 'DialogueView::check($chatId);') !== false && ($contract['selection_callback']['normal_next'] ?? null) === 'check' && ($contract['selection_callback']['edit_next'] ?? null) === 'check');

dateContractCheck('month navigation uses a separate replacement guard', strpos($callback, 'InteractionGuard::runExpectedStatusReplacementCallback(') !== false && strpos($callback, "'month_change'") !== false && preg_match('/10\.0\s*,\s*0\.75\s*,/s', $callback) === 1 && ($contract['month_navigation']['mutates_trip_date_value'] ?? null) === false);
dateContractCheck('month navigation accepts only after two nonempty parts', strpos($callback, "if (count(\$arr) >= 2 && \$arr[0] !== '' && \$arr[1] !== '')") !== false && strpos($callback, '$accept();') !== false && strpos($callback, 'DialogueView::calendar($chatId, $arr[0], $arr[1]);') !== false);
dateContractCheck('month guard reports all suppression modes', strpos($callback, 'STALE_MONTH_CHANGE_CALLBACK_SKIPPED') !== false && strpos($callback, 'DUPLICATE_MONTH_CHANGE_CALLBACK_SKIPPED') !== false && strpos($callback, 'RAPID_MONTH_CHANGE_CALLBACK_SKIPPED') !== false);

dateContractCheck('wizard free text resolves pending then full date context', strpos($handler, 'AiDateHandler::resolvePendingShortDate($chat_id, $text)') !== false && strpos($handler, 'AiDateHandler::rememberMonthFromText($chat_id, $text)') !== false);
dateContractCheck('wizard month-only input renders calendar without a date write', strpos($handler, "if(\$date === '' && !empty(\$resolved['month']) && !empty(\$resolved['year']))") !== false && strpos($handler, 'DialogueView::calendar($chat_id, (int)$resolved[\'month\'], (int)$resolved[\'year\']);') !== false);
dateContractCheck('wizard recognized date remains a direct unchecked write', strpos($handler, 'MaxSearchApi::saveLastValue($chat_id, MaxSearchApi::$statusDate, $date);') !== false && ($contract['wizard_free_text']['recognized_date_application'] ?? null) === 'MaxSearchApi::saveLastValue' && ($contract['wizard_free_text']['application_result_checked'] ?? null) === false && array_key_exists('interaction_guard_scope', $contract['wizard_free_text']) && $contract['wizard_free_text']['interaction_guard_scope'] === null);
dateContractCheck('wizard invalid input keeps the explicit error path', strpos($handler, 'Не получилось распознать дату.') !== false && ($contract['wizard_free_text']['unrecognized_behavior'] ?? null) === 'send_date_example_error_and_keep_current_state');

dateContractCheck('stateless parser validates calendar dates', substr_count($dateParser, 'checkdate(') >= 3 && strpos($dateParser, "sprintf('%02d.%02d.%04d'") !== false);
dateContractCheck('stateful resolver owns pending month lifecycle', strpos($dateResolver, 'PendingMonthStore::set(') !== false && substr_count($dateResolver, 'PendingMonthStore::clear(') >= 3 && strpos($dateResolver, 'resolvePendingShortDate') !== false);
dateContractCheck('AI month-only guard nulls exact date', strpos($aiPolicy, "\$params['date'] = null;") !== false && strpos($aiPolicy, "'month_only' => !empty(\$resolved['month']) && empty(\$resolved['date'])") !== false);
dateContractCheck('AI date paths apply through canonical parameter boundary', substr_count($aiHandler, 'NeedApplicationService::applyParameters(') >= 3 && strpos($aiHandler, "['date'=>\$shortDateValue]") !== false && strpos($aiHandler, 'AiDateContextService::applyAiGuard(') !== false);
dateContractCheck('AI normalization requires canonical future date', strpos($aiContext, "preg_match('/^\\d{2}\\.\\d{2}\\.\\d{4}$/', \$date)") !== false && strpos($maxSearch, 'NativeDateService::isTodayOrFuture((string)$date)') !== false && strpos($maxSearch, 'static::upsertStatusValue($chatID,$storageMap[$field],$value)') !== false);
dateContractCheck('direct date helper retains auto-insert fallback', strpos($maxSearch, 'in_array($status,[static::$statusAge,static::$statusDate],true)&&static::getLastValue($chatID,$status)===false') !== false && ($contract['selection_callback']['application_semantics'] ?? null) === 'direct_write_with_statusDate_auto_insert_fallback_when_no_current_session_value_row_exists');

dateContractCheck('calendar exposes only current-or-future dates and separate navigation', strpos($calendar, '$firstSelectable =') !== false && strpos($calendar, "'payload'=>'pick_date_'.\$cursor->format('d.m.Y')") !== false && strpos($calendar, "'callback_data'=>'month_change_'.(string)\$model['previous']") !== false && strpos($calendar, "'callback_data'=>'month_change_'.(string)\$model['next']") !== false && strpos($calendar, "'callback_data'=>'back_nights'") !== false);
dateContractCheck('calendar status is appended only after successful send', strpos($view, 'CalendarViewModel::build($month, $year)') !== false && strpos($view, 'if ($ok) MaxSearchApi::setStatus($chatId,$status);') !== false && ($contract['calendar']['calendar_send_success_status'] ?? null) === 'statusDate');
dateContractCheck('date edit captures snapshot and excludes date from restore', strpos($editCallback, "EditFlowService::begin(\$chatId, 'date')") !== false && strpos($edit, "case 'date': return [MaxSearchApi::\$statusDate];") !== false && !empty($contract['edit_and_back']['edit_date']['snapshot_before_edit']));

dateContractCheck('claim preserves stored date verbatim', strpos($claim, "'UF_DATE_DEPART' => !empty(\$savedData[\$statusMap['date']])") !== false && ($contract['downstream']['claim_field'] ?? null) === 'UF_DATE_DEPART');
dateContractCheck('search projects one normalized date to both parameters', substr_count($handoff, "'dateFrom' => \$date") === 2 && substr_count($handoff, "'dateTo' => \$date") === 2 && strpos($handoff, 'private static function dateValue($value): string') !== false);
dateContractCheck('summary owns the clamped display window', strpos($summary, "\$from = \$date->modify('-3 days');") !== false && strpos($summary, 'if ($from < $today) $from = $today;') !== false && strpos($summary, "\$to = \$date->modify('+3 days');") !== false);
dateContractCheck('trip state projects one date plus month and flexibility', strpos($tripState, "'dates'=>['from'=>\$date,'to'=>\$date,'month'=>self::monthFromDate(\$date),'flexible_days'=>\$date ? 3 : 0]") !== false);

$protected = (array)($contract['protected_non_goals'] ?? []);
dateContractCheck('inventory does not authorize a runtime migration', ($contract['migration_readiness']['runtime_migration_allowed'] ?? null) === false && count((array)($contract['migration_readiness']['required_before_runtime'] ?? [])) === 4);
dateContractCheck('runtime payload parser calendar search and protected mechanisms stay frozen', in_array('runtime behavior changes', $protected, true) && in_array('callback payload changes', $protected, true) && in_array('date parsing changes', $protected, true) && in_array('calendar UX changes', $protected, true) && in_array('URL or Tourvisor projection changes', $protected, true) && in_array('Yandex Metrica or goal changes', $protected, true) && in_array('lead delivery changes', $protected, true) && in_array('manager shift or routing changes', $protected, true) && in_array('AI log boundary changes', $protected, true) && in_array('deploy provenance changes', $protected, true));

echo "\n--------------------------------\n";
echo $failed === 0 ? "DATE FLOW CONTRACT: OK\n" : "DATE FLOW CONTRACT: FAIL ({$failed})\n";
exit($failed === 0 ? 0 : 1);
