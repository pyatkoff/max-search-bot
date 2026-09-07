<?php
// Exercise the real cron queue loop with synthetic files and no external delivery.
require_once __DIR__ . '/../services/FollowupQueueService.php';

class ProjectConfig
{
    public static function get($key, $default = null) { return $key === 'messenger.provider' ? 'max' : $default; }
}
class ConversationControlService
{
    public static $status = 'ai';
    public static $fail = false;
    public static $calls = [];
    public static function shouldRouteToAi($platform, $chatId): bool
    {
        self::$calls[] = [$platform, $chatId];
        if (self::$fail) throw new RuntimeException('synthetic read failure');
        return !in_array(self::$status, ['waiting_manager', 'manager'], true);
    }
}
class MaxSearchApi
{
    public static $phone = false;
    public static $events = [];
    public static function getLastClaimForChat($chatId) { return ['UF_PHONE' => self::$phone ? 'synthetic' : '']; }
    public static function funnelLog($chatId, $event) { self::$events[] = $event; }
}
class DialogueView
{
    public static $sent = [];
    public static function toursFollowup($chatId) { self::$sent[] = $chatId; return true; }
}
function cronLog($text) {}
function fgCheck($label, $actual, $expected): void
{
    if ($actual !== $expected) throw new RuntimeException($label . ': ' . var_export($actual, true));
    echo 'PASS ' . $label . PHP_EOL;
}

$source = file_get_contents(__DIR__ . '/../cron_followup.php');
$start = strpos($source, '    foreach ($files as $file) {');
$end = strpos($source, '    $managerFallback =', $start);
if ($start === false || $end === false) throw new RuntimeException('cron loop not found');
$loop = substr($source, $start, $end - $start);
$tmp = sys_get_temp_dir() . '/followup-manager-' . bin2hex(random_bytes(8));
mkdir($tmp, 0700);
try {
    // Reopening a previously generated tour URL may enqueue a fresh reminder
    // after handoff/reply. Recheck current ownership when it becomes due.
    foreach ([
        ['manager already replied', 'manager', false, false, 0, false],
        ['accepted without reply', 'manager', false, false, 0, false],
        ['waiting for manager', 'waiting_manager', false, false, 0, false],
        ['self service unchanged', 'ai', false, false, 1, false],
        ['legacy no conversation unchanged', null, false, false, 1, false],
        ['phone suppression unchanged', 'ai', true, false, 0, false],
        ['ownership read failure', 'ai', false, true, 0, true],
    ] as [$label, $status, $phone, $fail, $expectedSent, $expectRetained]) {
        ConversationControlService::$status = $status;
        ConversationControlService::$fail = $fail;
        ConversationControlService::$calls = [];
        MaxSearchApi::$phone = $phone;
        MaxSearchApi::$events = [];
        DialogueView::$sent = [];
        FollowupQueueService::schedule($tmp, '-123', 10, 100);
        $file = FollowupQueueService::file($tmp, '-123');
        $files = [$file];
        $now = 200;
        $sent = $waiting = 0;
        $thrown = false;
        try { eval($loop); } catch (RuntimeException $e) { $thrown = true; }
        fgCheck($label . ' sends', count(DialogueView::$sent), $expectedSent);
        fgCheck($label . ' telemetry', count(MaxSearchApi::$events), $expectedSent);
        fgCheck($label . ' retention', is_file($file), $expectRetained);
        fgCheck($label . ' propagates failure', $thrown, $fail);
        if (!$phone) fgCheck($label . ' current scoped lookup', ConversationControlService::$calls, [['max', '-123']]);
        if (is_file($file)) unlink($file);
    }
    ConversationControlService::$calls = [];
    FollowupQueueService::schedule($tmp, '-123', 300, 100);
    $files = [FollowupQueueService::file($tmp, '-123')];
    $now = 200;
    $sent = $waiting = 0;
    eval($loop);
    fgCheck('not due stays queued', is_file($files[0]), true);
    fgCheck('not due does not read ownership', ConversationControlService::$calls, []);
    fgCheck('not due increments waiting', $waiting, 1);
} finally {
    foreach (glob($tmp . '/followup/*') ?: [] as $file) unlink($file);
    rmdir($tmp . '/followup');
    rmdir($tmp);
}
