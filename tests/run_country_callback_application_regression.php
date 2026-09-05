<?php

declare(strict_types=1);

final class CountryCallbackFakeResult
{
    private array $rows;
    private int $index = 0;
    public function __construct(array $rows) { $this->rows = array_values($rows); }
    public function fetch() { return $this->rows[$this->index++] ?? false; }
}

final class CountryCallbackFakeEntity
{
    public function getDataClass(): string { return CountryCallbackFakeData::class; }
}

final class CountryCallbackFakeData
{
    public static array $rows = [];
    public static int $adds = 0;
    public static int $updates = 0;

    public static function getList(array $query): CountryCallbackFakeResult
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
        return new CountryCallbackFakeResult($rows);
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
eval('namespace Bitrix\\Highloadblock { class HighloadBlockTable { public static function getById($id) { return new \\CountryCallbackFakeResult([["ID"=>$id]]); } public static function compileEntity($row) { return new \\CountryCallbackFakeEntity(); } } }');

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
    public static int $currentStatus = 66;
    public static string $editMode = '';
    public static array $transitions = [];
    public static array $directSaves = [];
    public static array $adultsViews = [];
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
        foreach (CountryCallbackFakeData::$rows as $row) {
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
    public static function showAdultsButtons($chatId): bool
    {
        self::$adultsViews[] = $chatId;
        self::setStatus($chatId, self::$statusAdults);
        return true;
    }
}

require_once __DIR__ . '/../actions/callbacks/WizardCallbackAction.php';

final class CountryCallbackMessenger implements MessengerInterface
{
    public array $sent = [];
    public array $buttons = [];
    public function send($chatId, string $text): bool { $this->sent[] = [$chatId, $text]; return true; }
    public function sendWithButtons($chatId, string $text, array $buttons): bool { $this->buttons[] = [$chatId, $text, $buttons]; return true; }
    public function sendContactRequest($chatId, string $text, string $manualCallback, string $backCallback): bool { return true; }
}

$failed = 0;
function countryCallbackCheck(string $name, $actual, $expected): void
{
    global $failed;
    if ($actual === $expected) { echo "PASS  {$name}\n"; return; }
    echo "FAIL  {$name}\n";
    echo '      expected: ' . json_encode($expected, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
    echo '      actual:   ' . json_encode($actual, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
    $failed++;
}

function countryCallbackReset(int $chatId, bool $withStep = true): CountryCallbackMessenger
{
    EditFlowService::clearSnapshot($chatId);
    @unlink(InteractionGuard::lockPath($chatId, 'wizard.forward'));
    MaxSearchApi::$currentStatus = (int)MaxSearchApi::$statusContryChoose;
    MaxSearchApi::$editMode = '';
    MaxSearchApi::$transitions = [];
    MaxSearchApi::$directSaves = [];
    MaxSearchApi::$adultsViews = [];
    MaxSearchApi::$funnelEvents = [];
    CountryCallbackFakeData::$adds = 0;
    CountryCallbackFakeData::$updates = 0;
    CountryCallbackFakeData::$rows = [
        ['ID'=>10, 'UF_CHAT_ID'=>$chatId, 'UF_STATUS'=>MaxSearchApi::$statusStart, 'UF_VALUE'=>''],
    ];
    if ($withStep) {
        CountryCallbackFakeData::$rows[] = ['ID'=>20, 'UF_CHAT_ID'=>$chatId, 'UF_STATUS'=>MaxSearchApi::$statusContryChoose, 'UF_VALUE'=>'4'];
    }

    $messenger = new CountryCallbackMessenger();
    IntegrationRegistry::resetForTests($messenger);
    return $messenger;
}

$payloads = [
    'pick_country_1' => '1',
    'pick_country_2' => '2',
    'pick_country_4' => '4',
    'pick_country_8' => '8',
    'pick_country_9' => '9',
    'pick_country_12' => '12',
    'pick_country_347' => '347',
];
$chatId = 1200;
foreach ($payloads as $payload => $expected) {
    countryCallbackReset($chatId);
    $before = count(CountryCallbackFakeData::$rows);
    countryCallbackCheck("{$payload} is consumed", WizardCallbackAction::handle($chatId, $payload), true);
    countryCallbackCheck("{$payload} stores exact string", MaxSearchApi::getSavedData($chatId)[MaxSearchApi::$statusContryChoose] ?? null, $expected);
    countryCallbackCheck("{$payload} advances once to adults", MaxSearchApi::$transitions, [MaxSearchApi::$statusAdults]);
    countryCallbackCheck("{$payload} renders adults once", MaxSearchApi::$adultsViews, [$chatId]);
    countryCallbackCheck("{$payload} updates once", CountryCallbackFakeData::$updates, 1);
    countryCallbackCheck("{$payload} does not insert", count(CountryCallbackFakeData::$rows), $before);
    countryCallbackCheck("{$payload} avoids add", CountryCallbackFakeData::$adds, 0);
    countryCallbackCheck("{$payload} avoids legacy direct write", MaxSearchApi::$directSaves, []);
    countryCallbackCheck("{$payload} preserves funnel event", MaxSearchApi::$funnelEvents, [['country_selected', ['payload'=>$payload]]]);

    countryCallbackCheck("duplicate {$payload} is consumed", WizardCallbackAction::handle($chatId, $payload), true);
    countryCallbackCheck("duplicate {$payload} does not update", CountryCallbackFakeData::$updates, 1);
    countryCallbackCheck("duplicate {$payload} does not render", MaxSearchApi::$adultsViews, [$chatId]);
    countryCallbackCheck("duplicate {$payload} does not advance", MaxSearchApi::$transitions, [MaxSearchApi::$statusAdults]);
    countryCallbackCheck("duplicate {$payload} does not relog funnel", count(MaxSearchApi::$funnelEvents), 1);
    $chatId++;
}

countryCallbackReset(1210);
MaxSearchApi::$currentStatus = MaxSearchApi::$statusAdults;
$before = CountryCallbackFakeData::$rows;
countryCallbackCheck('stale country callback is consumed', WizardCallbackAction::handle(1210, 'pick_country_1'), true);
countryCallbackCheck('stale country callback preserves rows', CountryCallbackFakeData::$rows, $before);
countryCallbackCheck('stale country callback makes no update', CountryCallbackFakeData::$updates, 0);
countryCallbackCheck('stale country callback does not progress', MaxSearchApi::$adultsViews, []);
countryCallbackCheck('stale country callback does not log funnel', MaxSearchApi::$funnelEvents, []);

countryCallbackReset(1211, false);
$before = CountryCallbackFakeData::$rows;
countryCallbackCheck('missing country step is consumed', WizardCallbackAction::handle(1211, 'pick_country_2'), true);
countryCallbackCheck('missing country step preserves rows', CountryCallbackFakeData::$rows, $before);
countryCallbackCheck('missing country step never inserts', CountryCallbackFakeData::$adds, 0);
countryCallbackCheck('missing country step never updates', CountryCallbackFakeData::$updates, 0);
countryCallbackCheck('missing country step never progresses', MaxSearchApi::$adultsViews, []);
countryCallbackCheck('missing country step does not log funnel', MaxSearchApi::$funnelEvents, []);

countryCallbackReset(1212);
CountryCallbackFakeData::$rows[] = ['ID'=>30, 'UF_CHAT_ID'=>1212, 'UF_STATUS'=>MaxSearchApi::$statusStart, 'UF_VALUE'=>''];
$before = CountryCallbackFakeData::$rows;
countryCallbackCheck('pre-start country step is consumed', WizardCallbackAction::handle(1212, 'pick_country_4'), true);
countryCallbackCheck('pre-start country step preserves rows', CountryCallbackFakeData::$rows, $before);
countryCallbackCheck('pre-start country step never updates', CountryCallbackFakeData::$updates, 0);
countryCallbackCheck('pre-start country step never progresses', MaxSearchApi::$adultsViews, []);
countryCallbackCheck('pre-start country step does not log funnel', MaxSearchApi::$funnelEvents, []);

foreach (['pick_country_0', 'pick_country_-1', 'pick_country_1.5', 'pick_country_01', 'pick_country_', 'pick_country_1_extra'] as $index => $payload) {
    $invalidChatId = 1220 + $index;
    countryCallbackReset($invalidChatId);
    $before = CountryCallbackFakeData::$rows;
    countryCallbackCheck("invalid {$payload} is consumed", WizardCallbackAction::handle($invalidChatId, $payload), true);
    countryCallbackCheck("invalid {$payload} preserves rows", CountryCallbackFakeData::$rows, $before);
    countryCallbackCheck("invalid {$payload} never updates", CountryCallbackFakeData::$updates, 0);
    countryCallbackCheck("invalid {$payload} never progresses", MaxSearchApi::$adultsViews, []);
    countryCallbackCheck("invalid {$payload} never logs funnel", MaxSearchApi::$funnelEvents, []);
}

$messenger = countryCallbackReset(1230);
$before = CountryCallbackFakeData::$rows;
countryCallbackCheck('manual country payload is consumed', WizardCallbackAction::handle(1230, 'pick_country_other'), true);
countryCallbackCheck('manual country payload preserves rows', CountryCallbackFakeData::$rows, $before);
countryCallbackCheck('manual country payload renders prompt once', count($messenger->buttons), 1);
countryCallbackCheck('manual country payload keeps country state', MaxSearchApi::$transitions, [MaxSearchApi::$statusContryChoose]);
countryCallbackCheck('manual country payload makes no update', CountryCallbackFakeData::$updates, 0);
countryCallbackCheck('manual country payload logs no selection', MaxSearchApi::$funnelEvents, []);

$messenger = countryCallbackReset(1231);
EditFlowService::begin(1231, 'country');
countryCallbackCheck('country edit payload is consumed', WizardCallbackAction::handle(1231, 'pick_country_12'), true);
countryCallbackCheck('country edit stores exact value', MaxSearchApi::getSavedData(1231)[MaxSearchApi::$statusContryChoose] ?? null, '12');
countryCallbackCheck('country edit returns once to check', MaxSearchApi::$transitions, [MaxSearchApi::$statusCheck]);
countryCallbackCheck('country edit renders check once', count($messenger->buttons), 1);
countryCallbackCheck('country edit clears edit mode', MaxSearchApi::$editMode, '');
countryCallbackCheck('country edit does not render adults', MaxSearchApi::$adultsViews, []);
countryCallbackCheck('country edit updates one country row', CountryCallbackFakeData::$updates, 1);
countryCallbackCheck('country edit preserves funnel event', MaxSearchApi::$funnelEvents, [['country_selected', ['payload'=>'pick_country_12']]]);
countryCallbackCheck('country edit direct write is only check generation', array_column(MaxSearchApi::$directSaves, 1), [MaxSearchApi::$statusCheck]);

countryCallbackReset(1232);
MaxSearchApi::$currentStatus = MaxSearchApi::$statusChild;
$before = CountryCallbackFakeData::$rows;
countryCallbackCheck('back to adults is consumed', WizardCallbackAction::handle(1232, 'back_adults'), true);
countryCallbackCheck('back to adults preserves trip rows', CountryCallbackFakeData::$rows, $before);
countryCallbackCheck('back to adults renders adults once', MaxSearchApi::$adultsViews, [1232]);
countryCallbackCheck('back to adults moves to adults', MaxSearchApi::$transitions, [MaxSearchApi::$statusAdults]);
countryCallbackCheck('back to adults makes no value update', CountryCallbackFakeData::$updates, 0);

$source = (string)file_get_contents(__DIR__ . '/../actions/callbacks/WizardCallbackAction.php');
countryCallbackCheck('action parses country callback through value contract', strpos($source, 'CountryValueContract::fromCallbackPayload($q)') !== false, true);
countryCallbackCheck('action applies country through update-only boundary', strpos($source, 'MaxSearchApi::$statusContryChoose,') !== false && strpos($source, '$country') !== false, true);
countryCallbackCheck('action has no direct country callback write', strpos($source, 'MaxSearchApi::saveLastValue($chatId, MaxSearchApi::$statusContryChoose') === false, true);
countryCallbackCheck('action keeps the shared forward lock', strpos($source, "InteractionGuard::synchronized(\$chatId, 'wizard.forward'") !== false, true);
countryCallbackCheck('action keeps stale check inside the shared lock', strpos($source, 'self::staleForwardCallback($chatId, $q)') !== false, true);

foreach (array_merge(range(1200, 1206), [1210, 1211, 1212], range(1220, 1225), [1230, 1231, 1232]) as $cleanupChatId) {
    EditFlowService::clearSnapshot($cleanupChatId);
    @unlink(InteractionGuard::lockPath($cleanupChatId, 'wizard.forward'));
}

echo "\n--------------------------------\n";
echo $failed === 0 ? "COUNTRY CALLBACK APPLICATION: OK\n" : "COUNTRY CALLBACK APPLICATION: FAIL ({$failed})\n";
exit($failed === 0 ? 0 : 1);
