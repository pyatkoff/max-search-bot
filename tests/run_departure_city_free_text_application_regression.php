<?php

declare(strict_types=1);

final class DepartureCityFreeTextFakeResult
{
    private array $rows;
    private int $index = 0;
    public function __construct(array $rows) { $this->rows = array_values($rows); }
    public function fetch() { return $this->rows[$this->index++] ?? false; }
}

final class DepartureCityFreeTextFakeEntity
{
    public function getDataClass(): string { return DepartureCityFreeTextFakeData::class; }
}

final class DepartureCityFreeTextFakeData
{
    public static array $rows = [];
    public static int $adds = 0;
    public static int $updates = 0;

    public static function getList(array $query): DepartureCityFreeTextFakeResult
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
        return new DepartureCityFreeTextFakeResult($rows);
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
eval('namespace Bitrix\\Highloadblock { class HighloadBlockTable { public static function getById($id) { return new \\DepartureCityFreeTextFakeResult([["ID"=>$id]]); } public static function compileEntity($row) { return new \\DepartureCityFreeTextFakeEntity(); } } }');

function put_log_out($text): void {}

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
    public static array $countryViews = [];
    public static array $cityLookups = [];
    public static array $funnelEvents = [];
    public static array $aiApplications = [];

    public static function deletePrevMessage($chatId, $withButtons = false): void {}
    public static function setStatus($chatId, $status): void { self::$transitions[] = (int)$status; }
    public static function getEditMode($chatId): string { return self::$editMode; }
    public static function setEditMode($chatId, $field): void { self::$editMode = (string)$field; }
    public static function getCityByName($name)
    {
        self::$cityLookups[] = (string)$name;
        $rows = [
            'Москва' => ['ID'=>'1', 'NAME'=>'Москва'],
            'Без перелёта' => ['ID'=>99, 'NAME'=>'Без перелёта'],
            'Неканоничный' => ['ID'=>'01', 'NAME'=>'Неканоничный'],
        ];
        return $rows[(string)$name] ?? false;
    }
    public static function getSavedData($chatId): array
    {
        $values = [];
        foreach (DepartureCityFreeTextFakeData::$rows as $row) {
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
    public static function getAiSearchContext($chatId): array { return []; }
    public static function getAiMissingFields($chatId): array { return ['country']; }
    public static function applyAiParameters($chatId, array $params): array
    {
        self::$aiApplications[] = $params;
        return $params;
    }
}

require_once __DIR__ . '/../handlers/StateMessageHandler.php';

final class DepartureCityFreeTextMessenger implements MessengerInterface
{
    public array $sent = [];
    public array $buttons = [];
    public function send($chatId, string $text): bool { $this->sent[] = [$chatId, $text]; return true; }
    public function sendWithButtons($chatId, string $text, array $buttons): bool { $this->buttons[] = [$chatId, $text, $buttons]; return true; }
    public function sendContactRequest($chatId, string $text, string $manualCallback, string $backCallback): bool { return true; }
}

$directory = new PDO('sqlite::memory:');
$directory->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$directory->exec('CREATE TABLE catalog_departures (id INTEGER PRIMARY KEY, name TEXT, name_genitive TEXT, is_active INTEGER)');
$directory->exec("INSERT INTO catalog_departures (id, name, name_genitive, is_active) VALUES (1, 'Москва', 'Москвы', 1), (99, 'Без перелёта', 'Без перелёта', 1), (347, 'Минеральные Воды', 'Минеральных Вод', 1), (500, 'Скрытый', 'Скрытого', 0)");
$hotelPdo = new ReflectionProperty(HotelDatabase::class, 'pdo');
$hotelPdo->setAccessible(true);
$hotelPdo->setValue(null, $directory);

$failed = 0;
function departureCityFreeTextCheck(string $name, $actual, $expected): void
{
    global $failed;
    if ($actual === $expected) { echo "PASS  {$name}\n"; return; }
    echo "FAIL  {$name}\n";
    echo '      expected: ' . json_encode($expected, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
    echo '      actual:   ' . json_encode($actual, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
    $failed++;
}

function departureCityFreeTextReset(int $chatId, bool $withStep = true, bool $preStart = false): DepartureCityFreeTextMessenger
{
    EditFlowService::clearSnapshot($chatId);
    MaxSearchApi::$editMode = '';
    MaxSearchApi::$transitions = [];
    MaxSearchApi::$directSaves = [];
    MaxSearchApi::$countryViews = [];
    MaxSearchApi::$cityLookups = [];
    MaxSearchApi::$funnelEvents = [];
    MaxSearchApi::$aiApplications = [];
    DepartureCityFreeTextFakeData::$adds = 0;
    DepartureCityFreeTextFakeData::$updates = 0;
    DepartureCityFreeTextFakeData::$rows = $preStart
        ? [
            ['ID'=>5, 'UF_CHAT_ID'=>$chatId, 'UF_STATUS'=>MaxSearchApi::$statusCityChoose, 'UF_VALUE'=>'5'],
            ['ID'=>10, 'UF_CHAT_ID'=>$chatId, 'UF_STATUS'=>MaxSearchApi::$statusStart, 'UF_VALUE'=>''],
        ]
        : [
            ['ID'=>10, 'UF_CHAT_ID'=>$chatId, 'UF_STATUS'=>MaxSearchApi::$statusStart, 'UF_VALUE'=>''],
        ];
    if ($withStep && !$preStart) {
        DepartureCityFreeTextFakeData::$rows[] = ['ID'=>20, 'UF_CHAT_ID'=>$chatId, 'UF_STATUS'=>MaxSearchApi::$statusCityChoose, 'UF_VALUE'=>'5'];
    }
    $messenger = new DepartureCityFreeTextMessenger();
    IntegrationRegistry::resetForTests($messenger);
    return $messenger;
}

foreach ([
    ['primary directory hit', 1100, 'Москва', '1'],
    ['resolver fallback hit', 1101, 'Мин. Воды', '347'],
    ['no-flight directory id', 1102, 'Без перелёта', '99'],
] as [$label, $chatId, $text, $stored]) {
    $messenger = departureCityFreeTextReset($chatId);
    $before = count(DepartureCityFreeTextFakeData::$rows);
    StateMessageHandler::handle(['text'=>$text], $chatId, MaxSearchApi::$statusCityChoose);
    departureCityFreeTextCheck("{$label} calls primary lookup first", MaxSearchApi::$cityLookups, [$text]);
    departureCityFreeTextCheck("{$label} stores exact canonical id", MaxSearchApi::getSavedData($chatId)[MaxSearchApi::$statusCityChoose] ?? null, $stored);
    departureCityFreeTextCheck("{$label} updates one existing row", DepartureCityFreeTextFakeData::$updates, 1);
    departureCityFreeTextCheck("{$label} never inserts", count(DepartureCityFreeTextFakeData::$rows), $before);
    departureCityFreeTextCheck("{$label} never calls add", DepartureCityFreeTextFakeData::$adds, 0);
    departureCityFreeTextCheck("{$label} advances once to country", MaxSearchApi::$transitions, [MaxSearchApi::$statusContryChoose]);
    departureCityFreeTextCheck("{$label} renders country once", MaxSearchApi::$countryViews, [$chatId]);
    departureCityFreeTextCheck("{$label} avoids legacy direct write", MaxSearchApi::$directSaves, []);
    departureCityFreeTextCheck("{$label} emits no validation text", $messenger->sent, []);
}

$messenger = departureCityFreeTextReset(1200);
StateMessageHandler::handle(['text'=>'Неизвестный'], 1200, MaxSearchApi::$statusCityChoose);
departureCityFreeTextCheck('unknown short input uses primary lookup', MaxSearchApi::$cityLookups, ['Неизвестный']);
departureCityFreeTextCheck('unknown short input preserves city', MaxSearchApi::getSavedData(1200)[MaxSearchApi::$statusCityChoose] ?? null, '5');
departureCityFreeTextCheck('unknown short input sends existing validation', $messenger->sent[0][1] ?? '', 'Не нашла такой город вылета. Проверьте название или выберите один из предложенных вариантов.');
departureCityFreeTextCheck('unknown short input does not update', DepartureCityFreeTextFakeData::$updates, 0);
departureCityFreeTextCheck('unknown short input does not progress', MaxSearchApi::$transitions, []);

$messenger = departureCityFreeTextReset(1201);
$richText = 'Хочу тур из Москвы в Египет, 2 взрослых, без детей, на 7 ночей';
StateMessageHandler::handle(['text'=>$richText], 1201, MaxSearchApi::$statusCityChoose);
departureCityFreeTextCheck('rich input still attempts primary lookup first', MaxSearchApi::$cityLookups, [$richText]);
departureCityFreeTextCheck('rich input enters AI status first', MaxSearchApi::$transitions[0] ?? null, MaxSearchApi::$statusAi);
departureCityFreeTextCheck('rich input reaches the existing AI handler', in_array('ai_text', array_column(MaxSearchApi::$funnelEvents, 0), true), true);
departureCityFreeTextCheck('rich input does not use city update-only writer', DepartureCityFreeTextFakeData::$updates, 0);
departureCityFreeTextCheck('rich input preserves existing city row', MaxSearchApi::getSavedData(1201)[MaxSearchApi::$statusCityChoose] ?? null, '5');
departureCityFreeTextCheck('rich input keeps existing AI application path', count(MaxSearchApi::$aiApplications) > 0, true);

foreach ([['missing', 1300, false], ['pre-start', 1301, true]] as [$label, $chatId, $preStart]) {
    $messenger = departureCityFreeTextReset($chatId, $label !== 'missing', $preStart);
    $before = DepartureCityFreeTextFakeData::$rows;
    StateMessageHandler::handle(['text'=>'Москва'], $chatId, MaxSearchApi::$statusCityChoose);
    departureCityFreeTextCheck("{$label} city step preserves rows", DepartureCityFreeTextFakeData::$rows, $before);
    departureCityFreeTextCheck("{$label} city step does not update", DepartureCityFreeTextFakeData::$updates, 0);
    departureCityFreeTextCheck("{$label} city step does not insert", DepartureCityFreeTextFakeData::$adds, 0);
    departureCityFreeTextCheck("{$label} city step does not progress", MaxSearchApi::$transitions, []);
    departureCityFreeTextCheck("{$label} city step renders no next view", MaxSearchApi::$countryViews, []);
    departureCityFreeTextCheck("{$label} city step sends no validation", $messenger->sent, []);
}

$messenger = departureCityFreeTextReset(1302);
StateMessageHandler::handle(['text'=>'Неканоничный'], 1302, MaxSearchApi::$statusCityChoose);
departureCityFreeTextCheck('rejected directory id preserves city', MaxSearchApi::getSavedData(1302)[MaxSearchApi::$statusCityChoose] ?? null, '5');
departureCityFreeTextCheck('rejected directory id does not update', DepartureCityFreeTextFakeData::$updates, 0);
departureCityFreeTextCheck('rejected directory id does not progress', MaxSearchApi::$transitions, []);
departureCityFreeTextCheck('rejected directory id sends no validation', $messenger->sent, []);

$messenger = departureCityFreeTextReset(1400);
EditFlowService::begin(1400, 'city');
StateMessageHandler::handle(['text'=>'Мин. Воды'], 1400, MaxSearchApi::$statusCityChoose);
departureCityFreeTextCheck('city edit stores exact fallback id', MaxSearchApi::getSavedData(1400)[MaxSearchApi::$statusCityChoose] ?? null, '347');
departureCityFreeTextCheck('city edit returns once to check', MaxSearchApi::$transitions, [MaxSearchApi::$statusCheck]);
departureCityFreeTextCheck('city edit renders check once', count($messenger->buttons), 1);
departureCityFreeTextCheck('city edit clears mode', MaxSearchApi::$editMode, '');
departureCityFreeTextCheck('city edit does not render country', MaxSearchApi::$countryViews, []);
departureCityFreeTextCheck('city edit updates only city row', DepartureCityFreeTextFakeData::$updates, 1);
departureCityFreeTextCheck('city edit direct write is only check generation', array_column(MaxSearchApi::$directSaves, 1), [MaxSearchApi::$statusCheck]);

$source = (string)file_get_contents(__DIR__ . '/../handlers/StateMessageHandler.php');
$cityStart = strpos($source, 'if($status==MaxSearchApi::$statusCityChoose)');
$countryStart = $cityStart === false ? false : strpos($source, 'elseif($status==MaxSearchApi::$statusContryChoose)', $cityStart);
$citySource = $cityStart === false || $countryStart === false ? '' : substr($source, $cityStart, $countryStart - $cityStart);
departureCityFreeTextCheck('handler preserves primary-before-resolver lookup order', strpos($citySource, 'MaxSearchApi::getCityByName($city)') < strpos($citySource, 'DepartureCityResolver::resolveFieldValue($city)'), true);
departureCityFreeTextCheck('handler normalizes through one value contract call', substr_count($citySource, 'DepartureCityValueContract::fromDirectoryId') === 1, true);
departureCityFreeTextCheck('handler applies through one update-only boundary', substr_count($citySource, 'ExistingWizardStepApplicationService::apply(') === 1, true);
departureCityFreeTextCheck('handler no longer directly writes city', strpos($citySource, 'MaxSearchApi::saveLastValue') === false, true);
departureCityFreeTextCheck('handler preserves AI routing owner', strpos($citySource, 'self::routeFreeTextToAi($message,$chat_id)') !== false, true);

EditFlowService::clearSnapshot(1400);
HotelDatabase::resetForTests();
echo "\n--------------------------------\n";
echo $failed === 0 ? "DEPARTURE CITY FREE-TEXT APPLICATION: OK\n" : "DEPARTURE CITY FREE-TEXT APPLICATION: FAIL ({$failed})\n";
exit($failed === 0 ? 0 : 1);
