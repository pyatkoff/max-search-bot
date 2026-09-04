<?php

declare(strict_types=1);

final class ChildrenCallbackFakeResult
{
    private array $rows;
    private int $index = 0;
    public function __construct(array $rows) { $this->rows = array_values($rows); }
    public function fetch() { return $this->rows[$this->index++] ?? false; }
}

final class ChildrenCallbackFakeEntity
{
    public function getDataClass(): string { return ChildrenCallbackFakeData::class; }
}

final class ChildrenCallbackFakeData
{
    public static array $rows = [];
    public static int $adds = 0;
    public static int $updates = 0;

    public static function getList(array $query): ChildrenCallbackFakeResult
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
        return new ChildrenCallbackFakeResult($rows);
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
eval('namespace Bitrix\\Highloadblock { class HighloadBlockTable { public static function getById($id) { return new \\ChildrenCallbackFakeResult([["ID"=>$id]]); } public static function compileEntity($row) { return new \\ChildrenCallbackFakeEntity(); } } }');

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
    public static int $currentStatus = 68;
    public static string $editMode = '';
    public static array $transitions = [];
    public static array $directSaves = [];
    public static array $ageViews = [];
    public static array $funnelEvents = [];

    public static function getCurentStatus($chatId): int { return self::$currentStatus; }
    public static function deletePrevMessage($chatId, $withButtons = false): void {}
    public static function setStatus($chatId, $status): void
    {
        self::$currentStatus = (int)$status;
        self::$transitions[] = (int)$status;
    }
    public static function getEditMode($chatId): string { return self::$editMode; }
    public static function setEditMode($chatId, $field): void { self::$editMode = (string)$field; }
    public static function getSavedData($chatId): array
    {
        $values = [];
        foreach (ChildrenCallbackFakeData::$rows as $row) {
            if (($row['UF_CHAT_ID'] ?? null) == $chatId) $values[$row['UF_STATUS']] = $row['UF_VALUE'];
        }
        return $values;
    }
    public static function formatSavedData(array $data): array { return []; }
    public static function funnelLog($chatId, $event, array $data = []): void { self::$funnelEvents[] = [$event, $data]; }
    public static function saveLastValue($chatId, $status, $value): bool
    {
        self::$directSaves[] = [$chatId, $status, $value];
        return true;
    }

    public static function showStarsButtons($chatId): bool { return DialogueView::stars($chatId); }
    public static function showChildButtons($chatId): bool { return DialogueView::children($chatId); }
    public static function showAdultsButtons($chatId): bool { return DialogueView::adults($chatId); }
    public static function showAgeButtons($chatId, $children = 1): bool
    {
        self::$ageViews[] = (int)$children;
        return DialogueView::childAges($chatId, (int)$children);
    }
}

require_once __DIR__ . '/../actions/callbacks/WizardCallbackAction.php';

final class ChildrenCallbackMessenger implements MessengerInterface
{
    public array $sent = [];
    public array $buttons = [];
    public function send($chatId, string $text): bool { $this->sent[] = [$chatId, $text]; return true; }
    public function sendWithButtons($chatId, string $text, array $buttons): bool { $this->buttons[] = [$chatId, $text, $buttons]; return true; }
    public function sendContactRequest($chatId, string $text, string $manualCallback, string $backCallback): bool { return true; }
}

