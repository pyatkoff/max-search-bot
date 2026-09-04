<?php

declare(strict_types=1);

final class FreeTextNightsFakeResult
{
    private array $rows;
    private int $index = 0;

    public function __construct(array $rows) { $this->rows = array_values($rows); }
    public function fetch() { return $this->rows[$this->index++] ?? false; }
}

final class FreeTextNightsFakeEntity
{
    public function getDataClass(): string { return FreeTextNightsFakeData::class; }
}

final class FreeTextNightsFakeData
{
    public static array $rows = [];
    public static int $adds = 0;

    public static function getList(array $query): FreeTextNightsFakeResult
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
        return new FreeTextNightsFakeResult($rows);
    }

    public static function update($id, array $fields): bool
    {
        foreach (self::$rows as &$row) {
            if ((int)$row['ID'] !== (int)$id) continue;
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
eval('namespace Bitrix\\Highloadblock { class HighloadBlockTable { public static function getById($id) { return new \\FreeTextNightsFakeResult([["ID"=>$id]]); } public static function compileEntity($row) { return new \\FreeTextNightsFakeEntity(); } } }');

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

    public static function deletePrevMessage($chatId): void {}
    public static function setStatus($chatId, $status): void { self::$transitions[] = $status; }
    public static function getEditMode($chatId): string { return self::$editMode; }
    public static function setEditMode($chatId, $field): void { self::$editMode = (string)$field; }
    public static function getSavedData($chatId): array { return [self::$statusNights => self::storedValue($chatId)]; }
    public static function formatSavedData(array $data): array { return []; }
    public static function funnelLog($chatId, $event, array $data = []): void {}
    public static function saveLastValue($chatId, $status, $value): bool
    {
        self::$directSaves[] = [$chatId, $status, $value];
        return true;
    }

    private static function storedValue($chatId)
    {
        foreach (array_reverse(FreeTextNightsFakeData::$rows) as $row) {
            if (($row['UF_CHAT_ID'] ?? null) == $chatId && ($row['UF_STATUS'] ?? null) == self::$statusNights) {
                return $row['UF_VALUE'] ?? null;
            }
        }
        return null;
    }
}

require_once __DIR__ . '/../handlers/StateMessageHandler.php';

$freeTextNightsTransitionLog = sys_get_temp_dir() . '/max-search-free-text-nights-transition-' . getmypid() . '.log';
@unlink($freeTextNightsTransitionLog);
DiagnosticLogger::setFile($freeTextNightsTransitionLog);

final class FreeTextNightsMessenger implements MessengerInterface
{
    public array $sent = [];
    public array $buttons = [];
    public function send($chatId, string $text): bool { $this->sent[] = [$chatId, $text]; return true; }
    public function sendWithButtons($chatId, string $text, array $buttons): bool { $this->buttons[] = [$chatId, $text, $buttons]; return true; }
    public function sendContactRequest($chatId, string $text, string $manualCallback, string $backCallback): bool { return true; }
}

$failed = 0;
function freeTextNightsCheck(string $name, $actual, $expected): void
{
    global $failed;
    if ($actual === $expected) { echo "PASS  {$name}\n"; return; }
    echo "FAIL  {$name}\n";
    echo '      expected: ' . json_encode($expected, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
    echo '      actual:   ' . json_encode($actual, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
    $failed++;
}

function freeTextNightsReset(int $chatId, bool $withStep = true, bool $stale = false): FreeTextNightsMessenger
{
    global $freeTextNightsTransitionLog;
    EditFlowService::clearSnapshot($chatId);
    @unlink($freeTextNightsTransitionLog);
    MaxSearchApi::$editMode = '';
    MaxSearchApi::$transitions = [];
    MaxSearchApi::$directSaves = [];
    FreeTextNightsFakeData::$adds = 0;
    FreeTextNightsFakeData::$rows = $stale
        ? [
            ['ID'=>10, 'UF_CHAT_ID'=>$chatId, 'UF_STATUS'=>MaxSearchApi::$statusNights, 'UF_VALUE'=>'6'],
            ['ID'=>20, 'UF_CHAT_ID'=>$chatId, 'UF_STATUS'=>MaxSearchApi::$statusStart, 'UF_VALUE'=>''],
        ]
        : [
            ['ID'=>10, 'UF_CHAT_ID'=>$chatId, 'UF_STATUS'=>MaxSearchApi::$statusStart, 'UF_VALUE'=>''],
        ];
    if ($withStep && !$stale) {
        FreeTextNightsFakeData::$rows[] = ['ID'=>20, 'UF_CHAT_ID'=>$chatId, 'UF_STATUS'=>MaxSearchApi::$statusNights, 'UF_VALUE'=>'6'];
    }
    $messenger = new FreeTextNightsMessenger();
    IntegrationRegistry::resetForTests($messenger);
    return $messenger;
}

function freeTextNightsTransitionEvents(): array
{
    global $freeTextNightsTransitionLog;
    if (!is_file($freeTextNightsTransitionLog)) return [];
    $lines = file($freeTextNightsTransitionLog, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
    $events = array_values(array_filter(array_map(
        static fn(string $line): ?array => json_decode($line, true),
        $lines
    )));
    return array_values(array_filter(
        $events,
        static fn(array $event): bool => ($event['component'] ?? null) === 'dialogue_transition'
    ));
}

foreach ([
    ['На 6', '6'],
    ['7-10', '7-10'],
    ['3,4', '3-4'],
] as $index => [$text, $expected]) {
    $chatId = 100 + $index;
    $messenger = freeTextNightsReset($chatId);
    StateMessageHandler::handle(['text'=>$text], $chatId, MaxSearchApi::$statusNights);
    freeTextNightsCheck("{$text} stores canonical value", MaxSearchApi::getSavedData($chatId)[MaxSearchApi::$statusNights] ?? null, $expected);
    freeTextNightsCheck("{$text} advances to calendar", MaxSearchApi::$transitions, [MaxSearchApi::$statusDate]);
    freeTextNightsCheck("{$text} renders calendar once", count($messenger->buttons), 1);
    freeTextNightsCheck("{$text} avoids legacy direct write", MaxSearchApi::$directSaves, []);
    $transitionEvents = freeTextNightsTransitionEvents();
    freeTextNightsCheck("{$text} observes nights to date once", count($transitionEvents), 1);
    freeTextNightsCheck("{$text} observer is allowed", $transitionEvents[0]['data']['allowed'] ?? null, true);
    freeTextNightsCheck("{$text} observer keeps free-text scope", $transitionEvents[0]['data']['scope'] ?? null, 'free_text_nights');
}

$messenger = freeTextNightsReset(200);
StateMessageHandler::handle(['text'=>'не знаю'], 200, MaxSearchApi::$statusNights);
freeTextNightsCheck('invalid text keeps the step value', MaxSearchApi::getSavedData(200)[MaxSearchApi::$statusNights] ?? null, '6');
freeTextNightsCheck('invalid text sends the existing validation prompt', count($messenger->sent), 1);
freeTextNightsCheck('invalid text does not advance', MaxSearchApi::$transitions, []);
freeTextNightsCheck('invalid text emits no transition observation', freeTextNightsTransitionEvents(), []);

$messenger = freeTextNightsReset(300);
MaxSearchApi::$editMode = 'nights';
StateMessageHandler::handle(['text'=>'На 6'], 300, MaxSearchApi::$statusNights);
freeTextNightsCheck('edit flow stores the resolved value', MaxSearchApi::getSavedData(300)[MaxSearchApi::$statusNights] ?? null, '6');
freeTextNightsCheck('edit flow returns to check instead of calendar', MaxSearchApi::$transitions, [MaxSearchApi::$statusCheck]);
freeTextNightsCheck('edit flow renders check once', count($messenger->buttons), 1);
freeTextNightsCheck('edit flow clears edit mode', MaxSearchApi::$editMode, '');
freeTextNightsCheck('edit flow emits no nights to date observation', freeTextNightsTransitionEvents(), []);

foreach ([['stale', 400, true, true], ['missing', 401, false, false]] as [$label, $chatId, $withStep, $stale]) {
    $messenger = freeTextNightsReset($chatId, $withStep, $stale);
    $before = count(FreeTextNightsFakeData::$rows);
    StateMessageHandler::handle(['text'=>'7-10'], $chatId, MaxSearchApi::$statusNights);
    freeTextNightsCheck("{$label} step does not insert", count(FreeTextNightsFakeData::$rows), $before);
    freeTextNightsCheck("{$label} step does not call add", FreeTextNightsFakeData::$adds, 0);
    freeTextNightsCheck("{$label} step does not advance", MaxSearchApi::$transitions, []);
    freeTextNightsCheck("{$label} step renders no next view", count($messenger->buttons), 0);
    freeTextNightsCheck("{$label} step emits no transition observation", freeTextNightsTransitionEvents(), []);
}

$source = (string)file_get_contents(__DIR__ . '/../handlers/StateMessageHandler.php');
freeTextNightsCheck('handler resolves nights through canonical boundary', substr_count($source, "NeedValueResolver::resolve('nights'"), 1);
$nightsStart = strpos($source, 'elseif($status==MaxSearchApi::$statusNights)');
$dateStart = $nightsStart === false ? false : strpos($source, 'elseif($status==MaxSearchApi::$statusDate)', $nightsStart);
$nightsSource = $nightsStart === false || $dateStart === false ? '' : substr($source, $nightsStart, $dateStart - $nightsStart);
freeTextNightsCheck('handler applies nights through one update-only boundary', substr_count($nightsSource, 'ExistingWizardStepApplicationService::apply('), 1);
freeTextNightsCheck('handler no longer parses nights directly', strpos($source, 'NightsParser::parse('), false);

EditFlowService::clearSnapshot(300);
@unlink($freeTextNightsTransitionLog);
echo "\n--------------------------\n";
echo $failed === 0 ? "FREE-TEXT NIGHTS APPLICATION: OK\n" : "FREE-TEXT NIGHTS APPLICATION: FAIL ({$failed})\n";
exit($failed === 0 ? 0 : 1);
