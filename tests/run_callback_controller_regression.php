<?php

declare(strict_types=1);

if (!class_exists('MaxSearchApi')) {
    class MaxSearchApi {
        public static $statusCityChoose = 11;
        public static $statusContryChoose = 12;
        public static $statusAdults = 13;
        public static $statusChild = 14;
        public static $statusStars = 15;
        public static $statusMeal = 16;
        public static $statusNights = 17;
        public static $statusCheck = 18;
        public static $statusDate = 19;
        public static string $generationValue = '';
        public static int $currentStatus = 19;
        public static function getLastValue($chatId,$status){ return self::$generationValue; }
        public static function saveLastValue($chatId,$status,$value){ self::$generationValue=(string)$value; }
        public static function getCurentStatus($chatId){ return self::$currentStatus; }
    }
}

require_once __DIR__ . '/../services/CallbackController.php';
require_once __DIR__ . '/../services/InteractionGuard.php';
require_once __DIR__ . '/../services/CallbackGeneration.php';

$passed = 0;
$failed = 0;
function ccCheck(string $name, $actual, $expected): void {
    global $passed, $failed;
    if ($actual === $expected) { echo "PASS  {$name}\n"; $passed++; return; }
    echo "FAIL  {$name}\n";
    echo '      expected: '.json_encode($expected, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)."\n";
    echo '      actual:   '.json_encode($actual, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)."\n";
    $failed++;
}

ccCheck('name first + last', CallbackController::userName([
    'from'=>['first_name'=>'Pavel','last_name'=>'Test','username'=>'pt'],
]), 'Pavel Test');
ccCheck('name username fallback', CallbackController::userName([
    'from'=>['username'=>'tourist'],
]), 'tourist');

$families = [
    'ai_start'=>'ai',
    'start_search'=>'wizard',
    'pick_city_1'=>'wizard',
    'pick_country_4'=>'wizard',
    'pick_date_17.10.2026'=>'wizard',
    'adults_2'=>'wizard',
    'child_0'=>'wizard',
    'star_5'=>'wizard',
    'meal_3'=>'wizard',
    'nights_7_10'=>'wizard',
    'month_change_09.2026'=>'wizard',
    'back_pick_country'=>'wizard',
    'edit_country'=>'edit',
    'manager_request'=>'manager',
    'manager_after_tours'=>'manager',
    'phone_manual'=>'manager',
    'show_tours'=>'tours',
    'tours_checked'=>'tours',
    'tours_found'=>'tours',
    'finish'=>'tours',
    'restart'=>'restart',
    'back_phone'=>'phone',
    'something_new'=>'unknown',
];
foreach ($families as $payload=>$expected) {
    ccCheck('family '.$payload, CallbackController::family($payload), $expected);
}

ccCheck('wizard owns city', WizardCallbackAction::handles('pick_city_1'), true);
ccCheck('wizard owns date', WizardCallbackAction::handles('pick_date_17.10.2026'), true);
ccCheck('wizard excludes back phone', WizardCallbackAction::handles('back_phone'), false);
ccCheck('edit owns edit date', EditCallbackAction::handles('edit_date'), true);
ccCheck('manager owns manual phone', ManagerCallbackAction::handles('phone_manual'), true);
ccCheck('tours owns finish', ToursCallbackAction::handles('finish_from_ai'), true);
ccCheck('manager excludes tours', ManagerCallbackAction::handles('show_tours'), false);

