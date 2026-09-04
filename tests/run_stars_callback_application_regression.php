<?php

declare(strict_types=1);

final class StarsCallbackFakeResult
{
    private array $rows;
    private int $index = 0;
    public function __construct(array $rows) { $this->rows = array_values($rows); }
    public function fetch() { return $this->rows[$this->index++] ?? false; }
}

final class StarsCallbackFakeEntity
{
    public function getDataClass(): string { return StarsCallbackFakeData::class; }
}

final class StarsCallbackFakeData
{
    public static array $rows = [];
    public static int $adds = 0;
    public static int $updates = 0;

    public static function getList(array $query): StarsCallbackFakeResult
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
        return new StarsCallbackFakeResult($rows);
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
eval('namespace Bitrix\\Highloadblock { class HighloadBlockTable { public static function getById($id) { return new \\StarsCallbackFakeResult([["ID"=>$id]]); } public static function compileEntity($row) { return new \\StarsCallbackFakeEntity(); } } }');

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
    public static int $currentStatus = 70;
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
    public static function getSavedData($chatId): array { return [self::$statusStars => self::storedValue($chatId)]; }
    public static function formatSavedData(array $data): array { return []; }
    public static function funnelLog($chatId, $event, array $data = []): void {}
    public static function saveLastValue($chatId, $status, $value): bool
    {
        self::$directSaves[] = [$chatId, $status, $value];
        return true;
    }

    private static function storedValue($chatId)
    {
        foreach (array_reverse(StarsCallbackFakeData::$rows) as $row) {
            if (($row['UF_CHAT_ID'] ?? null) == $chatId && ($row['UF_STATUS'] ?? null) == self::$statusStars) {
                return $row['UF_VALUE'] ?? null;
            }
        }
        return null;
    }
}

require_once __DIR__ . '/../actions/callbacks/WizardCallbackAction.php';

final class StarsCallbackMessenger implements MessengerInterface
{
    public array $sent = [];
    public array $buttons = [];
    public function send($chatId, string $text): bool { $this->sent[] = [$chatId, $text]; return true; }
    public function sendWithButtons($chatId, string $text, array $buttons): bool { $this->buttons[] = [$chatId, $text, $buttons]; return true; }
    public function sendContactRequest($chatId, string $text, string $manualCallback, string $backCallback): bool { return true; }
}

