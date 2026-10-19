<?php
// Appended to the reviewed collector and executed over SSH stdin. No remote files.
if (PHP_SAPI !== 'cli') { exit(1); }
ini_set('display_errors', '0');
ini_set('log_errors', '0');
try {
    $root = '/var/www/anytoour/data/www/app.anytoour.ru';
    if (getcwd() !== $root) throw new RuntimeException('wrong_root');
    $expected = '4c0e35b3f8a2362efcc7ba12348b041b8f1eba40';
    $sha = trim((string)shell_exec('git rev-parse HEAD 2>/dev/null'));
    if (!hash_equals($expected, $sha)) throw new RuntimeException('source_mismatch');
    exec('git diff --quiet HEAD --', $ignored, $dirty);
    if ($dirty !== 0) throw new RuntimeException('tracked_drift');
    require_once $root.'/config.php';
    require_once $root.'/services/ManagerAiDraftContextService.php';
    require_once $root.'/services/ManagerAiDraftService.php';
    $pdo = ConversationDb::connection();
    if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'mysql') throw new RuntimeException('unsupported_driver');
    // Enforce read-only as the default for autocommit reads and every new transaction.
    $pdo->exec('SET SESSION TRANSACTION READ ONLY');
    $cohort = array_map('intval', $pdo->query('SELECT id FROM managers WHERE is_active=1 ORDER BY id')->fetchAll(PDO::FETCH_COLUMN));
    if (!$cohort) throw new RuntimeException('empty_cohort');
    $batch = ManagerDraftPilotCollector::collect($pdo, $cohort, 20);
    if (($batch['ok'] ?? false) !== true) throw new RuntimeException('capture_unavailable');
    $instructions = ManagerAiDraftService::instructions();
    $batch['source'] = [
        'repository'=>'pyatkoff/max-search-bot', 'production_sha'=>$sha,
        'instructions_sha256'=>hash('sha256', $instructions),
        'instructions'=>$instructions,
        'context_service_sha256'=>hash_file('sha256', $root.'/services/ManagerAiDraftContextService.php'),
        'draft_service_sha256'=>hash_file('sha256', $root.'/services/ManagerAiDraftService.php'),
    ];
    $batch['effects'] = ['provider_calls'=>0,'customer_sends'=>0,'read_markers'=>0,'database_writes'=>0,'draft_imports'=>0];
    echo json_encode($batch, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
} catch (Throwable $ignored) {
    fwrite(STDERR, "pilot_capture_failed\n");
    exit(1);
}