$wizardSource = (string)file_get_contents(__DIR__ . '/../actions/callbacks/WizardCallbackAction.php');
$editSource = (string)file_get_contents(__DIR__ . '/../actions/callbacks/EditCallbackAction.php');
$guardSource = (string)file_get_contents(__DIR__ . '/../services/InteractionGuard.php');
$controllerSource = (string)file_get_contents(__DIR__ . '/../services/CallbackController.php');
ccCheck('wizard loads shared interaction guard', strpos($wizardSource, 'InteractionGuard.php') !== false, true);
ccCheck('edit loads shared interaction guard', strpos($editSource, 'InteractionGuard.php') !== false, true);
ccCheck('shared guard owns interaction lock directory', strpos($guardSource, 'max-search-interaction-locks') !== false, true);
$dateStart=strpos($wizardSource,'private static function handleDateSelection');
$dateEnd=strpos($wizardSource,'public static function expectedStatusForForwardCallback',$dateStart===false?0:$dateStart);
$dateSource=$dateStart!==false&&$dateEnd!==false?substr($wizardSource,$dateStart,$dateEnd-$dateStart):'';
ccCheck('date callback delegates serialization and expected-status safety to shared guard',strpos($dateSource,'InteractionGuard::runExpectedStatusCallback(')!==false,true);
ccCheck('date callback no longer owns fopen or flock',strpos($dateSource,'fopen(')===false&&strpos($dateSource,'flock(')===false,true);
ccCheck('stale date callback expected-status policy lives in shared guard',strpos($guardSource,'function runExpectedStatusCallback(')!==false&&strpos($guardSource,"'stale_state'")!==false&&strpos($guardSource,'MaxSearchApi::getCurentStatus($chatId)')!==false&&strpos($dateSource,'$statusDate')!==false,true);
ccCheck('date stale legacy text log remains for operational continuity',strpos($dateSource,'STALE_DATE_CALLBACK_SKIPPED')!==false,true);
ccCheck('date callback routes through guarded handler', strpos($wizardSource, "strpos(\$q, 'pick_date_') === 0) return self::handleDateSelection") !== false, true);
$monthStart=strpos($wizardSource,'private static function handleMonthChange');
$monthEnd=strpos($wizardSource,'public static function handle(',$monthStart===false?0:$monthStart);
$monthSource=$monthStart!==false&&$monthEnd!==false?substr($wizardSource,$monthStart,$monthEnd-$monthStart):'';
ccCheck('month change routes through guarded handler', strpos($wizardSource, "strpos(\$q, 'month_change_') === 0) return self::handleMonthChange") !== false, true);
ccCheck('month callback delegates serialization status and replacement safety to shared guard',strpos($monthSource,'InteractionGuard::runExpectedStatusReplacementCallback(')!==false,true);
ccCheck('month callback no longer owns fopen flock or timestamp state',strpos($monthSource,'fopen(')===false&&strpos($monthSource,'flock(')===false&&strpos($monthSource,'microtime(')===false,true);
ccCheck('month callback preserves exact duplicate and rapid replacement windows',preg_match('/10\\.0\\s*,\\s*0\\.75\\s*,/s',$monthSource)===1,true);
$monthValidBranch=strpos($monthSource,"if (count(\$arr) >= 2 && \$arr[0] !== '' && \$arr[1] !== '')");
$monthAccept=strpos($monthSource,'$accept();');
$monthCalendar=strpos($monthSource,'DialogueView::calendar(');
ccCheck('month callback accepts marker only inside valid month payload branch',$monthValidBranch!==false&&$monthAccept!==false&&$monthCalendar!==false&&$monthValidBranch<$monthAccept&&$monthAccept<$monthCalendar,true);
ccCheck('stale month change requires active date step', strpos($wizardSource, 'STALE_MONTH_CHANGE_CALLBACK_SKIPPED') !== false, true);
ccCheck('duplicate month change is explicitly suppressed', strpos($wizardSource, 'DUPLICATE_MONTH_CHANGE_CALLBACK_SKIPPED') !== false, true);
ccCheck('rapid different month replacement is explicitly suppressed', strpos($wizardSource, 'RAPID_MONTH_CHANGE_CALLBACK_SKIPPED') !== false, true);
ccCheck('same month callback inside extended live debounce window is duplicate', InteractionGuard::isDuplicate('month_change_09.2026', 100.0, 'month_change_09.2026', 104.0, 10.0), true);
ccCheck('same month callback near end of debounce window is duplicate', InteractionGuard::isDuplicate('month_change_09.2026', 100.0, 'month_change_09.2026', 109.9, 10.0), true);
ccCheck('same month callback after debounce window is allowed', InteractionGuard::isDuplicate('month_change_09.2026', 100.0, 'month_change_09.2026', 110.1, 10.0), false);
ccCheck('different month remains non-duplicate', InteractionGuard::isDuplicate('month_change_09.2026', 100.0, 'month_change_10.2026', 100.1, 10.0), false);
ccCheck('live-style stale keyboard month replacement burst is suppressed', InteractionGuard::isRapidReplacement('month_change_08.2026', 100.0, 'month_change_10.2026', 100.2, 0.75), true);
ccCheck('same payload stays owned by duplicate guard, not rapid replacement', InteractionGuard::isRapidReplacement('month_change_08.2026', 100.0, 'month_change_08.2026', 100.2, 0.75), false);
ccCheck('different month after rendered-keyboard grace window is allowed', InteractionGuard::isRapidReplacement('month_change_08.2026', 100.0, 'month_change_09.2026', 100.8, 0.75), false);

