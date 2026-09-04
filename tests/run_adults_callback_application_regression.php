<?php

declare(strict_types=1);

final class AdultsCallbackFakeResult
{
    private array $rows;
    private int $index = 0;
    public function __construct(array $rows) { $this->rows = array_values($rows); }
    public function fetch() { return $this->rows[$this->index++] ?? false; }
}

final class AdultsCallbackFakeEntity
{
    public function getDataClass(): string { return AdultsCallbackFakeData::class; }
}

final class AdultsCallbackFakeData
{
    public static array $rows = [];
    public static int $adds = 0;
    public static int $updates = 0;

    public static function getList(array $query): AdultsCallbackFakeResult
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
        return new AdultsCallbackFakeResult($rows);
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
eval('namespace Bitrix\\Highloadblock { class HighloadBlockTable { public static function getById($id) { return new \\AdultsCallbackFakeResult([["ID"=>$id]]); } public static function compileEntity($row) { return new \\AdultsCallbackFakeEntity(); } } }');

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
    public static int $currentStatus = 67;
    public static array $transitions = [];
    public static array $directSaves = [];
    public static array $childViews = [];
    public static array $funnelEvents = [];

    public static function getCurentStatus($chatId): int { return self::$currentStatus; }
    public static function deletePrevMessage($chatId, $withButtons = false): void {}
    public static function setStatus($chatId, $status): void
    {
        self::$currentStatus = (int)$status;
        self::$transitions[] = (int)$status;
    }
    public static function getEditMode($chatId): string { return ''; }
    public static function setEditMode($chatId, $field): void {}
    public static function getSavedData($chatId): array { return [self::$statusAdults => self::storedValue($chatId)]; }
    public static function formatSavedData(array $data): array { return []; }
    public static function funnelLog($chatId, $event, array $data = []): void { self::$funnelEvents[] = [$event, $data]; }
    public static function saveLastValue($chatId, $status, $value): bool
    {
        self::$directSaves[] = [$chatId, $status, $value];
        return true;
    }
    public static function showChildButtons($chatId): bool
    {
        self::$childViews[] = $chatId;
        self::setStatus($chatId, self::$statusChild);
        return true;
    }

    private static function storedValue($chatId)
    {
        foreach (array_reverse(AdultsCallbackFakeData::$rows) as $row) {
            if (($row['UF_CHAT_ID'] ?? null) == $chatId && ($row['UF_STATUS'] ?? null) == self::$statusAdults) {
                return $row['UF_VALUE'] ?? null;
            }
        }
        return null;
    }
}

require_once __DIR__ . '/../actions/callbacks/WizardCallbackAction.php';

