<?php

declare(strict_types=1);
require_once __DIR__ . '/../handlers/AiShortAnswerHandler.php';

$cases = [
    ['live phrase', '14 лет один', ['children'=>1,'child_ages'=>[14]]],
    ['numeric one child', '14 лет 1', ['children'=>1,'child_ages'=>[14]]],
    ['explicit child noun', '9 лет один ребенок', ['children'=>1,'child_ages'=>[9]]],
    ['comma variant', '7 лет, один ребёнок', ['children'=>1,'child_ages'=>[7]]],
    ['adult age rejected', '18 лет один', null],
    ['ambiguous age only rejected', '14 лет', null],
    ['ambiguous count first rejected', 'один 14 лет', null],
    ['multiple children not guessed', '14 лет двое', null],
];

$failed = 0;
foreach ($cases as [$name, $input, $expected]) {
    $actual = AiShortAnswerHandler::childAgeCountClarificationWhileAskingChildren($input);
    if ($actual === $expected) {
        echo "PASS  {$name}\n";
        continue;
    }
    echo "FAIL  {$name}: expected ".var_export($expected,true).", got ".var_export($actual,true)."\n";
    $failed++;
}

$aiMessageSource = (string)file_get_contents(__DIR__ . '/../handlers/AiMessageHandler.php');
$completionSource = (string)file_get_contents(__DIR__ . '/../services/AiNeedCompletionService.php');
$sourceChecks = [
    'AiMessageHandler loads canonical completion service' => strpos($aiMessageSource, 'AiNeedCompletionService.php') !== false,
    'AiMessageHandler resolves child ages through completion boundary' => strpos($aiMessageSource, "AiNeedCompletionService::resolveApplyAndAdvance(\n                        \$chat_id,\n                        'child_ages'") !== false,
    'completion boundary resolves child ages through application service' => strpos($completionSource, 'NeedApplicationService::resolveAndApply($chatId, $field, $text, $context)') !== false,
    'completion boundary advances recognized child ages' => strpos($completionSource, 'NeedProgressionService::advance($chatId, $questionOptions)') !== false,
    'AiMessageHandler no longer resolves child ages directly' => strpos($aiMessageSource, "NeedApplicationService::resolveAndApply(\n                        \$chat_id,\n                        'child_ages'") === false,
    'AiMessageHandler no longer owns numeric child-age extraction' => strpos($aiMessageSource, "preg_match_all('/\\b(\\d{1,2})\\b/u', \$ageText") === false,
    'AiMessageHandler no longer writes child age directly' => strpos($aiMessageSource, 'MaxSearchApi::$statusAge') === false,
];

foreach ($sourceChecks as $name => $ok) {
    if ($ok) {
        echo "PASS  {$name}\n";
        continue;
    }
    echo "FAIL  {$name}\n";
    $failed++;
}


// Synthetic regression: execute the actual short-answer handler/resolver/application
// with an in-memory transport. No customer IDs, transcript or provider call.
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
    global $failed;
    echo ($ok ? 'PASS  ' : 'FAIL  ').$name."\n";
    if (!$ok) $failed++;
}
$transport = new UnsupportedCountTestMessenger();
IntegrationRegistry::resetForTests($transport, null, null);
$before = MaxSearchApi::$context;
foreach (['Конечно, 7 детей.', '7', '7 детей', 'Да, семеро', 'Дети, 7 человек'] as $text) {
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

exit($failed ? 1 : 0);