ccCheck('city choice is valid only on city step', WizardCallbackAction::expectedStatusForForwardCallback('pick_city_1'), (int)MaxSearchApi::$statusCityChoose);
ccCheck('country choice is valid only on country step', WizardCallbackAction::expectedStatusForForwardCallback('pick_country_4'), (int)MaxSearchApi::$statusContryChoose);
ccCheck('adult choice is valid only on adults step', WizardCallbackAction::expectedStatusForForwardCallback('adults_2'), (int)MaxSearchApi::$statusAdults);
ccCheck('children choice is valid only on children step', WizardCallbackAction::expectedStatusForForwardCallback('child_0'), (int)MaxSearchApi::$statusChild);
ccCheck('stars choice is valid only on stars step', WizardCallbackAction::expectedStatusForForwardCallback('star_4'), (int)MaxSearchApi::$statusStars);
ccCheck('meal choice is valid only on meal step', WizardCallbackAction::expectedStatusForForwardCallback('meal_7'), (int)MaxSearchApi::$statusMeal);
ccCheck('nights choice is valid only on nights step', WizardCallbackAction::expectedStatusForForwardCallback('nights_9_11'), (int)MaxSearchApi::$statusNights);
ccCheck('date choice remains under dedicated guarded handler', WizardCallbackAction::expectedStatusForForwardCallback('pick_date_17.10.2026'), null);
ccCheck('back navigation is not blocked by forward-step guard', WizardCallbackAction::expectedStatusForForwardCallback('back_nights'), null);
ccCheck('forward wizard callbacks delegate stale-step guard', strpos($wizardSource, 'InteractionGuard::isStaleWizardForward($chatId, $q)') !== false, true);
$forwardLockPos=strpos($wizardSource,"InteractionGuard::synchronized(\$chatId, 'wizard.forward'");
$forwardStalePos=strpos($wizardSource,'self::staleForwardCallback($chatId, $q)', $forwardLockPos===false?0:$forwardLockPos);
$forwardMutationPos=strpos($wizardSource,'self::handleUnlocked($chatId, $q)', $forwardLockPos===false?0:$forwardLockPos);
ccCheck('forward wizard callback check and mutation share one per-chat lock',$forwardLockPos!==false&&$forwardStalePos!==false&&$forwardMutationPos!==false&&$forwardLockPos<$forwardStalePos&&$forwardStalePos<$forwardMutationPos,true);
ccCheck('non-forward callbacks stay outside forward lock',strpos($wizardSource,'return self::handleUnlocked($chatId, $q);')!==false,true);

ccCheck('generic duplicate helper matches same payload', InteractionGuard::isDuplicate('meal_7', 100.0, 'meal_7', 101.0, 2.0), true);
ccCheck('generic duplicate helper allows different payload', InteractionGuard::isDuplicate('meal_7', 100.0, 'meal_5', 101.0, 2.0), false);
ccCheck('generic recent helper suppresses burst', InteractionGuard::isRecent(100.0, 101.5, 2.0), true);
ccCheck('generic recent helper allows later action', InteractionGuard::isRecent(100.0, 102.1, 2.0), false);
ccCheck('lock scope is sanitized', strpos(InteractionGuard::lockPath(123, 'edit menu/test'), 'edit_menu_test.lock') !== false, true);
ccCheck('callback controller reports unknown actions through shared guard', strpos($controllerSource, "InteractionGuard::reportSuppressed(\$chatId, \$q, 'unknown_action'") !== false, true);

