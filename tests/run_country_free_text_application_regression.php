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
    // Original update-only cases keep their no-op status adapter. Menu cases
    // opt in to the real repository's append/read/update/delete boundaries.
    public static bool $persistentSteps = false;
    public static ?int $failAddStatus = null;
    public static array $writes = [];

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
            if (self::$persistentSteps) self::$writes[] = ['id'=>(int)$id, 'status'=>(int)$row['UF_STATUS'], 'fields'=>$fields];
            foreach ($fields as $key => $value) $row[$key] = $value;
            return true;
        }
        return false;
    }

    public static function add(array $fields): bool
    {
        self::$adds++;
        if (self::$persistentSteps) {
            if (self::$failAddStatus === (int)$fields['UF_STATUS']) return false;
            $id = self::$rows === [] ? 1 : max(array_column(self::$rows, 'ID')) + 1;
            self::$rows[] = $fields + ['ID'=>$id, 'UF_VALUE'=>''];
        }
        return true;
    }

    public static function delete($id): bool
    {
        self::$rows = array_values(array_filter(self::$rows, static fn(array $row): bool => (int)$row['ID'] !== (int)$id));
        return true;
    }
}

eval('namespace Bitrix\\Main { class Loader { public static function includeModule($name) { return true; } } }');
eval('namespace Bitrix\\Main\\Type { class DateTime {} }');
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
    public static array $countryLookupOverrides = [];

    public static function deletePrevMessage($chatId, $withButtons = false): void
    {
        if (CountryFreeTextFakeData::$persistentSteps && $withButtons) {
            $row = ConversationStateRepository::latestMessageRow(self::$HL, $chatId);
            if ($row) ConversationStateRepository::deleteRow(self::$HL, $row['ID']);
        }
    }
    public static function setStatus($chatId, $status): void
    {
        self::$transitions[] = (int)$status;
        if (CountryFreeTextFakeData::$persistentSteps) ConversationStateRepository::addStatus(self::$HL, $chatId, $status);
    }
    public static function getCurentStatus($chatId)
    {
        if (!CountryFreeTextFakeData::$persistentSteps && MaxSearchApi::$transitions !== []) {
            return MaxSearchApi::$transitions[count(MaxSearchApi::$transitions) - 1];
        }
        return ConversationStateRepository::currentStatus(self::$HL, $chatId);
    }
    public static function cancelToursFollowup($chatId): void {}
    public static function showCityButtons($chatId): bool { return DialogueView::city($chatId); }
    public static function getEditMode($chatId): string { return self::$editMode; }
    public static function setEditMode($chatId, $field): void { self::$editMode = (string)$field; }
    public static function getCountryByName($name)
    {
        self::$countryLookups[] = (string)$name;
        if (array_key_exists((string)$name, self::$countryLookupOverrides)) return self::$countryLookupOverrides[(string)$name];
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
        if (CountryFreeTextFakeData::$persistentSteps) {
            foreach ($rows as $label => $row) {
                if (mb_strtolower($label, 'UTF-8') === mb_strtolower((string)$name, 'UTF-8')) return $row;
            }
        }
        return $rows[(string)$name] ?? false;
    }
    public static function getSavedData($chatId): array
    {
        if (CountryFreeTextFakeData::$persistentSteps) {
            return ConversationStateRepository::savedData(self::$HL, $chatId, self::$statusStart, self::$statusCheck);
        }
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
        if (CountryFreeTextFakeData::$persistentSteps) {
            return ConversationStateRepository::saveLastValue(self::$HL, $chatId, $status, $value, self::$statusStart);
        }
        return true;
    }
    public static function showAdultsButtons($chatId): bool
    {
        self::$adultsViews[] = $chatId;
        self::setStatus($chatId, self::$statusAdults);
        return true;
    }
    public static function getAiSearchContext($chatId): array { return []; }
    public static array $missingNeeds = ['country'];
    public static function getAiMissingFields($chatId): array { return self::$missingNeeds; }
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
    public bool $buttonsSucceed = true;
    public function send($chatId, string $text): bool { $this->sent[] = [$chatId, $text]; return true; }
    public function sendWithButtons($chatId, string $text, array $buttons): bool { $this->buttons[] = [$chatId, $text, $buttons]; return $this->buttonsSucceed; }
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
    MaxSearchApi::$countryLookupOverrides = [];
    CountryFreeTextFakeData::$adds = 0;
    CountryFreeTextFakeData::$updates = 0;
    CountryFreeTextFakeData::$persistentSteps = false;
    CountryFreeTextFakeData::$failAddStatus = null;
    CountryFreeTextFakeData::$writes = [];
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
// The edit case has a complete surrounding trip; earlier AI-route cases retain their missing country.
MaxSearchApi::$missingNeeds = [];
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
$childStart = $countryStart === false ? false : strpos($source, 'elseif($status==MaxSearchApi::$statusChild)', $countryStart);
$ageStart = $countryStart === false ? false : strpos($source, 'elseif($status==MaxSearchApi::$statusAge)', $countryStart);
$countryEnd = $childStart !== false ? $childStart : $ageStart;
$countrySource = $countryStart === false || $countryEnd === false ? '' : substr($source, $countryStart, $countryEnd - $countryStart);
countryFreeTextCheck('handler preserves exact directory lookup owner', substr_count($countrySource, 'MaxSearchApi::getCountryByName($country)') === 1, true);
countryFreeTextCheck('handler normalizes through one value contract call', substr_count($countrySource, 'CountryValueContract::fromDirectoryId') === 1, true);
countryFreeTextCheck('handler applies through one update-only boundary', substr_count($countrySource, 'ExistingWizardStepApplicationService::apply(') === 1, true);
countryFreeTextCheck('handler no longer directly writes country', strpos($countrySource, 'MaxSearchApi::saveLastValue') === false, true);
countryFreeTextCheck('handler preserves AI routing owner', strpos($countrySource, 'self::routeFreeTextToAi($message,$chat_id)') !== false, true);

