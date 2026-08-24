<?php

declare(strict_types=1);

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
mnhCheck('AiShortAnswerHandler accepts week as nights', preg_match('/недел/u', $shortSource) === 1, true);
mnhCheck('AiShortAnswerHandler completes through DialogueView::check', strpos($shortSource, 'DialogueView::check(') !== false, true);

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

$stateSource = (string)file_get_contents(__DIR__ . '/../handlers/StateMessageHandler.php');
mnhCheck('State handler edit completion avoids legacy finishEditIfNeeded', strpos($stateSource, 'MaxSearchApi::finishEditIfNeeded(') === false, true);
mnhCheck('State handler edit completion uses EditFlowService', strpos($stateSource, 'EditFlowService::finishIfNeeded(') !== false, true);
mnhCheck('State nights reaches neutral calendar', strpos($stateSource, 'DialogueView::calendar(') !== false, true);

$wizardViewSource = (string)file_get_contents(__DIR__ . '/../services/WizardStepView.php');
mnhCheck('WizardStepView uses active messenger', strpos($wizardViewSource, 'IntegrationRegistry::messenger()->sendWithButtons(') !== false, true);
mnhCheck('WizardStepView has no direct Max transport', strpos($wizardViewSource, 'MaxSearchApi::MaxSend') === false, true);

$editActionSource = (string)file_get_contents(__DIR__ . '/../actions/callbacks/EditCallbackAction.php');
mnhCheck('Edit menu avoids legacy showEditParamsButtons', strpos($editActionSource, 'MaxSearchApi::showEditParamsButtons(') === false, true);
mnhCheck('Edit meal avoids legacy showMealButtons', strpos($editActionSource, 'MaxSearchApi::showMealButtons(') === false, true);
mnhCheck('Edit nights avoids legacy showNightsButtons', strpos($editActionSource, 'MaxSearchApi::showNightsButtons(') === false, true);
mnhCheck('Edit date avoids legacy showCalendarButtons', strpos($editActionSource, 'MaxSearchApi::showCalendarButtons(') === false, true);
mnhCheck('Edit menu uses EditParamsView', strpos($editActionSource, 'EditParamsView::menu(') !== false, true);
mnhCheck('Edit date uses DialogueView calendar', strpos($editActionSource, 'DialogueView::calendar(') !== false, true);

$editViewSource = (string)file_get_contents(__DIR__ . '/../services/EditParamsView.php');
mnhCheck('EditParamsView uses active messenger', strpos($editViewSource, 'IntegrationRegistry::messenger()->sendWithButtons(') !== false, true);
mnhCheck('EditParamsView has no direct Max transport', strpos($editViewSource, 'MaxSearchApi::MaxSend') === false, true);

$editFlowSource = (string)file_get_contents(__DIR__ . '/../services/EditFlowService.php');
mnhCheck('EditFlowService returns through DialogueView check', strpos($editFlowSource, 'DialogueView::check(') !== false, true);
mnhCheck('EditFlowService clears edit mode', strpos($editFlowSource, "MaxSearchApi::setEditMode($chatId, '')") !== false, true);

$total = $passed + $failed;
echo "\n--------------------------\n";
echo "TOTAL {$total} | PASS {$passed} | FAIL {$failed}\n";
exit($failed > 0 ? 1 : 0);
