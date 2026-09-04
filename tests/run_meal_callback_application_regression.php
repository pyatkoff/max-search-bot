<?php

declare(strict_types=1);

final class MealCallbackFakeResult
{
    private array $rows;
    private int $index = 0;
    public function __construct(array $rows) { $this->rows = array_values($rows); }
    public function fetch() { return $this->rows[$this->index++] ?? false; }
}

final class MealCallbackFakeEntity
{
    public function getDataClass(): string { return MealCallbackFakeData::class; }
}

final class MealCallbackFakeData
{
    public static array $rows = [];
    public static int $adds = 0;
    public static int $updates = 0;

    public static function getList(array $query): MealCallbackFakeResult
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
        return new MealCallbackFakeResult($rows);
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
eval('namespace Bitrix\\Highloadblock { class HighloadBlockTable { public static function getById($id) { return new \\MealCallbackFakeResult([["ID"=>$id]]); } public static function compileEntity($row) { return new \\MealCallbackFakeEntity(); } } }');

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
    public static int $currentStatus = 71;
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
    public static function getSavedData($chatId): array { return [self::$statusMeal => self::storedValue($chatId)]; }
    public static function formatSavedData(array $data): array { return []; }
    public static function funnelLog($chatId, $event, array $data = []): void {}
    public static function saveLastValue($chatId, $status, $value): bool
    {
        self::$directSaves[] = [$chatId, $status, $value];
        return true;
    }

    private static function storedValue($chatId)
    {
        foreach (array_reverse(MealCallbackFakeData::$rows) as $row) {
            if (($row['UF_CHAT_ID'] ?? null) == $chatId && ($row['UF_STATUS'] ?? null) == self::$statusMeal) {
                return $row['UF_VALUE'] ?? null;
            }
        }
        return null;
    }
}

require_once __DIR__ . '/../actions/callbacks/WizardCallbackAction.php';

final class MealCallbackMessenger implements MessengerInterface
{
    public array $sent = [];
    public array $buttons = [];
    public function send($chatId, string $text): bool { $this->sent[] = [$chatId, $text]; return true; }
    public function sendWithButtons($chatId, string $text, array $buttons): bool { $this->buttons[] = [$chatId, $text, $buttons]; return true; }
    public function sendContactRequest($chatId, string $text, string $manualCallback, string $backCallback): bool { return true; }
}

