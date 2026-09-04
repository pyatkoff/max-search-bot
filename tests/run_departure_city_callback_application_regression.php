<?php

declare(strict_types=1);

final class DepartureCityCallbackFakeResult
{
    private array $rows;
    private int $index = 0;
    public function __construct(array $rows) { $this->rows = array_values($rows); }
    public function fetch() { return $this->rows[$this->index++] ?? false; }
}

final class DepartureCityCallbackFakeEntity
{
    public function getDataClass(): string { return DepartureCityCallbackFakeData::class; }
}

final class DepartureCityCallbackFakeData
{
    public static array $rows = [];
    public static int $adds = 0;
    public static int $updates = 0;

    public static function getList(array $query): DepartureCityCallbackFakeResult
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
        return new DepartureCityCallbackFakeResult($rows);
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
eval('namespace Bitrix\\Highloadblock { class HighloadBlockTable { public static function getById($id) { return new \\DepartureCityCallbackFakeResult([["ID"=>$id]]); } public static function compileEntity($row) { return new \\DepartureCityCallbackFakeEntity(); } } }');

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
    public static int $currentStatus = 65;
    public static string $editMode = '';
    public static array $transitions = [];
    public static array $directSaves = [];
    public static array $countryViews = [];
    public static array $manualCityViews = [];
    public static array $cityViews = [];
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
        foreach (DepartureCityCallbackFakeData::$rows as $row) {
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
    public static function showCountryButtons($chatId): bool
    {
        self::$countryViews[] = $chatId;
        self::setStatus($chatId, self::$statusContryChoose);
        return true;
    }
    public static function showCityOtherButtons($chatId): bool
    {
        self::$manualCityViews[] = $chatId;
        self::setStatus($chatId, self::$statusCityChoose);
        return true;
    }
    public static function showCityButtons($chatId): bool
    {
        self::$cityViews[] = $chatId;
        self::setStatus($chatId, self::$statusCityChoose);
        return true;
    }
}

require_once __DIR__ . '/../actions/callbacks/WizardCallbackAction.php';

final class DepartureCityCallbackMessenger implements MessengerInterface
{
    public array $sent = [];
    public array $buttons = [];
    public function send($chatId, string $text): bool { $this->sent[] = [$chatId, $text]; return true; }
    public function sendWithButtons($chatId, string $text, array $buttons): bool { $this->buttons[] = [$chatId, $text, $buttons]; return true; }
    public function sendContactRequest($chatId, string $text, string $manualCallback, string $backCallback): bool { return true; }
}

$failed = 0;
function departureCityCallbackCheck(string $name, $actual, $expected): void
{
    global $failed;
    if ($actual === $expected) { echo "PASS  {$name}\n"; return; }
    echo "FAIL  {$name}\n";
    echo '      expected: ' . json_encode($expected, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
    echo '      actual:   ' . json_encode($actual, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
    $failed++;
}

function departureCityCallbackReset(int $chatId, bool $withStep = true): DepartureCityCallbackMessenger
{
    EditFlowService::clearSnapshot($chatId);
    @unlink(InteractionGuard::lockPath($chatId, 'wizard.forward'));
    MaxSearchApi::$currentStatus = (int)MaxSearchApi::$statusCityChoose;
    MaxSearchApi::$editMode = '';
    MaxSearchApi::$transitions = [];
    MaxSearchApi::$directSaves = [];
    MaxSearchApi::$countryViews = [];
    MaxSearchApi::$manualCityViews = [];
    MaxSearchApi::$cityViews = [];
    MaxSearchApi::$funnelEvents = [];
    DepartureCityCallbackFakeData::$adds = 0;
    DepartureCityCallbackFakeData::$updates = 0;
    DepartureCityCallbackFakeData::$rows = [
        ['ID'=>10, 'UF_CHAT_ID'=>$chatId, 'UF_STATUS'=>MaxSearchApi::$statusStart, 'UF_VALUE'=>''],
    ];
    if ($withStep) {
        DepartureCityCallbackFakeData::$rows[] = ['ID'=>20, 'UF_CHAT_ID'=>$chatId, 'UF_STATUS'=>MaxSearchApi::$statusCityChoose, 'UF_VALUE'=>'5'];
    }

    $messenger = new DepartureCityCallbackMessenger();
    IntegrationRegistry::resetForTests($messenger);
    return $messenger;
}

$payloads = [
    'pick_city_1' => '1',
    'pick_city_5' => '5',
    'pick_city_10' => '10',
    'pick_city_12' => '12',
    'pick_city_99' => '99',
    'pick_city_347' => '347',
];
$chatId = 1100;
foreach ($payloads as $payload => $expected) {
    $messenger = departureCityCallbackReset($chatId);
    $before = count(DepartureCityCallbackFakeData::$rows);
    departureCityCallbackCheck("{$payload} is consumed", WizardCallbackAction::handle($chatId, $payload), true);
    departureCityCallbackCheck("{$payload} stores exact string", MaxSearchApi::getSavedData($chatId)[MaxSearchApi::$statusCityChoose] ?? null, $expected);
    departureCityCallbackCheck("{$payload} advances once to country", MaxSearchApi::$transitions, [MaxSearchApi::$statusContryChoose]);
    departureCityCallbackCheck("{$payload} renders country once", MaxSearchApi::$countryViews, [$chatId]);
    departureCityCallbackCheck("{$payload} updates once", DepartureCityCallbackFakeData::$updates, 1);
    departureCityCallbackCheck("{$payload} does not insert", count(DepartureCityCallbackFakeData::$rows), $before);
    departureCityCallbackCheck("{$payload} avoids add", DepartureCityCallbackFakeData::$adds, 0);
    departureCityCallbackCheck("{$payload} avoids legacy direct write", MaxSearchApi::$directSaves, []);

    departureCityCallbackCheck("duplicate {$payload} is consumed", WizardCallbackAction::handle($chatId, $payload), true);
    departureCityCallbackCheck("duplicate {$payload} does not update", DepartureCityCallbackFakeData::$updates, 1);
    departureCityCallbackCheck("duplicate {$payload} does not render", MaxSearchApi::$countryViews, [$chatId]);
    departureCityCallbackCheck("duplicate {$payload} does not advance", MaxSearchApi::$transitions, [MaxSearchApi::$statusContryChoose]);
    $chatId++;
}

$messenger = departureCityCallbackReset(1110);
MaxSearchApi::$currentStatus = MaxSearchApi::$statusContryChoose;
$before = DepartureCityCallbackFakeData::$rows;
departureCityCallbackCheck('stale city callback is consumed', WizardCallbackAction::handle(1110, 'pick_city_1'), true);
departureCityCallbackCheck('stale city callback preserves rows', DepartureCityCallbackFakeData::$rows, $before);
departureCityCallbackCheck('stale city callback makes no update', DepartureCityCallbackFakeData::$updates, 0);
departureCityCallbackCheck('stale city callback renders no country', MaxSearchApi::$countryViews, []);
departureCityCallbackCheck('stale city callback makes no transition', MaxSearchApi::$transitions, []);

$messenger = departureCityCallbackReset(1111, false);
$before = DepartureCityCallbackFakeData::$rows;
departureCityCallbackCheck('missing city step is consumed', WizardCallbackAction::handle(1111, 'pick_city_5'), true);
departureCityCallbackCheck('missing city step preserves rows', DepartureCityCallbackFakeData::$rows, $before);
departureCityCallbackCheck('missing city step never inserts', DepartureCityCallbackFakeData::$adds, 0);
departureCityCallbackCheck('missing city step never updates', DepartureCityCallbackFakeData::$updates, 0);
departureCityCallbackCheck('missing city step never renders', MaxSearchApi::$countryViews, []);
departureCityCallbackCheck('missing city step never advances', MaxSearchApi::$transitions, []);

$messenger = departureCityCallbackReset(1112);
DepartureCityCallbackFakeData::$rows[] = ['ID'=>30, 'UF_CHAT_ID'=>1112, 'UF_STATUS'=>MaxSearchApi::$statusStart, 'UF_VALUE'=>''];
$before = DepartureCityCallbackFakeData::$rows;
departureCityCallbackCheck('pre-start city step is consumed', WizardCallbackAction::handle(1112, 'pick_city_10'), true);
departureCityCallbackCheck('pre-start city step preserves rows', DepartureCityCallbackFakeData::$rows, $before);
departureCityCallbackCheck('pre-start city step never updates', DepartureCityCallbackFakeData::$updates, 0);
departureCityCallbackCheck('pre-start city step never renders', MaxSearchApi::$countryViews, []);
departureCityCallbackCheck('pre-start city step never advances', MaxSearchApi::$transitions, []);

foreach (['pick_city_0', 'pick_city_-1', 'pick_city_1.5', 'pick_city_01', 'pick_city_', 'pick_city_1_extra'] as $index => $payload) {
    $invalidChatId = 1120 + $index;
    departureCityCallbackReset($invalidChatId);
    $before = DepartureCityCallbackFakeData::$rows;
    departureCityCallbackCheck("invalid {$payload} is consumed", WizardCallbackAction::handle($invalidChatId, $payload), true);
    departureCityCallbackCheck("invalid {$payload} preserves rows", DepartureCityCallbackFakeData::$rows, $before);
    departureCityCallbackCheck("invalid {$payload} never updates", DepartureCityCallbackFakeData::$updates, 0);
    departureCityCallbackCheck("invalid {$payload} never renders", MaxSearchApi::$countryViews, []);
    departureCityCallbackCheck("invalid {$payload} never advances", MaxSearchApi::$transitions, []);
}

$messenger = departureCityCallbackReset(1130);
$before = DepartureCityCallbackFakeData::$rows;
departureCityCallbackCheck('manual city payload is consumed', WizardCallbackAction::handle(1130, 'pick_city_other'), true);
departureCityCallbackCheck('manual city payload preserves rows', DepartureCityCallbackFakeData::$rows, $before);
departureCityCallbackCheck('manual city payload renders manual city once', MaxSearchApi::$manualCityViews, [1130]);
departureCityCallbackCheck('manual city payload makes no update', DepartureCityCallbackFakeData::$updates, 0);

$messenger = departureCityCallbackReset(1131);
EditFlowService::begin(1131, 'city');
departureCityCallbackCheck('city edit payload is consumed', WizardCallbackAction::handle(1131, 'pick_city_99'), true);
departureCityCallbackCheck('city edit stores exact no-flight value', MaxSearchApi::getSavedData(1131)[MaxSearchApi::$statusCityChoose] ?? null, '99');
departureCityCallbackCheck('city edit returns once to check', MaxSearchApi::$transitions, [MaxSearchApi::$statusCheck]);
departureCityCallbackCheck('city edit renders check once', count($messenger->buttons), 1);
departureCityCallbackCheck('city edit clears edit mode', MaxSearchApi::$editMode, '');
departureCityCallbackCheck('city edit does not render country', MaxSearchApi::$countryViews, []);
departureCityCallbackCheck('city edit updates one city row', DepartureCityCallbackFakeData::$updates, 1);
departureCityCallbackCheck('city edit direct write is only check generation', array_column(MaxSearchApi::$directSaves, 1), [MaxSearchApi::$statusCheck]);

$messenger = departureCityCallbackReset(1132);
MaxSearchApi::$currentStatus = MaxSearchApi::$statusAdults;
$before = DepartureCityCallbackFakeData::$rows;
departureCityCallbackCheck('back to country is consumed', WizardCallbackAction::handle(1132, 'back_pick_country'), true);
departureCityCallbackCheck('back to country preserves trip rows', DepartureCityCallbackFakeData::$rows, $before);
departureCityCallbackCheck('back to country renders country once', MaxSearchApi::$countryViews, [1132]);
departureCityCallbackCheck('back to country advances to country', MaxSearchApi::$transitions, [MaxSearchApi::$statusContryChoose]);
departureCityCallbackCheck('back to country makes no value update', DepartureCityCallbackFakeData::$updates, 0);

$source = (string)file_get_contents(__DIR__ . '/../actions/callbacks/WizardCallbackAction.php');
departureCityCallbackCheck('action parses city callback through value contract', strpos($source, 'DepartureCityValueContract::fromCallbackPayload($q)') !== false, true);
departureCityCallbackCheck('action applies city through update-only boundary', strpos($source, 'MaxSearchApi::$statusCityChoose,') !== false && strpos($source, '$city') !== false, true);
departureCityCallbackCheck('action has no direct city callback write', strpos($source, 'MaxSearchApi::saveLastValue($chatId, MaxSearchApi::$statusCityChoose') === false, true);
departureCityCallbackCheck('action keeps the shared forward lock', strpos($source, "InteractionGuard::synchronized(\$chatId, 'wizard.forward'") !== false, true);
departureCityCallbackCheck('action keeps stale check inside the shared lock', strpos($source, 'self::staleForwardCallback($chatId, $q)') !== false, true);

foreach (array_merge(range(1100, 1105), [1110, 1111, 1112], range(1120, 1125), [1130, 1131, 1132]) as $cleanupChatId) {
    EditFlowService::clearSnapshot($cleanupChatId);
    @unlink(InteractionGuard::lockPath($cleanupChatId, 'wizard.forward'));
}

echo "\n--------------------------------\n";
echo $failed === 0 ? "DEPARTURE CITY CALLBACK APPLICATION: OK\n" : "DEPARTURE CITY CALLBACK APPLICATION: FAIL ({$failed})\n";
exit($failed === 0 ? 0 : 1);
