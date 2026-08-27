<?php

declare(strict_types=1);
require_once __DIR__ . '/../services/MealParser.php';
require_once __DIR__ . '/../handlers/AiShortAnswerHandler.php';

$passed = 0;
$failed = 0;
function mnhCheck(string $name, $actual, $expected): void {
    global $passed, $failed;
    if ($actual === $expected) { echo "PASS  {$name}\n"; $passed++; return; }
    echo "FAIL  {$name}\n";
    echo '      expected: '.json_encode($expected, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)."\n";
    echo '      actual:   '.json_encode($actual, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)."\n";
    $failed++;
}

$files = [
    'StateMessageHandler' => __DIR__ . '/../handlers/StateMessageHandler.php',
    'AiShortAnswerHandler' => __DIR__ . '/../handlers/AiShortAnswerHandler.php',
    'DepartureRouteAdviceHandler' => __DIR__ . '/../handlers/DepartureRouteAdviceHandler.php',
];

foreach ($files as $name => $file) {
    $source = (string)file_get_contents($file);
    mnhCheck($name . ' loads IntegrationRegistry', strpos($source, 'IntegrationRegistry.php') !== false, true);
    mnhCheck($name . ' has no direct MaxSend', strpos($source, 'MaxSearchApi::MaxSend(') === false, true);
    mnhCheck($name . ' uses messenger abstraction', strpos($source, 'IntegrationRegistry::messenger()->send(') !== false, true);
}

$aiMessageSource = (string)file_get_contents(__DIR__ . '/../handlers/AiMessageHandler.php');
mnhCheck('AiMessageHandler has no direct MaxSend', strpos($aiMessageSource, 'MaxSearchApi::MaxSend(') === false, true);
mnhCheck('AiMessageHandler has no legacy showCheckButtons completion', strpos($aiMessageSource, 'MaxSearchApi::showCheckButtons(') === false, true);
mnhCheck('AiMessageHandler completes through DialogueView::check', strpos($aiMessageSource, 'DialogueView::check(') !== false, true);

$shortSource = (string)file_get_contents(__DIR__ . '/../handlers/AiShortAnswerHandler.php');
mnhCheck('AiShortAnswerHandler routes deterministic meal/nights through NeedApplicationService', strpos($shortSource, 'NeedApplicationService::resolveAndApply($chat_id, $field, $lower)') !== false, true);
mnhCheck('AiShortAnswerHandler has no direct NightsParser call', strpos($shortSource, 'NightsParser::parse(') === false, true);
mnhCheck('AiShortAnswerHandler has no direct MealParser call', strpos($shortSource, 'MealParser::parse(') === false, true);
mnhCheck('shared NightsParser still accepts week as nights', NightsParser::parse('неделя'), '7');
mnhCheck('AiShortAnswerHandler completes through DialogueView::check', strpos($shortSource, 'DialogueView::check(') !== false, true);
mnhCheck('adult-only clarification means no children', AiShortAnswerHandler::partyClarificationWhileAskingChildren('1 взрослый'), ['adults'=>1,'children'=>0]);
mnhCheck('plural adult-only clarification means no children', AiShortAnswerHandler::partyClarificationWhileAskingChildren('2 взрослых'), ['adults'=>2,'children'=>0]);
mnhCheck('unrelated children answer is not adult clarification', AiShortAnswerHandler::partyClarificationWhileAskingChildren('1 ребенок'), null);
mnhCheck('sentence with extra intent is not silently collapsed', AiShortAnswerHandler::partyClarificationWhileAskingChildren('1 взрослый и ребенок'), null);

mnhCheck('MealParser live phrase Питание не нужно', MealParser::parse('Питание не нужно'), 'any');
mnhCheck('MealParser phrase питание не важно', MealParser::parse('питание не важно'), 'any');
mnhCheck('MealParser breakfast', MealParser::parse('Завтрак'), 'breakfast');
mnhCheck('MealParser all inclusive', MealParser::parse('Всё включено'), 'all_inclusive');
mnhCheck('MealParser live phrase Двух разовое', MealParser::parse('Двух разовое'), 'half_board');
mnhCheck('MealParser compact two-meals phrase', MealParser::parse('двухразовое'), 'half_board');
mnhCheck('MealParser numeric two-meals phrase', MealParser::parse('2 разовое'), 'half_board');
mnhCheck('MealParser live phrase Трёхразовое', MealParser::parse('Трёхразовое'), 'full_board');
mnhCheck('MealParser spaced three-meals phrase', MealParser::parse('трех разовое'), 'full_board');
mnhCheck('MealParser numeric three-meals phrase', MealParser::parse('3 разовое'), 'full_board');
mnhCheck('MealParser does not invent plan from quality preference', MealParser::parse('Вкусное'), null);
mnhCheck('MealParser does not invent plan from delicacies preference', MealParser::parse('Деликатэсы'), null);

