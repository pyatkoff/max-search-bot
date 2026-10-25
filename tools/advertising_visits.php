<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once dirname(__DIR__).'/services/AdvertisingVisitImportService.php';

try {
    $command = $argv[1] ?? 'validate';
    if (!in_array($command, ['validate','import','journal-validate','journal-import'], true)) throw new InvalidArgumentException('advertising_visit_command');
    $scope = [];
    $journal = null;
    foreach (array_slice($argv, 2) as $arg) {
        if (str_starts_with($arg, '--journal=')) {
            if ($journal !== null) throw new InvalidArgumentException('advertising_visit_argument');
            $journal = substr($arg, strlen('--journal='));
            continue;
        }
        if (!preg_match('/\A--(project_key|source_key|platform|counter_id)=(.+)\z/D', $arg, $m) || isset($scope[$m[1]])) throw new InvalidArgumentException('advertising_visit_argument');
        $scope[$m[1]] = $m[2];
    }
    $scope = AdvertisingVisitContract::scope($scope);
    $now = time();
    $isJournal = str_starts_with($command, 'journal-');
    if ($isJournal !== ($journal !== null)) throw new InvalidArgumentException('advertising_visit_argument');
    if (!$isJournal) {
        $raw = stream_get_contents(STDIN, AdvertisingVisitContract::MAX_BYTES + 1);
        if ($raw === false || strlen($raw) > AdvertisingVisitContract::MAX_BYTES) throw new InvalidArgumentException('advertising_visit_payload_bound');
        $page = json_decode($raw, true, 20, JSON_THROW_ON_ERROR);
        if (!is_array($page)) throw new InvalidArgumentException('advertising_visit_payload');
        $page = AdvertisingVisitContract::page($page, $scope, $now);
    }
    if ($command === 'validate' || $command === 'journal-validate') {
        if ($isJournal) $read = AdvertisingVisitJournalReader::read($journal, $scope, ['cursor'=>'0','fingerprint'=>null], $now);
        $result = ['status'=>'validated','observations'=>count($isJournal ? $read['page']['observations'] : $page['observations']),'database_writes'=>false];
        if ($isJournal) $result += ['cursor'=>$read['page']['next_cursor'],'has_more'=>$read['has_more']];
    } else {
        require_once dirname(__DIR__).'/config.php';
        require_once dirname(__DIR__).'/services/ConversationDb.php';
        require_once dirname(__DIR__).'/services/ProjectConfig.php';
        if (ProjectConfig::projectId() !== $scope['project_key']) throw new InvalidArgumentException('advertising_visit_runtime_project');
        $service = new AdvertisingVisitImportService(new AdvertisingVisitRepository(ConversationDb::connection()), $scope);
        $result = ['status'=>'imported'] + ($isJournal ? $service->importJournal($journal, $now) : $service->import($page, $now));
    }
    echo json_encode($result, JSON_THROW_ON_ERROR).PHP_EOL;
} catch (Throwable $e) {
    $error = $e instanceof InvalidArgumentException && preg_match('/\Aadvertising_visit_[a-z_]+\z/D', $e->getMessage()) ? $e->getMessage() : 'advertising_visit_failed';
    fwrite(STDERR, json_encode(['status'=>'failed','error'=>$error]).PHP_EOL);
    exit(1);
}