$expectedStatusDiagnosticFile=sys_get_temp_dir().'/max-search-expected-status-'.bin2hex(random_bytes(4)).'.log';
DiagnosticLogger::setFile($expectedStatusDiagnosticFile);
$expectedStatusChat=random_int(1000000,9999999);
$expectedStatusScope='date_selection_regression';
$expectedStatusLock=InteractionGuard::lockPath($expectedStatusChat,$expectedStatusScope);
$expectedCalls=0;$staleCalls=0;
try{
    MaxSearchApi::$currentStatus=(int)MaxSearchApi::$statusDate;
    $valid=InteractionGuard::runExpectedStatusCallback($expectedStatusChat,'pick_date_17.10.2026',$expectedStatusScope,(int)MaxSearchApi::$statusDate,function($fp)use(&$expectedCalls):bool{$expectedCalls++;return is_resource($fp);});
    ccCheck('expected-status guard executes callback on current state',$valid,true);
    ccCheck('expected-status guard executes valid mutation once',$expectedCalls,1);

    MaxSearchApi::$currentStatus=(int)MaxSearchApi::$statusCheck;
    $stale=InteractionGuard::runExpectedStatusCallback($expectedStatusChat,'pick_date_17.10.2026',$expectedStatusScope,(int)MaxSearchApi::$statusDate,function()use(&$expectedCalls):bool{$expectedCalls++;return true;},function(int $current,int $expected)use(&$staleCalls):void{if($current!==$expected)$staleCalls++;});
    ccCheck('expected-status guard consumes stale callback',$stale,true);
    ccCheck('expected-status guard blocks stale mutation',$expectedCalls,1);
    ccCheck('expected-status guard invokes stale compatibility hook',$staleCalls,1);
    $lines=is_file($expectedStatusDiagnosticFile)?file($expectedStatusDiagnosticFile,FILE_IGNORE_NEW_LINES|FILE_SKIP_EMPTY_LINES):[];
    $last=$lines?json_decode((string)end($lines),true):null;
    ccCheck('expected-status stale diagnostic reason',$last['data']['reason']??null,'stale_state');
    ccCheck('expected-status stale diagnostic scope',$last['data']['scope']??null,$expectedStatusScope);
    ccCheck('expected-status stale diagnostic payload',$last['data']['payload']??null,'pick_date_17.10.2026');
    ccCheck('expected-status stale diagnostic current status',$last['data']['current_status']??null,(int)MaxSearchApi::$statusCheck);
    ccCheck('expected-status stale diagnostic expected status',$last['data']['expected_status']??null,(int)MaxSearchApi::$statusDate);
}finally{
    MaxSearchApi::$currentStatus=(int)MaxSearchApi::$statusDate;
    @unlink($expectedStatusDiagnosticFile);
    @unlink($expectedStatusLock);
    DiagnosticLogger::setFile('');
}