$controllerSource = (string)file_get_contents(__DIR__ . '/../services/DialogueController.php');
mnhCheck('DialogueController start uses DialogueView', strpos($controllerSource, 'DialogueView::start($chatId)') !== false, true);
mnhCheck('DialogueController start has no legacy Max showStart', strpos($controllerSource, 'MaxSearchApi::showStart(') === false, true);

$wizardSource = (string)file_get_contents(__DIR__ . '/../actions/callbacks/WizardCallbackAction.php');
mnhCheck('Wizard meal does not call legacy showMealButtons', strpos($wizardSource, 'MaxSearchApi::showMealButtons(') === false, true);
mnhCheck('Wizard nights does not call legacy showNightsButtons', strpos($wizardSource, 'MaxSearchApi::showNightsButtons(') === false, true);
mnhCheck('Wizard meal uses messenger-neutral view', strpos($wizardSource, 'WizardStepView::meal(') !== false, true);
mnhCheck('Wizard nights uses messenger-neutral view', strpos($wizardSource, 'WizardStepView::nights(') !== false, true);
mnhCheck('Wizard edit completion avoids legacy finishEditIfNeeded', strpos($wizardSource, 'MaxSearchApi::finishEditIfNeeded(') === false, true);
mnhCheck('Wizard edit completion uses EditFlowService', strpos($wizardSource, 'EditFlowService::finishIfNeeded(') !== false, true);
mnhCheck('Wizard city selection completes city edit before country step', strpos($wizardSource, "EditFlowService::finishIfNeeded(\$chatId, 'city')") !== false, true);

$stateSource = (string)file_get_contents(__DIR__ . '/../handlers/StateMessageHandler.php');
mnhCheck('State handler edit completion avoids legacy finishEditIfNeeded', strpos($stateSource, 'MaxSearchApi::finishEditIfNeeded(') === false, true);
mnhCheck('State handler edit completion uses EditFlowService', strpos($stateSource, 'EditFlowService::finishIfNeeded(') !== false, true);
mnhCheck('State nights reaches neutral calendar', strpos($stateSource, 'DialogueView::calendar(') !== false, true);

$wizardViewSource = (string)file_get_contents(__DIR__ . '/../services/WizardStepView.php');
mnhCheck('WizardStepView uses active messenger', strpos($wizardViewSource, 'IntegrationRegistry::messenger()->sendWithButtons(') !== false, true);
mnhCheck('WizardStepView has no direct Max transport', strpos($wizardViewSource, 'MaxSearchApi::MaxSend') === false, true);

$editSource = (string)file_get_contents(__DIR__ . '/../actions/callbacks/EditCallbackAction.php');
mnhCheck('Edit menu avoids legacy showEditParamsButtons', strpos($editSource, 'MaxSearchApi::showEditParamsButtons(') === false, true);
mnhCheck('Edit meal avoids legacy showMealButtons', strpos($editSource, 'MaxSearchApi::showMealButtons(') === false, true);
mnhCheck('Edit nights avoids legacy showNightsButtons', strpos($editSource, 'MaxSearchApi::showNightsButtons(') === false, true);
mnhCheck('Edit date avoids legacy showCalendarButtons', strpos($editSource, 'MaxSearchApi::showCalendarButtons(') === false, true);
mnhCheck('Edit menu uses EditParamsView', strpos($editSource, 'EditParamsView::show(') !== false, true);
mnhCheck('Edit date uses DialogueView calendar', strpos($editSource, 'DialogueView::calendar(') !== false, true);

$editParamsSource = (string)file_get_contents(__DIR__ . '/../services/EditParamsView.php');
mnhCheck('EditParamsView uses active messenger', strpos($editParamsSource, 'IntegrationRegistry::messenger()->sendWithButtons(') !== false, true);
mnhCheck('EditParamsView has no direct Max transport', strpos($editParamsSource, 'MaxSearchApi::MaxSend') === false, true);

$editFlowSource = (string)file_get_contents(__DIR__ . '/../services/EditFlowService.php');
mnhCheck('EditFlowService returns through DialogueView check', strpos($editFlowSource, 'DialogueView::check(') !== false, true);
mnhCheck('EditFlowService clears edit mode', strpos($editFlowSource, 'MaxSearchApi::clearEditMode(') !== false, true);

echo "\n--------------------------\n";
echo 'TOTAL ' . ($passed + $failed) . " | PASS {$passed} | FAIL {$failed}\n";
exit($failed > 0 ? 1 : 0);
