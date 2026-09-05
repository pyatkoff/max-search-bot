<?php

declare(strict_types=1);

final class DateFreeTextFakeResult
{
    private array $rows;
    private int $index = 0;
    public function __construct(array $rows) { $this->rows = array_values($rows); }
    public function fetch() { return $this->rows[$this->index++] ?? false; }
}

final class DateFreeTextFakeEntity
{
    public function getDataClass(): string { return DateFreeTextFakeData::class; }
}

final class DateFreeTextFakeData
{
    public static array $rows = [];
    public static int $adds = 0;
    public static int $updates = 0;

    public static function getList(array $query): DateFreeTextFakeResult
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
        return new DateFreeTextFakeResult($rows);
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
eval('namespace Bitrix\\Highloadblock { class HighloadBlockTable { public static function getById($id) { return new \\DateFreeTextFakeResult([["ID"=>$id]]); } public static function compileEntity($row) { return new \\DateFreeTextFakeEntity(); } } }');

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
    public static array $funnelEvents = [];

    public static function deletePrevMessage($chatId, $withButtons = false): void {}
    public static function setStatus($chatId, $status): void { self::$transitions[] = (int)$status; }
    public static function getEditMode($chatId): string { return self::$editMode; }
    public static function setEditMode($chatId, $field): void { self::$editMode = (string)$field; }
    public static function getSavedData($chatId): array
    {
        $values = [];
        foreach (DateFreeTextFakeData::$rows as $row) {
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

require_once __DIR__ . '/../handlers/StateMessageHandler.php';

final class DateFreeTextMessenger implements MessengerInterface
{
    public array $sent = [];
    public array $buttons = [];
    public function send($chatId, string $text): bool { $this->sent[] = [$chatId, $text]; return true; }
    public function sendWithButtons($chatId, string $text, array $buttons): bool { $this->buttons[] = [$chatId, $text, $buttons]; return true; }
    public function sendContactRequest($chatId, string $text, string $manualCallback, string $backCallback): bool { return true; }
}

$failed = 0;
function dateFreeTextCheck(string $name, $actual, $expected): void
{
    global $failed;
    if ($actual === $expected) { echo "PASS  {$name}\n"; return; }
    echo "FAIL  {$name}\n";
    echo '      expected: ' . json_encode($expected, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
    echo '      actual:   ' . json_encode($actual, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
    $failed++;
}

function dateFreeTextReset(int $chatId, bool $withStep = true, bool $preStart = false): DateFreeTextMessenger
{
    EditFlowService::clearSnapshot($chatId);
    AiDateHandler::clear($chatId);
    MaxSearchApi::$editMode = '';
    MaxSearchApi::$transitions = [];
    MaxSearchApi::$directSaves = [];
    MaxSearchApi::$funnelEvents = [];
    DateFreeTextFakeData::$adds = 0;
    DateFreeTextFakeData::$updates = 0;
    DateFreeTextFakeData::$rows = $preStart
        ? [
            ['ID'=>5, 'UF_CHAT_ID'=>$chatId, 'UF_STATUS'=>MaxSearchApi::$statusDate, 'UF_VALUE'=>'05.09.2026'],
            ['ID'=>10, 'UF_CHAT_ID'=>$chatId, 'UF_STATUS'=>MaxSearchApi::$statusStart, 'UF_VALUE'=>''],
        ]
        : [
            ['ID'=>10, 'UF_CHAT_ID'=>$chatId, 'UF_STATUS'=>MaxSearchApi::$statusStart, 'UF_VALUE'=>''],
        ];
    if ($withStep && !$preStart) {
        DateFreeTextFakeData::$rows[] = ['ID'=>20, 'UF_CHAT_ID'=>$chatId, 'UF_STATUS'=>MaxSearchApi::$statusDate, 'UF_VALUE'=>'05.09.2026'];
    }
    $messenger = new DateFreeTextMessenger();
    IntegrationRegistry::resetForTests($messenger);
    return $messenger;
}

$cases = [
    ['past representation', 1500, '03.09.2026', '03.09.2026'],
    ['present representation', 1501, '05.09.2026', '05.09.2026'],
    ['future representation', 1502, '31.12.2030', '31.12.2030'],
    ['leap day', 1503, '29.02.2028', '29.02.2028'],
];
foreach ($cases as [$label, $chatId, $text, $expected]) {
    $messenger = dateFreeTextReset($chatId);
    $before = count(DateFreeTextFakeData::$rows);
    StateMessageHandler::handle(['text'=>$text], $chatId, MaxSearchApi::$statusDate);
    dateFreeTextCheck("{$label} stores exact value", MaxSearchApi::getSavedData($chatId)[MaxSearchApi::$statusDate] ?? null, $expected);
    dateFreeTextCheck("{$label} updates once", DateFreeTextFakeData::$updates, 1);
    dateFreeTextCheck("{$label} never inserts", count(DateFreeTextFakeData::$rows), $before);
    dateFreeTextCheck("{$label} avoids add", DateFreeTextFakeData::$adds, 0);
    dateFreeTextCheck("{$label} advances once to check", MaxSearchApi::$transitions, [MaxSearchApi::$statusCheck]);
    dateFreeTextCheck("{$label} renders check once", count($messenger->buttons), 1);
    dateFreeTextCheck("{$label} direct write is only check generation", array_column(MaxSearchApi::$directSaves, 1), [MaxSearchApi::$statusCheck]);
}

$messenger = dateFreeTextReset(1510);
AiDateHandler::rememberMonth(1510, 12, 2030);
StateMessageHandler::handle(['text'=>'14'], 1510, MaxSearchApi::$statusDate);
dateFreeTextCheck('pending short date stores resolved date', MaxSearchApi::getSavedData(1510)[MaxSearchApi::$statusDate] ?? null, '14.12.2030');
dateFreeTextCheck('pending short date updates once', DateFreeTextFakeData::$updates, 1);
dateFreeTextCheck('pending short date returns to check', MaxSearchApi::$transitions, [MaxSearchApi::$statusCheck]);
dateFreeTextCheck('pending short date renders check once', count($messenger->buttons), 1);
dateFreeTextCheck('pending short date clears context', PendingMonthStore::get(1510), []);

$messenger = dateFreeTextReset(1511);
$before = DateFreeTextFakeData::$rows;
StateMessageHandler::handle(['text'=>'декабрь 2030'], 1511, MaxSearchApi::$statusDate);
dateFreeTextCheck('month-only preserves date rows', DateFreeTextFakeData::$rows, $before);
dateFreeTextCheck('month-only makes no value update', DateFreeTextFakeData::$updates, 0);
dateFreeTextCheck('month-only renders calendar once', count($messenger->buttons), 1);
dateFreeTextCheck('month-only keeps date status', MaxSearchApi::$transitions, [MaxSearchApi::$statusDate]);
dateFreeTextCheck('month-only stores pending month', PendingMonthStore::get(1511), ['month'=>12, 'year'=>2030]);

$messenger = dateFreeTextReset(1512);
$before = DateFreeTextFakeData::$rows;
StateMessageHandler::handle(['text'=>'не знаю'], 1512, MaxSearchApi::$statusDate);
dateFreeTextCheck('invalid input preserves rows', DateFreeTextFakeData::$rows, $before);
dateFreeTextCheck('invalid input does not update', DateFreeTextFakeData::$updates, 0);
dateFreeTextCheck('invalid input does not progress', MaxSearchApi::$transitions, []);
dateFreeTextCheck('invalid input sends existing message', $messenger->sent[0][1] ?? '', 'Не получилось распознать дату. Напишите, например: 8 ноября, 08.11 или выберите дату в календаре.');

foreach ([['missing', 1520, false], ['pre-start', 1521, true]] as [$label, $chatId, $preStart]) {
    $messenger = dateFreeTextReset($chatId, $label !== 'missing', $preStart);
    $before = DateFreeTextFakeData::$rows;
    StateMessageHandler::handle(['text'=>'31.12.2030'], $chatId, MaxSearchApi::$statusDate);
    dateFreeTextCheck("{$label} date step preserves rows", DateFreeTextFakeData::$rows, $before);
    dateFreeTextCheck("{$label} date step does not update", DateFreeTextFakeData::$updates, 0);
    dateFreeTextCheck("{$label} date step does not insert", DateFreeTextFakeData::$adds, 0);
    dateFreeTextCheck("{$label} date step does not progress", MaxSearchApi::$transitions, []);
    dateFreeTextCheck("{$label} date step renders no view", count($messenger->buttons), 0);
    dateFreeTextCheck("{$label} date step sends no validation", $messenger->sent, []);
}

$messenger = dateFreeTextReset(1530);
EditFlowService::begin(1530, 'date');
StateMessageHandler::handle(['text'=>'31.12.2030'], 1530, MaxSearchApi::$statusDate);
dateFreeTextCheck('date edit stores exact value', MaxSearchApi::getSavedData(1530)[MaxSearchApi::$statusDate] ?? null, '31.12.2030');
dateFreeTextCheck('date edit updates once', DateFreeTextFakeData::$updates, 1);
dateFreeTextCheck('date edit returns once to check', MaxSearchApi::$transitions, [MaxSearchApi::$statusCheck]);
dateFreeTextCheck('date edit renders check once', count($messenger->buttons), 1);
dateFreeTextCheck('date edit clears mode', MaxSearchApi::$editMode, '');
dateFreeTextCheck('date edit direct write is only check generation', array_column(MaxSearchApi::$directSaves, 1), [MaxSearchApi::$statusCheck]);

$source = (string)file_get_contents(__DIR__ . '/../handlers/StateMessageHandler.php');
$dateStart = strpos($source, 'elseif($status==MaxSearchApi::$statusDate)');
$phoneStart = $dateStart === false ? false : strpos($source, 'elseif($status==MaxSearchApi::$statusPhone)', $dateStart);
$dateSource = $dateStart === false || $phoneStart === false ? '' : substr($source, $dateStart, $phoneStart - $dateStart);
dateFreeTextCheck('handler preserves pending-before-full resolution order', strpos($dateSource, 'AiDateHandler::resolvePendingShortDate') < strpos($dateSource, 'AiDateHandler::rememberMonthFromText'), true);
dateFreeTextCheck('handler projects through one date value contract', substr_count($dateSource, 'DateValueContract::fromStorageValue') === 1, true);
dateFreeTextCheck('handler applies through one update-only boundary', substr_count($dateSource, 'ExistingWizardStepApplicationService::apply(') === 1, true);
dateFreeTextCheck('handler no longer directly writes date', strpos($dateSource, 'MaxSearchApi::saveLastValue') === false, true);
dateFreeTextCheck('handler adds no message-path interaction guard', strpos($dateSource, 'InteractionGuard') === false, true);

foreach (array_merge(range(1500, 1503), [1510, 1511, 1512, 1520, 1521, 1530]) as $cleanupChatId) {
    EditFlowService::clearSnapshot($cleanupChatId);
    AiDateHandler::clear($cleanupChatId);
}

echo "\n--------------------------------\n";
echo $failed === 0 ? "DATE FREE-TEXT APPLICATION: OK\n" : "DATE FREE-TEXT APPLICATION: FAIL ({$failed})\n";
exit($failed === 0 ? 0 : 1);