$replacementDiagnosticFile=sys_get_temp_dir().'/max-search-replacement-'.bin2hex(random_bytes(4)).'.log';
DiagnosticLogger::setFile($replacementDiagnosticFile);
$replacementChat=random_int(1000000,9999999);
$replacementScope='month_change_regression';
$replacementLock=InteractionGuard::lockPath($replacementChat,$replacementScope);
$replacementCalls=0;
try{
    MaxSearchApi::$currentStatus=(int)MaxSearchApi::$statusDate;
    $invalid=InteractionGuard::runExpectedStatusReplacementCallback(
        $replacementChat,'month_change_invalid',$replacementScope,(int)MaxSearchApi::$statusDate,10.0,0.75,
        function(callable $accept)use(&$replacementCalls):bool{$replacementCalls++;return true;}
    );
    ccCheck('replacement guard consumes business-rejected payload',$invalid,true);
    ccCheck('replacement guard invokes business validation',$replacementCalls,1);
    $invalidState=is_file($replacementLock)?json_decode((string)file_get_contents($replacementLock),true):null;
    ccCheck('business-rejected payload does not commit accepted marker',$invalidState,null);

    $accepted=InteractionGuard::runExpectedStatusReplacementCallback(
        $replacementChat,'month_change_invalid',$replacementScope,(int)MaxSearchApi::$statusDate,10.0,0.75,
        function(callable $accept)use(&$replacementCalls):bool{$replacementCalls++;$accept();return true;}
    );
    ccCheck('replacement guard accepts validated payload',$accepted,true);
    ccCheck('unmarked rejected payload remains retryable',$replacementCalls,2);
    $acceptedState=is_file($replacementLock)?json_decode((string)file_get_contents($replacementLock),true):null;
    ccCheck('business-accepted payload commits marker',$acceptedState['payload']??null,'month_change_invalid');
    ccCheck('business-accepted marker records timestamp',isset($acceptedState['at'])&&is_numeric($acceptedState['at']),true);

    $duplicate=InteractionGuard::runExpectedStatusReplacementCallback(
        $replacementChat,'month_change_invalid',$replacementScope,(int)MaxSearchApi::$statusDate,10.0,0.75,
        function(callable $accept)use(&$replacementCalls):bool{$replacementCalls++;$accept();return true;}
    );
    ccCheck('replacement guard consumes exact duplicate',$duplicate,true);
    ccCheck('exact duplicate does not reach business callback',$replacementCalls,2);
    $lines=is_file($replacementDiagnosticFile)?file($replacementDiagnosticFile,FILE_IGNORE_NEW_LINES|FILE_SKIP_EMPTY_LINES):[];
    $last=$lines?json_decode((string)end($lines),true):null;
    ccCheck('replacement duplicate diagnostic reason',$last['data']['reason']??null,'duplicate');
    ccCheck('replacement duplicate diagnostic scope',$last['data']['scope']??null,$replacementScope);

    $rapid=InteractionGuard::runExpectedStatusReplacementCallback(
        $replacementChat,'month_change_10.2026',$replacementScope,(int)MaxSearchApi::$statusDate,10.0,0.75,
        function(callable $accept)use(&$replacementCalls):bool{$replacementCalls++;$accept();return true;}
    );
    ccCheck('replacement guard consumes rapid different payload',$rapid,true);
    ccCheck('rapid replacement does not reach business callback',$replacementCalls,2);
    $lines=is_file($replacementDiagnosticFile)?file($replacementDiagnosticFile,FILE_IGNORE_NEW_LINES|FILE_SKIP_EMPTY_LINES):[];
    $last=$lines?json_decode((string)end($lines),true):null;
    ccCheck('rapid replacement diagnostic reason',$last['data']['reason']??null,'rapid_replacement');
    ccCheck('rapid replacement diagnostic previous payload',$last['data']['previous_payload']??null,'month_change_invalid');

    MaxSearchApi::$currentStatus=(int)MaxSearchApi::$statusCheck;
    $stale=InteractionGuard::runExpectedStatusReplacementCallback(
        $replacementChat,'month_change_11.2026',$replacementScope,(int)MaxSearchApi::$statusDate,10.0,0.75,
        function(callable $accept)use(&$replacementCalls):bool{$replacementCalls++;$accept();return true;}
    );
    ccCheck('replacement guard consumes stale callback',$stale,true);
    ccCheck('stale replacement does not reach business callback',$replacementCalls,2);
    $lines=is_file($replacementDiagnosticFile)?file($replacementDiagnosticFile,FILE_IGNORE_NEW_LINES|FILE_SKIP_EMPTY_LINES):[];
    $last=$lines?json_decode((string)end($lines),true):null;
    ccCheck('replacement stale diagnostic reason',$last['data']['reason']??null,'stale_state');
    ccCheck('replacement stale diagnostic current status',$last['data']['current_status']??null,(int)MaxSearchApi::$statusCheck);
    ccCheck('replacement stale diagnostic expected status',$last['data']['expected_status']??null,(int)MaxSearchApi::$statusDate);
}finally{
    MaxSearchApi::$currentStatus=(int)MaxSearchApi::$statusDate;
    @unlink($replacementDiagnosticFile);
    @unlink($replacementLock);
    DiagnosticLogger::setFile('');
}

$generation='deadbeef';
$generatedShowTours=CallbackGeneration::wrap('show_tours',$generation);
ccCheck('generation wrapper keeps compact versioned payload',$generatedShowTours,'g1_deadbeef_show_tours');
ccCheck('generation parser extracts base payload',CallbackGeneration::parse($generatedShowTours)['payload']??null,'show_tours');
ccCheck('generation parser extracts token',CallbackGeneration::parse($generatedShowTours)['generation']??null,$generation);
ccCheck('unversioned back remains outside generation parser',CallbackGeneration::parse('back_check'),null);
ccCheck('unversioned edit remains outside generation parser',CallbackGeneration::parse('edit_country'),null);
ccCheck('family normalizes generated show tours',CallbackController::family($generatedShowTours),'tours');
ccCheck('controller routes generated callbacks through one-shot guard',strpos($controllerSource,'CallbackGeneration::parse($raw)')!==false&&strpos($controllerSource,'InteractionGuard::runGeneratedCallback')!==false,true);
ccCheck('generation protection is limited to final-check actions',strpos($controllerSource,"['show_tours','manager_request','edit_params']")!==false,true);

