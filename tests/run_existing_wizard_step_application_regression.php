<?php

declare(strict_types=1);

$backend = (string)($argv[1] ?? '');
if ($backend === '') {
    $failed = 0;
    foreach (['legacy', 'mysql'] as $mode) {
        $lines = [];
        $command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__) . ' ' . escapeshellarg($mode) . ' 2>&1';
        exec($command, $lines, $code);
        echo implode("\n", $lines) . "\n";
        if ($code !== 0) $failed++;
    }

    $serviceSource = (string)file_get_contents(__DIR__ . '/../services/ExistingWizardStepApplicationService.php');
    $callerSource = (string)file_get_contents(__DIR__ . '/../services/EditFlowService.php');
    existingStepCheck('contract delegates to update-only repository method', strpos($serviceSource, 'ConversationStateRepository::saveLastValue') !== false, true, $failed);
    existingStepCheck('contract contains no hidden status transition', strpos($serviceSource, 'setStatus(') === false, true, $failed);
    existingStepCheck('one existing caller uses the contract', substr_count($callerSource, 'ExistingWizardStepApplicationService::apply('), 1, $failed);
    existingStepCheck('coupled caller no longer writes the restored value directly', strpos($callerSource, 'MaxSearchApi::saveLastValue($chatId, $status, $value)') === false, true, $failed);

    echo "\n--------------------------\n";
    echo $failed === 0 ? "EXISTING WIZARD STEP APPLICATION: OK\n" : "EXISTING WIZARD STEP APPLICATION: FAIL ({$failed})\n";
    exit($failed === 0 ? 0 : 1);
}

$failed = 0;

