<?php

declare(strict_types=1);

final class StarsCallbackFakeResult
{
    private array $rows;
    private int $index = 0;
    public function __construct(array $rows) { $this->rows = array_values($rows); }
    public function fetch() { return $this->rows[$this->index++] ?? false; }
}

final class StarsCallbackFakeEntity
{
    public function getDataClass(): string { return StarsCallbackFakeData::class; }
}

final class StarsCallbackFakeData
{
    public static array $rows = [];
    public static int $adds = 0;
    public static int $updates = 0;

    public static function getList(array $query): StarsCallbackFakeResult
    {
        $filter = $query['filter'] ?? [];
        $rows = array_values(array_filter(self::$rows, static function (array $row) use ($filter): bool {
            foreach ($filter as $key => $value) {
                if (($row[$key] ?? null) != $value) return false;
            }
            return true;
        }));
        usort($rows, static fn(array $a, array $b): int => (int)$b['ID'] <=> (int)$a['ID']);
        if (!empty($query['limit'])) $rows = array_slice($rows, 0, (int)$query['limit']);
        return new StarsCallbackFakeResult($rows);
    }

    public static function update($id, array $fields): bool
    {
        foreach (self::$rows as &$row) {
            if ((int)$row['ID'] !== (int)$id) continue;
            self::$updates++;
            foreach ($fields as $key => $value) $row[$key] = $value;
            return true;
        }
        return false;
    }

    public static function add(array $fields): bool
    {
        self::$adds++;
        return true;
    }
}

eval('namespace Bitrix\\Main { class Loader { public static function includeModule($name) { return true; } } }');
eval('namespace Bitrix\\Highloadblock { class HighloadBlockTable { public static function getById($id) { return new \\StarsCallbackFakeResult([["ID"=>$id]]); } public static function compileEntity($row) { return new \\StarsCallbackFakeEntity(); } } }');

class MaxSearchApi
{
    public static $HL = 1;
    public static $statusStart = 64;
    public static $statusCityChoose = 65;
    public static $statusContryChoose = 66;
    public static $statusAdults = 67;
    public static $statusChild = 68;
    public static $statusAge = 69;
    public static $statusStars = 70;
    public static $statusMeal = 71;
    public static $statusNights = 72;
    public static $statusDate = 73;
    public static $statusCheck = 74;
    public static $statusPhone = 75;
    public static $statusAi = 76;
    public static int $currentStatus = 70;
    public static string $editMode = '';
    public static array $transitions = [];
    public static array $directSaves = [];
    public static array $aiApplyCalls = [];

    public static function getCurentStatus($chatId): int { return self::$currentStatus; }
    public static function deletePrevMessage($chatId, $withButtons = false): void {}
    public static function setStatus($chatId, $status): void
    {
        self::$currentStatus = (int)$status;
        self::$transitions[] = (int)$status;
    }
    public static function getEditMode($chatId): string { return self::$editMode; }
    public static function setEditMode($chatId, $field): void { self::$editMode = (string)$field; }
    public static function getSavedData($chatId): array { return [self::$statusStars => self::storedValue($chatId)]; }
    public static function getAiMissingFields($chatId): array { return ['nights']; }
    public static function formatSavedData(array $data): array { return []; }
    public static function funnelLog($chatId, $event, array $data = []): void {}
    public static function saveLastValue($chatId, $status, $value): bool
    {
        self::$directSaves[] = [$chatId, $status, $value];
        return true;
    }
    public static function applyAiParameters($chatId, array $params): array
    {
        self::$aiApplyCalls[] = ['chat_id'=>$chatId, 'params'=>$params];
        $applied = [];
        foreach (array_keys($params) as $field) $applied[(string)$field] = true;
        return $applied;
    }

    private static function storedValue($chatId)
    {
        foreach (array_reverse(StarsCallbackFakeData::$rows) as $row) {
            if (($row['UF_CHAT_ID'] ?? null) == $chatId && ($row['UF_STATUS'] ?? null) == self::$statusStars) {
                return $row['UF_VALUE'] ?? null;
            }
        }
        return null;
    }
}

require_once __DIR__ . '/../actions/callbacks/WizardCallbackAction.php';
require_once __DIR__ . '/../handlers/StateMessageHandler.php';

