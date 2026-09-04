<?php

declare(strict_types=1);

final class ChildAgeFreeTextFakeResult
{
    private array $rows;
    private int $index = 0;
    public function __construct(array $rows) { $this->rows = array_values($rows); }
    public function fetch() { return $this->rows[$this->index++] ?? false; }
}

final class ChildAgeFreeTextFakeEntity
{
    public function getDataClass(): string { return ChildAgeFreeTextFakeData::class; }
}

final class ChildAgeFreeTextFakeData
{
    public static array $rows = [];
    public static int $adds = 0;
    public static int $updates = 0;

    public static function getList(array $query): ChildAgeFreeTextFakeResult
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
        return new ChildAgeFreeTextFakeResult($rows);
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
eval('namespace Bitrix\\Highloadblock { class HighloadBlockTable { public static function getById($id) { return new \\ChildAgeFreeTextFakeResult([["ID"=>$id]]); } public static function compileEntity($row) { return new \\ChildAgeFreeTextFakeEntity(); } } }');

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
    public static string $editMode = '';
    public static array $transitions = [];
    public static array $directSaves = [];

    public static function deletePrevMessage($chatId, $withButtons = false): void {}
    public static function setStatus($chatId, $status): void { self::$transitions[] = (int)$status; }
    public static function getEditMode($chatId): string { return self::$editMode; }
    public static function setEditMode($chatId, $field): void { self::$editMode = (string)$field; }
    public static function getLastValue($chatId, $status)
    {
        $rows = array_reverse(ChildAgeFreeTextFakeData::$rows);
        foreach ($rows as $row) {
            if (($row['UF_CHAT_ID'] ?? null) == $chatId && ($row['UF_STATUS'] ?? null) == $status) return $row['UF_VALUE'] ?? null;
        }
        return false;
    }
    public static function getSavedData($chatId): array
    {
        $values = [];
        foreach (ChildAgeFreeTextFakeData::$rows as $row) {
            if (($row['UF_CHAT_ID'] ?? null) == $chatId) $values[$row['UF_STATUS']] = $row['UF_VALUE'];
        }
        return $values;
    }
    public static function formatSavedData(array $data): array { return []; }
    public static function funnelLog($chatId, $event, array $data = []): void {}
    public static function saveLastValue($chatId, $status, $value): bool
    {
        self::$directSaves[] = [$chatId, $status, $value];
        return true;
    }
    public static function showStarsButtons($chatId): bool { return DialogueView::stars($chatId); }
}

require_once __DIR__ . '/../handlers/StateMessageHandler.php';

final class ChildAgeFreeTextMessenger implements MessengerInterface
{
    public array $sent = [];
    public array $buttons = [];
    public function send($chatId, string $text): bool { $this->sent[] = [$chatId, $text]; return true; }
    public function sendWithButtons($chatId, string $text, array $buttons): bool { $this->buttons[] = [$chatId, $text, $buttons]; return true; }
    public function sendContactRequest($chatId, string $text, string $manualCallback, string $backCallback): bool { return true; }
}