function existingStepCheck(string $name, $actual, $expected, int &$failed): void
{
    if ($actual === $expected) {
        echo "PASS  {$name}\n";
        return;
    }
    echo "FAIL  {$name}\n";
    echo '      expected: ' . json_encode($expected, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
    echo '      actual:   ' . json_encode($actual, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
    $failed++;
}

class MaxSearchApi
{
    public static $HL = 1;
    public static $statusStart = 64;
    public static int $statusTransitions = 0;

    public static function setStatus($chatId, $statusId): void
    {
        self::$statusTransitions++;
    }
}

if ($backend === 'legacy') {
    final class ExistingStepFakeResult
    {
        private array $rows;
        private int $index = 0;
        public function __construct(array $rows) { $this->rows = array_values($rows); }
        public function fetch() { return $this->rows[$this->index++] ?? false; }
    }

    final class ExistingStepFakeEntity
    {
        public function getDataClass(): string { return ExistingStepFakeData::class; }
    }

    final class ExistingStepFakeData
    {
        public static array $rows = [];
        public static int $adds = 0;

        public static function getList(array $query): ExistingStepFakeResult
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
            return new ExistingStepFakeResult($rows);
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
    eval('namespace Bitrix\\Highloadblock { class HighloadBlockTable { public static function getById($id) { return new \\ExistingStepFakeResult([["ID"=>$id]]); } public static function compileEntity($row) { return new \\ExistingStepFakeEntity(); } } }');

    require_once __DIR__ . '/../services/ExistingWizardStepApplicationService.php';

    ExistingStepFakeData::$rows = [
        ['ID'=>10, 'UF_CHAT_ID'=>42, 'UF_STATUS'=>68, 'UF_VALUE'=>'2'],
        ['ID'=>20, 'UF_CHAT_ID'=>42, 'UF_STATUS'=>64, 'UF_VALUE'=>''],
        ['ID'=>30, 'UF_CHAT_ID'=>42, 'UF_STATUS'=>68, 'UF_VALUE'=>'1'],
        ['ID'=>31, 'UF_CHAT_ID'=>42, 'UF_STATUS'=>72, 'UF_VALUE'=>'6'],
    ];
    $before = count(ExistingStepFakeData::$rows);
    existingStepCheck('legacy current step accepts zero children', ExistingWizardStepApplicationService::apply(42, 68, 0), true, $failed);
    existingStepCheck('legacy preserves exact zero representation', ExistingStepFakeData::$rows[2]['UF_VALUE'], 0, $failed);
    existingStepCheck('legacy update does not insert', count(ExistingStepFakeData::$rows), $before, $failed);
    existingStepCheck('legacy backend add path is unused', ExistingStepFakeData::$adds, 0, $failed);
    existingStepCheck('legacy preserves exact range representation', ExistingWizardStepApplicationService::apply(42, 72, '7-10'), true, $failed);
    existingStepCheck('legacy stored range is unchanged', ExistingStepFakeData::$rows[3]['UF_VALUE'], '7-10', $failed);
    existingStepCheck('legacy missing step is rejected', ExistingWizardStepApplicationService::apply(42, 73, '05.10.2026'), false, $failed);
    existingStepCheck('legacy missing step does not insert', count(ExistingStepFakeData::$rows), $before, $failed);

    ExistingStepFakeData::$rows = [
        ['ID'=>10, 'UF_CHAT_ID'=>43, 'UF_STATUS'=>68, 'UF_VALUE'=>'2'],
        ['ID'=>20, 'UF_CHAT_ID'=>43, 'UF_STATUS'=>64, 'UF_VALUE'=>''],
    ];
    existingStepCheck('legacy pre-start step is rejected', ExistingWizardStepApplicationService::apply(43, 68, 0), false, $failed);
    existingStepCheck('legacy stale value remains unchanged', ExistingStepFakeData::$rows[0]['UF_VALUE'], '2', $failed);
} elseif ($backend === 'mysql') {
    define('MAX_SEARCH_RUNTIME_STORAGE', 'mysql');
    require_once __DIR__ . '/../services/ExistingWizardStepApplicationService.php';

    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec('CREATE TABLE runtime_dialogue_state (id INTEGER PRIMARY KEY AUTOINCREMENT, project_key TEXT NOT NULL, chat_id TEXT NOT NULL, status_id INTEGER NOT NULL, value_text TEXT, message_id TEXT)');
    ProjectConfig::resetForTests(['id'=>'wizard-step-test']);
    $property = new ReflectionProperty(ConversationDb::class, 'pdo');
    $property->setAccessible(true);
    $property->setValue(null, $pdo);

    $insert = $pdo->prepare('INSERT INTO runtime_dialogue_state (project_key, chat_id, status_id, value_text, message_id) VALUES (?, ?, ?, ?, ?)');
    $insert->execute(['wizard-step-test', '42', 64, '', '']);
    $insert->execute(['wizard-step-test', '42', 68, '1', '']);
    $insert->execute(['wizard-step-test', '42', 72, '6', '']);
    $before = (int)$pdo->query('SELECT COUNT(*) FROM runtime_dialogue_state')->fetchColumn();
    existingStepCheck('mysql current step accepts zero children', ExistingWizardStepApplicationService::apply(42, 68, 0), true, $failed);
    existingStepCheck('mysql preserves exact storage representation', $pdo->query('SELECT value_text FROM runtime_dialogue_state WHERE status_id = 68')->fetchColumn(), '0', $failed);
    existingStepCheck('mysql update does not insert', (int)$pdo->query('SELECT COUNT(*) FROM runtime_dialogue_state')->fetchColumn(), $before, $failed);
    existingStepCheck('mysql preserves exact range representation', ExistingWizardStepApplicationService::apply(42, 72, '7-10'), true, $failed);
    existingStepCheck('mysql stored range is unchanged', $pdo->query('SELECT value_text FROM runtime_dialogue_state WHERE status_id = 72')->fetchColumn(), '7-10', $failed);
    existingStepCheck('mysql missing step is rejected', ExistingWizardStepApplicationService::apply(42, 73, '05.10.2026'), false, $failed);
    existingStepCheck('mysql missing step does not insert', (int)$pdo->query('SELECT COUNT(*) FROM runtime_dialogue_state')->fetchColumn(), $before, $failed);

    $insert->execute(['wizard-step-test', '43', 68, '2', '']);
    $insert->execute(['wizard-step-test', '43', 64, '', '']);
    existingStepCheck('mysql pre-start step is rejected', ExistingWizardStepApplicationService::apply(43, 68, 0), false, $failed);
    existingStepCheck('mysql stale value remains unchanged', $pdo->query("SELECT value_text FROM runtime_dialogue_state WHERE chat_id = '43' AND status_id = 68")->fetchColumn(), '2', $failed);
} else {
    fwrite(STDERR, "Unknown backend: {$backend}\n");
    exit(2);
}

existingStepCheck("{$backend} application has no hidden status transition", MaxSearchApi::$statusTransitions, 0, $failed);
exit($failed === 0 ? 0 : 1);