EditFlowService::clearSnapshot(1600);

// Keep the original country-step cases above on their original adapter. These
// cases execute the existing edit action, shared controller, country owner and
// repository against an opt-in persistent Bitrix test double; no provider calls.
require_once __DIR__ . '/../services/DialogueController.php';

function countryFreeTextMenuReset(int $chatId): CountryFreeTextMessenger
{
    $messenger = countryFreeTextReset($chatId, false);
    CountryFreeTextFakeData::$persistentSteps = true;
    MaxSearchApi::$missingNeeds = [];
    $lock = InteractionGuard::lockPath($chatId, 'dedupe.edit_menu');
    if (is_file($lock)) unlink($lock);
    $context = TripContextMetadataPolicy::toStartValue([
        'budget'=>['max'=>250000, 'currency'=>'RUB', 'basis'=>'total'],
        'preferences'=>['Тихий отель'], 'negative_preferences'=>['Без ночных дискотек'],
    ]);
    CountryFreeTextFakeData::$rows = [
        ['ID'=>10, 'UF_CHAT_ID'=>$chatId, 'UF_STATUS'=>MaxSearchApi::$statusStart, 'UF_VALUE'=>$context],
    ];
    $needs = [
        MaxSearchApi::$statusCityChoose=>'1', MaxSearchApi::$statusContryChoose=>'4',
        MaxSearchApi::$statusAdults=>'2', MaxSearchApi::$statusChild=>'1', MaxSearchApi::$statusAge=>'6',
        MaxSearchApi::$statusStars=>'4', MaxSearchApi::$statusMeal=>'7',
        MaxSearchApi::$statusNights=>'8-10', MaxSearchApi::$statusDate=>'15.12.2030',
    ];
    foreach ($needs as $status => $value) {
        CountryFreeTextFakeData::$rows[] = ['ID'=>20 + count(CountryFreeTextFakeData::$rows), 'UF_CHAT_ID'=>$chatId, 'UF_STATUS'=>$status, 'UF_VALUE'=>$value];
    }
    CountryFreeTextFakeData::$rows[] = ['ID'=>90, 'UF_CHAT_ID'=>$chatId, 'UF_STATUS'=>MaxSearchApi::$statusCheck, 'UF_VALUE'=>'initial-check'];
    return $messenger;
}

function countryFreeTextNeeds(int $chatId): array
{
    $needs = MaxSearchApi::getSavedData($chatId);
    ksort($needs);
    return $needs;
}