$failed = 0;
function adultsCallbackCheck(string $name, $actual, $expected): void
{
    global $failed;
    if ($actual === $expected) { echo "PASS  {$name}\n"; return; }
    echo "FAIL  {$name}\n";
    echo '      expected: ' . json_encode($expected, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
    echo '      actual:   ' . json_encode($actual, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
    $failed++;
}

function adultsCallbackReset(int $chatId, bool $withStep = true): void
{
    @unlink(InteractionGuard::lockPath($chatId, 'wizard.forward'));
    MaxSearchApi::$currentStatus = (int)MaxSearchApi::$statusAdults;
    MaxSearchApi::$transitions = [];
    MaxSearchApi::$directSaves = [];
    MaxSearchApi::$childViews = [];
    MaxSearchApi::$funnelEvents = [];
    AdultsCallbackFakeData::$adds = 0;
    AdultsCallbackFakeData::$updates = 0;
    AdultsCallbackFakeData::$rows = [
        ['ID'=>10, 'UF_CHAT_ID'=>$chatId, 'UF_STATUS'=>MaxSearchApi::$statusStart, 'UF_VALUE'=>''],
    ];
    if ($withStep) {
        AdultsCallbackFakeData::$rows[] = ['ID'=>20, 'UF_CHAT_ID'=>$chatId, 'UF_STATUS'=>MaxSearchApi::$statusAdults, 'UF_VALUE'=>'1'];
    }
}

adultsCallbackReset(600);
$handled = WizardCallbackAction::handle(600, 'adults_4');
adultsCallbackCheck('adults callback is consumed', $handled, true);
adultsCallbackCheck('adults callback stores exact value', MaxSearchApi::getSavedData(600)[MaxSearchApi::$statusAdults] ?? null, '4');
adultsCallbackCheck('adults callback advances exactly once to children', MaxSearchApi::$transitions, [MaxSearchApi::$statusChild]);
adultsCallbackCheck('adults callback renders children once', MaxSearchApi::$childViews, [600]);
adultsCallbackCheck('adults callback updates the step exactly once', AdultsCallbackFakeData::$updates, 1);
adultsCallbackCheck('adults callback avoids legacy direct write', MaxSearchApi::$directSaves, []);
adultsCallbackCheck('adults callback preserves funnel event', MaxSearchApi::$funnelEvents, [['tourists_selected', ['stage'=>'adults','payload'=>'adults_4']]]);

$handledAgain = WizardCallbackAction::handle(600, 'adults_4');
adultsCallbackCheck('duplicate delivery is consumed after state advance', $handledAgain, true);
adultsCallbackCheck('duplicate delivery preserves value', MaxSearchApi::getSavedData(600)[MaxSearchApi::$statusAdults] ?? null, '4');
adultsCallbackCheck('duplicate delivery does not render again', MaxSearchApi::$childViews, [600]);
adultsCallbackCheck('duplicate delivery does not transition again', MaxSearchApi::$transitions, [MaxSearchApi::$statusChild]);
adultsCallbackCheck('duplicate delivery does not update again', AdultsCallbackFakeData::$updates, 1);
adultsCallbackCheck('duplicate delivery does not duplicate funnel event', count(MaxSearchApi::$funnelEvents), 1);

adultsCallbackReset(601);
MaxSearchApi::$currentStatus = (int)MaxSearchApi::$statusChild;
$handledStale = WizardCallbackAction::handle(601, 'adults_6');
adultsCallbackCheck('stale adults callback is consumed', $handledStale, true);
adultsCallbackCheck('stale adults callback preserves stored value', MaxSearchApi::getSavedData(601)[MaxSearchApi::$statusAdults] ?? null, '1');
adultsCallbackCheck('stale adults callback renders no view', MaxSearchApi::$childViews, []);
adultsCallbackCheck('stale adults callback makes no transition', MaxSearchApi::$transitions, []);
adultsCallbackCheck('stale adults callback makes no update', AdultsCallbackFakeData::$updates, 0);
adultsCallbackCheck('stale adults callback emits no funnel event', MaxSearchApi::$funnelEvents, []);

adultsCallbackReset(602, false);
$before = count(AdultsCallbackFakeData::$rows);
$handledMissing = WizardCallbackAction::handle(602, 'adults_2');
adultsCallbackCheck('missing adults step is consumed', $handledMissing, true);
adultsCallbackCheck('missing adults step is not inserted', count(AdultsCallbackFakeData::$rows), $before);
adultsCallbackCheck('missing adults step does not call add', AdultsCallbackFakeData::$adds, 0);
adultsCallbackCheck('missing adults step makes no update', AdultsCallbackFakeData::$updates, 0);
adultsCallbackCheck('missing adults step renders no next view', MaxSearchApi::$childViews, []);
adultsCallbackCheck('missing adults step makes no transition', MaxSearchApi::$transitions, []);

$source = (string)file_get_contents(__DIR__ . '/../actions/callbacks/WizardCallbackAction.php');
adultsCallbackCheck('action applies adults through update-only boundary', strpos($source, '$adults = str_replace') !== false && strpos($source, 'MaxSearchApi::$statusAdults,') !== false, true);
adultsCallbackCheck('action keeps the shared forward lock', strpos($source, "InteractionGuard::synchronized(\$chatId, 'wizard.forward'") !== false, true);
adultsCallbackCheck('action keeps stale check inside the shared lock', strpos($source, 'self::staleForwardCallback($chatId, $q)') !== false, true);

foreach ([600, 601, 602] as $chatId) {
    @unlink(InteractionGuard::lockPath($chatId, 'wizard.forward'));
}

echo "\n--------------------------\n";
echo $failed === 0 ? "ADULTS CALLBACK APPLICATION: OK\n" : "ADULTS CALLBACK APPLICATION: FAIL ({$failed})\n";
exit($failed === 0 ? 0 : 1);