$failed = 0;
function childrenCallbackCheck(string $name, $actual, $expected): void
{
    global $failed;
    if ($actual === $expected) { echo "PASS  {$name}\n"; return; }
    echo "FAIL  {$name}\n";
    echo '      expected: ' . json_encode($expected, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
    echo '      actual:   ' . json_encode($actual, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
    $failed++;
}

function childrenCallbackReset(int $chatId, bool $withStep = true): ChildrenCallbackMessenger
{
    EditFlowService::clearSnapshot($chatId);
    @unlink(InteractionGuard::lockPath($chatId, 'wizard.forward'));
    MaxSearchApi::$currentStatus = (int)MaxSearchApi::$statusChild;
    MaxSearchApi::$editMode = '';
    MaxSearchApi::$transitions = [];
    MaxSearchApi::$directSaves = [];
    MaxSearchApi::$ageViews = [];
    MaxSearchApi::$funnelEvents = [];
    ChildrenCallbackFakeData::$adds = 0;
    ChildrenCallbackFakeData::$updates = 0;
    ChildrenCallbackFakeData::$rows = [
        ['ID'=>10, 'UF_CHAT_ID'=>$chatId, 'UF_STATUS'=>MaxSearchApi::$statusStart, 'UF_VALUE'=>''],
        ['ID'=>11, 'UF_CHAT_ID'=>$chatId, 'UF_STATUS'=>MaxSearchApi::$statusAdults, 'UF_VALUE'=>'2'],
        ['ID'=>12, 'UF_CHAT_ID'=>$chatId, 'UF_STATUS'=>MaxSearchApi::$statusAge, 'UF_VALUE'=>'3, 7'],
    ];
    if ($withStep) {
        ChildrenCallbackFakeData::$rows[] = ['ID'=>20, 'UF_CHAT_ID'=>$chatId, 'UF_STATUS'=>MaxSearchApi::$statusChild, 'UF_VALUE'=>'2'];
    }
    $messenger = new ChildrenCallbackMessenger();
    IntegrationRegistry::resetForTests($messenger);
    return $messenger;
}


foreach (['0', '1', '2', '3'] as $value) {
    $chatId = 900 + (int)$value;
    $messenger = childrenCallbackReset($chatId);
    $before = count(ChildrenCallbackFakeData::$rows);
    $next = $value === '0' ? MaxSearchApi::$statusStars : MaxSearchApi::$statusAge;
    childrenCallbackCheck("child_{$value} consumed", WizardCallbackAction::handle($chatId, 'child_' . $value), true);
    childrenCallbackCheck("child_{$value} stores exact string", MaxSearchApi::getSavedData($chatId)[MaxSearchApi::$statusChild], $value);
    childrenCallbackCheck("child_{$value} preserves existing ages", MaxSearchApi::getSavedData($chatId)[MaxSearchApi::$statusAge], '3, 7');
    childrenCallbackCheck("child_{$value} progresses once", MaxSearchApi::$transitions, [$next]);
    childrenCallbackCheck("child_{$value} asks for exact age count", MaxSearchApi::$ageViews, $value === '0' ? [] : [(int)$value]);
    childrenCallbackCheck("child_{$value} renders once", count($messenger->buttons), 1);
    childrenCallbackCheck("child_{$value} updates once", ChildrenCallbackFakeData::$updates, 1);
    childrenCallbackCheck("child_{$value} does not insert", count(ChildrenCallbackFakeData::$rows), $before);
    childrenCallbackCheck("child_{$value} avoids add", ChildrenCallbackFakeData::$adds, 0);
    childrenCallbackCheck("child_{$value} avoids legacy write", MaxSearchApi::$directSaves, []);
    childrenCallbackCheck("child_{$value} preserves funnel event", MaxSearchApi::$funnelEvents, [['tourists_selected', ['stage'=>'children', 'payload'=>'child_' . $value]]]);
    if ($value !== '0') {
        $text = $messenger->buttons[0][1] ?? '';
        childrenCallbackCheck("child_{$value} renders correct prompt", strpos($text, $value === '1' ? 'Сколько лет ребёнку?' : 'Введите ' . $value . ' возраста') !== false, true);
    }
    childrenCallbackCheck("duplicate child_{$value} consumed", WizardCallbackAction::handle($chatId, 'child_' . $value), true);
    childrenCallbackCheck("duplicate child_{$value} does not update", ChildrenCallbackFakeData::$updates, 1);
    childrenCallbackCheck("duplicate child_{$value} does not render", count($messenger->buttons), 1);
    childrenCallbackCheck("duplicate child_{$value} does not advance", MaxSearchApi::$transitions, [$next]);
    childrenCallbackCheck("duplicate child_{$value} emits no second funnel event", count(MaxSearchApi::$funnelEvents), 1);
}

$messenger = childrenCallbackReset(904);
MaxSearchApi::$currentStatus = MaxSearchApi::$statusStars;
$before = ChildrenCallbackFakeData::$rows;
childrenCallbackCheck('stale child callback consumed', WizardCallbackAction::handle(904, 'child_0'), true);
childrenCallbackCheck('stale child callback preserves all rows', ChildrenCallbackFakeData::$rows, $before);
childrenCallbackCheck('stale callback has no view', $messenger->buttons, []);
childrenCallbackCheck('stale callback has no transition', MaxSearchApi::$transitions, []);
childrenCallbackCheck('stale callback has no funnel event', MaxSearchApi::$funnelEvents, []);

foreach (['0', '3'] as $value) {
    $messenger = childrenCallbackReset(905, false);
    MaxSearchApi::$editMode = 'tourists';
    $before = ChildrenCallbackFakeData::$rows;
    childrenCallbackCheck("missing step child_{$value} consumed", WizardCallbackAction::handle(905, 'child_' . $value), true);
    childrenCallbackCheck("missing step child_{$value} leaves all rows unchanged", ChildrenCallbackFakeData::$rows, $before);
    childrenCallbackCheck("missing step child_{$value} never inserts", ChildrenCallbackFakeData::$adds, 0);
    childrenCallbackCheck("missing step child_{$value} never updates", ChildrenCallbackFakeData::$updates, 0);
    childrenCallbackCheck("missing step child_{$value} never advances", MaxSearchApi::$transitions, []);
    childrenCallbackCheck("missing step child_{$value} never renders", $messenger->buttons, []);
    childrenCallbackCheck("missing step child_{$value} preserves edit mode", MaxSearchApi::$editMode, 'tourists');
}

$messenger = childrenCallbackReset(906);
ChildrenCallbackFakeData::$rows[] = ['ID'=>30, 'UF_CHAT_ID'=>906, 'UF_STATUS'=>MaxSearchApi::$statusStart, 'UF_VALUE'=>''];
$before = ChildrenCallbackFakeData::$rows;
WizardCallbackAction::handle(906, 'child_1');
childrenCallbackCheck('pre-start child step remains unchanged', ChildrenCallbackFakeData::$rows, $before);
childrenCallbackCheck('pre-start step never advances', MaxSearchApi::$transitions, []);
childrenCallbackCheck('pre-start step never renders', $messenger->buttons, []);

$messenger = childrenCallbackReset(907);
EditFlowService::begin(907, 'tourists');
WizardCallbackAction::handle(907, 'child_0');
childrenCallbackCheck('zero children edit stores exact zero', MaxSearchApi::getSavedData(907)[MaxSearchApi::$statusChild], '0');
childrenCallbackCheck('zero children edit retains existing age value', MaxSearchApi::getSavedData(907)[MaxSearchApi::$statusAge], '3, 7');
childrenCallbackCheck('zero children edit retains adults', MaxSearchApi::getSavedData(907)[MaxSearchApi::$statusAdults], '2');
childrenCallbackCheck('zero children edit returns once to check', MaxSearchApi::$transitions, [MaxSearchApi::$statusCheck]);
childrenCallbackCheck('zero children edit skips age prompt', MaxSearchApi::$ageViews, []);
childrenCallbackCheck('zero children edit clears edit mode', MaxSearchApi::$editMode, '');
childrenCallbackCheck('zero children edit renders summary', strpos($messenger->buttons[0][1] ?? '', 'Готово! Проверьте параметры') !== false, true);
childrenCallbackCheck('zero children edit updates one value', ChildrenCallbackFakeData::$updates, 1);
childrenCallbackCheck('zero children edit only writes check generation through legacy API', array_column(MaxSearchApi::$directSaves, 1), [MaxSearchApi::$statusCheck]);

$messenger = childrenCallbackReset(908);
EditFlowService::begin(908, 'tourists');
WizardCallbackAction::handle(908, 'child_3');
childrenCallbackCheck('positive children edit asks ages before finishing', MaxSearchApi::$transitions, [MaxSearchApi::$statusAge]);
childrenCallbackCheck('positive children edit retains edit mode for age message', MaxSearchApi::$editMode, 'tourists');
childrenCallbackCheck('positive children edit passes exact child count', MaxSearchApi::$ageViews, [3]);
childrenCallbackCheck('positive children edit stores exact count', MaxSearchApi::getSavedData(908)[MaxSearchApi::$statusChild], '3');

foreach ([
    ['back_adults', MaxSearchApi::$statusChild, MaxSearchApi::$statusAdults],
    ['back_child', MaxSearchApi::$statusAge, MaxSearchApi::$statusChild],
    ['back_child', MaxSearchApi::$statusStars, MaxSearchApi::$statusChild],
    ['back_stars', MaxSearchApi::$statusMeal, MaxSearchApi::$statusStars],
] as [$payload, $from, $to]) {
    $messenger = childrenCallbackReset(909);
    MaxSearchApi::$currentStatus = $from;
    MaxSearchApi::$editMode = 'tourists';
    $before = ChildrenCallbackFakeData::$rows;
    WizardCallbackAction::handle(909, $payload);
    childrenCallbackCheck("{$payload} from {$from} preserves trip values", ChildrenCallbackFakeData::$rows, $before);
    childrenCallbackCheck("{$payload} from {$from} renders one view", count($messenger->buttons), 1);
    childrenCallbackCheck("{$payload} from {$from} presents target step", MaxSearchApi::$transitions, [$to]);
    childrenCallbackCheck("{$payload} from {$from} makes no value write", MaxSearchApi::$directSaves, []);
    childrenCallbackCheck("{$payload} from {$from} makes no update", ChildrenCallbackFakeData::$updates, 0);
    childrenCallbackCheck("{$payload} from {$from} retains edit mode", MaxSearchApi::$editMode, 'tourists');
}

foreach (range(900, 909) as $chatId) {
    EditFlowService::clearSnapshot($chatId);
    @unlink(InteractionGuard::lockPath($chatId, 'wizard.forward'));
}
echo "\n--------------------------\n";
echo $failed === 0 ? "CHILDREN CALLBACK APPLICATION: OK\n" : "CHILDREN CALLBACK APPLICATION: FAIL ({$failed})\n";
exit($failed === 0 ? 0 : 1);