$failed = 0;
function starsCallbackCheck(string $name, $actual, $expected): void
{
    global $failed;
    if ($actual === $expected) { echo "PASS  {$name}\n"; return; }
    echo "FAIL  {$name}\n";
    echo '      expected: ' . json_encode($expected, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
    echo '      actual:   ' . json_encode($actual, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
    $failed++;
}

function starsCallbackReset(int $chatId, bool $withStep = true): StarsCallbackMessenger
{
    EditFlowService::clearSnapshot($chatId);
    @unlink(InteractionGuard::lockPath($chatId, 'wizard.forward'));
    MaxSearchApi::$currentStatus = (int)MaxSearchApi::$statusStars;
    MaxSearchApi::$editMode = '';
    MaxSearchApi::$transitions = [];
    MaxSearchApi::$directSaves = [];
    StarsCallbackFakeData::$adds = 0;
    StarsCallbackFakeData::$updates = 0;
    StarsCallbackFakeData::$rows = [
        ['ID'=>10, 'UF_CHAT_ID'=>$chatId, 'UF_STATUS'=>MaxSearchApi::$statusStart, 'UF_VALUE'=>''],
    ];
    if ($withStep) {
        StarsCallbackFakeData::$rows[] = ['ID'=>20, 'UF_CHAT_ID'=>$chatId, 'UF_STATUS'=>MaxSearchApi::$statusStars, 'UF_VALUE'=>'3'];
    }
    $messenger = new StarsCallbackMessenger();
    IntegrationRegistry::resetForTests($messenger);
    return $messenger;
}

$messenger = starsCallbackReset(700);
$handled = WizardCallbackAction::handle(700, 'star_4');
starsCallbackCheck('stars callback is consumed', $handled, true);
starsCallbackCheck('stars callback stores exact value', MaxSearchApi::getSavedData(700)[MaxSearchApi::$statusStars] ?? null, '4');
starsCallbackCheck('stars callback advances exactly once to meal', MaxSearchApi::$transitions, [MaxSearchApi::$statusMeal]);
starsCallbackCheck('stars callback renders meal once', count($messenger->buttons), 1);
starsCallbackCheck('stars callback updates the step exactly once', StarsCallbackFakeData::$updates, 1);
starsCallbackCheck('stars callback avoids legacy direct write', MaxSearchApi::$directSaves, []);

$handledAgain = WizardCallbackAction::handle(700, 'star_4');
starsCallbackCheck('duplicate delivery is consumed after state advance', $handledAgain, true);
starsCallbackCheck('duplicate delivery preserves value', MaxSearchApi::getSavedData(700)[MaxSearchApi::$statusStars] ?? null, '4');
starsCallbackCheck('duplicate delivery does not render again', count($messenger->buttons), 1);
starsCallbackCheck('duplicate delivery does not transition again', MaxSearchApi::$transitions, [MaxSearchApi::$statusMeal]);
starsCallbackCheck('duplicate delivery does not update again', StarsCallbackFakeData::$updates, 1);

$messenger = starsCallbackReset(701);
MaxSearchApi::$currentStatus = (int)MaxSearchApi::$statusMeal;
$handledStale = WizardCallbackAction::handle(701, 'star_5');
starsCallbackCheck('stale stars callback is consumed', $handledStale, true);
starsCallbackCheck('stale stars callback preserves stored value', MaxSearchApi::getSavedData(701)[MaxSearchApi::$statusStars] ?? null, '3');
starsCallbackCheck('stale stars callback renders no view', count($messenger->buttons), 0);
starsCallbackCheck('stale stars callback makes no transition', MaxSearchApi::$transitions, []);
starsCallbackCheck('stale stars callback makes no update', StarsCallbackFakeData::$updates, 0);

$messenger = starsCallbackReset(702);
MaxSearchApi::$editMode = 'stars';
WizardCallbackAction::handle(702, 'star_5');
starsCallbackCheck('edit callback stores exact value', MaxSearchApi::getSavedData(702)[MaxSearchApi::$statusStars] ?? null, '5');
starsCallbackCheck('edit callback returns to check', MaxSearchApi::$transitions, [MaxSearchApi::$statusCheck]);
starsCallbackCheck('edit callback renders check once', count($messenger->buttons), 1);
starsCallbackCheck('edit callback clears edit mode', MaxSearchApi::$editMode, '');

$messenger = starsCallbackReset(703, false);
$before = count(StarsCallbackFakeData::$rows);
$handledMissing = WizardCallbackAction::handle(703, 'star_2');
starsCallbackCheck('missing stars step is consumed', $handledMissing, true);
starsCallbackCheck('missing stars step is not inserted', count(StarsCallbackFakeData::$rows), $before);
starsCallbackCheck('missing stars step does not call add', StarsCallbackFakeData::$adds, 0);
starsCallbackCheck('missing stars step makes no update', StarsCallbackFakeData::$updates, 0);
starsCallbackCheck('missing stars step renders no next view', count($messenger->buttons), 0);
starsCallbackCheck('missing stars step makes no transition', MaxSearchApi::$transitions, []);

$source = (string)file_get_contents(__DIR__ . '/../actions/callbacks/WizardCallbackAction.php');
starsCallbackCheck('action applies stars through update-only boundary', strpos($source, '$stars = str_replace') !== false && strpos($source, 'MaxSearchApi::$statusStars,') !== false, true);
starsCallbackCheck('action keeps the shared forward lock', strpos($source, "InteractionGuard::synchronized(\$chatId, 'wizard.forward'") !== false, true);
starsCallbackCheck('action keeps stale check inside the shared lock', strpos($source, 'self::staleForwardCallback($chatId, $q)') !== false, true);

foreach ([700, 701, 702, 703] as $chatId) {
    EditFlowService::clearSnapshot($chatId);
    @unlink(InteractionGuard::lockPath($chatId, 'wizard.forward'));
}

echo "\n--------------------------\n";
echo $failed === 0 ? "STARS CALLBACK APPLICATION: OK\n" : "STARS CALLBACK APPLICATION: FAIL ({$failed})\n";
exit($failed === 0 ? 0 : 1);