final class StarsCallbackMessenger implements MessengerInterface
{
    public array $sent = [];
    public array $buttons = [];
    public function send($chatId, string $text): bool { $this->sent[] = [$chatId, $text]; return true; }
    public function sendWithButtons($chatId, string $text, array $buttons): bool { $this->buttons[] = [$chatId, $text, $buttons]; return true; }
    public function sendContactRequest($chatId, string $text, string $manualCallback, string $backCallback): bool { return true; }
}

$failed = 0;
function starsCallbackCheck(string $name, $actual, $expected): void
{
    global $failed;
    if ($actual === $expected) { echo "PASS  {$name}\n"; return; }
    echo "FAIL  {$name}\n";
    echo '      expected: ' . json_encode($expected, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
    echo '      actual:   ' . json_encode($actual, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
    $failed++;
}

function starsCallbackReset(int $chatId, bool $withStep = true): StarsCallbackMessenger
{
    EditFlowService::clearSnapshot($chatId);
    @unlink(InteractionGuard::lockPath($chatId, 'wizard.forward'));
    MaxSearchApi::$currentStatus = (int)MaxSearchApi::$statusStars;
    MaxSearchApi::$editMode = '';
    MaxSearchApi::$transitions = [];
    MaxSearchApi::$directSaves = [];
    MaxSearchApi::$aiApplyCalls = [];
    StarsCallbackFakeData::$adds = 0;
    StarsCallbackFakeData::$updates = 0;
    StarsCallbackFakeData::$rows = [
        ['ID'=>10, 'UF_CHAT_ID'=>$chatId, 'UF_STATUS'=>MaxSearchApi::$statusStart, 'UF_VALUE'=>''],
    ];
    if ($withStep) {
        StarsCallbackFakeData::$rows[] = ['ID'=>20, 'UF_CHAT_ID'=>$chatId, 'UF_STATUS'=>MaxSearchApi::$statusStars, 'UF_VALUE'=>'3'];
    }
    $messenger = new StarsCallbackMessenger();
    IntegrationRegistry::resetForTests($messenger);
    return $messenger;
}

$messenger = starsCallbackReset(700);
$handled = WizardCallbackAction::handle(700, 'star_4');
starsCallbackCheck('stars callback is consumed', $handled, true);
starsCallbackCheck('stars callback stores exact value', MaxSearchApi::getSavedData(700)[MaxSearchApi::$statusStars] ?? null, '4');
starsCallbackCheck('stars callback advances exactly once to meal', MaxSearchApi::$transitions, [MaxSearchApi::$statusMeal]);
starsCallbackCheck('stars callback renders meal once', count($messenger->buttons), 1);
starsCallbackCheck('stars callback updates the step exactly once', StarsCallbackFakeData::$updates, 1);
starsCallbackCheck('stars callback avoids legacy direct write', MaxSearchApi::$directSaves, []);

$handledAgain = WizardCallbackAction::handle(700, 'star_4');
starsCallbackCheck('duplicate delivery is consumed after state advance', $handledAgain, true);
starsCallbackCheck('duplicate delivery preserves value', MaxSearchApi::getSavedData(700)[MaxSearchApi::$statusStars] ?? null, '4');
starsCallbackCheck('duplicate delivery does not render again', count($messenger->buttons), 1);
starsCallbackCheck('duplicate delivery does not transition again', MaxSearchApi::$transitions, [MaxSearchApi::$statusMeal]);
starsCallbackCheck('duplicate delivery does not update again', StarsCallbackFakeData::$updates, 1);

$messenger = starsCallbackReset(701);
MaxSearchApi::$currentStatus = (int)MaxSearchApi::$statusMeal;
$handledStale = WizardCallbackAction::handle(701, 'star_5');
starsCallbackCheck('stale stars callback is consumed', $handledStale, true);
starsCallbackCheck('stale stars callback preserves stored value', MaxSearchApi::getSavedData(701)[MaxSearchApi::$statusStars] ?? null, '3');
starsCallbackCheck('stale stars callback renders no view', count($messenger->buttons), 0);
starsCallbackCheck('stale stars callback makes no transition', MaxSearchApi::$transitions, []);
starsCallbackCheck('stale stars callback makes no update', StarsCallbackFakeData::$updates, 0);

$messenger = starsCallbackReset(702);
MaxSearchApi::$editMode = 'stars';
WizardCallbackAction::handle(702, 'star_5');
starsCallbackCheck('edit callback stores exact value', MaxSearchApi::getSavedData(702)[MaxSearchApi::$statusStars] ?? null, '5');
starsCallbackCheck('edit callback returns to check', MaxSearchApi::$transitions, [MaxSearchApi::$statusCheck]);
starsCallbackCheck('edit callback renders check once', count($messenger->buttons), 1);
starsCallbackCheck('edit callback clears edit mode', MaxSearchApi::$editMode, '');

$messenger = starsCallbackReset(703, false);
$before = count(StarsCallbackFakeData::$rows);
$handledMissing = WizardCallbackAction::handle(703, 'star_2');
starsCallbackCheck('missing stars step is consumed', $handledMissing, true);
starsCallbackCheck('missing stars step is not inserted', count(StarsCallbackFakeData::$rows), $before);
starsCallbackCheck('missing stars step does not call add', StarsCallbackFakeData::$adds, 0);
starsCallbackCheck('missing stars step makes no update', StarsCallbackFakeData::$updates, 0);
starsCallbackCheck('missing stars step renders no next view', count($messenger->buttons), 0);
starsCallbackCheck('missing stars step makes no transition', MaxSearchApi::$transitions, []);

$messenger = starsCallbackReset(710);
StateMessageHandler::handle(['text'=>'4 звезды'], 710, MaxSearchApi::$statusStars);
starsCallbackCheck('free-text stars stores canonical minimum', MaxSearchApi::getSavedData(710)[MaxSearchApi::$statusStars] ?? null, '4');
starsCallbackCheck('free-text stars advances exactly once to meal', MaxSearchApi::$transitions, [MaxSearchApi::$statusMeal]);
starsCallbackCheck('free-text stars renders meal once', count($messenger->buttons), 1);
starsCallbackCheck('free-text stars updates the step exactly once', StarsCallbackFakeData::$updates, 1);
starsCallbackCheck('free-text stars avoids legacy direct write', MaxSearchApi::$directSaves, []);
starsCallbackCheck('free-text stars sends no validation hint', count($messenger->sent), 0);

$messenger = starsCallbackReset(711);
StateMessageHandler::handle(['text'=>'4 или 5 звезд'], 711, MaxSearchApi::$statusStars);
starsCallbackCheck('natural alternatives use minimum acceptable category', MaxSearchApi::getSavedData(711)[MaxSearchApi::$statusStars] ?? null, '4');
starsCallbackCheck('natural alternatives progress to meal', MaxSearchApi::$transitions, [MaxSearchApi::$statusMeal]);

$messenger = starsCallbackReset(712);
StateMessageHandler::handle(['text'=>'не важно'], 712, MaxSearchApi::$statusStars);
starsCallbackCheck('no stars preference preserves existing any-category semantics', MaxSearchApi::getSavedData(712)[MaxSearchApi::$statusStars] ?? null, '1');
starsCallbackCheck('no stars preference progresses to meal', MaxSearchApi::$transitions, [MaxSearchApi::$statusMeal]);

$messenger = starsCallbackReset(713);
StateMessageHandler::handle(['text'=>'шесть звезд'], 713, MaxSearchApi::$statusStars);
starsCallbackCheck('invalid free-text stars preserves stored value', MaxSearchApi::getSavedData(713)[MaxSearchApi::$statusStars] ?? null, '3');
starsCallbackCheck('invalid free-text stars makes no update', StarsCallbackFakeData::$updates, 0);
starsCallbackCheck('invalid free-text stars makes no transition', MaxSearchApi::$transitions, []);
starsCallbackCheck('invalid free-text stars renders no meal view', count($messenger->buttons), 0);
starsCallbackCheck('invalid free-text stars gets one bounded hint', count($messenger->sent), 1);

$messenger = starsCallbackReset(714);
MaxSearchApi::$editMode = 'stars';
StateMessageHandler::handle(['text'=>'5 звезд'], 714, MaxSearchApi::$statusStars);
starsCallbackCheck('edit free-text stars stores exact value', MaxSearchApi::getSavedData(714)[MaxSearchApi::$statusStars] ?? null, '5');
starsCallbackCheck('edit free-text stars returns to check', MaxSearchApi::$transitions, [MaxSearchApi::$statusCheck]);
starsCallbackCheck('edit free-text stars renders check once', count($messenger->buttons), 1);
starsCallbackCheck('edit free-text stars does not also render meal', count($messenger->buttons), 1);
starsCallbackCheck('edit free-text stars clears edit mode', MaxSearchApi::$editMode, '');

$messenger = starsCallbackReset(715, false);
$before = count(StarsCallbackFakeData::$rows);
StateMessageHandler::handle(['text'=>'4 звезды'], 715, MaxSearchApi::$statusStars);
starsCallbackCheck('missing free-text stars step is not inserted', count(StarsCallbackFakeData::$rows), $before);
starsCallbackCheck('missing free-text stars step does not call add', StarsCallbackFakeData::$adds, 0);
starsCallbackCheck('missing free-text stars step makes no update', StarsCallbackFakeData::$updates, 0);
starsCallbackCheck('missing free-text stars step makes no transition', MaxSearchApi::$transitions, []);
starsCallbackCheck('missing free-text stars step renders no next view', count($messenger->buttons), 0);
starsCallbackCheck('missing free-text stars step sends no false success hint', count($messenger->sent), 0);

// Behavioral coverage for the combined-answer slice: exercise the real handler,
// canonical application boundary and progression rather than only the splitter.
$messenger = starsCallbackReset(716);
StateMessageHandler::handle(['text'=>'4 звезды и всё включено'], 716, MaxSearchApi::$statusStars);
starsCallbackCheck('combined answer stores explicit stars once', MaxSearchApi::getSavedData(716)[MaxSearchApi::$statusStars] ?? null, '4');
starsCallbackCheck('combined answer applies explicit meal through canonical AI parameter boundary', MaxSearchApi::$aiApplyCalls, [
    ['chat_id'=>716, 'params'=>['meal'=>'all_inclusive']],
]);
starsCallbackCheck('combined answer skips repeat meal step and asks the next missing field once', MaxSearchApi::$transitions, [MaxSearchApi::$statusAi]);
starsCallbackCheck('combined answer renders no meal buttons', count($messenger->buttons), 0);
starsCallbackCheck('combined answer sends one next-field question', count($messenger->sent), 1);
starsCallbackCheck('combined answer next question is nights', strpos((string)($messenger->sent[0][1] ?? ''), 'На сколько ночей') !== false, true);
starsCallbackCheck('combined answer updates existing stars step exactly once', StarsCallbackFakeData::$updates, 1);
starsCallbackCheck('combined answer sends no stars validation repeat', strpos((string)($messenger->sent[0][1] ?? ''), 'категорию отеля') === false, true);

$source = (string)file_get_contents(__DIR__ . '/../actions/callbacks/WizardCallbackAction.php');
$stateSource = (string)file_get_contents(__DIR__ . '/../handlers/StateMessageHandler.php');
$starsStateStart = strpos($stateSource, 'elseif($status==MaxSearchApi::$statusStars)');
$starsStateEnd = strpos($stateSource, 'elseif($status==MaxSearchApi::$statusMeal)', $starsStateStart === false ? 0 : $starsStateStart);
$starsStateBlock = ($starsStateStart !== false && $starsStateEnd !== false)
    ? substr($stateSource, $starsStateStart, $starsStateEnd - $starsStateStart)
    : '';
starsCallbackCheck('action applies stars through update-only boundary', strpos($source, '$stars = str_replace') !== false && strpos($source, 'MaxSearchApi::$statusStars,') !== false, true);
starsCallbackCheck('action keeps the shared forward lock', strpos($source, "InteractionGuard::synchronized(\$chatId, 'wizard.forward'") !== false, true);
starsCallbackCheck('action keeps stale check inside the shared lock', strpos($source, 'self::staleForwardCallback($chatId, $q)') !== false, true);
starsCallbackCheck(
    'free-text stars uses canonical resolver/application owner',
    $starsStateBlock !== ''
        && strpos($starsStateBlock, 'NeedApplicationService::resolveAndApplyExistingWizardStep') !== false
        && strpos($starsStateBlock, "'stars'") !== false
        && strpos($starsStateBlock, 'NeedValueResolver::resolve') === false
        && strpos($starsStateBlock, 'ExistingWizardStepApplicationService::apply') === false,
    true
);

foreach ([700, 701, 702, 703, 710, 711, 712, 713, 714, 715, 716] as $chatId) {
    EditFlowService::clearSnapshot($chatId);
    @unlink(InteractionGuard::lockPath($chatId, 'wizard.forward'));
}

echo "\n--------------------------\n";
echo $failed === 0 ? "STARS CALLBACK APPLICATION: OK\n" : "STARS CALLBACK APPLICATION: FAIL ({$failed})\n";
exit($failed === 0 ? 0 : 1);