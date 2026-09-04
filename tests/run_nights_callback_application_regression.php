<?php

declare(strict_types=1);

final class NightsCallbackFakeResult
{
    private array $rows;
    private int $index = 0;
    public function __construct(array $rows) { $this->rows = array_values($rows); }
    public function fetch() { return $this->rows[$this->index++] ?? false; }
}

final class NightsCallbackFakeEntity
{
    public function getDataClass(): string { return NightsCallbackFakeData::class; }
}

final class NightsCallbackFakeData
{
    public static array $rows = [];
    public static int $adds = 0;
    public static int $updates = 0;

    public static function getList(array $query): NightsCallbackFakeResult
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
        return new NightsCallbackFakeResult($rows);
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
eval('namespace Bitrix\\Highloadblock { class HighloadBlockTable { public static function getById($id) { return new \\NightsCallbackFakeResult([["ID"=>$id]]); } public static function compileEntity($row) { return new \\NightsCallbackFakeEntity(); } } }');

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
    public static int $currentStatus = 72;
    public static string $editMode = '';
    public static array $transitions = [];
    public static array $directSaves = [];

    public static function getCurentStatus($chatId): int { return self::$currentStatus; }
    public static function deletePrevMessage($chatId, $withButtons = false): void {}
    public static function setStatus($chatId, $status): void
    {
        self::$currentStatus = (int)$status;
        self::$transitions[] = (int)$status;
    }
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
        foreach (array_reverse(NightsCallbackFakeData::$rows) as $row) {
            if (($row['UF_CHAT_ID'] ?? null) == $chatId && ($row['UF_STATUS'] ?? null) == self::$statusNights) {
                return $row['UF_VALUE'] ?? null;
            }
        }
        return null;
    }
}

require_once __DIR__ . '/../actions/callbacks/WizardCallbackAction.php';

$nightsCallbackTransitionLog = sys_get_temp_dir() . '/max-search-nights-callback-transition-' . getmypid() . '.log';
@unlink($nightsCallbackTransitionLog);
DiagnosticLogger::setFile($nightsCallbackTransitionLog);

final class NightsCallbackMessenger implements MessengerInterface
{
    public array $sent = [];
    public array $buttons = [];
    public function send($chatId, string $text): bool { $this->sent[] = [$chatId, $text]; return true; }
    public function sendWithButtons($chatId, string $text, array $buttons): bool { $this->buttons[] = [$chatId, $text, $buttons]; return true; }
    public function sendContactRequest($chatId, string $text, string $manualCallback, string $backCallback): bool { return true; }
}

