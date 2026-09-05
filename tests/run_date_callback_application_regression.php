<?php

declare(strict_types=1);

final class DateCallbackFakeResult
{
    private array $rows;
    private int $index = 0;
    public function __construct(array $rows) { $this->rows = array_values($rows); }
    public function fetch() { return $this->rows[$this->index++] ?? false; }
}

final class DateCallbackFakeEntity
{
    public function getDataClass(): string { return DateCallbackFakeData::class; }
}

final class DateCallbackFakeData
{
    public static array $rows = [];
    public static int $adds = 0;
    public static int $updates = 0;

    public static function getList(array $query): DateCallbackFakeResult
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
        return new DateCallbackFakeResult($rows);
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
eval('namespace Bitrix\\Highloadblock { class HighloadBlockTable { public static function getById($id) { return new \\DateCallbackFakeResult([["ID"=>$id]]); } public static function compileEntity($row) { return new \\DateCallbackFakeEntity(); } } }');

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
    public static int $currentStatus = 73;
    public static string $editMode = '';
    public static array $transitions = [];
    public static array $directSaves = [];
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
        foreach (DateCallbackFakeData::$rows as $row) {
            if (($row['UF_CHAT_ID'] ?? null) == $chatId) $values[$row['UF_STATUS']] = $row['UF_VALUE'] ?? null;
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
}

require_once __DIR__ . '/../actions/callbacks/WizardCallbackAction.php';

final class DateCallbackMessenger implements MessengerInterface
{
    public array $sent = [];
    public array $buttons = [];
    public function send($chatId, string $text): bool { $this->sent[] = [$chatId, $text]; return true; }
    public function sendWithButtons($chatId, string $text, array $buttons): bool { $this->buttons[] = [$chatId, $text, $buttons]; return true; }
    public function sendContactRequest($chatId, string $text, string $manualCallback, string $backCallback): bool { return true; }
}

$failed = 0;
function dateCallbackCheck(string $name, $actual, $expected): void
{
    global $failed;
    if ($actual === $expected) { echo "PASS  {$name}\n"; return; }
    echo "FAIL  {$name}\n";
    echo '      expected: ' . json_encode($expected, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
    echo '      actual:   ' . json_encode($actual, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
    $failed++;
}

function dateCallbackReset(int $chatId, bool $withStep = true): DateCallbackMessenger
{
    EditFlowService::clearSnapshot($chatId);
    @unlink(InteractionGuard::lockPath($chatId, 'date_selection'));
    MaxSearchApi::$currentStatus = (int)MaxSearchApi::$statusDate;
    MaxSearchApi::$editMode = '';
    MaxSearchApi::$transitions = [];
    MaxSearchApi::$directSaves = [];
    MaxSearchApi::$funnelEvents = [];
    DateCallbackFakeData::$adds = 0;
    DateCallbackFakeData::$updates = 0;
    DateCallbackFakeData::$rows = [
        ['ID'=>10, 'UF_CHAT_ID'=>$chatId, 'UF_STATUS'=>MaxSearchApi::$statusStart, 'UF_VALUE'=>''],
    ];
    if ($withStep) {
        DateCallbackFakeData::$rows[] = ['ID'=>20, 'UF_CHAT_ID'=>$chatId, 'UF_STATUS'=>MaxSearchApi::$statusDate, 'UF_VALUE'=>'05.09.2026'];
    }

    $messenger = new DateCallbackMessenger();
    IntegrationRegistry::resetForTests($messenger);
    return $messenger;
}

$dates = ['03.09.2026', '05.09.2026', '31.12.2030', '29.02.2028'];
$chatId = 1400;
foreach ($dates as $date) {
    $messenger = dateCallbackReset($chatId);
    $before = count(DateCallbackFakeData::$rows);
    $payload = 'pick_date_' . $date;
    dateCallbackCheck("{$payload} is consumed", WizardCallbackAction::handle($chatId, $payload), true);
    dateCallbackCheck("{$payload} stores exact date", MaxSearchApi::getSavedData($chatId)[MaxSearchApi::$statusDate] ?? null, $date);
    dateCallbackCheck("{$payload} advances once to check", MaxSearchApi::$transitions, [MaxSearchApi::$statusCheck]);
    dateCallbackCheck("{$payload} renders check once", count($messenger->buttons), 1);
    dateCallbackCheck("{$payload} updates once", DateCallbackFakeData::$updates, 1);
    dateCallbackCheck("{$payload} does not insert", count(DateCallbackFakeData::$rows), $before);
    dateCallbackCheck("{$payload} avoids add", DateCallbackFakeData::$adds, 0);
    dateCallbackCheck("{$payload} direct write is only check generation", array_column(MaxSearchApi::$directSaves, 1), [MaxSearchApi::$statusCheck]);
    dateCallbackCheck("{$payload} preserves search-ready funnel", MaxSearchApi::$funnelEvents, [['search_ready', []]]);

    dateCallbackCheck("duplicate {$payload} is consumed", WizardCallbackAction::handle($chatId, $payload), true);
    dateCallbackCheck("duplicate {$payload} does not update", DateCallbackFakeData::$updates, 1);
    dateCallbackCheck("duplicate {$payload} does not rerender", count($messenger->buttons), 1);
    dateCallbackCheck("duplicate {$payload} does not advance", MaxSearchApi::$transitions, [MaxSearchApi::$statusCheck]);
    $chatId++;
}

dateCallbackReset(1410);
MaxSearchApi::$currentStatus = MaxSearchApi::$statusCheck;
$before = DateCallbackFakeData::$rows;
dateCallbackCheck('stale date callback is consumed', WizardCallbackAction::handle(1410, 'pick_date_31.12.2030'), true);
dateCallbackCheck('stale date callback preserves rows', DateCallbackFakeData::$rows, $before);
dateCallbackCheck('stale date callback never updates', DateCallbackFakeData::$updates, 0);
dateCallbackCheck('stale date callback does not progress', MaxSearchApi::$transitions, []);

dateCallbackReset(1411, false);
$before = DateCallbackFakeData::$rows;
dateCallbackCheck('missing date step is consumed', WizardCallbackAction::handle(1411, 'pick_date_31.12.2030'), true);
dateCallbackCheck('missing date step preserves rows', DateCallbackFakeData::$rows, $before);
dateCallbackCheck('missing date step never inserts', DateCallbackFakeData::$adds, 0);
dateCallbackCheck('missing date step never updates', DateCallbackFakeData::$updates, 0);
dateCallbackCheck('missing date step does not progress', MaxSearchApi::$transitions, []);

dateCallbackReset(1412);
DateCallbackFakeData::$rows[] = ['ID'=>30, 'UF_CHAT_ID'=>1412, 'UF_STATUS'=>MaxSearchApi::$statusStart, 'UF_VALUE'=>''];
$before = DateCallbackFakeData::$rows;
dateCallbackCheck('pre-start date step is consumed', WizardCallbackAction::handle(1412, 'pick_date_31.12.2030'), true);
dateCallbackCheck('pre-start date step preserves rows', DateCallbackFakeData::$rows, $before);
dateCallbackCheck('pre-start date step never updates', DateCallbackFakeData::$updates, 0);
dateCallbackCheck('pre-start date step does not progress', MaxSearchApi::$transitions, []);

$invalid = [
    'pick_date_32.01.2026',
    'pick_date_29.02.2027',
    'pick_date_5.09.2026',
    'pick_date_05.9.2026',
    'pick_date_2026-09-05',
    'pick_date_05.09.2026_extra',
    'pick_date_',
];
foreach ($invalid as $index => $payload) {
    $invalidChatId = 1420 + $index;
    dateCallbackReset($invalidChatId);
    $before = DateCallbackFakeData::$rows;
    dateCallbackCheck("invalid {$payload} is consumed", WizardCallbackAction::handle($invalidChatId, $payload), true);
    dateCallbackCheck("invalid {$payload} preserves rows", DateCallbackFakeData::$rows, $before);
    dateCallbackCheck("invalid {$payload} never updates", DateCallbackFakeData::$updates, 0);
    dateCallbackCheck("invalid {$payload} never progresses", MaxSearchApi::$transitions, []);
}

$messenger = dateCallbackReset(1430);
EditFlowService::begin(1430, 'date');
dateCallbackCheck('date edit payload is consumed', WizardCallbackAction::handle(1430, 'pick_date_31.12.2030'), true);
dateCallbackCheck('date edit stores exact value', MaxSearchApi::getSavedData(1430)[MaxSearchApi::$statusDate] ?? null, '31.12.2030');
dateCallbackCheck('date edit returns once to check', MaxSearchApi::$transitions, [MaxSearchApi::$statusCheck]);
dateCallbackCheck('date edit renders check once', count($messenger->buttons), 1);
dateCallbackCheck('date edit clears edit mode', MaxSearchApi::$editMode, '');
dateCallbackCheck('date edit updates one date row', DateCallbackFakeData::$updates, 1);
dateCallbackCheck('date edit logs search-ready once', MaxSearchApi::$funnelEvents, [['search_ready', []]]);
dateCallbackCheck('date edit direct write is only check generation', array_column(MaxSearchApi::$directSaves, 1), [MaxSearchApi::$statusCheck]);

$source = (string)file_get_contents(__DIR__ . '/../actions/callbacks/WizardCallbackAction.php');
dateCallbackCheck('action parses callback through date value contract', substr_count($source, 'DateValueContract::fromCallbackPayload($q)') === 1, true);
dateCallbackCheck('action applies date through update-only boundary', strpos($source, 'MaxSearchApi::$statusDate,') !== false && strpos($source, '$date') !== false, true);
dateCallbackCheck('action has no direct date callback write', strpos($source, "MaxSearchApi::saveLastValue(\$chatId, MaxSearchApi::\$statusDate, str_replace('pick_date_', '', \$q))") === false, true);
dateCallbackCheck('action keeps dedicated expected-status guard', strpos($source, "'date_selection'") !== false && strpos($source, '(int)MaxSearchApi::$statusDate') !== false, true);

foreach (array_merge(range(1400, 1403), [1410, 1411, 1412], range(1420, 1426), [1430]) as $cleanupChatId) {
    EditFlowService::clearSnapshot($cleanupChatId);
    @unlink(InteractionGuard::lockPath($cleanupChatId, 'date_selection'));
}

echo "\n--------------------------------\n";
echo $failed === 0 ? "DATE CALLBACK APPLICATION: OK\n" : "DATE CALLBACK APPLICATION: FAIL ({$failed})\n";
exit($failed === 0 ? 0 : 1);