$failed = 0;
function mealCallbackCheck(string $name, $actual, $expected): void
{
    global $failed;
    if ($actual === $expected) { echo "PASS  {$name}\n"; return; }
    echo "FAIL  {$name}\n";
    echo '      expected: ' . json_encode($expected, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
    echo '      actual:   ' . json_encode($actual, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
    $failed++;
}

function mealCallbackReset(int $chatId, bool $withStep = true): MealCallbackMessenger
{
    EditFlowService::clearSnapshot($chatId);
    @unlink(InteractionGuard::lockPath($chatId, 'wizard.forward'));
    MaxSearchApi::$currentStatus = (int)MaxSearchApi::$statusMeal;
    MaxSearchApi::$editMode = '';
    MaxSearchApi::$transitions = [];
    MaxSearchApi::$directSaves = [];
    MealCallbackFakeData::$adds = 0;
    MealCallbackFakeData::$updates = 0;
    MealCallbackFakeData::$rows = [
        ['ID'=>10, 'UF_CHAT_ID'=>$chatId, 'UF_STATUS'=>MaxSearchApi::$statusStart, 'UF_VALUE'=>''],
    ];
    if ($withStep) {
        MealCallbackFakeData::$rows[] = ['ID'=>20, 'UF_CHAT_ID'=>$chatId, 'UF_STATUS'=>MaxSearchApi::$statusMeal, 'UF_VALUE'=>'3'];
    }
    $messenger = new MealCallbackMessenger();
    IntegrationRegistry::resetForTests($messenger);
    return $messenger;
}

$messenger = mealCallbackReset(800);
$handled = WizardCallbackAction::handle(800, 'meal_7');
mealCallbackCheck('meal callback is consumed', $handled, true);
mealCallbackCheck('meal callback stores exact directory id', MaxSearchApi::getSavedData(800)[MaxSearchApi::$statusMeal] ?? null, '7');
mealCallbackCheck('meal callback advances exactly once to nights', MaxSearchApi::$transitions, [MaxSearchApi::$statusNights]);
mealCallbackCheck('meal callback renders nights once', count($messenger->buttons), 1);
mealCallbackCheck('meal callback updates the step exactly once', MealCallbackFakeData::$updates, 1);
mealCallbackCheck('meal callback avoids legacy direct write', MaxSearchApi::$directSaves, []);

$handledAgain = WizardCallbackAction::handle(800, 'meal_7');
mealCallbackCheck('duplicate delivery is consumed after state advance', $handledAgain, true);
mealCallbackCheck('duplicate delivery preserves meal value', MaxSearchApi::getSavedData(800)[MaxSearchApi::$statusMeal] ?? null, '7');
mealCallbackCheck('duplicate delivery does not render again', count($messenger->buttons), 1);
mealCallbackCheck('duplicate delivery does not transition again', MaxSearchApi::$transitions, [MaxSearchApi::$statusNights]);
mealCallbackCheck('duplicate delivery does not update again', MealCallbackFakeData::$updates, 1);

$messenger = mealCallbackReset(801);
WizardCallbackAction::handle(801, 'meal_999');
mealCallbackCheck('any-meal callback preserves exact 999 id', MaxSearchApi::getSavedData(801)[MaxSearchApi::$statusMeal] ?? null, '999');

$messenger = mealCallbackReset(802);
MaxSearchApi::$currentStatus = (int)MaxSearchApi::$statusNights;
$handledStale = WizardCallbackAction::handle(802, 'meal_5');
mealCallbackCheck('stale meal callback is consumed', $handledStale, true);
mealCallbackCheck('stale meal callback preserves stored value', MaxSearchApi::getSavedData(802)[MaxSearchApi::$statusMeal] ?? null, '3');
mealCallbackCheck('stale meal callback renders no view', count($messenger->buttons), 0);
mealCallbackCheck('stale meal callback makes no transition', MaxSearchApi::$transitions, []);
mealCallbackCheck('stale meal callback makes no update', MealCallbackFakeData::$updates, 0);

$messenger = mealCallbackReset(803);
MaxSearchApi::$editMode = 'meal';
WizardCallbackAction::handle(803, 'meal_4');
mealCallbackCheck('edit callback stores exact directory id', MaxSearchApi::getSavedData(803)[MaxSearchApi::$statusMeal] ?? null, '4');
mealCallbackCheck('edit callback returns to check', MaxSearchApi::$transitions, [MaxSearchApi::$statusCheck]);
mealCallbackCheck('edit callback renders check once', count($messenger->buttons), 1);
mealCallbackCheck('edit callback clears edit mode', MaxSearchApi::$editMode, '');

$messenger = mealCallbackReset(804, false);
$before = count(MealCallbackFakeData::$rows);
$handledMissing = WizardCallbackAction::handle(804, 'meal_5');
mealCallbackCheck('missing meal step is consumed', $handledMissing, true);
mealCallbackCheck('missing meal step is not inserted', count(MealCallbackFakeData::$rows), $before);
mealCallbackCheck('missing meal step does not call add', MealCallbackFakeData::$adds, 0);
mealCallbackCheck('missing meal step makes no update', MealCallbackFakeData::$updates, 0);
mealCallbackCheck('missing meal step renders no next view', count($messenger->buttons), 0);
mealCallbackCheck('missing meal step makes no transition', MaxSearchApi::$transitions, []);

$messenger = mealCallbackReset(805);
WizardCallbackAction::handle(805, 'back_nights');
mealCallbackCheck('back from nights keeps stored meal value', MaxSearchApi::getSavedData(805)[MaxSearchApi::$statusMeal] ?? null, '3');
mealCallbackCheck('back from nights renders nights once', count($messenger->buttons), 1);
mealCallbackCheck('back from nights does not update meal', MealCallbackFakeData::$updates, 0);

$source = (string)file_get_contents(__DIR__ . '/../actions/callbacks/WizardCallbackAction.php');
mealCallbackCheck('action applies meal through update-only boundary', strpos($source, '$meal = str_replace') !== false && strpos($source, 'MaxSearchApi::$statusMeal,') !== false, true);
mealCallbackCheck('action keeps the shared forward lock', strpos($source, "InteractionGuard::synchronized(\$chatId, 'wizard.forward'") !== false, true);
mealCallbackCheck('action keeps stale check inside the shared lock', strpos($source, 'self::staleForwardCallback($chatId, $q)') !== false, true);

foreach ([800, 801, 802, 803, 804, 805] as $chatId) {
    EditFlowService::clearSnapshot($chatId);
    @unlink(InteractionGuard::lockPath($chatId, 'wizard.forward'));
}

echo "\n--------------------------\n";
echo $failed === 0 ? "MEAL CALLBACK APPLICATION: OK\n" : "MEAL CALLBACK APPLICATION: FAIL ({$failed})\n";
exit($failed === 0 ? 0 : 1);
