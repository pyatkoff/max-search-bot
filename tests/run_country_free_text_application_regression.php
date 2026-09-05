<?php

declare(strict_types=1);

final class CountryFreeTextFakeResult
{
    private array $rows;
    private int $index = 0;
    public function __construct(array $rows) { $this->rows = array_values($rows); }
    public function fetch() { return $this->rows[$this->index++] ?? false; }
}

final class CountryFreeTextFakeEntity
{
    public function getDataClass(): string { return CountryFreeTextFakeData::class; }
}

final class CountryFreeTextFakeData
{
    public static array $rows = [];
    public static int $adds = 0;
    public static int $updates = 0;

    public static function getList(array $query): CountryFreeTextFakeResult
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
        return new CountryFreeTextFakeResult($rows);
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
eval('namespace Bitrix\\Highloadblock { class HighloadBlockTable { public static function getById($id) { return new \\CountryFreeTextFakeResult([["ID"=>$id]]); } public static function compileEntity($row) { return new \\CountryFreeTextFakeEntity(); } } }');

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
    public static array $adultsViews = [];
    public static array $countryLookups = [];
    public static array $funnelEvents = [];
    public static array $aiApplications = [];

    public static function deletePrevMessage($chatId, $withButtons = false): void {}
    public static function setStatus($chatId, $status): void { self::$transitions[] = (int)$status; }
    public static function getEditMode($chatId): string { return self::$editMode; }
    public static function setEditMode($chatId, $field): void { self::$editMode = (string)$field; }
    public static function getCountryByName($name)
    {
        self::$countryLookups[] = (string)$name;
        $rows = [
            'Египет' => ['ID'=>1, 'NAME'=>'Египет'],
            'Таиланд' => ['ID'=>'2', 'NAME'=>'Таиланд'],
            'Турция' => ['ID'=>'4', 'NAME'=>'Турция'],
            'Мальдивы' => ['ID'=>'8', 'NAME'=>'Мальдивы'],
            'ОАЭ' => ['ID'=>9, 'NAME'=>'ОАЭ'],
            'Шри-Ланка' => ['ID'=>'12', 'NAME'=>'Шри-Ланка'],
            'Другая' => ['ID'=>347, 'NAME'=>'Другая'],
            'Неканоничная' => ['ID'=>'01', 'NAME'=>'Неканоничная'],
        ];
        return $rows[(string)$name] ?? false;
    }
    public static function getSavedData($chatId): array
    {
        $values = [];
        foreach (CountryFreeTextFakeData::$rows as $row) {
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
    public static function getAiSearchContext($chatId): array { return []; }
    public static function getAiMissingFields($chatId): array { return ['country']; }
    public static function applyAiParameters($chatId, array $params): array
    {
        self::$aiApplications[] = $params;
        return $params;
    }
}

require_once __DIR__ . '/../handlers/StateMessageHandler.php';

final class CountryFreeTextMessenger implements MessengerInterface
{
    public array $sent = [];
    public array $buttons = [];
    public function send($chatId, string $text): bool { $this->sent[] = [$chatId, $text]; return true; }
    public function sendWithButtons($chatId, string $text, array $buttons): bool { $this->buttons[] = [$chatId, $text, $buttons]; return true; }
    public function sendContactRequest($chatId, string $text, string $manualCallback, string $backCallback): bool { return true; }
}

$failed = 0;
function countryFreeTextCheck(string $name, $actual, $expected): void
{
    global $failed;
    if ($actual === $expected) { echo "PASS  {$name}\n"; return; }
    echo "FAIL  {$name}\n";
    echo '      expected: ' . json_encode($expected, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
    echo '      actual:   ' . json_encode($actual, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
    $failed++;
}

function countryFreeTextReset(int $chatId, bool $withStep = true, bool $preStart = false): CountryFreeTextMessenger
{
    EditFlowService::clearSnapshot($chatId);
    MaxSearchApi::$editMode = '';
    MaxSearchApi::$transitions = [];
    MaxSearchApi::$directSaves = [];
    MaxSearchApi::$adultsViews = [];
    MaxSearchApi::$countryLookups = [];
    MaxSearchApi::$funnelEvents = [];
    MaxSearchApi::$aiApplications = [];
    CountryFreeTextFakeData::$adds = 0;
    CountryFreeTextFakeData::$updates = 0;
    CountryFreeTextFakeData::$rows = $preStart
        ? [
            ['ID'=>5, 'UF_CHAT_ID'=>$chatId, 'UF_STATUS'=>MaxSearchApi::$statusContryChoose, 'UF_VALUE'=>'4'],
            ['ID'=>10, 'UF_CHAT_ID'=>$chatId, 'UF_STATUS'=>MaxSearchApi::$statusStart, 'UF_VALUE'=>''],
        ]
        : [
            ['ID'=>10, 'UF_CHAT_ID'=>$chatId, 'UF_STATUS'=>MaxSearchApi::$statusStart, 'UF_VALUE'=>''],
        ];
    if ($withStep && !$preStart) {
        CountryFreeTextFakeData::$rows[] = ['ID'=>20, 'UF_CHAT_ID'=>$chatId, 'UF_STATUS'=>MaxSearchApi::$statusContryChoose, 'UF_VALUE'=>'4'];
    }
    $messenger = new CountryFreeTextMessenger();
    IntegrationRegistry::resetForTests($messenger);
    return $messenger;
}

$inputs = [
    ['Egypt integer ID', 1300, 'Египет', '1'],
    ['Thailand PDO string ID', 1301, 'Таиланд', '2'],
    ['Turkey popular ID', 1302, 'Турция', '4'],
    ['Maldives popular ID', 1303, 'Мальдивы', '8'],
    ['UAE integer ID', 1304, 'ОАЭ', '9'],
    ['Sri Lanka PDO string ID', 1305, 'Шри-Ланка', '12'],
    ['another positive ID', 1306, 'Другая', '347'],
];
foreach ($inputs as [$label, $chatId, $text, $stored]) {
    $messenger = countryFreeTextReset($chatId);
    $before = count(CountryFreeTextFakeData::$rows);
    StateMessageHandler::handle(['text'=>$text], $chatId, MaxSearchApi::$statusContryChoose);
    countryFreeTextCheck("{$label} uses exact directory lookup", MaxSearchApi::$countryLookups, [$text]);
    countryFreeTextCheck("{$label} stores exact canonical id", MaxSearchApi::getSavedData($chatId)[MaxSearchApi::$statusContryChoose] ?? null, $stored);
    countryFreeTextCheck("{$label} updates one existing row", CountryFreeTextFakeData::$updates, 1);
    countryFreeTextCheck("{$label} never inserts", count(CountryFreeTextFakeData::$rows), $before);
    countryFreeTextCheck("{$label} never calls add", CountryFreeTextFakeData::$adds, 0);
    countryFreeTextCheck("{$label} advances once to adults", MaxSearchApi::$transitions, [MaxSearchApi::$statusAdults]);
    countryFreeTextCheck("{$label} renders adults once", MaxSearchApi::$adultsViews, [$chatId]);
    countryFreeTextCheck("{$label} avoids legacy direct write", MaxSearchApi::$directSaves, []);
    countryFreeTextCheck("{$label} emits no validation text", $messenger->sent, []);
}

$messenger = countryFreeTextReset(1400);
StateMessageHandler::handle(['text'=>'Неизвестная'], 1400, MaxSearchApi::$statusContryChoose);
countryFreeTextCheck('unknown short input uses exact lookup', MaxSearchApi::$countryLookups, ['Неизвестная']);
countryFreeTextCheck('unknown short input preserves country', MaxSearchApi::getSavedData(1400)[MaxSearchApi::$statusContryChoose] ?? null, '4');
countryFreeTextCheck('unknown short input sends existing validation', $messenger->sent[0][1] ?? '', 'Не нашла это направление в поиске. Проверьте название или выберите одну из популярных стран.');
countryFreeTextCheck('unknown short input does not update', CountryFreeTextFakeData::$updates, 0);
countryFreeTextCheck('unknown short input does not progress', MaxSearchApi::$transitions, []);

$messenger = countryFreeTextReset(1401);
$richText = 'Хочу тур в Египет, 2 взрослых, без детей, на 7 ночей';
StateMessageHandler::handle(['text'=>$richText], 1401, MaxSearchApi::$statusContryChoose);
countryFreeTextCheck('rich input still attempts exact lookup first', MaxSearchApi::$countryLookups, [$richText]);
countryFreeTextCheck('rich input enters AI status first', MaxSearchApi::$transitions[0] ?? null, MaxSearchApi::$statusAi);
countryFreeTextCheck('rich input reaches existing AI handler', in_array('ai_text', array_column(MaxSearchApi::$funnelEvents, 0), true), true);
countryFreeTextCheck('rich input does not use country update-only writer', CountryFreeTextFakeData::$updates, 0);
countryFreeTextCheck('rich input preserves existing country row', MaxSearchApi::getSavedData(1401)[MaxSearchApi::$statusContryChoose] ?? null, '4');
countryFreeTextCheck('rich input keeps existing AI application path', count(MaxSearchApi::$aiApplications) > 0, true);

foreach ([['missing', 1500, false], ['pre-start', 1501, true]] as [$label, $chatId, $preStart]) {
    $messenger = countryFreeTextReset($chatId, $label !== 'missing', $preStart);
    $before = CountryFreeTextFakeData::$rows;
    StateMessageHandler::handle(['text'=>'Турция'], $chatId, MaxSearchApi::$statusContryChoose);
    countryFreeTextCheck("{$label} country step preserves rows", CountryFreeTextFakeData::$rows, $before);
    countryFreeTextCheck("{$label} country step does not update", CountryFreeTextFakeData::$updates, 0);
    countryFreeTextCheck("{$label} country step does not insert", CountryFreeTextFakeData::$adds, 0);
    countryFreeTextCheck("{$label} country step does not progress", MaxSearchApi::$transitions, []);
    countryFreeTextCheck("{$label} country step renders no next view", MaxSearchApi::$adultsViews, []);
    countryFreeTextCheck("{$label} country step sends no validation", $messenger->sent, []);
}

$messenger = countryFreeTextReset(1502);
StateMessageHandler::handle(['text'=>'Неканоничная'], 1502, MaxSearchApi::$statusContryChoose);
countryFreeTextCheck('rejected directory id preserves country', MaxSearchApi::getSavedData(1502)[MaxSearchApi::$statusContryChoose] ?? null, '4');
countryFreeTextCheck('rejected directory id does not update', CountryFreeTextFakeData::$updates, 0);
countryFreeTextCheck('rejected directory id does not progress', MaxSearchApi::$transitions, []);
countryFreeTextCheck('rejected directory id sends no validation', $messenger->sent, []);

$messenger = countryFreeTextReset(1600);
EditFlowService::begin(1600, 'country');
StateMessageHandler::handle(['text'=>'Шри-Ланка'], 1600, MaxSearchApi::$statusContryChoose);
countryFreeTextCheck('country edit stores exact directory id', MaxSearchApi::getSavedData(1600)[MaxSearchApi::$statusContryChoose] ?? null, '12');
countryFreeTextCheck('country edit returns once to check', MaxSearchApi::$transitions, [MaxSearchApi::$statusCheck]);
countryFreeTextCheck('country edit renders check once', count($messenger->buttons), 1);
countryFreeTextCheck('country edit clears mode', MaxSearchApi::$editMode, '');
countryFreeTextCheck('country edit does not render adults', MaxSearchApi::$adultsViews, []);
countryFreeTextCheck('country edit updates only country row', CountryFreeTextFakeData::$updates, 1);
countryFreeTextCheck('country edit direct write is only check generation', array_column(MaxSearchApi::$directSaves, 1), [MaxSearchApi::$statusCheck]);

$source = (string)file_get_contents(__DIR__ . '/../handlers/StateMessageHandler.php');
$countryStart = strpos($source, 'elseif($status==MaxSearchApi::$statusContryChoose)');
$ageStart = $countryStart === false ? false : strpos($source, 'elseif($status==MaxSearchApi::$statusAge)', $countryStart);
$countrySource = $countryStart === false || $ageStart === false ? '' : substr($source, $countryStart, $ageStart - $countryStart);
countryFreeTextCheck('handler preserves exact directory lookup owner', substr_count($countrySource, 'MaxSearchApi::getCountryByName($country)') === 1, true);
countryFreeTextCheck('handler normalizes through one value contract call', substr_count($countrySource, 'CountryValueContract::fromDirectoryId') === 1, true);
countryFreeTextCheck('handler applies through one update-only boundary', substr_count($countrySource, 'ExistingWizardStepApplicationService::apply(') === 1, true);
countryFreeTextCheck('handler no longer directly writes country', strpos($countrySource, 'MaxSearchApi::saveLastValue') === false, true);
countryFreeTextCheck('handler preserves AI routing owner', strpos($countrySource, 'self::routeFreeTextToAi($message,$chat_id)') !== false, true);

EditFlowService::clearSnapshot(1600);
echo "\n--------------------------------\n";
echo $failed === 0 ? "COUNTRY FREE-TEXT APPLICATION: OK\n" : "COUNTRY FREE-TEXT APPLICATION: FAIL ({$failed})\n";
exit($failed === 0 ? 0 : 1);