function countryFreeTextCountryWrites(): array
{
    return array_values(array_filter(CountryFreeTextFakeData::$writes,
        static fn(array $write): bool => $write['status'] === MaxSearchApi::$statusContryChoose));
}

function countryFreeTextMenuInput(int $chatId, string $platform, string $text): array
{
    return ['platform'=>$platform, 'user'=>['chat_id'=>$chatId], 'message_id'=>'country-menu-'.$chatId, 'text'=>$text];
}

$menuChats = [];
$menuChatId = 2100;
$controller = new DialogueController();
try {
    foreach (['max', 'telegram'] as $platform) {
        $chatId = ++$menuChatId; $menuChats[] = $chatId;
        $messenger = countryFreeTextMenuReset($chatId);
        $before = countryFreeTextNeeds($chatId);
        $history = CountryFreeTextFakeData::$rows;
        countryFreeTextCheck("{$platform} fixture retains the budget in the real saved-data reader", $before['_budget'] ?? null, ['max'=>250000, 'currency'=>'RUB', 'basis'=>'total']);
        countryFreeTextCheck("{$platform} edit callback is handled", EditCallbackAction::handle($chatId, 'edit_params'), true);
        countryFreeTextCheck("{$platform} successful edit menu activates its marker", MaxSearchApi::$editMode, 'menu');
        countryFreeTextCheck("{$platform} edit menu appends one persisted check step", [CountryFreeTextFakeData::$adds, MaxSearchApi::getCurentStatus($chatId)], [1, MaxSearchApi::$statusCheck]);
        $snapshot = EditFlowService::captureSnapshot($chatId, false); ksort($snapshot);
        countryFreeTextCheck("{$platform} menu captures the complete original trip", $snapshot, $before);
        $incoming = countryFreeTextMenuInput($chatId, $platform, $platform === 'max' ? 'Мальдивы' : '  мАлЬдИвЫ  ');
        countryFreeTextCheck("{$platform} shared envelope retains platform and literal text", array_intersect_key(DialogueController::messageEnvelope($incoming), array_flip(['text', '_platform'])), ['text'=>$incoming['text'], '_platform'=>$platform]);
        countryFreeTextCheck("{$platform} menu country text is handled", $controller->handleIncomingMessage($incoming), true);
        $expected = $before; $expected[MaxSearchApi::$statusContryChoose] = '8';
        countryFreeTextCheck("{$platform} country edit preserves every other need and context value", countryFreeTextNeeds($chatId), $expected);
        countryFreeTextCheck("{$platform} country is applied once", count(countryFreeTextCountryWrites()), 1);
        countryFreeTextCheck("{$platform} country write targets a new step after the original history", (countryFreeTextCountryWrites()[0]['id'] ?? 0) > max(array_column($history, 'ID')), true);
        countryFreeTextCheck("{$platform} original rows remain unchanged", array_slice(CountryFreeTextFakeData::$rows, 0, count($history)), $history);
        countryFreeTextCheck("{$platform} flow is menu then country then one check", MaxSearchApi::$transitions, [MaxSearchApi::$statusCheck, MaxSearchApi::$statusContryChoose, MaxSearchApi::$statusCheck]);
        countryFreeTextCheck("{$platform} flow renders only menu and check", count($messenger->buttons), 2);
        countryFreeTextCheck("{$platform} final answer is the actual check view", strpos($messenger->buttons[1][1] ?? '', 'Готово! Проверьте параметры') !== false, true);
        countryFreeTextCheck("{$platform} edit completes without AI or an adults question", [MaxSearchApi::$editMode, MaxSearchApi::$adultsViews, MaxSearchApi::$aiApplications, $messenger->sent], ['', [], [], []]);
        countryFreeTextCheck("{$platform} direct save is only the check generation", array_column(MaxSearchApi::$directSaves, 1), [MaxSearchApi::$statusCheck]);
        $settledRows = CountryFreeTextFakeData::$rows;
        $controller->handleIncomingMessage($incoming);
        countryFreeTextCheck("{$platform} repeated text after completion cannot apply another country step", [CountryFreeTextFakeData::$rows, count(countryFreeTextCountryWrites())], [$settledRows, 1]);
        countryFreeTextCheck("{$platform} completed edit restores ordinary check guidance", strpos($messenger->buttons[2][1] ?? '', 'Параметры пока не изменены') !== false, true);

        $chatId = ++$menuChatId; $menuChats[] = $chatId;
        $messenger = countryFreeTextMenuReset($chatId);
        $beforeRows = CountryFreeTextFakeData::$rows;
        countryFreeTextCheck("{$platform} ordinary check country text is handled", $controller->handleIncomingMessage(countryFreeTextMenuInput($chatId, $platform, 'Мальдивы')), true);
        countryFreeTextCheck("{$platform} ordinary check does not start an edit or resolve a country", [CountryFreeTextFakeData::$rows, MaxSearchApi::$editMode, MaxSearchApi::$countryLookups, MaxSearchApi::$transitions], [$beforeRows, '', [], []]);
        countryFreeTextCheck("{$platform} ordinary check keeps neutral edit guidance", strpos($messenger->buttons[0][1] ?? '', 'Параметры пока не изменены') !== false, true);

        foreach (['Не Мальдивы', 'Мальдивы?', 'Мальдивы в ноябре', 'Неизвестная', 'Неканоничная', 'Псевдоним'] as $text) {
            $chatId = ++$menuChatId; $menuChats[] = $chatId;
            $messenger = countryFreeTextMenuReset($chatId);
            // Even a permissive directory result cannot turn an alias/question
            // into an exact full-name match. Production directory code is unchanged.
            MaxSearchApi::$countryLookupOverrides = [
                'Мальдивы?'=>['ID'=>'8', 'NAME'=>'Мальдивы'],
                'Псевдоним'=>['ID'=>'8', 'NAME'=>'Мальдивы'],
            ];
            EditCallbackAction::handle($chatId, 'edit_params');
            $beforeRows = CountryFreeTextFakeData::$rows;
            $snapshot = EditFlowService::captureSnapshot($chatId, false);
            countryFreeTextCheck("{$platform} {$text} keeps the existing check route", $controller->handleIncomingMessage(countryFreeTextMenuInput($chatId, $platform, $text)), true);
            countryFreeTextCheck("{$platform} {$text} cannot mutate or append a country step", [CountryFreeTextFakeData::$rows, CountryFreeTextFakeData::$updates], [$beforeRows, 0]);
            countryFreeTextCheck("{$platform} {$text} retains the open menu and original snapshot", [MaxSearchApi::$editMode, EditFlowService::captureSnapshot($chatId, false)], ['menu', $snapshot]);
            countryFreeTextCheck("{$platform} {$text} has one neutral answer and no AI application", [count($messenger->buttons), strpos($messenger->buttons[1][1] ?? '', 'Параметры пока не изменены') !== false, MaxSearchApi::$aiApplications], [2, true, []]);
        }

        $chatId = ++$menuChatId; $menuChats[] = $chatId;
        $messenger = countryFreeTextMenuReset($chatId);
        $before = countryFreeTextNeeds($chatId);
        EditCallbackAction::handle($chatId, 'edit_params');
        countryFreeTextCheck("{$platform} actual back_check action is handled", WizardCallbackAction::handle($chatId, 'back_check', $platform), true);
        countryFreeTextCheck("{$platform} successful back_check clears menu and preserves the trip", [MaxSearchApi::$editMode, MaxSearchApi::getCurentStatus($chatId), countryFreeTextNeeds($chatId)], ['', MaxSearchApi::$statusCheck, $before]);
        $beforeRows = CountryFreeTextFakeData::$rows;
        $controller->handleIncomingMessage(countryFreeTextMenuInput($chatId, $platform, 'Мальдивы'));
        countryFreeTextCheck("{$platform} country text after back_check cannot reuse the old menu", [CountryFreeTextFakeData::$rows, countryFreeTextCountryWrites()], [$beforeRows, []]);

        $chatId = ++$menuChatId; $menuChats[] = $chatId;
        $messenger = countryFreeTextMenuReset($chatId);
        $beforeRows = CountryFreeTextFakeData::$rows;
        $messenger->buttonsSucceed = false;
        EditCallbackAction::handle($chatId, 'edit_params');
        countryFreeTextCheck("{$platform} failed menu delivery never activates a state or marker", [CountryFreeTextFakeData::$rows, MaxSearchApi::$editMode, MaxSearchApi::$transitions], [$beforeRows, '', []]);
        $messenger->buttonsSucceed = true;
        $controller->handleIncomingMessage(countryFreeTextMenuInput($chatId, $platform, 'Мальдивы'));
        countryFreeTextCheck("{$platform} failed menu cannot authorize a later country change", [CountryFreeTextFakeData::$rows, MaxSearchApi::$countryLookups], [$beforeRows, []]);

        $chatId = ++$menuChatId; $menuChats[] = $chatId;
        $messenger = countryFreeTextMenuReset($chatId);
        EditCallbackAction::handle($chatId, 'edit_params');
        $beforeRows = CountryFreeTextFakeData::$rows;
        $snapshot = EditFlowService::captureSnapshot($chatId, false);
        CountryFreeTextFakeData::$failAddStatus = MaxSearchApi::$statusContryChoose;
        countryFreeTextCheck("{$platform} failed country-step append returns to check guidance", $controller->handleIncomingMessage(countryFreeTextMenuInput($chatId, $platform, 'Мальдивы')), true);
        countryFreeTextCheck("{$platform} failed append cannot overwrite the historical country row", [CountryFreeTextFakeData::$rows, CountryFreeTextFakeData::$updates], [$beforeRows, 0]);
        countryFreeTextCheck("{$platform} failed append retains current check, menu and snapshot", [MaxSearchApi::getCurentStatus($chatId), MaxSearchApi::$editMode, EditFlowService::captureSnapshot($chatId, false)], [MaxSearchApi::$statusCheck, 'menu', $snapshot]);
        countryFreeTextCheck("{$platform} failed append sends no false check confirmation", [count($messenger->buttons), strpos($messenger->buttons[1][1] ?? '', 'Параметры пока не изменены') !== false, MaxSearchApi::$adultsViews], [2, true, []]);

        $chatId = ++$menuChatId; $menuChats[] = $chatId;
        $messenger = countryFreeTextMenuReset($chatId);
        EditCallbackAction::handle($chatId, 'edit_params');
        $beforeRows = CountryFreeTextFakeData::$rows;
        $messenger->buttonsSucceed = false;
        countryFreeTextCheck("{$platform} failed check view reports its actual send result", DialogueView::check($chatId), false);
        countryFreeTextCheck("{$platform} failed check does not clear the open menu or persist a generation", [CountryFreeTextFakeData::$rows, MaxSearchApi::$editMode, MaxSearchApi::$directSaves], [$beforeRows, 'menu', []]);

        $chatId = ++$menuChatId; $menuChats[] = $chatId;
        $messenger = countryFreeTextMenuReset($chatId);
        EditCallbackAction::handle($chatId, 'edit_params');
        EditCallbackAction::handle($chatId, 'edit_city');
        $beforeRows = CountryFreeTextFakeData::$rows;
        countryFreeTextCheck("{$platform} actual other-field edit replaces the menu marker", [MaxSearchApi::$editMode, MaxSearchApi::getCurentStatus($chatId)], ['city', MaxSearchApi::$statusCityChoose]);
        countryFreeTextCheck("{$platform} country shortcut cannot take another field's input", StateMessageHandler::handleMenuCountryEdit(DialogueController::messageEnvelope(countryFreeTextMenuInput($chatId, $platform, 'Мальдивы')), $chatId), false);
        countryFreeTextCheck("{$platform} other-field guard preserves rows without a country lookup", [CountryFreeTextFakeData::$rows, MaxSearchApi::$countryLookups], [$beforeRows, []]);
    }
} finally {
    foreach ($menuChats as $chatId) {
        EditFlowService::clearSnapshot($chatId);
        $lock = InteractionGuard::lockPath($chatId, 'dedupe.edit_menu');
        if (is_file($lock)) unlink($lock);
    }
    CountryFreeTextFakeData::$persistentSteps = false;
    IntegrationRegistry::resetForTests();
}

echo "\n--------------------------------\n";
echo $failed === 0 ? "COUNTRY FREE-TEXT APPLICATION: OK\n" : "COUNTRY FREE-TEXT APPLICATION: FAIL ({$failed})\n";
exit($failed === 0 ? 0 : 1);
