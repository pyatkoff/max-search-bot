<?php

declare(strict_types=1);
require_once __DIR__ . '/../services/NeedValueResolver.php';
require_once __DIR__ . '/../services/ChildAgeValueContract.php';
require_once __DIR__ . '/../services/AiSearchContextService.php';
require_once __DIR__ . '/../maxsearchbaseclass.php';

$passed = 0;
$failed = 0;

function nvrCheck(string $label, $actual, $expected): void
{
    global $passed, $failed;
    if ($actual === $expected) {
        echo "PASS  {$label}\n";
        $passed++;
        return;
    }

    echo "FAIL  {$label}\n";
    echo '      expected: ' . json_encode($expected, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
    echo '      actual:   ' . json_encode($actual, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
    $failed++;
}

$adults = NeedValueResolver::resolve('adults', 'Двое');
nvrCheck('adults word recognized', $adults['recognized'], true);
nvrCheck('adults canonical value', $adults['value'], 2);
nvrCheck('adults source is deterministic', $adults['source'], 'deterministic:adults_parser');
nvrCheck('adults deterministic confidence', $adults['confidence'], 1.0);

$adultsSuffix = NeedValueResolver::resolve('adults', '3 взрослых');
nvrCheck('adults suffix form retained', $adultsSuffix['value'], 3);
$adultsLivePair = NeedValueResolver::resolve('adults', 'Я и жена');
nvrCheck('live spouse pair is recognized', $adultsLivePair['recognized'], true);
nvrCheck('live spouse pair maps to two adults', $adultsLivePair['value'], 2);
$adultsUnrelatedPair = NeedValueResolver::resolve('adults', 'Я и ребенок');
nvrCheck('unrelated pair does not invent adults', $adultsUnrelatedPair['recognized'], false);
$adultsPairWithExtraIntent = NeedValueResolver::resolve('adults', 'Я и жена, и ребенок');
nvrCheck('spouse pair with extra party detail stays unresolved', $adultsPairWithExtraIntent['recognized'], false);
$adultsUnknown = NeedValueResolver::resolve('adults', 'семеро');
nvrCheck('out of range adults stays unresolved', $adultsUnknown['recognized'], false);
$adultsNegated = NeedValueResolver::resolve('adults', 'не 2 взрослых');
nvrCheck('negated adult count is not persisted as a fact', $adultsNegated['recognized'], false);
$adultsCorrection = NeedValueResolver::resolve('adults', 'не 2 взрослых, а 3 взрослых');
nvrCheck('explicit adult correction is recognized', $adultsCorrection['recognized'], true);
nvrCheck('explicit adult correction keeps positive replacement', $adultsCorrection['value'], 3);

$childrenNone = NeedValueResolver::resolve('children', 'без детей');
nvrCheck('children none phrase recognized', $childrenNone['recognized'], true);
nvrCheck('children none canonical value', $childrenNone['value'], 0);
nvrCheck('children source is deterministic', $childrenNone['source'], 'deterministic:children_parser');
nvrCheck('children deterministic confidence', $childrenNone['confidence'], 1.0);
$childrenWord = NeedValueResolver::resolve('children', 'двое');
nvrCheck('children word value retained', $childrenWord['value'], 2);
$childrenSuffix = NeedValueResolver::resolve('children', '3 детей');
nvrCheck('children suffix value retained', $childrenSuffix['value'], 3);
$childrenAffirmativeNumeric = NeedValueResolver::resolve('children', 'Да, 3');
nvrCheck('live affirmative numeric child count recognized', $childrenAffirmativeNumeric['recognized'], true);
nvrCheck('live affirmative numeric child count value', $childrenAffirmativeNumeric['value'], 3);
$childrenAffirmativeWord = NeedValueResolver::resolve('children', 'Да, трое');
nvrCheck('live affirmative word child count recognized', $childrenAffirmativeWord['recognized'], true);
nvrCheck('live affirmative word child count value', $childrenAffirmativeWord['value'], 3);
$childrenLabeledPerson = NeedValueResolver::resolve('children', 'Дети, 1 человек');
nvrCheck('live 734 labeled child count recognized', $childrenLabeledPerson['recognized'], true);
nvrCheck('live 734 labeled child count value', $childrenLabeledPerson['value'], 1);
$childrenLabeledAge = NeedValueResolver::resolve('children', 'Дети, 2 года');
nvrCheck('labeled age phrase is not consumed as child count', $childrenLabeledAge['recognized'], false);
$childrenBareYes = NeedValueResolver::resolve('children', 'Да');
nvrCheck('bare affirmative does not invent child count', $childrenBareYes['recognized'], false);
$childrenTooMany = NeedValueResolver::resolve('children', '4');
nvrCheck('children above current maximum stays unresolved', $childrenTooMany['recognized'], false);
$childrenAmbiguous = NeedValueResolver::resolve('children', '6 лет, один ребенок');
nvrCheck('multi-field child age clarification is not consumed as simple children value', $childrenAmbiguous['recognized'], false);

$childAges = NeedValueResolver::resolve('child_ages', '5 и 12', ['children'=>2]);
nvrCheck('child ages recognized with expected count', $childAges['recognized'], true);
nvrCheck('child ages canonical value', $childAges['value'], [5,12]);
nvrCheck('child ages source is deterministic', $childAges['source'], 'deterministic:child_ages_parser');
nvrCheck('child ages deterministic confidence', $childAges['confidence'], 1.0);
$childAgesWrongCount = NeedValueResolver::resolve('child_ages', '5', ['children'=>2]);
nvrCheck('child ages wrong count stays unresolved', $childAgesWrongCount['recognized'], false);
$childAgesOutOfRange = NeedValueResolver::resolve('child_ages', '5 и 18', ['children'=>2]);
nvrCheck('child ages out of range stays unresolved', $childAgesOutOfRange['recognized'], false);

$infantMonths = NeedValueResolver::resolve('child_ages', 'Малышу 7 месяцев', ['children'=>1]);
nvrCheck('explicit infant months do not become years', $infantMonths['value'], [0]);
nvrCheck('infant zero uses existing completed-year storage', ChildAgeValueContract::toStorage($infantMonths['value'], 1), '0');
$monthsAboveYear = NeedValueResolver::resolve('child_ages', '18 месяцев', ['children'=>1]);
nvrCheck('explicit months retain completed years', $monthsAboveYear['value'], [1]);
$yearsAndMonths = NeedValueResolver::resolve('child_ages', '1 год 7 месяцев', ['children'=>1]);
nvrCheck('compound age is one child, not two children', $yearsAndMonths['value'], [1]);
$mixedUnits = NeedValueResolver::resolve('child_ages', '7 месяцев и 4 года', ['children'=>2]);
nvrCheck('each explicit unit belongs to its child', $mixedUnits['value'], [0,4]);
foreach (['7 месяцев и 4', 'почти 12 месяцев', 'не 7 месяцев', '1 год 12 месяцев', '7-8 месяцев', '-7 месяцев', '07.12.2027', '2 ребенка, 7 месяцев', '7 месяцев или 8 лет'] as $text) {
    $unresolvedAge = NeedValueResolver::resolve('child_ages', $text, ['children'=>1]);
    nvrCheck('ambiguous or contradictory unit age stays unresolved: '.$text, $unresolvedAge['recognized'], false);
}
$alternativeMonthAges = NeedValueResolver::resolve('child_ages', '7 месяцев или 8 лет', ['children'=>2]);
nvrCheck('an age alternative is not two children', $alternativeMonthAges['recognized'], false);
$upperMonthUnit = NeedValueResolver::resolve('child_ages', '7 МЕСЯЦЕВ', ['children'=>1]);
nvrCheck('month units remain explicit regardless of letter case', $upperMonthUnit['value'], [0]);
$wrongMonthCount = NeedValueResolver::resolve('child_ages', '7 месяцев', ['children'=>2]);
nvrCheck('months never invent a missing second age', $wrongMonthCount['recognized'], false);


$infantNormalized = AiSearchContextService::normalizeParameters(
    ['child_ages'=>$infantMonths['value']], static fn($name)=>null, static fn($name)=>null);
nvrCheck('infant remains zero through canonical normalization', $infantNormalized['child_ages'] ?? null, '0');
$infantStatus = ['city'=>101,'country'=>102,'adults'=>103,'children'=>104,'child_ages'=>105,'stars'=>106,'meal'=>107,'nights'=>108,'date'=>109];
$infantSaved = [101=>1,102=>1,103=>2,104=>1,105=>'0',106=>null,107=>null,108=>'7',109=>'15.12.2030'];
$infantContext = AiSearchContextService::contextFromSaved($infantSaved, $infantStatus,
    static fn($id)=>'Fixture origin', static fn($id)=>'Fixture country');
nvrCheck('saved zero age survives the next-turn context', $infantContext['child_ages'] ?? null, '0');
nvrCheck('saved zero age is complete rather than missing', AiSearchContextService::missingFromSaved($infantSaved, $infantStatus), []);
$wrongInfantParty = $infantSaved;
$wrongInfantParty[104] = 2;
$wrongInfantContext = AiSearchContextService::contextFromSaved($wrongInfantParty, $infantStatus,
    static fn($id)=>'Fixture origin', static fn($id)=>'Fixture country');
nvrCheck('one infant age cannot fill a second child', array_key_exists('child_ages', $wrongInfantContext), false);
class InfantAgeSummaryFixture extends MaxSearchBase
{
    static $statusCityChoose=101; static $statusContryChoose=102;
    static $statusAdults=103; static $statusChild=104; static $statusAge=105;
    static $statusStars=106; static $statusMeal=107; static $statusNights=108; static $statusDate=109;
}
foreach ([0, '0'] as $zeroAge) {
    $summarySaved = [101=>null,102=>null,103=>2,104=>1,105=>$zeroAge,106=>null,107=>null,108=>null,109=>null];
    nvrCheck('actual trip summary shows an infant instead of dropping zero age',
        InfantAgeSummaryFixture::formatSavedData($summarySaved), ['👥 2 взр. + 1 реб. (до 1 года)']);
}

$childAgesNoContext = NeedValueResolver::resolve('child_ages', '5');
nvrCheck('child ages without child count stays unresolved', $childAgesNoContext['recognized'], false);

$stars = NeedValueResolver::resolve('stars', '4,5');
nvrCheck('star set recognized', $stars['recognized'], true);
nvrCheck('star set keeps minimum semantics', $stars['value'], 4);
nvrCheck('stars source is deterministic', $stars['source'], 'deterministic:stars_parser');
nvrCheck('stars deterministic confidence', $stars['confidence'], 1.0);
$starsNaturalChoice = NeedValueResolver::resolve('stars', '4 или 5');
nvrCheck('live natural star alternative recognized', $starsNaturalChoice['recognized'], true);
nvrCheck('live natural star alternative keeps minimum semantics', $starsNaturalChoice['value'], 4);
$starsNaturalChoiceSuffix = NeedValueResolver::resolve('stars', '4 или 5 звёзд');
nvrCheck('live natural star alternative with suffix recognized', $starsNaturalChoiceSuffix['recognized'], true);
nvrCheck('live natural star alternative with suffix keeps minimum semantics', $starsNaturalChoiceSuffix['value'], 4);
$starsAny = NeedValueResolver::resolve('stars', 'не важно');
nvrCheck('any stars retains existing minimum-one semantics', $starsAny['value'], 1);
$starsUnknown = NeedValueResolver::resolve('stars', 'шесть');
nvrCheck('out of range stars stays unresolved', $starsUnknown['recognized'], false);

$meal = NeedValueResolver::resolve('meal', 'Всё включено');
nvrCheck('meal recognized', $meal['recognized'], true);
nvrCheck('meal canonical value', $meal['value'], 'all_inclusive');
nvrCheck('meal source is deterministic', $meal['source'], 'deterministic:meal_parser');
nvrCheck('meal deterministic confidence', $meal['confidence'], 1.0);

$liveMeal = NeedValueResolver::resolve('meal', '3х разовое');
nvrCheck('live 3х meal shorthand recognized', $liveMeal['recognized'], true);
nvrCheck('live 3х meal shorthand maps to full board', $liveMeal['value'], 'full_board');
nvrCheck('live 3х meal shorthand stays deterministic', $liveMeal['source'], 'deterministic:meal_parser');

$mealUnknown = NeedValueResolver::resolve('meal', 'Вкусное');
nvrCheck('unknown meal not recognized', $mealUnknown['recognized'], false);
nvrCheck('unknown meal has no value', $mealUnknown['value'], null);
nvrCheck('unknown meal confidence is zero', $mealUnknown['confidence'], 0.0);

$nights = NeedValueResolver::resolve('nights', 'От 7-9');
nvrCheck('live nights range recognized', $nights['recognized'], true);
nvrCheck('live nights range canonical value', $nights['value'], '7-9');
nvrCheck('nights source is deterministic', $nights['source'], 'deterministic:nights_parser');
nvrCheck('nights deterministic confidence', $nights['confidence'], 1.0);

$nightsUnknown = NeedValueResolver::resolve('nights', 'от 7');
nvrCheck('minimum-only nights stays unresolved', $nightsUnknown['recognized'], false);
nvrCheck('minimum-only nights has no invented value', $nightsUnknown['value'], null);

$date = NeedValueResolver::resolve('date', '31.12.2030');
nvrCheck('canonical stored date is recognized', $date['recognized'], true);
nvrCheck('canonical stored date is preserved exactly', $date['value'], '31.12.2030');
nvrCheck('date source is exact value contract', $date['source'], 'deterministic:date_value_contract');
nvrCheck('date deterministic confidence', $date['confidence'], 1.0);
$dateInvalid = NeedValueResolver::resolve('date', '31.02.2030');
nvrCheck('calendar-invalid stored date stays unresolved', $dateInvalid['recognized'], false);
$dateNatural = NeedValueResolver::resolve('date', '12 октября');
nvrCheck('resolver does not take over natural date parsing', $dateNatural['recognized'], false);

$budgetPersonalPrefix = NeedValueResolver::resolve('budget', 'Бюджет на человека до 90 тыс');
nvrCheck('budget basis before amount is recognized', $budgetPersonalPrefix['recognized'], true);
nvrCheck('budget basis before amount keeps the amount', $budgetPersonalPrefix['value']['budget.max'] ?? null, 90000);
nvrCheck('budget basis before amount keeps explicit personal basis', $budgetPersonalPrefix['value']['budget.basis'] ?? null, 'per_person');

$budgetTotalPrefix = NeedValueResolver::resolve('budget', 'Бюджет на всех до 250 тыс');
nvrCheck('whole-party budget basis before amount is recognized', $budgetTotalPrefix['recognized'], true);
nvrCheck('whole-party budget basis before amount stays total', $budgetTotalPrefix['value']['budget.basis'] ?? null, 'total');

$budgetContradictoryBasis = NeedValueResolver::resolve('budget', 'Бюджет на человека 90 тыс на всех');
nvrCheck('contradictory budget basis stays unresolved', $budgetContradictoryBasis['recognized'], false);
$budgetSubsetPrefix = NeedValueResolver::resolve('budget', 'Бюджет на взрослого до 90 тыс');
nvrCheck('subset budget basis before amount stays unresolved', $budgetSubsetPrefix['recognized'], false);
$budgetPerNightPrefix = NeedValueResolver::resolve('budget', 'Бюджет на человека 90 тыс за ночь');
nvrCheck('per-night budget stays unresolved with prefix basis', $budgetPerNightPrefix['recognized'], false);

$unsupported = NeedValueResolver::resolve('resort', 'Анталья');
nvrCheck('unmigrated field remains explicitly unsupported', $unsupported, [
    'recognized' => false,
    'value' => null,
    'source' => 'unsupported',
    'confidence' => 0.0,
]);

$handlerSource = (string)file_get_contents(__DIR__ . '/../handlers/AiShortAnswerHandler.php');
nvrCheck('AI children delegates simple values through application service', strpos($handlerSource, "NeedApplicationService::resolveAndApply(\$chat_id, 'children'") !== false, true);
nvrCheck('AI children keeps multi-field party clarification before simple resolver', strpos($handlerSource, 'if ($partyClarification !== null)') !== false && strpos($handlerSource, 'elseif ($ageCountClarification !== null)') !== false, true);
nvrCheck('AI children no longer owns generic short number parsing', strpos($handlerSource, 'numberFromShortText') === false, true);
nvrCheck('AI child ages delegates through application service', strpos($handlerSource, "NeedApplicationService::resolveAndApply(\$chat_id, 'child_ages'") !== false, true);
nvrCheck('AI child ages no longer owns numeric extraction', strpos($handlerSource, 'preg_match_all(\'/\\b(\\d{1,2})\\b/u\', $lower') === false, true);

// Synthetic regression: execute the actual short-answer handler/resolver/application
// with an in-memory transport. No customer IDs, transcript or provider call.
require_once __DIR__.'/../handlers/AiShortAnswerHandler.php';
require_once __DIR__.'/../contracts/MessengerInterface.php';
class UnsupportedCountTestMessenger implements MessengerInterface
{
    public array $sent = [];
    public function send($chatId, string $text): bool { $this->sent[] = $text; return true; }
    public function sendWithButtons($chatId, string $text, array $buttons): bool { throw new RuntimeException('unexpected_buttons'); }
    public function sendContactRequest($chatId, string $text, string $manualCallback, string $backCallback): bool { throw new RuntimeException('unexpected_handoff'); }
}
class MaxSearchApi
{
    public static array $missing = ['children'];
    public static array $context = ['adults'=>2, 'country'=>'Турция', 'child_ages'=>'4, 9'];
    public static int $writes = 0;
    public static function getAiMissingFields($chatId): array { return self::$missing; }
    public static function getAiSearchContext($chatId): array { return self::$context; }
    public static function applyAiParameters($chatId, array $params): array { self::$writes++; throw new RuntimeException('unexpected_need_write'); }
    public static function setStatus(...$args): void { self::$writes++; throw new RuntimeException('unexpected_status_write'); }
    public static function funnelLog(...$args): void {}
}
function unsupportedCheck(string $name, bool $ok): void
{
    nvrCheck($name, $ok, true);
}
$transport = new UnsupportedCountTestMessenger();
IntegrationRegistry::resetForTests($transport, null, null);
$before = MaxSearchApi::$context;
foreach (['Конечно, 7 детей.', '7', '7 детей', '7детей', 'Да, семеро', 'Дети, 7 человек'] as $text) {
    $transport->sent = [];
    $handled = AiShortAnswerHandler::handle(['text'=>$text], 123);
    unsupportedCheck('known unsupported count consumes the turn: '.$text, $handled === true);
    unsupportedCheck('explains capacity and customer-initiated manager path: '.$text,
        count($transport->sent) === 1
        && strpos($transport->sent[0], '7 детей') !== false
        && strpos($transport->sent[0], 'до 3 детей') !== false
        && strpos($transport->sent[0], 'Позвать менеджера') !== false
        && strpos($transport->sent[0], 'Будут дети?') === false);
    unsupportedCheck('no need/status mutation, truncation or inferred ages: '.$text,
        MaxSearchApi::$writes === 0 && MaxSearchApi::$context === $before);
}
foreach (['7 лет', '7 ночей', '07.12.2027', '7 и 9', 'не 7 детей', 'двое взрослых, дети 4,9 лет'] as $text) {
    $transport->sent = [];
    unsupportedCheck('ambiguous or unrelated input is not an unsupported count: '.$text,
        AiShortAnswerHandler::handle(['text'=>$text], 123) === false && $transport->sent === []);
}
foreach ([['date'], ['city', 'children'], []] as $missing) {
    MaxSearchApi::$missing = $missing;
    $transport->sent = [];
    unsupportedCheck('bare number is child-scoped, not date/city/complete input: '.json_encode($missing),
        AiShortAnswerHandler::handle(['text'=>'7'], 123) === false && $transport->sent === []);
}
foreach (['нет'=>0, '1 ребенок'=>1, 'двое'=>2, 'Да, 3'=>3] as $text=>$expected) {
    $resolved = NeedValueResolver::resolve('children', $text);
    unsupportedCheck('supported child contract unchanged: '.$text,
        $resolved['recognized'] === true && $resolved['value'] === $expected && !isset($resolved['unsupported_count']));
}
$unsupported = NeedApplicationService::resolveAndApply(123, 'children', '4 детей');
unsupportedCheck('unsupported diagnostic survives application but never becomes a value',
    $unsupported['recognized'] === false && $unsupported['value'] === null
    && $unsupported['applied'] === false && ($unsupported['unsupported_count'] ?? null) === 4
    && MaxSearchApi::$writes === 0);
$ages = NeedValueResolver::resolve('child_ages', '7', ['children'=>1]);
unsupportedCheck('single seven remains a valid child age in its own field',
    $ages['recognized'] === true && $ages['value'] === [7] && !isset($ages['unsupported_count']));
IntegrationRegistry::resetForTests();


echo "\n--------------------------\n";
echo 'TOTAL ' . ($passed + $failed) . " | PASS {$passed} | FAIL {$failed}\n";
exit($failed > 0 ? 1 : 0);