$failed = 0;
function nightsCallbackCheck(string $name, $actual, $expected): void
{
    global $failed;
    if ($actual === $expected) { echo "PASS  {$name}\n"; return; }
    echo "FAIL  {$name}\n";
    echo '      expected: ' . json_encode($expected, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
    echo '      actual:   ' . json_encode($actual, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
    $failed++;
}

function nightsCallbackReset(int $chatId, bool $withStep = true): NightsCallbackMessenger
{
    global $nightsCallbackTransitionLog;
    EditFlowService::clearSnapshot($chatId);
    @unlink($nightsCallbackTransitionLog);
    @unlink(InteractionGuard::lockPath($chatId, 'wizard.forward'));
    MaxSearchApi::$currentStatus = (int)MaxSearchApi::$statusNights;
    MaxSearchApi::$editMode = '';
    MaxSearchApi::$transitions = [];
    MaxSearchApi::$directSaves = [];
    NightsCallbackFakeData::$adds = 0;
    NightsCallbackFakeData::$updates = 0;
    NightsCallbackFakeData::$rows = [
        ['ID'=>10, 'UF_CHAT_ID'=>$chatId, 'UF_STATUS'=>MaxSearchApi::$statusStart, 'UF_VALUE'=>''],
    ];
    if ($withStep) {
        NightsCallbackFakeData::$rows[] = ['ID'=>20, 'UF_CHAT_ID'=>$chatId, 'UF_STATUS'=>MaxSearchApi::$statusNights, 'UF_VALUE'=>'6-8'];
    }
    $messenger = new NightsCallbackMessenger();
    IntegrationRegistry::resetForTests($messenger);
    return $messenger;
}

function nightsCallbackTransitionEvents(): array
{
    global $nightsCallbackTransitionLog;
    $lines = file($nightsCallbackTransitionLog, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
    return array_values(array_filter(array_map(
        static fn(string $line): ?array => json_decode($line, true),
        $lines
    )));
}

$messenger = nightsCallbackReset(500);
$handled = WizardCallbackAction::handle(500, 'nights_9_11');
nightsCallbackCheck('nights callback is consumed', $handled, true);
nightsCallbackCheck('nights callback stores exact range value', MaxSearchApi::getSavedData(500)[MaxSearchApi::$statusNights] ?? null, '9-11');
nightsCallbackCheck('nights callback advances exactly once to date', MaxSearchApi::$transitions, [MaxSearchApi::$statusDate]);
nightsCallbackCheck('nights callback renders calendar once', count($messenger->buttons), 1);
nightsCallbackCheck('nights callback updates the step exactly once', NightsCallbackFakeData::$updates, 1);
nightsCallbackCheck('nights callback avoids legacy direct write', MaxSearchApi::$directSaves, []);
$transitionEvents = nightsCallbackTransitionEvents();
nightsCallbackCheck('nights callback observes nights to date once', count($transitionEvents), 1);
nightsCallbackCheck('nights callback observer is allowed', $transitionEvents[0]['data']['allowed'] ?? null, true);
nightsCallbackCheck('nights callback observer keeps callback scope', $transitionEvents[0]['data']['scope'] ?? null, 'nights_callback');

$handledAgain = WizardCallbackAction::handle(500, 'nights_9_11');
nightsCallbackCheck('duplicate delivery is consumed after state advance', $handledAgain, true);
nightsCallbackCheck('duplicate delivery does not write again', MaxSearchApi::getSavedData(500)[MaxSearchApi::$statusNights] ?? null, '9-11');
nightsCallbackCheck('duplicate delivery does not render again', count($messenger->buttons), 1);
nightsCallbackCheck('duplicate delivery does not transition again', MaxSearchApi::$transitions, [MaxSearchApi::$statusDate]);
nightsCallbackCheck('duplicate delivery does not update again', NightsCallbackFakeData::$updates, 1);
nightsCallbackCheck('duplicate delivery does not emit another observation', count(nightsCallbackTransitionEvents()), 1);

$messenger = nightsCallbackReset(501);
MaxSearchApi::$currentStatus = (int)MaxSearchApi::$statusDate;
$handledStale = WizardCallbackAction::handle(501, 'nights_12_14');
nightsCallbackCheck('stale nights callback is consumed', $handledStale, true);
nightsCallbackCheck('stale nights callback preserves stored value', MaxSearchApi::getSavedData(501)[MaxSearchApi::$statusNights] ?? null, '6-8');
nightsCallbackCheck('stale nights callback renders no view', count($messenger->buttons), 0);
nightsCallbackCheck('stale nights callback makes no transition', MaxSearchApi::$transitions, []);
nightsCallbackCheck('stale nights callback makes no update', NightsCallbackFakeData::$updates, 0);
nightsCallbackCheck('stale nights callback emits no transition observation', nightsCallbackTransitionEvents(), []);

$messenger = nightsCallbackReset(502);
MaxSearchApi::$editMode = 'nights';
WizardCallbackAction::handle(502, 'nights_9_11');
nightsCallbackCheck('edit callback stores exact range', MaxSearchApi::getSavedData(502)[MaxSearchApi::$statusNights] ?? null, '9-11');
nightsCallbackCheck('edit callback returns to check', MaxSearchApi::$transitions, [MaxSearchApi::$statusCheck]);
nightsCallbackCheck('edit callback renders check once', count($messenger->buttons), 1);
nightsCallbackCheck('edit callback clears edit mode', MaxSearchApi::$editMode, '');
nightsCallbackCheck('edit callback emits no nights to date observation', nightsCallbackTransitionEvents(), []);

$messenger = nightsCallbackReset(503, false);
$before = count(NightsCallbackFakeData::$rows);
$handledMissing = WizardCallbackAction::handle(503, 'nights_9_11');
nightsCallbackCheck('missing nights step is consumed', $handledMissing, true);
nightsCallbackCheck('missing nights step is not inserted', count(NightsCallbackFakeData::$rows), $before);
nightsCallbackCheck('missing nights step does not call add', NightsCallbackFakeData::$adds, 0);
nightsCallbackCheck('missing nights step makes no update', NightsCallbackFakeData::$updates, 0);
nightsCallbackCheck('missing nights step renders no next view', count($messenger->buttons), 0);
nightsCallbackCheck('missing nights step makes no transition', MaxSearchApi::$transitions, []);
nightsCallbackCheck('missing nights step emits no transition observation', nightsCallbackTransitionEvents(), []);

$source = (string)file_get_contents(__DIR__ . '/../actions/callbacks/WizardCallbackAction.php');
nightsCallbackCheck('action applies nights through update-only boundary', strpos($source, '$nights = str_replace') !== false && strpos($source, 'MaxSearchApi::$statusNights,') !== false, true);
nightsCallbackCheck('action keeps the shared forward lock', strpos($source, "InteractionGuard::synchronized(\$chatId, 'wizard.forward'") !== false, true);
nightsCallbackCheck('action keeps stale check inside the shared lock', strpos($source, 'self::staleForwardCallback($chatId, $q)') !== false, true);

foreach ([500, 501, 502, 503] as $chatId) {
    EditFlowService::clearSnapshot($chatId);
    @unlink(InteractionGuard::lockPath($chatId, 'wizard.forward'));
}
@unlink($nightsCallbackTransitionLog);

echo "\n--------------------------\n";
echo $failed === 0 ? "NIGHTS CALLBACK APPLICATION: OK\n" : "NIGHTS CALLBACK APPLICATION: FAIL ({$failed})\n";
exit($failed === 0 ? 0 : 1);
