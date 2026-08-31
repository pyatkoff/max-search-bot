<?php
$documentRoot = dirname(__DIR__);
$_SERVER['DOCUMENT_ROOT'] = $documentRoot;

$logFile = __DIR__ . '/cron_followup.log';

function cronLog($text) {
    global $logFile;
    @file_put_contents(
        $logFile,
        date('d.m.Y H:i:s') . '--- ' . $text . PHP_EOL,
        FILE_APPEND | LOCK_EX
    );
}

cronLog('START php=' . PHP_VERSION . ' sapi=' . PHP_SAPI . ' root=' . $documentRoot);

try {
    require_once(__DIR__ . '/config.php');
    require_once(__DIR__ . '/services/RuntimeBootstrap.php');
    RuntimeBootstrap::boot($documentRoot);
    require_once(__DIR__ . '/maxsearchclass.php');
    require_once(__DIR__ . '/services/FollowupQueueService.php');
    require_once(__DIR__ . '/services/DialogueView.php');
    require_once(__DIR__ . '/services/ManagerPhoneFallbackService.php');
    require_once(__DIR__ . '/services/LeadTaskReminderService.php');

    $now = time();
    $sent = 0;
    $waiting = 0;
    $files = FollowupQueueService::list(__DIR__);
    $dir = FollowupQueueService::dir(__DIR__);

    cronLog('QUEUE files=' . count($files) . ' dir=' . $dir);

    foreach ($files as $file) {
        $entry = FollowupQueueService::readFile($file);
        if (empty($entry['ok'])) {
            cronLog('BAD_QUEUE_FILE ' . basename($file) . ' raw=' . ($entry['raw'] ?? ''));
            @unlink($file);
            continue;
        }

        $data = $entry['data'];
        $chatID = (string)$data['chat_id'];
        $state = FollowupQueueService::classify($data, $now);

        if (($state['status'] ?? '') === 'waiting') {
            $waiting++;
            cronLog('WAIT chat=' . $chatID . ' seconds=' . (int)($state['seconds'] ?? 0));
            continue;
        }

        $claim = MaxSearchApi::getLastClaimForChat($chatID);
        $hasPhone = $claim && !empty($claim['UF_PHONE']);

        if ($hasPhone) {
            cronLog('SKIP_PHONE chat=' . $chatID);
            @unlink($file);
            continue;
        }

        cronLog('SEND_START chat=' . $chatID);
        MaxSearchApi::funnelLog($chatID, 'followup_sent');
        $result = DialogueView::toursFollowup($chatID);
        cronLog('SEND_DONE chat=' . $chatID . ' result=' . var_export($result, true));

        $sent++;
        @unlink($file);
    }

    $managerFallback = ManagerPhoneFallbackService::runDue($now);
    cronLog('MANAGER_PHONE_FALLBACK ' . json_encode($managerFallback, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));

    $taskReminders = LeadTaskReminderService::runDue();
    cronLog('LEAD_TASK_REMINDERS ' . json_encode($taskReminders, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));

    $summary = 'OK sent=' . $sent . ' waiting=' . $waiting . ' queue=' . count($files)
        . ' manager_phone_sent=' . (int)($managerFallback['sent'] ?? 0)
        . ' manager_phone_failed=' . (int)($managerFallback['failed'] ?? 0)
        . ' manager_phone_outside_hours=' . (!empty($managerFallback['outside_hours']) ? '1' : '0')
        . ' task_reminders_due=' . (int)($taskReminders['due'] ?? 0)
        . ' task_reminders_notified=' . (int)($taskReminders['notified'] ?? 0)
        . ' task_reminders_failed=' . (int)($taskReminders['failed'] ?? 0);
    cronLog($summary);

    if (PHP_SAPI !== 'cli') {
        header('Content-Type: text/plain; charset=utf-8');
    }
    echo $summary . PHP_EOL;

} catch (\Throwable $e) {
    cronLog(
        'FATAL ' . get_class($e) . ': ' . $e->getMessage() .
        ' FILE=' . $e->getFile() .
        ' LINE=' . $e->getLine()
    );

    if (PHP_SAPI !== 'cli') {
        header('Content-Type: text/plain; charset=utf-8');
        http_response_code(500);
    }

    echo 'ERROR: ' . $e->getMessage() . PHP_EOL;
    exit(1);
}