$failed = 0;
function childAgeFreeTextCheck(string $name, $actual, $expected): void
{
    global $failed;
    if ($actual === $expected) { echo "PASS  {$name}\n"; return; }
    echo "FAIL  {$name}\n";
    echo '      expected: ' . json_encode($expected, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
    echo '      actual:   ' . json_encode($actual, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
    $failed++;
}

function childAgeFreeTextReset(int $chatId, int $children = 2, bool $withStep = true, bool $preStart = false): ChildAgeFreeTextMessenger
{
    EditFlowService::clearSnapshot($chatId);
    MaxSearchApi::$editMode = '';
    MaxSearchApi::$transitions = [];
    MaxSearchApi::$directSaves = [];
    ChildAgeFreeTextFakeData::$adds = 0;
    ChildAgeFreeTextFakeData::$updates = 0;
    ChildAgeFreeTextFakeData::$rows = $preStart
        ? [
            ['ID'=>5, 'UF_CHAT_ID'=>$chatId, 'UF_STATUS'=>MaxSearchApi::$statusAge, 'UF_VALUE'=>'4, 8'],
            ['ID'=>10, 'UF_CHAT_ID'=>$chatId, 'UF_STATUS'=>MaxSearchApi::$statusStart, 'UF_VALUE'=>''],
            ['ID'=>11, 'UF_CHAT_ID'=>$chatId, 'UF_STATUS'=>MaxSearchApi::$statusAdults, 'UF_VALUE'=>'2'],
            ['ID'=>12, 'UF_CHAT_ID'=>$chatId, 'UF_STATUS'=>MaxSearchApi::$statusChild, 'UF_VALUE'=>(string)$children],
        ]
        : [
            ['ID'=>10, 'UF_CHAT_ID'=>$chatId, 'UF_STATUS'=>MaxSearchApi::$statusStart, 'UF_VALUE'=>''],
            ['ID'=>11, 'UF_CHAT_ID'=>$chatId, 'UF_STATUS'=>MaxSearchApi::$statusAdults, 'UF_VALUE'=>'2'],
            ['ID'=>12, 'UF_CHAT_ID'=>$chatId, 'UF_STATUS'=>MaxSearchApi::$statusChild, 'UF_VALUE'=>(string)$children],
        ];
    if ($withStep && !$preStart) {
        ChildAgeFreeTextFakeData::$rows[] = ['ID'=>20, 'UF_CHAT_ID'=>$chatId, 'UF_STATUS'=>MaxSearchApi::$statusAge, 'UF_VALUE'=>'4, 8'];
    }
    $messenger = new ChildAgeFreeTextMessenger();
    IntegrationRegistry::resetForTests($messenger);
    return $messenger;
}

$accepted = [
    ['6', 1, '6'],
    ['0', 1, '0'],
    ['3 7', 2, '3, 7'],
    ['3,7', 2, '3, 7'],
    ['3, 7', 2, '3, 7'],
    ['3 4, 7', 2, '3, 7'],
    ['3, 7 8', 2, '3, 7'],
];
foreach ($accepted as $index => [$text, $children, $stored]) {
    $chatId = 1100 + $index;
    $messenger = childAgeFreeTextReset($chatId, $children);
    $before = count(ChildAgeFreeTextFakeData::$rows);
    StateMessageHandler::handle(['text'=>$text], $chatId, MaxSearchApi::$statusAge);
    childAgeFreeTextCheck("{$text} stores exact projection", MaxSearchApi::getSavedData($chatId)[MaxSearchApi::$statusAge] ?? null, $stored);
    childAgeFreeTextCheck("{$text} advances to stars", MaxSearchApi::$transitions, [MaxSearchApi::$statusStars]);
    childAgeFreeTextCheck("{$text} renders stars once", count($messenger->buttons), 1);
    childAgeFreeTextCheck("{$text} updates once", ChildAgeFreeTextFakeData::$updates, 1);
    childAgeFreeTextCheck("{$text} does not insert", count(ChildAgeFreeTextFakeData::$rows), $before);
    childAgeFreeTextCheck("{$text} never calls add", ChildAgeFreeTextFakeData::$adds, 0);
    childAgeFreeTextCheck("{$text} avoids legacy direct write", MaxSearchApi::$directSaves, []);
}

foreach (['3 и 7', '3;7', '3/7', '3-7', "3\t7", '3  7', '3', '3, 18'] as $index => $text) {
    $chatId = 1200 + $index;
    $messenger = childAgeFreeTextReset($chatId);
    StateMessageHandler::handle(['text'=>$text], $chatId, MaxSearchApi::$statusAge);
    childAgeFreeTextCheck("{$text} keeps existing age value", MaxSearchApi::getSavedData($chatId)[MaxSearchApi::$statusAge] ?? null, '4, 8');
    childAgeFreeTextCheck("{$text} sends one validation message", count($messenger->sent), 1);
    childAgeFreeTextCheck("{$text} does not advance", MaxSearchApi::$transitions, []);
    childAgeFreeTextCheck("{$text} renders no next view", $messenger->buttons, []);
    childAgeFreeTextCheck("{$text} does not update", ChildAgeFreeTextFakeData::$updates, 0);
}

$messenger = childAgeFreeTextReset(1300, 1);
StateMessageHandler::handle(['text'=>'18'], 1300, MaxSearchApi::$statusAge);
childAgeFreeTextCheck('single-child error keeps exact singular prompt', $messenger->sent[0][1] ?? '', 'К сожалению возраст ребенка указан неверно. Пожалуйста, введите 1 число в диапазоне от 0 до 17.');

foreach ([['missing', 1301, false], ['pre-start', 1302, true]] as [$label, $chatId, $preStart]) {
    $messenger = childAgeFreeTextReset($chatId, 2, $label !== 'missing', $preStart);
    $before = ChildAgeFreeTextFakeData::$rows;
    StateMessageHandler::handle(['text'=>'5, 12'], $chatId, MaxSearchApi::$statusAge);
    childAgeFreeTextCheck("{$label} age step preserves rows", ChildAgeFreeTextFakeData::$rows, $before);
    childAgeFreeTextCheck("{$label} age step does not update", ChildAgeFreeTextFakeData::$updates, 0);
    childAgeFreeTextCheck("{$label} age step does not insert", ChildAgeFreeTextFakeData::$adds, 0);
    childAgeFreeTextCheck("{$label} age step does not advance", MaxSearchApi::$transitions, []);
    childAgeFreeTextCheck("{$label} age step renders nothing", $messenger->buttons, []);
    childAgeFreeTextCheck("{$label} age step sends no validation error", $messenger->sent, []);
}

$messenger = childAgeFreeTextReset(1400);
EditFlowService::begin(1400, 'tourists');
StateMessageHandler::handle(['text'=>'5, 12'], 1400, MaxSearchApi::$statusAge);
childAgeFreeTextCheck('tourists edit stores exact ages', MaxSearchApi::getSavedData(1400)[MaxSearchApi::$statusAge] ?? null, '5, 12');
childAgeFreeTextCheck('tourists edit returns to check once', MaxSearchApi::$transitions, [MaxSearchApi::$statusCheck]);
childAgeFreeTextCheck('tourists edit renders check once', count($messenger->buttons), 1);
childAgeFreeTextCheck('tourists edit clears mode', MaxSearchApi::$editMode, '');
childAgeFreeTextCheck('tourists edit updates only age value', ChildAgeFreeTextFakeData::$updates, 1);
childAgeFreeTextCheck('tourists edit legacy write is check generation only', array_column(MaxSearchApi::$directSaves, 1), [MaxSearchApi::$statusCheck]);

$source = (string)file_get_contents(__DIR__ . '/../handlers/StateMessageHandler.php');
childAgeFreeTextCheck('handler parses age through executable legacy contract', substr_count($source, 'ChildAgeValueContract::parseLegacyInput') === 1, true);
childAgeFreeTextCheck('handler projects age through executable storage contract', substr_count($source, 'ChildAgeValueContract::toStorage') === 1, true);
$ageStart = strpos($source, 'elseif($status==MaxSearchApi::$statusAge)');
$nightsStart = $ageStart === false ? false : strpos($source, 'elseif($status==MaxSearchApi::$statusNights)', $ageStart);
$ageSource = $ageStart === false || $nightsStart === false ? '' : substr($source, $ageStart, $nightsStart - $ageStart);
childAgeFreeTextCheck('handler applies age through one update-only boundary', substr_count($ageSource, 'ExistingWizardStepApplicationService::apply(') === 1, true);
childAgeFreeTextCheck('handler no longer writes age directly', strpos($source, 'MaxSearchApi::saveLastValue($chat_id,MaxSearchApi::$statusAge') === false, true);

EditFlowService::clearSnapshot(1400);
echo "\n--------------------------\n";
echo $failed === 0 ? "CHILD-AGE FREE-TEXT APPLICATION: OK\n" : "CHILD-AGE FREE-TEXT APPLICATION: FAIL ({$failed})\n";
exit($failed === 0 ? 0 : 1);