$generationDiagnosticFile = sys_get_temp_dir() . '/max-search-generation-regression-' . bin2hex(random_bytes(4)) . '.log';
DiagnosticLogger::setFile($generationDiagnosticFile);
$generationCalls=0;
try {
    MaxSearchApi::$generationValue=$generation;
    $first=InteractionGuard::runGeneratedCallback(987654,$generatedShowTours,$generation,(int)MaxSearchApi::$statusCheck,function()use(&$generationCalls):bool{$generationCalls++;return true;});
    ccCheck('current generation executes first action',$first,true);
    ccCheck('current generation executes business action once',$generationCalls,1);
    ccCheck('successful generation is persisted as used',MaxSearchApi::$generationValue,'used:deadbeef');

    $second=InteractionGuard::runGeneratedCallback(987654,$generatedShowTours,$generation,(int)MaxSearchApi::$statusCheck,function()use(&$generationCalls):bool{$generationCalls++;return true;});
    ccCheck('obsolete generation delivery is consumed',$second,true);
    ccCheck('obsolete generation does not repeat business action',$generationCalls,1);
    $lines=is_file($generationDiagnosticFile)?file($generationDiagnosticFile,FILE_IGNORE_NEW_LINES|FILE_SKIP_EMPTY_LINES):[];
    $last=$lines?json_decode((string)end($lines),true):null;
    ccCheck('obsolete generation diagnostic reason',$last['data']['reason']??null,'obsolete_generation');
    ccCheck('obsolete generation diagnostic scope',$last['data']['scope']??null,'callback_generation');
    ccCheck('obsolete generation diagnostic token',$last['data']['generation']??null,$generation);

    $newGeneration='cafebabe';
    MaxSearchApi::$generationValue=$newGeneration;
    $newPayload=CallbackGeneration::wrap('edit_params',$newGeneration);
    $third=InteractionGuard::runGeneratedCallback(987654,$newPayload,$newGeneration,(int)MaxSearchApi::$statusCheck,function()use(&$generationCalls):bool{$generationCalls++;return true;});
    ccCheck('newly rendered generation is accepted',$third,true);
    ccCheck('new generation executes next business action',$generationCalls,2);
} finally {
    @unlink($generationDiagnosticFile);
    DiagnosticLogger::setFile('');
}

$controller = new CallbackController();
ccCheck('empty callback rejected', $controller->handle(['from'=>['id'=>1],'data'=>'']), false);
ccCheck('missing chat rejected', $controller->handle(['from'=>[],'data'=>'ai_start']), false);

$diagnosticFile = sys_get_temp_dir() . '/max-search-callback-regression-' . bin2hex(random_bytes(4)) . '.log';
DiagnosticLogger::setFile($diagnosticFile);
try {
    ccCheck('unknown callback rejected', $controller->handle(['from'=>['id'=>1],'data'=>'something_new']), false);
    $line = is_file($diagnosticFile) ? trim((string)file_get_contents($diagnosticFile)) : '';
    $record = $line !== '' ? json_decode($line, true) : null;
    ccCheck('unknown callback diagnostic component', $record['component'] ?? null, 'interaction_guard');
    ccCheck('unknown callback diagnostic event', $record['event'] ?? null, 'callback_suppressed');
    ccCheck('unknown callback diagnostic reason', $record['data']['reason'] ?? null, 'unknown_action');
    ccCheck('unknown callback diagnostic scope', $record['data']['scope'] ?? null, 'callback_controller');
    ccCheck('unknown callback diagnostic payload', $record['data']['payload'] ?? null, 'something_new');
    ccCheck('unknown callback diagnostic chat id', $record['chat_id'] ?? null, 1);
} finally {
    @unlink($diagnosticFile);
    DiagnosticLogger::setFile('');
}

$total = $passed + $failed;
echo "\n--------------------------\n";
echo "TOTAL {$total} | PASS {$passed} | FAIL {$failed}\n";
exit($failed > 0 ? 1 : 0);
